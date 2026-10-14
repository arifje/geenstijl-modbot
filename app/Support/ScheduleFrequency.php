<?php

namespace App\Support;

use Cron\CronExpression;

/**
 * Translates between the schedule form ("every 15 minutes", "daily at 08:00
 * and 20:00", ...) and the cron expression the scheduler stores and runs.
 * Expressions that don't match one of the simple forms are kept as "cron".
 */
class ScheduleFrequency
{
    public const MINUTES = [5, 10, 15, 30];

    public const HOURS = [1, 2, 3, 4, 6, 8, 12];

    /** cron day number => label, Monday first */
    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'];

    /**
     * @param  array{frequency: string, every_minutes?: int|string, every_hours?: int|string, at_minute?: int|string|null, times?: list<string>, days?: list<int|string>, cron?: string}  $data
     */
    public static function toCron(array $data): string
    {
        return match ($data['frequency']) {
            'minutes' => '*/'.(int) $data['every_minutes'].' * * * *',
            'hours' => (int) ($data['at_minute'] ?? 0).' '.((int) $data['every_hours'] === 1 ? '*' : '*/'.(int) $data['every_hours']).' * * *',
            'daily' => self::timesToCron($data['times'] ?? []).' * * *',
            'weekly' => self::timesToCron($data['times'] ?? []).' * * '.self::daysToCron($data['days'] ?? []),
            default => trim((string) ($data['cron'] ?? '')),
        };
    }

    /**
     * Form fields for a cron expression; anything unusual becomes "cron".
     *
     * @return array<string, mixed>
     */
    public static function fromCron(string $cron): array
    {
        $parts = preg_split('/\s+/', trim($cron));
        $advanced = ['frequency' => 'cron', 'cron' => trim($cron)];

        if (count($parts) !== 5) {
            return $advanced;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;

        if ($dayOfMonth !== '*' || $month !== '*') {
            return $advanced;
        }

        // */15 * * * *
        if ($dayOfWeek === '*' && $hour === '*' && preg_match('#^\*/(\d+)$#', $minute, $m) && in_array((int) $m[1], self::MINUTES, true)) {
            return ['frequency' => 'minutes', 'every_minutes' => (int) $m[1]];
        }

        if (! ctype_digit($minute) || (int) $minute > 59) {
            return $advanced;
        }

        // 0 * * * *  /  0 */6 * * *
        if ($dayOfWeek === '*' && ($hour === '*' || preg_match('#^\*/(\d+)$#', $hour, $h))) {
            $every = $hour === '*' ? 1 : (int) $h[1];

            return in_array($every, self::HOURS, true)
                ? ['frequency' => 'hours', 'every_hours' => $every, 'at_minute' => (int) $minute]
                : $advanced;
        }

        // 0 8,20 * * *  /  30 7 * * 1-5
        $hours = self::expand($hour, 0, 23);
        if ($hours === null) {
            return $advanced;
        }

        $times = array_map(fn (int $h) => sprintf('%02d:%02d', $h, (int) $minute), $hours);

        if ($dayOfWeek === '*') {
            return ['frequency' => 'daily', 'times' => $times];
        }

        $days = self::expand($dayOfWeek, 0, 7);
        if ($days === null) {
            return $advanced;
        }

        $days = array_values(array_unique(array_map(fn (int $d) => $d % 7, $days)));

        return ['frequency' => 'weekly', 'times' => $times, 'days' => $days];
    }

    /**
     * "Every 15 minutes", "Every day at 08:00 and 20:00", ...
     */
    public static function describe(string $cron): string
    {
        $data = self::fromCron($cron);

        return match ($data['frequency']) {
            'minutes' => "Every {$data['every_minutes']} minutes",
            'hours' => ($data['every_hours'] === 1 ? 'Every hour' : "Every {$data['every_hours']} hours")
                .sprintf(' at :%02d', $data['at_minute']),
            'daily' => 'Every day at '.self::list($data['times']),
            'weekly' => self::describeDays($data['days']).' at '.self::list($data['times']),
            default => "Custom: {$data['cron']}",
        };
    }

    /**
     * Minutes after midnight of the first run on a day, for sorting schedules
     * by time of day (every 15 minutes = 0, daily at 08:00 = 480).
     */
    public static function firstMinuteOfDay(string $cron): int
    {
        if (! CronExpression::isValidExpression($cron)) {
            return PHP_INT_MAX;
        }

        // From a Monday at midnight: the first run that week, at its time of day.
        $next = (new CronExpression($cron))->getNextRunDate(new \DateTimeImmutable('2026-01-05 00:00:00'), 0, true);

        return (int) $next->format('G') * 60 + (int) $next->format('i');
    }

    /**
     * Validation message when the times cannot be expressed in one schedule, or null.
     *
     * @param  list<string>  $times
     */
    public static function timesError(array $times): ?string
    {
        if ($times === []) {
            return 'Add at least one time, e.g. 08:00.';
        }

        foreach ($times as $time) {
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                return "\"{$time}\" is not a valid time; use HH:MM, e.g. 08:00.";
            }
        }

        if (count(array_unique(array_map(fn (string $t) => substr($t, 3), $times))) > 1) {
            return 'All times must have the same minutes (e.g. 08:00 and 20:00). For other combinations, use two schedules.';
        }

        return null;
    }

    /**
     * @param  list<string>  $times  HH:MM, all with the same minutes (see timesError)
     */
    private static function timesToCron(array $times): string
    {
        sort($times);
        $minute = (int) substr($times[0] ?? '00:00', 3, 2);
        $hours = array_map(fn (string $t) => (int) substr($t, 0, 2), $times);

        return $minute.' '.implode(',', array_unique($hours));
    }

    /**
     * @param  list<int|string>  $days
     */
    private static function daysToCron(array $days): string
    {
        $days = array_map('intval', $days);
        sort($days);

        return $days === [] ? '*' : implode(',', array_unique($days));
    }

    /**
     * "8,20" / "1-5" / "7" → [8, 20] / [1, 2, 3, 4, 5] / [7]; null when not a plain list or range.
     *
     * @return list<int>|null
     */
    private static function expand(string $field, int $min, int $max): ?array
    {
        $values = [];

        foreach (explode(',', $field) as $part) {
            if (preg_match('/^(\d+)-(\d+)$/', $part, $m) && (int) $m[1] <= (int) $m[2]) {
                $values = [...$values, ...range((int) $m[1], (int) $m[2])];
            } elseif (ctype_digit($part)) {
                $values[] = (int) $part;
            } else {
                return null;
            }
        }

        foreach ($values as $value) {
            if ($value < $min || $value > $max) {
                return null;
            }
        }

        sort($values);

        return array_values(array_unique($values));
    }

    /**
     * @param  list<int>  $days
     */
    private static function describeDays(array $days): string
    {
        sort($days);

        return match ($days) {
            [1, 2, 3, 4, 5] => 'Weekdays',
            [0, 6] => 'Weekends',
            [0, 1, 2, 3, 4, 5, 6] => 'Every day',
            default => self::list(array_map(
                fn (int $d) => self::DAYS[$d],
                // Monday first, Sunday last
                array_merge(array_values(array_filter($days, fn (int $d) => $d !== 0)), in_array(0, $days, true) ? [0] : []),
            )),
        };
    }

    /**
     * @param  list<string>  $items
     */
    private static function list(array $items): string
    {
        return count($items) <= 1
            ? implode('', $items)
            : implode(', ', array_slice($items, 0, -1)).' and '.end($items);
    }
}
