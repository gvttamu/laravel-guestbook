<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\WorkflowInstance;
use App\Workflows\FinancialScoring\FinancialScorecard;
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
     * Score the run's customer and record the result, with a "scored" audit entry that has
     * no user, since nobody did it by hand. The run is locked while it's scored, so it can't
     * be superseded halfway; a run that's already scored or superseded is left alone.
     */
    public function handle(FinancialScoring $scoring, FinancialScorecard $scorecard): void
    {
        DB::transaction(function () use ($scoring, $scorecard): void {
            $run = WorkflowInstance::lockForUpdate()->find($this->workflowInstance->getKey());

            if ($run === null || ! $scoring->stateMachine()->can($run, FinancialScoring::SCORE)) {
                return;
            }

            /** @var Customer $customer */
            $customer = $run->subject;

            $run->result = $scorecard->score($customer);
            $scoring->apply($run, FinancialScoring::SCORE);
            $run->save();

            $customer->auditLogs()->create([
                'event' => 'scored',
                'new_values' => $run->result,
            ]);
        });
    }
}
