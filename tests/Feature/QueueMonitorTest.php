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
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        // Each minute the scheduler starts or skips the worker task.
        $task = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'queue:work'));
        event(new ScheduledTaskSkipped($task));
        $this->assertTrue(Heartbeat::alive('scheduler'));
        $this->assertNull(QueueMonitor::getNavigationBadge());

        $this->travel(Heartbeat::ALIVE_MINUTES + 1)->minutes();
        $this->assertFalse(Heartbeat::alive('scheduler'));
        $this->assertSame('!', QueueMonitor::getNavigationBadge());
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

    private function workerTask(): Event
    {
        return collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'queue:work'));
    }

    public function test_a_stale_worker_lock_is_released_when_jobs_wait(): void
    {
        // A worker took the lock and was killed (container restart).
        $task = $this->workerTask();
        $this->assertSame(QueueHealth::WORKER_MUTEX, $task->mutexName());
        $task->mutex->create($task);
        $this->assertFalse($task->filtersPass(app()), 'The scheduler skips the worker while the lock is held.');

        $this->queueRun();

        // A fresh job: the lock may still belong to a worker that is starting.
        $this->artisan('modbot:queue-check')->assertSuccessful();
        $this->assertTrue(app(QueueHealth::class)->workerLocked());

        $this->travel(QueueHealth::STUCK_MINUTES + 1)->minutes();
        $this->assertTrue(app(QueueHealth::class)->workerBlocked());
        $this->assertSame('!', QueueMonitor::getNavigationBadge());

        $this->artisan('modbot:queue-check')->assertSuccessful();
        $this->assertFalse(app(QueueHealth::class)->workerLocked());
        $this->assertTrue($task->filtersPass(app()));
        $this->assertDatabaseHas('log_entries', ['category' => 'queue', 'level' => 'warning']);
    }

    public function test_the_lock_of_a_worker_that_is_processing_a_run_is_kept(): void
    {
        $task = $this->workerTask();
        $task->mutex->create($task);
        $this->queueRun();
        $this->queueRun();
        QueuedJob::whereKey(QueuedJob::min('id'))->update(['reserved_at' => now()->getTimestamp(), 'attempts' => 1]);

        $this->travel(20)->minutes();
        $this->artisan('modbot:queue-check')->assertSuccessful();

        $this->assertFalse(app(QueueHealth::class)->workerBlocked());
        $this->assertTrue(app(QueueHealth::class)->workerLocked());
    }

    public function test_the_worker_can_be_released_from_the_page(): void
    {
        $task = $this->workerTask();
        $task->mutex->create($task);
        $this->travel(QueueHealth::STUCK_MINUTES + 1)->minutes();

        Livewire::test(QueueMonitor::class)
            ->assertActionVisible('releaseWorker')
            ->callAction('releaseWorker')
            ->assertNotified('Worker released');

        $this->assertFalse(app(QueueHealth::class)->workerLocked());
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

    public function test_a_restarted_scheduler_clears_what_the_previous_one_left(): void
    {
        $task = $this->workerTask();
        $task->mutex->create($task);
        Cache::lock(QueueHealth::RUN_LOCK, 1800)->get();
        $running = $this->queueRun();
        $running->update(['status' => 'running']);
        QueuedJob::query()->update(['reserved_at' => now()->getTimestamp(), 'attempts' => 1]);
        $queued = $this->queueRun();

        $this->artisan('modbot:recover')->assertSuccessful();

        $this->assertFalse(app(QueueHealth::class)->workerLocked());
        $this->assertFalse(Cache::lock(QueueHealth::RUN_LOCK)->isLocked());
        $this->assertSame('failed', $running->fresh()->status);
        $this->assertStringContainsString('restarted', $running->fresh()->error);
        $this->assertSame('queued', $queued->fresh()->status);
        $this->assertSame(0, QueuedJob::whereNotNull('reserved_at')->count());
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
