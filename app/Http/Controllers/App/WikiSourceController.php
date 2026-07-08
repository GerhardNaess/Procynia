<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiIngest;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestSection;
use App\Services\Ai\Wiki\EnterpriseWikiIngestService;
use App\Services\Ai\Wiki\WikiSectionAiClient;
use App\Services\DocumentTextExtractor;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class WikiSourceController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly DocumentTextExtractor $documentTextExtractor,
        private readonly EnterpriseWikiIngestService $ingestService,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,docx', 'max:20480'],
        ]);

        $file = $validated['file'];
        $fileHash = (string) hash_file('sha256', $file->getRealPath());

        $duplicate = EnterpriseWikiDocument::query()
            ->where('customer_id', $customerId)
            ->where('file_hash_sha256', $fileHash)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'file' => 'Dette dokumentet er allerede lastet opp for din virksomhet.',
            ]);
        }

        $storedPath = null;

        try {
            $ext = Str::lower(trim((string) $file->getClientOriginalExtension()));
            if ($ext === '') {
                $ext = 'bin';
            }
            $storedFilename = Str::ulid().'.'.$ext;
            $storedPath = Storage::disk('local')->putFileAs(
                sprintf('customers/%d/wiki-documents', $customerId),
                $file,
                $storedFilename,
            );

            abort_unless(is_string($storedPath) && $storedPath !== '', 500, 'Failed to store the wiki document.');

            $absolutePath = Storage::disk('local')->path($storedPath);
            $extractedText = trim($this->documentTextExtractor->extractText($absolutePath));

            DB::transaction(function () use ($customerId, $user, $file, $storedPath, $fileHash, $extractedText): void {
                EnterpriseWikiDocument::query()->create([
                    'customer_id' => $customerId,
                    'uploaded_by_user_id' => $user?->id,
                    'original_filename' => $file->getClientOriginalName(),
                    'file_path' => $storedPath,
                    'file_hash_sha256' => $fileHash,
                    'extracted_text' => $extractedText !== '' ? $extractedText : null,
                    'document_status' => $extractedText !== ''
                        ? EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED
                        : EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED,
                ]);
            });
        } catch (\Throwable $e) {
            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk('local')->delete($storedPath);
            }

            Log::error('[PROCYNIA][WIKI_SOURCE] Failed to store wiki document.', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return redirect()->route('app.wiki.index')
            ->with('success', 'Dokumentet er lastet opp og klart for ingest.');
    }

    public function download(EnterpriseWikiDocument $document): BinaryFileResponse
    {
        $customerId = $this->customerContext->currentCustomerId();

        if ($document->customer_id !== $customerId) {
            abort(404);
        }

        $disk = Storage::disk('local');
        abort_unless($disk->exists($document->file_path), 404);

        $mimeType = match (strtolower(pathinfo($document->original_filename, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };

        $response = response()->file($disk->path($document->file_path), ['Content-Type' => $mimeType]);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $document->original_filename);

        return $response;
    }

    public function destroy(EnterpriseWikiDocument $document): RedirectResponse
    {
        $customerId = $this->customerContext->currentCustomerId();

        if ($document->customer_id !== $customerId) {
            abort(404);
        }

        $runs = EnterpriseWikiIngestRun::query()
            ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->where('source_id', $document->id)
            ->where('customer_id', $customerId)
            ->get(['id', 'status', 'enterprise_wiki_page_id']);

        $inProgressStatuses = [
            EnterpriseWikiIngestRun::STATUS_QUEUED,
            EnterpriseWikiIngestRun::STATUS_RUNNING,
            EnterpriseWikiIngestRun::STATUS_SECTIONS_PLANNED,
        ];

        if ($runs->whereIn('status', $inProgressStatuses)->isNotEmpty()) {
            return redirect()->route('app.wiki.index')
                ->with('error', 'Kan ikke slette dokumentet mens ingest-jobben kjører.');
        }

        if ($runs->whereNotNull('enterprise_wiki_page_id')->isNotEmpty()) {
            return redirect()->route('app.wiki.index')
                ->with('error', 'Kan ikke slette dokumentet fordi det har genererte wiki-sider.');
        }

        DB::transaction(function () use ($document, $runs): void {
            if ($runs->isNotEmpty()) {
                EnterpriseWikiIngestSection::query()
                    ->whereIn('enterprise_wiki_ingest_run_id', $runs->pluck('id'))
                    ->delete();

                EnterpriseWikiIngestRun::query()
                    ->whereIn('id', $runs->pluck('id'))
                    ->delete();
            }

            Storage::disk('local')->delete($document->file_path);

            $document->delete();
        });

        Log::info('[PROCYNIA][WIKI_SOURCE] Deleted wiki source document.', [
            'document_id' => $document->id,
            'customer_id' => $customerId,
        ]);

        return redirect()->route('app.wiki.index')
            ->with('success', 'Kildedokumentet er slettet.');
    }

    public function ingest(EnterpriseWikiDocument $document): RedirectResponse
    {
        $customerId = $this->customerContext->currentCustomerId();

        if ($document->customer_id !== $customerId) {
            abort(403);
        }

        if (! WikiSectionAiClient::isAvailable()) {
            return redirect()->route('app.wiki.index')
                ->with('error', 'Wiki-generering er ikke aktivert ennå.');
        }

        if ($document->document_status !== EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED) {
            return redirect()->route('app.wiki.index')
                ->with('error', 'Dokumentet er ikke klart for ingest. Kun ekstraherte dokumenter kan brukes.');
        }

        try {
            $validated = $this->ingestService->resolveDocumentForIngest($customerId, $document->id);
        } catch (InvalidArgumentException $e) {
            Log::warning('[PROCYNIA][WIKI_SOURCE_INGEST] '.$e->getMessage(), ['document_id' => $document->id]);

            return redirect()->route('app.wiki.index')
                ->with('error', 'Kunne ikke starte ingest. Prøv igjen.');
        }

        $run = $this->ingestService->createQueuedRunForDocument($customerId, $validated);

        ProcessEnterpriseWikiIngest::dispatch($run->id);

        Log::info('[PROCYNIA][WIKI_SOURCE_INGEST] Queued ingest run.', [
            'run_id' => $run->id,
            'customer_id' => $customerId,
            'document_id' => $document->id,
        ]);

        return redirect()->route('app.wiki.index')
            ->with('success', 'Wiki-utkast er satt i kø. Det vil snart være klart til gjennomgang.');
    }
}
