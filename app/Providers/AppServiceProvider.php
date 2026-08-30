<?php

namespace App\Providers;

use App\Models\Customer;
use App\Support\Ai\AiCallContextScope;
use App\Support\EnterpriseWiki\EnterpriseWikiQueueReservationTrace;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
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
        $this->app->singleton(AiCallContextScope::class);
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

        $this->configureTrustedProxies();

        RateLimiter::for('public-registration', function (Request $request): Limit {
            $email = Str::lower(trim((string) $request->input('owner_email', '')));
            $key = sprintf('%s|%s', $request->ip() ?? 'unknown', $email !== '' ? $email : 'anonymous');

            return Limit::perMinute(5)->by($key);
        });

        // Entra sign-in start and callback. Not the F-01 password throttle — there is no credential
        // to guess here — but both endpoints are unauthenticated and do real work (a token exchange,
        // a signing-key fetch), so they get an abuse limit keyed on the client IP.
        RateLimiter::for('entra-auth', function (Request $request): Limit {
            return Limit::perMinute(20)->by($request->ip() ?? 'unknown');
        });
    }

    /**
     * Decide whose X-Forwarded-* headers this application is allowed to believe.
     *
     * Configured here rather than in bootstrap/app.php because configuration is not loaded when the
     * middleware closure there runs. The default is to trust nothing, which makes request()->ip()
     * resolve to REMOTE_ADDR — the peer nginx actually accepted the connection from, and a value no
     * client can forge.
     *
     * Nothing is set when the list is empty: Laravel's TrustProxies treats an empty array as "not
     * configured" and falls through to its own default, which is also "trust nothing".
     */
    private function configureTrustedProxies(): void
    {
        /** @var list<string> $proxies */
        $proxies = (array) config('procynia.security.trusted_proxies', []);

        if ($proxies === []) {
            return;
        }

        TrustProxies::at(in_array('*', $proxies, true) ? '*' : $proxies);
    }
}
