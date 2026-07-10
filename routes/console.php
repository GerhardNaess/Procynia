<?php

use App\Jobs\OpsQueueHeartbeatJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (config('doffin.scheduled_import_enabled')) {
    Schedule::command('doffin:import-batch --trigger=scheduler')
        ->hourly()
        ->withoutOverlapping();
}

if (config('doffin.watch_inbox_discovery_enabled')) {
    Schedule::command('doffin:watch-inbox-discover --trigger=scheduler')
        ->dailyAt('01:15')
        ->withoutOverlapping();
}

Schedule::command('ops:scheduler-heartbeat')->everyMinute();
Schedule::command('procynia:backup')->hourly()->withoutOverlapping();
Schedule::job(new OpsQueueHeartbeatJob('supplier-harvests'))->everyMinute();
Schedule::job(new OpsQueueHeartbeatJob('supplier-lookups'))->everyMinute();
Schedule::job(new OpsQueueHeartbeatJob('ai-requirements'))->everyMinute();
Schedule::job(new OpsQueueHeartbeatJob('default'))->everyMinute();

Schedule::command('ai:sync-model-prices')
    ->dailyAt('03:00')
    ->withoutOverlapping();

// Norges Bank publishes rates ~16:00 CET; 17:00 Europe/Oslo ensures today's rate is available.
Schedule::command('exchange-rates:sync')
    ->dailyAt('17:00')
    ->timezone('Europe/Oslo')
    ->withoutOverlapping();

// Enterprise Wiki lint: refresh open/resolved health findings for all active customers.
Schedule::command('wiki:lint')
    ->dailyAt('02:30')
    ->withoutOverlapping();

// Enterprise Wiki post-ingest QA: process applied runs with pending or failed QA status.
Schedule::command('wiki:run-post-ingest-qa --all-pending')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
