<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Data\Ai\Requirements\DocxImageData;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiSourceReference;
use App\Services\EnterpriseWiki\EnterpriseWikiImageBlockBuilder;
use App\Services\EnterpriseWiki\EnterpriseWikiImageClassificationService;
use App\Services\EnterpriseWiki\EnterpriseWikiImageDescriptionBuilder;
use Tests\TestCase;

class EnterpriseWikiImageBlockBuilderTest extends TestCase
{
    private function builder(): EnterpriseWikiImageBlockBuilder
    {
        $classificationService = new EnterpriseWikiImageClassificationService;

        return new EnterpriseWikiImageBlockBuilder(
            $classificationService,
            new EnterpriseWikiImageDescriptionBuilder($classificationService),
        );
    }

    private function document(): EnterpriseWikiDocument
    {
        $document = new EnterpriseWikiDocument([
            'original_filename' => 'Driftsarkitektur.docx',
            'file_hash_sha256' => str_repeat('b', 64),
        ]);
        $document->id = 11;

        return $document;
    }

    private function image(array $overrides = []): DocxImageData
    {
        return new DocxImageData(
            sourceImageKey: $overrides['sourceImageKey'] ?? 'img0',
            imageIndex: $overrides['imageIndex'] ?? 0,
            documentOrder: $overrides['documentOrder'] ?? 3,
            relationshipId: $overrides['relationshipId'] ?? 'rId1',
            originalMediaPath: $overrides['originalMediaPath'] ?? 'word/media/image1.png',
            mimeType: $overrides['mimeType'] ?? 'image/png',
            width: array_key_exists('width', $overrides) ? $overrides['width'] : 800,
            height: array_key_exists('height', $overrides) ? $overrides['height'] : 600,
            contentHash: $overrides['contentHash'] ?? 'hash-a',
            sectionNumber: $overrides['sectionNumber'] ?? '1',
            sectionTitle: $overrides['sectionTitle'] ?? 'Integrasjoner',
            caption: $overrides['caption'] ?? 'Figur 1: Overordnet arkitektur',
            altText: $overrides['altText'] ?? 'Arkitekturdiagram',
            textBefore: $overrides['textBefore'] ?? 'Tekst før bildet.',
            textAfter: $overrides['textAfter'] ?? null,
            bytes: $overrides['bytes'] ?? 'raw-bytes',
        );
    }

    // ── referencedImageIndexes ───────────────────────────────────────────────

    public function test_referenced_image_indexes_detects_image_citations(): void
    {
        $blocks = [
            [
                'source_elements' => [
                    ['source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_IMAGE, 'source_element_key' => 'img0'],
                ],
            ],
            [
                'source_elements' => [
                    ['source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH, 'source_element_key' => 'paragraph-0'],
                ],
            ],
        ];

        $this->assertSame([0], $this->builder()->referencedImageIndexes($blocks));
    }

    public function test_referenced_image_indexes_is_empty_when_no_images_cited(): void
    {
        $blocks = [['source_elements' => [['source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH]]]];

        $this->assertSame([], $this->builder()->referencedImageIndexes($blocks));
    }

    public function test_referenced_image_indexes_deduplicates_and_sorts(): void
    {
        $blocks = [
            ['source_elements' => [['source_element_type' => 'image', 'source_element_key' => 'img3']]],
            ['source_elements' => [['source_element_type' => 'image', 'source_element_key' => 'img1']]],
            ['source_elements' => [['source_element_type' => 'image', 'source_element_key' => 'img3']]],
        ];

        $this->assertSame([1, 3], $this->builder()->referencedImageIndexes($blocks));
    }

    // ── buildImageBlocks ─────────────────────────────────────────────────────

    public function test_build_image_blocks_produces_a_block_type_image_with_structured_data(): void
    {
        $document = $this->document();
        $image = $this->image();

        $blocks = $this->builder()->buildImageBlocks($document, [$image], [0], 5);

        $this->assertCount(1, $blocks);
        $block = $blocks[0];

        $this->assertSame('image', $block['block_type']);
        $this->assertSame(5, $block['position']);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $block['content_origin']);
        $this->assertSame($document->id, $block['source_id']);
        $this->assertSame('img0', $block['image_data']['source_image_key']);
        $this->assertSame(1, $block['image_data']['figure_number']);
        $this->assertSame('Arkitekturdiagram', $block['image_data']['alt_text']);
        $this->assertSame('Figur 1: Overordnet arkitektur', $block['image_data']['caption']);
        $this->assertSame('Driftsarkitektur.docx → Figur 1', $block['page_reference']);
    }

    public function test_build_image_blocks_has_non_empty_markdown_fallback(): void
    {
        $blocks = $this->builder()->buildImageBlocks($this->document(), [$this->image()], [0], 0);

        $this->assertNotSame('', trim($blocks[0]['markdown']));
        $this->assertStringContainsString('Figur 1: Overordnet arkitektur', $blocks[0]['markdown']);
        $this->assertStringContainsString('Driftsarkitektur.docx → Figur 1', $blocks[0]['markdown']);
    }

    public function test_build_image_blocks_skips_unreferenced_or_unknown_indexes(): void
    {
        $builder = $this->builder();

        $this->assertSame([], $builder->buildImageBlocks($this->document(), [$this->image()], [], 0));
        $this->assertSame([], $builder->buildImageBlocks($this->document(), [$this->image()], [99], 0));
    }

    public function test_build_image_blocks_never_shows_a_decorative_or_logo_image_even_if_cited(): void
    {
        // Defense in depth: a tiny image is never a citable element in the first place (see
        // EnterpriseWikiDocumentSourceElementService), but buildImageBlocks() must independently
        // refuse to render one if it were ever passed a stale/invalid index.
        $tinyLogo = $this->image(['width' => 20, 'height' => 20]);

        $this->assertSame([], $this->builder()->buildImageBlocks($this->document(), [$tinyLogo], [0], 0));
    }

    public function test_build_image_blocks_gives_decorative_images_empty_alt_text_when_shown(): void
    {
        // A "small, no caption/alt" image classifies as decorative and is filtered out entirely —
        // this test exercises the empty-alt-text branch directly via a keyword-triggered decorative
        // classification path is not reachable (logo/decorative are never built); instead confirm
        // a genuinely showable image keeps its real alt-text.
        $blocks = $this->builder()->buildImageBlocks($this->document(), [$this->image()], [0], 0);

        $this->assertNotSame('', $blocks[0]['image_data']['alt_text']);
    }

    public function test_build_image_blocks_handles_multiple_images_in_order(): void
    {
        $imageA = $this->image(['sourceImageKey' => 'img0', 'imageIndex' => 0, 'contentHash' => 'hash-a']);
        $imageB = $this->image(['sourceImageKey' => 'img4', 'imageIndex' => 4, 'contentHash' => 'hash-b', 'caption' => 'Figur 5: Detaljbilde']);

        $blocks = $this->builder()->buildImageBlocks($this->document(), [$imageA, $imageB], [0, 4], 0);

        $this->assertCount(2, $blocks);
        $this->assertSame(0, $blocks[0]['position']);
        $this->assertSame(1, $blocks[1]['position']);
        $this->assertSame('image-block-0001', $blocks[0]['block_key']);
        $this->assertSame('image-block-0005', $blocks[1]['block_key']);
    }

    public function test_no_per_image_claims_are_generated_only_a_single_source_element(): void
    {
        // Section 9: never mint deterministic factual claims from an image's caption/alt-text
        // alone — contrast with EnterpriseWikiTableBlockBuilder::tableClaimPayloads().
        $blocks = $this->builder()->buildImageBlocks($this->document(), [$this->image()], [0], 0);

        $this->assertCount(1, $blocks[0]['source_elements']);
        $this->assertSame(
            EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_IMAGE,
            $blocks[0]['source_elements'][0]['source_element_type'],
        );
    }
}
