<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\ModerationRun;
use App\Models\Setting;
use App\Models\User;
use App\Services\Analysis\LanguageModel;
use App\Services\Analysis\LlmOutputTooLong;
use App\Services\Analysis\LlmResult;
use App\Support\Settings as SettingsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ApiKeysAndLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_answer_that_hits_max_tokens_is_retried_in_smaller_batches(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        config(['modbot.llm.batch_size' => 40]);

        $comments = collect(range(1, 40))->map(fn (int $i) => [
            'id' => "c-{$i}", 'author' => ['id' => '1', 'username' => "u{$i}"], 'content' => "Reactie {$i}",
            'createdAt' => '2026-10-05T11:00:00', 'status' => 'visible', 'approved' => false, 'parentId' => null,
        ])->all();

        Http::fake([
            '*/articles*' => Http::response(['data' => [['id' => 1, 'slug' => 'a', 'title' => 'A', 'publishedAt' => '2026-10-05T09:00:00', 'commentsCount' => 40]], 'meta' => ['pagination' => ['pageCount' => 1]]]),
            '*/v3/1/comments' => Http::response(['closed' => false, 'data' => $comments]),
        ]);

        // A model whose answer only fits for batches of at most 10 comments.
        $sizes = [];
        $this->app->instance(LanguageModel::class, new class($sizes) implements LanguageModel
        {
            public function __construct(public array &$sizes) {}

            public function complete(string $system, string $input, ?array $schema = null): LlmResult
            {
                $count = count(json_decode($input, true)['comments'] ?? []);
                $this->sizes[] = $count;

                if ($schema !== null && $count > 10) {
                    throw new LlmOutputTooLong('Model output hit max_tokens (32000)');
                }

                return new LlmResult($schema === null ? 'Rustig.' : json_encode(['summary' => 'Rustig.', 'flags' => []]), 'fake', 10, 10);
            }

            public function name(): string
            {
                return 'fake';
            }
        });

        $this->artisan('modbot:run --no-slack --no-email')->assertSuccessful();

        // 40 → 2×20 → 4×10: everything analysed, nothing failed.
        $this->assertSame([40, 20, 10, 10, 20, 10, 10], array_slice($sizes, 0, 7));
        $run = ModerationRun::sole();
        $this->assertSame(40, $run->analyzed_count);
        $this->assertSame(0, $run->failed_count);
        $this->assertSame('succeeded', $run->status);
    }

    public function test_max_tokens_and_batch_size_are_settings(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Settings::class)
            ->fillForm(['llm_max_tokens' => 48000, 'llm_batch_size' => 150])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(48000, config('modbot.llm.max_tokens'));
        $this->assertSame(150, config('modbot.llm.batch_size'));
        $this->assertSame(48000, Setting::find('llm_max_tokens')->value);
    }

    public function test_an_api_key_is_stored_encrypted_and_replaces_the_env_key(): void
    {
        $this->actingAs(User::factory()->create());
        config(['modbot.llm.providers.anthropic.api_key' => 'sk-ant-from-env-0000']);
        SettingsStore::apply(); // remembers the .env key

        Livewire::test(Settings::class)
            ->assertFormSet(['anthropic_api_key' => null])
            ->fillForm(['anthropic_api_key' => 'sk-ant-saved-in-admin-1234'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertFormSet(['anthropic_api_key' => null]); // never echoed back

        $raw = Setting::find('anthropic_api_key')->value;
        $this->assertStringNotContainsString('saved-in-admin', $raw);
        $this->assertSame('sk-ant-saved-in-admin-1234', Crypt::decryptString($raw));

        SettingsStore::apply();
        $this->assertSame('sk-ant-saved-in-admin-1234', config('modbot.llm.providers.anthropic.api_key'));

        // The page shows it masked only.
        $html = $this->get('/admin/settings')->assertOk()->getContent();
        $this->assertStringContainsString('sk-ant-…1234', $html);
        $this->assertStringNotContainsString('sk-ant-saved-in-admin-1234', $html);

        // Removing it falls back to .env.
        SettingsStore::forgetSecret('anthropic_api_key');
        SettingsStore::apply();
        $this->assertSame('sk-ant-from-env-0000', config('modbot.llm.providers.anthropic.api_key'));
    }

    public function test_the_ai_test_uses_a_typed_key_without_saving_it(): void
    {
        $this->actingAs(User::factory()->create());
        config(['modbot.llm.providers.openai.model' => 'gpt-test']);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'model' => 'gpt-test', 'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'OK']]]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), 'api.openai.com/v1/models' => Http::response(['data' => []])]);

        Livewire::test(Settings::class)
            ->fillForm(['llm_provider' => 'openai', 'openai_model' => 'gpt-test', 'openai_api_key' => 'sk-typed-but-not-saved'])
            ->callAction('testAi')
            ->assertNotified('AI connection works');

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer sk-typed-but-not-saved'));
        $this->assertNull(Setting::find('openai_api_key'));
    }

    public function test_each_provider_links_to_its_api_key_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/settings')->assertSee('https://platform.claude.com/settings/keys', false);

        Livewire::test(Settings::class)
            ->fillForm(['llm_provider' => 'openai_compatible', 'compatible_base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai'])
            ->assertSee('https://aistudio.google.com/apikey');
    }
}
