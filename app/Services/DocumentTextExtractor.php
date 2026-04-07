<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
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

            return trim(implode("\n", $paragraphs));
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

        return $this->normalizeWhitespace(implode("\n", array_filter($sheets)));
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

            return trim(implode("\n", $rows));
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
        $contents = @file_get_contents($path);

        if (! is_string($contents) || trim($contents) === '') {
            return '';
        }

        $chunks = [];
        $streamMatches = [];

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $contents, $streamMatches)) {
            foreach ($streamMatches[1] as $stream) {
                $decodedStream = $this->decodePdfStream((string) $stream);
                $text = $this->extractPdfTextFromChunk($decodedStream);

                if ($text !== '') {
                    $chunks[] = $text;
                }
            }
        } else {
            $text = $this->extractPdfTextFromChunk($contents);

            if ($text !== '') {
                $chunks[] = $text;
            }
        }

        return $this->normalizeWhitespace(implode(' ', $chunks));
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
        $texts = [];

        if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)\s*Tj/s', $chunk, $matches)) {
            foreach ($matches[0] as $match) {
                if (preg_match('/\(((?:\\\\.|[^\\\\()])*)\)\s*Tj/s', $match, $innerMatches)) {
                    $texts[] = $this->decodePdfString($innerMatches[1]);
                }
            }
        }

        if (preg_match_all('/\[((?:.|\n)*?)\]\s*TJ/s', $chunk, $arrayMatches)) {
            foreach ($arrayMatches[1] as $arrayChunk) {
                if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)/s', $arrayChunk, $stringMatches)) {
                    foreach ($stringMatches[1] as $stringValue) {
                        $texts[] = $this->decodePdfString($stringValue);
                    }
                }
            }
        }

        if ($texts === []) {
            return '';
        }

        return $this->normalizeWhitespace(implode(' ', $texts));
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

        return $this->normalizeWhitespace($value);
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
}
