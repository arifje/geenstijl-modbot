<?php

namespace App\Filament\Queue;

use App\Filament\Resources\ModerationRuns\ModerationRunResource;
use App\Models\FailedJob;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;

/**
 * Jobs that failed, to inspect, retry or delete.
 */
class FailedJobsTable extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Failed jobs')
            ->query(FailedJob::query())
            ->defaultSort('failed_at', 'desc')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('failed_at')->label('Failed')->dateTime('d-m H:i:s', 'Europe/Amsterdam')->sortable(),
                TextColumn::make('job')->state(fn (FailedJob $record) => $record->displayName())
                    ->description(fn (FailedJob $record) => $record->runId() ? "Run #{$record->runId()}" : null)
                    ->url(fn (FailedJob $record) => $record->runId() ? ModerationRunResource::getUrl('view', ['record' => $record->runId()]) : null),
                TextColumn::make('error')->state(fn (FailedJob $record) => $record->errorSummary())->wrap()->color('danger'),
                TextColumn::make('queue')->color('gray')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('view')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modalHeading(fn (FailedJob $record) => $record->displayName())
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        TextEntry::make('failed_at')->label('Failed')->dateTime('d-m-Y H:i:s', 'Europe/Amsterdam'),
                        TextEntry::make('exception')->label('Error')
                            ->fontFamily('mono')
                            ->extraAttributes(['style' => 'white-space: pre-wrap; font-size: 12px; max-height: 60vh; overflow: auto']),
                    ]),
                Action::make('retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (FailedJob $record) => $record->retryable())
                    ->requiresConfirmation()
                    ->action(function (FailedJob $record) {
                        Artisan::call('queue:retry', ['id' => [$record->uuid]]);
                        Notification::make()->title('Job queued again')->success()->send();
                    }),
                Action::make('retryRun')
                    ->label('Retry run')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (FailedJob $record) => ! $record->retryable() && $record->runId())
                    ->url(fn (FailedJob $record) => ModerationRunResource::getUrl('view', ['record' => $record->runId()])),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkAction::make('retry')
                    ->label('Retry selected')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->modalDescription('Moderation runs are skipped: retry those from the run (Runs → Retry).')
                    ->action(function (Collection $records) {
                        $uuids = $records->filter->retryable()->pluck('uuid')->all();

                        if ($uuids) {
                            Artisan::call('queue:retry', ['id' => $uuids]);
                        }

                        Notification::make()->title(count($uuids).' job(s) queued again')->success()->send();
                    }),
                DeleteBulkAction::make(),
            ])
            ->emptyStateHeading('No failed jobs')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }
}
