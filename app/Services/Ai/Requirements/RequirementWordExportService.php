<?php

namespace App\Services\Ai\Requirements;

use App\Models\DocumentTemplate;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use DOMDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style;
use PhpOffice\PhpWord\TemplateProcessor;

class RequirementWordExportService
{
    /**
     * Purpose: Build a Word (.docx) document containing all requirements with saved answer drafts.
     * Inputs: The SavedNotice (for title/metadata) and a collection of SavedNoticeAiRequirement models.
     * Returns: Raw .docx binary as a string.
     * Side effects: Writes and deletes temporary files when customer templates are used.
     */
    public function build(SavedNotice $savedNotice, Collection $requirements): string
    {
        $standardDocument = $this->buildStandardExport($savedNotice, $requirements);

        try {
            $templatedDocument = $this->buildTemplatedExport($savedNotice, $standardDocument);

            if (is_string($templatedDocument) && $templatedDocument !== '') {
                return $templatedDocument;
            }
        } catch (\Throwable) {
            // Fall back to the standard export when a customer template cannot be applied safely.
        }

        return $standardDocument;
    }

    /**
     * Purpose: Build the standard Word export without applying a customer template.
     * Inputs: The SavedNotice and its requirement collection.
     * Returns: Raw .docx binary as a string.
     * Side effects: Writes and deletes a temp file.
     */
    private function buildStandardExport(SavedNotice $savedNotice, Collection $requirements): string
    {
        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);

        foreach ([
            1 => 18,
            2 => 13,
            3 => 12,
            4 => 11,
            5 => 10,
            6 => 9,
        ] as $level => $size) {
            Style::addTitleStyle($level, ['bold' => true, 'size' => $size, 'name' => 'Calibri']);
        }

        $section = $phpWord->addSection();

        $section->addTitle($savedNotice->title ?? '—', 1);
        $section->addTextBreak(1);

        $buyer = $savedNotice->notice?->contracting_body_name ?? '—';
        $deadline = $savedNotice->deadline
            ? $savedNotice->deadline->format('d.m.Y')
            : '—';
        $exportedAt = now()->format('d.m.Y');

        $section->addText("Kjøper: {$buyer}");
        $section->addText("Frist: {$deadline}");
        $section->addText("Eksportert: {$exportedAt}");
        $section->addTextBreak(1);

        /** @var SavedNoticeAiRequirement $requirement */
        foreach ($requirements as $requirement) {
            $identifier = (string) ($requirement->requirement_identifier ?? '');
            $headingText = $identifier !== '' ? "{$identifier}" : 'Krav';
            $section->addTitle($headingText, 2);

            $section->addText(
                'Kravtype: ' . $this->requirementTypeLabel((string) ($requirement->requirement_type ?? '')),
                ['bold' => false],
            );

            $section->addTextBreak(1);
            $section->addText('Kravtekst:', ['bold' => true]);
            $this->addMultilineText($section, (string) ($requirement->requirement_text ?? ''));

            $section->addTextBreak(1);
            $section->addText('Svarutkast:', ['bold' => true]);
            $this->addMarkdownMultilineText($section, (string) ($requirement->answer_draft_text ?? ''));

            $sources = is_array($requirement->answer_draft_retrieval_sources)
                ? $requirement->answer_draft_retrieval_sources
                : [];

            if ($sources !== []) {
                $section->addTextBreak(1);
                $section->addText('Kildegrunnlag:', ['bold' => true]);
                foreach ($sources as $source) {
                    $section->addListItem($this->sourceLabel($source), 0);
                }
            }

            $section->addTextBreak(2);
        }

        return $this->renderToString($phpWord);
    }

    /**
     * Purpose: Apply a customer-provided Word template to the standard export body.
     * Inputs: The saved notice and the already rendered standard export document.
     * Returns: A merged .docx binary when a usable template exists, otherwise null.
     * Side effects: Writes temporary docx files and patches the resulting package.
     */
    private function buildTemplatedExport(SavedNotice $savedNotice, string $standardDocument): ?string
    {
        $template = $this->resolveActiveWordExportTemplate($savedNotice);

        if ($template === null) {
            return null;
        }

        $templatePath = $this->resolveTemplateFilePath($template);

        if ($templatePath === null) {
            return null;
        }

        $sourcePath = $this->writeTempDocx($standardDocument, 'procynia_word_export_source_');
        $outputPath = $this->writeTempDocx('', 'procynia_word_export_output_');
        $templateProcessor = null;

        try {
            DocumentTemplate::validateStoredWordExportTemplate($template->file_disk, $template->file_path);

            $bodyXml = $this->extractBodyXmlFromDocx($sourcePath);

            if ($bodyXml === null) {
                return null;
            }

            $templateProcessor = new TemplateProcessor($templatePath);
            $templateProcessor->setMacroChars('[[', ']]');
            $templateProcessor->replaceXmlBlock(DocumentTemplate::CONTENT_PLACEHOLDER, $bodyXml, 'w:p');

            if (is_file($outputPath)) {
                @unlink($outputPath);
            }

            $templateProcessor->saveAs($outputPath);

            $binary = file_get_contents($outputPath);

            return is_string($binary) && $binary !== '' ? $binary : null;
        } catch (\Throwable) {
            return null;
        } finally {
            if ($templateProcessor !== null) {
                try {
                    $templateProcessor->setMacroChars('${', '}');
                } catch (\Throwable) {
                    // Ignore cleanup issues.
                }
            }

            $this->deleteTempFile($sourcePath);
            $this->deleteTempFile($outputPath);
        }
    }

    /**
     * Purpose: Resolve the active standard Word-export template for a saved notice customer.
     * Inputs: The saved notice being exported.
     * Returns: The matching template model or null when none exists.
     * Side effects: None.
     */
    private function resolveActiveWordExportTemplate(SavedNotice $savedNotice): ?DocumentTemplate
    {
        try {
            return DocumentTemplate::activeWordExportForCustomer((int) $savedNotice->customer_id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Purpose: Resolve the filesystem path for a persisted document template.
     * Inputs: The template model.
     * Returns: An absolute filesystem path when available, otherwise null.
     * Side effects: None.
     */
    private function resolveTemplateFilePath(DocumentTemplate $template): ?string
    {
        try {
            $storage = Storage::disk($template->file_disk ?: 'local');

            if (! $storage->exists($template->file_path)) {
                return null;
            }

            $path = $storage->path($template->file_path);

            return is_string($path) && $path !== '' && is_file($path) && is_readable($path)
                ? $path
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Purpose: Extract the main body XML from a DOCX file, excluding the trailing section properties.
     * Inputs: A DOCX filepath.
     * Returns: Raw body XML that can be inserted into another Word document.
     * Side effects: None.
     */
    private function extractBodyXmlFromDocx(string $docxPath): ?string
    {
        $zip = new \ZipArchive();

        if ($zip->open($docxPath) !== true) {
            return null;
        }

        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (! is_string($documentXml) || $documentXml === '') {
            return null;
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;

        if (! @$dom->loadXML($documentXml)) {
            return null;
        }

        $body = $dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'body')->item(0)
            ?? $dom->getElementsByTagName('w:body')->item(0)
            ?? $dom->getElementsByTagName('body')->item(0);

        if (! $body instanceof \DOMElement) {
            return null;
        }

        $innerXml = '';

        foreach ($body->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'sectPr') {
                continue;
            }

            $innerXml .= $dom->saveXML($child);
        }

        return trim($innerXml) !== '' ? $innerXml : null;
    }

    /**
     * Purpose: Write a temporary DOCX file for intermediate template processing.
     * Inputs: Optional binary contents and a filename prefix.
     * Returns: A writable temporary DOCX path.
     * Side effects: Creates a temporary file on disk.
     */
    private function writeTempDocx(string $contents = '', string $prefix = 'procynia_word_export_'): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), $prefix);

        if ($tempPath === false) {
            throw new \RuntimeException('Unable to create a temporary DOCX file.');
        }

        $docxPath = $tempPath . '.docx';

        if (! @rename($tempPath, $docxPath)) {
            @unlink($tempPath);
            throw new \RuntimeException('Unable to prepare a temporary DOCX filename.');
        }

        if ($contents !== '') {
            file_put_contents($docxPath, $contents);
        }

        return $docxPath;
    }

    /**
     * Purpose: Delete a temporary file if it exists.
     * Inputs: The temporary file path, or null.
     * Returns: None.
     * Side effects: Removes the file from disk.
     */
    private function deleteTempFile(?string $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function addMultilineText(mixed $section, string $text): void
    {
        $lines = $this->splitLines($text);
        foreach ($lines as $index => $line) {
            $section->addText($line !== '' ? $line : ' ');
        }
    }

    private function addMarkdownMultilineText(mixed $section, string $text): void
    {
        foreach ($this->splitLines($text) as $line) {
            $heading = $this->parseMarkdownHeadingLine($line);

            if (is_array($heading)) {
                $section->addTitle($heading['text'], $heading['level']);

                continue;
            }

            $section->addText($line !== '' ? $line : ' ');
        }
    }

    /**
     * Purpose: Split text into lines while handling CRLF and lone CR endings safely.
     * Inputs: Raw multi-line text.
     * Returns: A line list preserving empty lines.
     * Side effects: None.
     *
     * @return array<int, string>
     */
    private function splitLines(string $text): array
    {
        return explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * Purpose: Detect a Markdown heading line and map it to a Word heading level.
     * Inputs: One line of exported answer-draft text.
     * Returns: Heading metadata when the line is a Markdown heading, otherwise null.
     * Side effects: None.
     *
     * @return array{text: string, level: int}|null
     */
    private function parseMarkdownHeadingLine(string $line): ?array
    {
        if (trim($line) === '') {
            return null;
        }

        if (preg_match('/^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/u', $line, $matches) !== 1) {
            return null;
        }

        $headingText = trim((string) $matches[2]);

        if ($headingText === '') {
            return null;
        }

        $markdownLevel = strlen((string) $matches[1]);

        return [
            'text' => $headingText,
            'level' => $this->markdownHeadingLevelToWordTitleLevel($markdownLevel),
        ];
    }

    /**
     * Purpose: Offset Markdown heading depth to a document heading style depth.
     * Inputs: The Markdown heading level from the AI answer draft.
     * Returns: A Word title level that stays below the document title and requirement heading.
     * Side effects: None.
     */
    private function markdownHeadingLevelToWordTitleLevel(int $markdownLevel): int
    {
        return min(6, max(2, $markdownLevel + 1));
    }

    private function sourceLabel(array $source): string
    {
        $chunkType = (string) ($source['chunk_type'] ?? 'semantic');
        $title = (string) ($source['knowledge_item_title'] ?? 'Ukjent kilde');
        $headingPath = (string) ($source['heading_path'] ?? $source['section_path'] ?? '');
        $suffix = $headingPath !== '' ? ' – ' . $headingPath : '';

        if ($chunkType === 'table') {
            return 'Kilde inneholder tabell: ' . $title . $suffix;
        }

        if ($chunkType === 'image') {
            $caption = (string) ($source['image_caption'] ?? $source['image_alt_text'] ?? '');
            $captionSuffix = $caption !== '' ? ' – ' . $caption : '';

            return 'Kilde inneholder grafikk: ' . $title . $captionSuffix . $suffix;
        }

        return $title . $suffix;
    }

    private function requirementTypeLabel(string $type): string
    {
        return match ($type) {
            'technical' => 'Teknisk',
            'documentation' => 'Dokumentasjon',
            'administrative' => 'Administrativt',
            default => $type !== '' ? $type : 'Uspesifisert',
        };
    }

    private function renderToString(PhpWord $phpWord): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'procynia_docx_');
        try {
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($tmpFile);
            $contents = (string) file_get_contents($tmpFile);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        return $contents;
    }
}
