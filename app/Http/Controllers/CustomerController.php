<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\FormDraft;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customers are created and edited through drafts; see CustomerDraftController.
 */
class CustomerController extends Controller
{
    /**
     * List customers. Passing `?customer={id}` also loads that customer's audit history;
     * the page fetches it with a partial reload so the list isn't queried again.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('customers/Index', [
            'customers' => fn () => Customer::with([
                'verification:id,subject_type,subject_id,workflow,status,current_task,result',
                'scoring:id,subject_type,subject_id,workflow,status,result',
            ])
                ->latest()
                ->latest('id')
                ->limit(50)
                ->get(['id', 'first_name', 'last_name', 'email', 'city', 'country', 'created_at']),
            'hasDraft' => fn () => FormDraft::whereBelongsTo($request->user())->whereNull('customer_id')->exists(),
            'customerIdsWithDrafts' => fn () => FormDraft::whereBelongsTo($request->user())->whereNotNull('customer_id')->pluck('customer_id'),
            'auditHistory' => fn () => $this->auditHistory($request->integer('customer')),
        ]);
    }

    /**
     * @return array{customer: array<string, mixed>, entries: mixed}|null
     */
    private function auditHistory(int $customerId): ?array
    {
        $customer = Customer::find($customerId, ['id', 'first_name', 'last_name']);

        if ($customer === null) {
            return null;
        }

        return [
            'customer' => $customer->only(['id', 'first_name', 'last_name']),
            'entries' => $customer->auditLogs()
                ->with('user:id,name')
                ->latest()
                ->latest('id')
                ->get(['id', 'auditable_type', 'auditable_id', 'user_id', 'event', 'old_values', 'new_values', 'ip_address', 'created_at']),
        ];
    }
}
