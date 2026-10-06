<?php

namespace App\Models;

use App\Services\RunOptions;
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
        ];
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
     * Approximate cost in USD, from the token counts and the price table in
     * config/modbot.php. Cached input tokens are counted at the full rate.
     */
    public function estimatedCost(): ?float
    {
        $prices = config("modbot.llm.prices.{$this->model}");

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
