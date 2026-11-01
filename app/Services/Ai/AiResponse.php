<?php

namespace App\Services\Ai;

/** A structured model answer and its usage. */
final class AiResponse
{
    public function __construct(
        public readonly array $data,
        public readonly string $model,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
    ) {}
}
