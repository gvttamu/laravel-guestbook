<?php

namespace App\Http\Controllers\Concerns;

use App\Models\WorkflowInstance;
use App\Workflows\CustomerVerification\CustomerVerification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A compliance review is a verification run waiting at one of its review places: the
 * contact or address step found a sanctions match for an officer to approve or reject.
 */
trait ListsComplianceReviews
{
    /**
     * The open reviews, longest waiting first, each with the customer it reviews.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function openReviews(): Collection
    {
        return $this->runsAwaitingReview()
            ->with('subject:id,first_name,last_name,email')
            ->get()
            ->map(fn (WorkflowInstance $run): array => [
                ...$this->review($run),
                'customer' => $run->subject->only(['id', 'first_name', 'last_name', 'email']),
            ])
            ->sortBy('flagged_at')
            ->values();
    }

    /**
     * @return Builder<WorkflowInstance>
     */
    private function runsAwaitingReview(): Builder
    {
        return WorkflowInstance::where('workflow', WorkflowInstance::CUSTOMER_VERIFICATION)
            ->whereIn('status', array_keys(CustomerVerification::REVIEW_PLACES));
    }

    /**
     * @return array{id: int, flagged_at: string, failed_step: string, matches: list<string>}
     */
    private function review(WorkflowInstance $run): array
    {
        return [
            'id' => $run->id,
            // Runs flagged by the old workflow engine have no flagged_at; they last changed when they were flagged.
            'flagged_at' => $run->result['flagged_at'] ?? $run->updated_at->toIso8601String(),
            'failed_step' => CustomerVerification::REVIEW_PLACES[$run->status],
            'matches' => $run->result['matches'] ?? [],
        ];
    }
}
