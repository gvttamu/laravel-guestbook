<?php

namespace App\Jobs;

use App\Mail\NewGuestbookEntry;
use App\Models\Entry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class NotifyOwnerOfNewEntry implements ShouldQueue
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
    public array $backoff = [10, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(public Entry $entry)
    {
        $this->afterCommit();
    }

    /**
     * Email the guestbook owner about the new entry.
     */
    public function handle(): void
    {
        Mail::to(config('guestbook.owner_email'))->send(new NewGuestbookEntry($this->entry));
    }
}
