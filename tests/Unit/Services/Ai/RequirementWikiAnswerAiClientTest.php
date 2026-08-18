<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\RequirementWikiAnswerAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Purpose: Verify the final-answer AI client receives Wiki PAGES as its first-priority context
 * (not a flat claim list), is explicitly allowed to supplement with best practice, protects against
 * invented company-specific facts, and no longer self-reports coverage_status/missing_summary or
 * forces a section to cite a page — those judgments now belong to RequirementWikiAlignmentAiClient
 * and RequirementWikiAnswerService, run after this client.
 * Inputs: None.
 * Returns: None.
 * Side effects: None.
 */
class RequirementWikiAnswerAiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    private function onePage(): array
    {
        return [[
            'page_id' => 101,
            'title' => 'Problem Management',
            'page_type' => 'concept',
            'content_mode' => 'full',
            'content_markdown' => "# Problem Management\n\nProsessen identifiserer rotårsaker til hendelser.",
            'selected_headings' => [],
            'source_based_claim_texts' => ['Problem Management gjennomfører rotårsaksanalyse.'],
            'best_practice_claim_texts' => [],
        ]];
    }

    private function twoPages(): array
    {
        return [
            [
                'page_id' => 101,
                'title' => 'Problem Management',
                'page_type' => 'concept',
                'content_mode' => 'full',
                'content_markdown' => 'Innhold om Problem Management.',
                'selected_headings' => [],
                'source_based_claim_texts' => [],
                'best_practice_claim_texts' => [],
            ],
            [
                'page_id' => 102,
                'title' => 'Incident Management',
                'page_type' => 'concept',
                'content_mode' => 'full',
                'content_markdown' => 'Innhold om Incident Management.',
                'selected_headings' => [],
                'source_based_claim_texts' => [],
                'best_practice_claim_texts' => [],
            ],
        ];
    }

    public function test_payload_contract_sends_pages_as_primary_context(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['answer_sections' => [['key' => 'S1', 'heading' => 'Problem Management', 'text' => 'Svar.', 'used_page_ids' => [101]]]]);
            });
        });

        app(RequirementWikiAnswerAiClient::class)->generateAnswer('1.1', 'Beskriv Problem Management.', $this->onePage(), 'no');

        $this->assertSame('gpt-4.1-mini', $captured['model']);
        $this->assertFalse($captured['store']);
        $this->assertSame('json_schema', data_get($captured, 'text.format.type'));
        $userText = data_get($captured, 'input.1.content.0.text');
        $this->assertStringContainsString('PAGE_ID: 101', $userText);
        $this->assertStringContainsString('rotårsaker til hendelser', $userText);
        $this->assertStringContainsString('SOURCE-DOCUMENTED FACTS', $userText);
    }

    public function test_prompt_instructs_the_model_to_read_the_pages(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => 'Svar.', 'used_page_ids' => []]]]);
            });
        });

        app(RequirementWikiAnswerAiClient::class)->generateAnswer('1.1', 'text', $this->onePage(), 'no');

        $developerPrompt = data_get($captured, 'input.0.content.0.text');
        $this->assertStringContainsString('Read every Wiki page provided in full', $developerPrompt);
    }

    public function test_prompt_permits_best_practice_but_forbids_invented_company_facts(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => 'Svar.', 'used_page_ids' => []]]]);
            });
        });

        app(RequirementWikiAnswerAiClient::class)->generateAnswer('1.1', 'text', $this->onePage(), 'no');

        $developerPrompt = data_get($captured, 'input.0.content.0.text');
        $this->assertStringContainsString('you MAY supplement with established, professionally recognized best practice', $developerPrompt);
        $this->assertStringContainsString('a specific certification, a specific tool, an SLA or response time, an existing role or organizational structure, a named internal process, or a guaranteed outcome', $developerPrompt);
        $this->assertStringContainsString('recommended method, a suggested approach, relevant professional practice, or a solution to be clarified/adapted', $developerPrompt);
        $this->assertStringContainsString('do not make the rest of the answer generic or overly cautious because of it', $developerPrompt);
        $this->assertStringContainsString('Use as many answer_sections as the requirement and the Wiki material require', $developerPrompt);
        $this->assertStringContainsString('Do not create a separate summary, conclusion, or interplay section', $developerPrompt);
        $this->assertStringContainsString('The answer should end after the last necessary substantive section', $developerPrompt);
        $this->assertStringContainsString('Do not append a general summary of stability, availability, traceability, or continuous improvement after the main sections', $developerPrompt);
        $this->assertStringContainsString('Every section must contribute new information', $developerPrompt);
        $this->assertStringContainsString('each section should focus on the specific subject matter it needs to cover', $developerPrompt);
        $this->assertStringContainsString('Avoid repeating CMDB, monitoring, traceability, Change Enablement, and continuous improvement', $developerPrompt);
        $this->assertStringNotContainsString('Incident Management section', $developerPrompt);
        $this->assertStringNotContainsString('Problem Management section', $developerPrompt);
    }

    /**
     * v0.9 provenance-gap closure: a page's source_based and best_practice claims must appear in
     * two separately labeled blocks, and the prompt must explicitly forbid presenting a
     * BEST-PRACTICE SUGGESTIONS claim as an existing customer fact.
     */
    public function test_source_based_and_best_practice_claims_appear_in_two_distinct_labeled_blocks(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => 'Svar.', 'used_page_ids' => [101]]]]);
            });
        });

        $pages = [[
            'page_id' => 101,
            'title' => 'Problem Management',
            'page_type' => 'concept',
            'content_mode' => 'full',
            'content_markdown' => 'Innhold.',
            'selected_headings' => [],
            'source_based_claim_texts' => ['Problem Management rapporteres månedlig.'],
            'best_practice_claim_texts' => ['Det anbefales å automatisere rotårsaksanalyse.'],
        ]];

        app(RequirementWikiAnswerAiClient::class)->generateAnswer('1.1', 'text', $pages, 'no');

        $userText = data_get($captured, 'input.1.content.0.text');
        $this->assertStringContainsString('SOURCE-DOCUMENTED FACTS', $userText);
        $this->assertStringContainsString('Problem Management rapporteres månedlig.', $userText);
        $this->assertStringContainsString('BEST-PRACTICE SUGGESTIONS', $userText);
        $this->assertStringContainsString('Det anbefales å automatisere rotårsaksanalyse.', $userText);

        $developerPrompt = data_get($captured, 'input.0.content.0.text');
        $this->assertStringContainsString('BEST-PRACTICE SUGGESTIONS', $developerPrompt);
        $this->assertStringContainsString('never as something the vendor already has, does, or guarantees', $developerPrompt);
    }

    public function test_prompt_requires_formal_contract_language_and_explicit_parties(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => 'Svar.', 'used_page_ids' => []]]]);
            });
        });

        app(RequirementWikiAnswerAiClient::class)->generateAnswer('1.1', 'text', $this->onePage(), 'no');

        $developerPrompt = data_get($captured, 'input.0.content.0.text');
        $this->assertStringContainsString('formal contractual text', $developerPrompt);
        $this->assertStringContainsString('Leverandøren and Kunden', $developerPrompt);
        $this->assertStringContainsString('Do not use first-person or second-person language', $developerPrompt);
        $this->assertStringContainsString('skal for binding commitments, kan for possibilities or rights, bør for recommendations or best practice', $developerPrompt);
        $this->assertStringContainsString('Describe responsibilities, activities, governance, control, documentation, reporting, follow-up, dependencies, and interfaces with clear attribution', $developerPrompt);
        $this->assertStringContainsString('Formulate factual claims about certifications, tools, service levels, roles, organization, internal processes, guarantees, or specific results only when the Wiki supports them', $developerPrompt);
    }

    public function test_it_can_generate_an_answer_with_zero_pages_using_best_practice_only(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['answer_sections' => [['key' => 'S1', 'heading' => 'Anbefalt tilnærming', 'text' => 'Beste praksis-svar uten Wiki-støtte.', 'used_page_ids' => []]]]);
            });
        });

        $result = app(RequirementWikiAnswerAiClient::class)->generateAnswer('1.1', 'text', [], 'no');

        $userText = data_get($captured, 'input.1.content.0.text');
        $this->assertStringContainsString('write the answer using recognized professional best practice only', $userText);
        $this->assertSame('Beste praksis-svar uten Wiki-støtte.', $result['answer_sections'][0]['text']);
        $this->assertSame([], $result['answer_sections'][0]['used_page_ids']);
    }

    public function test_a_section_can_have_an_empty_used_page_ids_and_is_not_dropped(): void
    {
        $client = $this->clientReturning([
            'answer_sections' => [
                ['key' => 'S1', 'heading' => 'Wiki-forankret', 'text' => 'Wiki-basert avsnitt.', 'used_page_ids' => [101]],
                ['key' => 'S2', 'heading' => 'Beste praksis', 'text' => 'Beste praksis-avsnitt uten sidehenvisning.', 'used_page_ids' => []],
            ],
        ]);

        $result = $client->generateAnswer('1.1', 'text', $this->onePage(), 'no');

        $this->assertCount(2, $result['answer_sections']);
        $this->assertSame([], $result['answer_sections'][1]['used_page_ids']);
        $this->assertSame('Beste praksis-avsnitt uten sidehenvisning.', $result['answer_sections'][1]['text']);
    }

    public function test_an_answer_can_cite_more_than_one_page_in_one_section(): void
    {
        $client = $this->clientReturning([
            'answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => 'Svar basert på begge sider.', 'used_page_ids' => [101, 102]]],
        ]);

        $result = $client->generateAnswer('1.1', 'text', $this->twoPages(), 'no');

        $this->assertEqualsCanonicalizing([101, 102], $result['answer_sections'][0]['used_page_ids']);
    }

    public function test_an_unknown_page_id_is_filtered_out_of_a_section_without_dropping_it(): void
    {
        $client = $this->clientReturning([
            'answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => 'Svar.', 'used_page_ids' => [101, 999]]],
        ]);

        $result = $client->generateAnswer('1.1', 'text', $this->onePage(), 'no');

        $this->assertSame([101], $result['answer_sections'][0]['used_page_ids']);
    }

    public function test_a_page_that_was_never_provided_cannot_be_cited_but_the_section_is_kept(): void
    {
        $client = $this->clientReturning([
            'answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => 'Svar uten gyldig sidehenvisning.', 'used_page_ids' => [999]]],
        ]);

        $result = $client->generateAnswer('1.1', 'text', $this->onePage(), 'no');

        $this->assertSame([], $result['answer_sections'][0]['used_page_ids']);
        $this->assertSame('Svar uten gyldig sidehenvisning.', $result['answer_sections'][0]['text']);
    }

    public function test_a_blank_or_duplicate_key_is_replaced_with_a_synthetic_one(): void
    {
        $client = $this->clientReturning([
            'answer_sections' => [
                ['key' => '', 'heading' => '', 'text' => 'Første.', 'used_page_ids' => []],
                ['key' => 'S1', 'heading' => '', 'text' => 'Andre.', 'used_page_ids' => []],
            ],
        ]);

        $result = $client->generateAnswer('1.1', 'text', $this->onePage(), 'no');

        $keys = array_column($result['answer_sections'], 'key');
        $this->assertCount(2, array_unique($keys));
        foreach ($keys as $key) {
            $this->assertNotSame('', $key);
        }
    }

    public function test_response_no_longer_carries_a_coverage_status_field(): void
    {
        $client = $this->clientReturning([
            'answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => 'Svar.', 'used_page_ids' => [101]]],
        ]);

        $result = $client->generateAnswer('1.1', 'text', $this->onePage(), 'no');

        $this->assertArrayNotHasKey('coverage_status', $result);
        $this->assertArrayNotHasKey('missing_summary', $result);
    }

    public function test_throws_when_no_usable_answer_sections_are_produced(): void
    {
        $client = $this->clientReturning(['answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => '', 'used_page_ids' => []]]]);

        $this->expectException(RuntimeException::class);

        $client->generateAnswer('1.1', 'text', $this->onePage(), 'no');
    }

    /**
     * AI-to-Wiki consolidation: the owning customer's shared ai_instructions (case_instructions) must
     * reach the prompt, framed as a style-only directive that can never be mistaken for a knowledge
     * source or an override of the anti-fabrication/citation rules.
     */
    public function test_case_instructions_are_included_as_a_subordinate_style_directive(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => 'Svar.', 'used_page_ids' => [101]]]]);
            });
        });

        app(RequirementWikiAnswerAiClient::class)->generateAnswer(
            '1.1',
            'text',
            $this->onePage(),
            'no',
            'Skriv i en uformell og vennlig tone.',
        );

        $userText = data_get($captured, 'input.1.content.0.text');
        $this->assertStringContainsString('CASE INSTRUCTIONS', $userText);
        $this->assertStringContainsString('Skriv i en uformell og vennlig tone.', $userText);
        $this->assertStringContainsString('never a source of facts', $userText);

        $developerPrompt = data_get($captured, 'input.0.content.0.text');
        $this->assertStringContainsString('Case instructions', $developerPrompt);
        $this->assertStringContainsString('only for tone, terminology, style, and capitalization', $developerPrompt);
        $this->assertStringContainsString('never override a Wiki page\'s SOURCE-DOCUMENTED FACTS', $developerPrompt);
    }

    public function test_no_case_instructions_block_is_added_when_none_are_provided(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => 'Svar.', 'used_page_ids' => [101]]]]);
            });
        });

        app(RequirementWikiAnswerAiClient::class)->generateAnswer('1.1', 'text', $this->onePage(), 'no');

        $userText = data_get($captured, 'input.1.content.0.text');
        $this->assertStringNotContainsString('CASE INSTRUCTIONS', $userText);
    }

    public function test_blank_case_instructions_are_treated_the_same_as_none(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => 'Svar.', 'used_page_ids' => [101]]]]);
            });
        });

        app(RequirementWikiAnswerAiClient::class)->generateAnswer('1.1', 'text', $this->onePage(), 'no', "   \n  ");

        $userText = data_get($captured, 'input.1.content.0.text');
        $this->assertStringNotContainsString('CASE INSTRUCTIONS', $userText);
    }

    /**
     * AI-to-Wiki consolidation (Wiki-answer-as-sole-engine): a one-off, per-generation
     * requirementUserPrompt (feature parity with the legacy answer-draft flow's user_answer_prompt)
     * must reach the prompt, framed with the same subordinate, style-only status as case
     * instructions, applied after it.
     */
    public function test_requirement_user_prompt_is_included_as_a_subordinate_style_directive(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => 'Svar.', 'used_page_ids' => [101]]]]);
            });
        });

        app(RequirementWikiAnswerAiClient::class)->generateAnswer(
            '1.1',
            'text',
            $this->onePage(),
            'no',
            'Skriv i en uformell og vennlig tone.',
            'Fokuser spesielt på ansvarsfordeling.',
        );

        $userText = data_get($captured, 'input.1.content.0.text');
        $this->assertStringContainsString('REQUIREMENT-SPECIFIC INSTRUCTIONS', $userText);
        $this->assertStringContainsString('Fokuser spesielt på ansvarsfordeling.', $userText);
        $this->assertStringContainsString('never a source of facts', $userText);

        $developerPrompt = data_get($captured, 'input.0.content.0.text');
        $this->assertStringContainsString('requirement-specific instructions', $developerPrompt);
    }

    public function test_no_requirement_user_prompt_block_is_added_when_none_is_provided(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => 'Svar.', 'used_page_ids' => [101]]]]);
            });
        });

        app(RequirementWikiAnswerAiClient::class)->generateAnswer('1.1', 'text', $this->onePage(), 'no');

        $userText = data_get($captured, 'input.1.content.0.text');
        $this->assertStringNotContainsString('REQUIREMENT-SPECIFIC INSTRUCTIONS', $userText);
    }

    public function test_throws_when_ai_generation_is_disabled(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);

        $this->expectException(RuntimeException::class);

        app(RequirementWikiAnswerAiClient::class)->generateAnswer('1.1', 'text', $this->onePage(), 'no');
    }

    private function clientReturning(array $body): RequirementWikiAnswerAiClient
    {
        $this->mock(OpenAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('createResponse')->once()->andReturn($this->response($body)));

        return app(RequirementWikiAnswerAiClient::class);
    }

    private function response(array $body): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'output' => [], 'output_text' => json_encode($body)];
    }
}
