<?php

namespace Tests\Feature;

use App\Jobs\CancelWorkflowRun;
use App\Jobs\StartWorkflowRun;
use App\Models\Customer;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StartWorkflowRunTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.flowable.url' => 'http://flowable.test/process-api',
            'services.flowable.username' => 'rest-admin',
            'services.flowable.password' => 'secret',
        ]);
    }

    public function test_starts_the_process_with_a_business_key_naming_the_run_and_marks_it_running(): void
    {
        $workflow = $this->pendingWorkflow();
        Http::fake(fn (Request $request) => $request->method() === 'GET'
            ? Http::response(['data' => []])
            : Http::response(['id' => 'process-1'], 201));

        (new StartWorkflowRun($workflow))->handle();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'http://flowable.test/process-api/runtime/process-instances'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('rest-admin:secret'))
            && $request['processDefinitionKey'] === 'customerVerification'
            && $request['businessKey'] === "customer-{$workflow->subject_id}-run-{$workflow->id}"
            && $request['variables'] === [
                ['name' => 'customerId', 'type' => 'long', 'value' => $workflow->subject_id],
                ['name' => 'runId', 'type' => 'long', 'value' => $workflow->id],
            ]);
        $workflow->refresh();
        $this->assertSame('process-1', $workflow->external_id);
        $this->assertSame('running', $workflow->status);
    }

    public function test_a_scoring_run_starts_the_financial_scoring_process(): void
    {
        $run = Customer::factory()->create()->workflows()->create(['workflow' => WorkflowInstance::FINANCIAL_SCORING, 'status' => 'pending']);
        Http::fake(fn (Request $request) => $request->method() === 'GET'
            ? Http::response(['data' => []])
            : Http::response(['id' => 'process-1'], 201));

        (new StartWorkflowRun($run))->handle();

        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request['processDefinitionKey'] === 'financialScoring');
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['processDefinitionKey'] === 'financialScoring'
            && $request['businessKey'] === "customer-{$run->subject_id}-run-{$run->id}");
        $this->assertSame('running', $run->refresh()->status);
    }

    public function test_a_retry_reuses_a_process_an_earlier_attempt_started(): void
    {
        $workflow = $this->pendingWorkflow();
        Http::fake(['*' => Http::response(['data' => [['id' => 'process-from-earlier-attempt']]])]);

        (new StartWorkflowRun($workflow))->handle();

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
        $this->assertSame('process-from-earlier-attempt', $workflow->refresh()->external_id);
    }

    public function test_keeps_a_result_the_engine_reported_while_the_process_was_starting(): void
    {
        $workflow = $this->pendingWorkflow();
        Http::fake(function (Request $request) use ($workflow) {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            $workflow->newQuery()->whereKey($workflow->id)->update(['status' => 'verified', 'external_id' => 'process-1']);

            return Http::response(['id' => 'process-1'], 201);
        });

        (new StartWorkflowRun($workflow))->handle();

        $this->assertSame('verified', $workflow->refresh()->status);
    }

    public function test_cancels_the_process_if_the_run_was_superseded_while_it_was_starting(): void
    {
        Queue::fake([CancelWorkflowRun::class]);
        $workflow = $this->pendingWorkflow();
        Http::fake(function (Request $request) use ($workflow) {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            $workflow->newQuery()->whereKey($workflow->id)->update(['status' => 'superseded']);

            return Http::response(['id' => 'process-1'], 201);
        });

        (new StartWorkflowRun($workflow))->handle();

        $this->assertSame('superseded', $workflow->refresh()->status);
        Queue::assertPushed(CancelWorkflowRun::class, fn (CancelWorkflowRun $job) => $job->workflowInstance->external_id === 'process-1');
    }

    public function test_does_nothing_once_the_workflow_has_left_pending(): void
    {
        $workflow = $this->pendingWorkflow();
        $workflow->update(['status' => 'flagged']);
        Http::fake();

        (new StartWorkflowRun($workflow))->handle();

        Http::assertNothingSent();
    }

    public function test_a_flowable_error_fails_the_attempt_so_it_is_retried(): void
    {
        $workflow = $this->pendingWorkflow();
        Http::fake(['*' => Http::response('Unavailable', 503)]);

        $this->expectException(RequestException::class);

        try {
            (new StartWorkflowRun($workflow))->handle();
        } finally {
            $this->assertSame('pending', $workflow->refresh()->status);
            $this->assertNull($workflow->external_id);
        }
    }

    private function pendingWorkflow(): WorkflowInstance
    {
        return Customer::factory()->create()->workflow()->create([
            'workflow' => WorkflowInstance::CUSTOMER_VERIFICATION,
            'status' => 'pending',
        ]);
    }
}
