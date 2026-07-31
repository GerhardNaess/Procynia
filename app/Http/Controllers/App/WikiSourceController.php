<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\EnterpriseWiki\ReconcileEnterpriseWikiClaimSourcesForDocument;
use App\Jobs\EnterpriseWiki\RunEnterpriseWikiDocumentFlow;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentDeletionService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSourceElementService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Support\CustomerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        private readonly EnterpriseWikiDocumentDeletionService $deletionService,
        private readonly EnterpriseWikiDocumentSourceElementService $sourceElementService,
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

    /**
     * Serves a single Word image extracted from this document's ordinary content flow. There is
     * no separate stored copy of the image anywhere (Fase 1 Enterprise Wiki image support
     * deliberately reuses the document's own already-private, already customer-scoped storage
     * instead of introducing a parallel media store — see CLAUDE.md: "reuse existing
     * architecture") — the image is re-extracted from the stored .docx on every request and its
     * bytes are re-encoded through GD before being served, which both validates the bytes really
     * decode as the claimed format and strips any embedded metadata (e.g. JPEG EXIF) the source
     * file might carry (Section 5: "remove or ignore unnecessary metadata").
     */
    public function image(EnterpriseWikiDocument $document, string $imageKey): Response
    {
        $customerId = $this->customerContext->currentCustomerId();

        if ($document->customer_id !== $customerId) {
            abort(404);
        }

        $image = $this->sourceElementService->imageBySourceKey($document, $imageKey);

        if ($image === null) {
            abort(404);
        }

        $servableBytes = $this->resolveServableImageBytes($image->bytes, $image->mimeType);

        abort_if($servableBytes === null, 404);

        return response($servableBytes, 200, [
            'Content-Type' => $image->mimeType,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Purpose: Decide what bytes are safe to serve for one image, preferring a GD re-encode (which
     * strips embedded metadata, e.g. JPEG EXIF — Section 5: "remove or ignore unnecessary
     * metadata") but falling back to the original bytes when GD cannot decode an otherwise
     * genuinely well-formed image (some valid, spec-compliant PNG/JPEG files exercise decoder
     * edge cases GD's bundled libraries reject even though the format's own header is intact).
     * Only bytes that fail BOTH the GD re-encode and a raw header check are treated as truly
     * corrupt and refused (Section 12: a corrupt image must be handled in a controlled way, not
     * served as broken content — but a merely GD-unfriendly one should still display).
     * Inputs: The raw bytes and the MIME type DocumentTextExtractor determined at extraction time.
     * Returns: Bytes ready to serve, or null when the bytes are genuinely corrupt/unsupported.
     * Side effects: None (operates entirely in memory).
     */
    private function resolveServableImageBytes(string $bytes, ?string $mimeType): ?string
    {
        $resource = @imagecreatefromstring($bytes);

        if ($resource !== false) {
            ob_start();

            $encoded = match ($mimeType) {
                'image/png' => imagepng($resource),
                'image/jpeg' => imagejpeg($resource, null, 90),
                default => false,
            };

            $reencodedBytes = ob_get_clean();
            imagedestroy($resource);

            if ($encoded && is_string($reencodedBytes) && $reencodedBytes !== '') {
                return $reencodedBytes;
            }
        }

        $headerInfo = @getimagesizefromstring($bytes);
        $headerMimeType = is_array($headerInfo) ? ($headerInfo['mime'] ?? null) : null;

        return $headerMimeType === $mimeType ? $bytes : null;
    }

    public function deletePreview(EnterpriseWikiDocument $document): JsonResponse
    {
        $customerId = $this->customerContext->currentCustomerId();
        $user = $this->customerContext->currentUser();

        if ($document->customer_id !== $customerId) {
            abort(404);
        }

        abort_unless($user?->canDeleteEnterpriseWikiDocument($document) ?? false, 403);

        return response()->json($this->deletionService->preview($document));
    }

    /**
     * Cancels every non-terminal ingest run for this document so the document becomes eligible
     * for the ordinary deletion flow, which is blocked by ANY non-terminal run (see
     * EnterpriseWikiDocumentDeletionService::hasActiveRun()). Deliberately separate from
     * WikiController::cancelRun() (the Kjøringer-tab "Avbryt kjøring" action, which only allows
     * cancelling a run EnterpriseWikiIngestRun::isCancellable() considers still genuinely under
     * automatic processing): a run stuck waiting on Document Owner approval has nothing left
     * running to interrupt from the Kjøringer tab, but it still blocks deletion, and this action
     * exists specifically to unblock that — never presented as an ordinary "stop the run" control.
     */
    public function cancelBlockingRunsForDeletion(EnterpriseWikiDocument $document): RedirectResponse
    {
        $customerId = $this->customerContext->currentCustomerId();
        $user = $this->customerContext->currentUser();

        if ($document->customer_id !== $customerId) {
            abort(404);
        }

        abort_unless($user instanceof User && $user->canDeleteEnterpriseWikiDocument($document), 403);

        $blockingRuns = $this->deletionService->documentRuns($document)
            ->reject(fn (EnterpriseWikiIngestRun $run): bool => $run->isTerminal());

        if ($blockingRuns->isEmpty()) {
            return redirect()->route('app.wiki.index')
                ->with('error', __('procynia.wiki.cancel_blocking_runs_none_active'));
        }

        foreach ($blockingRuns as $run) {
            $this->documentFlowService->cancelRun($run, $user);
        }

        Log::info('[PROCYNIA][WIKI_SOURCE] Cancelled blocking ingest runs to unblock document deletion.', [
            'document_id' => $document->id,
            'customer_id' => $customerId,
            'run_ids' => $blockingRuns->pluck('id')->all(),
            'user_id' => $user->id,
        ]);

        return redirect()->route('app.wiki.index')
            ->with('success', __('procynia.wiki.cancel_blocking_runs_success'));
    }

    public function destroy(EnterpriseWikiDocument $document): RedirectResponse
    {
        $customerId = $this->customerContext->currentCustomerId();
        $user = $this->customerContext->currentUser();

        if ($document->customer_id !== $customerId) {
            abort(404);
        }

        abort_unless($user instanceof User && $user->canDeleteEnterpriseWikiDocument($document), 403);

        $documentId = $document->id;
        $result = $this->deletionService->delete($document, $user);

        if ($result['blocked'] ?? false) {
            return redirect()->route('app.wiki.index')
                ->with('error', __('procynia.wiki.delete_preview_blocked_in_progress'));
        }

        Log::info('[PROCYNIA][WIKI_SOURCE] Deleted wiki source document.', [
            'document_id' => $documentId,
            'customer_id' => $customerId,
            'runs_deleted' => $result['runs_deleted'],
            'pending_approval_runs_cancelled' => $result['pending_approval_runs_cancelled'],
            'sole_source_pages_deleted' => $result['sole_source_pages_deleted'],
            'shared_pages_kept' => $result['shared_pages_kept'],
            'page_versions_deleted' => $result['page_versions_deleted'],
            'claims_affected' => $result['claims_affected'],
            'findings_deleted' => $result['findings_deleted'],
            'stale_wiki_answers_marked' => $result['stale_wiki_answers_marked'],
            'storage_deleted' => $result['storage_deleted'],
            'storage_error' => $result['storage_error'],
        ]);

        return redirect()->route('app.wiki.index')
            ->with('success', $this->deletionSuccessMessage($result));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function deletionSuccessMessage(array $result): string
    {
        if (! ($result['storage_deleted'] ?? true)) {
            return __('procynia.wiki.source_delete_failure_storage');
        }

        $message = __('procynia.wiki.source_delete_success');

        $soleSourcePages = (int) ($result['sole_source_pages_deleted'] ?? 0);
        $runs = (int) ($result['runs_deleted'] ?? 0);
        $sharedPages = (int) ($result['shared_pages_kept'] ?? 0);

        $message .= ' '.__('procynia.wiki.source_delete_success_summary', [
            'pages' => $soleSourcePages,
            'runs' => $runs,
        ]);

        if ($sharedPages > 0) {
            $message .= ' '.trans_choice('procynia.wiki.source_delete_shared_pages_kept', $sharedPages, [
                'count' => $sharedPages,
            ]);
        }

        return $message;
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
