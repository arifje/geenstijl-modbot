<?php

namespace App\Models;

use App\Jobs\RunModerationJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A job that failed; kept until it is retried or deleted (or pruned).
 */
class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'failed_at' => 'datetime',
        ];
    }

    public function displayName(): string
    {
        return class_basename($this->payload['displayName'] ?? 'Unknown job');
    }

    public function runId(): ?int
    {
        return QueuedJob::runIdFromPayload($this->payload ?? []);
    }

    /**
     * Moderation runs are retried from the run itself (Runs → Retry), which
     * starts a new run; retrying the old job would skip the failed run.
     */
    public function retryable(): bool
    {
        return ($this->payload['displayName'] ?? null) !== RunModerationJob::class;
    }

    public function errorSummary(): string
    {
        return Str::limit(trim(Str::before((string) $this->exception, "\n")), 200);
    }
}
