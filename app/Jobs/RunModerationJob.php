<?php

namespace App\Jobs;

use App\Models\ModerationRun;
use App\Services\ModerationRunner;
use App\Services\RunOptions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Run started from the admin or queued by a schedule. Picked up by the worker
 * container (php artisan queue:work).
 */
class RunModerationJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1500;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $options  RunOptions::toArray()
     */
    public function __construct(public array $options) {}

    public function handle(ModerationRunner $runner): void
    {
        $runner->run(RunOptions::fromArray($this->options));
    }

    /**
     * E.g. a timeout or a killed worker: don't leave the run "queued" or "running".
     */
    public function failed(?\Throwable $e): void
    {
        ModerationRun::whereKey($this->options['runId'] ?? null)
            ->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error' => $e?->getMessage() ?? 'Job failed', 'finished_at' => now()]);
    }
}
