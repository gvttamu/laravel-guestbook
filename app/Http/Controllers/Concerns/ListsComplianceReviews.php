<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Models\WorkflowInstance;
use App\Workflows\CustomerVerification\CustomerVerification;
use Illuminate\Support\Collection;

/**
 * A compliance review is a verification run waiting at one of its review places: the
 * contact or address step found a sanctions match for an officer to approve or reject.
 */
trait ListsComplianceReviews
{
    /**
     * The open reviews the officer can pick up, unassigned or assigned to them, longest
     * waiting first, each with the customer it reviews.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function openReviews(User $officer): Collection
    {
        return WorkflowInstance::awaitingReviewBy($officer)
            ->with(['subject:id,first_name,last_name,email', 'assignedTo:id,name'])
            ->get()
            ->map(fn (WorkflowInstance $run): array => [
                ...$this->review($run),
                'customer' => $run->subject->only(['id', 'first_name', 'last_name', 'email']),
            ])
            ->sortBy('flagged_at')
            ->values();
    }

    /**
     * @return array{id: int, flagged_at: string, failed_step: string, matches: list<string>, assignee: ?array{id: int, name: string}}
     */
    private function review(WorkflowInstance $run): array
    {
        return [
            'id' => $run->id,
            'assignee' => $run->assignedTo?->only(['id', 'name']),
            // Runs flagged by the old workflow engine have no flagged_at; they last changed when they were flagged.
            'flagged_at' => $run->result['flagged_at'] ?? $run->updated_at->toIso8601String(),
            'failed_step' => CustomerVerification::REVIEW_PLACES[$run->status],
            'matches' => $run->result['matches'] ?? [],
        ];
    }
}
