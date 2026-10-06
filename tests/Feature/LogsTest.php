<?php

namespace Tests\Feature;

use App\Models\LogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_records_are_stored_with_their_category(): void
    {
        Log::channel('database')->warning('Slack is down', ['category' => 'run', 'run_id' => 7]);
        Log::channel('database')->error('Boom', ['exception' => new \RuntimeException('kapot')]);

        $run = LogEntry::where('category', 'run')->sole();
        $this->assertSame('warning', $run->level);
        $this->assertSame(['run_id' => 7], $run->context);

        $app = LogEntry::where('category', 'app')->sole();
        $this->assertSame('error', $app->level);
        $this->assertSame('kapot', $app->context['exception']['message']);
        $this->assertSame(\RuntimeException::class, $app->context['exception']['class']);
    }

    public function test_the_logs_page_lists_and_filters_entries(): void
    {
        $this->actingAs(User::factory()->create());

        Log::channel('database')->info('Job started: RunModerationJob', ['category' => 'queue']);
        Log::channel('database')->error('Run failed: no API key', ['category' => 'run']);

        $this->get('/admin/logs')->assertOk()->assertSee('Job started: RunModerationJob')->assertSee('Run failed: no API key');
        $this->get('/admin/logs?filters[min_level][value]=400')->assertOk()
            ->assertSee('Run failed: no API key')
            ->assertDontSee('Job started: RunModerationJob');
    }

    public function test_old_entries_are_pruned(): void
    {
        LogEntry::create(['level' => 'info', 'level_value' => 200, 'category' => 'app', 'message' => 'oud', 'created_at' => now()->subDays(20)]);
        LogEntry::create(['level' => 'info', 'level_value' => 200, 'category' => 'app', 'message' => 'nieuw', 'created_at' => now()]);

        $this->artisan('modbot:prune')->assertSuccessful();

        $this->assertSame(['nieuw'], LogEntry::pluck('message')->all());
    }
}
