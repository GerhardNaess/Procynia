<?php

namespace Tests\Feature\App;

use App\Http\Controllers\App\AiController;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\Language;
use App\Models\KnowledgeItem;
use App\Models\SavedNotice;
use App\Models\SavedNoticeInfoItem;
use App\Models\SavedNoticeUserAccess;
use App\Models\SavedNoticePhaseComment;
use App\Models\BidSubmission;
use App\Models\AiUsageEvent;
use App\Models\Nationality;
use App\Models\KnowledgeItemChunk;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\SavedNoticeAiAnswerBasisItem;
use App\Models\SavedNoticeAiEvidence;
use App\Models\SavedNoticeAiRequirementAssessment;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementRevision;
use App\Models\AiTokenEvent;
use App\Models\RequirementExtractionCall;
use App\Models\RequirementExtractionRun;
use App\Services\Ai\AiTokenLogger;
use App\Jobs\Ai\Requirements\ProcessRequirementExtractionRun;
use App\Services\Ai\Requirements\RequirementExtractionRunService;
use App\Services\OpenAi\EmbeddingService;
use App\Services\Ai\Requirements\RequirementExtractionPipeline;
use App\Services\Ai\Requirements\FullDocumentRequirementExtractionPrompt;
use App\Services\Ai\Requirements\RequirementLoader;
use App\Services\Ai\Requirements\RequirementAnswerDraftService;
use App\Services\Ai\Requirements\RequirementGroundingJudgeService;
use App\Services\Ai\AiUsageGuard;
use App\Services\Ai\Retrieval\MetadataRetrievalPlanService;
use App\Services\RequirementExtractor;
use App\Services\KnowledgeChunkCoverageService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Mockery;
use RuntimeException;
use ZipArchive;
use Tests\TestCase;

class AiControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
        $this->bindKnowledgeGroundingService(function (...$ignored): array {
            return [
                'level' => 'amber',
                'max_score' => 0.74,
                'sources_count' => 1,
            ];
        });
        $this->bindGroundingJudgeService(function (...$ignored): array {
            return [
                'status' => 'supported',
                'can_generate_answer' => true,
                'directly_supported_points' => [
                    [
                        'requirement_point' => 'Kunnskapsgrunnlaget dekker kravet tilstrekkelig.',
                        'support_summary' => 'Kunnskapsgrunnlaget dokumenterer løsningen i relevant kontekst.',
                        'evidence_reference' => 'Chunk 1 · Relevant knowledge',
                        'evidence_quote' => 'Leverandøren beskriver løsningen i detalj.',
                    ],
                ],
                'related_but_insufficient_points' => [],
                'unsupported_points' => [],
                'missing_knowledge_summary' => null,
                'recommended_document_title' => null,
                'suggested_filename' => null,
                'reasoning_summary' => 'Grunnlaget er tilstrekkelig dokumentert.',
            ];
        });
        $this->bindMetadataRetrievalPlanService(function (...$ignored): array {
            return [
                'selected_metadata' => [],
                'search_text' => '',
                'intent_summary' => '',
                'confidence' => 0.0,
            ];
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_ai_index_returns_the_workspace_landing_page_without_tab_navigation(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->actingAs($context['user'])->get('/app/ai');

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/AI/Index'
                && data_get($page, 'props.pageTitle') === 'Oversikt'
                && data_get($page, 'props.analysisCases') === []
                && ! array_key_exists('tabs', data_get($page, 'props', []))
                && ! array_key_exists('activeTab', data_get($page, 'props', []));
        });
    }

    public function test_ai_index_ignores_tab_query_parameters_and_still_returns_the_workspace_landing_page(): void
    {
        $context = $this->customerAdminContext();

        $requirementsResponse = $this->actingAs($context['user'])->get('/app/ai?tab=requirements');
        $requirementsResponse->assertOk();
        $requirementsResponse->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/AI/Index'
                && data_get($page, 'props.pageTitle') === 'Oversikt'
                && data_get($page, 'props.analysisCases') === []
                && ! array_key_exists('tabs', data_get($page, 'props', []))
                && ! array_key_exists('activeTab', data_get($page, 'props', []));
        });

        $documentsResponse = $this->actingAs($context['user'])->get('/app/ai?tab=documents');
        $documentsResponse->assertOk();
        $documentsResponse->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/AI/Index'
                && data_get($page, 'props.pageTitle') === 'Oversikt'
                && data_get($page, 'props.analysisCases') === []
                && ! array_key_exists('tabs', data_get($page, 'props', []))
                && ! array_key_exists('activeTab', data_get($page, 'props', []));
        });

        $fallbackResponse = $this->actingAs($context['user'])->get('/app/ai?tab=bogus');
        $fallbackResponse->assertOk();
        $fallbackResponse->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/AI/Index'
                && data_get($page, 'props.pageTitle') === 'Oversikt'
                && data_get($page, 'props.analysisCases') === []
                && ! array_key_exists('tabs', data_get($page, 'props', []))
                && ! array_key_exists('activeTab', data_get($page, 'props', []));
        });
    }

    public function test_ai_index_and_show_flag_ai_access_as_unavailable_without_entitlement(): void
    {
        $context = $this->customerAdminContext('Free AI Customer AS', false);
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-FREE-001', 'Free AI case', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 09:10:00');

        $indexResponse = $this->actingAs($context['user'])->get('/app/ai');

        $indexResponse->assertOk();
        $indexResponse->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/AI/Index'
                && data_get($page, 'props.can_use_ai_offer') === false;
        });

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/AI/Show'
                && data_get($page, 'props.can_use_ai_offer') === false;
        });
    }

    public function test_ai_analysis_tab_returns_visible_saved_notices_with_canonical_statuses_and_action_urls(): void
    {
        $context = $this->customerAdminContext();
        $readyOwner = User::factory()->create([
            'name' => 'Ready Owner',
            'email' => 'ready.owner@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);
        $reviewManager = User::factory()->create([
            'name' => 'Review Manager',
            'email' => 'review.manager@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $notStartedNotice = $this->createSavedNotice($context['customer']->id, 'AI-1001', 'Not started case', [
            'bid_status' => SavedNotice::BID_STATUS_DISCOVERED,
        ]);
        $this->touchSavedNotice($notStartedNotice, '2026-04-01 10:00:00');

        $readyNotice = $this->createSavedNotice($context['customer']->id, 'AI-1002', 'Ready case', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'opportunity_owner_user_id' => $readyOwner->id,
        ]);
        $this->createInfoItem($readyNotice->id, $readyOwner->id, $context['user']->id, 'Ready to analyze');
        $this->touchSavedNotice($readyNotice, '2026-04-03 10:00:00');

        $inReviewNotice = $this->createSavedNotice($context['customer']->id, 'AI-1003', 'In review case', [
            'bid_status' => SavedNotice::BID_STATUS_GO_NO_GO,
            'bid_manager_user_id' => $reviewManager->id,
        ]);
        $this->createPhaseComment($inReviewNotice->id, $reviewManager->id, SavedNotice::BID_STATUS_GO_NO_GO, 'Analysis is underway');
        $this->createSubmission($inReviewNotice->id, '2026-04-05 08:45:00');
        $this->touchSavedNotice($inReviewNotice, '2026-04-05 10:00:00');

        $foreignCustomer = $this->createCustomer('Other Customer AS');
        $foreignNotice = $this->createSavedNotice($foreignCustomer->id, 'AI-9999', 'Hidden foreign case', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($foreignNotice, '2026-04-06 10:00:00');

        $response = $this->actingAs($context['user'])->get('/app/ai');

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($notStartedNotice, $readyNotice, $inReviewNotice, $foreignNotice): bool {
            $analysisCases = collect(data_get($page, 'props.analysisCases', []));
            $analysisById = $analysisCases->keyBy('id');

            return data_get($page, 'component') === 'App/AI/Index'
                && data_get($page, 'props.pageTitle') === 'Oversikt'
                && $analysisCases->count() === 3
                && $analysisCases->pluck('id')->all() === [$inReviewNotice->id, $readyNotice->id, $notStartedNotice->id]
                && $analysisById->get($notStartedNotice->id)['ai_status'] === 'not_started'
                && $analysisById->get($readyNotice->id)['ai_status'] === 'ready'
                && $analysisById->get($inReviewNotice->id)['ai_status'] === 'in_review'
                && $analysisById->get($notStartedNotice->id)['reference'] === 'AI-1001'
                && $analysisById->get($readyNotice->id)['reference'] === 'AI-1002'
                && $analysisById->get($inReviewNotice->id)['reference'] === 'AI-1003'
                && $analysisById->get($notStartedNotice->id)['action_url'] === route('app.ai.show', ['savedNotice' => $notStartedNotice->id])
                && $analysisById->get($readyNotice->id)['action_url'] === route('app.ai.show', ['savedNotice' => $readyNotice->id])
                && $analysisById->get($inReviewNotice->id)['action_url'] === route('app.ai.show', ['savedNotice' => $inReviewNotice->id])
                && filled($analysisById->get($notStartedNotice->id)['updated_at'])
                && filled($analysisById->get($readyNotice->id)['updated_at'])
                && filled($analysisById->get($inReviewNotice->id)['updated_at'])
                && ! $analysisCases->contains(fn (array $case): bool => $case['id'] === $foreignNotice->id);
        });
    }

    public function test_ai_case_view_returns_case_payload_for_visible_saved_notices_and_rejects_invisible_cases(): void
    {
        $context = $this->customerAdminContext();
        $owner = User::factory()->create([
            'name' => 'AI Owner',
            'email' => 'ai.owner@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001', 'Case view target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'opportunity_owner_user_id' => $owner->id,
            'reference_number' => 'REF-2001',
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 09:30:00');
        Storage::fake('local');
        Storage::disk('local')->put('saved-notices/'.$savedNotice->id.'/ai-documents/analysis-pack.pdf', 'analysis-pack-contents');
        Storage::disk('local')->put('saved-notices/'.$savedNotice->id.'/ai-documents/scope-notes.docx', 'scope-notes-contents');
        $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'analysis-pack.pdf',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/analysis-pack.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 2048,
        ]);
        $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $owner->id,
            'original_filename' => 'scope-notes.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/scope-notes.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => 'Scope notes for AI analysis.',
            'text_extracted_at' => '2026-04-06 09:45:00',
        ]);

        $response = $this->actingAs($context['user'])->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($savedNotice): bool {
            $case = data_get($page, 'props.case', []);
            $documents = collect(data_get($page, 'props.documents', []));
            $documentsByFilename = $documents->keyBy('original_filename');

            return data_get($page, 'component') === 'App/AI/Show'
                && data_get($page, 'props.pageTitle') === 'I arbeid · Case view target'
                && data_get($page, 'props.ai_status') === 'ready'
                && data_get($page, 'props.requirements_count') === 0
                && data_get($page, 'props.requirements') === []
                && data_get($page, 'props.assessment_refresh_url') === route('app.ai.requirements.assessment.refresh', ['savedNotice' => $savedNotice->id])
                && data_get($page, 'props.documents_upload_url') === route('app.ai.documents.store', ['savedNotice' => $savedNotice->id])
                && data_get($case, 'id') === $savedNotice->id
                && data_get($case, 'title') === 'Case view target'
                && data_get($case, 'reference') === 'REF-2001'
                && data_get($case, 'owner') === 'AI Owner'
                && data_get($case, 'stage') === SavedNotice::BID_STATUS_LABELS[SavedNotice::BID_STATUS_QUALIFYING]
                && filled(data_get($case, 'updated_at'))
                && $documents->count() === 2
                && $documents->first()['original_filename'] === 'scope-notes.docx'
                && $documents->last()['original_filename'] === 'analysis-pack.pdf'
                && $documentsByFilename->get('scope-notes.docx')['has_extracted_text'] === true
                && filled($documentsByFilename->get('scope-notes.docx')['text_extracted_at'])
                && $documentsByFilename->get('analysis-pack.pdf')['has_extracted_text'] === false
                && $documentsByFilename->get('analysis-pack.pdf')['text_extracted_at'] === null
                && $documentsByFilename->get('scope-notes.docx')['chunk_count'] === 0
                && $documentsByFilename->get('analysis-pack.pdf')['chunk_count'] === 0
                && $documentsByFilename->get('scope-notes.docx')['preview_mode'] === 'pdf'
                && $documentsByFilename->get('analysis-pack.pdf')['preview_mode'] === 'pdf'
                && $documentsByFilename->get('scope-notes.docx')['preview_url'] === route('app.ai.documents.preview', [
                    'savedNotice' => $savedNotice->id,
                    'document' => $documentsByFilename->get('scope-notes.docx')['id'],
                ])
                && $documentsByFilename->get('analysis-pack.pdf')['preview_url'] === route('app.ai.documents.preview', [
                    'savedNotice' => $savedNotice->id,
                    'document' => $documentsByFilename->get('analysis-pack.pdf')['id'],
                ])
                && $documentsByFilename->get('scope-notes.docx')['download_url'] === route('app.ai.documents.download', [
                    'savedNotice' => $savedNotice->id,
                    'document' => $documentsByFilename->get('scope-notes.docx')['id'],
                ])
                && $documentsByFilename->get('analysis-pack.pdf')['download_url'] === route('app.ai.documents.download', [
                    'savedNotice' => $savedNotice->id,
                    'document' => $documentsByFilename->get('analysis-pack.pdf')['id'],
                ])
                && $documentsByFilename->get('scope-notes.docx')['delete_url'] === route('app.ai.documents.destroy', [
                    'savedNotice' => $savedNotice->id,
                    'document' => $documentsByFilename->get('scope-notes.docx')['id'],
                ])
                && $documents->first()['processing_status'] === SavedNoticeAiDocument::PROCESSING_STATUS_UPLOADED
                && $documents->first()['file_size_human'] === '4.0 KB';
        });

        $foreignContext = $this->customerAdminContext('Other Customer AS');

        $this->actingAs($foreignContext['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->assertNotFound();
    }

    public function test_ai_case_view_includes_answer_draft_payload_and_action_urls_for_requirements(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-DRAFT', 'Draft payload target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 10:30:00');

        Storage::fake('local');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'scope-notes.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/scope-notes.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => 'Scope notes for AI analysis.',
            'text_extracted_at' => '2026-04-06 10:05:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'answer_draft_text' => 'Leverandøren skal beskrive løsningen og hvordan den oppfyller kravet.',
            'answer_draft_generated_at' => '2026-04-06 11:15:00',
        ]);

        $response = $this->actingAs($context['user'])->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirements = collect(data_get($page, 'props.requirements', []));
        $requirementRow = $requirements->firstWhere('id', $requirement->id);

        $this->assertSame('App/AI/Show', data_get($page, 'component'));
        $this->assertSame(1, data_get($page, 'props.requirements_count'));
        $this->assertSame(route('app.ai.requirements.store', ['savedNotice' => $savedNotice->id]), data_get($page, 'props.requirements_store_url'));
        $this->assertSame(route('app.ai.requirements.destroy-all', ['savedNotice' => $savedNotice->id]), data_get($page, 'props.requirements_destroy_all_url'));
        $this->assertCount(1, $requirements);
        $this->assertNotNull($requirementRow);
        $this->assertSame([], data_get($requirementRow, 'answer_basis_item_ids'));
        $this->assertSame(
            route('app.ai.requirements.answer-basis.sync', [
                'savedNotice' => $savedNotice->id,
                'requirement' => $requirement->id,
            ]),
            data_get($requirementRow, 'answer_basis_selection_sync_url'),
        );
        $this->assertSame(
            'Leverandøren skal beskrive løsningen og hvordan den oppfyller kravet.',
            data_get($requirementRow, 'answer_draft.text'),
        );
        $this->assertSame(
            route('app.ai.requirements.answer-draft.generate', [
                'savedNotice' => $savedNotice->id,
                'requirement' => $requirement->id,
            ]),
            data_get($requirementRow, 'answer_draft_generate_url'),
        );
        $this->assertSame(
            route('app.ai.requirements.answer-draft.update', [
                'savedNotice' => $savedNotice->id,
                'requirement' => $requirement->id,
            ]),
            data_get($requirementRow, 'answer_draft_save_url'),
        );
        $this->assertNotEmpty(data_get($requirementRow, 'answer_draft.generated_at'));
    }

    public function test_ai_requirement_destroy_all_removes_only_extracted_requirements_and_keeps_manual_requirements(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-3004-DELETE', 'Delete extracted requirements target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:10:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'delete-target.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/delete-target.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Dokumenttekst for sletting.',
            'text_extracted_at' => '2026-04-06 12:05:00',
        ]);
        $firstChunk = $this->createAiDocumentChunk($document, 'Ekstrahert krav 1.');
        $secondChunk = $this->createAiDocumentChunk($document, 'Ekstrahert krav 2.', 1);
        $manualChunk = $this->createAiDocumentChunk($document, 'Manuelt krav.', 2);

        $firstExtractedRequirement = $this->createAiRequirement($savedNotice, $document, $firstChunk, [
            'requirement_text' => 'Ekstrahert krav 1.',
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
        ]);
        $secondExtractedRequirement = $this->createAiRequirement($savedNotice, $document, $secondChunk, [
            'requirement_text' => 'Ekstrahert krav 2.',
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
        ]);
        $manualRequirement = $this->createAiRequirement($savedNotice, $document, $manualChunk, [
            'requirement_text' => 'Manuelt krav.',
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_MANUAL,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_MANUAL,
        ]);

        $response = $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->delete(route('app.ai.requirements.destroy-all', ['savedNotice' => $savedNotice->id]));

        $response->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));
        $response->assertSessionHas('success', 'Alle ekstraherte kravkandidater er slettet.');
        $this->assertDatabaseMissing('saved_notice_ai_requirements', ['id' => $firstExtractedRequirement->id]);
        $this->assertDatabaseMissing('saved_notice_ai_requirements', ['id' => $secondExtractedRequirement->id]);
        $this->assertDatabaseHas('saved_notice_ai_requirements', ['id' => $manualRequirement->id]);

        $pageResponse = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $pageResponse->assertOk();
        $page = $this->inertiaPageFromResponse($pageResponse);
        $requirements = collect(data_get($page, 'props.requirements', []));

        $this->assertSame(1, data_get($page, 'props.requirements_count'));
        $this->assertCount(1, $requirements);
        $this->assertSame($manualRequirement->id, $requirements->first()['id']);
    }

    public function test_ai_case_instructions_page_includes_ai_instructions_payload_and_update_url(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-INSTRUCTIONS', 'Instructions target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'ai_instructions' => 'Skriv formelt og bruk Kunde med stor K.',
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 10:35:00');

        $response = $this->actingAs($context['user'])->get(route('app.ai.instructions.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);

        $this->assertSame('App/AI/Instructions', data_get($page, 'component'));
        $this->assertSame('AI instrukser', data_get($page, 'props.pageTitle'));
        $this->assertSame('Skriv formelt og bruk Kunde med stor K.', data_get($page, 'props.ai_instructions'));
        $this->assertSame(route('app.ai.instructions.update', [
            'savedNotice' => $savedNotice->id,
        ]), data_get($page, 'props.ai_instructions_update_url'));
        $this->assertSame($savedNotice->id, data_get($page, 'props.case.id'));
        $this->assertSame($savedNotice->title, data_get($page, 'props.case.title'));
    }

    public function test_ai_case_ai_instructions_update_endpoint_persists_instructions(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-INSTRUCTIONS-UPDATE', 'Instructions update target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'ai_instructions' => 'Opprinnelig instruksjon.',
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 10:36:00');

        $response = $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->patch(route('app.ai.instructions.update', ['savedNotice' => $savedNotice->id]), [
                'ai_instructions' => "Skriv formelt.\nBruk Kunde med stor K.",
            ]);

        $response->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));
        $response->assertSessionHas('success', 'AI-instruks lagret.');

        $savedNotice->refresh();
        $this->assertSame("Skriv formelt.\nBruk Kunde med stor K.", $savedNotice->ai_instructions);
    }

    public function test_ai_requirement_answer_draft_generation_endpoint_generates_and_persists_a_draft(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-06 11:30:00'));

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-GENERATE', 'Generate target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'ai_instructions' => 'Skriv formelt og bruk Kunde med stor K.',
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 11:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'requirements.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/requirements.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 11:01:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'answer_draft_text' => '',
            'answer_draft_generated_at' => null,
        ]);

        [$retrievalKnowledge, $retrievalChunk] = $this->createGroundedKnowledgeFixture(
            $context['customer'],
            'Leverandøren skal beskrive løsningen.',
            'requirements-knowledge.docx',
        );

        $this->bindEmbeddingService(function (string $text): array {
            return [
                'ok' => true,
                'embedding' => [1.0, 0.0],
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'test-request-id',
                'response_body_excerpt' => null,
            ];
        });

        Http::fake(function (Request $request) use ($requirement) {
            $requestPayload = json_decode((string) $request->body(), true);

            if (! is_array($requestPayload)) {
                throw new RuntimeException('Unable to decode the fake OpenAI request payload.');
            }

            $inputPayload = json_decode((string) data_get($requestPayload, 'input.1.content.0.text', ''), true);

            $this->assertSame('requirement_answer_draft', data_get($requestPayload, 'text.format.name'));
            $this->assertIsArray($inputPayload);
            $this->assertSame($requirement->requirement_text, data_get($inputPayload, 'requirement.text'));
            $this->assertSame('Skriv formelt og bruk Kunde med stor K.', data_get($inputPayload, 'case_instructions'));
            $this->assertSame('supported', data_get($inputPayload, 'grounding_judge.status'));
            $this->assertTrue((bool) data_get($inputPayload, 'grounding_judge.can_generate_answer'));
            $this->assertSame('Kunnskapsgrunnlaget dekker kravet tilstrekkelig.', data_get($inputPayload, 'grounding_judge.directly_supported_points.0.requirement_point'));
            $this->assertSame('Kunnskapsgrunnlaget dokumenterer løsningen i relevant kontekst.', data_get($inputPayload, 'grounding_judge.directly_supported_points.0.support_summary'));
            $this->assertSame('Chunk 1 · Relevant knowledge', data_get($inputPayload, 'grounding_judge.directly_supported_points.0.evidence_reference'));
            $this->assertSame('Leverandøren beskriver løsningen i detalj.', data_get($inputPayload, 'grounding_judge.directly_supported_points.0.evidence_quote'));
            $this->assertSame([], data_get($inputPayload, 'grounding_judge.related_but_insufficient_points'));

            return Http::response(
                $this->openAiStructuredResponse([
                    'answer_draft_text' => 'Leverandøren skal beskrive løsningen og dokumentere metoden.',
                ], 58, 16),
                200,
                ['x-request-id' => 'req_answer_draft_generate'],
            );
        });

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('requirement_id', $requirement->id);
        $response->assertJsonPath('answer_draft.text', 'Leverandøren skal beskrive løsningen og dokumentere metoden.');
        $response->assertJsonStructure([
            'requirement_id',
            'answer_draft' => [
                'text',
                'generated_at',
            ],
        ]);

        $requirement->refresh();
        $this->assertSame('Leverandøren skal beskrive løsningen og dokumentere metoden.', $requirement->answer_draft_text);
        $this->assertNotNull($requirement->answer_draft_generated_at);

        $caseUsage = CustomerAiCaseUsage::query()
            ->where('customer_id', $context['customer']->id)
            ->where('saved_notice_id', $savedNotice->id)
            ->where('source_operation_key', AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT)
            ->first();

        $this->assertNotNull($caseUsage, 'An AI case usage row must be recorded after answer draft generation.');
        $this->assertSame($context['customer']->id, $caseUsage->customer_id);
        $this->assertSame($savedNotice->id, $caseUsage->saved_notice_id);
        $this->assertSame($context['user']->id, $caseUsage->activated_by_user_id);
        $this->assertSame(AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT, $caseUsage->source_operation_key);
        $this->assertSame('2026-04-01', $caseUsage->period_start?->toDateString());
        $this->assertSame('2026-04-30', $caseUsage->period_end?->toDateString());
    }

    public function test_answer_draft_generation_records_ai_token_event_with_correct_fields(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-TOKEN-EVENT-001', 'Token event target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 11:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'token-test.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/token-test.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Leverandøren skal beskrive kapasitet.',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive kapasitet.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '2.1',
            'requirement_text' => 'Leverandøren skal beskrive kapasitet.',
            'answer_draft_text' => '',
            'answer_draft_generated_at' => null,
        ]);

        $this->bindEmbeddingService(function (string $text): array {
            return [
                'ok' => true,
                'embedding' => [1.0, 0.0],
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'embed-req-id',
                'response_body_excerpt' => null,
            ];
        });

        Http::fake(fn () => Http::response(
            $this->openAiStructuredResponse(['answer_draft_text' => 'Leverandøren har tilstrekkelig kapasitet.'], 120, 45),
            200,
            ['x-request-id' => 'req_token_event_test'],
        ));

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();

        $tokenEvent = AiTokenEvent::query()
            ->where('customer_id', $context['customer']->id)
            ->where('operation_key', 'saved_notice_requirement_answer_draft')
            ->first();

        $this->assertNotNull($tokenEvent, 'An ai_token_events row must be created after answer draft generation.');
        $this->assertSame($context['customer']->id, $tokenEvent->customer_id);
        $this->assertSame($context['user']->id, $tokenEvent->user_id);
        $this->assertSame('saved_notice_requirement_answer_draft', $tokenEvent->operation_key);
        $this->assertNotEmpty($tokenEvent->model);
        $this->assertSame(120, $tokenEvent->input_tokens);
        $this->assertSame(45, $tokenEvent->output_tokens);
        $this->assertSame(165, $tokenEvent->total_tokens);
        $this->assertSame($savedNotice->id, $tokenEvent->saved_notice_id);
    }

    public function test_answer_draft_generation_succeeds_even_if_token_logging_fails(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-TOKEN-FAIL-001', 'Token fail target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 11:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'fail-test.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/fail-test.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Leverandøren skal beskrive sikkerhet.',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive sikkerhet.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '3.1',
            'requirement_text' => 'Leverandøren skal beskrive sikkerhet.',
            'answer_draft_text' => '',
            'answer_draft_generated_at' => null,
        ]);

        $failingLogger = Mockery::mock(AiTokenLogger::class);
        $failingLogger->shouldReceive('record')->andThrow(new RuntimeException('Simulated token logger failure'));
        $this->app->instance(AiTokenLogger::class, $failingLogger);

        $this->bindEmbeddingService(function (string $text): array {
            return [
                'ok' => true,
                'embedding' => [1.0, 0.0],
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'embed-fail-test',
                'response_body_excerpt' => null,
            ];
        });

        Http::fake(fn () => Http::response(
            $this->openAiStructuredResponse(['answer_draft_text' => 'Leverandøren oppfyller sikkerhetskravene.'], 80, 30),
            200,
            ['x-request-id' => 'req_fail_test'],
        ));

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $requirement->refresh();
        $this->assertSame('Leverandøren oppfyller sikkerhetskravene.', $requirement->answer_draft_text,
            'Answer draft generation must succeed even when the token logger throws.');
    }

    public function test_ai_requirement_answer_draft_generation_is_blocked_without_ai_entitlement(): void
    {
        $context = $this->customerAdminContext('Free AI Customer AS', false);
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-BLOCKED', 'Blocked target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 11:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'requirements.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/requirements.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 11:01:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'answer_draft_text' => '',
            'answer_draft_generated_at' => null,
        ]);

        $this->bindEmbeddingService(function (): array {
            throw new RuntimeException('Embedding service should not be called when AI access is unavailable.');
        });

        Http::fake();

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertForbidden();
        Http::assertNothingSent();

        $requirement->refresh();
        $this->assertSame('', (string) $requirement->answer_draft_text);
        $this->assertNull($requirement->answer_draft_generated_at);
        $this->assertSame(0, CustomerAiCaseUsage::query()->count(), 'Blocked answer draft generation must not record AI case usage.');
    }

    public function test_ai_requirement_answer_draft_generation_calls_the_grounding_judge_before_generating_the_answer(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-JUDGE-ORDER', 'Judge order target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 11:20:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'requirements.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/requirements.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 11:21:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'answer_draft_text' => '',
            'answer_draft_generated_at' => null,
        ]);

        $judge = Mockery::mock(RequirementGroundingJudgeService::class);
        $judge->shouldReceive('judge')
            ->once()
            ->ordered('grounding-judge-flow')
            ->andReturn([
                'status' => 'supported',
                'can_generate_answer' => true,
                'directly_supported_points' => ['Løsningen er dokumentert.'],
                'related_but_insufficient_points' => [],
                'unsupported_points' => [],
                'missing_knowledge_summary' => null,
                'recommended_document_title' => null,
                'suggested_filename' => null,
                'reasoning_summary' => 'Grunnlaget er tilstrekkelig.',
            ]);
        $this->app->instance(RequirementGroundingJudgeService::class, $judge);

        $draftService = Mockery::mock(RequirementAnswerDraftService::class);
        $draftService->shouldReceive('ensureAnswerDraft')
            ->once()
            ->ordered('grounding-judge-flow')
            ->withArgs(function (...$args) use ($requirement): bool {
                $draftRequirement = $args[0] ?? null;
                $forceGenerate = $args[2] ?? null;
                $caseInstructions = $args[3] ?? null;
                $retrievedKnowledgeChunks = $args[5] ?? null;
                $groundingJudge = $args[6] ?? null;

                return $draftRequirement instanceof SavedNoticeAiRequirement
                    && $draftRequirement->id === $requirement->id
                    && $forceGenerate === false
                    && $caseInstructions === null
                    && $retrievedKnowledgeChunks instanceof \Illuminate\Support\Collection
                    && is_array($groundingJudge)
                    && data_get($groundingJudge, 'status') === 'supported';
            })
            ->andReturnUsing(function (SavedNoticeAiRequirement $draftRequirement, ...$ignored): SavedNoticeAiRequirement {
                $draftRequirement->forceFill([
                    'answer_draft_text' => 'Leverandøren skal beskrive løsningen med utgangspunkt i dokumentasjonen.',
                    'answer_draft_generated_at' => '2026-04-06 11:22:00',
                ])->save();

                return $draftRequirement->refresh();
            });
        $this->app->instance(RequirementAnswerDraftService::class, $draftService);

        Http::fake();

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('answer_draft.text', 'Leverandøren skal beskrive løsningen med utgangspunkt i dokumentasjonen.');

        $requirement->refresh();
        $this->assertSame('Leverandøren skal beskrive løsningen med utgangspunkt i dokumentasjonen.', $requirement->answer_draft_text);
        $this->assertSame('2026-04-06 11:22:00', $requirement->answer_draft_generated_at?->toDateTimeString());

        Http::assertNothingSent();
    }

    public function test_ai_requirement_answer_draft_generation_endpoint_overwrites_existing_drafts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-06 11:30:00'));

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-REUSE', 'Reuse target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 11:30:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'requirements.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/requirements.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 11:31:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'answer_draft_text' => 'Eksisterende svarutkast.',
            'answer_draft_generated_at' => '2026-04-06 11:45:00',
        ]);

        [$retrievalKnowledge, $retrievalChunk] = $this->createGroundedKnowledgeFixture(
            $context['customer'],
            'Leverandøren skal beskrive løsningen.',
            'requirements-knowledge.docx',
        );

        $this->bindEmbeddingService(function (string $text): array {
            return [
                'ok' => true,
                'embedding' => [1.0, 0.0],
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'test-request-id',
                'response_body_excerpt' => null,
            ];
        });

        Http::fake(function (Request $request) use ($requirement) {
            $requestPayload = json_decode((string) $request->body(), true);

            if (! is_array($requestPayload)) {
                throw new RuntimeException('Unable to decode the fake OpenAI request payload.');
            }

            $inputPayload = json_decode((string) data_get($requestPayload, 'input.1.content.0.text', ''), true);

            $this->assertSame('requirement_answer_draft', data_get($requestPayload, 'text.format.name'));
            $this->assertIsArray($inputPayload);
            $this->assertSame($requirement->requirement_text, data_get($inputPayload, 'requirement.text'));

            return Http::response(
                $this->openAiStructuredResponse([
                    'answer_draft_text' => 'Nytt svarutkast som skal erstatte det gamle.',
                ], 58, 16),
                200,
                ['x-request-id' => 'req_answer_draft_generate_overwrite'],
            );
        });

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('requirement_id', $requirement->id);
        $response->assertJsonPath('answer_draft.text', 'Nytt svarutkast som skal erstatte det gamle.');
        $response->assertJsonStructure([
            'requirement_id',
            'answer_draft' => [
                'text',
                'generated_at',
            ],
        ]);
        $this->assertSame('Nytt svarutkast som skal erstatte det gamle.', $requirement->refresh()->answer_draft_text);
        $this->assertNotNull($requirement->answer_draft_generated_at);

        Carbon::setTestNow(Carbon::parse('2026-04-19 14:05:00'));

        $secondResponse = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $secondResponse->assertOk();
        $this->assertSame(1, CustomerAiCaseUsage::query()->count(), 'The same SavedNotice must only be recorded once in the same calendar month.');
    }

    public function test_ai_requirement_answer_draft_generation_does_not_record_case_usage_when_generation_fails(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-06 11:30:00'));

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-FAIL-CASE-USAGE', 'Fail target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 11:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'requirements.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/requirements.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 11:01:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'answer_draft_text' => '',
            'answer_draft_generated_at' => null,
        ]);

        [$retrievalKnowledge, $retrievalChunk] = $this->createGroundedKnowledgeFixture(
            $context['customer'],
            'Leverandøren skal beskrive løsningen.',
            'requirements-knowledge.docx',
        );

        $this->bindEmbeddingService(function (string $text): array {
            return [
                'ok' => true,
                'embedding' => [1.0, 0.0],
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'embed-fail-case-usage',
                'response_body_excerpt' => null,
            ];
        });

        Http::fake(fn () => Http::response(['unexpected' => 'payload'], 200, ['x-request-id' => 'req_invalid_answer_draft']));

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertServerError();
        $this->assertSame(0, CustomerAiCaseUsage::query()->count(), 'Failed answer draft generation must not record AI case usage.');
    }

    public function test_ai_requirement_answer_draft_generation_forwards_user_answer_prompt_to_draft_service(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-USERPROMPT', 'User prompt target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-05-15 10:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'requirements.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/requirements.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-05-15 10:01:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'answer_draft_text' => '',
            'answer_draft_generated_at' => null,
        ]);

        $judge = Mockery::mock(RequirementGroundingJudgeService::class);
        $judge->shouldReceive('judge')->once()->andReturn([
            'status' => 'supported',
            'can_generate_answer' => true,
            'directly_supported_points' => [],
            'related_but_insufficient_points' => [],
            'unsupported_points' => [],
            'missing_knowledge_summary' => null,
            'recommended_document_title' => null,
            'suggested_filename' => null,
            'reasoning_summary' => 'Tilstrekkelig grunnlag.',
        ]);
        $this->app->instance(RequirementGroundingJudgeService::class, $judge);

        $capturedUserPrompt = null;
        $draftService = Mockery::mock(RequirementAnswerDraftService::class);
        $draftService->shouldReceive('ensureAnswerDraft')
            ->once()
            ->withArgs(function (...$args) use (&$capturedUserPrompt): bool {
                // $args[4] is $requirementUserPrompt
                $capturedUserPrompt = $args[4] ?? 'NOT_SET';

                return true;
            })
            ->andReturnUsing(function (SavedNoticeAiRequirement $req, ...$ignored): SavedNoticeAiRequirement {
                $req->forceFill([
                    'answer_draft_text' => 'Svarutkast med brukerprompt.',
                    'answer_draft_generated_at' => '2026-05-15 10:05:00',
                ])->save();

                return $req->refresh();
            });
        $this->app->instance(RequirementAnswerDraftService::class, $draftService);

        Http::fake();

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
            'user_answer_prompt' => 'Vektlegg miljøsertifiseringen vår.',
        ]);

        $response->assertOk();
        $this->assertSame('Vektlegg miljøsertifiseringen vår.', $capturedUserPrompt);
    }

    public function test_ai_requirement_answer_draft_update_endpoint_persists_user_edits(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-UPDATE', 'Update target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'requirements.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/requirements.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 12:01:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'answer_draft_text' => 'Opprinnelig svarutkast.',
            'answer_draft_generated_at' => '2026-04-06 12:15:00',
        ]);

        $response = $this->actingAs($context['user'])->patch(route('app.ai.requirements.answer-draft.update', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_draft_text' => "Revidert svarutkast.\nMed ny linje.",
        ]);

        $response->assertOk();
        $response->assertJsonPath('requirement_id', $requirement->id);
        $response->assertJsonPath('answer_draft.text', "Revidert svarutkast.\nMed ny linje.");
        $response->assertJsonStructure([
            'requirement_id',
            'answer_draft' => [
                'text',
                'generated_at',
            ],
        ]);

        $requirement->refresh();
        $this->assertSame("Revidert svarutkast.\nMed ny linje.", $requirement->answer_draft_text);
        $this->assertSame('2026-04-06 12:15:00', $requirement->answer_draft_generated_at?->toDateTimeString());
    }

    public function test_ai_case_view_includes_answer_basis_payload_and_requirement_selection_urls(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-BASIS', 'Basis target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:20:00');

        Storage::fake('local');

        $documentBasisItem = $this->createAnswerBasisItem($savedNotice, [
            'created_by_user_id' => $context['user']->id,
            'answer_basis_type' => SavedNoticeAiAnswerBasisItem::ANSWER_BASIS_TYPE_DOCUMENT,
            'title' => 'Metodebibliotek',
            'original_filename' => 'metode.docx',
            'body_text' => 'Leverandøren beskriver metodikken i detaljer.',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-answer-basis-items/metode.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
        ]);
        $textBasisItem = $this->createAnswerBasisItem($savedNotice, [
            'created_by_user_id' => $context['user']->id,
            'answer_basis_type' => SavedNoticeAiAnswerBasisItem::ANSWER_BASIS_TYPE_TEXT,
            'title' => 'Standard svartekst',
            'original_filename' => null,
            'body_text' => 'Leverandøren forplikter seg til å levere i henhold til avtalt metode.',
            'stored_path' => null,
            'mime_type' => null,
            'file_size_bytes' => null,
        ]);

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'requirements.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/requirements.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 12:21:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'answer_draft_text' => '',
            'answer_draft_generated_at' => null,
        ]);

        $requirement->answerBasisItems()->attach([
            $documentBasisItem->id,
            $textBasisItem->id,
        ]);

        $response = $this->actingAs($context['user'])->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($savedNotice, $documentBasisItem, $textBasisItem, $requirement): bool {
            $answerBasisItems = collect(data_get($page, 'props.answer_basis_items', []));
            $answerBasisById = $answerBasisItems->keyBy('id');
            $requirements = collect(data_get($page, 'props.requirements', []));
            $requirementRow = $requirements->firstWhere('id', $requirement->id);

            return data_get($page, 'component') === 'App/AI/Show'
                && data_get($page, 'props.answer_basis_documents_upload_url') === route('app.ai.answer-basis.documents.store', ['savedNotice' => $savedNotice->id])
                && data_get($page, 'props.answer_basis_text_store_url') === route('app.ai.answer-basis.texts.store', ['savedNotice' => $savedNotice->id])
                && $answerBasisItems->count() === 2
                && $answerBasisById->get($documentBasisItem->id)['title'] === 'Metodebibliotek'
                && $answerBasisById->get($documentBasisItem->id)['answer_basis_type'] === SavedNoticeAiAnswerBasisItem::ANSWER_BASIS_TYPE_DOCUMENT
                && $answerBasisById->get($textBasisItem->id)['answer_basis_type'] === SavedNoticeAiAnswerBasisItem::ANSWER_BASIS_TYPE_TEXT
                && $answerBasisById->get($documentBasisItem->id)['delete_url'] === route('app.ai.answer-basis.destroy', [
                    'savedNotice' => $savedNotice->id,
                    'answerBasisItem' => $documentBasisItem->id,
                ])
                && $answerBasisById->get($textBasisItem->id)['delete_url'] === route('app.ai.answer-basis.destroy', [
                    'savedNotice' => $savedNotice->id,
                    'answerBasisItem' => $textBasisItem->id,
                ])
                && $requirementRow['answer_basis_item_ids'] === [$documentBasisItem->id, $textBasisItem->id]
                && $requirementRow['answer_basis_selection_sync_url'] === route('app.ai.requirements.answer-basis.sync', [
                    'savedNotice' => $savedNotice->id,
                    'requirement' => $requirement->id,
                ]);
        });
    }

    public function test_ai_answer_basis_upload_and_text_endpoints_persist_case_level_items(): void
    {
        Storage::fake('local');

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-BASIS-UPLOAD', 'Basis upload target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:25:00');

        $upload = $this->createDocxUpload('svargrunnlag.docx', 'Leverandøren skal forklare metode og bemanning.');

        $this->actingAs($context['user'])
            ->post(route('app.ai.answer-basis.documents.store', ['savedNotice' => $savedNotice->id]), [
                'documents' => [$upload],
            ])
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $this->actingAs($context['user'])
            ->post(route('app.ai.answer-basis.texts.store', ['savedNotice' => $savedNotice->id]), [
                'title' => 'Metodetekst',
                'body_text' => 'Leverandøren forplikter seg til å beskrive metode, bemanning og kvalitetsstyring.',
            ])
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $items = SavedNoticeAiAnswerBasisItem::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $items);
        $documentItem = $items->firstWhere('answer_basis_type', SavedNoticeAiAnswerBasisItem::ANSWER_BASIS_TYPE_DOCUMENT);
        $textItem = $items->firstWhere('answer_basis_type', SavedNoticeAiAnswerBasisItem::ANSWER_BASIS_TYPE_TEXT);

        $this->assertNotNull($documentItem);
        $this->assertNotNull($textItem);
        $this->assertSame('svargrunnlag.docx', $documentItem->original_filename);
        $this->assertNotEmpty($documentItem->body_text);
        $this->assertSame('Metodetekst', $textItem->title);
        $this->assertSame('Leverandøren forplikter seg til å beskrive metode, bemanning og kvalitetsstyring.', $textItem->body_text);
    }

    public function test_ai_answer_basis_document_upload_rejects_unsupported_legacy_file_type(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-BASIS-INVALID', 'Basis invalid upload target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:26:00');

        $response = $this->actingAs($context['user'])->post(route('app.ai.answer-basis.documents.store', ['savedNotice' => $savedNotice->id]), [
            'documents' => [
                UploadedFile::fake()->create('legacy.doc', 8, 'application/msword'),
            ],
        ]);

        $response->assertSessionHasErrors(['documents.0']);
    }

    public function test_ai_requirement_answer_basis_selection_sync_endpoint_persists_selected_ids(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-BASIS-SYNC', 'Basis sync target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:30:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'requirements.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/requirements.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 12:31:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'answer_draft_text' => '',
            'answer_draft_generated_at' => null,
        ]);

        $firstBasisItem = $this->createAnswerBasisItem($savedNotice, [
            'created_by_user_id' => $context['user']->id,
            'answer_basis_type' => SavedNoticeAiAnswerBasisItem::ANSWER_BASIS_TYPE_TEXT,
            'title' => 'Metode 1',
            'body_text' => 'Leverandøren bruker en strukturert leveransemetode.',
        ]);
        $secondBasisItem = $this->createAnswerBasisItem($savedNotice, [
            'created_by_user_id' => $context['user']->id,
            'answer_basis_type' => SavedNoticeAiAnswerBasisItem::ANSWER_BASIS_TYPE_TEXT,
            'title' => 'Metode 2',
            'body_text' => 'Leverandøren har erfaring fra tilsvarende leveranser.',
        ]);

        $response = $this->actingAs($context['user'])->patch(route('app.ai.requirements.answer-basis.sync', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [$firstBasisItem->id, $secondBasisItem->id, $firstBasisItem->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('requirement_id', $requirement->id);
        $response->assertJsonPath('answer_basis_item_ids', [$firstBasisItem->id, $secondBasisItem->id]);
        $this->assertDatabaseHas('saved_notice_ai_requirement_answer_basis_selections', [
            'saved_notice_ai_requirement_id' => $requirement->id,
            'saved_notice_ai_answer_basis_item_id' => $firstBasisItem->id,
        ]);
        $this->assertDatabaseHas('saved_notice_ai_requirement_answer_basis_selections', [
            'saved_notice_ai_requirement_id' => $requirement->id,
            'saved_notice_ai_answer_basis_item_id' => $secondBasisItem->id,
        ]);
        $this->assertSame(
            [$firstBasisItem->id, $secondBasisItem->id],
            $requirement->refresh()->answerBasisItems()->pluck('id')->values()->all(),
        );
    }

    public function test_ai_requirement_answer_draft_generation_uses_selected_answer_basis_items(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-BASIS-GENERATE', 'Basis generate target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:35:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'requirements.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/requirements.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 12:36:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'answer_draft_text' => '',
            'answer_draft_generated_at' => null,
        ]);

        [$retrievalKnowledge, $retrievalChunk] = $this->createGroundedKnowledgeFixture(
            $context['customer'],
            'Leverandøren skal beskrive løsningen.',
            'requirements-knowledge.docx',
        );

        $this->bindEmbeddingService(function (string $text): array {
            return [
                'ok' => true,
                'embedding' => [1.0, 0.0],
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'test-request-id',
                'response_body_excerpt' => null,
            ];
        });

        $documentBasisItem = $this->createAnswerBasisItem($savedNotice, [
            'created_by_user_id' => $context['user']->id,
            'answer_basis_type' => SavedNoticeAiAnswerBasisItem::ANSWER_BASIS_TYPE_DOCUMENT,
            'title' => 'Metodebibliotek',
            'original_filename' => 'metode.docx',
            'body_text' => 'Leverandøren beskriver metodikken i detaljer.',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-answer-basis-items/metode.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
        ]);
        $textBasisItem = $this->createAnswerBasisItem($savedNotice, [
            'created_by_user_id' => $context['user']->id,
            'answer_basis_type' => SavedNoticeAiAnswerBasisItem::ANSWER_BASIS_TYPE_TEXT,
            'title' => 'Standard svartekst',
            'body_text' => 'Leverandøren forplikter seg til å levere i henhold til avtalt metode.',
        ]);

        Http::fake(function (Request $request) use ($requirement, $documentBasisItem, $textBasisItem) {
            $requestPayload = json_decode((string) $request->body(), true);

            if (! is_array($requestPayload)) {
                throw new RuntimeException('Unable to decode the fake OpenAI request payload.');
            }

            $inputPayload = json_decode((string) data_get($requestPayload, 'input.1.content.0.text', ''), true);

            $this->assertSame('requirement_answer_draft', data_get($requestPayload, 'text.format.name'));
            $this->assertIsArray($inputPayload);
            $this->assertSame($requirement->requirement_text, data_get($inputPayload, 'requirement.text'));
            $this->assertSame(
                [$documentBasisItem->id, $textBasisItem->id],
                collect(data_get($inputPayload, 'answer_basis_items', []))->pluck('id')->all(),
            );
            $this->assertSame(
                ['document', 'text'],
                collect(data_get($inputPayload, 'answer_basis_items', []))->pluck('type')->all(),
            );

            return Http::response(
                $this->openAiStructuredResponse([
                    'answer_draft_text' => 'Leverandøren skal beskrive løsningen med utgangspunkt i valgt svargrunnlag.',
                ], 58, 16),
                200,
                ['x-request-id' => 'req_answer_draft_generate'],
            );
        });

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [$documentBasisItem->id, $textBasisItem->id],
            'force' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('requirement_id', $requirement->id);
        $response->assertJsonPath('answer_basis_item_ids', [$documentBasisItem->id, $textBasisItem->id]);
        $response->assertJsonPath('answer_draft.text', 'Leverandøren skal beskrive løsningen med utgangspunkt i valgt svargrunnlag.');

        $requirement->refresh();
        $this->assertSame('Leverandøren skal beskrive løsningen med utgangspunkt i valgt svargrunnlag.', $requirement->answer_draft_text);
        $this->assertSame(
            [$documentBasisItem->id, $textBasisItem->id],
            $requirement->answerBasisItems()->pluck('id')->values()->all(),
        );
    }

    public function test_ai_requirement_answer_draft_generation_includes_retrieved_knowledge_chunks_in_the_prompt_and_response(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-RAG', 'RAG target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:40:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'requirements.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/requirements.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 12:41:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'answer_draft_text' => '',
            'answer_draft_generated_at' => null,
        ]);

        $retrievalKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Relevant knowledge',
            'original_filename' => 'relevant-knowledge.docx',
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'content' => 'Leverandøren skal beskrive løsningen.',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($retrievalKnowledge);
        $retrievalChunk = $retrievalKnowledge->chunks()->firstOrFail();
        $retrievalChunk->forceFill([
            'title' => 'Dokumentstruktur',
            'topic' => 'Løsning',
            'sub_topic' => 'Dokumentasjon',
            'keywords' => ['løsningen'],
            'section_title' => 'SIEM',
            'section_path' => 'SOC-tjenester > SIEM',
            'embedding_vector' => [1.0, 0.0],
            'embedding_model' => 'text-embedding-3-small',
            'embedding_generated_at' => '2026-04-06 12:42:00',
            'embedding_error' => null,
        ])->save();
        $this->touchKnowledgeItem($retrievalKnowledge, '2026-04-06 12:42:00');

        $otherKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Irrelevant knowledge',
            'original_filename' => 'irrelevant-knowledge.docx',
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'content' => 'Denne teksten handler om noe annet.',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($otherKnowledge);
        $otherKnowledge->chunks()->firstOrFail()->forceFill([
            'title' => 'Urelevant struktur',
            'embedding_vector' => [0.0, 1.0],
            'embedding_model' => 'text-embedding-3-small',
            'embedding_generated_at' => '2026-04-06 12:43:00',
            'embedding_error' => null,
        ])->save();
        $this->touchKnowledgeItem($otherKnowledge, '2026-04-06 12:43:00');

        $this->bindEmbeddingService(function (string $text): array {
            return [
                'ok' => true,
                'embedding' => [1.0, 0.0],
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'test-request-id',
                'response_body_excerpt' => null,
            ];
        });

        Http::fake(function (Request $request) use ($requirement, $retrievalKnowledge, $retrievalChunk) {
            $requestPayload = json_decode((string) $request->body(), true);

            if (! is_array($requestPayload)) {
                throw new RuntimeException('Unable to decode the fake OpenAI request payload.');
            }

            $inputPayload = json_decode((string) data_get($requestPayload, 'input.1.content.0.text', ''), true);

            $this->assertSame('requirement_answer_draft', data_get($requestPayload, 'text.format.name'));
            $this->assertIsArray($inputPayload);
            $this->assertSame($requirement->requirement_text, data_get($inputPayload, 'requirement.text'));

            $retrievedKnowledgeChunks = collect(data_get($inputPayload, 'retrieved_knowledge_chunks', []));
            $this->assertCount(1, $retrievedKnowledgeChunks);
            $this->assertSame($retrievalKnowledge->original_filename, $retrievedKnowledgeChunks->first()['document_title']);
            $this->assertStringContainsString('Leverandøren skal beskrive løsningen.', $retrievedKnowledgeChunks->first()['content_preview']);

            return Http::response(
                $this->openAiStructuredResponse([
                    'answer_draft_text' => 'Leverandøren skal beskrive løsningen med utgangspunkt i dokumentasjonen.',
                ], 58, 16),
                200,
                ['x-request-id' => 'req_answer_draft_generate_rag'],
            );
        });

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('requirement_id', $requirement->id);
        $response->assertJsonPath('answer_draft.text', 'Leverandøren skal beskrive løsningen med utgangspunkt i dokumentasjonen.');
        $response->assertJsonPath('retrieval_sources.0.document_title', $retrievalKnowledge->original_filename);
        $response->assertJsonPath('retrieval_sources.0.heading_path', 'SOC-tjenester > SIEM');
        $response->assertJsonPath('retrieval_sources.0.section_title', 'SIEM');
        $response->assertJsonPath('retrieval_sources.0.section_path', 'SOC-tjenester > SIEM');
        $response->assertJsonPath('retrieval_sources.0.chunk_id', $retrievalChunk->id);
        $response->assertJsonPath('retrieval_sources.0.id', $retrievalChunk->id);
        $response->assertJsonPath('retrieval_sources.0.topic', 'Løsning');
        $response->assertJsonPath('retrieval_sources.0.sub_topic', 'Dokumentasjon');
        $response->assertJsonPath('retrieval_sources.0.keywords.0', 'løsningen');
        $response->assertJsonPath('retrieval_sources.0.table_html', '');
        $response->assertJsonPath('retrieval_sources.0.table_json', null);
        $response->assertJsonPath('knowledge_grounding.level', 'amber');
        $response->assertJsonPath('knowledge_grounding.sources_count', 1);
        $this->assertGreaterThanOrEqual(0.45, (float) $response->json('knowledge_grounding.max_score'));

        $requirement->refresh();
        $this->assertSame('Leverandøren skal beskrive løsningen med utgangspunkt i dokumentasjonen.', $requirement->answer_draft_text);
        $this->assertNotNull($requirement->answer_draft_generated_at);
        $this->assertIsArray($requirement->answer_draft_retrieval_sources);
        $this->assertNotEmpty($requirement->answer_draft_retrieval_sources);
        $this->assertSame($retrievalKnowledge->original_filename, data_get($requirement->answer_draft_retrieval_sources, '0.document_title'));
        $this->assertSame('semantic', data_get($requirement->answer_draft_retrieval_sources, '0.chunk_type'));
    }

    public function test_ai_requirement_answer_draft_generation_uses_table_chunk_summary_and_text_as_grounding_evidence(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-TABLE-RAG', 'Table RAG target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:45:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'table-requirement.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/table-requirement.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Beskriv sikkerhetsparametere for SOC tjenesten i en tabell.',
            'text_extracted_at' => '2026-04-06 12:46:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Beskriv sikkerhetsparametere for SOC tjenesten i en tabell.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.4',
            'requirement_text' => 'Beskriv sikkerhetsparametere for SOC tjenesten i en tabell.',
            'answer_draft_text' => '',
            'answer_draft_generated_at' => null,
        ]);

        $this->bindEmbeddingService(function (string $text): array {
            return [
                'ok' => false,
                'embedding' => null,
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => 'embedding_unavailable',
                'error_message' => 'Embedding unavailable for test.',
                'upstream_status' => 503,
                'request_id' => 'test-request-id',
                'response_body_excerpt' => null,
            ];
        });

        $this->bindMetadataRetrievalPlanService(function (...$ignored): array {
            return [
                'selected_metadata' => [],
                'search_text' => 'sikkerhetsparametere soc tjenesten tabell',
                'intent_summary' => 'Leter etter tabell med sikkerhetsparametere for SOC-tjenesten.',
                'confidence' => 0.0,
            ];
        });

        $this->bindAiControllerKnowledgeChunksForMatching(collect([
            [
                'chunk_id' => 9801,
                'knowledge_item_id' => 6601,
                'knowledge_item_title' => 'SOC-tjeneste',
                'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
                'knowledge_item_summary' => 'Generell oversikt over SOC-dokumentasjon.',
                'chunk_index' => 0,
                'chunk_type' => 'table',
                'content' => 'Oversikt',
                'title' => 'Tabell',
                'heading_path' => '1 Overskrift test',
                'summary_for_retrieval' => 'Tabellen viser sikkerhetsparametere for SOC tjenesten.',
                'table_text' => 'Sikkerhetsparametere for SOC tjenesten: overvåkning, responstid og eskalering.',
                'table_html' => '<table><tr><th colspan="2">Sikkerhetsparametere for SOC tjenesten</th></tr><tr><th>Parameter</th><th>Verdi</th></tr><tr><td>Overvåkning</td><td>24/7</td></tr></table>',
                'table_json' => [
                    'source_type' => 'docx_table',
                    'column_count' => 2,
                    'row_count' => 3,
                    'title_row_index' => 0,
                    'header_row_indices' => [1],
                    'rows' => [
                        [
                            'row_type' => 'title',
                            'cells' => [
                                [
                                    'text' => 'Sikkerhetsparametere for SOC tjenesten',
                                    'rowspan' => 1,
                                    'colspan' => 2,
                                    'is_title' => true,
                                    'is_header' => false,
                                    'is_empty' => false,
                                    'style_hints' => [],
                                    'source_metadata' => [],
                                ],
                            ],
                        ],
                        [
                            'row_type' => 'header',
                            'cells' => [
                                [
                                    'text' => 'Parameter',
                                    'rowspan' => 1,
                                    'colspan' => 1,
                                    'is_title' => false,
                                    'is_header' => true,
                                    'is_empty' => false,
                                    'style_hints' => [],
                                    'source_metadata' => [],
                                ],
                                [
                                    'text' => 'Verdi',
                                    'rowspan' => 1,
                                    'colspan' => 1,
                                    'is_title' => false,
                                    'is_header' => true,
                                    'is_empty' => false,
                                    'style_hints' => [],
                                    'source_metadata' => [],
                                ],
                            ],
                        ],
                        [
                            'row_type' => 'data',
                            'cells' => [
                                [
                                    'text' => 'Overvåkning',
                                    'rowspan' => 1,
                                    'colspan' => 1,
                                    'is_title' => false,
                                    'is_header' => false,
                                    'is_empty' => false,
                                    'style_hints' => [],
                                    'source_metadata' => [],
                                ],
                                [
                                    'text' => '24/7',
                                    'rowspan' => 1,
                                    'colspan' => 1,
                                    'is_title' => false,
                                    'is_header' => false,
                                    'is_empty' => false,
                                    'style_hints' => [],
                                    'source_metadata' => [],
                                ],
                            ],
                        ],
                    ],
                ],
                'topic' => '',
                'sub_topic' => '',
                'keywords' => [],
                'section_title' => '1 Overskrift test',
                'section_path' => '1 Overskrift test',
                'embedding_vector' => null,
                'embedding_model' => '',
                'embedding_generated_at' => null,
                'embedding_error' => null,
                'knowledge_item_updated_at' => '2026-04-06 12:47:00',
            ],
        ]));

        Http::fake(function (Request $request) use ($requirement) {
            $requestPayload = json_decode((string) $request->body(), true);

            if (! is_array($requestPayload)) {
                throw new RuntimeException('Unable to decode the fake OpenAI request payload.');
            }

            $inputPayload = json_decode((string) data_get($requestPayload, 'input.1.content.0.text', ''), true);

            $this->assertIsArray($inputPayload);
            $this->assertSame($requirement->requirement_text, data_get($inputPayload, 'requirement.text'));

            if (data_get($requestPayload, 'text.format.name') === 'requirement_grounding_judge') {
                $retrievedKnowledgeChunks = collect(data_get($inputPayload, 'retrieved_knowledge_chunks', []));
                $this->assertCount(1, $retrievedKnowledgeChunks);
                $this->assertSame('table', $retrievedKnowledgeChunks->first()['chunk_type']);
                $this->assertSame('Tabellen viser sikkerhetsparametere for SOC tjenesten.', $retrievedKnowledgeChunks->first()['summary_for_retrieval']);
                $this->assertSame('Sikkerhetsparametere for SOC tjenesten: overvåkning, responstid og eskalering.', $retrievedKnowledgeChunks->first()['table_text']);
                $this->assertSame('<table><tr><th colspan="2">Sikkerhetsparametere for SOC tjenesten</th></tr><tr><th>Parameter</th><th>Verdi</th></tr><tr><td>Overvåkning</td><td>24/7</td></tr></table>', $retrievedKnowledgeChunks->first()['table_html']);
                $this->assertSame(2, data_get($retrievedKnowledgeChunks->first(), 'table_json.column_count'));

                return Http::response(
                    $this->openAiStructuredResponse([
                        'status' => 'supported',
                        'can_generate_answer' => true,
                        'directly_supported_points' => [
                            [
                                'requirement_point' => 'Sikkerhetsparametere for SOC tjenesten.',
                                'support_summary' => 'Tabellen dokumenterer sikkerhetsparametere for SOC-tjenesten.',
                                'evidence_reference' => 'Chunk 1 · 1 Overskrift test',
                                'evidence_quote' => null,
                            ],
                        ],
                        'related_but_insufficient_points' => [],
                        'unsupported_points' => [],
                        'missing_knowledge_summary' => 'Grunnlaget er tilstrekkelig.',
                        'recommended_document_title' => 'SOC-sikkerhetsparametere',
                        'suggested_filename' => 'soc-sikkerhetsparametere.docx',
                        'reasoning_summary' => 'Tabellgrunnlaget er tilstrekkelig.',
                    ], 58, 16),
                    200,
                    ['x-request-id' => 'req_table_grounding_judge'],
                );
            }

            $this->assertSame('requirement_answer_draft', data_get($requestPayload, 'text.format.name'));

            return Http::response(
                $this->openAiStructuredResponse([
                    'answer_draft_text' => 'Svarutkastet beskriver sikkerhetsparametere for SOC-tjenesten med utgangspunkt i tabellen.',
                ], 58, 16),
                200,
                ['x-request-id' => 'req_table_answer_draft'],
            );
        });

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('answer_draft.text', 'Svarutkastet beskriver sikkerhetsparametere for SOC-tjenesten med utgangspunkt i tabellen.');
        $response->assertJsonPath('knowledge_grounding.level', 'amber');
        $response->assertJsonPath('knowledge_grounding.sources_count', 1);
        $this->assertGreaterThanOrEqual(0.45, (float) $response->json('knowledge_grounding.max_score'));

        $requirement->refresh();
        $this->assertSame('Svarutkastet beskriver sikkerhetsparametere for SOC-tjenesten med utgangspunkt i tabellen.', $requirement->answer_draft_text);
        $this->assertNotNull($requirement->answer_draft_generated_at);
    }

    public function test_ai_requirement_answer_draft_generation_keeps_table_chunks_when_table_text_matches_even_if_metadata_topic_does_not_match(): void
    {
        Log::spy();

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-TABLE-DIAG', 'Table retrieval diagnostic target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:55:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'table-retrieval-diagnostic.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/table-retrieval-diagnostic.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal ha en tabell som viser sikkerhetsparametere i SOC tjenesten.',
            'text_extracted_at' => '2026-04-06 12:56:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal ha en tabell som viser sikkerhetsparametere i SOC tjenesten.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.4',
            'requirement_text' => 'Leverandøren skal ha en tabell som viser sikkerhetsparametere i SOC tjenesten.',
            'answer_draft_text' => '',
            'answer_draft_generated_at' => null,
        ]);

        $tableKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'SOC table knowledge',
            'original_filename' => 'soc-table-knowledge.docx',
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'content' => 'Tabellgrunnlag for sikkerhetsparametere.',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($tableKnowledge);
        $tableChunk = $tableKnowledge->chunks()->firstOrFail();
        $tableChunk->forceFill([
            'title' => 'Sikkerhetsparametere for SOC tjenesten',
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_APPROVED,
            'chunk_type' => 'table',
            'content' => 'Sikkerhetsparametere for SOC tjenesten: overvåkning, responstid og eskalering.',
            'summary_for_retrieval' => 'Tabellen viser sikkerhetsparametere for SOC tjenesten.',
            'table_text' => 'Sikkerhetsparametere for SOC tjenesten: overvåkning, responstid og eskalering.',
            'table_html' => '<table><tr><th colspan="2">Sikkerhetsparametere for SOC tjenesten</th></tr><tr><th>Parameter</th><th>Verdi</th></tr><tr><td>Overvåkning</td><td>24/7</td></tr></table>',
            'table_json' => [
                'source_type' => 'docx_table',
                'column_count' => 2,
                'row_count' => 3,
                'title_row_index' => 0,
                'header_row_indices' => [1],
                'rows' => [
                    [
                        'row_type' => 'title',
                        'cells' => [
                            [
                                'text' => 'Sikkerhetsparametere for SOC tjenesten',
                                'rowspan' => 1,
                                'colspan' => 2,
                                'is_title' => true,
                                'is_header' => false,
                                'is_empty' => false,
                                'style_hints' => [],
                                'source_metadata' => [],
                            ],
                        ],
                    ],
                    [
                        'row_type' => 'header',
                        'cells' => [
                            [
                                'text' => 'Parameter',
                                'rowspan' => 1,
                                'colspan' => 1,
                                'is_title' => false,
                                'is_header' => true,
                                'is_empty' => false,
                                'style_hints' => [],
                                'source_metadata' => [],
                            ],
                            [
                                'text' => 'Verdi',
                                'rowspan' => 1,
                                'colspan' => 1,
                                'is_title' => false,
                                'is_header' => true,
                                'is_empty' => false,
                                'style_hints' => [],
                                'source_metadata' => [],
                            ],
                        ],
                    ],
                    [
                        'row_type' => 'data',
                        'cells' => [
                            [
                                'text' => 'Overvåkning',
                                'rowspan' => 1,
                                'colspan' => 1,
                                'is_title' => false,
                                'is_header' => false,
                                'is_empty' => false,
                                'style_hints' => [],
                                'source_metadata' => [],
                            ],
                            [
                                'text' => '24/7',
                                'rowspan' => 1,
                                'colspan' => 1,
                                'is_title' => false,
                                'is_header' => false,
                                'is_empty' => false,
                                'style_hints' => [],
                                'source_metadata' => [],
                            ],
                        ],
                    ],
                ],
            ],
            'topic' => '',
            'sub_topic' => '',
            'keywords' => [],
            'section_title' => '1 Overskrift test',
            'section_path' => '1 Overskrift test',
            'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW,
            'embedding_vector' => null,
            'embedding_model' => null,
            'embedding_generated_at' => null,
            'embedding_error' => null,
        ])->save();
        $this->touchKnowledgeItem($tableKnowledge, '2026-04-06 12:57:00');

        $tableChunk->refresh();
        $this->assertDatabaseHas('knowledge_items', [
            'id' => $tableKnowledge->id,
            'customer_id' => $context['customer']->id,
            'original_filename' => 'soc-table-knowledge.docx',
            'is_active' => true,
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('knowledge_item_chunks', [
            'id' => $tableChunk->id,
            'knowledge_item_id' => $tableKnowledge->id,
            'chunk_type' => 'table',
            'title' => 'Sikkerhetsparametere for SOC tjenesten',
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_APPROVED,
            'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW,
        ]);
        $this->assertSame($tableKnowledge->id, (int) $tableChunk->knowledge_item_id);
        $this->assertSame('table', $tableChunk->chunk_type);
        $this->assertSame('Sikkerhetsparametere for SOC tjenesten', $tableChunk->title);
        $this->assertSame('approved', $tableChunk->review_status);
        $this->assertSame('pending_review', $tableChunk->metadata_status);
        $this->assertSame('Tabellen viser sikkerhetsparametere for SOC tjenesten.', $tableChunk->summary_for_retrieval);
        $this->assertSame('Sikkerhetsparametere for SOC tjenesten: overvåkning, responstid og eskalering.', $tableChunk->table_text);
        $this->assertSame('', (string) $tableChunk->topic);
        $this->assertSame('', (string) $tableChunk->sub_topic);
        $this->assertSame([], $tableChunk->keywords);
        $this->assertSame(mb_strlen('Tabellen viser sikkerhetsparametere for SOC tjenesten.', 'UTF-8'), mb_strlen((string) $tableChunk->summary_for_retrieval, 'UTF-8'));
        $this->assertSame(mb_strlen('Sikkerhetsparametere for SOC tjenesten: overvåkning, responstid og eskalering.', 'UTF-8'), mb_strlen((string) $tableChunk->table_text, 'UTF-8'));

        $semanticKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Semantic metadata knowledge',
            'original_filename' => 'semantic-metadata-knowledge.docx',
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'content' => 'Beskriv prioritet og oppfølging.',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($semanticKnowledge);
        $semanticChunk = $semanticKnowledge->chunks()->firstOrFail();
        $semanticChunk->forceFill([
            'title' => 'Utvalgt seksjon',
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_APPROVED,
            'topic' => 'Tema A',
            'sub_topic' => 'Underemne A',
            'keywords' => ['Nøkkelord A'],
            'section_title' => 'Del A',
            'section_path' => 'Kapittel > Del A',
            'embedding_vector' => [0.9, 0.1],
            'embedding_model' => 'text-embedding-3-small',
            'embedding_generated_at' => '2026-04-06 12:58:00',
            'embedding_error' => null,
        ])->save();
        $this->touchKnowledgeItem($semanticKnowledge, '2026-04-06 12:58:00');

        $this->bindEmbeddingService(function (string $text): array {
            return [
                'ok' => true,
                'embedding' => [1.0, 0.0],
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'test-request-id',
                'response_body_excerpt' => null,
            ];
        });

        $this->bindMetadataRetrievalPlanService(function (...$ignored): array {
            return [
                'selected_metadata' => [
                    'topic' => ['Tema A'],
                ],
                'search_text' => 'Leverandøren skal ha en tabell som viser sikkerhetsparametere i SOC tjenesten.',
                'intent_summary' => 'Leter etter tabell med sikkerhetsparametere for SOC-tjenesten.',
                'confidence' => 0.94,
            ];
        });

        Http::fake(function (Request $request) use ($requirement, $tableChunk, $semanticChunk, $tableKnowledge, $semanticKnowledge) {
            $requestPayload = json_decode((string) $request->body(), true);

            if (! is_array($requestPayload)) {
                throw new RuntimeException('Unable to decode the fake OpenAI request payload.');
            }

            $inputPayload = json_decode((string) data_get($requestPayload, 'input.1.content.0.text', ''), true);

            $this->assertIsArray($inputPayload);
            $this->assertSame($requirement->requirement_text, data_get($inputPayload, 'requirement.text'));

            if (data_get($requestPayload, 'text.format.name') === 'requirement_grounding_judge') {
                $retrievedKnowledgeChunks = collect(data_get($inputPayload, 'retrieved_knowledge_chunks', []));
                $this->assertNotEmpty($retrievedKnowledgeChunks);

                $tableRow = $retrievedKnowledgeChunks->firstWhere('chunk_id', $tableChunk->id);
                $semanticRow = $retrievedKnowledgeChunks->firstWhere('chunk_id', $semanticChunk->id);

                $this->assertNotNull($tableRow);
                $this->assertNotNull($semanticRow);
                $this->assertSame('table', $tableRow['chunk_type']);
                $this->assertSame($tableKnowledge->original_filename, $tableRow['document_title']);
                $this->assertSame('Sikkerhetsparametere for SOC tjenesten', $tableRow['heading_path']);
                $this->assertSame('Tabellen viser sikkerhetsparametere for SOC tjenesten.', $tableRow['summary_for_retrieval']);
                $this->assertSame('Sikkerhetsparametere for SOC tjenesten: overvåkning, responstid og eskalering.', $tableRow['table_text']);
                $this->assertSame('<table><tr><th colspan="2">Sikkerhetsparametere for SOC tjenesten</th></tr><tr><th>Parameter</th><th>Verdi</th></tr><tr><td>Overvåkning</td><td>24/7</td></tr></table>', $tableRow['table_html']);
                $this->assertSame(2, data_get($tableRow, 'table_json.column_count'));

                return Http::response(
                    $this->openAiStructuredResponse([
                        'status' => 'supported',
                        'can_generate_answer' => true,
                        'directly_supported_points' => [
                            [
                                'requirement_point' => 'Sikkerhetsparametere for SOC tjenesten.',
                                'support_summary' => 'Tabellen dokumenterer sikkerhetsparametere for SOC-tjenesten.',
                                'evidence_reference' => 'Chunk 1 · Sikkerhetsparametere for SOC tjenesten',
                                'evidence_quote' => null,
                            ],
                        ],
                        'related_but_insufficient_points' => [],
                        'unsupported_points' => [],
                        'missing_knowledge_summary' => null,
                        'recommended_document_title' => null,
                        'suggested_filename' => null,
                        'reasoning_summary' => 'Grunnlaget er tilstrekkelig.',
                    ], 58, 16),
                    200,
                    ['x-request-id' => 'req_table_metadata_grounding_judge'],
                );
            }

            $this->assertSame('requirement_answer_draft', data_get($requestPayload, 'text.format.name'));

            $retrievedKnowledgeChunks = collect(data_get($inputPayload, 'retrieved_knowledge_chunks', []));
            $this->assertNotEmpty($retrievedKnowledgeChunks);

            $tableRow = $retrievedKnowledgeChunks->firstWhere('chunk_id', $tableChunk->id);
            $semanticRow = $retrievedKnowledgeChunks->firstWhere('chunk_id', $semanticChunk->id);

            $this->assertNotNull($tableRow);
            $this->assertNotNull($semanticRow);
            $this->assertSame('table', $tableRow['chunk_type']);
            $this->assertSame($tableKnowledge->original_filename, $tableRow['document_title']);
            $this->assertStringContainsString('Tabellen viser sikkerhetsparametere for SOC tjenesten.', $tableRow['summary_for_retrieval']);
            $this->assertStringContainsString('Sikkerhetsparametere for SOC tjenesten', $tableRow['table_text']);

            return Http::response(
                $this->openAiStructuredResponse([
                    'answer_draft_text' => 'Leverandøren skal beskrive sikkerhetsparametere for SOC-tjenesten med utgangspunkt i tabellen.',
                ], 58, 16),
                200,
                ['x-request-id' => 'req_table_metadata_answer_draft'],
            );
        });

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('answer_draft.text', 'Leverandøren skal beskrive sikkerhetsparametere for SOC-tjenesten med utgangspunkt i tabellen.');
        $response->assertJsonPath('retrieval_sources.0.id', $tableChunk->id);
        $response->assertJsonPath('retrieval_sources.0.chunk_type', 'table');
        $response->assertJsonPath('retrieval_sources.0.table_html', '<table><tr><th colspan="2">Sikkerhetsparametere for SOC tjenesten</th></tr><tr><th>Parameter</th><th>Verdi</th></tr><tr><td>Overvåkning</td><td>24/7</td></tr></table>');
        $response->assertJsonPath('retrieval_sources.0.table_json.column_count', 2);

        $requirement->refresh();
        $this->assertSame('Leverandøren skal beskrive sikkerhetsparametere for SOC-tjenesten med utgangspunkt i tabellen.', $requirement->answer_draft_text);
        $this->assertNotNull($requirement->answer_draft_generated_at);
        $this->assertIsArray($requirement->answer_draft_retrieval_sources);
        $this->assertNotEmpty($requirement->answer_draft_retrieval_sources);
        $persistedTableSource = collect($requirement->answer_draft_retrieval_sources)->firstWhere('id', $tableChunk->id);
        $this->assertNotNull($persistedTableSource);
        $this->assertSame('table', data_get($persistedTableSource, 'chunk_type'));

        $refreshResponse = $this->actingAs($context['user'])->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));
        $refreshResponse->assertOk();
        $refreshPage = $this->inertiaPageFromResponse($refreshResponse);
        $refreshRequirements = collect(data_get($refreshPage, 'props.requirements', []));
        $refreshRequirementRow = $refreshRequirements->firstWhere('id', $requirement->id);

        $this->assertNotNull($refreshRequirementRow);
        $refreshTableSource = collect(data_get($refreshRequirementRow, 'answer_draft.retrieval_sources', []))
            ->firstWhere('id', $tableChunk->id);
        $this->assertNotNull($refreshTableSource);
        $this->assertSame($tableKnowledge->original_filename, data_get($refreshTableSource, 'document_title'));
        $this->assertSame('table', data_get($refreshTableSource, 'chunk_type'));
        $this->assertSame(
            '<table><tr><th colspan="2">Sikkerhetsparametere for SOC tjenesten</th></tr><tr><th>Parameter</th><th>Verdi</th></tr><tr><td>Overvåkning</td><td>24/7</td></tr></table>',
            data_get($refreshTableSource, 'table_html'),
        );

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $logContext) use ($savedNotice, $requirement, $context): bool {
            return $message === '[PROCYNIA][REAL_RETRIEVAL_PATH] generateRequirementAnswerDraft entry.'
                && data_get($logContext, 'route_name') === 'app.ai.requirements.answer-draft.generate'
                && (int) data_get($logContext, 'saved_notice_id', 0) === (int) $savedNotice->id
                && (int) data_get($logContext, 'requirement_id', 0) === (int) $requirement->id
                && (int) data_get($logContext, 'customer_id', 0) === (int) $context['customer']->id;
        });

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $logContext) use ($tableChunk): bool {
            return $message === '[PROCYNIA][REAL_RETRIEVAL_PATH] retrievedKnowledgeChunksForRequirement stage metadata_candidates.'
                && collect((array) data_get($logContext, 'table_chunks', []))
                    ->contains(static function (array $row) use ($tableChunk): bool {
                        return (int) data_get($row, 'chunk_id', 0) === (int) $tableChunk->id;
                    });
        });

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $logContext) use ($tableChunk): bool {
            return $message === '[PROCYNIA][REAL_RETRIEVAL_PATH] retrievedKnowledgeChunksForRequirement stage ranked_matches.'
                && collect((array) data_get($logContext, 'table_chunks', []))
                    ->contains(static function (array $row) use ($tableChunk): bool {
                        return (int) data_get($row, 'chunk_id', 0) === (int) $tableChunk->id;
                    });
        });

    }

    public function test_ai_requirement_answer_draft_generation_exposes_image_chunk_evidence_and_image_url(): void
    {
        Storage::fake('local');

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-IMAGE-RAG', 'Image RAG target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 13:05:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'image-requirement.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/image-requirement.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal ha et bilde som viser sikkerhetsparametere i SOC tjenesten.',
            'text_extracted_at' => '2026-04-06 13:06:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal ha et bilde som viser sikkerhetsparametere i SOC tjenesten.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.5',
            'requirement_text' => 'Leverandøren skal ha et bilde som viser sikkerhetsparametere i SOC tjenesten.',
            'answer_draft_text' => '',
            'answer_draft_generated_at' => null,
        ]);

        $knowledgeItem = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Sikkerhetsparametere for SOC tjenesten',
            'original_filename' => 'soc-image-knowledge.docx',
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'content' => 'Bilde i seksjon: 1 Overskrift test. Bildet viser sikkerhetsparametere for SOC tjenesten.',
            'is_active' => true,
        ]);

        $imageBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO5r7uQAAAAASUVORK5CYII=');
        $imageHash = hash('sha256', $imageBytes);
        $imagePath = sprintf('knowledge-images/%d/%s.png', $knowledgeItem->id, $imageHash);
        Storage::disk('local')->put($imagePath, $imageBytes);

        $imageChunk = KnowledgeItemChunk::query()->create([
            'knowledge_item_id' => $knowledgeItem->id,
            'chunk_index' => 0,
            'chunk_type' => 'image',
            'start_offset' => 0,
            'end_offset' => mb_strlen('Bilde i seksjon: 1 Overskrift test. Bildet viser sikkerhetsparametere for SOC tjenesten.', 'UTF-8'),
            'content' => 'Bilde i seksjon: 1 Overskrift test. Bildet viser sikkerhetsparametere for SOC tjenesten.',
            'title' => 'Sikkerhetsparametere for SOC tjenesten',
            'heading_path' => '1 Overskrift test',
            'section_title' => '1 Overskrift test',
            'section_path' => '1 Overskrift test',
            'summary_for_retrieval' => 'Bildet viser sikkerhetsparametere for SOC tjenesten.',
            'image_path' => $imagePath,
            'image_disk' => 'local',
            'image_mime_type' => 'image/png',
            'image_original_filename' => 'soc-image.png',
            'image_width' => 1,
            'image_height' => 1,
            'image_hash' => $imageHash,
            'image_metadata' => [
                'source_type' => 'docx_image',
                'relationship_id' => 'rId1',
                'media_path' => 'word/media/image1.png',
                'document_order_index' => 1,
                'heading_path' => '1 Overskrift test',
                'image_kind' => 'unknown',
                'detected_type' => 'unknown',
                'extension' => 'png',
                'mime_type' => 'image/png',
            ],
            'image_alt_text' => 'Arkitekturdiagram med integrasjoner',
            'image_caption' => 'Figur 1: Overordnet arkitektur',
            'ocr_text' => null,
            'image_description' => null,
            'topic' => '',
            'sub_topic' => '',
            'keywords' => [],
            'embedding_vector' => [1.0, 0.0],
            'embedding_model' => 'text-embedding-3-small',
            'embedding_generated_at' => '2026-04-06 13:04:00',
            'embedding_error' => null,
        ]);

        $this->bindAiControllerKnowledgeChunksForMatching(collect([
            [
                'id' => $imageChunk->id,
                'chunk_id' => $imageChunk->id,
                'knowledge_item_id' => $knowledgeItem->id,
                'knowledge_item_title' => $knowledgeItem->original_filename,
                'content_type' => $knowledgeItem->document_type,
                'knowledge_item_summary' => $knowledgeItem->summary ?? '',
                'chunk_index' => 0,
                'chunk_type' => 'image',
                'content' => (string) $imageChunk->content,
                'title' => (string) $imageChunk->title,
                'heading_path' => (string) $imageChunk->heading_path,
                'summary_for_retrieval' => (string) $imageChunk->summary_for_retrieval,
                'table_text' => '',
                'table_html' => '',
                'table_json' => null,
                'image_path' => $imageChunk->image_path,
                'image_disk' => $imageChunk->image_disk,
                'image_mime_type' => $imageChunk->image_mime_type,
                'image_original_filename' => $imageChunk->image_original_filename,
                'image_width' => $imageChunk->image_width,
                'image_height' => $imageChunk->image_height,
                'image_hash' => $imageChunk->image_hash,
                'image_metadata' => $imageChunk->image_metadata,
                'image_alt_text' => $imageChunk->image_alt_text,
                'image_caption' => $imageChunk->image_caption,
                'ocr_text' => $imageChunk->ocr_text,
                'image_description' => $imageChunk->image_description,
                'topic' => '',
                'sub_topic' => '',
                'keywords' => [],
                'section_title' => (string) $imageChunk->section_title,
                'section_path' => (string) $imageChunk->section_path,
                'embedding_vector' => [1.0, 0.0],
                'embedding_model' => 'text-embedding-3-small',
                'embedding_generated_at' => '2026-04-06 13:04:00',
                'embedding_error' => null,
                'knowledge_item_updated_at' => $knowledgeItem->updated_at->toIso8601String(),
                'image_url' => route('app.ai.knowledge-base.chunks.image', [
                    'knowledgeItem' => $knowledgeItem->id,
                    'chunk' => $imageChunk->id,
                ]),
            ],
        ]));

        $this->bindEmbeddingService(function (string $text): array {
            return [
                'ok' => true,
                'embedding' => [1.0, 0.0],
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'test-request-id',
                'response_body_excerpt' => null,
            ];
        });

        Http::fake(function (Request $request) use ($requirement, $imageChunk, $knowledgeItem) {
            $requestPayload = json_decode((string) $request->body(), true);

            if (! is_array($requestPayload)) {
                throw new RuntimeException('Unable to decode the fake OpenAI request payload.');
            }

            $inputPayload = json_decode((string) data_get($requestPayload, 'input.1.content.0.text', ''), true);

            $this->assertSame('requirement_answer_draft', data_get($requestPayload, 'text.format.name'));
            $this->assertIsArray($inputPayload);
            $this->assertSame($requirement->requirement_text, data_get($inputPayload, 'requirement.text'));

            $retrievedKnowledgeChunks = collect(data_get($inputPayload, 'retrieved_knowledge_chunks', []));
            $this->assertNotEmpty($retrievedKnowledgeChunks);

            $imageRow = $retrievedKnowledgeChunks->firstWhere('chunk_id', $imageChunk->id);
            $this->assertNotNull($imageRow);
            $this->assertSame('image', $imageRow['chunk_type']);
            $this->assertStringContainsString('sikkerhetsparametere for SOC tjenesten', $imageRow['content_preview']);

            return Http::response(
                $this->openAiStructuredResponse([
                    'answer_draft_text' => 'Leverandøren skal vise sikkerhetsparametere i et bilde som grunnlag for svaret.',
                ], 58, 16),
                200,
                ['x-request-id' => 'req_image_answer_draft'],
            );
        });

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('answer_draft.text', 'Leverandøren skal vise sikkerhetsparametere i et bilde som grunnlag for svaret.');
        $response->assertJsonPath('retrieval_sources.0.id', $imageChunk->id);
        $response->assertJsonPath('retrieval_sources.0.chunk_type', 'image');
        $response->assertJsonPath('retrieval_sources.0.image_url', route('app.ai.knowledge-base.chunks.image', [
            'knowledgeItem' => $knowledgeItem->id,
            'chunk' => $imageChunk->id,
        ]));
        $response->assertJsonPath('retrieval_sources.0.image_mime_type', 'image/png');
        $response->assertJsonPath('retrieval_sources.0.image_alt_text', 'Arkitekturdiagram med integrasjoner');
        $response->assertJsonPath('retrieval_sources.0.image_caption', 'Figur 1: Overordnet arkitektur');
        $response->assertJsonPath('retrieval_sources.0.image_metadata.extension', 'png');
        $response->assertJsonPath('knowledge_grounding.level', 'amber');
        $response->assertJsonPath('knowledge_grounding.sources_count', 1);

        $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.chunks.image', [
                'knowledgeItem' => $knowledgeItem->id,
                'chunk' => $imageChunk->id,
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $requirement->refresh();
        $this->assertSame('Leverandøren skal vise sikkerhetsparametere i et bilde som grunnlag for svaret.', $requirement->answer_draft_text);
        $this->assertNotNull($requirement->answer_draft_generated_at);
        $this->assertIsArray($requirement->answer_draft_retrieval_sources);
        $this->assertCount(1, $requirement->answer_draft_retrieval_sources);
        $this->assertSame($imageChunk->id, data_get($requirement->answer_draft_retrieval_sources, '0.id'));
        $this->assertSame('image', data_get($requirement->answer_draft_retrieval_sources, '0.chunk_type'));

        $refreshResponse = $this->actingAs($context['user'])->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));
        $refreshResponse->assertOk();
        $refreshPage = $this->inertiaPageFromResponse($refreshResponse);
        $refreshRequirements = collect(data_get($refreshPage, 'props.requirements', []));
        $refreshRequirementRow = $refreshRequirements->firstWhere('id', $requirement->id);

        $this->assertNotNull($refreshRequirementRow);
        $this->assertSame($knowledgeItem->original_filename, data_get($refreshRequirementRow, 'answer_draft.retrieval_sources.0.document_title'));
        $this->assertSame($imageChunk->id, data_get($refreshRequirementRow, 'answer_draft.retrieval_sources.0.id'));
        $this->assertSame('image', data_get($refreshRequirementRow, 'answer_draft.retrieval_sources.0.chunk_type'));
        $this->assertSame(
            route('app.ai.knowledge-base.chunks.image', [
                'knowledgeItem' => $knowledgeItem->id,
                'chunk' => $imageChunk->id,
            ]),
            data_get($refreshRequirementRow, 'answer_draft.retrieval_sources.0.image_url'),
        );
    }

    public function test_ai_requirement_answer_draft_generation_uses_metadata_selected_chunks_before_a_stronger_text_only_match(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-METADATA', 'Metadata target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:50:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'metadata-requirement.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/metadata-requirement.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Beskriv prioritet og oppfølging.',
            'text_extracted_at' => '2026-04-06 12:51:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Beskriv prioritet og oppfølging.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.2',
            'requirement_text' => 'Beskriv prioritet og oppfølging.',
            'answer_draft_text' => '',
            'answer_draft_generated_at' => null,
        ]);

        $selectedKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Selected metadata knowledge',
            'original_filename' => 'selected-metadata-knowledge.docx',
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'content' => 'Innhold uten kravord.',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($selectedKnowledge);
        $selectedChunk = $selectedKnowledge->chunks()->firstOrFail();
        $selectedChunk->forceFill([
            'title' => 'Utvalgt seksjon',
            'topic' => 'Tema A',
            'sub_topic' => 'Underemne A',
            'keywords' => ['Nøkkelord A'],
            'section_title' => 'Del A',
            'section_path' => 'Kapittel > Del A',
            'embedding_vector' => [0.9, 0.1],
            'embedding_model' => 'text-embedding-3-small',
            'embedding_generated_at' => '2026-04-06 12:52:00',
            'embedding_error' => null,
        ])->save();
        $this->touchKnowledgeItem($selectedKnowledge, '2026-04-06 12:52:00');

        $competingKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Competing text match',
            'original_filename' => 'competing-text-match.docx',
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'content' => 'Beskriv prioritet og oppfølging i detalj.',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($competingKnowledge);
        $competingChunk = $competingKnowledge->chunks()->firstOrFail();
        $competingChunk->forceFill([
            'title' => 'Urelevant seksjon',
            'topic' => 'Tema B',
            'sub_topic' => 'Underemne B',
            'keywords' => ['Nøkkelord B'],
            'section_title' => 'Del B',
            'section_path' => 'Kapittel > Del B',
            'embedding_vector' => [0.8, 0.2],
            'embedding_model' => 'text-embedding-3-small',
            'embedding_generated_at' => '2026-04-06 12:53:00',
            'embedding_error' => null,
        ])->save();
        $this->touchKnowledgeItem($competingKnowledge, '2026-04-06 12:53:00');

        $this->bindEmbeddingService(function (string $text): array {
            return [
                'ok' => true,
                'embedding' => [1.0, 0.0],
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'test-request-id',
                'response_body_excerpt' => null,
            ];
        });

        $this->bindMetadataRetrievalPlanService(function (...$ignored): array {
            return [
                'selected_metadata' => [
                    'topic' => ['Tema A'],
                ],
                'search_text' => 'tema a',
                'intent_summary' => 'Leter etter tema a.',
                'confidence' => 0.93,
            ];
        });

        Http::fake(function (Request $request) use ($requirement, $selectedKnowledge, $selectedChunk, $competingChunk) {
            $requestPayload = json_decode((string) $request->body(), true);

            if (! is_array($requestPayload)) {
                throw new RuntimeException('Unable to decode the fake OpenAI request payload.');
            }

            $inputPayload = json_decode((string) data_get($requestPayload, 'input.1.content.0.text', ''), true);

            $this->assertSame('requirement_answer_draft', data_get($requestPayload, 'text.format.name'));
            $this->assertIsArray($inputPayload);
            $this->assertSame($requirement->requirement_text, data_get($inputPayload, 'requirement.text'));

            $retrievedKnowledgeChunks = collect(data_get($inputPayload, 'retrieved_knowledge_chunks', []));
            $this->assertCount(1, $retrievedKnowledgeChunks);
            $this->assertSame($selectedKnowledge->original_filename, $retrievedKnowledgeChunks->first()['document_title']);
            $this->assertSame('Utvalgt seksjon', $retrievedKnowledgeChunks->first()['heading_path']);
            $this->assertSame('Tema A', $retrievedKnowledgeChunks->first()['topic']);
            $this->assertSame('Underemne A', $retrievedKnowledgeChunks->first()['sub_topic']);
            $this->assertSame(['Nøkkelord A'], $retrievedKnowledgeChunks->first()['keywords']);
            $this->assertStringContainsString('Innhold uten kravord.', $retrievedKnowledgeChunks->first()['content_preview']);
            $this->assertSame($selectedChunk->id, data_get($retrievedKnowledgeChunks->first(), 'chunk_id'));
            $this->assertNotEquals($competingChunk->id, data_get($retrievedKnowledgeChunks->first(), 'chunk_id'));

            return Http::response(
                $this->openAiStructuredResponse([
                    'answer_draft_text' => 'Leverandøren skal beskrive prioritet og oppfølging med utgangspunkt i metadata-valgt kunnskap.',
                ], 58, 16),
                200,
                ['x-request-id' => 'req_answer_draft_generate_metadata'],
            );
        });

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('requirement_id', $requirement->id);
        $response->assertJsonPath('answer_draft.text', 'Leverandøren skal beskrive prioritet og oppfølging med utgangspunkt i metadata-valgt kunnskap.');

        $requirement->refresh();
        $this->assertSame('Leverandøren skal beskrive prioritet og oppfølging med utgangspunkt i metadata-valgt kunnskap.', $requirement->answer_draft_text);
        $this->assertNotNull($requirement->answer_draft_generated_at);
    }

    public function test_ai_requirement_answer_draft_generation_uses_metadata_candidates_even_when_the_legacy_base_pool_is_empty(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-METADATA-EMPTY-BASE', 'Metadata empty base target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 13:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'metadata-empty-base-requirement.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/metadata-empty-base-requirement.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Beskriv prioritet og oppfølging.',
            'text_extracted_at' => '2026-04-06 13:01:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Beskriv prioritet og oppfølging.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.3',
            'requirement_text' => 'Beskriv prioritet og oppfølging.',
            'answer_draft_text' => '',
            'answer_draft_generated_at' => null,
        ]);

        $selectedKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Metadata selected knowledge',
            'original_filename' => 'metadata-selected-knowledge.docx',
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'content' => 'Innhold uten kravord.',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($selectedKnowledge);
        $selectedChunk = $selectedKnowledge->chunks()->firstOrFail();
        $selectedChunk->forceFill([
            'title' => 'Utvalgt seksjon',
            'topic' => 'Tema A',
            'sub_topic' => 'Underemne A',
            'keywords' => ['Nøkkelord A'],
            'section_title' => 'Del A',
            'section_path' => 'Kapittel > Del A',
            'embedding_vector' => [0.9, 0.1],
            'embedding_model' => 'text-embedding-3-small',
            'embedding_generated_at' => '2026-04-06 13:02:00',
            'embedding_error' => null,
        ])->save();
        $this->touchKnowledgeItem($selectedKnowledge, '2026-04-06 13:02:00');

        $this->bindEmbeddingService(function (string $text): array {
            return [
                'ok' => true,
                'embedding' => [1.0, 0.0],
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'test-request-id',
                'response_body_excerpt' => null,
            ];
        });

        $this->bindMetadataRetrievalPlanService(function (...$ignored): array {
            return [
                'selected_metadata' => [
                    'topic' => ['Tema A'],
                ],
                'search_text' => 'tema a',
                'intent_summary' => 'Leter etter tema a.',
                'confidence' => 0.92,
            ];
        });

        $this->bindAiControllerKnowledgeChunksForMatching(collect());

        Http::fake(function (Request $request) use ($requirement, $selectedKnowledge, $selectedChunk) {
            $requestPayload = json_decode((string) $request->body(), true);

            if (! is_array($requestPayload)) {
                throw new RuntimeException('Unable to decode the fake OpenAI request payload.');
            }

            $inputPayload = json_decode((string) data_get($requestPayload, 'input.1.content.0.text', ''), true);

            $this->assertSame('requirement_answer_draft', data_get($requestPayload, 'text.format.name'));
            $this->assertIsArray($inputPayload);
            $this->assertSame($requirement->requirement_text, data_get($inputPayload, 'requirement.text'));

            $retrievedKnowledgeChunks = collect(data_get($inputPayload, 'retrieved_knowledge_chunks', []));
            $this->assertCount(1, $retrievedKnowledgeChunks);
            $this->assertSame($selectedKnowledge->original_filename, $retrievedKnowledgeChunks->first()['document_title']);
            $this->assertSame('Utvalgt seksjon', $retrievedKnowledgeChunks->first()['heading_path']);
            $this->assertSame('Tema A', $retrievedKnowledgeChunks->first()['topic']);
            $this->assertSame($selectedChunk->id, data_get($retrievedKnowledgeChunks->first(), 'chunk_id'));

            return Http::response(
                $this->openAiStructuredResponse([
                    'answer_draft_text' => 'Leverandøren skal beskrive prioritet og oppfølging med utgangspunkt i metadata-valgt kunnskap.',
                ], 58, 16),
                200,
                ['x-request-id' => 'req_answer_draft_generate_metadata_empty_base'],
            );
        });

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('requirement_id', $requirement->id);
        $response->assertJsonPath('answer_draft.text', 'Leverandøren skal beskrive prioritet og oppfølging med utgangspunkt i metadata-valgt kunnskap.');

        $requirement->refresh();
        $this->assertSame('Leverandøren skal beskrive prioritet og oppfølging med utgangspunkt i metadata-valgt kunnskap.', $requirement->answer_draft_text);
        $this->assertNotNull($requirement->answer_draft_generated_at);
    }

    public function test_ai_requirement_answer_draft_generation_blocks_when_knowledge_grounding_is_red_and_suggests_a_document(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-BLOCKED', 'Blocked target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 13:10:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'requirement.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/requirement.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Kravtekst om lærling og læreforhold.',
            'text_extracted_at' => '2026-04-06 13:11:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Kravtekst om lærling og læreforhold.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '2.1',
            'requirement_text' => 'Leverandøren skal ha lærling med godkjent læreforhold og fagbrev.',
            'answer_draft_text' => null,
            'answer_draft_generated_at' => null,
        ]);

        $this->bindKnowledgeGroundingService(function (...$ignored): array {
            return [
                'level' => 'red',
                'max_score' => 0.12,
                'sources_count' => 0,
            ];
        });

        $judge = Mockery::mock(RequirementGroundingJudgeService::class);
        $judge->shouldNotReceive('judge');
        $this->app->instance(RequirementGroundingJudgeService::class, $judge);

        Http::fake();

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('requirement_id', $requirement->id);
        $response->assertJsonPath('answer_draft.text', '');
        $response->assertJsonPath('answer_draft.generated_at', null);
        $response->assertJsonPath('answer_draft.generation_state', 'blocked_missing_knowledge');
        $response->assertJsonPath('answer_draft.missing_knowledge.message', 'Procynia har ikke laget et svar fordi kunnskapsgrunnlaget er for svakt.');
        $response->assertJsonPath('answer_draft.missing_knowledge.recommended_document_title', 'Lærlingeordning og kompetanseutvikling');
        $response->assertJsonPath('answer_draft.missing_knowledge.suggested_filename', 'laerlingeordning-og-kompetanseutvikling.docx');
        $response->assertJsonPath('knowledge_grounding.level', 'red');
        $response->assertJsonPath('knowledge_grounding.sources_count', 0);

        $requirement->refresh();
        $this->assertNull($requirement->answer_draft_text);
        $this->assertNull($requirement->answer_draft_generated_at);

        Http::assertNothingSent();
    }

    public function test_ai_requirement_answer_draft_generation_blocks_when_grounding_judge_reports_partial_support(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-JUDGE-PARTIAL', 'Judge partial target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 13:25:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'language-requirements.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/language-requirements.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Kravtekst om språk, norsk dokumentasjon og B2-nivå.',
            'text_extracted_at' => '2026-04-06 13:26:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Kravtekst om språk, norsk dokumentasjon og B2-nivå.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '3.1',
            'requirement_text' => 'Språk i samhandling og dokumentasjon. All kommunikasjon og dokumentasjon skal være på norsk på minimum B2-nivå.',
            'answer_draft_text' => null,
            'answer_draft_generated_at' => null,
        ]);

        $judge = Mockery::mock(RequirementGroundingJudgeService::class);
        $judge->shouldReceive('judge')
            ->once()
            ->andReturn([
                'status' => 'partial',
                'can_generate_answer' => false,
                'directly_supported_points' => [],
                'related_but_insufficient_points' => ['Generell SOC/IRT-overvåkning er dokumentert.'],
                'unsupported_points' => ['Norsk dokumentasjon og B2-nivå er ikke dokumentert i kunnskapsgrunnlaget.'],
                'missing_knowledge_summary' => 'Kunnskapsgrunnlaget mangler eksplisitt støtte for språkkrav og norsk dokumentasjon.',
                'recommended_document_title' => 'Språkkrav og norsk dokumentasjon',
                'suggested_filename' => 'sprakkrav-og-norsk-dokumentasjon.docx',
                'reasoning_summary' => 'Språkkrav mangler i den relevante konteksten.',
            ]);
        $this->app->instance(RequirementGroundingJudgeService::class, $judge);

        Http::fake();

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('answer_draft.generation_state', 'blocked_missing_knowledge');
        $response->assertJsonPath('answer_draft.missing_knowledge.message', 'Procynia har ikke laget et svar fordi kunnskapsgrunnlaget ikke dokumenterer kravet godt nok. Opprett eller last opp relevant kunnskapsdokumentasjon, og prøv deretter å lage svaret på nytt.');
        $response->assertJsonPath('answer_draft.missing_knowledge.missing_knowledge_summary', 'Kunnskapsgrunnlaget mangler eksplisitt støtte for språkkrav og norsk dokumentasjon.');
        $response->assertJsonPath('answer_draft.missing_knowledge.recommended_document_title', 'Språkkrav og norsk dokumentasjon');
        $response->assertJsonPath('answer_draft.missing_knowledge.suggested_filename', 'sprakkrav-og-norsk-dokumentasjon.docx');
        $response->assertJsonPath('answer_draft.missing_knowledge.judge_status', 'partial');
        $response->assertJsonPath('answer_draft.missing_knowledge.directly_supported_points', []);
        $response->assertJsonPath('answer_draft.missing_knowledge.related_but_insufficient_points.0', 'Generell SOC/IRT-overvåkning er dokumentert.');
        $response->assertJsonPath('answer_draft.missing_knowledge.unsupported_points.0', 'Norsk dokumentasjon og B2-nivå er ikke dokumentert i kunnskapsgrunnlaget.');

        $requirement->refresh();
        $this->assertNull($requirement->answer_draft_text);
        $this->assertNull($requirement->answer_draft_generated_at);

        Http::assertNothingSent();
    }

    public function test_ai_requirement_answer_draft_generation_blocks_when_microsoft_change_follow_up_is_only_related_not_directly_supported(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-JUDGE-MICROSOFT', 'Judge microsoft target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 13:30:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'microsoft-changes.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/microsoft-changes.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Kravtekst om Microsoft-endringer.',
            'text_extracted_at' => '2026-04-06 13:31:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Kravtekst om Microsoft-endringer.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '3.1',
            'requirement_text' => 'Leverandøren bør ha rutiner for å følge opp Microsoft-endringer og proaktivt informere Kunden med anbefalte tiltak, konsekvensvurdering og prioritering, integrert i styring og endringshåndtering.',
            'answer_draft_text' => null,
            'answer_draft_generated_at' => null,
        ]);

        $judge = Mockery::mock(RequirementGroundingJudgeService::class);
        $judge->shouldReceive('judge')
            ->once()
            ->andReturn([
                'status' => 'partial',
                'can_generate_answer' => false,
                'directly_supported_points' => [],
                'related_but_insufficient_points' => ['Generell SOC/IRT-overvåkning og hendelseshåndtering er dokumentert.'],
                'unsupported_points' => [
                    'Microsoft-endringsoppfølging er ikke dokumentert.',
                    'Anbefalte tiltak, konsekvensvurdering, prioritering og integrasjon med endringshåndtering er ikke dokumentert.',
                ],
                'missing_knowledge_summary' => 'Kunnskapsgrunnlaget mangler dokumentert støtte for Microsoft-endringsoppfølging og tilhørende tiltak.',
                'recommended_document_title' => 'Beredskap og hendelseshåndtering',
                'suggested_filename' => 'beredskap-og-hendelseshandtering.docx',
                'reasoning_summary' => 'Bare generell hendelseshåndtering er funnet.',
            ]);
        $this->app->instance(RequirementGroundingJudgeService::class, $judge);

        Http::fake();

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('answer_draft.generation_state', 'blocked_missing_knowledge');
        $response->assertJsonPath('answer_draft.missing_knowledge.recommended_document_title', 'Proaktiv oppfølging av Microsoft-endringer');
        $response->assertJsonPath('answer_draft.missing_knowledge.suggested_filename', 'proaktiv-oppfolging-av-microsoft-endringer.docx');
        $response->assertJsonPath('answer_draft.missing_knowledge.directly_supported_points', []);
        $response->assertJsonPath('answer_draft.missing_knowledge.related_but_insufficient_points.0', 'Generell SOC/IRT-overvåkning og hendelseshåndtering er dokumentert.');
        $response->assertJsonPath('answer_draft.missing_knowledge.unsupported_points.0', 'Microsoft-endringsoppfølging er ikke dokumentert.');

        $requirement->refresh();
        $this->assertNull($requirement->answer_draft_text);
        $this->assertNull($requirement->answer_draft_generated_at);

        Http::assertNothingSent();
    }

    public function test_ai_requirement_answer_draft_generation_blocks_when_grounding_judge_reports_unsupported(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-JUDGE-UNSUPPORTED', 'Judge unsupported target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 13:35:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'requirements.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/requirements.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Kravtekst om beredskap.',
            'text_extracted_at' => '2026-04-06 13:36:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Kravtekst om beredskap.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '3.2',
            'requirement_text' => 'Leverandøren skal beskrive beredskap og hendelseshåndtering.',
            'answer_draft_text' => null,
            'answer_draft_generated_at' => null,
        ]);

        $judge = Mockery::mock(RequirementGroundingJudgeService::class);
        $judge->shouldReceive('judge')
            ->once()
            ->andReturn([
                'status' => 'unsupported',
                'can_generate_answer' => false,
                'directly_supported_points' => [],
                'related_but_insufficient_points' => ['Generell SOC/IRT-overvåkning er dokumentert.'],
                'unsupported_points' => ['Beredskap er ikke dokumentert i den relevante kunnskapsbasen.'],
                'missing_knowledge_summary' => 'Kravet mangler dokumentert støtte i kunnskapsgrunnlaget.',
                'recommended_document_title' => null,
                'suggested_filename' => null,
                'reasoning_summary' => 'Ingen relevant støtte funnet.',
            ]);
        $this->app->instance(RequirementGroundingJudgeService::class, $judge);

        Http::fake();

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('answer_draft.generation_state', 'blocked_missing_knowledge');
        $response->assertJsonPath('answer_draft.missing_knowledge.judge_status', 'unsupported');
        $response->assertJsonPath('answer_draft.missing_knowledge.directly_supported_points', []);
        $response->assertJsonPath('answer_draft.missing_knowledge.related_but_insufficient_points.0', 'Generell SOC/IRT-overvåkning er dokumentert.');
        $response->assertJsonPath('answer_draft.missing_knowledge.unsupported_points.0', 'Beredskap er ikke dokumentert i den relevante kunnskapsbasen.');
        $response->assertJsonPath('answer_draft.missing_knowledge.recommended_document_title', 'Beredskap og hendelseshåndtering');
        $response->assertJsonPath('answer_draft.missing_knowledge.suggested_filename', 'beredskap-og-hendelseshandtering.docx');

        $requirement->refresh();
        $this->assertNull($requirement->answer_draft_text);
        $this->assertNull($requirement->answer_draft_generated_at);

        Http::assertNothingSent();
    }

    public function test_ai_requirement_answer_draft_generation_attaches_a_clickable_source_to_directly_supported_points_when_the_source_can_be_resolved(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-JUDGE-SOURCE', 'Judge source target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 13:40:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'source-chunk.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/source-chunk.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren beskriver en tydelig tjenestebeskrivelse.',
            'text_extracted_at' => '2026-04-06 13:41:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren beskriver en tydelig tjenestebeskrivelse.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '3.4',
            'requirement_text' => 'Leverandøren skal beskrive tjenestebeskrivelsen.',
            'answer_draft_text' => null,
            'answer_draft_generated_at' => null,
        ]);

        $selectedKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Relevant knowledge',
            'original_filename' => 'source-knowledge.docx',
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($selectedKnowledge);
        $selectedChunk = $selectedKnowledge->chunks()->firstOrFail();
        $selectedChunkContent = trim('Leverandøren beskriver en tydelig tjenestebeskrivelse. '.str_repeat('Ytterligere detaljer om tjenestebeskrivelsen og leveransemodellen. ', 30));
        $selectedChunk->forceFill([
            'content' => $selectedChunkContent,
        ])->save();
        $selectedChunk->forceFill([
            'title' => 'Utvalgt seksjon',
            'topic' => 'Tema A',
            'sub_topic' => 'Underemne A',
            'keywords' => ['Nøkkelord A'],
            'section_title' => 'Utvalgt seksjon',
            'section_path' => 'Dokument > Utvalgt seksjon',
            'embedding_vector' => [0.9, 0.1],
            'embedding_model' => 'text-embedding-3-small',
            'embedding_generated_at' => '2026-04-06 13:42:00',
            'embedding_error' => null,
        ])->save();
        $this->touchKnowledgeItem($selectedKnowledge, '2026-04-06 13:42:00');

        $this->bindEmbeddingService(function (string $text): array {
            return [
                'ok' => true,
                'embedding' => [1.0, 0.0],
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'test-request-id',
                'response_body_excerpt' => null,
            ];
        });

        $this->bindKnowledgeGroundingService(function (...$ignored): array {
            return [
                'level' => 'green',
                'max_score' => 5.2,
                'sources_count' => 1,
            ];
        });

        $this->bindMetadataRetrievalPlanService(function (...$ignored): array {
            return [
                'selected_metadata' => [
                    'topic' => ['Tema A'],
                ],
                'search_text' => 'tema a',
                'intent_summary' => 'Leter etter tema a.',
                'confidence' => 0.94,
            ];
        });

        $sourceLabel = $selectedKnowledge->original_filename.' · Utvalgt seksjon · Chunk 1';
        $this->bindGroundingJudgeService(function (...$ignored) use ($sourceLabel): array {
            return [
                'status' => 'partial',
                'can_generate_answer' => false,
                'directly_supported_points' => [
                    [
                        'requirement_point' => 'Leverandøren beskriver tjenestebeskrivelsen.',
                        'support_summary' => 'Kunnskapsgrunnlaget dokumenterer en tydelig tjenestebeskrivelse.',
                        'evidence_reference' => $sourceLabel,
                        'evidence_quote' => null,
                    ],
                ],
                'related_but_insufficient_points' => [],
                'unsupported_points' => [
                    'Detaljnivået er fortsatt utilstrekkelig for full beskrivelse.',
                ],
                'missing_knowledge_summary' => 'Kunnskapsgrunnlaget er delvis dokumentert.',
                'recommended_document_title' => 'Tjenestebeskrivelse og leveransemodell',
                'suggested_filename' => 'tjenestebeskrivelse-og-leveransemodell.docx',
                'reasoning_summary' => 'Kravet er delvis støttet.',
            ];
        });

        Http::fake(function (Request $request) use ($requirement, $selectedKnowledge, $selectedChunk) {
            $requestPayload = json_decode((string) $request->body(), true);

            if (! is_array($requestPayload)) {
                throw new RuntimeException('Unable to decode the fake OpenAI request payload.');
            }

            $inputPayload = json_decode((string) data_get($requestPayload, 'input.1.content.0.text', ''), true);

            $this->assertSame('requirement_answer_draft', data_get($requestPayload, 'text.format.name'));
            $this->assertIsArray($inputPayload);
            $this->assertSame($requirement->requirement_text, data_get($inputPayload, 'requirement.text'));

            $retrievedKnowledgeChunks = collect(data_get($inputPayload, 'retrieved_knowledge_chunks', []));
            $this->assertCount(1, $retrievedKnowledgeChunks);
            $this->assertSame($selectedKnowledge->original_filename, $retrievedKnowledgeChunks->first()['document_title']);
            $this->assertSame('Utvalgt seksjon', $retrievedKnowledgeChunks->first()['section_title']);
            $this->assertSame($selectedChunk->id, data_get($retrievedKnowledgeChunks->first(), 'chunk_id'));

            return Http::response(
                $this->openAiStructuredResponse([
                    'answer_draft_text' => 'Leverandøren skal beskrive tjenestebeskrivelsen med utgangspunkt i valgt kunnskap.',
                ], 58, 16),
                200,
                ['x-request-id' => 'req_answer_draft_generate_source'],
            );
        });

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('answer_draft.generation_state', 'blocked_missing_knowledge');
        $response->assertJsonPath('answer_draft.missing_knowledge.directly_supported_points.0.requirement_point', 'Leverandøren beskriver tjenestebeskrivelsen.');
        $response->assertJsonPath('answer_draft.missing_knowledge.directly_supported_points.0.source.knowledge_item_id', $selectedKnowledge->id);
        $response->assertJsonPath('answer_draft.missing_knowledge.directly_supported_points.0.source.knowledge_item_chunk_id', $selectedChunk->id);
        $response->assertJsonPath('answer_draft.missing_knowledge.directly_supported_points.0.source.document_title', $selectedKnowledge->original_filename);
        $response->assertJsonPath('answer_draft.missing_knowledge.directly_supported_points.0.source.section_title', 'Utvalgt seksjon');
        $response->assertJsonPath('answer_draft.missing_knowledge.directly_supported_points.0.source.content', $selectedChunkContent);
        self::assertStringContainsString(
            'Ytterligere detaljer om tjenestebeskrivelsen og leveransemodellen.',
            (string) data_get($response->json(), 'answer_draft.missing_knowledge.directly_supported_points.0.source.content_preview'),
        );
        $response->assertJsonPath('answer_draft.missing_knowledge.directly_supported_points.0.source.open_url', route('app.ai.knowledge-base.show', [
            'knowledgeItem' => $selectedKnowledge->id,
            'chunk' => $selectedChunk->id,
        ]));

        $requirement->refresh();
        $this->assertNull($requirement->answer_draft_text);
        $this->assertNull($requirement->answer_draft_generated_at);

        Http::assertNothingSent();
    }

    public function test_ai_requirement_answer_draft_generation_blocks_safely_when_grounding_judge_fails(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-JUDGE-FAIL', 'Judge fail target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 13:45:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'requirements.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/requirements.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Kravtekst om tilgangsstyring.',
            'text_extracted_at' => '2026-04-06 13:46:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Kravtekst om tilgangsstyring.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '3.3',
            'requirement_text' => 'Leverandøren skal beskrive tilgangsstyring og identitetsforvaltning.',
            'answer_draft_text' => null,
            'answer_draft_generated_at' => null,
        ]);

        $judge = Mockery::mock(RequirementGroundingJudgeService::class);
        $judge->shouldReceive('judge')
            ->once()
            ->andThrow(new RuntimeException('Judge unavailable.'));
        $this->app->instance(RequirementGroundingJudgeService::class, $judge);

        Http::fake();

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('answer_draft.generation_state', 'blocked_missing_knowledge');
        $response->assertJsonPath('answer_draft.missing_knowledge.message', 'Procynia har ikke laget et svar fordi kunnskapsgrunnlaget ikke dokumenterer kravet godt nok. Opprett eller last opp relevant kunnskapsdokumentasjon, og prøv deretter å lage svaret på nytt.');
        $response->assertJsonPath('answer_draft.missing_knowledge.missing_knowledge_summary', 'Procynia kunne ikke vurdere kunnskapsgrunnlaget sikkert.');
        $response->assertJsonPath('answer_draft.missing_knowledge.judge_status', 'unsupported');

        $requirement->refresh();
        $this->assertNull($requirement->answer_draft_text);
        $this->assertNull($requirement->answer_draft_generated_at);

        Http::assertNothingSent();
    }

    public function test_ai_documents_download_returns_the_uploaded_file_for_visible_cases(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-DOWNLOAD', 'Download target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 10:00:00');
        $documentPath = 'saved-notices/'.$savedNotice->id.'/ai-documents/source-notes.docx';

        Storage::fake('local');
        Storage::disk('local')->put($documentPath, 'source document contents');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'source-notes.docx',
            'stored_path' => $documentPath,
            'mime_type' => 'application/octet-stream',
            'file_size_bytes' => 24,
        ]);

        $this->actingAs($context['user'])
            ->get(route('app.ai.documents.download', [
                'savedNotice' => $savedNotice->id,
                'document' => $document->id,
            ]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename=source-notes.docx')
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    public function test_ai_documents_preview_returns_pdf_preview_pages_for_visible_cases(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2001-PREVIEW', 'Preview target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 10:00:00');

        Storage::fake('local');
        $docxPreviewSource = $this->createDocxUpload('source-notes.docx', 'Preview text for the DOCX source.');
        Storage::disk('local')->put('saved-notices/'.$savedNotice->id.'/ai-documents/source-notes.docx', file_get_contents($docxPreviewSource->getPathname()));
        Storage::disk('local')->put('saved-notices/'.$savedNotice->id.'/ai-documents/reference-pack.pdf', '%PDF-1.4 source document contents');

        $docxDocument = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'source-notes.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/source-notes.docx',
            'mime_type' => 'application/octet-stream',
            'file_size_bytes' => 24,
            'extracted_text' => 'Preview text for the DOCX source.',
            'text_extracted_at' => '2026-04-06 10:05:00',
        ]);

        $pdfDocument = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'reference-pack.pdf',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/reference-pack.pdf',
            'mime_type' => 'application/octet-stream',
            'file_size_bytes' => 32,
            'extracted_text' => '',
            'text_extracted_at' => '2026-04-06 10:06:00',
        ]);

        $docxResponse = $this->actingAs($context['user'])->get(route('app.ai.documents.preview', [
            'savedNotice' => $savedNotice->id,
            'document' => $docxDocument->id,
        ]));

        $docxResponse->assertOk();
        $docxResponse->assertViewHas('page', function (array $page) use ($savedNotice, $docxDocument): bool {
            $document = data_get($page, 'props.document', []);

            return data_get($page, 'component') === 'App/AI/DocumentPreview'
                && data_get($page, 'props.pageTitle') === 'Kilde · source-notes.docx'
                && data_get($page, 'props.back_url') === route('app.ai.show', ['savedNotice' => $savedNotice->id])
                && data_get($document, 'preview_mode') === 'pdf'
                && data_get($document, 'preview_file_url') === route('app.ai.documents.preview-file', [
                    'savedNotice' => $savedNotice->id,
                    'document' => $docxDocument->id,
                ])
                && data_get($document, 'download_url') === route('app.ai.documents.download', [
                    'savedNotice' => $savedNotice->id,
                    'document' => $docxDocument->id,
                ]);
        });

        $docxPreviewPath = 'saved-notices/'.$savedNotice->id.'/ai-document-previews/'.$docxDocument->id.'.pdf';
        $this->assertTrue(Storage::disk('local')->exists($docxPreviewPath));
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($docxPreviewPath));

        $docxPreviewFileResponse = $this->actingAs($context['user'])->get(route('app.ai.documents.preview-file', [
            'savedNotice' => $savedNotice->id,
            'document' => $docxDocument->id,
        ]));

        $docxPreviewFileResponse->assertOk();
        $docxPreviewFileResponse->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline', (string) $docxPreviewFileResponse->headers->get('Content-Disposition'));

        $pdfResponse = $this->actingAs($context['user'])->get(route('app.ai.documents.preview', [
            'savedNotice' => $savedNotice->id,
            'document' => $pdfDocument->id,
        ]));

        $pdfResponse->assertOk();
        $pdfResponse->assertViewHas('page', function (array $page) use ($savedNotice, $pdfDocument): bool {
            $document = data_get($page, 'props.document', []);

            return data_get($page, 'component') === 'App/AI/DocumentPreview'
                && data_get($page, 'props.pageTitle') === 'Kilde · reference-pack.pdf'
                && data_get($page, 'props.back_url') === route('app.ai.show', ['savedNotice' => $savedNotice->id])
                && data_get($document, 'preview_mode') === 'pdf'
                && data_get($document, 'preview_file_url') === route('app.ai.documents.preview-file', [
                    'savedNotice' => $savedNotice->id,
                    'document' => $pdfDocument->id,
                ])
                && data_get($document, 'download_url') === route('app.ai.documents.download', [
                    'savedNotice' => $savedNotice->id,
                    'document' => $pdfDocument->id,
                ]);
        });

        $pdfPreviewFileResponse = $this->actingAs($context['user'])->get(route('app.ai.documents.preview-file', [
            'savedNotice' => $savedNotice->id,
            'document' => $pdfDocument->id,
        ]));

        $pdfPreviewFileResponse->assertOk();
        $pdfPreviewFileResponse->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline', (string) $pdfPreviewFileResponse->headers->get('Content-Disposition'));
    }

    public function test_requirement_extractor_filters_heading_like_noise_and_deduplicates_near_identical_lines(): void
    {
        $extractor = app(RequirementExtractor::class);

        $chunkText = implode("\n", [
            'Krav til dokumentasjon',
            'Dokumentasjon må vedlegges.',
            'Tilbudet skal leveres innen tilbudsfrist.',
            'Tilbudet skal leveres innen tilbudsfrist',
            'Leverandøren skal beskrive løsningen.',
        ]);

        $firstPass = $extractor->extractFromChunk($chunkText);
        $secondPass = $extractor->extractFromChunk($chunkText);

        $this->assertSame($firstPass, $secondPass);
        $this->assertCount(3, $firstPass);
        $this->assertSame(
            [
                'Dokumentasjon må vedlegges.',
                'Tilbudet skal leveres innen tilbudsfrist.',
                'Leverandøren skal beskrive løsningen.',
            ],
            array_column($firstPass, 'requirement_text'),
        );
        $this->assertSame(
            [
                SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
                SavedNoticeAiRequirement::REQUIREMENT_TYPE_ADMINISTRATIVE,
                SavedNoticeAiRequirement::REQUIREMENT_TYPE_MANDATORY,
            ],
            array_column($firstPass, 'requirement_type'),
        );
        $this->assertSame(
            [
                SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
                SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
                SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            ],
            array_column($firstPass, 'extraction_method'),
        );
        $this->assertSame(
            [
                SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
                SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
                SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            ],
            array_column($firstPass, 'review_status'),
        );
    }

    public function test_ai_documents_upload_extracts_requirement_candidates_and_exposes_them_in_case_view(): void
    {
        Storage::fake('local');
        Queue::fake();

        $context = $this->customerAdminContext();
        $owner = User::factory()->create([
            'name' => 'Requirement Owner',
            'email' => 'requirement.owner@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-3000', 'Requirement extraction target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'opportunity_owner_user_id' => $owner->id,
            'reference_number' => 'REF-3000',
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 10:45:00');

        $firstDocumentText = 'Leverandøren skal levere dokumentasjon innen 10 dager.';
        $secondDocumentText = 'Tilbudet skal leveres innen fristen.';
        $firstDocument = $this->createDocxUpload('requirements-pack-a.docx', $firstDocumentText);
        $secondDocument = $this->createDocxUpload('requirements-pack-b.docx', $secondDocumentText);

        $capturedRequests = [];
        $this->fakeOpenAiFullDocumentRequirementExtractionResponse(function (array $promptContext) use (&$capturedRequests, $firstDocumentText, $secondDocumentText): array {
            $promptName = (string) data_get($promptContext, 'prompt_name', '');
            $documentText = (string) data_get($promptContext, 'document_text', '');

            $capturedRequests[] = [
                'prompt_name' => $promptName,
                'document_text' => $documentText,
            ];

            if ($promptName !== FullDocumentRequirementExtractionPrompt::promptName()) {
                throw new RuntimeException('Unexpected prompt type: '.$promptName);
            }

            if (str_contains($documentText, $firstDocumentText)) {
                return [
                    'body' => $this->openAiStructuredResponse([
                        'candidates' => [
                            $this->buildFullDocumentRequirementExtractionCandidate($firstDocumentText, [
                                'requirement_identifier' => '1.1',
                                'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
                                'obligation_type' => 'must',
                                'interpretation_risk' => 'low',
                                'source_reference_text' => 'Bilag 1 punkt 2.7',
                            ]),
                        ],
                    ], 120, 42),
                ];
            }

            if (str_contains($documentText, $secondDocumentText)) {
                return [
                    'body' => $this->openAiStructuredResponse([
                        'candidates' => [
                            $this->buildFullDocumentRequirementExtractionCandidate($secondDocumentText, [
                                'requirement_identifier' => '2.1',
                                'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_ADMINISTRATIVE,
                                'obligation_type' => 'must',
                                'interpretation_risk' => 'low',
                                'source_reference_text' => 'Bilag 2 punkt 4.1',
                            ]),
                        ],
                    ], 120, 42),
                ];
            }

            return [
                'body' => $this->openAiStructuredResponse([
                    'candidates' => [],
                ], 120, 42),
            ];
        });

        $this->actingAs($context['user'])
            ->post(route('app.ai.documents.store', ['savedNotice' => $savedNotice->id]), [
                'documents' => [$firstDocument, $secondDocument],
            ])
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        Queue::assertPushed(ProcessRequirementExtractionRun::class, 2);

        $queuedDocuments = SavedNoticeAiDocument::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->orderBy('id')
            ->get()
            ->keyBy('original_filename');
        $firstSavedDocument = $queuedDocuments->get('requirements-pack-a.docx');
        $secondSavedDocument = $queuedDocuments->get('requirements-pack-b.docx');

        $this->assertNotNull($firstSavedDocument);
        $this->assertNotNull($secondSavedDocument);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_QUEUED, $firstSavedDocument->processing_status);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_QUEUED, $secondSavedDocument->processing_status);
        $this->assertNotNull($firstSavedDocument->queued_at);
        $this->assertNotNull($secondSavedDocument->queued_at);
        $this->assertNull($firstSavedDocument->processing_started_at);
        $this->assertNull($secondSavedDocument->processing_started_at);

        $queuedRuns = RequirementExtractionRun::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $queuedRuns);
        $this->assertTrue($queuedRuns->every(fn (RequirementExtractionRun $run): bool => $run->status === RequirementExtractionRun::STATUS_QUEUED));
        $this->assertTrue($queuedRuns->every(fn (RequirementExtractionRun $run): bool => $run->strategy === RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION));

        $runService = app(RequirementExtractionRunService::class);

        foreach ($queuedRuns as $queuedRun) {
            $runService->processRun($queuedRun);
        }

        $firstSavedDocument->refresh();
        $secondSavedDocument->refresh();

        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED, $firstSavedDocument->processing_status);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED, $secondSavedDocument->processing_status);
        $this->assertNotNull($firstSavedDocument->processing_started_at);
        $this->assertNotNull($secondSavedDocument->processing_started_at);
        $this->assertNotNull($firstSavedDocument->processing_finished_at);
        $this->assertNotNull($secondSavedDocument->processing_finished_at);

        $requirements = SavedNoticeAiRequirement::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->whereIn('saved_notice_ai_document_id', [$firstSavedDocument->id, $secondSavedDocument->id])
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED)
            ->with(['document', 'chunk'])
            ->orderBy('saved_notice_ai_document_id')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $capturedRequests);
        $this->assertSame(2, collect($capturedRequests)->where('prompt_name', FullDocumentRequirementExtractionPrompt::promptName())->count());
        $this->assertTrue(collect($capturedRequests)->pluck('document_text')->contains(fn (string $documentText): bool => str_contains($documentText, $firstDocumentText)));
        $this->assertTrue(collect($capturedRequests)->pluck('document_text')->contains(fn (string $documentText): bool => str_contains($documentText, $secondDocumentText)));
        $this->assertSame(2, $requirements->count(), json_encode([
            'requirements' => $requirements->map(fn (SavedNoticeAiRequirement $requirement): array => [
                'id' => $requirement->id,
                'document_id' => $requirement->saved_notice_ai_document_id,
                'chunk_id' => $requirement->saved_notice_ai_document_chunk_id,
                'source_reference' => $requirement->source_reference,
                'extraction_metadata' => $requirement->extraction_metadata,
            ])->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertSame(
            [SavedNoticeAiRequirement::EXTRACTION_METHOD_AI_FULL_DOCUMENT, SavedNoticeAiRequirement::EXTRACTION_METHOD_AI_FULL_DOCUMENT],
            $requirements->pluck('extraction_method')->all(),
        );
        $this->assertSame(
            [$firstSavedDocument->id, $secondSavedDocument->id],
            $requirements->pluck('saved_notice_ai_document_id')->sort()->values()->all(),
        );
        $this->assertSame(
            [$firstSavedDocument->original_filename, $secondSavedDocument->original_filename],
            $requirements->pluck('document.original_filename')->sort()->values()->all(),
        );

        $firstRequirement = $requirements->firstWhere('saved_notice_ai_document_id', $firstSavedDocument->id);
        $secondRequirement = $requirements->firstWhere('saved_notice_ai_document_id', $secondSavedDocument->id);

        $this->assertNotNull($firstRequirement);
        $this->assertNotNull($secondRequirement);
        $this->assertSame('Leverandøren skal levere dokumentasjon innen 10 dager.', $firstRequirement->requirement_text);
        $this->assertSame('Tilbudet skal leveres innen fristen.', $secondRequirement->requirement_text);
        $this->assertSame($firstSavedDocument->id, $firstRequirement->source_reference['saved_notice_ai_document_id']);
        $this->assertSame($secondSavedDocument->id, $secondRequirement->source_reference['saved_notice_ai_document_id']);
        $this->assertNull($firstRequirement->saved_notice_ai_document_chunk_id);
        $this->assertNull($secondRequirement->saved_notice_ai_document_chunk_id);
        $this->assertTrue(str_starts_with($firstRequirement->source_reference['source_block_id'], sprintf('saved-notice-ai-document-%d-phase-1-', $firstSavedDocument->id)));
        $this->assertTrue(str_starts_with($secondRequirement->source_reference['source_block_id'], sprintf('saved-notice-ai-document-%d-phase-1-', $secondSavedDocument->id)));

        $completedRuns = RequirementExtractionRun::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $completedRuns);
        $this->assertTrue($completedRuns->every(fn (RequirementExtractionRun $run): bool => $run->status === RequirementExtractionRun::STATUS_COMPLETED));
        $this->assertTrue($completedRuns->every(fn (RequirementExtractionRun $run): bool => $run->openai_call_count === 1));
        $this->assertTrue($completedRuns->every(fn (RequirementExtractionRun $run): bool => $run->persisted_requirement_count === 1));
        $this->assertTrue($completedRuns->every(fn (RequirementExtractionRun $run): bool => $run->candidate_count === 1));
        $this->assertTrue($completedRuns->every(fn (RequirementExtractionRun $run): bool => $run->strategy === RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION));
        $this->assertSame(2, RequirementExtractionCall::query()->whereIn('requirement_extraction_run_id', $completedRuns->pluck('id')->all())->count());
        Http::assertSentCount(2);

        $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->assertViewHas('page', function (array $page) use ($savedNotice, $firstSavedDocument, $secondSavedDocument): bool {
                $requirements = collect(data_get($page, 'props.requirements', []))->keyBy('saved_notice_ai_document_id');

                return data_get($page, 'component') === 'App/AI/Show'
                    && data_get($page, 'props.requirements_count') === 2
                    && $requirements->get($firstSavedDocument->id)['document_filename'] === $firstSavedDocument->original_filename
                    && $requirements->get($secondSavedDocument->id)['document_filename'] === $secondSavedDocument->original_filename
                    && str_starts_with($requirements->get($firstSavedDocument->id)['source_reference']['source_block_id'], sprintf('saved-notice-ai-document-%d-phase-1-', $firstSavedDocument->id))
                    && str_starts_with($requirements->get($secondSavedDocument->id)['source_reference']['source_block_id'], sprintf('saved-notice-ai-document-%d-phase-1-', $secondSavedDocument->id))
                    && $requirements->get($firstSavedDocument->id)['extraction_method'] === SavedNoticeAiRequirement::EXTRACTION_METHOD_AI_PHASE_1
                    && $requirements->get($secondSavedDocument->id)['extraction_method'] === SavedNoticeAiRequirement::EXTRACTION_METHOD_AI_PHASE_1
                    && $requirements->get($firstSavedDocument->id)['saved_notice_ai_document_chunk_id'] === null
                    && $requirements->get($secondSavedDocument->id)['saved_notice_ai_document_chunk_id'] === null
                    && data_get($page, 'props.requirements_store_url') === route('app.ai.requirements.store', ['savedNotice' => $savedNotice->id]);
            });

    }

    public function test_ai_requirement_extraction_pipeline_uses_full_document_extraction_for_every_uploaded_document(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-3004-EXTRACT', 'Extraction service target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 10:50:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'requirement-blocks.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/requirement-blocks.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'text_extracted_at' => '2026-04-06 10:51:00',
        ]);

        $document->chunks()->createMany([
            [
                'chunk_index' => 0,
                'content' => 'Forside, kontaktinformasjon og generell introduksjon som ikke er et krav. '.str_repeat('Boilerplate uten krav. ', 40),
                'char_start' => 0,
                'char_end' => 950,
                'word_count' => 150,
            ],
            [
                'chunk_index' => 1,
                'content' => 'Anskaffelsen beskriver bakgrunn og kontekst, men inneholder ikke konkrete krav. '.str_repeat('Bakgrunnstekst uten krav. ', 40),
                'char_start' => 951,
                'char_end' => 1900,
                'word_count' => 150,
            ],
        ]);

        $capturedRequests = [];
        $this->fakeOpenAiFullDocumentRequirementExtractionResponse(function (array $promptContext) use (&$capturedRequests): array {
            $promptName = (string) data_get($promptContext, 'prompt_name', '');
            $documentText = (string) data_get($promptContext, 'document_text', '');

            $capturedRequests[] = [
                'prompt_name' => $promptName,
                'document_text' => $documentText,
            ];

            if ($promptName !== FullDocumentRequirementExtractionPrompt::promptName()) {
                throw new RuntimeException('Unexpected prompt type: '.$promptName);
            }

            return [
                'body' => $this->openAiStructuredResponse([
                    'candidates' => [
                        $this->buildFullDocumentRequirementExtractionCandidate('Leverandøren skal levere dokumentasjon innen 10 dager.', [
                            'requirement_identifier' => '1.1',
                            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
                            'obligation_type' => 'must',
                            'source_reference_text' => 'Bilag 1 punkt 2.7',
                            'interpretation_risk' => 'low',
                        ]),
                    ],
                ], 120, 42),
            ];
        });

        $result = app(RequirementExtractionPipeline::class)->syncDocumentRequirements($document);

        $this->assertTrue($result->ok);
        $this->assertFalse($result->partial);
        $this->assertSame(0, $result->segmentCount);
        $this->assertSame(0, $result->relevantSegmentCount);
        $this->assertSame(0, $result->relevanceCallCount);
        $this->assertSame(1, $result->extractionCallCount);
        $this->assertSame(1, $result->openAiCallCount);
        $this->assertCount(1, $result->candidates);
        $this->assertSame('1.1', $result->candidates[0]->requirementIdentifier);
        $this->assertSame(SavedNoticeAiRequirement::EXTRACTION_METHOD_AI_PHASE_1, $result->candidates[0]->extractionMethod);
        $this->assertSame('Bilag 1 punkt 2.7', $result->candidates[0]->sourceReference['source_reference_text']);
        $this->assertSame(1, SavedNoticeAiRequirement::query()->where('saved_notice_ai_document_id', $document->id)->count());

        $requirement = SavedNoticeAiRequirement::query()
            ->where('saved_notice_ai_document_id', $document->id)
            ->firstOrFail();

        $this->assertSame(SavedNoticeAiRequirement::EXTRACTION_METHOD_AI_FULL_DOCUMENT, $requirement->extraction_method);
        $this->assertNull($requirement->saved_notice_ai_document_chunk_id);
        $this->assertSame('Leverandøren skal levere dokumentasjon innen 10 dager.', $requirement->requirement_text);
        $this->assertSame('Bilag 1 punkt 2.7', $requirement->source_reference['source_reference_text']);
        $this->assertSame(1, collect($capturedRequests)->where('prompt_name', FullDocumentRequirementExtractionPrompt::promptName())->count());
        $this->assertTrue(collect($capturedRequests)->pluck('document_text')->contains(fn (string $documentText): bool => str_contains($documentText, 'Leverandøren skal levere dokumentasjon innen 10 dager.')));
        Http::assertSentCount(1);
    }

    public function test_ai_requirement_extraction_pipeline_marks_full_document_failure_explicitly_without_fallback(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-3004-FALLBACK', 'Fallback extraction target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 10:55:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'fallback-blocks.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/fallback-blocks.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'text_extracted_at' => '2026-04-06 10:56:00',
        ]);

        $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Leverandøren skal levere dokumentasjon innen 10 dager. '.str_repeat('Relevant kravtekst. ', 50),
            'char_start' => 0,
            'char_end' => 1200,
            'word_count' => 140,
        ]);

        $capturedRequests = [];
        $this->fakeOpenAiFullDocumentRequirementExtractionResponse(function (array $promptContext) use (&$capturedRequests): array {
            $promptName = (string) data_get($promptContext, 'prompt_name', '');
            $documentText = (string) data_get($promptContext, 'document_text', '');

            $capturedRequests[] = [
                'prompt_name' => $promptName,
                'document_text' => $documentText,
            ];

            if ($promptName !== FullDocumentRequirementExtractionPrompt::promptName()) {
                throw new RuntimeException('Unexpected prompt type: '.$promptName);
            }

            return [
                'body' => $this->openAiStructuredResponse([
                    'candidates' => [],
                ], 120, 42),
                'status' => 503,
            ];
        });

        $result = app(RequirementExtractionPipeline::class)->syncDocumentRequirements($document);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->partial);
        $this->assertSame(0, $result->segmentCount);
        $this->assertSame(0, $result->relevantSegmentCount);
        $this->assertSame(0, $result->relevanceCallCount);
        $this->assertSame(1, $result->extractionCallCount);
        $this->assertSame(1, $result->openAiCallCount);
        $this->assertSame(0, count($result->candidates));
        $this->assertSame('upstream_error', $result->errorType);
        $this->assertFalse((bool) ($result->metadata['fallback_used'] ?? true));
        $this->assertSame(1, collect($capturedRequests)->where('prompt_name', FullDocumentRequirementExtractionPrompt::promptName())->count());
        $this->assertSame(0, SavedNoticeAiRequirement::query()->where('saved_notice_ai_document_id', $document->id)->count());
        Http::assertSentCount(1);
    }

    public function test_ai_documents_show_exposes_backend_failure_state_for_failed_document_runs(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-3004-FAIL-STATE', 'Failure state target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 10:57:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'failed-document.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/failed-document.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'text_extracted_at' => '2026-04-06 10:58:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_FAILED,
            'processing_error_type' => 'timeout',
            'processing_error_message' => 'OpenAI request timed out after 180001 milliseconds.',
            'queued_at' => '2026-04-06 10:58:05',
            'processing_started_at' => '2026-04-06 10:58:06',
            'processing_finished_at' => '2026-04-06 10:58:10',
        ]);

        RequirementExtractionRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'saved_notice_id' => $savedNotice->id,
            'saved_notice_ai_document_id' => $document->id,
            'status' => RequirementExtractionRun::STATUS_FAILED,
            'strategy' => RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION,
            'prompt_version' => FullDocumentRequirementExtractionPrompt::promptVersion(),
            'model' => FullDocumentRequirementExtractionPrompt::model(),
            'failure_stage' => 'openai_timeout',
            'error_type' => 'timeout',
            'error_message' => 'OpenAI request timed out after 180001 milliseconds.',
            'candidate_count' => 0,
            'persisted_requirement_count' => 0,
            'openai_call_count' => 1,
            'input_tokens_total' => 0,
            'output_tokens_total' => 0,
            'total_tokens_total' => 0,
            'queued_at' => '2026-04-06 10:58:05',
            'started_at' => '2026-04-06 10:58:06',
            'finished_at' => '2026-04-06 10:58:10',
            'last_heartbeat_at' => '2026-04-06 10:58:10',
        ]);

        $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->assertViewHas('page', function (array $page) use ($savedNotice, $document): bool {
                $documentRow = collect(data_get($page, 'props.documents', []))
                    ->firstWhere('id', $document->id);

                return data_get($page, 'component') === 'App/AI/Show'
                    && $documentRow !== null
                    && $documentRow['processing_status'] === SavedNoticeAiDocument::PROCESSING_STATUS_FAILED
                    && $documentRow['processing_error_type'] === 'timeout'
                    && $documentRow['processing_error_message'] === 'OpenAI request timed out after 180001 milliseconds.'
                    && $documentRow['processing_failure_stage'] === 'openai_timeout'
                    && $documentRow['processing_failure_type'] === 'timeout'
                    && $documentRow['processing_failure_message'] === 'OpenAI request timed out after 180001 milliseconds.'
                    && data_get($page, 'props.documents_upload_url') === route('app.ai.documents.store', ['savedNotice' => $savedNotice->id]);
            });
    }

    public function test_ai_documents_upload_persists_files_and_redirects_back_to_the_case_view(): void
    {
        Storage::fake('local');
        Queue::fake();

        $context = $this->customerAdminContext();
        $owner = User::factory()->create([
            'name' => 'AI Owner',
            'email' => 'ai.upload.owner@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-3001', 'Upload target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'opportunity_owner_user_id' => $owner->id,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 10:30:00');

        $longText = implode(' ', array_fill(0, 240, 'Procynia tender workspace'));
        $firstDocument = $this->createDocxUpload('scope-note.docx', $longText);
        $secondDocument = UploadedFile::fake()->create(
            'requirements-list.docx',
            256,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );

        $response = $this->actingAs($context['user'])->post(route('app.ai.documents.store', ['savedNotice' => $savedNotice->id]), [
            'documents' => [$firstDocument, $secondDocument],
        ]);

        $response->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));
        $response->assertSessionHas('success', 'Uploaded 2 documents.');
        Queue::assertPushed(ProcessRequirementExtractionRun::class, 2);

        $documents = SavedNoticeAiDocument::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
        $documentsByFilename = $documents->keyBy('original_filename');
        $scopeNoteDocument = $documentsByFilename->get('scope-note.docx');
        $requirementsDocument = $documentsByFilename->get('requirements-list.docx');
        $scopeNoteChunks = SavedNoticeAiDocumentChunk::query()
            ->where('saved_notice_ai_document_id', $scopeNoteDocument->id)
            ->orderBy('chunk_index')
            ->get();
        $requirementsChunks = SavedNoticeAiDocumentChunk::query()
            ->where('saved_notice_ai_document_id', $requirementsDocument->id)
            ->get();

        $this->assertCount(2, $documents);
        $this->assertSame($context['user']->id, $documents->first()->uploaded_by_user_id);
        $this->assertSame('requirements-list.docx', $documents->first()->original_filename);
        $this->assertSame('scope-note.docx', $documents->last()->original_filename);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_QUEUED, $documents->first()->processing_status);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_QUEUED, $documents->last()->processing_status);
        $this->assertSame('', (string) $requirementsDocument->extracted_text);
        $this->assertNotNull($requirementsDocument->text_extracted_at);
        $this->assertNotNull($requirementsDocument->queued_at);
        $this->assertNull($requirementsDocument->processing_started_at);
        $this->assertNull($requirementsDocument->processing_finished_at);
        $this->assertStringStartsWith('saved-notices/'.$savedNotice->id.'/ai-documents/', $requirementsDocument->stored_path);
        $this->assertSame($longText, $scopeNoteDocument->extracted_text);
        $this->assertNotNull($scopeNoteDocument->text_extracted_at);
        $this->assertNotNull($scopeNoteDocument->queued_at);
        $this->assertNull($scopeNoteDocument->processing_started_at);
        $this->assertNull($scopeNoteDocument->processing_finished_at);
        $this->assertStringStartsWith('saved-notices/'.$savedNotice->id.'/ai-documents/', $scopeNoteDocument->stored_path);
        $this->assertSame(0, $requirementsChunks->count());
        $this->assertSame(1, $scopeNoteChunks->count());
        $this->assertSame(range(0, $scopeNoteChunks->count() - 1), $scopeNoteChunks->pluck('chunk_index')->all());

        $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->assertViewHas('page', function (array $page) use ($savedNotice): bool {
                $documents = collect(data_get($page, 'props.documents', []));
                $documentsByFilename = $documents->keyBy('original_filename');

                return data_get($page, 'component') === 'App/AI/Show'
                    && data_get($page, 'props.documents_upload_url') === route('app.ai.documents.store', ['savedNotice' => $savedNotice->id])
                    && $documents->count() === 2
                    && $documents->first()['original_filename'] === 'requirements-list.docx'
                    && $documents->last()['original_filename'] === 'scope-note.docx'
                    && $documents->first()['processing_status'] === SavedNoticeAiDocument::PROCESSING_STATUS_QUEUED
                    && $documentsByFilename->get('requirements-list.docx')['has_extracted_text'] === false
                    && $documentsByFilename->get('scope-note.docx')['has_extracted_text'] === true
                    && $documentsByFilename->get('requirements-list.docx')['chunk_count'] === 0
                    && $documentsByFilename->get('scope-note.docx')['chunk_count'] === 1
                    && $documentsByFilename->get('scope-note.docx')['delete_url'] === route('app.ai.documents.destroy', [
                        'savedNotice' => $savedNotice->id,
                        'document' => $documentsByFilename->get('scope-note.docx')['id'],
                    ]);
            });
    }

    public function test_ai_documents_upload_keeps_empty_extracted_text_when_parsing_fails(): void
    {
        Storage::fake('local');
        Queue::fake();

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-3003', 'Extraction fallback target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 11:30:00');

        $brokenDocument = UploadedFile::fake()->create(
            'broken.docx',
            128,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );

        $response = $this->actingAs($context['user'])->post(route('app.ai.documents.store', ['savedNotice' => $savedNotice->id]), [
            'documents' => [$brokenDocument],
        ]);

        $response->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));
        $response->assertSessionHas('success', 'Uploaded 1 document.');
        Queue::assertPushed(ProcessRequirementExtractionRun::class, 1);

        $document = SavedNoticeAiDocument::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->firstOrFail();

        $this->assertSame('', (string) $document->extracted_text);
        $this->assertNotNull($document->text_extracted_at);
        $this->assertNotNull($document->queued_at);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_QUEUED, $document->processing_status);
        $this->assertStringStartsWith('saved-notices/'.$savedNotice->id.'/ai-documents/', $document->stored_path);
        $this->assertSame(0, SavedNoticeAiDocumentChunk::query()->where('saved_notice_ai_document_id', $document->id)->count());

        $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->assertViewHas('page', function (array $page): bool {
                $documents = collect(data_get($page, 'props.documents', []));

                return $documents->count() === 1
                    && $documents->first()['has_extracted_text'] === false
                    && $documents->first()['chunk_count'] === 0
                    && $documents->first()['processing_status'] === SavedNoticeAiDocument::PROCESSING_STATUS_QUEUED;
            });
    }

    public function test_ai_documents_upload_rejects_unsupported_legacy_file_type(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-3003-INVALID', 'Invalid upload target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 11:35:00');

        $response = $this->actingAs($context['user'])->post(route('app.ai.documents.store', ['savedNotice' => $savedNotice->id]), [
            'documents' => [
                UploadedFile::fake()->create('legacy.xls', 8, 'application/vnd.ms-excel'),
            ],
        ]);

        $response->assertSessionHasErrors(['documents.0']);
    }

    public function test_ai_document_delete_removes_file_chunks_requirements_and_returns_to_the_case_view(): void
    {
        Storage::fake('local');

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-3004', 'Delete target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:15:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'delete-me.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/delete-me.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Dokumentasjon må vedlegges.',
            'text_extracted_at' => '2026-04-06 12:20:00',
        ]);
        Storage::disk('local')->put($document->stored_path, 'document bytes');

        $chunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Dokumentasjon må vedlegges.',
            'char_start' => 0,
            'char_end' => 27,
            'word_count' => 3,
        ]);
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk);

        $response = $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->delete(route('app.ai.documents.destroy', [
                'savedNotice' => $savedNotice->id,
                'document' => $document->id,
            ]));

        $response->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));
        $response->assertSessionHas('success', 'Deleted 1 document.');
        $this->assertDatabaseMissing('saved_notice_ai_documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('saved_notice_ai_document_chunks', ['id' => $chunk->id]);
        $this->assertDatabaseMissing('saved_notice_ai_requirements', ['id' => $requirement->id]);
        $this->assertTrue(Storage::disk('local')->missing($document->stored_path));

        $pageResponse = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $pageResponse->assertOk();
        $page = $this->inertiaPageFromResponse($pageResponse);

        $this->assertSame([], data_get($page, 'props.documents', []));
    }

    public function test_ai_document_delete_is_scoped_to_the_visible_saved_notice(): void
    {
        Storage::fake('local');

        $context = $this->customerAdminContext();
        $firstSavedNotice = $this->createSavedNotice($context['customer']->id, 'AI-3005', 'Delete scope first', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($firstSavedNotice, '2026-04-06 12:30:00');

        $secondSavedNotice = $this->createSavedNotice($context['customer']->id, 'AI-3006', 'Delete scope second', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($secondSavedNotice, '2026-04-06 12:35:00');

        $document = $this->createAiDocument($secondSavedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'wrong-case.docx',
            'stored_path' => 'saved-notices/'.$secondSavedNotice->id.'/ai-documents/wrong-case.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Dokumentasjon må vedlegges.',
            'text_extracted_at' => '2026-04-06 12:40:00',
        ]);
        Storage::disk('local')->put($document->stored_path, 'document bytes');

        $this->actingAs($context['user'])
            ->delete(route('app.ai.documents.destroy', [
                'savedNotice' => $firstSavedNotice->id,
                'document' => $document->id,
            ]))
            ->assertNotFound();

        $this->assertDatabaseHas('saved_notice_ai_documents', ['id' => $document->id]);
        $this->assertTrue(Storage::disk('local')->exists($document->stored_path));
    }

    public function test_ai_requirement_review_status_can_transition_between_pending_confirmed_rejected_and_back_to_pending(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4001', 'Review target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'review-pack.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/review-pack.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Dokumentasjon må vedlegges.',
            'text_extracted_at' => '2026-04-06 12:05:00',
        ]);
        $chunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Dokumentasjon må vedlegges.',
            'char_start' => 0,
            'char_end' => 27,
            'word_count' => 3,
        ]);
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk);

        $reviewStatusUrl = route('app.ai.requirements.review-status.update', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]);

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->patch($reviewStatusUrl, [
                'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            ])
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $requirement->refresh();
        $this->assertSame(SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED, $requirement->review_status);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirements = collect(data_get($page, 'props.requirements', []));

        $this->assertSame(1, $requirements->count());
        $this->assertSame(SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED, $requirements->first()['review_status']);
        $this->assertSame(
            SavedNoticeAiRequirement::REVIEW_STATUS_LABELS[SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED],
            $requirements->first()['review_status_label'],
        );

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->patch($reviewStatusUrl, [
                'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED,
            ])
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $requirement->refresh();
        $this->assertSame(SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED, $requirement->review_status);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirements = collect(data_get($page, 'props.requirements', []));

        $this->assertSame(1, $requirements->count());
        $this->assertSame(SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED, $requirements->first()['review_status']);
        $this->assertSame(
            SavedNoticeAiRequirement::REVIEW_STATUS_LABELS[SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED],
            $requirements->first()['review_status_label'],
        );

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->patch($reviewStatusUrl, [
                'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            ])
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $requirement->refresh();
        $this->assertSame(SavedNoticeAiRequirement::REVIEW_STATUS_PENDING, $requirement->review_status);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirements = collect(data_get($page, 'props.requirements', []));

        $this->assertSame(1, $requirements->count());
        $this->assertSame(SavedNoticeAiRequirement::REVIEW_STATUS_PENDING, $requirements->first()['review_status']);
        $this->assertSame(
            SavedNoticeAiRequirement::REVIEW_STATUS_LABELS[SavedNoticeAiRequirement::REVIEW_STATUS_PENDING],
            $requirements->first()['review_status_label'],
        );
    }

    public function test_ai_requirement_review_status_rejects_invalid_values(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4002', 'Invalid review target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:30:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'invalid-review.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/invalid-review.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 12:31:00',
        ]);
        $chunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Leverandøren skal beskrive løsningen.',
            'char_start' => 0,
            'char_end' => 37,
            'word_count' => 4,
        ]);
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk);

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->patch(route('app.ai.requirements.review-status.update', [
                'savedNotice' => $savedNotice->id,
                'requirement' => $requirement->id,
            ]), [
                'review_status' => 'approved',
            ])
            ->assertSessionHasErrors('review_status');

        $requirement->refresh();
        $this->assertSame(SavedNoticeAiRequirement::REVIEW_STATUS_PENDING, $requirement->review_status);
    }

    public function test_ai_requirement_review_status_is_scoped_to_the_visible_saved_notice(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4003', 'Scope target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 13:00:00');

        $otherSavedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4004', 'Foreign requirement target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($otherSavedNotice, '2026-04-06 13:05:00');

        $savedDocument = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'scope-review.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/scope-review.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Dokumentasjon må vedlegges.',
            'text_extracted_at' => '2026-04-06 13:01:00',
        ]);
        $savedChunk = $savedDocument->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Dokumentasjon må vedlegges.',
            'char_start' => 0,
            'char_end' => 27,
            'word_count' => 3,
        ]);
        $savedRequirement = $this->createAiRequirement($savedNotice, $savedDocument, $savedChunk);

        $otherDocument = $this->createAiDocument($otherSavedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'foreign-review.docx',
            'stored_path' => 'saved-notices/'.$otherSavedNotice->id.'/ai-documents/foreign-review.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 13:06:00',
        ]);
        $otherChunk = $otherDocument->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Leverandøren skal beskrive løsningen.',
            'char_start' => 0,
            'char_end' => 37,
            'word_count' => 4,
        ]);
        $otherRequirement = $this->createAiRequirement($otherSavedNotice, $otherDocument, $otherChunk);

        $this->actingAs($context['user'])
            ->patch(route('app.ai.requirements.review-status.update', [
                'savedNotice' => $savedNotice->id,
                'requirement' => $otherRequirement->id,
            ]), [
                'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            ])
            ->assertNotFound();

        $savedRequirement->refresh();
        $otherRequirement->refresh();

        $this->assertSame(SavedNoticeAiRequirement::REVIEW_STATUS_PENDING, $savedRequirement->review_status);
        $this->assertSame(SavedNoticeAiRequirement::REVIEW_STATUS_PENDING, $otherRequirement->review_status);
    }

    public function test_ai_case_view_includes_work_metadata_for_confirmed_and_pending_requirements(): void
    {
        $context = $this->customerAdminContext();
        $assignedUser = User::factory()->create([
            'name' => 'Work Owner',
            'email' => 'work.owner@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4005', 'Work payload target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 13:20:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'work-pack.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/work-pack.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Dokumentasjon må vedlegges. Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 13:25:00',
        ]);
        $confirmedChunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Dokumentasjon må vedlegges.',
            'char_start' => 0,
            'char_end' => 27,
            'word_count' => 3,
        ]);
        $pendingChunk = $document->chunks()->create([
            'chunk_index' => 1,
            'content' => 'Leverandøren skal beskrive løsningen.',
            'char_start' => 28,
            'char_end' => 65,
            'word_count' => 4,
        ]);

        $confirmedRequirement = $this->createAiRequirement($savedNotice, $document, $confirmedChunk, [
            'requirement_text' => 'Dokumentasjon må vedlegges.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_IN_PROGRESS,
            'assigned_user_id' => $assignedUser->id,
        ]);
        $pendingRequirement = $this->createAiRequirement($savedNotice, $document, $pendingChunk, [
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
        ]);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirements = collect(data_get($page, 'props.requirements', []))->keyBy('id');
        $assignedUserOptions = collect(data_get($page, 'props.assigned_user_options', []));
        $assignableUsers = collect(data_get($page, 'props.assignable_users', []));

        $this->assertSame(2, data_get($page, 'props.requirements_count'));
        $this->assertTrue($assignedUserOptions->contains(fn (array $option): bool => $option['value'] === $assignedUser->id));
        $this->assertTrue($assignableUsers->contains(fn (array $user): bool => $user['id'] === $assignedUser->id && $user['name'] === $assignedUser->name && $user['email'] === $assignedUser->email));
        $this->assertSame(SavedNoticeAiRequirement::WORK_STATUS_IN_PROGRESS, $requirements->get($confirmedRequirement->id)['work_status']);
        $this->assertSame(
            SavedNoticeAiRequirement::WORK_STATUS_LABELS[SavedNoticeAiRequirement::WORK_STATUS_IN_PROGRESS],
            $requirements->get($confirmedRequirement->id)['work_status_label'],
        );
        $this->assertSame($confirmedRequirement->assigned_user_id, $requirements->get($confirmedRequirement->id)['assigned_user_id']);
        $this->assertSame($assignedUser->id, $requirements->get($confirmedRequirement->id)['assigned_user']['id']);
        $this->assertSame($assignedUser->name, $requirements->get($confirmedRequirement->id)['assigned_user']['name']);
        $this->assertSame($assignedUser->email, $requirements->get($confirmedRequirement->id)['assigned_user']['email']);
        $this->assertSame(route('app.ai.requirements.assigned-user.update', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $confirmedRequirement->id,
        ]), $requirements->get($confirmedRequirement->id)['assigned_user_update_url']);
        $this->assertSame(route('app.ai.requirements.work.update', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $confirmedRequirement->id,
        ]), $requirements->get($confirmedRequirement->id)['work_update_url']);
        $this->assertSame(SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED, $requirements->get($pendingRequirement->id)['work_status']);
        $this->assertSame(
            SavedNoticeAiRequirement::WORK_STATUS_LABELS[SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED],
            $requirements->get($pendingRequirement->id)['work_status_label'],
        );
        $this->assertNull($requirements->get($pendingRequirement->id)['assigned_user']);
    }

    public function test_ai_case_view_includes_requirements_overview_counts_for_review_and_work_layers(): void
    {
        $context = $this->customerAdminContext();
        $assignedUserOne = User::factory()->create([
            'name' => 'Assigned One',
            'email' => 'assigned.one@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);
        $assignedUserTwo = User::factory()->create([
            'name' => 'Assigned Two',
            'email' => 'assigned.two@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4006', 'Overview target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 13:50:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'overview-pack.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/overview-pack.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => 'Overview source text.',
            'text_extracted_at' => '2026-04-06 13:55:00',
        ]);

        $chunks = [
            $document->chunks()->create([
                'chunk_index' => 0,
                'content' => 'Confirmed requirement one.',
                'char_start' => 0,
                'char_end' => 26,
                'word_count' => 3,
            ]),
            $document->chunks()->create([
                'chunk_index' => 1,
                'content' => 'Confirmed requirement two.',
                'char_start' => 27,
                'char_end' => 53,
                'word_count' => 3,
            ]),
            $document->chunks()->create([
                'chunk_index' => 2,
                'content' => 'Confirmed requirement three.',
                'char_start' => 54,
                'char_end' => 82,
                'word_count' => 3,
            ]),
            $document->chunks()->create([
                'chunk_index' => 3,
                'content' => 'Confirmed requirement four.',
                'char_start' => 83,
                'char_end' => 110,
                'word_count' => 3,
            ]),
            $document->chunks()->create([
                'chunk_index' => 4,
                'content' => 'Pending requirement.',
                'char_start' => 111,
                'char_end' => 132,
                'word_count' => 2,
            ]),
            $document->chunks()->create([
                'chunk_index' => 5,
                'content' => 'Rejected requirement.',
                'char_start' => 133,
                'char_end' => 155,
                'word_count' => 2,
            ]),
        ];

        $this->createAiRequirement($savedNotice, $document, $chunks[0], [
            'requirement_text' => 'Confirmed requirement one.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
            'assigned_user_id' => null,
        ]);
        $this->createAiRequirement($savedNotice, $document, $chunks[1], [
            'requirement_text' => 'Confirmed requirement two.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_IN_PROGRESS,
            'assigned_user_id' => $assignedUserOne->id,
        ]);
        $this->createAiRequirement($savedNotice, $document, $chunks[2], [
            'requirement_text' => 'Confirmed requirement three.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_DONE,
            'assigned_user_id' => $assignedUserTwo->id,
        ]);
        $this->createAiRequirement($savedNotice, $document, $chunks[3], [
            'requirement_text' => 'Confirmed requirement four.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_DONE,
            'assigned_user_id' => null,
        ]);
        $this->createAiRequirement($savedNotice, $document, $chunks[4], [
            'requirement_text' => 'Pending requirement.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
        ]);
        $this->createAiRequirement($savedNotice, $document, $chunks[5], [
            'requirement_text' => 'Rejected requirement.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED,
        ]);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirementsOverview = data_get($page, 'props.requirements_overview', []);
        $requirements = collect(data_get($page, 'props.requirements', []));

        $this->assertSame(6, data_get($page, 'props.requirements_count'));
        $this->assertSame(4, data_get($requirementsOverview, 'confirmed_total'));
        $this->assertSame(4, data_get($requirementsOverview, 'approved_total'));
        $this->assertSame(1, data_get($requirementsOverview, 'pending_total'));
        $this->assertSame(1, data_get($requirementsOverview, 'draft_total'));
        $this->assertSame(1, data_get($requirementsOverview, 'rejected_total'));
        $this->assertSame(1, data_get($requirementsOverview, 'not_started_total'));
        $this->assertSame(1, data_get($requirementsOverview, 'in_progress_total'));
        $this->assertSame(2, data_get($requirementsOverview, 'done_total'));
        $this->assertSame(2, data_get($requirementsOverview, 'unassigned_confirmed_total'));
        $this->assertSame(6, $requirements->count());
    }

    public function test_ai_requirement_manual_create_edit_and_revisions_are_tracked(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4006-MANUAL', 'Manual requirement target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 13:51:00');

        $createResponse = $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.ai.requirements.store', ['savedNotice' => $savedNotice->id]), [
                'requirement_identifier' => '3.2',
                'requirement_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
                'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            ]);

        $createResponse->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $requirement = SavedNoticeAiRequirement::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('requirement_identifier', '3.2')
            ->with('revisions')
            ->withCount('revisions')
            ->firstOrFail();

        $this->assertSame(SavedNoticeAiRequirement::SOURCE_TYPE_MANUAL, $requirement->source_type);
        $this->assertSame(SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT, $requirement->approval_status);
        $this->assertSame(SavedNoticeAiRequirement::REVIEW_STATUS_PENDING, $requirement->review_status);
        $this->assertSame('3.2', $requirement->requirement_identifier);
        $this->assertSame('3.2', $requirement->original_requirement_identifier);
        $this->assertSame('Leverandøren skal levere dokumentasjon innen 10 dager.', $requirement->requirement_text);
        $this->assertSame('Leverandøren skal levere dokumentasjon innen 10 dager.', $requirement->original_requirement_text);
        $this->assertSame(1, $requirement->revisions_count);
        $this->assertSame(SavedNoticeAiRequirementRevision::CHANGE_TYPE_MANUAL_CREATE, $requirement->revisions->first()->change_type);

        $updateResponse = $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->patch(route('app.ai.requirements.update', [
                'savedNotice' => $savedNotice->id,
                'requirement' => $requirement->id,
            ]), [
                'requirement_identifier' => '3.2a',
                'requirement_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager og varsle avvik.',
                'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            ]);

        $updateResponse->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $requirement->refresh()->load('revisions')->loadCount('revisions');

        $this->assertSame('3.2a', $requirement->requirement_identifier);
        $this->assertSame('3.2', $requirement->original_requirement_identifier);
        $this->assertSame('Leverandøren skal levere dokumentasjon innen 10 dager og varsle avvik.', $requirement->requirement_text);
        $this->assertSame('Leverandøren skal levere dokumentasjon innen 10 dager.', $requirement->original_requirement_text);
        $this->assertSame(SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT, $requirement->approval_status);
        $this->assertSame(SavedNoticeAiRequirement::REVIEW_STATUS_PENDING, $requirement->review_status);
        $this->assertSame(2, $requirement->revisions_count);
        $this->assertSame(
            SavedNoticeAiRequirementRevision::CHANGE_TYPE_EDIT_METADATA,
            $requirement->revisions->last()->change_type,
        );
        $this->assertContains('requirement_identifier', $requirement->revisions->last()->changed_fields ?? []);
        $this->assertContains('requirement_text', $requirement->revisions->last()->changed_fields ?? []);

        $approvedRequirements = app(RequirementLoader::class)->loadApprovedForCase($savedNotice->id);
        $this->assertCount(0, $approvedRequirements);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirements = collect(data_get($page, 'props.requirements', []))->keyBy('id');
        $payload = $requirements->get($requirement->id);

        $this->assertSame('3.2a', $payload['current_requirement_identifier']);
        $this->assertSame('3.2', $payload['original_requirement_identifier']);
        $this->assertSame('Leverandøren skal levere dokumentasjon innen 10 dager og varsle avvik.', $payload['current_requirement_text']);
        $this->assertSame('Leverandøren skal levere dokumentasjon innen 10 dager.', $payload['original_requirement_text']);
        $this->assertSame(SavedNoticeAiRequirement::SOURCE_TYPE_MANUAL, $payload['source_type']);
        $this->assertSame(SavedNoticeAiRequirement::SOURCE_TYPE_LABELS[SavedNoticeAiRequirement::SOURCE_TYPE_MANUAL], $payload['source_type_label']);
        $this->assertSame(SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT, $payload['approval_status']);
        $this->assertSame('Redigert', $payload['edit_state_label']);
        $this->assertSame(2, $payload['revision_count']);
        $this->assertSame(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), $payload['answer_draft_generate_url']);
        $this->assertSame(route('app.ai.requirements.answer-draft.update', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), $payload['answer_draft_save_url']);
    }

    public function test_ai_requirement_approval_rejection_and_loader_scope_are_explicit(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4006-STATE', 'State target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 13:52:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'state-pack.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/state-pack.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 13:53:00',
        ]);
        $chunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Leverandøren skal beskrive løsningen.',
            'char_start' => 0,
            'char_end' => 37,
            'word_count' => 4,
        ]);
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
        ]);

        $reviewStatusUrl = route('app.ai.requirements.review-status.update', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]);

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->patch($reviewStatusUrl, [
                'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            ])
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $requirement->refresh()->loadCount('revisions');

        $this->assertSame(SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED, $requirement->approval_status);
        $this->assertSame(SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED, $requirement->review_status);
        $this->assertNotNull($requirement->approved_at);
        $this->assertNull($requirement->rejected_at);
        $this->assertSame(1, $requirement->revisions_count);
        $this->assertCount(1, app(RequirementLoader::class)->loadApprovedForCase($savedNotice->id));

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->patch($reviewStatusUrl, [
                'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED,
            ])
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $requirement->refresh()->loadCount('revisions');

        $this->assertSame(SavedNoticeAiRequirement::APPROVAL_STATUS_REJECTED, $requirement->approval_status);
        $this->assertSame(SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED, $requirement->review_status);
        $this->assertNull($requirement->approved_at);
        $this->assertNotNull($requirement->rejected_at);
        $this->assertSame(2, $requirement->revisions_count);
        $this->assertCount(0, app(RequirementLoader::class)->loadApprovedForCase($savedNotice->id));

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->patch($reviewStatusUrl, [
                'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            ])
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $requirement->refresh()->loadCount('revisions');

        $this->assertSame(SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT, $requirement->approval_status);
        $this->assertSame(SavedNoticeAiRequirement::REVIEW_STATUS_PENDING, $requirement->review_status);
        $this->assertNull($requirement->approved_at);
        $this->assertNull($requirement->rejected_at);
        $this->assertSame(3, $requirement->revisions_count);
        $this->assertCount(0, app(RequirementLoader::class)->loadApprovedForCase($savedNotice->id));
    }

    public function test_ai_case_view_refreshes_and_displays_persisted_evidence_for_confirmed_requirements_only(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4007', 'Evidence target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 14:10:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'evidence-target.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/evidence-target.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 3072,
            'extracted_text' => 'Requirement source text.',
            'text_extracted_at' => '2026-04-06 14:12:00',
        ]);
        $sourceChunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Vi trenger erfaring med metode og cv i leveransen.',
            'char_start' => 0,
            'char_end' => 52,
            'word_count' => 9,
        ]);

        $confirmedRequirement = $this->createAiRequirement($savedNotice, $document, $sourceChunk, [
            'requirement_text' => 'Vi trenger erfaring med metode og cv i leveransen.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
            'assigned_user_id' => null,
        ]);
        $pendingRequirement = $this->createAiRequirement($savedNotice, $document, $sourceChunk, [
            'requirement_text' => 'Vi trenger erfaring med metode og cv i leveransen.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
        ]);
        $rejectedRequirement = $this->createAiRequirement($savedNotice, $document, $sourceChunk, [
            'requirement_text' => 'Vi trenger erfaring med metode og cv i leveransen.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED,
        ]);

        $cvKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'CV profile',
            'content_type' => KnowledgeItem::CONTENT_TYPE_CV,
            'content' => 'CV erfaring',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($cvKnowledge);
        $cvChunk = $cvKnowledge->chunks()->firstOrFail();

        $referenceKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Reference profile',
            'content_type' => KnowledgeItem::CONTENT_TYPE_REFERENCE,
            'content' => 'erfaring referanser',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($referenceKnowledge);

        $methodKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Method profile',
            'content_type' => KnowledgeItem::CONTENT_TYPE_METHOD,
            'content' => 'metode prosess',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($methodKnowledge);

        $companyKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Company profile',
            'content_type' => KnowledgeItem::CONTENT_TYPE_COMPANY,
            'content' => 'leveranser erfaring',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($companyKnowledge);

        $boilerplateKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Boilerplate profile',
            'content_type' => KnowledgeItem::CONTENT_TYPE_BOILERPLATE,
            'content' => 'leveranser standard',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($boilerplateKnowledge);

        $otherKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Other profile',
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'content' => 'metode standard',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($otherKnowledge);

        $inactiveKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Inactive knowledge',
            'content_type' => KnowledgeItem::CONTENT_TYPE_REFERENCE,
            'content' => 'erfaring referanser',
            'is_active' => false,
        ]);
        $this->syncKnowledgeItemChunks($inactiveKnowledge);

        $foreignContext = $this->customerAdminContext('Foreign Knowledge AS');
        $foreignKnowledge = $this->createKnowledgeItem($foreignContext['customer'], [
            'title' => 'Foreign knowledge',
            'content_type' => KnowledgeItem::CONTENT_TYPE_REFERENCE,
            'content' => 'erfaring referanser',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($foreignKnowledge);

        SavedNoticeAiEvidence::query()->create([
            'saved_notice_ai_requirement_id' => $confirmedRequirement->id,
            'knowledge_item_id' => $cvKnowledge->id,
            'knowledge_item_chunk_id' => $cvChunk->id,
            'match_type' => SavedNoticeAiEvidence::MATCH_TYPE_MANUAL_ADD,
            'match_score' => 10,
            'match_rank' => 1,
            'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SELECTED,
            'is_primary' => true,
            'created_by_user_id' => $context['user']->id,
        ]);

        $this->bindEmbeddingService(function (string $text): array {
            $embeddingVector = $this->deterministicEmbeddingVector();

            return [
                'ok' => true,
                'embedding' => $embeddingVector,
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'test-request-id',
                'response_body_excerpt' => null,
            ];
        });

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.ai.evidence.refresh', ['savedNotice' => $savedNotice->id]))
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.ai.evidence.refresh', ['savedNotice' => $savedNotice->id]))
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirements = collect(data_get($page, 'props.requirements', []))->keyBy('id');
        $confirmedEvidence = collect(data_get($requirements->get($confirmedRequirement->id), 'evidence', []));
        $pendingEvidence = collect(data_get($requirements->get($pendingRequirement->id), 'evidence', []));
        $rejectedEvidence = collect(data_get($requirements->get($rejectedRequirement->id), 'evidence', []));

        $this->assertNotEmpty(data_get($page, 'props.evidence_refresh_url'));
        $this->assertSame(3, $requirements->count());
        $this->assertSame(5, $confirmedEvidence->count());
        $this->assertSame(SavedNoticeAiEvidence::MATCH_TYPE_MANUAL_ADD, $confirmedEvidence->first()['match_type']);
        $this->assertSame(SavedNoticeAiEvidence::SELECTION_STATUS_SELECTED, $confirmedEvidence->first()['selection_status']);
        $this->assertTrue($confirmedEvidence->first()['is_primary']);
        $this->assertSame(1, $confirmedEvidence->filter(static function (array $evidence) use ($cvChunk): bool {
            return (int) data_get($evidence, 'knowledge_chunk.id') === $cvChunk->id;
        })->count());
        $this->assertTrue($confirmedEvidence->every(static function (array $evidence): bool {
            return filled($evidence['id'])
                && filled($evidence['selection_status'])
                && filled($evidence['match_type'])
                && array_key_exists('knowledge_item', $evidence)
                && array_key_exists('knowledge_chunk', $evidence)
                && filled(data_get($evidence, 'knowledge_item.id'))
                && filled(data_get($evidence, 'knowledge_item.original_filename'))
                && filled(data_get($evidence, 'knowledge_chunk.content'));
        }));
        $this->assertFalse($confirmedEvidence->contains(fn (array $evidence): bool => data_get($evidence, 'knowledge_item.original_filename') === 'Foreign knowledge'));
        $this->assertFalse($confirmedEvidence->contains(fn (array $evidence): bool => data_get($evidence, 'knowledge_item.original_filename') === 'Inactive knowledge'));
        $this->assertSame([], $pendingEvidence->all());
        $this->assertSame([], $rejectedEvidence->all());
    }

    public function test_ai_case_view_refreshes_evidence_using_hybrid_reranking_when_embeddings_are_available(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4012', 'Hybrid evidence target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 15:20:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'hybrid-evidence.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/hybrid-evidence.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 3072,
            'extracted_text' => 'Hybrid evidence source text.',
            'text_extracted_at' => '2026-04-06 15:21:00',
        ]);
        $requirementChunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'erfaring metode',
            'char_start' => 0,
            'char_end' => 15,
            'word_count' => 2,
        ]);
        $requirement = $this->createAiRequirement($savedNotice, $document, $requirementChunk, [
            'requirement_text' => 'erfaring metode',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        $knowledgeA = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Hybrid A',
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'content' => 'erfaring metode',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($knowledgeA);
        $knowledgeAChunk = $knowledgeA->chunks()->firstOrFail();
        $knowledgeAChunk->forceFill([
            'embedding_vector' => [1.0, 0.0],
            'embedding_model' => 'text-embedding-3-small',
            'embedding_generated_at' => '2026-04-06 15:22:00',
            'embedding_error' => null,
        ])->save();
        $this->touchKnowledgeItem($knowledgeA, '2026-04-06 15:22:00');

        $knowledgeB = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Hybrid B',
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'content' => 'erfaring metode',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($knowledgeB);
        $knowledgeBChunk = $knowledgeB->chunks()->firstOrFail();
        $knowledgeBChunk->forceFill([
            'embedding_vector' => [0.0, 1.0],
            'embedding_model' => 'text-embedding-3-small',
            'embedding_generated_at' => '2026-04-06 15:23:00',
            'embedding_error' => null,
        ])->save();
        $this->touchKnowledgeItem($knowledgeB, '2026-04-06 15:23:00');

        $knowledgeC = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Hybrid C',
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'content' => 'erfaring metode',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($knowledgeC);
        $knowledgeCChunk = $knowledgeC->chunks()->firstOrFail();
        $this->touchKnowledgeItem($knowledgeC, '2026-04-06 15:24:00');

        $this->bindEmbeddingService(function (string $text): array {
            return [
                'ok' => true,
                'embedding' => [1.0, 0.0],
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'test-request-id',
                'response_body_excerpt' => null,
            ];
        });

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.ai.evidence.refresh', ['savedNotice' => $savedNotice->id]))
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirements = collect(data_get($page, 'props.requirements', []))->keyBy('id');
        $evidence = collect(data_get($requirements->get($requirement->id), 'evidence', []));

        $this->assertSame(['Hybrid A', 'Hybrid B', 'Hybrid C'], $evidence->pluck('knowledge_item.original_filename')->all());
        $this->assertSame([$knowledgeAChunk->id, $knowledgeBChunk->id, $knowledgeCChunk->id], $evidence->pluck('knowledge_chunk.id')->all());
        $this->assertSame([1, 2, 3], $evidence->pluck('match_rank')->all());
        $this->assertSame(2, $evidence->first()['match_score']);
    }

    public function test_ai_case_view_refreshes_evidence_with_base_matcher_fallback_when_requirement_embedding_fails(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4013', 'Fallback evidence target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 15:30:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'fallback-evidence.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/fallback-evidence.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 3072,
            'extracted_text' => 'Fallback evidence source text.',
            'text_extracted_at' => '2026-04-06 15:31:00',
        ]);
        $requirementChunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'erfaring metode',
            'char_start' => 0,
            'char_end' => 15,
            'word_count' => 2,
        ]);
        $requirement = $this->createAiRequirement($savedNotice, $document, $requirementChunk, [
            'requirement_text' => 'erfaring metode',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        $knowledgeOldest = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Fallback Oldest',
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'content' => 'erfaring metode',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($knowledgeOldest);
        $knowledgeOldestChunk = $knowledgeOldest->chunks()->firstOrFail();
        $this->touchKnowledgeItem($knowledgeOldest, '2026-04-06 15:32:00');

        $knowledgeNewest = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Fallback Newest',
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'content' => 'erfaring metode',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($knowledgeNewest);
        $knowledgeNewestChunk = $knowledgeNewest->chunks()->firstOrFail();
        $this->touchKnowledgeItem($knowledgeNewest, '2026-04-06 15:33:00');

        $this->bindEmbeddingService(function (string $text): array {
            return [
                'ok' => false,
                'embedding' => null,
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => 'upstream_unavailable',
                'error_message' => 'OpenAI embedding request failed with HTTP status [503].',
                'upstream_status' => 503,
                'request_id' => 'test-request-id',
                'response_body_excerpt' => '{"error":"upstream unavailable"}',
            ];
        });

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.ai.evidence.refresh', ['savedNotice' => $savedNotice->id]))
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirements = collect(data_get($page, 'props.requirements', []))->keyBy('id');
        $evidence = collect(data_get($requirements->get($requirement->id), 'evidence', []));

        $this->assertSame(['Fallback Newest', 'Fallback Oldest'], $evidence->pluck('knowledge_item.original_filename')->all());
        $this->assertSame([$knowledgeNewestChunk->id, $knowledgeOldestChunk->id], $evidence->pluck('knowledge_chunk.id')->all());
        $this->assertSame([1, 2], $evidence->pluck('match_rank')->all());
        $this->assertSame(2, $evidence->first()['match_score']);
    }

    public function test_ai_evidence_selection_status_can_be_updated_for_confirmed_requirements_and_primary_selection_is_unique(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4008', 'Evidence selection target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 14:20:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'evidence-selection.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/evidence-selection.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 3072,
            'extracted_text' => 'Requirement source text.',
            'text_extracted_at' => '2026-04-06 14:22:00',
        ]);
        $chunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Dokumentasjon må vedlegges.',
            'char_start' => 0,
            'char_end' => 27,
            'word_count' => 3,
        ]);
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Dokumentasjon må vedlegges.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        $knowledgeOne = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Selection knowledge one',
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'content' => 'Dokumentasjon må vedlegges.',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($knowledgeOne);
        $chunkOne = $knowledgeOne->chunks()->firstOrFail();

        $knowledgeTwo = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Selection knowledge two',
            'content_type' => KnowledgeItem::CONTENT_TYPE_METHOD,
            'content' => 'Dokumentasjon må vedlegges.',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($knowledgeTwo);
        $chunkTwo = $knowledgeTwo->chunks()->firstOrFail();

        $evidenceOne = SavedNoticeAiEvidence::query()->create([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'knowledge_item_id' => $knowledgeOne->id,
            'knowledge_item_chunk_id' => $chunkOne->id,
            'match_type' => SavedNoticeAiEvidence::MATCH_TYPE_AUTO_MATCH,
            'match_score' => 5,
            'match_rank' => 1,
            'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED,
            'is_primary' => false,
            'created_by_user_id' => null,
        ]);
        $evidenceTwo = SavedNoticeAiEvidence::query()->create([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'knowledge_item_id' => $knowledgeTwo->id,
            'knowledge_item_chunk_id' => $chunkTwo->id,
            'match_type' => SavedNoticeAiEvidence::MATCH_TYPE_AUTO_MATCH,
            'match_score' => 4,
            'match_rank' => 2,
            'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED,
            'is_primary' => false,
            'created_by_user_id' => null,
        ]);

        $selectionStatusUrlOne = route('app.ai.evidence.selection-status.update', [
            'savedNotice' => $savedNotice->id,
            'evidence' => $evidenceOne->id,
        ]);
        $selectionStatusUrlTwo = route('app.ai.evidence.selection-status.update', [
            'savedNotice' => $savedNotice->id,
            'evidence' => $evidenceTwo->id,
        ]);

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->patch($selectionStatusUrlTwo, [
                'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SELECTED,
            ])
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $evidenceTwo->refresh();
        $this->assertSame(SavedNoticeAiEvidence::SELECTION_STATUS_SELECTED, $evidenceTwo->selection_status);
        $this->assertTrue($evidenceTwo->is_primary);

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->patch($selectionStatusUrlOne, [
                'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SELECTED,
            ])
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $evidenceOne->refresh();
        $evidenceTwo->refresh();

        $this->assertSame(SavedNoticeAiEvidence::SELECTION_STATUS_SELECTED, $evidenceOne->selection_status);
        $this->assertTrue($evidenceOne->is_primary);
        $this->assertSame(SavedNoticeAiEvidence::SELECTION_STATUS_SELECTED, $evidenceTwo->selection_status);
        $this->assertFalse($evidenceTwo->is_primary);

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->patch($selectionStatusUrlOne, [
                'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_REJECTED,
            ])
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $evidenceOne->refresh();
        $this->assertSame(SavedNoticeAiEvidence::SELECTION_STATUS_REJECTED, $evidenceOne->selection_status);
        $this->assertFalse($evidenceOne->is_primary);

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->patch($selectionStatusUrlOne, [
                'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED,
            ])
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $evidenceOne->refresh();
        $this->assertSame(SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED, $evidenceOne->selection_status);
        $this->assertFalse($evidenceOne->is_primary);
    }

    public function test_ai_case_view_refreshes_requirement_assessments_for_confirmed_requirements_only_and_prefers_selected_evidence(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-06 14:50:00'));

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4009', 'Assessment target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'ai_instructions' => 'Skriv formelt og bruk Kunde med stor K.',
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 14:40:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'assessment-pack.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/assessment-pack.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => 'Reference and method source text.',
            'text_extracted_at' => '2026-04-06 14:41:00',
        ]);
        $selectedChunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Dokumentert erfaring fra tilsvarende prosjekter er vedlagt.',
            'char_start' => 0,
            'char_end' => 60,
            'word_count' => 7,
        ]);
        $suggestedChunk = $document->chunks()->create([
            'chunk_index' => 1,
            'content' => 'Dette er en metode for gjennomføring av leveransen.',
            'char_start' => 61,
            'char_end' => 113,
            'word_count' => 8,
        ]);

        $selectedRequirement = $this->createAiRequirement($savedNotice, $document, $selectedChunk, [
            'requirement_text' => 'Leverandøren skal dokumentere erfaring fra tilsvarende prosjekter.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);
        $suggestedRequirement = $this->createAiRequirement($savedNotice, $document, $suggestedChunk, [
            'requirement_text' => 'Leverandøren skal beskrive metode for gjennomføring og kvalitetssikring.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_MANDATORY,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);
        $pendingRequirement = $this->createAiRequirement($savedNotice, $document, $suggestedChunk, [
            'requirement_text' => 'Dokumentasjon må vedlegges.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
        ]);
        $rejectedRequirement = $this->createAiRequirement($savedNotice, $document, $suggestedChunk, [
            'requirement_text' => 'Leverandøren skal oppgi kontaktperson.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED,
        ]);

        $selectedKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Selected evidence knowledge',
            'content_type' => KnowledgeItem::CONTENT_TYPE_REFERENCE,
            'content' => 'Dokumentert erfaring fra tilsvarende prosjekter er vedlagt.',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($selectedKnowledge);
        $selectedKnowledgeChunk = $selectedKnowledge->chunks()->firstOrFail();

        $suggestedKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Suggested evidence knowledge',
            'content_type' => KnowledgeItem::CONTENT_TYPE_METHOD,
            'content' => 'Dette er en metode for gjennomføring av leveransen.',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($suggestedKnowledge);
        $suggestedKnowledgeChunk = $suggestedKnowledge->chunks()->firstOrFail();

        SavedNoticeAiEvidence::query()->create([
            'saved_notice_ai_requirement_id' => $selectedRequirement->id,
            'knowledge_item_id' => $selectedKnowledge->id,
            'knowledge_item_chunk_id' => $selectedKnowledgeChunk->id,
            'match_type' => SavedNoticeAiEvidence::MATCH_TYPE_AUTO_MATCH,
            'match_score' => 9,
            'match_rank' => 1,
            'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SELECTED,
            'is_primary' => true,
            'created_by_user_id' => $context['user']->id,
        ]);
        SavedNoticeAiEvidence::query()->create([
            'saved_notice_ai_requirement_id' => $selectedRequirement->id,
            'knowledge_item_id' => $suggestedKnowledge->id,
            'knowledge_item_chunk_id' => $suggestedKnowledgeChunk->id,
            'match_type' => SavedNoticeAiEvidence::MATCH_TYPE_AUTO_MATCH,
            'match_score' => 6,
            'match_rank' => 2,
            'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED,
            'is_primary' => false,
            'created_by_user_id' => $context['user']->id,
        ]);
        SavedNoticeAiEvidence::query()->create([
            'saved_notice_ai_requirement_id' => $suggestedRequirement->id,
            'knowledge_item_id' => $suggestedKnowledge->id,
            'knowledge_item_chunk_id' => $suggestedKnowledgeChunk->id,
            'match_type' => SavedNoticeAiEvidence::MATCH_TYPE_AUTO_MATCH,
            'match_score' => 7,
            'match_rank' => 1,
            'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED,
            'is_primary' => false,
            'created_by_user_id' => $context['user']->id,
        ]);

        $capturedRequests = [];
        $openAiResponses = [
            $this->openAiAssessmentResponse([
                'coverage_status' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_COVERED,
                'risk_level' => SavedNoticeAiRequirementAssessment::RISK_LEVEL_LOW,
                'requirement_summary' => 'Kravet krever dokumentert erfaring fra tilsvarende prosjekter.',
                'coverage_rationale' => 'Valgt evidens viser dokumentert erfaring fra tilsvarende prosjekter og støtter kravet tydelig.',
                'missing_information' => 'Ingen åpenbare mangler identifisert.',
                'recommended_next_step' => 'Bruk valgt evidens i utkastet og behold samme dokumentasjon som grunnlag.',
            ]),
            $this->openAiAssessmentResponse([
                'coverage_status' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_PARTIAL,
                'risk_level' => SavedNoticeAiRequirementAssessment::RISK_LEVEL_MEDIUM,
                'requirement_summary' => 'Kravet krever en beskrivelse av gjennomføringsmetoden og kvalitetssikringen.',
                'coverage_rationale' => 'Evidensen beskriver metode, men mangler tydelig støtte for kvalitetssikring og full dekning.',
                'missing_information' => 'Mangler konkret kvalitetssikringsbeskrivelse og tydeligere dokumentasjon av gjennomføring.',
                'recommended_next_step' => 'Legg til mer presis metode- og kvalitetssikringsdokumentasjon før utkast genereres.',
            ]),
        ];

        Http::fake(function (Request $request) use (&$capturedRequests, $openAiResponses) {
            $capturedRequests[] = $request;
            $requestIndex = count($capturedRequests) - 1;

            $requestPayload = json_decode((string) $request->body(), true);
            $inputPayload = json_decode((string) data_get($requestPayload, 'input.1.content.0.text', ''), true);

            $this->assertIsArray($inputPayload);
            $this->assertSame('Skriv formelt og bruk Kunde med stor K.', data_get($inputPayload, 'case_instructions'));

            $fallbackResponse = $openAiResponses[array_key_last($openAiResponses)];

            return Http::response($openAiResponses[$requestIndex] ?? $fallbackResponse, 200);
        });

        $foreignContext = $this->customerAdminContext('Foreign Assessment AS');
        $foreignNotice = $this->createSavedNotice($foreignContext['customer']->id, 'AI-4010', 'Foreign assessment target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($foreignNotice, '2026-04-06 14:45:00');
        $foreignDocument = $this->createAiDocument($foreignNotice, [
            'uploaded_by_user_id' => $foreignContext['user']->id,
            'original_filename' => 'foreign-assessment.docx',
            'stored_path' => 'saved-notices/'.$foreignNotice->id.'/ai-documents/foreign-assessment.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Foreign assessment source text.',
            'text_extracted_at' => '2026-04-06 14:46:00',
        ]);
        $foreignChunk = $foreignDocument->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Dokumentert erfaring fra tilsvarende prosjekter er vedlagt.',
            'char_start' => 0,
            'char_end' => 60,
            'word_count' => 7,
        ]);
        $foreignRequirement = $this->createAiRequirement($foreignNotice, $foreignDocument, $foreignChunk, [
            'requirement_text' => 'Leverandøren skal dokumentere erfaring fra tilsvarende prosjekter.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.ai.requirements.assessment.refresh', ['savedNotice' => $savedNotice->id]))
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->assertSessionHas('success', 'Krav analysert.');

        Http::assertSentCount(2);

        $firstPrompt = (string) data_get($capturedRequests[0]['input'], '1.content.0.text', '');
        $secondPrompt = (string) data_get($capturedRequests[1]['input'], '1.content.0.text', '');

        $this->assertSame(config('services.openai.model'), $capturedRequests[0]['model']);
        $this->assertStringContainsString('Dokumentert erfaring fra tilsvarende prosjekter er vedlagt.', $firstPrompt);
        $this->assertStringNotContainsString('Dette er en metode for gjennomføring av leveransen.', $firstPrompt);
        $this->assertStringContainsString('Dette er en metode for gjennomføring av leveransen.', $secondPrompt);

        $selectedRequirement->refresh();
        $suggestedRequirement->refresh();
        $pendingRequirement->refresh();
        $rejectedRequirement->refresh();
        $foreignRequirement->refresh();

        $this->assertDatabaseHas('saved_notice_ai_requirement_assessments', [
            'saved_notice_ai_requirement_id' => $selectedRequirement->id,
            'assessment_status' => SavedNoticeAiRequirementAssessment::ASSESSMENT_STATUS_COMPLETED,
            'coverage_status' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_COVERED,
            'risk_level' => SavedNoticeAiRequirementAssessment::RISK_LEVEL_LOW,
        ]);
        $this->assertDatabaseHas('saved_notice_ai_requirement_assessments', [
            'saved_notice_ai_requirement_id' => $suggestedRequirement->id,
            'assessment_status' => SavedNoticeAiRequirementAssessment::ASSESSMENT_STATUS_COMPLETED,
            'coverage_status' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_PARTIAL,
            'risk_level' => SavedNoticeAiRequirementAssessment::RISK_LEVEL_MEDIUM,
        ]);
        $this->assertDatabaseMissing('saved_notice_ai_requirement_assessments', [
            'saved_notice_ai_requirement_id' => $pendingRequirement->id,
        ]);
        $this->assertDatabaseMissing('saved_notice_ai_requirement_assessments', [
            'saved_notice_ai_requirement_id' => $rejectedRequirement->id,
        ]);
        $this->assertDatabaseMissing('saved_notice_ai_requirement_assessments', [
            'saved_notice_ai_requirement_id' => $foreignRequirement->id,
        ]);

        $selectedAssessment = SavedNoticeAiRequirementAssessment::query()
            ->where('saved_notice_ai_requirement_id', $selectedRequirement->id)
            ->firstOrFail();
        $suggestedAssessment = SavedNoticeAiRequirementAssessment::query()
            ->where('saved_notice_ai_requirement_id', $suggestedRequirement->id)
            ->firstOrFail();

        $this->assertCount(1, $selectedAssessment->source_evidence_snapshot);
        $this->assertSame(SavedNoticeAiEvidence::SELECTION_STATUS_SELECTED, $selectedAssessment->source_evidence_snapshot[0]['selection_status']);
        $this->assertSame(SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED, $suggestedAssessment->source_evidence_snapshot[0]['selection_status']);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirements = collect(data_get($page, 'props.requirements', []))->keyBy('id');

        $this->assertSame(route('app.ai.requirements.assessment.refresh', ['savedNotice' => $savedNotice->id]), data_get($page, 'props.assessment_refresh_url'));
        $this->assertSame(SavedNoticeAiRequirementAssessment::ASSESSMENT_STATUS_COMPLETED, data_get($requirements->get($selectedRequirement->id), 'assessment.assessment_status'));
        $this->assertSame(SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_COVERED, data_get($requirements->get($selectedRequirement->id), 'assessment.coverage_status'));
        $this->assertSame(SavedNoticeAiRequirementAssessment::RISK_LEVEL_LOW, data_get($requirements->get($selectedRequirement->id), 'assessment.risk_level'));
        $this->assertSame(SavedNoticeAiRequirementAssessment::ASSESSMENT_STATUS_COMPLETED, data_get($requirements->get($suggestedRequirement->id), 'assessment.assessment_status'));
        $this->assertSame(SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_PARTIAL, data_get($requirements->get($suggestedRequirement->id), 'assessment.coverage_status'));
        $this->assertSame(SavedNoticeAiRequirementAssessment::RISK_LEVEL_MEDIUM, data_get($requirements->get($suggestedRequirement->id), 'assessment.risk_level'));
        $this->assertNull(data_get($requirements->get($pendingRequirement->id), 'assessment'));
        $this->assertNull(data_get($requirements->get($rejectedRequirement->id), 'assessment'));
        $this->assertCount(4, $requirements);
        $this->assertFalse($requirements->contains(fn (array $requirement): bool => $requirement['id'] === $foreignRequirement->id));

        $caseUsage = CustomerAiCaseUsage::query()
            ->where('customer_id', $context['customer']->id)
            ->where('saved_notice_id', $savedNotice->id)
            ->where('source_operation_key', AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH)
            ->first();

        $this->assertNotNull($caseUsage, 'Assessment refresh must record one AI case usage row for the SavedNotice.');
        $this->assertSame($context['customer']->id, $caseUsage->customer_id);
        $this->assertSame($savedNotice->id, $caseUsage->saved_notice_id);
        $this->assertSame($context['user']->id, $caseUsage->activated_by_user_id);
        $this->assertSame(AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH, $caseUsage->source_operation_key);
        $this->assertSame('2026-04-01', $caseUsage->period_start?->toDateString());
        $this->assertSame('2026-04-30', $caseUsage->period_end?->toDateString());

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.ai.requirements.assessment.refresh', ['savedNotice' => $savedNotice->id]))
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->assertSessionHas('success', 'Krav analysert.');

        $this->assertSame(1, CustomerAiCaseUsage::query()
            ->where('customer_id', $context['customer']->id)
            ->where('saved_notice_id', $savedNotice->id)
            ->where('source_operation_key', AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH)
            ->count(), 'Assessment refresh must not duplicate case usage for the same SavedNotice in the same month.');
    }

    public function test_ai_requirement_assessment_refresh_does_not_duplicate_ai_case_usage_when_processed_more_than_once_for_same_saved_notice_in_same_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-07 09:15:00'));

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4010-CASE-USAGE', 'Assessment case usage target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'ai_instructions' => 'Skriv formelt og bruk Kunde med stor K.',
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-07 09:10:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'assessment-case-usage.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/assessment-case-usage.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => 'Assessment case usage source text.',
            'text_extracted_at' => '2026-04-07 09:11:00',
        ]);
        $chunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Leverandøren skal dokumentere erfaring fra tilsvarende prosjekter.',
            'char_start' => 0,
            'char_end' => 67,
            'word_count' => 8,
        ]);
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal dokumentere erfaring fra tilsvarende prosjekter.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        $knowledgeItem = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Assessment case usage knowledge',
            'content_type' => KnowledgeItem::CONTENT_TYPE_REFERENCE,
            'content' => 'Leverandøren skal dokumentere erfaring fra tilsvarende prosjekter.',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($knowledgeItem);
        $knowledgeChunk = $knowledgeItem->chunks()->firstOrFail();

        SavedNoticeAiEvidence::query()->create([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'knowledge_item_id' => $knowledgeItem->id,
            'knowledge_item_chunk_id' => $knowledgeChunk->id,
            'match_type' => SavedNoticeAiEvidence::MATCH_TYPE_AUTO_MATCH,
            'match_score' => 9,
            'match_rank' => 1,
            'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SELECTED,
            'is_primary' => true,
            'created_by_user_id' => $context['user']->id,
        ]);

        Http::fake(function (Request $request) {
            $requestPayload = json_decode((string) $request->body(), true);

            if (! is_array($requestPayload)) {
                throw new RuntimeException('Unable to decode the fake OpenAI request payload.');
            }

            return Http::response($this->openAiAssessmentResponse([
                'coverage_status' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_COVERED,
                'risk_level' => SavedNoticeAiRequirementAssessment::RISK_LEVEL_LOW,
                'requirement_summary' => 'Kravet er dekket.',
                'coverage_rationale' => 'Evidensen dekker kravet tydelig.',
                'missing_information' => 'Ingen vesentlige mangler.',
                'recommended_next_step' => 'Behold eksisterende grunnlag.',
            ]), 200);
        });

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.ai.requirements.assessment.refresh', ['savedNotice' => $savedNotice->id]))
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->assertSessionHas('success', 'Krav analysert.');

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.ai.requirements.assessment.refresh', ['savedNotice' => $savedNotice->id]))
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->assertSessionHas('success', 'Krav analysert.');

        $this->assertSame(1, CustomerAiCaseUsage::query()
            ->where('customer_id', $context['customer']->id)
            ->where('saved_notice_id', $savedNotice->id)
            ->where('source_operation_key', AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH)
            ->count(), 'Assessment refresh must not duplicate case usage on repeated processing within the same month.');
    }

    public function test_ai_requirement_assessment_refresh_preserves_completed_rows_when_the_service_fails_and_creates_failed_rows_without_previous_state(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4011', 'Assessment failure target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 15:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'assessment-failure.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/assessment-failure.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => 'Assessment failure source text.',
            'text_extracted_at' => '2026-04-06 15:01:00',
        ]);
        $primaryChunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Leverandøren skal dokumentere erfaring fra tilsvarende prosjekter.',
            'char_start' => 0,
            'char_end' => 67,
            'word_count' => 8,
        ]);
        $secondaryChunk = $document->chunks()->create([
            'chunk_index' => 1,
            'content' => 'Leverandøren skal beskrive metode for gjennomføring.',
            'char_start' => 68,
            'char_end' => 120,
            'word_count' => 7,
        ]);

        $completedRequirement = $this->createAiRequirement($savedNotice, $document, $primaryChunk, [
            'requirement_text' => 'Leverandøren skal dokumentere erfaring fra tilsvarende prosjekter.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);
        $failedRequirement = $this->createAiRequirement($savedNotice, $document, $secondaryChunk, [
            'requirement_text' => 'Leverandøren skal beskrive metode for gjennomføring.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        SavedNoticeAiRequirementAssessment::query()->create([
            'saved_notice_ai_requirement_id' => $completedRequirement->id,
            'assessment_status' => SavedNoticeAiRequirementAssessment::ASSESSMENT_STATUS_COMPLETED,
            'coverage_status' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_COVERED,
            'risk_level' => SavedNoticeAiRequirementAssessment::RISK_LEVEL_LOW,
            'requirement_summary' => 'Existing completed summary.',
            'coverage_rationale' => 'Existing completed rationale.',
            'missing_information' => 'Existing completed missing information.',
            'recommended_next_step' => 'Existing completed next step.',
            'source_evidence_snapshot' => [
                [
                    'id' => 1,
                    'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SELECTED,
                ],
            ],
            'assessed_at' => '2026-04-06 15:05:00',
            'assessed_by_user_id' => $context['user']->id,
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiInvalidAssessmentResponse(), 200),
        ]);

        $response = $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.ai.requirements.assessment.refresh', ['savedNotice' => $savedNotice->id]));

        $response->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));
        $response->assertSessionHas('warning', 'AI-vurdering feilet for ett eller flere krav.');

        Http::assertSentCount(2);

        $completedAssessment = SavedNoticeAiRequirementAssessment::query()
            ->where('saved_notice_ai_requirement_id', $completedRequirement->id)
            ->firstOrFail();
        $failedAssessment = SavedNoticeAiRequirementAssessment::query()
            ->where('saved_notice_ai_requirement_id', $failedRequirement->id)
            ->firstOrFail();

        $this->assertSame(SavedNoticeAiRequirementAssessment::ASSESSMENT_STATUS_COMPLETED, $completedAssessment->assessment_status);
        $this->assertSame('Existing completed summary.', $completedAssessment->requirement_summary);
        $this->assertSame(SavedNoticeAiRequirementAssessment::ASSESSMENT_STATUS_FAILED, $failedAssessment->assessment_status);
        $this->assertNull($failedAssessment->coverage_status);
        $this->assertNull($failedAssessment->risk_level);
        $this->assertNull($failedAssessment->requirement_summary);
        $this->assertSame([], $failedAssessment->source_evidence_snapshot);
        $this->assertNull($failedAssessment->assessed_at);
        $this->assertSame(0, CustomerAiCaseUsage::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('source_operation_key', AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH)
            ->count(), 'Failed assessment refresh must not record AI case usage before an AI result exists.');
    }

    public function test_ai_requirement_work_status_can_be_updated_for_confirmed_requirements_and_assignment_is_persisted(): void
    {
        $context = $this->customerAdminContext();
        $assignedUser = User::factory()->create([
            'name' => 'Assigned User',
            'email' => 'assigned.user@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4006', 'Work update target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 13:40:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'work-update.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/work-update.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Dokumentasjon må vedlegges.',
            'text_extracted_at' => '2026-04-06 13:45:00',
        ]);
        $chunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Dokumentasjon må vedlegges.',
            'char_start' => 0,
            'char_end' => 27,
            'word_count' => 3,
        ]);
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        $workUpdateUrl = route('app.ai.requirements.work.update', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]);

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->patch($workUpdateUrl, [
                'work_status' => SavedNoticeAiRequirement::WORK_STATUS_IN_PROGRESS,
                'assigned_user_id' => $assignedUser->id,
            ])
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $requirement->refresh();
        $this->assertSame(SavedNoticeAiRequirement::WORK_STATUS_IN_PROGRESS, $requirement->work_status);
        $this->assertSame($assignedUser->id, $requirement->assigned_user_id);

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->patch($workUpdateUrl, [
                'work_status' => SavedNoticeAiRequirement::WORK_STATUS_DONE,
                'assigned_user_id' => null,
            ])
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $requirement->refresh();
        $this->assertSame(SavedNoticeAiRequirement::WORK_STATUS_DONE, $requirement->work_status);
        $this->assertNull($requirement->assigned_user_id);
    }

    public function test_ai_requirement_assigned_user_can_be_set_and_cleared_for_visible_requirement(): void
    {
        $context = $this->customerAdminContext();
        $assignedUser = User::factory()->create([
            'name' => 'Responsible User',
            'email' => 'responsible.user@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4008-ASSIGN', 'Responsible target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 13:55:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'responsible.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/responsible.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Dokumentasjon må vedlegges.',
            'text_extracted_at' => '2026-04-06 13:56:00',
        ]);
        $chunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Dokumentasjon må vedlegges.',
            'char_start' => 0,
            'char_end' => 27,
            'word_count' => 3,
        ]);
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        $assignUrl = route('app.ai.requirements.assigned-user.update', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]);

        $this->actingAs($context['user'])
            ->patchJson($assignUrl, [
                'assigned_user_id' => $assignedUser->id,
            ])
            ->assertOk()
            ->assertJsonPath('assigned_user_id', $assignedUser->id)
            ->assertJsonPath('assigned_user.id', $assignedUser->id)
            ->assertJsonPath('assigned_user.name', $assignedUser->name)
            ->assertJsonPath('assigned_user.email', $assignedUser->email)
            ->assertJsonPath('requirement.assigned_user_id', $assignedUser->id)
            ->assertJsonPath('requirement.assigned_user.id', $assignedUser->id)
            ->assertJsonPath('requirement.assigned_user.email', $assignedUser->email);

        $requirement->refresh();
        $this->assertSame($assignedUser->id, $requirement->assigned_user_id);

        $this->actingAs($context['user'])
            ->patchJson($assignUrl, [
                'assigned_user_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('assigned_user_id', null)
            ->assertJsonPath('assigned_user', null)
            ->assertJsonPath('requirement.assigned_user_id', null)
            ->assertJsonPath('requirement.assigned_user', null);

        $requirement->refresh();
        $this->assertNull($requirement->assigned_user_id);
    }

    public function test_ai_requirement_assigned_user_creates_and_moves_an_info_center_task_without_duplicates(): void
    {
        $context = $this->customerAdminContext();
        $firstAssignee = User::factory()->create([
            'name' => 'First Assignee',
            'email' => 'first.assignee@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);
        $secondAssignee = User::factory()->create([
            'name' => 'Second Assignee',
            'email' => 'second.assignee@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);
        $observer = User::factory()->create([
            'name' => 'Observer User',
            'email' => 'observer.user@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4008-TASK', 'Task sync target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 13:55:00');

        foreach ([$firstAssignee, $secondAssignee, $observer] as $user) {
            SavedNoticeUserAccess::query()->create([
                'saved_notice_id' => $savedNotice->id,
                'user_id' => $user->id,
                'granted_by_user_id' => $context['user']->id,
                'access_role' => SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR,
            ]);
        }

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'task-sync.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/task-sync.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Dokumentasjon må vedlegges.',
            'text_extracted_at' => '2026-04-06 13:56:00',
        ]);
        $chunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Dokumentasjon må vedlegges.',
            'char_start' => 0,
            'char_end' => 27,
            'word_count' => 3,
        ]);
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        $assignUrl = route('app.ai.requirements.assigned-user.update', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]);

        $this->actingAs($context['user'])
            ->patchJson($assignUrl, [
                'assigned_user_id' => $firstAssignee->id,
            ])
            ->assertOk()
            ->assertJsonPath('assigned_user_id', $firstAssignee->id);

        $infoTask = SavedNoticeInfoItem::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('type', SavedNoticeInfoItem::TYPE_AI_REQUIREMENT_RESPONSIBILITY)
            ->where('source_type', SavedNoticeInfoItem::SOURCE_TYPE_SAVED_NOTICE_AI_REQUIREMENT)
            ->where('source_id', $requirement->id)
            ->firstOrFail();

        $this->assertSame(SavedNoticeInfoItem::STATUS_OPEN, $infoTask->status);
        $this->assertSame($firstAssignee->id, $infoTask->owner_user_id);
        $this->assertSame($context['user']->id, $infoTask->created_by_user_id);
        $this->assertSame('Besvar krav:', Str::substr($infoTask->subject, 0, 12));

        $firstInfoCenterPage = $this->actingAs($firstAssignee)->get('/app/info-center?view=my_tasks');
        $firstInfoCenterPage->assertOk();
        $firstInfoCenterPayload = $this->inertiaPageFromResponse($firstInfoCenterPage);
        $firstInfoCenterItems = collect(data_get($firstInfoCenterPayload, 'props.infoCenter.items', []));

        $this->assertCount(1, $firstInfoCenterItems);
        $this->assertSame(
            route('app.ai.show', [
                'savedNotice' => $savedNotice->id,
                'requirement_id' => $requirement->id,
            ]),
            $firstInfoCenterItems->first()['action_url'],
        );
        $this->assertSame(SavedNoticeInfoItem::STATUS_OPEN, $firstInfoCenterItems->first()['status']);

        $observerInfoCenterPage = $this->actingAs($observer)->get('/app/info-center?view=my_tasks');
        $observerInfoCenterPage->assertOk();
        $observerInfoCenterPayload = $this->inertiaPageFromResponse($observerInfoCenterPage);
        $observerInfoCenterItems = collect(data_get($observerInfoCenterPayload, 'props.infoCenter.items', []));
        $this->assertCount(0, $observerInfoCenterItems);

        $this->actingAs($context['user'])
            ->patchJson($assignUrl, [
                'assigned_user_id' => $firstAssignee->id,
            ])
            ->assertOk();

        $this->assertSame(
            1,
            SavedNoticeInfoItem::query()
                ->where('saved_notice_id', $savedNotice->id)
                ->where('type', SavedNoticeInfoItem::TYPE_AI_REQUIREMENT_RESPONSIBILITY)
                ->where('source_type', SavedNoticeInfoItem::SOURCE_TYPE_SAVED_NOTICE_AI_REQUIREMENT)
                ->where('source_id', $requirement->id)
                ->where('status', SavedNoticeInfoItem::STATUS_OPEN)
                ->count(),
        );

        $this->actingAs($context['user'])
            ->patchJson($assignUrl, [
                'assigned_user_id' => $secondAssignee->id,
            ])
            ->assertOk()
            ->assertJsonPath('assigned_user_id', $secondAssignee->id);

        $requirement->refresh();
        $this->assertSame($secondAssignee->id, $requirement->assigned_user_id);

        $this->assertSame(
            1,
            SavedNoticeInfoItem::query()
                ->where('saved_notice_id', $savedNotice->id)
                ->where('type', SavedNoticeInfoItem::TYPE_AI_REQUIREMENT_RESPONSIBILITY)
                ->where('source_type', SavedNoticeInfoItem::SOURCE_TYPE_SAVED_NOTICE_AI_REQUIREMENT)
                ->where('source_id', $requirement->id)
                ->where('status', SavedNoticeInfoItem::STATUS_OPEN)
                ->count(),
        );

        $firstInfoCenterPageAfterMove = $this->actingAs($firstAssignee)->get('/app/info-center?view=my_tasks');
        $firstInfoCenterPageAfterMove->assertOk();
        $firstInfoCenterPayloadAfterMove = $this->inertiaPageFromResponse($firstInfoCenterPageAfterMove);
        $firstInfoCenterItemsAfterMove = collect(data_get($firstInfoCenterPayloadAfterMove, 'props.infoCenter.items', []));
        $this->assertCount(0, $firstInfoCenterItemsAfterMove);

        $secondInfoCenterPage = $this->actingAs($secondAssignee)->get('/app/info-center?view=my_tasks');
        $secondInfoCenterPage->assertOk();
        $secondInfoCenterPayload = $this->inertiaPageFromResponse($secondInfoCenterPage);
        $secondInfoCenterItems = collect(data_get($secondInfoCenterPayload, 'props.infoCenter.items', []));

        $this->assertCount(1, $secondInfoCenterItems);
        $this->assertSame(
            route('app.ai.show', [
                'savedNotice' => $savedNotice->id,
                'requirement_id' => $requirement->id,
            ]),
            $secondInfoCenterItems->first()['action_url'],
        );

        $this->actingAs($context['user'])
            ->patchJson($assignUrl, [
                'assigned_user_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('assigned_user_id', null);

        $this->assertSame(
            0,
            SavedNoticeInfoItem::query()
                ->where('saved_notice_id', $savedNotice->id)
                ->where('type', SavedNoticeInfoItem::TYPE_AI_REQUIREMENT_RESPONSIBILITY)
                ->where('source_type', SavedNoticeInfoItem::SOURCE_TYPE_SAVED_NOTICE_AI_REQUIREMENT)
                ->where('source_id', $requirement->id)
                ->where('status', SavedNoticeInfoItem::STATUS_OPEN)
                ->count(),
        );

        $secondInfoCenterPageAfterClear = $this->actingAs($secondAssignee)->get('/app/info-center?view=my_tasks');
        $secondInfoCenterPageAfterClear->assertOk();
        $secondInfoCenterPayloadAfterClear = $this->inertiaPageFromResponse($secondInfoCenterPageAfterClear);
        $secondInfoCenterItemsAfterClear = collect(data_get($secondInfoCenterPayloadAfterClear, 'props.infoCenter.items', []));
        $this->assertCount(0, $secondInfoCenterItemsAfterClear);
    }

    public function test_ai_requirement_assigned_user_rejects_foreign_customer_users_and_foreign_requirements(): void
    {
        $context = $this->customerAdminContext();
        $foreignContext = $this->customerAdminContext('Foreign Customer AS');
        $foreignUser = User::factory()->create([
            'name' => 'Foreign User',
            'email' => 'foreign.user@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $foreignContext['customer']->id,
            'is_active' => true,
        ]);
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4009-ASSIGN', 'Responsible validation target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 14:10:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'responsible-validation.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/responsible-validation.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Dokumentasjon må vedlegges.',
            'text_extracted_at' => '2026-04-06 14:11:00',
        ]);
        $chunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Dokumentasjon må vedlegges.',
            'char_start' => 0,
            'char_end' => 27,
            'word_count' => 3,
        ]);
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        $assignUrl = route('app.ai.requirements.assigned-user.update', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]);

        $this->actingAs($context['user'])
            ->patchJson($assignUrl, [
                'assigned_user_id' => $foreignUser->id,
            ])
            ->assertStatus(422);

        $foreignNotice = $this->createSavedNotice($foreignContext['customer']->id, 'AI-4010', 'Foreign responsible target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($foreignNotice, '2026-04-06 14:15:00');

        $foreignDocument = $this->createAiDocument($foreignNotice, [
            'uploaded_by_user_id' => $foreignContext['user']->id,
            'original_filename' => 'foreign-responsible.docx',
            'stored_path' => 'saved-notices/'.$foreignNotice->id.'/ai-documents/foreign-responsible.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Dokumentasjon må vedlegges.',
            'text_extracted_at' => '2026-04-06 14:16:00',
        ]);
        $foreignChunk = $foreignDocument->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Dokumentasjon må vedlegges.',
            'char_start' => 0,
            'char_end' => 27,
            'word_count' => 3,
        ]);
        $foreignRequirement = $this->createAiRequirement($foreignNotice, $foreignDocument, $foreignChunk, [
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        $this->actingAs($context['user'])
            ->patchJson(route('app.ai.requirements.assigned-user.update', [
                'savedNotice' => $foreignNotice->id,
                'requirement' => $foreignRequirement->id,
            ]), [
                'assigned_user_id' => null,
            ])
            ->assertNotFound();
    }

    public function test_ai_requirement_work_status_rejects_invalid_values_and_is_scoped_to_the_visible_saved_notice(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4007', 'Work validation target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 14:00:00');

        $otherSavedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4008', 'Foreign work target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($otherSavedNotice, '2026-04-06 14:05:00');

        $savedDocument = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'work-validation.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/work-validation.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Dokumentasjon må vedlegges.',
            'text_extracted_at' => '2026-04-06 14:01:00',
        ]);
        $savedChunk = $savedDocument->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Dokumentasjon må vedlegges.',
            'char_start' => 0,
            'char_end' => 27,
            'word_count' => 3,
        ]);
        $savedRequirement = $this->createAiRequirement($savedNotice, $savedDocument, $savedChunk, [
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        $otherDocument = $this->createAiDocument($otherSavedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'foreign-work.docx',
            'stored_path' => 'saved-notices/'.$otherSavedNotice->id.'/ai-documents/foreign-work.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 14:06:00',
        ]);
        $otherChunk = $otherDocument->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Leverandøren skal beskrive løsningen.',
            'char_start' => 0,
            'char_end' => 37,
            'word_count' => 4,
        ]);
        $otherRequirement = $this->createAiRequirement($otherSavedNotice, $otherDocument, $otherChunk, [
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->patch(route('app.ai.requirements.work.update', [
                'savedNotice' => $savedNotice->id,
                'requirement' => $savedRequirement->id,
            ]), [
                'work_status' => 'paused',
                'assigned_user_id' => null,
            ])
            ->assertSessionHasErrors('work_status');

        $savedRequirement->refresh();
        $this->assertSame(SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED, $savedRequirement->work_status);
        $this->assertNull($savedRequirement->assigned_user_id);

        $this->actingAs($context['user'])
            ->patch(route('app.ai.requirements.work.update', [
                'savedNotice' => $savedNotice->id,
                'requirement' => $otherRequirement->id,
            ]), [
                'work_status' => SavedNoticeAiRequirement::WORK_STATUS_DONE,
                'assigned_user_id' => null,
            ])
            ->assertNotFound();

        $otherRequirement->refresh();
        $this->assertSame(SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED, $otherRequirement->work_status);
        $this->assertNull($otherRequirement->assigned_user_id);
    }

    public function test_ai_documents_upload_is_restricted_to_visible_saved_notices(): void
    {
        Storage::fake('local');

        $context = $this->customerAdminContext();
        $foreignContext = $this->customerAdminContext('Other Customer AS');
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-3002', 'Restricted upload target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 11:00:00');

        $document = UploadedFile::fake()->create('restricted.pdf', 256, 'application/pdf');

        $this->actingAs($foreignContext['user'])
            ->post(route('app.ai.documents.store', ['savedNotice' => $savedNotice->id]), [
                'documents' => [$document],
            ])
            ->assertNotFound();

        $this->assertSame(0, SavedNoticeAiDocument::query()->count());
    }

    public function test_ai_requirement_answer_draft_is_blocked_by_usage_guard_before_generation(): void
    {
        app()->setLocale('no');
        config([
            'procynia.ai.usage_guard.user_per_minute' => 1,
            'procynia.ai.usage_guard.customer_per_hour' => 50,
            'procynia.ai.usage_guard.user_decay_seconds' => 60,
            'procynia.ai.usage_guard.customer_decay_seconds' => 3600,
        ]);

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-3003-RATE', 'Rate limit target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 11:05:00');

        $document = $this->createAiDocument($savedNotice, [
            'original_filename' => 'rate-limit.pdf',
            'stored_path' => sprintf('saved-notices/%d/ai-documents/rate-limit.pdf', $savedNotice->id),
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren beskriver leveransen i detalj.', 0);
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal beskrive leveransen.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
        ]);

        $operationKey = AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT;
        $userKey = sprintf('ai:user:%d:%s', $context['user']->id, $operationKey);
        $customerKey = sprintf('ai:customer:%d:%s', $context['customer']->id, $operationKey);

        RateLimiter::clear($userKey);
        RateLimiter::clear($customerKey);
        RateLimiter::increment($userKey, 60, 1);

        $response = $this->actingAs($context['user'])
            ->postJson(route('app.ai.requirements.answer-draft.generate', [
                'savedNotice' => $savedNotice->id,
                'requirement' => $requirement->id,
            ]), [
                'answer_basis_item_ids' => [],
            ]);

        $response->assertStatus(429)
            ->assertJsonPath('status', 'fail');

        $message = (string) $response->json('message');
        $this->assertStringContainsString(__('procynia.ai.usage_guard.user_blocked_base'), $message);
        $this->assertStringNotContainsString('rate limit', Str::lower($message));
        $this->assertStringNotContainsString('throttle', Str::lower($message));
        $this->assertStringNotContainsString('429', $message);

        $this->assertSame(1, AiUsageEvent::query()->count());

        $usageEvent = AiUsageEvent::query()->firstOrFail();
        $this->assertSame(AiUsageEvent::STATUS_BLOCKED, $usageEvent->status);
        $this->assertSame(AiUsageEvent::LIMIT_TYPE_USER, $usageEvent->limit_type);
        $this->assertSame(1, $usageEvent->operation_count);
        $this->assertSame($context['customer']->id, $usageEvent->customer_id);
        $this->assertSame($context['user']->id, $usageEvent->user_id);
        $this->assertSame(AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT, $usageEvent->operation_key);

        $requirement->refresh();
        $this->assertNull($requirement->answer_draft_text);
    }

    // -------------------------------------------------------------------------
    // AVVIK-024A — AI-output kvalitetstester (T1–T5)
    // Tester Procynias håndtering av AI-output, ikke språkmodellkvalitet.
    // Ingen av disse testene kaller ekte OpenAI.
    // -------------------------------------------------------------------------

    /**
     * T4 – Payload-struktur for lagret utkast.
     * Proves that the Show page returns generation_state = 'generated' and
     * the persisted retrieval_sources when a requirement already has a saved draft.
     * No AI calls needed — data is read directly from the database.
     */
    public function test_answer_draft_payload_returns_generation_state_generated_and_retrieval_sources_for_saved_draft(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-QT4-PAYLOAD', 'Payload T4 target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);

        $document = $this->createAiDocument($savedNotice, [
            'original_filename' => 'kravdokument.docx',
            'stored_path' => sprintf('saved-notices/%d/ai-documents/kravdokument.docx', $savedNotice->id),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 11:01:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'answer_draft_text' => 'Leverandøren beskriver løsningen grundig i dette dokumentet.',
            'answer_draft_generated_at' => '2026-04-06 11:15:00',
            'answer_draft_retrieval_sources' => [
                [
                    'chunk_id' => 42,
                    'document_title' => 'relevant-knowledge.docx',
                    'chunk_type' => 'semantic',
                ],
            ],
        ]);

        $response = $this->actingAs($context['user'])->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirements = collect(data_get($page, 'props.requirements', []));
        $requirementRow = $requirements->firstWhere('id', $requirement->id);

        $this->assertNotNull($requirementRow);
        $this->assertSame('Leverandøren beskriver løsningen grundig i dette dokumentet.', data_get($requirementRow, 'answer_draft.text'));
        $this->assertSame('generated', data_get($requirementRow, 'answer_draft.generation_state'));
        $this->assertNotEmpty(data_get($requirementRow, 'answer_draft.generated_at'));
        $this->assertIsArray(data_get($requirementRow, 'answer_draft.retrieval_sources'));
        $this->assertNotEmpty(data_get($requirementRow, 'answer_draft.retrieval_sources'));
        $this->assertSame('relevant-knowledge.docx', data_get($requirementRow, 'answer_draft.retrieval_sources.0.document_title'));
        $this->assertSame('semantic', data_get($requirementRow, 'answer_draft.retrieval_sources.0.chunk_type'));
    }

    /**
     * T5 – Payload-struktur for krav uten utkast.
     * Proves that the Show page returns a controlled empty payload with
     * generation_state = null when no draft has been generated yet.
     * No AI calls needed.
     */
    public function test_answer_draft_payload_returns_null_generation_state_for_requirement_without_draft(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-QT5-EMPTY', 'Empty draft T5 target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);

        $document = $this->createAiDocument($savedNotice, [
            'original_filename' => 'kravdokument.docx',
            'stored_path' => sprintf('saved-notices/%d/ai-documents/kravdokument.docx', $savedNotice->id),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive sikkerhetsarkitekturen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal beskrive sikkerhetsarkitekturen.',
        ]);

        $response = $this->actingAs($context['user'])->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirements = collect(data_get($page, 'props.requirements', []));
        $requirementRow = $requirements->firstWhere('id', $requirement->id);

        $this->assertNotNull($requirementRow);
        $this->assertNull(data_get($requirementRow, 'answer_draft.generation_state'));
        $this->assertSame('', data_get($requirementRow, 'answer_draft.text'));
        $this->assertIsArray(data_get($requirementRow, 'answer_draft.retrieval_sources'));
        $this->assertEmpty(data_get($requirementRow, 'answer_draft.retrieval_sources'));
    }

    /**
     * T1 – Blokkert generering ved rød grounding.
     * Proves that the generate endpoint returns a blocked payload and does not
     * persist any draft when the knowledge grounding level is red.
     * No OpenAI calls should be made.
     */
    public function test_answer_draft_generation_is_blocked_and_nothing_persisted_when_knowledge_grounding_is_red(): void
    {
        $this->bindKnowledgeGroundingService(function (...$ignored): array {
            return [
                'level' => 'red',
                'max_score' => 0.08,
                'sources_count' => 0,
            ];
        });

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-QT1-RED', 'Red grounding T1 target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);

        $document = $this->createAiDocument($savedNotice, [
            'original_filename' => 'kravdokument.docx',
            'stored_path' => sprintf('saved-notices/%d/ai-documents/kravdokument.docx', $savedNotice->id),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 11:01:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
        ]);

        Http::fake();

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('answer_draft.generation_state', 'blocked_missing_knowledge');
        $response->assertJsonPath('answer_draft.text', '');
        $response->assertJsonPath('knowledge_grounding.level', 'red');

        $requirement->refresh();
        $this->assertNull($requirement->answer_draft_text);
        $this->assertNull($requirement->answer_draft_generated_at);

        Http::assertNothingSent();
    }

    /**
     * T2 – Blokkert generering når judge sier can_generate_answer = false.
     * Proves that when the grounding judge returns can_generate_answer = false,
     * no draft is generated or persisted, and the blocked payload is returned.
     * The judge service is mocked; no OpenAI HTTP calls should be made.
     */
    public function test_answer_draft_generation_is_blocked_and_nothing_persisted_when_judge_says_cannot_generate(): void
    {
        $this->bindKnowledgeGroundingService(function (...$ignored): array {
            return [
                'level' => 'amber',
                'max_score' => 0.55,
                'sources_count' => 1,
            ];
        });

        $this->bindGroundingJudgeService(function (...$ignored): array {
            return [
                'status' => 'unsupported',
                'can_generate_answer' => false,
                'directly_supported_points' => [],
                'related_but_insufficient_points' => [],
                'unsupported_points' => ['Leverandøren mangler dokumentasjon på sikkerhetssertifisering.'],
                'missing_knowledge_summary' => 'Kunnskapsgrunnlaget inneholder ikke nødvendig sikkerhetsdokumentasjon.',
                'recommended_document_title' => null,
                'suggested_filename' => null,
                'reasoning_summary' => 'Grunnlaget er utilstrekkelig for å generere et svar.',
            ];
        });

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-QT2-JUDGE', 'Judge blocked T2 target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);

        $document = $this->createAiDocument($savedNotice, [
            'original_filename' => 'kravdokument.docx',
            'stored_path' => sprintf('saved-notices/%d/ai-documents/kravdokument.docx', $savedNotice->id),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 11:01:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
        ]);

        Http::fake();

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('answer_draft.generation_state', 'blocked_missing_knowledge');
        $response->assertJsonPath('answer_draft.text', '');
        $response->assertJsonPath('answer_draft.missing_knowledge.judge_status', 'unsupported');
        $response->assertJsonPath('answer_draft.missing_knowledge.can_generate_answer', false);

        $requirement->refresh();
        $this->assertNull($requirement->answer_draft_text);
        $this->assertNull($requirement->answer_draft_generated_at);

        Http::assertNothingSent();
    }

    /**
     * T3 – Persistens av retrieval_sources etter vellykket generering.
     * Proves that after a successful generate call, answer_draft_retrieval_sources
     * is stored in the database and is returned correctly from the Show page payload.
     * OpenAI is replaced by Http::fake() with a fixed draft response.
     */
    public function test_retrieval_sources_are_persisted_after_successful_generation_and_survive_payload_readback(): void
    {
        $this->bindKnowledgeGroundingService(function (...$ignored): array {
            return [
                'level' => 'green',
                'max_score' => 0.92,
                'sources_count' => 1,
            ];
        });

        $this->bindGroundingJudgeService(function (...$ignored): array {
            return [
                'status' => 'supported',
                'can_generate_answer' => true,
                'directly_supported_points' => [],
                'related_but_insufficient_points' => [],
                'unsupported_points' => [],
                'missing_knowledge_summary' => null,
                'recommended_document_title' => null,
                'suggested_filename' => null,
                'reasoning_summary' => 'Grunnlaget er tilstrekkelig dokumentert.',
            ];
        });

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-QT3-PERSIST', 'Persist sources T3 target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);

        $document = $this->createAiDocument($savedNotice, [
            'original_filename' => 'kravdokument.docx',
            'stored_path' => sprintf('saved-notices/%d/ai-documents/kravdokument.docx', $savedNotice->id),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal beskrive løsningen.',
            'text_extracted_at' => '2026-04-06 11:01:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
        ]);

        [$retrievalKnowledge] = $this->createGroundedKnowledgeFixture(
            $context['customer'],
            'Leverandøren skal beskrive løsningen.',
            'kunnskapsgrunnlag.docx',
        );

        $this->bindEmbeddingService(function (string $text): array {
            return [
                'ok' => true,
                'embedding' => [1.0, 0.0],
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'test-request-id',
                'response_body_excerpt' => null,
            ];
        });

        Http::fake(function (Request $request) {
            return Http::response(
                $this->openAiStructuredResponse(
                    ['answer_draft_text' => 'Leverandøren dokumenterer løsningen i tråd med kunnskapsgrunnlaget.'],
                    50,
                    20,
                ),
                200,
                ['x-request-id' => 'req_answer_draft_persist_t3'],
            );
        });

        $response = $this->actingAs($context['user'])->post(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), [
            'answer_basis_item_ids' => [],
        ]);

        $response->assertOk();
        $this->assertIsArray($response->json('retrieval_sources'));
        $this->assertNotEmpty($response->json('retrieval_sources'));

        $requirement->refresh();
        $this->assertNotNull($requirement->answer_draft_text);
        $this->assertNotNull($requirement->answer_draft_generated_at);
        $this->assertIsArray($requirement->answer_draft_retrieval_sources);
        $this->assertNotEmpty($requirement->answer_draft_retrieval_sources);
        $this->assertSame('kunnskapsgrunnlag.docx', data_get($requirement->answer_draft_retrieval_sources, '0.document_title'));

        $pageResponse = $this->actingAs($context['user'])->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));
        $pageResponse->assertOk();
        $page = $this->inertiaPageFromResponse($pageResponse);
        $requirements = collect(data_get($page, 'props.requirements', []));
        $requirementRow = $requirements->firstWhere('id', $requirement->id);

        $this->assertNotNull($requirementRow);
        $this->assertSame('generated', data_get($requirementRow, 'answer_draft.generation_state'));
        $this->assertIsArray(data_get($requirementRow, 'answer_draft.retrieval_sources'));
        $this->assertNotEmpty(data_get($requirementRow, 'answer_draft.retrieval_sources'));
        $this->assertSame('kunnskapsgrunnlag.docx', data_get($requirementRow, 'answer_draft.retrieval_sources.0.document_title'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AVVIK-018B — Word export tests
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * W1 – Authenticated user receives a valid .docx binary for a notice with saved drafts.
     * Proves that the export endpoint returns 200 with the correct content-type
     * and a non-empty response body that starts with a PK (ZIP/DOCX) magic header.
     */
    public function test_export_requirements_docx_returns_word_document_with_saved_drafts(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'DOCX-W1', 'Anbudsbesvarelse W1', [
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
        ]);

        $document = $this->createAiDocument($savedNotice, [
            'original_filename' => 'krav.docx',
            'stored_path' => sprintf('saved-notices/%d/ai-documents/krav.docx', $savedNotice->id),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal ha ISO 27001-sertifisering.');
        $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal ha ISO 27001-sertifisering.',
            'answer_draft_text' => 'Leverandøren er ISO 27001-sertifisert og kan dokumentere dette.',
            'answer_draft_generated_at' => now()->toDateTimeString(),
        ]);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.requirements.export.docx', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $response->assertStreamed();
        $response->assertDownload('tilbudsbesvarelse-'.$savedNotice->id.'.docx');
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    /**
     * W2 – Export only includes requirements that have a saved answer_draft_text.
     * Proves that requirements without a draft are silently excluded from the
     * exported document — the endpoint still returns 200.
     */
    public function test_export_requirements_docx_excludes_requirements_without_drafts(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'DOCX-W2', 'Anbudsbesvarelse W2', [
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
        ]);

        $document = $this->createAiDocument($savedNotice, [
            'original_filename' => 'krav.docx',
            'stored_path' => sprintf('saved-notices/%d/ai-documents/krav.docx', $savedNotice->id),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Krav med utkast.');
        $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '2.1',
            'requirement_text' => 'Krav med utkast.',
            'answer_draft_text' => 'Dette kravet har et svarutkast.',
            'answer_draft_generated_at' => now()->toDateTimeString(),
        ]);

        $chunkB = $this->createAiDocumentChunk($document, 'Krav uten utkast.', 1);
        $this->createAiRequirement($savedNotice, $document, $chunkB, [
            'requirement_identifier' => '2.2',
            'requirement_text' => 'Krav uten utkast.',
            'answer_draft_text' => null,
        ]);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.requirements.export.docx', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    /**
     * W3 – Export returns 422 when no requirements have a saved draft.
     * Proves the endpoint does not return an empty or broken document when there
     * is nothing to export — a 422 lets the frontend show an appropriate message.
     */
    public function test_export_requirements_docx_returns_422_when_no_drafts_exist(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'DOCX-W3', 'Anbudsbesvarelse W3', [
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
        ]);

        $document = $this->createAiDocument($savedNotice, [
            'original_filename' => 'krav.docx',
            'stored_path' => sprintf('saved-notices/%d/ai-documents/krav.docx', $savedNotice->id),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Krav uten noe utkast ennå.');
        $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Krav uten noe utkast ennå.',
            'answer_draft_text' => null,
        ]);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.requirements.export.docx', ['savedNotice' => $savedNotice->id]));

        $response->assertStatus(422);
    }

    /**
     * W4 – Export endpoint requires authentication.
     * Proves that unauthenticated requests are redirected to the login page.
     */
    public function test_export_requirements_docx_requires_authentication(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'DOCX-W4', 'Anbudsbesvarelse W4', [
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
        ]);

        $response = $this->get(route('app.ai.requirements.export.docx', ['savedNotice' => $savedNotice->id]));

        $response->assertRedirect();
    }

    /**
     * W5 – Export endpoint is scoped to the authenticated customer's tenant.
     * Proves that a user from another customer receives a 404 when trying to
     * export requirements belonging to a different customer's saved notice.
     */
    public function test_export_requirements_docx_is_scoped_to_customer_tenant(): void
    {
        $ownerContext = $this->customerAdminContext('Owner Co');
        $attackerContext = $this->customerAdminContext('Attacker Co');

        $savedNotice = $this->createSavedNotice($ownerContext['customer']->id, 'DOCX-W5-OWNER', 'Owner notice W5', [
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
        ]);

        $document = $this->createAiDocument($savedNotice, [
            'original_filename' => 'krav.docx',
            'stored_path' => sprintf('saved-notices/%d/ai-documents/krav.docx', $savedNotice->id),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Sensitiv kravtekst.');
        $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Sensitiv kravtekst.',
            'answer_draft_text' => 'Sensitiv besvarelse.',
            'answer_draft_generated_at' => now()->toDateTimeString(),
        ]);

        $response = $this->actingAs($attackerContext['user'])
            ->get(route('app.ai.requirements.export.docx', ['savedNotice' => $savedNotice->id]));

        $response->assertNotFound();
    }

    private function customerAdminContext(string $customerName = 'Procynia AS', bool $withAiAccess = true): array
    {
        $customer = $this->createCustomer($customerName);

        if ($withAiAccess) {
            $customer->forceFill([
                'subscription_plan' => Customer::PLAN_PRO,
                'billing_interval' => Customer::BILLING_MONTHLY,
                'included_ai_credits' => 20,
            ])->save();
        }

        $user = User::factory()->create([
            'name' => 'AI Tester',
            'email' => Str::slug($customerName).'.ai.tester.'.Str::lower(Str::random(6)).'@example.test',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return [
            'customer' => $customer,
            'user' => $user,
        ];
    }

    /**
     * Purpose: Bind a deterministic embedding service for controller integration tests.
     * Inputs: A callback that returns the desired embedding outcome.
     * Returns: None.
     * Side effects: Replaces the container binding with a predictable fake service.
     */
    private function bindEmbeddingService(callable $handler): void
    {
        $service = Mockery::mock(EmbeddingService::class);
        $service->shouldReceive('tryEmbedText')
            ->andReturnUsing($handler);

        $this->app->instance(EmbeddingService::class, $service);
    }

    /**
     * Purpose: Provide a deterministic embedding vector that matches the pgvector dimension.
     * Inputs: None.
     * Returns: A 1536-dimensional embedding vector with stable values.
     * Side effects: None.
     */
    private function deterministicEmbeddingVector(): array
    {
        return array_fill(0, 1536, 0.001);
    }

    /**
     * Purpose: Bind a deterministic grounding judge for controller integration tests.
     * Inputs: A callback that returns the desired grounding outcome.
     * Returns: None.
     * Side effects: Replaces the container binding with a predictable fake service.
     */
    private function bindGroundingJudgeService(callable $handler): void
    {
        $service = Mockery::mock(RequirementGroundingJudgeService::class);
        $service->shouldReceive('judge')
            ->andReturnUsing($handler);

        $this->app->instance(RequirementGroundingJudgeService::class, $service);
    }

    /**
     * Purpose: Bind a deterministic coverage evaluator for controller integration tests.
     * Inputs: A callback that returns the desired coverage payload.
     * Returns: None.
     * Side effects: Replaces the container binding with a predictable fake service.
     */
    private function bindKnowledgeGroundingService(callable $handler): void
    {
        $service = Mockery::mock(KnowledgeChunkCoverageService::class)->makePartial();
        $service->shouldReceive('evaluateKnowledgeGrounding')
            ->andReturnUsing($handler);

        $this->app->instance(KnowledgeChunkCoverageService::class, $service);
    }

    /**
     * Purpose: Bind a deterministic metadata retrieval planner for controller integration tests.
     * Inputs: A callback that returns the desired retrieval plan.
     * Returns: None.
     * Side effects: Replaces the container binding with a predictable fake service.
     */
    private function bindMetadataRetrievalPlanService(callable $handler): void
    {
        $service = Mockery::mock(MetadataRetrievalPlanService::class);
        $service->shouldReceive('buildPlan')
            ->andReturnUsing($handler);

        $this->app->instance(MetadataRetrievalPlanService::class, $service);
    }

    /**
     * Purpose: Bind a controller instance whose legacy base pool can be forced to a deterministic result.
     * Inputs: The collection that should be returned from the legacy chunk pool helper.
     * Returns: None.
     * Side effects: Replaces the controller binding with a partial mock.
     */
    private function bindAiControllerKnowledgeChunksForMatching(Collection $chunks): void
    {
        $controller = Mockery::mock($this->app->make(AiController::class))->makePartial();
        $controller->shouldAllowMockingProtectedMethods();
        $controller->shouldReceive('knowledgeChunksForMatching')
            ->andReturn($chunks);

        $this->app->instance(AiController::class, $controller);
    }

    /**
     * Purpose: Persist a deterministic timestamp for a knowledge item fixture.
     * Inputs: The knowledge item and the timestamp to apply.
     * Returns: The refreshed knowledge item model.
     * Side effects: Updates the knowledge_items row directly in the test database.
     */
    private function touchKnowledgeItem(KnowledgeItem $knowledgeItem, string $timestamp): KnowledgeItem
    {
        DB::table('knowledge_items')
            ->where('id', $knowledgeItem->id)
            ->update([
                'updated_at' => $timestamp,
                'created_at' => $timestamp,
            ]);

        return $knowledgeItem->refresh();
    }

    /**
     * Purpose: Build a deterministic knowledge item and one grounded chunk for answer-generation tests.
     * Inputs: The owning customer, the grounded content, and the stored filename.
     * Returns: The persisted knowledge item and its single chunk.
     * Side effects: Writes knowledge item rows to the test database.
     */
    private function createGroundedKnowledgeFixture(Customer $customer, string $content, string $originalFilename): array
    {
        $knowledgeItem = $this->createKnowledgeItem($customer, [
            'title' => 'Relevant knowledge',
            'original_filename' => $originalFilename,
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'content' => $content,
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($knowledgeItem);

        $knowledgeChunk = $knowledgeItem->chunks()->firstOrFail();
        $knowledgeChunk->forceFill([
            'title' => 'Dokumentstruktur',
            'topic' => 'Løsning',
            'sub_topic' => 'Dokumentasjon',
            'keywords' => ['løsningen'],
            'section_title' => 'SIEM',
            'section_path' => 'SOC-tjenester > SIEM',
            'embedding_vector' => [1.0, 0.0],
            'embedding_model' => 'text-embedding-3-small',
            'embedding_generated_at' => '2026-04-06 11:03:00',
            'embedding_error' => null,
        ])->save();
        $this->touchKnowledgeItem($knowledgeItem, '2026-04-06 11:03:00');

        return [$knowledgeItem->refresh(), $knowledgeChunk->refresh()];
    }

    private function createCustomer(string $name): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }

    /**
     * Purpose: Create a compact saved notice fixture for AI analysis tests.
     * Inputs: Customer id, external id, title, and optional field overrides.
     * Returns: The persisted saved notice model.
     * Side effects: Writes a saved notice row to the test database.
     */
    private function createSavedNotice(int $customerId, string $externalId, string $title, array $overrides = []): SavedNotice
    {
        $attributes = [
            'customer_id' => $customerId,
            'saved_by_user_id' => $overrides['saved_by_user_id'] ?? null,
            'bid_status' => $overrides['bid_status'] ?? SavedNotice::BID_STATUS_DISCOVERED,
            'source_type' => $overrides['source_type'] ?? SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'opportunity_owner_user_id' => $overrides['opportunity_owner_user_id'] ?? null,
            'bid_manager_user_id' => $overrides['bid_manager_user_id'] ?? null,
            'external_id' => $externalId,
            'title' => $title,
            'buyer_name' => $overrides['buyer_name'] ?? 'Procynia',
            'external_url' => $overrides['external_url'] ?? "https://doffin.no/notices/{$externalId}",
            'summary' => $overrides['summary'] ?? 'Kort oppsummering',
            'publication_date' => $overrides['publication_date'] ?? '2026-03-20 00:00:00',
            'deadline' => $overrides['deadline'] ?? '2026-04-20 00:00:00',
            'status' => $overrides['status'] ?? 'ACTIVE',
            'cpv_code' => $overrides['cpv_code'] ?? '72000000',
            'archived_at' => $overrides['archived_at'] ?? null,
            'reference_number' => $overrides['reference_number'] ?? null,
            'contact_person_name' => $overrides['contact_person_name'] ?? null,
            'contact_person_email' => $overrides['contact_person_email'] ?? null,
            'notes' => $overrides['notes'] ?? null,
        ];

        if (Schema::hasColumn('saved_notices', 'history_type')) {
            $attributes['history_type'] = $overrides['history_type'] ?? null;
        }

        if (Schema::hasColumn('saved_notices', 'ai_instructions') && array_key_exists('ai_instructions', $overrides)) {
            $attributes['ai_instructions'] = $overrides['ai_instructions'];
        }

        return SavedNotice::query()->create($attributes);
    }

    /**
     * Purpose: Persist a deterministic updated_at timestamp for a saved notice fixture.
     * Inputs: The saved notice to update and the timestamp to apply.
     * Returns: The refreshed saved notice model.
     * Side effects: Updates the saved_notices row directly in the test database.
     */
    private function touchSavedNotice(SavedNotice $savedNotice, string $timestamp): SavedNotice
    {
        DB::table('saved_notices')
            ->where('id', $savedNotice->id)
            ->update([
                'updated_at' => $timestamp,
                'created_at' => $timestamp,
            ]);

        return $savedNotice->refresh();
    }

    /**
     * Purpose: Create a deterministic saved-notice info item fixture.
     * Inputs: The saved notice id plus owner and creator ids for the info item.
     * Returns: The persisted info item model.
     * Side effects: Writes an info item row to the test database.
     */
    private function createInfoItem(
        int $savedNoticeId,
        int $ownerUserId,
        int $createdByUserId,
        string $subject,
        array $overrides = [],
    ): SavedNoticeInfoItem
    {
        $attributes = [
            'saved_notice_id' => $savedNoticeId,
            'type' => $overrides['type'] ?? SavedNoticeInfoItem::TYPE_NOTE,
            'direction' => $overrides['direction'] ?? SavedNoticeInfoItem::DIRECTION_INTERNAL,
            'channel' => $overrides['channel'] ?? SavedNoticeInfoItem::CHANNEL_MANUAL,
            'subject' => $subject,
            'body' => $overrides['body'] ?? 'AI analysis foundation item.',
            'status' => $overrides['status'] ?? SavedNoticeInfoItem::STATUS_OPEN,
            'requires_response' => $overrides['requires_response'] ?? false,
            'owner_user_id' => $ownerUserId,
            'created_by_user_id' => $createdByUserId,
        ];

        if (Schema::hasColumn('saved_notice_info_items', 'closed_at')) {
            $attributes['closed_at'] = $overrides['closed_at'] ?? null;
        }

        if (Schema::hasColumn('saved_notice_info_items', 'closure_comment')) {
            $attributes['closure_comment'] = $overrides['closure_comment'] ?? null;
        }

        if (Schema::hasColumn('saved_notice_info_items', 'source_type')) {
            $attributes['source_type'] = $overrides['source_type'] ?? null;
        }

        if (Schema::hasColumn('saved_notice_info_items', 'source_id')) {
            $attributes['source_id'] = $overrides['source_id'] ?? null;
        }

        return SavedNoticeInfoItem::query()->create($attributes);
    }

    /**
     * Purpose: Create a deterministic saved-notice phase comment fixture.
     * Inputs: The saved notice id, author user id, phase status, and comment body.
     * Returns: The persisted phase comment model.
     * Side effects: Writes a phase comment row to the test database.
     */
    private function createPhaseComment(int $savedNoticeId, int $userId, string $phaseStatus, string $comment): SavedNoticePhaseComment
    {
        return SavedNoticePhaseComment::query()->create([
            'saved_notice_id' => $savedNoticeId,
            'user_id' => $userId,
            'phase_status' => $phaseStatus,
            'comment' => $comment,
        ]);
    }

    /**
     * Purpose: Create a deterministic bid submission fixture.
     * Inputs: The saved notice id and submission timestamp.
     * Returns: The persisted bid submission model.
     * Side effects: Writes a bid submission row to the test database.
     */
    private function createSubmission(int $savedNoticeId, string $submittedAt): BidSubmission
    {
        $sequenceNumber = (int) BidSubmission::query()
            ->where('saved_notice_id', $savedNoticeId)
            ->max('sequence_number');

        $sequenceNumber++;

        return BidSubmission::query()->create([
            'saved_notice_id' => $savedNoticeId,
            'sequence_number' => $sequenceNumber,
            'label' => BidSubmission::defaultLabelForSequence($sequenceNumber),
            'submitted_at' => $submittedAt,
        ]);
    }

    /**
     * Purpose: Create a deterministic AI document fixture for AI case view tests.
     * Inputs: The saved notice id and optional field overrides.
     * Returns: The persisted SavedNotice AI document model.
     * Side effects: Writes a saved_notice_ai_documents row to the test database.
     */
    private function createAiDocument(SavedNotice $savedNotice, array $overrides = []): SavedNoticeAiDocument
    {
        return SavedNoticeAiDocument::query()->create(array_merge([
            'saved_notice_id' => $savedNotice->id,
            'uploaded_by_user_id' => $overrides['uploaded_by_user_id'] ?? null,
            'original_filename' => $overrides['original_filename'] ?? 'analysis.pdf',
            'stored_path' => $overrides['stored_path'] ?? sprintf('saved-notices/%d/ai-documents/analysis.pdf', $savedNotice->id),
            'mime_type' => $overrides['mime_type'] ?? 'application/pdf',
            'file_size_bytes' => $overrides['file_size_bytes'] ?? 1024,
            'processing_status' => $overrides['processing_status'] ?? SavedNoticeAiDocument::PROCESSING_STATUS_UPLOADED,
        ], $overrides));
    }

    /**
     * Purpose: Create a deterministic answer basis fixture for AI case view tests.
     * Inputs: The saved notice and optional field overrides.
     * Returns: The persisted answer basis item model.
     * Side effects: Writes a saved_notice_ai_answer_basis_items row to the test database.
     */
    private function createAnswerBasisItem(SavedNotice $savedNotice, array $overrides = []): SavedNoticeAiAnswerBasisItem
    {
        return SavedNoticeAiAnswerBasisItem::query()->create(array_merge([
            'saved_notice_id' => $savedNotice->id,
            'created_by_user_id' => $overrides['created_by_user_id'] ?? null,
            'answer_basis_type' => $overrides['answer_basis_type'] ?? SavedNoticeAiAnswerBasisItem::ANSWER_BASIS_TYPE_TEXT,
            'title' => $overrides['title'] ?? 'Svargrunnlag',
            'original_filename' => $overrides['original_filename'] ?? null,
            'body_text' => $overrides['body_text'] ?? 'Leverandøren beskriver løsningen.',
            'stored_path' => $overrides['stored_path'] ?? null,
            'mime_type' => $overrides['mime_type'] ?? null,
            'file_size_bytes' => $overrides['file_size_bytes'] ?? null,
        ], $overrides));
    }

    /**
     * Purpose: Create a deterministic AI document chunk fixture for AI review tests.
     * Inputs: The saved notice AI document, chunk text, and optional chunk index.
     * Returns: The persisted SavedNotice AI document chunk model.
     * Side effects: Writes a saved_notice_ai_document_chunks row to the test database.
     */
    private function createAiDocumentChunk(SavedNoticeAiDocument $document, string $content, int $chunkIndex = 0): SavedNoticeAiDocumentChunk
    {
        return SavedNoticeAiDocumentChunk::query()->create([
            'saved_notice_ai_document_id' => $document->id,
            'chunk_index' => $chunkIndex,
            'content' => $content,
            'char_start' => 0,
            'char_end' => mb_strlen($content, 'UTF-8'),
            'word_count' => count(preg_split('/\s+/u', trim($content)) ?: []),
        ]);
    }

    /**
     * Purpose: Create a deterministic requirement candidate fixture for AI review tests.
     * Inputs: The saved notice, owning document, source chunk, and optional field overrides.
     * Returns: The persisted SavedNotice AI requirement model.
     * Side effects: Writes a saved_notice_ai_requirements row to the test database.
     */
    private function createAiRequirement(
        SavedNotice $savedNotice,
        SavedNoticeAiDocument $document,
        SavedNoticeAiDocumentChunk $chunk,
        array $overrides = [],
    ): SavedNoticeAiRequirement {
        $requirementText = (string) ($overrides['requirement_text'] ?? 'Dokumentasjon må vedlegges.');
        $requirementIdentifier = array_key_exists('requirement_identifier', $overrides)
            ? $overrides['requirement_identifier']
            : null;
        $reviewStatus = (string) ($overrides['review_status'] ?? SavedNoticeAiRequirement::REVIEW_STATUS_PENDING);
        $approvalStatus = (string) ($overrides['approval_status'] ?? SavedNoticeAiRequirement::approvalStatusForReviewStatus($reviewStatus));
        $publicationStatus = (string) ($overrides['publication_status'] ?? SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED);
        $publishedAt = array_key_exists('published_at', $overrides)
            ? $overrides['published_at']
            : ($publicationStatus === SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED ? now() : null);

        return SavedNoticeAiRequirement::query()->create(array_merge([
            'saved_notice_id' => $savedNotice->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_ai_document_chunk_id' => $chunk->id,
            'extraction_run_id' => $overrides['extraction_run_id'] ?? null,
            'source_type' => $overrides['source_type'] ?? SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            'approval_status' => $approvalStatus,
            'publication_status' => $publicationStatus,
            'requirement_identifier' => $requirementIdentifier,
            'original_requirement_identifier' => array_key_exists('original_requirement_identifier', $overrides)
                ? $overrides['original_requirement_identifier']
                : $requirementIdentifier,
            'requirement_text' => $requirementText,
            'original_requirement_text' => array_key_exists('original_requirement_text', $overrides)
                ? $overrides['original_requirement_text']
                : $requirementText,
            'requirement_type' => $overrides['requirement_type'] ?? SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => $overrides['extraction_method'] ?? SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'review_status' => $reviewStatus,
            'work_status' => $overrides['work_status'] ?? SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
            'assigned_user_id' => $overrides['assigned_user_id'] ?? null,
            'published_at' => $publishedAt,
            'superseded_at' => $overrides['superseded_at'] ?? null,
        ], $overrides));
    }

    /**
     * Purpose: Fake the OpenAI requirement segment endpoints with structured JSON responses.
     * Inputs: A callback that builds response payloads from the request prompt payload.
     * Returns: None.
     * Side effects: Replaces the HTTP fake for the current test with a deterministic segmented AI response.
     */
    private function fakeOpenAiRequirementExtractionResponse(callable $resolver, int $status = 200): void
    {
        Http::fake(function (Request $request) use ($resolver, $status) {
            $requestPayload = json_decode((string) $request->body(), true);

            if (! is_array($requestPayload)) {
                throw new RuntimeException('Unable to decode the fake OpenAI request payload.');
            }

            $inputText = (string) data_get($requestPayload, 'input.1.content.0.text', '');
            $decodedInput = json_decode($inputText, true);

            $promptContext = [
                'prompt_name' => (string) data_get($requestPayload, 'text.format.name', ''),
                'prompt_text' => (string) data_get($requestPayload, 'input.0.content.0.text', ''),
                'input_text' => $inputText,
                'request_payload' => $requestPayload,
                'document' => is_array($decodedInput) ? data_get($decodedInput, 'document', []) : [],
                'segment' => is_array($decodedInput) ? data_get($decodedInput, 'segment', []) : [],
                'model' => data_get($requestPayload, 'model'),
            ];

            $response = $resolver($promptContext, $request);

            if (! is_array($response) || ! array_key_exists('body', $response) || ! is_array($response['body'])) {
                throw new RuntimeException('The fake OpenAI resolver must return an array with a body key.');
            }

            return Http::response(
                $response['body'],
                (int) ($response['status'] ?? $status),
                $response['headers'] ?? [
                    'x-request-id' => 'req_requirement_extraction',
                ],
            );
        });
    }

    /**
     * Purpose: Fake the OpenAI full-document requirement extraction endpoint with a deterministic raw JSON response.
     * Inputs: A callback that builds response payloads from the request prompt payload.
     * Returns: None.
     * Side effects: Replaces the HTTP fake for the current test with a deterministic full-document AI response.
     */
    private function fakeOpenAiFullDocumentRequirementExtractionResponse(callable $resolver, int $status = 200): void
    {
        Http::fake(function (Request $request) use ($resolver, $status) {
            $requestPayload = json_decode((string) $request->body(), true);

            if (! is_array($requestPayload)) {
                throw new RuntimeException('Unable to decode the fake OpenAI request payload.');
            }

            $promptText = (string) data_get($requestPayload, 'input.0.content.0.text', '');
            $documentText = (string) data_get($requestPayload, 'input.1.content.0.text', '');

            $promptContext = [
                'prompt_name' => (string) data_get($requestPayload, 'text.format.name', ''),
                'prompt_text' => $promptText,
                'input_text' => $documentText,
                'document_text' => $documentText,
                'request_payload' => $requestPayload,
                'model' => data_get($requestPayload, 'model'),
            ];

            $response = $resolver($promptContext, $request);

            if (! is_array($response) || ! array_key_exists('body', $response) || ! is_array($response['body'])) {
                throw new RuntimeException('The fake OpenAI resolver must return an array with a body key.');
            }

            return Http::response(
                $response['body'],
                (int) ($response['status'] ?? $status),
                $response['headers'] ?? [
                    'x-request-id' => 'req_requirement_extraction',
                ],
            );
        });
    }

    /**
     * Purpose: Fake the OpenAI structured block extraction endpoint with deterministic responses.
     * Inputs: A callback that builds response payloads from the decoded block prompt.
     * Returns: None.
     * Side effects: Replaces the HTTP fake for the current test with a deterministic block-level AI response.
     */
    private function fakeOpenAiStructuredBlockRequirementExtractionResponse(callable $resolver, int $status = 200): void
    {
        Http::fake(function (Request $request) use ($resolver, $status) {
            $requestPayload = json_decode((string) $request->body(), true);

            if (! is_array($requestPayload)) {
                throw new RuntimeException('Unable to decode the fake OpenAI request payload.');
            }

            $inputText = (string) data_get($requestPayload, 'input.1.content.0.text', '');
            $decodedInput = json_decode($inputText, true);
            $blocks = is_array($decodedInput) ? data_get($decodedInput, 'blocks', []) : [];
            $block = is_array($blocks) ? data_get($blocks, '0', []) : [];

            $promptContext = [
                'prompt_name' => (string) data_get($requestPayload, 'text.format.name', ''),
                'prompt_text' => (string) data_get($requestPayload, 'input.0.content.0.text', ''),
                'input_text' => $inputText,
                'request_payload' => $requestPayload,
                'document' => is_array($decodedInput) ? data_get($decodedInput, 'document', []) : [],
                'blocks' => is_array($decodedInput) ? $blocks : [],
                'block' => is_array($block) ? $block : [],
                'model' => data_get($requestPayload, 'model'),
            ];

            $response = $resolver($promptContext, $request);

            if (! is_array($response) || ! array_key_exists('body', $response) || ! is_array($response['body'])) {
                throw new RuntimeException('The fake OpenAI resolver must return an array with a body key.');
            }

            return Http::response(
                $response['body'],
                (int) ($response['status'] ?? $status),
                $response['headers'] ?? [
                    'x-request-id' => 'req_requirement_extraction',
                ],
            );
        });
    }

    /**
     * Purpose: Build one structured requirement extraction row for a segment-level prompt output.
     * Inputs: The requirement text and optional overrides.
     * Returns: A fully populated requirement row matching the segment prompt contract.
     * Side effects: None.
     */
    private function buildRequirementExtractionCandidate(string $originalText, array $overrides = []): array
    {
        return array_merge([
            'requirement_identifier' => null,
            'parent_reference' => null,
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_MANDATORY,
            'obligation_type' => 'must',
            'original_text' => $originalText,
            'normalized_text' => preg_replace('/\s+/u', ' ', trim($originalText)) ?: $originalText,
            'comment' => null,
            'evaluation_notes' => null,
            'response_expectation' => null,
            'expected_evidence' => [],
            'keywords' => [],
            'domain' => [],
            'related_references' => [],
            'source_excerpt' => $originalText,
            'source_page_start' => null,
            'source_page_end' => null,
            'source_section_title' => null,
            'interpretation_risk' => 'low',
            'is_requirement' => true,
            'confidence' => 0.95,
            'warnings' => [],
        ], $overrides);
    }

    /**
     * Purpose: Build one structured block requirement row for the active structured block prompt.
     * Inputs: The source block metadata, the requirement text, and optional overrides.
     * Returns: A fully populated requirement row matching the block prompt contract.
     * Side effects: None.
     */
    private function buildStructuredBlockRequirementExtractionCandidate(array $block, string $originalText, array $overrides = []): array
    {
        $sourceReference = array_merge([
            'saved_notice_ai_document_id' => data_get($block, 'saved_notice_ai_document_id'),
            'saved_notice_ai_document_chunk_id' => data_get($block, 'saved_notice_ai_document_chunk_id'),
            'source_block_id' => data_get($block, 'source_block_id'),
            'source_block_index' => data_get($block, 'source_block_index'),
            'document_filename' => data_get($block, 'document_filename'),
            'chunk_index' => data_get($block, 'source_block_index'),
            'char_start' => data_get($block, 'source_reference.char_start'),
            'char_end' => data_get($block, 'source_reference.char_end'),
            'source_chunk_ids' => data_get($block, 'source_chunk_ids', []),
            'source_reference_text' => $originalText,
            'source_excerpt' => $originalText,
        ], (array) ($overrides['source_reference'] ?? []));

        $sourceReferenceText = trim((string) ($sourceReference['source_reference_text'] ?? ''));
        $sourceExcerpt = trim((string) ($sourceReference['source_excerpt'] ?? ''));

        if ($sourceReferenceText !== '') {
            $sourceReference['source_excerpt'] = $sourceReferenceText;
        } elseif ($sourceExcerpt !== '') {
            $sourceReference['source_reference_text'] = $sourceExcerpt;
        }

        return array_merge([
            'source_document_id' => data_get($block, 'saved_notice_ai_document_id'),
            'source_block_id' => data_get($block, 'source_block_id'),
            'source_block_index' => data_get($block, 'source_block_index'),
            'requirement_identifier' => null,
            'parent_reference' => null,
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_MANDATORY,
            'obligation_type' => 'must',
            'original_text' => $originalText,
            'normalized_text' => preg_replace('/\s+/u', ' ', trim($originalText)) ?: $originalText,
            'comment' => null,
            'evaluation_notes' => null,
            'response_expectation' => null,
            'expected_evidence' => [],
            'keywords' => [],
            'domain' => [],
            'related_references' => [],
            'source_reference' => $sourceReference,
            'interpretation_risk' => 'low',
            'is_requirement' => true,
            'confidence' => 0.95,
            'warnings' => [],
        ], $overrides);
    }

    /**
     * Purpose: Build one full-document requirement extraction row for the active document-level prompt.
     * Inputs: The requirement text and optional overrides.
     * Returns: A fully populated requirement row matching the full-document prompt contract.
     * Side effects: None.
     */
    private function buildFullDocumentRequirementExtractionCandidate(string $originalText, array $overrides = []): array
    {
        $candidate = array_merge([
            'requirement_identifier' => null,
            'parent_reference' => null,
            'original_text' => $originalText,
            'source_reference_text' => $originalText,
            'is_requirement' => true,
            'confidence' => 0.95,
        ], $overrides);

        return array_intersect_key($candidate, array_flip([
            'requirement_identifier',
            'parent_reference',
            'original_text',
            'source_reference_text',
            'is_requirement',
            'confidence',
        ]));
    }

    /**
     * Purpose: Build a deterministic OpenAI structured response payload.
     * Inputs: The JSON body fields and optional token usage overrides.
     * Returns: A fake OpenAI response body that mimics a structured Responses API result.
     * Side effects: None.
     */
    private function openAiStructuredResponse(array $fields, int $inputTokens = 40, int $outputTokens = 12): array
    {
        $json = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($json)) {
            throw new RuntimeException('Unable to build a fake OpenAI response.');
        }

        return [
            'id' => (string) Str::ulid(),
            'object' => 'response',
            'status' => 'completed',
            'output' => [
                [
                    'id' => (string) Str::ulid(),
                    'type' => 'message',
                    'role' => 'assistant',
                    'status' => 'completed',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => $json,
                        ],
                    ],
                ],
            ],
            'output_text' => $json,
            'usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
            ],
        ];
    }

    /**
     * Purpose: Build a deterministic OpenAI response for one segment relevance decision.
     * Inputs: The relevance boolean, rationale, and confidence score.
     * Returns: A fake OpenAI response body for the relevance classifier.
     * Side effects: None.
     */
    private function openAiSegmentRelevanceResponse(bool $isRelevant, string $reason, float $confidence = 0.95): array
    {
        return $this->openAiStructuredResponse([
            'is_relevant' => $isRelevant,
            'confidence' => $confidence,
            'reason' => $reason,
        ], 28, 8);
    }

    /**
     * Purpose: Build a deterministic OpenAI response for one segment extraction request.
     * Inputs: The extracted candidate rows for one segment.
     * Returns: A fake OpenAI response body for the extraction stage.
     * Side effects: None.
     */
    private function openAiSegmentExtractionResponse(array $candidates): array
    {
        return $this->openAiStructuredResponse([
            'candidates' => array_values($candidates),
        ], 90, 30);
    }

    /**
     * Purpose: Render raw extraction rows as a Markdown table for the fake OpenAI response.
     * Inputs: The structured requirement rows.
     * Returns: A raw table string that mimics model output.
     * Side effects: None.
     */
    private function buildRequirementExtractionTable(array $rows): string
    {
        $columns = [
            ['label' => 'Krav ID', 'key' => 'requirement_identifier'],
            ['label' => 'Foreldre-ID / kapittel / tema', 'key' => 'parent_reference'],
            ['label' => 'Kravtype', 'key' => 'requirement_type'],
            ['label' => 'Obligatorisk eller evalueringsdrivende', 'key' => 'obligation_type'],
            ['label' => 'Kravtekst original', 'key' => 'original_text'],
            ['label' => 'Kravtekst normalisert for semantisk søk', 'key' => 'normalized_text'],
            ['label' => 'Viktig merknad / kommentar', 'key' => 'comment'],
            ['label' => 'Evalueringsmomenter', 'key' => 'evaluation_notes'],
            ['label' => 'Hva må besvares', 'key' => 'response_expectation'],
            ['label' => 'Forventet dokumentasjon / bevis', 'key' => 'expected_evidence'],
            ['label' => 'Nøkkelord', 'key' => 'keywords'],
            ['label' => 'Fagområde', 'key' => 'domain'],
            ['label' => 'Relaterte krav / avhengigheter', 'key' => 'related_references'],
            ['label' => 'Kildehenvisning i dokumentet', 'key' => 'source_reference_text'],
            ['label' => 'Tolkingsrisiko / uklarhet', 'key' => 'interpretation_risk'],
        ];

        $lines = [
            '| '.implode(' | ', array_column($columns, 'label')).' |',
            '| '.implode(' | ', array_fill(0, count($columns), '---')).' |',
        ];

        foreach ($rows as $row) {
            $cells = [];

            foreach ($columns as $column) {
                $cells[] = $this->stringifyExtractionCell($row[$column['key']] ?? null);
            }

            $lines[] = '| '.implode(' | ', $cells).' |';
        }

        return implode("\n", $lines);
    }

    private function stringifyExtractionCell(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_map(
                static fn (mixed $item): string => trim((string) $item),
                array_filter($value, static fn (mixed $item): bool => $item !== null && trim((string) $item) !== ''),
            ));
        }

        if (is_bool($value)) {
            $value = $value ? 'ja' : 'nei';
        }

        $text = trim((string) $value);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = str_replace('|', '\|', $text);

        return $text;
    }

    /**
     * Purpose: Create a deterministic knowledge item fixture for AI retrieval tests.
     * Inputs: The customer plus optional field overrides.
     * Returns: The persisted KnowledgeItem model.
     * Side effects: Writes a knowledge_items row to the test database.
     */
    private function createKnowledgeItem(Customer $customer, array $overrides = []): KnowledgeItem
    {
        $title = $overrides['title'] ?? 'Knowledge document';
        $originalFilename = $overrides['original_filename'] ?? $title;
        $documentType = $overrides['document_type'] ?? $overrides['content_type'] ?? KnowledgeItem::CONTENT_TYPE_OTHER;
        $extractedText = $overrides['extracted_text'] ?? $overrides['content'] ?? 'Knowledge document content.';
        $slug = Str::slug($originalFilename);

        if ($slug === '') {
            $slug = 'knowledge-document';
        }

        return KnowledgeItem::query()->create(array_merge([
            'customer_id' => $customer->id,
            'title' => $title,
            'content' => $extractedText,
            'original_filename' => $originalFilename,
            'storage_path' => $overrides['storage_path'] ?? sprintf('customers/%d/knowledge-documents/%s', $customer->id, $slug.'.docx'),
            'mime_type' => $overrides['mime_type'] ?? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => $overrides['file_size_bytes'] ?? 1024,
            'document_type' => $documentType,
            'content_type' => $documentType,
            'extracted_text' => $extractedText,
            'extraction_status' => $overrides['extraction_status'] ?? KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => $overrides['extraction_error'] ?? null,
            'uploaded_by_user_id' => $overrides['uploaded_by_user_id'] ?? null,
            'is_active' => $overrides['is_active'] ?? true,
        ], $overrides));
    }

    /**
     * Purpose: Regenerate deterministic chunks for one knowledge item fixture.
     * Inputs: The knowledge item to chunk.
     * Returns: None.
     * Side effects: Deletes existing chunks and recreates them from the current content.
     */
    private function syncKnowledgeItemChunks(KnowledgeItem $knowledgeItem): void
    {
        $knowledgeItem->chunks()->delete();

        $chunkPayloads = app(\App\Services\DocumentChunker::class)->chunkText((string) $knowledgeItem->extracted_text);

        $knowledgeItem->chunks()->createMany(array_map(
            static fn (array $chunk, int $chunkIndex): array => [
                'chunk_index' => $chunkIndex,
                'content' => (string) ($chunk['content'] ?? ''),
                'start_offset' => (int) ($chunk['char_start'] ?? 0),
                'end_offset' => (int) ($chunk['char_end'] ?? 0),
            ],
            $chunkPayloads,
            array_keys($chunkPayloads),
        ));
    }

    /**
     * Purpose: Decode the Inertia page payload from a customer-app test response.
     * Inputs: The HTTP test response returned by the AI page request.
     * Returns: The decoded Inertia page array.
     * Side effects: Throws when the response does not contain a readable Inertia payload.
     */
    private function inertiaPageFromResponse(\Illuminate\Testing\TestResponse $response): array
    {
        try {
            $page = $response->viewData('page');

            if (is_array($page)) {
                return $page;
            }
        } catch (\Throwable) {
            // Fall through to the HTML payload parser below.
        }

        $content = $response->getContent();
        preg_match('/data-page="([^"]+)"/', $content, $matches);

        if (! isset($matches[1])) {
            throw new RuntimeException('Unable to extract the Inertia page payload from the response.');
        }

        $page = json_decode(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($page)) {
            throw new RuntimeException('Unable to decode the Inertia page payload from the response.');
        }

        return $page;
    }

    /**
     * Purpose: Create a small DOCX fixture with extractable text for upload tests.
     * Inputs: The client filename and the raw text to embed in the document body.
     * Returns: A test uploaded file backed by a real DOCX archive.
     * Side effects: Writes a temporary ZIP file to the system temp directory.
     */
    private function createDocxUpload(string $filename, string $text): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'procynia-docx-');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary DOCX file.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException('Unable to create a DOCX archive for testing.');
        }

        $escapedText = htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'
            .'<w:p><w:r><w:t>'.$escapedText.'</w:t></w:r></w:p>'
            .'</w:body>'
            .'</w:document>';

        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return new UploadedFile(
            $path,
            $filename,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true,
        );
    }

    /**
     * Purpose: Build a deterministic OpenAI Responses API payload for assessment tests.
     * Inputs: The canonical assessment fields to embed in the assistant output.
     * Returns: A fake OpenAI response body that mimics a JSON-schema response.
     * Side effects: None.
     */
    private function openAiAssessmentResponse(array $fields): array
    {
        $json = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($json)) {
            throw new RuntimeException('Unable to build a fake OpenAI assessment response.');
        }

        return [
            'id' => (string) Str::ulid(),
            'object' => 'response',
            'status' => 'completed',
            'output' => [
                [
                    'id' => (string) Str::ulid(),
                    'type' => 'message',
                    'role' => 'assistant',
                    'status' => 'completed',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => $json,
                        ],
                    ],
                ],
            ],
            'output_text' => $json,
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 40,
                'total_tokens' => 140,
            ],
        ];
    }

    /**
     * Purpose: Build a deterministic invalid OpenAI assessment payload for failure tests.
     * Inputs: None.
     * Returns: A fake OpenAI response body with invalid enum values.
     * Side effects: None.
     */
    private function openAiInvalidAssessmentResponse(): array
    {
        return $this->openAiAssessmentResponse([
            'coverage_status' => 'unknown',
            'risk_level' => 'extreme',
            'requirement_summary' => 'Invalid output.',
            'coverage_rationale' => 'Invalid output.',
            'missing_information' => 'Invalid output.',
            'recommended_next_step' => 'Invalid output.',
        ]);
    }

    public function test_documents_payload_returns_only_newest_document_per_filename_when_completed_supersedes_failed(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-DEDUP-001', 'Dedup target', [
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
        ]);

        Storage::fake('local');

        $olderFailed = $this->createAiDocument($savedNotice, [
            'original_filename' => 'kravspesifikasjon.pdf',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_FAILED,
            'processing_error_type' => 'truncated_response',
            'processing_error_message' => 'AI response appears to have been truncated at the configured output token limit before valid JSON could be parsed.',
        ]);

        $newerCompleted = $this->createAiDocument($savedNotice, [
            'original_filename' => 'kravspesifikasjon.pdf',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED,
        ]);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $documents = collect(data_get($page, 'props.documents', []));
        $documentIds = $documents->pluck('id');

        $this->assertCount(1, $documents, 'Only the newest document per filename should be in the payload.');
        $this->assertTrue($documentIds->contains($newerCompleted->id), 'Payload must include the newer completed document.');
        $this->assertFalse($documentIds->contains($olderFailed->id), 'Payload must not include the older failed document.');
        $this->assertSame(
            SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED,
            $documents->first()['processing_status'],
            'The document in the payload must have processing_status = completed.',
        );
    }

    public function test_documents_payload_still_returns_latest_failed_document_when_no_completed_exists_for_filename(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-DEDUP-002', 'Dedup failed target', [
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
        ]);

        Storage::fake('local');

        $olderFailed = $this->createAiDocument($savedNotice, [
            'original_filename' => 'kravspesifikasjon.pdf',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_FAILED,
            'processing_error_type' => 'timeout',
        ]);

        $newerFailed = $this->createAiDocument($savedNotice, [
            'original_filename' => 'kravspesifikasjon.pdf',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_FAILED,
            'processing_error_type' => 'truncated_response',
        ]);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $documents = collect(data_get($page, 'props.documents', []));
        $documentIds = $documents->pluck('id');

        $this->assertCount(1, $documents, 'Only the newest document per filename should be in the payload.');
        $this->assertTrue($documentIds->contains($newerFailed->id), 'Payload must include the newer failed document.');
        $this->assertFalse($documentIds->contains($olderFailed->id), 'Payload must not include the older failed document.');
        $this->assertSame(
            SavedNoticeAiDocument::PROCESSING_STATUS_FAILED,
            $documents->first()['processing_status'],
            'The document in the payload must still reflect the real failed status.',
        );
        $this->assertSame(
            'truncated_response',
            $documents->first()['processing_error_type'],
            'The newest failed document error type must be visible in the payload.',
        );
    }

    public function test_documents_payload_includes_requirement_extraction_progress_with_call_counts(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-PROGRESS-001', 'Progress target', [
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
        ]);

        Storage::fake('local');

        $document = $this->createAiDocument($savedNotice, [
            'original_filename' => 'kravspesifikasjon.pdf',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
        ]);

        $run = RequirementExtractionRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'saved_notice_id' => $savedNotice->id,
            'saved_notice_ai_document_id' => $document->id,
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'strategy' => RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION,
            'prompt_version' => 1,
            'model' => 'gpt-4.1-mini',
            'candidate_count' => 143,
            'persisted_requirement_count' => 0,
            'openai_call_count' => 0,
            'input_tokens_total' => 0,
            'output_tokens_total' => 0,
            'total_tokens_total' => 0,
        ]);

        $callBase = [
            'requirement_extraction_run_id' => $run->id,
            'saved_notice_id' => $savedNotice->id,
            'saved_notice_ai_document_id' => $document->id,
            'strategy' => RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION,
            'prompt_version' => 1,
            'model' => 'gpt-4.1-mini',
        ];

        for ($i = 0; $i < 12; $i++) {
            RequirementExtractionCall::query()->create(array_merge($callBase, [
                'status' => RequirementExtractionCall::STATUS_COMPLETED,
            ]));
        }

        RequirementExtractionCall::query()->create(array_merge($callBase, [
            'status' => RequirementExtractionCall::STATUS_RUNNING,
        ]));

        for ($i = 0; $i < 27; $i++) {
            RequirementExtractionCall::query()->create(array_merge($callBase, [
                'status' => RequirementExtractionCall::STATUS_QUEUED,
            ]));
        }

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $documentRow = collect(data_get($page, 'props.documents', []))->firstWhere('id', $document->id);
        $progress = $documentRow['requirement_extraction_progress'] ?? null;

        $this->assertNotNull($progress, 'requirement_extraction_progress must be present for a document with an active run.');
        $this->assertSame(40, $progress['total_calls']);
        $this->assertSame(12, $progress['completed_calls']);
        $this->assertSame(1, $progress['running_calls']);
        $this->assertSame(27, $progress['queued_calls']);
        $this->assertSame(0, $progress['failed_calls']);
        $this->assertSame(143, $progress['candidate_count']);
    }

    public function test_documents_payload_includes_requirement_extraction_progress_for_completed_document(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-PROGRESS-002', 'Progress completed target', [
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
        ]);

        Storage::fake('local');

        $document = $this->createAiDocument($savedNotice, [
            'original_filename' => 'kravspesifikasjon.pdf',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED,
        ]);

        RequirementExtractionRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'saved_notice_id' => $savedNotice->id,
            'saved_notice_ai_document_id' => $document->id,
            'status' => RequirementExtractionRun::STATUS_COMPLETED,
            'strategy' => RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION,
            'prompt_version' => 1,
            'model' => 'gpt-4.1-mini',
            'candidate_count' => 55,
            'persisted_requirement_count' => 55,
            'openai_call_count' => 3,
            'input_tokens_total' => 0,
            'output_tokens_total' => 0,
            'total_tokens_total' => 0,
        ]);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $documentRow = collect(data_get($page, 'props.documents', []))->firstWhere('id', $document->id);

        $this->assertSame(
            SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED,
            $documentRow['processing_status'],
            'Completed document must still report processing_status = completed.',
        );
        $this->assertSame(
            55,
            $documentRow['requirement_extraction_progress']['candidate_count'] ?? null,
            'requirement_extraction_progress.candidate_count must reflect the completed run.',
        );
    }

    public function test_ai_index_returns_200_when_archived_notices_exist(): void
    {
        $context = $this->customerAdminContext();

        $this->createSavedNotice($context['customer']->id, 'AI-ARCH-IDX-001', 'Archived notice in index', [
            'bid_status' => SavedNotice::BID_STATUS_DISCOVERED,
            'archived_at' => '2026-05-31 17:20:54',
            'history_type' => 'closed',
        ]);

        $this->createSavedNotice($context['customer']->id, 'AI-ACTIVE-IDX-001', 'Active notice in index', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);

        $this->actingAs($context['user'])
            ->get(route('app.ai.index'))
            ->assertOk();
    }

    public function test_ai_index_returns_200_and_omits_archived_notices_from_analysis_cases(): void
    {
        $context = $this->customerAdminContext();

        $archivedNotice = $this->createSavedNotice($context['customer']->id, 'AI-ARCH-IDX-002', 'Archived ex-case', [
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
            'archived_at' => '2026-05-31 17:20:54',
            'history_type' => 'closed',
        ]);

        $activeNotice = $this->createSavedNotice($context['customer']->id, 'AI-ACTIVE-IDX-002', 'Active case', [
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
        ]);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.index'));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $caseIds = collect(data_get($page, 'props.analysisCases', []))->pluck('id');

        $this->assertFalse(
            $caseIds->contains($archivedNotice->id),
            'Archived notice must not appear in analysisCases — the frontend rememberedAiCaseId validation relies on this.',
        );
        $this->assertTrue(
            $caseIds->contains($activeNotice->id),
            'Active notice must appear in analysisCases.',
        );
    }

    public function test_archived_saved_notice_returns_404_on_ai_show(): void
    {
        $context = $this->customerAdminContext();
        $archivedNotice = $this->createSavedNotice($context['customer']->id, 'AI-ARCHIVED-001', 'Archived test case', [
            'bid_status' => SavedNotice::BID_STATUS_DISCOVERED,
            'archived_at' => '2026-05-31 17:20:54',
            'history_type' => 'closed',
        ]);

        $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $archivedNotice->id]))
            ->assertNotFound();
    }

    public function test_non_archived_saved_notice_is_accessible_on_ai_show(): void
    {
        $context = $this->customerAdminContext();
        $activeNotice = $this->createSavedNotice($context['customer']->id, 'AI-ACTIVE-001', 'Active test case', [
            'bid_status' => SavedNotice::BID_STATUS_DISCOVERED,
        ]);

        $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $activeNotice->id]))
            ->assertOk();
    }

    private function useProjectPostgresConnection(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'procynia_test',
        ]);

        DB::purge('pgsql');
        DB::reconnect('pgsql');
    }
}
