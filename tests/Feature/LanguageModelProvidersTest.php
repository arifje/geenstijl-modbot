<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\User;
use App\Services\Analysis\LanguageModel;
use App\Services\Analysis\LanguageModelFactory;
use App\Services\Analysis\LlmException;
use App\Services\Analysis\OpenAiCompatibleLanguageModel;
use App\Services\Analysis\OpenAiLanguageModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class LanguageModelProvidersTest extends TestCase
{
    use RefreshDatabase;

    private const SCHEMA = ['type' => 'object', 'properties' => ['flags' => ['type' => 'array']], 'required' => ['flags'], 'additionalProperties' => false];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modbot.llm.providers.openai.api_key' => 'sk-test',
            'modbot.llm.providers.openai.model' => 'gpt-test',
            'modbot.llm.providers.openai_compatible.api_key' => 'gm-test',
            'modbot.llm.providers.openai_compatible.model' => 'gemini-test',
            'modbot.llm.providers.openai_compatible.base_url' => 'https://example.test/v1/',
            'modbot.llm.effort' => 'medium',
        ]);
    }

    public function test_the_factory_builds_the_configured_provider(): void
    {
        config(['modbot.llm.provider' => 'openai']);
        $this->assertInstanceOf(OpenAiLanguageModel::class, app(LanguageModel::class));

        config(['modbot.llm.provider' => 'openai_compatible']);
        $this->assertInstanceOf(OpenAiCompatibleLanguageModel::class, app(LanguageModel::class));
    }

    public function test_missing_keys_and_models_are_explained(): void
    {
        config(['modbot.llm.providers.openai.api_key' => null]);
        $this->assertThrows(fn () => app(LanguageModelFactory::class)->make('openai'), LlmException::class, 'OPENAI_API_KEY is not set.');

        config(['modbot.llm.providers.openai_compatible.model' => '']);
        $this->assertThrows(fn () => app(LanguageModelFactory::class)->make('openai_compatible'), LlmException::class, 'No model set');
    }

    public function test_openai_responses_api(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'model' => 'gpt-test-2026',
            'status' => 'completed',
            'output' => [
                ['type' => 'reasoning', 'summary' => []],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"flags":[]}']]],
            ],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 7],
        ])]);

        $result = app(LanguageModelFactory::class)->make('openai')->complete('systeem', 'invoer', self::SCHEMA);

        $this->assertSame(['flags' => []], $result->json());
        $this->assertSame('gpt-test-2026', $result->model);
        $this->assertSame([120, 7], [$result->inputTokens, $result->outputTokens]);

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer sk-test')
            && $r['instructions'] === 'systeem'
            && $r['input'] === 'invoer'
            && $r['text']['format']['type'] === 'json_schema'
            && $r['text']['format']['strict'] === true
            && $r['reasoning'] === ['effort' => 'medium']);
    }

    public function test_openai_errors_become_llm_exceptions(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided']], 401)]);

        $this->assertThrows(
            fn () => app(LanguageModelFactory::class)->make('openai')->complete('s', 'i'),
            LlmException::class,
            'Incorrect API key provided',
        );
    }

    public function test_openai_compatible_chat_completions(): void
    {
        config(['modbot.llm.effort' => 'default']);

        Http::fake(['example.test/v1/chat/completions' => Http::response([
            'model' => 'gemini-test',
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => "```json\n{\"flags\":[]}\n```"]]],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 5],
        ])]);

        $result = app(LanguageModelFactory::class)->make('openai_compatible')->complete('systeem', 'invoer', self::SCHEMA);

        $this->assertSame(['flags' => []], $result->json());
        $this->assertSame([50, 5], [$result->inputTokens, $result->outputTokens]);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://example.test/v1/chat/completions'
            && $r['messages'][0] === ['role' => 'system', 'content' => 'systeem']
            && $r['response_format']['json_schema']['schema'] === self::SCHEMA
            && ! isset($r['reasoning_effort']));
    }

    public function test_truncated_output_is_an_error(): void
    {
        Http::fake(['example.test/*' => Http::response(['choices' => [['finish_reason' => 'length', 'message' => ['content' => '{"fla']]]])]);

        $this->assertThrows(
            fn () => app(LanguageModelFactory::class)->make('openai_compatible')->complete('s', 'i'),
            LlmException::class,
            'max_tokens',
        );
    }

    public function test_the_settings_page_tests_the_selected_provider_without_saving(): void
    {
        $this->actingAs(User::factory()->create());

        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'gpt-test', 'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'OK']]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 1],
        ])]);

        Livewire::test(Settings::class)
            ->fillForm(['llm_provider' => 'openai', 'openai_model' => 'gpt-other'])
            ->callAction('testAi')
            ->assertNotified('AI connection works');

        Http::assertSent(fn (Request $r) => $r['model'] === 'gpt-other');
        $this->assertDatabaseMissing('settings', ['key' => 'llm_provider']);
    }
}
