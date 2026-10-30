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

class CustomerScoringApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_token_that_can_only_read_customers_cannot_report_scores(): void
    {
        [$customer] = $this->customerWithScoringRun();
        Sanctum::actingAs(User::factory()->create(), [CustomerController::READ_ABILITY]);

        $this->putJson("/api/v1/customers/{$customer->id}/scoring", $this->score())->assertForbidden();
    }

    public function test_records_the_score_with_an_audit_entry_naming_the_engine(): void
    {
        [$customer, $run] = $this->customerWithScoringRun(externalId: null);
        $engine = $this->actingAsEngine();

        $this->putJson("/api/v1/customers/{$customer->id}/scoring", [...$this->score(), 'run_id' => $run->id])
            ->assertOk()
            ->assertExactJson(['status' => 'scored']);

        $run->refresh();
        $this->assertSame('scored', $run->status);
        $this->assertSame('process-1', $run->external_id);
        $this->assertSame([
            'score' => 70,
            'risk_band' => 'medium',
            'debt_to_income' => 0.38,
            'disposable_income' => 850.5,
            'loan_repayment' => 395.01,
            'reasons' => ['Debt repayments take 38% of income'],
        ], $run->result);

        $audit = $customer->auditLogs()->sole();
        $this->assertSame('scored', $audit->event);
        $this->assertSame(70, $audit->new_values['score']);
        $this->assertTrue($audit->user->is($engine));
    }

    public function test_a_repeated_report_is_recorded_once(): void
    {
        [$customer] = $this->customerWithScoringRun();
        $this->actingAsEngine();

        $this->putJson("/api/v1/customers/{$customer->id}/scoring", $this->score())->assertOk();
        $this->putJson("/api/v1/customers/{$customer->id}/scoring", [...$this->score(), 'score' => 10])->assertOk();

        $this->assertSame(70, $customer->scoring->result['score']);
        $this->assertSame(1, $customer->auditLogs()->count());
    }

    public function test_a_late_score_from_a_superseded_run_is_dropped(): void
    {
        [$customer, $run] = $this->customerWithScoringRun();
        $run->update(['status' => 'superseded']);
        $this->actingAsEngine();

        $this->putJson("/api/v1/customers/{$customer->id}/scoring", $this->score())
            ->assertOk()
            ->assertExactJson(['status' => 'superseded']);

        $this->assertNull($run->refresh()->result);
        $this->assertSame(0, $customer->auditLogs()->count());
    }

    public function test_a_score_for_a_verification_process_is_not_found(): void
    {
        $customer = Customer::factory()->create();
        $verification = $customer->workflows()->create(['workflow' => WorkflowInstance::CUSTOMER_VERIFICATION, 'external_id' => 'process-1', 'status' => 'running']);
        $this->actingAsEngine();

        $this->putJson("/api/v1/customers/{$customer->id}/scoring", $this->score())->assertNotFound();

        $this->assertSame('running', $verification->refresh()->status);
    }

    public function test_rejects_an_unknown_risk_band_and_an_out_of_range_score(): void
    {
        [$customer] = $this->customerWithScoringRun();
        $this->actingAsEngine();

        $this->putJson("/api/v1/customers/{$customer->id}/scoring", [...$this->score(), 'risk_band' => 'extreme', 'score' => 101])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['risk_band', 'score' => 'The score field must be between 0 and 100.']);
    }

    /**
     * @return array{0: Customer, 1: WorkflowInstance}
     */
    private function customerWithScoringRun(?string $externalId = 'process-1'): array
    {
        $customer = Customer::factory()->create();
        $run = $customer->workflows()->create([
            'workflow' => WorkflowInstance::FINANCIAL_SCORING,
            'external_id' => $externalId,
            'status' => 'running',
        ]);

        return [$customer, $run];
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
    private function score(): array
    {
        return [
            'score' => 70,
            'risk_band' => 'medium',
            'debt_to_income' => 0.38,
            'disposable_income' => 850.5,
            'loan_repayment' => 395.01,
            'reasons' => ['Debt repayments take 38% of income'],
            'process_instance_id' => 'process-1',
        ];
    }
}
