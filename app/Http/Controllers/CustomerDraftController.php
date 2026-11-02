<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCustomerDraftRequest;
use App\Jobs\RunVerificationChecks;
use App\Jobs\ScoreCustomerFinancials;
use App\Models\Customer;
use App\Models\FormDraft;
use App\Models\WorkflowInstance;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * New customers and changes to existing ones are both captured as drafts that
 * autosave as you go. Nothing reaches the customer record until the draft is submitted.
 */
class CustomerDraftController extends Controller
{
    public const FORM_TYPE = 'customer';

    /**
     * Bump when the payload shape changes, so older drafts can be migrated or discarded.
     */
    public const SCHEMA_VERSION = 1;

    /**
     * @var list<string>
     */
    public const STEPS = ['basic', 'details', 'financials', 'loan'];

    /**
     * Resume your draft of a new customer, or start one.
     */
    public function create(Request $request): RedirectResponse
    {
        $draft = FormDraft::whereBelongsTo($request->user())->where('form_type', self::FORM_TYPE)->whereNull('customer_id')->latest('updated_at')->first()
            ?? FormDraft::create([
                'form_type' => self::FORM_TYPE,
                'user_id' => $request->user()->id,
                'schema_version' => self::SCHEMA_VERSION,
                'payload' => [],
                'current_step' => self::STEPS[0],
                'version' => 1,
            ]);

        return to_route('customer-drafts.edit', ['customerDraft' => $draft, 'step' => $draft->current_step]);
    }

    /**
     * Resume your draft of changes to the customer, or start one from the customer's
     * saved details and version.
     */
    public function editCustomer(Request $request, Customer $customer): RedirectResponse
    {
        $draft = FormDraft::whereBelongsTo($request->user())->whereBelongsTo($customer)->latest('updated_at')->first();

        if ($draft === null) {
            $details = $this->customerDetails($customer);

            $draft = FormDraft::create([
                'form_type' => self::FORM_TYPE,
                'user_id' => $request->user()->id,
                'customer_id' => $customer->id,
                'customer_version' => $customer->version,
                'schema_version' => self::SCHEMA_VERSION,
                'payload' => $details,
                'original_payload' => $details,
                'current_step' => self::STEPS[0],
                'version' => 1,
            ]);
        }

        return to_route('customer-drafts.edit', ['customerDraft' => $draft, 'step' => $draft->current_step]);
    }

    /**
     * Show a step of the draft. A draft of changes also gets the customer as currently
     * saved, so the page can show what someone else has changed since editing began.
     */
    public function edit(FormDraft $customerDraft, string $step): Response
    {
        $customer = $customerDraft->customer;

        return Inertia::render('customers/CustomerForm', [
            'draft' => $customerDraft->only(['id', 'payload', 'original_payload', 'customer_version', 'version']),
            'customer' => $customer === null ? null : ['id' => $customer->id, 'version' => $customer->version, ...$this->customerDetails($customer)],
            'step' => $step,
        ]);
    }

    /**
     * Autosave the whole draft. Saves made from an out-of-date copy (another tab,
     * or a delayed request) are rejected with a 409 instead of overwriting newer data.
     */
    public function update(UpdateCustomerDraftRequest $request, FormDraft $customerDraft): JsonResponse
    {
        $version = $request->integer('version');

        $saved = FormDraft::whereKey($customerDraft->getKey())
            ->where('version', $version)
            ->update([
                'payload' => json_encode($request->safe()->except(['version', 'current_step'])),
                'current_step' => $request->validated('current_step'),
                'version' => $version + 1,
            ]);

        if ($saved === 0) {
            return response()->json([
                'message' => 'This draft was changed in another tab or window. Reload to continue.',
            ], 409);
        }

        return response()->json(['version' => $version + 1]);
    }

    /**
     * Validate the saved draft in full, then create the customer (or apply the changes
     * to it), record the audit entry, and discard the draft in one transaction.
     */
    public function submit(Request $request, FormDraft $customerDraft): RedirectResponse
    {
        if ($customerDraft->customer_id !== null) {
            return $this->saveChanges($request, $customerDraft);
        }

        [$customer, $runs] = DB::transaction(function () use ($request, $customerDraft): array {
            $draft = FormDraft::lockForUpdate()->findOrFail($customerDraft->getKey());

            $validated = Validator::make($draft->payload, Customer::validationRules())->validate();

            $customer = Customer::create($validated);

            $customer->auditLogs()->create([
                'user_id' => $request->user()->id,
                'event' => 'created',
                'new_values' => $validated,
                'ip_address' => $request->ip(),
            ]);

            $runs = collect([WorkflowInstance::CUSTOMER_VERIFICATION, WorkflowInstance::FINANCIAL_SCORING])
                ->map(fn (string $workflow): WorkflowInstance => $customer->workflows()->create([
                    'workflow' => $workflow,
                    'status' => 'pending',
                ]));

            $draft->delete();

            return [$customer, $runs];
        });

        $this->startRuns($runs);

        return $this->toInboxAfterSaving($customer, 'created');
    }

    /**
     * Throw the draft away. Passing `reopen` starts editing again from the customer's
     * latest saved details, which is how a draft that has fallen behind is refreshed.
     */
    public function destroy(Request $request, FormDraft $customerDraft): RedirectResponse
    {
        $customerDraft->delete();

        if ($customerDraft->customer_id === null) {
            return to_route('customers.index');
        }

        return $request->boolean('reopen')
            ? to_route('customers.edit', $customerDraft->customer_id)
            : to_route('customers.index', ['customer' => $customerDraft->customer_id]);
    }

    /**
     * Apply a draft of changes and record an "updated" audit entry holding the old and
     * new value of each changed field. Saving without changes records nothing.
     *
     * If someone else has saved the customer since the draft was started, the save is
     * rejected and the draft kept, instead of overwriting their changes.
     *
     * Changing a field the sanctions check screens starts a new verification run, and
     * changing the financials or the loan's amount or term starts a new scoring run. Either
     * cancels the previous run of that workflow if it hasn't finished, since it checked the old details.
     */
    private function saveChanges(Request $request, FormDraft $customerDraft): RedirectResponse
    {
        $customer = $customerDraft->customer;
        $version = $customerDraft->customer_version;

        $customer->fill(Validator::make($customerDraft->payload, Customer::validationRules(ignore: $customer))->validate());

        $changedFields = array_keys($customer->getDirty());
        $workflowsToRerun = array_keys(array_filter([
            WorkflowInstance::CUSTOMER_VERIFICATION => array_intersect($changedFields, Customer::SCREENED_FIELDS) !== [],
            WorkflowInstance::FINANCIAL_SCORING => array_intersect($changedFields, Customer::SCORED_FIELDS) !== [],
        ]));

        $newRuns = DB::transaction(function () use ($request, $customerDraft, $customer, $changedFields, $version, $workflowsToRerun): ?Collection {
            if ($changedFields === []) {
                $customerDraft->delete();

                return collect();
            }

            // Claiming the next version is a single atomic update, so of two
            // simultaneous saves from the same version only one can succeed.
            $claimed = Customer::whereKey($customer->getKey())
                ->where('version', $version)
                ->update(['version' => $version + 1]);

            if ($claimed === 0) {
                return null;
            }

            $customerDraft->delete();

            $oldValues = $this->auditValues($customer, $changedFields, original: true);

            $customer->version = $version + 1;
            $customer->save();

            $customer->auditLogs()->create([
                'user_id' => $request->user()->id,
                'event' => 'updated',
                'old_values' => $oldValues,
                'new_values' => $this->auditValues($customer, $changedFields),
                'ip_address' => $request->ip(),
            ]);

            if ($workflowsToRerun === []) {
                return collect();
            }

            $customer->workflows()
                ->whereIn('workflow', $workflowsToRerun)
                ->whereIn('status', WorkflowInstance::unfinishedStatuses())
                ->lockForUpdate()
                ->get()
                ->each->supersede();

            return collect($workflowsToRerun)->map(fn (string $workflow): WorkflowInstance => $customer->workflows()->create([
                'workflow' => $workflow,
                'status' => 'pending',
            ]));
        });

        if ($newRuns === null) {
            Inertia::flash('error', 'Someone else changed this customer after you started editing, so your changes weren’t saved.');

            return back();
        }

        $this->startRuns($newRuns);

        return $this->toInboxAfterSaving($customer, $changedFields === [] ? 'unchanged' : 'updated');
    }

    /**
     * Queue the jobs that run new workflow runs once the transaction commits.
     *
     * @param  Collection<int, WorkflowInstance>  $runs
     */
    private function startRuns(Collection $runs): void
    {
        foreach ($runs as $run) {
            match ($run->workflow) {
                WorkflowInstance::CUSTOMER_VERIFICATION => RunVerificationChecks::dispatch($run),
                WorkflowInstance::FINANCIAL_SCORING => ScoreCustomerFinancials::dispatch($run),
            };
        }
    }

    /**
     * Go to the inbox, which confirms the save once with a "Customer saved" message
     * linking to the customer's history.
     *
     * @param  'created'|'updated'|'unchanged'  $outcome
     */
    private function toInboxAfterSaving(Customer $customer, string $outcome): RedirectResponse
    {
        Inertia::flash('customerSaved', [
            'id' => $customer->id,
            'name' => "{$customer->first_name} {$customer->last_name}",
            'outcome' => $outcome,
        ]);

        return to_route('inbox');
    }

    /**
     * The customer's form fields as saved, with a plain date of birth.
     *
     * @return array<string, mixed>
     */
    private function customerDetails(Customer $customer): array
    {
        return Arr::only($customer->toArray(), $customer->getFillable());
    }

    /**
     * Read the given fields in the same format the "created" audit entry uses (dates as Y-m-d).
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function auditValues(Customer $customer, array $fields, bool $original = false): array
    {
        return collect($fields)
            ->mapWithKeys(function (string $field) use ($customer, $original): array {
                $value = $original ? $customer->getOriginal($field) : $customer->getAttribute($field);

                return [$field => $value instanceof CarbonInterface ? $value->toDateString() : $value];
            })
            ->all();
    }
}
