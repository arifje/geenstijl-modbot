<?php

namespace App\Services\Analysis;

use App\Enums\Category;
use App\Enums\Priority;
use App\Models\Article;
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
    public function __construct(
        private LanguageModel $model,
        private PromptRepository $prompts,
    ) {}

    /**
     * @param  Collection<int, Comment>  $comments  comments not analysed yet
     * @return array{analyzed: int, failed: int, flagged: int, summaries: array<int, string>}
     */
    public function analyze(ModerationRun $run, Collection $comments): array
    {
        $stats = ['analyzed' => 0, 'failed' => 0, 'flagged' => 0, 'summaries' => []];
        $system = $this->prompts->get('moderation');

        $comments->loadMissing('article', 'parent');

        foreach ($comments->groupBy('article_id') as $articleComments) {
            /** @var Article $article */
            $article = $articleComments->first()->article;

            foreach ($articleComments->sortBy('posted_at')->chunk(config('modbot.llm.batch_size')) as $chunk) {
                try {
                    $result = $this->model->complete($system, $this->buildInput($article, $chunk), $this->schema());
                    $data = $result->json();
                } catch (\Throwable $e) {
                    Log::error('Analysing comments failed', ['article' => $article->id, 'error' => $e->getMessage()]);
                    $stats['failed'] += $chunk->count();

                    continue;
                }

                $flagged = $this->store($run, $chunk, $data['flags'] ?? [], $result->model);

                $run->increment('input_tokens', $result->inputTokens);
                $run->increment('output_tokens', $result->outputTokens);
                $run->model = $result->model;

                $stats['analyzed'] += $chunk->count();
                $stats['flagged'] += $flagged;

                if (filled($data['summary'] ?? null)) {
                    $stats['summaries'][$article->id] = trim(($stats['summaries'][$article->id] ?? '').' '.$data['summary']);
                }
            }
        }

        return $stats;
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
            foreach ($flags as $flag) {
                $comment = $byId->get($flag['comment_id'] ?? null);
                $priority = Priority::tryFrom($flag['priority'] ?? '');
                $category = Category::tryFrom($flag['category'] ?? '');

                // Ignore ids the model invented or context comments it flagged anyway.
                if ($comment === null || $priority === null || $category === null) {
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
                $count++;
            }

            Comment::whereIn('id', $chunk->pluck('id'))->update([
                'analyzed_at' => now(),
                'analysis_run_id' => $run->id,
            ]);
        });

        return $count;
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
