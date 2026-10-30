<?php

namespace App\Models;

use App\Workflows\CustomerVerification\CustomerVerification;
use App\Workflows\FinancialScoring\FinancialScoring;
use App\Workflows\StateMachineWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One run of a workflow for a subject (a customer). Each workflow is a Symfony state
 * machine (see STATE_MACHINES) whose place is the run's `status`. A run that a newer
 * run replaces before it finishes becomes superseded.
 *
 * `external_id` is the Flowable process instance id of runs from before the workflows
 * moved into the app; newer runs have none.
 */
class WorkflowInstance extends Model
{
    public const CUSTOMER_VERIFICATION = 'customer-verification';

    public const FINANCIAL_SCORING = 'financial-scoring';

    /**
     * The state machine each workflow runs.
     *
     * @var array<string, class-string<StateMachineWorkflow>>
     */
    public const STATE_MACHINES = [
        self::CUSTOMER_VERIFICATION => CustomerVerification::class,
        self::FINANCIAL_SCORING => FinancialScoring::class,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workflow',
        'external_id',
        'status',
        'current_task',
        'result',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => 'array',
        ];
    }

    /**
     * Statuses of a run that hasn't reached a final outcome yet, in any workflow.
     *
     * @return list<string>
     */
    public static function unfinishedStatuses(): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(fn (string $workflow): array => app($workflow)->unfinishedPlaces(), array_values(self::STATE_MACHINES)),
        )));
    }

    /**
     * The state machine this run follows.
     */
    public function stateMachineWorkflow(): StateMachineWorkflow
    {
        return app(self::STATE_MACHINES[$this->workflow]);
    }

    /**
     * Mark an unfinished run as replaced by a newer one, by its state machine's "supersede"
     * transition, so it can only be superseded from a step that allows it.
     */
    public function supersede(): void
    {
        $this->stateMachineWorkflow()->apply($this, StateMachineWorkflow::SUPERSEDE);
        $this->current_task = null;
        $this->save();
    }

    /**
     * The steps the run has taken, in order.
     *
     * @return HasMany<WorkflowTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class)->orderBy('id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
