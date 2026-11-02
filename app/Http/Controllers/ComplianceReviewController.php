<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsComplianceReviews;
use App\Http\Requests\AssignComplianceReviewRequest;
use App\Http\Requests\StoreReviewDecisionRequest;
use App\Jobs\NotifyOfficerOfScreeningReview;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Workflows\CustomerVerification\CustomerVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Compliance reviews are verification runs waiting for an officer to approve or reject
 * a sanctions match (see CustomerVerification). This lists them, shows one with the
 * customer's details, records the decision, and assigns it to an officer.
 */
class ComplianceReviewController extends Controller
{
    use ListsComplianceReviews;

    public function index(Request $request): Response
    {
        return Inertia::render('compliance/Reviews', [
            'reviews' => $this->openReviews($request->user()),
        ]);
    }

    /**
     * Show a run awaiting review with the form to decide it. A run that isn't awaiting review,
     * because it has been decided, superseded, or never needed one, shows a summary instead,
     * as officers reach it from emails that can outlive the review. So does a review assigned
     * to another officer, naming them, as it's theirs to decide. An unassigned review is
     * assigned to the officer who opens it, so it leaves the other officers' inboxes.
     */
    public function show(Request $request, int $run, CustomerVerification $verification): Response
    {
        $workflow = WorkflowInstance::where('workflow', WorkflowInstance::CUSTOMER_VERIFICATION)->with(['subject', 'assignedTo:id,name'])->findOrFail($run);
        $awaitingReview = isset(CustomerVerification::REVIEW_PLACES[$workflow->status]);

        if ($awaitingReview && $workflow->assigned_to_id === null) {
            $workflow->claimReviewFor($request->user(), $request->ip());
            $awaitingReview = isset(CustomerVerification::REVIEW_PLACES[$workflow->status]);
        }

        /** @var Customer $customer */
        $customer = $workflow->subject;

        if (! $awaitingReview || $workflow->isAssignedToSomeoneOtherThan($request->user())) {
            return Inertia::render('compliance/ReviewSummary', [
                'review' => [
                    'id' => $workflow->id,
                    'status' => $workflow->status,
                    'status_label' => $verification->placeLabels()[$workflow->status] ?? $workflow->status,
                    'customer' => $customer->only(['id', 'first_name', 'last_name']),
                    'reviews' => $this->earlierReviews($workflow),
                    'assigned_to' => $awaitingReview ? $workflow->assignedTo?->name : null,
                ],
            ]);
        }

        return Inertia::render('compliance/Review', [
            'review' => [
                ...$this->review($workflow),
                // A sanctions review doesn't need the customer's finances or loan application.
                'customer' => Arr::only($customer->toArray(), ['id', ...array_diff($customer->getFillable(), Customer::FINANCIAL_FIELDS, Customer::LOAN_FIELDS)]),
                'earlier_reviews' => $this->earlierReviews($workflow),
            ],
            // The officers the review can be reassigned to: everyone but the officer viewing it.
            'officers' => User::complianceOfficers()->whereKeyNot($request->user()->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Record the decision on the step the officer reviewed. The run is locked while the
     * decision is recorded, so of two officers deciding at once only the first counts; the
     * other is told, as is an officer whose run has moved on to another step since they
     * opened it, or whose review has been assigned to another officer.
     */
    public function decide(StoreReviewDecisionRequest $request, int $run, CustomerVerification $verification): RedirectResponse
    {
        $error = DB::transaction(function () use ($request, $run, $verification): ?string {
            $workflow = WorkflowInstance::where('workflow', WorkflowInstance::CUSTOMER_VERIFICATION)->lockForUpdate()->findOrFail($run);

            if ((CustomerVerification::REVIEW_PLACES[$workflow->status] ?? null) !== $request->validated('step')) {
                return 'Someone else has already completed this review, so your decision wasn’t recorded.';
            }

            if ($workflow->isAssignedToSomeoneOtherThan($request->user())) {
                return "This review is assigned to {$workflow->assignedTo->name}, so your decision wasn’t recorded.";
            }

            $verification->decide($workflow, $request->validated('decision'), $request->validated('notes'), $request->user(), $request->ip());
            $workflow->save();

            return null;
        });

        if ($error) {
            Inertia::flash('error', $error);
        }

        return to_route('compliance.reviews.index');
    }

    /**
     * Assign the review to another officer, who is emailed, or leave it unassigned, recording
     * who changed it, then return to the inbox as the review is no longer theirs. Like a decision,
     * it only applies to the step the officer saw, so a review completed meanwhile isn't reopened,
     * and only an unassigned review or the officer's own can be reassigned.
     */
    public function assign(AssignComplianceReviewRequest $request, int $run): RedirectResponse
    {
        $error = DB::transaction(function () use ($request, $run): ?string {
            $workflow = WorkflowInstance::where('workflow', WorkflowInstance::CUSTOMER_VERIFICATION)->lockForUpdate()->findOrFail($run);
            $step = $request->validated('step');

            if ((CustomerVerification::REVIEW_PLACES[$workflow->status] ?? null) !== $step) {
                return 'This review has already been completed, so it wasn’t reassigned.';
            }

            if ($workflow->isAssignedToSomeoneOtherThan($request->user())) {
                return "This review is assigned to {$workflow->assignedTo->name}, so it wasn’t reassigned.";
            }

            $previous = $workflow->assignedTo;
            $assignee = $request->assignee();

            if ($previous?->id === $assignee?->id) {
                return null;
            }

            $workflow->assignedTo()->associate($assignee)->save();

            $workflow->subject->auditLogs()->create([
                'user_id' => $request->user()->id,
                'event' => 'assigned',
                'old_values' => ['review_assignee' => $previous?->name],
                'new_values' => ['review_assignee' => $assignee?->name],
                'ip_address' => $request->ip(),
            ]);

            if ($assignee) {
                NotifyOfficerOfScreeningReview::dispatch($workflow, $step, $assignee, $request->user());
            }

            return null;
        });

        if ($error) {
            Inertia::flash('error', $error);

            return to_route('compliance.reviews.index');
        }

        return to_route('inbox');
    }

    /**
     * The reviews already recorded in this verification run, oldest first, naming each reviewer.
     * Reviews recorded before matches were stored with them have no matches.
     *
     * @return list<array{step: string, matches: list<string>, decision: string, notes: ?string, reviewed_by: ?string, reviewed_at: string}>
     */
    private function earlierReviews(WorkflowInstance $workflow): array
    {
        $reviews = $workflow->result['reviews'] ?? [];
        $reviewers = User::whereIn('id', array_column($reviews, 'reviewed_by'))->pluck('name', 'id');

        return array_map(fn (array $review): array => [
            'step' => $review['step'],
            'matches' => $review['matches'] ?? [],
            'decision' => $review['decision'],
            'notes' => $review['notes'] ?? null,
            'reviewed_by' => $reviewers->get($review['reviewed_by'] ?? 0),
            'reviewed_at' => $review['reviewed_at'],
        ], $reviews);
    }
}
