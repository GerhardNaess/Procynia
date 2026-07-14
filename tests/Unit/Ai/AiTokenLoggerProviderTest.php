<?php

namespace Tests\Unit\Ai;

use App\Models\AiTokenEvent;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\AiTokenLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

class AiTokenLoggerProviderTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useProjectPostgresConnection();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::disconnect(DB::getDefaultConnection());
        parent::tearDown();
    }

    public function test_provider_field_is_stored_when_supplied(): void
    {
        $logger = app(AiTokenLogger::class);
        $customer = $this->createCustomer();

        $logger->record([
            'customer_id' => $customer->id,
            'operation_key' => 'test_op',
            'model' => 'gpt-4.1',
            'provider' => 'openai',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
        ]);

        $event = AiTokenEvent::query()->where('customer_id', $customer->id)->first();
        $this->assertNotNull($event);
        $this->assertSame('openai', $event->provider);
    }

    public function test_deployment_name_and_region_are_stored(): void
    {
        $logger = app(AiTokenLogger::class);
        $customer = $this->createCustomer();

        $logger->record([
            'customer_id' => $customer->id,
            'operation_key' => 'test_op',
            'model' => 'gpt-4.1',
            'provider' => 'azure_openai',
            'deployment_name' => 'my-gpt4-deployment',
            'provider_region' => 'norwayeast',
            'input_tokens' => 200,
            'output_tokens' => 80,
            'total_tokens' => 280,
        ]);

        $event = AiTokenEvent::query()->where('customer_id', $customer->id)->first();
        $this->assertNotNull($event);
        $this->assertSame('azure_openai', $event->provider);
        $this->assertSame('my-gpt4-deployment', $event->deployment_name);
        $this->assertSame('norwayeast', $event->provider_region);
    }

    public function test_provider_falls_back_to_config_when_not_supplied(): void
    {
        config(['services.openai.provider_key' => 'openai']);

        $logger = app(AiTokenLogger::class);
        $customer = $this->createCustomer();

        $logger->record([
            'customer_id' => $customer->id,
            'operation_key' => 'test_op',
            'model' => 'gpt-4.1',
            'input_tokens' => 50,
            'output_tokens' => 25,
            'total_tokens' => 75,
        ]);

        $event = AiTokenEvent::query()->where('customer_id', $customer->id)->first();
        $this->assertNotNull($event);
        $this->assertSame('openai', $event->provider, 'Provider must fall back to config when not explicitly provided.');
    }

    public function test_existing_token_logging_still_works_without_provider(): void
    {
        $logger = app(AiTokenLogger::class);
        $customer = $this->createCustomer();

        $logger->record([
            'customer_id' => $customer->id,
            'operation_key' => 'saved_notice_requirement_answer_draft',
            'model' => 'gpt-4.1',
            'input_tokens' => 1000,
            'output_tokens' => 400,
            'total_tokens' => 1400,
        ]);

        $event = AiTokenEvent::query()->where('customer_id', $customer->id)->first();
        $this->assertNotNull($event);
        $this->assertSame(1400, $event->total_tokens);
    }

    private function createCustomer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Provider Test AS',
            'slug' => 'provider-test-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
