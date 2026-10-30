<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Laravel's record of a workflow running in the Flowable engine. `external_id` is the
 * Flowable process instance id; `status` moves from pending (not yet started in
 * Flowable) to running, then to the outcome the engine reports back. A run that a
 * newer run replaces before it finishes becomes superseded.
 */
class WorkflowInstance extends Model
{
    public const CUSTOMER_VERIFICATION = 'customer-verification';

    public const FINANCIAL_SCORING = 'financial-scoring';

    /**
     * The key of the Flowable process definition each workflow runs.
     *
     * @var array<string, string>
     */
    public const PROCESS_DEFINITION_KEYS = [
        self::CUSTOMER_VERIFICATION => 'customerVerification',
        self::FINANCIAL_SCORING => 'financialScoring',
    ];

    /**
     * Statuses of a run that hasn't reached a final outcome yet.
     *
     * @var list<string>
     */
    public const UNFINISHED_STATUSES = ['pending', 'running', 'flagged'];

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

    public function processDefinitionKey(): string
    {
        return self::PROCESS_DEFINITION_KEYS[$this->workflow];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
