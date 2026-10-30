<?php

namespace Tests\Feature;

use App\Jobs\ScoreCustomerFinancials;
use App\Models\Customer;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;
use Tests\TestCase;

class ScoreCustomerFinancialsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_scores_a_pending_run_with_an_audit_entry_that_names_no_user(): void
    {
        $customer = $this->customer();
        $run = $this->scoringRun($customer, 'pending');

        ScoreCustomerFinancials::dispatch($run);

        $run->refresh();
        $this->assertSame('scored', $run->status);
        $this->assertSame(85, $run->result['score']);
        $this->assertSame('low', $run->result['risk_band']);

        $audit = $customer->auditLogs()->sole();
        $this->assertSame('scored', $audit->event);
        $this->assertSame($run->result, $audit->new_values);
        $this->assertNull($audit->user_id);
    }

    public function test_a_run_is_scored_once_even_if_the_job_runs_again(): void
    {
        $customer = $this->customer();
        $run = $this->scoringRun($customer, 'pending');

        ScoreCustomerFinancials::dispatch($run);
        $customer->update(['monthly_income' => 1000]);
        ScoreCustomerFinancials::dispatch($run);

        $this->assertSame(85, $run->refresh()->result['score']);
        $this->assertSame(1, $customer->auditLogs()->count());
    }

    public function test_a_superseded_run_is_not_scored(): void
    {
        $customer = $this->customer();
        $run = $this->scoringRun($customer, 'pending');
        $run->supersede();

        ScoreCustomerFinancials::dispatch($run);

        $this->assertSame('superseded', $run->refresh()->status);
        $this->assertNull($run->result);
        $this->assertSame(0, $customer->auditLogs()->count());
    }

    public function test_a_scored_run_cannot_be_superseded(): void
    {
        $run = $this->scoringRun($this->customer(), 'scored');

        $this->expectException(NotEnabledTransitionException::class);

        $run->supersede();
    }

    private function customer(): Customer
    {
        return Customer::factory()->create([
            'monthly_income' => 4200,
            'other_income' => null,
            'rent_or_mortgage' => 1350.50,
            'car_payments' => 300,
            'other_debt_payments' => null,
            'living_expenses' => 900,
            'loan_amount' => null,
            'loan_term_months' => null,
        ]);
    }

    private function scoringRun(Customer $customer, string $status): WorkflowInstance
    {
        return $customer->workflows()->create(['workflow' => WorkflowInstance::FINANCIAL_SCORING, 'status' => $status]);
    }
}
