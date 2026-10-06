<?php

namespace App\Services\Analysis;

use Anthropic\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Lists the models a provider offers, for the model dropdown in Settings.
 * Cached for 15 minutes per provider, base URL and key.
 */
class ModelCatalog
{
    public const TTL_SECONDS = 900;

    // OpenAI's /models also lists embedding, audio and image models.
    private const NON_CHAT = ['embedding', 'tts', 'whisper', 'dall-e', 'image', 'audio', 'transcribe', 'realtime', 'moderation', 'search', 'davinci', 'babbage', 'sora'];

    /**
     * @param  array{api_key?: ?string, base_url?: ?string}  $overrides  e.g. the unsaved form values
     * @return array<string, string> model id => label
     *
     * @throws LlmException when the provider cannot be reached
     */
    public function models(string $provider, array $overrides = []): array
    {
        $config = array_merge(config("modbot.llm.providers.{$provider}", []), array_filter($overrides));

        return Cache::remember($this->cacheKey($provider, $config), self::TTL_SECONDS, fn () => match ($provider) {
            'anthropic' => $this->anthropic($config),
            'openai', 'openai_compatible' => $this->openAiStyle($provider, $config),
            default => [],
        });
    }

    public function forget(string $provider, array $overrides = []): void
    {
        $config = array_merge(config("modbot.llm.providers.{$provider}", []), array_filter($overrides));
        Cache::forget($this->cacheKey($provider, $config));
    }

    /**
     * @return array<string, string>
     */
    private function anthropic(array $config): array
    {
        if (blank($config['api_key'] ?? null)) {
            throw new LlmException('ANTHROPIC_API_KEY is not set.');
        }

        try {
            $models = [];
            foreach ((new Client(apiKey: $config['api_key']))->models->list(limit: 100)->pagingEachItem() as $model) {
                $models[$model->id] = "{$model->displayName} ({$model->id})";
            }
        } catch (\Throwable $e) {
            throw new LlmException('Could not load Anthropic models: '.$e->getMessage(), previous: $e);
        }

        return $models;
    }

    /**
     * @return array<string, string>
     */
    private function openAiStyle(string $provider, array $config): array
    {
        if (blank($config['base_url'] ?? null)) {
            throw new LlmException('Set the base URL first.');
        }

        if ($provider === 'openai' && blank($config['api_key'] ?? null)) {
            throw new LlmException('OPENAI_API_KEY is not set.');
        }

        try {
            $data = Http::when(filled($config['api_key'] ?? null), fn ($http) => $http->withToken($config['api_key']))
                ->acceptJson()
                ->timeout(15)
                ->get(rtrim($config['base_url'], '/').'/models')
                ->throw()
                ->json('data', []);
        } catch (\Throwable $e) {
            throw new LlmException('Could not load models: '.$e->getMessage(), previous: $e);
        }

        return collect($data)
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && ! str($id)->lower()->contains(self::NON_CHAT))
            // Gemini returns "models/gemini-…", but expects the bare id in requests.
            ->map(fn (string $id) => str_starts_with($id, 'models/') ? substr($id, 7) : $id)
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $id) => [$id => $id])
            ->all();
    }

    private function cacheKey(string $provider, array $config): string
    {
        return 'modbot:models:'.$provider.':'.sha1(($config['base_url'] ?? '').'|'.($config['api_key'] ?? ''));
    }
}
