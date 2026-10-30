<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\WorkflowInstance;
use App\Workflows\CustomerVerification\CustomerVerification;
use App\Workflows\FinancialScoring\FinancialScoring;
use App\Workflows\StateMachineWorkflow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Signature('workflows:reconstruct-history')]
#[Description('Rebuild the steps of workflow runs from before steps were recorded, from the audit log')]
class ReconstructWorkflowHistory extends Command
{
    /**
     * Runs from before steps were recorded have no transitions, but each flag, review
     * decision, outcome and score was audited on the customer. Replaying the audit entries
     * from a run's start until the next run of the same workflow began, through the
     * workflow's state machine, recovers its steps; entries that aren't a step from where
     * the run is (such as an outcome repeating a final review decision) are skipped. A run
     * that was superseded is taken to have been superseded when the next run started.
     *
     * Runs that already have steps are left alone, so it's safe to run again. A run whose
     * entries don't lead all the way to its status is reported, with the steps that were found.
     */
    public function handle(): int
    {
        $runs = WorkflowInstance::whereIn('workflow', array_keys(WorkflowInstance::STATE_MACHINES))
            ->whereDoesntHave('transitions')
            ->orderBy('id')
            ->get();

        foreach ($runs as $run) {
            $steps = $this->steps($run);

            DB::transaction(fn () => $run->transitions()->createMany($steps));

            if (($steps === [] ? null : end($steps)['to']) !== $run->status && $run->status !== 'pending') {
                $this->components->warn("Run {$run->id} ({$run->workflow}) could only be traced to ".($steps === [] ? 'its start' : end($steps)['to'])." of {$run->status}.");
            }
        }

        $this->components->info("Reconstructed the steps of {$runs->count()} runs.");

        return self::SUCCESS;
    }

    /**
     * @return list<array{transition: string, from: string, to: string, user_id: ?int, reconstructed: true, created_at: Carbon}>
     */
    private function steps(WorkflowInstance $run): array
    {
        $workflow = $run->stateMachineWorkflow();
        $nextRunStartedAt = WorkflowInstance::where('workflow', $run->workflow)
            ->where('subject_type', $run->subject_type)
            ->where('subject_id', $run->subject_id)
            ->where('id', '>', $run->id)
            ->orderBy('id')
            ->value('created_at');

        $entries = AuditLog::where('auditable_type', $run->subject_type)
            ->where('auditable_id', $run->subject_id)
            ->where('created_at', '>=', $run->created_at)
            ->when($nextRunStartedAt, fn ($query) => $query->where('created_at', '<', $nextRunStartedAt))
            ->orderBy('id')
            ->get();

        $place = array_key_first($workflow->placeLabels());
        $steps = [];

        foreach ($entries as $entry) {
            $transition = $this->transitionFor($workflow, $place, $entry);

            if ($transition !== null) {
                $steps[] = $this->step($workflow, $place, $transition, $entry->user_id, $entry->created_at);
                $place = end($steps)['to'];
            }
        }

        if ($run->status === 'superseded' && $nextRunStartedAt !== null && $workflow->placeAfter($place, StateMachineWorkflow::SUPERSEDE) !== null) {
            $steps[] = $this->step($workflow, $place, StateMachineWorkflow::SUPERSEDE, null, Carbon::parse($nextRunStartedAt));
        }

        return $steps;
    }

    /**
     * The transition an audit entry records, when it's one the run can take from where it is.
     */
    private function transitionFor(StateMachineWorkflow $workflow, string $place, AuditLog $entry): ?string
    {
        $values = $entry->new_values ?? [];

        $transition = match (true) {
            $entry->event === 'scored' => FinancialScoring::SCORE,
            $entry->event === 'flagged' => ($values['failed_step'] ?? null) === 'address' ? CustomerVerification::FLAG_ADDRESS : CustomerVerification::FLAG_CONTACT,
            // Decisions that don't name their step come from the engine's first, single-review process, where
            // the decision itself ended the check. The state machine has no such step, so the trace stops there.
            isset($values['review_decision']) && ! isset($values['review_step']) => null,
            isset($values['review_decision']) && $values['review_decision'] === 'rejected' => CustomerVerification::REJECT,
            isset($values['review_decision']) => $place === 'contact_review' ? CustomerVerification::APPROVE_CONTACT : CustomerVerification::APPROVE_ADDRESS,
            $entry->event === 'verified' => CustomerVerification::VERIFY,
            $entry->event === 'approved' => CustomerVerification::CLEAR_ADDRESS,
            default => null,
        };

        return $transition !== null && $workflow->placeAfter($place, $transition) !== null ? $transition : null;
    }

    /**
     * @return array{transition: string, from: string, to: string, user_id: ?int, reconstructed: true, created_at: Carbon}
     */
    private function step(StateMachineWorkflow $workflow, string $from, string $transition, ?int $userId, Carbon $at): array
    {
        return [
            'transition' => $transition,
            'from' => $from,
            'to' => $workflow->placeAfter($from, $transition),
            'user_id' => $userId,
            'reconstructed' => true,
            'created_at' => $at,
        ];
    }
}
