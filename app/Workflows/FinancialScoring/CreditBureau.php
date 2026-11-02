<?php

namespace App\Workflows\FinancialScoring;

/**
 * The external credit bureau, standing in for a real one: the credit scores (300 to 850) it
 * holds, by email address, are loaded from credit_bureau.json. A customer it has no file on
 * has no score. Addresses are compared ignoring case and surrounding spaces.
 */
class CreditBureau
{
    private string $name;

    /**
     * @var array<string, int>
     */
    private array $scores;

    public function __construct(string $path = __DIR__.'/credit_bureau.json')
    {
        /** @var array{bureau: string, scores: array<string, int>} $file */
        $file = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->name = $file['bureau'];
        $this->scores = array_combine(array_map(self::normaliseEmail(...), array_keys($file['scores'])), array_values($file['scores']));
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The customer's credit score, or null when the bureau has no file on them.
     */
    public function score(string $email): ?int
    {
        return $this->scores[self::normaliseEmail($email)] ?? null;
    }

    private static function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
