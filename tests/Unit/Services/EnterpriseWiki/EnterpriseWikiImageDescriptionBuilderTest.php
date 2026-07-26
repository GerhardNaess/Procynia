<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Data\Ai\Requirements\DocxImageData;
use App\Models\EnterpriseWikiDocument;
use App\Services\EnterpriseWiki\EnterpriseWikiImageClassificationService;
use App\Services\EnterpriseWiki\EnterpriseWikiImageDescriptionBuilder;
use Tests\TestCase;

class EnterpriseWikiImageDescriptionBuilderTest extends TestCase
{
    private function document(): EnterpriseWikiDocument
    {
        $document = new EnterpriseWikiDocument(['original_filename' => 'Driftsarkitektur.docx']);
        $document->id = 9;

        return $document;
    }

    private function image(array $overrides = []): DocxImageData
    {
        return new DocxImageData(
            sourceImageKey: $overrides['sourceImageKey'] ?? 'img2',
            imageIndex: $overrides['imageIndex'] ?? 2,
            documentOrder: $overrides['documentOrder'] ?? 5,
            relationshipId: $overrides['relationshipId'] ?? 'rId3',
            originalMediaPath: $overrides['originalMediaPath'] ?? 'word/media/image3.png',
            mimeType: $overrides['mimeType'] ?? 'image/png',
            width: $overrides['width'] ?? 800,
            height: $overrides['height'] ?? 600,
            contentHash: $overrides['contentHash'] ?? 'hash-3',
            sectionNumber: $overrides['sectionNumber'] ?? null,
            sectionTitle: array_key_exists('sectionTitle', $overrides) ? $overrides['sectionTitle'] : 'Integrasjoner',
            caption: array_key_exists('caption', $overrides) ? $overrides['caption'] : 'Oversikt over systemintegrasjonene',
            altText: array_key_exists('altText', $overrides) ? $overrides['altText'] : 'Integrasjonsarkitektur',
            textBefore: array_key_exists('textBefore', $overrides) ? $overrides['textBefore'] : 'Figuren følger et avsnitt om dataflyt mellom CRM og ERP.',
            textAfter: $overrides['textAfter'] ?? null,
            bytes: $overrides['bytes'] ?? 'bytes',
        );
    }

    public function test_it_builds_the_full_textual_description_in_the_specified_format(): void
    {
        $builder = new EnterpriseWikiImageDescriptionBuilder(new EnterpriseWikiImageClassificationService);

        $description = $builder->build($this->image(), EnterpriseWikiImageClassificationService::CATEGORY_DIAGRAM, $this->document());

        $this->assertStringContainsString('Figurtype: diagram', $description);
        $this->assertStringContainsString('Tittel: Integrasjonsarkitektur', $description);
        $this->assertStringContainsString('Bildetekst: Oversikt over systemintegrasjonene', $description);
        $this->assertStringContainsString('Figuren står under seksjonen «Integrasjoner»', $description);
        $this->assertStringContainsString('følger et avsnitt', $description);
        $this->assertStringContainsString('Kilde: Driftsarkitektur.docx → Figur 3', $description);
    }

    public function test_it_omits_missing_lines_rather_than_showing_them_empty(): void
    {
        $builder = new EnterpriseWikiImageDescriptionBuilder(new EnterpriseWikiImageClassificationService);

        $image = $this->image([
            'caption' => null,
            'altText' => null,
            'sectionTitle' => null,
            'textBefore' => null,
        ]);

        $description = $builder->build($image, EnterpriseWikiImageClassificationService::CATEGORY_UNKNOWN, $this->document());

        $this->assertStringNotContainsString('Tittel:', $description);
        $this->assertStringNotContainsString('Bildetekst:', $description);
        $this->assertStringNotContainsString('Kontekst:', $description);
        $this->assertStringContainsString('Figurtype: ukjent figurtype', $description);
        $this->assertStringContainsString('Kilde: Driftsarkitektur.docx → Figur 3', $description);
    }

    public function test_citation_uses_one_based_figure_numbering_from_image_index(): void
    {
        $builder = new EnterpriseWikiImageDescriptionBuilder(new EnterpriseWikiImageClassificationService);

        $this->assertSame(
            'Driftsarkitektur.docx → Figur 1',
            $builder->citation($this->image(['imageIndex' => 0]), $this->document()),
        );
        $this->assertSame(
            'Driftsarkitektur.docx → Figur 3',
            $builder->citation($this->image(['imageIndex' => 2]), $this->document()),
        );
    }

    public function test_it_never_invents_content_beyond_the_documents_own_textual_metadata(): void
    {
        $builder = new EnterpriseWikiImageDescriptionBuilder(new EnterpriseWikiImageClassificationService);

        $image = $this->image([
            'caption' => null,
            'altText' => null,
            'sectionTitle' => null,
            'textBefore' => null,
            'textAfter' => null,
        ]);

        $description = $builder->build($image, EnterpriseWikiImageClassificationService::CATEGORY_UNKNOWN, $this->document());

        // Only the deterministic figure-type label and the source citation remain — nothing
        // about the image's visual content is fabricated.
        $this->assertSame("Figurtype: ukjent figurtype\nKilde: Driftsarkitektur.docx → Figur 3", $description);
    }
}
