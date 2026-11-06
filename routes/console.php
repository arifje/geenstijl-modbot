<?php

use App\Models\Schedule as RunSchedule;
use Illuminate\Support\Facades\Schedule;

// Report schedules are managed in the admin (/admin/schedules). The
// scheduler re-reads them every minute and only queues the runs: the worker
// container ("php artisan queue:work") runs them, one at a time.
try {
    $schedules = RunSchedule::where('enabled', true)->get();
} catch (Throwable) {
    $schedules = collect(); // database not migrated yet
}

foreach ($schedules as $schedule) {
    Schedule::command('modbot:run', ['--schedule' => $schedule->id, '--queue'])
        ->name("modbot:run schedule {$schedule->id} ({$schedule->name})")
        ->cron($schedule->cron)
        ->timezone($schedule->timezone);
}

// Fails queued runs whose job is gone. Also keeps the scheduler's "last seen"
// fresh: something is due every minute.
Schedule::command('modbot:queue-check')->everyMinute();

Schedule::command('modbot:prune')
    ->dailyAt('04:00')
    ->timezone('Europe/Amsterdam');

// Versions, end of life and security advisories, for System → About & updates.
Schedule::command('modbot:check-updates')
    ->dailyAt('03:30')
    ->timezone('Europe/Amsterdam');
