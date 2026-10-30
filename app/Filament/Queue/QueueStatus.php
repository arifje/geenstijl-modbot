<?php

namespace App\Filament\Queue;

use App\Models\FailedJob;
use App\Models\QueuedJob;
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
                ->description('Schedules and the queue only run while the scheduler container runs.')
                ->color('danger'),
            Heartbeat::alive('scheduler') => Stat::make('Scheduler', 'Running')
                ->description('Last seen '.$seen->diffForHumans())
                ->color('success'),
            default => Stat::make('Scheduler', 'Not running')
                ->description('Last seen '.$seen->timezone('Europe/Amsterdam')->format('d-m H:i').' ('.$seen->diffForHumans().'). No schedules or queued runs are processed.')
                ->color('danger'),
        };
    }

    private function worker(): Stat
    {
        $busy = QueuedJob::whereNotNull('reserved_at')->orderBy('reserved_at')->first();

        if ($busy) {
            $run = $busy->runId() ? " (run #{$busy->runId()})" : '';

            return Stat::make('Queue worker', 'Busy')
                ->description("{$busy->displayName()}{$run}, started {$busy->startedAt()->diffForHumans()}")
                ->color('info');
        }

        $seen = Heartbeat::lastSeen('worker');

        return Stat::make('Queue worker', Heartbeat::alive('scheduler') ? 'Idle' : 'Stopped')
            ->description('Started by the scheduler every minute'.($seen ? '; last active '.$seen->diffForHumans() : '').'.')
            ->color(Heartbeat::alive('scheduler') ? 'gray' : 'danger');
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
