<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsCreditReviews;
use App\Http\Requests\ReconsiderCreditDecisionRequest;
use App\Http\Requests\StoreCreditDecisionRequest;
use App\Models\Customer;
use App\Models\WorkflowInstance;
use App\Workflows\FinancialScoring\FinancialScoring;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Credit reviews are loan applications waiting for a credit officer to approve or reject
 * them (see FinancialScoring). This lists them, shows one with the customer's financials
 * and scores, records the decision, and reopens a decided application to be reconsidered.
 */
class CreditReviewController extends Controller
{
    use ListsCreditReviews;

    public function index(Request $request): Response
    {
        return Inertia::render('credit/Reviews', [
            'reviews' => $this->openCreditReviews($request->user()),
        ]);
    }

    /**
     * Show a referred application in the officer's credit risk tier, or a less risky one, with the form to decide it,
     * assigning it to them if it's unassigned, so it leaves the other officers' inboxes. One in
     * a riskier tier, or assigned to another officer, shows a summary saying so instead, as does
     * any application that isn't referred, from which a decided one can be reconsidered.
     */
    public function show(Request $request, int $run, FinancialScoring $scoring): Response
    {
        $workflow = WorkflowInstance::where('workflow', WorkflowInstance::FINANCIAL_SCORING)->with(['subject', 'assignedTo:id,name'])->findOrFail($run);

        $inTier = $workflow->isWithinCreditRiskTierOf($request->user());

        if ($workflow->status === FinancialScoring::REVIEW_PLACE && $inTier && $workflow->assigned_to_id === null) {
            $workflow->claimReviewFor($request->user(), $request->ip());
        }

        /** @var Customer $customer */
        $customer = $workflow->subject;

        $review = [
            ...$this->creditScores($workflow),
            'status' => $workflow->status,
            'status_label' => $scoring->placeLabels()[$workflow->status] ?? $workflow->status,
            'affordability_reasons' => $workflow->result['reasons'] ?? [],
            'loan_repayment' => $workflow->result['loan_repayment'] ?? null,
            'decisions' => $this->creditDecisions($workflow),
        ];

        $awaitingReview = $workflow->status === FinancialScoring::REVIEW_PLACE;

        if (! $awaitingReview || ! $inTier || $workflow->isAssignedToSomeoneOtherThan($request->user())) {
            return Inertia::render('credit/ReviewSummary', [
                'review' => [
                    ...$review,
                    'customer' => $customer->only(['id', 'first_name', 'last_name']),
                    'assigned_to' => $awaitingReview ? $workflow->assignedTo?->name : null,
                    // A referred application in a riskier tier than the officer's is for other officers to decide.
                    'above_tier' => $awaitingReview && ! $inTier,
                    'can_reconsider' => $this->canReconsider($workflow, $scoring),
                ],
            ]);
        }

        return Inertia::render('credit/Review', [
            'review' => [...$review, 'customer' => $this->applicant($customer)],
        ]);
    }

    /**
     * Record the decision on a referred application. The run is locked while the decision is
     * recorded, so of two officers deciding at once only the first counts; the other is told, as
     * is an officer whose application has been assigned to another officer or is in a riskier
     * credit risk tier than theirs.
     */
    public function decide(StoreCreditDecisionRequest $request, int $run, FinancialScoring $scoring): RedirectResponse
    {
        $error = DB::transaction(function () use ($request, $run, $scoring): ?string {
            $workflow = WorkflowInstance::where('workflow', WorkflowInstance::FINANCIAL_SCORING)->lockForUpdate()->findOrFail($run);

            if ($workflow->status !== FinancialScoring::REVIEW_PLACE) {
                return 'Someone else has already decided this application, so your decision wasn’t recorded.';
            }

            if (! $workflow->isWithinCreditRiskTierOf($request->user())) {
                $tier = $workflow->result['credit_risk_tier'] ?? 'unrated';

                return "This is a {$tier}-risk application, above your credit risk tier, so your decision wasn’t recorded.";
            }

            if ($workflow->isAssignedToSomeoneOtherThan($request->user())) {
                return "This application is assigned to {$workflow->assignedTo->name}, so your decision wasn’t recorded.";
            }

            $scoring->decide($workflow, $request->validated('decision'), $request->validated('notes'), $request->user(), $request->ip());
            $workflow->save();

            return null;
        });

        if ($error) {
            Inertia::flash('error', $error);
        }

        return to_route('credit.reviews.index');
    }

    /**
     * Reopen a decided application for a credit officer to decide again, then show it with
     * the form. One that has since been reopened, or replaced by a new run, isn't.
     */
    public function reconsider(ReconsiderCreditDecisionRequest $request, int $run, FinancialScoring $scoring): RedirectResponse
    {
        $reconsidered = DB::transaction(function () use ($request, $run, $scoring): bool {
            $workflow = WorkflowInstance::where('workflow', WorkflowInstance::FINANCIAL_SCORING)->lockForUpdate()->findOrFail($run);

            if (! $this->canReconsider($workflow, $scoring)) {
                return false;
            }

            $scoring->reconsider($workflow, $request->validated('notes'), $request->user(), $request->ip());
            $workflow->save();

            return true;
        });

        if (! $reconsidered) {
            Inertia::flash('error', 'This application can’t be reconsidered: it’s no longer decided, or the customer’s financials have changed since.');
        }

        return to_route('credit.reviews.show', $run);
    }

    /**
     * Whether the run is decided and still the customer's latest: once their financials change,
     * the new run is the one to decide.
     */
    private function canReconsider(WorkflowInstance $workflow, FinancialScoring $scoring): bool
    {
        $latestRunId = WorkflowInstance::where('workflow', WorkflowInstance::FINANCIAL_SCORING)
            ->whereMorphedTo('subject', $workflow->subject)
            ->max('id');

        return $scoring->stateMachine()->can($workflow, FinancialScoring::RECONSIDER) && (int) $latestRunId === $workflow->id;
    }
}
