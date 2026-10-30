<?php

namespace App\Console\Commands;

use App\Services\QueueHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Started by the scheduler container before schedule:work (Dockerfile CMD,
 * Helm chart). Only run it where no scheduler is running.
 */
#[Signature('modbot:recover')]
#[Description('Clear locks and interrupted runs left behind by a previous scheduler container')]
class RecoverAfterRestart extends Command
{
    public function handle(QueueHealth $health): int
    {
        $result = $health->recoverAfterRestart();

        Log::info('Scheduler started; cleared locks of the previous scheduler.', ['category' => 'scheduler', ...$result]);

        if ($result['interrupted']) {
            Log::warning("{$result['interrupted']} run(s) were interrupted by the restart and marked failed.", ['category' => 'run']);
        }

        $this->components->info("Cleared scheduler locks; {$result['interrupted']} interrupted run(s) marked failed, {$result['released_jobs']} unfinished job(s) handed back.");

        return self::SUCCESS;
    }
}
