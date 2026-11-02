<?php

namespace Tests\Feature;

use App\Workflows\FinancialScoring\CreditBureau;
use Tests\TestCase;

class CreditBureauTest extends TestCase
{
    public function test_gives_the_score_on_file_ignoring_case_and_surrounding_spaces(): void
    {
        $bureau = new CreditBureau;

        $this->assertSame('Demo credit bureau', $bureau->name());
        $this->assertSame(780, $bureau->score(' Hannah.High@Example.com '));
    }

    public function test_has_no_score_for_a_customer_it_has_no_file_on(): void
    {
        $this->assertNull((new CreditBureau)->score('nina.newcomer@example.com'));
    }
}
