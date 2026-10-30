<?php

namespace App\Jobs;

use App\Models\WorkflowInstance;
use App\Workflows\CustomerVerification\CustomerVerification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RunVerificationChecks implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public WorkflowInstance $workflowInstance)
    {
        $this->afterCommit();
    }

    /**
     * Screen the run's customer against the sanctions list. The run is locked while it's
     * checked, so it can't be superseded halfway.
     */
    public function handle(CustomerVerification $verification): void
    {
        DB::transaction(function () use ($verification): void {
            $run = WorkflowInstance::lockForUpdate()->find($this->workflowInstance->getKey());

            if ($run === null) {
                return;
            }

            $verification->runChecks($run);
            $run->save();
        });
    }
}
