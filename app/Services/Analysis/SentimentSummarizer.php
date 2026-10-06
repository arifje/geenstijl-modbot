<?php

namespace App\Services\Analysis;

use App\Models\Article;
use App\Models\ModerationRun;
use Illuminate\Support\Facades\Log;

/**
 * Combines the per-article summaries into the two-sentence "Sfeer en
 * sentiment" paragraph at the top of the report.
 */
class SentimentSummarizer
{
    public function __construct(private PromptRepository $prompts) {}

    /**
     * @param  array<int, string>  $summaries  article id => summary
     * @param  array<string, int>  $categoryCounts  category label => count
     */
    public function summarize(ModerationRun $run, array $summaries, array $categoryCounts): ?string
    {
        if ($summaries === []) {
            return null;
        }

        $titles = Article::whereIn('id', array_keys($summaries))->pluck('title', 'id');

        $input = json_encode([
            'articles' => collect($summaries)->map(fn (string $summary, int $id) => [
                'title' => $titles[$id] ?? null,
                'summary' => $summary,
            ])->values(),
            'flagged_per_category' => $categoryCounts,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        try {
            $result = app(LanguageModel::class)->complete($this->prompts->get('summary'), $input);
        } catch (\Throwable $e) {
            Log::error('Summarising sentiment failed', ['error' => $e->getMessage()]);

            return null;
        }

        $run->increment('input_tokens', $result->inputTokens);
        $run->increment('output_tokens', $result->outputTokens);

        return $result->text;
    }
}
