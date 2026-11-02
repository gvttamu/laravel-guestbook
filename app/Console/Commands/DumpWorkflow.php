<?php

namespace App\Console\Commands;

use App\Models\WorkflowInstance;
use App\Workflows\StateMachineWorkflow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('workflows:dump
    {workflow : The workflow to export: customer-verification or financial-scoring}
    {--output= : Write the DOT to this file instead of printing it}')]
#[Description('Export a workflow\'s state machine as a Graphviz DOT graph')]
class DumpWorkflow extends Command
{
    /**
     * Print the workflow's DOT, or write it to --output. Render it with Graphviz, for
     * example `dot -Tsvg verification.dot -o verification.svg`.
     */
    public function handle(): int
    {
        $workflow = $this->argument('workflow');

        if (! isset(WorkflowInstance::STATE_MACHINES[$workflow])) {
            $this->components->error("There's no workflow called \"{$workflow}\". Choose one of: ".implode(', ', array_keys(WorkflowInstance::STATE_MACHINES)).'.');

            return self::INVALID;
        }

        /** @var StateMachineWorkflow $definition */
        $definition = app(WorkflowInstance::STATE_MACHINES[$workflow]);
        $dot = $definition->graphviz();

        if ($output = $this->option('output')) {
            file_put_contents($output, $dot);
            $this->components->info("Wrote the {$definition->title()} workflow to {$output}.");
        } else {
            $this->output->write($dot);
        }

        return self::SUCCESS;
    }
}
