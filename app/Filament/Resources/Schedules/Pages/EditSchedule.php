<?php

namespace App\Filament\Resources\Schedules\Pages;

use App\Filament\Resources\Schedules\ScheduleResource;
use App\Support\ScheduleFrequency;
use Filament\Resources\Pages\EditRecord;

class EditSchedule extends EditRecord
{
    protected static string $resource = ScheduleResource::class;

    /**
     * Opens "0 8,20 * * *" as "Every day at 08:00 and 20:00"; anything else as Advanced.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, ...ScheduleResource::frequencyDefaults(), ...ScheduleFrequency::fromCron($data['cron'])];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ScheduleResource::withCron($data);
    }
}
