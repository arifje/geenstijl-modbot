<?php

namespace App\Services\Analysis;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Lib\Streaming\MessageAccumulator;

class AnthropicLanguageModel implements LanguageModel
{
    public function __construct(
        private Client $client,
        private string $model,
        private string $effort,
        private int $maxTokens,
    ) {}

    public function complete(string $system, string $input, ?array $schema = null): LlmResult
    {
        $outputConfig = $this->effort === 'default' ? [] : ['effort' => $this->effort];

        if ($schema !== null) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $schema];
        }

        try {
            // Streamed: the SDK needs streaming for large max_tokens values, to
            // stay clear of HTTP timeouts. The accumulator builds the final message.
            $stream = $this->client->beta->messages->createStream(
                model: $this->model,
                maxTokens: $this->maxTokens,
                system: [
                    ['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']],
                ],
                messages: [
                    ['role' => 'user', 'content' => $input],
                ],
                outputConfig: $outputConfig ?: null,
                // Retry on another model if a safety classifier declines the request.
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );

            $accumulator = MessageAccumulator::forBetaMessages();
            foreach ($stream as $event) {
                $accumulator->accumulate($event);
            }
            $message = $accumulator->message();
        } catch (AnthropicException $e) {
            throw new LlmException('Anthropic API error: '.$e->getMessage(), previous: $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new LlmException('Model refused the request: '.($message->stopDetails?->category ?? 'unknown'));
        }

        if ($message->stopReason === 'max_tokens') {
            throw new LlmOutputTooLong("Model output hit max_tokens ({$this->maxTokens})");
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return new LlmResult(
            text: trim($text),
            model: $message->model,
            inputTokens: $message->usage->inputTokens
                + ($message->usage->cacheReadInputTokens ?? 0)
                + ($message->usage->cacheCreationInputTokens ?? 0),
            outputTokens: $message->usage->outputTokens,
        );
    }

    public function name(): string
    {
        return $this->model;
    }
}
