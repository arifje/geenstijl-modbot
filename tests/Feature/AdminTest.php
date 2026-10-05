<?php

namespace Tests\Feature;

use App\Filament\Resources\ModerationRuns\Pages\ListModerationRuns;
use App\Jobs\RunModerationJob;
use App\Models\ModerationRun;
use App\Models\Schedule;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_admin_pages_load(): void
    {
        $run = ModerationRun::create([
            'status' => 'succeeded',
            'started_at' => now(),
            'finished_at' => now(),
            'window_start' => now()->subHours(12),
            'window_end' => now(),
            'model' => 'claude-opus-5-5',
            'input_tokens' => 1_000_000,
            'output_tokens' => 100_000,
        ]);

        foreach (['/admin', '/admin/runs', "/admin/runs/{$run->id}", '/admin/flags', '/admin/comments', '/admin/articles', '/admin/schedules', '/admin/schedules/create', '/admin/settings', '/admin/users'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->assertSame(6.0, $run->estimatedCost());
    }

    public function test_guests_are_redirected_to_login(): void
    {
        auth()->logout();

        $this->get('/admin/runs')->assertRedirect('/admin/login');
    }

    public function test_run_now_queues_a_run(): void
    {
        Queue::fake();

        Livewire::test(ListModerationRuns::class)
            ->callAction('runNow', data: ['windowHours' => 6, 'dryRun' => true, 'reanalyze' => false])
            ->assertNotified('Run queued');

        Queue::assertPushed(RunModerationJob::class, fn (RunModerationJob $job) => $job->options['windowHours'] === 6
            && $job->options['dryRun'] === true
            && $job->options['trigger'] === 'manual'
            && $job->options['userId'] === auth()->id());
    }

    public function test_stored_settings_override_the_environment(): void
    {
        Settings::save(['slack_channel' => '#moderatie', 'report_max_items' => 10]);
        config(['modbot.slack.channel' => '#from-env', 'modbot.report.max_items' => 50]);

        Settings::apply();

        $this->assertSame('#moderatie', config('modbot.slack.channel'));
        $this->assertSame(10, config('modbot.report.max_items'));
    }

    public function test_the_default_schedule_is_seeded_and_registered(): void
    {
        $schedule = Schedule::sole();
        $this->assertSame('0 8,20 * * *', $schedule->cron);

        // routes/console.php is loaded before the test database exists; load it again.
        require base_path('routes/console.php');

        $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->pluck('command')->implode("\n");
        $this->assertStringContainsString("modbot:run --schedule={$schedule->id}", str_replace(["'", '"'], '', $commands));
    }
}
