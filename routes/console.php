<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('doffin:import-batch --trigger=scheduler')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('doffin:watch-inbox-discover')
    ->dailyAt('01:15')
    ->withoutOverlapping();
