<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\LogEntry;
use App\Models\ModerationRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('modbot:prune {--days= : Retention in days (default MODBOT_RETENTION_DAYS)}')]
#[Description('Delete stored articles, comments and runs older than the retention period')]
class PruneData extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subDays((int) ($this->option('days') ?: config('modbot.retention_days')));

        // Comments and verdicts cascade with their article.
        $articles = Article::where('published_at', '<', $cutoff)->delete();
        $runs = ModerationRun::where('started_at', '<', $cutoff)->delete();

        $logs = LogEntry::where('created_at', '<', now()->subDays(config('modbot.log_retention_days')))->delete();

        $this->components->info("Pruned {$articles} articles and {$runs} runs older than {$cutoff->toDateString()}, and {$logs} log entries");

        return self::SUCCESS;
    }
}
