<?php

namespace App\Workflows\FinancialScoring;

use App\Workflows\StateMachineWorkflow;

/**
 * Financial scoring: a new run waits for the queue, then FinancialScorecard scores
 * the customer's financials and the loan they're applying for (see ScoreCustomerFinancials).
 * A run whose financials change before it's scored is superseded by a new run.
 */
class FinancialScoring extends StateMachineWorkflow
{
    public const SCORE = 'score';

    public function title(): string
    {
        return 'Financial scoring';
    }

    public function description(): string
    {
        return 'Scores how well a customer can afford their commitments, including the repayment on the loan they’re applying for. A new run starts whenever their financials or loan change.';
    }

    protected function places(): array
    {
        return [
            'pending' => 'Waiting to be scored',
            'scored' => 'Scored',
            'superseded' => 'Superseded',
        ];
    }

    protected function transitions(): array
    {
        return [
            self::SCORE => ['from' => 'pending', 'to' => 'scored', 'label' => 'Score affordability'],
            self::SUPERSEDE => ['from' => 'pending', 'to' => 'superseded', 'label' => 'Financials changed'],
        ];
    }
}
