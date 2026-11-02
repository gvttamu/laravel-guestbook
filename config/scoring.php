<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Annual Interest Rate
    |--------------------------------------------------------------------------
    |
    | The customer form doesn't capture an interest rate, so financial scoring
    | estimates the repayment on the loan applied for at this annual rate,
    | as a decimal: 0.12 is 12%. It's kept as a string so it stays exact.
    |
    */

    'annual_interest_rate' => (string) env('SCORING_ANNUAL_INTEREST_RATE', '0.12'),

    /*
    |--------------------------------------------------------------------------
    | Credit Score Thresholds
    |--------------------------------------------------------------------------
    |
    | The decision engine weighs the credit bureau's score (300 to 850) with the
    | affordability score. An application is approved automatically when its
    | credit score is at least `approve_credit_score` and its affordability is
    | low risk, and rejected automatically when its credit score is below
    | `reject_credit_score` or its affordability is high risk. Anything else,
    | including a customer the bureau has no file on, goes to a credit officer.
    |
    */

    'approve_credit_score' => (int) env('SCORING_APPROVE_CREDIT_SCORE', 700),

    'reject_credit_score' => (int) env('SCORING_REJECT_CREDIT_SCORE', 580),

];
