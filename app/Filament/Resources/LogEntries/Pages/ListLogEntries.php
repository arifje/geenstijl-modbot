<?php

namespace App\Filament\Resources\LogEntries\Pages;

use App\Filament\Resources\LogEntries\LogEntryResource;
use App\Models\LogEntry;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListLogEntries extends ListRecords
{
    protected static string $resource = LogEntryResource::class;

    public function getSubheading(): ?string
    {
        return 'Application, queue, scheduler and run logs from all containers. Kept for '
            .config('modbot.log_retention_days').' days (Settings).';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('clearLogs')
                ->label('Clear logs')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->schema([
                    Select::make('age')->label('Remove')->required()->default('all')
                        ->options([
                            'all' => 'All log entries',
                            '1' => 'Older than 1 day',
                            '7' => 'Older than 7 days',
                        ]),
                    Select::make('category')->label('Category')
                        ->placeholder('All categories')
                        ->options(LogEntryResource::CATEGORIES),
                ])
                ->modalDescription('Log entries are deleted permanently.')
                ->modalSubmitActionLabel('Clear')
                ->action(function (array $data) {
                    $deleted = LogEntry::query()
                        ->when($data['age'] !== 'all', fn ($q) => $q->where('created_at', '<', now()->subDays((int) $data['age'])))
                        ->when($data['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
                        ->delete();

                    Notification::make()->title("Removed {$deleted} log entries")->success()->send();
                }),
        ];
    }
}
