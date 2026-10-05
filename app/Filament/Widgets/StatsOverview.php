<?php

namespace App\Filament\Widgets;

use App\Models\CommentVerdict;
use App\Models\ModerationRun;
use App\Models\Schedule;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $lastRun = ModerationRun::latest('id')->first();
        $flags24h = CommentVerdict::whereHas('comment', fn ($q) => $q->where('posted_at', '>=', now()->subDay()))->count();
        $high24h = CommentVerdict::where('priority', 'high')->whereHas('comment', fn ($q) => $q->where('posted_at', '>=', now()->subDay()))->count();
        $monthCost = ModerationRun::where('started_at', '>=', now()->startOfMonth())->get()->sum(fn (ModerationRun $r) => $r->estimatedCost() ?? 0);
        $next = Schedule::where('enabled', true)->get()->map->nextRunAt()->filter()->sort()->first();

        return [
            Stat::make('Flags (last 24h)', $flags24h)
                ->description("{$high24h} high priority")
                ->color($high24h > 0 ? 'danger' : 'gray'),
            Stat::make('Last run', $lastRun ? $lastRun->started_at->timezone('Europe/Amsterdam')->format('d-m H:i') : '—')
                ->description($lastRun ? "{$lastRun->status} · {$lastRun->analyzed_count} analysed · {$lastRun->flagged_count} flagged" : 'No runs yet')
                ->color(match ($lastRun?->status) {
                    'succeeded' => 'success', 'partial' => 'warning', 'failed' => 'danger', default => 'gray',
                }),
            Stat::make('Next run', $next ? $next->timezone('Europe/Amsterdam')->format('D d-m H:i') : '—')
                ->description($next ? $next->diffForHumans() : 'No enabled schedules'),
            Stat::make('Claude cost this month', '≈ $'.number_format($monthCost, 2)),
        ];
    }
}
