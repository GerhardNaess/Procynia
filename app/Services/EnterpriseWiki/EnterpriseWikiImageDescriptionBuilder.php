<?php

namespace App\Services\EnterpriseWiki;

use App\Data\Ai\Requirements\DocxImageData;
use App\Models\EnterpriseWikiDocument;
use Illuminate\Support\Str;

/**
 * Builds the Fase 1 "controlled textual description" of a Word image (Section 8 of the image
 * support spec) — alt-text, caption, section heading, and surrounding paragraph text only. Every
 * line is derived from metadata DocumentTextExtractor::extractDocxImages() already captured from
 * the document's own text; nothing here is inferred from the image's pixels. Shared by
 * EnterpriseWikiDocumentSourceElementService (citable source-element text, seen by the page-
 * generation AI before any figure block exists) and EnterpriseWikiImageBlockBuilder (the rendered
 * figure block's caption/citation), so both places describe the same image identically.
 */
class EnterpriseWikiImageDescriptionBuilder
{
    public function __construct(
        private readonly EnterpriseWikiImageClassificationService $classificationService,
    ) {}

    /**
     * Purpose: Render the deterministic multi-line textual description for one image.
     * Inputs: The image, its classification category, and the owning document (for the filename
     * used in the "Kilde" line).
     * Returns: A "Figurtype: ...\nTittel: ...\n..." block — see class docblock. Lines with no
     * available data (e.g. no alt-text) are omitted rather than shown empty.
     * Side effects: None.
     */
    public function build(DocxImageData $image, string $category, EnterpriseWikiDocument $document): string
    {
        $lines = ['Figurtype: '.$this->classificationService->label($category)];

        if ($image->altText !== null && trim($image->altText) !== '') {
            $lines[] = 'Tittel: '.trim($image->altText);
        }

        if ($image->caption !== null && trim($image->caption) !== '') {
            $lines[] = 'Bildetekst: '.trim($image->caption);
        }

        $context = $this->buildContextSentence($image);

        if ($context !== null) {
            $lines[] = 'Kontekst: '.$context;
        }

        $lines[] = 'Kilde: '.$this->citation($image, $document);

        return implode("\n", $lines);
    }

    /**
     * Purpose: Build the "Document.docx → Figur N" precise source citation (Section 7).
     * Inputs: The image and its owning document.
     * Returns: The citation string. Figure numbering is 1-based on the image's document-order
     * extraction ordinal (imageIndex), stable across re-parses of the same document version.
     * Side effects: None.
     */
    public function citation(DocxImageData $image, EnterpriseWikiDocument $document): string
    {
        return sprintf('%s → Figur %d', $document->original_filename, $image->imageIndex + 1);
    }

    private function buildContextSentence(DocxImageData $image): ?string
    {
        $sentences = [];

        if ($image->sectionTitle !== null && trim($image->sectionTitle) !== '') {
            $sentences[] = sprintf('Figuren står under seksjonen «%s»', trim($image->sectionTitle));
        }

        if ($image->textBefore !== null && trim($image->textBefore) !== '') {
            $sentences[] = sprintf('følger et avsnitt: «%s»', Str::limit(trim($image->textBefore), 200));
        }

        if ($image->textAfter !== null && trim($image->textAfter) !== '') {
            $sentences[] = sprintf('etterfølges av: «%s»', Str::limit(trim($image->textAfter), 200));
        }

        if ($sentences === []) {
            return null;
        }

        return implode(', og ', $sentences).'.';
    }
}
