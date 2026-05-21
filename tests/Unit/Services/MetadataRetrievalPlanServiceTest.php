<?php

namespace Tests\Unit\Services;

use App\Services\Ai\Retrieval\MetadataRetrievalPlanService;
use App\Services\OpenAi\OpenAiClient;
use Mockery;
use Tests\TestCase;

class MetadataRetrievalPlanServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_returns_a_structured_plan_and_only_uses_declared_metadata_fields(): void
    {
        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                $inputPayload = json_decode((string) data_get($payload, 'input.1.content.0.text', ''), true);
                $schema = data_get($payload, 'text.format.schema', []);
                $selectedMetadataSchema = data_get($schema, 'properties.selected_metadata', []);
                $selectedMetadataProperties = data_get($selectedMetadataSchema, 'properties', []);
                $selectedMetadataRequired = data_get($selectedMetadataSchema, 'required', []);
                $selectedMetadataPropertyKeys = array_values(array_filter(
                    array_keys(is_array($selectedMetadataProperties) ? $selectedMetadataProperties : []),
                    static fn (string|int $field): bool => is_string($field) && trim($field) !== '',
                ));

                return data_get($payload, 'text.format.name') === 'metadata_retrieval_plan'
                    && data_get($selectedMetadataSchema, 'type') === 'object'
                    && $selectedMetadataRequired === $selectedMetadataPropertyKeys
                    && data_get($selectedMetadataProperties, 'topic.anyOf.0.type') === 'array'
                    && data_get($selectedMetadataProperties, 'topic.anyOf.1.type') === 'null'
                    && data_get($selectedMetadataProperties, 'sub_topic.anyOf.0.type') === 'array'
                    && data_get($selectedMetadataProperties, 'sub_topic.anyOf.1.type') === 'null'
                    && data_get($selectedMetadataProperties, 'keywords.anyOf.0.type') === 'array'
                    && data_get($selectedMetadataProperties, 'keywords.anyOf.1.type') === 'null'
                    && is_array($inputPayload)
                    && data_get($inputPayload, 'question') === 'Hvilke metadata passer best?'
                    && data_get($inputPayload, 'metadata_map.fields.topic.0') === 'Tema A'
                    && data_get($inputPayload, 'metadata_map.fields.sub_topic.0') === 'Underemne A'
                    && data_get($inputPayload, 'metadata_map.fields.keywords.0') === 'Nøkkelord A'
                    && str_contains((string) data_get($inputPayload, 'instructions.0', ''), 'Choose only values that appear in metadata_map.fields.');
            }))
            ->andReturn([
                'id' => 'resp_metadata_plan_1',
                'output_text' => json_encode([
                    'selected_metadata' => [
                        'topic' => ['Tema A'],
                        'sub_topic' => ['Underemne A'],
                        'keywords' => ['Nøkkelord A'],
                    ],
                    'search_text' => 'tema a nøkkelord a',
                    'intent_summary' => 'Bruker ser etter metadata som matcher tema a.',
                    'confidence' => 0.87,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

        $service = new MetadataRetrievalPlanService($client);

        $plan = $service->buildPlan('Hvilke metadata passer best?', [
            'fields' => [
                'topic' => ['Tema A', 'Tema B'],
                'sub_topic' => ['Underemne A', 'Underemne B'],
                'keywords' => ['Nøkkelord A', 'Nøkkelord B'],
            ],
            'field_counts' => [
                'topic' => 2,
                'sub_topic' => 2,
                'keywords' => 2,
            ],
        ]);

        $this->assertSame([
            'selected_metadata' => [
                'topic' => ['Tema A'],
                'sub_topic' => ['Underemne A'],
                'keywords' => ['Nøkkelord A'],
            ],
            'search_text' => 'tema a nøkkelord a',
            'intent_summary' => 'Bruker ser etter metadata som matcher tema a.',
            'confidence' => 0.87,
        ], $plan);
    }
}
