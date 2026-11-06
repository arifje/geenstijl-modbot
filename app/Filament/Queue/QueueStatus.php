<?php

namespace App\Filament\Queue;

use App\Models\FailedJob;
use App\Models\QueuedJob;
use App\Services\QueueHealth;
use App\Support\Heartbeat;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Scheduler, worker and job counts at the top of System → Queue.
 */
class QueueStatus extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '10s';

    protected function getStats(): array
    {
        return [
            $this->scheduler(),
            $this->worker(),
            $this->waiting(),
            $this->failed(),
        ];
    }

    private function scheduler(): Stat
    {
        $seen = Heartbeat::lastSeen('scheduler');

        return match (true) {
            $seen === null => Stat::make('Scheduler', 'Not seen yet')
                ->description('Scheduled runs are only queued while the scheduler container runs.')
                ->color('danger'),
            Heartbeat::alive('scheduler') => Stat::make('Scheduler', 'Running')
                ->description('Last seen '.$seen->diffForHumans())
                ->color('success'),
            default => Stat::make('Scheduler', 'Not running')
                ->description('Last seen '.$seen->timezone('Europe/Amsterdam')->format('d-m H:i').' ('.$seen->diffForHumans().'). Scheduled runs are not queued.')
                ->color('danger'),
        };
    }

    private function worker(): Stat
    {
        $health = app(QueueHealth::class);

        if ($busy = $health->busyJob()) {
            $run = $busy->runId() ? " (run #{$busy->runId()})" : '';

            return Stat::make('Queue worker', 'Busy')
                ->description("{$busy->displayName()}{$run}, started {$busy->startedAt()->diffForHumans()}")
                ->color('info');
        }

        $seen = Heartbeat::lastSeen('worker');

        if ($health->workerAlive()) {
            return Stat::make('Queue worker', 'Running')
                ->description('Waiting for jobs; last checked '.$seen->diffForHumans().'.')
                ->color('success');
        }

        return Stat::make('Queue worker', 'Not running')
            ->description(($seen ? 'Last seen '.$seen->timezone('Europe/Amsterdam')->format('d-m H:i').'. ' : '')
                .'No runs are processed. Check the worker container (docker compose ps worker).')
            ->color('danger');
    }

    private function waiting(): Stat
    {
        $waiting = QueuedJob::whereNull('reserved_at');
        $count = (clone $waiting)->count();
        $oldest = (clone $waiting)->min('created_at');
        $stuck = $oldest && $oldest < now()->subMinutes(Heartbeat::ALIVE_MINUTES)->getTimestamp();

        return Stat::make('Waiting jobs', (string) $count)
            ->description($oldest ? 'Oldest waiting since '.now()->setTimestamp($oldest)->diffForHumans() : 'Nothing waiting')
            ->color($stuck ? 'warning' : 'gray');
    }

    private function failed(): Stat
    {
        $count = FailedJob::count();

        return Stat::make('Failed jobs', (string) $count)
            ->description($count ? 'See below; retry or delete them.' : 'No failed jobs')
            ->color($count ? 'danger' : 'gray');
    }
}
