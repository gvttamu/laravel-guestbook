<?php

namespace App\Workflows\CustomerVerification;

use App\Jobs\NotifyOfficerOfScreeningReview;
use App\Jobs\RunVerificationChecks;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Workflows\StateMachineWorkflow;

/**
 * Customer verification: screen the contact details, then the address, against the
 * sanctions list. A step that finds a match waits for a compliance officer to review it,
 * and every compliance officer is emailed that it's waiting. An approved contact match goes on to the address check, and a rejected match ends the
 * check. It ends verified when nothing matched, approved when every match was approved,
 * or rejected.
 *
 * The run's result keeps the latest flagged step and its matches, and every review, so
 * the outcome doesn't erase what led to it. Each flag and outcome is audited with no user,
 * since the checks run automatically; each review decision names its reviewer.
 */
class CustomerVerification extends StateMachineWorkflow
{
    public const VERIFY = 'verify';

    public const FLAG_CONTACT = 'flag_contact';

    public const FLAG_ADDRESS = 'flag_address';

    public const APPROVE_CONTACT = 'approve_contact';

    public const CLEAR_ADDRESS = 'clear_address';

    public const APPROVE_ADDRESS = 'approve_address';

    public const REJECT = 'reject';

    /**
     * The places where a run waits for a compliance review, and the step whose matches each reviews.
     *
     * @var array<string, 'contact'|'address'>
     */
    public const REVIEW_PLACES = [
        'contact_review' => 'contact',
        'address_review' => 'address',
    ];

    /**
     * The transition that ends each finished place, and the outcome it reaches.
     *
     * @var array<string, string>
     */
    private const OUTCOMES = [
        self::VERIFY => 'verified',
        self::CLEAR_ADDRESS => 'approved',
        self::APPROVE_ADDRESS => 'approved',
        self::REJECT => 'rejected',
    ];

    public function __construct(private SanctionsRegistry $sanctions) {}

    public function title(): string
    {
        return 'Customer verification';
    }

    public function description(): string
    {
        return 'Screens a customer’s contact details, then their address, against the sanctions list. A match waits for a compliance officer to approve or reject it. A new run starts whenever the screened details change.';
    }

    protected function places(): array
    {
        return [
            'pending' => 'Waiting for checks',
            'contact_review' => 'Contact match: awaiting review',
            'address_check' => 'Waiting for address check',
            'address_review' => 'Address match: awaiting review',
            'verified' => 'Verified',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'superseded' => 'Superseded',
        ];
    }

    protected function transitions(): array
    {
        return [
            self::VERIFY => ['from' => 'pending', 'to' => 'verified', 'label' => 'Contact and address clear'],
            self::FLAG_CONTACT => ['from' => 'pending', 'to' => 'contact_review', 'label' => 'Contact on sanctions list'],
            self::FLAG_ADDRESS => ['from' => ['pending', 'address_check'], 'to' => 'address_review', 'label' => 'Address on sanctions list'],
            self::APPROVE_CONTACT => ['from' => 'contact_review', 'to' => 'address_check', 'label' => 'Compliance approves'],
            self::CLEAR_ADDRESS => ['from' => 'address_check', 'to' => 'approved', 'label' => 'Address clear'],
            self::APPROVE_ADDRESS => ['from' => 'address_review', 'to' => 'approved', 'label' => 'Compliance approves'],
            self::REJECT => ['from' => ['contact_review', 'address_review'], 'to' => 'rejected', 'label' => 'Compliance rejects'],
            self::SUPERSEDE => ['from' => ['pending', 'contact_review', 'address_check', 'address_review'], 'to' => 'superseded', 'label' => 'Details changed'],
        ];
    }

    /**
     * Run the automatic checks a run is waiting for: both steps for a new run, or just the
     * address once a contact match has been approved. Does nothing for a run in any other
     * place, so a repeated job can't check twice. The caller locks and saves the run.
     */
    public function runChecks(WorkflowInstance $run): void
    {
        $stateMachine = $this->stateMachine();

        if (! $stateMachine->can($run, self::VERIFY) && ! $stateMachine->can($run, self::CLEAR_ADDRESS)) {
            return;
        }

        /** @var Customer $customer */
        $customer = $run->subject;

        if ($run->status === 'pending' && $matches = $this->sanctions->screenContact($customer->phone, $customer->email)) {
            $this->flag($run, self::FLAG_CONTACT, 'contact', $matches);

            return;
        }

        if ($matches = $this->sanctions->screenAddress($customer->address_line1, $customer->postal_code, $customer->country)) {
            $this->flag($run, self::FLAG_ADDRESS, 'address', $matches);

            return;
        }

        $this->finish($run, $run->status === 'pending' ? self::VERIFY : self::CLEAR_ADDRESS);
    }

    /**
     * Record a compliance officer's decision on the match a run is waiting on, with an
     * audit entry naming them. An approved contact match queues the address check;
     * any other decision ends the check. The caller locks and saves the run, having
     * checked it's waiting for a review.
     *
     * @param  'approved'|'rejected'  $decision
     */
    public function decide(WorkflowInstance $run, string $decision, ?string $notes, User $reviewer, ?string $ipAddress): void
    {
        $step = self::REVIEW_PLACES[$run->status];

        $run->result = [...$run->result, 'reviews' => [
            ...($run->result['reviews'] ?? []),
            [
                'step' => $step,
                'matches' => $run->result['matches'] ?? [],
                'decision' => $decision,
                'notes' => $notes,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now()->toIso8601String(),
            ],
        ]];

        $run->subject->auditLogs()->create([
            'user_id' => $reviewer->id,
            'event' => $decision,
            'new_values' => ['review_step' => $step, 'review_decision' => $decision, 'review_notes' => $notes],
            'ip_address' => $ipAddress,
        ]);

        if ($decision === 'rejected') {
            $this->finish($run, self::REJECT, $reviewer);
        } elseif ($step === 'contact') {
            $this->apply($run, self::APPROVE_CONTACT, $reviewer);
            RunVerificationChecks::dispatch($run);
        } else {
            $this->finish($run, self::APPROVE_ADDRESS, $reviewer);
        }
    }

    /**
     * @param  list<string>  $matches
     */
    private function flag(WorkflowInstance $run, string $transition, string $step, array $matches): void
    {
        $report = ['status' => 'flagged', 'failed_step' => $step, 'matches' => $matches];

        $run->result = [...($run->result ?? []), ...$report, 'flagged_at' => now()->toIso8601String()];
        $this->apply($run, $transition);

        $run->subject->auditLogs()->create(['event' => 'flagged', 'new_values' => $report]);

        // Each compliance officer is emailed once the run is saved, so the email can't arrive before the review is open.
        User::complianceOfficers()->each(fn (User $officer) => NotifyOfficerOfScreeningReview::dispatch($run, $step, $officer));
    }

    /**
     * End the check, with an outcome audit entry. `$by` is the reviewer whose decision ended
     * it, if any; the outcome entry itself names nobody, as it always has.
     */
    private function finish(WorkflowInstance $run, string $transition, ?User $by = null): void
    {
        $outcome = self::OUTCOMES[$transition];

        $run->result = [...($run->result ?? []), 'status' => $outcome];
        $this->apply($run, $transition, $by);

        $run->subject->auditLogs()->create([
            'event' => $outcome,
            'new_values' => ['status' => $outcome, 'failed_step' => null, 'matches' => []],
        ]);
    }
}
