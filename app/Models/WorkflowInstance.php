<?php

namespace App\Models;

use App\Workflows\CustomerVerification\CustomerVerification;
use App\Workflows\FinancialScoring\CreditRiskTier;
use App\Workflows\FinancialScoring\FinancialScoring;
use App\Workflows\StateMachineWorkflow;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One run of a workflow for a subject (a customer). Each workflow is a Symfony state
 * machine (see STATE_MACHINES) whose place is the run's `status`. A run that a newer
 * run replaces before it finishes becomes superseded.
 *
 * A run awaiting a compliance or credit review is assigned (`assigned_to_id`) to the officer
 * who opens it, and only they can decide it; a compliance review can also be reassigned.
 * Deciding the review clears it.
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
     * Runs awaiting a compliance review that are the officer's to pick up: unassigned, or assigned to them.
     *
     * @param  Builder<WorkflowInstance>  $query
     */
    #[Scope]
    protected function awaitingReviewBy(Builder $query, User $officer): void
    {
        $query->where('workflow', self::CUSTOMER_VERIFICATION)
            ->whereIn('status', array_keys(CustomerVerification::REVIEW_PLACES))
            ->where(fn (Builder $query) => $query->whereNull('assigned_to_id')->orWhere('assigned_to_id', $officer->id));
    }

    /**
     * Loan applications waiting for a credit officer, referred by the decision engine or reconsidered
     * by an officer, that are this officer's to pick up: in their credit risk tier or a less risky one,
     * and unassigned or assigned to them.
     *
     * @param  Builder<WorkflowInstance>  $query
     */
    #[Scope]
    protected function awaitingCreditReviewBy(Builder $query, User $officer): void
    {
        $query->where('workflow', self::FINANCIAL_SCORING)
            ->where('status', FinancialScoring::REVIEW_PLACE)
            ->whereIn('result->credit_risk_tier', CreditRiskTier::upTo($officer->credit_risk_tier))
            ->where(fn (Builder $query) => $query->whereNull('assigned_to_id')->orWhere('assigned_to_id', $officer->id));
    }

    /**
     * Whether the run's review is assigned to an officer other than this one, making it theirs to decide or reassign.
     */
    public function isAssignedToSomeoneOtherThan(User $officer): bool
    {
        return $this->assigned_to_id !== null && $this->assigned_to_id !== $officer->id;
    }

    /**
     * Whether a credit officer may decide this loan application: it's in their credit risk tier or a less risky one.
     */
    public function isWithinCreditRiskTierOf(User $officer): bool
    {
        return in_array($this->result['credit_risk_tier'] ?? null, CreditRiskTier::upTo($officer->credit_risk_tier), true);
    }

    /**
     * Whether this loan application is waiting for the credit officer to pick up: referred, within
     * their credit risk tier, and unassigned or assigned to them.
     */
    public function isAwaitingCreditReviewBy(User $officer): bool
    {
        return $this->workflow === self::FINANCIAL_SCORING
            && $this->status === FinancialScoring::REVIEW_PLACE
            && $this->isWithinCreditRiskTierOf($officer)
            && ! $this->isAssignedToSomeoneOtherThan($officer);
    }

    /**
     * Assign the run's unassigned review to the officer opening it, with an audit entry, and
     * reload the run. Only one of two officers opening it at once gets it: the update applies
     * only while it's unassigned and still at the same step, so the other sees who did.
     */
    public function claimReviewFor(User $officer, ?string $ipAddress): void
    {
        $claimed = self::whereKey($this->id)
            ->where('status', $this->status)
            ->whereNull('assigned_to_id')
            ->update(['assigned_to_id' => $officer->id]);

        if ($claimed) {
            $this->subject->auditLogs()->create([
                'user_id' => $officer->id,
                'event' => 'assigned',
                'old_values' => ['review_assignee' => null],
                'new_values' => ['review_assignee' => $officer->name],
                'ip_address' => $ipAddress,
            ]);
        }

        $this->refresh()->load('assignedTo:id,name');
    }

    /**
     * The compliance officer the run's open review is assigned to, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
