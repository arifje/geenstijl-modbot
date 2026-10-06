<?php

namespace Tests\Feature;

use App\Services\Analysis\LlmException;
use App\Services\Analysis\ModelCatalog;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModelCatalogTest extends TestCase
{
    public function test_it_lists_chat_models_and_caches_them_for_15_minutes(): void
    {
        config(['modbot.llm.providers.openai.api_key' => 'sk-test']);
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [
            ['id' => 'gpt-b'], ['id' => 'gpt-a'], ['id' => 'text-embedding-3-large'], ['id' => 'whisper-1'], ['id' => 'tts-1'],
        ]])]);

        $catalog = app(ModelCatalog::class);

        $this->assertSame(['gpt-a' => 'gpt-a', 'gpt-b' => 'gpt-b'], $catalog->models('openai'));
        $catalog->models('openai');
        Http::assertSentCount(1);

        $this->travel(16)->minutes();
        $catalog->models('openai');
        Http::assertSentCount(2);
    }

    public function test_gemini_model_names_are_stripped(): void
    {
        Http::fake(['example.test/v1/models' => Http::response(['data' => [['id' => 'models/gemini-pro'], ['id' => 'models/gemini-flash']]])]);

        $models = app(ModelCatalog::class)->models('openai_compatible', ['base_url' => 'https://example.test/v1']);

        $this->assertSame(['gemini-flash', 'gemini-pro'], array_keys($models));
    }

    public function test_a_missing_key_is_reported_without_calling_the_api(): void
    {
        Http::fake();

        $this->assertThrows(fn () => app(ModelCatalog::class)->models('anthropic'), LlmException::class, 'ANTHROPIC_API_KEY is not set.');
        Http::assertNothingSent();
    }
}
