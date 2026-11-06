<?php

namespace App\Console\Commands;

use App\Services\QueueHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Runs every minute in the scheduler.
 */
#[Signature('modbot:queue-check')]
#[Description('Fail queued runs whose job is gone')]
class QueueCheck extends Command
{
    public function handle(QueueHealth $health): int
    {
        if ($orphaned = $health->failOrphanedRuns()) {
            Log::warning("{$orphaned} queued run(s) failed: their job was gone.", ['category' => 'queue']);
        }

        return self::SUCCESS;
    }
}
