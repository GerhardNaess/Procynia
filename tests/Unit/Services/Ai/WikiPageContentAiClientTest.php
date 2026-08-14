<?php

namespace Tests\Unit\Services\Ai;

use App\Data\Ai\Capacity\AiCapacityRequest;
use App\Data\Ai\Capacity\AiTimeoutRequest;
use App\Exceptions\EnterpriseWikiAiOutputCapacityExceededException;
use App\Exceptions\EnterpriseWikiInvalidUtf8Exception;
use App\Exceptions\EnterpriseWikiPlannedSectionRepairShapeException;
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

    public function test_invalid_source_element_text_is_rejected_before_the_ai_request_is_sent(): void
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldNotReceive('createResponse');

        try {
            app(WikiPageContentAiClient::class)->generatePageFromSource(
                pageTitle: 'Test Page',
                pageType: 'article',
                sourceText: 'Gyldig kildetekst.',
                languageCode: 'no',
                sourceElements: [[
                    'source_element_key' => 'paragraph-7',
                    'reference_text' => "Ugyldig \xB1 tekst",
                ]],
            );
            $this->fail('Expected invalid UTF-8 to stop request construction.');
        } catch (EnterpriseWikiInvalidUtf8Exception $exception) {
            $this->assertSame('source_elements[0].reference_text', $exception->fieldPath);
            $this->assertSame('paragraph-7', $exception->sourceElementKey);
        }
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
            ['page_id' => 101, 'slug' => 'business-case', 'title' => 'Business Case', 'page_type' => 'concept'],
            ['page_id' => 102, 'slug' => 'prosjekteier', 'title' => 'Prosjekteier', 'page_type' => 'entity'],
        ];

        $payload = $this->capturePayload(linkCatalog: $catalog);
        $userPrompt = $this->userPromptTextFromPayload($payload);

        $this->assertStringContainsString('ALLOWED WIKILINK TARGETS (2 pages):', $userPrompt);
        $this->assertStringContainsString('business-case', $userPrompt);
        $this->assertStringContainsString('prosjekteier', $userPrompt);
    }

    public function test_catalog_shows_server_authoritative_page_id_per_target(): void
    {
        $catalog = [
            ['page_id' => 201, 'slug' => 'advania', 'title' => 'Advania', 'page_type' => 'entity'],
            ['page_id' => 202, 'slug' => 'risikostyring', 'title' => 'Risikostyring', 'page_type' => 'concept'],
        ];

        $payload = $this->capturePayload(linkCatalog: $catalog);
        $userPrompt = $this->userPromptTextFromPayload($payload);

        $this->assertStringContainsString('"page_id": 201', $userPrompt);
        $this->assertStringContainsString('"page_id": 202', $userPrompt);
    }

    public function test_developer_prompt_instructs_selecting_server_authoritative_identity(): void
    {
        $payload = $this->capturePayload();
        $developerPrompt = $this->developerPromptTextFromPayload($payload);

        $this->assertStringContainsString('target_page_id', $developerPrompt);
        // The model names the words to link; it never writes the syntax (run 59).
        $this->assertStringContainsString('anchor_text', $developerPrompt);
        $this->assertStringNotContainsString('{{wiki_link:', $developerPrompt);
        $this->assertStringContainsString('never write a target slug', mb_strtolower($developerPrompt));
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

    public function test_planned_generation_uses_per_section_evidence_instead_of_a_global_source_blob(): void
    {
        $payload = $this->capturePayload(
            sourceText: 'Dette må ikke bli den globale kildeblobben.',
            plannedSections: [[
                'section_index' => 0,
                'planned_topic' => 'Problemterskler',
                'required_heading' => 'Problemterskler',
                'section_purpose' => 'Explain the threshold.',
                'source_element_keys' => ['paragraph-2'],
                'source_evidence' => [[
                    'source_element_key' => 'paragraph-2',
                    'source_element_type' => 'paragraph',
                    'reference_text' => 'Tre P2-hendelser innen 30 dager utløser problemregistrering.',
                ]],
            ]],
        );

        $userPrompt = $this->userPromptTextFromPayload($payload);
        $developerPrompt = $this->developerPromptTextFromPayload($payload);

        $this->assertStringContainsString('AUTHORITATIVE PLANNED SECTION CONTRACT', $userPrompt);
        $this->assertStringContainsString('section_index: 0', $userPrompt);
        $this->assertStringContainsString('source_element_keys: paragraph-2', $userPrompt);
        $this->assertStringContainsString('Tre P2-hendelser', $userPrompt);
        $this->assertStringNotContainsString('Dette må ikke bli den globale kildeblobben.', $userPrompt);
        $this->assertStringContainsString('Generate exactly one substantial ## section', $developerPrompt);
    }

    public function test_adding_a_link_catalog_does_not_change_the_model_token_reasoning_or_store_contract(): void
    {
        $catalog = [['page_id' => 101, 'slug' => 'business-case', 'title' => 'Business Case', 'page_type' => 'concept']];

        $payloadWithoutCatalog = $this->capturePayload();
        $payloadWithCatalog = $this->capturePayload(linkCatalog: $catalog);

        $this->assertSame('gpt-5', $payloadWithCatalog['model']);
        // The link catalog is never part of the capacity estimate (page-type/source-size driven
        // only), so it must not change the chosen output-token budget either.
        $this->assertSame($payloadWithoutCatalog['max_output_tokens'], $payloadWithCatalog['max_output_tokens']);
        $this->assertSame('low', data_get($payloadWithCatalog, 'reasoning.effort'));
        $this->assertFalse($payloadWithCatalog['store']);
    }

    public function test_developer_prompt_documents_structured_link_intents(): void
    {
        foreach (['article', 'summary', 'concept', 'entity'] as $pageType) {
            $payload = $this->capturePayload(pageType: $pageType);
            $developerPrompt = $this->developerPromptTextFromPayload($payload);

            $this->assertStringContainsString('INLINE WIKILINK INTENTS', $developerPrompt, "page type: {$pageType}");
            $this->assertStringContainsString('target_page_id', $developerPrompt, "page type: {$pageType}");
            $this->assertStringContainsString('anchor_text copied verbatim', $developerPrompt, "page type: {$pageType}");
            $this->assertStringNotContainsString('{{wiki_link:', $developerPrompt, "page type: {$pageType}");
        }
    }

    public function test_schema_requires_page_blocks_with_provenance_fields(): void
    {
        $payload = $this->capturePayload();

        $schema = $payload['text']['format']['schema'];

        $this->assertSame(['page', 'best_practice_review'], $schema['required']);
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

    public function test_schema_limits_link_intent_target_page_id_to_the_allowed_catalog(): void
    {
        $payload = $this->capturePayload(linkCatalog: [
            ['page_id' => 201, 'slug' => 'service-improvement-f93a12', 'title' => 'Service Improvement', 'page_type' => 'article'],
            ['page_id' => 202, 'slug' => 'incident-management', 'title' => 'Incident Management', 'page_type' => 'concept'],
        ]);

        $targetPageIdSchema = data_get($payload, 'text.format.schema.properties.page.properties.blocks.items.properties.link_intents.items.properties.target_page_id');

        $this->assertSame('integer', $targetPageIdSchema['type']);
        $this->assertSame([201, 202], $targetPageIdSchema['enum']);
        $intentSchema = data_get($payload, 'text.format.schema.properties.page.properties.blocks.items.properties.link_intents.items');
        $this->assertSame(['intent_id', 'target_page_id', 'anchor_text', 'reason'], $intentSchema['required']);
        $this->assertSame('^[A-Za-z0-9_-]+$', $intentSchema['properties']['intent_id']['pattern']);
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
     * Generated Wiki content must be directly usable as agreement text. A best_practice block
     * therefore states its clause normatively ("skal ..."), never as advice from Procynia — the
     * previous prompt actively instructed the banned form ("Procynia anbefaler at ... fordi ...").
     */
    public function test_developer_prompt_requires_best_practice_written_as_normative_contract_text(): void
    {
        foreach (['article', 'summary', 'concept', 'entity'] as $pageType) {
            $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($this->capturePayload(pageType: $pageType)));

            $this->assertStringContainsString('finished, normative agreement text', $developerPrompt, "page type: {$pageType}");
            $this->assertStringContainsString('"skal", "skal sikre", "skal etablere", "skal dokumentere", "skal følge opp"', $developerPrompt, "page type: {$pageType}");
            $this->assertStringContainsString('keep it normative, never descriptive', $developerPrompt, "page type: {$pageType}");
        }
    }

    public function test_developer_prompt_bans_advisory_framing_in_best_practice_text(): void
    {
        $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($this->capturePayload()));

        $this->assertStringContainsString('never frame it as advice, opinion, or a suggestion from anyone', $developerPrompt);

        foreach (['procynia anbefaler', 'vi anbefaler', 'det anbefales', 'beste praksis tilsier', 'fordi dette vil'] as $bannedPhrase) {
            $this->assertStringContainsString($bannedPhrase, $developerPrompt, "banned phrase must be named: {$bannedPhrase}");
        }
    }

    /**
     * The justification is review metadata, not page content — it must be carried by
     * best_practice_reason (which the existing best_practice -> claim -> review flow already
     * persists and surfaces to the reviewer), never mixed into the visible clause.
     */
    public function test_developer_prompt_keeps_justification_in_best_practice_reason_not_in_the_text(): void
    {
        $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($this->capturePayload()));

        $this->assertStringContainsString('the block\'s markdown carries only the clause', $developerPrompt);
        $this->assertStringContainsString('belongs exclusively in best_practice_reason, never in the visible text', $developerPrompt);
        $this->assertStringContainsString('best_practice_reason is where the justification lives', $developerPrompt);
        $this->assertStringContainsString('never rendered as page text', $developerPrompt);
    }

    /**
     * The house style applies to source_based content too: it is rewritten into finished
     * agreement prose, but its meaning, obligations and parties stay exactly as the source has
     * them, and nothing ever comments on the source or on Procynia.
     */
    public function test_developer_prompt_requires_source_based_written_as_agreement_text_without_meta_commentary(): void
    {
        $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($this->capturePayload()));

        $this->assertStringContainsString('the whole page must read as usable agreement text', $developerPrompt);
        $this->assertStringContainsString('preserve the meaning, the obligations, and the parties/roles exactly as the source states them', $developerPrompt);
        $this->assertStringContainsString('never write about the source, about this system, or about your own work', $developerPrompt);
        $this->assertStringContainsString('kilden beskriver', $developerPrompt);
    }

    public function test_developer_prompt_forbids_inventing_parties_or_defaulting_every_obligation_to_the_customer(): void
    {
        $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($this->capturePayload()));

        $this->assertStringContainsString('use the party and role names that actually follow from the source and its context', $developerPrompt);
        $this->assertStringContainsString('never invent a party the material does not support', $developerPrompt);
        $this->assertStringContainsString('never default every obligation to "kunden skal"', $developerPrompt);
    }

    /**
     * Section repair writes onto the same page as ordinary generation, so it must produce the
     * same finished-agreement-text style — otherwise a repaired section reintroduces exactly the
     * advisory phrasing this change removes.
     */
    public function test_repair_prompt_requires_the_same_contract_text_style(): void
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
            sourceText: 'Kildetekst.',
            languageCode: 'no',
        );

        $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($capturedPayload));

        $this->assertStringContainsString('write every block as finished agreement text', $developerPrompt);
        $this->assertStringContainsString('never as advice', $developerPrompt);
        $this->assertStringContainsString('procynia anbefaler', $developerPrompt);
        $this->assertStringContainsString('the justification belongs in best_practice_reason', $developerPrompt);
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
        $this->assertStringContainsString('keep every recommendation concrete and anchored in what this page actually deals with', $developerPrompt);
    }

    /**
     * Run 12 regression: the same source document that had previously produced real best_practice
     * content generated none once the concrete gap checks were replaced by an abstract
     * subject-comparison. The checks are back as professional triggers — explicitly not a quota —
     * so process material with obvious governance gaps has something concrete to catch on.
     */
    public function test_developer_prompt_lists_the_concrete_gap_triggers(): void
    {
        $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($this->capturePayload()));

        foreach ([
            'ownership and responsibility',
            'control points',
            'targets, kpis and measurement frequency',
            'deviation handling',
            'decision criteria',
            'risk and security',
            'process boundaries',
            'follow-up and improvement',
        ] as $trigger) {
            $this->assertStringContainsString($trigger, $developerPrompt, "missing gap trigger: {$trigger}");
        }

        $this->assertStringContainsString('professional triggers, not a quota', $developerPrompt);
    }

    /**
     * The same run-12 regression from the other side: nothing may push the model into withholding
     * a well-founded recommendation. The old "depth over breadth" framing and its demand that a
     * recommendation never fit any other document are gone.
     */
    public function test_developer_prompt_does_not_discourage_well_founded_recommendations(): void
    {
        $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($this->capturePayload()));

        $this->assertStringNotContainsString('depth over breadth', $developerPrompt);
        $this->assertStringNotContainsString('would read exactly the same for an unrelated document', $developerPrompt);
        $this->assertStringContainsString('never a reason to withhold it', $developerPrompt);

        // A mature source may still legitimately yield nothing.
        $this->assertStringContainsString('zero best_practice blocks is the correct outcome', $developerPrompt);
        $this->assertStringContainsString('no quota, no minimum, no padding', $developerPrompt);
    }

    /**
     * Requiring best_practice to read exactly like the rest of the agreement removed the cue the
     * model used to classify such a block at all, so gap-closing clauses could be filed as
     * source_based or structural instead. Origin is now stated to decide classification, not tone.
     */
    public function test_developer_prompt_states_that_origin_not_tone_decides_classification(): void
    {
        $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($this->capturePayload()));

        $this->assertStringContainsString('classification is decided by origin, not by tone', $developerPrompt);
        $this->assertStringContainsString('that block is best_practice', $developerPrompt);
        $this->assertStringContainsString('it is not source_based', $developerPrompt);
        $this->assertStringContainsString('it is not structural either', $developerPrompt);
        $this->assertStringContainsString('never turns it into source content', $developerPrompt);
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

    public function test_repair_request_serializes_a_long_unicode_issue_body_without_utf8_corruption(): void
    {
        $capturedPayload = null;
        $plannedTopic = 'Årsaksanalyse og oppfølging';
        $currentInvalidBody = str_repeat('Årsak – «åpen» oppfølging. ', 60).'Å';

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedPayload, $plannedTopic): array {
                $capturedPayload = $payload;

                return [
                    'status' => 'completed',
                    'output_text' => json_encode([
                        'sections' => [$this->sourceBasedSection($plannedTopic, 'Årsakanalysen gjennomgås annenhver uke.')],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            });

        app(WikiPageContentAiClient::class)->repairPlannedSections(
            pageTitle: 'Årsaksanalyse',
            pageType: 'concept',
            existingMarkdown: "# Årsaksanalyse\n\n## {$plannedTopic}\n\n{$currentInvalidBody}",
            issues: [[
                'type' => 'planned_section_only_links',
                'planned_topic' => $plannedTopic,
                'heading' => $plannedTopic,
                'current_invalid_body' => $currentInvalidBody,
            ]],
            sourceText: 'Årsaker og tiltak skal gjennomgås annenhver uke.',
            languageCode: 'no',
        );

        $this->assertTrue(mb_check_encoding($currentInvalidBody, 'UTF-8'));
        $this->assertNotFalse(json_encode($capturedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString($currentInvalidBody, $this->userPromptTextFromPayload((array) $capturedPayload));
    }

    public function test_repair_planned_sections_locks_planned_topic_to_the_exact_allowed_set_for_the_page(): void
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
                        'sections' => [
                            $this->sourceBasedSection('Roller i styringsmodellen', 'Ferdig tekst.'),
                            $this->sourceBasedSection('Møtefora og beslutningsflyt', 'Ferdig tekst.'),
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            });

        app(WikiPageContentAiClient::class)->repairPlannedSections(
            pageTitle: 'Test Page',
            pageType: 'concept',
            existingMarkdown: "# Test Page\n\n## Roller i styringsmodellen\n\n## Møtefora og beslutningsflyt",
            issues: [
                ['type' => 'planned_section_missing', 'planned_topic' => 'Roller i styringsmodellen', 'heading' => 'Roller i styringsmodellen'],
                ['type' => 'planned_section_missing', 'planned_topic' => 'Møtefora og beslutningsflyt', 'heading' => 'Møtefora og beslutningsflyt'],
            ],
            sourceText: 'Kildetekst med relevant informasjon.',
            languageCode: 'no',
        );

        $userPrompt = $this->userPromptTextFromPayload($capturedPayload);
        $developerPrompt = $this->developerPromptTextFromPayload($capturedPayload);
        $allowedTopics = data_get($capturedPayload, 'text.format.schema.properties.sections.items.properties.planned_topic.enum', []);
        $minItems = data_get($capturedPayload, 'text.format.schema.properties.sections.minItems');
        $maxItems = data_get($capturedPayload, 'text.format.schema.properties.sections.maxItems');

        $this->assertSame([
            'Roller i styringsmodellen',
            'Møtefora og beslutningsflyt',
        ], $allowedTopics);
        $this->assertSame(2, $minItems);
        $this->assertSame(2, $maxItems);
        $this->assertStringContainsString('ALLOWED PLANNED TOPICS FOR THIS PAGE', $userPrompt);
        $this->assertStringContainsString('- Roller i styringsmodellen', $userPrompt);
        $this->assertStringContainsString('- Møtefora og beslutningsflyt', $userPrompt);
        $this->assertStringContainsString('allowed planned topics (exact copy only):', mb_strtolower($developerPrompt));
        $this->assertStringContainsString('the only valid planned_topic values for this response are the exact allowed topics listed below', mb_strtolower($developerPrompt));
    }

    public function test_repair_planned_sections_includes_per_section_source_evidence_and_non_empty_contract(): void
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
                        'sections' => [
                            $this->sourceBasedSection('Innhold i problemregistrering og krav til gjennomgangskadens', 'Ferdig tekst.'),
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            });

        app(WikiPageContentAiClient::class)->repairPlannedSections(
            pageTitle: 'Test Page',
            pageType: 'concept',
            existingMarkdown: "# Test Page\n\n## Innhold i problemregistrering og krav til gjennomgangskadens",
            issues: [
                ['type' => 'planned_section_only_links', 'planned_topic' => 'Innhold i problemregistrering og krav til gjennomgangskadens', 'heading' => 'Innhold i problemregistrering og krav til gjennomgangskadens'],
            ],
            sourceText: 'Kildetekst med relevant informasjon.',
            languageCode: 'no',
            sourceElements: [
                [
                    'source_element_key' => 'paragraph-3',
                    'source_element_type' => 'paragraph',
                    'section_title' => '2. Problemhåndtering',
                    'page_reference' => '2. Problemhåndtering',
                    'reference_text' => 'Gjentakende hendelser og hendelser uten kjent varig løsning skal vurderes som problem.',
                    'display_text' => 'Gjentakende hendelser og hendelser uten kjent varig løsning skal vurderes som problem.',
                ],
                [
                    'source_element_key' => 'paragraph-5',
                    'source_element_type' => 'paragraph',
                    'section_title' => '4. Måling og rapportering',
                    'page_reference' => '4. Måling og rapportering',
                    'reference_text' => 'Månedsrapporten skal inneholde tilgjengelighet, antall hendelser per prioritet.',
                    'display_text' => 'Månedsrapporten skal inneholde tilgjengelighet, antall hendelser per prioritet.',
                ],
            ],
        );

        $userPrompt = $this->userPromptTextFromPayload($capturedPayload);

        $this->assertStringContainsString('PLANNED SECTION EVIDENCE', $userPrompt);
        $this->assertStringContainsString('required_heading: Innhold i problemregistrering og krav til gjennomgangskadens', $userPrompt);
        $this->assertStringContainsString('must_be_non_empty: true', $userPrompt);
        $this->assertStringContainsString('[paragraph-3] (paragraph)', $userPrompt);
        $this->assertStringContainsString('2. Problemhåndtering', $userPrompt);
        $this->assertStringContainsString('[paragraph-5] (paragraph)', $userPrompt);
        $this->assertStringContainsString('4. Måling og rapportering', $userPrompt);
        $this->assertStringContainsString('SOURCE ELEMENTS (2 elements):', $userPrompt);
        $this->assertStringContainsString('The source document, split into its addressable elements', $userPrompt);
    }

    public function test_only_links_repair_prompt_includes_the_exact_invalid_body_and_assigned_evidence(): void
    {
        $capturedPayload = null;

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedPayload): array {
                $capturedPayload = $payload;
                $section = $this->sourceBasedSection('Review cadence for open problems', 'Open problems are reviewed every second week.');
                $section['blocks'][0]['source_element_keys'] = ['paragraph-4'];

                return [
                    'status' => 'completed',
                    'output_text' => json_encode([
                        'sections' => [$section],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            });

        app(WikiPageContentAiClient::class)->repairPlannedSections(
            pageTitle: 'Problem management',
            pageType: 'concept',
            existingMarkdown: "# Problem management\n\n## Review cadence for open problems\n\n[[problem-management|Problem management]]",
            issues: [[
                'type' => 'planned_section_only_links',
                'issue_code' => 'planned_section_only_links',
                'section_index' => 0,
                'planned_topic' => 'Review cadence for open problems',
                'heading' => 'Review cadence for open problems',
                'current_invalid_body' => '[[problem-management|Problem management]]',
                'assigned_source_element_keys' => ['paragraph-4'],
                'assigned_source_evidence' => [[
                    'source_element_key' => 'paragraph-4',
                    'source_element_type' => 'paragraph',
                    'reference_text' => 'Open problems are reviewed every second week.',
                ]],
            ]],
            sourceText: 'Open problems are reviewed every second week.',
            languageCode: 'en',
            plannedSections: [[
                'section_index' => 0,
                'planned_topic' => 'Review cadence for open problems',
                'required_heading' => 'Review cadence for open problems',
                'source_element_keys' => ['paragraph-4'],
                'source_evidence' => [[
                    'source_element_key' => 'paragraph-4',
                    'source_element_type' => 'paragraph',
                    'reference_text' => 'Open problems are reviewed every second week.',
                ]],
            ]],
            sectionStatuses: [[
                'section_index' => 0,
                'planned_topic' => 'Review cadence for open problems',
                'status' => 'planned_section_only_links',
            ]],
        );

        $userPrompt = $this->userPromptTextFromPayload($capturedPayload);
        $developerPrompt = $this->developerPromptTextFromPayload($capturedPayload);

        $this->assertStringContainsString('issue_code: planned_section_only_links', $userPrompt);
        $this->assertStringContainsString('section_index: 0', $userPrompt);
        $this->assertStringContainsString('current_invalid_body: [[problem-management|Problem management]]', $userPrompt);
        $this->assertStringContainsString('assigned_source_element_keys: paragraph-4', $userPrompt);
        $this->assertStringContainsString('Open problems are reviewed every second week.', $userPrompt);
        $this->assertStringContainsString('must_contain_grounded_prose: true', $userPrompt);
        $this->assertStringContainsString('links_must_not_be_the_only_content: true', $userPrompt);
        $this->assertStringContainsString('replace the link-only body with concise grounded prose', mb_strtolower($developerPrompt));
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

        $this->expectException(EnterpriseWikiPlannedSectionRepairShapeException::class);
        $this->expectExceptionMessageMatches('/repair_section_count_mismatch/');

        app(WikiPageContentAiClient::class)->repairPlannedSections(
            pageTitle: 'Test Page',
            pageType: 'concept',
            existingMarkdown: '# Test Page',
            issues: [['type' => 'planned_section_empty', 'planned_topic' => 'X', 'heading' => 'X']],
            sourceText: 'Kildetekst.',
            languageCode: 'no',
        );
    }

    public function test_repair_planned_sections_rejects_one_of_four_sections_with_an_explicit_shape_code(): void
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturn([
                'status' => 'completed',
                'output_text' => json_encode([
                    'sections' => [$this->sourceBasedSection('A', 'Only one section returned.')],
                ]),
            ]);

        try {
            app(WikiPageContentAiClient::class)->repairPlannedSections(
                pageTitle: 'Test Page',
                pageType: 'concept',
                existingMarkdown: '# Test Page',
                issues: [['type' => 'planned_section_empty', 'planned_topic' => 'A', 'heading' => 'A']],
                sourceText: 'Kildetekst.',
                languageCode: 'no',
                plannedSections: [
                    ['planned_topic' => 'A', 'source_element_keys' => ['source-a']],
                    ['planned_topic' => 'B', 'source_element_keys' => ['source-b']],
                    ['planned_topic' => 'C', 'source_element_keys' => ['source-c']],
                    ['planned_topic' => 'D', 'source_element_keys' => ['source-d']],
                ],
            );
            $this->fail('Expected the repair shape contract to reject one of four sections.');
        } catch (EnterpriseWikiPlannedSectionRepairShapeException $e) {
            $this->assertSame(4, $e->expectedSectionCount);
            $this->assertSame(1, $e->returnedSectionCount);
        }
    }

    public function test_repair_planned_sections_rejects_evidence_assigned_to_another_section(): void
    {
        $foreignEvidenceSection = $this->sourceBasedSection('A', 'Body supported by the wrong element.');
        $foreignEvidenceSection['blocks'][0]['source_element_keys'] = ['source-b'];

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturn([
                'status' => 'completed',
                'output_text' => json_encode(['sections' => [$foreignEvidenceSection]]),
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/repair_section_unassigned_evidence/');

        app(WikiPageContentAiClient::class)->repairPlannedSections(
            pageTitle: 'Test Page',
            pageType: 'concept',
            existingMarkdown: '# Test Page',
            issues: [['type' => 'planned_section_empty', 'planned_topic' => 'A', 'heading' => 'A']],
            sourceText: 'Kildetekst.',
            languageCode: 'no',
            plannedSections: [
                ['planned_topic' => 'A', 'source_element_keys' => ['source-a']],
            ],
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
        $this->expectExceptionMessageMatches('/did not match requested planned_topic/');

        app(WikiPageContentAiClient::class)->repairPlannedSections(
            pageTitle: 'Test Page',
            pageType: 'concept',
            existingMarkdown: '# Test Page',
            issues: [['type' => 'planned_section_empty', 'planned_topic' => 'X', 'heading' => 'X']],
            sourceText: 'Kildetekst.',
            languageCode: 'no',
        );
    }

    public function test_repair_planned_sections_throws_when_topics_are_returned_in_the_wrong_order(): void
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturn([
                'status' => 'completed',
                'output_text' => json_encode([
                    'sections' => [
                        $this->sourceBasedSection('Y', 'Some content.'),
                        $this->sourceBasedSection('X', 'Some content.'),
                    ],
                ]),
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/did not match requested planned_topic/');

        app(WikiPageContentAiClient::class)->repairPlannedSections(
            pageTitle: 'Test Page',
            pageType: 'concept',
            existingMarkdown: '# Test Page',
            issues: [
                ['type' => 'planned_section_empty', 'planned_topic' => 'X', 'heading' => 'X'],
                ['type' => 'planned_section_empty', 'planned_topic' => 'Y', 'heading' => 'Y'],
            ],
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

            $sections = [$this->sourceBasedSection('X', 'Repaired body text.')];

            if (count($capturedPayloads) === 2) {
                $sections[] = $this->sourceBasedSection('Y', 'Repaired body text.');
            }

            return [
                'status' => 'completed',
                'output_text' => json_encode([
                    'sections' => $sections,
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
        array $plannedSections = [],
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
            plannedSections: $plannedSections,
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
