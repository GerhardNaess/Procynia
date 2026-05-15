<?php

namespace Tests\Feature;

use Tests\TestCase;

class DoffinProductionConfigTest extends TestCase
{
    public function test_doffin_config_has_no_beta_default(): void
    {
        $source = file_get_contents(config_path('doffin.php'));

        $this->assertStringNotContainsString(
            'betaapi.doffin.no',
            $source,
            'config/doffin.php must not contain betaapi.doffin.no as a default value',
        );
    }

    public function test_env_example_sets_live_api_as_doffin_base_url(): void
    {
        $content = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString(
            'DOFFIN_BASE_URL=https://api.doffin.no',
            $content,
            '.env.example must document the live Doffin API as the DOFFIN_BASE_URL value',
        );
    }

    public function test_env_example_does_not_set_beta_as_active_doffin_base_url(): void
    {
        $lines = explode("\n", file_get_contents(base_path('.env.example')));

        foreach ($lines as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }

            $this->assertStringNotContainsString(
                'DOFFIN_BASE_URL=https://betaapi.doffin.no',
                $line,
                '.env.example must not set betaapi.doffin.no as the active DOFFIN_BASE_URL',
            );
        }
    }

    public function test_doffin_api_key_has_no_value_in_env_example(): void
    {
        $lines = explode("\n", file_get_contents(base_path('.env.example')));

        foreach ($lines as $line) {
            if (!str_starts_with(ltrim($line), 'DOFFIN_API_KEY=')) {
                continue;
            }

            $value = trim(explode('=', $line, 2)[1] ?? '');

            $this->assertEmpty(
                $value,
                'DOFFIN_API_KEY must not have a committed value in .env.example',
            );
        }
    }
}
