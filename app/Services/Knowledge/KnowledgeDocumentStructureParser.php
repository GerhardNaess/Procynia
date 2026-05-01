<?php

namespace App\Services\Knowledge;

use App\Services\DocumentTextExtractor;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

class KnowledgeDocumentStructureParser
{
    public function __construct(
        private readonly DocumentTextExtractor $documentTextExtractor,
    ) {
    }

    /**
     * Purpose: Parse a knowledge document into ordered structural elements and canonical source text.
     * Inputs: Absolute filesystem path to the stored document.
     * Returns: An array with the canonical source text and ordered structural elements.
     * Side effects: Reads the document from disk and may open ZIP archives in memory.
     *
     * @return array{
     *     document_format: string,
     *     source_text: string,
     *     elements: array<int, array{
     *         id: string,
     *         type: string,
     *         heading_path: ?string,
     *         heading_context: ?string,
     *         text: string,
     *         start_offset: int,
     *         end_offset: int,
     *         order_index: int,
     *         heading_level: ?int,
     *         relation_hint: ?string,
     *         table_json?: array<string, mixed>,
     *         table_html?: string,
     *         table_complexity?: string,
     *         table_warnings?: array<int, string>,
     *         table_markdown?: string,
     *         table_text?: string,
     *         rows?: array<int, array<int, string>>,
     *         row_count?: int,
     *         column_count?: int,
     *         table_index_in_document?: int,
     *         image_bytes?: string,
     *         image_path?: ?string,
     *         image_disk?: ?string,
     *         image_mime_type?: ?string,
     *         image_original_filename?: ?string,
     *         image_width?: ?int,
     *         image_height?: ?int,
     *         image_hash?: ?string,
     *         image_metadata?: array<string, mixed>,
     *         image_alt_text?: ?string,
     *         image_caption?: ?string,
     *         ocr_text?: ?string,
     *         image_description?: ?string,
     *         source_metadata?: array<string, mixed>,
     *         image_index_in_document?: int
     *     }>,
     *     word_count: int
     * }
     */
    public function parse(string $path): array
    {
        try {
            if (! is_file($path) || ! is_readable($path)) {
                return $this->normalizeElements([], 'text');
            }

            $extension = Str::lower((string) pathinfo($path, PATHINFO_EXTENSION));

            return match ($extension) {
                'docx' => $this->parseDocx($path),
                'pdf', 'xlsx' => $this->parsePlainText($path),
                default => $this->parsePlainText($path),
            };
        } catch (Throwable) {
            return $this->parsePlainText($path);
        }
    }

    /**
     * Purpose: Parse a DOCX knowledge document into structural elements.
     * Inputs: Absolute filesystem path to a DOCX file.
     * Returns: Canonical source text and ordered structural elements.
     * Side effects: Opens the DOCX archive and parses XML in memory.
     *
     * @return array{
     *     document_format: string,
     *     source_text: string,
     *     elements: array<int, array{
     *         id: string,
     *         type: string,
     *         heading_path: ?string,
     *         text: string,
     *         start_offset: int,
     *         end_offset: int,
     *         order_index: int,
     *         heading_level: ?int,
     *         relation_hint: ?string
     *     }>,
     *     word_count: int
     * }
     */
    private function parseDocx(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            return $this->parsePlainText($path);
        }

        $documentXml = $zip->getFromName('word/document.xml');
        $stylesXml = $zip->getFromName('word/styles.xml');
        $relationshipsXml = $zip->getFromName('word/_rels/document.xml.rels');
        $mediaFiles = $this->extractDocxMediaFiles($zip);
        $zip->close();

        if (! is_string($documentXml) || trim($documentXml) === '') {
            return $this->parsePlainText($path);
        }

        $elements = $this->parseDocxBodyElements(
            $documentXml,
            is_string($stylesXml) ? $stylesXml : null,
            is_string($relationshipsXml) ? $relationshipsXml : null,
            $mediaFiles,
        );

        if ($elements === []) {
            return $this->parsePlainText($path);
        }

        return $this->normalizeElements($elements, 'docx');
    }

    /**
     * Purpose: Parse a DOCX document body into ordered raw structural elements.
     * Inputs: Raw DOCX XML content, optional styles XML content, optional document relationships XML content, and extracted DOCX media files.
     * Returns: Ordered raw elements with lightweight structural metadata.
     * Side effects: Parses XML in memory.
     *
     * @return array<int, array{
     *     type: string,
     *     heading_path: ?string,
     *     heading_context: ?string,
     *     text: string,
     *     heading_level: ?int,
     *     relation_hint: ?string,
     *     table_markdown?: string,
     *     table_text?: string,
     *     rows?: array<int, array<int, string>>,
     *     row_count?: int,
     *     column_count?: int,
     *     table_index_in_document?: int,
     *     image_bytes?: string,
     *     image_path?: ?string,
     *     image_disk?: ?string,
     *     image_mime_type?: ?string,
     *     image_original_filename?: ?string,
     *     image_width?: ?int,
     *     image_height?: ?int,
     *     image_hash?: ?string,
     *     image_metadata?: array<string, mixed>,
     *     image_alt_text?: ?string,
     *     image_caption?: ?string,
     *     ocr_text?: ?string,
     *     image_description?: ?string,
     *     source_metadata?: array<string, mixed>,
     *     image_index_in_document?: int
     * }>
     */
    private function parseDocxBodyElements(
        string $documentXml,
        ?string $stylesXml = null,
        ?string $relationshipsXml = null,
        array $mediaFiles = [],
    ): array
    {
        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument();

            if (! $dom->loadXML($documentXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return [];
            }

            $styleMap = $this->extractDocxStyleMap($stylesXml);
            $relationshipMap = $this->extractDocxRelationshipMap($relationshipsXml);
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $xpath->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');

            $rawElements = [];
            $currentHeadings = [];
            $tableIndexInDocument = 0;
            $documentOrderIndex = 0;

            $bodyNodes = $xpath->query('/w:document/w:body/*');

            if ($bodyNodes === false) {
                return [];
            }

            foreach ($bodyNodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                if ($node->nodeName === 'w:p') {
                    $text = $this->extractParagraphText($xpath, $node);
                    $styleId = $this->paragraphStyleId($xpath, $node);
                    $styleName = $styleMap[$styleId] ?? $styleId;
                    $headingLevel = $this->resolveHeadingLevel($styleId, $styleName);
                    $headingPath = $this->currentHeadingPath($currentHeadings);
                    $imageElements = $this->extractDocxImageElements(
                        $xpath,
                        $node,
                        $relationshipMap,
                        $mediaFiles,
                        $headingPath,
                        $documentOrderIndex,
                        $text,
                        $styleName,
                    );

                    foreach ($imageElements as $imageElement) {
                        $rawElements[] = $imageElement;
                    }

                    if ($text === '' && $imageElements !== []) {
                        $documentOrderIndex++;

                        continue;
                    }

                    if ($text === '') {
                        $documentOrderIndex++;

                        continue;
                    }

                    if ($headingLevel !== null) {
                        $currentHeadings[$headingLevel] = $text;
                        $currentHeadings = $this->trimHeadingStack($currentHeadings, $headingLevel);
                        $headingPath = $this->currentHeadingPath($currentHeadings);

                        $rawElements[] = [
                            'type' => 'heading',
                            'heading_path' => $headingPath,
                            'heading_context' => $headingPath,
                            'text' => $text,
                            'heading_level' => $headingLevel,
                            'relation_hint' => null,
                        ];

                        continue;
                    }

                    $type = $this->classifyParagraphType($xpath, $node, $text, $styleName);
                    $headingPath = $this->currentHeadingPath($currentHeadings);
                    $relationHint = $this->relationHintForParagraph($type, $text);

                    $rawElements[] = [
                        'type' => $type,
                        'heading_path' => $headingPath,
                        'heading_context' => $headingPath,
                        'text' => $text,
                        'heading_level' => null,
                        'relation_hint' => $relationHint,
                    ];

                    $documentOrderIndex++;

                    continue;
                }

                if ($node->nodeName === 'w:tbl') {
                    $tableData = $this->extractDocxTableData($xpath, $node);
                    $text = (string) ($tableData['text'] ?? '');
                    $rowCount = (int) ($tableData['row_count'] ?? 0);

                    if ($text === '' && $rowCount === 0) {
                        continue;
                    }

                    $headingPath = $this->currentHeadingPath($currentHeadings);
                    $tableJson = is_array($tableData['table_json'] ?? null) ? $tableData['table_json'] : [];

                    if ($tableJson !== []) {
                        $tableJson['table_index_in_document'] = $tableIndexInDocument;
                        $tableJson['source_metadata'] = array_merge(
                            is_array($tableJson['source_metadata'] ?? null) ? $tableJson['source_metadata'] : [],
                            [
                                'table_index_in_document' => $tableIndexInDocument,
                            ],
                        );
                        $tableData['table_json'] = $tableJson;
                    }

                    $rawElements[] = [
                        'type' => 'table',
                        'heading_path' => $headingPath,
                        'heading_context' => $headingPath,
                        'text' => $text,
                        'heading_level' => null,
                        'relation_hint' => 'table_group',
                        'table_json' => $tableData['table_json'] ?? null,
                        'table_html' => (string) ($tableData['table_html'] ?? ''),
                        'table_complexity' => (string) ($tableData['table_complexity'] ?? 'complex'),
                        'table_warnings' => array_values(array_filter((array) ($tableData['table_warnings'] ?? []), static fn ($warning): bool => trim((string) $warning) !== '')),
                        'table_markdown' => (string) ($tableData['markdown'] ?? ''),
                        'table_text' => $text,
                        'rows' => (array) ($tableData['rows'] ?? []),
                        'row_count' => $rowCount,
                        'column_count' => (int) ($tableData['column_count'] ?? 0),
                        'table_index_in_document' => $tableIndexInDocument++,
                    ];

                    $documentOrderIndex++;
                }
            }

            $rawElements = $this->linkDocxImageCaptions($rawElements);
            $mergedElements = $this->mergeContiguousListElements($rawElements);

            return $this->groupH2Sections($mergedElements);
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
            $dom = new DOMDocument();

            if (! $dom->loadXML($stylesXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return [];
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            $map = [];

            foreach ($xpath->query('//w:style') as $styleNode) {
                if (! $styleNode instanceof DOMElement) {
                    continue;
                }

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
     * Purpose: Read the DOCX relationships file and resolve media relationships for embedded images.
     * Inputs: Raw document relationships XML content, if available.
     * Returns: A map from relationship id to normalized relationship metadata.
     * Side effects: Parses XML in memory.
     *
     * @return array<string, array{target: string, type: string, target_mode: ?string}>
     */
    private function extractDocxRelationshipMap(?string $relationshipsXml): array
    {
        if (! is_string($relationshipsXml) || trim($relationshipsXml) === '') {
            return [];
        }

        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument();

            if (! $dom->loadXML($relationshipsXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return [];
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');

            $map = [];

            foreach ($xpath->query('/rel:Relationships/rel:Relationship') as $relationshipNode) {
                if (! $relationshipNode instanceof DOMElement) {
                    continue;
                }

                $id = (string) ($relationshipNode->attributes?->getNamedItem('Id')?->nodeValue ?? '');
                $type = (string) ($relationshipNode->attributes?->getNamedItem('Type')?->nodeValue ?? '');
                $target = (string) ($relationshipNode->attributes?->getNamedItem('Target')?->nodeValue ?? '');
                $targetMode = (string) ($relationshipNode->attributes?->getNamedItem('TargetMode')?->nodeValue ?? '');

                if ($id === '' || $target === '' || ! Str::contains(Str::lower($type), '/image')) {
                    continue;
                }

                $map[$id] = [
                    'target' => $this->normalizeDocxRelationshipTarget($target),
                    'type' => $type,
                    'target_mode' => $targetMode !== '' ? $targetMode : null,
                ];
            }

            return $map;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
    }

    /**
     * Purpose: Extract all DOCX media files from the archive into an in-memory lookup map.
     * Inputs: An open DOCX ZIP archive.
     * Returns: A map from media path to raw file bytes.
     * Side effects: Reads ZIP entries into memory.
     *
     * @return array<string, string>
     */
    private function extractDocxMediaFiles(ZipArchive $zip): array
    {
        $mediaFiles = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index);

            if (! is_string($entryName) || ! Str::startsWith($entryName, 'word/media/')) {
                continue;
            }

            $contents = $zip->getFromIndex($index);

            if (! is_string($contents) || $contents === '') {
                continue;
            }

            $mediaFiles[$entryName] = $contents;
        }

        return $mediaFiles;
    }

    /**
     * Purpose: Extract embedded DOCX images from one paragraph in document order.
     * Inputs: The XML XPath helper, the paragraph node, document relationships, media file bytes, the active heading path, the document order index, the paragraph text, and the resolved style name.
     * Returns: Ordered image elements that belong to the paragraph.
     * Side effects: None.
     *
     * @param array<string, array{target: string, type: string, target_mode: ?string}> $relationshipMap
     * @param array<string, string> $mediaFiles
     * @return array<int, array<string, mixed>>
     */
    private function extractDocxImageElements(
        DOMXPath $xpath,
        DOMElement $paragraph,
        array $relationshipMap,
        array $mediaFiles,
        ?string $headingPath,
        int $documentOrderIndex,
        string $paragraphText,
        ?string $styleName,
    ): array {
        $drawingNodes = $xpath->query('.//w:drawing', $paragraph);

        if ($drawingNodes === false || $drawingNodes->length === 0) {
            return [];
        }

        $paragraphText = trim($paragraphText);
        $captionText = $this->isFigureText($paragraphText, $styleName) ? $paragraphText : null;
        $results = [];
        $imageIndex = 0;

        foreach ($drawingNodes as $drawingNode) {
            if (! $drawingNode instanceof DOMElement) {
                continue;
            }

            $blipNode = $xpath->query('.//a:blip', $drawingNode);
            $docPrNode = $xpath->query('.//wp:docPr', $drawingNode);
            $extentNode = $xpath->query('.//wp:extent', $drawingNode);

            $relationshipId = null;

            if ($blipNode !== false && $blipNode->length > 0) {
                $relationshipId = (string) ($blipNode->item(0)?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed')?->nodeValue
                    ?? $blipNode->item(0)?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'link')?->nodeValue
                    ?? '');
            }

            if (! is_string($relationshipId) || $relationshipId === '' || ! isset($relationshipMap[$relationshipId])) {
                continue;
            }

            $relationship = $relationshipMap[$relationshipId];
            $targetPath = (string) ($relationship['target'] ?? '');
            $mediaPath = $this->normalizeDocxMediaPath($targetPath);
            $imageBytes = $mediaPath !== '' && isset($mediaFiles[$mediaPath])
                ? $mediaFiles[$mediaPath]
                : null;

            if (! is_string($imageBytes) || $imageBytes === '') {
                continue;
            }

            $originalFilename = basename($mediaPath !== '' ? $mediaPath : $targetPath);
            $extension = Str::lower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
            $dimensions = $this->extractDocxImageDimensions($imageBytes, $extentNode !== false ? $extentNode->item(0) : null);
            $mimeType = $dimensions['mime_type'] ?? $this->mimeTypeForImageExtension($extension);
            $resolvedExtension = $extension !== '' ? $extension : $this->imageExtensionForMimeType($mimeType);
            $imageHash = hash('sha256', $imageBytes);
            $altText = $this->normalizeNullableString(
                (string) (
                    $docPrNode !== false && $docPrNode->length > 0
                        ? (
                            $docPrNode->item(0)?->attributes?->getNamedItem('descr')?->nodeValue
                            ?: $docPrNode->item(0)?->attributes?->getNamedItem('title')?->nodeValue
                            ?: $docPrNode->item(0)?->attributes?->getNamedItem('name')?->nodeValue
                        )
                        : null
                ),
            );
            $caption = $captionText !== null ? $captionText : null;
            $searchText = $this->buildDocxImageSearchText($headingPath, $caption, $altText);

            $results[] = [
                'type' => 'image',
                'heading_path' => $headingPath,
                'heading_context' => $headingPath,
                'text' => $searchText,
                'heading_level' => null,
                'relation_hint' => 'image',
                'image_bytes' => $imageBytes,
                'image_path' => null,
                'image_disk' => null,
                'image_mime_type' => $mimeType,
                'image_original_filename' => $originalFilename,
                'image_width' => $dimensions['width'] ?? null,
                'image_height' => $dimensions['height'] ?? null,
                'image_hash' => $imageHash,
                'image_metadata' => [
                    'source_type' => 'docx_image',
                    'image_kind' => 'unknown',
                    'detected_type' => 'unknown',
                    'relationship_id' => $relationshipId,
                    'media_path' => $mediaPath !== '' ? $mediaPath : $targetPath,
                    'document_order_index' => $documentOrderIndex,
                    'heading_path' => $headingPath,
                    'extension' => $resolvedExtension,
                    'nearby_text_before' => null,
                    'nearby_text_after' => null,
                    'caption_detected' => $caption !== null,
                    'alt_text_detected' => $altText !== null,
                    'mime_type' => $mimeType,
                    'width' => $dimensions['width'] ?? null,
                    'height' => $dimensions['height'] ?? null,
                    'image_hash' => $imageHash,
                    'docpr_title' => $this->normalizeNullableString((string) ($docPrNode !== false && $docPrNode->length > 0 ? ($docPrNode->item(0)?->attributes?->getNamedItem('title')?->nodeValue ?? '') : '')),
                    'docpr_descr' => $this->normalizeNullableString((string) ($docPrNode !== false && $docPrNode->length > 0 ? ($docPrNode->item(0)?->attributes?->getNamedItem('descr')?->nodeValue ?? '') : '')),
                    'docpr_name' => $this->normalizeNullableString((string) ($docPrNode !== false && $docPrNode->length > 0 ? ($docPrNode->item(0)?->attributes?->getNamedItem('name')?->nodeValue ?? '') : '')),
                ],
                'image_alt_text' => $altText,
                'image_caption' => $caption,
                'ocr_text' => null,
                'image_description' => null,
                'source_metadata' => [
                    'source_type' => 'docx_image',
                    'image_kind' => 'unknown',
                    'detected_type' => 'unknown',
                    'relationship_id' => $relationshipId,
                    'media_path' => $mediaPath !== '' ? $mediaPath : $targetPath,
                    'document_order_index' => $documentOrderIndex,
                    'heading_path' => $headingPath,
                    'extension' => $resolvedExtension,
                    'nearby_text_before' => null,
                    'nearby_text_after' => null,
                    'caption_detected' => $caption !== null,
                    'alt_text_detected' => $altText !== null,
                    'mime_type' => $mimeType,
                    'width' => $dimensions['width'] ?? null,
                    'height' => $dimensions['height'] ?? null,
                    'image_hash' => $imageHash,
                ],
                'image_index_in_document' => $imageIndex++,
            ];
        }

        return $results;
    }

    /**
     * Purpose: Link nearby caption paragraphs to image elements and optionally suppress duplicate caption text.
     * Inputs: The ordered raw elements extracted from the DOCX body.
     * Returns: The same element list with image caption metadata attached where possible.
     * Side effects: Mutates the supplied element array in place.
     *
     * @param array<int, array<string, mixed>> $elements
     * @return array<int, array<string, mixed>>
     */
    private function linkDocxImageCaptions(array $elements): array
    {
        $count = count($elements);

        for ($index = 0; $index < $count; $index++) {
            if ((string) data_get($elements[$index], 'type', '') !== 'image') {
                continue;
            }

            $captionIndex = null;
            $currentHeadingPath = $this->normalizeNullableString($elements[$index]['heading_path'] ?? null);

            $previousIndex = $this->previousVisibleElementIndex($elements, $index);
            if ($previousIndex !== null && $this->isDocxImageCaptionCandidate($elements[$previousIndex], $currentHeadingPath)) {
                $captionIndex = $previousIndex;
            } else {
                $nextIndex = $this->nextVisibleElementIndex($elements, $index);
                if ($nextIndex !== null && $this->isDocxImageCaptionCandidate($elements[$nextIndex], $currentHeadingPath)) {
                    $captionIndex = $nextIndex;
                }
            }

            $nearbyBefore = $previousIndex !== null ? trim((string) data_get($elements[$previousIndex], 'text', '')) : null;
            $nearbyAfter = $this->nextVisibleText($elements, $index);

            if ($captionIndex !== null) {
                $captionText = trim((string) data_get($elements[$captionIndex], 'text', ''));
                $elements[$index]['image_caption'] = $captionText !== '' ? $captionText : null;
                $elements[$index]['image_metadata']['caption_detected'] = true;
                $elements[$index]['source_metadata']['caption_detected'] = true;
                $elements[$index]['source_metadata']['nearby_text_before'] = $nearbyBefore !== '' ? $nearbyBefore : null;
                $elements[$index]['source_metadata']['nearby_text_after'] = $nearbyAfter !== '' ? $nearbyAfter : null;
                $elements[$index]['image_metadata']['nearby_text_before'] = $nearbyBefore !== '' ? $nearbyBefore : null;
                $elements[$index]['image_metadata']['nearby_text_after'] = $nearbyAfter !== '' ? $nearbyAfter : null;
                $elements[$index]['text'] = $this->buildDocxImageSearchText(
                    $currentHeadingPath,
                    $elements[$index]['image_caption'] ?? null,
                    $this->normalizeNullableString($elements[$index]['image_alt_text'] ?? null),
                );
                $elements[$captionIndex]['text'] = '';
                $elements[$captionIndex]['relation_hint'] = 'image_caption';
            } else {
                $elements[$index]['source_metadata']['nearby_text_before'] = $nearbyBefore !== '' ? $nearbyBefore : null;
                $elements[$index]['source_metadata']['nearby_text_after'] = $nearbyAfter !== '' ? $nearbyAfter : null;
                $elements[$index]['image_metadata']['nearby_text_before'] = $nearbyBefore !== '' ? $nearbyBefore : null;
                $elements[$index]['image_metadata']['nearby_text_after'] = $nearbyAfter !== '' ? $nearbyAfter : null;
                $elements[$index]['text'] = $this->buildDocxImageSearchText(
                    $currentHeadingPath,
                    $this->normalizeNullableString($elements[$index]['image_caption'] ?? null),
                    $this->normalizeNullableString($elements[$index]['image_alt_text'] ?? null),
                );
            }
        }

        return $elements;
    }

    /**
     * Purpose: Determine whether one raw DOCX element should be treated as a caption candidate for a nearby image.
     * Inputs: One parsed raw element and the active heading path.
     * Returns: True when the element looks like a caption and belongs to the same section context.
     * Side effects: None.
     *
     * @param array<string, mixed> $element
     */
    private function isDocxImageCaptionCandidate(array $element, ?string $headingPath): bool
    {
        $text = trim((string) data_get($element, 'text', ''));

        if ($text === '') {
            return false;
        }

        $elementType = (string) data_get($element, 'type', '');

        if ($elementType !== 'figure_text' && $elementType !== 'paragraph') {
            return false;
        }

        $elementHeadingPath = $this->normalizeNullableString($element['heading_path'] ?? null);

        if ($headingPath !== null && $elementHeadingPath !== null && $headingPath !== $elementHeadingPath) {
            return false;
        }

        return $this->isFigureText($text, $this->normalizeNullableString($element['heading_context'] ?? null));
    }

    /**
     * Purpose: Locate the previous visible element index in an ordered element list.
     * Inputs: The raw element list and the current index.
     * Returns: The previous non-empty element index or null when none exists.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $elements
     */
    private function previousVisibleElementIndex(array $elements, int $currentIndex): ?int
    {
        for ($index = $currentIndex - 1; $index >= 0; $index--) {
            if (trim((string) data_get($elements[$index], 'text', '')) !== '') {
                return $index;
            }
        }

        return null;
    }

    /**
     * Purpose: Locate the next visible element index in an ordered element list.
     * Inputs: The raw element list and the current index.
     * Returns: The next non-empty element index or null when none exists.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $elements
     */
    private function nextVisibleElementIndex(array $elements, int $currentIndex): ?int
    {
        $count = count($elements);

        for ($index = $currentIndex + 1; $index < $count; $index++) {
            if (trim((string) data_get($elements[$index], 'text', '')) !== '') {
                return $index;
            }
        }

        return null;
    }

    /**
     * Purpose: Resolve the next visible text from an element list for image context linking.
     * Inputs: The raw element list and the current index.
     * Returns: The next visible text string or null.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $elements
     */
    private function nextVisibleText(array $elements, int $currentIndex): ?string
    {
        $nextIndex = $this->nextVisibleElementIndex($elements, $currentIndex);

        return $nextIndex !== null
            ? trim((string) data_get($elements[$nextIndex], 'text', ''))
            : null;
    }

    /**
     * Purpose: Build a searchable text representation for one DOCX image element.
     * Inputs: The active heading path, an optional caption, and an optional alt text.
     * Returns: A compact search string that can be embedded and chunked.
     * Side effects: None.
     */
    private function buildDocxImageSearchText(?string $headingPath, ?string $caption, ?string $altText): string
    {
        $lines = [];

        if ($headingPath !== null && trim($headingPath) !== '') {
            $lines[] = 'Bilde i seksjon: '.trim($headingPath);
        } else {
            $lines[] = 'Bilde';
        }

        $caption = $this->normalizeNullableString($caption);
        $altText = $this->normalizeNullableString($altText);

        if ($caption !== null) {
            $lines[] = 'Bildetekst: '.$caption;
        }

        if ($altText !== null) {
            $lines[] = 'Alternativ tekst: '.$altText;
        }

        if ($caption === null && $altText === null) {
            $lines[] = 'Ingen bildetekst eller alternativ tekst er registrert.';
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Purpose: Normalize a DOCX relationship target into a ZIP entry path.
     * Inputs: A raw relationship target string.
     * Returns: A normalized media path.
     * Side effects: None.
     */
    private function normalizeDocxRelationshipTarget(string $target): string
    {
        $target = ltrim(trim($target), '/');

        if ($target === '') {
            return '';
        }

        if (Str::startsWith($target, 'word/')) {
            return $target;
        }

        return 'word/'.ltrim($target, '/');
    }

    /**
     * Purpose: Normalize a media path for a DOCX embedded image.
     * Inputs: A relationship target or media path.
     * Returns: A normalized media path inside the DOCX archive.
     * Side effects: None.
     */
    private function normalizeDocxMediaPath(string $targetPath): string
    {
        $targetPath = ltrim(trim($targetPath), '/');

        if ($targetPath === '') {
            return '';
        }

        if (Str::startsWith($targetPath, 'word/media/')) {
            return $targetPath;
        }

        return 'word/'.ltrim($targetPath, '/');
    }

    /**
     * Purpose: Resolve a MIME type for a DOCX image extension.
     * Inputs: An image file extension.
     * Returns: A best-effort image MIME type string.
     * Side effects: None.
     */
    private function mimeTypeForImageExtension(string $extension): string
    {
        return match (Str::lower(trim($extension))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'webp' => 'image/webp',
            'tif', 'tiff' => 'image/tiff',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }

    /**
     * Purpose: Resolve a stable image extension for a DOCX embedded image.
     * Inputs: An image MIME type string.
     * Returns: A lower-case file extension or "bin" when the MIME type is unknown.
     * Side effects: None.
     */
    private function imageExtensionForMimeType(string $mimeType): string
    {
        return match (Str::lower(trim($mimeType))) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/webp' => 'webp',
            'image/tiff' => 'tiff',
            'image/svg+xml' => 'svg',
            default => 'bin',
        };
    }

    /**
     * Purpose: Derive width, height, and MIME type metadata for one embedded image.
     * Inputs: Raw image bytes and the optional WordprocessingML extent node.
     * Returns: A compact dimension and MIME metadata array.
     * Side effects: None.
     *
     * @return array{width: ?int, height: ?int, mime_type: ?string}
     */
    private function extractDocxImageDimensions(string $imageBytes, ?DOMElement $extentNode = null): array
    {
        $width = null;
        $height = null;
        $mimeType = null;

        if (function_exists('getimagesizefromstring')) {
            $size = @getimagesizefromstring($imageBytes);

            if (is_array($size)) {
                $width = isset($size[0]) ? (int) $size[0] : null;
                $height = isset($size[1]) ? (int) $size[1] : null;
                $mimeType = isset($size['mime']) ? (string) $size['mime'] : null;
            }
        }

        if (($width === null || $height === null) && $extentNode instanceof DOMElement) {
            $cx = (int) ($extentNode->attributes?->getNamedItem('cx')?->nodeValue ?? 0);
            $cy = (int) ($extentNode->attributes?->getNamedItem('cy')?->nodeValue ?? 0);

            if ($width === null && $cx > 0) {
                $width = (int) round($cx / 9525);
            }

            if ($height === null && $cy > 0) {
                $height = (int) round($cy / 9525);
            }
        }

        return [
            'width' => $width,
            'height' => $height,
            'mime_type' => $mimeType,
        ];
    }

    /**
     * Purpose: Parse a non-DOCX document into a plain-text structural fallback.
     * Inputs: Absolute filesystem path to the stored document.
     * Returns: Canonical source text and paragraph-like fallback elements.
     * Side effects: Reads the file from disk.
     *
     * @return array{
     *     document_format: string,
     *     source_text: string,
     *     elements: array<int, array{
     *         id: string,
     *         type: string,
     *         heading_path: ?string,
     *         text: string,
     *         start_offset: int,
     *         end_offset: int,
     *         order_index: int,
     *         heading_level: ?int,
     *         relation_hint: ?string
     *     }>,
     *     word_count: int
     * }
     */
    private function parsePlainText(string $path): array
    {
        $text = trim($this->documentTextExtractor->extractText($path));

        if ($text === '') {
            return $this->normalizeElements([], 'text');
        }

        $parts = preg_split('/(\n{2,})/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (! is_array($parts) || $parts === []) {
            return $this->normalizeElements([
                [
                    'type' => 'other',
                    'heading_path' => null,
                    'text' => $text,
                    'heading_level' => null,
                    'relation_hint' => null,
                ],
            ], 'text');
        }

        $elements = [];

        for ($index = 0; $index < count($parts); $index += 2) {
            $content = trim((string) ($parts[$index] ?? ''));

            if ($content === '') {
                continue;
            }

            $type = $this->isListText($content) ? 'list' : 'paragraph';

            $elements[] = [
                'type' => $type,
                'heading_path' => null,
                'text' => $content,
                'heading_level' => null,
                'relation_hint' => null,
            ];
        }

        if ($elements === []) {
            $elements[] = [
                'type' => 'other',
                'heading_path' => null,
                'text' => $text,
                'heading_level' => null,
                'relation_hint' => null,
            ];
        }

        return $this->normalizeElements($elements, 'text');
    }

    /**
     * Purpose: Extract the textual content of one DOCX paragraph.
     * Inputs: The XML XPath helper and the paragraph node.
     * Returns: A normalized paragraph string.
     * Side effects: None.
     */
    private function extractParagraphText(DOMXPath $xpath, DOMElement $paragraph): string
    {
        $textNodes = $xpath->query('.//w:t', $paragraph);
        $parts = [];

        if ($textNodes !== false) {
            foreach ($textNodes as $textNode) {
                $parts[] = (string) $textNode->textContent;
            }
        }

        return $this->normalizeText(implode(' ', $parts));
    }

    /**
     * Purpose: Extract the structured content of one DOCX table.
     * Inputs: The XML XPath helper and the table node.
     * Returns: A normalized table payload with structured JSON and derived display formats.
     * Side effects: None.
     *
     * @return array{
     *     text: string,
     *     html: string,
     *     markdown: string,
     *     rows: array<int, array<string, mixed>>,
     *     row_count: int,
     *     column_count: int,
     *     table_json: array<string, mixed>,
     *     table_html: string,
     *     table_complexity: string,
     *     table_warnings: array<int, string>
     * }
     */
    private function extractDocxTableData(DOMXPath $xpath, DOMElement $table): array
    {
        $rows = $this->extractDocxTableRows($xpath, $table);

        if ($rows === []) {
            return [
                'text' => '',
                'html' => '',
                'markdown' => '',
                'rows' => [],
                'row_count' => 0,
                'column_count' => 0,
                'table_json' => [],
                'table_html' => '',
                'table_complexity' => 'complex',
                'table_warnings' => [],
            ];
        }

        $columnCount = $this->extractDocxTableColumnCount($xpath, $table, $rows);
        $tablePayload = $this->buildDocxTablePayload($rows, $columnCount);

        return [
            'text' => (string) ($tablePayload['table_text'] ?? ''),
            'html' => (string) ($tablePayload['table_html'] ?? ''),
            'markdown' => (string) ($tablePayload['table_markdown'] ?? ''),
            'rows' => (array) ($tablePayload['rows'] ?? []),
            'row_count' => (int) ($tablePayload['row_count'] ?? 0),
            'column_count' => (int) ($tablePayload['column_count'] ?? 0),
            'table_json' => (array) ($tablePayload['table_json'] ?? []),
            'table_html' => (string) ($tablePayload['table_html'] ?? ''),
            'table_complexity' => (string) ($tablePayload['table_complexity'] ?? 'complex'),
            'table_warnings' => (array) ($tablePayload['table_warnings'] ?? []),
        ];
    }

    /**
     * Purpose: Extract the textual content of one DOCX table.
     * Inputs: The XML XPath helper and the table node.
     * Returns: A normalized table string that preserves row order.
     * Side effects: None.
     */
    private function extractTableText(DOMXPath $xpath, DOMElement $table): string
    {
        return (string) $this->extractDocxTableData($xpath, $table)['text'];
    }

    /**
     * Purpose: Read raw row and cell data from one DOCX table.
     * Inputs: The XML XPath helper and the table node.
     * Returns: Ordered raw table rows with text and merge metadata.
     * Side effects: None.
     *
     * @return array<int, array{
     *     row_index: int,
     *     explicit_header: bool,
     *     cells: array<int, array<string, mixed>>
     * }>
     */
    private function extractDocxTableRows(DOMXPath $xpath, DOMElement $table): array
    {
        $tableRows = $xpath->query('./w:tr', $table);

        if ($tableRows === false) {
            return [];
        }

        $rows = [];
        $rowCount = $tableRows->length;

        foreach ($tableRows as $rowIndex => $rowNode) {
            if (! $rowNode instanceof DOMElement) {
                continue;
            }

            $cells = [];
            $cellNodes = $xpath->query('./w:tc', $rowNode);
            $explicitHeader = $this->hasDocxTableHeaderFlag($xpath, $rowNode);

            if ($cellNodes !== false) {
                foreach ($cellNodes as $cellIndex => $cellNode) {
                    if (! $cellNode instanceof DOMElement) {
                        continue;
                    }

                    $cells[] = $this->extractDocxTableCell($xpath, $cellNode, (int) $rowIndex, (int) $cellIndex, $explicitHeader, $rowCount);
                }
            }

            $rows[] = [
                'row_index' => (int) $rowIndex,
                'explicit_header' => $explicitHeader,
                'cells' => $cells,
            ];
        }

        return $rows;
    }

    /**
     * Purpose: Extract one DOCX table cell with structural metadata.
     * Inputs: The XML XPath helper, the cell node, and its row and cell indices.
     * Returns: One normalized table cell record.
     * Side effects: None.
     *
     * @return array<string, mixed>
     */
    private function extractDocxTableCell(DOMXPath $xpath, DOMElement $cellNode, int $rowIndex, int $cellIndex, bool $explicitHeader, int $rowCount): array
    {
        $cellTextNodes = $xpath->query('.//w:t', $cellNode);
        $parts = [];

        if ($cellTextNodes !== false) {
            foreach ($cellTextNodes as $textNode) {
                $parts[] = (string) $textNode->textContent;
            }
        }

        $text = $this->normalizeText(implode(' ', $parts));
        $colSpan = $this->extractDocxTableGridSpan($xpath, $cellNode);
        $vMerge = $this->extractDocxTableVMerge($xpath, $cellNode);
        $styleHints = [];

        if ($colSpan > 1) {
            $styleHints[] = 'grid_span';
        }

        if ($vMerge === 'restart') {
            $styleHints[] = 'vertical_merge_restart';
        } elseif ($vMerge === 'continue') {
            $styleHints[] = 'vertical_merge_continue';
        }

        if ($explicitHeader) {
            $styleHints[] = 'header_row';
        }

        return [
            'row_index' => $rowIndex,
            'cell_index' => $cellIndex,
            'column_index' => $cellIndex,
            'text' => $text,
            'is_empty' => $text === '',
            'rowspan' => 1,
            'colspan' => $colSpan,
            'is_header' => $explicitHeader,
            'is_title' => false,
            'style_hints' => array_values(array_unique($styleHints)),
            'source_metadata' => [
                'grid_span' => $colSpan,
                'v_merge' => $vMerge,
                'row_index' => $rowIndex,
                'cell_index' => $cellIndex,
                'detected_title_row' => false,
                'detected_header_rows' => [],
                'column_count' => null,
                'row_count' => $rowCount,
            ],
        ];
    }

    /**
     * Purpose: Extract the colspan value from one DOCX table cell.
     * Inputs: The XML XPath helper and the cell node.
     * Returns: The logical column span for the cell.
     * Side effects: None.
     */
    private function extractDocxTableGridSpan(DOMXPath $xpath, DOMElement $cellNode): int
    {
        $gridSpanNodes = $xpath->query('./w:tcPr/w:gridSpan', $cellNode);

        if ($gridSpanNodes === false || $gridSpanNodes->length === 0) {
            return 1;
        }

        $gridSpanNode = $gridSpanNodes->item(0);
        $gridSpanValue = (int) ($gridSpanNode?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')?->nodeValue ?? 1);

        return max(1, $gridSpanValue);
    }

    /**
     * Purpose: Extract the vertical merge mode from one DOCX table cell.
     * Inputs: The XML XPath helper and the cell node.
     * Returns: restart, continue or null when no vertical merge exists.
     * Side effects: None.
     */
    private function extractDocxTableVMerge(DOMXPath $xpath, DOMElement $cellNode): ?string
    {
        $vMergeNodes = $xpath->query('./w:tcPr/w:vMerge', $cellNode);

        if ($vMergeNodes === false || $vMergeNodes->length === 0) {
            return null;
        }

        $vMergeNode = $vMergeNodes->item(0);
        $vMergeValue = Str::lower(trim((string) ($vMergeNode?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')?->nodeValue ?? '')));

        if ($vMergeValue === 'restart') {
            return 'restart';
        }

        return 'continue';
    }

    /**
     * Purpose: Determine the effective number of columns in one DOCX table.
     * Inputs: The XML XPath helper, the table node, and the raw rows.
     * Returns: The logical column count for the table.
     * Side effects: None.
     *
     * @param array<int, array{
     *     row_index: int,
     *     explicit_header: bool,
     *     cells: array<int, array<string, mixed>>
     * }> $rows
     */
    private function extractDocxTableColumnCount(DOMXPath $xpath, DOMElement $table, array $rows): int
    {
        $tblGridNodes = $xpath->query('./w:tblGrid/w:gridCol', $table);

        if ($tblGridNodes !== false && $tblGridNodes->length > 0) {
            return max(1, $tblGridNodes->length);
        }

        $columnCount = 0;

        foreach ($rows as $row) {
            $rowColumnCount = 0;

            foreach ((array) ($row['cells'] ?? []) as $cell) {
                $rowColumnCount += max(1, (int) ($cell['colspan'] ?? 1));
            }

            $columnCount = max($columnCount, $rowColumnCount);
        }

        return max(1, $columnCount);
    }

    /**
     * Purpose: Build the final structured representation and derived display formats for one DOCX table.
     * Inputs: Raw table rows and the computed column count.
     * Returns: A complete structured table payload with JSON, HTML, markdown and searchable text.
     * Side effects: None.
     *
     * @param array<int, array{
     *     row_index: int,
     *     explicit_header: bool,
     *     cells: array<int, array<string, mixed>>
     * }> $rows
     * @return array{
     *     table_json: array<string, mixed>,
     *     table_html: string,
     *     table_markdown: string,
     *     table_text: string,
     *     table_complexity: string,
     *     table_warnings: array<int, string>,
     *     rows: array<int, array{
     *         row_index: int,
     *         row_type: string,
     *         is_title: bool,
     *         is_header: bool,
     *         is_empty: bool,
     *         explicit_header: bool,
     *         cells: array<int, array<string, mixed>>,
     *         source_metadata: array<string, mixed>
     *     }>,
     *     row_count: int,
     *     column_count: int
     * }
     */
    private function buildDocxTablePayload(array $rows, int $columnCount): array
    {
        $rowCount = count($rows);
        $titleRowIndex = null;
        $headerRowIndices = [];
        $hasMergedCells = false;
        $hasVerticalMerges = false;
        $hasGroupRows = false;
        $detectedHeaderRows = [];

        foreach ($rows as $rowIndex => $row) {
            $rowCells = array_values(array_filter((array) ($row['cells'] ?? []), static fn ($cell): bool => is_array($cell)));
            $logicalColumnIndex = 0;

            foreach ($rowCells as $cellIndex => $cell) {
                $colSpan = max(1, (int) ($cell['colspan'] ?? 1));
                $vMerge = (string) data_get($cell, 'source_metadata.v_merge', '');

                if ($colSpan > 1) {
                    $hasMergedCells = true;
                }

                if ($vMerge !== '') {
                    $hasMergedCells = true;
                    $hasVerticalMerges = true;
                }

                $rowCells[$cellIndex]['column_index'] = $logicalColumnIndex;
                $rowCells[$cellIndex]['colspan'] = $colSpan;
                $rowCells[$cellIndex]['rowspan'] = max(1, (int) ($rowCells[$cellIndex]['rowspan'] ?? 1));
                $rowCells[$cellIndex]['source_metadata']['column_count'] = $columnCount;
                $rowCells[$cellIndex]['source_metadata']['row_count'] = $rowCount;
                $rowCells[$cellIndex]['style_hints'] = array_values(array_unique(array_filter((array) ($rowCells[$cellIndex]['style_hints'] ?? []), static fn ($hint): bool => trim((string) $hint) !== '')));
                $logicalColumnIndex += $colSpan;
            }

            $rows[$rowIndex]['cells'] = $rowCells;
            $rows[$rowIndex]['row_index'] = $rowIndex;
            $rows[$rowIndex]['is_empty'] = $this->rowHasVisibleText($rowCells) === false;
            $rows[$rowIndex]['is_title'] = false;
            $rows[$rowIndex]['is_header'] = false;
            $rows[$rowIndex]['row_type'] = 'data';
            $rows[$rowIndex]['source_metadata'] = [
                'row_index' => $rowIndex,
                'explicit_header' => (bool) ($row['explicit_header'] ?? false),
                'column_count' => $columnCount,
                'row_count' => $rowCount,
            ];

            if ($rowIndex === 0 && $this->isDocxTableTitleRow($rowCells, $columnCount)) {
                $titleRowIndex = 0;
            }

            if ((bool) ($row['explicit_header'] ?? false)) {
                $headerRowIndices[] = $rowIndex;
                $detectedHeaderRows[] = $rowIndex;
            }

            if ($rows[$rowIndex]['is_empty'] === false && $rowIndex !== $titleRowIndex && ! (bool) ($row['explicit_header'] ?? false) && $this->isDocxTableGroupRow($rowCells, $columnCount)) {
                $hasGroupRows = true;
                $rows[$rowIndex]['row_type'] = 'group';
                continue;
            }
        }

        if ($titleRowIndex !== null && isset($rows[$titleRowIndex])) {
            $rows[$titleRowIndex]['row_type'] = 'title';
            $rows[$titleRowIndex]['is_title'] = true;
            $rows[$titleRowIndex]['source_metadata']['detected_title_row'] = true;
            $rows[$titleRowIndex]['source_metadata']['detected_header_rows'] = $detectedHeaderRows;
            foreach ($rows[$titleRowIndex]['cells'] as $cellIndex => $cell) {
                $rows[$titleRowIndex]['cells'][$cellIndex]['is_title'] = true;
                $rows[$titleRowIndex]['cells'][$cellIndex]['source_metadata']['detected_title_row'] = true;
                $rows[$titleRowIndex]['cells'][$cellIndex]['source_metadata']['detected_header_rows'] = $detectedHeaderRows;
            }
        }

        foreach ($headerRowIndices as $headerRowIndex) {
            if (! isset($rows[$headerRowIndex])) {
                continue;
            }

            $rows[$headerRowIndex]['row_type'] = 'header';
            $rows[$headerRowIndex]['is_header'] = true;
            $rows[$headerRowIndex]['source_metadata']['detected_header_rows'] = $detectedHeaderRows;

            foreach ($rows[$headerRowIndex]['cells'] as $cellIndex => $cell) {
                $rows[$headerRowIndex]['cells'][$cellIndex]['is_header'] = true;
                $rows[$headerRowIndex]['cells'][$cellIndex]['source_metadata']['detected_header_rows'] = $detectedHeaderRows;
            }
        }

        $rows = $this->applyDocxTableRowspans($rows);

        $flatCells = [];

        foreach ($rows as $row) {
            foreach ((array) ($row['cells'] ?? []) as $cell) {
                $flatCells[] = $cell;
            }
        }

        $tableWarnings = [];

        if ($hasMergedCells) {
            $tableWarnings[] = 'merged_cells_detected';
        }

        if ($titleRowIndex !== null) {
            $tableWarnings[] = 'title_row_detected';
        }

        if (count($headerRowIndices) > 1) {
            $tableWarnings[] = 'multiple_header_rows_detected';
        }

        if ($hasVerticalMerges) {
            $tableWarnings[] = 'vertical_merge_detected';
        }

        if ($headerRowIndices === []) {
            $tableWarnings[] = 'no_explicit_header_detected';
        }

        $tableComplexity = (! $hasMergedCells && ! $hasVerticalMerges && $titleRowIndex === null && count($headerRowIndices) === 1 && ! $hasGroupRows)
            ? 'simple'
            : 'complex';

        $tableWarnings = array_values(array_unique(array_filter($tableWarnings, static fn (string $warning): bool => trim($warning) !== '')));
        $tableJson = [
            'source_type' => 'docx_table',
            'complexity' => $tableComplexity,
            'warnings' => $tableWarnings,
            'row_count' => $rowCount,
            'column_count' => $columnCount,
            'title_row_index' => $titleRowIndex,
            'header_row_indices' => array_values($headerRowIndices),
            'rows' => $rows,
            'cells' => $flatCells,
            'source_metadata' => [
                'source_type' => 'docx_table',
                'row_count' => $rowCount,
                'column_count' => $columnCount,
                'title_row_index' => $titleRowIndex,
                'header_row_indices' => array_values($headerRowIndices),
                'has_merged_cells' => $hasMergedCells,
                'has_vertical_merges' => $hasVerticalMerges,
                'has_group_rows' => $hasGroupRows,
            ],
        ];

        $tableText = $this->buildDocxTableText($tableJson);
        // Purpose: Keep markdown only as a legacy fallback. table_json is the source of truth.
        $tableMarkdown = $this->buildDocxTableMarkdown($tableJson);
        $tableHtml = $this->buildDocxTableHtml($tableJson);

        $tableJson['table_text'] = $tableText;
        $tableJson['table_markdown'] = $tableMarkdown;
        $tableJson['table_html'] = $tableHtml;

        return [
            'table_json' => $tableJson,
            'table_html' => $tableHtml,
            'table_markdown' => $tableMarkdown,
            'table_text' => $tableText,
            'table_complexity' => $tableComplexity,
            'table_warnings' => $tableWarnings,
            'rows' => $rows,
            'row_count' => $rowCount,
            'column_count' => $columnCount,
            'text' => $tableText,
            'markdown' => $tableMarkdown,
            'html' => $tableHtml,
        ];
    }

    /**
     * Purpose: Determine whether one DOCX table row should be treated as a title row.
     * Inputs: The row cells and the logical column count.
     * Returns: True when the row appears to be a title row rather than a header row.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $rowCells
     */
    private function isDocxTableTitleRow(array $rowCells, int $columnCount): bool
    {
        $nonEmptyCells = array_values(array_filter(
            $rowCells,
            static fn (array $cell): bool => trim((string) ($cell['text'] ?? '')) !== '',
        ));

        if (count($nonEmptyCells) !== 1) {
            return false;
        }

        $firstNonEmptyCell = $nonEmptyCells[0];
        $colSpan = max(1, (int) ($firstNonEmptyCell['colspan'] ?? 1));

        if ($colSpan >= $columnCount) {
            return true;
        }

        if (count($rowCells) === 1 && $columnCount > 1) {
            return true;
        }

        return count($rowCells) > 1 && count($nonEmptyCells) === 1;
    }

    /**
     * Purpose: Determine whether one DOCX table row behaves like a group row.
     * Inputs: The row cells and the logical column count.
     * Returns: True when the row looks like a section divider or grouped label row.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $rowCells
     */
    private function isDocxTableGroupRow(array $rowCells, int $columnCount): bool
    {
        $nonEmptyCells = array_values(array_filter(
            $rowCells,
            static fn (array $cell): bool => trim((string) ($cell['text'] ?? '')) !== '',
        ));

        if ($nonEmptyCells === []) {
            return false;
        }

        if (count($nonEmptyCells) !== 1) {
            return false;
        }

        $singleCell = $nonEmptyCells[0];
        $colSpan = max(1, (int) ($singleCell['colspan'] ?? 1));

        return $columnCount > 1 && $colSpan < $columnCount;
    }

    /**
     * Purpose: Check whether one DOCX table row uses the explicit Word header-row marker.
     * Inputs: The XML XPath helper and the row node.
     * Returns: True when the table row is explicitly marked as a header.
     * Side effects: None.
     */
    private function hasDocxTableHeaderFlag(DOMXPath $xpath, DOMElement $rowNode): bool
    {
        $headerNodes = $xpath->query('./w:trPr/w:tblHeader', $rowNode);

        return $headerNodes !== false && $headerNodes->length > 0;
    }

    /**
     * Purpose: Apply vertical merge spans to the parsed table rows.
     * Inputs: The row matrix with cells and merge metadata.
     * Returns: The same row matrix with calculated rowspan values where possible.
     * Side effects: Marks merge continuations in the returned cell metadata.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function applyDocxTableRowspans(array $rows): array
    {
        $rowCount = count($rows);

        for ($rowIndex = 0; $rowIndex < $rowCount; $rowIndex++) {
            $cells = array_values(array_filter((array) ($rows[$rowIndex]['cells'] ?? []), static fn ($cell): bool => is_array($cell)));

            foreach ($cells as $cellIndex => $cell) {
                $vMerge = (string) data_get($cell, 'source_metadata.v_merge', '');

                if ($vMerge !== 'restart') {
                    continue;
                }

                $columnIndex = (int) ($cell['column_index'] ?? 0);
                $rowspan = 1;

                for ($nextRowIndex = $rowIndex + 1; $nextRowIndex < $rowCount; $nextRowIndex++) {
                    $nextCells = array_values(array_filter((array) ($rows[$nextRowIndex]['cells'] ?? []), static fn ($candidate): bool => is_array($candidate)));
                    $matchedCellIndex = $this->findDocxTableCellIndexAtColumn($nextCells, $columnIndex);

                    if ($matchedCellIndex === null) {
                        break;
                    }

                    $nextCell = $nextCells[$matchedCellIndex];

                    if ((string) data_get($nextCell, 'source_metadata.v_merge', '') !== 'continue') {
                        break;
                    }

                    $rowspan++;
                    $rows[$nextRowIndex]['cells'][$matchedCellIndex]['is_vertical_merge_continuation'] = true;
                    $rows[$nextRowIndex]['cells'][$matchedCellIndex]['source_metadata']['merged_into_row_index'] = $rowIndex;
                    $rows[$nextRowIndex]['cells'][$matchedCellIndex]['source_metadata']['merged_into_column_index'] = $columnIndex;
                }

                $rows[$rowIndex]['cells'][$cellIndex]['rowspan'] = $rowspan;
            }
        }

        return $rows;
    }

    /**
     * Purpose: Locate a cell at a specific logical column index within one table row.
     * Inputs: A row's cell list and the target column index.
     * Returns: The matching cell index or null when no cell starts at that column.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $cells
     */
    private function findDocxTableCellIndexAtColumn(array $cells, int $columnIndex): ?int
    {
        foreach ($cells as $cellIndex => $cell) {
            if ((int) ($cell['column_index'] ?? -1) === $columnIndex) {
                return $cellIndex;
            }
        }

        return null;
    }

    /**
     * Purpose: Create searchable plain text from a structured DOCX table.
     * Inputs: The structured table JSON payload.
     * Returns: A normalized text representation of the table.
     * Side effects: None.
     *
     * @param array<string, mixed> $tableJson
     */
    private function buildDocxTableText(array $tableJson): string
    {
        $lines = [];
        $rows = array_values(array_filter((array) ($tableJson['rows'] ?? []), static fn ($row): bool => is_array($row)));

        foreach ($rows as $row) {
            $rowType = (string) ($row['row_type'] ?? 'data');
            $rowCells = array_values(array_filter((array) ($row['cells'] ?? []), static fn ($cell): bool => is_array($cell)));
            $visibleCells = array_values(array_filter(
                $rowCells,
                static fn (array $cell): bool => (string) data_get($cell, 'source_metadata.v_merge', '') !== 'continue',
            ));
            $cellTexts = array_map(
                static fn (array $cell): string => trim((string) ($cell['text'] ?? '')),
                $visibleCells,
            );

            if ($rowType === 'title') {
                $title = trim(implode(' ', array_filter($cellTexts, static fn (string $text): bool => $text !== '')));

                if ($title !== '') {
                    $lines[] = $title;
                }

                continue;
            }

            if ($cellTexts === []) {
                continue;
            }

            $lines[] = implode(' | ', $cellTexts);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Purpose: Create a markdown representation from a structured DOCX table for legacy fallback only.
     * Inputs: The structured table JSON payload.
     * Returns: A markdown table string that may be simplified for complex tables.
     * Side effects: None.
     *
     * @param array<string, mixed> $tableJson
     */
    private function buildDocxTableMarkdown(array $tableJson): string
    {
        $rows = array_values(array_filter((array) ($tableJson['rows'] ?? []), static fn ($row): bool => is_array($row)));
        $columnCount = max(1, (int) ($tableJson['column_count'] ?? 0));
        $titleRowIndex = isset($tableJson['title_row_index']) ? (int) $tableJson['title_row_index'] : null;
        $headerRowIndices = array_values(array_map(
            static fn ($value): int => (int) $value,
            (array) ($tableJson['header_row_indices'] ?? []),
        ));
        $headerRowIndex = $headerRowIndices[0] ?? null;
        $titleLine = null;

        if ($titleRowIndex !== null && isset($rows[$titleRowIndex])) {
            $titleLine = trim(implode(' ', array_filter(array_map(
                static fn (array $cell): string => trim((string) ($cell['text'] ?? '')),
                array_values(array_filter((array) ($rows[$titleRowIndex]['cells'] ?? []), static fn ($cell): bool => is_array($cell))),
            ), static fn (string $text): bool => $text !== '')));
        }

        $markdownLines = [];

        if ($titleLine !== null && $titleLine !== '') {
            $markdownLines[] = '**'.$titleLine.'**';
        }

        $headerLabels = [];

        if ($headerRowIndex !== null && isset($rows[$headerRowIndex])) {
            $headerLabels = $this->tableMarkdownLabelsFromRow($rows[$headerRowIndex], $columnCount);
        } else {
            $headerLabels = $this->tableMarkdownGenericLabels($columnCount);
        }

        if ($headerLabels === []) {
            return implode("\n", $markdownLines);
        }

        $markdownLines[] = '| '.implode(' | ', $headerLabels).' |';
        $markdownLines[] = '| '.implode(' | ', array_fill(0, count($headerLabels), '---')).' |';

        foreach ($rows as $rowIndex => $row) {
            if ((int) ($row['row_index'] ?? $rowIndex) === $titleRowIndex) {
                continue;
            }

            if (in_array((int) ($row['row_index'] ?? $rowIndex), $headerRowIndices, true)) {
                if ($headerRowIndex !== null && (int) ($row['row_index'] ?? $rowIndex) === $headerRowIndex) {
                    continue;
                }
            }

            $rowLabels = $this->tableMarkdownLabelsFromRow($row, $columnCount);

            if ($rowLabels === [] || count(array_filter($rowLabels, static fn (string $value): bool => trim($value) !== '')) === 0) {
                continue;
            }

            $markdownLines[] = '| '.implode(' | ', $rowLabels).' |';
        }

        return implode("\n", $markdownLines);
    }

    /**
     * Purpose: Create a minimal HTML table representation from structured DOCX table data.
     * Inputs: The structured table JSON payload.
     * Returns: An HTML table string with preserved colspan and rowspan.
     * Side effects: None.
     *
     * @param array<string, mixed> $tableJson
     */
    private function buildDocxTableHtml(array $tableJson): string
    {
        $rows = array_values(array_filter((array) ($tableJson['rows'] ?? []), static fn ($row): bool => is_array($row)));
        $columnCount = max(1, (int) ($tableJson['column_count'] ?? 1));
        $titleRowIndex = isset($tableJson['title_row_index']) ? (int) $tableJson['title_row_index'] : null;
        $headerRowIndices = array_values(array_map(
            static fn ($value): int => (int) $value,
            (array) ($tableJson['header_row_indices'] ?? []),
        ));
        $titleText = null;

        if ($titleRowIndex !== null && isset($rows[$titleRowIndex])) {
            $titleText = trim(implode(' ', array_filter(array_map(
                static fn (array $cell): string => trim((string) ($cell['text'] ?? '')),
                array_values(array_filter((array) ($rows[$titleRowIndex]['cells'] ?? []), static fn ($cell): bool => is_array($cell))),
            ), static fn (string $text): bool => $text !== '')));
        }

        $html = '<table style="width:100%; border-collapse:collapse; border:1px solid #cbd5e1; font-size:14px; line-height:1.4;">';
        $hasThead = $titleText !== null && $titleText !== '' || $headerRowIndices !== [];

        if ($hasThead) {
            $html .= '<thead>';

            if ($titleText !== null && $titleText !== '') {
                $html .= '<tr><th colspan="'.$columnCount.'" scope="colgroup" style="padding:0.7rem 0.85rem; border:1px solid #cbd5e1; background:#f8fafc; font-weight:600; color:#0f172a;">'
                    .htmlspecialchars($titleText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    .'</th></tr>';
            }

            foreach ($headerRowIndices as $headerRowIndex) {
                if (! isset($rows[$headerRowIndex])) {
                    continue;
                }

                $html .= $this->buildDocxTableHtmlRow($rows[$headerRowIndex], $columnCount, true);
            }

            $html .= '</thead>';
        }

        $html .= '<tbody>';

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex === $titleRowIndex || in_array($rowIndex, $headerRowIndices, true)) {
                continue;
            }

            $html .= $this->buildDocxTableHtmlRow($row, $columnCount, false);
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Purpose: Build one HTML row for a structured DOCX table.
     * Inputs: One table row, the logical column count and whether the row is part of the table header.
     * Returns: One HTML table row string.
     * Side effects: None.
     *
     * @param array<string, mixed> $row
     */
    private function buildDocxTableHtmlRow(array $row, int $columnCount, bool $isHeaderRow): string
    {
        $rowType = (string) ($row['row_type'] ?? 'data');
        $rowCells = array_values(array_filter((array) ($row['cells'] ?? []), static fn ($cell): bool => is_array($cell)));
        $tagName = $isHeaderRow ? 'th' : 'td';
        $html = '<tr>';
        $renderedCellCount = 0;

        foreach ($rowCells as $cellIndex => $cell) {
            if ((bool) ($cell['is_vertical_merge_continuation'] ?? false)) {
                continue;
            }

            $cellText = trim((string) ($cell['text'] ?? ''));
            $colspan = max(1, (int) ($cell['colspan'] ?? 1));
            $rowspan = max(1, (int) ($cell['rowspan'] ?? 1));
            $style = 'padding:0.7rem 0.85rem; border:1px solid #cbd5e1; vertical-align:top;';

            if ($isHeaderRow || $rowType === 'title') {
                $style .= ' background:#f8fafc; font-weight:600; color:#0f172a;';
            }

            if ($rowType === 'group') {
                $style .= ' background:#f8fafc; font-weight:600; color:#0f172a;';
            }

            $attributes = [
                'style="'.$style.'"',
            ];

            if ($colspan > 1) {
                $attributes[] = 'colspan="'.$colspan.'"';
            }

            if ($rowspan > 1) {
                $attributes[] = 'rowspan="'.$rowspan.'"';
            }

            if ($isHeaderRow) {
                $attributes[] = 'scope="col"';
            } elseif ($rowType === 'group' && $renderedCellCount === 0) {
                $attributes[] = 'scope="row"';
            }

            $content = $cellText !== ''
                ? htmlspecialchars($cellText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : '&nbsp;';

            if ($isHeaderRow || ($rowType === 'group' && $renderedCellCount === 0)) {
                $html .= '<th '.implode(' ', $attributes).'>'.$content.'</th>';
            } else {
                $html .= '<td '.implode(' ', $attributes).'>'.$content.'</td>';
            }

            $renderedCellCount++;
        }

        if ($renderedCellCount === 0) {
            $html .= '<td style="padding:0.7rem 0.85rem; border:1px solid #cbd5e1;" colspan="'.$columnCount.'">&nbsp;</td>';
        }

        $html .= '</tr>';

        return $html;
    }

    /**
     * Purpose: Build generic markdown header labels for tables without an explicit header row.
     * Inputs: The logical column count.
     * Returns: A list of generic column labels.
     * Side effects: None.
     */
    private function tableMarkdownGenericLabels(int $columnCount): array
    {
        $labels = [];

        for ($index = 0; $index < max(1, $columnCount); $index++) {
            $labels[] = 'Column '.($index + 1);
        }

        return $labels;
    }

    /**
     * Purpose: Convert one structured table row into a markdown-friendly label list.
     * Inputs: The table row and the logical column count.
     * Returns: One list of cell texts padded to the logical column count.
     * Side effects: None.
     *
     * @param array<string, mixed> $row
     */
    private function tableMarkdownLabelsFromRow(array $row, int $columnCount): array
    {
        $labels = [];
        $rowCells = array_values(array_filter((array) ($row['cells'] ?? []), static fn ($cell): bool => is_array($cell)));

        foreach ($rowCells as $cell) {
            if ((string) data_get($cell, 'source_metadata.v_merge', '') === 'continue') {
                continue;
            }

            $colspan = max(1, (int) ($cell['colspan'] ?? 1));
            $text = trim((string) ($cell['text'] ?? ''));
            $labels[] = $text;

            for ($index = 1; $index < $colspan; $index++) {
                $labels[] = '';
            }
        }

        while (count($labels) < max(1, $columnCount)) {
            $labels[] = '';
        }

        return array_slice($labels, 0, max(1, $columnCount));
    }

    /**
     * Purpose: Determine whether a structured table row has visible text.
     * Inputs: One table row cell list.
     * Returns: True when at least one visible cell contains text.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $rowCells
     */
    private function rowHasVisibleText(array $rowCells): bool
    {
        foreach ($rowCells as $cell) {
            if (trim((string) ($cell['text'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Purpose: Resolve the style ID of one DOCX paragraph.
     * Inputs: The XML XPath helper and the paragraph node.
     * Returns: The raw style ID or null when no paragraph style exists.
     * Side effects: None.
     */
    private function paragraphStyleId(DOMXPath $xpath, DOMElement $paragraph): ?string
    {
        $styleNode = $xpath->query('.//w:pPr/w:pStyle', $paragraph);

        if ($styleNode === false || $styleNode->length === 0) {
            return null;
        }

        $styleId = (string) $styleNode->item(0)?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')?->nodeValue;

        return $styleId !== '' ? $styleId : null;
    }

    /**
     * Purpose: Resolve a heading level from the raw DOCX style ID or style name.
     * Inputs: The style ID and resolved style name.
     * Returns: 1, 2, 3, or null when the paragraph is not a heading.
     * Side effects: None.
     */
    private function resolveHeadingLevel(?string $styleId, ?string $styleName): ?int
    {
        $candidates = array_filter([
            $styleId,
            $styleName,
        ], static fn (?string $value): bool => is_string($value) && trim($value) !== '');

        foreach ($candidates as $candidate) {
            $normalized = Str::lower(trim($candidate));

            if (preg_match('/(?:heading|overskrift)\s*1\b/u', $normalized) === 1 || $normalized === 'heading1' || $normalized === 'overskrift1') {
                return 1;
            }

            if (preg_match('/(?:heading|overskrift)\s*2\b/u', $normalized) === 1 || $normalized === 'heading2' || $normalized === 'overskrift2') {
                return 2;
            }

            if (preg_match('/(?:heading|overskrift)\s*3\b/u', $normalized) === 1 || $normalized === 'heading3' || $normalized === 'overskrift3') {
                return 3;
            }
        }

        return null;
    }

    /**
     * Purpose: Determine whether one paragraph should be classified as a list item.
     * Inputs: The XPath helper, the paragraph node, the visible text, and the resolved style name.
     * Returns: True when the paragraph behaves like a list item.
     * Side effects: None.
     */
    private function isListParagraph(DOMXPath $xpath, DOMElement $paragraph, string $text, ?string $styleName): bool
    {
        $numberingNodes = $xpath->query('.//w:pPr/w:numPr', $paragraph);

        if ($numberingNodes !== false && $numberingNodes->length > 0) {
            return true;
        }

        if (is_string($styleName) && Str::contains(Str::lower($styleName), 'list')) {
            return true;
        }

        return $this->isListText($text);
    }

    /**
     * Purpose: Determine whether visible paragraph text resembles a list item.
     * Inputs: The visible text content.
     * Returns: True when the text appears to be a list item.
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
     * Purpose: Determine whether one paragraph is descriptive figure or caption text.
     * Inputs: The visible text and the resolved style name.
     * Returns: True when the paragraph should be treated as figure_text.
     * Side effects: None.
     */
    private function isFigureText(string $text, ?string $styleName): bool
    {
        $normalizedStyle = is_string($styleName) ? Str::lower(trim($styleName)) : '';
        $normalizedText = Str::lower(trim($text));

        if ($normalizedStyle !== '' && (
            Str::contains($normalizedStyle, 'caption')
            || Str::contains($normalizedStyle, 'figure')
            || Str::contains($normalizedStyle, 'illustration')
            || Str::contains($normalizedStyle, 'image')
            || Str::contains($normalizedStyle, 'diagram')
            || Str::contains($normalizedStyle, 'bilde')
            || Str::contains($normalizedStyle, 'illustrasjon')
        )) {
            return true;
        }

        return Str::startsWith($normalizedText, ['figur ', 'figure ', 'bilde ', 'image ', 'illustrasjon ', 'diagram ', 'tabell ', 'table ']);
    }

    /**
     * Purpose: Classify a non-heading DOCX paragraph into one of the supported structural types.
     * Inputs: The XPath helper, the paragraph node, the paragraph text, and the resolved style name.
     * Returns: A supported structural type name.
     * Side effects: None.
     */
    private function classifyParagraphType(DOMXPath $xpath, DOMElement $paragraph, string $text, ?string $styleName): string
    {
        if ($this->isListParagraph($xpath, $paragraph, $text, $styleName)) {
            return 'list';
        }

        if ($this->isFigureText($text, $styleName)) {
            return 'figure_text';
        }

        return 'paragraph';
    }

    /**
     * Purpose: Determine the relation hint for a parsed paragraph.
     * Inputs: The structural type and visible text.
     * Returns: A lightweight relation hint or null.
     * Side effects: None.
     */
    private function relationHintForParagraph(string $type, string $text): ?string
    {
        if ($type === 'list') {
            return 'list_item';
        }

        if ($type === 'figure_text') {
            return 'figure_caption';
        }

        $normalized = trim($text);

        if ($normalized !== '' && preg_match('/[:;]\s*$/u', $normalized) === 1) {
            return 'lead_in';
        }

        return null;
    }

    /**
     * Purpose: Remove deeper heading levels from the heading stack after a new heading is seen.
     * Inputs: The current heading stack and the active heading level.
     * Returns: The trimmed heading stack.
     * Side effects: None.
     *
     * @param array<int, string> $currentHeadings
     * @return array<int, string>
     */
    private function trimHeadingStack(array $currentHeadings, int $headingLevel): array
    {
        foreach (array_keys($currentHeadings) as $level) {
            if ((int) $level > $headingLevel) {
                unset($currentHeadings[$level]);
            }
        }

        ksort($currentHeadings);

        return $currentHeadings;
    }

    /**
     * Purpose: Build a stable heading path string from the current heading stack.
     * Inputs: The current heading stack.
     * Returns: A readable heading path or null when no headings are active.
     * Side effects: None.
     *
     * @param array<int, string> $currentHeadings
     */
    private function currentHeadingPath(array $currentHeadings): ?string
    {
        $parts = [];

        foreach ($currentHeadings as $heading) {
            $heading = trim((string) $heading);

            if ($heading !== '') {
                $parts[] = $heading;
            }
        }

        $parts = array_values(array_filter($parts, static fn (string $heading): bool => $heading !== ''));

        return $parts !== [] ? implode(' > ', $parts) : null;
    }

    /**
     * Purpose: Group parsed DOCX elements into deterministic H2 sections before chunking.
     * Inputs: The ordered raw elements extracted from the DOCX body after list merging.
     * Returns: Ordered elements where each H2 section is represented as one structural section element.
     * Side effects: None.
     *
     * @param array<int, array{
     *     type: string,
     *     heading_path: ?string,
     *     heading_context: ?string,
     *     text: string,
     *     heading_level: ?int,
     *     relation_hint: ?string,
     *     table_markdown?: string,
     *     table_text?: string,
     *     rows?: array<int, array<int, string>>,
     *     row_count?: int,
     *     column_count?: int,
     *     table_index_in_document?: int
     * }> $elements
     * @return array<int, array{
     *     type: string,
     *     heading_path: ?string,
     *     heading_context: ?string,
     *     text: string,
     *     heading_level: ?int,
     *     relation_hint: ?string,
     *     table_markdown?: string,
     *     table_text?: string,
     *     rows?: array<int, array<int, string>>,
     *     row_count?: int,
     *     column_count?: int,
     *     table_index_in_document?: int,
     *     image_bytes?: string,
     *     image_path?: ?string,
     *     image_disk?: ?string,
     *     image_mime_type?: ?string,
     *     image_original_filename?: ?string,
     *     image_width?: ?int,
     *     image_height?: ?int,
     *     image_hash?: ?string,
     *     image_metadata?: array<string, mixed>,
     *     image_alt_text?: ?string,
     *     image_caption?: ?string,
     *     ocr_text?: ?string,
     *     image_description?: ?string,
     *     source_metadata?: array<string, mixed>,
     *     image_index_in_document?: int
     * }>
     */
    private function groupH2Sections(array $elements): array
    {
        $grouped = [];
        $currentSection = [];

        foreach ($elements as $element) {
            $type = (string) ($element['type'] ?? 'other');
            $headingLevel = isset($element['heading_level']) ? (int) $element['heading_level'] : null;
            $text = trim((string) ($element['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            if ($type === 'heading' && $headingLevel === 1) {
                if ($currentSection !== []) {
                    $section = $this->buildH2SectionElement($currentSection);

                    if ($section !== null) {
                        $grouped[] = $section;
                    }

                    $currentSection = [];
                }

                continue;
            }

            if ($type === 'heading' && $headingLevel === 2) {
                if ($currentSection !== []) {
                    $section = $this->buildH2SectionElement($currentSection);

                    if ($section !== null) {
                        $grouped[] = $section;
                    }
                }

                $element['text'] = $text;
                $currentSection = [$element];

                continue;
            }

            if ($type === 'table' || $type === 'image') {
                if ($currentSection !== []) {
                    $headingSeed = $currentSection[0] ?? null;
                    $section = $this->buildH2SectionElement($currentSection);

                    if ($section !== null) {
                        $grouped[] = $section;
                    }

                    $currentSection = is_array($headingSeed) ? [$headingSeed] : [];
                }

                $element['text'] = $text;
                $grouped[] = $element;

                continue;
            }

            if ($currentSection !== []) {
                $element['text'] = $text;
                $currentSection[] = $element;

                continue;
            }

            $element['text'] = $text;
            $grouped[] = $element;
        }

        if ($currentSection !== []) {
            $section = $this->buildH2SectionElement($currentSection);

            if ($section !== null) {
                $grouped[] = $section;
            }
        }

        return $grouped;
    }

    /**
     * Purpose: Build one deterministic H2 section element from contiguous raw elements.
     * Inputs: The H2 heading element and all following elements until the next H1 or H2.
     * Returns: One raw structural element representing the complete H2 section.
     * Side effects: None.
     *
     * @param array<int, array{
     *     type: string,
     *     heading_path: ?string,
     *     heading_context: ?string,
     *     text: string,
     *     heading_level: ?int,
     *     relation_hint: ?string,
     *     table_markdown?: string,
     *     table_text?: string,
     *     rows?: array<int, array<int, string>>,
     *     row_count?: int,
     *     column_count?: int,
     *     table_index_in_document?: int,
     *     image_bytes?: string,
     *     image_path?: ?string,
     *     image_disk?: ?string,
     *     image_mime_type?: ?string,
     *     image_original_filename?: ?string,
     *     image_width?: ?int,
     *     image_height?: ?int,
     *     image_hash?: ?string,
     *     image_metadata?: array<string, mixed>,
     *     image_alt_text?: ?string,
     *     image_caption?: ?string,
     *     ocr_text?: ?string,
     *     image_description?: ?string,
     *     source_metadata?: array<string, mixed>,
     *     image_index_in_document?: int
     * }> $sectionElements
     * @return array{
     *     type: string,
     *     heading_path: ?string,
     *     heading_context: ?string,
     *     text: string,
     *     heading_level: ?int,
     *     relation_hint: ?string
     * }|null
     */
    private function buildH2SectionElement(array $sectionElements): ?array
    {
        $firstElement = $sectionElements[0] ?? [];
        $textParts = [];

        foreach ($sectionElements as $sectionElement) {
            $text = trim((string) ($sectionElement['text'] ?? ''));

            if ($text !== '') {
                $textParts[] = $text;
            }
        }

        if (count($textParts) <= 1) {
            return null;
        }

        return [
            'type' => 'h2_section',
            'heading_path' => $this->normalizeNullableString($firstElement['heading_path'] ?? null),
            'heading_context' => $this->normalizeNullableString($firstElement['heading_context'] ?? null) ?? $this->normalizeNullableString($firstElement['heading_path'] ?? null),
            'text' => trim(implode("\n\n", $textParts)),
            'heading_level' => 2,
            'relation_hint' => 'h2_section',
        ];
    }

    /**
     * Purpose: Merge adjacent list items that belong to the same structural context.
     * Inputs: The raw ordered structural elements extracted from the document body.
     * Returns: A normalized list of structural elements with contiguous list items merged.
     * Side effects: None.
     *
     * @param array<int, array{
     *     type: string,
     *     heading_path: ?string,
     *     heading_context: ?string,
     *     text: string,
     *     heading_level: ?int,
     *     relation_hint: ?string,
     *     table_markdown?: string,
     *     table_text?: string,
     *     rows?: array<int, array<int, string>>,
     *     row_count?: int,
     *     column_count?: int,
     *     table_index_in_document?: int
     * }> $elements
     * @return array<int, array{
     *     type: string,
     *     heading_path: ?string,
     *     heading_context: ?string,
     *     text: string,
     *     heading_level: ?int,
     *     relation_hint: ?string,
     *     table_markdown?: string,
     *     table_text?: string,
     *     rows?: array<int, array<int, string>>,
     *     row_count?: int,
     *     column_count?: int,
     *     table_index_in_document?: int
     * }>
     */
    private function mergeContiguousListElements(array $elements): array
    {
        $merged = [];

        foreach ($elements as $element) {
            $type = (string) ($element['type'] ?? 'other');
            $text = trim((string) ($element['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            if ($type === 'list' && $merged !== []) {
                $lastIndex = array_key_last($merged);

                if ($lastIndex !== null && (string) ($merged[$lastIndex]['type'] ?? '') === 'list') {
                    $lastHeadingPath = $this->normalizeNullableString($merged[$lastIndex]['heading_path'] ?? null);
                    $currentHeadingPath = $this->normalizeNullableString($element['heading_path'] ?? null);

                    if ($lastHeadingPath === $currentHeadingPath) {
                        $merged[$lastIndex]['text'] = trim((string) $merged[$lastIndex]['text'])."\n".$text;
                        $merged[$lastIndex]['relation_hint'] = 'list_group';

                        continue;
                    }
                }
            }

            $element['text'] = $text;
            $element['heading_context'] = $element['heading_context'] ?? $element['heading_path'] ?? null;
            $merged[] = $element;
        }

        return $merged;
    }

    /**
     * Purpose: Merge contiguous list items and normalize the raw elements into final parsed elements.
     * Inputs: The raw ordered structural elements and the detected document format.
     * Returns: Canonical source text and final ordered elements with offsets.
     * Side effects: None.
     *
     * @param array<int, array{
     *     type: string,
     *     heading_path: ?string,
     *     heading_context: ?string,
     *     text: string,
     *     heading_level: ?int,
     *     relation_hint: ?string,
     *     table_markdown?: string,
     *     table_text?: string,
     *     rows?: array<int, array<int, string>>,
     *     row_count?: int,
     *     column_count?: int,
     *     table_index_in_document?: int
     * }> $elements
     * @return array{
     *     document_format: string,
     *     source_text: string,
     *     elements: array<int, array{
     *         id: string,
     *         type: string,
     *         heading_path: ?string,
     *         heading_context: ?string,
     *         text: string,
     *         start_offset: int,
     *         end_offset: int,
     *         order_index: int,
     *         heading_level: ?int,
     *         relation_hint: ?string,
     *         table_json?: array<string, mixed>,
     *         table_html?: string,
     *         table_complexity?: string,
     *         table_warnings?: array<int, string>,
     *         table_markdown?: string,
     *         table_text?: string,
     *         rows?: array<int, array<int, string>>,
     *         row_count?: int,
     *         column_count?: int,
     *         table_index_in_document?: int
     *     }>,
     *     word_count: int
     * }
     */
    private function normalizeElements(array $elements, string $documentFormat): array
    {
        $merged = [];
        $count = count($elements);

        for ($index = 0; $index < $count; $index++) {
            $element = $elements[$index];
            $type = (string) ($element['type'] ?? 'other');

            if ($type === 'list' && isset($merged[array_key_last($merged)]) && (string) ($merged[array_key_last($merged)]['type'] ?? '') === 'list') {
                $lastKey = array_key_last($merged);
                $merged[$lastKey]['text'] = trim((string) $merged[$lastKey]['text'])."\n".trim((string) ($element['text'] ?? ''));
                $merged[$lastKey]['relation_hint'] = 'list_group';
                continue;
            }

            $merged[] = [
                'type' => $type,
                'heading_path' => $element['heading_path'] ?? null,
                'heading_context' => $element['heading_context'] ?? $element['heading_path'] ?? null,
                'text' => trim((string) ($element['text'] ?? '')),
                'heading_level' => $element['heading_level'] ?? null,
                'relation_hint' => $element['relation_hint'] ?? null,
                'table_json' => $element['table_json'] ?? null,
                'table_html' => $element['table_html'] ?? null,
                'table_complexity' => $element['table_complexity'] ?? null,
                'table_warnings' => $element['table_warnings'] ?? null,
                'table_markdown' => $element['table_markdown'] ?? null,
                'table_text' => $element['table_text'] ?? null,
                'rows' => $element['rows'] ?? null,
                'row_count' => $element['row_count'] ?? null,
                'column_count' => $element['column_count'] ?? null,
                'table_index_in_document' => $element['table_index_in_document'] ?? null,
                'image_bytes' => $element['image_bytes'] ?? null,
                'image_path' => $element['image_path'] ?? null,
                'image_disk' => $element['image_disk'] ?? null,
                'image_mime_type' => $element['image_mime_type'] ?? null,
                'image_original_filename' => $element['image_original_filename'] ?? null,
                'image_width' => $element['image_width'] ?? null,
                'image_height' => $element['image_height'] ?? null,
                'image_hash' => $element['image_hash'] ?? null,
                'image_metadata' => $element['image_metadata'] ?? null,
                'image_alt_text' => $element['image_alt_text'] ?? null,
                'image_caption' => $element['image_caption'] ?? null,
                'ocr_text' => $element['ocr_text'] ?? null,
                'image_description' => $element['image_description'] ?? null,
                'source_metadata' => $element['source_metadata'] ?? null,
                'image_index_in_document' => $element['image_index_in_document'] ?? null,
            ];
        }

        $finalElements = [];
        $sourceTextParts = [];
        $cursor = 0;

        foreach ($merged as $index => $element) {
            $text = trim((string) ($element['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $startOffset = $cursor;
            $sourceTextParts[] = $text;
            $cursor += mb_strlen($text, 'UTF-8');

            if (count($merged) > 1 && $index < count($merged) - 1) {
                $cursor += 2;
            }

            $endOffset = $cursor;

            $finalElements[] = [
                'id' => sprintf('element-%04d', count($finalElements) + 1),
                'type' => (string) ($element['type'] ?? 'other'),
                'heading_path' => $this->normalizeNullableString($element['heading_path'] ?? null),
                'heading_context' => $this->normalizeNullableString($element['heading_context'] ?? $element['heading_path'] ?? null),
                'text' => $text,
                'start_offset' => $startOffset,
                'end_offset' => $endOffset,
                'order_index' => count($finalElements),
                'heading_level' => isset($element['heading_level']) ? (int) $element['heading_level'] : null,
                'relation_hint' => $this->normalizeNullableString($element['relation_hint'] ?? null),
                'table_json' => is_array($element['table_json'] ?? null) ? $element['table_json'] : null,
                'table_html' => $this->normalizeNullableString($element['table_html'] ?? null),
                'table_complexity' => $this->normalizeNullableString($element['table_complexity'] ?? null),
                'table_warnings' => isset($element['table_warnings']) && is_array($element['table_warnings']) ? array_values(array_filter(array_map(
                    static fn ($warning): string => trim((string) ($warning ?? '')),
                    $element['table_warnings'],
                ), static fn (string $warning): bool => $warning !== '')) : null,
                'table_markdown' => $this->normalizeNullableString($element['table_markdown'] ?? null),
                'table_text' => $this->normalizeNullableString($element['table_text'] ?? null),
                'rows' => isset($element['rows']) && is_array($element['rows']) ? $element['rows'] : null,
                'row_count' => isset($element['row_count']) ? (int) $element['row_count'] : null,
                'column_count' => isset($element['column_count']) ? (int) $element['column_count'] : null,
                'table_index_in_document' => isset($element['table_index_in_document']) ? (int) $element['table_index_in_document'] : null,
                'image_bytes' => is_string($element['image_bytes'] ?? null) && trim((string) $element['image_bytes']) !== '' ? (string) $element['image_bytes'] : null,
                'image_path' => $this->normalizeNullableString($element['image_path'] ?? null),
                'image_disk' => $this->normalizeNullableString($element['image_disk'] ?? null),
                'image_mime_type' => $this->normalizeNullableString($element['image_mime_type'] ?? null),
                'image_original_filename' => $this->normalizeNullableString($element['image_original_filename'] ?? null),
                'image_width' => isset($element['image_width']) ? (int) $element['image_width'] : null,
                'image_height' => isset($element['image_height']) ? (int) $element['image_height'] : null,
                'image_hash' => $this->normalizeNullableString($element['image_hash'] ?? null),
                'image_metadata' => isset($element['image_metadata']) && is_array($element['image_metadata']) ? $element['image_metadata'] : null,
                'image_alt_text' => $this->normalizeNullableString($element['image_alt_text'] ?? null),
                'image_caption' => $this->normalizeNullableString($element['image_caption'] ?? null),
                'ocr_text' => $this->normalizeNullableString($element['ocr_text'] ?? null),
                'image_description' => $this->normalizeNullableString($element['image_description'] ?? null),
                'source_metadata' => isset($element['source_metadata']) && is_array($element['source_metadata']) ? $element['source_metadata'] : null,
                'image_index_in_document' => isset($element['image_index_in_document']) ? (int) $element['image_index_in_document'] : null,
            ];
        }

        $sourceText = implode("\n\n", $sourceTextParts);

        return [
            'document_format' => $documentFormat,
            'source_text' => $sourceText,
            'elements' => $finalElements,
            'word_count' => $this->wordCount($sourceText),
        ];
    }

    /**
     * Purpose: Normalize whitespace in a text block without changing the intended order.
     * Inputs: Raw text extracted from the document XML.
     * Returns: A trimmed string with collapsed internal whitespace.
     * Side effects: None.
     */
    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Purpose: Normalize a nullable string value to a trimmed nullable string.
     * Inputs: A raw scalar value or null.
     * Returns: A trimmed string or null.
     * Side effects: None.
     */
    private function normalizeNullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    /**
     * Purpose: Count the approximate number of words in a text block.
     * Inputs: Raw text.
     * Returns: The word count.
     * Side effects: None.
     */
    private function wordCount(string $text): int
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($normalized === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? count($parts) : 0;
    }
}
