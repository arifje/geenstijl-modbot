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
        return 'The scheduler queues the scheduled runs; the worker container runs them, and the runs started from the admin, one at a time.';
    }

    /**
     * Red when the scheduler or the worker stopped, otherwise the number of
     * failed jobs.
     */
    public static function getNavigationBadge(): ?string
    {
        if (self::problem() !== null) {
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
        return self::problem() ?? 'Failed jobs';
    }

    private static function problem(): ?string
    {
        return match (true) {
            ! Heartbeat::alive('scheduler') => 'The scheduler is not running',
            ! rescue(fn () => app(QueueHealth::class)->workerAlive(), true, false) => 'The queue worker is not running',
            default => null,
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
            Action::make('restartWorker')
                ->label('Restart worker')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('The worker stops after the job it is running (if any) and is started again by Docker or Kubernetes, with the latest settings and code.')
                ->action(function () {
                    Artisan::call('queue:restart');
                    Log::info('Queue worker restart requested from the admin.', ['category' => 'queue', 'user' => auth()->user()?->email]);
                    Notification::make()->title('Worker restarts')->body('It starts again within a few seconds, after the current job.')->success()->send();
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
