<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\WikiPageContentAiClient;
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
        $this->assertSame(6000, $payload['max_output_tokens']);
        $this->assertSame('low', data_get($payload, 'reasoning.effort'));
        $this->assertFalse($payload['store']);
        $this->assertArrayNotHasKey('temperature', $payload);
    }

    public function test_generate_from_source_uses_a_300_second_http_timeout(): void
    {
        $capturedTimeout = null;

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

        app(WikiPageContentAiClient::class)->generateFromSource('Test Page', 'article', 'Noe kildetekst.', 'no');

        $this->assertSame(300, $capturedTimeout);
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

    public function test_best_practice_rule_requires_necessity_not_just_accuracy(): void
    {
        $payload = $this->capturePayload(pageType: 'concept');
        $developerPrompt = mb_strtolower($this->developerPromptTextFromPayload($payload));

        $this->assertStringContainsString('a thin source document justifies a thin page', $developerPrompt);
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

        $payload = $this->capturePayload(linkCatalog: $catalog);

        $this->assertSame('gpt-5', $payload['model']);
        $this->assertSame(6000, $payload['max_output_tokens']);
        $this->assertSame('low', data_get($payload, 'reasoning.effort'));
        $this->assertFalse($payload['store']);
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

    public function test_generate_from_source_throws_on_incomplete_response_and_logs_safe_diagnostics(): void
    {
        Log::spy();

        $client = $this->clientWithRawResponse([
            'id' => 'resp_incomplete',
            'status' => 'incomplete',
            'incomplete_details' => [
                'reason' => 'max_output_tokens',
            ],
            'output_text' => '{"page":{"markdown":"# Test Page',
            'output' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/OpenAI response was incomplete\..*max_output_tokens/s');

        $client->generateFromSource('Test Page', 'article', 'Noe kildetekst.', 'no');

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === '[PROCYNIA][WIKI_PAGE_CONTENT] OpenAI response diagnostics.'
                && ($context['response_id'] ?? null) === 'resp_incomplete'
                && ($context['http_status'] ?? null) === 200
                && ($context['response_status'] ?? null) === 'incomplete'
                && ($context['incomplete_details']['reason'] ?? null) === 'max_output_tokens'
                && ($context['output_text_length'] ?? null) > 0
                && ($context['output_item_types'] ?? null) === []
                && ($context['content_item_types'] ?? null) === []
                && ($context['has_refusal'] ?? null) === false;
        });
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
}
