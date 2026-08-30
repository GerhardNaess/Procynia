<?php

namespace Tests\Feature\Services;

use App\Data\Ai\AiCallContext;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\AiUsageMeter;
use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\Ai\Wiki\Responses\Exceptions\EnterpriseWikiResponseIncompleteException;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiUsageMeterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_successful_provider_attempt_without_prompt_or_response_content(): void
    {
        $customer = $this->createCustomer();
        $meter = app(AiUsageMeter::class);

        $meter->within(new AiCallContext(
            customerId: $customer->id,
            feature: 'wiki',
            operation: 'wiki.ask.answer',
            resourceType: 'enterprise_wiki',
            requestCorrelationId: 'request-test-1',
        ), fn (): array => $meter->measureResponse('gpt-5', fn (): array => [
            'usage' => ['input_tokens' => 123, 'output_tokens' => 45, 'total_tokens' => 168],
            '_meta' => ['request_id' => 'req_test_1'],
        ]));

        $this->assertDatabaseHas('ai_usage_attempts', [
            'customer_id' => $customer->id,
            'feature' => 'wiki',
            'operation_key' => 'wiki.ask.answer',
            'model' => 'gpt-5',
            'status' => 'success',
            'provider_request_id' => 'req_test_1',
            'input_tokens' => 123,
            'output_tokens' => 45,
            'total_tokens' => 168,
        ]);

        $this->assertDatabaseMissing('ai_usage_attempts', [
            'request_correlation_id' => 'What is the deadline?',
        ]);
    }

    public function test_it_marks_an_embedding_timeout_as_uncertain_instead_of_zero_cost_success(): void
    {
        $customer = $this->createCustomer();
        $meter = app(AiUsageMeter::class);

        $meter->within(new AiCallContext(customerId: $customer->id, feature: 'knowledge', operation: 'knowledge.embedding'), fn (): array => $meter->measureEmbedding(
            'text-embedding-3-small',
            fn (): array => ['ok' => false, 'model' => 'text-embedding-3-small', 'usage' => [], 'error_type' => 'timeout'],
        ));

        $this->assertDatabaseHas('ai_usage_attempts', [
            'customer_id' => $customer->id,
            'operation_key' => 'knowledge.embedding',
            'model' => 'text-embedding-3-small',
            'status' => 'uncertain',
            'failure_type' => 'timeout',
        ]);
    }

    public function test_the_openai_responses_client_creates_one_measured_gpt_five_attempt(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://openai.test/v1');
        Http::fake([
            'https://openai.test/v1/responses' => Http::response([
                'id' => 'resp_test_1',
                'status' => 'completed',
                'usage' => ['input_tokens' => 210, 'output_tokens' => 90, 'total_tokens' => 300],
            ], 200, ['x-request-id' => 'req_openai_test_1']),
        ]);

        $customer = $this->createCustomer();
        $meter = app(AiUsageMeter::class);

        $meter->within(new AiCallContext(customerId: $customer->id, feature: 'enterprise_wiki', operation: 'enterprise_wiki.test'), fn (): array => app(OpenAiClient::class)->createResponse([
            'model' => 'gpt-5',
            'input' => [],
        ]));

        $this->assertDatabaseHas('ai_usage_attempts', [
            'customer_id' => $customer->id,
            'feature' => 'enterprise_wiki',
            'operation_key' => 'enterprise_wiki.test',
            'endpoint' => 'responses',
            'model' => 'gpt-5',
            'status' => 'success',
            'provider_request_id' => 'req_openai_test_1',
            'total_tokens' => 300,
        ]);
    }

    public function test_an_invalid_wiki_response_remains_a_failed_attempt_even_when_the_transport_returned_tokens(): void
    {
        $customer = $this->createCustomer();
        $meter = app(AiUsageMeter::class);

        try {
            $meter->within(new AiCallContext(customerId: $customer->id, feature: 'enterprise_wiki', operation: 'enterprise_wiki.verify'), function () use ($meter): void {
                $response = $meter->measureResponse('gpt-5', fn (): array => [
                    'status' => 'incomplete',
                    'usage' => ['input_tokens' => 90, 'output_tokens' => 40, 'total_tokens' => 130],
                ]);

                app(EnterpriseWikiResponsesDecoder::class)->decode($response, 'test');
            });
        } catch (EnterpriseWikiResponseIncompleteException) {
            // Expected: provider ran, but the envelope cannot be used by the Wiki flow.
        }

        $this->assertDatabaseHas('ai_usage_attempts', [
            'customer_id' => $customer->id,
            'operation_key' => 'enterprise_wiki.verify',
            'status' => 'failed',
            'failure_type' => 'invalid_response',
            'total_tokens' => 130,
        ]);
    }

    private function createCustomer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Usage meter AS',
            'slug' => 'usage-meter-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'included_ai_credits' => 3,
        ]);
    }
}
