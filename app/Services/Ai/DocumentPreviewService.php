<?php

namespace App\Services\Ai;

use App\Models\SavedNoticeAiDocument;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use Throwable;

class DocumentPreviewService
{
    /**
     * Purpose: Resolve the canonical preview mode for one AI source document.
     * Inputs: The saved notice AI document row.
     * Returns: A preview mode string for the frontend.
     * Side effects: None.
     */
    public function previewMode(SavedNoticeAiDocument $document): string
    {
        $storedPath = (string) $document->stored_path;

        if (! $this->hasStoredSourceFile($storedPath)) {
            return 'unavailable';
        }

        if ($this->isPdfSource($document, $storedPath) || $this->isDocxSource($document, $storedPath)) {
            return 'pdf';
        }

        return 'unavailable';
    }

    /**
     * Purpose: Resolve the canonical preview file path for one AI source document.
     * Inputs: The saved notice AI document row.
     * Returns: The stored PDF path to use for previewing, or null when no preview is available.
     * Side effects: May lazily generate and persist a PDF preview for DOCX sources.
     */
    public function resolvePreviewFilePath(SavedNoticeAiDocument $document): ?string
    {
        $storedPath = (string) $document->stored_path;

        if (! $this->hasStoredSourceFile($storedPath)) {
            return null;
        }

        if ($this->isPdfSource($document, $storedPath)) {
            return $storedPath;
        }

        if (! $this->isDocxSource($document, $storedPath)) {
            return null;
        }

        $previewPath = $this->previewPathForDocument($document);

        if ($this->hasStoredSourceFile($previewPath) && Storage::disk('local')->size($previewPath) > 0) {
            return $previewPath;
        }

        if ($this->generateDocxPreviewPdf($document, $storedPath, $previewPath)) {
            return $previewPath;
        }

        return null;
    }

    /**
     * Purpose: Resolve the canonical preview URL for one AI source document.
     * Inputs: The saved notice AI document row.
     * Returns: A route URL to the preview PDF, or null when preview is unavailable.
     * Side effects: None.
     */
    public function previewFileUrl(SavedNoticeAiDocument $document): ?string
    {
        if ($this->previewMode($document) === 'unavailable') {
            return null;
        }

        return route('app.ai.documents.preview-file', [
            'savedNotice' => $document->saved_notice_id,
            'document' => $document->id,
        ]);
    }

    /**
     * Purpose: Resolve the deterministic PDF preview path for a DOCX source document.
     * Inputs: The saved notice AI document row.
     * Returns: A stable local storage path for the generated PDF preview.
     * Side effects: None.
     */
    private function previewPathForDocument(SavedNoticeAiDocument $document): string
    {
        return sprintf(
            'saved-notices/%d/ai-document-previews/%d.pdf',
            $document->saved_notice_id,
            $document->id,
        );
    }

    /**
     * Purpose: Determine whether the given source path exists on the local storage disk.
     * Inputs: A relative storage path.
     * Returns: True when the source file exists and is readable.
     * Side effects: None.
     */
    private function hasStoredSourceFile(string $storedPath): bool
    {
        return $storedPath !== '' && Storage::disk('local')->exists($storedPath);
    }

    /**
     * Purpose: Determine whether the stored source file is a PDF.
     * Inputs: The saved notice AI document row and its source path.
     * Returns: True when the file is a PDF source.
     * Side effects: None.
     */
    private function isPdfSource(SavedNoticeAiDocument $document, string $storedPath): bool
    {
        $filename = (string) ($document->original_filename ?: basename($storedPath));
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType = Str::lower(trim((string) ($document->mime_type ?? '')));

        return $extension === 'pdf' || $mimeType === 'application/pdf';
    }

    /**
     * Purpose: Determine whether the stored source file is a DOCX document.
     * Inputs: The saved notice AI document row and its source path.
     * Returns: True when the file is a DOCX source that can be converted to PDF preview.
     * Side effects: None.
     */
    private function isDocxSource(SavedNoticeAiDocument $document, string $storedPath): bool
    {
        $filename = (string) ($document->original_filename ?: basename($storedPath));
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType = Str::lower(trim((string) ($document->mime_type ?? '')));

        return $extension === 'docx'
            || $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    /**
     * Purpose: Convert a DOCX file into a cached PDF preview.
     * Inputs: Source DOCX path and the target PDF preview path.
     * Returns: True when the PDF preview was generated successfully.
     * Side effects: Reads the DOCX file, writes a generated PDF to local storage, and may log failures.
     */
    private function generateDocxPreviewPdf(
        SavedNoticeAiDocument $document,
        string $sourcePath,
        string $previewPath,
    ): bool
    {
        $htmlPath = tempnam(sys_get_temp_dir(), 'procynia-ai-preview-');

        if ($htmlPath === false) {
            return false;
        }

        try {
            $phpWord = IOFactory::load(Storage::disk('local')->path($sourcePath));
            $htmlWriter = IOFactory::createWriter($phpWord, 'HTML');

            $htmlWriter->save($htmlPath);

            $html = file_get_contents($htmlPath);

            if (! is_string($html) || trim($html) === '') {
                return false;
            }

            $html = $this->injectPreviewStyles($html);
            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            Storage::disk('local')->makeDirectory(dirname($previewPath));
            Storage::disk('local')->put($previewPath, $dompdf->output());

            return Storage::disk('local')->exists($previewPath) && Storage::disk('local')->size($previewPath) > 0;
        } catch (Throwable $throwable) {
            Log::warning('[PROCYNIA][AI_PREVIEW] DOCX preview generation failed.', [
                'saved_notice_ai_document_id' => $document->id,
                'source_path' => $sourcePath,
                'preview_path' => $previewPath,
                'error' => $throwable->getMessage(),
            ]);

            return false;
        } finally {
            if (is_file($htmlPath)) {
                @unlink($htmlPath);
            }
        }
    }

    /**
     * Purpose: Inject minimal PDF-friendly styling into generated HTML.
     * Inputs: Raw HTML produced by PHPWord.
     * Returns: HTML augmented with deterministic preview styling.
     * Side effects: None.
     */
    private function injectPreviewStyles(string $html): string
    {
        $styles = <<<'HTML'
<style>
    @page {
        margin: 18mm;
    }

    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 11pt;
        line-height: 1.45;
        color: #0f172a;
    }

    img {
        max-width: 100%;
        height: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }
</style>
HTML;

        if (str_contains($html, '</head>')) {
            return str_replace('</head>', $styles.'</head>', $html);
        }

        return '<!doctype html><html><head><meta charset="utf-8">'.$styles.'</head><body>'.$html.'</body></html>';
    }
}
