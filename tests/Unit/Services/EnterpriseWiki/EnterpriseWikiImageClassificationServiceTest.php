<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Data\Ai\Requirements\DocxImageData;
use App\Services\EnterpriseWiki\EnterpriseWikiImageClassificationService;
use Tests\TestCase;

class EnterpriseWikiImageClassificationServiceTest extends TestCase
{
    private function image(array $overrides = []): DocxImageData
    {
        return new DocxImageData(
            sourceImageKey: $overrides['sourceImageKey'] ?? 'img0',
            imageIndex: $overrides['imageIndex'] ?? 0,
            documentOrder: $overrides['documentOrder'] ?? 0,
            relationshipId: $overrides['relationshipId'] ?? 'rId1',
            originalMediaPath: $overrides['originalMediaPath'] ?? 'word/media/image1.png',
            mimeType: $overrides['mimeType'] ?? 'image/png',
            width: array_key_exists('width', $overrides) ? $overrides['width'] : 800,
            height: array_key_exists('height', $overrides) ? $overrides['height'] : 600,
            contentHash: $overrides['contentHash'] ?? 'hash-1',
            sectionNumber: $overrides['sectionNumber'] ?? null,
            sectionTitle: $overrides['sectionTitle'] ?? null,
            caption: $overrides['caption'] ?? null,
            altText: $overrides['altText'] ?? null,
            textBefore: $overrides['textBefore'] ?? null,
            textAfter: $overrides['textAfter'] ?? null,
            bytes: $overrides['bytes'] ?? 'bytes',
        );
    }

    public function test_it_classifies_a_tiny_image_as_logo_regardless_of_caption(): void
    {
        $service = new EnterpriseWikiImageClassificationService;
        $image = $this->image(['width' => 32, 'height' => 32, 'caption' => 'Figur 1: Viktig diagram']);

        $this->assertSame(EnterpriseWikiImageClassificationService::CATEGORY_LOGO, $service->classify($image, 1));
        $this->assertFalse($service->isShowable($service->classify($image, 1)));
    }

    public function test_it_classifies_a_repeated_image_as_logo(): void
    {
        $service = new EnterpriseWikiImageClassificationService;
        $image = $this->image(['caption' => 'Figur 1: Oversikt']);

        $this->assertSame(EnterpriseWikiImageClassificationService::CATEGORY_LOGO, $service->classify($image, 3));
    }

    public function test_it_classifies_caption_or_alt_text_mentioning_logo_as_logo(): void
    {
        $service = new EnterpriseWikiImageClassificationService;
        $image = $this->image(['altText' => 'Firmalogo']);

        $this->assertSame(EnterpriseWikiImageClassificationService::CATEGORY_LOGO, $service->classify($image, 1));
    }

    public function test_it_classifies_a_small_image_with_no_caption_or_alt_text_as_decorative(): void
    {
        $service = new EnterpriseWikiImageClassificationService;
        $image = $this->image(['width' => 100, 'height' => 100]);

        $this->assertSame(EnterpriseWikiImageClassificationService::CATEGORY_DECORATIVE, $service->classify($image, 1));
        $this->assertFalse($service->isShowable(EnterpriseWikiImageClassificationService::CATEGORY_DECORATIVE));
    }

    public function test_it_classifies_a_large_image_with_no_caption_or_alt_text_as_unknown(): void
    {
        $service = new EnterpriseWikiImageClassificationService;
        $image = $this->image(['width' => 900, 'height' => 700]);

        $this->assertSame(EnterpriseWikiImageClassificationService::CATEGORY_UNKNOWN, $service->classify($image, 1));
        $this->assertTrue($service->isShowable(EnterpriseWikiImageClassificationService::CATEGORY_UNKNOWN));
    }

    /**
     * Regression for a real production document ("Incident Management Illustration.docx", run
     * 475): no caption, no real alt-text (Word's auto-generated "Picture 1" name is already
     * excluded upstream in DocumentTextExtractor), but the paragraph immediately before the image
     * explicitly introduces it. A figure must not be demoted to 'unknown'/'decorative' just
     * because Word's own metadata is empty when the surrounding text already establishes it as
     * informative.
     */
    public function test_it_classifies_an_image_explicitly_introduced_by_preceding_text_as_informative(): void
    {
        $service = new EnterpriseWikiImageClassificationService;
        $image = $this->image([
            'width' => 554,
            'height' => 554,
            'caption' => null,
            'altText' => null,
            'textBefore' => 'Figuren under illustrerer samhandlingsprosessen mellom Kunden og Leverandøren i forbindelse med Incident prosessen.',
        ]);

        $category = $service->classify($image, 1);

        $this->assertSame(EnterpriseWikiImageClassificationService::CATEGORY_INFORMATIVE, $category);
        $this->assertTrue($service->isShowable($category));
    }

    public function test_it_classifies_an_image_explicitly_introduced_by_following_text_as_informative(): void
    {
        $service = new EnterpriseWikiImageClassificationService;
        $image = $this->image([
            'width' => 400,
            'height' => 300,
            'caption' => null,
            'altText' => null,
            'textAfter' => 'Bildet over viser en oversikt over arbeidsflyten.',
        ]);

        $this->assertSame(
            EnterpriseWikiImageClassificationService::CATEGORY_INFORMATIVE,
            $service->classify($image, 1),
        );
    }

    public function test_it_does_not_treat_a_passing_mid_sentence_mention_of_figur_as_an_introduction(): void
    {
        $service = new EnterpriseWikiImageClassificationService;
        $image = $this->image([
            'width' => 900,
            'height' => 700,
            'caption' => null,
            'altText' => null,
            'textBefore' => 'Denne figuren er ikke nevnt andre steder i dokumentet.',
        ]);

        // "Denne figuren er ikke nevnt" does not match the direct subject+verb introduction
        // pattern ("figuren ... illustrerer/viser/beskriver") — stays conservatively 'unknown'.
        $this->assertSame(
            EnterpriseWikiImageClassificationService::CATEGORY_UNKNOWN,
            $service->classify($image, 1),
        );
    }

    public function test_it_classifies_a_screenshot_by_caption_keyword(): void
    {
        $service = new EnterpriseWikiImageClassificationService;
        $image = $this->image(['caption' => 'Figur 2: Skjermbilde av innloggingssiden']);

        $this->assertSame(EnterpriseWikiImageClassificationService::CATEGORY_SCREENSHOT, $service->classify($image, 1));
    }

    public function test_it_classifies_a_diagram_by_alt_text_keyword(): void
    {
        $service = new EnterpriseWikiImageClassificationService;
        $image = $this->image(['altText' => 'Arkitekturdiagram for integrasjoner']);

        $this->assertSame(EnterpriseWikiImageClassificationService::CATEGORY_DIAGRAM, $service->classify($image, 1));
    }

    public function test_it_classifies_a_chart_by_caption_keyword(): void
    {
        $service = new EnterpriseWikiImageClassificationService;
        $image = $this->image(['caption' => 'Figur 4: Graf over omsetning siste kvartal']);

        $this->assertSame(EnterpriseWikiImageClassificationService::CATEGORY_CHART, $service->classify($image, 1));
    }

    public function test_it_classifies_a_captioned_image_with_no_specific_keyword_as_informative(): void
    {
        $service = new EnterpriseWikiImageClassificationService;
        $image = $this->image(['caption' => 'Figur 5: Prosjektorganisasjonen']);

        $category = $service->classify($image, 1);

        $this->assertSame(EnterpriseWikiImageClassificationService::CATEGORY_INFORMATIVE, $category);
        $this->assertTrue($service->isShowable($category));
    }

    public function test_labels_are_returned_in_norwegian(): void
    {
        $service = new EnterpriseWikiImageClassificationService;

        $this->assertSame('skjermbilde', $service->label(EnterpriseWikiImageClassificationService::CATEGORY_SCREENSHOT));
        $this->assertSame('diagram', $service->label(EnterpriseWikiImageClassificationService::CATEGORY_DIAGRAM));
        $this->assertSame('graf', $service->label(EnterpriseWikiImageClassificationService::CATEGORY_CHART));
        $this->assertSame('informativt bilde', $service->label(EnterpriseWikiImageClassificationService::CATEGORY_INFORMATIVE));
        $this->assertSame('ukjent figurtype', $service->label(EnterpriseWikiImageClassificationService::CATEGORY_UNKNOWN));
    }
}
