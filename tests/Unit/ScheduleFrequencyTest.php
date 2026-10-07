<?php

namespace Tests\Unit;

use App\Support\ScheduleFrequency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScheduleFrequencyTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function schedules(): array
    {
        return [
            'every 15 minutes' => ['*/15 * * * *', ['frequency' => 'minutes', 'every_minutes' => 15], 'Every 15 minutes'],
            'every hour' => ['0 * * * *', ['frequency' => 'hours', 'every_hours' => 1, 'at_minute' => 0], 'Every hour at :00'],
            'every 6 hours' => ['30 */6 * * *', ['frequency' => 'hours', 'every_hours' => 6, 'at_minute' => 30], 'Every 6 hours at :30'],
            'twice a day' => ['0 8,20 * * *', ['frequency' => 'daily', 'times' => ['08:00', '20:00']], 'Every day at 08:00 and 20:00'],
            'weekdays' => ['30 7 * * 1,2,3,4,5', ['frequency' => 'weekly', 'times' => ['07:30'], 'days' => [1, 2, 3, 4, 5]], 'Weekdays at 07:30'],
            'weekend' => ['0 10 * * 0,6', ['frequency' => 'weekly', 'times' => ['10:00'], 'days' => [0, 6]], 'Weekends at 10:00'],
            'some days' => ['0 9,17 * * 0,1,3', ['frequency' => 'weekly', 'times' => ['09:00', '17:00'], 'days' => [0, 1, 3]], 'Mon, Wed and Sun at 09:00 and 17:00'],
        ];
    }

    #[DataProvider('schedules')]
    public function test_round_trip(string $cron, array $fields, string $description): void
    {
        $this->assertSame($fields, ScheduleFrequency::fromCron($cron));
        $this->assertSame($cron, ScheduleFrequency::toCron($fields));
        $this->assertSame($description, ScheduleFrequency::describe($cron));
    }

    public function test_ranges_and_sunday_as_7_are_recognised(): void
    {
        $this->assertSame(['frequency' => 'weekly', 'times' => ['07:30'], 'days' => [1, 2, 3, 4, 5]], ScheduleFrequency::fromCron('30 7 * * 1-5'));
        $this->assertSame('Sun at 10:00', ScheduleFrequency::describe('0 10 * * 7'));
    }

    public function test_unusual_expressions_stay_advanced(): void
    {
        foreach (['0 8 1 * *', '*/7 * * * *', '0 */5 * * *', '15,45 * * * *', '0 8-18/2 * * *', 'nonsense'] as $cron) {
            $this->assertSame(['frequency' => 'cron', 'cron' => $cron], ScheduleFrequency::fromCron($cron), $cron);
            $this->assertSame("Custom: {$cron}", ScheduleFrequency::describe($cron));
        }
    }

    public function test_times_must_share_their_minutes(): void
    {
        $this->assertNull(ScheduleFrequency::timesError(['08:00', '20:00']));
        $this->assertStringContainsString('same minutes', ScheduleFrequency::timesError(['08:00', '20:30']));
        $this->assertStringContainsString('not a valid time', ScheduleFrequency::timesError(['8 uur']));
        $this->assertStringContainsString('at least one', ScheduleFrequency::timesError([]));
    }
}
