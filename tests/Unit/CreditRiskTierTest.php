<?php

namespace Tests\Unit;

use App\Workflows\FinancialScoring\CreditRiskTier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CreditRiskTierTest extends TestCase
{
    /**
     * @return array<string, array{int, ?int, ?string, ?string, string, int, list<string>}>
     */
    public static function applications(): array
    {
        return [
            'nothing risky' => [75, 700, '10000.00', 'car', 'low', 0, []],
            'one point' => [74, 700, '10000.00', 'home_improvement', 'low', 1, ['Affordability score 74: +1']],
            'two points' => [100, null, '10000.00', 'car', 'medium', 2, ['No credit file: +2']],
            'three points' => [100, 579, '10000.00', 'car', 'medium', 3, ['Credit score 579: +3']],
            'four points' => [80, 650, '40000.00', 'debt_consolidation', 'high', 4, ['Credit score 650: +1', 'Loan of 40,000.00: +2', 'Loan for debt consolidation: +1']],
            'five points' => [49, 640, '10000.01', 'education', 'high', 5, ['Affordability score 49: +2', 'Credit score 640: +1', 'Loan of 10,000.01: +1', 'Loan for education: +1']],
            'six points' => [100, 580, '50000.01', 'education', 'highest', 6, ['Credit score 580: +2', 'Loan of 50,000.01: +3', 'Loan for education: +1']],
            'the riskiest' => [10, 400, '90000.00', 'business', 'highest', 10, ['Affordability score 10: +2', 'Credit score 400: +3', 'Loan of 90,000.00: +3', 'Loan for business: +2']],
            'an unknown purpose counts as other' => [100, 700, '25000.00', 'holiday', 'medium', 3, ['Loan of 25,000.00: +1', 'Loan for holiday: +2']],
        ];
    }

    public function test_an_officer_may_decide_their_tier_and_every_less_risky_one(): void
    {
        $this->assertSame(['low'], CreditRiskTier::upTo('low'));
        $this->assertSame(['low', 'medium', 'high'], CreditRiskTier::upTo('high'));
        $this->assertSame(CreditRiskTier::TIERS, CreditRiskTier::upTo('highest'));
        $this->assertSame([], CreditRiskTier::upTo(null));
    }

    /**
     * @param  list<string>  $reasons
     */
    #[DataProvider('applications')]
    public function test_sorts_an_application_into_a_tier_by_its_points(int $affordabilityScore, ?int $creditScore, ?string $loanAmount, ?string $loanPurpose, string $tier, int $points, array $reasons): void
    {
        $this->assertSame(
            ['tier' => $tier, 'points' => $points, 'reasons' => $reasons],
            (new CreditRiskTier)->assess($affordabilityScore, $creditScore, $loanAmount, $loanPurpose),
        );
    }
}
