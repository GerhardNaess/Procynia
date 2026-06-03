<?php

use App\Jobs\OpsQueueHeartbeatJob;
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

Schedule::command('ops:scheduler-heartbeat')->everyMinute();
Schedule::command('procynia:backup')->hourly()->withoutOverlapping();
Schedule::job(new OpsQueueHeartbeatJob('supplier-harvests'))->everyMinute();
Schedule::job(new OpsQueueHeartbeatJob('supplier-lookups'))->everyMinute();
Schedule::job(new OpsQueueHeartbeatJob('ai-requirements'))->everyMinute();
Schedule::job(new OpsQueueHeartbeatJob('default'))->everyMinute();

Schedule::command('ai:sync-model-prices')
    ->dailyAt('03:00')
    ->withoutOverlapping();
