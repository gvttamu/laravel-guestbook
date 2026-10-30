<?php

namespace Tests\Feature;

use App\Jobs\CancelWorkflowRun;
use App\Models\Customer;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CancelWorkflowRunTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const FLOWABLE = 'http://flowable.test/process-api';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.flowable.url' => self::FLOWABLE]);
    }

    public function test_deletes_the_runs_process_in_flowable(): void
    {
        Http::fake(['*' => Http::response(null, 204)]);

        (new CancelWorkflowRun($this->supersededRun()))->handle();

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_starts_with($request->url(), self::FLOWABLE.'/runtime/process-instances/process-1?deleteReason='));
    }

    public function test_a_process_that_has_already_ended_needs_no_cancelling(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Could not find a process instance'], 404)]);

        (new CancelWorkflowRun($this->supersededRun()))->handle();

        Http::assertSentCount(1);
    }

    public function test_a_flowable_error_fails_the_attempt_so_it_is_retried(): void
    {
        Http::fake(['*' => Http::response('Unavailable', 503)]);

        $this->expectException(RequestException::class);

        (new CancelWorkflowRun($this->supersededRun()))->handle();
    }

    private function supersededRun(): WorkflowInstance
    {
        return Customer::factory()->create()->workflows()->create([
            'workflow' => WorkflowInstance::CUSTOMER_VERIFICATION,
            'external_id' => 'process-1',
            'status' => 'superseded',
        ]);
    }
}
