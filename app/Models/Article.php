<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Article extends Model
{
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'comments_synced_at' => 'datetime',
            'comments_closed' => 'boolean',
        ];
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function verdicts(): HasManyThrough
    {
        return $this->hasManyThrough(CommentVerdict::class, Comment::class, 'article_id', 'comment_id');
    }

    public function url(): string
    {
        return rtrim(config('modbot.geenstijl.site_url'), '/')."/{$this->id}/{$this->slug}";
    }
}
