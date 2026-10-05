<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModerationRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'window_start' => 'datetime',
            'window_end' => 'datetime',
        ];
    }

    public function verdicts(): HasMany
    {
        return $this->hasMany(CommentVerdict::class);
    }
}
