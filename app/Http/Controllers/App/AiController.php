<?php

namespace App\Http\Controllers\App;

use App\Data\Ai\Requirements\RequirementEditData;
use App\Data\Ai\Requirements\RequirementViewData;
use App\Http\Controllers\Controller;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\RequirementExtractionCall;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiAnswerBasisItem;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiEvidence;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementAssessment;
use App\Models\User;
use App\Services\Ai\AiUsageGuard;
use App\Services\Ai\DocumentPreviewService;
use App\Services\Ai\Requirements\RequirementAnswerBasisService;
use App\Services\Ai\Requirements\RequirementAnswerDraftService;
use App\Services\Ai\Requirements\RequirementEditorService;
use App\Services\Ai\Requirements\RequirementExtractionPipeline;
use App\Services\Ai\Requirements\RequirementExtractionRunService;
use App\Services\Ai\Requirements\RequirementGroundingJudgeService;
use App\Services\Ai\Requirements\RequirementKnowledgeDocumentRecommendationService;
use App\Services\Ai\Requirements\RequirementLoader;
use App\Services\Ai\Requirements\RequirementWordExportService;
use App\Services\Ai\Retrieval\KnowledgeMetadataMapService;
use App\Services\Ai\Retrieval\MetadataCandidateRetrievalService;
use App\Services\Ai\Retrieval\MetadataRetrievalPlanService;
use App\Services\Ai\Retrieval\MetadataRetrievalPlanValidator;
use App\Services\Billing\BillingEntitlementService;
use App\Services\DocumentChunker;
use App\Services\DocumentTextExtractor;
use App\Services\InfoCenter\RequirementResponsibilityTaskService;
use App\Services\KnowledgeChunkCoverageService;
use App\Services\OpenAi\EmbeddingService;
use App\Services\RequirementAssessmentService;
use App\Services\RequirementKnowledgeMatcher;
use App\Services\SavedNoticeAccessService;
use App\Support\CustomerContext;
use App\Support\PgVector;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AiController extends Controller
{
    private const ANALYSIS_ACTIVE_STATUSES = [
        SavedNotice::BID_STATUS_DISCOVERED,
        SavedNotice::BID_STATUS_QUALIFYING,
        SavedNotice::BID_STATUS_GO_NO_GO,
        SavedNotice::BID_STATUS_IN_PROGRESS,
        SavedNotice::BID_STATUS_SUBMITTED,
        SavedNotice::BID_STATUS_NEGOTIATION,
    ];

    private const CASE_DOCUMENT_DELETE_BLOCKING_STATUSES = [
        SavedNoticeAiDocument::PROCESSING_STATUS_QUEUED,
        SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
        SavedNoticeAiDocument::PROCESSING_STATUS_TEXT_EXTRACTED,
        SavedNoticeAiDocument::PROCESSING_STATUS_MERGING,
    ];

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SavedNoticeAccessService $savedNoticeAccess,
        private readonly DocumentTextExtractor $documentTextExtractor,
        private readonly DocumentChunker $documentChunker,
        private readonly RequirementExtractionPipeline $requirementExtractionPipeline,
        private readonly RequirementExtractionRunService $requirementExtractionRunService,
        private readonly RequirementLoader $requirementLoader,
        private readonly RequirementAnswerBasisService $requirementAnswerBasisService,
        private readonly RequirementAnswerDraftService $requirementAnswerDraftService,
        private readonly RequirementGroundingJudgeService $requirementGroundingJudgeService,
        private readonly KnowledgeMetadataMapService $knowledgeMetadataMapService,
        private readonly MetadataRetrievalPlanService $metadataRetrievalPlanService,
        private readonly MetadataRetrievalPlanValidator $metadataRetrievalPlanValidator,
        private readonly MetadataCandidateRetrievalService $metadataCandidateRetrievalService,
        private readonly RequirementKnowledgeDocumentRecommendationService $requirementKnowledgeDocumentRecommendationService,
        private readonly RequirementEditorService $requirementEditorService,
        private readonly RequirementKnowledgeMatcher $requirementKnowledgeMatcher,
        private readonly DocumentPreviewService $documentPreviewService,
        private readonly KnowledgeChunkCoverageService $knowledgeChunkCoverageService,
        private readonly RequirementResponsibilityTaskService $requirementResponsibilityTaskService,
        private readonly AiUsageGuard $aiUsageGuard,
        private readonly RequirementWordExportService $requirementWordExportService,
    ) {}

    /**
     * Purpose: Render the AI workspace landing page for customer case work.
     * Inputs: The current frontend request.
     * Returns: Inertia\Response for the AI index page.
     * Side effects: None.
     */
    public function index(Request $request): Response
    {
        [$user, $customerId] = $this->frontendContext($request);
        $analysisCases = $this->analysisCases($user, $customerId);
        $canUseAiOffer = $this->customerCanUseAiOffer($user);

        return Inertia::render('App/AI/Index', [
            'pageTitle' => 'Oversikt',
            'analysisCases' => $analysisCases,
            'can_use_ai_offer' => $canUseAiOffer,
        ]);
    }

    /**
     * Purpose: Render the AI case view for a visible saved notice.
     * Inputs: The current request and the route-bound saved notice model.
     * Returns: Inertia\Response for the AI case page.
     * Side effects: None.
     */
    public function show(Request $request, SavedNotice $savedNotice): Response
    {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $record->loadMissing([
            'bidManager',
            'opportunityOwner',
            'aiDocuments.uploadedBy',
            'aiDocuments.chunks',
            'aiDocuments.latestExtractionRun',
            'answerBasisItems.createdBy',
        ])->loadCount([
            'infoItems',
            'phaseComments',
            'submissions',
            'businessReviews',
        ]);

        $analysisCase = $this->analysisCasePayload($record);
        $requirements = $this->requirementLoader->loadForCase($record->id);
        $requirementsPayload = $this->aiRequirementsPayload($requirements);
        $requirementsOverview = $this->requirementsOverviewPayload($requirements);
        $canUseAiOffer = $this->customerCanUseAiOffer($request->user());

        return Inertia::render('App/AI/Show', [
            'pageTitle' => sprintf('I arbeid · %s', $record->title),
            'saved_notice_show_url' => route('app.notices.saved.show', ['savedNotice' => $record->id]),
            'case' => [
                'id' => $analysisCase['id'],
                'title' => $analysisCase['title'],
                'reference' => $analysisCase['reference'],
                'owner' => $analysisCase['owner_name'],
                'stage' => $analysisCase['stage_label'],
                'updated_at' => $analysisCase['updated_at'],
            ],
            'ai_status' => $analysisCase['ai_status'],
            'requirements_count' => count($requirementsPayload),
            'requirements_overview' => $requirementsOverview,
            'requirements' => $requirementsPayload,
            'requirements_store_url' => route('app.ai.requirements.store', ['savedNotice' => $record->id]),
            'requirements_destroy_all_url' => route('app.ai.requirements.destroy-all', ['savedNotice' => $record->id]),
            'assessment_refresh_url' => route('app.ai.requirements.assessment.refresh', ['savedNotice' => $record->id]),
            'evidence_refresh_url' => route('app.ai.evidence.refresh', ['savedNotice' => $record->id]),
            'assigned_user_options' => $this->customerRequirementAssigneeOptions((int) $record->customer_id),
            'assignable_users' => $this->customerAssignableUsers((int) $record->customer_id),
            'documents_upload_url' => route('app.ai.documents.store', ['savedNotice' => $record->id]),
            'documents' => $this->aiDocumentsPayload($record),
            'answer_basis_items' => $this->aiAnswerBasisItemsPayload($record->answerBasisItems),
            'answer_basis_documents_upload_url' => route('app.ai.answer-basis.documents.store', ['savedNotice' => $record->id]),
            'answer_basis_text_store_url' => route('app.ai.answer-basis.texts.store', ['savedNotice' => $record->id]),
            'export_docx_url' => route('app.ai.requirements.export.docx', ['savedNotice' => $record->id]),
            'can_use_ai_offer' => $canUseAiOffer,
        ]);
    }

    /**
     * Purpose: Render the dedicated AI instruction page for a visible saved notice.
     * Inputs: The current request and the route-bound saved notice model.
     * Returns: Inertia\Response for the AI instruction page.
     * Side effects: None.
     */
    public function instructions(Request $request, SavedNotice $savedNotice): Response
    {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);
        $analysisCase = $this->analysisCasePayload($record);

        return Inertia::render('App/AI/Instructions', [
            'pageTitle' => 'AI instrukser',
            'case' => [
                'id' => $analysisCase['id'],
                'title' => $analysisCase['title'],
                'reference' => $analysisCase['reference'],
                'owner' => $analysisCase['owner_name'],
                'stage' => $analysisCase['stage_label'],
                'updated_at' => $analysisCase['updated_at'],
            ],
            'ai_instructions' => (string) ($record->ai_instructions ?? ''),
            'ai_instructions_update_url' => route('app.ai.instructions.update', ['savedNotice' => $record->id]),
        ]);
    }

    /**
     * Purpose: Persist the case-level AI instructions for a visible saved notice.
     * Inputs: The current request and the route-bound saved notice.
     * Returns: A redirect back to the AI case view after saving the instructions.
     * Side effects: Updates the saved notice row.
     */
    public function updateAiInstructions(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);

        $validated = $request->validate([
            'ai_instructions' => ['nullable', 'string', 'max:20000'],
        ]);

        $normalizedInstructions = trim(str_replace(["\r\n", "\r"], "\n", (string) ($validated['ai_instructions'] ?? '')));

        $record->forceFill([
            'ai_instructions' => $normalizedInstructions !== '' ? $normalizedInstructions : null,
        ])->save();

        return back()->with('success', 'AI-instruks lagret.');
    }

    /**
     * Purpose: Render a deterministic in-app preview for one uploaded AI document.
     * Inputs: The current request, route-bound saved notice, and route-bound document.
     * Returns: An Inertia response for the source document preview page.
     * Side effects: None.
     */
    public function previewDocument(
        Request $request,
        SavedNotice $savedNotice,
        SavedNoticeAiDocument $document,
    ): Response {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);
        $ownedDocument = $record->aiDocuments()
            ->with('uploadedBy')
            ->whereKey($document->id)
            ->firstOrFail();

        $analysisCase = $this->analysisCasePayload($record);
        $previewFilePath = $this->documentPreviewService->resolvePreviewFilePath($ownedDocument);
        $previewMode = is_string($previewFilePath) && $previewFilePath !== ''
            ? 'pdf'
            : 'unavailable';
        $previewFileUrl = $previewMode === 'pdf'
            ? route('app.ai.documents.preview-file', [
                'savedNotice' => $record->id,
                'document' => $ownedDocument->id,
            ])
            : null;

        return Inertia::render('App/AI/DocumentPreview', [
            'pageTitle' => sprintf('Kilde · %s', $ownedDocument->original_filename ?: basename((string) $ownedDocument->stored_path)),
            'case' => [
                'id' => $analysisCase['id'],
                'title' => $analysisCase['title'],
                'reference' => $analysisCase['reference'],
                'owner' => $analysisCase['owner_name'],
                'stage' => $analysisCase['stage_label'],
                'updated_at' => $analysisCase['updated_at'],
            ],
            'document' => $this->aiDocumentPreviewPayload($ownedDocument, $previewMode, $previewFileUrl),
            'back_url' => route('app.ai.show', ['savedNotice' => $record->id]),
        ]);
    }

    /**
     * Purpose: Stream the canonical PDF preview for one visible AI source document.
     * Inputs: The current request, route-bound saved notice, and route-bound document.
     * Returns: An inline PDF file response that the preview page can embed.
     * Side effects: May lazily generate and persist a PDF preview for DOCX sources.
     */
    public function previewPdfDocument(
        Request $request,
        SavedNotice $savedNotice,
        SavedNoticeAiDocument $document,
    ): BinaryFileResponse {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);
        $ownedDocument = $record->aiDocuments()
            ->with('uploadedBy')
            ->whereKey($document->id)
            ->firstOrFail();

        $previewPath = $this->documentPreviewService->resolvePreviewFilePath($ownedDocument);

        abort_unless(is_string($previewPath) && $previewPath !== '' && Storage::disk('local')->exists($previewPath), 404);

        $previewName = sprintf(
            '%s.pdf',
            pathinfo((string) ($ownedDocument->original_filename ?: basename((string) $ownedDocument->stored_path)), PATHINFO_FILENAME),
        );

        $response = response()->file(Storage::disk('local')->path($previewPath), [
            'Content-Type' => 'application/pdf',
        ]);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $previewName);

        return $response;
    }

    /**
     * Purpose: Persist one or more uploaded documents on a visible AI case.
     * Inputs: The current request and the route-bound saved notice.
     * Returns: A redirect back to the AI case view after saving the uploads.
     * Side effects: Stores files on disk and creates SavedNotice AI document rows.
     */
    public function storeDocuments(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);

        $request->validate([
            'documents' => ['required', 'array', 'min:1'],
            'documents.*' => ['file', 'mimes:pdf,docx,xlsx', 'max:20480'],
        ]);

        $documents = $request->file('documents', []);
        $usageWarning = $this->aiUsageGuard->assertCanStartAiOperation(
            $record->customer()->firstOrFail(),
            $request->user(),
            AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD,
            count($documents),
        );

        if ($usageWarning !== null) {
            session()->flash('warning', $usageWarning);
        }
        $uploadedCount = 0;
        $uploadStartedAt = microtime(true);
        $requestRunId = (string) Str::uuid();
        $lastDocumentId = null;
        $lastRunId = null;

        Log::info('[PROCYNIA][AI_HANG] Upload request received.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $requestRunId,
            'document_id' => null,
            'saved_notice_id' => $record->id,
            'requested_document_count' => count($documents),
        ]);

        foreach ($documents as $document) {
            if (! $document) {
                continue;
            }

            $originalFilename = $document->getClientOriginalName();
            $extension = Str::lower((string) ($document->getClientOriginalExtension() ?: $document->extension() ?: 'bin'));
            $storedFilename = Str::ulid().'.'.$extension;
            $storedPath = Storage::disk('local')->putFileAs(
                sprintf('saved-notices/%d/ai-documents', $record->id),
                $document,
                $storedFilename,
            );

            abort_unless(is_string($storedPath) && $storedPath !== '', 500, 'Failed to store AI document.');

            $absolutePath = Storage::disk('local')->path($storedPath);
            $extractedText = $this->documentTextExtractor->extractText($absolutePath);
            $structuredBlocks = $this->documentTextExtractor->extractStructuredText($absolutePath);

            $documentRecord = $record->aiDocuments()->create([
                'uploaded_by_user_id' => $request->user()?->id,
                'original_filename' => $originalFilename,
                'stored_path' => $storedPath,
                'mime_type' => $document->getClientMimeType(),
                'file_size_bytes' => (int) $document->getSize(),
                'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_UPLOADED,
                'extracted_text' => $extractedText,
                'text_extracted_at' => now(),
            ]);

            $documentRunId = (string) Str::uuid();

            $documentRecord->forceFill([
                'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_TEXT_EXTRACTED,
            ])->save();

            Log::info('[PROCYNIA][AI_HANG] Document extraction request accepted.', [
                'timestamp' => now()->toIso8601String(),
                'run_id' => $documentRunId,
                'document_id' => $documentRecord->id,
                'saved_notice_ai_document_id' => $documentRecord->id,
                'saved_notice_id' => $record->id,
                'document_title' => $originalFilename,
                'document_filename' => $storedFilename,
                'document_text_length' => mb_strlen(trim((string) $extractedText), 'UTF-8'),
                'uploaded_document_index' => $uploadedCount + 1,
                'requested_document_count' => count($documents),
            ]);

            $this->syncDocumentChunks($documentRecord, $structuredBlocks);
            $queuedRun = $this->requirementExtractionRunService->createQueuedRunForDocument($documentRecord);
            $documentRunId = $queuedRun->uuid;

            $uploadedCount++;
            $lastDocumentId = $documentRecord->id;
            $lastRunId = $documentRunId;
        }

        $message = $uploadedCount === 1
            ? 'Uploaded 1 document.'
            : sprintf('Uploaded %d documents.', $uploadedCount);

        Log::info('[PROCYNIA][AI_HANG] Controller returning response.', [
            'timestamp' => now()->toIso8601String(),
            'run_id' => $requestRunId,
            'document_id' => $lastDocumentId,
            'document_run_id' => $lastRunId,
            'saved_notice_id' => $record->id,
            'uploaded_document_count' => $uploadedCount,
            'elapsed_ms' => (int) round((microtime(true) - $uploadStartedAt) * 1000),
        ]);

        return redirect()
            ->route('app.ai.show', ['savedNotice' => $record->id])
            ->with('success', $message);
    }

    /**
     * Purpose: Delete one uploaded AI document from a visible saved notice.
     * Inputs: The current request, route-bound saved notice, and route-bound document.
     * Returns: A redirect back to the AI case view after removing the document.
     * Side effects: Deletes the stored file and cascades related chunks and requirements.
     */
    public function destroyDocument(
        Request $request,
        SavedNotice $savedNotice,
        SavedNoticeAiDocument $document,
    ): RedirectResponse {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);
        $ownedDocument = $record->aiDocuments()
            ->whereKey($document->id)
            ->firstOrFail();

        if ($this->documentDeleteIsBlocked($ownedDocument)) {
            return redirect()
                ->route('app.ai.show', ['savedNotice' => $record->id])
                ->with('error', __('procynia.ai.document_delete_blocked'));
        }

        $storedPath = $ownedDocument->stored_path;

        DB::transaction(function () use ($ownedDocument): void {
            $ownedDocument->delete();
        });

        if (is_string($storedPath) && $storedPath !== '') {
            Storage::disk('local')->delete($storedPath);
        }

        return redirect()
            ->route('app.ai.show', ['savedNotice' => $record->id])
            ->with('success', 'Deleted 1 document.');
    }

    /**
     * Purpose: Decide whether an AI case document is still protected from hard deletion.
     * Inputs: The owned document row.
     * Returns: True when the document still has processing, chunk, requirement, or extraction history.
     * Side effects: None.
     */
    private function documentDeleteIsBlocked(SavedNoticeAiDocument $document): bool
    {
        if (in_array($document->processing_status, self::CASE_DOCUMENT_DELETE_BLOCKING_STATUSES, true)) {
            return true;
        }

        if ($document->chunks()->exists()) {
            return true;
        }

        if ($document->requirements()->exists()) {
            return true;
        }

        return $document->extractionRuns()->exists();
    }

    /**
     * Purpose: Persist one or more supplier-owned answer basis documents on a visible AI case.
     * Inputs: The current request and the route-bound saved notice.
     * Returns: A redirect back to the AI case view after saving the uploads.
     * Side effects: Stores files on disk and creates answer basis rows.
     */
    public function storeAnswerBasisDocuments(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);

        $request->validate([
            'documents' => ['required', 'array', 'min:1'],
            'documents.*' => ['file', 'mimes:pdf,docx,xlsx', 'max:20480'],
        ]);

        $createdItems = $this->requirementAnswerBasisService->createDocumentItems(
            $record,
            $request->file('documents', []),
            $request->user(),
        );

        return redirect()
            ->route('app.ai.show', ['savedNotice' => $record->id])
            ->with('success', sprintf('%d svargrunnlagsdokumenter lagt til.', $createdItems->count()));
    }

    /**
     * Purpose: Persist one supplier-owned answer basis text item on a visible AI case.
     * Inputs: The current request and the route-bound saved notice.
     * Returns: A redirect back to the AI case view after saving the text item.
     * Side effects: Creates an answer basis row in the database.
     */
    public function storeAnswerBasisText(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body_text' => ['required', 'string', 'max:20000'],
        ]);

        $this->requirementAnswerBasisService->createTextItem(
            $record,
            (string) $validated['title'],
            (string) $validated['body_text'],
            $request->user(),
        );

        return redirect()
            ->route('app.ai.show', ['savedNotice' => $record->id])
            ->with('success', 'Svargrunnlag lagt til.');
    }

    /**
     * Purpose: Delete one answer basis item from a visible AI case.
     * Inputs: The current request, route-bound saved notice, and route-bound answer basis item.
     * Returns: A redirect back to the AI case view after removing the item.
     * Side effects: Deletes the stored file and cascades related selections.
     */
    public function destroyAnswerBasisItem(
        Request $request,
        SavedNotice $savedNotice,
        SavedNoticeAiAnswerBasisItem $answerBasisItem,
    ): RedirectResponse {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);
        $ownedItem = $record->answerBasisItems()
            ->whereKey($answerBasisItem->id)
            ->firstOrFail();

        $this->requirementAnswerBasisService->deleteItem($ownedItem);

        return redirect()
            ->route('app.ai.show', ['savedNotice' => $record->id])
            ->with('success', 'Svargrunnlag slettet.');
    }

    /**
     * Purpose: Download one uploaded AI document from a visible saved notice.
     * Inputs: The current request, route-bound saved notice, and route-bound document.
     * Returns: A file response that streams the stored AI document back to the browser.
     * Side effects: None.
     */
    public function downloadDocument(
        Request $request,
        SavedNotice $savedNotice,
        SavedNoticeAiDocument $document,
    ): BinaryFileResponse {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);
        $ownedDocument = $record->aiDocuments()
            ->whereKey($document->id)
            ->firstOrFail();
        $storedPath = $ownedDocument->stored_path;

        abort_unless(is_string($storedPath) && $storedPath !== '', 404);
        abort_unless(Storage::disk('local')->exists($storedPath), 404);

        $downloadName = (string) ($ownedDocument->original_filename ?: basename($storedPath));
        $headers = [];
        $contentType = $this->aiDocumentMimeTypeForResponse($ownedDocument, $storedPath);

        if (is_string($contentType) && $contentType !== '') {
            $headers['Content-Type'] = $contentType;
        }

        $response = response()->file(Storage::disk('local')->path($storedPath), $headers);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $downloadName);

        return $response;
    }

    /**
     * Purpose: Export all requirements with saved answer drafts as a Word (.docx) document.
     * Inputs: The current request and the route-bound saved notice.
     * Returns: A streamed .docx download response, or 422 if no drafts exist.
     * Side effects: None.
     */
    public function exportRequirementsToDocx(
        Request $request,
        SavedNotice $savedNotice,
    ): StreamedResponse|\Illuminate\Http\Response {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);

        $requirements = $record->aiRequirements()
            ->whereNotNull('answer_draft_text')
            ->where('answer_draft_text', '!=', '')
            ->orderBy('requirement_identifier')
            ->get();

        if ($requirements->isEmpty()) {
            return response('', 422);
        }

        $docxContents = $this->requirementWordExportService->build($record, $requirements);
        $filename = 'tilbudsbesvarelse-'.$record->id.'.docx';

        return response()->streamDownload(
            function () use ($docxContents): void {
                echo $docxContents;
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        );
    }

    /**
     * Purpose: Update the canonical review status for a single AI requirement candidate.
     * Inputs: The current request, route-bound saved notice, and route-bound requirement candidate.
     * Returns: A redirect back to the AI case view after persisting the review status.
     * Side effects: Updates the requirement review_status in the database.
     */
    public function updateRequirementReviewStatus(
        Request $request,
        SavedNotice $savedNotice,
        SavedNoticeAiRequirement $requirement,
    ): RedirectResponse {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);
        $ownedRequirement = $record->aiRequirements()
            ->whereKey($requirement->id)
            ->firstOrFail();

        $validated = $request->validate([
            'review_status' => ['required', 'string', Rule::in(SavedNoticeAiRequirement::REVIEW_STATUSES)],
        ]);

        $this->requirementEditorService->transitionRequirementReviewStatus(
            $ownedRequirement,
            (string) $validated['review_status'],
            $request->user(),
        );

        return back()->with('success', 'Kravstatus oppdatert.');
    }

    /**
     * Purpose: Persist a manually created requirement for the visible AI case.
     * Inputs: The current request and the route-bound saved notice.
     * Returns: A redirect back to the AI case view after creating the requirement row.
     * Side effects: Creates a new manual requirement row and a revision row.
     */
    public function storeRequirement(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);

        $validated = $request->validate([
            'requirement_identifier' => ['nullable', 'string', 'max:255'],
            'requirement_text' => ['required', 'string', 'max:20000'],
            'requirement_type' => ['required', 'string', Rule::in(SavedNoticeAiRequirement::REQUIREMENT_TYPES)],
        ]);

        $this->requirementEditorService->createManualRequirement(
            $record,
            RequirementEditData::fromArray($validated),
            $request->user(),
        );

        return back()->with('success', 'Krav lagt til.');
    }

    /**
     * Purpose: Delete all requirement candidates for the visible AI case.
     * Inputs: The current request and the route-bound saved notice.
     * Returns: A redirect back to the AI case view after deletion.
     * Side effects: Deletes all SavedNoticeAiRequirement rows for the notice.
     */
    public function destroyAllRequirements(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);

        $record->aiRequirements()
            ->where('source_type', SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE)
            ->delete();

        return back()->with('success', 'Alle ekstraherte kravkandidater er slettet.');
    }

    /**
     * Purpose: Persist edits to a single visible requirement.
     * Inputs: The current request, route-bound saved notice, and route-bound requirement candidate.
     * Returns: A redirect back to the AI case view after saving the edits.
     * Side effects: Updates the canonical requirement row and creates a revision row.
     */
    public function updateRequirement(
        Request $request,
        SavedNotice $savedNotice,
        SavedNoticeAiRequirement $requirement,
    ): RedirectResponse {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);
        $ownedRequirement = $record->aiRequirements()
            ->whereKey($requirement->id)
            ->firstOrFail();

        $validated = $request->validate([
            'requirement_identifier' => ['nullable', 'string', 'max:255'],
            'requirement_text' => ['required', 'string', 'max:20000'],
            'requirement_type' => ['required', 'string', Rule::in(SavedNoticeAiRequirement::REQUIREMENT_TYPES)],
        ]);

        $this->requirementEditorService->updateRequirement(
            $ownedRequirement,
            RequirementEditData::fromArray($validated),
            $request->user(),
        );

        return back()->with('success', 'Krav oppdatert.');
    }

    /**
     * Purpose: Persist the responsible user for one visible requirement.
     * Inputs: The current request, route-bound saved notice, and route-bound requirement candidate.
     * Returns: A JSON response with the updated requirement payload.
     * Side effects: Updates assigned_user_id in the database.
     */
    public function updateRequirementAssignedUser(
        Request $request,
        SavedNotice $savedNotice,
        SavedNoticeAiRequirement $requirement,
    ): JsonResponse {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);

        $ownedRequirement = $record->aiRequirements()
            ->whereKey($requirement->id)
            ->firstOrFail();

        $validated = $request->validate([
            'assigned_user_id' => [
                'nullable',
                'integer',
                Rule::exists(User::class, 'id')->where(fn ($query) => $query
                    ->where('customer_id', $record->customer_id)),
            ],
        ]);

        DB::transaction(function () use ($ownedRequirement, $validated, $request): void {
            $ownedRequirement->forceFill([
                'assigned_user_id' => array_key_exists('assigned_user_id', $validated) && $validated['assigned_user_id'] !== null
                    ? (int) $validated['assigned_user_id']
                    : null,
            ])->save();

            $this->requirementResponsibilityTaskService->syncRequirementTask($ownedRequirement, $request->user());
        });

        $ownedRequirement->load([
            'assignedUser',
            'document',
            'chunk',
            'answerBasisItems',
            'assessment.assessedBy',
            'evidence.knowledgeItem',
            'evidence.knowledgeItemChunk',
            'evidence.knowledgeItemVersion',
            'revisions.changedBy',
        ])->loadCount('revisions');

        return response()->json([
            'requirement' => $this->aiRequirementPayload($ownedRequirement),
            'assigned_user_id' => $ownedRequirement->assigned_user_id !== null ? (int) $ownedRequirement->assigned_user_id : null,
            'assigned_user' => $ownedRequirement->assignedUser ? [
                'id' => $ownedRequirement->assignedUser->id,
                'name' => $ownedRequirement->assignedUser->name,
                'email' => $ownedRequirement->assignedUser->email,
            ] : null,
        ]);
    }

    /**
     * Purpose: Update the operational work status and assignment for one confirmed requirement candidate.
     * Inputs: The current request, route-bound saved notice, and route-bound requirement candidate.
     * Returns: A redirect back to the AI case view after persisting the work changes.
     * Side effects: Updates work_status and assigned_user_id in the database.
     */
    public function updateRequirementWork(
        Request $request,
        SavedNotice $savedNotice,
        SavedNoticeAiRequirement $requirement,
    ): RedirectResponse {
        [$user, $customerId] = $this->frontendContext($request);

        $record = $this->savedNoticeAccess->visibleQueryFor($user)
            ->where('customer_id', $customerId)
            ->whereKey($savedNotice->id)
            ->firstOrFail();
        $this->assertAiAccess($record);

        $ownedRequirement = $record->aiRequirements()
            ->whereKey($requirement->id)
            ->firstOrFail();

        abort_unless($ownedRequirement->isApproved(), 422, 'Only approved requirements can be assigned work.');

        $validated = $request->validate([
            'work_status' => ['required', 'string', Rule::in(SavedNoticeAiRequirement::WORK_STATUSES)],
            'assigned_user_id' => [
                'nullable',
                'integer',
                Rule::exists(User::class, 'id')->where(fn ($query) => $query
                    ->where('customer_id', $customerId)
                    ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])),
            ],
        ]);

        DB::transaction(function () use ($ownedRequirement, $validated, $request): void {
            $ownedRequirement->forceFill([
                'work_status' => $validated['work_status'],
                'assigned_user_id' => isset($validated['assigned_user_id']) && $validated['assigned_user_id'] !== null
                    ? (int) $validated['assigned_user_id']
                    : null,
            ])->save();

            $this->requirementResponsibilityTaskService->syncRequirementTask($ownedRequirement, $request->user());
        });

        return back();
    }

    /**
     * Purpose: Generate and persist one answer draft for a visible requirement candidate.
     * Inputs: The current request, route-bound saved notice, and route-bound requirement candidate.
     * Returns: A JSON response with the persisted answer draft payload.
     * Side effects: May call OpenAI and updates the requirement row.
     */
    public function generateRequirementAnswerDraft(
        Request $request,
        SavedNotice $savedNotice,
        SavedNoticeAiRequirement $requirement,
    ): JsonResponse {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);
        $ownedRequirement = $record->aiRequirements()
            ->whereKey($requirement->id)
            ->firstOrFail();

        $validated = $request->validate([
            'answer_basis_item_ids' => ['present', 'array'],
            'answer_basis_item_ids.*' => ['integer'],
            'force' => ['sometimes', 'boolean'],
            'user_answer_prompt' => ['nullable', 'string', 'max:5000'],
        ]);

        $userAnswerPrompt = $this->normalizeOptionalPromptText($validated['user_answer_prompt'] ?? null);
        $usageWarning = $this->aiUsageGuard->assertCanStartAiOperation(
            $record->customer()->firstOrFail(),
            $request->user(),
            AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT,
        );

        if ($usageWarning !== null) {
            session()->flash('warning', $usageWarning);
        }

        Log::info('[PROCYNIA][REAL_RETRIEVAL_PATH] generateRequirementAnswerDraft entry.', [
            'route_name' => $request->route()?->getName(),
            'request_url' => $request->fullUrl(),
            'controller_method' => __METHOD__,
            'saved_notice_id' => $record->id,
            'requirement_id' => $ownedRequirement->id,
            'customer_id' => $record->customer_id,
        ]);

        $selectedAnswerBasisItems = $this->syncRequirementAnswerBasisSelectionItems(
            $record,
            $ownedRequirement,
            $validated['answer_basis_item_ids'],
        );

        $languageCode = $this->customerContext->resolveLanguageCode();

        $retrievedKnowledgeChunks = $this->retrievedKnowledgeChunksForRequirement($request, $record, $ownedRequirement);
        $knowledgeGrounding = $this->calculateKnowledgeGroundingLevel($retrievedKnowledgeChunks, $ownedRequirement->requirement_text);

        if ($this->shouldBlockAnswerDraftGeneration($knowledgeGrounding)) {
            $missingKnowledge = $this->missingKnowledgeInstructionPayload($ownedRequirement);

            Log::warning('[PROCYNIA][AI_GROUNDING] Answer draft generation blocked due to weak knowledge grounding.', [
                'saved_notice_id' => $record->id,
                'requirement_id' => $ownedRequirement->id,
                'coverage_status' => data_get($knowledgeGrounding, 'level'),
                'coverage_score' => data_get($knowledgeGrounding, 'max_score'),
                'retrieved_chunk_count' => $retrievedKnowledgeChunks->count(),
                'recommended_document_title' => $missingKnowledge['recommended_document_title'],
                'suggested_filename' => $missingKnowledge['suggested_filename'],
                'reason' => 'knowledge_grounding_red',
            ]);

            $selectedAnswerBasisItems = collect($selectedAnswerBasisItems->all());

            return response()->json(array_merge(
                [
                    'requirement_id' => $ownedRequirement->id,
                    'warning' => $usageWarning,
                    'answer_draft' => $this->blockedAnswerDraftPayload($knowledgeGrounding, $missingKnowledge),
                    'answer_basis_item_ids' => $selectedAnswerBasisItems
                        ->pluck('id')
                        ->map(static fn (mixed $value): int => (int) $value)
                        ->values()
                        ->all(),
                    'answer_basis_items' => $this->aiAnswerBasisItemsPayload($selectedAnswerBasisItems),
                    'retrieval_sources' => $retrievedKnowledgeChunks->all(),
                    'knowledge_grounding' => $knowledgeGrounding,
                    'knowledge_sources_sent_to_ai' => [],
                ],
            ));
        }

        Log::info('[PROCYNIA][AI_GROUNDING_JUDGE] Running grounding judge before answer generation.', [
            'saved_notice_id' => $record->id,
            'requirement_id' => $ownedRequirement->id,
            'coverage_status' => data_get($knowledgeGrounding, 'level'),
            'coverage_score' => data_get($knowledgeGrounding, 'max_score'),
            'retrieved_chunk_count' => $retrievedKnowledgeChunks->count(),
        ]);

        Log::info('[PROCYNIA][AI_GROUNDING_JUDGE] Judge context snapshot prepared.', $this->groundingJudgeContextDiagnostics(
            $record->id,
            $ownedRequirement->id,
            $retrievedKnowledgeChunks,
        ));

        try {
            $groundingJudge = $this->requirementGroundingJudgeService->judge(
                $ownedRequirement,
                $retrievedKnowledgeChunks,
                $knowledgeGrounding,
                $languageCode,
            );
        } catch (Throwable $exception) {
            Log::warning('[PROCYNIA][AI_GROUNDING_JUDGE] Grounding judge failed. Blocking answer generation safely.', [
                'saved_notice_id' => $record->id,
                'requirement_id' => $ownedRequirement->id,
                'coverage_status' => data_get($knowledgeGrounding, 'level'),
                'coverage_score' => data_get($knowledgeGrounding, 'max_score'),
                'retrieved_chunk_count' => $retrievedKnowledgeChunks->count(),
                'reason' => 'grounding_judge_failed',
                'error' => $exception->getMessage(),
            ]);

            $syntheticJudge = [
                'status' => 'unsupported',
                'can_generate_answer' => false,
                'directly_supported_points' => [],
                'related_but_insufficient_points' => [],
                'unsupported_points' => [],
                'missing_knowledge_summary' => 'Procynia kunne ikke vurdere kunnskapsgrunnlaget sikkert.',
                'recommended_document_title' => null,
                'suggested_filename' => null,
                'reasoning_summary' => null,
            ];
            $missingKnowledge = $this->judgeBlockedMissingKnowledgePayload($ownedRequirement, $syntheticJudge, $retrievedKnowledgeChunks);

            return response()->json(array_merge(
                [
                    'requirement_id' => $ownedRequirement->id,
                    'warning' => $usageWarning,
                    'answer_draft' => $this->blockedAnswerDraftPayload($knowledgeGrounding, $missingKnowledge),
                    'answer_basis_item_ids' => $selectedAnswerBasisItems
                        ->pluck('id')
                        ->map(static fn (mixed $value): int => (int) $value)
                        ->values()
                        ->all(),
                    'answer_basis_items' => $this->aiAnswerBasisItemsPayload($selectedAnswerBasisItems),
                    'retrieval_sources' => $retrievedKnowledgeChunks->all(),
                    'knowledge_grounding' => $knowledgeGrounding,
                    'knowledge_sources_sent_to_ai' => [],
                ],
            ));
        }

        Log::info('[PROCYNIA][AI_GROUNDING_JUDGE] Grounding judge completed.', [
            'saved_notice_id' => $record->id,
            'requirement_id' => $ownedRequirement->id,
            'coverage_status' => data_get($knowledgeGrounding, 'level'),
            'coverage_score' => data_get($knowledgeGrounding, 'max_score'),
            'judge_status' => data_get($groundingJudge, 'status'),
            'can_generate_answer' => data_get($groundingJudge, 'can_generate_answer'),
            'retrieved_chunk_count' => $retrievedKnowledgeChunks->count(),
            'recommended_document_title' => data_get($groundingJudge, 'recommended_document_title'),
        ]);

        if (! (bool) data_get($groundingJudge, 'can_generate_answer', false)) {
            $missingKnowledge = $this->judgeBlockedMissingKnowledgePayload($ownedRequirement, $groundingJudge, $retrievedKnowledgeChunks);

            Log::warning('[PROCYNIA][AI_GROUNDING_JUDGE] Answer draft generation blocked by grounding judge.', [
                'saved_notice_id' => $record->id,
                'requirement_id' => $ownedRequirement->id,
                'coverage_status' => data_get($knowledgeGrounding, 'level'),
                'coverage_score' => data_get($knowledgeGrounding, 'max_score'),
                'judge_status' => data_get($groundingJudge, 'status'),
                'can_generate_answer' => false,
                'retrieved_chunk_count' => $retrievedKnowledgeChunks->count(),
                'recommended_document_title' => $missingKnowledge['recommended_document_title'],
                'suggested_filename' => $missingKnowledge['suggested_filename'],
                'reason' => 'grounding_judge_blocked',
            ]);

            $selectedAnswerBasisItems = collect($selectedAnswerBasisItems->all());

            return response()->json(array_merge(
                [
                    'requirement_id' => $ownedRequirement->id,
                    'answer_draft' => $this->blockedAnswerDraftPayload($knowledgeGrounding, $missingKnowledge),
                    'answer_basis_item_ids' => $selectedAnswerBasisItems
                        ->pluck('id')
                        ->map(static fn (mixed $value): int => (int) $value)
                        ->values()
                        ->all(),
                    'answer_basis_items' => $this->aiAnswerBasisItemsPayload($selectedAnswerBasisItems),
                    'retrieval_sources' => $retrievedKnowledgeChunks->all(),
                    'knowledge_grounding' => $knowledgeGrounding,
                ],
            ));
        }

        $persistedRequirement = $this->requirementAnswerDraftService->ensureAnswerDraft(
            $ownedRequirement,
            $selectedAnswerBasisItems,
            (bool) ($validated['force'] ?? false),
            $record->ai_instructions,
            $userAnswerPrompt,
            $retrievedKnowledgeChunks,
            $groundingJudge,
            $languageCode,
            (int) $record->customer_id,
            (int) ($request->user()?->id ?? 0),
        );

        DB::table('saved_notice_ai_requirements')
            ->where('id', $persistedRequirement->id)
            ->update([
                'answer_draft_retrieval_sources' => json_encode(
                    $retrievedKnowledgeChunks->all(),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
            ]);
        $persistedRequirement->refresh();

        $this->syncRequirementEvidence(
            $ownedRequirement,
            $retrievedKnowledgeChunks,
            $request->user()?->id,
            null,
        );

        $persistedRequirement->load([
            'evidence.knowledgeItem',
            'evidence.knowledgeItemChunk',
            'evidence.knowledgeItemVersion',
        ]);

        Log::info('[PROCYNIA][AI_GROUNDING_JUDGE] Grounding judge allowed answer generation.', [
            'saved_notice_id' => $record->id,
            'requirement_id' => $ownedRequirement->id,
            'coverage_status' => data_get($knowledgeGrounding, 'level'),
            'coverage_score' => data_get($knowledgeGrounding, 'max_score'),
            'judge_status' => data_get($groundingJudge, 'status'),
            'can_generate_answer' => true,
            'retrieved_chunk_count' => $retrievedKnowledgeChunks->count(),
            'recommended_document_title' => data_get($groundingJudge, 'recommended_document_title'),
        ]);

        $selectedAnswerBasisItems = collect($selectedAnswerBasisItems->all());

        $judgeStatus = (string) data_get($groundingJudge, 'status');
        $coverageSummary = $judgeStatus === 'partial'
            ? $this->partialCoverageSummaryPayload($groundingJudge, $retrievedKnowledgeChunks)
            : null;

        return response()->json(array_merge(
            $this->aiRequirementAnswerDraftResponsePayload($persistedRequirement, $judgeStatus, $coverageSummary),
            [
                'warning' => $usageWarning,
                'answer_basis_item_ids' => $selectedAnswerBasisItems
                    ->pluck('id')
                    ->map(static fn (mixed $value): int => (int) $value)
                    ->values()
                    ->all(),
                'answer_basis_items' => $this->aiAnswerBasisItemsPayload($selectedAnswerBasisItems),
                'retrieval_sources' => $retrievedKnowledgeChunks->all(),
                'knowledge_grounding' => $knowledgeGrounding,
                'knowledge_sources_sent_to_ai' => $this->aiRequirementKnowledgeSourcesPayload($persistedRequirement),
            ],
        ));
    }

    /**
     * Purpose: Normalize optional user prompt text for one answer draft generation request.
     * Inputs: Raw request value from the answer draft generation form.
     * Returns: A trimmed string or null when the user has not provided an individual prompt.
     * Side effects: None.
     */
    private function normalizeOptionalPromptText(mixed $value): ?string
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", (string) ($value ?? '')));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Purpose: Persist edits to one visible requirement answer draft.
     * Inputs: The current request, route-bound saved notice, and route-bound requirement candidate.
     * Returns: A JSON response with the persisted answer draft payload.
     * Side effects: Updates the requirement row.
     */
    public function updateRequirementAnswerDraft(
        Request $request,
        SavedNotice $savedNotice,
        SavedNoticeAiRequirement $requirement,
    ): JsonResponse {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);
        $ownedRequirement = $record->aiRequirements()
            ->whereKey($requirement->id)
            ->firstOrFail();

        $validated = $request->validate([
            'answer_draft_text' => ['required', 'string', 'max:20000'],
        ]);

        $persistedRequirement = $this->requirementAnswerDraftService->updateAnswerDraft(
            $ownedRequirement,
            (string) $validated['answer_draft_text'],
        );

        return response()->json($this->aiRequirementAnswerDraftResponsePayload($persistedRequirement));
    }

    /**
     * Purpose: Synchronize the selected answer basis items for one visible requirement candidate.
     * Inputs: The current request, route-bound saved notice, and route-bound requirement candidate.
     * Returns: A JSON response with the persisted selection payload.
     * Side effects: Updates the selection pivot rows for the requirement.
     */
    public function syncRequirementAnswerBasisSelection(
        Request $request,
        SavedNotice $savedNotice,
        SavedNoticeAiRequirement $requirement,
    ): JsonResponse {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);
        $ownedRequirement = $record->aiRequirements()
            ->whereKey($requirement->id)
            ->firstOrFail();

        $validated = $request->validate([
            'answer_basis_item_ids' => ['present', 'array'],
            'answer_basis_item_ids.*' => ['integer'],
        ]);

        $selectedAnswerBasisItems = $this->syncRequirementAnswerBasisSelectionItems(
            $record,
            $ownedRequirement,
            $validated['answer_basis_item_ids'],
        );

        $selectedAnswerBasisItems = collect($selectedAnswerBasisItems->all());

        return response()->json([
            'requirement_id' => $ownedRequirement->id,
            'answer_basis_item_ids' => $selectedAnswerBasisItems
                ->pluck('id')
                ->map(static fn (mixed $value): int => (int) $value)
                ->values()
                ->all(),
            'answer_basis_items' => $this->aiAnswerBasisItemsPayload($selectedAnswerBasisItems),
        ]);
    }

    /**
     * Purpose: Synchronize selected answer basis items for a requirement within the visible case.
     * Inputs: The visible saved notice, the owned requirement, and raw answer basis item ids.
     * Returns: The canonical selected answer basis item collection.
     * Side effects: Updates the selection pivot rows for the requirement.
     */
    private function syncRequirementAnswerBasisSelectionItems(
        SavedNotice $record,
        SavedNoticeAiRequirement $requirement,
        array $answerBasisItemIds,
    ): Collection {
        $selectedItems = $this->requirementAnswerBasisService->syncRequirementSelection(
            $requirement,
            $answerBasisItemIds,
        );

        return $selectedItems->filter(static fn (SavedNoticeAiAnswerBasisItem $item): bool => (int) $item->saved_notice_id === (int) $record->id)
            ->values();
    }

    /**
     * Purpose: Rebuild persisted evidence rows for every confirmed requirement in the visible AI case.
     * Inputs: The current request and the route-bound saved notice.
     * Returns: A redirect back to the AI case view after refreshing the evidence rows.
     * Side effects: Deletes stale auto-suggested evidence rows and recreates deterministic matches.
     */
    public function refreshEvidence(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);
        $userId = $request->user()?->id;

        $confirmedRequirements = $this->requirementLoader->loadApprovedForCase($record->id);
        if ($confirmedRequirements->isNotEmpty()) {
            $usageWarning = $this->aiUsageGuard->assertCanStartAiOperation(
                $record->customer()->firstOrFail(),
                $request->user(),
                AiUsageGuard::OPERATION_SAVED_NOTICE_EVIDENCE_REFRESH,
                $confirmedRequirements->count(),
            );

            if ($usageWarning !== null) {
                session()->flash('warning', $usageWarning);
            }
        }

        $requirementEmbeddings = $confirmedRequirements->mapWithKeys(function (SavedNoticeAiRequirement $requirement): array {
            return [$requirement->id => $this->requirementEmbeddingFor($requirement)];
        });

        DB::transaction(function () use ($confirmedRequirements, $record, $userId, $requirementEmbeddings): void {
            foreach ($confirmedRequirements as $requirement) {
                $requirementEmbedding = $requirementEmbeddings->get($requirement->id);
                $knowledgeChunks = $this->knowledgeChunksForMatching((int) $record->customer_id, $requirementEmbedding);
                $this->syncRequirementEvidence(
                    $requirement,
                    $knowledgeChunks,
                    $userId,
                    $requirementEmbedding,
                );
            }
        });

        return back()->with('success', 'Bevisgrunnlag oppdatert.');
    }

    /**
     * Purpose: Rebuild persisted assessment rows for every confirmed requirement in the visible AI case.
     * Inputs: The current request and the route-bound saved notice.
     * Returns: A redirect back to the AI case view after refreshing the assessment rows.
     * Side effects: Upserts one assessment row per confirmed requirement.
     */
    public function refreshAssessments(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);
        $userId = $request->user()?->id;
        $confirmedRequirements = $this->requirementLoader->loadApprovedForCase($record->id);
        if ($confirmedRequirements->isNotEmpty()) {
            $usageWarning = $this->aiUsageGuard->assertCanStartAiOperation(
                $record->customer()->firstOrFail(),
                $request->user(),
                AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH,
                $confirmedRequirements->count(),
            );

            if ($usageWarning !== null) {
                session()->flash('warning', $usageWarning);
            }
        }

        $requirementAssessmentService = app(RequirementAssessmentService::class);

        $failedCount = 0;

        foreach ($confirmedRequirements as $requirement) {
            try {
                $requirementAssessmentService->assessRequirement($requirement, $userId, $record->ai_instructions);
            } catch (Throwable) {
                $this->persistFailedRequirementAssessment($requirement, $userId);
                $failedCount++;
            }
        }

        if ($failedCount > 0) {
            return back()->with('warning', 'AI-vurdering feilet for ett eller flere krav.');
        }

        return back()->with('success', 'Krav analysert.');
    }

    /**
     * Purpose: Update the selection state for one persisted evidence row.
     * Inputs: The current request, route-bound saved notice, and route-bound evidence row.
     * Returns: A redirect back to the AI case view after updating the evidence state.
     * Side effects: Updates the evidence selection status and primary marker in the database.
     */
    public function updateEvidenceSelectionStatus(
        Request $request,
        SavedNotice $savedNotice,
        SavedNoticeAiEvidence $evidence,
    ): RedirectResponse {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);

        $ownedEvidence = SavedNoticeAiEvidence::query()
            ->whereKey($evidence->id)
            ->whereHas('requirement', static function ($query) use ($record): void {
                $query->where('saved_notice_id', $record->id);
            })
            ->firstOrFail();

        $validated = $request->validate([
            'selection_status' => ['required', 'string', Rule::in(SavedNoticeAiEvidence::SELECTION_STATUSES)],
        ]);

        DB::transaction(function () use ($ownedEvidence, $validated): void {
            $selectionStatus = $validated['selection_status'];

            $ownedEvidence->forceFill([
                'selection_status' => $selectionStatus,
                'is_primary' => $selectionStatus === SavedNoticeAiEvidence::SELECTION_STATUS_SELECTED,
            ])->save();

            if ($selectionStatus === SavedNoticeAiEvidence::SELECTION_STATUS_SELECTED) {
                $ownedEvidence->requirement
                    ->evidence()
                    ->where('id', '!=', $ownedEvidence->id)
                    ->update(['is_primary' => false]);
            }
        });

        return back();
    }

    /**
     * Purpose: Persist a failed assessment result without overwriting a previously completed one.
     * Inputs: The requirement row and the current user id.
     * Returns: The persisted assessment row.
     * Side effects: Creates or updates a failed assessment row when no completed assessment exists.
     */
    private function persistFailedRequirementAssessment(SavedNoticeAiRequirement $requirement, ?int $userId): SavedNoticeAiRequirementAssessment
    {
        $requirement->loadMissing('assessment');

        if (
            $requirement->assessment !== null
            && $requirement->assessment->assessment_status === SavedNoticeAiRequirementAssessment::ASSESSMENT_STATUS_COMPLETED
        ) {
            return $requirement->assessment;
        }

        return SavedNoticeAiRequirementAssessment::query()->updateOrCreate(
            [
                'saved_notice_ai_requirement_id' => $requirement->id,
            ],
            [
                'assessment_status' => SavedNoticeAiRequirementAssessment::ASSESSMENT_STATUS_FAILED,
                'coverage_status' => null,
                'risk_level' => null,
                'requirement_summary' => null,
                'coverage_rationale' => null,
                'missing_information' => null,
                'recommended_next_step' => null,
                'source_evidence_snapshot' => [],
                'assessed_at' => null,
                'assessed_by_user_id' => $userId,
            ],
        );
    }

    /**
     * Purpose: Resolve the authenticated customer context for the AI workspace.
     * Inputs: Incoming request carrying the current authenticated user.
     * Returns: The current user and customer id.
     * Side effects: Aborts with HTTP 403 if the customer context is unavailable.
     */
    private function frontendContext(Request $request): array
    {
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        abort_unless(
            $user instanceof User
            && $user->canAccessCustomerFrontend()
            && $customerId !== null,
            403,
        );

        return [$user, $customerId];
    }

    private function customerCanUseAiOffer(?User $user): bool
    {
        $customer = $this->customerContext->currentCustomer($user);

        return $customer !== null && app(BillingEntitlementService::class)->canUseAiOffer($customer);
    }

    private function assertAiAccess(SavedNotice $record): void
    {
        abort_unless(
            $record->customer && app(BillingEntitlementService::class)->canUseAiOffer($record->customer),
            403,
            __('procynia.ai.ai_access_unavailable_message'),
        );
    }

    /**
     * Purpose: Build the visible AI analysis case list from canonical saved-notice access.
     * Inputs: The authenticated user and customer id for the current frontend context.
     * Returns: A compact list of saved notices ready for the AI analysis workspace.
     * Side effects: None.
     */
    private function analysisCases(User $user, int $customerId): array
    {
        return $this->savedNoticeAccess->visibleQueryFor($user)
            ->where('customer_id', $customerId)
            ->whereNull('archived_at')
            ->whereIn('bid_status', self::ANALYSIS_ACTIVE_STATUSES)
            ->select([
                'id',
                'customer_id',
                'bid_status',
                'bid_manager_user_id',
                'opportunity_owner_user_id',
                'external_id',
                'reference_number',
                'title',
                'updated_at',
                'archived_at',
            ])
            ->with([
                'bidManager:id,name',
                'opportunityOwner:id,name',
            ])
            ->withCount([
                'infoItems',
                'phaseComments',
                'submissions',
                'businessReviews',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SavedNotice $notice): array => $this->analysisCasePayload($notice))
            ->values()
            ->all();
    }

    /**
     * Purpose: Convert a saved notice into the compact AI analysis row payload.
     * Inputs: A visible saved notice model with the relations needed by the analysis list.
     * Returns: A frontend-ready array for the AI analysis table.
     * Side effects: None.
     */
    private function analysisCasePayload(SavedNotice $notice): array
    {
        return [
            'id' => $notice->id,
            'title' => $notice->title,
            'reference' => $notice->reference_number ?: $notice->external_id,
            'owner_name' => $notice->bidManager?->name ?? $notice->opportunityOwner?->name ?? __('procynia.ai.not_assigned'),
            'stage_label' => $notice->bid_status_label,
            'ai_status' => $this->analysisStatusFor($notice),
            'updated_at' => optional($notice->updated_at)?->toIso8601String(),
            'action_url' => route('app.ai.show', ['savedNotice' => $notice->id]),
        ];
    }

    /**
     * Purpose: Convert AI case documents into a compact frontend payload.
     * Inputs: A visible saved notice with AI document relations loaded.
     * Returns: An ordered array of document rows for the AI case view.
     * Side effects: None.
     */
    private function aiDocumentsPayload(SavedNotice $notice): array
    {
        return $notice->aiDocuments
            ->unique('original_filename')
            ->map(fn (SavedNoticeAiDocument $document): array => $this->aiDocumentPayload($document))
            ->values()
            ->all();
    }

    /**
     * Purpose: Convert supplier-owned answer basis items into a compact frontend payload.
     * Inputs: A visible saved notice or a selected answer basis item collection.
     * Returns: An ordered array of answer basis rows for the AI case view.
     * Side effects: None.
     */
    private function aiAnswerBasisItemsPayload(Collection $answerBasisItems): array
    {
        if (method_exists($answerBasisItems, 'loadMissing')) {
            $answerBasisItems->loadMissing('createdBy');
        }

        return $answerBasisItems
            ->map(fn (SavedNoticeAiAnswerBasisItem $answerBasisItem): array => $this->aiAnswerBasisItemPayload($answerBasisItem))
            ->values()
            ->all();
    }

    /**
     * Purpose: Convert one supplier-owned answer basis item into the canonical frontend payload.
     * Inputs: A visible answer basis item row with its creator relation loaded.
     * Returns: A frontend-ready array for one answer basis row.
     * Side effects: None.
     */
    private function aiAnswerBasisItemPayload(SavedNoticeAiAnswerBasisItem $answerBasisItem): array
    {
        return [
            'id' => $answerBasisItem->id,
            'saved_notice_id' => $answerBasisItem->saved_notice_id,
            'answer_basis_type' => $answerBasisItem->answer_basis_type,
            'answer_basis_type_label' => $answerBasisItem->answer_basis_type_label,
            'title' => $answerBasisItem->title,
            'original_filename' => $answerBasisItem->original_filename,
            'body_text' => $answerBasisItem->body_text,
            'stored_path' => $answerBasisItem->stored_path,
            'mime_type' => $answerBasisItem->mime_type,
            'file_size_bytes' => $answerBasisItem->file_size_bytes,
            'file_size_human' => $this->humanFileSize($answerBasisItem->file_size_bytes),
            'created_by_user_id' => $answerBasisItem->created_by_user_id,
            'created_by' => $answerBasisItem->createdBy?->name,
            'created_at' => optional($answerBasisItem->created_at)?->toIso8601String(),
            'updated_at' => optional($answerBasisItem->updated_at)?->toIso8601String(),
            'delete_url' => route('app.ai.answer-basis.destroy', [
                'savedNotice' => $answerBasisItem->saved_notice_id,
                'answerBasisItem' => $answerBasisItem->id,
            ]),
        ];
    }

    /**
     * Purpose: Convert one AI document into the canonical frontend payload.
     * Inputs: A visible AI document row with its related extraction data loaded.
     * Returns: A frontend-ready array for one AI document row.
     * Side effects: None.
     */
    private function aiDocumentPayload(SavedNoticeAiDocument $document): array
    {
        $storedPath = (string) $document->stored_path;
        $previewMode = $this->documentPreviewService->previewMode($document);

        return [
            'id' => $document->id,
            'original_filename' => $document->original_filename,
            'uploaded_at' => optional($document->created_at)?->toIso8601String(),
            'file_size_bytes' => $document->file_size_bytes,
            'file_size_human' => $this->humanFileSize($document->file_size_bytes),
            'processing_status' => $document->processing_status,
            'processing_status_label' => SavedNoticeAiDocument::PROCESSING_STATUS_LABELS[$document->processing_status]
                ?? $document->processing_status,
            'uploaded_by' => $document->uploadedBy?->name,
            'mime_type' => $document->mime_type,
            'text_extracted_at' => optional($document->text_extracted_at)?->toIso8601String(),
            'queued_at' => optional($document->queued_at)?->toIso8601String(),
            'processing_started_at' => optional($document->processing_started_at)?->toIso8601String(),
            'processing_finished_at' => optional($document->processing_finished_at)?->toIso8601String(),
            'processing_error_type' => $document->processing_error_type,
            'processing_error_message' => $document->processing_error_message,
            'processing_failure_stage' => $document->latestExtractionRun?->failure_stage,
            'processing_failure_type' => $document->latestExtractionRun?->error_type,
            'processing_failure_message' => $document->latestExtractionRun?->error_message,
            'requirement_extraction_progress' => $this->extractionProgressPayload($document),
            'has_extracted_text' => filled($document->extracted_text),
            'chunk_count' => $document->chunks->count(),
            'preview_mode' => $previewMode,
            'preview_url' => $previewMode !== 'unavailable'
                ? route('app.ai.documents.preview', [
                    'savedNotice' => $document->saved_notice_id,
                    'document' => $document->id,
                ])
                : null,
            'download_url' => filled($storedPath) && Storage::disk('local')->exists($storedPath)
                ? route('app.ai.documents.download', [
                    'savedNotice' => $document->saved_notice_id,
                    'document' => $document->id,
                ])
                : null,
            'delete_url' => route('app.ai.documents.destroy', [
                'savedNotice' => $document->saved_notice_id,
                'document' => $document->id,
            ]),
        ];
    }

    /**
     * Purpose: Build a call-count summary for the latest extraction run of one AI document.
     * Inputs: A document model with latestExtractionRun already loaded.
     * Returns: Progress array or null when no run exists.
     * Side effects: One aggregate query per document against requirement_extraction_calls.
     */
    private function extractionProgressPayload(SavedNoticeAiDocument $document): ?array
    {
        $run = $document->latestExtractionRun;

        if ($run === null) {
            return null;
        }

        $counts = DB::table('requirement_extraction_calls')
            ->where('requirement_extraction_run_id', $run->id)
            ->selectRaw(
                'COUNT(*) as total_calls,'.
                ' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed_calls,'.
                ' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as running_calls,'.
                ' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as queued_calls,'.
                ' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed_calls',
                [
                    RequirementExtractionCall::STATUS_COMPLETED,
                    RequirementExtractionCall::STATUS_RUNNING,
                    RequirementExtractionCall::STATUS_QUEUED,
                    RequirementExtractionCall::STATUS_FAILED,
                ]
            )
            ->first();

        return [
            'status' => $run->status,
            'total_calls' => (int) ($counts->total_calls ?? 0),
            'completed_calls' => (int) ($counts->completed_calls ?? 0),
            'running_calls' => (int) ($counts->running_calls ?? 0),
            'queued_calls' => (int) ($counts->queued_calls ?? 0),
            'failed_calls' => (int) ($counts->failed_calls ?? 0),
            'candidate_count' => (int) ($run->candidate_count ?? 0),
            'persisted_requirement_count' => (int) ($run->persisted_requirement_count ?? 0),
        ];
    }

    /**
     * Purpose: Convert one AI document into a detailed preview payload.
     * Inputs: A visible AI document row with its related extraction data loaded.
     * Returns: A frontend-ready array for the preview page.
     * Side effects: None.
     */
    private function aiDocumentPreviewPayload(
        SavedNoticeAiDocument $document,
        ?string $previewMode = null,
        ?string $previewFileUrl = null,
    ): array {
        $storedPath = (string) $document->stored_path;
        $resolvedPreviewMode = $previewMode ?? $this->documentPreviewService->previewMode($document);
        $resolvedPreviewFileUrl = $previewFileUrl ?? $this->documentPreviewService->previewFileUrl($document);

        return [
            'id' => $document->id,
            'original_filename' => $document->original_filename,
            'file_size_bytes' => $document->file_size_bytes,
            'file_size_human' => $this->humanFileSize($document->file_size_bytes),
            'mime_type' => $this->aiDocumentMimeTypeForResponse($document, $storedPath) ?? $document->mime_type,
            'uploaded_at' => optional($document->created_at)?->toIso8601String(),
            'has_extracted_text' => filled($document->extracted_text),
            'extracted_text' => (string) $document->extracted_text,
            'preview_mode' => $resolvedPreviewMode,
            'preview_file_url' => $resolvedPreviewFileUrl,
            'download_url' => filled($storedPath) && Storage::disk('local')->exists($storedPath)
                ? route('app.ai.documents.download', [
                    'savedNotice' => $document->saved_notice_id,
                    'document' => $document->id,
                ])
                : null,
        ];
    }

    /**
     * Purpose: Resolve the canonical MIME type to expose for an AI document file response.
     * Inputs: A saved notice AI document and its stored path.
     * Returns: A MIME type string for the file response, or null when no reliable type is available.
     * Side effects: None.
     */
    private function aiDocumentMimeTypeForResponse(SavedNoticeAiDocument $document, string $storedPath): ?string
    {
        $filename = (string) ($document->original_filename ?: basename($storedPath));
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($extension === 'docx') {
            return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }

        if (is_string($document->mime_type) && $document->mime_type !== '') {
            return $document->mime_type;
        }

        $storageMimeType = Storage::disk('local')->mimeType($storedPath);

        return is_string($storageMimeType) && $storageMimeType !== ''
            ? $storageMimeType
            : null;
    }

    /**
     * Purpose: Convert extracted requirement candidates into a compact frontend payload.
     * Inputs: A visible saved notice with requirement relations loaded.
     * Returns: An ordered array of requirement rows for the AI case view.
     * Side effects: None.
     */
    private function aiRequirementsPayload(Collection $requirements): array
    {
        return $requirements
            ->map(fn (SavedNoticeAiRequirement $requirement): array => $this->aiRequirementPayload($requirement))
            ->values()
            ->all();
    }

    /**
     * Purpose: Convert one persisted requirement row into the frontend payload used by the AI workspace.
     * Inputs: A requirement with canonical relations loaded.
     * Returns: A single frontend-ready requirement array.
     * Side effects: None.
     */
    private function aiRequirementPayload(SavedNoticeAiRequirement $requirement): array
    {
        $selectedAnswerBasisItems = collect($requirement->answerBasisItems->all());

        $viewData = RequirementViewData::fromRequirement($requirement, [
            'review_status_update_url' => route('app.ai.requirements.review-status.update', [
                'savedNotice' => $requirement->saved_notice_id,
                'requirement' => $requirement->id,
            ]),
            'edit_url' => route('app.ai.requirements.update', [
                'savedNotice' => $requirement->saved_notice_id,
                'requirement' => $requirement->id,
            ]),
            'work_update_url' => route('app.ai.requirements.work.update', [
                'savedNotice' => $requirement->saved_notice_id,
                'requirement' => $requirement->id,
            ]),
            'assigned_user_update_url' => route('app.ai.requirements.assigned-user.update', [
                'savedNotice' => $requirement->saved_notice_id,
                'requirement' => $requirement->id,
            ]),
        ]);

        return array_merge(
            $viewData->toArray(),
            [
                'source_document_preview_url' => $this->requirementSourceDocumentPreviewUrl($requirement),
                'answer_draft' => $this->aiRequirementAnswerDraftPayload($requirement),
                'answer_basis_item_ids' => $selectedAnswerBasisItems
                    ->pluck('id')
                    ->map(static fn (mixed $value): int => (int) $value)
                    ->values()
                    ->all(),
                'answer_basis_selection_sync_url' => route('app.ai.requirements.answer-basis.sync', [
                    'savedNotice' => $requirement->saved_notice_id,
                    'requirement' => $requirement->id,
                ]),
                'answer_draft_generate_url' => route('app.ai.requirements.answer-draft.generate', [
                    'savedNotice' => $requirement->saved_notice_id,
                    'requirement' => $requirement->id,
                ]),
                'answer_draft_save_url' => route('app.ai.requirements.answer-draft.update', [
                    'savedNotice' => $requirement->saved_notice_id,
                    'requirement' => $requirement->id,
                ]),
                'assessment' => $this->aiRequirementAssessmentPayload($requirement->assessment),
                'evidence' => $this->aiRequirementEvidencePayload($requirement),
                'knowledge_sources_sent_to_ai' => $this->aiRequirementKnowledgeSourcesPayload($requirement),
            ],
        );
    }

    /**
     * Purpose: Resolve the preview URL for the exact source document behind one requirement.
     * Inputs: A requirement row with its document relation loaded.
     * Returns: A preview route URL when the concrete source document can be previewed, or null.
     * Side effects: None.
     */
    private function requirementSourceDocumentPreviewUrl(SavedNoticeAiRequirement $requirement): ?string
    {
        $requirement->loadMissing('document');
        $document = $requirement->document;

        if ($document === null || $this->documentPreviewService->previewMode($document) === 'unavailable') {
            return null;
        }

        return route('app.ai.documents.preview', [
            'savedNotice' => $document->saved_notice_id,
            'document' => $document->id,
        ]);
    }

    /**
     * Purpose: Convert a persisted AI assessment into a compact frontend payload.
     * Inputs: A requirement assessment row or null when no assessment exists yet.
     * Returns: A frontend-ready assessment array or null.
     * Side effects: None.
     */
    private function aiRequirementAssessmentPayload(?SavedNoticeAiRequirementAssessment $assessment): ?array
    {
        if ($assessment === null) {
            return null;
        }

        $assessmentStatus = $assessment->assessment_status;
        $coverageStatus = $assessment->coverage_status;
        $riskLevel = $assessment->risk_level;

        return [
            'id' => $assessment->id,
            'assessment_status' => $assessmentStatus,
            'assessment_status_label' => SavedNoticeAiRequirementAssessment::ASSESSMENT_STATUS_LABELS[$assessmentStatus]
                ?? $assessmentStatus,
            'coverage_status' => $coverageStatus,
            'coverage_status_label' => filled($coverageStatus)
                ? (SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_LABELS[$coverageStatus] ?? $coverageStatus)
                : null,
            'risk_level' => $riskLevel,
            'risk_level_label' => filled($riskLevel)
                ? (SavedNoticeAiRequirementAssessment::RISK_LEVEL_LABELS[$riskLevel] ?? $riskLevel)
                : null,
            'requirement_summary' => $assessment->requirement_summary,
            'coverage_rationale' => $assessment->coverage_rationale,
            'missing_information' => $assessment->missing_information,
            'recommended_next_step' => $assessment->recommended_next_step,
            'assessed_at' => optional($assessment->assessed_at)?->toIso8601String(),
        ];
    }

    /**
     * Purpose: Convert one persisted answer draft into a compact frontend payload.
     * Inputs: A requirement row with optional answer draft fields.
     * Returns: A frontend-ready answer draft array.
     * Side effects: None.
     */
    private function aiRequirementAnswerDraftPayload(
        SavedNoticeAiRequirement $requirement,
        ?string $judgeStatus = null,
        ?array $coverageSummary = null,
    ): array {
        $hasAnswerDraftText = filled(trim((string) ($requirement->answer_draft_text ?? '')));
        $retrievalSources = is_array($requirement->answer_draft_retrieval_sources ?? null)
            ? $requirement->answer_draft_retrieval_sources
            : [];

        $generationState = match (true) {
            ! $hasAnswerDraftText => null,
            $judgeStatus === 'partial' => 'partial',
            default => 'generated',
        };

        return [
            'text' => (string) ($requirement->answer_draft_text ?? ''),
            'generated_at' => optional($requirement->answer_draft_generated_at)?->toIso8601String(),
            'generation_state' => $generationState,
            'missing_knowledge' => $judgeStatus === 'partial' ? $coverageSummary : null,
            'retrieval_sources' => $retrievalSources,
        ];
    }

    /**
     * Purpose: Convert one persisted requirement row into a compact answer draft API payload.
     * Inputs: A saved notice AI requirement row.
     * Returns: A JSON response payload for the answer draft endpoints.
     * Side effects: None.
     */
    private function aiRequirementAnswerDraftResponsePayload(
        SavedNoticeAiRequirement $requirement,
        ?string $judgeStatus = null,
        ?array $coverageSummary = null,
    ): array {
        return [
            'requirement_id' => $requirement->id,
            'answer_draft' => $this->aiRequirementAnswerDraftPayload($requirement, $judgeStatus, $coverageSummary),
        ];
    }

    /**
     * Purpose: Convert the persisted evidence rows for one requirement into a compact frontend payload.
     * Inputs: A requirement with its evidence relations loaded.
     * Returns: An ordered array of evidence rows ready for rendering.
     * Side effects: None.
     */
    private function aiRequirementEvidencePayload(SavedNoticeAiRequirement $requirement): array
    {
        return $requirement->evidence
            ->map(function (SavedNoticeAiEvidence $evidence): array {
                $knowledgeItem = $evidence->knowledgeItem;
                $knowledgeChunk = $evidence->knowledgeItemChunk;
                $selectionStatus = $evidence->selection_status;
                $matchType = $evidence->match_type;
                $knowledgeDocumentType = $knowledgeItem?->document_type ?? KnowledgeItem::DOCUMENT_TYPE_OTHER;

                return [
                    'id' => $evidence->id,
                    'selection_status' => $selectionStatus,
                    'selection_status_label' => SavedNoticeAiEvidence::SELECTION_STATUS_LABELS[$selectionStatus]
                        ?? $selectionStatus,
                    'match_type' => $matchType,
                    'match_type_label' => SavedNoticeAiEvidence::MATCH_TYPE_LABELS[$matchType]
                        ?? $matchType,
                    'match_score' => $evidence->match_score,
                    'match_rank' => $evidence->match_rank,
                    'is_primary' => $evidence->is_primary,
                    'selection_status_update_url' => route('app.ai.evidence.selection-status.update', [
                        'savedNotice' => $evidence->requirement->saved_notice_id,
                        'evidence' => $evidence->id,
                    ]),
                    'knowledge_item' => [
                        'id' => $knowledgeItem?->id,
                        'original_filename' => $knowledgeItem?->original_filename,
                        'document_type' => $knowledgeDocumentType,
                        'document_type_label' => filled($knowledgeDocumentType)
                            ? (KnowledgeItem::DOCUMENT_TYPE_LABELS[$knowledgeDocumentType] ?? $knowledgeDocumentType)
                            : null,
                    ],
                    'knowledge_chunk' => [
                        'id' => $knowledgeChunk?->id,
                        'chunk_index' => $knowledgeChunk?->chunk_index,
                        'content' => $knowledgeChunk?->content,
                        'start_offset' => $knowledgeChunk?->start_offset,
                        'end_offset' => $knowledgeChunk?->end_offset,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Purpose: Build the Kunnskapsbase source list sent to AI as grounding for one requirement.
     * Inputs: A requirement with evidence, knowledgeItem, knowledgeItemChunk, and knowledgeItemVersion loaded.
     * Returns: An ordered array of source entries; empty array when no evidence exists.
     * Side effects: None.
     */
    private function aiRequirementKnowledgeSourcesPayload(SavedNoticeAiRequirement $requirement): array
    {
        return $requirement->evidence
            ->map(function (SavedNoticeAiEvidence $evidence): array {
                $knowledgeItem = $evidence->knowledgeItem;
                $knowledgeChunk = $evidence->knowledgeItemChunk;
                $version = $evidence->knowledgeItemVersion;
                $documentType = $knowledgeItem?->document_type ?? KnowledgeItem::DOCUMENT_TYPE_OTHER;

                return [
                    'evidence_id' => $evidence->id,
                    'knowledge_item_id' => $knowledgeItem?->id,
                    'knowledge_item_show_url' => $knowledgeItem !== null
                        ? route('app.ai.knowledge-base.show', ['knowledgeItem' => $knowledgeItem->id])
                        : null,
                    'knowledge_item_version_id' => $evidence->knowledge_item_version_id,
                    'knowledge_item_version_no' => $version?->version_no,
                    'original_filename' => $knowledgeItem?->original_filename,
                    'document_type' => $documentType,
                    'document_type_label' => filled($documentType)
                        ? (KnowledgeItem::DOCUMENT_TYPE_LABELS[$documentType] ?? $documentType)
                        : null,
                    'chunk_id' => $knowledgeChunk?->id,
                    'chunk_index' => $knowledgeChunk?->chunk_index,
                    'chunk_type' => $knowledgeChunk?->chunk_type,
                    'section_title' => $knowledgeChunk?->section_title,
                    'heading_path' => $knowledgeChunk?->heading_path ?? $knowledgeChunk?->section_path,
                    'match_score' => $evidence->match_score,
                    'match_rank' => $evidence->match_rank,
                    'match_type' => $evidence->match_type,
                    'is_primary' => $evidence->is_primary,
                    'selection_status' => $evidence->selection_status,
                    'version_approval_status' => $version?->approval_status,
                    'version_is_current_now' => $version !== null && (bool) $version->is_current,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Purpose: Resolve the customer knowledge chunks that may ground AI requirement matching.
     * Inputs: The current customer id for the visible AI case.
     * Returns: A compact collection of active knowledge chunks limited to a safe V1 cap.
     * Side effects: None.
     *
     * Guards use knowledge_item_versions (not knowledge_items mirrors) as the authoritative source
     * for storage_path and extraction_status. The join is through the chunk's own version pointer
     * so only chunks belonging to the current version are returned. Chunks with a null version
     * pointer or a pointer to a non-current version are excluded.
     */
    protected function knowledgeChunksForMatching(int $customerId, ?array $requirementEmbedding = null): Collection
    {
        $query = KnowledgeItemChunk::query()
            ->join('knowledge_items', 'knowledge_items.id', '=', 'knowledge_item_chunks.knowledge_item_id')
            ->join('knowledge_item_versions', function (JoinClause $join): void {
                $join->on('knowledge_item_versions.id', '=', 'knowledge_item_chunks.knowledge_item_version_id')
                    ->on('knowledge_item_versions.knowledge_item_id', '=', 'knowledge_items.id')
                    ->where('knowledge_item_versions.is_current', true);
            })
            ->where('knowledge_items.customer_id', $customerId)
            ->where('knowledge_items.ownership_type', KnowledgeItem::OWNERSHIP_TYPE_COMPANY)
            ->where('knowledge_items.document_status', KnowledgeItem::DOCUMENT_STATUS_ACTIVE)
            ->whereNotNull('knowledge_item_versions.storage_path')
            ->where('knowledge_item_versions.extraction_status', KnowledgeItem::EXTRACTION_STATUS_COMPLETED)
            ->select([
                'knowledge_item_chunks.*',
                'knowledge_items.original_filename as knowledge_item_title',
                'knowledge_items.document_type as document_type',
                'knowledge_items.summary as knowledge_item_summary',
                'knowledge_items.updated_at as knowledge_item_updated_at',
            ]);

        if (
            is_array($requirementEmbedding)
            && $requirementEmbedding !== []
            && count($requirementEmbedding) === 1536
            && Schema::hasColumn('knowledge_item_chunks', 'embedding_vector_pgvector')
        ) {
            $vectorLiteral = PgVector::literal($requirementEmbedding);

            $query->selectRaw(
                'CASE WHEN knowledge_item_chunks.embedding_vector_pgvector IS NULL THEN NULL ELSE 1 - (knowledge_item_chunks.embedding_vector_pgvector <=> ?::vector) END as embedding_similarity',
                [$vectorLiteral],
            );
            $query->orderByRaw('CASE WHEN knowledge_item_chunks.embedding_vector_pgvector IS NULL THEN 1 ELSE 0 END');
            $query->orderByRaw('knowledge_item_chunks.embedding_vector_pgvector <=> ?::vector', [$vectorLiteral]);
            $query->orderByDesc('knowledge_items.updated_at');
            $query->orderByDesc('knowledge_items.id');
            $query->orderBy('knowledge_item_chunks.chunk_index');
            $query->orderBy('knowledge_item_chunks.id');
        } else {
            $query->orderByDesc('knowledge_items.updated_at')
                ->orderByDesc('knowledge_items.id')
                ->orderBy('knowledge_item_chunks.chunk_index')
                ->orderBy('knowledge_item_chunks.id');
        }

        $knowledgeChunks = $query
            ->limit(1000)
            ->get()
            ->map(fn (KnowledgeItemChunk $chunk): array => [
                'id' => (int) $chunk->id,
                'chunk_id' => (int) $chunk->id,
                'knowledge_item_id' => (int) $chunk->knowledge_item_id,
                'knowledge_item_title' => (string) $chunk->getAttribute('knowledge_item_title'),
                'document_type' => (string) $chunk->getAttribute('document_type'),
                'knowledge_item_summary' => (string) $chunk->getAttribute('knowledge_item_summary'),
                'chunk_index' => (int) $chunk->chunk_index,
                'chunk_type' => (string) ($chunk->chunk_type ?? 'semantic'),
                'content' => (string) $chunk->content,
                'title' => (string) ($chunk->title ?? ''),
                'heading_path' => (string) ($chunk->heading_path ?? $chunk->section_path ?? $chunk->title ?? ''),
                'summary_for_retrieval' => (string) ($chunk->summary_for_retrieval ?? ''),
                'table_text' => (string) ($chunk->table_text ?? ''),
                'table_html' => (string) ($chunk->table_html ?? ''),
                'table_json' => is_array($chunk->table_json) ? $chunk->table_json : null,
                'image_path' => (string) ($chunk->image_path ?? ''),
                'image_disk' => (string) ($chunk->image_disk ?? ''),
                'image_mime_type' => (string) ($chunk->image_mime_type ?? ''),
                'image_original_filename' => (string) ($chunk->image_original_filename ?? ''),
                'image_width' => is_numeric($chunk->image_width ?? null) ? (int) $chunk->image_width : null,
                'image_height' => is_numeric($chunk->image_height ?? null) ? (int) $chunk->image_height : null,
                'image_hash' => (string) ($chunk->image_hash ?? ''),
                'image_metadata' => is_array($chunk->image_metadata) ? $chunk->image_metadata : null,
                'image_alt_text' => (string) ($chunk->image_alt_text ?? ''),
                'image_caption' => (string) ($chunk->image_caption ?? ''),
                'ocr_text' => (string) ($chunk->ocr_text ?? ''),
                'image_description' => (string) ($chunk->image_description ?? ''),
                'topic' => (string) ($chunk->topic ?? ''),
                'sub_topic' => (string) ($chunk->sub_topic ?? ''),
                'keywords' => $this->knowledgeChunkCoverageService->normalizeKeywords($chunk->keywords) ?? [],
                'section_title' => (string) ($chunk->section_title ?? ''),
                'section_path' => (string) ($chunk->section_path ?? ''),
                'embedding_vector' => is_array($chunk->embedding_vector) ? $chunk->embedding_vector : null,
                'embedding_vector_pgvector' => is_array($chunk->embedding_vector_pgvector) ? $chunk->embedding_vector_pgvector : null,
                'embedding_similarity' => is_numeric($chunk->getAttribute('embedding_similarity'))
                    ? (float) $chunk->getAttribute('embedding_similarity')
                    : null,
                'embedding_model' => (string) ($chunk->embedding_model ?? ''),
                'embedding_generated_at' => optional($chunk->embedding_generated_at)?->toIso8601String(),
                'embedding_error' => $chunk->embedding_error,
                'knowledge_item_updated_at' => (string) $chunk->getAttribute('knowledge_item_updated_at'),
                'knowledge_item_version_id' => is_numeric($chunk->knowledge_item_version_id)
                    ? (int) $chunk->knowledge_item_version_id
                    : null,
            ])
            ->values();

        return $knowledgeChunks;
    }

    /**
     * Purpose: Retrieve the top knowledge chunks for one requirement using the canonical customer knowledge index.
     * Inputs: The visible AI case and the requirement being drafted.
     * Returns: A small ranked collection of retrieval source rows ready for answer drafting.
     * Side effects: May generate a temporary requirement embedding and logs failures through the existing embedding flow.
     */
    private function retrievedKnowledgeChunksForRequirement(
        Request $request,
        SavedNotice $record,
        SavedNoticeAiRequirement $requirement,
    ): Collection {
        $requirementText = trim((string) $requirement->requirement_text);

        if ($requirementText === '') {
            return collect();
        }

        $metadataMap = $this->knowledgeMetadataMapService->buildForCustomer((int) $record->customer_id);
        $retrievalPlan = $this->metadataRetrievalPlanService->buildPlan($requirementText, $metadataMap);
        $validatedPlan = $this->metadataRetrievalPlanValidator->validate($retrievalPlan, $metadataMap);
        $selectedMetadata = data_get($validatedPlan, 'selected_metadata', []);
        $candidateChunks = collect();
        $usedMetadataCandidates = false;
        $usedBasePoolFallback = false;
        $metadataCandidateCount = 0;
        $baseCandidateCount = 0;

        if (is_array($selectedMetadata) && $selectedMetadata !== []) {
            Log::info('[PROCYNIA][METADATA_RETRIEVAL] Validated retrieval plan selected metadata; attempting metadata candidate retrieval.', [
                'saved_notice_id' => $record->id,
                'requirement_id' => $requirement->id,
                'customer_id' => $record->customer_id,
                'selected_field_count' => count($selectedMetadata),
                'selected_value_count' => $this->selectedMetadataValueCount($selectedMetadata),
            ]);

            $metadataCandidateChunks = $this->metadataCandidateRetrievalService->retrieveForCustomer((int) $record->customer_id, $validatedPlan);
            $metadataCandidateCount = $metadataCandidateChunks->count();

            if ($metadataCandidateChunks->isNotEmpty()) {
                $candidateChunks = $metadataCandidateChunks;
                $usedMetadataCandidates = true;
                Log::info('[PROCYNIA][METADATA_RETRIEVAL] Metadata candidate retrieval returned rows; using metadata candidates.', [
                    'saved_notice_id' => $record->id,
                    'requirement_id' => $requirement->id,
                    'customer_id' => $record->customer_id,
                    'metadata_candidate_count' => $metadataCandidateCount,
                ]);
            } else {
                Log::info('[PROCYNIA][METADATA_RETRIEVAL] Metadata candidate retrieval returned no rows; falling back to the base retrieval pool.', [
                    'saved_notice_id' => $record->id,
                    'requirement_id' => $requirement->id,
                    'customer_id' => $record->customer_id,
                    'selected_field_count' => count($selectedMetadata),
                    'selected_value_count' => $this->selectedMetadataValueCount($selectedMetadata),
                ]);
            }

            Log::info('[PROCYNIA][REAL_RETRIEVAL_PATH] retrievedKnowledgeChunksForRequirement stage metadata_candidates.', array_merge([
                'route_name' => request()->route()?->getName(),
                'request_url' => request()->fullUrl(),
                'controller_method' => __METHOD__,
                'saved_notice_id' => $record->id,
                'requirement_id' => $requirement->id,
                'customer_id' => $record->customer_id,
                'stage' => 'metadata_candidates',
                'selected_field_count' => count($selectedMetadata),
                'selected_value_count' => $this->selectedMetadataValueCount($selectedMetadata),
                'candidate_count' => $metadataCandidateChunks->count(),
                'knowledge_item_ids' => $metadataCandidateChunks
                    ->pluck('knowledge_item_id')
                    ->filter(static fn (mixed $value): bool => is_int($value) || ctype_digit((string) $value))
                    ->map(static fn (mixed $value): int => (int) $value)
                    ->unique()
                    ->values()
                    ->all(),
            ], $this->realRetrievalPathDebugSummary($metadataCandidateChunks)));

        } else {
            Log::info('[PROCYNIA][METADATA_RETRIEVAL] Validated retrieval plan contained no selected metadata; using the base retrieval pool.', [
                'saved_notice_id' => $record->id,
                'requirement_id' => $requirement->id,
                'customer_id' => $record->customer_id,
            ]);
        }

        if (! $usedMetadataCandidates) {
            $usedBasePoolFallback = true;
            $baseCandidateChunks = $this->knowledgeChunksForMatching((int) $record->customer_id);
            $baseCandidateCount = $baseCandidateChunks->count();

            if ($baseCandidateChunks->isEmpty()) {
                Log::info('[PROCYNIA][METADATA_RETRIEVAL] Base retrieval pool was empty after metadata retrieval attempt; returning no retrieval candidates.', [
                    'saved_notice_id' => $record->id,
                    'requirement_id' => $requirement->id,
                    'customer_id' => $record->customer_id,
                    'metadata_plan_attempted' => is_array($selectedMetadata) && $selectedMetadata !== [],
                    'metadata_candidate_count' => $metadataCandidateCount,
                ]);

                return collect();
            }

            $candidateChunks = $baseCandidateChunks;
            Log::info('[PROCYNIA][METADATA_RETRIEVAL] Base retrieval pool selected as fallback candidate set.', [
                'saved_notice_id' => $record->id,
                'requirement_id' => $requirement->id,
                'customer_id' => $record->customer_id,
                'base_candidate_count' => $baseCandidateCount,
                'metadata_candidate_count' => $metadataCandidateCount,
            ]);

            Log::info('[PROCYNIA][REAL_RETRIEVAL_PATH] retrievedKnowledgeChunksForRequirement stage base_pool.', array_merge([
                'route_name' => request()->route()?->getName(),
                'request_url' => request()->fullUrl(),
                'controller_method' => __METHOD__,
                'saved_notice_id' => $record->id,
                'requirement_id' => $requirement->id,
                'customer_id' => $record->customer_id,
                'stage' => 'base_pool',
                'base_candidate_count' => $baseCandidateCount,
                'metadata_candidate_count' => $metadataCandidateCount,
                'knowledge_item_ids' => $baseCandidateChunks
                    ->pluck('knowledge_item_id')
                    ->filter(static fn (mixed $value): bool => is_int($value) || ctype_digit((string) $value))
                    ->map(static fn (mixed $value): int => (int) $value)
                    ->unique()
                    ->values()
                    ->all(),
            ], $this->realRetrievalPathDebugSummary($baseCandidateChunks)));

        }

        $requirementEmbedding = $this->requirementEmbeddingFor($requirement);
        $rankedMatches = $this->requirementKnowledgeMatcher->match($requirementText, $candidateChunks, $requirementEmbedding);
        $candidateChunksById = $candidateChunks->keyBy(static fn (array $candidate): int => (int) data_get($candidate, 'chunk_id', 0));

        Log::info('[PROCYNIA][METADATA_RETRIEVAL] Requirement candidate ranking completed.', [
            'saved_notice_id' => $record->id,
            'requirement_id' => $requirement->id,
            'metadata_plan_attempted' => is_array($selectedMetadata) && $selectedMetadata !== [],
            'base_candidate_count' => $baseCandidateCount,
            'metadata_candidate_count' => $metadataCandidateCount,
            'candidate_count' => $candidateChunks->count(),
            'used_metadata_candidates' => $usedMetadataCandidates,
            'used_base_pool_fallback' => $usedBasePoolFallback,
            'selected_field_count' => is_array($selectedMetadata) ? count($selectedMetadata) : 0,
            'selected_value_count' => is_array($selectedMetadata) ? $this->selectedMetadataValueCount($selectedMetadata) : 0,
            'ranked_chunk_ids' => $rankedMatches->take(10)->pluck('chunk_id')->all(),
        ]);

        $retrievedKnowledgeChunks = $rankedMatches
            ->map(function (array $match) use ($candidateChunksById): array {
                $chunkId = (int) data_get($match, 'chunk_id', 0);
                $candidate = $candidateChunksById->get($chunkId, []);
                $content = trim((string) data_get($candidate, 'content', data_get($match, 'chunk_content', '')));
                $chunkTitle = trim((string) data_get($candidate, 'title', ''));
                $chunkHeadingPath = trim((string) data_get($candidate, 'heading_path', ''));
                $documentTitle = trim((string) data_get($candidate, 'knowledge_item_title', data_get($match, 'knowledge_item_title', '')));
                $chunkIndex = (int) data_get($match, 'chunk_index', 0);

                return [
                    'id' => $chunkId,
                    'score' => (float) data_get($match, 'final_score', data_get($match, 'score', 0)),
                    'base_score' => (float) data_get($match, 'base_score', data_get($match, 'score', 0)),
                    'embedding_similarity' => data_get($match, 'embedding_similarity'),
                    'knowledge_item_id' => (int) data_get($match, 'knowledge_item_id', 0),
                    'document_title' => $documentTitle !== '' ? $documentTitle : null,
                    'knowledge_item_title' => $documentTitle !== '' ? $documentTitle : null,
                    'document_type' => (string) data_get($candidate, 'document_type', ''),
                    'knowledge_item_summary' => (string) data_get($candidate, 'knowledge_item_summary', ''),
                    'chunk_id' => $chunkId,
                    'chunk_index' => $chunkIndex,
                    'chunk_type' => (string) data_get($candidate, 'chunk_type', 'semantic'),
                    'heading_path' => $chunkHeadingPath !== ''
                        ? $chunkHeadingPath
                        : ($chunkTitle !== '' ? $chunkTitle : sprintf('Chunk %d', $chunkIndex + 1)),
                    'summary_for_retrieval' => (string) data_get($candidate, 'summary_for_retrieval', ''),
                    'table_text' => (string) data_get($candidate, 'table_text', ''),
                    'table_html' => (string) data_get($candidate, 'table_html', ''),
                    'table_json' => data_get($candidate, 'table_json'),
                    'image_path' => (string) data_get($candidate, 'image_path', ''),
                    'image_disk' => (string) data_get($candidate, 'image_disk', ''),
                    'image_mime_type' => (string) data_get($candidate, 'image_mime_type', ''),
                    'image_original_filename' => (string) data_get($candidate, 'image_original_filename', ''),
                    'image_width' => is_numeric(data_get($candidate, 'image_width')) ? (int) data_get($candidate, 'image_width') : null,
                    'image_height' => is_numeric(data_get($candidate, 'image_height')) ? (int) data_get($candidate, 'image_height') : null,
                    'image_hash' => (string) data_get($candidate, 'image_hash', ''),
                    'image_metadata' => is_array(data_get($candidate, 'image_metadata')) ? data_get($candidate, 'image_metadata') : null,
                    'image_alt_text' => (string) data_get($candidate, 'image_alt_text', ''),
                    'image_caption' => (string) data_get($candidate, 'image_caption', ''),
                    'ocr_text' => (string) data_get($candidate, 'ocr_text', ''),
                    'image_description' => (string) data_get($candidate, 'image_description', ''),
                    'topic' => (string) data_get($candidate, 'topic', ''),
                    'sub_topic' => (string) data_get($candidate, 'sub_topic', ''),
                    'keywords' => $this->knowledgeChunkCoverageService->normalizeKeywords(data_get($candidate, 'keywords')) ?? [],
                    'section_title' => (string) data_get($candidate, 'section_title', ''),
                    'section_path' => (string) data_get($candidate, 'section_path', ''),
                    'content' => $content,
                    'content_preview' => Str::limit(Str::squish($content), 1200, '...'),
                    'knowledge_item_version_id' => is_numeric(data_get($match, 'knowledge_item_version_id'))
                        ? (int) data_get($match, 'knowledge_item_version_id')
                        : null,
                    'image_url' => (string) data_get($candidate, 'chunk_type', 'semantic') === 'image'
                        && (int) data_get($candidate, 'knowledge_item_id', 0) > 0
                        && (int) data_get($candidate, 'chunk_id', 0) > 0
                        ? route('app.ai.knowledge-base.chunks.image', [
                            'knowledgeItem' => (int) data_get($candidate, 'knowledge_item_id', 0),
                            'chunk' => (int) data_get($candidate, 'chunk_id', 0),
                        ])
                        : null,
                ];
            })
            ->values();

        $rankedTableMatches = $retrievedKnowledgeChunks
            ->filter(static fn (array $match): bool => (string) data_get($match, 'chunk_type', '') === 'table')
            ->values();

        Log::info('[PROCYNIA][REAL_RETRIEVAL_PATH] retrievedKnowledgeChunksForRequirement stage ranked_matches.', array_merge([
            'route_name' => request()->route()?->getName(),
            'request_url' => request()->fullUrl(),
            'controller_method' => __METHOD__,
            'saved_notice_id' => $record->id,
            'requirement_id' => $requirement->id,
            'customer_id' => $record->customer_id,
            'stage' => 'ranked_matches',
            'candidate_source' => $usedMetadataCandidates ? 'metadata' : ($usedBasePoolFallback ? 'base' : 'unknown'),
            'candidate_count' => $candidateChunks->count(),
            'ranked_count' => $retrievedKnowledgeChunks->count(),
            'table_ranked_count' => $rankedTableMatches->count(),
            'table_ranked_ids' => $rankedTableMatches
                ->pluck('chunk_id')
                ->map(static fn (mixed $value): int => (int) $value)
                ->values()
                ->all(),
        ], $this->realRetrievalPathDebugSummary($retrievedKnowledgeChunks)));

        return $retrievedKnowledgeChunks;
    }

    /**
     * Purpose: Count how many metadata values the validated retrieval plan selected.
     * Inputs: The validated metadata selection.
     * Returns: The total number of selected metadata values.
     * Side effects: None.
     */
    private function selectedMetadataValueCount(array $selectedMetadata): int
    {
        $count = 0;

        foreach ($selectedMetadata as $values) {
            if (! is_array($values)) {
                continue;
            }

            $count += count(array_filter($values, static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
        }

        return $count;
    }

    /**
     * Purpose: Summarize how strongly a retrieval result grounds a generated answer.
     * Inputs: The retrieval source rows returned for one requirement.
     * Returns: A compact traffic-light grounding summary.
     * Side effects: None.
     */
    private function calculateKnowledgeGroundingLevel(Collection $retrievedKnowledgeChunks, ?string $requirementText = null): array
    {
        return $this->knowledgeChunkCoverageService->evaluateKnowledgeGrounding($retrievedKnowledgeChunks, $requirementText);
    }

    /**
     * Purpose: Determine whether a generated answer draft must be blocked because the grounding is too weak.
     * Inputs: A normalized knowledge grounding payload.
     * Returns: True when the draft should not be generated.
     * Side effects: None.
     */
    private function shouldBlockAnswerDraftGeneration(array $knowledgeGrounding): bool
    {
        return data_get($knowledgeGrounding, 'level') === 'red';
    }

    /**
     * Purpose: Build the read-only message shown when knowledge is too weak to draft an answer safely.
     * Inputs: The requirement that needs a knowledge document.
     * Returns: A deterministic internal instruction payload.
     * Side effects: None.
     */
    private function missingKnowledgeInstructionPayload(SavedNoticeAiRequirement $requirement): array
    {
        return [
            'message' => 'Procynia har ikke laget et svar fordi kunnskapsgrunnlaget er for svakt.',
            ...$this->requirementKnowledgeDocumentRecommendationService->recommendForRequirement($requirement),
        ];
    }

    /**
     * Purpose: Build the coverage summary included in partial answer draft responses.
     * Inputs: The grounding judge result and retrieved chunks for source enrichment.
     * Returns: A structured coverage payload matching the missing_knowledge shape.
     * Side effects: None.
     */
    private function partialCoverageSummaryPayload(array $groundingJudge, Collection $retrievedKnowledgeChunks): array
    {
        $directlySupportedPoints = $this->normalizeGroundingPointList(
            data_get($groundingJudge, 'directly_supported_points', data_get($groundingJudge, 'supported_points', [])),
        );
        $directlySupportedPoints = $this->enrichGroundingPointsWithSources($directlySupportedPoints, $retrievedKnowledgeChunks);

        return [
            'missing_knowledge_summary' => $this->normalizeOptionalString(data_get($groundingJudge, 'missing_knowledge_summary')),
            'directly_supported_points' => $directlySupportedPoints,
            'related_but_insufficient_points' => $this->normalizeStringList(data_get($groundingJudge, 'related_but_insufficient_points', [])),
            'unsupported_points' => $this->normalizeStringList(data_get($groundingJudge, 'unsupported_points', [])),
            'judge_status' => 'partial',
            'can_generate_answer' => true,
            'supported_points' => $this->normalizeGroundingPointRequirementPoints($directlySupportedPoints),
        ];
    }

    /**
     * Purpose: Build the read-only message shown when the grounding judge blocks answer generation.
     * Inputs: The requirement and the grounding judge result.
     * Returns: A deterministic internal instruction payload.
     * Side effects: None.
     */
    private function judgeBlockedMissingKnowledgePayload(
        SavedNoticeAiRequirement $requirement,
        array $groundingJudge,
        Collection $retrievedKnowledgeChunks,
    ): array {
        $recommendation = $this->recommendationForGroundingJudge($requirement, $groundingJudge);
        $directlySupportedPoints = $this->normalizeGroundingPointList(
            data_get($groundingJudge, 'directly_supported_points', data_get($groundingJudge, 'supported_points', [])),
        );
        $directlySupportedPoints = $this->enrichGroundingPointsWithSources($directlySupportedPoints, $retrievedKnowledgeChunks);
        $relatedButInsufficientPoints = $this->normalizeStringList(data_get($groundingJudge, 'related_but_insufficient_points', []));

        return array_merge([
            'message' => 'Procynia har ikke laget et svar fordi kunnskapsgrunnlaget ikke dokumenterer kravet godt nok. Opprett eller last opp relevant kunnskapsdokumentasjon, og prøv deretter å lage svaret på nytt.',
            'missing_knowledge_summary' => $this->normalizeOptionalString(data_get($groundingJudge, 'missing_knowledge_summary'))
                ?? 'Kunnskapsgrunnlaget dokumenterer ikke kravet sikkert nok til å generere et svar.',
            'directly_supported_points' => $directlySupportedPoints,
            'related_but_insufficient_points' => $relatedButInsufficientPoints,
            'unsupported_points' => $this->normalizeStringList(data_get($groundingJudge, 'unsupported_points', [])),
            'reasoning_summary' => $this->normalizeOptionalString(data_get($groundingJudge, 'reasoning_summary')),
            'judge_status' => $this->normalizeOptionalString(data_get($groundingJudge, 'status')),
            'can_generate_answer' => (bool) data_get($groundingJudge, 'can_generate_answer', false),
            'supported_points' => $this->normalizeGroundingPointRequirementPoints($directlySupportedPoints),
        ], $recommendation);
    }

    /**
     * Purpose: Build a compact debug snapshot for the real retrieval path.
     * Inputs: A collection of retrieval rows.
     * Returns: A count-by-type summary and compact table chunk snapshots.
     * Side effects: None.
     */
    private function realRetrievalPathDebugSummary(Collection $chunks): array
    {
        $countByChunkType = $chunks
            ->countBy(static fn (array $chunk): string => (string) data_get($chunk, 'chunk_type', 'semantic'))
            ->all();

        return [
            'total_chunk_count' => $chunks->count(),
            'count_by_chunk_type' => $countByChunkType,
            'total_table_chunk_count' => (int) data_get($countByChunkType, 'table', 0),
            'knowledge_item_ids' => $chunks
                ->pluck('knowledge_item_id')
                ->filter(static fn (mixed $value): bool => is_int($value) || ctype_digit((string) $value))
                ->map(static fn (mixed $value): int => (int) $value)
                ->unique()
                ->values()
                ->all(),
            'table_chunks' => $chunks
                ->filter(static fn (array $chunk): bool => (string) data_get($chunk, 'chunk_type', 'semantic') === 'table')
                ->map(static function (array $chunk): array {
                    return [
                        'chunk_id' => (int) data_get($chunk, 'chunk_id', 0),
                        'knowledge_item_id' => (int) data_get($chunk, 'knowledge_item_id', 0),
                        'title' => (string) data_get($chunk, 'title', ''),
                        'chunk_type' => (string) data_get($chunk, 'chunk_type', ''),
                        'content_length' => mb_strlen((string) data_get($chunk, 'content', ''), 'UTF-8'),
                        'summary_for_retrieval_length' => mb_strlen((string) data_get($chunk, 'summary_for_retrieval', ''), 'UTF-8'),
                        'table_text_length' => mb_strlen((string) data_get($chunk, 'table_text', ''), 'UTF-8'),
                        'heading_path' => (string) data_get($chunk, 'heading_path', ''),
                        'section_path' => (string) data_get($chunk, 'section_path', ''),
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * Purpose: Build the blocked answer draft payload used by the frontend when knowledge grounding is too weak.
     * Inputs: The grounding payload and the missing-knowledge instruction payload.
     * Returns: A frontend-ready draft payload.
     * Side effects: None.
     */
    private function blockedAnswerDraftPayload(array $knowledgeGrounding, array $missingKnowledge): array
    {
        return [
            'text' => '',
            'generated_at' => null,
            'generation_state' => 'blocked_missing_knowledge',
            'missing_knowledge' => $missingKnowledge,
            'knowledge_grounding' => $knowledgeGrounding,
        ];
    }

    /**
     * Purpose: Resolve a safe document recommendation from the judge output or the existing fallback service.
     * Inputs: The requirement and the grounding judge result.
     * Returns: A deterministic recommendation payload.
     * Side effects: None.
     */
    private function recommendationForGroundingJudge(SavedNoticeAiRequirement $requirement, array $groundingJudge): array
    {
        return $this->requirementKnowledgeDocumentRecommendationService->recommendForRequirement($requirement, $groundingJudge);
    }

    /**
     * Purpose: Normalize a mixed value into a trimmed nullable string.
     * Inputs: A raw scalar or null.
     * Returns: A trimmed string or null.
     * Side effects: None.
     */
    private function normalizeOptionalString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Purpose: Normalize a mixed array into a unique list of trimmed strings.
     * Inputs: A mixed array-like value.
     * Returns: A deterministic list of strings.
     * Side effects: None.
     */
    private function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($values as $value) {
            $item = trim((string) $value);

            if ($item === '') {
                continue;
            }

            $key = mb_strtolower($item, 'UTF-8');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * Purpose: Normalize grounding judge direct support points into stable objects.
     * Inputs: A raw array-like payload from the judge.
     * Returns: A deterministic list of supported point objects.
     * Side effects: None.
     */
    private function normalizeGroundingPointList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_array($value)) {
                continue;
            }

            $requirementPoint = $this->normalizeOptionalString(data_get($value, 'requirement_point'));
            $supportSummary = $this->normalizeOptionalString(data_get($value, 'support_summary'));
            $evidenceReference = $this->normalizeOptionalString(data_get($value, 'evidence_reference'));
            $evidenceQuote = $this->normalizeOptionalString(data_get($value, 'evidence_quote'));
            $source = $this->normalizeGroundingSourcePayload(data_get($value, 'source'));

            if ($requirementPoint === null && $supportSummary === null) {
                continue;
            }

            $normalized[] = [
                'requirement_point' => $requirementPoint ?? $supportSummary,
                'support_summary' => $supportSummary ?? $requirementPoint,
                'evidence_reference' => $evidenceReference,
                'evidence_quote' => $evidenceQuote,
                'source' => $source,
            ];
        }

        return $normalized;
    }

    /**
     * Purpose: Attach stable source metadata to grounding points when the retrieved chunks can be matched safely.
     * Inputs: Normalized grounding points and the retrieved knowledge chunk rows.
     * Returns: The points with optional source payloads.
     * Side effects: None.
     */
    private function enrichGroundingPointsWithSources(array $points, Collection $retrievedKnowledgeChunks): array
    {
        if ($points === [] || $retrievedKnowledgeChunks->isEmpty()) {
            return $points;
        }

        $retrievalSources = $retrievedKnowledgeChunks->values()->all();

        return array_map(function (array $point) use ($retrievalSources): array {
            $source = $this->resolveGroundingPointSource($point, $retrievalSources);

            if ($source !== null) {
                $point['source'] = $source;
            }

            return $point;
        }, $points);
    }

    /**
     * Purpose: Resolve one grounding point to a retrieved source chunk when the evidence text matches safely.
     * Inputs: One normalized grounding point and the retrieved knowledge chunk rows.
     * Returns: A stable source payload or null when no safe match exists.
     * Side effects: None.
     */
    private function resolveGroundingPointSource(array $point, array $retrievalSources): ?array
    {
        $reference = $this->normalizeGroundingSourceText(data_get($point, 'evidence_reference'));
        $quote = $this->normalizeGroundingSourceText(data_get($point, 'evidence_quote'));

        if ($reference === '' && $quote === '') {
            return null;
        }

        $bestSource = null;
        $bestScore = 0;
        $bestCount = 0;

        foreach ($retrievalSources as $retrievalSource) {
            if (! is_array($retrievalSource)) {
                continue;
            }

            $score = $this->scoreGroundingPointSourceMatch($reference, $quote, $retrievalSource);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSource = $retrievalSource;
                $bestCount = 1;

                continue;
            }

            if ($score > 0 && $score === $bestScore) {
                $bestCount++;
            }
        }

        if ($bestSource === null || $bestScore < 80 || $bestCount > 1) {
            return null;
        }

        return $this->groundingPointSourcePayload($bestSource);
    }

    /**
     * Purpose: Score how well one retrieved chunk matches a grounding point's evidence text.
     * Inputs: The normalized evidence text and one retrieved source row.
     * Returns: A deterministic integer score.
     * Side effects: None.
     */
    private function scoreGroundingPointSourceMatch(string $reference, string $quote, array $retrievalSource): int
    {
        $score = 0;
        $documentTitle = $this->normalizeGroundingSourceText(data_get($retrievalSource, 'document_title', data_get($retrievalSource, 'knowledge_item_title', '')));
        $sectionTitle = $this->normalizeGroundingSourceText(data_get($retrievalSource, 'section_title', data_get($retrievalSource, 'section_path', data_get($retrievalSource, 'heading_path', ''))));
        $sectionPath = $this->normalizeGroundingSourceText(data_get($retrievalSource, 'section_path', data_get($retrievalSource, 'heading_path', '')));
        $sourceLabel = $this->normalizeGroundingSourceText($this->groundingSourceLabel($retrievalSource));
        $contentPreview = $this->normalizeGroundingSourceText(data_get($retrievalSource, 'content_preview', ''));
        $chunkIndex = (int) data_get($retrievalSource, 'chunk_index', -1);
        $chunkId = (int) data_get($retrievalSource, 'chunk_id', 0);
        $chunkLabel = $chunkIndex >= 0 ? $this->normalizeGroundingSourceText(sprintf('chunk %d', $chunkIndex + 1)) : '';

        if ($chunkId > 0 && preg_match('/\b'.preg_quote((string) $chunkId, '/').'\b/u', $reference) === 1) {
            $score += 100;
        }

        if ($reference !== '' && $documentTitle !== '' && $sectionTitle !== '' && str_contains($reference, $documentTitle) && str_contains($reference, $sectionTitle)) {
            $score += 85;
        }

        if ($reference !== '' && $documentTitle !== '' && $sectionPath !== '' && str_contains($reference, $documentTitle) && str_contains($reference, $sectionPath)) {
            $score += 80;
        }

        if ($reference !== '' && $documentTitle !== '' && $chunkLabel !== '' && str_contains($reference, $documentTitle) && str_contains($reference, $chunkLabel)) {
            $score += 75;
        }

        if ($sourceLabel !== '' && $reference !== '' && $reference === $sourceLabel) {
            $score += 90;
        } elseif ($sourceLabel !== '' && $reference !== '' && str_contains($reference, $sourceLabel)) {
            $score += 85;
        }

        if ($documentTitle !== '' && $reference !== '' && str_contains($reference, $documentTitle)) {
            $score += 20;
        }

        if ($sectionTitle !== '' && $reference !== '' && str_contains($reference, $sectionTitle)) {
            $score += 20;
        }

        if ($sectionPath !== '' && $reference !== '' && str_contains($reference, $sectionPath)) {
            $score += 15;
        }

        if ($chunkLabel !== '' && $reference !== '' && str_contains($reference, $chunkLabel)) {
            $score += 10;
        }

        if ($quote !== '' && $contentPreview !== '' && str_contains($contentPreview, $quote)) {
            $score += 90;
        }

        return $score;
    }

    /**
     * Purpose: Convert one retrieved source row into a stable, frontend-friendly source payload.
     * Inputs: A retrieved knowledge chunk row.
     * Returns: A normalized source payload or null when it cannot be opened safely.
     * Side effects: None.
     */
    private function groundingPointSourcePayload(array $retrievalSource): ?array
    {
        $knowledgeItemId = (int) data_get($retrievalSource, 'knowledge_item_id', 0);
        $chunkId = (int) data_get($retrievalSource, 'chunk_id', 0);
        $documentTitle = $this->normalizeOptionalString(data_get($retrievalSource, 'document_title', data_get($retrievalSource, 'knowledge_item_title', '')));
        $sectionTitle = $this->normalizeOptionalString(data_get($retrievalSource, 'section_title', data_get($retrievalSource, 'section_path', data_get($retrievalSource, 'heading_path', ''))));
        $sectionPath = $this->normalizeOptionalString(data_get($retrievalSource, 'section_path', data_get($retrievalSource, 'heading_path', '')));
        $contentPreview = $this->normalizeOptionalString(data_get($retrievalSource, 'content_preview', ''));
        $chunkIndex = (int) data_get($retrievalSource, 'chunk_index', -1);

        if ($knowledgeItemId <= 0 || $chunkId <= 0) {
            return null;
        }

        return [
            'knowledge_item_id' => $knowledgeItemId,
            'knowledge_item_chunk_id' => $chunkId,
            'document_title' => $documentTitle,
            'section_title' => $sectionTitle,
            'section_path' => $sectionPath,
            'chunk_index' => $chunkIndex >= 0 ? $chunkIndex + 1 : null,
            'source_label' => $this->groundingSourceLabel($retrievalSource),
            'open_url' => route('app.ai.knowledge-base.show', [
                'knowledgeItem' => $knowledgeItemId,
                'chunk' => $chunkId,
            ]),
            'content' => $this->normalizeOptionalString(data_get($retrievalSource, 'content')),
            'content_preview' => $contentPreview,
        ];
    }

    /**
     * Purpose: Build a readable label for a retrieved chunk source.
     * Inputs: A retrieved knowledge chunk row.
     * Returns: A compact source label.
     * Side effects: None.
     */
    private function groundingSourceLabel(array $retrievalSource): string
    {
        $documentTitle = $this->normalizeOptionalString(data_get($retrievalSource, 'document_title', data_get($retrievalSource, 'knowledge_item_title', '')));
        $sectionTitle = $this->normalizeOptionalString(data_get($retrievalSource, 'section_title', data_get($retrievalSource, 'section_path', data_get($retrievalSource, 'heading_path', ''))));
        $chunkIndex = (int) data_get($retrievalSource, 'chunk_index', -1);

        $parts = array_filter([
            $documentTitle !== '' ? $documentTitle : null,
            $sectionTitle !== '' ? $sectionTitle : null,
            $chunkIndex >= 0 ? sprintf('Chunk %d', $chunkIndex + 1) : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        return trim(implode(' · ', $parts));
    }

    /**
     * Purpose: Normalize evidence text so source matching can compare labels safely.
     * Inputs: A mixed scalar or null.
     * Returns: A lowercased, punctuation-normalized string.
     * Side effects: None.
     */
    private function normalizeGroundingSourceText(mixed $value): string
    {
        $normalized = mb_strtolower(trim((string) ($value ?? '')), 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * Purpose: Normalize an optional source payload returned by the judge or source matcher.
     * Inputs: A mixed source value.
     * Returns: A stable source payload or null.
     * Side effects: None.
     */
    private function normalizeGroundingSourcePayload(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $knowledgeItemId = (int) data_get($value, 'knowledge_item_id', 0);
        $knowledgeItemChunkId = (int) data_get($value, 'knowledge_item_chunk_id', 0);
        $openUrl = $this->normalizeOptionalString(data_get($value, 'open_url'));

        if ($knowledgeItemId <= 0 || $knowledgeItemChunkId <= 0 || $openUrl === null) {
            return null;
        }

        $documentTitle = $this->normalizeOptionalString(data_get($value, 'document_title'));
        $sectionTitle = $this->normalizeOptionalString(data_get($value, 'section_title'));
        $sectionPath = $this->normalizeOptionalString(data_get($value, 'section_path'));
        $sourceLabel = $this->normalizeOptionalString(data_get($value, 'source_label'));
        $content = $this->normalizeOptionalString(data_get($value, 'content'));
        $contentPreview = $this->normalizeOptionalString(data_get($value, 'content_preview'));
        $chunkIndex = data_get($value, 'chunk_index');
        $normalizedChunkIndex = is_numeric($chunkIndex) ? (int) $chunkIndex : null;

        return [
            'knowledge_item_id' => $knowledgeItemId,
            'knowledge_item_chunk_id' => $knowledgeItemChunkId,
            'document_title' => $documentTitle,
            'section_title' => $sectionTitle,
            'section_path' => $sectionPath,
            'chunk_index' => $normalizedChunkIndex,
            'source_label' => $sourceLabel,
            'open_url' => $openUrl,
            'content' => $content,
            'content_preview' => $contentPreview,
        ];
    }

    /**
     * Purpose: Convert normalized grounding points into a compatibility list of strings.
     * Inputs: The normalized direct support points.
     * Returns: A deterministic list of requirement point strings.
     * Side effects: None.
     */
    private function normalizeGroundingPointRequirementPoints(array $points): array
    {
        $normalized = [];
        $seen = [];

        foreach ($points as $point) {
            $text = trim((string) data_get($point, 'requirement_point', data_get($point, 'support_summary', '')));

            if ($text === '') {
                continue;
            }

            $key = mb_strtolower($text, 'UTF-8');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $text;
        }

        return $normalized;
    }

    /**
     * Purpose: Log a safe diagnostic snapshot of the grounding judge context.
     * Inputs: The saved notice id, requirement id, and retrieved knowledge rows.
     * Returns: A log payload with chunk ids, section context, and evidence-term probes.
     * Side effects: None.
     */
    private function groundingJudgeContextDiagnostics(int $savedNoticeId, int $requirementId, Collection $retrievedKnowledgeChunks): array
    {
        $chunkSummaries = $retrievedKnowledgeChunks
            ->map(function (array $row): array {
                $contentPreview = (string) data_get($row, 'content_preview', '');

                return [
                    'chunk_id' => (int) data_get($row, 'chunk_id', 0),
                    'chunk_index' => (int) data_get($row, 'chunk_index', 0),
                    'section_title' => $this->normalizeOptionalString(data_get($row, 'section_title')),
                    'section_path' => $this->normalizeOptionalString(data_get($row, 'section_path')),
                    'topic' => $this->normalizeOptionalString(data_get($row, 'topic')),
                    'sub_topic' => $this->normalizeOptionalString(data_get($row, 'sub_topic')),
                    'keywords' => $this->normalizeStringList((array) data_get($row, 'keywords', [])),
                    'evidence_terms' => $this->diagnosticEvidenceTermsFromText($contentPreview),
                ];
            })
            ->values()
            ->all();

        $probeTerms = collect($chunkSummaries)
            ->pluck('evidence_terms')
            ->flatten()
            ->filter(static fn (mixed $term): bool => is_string($term) && trim($term) !== '')
            ->map(static fn (string $term): string => trim($term))
            ->unique(fn (string $term): string => mb_strtolower($term, 'UTF-8'))
            ->values()
            ->all();

        $compactedContext = json_encode($chunkSummaries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $contextContainsProbeTerms = $this->contextContainsTerms($compactedContext, $probeTerms);

        return [
            'saved_notice_id' => $savedNoticeId,
            'requirement_id' => $requirementId,
            'retrieved_chunk_count' => $retrievedKnowledgeChunks->count(),
            'retrieved_chunk_ids' => array_values(array_filter(array_map(
                static fn (array $row): int => (int) data_get($row, 'chunk_id', 0),
                $retrievedKnowledgeChunks->all(),
            ))),
            'retrieved_chunk_context' => $chunkSummaries,
            'prompt_context_contains_key_evidence_terms' => $contextContainsProbeTerms,
        ];
    }

    /**
     * Purpose: Derive a compact list of diagnostic evidence terms from safe text.
     * Inputs: A compact content preview.
     * Returns: A bounded list of likely evidence terms.
     * Side effects: None.
     */
    private function diagnosticEvidenceTermsFromText(string $text): array
    {
        $normalizedText = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($normalizedText === '') {
            return [];
        }

        preg_match_all('/\b(?:[A-ZÆØÅ0-9][A-Za-z0-9ÆØÅæøå]+(?:\s+[A-Z0-9ÆØÅ][A-Za-z0-9ÆØÅæøå\-]+){0,3}|[A-ZÆØÅ0-9]{2,}(?:\/[A-ZÆØÅ0-9]{2,})+)\b/u', $normalizedText, $matches);

        if (! is_array($matches[0] ?? null)) {
            return [];
        }

        $terms = [];
        $seen = [];

        foreach ($matches[0] as $term) {
            $term = trim((string) $term);

            if ($term === '') {
                continue;
            }

            $key = mb_strtolower($term, 'UTF-8');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $terms[] = $term;

            if (count($terms) >= 8) {
                break;
            }
        }

        return $terms;
    }

    /**
     * Purpose: Check whether a compact context string contains the probe terms.
     * Inputs: The serialized compact context and the probe terms.
     * Returns: True when at least one probe term is present.
     * Side effects: None.
     */
    private function contextContainsTerms(string $context, array $probeTerms): bool
    {
        if (trim($context) === '' || $probeTerms === []) {
            return false;
        }

        foreach ($probeTerms as $term) {
            $normalizedTerm = trim((string) $term);

            if ($normalizedTerm === '') {
                continue;
            }

            if (str_contains($context, $normalizedTerm)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Purpose: Summarize the requirements state for a visible saved notice.
     * Inputs: A visible saved notice with requirement relations loaded.
     * Returns: A compact case-level overview of review and work counts.
     * Side effects: None.
     */
    private function requirementsOverviewPayload(Collection $requirements): array
    {
        $confirmedRequirements = $requirements->filter(fn (SavedNoticeAiRequirement $requirement): bool => $requirement->isApproved());
        $pendingRequirements = $requirements->filter(fn (SavedNoticeAiRequirement $requirement): bool => $requirement->isDraft());
        $rejectedRequirements = $requirements->filter(fn (SavedNoticeAiRequirement $requirement): bool => $requirement->isRejected());

        return [
            'confirmed_total' => $confirmedRequirements->count(),
            'approved_total' => $confirmedRequirements->count(),
            'pending_total' => $pendingRequirements->count(),
            'draft_total' => $pendingRequirements->count(),
            'rejected_total' => $rejectedRequirements->count(),
            'not_started_total' => $confirmedRequirements->where('work_status', SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED)->count(),
            'in_progress_total' => $confirmedRequirements->where('work_status', SavedNoticeAiRequirement::WORK_STATUS_IN_PROGRESS)->count(),
            'done_total' => $confirmedRequirements->where('work_status', SavedNoticeAiRequirement::WORK_STATUS_DONE)->count(),
            'unassigned_confirmed_total' => $confirmedRequirements->whereNull('assigned_user_id')->count(),
            'unassigned_approved_total' => $confirmedRequirements->whereNull('assigned_user_id')->count(),
        ];
    }

    /**
     * Purpose: Build the list of customer users that can be assigned to an AI requirement.
     * Inputs: The current customer id for the visible AI case.
     * Returns: A compact list of assignable user options.
     * Side effects: None.
     */
    private function customerRequirementAssigneeOptions(int $customerId): array
    {
        return User::query()
            ->where('customer_id', $customerId)
            ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_active'])
            ->map(fn (User $user): array => [
                'value' => $user->id,
                'label' => $user->is_active
                    ? sprintf('%s · %s', $user->name, $user->email)
                    : sprintf('%s · %s (inactive)', $user->name, $user->email),
            ])
            ->values()
            ->all();
    }

    /**
     * Purpose: Build the list of all customer users that can be chosen as the responsible user for one requirement.
     * Inputs: The current customer id for the visible AI case.
     * Returns: A compact list of assignable users with identity fields.
     * Side effects: None.
     */
    private function customerAssignableUsers(int $customerId): array
    {
        return User::query()
            ->where('customer_id', $customerId)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->values()
            ->all();
    }

    /**
     * Purpose: Resolve the canonical AI control status for a visible saved notice.
     * Inputs: A saved notice with count-loaded relations.
     * Returns: One of the canonical AI status keys.
     * Side effects: None.
     */
    private function analysisStatusFor(SavedNotice $notice): string
    {
        if (in_array($notice->bid_status, [
            SavedNotice::BID_STATUS_GO_NO_GO,
            SavedNotice::BID_STATUS_IN_PROGRESS,
            SavedNotice::BID_STATUS_SUBMITTED,
            SavedNotice::BID_STATUS_NEGOTIATION,
        ], true)) {
            return 'in_review';
        }

        $hasAnalysisFoundation = (int) ($notice->info_items_count ?? 0) > 0
            || (int) ($notice->phase_comments_count ?? 0) > 0
            || (int) ($notice->submissions_count ?? 0) > 0
            || (int) ($notice->business_reviews_count ?? 0) > 0
            || $notice->bid_manager_user_id !== null
            || $notice->opportunity_owner_user_id !== null;

        if ($hasAnalysisFoundation) {
            return 'ready';
        }

        return 'not_started';
    }

    /**
     * Purpose: Resolve a saved notice that is visible in the current AI workspace.
     * Inputs: The current request and the route-bound saved notice model.
     * Returns: The visible saved notice record for the current customer context.
     * Side effects: Aborts with HTTP 404 if the saved notice is not visible.
     */
    private function visibleAiSavedNotice(Request $request, SavedNotice $savedNotice): SavedNotice
    {
        [$user, $customerId] = $this->frontendContext($request);

        return $this->savedNoticeAccess->visibleQueryFor($user)
            ->where('customer_id', $customerId)
            ->whereNull('archived_at')
            ->whereKey($savedNotice->id)
            ->firstOrFail();
    }

    /**
     * Purpose: Synchronize stored text chunks for a saved notice AI document.
     * Inputs: The persisted AI document and structured text blocks.
     * Returns: None.
     * Side effects: Deletes existing chunks and recreates them from the extracted text when available.
     */
    private function syncDocumentChunks(SavedNoticeAiDocument $document, array $structuredBlocks): void
    {
        $document->chunks()->delete();

        $chunks = $this->documentChunker->chunkStructured($structuredBlocks);

        if ($chunks === []) {
            return;
        }

        $document->chunks()->createMany(array_map(
            /**
             * Purpose: Attach the canonical chunk index to a chunk payload before persistence.
             * Inputs: A chunk payload and its zero-based position in the current chunk set.
             * Returns: A chunk payload ready for the database insert.
             * Side effects: None.
             */
            static fn (array $chunk, int $index): array => [
                'chunk_index' => $index,
                'content' => $chunk['content'],
                'char_start' => $chunk['char_start'],
                'char_end' => $chunk['char_end'],
                'word_count' => $chunk['word_count'],
            ],
            $chunks,
            array_keys($chunks),
        ));
    }

    /**
     * Purpose: Rebuild requirement candidates for a persisted AI document.
     * Inputs: The persisted AI document whose chunks should be scanned.
     * Returns: None.
     * Side effects: Deletes and recreates requirement rows for the document.
     */
    private function syncDocumentRequirements(SavedNoticeAiDocument $document, ?User $changedBy = null, ?string $runId = null): void
    {
        $this->requirementExtractionPipeline->syncDocumentRequirements($document, $changedBy, $runId);
    }

    /**
     * Purpose: Regenerate persisted evidence rows for one confirmed requirement.
     * Inputs: The requirement, the scoped customer knowledge chunks, and the current user id.
     * Returns: None.
     * Side effects: Deletes stale auto-suggested evidence rows and creates deterministic matches.
     */
    private function syncRequirementEvidence(
        SavedNoticeAiRequirement $requirement,
        Collection $knowledgeChunks,
        ?int $createdByUserId,
        ?array $requirementEmbedding = null,
    ): void {
        $existingEvidence = $requirement->evidence()->get();
        $preservedChunkIds = $existingEvidence
            ->reject(static function (SavedNoticeAiEvidence $evidence): bool {
                return $evidence->match_type === SavedNoticeAiEvidence::MATCH_TYPE_AUTO_MATCH
                    && $evidence->selection_status === SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED;
            })
            ->pluck('knowledge_item_chunk_id')
            ->all();

        $requirement->evidence()
            ->where('match_type', SavedNoticeAiEvidence::MATCH_TYPE_AUTO_MATCH)
            ->where('selection_status', SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED)
            ->delete();

        if ($knowledgeChunks->isEmpty()) {
            return;
        }

        $matches = $this->requirementKnowledgeMatcher->match(
            (string) $requirement->requirement_text,
            $knowledgeChunks,
            $requirementEmbedding,
        );

        if ($matches->isEmpty()) {
            return;
        }

        foreach ($matches->values() as $index => $match) {
            $chunkId = (int) $match['chunk_id'];

            if (in_array($chunkId, $preservedChunkIds, true)) {
                continue;
            }

            $requirement->evidence()->create([
                'knowledge_item_id' => (int) $match['knowledge_item_id'],
                'knowledge_item_chunk_id' => $chunkId,
                'knowledge_item_version_id' => isset($match['knowledge_item_version_id'])
                    ? (int) $match['knowledge_item_version_id']
                    : null,
                'match_type' => SavedNoticeAiEvidence::MATCH_TYPE_AUTO_MATCH,
                'match_score' => (int) $match['score'],
                'match_rank' => $index + 1,
                'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED,
                'is_primary' => false,
                'created_by_user_id' => $createdByUserId,
            ]);
        }
    }

    /**
     * Purpose: Generate a temporary embedding for one requirement before evidence refresh.
     * Inputs: The requirement row that is about to be matched.
     * Returns: The embedding vector when available, otherwise null.
     * Side effects: Logs controlled upstream failures and falls back to matcher-only retrieval.
     */
    private function requirementEmbeddingFor(SavedNoticeAiRequirement $requirement): ?array
    {
        $requirementText = trim((string) $requirement->requirement_text);

        if ($requirementText === '') {
            return null;
        }

        $outcome = app(EmbeddingService::class)->tryEmbedText($requirementText);

        if (($outcome['ok'] ?? false) !== true) {
            $this->logRequirementEmbeddingFailure($requirement, $outcome);

            return null;
        }

        return is_array($outcome['embedding'] ?? null) ? $outcome['embedding'] : null;
    }

    /**
     * Purpose: Log a temporary requirement embedding failure before evidence refresh.
     * Inputs: The requirement row and the embedding outcome.
     * Returns: None.
     * Side effects: Writes a warning or error log entry.
     */
    private function logRequirementEmbeddingFailure(SavedNoticeAiRequirement $requirement, array $outcome): void
    {
        $context = [
            'saved_notice_ai_requirement_id' => $requirement->id,
            'saved_notice_id' => $requirement->saved_notice_id,
            'error_type' => $outcome['error_type'] ?? null,
            'error_message' => $outcome['error_message'] ?? null,
            'upstream_status' => $outcome['upstream_status'] ?? null,
            'request_id' => $outcome['request_id'] ?? null,
            'response_body_excerpt' => $outcome['response_body_excerpt'] ?? null,
        ];

        if (in_array($outcome['error_type'] ?? null, ['unexpected_response', 'invalid_request'], true)) {
            Log::error('Requirement embedding failed during evidence refresh.', $context);

            return;
        }

        Log::warning('Requirement embedding failed during evidence refresh.', $context);
    }

    /**
     * Purpose: Format a file size for human-readable display.
     * Inputs: The file size in bytes or null when not available.
     * Returns: A compact human-readable file size label.
     * Side effects: None.
     */
    private function humanFileSize(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }

        if ($bytes < 1024) {
            return sprintf('%d B', $bytes);
        }

        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%.1f MB', $bytes / (1024 * 1024));
    }
}
