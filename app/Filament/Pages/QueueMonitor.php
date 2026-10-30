<?php

namespace App\Filament\Pages;

use App\Filament\Queue\ActiveJobsTable;
use App\Filament\Queue\FailedJobsTable;
use App\Filament\Queue\QueueStatus;
use App\Models\FailedJob;
use App\Services\QueueHealth;
use App\Support\Heartbeat;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use UnitEnum;

/**
 * The background processes: is the scheduler alive, what is waiting in the
 * queue, and what failed.
 */
class QueueMonitor extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Queue';

    protected static ?string $title = 'Queue';

    protected static ?string $slug = 'queue';

    protected static ?int $navigationSort = 2;

    public function getSubheading(): ?string
    {
        return 'The scheduler container runs the schedules and starts a queue worker every minute; '
            .'the worker processes the waiting jobs (runs started from the admin) and stops.';
    }

    /**
     * Red when the scheduler stopped or the worker is blocked, otherwise the
     * number of failed jobs.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! Heartbeat::alive('scheduler') || rescue(fn () => app(QueueHealth::class)->workerBlocked(), false, false)) {
            return '!';
        }

        $failed = rescue(fn () => FailedJob::count(), 0, false);

        return $failed > 0 ? (string) $failed : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return match (true) {
            ! Heartbeat::alive('scheduler') => 'The scheduler is not running',
            app(QueueHealth::class)->workerBlocked() => 'The queue worker is blocked',
            default => 'Failed jobs',
        };
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Livewire::make(QueueStatus::class),
            Livewire::make(ActiveJobsTable::class),
            Livewire::make(FailedJobsTable::class),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('releaseWorker')
                ->label('Release worker')
                ->icon(Heroicon::OutlinedLockOpen)
                ->color('warning')
                ->visible(fn () => app(QueueHealth::class)->workerBlocked())
                ->requiresConfirmation()
                ->modalDescription('Removes the lock of a worker that is gone, so the scheduler starts a new one within a minute.')
                ->action(function (QueueHealth $health) {
                    $health->releaseWorker();
                    Log::warning('Queue worker lock released from the admin.', ['category' => 'queue', 'user' => auth()->user()?->email]);
                    Notification::make()->title('Worker released')->body('A new worker starts within a minute.')->success()->send();
                }),
            Action::make('deleteFailed')
                ->label('Delete all failed jobs')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->visible(fn () => FailedJob::exists())
                ->requiresConfirmation()
                ->action(function () {
                    Artisan::call('queue:flush');
                    Notification::make()->title('Failed jobs deleted')->success()->send();
                }),
        ];
    }
}
