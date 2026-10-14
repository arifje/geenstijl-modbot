<?php

namespace App\Services;

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
    public function run(RunOptions $options, ?callable $progress = null): ModerationRun
    {
        $progress ??= fn (string $message) => null;
        $windowHours = $options->windowHours;
        $reanalyze = $options->reanalyze;

        // The window starts when the run starts, also for a run that was queued earlier.
        $attributes = [
            ...ModerationRun::attributesFor($options),
            'status' => 'running',
            'prompt_version' => $this->prompts->version('moderation'),
        ];

        $run = $options->runId
            ? tap(ModerationRun::findOrFail($options->runId))->update($attributes)
            : ModerationRun::create($attributes);
        $now = $run->window_end;

        try {
            $synced = $this->sync->sync($run->article_window_start, $run->window_start);
            $progress("Synced {$synced['articles']} articles, {$synced['comments']} comments");

            // Comments that could not be fetched are not analysed; don't report success.
            $syncFailed = count($synced['failed']);
            $run->sync_failed_count = $syncFailed;

            if ($syncFailed > 0 && $syncFailed === $synced['fetched']) {
                throw new RuntimeException("Comments could not be fetched for any of the {$syncFailed} articles, see the log.");
            }

            if ($syncFailed > 0) {
                $run->error = "Comments could not be fetched for {$syncFailed} of {$synced['fetched']} articles (".implode(', ', $synced['failed']).').';
                $progress($run->error);
            }

            $pending = $run->moderatableComments()
                ->unless($reanalyze, fn ($q) => $q->whereNull('analyzed_at'))
                ->get();

            // With $reanalyze, earlier flags are replaced per batch once that batch
            // succeeds (CommentAnalyzer::store); a failed batch keeps them.
            $progress("Analysing {$pending->count()} new comments");
            $stats = $this->analyzer->analyze($run, $pending);
            $progress("Analysed {$stats['analyzed']}, flagged {$stats['flagged']}, failed {$stats['failed']}");

            if ($stats['analyzed'] === 0 && $stats['failed'] > 0) {
                throw new RuntimeException("Analysis failed for all {$stats['failed']} comments: ".self::describeErrors($stats['errors']));
            }

            $inWindow = $run->moderatableComments();

            $run->fill([
                'articles_count' => (clone $inWindow)->distinct()->count('article_id'),
                'comments_count' => (clone $inWindow)->count(),
                'analyzed_count' => $stats['analyzed'],
                'failed_count' => $stats['failed'],
                'flagged_count' => $stats['flagged'],
            ]);

            // Only runs that produce a report need the sentiment; analysis-only
            // runs (e.g. every 15 minutes) skip this AI call.
            if ($options->sendSlack || $options->sendEmail || $options->dryRun) {
                $progress('Summarising the sentiment');
                $run->sentiment = $this->summarizer->summarize($run, $this->categoryCounts($run));
            }

            if ($stats['failed'] > 0) {
                $run->error = trim(($run->error ? $run->error."\n" : '')."Analysis failed for {$stats['failed']} comments: ".self::describeErrors($stats['errors']));
            }

            $run->fill([
                'status' => $stats['failed'] > 0 || $syncFailed > 0 ? 'partial' : 'succeeded',
                'finished_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $run->fill(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()])->save();

            throw $e;
        }

        return $run;
    }

    /**
     * "Anthropic API error: … (6 batches); …", most frequent first.
     *
     * @param  array<string, int>  $errors
     */
    private static function describeErrors(array $errors): string
    {
        arsort($errors);

        return collect($errors)
            ->take(3)
            ->map(fn (int $count, string $error) => $count > 1 ? "{$error} ({$count} batches)" : $error)
            ->implode('; ') ?: 'unknown error';
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
            ->whereHas('comment', fn ($q) => $q->moderatable($run->window_start, $run->window_end, $run->article_window_start))
            ->get()
            ->countBy(fn (CommentVerdict $v) => $v->category->label())
            ->all();
    }
}
