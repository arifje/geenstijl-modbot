<?php

namespace Tests\Feature;

use App\Filament\Resources\ModerationRuns\Pages\ListModerationRuns;
use App\Filament\Resources\ModerationRuns\Pages\ViewModerationRun;
use App\Jobs\RunModerationJob;
use App\Models\ModerationRun;
use App\Models\Schedule;
use App\Models\User;
use App\Services\RunOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class RetryRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        Queue::fake();
    }

    public function test_a_failed_run_can_be_retried_with_the_same_settings(): void
    {
        $failed = ModerationRun::queue(new RunOptions(
            windowHours: 6, articleWindowHours: 48, sendSlack: true, sendEmail: false,
            slackChannel: '#moderatie', trigger: 'manual',
        ));
        $failed->update(['status' => 'failed', 'error' => 'Analysis failed for all 252 comments: credit balance too low']);

        Livewire::test(ListModerationRuns::class)
            ->assertTableActionVisible('retry', $failed)
            ->mountTableAction('retry', $failed)
            ->assertTableActionDataSet([
                'windowHours' => 6, 'articleWindowHours' => 48, 'sendSlack' => true,
                'sendEmail' => false, 'slackChannel' => '#moderatie', 'dryRun' => false,
            ])
            ->callMountedTableAction()
            ->assertNotified('Retry queued');

        $retry = ModerationRun::latest('id')->first();
        $this->assertNotSame($failed->id, $retry->id);
        $this->assertSame('queued', $retry->status);
        $this->assertSame($failed->id, $retry->retry_of_id);
        $this->assertSame(48, $retry->articleWindowHours());

        Queue::assertPushed(RunModerationJob::class, fn (RunModerationJob $job) => $job->options['runId'] === $retry->id
            && $job->options['retryOfId'] === $failed->id
            && $job->options['slackChannel'] === '#moderatie'
            && $job->options['sendEmail'] === false);

        // The original run is left as it was.
        $this->assertSame('failed', $failed->fresh()->status);
    }

    public function test_retry_is_offered_only_for_failed_partial_and_cancelled_runs(): void
    {
        $runs = collect(['succeeded', 'running', 'queued', 'failed', 'partial', 'cancelled'])
            ->mapWithKeys(fn (string $status) => [$status => tap(ModerationRun::queue(new RunOptions(windowHours: 12)))->update(['status' => $status])]);

        $page = Livewire::test(ListModerationRuns::class);

        foreach (['succeeded', 'running', 'queued'] as $status) {
            $page->assertTableActionHidden('retry', $runs[$status]);
        }

        foreach (['failed', 'partial', 'cancelled'] as $status) {
            $page->assertTableActionVisible('retry', $runs[$status]);
        }
    }

    public function test_an_older_run_without_stored_options_gets_them_from_its_schedule(): void
    {
        $schedule = Schedule::create(['name' => 'Rapport', 'cron' => '0 8 * * *', 'window_hours' => 12, 'article_window_hours' => 24, 'send_slack' => true, 'send_email' => true]);
        $old = ModerationRun::create([
            'status' => 'partial', 'trigger' => 'schedule', 'schedule_id' => $schedule->id,
            'started_at' => now(), 'window_start' => now()->subHours(12), 'window_end' => now(),
            'article_window_start' => now()->subHours(24),
        ]);

        Livewire::test(ViewModerationRun::class, ['record' => $old->id])
            ->mountAction('retry')
            ->assertActionDataSet(['windowHours' => 12, 'articleWindowHours' => 24, 'sendSlack' => true, 'sendEmail' => true])
            ->callMountedAction()
            ->assertNotified('Retry queued');

        $retry = ModerationRun::latest('id')->first();
        $this->assertSame($schedule->id, $retry->schedule_id);
        $this->assertSame($old->id, $retry->retry_of_id);
    }
}
