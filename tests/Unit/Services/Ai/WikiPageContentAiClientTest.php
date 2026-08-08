<?php

namespace Tests\Unit\Services\Ai;

use App\Data\Ai\Capacity\AiCapacityRequest;
use App\Data\Ai\Capacity\AiTimeoutRequest;
use App\Exceptions\EnterpriseWikiAiOutputCapacityExceededException;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiAiCapacityPlanner;
use App\Services\EnterpriseWiki\EnterpriseWikiAiRequestTimeoutPolicy;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class WikiPageContentAiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    // =========================================================================
    // Payload structure
    // =========================================================================

    public function test_payload_includes_strict_json_schema_and_low_reasoning(): void
    {
        $payload = $this->capturePayload();

        $format = $payload['text']['format'];

        $this->assertSame('gpt-5', $payload['model']);
        $this->assertSame('json_schema', $format['type']);
        $this->assertSame('wiki_page_content_generation', $format['name']);
        $this->assertTrue($format['strict']);
        $this->assertIsArray($format['schema']);
        $this->assertIsInt($payload['max_output_tokens']);
        $this->assertGreaterThan(0, $payload['max_output_tokens']);
        $this->assertSame('low', data_get($payload, 'reasoning.effort'));
        $this->assertFalse($payload['store']);
        $this->assertArrayNotHasKey('temperature', $payload);
    }

    // Wiki run-6: max_output_tokens (and, by extension, the request timeout — see
    // EnterpriseWikiAiRequestTimeoutPolicy) is no longer a flat constant — it is derived from
    // EnterpriseWikiAiCapacityPlanner's 'enterprise_wiki_page_content' profile, the same
    // mechanism EnterpriseWikiMaintainerDecisionAiClient already uses. Computing the SAME plan
    // independently here (rather than hardcoding an expected number) is the same pattern
    // EnterpriseWikiMaintainerDecisionAiClientTest uses.
    public function test_payload_uses_capacity_planner_derived_max_output_tokens(): void
    {
        $sourceText = 'Noe kildetekst.';
        $payload = $this->capturePayload(pageType: 'article', sourceText: $sourceText);

        $expectedPlan = app(EnterpriseWikiAiCapacityPlanner::class)->plan(new AiCapacityRequest(
            operationType: 'enterprise_wiki_page_content',
            model: 'gpt-5',
            inputSizeChars: mb_strlen($sourceText),
            expectedResultObjects: 8, // WikiPageContentAiClient::EXPECTED_BLOCK_COUNTS['article']
        ));

        $this->assertSame($expectedPlan->chosenMaxOutputTokens, $payload['max_output_tokens']);
        $this->assertGreaterThan(0, $payload['max_output_tokens']);
    }

    public function test_article_pages_get_a_larger_budget_than_summary_pages_for_the_same_source(): void
    {
        $sourceText = 'Noe kildetekst.';
        $articlePayload = $this->capturePayload(pageType: 'article', sourceText: $sourceText);
        $summaryPayload = $this->capturePayload(pageType: 'summary', sourceText: $sourceText);

        $this->assertGreaterThan($summaryPayload['max_output_tokens'], $articlePayload['max_output_tokens']);
    }

    public function test_larger_source_text_produces_a_larger_max_output_tokens_than_a_small_document(): void
    {
        $smallPayload = $this->capturePayload(sourceText: 'Kort tekst.');
        $largePayload = $this->capturePayload(sourceText: str_repeat('ITIL Incident Management prosessbeskrivelse. ', 400));

        $this->assertGreaterThan($smallPayload['max_output_tokens'], $largePayload['max_output_tokens']);
    }

    public function test_generate_from_source_uses_a_capacity_derived_http_timeout(): void
    {
        $capturedTimeout = null;
        $sourceText = 'Noe kildetekst.';

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturnUsing(function (array $payload, int $timeoutSeconds = 120) use (&$capturedTimeout): array {
                $capturedTimeout = $timeoutSeconds;

                return [
                    'status' => 'completed',
                    'output_text' => json_encode(['page' => ['blocks' => [
                        $this->sourceBasedBlock('# Test Page'),
                    ]]]),
                ];
            });

        app(WikiPageContentAiClient::class)->generateFromSource('Test Page', 'article', $sourceText, 'no');

        $expectedPlan = app(EnterpriseWikiAiCapacityPlanner::class)->plan(new AiCapacityRequest(
            operationType: 'enterprise_wiki_page_content',
            model: 'gpt-5',
            inputSizeChars: mb_strlen($sourceText),
            expectedResultObjects: 8,
        ));
        $expectedTimeout = app(EnterpriseWikiAiRequestTimeoutPolicy::class)->resolve(new AiTimeoutRequest(
            operationType: 'enterprise_wiki_page_content',
            inputSizeChars: mb_strlen($sourceText),
            chosenMaxOutputTokens: $expectedPlan->chosenMaxOutputTokens,
        ))->timeoutSeconds;

        $this->assertSame($expectedTimeout, $capturedTimeout);
        $this->assertGreaterThanOrEqual(30, $capturedTimeout);
        $this->assertLessThanOrEqual(180, $capturedTimeout);
    }

    // =========================================================================
    // Wiki-link catalog (8I-4)
    // =========================================================================

    public function test_link_catalog_is_included_in_the_user_prompt(): void
    {
        $catalog = [
            ['slug' => 'business-case', 'title' => 'Business Case', 'page_type' => 'concept'],
            ['slug' => 'prosjekteier', 'title' => 'Prosjekteier', 'page_type' => 'entity'],
        ];

        $payload = $this->capturePayload(linkCatalog: $catalog);
        $userPrompt = $this->userPromptTextFromPayload($payload);

        $this->assertStringContainsString('ALLOWED WIKILINK TARGETS (2 pages):', $userPrompt);
        $this->assertStringContainsString('business-case', $userPrompt);
        $this->assertStringContainsString('prosjekteier', $userPrompt);
    }

    // Runtime fix (run 18): the catalog must show exact, copyable canonical [[slug|Title]]
    // markup per target — reduces the chance the model writes a title-cased bare slug instead
    // of the real, differently-cased slug (e.g. [[Advania]] instead of [[advania|Advania]]).
    public function test_catalog_shows_exact_copyable_canonical_markup_per_target(): void
    {
        $catalog = [
            ['slug' => 'advania', 'title' => 'Advania', 'page_type' => 'entity'],
            ['slug' => 'risikostyring', 'title' => 'Risikostyring', 'page_type' => 'concept'],
        ];

        $payload = $this->capturePayload(linkCatalog: $catalog);
        $userPrompt = $this->userPromptTextFromPayload($payload);

        $this->assertStringContainsString('[[advania|Advania]]', $userPrompt);
        $this->assertStringContainsString('[[risikostyring|Risikostyring]]', $userPrompt);
    }

    public function test_developer_prompt_instructs_copying_the_slug_exactly(): void
    {
        $payload = $this->capturePayload();
        $developerPrompt = $this->developerPromptTextFromPayload($payload);

        $this->assertStringContainsString('copy', mb_strtolower($developerPrompt));
        $this->assertStringContainsString('slug', mb_strtolower($developerPrompt));
    }

    // =========================================================================
    // Page responsibility (reduce cross-page repetition)
    // =========================================================================

    public function test_developer_prompt_documents_page_responsibility_for_every_page_type(): void
    {
        foreach (['article', 'summary', 'concept', 'entity'] as $pageType) {
            $payload = $this->capturePayload(pageType: $pageType);
            $developerPrompt = $this->developerPromptTextFromPayload($payload);

            $this->assertStringContainsString('PAGE RESPONSIBILITY', $developerPrompt, "page type: {$pageType}");
            $this->assertStringContainsString('excluded', mb_strtolower($developerPrompt), "page type: {$pageType}");
        }
    }

    public function test_developer_prompt_treats_excluded_topics_as_a_hard_binding_boundary(): void
    {
        foreach (['article', 'summary', 'concept', 'entity'] as $pageType) {
            $payload = $this->capturePayload(pageType: $pageType);
            $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($payload));

            $this->assertStringContainsString('hard, binding boundary', $developerPrompt, "page type: {$pageType}");
        }
    }

    public function test_developer_prompt_caps_reference_only_topics_to_one_sentence(): void
    {
        foreach (['article', 'summary', 'concept', 'entity'] as $pageType) {
            $payload = $this->capturePayload(pageType: $pageType);
            $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($payload));

            $this->assertStringContainsString('at most one short sentence', $developerPrompt, "page type: {$pageType}");
        }
    }

    public function test_concept_and_entity_structure_caps_section_count_to_owned_topics(): void
    {
        foreach (['concept', 'entity'] as $pageType) {
            $payload = $this->capturePayload(pageType: $pageType);
            $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($payload));

            $this->assertStringContainsString('at most one ## section per item', $developerPrompt, "page type: {$pageType}");
        }
    }

    public function test_concept_prompt_requires_exact_planned_section_headings(): void
    {
        $conceptPrompt = $this->developerPromptTextFromPayload($this->capturePayload(pageType: 'concept'));

        $this->assertStringContainsString('the heading line must be exactly "## " followed by that item text copied verbatim', $conceptPrompt);
        $this->assertStringContainsString('Do not paraphrase, shorten, use ###, bold labels, or plain paragraph labels', $conceptPrompt);

        foreach (['article', 'summary'] as $pageType) {
            $developerPrompt = $this->developerPromptTextFromPayload($this->capturePayload(pageType: $pageType));

            $this->assertStringNotContainsString('the heading line must be exactly "## " followed by that item text copied verbatim', $developerPrompt);
        }
    }

    /**
     * The relevance guard that keeps best_practice content on THIS page's owned topics (never a
     * broad sweep of the wider subject area) is unchanged. What changed: a thin source document
     * now only justifies thin SOURCE_BASED content — it must never be read as licence to skip the
     * best-practice synthesis, and doubt is resolved on relevance rather than by suppressing a
     * well-founded recommendation outright.
     */
    public function test_best_practice_rule_keeps_the_relevance_guard_without_suppressing_recommendations(): void
    {
        $payload = $this->capturePayload(pageType: 'concept');
        $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($payload));

        $this->assertStringContainsString('never add general professional/industry elaboration of the wider subject area', $developerPrompt);
        $this->assertStringContainsString('a thin source document justifies thin source_based content', $developerPrompt);
        $this->assertStringContainsString('it never justifies skipping the best-practice synthesis', $developerPrompt);
        $this->assertStringContainsString('resolve doubt on relevance, not on whether to propose at all', $developerPrompt);
    }

    public function test_developer_prompt_forbids_repeating_the_same_sentence(): void
    {
        foreach (['article', 'summary', 'concept', 'entity'] as $pageType) {
            $payload = $this->capturePayload(pageType: $pageType);
            $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($payload));

            $this->assertStringContainsString('never restate the same sentence', $developerPrompt, "page type: {$pageType}");
        }
    }

    public function test_summary_developer_prompt_instructs_basing_on_the_finished_article(): void
    {
        $payload = $this->capturePayload(pageType: 'summary');
        $developerPrompt = $this->developerPromptTextFromPayload($payload);

        $this->assertStringContainsString('Additional context', $developerPrompt);
        $this->assertStringContainsString('finished article', mb_strtolower($developerPrompt));
        $this->assertStringContainsString('independently re-deriving', mb_strtolower($developerPrompt));
    }

    public function test_concept_and_entity_developer_prompts_instruct_linking_instead_of_repeating(): void
    {
        foreach (['concept', 'entity'] as $pageType) {
            $payload = $this->capturePayload(pageType: $pageType);
            $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($payload));

            $this->assertStringContainsString('never a repeated or newly-invented explanation', $developerPrompt, "page type: {$pageType}");
        }
    }

    public function test_additional_context_with_responsibility_guidance_reaches_the_user_prompt(): void
    {
        $context = "This page's own content responsibility — explain ONLY these in depth, nothing beyond them:\n- Definer ITIL som rammeverk.\n\nEXCLUDED — do not mention these at all on this page, in any depth:\n- Detaljert Incident Management-flyt.";

        $payload = $this->capturePayload(pageType: 'concept', additionalContext: $context);
        $userPrompt = $this->userPromptTextFromPayload($payload);

        $this->assertStringContainsString('Definer ITIL som rammeverk.', $userPrompt);
        $this->assertStringContainsString('Detaljert Incident Management-flyt.', $userPrompt);
    }

    public function test_empty_link_catalog_is_documented_as_no_pages_available(): void
    {
        $payload = $this->capturePayload(linkCatalog: []);
        $userPrompt = $this->userPromptTextFromPayload($payload);

        $this->assertStringContainsString('ALLOWED WIKILINK TARGETS (0 pages):', $userPrompt);
        $this->assertStringContainsString('No other pages available to link to.', $userPrompt);
    }

    public function test_source_elements_are_included_in_the_user_prompt(): void
    {
        $payload = $this->capturePayload(sourceElements: [
            [
                'source_element_key' => 'paragraph-123',
                'source_element_type' => 'paragraph',
                'reference_text' => 'Dokumentert kildeelement.',
            ],
        ]);
        $userPrompt = $this->userPromptTextFromPayload($payload);

        $this->assertStringContainsString('SOURCE ELEMENTS (1 elements):', $userPrompt);
        $this->assertStringContainsString('paragraph-123', $userPrompt);
        $this->assertStringContainsString('Every source_based block must cite one or more source_element_key values', $userPrompt);
    }

    public function test_adding_a_link_catalog_does_not_change_the_model_token_reasoning_or_store_contract(): void
    {
        $catalog = [['slug' => 'business-case', 'title' => 'Business Case', 'page_type' => 'concept']];

        $payloadWithoutCatalog = $this->capturePayload();
        $payloadWithCatalog = $this->capturePayload(linkCatalog: $catalog);

        $this->assertSame('gpt-5', $payloadWithCatalog['model']);
        // The link catalog is never part of the capacity estimate (page-type/source-size driven
        // only), so it must not change the chosen output-token budget either.
        $this->assertSame($payloadWithoutCatalog['max_output_tokens'], $payloadWithCatalog['max_output_tokens']);
        $this->assertSame('low', data_get($payloadWithCatalog, 'reasoning.effort'));
        $this->assertFalse($payloadWithCatalog['store']);
    }

    public function test_developer_prompt_documents_slug_and_slug_anchor_syntax(): void
    {
        foreach (['article', 'summary', 'concept', 'entity'] as $pageType) {
            $payload = $this->capturePayload(pageType: $pageType);
            $developerPrompt = $this->developerPromptTextFromPayload($payload);

            $this->assertStringContainsString('[[target-slug|natural visible text]]', $developerPrompt, "page type: {$pageType}");
            $this->assertStringContainsString('[[target-slug]]', $developerPrompt, "page type: {$pageType}");
        }
    }

    public function test_schema_requires_page_blocks_with_provenance_fields(): void
    {
        $payload = $this->capturePayload();

        $schema = $payload['text']['format']['schema'];

        $this->assertSame(['page'], $schema['required']);
        $this->assertSame(['blocks'], $schema['properties']['page']['required']);
        $blockSchema = $schema['properties']['page']['properties']['blocks']['items'];
        $this->assertSame([
            'markdown',
            'content_origin',
            'source_element_keys',
            'source_element_types',
            'best_practice_reason',
            'link_intents',
        ], $blockSchema['required']);
        $this->assertFalse($schema['properties']['page']['additionalProperties']);
        $this->assertFalse($schema['additionalProperties']);
    }

    // =========================================================================
    // structural content_origin (Wiki run-5)
    // =========================================================================

    public function test_schema_allows_structural_content_origin(): void
    {
        $payload = $this->capturePayload();
        $schema = $payload['text']['format']['schema'];
        $blockSchema = $schema['properties']['page']['properties']['blocks']['items'];

        $this->assertSame(
            ['source_based', 'best_practice', 'structural'],
            $blockSchema['properties']['content_origin']['enum'],
        );
    }

    public function test_developer_prompt_instructs_headings_and_cross_references_to_be_structural(): void
    {
        foreach (['article', 'summary', 'concept', 'entity'] as $pageType) {
            $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($this->capturePayload(pageType: $pageType)));

            $this->assertStringContainsString('structural', $developerPrompt, "page type: {$pageType}");
            $this->assertStringContainsString('the page title, a section heading', $developerPrompt, "page type: {$pageType}");
            $this->assertStringContainsString('cross-reference', $developerPrompt, "page type: {$pageType}");
        }
    }

    public function test_developer_prompt_requires_concrete_procynia_assertion_for_best_practice(): void
    {
        $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($this->capturePayload()));

        $this->assertStringContainsString('at least one concrete procynia assertion', $developerPrompt);
        $this->assertStringContainsString('a heading, title, or cross-reference is never best_practice by itself', $developerPrompt);
        $this->assertStringContainsString('never write a vague, generic sentence', $developerPrompt);
    }

    /**
     * The synthesis is a four-step reasoning procedure — understand the source, identify which
     * professional subject the page actually deals with, compare the source against mature
     * practice for THAT subject, then contribute where it adds value — deliberately replacing the
     * previous generic gap checklist, which invited recommendations that would read the same for
     * any unrelated document.
     */
    public function test_developer_prompt_instructs_subject_specific_best_practice_synthesis(): void
    {
        $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($this->capturePayload()));

        $this->assertStringContainsString('professional best-practice synthesis', $developerPrompt);
        $this->assertStringContainsString('step 1 — understand the source', $developerPrompt);
        $this->assertStringContainsString('step 2 — identify the subject', $developerPrompt);
        $this->assertStringContainsString('step 3 — compare against mature practice', $developerPrompt);
        $this->assertStringContainsString('step 4 — contribute where it adds value', $developerPrompt);
        $this->assertStringContainsString('the established good practice for that subject specifically', $developerPrompt);
        $this->assertStringContainsString('depth over breadth', $developerPrompt);
        $this->assertStringContainsString('generic checklist items', $developerPrompt);
    }

    /**
     * The assertiveness contract: a recommendation being absent from the source is the REASON to
     * propose it (that absence is what makes it a Procynia contribution), never grounds to
     * suppress it — with human review named as the safety mechanism that makes proposing safe.
     */
    public function test_developer_prompt_does_not_suppress_recommendations_absent_from_the_source(): void
    {
        $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($this->capturePayload()));

        $this->assertStringContainsString('being absent from the source is never a reason to withhold a relevant recommendation', $developerPrompt);
        $this->assertStringContainsString('procynia is expected to add professional value here', $developerPrompt);
        $this->assertStringContainsString('routed to a human reviewer', $developerPrompt);
    }

    /**
     * Assertiveness must not become padding: zero best_practice blocks stays valid when the
     * source already treats its subject well, and no quota may ever be manufactured.
     */
    public function test_developer_prompt_allows_zero_best_practice_blocks_and_forbids_padding(): void
    {
        $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($this->capturePayload()));

        $this->assertStringContainsString('no quota, no minimum, no padding', $developerPrompt);
        $this->assertStringContainsString('zero best_practice blocks is the correct outcome', $developerPrompt);
        $this->assertStringContainsString('never manufacture a recommendation to fill space', $developerPrompt);
    }

    public function test_generate_page_from_source_accepts_a_structural_block(): void
    {
        $client = $this->clientReturning([
            'page' => [
                'blocks' => [
                    $this->structuralBlock('# Test Page'),
                    $this->sourceBasedBlock('Kildeinnhold.'),
                ],
            ],
        ]);

        $result = $client->generatePageFromSource('Test Page', 'article', 'Noe kildetekst.', 'no');

        $this->assertSame('structural', $result['blocks'][0]['content_origin']);
        $this->assertNull($result['blocks'][0]['best_practice_reason'] ?? null);
    }

    public function test_generate_page_from_source_still_rejects_an_unsupported_content_origin(): void
    {
        $block = $this->sourceBasedBlock('# Test Page');
        $block['content_origin'] = 'mixed';

        $client = $this->clientReturning(['page' => ['blocks' => [$block]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has invalid content_origin');

        $client->generatePageFromSource('Test Page', 'article', 'Noe kildetekst.', 'no');
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function test_generate_from_source_returns_markdown_on_top_level_output_text(): void
    {
        $expectedMarkdown = "# Test Page\n\nDette er generert innhold.";
        $client = $this->clientReturning([
            'page' => [
                'blocks' => [
                    $this->sourceBasedBlock($expectedMarkdown),
                ],
            ],
        ]);

        $result = $client->generateFromSource('Test Page', 'article', 'Noe kildetekst.', 'no');

        $this->assertSame($expectedMarkdown, $result);
    }

    public function test_generate_from_source_returns_markdown_from_nested_output_text(): void
    {
        $expectedMarkdown = "# Test Page\n\nDette er generert innhold.";
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
                            'text' => json_encode([
                                'page' => [
                                    'blocks' => [
                                        $this->sourceBasedBlock($expectedMarkdown),
                                    ],
                                ],
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ],
            ],
        ]);

        $result = $client->generateFromSource('Test Page', 'article', 'Noe kildetekst.', 'no');

        $this->assertSame($expectedMarkdown, $result);
    }

    // =========================================================================
    // Error handling
    // =========================================================================

    public function test_generate_from_source_throws_when_ai_is_disabled(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);
        $client = app(WikiPageContentAiClient::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not enabled/');

        $client->generateFromSource('Test Page', 'article', 'Noe kildetekst.', 'no');
    }

    public function test_generate_from_source_throws_on_empty_response_and_logs_safe_diagnostics(): void
    {
        Log::spy();

        $client = $this->clientWithRawResponse([
            'id' => 'resp_empty',
            'output_text' => '',
            'output' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no output text/');

        $client->generateFromSource('Test Page', 'article', 'Noe kildetekst.', 'no');

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === '[PROCYNIA][WIKI_PAGE_CONTENT] OpenAI response diagnostics.'
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
                && ! array_key_exists('sourceText', $context)
                && ! array_key_exists('additionalContext', $context)
                && ! array_key_exists('pageTitle', $context)
                && ! array_key_exists('input', $context)
                && ! array_key_exists('output', $context)
                && ! array_key_exists('prompt', $context);
        });
    }

    /**
     * Wiki run-6: a single incomplete/max_output_tokens response is no longer an immediate,
     * permanent failure — EnterpriseWikiAiCapacityRetryExecutor (adopted from
     * EnterpriseWikiMaintainerDecisionAiClient) makes exactly one bounded capacity retry at a
     * higher budget first. Only when BOTH attempts still come back incomplete/max_output_tokens
     * does this now surface as EnterpriseWikiAiOutputCapacityExceededException — never an
     * unbounded/automatic retry loop (mirrors
     * EnterpriseWikiMaintainerDecisionAiClientTest::test_decide_throws_capacity_exceeded_when_both_attempts_hit_max_output_tokens()).
     */
    public function test_generate_from_source_throws_capacity_exceeded_after_bounded_retry_on_incomplete_max_output_tokens(): void
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')->twice()->andReturn([
            'id' => 'resp_incomplete',
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output_text' => '{"page":{"markdown":"# Test Page',
            'output' => [],
            'usage' => ['output_tokens' => 20],
        ]);

        $this->expectException(EnterpriseWikiAiOutputCapacityExceededException::class);
        $this->expectExceptionMessageMatches('/WikiPageContentAiClient: exhausted capacity retry/');
        $this->expectExceptionMessageMatches('/retry level 1/');
        $this->expectExceptionMessageMatches('/max_output_tokens/');

        app(WikiPageContentAiClient::class)->generateFromSource('Test Page', 'article', 'Noe kildetekst.', 'no');
    }

    /**
     * A non-token incomplete reason (e.g. a content filter) is never treated as a capacity
     * problem — it must propagate on the very first attempt, with no retry at all.
     */
    public function test_generate_from_source_does_not_retry_a_non_token_incomplete_reason(): void
    {
        Log::spy();

        $client = $this->clientWithRawResponse([
            'id' => 'resp_incomplete_other',
            'status' => 'incomplete',
            'incomplete_details' => [
                'reason' => 'content_filter',
            ],
            'output_text' => '',
            'output' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/OpenAI response was incomplete\..*content_filter/s');

        $client->generateFromSource('Test Page', 'article', 'Noe kildetekst.', 'no');
    }

    public function test_generate_from_source_throws_on_refusal_and_logs_safe_diagnostics(): void
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

        $client->generateFromSource('Test Page', 'article', 'Noe kildetekst.', 'no');

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === '[PROCYNIA][WIKI_PAGE_CONTENT] OpenAI response diagnostics.'
                && ($context['response_id'] ?? null) === 'resp_refusal'
                && ($context['response_status'] ?? null) === 'completed'
                && ($context['output_text_length'] ?? null) === 0
                && ($context['output_item_types'] ?? null) === ['message']
                && ($context['content_item_types'] ?? null) === ['refusal']
                && ($context['has_refusal'] ?? null) === true;
        });
    }

    public function test_generate_from_source_throws_on_invalid_json_and_logs_safe_diagnostics(): void
    {
        Log::spy();

        $client = $this->clientWithRawResponse([
            'id' => 'resp_invalid_json',
            'output_text' => 'dette er ikke json',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        $client->generateFromSource('Test Page', 'article', 'Noe kildetekst.', 'no');

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === '[PROCYNIA][WIKI_PAGE_CONTENT] OpenAI response diagnostics.'
                && ($context['response_id'] ?? null) === 'resp_invalid_json'
                && ($context['http_status'] ?? null) === 200
                && ($context['response_status'] ?? null) === 'completed'
                && ($context['output_text_length'] ?? null) === mb_strlen('dette er ikke json', 'UTF-8')
                && ($context['output_item_types'] ?? null) === []
                && ($context['content_item_types'] ?? null) === []
                && ($context['has_refusal'] ?? null) === false
                && ($context['token_usage']['input_tokens'] ?? null) === 100
                && ($context['token_usage']['output_tokens'] ?? null) === 20
                && ($context['token_usage']['total_tokens'] ?? null) === 120;
        });
    }

    public function test_generate_from_source_throws_on_missing_blocks_and_logs_safe_diagnostics(): void
    {
        Log::spy();

        $client = $this->clientWithRawResponse([
            'id' => 'resp_missing_blocks',
            'output_text' => json_encode(['page' => []]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/generated page blocks were empty/');

        $client->generateFromSource('Test Page', 'article', 'Noe kildetekst.', 'no');

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === '[PROCYNIA][WIKI_PAGE_CONTENT] OpenAI response diagnostics.'
                && ($context['response_id'] ?? null) === 'resp_missing_blocks'
                && ($context['http_status'] ?? null) === 200
                && ($context['response_status'] ?? null) === 'completed'
                && ($context['output_text_length'] ?? null) > 0
                && ($context['output_item_types'] ?? null) === []
                && ($context['content_item_types'] ?? null) === []
                && ($context['has_refusal'] ?? null) === false;
        });
    }

    // =========================================================================
    // Wiki run-586: planned section repair
    // =========================================================================

    public function test_repair_planned_sections_includes_existing_markdown_and_issues_in_the_user_prompt(): void
    {
        $capturedPayload = null;

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedPayload): array {
                $capturedPayload = $payload;

                return [
                    'status' => 'completed',
                    'output_text' => json_encode([
                        'sections' => [$this->sourceBasedSection('En tom seksjon', 'Repaired content.')],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            });

        app(WikiPageContentAiClient::class)->repairPlannedSections(
            pageTitle: 'Test Page',
            pageType: 'concept',
            existingMarkdown: "# Test Page\n\n## En tom seksjon",
            issues: [
                ['type' => 'planned_section_empty', 'planned_topic' => 'En tom seksjon', 'heading' => 'En tom seksjon'],
            ],
            sourceText: 'Kildetekst med relevant informasjon.',
            languageCode: 'no',
        );

        $userPrompt = $this->userPromptTextFromPayload($capturedPayload);
        $developerPrompt = $this->developerPromptTextFromPayload($capturedPayload);

        $this->assertStringContainsString('## En tom seksjon', $userPrompt);
        $this->assertStringContainsString('SECTIONS TO REPAIR', $userPrompt);
        $this->assertStringContainsString('En tom seksjon', $userPrompt);
        $this->assertStringContainsString('Kildetekst med relevant informasjon.', $userPrompt);
        $this->assertStringContainsString('repairing specific missing or empty planned sections', mb_strtolower($developerPrompt));
        $this->assertStringContainsString('previously generated', mb_strtolower($developerPrompt));
        $this->assertStringContainsString('never return an empty section', mb_strtolower($developerPrompt));
        $this->assertStringContainsString('do not write the section heading', mb_strtolower($developerPrompt));
    }

    public function test_repair_planned_sections_uses_the_same_strict_schema(): void
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturnUsing(function (array $payload): array {
                $this->assertSame('json_schema', $payload['text']['format']['type']);
                $this->assertTrue($payload['text']['format']['strict']);

                return [
                    'status' => 'completed',
                    'output_text' => json_encode([
                        'sections' => [$this->sourceBasedSection('X', 'Repaired body text.')],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            });

        $result = app(WikiPageContentAiClient::class)->repairPlannedSections(
            pageTitle: 'Test Page',
            pageType: 'concept',
            existingMarkdown: '# Test Page',
            issues: [['type' => 'planned_section_empty', 'planned_topic' => 'X', 'heading' => 'X']],
            sourceText: 'Kildetekst.',
            languageCode: 'no',
        );

        $this->assertSame('X', $result[0]['planned_topic']);
        $this->assertSame('Repaired body text.', $result[0]['blocks'][0]['markdown']);
    }

    public function test_repair_planned_sections_throws_when_response_has_no_sections(): void
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturn([
                'status' => 'completed',
                'output_text' => json_encode(['sections' => []]),
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/repaired sections were empty/');

        app(WikiPageContentAiClient::class)->repairPlannedSections(
            pageTitle: 'Test Page',
            pageType: 'concept',
            existingMarkdown: '# Test Page',
            issues: [['type' => 'planned_section_empty', 'planned_topic' => 'X', 'heading' => 'X']],
            sourceText: 'Kildetekst.',
            languageCode: 'no',
        );
    }

    public function test_repair_planned_sections_throws_when_a_section_body_is_empty(): void
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturn([
                'status' => 'completed',
                'output_text' => json_encode([
                    'sections' => [$this->sourceBasedSection('X', '')],
                ]),
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/markdown was empty/');

        app(WikiPageContentAiClient::class)->repairPlannedSections(
            pageTitle: 'Test Page',
            pageType: 'concept',
            existingMarkdown: '# Test Page',
            issues: [['type' => 'planned_section_empty', 'planned_topic' => 'X', 'heading' => 'X']],
            sourceText: 'Kildetekst.',
            languageCode: 'no',
        );
    }

    public function test_repair_planned_sections_throws_when_a_section_does_not_match_a_requested_topic(): void
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturn([
                'status' => 'completed',
                'output_text' => json_encode([
                    'sections' => [$this->sourceBasedSection('Not the requested topic', 'Some content.')],
                ]),
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/did not match any requested planned_topic/');

        app(WikiPageContentAiClient::class)->repairPlannedSections(
            pageTitle: 'Test Page',
            pageType: 'concept',
            existingMarkdown: '# Test Page',
            issues: [['type' => 'planned_section_empty', 'planned_topic' => 'X', 'heading' => 'X']],
            sourceText: 'Kildetekst.',
            languageCode: 'no',
        );
    }

    // =========================================================================
    // Capacity policy (Wiki run-6)
    // =========================================================================

    /**
     * Test B: a large-but-valid full-page response (well beyond the old flat 6000-token
     * ceiling) is accepted end-to-end once max_output_tokens is sized by the capacity planner
     * instead of a fixed constant.
     */
    public function test_generate_page_from_source_accepts_a_large_valid_response_beyond_the_old_flat_ceiling(): void
    {
        $manyBlocks = [];
        for ($i = 0; $i < 12; $i++) {
            $manyBlocks[] = $this->sourceBasedBlock("Avsnitt {$i} med reelt innhold i en stor artikkel.");
        }

        $client = $this->clientReturning(['page' => ['blocks' => $manyBlocks]]);

        $result = $client->generatePageFromSource(
            'Stor artikkel',
            'article',
            str_repeat('ITIL Incident Management prosessbeskrivelse. ', 400),
            'no',
        );

        $this->assertCount(12, $result['blocks']);
    }

    /**
     * Test C/D (run-6 regression): repairing 2 planned sections at once — the exact run-6 shape
     * (article page, 2 missing sections) — must be sized with a materially larger budget than
     * repairing just 1, via EnterpriseWikiAiCapacityPlanner::planBatchCall(). Before this fix,
     * both shared the identical flat 6000-token ceiling regardless of section count.
     */
    public function test_repairing_two_sections_computes_a_larger_budget_than_repairing_one(): void
    {
        $capturedPayloads = [];
        $capture = function (array $payload) use (&$capturedPayloads): array {
            $capturedPayloads[] = $payload;

            return [
                'status' => 'completed',
                'output_text' => json_encode([
                    'sections' => [$this->sourceBasedSection('X', 'Repaired body text.')],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        };

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')->twice()->andReturnUsing($capture);

        $client = app(WikiPageContentAiClient::class);

        $client->repairPlannedSections(
            pageTitle: 'Masterdata ITIL',
            pageType: 'article',
            existingMarkdown: '# Masterdata ITIL',
            issues: [['type' => 'planned_section_missing', 'planned_topic' => 'X', 'heading' => 'X']],
            sourceText: 'Kildetekst.',
            languageCode: 'no',
        );

        $client->repairPlannedSections(
            pageTitle: 'Masterdata ITIL',
            pageType: 'article',
            existingMarkdown: '# Masterdata ITIL',
            issues: [
                ['type' => 'planned_section_missing', 'planned_topic' => 'X', 'heading' => 'X'],
                ['type' => 'planned_section_missing', 'planned_topic' => 'Y', 'heading' => 'Y'],
            ],
            sourceText: 'Kildetekst.',
            languageCode: 'no',
        );

        $this->assertGreaterThan(
            $capturedPayloads[0]['max_output_tokens'],
            $capturedPayloads[1]['max_output_tokens'],
        );
    }

    /**
     * Test D/E (run-6 regression, exact shape): a 2-section repair that still comes back
     * incomplete/max_output_tokens after one bounded capacity retry throws
     * EnterpriseWikiAiOutputCapacityExceededException — clearly naming the operation (repair),
     * the configured/chosen budget, and the response id — never an unbounded retry loop (exactly
     * 2 attempts: the mock would fail verification if a 3rd were attempted).
     */
    public function test_repair_planned_sections_throws_capacity_exceeded_after_bounded_retry(): void
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')->twice()->andReturn([
            'id' => 'resp_00b83d3e7a626e95016a771e74f498819e9268234c502287c2',
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output_text' => '{"sections":[',
            'output' => [],
            'usage' => ['output_tokens' => 6000],
        ]);

        $this->expectException(EnterpriseWikiAiOutputCapacityExceededException::class);
        $this->expectExceptionMessageMatches('/WikiPageContentAiClient\(repair\): exhausted capacity retry/');
        $this->expectExceptionMessageMatches('/max_output_tokens/');
        $this->expectExceptionMessageMatches('/resp_00b83d3e7a626e95016a771e74f498819e9268234c502287c2/');

        app(WikiPageContentAiClient::class)->repairPlannedSections(
            pageTitle: 'Masterdata ITIL',
            pageType: 'article',
            existingMarkdown: '# Masterdata ITIL',
            issues: [
                ['type' => 'planned_section_missing', 'planned_topic' => 'Hvordan ITIL brukes operativt i leveransen', 'heading' => 'Hvordan ITIL brukes operativt i leveransen'],
                ['type' => 'planned_section_missing', 'planned_topic' => 'Metodikk for etablering, innføring og oppfølging av ITIL-prosesser', 'heading' => 'Metodikk for etablering, innføring og oppfølging av ITIL-prosesser'],
            ],
            sourceText: 'Kildetekst.',
            languageCode: 'no',
        );
    }

    /**
     * Wiki run-6: repairUserPrompt() previously sent the full flat source text AND the full
     * SOURCE ELEMENTS list (the same content, doubled) whenever source elements were available —
     * a direct contributor to run 6's oversized repair input (15594 input tokens). When source
     * elements exist, the flat copy must be omitted.
     */
    public function test_repair_prompt_omits_duplicate_flat_source_text_when_source_elements_are_present(): void
    {
        $capturedPayload = null;

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedPayload): array {
                $capturedPayload = $payload;

                return [
                    'status' => 'completed',
                    'output_text' => json_encode([
                        'sections' => [$this->sourceBasedSection('X', 'Repaired body text.')],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            });

        app(WikiPageContentAiClient::class)->repairPlannedSections(
            pageTitle: 'Test Page',
            pageType: 'article',
            existingMarkdown: '# Test Page',
            issues: [['type' => 'planned_section_missing', 'planned_topic' => 'X', 'heading' => 'X']],
            sourceText: 'Dette er den fulle kildeteksten som ikke skal gjentas.',
            languageCode: 'no',
            sourceElements: [[
                'source_element_key' => 'paragraph-1',
                'source_element_type' => 'paragraph',
                'reference_text' => 'Dokumentert kildeelement.',
            ]],
        );

        $userPrompt = $this->userPromptTextFromPayload($capturedPayload);

        $this->assertStringNotContainsString('Dette er den fulle kildeteksten som ikke skal gjentas.', $userPrompt);
        $this->assertStringContainsString('see SOURCE ELEMENTS below', $userPrompt);
        $this->assertStringContainsString('paragraph-1', $userPrompt);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function capturePayload(
        string $pageTitle = 'Test Page',
        string $pageType = 'article',
        string $sourceText = 'Noe kildetekst.',
        string $languageCode = 'no',
        string $additionalContext = '',
        array $linkCatalog = [],
        array $sourceElements = [],
    ): array {
        $capturedPayload = null;

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedPayload, $pageTitle): array {
                $capturedPayload = $payload;

                return [
                    'status' => 'completed',
                    'output_text' => json_encode([
                        'page' => [
                            'blocks' => [
                                $this->sourceBasedBlock("# {$pageTitle}\n\nGenerert innhold."),
                            ],
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            });

        app(WikiPageContentAiClient::class)->generatePageFromSource(
            pageTitle: $pageTitle,
            pageType: $pageType,
            sourceText: $sourceText,
            languageCode: $languageCode,
            additionalContext: $additionalContext,
            linkCatalog: $linkCatalog,
            sourceElements: $sourceElements,
        );

        return (array) $capturedPayload;
    }

    private function userPromptTextFromPayload(array $payload): string
    {
        return (string) data_get($payload, 'input.1.content.0.text', '');
    }

    private function developerPromptTextFromPayload(array $payload): string
    {
        return (string) data_get($payload, 'input.0.content.0.text', '');
    }

    private function clientReturning(array $body): WikiPageContentAiClient
    {
        return $this->clientWithRawResponse([
            'output_text' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function clientWithRawResponse(array $responseBody): WikiPageContentAiClient
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

        return app(WikiPageContentAiClient::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceBasedBlock(string $markdown): array
    {
        return [
            'markdown' => $markdown,
            'content_origin' => 'source_based',
            'source_element_keys' => ['document-1-full-text'],
            'source_element_types' => ['manual'],
            'best_practice_reason' => null,
            'link_intents' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function structuralBlock(string $markdown): array
    {
        return [
            'markdown' => $markdown,
            'content_origin' => 'structural',
            'source_element_keys' => [],
            'source_element_types' => [],
            'best_practice_reason' => null,
            'link_intents' => [],
        ];
    }

    /**
     * Wiki run-593: one "sections" entry as repairPlannedSections() now expects — body content
     * only (no heading), keyed by the exact planned_topic it repairs.
     */
    private function sourceBasedSection(string $plannedTopic, string $bodyMarkdown): array
    {
        return [
            'planned_topic' => $plannedTopic,
            'blocks' => [$this->sourceBasedBlock($bodyMarkdown)],
        ];
    }
}
