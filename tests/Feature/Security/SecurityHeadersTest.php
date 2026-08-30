<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Global HTTP security headers (security finding F-05).
 *
 * Before this, Procynia sent none of these on any route. These tests pin the policy itself, not just
 * the presence of a header name: a CSP that says `default-src *` would satisfy assertHeader() and
 * protect nothing.
 *
 * The tests run in the `testing` environment, so AddSecurityHeaders takes the production branch and
 * asserts the enforcing policy that actually ships — not whatever a developer happens to have
 * running locally.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function customerUser(): User
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
            'name' => 'Security Headers AS',
            'slug' => 'security-headers-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);

        return User::query()->create([
            'name' => 'Security Headers User',
            'email' => 'security.headers@procynia.test',
            'password' => bcrypt('SecurityHeaders123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /** @return array<string, string> */
    private function cspDirectives(string $policy): array
    {
        $directives = [];

        foreach (array_filter(array_map('trim', explode(';', $policy))) as $part) {
            $bits = preg_split('/\s+/', $part, 2);
            $directives[$bits[0]] = $bits[1] ?? '';
        }

        return $directives;
    }

    public function test_the_login_page_carries_the_baseline_headers(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Frame-Options', 'DENY');

        $this->assertStringContainsString(
            'camera=()',
            $response->headers->get('Permissions-Policy', ''),
        );
    }

    public function test_browser_features_procynia_does_not_use_are_denied(): void
    {
        $policy = $this->get('/login')->headers->get('Permissions-Policy', '');

        foreach (['camera', 'microphone', 'geolocation', 'payment', 'usb'] as $feature) {
            $this->assertStringContainsString(
                $feature.'=()',
                $policy,
                "Permissions-Policy should deny {$feature}.",
            );
        }
    }

    public function test_the_content_security_policy_is_enforcing_and_restrictive(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('Content-Security-Policy');
        $this->assertNull(
            $response->headers->get('Content-Security-Policy-Report-Only'),
            'The shipping policy must enforce, not merely report.',
        );

        $directives = $this->cspDirectives($response->headers->get('Content-Security-Policy'));

        $this->assertSame("'self'", $directives['default-src'] ?? null);
        $this->assertSame("'none'", $directives['object-src'] ?? null);
        $this->assertSame("'self'", $directives['base-uri'] ?? null);
        $this->assertSame("'none'", $directives['frame-ancestors'] ?? null);
        $this->assertSame("'self'", $directives['form-action'] ?? null);
        $this->assertSame("'self'", $directives['connect-src'] ?? null);

        // The AI document preview frames a same-origin PDF, so frame-src must allow self — which is
        // separate from frame-ancestors denying everyone the right to frame us.
        $this->assertSame("'self'", $directives['frame-src'] ?? null);
    }

    public function test_the_customer_frontend_allows_no_inline_or_eval_script(): void
    {
        $directives = $this->cspDirectives(
            $this->get('/login')->headers->get('Content-Security-Policy'),
        );

        $this->assertSame("'self'", $directives['script-src'] ?? null);
        $this->assertStringNotContainsString('unsafe-inline', $directives['script-src'] ?? '');
        $this->assertStringNotContainsString('unsafe-eval', $directives['script-src'] ?? '');
    }

    public function test_the_policy_contains_no_wildcard_sources(): void
    {
        $policy = $this->get('/login')->headers->get('Content-Security-Policy');

        foreach ($this->cspDirectives($policy) as $directive => $value) {
            $this->assertNotSame('*', trim($value), "{$directive} must not be a wildcard.");
            $this->assertStringNotContainsString('http://*', $value);
            $this->assertStringNotContainsString('https://*', $value);
        }
    }

    public function test_the_admin_panel_gets_the_wider_script_policy_it_needs(): void
    {
        $directives = $this->cspDirectives(
            $this->get('/admin/login')->headers->get('Content-Security-Policy'),
        );

        // Filament renders inline <script> and bundles Alpine, which evaluates with new Function.
        $this->assertStringContainsString("'unsafe-inline'", $directives['script-src'] ?? '');
        $this->assertStringContainsString("'unsafe-eval'", $directives['script-src'] ?? '');

        // The relaxation is scoped: the structural protections still hold on /admin.
        $this->assertSame("'none'", $directives['object-src'] ?? null);
        $this->assertSame("'none'", $directives['frame-ancestors'] ?? null);
        $this->assertSame("'self'", $directives['base-uri'] ?? null);
    }

    public function test_the_admin_relaxation_does_not_leak_into_the_customer_frontend(): void
    {
        $customer = $this->cspDirectives(
            $this->get('/login')->headers->get('Content-Security-Policy'),
        );

        $this->assertStringNotContainsString('unsafe-eval', $customer['script-src'] ?? '');
        $this->assertStringNotContainsString('ui-avatars.com', $customer['img-src'] ?? '');
    }

    public function test_hsts_is_sent_over_https(): void
    {
        $response = $this->get('https://localhost/login');

        $this->assertStringContainsString(
            'max-age=31536000',
            $response->headers->get('Strict-Transport-Security', ''),
        );
    }

    public function test_hsts_never_carries_preload(): void
    {
        // preload is a one-way door: getting off the browser preload list takes months. It must be a
        // deliberate operational decision, never a side effect of this middleware.
        $this->assertStringNotContainsString(
            'preload',
            $this->get('https://localhost/login')->headers->get('Strict-Transport-Security', ''),
        );
    }

    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        // Emitting HSTS in local HTTP development would pin http://localhost to HTTPS in the
        // developer's browser, which is painful to undo.
        $this->assertNull(
            $this->get('http://localhost/login')->headers->get('Strict-Transport-Security'),
        );
    }

    public function test_an_authenticated_app_route_is_covered(): void
    {
        $response = $this->actingAs($this->customerUser())->get('/app/dashboard');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Content-Security-Policy');
    }

    public function test_non_html_responses_keep_the_simple_headers_but_not_the_csp(): void
    {
        // CSP governs how a browser parses a document. On a JSON payload it is bytes without effect,
        // while nosniff genuinely matters there.
        $response = $this->postJson('/stripe/webhook', []);

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_the_legacy_xss_protection_header_is_not_reintroduced(): void
    {
        // X-XSS-Protection is deprecated and its filter has itself been a source of vulnerabilities.
        // CSP replaces it; this asserts nobody adds it back out of habit.
        $this->assertNull(
            $this->get('/login')->headers->get('X-XSS-Protection'),
        );
    }
}
