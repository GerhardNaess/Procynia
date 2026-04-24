<?php

namespace App\Http\Controllers\App;

use App\Data\Ai\Requirements\RequirementEditData;
use App\Data\Ai\Requirements\RequirementViewData;
use App\Http\Controllers\Controller;
use App\Models\SavedNoticeAiEvidence;
use App\Models\SavedNoticeAiAnswerBasisItem;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiRequirementAssessment;
use App\Models\SavedNoticeAiRequirement;
use App\Models\User;
use App\Services\DocumentChunker;
use App\Services\DocumentTextExtractor;
use App\Services\Ai\DocumentPreviewService;
use App\Services\Ai\Requirements\RequirementExtractionPipeline;
use App\Services\Ai\Requirements\RequirementExtractionRunService;
use App\Services\Ai\Requirements\RequirementAnswerBasisService;
use App\Services\Ai\Requirements\RequirementAnswerDraftService;
use App\Services\Ai\Requirements\RequirementEditorService;
use App\Services\Ai\Requirements\RequirementLoader;
use App\Services\OpenAi\EmbeddingService;
use App\Services\RequirementAssessmentService;
use App\Services\RequirementKnowledgeMatcher;
use App\Services\SavedNoticeAccessService;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Collection;
use Throwable;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

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
        private readonly RequirementEditorService $requirementEditorService,
        private readonly RequirementKnowledgeMatcher $requirementKnowledgeMatcher,
        private readonly DocumentPreviewService $documentPreviewService,
    ) {
    }

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

        return Inertia::render('App/AI/Index', [
            'pageTitle' => 'Oversikt',
            'analysisCases' => $analysisCases,
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

        return Inertia::render('App/AI/Show', [
            'pageTitle' => sprintf('I arbeid · %s', $record->title),
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
            'assessment_refresh_url' => route('app.ai.requirements.assessment.refresh', ['savedNotice' => $record->id]),
            'evidence_refresh_url' => route('app.ai.evidence.refresh', ['savedNotice' => $record->id]),
            'assigned_user_options' => $this->customerRequirementAssigneeOptions((int) $record->customer_id),
            'documents_upload_url' => route('app.ai.documents.store', ['savedNotice' => $record->id]),
            'documents' => $this->aiDocumentsPayload($record),
            'answer_basis_items' => $this->aiAnswerBasisItemsPayload($record->answerBasisItems),
            'answer_basis_documents_upload_url' => route('app.ai.answer-basis.documents.store', ['savedNotice' => $record->id]),
            'answer_basis_text_store_url' => route('app.ai.answer-basis.texts.store', ['savedNotice' => $record->id]),
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

        $request->validate([
            'documents' => ['required', 'array', 'min:1'],
            'documents.*' => ['file', 'mimes:pdf,docx,xlsx', 'max:20480'],
        ]);

        $documents = $request->file('documents', []);
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
        $ownedDocument = $record->aiDocuments()
            ->whereKey($document->id)
            ->firstOrFail();
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
     * Purpose: Persist one or more supplier-owned answer basis documents on a visible AI case.
     * Inputs: The current request and the route-bound saved notice.
     * Returns: A redirect back to the AI case view after saving the uploads.
     * Side effects: Stores files on disk and creates answer basis rows.
     */
    public function storeAnswerBasisDocuments(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);

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

        $ownedRequirement->forceFill([
            'work_status' => $validated['work_status'],
            'assigned_user_id' => isset($validated['assigned_user_id']) && $validated['assigned_user_id'] !== null
                ? (int) $validated['assigned_user_id']
                : null,
        ])->save();

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
        $ownedRequirement = $record->aiRequirements()
            ->whereKey($requirement->id)
            ->firstOrFail();

        $validated = $request->validate([
            'answer_basis_item_ids' => ['present', 'array'],
            'answer_basis_item_ids.*' => ['integer'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $selectedAnswerBasisItems = $this->syncRequirementAnswerBasisSelectionItems(
            $record,
            $ownedRequirement,
            $validated['answer_basis_item_ids'],
        );

        $persistedRequirement = $this->requirementAnswerDraftService->ensureAnswerDraft(
            $ownedRequirement,
            $selectedAnswerBasisItems,
            (bool) ($validated['force'] ?? false),
            $record->ai_instructions,
        );

        $selectedAnswerBasisItems = collect($selectedAnswerBasisItems->all());

        return response()->json(array_merge(
            $this->aiRequirementAnswerDraftResponsePayload($persistedRequirement),
            [
                'answer_basis_item_ids' => $selectedAnswerBasisItems
                    ->pluck('id')
                    ->map(static fn (mixed $value): int => (int) $value)
                    ->values()
                    ->all(),
                'answer_basis_items' => $this->aiAnswerBasisItemsPayload($selectedAnswerBasisItems),
            ],
        ));
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
        $knowledgeChunks = $this->knowledgeChunksForMatching((int) $record->customer_id);
        $userId = $request->user()?->id;

        $confirmedRequirements = $this->requirementLoader->loadApprovedForCase($record->id);

        $requirementEmbeddings = $confirmedRequirements->mapWithKeys(function (SavedNoticeAiRequirement $requirement): array {
            return [$requirement->id => $this->requirementEmbeddingFor($requirement)];
        });

        DB::transaction(function () use ($confirmedRequirements, $knowledgeChunks, $userId, $requirementEmbeddings): void {
            foreach ($confirmedRequirements as $requirement) {
                $this->syncRequirementEvidence(
                    $requirement,
                    $knowledgeChunks,
                    $userId,
                    $requirementEmbeddings->get($requirement->id),
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
        $userId = $request->user()?->id;
        $requirementAssessmentService = app(RequirementAssessmentService::class);

        $confirmedRequirements = $this->requirementLoader->loadApprovedForCase($record->id);

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
            'owner_name' => $notice->bidManager?->name ?? $notice->opportunityOwner?->name ?? 'Not assigned',
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
     * Purpose: Convert one AI document into a detailed preview payload.
     * Inputs: A visible AI document row with its related extraction data loaded.
     * Returns: A frontend-ready array for the preview page.
     * Side effects: None.
     */
    private function aiDocumentPreviewPayload(
        SavedNoticeAiDocument $document,
        ?string $previewMode = null,
        ?string $previewFileUrl = null,
    ): array
    {
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
            ->map(function (SavedNoticeAiRequirement $requirement): array {
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
                ]);

                return array_merge(
                    $viewData->toArray(),
                    [
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
                    ],
                );
            })
            ->values()
            ->all();
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
    private function aiRequirementAnswerDraftPayload(SavedNoticeAiRequirement $requirement): array
    {
        return [
            'text' => (string) ($requirement->answer_draft_text ?? ''),
            'generated_at' => optional($requirement->answer_draft_generated_at)?->toIso8601String(),
        ];
    }

    /**
     * Purpose: Convert one persisted requirement row into a compact answer draft API payload.
     * Inputs: A saved notice AI requirement row.
     * Returns: A JSON response payload for the answer draft endpoints.
     * Side effects: None.
     */
    private function aiRequirementAnswerDraftResponsePayload(SavedNoticeAiRequirement $requirement): array
    {
        return [
            'requirement_id' => $requirement->id,
            'answer_draft' => $this->aiRequirementAnswerDraftPayload($requirement),
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
                $knowledgeDocumentType = $knowledgeItem?->document_type ?? $knowledgeItem?->content_type;

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
     * Purpose: Resolve the customer knowledge chunks that may ground AI requirement matching.
     * Inputs: The current customer id for the visible AI case.
     * Returns: A compact collection of active knowledge chunks limited to a safe V1 cap.
     * Side effects: None.
     */
    private function knowledgeChunksForMatching(int $customerId): Collection
    {
        return KnowledgeItemChunk::query()
            ->join('knowledge_items', 'knowledge_items.id', '=', 'knowledge_item_chunks.knowledge_item_id')
            ->where('knowledge_items.customer_id', $customerId)
            ->where('knowledge_items.is_active', true)
            ->whereNotNull('knowledge_items.storage_path')
            ->where('knowledge_items.extraction_status', KnowledgeItem::EXTRACTION_STATUS_COMPLETED)
            ->orderByDesc('knowledge_items.updated_at')
            ->orderByDesc('knowledge_items.id')
            ->orderBy('knowledge_item_chunks.chunk_index')
            ->orderBy('knowledge_item_chunks.id')
            ->limit(1000)
            ->get([
                'knowledge_item_chunks.*',
                'knowledge_items.original_filename as knowledge_item_title',
                'knowledge_items.document_type as content_type',
                'knowledge_items.updated_at as knowledge_item_updated_at',
            ])
            ->map(static fn (KnowledgeItemChunk $chunk): array => [
                'chunk_id' => (int) $chunk->id,
                'knowledge_item_id' => (int) $chunk->knowledge_item_id,
                'knowledge_item_title' => (string) $chunk->getAttribute('knowledge_item_title'),
                'content_type' => (string) $chunk->getAttribute('content_type'),
                'chunk_index' => (int) $chunk->chunk_index,
                'content' => (string) $chunk->content,
                'embedding_vector' => is_array($chunk->embedding_vector) ? $chunk->embedding_vector : null,
                'embedding_model' => (string) ($chunk->embedding_model ?? ''),
                'embedding_generated_at' => optional($chunk->embedding_generated_at)?->toIso8601String(),
                'embedding_error' => $chunk->embedding_error,
                'knowledge_item_updated_at' => (string) $chunk->getAttribute('knowledge_item_updated_at'),
            ])
            ->values();
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
