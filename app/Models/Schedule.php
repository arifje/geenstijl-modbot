<?php

namespace App\Models;

use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Schedule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'send_slack' => 'boolean',
            'send_email' => 'boolean',
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ModerationRun::class);
    }

    public function nextRunAt(): ?Carbon
    {
        if (! $this->enabled || ! CronExpression::isValidExpression($this->cron)) {
            return null;
        }

        return Carbon::instance(
            (new CronExpression($this->cron))->getNextRunDate(now($this->timezone)->toDateTime(), 0, false, $this->timezone)
        );
    }
}
