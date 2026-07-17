<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\EnterpriseWiki\ReconcileEnterpriseWikiClaimSourcesForDocument;
use App\Jobs\EnterpriseWiki\RunEnterpriseWikiDocumentFlow;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestSection;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentWikiAnswerStalenessService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Support\CustomerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        private readonly EnterpriseWikiDocumentFlowService $documentFlowService,
        private readonly EnterpriseWikiDocumentWikiAnswerStalenessService $wikiAnswerStalenessService,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,docx', 'max:20480'],
            'owner_user_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $file = $validated['file'];
        $ownerUserId = $this->resolveOwnerUserIdForCustomer(
            $customerId,
            (int) ($validated['owner_user_id'] ?? $user?->id ?? 0),
        );
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

            $document = DB::transaction(function () use ($customerId, $user, $ownerUserId, $file, $storedPath, $fileHash, $extractedText): EnterpriseWikiDocument {
                return EnterpriseWikiDocument::query()->create([
                    'customer_id' => $customerId,
                    'uploaded_by_user_id' => $user?->id,
                    'owner_user_id' => $ownerUserId,
                    'original_filename' => $file->getClientOriginalName(),
                    'file_path' => $storedPath,
                    'file_hash_sha256' => $fileHash,
                    'extracted_text' => $extractedText !== '' ? $extractedText : null,
                    'document_status' => $extractedText !== ''
                        ? EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED
                        : EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED,
                ]);
            });

            if ($document->document_status === EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED) {
                ReconcileEnterpriseWikiClaimSourcesForDocument::dispatch($document->id);
            }
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

    public function updateOwner(Request $request, EnterpriseWikiDocument $document): RedirectResponse
    {
        $user = $this->customerContext->currentUser();
        $customerId = $this->customerContext->currentCustomerId();

        if ($document->customer_id !== $customerId) {
            abort(404);
        }

        abort_unless($user?->canAssignEnterpriseWikiDocumentOwner() ?? false, 403);

        $validated = $request->validate([
            'owner_user_id' => ['nullable', 'integer'],
        ]);

        $ownerUserId = array_key_exists('owner_user_id', $validated) && $validated['owner_user_id'] !== null
            ? $this->resolveOwnerUserIdForCustomer($customerId, (int) $validated['owner_user_id'])
            : null;

        DB::transaction(function () use ($customerId, $document, $ownerUserId): void {
            $lockedDocument = EnterpriseWikiDocument::query()
                ->where('customer_id', $customerId)
                ->where('id', $document->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedDocument->forceFill([
                'owner_user_id' => $ownerUserId,
            ])->save();

            $this->documentFlowService->syncDocumentOwnerApprovals($lockedDocument->fresh());
        });

        Log::info('[PROCYNIA][WIKI_SOURCE] Updated wiki document owner.', [
            'document_id' => $document->id,
            'customer_id' => $customerId,
            'owner_user_id' => $ownerUserId,
            'updated_by_user_id' => $user?->id,
        ]);

        return redirect()->route('app.wiki.index', ['tab' => 'sources'])
            ->with('success', 'Dokumenteier oppdatert.');
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

    public function deletePreview(EnterpriseWikiDocument $document): JsonResponse
    {
        $customerId = $this->customerContext->currentCustomerId();

        if ($document->customer_id !== $customerId) {
            abort(404);
        }

        $runs = EnterpriseWikiIngestRun::query()
            ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->where('source_id', $document->id)
            ->where('customer_id', $customerId)
            ->get(['id', 'status']);

        if ($runs->contains(fn (EnterpriseWikiIngestRun $run) => ! $run->isTerminal())) {
            return response()->json([
                'blocked' => true,
                'reason' => 'in_progress_run',
            ]);
        }

        [$soleSourcePageIds, $sharedPageIds] = $this->classifyPages($runs->pluck('id'));
        $staleImpact = $this->wikiAnswerStalenessService->previewDeletionImpact($document, $runs->pluck('id'), $soleSourcePageIds);

        return response()->json([
            'blocked' => false,
            'document_name' => $document->original_filename,
            'document_owner_name' => $document->owner?->name,
            'run_count' => $runs->count(),
            'sole_source_page_count' => $soleSourcePageIds->count(),
            'shared_page_count' => $sharedPageIds->count(),
            'stale_wiki_answer_count' => $staleImpact['stale_wiki_answer_count'],
            'impacted_claim_count' => $staleImpact['impacted_claim_count'],
            'impacted_source_reference_count' => $staleImpact['impacted_source_reference_count'],
        ]);
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
            ->get(['id', 'status']);

        if ($runs->contains(fn (EnterpriseWikiIngestRun $run) => ! $run->isTerminal())) {
            return redirect()->route('app.wiki.index')
                ->with('error', 'Kan ikke slette dokumentet mens ingest-jobben kjører.');
        }

        $runIds = $runs->pluck('id');
        [$soleSourcePageIds] = $this->classifyPages($runIds);
        $staleImpact = $this->wikiAnswerStalenessService->previewDeletionImpact($document, $runIds, $soleSourcePageIds);

        DB::transaction(function () use ($document, $runIds, $soleSourcePageIds): void {
            $this->wikiAnswerStalenessService->markAnswersStaleForDeletedDocument($document, $runIds, $soleSourcePageIds);

            // Delete lint findings for sole-source pages
            if ($soleSourcePageIds->isNotEmpty()) {
                EnterpriseWikiLintFinding::query()
                    ->whereIn('enterprise_wiki_page_id', $soleSourcePageIds)
                    ->delete();
            }

            // Delete lint findings tied to this document's runs
            if ($runIds->isNotEmpty()) {
                EnterpriseWikiLintFinding::query()
                    ->whereIn('enterprise_wiki_ingest_run_id', $runIds)
                    ->delete();
            }

            // Delete lint findings tied directly to this document
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_document_id', $document->id)
                ->delete();

            // Delete source references on any page's claims that point to this document
            EnterpriseWikiSourceReference::query()
                ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->where('source_id', $document->id)
                ->delete();

            // Delete sole-source pages (cascades: claims, source refs, versions, page links, run_pages)
            if ($soleSourcePageIds->isNotEmpty()) {
                EnterpriseWikiPage::query()
                    ->whereIn('id', $soleSourcePageIds)
                    ->delete();
            }

            // Delete ingest sections and runs
            if ($runIds->isNotEmpty()) {
                EnterpriseWikiIngestSection::query()
                    ->whereIn('enterprise_wiki_ingest_run_id', $runIds)
                    ->delete();

                EnterpriseWikiIngestRun::query()
                    ->whereIn('id', $runIds)
                    ->delete();
            }

            // Delete the uploaded file
            Storage::disk('local')->delete($document->file_path);

            $document->delete();
        });

        Log::info('[PROCYNIA][WIKI_SOURCE] Deleted wiki source document.', [
            'document_id' => $document->id,
            'customer_id' => $customerId,
            'sole_source_pages_deleted' => $soleSourcePageIds->count(),
            'stale_wiki_answers_marked' => $staleImpact['stale_wiki_answer_count'],
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

        if (! EnterpriseWikiMaintainerDecisionAiClient::isAvailable()) {
            return redirect()->route('app.wiki.index')
                ->with('error', 'Wiki-generering er ikke aktivert ennå.');
        }

        if ($document->document_status !== EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED) {
            return redirect()->route('app.wiki.index')
                ->with('error', 'Dokumentet er ikke klart for ingest. Kun ekstraherte dokumenter kan brukes.');
        }

        try {
            $prepared = $this->documentFlowService->prepareRunForDocument($customerId, $document->id);
        } catch (InvalidArgumentException $e) {
            Log::warning('[PROCYNIA][WIKI_SOURCE_INGEST] '.$e->getMessage(), ['document_id' => $document->id]);

            return redirect()->route('app.wiki.index')
                ->with('error', 'Kunne ikke starte ingest. Prøv igjen.');
        }

        $run = $prepared['run'];

        if ($prepared['created']) {
            RunEnterpriseWikiDocumentFlow::dispatch($run->id);
        }

        Log::info('[PROCYNIA][WIKI_SOURCE_INGEST] Queued ingest run.', [
            'run_id' => $run->id,
            'customer_id' => $customerId,
            'document_id' => $document->id,
            'created' => $prepared['created'],
        ]);

        return redirect()->route('app.wiki.index')
            ->with(
                'success',
                $prepared['created']
                    ? 'Wiki-utkast er satt i kø. Det vil snart være klart til gjennomgang.'
                    : 'Wiki-utkast er allerede i kø eller under behandling.',
            );
    }

    /**
     * Classify pages linked to the given run IDs as sole-source or shared.
     *
     * Sole-source: page only appears in run_pages rows for these runs.
     * Shared: page also appears in run_pages rows from other runs (other documents).
     *
     * @param  Collection<int, int>  $runIds
     * @return array{Collection<int, int>, Collection<int, int>}  [soleSourcePageIds, sharedPageIds]
     */
    private function classifyPages(Collection $runIds): array
    {
        if ($runIds->isEmpty()) {
            return [collect(), collect()];
        }

        $allPageIds = DB::table('enterprise_wiki_ingest_run_pages')
            ->whereIn('enterprise_wiki_ingest_run_id', $runIds)
            ->pluck('enterprise_wiki_page_id')
            ->unique()
            ->values();

        if ($allPageIds->isEmpty()) {
            return [collect(), collect()];
        }

        $sharedPageIds = DB::table('enterprise_wiki_ingest_run_pages')
            ->whereIn('enterprise_wiki_page_id', $allPageIds)
            ->whereNotIn('enterprise_wiki_ingest_run_id', $runIds)
            ->pluck('enterprise_wiki_page_id')
            ->unique()
            ->values();

        $soleSourcePageIds = $allPageIds->diff($sharedPageIds)->values();

        return [$soleSourcePageIds, $sharedPageIds];
    }

    private function resolveOwnerUserIdForCustomer(int $customerId, int $ownerUserId): int
    {
        $owner = User::query()
            ->whereKey($ownerUserId)
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->with('customer:id,permission_settings')
            ->first();

        if ($owner === null || ! $owner->canBeEnterpriseWikiDocumentOwner()) {
            throw ValidationException::withMessages([
                'owner_user_id' => __('procynia.wiki.document_owner_invalid'),
            ]);
        }

        return $owner->id;
    }
}
