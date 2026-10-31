<?php

namespace App\Support;

use RuntimeException;

/** A plan limit or feature blocks the action. The message is user-facing. */
class PlanLimitException extends RuntimeException
{
    public static function limit(string $key, int $limit): self
    {
        return new self(__('plans.limit.'.$key, ['limit' => $limit]));
    }

    public static function feature(string $feature): self
    {
        return new self(__('plans.feature.'.$feature));
    }
}
