<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ai:sync-standby --minutes=180 --limit=40')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('ai:requeue-stuck-booking --minutes=720 --limit=40')
    ->cron('*/2 * * * *')
    ->withoutOverlapping();

Schedule::command('project:check-material-readiness')
    ->daily()
    ->withoutOverlapping();
