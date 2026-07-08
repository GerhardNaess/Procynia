<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class EnterpriseWikiMaintainerDecisionAiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    // =========================================================================
    // Payload structure
    // =========================================================================

    public function test_payload_includes_strict_json_schema(): void
    {
        $payload = $this->capturePayload();

        $format = $payload['text']['format'];
        $this->assertSame('json_schema', $format['type']);
        $this->assertTrue($format['strict']);
        $this->assertSame('maintainer_decision', $format['name']);
        $this->assertIsArray($format['schema']);
    }

    public function test_payload_does_not_include_temperature(): void
    {
        $payload = $this->capturePayload();
        $this->assertArrayNotHasKey('temperature', $payload);
    }

    public function test_payload_model_is_gpt5(): void
    {
        $payload = $this->capturePayload();
        $this->assertSame('gpt-5', $payload['model']);
    }

    public function test_payload_includes_source_title_in_user_message(): void
    {
        $payload = $this->capturePayload(
            sourceMeta: ['title' => 'Masterdata Prosjekt', 'filename' => 'Masterdata Prosjekt.docx'],
        );

        $userText = $this->userMessageText($payload);
        $this->assertStringContainsString('Masterdata Prosjekt', $userText);
    }

    public function test_payload_includes_source_filename_in_user_message(): void
    {
        $payload = $this->capturePayload(
            sourceMeta: ['title' => 'Selskapsinfo', 'filename' => 'selskapsinfo.pdf'],
        );

        $userText = $this->userMessageText($payload);
        $this->assertStringContainsString('selskapsinfo.pdf', $userText);
    }

    public function test_payload_includes_extracted_text_in_user_message(): void
    {
        $payload = $this->capturePayload(
            sourceText: 'Dette er innholdet i kildefilen.',
        );

        $userText = $this->userMessageText($payload);
        $this->assertStringContainsString('Dette er innholdet i kildefilen.', $userText);
    }

    public function test_payload_includes_index_context_in_user_message(): void
    {
        $indexContext = [
            [
                'id'             => 7,
                'title'          => 'Eksisterende Konseptside',
                'slug'           => 'eksisterende-konseptside',
                'page_type'      => 'concept',
                'status'         => 'approved',
                'excerpt'        => null,
                'open_lint_count' => 0,
                'updated_at'     => '2026-07-01T12:00:00+00:00',
            ],
        ];

        $payload = $this->capturePayload(indexContext: $indexContext);

        $userText = $this->userMessageText($payload);
        $this->assertStringContainsString('Eksisterende Konseptside', $userText);
        $this->assertStringContainsString('eksisterende-konseptside', $userText);
    }

    public function test_payload_indicates_empty_index_when_no_pages_exist(): void
    {
        $payload = $this->capturePayload(indexContext: []);

        $userText = $this->userMessageText($payload);
        $this->assertStringContainsString('No pages yet.', $userText);
    }

    // =========================================================================
    // decide() — happy path
    // =========================================================================

    public function test_decide_returns_valid_decision_array(): void
    {
        $decision = $this->validDecision();
        $client = $this->clientReturning($decision);

        $result = $client->decide(
            sourceMeta: ['title' => 'Test Dokument', 'filename' => 'test.docx'],
            sourceText: 'Noe innhold.',
            indexContext: [],
            languageCode: 'no',
        );

        $this->assertSame('create', $result['source_article']['action']);
        $this->assertSame('Test Artikkel', $result['source_article']['title']);
        $this->assertSame([], $result['concept_pages']);
    }

    public function test_decide_normalises_missing_optional_keys(): void
    {
        $decision = $this->validDecision();
        unset($decision['concept_pages'], $decision['entity_pages'], $decision['warnings'], $decision['no_action_reason']);

        $client = $this->clientReturning($decision);
        $result = $client->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');

        $this->assertSame([], $result['concept_pages']);
        $this->assertSame([], $result['entity_pages']);
        $this->assertNull($result['no_action_reason']);
    }

    // =========================================================================
    // decide() — error handling
    // =========================================================================

    public function test_decide_throws_when_ai_is_disabled(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);
        $client = app(EnterpriseWikiMaintainerDecisionAiClient::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not enabled/');

        $client->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');
    }

    public function test_decide_throws_on_empty_response(): void
    {
        $client = $this->clientWithRawResponse(['output_text' => '', 'output' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty response/');

        $client->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');
    }

    public function test_decide_throws_on_non_json_response(): void
    {
        $client = $this->clientWithRawResponse(['output_text' => 'dette er ikke json']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        $client->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');
    }

    public function test_decide_throws_on_schema_violation(): void
    {
        // Missing source_article — parse() will fail, client wraps as RuntimeException
        $client = $this->clientWithRawResponse([
            'output_text' => json_encode([
                'source_summary' => [
                    'action' => 'create', 'title' => 'T', 'proposed_slug' => 'test', 'reason' => 'ok',
                ],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/schema validation/');

        $client->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');
    }

    public function test_api_exception_propagates_from_open_ai_client(): void
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')->once()->andThrow(new RuntimeException('upstream error'));

        $client = app(EnterpriseWikiMaintainerDecisionAiClient::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('upstream error');

        $client->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function test_empty_source_text_is_handled_safely(): void
    {
        $client = $this->clientReturning($this->validDecision());

        // Must not throw — empty text is valid input
        $result = $client->decide(['title' => 'T', 'filename' => 'T.docx'], '', [], 'no');

        $this->assertArrayHasKey('source_article', $result);
    }

    public function test_empty_source_text_renders_empty_marker_in_user_message(): void
    {
        $payload = $this->capturePayload(sourceText: '');

        $userText = $this->userMessageText($payload);
        $this->assertStringContainsString('(empty)', $userText);
    }

    public function test_long_source_text_is_truncated_in_payload(): void
    {
        $longText = str_repeat('A', 20_000);
        $payload = $this->capturePayload(sourceText: $longText);

        $userText = $this->userMessageText($payload);
        $this->assertStringContainsString('[... text truncated ...]', $userText);
        $this->assertLessThan(20_000, mb_strlen($userText));
    }

    public function test_no_real_network_calls_are_made(): void
    {
        $client = $this->clientReturning($this->validDecision());

        $result = $client->decide(
            ['title' => 'Dokument', 'filename' => 'Dokument.docx'],
            'Innhold.',
            [],
            'en',
        );

        $this->assertArrayHasKey('source_article', $result);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function validDecision(): array
    {
        return [
            'source_article' => [
                'action'        => 'create',
                'title'         => 'Test Artikkel',
                'proposed_slug' => 'test-artikkel-ab1c2d',
                'reason'        => 'New article for this source.',
            ],
            'source_summary' => [
                'action'        => 'create',
                'title'         => 'Sammendrag: Test Artikkel',
                'proposed_slug' => 'sammendrag-test-artikkel-ab1c2d',
                'reason'        => 'Companion summary page.',
            ],
            'concept_pages'    => [],
            'entity_pages'     => [],
            'no_action_reason' => null,
            'warnings'         => [],
        ];
    }

    private function capturePayload(
        array $sourceMeta = ['title' => 'Test Dokument', 'filename' => 'Test Dokument.docx'],
        string $sourceText = 'Noe innhold her.',
        array $indexContext = [],
        string $languageCode = 'no',
    ): array {
        $capturedPayload = null;

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedPayload): array {
                $capturedPayload = $payload;

                return ['output_text' => json_encode($this->validDecision())];
            });

        app(EnterpriseWikiMaintainerDecisionAiClient::class)->decide(
            $sourceMeta, $sourceText, $indexContext, $languageCode,
        );

        return (array) $capturedPayload;
    }

    private function clientReturning(array $decision): EnterpriseWikiMaintainerDecisionAiClient
    {
        return $this->clientWithRawResponse(['output_text' => json_encode($decision)]);
    }

    private function clientWithRawResponse(array $responseBody): EnterpriseWikiMaintainerDecisionAiClient
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')->once()->andReturn($responseBody);

        return app(EnterpriseWikiMaintainerDecisionAiClient::class);
    }

    private function userMessageText(array $payload): string
    {
        return (string) data_get($payload, 'input.1.content.0.text', '');
    }
}
