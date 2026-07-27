<?php

namespace App\Services;

use App\Data\Ai\Requirements\DocxHeadingData;
use App\Data\Ai\Requirements\DocxImageData;
use App\Data\Ai\Requirements\DocxListItemData;
use App\Data\Ai\Requirements\DocxTableCellData;
use App\Data\Ai\Requirements\DocxTableData;
use App\Data\Ai\Requirements\DocxTableRowData;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

class DocumentTextExtractor
{
    /**
     * Purpose: Extract raw text from a supported document file.
     * Inputs: Absolute filesystem path to the stored document.
     * Returns: Extracted text, or an empty string when parsing fails or no text is available.
     * Side effects: Reads the file from disk and may open ZIP archives in memory.
     */
    public function extractText(string $path): string
    {
        try {
            if (! is_file($path) || ! is_readable($path)) {
                return '';
            }

            $extension = Str::lower((string) pathinfo($path, PATHINFO_EXTENSION));

            return match ($extension) {
                'docx' => $this->extractDocxText($path),
                'xlsx' => $this->extractXlsxText($path),
                'pdf' => $this->extractPdfText($path),
                default => '',
            };
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Purpose: Extract structured text blocks from a supported document file.
     * Inputs: Absolute filesystem path to the stored document.
     * Returns: An ordered list of text blocks with style and level metadata.
     * Side effects: Reads the file from disk and may open ZIP archives in memory.
     *
     * @return array<int, array{text: string, style: ?string, level: ?int}>
     */
    public function extractStructuredText(string $path): array
    {
        try {
            if (! is_file($path) || ! is_readable($path)) {
                return [];
            }

            $extension = Str::lower((string) pathinfo($path, PATHINFO_EXTENSION));

            return match ($extension) {
                'docx' => $this->extractStructuredDocxText($path),
                'pdf' => $this->extractStructuredPdfText($path),
                'xlsx' => $this->extractStructuredFallbackText($path),
                default => [],
            };
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Purpose: Extract text from a DOCX file by reading word/document.xml.
     * Inputs: Absolute filesystem path to a DOCX file.
     * Returns: Plain text content, or an empty string when extraction fails.
     * Side effects: Opens the ZIP archive and parses XML.
     */
    private function extractDocxText(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (! is_string($xml) || trim($xml) === '') {
            return '';
        }

        return $this->extractWordXmlText($xml);
    }

    /**
     * Purpose: Extract DOCX text alongside a deterministic, generic model of the document's
     * canonical source elements — tables (with rows/cells), headings, and list items — each
     * carrying a stable source key, document order, reconstructed Word auto-numbering (any
     * numFmt: decimal, lower/upperLetter, lower/upperRoman, decimalZero, ordinal — resolved
     * generically from the document's own numbering.xml/styles.xml, never hardcoded to any
     * specific numId, style name, or language), and char_start/char_end within the same flat
     * text extractText()/extractDocxText() would produce. This is the server-authoritative
     * source of provenance for requirement extraction — never the AI's restatement of it.
     * Inputs: Absolute filesystem path to a DOCX file.
     * Returns: text, tables, headings, list_items, text_elements — see above. `text_elements` is
     * the flat, stably-keyed, requirement-provenance-ready subset of body-level elements that can
     * themselves be a requirement source — plain paragraphs and list items (headings are section
     * context, not requirement text, so they are not included here) — each carrying element_key,
     * element_type ('paragraph'|'list_item'), text, number (list items only), section_number/
     * section_title, and char_start/char_end. See RequirementCandidateExtractor::
     * reconcileCandidatesWithTextElements().
     * Side effects: Opens the ZIP archive and parses XML. Used only by the requirement
     * extraction upload path (AiController::storeDocuments()) — extractText()/extractStructuredText()
     * and their existing callers (Enterprise Wiki, Knowledge Base ingest) are untouched.
     *
     * @return array{text: string, tables: list<DocxTableData>, headings: list<DocxHeadingData>, list_items: list<DocxListItemData>, text_elements: list<array<string, mixed>>}
     */
    public function extractDocxTextAndTables(string $path): array
    {
        $empty = ['text' => '', 'tables' => [], 'headings' => [], 'list_items' => [], 'text_elements' => []];

        if (! is_file($path) || ! is_readable($path)) {
            return $empty;
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return $empty;
        }

        $xml = $zip->getFromName('word/document.xml');
        $stylesXml = $zip->getFromName('word/styles.xml');
        $numberingXml = $zip->getFromName('word/numbering.xml');
        $zip->close();

        if (! is_string($xml) || trim($xml) === '') {
            return $empty;
        }

        return $this->parseWordXmlTextAndTables(
            $xml,
            is_string($stylesXml) ? $stylesXml : null,
            is_string($numberingXml) ? $numberingXml : null,
        );
    }

    /**
     * @return array{text: string, tables: list<DocxTableData>, headings: list<DocxHeadingData>, list_items: list<DocxListItemData>, text_elements: list<array<string, mixed>>}
     */
    private function parseWordXmlTextAndTables(string $xml, ?string $stylesXml = null, ?string $numberingXml = null): array
    {
        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return ['text' => '', 'tables' => [], 'headings' => [], 'list_items' => [], 'text_elements' => []];
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $styleMap = $this->extractDocxStyleMap($stylesXml);
            $styleNumberingMap = $this->extractDocxStyleNumberingMap($stylesXml);
            $numberingDefs = $this->extractDocxNumberingDefinitions($numberingXml);
            /** @var array<string, array<int, int>> $numberingCounters numId => [ilvl => current value] */
            $numberingCounters = [];

            $paragraphs = [];
            /** @var array<int, int> $tableOrderByObjectId spl_object_id(w:tbl) => table order index */
            $tableOrderByObjectId = [];
            // spl_object_id() is only unique while the object is alive — docxTableCellAncestry()
            // creates transient parentNode wrapper objects on every call, and once nothing else
            // references an earlier <w:tbl>/<w:tr> wrapper, PHP can garbage-collect it and reuse
            // its id for a LATER, entirely different table/row. Without holding these arrays, a
            // document with more than a handful of tables silently collapsed dozens of distinct
            // tables down to a handful of merged, cross-contaminated ones (confirmed empirically:
            // 50 real <w:tbl> elements collapsed to 6 apparent ones). Keeping a strong reference
            // here is the fix — it is the entire purpose of these two arrays.
            $tableObjectKeepAlive = [];
            /** @var array<int, array<int, int>> $rowOrderByTable tableIndex => [spl_object_id(w:tr) => row order index] */
            $rowOrderByTable = [];
            $rowObjectKeepAlive = [];
            /** @var array<int, array<int, array<int, list<string>>>> $tableCellParagraphs tableIndex => rowIndex => colIndex => paragraph texts */
            $tableCellParagraphs = [];
            /** @var array<string, array{0: int, 1: int}> $rowParagraphIndexRange "tableIndex:rowIndex" => [minParagraphIndex, maxParagraphIndex] */
            $rowParagraphIndexRange = [];
            /** @var array<int, array{text: ?string, number: ?string}> $tableSectionHeading tableIndex => nearest preceding heading */
            $tableSectionHeading = [];
            $currentSectionHeadingText = null;
            $currentSectionHeadingNumber = null;
            /** @var list<DocxHeadingData> $headings canonical heading source elements, in document order */
            $headings = [];
            /** @var list<DocxListItemData> $listItems canonical list-item source elements, in document order */
            $listItems = [];
            /** @var list<array<string, mixed>> $textElements flat, normalized paragraph + list-item requirement-source elements, in document order */
            $textElements = [];
            $headingOrdinal = 0;
            $listItemOrdinal = 0;
            $paragraphOrdinal = 0;

            foreach ($xpath->query('//w:p') as $paragraph) {
                $textNodes = $xpath->query('.//w:t', $paragraph);
                $parts = [];

                if ($textNodes !== false) {
                    foreach ($textNodes as $textNode) {
                        $parts[] = (string) $textNode->textContent;
                    }
                }

                $paragraphText = $this->normalizeWhitespace(implode(' ', $parts));

                $styleId = null;
                $styleNode = $xpath->query('.//w:pPr/w:pStyle', $paragraph);

                if ($styleNode !== false && $styleNode->length > 0) {
                    $styleId = (string) $styleNode->item(0)?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')?->nodeValue;
                }

                $resolvedStyle = $styleMap[$styleId] ?? $styleId;

                // Word auto-numbering (e.g. a "Req. No." table cell styled as a heading level
                // with no literal text at all — the number only exists via numPr + numbering.xml,
                // never as a <w:t> value) is resolved for every paragraph up front, in document
                // order, so the shared per-list counters stay correct regardless of whether the
                // paragraph turns out to be a heading, a table cell, or neither.
                $numberingRef = $this->resolveDocxParagraphNumbering($paragraph, $xpath, $styleId, $styleNumberingMap);
                $renderedNumber = $numberingRef !== null
                    ? $this->renderDocxNumberingValue($numberingRef, $numberingDefs, $numberingCounters)
                    : null;

                // Only substitutes when the paragraph's own text is empty — a heading always has
                // real title text, so this only ever fires for auto-numbered-but-textless cells.
                if ($paragraphText === '' && $renderedNumber !== null) {
                    $paragraphText = $renderedNumber;
                }

                // Table ancestry/column registration happens BEFORE the blank-text skip below,
                // so a genuinely blank cell (e.g. the empty "Response instruction"/"Y/N"/
                // "Detailed response" columns in a requirement table) still gets a column entry
                // with an empty value, rather than silently disappearing from the row's cells —
                // every <w:tc> contains at least one <w:p> per the OOXML spec, blank or not.
                $cellAncestry = $this->docxTableCellAncestry($paragraph);

                if ($cellAncestry !== null) {
                    [$tableCell, $tableRow, $table] = $cellAncestry;

                    $tableObjectId = spl_object_id($table);

                    if (! array_key_exists($tableObjectId, $tableOrderByObjectId)) {
                        $tableOrderByObjectId[$tableObjectId] = count($tableOrderByObjectId);
                        $tableObjectKeepAlive[] = $table;
                    }

                    $tableIndex = $tableOrderByObjectId[$tableObjectId];

                    if (! array_key_exists($tableIndex, $tableSectionHeading)) {
                        $tableSectionHeading[$tableIndex] = [
                            'text' => $currentSectionHeadingText,
                            'number' => $currentSectionHeadingNumber,
                        ];
                    }

                    $rowOrderByTable[$tableIndex] ??= [];
                    $rowObjectKeepAlive[$tableIndex] ??= [];
                    $rowObjectId = spl_object_id($tableRow);

                    if (! array_key_exists($rowObjectId, $rowOrderByTable[$tableIndex])) {
                        $rowOrderByTable[$tableIndex][$rowObjectId] = count($rowOrderByTable[$tableIndex]);
                        $rowObjectKeepAlive[$tableIndex][] = $tableRow;
                    }

                    $rowIndex = $rowOrderByTable[$tableIndex][$rowObjectId];
                    $columnIndex = $this->docxTableCellColumnIndex($tableCell, $tableRow);
                    $tableCellParagraphs[$tableIndex][$rowIndex][$columnIndex] ??= [];

                    if ($paragraphText !== '') {
                        $tableCellParagraphs[$tableIndex][$rowIndex][$columnIndex][] = $paragraphText;

                        $paragraphIndex = count($paragraphs);
                        $rangeKey = $tableIndex.':'.$rowIndex;

                        if (! isset($rowParagraphIndexRange[$rangeKey])) {
                            $rowParagraphIndexRange[$rangeKey] = [$paragraphIndex, $paragraphIndex];
                        } else {
                            $rowParagraphIndexRange[$rangeKey][1] = $paragraphIndex;
                        }
                    }
                } elseif ($paragraphText !== '') {
                    // Heading tracking only considers body-level paragraphs (not inside a table
                    // cell) — a styled heading inside a table cell is not a document section.
                    $level = $this->resolveDocxHeadingLevel($styleId, $resolvedStyle);
                    $paragraphIndex = count($paragraphs);

                    if ($level !== null) {
                        $currentSectionHeadingText = $paragraphText;
                        $currentSectionHeadingNumber = $renderedNumber;

                        $headings[] = new DocxHeadingData(
                            sourceKey: sprintf('heading-%d', $headingOrdinal),
                            documentOrder: $paragraphIndex,
                            level: $level,
                            text: $paragraphText,
                            number: $renderedNumber,
                            charStart: 0,
                            charEnd: 0,
                        );
                        $headingOrdinal++;
                    } elseif ($numberingRef !== null) {
                        // Auto-numbered body paragraph that isn't a recognized document heading —
                        // a list item (e.g. a lettered/numbered clause inside body text).
                        $listItemKey = sprintf('listitem-%d', $listItemOrdinal);

                        $listItems[] = new DocxListItemData(
                            sourceKey: $listItemKey,
                            documentOrder: $paragraphIndex,
                            ilvl: $numberingRef['ilvl'],
                            text: $paragraphText,
                            number: $renderedNumber,
                            charStart: 0,
                            charEnd: 0,
                            sectionNumber: $currentSectionHeadingNumber,
                            sectionTitle: $currentSectionHeadingText,
                        );
                        $textElements[] = [
                            'element_key' => $listItemKey,
                            'element_type' => 'list_item',
                            'document_order' => $paragraphIndex,
                            'text' => $paragraphText,
                            'number' => $renderedNumber,
                            'section_number' => $currentSectionHeadingNumber,
                            'section_title' => $currentSectionHeadingText,
                            'char_start' => 0,
                            'char_end' => 0,
                        ];
                        $listItemOrdinal++;
                    } else {
                        // A plain body paragraph — no heading style, no Word auto-numbering. Still
                        // a valid requirement source (running-text requirements), just without a
                        // reconstructed number of its own.
                        $paragraphKey = sprintf('paragraph-%d', $paragraphOrdinal);

                        $textElements[] = [
                            'element_key' => $paragraphKey,
                            'element_type' => 'paragraph',
                            'document_order' => $paragraphIndex,
                            'text' => $paragraphText,
                            'number' => null,
                            'section_number' => $currentSectionHeadingNumber,
                            'section_title' => $currentSectionHeadingText,
                            'char_start' => 0,
                            'char_end' => 0,
                        ];
                        $paragraphOrdinal++;
                    }
                }

                if ($paragraphText === '') {
                    continue;
                }

                $paragraphs[] = $paragraphText;
            }

            $joinedText = $this->normalizeBlockText(implode("\n\n", $paragraphs));
            $paragraphOffsets = $this->locateParagraphOffsets($paragraphs, $joinedText);
            $tables = $this->buildDocxTablesFromParagraphs($tableCellParagraphs, $rowParagraphIndexRange, $paragraphOffsets, $tableSectionHeading);
            $headings = $this->stampDocxElementCharOffsets($headings, $paragraphOffsets);
            $listItems = $this->stampDocxElementCharOffsets($listItems, $paragraphOffsets);
            $textElements = $this->stampDocxTextElementCharOffsets($textElements, $paragraphOffsets);

            return ['text' => $joinedText, 'tables' => $tables, 'headings' => $headings, 'list_items' => $listItems, 'text_elements' => $textElements];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
    }

    /**
     * Purpose: Extract PNG/JPEG images placed in a DOCX file's ordinary body-level content flow
     * (Fase 1 Enterprise Wiki image support — see DocxImageData's class docblock). Each image is
     * linked to its Word relationship ID, document position, nearest section heading, and the
     * paragraph text immediately before/after it.
     * Inputs: Absolute filesystem path to a DOCX file.
     * Returns: Images in document order. Images inside table cells (Fase 1 explicitly excludes
     * these — see docxTableCellAncestry()), images whose relationship cannot be resolved, and
     * non-PNG/JPEG images (including SVG) are silently omitted rather than surfaced as broken
     * entries — a single corrupt/unsupported image must never break extraction of the rest of the
     * document (Section 12). Images inside header/footer OOXML parts are never seen at all, since
     * this method only ever reads word/document.xml.
     * Side effects: Opens the ZIP archive and parses XML independently of
     * extractDocxTextAndTables()/parseWordXmlTextAndTables() — neither of those methods, nor any
     * of their existing callers, is touched by this method.
     *
     * @return list<DocxImageData>
     */
    public function extractDocxImages(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return [];
        }

        $xml = $zip->getFromName('word/document.xml');
        $relationshipsXml = $zip->getFromName('word/_rels/document.xml.rels');
        $stylesXml = $zip->getFromName('word/styles.xml');
        $mediaFiles = $this->extractDocxImageMediaFiles($zip);
        $zip->close();

        if (! is_string($xml) || trim($xml) === '') {
            return [];
        }

        $relationshipMap = $this->extractDocxImageRelationshipMap(
            is_string($relationshipsXml) ? $relationshipsXml : null,
        );

        return $this->parseWordXmlImages(
            $xml,
            $relationshipMap,
            $mediaFiles,
            is_string($stylesXml) ? $stylesXml : null,
        );
    }

    /**
     * Purpose: Read every file under word/media/ in the DOCX ZIP archive into memory.
     * Inputs: An already-open ZipArchive.
     * Returns: word/media/... path => raw file bytes.
     * Side effects: Reads entries from the ZIP archive.
     *
     * @return array<string, string>
     */
    private function extractDocxImageMediaFiles(ZipArchive $zip): array
    {
        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (! is_string($name) || ! str_starts_with($name, 'word/media/')) {
                continue;
            }

            $bytes = $zip->getFromIndex($i);

            if (is_string($bytes)) {
                $files[$name] = $bytes;
            }
        }

        return $files;
    }

    /**
     * Purpose: Parse word/_rels/document.xml.rels into a relationship-ID => media-path map,
     * keeping only relationships whose Type identifies them as images.
     * Inputs: Raw relationships XML content, if present.
     * Returns: Relationship Id => normalized "word/media/..." path.
     * Side effects: Parses XML in memory.
     *
     * @return array<string, string>
     */
    private function extractDocxImageRelationshipMap(?string $relationshipsXml): array
    {
        if (! is_string($relationshipsXml) || trim($relationshipsXml) === '') {
            return [];
        }

        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($relationshipsXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return [];
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

            $map = [];
            $relationshipNodes = $xpath->query('//r:Relationship');

            if ($relationshipNodes === false) {
                return [];
            }

            foreach ($relationshipNodes as $relationshipNode) {
                if (! $relationshipNode instanceof DOMElement) {
                    continue;
                }

                $type = (string) $relationshipNode->attributes?->getNamedItem('Type')?->nodeValue;

                if (! str_contains($type, '/image')) {
                    continue;
                }

                $id = (string) $relationshipNode->attributes?->getNamedItem('Id')?->nodeValue;
                $target = (string) $relationshipNode->attributes?->getNamedItem('Target')?->nodeValue;

                if ($id === '' || $target === '') {
                    continue;
                }

                $map[$id] = $this->normalizeDocxImageMediaPath($target);
            }

            return $map;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
    }

    /**
     * Purpose: Normalize a relationship Target attribute (e.g. "media/image1.png") into the
     * "word/media/image1.png" path used as a ZIP entry name/media-files map key.
     * Inputs: The raw Target attribute value.
     * Returns: A path rooted at "word/".
     * Side effects: None.
     */
    private function normalizeDocxImageMediaPath(string $target): string
    {
        $target = ltrim($target, '/');
        $target = preg_replace('#^(\.\./)+#', '', $target) ?? $target;

        if (! str_starts_with($target, 'word/')) {
            $target = 'word/'.$target;
        }

        return $target;
    }

    /**
     * Purpose: Walk word/document.xml once, collecting an ordered list of body-level text and
     * image entries, then assemble those into DocxImageData (Section 3: document order/context).
     * Inputs: Raw document.xml content, the relationship-ID => media-path map, the media-path =>
     * bytes map, and optional raw styles.xml content (for heading/caption style-name detection).
     * Returns: Images in document order.
     * Side effects: Parses XML in memory.
     *
     * @param  array<string, string>  $relationshipMap
     * @param  array<string, string>  $mediaFiles
     * @return list<DocxImageData>
     */
    private function parseWordXmlImages(string $xml, array $relationshipMap, array $mediaFiles, ?string $stylesXml): array
    {
        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return [];
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $xpath->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');
            $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

            $styleMap = $this->extractDocxStyleMap($stylesXml);

            /** @var list<array<string, mixed>> $entries ordered {kind: 'text'|'image', ...} entries */
            $entries = [];
            $currentSectionNumber = null;
            $currentSectionTitle = null;

            $paragraphNodes = $xpath->query('//w:p');

            if ($paragraphNodes === false) {
                return [];
            }

            foreach ($paragraphNodes as $paragraph) {
                if (! $paragraph instanceof DOMElement) {
                    continue;
                }

                if ($this->docxTableCellAncestry($paragraph) !== null) {
                    // Fase 1: images inside table cells are out of scope (see class docblock).
                    continue;
                }

                $textNodes = $xpath->query('.//w:t', $paragraph);
                $parts = [];

                if ($textNodes !== false) {
                    foreach ($textNodes as $textNode) {
                        $parts[] = (string) $textNode->textContent;
                    }
                }

                $paragraphText = $this->normalizeWhitespace(implode(' ', $parts));

                $styleId = null;
                $styleNode = $xpath->query('.//w:pPr/w:pStyle', $paragraph);

                if ($styleNode !== false && $styleNode->length > 0) {
                    $styleId = (string) $styleNode->item(0)?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')?->nodeValue;
                }

                $resolvedStyleName = $styleMap[$styleId] ?? $styleId;

                if ($paragraphText !== '' && $this->resolveDocxHeadingLevel($styleId, $resolvedStyleName) !== null) {
                    // Simplified section tracking for image context: the literal leading number
                    // in the heading text (if any) is used directly — Word auto-numbering
                    // reconstruction (numbering.xml) is intentionally not replicated here, since
                    // exact section numbers matter less for image context than for tables.
                    [$currentSectionNumber, $currentSectionTitle] = $this->splitDocxSectionHeading($paragraphText);
                }

                $images = $this->collectDocxParagraphImages($paragraph, $xpath, $relationshipMap, $mediaFiles);

                if ($images === []) {
                    if ($paragraphText !== '') {
                        $entries[] = ['kind' => 'text', 'text' => $paragraphText, 'style_name' => $resolvedStyleName];
                    }

                    continue;
                }

                foreach ($images as $image) {
                    $entries[] = [
                        'kind' => 'image',
                        'text' => $paragraphText,
                        'style_name' => $resolvedStyleName,
                        'image' => $image,
                        'section_number' => $currentSectionNumber,
                        'section_title' => $currentSectionTitle,
                    ];
                }
            }

            return $this->assembleDocxImages($entries);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
    }

    /**
     * Purpose: Resolve every image drawn in a single body paragraph into raw image data.
     * Inputs: The paragraph element, the bound DOMXPath, the relationship map, and the media-file
     * bytes map.
     * Returns: Zero or more raw image entries (relationship_id, media_path, mime_type, width,
     * height, content_hash, alt_text, bytes) — unresolvable relationships, missing media bytes,
     * and non-PNG/JPEG formats are silently skipped (Section 12).
     * Side effects: None.
     *
     * @param  array<string, string>  $relationshipMap
     * @param  array<string, string>  $mediaFiles
     * @return list<array<string, mixed>>
     */
    private function collectDocxParagraphImages(DOMElement $paragraph, DOMXPath $xpath, array $relationshipMap, array $mediaFiles): array
    {
        $blipNodes = $xpath->query('.//w:drawing//a:blip', $paragraph);

        if ($blipNodes === false || $blipNodes->length === 0) {
            return [];
        }

        $images = [];

        foreach ($blipNodes as $blipNode) {
            if (! $blipNode instanceof DOMElement) {
                continue;
            }

            $relationshipId = (string) $blipNode->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed')?->nodeValue;

            if ($relationshipId === '') {
                $relationshipId = (string) $blipNode->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'link')?->nodeValue;
            }

            if ($relationshipId === '' || ! isset($relationshipMap[$relationshipId])) {
                continue;
            }

            $mediaPath = $relationshipMap[$relationshipId];
            $bytes = $mediaFiles[$mediaPath] ?? null;

            if (! is_string($bytes) || $bytes === '') {
                continue;
            }

            $extension = Str::lower((string) pathinfo($mediaPath, PATHINFO_EXTENSION));

            if (! in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
                // Fase 1 scope: PNG/JPEG only. Other formats (incl. SVG) are not extracted at
                // all here, per Section 5's "reject or mark unsupported".
                continue;
            }

            [$width, $height] = $this->docxImageDimensions($bytes, $blipNode, $xpath);

            $images[] = [
                'relationship_id' => $relationshipId,
                'media_path' => $mediaPath,
                'mime_type' => $extension === 'png' ? 'image/png' : 'image/jpeg',
                'width' => $width,
                'height' => $height,
                'content_hash' => hash('sha256', $bytes),
                'alt_text' => $this->docxImageAltText($blipNode, $xpath),
                'bytes' => $bytes,
            ];
        }

        return $images;
    }

    /**
     * Purpose: Resolve an image's alt-text via Word's own descr -> title fallback chain.
     * Inputs: The <a:blip> element and the bound DOMXPath.
     * Returns: The first non-blank of wp:docPr's descr/title attributes that isn't just Word's
     * auto-generated shape name, or null.
     * Side effects: None.
     *
     * `wp:docPr`'s `name` attribute is deliberately EXCLUDED from this chain — unlike `descr`
     * (the modern Alt Text field) and `title` (the older Office alt-text field), `name` is never
     * human-authored: Word assigns it automatically ("Picture 1", "Picture 2", ...) to every
     * inline image regardless of whether anyone ever opens the Alt Text pane. Treating `name` as
     * real alt-text produced meaningless placeholder text (e.g. "Alt-tekst: Picture 1") that
     * described nothing about the image — a real bug found via a genuine production document
     * (INCLUDEPICTURE-pasted image, no formal alt-text/caption, Word name "Picture 1"). The same
     * auto-generated-looking pattern is filtered out of `descr`/`title` too, in the rarer case a
     * paste operation populates one of those with an equally generic placeholder.
     */
    private function docxImageAltText(DOMElement $blipNode, DOMXPath $xpath): ?string
    {
        $docPrNodes = $xpath->query('ancestor::wp:inline/wp:docPr | ancestor::wp:anchor/wp:docPr', $blipNode);

        if ($docPrNodes === false || $docPrNodes->length === 0) {
            return null;
        }

        $docPr = $docPrNodes->item(0);

        if (! $docPr instanceof DOMElement) {
            return null;
        }

        foreach (['descr', 'title'] as $attribute) {
            $value = trim((string) $docPr->attributes?->getNamedItem($attribute)?->nodeValue);

            if ($value !== '' && ! $this->isAutoGeneratedImageName($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Purpose: Detect Word's auto-generated shape-name pattern ("Picture 1", "Image 2", ...) so it
     * is never mistaken for real, human-authored alt-text.
     * Inputs: A candidate alt-text/title value.
     * Returns: Whether the value looks like an auto-generated placeholder rather than a real
     * description.
     * Side effects: None.
     */
    private function isAutoGeneratedImageName(string $value): bool
    {
        return preg_match('/^(picture|image|graphic|illustration|bilde|figur)\s*\d*$/iu', trim($value)) === 1;
    }

    /**
     * Purpose: Determine an image's pixel dimensions, preferring the actual decoded image over
     * Word's own declared display size.
     * Inputs: The raw image bytes, the <a:blip> element, and the bound DOMXPath.
     * Returns: [width, height] in pixels, or [null, null] when neither source yields a value.
     * Side effects: None.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function docxImageDimensions(string $bytes, DOMElement $blipNode, DOMXPath $xpath): array
    {
        $sizeInfo = @getimagesizefromstring($bytes);

        if (is_array($sizeInfo) && isset($sizeInfo[0], $sizeInfo[1])) {
            return [(int) $sizeInfo[0], (int) $sizeInfo[1]];
        }

        $extentNodes = $xpath->query('ancestor::wp:inline/wp:extent | ancestor::wp:anchor/wp:extent', $blipNode);

        if ($extentNodes !== false && $extentNodes->length > 0) {
            $extent = $extentNodes->item(0);

            if ($extent instanceof DOMElement) {
                $cx = $extent->attributes?->getNamedItem('cx')?->nodeValue;
                $cy = $extent->attributes?->getNamedItem('cy')?->nodeValue;

                if (is_numeric($cx) && is_numeric($cy)) {
                    // EMUs (English Metric Units) -> pixels: 914400 EMU per inch, 96 px per inch.
                    return [(int) round(((float) $cx) / 9525), (int) round(((float) $cy) / 9525)];
                }
            }
        }

        return [null, null];
    }

    /**
     * Purpose: Turn the ordered text/image entry list collected by parseWordXmlImages() into
     * DocxImageData, resolving each image's nearest preceding/following text and adopting a
     * caption (Section 3/7/8: document order and context).
     * Inputs: Ordered entries, each either {kind: 'text', text, style_name} or {kind: 'image',
     * text, style_name, image, section_number, section_title}.
     * Returns: Images in document order, with a placeholder sourceImageKey ("img{n}") — see
     * DocxImageData::manyWithDocumentId() for the final, document-scoped key.
     * Side effects: None.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<DocxImageData>
     */
    private function assembleDocxImages(array $entries): array
    {
        $images = [];
        $imageIndex = 0;

        foreach ($entries as $position => $entry) {
            if ($entry['kind'] !== 'image') {
                continue;
            }

            $image = $entry['image'];
            $textBefore = $this->nearestDocxTextEntry($entries, $position, -1);
            $textAfter = $this->nearestDocxTextEntry($entries, $position, 1);

            $ownText = trim((string) ($entry['text'] ?? ''));
            $caption = null;

            if ($ownText !== '' && $this->isDocxFigureCaptionText($ownText, $entry['style_name'] ?? null)) {
                $caption = $ownText;
            } elseif ($textAfter !== null && $this->isDocxFigureCaptionText($textAfter, null)) {
                $caption = $textAfter;
            }

            $images[] = new DocxImageData(
                sourceImageKey: sprintf('img%d', $imageIndex),
                imageIndex: $imageIndex,
                documentOrder: (int) $position,
                relationshipId: $image['relationship_id'],
                originalMediaPath: $image['media_path'],
                mimeType: $image['mime_type'],
                width: $image['width'],
                height: $image['height'],
                contentHash: $image['content_hash'],
                sectionNumber: $entry['section_number'] ?? null,
                sectionTitle: $entry['section_title'] ?? null,
                caption: $caption,
                altText: $image['alt_text'],
                textBefore: $textBefore,
                textAfter: $textAfter,
                bytes: $image['bytes'],
            );

            $imageIndex++;
        }

        return $images;
    }

    /**
     * Purpose: Find the nearest plain-text entry preceding/following an image entry, skipping
     * over any other image entries in between.
     * Inputs: The full ordered entry list, the image's own position, and a direction (-1 = before,
     * 1 = after).
     * Returns: The nearest text entry's text, or null if none exists in that direction.
     * Side effects: None.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function nearestDocxTextEntry(array $entries, int $fromPosition, int $direction): ?string
    {
        $position = $fromPosition + $direction;

        while (isset($entries[$position])) {
            if ($entries[$position]['kind'] === 'text') {
                $text = trim((string) $entries[$position]['text']);

                return $text !== '' ? $text : null;
            }

            $position += $direction;
        }

        return null;
    }

    /**
     * Purpose: Heuristically decide whether a piece of text is a Word figure/image caption —
     * either its paragraph style name suggests a caption, or the text itself starts with a
     * figure/image-like prefix (Norwegian and English). Deliberately conservative: a plain
     * paragraph that merely mentions "figur" mid-sentence is not treated as a caption.
     * Inputs: The candidate text and an optional resolved paragraph style name.
     * Returns: Whether the text looks like a figure caption.
     * Side effects: None.
     */
    private function isDocxFigureCaptionText(string $text, ?string $styleName): bool
    {
        if ($styleName !== null && preg_match('/caption|figure|figur|illustrasjon|bilde|diagram/iu', $styleName) === 1) {
            return true;
        }

        $normalizedText = mb_strtolower(trim($text), 'UTF-8');

        foreach (['figur ', 'figure ', 'bilde ', 'image ', 'illustrasjon ', 'diagram '] as $prefix) {
            if (str_starts_with($normalizedText, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Purpose: Find the nearest enclosing table cell/row/table for a <w:p> paragraph node, if any.
     * Inputs: The paragraph DOM element.
     * Returns: [w:tc, w:tr, w:tbl] ancestor elements, or null if the paragraph is not inside a table.
     * Side effects: None.
     *
     * @return array{0: DOMElement, 1: DOMElement, 2: DOMElement}|null
     */
    private function docxTableCellAncestry(DOMElement $paragraph): ?array
    {
        $cell = null;
        $row = null;
        $node = $paragraph->parentNode;

        while ($node instanceof DOMElement) {
            if ($cell === null && $node->localName === 'tc') {
                $cell = $node;
            } elseif ($cell !== null && $row === null && $node->localName === 'tr') {
                $row = $node;
            } elseif ($row !== null && $node->localName === 'tbl') {
                return [$cell, $row, $node];
            }

            $node = $node->parentNode;
        }

        return null;
    }

    /**
     * Purpose: Determine a table cell's column position among its row's direct <w:tc> siblings.
     * Inputs: The cell element and its parent row element.
     * Returns: Zero-based column index. Cells merged via w:gridSpan/w:vMerge are not expanded —
     * each physical <w:tc> is counted once, a known simplification for merged-cell layouts.
     * Side effects: None.
     */
    private function docxTableCellColumnIndex(DOMElement $tableCell, DOMElement $tableRow): int
    {
        $columnIndex = 0;

        foreach ($tableRow->childNodes as $child) {
            if ($child === $tableCell) {
                break;
            }

            if ($child instanceof DOMElement && $child->localName === 'tc') {
                $columnIndex++;
            }
        }

        return $columnIndex;
    }

    /**
     * Purpose: Locate each paragraph's character offset within the final joined/normalized text.
     * Inputs: Ordered paragraph texts and the final joined text they were built from.
     * Returns: Per-paragraph-index [start, end] character offsets.
     * Side effects: None.
     *
     * @param  list<string>  $paragraphs
     * @return array<int, array{start: int, end: int}>
     */
    private function locateParagraphOffsets(array $paragraphs, string $joinedText): array
    {
        $offsets = [];
        $cursor = 0;

        foreach ($paragraphs as $index => $paragraphText) {
            $position = mb_strpos($joinedText, $paragraphText, $cursor, 'UTF-8');

            if ($position === false) {
                // Should not happen (every paragraph is an exact substring of the text it was
                // joined into), but fail safe rather than throw on an unexpected edge case.
                $offsets[$index] = ['start' => $cursor, 'end' => $cursor];

                continue;
            }

            $end = $position + mb_strlen($paragraphText, 'UTF-8');
            $offsets[$index] = ['start' => $position, 'end' => $end];
            $cursor = $end;
        }

        return $offsets;
    }

    /**
     * Purpose: Stamp final char_start/char_end onto canonical heading/list-item source elements
     * once every paragraph's offset within the joined text is known — offsets can't be computed
     * while paragraphs are still being collected, so elements are built with placeholder [0, 0]
     * and back-filled here from their recorded documentOrder (paragraph index).
     * Inputs: The elements (each exposing documentOrder and withCharOffsets()) and the full
     * paragraph-index => [start, end] offset map.
     * Returns: The same elements, in the same order, with real char offsets.
     * Side effects: None.
     *
     * @template T of DocxHeadingData|DocxListItemData
     *
     * @param  list<T>  $elements
     * @param  array<int, array{start: int, end: int}>  $paragraphOffsets
     * @return list<T>
     */
    private function stampDocxElementCharOffsets(array $elements, array $paragraphOffsets): array
    {
        return array_map(
            static function (DocxHeadingData|DocxListItemData $element) use ($paragraphOffsets): DocxHeadingData|DocxListItemData {
                $offset = $paragraphOffsets[$element->documentOrder] ?? ['start' => 0, 'end' => 0];

                return $element->withCharOffsets($offset['start'], $offset['end']);
            },
            $elements,
        );
    }

    /**
     * Purpose: Stamp final char_start/char_end onto the flat, plain-array text_elements list (see
     * extractDocxTextAndTables()) — the array counterpart of stampDocxElementCharOffsets() for
     * elements that are built as plain arrays rather than DTOs.
     * Inputs: The elements (each carrying document_order) and the paragraph-index => [start, end]
     * offset map.
     * Returns: The same elements, in the same order, with real char offsets.
     * Side effects: None.
     *
     * @param  list<array<string, mixed>>  $elements
     * @param  array<int, array{start: int, end: int}>  $paragraphOffsets
     * @return list<array<string, mixed>>
     */
    private function stampDocxTextElementCharOffsets(array $elements, array $paragraphOffsets): array
    {
        return array_map(
            static function (array $element) use ($paragraphOffsets): array {
                $offset = $paragraphOffsets[$element['document_order']] ?? ['start' => 0, 'end' => 0];
                $element['char_start'] = $offset['start'];
                $element['char_end'] = $offset['end'];

                return $element;
            },
            $elements,
        );
    }

    /**
     * Purpose: Build DocxTableData objects from the raw per-cell paragraph texts collected while
     * walking the document. The first (lowest-index) row of each table is treated as the header
     * row; its cell text becomes each data cell's original_header/normalized_column_key.
     * Inputs: tableIndex => rowIndex => colIndex => paragraph texts; row paragraph-index ranges;
     * paragraph character offsets.
     * Returns: One DocxTableData per parsed table, in document order.
     * Side effects: None.
     *
     * @param  array<int, array<int, array<int, list<string>>>>  $tableCellParagraphs
     * @param  array<string, array{0: int, 1: int}>  $rowParagraphIndexRange
     * @param  array<int, array{start: int, end: int}>  $paragraphOffsets
     * @param  array<int, array{text: ?string, number: ?string}>  $tableSectionHeading  tableIndex => nearest preceding heading
     * @return list<DocxTableData>
     */
    private function buildDocxTablesFromParagraphs(
        array $tableCellParagraphs,
        array $rowParagraphIndexRange,
        array $paragraphOffsets,
        array $tableSectionHeading = [],
    ): array {
        $tables = [];

        ksort($tableCellParagraphs);

        foreach ($tableCellParagraphs as $tableIndex => $rows) {
            ksort($rows);
            $rowIndexes = array_keys($rows);

            if ($rowIndexes === []) {
                continue;
            }

            $headerRowIndex = min($rowIndexes);
            $headerCells = $rows[$headerRowIndex];
            ksort($headerCells);

            $headerLabels = [];

            foreach ($headerCells as $columnIndex => $cellParagraphTexts) {
                $headerLabels[$columnIndex] = trim(implode(' ', $cellParagraphTexts));
            }

            $sectionHeading = $tableSectionHeading[$tableIndex] ?? ['text' => null, 'number' => null];
            [$fallbackSectionNumber, $sectionTitle] = $this->splitDocxSectionHeading($sectionHeading['text'] ?? null);
            // The resolved Word auto-number (from numbering.xml) is authoritative when available;
            // the regex-derived number is only a fallback for documents that type section numbers
            // literally instead of relying on Word's own numbering.
            $sectionNumber = $sectionHeading['number'] ?? $fallbackSectionNumber;

            $dataRows = [];
            $dataRowOrdinal = 0;

            foreach ($rows as $rowIndex => $cells) {
                if ($rowIndex === $headerRowIndex) {
                    continue;
                }

                ksort($cells);

                $rangeKey = $tableIndex.':'.$rowIndex;
                [$minParagraphIndex, $maxParagraphIndex] = $rowParagraphIndexRange[$rangeKey] ?? [0, 0];
                $charStart = $paragraphOffsets[$minParagraphIndex]['start'] ?? 0;
                $charEnd = $paragraphOffsets[$maxParagraphIndex]['end'] ?? $charStart;

                $dataCells = [];
                // Reset per row, not per table — this only disambiguates multiple blank/duplicate
                // headers within the SAME row. Declaring it once for the whole table was a bug:
                // it made every row after the first pick up an incrementing suffix (req_no_2,
                // req_no_3, ...) on its normalized_column_key, silently breaking any lookup by
                // column key (e.g. identifierCellValue()/typeCellValue()) for every row but the
                // first in each table.
                $usedColumnKeys = [];

                foreach ($cells as $columnIndex => $cellParagraphTexts) {
                    $originalHeader = $headerLabels[$columnIndex] ?? null;
                    $normalizedColumnKey = $this->uniqueDocxColumnKey(
                        $originalHeader !== null && $originalHeader !== '' ? $originalHeader : ('column_'.$columnIndex),
                        $usedColumnKeys,
                    );

                    $dataCells[] = new DocxTableCellData(
                        columnIndex: $columnIndex,
                        originalHeader: $originalHeader !== '' ? $originalHeader : null,
                        normalizedColumnKey: $normalizedColumnKey,
                        value: trim(implode(' ', $cellParagraphTexts)),
                    );
                }

                // Placeholder key ("tbl{n}-row{n}") — DocxTableData::withDocumentId() stamps the
                // final, document-scoped source_row_key once the document's DB id is known.
                $dataRows[] = new DocxTableRowData(
                    sourceRowKey: sprintf('tbl%d-row%d', $tableIndex, $dataRowOrdinal),
                    tableIndex: $tableIndex,
                    rowIndex: $dataRowOrdinal,
                    charStart: $charStart,
                    charEnd: $charEnd,
                    cells: $dataCells,
                    sectionNumber: $sectionNumber,
                    sectionTitle: $sectionTitle,
                );

                $dataRowOrdinal++;
            }

            if ($dataRows === []) {
                continue;
            }

            $tables[] = new DocxTableData(
                tableIndex: $tableIndex,
                headerLabels: array_values($headerLabels),
                rows: $dataRows,
                sectionNumber: $sectionNumber,
                sectionTitle: $sectionTitle,
            );
        }

        return $tables;
    }

    /**
     * Purpose: Split a DOCX heading paragraph's text into a leading numbering prefix and the
     * remaining title (e.g. "2.1 Buying responsibility, not activities" -> ["2.1", "Buying
     * responsibility, not activities"]). Falls back to a null number with the full text as the
     * title when no leading numbering pattern is present.
     * Inputs: The raw heading paragraph text, or null when no heading precedes the table.
     * Returns: [sectionNumber, sectionTitle], both null when $headingText is null/blank.
     * Side effects: None.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitDocxSectionHeading(?string $headingText): array
    {
        if ($headingText === null || trim($headingText) === '') {
            return [null, null];
        }

        $headingText = trim($headingText);

        if (preg_match('/^(\d+(?:\.\d+)*)\.?\s+(.+)$/u', $headingText, $matches) === 1) {
            return [$matches[1], trim($matches[2])];
        }

        return [null, $headingText];
    }

    /**
     * Purpose: Derive a machine-friendly, unique-within-table column key from a raw header label.
     * Inputs: The raw header text and the set of keys already used in this table.
     * Returns: A lowercase, ASCII, underscore-separated key; disambiguated with a numeric suffix
     * if the same key was already produced for an earlier column (e.g. duplicate/blank headers).
     * Side effects: Adds the returned key to $usedColumnKeys (passed by reference).
     */
    private function uniqueDocxColumnKey(string $header, array &$usedColumnKeys): string
    {
        $key = mb_strtolower(trim($header), 'UTF-8');
        $key = strtr($key, [
            'æ' => 'ae', 'ø' => 'o', 'å' => 'aa',
            'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'ü' => 'u', 'ö' => 'o', 'ä' => 'a',
        ]);
        $key = preg_replace('/[^a-z0-9]+/u', '_', $key) ?? '';
        $key = trim($key, '_');

        if ($key === '') {
            $key = 'column';
        }

        $baseKey = $key;
        $suffix = 2;

        while (isset($usedColumnKeys[$key])) {
            $key = $baseKey.'_'.$suffix;
            $suffix++;
        }

        $usedColumnKeys[$key] = true;

        return $key;
    }

    /**
     * Purpose: Extract structured paragraphs from a DOCX file.
     * Inputs: Absolute filesystem path to a DOCX file.
     * Returns: Ordered blocks containing text, style, and heading level metadata.
     * Side effects: Opens the ZIP archive and parses XML.
     *
     * @return array<int, array{text: string, style: ?string, level: ?int}>
     */
    private function extractStructuredDocxText(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return [];
        }

        $documentXml = $zip->getFromName('word/document.xml');
        $stylesXml = $zip->getFromName('word/styles.xml');
        $zip->close();

        if (! is_string($documentXml) || trim($documentXml) === '') {
            return [];
        }

        return $this->extractDocxStructuredBlocks($documentXml, is_string($stylesXml) ? $stylesXml : null);
    }

    /**
     * Purpose: Extract a single structured fallback block from a non-DOCX document.
     * Inputs: Absolute filesystem path to the document.
     * Returns: A single block with the extracted plain text, or an empty array when no text exists.
     * Side effects: Reuses the existing plain-text extractor.
     *
     * @return array<int, array{text: string, style: ?string, level: ?int}>
     */
    private function extractStructuredFallbackText(string $path): array
    {
        $text = trim($this->extractText($path));

        if ($text === '') {
            return [];
        }

        return [
            [
                'text' => $text,
                'style' => null,
                'level' => null,
            ],
        ];
    }

    /**
     * @return array<int, array{text: string, style: ?string, level: ?int}>
     */
    private function extractStructuredPdfText(string $path): array
    {
        $blocks = $this->extractStructuredPdfBlocksViaPoppler($path);

        if ($blocks !== []) {
            return $blocks;
        }

        return $this->extractStructuredPdfTextFallback($path);
    }

    /**
     * Purpose: Extract structured PDF blocks using Poppler XML output with text, table, and image metadata.
     * Inputs: Absolute filesystem path to a PDF file.
     * Returns: Ordered blocks with page, table, and graphic metadata when Poppler extraction succeeds.
     * Side effects: Runs Poppler commands and reads temporary files from disk.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractStructuredPdfBlocksViaPoppler(string $path): array
    {
        $workingDirectory = $this->createTemporaryPdfWorkingDirectory();

        if ($workingDirectory === null) {
            return [];
        }

        try {
            $result = $this->runPopplerPdfToHtmlXml($path, $workingDirectory);

            if ($result === null) {
                return [];
            }

            return $this->parsePopplerPdfXml($result['xml'], $workingDirectory, $path);
        } finally {
            $this->removeTemporaryDirectory($workingDirectory);
        }
    }

    /**
     * Purpose: Extract structured PDF blocks with the existing pdftotext line heuristic as a fallback.
     * Inputs: Absolute filesystem path to a PDF file.
     * Returns: Ordered blocks containing text and optional heading levels.
     * Side effects: Runs pdftotext and parses the output text in memory.
     *
     * @return array<int, array{text: string, style: ?string, level: ?int}>
     */
    private function extractStructuredPdfTextFallback(string $path): array
    {
        $text = trim($this->extractPdfText($path));

        if ($text === '') {
            return [];
        }

        $blocks = [];
        $paragraphLines = [];

        foreach (explode("\n", $text) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                if ($paragraphLines !== []) {
                    $blocks[] = ['text' => implode(' ', $paragraphLines), 'style' => null, 'level' => null];
                    $paragraphLines = [];
                }

                continue;
            }

            $level = $this->detectPdfHeadingLevel($trimmed);

            if ($level !== null) {
                if ($paragraphLines !== []) {
                    $blocks[] = ['text' => implode(' ', $paragraphLines), 'style' => null, 'level' => null];
                    $paragraphLines = [];
                }

                $blocks[] = ['text' => $trimmed, 'style' => null, 'level' => $level];
            } else {
                $paragraphLines[] = $trimmed;
            }
        }

        if ($paragraphLines !== []) {
            $blocks[] = ['text' => implode(' ', $paragraphLines), 'style' => null, 'level' => null];
        }

        return $blocks !== [] ? $blocks : [['text' => $text, 'style' => null, 'level' => null]];
    }

    /**
     * Purpose: Create a temporary working directory for Poppler PDF extraction.
     * Inputs: None.
     * Returns: The working directory path or null when the directory cannot be created.
     * Side effects: Creates a temporary directory on disk.
     */
    private function createTemporaryPdfWorkingDirectory(): ?string
    {
        $baseDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $workingDirectory = $baseDirectory.DIRECTORY_SEPARATOR.'procynia-pdf-'.Str::ulid();

        if (@mkdir($workingDirectory, 0775, true)) {
            return $workingDirectory;
        }

        return null;
    }

    /**
     * Purpose: Run pdftohtml and read the generated XML payload from the temporary directory.
     * Inputs: The source PDF path and a writable working directory.
     * Returns: The generated XML string and metadata when successful, otherwise null.
     * Side effects: Runs Poppler and writes extracted image files into the working directory.
     *
     * @return array{xml: string, xml_path: string}|null
     */
    private function runPopplerPdfToHtmlXml(string $path, string $workingDirectory): ?array
    {
        $binary = $this->resolvePopplerBinary('services.pdftohtml.binary', 'pdftohtml');
        $outputBase = 'document';
        $command = [
            (string) $binary,
            '-xml',
            '-enc',
            'UTF-8',
            $path,
            $outputBase,
        ];

        $result = Process::path($workingDirectory)->run($command);

        if (! $result->successful()) {
            Log::warning('[Procynia][PDF] pdftohtml XML extraction failed.', [
                'path' => $path,
                'working_directory' => $workingDirectory,
                'exit_code' => $result->exitCode(),
                'error_output' => trim((string) $result->errorOutput()),
            ]);

            return null;
        }

        $xmlPath = $workingDirectory.DIRECTORY_SEPARATOR.$outputBase.'.xml';

        if (! is_file($xmlPath) || ! is_readable($xmlPath)) {
            Log::warning('[Procynia][PDF] pdftohtml did not create an XML payload.', [
                'path' => $path,
                'xml_path' => $xmlPath,
            ]);

            return null;
        }

        $xml = file_get_contents($xmlPath);

        if (! is_string($xml) || trim($xml) === '') {
            return null;
        }

        return [
            'xml' => $xml,
            'xml_path' => $xmlPath,
        ];
    }

    /**
     * Purpose: Parse Poppler XML into ordered PDF blocks with text, table, and graphic metadata.
     * Inputs: The XML payload, the Poppler working directory, and the source PDF path.
     * Returns: Ordered structured blocks suitable for downstream chunking.
     * Side effects: Reads extracted image files from the working directory.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parsePopplerPdfXml(string $xml, string $workingDirectory, string $path): array
    {
        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return [];
            }

            $xpath = new DOMXPath($dom);
            $blocks = [];
            $tableIndexInDocument = 0;
            $graphicIndexInDocument = 0;

            foreach ($xpath->query('//page') as $pageNode) {
                if (! $pageNode instanceof DOMElement) {
                    continue;
                }

                $pageWidth = max(1, (int) ($pageNode->attributes?->getNamedItem('width')?->nodeValue ?? 0));
                $pageHeight = max(1, (int) ($pageNode->attributes?->getNamedItem('height')?->nodeValue ?? 0));

                $pageBlocks = $this->extractPopplerPdfPageBlocks(
                    $xpath,
                    $pageNode,
                    $workingDirectory,
                    $path,
                    $pageWidth,
                    $pageHeight,
                    $tableIndexInDocument,
                    $graphicIndexInDocument,
                );

                if ($pageBlocks !== []) {
                    $blocks = array_merge($blocks, $pageBlocks);
                }
            }

            usort($blocks, static function (array $left, array $right): int {
                $pageComparison = ((int) ($left['page_number'] ?? 0)) <=> ((int) ($right['page_number'] ?? 0));

                if ($pageComparison !== 0) {
                    return $pageComparison;
                }

                $topComparison = ((int) ($left['top'] ?? 0)) <=> ((int) ($right['top'] ?? 0));

                if ($topComparison !== 0) {
                    return $topComparison;
                }

                $leftComparison = ((int) ($left['left'] ?? 0)) <=> ((int) ($right['left'] ?? 0));

                if ($leftComparison !== 0) {
                    return $leftComparison;
                }

                return ((int) ($left['order_index'] ?? 0)) <=> ((int) ($right['order_index'] ?? 0));
            });

            $blocks = $this->suppressPdfRunningHeaderFooterBlocks($blocks);

            return $this->mergePopplerPdfTableBlocksAcrossPages($blocks);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
    }

    /**
     * Purpose: Parse one Poppler page into ordered text, table, and graphic blocks.
     * Inputs: The XML helper, the page node, the working directory, the source PDF path, and running block counters.
     * Returns: Ordered blocks for a single page.
     * Side effects: Reads extracted image files from the working directory.
     *
     * @param  int  $tableIndexInDocument  Zero-based table index that is incremented as tables are discovered.
     * @param  int  $graphicIndexInDocument  Zero-based graphic index that is incremented as graphics are discovered.
     * @return array<int, array<string, mixed>>
     */
    private function extractPopplerPdfPageBlocks(
        DOMXPath $xpath,
        DOMElement $pageNode,
        string $workingDirectory,
        string $path,
        int $pageWidth,
        int $pageHeight,
        int &$tableIndexInDocument,
        int &$graphicIndexInDocument,
    ): array {
        $pageNumber = max(1, (int) ($pageNode->attributes?->getNamedItem('number')?->nodeValue ?? 0));
        $pageWidth = max(1, $pageWidth);
        $pageHeight = max(1, $pageHeight);
        $textItems = $this->extractPopplerPdfTextItems($xpath, $pageNode, $pageNumber);
        $lineGroups = $this->groupPopplerPdfTextItemsIntoLines($textItems);
        $imageItems = $this->extractPopplerPdfImageItems($xpath, $pageNode, $workingDirectory, $pageNumber, $pageWidth, $pageHeight);
        $consumedLineIndexes = [];
        $blocks = [];

        foreach ($this->detectPopplerPdfTableRuns($lineGroups, $pageWidth) as $tableRun) {
            $nextTableIndexInDocument = $tableIndexInDocument + 1;
            $tableBlock = $this->buildPopplerPdfTableBlock(
                $lineGroups,
                $tableRun,
                $pageNumber,
                $nextTableIndexInDocument,
                $pageWidth,
                $pageHeight,
            );

            if ($tableBlock === null) {
                continue;
            }

            $tableIndexInDocument = $nextTableIndexInDocument;

            foreach (range($tableRun['start_index'], $tableRun['end_index']) as $lineIndex) {
                $consumedLineIndexes[$lineIndex] = true;
            }

            if (isset($tableRun['title_index'])) {
                $consumedLineIndexes[(int) $tableRun['title_index']] = true;
            }

            $blocks[] = $tableBlock;
        }

        foreach ($imageItems as $imageItem) {
            $graphicIndexInDocument++;
            $imageBlock = $this->buildPopplerPdfImageBlock(
                $lineGroups,
                $imageItem,
                $workingDirectory,
                $pageNumber,
                $graphicIndexInDocument,
                $consumedLineIndexes,
                $path,
            );

            if ($imageBlock !== null) {
                $blocks[] = $imageBlock;
            }
        }

        foreach ($this->buildPopplerPdfTextBlocks($lineGroups, $consumedLineIndexes, $pageNumber) as $textBlock) {
            $textBlock['page_width'] = $pageWidth;
            $textBlock['page_height'] = $pageHeight;
            $blocks[] = $textBlock;
        }

        return $blocks;
    }

    /**
     * Purpose: Read text items from one Poppler PDF page.
     * Inputs: The XML helper, the page node, and the page number.
     * Returns: Ordered text fragments with coordinates.
     * Side effects: None.
     *
     * @return array<int, array{text: string, top: int, left: int, width: int, height: int, bottom: int, page_number: int}>
     */
    private function extractPopplerPdfTextItems(DOMXPath $xpath, DOMElement $pageNode, int $pageNumber): array
    {
        $items = [];

        foreach ($xpath->query('./text', $pageNode) as $textNode) {
            if (! $textNode instanceof DOMElement) {
                continue;
            }

            $text = $this->normalizeLineText((string) $textNode->textContent);

            if ($text === '') {
                continue;
            }

            $top = (int) ($textNode->attributes?->getNamedItem('top')?->nodeValue ?? 0);
            $left = (int) ($textNode->attributes?->getNamedItem('left')?->nodeValue ?? 0);
            $width = max(0, (int) ($textNode->attributes?->getNamedItem('width')?->nodeValue ?? 0));
            $height = max(0, (int) ($textNode->attributes?->getNamedItem('height')?->nodeValue ?? 0));

            $items[] = [
                'text' => $text,
                'raw_text' => (string) $textNode->textContent,
                'top' => $top,
                'left' => $left,
                'width' => $width,
                'height' => $height,
                'bottom' => $top + max(1, $height),
                'page_number' => $pageNumber,
            ];
        }

        usort($items, static function (array $left, array $right): int {
            $topComparison = ((int) ($left['top'] ?? 0)) <=> ((int) ($right['top'] ?? 0));

            if ($topComparison !== 0) {
                return $topComparison;
            }

            return ((int) ($left['left'] ?? 0)) <=> ((int) ($right['left'] ?? 0));
        });

        return $items;
    }

    /**
     * Purpose: Read image items from one Poppler PDF page.
     * Inputs: The XML helper, the page node, the Poppler working directory, and the page number.
     * Returns: Ordered image descriptors with resolved bytes when available.
     * Side effects: Reads image files extracted by Poppler.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractPopplerPdfImageItems(DOMXPath $xpath, DOMElement $pageNode, string $workingDirectory, int $pageNumber, int $pageWidth, int $pageHeight): array
    {
        $items = [];

        foreach ($xpath->query('./image', $pageNode) as $imageNode) {
            if (! $imageNode instanceof DOMElement) {
                continue;
            }

            $top = (int) ($imageNode->attributes?->getNamedItem('top')?->nodeValue ?? 0);
            $left = (int) ($imageNode->attributes?->getNamedItem('left')?->nodeValue ?? 0);
            $width = max(0, (int) ($imageNode->attributes?->getNamedItem('width')?->nodeValue ?? 0));
            $height = max(0, (int) ($imageNode->attributes?->getNamedItem('height')?->nodeValue ?? 0));
            $src = trim((string) ($imageNode->attributes?->getNamedItem('src')?->nodeValue ?? ''));
            $resolvedPath = $this->resolvePopplerImagePath($workingDirectory, $src);
            $imageBytes = $resolvedPath !== null && is_file($resolvedPath) && is_readable($resolvedPath)
                ? file_get_contents($resolvedPath)
                : false;

            $items[] = [
                'top' => $top,
                'left' => $left,
                'width' => $width,
                'height' => $height,
                'page_number' => $pageNumber,
                'page_width' => $pageWidth,
                'page_height' => $pageHeight,
                'src' => $src !== '' ? $src : null,
                'resolved_path' => $resolvedPath,
                'image_bytes' => is_string($imageBytes) && $imageBytes !== '' ? $imageBytes : null,
            ];
        }

        usort($items, static function (array $left, array $right): int {
            $topComparison = ((int) ($left['top'] ?? 0)) <=> ((int) ($right['top'] ?? 0));

            if ($topComparison !== 0) {
                return $topComparison;
            }

            return ((int) ($left['left'] ?? 0)) <=> ((int) ($right['left'] ?? 0));
        });

        return $items;
    }

    /**
     * Purpose: Group Poppler text items into visual lines using their top coordinates.
     * Inputs: Ordered text fragments with page coordinates.
     * Returns: Ordered line groups with combined line text and geometry.
     * Side effects: None.
     *
     * @param  array<int, array{text: string, top: int, left: int, width: int, height: int, bottom: int, page_number: int}>  $textItems
     * @return array<int, array<string, mixed>>
     */
    private function groupPopplerPdfTextItemsIntoLines(array $textItems): array
    {
        if ($textItems === []) {
            return [];
        }

        $lines = [];
        $currentLine = null;
        $lineTolerance = 4;

        foreach ($textItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            if ($currentLine === null) {
                $currentLine = [
                    'items' => [$item],
                    'top' => (int) ($item['top'] ?? 0),
                    'left' => (int) ($item['left'] ?? 0),
                    'right' => (int) ($item['left'] ?? 0) + (int) ($item['width'] ?? 0),
                    'bottom' => (int) ($item['bottom'] ?? 0),
                    'page_number' => (int) ($item['page_number'] ?? 0),
                ];

                continue;
            }

            $currentTop = (int) ($currentLine['top'] ?? 0);
            $itemTop = (int) ($item['top'] ?? 0);

            if (abs($itemTop - $currentTop) <= $lineTolerance) {
                $currentLine['items'][] = $item;
                $currentLine['left'] = min((int) $currentLine['left'], (int) ($item['left'] ?? 0));
                $currentLine['right'] = max((int) $currentLine['right'], (int) ($item['left'] ?? 0) + (int) ($item['width'] ?? 0));
                $currentLine['bottom'] = max((int) $currentLine['bottom'], (int) ($item['bottom'] ?? 0));

                continue;
            }

            $lines[] = $this->finalizePopplerPdfLine($currentLine);

            $currentLine = [
                'items' => [$item],
                'top' => (int) ($item['top'] ?? 0),
                'left' => (int) ($item['left'] ?? 0),
                'right' => (int) ($item['left'] ?? 0) + (int) ($item['width'] ?? 0),
                'bottom' => (int) ($item['bottom'] ?? 0),
                'page_number' => (int) ($item['page_number'] ?? 0),
            ];
        }

        if ($currentLine !== null) {
            $lines[] = $this->finalizePopplerPdfLine($currentLine);
        }

        return $lines;
    }

    /**
     * Purpose: Normalize one Poppler line group after coordinates have been merged.
     * Inputs: A mutable line group with text items and geometry.
     * Returns: The same line group with normalized line text.
     * Side effects: None.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function finalizePopplerPdfLine(array $line): array
    {
        $items = array_values(array_filter((array) ($line['items'] ?? []), static fn ($item): bool => is_array($item)));
        usort($items, static fn (array $left, array $right): int => ((int) ($left['left'] ?? 0)) <=> ((int) ($right['left'] ?? 0)));

        $textParts = [];
        $rawTextParts = [];

        foreach ($items as $item) {
            $text = $this->normalizeLineText((string) ($item['text'] ?? ''));
            $rawText = (string) ($item['raw_text'] ?? $item['text'] ?? '');

            if ($text !== '') {
                $textParts[] = $text;
            }

            if (trim($rawText) !== '') {
                $rawTextParts[] = $rawText;
            }
        }

        $line['items'] = $items;
        $line['text'] = $this->normalizeLineText(implode(' ', $textParts));
        $line['raw_text'] = trim(implode(' ', $rawTextParts));
        $line['left'] = (int) ($line['left'] ?? 0);
        $line['right'] = (int) ($line['right'] ?? 0);
        $line['top'] = (int) ($line['top'] ?? 0);
        $line['bottom'] = (int) ($line['bottom'] ?? 0);
        $line['page_number'] = (int) ($line['page_number'] ?? 0);

        return $line;
    }

    /**
     * Purpose: Detect Poppler line runs that look like tables.
     * Inputs: Ordered line groups and the page width.
     * Returns: Ordered table run descriptors with title and row boundaries.
     * Side effects: None.
     *
     * @param  array<int, array<string, mixed>>  $lineGroups
     * @return array<int, array{start_index: int, end_index: int, title_index?: int}>
     */
    private function detectPopplerPdfTableRuns(array $lineGroups, int $pageWidth): array
    {
        $runs = [];
        $lineCount = count($lineGroups);
        $index = 0;

        while ($index < $lineCount) {
            $line = $lineGroups[$index];

            if (! $this->isPopplerPdfTableCandidateLine($line, $pageWidth)) {
                $index++;

                continue;
            }

            $template = $this->popplerPdfTableColumnTemplate($line);
            $endIndex = $index;
            $previousLine = $line;

            for ($candidateIndex = $index + 1; $candidateIndex < $lineCount; $candidateIndex++) {
                $candidateLine = $lineGroups[$candidateIndex];

                if (! $this->isPopplerPdfTableCandidateLine($candidateLine, $pageWidth)) {
                    if ($this->isPopplerPdfTableContinuationLine($candidateLine, $previousLine, $template, $pageWidth)) {
                        $endIndex = $candidateIndex;
                        $previousLine = $candidateLine;

                        continue;
                    }

                    break;
                }

                if (! $this->matchesPopplerPdfTableColumns($candidateLine, $template)) {
                    break;
                }

                $endIndex = $candidateIndex;
                $previousLine = $candidateLine;
                $updatedTemplate = $this->popplerPdfTableColumnTemplate($candidateLine);

                if ($updatedTemplate !== null) {
                    $template = $updatedTemplate;
                }
            }

            if ($endIndex > $index) {
                $titleIndex = null;

                if ($index > 0) {
                    $possibleTitle = $lineGroups[$index - 1];

                    if ($this->isPopplerPdfTableTitleCandidate($possibleTitle, $lineGroups[$index], $pageWidth)) {
                        $titleIndex = $index - 1;
                    }
                }

                $runs[] = array_filter([
                    'start_index' => $index,
                    'end_index' => $endIndex,
                    'title_index' => $titleIndex,
                ], static fn ($value): bool => $value !== null);

                $index = $endIndex + 1;

                continue;
            }

            $index++;
        }

        return $runs;
    }

    /**
     * Purpose: Determine whether one Poppler line group looks like a table row.
     * Inputs: One line group and the page width.
     * Returns: True when the line contains two or more aligned cells.
     * Side effects: None.
     *
     * @param  array<string, mixed>  $line
     */
    private function isPopplerPdfTableCandidateLine(array $line, int $pageWidth): bool
    {
        $lineText = trim((string) ($line['raw_text'] ?? $line['text'] ?? ''));

        if ($lineText === '' || $this->detectPdfHeadingLevel($lineText) !== null) {
            return false;
        }

        if ($this->isPopplerPdfTocEntryLine($line)) {
            return false;
        }

        $template = $this->popplerPdfTableColumnTemplate($line);

        if ($template === null) {
            return false;
        }

        $lineWidth = max(0, (int) ($line['right'] ?? 0) - (int) ($line['left'] ?? 0));

        if ($pageWidth > 0 && $lineWidth > (int) round($pageWidth * 0.92)) {
            return false;
        }

        return true;
    }

    /**
     * Purpose: Detect table-of-contents style lines so they can be excluded before table detection.
     * Inputs: One Poppler line group with text and item metadata.
     * Returns: True when the line looks like a TOC entry with a numbered prefix, leader dots or long spacing, and a trailing page marker.
     * Side effects: None.
     *
     * @param  array<string, mixed>  $line
     */
    private function isPopplerPdfTocEntryLine(array $line): bool
    {
        $lineText = $this->normalizeLineText((string) ($line['text'] ?? $line['raw_text'] ?? ''));

        if ($lineText === '' || mb_strlen($lineText, 'UTF-8') > 180) {
            return false;
        }

        if (preg_match('/^\s*(?:\d+(?:\.\d+)*|bilag\s+\d+(?:-\d+)?|vedlegg\s+[A-Z0-9]+)\b/iu', $lineText) !== 1) {
            return false;
        }

        if (preg_match('/(?:\.{4,}|\s{6,})\s*(?:\d{1,3}|[ivxlcdm]{1,5})\s*$/iu', $lineText) !== 1) {
            return false;
        }

        return true;
    }

    /**
     * Purpose: Create the column template for a Poppler table candidate line.
     * Inputs: One table candidate line.
     * Returns: Normalized column left positions in reading order.
     * Side effects: None.
     *
     * @param  array<string, mixed>  $line
     * @return array<int, int>
     */
    private function popplerPdfTableColumnTemplate(array $line): ?array
    {
        $lineText = trim((string) ($line['raw_text'] ?? $line['text'] ?? ''));

        $cells = $this->normalizePopplerPdfTableCells($line);

        if (count($cells) < 2) {
            return null;
        }

        if (count($cells) === 2 && $this->isPopplerPdfListText($lineText)) {
            return null;
        }

        return [
            'mode' => array_values(array_filter((array) ($line['items'] ?? []), static fn ($item): bool => is_array($item))) !== [] ? 'items' : 'text',
            'cells' => $cells,
            'cell_count' => count($cells),
            'line_left' => (int) ($line['left'] ?? 0),
            'line_right' => (int) ($line['right'] ?? 0),
        ];
    }

    /**
     * Purpose: Check whether one Poppler line matches a detected table column template.
     * Inputs: One candidate line and the column template.
     * Returns: True when the cell positions stay aligned with the template.
     * Side effects: None.
     *
     * @param  array<string, mixed>  $line
     * @param  array<int, int>  $template
     */
    private function matchesPopplerPdfTableColumns(array $line, array $template): bool
    {
        $candidate = $this->popplerPdfTableColumnTemplate($line);

        if ($candidate === null || $template === []) {
            return false;
        }

        if ((int) ($candidate['cell_count'] ?? 0) !== (int) ($template['cell_count'] ?? 0)) {
            return false;
        }

        if (($candidate['mode'] ?? '') === 'items' && ($template['mode'] ?? '') === 'items') {
            $candidateCells = array_values((array) ($candidate['cells'] ?? []));
            $templateCells = array_values((array) ($template['cells'] ?? []));

            foreach ($candidateCells as $index => $item) {
                $candidateLeft = (int) ($item['left'] ?? 0);
                $templateLeft = (int) ($templateCells[$index]['left'] ?? 0);

                if (abs($candidateLeft - $templateLeft) > 50) {
                    return false;
                }
            }

            return true;
        }

        $leftDelta = abs((int) ($candidate['line_left'] ?? 0) - (int) ($template['line_left'] ?? 0));
        $rightDelta = abs((int) ($candidate['line_right'] ?? 0) - (int) ($template['line_right'] ?? 0));

        if ($leftDelta > 40 || $rightDelta > 40) {
            return false;
        }

        return true;
    }

    /**
     * Purpose: Decide whether a single-line Poppler text block should be treated as a table title.
     * Inputs: A potential title line and the first table row.
     * Returns: True when the preceding line looks like a table heading or caption.
     * Side effects: None.
     *
     * @param  array<string, mixed>  $titleLine
     * @param  array<string, mixed>  $firstTableLine
     */
    private function isPopplerPdfTableTitleCandidate(array $titleLine, array $firstTableLine, int $pageWidth): bool
    {
        $titleText = trim((string) ($titleLine['text'] ?? ''));

        if ($titleText === '' || mb_strlen($titleText, 'UTF-8') > 160) {
            return false;
        }

        $titleWidth = max(0, (int) ($titleLine['right'] ?? 0) - (int) ($titleLine['left'] ?? 0));
        $firstRowTop = (int) ($firstTableLine['top'] ?? 0);
        $titleBottom = (int) ($titleLine['bottom'] ?? 0);
        $verticalGap = $firstRowTop - $titleBottom;

        if ($pageWidth > 0 && $titleWidth < (int) round($pageWidth * 0.35)) {
            return false;
        }

        if ($verticalGap < 0 || $verticalGap > 40) {
            return false;
        }

        return true;
    }

    /**
     * Purpose: Build a structured table block from one detected Poppler table run.
     * Inputs: The Poppler line groups, the table run descriptor, the page number, and the running table sequence.
     * Returns: A single structured table block with title, rows, and table metadata.
     * Side effects: None.
     *
     * @param  array<int, array<string, mixed>>  $lineGroups
     * @param  array<string, mixed>  $tableRun
     * @return array<string, mixed>|null
     */
    private function buildPopplerPdfTableBlock(
        array $lineGroups,
        array $tableRun,
        int $pageNumber,
        int $tableSequenceInDocument,
        int $pageWidth,
        int $pageHeight,
    ): ?array {
        $startIndex = (int) ($tableRun['start_index'] ?? 0);
        $endIndex = (int) ($tableRun['end_index'] ?? $startIndex);
        $titleIndex = isset($tableRun['title_index']) ? (int) $tableRun['title_index'] : null;
        $titleLine = $titleIndex !== null && isset($lineGroups[$titleIndex]) ? $lineGroups[$titleIndex] : null;
        $tableLines = [];

        for ($lineIndex = $startIndex; $lineIndex <= $endIndex; $lineIndex++) {
            if (! isset($lineGroups[$lineIndex])) {
                continue;
            }

            $tableLines[] = $lineGroups[$lineIndex];
        }

        $dataLines = $tableLines;

        if ($titleLine !== null) {
            $dataLines = array_values(array_filter($dataLines, static fn (array $line) => (int) ($line['top'] ?? 0) !== (int) ($titleLine['top'] ?? 0)));
        }

        $logicalRows = $this->buildPopplerPdfTableLogicalRows($dataLines, $pageWidth);

        $columnCount = 0;
        foreach ($logicalRows as $logicalRow) {
            $columnCount = max($columnCount, count((array) ($logicalRow['cells'] ?? [])));
        }

        $columnCount = max(1, $columnCount);

        if (! $this->isPopplerPdfTableStructure($logicalRows, $columnCount)) {
            return null;
        }

        $rows = [];
        $tableTextLines = [];
        $headerRowIndices = [];
        $tableTitleText = $titleLine !== null ? $this->normalizeLineText((string) ($titleLine['text'] ?? '')) : null;

        if ($tableTitleText !== null && $tableTitleText !== '') {
            $tableTextLines[] = $tableTitleText;
            $rows[] = [
                'row_index' => 0,
                'row_type' => 'title',
                'is_title' => true,
                'is_header' => false,
                'is_empty' => false,
                'explicit_header' => false,
                'cells' => [[
                    'row_index' => 0,
                    'cell_index' => 0,
                    'column_index' => 0,
                    'text' => $tableTitleText,
                    'is_empty' => false,
                    'rowspan' => 1,
                    'colspan' => $columnCount,
                    'is_header' => false,
                    'is_title' => true,
                    'style_hints' => ['title_row'],
                    'source_metadata' => [
                        'source_type' => 'pdf_table',
                        'page_number' => $pageNumber,
                        'table_sequence_in_document' => $tableSequenceInDocument,
                        'row_index' => 0,
                        'cell_index' => 0,
                        'row_count' => count($dataLines) + 1,
                        'column_count' => $columnCount,
                        'is_title_row' => true,
                    ],
                ]],
                'source_metadata' => [
                    'source_type' => 'pdf_table',
                    'page_number' => $pageNumber,
                    'page_width' => $pageWidth,
                    'page_height' => $pageHeight,
                    'table_sequence_in_document' => $tableSequenceInDocument,
                ],
            ];
        }

        $logicalRowIndex = count($rows);
        $logicalRowCount = count($logicalRows);

        foreach ($logicalRows as $logicalRow) {
            $itemTexts = array_values(array_filter(array_map(
                static fn (array $cell): string => trim((string) ($cell['text'] ?? '')),
                (array) ($logicalRow['cells'] ?? []),
            ), static fn (string $text): bool => $text !== ''));

            if ($itemTexts === []) {
                continue;
            }

            if (! in_array($logicalRowIndex, $headerRowIndices, true) && $logicalRowIndex === count($rows)) {
                $headerRowIndices[] = $logicalRowIndex;
            }

            $rowType = $tableTitleText !== null && $tableTitleText !== '' && $logicalRowIndex === count($rows) ? 'header' : 'data';
            $rowCells = [];

            foreach ((array) ($logicalRow['cells'] ?? []) as $cellIndex => $cell) {
                if (! is_array($cell)) {
                    continue;
                }

                $cellText = trim((string) ($cell['text'] ?? ''));
                $rowCells[] = [
                    'row_index' => $logicalRowIndex,
                    'cell_index' => $cellIndex,
                    'column_index' => $cellIndex,
                    'text' => $cellText,
                    'is_empty' => $cellText === '',
                    'rowspan' => 1,
                    'colspan' => 1,
                    'is_header' => $rowType === 'header',
                    'is_title' => false,
                    'style_hints' => $rowType === 'header' ? ['header_row'] : [],
                    'source_metadata' => [
                        'source_type' => 'pdf_table',
                        'page_number' => $pageNumber,
                        'table_sequence_in_document' => $tableSequenceInDocument,
                        'row_index' => $logicalRowIndex,
                        'cell_index' => $cellIndex,
                        'column_count' => $columnCount,
                        'row_count' => $logicalRowCount + ($tableTitleText !== null && $tableTitleText !== '' ? 1 : 0),
                    ],
                ];
            }

            $rows[] = [
                'row_index' => $logicalRowIndex,
                'row_type' => $rowType,
                'is_title' => false,
                'is_header' => $rowType === 'header',
                'is_empty' => false,
                'explicit_header' => $rowType === 'header',
                'cells' => $rowCells,
                'source_metadata' => [
                    'source_type' => 'pdf_table',
                    'page_number' => $pageNumber,
                    'table_sequence_in_document' => $tableSequenceInDocument,
                    'row_index' => $logicalRowIndex,
                    'column_count' => $columnCount,
                    'row_count' => $logicalRowCount + ($tableTitleText !== null && $tableTitleText !== '' ? 1 : 0),
                ],
            ];

            $tableTextLines[] = implode(' | ', $itemTexts);
            $logicalRowIndex++;
        }

        $titleRowIndex = $tableTitleText !== null && $tableTitleText !== '' ? 0 : null;
        $tableText = trim(implode("\n", $tableTextLines));
        $markdownLines = [];

        if ($tableTitleText !== null && $tableTitleText !== '') {
            $markdownLines[] = '**'.$tableTitleText.'**';
        }

        if ($logicalRows !== []) {
            $headerCells = array_values(array_filter(array_map(
                static fn (array $cell): string => trim((string) ($cell['text'] ?? '')),
                (array) ($logicalRows[0]['cells'] ?? []),
            ), static fn (string $text): bool => $text !== ''));

            if ($headerCells !== []) {
                $markdownLines[] = '| '.implode(' | ', $headerCells).' |';
                $markdownLines[] = '| '.implode(' | ', array_fill(0, count($headerCells), '---')).' |';

                foreach (array_slice($logicalRows, 1) as $dataRow) {
                    $cells = array_values(array_filter(array_map(
                        static fn (array $cell): string => trim((string) ($cell['text'] ?? '')),
                        (array) ($dataRow['cells'] ?? []),
                    ), static fn (string $text): bool => $text !== ''));

                    if ($cells !== []) {
                        $markdownLines[] = '| '.implode(' | ', $cells).' |';
                    }
                }
            }
        }

        $tableMarkdown = implode("\n", $markdownLines);
        $tableHtml = $this->buildPopplerPdfTableHtml($rows, $columnCount, $titleRowIndex, $headerRowIndices);
        $tableJson = [
            'source_type' => 'pdf_table',
            'complexity' => 'simple',
            'warnings' => [],
            'row_count' => count($rows),
            'column_count' => $columnCount,
            'title_row_index' => $titleRowIndex,
            'header_row_indices' => $headerRowIndices,
            'rows' => $rows,
            'source_metadata' => [
                'source_type' => 'pdf_table',
                'page_number' => $pageNumber,
                'table_sequence_in_document' => $tableSequenceInDocument,
            ],
            'table_text' => $tableText,
            'table_markdown' => $tableMarkdown,
            'table_html' => $tableHtml,
        ];

        return [
            'type' => 'table',
            'page_number' => $pageNumber,
            'page_width' => $pageWidth,
            'page_height' => $pageHeight,
            'top' => (int) ($titleLine['top'] ?? ($tableLines[0]['top'] ?? 0)),
            'left' => (int) ($titleLine['left'] ?? ($tableLines[0]['left'] ?? 0)),
            'width' => (int) ($titleLine['right'] ?? ($tableLines[0]['right'] ?? 0)),
            'height' => (int) ($tableLines[array_key_last($tableLines)]['bottom'] ?? ($tableLines[0]['bottom'] ?? 0)) - (int) ($tableLines[0]['top'] ?? 0),
            'title' => sprintf('Tabell %d – side %d', $tableSequenceInDocument, $pageNumber),
            'text' => $tableText,
            'style' => null,
            'level' => null,
            'table_json' => $tableJson,
            'table_html' => $tableHtml,
            'table_markdown' => $tableMarkdown,
            'table_text' => $tableText,
            'table_complexity' => 'simple',
            'table_warnings' => [],
            'table_index_in_document' => $tableSequenceInDocument - 1,
            'source_metadata' => [
                'source_type' => 'pdf_table',
                'page_number' => $pageNumber,
                'page_width' => $pageWidth,
                'page_height' => $pageHeight,
                'table_sequence_in_document' => $tableSequenceInDocument,
                'table_index_in_document' => $tableSequenceInDocument - 1,
                'display_title' => sprintf('Tabell %d – side %d', $tableSequenceInDocument, $pageNumber),
            ],
        ];
    }

    /**
     * Purpose: Decide whether Poppler-derived logical rows really form a table instead of wrapped prose or layout fragments.
     * Inputs: Normalized logical rows and the maximum column count.
     * Returns: True when the structure has enough table-like evidence to be treated as a table.
     * Side effects: None.
     *
     * @param array<int, array{cells?: array<int, array{text: string, left: ?int, width: ?int}}}> $logicalRows
     */
    private function isPopplerPdfTableStructure(array $logicalRows, int $columnCount): bool
    {
        $rows = array_values(array_filter($logicalRows, static fn ($row): bool => is_array($row)));

        if (count($rows) < 2 || $columnCount < 2) {
            return false;
        }

        $meaningfulRows = 0;
        $labelLikeRows = 0;
        $rowsWithAtLeastThreeCells = 0;

        foreach ($rows as $row) {
            $cells = array_values(array_filter((array) ($row['cells'] ?? []), static fn ($cell): bool => is_array($cell)));
            $texts = array_values(array_filter(array_map(
                fn (array $cell): string => $this->normalizeLineText((string) ($cell['text'] ?? '')),
                $cells,
            ), static fn (string $text): bool => $text !== ''));

            if (count($texts) < 2) {
                continue;
            }

            $meaningfulRows++;

            if (count($texts) >= 3) {
                $rowsWithAtLeastThreeCells++;
            }

            foreach ($texts as $text) {
                if ($this->isPopplerPdfTableCellLabelLike($text)) {
                    $labelLikeRows++;
                    break;
                }
            }
        }

        if ($meaningfulRows < 2) {
            return false;
        }

        if ($columnCount === 2) {
            return $meaningfulRows >= 3 || $labelLikeRows >= 2;
        }

        if ($columnCount >= 3) {
            return $rowsWithAtLeastThreeCells >= 1 || $labelLikeRows >= 2;
        }

        return false;
    }

    /**
     * Purpose: Determine whether a cell looks like a short label or code rather than a sentence fragment.
     * Inputs: One table cell text.
     * Returns: True when the text is concise and label-like.
     * Side effects: None.
     */
    private function isPopplerPdfTableCellLabelLike(string $text): bool
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($normalized === '') {
            return false;
        }

        if ($this->isPopplerPdfListText($normalized)) {
            return false;
        }

        if (preg_match('/^(?:[•\-\*–—●○]|(?:\d+|[A-Za-zÆØÅæøå])[).])\s+/u', $normalized) === 1) {
            return false;
        }

        if (mb_strlen($normalized, 'UTF-8') > 48) {
            return false;
        }

        $tokens = array_values(array_filter(preg_split('/\s+/u', $normalized) ?: [], static fn (string $word): bool => $word !== ''));
        $wordCount = count($tokens);

        if ($wordCount > 6) {
            return false;
        }

        if ($wordCount >= 4) {
            $firstToken = mb_strtolower((string) ($tokens[0] ?? ''), 'UTF-8');

            if (in_array($firstToken, ['en', 'et', 'den', 'det', 'de', 'the', 'a', 'an', 'this', 'that', 'these', 'those'], true)) {
                return false;
            }
        }

        if (preg_match('/[.!?]\s*$/u', $normalized) === 1) {
            return false;
        }

        return true;
    }

    /**
     * Purpose: Extract readable cell texts from one Poppler table line.
     * Inputs: A line group that was already identified as table-like.
     * Returns: Ordered non-empty cell texts suitable for table JSON and previews.
     * Side effects: None.
     *
     * @param  array<string, mixed>  $line
     * @return array<int, string>
     */
    private function popplerPdfTableLineCellTexts(array $line): array
    {
        $template = $this->popplerPdfTableColumnTemplate($line);

        if ($template === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (array $cell): string => trim((string) ($cell['text'] ?? '')),
            (array) ($template['cells'] ?? []),
        ), static fn (string $text): bool => $text !== ''));
    }

    /**
     * Purpose: Normalize one Poppler table candidate line into logical cells.
     * Inputs: A line group with text items or flattened text.
     * Returns: Ordered cell descriptors suitable for table detection.
     * Side effects: None.
     *
     * @param  array<string, mixed>  $line
     * @return array<int, array{text: string, left: ?int, width: ?int}>
     */
    private function normalizePopplerPdfTableCells(array $line): array
    {
        $items = array_values(array_filter((array) ($line['items'] ?? []), static fn ($item): bool => is_array($item)));
        usort($items, static fn (array $left, array $right): int => ((int) ($left['left'] ?? 0)) <=> ((int) ($right['left'] ?? 0)));

        if ($items !== []) {
            $cells = [];
            $itemCount = count($items);

            for ($index = 0; $index < $itemCount; $index++) {
                $item = $items[$index];
                $cellText = $this->normalizeLineText((string) ($item['text'] ?? ''));

                if ($cellText === '') {
                    continue;
                }

                if ($this->isStandalonePopplerPdfBulletMarker($cellText) && isset($items[$index + 1])) {
                    $nextItem = $items[$index + 1];
                    $nextText = $this->normalizeLineText((string) ($nextItem['text'] ?? ''));

                    if ($nextText !== '') {
                        $cells[] = [
                            'text' => $this->normalizeLineText($cellText.' '.$nextText),
                            'left' => (int) ($item['left'] ?? 0),
                            'width' => max(
                                0,
                                ((int) ($nextItem['left'] ?? 0) + (int) ($nextItem['width'] ?? 0)) - (int) ($item['left'] ?? 0),
                            ),
                        ];
                        $index++;

                        continue;
                    }
                }

                $cells[] = [
                    'text' => $cellText,
                    'left' => (int) ($item['left'] ?? 0),
                    'width' => max(0, (int) ($item['width'] ?? 0)),
                ];
            }

            if (count($cells) >= 2) {
                return $cells;
            }
        }

        $textCells = $this->splitPopplerPdfTableTextCells((string) ($line['raw_text'] ?? $line['text'] ?? ''));

        return array_map(
            static fn (string $cellText): array => [
                'text' => $cellText,
                'left' => null,
                'width' => null,
            ],
            $textCells,
        );
    }

    /**
     * Purpose: Determine whether a token is a standalone bullet marker in a PDF line.
     * Inputs: One extracted text token.
     * Returns: True when the token is just a bullet glyph or dash marker.
     * Side effects: None.
     */
    private function isStandalonePopplerPdfBulletMarker(string $text): bool
    {
        return preg_match('/^(?:[•\-\*–—●○])$/u', trim($text)) === 1;
    }

    /**
     * Purpose: Decide whether a non-candidate line continues the current PDF table row.
     * Inputs: The current line, the previous line in reading order, the detected table template, and the page width.
     * Returns: True when the line is a wrapped continuation inside the table body.
     * Side effects: None.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $previousLine
     * @param  array<string, mixed>  $template
     */
    private function isPopplerPdfTableContinuationLine(array $line, array $previousLine, array $template, int $pageWidth): bool
    {
        $text = trim((string) ($line['text'] ?? ''));

        if ($text === '') {
            return false;
        }

        if ($this->detectPdfHeadingLevel($text) !== null) {
            return false;
        }

        if ($this->looksLikePdfHeaderFooterMarker($text)) {
            return false;
        }

        $lineTop = (int) ($line['top'] ?? 0);
        $previousBottom = (int) ($previousLine['bottom'] ?? $lineTop);
        $previousTop = (int) ($previousLine['top'] ?? $lineTop);
        $verticalGap = $lineTop - $previousBottom;

        $previousHeight = max(1, $previousBottom - $previousTop);
        $allowedNegativeOverlap = min(10, max(2, (int) round($previousHeight * 0.35)));

        if ($verticalGap < -$allowedNegativeOverlap || $verticalGap > 30) {
            return false;
        }

        $lineLeft = (int) ($line['left'] ?? 0);
        $lineWidth = max(0, (int) ($line['right'] ?? 0) - $lineLeft);
        $templateCells = array_values((array) ($template['cells'] ?? []));

        if (count($templateCells) < 2) {
            return false;
        }

        $matchedCellIndex = null;
        $matchedDistance = null;

        foreach ($templateCells as $cellIndex => $cell) {
            $cellLeft = (int) ($cell['left'] ?? 0);
            $distance = abs($lineLeft - $cellLeft);

            if ($distance <= 45 && ($matchedDistance === null || $distance < $matchedDistance)) {
                $matchedCellIndex = $cellIndex;
                $matchedDistance = $distance;
            }
        }

        if ($matchedCellIndex === null) {
            return false;
        }

        if ($pageWidth > 0 && $lineWidth > (int) round($pageWidth * 0.95)) {
            return false;
        }

        if ($matchedCellIndex === 0 && $pageWidth > 0 && $lineWidth > (int) round($pageWidth * 0.5)) {
            return false;
        }

        return true;
    }

    /**
     * Purpose: Convert Poppler table lines into logical rows while preserving wrapped cell text.
     * Inputs: Ordered table-like lines and the page width.
     * Returns: Logical rows with merged cells that can be rendered as a single table.
     * Side effects: None.
     *
     * @param  array<int, array<string, mixed>>  $tableLines
     * @return array<int, array{cells: array<int, array{text: string, left: ?int, width: ?int}}, source_lines: array<int, array<string, mixed>>}>
     */
    private function buildPopplerPdfTableLogicalRows(array $tableLines, int $pageWidth): array
    {
        $rows = [];
        $currentRow = null;
        $currentTemplate = null;
        $previousLine = null;

        foreach ($tableLines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $lineTemplate = $this->popplerPdfTableColumnTemplate($line);

            if ($lineTemplate !== null) {
                if ($currentRow !== null) {
                    $rows[] = $currentRow;
                }

                $currentRow = [
                    'cells' => $lineTemplate['cells'] ?? [],
                    'source_lines' => [$line],
                ];
                $currentTemplate = $lineTemplate;
                $previousLine = $line;

                continue;
            }

            if ($currentRow !== null && $previousLine !== null && $currentTemplate !== null && $this->isPopplerPdfTableContinuationLine($line, $previousLine, $currentTemplate, $pageWidth)) {
                $this->appendPopplerPdfTableContinuationLine($currentRow, $line);
                $currentRow['source_lines'][] = $line;
                $previousLine = $line;
            }
        }

        if ($currentRow !== null) {
            $rows[] = $currentRow;
        }

        return $rows;
    }

    /**
     * Purpose: Append one wrapped Poppler line to the last cell of the current logical table row.
     * Inputs: A mutable logical row and one continuation line.
     * Returns: The same row with updated cell text.
     * Side effects: Mutates the supplied row structure.
     *
     * @param  array<string, mixed>  $currentRow
     * @param  array<string, mixed>  $line
     */
    private function appendPopplerPdfTableContinuationLine(array &$currentRow, array $line): void
    {
        $cells = array_values((array) ($currentRow['cells'] ?? []));

        if ($cells === []) {
            return;
        }

        $lineLeft = (int) ($line['left'] ?? 0);
        $appendIndex = null;
        $appendDistance = null;

        foreach ($cells as $index => $cell) {
            if (! is_array($cell)) {
                continue;
            }

            $cellLeft = (int) ($cell['left'] ?? 0);
            $distance = abs($lineLeft - $cellLeft);

            if ($appendDistance === null || $distance < $appendDistance) {
                $appendIndex = $index;
                $appendDistance = $distance;
            }
        }

        if ($appendIndex === null || ! isset($cells[$appendIndex]) || ! is_array($cells[$appendIndex])) {
            return;
        }

        $addition = trim((string) ($line['text'] ?? ''));

        if ($addition === '') {
            return;
        }

        $existing = trim((string) ($cells[$appendIndex]['text'] ?? ''));
        $cells[$appendIndex]['text'] = $existing !== '' ? trim($existing."\n".$addition) : $addition;
        $cells[$appendIndex]['width'] = max(
            (int) ($cells[$appendIndex]['width'] ?? 0),
            max(0, (int) ($line['right'] ?? 0) - (int) ($cells[$appendIndex]['left'] ?? 0)),
        );

        $currentRow['cells'] = $cells;
    }

    /**
     * Purpose: Merge adjacent Poppler table blocks when a single table continues onto the next page.
     * Inputs: Ordered Poppler blocks across the full document.
     * Returns: The same block list with consecutive cross-page table fragments merged into one block.
     * Side effects: None.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private function mergePopplerPdfTableBlocksAcrossPages(array $blocks): array
    {
        if ($blocks === []) {
            return [];
        }

        $mergedBlocks = [];

        foreach ($blocks as $block) {
            if ($mergedBlocks !== []) {
                $lastIndex = array_key_last($mergedBlocks);
                $lastBlock = $lastIndex !== null ? $mergedBlocks[$lastIndex] : null;

                // Standard adjacent merge: the previous block is the table on page N.
                if (is_array($lastBlock) && $this->shouldMergePopplerPdfTableBlocks($lastBlock, $block)) {
                    $mergedBlocks[$lastIndex] = $this->mergePopplerPdfTableBlocks($lastBlock, $block);

                    continue;
                }

                // Lookback merge: the table on page N is separated from the continuation by
                // page-separator blocks (footers, logos, ALL-CAPS section labels).
                if ((string) ($block['type'] ?? '') === 'table') {
                    $lookbackIndex = $this->findMergeableTableBlockIndexViaLookback($mergedBlocks, $block);

                    if ($lookbackIndex !== null) {
                        $mergedBlocks[$lookbackIndex] = $this->mergePopplerPdfTableBlocks($mergedBlocks[$lookbackIndex], $block);

                        continue;
                    }
                }
            }

            $mergedBlocks[] = $block;
        }

        return array_values($mergedBlocks);
    }

    /**
     * Purpose: Decide whether two adjacent Poppler table blocks are fragments of the same table across a page break.
     * Inputs: The previous and current structured blocks.
     * Returns: True when the blocks should be merged into one table block.
     * Side effects: None.
     *
     * @param  array<string, mixed>  $previousBlock
     * @param  array<string, mixed>  $currentBlock
     */
    private function shouldMergePopplerPdfTableBlocks(array $previousBlock, array $currentBlock): bool
    {
        if ((string) ($previousBlock['type'] ?? '') !== 'table' || (string) ($currentBlock['type'] ?? '') !== 'table') {
            return false;
        }

        $previousPage = isset($previousBlock['page_end']) && (int) $previousBlock['page_end'] > 0
            ? (int) $previousBlock['page_end']
            : (int) ($previousBlock['page_number'] ?? 0);
        $currentPage = (int) ($currentBlock['page_number'] ?? 0);

        if ($previousPage <= 0 || $currentPage <= 0 || $currentPage !== $previousPage + 1) {
            return false;
        }

        $previousPageHeight = max(1, (int) ($previousBlock['page_height'] ?? 0));
        $currentPageHeight = max(1, (int) ($currentBlock['page_height'] ?? 0));
        $previousTop = (int) ($previousBlock['top'] ?? 0);
        $previousHeight = max(0, (int) ($previousBlock['height'] ?? 0));
        $previousBottom = $previousTop + $previousHeight;
        $previousBottomRatio = $previousBottom / $previousPageHeight;

        if ($previousBottomRatio < 0.70) {
            return false;
        }

        $currentTop = max(0, (int) ($currentBlock['top'] ?? 0));
        $currentTopRatio = $currentTop / $currentPageHeight;

        if ($currentTopRatio > 0.30) {
            return false;
        }

        $previousLeft = (int) ($previousBlock['left'] ?? 0);
        $currentLeft = (int) ($currentBlock['left'] ?? 0);
        $previousWidth = max(0, (int) ($previousBlock['width'] ?? 0));
        $currentWidth = max(0, (int) ($currentBlock['width'] ?? 0));
        $allowedLeftDelta = max(45, (int) round(min((int) ($previousBlock['page_width'] ?? 0), (int) ($currentBlock['page_width'] ?? 0)) * 0.06));

        if (abs($previousLeft - $currentLeft) > $allowedLeftDelta) {
            return false;
        }

        if ($previousWidth > 0 && $currentWidth > 0) {
            $smallerWidth = min($previousWidth, $currentWidth);
            $largerWidth = max($previousWidth, $currentWidth);

            // Poppler can report a much wider continuation block on the next page when
            // wrapped cells are laid out differently, so use a conservative width ratio
            // instead of a brittle absolute delta.
            if ($largerWidth > max(120, (int) round($smallerWidth * 2.5))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Purpose: Determine whether a block on the continuation page is a page-level separator (logo, marker) that
     *          should be ignored when looking for a table to merge across a page break.
     * Inputs: One structured block from the continuation page.
     * Returns: True when the block carries no document content and should be skipped during cross-page table lookup.
     * Side effects: None.
     *
     * @param  array<string, mixed>  $block
     */
    private function isTransparentPdfPageSeparatorBlock(array $block): bool
    {
        $blockType = (string) ($block['type'] ?? '');
        $text = trim((string) ($block['text'] ?? ''));
        $pageHeight = max(1, (int) ($block['page_height'] ?? 842));
        $top = max(0, (int) ($block['top'] ?? 0));
        $topRatio = $top / $pageHeight;

        // Company logos and decorative images printed at the top of the page.
        if ($blockType === 'image') {
            return $topRatio < 0.25;
        }

        if ($text === '') {
            return false;
        }

        // Short footer/header-like text lines (dates, page numbers, company names).
        if (in_array($blockType, ['paragraph', 'list', 'text'], true)) {
            return $this->looksLikePdfHeaderFooterMarker($text);
        }

        // ALL-CAPS attachment/section identifiers printed at the top of the page
        // (e.g. "BILAG 1-11", "VEDLEGG A"). These are page-level labels, not content headings.
        if ($blockType === 'heading' && $topRatio < 0.20) {
            $normalized = trim($text);

            return mb_strlen($normalized, 'UTF-8') <= 20
                && preg_match('/^[A-ZÆØÅ][A-ZÆØÅ\s0-9\-\/]+$/u', $normalized) === 1
                && preg_match('/[A-ZÆØÅ]{3,}/u', $normalized) === 1;
        }

        return false;
    }

    /**
     * Purpose: Determine whether a block that trails on the previous page (page N) is a footer marker
     *          that should be skipped when performing a cross-page table merge lookback.
     * Inputs: One structured block from the previous page, after the table block on that page.
     * Returns: True when the block is a page footer that does not represent new document content.
     * Side effects: None.
     *
     * @param  array<string, mixed>  $block
     */
    private function isTransparentPdfTrailingFooterBlock(array $block): bool
    {
        $blockType = (string) ($block['type'] ?? '');
        $text = trim((string) ($block['text'] ?? ''));

        if ($text === '' || ! in_array($blockType, ['paragraph', 'list', 'text'], true)) {
            return false;
        }

        return $this->looksLikePdfHeaderFooterMarker($text);
    }

    /**
     * Purpose: Look back through recently merged blocks to find a table block on the previous page that
     *          can be merged with the current continuation table, even when non-content page-separator
     *          blocks appear between them.
     * Inputs: The ordered merged block list and the current table block being evaluated.
     * Returns: The index into $mergedBlocks of a mergeable previous table block, or null when none qualifies.
     * Side effects: None.
     *
     * @param  array<int, array<string, mixed>>  $mergedBlocks
     * @param  array<string, mixed>  $currentBlock
     */
    private function findMergeableTableBlockIndexViaLookback(array $mergedBlocks, array $currentBlock): ?int
    {
        $currentPage = (int) ($currentBlock['page_number'] ?? 0);

        if ($currentPage <= 1 || $mergedBlocks === []) {
            return null;
        }

        $targetPage = $currentPage - 1;

        foreach (array_reverse(array_keys($mergedBlocks), true) as $index) {
            $candidate = $mergedBlocks[$index];

            if (! is_array($candidate)) {
                return null;
            }

            $candidatePage = (int) ($candidate['page_number'] ?? 0);
            $candidateType = (string) ($candidate['type'] ?? '');
            $candidatePageEnd = isset($candidate['page_end']) && (int) $candidate['page_end'] > 0
                ? (int) $candidate['page_end']
                : $candidatePage;

            // Found a table block on the previous page (or spanning up to the previous page).
            if ($candidateType === 'table' && $candidatePageEnd === $targetPage) {
                return $this->shouldMergePopplerPdfTableBlocks($candidate, $currentBlock)
                    ? $index
                    : null;
            }

            // A non-table block on the continuation page is acceptable if it is a page separator.
            if ($candidatePage === $currentPage) {
                if ($this->isTransparentPdfPageSeparatorBlock($candidate)) {
                    continue;
                }

                return null;
            }

            // A non-table block on the target page is acceptable if it is a footer trailing after the table.
            if ($candidatePage === $targetPage) {
                if ($this->isTransparentPdfTrailingFooterBlock($candidate)) {
                    continue;
                }

                return null;
            }

            // Any block on an unrelated page breaks the chain.
            return null;
        }

        return null;
    }

    /**
     * Purpose: Merge two adjacent Poppler table blocks into one logical table.
     * Inputs: A previous table block and the continuation block that follows it on the next page.
     * Returns: One combined table block with concatenated rows and metadata.
     * Side effects: None.
     *
     * @param  array<string, mixed>  $previousBlock
     * @param  array<string, mixed>  $currentBlock
     * @return array<string, mixed>
     */
    private function mergePopplerPdfTableBlocks(array $previousBlock, array $currentBlock): array
    {
        $previousJson = is_array($previousBlock['table_json'] ?? null) ? $previousBlock['table_json'] : [];
        $currentJson = is_array($currentBlock['table_json'] ?? null) ? $currentBlock['table_json'] : [];

        if ($previousJson === [] || $currentJson === []) {
            return $previousBlock;
        }

        $previousRows = array_values(array_filter((array) ($previousJson['rows'] ?? []), static fn ($row): bool => is_array($row)));
        $currentRows = array_values(array_filter((array) ($currentJson['rows'] ?? []), static fn ($row): bool => is_array($row)));

        if ($previousRows === [] || $currentRows === []) {
            return $previousBlock;
        }

        $previousColumnCount = (int) ($previousJson['column_count'] ?? 0);
        $currentColumnCount = (int) ($currentJson['column_count'] ?? 0);

        if ($previousColumnCount > 0 && $currentColumnCount > 0 && $previousColumnCount !== $currentColumnCount) {
            return $previousBlock;
        }

        $mergedRows = $previousRows;
        $rowOffset = count($mergedRows);

        foreach ($currentRows as $rowIndex => $row) {
            $row['row_index'] = $rowOffset + $rowIndex;
            $row['source_metadata'] = array_merge(
                is_array($row['source_metadata'] ?? null) ? $row['source_metadata'] : [],
                [
                    'row_index' => $rowOffset + $rowIndex,
                ],
            );

            $cells = array_values(array_filter((array) ($row['cells'] ?? []), static fn ($cell): bool => is_array($cell)));

            foreach ($cells as $cellIndex => &$cell) {
                $cell['row_index'] = $rowOffset + $rowIndex;
                $cell['cell_index'] = $cellIndex;
                $cell['source_metadata'] = array_merge(
                    is_array($cell['source_metadata'] ?? null) ? $cell['source_metadata'] : [],
                    [
                        'row_index' => $rowOffset + $rowIndex,
                        'cell_index' => $cellIndex,
                    ],
                );
            }
            unset($cell);

            $row['cells'] = $cells;
            $mergedRows[] = $row;
        }

        $mergedColumnCount = max($previousColumnCount, $currentColumnCount);
        $previousTitleRowIndex = isset($previousJson['title_row_index']) ? (int) $previousJson['title_row_index'] : null;
        $previousHeaderRows = array_values(array_unique(array_map(
            static fn ($index): int => (int) $index,
            (array) ($previousJson['header_row_indices'] ?? []),
        )));

        $mergedJson = $previousJson;
        $mergedJson['rows'] = $mergedRows;
        $mergedJson['row_count'] = count($mergedRows);
        $mergedJson['column_count'] = $mergedColumnCount;
        $mergedJson['header_row_indices'] = $previousHeaderRows;
        $mergedJson['table_text'] = trim(implode("\n", array_filter([
            trim((string) ($previousBlock['table_text'] ?? $previousJson['table_text'] ?? $previousBlock['text'] ?? '')),
            trim((string) ($currentBlock['table_text'] ?? $currentJson['table_text'] ?? $currentBlock['text'] ?? '')),
        ], static fn (string $text): bool => $text !== '')));
        $mergedJson['table_markdown'] = trim(implode("\n", array_filter([
            trim((string) ($previousBlock['table_markdown'] ?? $previousJson['table_markdown'] ?? '')),
            trim((string) ($currentBlock['table_markdown'] ?? $currentJson['table_markdown'] ?? '')),
        ], static fn (string $text): bool => $text !== '')));

        $mergedBlock = $previousBlock;
        $mergedBlock['height'] = max(
            (int) ($previousBlock['height'] ?? 0),
            max(0, ((int) ($currentBlock['top'] ?? 0) + (int) ($currentBlock['height'] ?? 0)) - (int) ($previousBlock['top'] ?? 0)),
        );
        $mergedBlock['width'] = max((int) ($previousBlock['width'] ?? 0), (int) ($currentBlock['width'] ?? 0));
        $mergedBlock['text'] = trim(implode("\n", array_filter([
            trim((string) ($previousBlock['text'] ?? '')),
            trim((string) ($currentBlock['text'] ?? '')),
        ], static fn (string $text): bool => $text !== '')));
        $mergedBlock['table_text'] = $mergedJson['table_text'];
        $mergedBlock['table_markdown'] = $mergedJson['table_markdown'];
        $mergedBlock['table_json'] = $mergedJson;
        $mergedBlock['table_html'] = $this->buildPopplerPdfTableHtml(
            $mergedRows,
            $mergedColumnCount,
            $previousTitleRowIndex,
            $previousHeaderRows,
        );
        $mergedBlock['table_complexity'] = (string) ($previousBlock['table_complexity'] ?? $currentBlock['table_complexity'] ?? 'simple');
        $mergedBlock['table_warnings'] = array_values(array_unique(array_filter(array_merge(
            (array) ($previousBlock['table_warnings'] ?? []),
            (array) ($currentBlock['table_warnings'] ?? []),
        ), static fn ($warning): bool => trim((string) $warning) !== '')));
        $currentPage = (int) ($currentBlock['page_number'] ?? 0);
        $previousPageStart = (int) ($previousBlock['page_number'] ?? 0);
        $mergedBlock['source_metadata'] = array_merge(
            is_array($previousBlock['source_metadata'] ?? null) ? $previousBlock['source_metadata'] : [],
            [
                'page_end' => $currentPage,
            ],
        );
        $mergedBlock['page_end'] = $currentPage;

        // Update the display title to show the page range (e.g. "Tabell 8 – side 13–14").
        $previousTitle = trim((string) ($previousBlock['title'] ?? ''));

        if ($previousTitle !== '' && $previousPageStart > 0 && $currentPage > $previousPageStart) {
            $updatedTitle = preg_replace(
                '/–\s*side\s*\d+(?:–\d+)?\s*$/ui',
                sprintf('– side %d–%d', $previousPageStart, $currentPage),
                $previousTitle,
            );

            if (is_string($updatedTitle) && $updatedTitle !== '') {
                $mergedBlock['title'] = $updatedTitle;
            }
        }

        return $mergedBlock;
    }

    /**
     * Purpose: Split a flattened Poppler line into table-like cells using explicit column separators.
     * Inputs: A single extracted text line.
     * Returns: Ordered cell texts or an empty array when the line does not look tabular.
     * Side effects: None.
     *
     * @return array<int, string>
     */
    private function splitPopplerPdfTableTextCells(string $text): array
    {
        $normalized = trim($text);

        if ($normalized === '') {
            return [];
        }

        if (! preg_match('/(?:\t+|\s{2,}|\s*\|\s*)/u', $normalized)) {
            return [];
        }

        $segments = preg_split('/(?:\s*\|\s*|\t+|\s{2,})/u', $normalized);

        if (! is_array($segments)) {
            return [];
        }

        $segments = array_values(array_filter(array_map(
            static fn (string $segment): string => trim($segment),
            $segments,
        ), static fn (string $segment): bool => $segment !== ''));

        return count($segments) >= 2 ? $segments : [];
    }

    /**
     * Purpose: Render a minimal HTML table preview for Poppler-derived tables.
     * Inputs: The normalized table rows, the logical column count, the title row index, and the header rows.
     * Returns: An HTML table string suitable for previews and retrieval payloads.
     * Side effects: None.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $headerRowIndices
     */
    private function buildPopplerPdfTableHtml(array $rows, int $columnCount, ?int $titleRowIndex, array $headerRowIndices): string
    {
        $html = '<table style="width:100%; border-collapse:collapse; border:1px solid #cbd5e1; font-size:14px; line-height:1.4;">';
        $hasHeader = $titleRowIndex !== null || $headerRowIndices !== [];

        if ($hasHeader) {
            $html .= '<thead>';
        }

        if ($titleRowIndex !== null && isset($rows[$titleRowIndex])) {
            $titleText = trim(implode(' ', array_filter(array_map(
                static fn (array $cell): string => trim((string) ($cell['text'] ?? '')),
                array_values(array_filter((array) ($rows[$titleRowIndex]['cells'] ?? []), static fn ($cell): bool => is_array($cell))),
            ), static fn (string $text): bool => $text !== '')));

            $html .= '<tr><th colspan="'.$columnCount.'" scope="colgroup" style="padding:0.7rem 0.85rem; border:1px solid #cbd5e1; background:#f8fafc; font-weight:600; color:#0f172a;">'
                .htmlspecialchars($titleText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                .'</th></tr>';
        }

        foreach ($headerRowIndices as $headerRowIndex) {
            if (! isset($rows[$headerRowIndex])) {
                continue;
            }

            $html .= '<tr>';

            foreach ((array) ($rows[$headerRowIndex]['cells'] ?? []) as $cell) {
                if (! is_array($cell)) {
                    continue;
                }

                $cellText = trim((string) ($cell['text'] ?? ''));
                $html .= '<th style="padding:0.6rem 0.75rem; border:1px solid #cbd5e1; background:#f8fafc; font-weight:600; color:#0f172a;">'
                    .htmlspecialchars($cellText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    .'</th>';
            }

            $html .= '</tr>';
        }

        if ($hasHeader) {
            $html .= '</thead>';
        }

        $html .= '<tbody>';

        foreach ($rows as $rowIndex => $row) {
            if ($titleRowIndex !== null && $rowIndex === $titleRowIndex) {
                continue;
            }

            if (in_array((int) ($row['row_index'] ?? $rowIndex), $headerRowIndices, true)) {
                continue;
            }

            $html .= '<tr>';

            foreach ((array) ($row['cells'] ?? []) as $cell) {
                if (! is_array($cell)) {
                    continue;
                }

                $cellText = trim((string) ($cell['text'] ?? ''));
                $html .= '<td style="padding:0.6rem 0.75rem; border:1px solid #cbd5e1; background:#ffffff; color:#334155;">'
                    .htmlspecialchars($cellText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    .'</td>';
            }

            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Purpose: Build a text block collection from Poppler lines that are not consumed by tables or graphics.
     * Inputs: The ordered line groups, the consumed line index set, and the page number.
     * Returns: Ordered text, heading, and list blocks.
     * Side effects: None.
     *
     * @param  array<int, array<string, mixed>>  $lineGroups
     * @param  array<int, bool>  $consumedLineIndexes
     * @return array<int, array<string, mixed>>
     */
    private function buildPopplerPdfTextBlocks(array $lineGroups, array $consumedLineIndexes, int $pageNumber): array
    {
        $blocks = [];
        $currentParagraph = [];
        $currentParagraphTop = null;
        $currentParagraphLeft = null;
        $previousLine = null;
        $orderIndex = 0;
        $pageHasTocEntries = false;

        foreach ($lineGroups as $lineIndex => $line) {
            if (isset($consumedLineIndexes[$lineIndex])) {
                continue;
            }

            if ($this->isPopplerPdfTocEntryLine((array) $line)) {
                $pageHasTocEntries = true;
                break;
            }
        }

        $flushParagraph = function () use (&$blocks, &$currentParagraph, &$currentParagraphTop, &$currentParagraphLeft, &$orderIndex, $pageNumber): void {
            if ($currentParagraph === []) {
                return;
            }

            $text = $this->normalizeBlockText($this->joinPdfParagraphLines($currentParagraph));

            if ($text !== '') {
                $blocks[] = [
                    'type' => $this->isPopplerPdfListText($text) ? 'list' : 'paragraph',
                    'page_number' => $pageNumber,
                    'top' => $currentParagraphTop ?? 0,
                    'left' => $currentParagraphLeft ?? 0,
                    'width' => 0,
                    'height' => 0,
                    'text' => $text,
                    'style' => null,
                    'level' => null,
                    'order_index' => $orderIndex++,
                    'source_metadata' => [
                        'source_type' => 'pdf_text',
                        'page_number' => $pageNumber,
                    ],
                ];
            }

            $currentParagraph = [];
            $currentParagraphTop = null;
            $currentParagraphLeft = null;
        };

        foreach ($lineGroups as $lineIndex => $line) {
            if (isset($consumedLineIndexes[$lineIndex])) {
                continue;
            }

            $text = trim((string) ($line['text'] ?? ''));

            if ($text === '') {
                $flushParagraph();
                $previousLine = null;

                continue;
            }

            if ($pageHasTocEntries && $this->isPopplerPdfTocHeadingLine($text)) {
                $flushParagraph();
                $previousLine = null;

                continue;
            }

            if ($this->isPopplerPdfTocEntryLine((array) $line)) {
                $flushParagraph();
                $previousLine = null;

                continue;
            }

            $level = $this->detectPdfHeadingLevel($text);
            $isListLine = $this->isPopplerPdfListLine($line);
            $lineTop = (int) ($line['top'] ?? 0);
            $lineLeft = (int) ($line['left'] ?? 0);
            $previousBottom = $previousLine !== null ? (int) ($previousLine['bottom'] ?? $lineTop) : null;
            $verticalGap = $previousBottom !== null ? $lineTop - $previousBottom : null;

            if ($isListLine) {
                $flushParagraph();
                $blocks[] = [
                    'type' => 'list',
                    'page_number' => $pageNumber,
                    'top' => $lineTop,
                    'left' => $lineLeft,
                    'width' => (int) (($line['right'] ?? $lineLeft) - $lineLeft),
                    'height' => (int) (($line['bottom'] ?? $lineTop) - $lineTop),
                    'text' => $text,
                    'style' => null,
                    'level' => null,
                    'order_index' => $orderIndex++,
                    'source_metadata' => [
                        'source_type' => 'pdf_text',
                        'page_number' => $pageNumber,
                    ],
                ];
                $previousLine = $line;

                continue;
            }

            if ($level !== null) {
                $flushParagraph();
                $blocks[] = [
                    'type' => 'heading',
                    'page_number' => $pageNumber,
                    'top' => $lineTop,
                    'left' => $lineLeft,
                    'width' => (int) (($line['right'] ?? $lineLeft) - $lineLeft),
                    'height' => (int) (($line['bottom'] ?? $lineTop) - $lineTop),
                    'text' => $text,
                    'style' => null,
                    'level' => $level,
                    'order_index' => $orderIndex++,
                    'source_metadata' => [
                        'source_type' => 'pdf_text',
                        'page_number' => $pageNumber,
                    ],
                ];
                $previousLine = $line;

                continue;
            }

            $shouldContinueParagraph = $currentParagraph !== []
                && $verticalGap !== null
                && $verticalGap >= 0
                && $verticalGap <= 18
                && abs($lineLeft - (int) ($currentParagraphLeft ?? $lineLeft)) <= 28;

            if (! $shouldContinueParagraph) {
                $flushParagraph();
                $currentParagraphTop = $lineTop;
                $currentParagraphLeft = $lineLeft;
            }

            $currentParagraph[] = $text;
            $previousLine = $line;
        }

        $flushParagraph();

        return $blocks;
    }

    /**
     * Purpose: Build one Poppler image block and optionally attach nearby caption text.
     * Inputs: The ordered line groups, the image descriptor, the working directory, the page number, the running graphic index, and consumed line indexes.
     * Returns: One image block or null when no readable image bytes are available.
     * Side effects: Reads extracted image files from disk.
     *
     * @param  array<int, array<string, mixed>>  $lineGroups
     * @param  array<int, bool>  $consumedLineIndexes
     * @return array<string, mixed>|null
     */
    private function buildPopplerPdfImageBlock(
        array $lineGroups,
        array $imageItem,
        string $workingDirectory,
        int $pageNumber,
        int $graphicSequenceInDocument,
        array &$consumedLineIndexes,
        string $pdfPath,
    ): ?array {
        $pageWidth = max(1, (int) ($imageItem['page_width'] ?? 0));
        $pageHeight = max(1, (int) ($imageItem['page_height'] ?? 0));

        if ($this->isPopplerPdfDecorativeGraphic($imageItem, $pageWidth, $pageHeight)) {
            return null;
        }

        $imagePath = $this->resolvePopplerImagePath($workingDirectory, (string) ($imageItem['resolved_path'] ?? $imageItem['src'] ?? ''));
        $imageBytes = is_string($imageItem['image_bytes'] ?? null) ? $imageItem['image_bytes'] : null;

        if ($imageBytes === null && $imagePath !== null && is_file($imagePath) && is_readable($imagePath)) {
            $imageBytes = file_get_contents($imagePath);
        }

        if (! is_string($imageBytes) || trim($imageBytes) === '') {
            return [
                'type' => 'image',
                'page_number' => $pageNumber,
                'page_width' => $pageWidth,
                'page_height' => $pageHeight,
                'top' => (int) ($imageItem['top'] ?? 0),
                'left' => (int) ($imageItem['left'] ?? 0),
                'width' => (int) ($imageItem['width'] ?? 0),
                'height' => (int) ($imageItem['height'] ?? 0),
                'title' => sprintf('Grafikk %d – side %d', $graphicSequenceInDocument, $pageNumber),
                'text' => sprintf('Grafikk %d – side %d', $graphicSequenceInDocument, $pageNumber),
                'style' => null,
                'level' => null,
                'image_bytes' => null,
                'image_path' => null,
                'image_disk' => null,
                'image_mime_type' => null,
                'image_original_filename' => null,
                'image_width' => (int) ($imageItem['width'] ?? 0) ?: null,
                'image_height' => (int) ($imageItem['height'] ?? 0) ?: null,
                'image_hash' => null,
                'image_metadata' => [
                    'source_type' => 'pdf_graphic',
                    'page_number' => $pageNumber,
                    'page_width' => $pageWidth,
                    'page_height' => $pageHeight,
                    'graphic_sequence_in_document' => $graphicSequenceInDocument,
                    'source_image_path' => $imagePath,
                    'extraction_failed' => true,
                ],
                'image_alt_text' => null,
                'image_caption' => null,
                'ocr_text' => null,
                'image_description' => null,
                'image_index_in_document' => $graphicSequenceInDocument - 1,
                'source_metadata' => [
                    'source_type' => 'pdf_graphic',
                    'page_number' => $pageNumber,
                    'page_width' => $pageWidth,
                    'page_height' => $pageHeight,
                    'graphic_sequence_in_document' => $graphicSequenceInDocument,
                    'image_index_in_document' => $graphicSequenceInDocument - 1,
                    'display_title' => sprintf('Grafikk %d – side %d', $graphicSequenceInDocument, $pageNumber),
                    'source_image_path' => $imagePath,
                    'extraction_failed' => true,
                ],
                'order_index' => $graphicSequenceInDocument,
            ];
        }

        $captionIndex = $this->detectPopplerPdfCaptionLineIndex($lineGroups, $imageItem, $consumedLineIndexes);
        $captionText = $captionIndex !== null && isset($lineGroups[$captionIndex])
            ? $this->normalizeLineText((string) data_get($lineGroups[$captionIndex], 'text', ''))
            : null;

        if ($captionIndex !== null) {
            $consumedLineIndexes[$captionIndex] = true;
        }

        $nearbyBefore = $this->popplerPdfNearbyLineText($lineGroups, $imageItem, $consumedLineIndexes, -1);
        $nearbyAfter = $this->popplerPdfNearbyLineText($lineGroups, $imageItem, $consumedLineIndexes, 1);
        $displayTitle = sprintf('Grafikk %d – side %d', $graphicSequenceInDocument, $pageNumber);
        $contentParts = [
            $displayTitle,
            'Grafikk på side '.$pageNumber,
        ];

        if ($captionText !== null && $captionText !== '') {
            $contentParts[] = 'Grafikktekst: '.$captionText;
        }

        if ($nearbyBefore !== null && $nearbyBefore !== '') {
            $contentParts[] = 'Tekst før grafikken: '.$nearbyBefore;
        }

        if ($nearbyAfter !== null && $nearbyAfter !== '') {
            $contentParts[] = 'Tekst etter grafikken: '.$nearbyAfter;
        }

        if ($captionText === null && $nearbyBefore === null && $nearbyAfter === null) {
            $contentParts[] = 'Grafikken finnes på siden, men ingen tekst rundt den er registrert.';
        }

        $content = trim(implode("\n\n", $contentParts));
        $imageHash = hash('sha256', $imageBytes);
        $imageOriginalFilename = $this->normalizePopplerImageFilename($imagePath);
        $mimeType = $this->resolveImageMimeTypeFromBytes($imageBytes, $imagePath);
        $dimensions = $this->extractPopplerImageDimensions($imageBytes, $imageItem);
        $imageMetadata = [
            'source_type' => 'pdf_graphic',
            'page_number' => $pageNumber,
            'graphic_sequence_in_document' => $graphicSequenceInDocument,
            'source_image_path' => $imagePath,
            'caption_detected' => $captionText !== null,
            'nearby_text_before' => $nearbyBefore,
            'nearby_text_after' => $nearbyAfter,
            'image_hash' => $imageHash,
            'width' => $dimensions['width'] ?? null,
            'height' => $dimensions['height'] ?? null,
            'mime_type' => $mimeType,
        ];

        return [
            'type' => 'image',
            'page_number' => $pageNumber,
            'page_width' => $pageWidth,
            'page_height' => $pageHeight,
            'top' => (int) ($imageItem['top'] ?? 0),
            'left' => (int) ($imageItem['left'] ?? 0),
            'width' => (int) ($imageItem['width'] ?? 0),
            'height' => (int) ($imageItem['height'] ?? 0),
            'title' => $displayTitle,
            'text' => $content,
            'style' => null,
            'level' => null,
            'image_bytes' => $imageBytes,
            'image_path' => null,
            'image_disk' => null,
            'image_mime_type' => $mimeType,
            'image_original_filename' => $imageOriginalFilename,
            'image_width' => $dimensions['width'] ?? null,
            'image_height' => $dimensions['height'] ?? null,
            'image_hash' => $imageHash,
            'image_metadata' => $imageMetadata,
            'image_alt_text' => $captionText,
            'image_caption' => $captionText,
            'ocr_text' => null,
            'image_description' => null,
            'image_index_in_document' => $graphicSequenceInDocument - 1,
            'source_metadata' => array_merge($imageMetadata, [
                'display_title' => $displayTitle,
                'page_width' => $pageWidth,
                'page_height' => $pageHeight,
                'image_index_in_document' => $graphicSequenceInDocument - 1,
            ]),
            'order_index' => $graphicSequenceInDocument,
        ];
    }

    /**
     * Purpose: Decide whether one Poppler image is a decorative edge graphic such as a header logo or footer mark.
     * Inputs: The image descriptor and the page dimensions.
     * Returns: True when the image is small enough and close enough to the page edge to be treated as decoration.
     * Side effects: None.
     *
     * @param  array<string, mixed>  $imageItem
     */
    private function isPopplerPdfDecorativeGraphic(array $imageItem, int $pageWidth, int $pageHeight): bool
    {
        if ($pageWidth <= 0 || $pageHeight <= 0) {
            return false;
        }

        $graphicWidth = max(1, (int) ($imageItem['width'] ?? 0));
        $graphicHeight = max(1, (int) ($imageItem['height'] ?? 0));
        $top = max(0, (int) ($imageItem['top'] ?? 0));
        $bottom = $top + $graphicHeight;
        $pageArea = max(1, $pageWidth * $pageHeight);
        $graphicAreaRatio = ($graphicWidth * $graphicHeight) / $pageArea;
        $isEdgeGraphic = $top <= 90 || $bottom >= max(0, $pageHeight - 90);

        if (! $isEdgeGraphic) {
            return false;
        }

        if ($graphicAreaRatio <= 0.12) {
            return true;
        }

        return $graphicAreaRatio <= 0.18 && $graphicWidth <= 360 && $graphicHeight <= 180;
    }

    /**
     * Purpose: Determine whether a line is the standalone heading of a table-of-contents page.
     * Inputs: One normalized line of text.
     * Returns: True when the line is a TOC heading such as "Innholdsfortegnelse" or "Table of contents".
     * Side effects: None.
     */
    private function isPopplerPdfTocHeadingLine(string $text): bool
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($normalized === '') {
            return false;
        }

        return in_array(mb_strtolower($normalized, 'UTF-8'), [
            'innholdsfortegnelse',
            'innhaldsliste',
            'innhold',
            'table of contents',
            'contents',
            'content',
        ], true);
    }

    /**
     * Purpose: Determine which Poppler line is the most likely caption for one detected image.
     * Inputs: The ordered line groups, the image descriptor, and the consumed line index map.
     * Returns: The line index of a nearby caption or null.
     * Side effects: None.
     *
     * @param  array<int, array<string, mixed>>  $lineGroups
     * @param  array<string, mixed>  $imageItem
     * @param  array<int, bool>  $consumedLineIndexes
     */
    private function detectPopplerPdfCaptionLineIndex(array $lineGroups, array $imageItem, array $consumedLineIndexes): ?int
    {
        $imageTop = (int) ($imageItem['top'] ?? 0);
        $imageBottom = $imageTop + max(1, (int) ($imageItem['height'] ?? 0));
        $bestIndex = null;
        $bestDistance = null;

        foreach ($lineGroups as $lineIndex => $line) {
            if (isset($consumedLineIndexes[$lineIndex])) {
                continue;
            }

            $text = trim((string) ($line['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            if ($this->isPopplerPdfNonContentLine($text)) {
                continue;
            }

            $lineTop = (int) ($line['top'] ?? 0);
            $lineBottom = (int) ($line['bottom'] ?? $lineTop);
            $distance = min(abs($lineTop - $imageBottom), abs($lineBottom - $imageTop));

            if ($distance > 90) {
                continue;
            }

            if (! $this->looksLikePopplerPdfCaption($text)) {
                continue;
            }

            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestIndex = $lineIndex;
            }
        }

        return $bestIndex;
    }

    /**
     * Purpose: Read nearby non-consumed text above or below one Poppler image.
     * Inputs: The ordered line groups, the image descriptor, the consumed line index map, and a direction.
     * Returns: The nearest usable text line or null.
     * Side effects: None.
     *
     * @param  array<int, array<string, mixed>>  $lineGroups
     * @param  array<string, mixed>  $imageItem
     * @param  array<int, bool>  $consumedLineIndexes
     */
    private function popplerPdfNearbyLineText(array $lineGroups, array $imageItem, array $consumedLineIndexes, int $direction): ?string
    {
        $imageTop = (int) ($imageItem['top'] ?? 0);
        $imageBottom = $imageTop + max(1, (int) ($imageItem['height'] ?? 0));
        $bestText = null;
        $bestDistance = null;

        foreach ($lineGroups as $lineIndex => $line) {
            if (isset($consumedLineIndexes[$lineIndex])) {
                continue;
            }

            $text = trim((string) ($line['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            if ($this->isPopplerPdfNonContentLine($text)) {
                continue;
            }

            $lineTop = (int) ($line['top'] ?? 0);
            $lineBottom = (int) ($line['bottom'] ?? $lineTop);

            if ($direction < 0 && $lineBottom > $imageTop) {
                continue;
            }

            if ($direction > 0 && $lineTop < $imageBottom) {
                continue;
            }

            $distance = $direction < 0
                ? $imageTop - $lineBottom
                : $lineTop - $imageBottom;

            if ($distance < 0 || $distance > 120) {
                continue;
            }

            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestText = $text;
            }
        }

        return $bestText !== null ? $bestText : null;
    }

    /**
     * Purpose: Determine whether a line likely acts as an image caption.
     * Inputs: A candidate line text.
     * Returns: True when the line resembles a caption or figure label.
     * Side effects: None.
     */
    private function looksLikePopplerPdfCaption(string $text): bool
    {
        $normalized = trim($text);

        if ($normalized === '') {
            return false;
        }

        if (preg_match('/^(figur|figure|grafikk|diagram|illustrasjon|tabell|table)\b/iu', $normalized) === 1) {
            return true;
        }

        return mb_strlen($normalized, 'UTF-8') <= 140 && preg_match('/[:.]\s*\S/u', $normalized) === 1;
    }

    /**
     * Purpose: Determine whether visible PDF text resembles a bullet, numbered, or lettered list item.
     * Inputs: A single extracted text line.
     * Returns: True when the text appears to be a list item.
     * Side effects: None.
     */
    private function isPopplerPdfListText(string $text): bool
    {
        $normalized = trim($text);

        if ($normalized === '') {
            return false;
        }

        return preg_match('/^(?:[•\-\*–—●○]|(?:\d+|[A-Za-zÆØÅæøå])[).])\s+/u', $normalized) === 1;
    }

    /**
     * Purpose: Determine whether one Poppler line group behaves like a list item.
     * Inputs: A Poppler line group with item positions and normalized text.
     * Returns: True when the layout looks like a list rather than a table row.
     * Side effects: None.
     *
     * @param  array<string, mixed>  $line
     */
    private function isPopplerPdfListLine(array $line): bool
    {
        $items = array_values(array_filter((array) ($line['items'] ?? []), static fn ($item): bool => is_array($item)));

        if (count($items) < 2) {
            return false;
        }

        return $this->isPopplerPdfListText((string) ($line['text'] ?? ''));
    }

    /**
     * Purpose: Determine whether a nearby Poppler line is a repeated header/footer marker.
     * Inputs: One line of extracted text.
     * Returns: True when the line looks like page metadata or a short footer/header stamp.
     * Side effects: None.
     */
    private function looksLikePdfHeaderFooterMarker(string $text): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 'UTF-8');

        if ($normalized === '') {
            return false;
        }

        if (preg_match('/^\d+\s*$/u', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^\d+\s*[\/-]\s*\d+\s*$/u', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^\s*(?:side|page)\s*\d+(?:\s*(?:av|of)\s*\d+)?\s*$/iu', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^\d{1,2}[.\-\/]\d{1,2}[.\-\/]\d{2,4}(?:\s+\d{1,2}:\d{2})?\s*$/u', $normalized) === 1) {
            return true;
        }

        return mb_strlen($normalized, 'UTF-8') <= 48 && preg_match('/\d/u', $normalized) === 1;
    }

    /**
     * Purpose: Determine whether a nearby Poppler line introduces a table of contents.
     * Inputs: One line of extracted text.
     * Returns: True when the line clearly introduces a contents block.
     * Side effects: None.
     */
    private function isPdfTocHeadingLine(string $text): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 'UTF-8');

        if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > 80) {
            return false;
        }

        return preg_match('/\b(?:innholdsfortegnelse|table of contents|contents)\b/ui', $normalized) === 1
            || $normalized === 'innhold'
            || $normalized === 'innholdsfortegnelse';
    }

    /**
     * Purpose: Determine whether a nearby Poppler line looks like a table-of-contents entry.
     * Inputs: One line of extracted text.
     * Returns: True when the line resembles an outline entry with a page number.
     * Side effects: None.
     */
    private function isPdfTocOutlineLine(string $text): bool
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > 180) {
            return false;
        }

        if (preg_match('/\.\.\.+\s*\d+/u', $normalized) === 1) {
            return true;
        }

        if (preg_match('/(?:\b\d+(?:\.\d+)*\b|\b[A-ZÆØÅ]\.|\bKapittel\s+\d+\b|\bDel\s+\d+\b)\s+.+?(?:\s+(?:side\s+)?\d+\b)/iu', $normalized) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Purpose: Determine whether a nearby Poppler line is header/footer or TOC noise that should not be attached to graphics.
     * Inputs: One candidate line of text.
     * Returns: True when the line should be ignored for graphic context and caption detection.
     * Side effects: None.
     */
    private function isPopplerPdfNonContentLine(string $text): bool
    {
        return $this->looksLikePdfHeaderFooterMarker($text)
            || $this->isPdfTocHeadingLine($text)
            || $this->isPdfTocOutlineLine($text);
    }

    /**
     * Purpose: Remove running PDF header and footer blocks from the extracted block list.
     * Inputs: All blocks from one PDF after sorting.
     * Returns: Blocks with running header/footer text removed.
     * Side effects: None.
     *
     * A block is suppressed when it is a paragraph or list block positioned in the top 10 %
     * or bottom 12 % of its page AND either (a) its text matches the page-marker heuristic
     * or (b) its normalised text (with integers replaced by N) appears in that same edge zone
     * on two or more distinct pages.  Heading, table, and image blocks are never touched.
     * Blocks without a valid page_height pass through unchanged, which keeps DOCX and other
     * non-PDF extraction paths unaffected even if this method were ever called on their output.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private function suppressPdfRunningHeaderFooterBlocks(array $blocks): array
    {
        /** @var array<string, list<int>> $edgeTextPages normalised_text => [page_number, ...] */
        $edgeTextPages = [];

        foreach ($blocks as $block) {
            if (! in_array($block['type'] ?? '', ['paragraph', 'list'], true)) {
                continue;
            }

            $pageHeight = (int) ($block['page_height'] ?? 0);

            if ($pageHeight <= 0) {
                continue;
            }

            $text = trim((string) ($block['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $topRatio = (int) ($block['top'] ?? 0) / $pageHeight;

            if ($topRatio > 0.10 && $topRatio < 0.88) {
                continue;
            }

            $normalized = $this->normalizePdfRunningLineText($text);
            $pageNumber = (int) ($block['page_number'] ?? 0);

            if (! isset($edgeTextPages[$normalized])) {
                $edgeTextPages[$normalized] = [];
            }

            if (! in_array($pageNumber, $edgeTextPages[$normalized], true)) {
                $edgeTextPages[$normalized][] = $pageNumber;
            }
        }

        $repeatedEdgeTexts = array_flip(
            array_keys(array_filter($edgeTextPages, static fn (array $pages): bool => count($pages) >= 2))
        );

        return array_values(array_filter($blocks, function (array $block) use ($repeatedEdgeTexts): bool {
            if (! in_array($block['type'] ?? '', ['paragraph', 'list'], true)) {
                return true;
            }

            $pageHeight = (int) ($block['page_height'] ?? 0);

            if ($pageHeight <= 0) {
                return true;
            }

            $text = trim((string) ($block['text'] ?? ''));

            if ($text === '') {
                return true;
            }

            $topRatio = (int) ($block['top'] ?? 0) / $pageHeight;

            if ($topRatio > 0.10 && $topRatio < 0.88) {
                return true;
            }

            if ($this->looksLikePdfHeaderFooterMarker($text)) {
                return false;
            }

            return ! isset($repeatedEdgeTexts[$this->normalizePdfRunningLineText($text)]);
        }));
    }

    /**
     * Purpose: Normalise a PDF line for cross-page repetition detection.
     * Inputs: Raw line text.
     * Returns: Lowercase text with standalone integers replaced by N.
     * Side effects: None.
     */
    private function normalizePdfRunningLineText(string $text): string
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 'UTF-8');

        return preg_replace('/\b\d+\b/u', 'N', $normalized) ?? $normalized;
    }

    /**
     * Purpose: Resolve a Poppler image path relative to the working directory when needed.
     * Inputs: The working directory and a raw src value from the XML.
     * Returns: An absolute readable path or null.
     * Side effects: None.
     */
    private function resolvePopplerImagePath(string $workingDirectory, string $src): ?string
    {
        $normalizedSrc = trim($src);

        if ($normalizedSrc === '') {
            return null;
        }

        if (is_file($normalizedSrc) && is_readable($normalizedSrc)) {
            return $normalizedSrc;
        }

        $candidate = ltrim($normalizedSrc, '/');
        if (Str::startsWith($candidate, './')) {
            $candidate = substr($candidate, 2);
        }

        $resolved = $workingDirectory.DIRECTORY_SEPARATOR.$candidate;

        if (is_file($resolved) && is_readable($resolved)) {
            return $resolved;
        }

        $basename = basename($candidate);
        $fallback = $workingDirectory.DIRECTORY_SEPARATOR.$basename;

        if (is_file($fallback) && is_readable($fallback)) {
            return $fallback;
        }

        return null;
    }

    /**
     * Purpose: Normalize a Poppler image file name from a resolved image path.
     * Inputs: The absolute image path or null.
     * Returns: A stable file name when available.
     * Side effects: None.
     */
    private function normalizePopplerImageFilename(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return basename($path);
    }

    /**
     * Purpose: Infer a MIME type for a Poppler extracted image.
     * Inputs: Raw image bytes and the resolved image path.
     * Returns: A MIME type string or null when no reliable type can be inferred.
     * Side effects: None.
     *
     * @return array{width: ?int, height: ?int}
     */
    private function extractPopplerImageDimensions(string $imageBytes, array $imageItem): array
    {
        if (! function_exists('getimagesizefromstring')) {
            return [
                'width' => isset($imageItem['width']) ? (int) $imageItem['width'] : null,
                'height' => isset($imageItem['height']) ? (int) $imageItem['height'] : null,
            ];
        }

        $size = @getimagesizefromstring($imageBytes);

        if (! is_array($size)) {
            return [
                'width' => isset($imageItem['width']) ? (int) $imageItem['width'] : null,
                'height' => isset($imageItem['height']) ? (int) $imageItem['height'] : null,
            ];
        }

        return [
            'width' => isset($size[0]) ? (int) $size[0] : null,
            'height' => isset($size[1]) ? (int) $size[1] : null,
        ];
    }

    /**
     * Purpose: Infer the MIME type for a Poppler image from its bytes or file extension.
     * Inputs: Raw image bytes and the resolved image path.
     * Returns: A best-effort MIME type string.
     * Side effects: None.
     */
    private function resolveImageMimeTypeFromBytes(string $imageBytes, ?string $imagePath): ?string
    {
        if (function_exists('getimagesizefromstring')) {
            $size = @getimagesizefromstring($imageBytes);

            if (is_array($size) && isset($size['mime']) && is_string($size['mime']) && $size['mime'] !== '') {
                return $size['mime'];
            }
        }

        if (! is_string($imagePath) || trim($imagePath) === '') {
            return null;
        }

        return $this->mimeTypeForImageExtension(Str::lower((string) pathinfo($imagePath, PATHINFO_EXTENSION)));
    }

    /**
     * Purpose: Resolve a Poppler binary path from configuration, falling back to the command name.
     * Inputs: The configuration key and the default command name.
     * Returns: A command string that can be executed in the current container.
     * Side effects: None.
     */
    private function resolvePopplerBinary(string $configKey, string $fallbackCommand): string
    {
        $configuredBinary = config($configKey);

        return is_string($configuredBinary) && trim($configuredBinary) !== ''
            ? trim($configuredBinary)
            : $fallbackCommand;
    }

    /**
     * Purpose: Recursively delete a temporary Poppler working directory.
     * Inputs: A directory path.
     * Returns: None.
     * Side effects: Deletes temporary files on disk.
     */
    private function removeTemporaryDirectory(string $workingDirectory): void
    {
        if (! is_dir($workingDirectory)) {
            return;
        }

        $entries = scandir($workingDirectory);

        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $entryPath = $workingDirectory.DIRECTORY_SEPARATOR.$entry;

                if (is_dir($entryPath)) {
                    $this->removeTemporaryDirectory($entryPath);

                    continue;
                }

                @unlink($entryPath);
            }
        }

        @rmdir($workingDirectory);
    }

    private function detectPdfHeadingLevel(string $line): ?int
    {
        if (mb_strlen($line) > 120) {
            return null;
        }

        // Sub-numbered: "1.1 Title", "2.3.4 Title", "1.1.1 Title"
        if (preg_match('/^\d+\.\d[\d.]*\s+\S/u', $line)) {
            return 2;
        }

        // Unnumbered chapter labels that often appear in PDFs.
        if (preg_match('/^\s*(?:kapittel|del)\s+\d+\b(?:\s+[^\d].*)?$/iu', $line)) {
            return 1;
        }

        // Top-level numbered: "1. Title", "10. Title"
        if (preg_match('/^\d+\.\s+[^\d\s]/u', $line)) {
            return 1;
        }

        // Top-level numbered without a trailing dot: "1 Title"
        if (preg_match('/^\d+\s+[^\d\s]/u', $line)) {
            return 1;
        }

        // Alphabetic appendix-style headings: "A. Vedlegg"
        if (preg_match('/^[A-ZÆØÅ]\.\s+[^\d]/u', $line)) {
            return 1;
        }

        // ALL CAPS heading (min 4 letters, no sentence punctuation)
        if (preg_match('/^[A-ZÆØÅ][A-ZÆØÅ0-9\s\-\/]{3,}$/u', $line) && ! str_contains($line, '.')) {
            return 1;
        }

        return null;
    }

    /**
     * Purpose: Determine whether visible PDF text resembles a list item.
     * Inputs: A single extracted text line.
     * Returns: True when the text appears to be a bulleted or numbered list item.
     * Side effects: None.
     */
    private function isListText(string $text): bool
    {
        $normalized = trim($text);

        if ($normalized === '') {
            return false;
        }

        return preg_match('/^(?:[•\-\*]|\d+[).])\s+/u', $normalized) === 1;
    }

    /**
     * Purpose: Extract structured paragraph blocks from DOCX XML.
     * Inputs: Raw WordprocessingML XML content plus optional styles XML content.
     * Returns: Ordered structured blocks with resolved paragraph style metadata.
     * Side effects: Parses XML in memory.
     *
     * @return array<int, array{text: string, style: ?string, level: ?int}>
     */
    private function extractDocxStructuredBlocks(string $documentXml, ?string $stylesXml = null): array
    {
        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($documentXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return [];
            }

            $styleMap = $this->extractDocxStyleMap($stylesXml);
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            $blocks = [];

            foreach ($xpath->query('//w:p') as $paragraph) {
                $textNodes = $xpath->query('.//w:t', $paragraph);
                $parts = [];

                if ($textNodes !== false) {
                    foreach ($textNodes as $textNode) {
                        $parts[] = (string) $textNode->textContent;
                    }
                }

                $text = $this->normalizeBlockText(implode(' ', $parts));

                if ($text === '') {
                    continue;
                }

                $styleId = null;
                $styleNode = $xpath->query('.//w:pPr/w:pStyle', $paragraph);

                if ($styleNode !== false && $styleNode->length > 0) {
                    $styleId = (string) $styleNode->item(0)?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')?->nodeValue;
                }

                $resolvedStyle = $styleMap[$styleId] ?? $styleId;
                $level = $this->resolveDocxHeadingLevel($styleId, $resolvedStyle);

                $blocks[] = [
                    'text' => $text,
                    'style' => $resolvedStyle !== '' ? $resolvedStyle : null,
                    'level' => $level,
                ];
            }

            return $blocks;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
    }

    /**
     * Purpose: Read the optional DOCX styles.xml file and map style IDs to style names.
     * Inputs: Raw styles XML content, if available.
     * Returns: A map from style ID to human-readable style name.
     * Side effects: Parses XML in memory.
     *
     * @return array<string, string>
     */
    private function extractDocxStyleMap(?string $stylesXml): array
    {
        if (! is_string($stylesXml) || trim($stylesXml) === '') {
            return [];
        }

        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($stylesXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return [];
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            $map = [];

            foreach ($xpath->query('//w:style') as $styleNode) {
                $styleId = (string) $styleNode->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'styleId')?->nodeValue;
                $styleNameNode = $xpath->query('./w:name', $styleNode);
                $styleName = null;

                if ($styleNameNode !== false && $styleNameNode->length > 0) {
                    $styleName = (string) $styleNameNode->item(0)?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')?->nodeValue;
                }

                if ($styleId !== '') {
                    $map[$styleId] = $styleName !== '' ? $styleName : $styleId;
                }
            }

            return $map;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
    }

    /**
     * Purpose: Resolve the heading level for a DOCX paragraph style.
     * Inputs: The raw style ID and the resolved style name.
     * Returns: 1 for H1, 2 for H2, or null when the style is not a heading.
     * Side effects: None.
     */
    private function resolveDocxHeadingLevel(?string $styleId, ?string $styleName): ?int
    {
        $candidates = array_values(array_filter([
            $styleId,
            $styleName,
        ], static fn (?string $value): bool => is_string($value) && trim($value) !== ''));

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeDocxStyleKey($candidate);

            if (in_array($normalized, ['heading1', 'overskrift1'], true)) {
                return 1;
            }

            if (in_array($normalized, ['heading2', 'overskrift2'], true)) {
                return 2;
            }
        }

        return null;
    }

    /**
     * Purpose: Normalize a DOCX style identifier or style name for heading matching.
     * Inputs: A style identifier or style name.
     * Returns: A lowercase alphanumeric key without spacing.
     * Side effects: None.
     */
    private function normalizeDocxStyleKey(string $value): string
    {
        $normalized = preg_replace('/[^[:alnum:]]+/u', '', mb_strtolower(trim($value), 'UTF-8'));

        return is_string($normalized) ? $normalized : mb_strtolower(trim($value), 'UTF-8');
    }

    /**
     * Purpose: Map each paragraph style that carries its OWN numbering reference (e.g. Word's
     * built-in "Overskrift1"–"Overskrift9"/"Heading 1"–"Heading 9" styles, each embedding
     * <w:pPr><w:numPr>) to its numId/ilvl, so a paragraph using that style — even with no
     * per-paragraph <w:numPr> override — resolves to the same numbering as Word would render.
     * Inputs: Raw optional styles.xml content.
     * Returns: styleId => ['num_id' => string, 'ilvl' => int].
     * Side effects: Parses XML in memory.
     *
     * @return array<string, array{num_id: string, ilvl: int}>
     */
    private function extractDocxStyleNumberingMap(?string $stylesXml): array
    {
        if (! is_string($stylesXml) || trim($stylesXml) === '') {
            return [];
        }

        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($stylesXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return [];
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            $map = [];

            foreach ($xpath->query('//w:style[@w:type="paragraph"]') as $styleNode) {
                $styleId = (string) $styleNode->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'styleId')?->nodeValue;

                if ($styleId === '') {
                    continue;
                }

                $numPrNodes = $xpath->query('./w:pPr/w:numPr', $styleNode);

                if ($numPrNodes === false || $numPrNodes->length === 0) {
                    continue;
                }

                $numPrNode = $numPrNodes->item(0);
                $numIdNodes = $xpath->query('./w:numId', $numPrNode);

                if ($numIdNodes === false || $numIdNodes->length === 0) {
                    continue;
                }

                $numId = (string) $numIdNodes->item(0)?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')?->nodeValue;

                if ($numId === '') {
                    continue;
                }

                $ilvlNodes = $xpath->query('./w:ilvl', $numPrNode);
                $ilvl = $ilvlNodes !== false && $ilvlNodes->length > 0
                    ? (int) ($ilvlNodes->item(0)?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')?->nodeValue ?? '0')
                    : 0;

                $map[$styleId] = ['num_id' => $numId, 'ilvl' => $ilvl];
            }

            return $map;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
    }

    /**
     * Purpose: Parse word/numbering.xml into the level definitions and numId->abstractNumId
     * mapping needed to render Word's own auto-numbering (e.g. "2.1", "2.1.1") for a paragraph,
     * instead of relying on any literal number typed into the paragraph text (which frequently
     * doesn't exist at all — the number is purely a rendering of the numbering definition).
     * Inputs: Raw optional numbering.xml content.
     * Returns: num_to_abstract (numId => abstractNumId), num_overrides (numId => [ilvl => start]
     * from <w:lvlOverride><w:startOverride>), abstract_levels (abstractNumId => [ilvl =>
     * ['num_fmt' => ?string, 'lvl_text' => ?string, 'start' => int]]).
     * Side effects: Parses XML in memory.
     *
     * @return array{
     *     num_to_abstract: array<string, string>,
     *     num_overrides: array<string, array<int, int>>,
     *     abstract_levels: array<string, array<int, array{num_fmt: ?string, lvl_text: ?string, start: int}>>,
     * }
     */
    private function extractDocxNumberingDefinitions(?string $numberingXml): array
    {
        $empty = ['num_to_abstract' => [], 'num_overrides' => [], 'abstract_levels' => []];

        if (! is_string($numberingXml) || trim($numberingXml) === '') {
            return $empty;
        }

        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($numberingXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return $empty;
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            $abstractLevels = [];

            foreach ($xpath->query('//w:abstractNum') as $abstractNumNode) {
                $abstractNumId = (string) $abstractNumNode->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'abstractNumId')?->nodeValue;

                if ($abstractNumId === '') {
                    continue;
                }

                $levels = [];

                foreach ($xpath->query('./w:lvl', $abstractNumNode) as $lvlNode) {
                    $ilvl = (int) ($lvlNode->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'ilvl')?->nodeValue ?? '0');

                    $numFmtNodes = $xpath->query('./w:numFmt', $lvlNode);
                    $numFmt = $numFmtNodes !== false && $numFmtNodes->length > 0
                        ? (string) $numFmtNodes->item(0)?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')?->nodeValue
                        : null;

                    $lvlTextNodes = $xpath->query('./w:lvlText', $lvlNode);
                    $lvlText = $lvlTextNodes !== false && $lvlTextNodes->length > 0
                        ? (string) $lvlTextNodes->item(0)?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')?->nodeValue
                        : null;

                    $startNodes = $xpath->query('./w:start', $lvlNode);
                    $start = $startNodes !== false && $startNodes->length > 0
                        ? (int) ($startNodes->item(0)?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')?->nodeValue ?? '1')
                        : 1;

                    $levels[$ilvl] = [
                        'num_fmt' => $numFmt !== '' ? $numFmt : null,
                        'lvl_text' => $lvlText !== '' ? $lvlText : null,
                        'start' => $start,
                    ];
                }

                $abstractLevels[$abstractNumId] = $levels;
            }

            $numToAbstract = [];
            $numOverrides = [];

            foreach ($xpath->query('//w:num') as $numNode) {
                $numId = (string) $numNode->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'numId')?->nodeValue;

                if ($numId === '') {
                    continue;
                }

                $abstractIdNodes = $xpath->query('./w:abstractNumId', $numNode);

                if ($abstractIdNodes === false || $abstractIdNodes->length === 0) {
                    continue;
                }

                $numToAbstract[$numId] = (string) $abstractIdNodes->item(0)?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')?->nodeValue;

                foreach ($xpath->query('./w:lvlOverride', $numNode) as $overrideNode) {
                    $overrideIlvl = (int) ($overrideNode->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'ilvl')?->nodeValue ?? '0');
                    $startOverrideNodes = $xpath->query('./w:startOverride', $overrideNode);

                    if ($startOverrideNodes !== false && $startOverrideNodes->length > 0) {
                        $numOverrides[$numId][$overrideIlvl] = (int) ($startOverrideNodes->item(0)?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')?->nodeValue ?? '1');
                    }
                }
            }

            return [
                'num_to_abstract' => $numToAbstract,
                'num_overrides' => $numOverrides,
                'abstract_levels' => $abstractLevels,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
    }

    /**
     * Purpose: Resolve which numId/ilvl (if any) a paragraph's own numbering comes from — a
     * direct <w:pPr><w:numPr> override on the paragraph itself takes precedence, falling back to
     * the paragraph's style's own numPr (see extractDocxStyleNumberingMap()).
     * Inputs: The paragraph element, its DOMXPath, its resolved style id, and the style numbering map.
     * Returns: ['num_id' => string, 'ilvl' => int], or null when the paragraph has no numbering.
     * Side effects: None.
     *
     * @param  array<string, array{num_id: string, ilvl: int}>  $styleNumberingMap
     * @return array{num_id: string, ilvl: int}|null
     */
    private function resolveDocxParagraphNumbering(DOMElement $paragraph, DOMXPath $xpath, ?string $styleId, array $styleNumberingMap): ?array
    {
        $directNumPrNodes = $xpath->query('./w:pPr/w:numPr', $paragraph);

        if ($directNumPrNodes !== false && $directNumPrNodes->length > 0) {
            $numPrNode = $directNumPrNodes->item(0);
            $numIdNodes = $xpath->query('./w:numId', $numPrNode);

            if ($numIdNodes !== false && $numIdNodes->length > 0) {
                $numId = (string) $numIdNodes->item(0)?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')?->nodeValue;

                if ($numId !== '') {
                    $ilvlNodes = $xpath->query('./w:ilvl', $numPrNode);
                    $ilvl = $ilvlNodes !== false && $ilvlNodes->length > 0
                        ? (int) ($ilvlNodes->item(0)?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')?->nodeValue ?? '0')
                        : 0;

                    return ['num_id' => $numId, 'ilvl' => $ilvl];
                }
            }
        }

        return $styleId !== null ? ($styleNumberingMap[$styleId] ?? null) : null;
    }

    /**
     * Purpose: Render a paragraph's Word auto-number (e.g. "2.1", "2.1.1") from its resolved
     * numId/ilvl, advancing the shared per-list counters exactly as Word would when displaying
     * the document — incrementing the paragraph's own level and resetting every deeper level,
     * regardless of whether this paragraph is a heading or a table cell (they can share the same
     * list, as in the real ANNEX 01A document, where "Req. No." cells are Heading-3-styled
     * paragraphs with no literal text at all).
     * Inputs: The paragraph's resolved numbering reference, the parsed numbering definitions, and
     * the mutable per-numId/ilvl counters (updated in place, in document order).
     * Returns: The rendered number (e.g. "2.1.1"), or null when the level isn't decimal-formatted
     * or its abstract numbering definition can't be resolved.
     * Side effects: Mutates $counters.
     *
     * @param  array{num_id: string, ilvl: int}  $numberingRef
     * @param  array{num_to_abstract: array<string, string>, num_overrides: array<string, array<int, int>>, abstract_levels: array<string, array<int, array{num_fmt: ?string, lvl_text: ?string, start: int}>>}  $numberingDefs
     * @param  array<string, array<int, int>>  $counters
     */
    private function renderDocxNumberingValue(array $numberingRef, array $numberingDefs, array &$counters): ?string
    {
        $numId = $numberingRef['num_id'];
        $ilvl = $numberingRef['ilvl'];

        $abstractNumId = $numberingDefs['num_to_abstract'][$numId] ?? null;

        if ($abstractNumId === null) {
            return null;
        }

        $levelDef = $numberingDefs['abstract_levels'][$abstractNumId][$ilvl] ?? null;

        if ($levelDef === null || ! $this->isRenderableDocxNumberingFormat($levelDef['num_fmt'] ?? 'decimal')) {
            // 'bullet'/'none' (and anything we don't recognize) have no sequential numeric
            // meaning — there is nothing honest to render for this paragraph's own level.
            return null;
        }

        $start = $numberingDefs['num_overrides'][$numId][$ilvl] ?? $levelDef['start'];

        $counters[$numId] ??= [];

        if (! array_key_exists($ilvl, $counters[$numId])) {
            $counters[$numId][$ilvl] = $start;
        } else {
            $counters[$numId][$ilvl]++;
        }

        foreach (array_keys($counters[$numId]) as $existingIlvl) {
            if ($existingIlvl > $ilvl) {
                unset($counters[$numId][$existingIlvl]);
            }
        }

        $lvlText = $levelDef['lvl_text'] ?? ('%'.($ilvl + 1));
        $rendered = $lvlText;

        // Each ancestor level is substituted using ITS OWN format, not the paragraph's own level
        // — a single list can legitimately mix formats per level (e.g. "1.a", "1.b", "2.a" is
        // decimal at level 0, lowerLetter at level 1), and OOXML puts no constraint against it.
        for ($level = 0; $level <= $ilvl; $level++) {
            $levelValue = $counters[$numId][$level] ?? ($numberingDefs['abstract_levels'][$abstractNumId][$level]['start'] ?? 1);
            $levelFormat = $numberingDefs['abstract_levels'][$abstractNumId][$level]['num_fmt'] ?? 'decimal';
            $rendered = str_replace('%'.($level + 1), $this->formatDocxNumberingCounterValue($levelValue, $levelFormat), $rendered);
        }

        return $rendered;
    }

    /**
     * Purpose: Determine whether a w:numFmt value has a sequential numeric rendering at all —
     * 'bullet'/'none' (and unrecognized values, treated conservatively the same way) don't.
     * Inputs: The raw w:numFmt value (or null, treated as 'decimal' — the common case where a
     * level definition omits it).
     * Returns: Whether formatDocxNumberingCounterValue() can produce a meaningful value.
     * Side effects: None.
     */
    private function isRenderableDocxNumberingFormat(?string $numFmt): bool
    {
        return in_array($numFmt, [
            'decimal', 'decimalZero', 'lowerLetter', 'upperLetter', 'lowerRoman', 'upperRoman', 'ordinal',
        ], true);
    }

    /**
     * Purpose: Render one level's counter value in its own w:numFmt — the generic building block
     * that makes multi-level, mixed-format numbering (e.g. "A.3.4", "3.2 (a)") possible without
     * hardcoding any specific pattern.
     * Inputs: The current 1-based counter value and the level's w:numFmt (decimal when omitted).
     * Returns: The formatted string for this one level (never includes surrounding lvlText).
     * Side effects: None.
     */
    private function formatDocxNumberingCounterValue(int $value, ?string $numFmt): string
    {
        return match ($numFmt) {
            'decimalZero' => sprintf('%02d', $value),
            'lowerLetter' => $this->docxNumberingLetterSequence($value, false),
            'upperLetter' => $this->docxNumberingLetterSequence($value, true),
            'lowerRoman' => $this->docxNumberingRomanNumeral($value, false),
            'upperRoman' => $this->docxNumberingRomanNumeral($value, true),
            'ordinal' => $this->docxNumberingOrdinal($value),
            default => (string) $value,
        };
    }

    /**
     * Purpose: Word's own lowerLetter/upperLetter numbering — a REPEATING single-letter sequence
     * (a, b, ..., z, aa, bb, ..., zz, aaa, ...), not a base-26 sequential one (a, b, ..., z, aa,
     * ab, ac, ...) the way spreadsheet column names work. Getting this wrong would silently
     * mislabel every list item past "z".
     * Inputs: A 1-based counter value and whether to uppercase the result.
     * Returns: The rendered letter sequence, or '' for a non-positive value.
     * Side effects: None.
     */
    private function docxNumberingLetterSequence(int $value, bool $uppercase): string
    {
        if ($value < 1) {
            return '';
        }

        $repeatCount = intdiv($value - 1, 26) + 1;
        $letter = chr(ord('a') + (($value - 1) % 26));

        $sequence = str_repeat($letter, $repeatCount);

        return $uppercase ? strtoupper($sequence) : $sequence;
    }

    /**
     * Purpose: Render a positive integer as a Roman numeral, for w:numFmt lowerRoman/upperRoman.
     * Inputs: A 1-based counter value and whether to uppercase the result.
     * Returns: The Roman numeral, or '' for a non-positive value (Roman numerals have no zero).
     * Side effects: None.
     */
    private function docxNumberingRomanNumeral(int $value, bool $uppercase): string
    {
        if ($value < 1) {
            return '';
        }

        $symbolsByValue = [
            1000 => 'm', 900 => 'cm', 500 => 'd', 400 => 'cd',
            100 => 'c', 90 => 'xc', 50 => 'l', 40 => 'xl',
            10 => 'x', 9 => 'ix', 5 => 'v', 4 => 'iv', 1 => 'i',
        ];

        $remaining = $value;
        $roman = '';

        foreach ($symbolsByValue as $symbolValue => $symbol) {
            while ($remaining >= $symbolValue) {
                $roman .= $symbol;
                $remaining -= $symbolValue;
            }
        }

        return $uppercase ? strtoupper($roman) : $roman;
    }

    /**
     * Purpose: Render an English ordinal (1st, 2nd, 3rd, 4th, ...) for w:numFmt 'ordinal'.
     * Inputs: A counter value.
     * Returns: The ordinal string.
     * Side effects: None.
     */
    private function docxNumberingOrdinal(int $value): string
    {
        if ($value % 100 >= 11 && $value % 100 <= 13) {
            return $value.'th';
        }

        return $value.match ($value % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }

    /**
     * Purpose: Extract text from a DOCX document.xml payload.
     * Inputs: Raw WordprocessingML XML content.
     * Returns: Normalized plain text content.
     * Side effects: Parses XML in memory.
     */
    private function extractWordXmlText(string $xml): string
    {
        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return '';
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            $paragraphs = [];

            foreach ($xpath->query('//w:p') as $paragraph) {
                $textNodes = $xpath->query('.//w:t', $paragraph);
                $parts = [];

                if ($textNodes !== false) {
                    foreach ($textNodes as $textNode) {
                        $parts[] = (string) $textNode->textContent;
                    }
                }

                $paragraphText = $this->normalizeWhitespace(implode(' ', $parts));

                if ($paragraphText !== '') {
                    $paragraphs[] = $paragraphText;
                }
            }

            return $this->normalizeBlockText(implode("\n\n", $paragraphs));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
    }

    /**
     * Purpose: Extract text from an XLSX file by reading worksheet XML and shared strings.
     * Inputs: Absolute filesystem path to an XLSX file.
     * Returns: Plain text content, or an empty string when extraction fails.
     * Side effects: Opens the ZIP archive and parses worksheet XML.
     */
    private function extractXlsxText(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return '';
        }

        $sharedStrings = $this->extractXlsxSharedStrings($zip);
        $sheets = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index);

            if (! is_string($entryName) || ! str_starts_with($entryName, 'xl/worksheets/sheet') || ! str_ends_with($entryName, '.xml')) {
                continue;
            }

            $sheetXml = $zip->getFromName($entryName);

            if (! is_string($sheetXml) || trim($sheetXml) === '') {
                continue;
            }

            $sheets[] = $this->extractXlsxSheetText($sheetXml, $sharedStrings);
        }

        $zip->close();

        return $this->normalizeBlockText(implode("\n\n", array_filter($sheets)));
    }

    /**
     * Purpose: Extract shared strings from an XLSX archive.
     * Inputs: An open ZipArchive instance for the XLSX file.
     * Returns: Zero-based array of shared string values.
     * Side effects: Parses XML in memory.
     */
    private function extractXlsxSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if (! is_string($xml) || trim($xml) === '') {
            return [];
        }

        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return [];
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

            $sharedStrings = [];

            foreach ($xpath->query('//a:si') as $sharedStringNode) {
                $textNodes = $xpath->query('.//a:t', $sharedStringNode);
                $parts = [];

                if ($textNodes !== false) {
                    foreach ($textNodes as $textNode) {
                        $parts[] = (string) $textNode->textContent;
                    }
                }

                $sharedStrings[] = $this->normalizeWhitespace(implode(' ', $parts));
            }

            return $sharedStrings;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
    }

    /**
     * Purpose: Extract text from a single XLSX worksheet XML payload.
     * Inputs: Raw worksheet XML and the shared string table.
     * Returns: Normalized plain text content.
     * Side effects: Parses XML in memory.
     */
    private function extractXlsxSheetText(string $xml, array $sharedStrings): string
    {
        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return '';
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

            $rows = [];

            foreach ($xpath->query('//a:row') as $rowNode) {
                $cellValues = [];

                foreach ($xpath->query('.//a:c', $rowNode) as $cellNode) {
                    $cellType = (string) $cellNode->attributes?->getNamedItem('t')?->nodeValue;
                    $value = '';

                    if ($cellType === 's') {
                        $index = (int) trim((string) $xpath->evaluate('string(a:v)', $cellNode));
                        $value = $sharedStrings[$index] ?? '';
                    } elseif ($cellType === 'inlineStr') {
                        $value = trim((string) $xpath->evaluate('string(a:is)', $cellNode));
                    } else {
                        $value = trim((string) $xpath->evaluate('string(a:v)', $cellNode));
                    }

                    if ($value !== '') {
                        $cellValues[] = $value;
                    }
                }

                $rowText = $this->normalizeWhitespace(implode(' ', $cellValues));

                if ($rowText !== '') {
                    $rows[] = $rowText;
                }
            }

            return $this->normalizeBlockText(implode("\n\n", $rows));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
    }

    /**
     * Purpose: Extract text from a PDF file using simple stream and text-operator parsing.
     * Inputs: Absolute filesystem path to a PDF file.
     * Returns: Plain text content, or an empty string when extraction fails.
     * Side effects: Reads and decodes PDF content from disk.
     */
    private function extractPdfText(string $path): string
    {
        $binary = trim((string) config('services.pdftotext.binary'));

        if ($binary === '') {
            Log::warning('[Procynia][PDF] pdftotext binary is not configured.', [
                'config_key' => 'services.pdftotext.binary',
            ]);

            return '';
        }

        if (! is_executable($binary)) {
            Log::warning('[Procynia][PDF] pdftotext binary is not executable.', [
                'binary_path' => $binary,
            ]);

            return '';
        }

        $result = Process::run([(string) $binary, '-nopgbrk', '-enc', 'UTF-8', $path, '-']);

        if (! $result->successful() || trim($result->output()) === '') {
            return '';
        }

        return $this->normalizeBlockText($result->output());
    }

    /**
     * Purpose: Decode a raw PDF stream if it uses a simple deflate encoding.
     * Inputs: A raw stream payload extracted from the PDF.
     * Returns: Decoded stream text when possible, otherwise the original payload.
     * Side effects: Attempts decompression in memory only.
     */
    private function decodePdfStream(string $stream): string
    {
        $trimmedStream = ltrim($stream);

        if ($trimmedStream === '') {
            return $stream;
        }

        $firstByte = ord($trimmedStream[0]);

        if ($firstByte !== 0x78 && ! str_starts_with($trimmedStream, "\x1f\x8b")) {
            return $stream;
        }

        $decoded = @gzuncompress($trimmedStream);

        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }

        $decoded = @gzinflate($trimmedStream);

        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }

        return $stream;
    }

    /**
     * Purpose: Extract simple text operators from a PDF chunk.
     * Inputs: A decoded or raw PDF chunk.
     * Returns: A plain text approximation from the chunk.
     * Side effects: None.
     */
    private function extractPdfTextFromChunk(string $chunk): string
    {
        $pattern = '/\((?:\\\\.|[^\\\\()])*\)\s*Tj|\[((?:.|\n)*?)\]\s*TJ|BT|ET|T\*|(?:-?\d+(?:\.\d+)?\s+){2}Td|(?:-?\d+(?:\.\d+)?\s+){2}TD/s';

        if (! preg_match_all($pattern, $chunk, $matches)) {
            return '';
        }

        $blocks = [];
        $currentBlockLines = [];
        $currentLine = '';

        foreach ($matches[0] as $token) {
            $token = trim((string) $token);

            if ($token === '') {
                continue;
            }

            if ($token === 'BT' || $token === 'ET') {
                $this->flushPdfLine($currentLine, $currentBlockLines);
                $this->flushPdfBlock($currentBlockLines, $blocks);

                continue;
            }

            if ($token === 'T*' || preg_match('/^(?:-?\d+(?:\.\d+)?\s+){2}(?:Td|TD)$/s', $token) === 1) {
                $this->flushPdfLine($currentLine, $currentBlockLines);

                continue;
            }

            $text = $this->decodePdfTextToken($token);

            if ($text === '') {
                continue;
            }

            $currentLine = $currentLine === '' ? $text : $currentLine.' '.$text;
        }

        $this->flushPdfLine($currentLine, $currentBlockLines);
        $this->flushPdfBlock($currentBlockLines, $blocks);

        return $this->normalizeBlockText(implode("\n\n", $blocks));
    }

    /**
     * Purpose: Decode escaped PDF string content into readable text.
     * Inputs: A raw PDF string token without surrounding parentheses.
     * Returns: A decoded approximation of the string value.
     * Side effects: None.
     */
    private function decodePdfString(string $value): string
    {
        $value = preg_replace_callback('/\\\\([0-7]{1,3})/', static function (array $matches): string {
            return chr(octdec($matches[1]));
        }, $value) ?? $value;

        $value = strtr($value, [
            '\\\\' => '\\',
            '\\(' => '(',
            '\\)' => ')',
            '\n' => "\n",
            '\r' => "\r",
            '\t' => "\t",
            '\b' => "\x08",
            '\f' => "\f",
        ]);

        return $this->normalizeLineText($value);
    }

    /**
     * Purpose: Decode one PDF text-show token into readable text.
     * Inputs: A raw PDF operator token.
     * Returns: A decoded approximation of the text content.
     * Side effects: None.
     */
    private function decodePdfTextToken(string $token): string
    {
        if (preg_match('/^\(((?:\\\\.|[^\\\\()])*)\)\s*Tj$/s', $token, $matches) === 1) {
            return $this->decodePdfString($matches[1]);
        }

        if (preg_match('/^\[((?:.|\n)*?)\]\s*TJ$/s', $token, $matches) === 1) {
            $parts = [];

            if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)/s', $matches[1], $stringMatches)) {
                foreach ($stringMatches[1] as $stringValue) {
                    $decoded = $this->decodePdfString((string) $stringValue);

                    if ($decoded !== '') {
                        $parts[] = $decoded;
                    }
                }
            }

            return $this->normalizeLineText(implode(' ', $parts));
        }

        return '';
    }

    /**
     * Purpose: Add the current PDF line to the active block when it contains text.
     * Inputs: The current line buffer and the current block line collection.
     * Returns: None.
     * Side effects: Appends to the provided arrays by reference.
     */
    private function flushPdfLine(string &$currentLine, array &$currentBlockLines): void
    {
        $line = $this->normalizeLineText($currentLine);

        if ($line !== '') {
            $currentBlockLines[] = $line;
        }

        $currentLine = '';
    }

    /**
     * Purpose: Add the current PDF text block to the collected block list when it contains text.
     * Inputs: The current block lines and the collected block list.
     * Returns: None.
     * Side effects: Appends to the provided arrays by reference.
     */
    private function flushPdfBlock(array &$currentBlockLines, array &$blocks): void
    {
        if ($currentBlockLines === []) {
            return;
        }

        $blocks[] = implode("\n", $currentBlockLines);
        $currentBlockLines = [];
    }

    /**
     * Purpose: Collapse whitespace for extracted text values.
     * Inputs: Raw extracted text.
     * Returns: A normalized string with stable spacing.
     * Side effects: None.
     */
    private function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * Purpose: Normalize a single visible text line while preserving block boundaries elsewhere.
     * Inputs: Raw extracted text.
     * Returns: A trimmed line with collapsed internal spacing.
     * Side effects: None.
     */
    private function normalizeLineText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * Purpose: Normalize extracted text while preserving blank-line block boundaries.
     * Inputs: Raw extracted text.
     * Returns: A cleaned text value with single blank lines between blocks.
     * Side effects: None.
     */
    /**
     * Join visually-wrapped PDF lines into a single string.
     * Lines ending with a hyphen followed by a lowercase letter on the next line
     * are treated as soft hyphens — the hyphen is removed and the words merged.
     * All other consecutive lines are joined with a single space.
     *
     * @param  array<int, string>  $lines
     */
    private function joinPdfParagraphLines(array $lines): string
    {
        $result = '';
        foreach ($lines as $line) {
            if ($result === '') {
                $result = $line;

                continue;
            }
            if (str_ends_with($result, '-') && preg_match('/^\p{Ll}/u', $line)) {
                $result = mb_substr($result, 0, -1).$line;
            } else {
                $result .= ' '.$line;
            }
        }

        return $result;
    }

    private function normalizeBlockText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = preg_split('/\n/u', $value) ?: [];
        $normalizedLines = [];
        $previousWasBlank = false;

        foreach ($lines as $line) {
            $normalizedLine = $this->normalizeLineText((string) $line);

            if ($normalizedLine === '') {
                if (! $previousWasBlank) {
                    $normalizedLines[] = '';
                    $previousWasBlank = true;
                }

                continue;
            }

            $normalizedLines[] = $normalizedLine;
            $previousWasBlank = false;
        }

        return trim(implode("\n", $normalizedLines));
    }
}
