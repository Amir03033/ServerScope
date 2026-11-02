<?php

namespace App\Services\Incidents\Evidence\Collectors;

/** Check results reduced to their flips between success and failure. */
final class Transitions
{
    /** Calls $emit for each flip between success and failure per group (a failure at the start counts too). */
    public static function each(iterable $rows, callable $group, callable $emit): void
    {
        $last = [];

        foreach ($rows as $row) {
            $key = $group($row);
            $success = (bool) $row->success;

            if (($last[$key] ?? true) !== $success) {
                $emit($row, ! $success);
            }

            $last[$key] = $success;
        }
    }
}
