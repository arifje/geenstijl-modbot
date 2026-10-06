<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\LogEntry;
use App\Models\ModerationRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily cleanup (04:00, see routes/console.php), also available as
 * "Run cleanup now" under Settings.
 */
#[Signature('modbot:prune {--days= : Retention for comments and runs (default MODBOT_RETENTION_DAYS)}')]
#[Description('Delete old comments, runs, log entries, failed jobs and expired cache entries')]
class PruneData extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subDays((int) ($this->option('days') ?: config('modbot.retention_days')));
        $logCutoff = now()->subDays((int) config('modbot.log_retention_days'));

        $counts = [
            // Comments, flags and summaries cascade with their article.
            'articles' => Article::where('published_at', '<', $cutoff)->delete(),
            'runs' => ModerationRun::where('started_at', '<', $cutoff)->delete(),
            'log entries' => LogEntry::where('created_at', '<', $logCutoff)->delete(),
            'failed jobs' => DB::table('failed_jobs')->where('failed_at', '<', $cutoff)->delete(),
            // The database cache store never removes expired entries itself.
            'expired cache entries' => DB::table('cache')->where('expiration', '<', now()->getTimestamp())->delete()
                + DB::table('cache_locks')->where('expiration', '<', now()->getTimestamp())->delete(),
        ];

        $summary = collect($counts)->map(fn (int $count, string $what) => "{$count} {$what}")->implode(', ');

        Log::info("Cleanup removed {$summary}", ['category' => 'app', 'retention_days' => (int) $cutoff->diffInDays(now(), true), 'log_retention_days' => (int) config('modbot.log_retention_days')]);
        $this->components->info("Removed {$summary}");

        return self::SUCCESS;
    }
}
