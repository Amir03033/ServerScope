<?php

namespace App\Monitoring\Dns;

use RuntimeException;

class DnsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'error',
    ) {
        parent::__construct($message);
    }
}
