<?php

namespace App\Http\Controllers\App;

use App\Data\Ai\Requirements\DocxTableData;
use App\Data\Ai\Requirements\RequirementEditData;
use App\Data\Ai\Requirements\RequirementViewData;
use App\Exceptions\Ai\AiCostControlException;
use App\Exceptions\Ai\XlsxRequirementImportException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\RequirementExtractionCall;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiAnswerBasisItem;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementAssessment;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Models\User;
use App\Services\Ai\AiUsageGuard;
use App\Services\Ai\Commercial\AiQuotaStatusService;
use App\Services\Ai\DocumentPreviewService;
use App\Services\Ai\Requirements\Excel\XlsxRequirementImportPreparer;
use App\Services\Ai\Requirements\RequirementAnswerBasisService;
use App\Services\Ai\Requirements\RequirementEditorService;
use App\Services\Ai\Requirements\RequirementExtractionPipeline;
use App\Services\Ai\Requirements\RequirementExtractionRunService;
use App\Services\Ai\Requirements\RequirementLoader;
use App\Services\Ai\Requirements\RequirementWordExportService;
use App\Services\Ai\Wiki\RequirementWikiAnswerFigureResolver;
use App\Services\Ai\Wiki\RequirementWikiAnswerService;
use App\Services\Ai\Wiki\RequirementWikiAssessmentService;
use App\Services\Billing\BillingEntitlementService;
use App\Services\DocumentChunker;
use App\Services\DocumentTextExtractor;
use App\Services\InfoCenter\RequirementResponsibilityTaskService;
use App\Services\SavedNoticeAccessService;
use App\Support\Ai\AiCostControlPresenter;
use App\Support\CustomerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        private readonly RequirementEditorService $requirementEditorService,
        private readonly DocumentPreviewService $documentPreviewService,
        private readonly RequirementResponsibilityTaskService $requirementResponsibilityTaskService,
        private readonly AiUsageGuard $aiUsageGuard,
        private readonly RequirementWordExportService $requirementWordExportService,
        private readonly RequirementWikiAnswerService $requirementWikiAnswerService,
        private readonly RequirementWikiAssessmentService $requirementWikiAssessmentService,
        private readonly RequirementWikiAnswerFigureResolver $wikiAnswerFigureResolver,
        private readonly XlsxRequirementImportPreparer $xlsxRequirementImportPreparer,
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
            'requirements_reject_all_url' => route('app.ai.requirements.reject-all', ['savedNotice' => $record->id]),
            'requirements_delete_all_url' => route('app.ai.requirements.destroy-all', ['savedNotice' => $record->id]),
            'assessment_refresh_url' => route('app.ai.requirements.assessment.refresh', ['savedNotice' => $record->id]),
            'assigned_user_options' => $this->customerRequirementAssigneeOptions((int) $record->customer_id),
            'assignable_users' => $this->customerAssignableUsers((int) $record->customer_id),
            'documents_upload_url' => route('app.ai.documents.store', ['savedNotice' => $record->id]),
            'documents' => $this->aiDocumentsPayload($record),
            'answer_basis_items' => $this->aiAnswerBasisItemsPayload($record->answerBasisItems),
            'answer_basis_documents_upload_url' => route('app.ai.answer-basis.documents.store', ['savedNotice' => $record->id]),
            'answer_basis_text_store_url' => route('app.ai.answer-basis.texts.store', ['savedNotice' => $record->id]),
            'export_docx_url' => route('app.ai.requirements.export.docx', ['savedNotice' => $record->id]),
            'can_use_ai_offer' => $canUseAiOffer,
            'ai_quota' => $this->aiQuotaPayload($record),
        ]);
    }

    /**
     * Purpose: Expose the compact commercial AI-case position for the workspace this case lives in.
     * Inputs: The visible saved notice.
     * Returns: The canonical quota payload, or null when the case has no resolvable customer.
     * Side effects: None.
     *
     * This is the same state the hard stop enforces — the workspace never computes its own.
     *
     * @return array<string, mixed>|null
     */
    private function aiQuotaPayload(SavedNotice $record): ?array
    {
        $customer = $record->customer;

        return $customer instanceof Customer
            ? app(AiQuotaStatusService::class)->forCustomer($customer)->toArray()
            : null;
    }

    /**
     * Purpose: Render the dedicated AI instruction page for a visible saved notice.
     * Inputs: The current request and the route-bound saved notice model.
     * Returns: Inertia\Response for the AI instruction page.
     * Side effects: None.
     *
     * The instruction itself is owned by the customer, not the case: the page is reached through a
     * case only because that is where the AI menu lives. Every case belonging to the customer reads
     * and writes the same value.
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
            'ai_instructions' => (string) ($this->customerAiInstructions($record) ?? ''),
            'ai_instructions_update_url' => route('app.ai.instructions.update', ['savedNotice' => $record->id]),
        ]);
    }

    /**
     * Purpose: Persist the shared, customer-owned AI instruction reached through a visible saved notice.
     * Inputs: The current request and the route-bound saved notice.
     * Returns: A redirect back to the AI instruction page after saving.
     * Side effects: Updates the owning customer row; the saved notice itself is not written to.
     *
     * The route is case-scoped only for URL continuity — the value is stored on the customer that
     * owns the case and therefore applies to all of that customer's cases. visibleAiSavedNotice()
     * already restricts the case to the request's own customer, so a manipulated savedNotice id
     * belonging to another customer cannot reach this write.
     */
    public function updateAiInstructions(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);

        $validated = $request->validate([
            'ai_instructions' => ['nullable', 'string', 'max:20000'],
        ]);

        $normalizedInstructions = trim(str_replace(["\r\n", "\r"], "\n", (string) ($validated['ai_instructions'] ?? '')));

        $record->customer()->firstOrFail()->forceFill([
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

            // DOCX gets structure-preserving extraction (see DocumentTextExtractor::
            // extractDocxTextAndTables()) so requirement tables retain their column/value
            // association instead of relying on flattened prose alone. Other formats keep the
            // existing plain-text path unchanged.
            $parsedTables = [];
            $parsedTextElements = [];

            if ($extension === 'docx') {
                $docxResult = $this->documentTextExtractor->extractDocxTextAndTables($absolutePath);
                $extractedText = $docxResult['text'];
                $parsedTables = $docxResult['tables'];
                $parsedTextElements = $docxResult['text_elements'];
            } elseif ($extension === 'xlsx') {
                // Excel goes through structure discovery instead of flat text: a workbook has no
                // reading order to flatten, and the old fallback produced requirements with no
                // reliable link to any cell. Prepared BEFORE the document row is created, so a
                // workbook we cannot read safely leaves nothing half-imported behind — the file on
                // disk is removed and the user is told why.
                try {
                    $excelInput = $this->xlsxRequirementImportPreparer->prepare(
                        $absolutePath,
                        $originalFilename,
                        $this->customerContext->resolveLanguageCode($request->user()),
                    );
                } catch (XlsxRequirementImportException $exception) {
                    Storage::disk('local')->delete($storedPath);

                    Log::warning('[EXCEL_REQUIREMENT_IMPORT] Upload refused.', [
                        'saved_notice_id' => $record->id,
                        'document_filename' => $originalFilename,
                        'reason' => $exception->translationKey,
                        'details' => $exception->details,
                    ]);

                    return back()->with('error', __($exception->translationKey, ['file' => $originalFilename]));
                }

                $extractedText = $excelInput['extracted_text'];
                $parsedTextElements = $excelInput['text_elements'];
            } else {
                $extractedText = $this->documentTextExtractor->extractText($absolutePath);
            }

            $structuredBlocks = $extension === 'xlsx'
                // One block per logical requirement, so a chunk never splits a requirement in half.
                ? array_map(
                    static fn (array $element): array => ['text' => $element['text'], 'style' => null, 'level' => null],
                    $parsedTextElements,
                )
                : $this->documentTextExtractor->extractStructuredText($absolutePath);

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

            if ($parsedTables !== []) {
                $stampedTables = DocxTableData::manyWithDocumentId($parsedTables, $documentRecord->id);
                $documentRecord->forceFill([
                    'structured_tables' => array_map(static fn (DocxTableData $table): array => $table->toArray(), $stampedTables),
                ])->save();
            }

            if ($parsedTextElements !== []) {
                // Document-scoped the same way DocxTableData::withDocumentId() scopes
                // source_row_key — parsing happens before the document's DB id is known.
                $stampedTextElements = array_map(
                    static fn (array $element): array => [
                        ...$element,
                        'element_key' => sprintf('doc%d-%s', $documentRecord->id, $element['element_key']),
                    ],
                    $parsedTextElements,
                );
                $documentRecord->forceFill([
                    'structured_text_elements' => $stampedTextElements,
                ])->save();
            }

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
     * Purpose: Export all requirements with a generated Wiki answer as a Word (.docx) document.
     * Inputs: The current request and the route-bound saved notice.
     * Returns: A streamed .docx download response, or 422 if no Wiki answers exist.
     * Side effects: None.
     */
    public function exportRequirementsToDocx(
        Request $request,
        SavedNotice $savedNotice,
    ): StreamedResponse|\Illuminate\Http\Response {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);

        $requirements = $record->aiRequirements()
            ->whereHas('wikiAnswer', function ($query): void {
                $query->whereNotNull('answer_text')->where('answer_text', '!=', '');
            })
            ->with('wikiAnswer')
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

        $flashMessages = [
            'rejected' => 'Kravet er avvist. Du kan gjenopprette det ved å klikke «Gjenopprett».',
            'confirmed' => 'Kravet er godkjent.',
            'pending' => 'Kravet er gjenopprettet.',
        ];

        return back()->with('success', $flashMessages[$validated['review_status']] ?? 'Kravstatus oppdatert.');
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
     * Purpose: Bulk-reject all AI-extracted requirement candidates for the visible AI case.
     * Inputs: The current request and the route-bound saved notice.
     * Returns: A redirect back to the AI case view after rejection.
     * Side effects: Sets approval_status/review_status to rejected for all ai_candidate requirements.
     *               Rows, relations, evidence, revisions and assessments are preserved.
     */
    public function rejectAllRequirements(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);

        $requirements = $record->aiRequirements()
            ->where('source_type', SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE)
            ->where('approval_status', '!=', SavedNoticeAiRequirement::APPROVAL_STATUS_REJECTED)
            ->get();

        DB::transaction(function () use ($requirements, $request): void {
            foreach ($requirements as $requirement) {
                $this->requirementEditorService->transitionRequirementReviewStatus(
                    $requirement,
                    SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED,
                    $request->user(),
                );
            }
        });

        return back()->with('success', 'Ekstraherte krav er avvist. Du kan gjenopprette dem enkeltvis.');
    }

    /**
     * Purpose: Permanently delete one extracted requirement candidate.
     * Inputs: The current request, route-bound saved notice, and route-bound requirement.
     * Returns: A redirect back to the AI case view.
     * Side effects: Deletes the requirement row. Postgres cascades its evidence, answer-basis
     *               selections, assessments, revisions and Wiki answer along with it.
     *
     * Deliberately separate from rejection rather than replacing it. Rejection
     * (approval_status = rejected) takes a requirement out of active work and can be undone;
     * this cannot. Both belong on the page, because "this is not a requirement for us" and "this
     * should never have been extracted" are different things, and only the second justifies
     * losing an answer draft.
     *
     * Scope is server-owned: the requirement is re-fetched through the case's own relation, so a
     * requirement id belonging to another case or customer resolves to nothing regardless of what
     * the client sent. Only AI-extracted candidates are deletable here — a manually added
     * requirement is the user's own work, not import residue.
     */
    public function destroyRequirement(
        Request $request,
        SavedNotice $savedNotice,
        SavedNoticeAiRequirement $requirement,
    ): RedirectResponse {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);

        $ownedRequirement = $record->aiRequirements()
            ->whereKey($requirement->id)
            ->where('source_type', SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE)
            ->firstOrFail();

        DB::transaction(static fn () => $ownedRequirement->delete());

        Log::info('[PROCYNIA][REQUIREMENT_DELETE] Requirement deleted permanently.', [
            'saved_notice_id' => $record->id,
            'requirement_id' => $requirement->id,
            'user_id' => $request->user()?->id,
        ]);

        return back()->with('success', __('procynia.ai.requirement_deleted'));
    }

    /**
     * Purpose: Permanently delete every extracted requirement candidate in this case.
     * Inputs: The current request and route-bound saved notice.
     * Returns: A redirect back to the AI case view.
     * Side effects: Deletes the rows and their cascaded dependants inside one transaction.
     *
     * "All" is defined by the server, never by whatever the client currently has filtered or
     * paginated into view: every ai_candidate requirement belonging to this case, rejected ones
     * included. Manually added requirements are left alone.
     *
     * The uploaded document, its extraction run and any parsed workbook data are untouched, so a
     * case emptied by mistake can be rebuilt by running extraction again.
     */
    public function destroyAllRequirements(Request $request, SavedNotice $savedNotice): RedirectResponse
    {
        $record = $this->visibleAiSavedNotice($request, $savedNotice);
        $this->assertAiAccess($record);

        $deletedCount = DB::transaction(static function () use ($record): int {
            $requirements = $record->aiRequirements()
                ->where('source_type', SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE)
                ->get();

            foreach ($requirements as $requirement) {
                $requirement->delete();
            }

            return $requirements->count();
        });

        Log::info('[PROCYNIA][REQUIREMENT_DELETE] Extracted requirements deleted permanently.', [
            'saved_notice_id' => $record->id,
            'deleted_count' => $deletedCount,
            'user_id' => $request->user()?->id,
        ]);

        return back()->with('success', $deletedCount === 0
            ? __('procynia.ai.requirements_deleted_none')
            : __('procynia.ai.requirements_deleted_all', ['count' => $deletedCount]));
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
     * Purpose: Generate (or regenerate) the Wiki-based answer for one visible requirement
     * candidate, using only Enterprise Wiki content approved and available for this customer.
     * Entirely separate from generateRequirementAnswerDraft() — reads/writes none of the
     * existing answer_draft_* state.
     * Inputs: The current request, route-bound saved notice, and route-bound requirement candidate.
     * Returns: A JSON response with the persisted Wiki-answer payload.
     * Side effects: Writes one saved_notice_ai_requirement_wiki_answers row for the requirement.
     */
    public function generateRequirementWikiAnswer(
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
            'user_answer_prompt' => ['nullable', 'string', 'max:5000'],
        ]);
        $userAnswerPrompt = $this->normalizeOptionalPromptText($validated['user_answer_prompt'] ?? null);

        $usageWarning = $this->aiUsageGuard->assertCanStartAiOperation(
            $record->customer()->firstOrFail(),
            $request->user(),
            AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_WIKI_ANSWER,
        );

        if ($usageWarning !== null) {
            session()->flash('warning', $usageWarning);
        }

        $languageCode = $this->customerContext->resolveLanguageCode();

        try {
            $wikiAnswer = $this->requirementWikiAnswerService->generate(
                $ownedRequirement,
                (int) $record->customer_id,
                $languageCode,
                $request->user()?->id,
                $this->customerAiInstructions($record),
                $userAnswerPrompt,
            );
        } catch (AiCostControlException $exception) {
            // A cost-control block is not a technical failure and must not be reported as one: the
            // customer can act on it, but only if they are told which of the four reasons applies.
            Log::info('[PROCYNIA][WIKI_ANSWER] Wiki answer blocked by AI cost control.', [
                'saved_notice_id' => $record->id,
                'requirement_id' => $ownedRequirement->id,
                'reason' => $exception->reason,
            ]);

            return response()->json(array_merge(
                ['requirement_id' => $ownedRequirement->id],
                app(AiCostControlPresenter::class)->payload($exception, $record->customer),
            ), 422);
        } catch (Throwable $exception) {
            Log::warning('[PROCYNIA][WIKI_ANSWER] Wiki answer generation failed.', [
                'saved_notice_id' => $record->id,
                'requirement_id' => $ownedRequirement->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'requirement_id' => $ownedRequirement->id,
                'error' => 'Kunne ikke generere Wiki-svar. Prøv igjen senere.',
            ], 422);
        }

        return response()->json(array_merge(
            $this->aiRequirementWikiAnswerResponsePayload($wikiAnswer, $ownedRequirement),
            ['warning' => $usageWarning],
        ));
    }

    /**
     * Purpose: Persist a hand-edit of one visible requirement's already-generated Wiki answer text.
     * Inputs: The current request, route-bound saved notice, and route-bound requirement candidate.
     * Returns: A JSON response with the persisted Wiki-answer payload.
     * Side effects: Updates the requirement's saved_notice_ai_requirement_wiki_answers row.
     */
    public function updateRequirementWikiAnswer(
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
            'answer_text' => ['required', 'string', 'max:20000'],
        ]);

        $wikiAnswer = $this->requirementWikiAnswerService->updateAnswerText(
            $ownedRequirement,
            (string) $validated['answer_text'],
        );

        return response()->json($this->aiRequirementWikiAnswerResponsePayload($wikiAnswer, $ownedRequirement));
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
     * Purpose: Rebuild persisted assessment rows for every confirmed requirement in the visible AI
     *          case, using Enterprise Wiki knowledge (AI-to-Wiki consolidation — the Knowledge Base
     *          is no longer read here; see RequirementWikiAssessmentService).
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

        $languageCode = $this->customerContext->resolveLanguageCode();
        $customerAiInstructions = $this->customerAiInstructions($record);
        $failedCount = 0;

        foreach ($confirmedRequirements as $requirement) {
            try {
                $this->requirementWikiAssessmentService->assessRequirement(
                    $requirement,
                    (int) $record->customer_id,
                    $languageCode,
                    $userId,
                    $customerAiInstructions,
                );
            } catch (AiCostControlException $exception) {
                // Every remaining requirement would hit the same block, so stop here and report the
                // real reason once instead of marking the rest as technical failures.
                Log::info('[PROCYNIA][ASSESSMENT_REFRESH] Assessment blocked by AI cost control.', [
                    'saved_notice_id' => $record->id,
                    'reason' => $exception->reason,
                ]);

                return back()->with('error', app(AiCostControlPresenter::class)->message($exception, $record->customer));
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
                'has_possible_conflict' => null,
                'engine_version' => null,
                'wiki_sources_snapshot' => [],
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
                'answer_basis_item_ids' => $selectedAnswerBasisItems
                    ->pluck('id')
                    ->map(static fn (mixed $value): int => (int) $value)
                    ->values()
                    ->all(),
                'answer_basis_selection_sync_url' => route('app.ai.requirements.answer-basis.sync', [
                    'savedNotice' => $requirement->saved_notice_id,
                    'requirement' => $requirement->id,
                ]),
                'wiki_answer' => $this->aiRequirementWikiAnswerPayload($requirement->wikiAnswer),
                // Only AI-extracted candidates are deletable; a manually added requirement is the
                // user's own work and has no delete affordance.
                'delete_url' => $requirement->source_type === SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE
                    ? route('app.ai.requirements.destroy', ['savedNotice' => $requirement->saved_notice_id, 'requirement' => $requirement->id])
                    : null,
                'wiki_answer_generate_url' => route('app.ai.requirements.wiki-answer.generate', [
                    'savedNotice' => $requirement->saved_notice_id,
                    'requirement' => $requirement->id,
                ]),
                'wiki_answer_update_url' => route('app.ai.requirements.wiki-answer.update', [
                    'savedNotice' => $requirement->saved_notice_id,
                    'requirement' => $requirement->id,
                ]),
                'assessment' => $this->aiRequirementAssessmentPayload($requirement->assessment),
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
            'has_possible_conflict' => $assessment->has_possible_conflict,
            'wiki_sources' => is_array($assessment->wiki_sources_snapshot) ? $assessment->wiki_sources_snapshot : [],
            'assessed_at' => optional($assessment->assessed_at)?->toIso8601String(),
        ];
    }

    /**
     * Purpose: Convert one persisted Wiki-answer row into a compact frontend payload.
     * Inputs: A requirement's Wiki-answer row, or null when none has been generated yet.
     * Returns: A frontend-ready Wiki-answer array — always present, even when null, so the
     * frontend can render an empty "not generated yet" state without a separate presence check.
     * Side effects: None.
     */
    private function aiRequirementWikiAnswerPayload(?SavedNoticeAiRequirementWikiAnswer $wikiAnswer): array
    {
        if ($wikiAnswer === null) {
            return [
                'coverage_status' => null,
                'coverage_status_label' => null,
                'text' => null,
                'figures' => [],
                'segments' => null,
                'missing_summary' => null,
                'sources' => [],
                'sections' => [],
                'main_pages' => [],
                'discovered_pages' => [],
                'alignment_summary' => null,
                'has_possible_conflict' => null,
                'has_source_based_support' => null,
                'engine_version' => null,
                'generated_at' => null,
                'is_stale' => null,
                'stale_at' => null,
                'stale_reason' => null,
                'stale_context' => null,
            ];
        }

        $sources = is_array($wikiAnswer->sources) ? $wikiAnswer->sources : [];
        $researchTrace = is_array($wikiAnswer->research_trace) ? $wikiAnswer->research_trace : null;
        $answer = is_array($researchTrace['answer'] ?? null) ? $researchTrace['answer'] : null;
        $alignmentTrace = is_array($wikiAnswer->alignment_trace) ? $wikiAnswer->alignment_trace : null;

        $pageTitleById = [];

        foreach ($sources as $source) {
            if (isset($source['enterprise_wiki_page_id'])) {
                $pageTitleById[$source['enterprise_wiki_page_id']] = $source['page_title'] ?? null;
            }
        }

        $alignmentBySectionKey = [];

        foreach (($alignmentTrace['sections'] ?? []) as $alignmentSection) {
            if (is_array($alignmentSection) && is_string($alignmentSection['section_key'] ?? null)) {
                $alignmentBySectionKey[$alignmentSection['section_key']] = $alignmentSection;
            }
        }

        $sections = [];

        foreach (($answer['answer_sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }

            // 'used_page_ids' is the current (wiki_reader_alignment_v3) field name; 'page_ids' is
            // kept as a fallback so rows persisted by the prior engine version (wiki_reader_v2)
            // still render their citations correctly.
            $sectionPageIds = is_array($section['used_page_ids'] ?? null)
                ? $section['used_page_ids']
                : (is_array($section['page_ids'] ?? null) ? $section['page_ids'] : []);

            $sectionKey = is_string($section['key'] ?? null) ? $section['key'] : null;
            $alignment = $sectionKey !== null ? ($alignmentBySectionKey[$sectionKey] ?? null) : null;
            $alignmentStatus = is_string($alignment['alignment_status'] ?? null) ? $alignment['alignment_status'] : null;
            $supportingPageIds = is_array($alignment['supporting_page_ids'] ?? null) ? $alignment['supporting_page_ids'] : [];
            $provenanceType = is_string($alignment['provenance_type'] ?? null) ? $alignment['provenance_type'] : null;

            $sections[] = [
                'key' => $sectionKey,
                'heading' => is_string($section['heading'] ?? null) ? $section['heading'] : null,
                'text' => (string) ($section['text'] ?? ''),
                'page_ids' => $sectionPageIds,
                'page_titles' => array_values(array_filter(array_map(
                    static fn (mixed $pageId): ?string => $pageTitleById[$pageId] ?? null,
                    $sectionPageIds,
                ))),
                'alignment_status' => $alignmentStatus,
                'alignment_status_label' => $alignmentStatus !== null
                    ? (SavedNoticeAiRequirementWikiAnswer::ALIGNMENT_STATUS_LABELS[$alignmentStatus] ?? $alignmentStatus)
                    : null,
                'supporting_page_ids' => $supportingPageIds,
                'supporting_page_titles' => array_values(array_filter(array_map(
                    static fn (mixed $pageId): ?string => $pageTitleById[$pageId] ?? null,
                    $supportingPageIds,
                ))),
                'supported_points' => is_array($alignment['supported_points'] ?? null) ? $alignment['supported_points'] : [],
                'uncovered_points' => is_array($alignment['uncovered_points'] ?? null) ? $alignment['uncovered_points'] : [],
                'conflict_summary' => is_string($alignment['conflict_summary'] ?? null) ? $alignment['conflict_summary'] : null,
                'review_note' => is_string($alignment['review_note'] ?? null) ? $alignment['review_note'] : null,
                'revised' => (bool) ($alignment['revised'] ?? false),
                // provenance_type is computed deterministically from claim content_origin (see
                // RequirementWikiAnswerService::computeSectionsProvenance()) — a different axis from
                // alignment_status: whether this section's concrete facts are actually documented in
                // the customer's own sources (source_based), a professional addition (best_practice),
                // or both (mixed). Null only for answers persisted before this field existed.
                'provenance_type' => $provenanceType,
            ];
        }

        $alignmentSummary = null;

        if ($alignmentTrace !== null) {
            $counts = [
                SavedNoticeAiRequirementWikiAnswer::ALIGNMENT_STATUS_ALIGNED => 0,
                SavedNoticeAiRequirementWikiAnswer::ALIGNMENT_STATUS_PARTIALLY_ALIGNED => 0,
                SavedNoticeAiRequirementWikiAnswer::ALIGNMENT_STATUS_BEST_PRACTICE => 0,
                SavedNoticeAiRequirementWikiAnswer::ALIGNMENT_STATUS_POSSIBLE_CONFLICT => 0,
            ];

            foreach (($alignmentTrace['sections'] ?? []) as $alignmentSection) {
                $status = is_array($alignmentSection) ? ($alignmentSection['alignment_status'] ?? null) : null;

                if (is_string($status) && array_key_exists($status, $counts)) {
                    $counts[$status]++;
                }
            }

            $alignmentSummary = array_merge($counts, ['total' => array_sum($counts)]);
        }

        // Customer scope is taken from the current frontend context, never from the answer row —
        // the same rule every other read in this controller follows.
        $figures = $this->wikiAnswerFigureResolver->resolve($wikiAnswer, (int) $this->customerContext->currentCustomerId());

        return [
            'coverage_status' => $wikiAnswer->coverage_status,
            'coverage_status_label' => SavedNoticeAiRequirementWikiAnswer::COVERAGE_STATUS_LABELS[$wikiAnswer->coverage_status]
                ?? $wikiAnswer->coverage_status,
            'text' => $wikiAnswer->answer_text,
            // Resolved live, every request: a figure whose Wiki page no longer carries it simply
            // disappears from the payload rather than rendering as a broken image.
            'figures' => $figures,
            // Non-null only while the persisted section boundaries still describe answer_text
            // exactly, so a figure is never anchored to text a hand-edit has moved.
            'segments' => $this->wikiAnswerFigureResolver->segments($wikiAnswer, $figures),
            'missing_summary' => $wikiAnswer->missing_summary,
            'sources' => $sources,
            'sections' => $sections,
            'main_pages' => array_values(array_filter(
                $sources,
                static fn (array $source): bool => ($source['selection_type'] ?? null) === 'direct_search',
            )),
            'discovered_pages' => array_values(array_filter(
                $sources,
                static fn (array $source): bool => ($source['selection_type'] ?? null) === 'wikilink',
            )),
            'alignment_summary' => $alignmentSummary,
            'has_possible_conflict' => $wikiAnswer->has_possible_conflict,
            'has_source_based_support' => is_bool($alignmentTrace['has_source_based_support'] ?? null)
                ? $alignmentTrace['has_source_based_support']
                : null,
            'engine_version' => $wikiAnswer->engine_version,
            'generated_at' => optional($wikiAnswer->generated_at)?->toIso8601String(),
            'is_stale' => $wikiAnswer->isStale(),
            'stale_at' => optional($wikiAnswer->stale_at)?->toIso8601String(),
            'stale_reason' => $wikiAnswer->stale_reason,
            'stale_context' => $wikiAnswer->stale_context,
        ];
    }

    /**
     * Purpose: Convert one persisted Wiki-answer row into a JSON response payload.
     * Inputs: The Wiki-answer row and the requirement it belongs to.
     * Returns: A JSON response payload for the Wiki-answer generation endpoint.
     * Side effects: None.
     */
    private function aiRequirementWikiAnswerResponsePayload(
        SavedNoticeAiRequirementWikiAnswer $wikiAnswer,
        SavedNoticeAiRequirement $requirement,
    ): array {
        return [
            'requirement_id' => $requirement->id,
            'wiki_answer' => $this->aiRequirementWikiAnswerPayload($wikiAnswer),
        ];
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
    /**
     * Purpose: Resolve the shared AI instruction that applies to a case, via its owning customer.
     * Inputs: The saved notice whose customer owns the instruction.
     * Returns: The instruction text, or null when the customer has not set one.
     * Side effects: None.
     *
     * The instruction is customer-scoped: every case belonging to the customer resolves the same
     * value. It stays subordinate to grounded facts and sources in every prompt that receives it.
     */
    private function customerAiInstructions(SavedNotice $record): ?string
    {
        return $record->customer()->first()?->resolvedAiInstructions();
    }

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
