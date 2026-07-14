<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\RequirementWikiAnswerAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Purpose: Verify the final-answer AI client receives Wiki PAGES as its primary context (not a
 * flat claim list), instructs the model to actually read them, forbids general model knowledge and
 * undocumented delivery commitments, and enforces the page-citation/anti-fabrication contract in
 * PHP rather than trusting the model's own compliance.
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
            'claim_texts' => ['Problem Management gjennomfører rotårsaksanalyse.'],
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
                'claim_texts' => [],
            ],
            [
                'page_id' => 102,
                'title' => 'Incident Management',
                'page_type' => 'concept',
                'content_mode' => 'full',
                'content_markdown' => 'Innhold om Incident Management.',
                'selected_headings' => [],
                'claim_texts' => [],
            ],
        ];
    }

    public function test_payload_contract_sends_pages_as_primary_context(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['coverage_status' => 'none', 'answer_sections' => [], 'missing_summary' => null, 'used_page_ids' => []]);
            });
        });

        app(RequirementWikiAnswerAiClient::class)->generateAnswer('1.1', 'Beskriv Problem Management.', $this->onePage(), 'no');

        $this->assertSame('gpt-4.1-mini', $captured['model']);
        $this->assertFalse($captured['store']);
        $this->assertSame('json_schema', data_get($captured, 'text.format.type'));
        $userText = data_get($captured, 'input.1.content.0.text');
        $this->assertStringContainsString('PAGE_ID: 101', $userText);
        $this->assertStringContainsString('rotårsaker til hendelser', $userText);
        $this->assertStringContainsString('VERIFIED FACTS', $userText);
    }

    public function test_prompt_instructs_the_model_to_read_the_pages(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['coverage_status' => 'none', 'answer_sections' => [], 'missing_summary' => null, 'used_page_ids' => []]);
            });
        });

        app(RequirementWikiAnswerAiClient::class)->generateAnswer('1.1', 'text', $this->onePage(), 'no');

        $developerPrompt = data_get($captured, 'input.0.content.0.text');
        $this->assertStringContainsString('Read every page provided in full', $developerPrompt);
    }

    public function test_prompt_forbids_general_model_knowledge_and_undocumented_commitments(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['coverage_status' => 'none', 'answer_sections' => [], 'missing_summary' => null, 'used_page_ids' => []]);
            });
        });

        app(RequirementWikiAnswerAiClient::class)->generateAnswer('1.1', 'text', $this->onePage(), 'no');

        $developerPrompt = data_get($captured, 'input.0.content.0.text');
        $this->assertStringContainsString('Never use general/external ITIL or industry knowledge', $developerPrompt);
        $this->assertStringContainsString('Never turn a general process description into a specific delivery commitment', $developerPrompt);
        $this->assertStringContainsString('Never add advisory services, ownership, reporting duties', $developerPrompt);
        $this->assertStringContainsString('must be grounded in the VERIFIED FACTS', $developerPrompt);
    }

    public function test_an_answer_can_cite_more_than_one_page(): void
    {
        $client = $this->clientReturning([
            'coverage_status' => 'full',
            'answer_sections' => [['text' => 'Svar basert på begge sider.', 'page_ids' => [101, 102]]],
            'missing_summary' => null,
            'used_page_ids' => [101, 102],
        ]);

        $result = $client->generateAnswer('1.1', 'text', $this->twoPages(), 'no');

        $this->assertSame(['101', '102'], array_map('strval', $result['answer_sections'][0]['page_ids']));
        $this->assertEqualsCanonicalizing([101, 102], $result['used_page_ids']);
    }

    public function test_an_unknown_page_id_is_filtered_out_of_a_section(): void
    {
        $client = $this->clientReturning([
            'coverage_status' => 'full',
            'answer_sections' => [['text' => 'Svar.', 'page_ids' => [101, 999]]],
            'missing_summary' => null,
            'used_page_ids' => [101, 999],
        ]);

        $result = $client->generateAnswer('1.1', 'text', $this->onePage(), 'no');

        $this->assertSame([101], $result['answer_sections'][0]['page_ids']);
        $this->assertSame([101], $result['used_page_ids']);
    }

    public function test_a_page_that_was_never_provided_cannot_be_cited_at_all(): void
    {
        $client = $this->clientReturning([
            'coverage_status' => 'full',
            'answer_sections' => [['text' => 'Svar.', 'page_ids' => [999]]],
            'missing_summary' => null,
            'used_page_ids' => [999],
        ]);

        $this->expectException(RuntimeException::class);

        $client->generateAnswer('1.1', 'text', $this->onePage(), 'no');
    }

    public function test_used_page_ids_are_derived_from_validated_sections_not_trusted_verbatim(): void
    {
        $client = $this->clientReturning([
            'coverage_status' => 'full',
            'answer_sections' => [['text' => 'Svar.', 'page_ids' => [101]]],
            'missing_summary' => null,
            // The model claims 102 was used, but no section actually cites it.
            'used_page_ids' => [101, 102],
        ]);

        $result = $client->generateAnswer('1.1', 'text', $this->twoPages(), 'no');

        $this->assertSame([101], $result['used_page_ids']);
    }

    public function test_used_page_ids_are_deduplicated(): void
    {
        $client = $this->clientReturning([
            'coverage_status' => 'full',
            'answer_sections' => [
                ['text' => 'Del en.', 'page_ids' => [101]],
                ['text' => 'Del to.', 'page_ids' => [101, 102]],
            ],
            'missing_summary' => null,
            'used_page_ids' => [],
        ]);

        $result = $client->generateAnswer('1.1', 'text', $this->twoPages(), 'no');

        $this->assertEqualsCanonicalizing([101, 102], $result['used_page_ids']);
    }

    public function test_none_coverage_always_forces_empty_sections_and_used_page_ids(): void
    {
        $client = $this->clientReturning([
            'coverage_status' => 'none',
            'answer_sections' => [['text' => 'Jeg dikter opp et svar likevel.', 'page_ids' => [101]]],
            'missing_summary' => null,
            'used_page_ids' => [101],
        ]);

        $result = $client->generateAnswer('1.1', 'text', $this->onePage(), 'no');

        $this->assertSame('none', $result['coverage_status']);
        $this->assertSame([], $result['answer_sections']);
        $this->assertSame([], $result['used_page_ids']);
    }

    public function test_partial_coverage_keeps_sections_and_missing_summary(): void
    {
        $client = $this->clientReturning([
            'coverage_status' => 'partial',
            'answer_sections' => [['text' => 'Delvis svar.', 'page_ids' => [101]]],
            'missing_summary' => 'Wiki-en dokumenterer ikke responstider.',
            'used_page_ids' => [101],
        ]);

        $result = $client->generateAnswer('1.1', 'text', $this->onePage(), 'no');

        $this->assertSame('partial', $result['coverage_status']);
        $this->assertSame('Delvis svar.', $result['answer_sections'][0]['text']);
        $this->assertSame('Wiki-en dokumenterer ikke responstider.', $result['missing_summary']);
    }

    public function test_full_coverage_with_no_valid_cited_page_is_rejected(): void
    {
        $client = $this->clientReturning([
            'coverage_status' => 'full',
            'answer_sections' => [['text' => 'Svar uten gyldig sidehenvisning.', 'page_ids' => [999]]],
            'missing_summary' => null,
            'used_page_ids' => [],
        ]);

        $this->expectException(RuntimeException::class);

        $client->generateAnswer('1.1', 'text', $this->onePage(), 'no');
    }

    public function test_throws_when_no_pages_are_provided(): void
    {
        $this->expectException(RuntimeException::class);

        app(RequirementWikiAnswerAiClient::class)->generateAnswer('1.1', 'text', [], 'no');
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
