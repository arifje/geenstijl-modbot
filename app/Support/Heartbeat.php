<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * "Last seen" times of the background processes, for System → Queue.
 *
 *   scheduler  every minute, when schedule:run starts a task
 *   worker     each time the queue worker looks for or starts a job
 */
class Heartbeat
{
    /** Seen less than this many minutes ago counts as running. */
    public const ALIVE_MINUTES = 3;

    /** At most one write per this many seconds per process (an idle worker loops every 3 s). */
    public const THROTTLE_SECONDS = 30;

    /** @var array<string, int> */
    private static array $written = [];

    public static function beat(string $name): void
    {
        $now = now()->getTimestamp();

        if ($now - (self::$written[$name] ?? 0) < self::THROTTLE_SECONDS) {
            return;
        }

        self::$written[$name] = $now;

        // Never let bookkeeping break the scheduler or a job.
        rescue(fn () => Cache::forever("heartbeat:{$name}", $now), report: false);
    }

    /**
     * The next beat is written again (tests, which travel in time).
     */
    public static function forgetThrottle(): void
    {
        self::$written = [];
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
