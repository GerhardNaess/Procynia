<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Session cookie hardening (security finding F-06).
 *
 * The original finding read "SESSION_SECURE_COOKIE has no default", which was true but understated
 * what the default actually did. Laravel shipped `env('SESSION_SECURE_COOKIE')`, resolving to null,
 * and null is not "insecure" — Symfony reads it as "match the request", so the cookie was Secure over
 * HTTPS and plain over HTTP.
 *
 * The real exposure was narrower and worse: in production TLS terminates at nginx or Container Apps
 * ingress and the app is reached over plain HTTP with X-Forwarded-Proto: https. Laravel only trusts
 * that header for proxies in the trusted list, and that list is empty by default (finding F-01). On a
 * deployment where TRUSTED_PROXIES was never set, the session cookie shipped WITHOUT Secure while the
 * browser connection was HTTPS.
 *
 * These tests pin the fix: outside local the flag is true independently of how the request looks, so
 * a missing TRUSTED_PROXIES can no longer downgrade the cookie.
 *
 * The suite runs with APP_ENV=testing, which is not local — so config('session.secure') here is the
 * production value, and that is deliberately what these tests assert.
 */
class SessionCookieSecurityTest extends TestCase
{
    private function sessionCookie(TestResponse $response): ?Cookie
    {
        return collect($response->headers->getCookies())
            ->first(fn (Cookie $cookie) => $cookie->getName() === config('session.cookie'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Keep these tests about the cookie, not about session storage.
        Config::set('session.driver', 'array');
    }

    public function test_the_production_default_is_secure_even_when_the_env_variable_is_missing(): void
    {
        // The F-06 regression test. SESSION_SECURE_COOKIE is not set in the test environment, so this
        // asserts the *default*, which is the thing that was missing.
        $this->assertTrue(
            config('session.secure'),
            'Outside local, a missing SESSION_SECURE_COOKIE must still produce a Secure cookie.',
        );
    }

    public function test_the_cookie_is_secure_over_https(): void
    {
        $cookie = $this->sessionCookie($this->get('https://localhost/login'));

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
    }

    public function test_a_terminated_tls_request_from_an_untrusted_proxy_still_gets_a_secure_cookie(): void
    {
        // This is the case the finding was really about. The request reaches the app over plain HTTP
        // carrying X-Forwarded-Proto: https, and no proxy is trusted — so Laravel considers it
        // insecure. Before the fix the cookie came back without Secure; it must not any more.
        $response = $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://localhost/login');

        $this->assertFalse(
            $response->baseRequest->isSecure(),
            'Precondition: with no trusted proxy Laravel must still regard this request as insecure.',
        );

        $cookie = $this->sessionCookie($response);

        $this->assertNotNull($cookie);
        $this->assertTrue(
            $cookie->isSecure(),
            'The Secure flag must not depend on runtime HTTPS detection.',
        );
    }

    public function test_the_cookie_keeps_its_other_hardening_attributes(): void
    {
        $cookie = $this->sessionCookie($this->get('https://localhost/login'));

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly(), 'JavaScript must not be able to read the session id.');
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
    }

    public function test_local_development_over_plain_http_is_not_broken(): void
    {
        // In local the default stays null, which Symfony reads as "match the request": no Secure flag
        // on plain http://localhost, so a developer can still log in.
        Config::set('session.secure', null);

        $cookie = $this->sessionCookie($this->get('http://localhost/login'));

        $this->assertNotNull($cookie, 'A session cookie must still be issued over local HTTP.');
        $this->assertFalse($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
    }

    public function test_local_development_over_https_still_gets_a_secure_cookie(): void
    {
        // The useful half of the old null behaviour is preserved: null follows the request, so a
        // developer running HTTPS locally is not downgraded.
        Config::set('session.secure', null);

        $cookie = $this->sessionCookie($this->get('https://localhost/login'));

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
    }

    public function test_an_explicit_override_is_still_honoured(): void
    {
        // Deliberately still possible: an operator debugging an unusual ingress may need it. F-06 was
        // about the variable being *forgotten*, and a forgotten variable now lands on the safe side.
        Config::set('session.secure', false);

        $cookie = $this->sessionCookie($this->get('https://localhost/login'));

        $this->assertNotNull($cookie);
        $this->assertFalse($cookie->isSecure());
    }

    public function test_the_config_default_is_environment_aware_and_not_a_bare_env_call(): void
    {
        // Structural guard. Without this, someone restoring Laravel's stock line
        // `'secure' => env('SESSION_SECURE_COOKIE')` would silently reopen F-06, and every
        // behavioural test above would still pass in the testing environment.
        $config = file_get_contents(config_path('session.php'));

        $this->assertStringNotContainsString(
            "'secure' => env('SESSION_SECURE_COOKIE'),",
            $config,
            'The bare env() default is what F-06 was about; it must not come back.',
        );

        $this->assertMatchesRegularExpression(
            "/'secure' => env\(\s*'SESSION_SECURE_COOKIE',\s*env\('APP_ENV'\) === 'local' \? null : true,\s*\)/",
            $config,
        );
    }
}
