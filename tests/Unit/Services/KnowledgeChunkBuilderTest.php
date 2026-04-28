<?php

namespace Tests\Unit\Services;

use App\Services\Knowledge\KnowledgeChunkBuilder;
use Tests\TestCase;

class KnowledgeChunkBuilderTest extends TestCase
{
    public function test_it_builds_chunk_payloads_from_original_source_text_and_validated_plans(): void
    {
        $sourceText = implode("\n\n", [
            'Intro før første heading.',
            'Strategisk samhandling',
            'Første avsnitt under hovedseksjonen.',
            'Underseksjon A',
            'Mer tekst i underseksjonen.',
            'Andre hovedseksjon',
            'Avsluttende avsnitt.',
        ]);

        $firstChunkEnd = mb_strlen(implode("\n\n", [
            'Intro før første heading.',
            'Strategisk samhandling',
            'Første avsnitt under hovedseksjonen.',
            'Underseksjon A',
            'Mer tekst i underseksjonen.',
        ]), 'UTF-8') + 2;

        $chunkPlans = [
            [
                'start_offset' => 0,
                'end_offset' => $firstChunkEnd,
                'order_index' => 0,
                'chunk_type' => 'semantic',
                'heading_path' => 'Strategisk samhandling',
                'section_title' => 'Strategisk samhandling',
                'section_path' => 'Strategisk samhandling',
                'title' => null,
                'topic' => 'Tema A',
                'sub_topic' => 'Underemne A',
                'keywords' => ['samhandling', 'SLA'],
            ],
            [
                'start_offset' => $firstChunkEnd,
                'end_offset' => mb_strlen($sourceText, 'UTF-8'),
                'order_index' => 1,
                'chunk_type' => 'list',
                'heading_path' => 'Andre hovedseksjon',
                'section_title' => 'Andre hovedseksjon',
                'section_path' => 'Andre hovedseksjon',
                'title' => null,
                'topic' => 'Tema B',
                'sub_topic' => 'Underemne B',
                'keywords' => 'drift, drift, tilgjengelighet',
            ],
        ];

        $payloads = app(KnowledgeChunkBuilder::class)->build($sourceText, $chunkPlans);

        $this->assertCount(2, $payloads);
        $this->assertSame(0, $payloads[0]['chunk_index']);
        $this->assertSame('Intro før første heading.', trim((string) strtok($payloads[0]['content'], "\n")));
        $this->assertStringContainsString('Underseksjon A', $payloads[0]['content']);
        $this->assertSame('Strategisk samhandling', $payloads[0]['heading_path']);
        $this->assertSame('semantic', $payloads[0]['chunk_type']);
        $this->assertSame('Strategisk samhandling', $payloads[0]['title']);
        $this->assertSame('Strategisk samhandling', $payloads[0]['section_title']);
        $this->assertSame(['samhandling', 'SLA'], $payloads[0]['keywords']);
        $this->assertSame('Andre hovedseksjon', $payloads[1]['heading_path']);
        $this->assertSame('list', $payloads[1]['chunk_type']);
        $this->assertSame(['drift', 'tilgjengelighet'], $payloads[1]['keywords']);
        $this->assertSame('Andre hovedseksjon', $payloads[1]['title']);
        $this->assertSame('pending_review', $payloads[0]['review_status']);
        $this->assertSame('pending_review', $payloads[1]['review_status']);
        $this->assertSame(
            mb_substr($sourceText, $chunkPlans[0]['start_offset'], $chunkPlans[0]['end_offset'] - $chunkPlans[0]['start_offset'], 'UTF-8'),
            $payloads[0]['content'],
        );
        $this->assertSame(
            mb_substr($sourceText, $chunkPlans[1]['start_offset'], $chunkPlans[1]['end_offset'] - $chunkPlans[1]['start_offset'], 'UTF-8'),
            $payloads[1]['content'],
        );
    }

    public function test_it_keeps_title_null_when_no_heading_context_exists(): void
    {
        $sourceText = str_repeat('Boilerplate document content that will chunk deterministically. ', 20);

        $payloads = app(KnowledgeChunkBuilder::class)->build($sourceText, [[
            'start_offset' => 0,
            'end_offset' => mb_strlen($sourceText, 'UTF-8'),
            'order_index' => 0,
            'chunk_type' => 'semantic',
            'heading_path' => null,
            'section_title' => null,
            'section_path' => null,
            'title' => null,
            'topic' => null,
            'sub_topic' => null,
            'keywords' => [],
        ]]);

        $this->assertCount(1, $payloads);
        $this->assertNull($payloads[0]['title']);
        $this->assertSame(mb_substr($sourceText, 0, mb_strlen($sourceText, 'UTF-8'), 'UTF-8'), $payloads[0]['content']);
    }
}
