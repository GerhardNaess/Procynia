<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\QualityProcessBlueprint;
use App\Services\Doffin\DoffinSourceAdapter;
use App\Services\EnterpriseWiki\GraphProjection\GraphProjectionService;
use App\Services\EnterpriseWiki\GraphProjection\Neo4jGraphProjectionService;
use App\Services\EnterpriseWiki\GraphProjection\NullGraphProjectionService;
use App\Services\EnterpriseWiki\GraphQuery\GraphQueryService;
use App\Services\EnterpriseWiki\GraphQuery\Neo4jGraphQueryService;
use App\Services\EnterpriseWiki\GraphQuery\NullGraphQueryService;
use App\Services\OpportunitySources\OpportunitySourceRegistry;
use App\Services\Quality\QualityActivityLinkCleanup;
use App\Services\Ted\TedSourceAdapter;
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

        // Every opportunity source Procynia can speak to, addressed by its own key.
        //
        // This replaces a bind of OpportunitySourceAdapter to DoffinSourceAdapter. That binding
        // made "the adapter" mean Doffin everywhere, so a consumer holding a row from another
        // register would have been handed Doffin's adapter and had no way to tell. Nothing
        // source-neutral resolves the interface from the container any more; it asks the registry
        // for the source it actually has.
        $this->app->singleton(OpportunitySourceRegistry::class, fn ($app): OpportunitySourceRegistry => new OpportunitySourceRegistry([
            $app->make(DoffinSourceAdapter::class),
            $app->make(TedSourceAdapter::class),
        ]));

        $this->app->singleton(GraphProjectionService::class, function (): GraphProjectionService {
            /** @var array{enabled?: bool, uri?: string, database?: ?string, username?: ?string, password?: ?string} $config */
            $config = config('services.neo4j', []);

            if (! (bool) ($config['enabled'] ?? false)) {
                return new NullGraphProjectionService;
            }

            return new Neo4jGraphProjectionService(
                uri: (string) ($config['uri'] ?? 'bolt://localhost:7687'),
                database: $config['database'] ?? null,
                username: $config['username'] ?? null,
                password: $config['password'] ?? null,
            );
        });

        // The read side of the same pilot, bound separately: a deployment may well project the
        // graph long before anything is allowed to read from it, and the null reader keeps the
        // SQL-backed graph entirely unaffected when Neo4j is off.
        $this->app->singleton(GraphQueryService::class, function (): GraphQueryService {
            /** @var array{enabled?: bool, uri?: string, database?: ?string, username?: ?string, password?: ?string} $config */
            $config = config('services.neo4j', []);

            if (! (bool) ($config['enabled'] ?? false)) {
                return new NullGraphQueryService;
            }

            return new Neo4jGraphQueryService(
                uri: (string) ($config['uri'] ?? 'bolt://localhost:7687'),
                database: $config['database'] ?? null,
                username: $config['username'] ?? null,
                password: $config['password'] ?? null,
            );
        });
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

        // A risk or a KPI linked to a Kvalitet activity loses the link when the step leaves the
        // working flow, or the flow goes. Hooked here so Kvalitet's own code never reads or mentions
        // Risiko or Mål og KPI.
        QualityProcessBlueprint::saved(static function (QualityProcessBlueprint $blueprint): void {
            app(QualityActivityLinkCleanup::class)->prune((int) $blueprint->quality_item_id, $blueprint);
        });
        QualityProcessBlueprint::deleted(static function (QualityProcessBlueprint $blueprint): void {
            app(QualityActivityLinkCleanup::class)->prune((int) $blueprint->quality_item_id, null);
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
