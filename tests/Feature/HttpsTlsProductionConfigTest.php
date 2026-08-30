<?php

namespace Tests\Feature;

use Tests\TestCase;

class HttpsTlsProductionConfigTest extends TestCase
{
    public function test_production_deploy_guide_requires_https(): void
    {
        $content = file_get_contents(base_path('docs/operations/production-deploy.md'));

        $this->assertStringContainsString(
            'HTTPS',
            $content,
            'docs/operations/production-deploy.md must require HTTPS for production',
        );
    }

    public function test_production_deploy_guide_documents_http_to_https_redirect(): void
    {
        $content = file_get_contents(base_path('docs/operations/production-deploy.md'));

        $this->assertStringContainsString(
            'return 301 https://',
            $content,
            'docs/operations/production-deploy.md must document HTTP to HTTPS redirect',
        );
    }

    public function test_production_deploy_guide_documents_reverse_proxy(): void
    {
        $content = file_get_contents(base_path('docs/operations/production-deploy.md'));

        $this->assertStringContainsString(
            'proxy_pass',
            $content,
            'docs/operations/production-deploy.md must document reverse proxy configuration',
        );
    }

    public function test_production_deploy_guide_documents_x_forwarded_proto(): void
    {
        $content = file_get_contents(base_path('docs/operations/production-deploy.md'));

        $this->assertStringContainsString(
            'X-Forwarded-Proto',
            $content,
            'docs/operations/production-deploy.md must document X-Forwarded-Proto header',
        );
    }

    public function test_production_deploy_guide_documents_app_url_https(): void
    {
        $content = file_get_contents(base_path('docs/operations/production-deploy.md'));

        $this->assertStringContainsString(
            'APP_URL=https://',
            $content,
            'docs/operations/production-deploy.md must document APP_URL set to https://',
        );
    }

    public function test_production_deploy_guide_documents_http_redirect_verification(): void
    {
        $content = file_get_contents(base_path('docs/operations/production-deploy.md'));

        $this->assertStringContainsString(
            'curl -I http://app.procynia.no',
            $content,
            'docs/operations/production-deploy.md must include concrete HTTP redirect verification command',
        );
    }

    public function test_production_deploy_guide_documents_https_verification(): void
    {
        $content = file_get_contents(base_path('docs/operations/production-deploy.md'));

        $this->assertStringContainsString(
            'curl -I https://app.procynia.no',
            $content,
            'docs/operations/production-deploy.md must include concrete HTTPS verification command',
        );
    }

    /**
     * Trusted proxies are configuration, not a hardcoded wildcard.
     *
     * This assertion used to look for the string "trustProxies" anywhere in bootstrap/app.php. After
     * the trusted-proxy fix that file no longer configures trusted proxies at all — it only explains
     * in a comment why trustProxies(at: '*') was removed — so the old check stayed green while
     * verifying nothing. Comment lines are therefore stripped before anything is asserted.
     */
    public function test_trusted_proxies_come_from_configuration_and_not_from_a_wildcard(): void
    {
        $executable = implode("\n", array_filter(
            explode("\n", file_get_contents(base_path('bootstrap/app.php'))),
            static fn (string $line): bool => ! str_starts_with(ltrim($line), '//')
                && ! str_starts_with(ltrim($line), '*')
                && ! str_starts_with(ltrim($line), '/*'),
        ));

        $this->assertStringNotContainsString(
            'trustProxies(',
            $executable,
            'bootstrap/app.php must not configure trusted proxies directly; the wildcard it used to '
            .'set made the client IP forgeable.',
        );

        $this->assertContains(
            'trusted_proxies',
            array_keys(config('procynia.security')),
            'Trusted proxies must be configurable through procynia.security.trusted_proxies.',
        );

        $this->assertStringContainsString(
            "env('TRUSTED_PROXIES'",
            file_get_contents(config_path('procynia.php')),
            'The trusted proxy list must be settable per environment via TRUSTED_PROXIES.',
        );

        $this->assertStringContainsString(
            'TrustProxies::at(',
            file_get_contents(app_path('Providers/AppServiceProvider.php')),
            'The configured list must actually be applied to the TrustProxies middleware.',
        );
    }

    public function test_env_example_has_production_comment_for_app_url(): void
    {
        $content = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString(
            'APP_URL=https://',
            $content,
            '.env.example must document that APP_URL must use https:// in production',
        );
    }
}
