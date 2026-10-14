<?php

namespace App\Services\Analysis;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Responses API with structured outputs.
 */
class OpenAiLanguageModel implements LanguageModel
{
    public function __construct(
        private string $apiKey,
        private string $model,
        private string $effort,
        private int $maxTokens,
        private int $timeout,
        private string $baseUrl = 'https://api.openai.com/v1',
    ) {}

    public function complete(string $system, string $input, ?array $schema = null): LlmResult
    {
        $payload = [
            'model' => $this->model,
            'instructions' => $system,
            'input' => $input,
            'max_output_tokens' => $this->maxTokens,
            'store' => false,
        ];

        if ($schema !== null) {
            $payload['text'] = ['format' => [
                'type' => 'json_schema',
                'name' => 'moderation',
                'schema' => $schema,
                'strict' => true,
            ]];
        }

        // Only reasoning models accept this; use "default" for others.
        if ($this->effort !== 'default') {
            $payload['reasoning'] = ['effort' => $this->effort];
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->baseUrl(rtrim($this->baseUrl, '/'))
                ->acceptJson()
                ->timeout($this->timeout)
                ->retry(2, 2000, fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503], true)), throw: false)
                ->post('/responses', $payload)
                ->throw()
                ->json();
        } catch (RequestException $e) {
            throw new LlmException('OpenAI API error: '.($e->response->json('error.message') ?? $e->getMessage()), previous: $e);
        } catch (ConnectionException $e) {
            throw new LlmException('OpenAI connection error: '.$e->getMessage(), previous: $e);
        }

        if (($response['status'] ?? null) === 'incomplete') {
            $reason = $response['incomplete_details']['reason'] ?? 'unknown';

            throw $reason === 'max_output_tokens'
                ? new LlmOutputTooLong("Model output hit max_tokens ({$this->maxTokens})")
                : new LlmException("OpenAI response incomplete: {$reason}");
        }

        $text = '';
        foreach ($response['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    throw new LlmException('Model refused the request: '.($content['refusal'] ?? ''));
                }
                if (($content['type'] ?? null) === 'output_text') {
                    $text .= $content['text'];
                }
            }
        }

        return new LlmResult(
            text: trim($text),
            model: $response['model'] ?? $this->model,
            inputTokens: (int) ($response['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($response['usage']['output_tokens'] ?? 0),
        );
    }

    public function name(): string
    {
        return $this->model;
    }
}
