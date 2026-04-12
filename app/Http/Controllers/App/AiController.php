<?php

namespace App\Http\Controllers\App;

use App\Data\Ai\Requirements\RequirementEditData;
use App\Data\Ai\Requirements\RequirementViewData;
use App\Http\Controllers\Controller;
use App\Models\SavedNoticeAiEvidence;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\SavedNoticeAiRequirementAssessment;
use App\Models\SavedNoticeAiRequirement;
use App\Models\User;
use App\Services\DocumentChunker;
use App\Services\DocumentTextExtractor;
use App\Services\Ai\Requirements\RequirementExtractionPipeline;
use App\Services\Ai\Requirements\RequirementExtractionRunService;
use App\Services\Ai\Requirements\RequirementEditorService;
use App\Services\Ai\Requirements\RequirementLoader;
use App\Services\OpenAi\EmbeddingService;
use App\Services\RequirementAssessmentService;
use App\Services\RequirementKnowledgeMatcher;
use App\Services\SavedNoticeAccessService;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Collection;
use Throwable;
use Inertia\Inertia;
use Inertia\Response;

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
        private readonly RequirementEditorService $requirementEditorService,
        private readonly RequirementKnowledgeMatcher $requirementKnowledgeMatcher,
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
            'pageTitle' => 'AI-arbeid',
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
        $searchQuery = trim((string) $request->query('search', ''));
        $record->loadMissing([
            'bidManager',
            'opportunityOwner',
            'aiDocuments.uploadedBy',
            'aiDocuments.chunks',
            'aiDocuments.latestExtractionRun',
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
            'pageTitle' => sprintf('AI-arbeid · %s', $record->title),
            'case' => [
                'id' => $analysisCase['id'],
                'title' => $analysisCase['title'],
                'reference' => $analysisCase['reference'],
                'owner' => $analysisCase['owner_name'],
                'stage' => $analysisCase['stage_label'],
                'updated_at' => $analysisCase['updated_at'],
            ],
            'ai_status' => $analysisCase['ai_status'],
            'search_url' => route('app.ai.show', ['savedNotice' => $record->id]),
            'search_query' => $searchQuery,
            'search_results' => $searchQuery !== ''
                ? $this->searchAiDocumentChunks($record, $searchQuery)
                : [],
            'requirements_count' => count($requirementsPayload),
            'requirements_overview' => $requirementsOverview,
            'requirements' => $requirementsPayload,
            'requirements_store_url' => route('app.ai.requirements.store', ['savedNotice' => $record->id]),
            'assessment_refresh_url' => route('app.ai.requirements.assessment.refresh', ['savedNotice' => $record->id]),
            'evidence_refresh_url' => route('app.ai.evidence.refresh', ['savedNotice' => $record->id]),
            'assigned_user_options' => $this->customerRequirementAssigneeOptions((int) $record->customer_id),
            'documents_upload_url' => route('app.ai.documents.store', ['savedNotice' => $record->id]),
            'documents' => $this->aiDocumentsPayload($record),
        ]);
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
            'documents.*' => ['file', 'mimes:pdf,doc,docx,xls,xlsx', 'max:20480'],
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

            $extractedText = $this->documentTextExtractor->extractText(Storage::disk('local')->path($storedPath));

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

            $this->syncDocumentChunks($documentRecord, $extractedText);
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
                $requirementAssessmentService->assessRequirement($requirement, $userId);
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
            ->map(function (SavedNoticeAiDocument $document): array {
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
                    'delete_url' => route('app.ai.documents.destroy', [
                        'savedNotice' => $document->saved_notice_id,
                        'document' => $document->id,
                    ]),
                ];
            })
            ->values()
            ->all();
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
     * Purpose: Search chunked AI document content for a visible saved notice.
     * Inputs: The saved notice context and the trimmed search query string.
     * Returns: An ordered list of chunk-level search hits for the AI case view.
     * Side effects: None.
     */
    private function searchAiDocumentChunks(SavedNotice $notice, string $searchQuery): array
    {
        $searchQuery = trim($searchQuery);

        if ($searchQuery === '') {
            return [];
        }

        $normalizedNeedle = mb_strtolower($searchQuery, 'UTF-8');
        $escapedNeedle = addcslashes($normalizedNeedle, "\\%_");

        return SavedNoticeAiDocumentChunk::query()
            ->join('saved_notice_ai_documents', 'saved_notice_ai_documents.id', '=', 'saved_notice_ai_document_chunks.saved_notice_ai_document_id')
            ->where('saved_notice_ai_documents.saved_notice_id', $notice->id)
            ->whereRaw("LOWER(saved_notice_ai_document_chunks.content) LIKE ? ESCAPE '\\'", [
                '%'.$escapedNeedle.'%',
            ])
            ->orderByDesc('saved_notice_ai_documents.created_at')
            ->orderByDesc('saved_notice_ai_documents.id')
            ->orderBy('saved_notice_ai_document_chunks.chunk_index')
            ->get([
                'saved_notice_ai_document_chunks.id as chunk_id',
                'saved_notice_ai_document_chunks.saved_notice_ai_document_id as document_id',
                'saved_notice_ai_document_chunks.chunk_index',
                'saved_notice_ai_document_chunks.content',
                'saved_notice_ai_documents.original_filename as document_filename',
            ])
            ->map(function ($chunk) use ($searchQuery): array {
                $content = (string) $chunk->content;

                return [
                    'document_id' => (int) $chunk->document_id,
                    'document_filename' => (string) $chunk->document_filename,
                    'chunk_id' => (int) $chunk->chunk_id,
                    'chunk_index' => (int) $chunk->chunk_index,
                    'snippet' => $this->buildSearchSnippet($content, $searchQuery),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Purpose: Build a short, readable snippet around a query match in chunk text.
     * Inputs: The chunk content and the case-insensitive search query.
     * Returns: A compact snippet suitable for list rendering.
     * Side effects: None.
     */
    private function buildSearchSnippet(string $content, string $searchQuery): string
    {
        $normalizedContent = preg_replace('/\s+/u', ' ', trim($content));

        if (! is_string($normalizedContent) || $normalizedContent === '') {
            return '';
        }

        $searchQuery = trim($searchQuery);

        if ($searchQuery === '') {
            return mb_strlen($normalizedContent, 'UTF-8') <= 220
                ? $normalizedContent
                : mb_substr($normalizedContent, 0, 217, 'UTF-8').'...';
        }

        $matchPosition = mb_stripos($normalizedContent, $searchQuery, 0, 'UTF-8');

        if ($matchPosition === false) {
            return mb_strlen($normalizedContent, 'UTF-8') <= 220
                ? $normalizedContent
                : mb_substr($normalizedContent, 0, 217, 'UTF-8').'...';
        }

        $contentLength = mb_strlen($normalizedContent, 'UTF-8');
        $windowSize = 220;
        $start = max(0, $matchPosition - 90);
        $snippet = mb_substr($normalizedContent, $start, $windowSize, 'UTF-8');

        if ($start > 0) {
            $snippet = '...'.ltrim($snippet);
        }

        if ($start + $windowSize < $contentLength) {
            $snippet = rtrim($snippet).'...';
        }

        return $snippet;
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
     * Inputs: The persisted AI document and the extracted raw text.
     * Returns: None.
     * Side effects: Deletes existing chunks and recreates them from the extracted text when available.
     */
    private function syncDocumentChunks(SavedNoticeAiDocument $document, string $extractedText): void
    {
        $document->chunks()->delete();

        if (trim($extractedText) === '') {
            return;
        }

        $chunks = $this->documentChunker->chunkText($extractedText);

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
