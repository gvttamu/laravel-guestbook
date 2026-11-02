<?php

namespace App\Jobs;

use App\Mail\ScreeningReviewWaiting;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Workflows\CustomerVerification\CustomerVerification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class NotifyOfficerOfScreeningReview implements ShouldQueue
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
     * Create a new job instance for the review of the given step. There's one job per officer,
     * so a failed send is retried for that officer alone, without emailing the others twice.
     * `$assignedBy` is the officer who assigned the review to this one, when that's why they're told.
     *
     * @param  'contact'|'address'  $step
     */
    public function __construct(public WorkflowInstance $workflowInstance, public string $step, public User $officer, public ?User $assignedBy = null)
    {
        $this->afterCommit();
    }

    /**
     * Email the officer that the run is waiting for review of the step, unless it no longer is:
     * another officer may have decided it, or the customer's details changed, before this ran.
     */
    public function handle(): void
    {
        $run = $this->workflowInstance->fresh();

        if ((CustomerVerification::REVIEW_PLACES[$run?->status ?? ''] ?? null) !== $this->step) {
            return;
        }

        Mail::to($this->officer)->send(new ScreeningReviewWaiting($run, $this->assignedBy));
    }
}
