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
        $zip->close();

        if (! is_string($documentXml) || trim($documentXml) === '') {
            return $this->parsePlainText($path);
        }

        $elements = $this->parseDocxBodyElements($documentXml, is_string($stylesXml) ? $stylesXml : null);

        if ($elements === []) {
            return $this->parsePlainText($path);
        }

        return $this->normalizeElements($elements, 'docx');
    }

    /**
     * Purpose: Parse a DOCX document body into ordered raw structural elements.
     * Inputs: Raw DOCX XML content and optional styles XML content.
     * Returns: Ordered raw elements with lightweight structural metadata.
     * Side effects: Parses XML in memory.
     *
     * @return array<int, array{
     *     type: string,
     *     heading_path: ?string,
     *     text: string,
     *     heading_level: ?int,
     *     relation_hint: ?string
     * }>
     */
    private function parseDocxBodyElements(string $documentXml, ?string $stylesXml = null): array
    {
        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument();

            if (! $dom->loadXML($documentXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return [];
            }

            $styleMap = $this->extractDocxStyleMap($stylesXml);
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            $rawElements = [];
            $currentHeadings = [];

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

                    if ($text === '') {
                        continue;
                    }

                    $styleId = $this->paragraphStyleId($xpath, $node);
                    $styleName = $styleMap[$styleId] ?? $styleId;
                    $headingLevel = $this->resolveHeadingLevel($styleId, $styleName);

                    if ($headingLevel !== null) {
                        $currentHeadings[$headingLevel] = $text;
                        $currentHeadings = $this->trimHeadingStack($currentHeadings, $headingLevel);

                        $rawElements[] = [
                            'type' => 'heading',
                            'heading_path' => $this->currentHeadingPath($currentHeadings),
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
                        'text' => $text,
                        'heading_level' => null,
                        'relation_hint' => $relationHint,
                    ];

                    continue;
                }

                if ($node->nodeName === 'w:tbl') {
                    $text = $this->extractTableText($xpath, $node);

                    if ($text === '') {
                        continue;
                    }

                    $rawElements[] = [
                        'type' => 'table',
                        'heading_path' => $this->currentHeadingPath($currentHeadings),
                        'text' => $text,
                        'heading_level' => null,
                        'relation_hint' => 'table_group',
                    ];
                }
            }

            return $this->mergeContiguousListElements($rawElements);
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
     * Purpose: Extract the textual content of one DOCX table.
     * Inputs: The XML XPath helper and the table node.
     * Returns: A normalized table string that preserves row order.
     * Side effects: None.
     */
    private function extractTableText(DOMXPath $xpath, DOMElement $table): string
    {
        $rows = [];
        $tableRows = $xpath->query('.//w:tr', $table);

        if ($tableRows === false) {
            return '';
        }

        foreach ($tableRows as $rowNode) {
            if (! $rowNode instanceof DOMElement) {
                continue;
            }

            $cells = [];
            $cellNodes = $xpath->query('./w:tc', $rowNode);

            if ($cellNodes === false) {
                continue;
            }

            foreach ($cellNodes as $cellNode) {
                if (! $cellNode instanceof DOMElement) {
                    continue;
                }

                $cellTextNodes = $xpath->query('.//w:t', $cellNode);
                $parts = [];

                if ($cellTextNodes !== false) {
                    foreach ($cellTextNodes as $textNode) {
                        $parts[] = (string) $textNode->textContent;
                    }
                }

                $cellText = $this->normalizeText(implode(' ', $parts));

                if ($cellText !== '') {
                    $cells[] = $cellText;
                }
            }

            if ($cells !== []) {
                $rows[] = implode(' | ', $cells);
            }
        }

        return trim(implode("\n", $rows));
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
        )) {
            return true;
        }

        return Str::startsWith($normalizedText, ['figur ', 'figure ', 'tabell ', 'table ']);
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
     * Purpose: Merge adjacent list items that belong to the same structural context.
     * Inputs: The raw ordered structural elements extracted from the document body.
     * Returns: A normalized list of structural elements with contiguous list items merged.
     * Side effects: None.
     *
     * @param array<int, array{
     *     type: string,
     *     heading_path: ?string,
     *     text: string,
     *     heading_level: ?int,
     *     relation_hint: ?string
     * }> $elements
     * @return array<int, array{
     *     type: string,
     *     heading_path: ?string,
     *     text: string,
     *     heading_level: ?int,
     *     relation_hint: ?string
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
     *     text: string,
     *     heading_level: ?int,
     *     relation_hint: ?string
     * }> $elements
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
                'text' => trim((string) ($element['text'] ?? '')),
                'heading_level' => $element['heading_level'] ?? null,
                'relation_hint' => $element['relation_hint'] ?? null,
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
                'text' => $text,
                'start_offset' => $startOffset,
                'end_offset' => $endOffset,
                'order_index' => count($finalElements),
                'heading_level' => isset($element['heading_level']) ? (int) $element['heading_level'] : null,
                'relation_hint' => $this->normalizeNullableString($element['relation_hint'] ?? null),
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
