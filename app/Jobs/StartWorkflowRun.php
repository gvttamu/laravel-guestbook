<?php

namespace App\Jobs;

use App\Models\WorkflowInstance;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class StartWorkflowRun implements ShouldQueue
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
     * Start the run's process in Flowable, with a business key naming
     * this run. A retry first looks for a process an earlier attempt already started,
     * so a lost response never starts a second one.
     */
    public function handle(): void
    {
        if ($this->workflowInstance->status !== 'pending') {
            return;
        }

        $customerId = $this->workflowInstance->subject_id;
        $businessKey = "customer-{$customerId}-run-{$this->workflowInstance->id}";

        $processInstanceId = Http::flowable()
            ->get('runtime/process-instances', [
                'processDefinitionKey' => $this->workflowInstance->processDefinitionKey(),
                'businessKey' => $businessKey,
            ])
            ->throw()
            ->json('data.0.id');

        $processInstanceId ??= Http::flowable()
            ->post('runtime/process-instances', [
                'processDefinitionKey' => $this->workflowInstance->processDefinitionKey(),
                'businessKey' => $businessKey,
                'variables' => [
                    ['name' => 'customerId', 'type' => 'long', 'value' => $customerId],
                    ['name' => 'runId', 'type' => 'long', 'value' => $this->workflowInstance->id],
                ],
            ])
            ->throw()
            ->json('id');

        // The engine may report its result before this line runs, so only a
        // still-pending workflow moves to running.
        WorkflowInstance::whereKey($this->workflowInstance->getKey())->whereNull('external_id')->update(['external_id' => $processInstanceId]);
        WorkflowInstance::whereKey($this->workflowInstance->getKey())->where('status', 'pending')->update(['status' => 'running']);

        // The customer may have been edited while the process was starting, which
        // supersedes this run before it had a process id to cancel.
        $run = $this->workflowInstance->fresh();

        if ($run->status === 'superseded') {
            CancelWorkflowRun::dispatch($run);
        }
    }
}
