<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsComplianceReviews;
use App\Http\Requests\StoreReviewDecisionRequest;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Workflows\CustomerVerification\CustomerVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Compliance reviews are verification runs waiting for an officer to approve or reject
 * a sanctions match (see CustomerVerification). This lists them, shows one with the
 * customer's details, and records the decision.
 */
class ComplianceReviewController extends Controller
{
    use ListsComplianceReviews;

    public function index(): Response
    {
        return Inertia::render('compliance/Reviews', [
            'reviews' => $this->openReviews(),
        ]);
    }

    public function show(int $run): Response
    {
        $workflow = $this->runsAwaitingReview()->with('subject')->findOrFail($run);

        /** @var Customer $customer */
        $customer = $workflow->subject;

        return Inertia::render('compliance/Review', [
            'review' => [
                ...$this->review($workflow),
                // A sanctions review doesn't need the customer's finances or loan application.
                'customer' => Arr::only($customer->toArray(), ['id', ...array_diff($customer->getFillable(), Customer::FINANCIAL_FIELDS, Customer::LOAN_FIELDS)]),
                'earlier_reviews' => $this->earlierReviews($workflow),
            ],
        ]);
    }

    /**
     * Record the decision on the step the officer reviewed. The run is locked while the
     * decision is recorded, so of two officers deciding at once only the first counts; the
     * other is told, as is an officer whose run has moved on to another step since they
     * opened it.
     */
    public function decide(StoreReviewDecisionRequest $request, int $run, CustomerVerification $verification): RedirectResponse
    {
        $decided = DB::transaction(function () use ($request, $run, $verification): bool {
            $workflow = WorkflowInstance::where('workflow', WorkflowInstance::CUSTOMER_VERIFICATION)->lockForUpdate()->findOrFail($run);

            if ((CustomerVerification::REVIEW_PLACES[$workflow->status] ?? null) !== $request->validated('step')) {
                return false;
            }

            $verification->decide($workflow, $request->validated('decision'), $request->validated('notes'), $request->user(), $request->ip());
            $workflow->save();

            return true;
        });

        if (! $decided) {
            Inertia::flash('error', 'Someone else has already completed this review, so your decision wasn’t recorded.');
        }

        return to_route('compliance.reviews.index');
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
