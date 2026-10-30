<?php

namespace App\Console\Commands;

use App\Services\QueueHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Runs every minute, just before the queue worker is started.
 */
#[Signature('modbot:queue-check')]
#[Description('Release a stale queue worker lock and fail queued runs whose job is gone')]
class QueueCheck extends Command
{
    public function handle(QueueHealth $health): int
    {
        $result = $health->check();

        if ($result['released']) {
            Log::warning('Released a stale queue worker lock: jobs were waiting but no worker was running.', ['category' => 'queue']);
        }

        if ($result['orphaned']) {
            Log::warning("{$result['orphaned']} queued run(s) failed: their job was gone.", ['category' => 'queue']);
        }

        return self::SUCCESS;
    }
}
