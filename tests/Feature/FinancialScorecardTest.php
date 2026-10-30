<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Workflows\FinancialScoring\FinancialScorecard;
use Tests\TestCase;

class FinancialScorecardTest extends TestCase
{
    public function test_low_debt_and_plenty_left_over_is_low_risk(): void
    {
        $score = $this->scorecard()->score($this->customer('4200', null, '1350.50', '300', null, '900'));

        $this->assertSame([
            'score' => 85,
            'risk_band' => 'low',
            'debt_to_income' => 0.39,
            'disposable_income' => 1649.5,
            'loan_repayment' => null,
            'reasons' => [
                'Rent, car and loan payments take 39% of income',
                '39% of income is left after all outgoings',
            ],
        ], $score);
    }

    public function test_the_new_loans_repayment_counts_as_a_debt_payment_and_an_outgoing(): void
    {
        $score = $this->scorecard()->score($this->customer('4200', null, '1350.50', '300', null, '900', loanAmount: '15000', termMonths: 48));

        $this->assertSame([
            'score' => 55,
            'risk_band' => 'medium',
            'debt_to_income' => 0.49,
            'disposable_income' => 1254.49,
            'loan_repayment' => 395.01,
            'reasons' => [
                'The 15000.00 loan over 48 months adds about 395.01 a month at 12% a year',
                'Rent, car and loan payments, with the new loan, take 49% of income',
                '30% of income is left after all outgoings',
            ],
        ], $score);
    }

    public function test_estimates_the_repayment_on_an_amortising_loan(): void
    {
        $this->assertSame('395.01', (string) $this->scorecard()->loanRepayment('15000', 48));
        $this->assertSame('100.00', (string) $this->scorecard('0')->loanRepayment('1200', 12));
    }

    public function test_there_is_no_repayment_without_an_amount_and_term(): void
    {
        $this->assertNull($this->scorecard()->loanRepayment(null, 48));
        $this->assertNull($this->scorecard()->loanRepayment('15000', null));
    }

    public function test_a_fractional_rate_is_shown_with_its_decimals(): void
    {
        $score = $this->scorecard('0.125')->score($this->customer('4000', null, null, null, null, null, loanAmount: '10000', termMonths: 24));

        $this->assertStringEndsWith('at 12.5% a year', $score['reasons'][0]);
    }

    public function test_other_income_counts_towards_income(): void
    {
        $score = $this->scorecard()->score($this->customer('3000', '500', '1000', '300', null, '1700'));

        $this->assertSame(0.37, $score['debt_to_income']);
        $this->assertSame(60, $score['score']);
        $this->assertSame('medium', $score['risk_band']);
    }

    public function test_heavy_debt_with_little_left_over_is_high_risk(): void
    {
        $score = $this->scorecard()->score($this->customer('3000', null, '1200', '400', '200', '1000'));

        $this->assertSame(5, $score['score']);
        $this->assertSame('high', $score['risk_band']);
    }

    public function test_says_how_far_outgoings_exceed_income(): void
    {
        $score = $this->scorecard()->score($this->customer('2000', null, '1500', null, null, '800'));

        $this->assertSame(0, $score['score']);
        $this->assertSame(-300.0, $score['disposable_income']);
        $this->assertContains('Outgoings exceed income by 300.00 a month', $score['reasons']);
    }

    public function test_no_income_scores_zero_without_a_ratio(): void
    {
        $score = $this->scorecard()->score($this->customer(null, null, '900', null, null, null));

        $this->assertSame(0, $score['score']);
        $this->assertSame('high', $score['risk_band']);
        $this->assertNull($score['debt_to_income']);
        $this->assertSame(['No income recorded'], $score['reasons']);
    }

    public function test_band_and_point_boundaries_are_inclusive(): void
    {
        $this->assertSame(60, FinancialScorecard::debtToIncomePoints(0.30));
        $this->assertSame(45, FinancialScorecard::debtToIncomePoints(0.3001));
        $this->assertSame(40, FinancialScorecard::disposableIncomePoints(0.30));
        $this->assertSame(0, FinancialScorecard::disposableIncomePoints(0));
        $this->assertSame('low', FinancialScorecard::riskBand(75));
        $this->assertSame('medium', FinancialScorecard::riskBand(74));
        $this->assertSame('medium', FinancialScorecard::riskBand(50));
        $this->assertSame('high', FinancialScorecard::riskBand(49));
    }

    public function test_the_rate_comes_from_configuration(): void
    {
        config(['scoring.annual_interest_rate' => '0']);

        $this->assertSame('100.00', (string) app(FinancialScorecard::class)->loanRepayment('1200', 12));
    }

    private function scorecard(string $annualInterestRate = '0.12'): FinancialScorecard
    {
        return new FinancialScorecard($annualInterestRate);
    }

    private function customer(?string $income, ?string $otherIncome, ?string $rent, ?string $car, ?string $otherDebt, ?string $living, ?string $loanAmount = null, ?int $termMonths = null): Customer
    {
        return Customer::factory()->make([
            'monthly_income' => $income,
            'other_income' => $otherIncome,
            'rent_or_mortgage' => $rent,
            'car_payments' => $car,
            'other_debt_payments' => $otherDebt,
            'living_expenses' => $living,
            'loan_amount' => $loanAmount,
            'loan_term_months' => $termMonths,
        ]);
    }
}
