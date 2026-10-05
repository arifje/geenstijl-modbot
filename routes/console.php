<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('modbot:run')
    ->cron(config('modbot.report.cron'))
    ->timezone(config('modbot.geenstijl.timezone'))
    ->withoutOverlapping(60);

Schedule::command('modbot:prune')
    ->dailyAt('04:00')
    ->timezone(config('modbot.geenstijl.timezone'));
