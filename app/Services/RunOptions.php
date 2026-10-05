<?php

namespace App\Services;

use App\Models\Schedule;

final readonly class RunOptions
{
    public function __construct(
        public int $windowHours,
        public bool $sendSlack = true,
        public bool $sendEmail = true,
        public ?string $slackChannel = null,
        public bool $dryRun = false,
        public bool $reanalyze = false,
        public string $trigger = 'cli',
        public ?int $scheduleId = null,
        public ?int $userId = null,
    ) {}

    public static function fromSchedule(Schedule $schedule): self
    {
        return new self(
            windowHours: $schedule->window_hours,
            sendSlack: $schedule->send_slack,
            sendEmail: $schedule->send_email,
            slackChannel: $schedule->slack_channel ?: null,
            trigger: 'schedule',
            scheduleId: $schedule->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
