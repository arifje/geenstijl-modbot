<?php

namespace App\Filament\Actions;

use App\Models\ModerationRun;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Cancels a run that has not started yet. The queued job sees the status
 * and skips it.
 */
class CancelRunAction
{
    public static function make(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->visible(fn (ModerationRun $record) => $record->status === 'queued')
            ->requiresConfirmation()
            ->modalHeading('Cancel this run?')
            ->action(function (ModerationRun $record) {
                $cancelled = ModerationRun::whereKey($record->id)
                    ->where('status', 'queued')
                    ->update(['status' => 'cancelled', 'finished_at' => now()]);

                $cancelled
                    ? Notification::make()->title('Run cancelled')->success()->send()
                    : Notification::make()->title('The run already started')->warning()->send();
            });
    }
}
