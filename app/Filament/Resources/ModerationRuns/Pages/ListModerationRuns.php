<?php

namespace App\Filament\Resources\ModerationRuns\Pages;

use App\Filament\Actions\RunNowAction;
use App\Filament\Resources\ModerationRuns\ModerationRunResource;
use Filament\Resources\Pages\ListRecords;

class ListModerationRuns extends ListRecords
{
    protected static string $resource = ModerationRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RunNowAction::make(),
        ];
    }
}
