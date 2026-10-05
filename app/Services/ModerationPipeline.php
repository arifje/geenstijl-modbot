<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\CommentVerdict;
use App\Models\ModerationRun;
use App\Services\Analysis\CommentAnalyzer;
use App\Services\Analysis\PromptRepository;
use App\Services\Analysis\SentimentSummarizer;
use App\Services\GeenStijl\CommentSync;
use App\Services\Reporting\Report;
use App\Services\Reporting\ReportBuilder;
use RuntimeException;

/**
 * sync → analyse new comments → summarise → build report.
 * Delivery is left to the caller.
 */
class ModerationPipeline
{
    public function __construct(
        private CommentSync $sync,
        private CommentAnalyzer $analyzer,
        private SentimentSummarizer $summarizer,
        private ReportBuilder $reports,
        private PromptRepository $prompts,
    ) {}

    /**
     * @param  callable(string): void|null  $progress
     */
    public function run(int $windowHours, bool $reanalyze = false, ?callable $progress = null): ModerationRun
    {
        $progress ??= fn (string $message) => null;
        $now = now();

        $run = ModerationRun::create([
            'started_at' => $now,
            'window_start' => $now->copy()->subHours($windowHours),
            'window_end' => $now,
            'prompt_version' => $this->prompts->version('moderation'),
        ]);

        try {
            $lookback = max($windowHours, config('modbot.article_lookback_hours'));
            $synced = $this->sync->sync($now->copy()->subHours($lookback), $run->window_start);
            $progress("Synced {$synced['articles']} articles, {$synced['comments']} comments");

            $pending = Comment::moderatable($run->window_start, $run->window_end)
                ->unless($reanalyze, fn ($q) => $q->whereNull('analyzed_at'))
                ->get();

            if ($reanalyze) {
                CommentVerdict::whereIn('comment_id', $pending->pluck('id'))->delete();
            }

            $progress("Analysing {$pending->count()} new comments");
            $stats = $this->analyzer->analyze($run, $pending);
            $progress("Analysed {$stats['analyzed']}, flagged {$stats['flagged']}, failed {$stats['failed']}");

            if ($stats['analyzed'] === 0 && $stats['failed'] > 0) {
                throw new RuntimeException("Analysis failed for all {$stats['failed']} comments, see the log.");
            }

            $run->fill([
                'analyzed_count' => $stats['analyzed'],
                'failed_count' => $stats['failed'],
                'flagged_count' => $stats['flagged'],
            ]);

            $run->sentiment = $this->summarizer->summarize($run, $stats['summaries'], $this->categoryCounts($run));

            $run->fill([
                'status' => $stats['failed'] > 0 ? 'partial' : 'succeeded',
                'finished_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $run->fill(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()])->save();

            throw $e;
        }

        return $run;
    }

    public function report(ModerationRun $run): Report
    {
        return $this->reports->build($run);
    }

    /**
     * @return array<string, int>
     */
    private function categoryCounts(ModerationRun $run): array
    {
        return CommentVerdict::query()
            ->whereHas('comment', fn ($q) => $q->moderatable($run->window_start, $run->window_end))
            ->get()
            ->countBy(fn (CommentVerdict $v) => $v->category->label())
            ->all();
    }
}
