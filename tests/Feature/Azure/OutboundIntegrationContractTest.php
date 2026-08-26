<?php

namespace Tests\Feature\Azure;

use App\Services\OpenAi\OpenAiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Azure migration readiness — outbound integration contract.
 *
 * In Container Apps every outbound call leaves through Azure egress rather than the developer's
 * machine or the production VM. Three things have to hold before that move:
 *
 *   * every base URL comes from configuration, so nothing points at a host that only exists locally
 *   * every call is HTTPS, because egress crosses the public internet
 *   * every call has a timeout, because a hung connection would occupy a queue worker replica that
 *     cannot scale out
 *
 * No real network call is made. The one HTTP exercise uses Laravel's HTTP fake to inspect the
 * request the client actually builds — that verifies the client's own behaviour without spending
 * money or depending on connectivity. Real connectivity is a staging test.
 */
class OutboundIntegrationContractTest extends TestCase
{
    public function test_every_outbound_base_url_is_configuration_driven_and_https(): void
    {
        $baseUrls = [
            'services.openai.base_url' => config('services.openai.base_url'),
            'doffin.base_url' => config('doffin.base_url'),
            'doffin.live_search_base_url' => config('doffin.live_search_base_url'),
            'doffin.public_notice_base_url' => config('doffin.public_notice_base_url'),
        ];

        foreach ($baseUrls as $key => $url) {
            $this->assertIsString($url, sprintf('%s is not configured.', $key));
            $this->assertNotSame('', trim((string) $url), sprintf('%s is empty.', $key));

            $this->assertStringStartsWith(
                'https://',
                (string) $url,
                sprintf('%s must use HTTPS: outbound traffic from Container Apps crosses the public internet.', $key),
            );

            $host = (string) parse_url((string) $url, PHP_URL_HOST);

            $this->assertNotContains(
                $host,
                ['localhost', '127.0.0.1', '0.0.0.0', 'host.docker.internal'],
                sprintf('%s points at a local host [%s], which does not exist in Azure.', $key, $host),
            );
        }
    }

    /**
     * The config files must read these from the environment rather than hardcoding a value, so the
     * Container App environment variables and Key Vault references actually take effect.
     */
    public function test_integration_configuration_is_read_from_the_environment(): void
    {
        $services = file_get_contents(config_path('services.php'));
        $doffin = file_get_contents(config_path('doffin.php'));

        foreach ([
            "env('OPENAI_API_KEY')" => $services,
            "env('OPENAI_BASE_URL'" => $services,
            "env('DOFFIN_API_KEY')" => $doffin,
            "env('DOFFIN_BASE_URL')" => $doffin,
        ] as $needle => $source) {
            $this->assertStringContainsString(
                $needle,
                $source,
                sprintf('Configuration must read %s from the environment.', $needle),
            );
        }
    }

    /**
     * Doffin timeouts must exist and be finite. A queue worker replica is a fixed resource in Azure:
     * a hung outbound call blocks it until the job timeout kills the whole job.
     */
    public function test_doffin_calls_have_finite_timeouts(): void
    {
        $timeout = (int) config('doffin.timeout');
        $connectTimeout = (int) config('doffin.connect_timeout');

        $this->assertGreaterThan(0, $timeout, 'doffin.timeout must be a positive number of seconds.');
        $this->assertGreaterThan(0, $connectTimeout, 'doffin.connect_timeout must be positive.');
        $this->assertLessThanOrEqual(
            $timeout,
            $connectTimeout,
            'The connect timeout must not exceed the overall request timeout.',
        );
    }

    /**
     * The OpenAI client must apply a timeout and target the configured base URL. Verified against
     * the request the client really builds, using the HTTP fake — no network traffic, no cost.
     */
    public function test_the_openai_client_applies_a_timeout_and_the_configured_base_url(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response(['id' => 'resp_test', 'output' => []], 200),
        ]);

        /** @var OpenAiClient $client */
        $client = app(OpenAiClient::class);
        $client->post('responses', ['model' => 'gpt-4.1-mini'], 42);

        Http::assertSent(function (Request $request): bool {
            $this->assertStringStartsWith(
                rtrim((string) config('services.openai.base_url'), '/'),
                $request->url(),
                'The OpenAI client must call the configured base URL.',
            );

            $this->assertStringStartsWith('https://', $request->url(), 'OpenAI calls must be HTTPS.');

            return true;
        });

        // And the timeout is genuinely part of the request the client builds.
        $source = file_get_contents(app_path('Services/OpenAi/OpenAiClient.php'));

        $this->assertStringContainsString(
            '->timeout($timeoutSeconds)',
            $source,
            'OpenAiClient must apply an explicit request timeout.',
        );
        $this->assertStringContainsString(
            'CURLOPT_CONNECTTIMEOUT',
            $source,
            'OpenAiClient must apply an explicit connect timeout.',
        );
    }

    /**
     * A failing upstream must surface as a handled failure, not a hung worker. Exercised against the
     * real client with a faked 500 response.
     */
    public function test_an_upstream_failure_is_handled_rather_than_hanging(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'upstream unavailable']], 500),
        ]);

        /** @var OpenAiClient $client */
        $client = app(OpenAiClient::class);

        $threw = false;

        try {
            $client->createResponse(['model' => 'gpt-4.1-mini']);
        } catch (\Throwable $e) {
            $threw = true;
            $this->assertNotSame('', $e->getMessage(), 'A failed OpenAI call must carry a diagnostic message.');
        }

        $this->assertTrue($threw, 'A 500 from OpenAI must surface as an exception the job can fail on.');
    }

    /**
     * Stripe is installed (laravel/cashier) but billing is not built. The Azure secret contract
     * therefore leaves the Stripe bindings off by default. Assert that the configuration is still
     * environment-driven, so enabling it later needs no code change.
     */
    public function test_stripe_configuration_is_environment_driven_even_though_billing_is_not_built(): void
    {
        $cashier = file_get_contents(config_path('cashier.php'));

        foreach (["env('STRIPE_KEY')", "env('STRIPE_SECRET')"] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $cashier,
                sprintf('config/cashier.php must read %s from the environment.', $needle),
            );
        }

        $this->assertStringContainsString(
            'param includeStripeSecrets bool = false',
            file_get_contents(base_path('infra/main.bicep')),
            'The Azure secret contract must keep the Stripe bindings off until billing exists.',
        );
    }

    /**
     * OpenAI stays an external service in this phase — no Azure OpenAI migration.
     */
    public function test_openai_remains_an_external_service_in_the_azure_contract(): void
    {
        $main = file_get_contents(base_path('infra/main.bicep'));

        $this->assertStringNotContainsString(
            'Microsoft.CognitiveServices',
            $main,
            'This migration phase must not introduce Azure OpenAI.',
        );

        $this->assertStringContainsString(
            'OPENAI-API-KEY',
            $main,
            'The OpenAI API key must still be delivered as a Key Vault secret.',
        );
    }
}
