<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class WikiClaimVerificationAiClientTest extends TestCase
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
        $this->assertSame(900, $payload['max_output_tokens']);
        $this->assertFalse($payload['store']);
        $this->assertArrayNotHasKey('reasoning', $payload);
        $this->assertSame('json_schema', data_get($payload, 'text.format.type'));
        $this->assertTrue(data_get($payload, 'text.format.strict'));
    }

    public function test_candidate_source_elements_are_included_in_the_prompt_with_their_keys(): void
    {
        $payload = $this->capturePayload(sourceElements: [
            ['key' => 'paragraph-80', 'type' => 'paragraph', 'excerpt' => 'Response time is 30 seconds.', 'page_reference' => 'Section 9'],
        ]);

        $prompt = data_get($payload, 'input.1.content.0.text');

        $this->assertStringContainsString('paragraph-80', $prompt);
        $this->assertStringContainsString('Response time is 30 seconds.', $prompt);
    }

    public function test_schema_constrains_supporting_keys_to_the_given_candidates(): void
    {
        $payload = $this->capturePayload(sourceElements: [
            ['key' => 'paragraph-80', 'type' => 'paragraph', 'excerpt' => 'Response time is 30 seconds.', 'page_reference' => null],
        ]);

        $enum = data_get($payload, 'text.format.schema.properties.supporting_source_element_keys.items.enum');

        $this->assertSame(['paragraph-80'], $enum);
    }

    public function test_schema_falls_back_to_a_synthetic_key_when_no_candidates_are_given(): void
    {
        $payload = $this->capturePayload(sourceElements: []);

        $enum = data_get($payload, 'text.format.schema.properties.supporting_source_element_keys.items.enum');

        $this->assertSame([WikiClaimVerificationAiClient::FALLBACK_SOURCE_ELEMENT_KEY], $enum);
    }

    public function test_developer_prompt_allows_combining_excerpts_about_the_same_entity(): void
    {
        $payload = $this->capturePayload();
        $prompt = data_get($payload, 'input.0.content.0.text');

        $this->assertStringContainsString('synthesizes ONE topic/entity/process', $prompt);
    }

    public function test_developer_prompt_still_rejects_misattribution_and_reinforcement(): void
    {
        $payload = $this->capturePayload();
        $prompt = data_get($payload, 'input.0.content.0.text');

        $this->assertStringContainsString('misattribution', $prompt);
        $this->assertStringContainsString('reinforcement', $prompt);
    }

    /**
     * Readability/language-flow fix: reason/unsupported_parts previously had no output-language
     * instruction at all, so the model defaulted to English free text regardless of the
     * customer's Wiki language — this is the root cause of English explanation text on a
     * Norwegian-locale findings list, fixed here rather than in presentation code.
     */
    public function test_developer_prompt_instructs_norwegian_output_for_reason_fields_by_default(): void
    {
        $payload = $this->capturePayload(languageCode: 'no');
        $prompt = data_get($payload, 'input.0.content.0.text');

        $this->assertStringContainsString('Respond in Norwegian for the "reason" and "unsupported_parts" fields', $prompt);
    }

    public function test_developer_prompt_instructs_english_output_for_reason_fields_when_language_is_en(): void
    {
        $payload = $this->capturePayload(languageCode: 'en');
        $prompt = data_get($payload, 'input.0.content.0.text');

        $this->assertStringContainsString('Respond in English for the "reason" and "unsupported_parts" fields', $prompt);
    }

    /**
     * Technical/audit fields (verdict, checks, supporting_source_element_keys, claim_language,
     * source_language, same_meaning_across_languages) must never be subject to the output-language
     * instruction — only the two human-readable free-text fields are.
     */
    public function test_developer_prompt_marks_structural_fields_as_unaffected_by_language(): void
    {
        $payload = $this->capturePayload();
        $prompt = data_get($payload, 'input.0.content.0.text');

        $this->assertStringContainsString('verdict/checks/supporting_source_element_keys/', $prompt);
        $this->assertStringContainsString('are structural and unaffected', $prompt);
    }

    public function test_rejects_an_unknown_verdict(): void
    {
        $this->expectException(RuntimeException::class);

        $this->clientReturning($this->rawResult(['verdict' => 'maybe']))
            ->verifyClaim('Påstand', [], 'Kildetekst', 'no');
    }

    public function test_rejects_response_missing_checks(): void
    {
        $this->expectException(RuntimeException::class);

        $result = $this->rawResult();
        unset($result['checks']);

        $this->clientReturning($result)->verifyClaim('Påstand', [], 'Kildetekst', 'no');
    }

    public function test_rejects_an_invalid_check_value(): void
    {
        $this->expectException(RuntimeException::class);

        $result = $this->rawResult();
        $result['checks']['negation'] = 'sort_of';

        $this->clientReturning($result)->verifyClaim('Påstand', [], 'Kildetekst', 'no');
    }

    public function test_filters_out_a_supporting_key_not_among_the_given_candidates(): void
    {
        $result = $this->rawResult(['supporting_source_element_keys' => ['paragraph-80', 'paragraph-999']]);

        $decoded = $this->clientReturning($result)->verifyClaim(
            'Påstand',
            [['key' => 'paragraph-80', 'type' => 'paragraph', 'excerpt' => 'Kildetekst.', 'page_reference' => null]],
            'Kildetekst',
            'no',
        );

        $this->assertSame(['paragraph-80'], $decoded['supporting_source_element_keys']);
    }

    public function test_accepts_a_full_valid_response(): void
    {
        $decoded = $this->clientReturning($this->rawResult())
            ->verifyClaim('Påstand', [], 'Kildetekst', 'no');

        $this->assertSame('supported', $decoded['verdict']);
        $this->assertTrue($decoded['same_meaning_across_languages']);
        $this->assertSame('match', $decoded['checks']['numbers_and_units']);
    }

    private function capturePayload(array $sourceElements = [], string $languageCode = 'no'): array
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response($this->rawResult());
            });
        });
        app(WikiClaimVerificationAiClient::class)->verifyClaim('Påstand', $sourceElements, 'Kilde', $languageCode);

        return $captured;
    }

    private function clientReturning(array $body): WikiClaimVerificationAiClient
    {
        $this->mock(OpenAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('createResponse')->once()->andReturn($this->response($body)));

        return app(WikiClaimVerificationAiClient::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawResult(array $overrides = []): array
    {
        return array_merge([
            'verdict' => 'supported',
            'same_meaning_across_languages' => true,
            'claim_language' => 'no',
            'source_language' => 'en',
            'supporting_source_element_keys' => [],
            'reason' => 'Same fact, different language.',
            'unsupported_parts' => '',
            'checks' => [
                'actor' => 'match',
                'action' => 'match',
                'object' => 'match',
                'modality' => 'match',
                'negation' => 'match',
                'numbers_and_units' => 'match',
                'time_and_date' => 'match',
                'scope' => 'match',
                'conditions_and_exceptions' => 'not_applicable',
                'subject_entity' => 'match',
            ],
        ], $overrides);
    }

    private function response(array $body): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'output' => [], 'output_text' => json_encode($body)];
    }
}
