<?php

namespace App\Services\Reporting;

use App\Models\Comment;
use App\Models\CommentVerdict;
use App\Models\ModerationRun;
use Illuminate\Support\Collection;

/**
 * Builds the report from what is stored in the database, so it also covers
 * comments that were analysed in earlier runs inside the same window.
 */
class ReportBuilder
{
    public function build(ModerationRun $run): Report
    {
        $from = $run->window_start;
        $to = $run->window_end;

        $comments = Comment::moderatable($from, $to);

        $items = CommentVerdict::query()
            ->whereHas('comment', fn ($q) => $q->moderatable($from, $to))
            ->with('comment.article')
            ->get()
            ->sortBy([
                fn (CommentVerdict $a, CommentVerdict $b) => $a->priority->rank() <=> $b->priority->rank(),
                fn (CommentVerdict $a, CommentVerdict $b) => $b->comment->posted_at <=> $a->comment->posted_at,
            ])
            // Group per article: articles with the most severe flags first,
            // then the most flags, then the newest article.
            ->groupBy(fn (CommentVerdict $v) => $v->comment->article_id)
            ->sortBy([
                fn (Collection $a, Collection $b) => $a->first()->priority->rank() <=> $b->first()->priority->rank(),
                fn (Collection $a, Collection $b) => $b->count() <=> $a->count(),
                fn (Collection $a, Collection $b) => $b->first()->comment->article->published_at <=> $a->first()->comment->article->published_at,
            ])
            ->flatten(1)
            ->values();

        return new Report(
            windowHours: (int) round($from->diffInHours($to)),
            windowEnd: $to,
            articlesCount: (clone $comments)->distinct()->count('article_id'),
            commentsCount: (clone $comments)->count(),
            failedCount: $run->failed_count,
            syncFailedCount: $run->sync_failed_count,
            items: $items,
            sentiment: $run->sentiment,
        );
    }
}
