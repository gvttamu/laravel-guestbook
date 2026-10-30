<?php

namespace App\Http\Controllers\Concerns;

use App\Models\WorkflowInstance;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Compliance reviews are the "Review contact match" and "Review address match" user
 * tasks in the Flowable customer verification process. Flowable owns the task list;
 * this reads it and pairs each task with the flagged customer it reviews.
 */
trait ListsComplianceReviews
{
    /**
     * The review tasks in customer-verification.bpmn20.xml, and the step whose matches each reviews.
     *
     * @var array<string, string>
     */
    private const REVIEW_TASKS = [
        'reviewContact' => 'contact',
        'reviewAddress' => 'address',
    ];

    /**
     * The single review task of process versions 1 and 2. Those processes end after the
     * review without reporting an outcome, so the decision itself is the outcome.
     */
    private const LEGACY_REVIEW_TASK = 'reviewMatch';

    /**
     * The open review tasks, oldest first, each with the customer it reviews. Null when
     * the workflow engine can't be reached.
     *
     * @return Collection<int, array<string, mixed>>|null
     */
    private function openReviews(): ?Collection
    {
        try {
            $tasks = Http::flowable()
                ->get('runtime/tasks', [
                    'processDefinitionKey' => 'customerVerification',
                    'candidateGroup' => 'compliance',
                    'sort' => 'createTime',
                    'order' => 'asc',
                    'size' => 100,
                ])
                ->throw()
                ->json('data');
        } catch (ConnectionException|RequestException) {
            return null;
        }

        $workflows = WorkflowInstance::with('subject:id,first_name,last_name,email')
            ->where('workflow', WorkflowInstance::CUSTOMER_VERIFICATION)
            ->where('status', 'flagged')
            ->whereIn('external_id', array_column($tasks, 'processInstanceId'))
            ->get()
            ->keyBy('external_id');

        return collect($tasks)
            ->filter(fn (array $task): bool => $workflows->has($task['processInstanceId'])
                && $this->reviewsCurrentFlag($task, $workflows[$task['processInstanceId']]))
            ->map(fn (array $task): array => [
                ...$this->review($task, $workflows[$task['processInstanceId']]),
                'customer' => $workflows[$task['processInstanceId']]->subject->only(['id', 'first_name', 'last_name', 'email']),
            ])
            ->values();
    }

    /**
     * Whether the task is a review task for the step the workflow is currently flagged at.
     *
     * @param  array<string, mixed>  $task
     */
    private function reviewsCurrentFlag(array $task, WorkflowInstance $workflow): bool
    {
        if (($task['taskDefinitionKey'] ?? null) === self::LEGACY_REVIEW_TASK) {
            return true;
        }

        $step = self::REVIEW_TASKS[$task['taskDefinitionKey'] ?? ''] ?? null;

        return $step !== null && $step === ($workflow->result['failed_step'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function review(array $task, WorkflowInstance $workflow): array
    {
        return [
            'id' => $task['id'],
            'created_at' => $task['createTime'],
            'failed_step' => $workflow->result['failed_step'] ?? null,
            'matches' => $workflow->result['matches'] ?? [],
        ];
    }
}
