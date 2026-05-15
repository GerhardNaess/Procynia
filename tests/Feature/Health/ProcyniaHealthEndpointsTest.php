<?php

namespace Tests\Feature\Health;

use App\Models\BillingEvent;
use App\Models\Customer;
use App\Models\DoffinImportRun;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\RequirementExtractionRun;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiDocument;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcyniaHealthEndpointsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_health_endpoints_require_the_shared_token(): void
    {
        config(['procynia.health_token' => 'secret-token']);

        foreach ($this->healthUris() as $uri) {
            $this->getJson($uri)
                ->assertStatus(403)
                ->assertExactJson([
                    'status' => 'fail',
                    'message' => 'Forbidden',
                ]);

            $this->withHeaders(['X-Procynia-Health-Token' => 'wrong-token'])
                ->getJson($uri)
                ->assertStatus(403)
                ->assertExactJson([
                    'status' => 'fail',
                    'message' => 'Forbidden',
                ]);
        }
    }

    public function test_health_endpoints_return_503_when_token_is_not_configured(): void
    {
        config(['procynia.health_token' => '']);

        foreach ($this->healthUris() as $uri) {
            $this->withHeaders(['X-Procynia-Health-Token' => 'any-token'])
                ->getJson($uri)
                ->assertStatus(503)
                ->assertExactJson([
                    'status' => 'fail',
                    'message' => 'Health token is not configured',
                ]);
        }
    }

    public function test_doffin_import_freshness_returns_ok_for_recent_successful_import(): void
    {
        config(['procynia.health_token' => 'secret-token']);
        Carbon::setTestNow(Carbon::parse('2026-05-14 12:00:00', 'UTC'));

        DoffinImportRun::query()->create([
            'trigger' => 'scheduler',
            'started_at' => now()->subMinutes(20),
            'finished_at' => now()->subMinutes(15),
            'status' => 'success',
        ]);

        $response = $this->withHeaders(['X-Procynia-Health-Token' => 'secret-token'])
            ->getJson('/health/integrations/doffin/import-freshness');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'doffin_import_freshness')
            ->assertJsonPath('threshold_minutes', 90)
            ->assertJsonStructure([
                'status',
                'service',
                'checked_at',
                'last_success_at',
                'threshold_minutes',
                'message',
            ]);
    }

    public function test_doffin_import_freshness_returns_fail_for_stale_successful_import(): void
    {
        config(['procynia.health_token' => 'secret-token']);
        Carbon::setTestNow(Carbon::parse('2026-05-14 12:00:00', 'UTC'));

        DoffinImportRun::query()->create([
            'trigger' => 'scheduler',
            'started_at' => now()->subHours(3),
            'finished_at' => now()->subHours(2),
            'status' => 'success',
        ]);

        $response = $this->withHeaders(['X-Procynia-Health-Token' => 'secret-token'])
            ->getJson('/health/integrations/doffin/import-freshness');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('service', 'doffin_import_freshness')
            ->assertJsonPath('threshold_minutes', 90)
            ->assertJsonPath('last_success_at', now()->subHours(2)->toIso8601String());
    }

    public function test_openai_connectivity_returns_ok_when_the_api_responds(): void
    {
        config([
            'procynia.health_token' => 'secret-token',
            'services.openai.api_key' => 'sk-test',
            'services.openai.base_url' => 'https://api.openai.com/v1',
        ]);

        Http::fake([
            'https://api.openai.com/v1/models' => Http::response([
                'data' => [],
            ], 200),
        ]);

        $response = $this->withHeaders(['X-Procynia-Health-Token' => 'secret-token'])
            ->getJson('/health/integrations/openai');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'openai_connectivity')
            ->assertJsonStructure([
                'status',
                'service',
                'checked_at',
                'response_time_ms',
                'message',
            ]);
    }

    public function test_openai_connectivity_returns_fail_when_the_api_key_is_missing(): void
    {
        config([
            'procynia.health_token' => 'secret-token',
            'services.openai.api_key' => '',
            'services.openai.base_url' => 'https://api.openai.com/v1',
        ]);

        $response = $this->withHeaders(['X-Procynia-Health-Token' => 'secret-token'])
            ->getJson('/health/integrations/openai');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('service', 'openai_connectivity')
            ->assertJsonPath('message', 'OpenAI API key is not configured.');
    }

    public function test_stripe_webhook_health_returns_ok_when_local_webhook_events_exist_and_no_failures_exist(): void
    {
        config(['procynia.health_token' => 'secret-token']);
        Carbon::setTestNow(Carbon::parse('2026-05-14 12:00:00', 'UTC'));

        BillingEvent::query()->create([
            'event_type' => 'subscription_recalculated',
            'source' => 'webhook',
            'description' => 'Webhook event processed.',
        ]);

        $response = $this->withHeaders(['X-Procynia-Health-Token' => 'secret-token'])
            ->getJson('/health/integrations/stripe/webhooks');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'stripe_webhooks')
            ->assertJsonStructure([
                'status',
                'service',
                'checked_at',
                'last_processed_at',
                'failed_count',
                'pending_count',
                'message',
            ]);
    }

    public function test_stripe_webhook_health_returns_fail_when_failed_webhooks_exist(): void
    {
        config(['procynia.health_token' => 'secret-token']);
        Carbon::setTestNow(Carbon::parse('2026-05-14 12:00:00', 'UTC'));

        BillingEvent::query()->create([
            'event_type' => 'subscription_recalculated',
            'source' => 'webhook',
            'description' => 'Webhook event processed.',
        ]);

        $table = (string) config('queue.failed.table', 'failed_jobs');
        DB::table($table)->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\StripeWebhookDelivery',
            ], JSON_UNESCAPED_SLASHES),
            'exception' => 'Stripe webhook failed.',
            'failed_at' => now()->subMinutes(5),
        ]);

        $response = $this->withHeaders(['X-Procynia-Health-Token' => 'secret-token'])
            ->getJson('/health/integrations/stripe/webhooks');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('service', 'stripe_webhooks')
            ->assertJsonPath('failed_count', 1);
    }

    public function test_document_parsing_returns_ok_when_recent_success_exists_and_no_recent_failures_exist(): void
    {
        config(['procynia.health_token' => 'secret-token']);
        Carbon::setTestNow(Carbon::parse('2026-05-14 12:00:00', 'UTC'));

        $customer = $this->createCustomer('Health Customer');

        $savedNotice = SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'external_id' => 'notice-health-1',
            'title' => 'Health notice',
        ]);

        $document = SavedNoticeAiDocument::query()->create([
            'saved_notice_id' => $savedNotice->id,
            'original_filename' => 'health-doc.pdf',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/documents/health-doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED,
            'text_extracted_at' => now()->subMinutes(8),
            'processing_finished_at' => now()->subMinutes(8),
        ]);

        RequirementExtractionRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'saved_notice_id' => $savedNotice->id,
            'saved_notice_ai_document_id' => $document->id,
            'status' => RequirementExtractionRun::STATUS_COMPLETED,
            'strategy' => RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION,
            'prompt_version' => 'test',
            'model' => 'gpt-4.1-mini',
            'candidate_count' => 1,
            'persisted_requirement_count' => 1,
            'openai_call_count' => 1,
            'input_tokens_total' => 100,
            'output_tokens_total' => 50,
            'total_tokens_total' => 150,
            'queued_at' => now()->subMinutes(10),
            'started_at' => now()->subMinutes(9),
            'finished_at' => now()->subMinutes(8),
            'last_heartbeat_at' => now()->subMinutes(8),
        ]);

        $response = $this->withHeaders(['X-Procynia-Health-Token' => 'secret-token'])
            ->getJson('/health/documents/parsing');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'document_parsing')
            ->assertJsonStructure([
                'status',
                'service',
                'checked_at',
                'last_success_at',
                'failed_count_last_60_minutes',
                'message',
            ]);
    }

    public function test_document_parsing_returns_fail_when_recent_failed_jobs_exist(): void
    {
        config(['procynia.health_token' => 'secret-token']);
        Carbon::setTestNow(Carbon::parse('2026-05-14 12:00:00', 'UTC'));

        $table = (string) config('queue.failed.table', 'failed_jobs');
        DB::table($table)->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'ai-requirements',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\Ai\\Requirements\\ProcessRequirementExtractionRun',
            ], JSON_UNESCAPED_SLASHES),
            'exception' => 'Requirement extraction job failed.',
            'failed_at' => now()->subMinutes(15),
        ]);

        $response = $this->withHeaders(['X-Procynia-Health-Token' => 'secret-token'])
            ->getJson('/health/documents/parsing');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('service', 'document_parsing')
            ->assertJsonPath('failed_count_last_60_minutes', 1);
    }

    /**
     * Purpose: Provide the canonical health endpoint URIs for shared protection assertions.
     * Inputs: None.
     * Returns: The list of monitored health endpoint paths.
     * Side effects: None.
     *
     * @return array<int, string>
     */
    private function healthUris(): array
    {
        return [
            '/health/integrations/doffin/import-freshness',
            '/health/integrations/openai',
            '/health/integrations/stripe/webhooks',
            '/health/documents/parsing',
        ];
    }

    /**
     * Purpose: Create a valid customer record for health endpoint fixtures.
     * Inputs: Optional customer name.
     * Returns: A persisted customer record with required nationality and language relations.
     * Side effects: Creates reference rows when they do not already exist.
     */
    private function createCustomer(string $name = 'Procynia AS'): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => '🇳🇴'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }

    /**
     * Purpose: Configure the application to use the project PostgreSQL test database.
     * Inputs: None.
     * Returns: None.
     * Side effects: Resets the default connection to procynia_test.
     */
    private function useProjectPostgresConnection(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'procynia_test',
            'cache.default' => 'array',
            'session.driver' => 'array',
        ]);
    }
}
