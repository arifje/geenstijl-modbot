<?php

namespace App\Services\Reporting;

use App\Models\Article;
use App\Models\CommentVerdict;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final readonly class Report
{
    /**
     * @param  Collection<int, CommentVerdict>  $items  sorted per article, with comment.article loaded
     */
    public function __construct(
        public int $windowHours,
        public ?int $articleWindowHours,
        public Carbon $windowEnd,
        public int $articlesCount,
        public int $commentsCount,
        public int $failedCount,
        public int $syncFailedCount,
        public Collection $items,
        public ?string $sentiment,
    ) {}

    public function title(): string
    {
        return "Moderatierapport reacties — laatste {$this->windowHours} uur";
    }

    /**
     * e.g. "Reacties van de laatste 12 uur op artikelen van de laatste 24 uur".
     */
    public function scope(): string
    {
        $comments = "Reacties van de laatste {$this->windowHours} uur";

        return $this->articleWindowHours === null
            ? $comments
            : "{$comments} op artikelen van de laatste {$this->articleWindowHours} uur";
    }

    /**
     * @return Collection<int, CommentVerdict>
     */
    public function shownItems(): Collection
    {
        return $this->items->take(config('modbot.report.max_items'));
    }

    /**
     * Shown items grouped per article, in report order.
     *
     * @return Collection<int, array{article: Article, items: Collection<int, CommentVerdict>}>
     */
    public function groups(): Collection
    {
        return $this->shownItems()
            ->groupBy(fn (CommentVerdict $v) => $v->comment->article_id)
            ->map(fn (Collection $items) => ['article' => $items->first()->comment->article, 'items' => $items->values()])
            ->values();
    }

    public function hiddenCount(): int
    {
        return max(0, $this->items->count() - $this->shownItems()->count());
    }
}
