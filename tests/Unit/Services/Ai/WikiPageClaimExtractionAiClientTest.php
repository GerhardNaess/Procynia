<?php

namespace Tests\Unit\Services\Ai;

use App\Models\EnterpriseWikiClaim;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WikiPageClaimExtractionAiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_payload_contract(): void
    {
        $payload = $this->capturePayload();

        $this->assertSame('gpt-4.1-mini', $payload['model']);
        $this->assertSame(4000, $payload['max_output_tokens']);
        $this->assertFalse($payload['store']);
        $this->assertArrayNotHasKey('reasoning', $payload);
        $this->assertSame('json_schema', data_get($payload, 'text.format.type'));
        $this->assertTrue(data_get($payload, 'text.format.strict'));
    }

    public function test_ordinary_extract_claims_schema_does_not_include_manual_origin_fields(): void
    {
        $payload = $this->capturePayload();
        $properties = data_get($payload, 'text.format.schema.properties.claims.items.properties');

        $this->assertArrayNotHasKey('content_origin', $properties);
        $this->assertArrayNotHasKey('source_element_keys', $properties);
        $this->assertArrayNotHasKey('best_practice_reason', $properties);
        $this->assertSame(
            ['text', 'confidence', 'excerpt', 'conflict_note'],
            data_get($payload, 'text.format.schema.properties.claims.items.required'),
        );
    }

    public function test_returns_valid_claims_and_filters_malformed_elements(): void
    {
        $client = $this->clientReturning(['claims' => [
            ['text' => 'Gyldig.', 'confidence' => 'high', 'excerpt' => 'Gyldig.', 'conflict_note' => null],
            'not-an-array',
            ['text' => '', 'confidence' => 'high', 'excerpt' => '', 'conflict_note' => null],
            ['text' => 'Feil confidence.', 'confidence' => 'certain', 'excerpt' => 'x', 'conflict_note' => null],
        ]]);

        $result = $client->extractClaims('Side', 'article', 'Innhold', 'no');

        $this->assertCount(1, $result['claims']);
        $this->assertSame('Gyldig.', $result['claims'][0]['text']);
    }

    public function test_manual_mixed_block_payload_contract_uses_explicit_origin_schema(): void
    {
        $payload = $this->captureManualMixedBlockPayload();

        $this->assertSame('wiki_page_claim_extraction_manual_mixed_block', data_get($payload, 'text.format.name'));
        $this->assertSame(
            [
                EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            ],
            data_get($payload, 'text.format.schema.properties.claims.items.properties.content_origin.enum'),
        );
        $this->assertSame(
            [
                'text',
                'confidence',
                'excerpt',
                'content_origin',
                'source_element_keys',
                'best_practice_reason',
                'conflict_note',
            ],
            data_get($payload, 'text.format.schema.properties.claims.items.required'),
        );
        $this->assertStringContainsString('Content block key: block-0002', data_get($payload, 'input.1.content.0.text'));
        $this->assertStringContainsString('[source-alpha] (paragraph) Alpha source text.', data_get($payload, 'input.1.content.0.text'));
    }

    public function test_manual_mixed_block_variant_accepts_all_allowed_origins(): void
    {
        $client = $this->clientReturning(['claims' => [
            [
                'text' => 'Kunden har en rutine.',
                'confidence' => 'high',
                'excerpt' => 'Kunden har en rutine.',
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                'source_element_keys' => ['source-alpha'],
                'best_practice_reason' => null,
                'conflict_note' => null,
            ],
            [
                'text' => 'Det bør etableres en fast kontroll.',
                'confidence' => 'medium',
                'excerpt' => 'Det bør etableres en fast kontroll.',
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                'source_element_keys' => [],
                'best_practice_reason' => 'Normativ anbefaling.',
                'conflict_note' => null,
            ],
            [
                'text' => 'Kunden har en ukildet kontroll.',
                'confidence' => 'low',
                'excerpt' => 'Kunden har en ukildet kontroll.',
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
                'source_element_keys' => [],
                'best_practice_reason' => null,
                'conflict_note' => null,
            ],
        ]]);

        $result = $this->extractManualMixedBlock($client);

        $this->assertSame([
            EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
        ], array_column($result['claims'], 'content_origin'));
    }

    #[DataProvider('invalidManualMixedBlockOriginsProvider')]
    public function test_manual_mixed_block_variant_rejects_invalid_origins(string $origin): void
    {
        $client = $this->clientReturning(['claims' => [[
            'text' => 'Kunden har en rutine.',
            'confidence' => 'high',
            'excerpt' => 'Kunden har en rutine.',
            'content_origin' => $origin,
            'source_element_keys' => [],
            'best_practice_reason' => null,
            'conflict_note' => null,
        ]]]);

        $this->expectException(RuntimeException::class);

        $this->extractManualMixedBlock($client);
    }

    /**
     * @param  list<string>  $sourceElementKeys
     */
    #[DataProvider('invalidManualMixedBlockKeySetsProvider')]
    public function test_manual_mixed_block_variant_rejects_invalid_key_sets(string $origin, array $sourceElementKeys, ?string $bestPracticeReason): void
    {
        $client = $this->clientReturning(['claims' => [[
            'text' => 'Kunden har en rutine.',
            'confidence' => 'high',
            'excerpt' => 'Kunden har en rutine.',
            'content_origin' => $origin,
            'source_element_keys' => $sourceElementKeys,
            'best_practice_reason' => $bestPracticeReason,
            'conflict_note' => null,
        ]]]);

        $this->expectException(RuntimeException::class);

        $this->extractManualMixedBlock($client);
    }

    public function test_manual_mixed_block_variant_rejects_whole_response_when_one_claim_is_invalid(): void
    {
        $client = $this->clientReturning(['claims' => [
            [
                'text' => 'Kunden har en rutine.',
                'confidence' => 'high',
                'excerpt' => 'Kunden har en rutine.',
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                'source_element_keys' => ['source-alpha'],
                'best_practice_reason' => null,
                'conflict_note' => null,
            ],
            [
                'text' => 'Ugyldig claim.',
                'confidence' => 'high',
                'excerpt' => 'Ugyldig claim.',
                'content_origin' => 'mixed',
                'source_element_keys' => [],
                'best_practice_reason' => null,
                'conflict_note' => null,
            ],
        ]]);

        $this->expectException(RuntimeException::class);

        $this->extractManualMixedBlock($client);
    }

    public static function invalidManualMixedBlockOriginsProvider(): array
    {
        return [
            'mixed is block provenance only' => ['mixed'],
            'unclassified is not allowed' => [EnterpriseWikiClaim::CONTENT_ORIGIN_UNCLASSIFIED],
            'unknown origin' => ['customer_fact'],
        ];
    }

    public static function invalidManualMixedBlockKeySetsProvider(): array
    {
        return [
            'source_based without keys' => [EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, [], null],
            'source_based with unknown key' => [EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, ['source-missing'], null],
            'source_based with best-practice reason' => [EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, ['source-alpha'], 'Ikke tillatt.'],
            'best_practice with source key' => [EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, ['source-alpha'], 'Normativt.'],
            'best_practice without reason' => [EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, [], null],
            'unsupported with source key' => [EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, ['source-alpha'], null],
            'unsupported with best-practice reason' => [EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, [], 'Ikke tillatt.'],
        ];
    }

    private function capturePayload(): array
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['claims' => []]);
            });
        });

        app(WikiPageClaimExtractionAiClient::class)->extractClaims('Side', 'article', 'Innhold', 'no');

        return $captured;
    }

    private function captureManualMixedBlockPayload(): array
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['claims' => []]);
            });
        });

        $this->extractManualMixedBlock(app(WikiPageClaimExtractionAiClient::class));

        return $captured;
    }

    private function clientReturning(array $body): WikiPageClaimExtractionAiClient
    {
        $this->mock(OpenAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('createResponse')->once()->andReturn($this->response($body)));

        return app(WikiPageClaimExtractionAiClient::class);
    }

    private function extractManualMixedBlock(WikiPageClaimExtractionAiClient $client): array
    {
        return $client->extractClaimsForManualMixedBlock(
            pageTitle: 'Side',
            pageType: 'article',
            blockMarkdown: 'Kunden har en rutine. Det bør etableres en fast kontroll. Kunden har en ukildet kontroll. Ugyldig claim.',
            contentBlockKey: 'block-0002',
            sourceElements: [[
                'key' => 'source-alpha',
                'type' => 'paragraph',
                'text' => 'Alpha source text.',
            ]],
            languageCode: 'no',
        );
    }

    private function response(array $body): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'output' => [], 'output_text' => json_encode($body)];
    }
}
