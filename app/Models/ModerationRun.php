<?php

namespace App\Models;

use App\Services\RunOptions;
use App\Support\ModelPrices;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
            'article_window_start' => 'datetime',
            'dry_run' => 'boolean',
        ];
    }

    /**
     * Create the record for a run that will be started by the queue worker,
     * so it shows up in the admin right away.
     */
    public static function queue(RunOptions $options): self
    {
        return self::create([
            'status' => 'queued',
            ...self::attributesFor($options),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function attributesFor(RunOptions $options): array
    {
        $now = now();

        return [
            'trigger' => $options->trigger,
            'provider' => config('modbot.llm.provider'),
            'schedule_id' => $options->scheduleId,
            'user_id' => $options->userId,
            'dry_run' => $options->dryRun,
            'started_at' => $now,
            'window_start' => $now->copy()->subHours($options->windowHours),
            'window_end' => $now,
            'article_window_start' => $now->copy()->subHours($options->articleHours()),
        ];
    }

    /**
     * Comments in scope for this run: posted in the comment window, on an
     * article published in the article window, matching the filters.
     */
    public function moderatableComments(): Builder
    {
        return Comment::moderatable($this->window_start, $this->window_end, $this->article_window_start);
    }

    public function articleWindowHours(): ?int
    {
        return $this->article_window_start ? (int) round($this->article_window_start->diffInHours($this->window_end)) : null;
    }

    public function commentWindowHours(): int
    {
        return (int) round($this->window_start->diffInHours($this->window_end));
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Approximate cost in USD, from the token counts and the model's price
     * (App\Support\ModelPrices). Cached input tokens are counted at the full
     * rate. Null when the model has no price.
     */
    public function estimatedCost(): ?float
    {
        $prices = ModelPrices::for($this->model);

        if ($prices === null) {
            return null;
        }

        return ($this->input_tokens * $prices[0] + $this->output_tokens * $prices[1]) / 1_000_000;
    }

    public function verdicts(): HasMany
    {
        return $this->hasMany(CommentVerdict::class);
    }
}
