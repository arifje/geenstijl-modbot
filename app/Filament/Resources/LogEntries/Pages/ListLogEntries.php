<?php

namespace App\Filament\Resources\LogEntries\Pages;

use App\Filament\Resources\LogEntries\LogEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListLogEntries extends ListRecords
{
    protected static string $resource = LogEntryResource::class;

    protected ?string $subheading = 'Application, queue, scheduler and run logs from all containers. Kept for 14 days.';
}
