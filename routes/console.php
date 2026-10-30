<?php

use App\Models\Schedule as RunSchedule;
use App\Services\QueueHealth;
use Illuminate\Support\Facades\Schedule;

// Report schedules are managed in the admin (/admin/schedules). The
// scheduler re-reads them every minute.
try {
    $schedules = RunSchedule::where('enabled', true)->get();
} catch (Throwable) {
    $schedules = collect(); // database not migrated yet
}

foreach ($schedules as $schedule) {
    Schedule::command('modbot:run', ['--schedule' => $schedule->id])
        ->name("modbot:run schedule {$schedule->id} ({$schedule->name})")
        ->cron($schedule->cron)
        ->timezone($schedule->timezone)
        ->withoutOverlapping(60)
        ->runInBackground();
}

// Runs started from the admin. The check goes first: it releases a lock left
// by a worker that is gone, so the worker below can start in the same minute.
Schedule::command('modbot:queue-check')->everyMinute();

Schedule::command('queue:work --stop-when-empty --tries=1 --timeout=1500')
    ->everyMinute()
    ->withoutOverlapping(30)
    ->createMutexNameUsing(QueueHealth::WORKER_MUTEX)
    ->runInBackground();

Schedule::command('modbot:prune')
    ->dailyAt('04:00')
    ->timezone('Europe/Amsterdam');

// Versions, end of life and security advisories, for System → About & updates.
Schedule::command('modbot:check-updates')
    ->dailyAt('03:30')
    ->timezone('Europe/Amsterdam');
