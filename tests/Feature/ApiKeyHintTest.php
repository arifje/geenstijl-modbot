<?php

namespace Tests\Feature;

use App\Models\LogEntry;
use App\Models\ModerationRun;
use App\Services\Analysis\LanguageModel;
use App\Services\Analysis\LlmException;
use App\Services\RunOptions;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\FakeLanguageModel;
use Tests\TestCase;

class ApiKeyHintTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-ant-api03-SECRETPARTabcdefghijklmnop-Xy1AAA';

    protected function setUp(): void
    {
        parent::setUp();

        config(['modbot.llm.provider' => 'anthropic', 'modbot.llm.providers.anthropic.api_key' => self::KEY]);
        Settings::apply();
    }

    public function test_keys_are_masked_to_their_first_and_last_characters(): void
    {
        $this->assertSame('sk-ant-api…1AAA', Settings::mask(self::KEY));
        $this->assertSame('sk-ant-api…1AAA (from .env)', Settings::keyHint());

        Settings::saveSecret('anthropic_api_key', 'sk-ant-api03-OTHERKEYabcdefghijklmn-Zz9BBB');
        $this->assertSame('sk-ant-api…9BBB (saved in ModBot)', Settings::keyHint());

        config(['modbot.llm.providers.openai.api_key' => null]);
        $this->assertSame('none', Settings::keyHint('openai'));
    }

    public function test_a_run_records_which_key_it_used(): void
    {
        $run = ModerationRun::queue(new RunOptions(windowHours: 12));

        $this->assertSame('sk-ant-api…1AAA (from .env)', $run->api_key);
    }

    public function test_failures_name_the_key_but_never_contain_it(): void
    {
        Log::setDefaultDriver('database');
        Http::fake([
            '*/articles*' => Http::response(['data' => [['id' => 1, 'slug' => 'a', 'title' => 'A', 'publishedAt' => now('Europe/Amsterdam')->subHour()->format('Y-m-d\TH:i:s'), 'commentsCount' => 1]], 'meta' => ['pagination' => ['pageCount' => 1]]]),
            '*/v3/1/comments' => Http::response(['closed' => false, 'data' => [[
                'id' => 'c-1', 'author' => ['id' => '1', 'username' => 'u'], 'content' => 'Reactie',
                'createdAt' => now('Europe/Amsterdam')->subMinutes(10)->format('Y-m-d\TH:i:s'), 'status' => 'visible', 'approved' => false, 'parentId' => null,
            ]]]),
        ]);
        $this->app->instance(LanguageModel::class, new FakeLanguageModel(fn () => throw new LlmException('Anthropic API error: Your credit balance is too low')));

        $this->artisan('modbot:run --no-slack --no-email')->assertFailed();

        $run = ModerationRun::sole();
        $this->assertStringContainsString('credit balance is too low — API key sk-ant-api…1AAA (from .env)', $run->error);

        $entry = LogEntry::where('message', 'like', 'Analysing comments failed%')->sole();
        $this->assertStringContainsString('[API key sk-ant-api…1AAA (from .env)]', $entry->message);
        $this->assertSame('sk-ant-api…1AAA (from .env)', $entry->context['api_key']);

        // The full key is nowhere: not in logs, not on the run.
        $this->assertSame(0, LogEntry::where('message', 'like', '%SECRETPART%')->count());
        $this->assertStringNotContainsString('SECRETPART', json_encode(LogEntry::pluck('context')));
        $this->assertStringNotContainsString('SECRETPART', json_encode($run->fresh()->toArray()));
    }
}
