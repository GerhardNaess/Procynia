<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\RequirementWikiAnswerRevisionAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Purpose: Verify the single-conflict-revision client only ever touches the sections it was asked
 * to revise, validates used_page_ids against the pages actually given, drops any hallucinated or
 * unrequested key, and never retries on its own (RequirementWikiAnswerService owns the "at most one
 * revision pass" rule — this client is just the one call it may make).
 * Inputs: None.
 * Returns: None.
 * Side effects: None.
 */
class RequirementWikiAnswerRevisionAiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    private function oneSectionToRevise(): array
    {
        return [[
            'key' => 'S2',
            'heading' => 'Ansvar',
            'text' => 'Leverandøren eier prosessen videre.',
            'used_page_ids' => [],
            'conflict_summary' => 'Wiki-en sier at Kunden eier prosessen etter etablering, ikke Leverandøren.',
        ]];
    }

    private function onePage(): array
    {
        return [[
            'page_id' => 101,
            'title' => 'Problem Management',
            'page_type' => 'concept',
            'content_mode' => 'full',
            'content_markdown' => 'Etter etablering eier Kunden prosessen videre.',
            'selected_headings' => [],
            'claim_texts' => [],
        ]];
    }

    public function test_payload_contract_sends_the_conflict_summary_and_pages(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['revised_sections' => [['key' => 'S2', 'heading' => 'Ansvar', 'text' => 'Kunden eier prosessen videre.', 'used_page_ids' => [101]]]]);
            });
        });

        app(RequirementWikiAnswerRevisionAiClient::class)->reviseSections('1.1', 'text', $this->oneSectionToRevise(), $this->onePage(), 'no');

        $userText = data_get($captured, 'input.1.content.0.text');
        $this->assertStringContainsString('KEY: S2', $userText);
        $this->assertStringContainsString('Wiki-en sier at Kunden eier prosessen', $userText);
        $this->assertStringContainsString('PAGE_ID: 101', $userText);
    }

    public function test_prompt_instructs_fixing_only_the_described_contradiction(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response(['revised_sections' => [['key' => 'S2', 'heading' => '', 'text' => 'Fikset.', 'used_page_ids' => []]]]);
            });
        });

        app(RequirementWikiAnswerRevisionAiClient::class)->reviseSections('1.1', 'text', $this->oneSectionToRevise(), $this->onePage(), 'no');

        $developerPrompt = data_get($captured, 'input.0.content.0.text');
        $this->assertStringContainsString('Fix ONLY the specific contradiction described', $developerPrompt);
        $this->assertStringContainsString('Preserve the section\'s professional quality', $developerPrompt);
        $this->assertStringContainsString('Do not make the section generic, vague, or overly cautious', $developerPrompt);
    }

    public function test_it_returns_the_revised_section_keyed_by_its_key(): void
    {
        $client = $this->clientReturning(['revised_sections' => [
            ['key' => 'S2', 'heading' => 'Ansvar', 'text' => 'Kunden eier prosessen videre etter etablering.', 'used_page_ids' => [101]],
        ]]);

        $result = $client->reviseSections('1.1', 'text', $this->oneSectionToRevise(), $this->onePage(), 'no');

        $this->assertArrayHasKey('S2', $result);
        $this->assertSame('Kunden eier prosessen videre etter etablering.', $result['S2']['text']);
        $this->assertSame([101], $result['S2']['used_page_ids']);
    }

    public function test_an_unrequested_key_is_dropped(): void
    {
        $client = $this->clientReturning(['revised_sections' => [
            ['key' => 'S2', 'heading' => '', 'text' => 'Fikset S2.', 'used_page_ids' => []],
            ['key' => 'S99', 'heading' => '', 'text' => 'Hallusinert seksjon.', 'used_page_ids' => []],
        ]]);

        $result = $client->reviseSections('1.1', 'text', $this->oneSectionToRevise(), $this->onePage(), 'no');

        $this->assertArrayHasKey('S2', $result);
        $this->assertArrayNotHasKey('S99', $result);
    }

    public function test_an_unknown_page_id_is_filtered_out(): void
    {
        $client = $this->clientReturning(['revised_sections' => [
            ['key' => 'S2', 'heading' => '', 'text' => 'Fikset.', 'used_page_ids' => [101, 999]],
        ]]);

        $result = $client->reviseSections('1.1', 'text', $this->oneSectionToRevise(), $this->onePage(), 'no');

        $this->assertSame([101], $result['S2']['used_page_ids']);
    }

    public function test_an_empty_revised_text_is_not_returned(): void
    {
        $client = $this->clientReturning(['revised_sections' => [
            ['key' => 'S2', 'heading' => '', 'text' => '', 'used_page_ids' => []],
        ]]);

        $result = $client->reviseSections('1.1', 'text', $this->oneSectionToRevise(), $this->onePage(), 'no');

        $this->assertArrayNotHasKey('S2', $result);
    }

    public function test_throws_when_no_sections_are_given_to_revise(): void
    {
        $this->expectException(RuntimeException::class);

        app(RequirementWikiAnswerRevisionAiClient::class)->reviseSections('1.1', 'text', [], $this->onePage(), 'no');
    }

    public function test_throws_when_ai_generation_is_disabled(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);

        $this->expectException(RuntimeException::class);

        app(RequirementWikiAnswerRevisionAiClient::class)->reviseSections('1.1', 'text', $this->oneSectionToRevise(), $this->onePage(), 'no');
    }

    private function clientReturning(array $body): RequirementWikiAnswerRevisionAiClient
    {
        $this->mock(OpenAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('createResponse')->once()->andReturn($this->response($body)));

        return app(RequirementWikiAnswerRevisionAiClient::class);
    }

    private function response(array $body): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'output' => [], 'output_text' => json_encode($body)];
    }
}
