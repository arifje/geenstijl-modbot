<?php

namespace App\Services\Analysis;

use Anthropic\Client;
use InvalidArgumentException;

class LanguageModelFactory
{
    public const PROVIDERS = [
        'anthropic' => 'Anthropic (Claude)',
        'openai' => 'OpenAI',
        'openai_compatible' => 'OpenAI-compatible (Gemini, Mistral, Groq, Ollama, ...)',
    ];

    public function make(?string $provider = null): LanguageModel
    {
        $provider ??= config('modbot.llm.provider');
        $config = config("modbot.llm.providers.{$provider}");
        $llm = config('modbot.llm');

        if ($config === null) {
            throw new InvalidArgumentException("Unknown AI provider [{$provider}].");
        }

        if (blank($config['model'] ?? null)) {
            throw new LlmException("No model set for {$provider}. Set it in the admin under Settings.");
        }

        return match ($provider) {
            'anthropic' => new AnthropicLanguageModel(
                client: new Client(apiKey: $this->requireKey($provider, 'ANTHROPIC_API_KEY')),
                model: $config['model'],
                effort: $llm['effort'],
                maxTokens: $llm['max_tokens'],
            ),
            'openai' => new OpenAiLanguageModel(
                apiKey: $this->requireKey($provider, 'OPENAI_API_KEY'),
                model: $config['model'],
                effort: $llm['effort'],
                maxTokens: $llm['max_tokens'],
                timeout: $llm['timeout'],
                baseUrl: $config['base_url'],
            ),
            'openai_compatible' => new OpenAiCompatibleLanguageModel(
                // Optional: a local Ollama needs no key.
                apiKey: $config['api_key'],
                model: $config['model'],
                baseUrl: $config['base_url'] ?: throw new LlmException('No base URL set for the OpenAI-compatible provider.'),
                effort: $llm['effort'],
                maxTokens: $llm['max_tokens'],
                timeout: $llm['timeout'],
            ),
        };
    }

    public function hasKey(string $provider): bool
    {
        return filled(config("modbot.llm.providers.{$provider}.api_key"));
    }

    private function requireKey(string $provider, string $env): string
    {
        return config("modbot.llm.providers.{$provider}.api_key") ?: throw new LlmException("{$env} is not set.");
    }
}
