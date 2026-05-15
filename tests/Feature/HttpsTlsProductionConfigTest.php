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

    public function test_bootstrap_trusts_proxy_forwarded_headers(): void
    {
        $source = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString(
            'trustProxies',
            $source,
            'bootstrap/app.php must configure trustProxies so the app recognises HTTPS behind a reverse proxy',
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
