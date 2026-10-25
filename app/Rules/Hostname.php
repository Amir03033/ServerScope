<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A DNS hostname (letters, digits, hyphens, dots; IDN in punycode form)
 * or an IP address.
 */
class Hostname implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Het :attribute is ongeldig.');

            return;
        }

        $value = rtrim(trim($value), '.');

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return;
        }

        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

        if (
            strlen($value) > 253
            || preg_match('/\A'.$label.'(?:\.'.$label.')*\z/i', $value) !== 1
        ) {
            $fail('Het :attribute moet een geldige hostname of een IP-adres zijn.');
        }
    }
}
