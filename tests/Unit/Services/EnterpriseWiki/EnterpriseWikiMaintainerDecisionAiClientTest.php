<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Data\Ai\Capacity\AiCapacityRequest;
use App\Exceptions\EnterpriseWikiAiOutputCapacityExceededException;
use App\Services\EnterpriseWiki\EnterpriseWikiAiCapacityPlanner;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionSplitCoordinator;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Facades\Log;
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
    // Fase 8K-1 — EXISTING PAGE CANDIDATES context alongside the source catalog
    // =========================================================================

    public function test_existing_page_candidates_reach_the_maintainer_prompt(): void
    {
        $prompt = $this->userMessageText($this->capturePayload(existingPageCandidates: $this->candidateFixture()));

        $this->assertStringContainsString('EXISTING PAGE CANDIDATES', $prompt);
        $this->assertStringContainsString('[page 41]', $prompt);
        $this->assertStringContainsString('OLD-THRESHOLD-MARKER', $prompt, 'the superseded value must be visible');
        $this->assertStringContainsString('current version: 77', $prompt);
    }

    public function test_candidate_context_does_not_replace_the_source_catalog(): void
    {
        // The two blocks answer different questions: SOURCE ELEMENTS is the NEW document,
        // EXISTING PAGE CANDIDATES is what the Wiki already says. Both must survive together.
        $prompt = $this->userMessageText($this->capturePayload(
            sourceElements: $this->sourceElementFixture(),
            existingPageCandidates: $this->candidateFixture(),
        ));

        $this->assertStringContainsString('SOURCE ELEMENTS', $prompt);
        $this->assertStringContainsString('[paragraph-0] (paragraph) First body paragraph.', $prompt);
        $this->assertStringContainsString('EXISTING PAGE CANDIDATES', $prompt);
        $this->assertStringContainsString('OLD-THRESHOLD-MARKER', $prompt);
    }

    public function test_candidate_block_includes_authoritative_heading_contract_data(): void
    {
        $prompt = $this->userMessageText($this->capturePayload(existingPageCandidates: $this->candidateFixture()));

        $this->assertStringContainsString('page_has_subsections: false', $prompt);
        $this->assertStringContainsString('page_has_subsections: true', $prompt);
        $this->assertStringContainsString('valid_target_headings: []', $prompt);
        $this->assertStringContainsString('"Response times"', $prompt);
    }

    public function test_prompt_is_unchanged_when_there_are_no_candidates(): void
    {
        $withCandidates = $this->userMessageText($this->capturePayload(sourceElements: $this->sourceElementFixture()));

        $this->assertStringNotContainsString('EXISTING PAGE CANDIDATES', $withCandidates);
    }

    public function test_only_the_supplied_candidates_are_sent_never_a_whole_wiki(): void
    {
        $prompt = $this->userMessageText($this->capturePayload(existingPageCandidates: $this->candidateFixture()));

        // Exactly the two supplied candidates — the block never reaches back for more pages.
        $this->assertSame(2, substr_count($prompt, '[page '));
        $this->assertStringContainsString('EXISTING PAGE CANDIDATES (2 pages)', $prompt);
    }

    public function test_candidate_block_is_byte_identical_for_identical_input(): void
    {
        $first = $this->userMessageText($this->capturePayload(existingPageCandidates: $this->candidateFixture()));
        $second = $this->userMessageText($this->capturePayload(existingPageCandidates: $this->candidateFixture()));

        $this->assertSame($first, $second);
    }

    public function test_candidate_context_does_not_alter_the_decision_schema(): void
    {
        $schema = $this->capturePayload(existingPageCandidates: $this->candidateFixture())['text']['format']['schema'];

        // 8K-1 is context only — no patch contract, no owned_topics change (that would be 8K-2).
        $ownedTopics = $schema['properties']['source_article']['properties']['owned_topics'];
        $this->assertSame('array', $ownedTopics['type']);
        $this->assertSame(['type' => 'string'], $ownedTopics['items']);
        $this->assertSame(['create', 'update'], $schema['properties']['source_article']['properties']['action']['enum']);
    }

    public function test_the_block_does_not_claim_every_candidate_is_named_in_the_source_document(): void
    {
        // Candidates reached through the Wiki's own relations are NOT mentioned by name — the fixture's
        // second page has mention_count 0 for exactly that reason. Telling the maintainer that every
        // candidate is named would make it dismiss precisely the pages run 25 showed it must inspect.
        $prompt = $this->userMessageText($this->capturePayload(existingPageCandidates: $this->candidateFixture()));

        $this->assertStringNotContainsString('are named in the source document', $prompt);
        $this->assertStringContainsString('without naming the page itself', $prompt);
        $this->assertStringContainsString('on whether that document happens to mention the page by name', $prompt);

        // The correction must not soften the guardrails in the other direction.
        $this->assertStringContainsString('not a verdict', $prompt);
        $this->assertStringContainsString('leave a candidate untouched when its substance is not affected', $prompt);
    }

    public function test_the_correction_leaves_candidate_rendering_and_data_untouched(): void
    {
        $prompt = $this->userMessageText($this->capturePayload(existingPageCandidates: $this->candidateFixture()));

        // Only the framing sentences changed; every candidate row still renders identically.
        $this->assertStringContainsString('[page 42] Governing Procedure', $prompt);
        $this->assertStringContainsString('type: article | slug: governing-procedure | current version: 78 (v1) | content truncated', $prompt);
        $this->assertStringContainsString('OLD-DEADLINE-MARKER', $prompt);
    }

    /**
     * Domain-free candidates in the shape EnterpriseWikiPatchCandidateService returns them.
     *
     * @return list<array<string, mixed>>
     */
    private function candidateFixture(): array
    {
        return [
            [
                'page_id' => 41,
                'title' => 'Existing Platform Page',
                'slug' => 'existing-platform-page',
                'page_type' => 'entity',
                'page_version_id' => 77,
                'version_number' => 2,
                'content' => "# Existing Platform Page\n\nTarget availability is OLD-THRESHOLD-MARKER per month.",
                'page_has_subsections' => false,
                'valid_target_headings' => [],
                'truncated' => false,
                'mention_count' => 2,
            ],
            [
                'page_id' => 42,
                'title' => 'Governing Procedure',
                'slug' => 'governing-procedure',
                'page_type' => 'article',
                'page_version_id' => 78,
                'version_number' => 1,
                'content' => "# Governing Procedure\n\n## Response times\n\nIncidents are confirmed within OLD-DEADLINE-MARKER.",
                'page_has_subsections' => true,
                'valid_target_headings' => ['Response times'],
                'truncated' => true,
                'mention_count' => 0,
            ],
        ];
    }

    // =========================================================================
    // Fase 8J-1B — addressable SOURCE ELEMENTS catalog for the maintainer decision
    // =========================================================================

    public function test_maintainer_prompt_carries_the_addressable_source_catalog_when_elements_exist(): void
    {
        $prompt = $this->userMessageText($this->capturePayload(sourceElements: $this->sourceElementFixture()));

        $this->assertStringContainsString('SOURCE ELEMENTS', $prompt);
        $this->assertStringContainsString('[paragraph-0] (paragraph) First body paragraph.', $prompt);
        $this->assertStringContainsString('[listitem-0] (list_item) First requirement item.', $prompt);
        $this->assertStringContainsString('[tbl0-row0] (table_row) Field: Value', $prompt);
    }

    public function test_section_context_is_carried_and_grouped_rather_than_repeated_per_element(): void
    {
        $prompt = $this->userMessageText($this->capturePayload(sourceElements: $this->sourceElementFixture()));

        $this->assertStringContainsString('# 1. Alpha', $prompt);
        $this->assertStringContainsString('# 2. Beta', $prompt);
        // Grouped: the section is printed once per run of elements, not on every line.
        $this->assertSame(1, substr_count($prompt, '# 1. Alpha'));
        $this->assertSame(1, substr_count($prompt, '# 2. Beta'));
    }

    public function test_catalog_order_is_deterministic_and_follows_the_service_order(): void
    {
        $first = $this->userMessageText($this->capturePayload(sourceElements: $this->sourceElementFixture()));
        $second = $this->userMessageText($this->capturePayload(sourceElements: $this->sourceElementFixture()));

        $this->assertSame($first, $second, 'same input must produce a byte-identical catalog');

        foreach ([['paragraph-0', 'listitem-0'], ['listitem-0', 'listitem-1'], ['listitem-1', 'paragraph-1'], ['paragraph-1', 'tbl0-row0']] as [$earlier, $later]) {
            $this->assertLessThan(
                strpos($first, "[{$later}]"),
                strpos($first, "[{$earlier}]"),
                "{$earlier} must precede {$later}",
            );
        }
    }

    public function test_each_key_carries_its_own_text(): void
    {
        $prompt = $this->userMessageText($this->capturePayload(sourceElements: $this->sourceElementFixture()));

        // The regression this guards: a key/text pairing that drifts would make every downstream
        // provenance statement wrong while still looking structurally valid.
        $this->assertMatchesRegularExpression('/\[listitem-1\] \(list_item\) Second requirement item\./', $prompt);
        $this->assertMatchesRegularExpression('/\[paragraph-1\] \(paragraph\) Paragraph under the second section\./', $prompt);
    }

    public function test_images_stay_in_figure_candidates_and_never_enter_the_prose_catalog(): void
    {
        $catalog = EnterpriseWikiMaintainerDecisionAiClient::sourceCatalogElements($this->sourceElementFixture());

        $this->assertSame(
            ['paragraph-0', 'listitem-0', 'listitem-1', 'paragraph-1', 'tbl0-row0'],
            array_column($catalog, 'source_element_key'),
            'image elements belong to FIGURE CANDIDATES, not the prose catalog',
        );
    }

    public function test_figure_candidates_block_is_still_rendered_alongside_the_catalog(): void
    {
        $prompt = $this->userMessageText($this->capturePayload(sourceElements: $this->sourceElementFixture()));

        $this->assertStringContainsString('FIGURE CANDIDATES', $prompt);
    }

    public function test_flat_source_text_is_not_sent_alongside_the_catalog(): void
    {
        $prompt = $this->userMessageText($this->capturePayload(
            sourceText: 'UNIQUE-FLAT-TEXT-MARKER',
            sourceElements: $this->sourceElementFixture(),
        ));

        // The binding rule: the same document content is never sent twice.
        $this->assertStringNotContainsString('SOURCE TEXT:', $prompt);
        $this->assertStringNotContainsString('UNIQUE-FLAT-TEXT-MARKER', $prompt);
    }

    public function test_falls_back_to_flat_source_text_when_no_structured_elements_exist(): void
    {
        // Old document, non-DOCX format, or a file that cannot be parsed.
        $prompt = $this->userMessageText($this->capturePayload(sourceText: 'Plain extracted text.'));

        $this->assertStringContainsString('SOURCE TEXT:', $prompt);
        $this->assertStringContainsString('Plain extracted text.', $prompt);
        $this->assertStringNotContainsString('SOURCE ELEMENTS', $prompt);
    }

    public function test_an_image_only_document_still_falls_back_to_flat_source_text(): void
    {
        $imagesOnly = array_values(array_filter(
            $this->sourceElementFixture(),
            static fn (array $element): bool => $element['source_element_type'] === 'image',
        ));

        $prompt = $this->userMessageText($this->capturePayload(sourceText: 'Plain extracted text.', sourceElements: $imagesOnly));

        $this->assertStringContainsString('SOURCE TEXT:', $prompt);
        $this->assertStringNotContainsString('SOURCE ELEMENTS', $prompt);
    }

    public function test_catalog_truncates_on_whole_element_boundaries(): void
    {
        $block = EnterpriseWikiMaintainerDecisionAiClient::sourceElementsBlock(
            EnterpriseWikiMaintainerDecisionAiClient::sourceCatalogElements($this->sourceElementFixture()),
            120,
        );

        // A half-rendered element would hand the model a key whose text it cannot see.
        $this->assertStringContainsString('further element(s) truncated', $block);
        $this->assertStringNotContainsString('[tbl0-row0]', $block);
    }

    public function test_the_catalog_sends_the_document_content_once(): void
    {
        $elements = $this->sourceElementFixture();
        $flat = implode("\n", array_column($elements, 'reference_text'));

        $block = EnterpriseWikiMaintainerDecisionAiClient::sourceContentBlock($flat, $elements, 12000);

        foreach (['First body paragraph.', 'First requirement item.', 'Field: Value'] as $text) {
            $this->assertSame(1, substr_count($block, $text), "[{$text}] must appear exactly once");
        }
    }

    public function test_decision_schema_and_owned_topics_contract_are_unchanged(): void
    {
        $schema = $this->capturePayload(sourceElements: $this->sourceElementFixture())['text']['format']['schema'];

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('concept_candidates', $schema['properties']);

        // owned_topics must still be a plain string[] — 8J-1B does not touch the ownership schema.
        $ownedTopics = $schema['properties']['source_article']['properties']['owned_topics'];
        $this->assertSame('array', $ownedTopics['type']);
        $this->assertSame(['type' => 'string'], $ownedTopics['items']);
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

    public function test_payload_uses_capacity_planner_derived_max_output_tokens(): void
    {
        $sourceText = 'Noe innhold her.';
        $payload = $this->capturePayload(sourceText: $sourceText);

        // The split decision remains based on raw source size, but the output budget must reflect
        // the rendered maintainer context the model actually receives.
        $expectedPlan = app(EnterpriseWikiAiCapacityPlanner::class)->plan(new AiCapacityRequest(
            operationType: 'enterprise_wiki_maintainer_decision',
            model: 'gpt-5',
            inputSizeChars: mb_strlen($this->userMessageText($payload)),
            expectedResultObjects: 2,
        ));

        $this->assertSame($expectedPlan->chosenMaxOutputTokens, $payload['max_output_tokens']);
        $this->assertGreaterThan(0, $payload['max_output_tokens']);
    }

    public function test_small_prompt_keeps_a_modest_bounded_initial_capacity(): void
    {
        $payload = $this->capturePayload(sourceText: 'Kort beslutningsgrunnlag.');

        $this->assertLessThan(6_000, $payload['max_output_tokens']);
        $this->assertLessThanOrEqual(9_000, $payload['max_output_tokens']);
    }

    public function test_large_rendered_context_with_candidate_pages_gets_realistic_first_call_capacity(): void
    {
        $payload = $this->capturePayload(
            sourceText: 'Endringsnotat med ny terskel og ny frist.',
            indexContext: $this->largeIndexFixture(),
            sourceElements: $this->sourceElementFixture(),
            existingPageCandidates: $this->largeCandidateFixture(),
        );

        $expectedPlan = app(EnterpriseWikiAiCapacityPlanner::class)->plan(new AiCapacityRequest(
            operationType: 'enterprise_wiki_maintainer_decision',
            model: 'gpt-5',
            inputSizeChars: min(20_000, mb_strlen($this->userMessageText($payload))),
            expectedResultObjects: 5, // source article + summary + the three bounded patch candidates
        ));

        $this->assertSame($expectedPlan->chosenMaxOutputTokens, $payload['max_output_tokens']);
        $this->assertGreaterThanOrEqual(7_560, $payload['max_output_tokens']);
        $this->assertLessThanOrEqual(9_000, $payload['max_output_tokens']);
    }

    public function test_candidate_context_increases_first_call_capacity_without_changing_split_routing(): void
    {
        $small = $this->capturePayload(sourceText: 'Kort endringsnotat.');
        $contextual = $this->capturePayload(
            sourceText: 'Kort endringsnotat.',
            indexContext: $this->largeIndexFixture(),
            sourceElements: $this->sourceElementFixture(),
            existingPageCandidates: $this->largeCandidateFixture(),
        );

        $this->assertGreaterThan($small['max_output_tokens'], $contextual['max_output_tokens']);
        $this->assertLessThanOrEqual(9_000, $contextual['max_output_tokens']);
    }

    public function test_larger_source_text_produces_a_larger_max_output_tokens_than_a_small_document(): void
    {
        $smallPayload = $this->capturePayload(sourceText: 'Kort tekst.');
        $largePayload = $this->capturePayload(sourceText: str_repeat('ITIL Incident Management prosessbeskrivelse. ', 400));

        $this->assertGreaterThan($smallPayload['max_output_tokens'], $largePayload['max_output_tokens']);
    }

    public function test_payload_uses_low_reasoning_effort(): void
    {
        $payload = $this->capturePayload();

        $this->assertSame('low', data_get($payload, 'reasoning.effort'));
        $this->assertFalse($payload['store']);
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
                'id' => 7,
                'title' => 'Eksisterende Konseptside',
                'slug' => 'eksisterende-konseptside',
                'page_type' => 'concept',
                'status' => 'approved',
                'excerpt' => null,
                'open_lint_count' => 0,
                'updated_at' => '2026-07-01T12:00:00+00:00',
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

    public function test_developer_prompt_allows_exact_local_source_page_guidance_without_concept_ownership(): void
    {
        $payload = $this->capturePayload();
        $developerText = $this->developerMessageText($payload);

        $this->assertStringContainsString('copy the exact', $developerText);
        $this->assertStringContainsString('source_article/source_summary title', $developerText);
        $this->assertStringContainsString('Never use source_article/source_summary as a', $developerText);
        $this->assertStringContainsString('concept_candidates.owning_page_title', $developerText);
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
        Log::spy();

        $client = $this->clientWithRawResponse(['id' => 'resp_empty', 'output_text' => '', 'output' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no output text/');

        $client->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === '[PROCYNIA][WIKI_MAINTAINER_DECISION] OpenAI response diagnostics.'
                && ($context['response_id'] ?? null) === 'resp_empty'
                && ($context['http_status'] ?? null) === 200
                && ($context['response_status'] ?? null) === 'completed'
                && ($context['output_text_length'] ?? null) === 0
                && ($context['output_item_types'] ?? null) === []
                && ($context['content_item_types'] ?? null) === []
                && ($context['has_refusal'] ?? null) === false
                && ($context['token_usage']['input_tokens'] ?? null) === 100
                && ($context['token_usage']['output_tokens'] ?? null) === 20
                && ($context['token_usage']['total_tokens'] ?? null) === 120
                && ! array_key_exists('title', $context)
                && ! array_key_exists('filename', $context)
                && ! array_key_exists('sourceText', $context)
                && ! array_key_exists('input', $context)
                && ! array_key_exists('output', $context);
        });
    }

    public function test_decide_throws_on_non_json_response(): void
    {
        $client = $this->clientWithRawResponse(['output_text' => 'dette er ikke json']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        $client->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');
    }

    public function test_decide_returns_valid_decision_array_from_nested_output_text(): void
    {
        $client = $this->clientWithRawResponse([
            'id' => 'resp_nested_text',
            'status' => 'completed',
            'output_text' => '',
            'output' => [
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => json_encode($this->validDecision()),
                        ],
                    ],
                ],
            ],
        ]);

        $result = $client->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');

        $this->assertSame('create', $result['source_article']['action']);
        $this->assertSame('Test Artikkel', $result['source_article']['title']);
    }

    // =========================================================================
    // decide() — capacity retry (Wiki run-583 fix: status=incomplete/reason=max_output_tokens)
    // =========================================================================

    /**
     * First attempt truncated at the computed budget; the capacity retry uses a strictly higher,
     * still-capped budget and succeeds — the client returns the parsed decision from the SECOND
     * attempt, never touching the first (partial) response text.
     */
    public function test_decide_retries_once_on_incomplete_max_output_tokens_then_succeeds(): void
    {
        $decision = $this->validDecision();
        $capturedPayloads = [];

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->twice()
            ->andReturnUsing(function (array $payload) use (&$capturedPayloads, $decision): array {
                $capturedPayloads[] = $payload;

                if (count($capturedPayloads) === 1) {
                    return [
                        'id' => 'resp_incomplete_1',
                        'status' => 'incomplete',
                        'incomplete_details' => ['reason' => 'max_output_tokens'],
                        'output_text' => '',
                        'output' => [],
                    ];
                }

                return ['id' => 'resp_completed_2', 'status' => 'completed', 'output_text' => json_encode($decision)];
            });

        $result = app(EnterpriseWikiMaintainerDecisionAiClient::class)
            ->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');

        $this->assertSame('Test Artikkel', $result['source_article']['title']);
        $this->assertCount(2, $capturedPayloads);
        $this->assertGreaterThan($capturedPayloads[0]['max_output_tokens'], $capturedPayloads[1]['max_output_tokens']);
    }

    /**
     * Both attempts hit max_output_tokens — exactly one capacity retry is allowed, so the client
     * throws a dedicated, traceable exception instead of silently giving up or retrying forever.
     * No partial decision is ever parsed or returned.
     */
    public function test_decide_throws_capacity_exceeded_when_both_attempts_hit_max_output_tokens(): void
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')->twice()->andReturn([
            'id' => 'resp_incomplete',
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output_text' => '',
            'output' => [],
            'usage' => ['output_tokens' => 20],
        ]);

        $this->expectException(EnterpriseWikiAiOutputCapacityExceededException::class);
        $this->expectExceptionMessageMatches('/exhausted capacity retry/');
        $this->expectExceptionMessageMatches('/retry level 1/');

        app(EnterpriseWikiMaintainerDecisionAiClient::class)
            ->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');
    }

    /**
     * A non-token incomplete reason must never be treated as a capacity problem — the client
     * makes exactly one attempt and lets the original exception propagate untouched.
     */
    public function test_decide_does_not_retry_on_non_token_incomplete_reason(): void
    {
        $client = $this->clientWithRawResponse([
            'id' => 'resp_content_filter',
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'content_filter'],
            'output_text' => '',
            'output' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/OpenAI response was incomplete\..*content_filter/s');

        $client->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');
    }

    /**
     * The consistency-repair pass (a separate, existing mechanism) must still work normally after
     * a fully completed response — capacity retry logic must never interfere with a call that
     * simply succeeds.
     */
    public function test_decide_does_not_retry_on_a_completed_response(): void
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')->once()->andReturn([
            'status' => 'completed',
            'output_text' => json_encode($this->validDecision()),
        ]);

        $result = app(EnterpriseWikiMaintainerDecisionAiClient::class)
            ->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');

        $this->assertSame('Test Artikkel', $result['source_article']['title']);
    }

    public function test_capacity_decision_is_logged_without_raw_document_text(): void
    {
        Log::spy();

        $client = $this->clientReturning($this->validDecision());
        $client->decide(['title' => 'T', 'filename' => 'T.docx'], 'Hemmelig kildetekst som aldri skal logges.', [], 'no');

        // Wiki run-592: the executor now also logs one line per raw HTTP attempt (see
        // EnterpriseWikiAiCapacityRetryExecutor::logAttempt()), in addition to this existing
        // capacity-decision line — so info() is called more than once per successful call overall;
        // this assertion only checks that AT LEAST one of those calls is the capacity-decision line
        // with the expected shape, never any raw document text.
        Log::shouldHaveReceived('info')->atLeast()->once()->withArgs(function (string $message, array $context): bool {
            return $message === '[PROCYNIA][WIKI_MAINTAINER_DECISION_CAPACITY] Capacity decision for AI call.'
                && ($context['operation_type'] ?? null) === 'enterprise_wiki_maintainer_decision'
                && ($context['model'] ?? null) === 'gpt-5'
                && ($context['retry_level'] ?? null) === 0
                && is_int($context['chosen_max_output_tokens'] ?? null)
                && is_string($context['basis'] ?? null)
                && ! str_contains(json_encode($context), 'Hemmelig kildetekst');
        });
    }

    public function test_decide_throws_when_response_contains_refusal_and_logs_safe_diagnostics(): void
    {
        Log::spy();

        $client = $this->clientWithRawResponse([
            'id' => 'resp_refusal',
            'status' => 'completed',
            'output_text' => '',
            'output' => [
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'refusal',
                            'refusal' => 'safety',
                        ],
                    ],
                ],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/refusal/');

        $client->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === '[PROCYNIA][WIKI_MAINTAINER_DECISION] OpenAI response diagnostics.'
                && ($context['response_id'] ?? null) === 'resp_refusal'
                && ($context['response_status'] ?? null) === 'completed'
                && ($context['output_text_length'] ?? null) === 0
                && ($context['output_item_types'] ?? null) === ['message']
                && ($context['content_item_types'] ?? null) === ['refusal']
                && ($context['has_refusal'] ?? null) === true;
        });
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
    // decide() — split routing (Wiki "Split oversized Wiki maintainer decisions" work item):
    // when the capacity planner reports strategy=split_required, decide() must delegate entirely
    // to EnterpriseWikiMaintainerDecisionSplitCoordinator instead of attempting (and predictably
    // truncating) a single oversized call.
    // =========================================================================

    public function test_decide_routes_to_split_coordinator_when_strategy_is_split_required(): void
    {
        $decision = $this->validDecision();

        /** @var EnterpriseWikiMaintainerDecisionSplitCoordinator&MockInterface $splitMock */
        $splitMock = $this->mock(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);
        $splitMock->shouldReceive('decide')->once()->andReturn($decision);

        /** @var OpenAiClient&MockInterface $aiMock */
        $aiMock = $this->mock(OpenAiClient::class);
        $aiMock->shouldNotReceive('createResponse');

        // A large enough source text that the real capacity profile resolves to split_required
        // (see EnterpriseWikiAiCapacityPlannerTest's equivalent real-profile assertion).
        $largeSourceText = str_repeat('ITIL prosessbeskrivelse med mange rammeverk og konsepter. ', 700);

        $result = app(EnterpriseWikiMaintainerDecisionAiClient::class)
            ->decide(['title' => 'T', 'filename' => 'T.docx'], $largeSourceText, [], 'no');

        $this->assertSame('Test Artikkel', $result['source_article']['title']);
    }

    public function test_decide_does_not_route_to_split_coordinator_for_a_small_document(): void
    {
        /** @var EnterpriseWikiMaintainerDecisionSplitCoordinator&MockInterface $splitMock */
        $splitMock = $this->mock(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);
        $splitMock->shouldNotReceive('decide');

        $client = $this->clientReturning($this->validDecision());

        $client->decide(['title' => 'T', 'filename' => 'T.docx'], 'Noe kort innhold.', [], 'no');
    }

    /**
     * The run-584 document pattern (~13k source chars, the exact input size that previously
     * completed successfully in a single call) must still resolve to a single call, not split —
     * no regression from introducing the split flow.
     */
    public function test_decide_still_uses_single_call_for_the_run_584_document_pattern(): void
    {
        /** @var EnterpriseWikiMaintainerDecisionSplitCoordinator&MockInterface $splitMock */
        $splitMock = $this->mock(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);
        $splitMock->shouldNotReceive('decide');

        $sourceText = str_repeat('A', 12_500); // truncated to MAX_SOURCE_TEXT_CHARS=12000 in the prompt, matching run 584's actual scale.
        $client = $this->clientReturning($this->validDecision());

        $client->decide(['title' => 'T', 'filename' => 'T.docx'], $sourceText, [], 'no');
    }

    public function test_repair_never_routes_to_split_coordinator_regardless_of_size(): void
    {
        /** @var EnterpriseWikiMaintainerDecisionSplitCoordinator&MockInterface $splitMock */
        $splitMock = $this->mock(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);
        $splitMock->shouldNotReceive('decide');

        /** @var OpenAiClient&MockInterface $aiMock */
        $aiMock = $this->mock(OpenAiClient::class);
        $aiMock->shouldReceive('createResponse')->once()->andReturn([
            'status' => 'completed',
            'output_text' => json_encode($this->validDecision()),
        ]);

        $largeSourceText = str_repeat('ITIL prosessbeskrivelse med mange rammeverk og konsepter. ', 700);

        app(EnterpriseWikiMaintainerDecisionAiClient::class)->repair(
            ['title' => 'T', 'filename' => 'T.docx'], $largeSourceText, [], 'no', $this->validDecision(), ['issue'],
        );
    }

    // =========================================================================
    // repair() — Wiki run-581 fix: a single bounded pass to fix a decision that
    // EnterpriseWikiMaintainerDecisionConsistencyValidator found logically inconsistent.
    // =========================================================================

    public function test_repair_returns_valid_corrected_decision(): void
    {
        $corrected = $this->validDecision();
        $corrected['concept_pages'] = [[
            'action' => 'create',
            'page_id' => null,
            'title' => 'ITIL Incident Management',
            'proposed_slug' => 'itil-incident-management',
            'reason' => 'Central concept the article points to.',
        ]];

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')->once()->andReturn([
            'status' => 'completed',
            'output_text' => json_encode($corrected),
        ]);

        $client = app(EnterpriseWikiMaintainerDecisionAiClient::class);

        $result = $client->repair(
            sourceMeta: ['title' => 'Test Dokument', 'filename' => 'test.docx'],
            sourceText: 'Innhold.',
            indexContext: [],
            languageCode: 'no',
            decision: $this->validDecision(),
            issues: ['source_article points readers to "ITIL Incident Management" via related_page_guidance, but no existing or planned page matches that title.'],
        );

        $this->assertCount(1, $result['concept_pages']);
        $this->assertSame('ITIL Incident Management', $result['concept_pages'][0]['title']);
    }

    public function test_repair_payload_includes_previous_decision_and_issues_in_user_message(): void
    {
        $previousDecision = $this->validDecision();
        $issues = ['source_article points readers to "ITIL Incident Management" via related_page_guidance, but no existing or planned page matches that title.'];

        $capturedPayload = null;

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedPayload): array {
                $capturedPayload = $payload;

                return ['status' => 'completed', 'output_text' => json_encode($this->validDecision())];
            });

        app(EnterpriseWikiMaintainerDecisionAiClient::class)->repair(
            ['title' => 'Test Dokument', 'filename' => 'test.docx'],
            'Innhold.',
            [],
            'no',
            $previousDecision,
            $issues,
        );

        $userText = $this->userMessageText($capturedPayload);
        $this->assertStringContainsString('ITIL Incident Management', $userText);
        $this->assertStringContainsString('ISSUES TO FIX', $userText);
        $this->assertStringContainsString('Test Artikkel', $userText);
    }

    public function test_repair_uses_the_same_strict_schema_as_decide(): void
    {
        $capturedPayload = null;

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedPayload): array {
                $capturedPayload = $payload;

                return ['status' => 'completed', 'output_text' => json_encode($this->validDecision())];
            });

        app(EnterpriseWikiMaintainerDecisionAiClient::class)->repair(
            ['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no', $this->validDecision(), ['issue'],
        );

        $this->assertSame('maintainer_decision', $capturedPayload['text']['format']['name']);
        $this->assertTrue($capturedPayload['text']['format']['strict']);
    }

    public function test_repair_throws_when_ai_is_disabled(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);
        $client = app(EnterpriseWikiMaintainerDecisionAiClient::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not enabled/');

        $client->repair(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no', $this->validDecision(), ['issue']);
    }

    /**
     * repair() shares the same capacity-retry mechanism as decide() (both go through
     * executeWithCapacityRetry) — its prompt is larger (it echoes the full previous decision), so
     * it independently benefits from the same one-bounded-retry behaviour.
     */
    public function test_repair_also_retries_once_on_incomplete_max_output_tokens_then_succeeds(): void
    {
        $corrected = $this->validDecision();
        $capturedPayloads = [];

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->twice()
            ->andReturnUsing(function (array $payload) use (&$capturedPayloads, $corrected): array {
                $capturedPayloads[] = $payload;

                if (count($capturedPayloads) === 1) {
                    return [
                        'status' => 'incomplete',
                        'incomplete_details' => ['reason' => 'max_output_tokens'],
                        'output_text' => '',
                        'output' => [],
                    ];
                }

                return ['status' => 'completed', 'output_text' => json_encode($corrected)];
            });

        $result = app(EnterpriseWikiMaintainerDecisionAiClient::class)->repair(
            ['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no', $this->validDecision(), ['issue'],
        );

        $this->assertSame('Test Artikkel', $result['source_article']['title']);
        $this->assertGreaterThan($capturedPayloads[0]['max_output_tokens'], $capturedPayloads[1]['max_output_tokens']);
    }

    public function test_repair_throws_on_schema_violation(): void
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')->once()->andReturn([
            'status' => 'completed',
            'output_text' => json_encode(['source_summary' => ['action' => 'create', 'title' => 'T', 'proposed_slug' => 'test', 'reason' => 'ok']]),
        ]);

        $client = app(EnterpriseWikiMaintainerDecisionAiClient::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/repaired decision failed schema validation/');

        $client->repair(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no', $this->validDecision(), ['issue']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function validDecision(): array
    {
        return [
            'source_article' => [
                'action' => 'create',
                'title' => 'Test Artikkel',
                'proposed_slug' => 'test-artikkel-ab1c2d',
                'reason' => 'New article for this source.',
            ],
            'source_summary' => [
                'action' => 'create',
                'title' => 'Sammendrag: Test Artikkel',
                'proposed_slug' => 'sammendrag-test-artikkel-ab1c2d',
                'reason' => 'Companion summary page.',
            ],
            'concept_pages' => [],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }

    private function capturePayload(
        array $sourceMeta = ['title' => 'Test Dokument', 'filename' => 'Test Dokument.docx'],
        string $sourceText = 'Noe innhold her.',
        array $indexContext = [],
        string $languageCode = 'no',
        array $sourceElements = [],
        array $existingPageCandidates = [],
    ): array {
        $capturedPayload = null;

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedPayload): array {
                $capturedPayload = $payload;

                return ['status' => 'completed', 'output_text' => json_encode($this->validDecision())];
            });

        app(EnterpriseWikiMaintainerDecisionAiClient::class)->decide(
            $sourceMeta, $sourceText, $indexContext, $languageCode, [], null, $sourceElements, $existingPageCandidates,
        );

        return (array) $capturedPayload;
    }

    /**
     * Domain-free source elements in the exact shape
     * EnterpriseWikiDocumentSourceElementService::inspect() returns them.
     *
     * @return list<array<string, mixed>>
     */
    private function sourceElementFixture(): array
    {
        return [
            ['source_element_key' => 'paragraph-0', 'source_element_type' => 'paragraph', 'section_number' => '1.', 'section_title' => 'Alpha', 'reference_text' => 'First body paragraph.'],
            ['source_element_key' => 'listitem-0', 'source_element_type' => 'list_item', 'section_number' => '1.', 'section_title' => 'Alpha', 'reference_text' => 'First requirement item.'],
            ['source_element_key' => 'listitem-1', 'source_element_type' => 'list_item', 'section_number' => '1.', 'section_title' => 'Alpha', 'reference_text' => 'Second requirement item.'],
            ['source_element_key' => 'paragraph-1', 'source_element_type' => 'paragraph', 'section_number' => '2.', 'section_title' => 'Beta', 'reference_text' => 'Paragraph under the second section.'],
            ['source_element_key' => 'tbl0-row0', 'source_element_type' => 'table_row', 'section_number' => '2.', 'section_title' => 'Beta', 'reference_text' => 'Field: Value'],
            ['source_element_key' => 'img0', 'source_element_type' => 'image', 'section_number' => '2.', 'section_title' => 'Beta', 'reference_text' => 'A described figure.'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function largeCandidateFixture(): array
    {
        $candidates = $this->candidateFixture();
        $candidates[] = [
            'page_id' => 43,
            'title' => 'Release Procedure',
            'slug' => 'release-procedure',
            'page_type' => 'article',
            'page_version_id' => 79,
            'version_number' => 4,
            'content' => '# Release Procedure',
            'page_has_subsections' => false,
            'valid_target_headings' => [],
            'truncated' => false,
            'mention_count' => 1,
        ];

        foreach ($candidates as $index => $candidate) {
            $candidates[$index]['content'] .= "\n\n".str_repeat('Current authoritative requirement context. ', 160);
        }

        return $candidates;
    }

    /** @return list<array<string, mixed>> */
    private function largeIndexFixture(): array
    {
        return array_map(static fn (int $id): array => [
            'id' => $id,
            'title' => "Current Wiki Page {$id}",
            'slug' => "current-wiki-page-{$id}",
            'page_type' => 'article',
            'status' => 'approved',
            'excerpt' => str_repeat('Indexed current knowledge. ', 12),
            'open_lint_count' => 0,
            'updated_at' => '2026-08-12T12:00:00+00:00',
        ], range(1, 12));
    }

    private function clientReturning(array $decision): EnterpriseWikiMaintainerDecisionAiClient
    {
        return $this->clientWithRawResponse(['status' => 'completed', 'output_text' => json_encode($decision)]);
    }

    private function clientWithRawResponse(array $responseBody): EnterpriseWikiMaintainerDecisionAiClient
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')->once()->andReturn(array_replace_recursive([
            'id' => 'resp_test',
            'status' => 'completed',
            'output_text' => '',
            'output' => [],
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 20,
                'total_tokens' => 120,
            ],
            '_meta' => [
                'request_id' => 'req_test',
                'http_status' => 200,
                'provider' => 'openai',
                'deployment_name' => null,
                'provider_region' => null,
            ],
        ], $responseBody));

        return app(EnterpriseWikiMaintainerDecisionAiClient::class);
    }

    private function userMessageText(array $payload): string
    {
        return (string) data_get($payload, 'input.1.content.0.text', '');
    }

    private function developerMessageText(array $payload): string
    {
        return (string) data_get($payload, 'input.0.content.0.text', '');
    }
}
