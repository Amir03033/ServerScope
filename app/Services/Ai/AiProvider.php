<?php

namespace App\Services\Ai;

/**
 * A language-model provider returning JSON that matches a schema.
 * Implementations make no decisions about content; callers validate the
 * result. Bound in the container (AppServiceProvider) from config
 * serverscope.ai, so features never name a provider or model themselves.
 */
interface AiProvider
{
    public function available(): bool;

    /**
     * @param  array<string, mixed>  $schema  JSON schema of the expected object
     *
     * @throws AiException
     */
    public function structured(string $instructions, string $input, string $schemaName, array $schema): AiResponse;
}
