<?php

namespace App\Services;

use App\Models\ModerationRun;
use App\Models\QueuedJob;
use App\Support\Heartbeat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the queue moving: the scheduler only starts a worker while no other
 * worker holds its lock, and that lock lives in the database, so a killed
 * worker (container restart) can leave it behind.
 */
class QueueHealth
{
    /** Lock name of the "queue:work" schedule task (see routes/console.php). */
    public const WORKER_MUTEX = 'modbot:queue-worker';

    /** Lock of a running moderation run (ModerationRunner). */
    public const RUN_LOCK = 'modbot:run';

    /** A waiting job older than this means the worker is not starting. */
    public const STUCK_MINUTES = 3;

    /** A queued run without a job after this long will never start. */
    public const ORPHAN_MINUTES = 10;

    public function workerLocked(): bool
    {
        return Cache::lock(self::WORKER_MUTEX)->isLocked();
    }

    /**
     * The lock is held, but no worker is processing a job or has been
     * active lately: the worker that took it is gone or hanging.
     */
    public function workerBlocked(): bool
    {
        return $this->workerLocked()
            && ! QueuedJob::whereNotNull('reserved_at')->exists()
            && ! Heartbeat::lastSeen('worker')?->gt(now()->subMinutes(self::STUCK_MINUTES));
    }

    public function releaseWorker(): void
    {
        Cache::lock(self::WORKER_MUTEX)->forceRelease();
    }

    /**
     * Every minute, before the worker is started: release a blocked worker
     * lock when jobs are waiting, and fail queued runs whose job is gone.
     *
     * @return array{released: bool, orphaned: int}
     */
    public function check(): array
    {
        $waitingSince = QueuedJob::whereNull('reserved_at')->min('created_at');
        $released = $waitingSince
            && $waitingSince < now()->subMinutes(self::STUCK_MINUTES)->getTimestamp()
            && $this->workerBlocked();

        if ($released) {
            $this->releaseWorker();
        }

        return ['released' => $released, 'orphaned' => $this->failOrphanedRuns()];
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
     * When the scheduler container starts, nothing of a previous container
     * can still be running (there is only one scheduler): clear its locks,
     * fail the runs it was doing, and hand its unfinished jobs back.
     *
     * @return array{interrupted: int, released_jobs: int}
     */
    public function recoverAfterRestart(): array
    {
        Artisan::call('schedule:clear-cache');
        Cache::lock(self::RUN_LOCK)->forceRelease();

        $interrupted = ModerationRun::where('status', 'running')->update([
            'status' => 'failed',
            'error' => 'Interrupted: the scheduler restarted while this run was in progress. Use Retry to run it again.',
            'finished_at' => now(),
        ]);

        // With one try each, these end up under failed jobs right away
        // instead of after the retry_after period.
        $releasedJobs = QueuedJob::whereNotNull('reserved_at')->update(['reserved_at' => null]);

        return ['interrupted' => $interrupted, 'released_jobs' => $releasedJobs];
    }
}
