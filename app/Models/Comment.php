<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Comment extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'analyzed_at' => 'datetime',
            'approved' => 'boolean',
            'author_premium' => 'boolean',
            'author_newbie' => 'boolean',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function verdict(): HasOne
    {
        return $this->hasOne(CommentVerdict::class);
    }

    /**
     * Comments that are in scope for moderation: inside the comment window,
     * optionally on articles published since $articlesFrom, and matching the
     * configured status/approval filters.
     */
    public function scopeModeratable(Builder $query, Carbon $from, Carbon $to, ?Carbon $articlesFrom = null): void
    {
        $query->whereBetween('posted_at', [$from, $to])
            ->when($articlesFrom, fn (Builder $q) => $q->whereHas('article', fn (Builder $a) => $a->where('published_at', '>=', $articlesFrom)))
            ->whereIn('status', config('modbot.statuses'))
            ->when(config('modbot.approval_filter') === 'unapproved', fn (Builder $q) => $q->where('approved', false))
            ->when(config('modbot.approval_filter') === 'approved', fn (Builder $q) => $q->where('approved', true));
    }

    /**
     * Link to the comment on the frontend, where moderators edit it.
     */
    public function url(): string
    {
        return $this->article->url().'#c'.$this->id;
    }

    public function displayName(): string
    {
        return $this->author_username ?: $this->id;
    }
}
