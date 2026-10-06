<?php

namespace App\Services\Analysis;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Any provider with an OpenAI-compatible chat completions endpoint
 * (Google Gemini, Mistral, Groq, Ollama, ...). Support for JSON schema
 * output differs per provider and model.
 */
class OpenAiCompatibleLanguageModel implements LanguageModel
{
    public function __construct(
        private ?string $apiKey,
        private string $model,
        private string $baseUrl,
        private string $effort,
        private int $maxTokens,
        private int $timeout,
    ) {}

    public function complete(string $system, string $input, ?array $schema = null): LlmResult
    {
        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $input],
            ],
            'max_tokens' => $this->maxTokens,
        ];

        if ($schema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'moderation', 'schema' => $schema, 'strict' => true],
            ];
        }

        if ($this->effort !== 'default') {
            $payload['reasoning_effort'] = $this->effort;
        }

        try {
            $response = Http::when(filled($this->apiKey), fn ($http) => $http->withToken($this->apiKey))
                ->baseUrl(rtrim($this->baseUrl, '/'))
                ->acceptJson()
                ->timeout($this->timeout)
                ->retry(2, 2000, fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503], true)), throw: false)
                ->post('/chat/completions', $payload)
                ->throw()
                ->json();
        } catch (RequestException $e) {
            $error = $e->response->json('error.message') ?? $e->response->json('error') ?? $e->getMessage();
            throw new LlmException('API error: '.(is_string($error) ? $error : json_encode($error)), previous: $e);
        } catch (ConnectionException $e) {
            throw new LlmException('Connection error: '.$e->getMessage(), previous: $e);
        }

        $choice = $response['choices'][0] ?? null;

        if (($choice['finish_reason'] ?? null) === 'length') {
            throw new LlmException('Model output hit max_tokens');
        }

        if (filled($choice['message']['refusal'] ?? null)) {
            throw new LlmException('Model refused the request: '.$choice['message']['refusal']);
        }

        return new LlmResult(
            text: $this->stripCodeFence(trim((string) ($choice['message']['content'] ?? ''))),
            model: $response['model'] ?? $this->model,
            inputTokens: (int) ($response['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($response['usage']['completion_tokens'] ?? 0),
        );
    }

    public function name(): string
    {
        return $this->model;
    }

    /**
     * Some providers wrap JSON in ```json fences even in JSON mode.
     */
    private function stripCodeFence(string $text): string
    {
        return preg_replace('/^```(?:json)?\s*(.*?)\s*```$/s', '$1', $text);
    }
}
