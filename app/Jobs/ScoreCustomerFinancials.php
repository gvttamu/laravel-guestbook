<?php

namespace App\Jobs;

use App\Models\WorkflowInstance;
use App\Workflows\FinancialScoring\FinancialScoring;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ScoreCustomerFinancials implements ShouldQueue
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
     * Score the run's customer and let the decision engine approve, reject or refer the
     * application (see FinancialScoring::score). The run is locked while it's scored, so it
     * can't be superseded halfway; a run that's already scored or superseded is left alone.
     */
    public function handle(FinancialScoring $scoring): void
    {
        DB::transaction(function () use ($scoring): void {
            $run = WorkflowInstance::lockForUpdate()->find($this->workflowInstance->getKey());

            if ($run === null) {
                return;
            }

            $scoring->score($run);
            $run->save();
        });
    }
}
