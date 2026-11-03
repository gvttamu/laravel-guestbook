<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\FormDraft;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Workflows\CustomerVerification\CustomerVerification;
use App\Workflows\FinancialScoring\FinancialScoring;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A customer's case: everything about them in one place. Their details, every verification
 * and financial scoring run with the steps it took, links to any review waiting for the
 * officer viewing it, their own unsaved changes, and the audit trail of who did what.
 *
 * Compliance officers see the case without the customer's finances, loan or scores, as their
 * sanctions reviews don't need them; everyone else sees all of it.
 */
class CustomerCaseController extends Controller
{
    /**
     * Audit events that only record finances, scores or loan decisions, left out for compliance officers.
     */
    private const FINANCIAL_EVENTS = ['scored', 'loan_approved', 'loan_rejected', 'loan_referred', 'loan_reconsidered'];

    public function show(Request $request, Customer $customer): Response
    {
        $user = $request->user();
        $showsFinances = ! $user->isComplianceOfficer();
        $hiddenFields = $showsFinances ? [] : [...Customer::FINANCIAL_FIELDS, ...Customer::LOAN_FIELDS];

        $runs = $customer->workflows()->latest('id')->get();

        return Inertia::render('customers/Case', [
            'customer' => Arr::except(
                $customer->only(['id', 'version', 'created_at', 'updated_at', ...$customer->getFillable()]),
                $hiddenFields,
            ),
            'showsFinances' => $showsFinances,
            'runs' => $runs->map(fn (WorkflowInstance $run): array => $this->run($run, $user, $showsFinances))->all(),
            'timeline' => $this->timeline($customer, $hiddenFields, $showsFinances),
            'editUrl' => $user->can('capture-customers') ? route('customers.edit', $customer) : null,
            'hasDraft' => FormDraft::whereBelongsTo($user)
                ->where('form_type', CustomerDraftController::FORM_TYPE)
                ->whereBelongsTo($customer)
                ->exists(),
        ]);
    }

    /**
     * A workflow run with the steps it took, what it found, and a link to its review when it's
     * waiting for the officer viewing the case.
     *
     * @return array<string, mixed>
     */
    private function run(WorkflowInstance $run, User $user, bool $showsFinances): array
    {
        $workflow = $run->stateMachineWorkflow();
        $result = $run->result ?? [];

        $details = $run->workflow === WorkflowInstance::CUSTOMER_VERIFICATION
            ? ['matches' => $result['matches'] ?? []]
            : ($showsFinances ? Arr::only($result, ['score', 'risk_band', 'credit_score', 'credit_risk_tier', 'engine_decision']) : []);

        return [
            'id' => $run->id,
            'workflow' => $run->workflow,
            'title' => $workflow->title(),
            'status' => $run->status,
            'status_label' => $workflow->placeLabels()[$run->status] ?? $run->status,
            'assigned_to' => $run->assignedTo?->name,
            'details' => $details,
            'history' => $workflow->history($run),
            'review_url' => $this->reviewUrl($run, $user),
        ];
    }

    /**
     * Where the officer viewing the case can review the run, if it's waiting for a review they can do.
     */
    private function reviewUrl(WorkflowInstance $run, User $user): ?string
    {
        if ($run->workflow === WorkflowInstance::CUSTOMER_VERIFICATION) {
            return $user->can('review-compliance') && isset(CustomerVerification::REVIEW_PLACES[$run->status])
                ? route('compliance.reviews.show', $run)
                : null;
        }

        return $user->can('review-credit') && $run->status === FinancialScoring::REVIEW_PLACE
            ? route('credit.reviews.show', $run)
            : null;
    }

    /**
     * The audit trail, newest first, without financial entries and values for those who don't see finances.
     *
     * @param  list<string>  $hiddenFields
     * @return list<array<string, mixed>>
     */
    private function timeline(Customer $customer, array $hiddenFields, bool $showsFinances): array
    {
        return $customer->auditLogs()
            ->with('user:id,name')
            ->unless($showsFinances, fn ($query) => $query->whereNotIn('event', self::FINANCIAL_EVENTS))
            ->latest()
            ->latest('id')
            ->get()
            ->map(fn (AuditLog $entry): array => [
                'id' => $entry->id,
                'event' => $entry->event,
                'user' => $entry->user?->only(['id', 'name']),
                'old_values' => $entry->old_values === null ? null : Arr::except($entry->old_values, $hiddenFields),
                'new_values' => $entry->new_values === null ? null : Arr::except($entry->new_values, $hiddenFields),
                'ip_address' => $entry->ip_address,
                'created_at' => $entry->created_at->toIso8601String(),
            ])
            ->all();
    }
}
