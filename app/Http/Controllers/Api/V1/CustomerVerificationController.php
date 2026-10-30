<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCustomerVerificationRequest;
use App\Models\Customer;
use App\Models\WorkflowInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CustomerVerificationController extends Controller
{
    /**
     * The token ability required to report verification results.
     */
    public const VERIFY_ABILITY = 'customers:verify';

    /**
     * Record a report from the workflow engine, with an audit entry naming the engine's
     * service account. A run reports "flagged" each time a step finds sanctions matches,
     * then its outcome: "verified", "approved" or "rejected".
     *
     * The result keeps the latest flagged step and its matches, and the reviews recorded
     * so far, so an outcome report doesn't erase what led to it.
     */
    public function update(UpdateCustomerVerificationRequest $request, Customer $customer): JsonResponse
    {
        $processInstanceId = $request->validated('process_instance_id');
        $runId = $request->validated('run_id');
        $report = [
            'status' => $request->validated('status'),
            'failed_step' => $request->validated('failed_step'),
            'matches' => $request->validated('matches'),
        ];

        $workflow = DB::transaction(function () use ($request, $customer, $processInstanceId, $runId, $report): WorkflowInstance {
            $workflow = $customer->lockRunForReport(WorkflowInstance::CUSTOMER_VERIFICATION, $processInstanceId, $runId);

            abort_if($workflow === null, 404, 'This customer has no verification workflow with that process instance id.');

            // The engine retries a report whose response it didn't receive; record it once.
            // A superseded run checked details that have since changed, so its result is dropped.
            $alreadyRecorded = $workflow->status === $report['status']
                && ($report['status'] !== 'flagged' || ($workflow->result['failed_step'] ?? null) === $report['failed_step']);

            if ($alreadyRecorded || $workflow->status === 'superseded') {
                return $workflow;
            }

            $result = [...($workflow->result ?? []), 'status' => $report['status']];

            if (! in_array($report['status'], ['approved', 'rejected'], true)) {
                $result = [...$result, 'failed_step' => $report['failed_step'], 'matches' => $report['matches']];
            }

            $workflow->update([
                'external_id' => $processInstanceId,
                'status' => $report['status'],
                'current_task' => null,
                'result' => $result,
            ]);

            $customer->auditLogs()->create([
                'user_id' => $request->user()->id,
                'event' => $report['status'],
                'new_values' => $report,
                'ip_address' => $request->ip(),
            ]);

            return $workflow;
        });

        return response()->json(['status' => $workflow->status]);
    }
}
