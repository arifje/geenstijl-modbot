<?php

namespace App\Filament\Queue;

use App\Filament\Resources\ModerationRuns\ModerationRunResource;
use App\Models\ModerationRun;
use App\Models\QueuedJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Jobs waiting in the database queue, and the one being processed.
 */
class ActiveJobsTable extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public const STATUSES = [
        'running' => ['Running', 'info'],
        'waiting' => ['Waiting', 'warning'],
        'delayed' => ['Delayed', 'gray'],
    ];

    public function table(Table $table): Table
    {
        return $table
            ->heading('Waiting and running jobs')
            ->query(QueuedJob::query())
            ->defaultSort('id')
            ->poll('5s')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('job')->state(fn (QueuedJob $record) => $record->displayName())
                    ->description(fn (QueuedJob $record) => $record->runId() ? "Run #{$record->runId()}" : null)
                    ->url(fn (QueuedJob $record) => $record->runId() ? ModerationRunResource::getUrl('view', ['record' => $record->runId()]) : null),
                TextColumn::make('status')->badge()
                    ->state(fn (QueuedJob $record) => $record->status())
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state][0])
                    ->color(fn (string $state) => self::STATUSES[$state][1]),
                TextColumn::make('queued')->label('Queued')
                    ->state(fn (QueuedJob $record) => $record->queuedAt())
                    ->dateTime('d-m H:i:s', 'Europe/Amsterdam')
                    ->description(fn (QueuedJob $record) => $record->queuedAt()->diffForHumans()),
                TextColumn::make('started')->label('Started')
                    ->state(fn (QueuedJob $record) => $record->startedAt())
                    ->dateTime('d-m H:i:s', 'Europe/Amsterdam')
                    ->placeholder('—'),
                TextColumn::make('attempts'),
                TextColumn::make('queue')->color('gray'),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label(fn (QueuedJob $record) => $record->runId() ? 'Cancel run' : 'Remove')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->visible(fn (QueuedJob $record) => $record->reserved_at === null)
                    ->requiresConfirmation()
                    ->modalDescription('The job is removed from the queue and will not run.')
                    ->action(function (QueuedJob $record) {
                        // Only while no worker has picked it up.
                        if (! QueuedJob::whereKey($record->id)->whereNull('reserved_at')->delete()) {
                            Notification::make()->title('The job already started')->warning()->send();

                            return;
                        }

                        ModerationRun::whereKey($record->runId())
                            ->where('status', 'queued')
                            ->update(['status' => 'cancelled', 'finished_at' => now()]);

                        Notification::make()->title('Job removed')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No jobs waiting')
            ->emptyStateDescription('Runs started from the admin wait here until the worker picks them up (within a minute).')
            ->emptyStateIcon(Heroicon::OutlinedInboxStack);
    }
}
