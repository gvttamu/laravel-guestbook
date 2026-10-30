<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerVerificationController;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerVerificationApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_token_that_can_only_read_customers_cannot_report_results(): void
    {
        [$customer] = $this->customerWithWorkflow();
        Sanctum::actingAs(User::factory()->create(), [CustomerController::READ_ABILITY]);

        $this->putJson("/api/v1/customers/{$customer->id}/verification", $this->verified())->assertForbidden();
    }

    public function test_records_a_verified_result_with_an_audit_entry_naming_the_engine(): void
    {
        [$customer, $workflow] = $this->customerWithWorkflow(externalId: null);
        $engine = $this->actingAsEngine();

        $this->putJson("/api/v1/customers/{$customer->id}/verification", [...$this->verified(), 'run_id' => $workflow->id])
            ->assertOk()
            ->assertExactJson(['status' => 'verified']);

        $workflow->refresh();
        $this->assertSame('verified', $workflow->status);
        $this->assertSame('process-1', $workflow->external_id);
        $this->assertSame(['status' => 'verified', 'failed_step' => null, 'matches' => []], $workflow->result);

        $audit = $customer->auditLogs()->sole();
        $this->assertSame('verified', $audit->event);
        $this->assertTrue($audit->user->is($engine));
    }

    public function test_records_a_flagged_result_with_the_failed_step_and_matches(): void
    {
        [$customer, $workflow] = $this->customerWithWorkflow();
        $this->actingAsEngine();

        $this->putJson("/api/v1/customers/{$customer->id}/verification", [
            'status' => 'flagged',
            'failed_step' => 'contact',
            'matches' => ['Phone number +1 555 010 0666 is on the Demo sanctions list'],
            'process_instance_id' => 'process-1',
        ])->assertOk();

        $workflow->refresh();
        $this->assertSame('flagged', $workflow->status);
        $this->assertSame('contact', $workflow->result['failed_step']);
        $this->assertSame(['Phone number +1 555 010 0666 is on the Demo sanctions list'], $customer->auditLogs()->sole()->new_values['matches']);
    }

    public function test_a_repeated_report_is_recorded_once(): void
    {
        [$customer] = $this->customerWithWorkflow();
        $this->actingAsEngine();

        $this->putJson("/api/v1/customers/{$customer->id}/verification", $this->verified())->assertOk();
        $this->putJson("/api/v1/customers/{$customer->id}/verification", $this->verified())->assertOk();

        $this->assertSame(1, $customer->auditLogs()->count());
    }

    public function test_rejects_a_report_for_a_different_process_instance(): void
    {
        [$customer, $workflow] = $this->customerWithWorkflow();
        $this->actingAsEngine();

        $this->putJson("/api/v1/customers/{$customer->id}/verification", [...$this->verified(), 'process_instance_id' => 'process-2'])
            ->assertNotFound();

        $this->assertSame('running', $workflow->refresh()->status);
        $this->assertSame(0, $customer->auditLogs()->count());
    }

    public function test_a_flag_at_the_address_step_after_a_reviewed_contact_flag_is_recorded(): void
    {
        [$customer, $workflow] = $this->customerWithWorkflow();
        $workflow->update(['result' => $this->reviewedContactFlag()]);
        $this->actingAsEngine();

        $this->putJson("/api/v1/customers/{$customer->id}/verification", [
            'status' => 'flagged',
            'failed_step' => 'address',
            'matches' => ['Address 13 Blackwater Road, EC1A 1BB, United Kingdom is on the Demo sanctions list'],
            'process_instance_id' => 'process-1',
        ])->assertOk();

        $workflow->refresh();
        $this->assertSame('flagged', $workflow->status);
        $this->assertSame('address', $workflow->result['failed_step']);
        $this->assertSame(['Address 13 Blackwater Road, EC1A 1BB, United Kingdom is on the Demo sanctions list'], $workflow->result['matches']);
        $this->assertSame('contact', $workflow->result['reviews'][0]['step']);
        $this->assertSame('flagged', $customer->auditLogs()->sole()->event);
    }

    public function test_the_outcome_keeps_the_flagged_step_and_reviews_that_led_to_it(): void
    {
        [$customer, $workflow] = $this->customerWithWorkflow();
        $workflow->update(['result' => $this->reviewedContactFlag()]);
        $engine = $this->actingAsEngine();

        $this->putJson("/api/v1/customers/{$customer->id}/verification", [...$this->verified(), 'status' => 'approved'])
            ->assertOk()
            ->assertExactJson(['status' => 'approved']);

        $workflow->refresh();
        $this->assertSame('approved', $workflow->status);
        $this->assertSame('contact', $workflow->result['failed_step']);
        $this->assertSame(['Phone number +1 555 010 0666 is on the Demo sanctions list'], $workflow->result['matches']);
        $this->assertSame('approved', $workflow->result['reviews'][0]['decision']);

        $audit = $customer->auditLogs()->sole();
        $this->assertSame('approved', $audit->event);
        $this->assertTrue($audit->user->is($engine));
    }

    public function test_a_late_report_from_a_superseded_run_is_dropped(): void
    {
        [$customer, $oldRun] = $this->customerWithWorkflow();
        $oldRun->update(['status' => 'superseded']);
        $newRun = $customer->workflows()->create(['workflow' => WorkflowInstance::CUSTOMER_VERIFICATION, 'status' => 'pending']);
        $this->actingAsEngine();

        $this->putJson("/api/v1/customers/{$customer->id}/verification", $this->verified())
            ->assertOk()
            ->assertExactJson(['status' => 'superseded']);

        $this->assertSame('superseded', $oldRun->refresh()->status);
        $this->assertSame('pending', $newRun->refresh()->status);
        $this->assertNull($newRun->external_id);
        $this->assertSame(0, $customer->auditLogs()->count());
    }

    public function test_an_early_report_from_a_superseded_run_is_not_credited_to_the_new_run(): void
    {
        [$customer, $oldRun] = $this->customerWithWorkflow(externalId: null);
        $oldRun->update(['status' => 'superseded']);
        $newRun = $customer->workflows()->create(['workflow' => WorkflowInstance::CUSTOMER_VERIFICATION, 'status' => 'pending']);
        $this->actingAsEngine();

        $this->putJson("/api/v1/customers/{$customer->id}/verification", [...$this->verified(), 'run_id' => $oldRun->id])
            ->assertOk()
            ->assertExactJson(['status' => 'superseded']);

        $this->assertSame('pending', $newRun->refresh()->status);
        $this->assertNull($newRun->external_id);
        $this->assertSame(0, $customer->auditLogs()->count());
    }

    public function test_a_report_without_a_known_process_or_run_id_is_not_found(): void
    {
        [$customer, $workflow] = $this->customerWithWorkflow(externalId: null);
        $this->actingAsEngine();

        $this->putJson("/api/v1/customers/{$customer->id}/verification", $this->verified())->assertNotFound();
        $this->putJson("/api/v1/customers/{$customer->id}/verification", [...$this->verified(), 'run_id' => $workflow->id + 1])->assertNotFound();

        $this->assertSame('running', $workflow->refresh()->status);
    }

    public function test_a_flagged_result_must_name_the_failed_step(): void
    {
        [$customer] = $this->customerWithWorkflow();
        $this->actingAsEngine();

        $this->putJson("/api/v1/customers/{$customer->id}/verification", [...$this->verified(), 'status' => 'flagged'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['failed_step' => 'The failed step field is required when status is flagged.']);
    }

    /**
     * @return array{0: Customer, 1: WorkflowInstance}
     */
    private function customerWithWorkflow(?string $externalId = 'process-1'): array
    {
        $customer = Customer::factory()->create();
        $workflow = $customer->workflow()->create([
            'workflow' => WorkflowInstance::CUSTOMER_VERIFICATION,
            'external_id' => $externalId,
            'status' => 'running',
        ]);

        return [$customer, $workflow];
    }

    /**
     * A run whose contact match a compliance officer has approved, while the engine checks the address.
     *
     * @return array<string, mixed>
     */
    private function reviewedContactFlag(): array
    {
        return [
            'status' => 'flagged',
            'failed_step' => 'contact',
            'matches' => ['Phone number +1 555 010 0666 is on the Demo sanctions list'],
            'reviews' => [['step' => 'contact', 'decision' => 'approved', 'notes' => null, 'reviewed_by' => 1, 'reviewed_at' => '2026-10-29T09:00:00+00:00']],
        ];
    }

    private function actingAsEngine(): User
    {
        $engine = User::factory()->create(['name' => 'Workflow engine']);
        Sanctum::actingAs($engine, [CustomerController::READ_ABILITY, CustomerVerificationController::VERIFY_ABILITY]);

        return $engine;
    }

    /**
     * @return array<string, mixed>
     */
    private function verified(): array
    {
        return ['status' => 'verified', 'failed_step' => null, 'matches' => [], 'process_instance_id' => 'process-1'];
    }
}
