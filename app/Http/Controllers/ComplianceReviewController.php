<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsComplianceReviews;
use App\Http\Requests\StoreReviewDecisionRequest;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Compliance reviews are the "Review contact match" and "Review address match" user
 * tasks in the Flowable customer verification process. Flowable owns the task list;
 * this controller shows those tasks with the customer's details and completes them.
 */
class ComplianceReviewController extends Controller
{
    use ListsComplianceReviews;

    public function index(): Response
    {
        $reviews = $this->openReviews();

        return Inertia::render('compliance/Reviews', [
            'reviews' => $reviews ?? [],
            'engineAvailable' => $reviews !== null,
        ]);
    }

    public function show(string $task): Response
    {
        [$task, $workflow] = $this->findReview($task);

        /** @var Customer $customer */
        $customer = $workflow->subject;

        return Inertia::render('compliance/Review', [
            'review' => [
                ...$this->review($task, $workflow),
                // A sanctions review doesn't need the customer's finances or loan application.
                'customer' => Arr::only($customer->toArray(), ['id', ...array_diff($customer->getFillable(), Customer::FINANCIAL_FIELDS, Customer::LOAN_FIELDS)]),
                'earlier_reviews' => $this->earlierReviews($workflow),
            ],
        ]);
    }

    /**
     * Record the decision on the customer's workflow with an audit entry, then complete
     * the review task in Flowable. The workflow goes back to "running": the engine may
     * still check the address, and it reports the outcome when the check ends.
     *
     * The workflow is updated before the task is completed, in one transaction, so the
     * engine's next report can't arrive first and then be overwritten. If Flowable
     * doesn't complete the task, nothing is recorded.
     */
    public function decide(StoreReviewDecisionRequest $request, string $task): RedirectResponse
    {
        [$task, $workflow] = $this->findReview($task);

        $decision = $request->validated('decision');
        $notes = $request->validated('notes');
        $isLegacyReview = $task['taskDefinitionKey'] === self::LEGACY_REVIEW_TASK;
        $step = $isLegacyReview ? $workflow->result['failed_step'] : self::REVIEW_TASKS[$task['taskDefinitionKey']];
        $status = $isLegacyReview ? $decision : 'running';

        DB::transaction(function () use ($request, $task, $workflow, $decision, $notes, $step, $status): void {
            $claimed = WorkflowInstance::whereKey($workflow->getKey())->where('status', 'flagged')->update(['status' => $status]);

            if ($claimed === 0) {
                throw ValidationException::withMessages(['decision' => 'Someone else has already completed this review.']);
            }

            $workflow->update([
                'status' => $status,
                'result' => [...$workflow->result, 'reviews' => [
                    ...($workflow->result['reviews'] ?? []),
                    [
                        'step' => $step,
                        'matches' => $workflow->result['matches'] ?? [],
                        'decision' => $decision,
                        'notes' => $notes,
                        'reviewed_by' => $request->user()->id,
                        'reviewed_at' => now()->toIso8601String(),
                    ],
                ]],
            ]);

            $workflow->subject->auditLogs()->create([
                'user_id' => $request->user()->id,
                'event' => $decision,
                'new_values' => ['review_step' => $step, 'review_decision' => $decision, 'review_notes' => $notes],
                'ip_address' => $request->ip(),
            ]);

            $completion = Http::flowable()->post("runtime/tasks/{$task['id']}", [
                'action' => 'complete',
                'variables' => [
                    ['name' => 'reviewDecision', 'value' => $decision],
                    ['name' => 'reviewNotes', 'value' => $notes ?? ''],
                ],
            ]);

            if ($completion->notFound()) {
                throw ValidationException::withMessages(['decision' => 'Someone else has already completed this review.']);
            }

            $completion->throw();
        });

        return to_route('compliance.reviews.index');
    }

    /**
     * Fetch an open review task and the customer workflow it belongs to.
     *
     * @return array{0: array<string, mixed>, 1: WorkflowInstance}
     */
    private function findReview(string $taskId): array
    {
        $response = Http::flowable()->get("runtime/tasks/{$taskId}");

        abort_if($response->notFound(), 404);

        $task = $response->throw()->json();

        $workflow = WorkflowInstance::with('subject')
            ->where('workflow', WorkflowInstance::CUSTOMER_VERIFICATION)
            ->where('status', 'flagged')
            ->where('external_id', $task['processInstanceId'] ?? null)
            ->first();

        abort_if($workflow === null || ! $this->reviewsCurrentFlag($task, $workflow), 404);

        return [$task, $workflow];
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
