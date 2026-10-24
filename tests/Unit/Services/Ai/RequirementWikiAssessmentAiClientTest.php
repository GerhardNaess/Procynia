<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\RequirementWikiAssessmentAiClient;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Purpose: Verify the assessment client accepts what its own prompt asks for — an empty
 * missing_information when coverage is "covered" and nothing is missing (V2-F1) — while every other
 * malformed answer is still refused.
 * Inputs: None.
 * Returns: None.
 * Side effects: None.
 */
class RequirementWikiAssessmentAiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_the_prompt_asks_for_an_empty_missing_information_only_when_covered(): void
    {
        $captured = null;
        $this->mock(OpenAiClient::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('createResponse')->once()->andReturnUsing(function (array $payload) use (&$captured): array {
                $captured = $payload;

                return $this->response($this->assessment('covered', ''));
            });
        });

        $this->assess();

        $this->assertStringContainsString(
            'missing_information: what documented knowledge is missing (or "" only when coverage_status is "covered" and truly nothing is missing)',
            data_get($captured, 'input.0.content.0.text'),
        );
    }

    public function test_an_empty_missing_information_is_valid_for_full_coverage(): void
    {
        $result = $this->clientReturning($this->assessment('covered', ''))->assessRequirement('K-1', 'Krav', [], 'no');

        $this->assertSame('covered', $result['coverage_status']);
        $this->assertSame('', $result['missing_information']);
    }

    public function test_whitespace_only_missing_information_is_stored_as_empty_for_full_coverage(): void
    {
        $result = $this->clientReturning($this->assessment('covered', "  \n"))->assessRequirement('K-1', 'Krav', [], 'no');

        $this->assertSame('', $result['missing_information']);
    }

    public function test_a_written_missing_information_is_kept_for_full_coverage(): void
    {
        $result = $this->clientReturning($this->assessment('covered', ' Ingenting vesentlig mangler. '))->assessRequirement('K-1', 'Krav', [], 'no');

        $this->assertSame('Ingenting vesentlig mangler.', $result['missing_information']);
    }

    public function test_an_empty_missing_information_is_refused_for_partial_coverage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[missing_information]');

        $this->clientReturning($this->assessment('partial', ''))->assessRequirement('K-1', 'Krav', [], 'no');
    }

    public function test_an_empty_missing_information_is_refused_for_missing_coverage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[missing_information]');

        $this->clientReturning($this->assessment('missing', ' '))->assessRequirement('K-1', 'Krav', [], 'no');
    }

    public function test_an_absent_missing_information_is_refused_even_for_full_coverage(): void
    {
        $body = $this->assessment('covered', '');
        unset($body['missing_information']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[missing_information]');

        $this->clientReturning($body)->assessRequirement('K-1', 'Krav', [], 'no');
    }

    public function test_a_non_string_missing_information_is_refused_even_for_full_coverage(): void
    {
        $body = $this->assessment('covered', '');
        $body['missing_information'] = null;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[missing_information]');

        $this->clientReturning($body)->assessRequirement('K-1', 'Krav', [], 'no');
    }

    public function test_an_invalid_coverage_status_is_still_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('coverage_status');

        $this->clientReturning($this->assessment('fully_covered', ''))->assessRequirement('K-1', 'Krav', [], 'no');
    }

    public function test_other_empty_text_fields_are_still_refused_for_full_coverage(): void
    {
        $body = $this->assessment('covered', '');
        $body['recommended_next_step'] = '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[recommended_next_step]');

        $this->clientReturning($body)->assessRequirement('K-1', 'Krav', [], 'no');
    }

    private function assess(): array
    {
        return app(RequirementWikiAssessmentAiClient::class)->assessRequirement('K-1', 'Krav', [], 'no');
    }

    private function assessment(string $coverageStatus, string $missingInformation): array
    {
        return [
            'coverage_status' => $coverageStatus,
            'has_possible_conflict' => false,
            'risk_level' => 'low',
            'requirement_summary' => 'Kravet gjelder kriterier for full gjennomgang.',
            'coverage_rationale' => 'Wiki-siden dokumenterer kriteriene.',
            'missing_information' => $missingInformation,
            'recommended_next_step' => 'Ingen handling nødvendig.',
        ];
    }

    private function clientReturning(array $body): RequirementWikiAssessmentAiClient
    {
        $this->mock(OpenAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('createResponse')->once()->andReturn($this->response($body)));

        return app(RequirementWikiAssessmentAiClient::class);
    }

    private function response(array $body): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'output' => [], 'output_text' => json_encode($body)];
    }
}
