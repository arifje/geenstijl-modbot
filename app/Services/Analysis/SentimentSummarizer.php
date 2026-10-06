<?php

namespace App\Services\Analysis;

use App\Models\ArticleSummary;
use App\Models\ModerationRun;
use Illuminate\Support\Facades\Log;

/**
 * Combines the per-article summaries stored in the run's window into the
 * two-sentence "Sfeer en sentiment" paragraph at the top of the report.
 */
class SentimentSummarizer
{
    public function __construct(private PromptRepository $prompts) {}

    /**
     * @param  array<string, int>  $categoryCounts  category label => count
     */
    public function summarize(ModerationRun $run, array $categoryCounts): ?string
    {
        $summaries = ArticleSummary::with('article')
            // Includes the summaries this run just created (after window_end).
            ->where('created_at', '>=', $run->window_start)
            ->where('created_at', '<=', now())
            ->orderBy('id')
            ->get()
            ->groupBy('article_id');

        if ($summaries->isEmpty()) {
            return null;
        }

        $input = json_encode([
            'articles' => $summaries->map(fn ($items) => [
                'title' => $items->first()->article?->title,
                'comments' => $items->sum('comments_count'),
                'summary' => $items->pluck('summary')->implode(' '),
            ])->values(),
            'flagged_per_category' => $categoryCounts,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        try {
            $result = app(LanguageModel::class)->complete($this->prompts->get('summary'), $input);
        } catch (\Throwable $e) {
            Log::error('Summarising sentiment failed', ['category' => 'run', 'error' => $e->getMessage()]);

            return null;
        }

        $run->increment('input_tokens', $result->inputTokens);
        $run->increment('output_tokens', $result->outputTokens);

        return $result->text;
    }
}
