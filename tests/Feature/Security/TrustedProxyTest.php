<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Cache\RateLimiter as RateLimiterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Trusted-proxy handling, and the login-throttle bypass it used to enable.
 *
 * Before this, bootstrap/app.php used trustProxies(at: '*'). Every peer counted as a trusted proxy,
 * so Symfony preferred the caller's own X-Forwarded-For over REMOTE_ADDR. Since the /login rate
 * limiter is keyed on email + IP, rotating that header handed the attacker a fresh bucket per value
 * and made F-01's throttle bypassable.
 *
 * The behaviour is now driven by config('procynia.security.trusted_proxies'), empty by default.
 *
 * Both halves are asserted: an untrusted caller must not be able to choose its own IP, and a genuinely
 * trusted proxy must still be able to report the real client — otherwise every user behind Azure
 * ingress would collapse into one address and the limiter would become a global bucket.
 */
class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    /** The peer nginx would report for a request arriving through Docker's published port. */
    private const PEER = '192.168.65.1';

    /** An address the caller asserts but does not control the connection from. */
    private const SPOOFED = '1.2.3.4';

    protected function setUp(): void
    {
        parent::setUp();

        // Same reason as LoginThrottleAndLoggingTest: this suite resolves the real Redis cache rather
        // than the array store phpunit.xml asks for, which would leak limiter counters between runs.
        Config::set('cache.default', 'array');
        Cache::clearResolvedInstances();
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance(RateLimiterService::class);
        RateLimiter::clearResolvedInstances();

        $this->resetProxyTrust();
    }

    protected function tearDown(): void
    {
        // Trusted proxies live in static state on both TrustProxies and Symfony's Request.
        $this->resetProxyTrust();

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // A. A spoofed header from an untrusted caller must be ignored
    // -----------------------------------------------------------------------

    public function test_an_untrusted_caller_cannot_choose_its_own_client_ip(): void
    {
        $request = $this->requestThroughTrustProxies([
            'REMOTE_ADDR' => self::PEER,
            'HTTP_X_FORWARDED_FOR' => self::SPOOFED,
        ]);

        $this->assertSame(
            self::PEER,
            $request->ip(),
            'A caller that is not a trusted proxy must not be able to assert its own client IP.',
        );
        $this->assertNotSame(self::SPOOFED, $request->ip());
    }

    public function test_a_spoofed_chain_from_an_untrusted_caller_is_ignored_entirely(): void
    {
        $request = $this->requestThroughTrustProxies([
            'REMOTE_ADDR' => self::PEER,
            // A caller may send a whole fabricated chain; none of it is trustworthy.
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 5.6.7.8, 9.10.11.12',
        ]);

        $this->assertSame(self::PEER, $request->ip());
    }

    // -----------------------------------------------------------------------
    // B. A genuinely trusted proxy must still deliver the real client
    // -----------------------------------------------------------------------

    public function test_a_trusted_proxy_can_report_the_real_client_ip(): void
    {
        $realClient = '203.0.113.9';

        $request = $this->requestThroughTrustProxies(
            [
                'REMOTE_ADDR' => self::PEER,
                // What the on-prem host proxy produces: the caller's claim, then the address it
                // actually saw, appended.
                'HTTP_X_FORWARDED_FOR' => self::SPOOFED.', '.$realClient,
            ],
            trustedProxies: [self::PEER],
        );

        $this->assertSame(
            $realClient,
            $request->ip(),
            'Behind a trusted proxy the real client must be resolved, not the peer and not the spoof.',
        );
    }

    /**
     * The property that keeps this safe even when a proxy is trusted: the rightmost entry the trusted
     * chain did not vouch for wins, so a value the caller prepended can never be selected.
     */
    public function test_a_trusted_proxy_does_not_let_the_caller_prepend_a_winning_entry(): void
    {
        $request = $this->requestThroughTrustProxies(
            [
                'REMOTE_ADDR' => self::PEER,
                'HTTP_X_FORWARDED_FOR' => self::SPOOFED.', 203.0.113.9',
            ],
            trustedProxies: [self::PEER],
        );

        $this->assertNotSame(self::SPOOFED, $request->ip());
    }

    // -----------------------------------------------------------------------
    // Proxy headers beyond the IP (scheme, host)
    // -----------------------------------------------------------------------

    public function test_an_untrusted_caller_cannot_assert_https(): void
    {
        $request = $this->requestThroughTrustProxies([
            'REMOTE_ADDR' => self::PEER,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->assertFalse(
            $request->isSecure(),
            'X-Forwarded-Proto from an untrusted caller must not make the request look secure.',
        );
    }

    public function test_a_trusted_proxy_can_report_https(): void
    {
        $request = $this->requestThroughTrustProxies(
            [
                'REMOTE_ADDR' => self::PEER,
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ],
            trustedProxies: [self::PEER],
        );

        $this->assertTrue(
            $request->isSecure(),
            'HTTPS detection behind a trusted TLS-terminating proxy must keep working.',
        );
    }

    public function test_an_untrusted_caller_cannot_override_the_host(): void
    {
        $request = $this->requestThroughTrustProxies([
            'REMOTE_ADDR' => self::PEER,
            'HTTP_X_FORWARDED_HOST' => 'evil.example',
        ]);

        $this->assertNotSame('evil.example', $request->getHost());
    }

    // -----------------------------------------------------------------------
    // Configuration contract
    // -----------------------------------------------------------------------

    public function test_the_default_configuration_trusts_no_proxies(): void
    {
        $this->assertSame(
            [],
            (array) config('procynia.security.trusted_proxies'),
            'The safe default is to trust nothing; a proxy must be named explicitly.',
        );
    }

    public function test_the_wildcard_is_no_longer_configured_in_the_bootstrap(): void
    {
        // Comment lines are stripped first: the file explains why the wildcard was removed, and that
        // prose must not be mistaken for a live call.
        $executable = implode("\n", array_filter(
            explode("\n", file_get_contents(base_path('bootstrap/app.php'))),
            static fn (string $line): bool => ! str_starts_with(ltrim($line), '//')
                && ! str_starts_with(ltrim($line), '*')
                && ! str_starts_with(ltrim($line), '/*'),
        ));

        $this->assertStringNotContainsString(
            'trustProxies(',
            $executable,
            'Trusted proxies must come from config, not from a hardcoded call. Trusting every peer is '
            .'what made the client IP forgeable.',
        );
    }

    /**
     * nginx must append the peer it actually accepted the connection from, so the rightmost entry is
     * always observed rather than asserted by the caller.
     */
    public function test_both_nginx_configs_normalise_the_forwarding_headers(): void
    {
        foreach (['docker/nginx/default.conf', 'docker/production/nginx.conf'] as $config) {
            $contents = file_get_contents(base_path($config));

            $this->assertStringContainsString(
                'fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;',
                $contents,
                sprintf('%s must append the observed peer to X-Forwarded-For.', $config),
            );
            $this->assertStringContainsString(
                'fastcgi_param HTTP_X_REAL_IP $remote_addr;',
                $contents,
                sprintf('%s must overwrite X-Real-IP with the observed peer.', $config),
            );
        }
    }

    // -----------------------------------------------------------------------
    // C. The F-01 bypass this all exists to close
    // -----------------------------------------------------------------------

    public function test_rotating_the_forwarded_header_does_not_create_new_limiter_buckets(): void
    {
        $user = $this->createUser('rotate@procynia.test');
        $clientAddress = '203.0.113.55';

        $this->withServerVariables(['REMOTE_ADDR' => $clientAddress]);

        // The same caller, presenting a different forwarded address each time.
        foreach (['1.1.1.1', '2.2.2.2', '3.3.3.3'] as $claimedAddress) {
            $this->withHeaders(['X-Forwarded-For' => $claimedAddress])
                ->from('/login')
                ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $bucket = $this->throttleKey($user->email, $clientAddress);

        $this->assertSame(
            3,
            (int) RateLimiter::attempts($bucket),
            'All three attempts must land in one bucket keyed on the real peer, not one per header value.',
        );

        // And no separate bucket was created for any of the claimed addresses.
        foreach (['1.1.1.1', '2.2.2.2', '3.3.3.3'] as $claimedAddress) {
            $this->assertSame(
                0,
                (int) RateLimiter::attempts($this->throttleKey($user->email, $claimedAddress)),
                sprintf('No bucket may exist for the asserted address %s.', $claimedAddress),
            );
        }
    }

    /**
     * End to end: exhaust the limit while rotating the header, then confirm the correct password is
     * still refused. This is the exact bypass reported against F-01.
     */
    public function test_the_login_throttle_cannot_be_bypassed_by_rotating_the_forwarded_header(): void
    {
        $user = $this->createUser('bypass@procynia.test');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77']);

        $maxAttempts = (int) config('procynia.auth.login.max_attempts');

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->withHeaders(['X-Forwarded-For' => "10.0.0.{$attempt}"])
                ->from('/login')
                ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $response = $this->withHeaders(['X-Forwarded-For' => '10.0.0.99'])
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // -----------------------------------------------------------------------
    // D. Ordinary local traffic still works
    // -----------------------------------------------------------------------

    public function test_a_normal_login_without_forwarding_headers_still_works(): void
    {
        $user = $this->createUser('normal@procynia.test');

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('app.notices.index', ['mode' => 'saved']));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_login_with_a_forwarded_header_still_works_for_a_legitimate_user(): void
    {
        $user = $this->createUser('forwarded@procynia.test');

        $this->withHeaders(['X-Forwarded-For' => '8.8.8.8'])
            ->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('app.notices.index', ['mode' => 'saved']));

        $this->assertAuthenticatedAs($user);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Run a request through the real TrustProxies middleware and hand back the mutated request.
     *
     * @param  array<string, string>  $server
     * @param  list<string>  $trustedProxies
     */
    private function requestThroughTrustProxies(array $server, array $trustedProxies = []): Request
    {
        if ($trustedProxies !== []) {
            TrustProxies::at($trustedProxies);
        }

        $request = Request::create('/', 'GET', server: $server);

        (new TrustProxies)->handle($request, static fn (): Response => new Response);

        return $request;
    }

    private function resetProxyTrust(): void
    {
        TrustProxies::flushState();
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    private function throttleKey(string $email, string $ip): string
    {
        return 'login:'.Str::transliterate(Str::lower(trim($email)).'|'.$ip);
    }

    private function createUser(string $email): User
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        $customer = Customer::query()->create([
            'name' => 'Proxy Test AS',
            'slug' => 'proxy-test-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);

        return User::query()->create([
            'name' => 'Proxy Test User',
            'email' => $email,
            'password' => bcrypt(self::PASSWORD),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
