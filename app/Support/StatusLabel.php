<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/** Label for a status code (lang/{en,nl}/status.php), or the code itself made readable. */
final class StatusLabel
{
    public static function for(?string $status): string
    {
        $status = (string) $status;

        return Lang::has('status.'.$status) ? __('status.'.$status) : ucfirst(str_replace('_', ' ', $status));
    }
}
