<?php

namespace Tests\Feature;

use App\Filament\Pages\QueueMonitor;
use App\Filament\Queue\ActiveJobsTable;
use App\Filament\Queue\FailedJobsTable;
use App\Jobs\RunModerationJob;
use App\Models\FailedJob;
use App\Models\ModerationRun;
use App\Models\QueuedJob;
use App\Models\User;
use App\Services\QueueHealth;
use App\Services\RunOptions;
use App\Support\Heartbeat;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class QueueMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        $this->actingAs(User::factory()->create());
    }

    private function queueRun(): ModerationRun
    {
        $run = ModerationRun::queue(new RunOptions(windowHours: 12, trigger: 'manual'));
        RunModerationJob::dispatch([...(new RunOptions(windowHours: 12))->toArray(), 'runId' => $run->id]);

        return $run;
    }

    public function test_the_page_shows_waiting_jobs_and_whether_the_scheduler_runs(): void
    {
        $run = $this->queueRun();

        $this->get('/admin/queue')
            ->assertOk()
            ->assertSee('Waiting and running jobs')
            ->assertSee('Failed jobs');

        $this->assertSame($run->id, QueuedJob::sole()->runId());
        Livewire::test(ActiveJobsTable::class)
            ->assertCanSeeTableRecords(QueuedJob::all())
            ->assertSee('RunModerationJob')
            ->assertSee("Run #{$run->id}");

        // Never seen: the menu warns.
        $this->assertSame('!', QueueMonitor::getNavigationBadge());

        // Each minute the scheduler starts the queue check; the worker loops.
        $task = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'modbot:queue-check'));
        event(new ScheduledTaskStarting($task));
        $this->assertTrue(Heartbeat::alive('scheduler'));
        $this->assertSame('!', QueueMonitor::getNavigationBadge());
        $this->assertSame('The queue worker is not running', QueueMonitor::getNavigationBadgeTooltip());

        event(new Looping('database', 'default'));
        $this->assertTrue(app(QueueHealth::class)->workerAlive());
        $this->assertNull(QueueMonitor::getNavigationBadge());

        $this->travel(Heartbeat::ALIVE_MINUTES + 1)->minutes();
        $this->assertFalse(Heartbeat::alive('scheduler'));
        $this->assertSame('!', QueueMonitor::getNavigationBadge());
        $this->assertSame('The scheduler is not running', QueueMonitor::getNavigationBadgeTooltip());
    }

    public function test_the_scheduler_only_queues_runs_and_starts_no_worker(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode("\n");

        $this->assertStringNotContainsString('queue:work', $commands);
        $this->assertTrue(collect(app(Schedule::class)->events())->every(fn ($e) => ! $e->runInBackground));
    }

    public function test_a_busy_worker_counts_as_running(): void
    {
        $this->queueRun();
        QueuedJob::query()->update(['reserved_at' => now()->getTimestamp(), 'attempts' => 1]);
        $this->travel(20)->minutes();

        $this->assertTrue(app(QueueHealth::class)->workerAlive());
        $this->assertNotNull(app(QueueHealth::class)->busyJob());
    }

    public function test_the_worker_can_be_restarted_from_the_page(): void
    {
        Livewire::test(QueueMonitor::class)
            ->callAction('restartWorker')
            ->assertNotified('Worker restarts');

        // queue:restart leaves a signal in the cache that the worker checks.
        $this->assertNotNull(Cache::get('illuminate:queue:restart'));
    }

    public function test_a_waiting_run_can_be_cancelled_from_the_queue(): void
    {
        $run = $this->queueRun();

        Livewire::test(ActiveJobsTable::class)
            ->callTableAction('remove', QueuedJob::sole())
            ->assertNotified('Job removed');

        $this->assertSame(0, QueuedJob::count());
        $this->assertSame('cancelled', $run->fresh()->status);
    }

    public function test_a_running_job_cannot_be_removed(): void
    {
        $this->queueRun();
        QueuedJob::query()->update(['reserved_at' => now()->getTimestamp(), 'attempts' => 1]);

        Livewire::test(ActiveJobsTable::class)
            ->assertTableActionHidden('remove', QueuedJob::sole());
    }

    public function test_failed_jobs_can_be_retried_and_deleted(): void
    {
        $other = $this->failJob('App\\Jobs\\SomethingElse', "RuntimeException: boom\n#0 trace");
        $runJob = $this->failJob(RunModerationJob::class, 'Timed out');

        Livewire::test(FailedJobsTable::class)
            ->assertCanSeeTableRecords([$other, $runJob])
            ->assertSee('RuntimeException: boom')
            // Moderation runs are retried from the run.
            ->assertTableActionHidden('retry', $runJob)
            ->assertTableActionVisible('retryRun', $runJob)
            ->callTableAction('retry', $other)
            ->assertNotified('Job queued again');

        $this->assertNull($other->fresh());
        $this->assertSame(1, DB::table('jobs')->count());

        Livewire::test(QueueMonitor::class)->callAction('deleteFailed');
        $this->assertSame(0, FailedJob::count());
    }

    public function test_a_queued_run_whose_job_is_gone_fails_so_it_can_be_retried(): void
    {
        $lost = $this->queueRun();
        $waiting = $this->queueRun();
        QueuedJob::all()->first(fn (QueuedJob $job) => $job->runId() === $lost->id)->delete();

        $this->artisan('modbot:queue-check')->assertSuccessful();
        $this->assertSame('queued', $lost->fresh()->status, 'Not before '.QueueHealth::ORPHAN_MINUTES.' minutes.');

        $this->travel(QueueHealth::ORPHAN_MINUTES + 1)->minutes();
        $this->artisan('modbot:queue-check')->assertSuccessful();

        $this->assertSame('failed', $lost->fresh()->status);
        $this->assertStringContainsString('Retry', $lost->fresh()->error);
        $this->assertSame('queued', $waiting->fresh()->status);
    }

    public function test_a_restarted_worker_cleans_up_after_the_previous_one(): void
    {
        Cache::lock(QueueHealth::RUN_LOCK, 1800)->get();
        $running = $this->queueRun();
        $running->update(['status' => 'running']);
        QueuedJob::query()->update(['reserved_at' => now()->getTimestamp(), 'attempts' => 1]);
        $queued = $this->queueRun();

        $this->artisan('modbot:recover')->assertSuccessful();

        $this->assertFalse(Cache::lock(QueueHealth::RUN_LOCK)->isLocked());
        $this->assertSame('failed', $running->fresh()->status);
        $this->assertStringContainsString('restarted', $running->fresh()->error);
        $this->assertSame('queued', $queued->fresh()->status);
        $this->assertSame(0, QueuedJob::whereNotNull('reserved_at')->count());
        $this->assertDatabaseHas('log_entries', ['category' => 'queue', 'level' => 'warning']);

        // The hourly restart with nothing to clean up is not logged.
        $logged = DB::table('log_entries')->count();
        $this->artisan('modbot:recover')->assertSuccessful();
        $this->assertSame($logged, DB::table('log_entries')->count());
    }

    private function failJob(string $class, string $exception): FailedJob
    {
        $run = ModerationRun::queue(new RunOptions(windowHours: 12, trigger: 'manual'));
        $command = $class === RunModerationJob::class
            ? serialize(new RunModerationJob(['runId' => $run->id]))
            : 'O:8:"stdClass":0:{}';

        $id = DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['uuid' => (string) Str::uuid(), 'displayName' => $class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => $class, 'command' => $command]]),
            'exception' => $exception,
            'failed_at' => now(),
        ]);

        return FailedJob::find($id);
    }
}
