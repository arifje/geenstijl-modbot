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
use App\Services\RunOptions;
use App\Support\Heartbeat;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
