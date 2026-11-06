<?php

namespace App\Console\Commands;

use App\Services\QueueHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Started by the worker container before queue:work (docker-compose.yml,
 * Helm chart). Only run it where no queue worker is running.
 */
#[Signature('modbot:recover')]
#[Description('Fail runs and hand back jobs left behind by a previous queue worker')]
class RecoverAfterRestart extends Command
{
    public function handle(QueueHealth $health): int
    {
        $result = $health->recoverAfterRestart();

        // The worker restarts every hour; only log when there was something to clean up.
        if ($result['interrupted'] || $result['released_jobs']) {
            Log::warning("Queue worker restarted: {$result['interrupted']} interrupted run(s) marked failed, {$result['released_jobs']} unfinished job(s) handed back.", ['category' => 'queue', ...$result]);
        }

        $this->components->info("Queue worker starting; {$result['interrupted']} interrupted run(s) marked failed, {$result['released_jobs']} unfinished job(s) handed back.");

        return self::SUCCESS;
    }
}
