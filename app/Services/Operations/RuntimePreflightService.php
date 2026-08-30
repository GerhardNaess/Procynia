<?php

namespace App\Services\Operations;

use App\Models\IdentityProvider;
use App\Services\Auth\EntraConfig;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Read-only preflight checks for a Procynia container.
 *
 * Answers one question: "is this runtime actually able to run Procynia?" — before a staging
 * environment is signed off, and after every deployment that changes the runtime contract.
 *
 * Every check is read-only. Nothing is created, migrated, deleted or dispatched. The only write is a
 * scratch file in the storage disk, which is removed again — because "the disk is mounted" and "the
 * disk is writable" are different claims, and the second is the one that matters.
 *
 * No check ever returns a secret value. Where a value is sensitive (APP_KEY, DB password, Redis URL)
 * the check reports presence and shape, never content.
 */
class RuntimePreflightService
{
    public const STATUS_PASS = 'pass';

    public const STATUS_FAIL = 'fail';

    public const STATUS_WARN = 'warn';

    public const STATUS_SKIP = 'skip';

    /**
     * PHP extensions the runtime genuinely depends on. Mirrors the list asserted in
     * tests/Feature/Azure/ContainerRuntimeContractTest.php.
     *
     * @var list<string>
     */
    private const REQUIRED_EXTENSIONS = [
        'bcmath', 'curl', 'dom', 'exif', 'gd', 'intl', 'mbstring', 'openssl',
        'pcntl', 'pdo_pgsql', 'pgsql', 'redis', 'xml', 'xmlreader', 'xmlwriter', 'xsl', 'zip',
    ];

    /**
     * Run every check.
     *
     * @param  bool  $azure  Apply the Azure runtime contract: legacy Compose backup must be disabled.
     * @param  bool  $withOpenAi  Additionally verify OpenAI connectivity. Off by default because it
     *                            costs a request; the check itself uses the cheapest endpoint there is.
     * @return list<array{name: string, status: string, detail: string, critical: bool}>
     */
    public function run(bool $azure = false, bool $withOpenAi = false): array
    {
        return array_merge(
            [
                $this->checkApplicationKey(),
                $this->checkApplicationEnvironment(),
                $this->checkApplicationUrl(),
            ],
            $this->checkPhpExtensions(),
            [
                $this->checkDatabase(),
                $this->checkVectorExtension(),
                $this->checkRedis(),
                $this->checkRedisAuthentication(),
                $this->checkEntraAuthentication(),
                $this->checkStorageDisk(),
                $this->checkSharedStoragePath(),
            ],
            $this->checkExternalBinaries(),
            [
                $this->checkLogging(),
                $this->checkLegacyBackup($azure),
                $this->checkQueueConnection(),
                $withOpenAi ? $this->checkOpenAi() : $this->skip('OpenAI connectivity', 'not requested (pass --with-openai)'),
            ],
            $this->checkAiCostControl(),
        );
    }

    // -----------------------------------------------------------------------
    // AI cost control
    // -----------------------------------------------------------------------

    /**
     * Verify that the cost-control chain can actually run in this environment.
     *
     * The guards are designed to fail closed, but that design assumes their own schema exists. On a
     * runtime where the migrations have not been applied, the first guard raises a raw database
     * error instead of a controlled stop — no money is spent, but nobody can tell why AI is broken.
     * Preflight is where that should surface, not a customer's first AI request.
     *
     * @return list<array{name: string, status: string, detail: string, critical: bool}>
     */
    private function checkAiCostControl(): array
    {
        return [
            $this->checkAiCostControlSchema(),
            $this->checkAiCostControlRuntimeSingleton(),
            $this->checkAiPricingReadiness(),
            $this->checkAiExchangeRateReadiness(),
            $this->checkAiCostControlConfiguration(),
        ];
    }

    /** @return array{name: string, status: string, detail: string, critical: bool} */
    private function checkAiCostControlSchema(): array
    {
        $required = [
            'ai_usage_attempts',
            'ai_runtime_controls',
            'customer_ai_quota_periods',
            'customer_ai_usage_reservations',
            'customer_ai_notification_states',
            'customer_ai_credit_adjustments',
            'customer_ai_operational_limits',
            'ai_operational_budget_periods',
        ];

        try {
            $missing = array_values(array_filter($required, fn (string $table): bool => ! Schema::hasTable($table)));

            if ($missing === []) {
                return $this->pass('AI cost control schema', 'all cost-control tables present');
            }

            return $this->fail(
                'AI cost control schema',
                sprintf(
                    'missing %d cost-control table(s): %s. Run the migrations before serving AI traffic.',
                    count($missing),
                    implode(', ', $missing),
                ),
            );
        } catch (Throwable $e) {
            return $this->fail('AI cost control schema', 'could not be determined: '.$this->redact($e->getMessage()));
        }
    }

    /**
     * The global runtime control is a singleton the guard reads on every call. A missing row makes
     * the guard fail closed — correct, but a total AI outage that preflight should announce first.
     *
     * @return array{name: string, status: string, detail: string, critical: bool}
     */
    private function checkAiCostControlRuntimeSingleton(): array
    {
        try {
            if (! Schema::hasTable('ai_runtime_controls')) {
                return $this->skip('AI runtime control row', 'cost-control schema not migrated yet');
            }

            $control = DB::table('ai_runtime_controls')->orderBy('id')->first();

            if ($control === null) {
                return $this->fail(
                    'AI runtime control row',
                    'no runtime control row exists; the guard fails closed and every AI call will be refused',
                );
            }

            if ((bool) $control->global_ai_stop) {
                return $this->warn('AI runtime control row', 'global AI stop is currently ACTIVE — no AI calls will run');
            }

            return $this->pass('AI runtime control row', 'present, global stop is off');
        } catch (Throwable $e) {
            return $this->fail('AI runtime control row', 'could not be determined: '.$this->redact($e->getMessage()));
        }
    }

    /**
     * Operational NOK enforcement prices every call before it runs. With an empty price catalogue
     * it can price nothing, so the budgets silently have nothing to charge — enforcement that looks
     * active but is not. That combination must not reach production.
     *
     * @return array{name: string, status: string, detail: string, critical: bool}
     */
    private function checkAiPricingReadiness(): array
    {
        try {
            if (! Schema::hasTable('ai_model_prices') || ! Schema::hasTable('ai_runtime_controls')) {
                return $this->skip('AI model pricing', 'cost-control schema not migrated yet');
            }

            $priceCount = DB::table('ai_model_prices')->count();
            $control = DB::table('ai_runtime_controls')->orderBy('id')->first();
            $budgetEnforcing = $control !== null && (bool) ($control->operational_budget_enabled ?? false);

            if ($priceCount === 0 && $budgetEnforcing) {
                return $this->fail(
                    'AI model pricing',
                    'operational budget enforcement is enabled but no model prices exist; budgets cannot price any call. Run ai:sync-model-prices.',
                );
            }

            if ($priceCount === 0) {
                return $this->warn(
                    'AI model pricing',
                    'no model prices registered; unknown-price enforcement is inert until the catalogue is populated',
                );
            }

            return $this->pass('AI model pricing', sprintf('%d model price(s) registered', $priceCount));
        } catch (Throwable $e) {
            return $this->fail('AI model pricing', 'could not be determined: '.$this->redact($e->getMessage()));
        }
    }

    /**
     * A missing rate never prices a call at zero — a conservative fallback is used instead — so this
     * is a warning, not a deploy blocker. It still needs to be visible: the fallback is a safety
     * value, not a reporting truth.
     *
     * @return array{name: string, status: string, detail: string, critical: bool}
     */
    private function checkAiExchangeRateReadiness(): array
    {
        try {
            if (! Schema::hasTable('exchange_rates')) {
                return $this->skip('AI exchange rates', 'exchange rate table not migrated yet');
            }

            $latest = DB::table('exchange_rates')
                ->where('base_currency', 'USD')
                ->where('quote_currency', 'NOK')
                ->orderByDesc('rate_date')
                ->first();

            if ($latest === null) {
                return $this->warn(
                    'AI exchange rates',
                    'no USD/NOK rate recorded; costs fall back to a conservative fixed rate. Run exchange-rates:sync.',
                );
            }

            $ageDays = (int) now()->startOfDay()->diffInDays($latest->rate_date, absolute: true);
            $criticalAge = max(1, (int) config('procynia.ai.fx.critical_age_days', 14));

            if ($ageDays >= $criticalAge) {
                return $this->warn(
                    'AI exchange rates',
                    sprintf('latest USD/NOK rate is %d days old; estimates use a safety margin', $ageDays),
                );
            }

            return $this->pass('AI exchange rates', sprintf('latest USD/NOK rate is %d day(s) old', $ageDays));
        } catch (Throwable $e) {
            return $this->warn('AI exchange rates', 'could not be determined: '.$this->redact($e->getMessage()));
        }
    }

    /**
     * Catch policy values that cannot mean what they say — a critical threshold below its warning,
     * a negative margin, a zero fallback rate that would silently price everything at nothing.
     *
     * @return array{name: string, status: string, detail: string, critical: bool}
     */
    private function checkAiCostControlConfiguration(): array
    {
        $problems = [];

        $quotaWarning = (int) config('procynia.ai.quota.warning_percent', 80);
        $quotaCritical = (int) config('procynia.ai.quota.critical_percent', 90);

        if ($quotaWarning < 1 || $quotaWarning > 99 || $quotaCritical < $quotaWarning || $quotaCritical > 99) {
            $problems[] = sprintf('quota thresholds are inconsistent (warning %d, critical %d)', $quotaWarning, $quotaCritical);
        }

        $priceWarning = (int) config('procynia.ai.pricing.warning_age_days', 90);
        $priceCritical = (int) config('procynia.ai.pricing.critical_age_days', 180);

        if ($priceWarning < 1 || $priceCritical < $priceWarning) {
            $problems[] = sprintf('model price ages are inconsistent (warning %d, critical %d)', $priceWarning, $priceCritical);
        }

        $fxWarning = (int) config('procynia.ai.fx.warning_age_days', 3);
        $fxCritical = (int) config('procynia.ai.fx.critical_age_days', 14);

        if ($fxWarning < 1 || $fxCritical < $fxWarning) {
            $problems[] = sprintf('FX ages are inconsistent (warning %d, critical %d)', $fxWarning, $fxCritical);
        }

        if ((float) config('procynia.ai.fx.fallback_usd_nok_rate', 12.0) <= 0) {
            $problems[] = 'FX fallback rate must be greater than zero, or an unpriced call would cost nothing';
        }

        foreach ([
            'procynia.ai.fx.safety_margin_percent',
            'procynia.ai.pricing.stale_safety_margin_percent',
            'procynia.ai.operational_budget.reservation_safety_margin_percent',
        ] as $key) {
            if ((float) config($key, 0) < 0) {
                $problems[] = $key.' must not be negative';
            }
        }

        if ((int) config('procynia.ai.payment.past_due_grace_days', 7) < 0) {
            $problems[] = 'past-due grace days must not be negative';
        }

        return $problems === []
            ? $this->pass('AI cost control configuration', 'thresholds, margins and fallbacks are consistent')
            : $this->fail('AI cost control configuration', implode('; ', $problems));
    }

    /**
     * @param  list<array{status: string, critical: bool}>  $checks
     */
    public function hasCriticalFailure(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === self::STATUS_FAIL && $check['critical']) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Application
    // -----------------------------------------------------------------------

    /** @return array{name: string, status: string, detail: string, critical: bool} */
    private function checkApplicationKey(): array
    {
        $key = (string) config('app.key');

        if ($key === '') {
            return $this->fail('APP_KEY', 'not set — sessions and encrypted columns cannot work');
        }

        if (! Str::startsWith($key, 'base64:')) {
            return $this->warn('APP_KEY', 'set, but not in the expected base64: form');
        }

        // Length only. The value itself is never reported.
        $decoded = base64_decode(substr($key, 7), true);

        if ($decoded === false || strlen($decoded) < 32) {
            return $this->fail('APP_KEY', 'set, but shorter than the 32 bytes AES-256-CBC requires');
        }

        return $this->pass('APP_KEY', 'present and correctly sized');
    }

    /** @return array{name: string, status: string, detail: string, critical: bool} */
    private function checkApplicationEnvironment(): array
    {
        $environment = (string) config('app.env');
        $debug = (bool) config('app.debug');

        if ($debug && $environment !== 'local' && $environment !== 'testing') {
            return $this->fail('APP_ENV / APP_DEBUG', sprintf('APP_DEBUG is true in the [%s] environment', $environment));
        }

        return $this->pass('APP_ENV / APP_DEBUG', sprintf('%s, debug %s', $environment, $debug ? 'on' : 'off'));
    }

    /** @return array{name: string, status: string, detail: string, critical: bool} */
    private function checkApplicationUrl(): array
    {
        $url = (string) config('app.url');

        if ($url === '' || $url === 'http://localhost') {
            return $this->warn('APP_URL', 'not configured for this environment — generated links will be wrong');
        }

        if (str_starts_with($url, 'http://') && config('app.env') === 'production') {
            return $this->warn('APP_URL', 'production environment is configured with a plain http:// URL');
        }

        return $this->pass('APP_URL', $url);
    }

    /** @return list<array{name: string, status: string, detail: string, critical: bool}> */
    private function checkPhpExtensions(): array
    {
        $missing = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $extension): bool => ! extension_loaded($extension),
        ));

        if ($missing !== []) {
            return [$this->fail('PHP extensions', 'missing: '.implode(', ', $missing))];
        }

        return [$this->pass('PHP extensions', sprintf('all %d present (PHP %s)', count(self::REQUIRED_EXTENSIONS), PHP_VERSION))];
    }

    // -----------------------------------------------------------------------
    // Backing services
    // -----------------------------------------------------------------------

    /** @return array{name: string, status: string, detail: string, critical: bool} */
    private function checkDatabase(): array
    {
        try {
            $row = DB::selectOne('select current_database() as db, version() as version');

            $version = (string) $row->version;
            $shortVersion = preg_match('/PostgreSQL (\d+\.\d+)/', $version, $m) === 1 ? $m[1] : 'unknown';

            // The database name is operational information, not a secret; the credentials are never
            // touched here.
            return $this->pass('PostgreSQL', sprintf('connected to [%s], PostgreSQL %s', $row->db, $shortVersion));
        } catch (Throwable $e) {
            return $this->fail('PostgreSQL', 'cannot connect: '.$this->redact($e->getMessage()));
        }
    }

    /**
     * pgvector. Reported as a failure only once the schema shows the database has been migrated —
     * before that, a missing extension is simply the expected state of an empty server.
     *
     * @return array{name: string, status: string, detail: string, critical: bool}
     */
    private function checkVectorExtension(): array
    {
        try {
            $extension = DB::selectOne("select extversion from pg_extension where extname = 'vector'");

            if ($extension !== null) {
                return $this->pass('pgvector', sprintf('vector extension %s installed', $extension->extversion));
            }

            $migrated = DB::selectOne(
                "select 1 as present from information_schema.tables
                 where table_schema = 'public' and table_name = 'knowledge_item_chunks'",
            );

            if ($migrated === null) {
                return $this->skip('pgvector', 'database not migrated yet — run the migration job first');
            }

            return $this->fail(
                'pgvector',
                'database is migrated but the vector extension is missing. Check that azure.extensions allows VECTOR.',
            );
        } catch (Throwable $e) {
            return $this->fail('pgvector', 'could not be determined: '.$this->redact($e->getMessage()));
        }
    }

    /** @return array{name: string, status: string, detail: string, critical: bool} */
    private function checkRedis(): array
    {
        try {
            Redis::connection('default')->ping();

            // Report the shape of the connection, never the credentials.
            $usesUrl = filled(config('database.redis.default.url'));
            $database = (string) config('database.redis.default.database', '0');
            $cacheDatabase = (string) config('database.redis.cache.database', '0');

            $detail = sprintf(
                'reachable (%s, db %s / cache db %s)',
                $usesUrl ? 'via REDIS_URL' : 'via REDIS_HOST',
                $database,
                $cacheDatabase,
            );

            // Azure Managed Redis exposes one logical database, so a split would break there.
            if ($database !== $cacheDatabase) {
                return $this->warn('Redis', $detail.' — Azure Managed Redis has only database 0');
            }

            return $this->pass('Redis', $detail);
        } catch (Throwable $e) {
            return $this->fail('Redis', 'cannot connect: '.$this->redact($e->getMessage()));
        }
    }

    /**
     * Redis must be authenticated outside local development (security finding F-08).
     *
     * Redis holds sessions, every queue and the cache. Before F-08 it answered PING to anyone who
     * could reach the port, so a foothold on the network meant readable session ids and writable
     * queue payloads.
     *
     * This is deliberately a hard failure rather than a warning: a deployment that reaches
     * production without a Redis credential should stop here instead of quietly running an open
     * Redis. Compose already refuses to start Redis without REDIS_PASSWORD, but a deployment may
     * point at an external Redis, and then this is the only gate.
     *
     * "null" and "true"/"false" are rejected explicitly: they are what a copied .env line leaves
     * behind, and Redis would happily accept the literal string as the password.
     *
     * Never returns the credential itself.
     *
     * @return array{name: string, status: string, detail: string, critical: bool}
     */
    private function checkRedisAuthentication(): array
    {
        $password = (string) config('database.redis.default.password', '');
        $usesUrl = filled(config('database.redis.default.url'));

        // A REDIS_URL can carry the credential in the userinfo part, so an empty password field is
        // not proof of an unauthenticated connection there.
        if ($usesUrl && $password === '') {
            return $this->warn('Redis auth', 'credential expected inside REDIS_URL — not verifiable from config alone');
        }

        $placeholders = ['', 'null', 'nil', 'none', 'true', 'false'];

        if (in_array(mb_strtolower(trim($password)), $placeholders, true)) {
            if (app()->environment('local')) {
                return $this->warn('Redis auth', 'no password set — acceptable locally, never in production');
            }

            return $this->fail('Redis auth', 'REDIS_PASSWORD is missing or a placeholder; Redis holds sessions and queues and must require authentication');
        }

        $cachePassword = (string) config('database.redis.cache.password', '');

        // Both connections point at the same instance, so a mismatch means one of them is
        // unauthenticated or simply broken.
        if ($cachePassword !== $password) {
            return $this->fail('Redis auth', 'the default and cache connections use different credentials for the same instance');
        }

        return $this->pass('Redis auth', 'password configured for both the default and cache connections');
    }

    /**
     * Entra ID configuration, when SSO is switched on.
     *
     * A deployment that enables Entra but is missing the secret or the callback URL must stop here.
     * The alternative — starting anyway — leaves an SSO-only customer with an OIDC route that throws
     * and, if local login was also disabled, no way in at all.
     *
     * Also flags the state where every login method is off, which is a lockout rather than a
     * configuration preference.
     *
     * Never returns the client secret.
     *
     * @return array{name: string, status: string, detail: string, critical: bool}
     */
    private function checkEntraAuthentication(): array
    {
        $config = app(EntraConfig::class);
        $problems = $config->problems();

        if ($problems !== []) {
            return $this->fail('Entra auth', implode('; ', $problems));
        }

        if (! $config->entraEnabled()) {
            return $this->skip('Entra auth', 'disabled; local login is the only method');
        }

        $providers = IdentityProvider::query()
            ->enabled()
            ->where('provider', IdentityProvider::PROVIDER_ENTRA)
            ->count();

        if ($providers === 0) {
            // Enabled with nothing to sign in against: every attempt would fail at the redirect.
            return $this->fail('Entra auth', 'enabled, but no identity provider is configured');
        }

        return $this->pass('Entra auth', sprintf(
            'enabled; %d provider(s); local login %s',
            $providers,
            $config->localLoginEnabled() ? 'also available' : 'disabled',
        ));
    }

    // -----------------------------------------------------------------------
    // Storage
    // -----------------------------------------------------------------------

    /**
     * "Mounted" and "writable" are different claims. This writes a scratch file and removes it again.
     *
     * @return array{name: string, status: string, detail: string, critical: bool}
     */
    private function checkStorageDisk(): array
    {
        $relative = 'runtime-check/'.Str::lower(Str::random(12)).'.probe';

        try {
            $disk = Storage::disk('local');

            $disk->put($relative, 'procynia-runtime-check');

            if ($disk->get($relative) !== 'procynia-runtime-check') {
                $disk->delete($relative);

                return $this->fail('Storage disk', 'wrote a probe file but read back different contents');
            }

            $absolute = $disk->path($relative);
            $disk->delete($relative);

            return $this->pass('Storage disk', sprintf('writable and readable (root %s)', dirname($absolute, 2)));
        } catch (Throwable $e) {
            return $this->fail('Storage disk', 'not writable: '.$this->redact($e->getMessage()));
        }
    }

    /**
     * The document pipeline hands physical paths to external processes, so the storage root has to
     * be a real directory that resolves the same way in every container.
     *
     * @return array{name: string, status: string, detail: string, critical: bool}
     */
    private function checkSharedStoragePath(): array
    {
        try {
            $root = Storage::disk('local')->path('');
            $root = rtrim($root, '/');

            if (! is_dir($root)) {
                return $this->fail('Shared storage path', sprintf('[%s] does not exist', $root));
            }

            if (! is_writable($root)) {
                return $this->fail('Shared storage path', sprintf('[%s] is not writable', $root));
            }

            return $this->pass('Shared storage path', $root);
        } catch (Throwable $e) {
            return $this->fail('Shared storage path', 'could not be resolved: '.$this->redact($e->getMessage()));
        }
    }

    // -----------------------------------------------------------------------
    // External binaries
    // -----------------------------------------------------------------------

    /** @return list<array{name: string, status: string, detail: string, critical: bool}> */
    private function checkExternalBinaries(): array
    {
        $checks = [];

        foreach ([
            'pdftotext' => 'services.pdftotext.binary',
            'pdftohtml' => 'services.pdftohtml.binary',
            'pdfimages' => 'services.pdfimages.binary',
            'pdfinfo' => 'services.pdfinfo.binary',
        ] as $name => $configKey) {
            $binary = (string) config($configKey, '');

            if ($binary === '') {
                $checks[] = $this->fail($name, sprintf('%s is not configured', strtoupper($name).'_BINARY'));

                continue;
            }

            if (! is_executable($binary)) {
                $checks[] = $this->fail($name, sprintf('[%s] is not executable', $binary));

                continue;
            }

            // pdftotext is the one the extraction path always uses, so it is actually invoked.
            if ($name === 'pdftotext') {
                $process = new Process([$binary, '-v'], null, null, null, 15);
                $process->run();

                $output = trim($process->getErrorOutput() ?: $process->getOutput());

                if (! str_contains(strtolower($output), 'pdftotext')) {
                    $checks[] = $this->fail($name, sprintf('[%s] did not identify itself as pdftotext', $binary));

                    continue;
                }

                $checks[] = $this->pass($name, explode("\n", $output)[0]);

                continue;
            }

            $checks[] = $this->pass($name, $binary);
        }

        return $checks;
    }

    // -----------------------------------------------------------------------
    // Runtime contract
    // -----------------------------------------------------------------------

    /** @return array{name: string, status: string, detail: string, critical: bool} */
    private function checkLogging(): array
    {
        $channel = (string) config('logging.default');
        $channels = (array) config('logging.channels', []);

        if (! array_key_exists($channel, $channels)) {
            return $this->fail('Logging', sprintf('LOG_CHANNEL is [%s], which is not a configured channel', $channel));
        }

        try {
            // Prove the channel can actually be built, rather than only that it is named in config.
            Log::channel($channel);
        } catch (Throwable $e) {
            return $this->fail('Logging', sprintf('channel [%s] cannot be resolved: %s', $channel, $this->redact($e->getMessage())));
        }

        if ($channel !== 'stderr' && config('app.env') !== 'local' && config('app.env') !== 'testing') {
            return $this->warn('Logging', sprintf('channel is [%s]; containers should log to stderr', $channel));
        }

        return $this->pass('Logging', sprintf('channel [%s], level [%s]', $channel, (string) config('logging.channels.'.$channel.'.level', 'debug')));
    }

    /**
     * The legacy Compose backup shells out to `docker compose exec -T postgres pg_dump`, which cannot
     * work in Container Apps. In an Azure runtime it must be off.
     *
     * @return array{name: string, status: string, detail: string, critical: bool}
     */
    private function checkLegacyBackup(bool $azure): array
    {
        $enabled = (bool) config('procynia.backup.legacy_enabled', true);

        if (! $azure) {
            return $enabled
                ? $this->pass('Legacy backup', 'enabled — expected outside Azure')
                : $this->pass('Legacy backup', 'disabled');
        }

        if ($enabled) {
            return $this->fail(
                'Legacy backup',
                'PROCYNIA_LEGACY_BACKUP_ENABLED is not false. The Compose backup cannot run in Container Apps.',
            );
        }

        return $this->pass('Legacy backup', 'disabled for this runtime, as Azure requires');
    }

    /** @return array{name: string, status: string, detail: string, critical: bool} */
    private function checkQueueConnection(): array
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            return $this->warn('Queue connection', 'sync — jobs would run inside the web request');
        }

        if ($connection !== 'redis') {
            return $this->warn('Queue connection', sprintf('[%s]; Procynia expects redis', $connection));
        }

        $retryAfter = (int) config('queue.connections.redis.retry_after', 0);

        if ($retryAfter <= 0) {
            return $this->fail('Queue connection', 'redis, but REDIS_QUEUE_RETRY_AFTER is not set');
        }

        return $this->pass('Queue connection', sprintf('redis, retry_after %ds', $retryAfter));
    }

    /**
     * Opt-in only. Every invocation costs an API request, so it is never part of the default run.
     *
     * @return array{name: string, status: string, detail: string, critical: bool}
     */
    private function checkOpenAi(): array
    {
        $key = (string) config('services.openai.api_key', '');

        if ($key === '') {
            return $this->fail('OpenAI connectivity', 'OPENAI_API_KEY is not set');
        }

        try {
            // The models list is the cheapest authenticated endpoint: no tokens are generated.
            $response = app(OpenAiClient::class)->get('models', 20);

            if ($response->successful()) {
                return $this->pass('OpenAI connectivity', 'authenticated request succeeded');
            }

            return $this->fail('OpenAI connectivity', sprintf('HTTP %d from the API', $response->status()));
        } catch (Throwable $e) {
            return $this->fail('OpenAI connectivity', $this->redact($e->getMessage()));
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Exception messages from PDO and Redis can echo back a connection string. Strip anything that
     * looks like a credential before it reaches the terminal or a log.
     */
    private function redact(string $message): string
    {
        $message = preg_replace('/(password=)\S+/i', '$1[redacted]', $message) ?? $message;
        $message = preg_replace('#(tls|tcp|redis|rediss)://[^@\s]+@#i', '$1://[redacted]@', $message) ?? $message;
        $message = preg_replace('/\b(sk-[A-Za-z0-9_-]{8,})/', '[redacted]', $message) ?? $message;

        return Str::limit(trim($message), 200);
    }

    /** @return array{name: string, status: string, detail: string, critical: bool} */
    private function pass(string $name, string $detail): array
    {
        return ['name' => $name, 'status' => self::STATUS_PASS, 'detail' => $detail, 'critical' => false];
    }

    /** @return array{name: string, status: string, detail: string, critical: bool} */
    private function fail(string $name, string $detail, bool $critical = true): array
    {
        return ['name' => $name, 'status' => self::STATUS_FAIL, 'detail' => $detail, 'critical' => $critical];
    }

    /** @return array{name: string, status: string, detail: string, critical: bool} */
    private function warn(string $name, string $detail): array
    {
        return ['name' => $name, 'status' => self::STATUS_WARN, 'detail' => $detail, 'critical' => false];
    }

    /** @return array{name: string, status: string, detail: string, critical: bool} */
    private function skip(string $name, string $detail): array
    {
        return ['name' => $name, 'status' => self::STATUS_SKIP, 'detail' => $detail, 'critical' => false];
    }
}
