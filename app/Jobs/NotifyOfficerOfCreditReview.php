<?php

namespace App\Jobs;

use App\Mail\CreditReviewWaiting;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class NotifyOfficerOfCreditReview implements ShouldQueue
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
     * Create a new job instance. There's one job per officer, so a failed send is retried for
     * that officer alone, without emailing the others twice.
     */
    public function __construct(public WorkflowInstance $workflowInstance, public User $officer)
    {
        $this->afterCommit();
    }

    /**
     * Email the officer that the application is waiting for them, unless it no longer is: another
     * officer may have taken it on or decided it, or the customer's financials changed, before this ran.
     */
    public function handle(): void
    {
        $run = $this->workflowInstance->fresh();

        if ($run === null || ! $run->isAwaitingCreditReviewBy($this->officer)) {
            return;
        }

        Mail::to($this->officer)->send(new CreditReviewWaiting($run));
    }
}
