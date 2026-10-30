<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsComplianceReviews;
use App\Models\FormDraft;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything waiting on the signed-in user, oldest first: open sanctions reviews for
 * compliance officers, and unfinished customer drafts for users who capture customers.
 */
class InboxController extends Controller
{
    use ListsComplianceReviews;

    public function index(Request $request): Response
    {
        $reviews = $request->user()->can('review-compliance') ? $this->openReviews() : collect();
        $drafts = $request->user()->can('capture-customers') ? $this->draftItems($request) : collect();

        $items = $this->reviewItems($reviews ?? collect())
            ->concat($drafts)
            ->sortBy(fn (array $item): int => Carbon::parse($item['since'])->getTimestamp())
            ->values();

        return Inertia::render('Inbox', [
            'items' => $items,
            'engineAvailable' => $reviews !== null,
            // Replaces the shared count, so the nav badge matches the list shown.
            'inboxCount' => $items->count(),
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $reviews
     * @return Collection<int, array<string, mixed>>
     */
    private function reviewItems(Collection $reviews): Collection
    {
        return $reviews->map(fn (array $review): array => [
            'key' => "review-{$review['id']}",
            'type' => 'sanctions_review',
            'customer' => $review['customer'],
            'failed_step' => $review['failed_step'],
            'matches' => $review['matches'],
            'since' => $review['created_at'],
            'url' => route('compliance.reviews.show', $review['id']),
        ]);
    }

    /**
     * The user's drafts of new customers and of changes to existing ones. A draft of
     * changes is out of date when someone else has saved the customer since it started.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function draftItems(Request $request): Collection
    {
        return FormDraft::with('customer:id,first_name,last_name,email,version')
            ->whereBelongsTo($request->user())
            ->where('form_type', CustomerDraftController::FORM_TYPE)
            ->get()
            ->map(fn (FormDraft $draft): array => [
                'key' => "draft-{$draft->id}",
                'type' => $draft->customer === null ? 'new_customer' : 'customer_changes',
                // A new customer is named by whatever the draft has so far, which may be nothing.
                'customer' => $draft->customer?->only(['id', 'first_name', 'last_name', 'email']) ?? [
                    'id' => null,
                    'first_name' => $draft->payload['first_name'] ?? null,
                    'last_name' => $draft->payload['last_name'] ?? null,
                    'email' => $draft->payload['email'] ?? null,
                ],
                'step' => $draft->current_step,
                'is_out_of_date' => $draft->customer !== null && $draft->customer->version !== $draft->customer_version,
                'since' => $draft->updated_at->toIso8601String(),
                'url' => route('customer-drafts.edit', ['customerDraft' => $draft, 'step' => $draft->current_step]),
            ]);
    }
}
