<?php

use App\Models\Schedule as RunSchedule;
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

// Runs started from the admin.
Schedule::command('queue:work --stop-when-empty --tries=1 --timeout=1500')
    ->everyMinute()
    ->withoutOverlapping(30)
    ->runInBackground();

Schedule::command('modbot:prune')
    ->dailyAt('04:00')
    ->timezone('Europe/Amsterdam');
