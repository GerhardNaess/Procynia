<?php

namespace App\Providers;

use App\Models\Customer;
use App\Support\EnterpriseWiki\EnterpriseWikiQueueReservationTrace;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobPopped;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Cashier::useCustomerModel(Customer::class);

        // Scoped queue observability for the enterprise-wiki Redis queue only.
        $events = $this->app['events'];
        $events->listen(JobPopping::class, static function (JobPopping $event): void {
            EnterpriseWikiQueueReservationTrace::logReservationCycle($event);
        });
        $events->listen(JobPopped::class, static function (JobPopped $event): void {
            EnterpriseWikiQueueReservationTrace::logReservation($event);
        });
        $events->listen(JobQueued::class, static function (JobQueued $event): void {
            EnterpriseWikiQueueReservationTrace::logDispatch($event);
        });

        RateLimiter::for('public-registration', function (Request $request): Limit {
            $email = Str::lower(trim((string) $request->input('owner_email', '')));
            $key = sprintf('%s|%s', $request->ip() ?? 'unknown', $email !== '' ? $email : 'anonymous');

            return Limit::perMinute(5)->by($key);
        });
    }
}
