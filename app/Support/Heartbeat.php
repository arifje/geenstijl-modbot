<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * "Last seen" times of the background processes, for System → Queue.
 *
 *   scheduler  every minute, when schedule:run starts or skips a task
 *   worker     each time a queue worker looks for or starts a job
 */
class Heartbeat
{
    /** Seen less than this many minutes ago counts as running. */
    public const ALIVE_MINUTES = 3;

    public static function beat(string $name): void
    {
        // Never let bookkeeping break the scheduler or a job.
        rescue(fn () => Cache::forever("heartbeat:{$name}", now()->getTimestamp()), report: false);
    }

    public static function lastSeen(string $name): ?Carbon
    {
        $timestamp = rescue(fn () => Cache::get("heartbeat:{$name}"), report: false);

        return $timestamp ? Carbon::createFromTimestamp($timestamp) : null;
    }

    public static function alive(string $name): bool
    {
        return (bool) self::lastSeen($name)?->gt(now()->subMinutes(self::ALIVE_MINUTES));
    }
}
