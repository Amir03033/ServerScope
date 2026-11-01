<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * A failed AI request. $kind is safe to store and show: unavailable,
 * timeout, rate_limited, provider_error, invalid_response. The message
 * never contains prompts, responses or credentials.
 */
class AiException extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $kind);
    }
}
