<?php

namespace App\Workflows\FinancialScoring;

use App\Models\Customer;
use BcMath\Number;
use RoundingMode;

/**
 * Scores how well a customer can afford their commitments, including the repayment on
 * the loan they're applying for, from 0 (can't) to 100.
 *
 * - Up to 60 points for the debt-to-income ratio: rent or mortgage, car payments, other
 *   loan payments and the new loan's repayment as a share of total income. 30% or less
 *   earns full points.
 * - Up to 40 points for what's left after every outgoing, living expenses and the new
 *   repayment included, as a share of income. 30% or more earns full points.
 *
 * 75 or more is low risk, 50 to 74 medium, and below 50 high. A blank amount counts as zero.
 * Amounts are worked out with BcMath numbers, so they stay exact until they're rounded.
 */
class FinancialScorecard
{
    /**
     * @param  string  $annualInterestRate  The customer app doesn't capture an interest rate, so the
     *                                      new loan's repayment is estimated at this rate ('0.12' is 12%).
     */
    public function __construct(private string $annualInterestRate) {}

    /**
     * @return array{score: int, risk_band: 'low'|'medium'|'high', debt_to_income: float|null, disposable_income: float, loan_repayment: float|null, reasons: list<string>}
     */
    public function score(Customer $customer): array
    {
        $loanRepayment = $this->loanRepayment($customer->loan_amount, $customer->loan_term_months);

        $income = $this->sum($customer->monthly_income, $customer->other_income);
        $debtPayments = $this->sum($customer->rent_or_mortgage, $customer->car_payments, $customer->other_debt_payments, $loanRepayment);
        $disposable = $income - $debtPayments - $this->amount($customer->living_expenses);

        $reasons = [];

        if ($loanRepayment !== null) {
            $reasons[] = "The {$this->money($this->amount($customer->loan_amount))} loan over {$customer->loan_term_months} months adds about "
                ."{$this->money($loanRepayment)} a month at {$this->ratePercent()} a year";
        }

        $result = [
            'score' => 0,
            'risk_band' => 'high',
            'debt_to_income' => null,
            'disposable_income' => (float) (string) $disposable->round(2, RoundingMode::HalfAwayFromZero),
            'loan_repayment' => $loanRepayment === null ? null : (float) (string) $loanRepayment,
        ];

        if ($income <= 0) {
            $reasons[] = 'No income recorded';

            return [...$result, 'reasons' => $reasons];
        }

        $debtToIncome = (float) (string) $this->divide($debtPayments, $income, 4);
        $disposableShare = (float) (string) $this->divide($disposable, $income, 4);

        $score = self::debtToIncomePoints($debtToIncome) + self::disposableIncomePoints($disposableShare);

        $reasons[] = ($loanRepayment === null ? 'Rent, car and loan payments' : 'Rent, car and loan payments, with the new loan,')
            .' take '.$this->percent($debtToIncome).' of income';
        $reasons[] = $disposable < 0
            ? "Outgoings exceed income by {$this->money(0 - $disposable)} a month"
            : $this->percent($disposableShare).' of income is left after all outgoings';

        return [
            ...$result,
            'score' => $score,
            'risk_band' => self::riskBand($score),
            'debt_to_income' => round($debtToIncome, 2),
            'reasons' => $reasons,
        ];
    }

    /**
     * Estimates the monthly repayment on an amortising loan at the configured fixed rate,
     * rounded to cents. Null when the amount or term is missing.
     */
    public function loanRepayment(?string $amount, ?int $termMonths): ?Number
    {
        if ($amount === null || $termMonths === null || $termMonths <= 0 || $this->amount($amount) <= 0) {
            return null;
        }

        $amount = $this->amount($amount);
        $monthlyRate = (new Number($this->annualInterestRate))->div(12, 20);

        if ($monthlyRate == 0) {
            return $this->divide($amount, $termMonths, 2);
        }

        return $this->divide($amount * $monthlyRate, 1 - (1 + $monthlyRate)->pow(-$termMonths, 20), 2);
    }

    public static function debtToIncomePoints(float $debtToIncome): int
    {
        return match (true) {
            $debtToIncome <= 0.30 => 60,
            $debtToIncome <= 0.40 => 45,
            $debtToIncome <= 0.50 => 25,
            default => 0,
        };
    }

    public static function disposableIncomePoints(float $disposableShare): int
    {
        return match (true) {
            $disposableShare >= 0.30 => 40,
            $disposableShare >= 0.20 => 30,
            $disposableShare >= 0.10 => 15,
            $disposableShare > 0 => 5,
            default => 0,
        };
    }

    /**
     * @return 'low'|'medium'|'high'
     */
    public static function riskBand(int $score): string
    {
        return match (true) {
            $score >= 75 => 'low',
            $score >= 50 => 'medium',
            default => 'high',
        };
    }

    private function percent(float $share): string
    {
        return round($share * 100).'%';
    }

    /**
     * An interest rate keeps its decimals, so 12.5% isn't shown as 13%.
     */
    private function ratePercent(): string
    {
        $percent = (string) (new Number($this->annualInterestRate) * 100);

        return (str_contains($percent, '.') ? rtrim(rtrim($percent, '0'), '.') : $percent).'%';
    }

    private function money(Number $amount): string
    {
        return number_format((float) (string) $amount->round(2, RoundingMode::HalfAwayFromZero), 2, '.', '');
    }

    /**
     * Divides, rounding half away from zero to the given number of decimals, as money is usually rounded.
     */
    private function divide(Number $dividend, Number|int $divisor, int $decimals): Number
    {
        return $dividend->div($divisor, $decimals + 10)->round($decimals, RoundingMode::HalfAwayFromZero);
    }

    private function sum(string|Number|null ...$amounts): Number
    {
        return array_reduce($amounts, fn (Number $total, string|Number|null $amount): Number => $total + $this->amount($amount), new Number(0));
    }

    private function amount(string|Number|null $amount): Number
    {
        return $amount instanceof Number ? $amount : new Number($amount ?? '0');
    }
}
