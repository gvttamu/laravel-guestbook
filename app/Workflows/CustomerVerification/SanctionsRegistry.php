<?php

namespace App\Workflows\CustomerVerification;

/**
 * The "database" of sanctioned contact details and addresses, loaded from sanctions.json.
 * Values are normalised before comparing, so formatting differences such as spaces in
 * phone numbers, letter case, punctuation, or "Lane" versus "Ln" don't hide a match.
 */
class SanctionsRegistry
{
    private const STREET_ABBREVIATIONS = [
        'street' => 'st', 'road' => 'rd', 'lane' => 'ln', 'avenue' => 'ave', 'drive' => 'dr', 'square' => 'sq',
    ];

    private string $listName;

    /**
     * @var array<string, true>
     */
    private array $phones;

    /**
     * @var array<string, true>
     */
    private array $emails;

    /**
     * @var list<string>
     */
    private array $addresses;

    public function __construct(string $path = __DIR__.'/sanctions.json')
    {
        /** @var array{list: string, phones: list<string>, emails: list<string>, addresses: list<array{line1: string, postal_code: string, country: string}>} $list */
        $list = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->listName = $list['list'];
        $this->phones = array_fill_keys(array_map(self::normalisePhone(...), $list['phones']), true);
        $this->emails = array_fill_keys(array_map(self::normaliseEmail(...), $list['emails']), true);
        $this->addresses = array_map(fn (array $address): string => self::normaliseAddress($address['line1'], $address['postal_code'], $address['country']), $list['addresses']);
    }

    /**
     * A description of each match; empty when the contact details are clear.
     *
     * @return list<string>
     */
    public function screenContact(?string $phone, ?string $email): array
    {
        $matches = [];

        if ($phone !== null && isset($this->phones[self::normalisePhone($phone)])) {
            $matches[] = "Phone number {$phone} is on the {$this->listName}";
        }

        if ($email !== null && isset($this->emails[self::normaliseEmail($email)])) {
            $matches[] = "Email address {$email} is on the {$this->listName}";
        }

        return $matches;
    }

    /**
     * A description of each match; empty when the address is clear.
     *
     * @return list<string>
     */
    public function screenAddress(?string $line1, ?string $postalCode, ?string $country): array
    {
        $address = self::normaliseAddress($line1, $postalCode, $country);

        return array_values(array_map(
            fn (): string => "Address {$line1}, {$postalCode}, {$country} is on the {$this->listName}",
            array_filter($this->addresses, fn (string $sanctioned): bool => $sanctioned === $address),
        ));
    }

    private static function normalisePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }

    private static function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function normaliseAddress(?string $line1, ?string $postalCode, ?string $country): string
    {
        return implode('|', [self::normaliseText($line1), str_replace(' ', '', self::normaliseText($postalCode)), self::normaliseText($country)]);
    }

    private static function normaliseText(?string $text): string
    {
        $words = explode(' ', trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($text ?? ''))));

        return implode(' ', array_map(fn (string $word): string => self::STREET_ABBREVIATIONS[$word] ?? $word, $words));
    }
}
