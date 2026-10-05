<?php

namespace App\Models;

use App\Enums\Category;
use App\Enums\Priority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentVerdict extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'category' => Category::class,
        ];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ModerationRun::class, 'moderation_run_id');
    }
}
