<?php

namespace App\Models;

use Database\Factories\WorkflowTransitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step a workflow run took through its state machine: the transition, the places it
 * moved between, and who made it (null when it happened automatically). Transitions are
 * recorded as they happen; `reconstructed` ones were rebuilt afterwards from the audit
 * log for runs from before recording started, so their times are only as exact as it is.
 */
class WorkflowTransition extends Model
{
    /** @use HasFactory<WorkflowTransitionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'transition',
        'from',
        'to',
        'user_id',
        'reconstructed',
        'created_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reconstructed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
