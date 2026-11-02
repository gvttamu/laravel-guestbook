<?php

namespace App\Workflows\FinancialScoring;

/**
 * Sorts a loan application into the credit risk tier whose credit officers decide it, from
 * points for each of four factors, each worth more the riskier it is:
 *
 * - Affordability score: 75 or more 0, 50 to 74 1, below 50 2.
 * - Credit score: 700 or more 0, 640 to 699 1, 580 to 639 2, below 580 3, and no credit file 2.
 * - Loan amount: up to 10,000 0, up to 25,000 1, up to 50,000 2, more 3.
 * - Loan purpose: home improvement or a car 0, education or debt consolidation 1, a business or other 2.
 *
 * 0 or 1 point is low risk, 2 or 3 medium, 4 or 5 high, and 6 or more highest.
 */
class CreditRiskTier
{
    /**
     * The tiers, from least to most risky.
     *
     * @var list<string>
     */
    public const TIERS = ['low', 'medium', 'high', 'highest'];

    /**
     * The tiers an officer of the given tier may decide: theirs and every less risky one.
     *
     * @return list<string>
     */
    public static function upTo(?string $tier): array
    {
        $index = array_search($tier, self::TIERS, true);

        return $index === false ? [] : array_slice(self::TIERS, 0, $index + 1);
    }

    /**
     * The tiers whose officers may decide an application of the given tier: its own and every riskier one.
     *
     * @return list<string>
     */
    public static function atOrAbove(string $tier): array
    {
        $index = array_search($tier, self::TIERS, true);

        return $index === false ? [] : array_slice(self::TIERS, $index);
    }

    /**
     * Points for each loan purpose; one not listed counts as 'other'.
     *
     * @var array<string, int>
     */
    private const PURPOSE_POINTS = [
        'home_improvement' => 0,
        'car' => 0,
        'education' => 1,
        'debt_consolidation' => 1,
        'business' => 2,
        'other' => 2,
    ];

    /**
     * @return array{tier: 'low'|'medium'|'high'|'highest', points: int, reasons: list<string>}
     */
    public function assess(int $affordabilityScore, ?int $creditScore, ?string $loanAmount, ?string $loanPurpose): array
    {
        $amount = (float) $loanAmount;

        // Each factor, described with the application's value, and its points.
        $factors = [
            "Affordability score {$affordabilityScore}" => match (true) {
                $affordabilityScore >= 75 => 0,
                $affordabilityScore >= 50 => 1,
                default => 2,
            },
            $creditScore === null ? 'No credit file' : "Credit score {$creditScore}" => match (true) {
                $creditScore === null => 2,
                $creditScore >= 700 => 0,
                $creditScore >= 640 => 1,
                $creditScore >= 580 => 2,
                default => 3,
            },
            'Loan of '.number_format($amount, 2, '.', ',') => match (true) {
                $amount <= 10000 => 0,
                $amount <= 25000 => 1,
                $amount <= 50000 => 2,
                default => 3,
            },
            'Loan for '.str_replace('_', ' ', $loanPurpose ?? 'other') => self::PURPOSE_POINTS[$loanPurpose] ?? self::PURPOSE_POINTS['other'],
        ];

        $points = array_sum($factors);
        $reasons = [];

        foreach ($factors as $factor => $factorPoints) {
            if ($factorPoints > 0) {
                $reasons[] = "{$factor}: +{$factorPoints}";
            }
        }

        return [
            'tier' => match (true) {
                $points <= 1 => 'low',
                $points <= 3 => 'medium',
                $points <= 5 => 'high',
                default => 'highest',
            },
            'points' => $points,
            'reasons' => $reasons,
        ];
    }
}
