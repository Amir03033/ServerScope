<?php

namespace App\Monitoring\Net;

use RuntimeException;

class BlockedTargetException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'blocked',
    ) {
        parent::__construct($message);
    }
}
