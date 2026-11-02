<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Chat Completions with Structured Outputs (json_schema, strict).
 * Key, model, base URL, time-out and output limit come from config
 * serverscope.ai. One attempt per call: retries are the caller's decision,
 * so a queue retry never doubles the cost silently.
 */
class OpenAiProvider implements AiProvider
{
    public function __construct(private array $config) {}

    public function available(): bool
    {
        return trim((string) ($this->config['api_key'] ?? '')) !== '' && trim((string) ($this->config['model'] ?? '')) !== '';
    }

    public function structured(string $instructions, string $input, string $schemaName, array $schema): AiResponse
    {
        if (! $this->available()) {
            throw new AiException('unavailable');
        }

        $payload = [
            'model' => $this->config['model'],
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => $input],
            ],
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => $schemaName, 'strict' => true, 'schema' => $schema]],
            // Reasoning models spend part of this on hidden reasoning.
            'max_completion_tokens' => max(500, (int) ($this->config['max_output_tokens'] ?? 8000)),
        ];

        if (in_array($this->config['reasoning_effort'] ?? null, ['minimal', 'low', 'medium', 'high'], true)) {
            $payload['reasoning_effort'] = $this->config['reasoning_effort'];
        }

        try {
            $response = Http::withToken((string) $this->config['api_key'])
                ->acceptJson()
                ->timeout(max(5, (int) ($this->config['timeout_seconds'] ?? 45)))
                ->post(($this->config['base_url'] ?? 'https://api.openai.com/v1').'/chat/completions', $payload);
        } catch (ConnectionException $exception) {
            throw new AiException(str_contains(strtolower($exception->getMessage()), 'timed out') ? 'timeout' : 'provider_error', 'Connection to the AI provider failed.');
        }

        if ($response->status() === 429) {
            throw new AiException('rate_limited', 'The AI provider rate-limited the request.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new AiException('unavailable', 'The AI provider rejected the credentials.');
        }

        if (! $response->successful()) {
            throw new AiException('provider_error', "The AI provider answered HTTP {$response->status()}.");
        }

        $choice = $response->json('choices.0');
        $content = is_array($choice) ? ($choice['message']['content'] ?? null) : null;

        $finish = is_array($choice) ? (string) ($choice['finish_reason'] ?? 'stop') : 'none';

        if ($finish === 'length') {
            throw new AiException('invalid_response', 'The AI answer was cut off at the output token limit (finish_reason: length); raise SERVERSCOPE_AI_MAX_OUTPUT_TOKENS or lower SERVERSCOPE_AI_REASONING_EFFORT.');
        }

        if (! is_string($content) || ($choice['message']['refusal'] ?? null) || $finish !== 'stop') {
            throw new AiException('invalid_response', "The AI provider returned no complete answer (finish_reason: {$finish}).");
        }

        $data = json_decode($content, true);

        if (! is_array($data)) {
            throw new AiException('invalid_response', 'The AI answer is not valid JSON.');
        }

        return new AiResponse(
            $data,
            (string) ($response->json('model') ?? $this->config['model']),
            is_int($response->json('usage.prompt_tokens')) ? $response->json('usage.prompt_tokens') : null,
            is_int($response->json('usage.completion_tokens')) ? $response->json('usage.completion_tokens') : null,
        );
    }
}
