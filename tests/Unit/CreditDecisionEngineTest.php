<?php

namespace Tests\Unit;

use App\Workflows\FinancialScoring\CreditDecisionEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CreditDecisionEngineTest extends TestCase
{
    /**
     * @return array<string, array{?int, string, string, list<string>}>
     */
    public static function applications(): array
    {
        return [
            'good credit, low risk' => [700, 'low', 'approved', ['Credit score 700 is at least 700', 'Affordability is low risk']],
            'just under approval, low risk' => [699, 'low', 'referred', ['Credit score 699 is below 700, the score for automatic approval']],
            'good credit, medium risk' => [780, 'medium', 'referred', ['Affordability is medium risk']],
            'fair credit, medium risk' => [600, 'medium', 'referred', ['Credit score 600 is below 700, the score for automatic approval', 'Affordability is medium risk']],
            'lowest score that isn’t rejected' => [580, 'low', 'referred', ['Credit score 580 is below 700, the score for automatic approval']],
            'poor credit' => [579, 'low', 'rejected', ['Credit score 579 is below 580']],
            'good credit, high risk' => [800, 'high', 'rejected', ['Affordability is high risk']],
            'poor credit, high risk' => [500, 'high', 'rejected', ['Credit score 500 is below 580', 'Affordability is high risk']],
            'no credit file, low risk' => [null, 'low', 'referred', ['The credit bureau has no file on the customer']],
            'no credit file, high risk' => [null, 'high', 'rejected', ['Affordability is high risk']],
        ];
    }

    /**
     * @param  'low'|'medium'|'high'  $riskBand
     * @param  list<string>  $reasons
     */
    #[DataProvider('applications')]
    public function test_decides_from_the_credit_score_and_affordability(?int $creditScore, string $riskBand, string $decision, array $reasons): void
    {
        $engine = new CreditDecisionEngine(approveCreditScore: 700, rejectCreditScore: 580);

        $this->assertSame(['decision' => $decision, 'reasons' => $reasons], $engine->decide($creditScore, $riskBand));
    }
}
