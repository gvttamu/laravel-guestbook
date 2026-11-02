<?php

namespace Tests\Feature;

use App\Jobs\ScoreCustomerFinancials;
use App\Models\Customer;
use App\Models\WorkflowInstance;
use App\Workflows\FinancialScoring\CreditBureau;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;
use Tests\TestCase;

class ScoreCustomerFinancialsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $path = storage_path('framework/testing/credit_bureau.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(['bureau' => 'Test bureau', 'scores' => ['good@example.com' => 760, 'fair@example.com' => 640, 'poor@example.com' => 520]]));
        $this->app->instance(CreditBureau::class, new CreditBureau($path));
    }

    /**
     * @return array<string, array{string, string, string, string, list<string>}>
     */
    public static function decisions(): array
    {
        return [
            'good credit' => ['good@example.com', 'approved', 'auto_approve', 'loan_approved', ['Credit score 760 is at least 700', 'Affordability is low risk']],
            'fair credit' => ['fair@example.com', 'credit_review', 'refer', 'loan_referred', ['Credit score 640 is below 700, the score for automatic approval']],
            'poor credit' => ['poor@example.com', 'rejected', 'auto_reject', 'loan_rejected', ['Credit score 520 is below 580']],
            'no credit file' => ['unknown@example.com', 'credit_review', 'refer', 'loan_referred', ['The credit bureau has no file on the customer']],
        ];
    }

    /**
     * @param  list<string>  $reasons
     */
    #[DataProvider('decisions')]
    public function test_scores_a_pending_run_and_lets_the_engine_decide_it_with_audit_entries_that_name_no_user(string $email, string $status, string $transition, string $event, array $reasons): void
    {
        $customer = $this->customer(['email' => $email]);
        $run = $this->scoringRun($customer, 'pending');

        ScoreCustomerFinancials::dispatch($run);

        $run->refresh();
        $this->assertSame($status, $run->status);
        $this->assertSame(85, $run->result['score']);
        $this->assertSame('low', $run->result['risk_band']);
        $this->assertSame('Test bureau', $run->result['credit_bureau']);
        $this->assertSame($reasons, $run->result['engine_reasons']);
        $this->assertContains($run->result['credit_risk_tier'], ['low', 'medium', 'high']);
        $this->assertSame(['score', $transition], $run->transitions->pluck('transition')->all());

        $audits = $customer->auditLogs()->orderBy('id')->get();
        $this->assertSame(['scored', $event], $audits->pluck('event')->all());
        $this->assertSame([null, null], $audits->pluck('user_id')->all());
        $this->assertSame($reasons, $audits->last()->new_values['loan_decision_reasons']);
    }

    public function test_a_high_risk_application_is_rejected_whatever_the_credit_score(): void
    {
        $run = $this->scoringRun($this->customer(['email' => 'good@example.com', 'monthly_income' => 2000]), 'pending');

        ScoreCustomerFinancials::dispatch($run);

        $run->refresh();
        $this->assertSame('rejected', $run->status);
        $this->assertSame(['Affordability is high risk'], $run->result['engine_reasons']);
    }

    public function test_records_the_credit_risk_tier_whose_officers_decide_a_referred_application(): void
    {
        // No credit file (+2), a 40,000 loan (+2) for a business (+2): 6 points, the highest tier.
        $run = $this->scoringRun($this->customer(['loan_amount' => 40000, 'loan_term_months' => 60, 'loan_purpose' => 'business', 'monthly_income' => 9000]), 'pending');

        ScoreCustomerFinancials::dispatch($run);

        $run->refresh();
        $this->assertSame('credit_review', $run->status);
        $this->assertSame('highest', $run->result['credit_risk_tier']);
        $this->assertSame(6, $run->result['credit_risk_points']);
        $this->assertSame(['No credit file: +2', 'Loan of 40,000.00: +2', 'Loan for business: +2'], $run->result['credit_risk_reasons']);
    }

    public function test_a_referred_application_records_when_it_was_referred(): void
    {
        $this->travelTo('2026-11-02 09:00:00');
        $run = $this->scoringRun($this->customer(['email' => 'fair@example.com']), 'pending');

        ScoreCustomerFinancials::dispatch($run);

        $this->assertSame('2026-11-02T09:00:00+00:00', $run->refresh()->result['referred_at']);
    }

    public function test_a_run_is_scored_once_even_if_the_job_runs_again(): void
    {
        $customer = $this->customer(['email' => 'good@example.com']);
        $run = $this->scoringRun($customer, 'pending');

        ScoreCustomerFinancials::dispatch($run);
        $customer->update(['monthly_income' => 1000]);
        ScoreCustomerFinancials::dispatch($run);

        $this->assertSame(85, $run->refresh()->result['score']);
        $this->assertSame(2, $customer->auditLogs()->count());
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

    public function test_a_referred_run_can_be_superseded_but_a_decided_one_cannot(): void
    {
        $referred = $this->scoringRun($this->customer(), 'credit_review');
        $referred->supersede();
        $this->assertSame('superseded', $referred->refresh()->status);

        $this->expectException(NotEnabledTransitionException::class);

        $this->scoringRun($this->customer(), 'approved')->supersede();
    }

    /**
     * A customer whose affordability scores 85, low risk, with no loan.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): Customer
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
            ...$attributes,
        ]);
    }

    private function scoringRun(Customer $customer, string $status): WorkflowInstance
    {
        return $customer->workflows()->create(['workflow' => WorkflowInstance::FINANCIAL_SCORING, 'status' => $status]);
    }
}
