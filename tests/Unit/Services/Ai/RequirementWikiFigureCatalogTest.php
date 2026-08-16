<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Wiki\RequirementWikiFigureCatalog;
use Tests\TestCase;

class RequirementWikiFigureCatalogTest extends TestCase
{
    /**
     * The exact block shape EnterpriseWikiImageBlockBuilder writes — caption/citation Markdown,
     * identity split across source_id and image_data.source_image_key, no bytes anywhere.
     *
     * @return array<string, mixed>
     */
    private function imageBlock(array $overrides = []): array
    {
        return array_merge([
            'block_key' => 'image-block-0004',
            'block_type' => 'image',
            'position' => 6,
            'source_id' => 63,
            'markdown' => "**Figur 4**\n_Kilde: Masterdata Samhandling.docx → Figur 4_",
            'page_reference' => 'Masterdata Samhandling.docx → Figur 4',
            'image_data' => [
                'source_image_key' => 'img3',
                'figure_number' => 4,
                'caption' => null,
                'alt_text' => '',
                'description' => 'Figurtype: informativt bilde. Viser samhandlingsmodellen.',
                'mime_type' => 'image/png',
            ],
        ], $overrides);
    }

    public function test_a_figure_is_built_from_a_real_wiki_image_block(): void
    {
        $figures = (new RequirementWikiFigureCatalog)->fromContentBlocks([
            ['block_type' => 'paragraph', 'markdown' => 'Vanlig avsnitt.'],
            $this->imageBlock(),
        ]);

        $this->assertCount(1, $figures);
        $this->assertSame('fig:63:img3', $figures[0]['figure_ref']);
        $this->assertSame(63, $figures[0]['document_id']);
        $this->assertSame('img3', $figures[0]['source_image_key']);
        $this->assertSame(4, $figures[0]['figure_number']);
        $this->assertSame('Masterdata Samhandling.docx → Figur 4', $figures[0]['page_reference']);
        $this->assertSame('Figurtype: informativt bilde. Viser samhandlingsmodellen.', $figures[0]['description']);
    }

    public function test_no_image_bytes_or_urls_ever_enter_the_figure_metadata(): void
    {
        $block = $this->imageBlock();
        $block['image_data']['bytes'] = base64_encode('not-a-real-image');
        $block['image_data']['storage_path'] = 'wiki/documents/63/img3.png';

        $figures = (new RequirementWikiFigureCatalog)->fromContentBlocks([$block]);
        $encoded = json_encode($figures);

        $this->assertStringNotContainsString('bytes', $encoded);
        $this->assertStringNotContainsString('storage_path', $encoded);
        $this->assertStringNotContainsString(base64_encode('not-a-real-image'), $encoded);
        $this->assertStringNotContainsString('http', $encoded);
    }

    public function test_a_block_missing_half_of_its_identity_is_not_a_usable_figure(): void
    {
        $catalog = new RequirementWikiFigureCatalog;

        $withoutDocument = $this->imageBlock(['source_id' => 0]);
        $withoutKey = $this->imageBlock();
        $withoutKey['image_data']['source_image_key'] = '';

        $this->assertSame([], $catalog->fromContentBlocks([$withoutDocument]));
        $this->assertSame([], $catalog->fromContentBlocks([$withoutKey]));
        $this->assertSame([], $catalog->fromContentBlocks([['block_type' => 'image', 'source_id' => 63]]));
    }

    public function test_only_figures_inside_the_content_actually_read_are_offered(): void
    {
        $catalog = new RequirementWikiFigureCatalog;
        $readFigure = $this->imageBlock();
        $skippedFigure = $this->imageBlock([
            'source_id' => 63,
            'markdown' => "**Figur 9**\n_Kilde: Masterdata Samhandling.docx → Figur 9_",
            'image_data' => ['source_image_key' => 'img8', 'figure_number' => 9, 'caption' => null, 'alt_text' => '', 'description' => null],
        ]);

        $figures = $catalog->fromContentBlocks([$readFigure, $skippedFigure]);
        $readContent = "## Samhandling\n\nTekst.\n\n**Figur 4**\n_Kilde: Masterdata Samhandling.docx → Figur 4_";

        $readable = $catalog->readable($figures, $readContent);

        $this->assertCount(1, $readable);
        $this->assertSame('fig:63:img3', $readable[0]['figure_ref']);
    }

    public function test_the_ref_is_the_document_and_image_key_pair(): void
    {
        $this->assertSame('fig:63:img3', RequirementWikiFigureCatalog::ref(63, 'img3'));
        $this->assertNotSame(
            RequirementWikiFigureCatalog::ref(63, 'img3'),
            RequirementWikiFigureCatalog::ref(64, 'img3'),
        );
    }
}
