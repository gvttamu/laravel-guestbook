<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * An international phone number: a "+", the country code, then the number, with digit groups
 * optionally separated by single spaces or hyphens and an area code optionally in parentheses,
 * e.g. "+1 555 010 0100", "+1 (555) 010-0100" or "+447946000000". E.164 allows 8 to 15 digits.
 */
class PhoneNumber implements ValidationRule
{
    private const PATTERN = '/^\+[1-9]\d{0,2}(?:[ -]?(?:\(\d+\)|\d+))+$/';

    private const MIN_DIGITS = 8;

    private const MAX_DIGITS = 15;

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digitCount = is_string($value) ? strlen(preg_replace('/\D/', '', $value)) : 0;

        if (! is_string($value) || ! preg_match(self::PATTERN, $value) || $digitCount < self::MIN_DIGITS || $digitCount > self::MAX_DIGITS) {
            $fail('The :attribute must be an international number starting with + and the country code, e.g. +1 555 010 0100.');
        }
    }
}
