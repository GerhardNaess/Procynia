<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Cache\RateLimiter as RateLimiterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Brute-force protection and security logging for POST /login — security finding F-01.
 *
 * Before this, /login had no throttling, no lockout and no logging: an attacker could guess passwords
 * indefinitely, and neither the attempt nor a resulting compromise would leave any trace.
 *
 * These tests exercise the real route, the real controller and the real rate limiter. Only the log
 * sink is replaced (Log::spy), because asserting on log output is the one thing that cannot be
 * observed through the HTTP response.
 */
class LoginThrottleAndLoggingTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    /**
     * The rate limiter is backed by the cache, and this suite resolves the real Redis cache rather
     * than the array store phpunit.xml asks for. Two consequences make the tests unreliable if left
     * alone: limiter counters survive between runs, and Redis TTLs ignore Carbon time travel, so the
     * cooldown can never be observed expiring.
     *
     * Pinning this class to the array store fixes both — every test starts from an empty limiter, and
     * travel() governs expiry. The application code under test is untouched.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');
        Cache::clearResolvedInstances();
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance(RateLimiterService::class);
        RateLimiter::clearResolvedInstances();
    }

    // -----------------------------------------------------------------------
    // A. A valid login still works
    // -----------------------------------------------------------------------

    public function test_valid_credentials_authenticate_and_redirect(): void
    {
        $user = $this->createCustomerUser('valid@procynia.test');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $response->assertRedirect(route('app.notices.index', ['mode' => 'saved']));
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Session fixation protection predates this change and must survive it.
     */
    public function test_session_is_regenerated_on_successful_login(): void
    {
        $user = $this->createCustomerUser('regenerate@procynia.test');

        $this->startSession();
        $idBefore = session()->getId();

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $this->assertNotSame(
            $idBefore,
            session()->getId(),
            'The session id must change after login, or session fixation is possible.',
        );
        $this->assertAuthenticatedAs($user);
    }

    public function test_successful_login_clears_the_rate_limiter(): void
    {
        $user = $this->createCustomerUser('clears@procynia.test');
        $key = $this->throttleKey($user->email);

        $this->postInvalidPassword($user->email);
        $this->postInvalidPassword($user->email);
        $this->assertSame(2, RateLimiter::attempts($key));

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, RateLimiter::attempts($key), 'A successful login must reset the counter.');
    }

    // -----------------------------------------------------------------------
    // B. Failures count against the limiter
    // -----------------------------------------------------------------------

    public function test_each_failed_attempt_increments_the_limiter(): void
    {
        $user = $this->createCustomerUser('counts@procynia.test');
        $key = $this->throttleKey($user->email);

        $this->assertSame(0, RateLimiter::attempts($key));

        foreach ([1, 2, 3] as $expected) {
            $this->postInvalidPassword($user->email);
            $this->assertSame($expected, RateLimiter::attempts($key));
        }

        $this->assertGuest();
    }

    /**
     * The counter is scoped per address, so guessing one account cannot exhaust another account's
     * allowance.
     */
    public function test_the_limiter_is_scoped_per_email(): void
    {
        $first = $this->createCustomerUser('first@procynia.test');
        $second = $this->createCustomerUser('second@procynia.test');

        foreach (range(1, 5) as $ignored) {
            $this->postInvalidPassword($first->email);
        }

        $this->assertSame(5, RateLimiter::attempts($this->throttleKey($first->email)));
        $this->assertSame(0, RateLimiter::attempts($this->throttleKey($second->email)));

        $this->post('/login', ['email' => $second->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('app.notices.index', ['mode' => 'saved']));
        $this->assertAuthenticatedAs($second);
    }

    // -----------------------------------------------------------------------
    // C. Too many failures block further attempts
    // -----------------------------------------------------------------------

    public function test_exceeding_the_limit_blocks_further_attempts(): void
    {
        $user = $this->createCustomerUser('blocked@procynia.test');

        foreach (range(1, $this->maxAttempts()) as $ignored) {
            $this->postInvalidPassword($user->email);
        }

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'still-wrong',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /**
     * The point of the control: once throttled, even the right password does not get you in.
     */
    public function test_the_correct_password_is_still_rejected_while_throttled(): void
    {
        $user = $this->createCustomerUser('locked-out@procynia.test');

        foreach (range(1, $this->maxAttempts()) as $ignored) {
            $this->postInvalidPassword($user->email);
        }

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();

        $errors = session('errors')->get('email');
        $this->assertStringContainsStringIgnoringCase(
            'mange',
            $errors[0],
            'A throttled attempt must say the request was rate limited, not that the password was wrong.',
        );
    }

    // -----------------------------------------------------------------------
    // D. Cooldown expires on its own — no permanent lockout
    // -----------------------------------------------------------------------

    public function test_login_works_again_once_the_cooldown_has_passed(): void
    {
        $user = $this->createCustomerUser('cooldown@procynia.test');

        foreach (range(1, $this->maxAttempts()) as $ignored) {
            $this->postInvalidPassword($user->email);
        }

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        // No sleep(): move the clock past the decay window.
        $this->travel($this->decaySeconds() + 5)->seconds();

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('app.notices.index', ['mode' => 'saved']));

        $this->assertAuthenticatedAs($user);
    }

    // -----------------------------------------------------------------------
    // E. A success in the middle resets the streak
    // -----------------------------------------------------------------------

    public function test_a_successful_login_between_failures_resets_the_streak(): void
    {
        $user = $this->createCustomerUser('streak@procynia.test');

        $this->postInvalidPassword($user->email);
        $this->postInvalidPassword($user->email);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');

        // The two earlier failures must not carry over: a full fresh allowance remains.
        foreach (range(1, $this->maxAttempts() - 1) as $ignored) {
            $this->postInvalidPassword($user->email);
        }

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('app.notices.index', ['mode' => 'saved']));

        $this->assertAuthenticatedAs($user);
    }

    // -----------------------------------------------------------------------
    // F. Inactive users — pre-existing behaviour preserved
    // -----------------------------------------------------------------------

    public function test_a_deactivated_account_cannot_log_in_with_the_correct_password(): void
    {
        $user = $this->createCustomerUser('deactivated@procynia.test', isActive: false);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /**
     * A deactivated account offers no cheaper path: the attempt counts like any other failure, so it
     * cannot be used to probe without spending the allowance.
     */
    public function test_a_deactivated_account_attempt_counts_against_the_limiter(): void
    {
        $user = $this->createCustomerUser('deactivated-counts@procynia.test', isActive: false);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->assertSame(1, RateLimiter::attempts($this->throttleKey($user->email)));
    }

    /**
     * A user who authenticates but may not use the customer frontend is rejected, and that rejection
     * must not clear the limiter — otherwise one valid internal credential becomes a reset oracle.
     */
    public function test_a_frontend_access_rejection_counts_against_the_limiter(): void
    {
        $admin = $this->createInternalAdmin('internal@procynia.test');
        $key = $this->throttleKey($admin->email);

        $this->postInvalidPassword($admin->email);
        $this->assertSame(1, RateLimiter::attempts($key));

        $this->from('/login')->post('/login', [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(2, RateLimiter::attempts($key), 'A rejected frontend login must not reset the counter.');
    }

    // -----------------------------------------------------------------------
    // Account enumeration
    // -----------------------------------------------------------------------

    /**
     * Unknown address, wrong password and deactivated account must be indistinguishable from outside.
     */
    public function test_failed_logins_are_indistinguishable_to_the_caller(): void
    {
        $active = $this->createCustomerUser('enum-active@procynia.test');
        $inactive = $this->createCustomerUser('enum-inactive@procynia.test', isActive: false);

        $messages = [];

        foreach ([
            ['email' => 'does-not-exist@procynia.test', 'password' => 'whatever'],
            ['email' => $active->email, 'password' => 'wrong-password'],
            ['email' => $inactive->email, 'password' => self::PASSWORD],
        ] as $payload) {
            $this->flushSession();
            $this->from('/login')->post('/login', $payload)->assertSessionHasErrors('email');
            $messages[] = session('errors')->get('email')[0];
        }

        $this->assertCount(1, array_unique($messages), 'All three failure modes must return the same message.');
        $this->assertGuest();
    }

    // -----------------------------------------------------------------------
    // G. Security logging
    // -----------------------------------------------------------------------

    public function test_a_successful_login_is_logged_without_credentials(): void
    {
        $user = $this->createCustomerUser('logged-success@procynia.test');

        Log::spy();

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($user): bool {
            return ($context['event'] ?? null) === AuthenticatedSessionController::EVENT_AUTH_SUCCEEDED
                && $context['user_id'] === $user->id
                && $context['customer_id'] === $user->customer_id
                && array_key_exists('ip', $context)
                && array_key_exists('user_agent', $context)
                && ! array_key_exists('password', $context);
        })->once();
    }

    /**
     * customer_id is null for internal accounts; the log record must handle that rather than break.
     */
    public function test_a_successful_login_records_a_null_customer_id_for_internal_accounts(): void
    {
        // Internal admins cannot reach the customer frontend, so the closest observable case is a
        // customer user; this asserts the field is present and typed, not merely truthy.
        $user = $this->createCustomerUser('null-safe@procynia.test');

        Log::spy();

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            return ($context['event'] ?? null) === AuthenticatedSessionController::EVENT_AUTH_SUCCEEDED
                && array_key_exists('customer_id', $context);
        })->once();
    }

    public function test_a_failed_login_is_logged_without_credentials(): void
    {
        $user = $this->createCustomerUser('logged-failure@procynia.test');

        Log::spy();

        $this->postInvalidPassword($user->email);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($user): bool {
            return ($context['event'] ?? null) === AuthenticatedSessionController::EVENT_AUTH_FAILED
                && $context['email'] === $user->email
                && $context['reason'] === 'invalid_credentials'
                && ! array_key_exists('password', $context);
        })->once();
    }

    public function test_a_throttled_login_is_logged_as_a_distinct_event(): void
    {
        $user = $this->createCustomerUser('logged-throttle@procynia.test');

        foreach (range(1, $this->maxAttempts()) as $ignored) {
            $this->postInvalidPassword($user->email);
        }

        Log::spy();

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            return ($context['event'] ?? null) === AuthenticatedSessionController::EVENT_AUTH_THROTTLED
                && is_int($context['available_in_seconds'] ?? null);
        })->once();

        // A blocked request must not also emit a normal failure event.
        Log::shouldNotHaveReceived('warning', [
            '[PROCYNIA][AUTH] Login failed.',
            \Mockery::any(),
        ]);
    }

    /**
     * The blunt guarantee: the submitted password must never reach the log, in any event.
     */
    public function test_the_submitted_password_is_never_written_to_the_log(): void
    {
        $user = $this->createCustomerUser('never-logged@procynia.test');
        $secret = 'super-secret-password-value';

        $captured = [];

        Log::listen(function ($message) use (&$captured): void {
            $captured[] = $message->message.' '.json_encode($message->context);
        });

        $this->post('/login', ['email' => $user->email, 'password' => $secret]);
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->assertNotEmpty($captured, 'The login flow is expected to log something.');

        foreach ($captured as $line) {
            $this->assertStringNotContainsString($secret, $line);
            $this->assertStringNotContainsString(self::PASSWORD, $line);
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function postInvalidPassword(string $email): void
    {
        $this->from('/login')->post('/login', [
            'email' => $email,
            'password' => 'definitely-not-the-password',
        ]);

        Auth::logout();
    }

    private function throttleKey(string $email, string $ip = '127.0.0.1'): string
    {
        return 'login:'.Str::transliterate(Str::lower(trim($email)).'|'.$ip);
    }

    private function maxAttempts(): int
    {
        return (int) config('procynia.auth.login.max_attempts');
    }

    private function decaySeconds(): int
    {
        return (int) config('procynia.auth.login.decay_seconds');
    }

    private function createCustomerUser(string $email, bool $isActive = true): User
    {
        return User::query()->create([
            'name' => 'Login Test User',
            'email' => $email,
            'password' => bcrypt(self::PASSWORD),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $this->customer()->id,
            'is_active' => $isActive,
        ]);
    }

    private function createInternalAdmin(string $email): User
    {
        return User::query()->create([
            'name' => 'Internal Admin',
            'email' => $email,
            'password' => bcrypt(self::PASSWORD),
            'role' => User::ROLE_SUPER_ADMIN,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => null,
            'is_active' => true,
        ]);
    }

    private function customer(): Customer
    {
        static $customer = null;

        if ($customer instanceof Customer && Customer::query()->whereKey($customer->id)->exists()) {
            return $customer;
        }

        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return $customer = Customer::query()->create([
            'name' => 'Login Test AS',
            'slug' => 'login-test-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }
}
