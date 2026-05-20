<?php

namespace App\Services;

use DOMDocument;
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
        $zip = new ZipArchive();

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
     * Purpose: Extract structured paragraphs from a DOCX file.
     * Inputs: Absolute filesystem path to a DOCX file.
     * Returns: Ordered blocks containing text, style, and heading level metadata.
     * Side effects: Opens the ZIP archive and parses XML.
     *
     * @return array<int, array{text: string, style: ?string, level: ?int}>
     */
    private function extractStructuredDocxText(string $path): array
    {
        $zip = new ZipArchive();

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

    private function detectPdfHeadingLevel(string $line): ?int
    {
        if (mb_strlen($line) > 120) {
            return null;
        }

        // Sub-numbered: "1.1 Title", "2.3.4 Title"
        if (preg_match('/^\d+\.\d[\d.]*\s+\S/u', $line)) {
            return 2;
        }

        // Top-level numbered: "1. Title", "10. Title"
        if (preg_match('/^\d+\.\s+[^\d\s]/u', $line)) {
            return 1;
        }

        // ALL CAPS heading (min 4 letters, no sentence punctuation)
        if (preg_match('/^[A-ZÆØÅ][A-ZÆØÅ0-9\s\-\/]{3,}$/u', $line) && ! str_contains($line, '.')) {
            return 1;
        }

        return null;
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
            $dom = new DOMDocument();

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
            $dom = new DOMDocument();

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
     * Purpose: Extract text from a DOCX document.xml payload.
     * Inputs: Raw WordprocessingML XML content.
     * Returns: Normalized plain text content.
     * Side effects: Parses XML in memory.
     */
    private function extractWordXmlText(string $xml): string
    {
        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument();

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
        $zip = new ZipArchive();

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
            $dom = new DOMDocument();

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
            $dom = new DOMDocument();

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
        $binary = config('services.pdftotext.binary');

        if (empty($binary)) {
            Log::warning('[Procynia][PDF] pdftotext binary is not configured or is not executable.');

            return '';
        }

        $result = Process::run([(string) $binary, '-nopgbrk', '-enc', 'UTF-8', $path, '-']);

        if (! $result->successful() || trim($result->output()) === '') {
            return '';
        }

        return $this->removePdfRunningHeaderFooterText($this->normalizeBlockText($result->output()));
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

        return $this->removePdfRunningHeaderFooterText($this->normalizeBlockText(implode("\n\n", $blocks)));
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
     * Purpose: Remove conservative PDF running header/footer lines from extracted text.
     * Inputs: Normalized extracted text.
     * Returns: The same text without safe footer/header lines.
     * Side effects: None.
     */
    private function removePdfRunningHeaderFooterText(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }

        $filteredLines = [];

        foreach (preg_split('/\n/u', str_replace(["\r\n", "\r"], "\n", $value)) ?: [] as $line) {
            $normalizedLine = $this->normalizeLineText((string) $line);

            if ($normalizedLine === '') {
                $filteredLines[] = '';
                continue;
            }

            if ($this->isPdfRunningHeaderFooterText($normalizedLine)) {
                continue;
            }

            $filteredLines[] = $normalizedLine;
        }

        return $this->normalizeBlockText(implode("\n", $filteredLines));
    }

    /**
     * Purpose: Detect safe PDF running header/footer text patterns.
     * Inputs: One normalized line of PDF text.
     * Returns: True only when the line is a conservative page marker or footer pattern.
     * Side effects: None.
     */
    private function isPdfRunningHeaderFooterText(string $text): bool
    {
        $normalized = $this->normalizeLineText($text);

        if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > 120) {
            return false;
        }

        if (preg_match('/^(?:Side|Page)\s+\d+\s+(?:av|of)\s+\d+$/iu', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^\d+\s*(?:\/|of)\s*\d+$/iu', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^(?:©|\(c\)|copyright\b).{0,80}\b(?:Side|Page)\s+\d+\s+(?:av|of)\s+\d+$/iu', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^\d{2}\.\d{2}\.\d{4}\s+(?:Side|Page)\s+\d+\s+(?:av|of)\s+\d+$/iu', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^(?:©|\(c\)|copyright\b).{0,80}\d{2}\.\d{2}\.\d{4}\s+(?:Side|Page)\s+\d+\s+(?:av|of)\s+\d+$/iu', $normalized) === 1) {
            return true;
        }

        return false;
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
