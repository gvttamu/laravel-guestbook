<?php

namespace App\Workflows\FinancialScoring;

use App\Jobs\NotifyOfficerOfCreditReview;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Workflows\StateMachineWorkflow;

/**
 * Financial scoring: a new run waits for the queue (see ScoreCustomerFinancials), then
 * FinancialScorecard scores the affordability of the customer's loan application, the
 * credit bureau is asked for their credit score, and CreditDecisionEngine approves,
 * rejects or refers the application to a credit officer. CreditRiskTier sorts it into the
 * risk tier whose credit officers decide it if it's referred. A credit officer of that tier,
 * or a riskier one, decides a referred application; any credit officer can reconsider a decided one, which
 * refers it again.
 * A run whose financials change before it's decided is superseded by a new run.
 *
 * The run's result keeps the scores, the engine's decision and reasons, when it was last
 * referred, and every credit officer's decision or reconsideration, so the outcome doesn't
 * erase what led to it. Automatic steps are audited with no user; an officer's name theirs.
 * Every credit officer who may decide a referred or reconsidered application is emailed.
 */
class FinancialScoring extends StateMachineWorkflow
{
    public const SCORE = 'score';

    public const AUTO_APPROVE = 'auto_approve';

    public const AUTO_REJECT = 'auto_reject';

    public const REFER = 'refer';

    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    public const RECONSIDER = 'reconsider';

    /**
     * The place where a run waits for a credit officer.
     */
    public const REVIEW_PLACE = 'credit_review';

    /**
     * The engine's transition for each of its decisions.
     *
     * @var array<string, string>
     */
    private const ENGINE_TRANSITIONS = [
        CreditDecisionEngine::APPROVED => self::AUTO_APPROVE,
        CreditDecisionEngine::REJECTED => self::AUTO_REJECT,
        CreditDecisionEngine::REFERRED => self::REFER,
    ];

    public function __construct(
        private FinancialScorecard $scorecard,
        private CreditBureau $bureau,
        private CreditDecisionEngine $engine,
        private CreditRiskTier $riskTier,
    ) {}

    public function title(): string
    {
        return 'Financial scoring';
    }

    public function description(): string
    {
        return 'Scores how well a customer can afford their commitments, including the repayment on the loan they’re applying for, and gets their credit score from the credit bureau. The application is then approved or rejected automatically, or referred to a credit officer, who can also reconsider any decision. A new run starts whenever their financials or loan change.';
    }

    protected function places(): array
    {
        return [
            'pending' => 'Waiting to be scored',
            'scored' => 'Scored',
            self::REVIEW_PLACE => 'Referred: awaiting credit review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'superseded' => 'Superseded',
        ];
    }

    protected function transitions(): array
    {
        return [
            self::SCORE => ['from' => 'pending', 'to' => 'scored', 'label' => 'Score affordability and credit'],
            self::AUTO_APPROVE => ['from' => 'scored', 'to' => 'approved', 'label' => 'Both scores pass'],
            self::AUTO_REJECT => ['from' => 'scored', 'to' => 'rejected', 'label' => 'A score fails'],
            self::REFER => ['from' => 'scored', 'to' => self::REVIEW_PLACE, 'label' => 'Refer to a credit officer'],
            self::APPROVE => ['from' => self::REVIEW_PLACE, 'to' => 'approved', 'label' => 'Credit officer approves'],
            self::REJECT => ['from' => self::REVIEW_PLACE, 'to' => 'rejected', 'label' => 'Credit officer rejects'],
            self::RECONSIDER => ['from' => ['approved', 'rejected'], 'to' => self::REVIEW_PLACE, 'label' => 'Credit officer reconsiders'],
            self::SUPERSEDE => ['from' => ['pending', self::REVIEW_PLACE], 'to' => 'superseded', 'label' => 'Financials changed'],
        ];
    }

    /**
     * Score a pending run and let the engine decide it. Does nothing for a run in any other
     * place, so a repeated job can't score twice. The caller locks and saves the run.
     */
    public function score(WorkflowInstance $run): void
    {
        if (! $this->stateMachine()->can($run, self::SCORE)) {
            return;
        }

        /** @var Customer $customer */
        $customer = $run->subject;

        $affordability = $this->scorecard->score($customer);
        $creditScore = $this->bureau->score($customer->email);
        $decision = $this->engine->decide($creditScore, $affordability['risk_band']);
        $tier = $this->riskTier->assess($affordability['score'], $creditScore, $customer->loan_amount, $customer->loan_purpose);

        $run->result = [
            ...$affordability,
            'credit_bureau' => $this->bureau->name(),
            'credit_score' => $creditScore,
            'engine_decision' => $decision['decision'],
            'engine_reasons' => $decision['reasons'],
            'credit_risk_tier' => $tier['tier'],
            'credit_risk_points' => $tier['points'],
            'credit_risk_reasons' => $tier['reasons'],
            'reviews' => [],
        ];
        $this->apply($run, self::SCORE);
        $customer->auditLogs()->create(['event' => 'scored', 'new_values' => $run->result]);

        $this->apply($run, self::ENGINE_TRANSITIONS[$decision['decision']]);

        if ($decision['decision'] === CreditDecisionEngine::REFERRED) {
            $run->result = [...$run->result, 'referred_at' => now()->toIso8601String()];
            $this->notifyCreditOfficers($run);
        }

        $customer->auditLogs()->create([
            'event' => "loan_{$decision['decision']}",
            'new_values' => ['loan_decision' => $decision['decision'], 'loan_decision_reasons' => $decision['reasons']],
        ]);
    }

    /**
     * Record a credit officer's decision on a referred application, with an audit entry
     * naming them. The caller locks and saves the run, having checked it's referred.
     *
     * @param  'approved'|'rejected'  $decision
     */
    public function decide(WorkflowInstance $run, string $decision, ?string $notes, User $officer, ?string $ipAddress): void
    {
        $this->recordReview($run, $decision, $notes, $officer, $ipAddress);
        $this->apply($run, $decision === 'approved' ? self::APPROVE : self::REJECT, $officer);
    }

    /**
     * Reopen a decided application for a credit officer to decide again, with an audit entry
     * naming the officer who reconsidered it. The caller locks and saves the run, having
     * checked it's decided.
     */
    public function reconsider(WorkflowInstance $run, string $notes, User $officer, ?string $ipAddress): void
    {
        $this->recordReview($run, 'reconsidered', $notes, $officer, $ipAddress);
        $run->result = [...$run->result, 'referred_at' => now()->toIso8601String()];
        $this->apply($run, self::RECONSIDER, $officer);
        $this->notifyCreditOfficers($run);
    }

    /**
     * Email each credit officer who may decide the application's tier that it's waiting, once the
     * run is saved, so the email can't arrive before the application is open for review.
     */
    private function notifyCreditOfficers(WorkflowInstance $run): void
    {
        // Applications scored before credit risk tiers existed have none, and no officer to route them to.
        if (! isset($run->result['credit_risk_tier'])) {
            return;
        }

        User::creditOfficersFor($run->result['credit_risk_tier'])
            ->each(fn (User $officer) => NotifyOfficerOfCreditReview::dispatch($run, $officer));
    }

    /**
     * @param  'approved'|'rejected'|'reconsidered'  $action
     */
    private function recordReview(WorkflowInstance $run, string $action, ?string $notes, User $officer, ?string $ipAddress): void
    {
        // The review is over, so it's nobody's; a reconsidered application goes back to the queue unassigned.
        $run->assigned_to_id = null;
        $run->result = [...$run->result, 'reviews' => [
            ...($run->result['reviews'] ?? []),
            ['action' => $action, 'notes' => $notes, 'reviewed_by' => $officer->id, 'reviewed_at' => now()->toIso8601String()],
        ]];

        $run->subject->auditLogs()->create([
            'user_id' => $officer->id,
            'event' => "loan_{$action}",
            'new_values' => ['loan_decision' => $action, 'loan_decision_notes' => $notes],
            'ip_address' => $ipAddress,
        ]);
    }
}
