<?php

namespace Tests\Unit\Services;

use App\Services\Knowledge\AiKnowledgeChunkBoundaryService;
use App\Services\OpenAi\OpenAiClient;
use Mockery;
use Tests\TestCase;

class AiKnowledgeChunkBoundaryServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_does_not_group_different_h2_sections_together(): void
    {
        $this->mockOpenAiClient();

        $service = app(AiKnowledgeChunkBoundaryService::class);
        $result = $service->suggestBoundaries(101, [
            'document_title' => 'Boundary test document',
            'original_filename' => 'boundary-test.docx',
            'content_type' => 'reference',
            'document_type' => 'reference',
            'summary' => null,
        ], $this->structureFromElements([
            $this->element('1. Seksjon', '1. Seksjon', 'heading', 1),
            $this->element($this->repeatWords('intro-a', 120), '1. Seksjon'),
            $this->element('1.1 Underemne A', '1. Seksjon > Underemne A', 'heading', 2),
            $this->element($this->repeatWords('alpha', 120), '1. Seksjon > Underemne A'),
            $this->element('1.2 Underemne B', '1. Seksjon > Underemne B', 'heading', 2),
            $this->element($this->repeatWords('beta', 120), '1. Seksjon > Underemne B'),
        ]));

        $groups = $result['analysis_groups'];

        $this->assertCount(3, $groups);
        $this->assertSame(
            [
                ['1. Seksjon'],
                ['1. Seksjon > Underemne A'],
                ['1. Seksjon > Underemne B'],
            ],
            array_map(fn (array $group): array => $this->uniqueHeadingPaths($group), $groups),
        );
    }

    public function test_it_keeps_long_h2_content_inside_the_same_h2_section(): void
    {
        $this->mockOpenAiClient();

        $service = app(AiKnowledgeChunkBoundaryService::class);
        $result = $service->suggestBoundaries(102, [
            'document_title' => 'Boundary test document',
            'original_filename' => 'boundary-test.docx',
            'content_type' => 'reference',
            'document_type' => 'reference',
            'summary' => null,
        ], $this->structureFromElements([
            $this->element('1. Seksjon', '1. Seksjon', 'heading', 1),
            $this->element('1.1 Underemne A', '1. Seksjon > Underemne A', 'heading', 2),
            $this->element($this->repeatWords('alpha', 360), '1. Seksjon > Underemne A'),
            $this->element($this->repeatWords('beta', 360), '1. Seksjon > Underemne A'),
            $this->element($this->repeatWords('gamma', 360), '1. Seksjon > Underemne A'),
            $this->element($this->repeatWords('delta', 360), '1. Seksjon > Underemne A'),
        ]));

        $groups = array_values(array_filter(
            $result['analysis_groups'],
            fn (array $group): bool => $this->uniqueHeadingPaths($group) === ['1. Seksjon > Underemne A'],
        ));

        $this->assertGreaterThanOrEqual(2, count($groups));

        foreach ($groups as $group) {
            $this->assertSame(['1. Seksjon > Underemne A'], $this->uniqueHeadingPaths($group));
        }
    }

    public function test_it_uses_h1_as_the_hard_boundary_when_no_h2_exists(): void
    {
        $this->mockOpenAiClient();

        $service = app(AiKnowledgeChunkBoundaryService::class);
        $result = $service->suggestBoundaries(103, [
            'document_title' => 'Boundary test document',
            'original_filename' => 'boundary-test.docx',
            'content_type' => 'reference',
            'document_type' => 'reference',
            'summary' => null,
        ], $this->structureFromElements([
            $this->element('1. Seksjon A', '1. Seksjon A', 'heading', 1),
            $this->element($this->repeatWords('alpha', 120), '1. Seksjon A'),
            $this->element('2. Seksjon B', '2. Seksjon B', 'heading', 1),
            $this->element($this->repeatWords('beta', 120), '2. Seksjon B'),
        ]));

        $groups = $result['analysis_groups'];

        $this->assertCount(2, $groups);
        $this->assertSame(
            [
                ['1. Seksjon A'],
                ['2. Seksjon B'],
            ],
            array_map(fn (array $group): array => $this->uniqueHeadingPaths($group), $groups),
        );
    }

    public function test_it_falls_back_to_word_count_grouping_when_headings_are_missing(): void
    {
        $this->mockOpenAiClient();

        $service = app(AiKnowledgeChunkBoundaryService::class);
        $result = $service->suggestBoundaries(104, [
            'document_title' => 'Boundary test document',
            'original_filename' => 'boundary-test.docx',
            'content_type' => 'reference',
            'document_type' => 'reference',
            'summary' => null,
        ], $this->structureFromElements([
            $this->element($this->repeatWords('alpha', 450)),
            $this->element($this->repeatWords('beta', 450)),
            $this->element($this->repeatWords('gamma', 450)),
        ]));

        $groups = $result['analysis_groups'];

        $this->assertGreaterThanOrEqual(2, count($groups));

        foreach ($groups as $group) {
            $this->assertSame([], $this->uniqueHeadingPaths($group));
        }
    }

    public function test_it_keeps_intro_text_and_following_list_together_inside_one_section(): void
    {
        $this->mockOpenAiClient();

        $service = app(AiKnowledgeChunkBoundaryService::class);
        $result = $service->suggestBoundaries(105, [
            'document_title' => 'Boundary test document',
            'original_filename' => 'boundary-test.docx',
            'content_type' => 'reference',
            'document_type' => 'reference',
            'summary' => null,
        ], $this->structureFromElements([
            $this->element('1. Seksjon A', '1. Seksjon A', 'heading', 1),
            $this->element($this->repeatWords('intro', 750), '1. Seksjon A', 'paragraph', null, 'lead_in'),
            $this->element('• Første punkt', '1. Seksjon A', 'list'),
            $this->element($this->repeatWords('etter', 80), '1. Seksjon A'),
        ]));

        $groups = $result['analysis_groups'];

        $this->assertGreaterThanOrEqual(2, count($groups));

        $leadInGroup = collect($groups)->first(function (array $group): bool {
            return in_array('lead_in', array_map(
                static fn (array $element): string => (string) data_get($element, 'relation_hint', ''),
                (array) data_get($group, 'elements', []),
            ), true);
        });

        $this->assertNotNull($leadInGroup);
        $this->assertContains('list', array_map(
            static fn (array $element): string => (string) data_get($element, 'type', ''),
            (array) data_get($leadInGroup, 'elements', []),
        ));
    }

    public function test_it_rejects_incomplete_responses_and_uses_deterministic_fallback(): void
    {
        $this->app->instance(OpenAiClient::class, $this->mockOpenAiClient([
            'id' => 'resp_boundary_incomplete',
            'status' => 'incomplete',
            'incomplete_details' => [
                'reason' => 'max_output_tokens',
            ],
            'output_text' => '',
            'output' => [],
        ]));

        $service = app(AiKnowledgeChunkBoundaryService::class);
        $result = $service->suggestBoundaries(101, [
            'document_title' => 'Boundary test document',
            'original_filename' => 'boundary-test.docx',
            'content_type' => 'reference',
            'document_type' => 'reference',
            'summary' => null,
        ], $this->structureFromText('Kort innhold til boundary-test.'));

        $this->assertCount(1, $result['analysis_groups']);
        $this->assertSame([], $result['analysis_groups'][0]['suggested_chunks']);
    }

    public function test_it_rejects_non_integer_offsets_and_uses_deterministic_fallback(): void
    {
        $this->app->instance(OpenAiClient::class, $this->mockOpenAiClient([
            'id' => 'resp_boundary_invalid_offsets',
            'status' => 'completed',
            'output_text' => json_encode([
                'chunks' => [
                    [
                        'start_offset_relative' => '0',
                        'end_offset_relative' => '18',
                        'short_reason' => 'Invalid offsets.',
                        'topic' => 'Tema A',
                        'sub_topic' => 'Underemne A',
                        'keywords' => ['A', 'B', 'C'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));

        $service = app(AiKnowledgeChunkBoundaryService::class);
        $result = $service->suggestBoundaries(102, [
            'document_title' => 'Boundary test document',
            'original_filename' => 'boundary-test.docx',
            'content_type' => 'reference',
            'document_type' => 'reference',
            'summary' => null,
        ], $this->structureFromText('Kort innhold til boundary-test.'));

        $this->assertCount(1, $result['analysis_groups']);
        $this->assertSame([], $result['analysis_groups'][0]['suggested_chunks']);
    }

    /**
     * Purpose: Provide a reusable OpenAI client double for boundary-service tests.
     * Inputs: Optional response payload override.
     * Returns: A mock client registered in the test container.
     * Side effects: Replaces the OpenAI client binding for the current test.
     */
    private function mockOpenAiClient(?array $response = null): OpenAiClient
    {
        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->andReturn($response ?? [
                'id' => 'resp_boundary_ok',
                'status' => 'completed',
                'output_text' => '{"chunks":[]}',
                'output' => [],
            ]);

        $this->app->instance(OpenAiClient::class, $client);

        return $client;
    }

    /**
     * Purpose: Build a canonical structured document for boundary-service tests.
     * Inputs: Ordered structural element definitions.
     * Returns: A canonical source text plus sequential element offsets.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $elements
     * @return array{source_text: string, elements: array<int, array<string, mixed>>}
     */
    private function structureFromElements(array $elements): array
    {
        $normalized = [];
        $sourceTextParts = [];
        $cursor = 0;

        foreach (array_values($elements) as $index => $element) {
            $text = trim((string) ($element['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $startOffset = $cursor;
            $sourceTextParts[] = $text;
            $cursor += mb_strlen($text, 'UTF-8');

            if ($index < count($elements) - 1) {
                $cursor += 2;
            }

            $normalized[] = [
                'id' => (string) ($element['id'] ?? sprintf('element-%04d', count($normalized) + 1)),
                'type' => (string) ($element['type'] ?? 'paragraph'),
                'heading_path' => $element['heading_path'] ?? null,
                'text' => $text,
                'start_offset' => $startOffset,
                'end_offset' => $cursor,
                'order_index' => count($normalized),
                'heading_level' => array_key_exists('heading_level', $element) ? $element['heading_level'] : null,
                'relation_hint' => $element['relation_hint'] ?? null,
            ];
        }

        return [
            'source_text' => implode("\n\n", $sourceTextParts),
            'elements' => $normalized,
        ];
    }

    /**
     * Purpose: Build one unheaded structural paragraph for boundary-service tests.
     * Inputs: The raw text to place in one paragraph element.
     * Returns: A canonical source text and one ordered element.
     * Side effects: None.
     *
     * @return array{source_text: string, elements: array<int, array<string, mixed>>}
     */
    private function structureFromText(string $text): array
    {
        return $this->structureFromElements([
            $this->element($text),
        ]);
    }

    /**
     * Purpose: Create one structural element definition for boundary-service tests.
     * Inputs: The visible text and optional structural metadata.
     * Returns: A compact element definition before offsets are assigned.
     * Side effects: None.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function element(string $text, ?string $headingPath = null, string $type = 'paragraph', ?int $headingLevel = null, ?string $relationHint = null): array
    {
        return array_filter([
            'text' => $text,
            'heading_path' => $headingPath,
            'type' => $type,
            'heading_level' => $headingLevel,
            'relation_hint' => $relationHint,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Purpose: Return the unique heading paths present in one analysis group.
     * Inputs: One analysis group.
     * Returns: Ordered unique heading paths or an empty list when none exist.
     * Side effects: None.
     *
     * @param array<string, mixed> $group
     * @return array<int, string>
     */
    private function uniqueHeadingPaths(array $group): array
    {
        $paths = [];

        foreach ((array) ($group['elements'] ?? []) as $element) {
            if (! is_array($element)) {
                continue;
            }

            $headingPath = trim((string) data_get($element, 'heading_path', ''));

            if ($headingPath === '') {
                continue;
            }

            if (! in_array($headingPath, $paths, true)) {
                $paths[] = $headingPath;
            }
        }

        return $paths;
    }

    /**
     * Purpose: Repeat one token a fixed number of times for test content generation.
     * Inputs: The token and the desired word count.
     * Returns: A whitespace-separated string with the token repeated.
     * Side effects: None.
     */
    private function repeatWords(string $word, int $count): string
    {
        return trim(str_repeat($word.' ', max(0, $count)));
    }
}
