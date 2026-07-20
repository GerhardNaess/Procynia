<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\EnterpriseWiki\ReconcileEnterpriseWikiClaimSourcesForDocument;
use App\Jobs\EnterpriseWiki\RunEnterpriseWikiDocumentFlow;
use App\Models\EnterpriseWikiDocument;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentDeletionService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Support\CustomerContext;
use Illuminate\Http\JsonResponse;
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
        private readonly EnterpriseWikiDocumentFlowService $documentFlowService,
        private readonly EnterpriseWikiDocumentDeletionService $deletionService,
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
        $user = $this->customerContext->currentUser();

        if ($document->customer_id !== $customerId) {
            abort(404);
        }

        abort_unless($user?->canDeleteEnterpriseWikiDocument($document) ?? false, 403);

        return response()->json($this->deletionService->preview($document));
    }

    public function destroy(EnterpriseWikiDocument $document): RedirectResponse
    {
        $customerId = $this->customerContext->currentCustomerId();
        $user = $this->customerContext->currentUser();

        if ($document->customer_id !== $customerId) {
            abort(404);
        }

        abort_unless($user?->canDeleteEnterpriseWikiDocument($document) ?? false, 403);

        $documentId = $document->id;
        $result = $this->deletionService->delete($document);

        if ($result['blocked'] ?? false) {
            return redirect()->route('app.wiki.index')
                ->with('error', __('procynia.wiki.delete_preview_blocked_in_progress'));
        }

        Log::info('[PROCYNIA][WIKI_SOURCE] Deleted wiki source document.', [
            'document_id' => $documentId,
            'customer_id' => $customerId,
            'runs_deleted' => $result['runs_deleted'],
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
