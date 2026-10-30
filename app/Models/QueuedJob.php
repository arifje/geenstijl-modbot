<?php

namespace App\Models;

use App\Jobs\RunModerationJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A job waiting in (or being processed from) the database queue.
 */
class QueuedJob extends Model
{
    protected $table = 'jobs';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function displayName(): string
    {
        return class_basename($this->payload['displayName'] ?? 'Unknown job');
    }

    /** The moderation run this job belongs to, if it is a RunModerationJob. */
    public function runId(): ?int
    {
        return self::runIdFromPayload($this->payload ?? []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function runIdFromPayload(array $payload): ?int
    {
        if (($payload['displayName'] ?? null) !== RunModerationJob::class) {
            return null;
        }

        // Read from the serialized job without unserializing it.
        return preg_match('/s:5:"runId";i:(\d+);/', (string) ($payload['data']['command'] ?? ''), $m) ? (int) $m[1] : null;
    }

    public function status(): string
    {
        return match (true) {
            $this->reserved_at !== null => 'running',
            $this->available_at > now()->getTimestamp() => 'delayed',
            default => 'waiting',
        };
    }

    public function queuedAt(): Carbon
    {
        return Carbon::createFromTimestamp($this->created_at);
    }

    public function startedAt(): ?Carbon
    {
        return $this->reserved_at ? Carbon::createFromTimestamp($this->reserved_at) : null;
    }
}
