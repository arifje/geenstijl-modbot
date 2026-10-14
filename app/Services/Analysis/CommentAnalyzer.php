<?php

namespace App\Services\Analysis;

use App\Enums\Category;
use App\Enums\Priority;
use App\Models\Article;
use App\Models\ArticleSummary;
use App\Models\Comment;
use App\Models\CommentVerdict;
use App\Models\ModerationRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends new comments to the language model, per article, and stores the
 * flags it returns as verdicts.
 */
class CommentAnalyzer
{
    /** Batches are not split below this many comments. */
    private const MIN_BATCH = 10;

    public function __construct(private PromptRepository $prompts) {}

    /**
     * @param  Collection<int, Comment>  $comments  comments not analysed yet
     * @return array{analyzed: int, failed: int, flagged: int}
     */
    public function analyze(ModerationRun $run, Collection $comments): array
    {
        $stats = ['analyzed' => 0, 'failed' => 0, 'flagged' => 0];

        if ($comments->isEmpty()) {
            return $stats;
        }

        $system = $this->prompts->get('moderation');
        // Resolved here (not injected) so a misconfigured provider fails the run
        // instead of the command, and a provider switched in the admin is used.
        $model = app(LanguageModel::class);

        $comments->loadMissing('article', 'parent');

        foreach ($comments->groupBy('article_id') as $articleComments) {
            /** @var Article $article */
            $article = $articleComments->first()->article;

            foreach ($articleComments->sortBy('posted_at')->chunk(max(1, (int) config('modbot.llm.batch_size'))) as $chunk) {
                $this->analyzeBatch($run, $article, $chunk->values(), $model, $system, $stats);
            }
        }

        return $stats;
    }

    /**
     * Analyses one batch of comments of an article. When the answer does not
     * fit in the max output tokens, the batch is split in two and each half is
     * analysed on its own, down to MIN_BATCH comments.
     *
     * @param  Collection<int, Comment>  $batch
     * @param  array{analyzed: int, failed: int, flagged: int}  $stats
     */
    private function analyzeBatch(ModerationRun $run, Article $article, Collection $batch, LanguageModel $model, string $system, array &$stats): void
    {
        try {
            $result = $model->complete($system, $this->buildInput($article, $batch), $this->schema());
            $data = $this->validated($result->json());
        } catch (LlmOutputTooLong $e) {
            if ($batch->count() >= 2 * self::MIN_BATCH) {
                Log::info("Answer for {$batch->count()} comments of article {$article->id} hit the max output tokens; splitting the batch in two", ['category' => 'run']);

                foreach ($batch->split(2) as $half) {
                    $this->analyzeBatch($run, $article, $half->values(), $model, $system, $stats);
                }

                return;
            }

            Log::error('Analysing comments failed', ['article' => $article->id, 'error' => $e->getMessage().'. Raise "Max output tokens" under Settings.']);
            $stats['failed'] += $batch->count();

            return;
        } catch (\Throwable $e) {
            Log::error('Analysing comments failed', ['article' => $article->id, 'error' => $e->getMessage()]);
            $stats['failed'] += $batch->count();

            return;
        }

        $flagged = $this->store($run, $batch, $data['flags'] ?? [], $result->model);

        $run->increment('input_tokens', $result->inputTokens);
        $run->increment('output_tokens', $result->outputTokens);
        $run->model = $result->model;

        $stats['analyzed'] += $batch->count();
        $stats['flagged'] += $flagged;

        if (filled($data['summary'] ?? null)) {
            ArticleSummary::create([
                'article_id' => $article->id,
                'moderation_run_id' => $run->id,
                'summary' => trim($data['summary']),
                'comments_count' => $batch->count(),
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @param  Collection<int, Comment>  $chunk
     * @param  list<array<string, mixed>>  $flags
     */
    private function store(ModerationRun $run, Collection $chunk, array $flags, string $model): int
    {
        $byId = $chunk->keyBy('id');
        $count = 0;

        DB::transaction(function () use ($run, $chunk, $flags, $model, $byId, &$count) {
            $flaggedIds = [];

            foreach ($flags as $flag) {
                $comment = $byId->get($flag['comment_id'] ?? null);
                $priority = Priority::tryFrom($flag['priority'] ?? '');
                $category = Category::tryFrom($flag['category'] ?? '');

                // Ignore ids the model invented, context comments it flagged anyway,
                // and a second flag for the same comment.
                if ($comment === null || $priority === null || $category === null || isset($flaggedIds[$comment->id])) {
                    Log::warning('Ignoring invalid flag', ['flag' => $flag]);

                    continue;
                }

                CommentVerdict::updateOrCreate(['comment_id' => $comment->id], [
                    'moderation_run_id' => $run->id,
                    'priority' => $priority,
                    'category' => $category,
                    'reason' => Str::limit(trim($flag['reason'] ?? ''), 500),
                    'quote' => $this->verifiedQuote((string) ($flag['quote'] ?? ''), $comment->content),
                    'model' => $model,
                    'prompt_version' => $this->prompts->version('moderation'),
                ]);
                $flaggedIds[$comment->id] = true;
                $count++;
            }

            // Re-analysis: comments in this batch that are no longer flagged lose
            // their earlier flag. Only reached when the batch succeeded.
            CommentVerdict::whereIn('comment_id', $chunk->pluck('id'))
                ->whereNotIn('comment_id', array_keys($flaggedIds))
                ->delete();

            Comment::whereIn('id', $chunk->pluck('id'))->update([
                'analyzed_at' => now(),
                'analysis_run_id' => $run->id,
            ]);
        });

        return $count;
    }

    /**
     * Structured output guarantees this shape for Anthropic and OpenAI, but not
     * for every OpenAI-compatible provider. An unusable response must fail the
     * batch, not mark its comments as reviewed.
     *
     * @return array{summary?: string, flags: list<array<string, mixed>>}
     *
     * @throws LlmException
     */
    private function validated(mixed $data): array
    {
        if (! is_array($data) || ! array_key_exists('flags', $data) || ! is_array($data['flags']) || ! array_is_list($data['flags'])) {
            throw new LlmException('The model response has no valid "flags" list.');
        }

        foreach ($data['flags'] as $flag) {
            if (! is_array($flag) || ! is_string($flag['comment_id'] ?? null)) {
                throw new LlmException('The model response contains a flag without a comment_id.');
            }
        }

        if (isset($data['summary']) && ! is_string($data['summary'])) {
            throw new LlmException('The model response has an invalid "summary".');
        }

        return $data;
    }

    /**
     * @param  Collection<int, Comment>  $chunk
     */
    private function buildInput(Article $article, Collection $chunk): string
    {
        $ids = $chunk->pluck('id')->flip();

        $context = $chunk
            ->map->parent
            ->filter(fn (?Comment $parent) => $parent !== null && ! $ids->has($parent->id))
            ->unique('id')
            ->map(fn (Comment $c) => $this->present($c))
            ->values();

        return json_encode([
            'article' => ['title' => $article->title],
            'context' => $context,
            'comments' => $chunk->map(fn (Comment $c) => $this->present($c))->values(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'author' => $comment->author_username ?? 'Onbekende gebruiker',
            'date' => $comment->posted_at->timezone(config('modbot.geenstijl.timezone'))->format('d/m H:i'),
            'parent_id' => $comment->parent_id,
            'content' => $comment->content,
        ];
    }

    /**
     * The quote must come from the comment itself. When the model paraphrased,
     * fall back to the start of the comment.
     */
    public function verifiedQuote(string $quote, string $content): string
    {
        $normalize = fn (string $s) => Str::lower(preg_replace('/\s+/u', ' ', trim($s)));
        $haystack = $normalize($content);

        $segments = collect(preg_split('/\.\.\.|…|\[afgeschermd\]/u', $quote))
            ->map(fn (string $s) => trim($normalize($s), " \"'“”"))
            ->filter();

        $valid = $segments->isNotEmpty()
            && $segments->every(fn (string $s) => str_contains($haystack, $s));

        // Never fall back to the raw comment when the model masked private data.
        $masked = str_contains($quote, '[afgeschermd]');

        $quote = $valid || $masked ? $quote : $content;

        return Str::limit(preg_replace('/\s+/u', ' ', trim($quote)), 160, '...');
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'flags' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'comment_id' => ['type' => 'string'],
                            'priority' => ['type' => 'string', 'enum' => array_column(Priority::cases(), 'value')],
                            'category' => ['type' => 'string', 'enum' => array_column(Category::cases(), 'value')],
                            'reason' => ['type' => 'string'],
                            'quote' => ['type' => 'string'],
                        ],
                        'required' => ['comment_id', 'priority', 'category', 'reason', 'quote'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['summary', 'flags'],
            'additionalProperties' => false,
        ];
    }
}
