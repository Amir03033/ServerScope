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
        // The whole group, so codes with a dot (incident.opened) work too.
        $labels = Lang::has("labels.{$group}") ? __("labels.{$group}") : [];

        if ($code !== '' && is_array($labels) && is_string($labels[$code] ?? null)) {
            return $labels[$code];
        }

        return $fallback[$code] ?? ucfirst(str_replace('_', ' ', $code));
    }
}
