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

];
