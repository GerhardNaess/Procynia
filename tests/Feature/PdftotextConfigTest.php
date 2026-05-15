<?php

namespace Tests\Feature;

use Tests\TestCase;

class PdftotextConfigTest extends TestCase
{
    public function test_services_config_has_no_hardcoded_pdftotext_path(): void
    {
        $source = file_get_contents(config_path('services.php'));

        $this->assertStringNotContainsString(
            '/usr/local/bin/pdftotext',
            $source,
            'config/services.php must not contain a hardcoded pdftotext path',
        );

        $this->assertStringNotContainsString(
            '/opt/homebrew/bin/pdftotext',
            $source,
            'config/services.php must not contain a hardcoded local Mac pdftotext path',
        );
    }

    public function test_services_config_reads_pdftotext_binary_from_env(): void
    {
        $source = file_get_contents(config_path('services.php'));

        $this->assertStringContainsString(
            'PDFTOTEXT_BINARY',
            $source,
            'config/services.php must read pdftotext binary path from PDFTOTEXT_BINARY env var',
        );
    }

    public function test_env_example_documents_pdftotext_binary(): void
    {
        $content = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString(
            'PDFTOTEXT_BINARY=',
            $content,
            '.env.example must document the PDFTOTEXT_BINARY variable',
        );
    }

    public function test_env_example_does_not_set_hardcoded_mac_path_as_active_value(): void
    {
        $lines = explode("\n", file_get_contents(base_path('.env.example')));

        foreach ($lines as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }

            $this->assertStringNotContainsString(
                'PDFTOTEXT_BINARY=/opt/homebrew/bin/pdftotext',
                $line,
                '.env.example must not set a Mac-only Homebrew path as the active PDFTOTEXT_BINARY value',
            );

            $this->assertStringNotContainsString(
                'PDFTOTEXT_BINARY=/usr/local/bin/pdftotext',
                $line,
                '.env.example must not set /usr/local/bin/pdftotext as the active PDFTOTEXT_BINARY value',
            );
        }
    }

    public function test_document_text_extractor_does_not_hardcode_pdftotext_path(): void
    {
        $source = file_get_contents(app_path('Services/DocumentTextExtractor.php'));

        $this->assertStringNotContainsString(
            '/usr/local/bin/pdftotext',
            $source,
            'DocumentTextExtractor.php must not hardcode a pdftotext binary path',
        );

        $this->assertStringNotContainsString(
            '/opt/homebrew/bin/pdftotext',
            $source,
            'DocumentTextExtractor.php must not hardcode a local Mac pdftotext binary path',
        );
    }

    public function test_document_text_extractor_reads_binary_from_config(): void
    {
        $source = file_get_contents(app_path('Services/DocumentTextExtractor.php'));

        $this->assertStringContainsString(
            "config('services.pdftotext.binary')",
            $source,
            'DocumentTextExtractor.php must read pdftotext binary path from config',
        );
    }

    public function test_document_text_extractor_logs_warning_when_binary_not_configured(): void
    {
        $source = file_get_contents(app_path('Services/DocumentTextExtractor.php'));

        $this->assertStringContainsString(
            'pdftotext binary is not configured',
            $source,
            'DocumentTextExtractor.php must log a warning when pdftotext binary is not configured',
        );
    }

    public function test_dockerfile_installs_poppler_utils(): void
    {
        $source = file_get_contents(base_path('docker/php/Dockerfile'));

        $this->assertStringContainsString(
            'poppler-utils',
            $source,
            'docker/php/Dockerfile must install poppler-utils to provide the pdftotext binary',
        );
    }

    public function test_pdftotext_binary_config_returns_null_without_env_var(): void
    {
        config(['services.pdftotext.binary' => null]);

        $this->assertNull(
            config('services.pdftotext.binary'),
            'pdftotext binary config must return null when not configured, not a hardcoded fallback path',
        );
    }
}
