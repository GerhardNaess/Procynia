<?php

namespace App\Services\Ai\Requirements;

use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use Illuminate\Support\Collection;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style;

class RequirementWordExportService
{
    /**
     * Purpose: Build a Word (.docx) document containing all requirements with saved answer drafts.
     * Inputs: The SavedNotice (for title/metadata) and a collection of SavedNoticeAiRequirement models.
     * Returns: Raw .docx binary as a string.
     * Side effects: Writes and deletes a temp file.
     */
    public function build(SavedNotice $savedNotice, Collection $requirements): string
    {
        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);

        Style::addTitleStyle(1, ['bold' => true, 'size' => 18, 'name' => 'Calibri']);
        Style::addTitleStyle(2, ['bold' => true, 'size' => 13, 'name' => 'Calibri']);

        $section = $phpWord->addSection();

        $section->addTitle($savedNotice->title ?? '—', 1);
        $section->addTextBreak(1);

        $buyer = $savedNotice->notice?->contracting_body_name ?? '—';
        $deadline = $savedNotice->submission_deadline
            ? $savedNotice->submission_deadline->format('d.m.Y')
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
            $this->addMultilineText($section, (string) ($requirement->answer_draft_text ?? ''));

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

    private function addMultilineText(mixed $section, string $text): void
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $text));
        foreach ($lines as $index => $line) {
            $section->addText($line !== '' ? $line : ' ');
        }
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
