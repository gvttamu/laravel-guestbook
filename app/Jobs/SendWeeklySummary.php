<?php

namespace App\Jobs;

use App\Mail\WeeklyGuestbookSummary;
use App\Models\Entry;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendWeeklySummary implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300];

    /**
     * Email the owner a PDF summary of last week's (Monday to Sunday) entries.
     */
    public function handle(): void
    {
        $ownerEmail = config('guestbook.owner_email');

        if (blank($ownerEmail)) {
            return;
        }

        $weekStart = now()->toImmutable()->subWeek()->startOfWeek(CarbonInterface::MONDAY);
        $weekEnd = $weekStart->endOfWeek(CarbonInterface::SUNDAY);

        $entries = Entry::whereBetween('created_at', [$weekStart, $weekEnd])
            ->oldest()
            ->oldest('id')
            ->get();

        Mail::to($ownerEmail)->send(new WeeklyGuestbookSummary($entries, $weekStart, $weekEnd));
    }
}
