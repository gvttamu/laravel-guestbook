<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCustomerScoringRequest;
use App\Models\Customer;
use App\Models\WorkflowInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CustomerScoringController extends Controller
{
    /**
     * Record the score a financial scoring run reports, with a "scored" audit entry naming
     * the engine's service account. Uses the same token ability as verification reports,
     * since both come from the workflow engine.
     */
    public function update(UpdateCustomerScoringRequest $request, Customer $customer): JsonResponse
    {
        $result = $request->safe()->only(['score', 'risk_band', 'debt_to_income', 'disposable_income', 'loan_repayment', 'reasons']);

        $run = DB::transaction(function () use ($request, $customer, $result): WorkflowInstance {
            $run = $customer->lockRunForReport(WorkflowInstance::FINANCIAL_SCORING, $request->validated('process_instance_id'), $request->validated('run_id'));

            abort_if($run === null, 404, 'This customer has no financial scoring run with that process instance id.');

            // The engine retries a report whose response it didn't receive; record it once.
            // A superseded run scored financials that have since changed, so its score is dropped.
            if (in_array($run->status, ['scored', 'superseded'], true)) {
                return $run;
            }

            $run->update([
                'external_id' => $request->validated('process_instance_id'),
                'status' => 'scored',
                'current_task' => null,
                'result' => $result,
            ]);

            $customer->auditLogs()->create([
                'user_id' => $request->user()->id,
                'event' => 'scored',
                'new_values' => $result,
                'ip_address' => $request->ip(),
            ]);

            return $run;
        });

        return response()->json(['status' => $run->status]);
    }
}
