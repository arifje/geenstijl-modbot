<?php

namespace App\Services\GeenStijl;

use App\Models\Article;
use App\Models\Comment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors recent articles and their comments into the database.
 */
class CommentSync
{
    public function __construct(private GeenStijlClient $client) {}

    /**
     * @return array{articles: int, comments: int}
     */
    public function sync(Carbon $articlesSince, Carbon $commentsSince): array
    {
        $articles = $this->client->articlesSince($articlesSince);
        $commentCount = 0;

        foreach ($articles as $data) {
            $article = Article::updateOrCreate(['id' => $data['id']], [
                'slug' => $data['slug'],
                'title' => $data['title'],
                'published_at' => $this->client->parseDate($data['publishedAt']),
                'comments_count' => $data['commentsCount'] ?? 0,
            ]);

            if ($article->comments_count === 0) {
                continue;
            }

            // Closed articles are synced too, so status changes made by
            // moderators after closing (e.g. removals) are picked up.
            $commentCount += $this->syncComments($article, $commentsSince);
        }

        return ['articles' => count($articles), 'comments' => $commentCount];
    }

    private function syncComments(Article $article, Carbon $since): int
    {
        try {
            $response = $this->client->comments($article->id);
        } catch (\Throwable $e) {
            Log::warning('Fetching comments failed', ['article' => $article->id, 'error' => $e->getMessage()]);

            return 0;
        }

        $now = now();
        $rows = collect($response['data'])
            ->map(fn (array $c) => [
                'id' => $c['id'],
                'article_id' => $article->id,
                'parent_id' => $c['parentId'] ?? null,
                'author_id' => isset($c['author']['id']) ? (string) $c['author']['id'] : null,
                'author_username' => $c['author']['username'] ?? null,
                'author_premium' => (bool) ($c['author']['premium'] ?? false),
                'author_newbie' => (bool) ($c['author']['newbie'] ?? false),
                'content' => (string) ($c['content'] ?? ''),
                'posted_at' => $this->client->parseDate($c['createdAt']),
                'status' => $c['status'] ?? 'unknown',
                'approved' => (bool) ($c['approved'] ?? false),
                'kudos' => (int) ($c['kudos'] ?? 0),
                'reports' => (int) ($c['reports'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        // Keep the window plus parents of comments in the window, so the
        // analysis has thread context.
        $inWindow = $rows->filter(fn (array $r) => $r['posted_at']->gte($since));
        $parentIds = $inWindow->pluck('parent_id')->filter()->flip();
        $keep = $rows->filter(fn (array $r) => $r['posted_at']->gte($since) || $parentIds->has($r['id']));

        DB::transaction(function () use ($keep, $article, $response, $now) {
            foreach ($keep->chunk(200) as $chunk) {
                Comment::upsert(
                    $chunk->values()->all(),
                    ['id'],
                    ['parent_id', 'author_username', 'author_premium', 'author_newbie', 'content', 'status', 'approved', 'kudos', 'reports', 'updated_at'],
                );
            }

            $article->forceFill([
                'comments_closed' => $response['closed'],
                'comments_synced_at' => $now,
            ])->save();
        });

        return $keep->count();
    }
}
