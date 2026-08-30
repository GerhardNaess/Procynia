<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Security event names emitted by the login flow. Kept as constants so that monitoring queries
     * and the tests reference the same strings the application actually writes.
     */
    public const EVENT_AUTH_SUCCEEDED = 'authentication_succeeded';

    public const EVENT_AUTH_FAILED = 'authentication_failed';

    public const EVENT_AUTH_THROTTLED = 'authentication_throttled';

    /**
     * Why a login attempt was rejected.
     *
     * REASON_INVALID_CREDENTIALS deliberately covers three cases at once — unknown address, wrong
     * password, and deactivated account — because Auth::attempt() is called with is_active => true,
     * which collapses all three into one indistinguishable failure. Splitting them in the log would
     * reconstruct exactly the distinction the HTTP response is careful not to make.
     */
    private const REASON_INVALID_CREDENTIALS = 'invalid_credentials';

    /**
     * Credentials were correct, but the account may not use the customer frontend (internal admins).
     * Safe to record distinctly: reaching this branch already requires the correct password, so it
     * tells an attacker nothing they did not already know, and it lets an operator tell a misdirected
     * admin login apart from password guessing.
     */
    private const REASON_FRONTEND_ACCESS_DENIED = 'customer_frontend_access_denied';

    public function create(): Response|RedirectResponse
    {
        if (Auth::check()) {
            $user = Auth::user();

            return $user && method_exists($user, 'canAccessCustomerFrontend') && $user->canAccessCustomerFrontend()
                ? redirect()->route('app.notices.index', ['mode' => 'saved'])
                : redirect()->route('filament.admin.pages.dashboard');
        }

        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = $this->throttleKey($request, $credentials['email']);

        $this->ensureIsNotRateLimited($request, $throttleKey, $credentials['email']);

        // is_active is part of the credential match on purpose: a correct password for a deactivated
        // account fails here, indistinguishably from a wrong password, and counts against the limiter
        // like any other failure. There is no cheaper path for an attacker and nothing is revealed.
        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_active' => true,
        ], $request->boolean('remember'))) {
            $this->recordFailedAttempt($request, $throttleKey, $credentials['email'], self::REASON_INVALID_CREDENTIALS);

            throw ValidationException::withMessages([
                'email' => __('procynia.frontend.invalid_credentials'),
            ]);
        }

        $request->session()->regenerate();

        $user = $request->user();

        if (! $user instanceof User || ! $user->canAccessCustomerFrontend()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Counted as a failure even though the password was correct. The limiter must not become
            // a reset oracle: if this branch cleared it, anyone holding one valid non-frontend
            // credential could wipe the counter at will.
            $this->recordFailedAttempt($request, $throttleKey, $credentials['email'], self::REASON_FRONTEND_ACCESS_DENIED);

            throw ValidationException::withMessages([
                'email' => __('procynia.frontend.customer_access_required'),
            ]);
        }

        RateLimiter::clear($throttleKey);

        Log::info('[PROCYNIA][AUTH] Login succeeded.', [
            'event' => self::EVENT_AUTH_SUCCEEDED,
            'user_id' => $user->id,
            'customer_id' => $user->customer_id,
            'ip' => $this->clientIp($request),
            'user_agent' => $this->userAgent($request),
        ]);

        return redirect()->intended(route('app.notices.index', ['mode' => 'saved']));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Rate limiter key for one login attempt.
     *
     * Scoped by address and client IP together, so that guessing one account from one origin cannot
     * lock out that account from every other origin. The password is never part of the key.
     */
    private function throttleKey(Request $request, string $email): string
    {
        return 'login:'.Str::transliterate(Str::lower(trim($email)).'|'.$this->clientIp($request));
    }

    /**
     * Reject the attempt when the limiter is exhausted, before any credential check runs.
     *
     * Called exactly once per request and throws immediately, so a single blocked request produces a
     * single throttle event rather than one per validation pass.
     */
    private function ensureIsNotRateLimited(Request $request, string $throttleKey, string $email): void
    {
        if (! RateLimiter::tooManyAttempts($throttleKey, $this->maxAttempts())) {
            return;
        }

        $seconds = RateLimiter::availableIn($throttleKey);

        Log::warning('[PROCYNIA][AUTH] Login throttled.', [
            'event' => self::EVENT_AUTH_THROTTLED,
            'email' => $this->normalisedEmail($email),
            'ip' => $this->clientIp($request),
            'user_agent' => $this->userAgent($request),
            'available_in_seconds' => $seconds,
        ]);

        // The wait time is safe to disclose: it is a property of the request origin, not of any
        // account, and it is the same whether or not the address exists.
        throw ValidationException::withMessages([
            'email' => __('procynia.frontend.too_many_login_attempts', ['seconds' => $seconds]),
        ]);
    }

    private function recordFailedAttempt(Request $request, string $throttleKey, string $email, string $reason): void
    {
        RateLimiter::hit($throttleKey, $this->decaySeconds());

        Log::warning('[PROCYNIA][AUTH] Login failed.', [
            'event' => self::EVENT_AUTH_FAILED,
            'email' => $this->normalisedEmail($email),
            'ip' => $this->clientIp($request),
            'user_agent' => $this->userAgent($request),
            'reason' => $reason,
        ]);
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('procynia.auth.login.max_attempts', 5));
    }

    private function decaySeconds(): int
    {
        return max(1, (int) config('procynia.auth.login.decay_seconds', 60));
    }

    private function normalisedEmail(string $email): string
    {
        return Str::limit(Str::lower(trim($email)), 191, '');
    }

    /**
     * Client IP as Laravel resolves it.
     *
     * Note: bootstrap/app.php trusts every proxy, so this value comes from X-Forwarded-For when that
     * header is present. See docs/operations/security.md for the trusted-proxy limitation this
     * carries for the IP half of the throttle key.
     */
    private function clientIp(Request $request): string
    {
        return $request->ip() ?? 'unknown';
    }

    /**
     * Truncated so that an oversized or hostile User-Agent cannot bloat the log record. The value is
     * written as structured context, never interpolated into the message.
     */
    private function userAgent(Request $request): string
    {
        return Str::limit((string) $request->userAgent(), 255, '');
    }
}
