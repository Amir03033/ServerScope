<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Label for a stored code from lang/{en,nl}/labels.php, falling back to the
 * Dutch constant in the code (or the readable code). LabelsTest keeps the
 * Dutch translations equal to those constants.
 */
final class Label
{
    /** @param  array<string, string>  $fallback */
    public static function of(string $group, ?string $code, array $fallback = []): string
    {
        $code = (string) $code;
        $key = "labels.{$group}.{$code}";

        if ($code !== '' && Lang::has($key)) {
            return __($key);
        }

        return $fallback[$code] ?? ucfirst(str_replace('_', ' ', $code));
    }
}
