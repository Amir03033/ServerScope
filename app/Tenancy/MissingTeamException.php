<?php

namespace App\Tenancy;

use RuntimeException;

/**
 * A team-owned record was about to be written without a team that could
 * be determined. Outside tests this is always a bug: the record would
 * otherwise end up in a team nobody chose.
 */
class MissingTeamException extends RuntimeException
{
    public static function for(string $what): self
    {
        return new self("Cannot determine the team for {$what}: no parent, subject or tenant.");
    }
}
