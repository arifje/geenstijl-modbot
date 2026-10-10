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
    /** The report shows a short recap: two sentences, at most 30 words. */
    public const MAX_WORDS = 30;

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
            ->when($run->article_window_start, fn ($q, $from) => $q->whereHas('article', fn ($a) => $a->where('published_at', '>=', $from)))
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
            $model = app(LanguageModel::class);
            $result = $model->complete($this->prompts->get('summary'), $input);
            $this->countTokens($run, $result);
            $text = self::normalize($result->text);

            // Smaller models sometimes list a summary per article instead of
            // one recap. Ask once more, with the length of the first answer.
            if (self::words($text) > self::MAX_WORDS) {
                $words = self::words($text);
                $result = $model->complete($this->prompts->get('summary'), $input
                    ."\n\nJe vorige antwoord was {$words} woorden en vatte per artikel samen. Geef één samenvatting voor alle artikelen samen: twee zinnen, samen maximaal ".self::MAX_WORDS.' woorden.');
                $this->countTokens($run, $result);
                $text = self::normalize($result->text);
            }
        } catch (\Throwable $e) {
            Log::error('Summarising sentiment failed', ['category' => 'run', 'error' => $e->getMessage()]);

            return null;
        }

        if (self::words($text) > self::MAX_WORDS) {
            Log::warning('Sentiment summary too long; shortened', ['category' => 'run', 'words' => self::words($text)]);
            $text = self::shorten($text);
        }

        return $text;
    }

    /**
     * One paragraph: no line breaks, headings or surrounding quotes.
     */
    public static function normalize(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text));

        return trim($text, " \"'“”");
    }

    public static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }

    /**
     * Whole sentences up to MAX_WORDS; a single overlong sentence is cut off.
     */
    public static function shorten(string $text): string
    {
        $kept = '';

        foreach (preg_split('/(?<=[.!?])\s+/u', $text) as $sentence) {
            if (self::words(trim($kept.' '.$sentence)) > self::MAX_WORDS) {
                break;
            }

            $kept = trim($kept.' '.$sentence);
        }

        if ($kept !== '') {
            return $kept;
        }

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return rtrim(implode(' ', array_slice($words, 0, self::MAX_WORDS)), ',;:').'…';
    }

    private function countTokens(ModerationRun $run, LlmResult $result): void
    {
        $run->increment('input_tokens', $result->inputTokens);
        $run->increment('output_tokens', $result->outputTokens);
    }
}
