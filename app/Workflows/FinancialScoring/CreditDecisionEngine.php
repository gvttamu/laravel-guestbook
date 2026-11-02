<?php

namespace App\Workflows\FinancialScoring;

/**
 * Decides a loan application from the credit bureau's score and the affordability score
 * (see FinancialScorecard). Either one failing rejects it; both passing approves it; and
 * anything in between, or a customer the bureau has no file on, refers it to a credit
 * officer. The thresholds are in config/scoring.php.
 */
class CreditDecisionEngine
{
    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const REFERRED = 'referred';

    public function __construct(private int $approveCreditScore, private int $rejectCreditScore) {}

    /**
     * @param  'low'|'medium'|'high'  $riskBand  The affordability risk band.
     * @return array{decision: 'approved'|'rejected'|'referred', reasons: list<string>}
     */
    public function decide(?int $creditScore, string $riskBand): array
    {
        $rejections = [];

        if ($creditScore !== null && $creditScore < $this->rejectCreditScore) {
            $rejections[] = "Credit score {$creditScore} is below {$this->rejectCreditScore}";
        }

        if ($riskBand === 'high') {
            $rejections[] = 'Affordability is high risk';
        }

        if ($rejections !== []) {
            return ['decision' => self::REJECTED, 'reasons' => $rejections];
        }

        if ($creditScore === null) {
            return ['decision' => self::REFERRED, 'reasons' => ['The credit bureau has no file on the customer']];
        }

        if ($creditScore >= $this->approveCreditScore && $riskBand === 'low') {
            return ['decision' => self::APPROVED, 'reasons' => ["Credit score {$creditScore} is at least {$this->approveCreditScore}", 'Affordability is low risk']];
        }

        $referrals = [];

        if ($creditScore < $this->approveCreditScore) {
            $referrals[] = "Credit score {$creditScore} is below {$this->approveCreditScore}, the score for automatic approval";
        }

        if ($riskBand === 'medium') {
            $referrals[] = 'Affordability is medium risk';
        }

        return ['decision' => self::REFERRED, 'reasons' => $referrals];
    }
}
