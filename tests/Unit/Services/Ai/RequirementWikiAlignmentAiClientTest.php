<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\RequirementWikiAlignmentAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Purpose: Verify the alignment client classifies already-written answer sections against the
 * Wiki pages actually read — never rewrites the answer, always returns exactly one assessment per
 * given section (in order), validates supporting_page_ids against the pages actually given, and
 * enforces that a conflict_summary only ever accompanies the possible_conflict status. The critical
 * distinction (missing Wiki detail vs. real conflict) is a model-judgment concern covered here only
 * at the prompt-content level — the deterministic PHP contract is what these tests assert.
 * Inputs: None.
 * Returns: None.
 * Side effects: None.
 */
class RequirementWikiAlignmentAiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    private function oneSection(): array
    {
        return [['key' => 'S1', 'heading' => 'Problem Management', 'text' => 'Svar om Problem Management.', 'used_page_ids' => [101]]];
    }

    private function twoSections(): array
    {
        return [
            ['key' => 'S1', 'heading' => 'Problem Management', 'text' => 'Svar en.', 'used_page_ids' => [101]],
            ['key' => 'S2', 'heading' => 'Beste praksis', 'text' => 'Svar to.', 'used_page_ids' => []],
        ];
    }

    private function onePage(): array
    {
        return [[
            'page_id' => 101,
            'title' => 'Problem Management',
            'page_type' => 'concept',
            'content_mode' => 'full',
            'content_markdown' => 'Problem Management gjennomfører rotårsaksanalyse.',
            'selected_headings' => [],
            'claim_texts' => [],
        ]];
    }

    public function test_payload_contract_sends_sections_and_pages(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['sections' => [$this->assessment('S1', 'aligned', [101])]]);
            });
        });

        app(RequirementWikiAlignmentAiClient::class)->assessAlignment('1.1', 'text', $this->oneSection(), $this->onePage(), 'no');

        $this->assertSame('gpt-4.1-mini', $captured['model']);
        $userText = data_get($captured, 'input.1.content.0.text');
        $this->assertStringContainsString('SECTION_KEY: S1', $userText);
        $this->assertStringContainsString('PAGE_ID: 101', $userText);
    }

    public function test_prompt_distinguishes_a_knowledge_gap_from_a_real_conflict(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['sections' => [$this->assessment('S1', 'aligned', [101])]]);
            });
        });

        app(RequirementWikiAlignmentAiClient::class)->assessAlignment('1.1', 'text', $this->oneSection(), $this->onePage(), 'no');

        $developerPrompt = data_get($captured, 'input.0.content.0.text');
        $this->assertStringContainsString('Silence in the Wiki is not disagreement', $developerPrompt);
        $this->assertStringContainsString('do not confuse a knowledge gap with a conflict', $developerPrompt);
        $this->assertStringContainsString('When in doubt between best_practice and possible_conflict, choose best_practice', $developerPrompt);
    }

    public function test_it_returns_one_assessment_per_section_in_the_given_order(): void
    {
        $client = $this->clientReturning(['sections' => [
            $this->assessment('S2', 'best_practice'),
            $this->assessment('S1', 'aligned', [101]),
        ]]);

        $result = $client->assessAlignment('1.1', 'text', $this->twoSections(), $this->onePage(), 'no');

        $this->assertSame('S1', $result[0]['section_key']);
        $this->assertSame('S2', $result[1]['section_key']);
        $this->assertSame('aligned', $result[0]['alignment_status']);
        $this->assertSame('best_practice', $result[1]['alignment_status']);
    }

    public function test_supporting_page_ids_are_filtered_to_the_pages_actually_given(): void
    {
        $client = $this->clientReturning(['sections' => [$this->assessment('S1', 'aligned', [101, 999])]]);

        $result = $client->assessAlignment('1.1', 'text', $this->oneSection(), $this->onePage(), 'no');

        $this->assertSame([101], $result[0]['supporting_page_ids']);
    }

    public function test_conflict_summary_is_forced_null_unless_status_is_possible_conflict(): void
    {
        $client = $this->clientReturning(['sections' => [
            [
                'section_key' => 'S1',
                'alignment_status' => 'aligned',
                'supporting_page_ids' => [101],
                'supported_points' => [],
                'uncovered_points' => [],
                'conflict_summary' => 'Dette bør ikke vises.',
                'review_note' => null,
            ],
        ]]);

        $result = $client->assessAlignment('1.1', 'text', $this->oneSection(), $this->onePage(), 'no');

        $this->assertNull($result[0]['conflict_summary']);
    }

    public function test_possible_conflict_keeps_its_conflict_summary(): void
    {
        $client = $this->clientReturning(['sections' => [
            $this->assessment('S1', 'possible_conflict', [], [], [], 'Wiki-en sier at Kunden eier prosessen, svaret sier Leverandøren gjør det.'),
        ]]);

        $result = $client->assessAlignment('1.1', 'text', $this->oneSection(), $this->onePage(), 'no');

        $this->assertSame('possible_conflict', $result[0]['alignment_status']);
        $this->assertSame('Wiki-en sier at Kunden eier prosessen, svaret sier Leverandøren gjør det.', $result[0]['conflict_summary']);
    }

    public function test_a_missing_assessment_for_a_given_section_throws(): void
    {
        $client = $this->clientReturning(['sections' => [$this->assessment('S1', 'aligned', [101])]]);

        $this->expectException(RuntimeException::class);

        $client->assessAlignment('1.1', 'text', $this->twoSections(), $this->onePage(), 'no');
    }

    public function test_a_duplicate_section_key_in_the_response_keeps_the_first_one(): void
    {
        $client = $this->clientReturning(['sections' => [
            $this->assessment('S1', 'aligned', [101]),
            $this->assessment('S1', 'best_practice'),
        ]]);

        $result = $client->assessAlignment('1.1', 'text', $this->oneSection(), $this->onePage(), 'no');

        $this->assertCount(1, $result);
        $this->assertSame('aligned', $result[0]['alignment_status']);
    }

    public function test_throws_when_no_answer_sections_are_given(): void
    {
        $this->expectException(RuntimeException::class);

        app(RequirementWikiAlignmentAiClient::class)->assessAlignment('1.1', 'text', [], $this->onePage(), 'no');
    }

    public function test_throws_when_ai_generation_is_disabled(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);

        $this->expectException(RuntimeException::class);

        app(RequirementWikiAlignmentAiClient::class)->assessAlignment('1.1', 'text', $this->oneSection(), $this->onePage(), 'no');
    }

    private function assessment(
        string $key,
        string $status,
        array $supportingPageIds = [],
        array $supportedPoints = [],
        array $uncoveredPoints = [],
        ?string $conflictSummary = null,
        ?string $reviewNote = null,
    ): array {
        return [
            'section_key' => $key,
            'alignment_status' => $status,
            'supporting_page_ids' => $supportingPageIds,
            'supported_points' => $supportedPoints,
            'uncovered_points' => $uncoveredPoints,
            'conflict_summary' => $conflictSummary,
            'review_note' => $reviewNote,
        ];
    }

    private function clientReturning(array $body): RequirementWikiAlignmentAiClient
    {
        $this->mock(OpenAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('createResponse')->once()->andReturn($this->response($body)));

        return app(RequirementWikiAlignmentAiClient::class);
    }

    private function response(array $body): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'output' => [], 'output_text' => json_encode($body)];
    }
}
