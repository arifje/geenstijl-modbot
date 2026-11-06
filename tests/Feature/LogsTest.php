<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Filament\Resources\LogEntries\Pages\ListLogEntries;
use App\Models\LogEntry;
use App\Models\User;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
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

        $this->assertSame(['nieuw'], LogEntry::where('message', '!=', '')->where('message', 'not like', 'Cleanup removed%')->pluck('message')->all());
    }

    public function test_background_tasks_are_logged_by_their_real_result(): void
    {
        Log::setDefaultDriver('database');
        $schedule = app(Schedule::class);
        $worker = $schedule->command('modbot:queue-check')->runInBackground();
        $run = $schedule->command('modbot:run --schedule=1')->runInBackground();

        // Started in the background: no exit code yet, nothing to report.
        event(new ScheduledTaskFinished($worker, 0.01));
        event(new ScheduledTaskFinished($run, 0.01));
        $this->assertSame(0, LogEntry::count());

        // Finished in the background.
        $worker->exitCode = 0;
        event(new ScheduledBackgroundTaskFinished($worker));
        $run->exitCode = 0;
        event(new ScheduledBackgroundTaskFinished($run));
        $worker->exitCode = 1;
        event(new ScheduledBackgroundTaskFinished($worker));

        $this->assertSame([
            'info: Scheduled task finished: modbot:run --schedule=1',
            'error: Scheduled task failed: modbot:queue-check',
        ], LogEntry::orderBy('id')->get()->map(fn ($e) => "{$e->level}: {$e->message}")->all());
    }

    public function test_the_cleanup_also_removes_failed_jobs_and_expired_cache(): void
    {
        DB::table('failed_jobs')->insert([
            ['uuid' => 'old', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(100)],
            ['uuid' => 'new', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()],
        ]);
        DB::table('cache')->insert([
            ['key' => 'expired', 'value' => 'x', 'expiration' => now()->subMinute()->getTimestamp()],
            ['key' => 'valid', 'value' => 'x', 'expiration' => now()->addHour()->getTimestamp()],
        ]);

        $this->artisan('modbot:prune')->expectsOutputToContain('1 failed jobs, 1 expired cache entries')->assertSuccessful();

        $this->assertSame(['new'], DB::table('failed_jobs')->pluck('uuid')->all());
        $this->assertSame(['valid'], DB::table('cache')->pluck('key')->all());
    }

    public function test_logs_can_be_cleared_from_the_admin(): void
    {
        $this->actingAs(User::factory()->create());
        LogEntry::create(['level' => 'info', 'level_value' => 200, 'category' => 'queue', 'message' => 'oud', 'created_at' => now()->subDays(3)]);
        LogEntry::create(['level' => 'info', 'level_value' => 200, 'category' => 'run', 'message' => 'oud run', 'created_at' => now()->subDays(3)]);
        LogEntry::create(['level' => 'info', 'level_value' => 200, 'category' => 'queue', 'message' => 'nieuw', 'created_at' => now()]);

        Livewire::test(ListLogEntries::class)
            ->callAction('clearLogs', data: ['age' => '1', 'category' => 'queue'])
            ->assertNotified('Removed 1 log entries');

        $this->assertSame(['oud run', 'nieuw'], LogEntry::orderBy('id')->pluck('message')->all());

        Livewire::test(ListLogEntries::class)
            ->callAction('clearLogs', data: ['age' => 'all', 'category' => null])
            ->assertNotified('Removed 2 log entries');

        $this->assertSame(0, LogEntry::count());
    }

    public function test_the_cleanup_can_be_started_from_settings(): void
    {
        $this->actingAs(User::factory()->create());
        LogEntry::create(['level' => 'info', 'level_value' => 200, 'category' => 'app', 'message' => 'oud', 'created_at' => now()->subDays(30)]);

        Livewire::test(Settings::class)
            ->callAction('cleanup')
            ->assertNotified('Cleanup done');

        $this->assertSame(0, LogEntry::where('message', 'oud')->count());
    }
}
