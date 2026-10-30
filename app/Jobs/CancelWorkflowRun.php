<?php

namespace App\Jobs;

use App\Models\WorkflowInstance;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class CancelWorkflowRun implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var list<int>
     */
    public array $backoff = [10, 30, 60, 300];

    /**
     * Create a new job instance.
     */
    public function __construct(public WorkflowInstance $workflowInstance)
    {
        $this->afterCommit();
    }

    /**
     * Delete a superseded run's process in Flowable, which also removes any review
     * task it has open. A process that has already ended is no longer there, so
     * Flowable answering "not found" means there is nothing left to cancel.
     */
    public function handle(): void
    {
        $response = Http::flowable()->delete(
            "runtime/process-instances/{$this->workflowInstance->external_id}?".http_build_query(['deleteReason' => 'Superseded by a newer run']),
        );

        if (! $response->notFound()) {
            $response->throw();
        }
    }
}
