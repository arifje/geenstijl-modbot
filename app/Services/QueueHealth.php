<?php

namespace App\Services;

use App\Models\ModerationRun;
use App\Models\QueuedJob;
use App\Support\Heartbeat;
use Illuminate\Support\Facades\Cache;

/**
 * State of the queue worker container ("php artisan queue:work"), which runs
 * all moderation runs, one at a time.
 */
class QueueHealth
{
    /** Lock of a running moderation run (ModerationRunner). */
    public const RUN_LOCK = 'modbot:run';

    /** A queued run without a job after this long will never start. */
    public const ORPHAN_MINUTES = 10;

    /**
     * Processing a job, or looked for one lately (an idle worker checks the
     * queue every few seconds).
     */
    public function workerAlive(): bool
    {
        return $this->busyJob() !== null || Heartbeat::alive('worker');
    }

    public function busyJob(): ?QueuedJob
    {
        return QueuedJob::whereNotNull('reserved_at')->orderBy('reserved_at')->first();
    }

    /**
     * Queued runs whose job was removed or lost would stay "queued" forever.
     */
    public function failOrphanedRuns(): int
    {
        $waitingRunIds = QueuedJob::all()->map(fn (QueuedJob $job) => $job->runId())->filter()->values()->all();

        return ModerationRun::where('status', 'queued')
            ->where('started_at', '<', now()->subMinutes(self::ORPHAN_MINUTES))
            ->whereNotIn('id', $waitingRunIds)
            ->update([
                'status' => 'failed',
                'error' => 'The queued job is gone, so this run never started. Use Retry to start it again.',
                'finished_at' => now(),
            ]);
    }

    /**
     * When the worker container starts, nothing of a previous worker can
     * still be running (there is only one worker): fail the runs it was doing
     * and hand its unfinished jobs back.
     *
     * @return array{interrupted: int, released_jobs: int}
     */
    public function recoverAfterRestart(): array
    {
        Cache::lock(self::RUN_LOCK)->forceRelease();

        $interrupted = ModerationRun::where('status', 'running')->update([
            'status' => 'failed',
            'error' => 'Interrupted: the queue worker restarted while this run was in progress. Use Retry to run it again.',
            'finished_at' => now(),
        ]);

        // With one try each, these end up under failed jobs right away
        // instead of after the retry_after period.
        $releasedJobs = QueuedJob::whereNotNull('reserved_at')->update(['reserved_at' => null]);

        return ['interrupted' => $interrupted, 'released_jobs' => $releasedJobs];
    }
}
