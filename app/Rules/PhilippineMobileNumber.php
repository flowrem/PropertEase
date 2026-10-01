<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A Philippine mobile number written as 09XXXXXXXXX or +639XXXXXXXXX.
 * Spaces, dashes, dots and brackets are ignored, so "0917 123 4567" passes.
 */
class PhilippineMobileNumber implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || self::normalize($value) === null) {
            $fail(__('Enter an 11-digit mobile number like 09171234567.'));
        }
    }

    /**
     * The number in +639XXXXXXXXX form, or null when it isn't a PH mobile number.
     */
    public static function normalize(string $number): ?string
    {
        $digits = preg_replace('/[\s\-.()]/', '', $number);

        if (preg_match('/^(?:09|\+639)(\d{9})$/', (string) $digits, $matches) !== 1) {
            return null;
        }

        return '+639'.$matches[1];
    }

    /**
     * A stored +639XXXXXXXXX number the way people here write it: 0917 123 4567.
     */
    public static function forDisplay(string $normalized): string
    {
        $local = self::forInput($normalized);

        return substr($local, 0, 4).' '.substr($local, 4, 3).' '.substr($local, 7);
    }

    /**
     * A stored +639XXXXXXXXX number as the 11 digits a phone input accepts: 09171234567.
     * No number gives an empty string, so it can prefill an input as-is.
     */
    public static function forInput(?string $normalized): string
    {
        return $normalized ? '0'.substr($normalized, 3) : '';
    }
}
