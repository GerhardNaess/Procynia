<?php

namespace Tests\Unit\Services;

use App\Services\Knowledge\KnowledgeChunkBoundaryValidator;
use Tests\TestCase;

class KnowledgeChunkBoundaryValidatorTest extends TestCase
{
    public function test_it_accepts_valid_ai_boundaries_for_mixed_structural_sections(): void
    {
        $structure = $this->structureFromBlocks([
            ['type' => 'paragraph', 'text' => 'Intro før første hovedseksjon.', 'heading_path' => null],
            ['type' => 'heading', 'text' => 'Hovedseksjon A', 'heading_path' => 'Hovedseksjon A', 'heading_level' => 1],
            ['type' => 'paragraph', 'text' => str_repeat('Innhold A ', 40), 'heading_path' => 'Hovedseksjon A'],
            ['type' => 'heading', 'text' => 'Underseksjon A1', 'heading_path' => 'Hovedseksjon A > Underseksjon A1', 'heading_level' => 2],
            ['type' => 'paragraph', 'text' => str_repeat('Mer A1 ', 40), 'heading_path' => 'Hovedseksjon A > Underseksjon A1'],
            ['type' => 'heading', 'text' => 'Hovedseksjon B', 'heading_path' => 'Hovedseksjon B', 'heading_level' => 1],
            ['type' => 'paragraph', 'text' => str_repeat('Innhold B ', 40), 'heading_path' => 'Hovedseksjon B'],
        ]);

        $elements = $structure['elements'];
        $validator = app(KnowledgeChunkBoundaryValidator::class);
        $result = $validator->validate($structure, [[
            'group_index' => 0,
            'start_offset' => 0,
            'end_offset' => mb_strlen($structure['source_text'], 'UTF-8'),
            'text' => $structure['source_text'],
            'elements' => $elements,
            'suggested_chunks' => [
                [
                    'start_offset_relative' => $elements[0]['start_offset'],
                    'end_offset_relative' => $elements[5]['start_offset'],
                    'short_reason' => 'Første tematisk samlede del.',
                    'topic' => 'Tema A',
                    'sub_topic' => 'Underemne A',
                    'keywords' => ['samhandling'],
                ],
                [
                    'start_offset_relative' => $elements[5]['start_offset'],
                    'end_offset_relative' => $elements[6]['end_offset'],
                    'short_reason' => 'Andre hovedseksjon.',
                    'topic' => 'Tema B',
                    'sub_topic' => 'Underemne B',
                    'keywords' => ['drift'],
                ],
            ],
        ]]);

        $this->assertCount(2, $result);
        $this->assertSame('Hovedseksjon A', $result[0]['heading_path']);
        $this->assertSame('semantic', $result[0]['chunk_type']);
        $this->assertStringContainsString('Underseksjon A1', mb_substr($structure['source_text'], $result[0]['start_offset'], $result[0]['end_offset'] - $result[0]['start_offset'], 'UTF-8'));
        $this->assertSame('Hovedseksjon B', $result[1]['heading_path']);
        $this->assertSame('semantic', $result[1]['chunk_type']);
    }

    public function test_it_keeps_intro_and_list_context_together_when_the_group_is_short(): void
    {
        $structure = $this->structureFromBlocks([
            ['type' => 'paragraph', 'text' => 'Intro før hovedseksjon.', 'heading_path' => null],
            ['type' => 'heading', 'text' => 'Kort seksjon', 'heading_path' => 'Kort seksjon', 'heading_level' => 1],
            ['type' => 'paragraph', 'text' => 'Kort forklaring.', 'heading_path' => 'Kort seksjon'],
            ['type' => 'list', 'text' => '• Første punkt', 'heading_path' => 'Kort seksjon', 'relation_hint' => 'list_item'],
            ['type' => 'list', 'text' => '• Andre punkt', 'heading_path' => 'Kort seksjon', 'relation_hint' => 'list_item'],
        ]);

        $validator = app(KnowledgeChunkBoundaryValidator::class);
        $result = $validator->validate($structure, [[
            'group_index' => 0,
            'start_offset' => 0,
            'end_offset' => mb_strlen($structure['source_text'], 'UTF-8'),
            'text' => $structure['source_text'],
            'elements' => $structure['elements'],
            'suggested_chunks' => [],
        ]]);

        $this->assertCount(1, $result);
        $this->assertSame('Kort seksjon', $result[0]['heading_path']);
        $this->assertStringContainsString('Intro før hovedseksjon.', $result[0]['content']);
        $this->assertStringContainsString('• Første punkt', $result[0]['content']);
        $this->assertStringContainsString('• Andre punkt', $result[0]['content']);
    }

    public function test_it_falls_back_to_safe_deterministic_packing_when_ai_boundaries_overlap_or_leave_gaps(): void
    {
        $structure = $this->structureFromBlocks([
            ['type' => 'paragraph', 'text' => 'Intro før hovedseksjon.', 'heading_path' => null],
            ['type' => 'heading', 'text' => 'Hovedseksjon', 'heading_path' => 'Hovedseksjon', 'heading_level' => 1],
            ['type' => 'paragraph', 'text' => 'Forklaring.', 'heading_path' => 'Hovedseksjon'],
            ['type' => 'paragraph', 'text' => 'Mer forklaring.', 'heading_path' => 'Hovedseksjon'],
        ]);

        $elements = $structure['elements'];
        $validator = app(KnowledgeChunkBoundaryValidator::class);
        $result = $validator->validate($structure, [[
            'group_index' => 0,
            'start_offset' => 0,
            'end_offset' => mb_strlen($structure['source_text'], 'UTF-8'),
            'text' => $structure['source_text'],
            'elements' => $elements,
            'suggested_chunks' => [
                [
                    'start_offset_relative' => $elements[0]['start_offset'],
                    'end_offset_relative' => $elements[2]['end_offset'],
                    'short_reason' => 'Første del.',
                    'topic' => 'Tema',
                    'sub_topic' => 'Underemne',
                    'keywords' => ['A'],
                ],
                [
                    'start_offset_relative' => $elements[2]['start_offset'],
                    'end_offset_relative' => $elements[3]['end_offset'],
                    'short_reason' => 'Overlappende del.',
                    'topic' => 'Tema',
                    'sub_topic' => 'Underemne',
                    'keywords' => ['B'],
                ],
            ],
        ]]);

        $this->assertCount(1, $result);
        $this->assertSame('Hovedseksjon', $result[0]['heading_path']);
        $this->assertStringContainsString('Intro før hovedseksjon.', $result[0]['content']);
        $this->assertStringContainsString('Mer forklaring.', $result[0]['content']);
    }

    public function test_it_rejects_zero_length_and_repeated_ai_boundaries_and_falls_back_to_deterministic_packing(): void
    {
        $structure = $this->structureFromBlocks([
            ['type' => 'paragraph', 'text' => 'Intro før hovedseksjon.', 'heading_path' => null],
            ['type' => 'heading', 'text' => 'Hovedseksjon', 'heading_path' => 'Hovedseksjon', 'heading_level' => 1],
            ['type' => 'paragraph', 'text' => 'Forklaring.', 'heading_path' => 'Hovedseksjon'],
            ['type' => 'paragraph', 'text' => 'Mer forklaring.', 'heading_path' => 'Hovedseksjon'],
        ]);

        $elements = $structure['elements'];
        $validator = app(KnowledgeChunkBoundaryValidator::class);
        $result = $validator->validate($structure, [[
            'group_index' => 0,
            'start_offset' => 0,
            'end_offset' => mb_strlen($structure['source_text'], 'UTF-8'),
            'text' => $structure['source_text'],
            'elements' => $elements,
            'suggested_chunks' => [
                [
                    'start_offset_relative' => 0,
                    'end_offset_relative' => 0,
                    'short_reason' => 'Zero-length chunk.',
                    'topic' => 'Tema A',
                    'sub_topic' => 'Underemne A',
                    'keywords' => ['A'],
                ],
                [
                    'start_offset_relative' => 0,
                    'end_offset_relative' => 0,
                    'short_reason' => 'Repeated zero-length chunk.',
                    'topic' => 'Tema A',
                    'sub_topic' => 'Underemne A',
                    'keywords' => ['A'],
                ],
            ],
        ]]);

        $this->assertCount(1, $result);
        $this->assertSame('Hovedseksjon', $result[0]['heading_path']);
        $this->assertStringContainsString('Intro før hovedseksjon.', $result[0]['content']);
        $this->assertStringContainsString('Mer forklaring.', $result[0]['content']);
    }

    /**
     * Purpose: Build canonical source text and structural elements for validator tests.
     * Inputs: Ordered structural block definitions.
     * Returns: A structure payload compatible with the validator contract.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array{source_text: string, elements: array<int, array<string, mixed>>}
     */
    private function structureFromBlocks(array $blocks): array
    {
        $elements = [];
        $sourceTextParts = [];
        $cursor = 0;

        foreach (array_values($blocks) as $index => $block) {
            $text = trim((string) data_get($block, 'text', ''));

            if ($text === '') {
                continue;
            }

            $startOffset = $cursor;
            $sourceTextParts[] = $text;
            $cursor += mb_strlen($text, 'UTF-8');

            if ($index < count($blocks) - 1) {
                $cursor += 2;
            }

            $endOffset = $cursor;

            $elements[] = [
                'id' => sprintf('element-%04d', count($elements) + 1),
                'type' => (string) data_get($block, 'type', 'paragraph'),
                'heading_path' => data_get($block, 'heading_path'),
                'text' => $text,
                'start_offset' => $startOffset,
                'end_offset' => $endOffset,
                'order_index' => count($elements),
                'heading_level' => data_get($block, 'heading_level'),
                'relation_hint' => data_get($block, 'relation_hint'),
            ];

        }

        return [
            'source_text' => implode("\n\n", $sourceTextParts),
            'elements' => $elements,
        ];
    }
}
