<?php

namespace Tests\Feature\App;

use App\Http\Controllers\App\AiController;
use App\Jobs\Ai\Requirements\ProcessRequirementExtractionRun;
use App\Models\BidSubmission;
use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\KnowledgeItemVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\RequirementExtractionCall;
use App\Models\RequirementExtractionRun;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiAnswerBasisItem;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\SavedNoticeAiEvidence;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementRevision;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Models\SavedNoticeInfoItem;
use App\Models\SavedNoticePhaseComment;
use App\Models\SavedNoticeUserAccess;
use App\Models\User;
use App\Services\Ai\Requirements\FullDocumentRequirementExtractionPrompt;
use App\Services\Ai\Requirements\RequirementAnswerDraftService;
use App\Services\Ai\Requirements\RequirementExtractionPipeline;
use App\Services\Ai\Requirements\RequirementExtractionRunService;
use App\Services\Ai\Requirements\RequirementGroundingJudgeService;
use App\Services\Ai\Requirements\RequirementLoader;
use App\Services\Ai\Retrieval\MetadataRetrievalPlanService;
use App\Services\DocumentChunker;
use App\Services\KnowledgeChunkCoverageService;
use App\Services\OpenAi\EmbeddingService;
use App\Services\RequirementExtractor;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;
use ZipArchive;

class AiControllerTest extends TestCase
{
    use UsesProjectPostgresConnection;

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
            $pageLocale = (string) data_get($page, 'props.locale', 'nb-NO');
            $expectedDedupNotice = str_starts_with($pageLocale, 'en')
                ? 'The list shows the latest upload per filename. Earlier uploads with the same filename are not shown in this overview.'
                : 'Listen viser nyeste opplasting per filnavn. Tidligere opplastinger med samme filnavn vises ikke i denne oversikten.';

            return data_get($page, 'component') === 'App/AI/Show'
                && data_get($page, 'props.pageTitle') === 'I arbeid · Case view target'
                && data_get($page, 'props.ai_status') === 'ready'
                && data_get($page, 'props.requirements_count') === 0
                && data_get($page, 'props.requirements') === []
                && data_get($page, 'props.translations.ai.documents_section_dedup_notice') === $expectedDedupNotice
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
        $this->assertSame(route('app.notices.saved.show', ['savedNotice' => $savedNotice->id]), data_get($page, 'props.saved_notice_show_url'));
        $this->assertSame(route('app.ai.requirements.store', ['savedNotice' => $savedNotice->id]), data_get($page, 'props.requirements_store_url'));
        $this->assertSame(route('app.ai.requirements.reject-all', ['savedNotice' => $savedNotice->id]), data_get($page, 'props.requirements_reject_all_url'));
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

    public function test_ai_requirement_reject_all_rejects_only_extracted_requirements_and_keeps_manual_requirements(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-3004-REJECT', 'Reject extracted requirements target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:10:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'reject-target.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/reject-target.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Dokumenttekst for avvisning.',
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
            ->patch(route('app.ai.requirements.reject-all', ['savedNotice' => $savedNotice->id]));

        $response->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));
        $response->assertSessionHas('success', 'Ekstraherte krav er avvist. Du kan gjenopprette dem enkeltvis.');

        // Extracted requirements must still exist in the database — rows are preserved, not deleted.
        $this->assertDatabaseHas('saved_notice_ai_requirements', [
            'id' => $firstExtractedRequirement->id,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_REJECTED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED,
        ]);
        $this->assertDatabaseHas('saved_notice_ai_requirements', [
            'id' => $secondExtractedRequirement->id,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_REJECTED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED,
        ]);

        // Manual requirement must be untouched.
        $this->assertDatabaseHas('saved_notice_ai_requirements', [
            'id' => $manualRequirement->id,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
        ]);

        // Page still serves all requirements (rejected ones remain visible in the list).
        $pageResponse = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $pageResponse->assertOk();
        $page = $this->inertiaPageFromResponse($pageResponse);
        $requirements = collect(data_get($page, 'props.requirements', []));

        $this->assertSame(3, data_get($page, 'props.requirements_count'));
        $this->assertCount(3, $requirements);

        $rejectedIds = $requirements
            ->whereIn('id', [$firstExtractedRequirement->id, $secondExtractedRequirement->id])
            ->pluck('approval_status')
            ->all();
        $this->assertSame(['rejected', 'rejected'], array_values($rejectedIds));

        $manualRow = $requirements->firstWhere('id', $manualRequirement->id);
        $this->assertSame('draft', data_get($manualRow, 'approval_status'));
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

    // -------------------------------------------------------------------------
    // AI-to-Wiki consolidation — Enterprise Wiki is the sole answer engine in
    // "I arbeid". The legacy Knowledge Base answer-draft endpoints are kept
    // (undeleted) but deprecated: they must never call RequirementAnswerDraftService
    // and must always return a controlled 410, never a 500.
    // -------------------------------------------------------------------------

    public function test_answer_draft_generation_endpoint_is_deprecated_and_returns_410(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-WIKI-DEPRECATED-1', 'Deprecated draft target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $document = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
        ]);

        $draftService = Mockery::mock(RequirementAnswerDraftService::class);
        $draftService->shouldNotReceive('ensureAnswerDraft');
        $this->app->instance(RequirementAnswerDraftService::class, $draftService);

        $response = $this->actingAs($context['user'])->postJson(route('app.ai.requirements.answer-draft.generate', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), ['answer_basis_item_ids' => []]);

        $response->assertStatus(410);
        $response->assertJsonPath('requirement_id', $requirement->id);
        $this->assertNull($requirement->refresh()->answer_draft_text);
    }

    public function test_answer_draft_update_endpoint_is_deprecated_and_returns_410(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-WIKI-DEPRECATED-2', 'Deprecated draft update target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $document = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
        ]);

        $draftService = Mockery::mock(RequirementAnswerDraftService::class);
        $draftService->shouldNotReceive('updateAnswerDraft');
        $this->app->instance(RequirementAnswerDraftService::class, $draftService);

        $response = $this->actingAs($context['user'])->patchJson(route('app.ai.requirements.answer-draft.update', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), ['answer_draft_text' => 'Forsøk på å redigere det gamle svarutkastet.']);

        $response->assertStatus(410);
        $this->assertNull($requirement->refresh()->answer_draft_text);
    }

    public function test_wiki_answer_update_endpoint_persists_edited_answer_text(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-WIKI-EDIT-1', 'Wiki answer edit target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $document = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
        ]);
        $this->createWikiAnswer($requirement, 'Opprinnelig Wiki-svar.');

        $response = $this->actingAs($context['user'])->patchJson(route('app.ai.requirements.wiki-answer.update', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirement->id,
        ]), ['answer_text' => 'Redigert Wiki-svar.']);

        $response->assertOk();
        $response->assertJsonPath('wiki_answer.text', 'Redigert Wiki-svar.');

        $this->assertSame(
            'Redigert Wiki-svar.',
            SavedNoticeAiRequirementWikiAnswer::query()->where('saved_notice_ai_requirement_id', $requirement->id)->firstOrFail()->answer_text,
        );
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

    public function test_ai_document_delete_is_blocked_when_document_has_chunks_requirements_or_runs(): void
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
        $response->assertSessionHas('error', __('procynia.ai.document_delete_blocked'));
        $this->assertDatabaseHas('saved_notice_ai_documents', ['id' => $document->id]);
        $this->assertDatabaseHas('saved_notice_ai_document_chunks', ['id' => $chunk->id]);
        $this->assertDatabaseHas('saved_notice_ai_requirements', ['id' => $requirement->id]);
        $this->assertTrue(Storage::disk('local')->exists($document->stored_path));
    }

    public function test_ai_document_delete_is_blocked_when_processing_is_still_active(): void
    {
        Storage::fake('local');

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-3004-ACTIVE', 'Active delete target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:16:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'active-delete.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/active-delete.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_QUEUED,
        ]);
        Storage::disk('local')->put($document->stored_path, 'document bytes');

        $response = $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->delete(route('app.ai.documents.destroy', [
                'savedNotice' => $savedNotice->id,
                'document' => $document->id,
            ]));

        $response->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));
        $response->assertSessionHas('error', __('procynia.ai.document_delete_blocked'));
        $this->assertDatabaseHas('saved_notice_ai_documents', ['id' => $document->id]);
        $this->assertTrue(Storage::disk('local')->exists($document->stored_path));
    }

    public function test_ai_document_delete_is_blocked_when_an_extraction_run_exists(): void
    {
        Storage::fake('local');

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-3004-RUN', 'Run delete target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:17:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'run-delete.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/run-delete.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED,
        ]);
        Storage::disk('local')->put($document->stored_path, 'document bytes');

        $run = RequirementExtractionRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'saved_notice_id' => $savedNotice->id,
            'saved_notice_ai_document_id' => $document->id,
            'status' => RequirementExtractionRun::STATUS_COMPLETED,
            'strategy' => RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION,
            'prompt_version' => 1,
            'model' => 'gpt-4.1-mini',
            'candidate_count' => 0,
            'persisted_requirement_count' => 0,
            'openai_call_count' => 0,
            'input_tokens_total' => 0,
            'output_tokens_total' => 0,
            'total_tokens_total' => 0,
            'queued_at' => '2026-04-06 12:17:01',
            'started_at' => '2026-04-06 12:17:02',
            'finished_at' => '2026-04-06 12:17:03',
            'last_heartbeat_at' => '2026-04-06 12:17:03',
        ]);

        $response = $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->delete(route('app.ai.documents.destroy', [
                'savedNotice' => $savedNotice->id,
                'document' => $document->id,
            ]));

        $response->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));
        $response->assertSessionHas('error', __('procynia.ai.document_delete_blocked'));
        $this->assertDatabaseHas('saved_notice_ai_documents', ['id' => $document->id]);
        $this->assertDatabaseHas('requirement_extraction_runs', ['id' => $run->id]);
        $this->assertTrue(Storage::disk('local')->exists($document->stored_path));
    }

    public function test_ai_document_delete_removes_an_isolated_document_and_returns_to_the_case_view(): void
    {
        Storage::fake('local');

        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-3004-ISO', 'Isolated delete target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:18:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'isolated-delete.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/isolated-delete.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_UPLOADED,
        ]);
        Storage::disk('local')->put($document->stored_path, 'document bytes');

        $response = $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->delete(route('app.ai.documents.destroy', [
                'savedNotice' => $savedNotice->id,
                'document' => $document->id,
            ]));

        $response->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));
        $response->assertSessionHas('success', 'Deleted 1 document.');
        $this->assertDatabaseMissing('saved_notice_ai_documents', ['id' => $document->id]);
        $this->assertTrue(Storage::disk('local')->missing($document->stored_path));
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

    public function test_ai_evidence_refresh_is_deprecated_and_performs_no_knowledge_base_retrieval(): void
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

        $cvKnowledge = $this->createKnowledgeItem($context['customer'], [
            'title' => 'CV profile',
            'content_type' => KnowledgeItem::CONTENT_TYPE_CV,
            'content' => 'CV erfaring',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($cvKnowledge);

        // The embedding service must never be called by the deprecated endpoint.
        $embeddingService = Mockery::mock(EmbeddingService::class);
        $embeddingService->shouldNotReceive('tryEmbedText');
        $this->app->instance(EmbeddingService::class, $embeddingService);

        $response = $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.ai.evidence.refresh', ['savedNotice' => $savedNotice->id]));

        $response->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));
        $response->assertSessionHas('error');

        $this->assertSame(0, SavedNoticeAiEvidence::query()
            ->where('saved_notice_ai_requirement_id', $confirmedRequirement->id)
            ->count());

        $pageResponse = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $pageResponse->assertOk();
        $page = $this->inertiaPageFromResponse($pageResponse);
        $this->assertNull(data_get($page, 'props.evidence_refresh_url'));
    }

    public function test_ai_evidence_selection_status_update_is_deprecated_and_leaves_existing_evidence_unchanged(): void
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

        // Historical evidence row, created before the manual-curation flow was deprecated.
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

        $selectionStatusUrlOne = route('app.ai.evidence.selection-status.update', [
            'savedNotice' => $savedNotice->id,
            'evidence' => $evidenceOne->id,
        ]);

        $response = $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->patch($selectionStatusUrlOne, [
                'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SELECTED,
            ]);

        $response->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));
        $response->assertSessionHas('error');

        $evidenceOne->refresh();
        $this->assertSame(SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED, $evidenceOne->selection_status);
        $this->assertFalse($evidenceOne->is_primary);
    }

    public function test_ai_evidence_selection_status_update_still_enforces_customer_scoped_ownership(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4009', 'Evidence ownership target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 14:25:00');

        $foreignContext = $this->customerAdminContext('Foreign Evidence AS');
        $foreignSavedNotice = $this->createSavedNotice($foreignContext['customer']->id, 'AI-4010', 'Foreign evidence target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($foreignSavedNotice, '2026-04-06 14:26:00');

        $foreignDocument = $this->createAiDocument($foreignSavedNotice, [
            'uploaded_by_user_id' => $foreignContext['user']->id,
            'original_filename' => 'foreign-evidence.docx',
            'stored_path' => 'saved-notices/'.$foreignSavedNotice->id.'/ai-documents/foreign-evidence.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 3072,
            'extracted_text' => 'Foreign requirement source text.',
            'text_extracted_at' => '2026-04-06 14:27:00',
        ]);
        $foreignChunk = $foreignDocument->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Foreign krav.',
            'char_start' => 0,
            'char_end' => 12,
            'word_count' => 2,
        ]);
        $foreignRequirement = $this->createAiRequirement($foreignSavedNotice, $foreignDocument, $foreignChunk, [
            'requirement_text' => 'Foreign krav.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);
        $foreignKnowledge = $this->createKnowledgeItem($foreignContext['customer'], [
            'title' => 'Foreign knowledge',
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'content' => 'Foreign krav.',
            'is_active' => true,
        ]);
        $this->syncKnowledgeItemChunks($foreignKnowledge);
        $foreignKnowledgeChunk = $foreignKnowledge->chunks()->firstOrFail();
        $foreignEvidence = SavedNoticeAiEvidence::query()->create([
            'saved_notice_ai_requirement_id' => $foreignRequirement->id,
            'knowledge_item_id' => $foreignKnowledge->id,
            'knowledge_item_chunk_id' => $foreignKnowledgeChunk->id,
            'match_type' => SavedNoticeAiEvidence::MATCH_TYPE_AUTO_MATCH,
            'match_score' => 5,
            'match_rank' => 1,
            'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED,
            'is_primary' => false,
            'created_by_user_id' => null,
        ]);

        $this->actingAs($context['user'])
            ->patch(route('app.ai.evidence.selection-status.update', [
                'savedNotice' => $savedNotice->id,
                'evidence' => $foreignEvidence->id,
            ]), [
                'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SELECTED,
            ])
            ->assertNotFound();
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
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal ha ISO 27001-sertifisering.',
        ]);
        $this->createWikiAnswer($requirement, 'Leverandøren er ISO 27001-sertifisert og kan dokumentere dette.');

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.requirements.export.docx', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $response->assertStreamed();
        $response->assertDownload('tilbudsbesvarelse-'.$savedNotice->id.'.docx');
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    /**
     * W2 – Export only includes requirements that have a generated Wiki answer.
     * Proves that requirements without a Wiki answer are silently excluded from
     * the exported document — the endpoint still returns 200.
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
        $requirementWithAnswer = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_identifier' => '2.1',
            'requirement_text' => 'Krav med utkast.',
        ]);
        $this->createWikiAnswer($requirementWithAnswer, 'Dette kravet har et svarutkast.');

        $chunkB = $this->createAiDocumentChunk($document, 'Krav uten utkast.', 1);
        $this->createAiRequirement($savedNotice, $document, $chunkB, [
            'requirement_identifier' => '2.2',
            'requirement_text' => 'Krav uten utkast.',
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
    ): SavedNoticeInfoItem {
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
     * Purpose: Persist a minimal generated Wiki answer for a requirement fixture — the export flow's
     *          only content source after the AI-to-Wiki consolidation (legacy answer_draft_text is
     *          no longer read).
     * Inputs: The requirement and the answer text to store.
     * Returns: The persisted Wiki-answer row.
     * Side effects: Writes one saved_notice_ai_requirement_wiki_answers row.
     */
    private function createWikiAnswer(SavedNoticeAiRequirement $requirement, string $answerText): SavedNoticeAiRequirementWikiAnswer
    {
        return SavedNoticeAiRequirementWikiAnswer::query()->create([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'coverage_status' => SavedNoticeAiRequirementWikiAnswer::COVERAGE_FULL,
            'answer_text' => $answerText,
            'sources' => [],
            'research_trace' => ['research' => ['pages' => []], 'answer' => ['answer_sections' => []]],
            'alignment_trace' => ['sections' => [], 'coverage_status' => 'full', 'has_possible_conflict' => false, 'revision' => ['attempted' => false, 'section_keys' => []]],
            'generated_at' => now(),
        ]);
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

        $storagePath = $overrides['storage_path'] ?? sprintf('customers/%d/knowledge-documents/%s', $customer->id, $slug.'.docx');
        $extractionStatus = $overrides['extraction_status'] ?? KnowledgeItem::EXTRACTION_STATUS_COMPLETED;

        $kiOverrides = array_diff_key($overrides, array_flip(['original_filename', 'storage_path', 'mime_type', 'file_size_bytes']));

        $item = KnowledgeItem::query()->create(array_merge([
            'customer_id' => $customer->id,
            'title' => $title,
            'content' => $extractedText,
            'document_type' => $documentType,
            'extracted_text' => $extractedText,
            'extraction_status' => $extractionStatus,
            'extraction_error' => $overrides['extraction_error'] ?? null,
            'uploaded_by_user_id' => $overrides['uploaded_by_user_id'] ?? null,
        ], $kiOverrides));

        // Every knowledge item needs a current version so retrieval guards can use version fields.
        // extracted_text must be set here: KnowledgeItem::resolvedExtractedText() (used by
        // textForKnowledgeProcessing(), which chunking reads) resolves from currentVersion.extracted_text
        // only — never from the KnowledgeItem's own extracted_text/content columns. Omitting it here
        // silently produces zero chunks from syncKnowledgeItemChunks(), not a chunking failure.
        KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $item->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
            'original_filename' => $originalFilename,
            'storage_path' => $storagePath,
            'extraction_status' => $extractionStatus,
            'extracted_text' => $extractedText,
        ]);

        return $item;
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

        $currentVersionId = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $knowledgeItem->id)
            ->where('is_current', true)
            ->value('id');

        $chunkPayloads = app(DocumentChunker::class)->chunkText((string) $knowledgeItem->textForKnowledgeProcessing());

        $knowledgeItem->chunks()->createMany(array_map(
            static fn (array $chunk, int $chunkIndex): array => [
                'chunk_index' => $chunkIndex,
                'content' => (string) ($chunk['content'] ?? ''),
                'start_offset' => (int) ($chunk['char_start'] ?? 0),
                'end_offset' => (int) ($chunk['char_end'] ?? 0),
                'knowledge_item_version_id' => $currentVersionId,
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
    private function inertiaPageFromResponse(TestResponse $response): array
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

        $zip = new ZipArchive;
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

    public function test_requirement_payload_keeps_preview_link_for_hidden_source_document_with_duplicate_filename(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-DEDUP-003', 'Dedup requirement target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);

        Storage::fake('local');

        $olderDocument = $this->createAiDocument($savedNotice, [
            'original_filename' => 'kravspesifikasjon.pdf',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/kravspesifikasjon-older.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 2048,
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED,
            'extracted_text' => 'Eldre opplasting med kravgrunnlag.',
            'text_extracted_at' => '2026-04-06 12:00:00',
        ]);
        Storage::disk('local')->put($olderDocument->stored_path, 'older-pdf-content');
        $olderChunk = $this->createAiDocumentChunk($olderDocument, 'Eldre opplasting med kravgrunnlag.');
        $requirement = $this->createAiRequirement($savedNotice, $olderDocument, $olderChunk, [
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        $newerDocument = $this->createAiDocument($savedNotice, [
            'original_filename' => 'kravspesifikasjon.pdf',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/kravspesifikasjon-newer.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 4096,
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED,
            'extracted_text' => 'Nyere opplasting med samme filnavn.',
            'text_extracted_at' => '2026-04-06 12:10:00',
        ]);
        Storage::disk('local')->put($newerDocument->stored_path, 'newer-pdf-content');

        $response = $this->actingAs($context['user'])->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($olderDocument, $newerDocument, $savedNotice): bool {
            $documents = collect(data_get($page, 'props.documents', []));
            $requirements = collect(data_get($page, 'props.requirements', []));
            $requirementRow = $requirements->firstWhere('saved_notice_ai_document_id', $olderDocument->id);

            return data_get($page, 'component') === 'App/AI/Show'
                && $documents->count() === 1
                && (int) data_get($documents->first(), 'id') === $newerDocument->id
                && (int) data_get($requirementRow, 'saved_notice_ai_document_id') === $olderDocument->id
                && data_get($requirementRow, 'document_filename') === $olderDocument->original_filename
                && data_get($requirementRow, 'source_document_preview_url') === route('app.ai.documents.preview', [
                    'savedNotice' => $savedNotice->id,
                    'document' => $olderDocument->id,
                ])
                && data_get($requirementRow, 'source_document_preview_url') !== route('app.ai.documents.preview', [
                    'savedNotice' => $savedNotice->id,
                    'document' => $newerDocument->id,
                ]);
        });
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

    public function test_knowledge_sources_payload_contains_source_with_version_info(): void
    {
        $context = $this->customerAdminContext();

        $savedNotice = $this->createSavedNotice($context['customer']->id, 'KS-001', 'Kunnskapskilder test', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'ks-source.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/ks-source.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Krav om metode og erfaring.',
            'text_extracted_at' => '2026-06-22 12:00:00',
        ]);

        $sourceChunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Krav om metode og erfaring.',
            'char_start' => 0,
            'char_end' => 27,
            'word_count' => 5,
        ]);

        $requirement = $this->createAiRequirement($savedNotice, $document, $sourceChunk, [
            'requirement_text' => 'Krav om metode og erfaring.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
        ]);

        $knowledgeItem = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Metode og erfaring',
            'content' => 'Metode og erfaring dokumentasjon.',
            'is_active' => true,
        ]);

        $version = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $knowledgeItem->id)
            ->where('version_no', 1)
            ->firstOrFail();

        $chunk = $knowledgeItem->chunks()->create([
            'knowledge_item_version_id' => $version->id,
            'chunk_index' => 0,
            'content' => 'Metode og erfaring dokumentasjon.',
            'start_offset' => 0,
            'end_offset' => 32,
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_APPROVED,
        ]);

        SavedNoticeAiEvidence::query()->create([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'knowledge_item_id' => $knowledgeItem->id,
            'knowledge_item_chunk_id' => $chunk->id,
            'knowledge_item_version_id' => $version->id,
            'match_type' => SavedNoticeAiEvidence::MATCH_TYPE_AUTO_MATCH,
            'match_score' => 5,
            'match_rank' => 1,
            'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED,
            'is_primary' => false,
            'created_by_user_id' => $context['user']->id,
        ]);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirements = collect(data_get($page, 'props.requirements', []))->keyBy('id');
        $sources = collect(data_get($requirements->get($requirement->id), 'knowledge_sources_sent_to_ai', null));

        $this->assertIsArray(data_get($requirements->get($requirement->id), 'knowledge_sources_sent_to_ai'));
        $this->assertCount(1, $sources);

        $source = $sources->first();
        $this->assertSame($knowledgeItem->id, $source['knowledge_item_id']);
        $this->assertSame($version->id, $source['knowledge_item_version_id']);
        $this->assertSame(1, $source['knowledge_item_version_no']);
        $this->assertSame($knowledgeItem->resolvedOriginalFilename(), $source['original_filename']);
        $this->assertSame($chunk->id, $source['chunk_id']);
        $this->assertSame(5, $source['match_score']);
        $this->assertSame(1, $source['match_rank']);
        $this->assertTrue($source['version_is_current_now']);
    }

    public function test_knowledge_sources_payload_is_empty_array_when_no_evidence(): void
    {
        $context = $this->customerAdminContext();

        $savedNotice = $this->createSavedNotice($context['customer']->id, 'KS-002', 'Tom kildeliste test', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'ks-empty.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/ks-empty.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Krav uten kunnskapskilder.',
            'text_extracted_at' => '2026-06-22 12:05:00',
        ]);

        $sourceChunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Krav uten kunnskapskilder.',
            'char_start' => 0,
            'char_end' => 25,
            'word_count' => 4,
        ]);

        $requirement = $this->createAiRequirement($savedNotice, $document, $sourceChunk, [
            'requirement_text' => 'Krav uten kunnskapskilder.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
        ]);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirements = collect(data_get($page, 'props.requirements', []))->keyBy('id');
        $sources = data_get($requirements->get($requirement->id), 'knowledge_sources_sent_to_ai');

        $this->assertIsArray($sources);
        $this->assertSame([], $sources);
    }

    public function test_knowledge_sources_payload_shows_version_not_current_when_superseded(): void
    {
        $context = $this->customerAdminContext();

        $savedNotice = $this->createSavedNotice($context['customer']->id, 'KS-003', 'Versjonsstabilitet test', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'ks-version.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/ks-version.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Krav for versjonsstabilitet.',
            'text_extracted_at' => '2026-06-22 12:10:00',
        ]);

        $sourceChunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Krav for versjonsstabilitet.',
            'char_start' => 0,
            'char_end' => 27,
            'word_count' => 4,
        ]);

        $requirement = $this->createAiRequirement($savedNotice, $document, $sourceChunk, [
            'requirement_text' => 'Krav for versjonsstabilitet.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
        ]);

        $knowledgeItem = $this->createKnowledgeItem($context['customer'], [
            'title' => 'Versjonsstabilitet dokument',
            'content' => 'Versjonsstabilitet dokumentasjon.',
            'is_active' => true,
        ]);

        $versionOne = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $knowledgeItem->id)
            ->where('version_no', 1)
            ->firstOrFail();

        $chunkOne = $knowledgeItem->chunks()->create([
            'knowledge_item_version_id' => $versionOne->id,
            'chunk_index' => 0,
            'content' => 'Versjonsstabilitet dokumentasjon.',
            'start_offset' => 0,
            'end_offset' => 32,
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_APPROVED,
        ]);

        SavedNoticeAiEvidence::query()->create([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'knowledge_item_id' => $knowledgeItem->id,
            'knowledge_item_chunk_id' => $chunkOne->id,
            'knowledge_item_version_id' => $versionOne->id,
            'match_type' => SavedNoticeAiEvidence::MATCH_TYPE_AUTO_MATCH,
            'match_score' => 3,
            'match_rank' => 1,
            'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED,
            'is_primary' => false,
            'created_by_user_id' => $context['user']->id,
        ]);

        // Simulate v1 being superseded by v2 — evidence still points to v1.
        $versionOne->update(['is_current' => false]);
        KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $knowledgeItem->id,
            'customer_id' => $context['customer']->id,
            'version_no' => 2,
            'is_current' => true,
            'storage_path' => 'customers/'.$context['customer']->id.'/knowledge-items/versjonsstabilitet-v2.docx',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
        ]);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirements = collect(data_get($page, 'props.requirements', []))->keyBy('id');
        $sources = collect(data_get($requirements->get($requirement->id), 'knowledge_sources_sent_to_ai', []));

        $this->assertCount(1, $sources);
        $source = $sources->first();
        $this->assertSame($versionOne->id, $source['knowledge_item_version_id']);
        $this->assertSame(1, $source['knowledge_item_version_no']);
        $this->assertFalse($source['version_is_current_now'],
            'Evidence pointing to the superseded version should show version_is_current_now = false.');
    }

    public function test_knowledge_sources_payload_respects_customer_scope(): void
    {
        $contextA = $this->customerAdminContext('Scope Kunde A AS');
        $contextB = $this->customerAdminContext('Scope Kunde B AS');

        $savedNotice = $this->createSavedNotice($contextA['customer']->id, 'KS-005', 'Kundeskopetest', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $contextA['user']->id,
            'original_filename' => 'ks-scope.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/ks-scope.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Krav for kundescoping.',
            'text_extracted_at' => '2026-06-22 12:20:00',
        ]);

        $sourceChunk = $document->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Krav for kundescoping.',
            'char_start' => 0,
            'char_end' => 22,
            'word_count' => 4,
        ]);

        $requirement = $this->createAiRequirement($savedNotice, $document, $sourceChunk, [
            'requirement_text' => 'Krav for kundescoping.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
        ]);

        // Customer A's knowledge item with evidence.
        $knowledgeItemA = $this->createKnowledgeItem($contextA['customer'], [
            'title' => 'Kundescopetest A',
            'content' => 'Kundescoping dokumentasjon A.',
            'is_active' => true,
        ]);
        $versionA = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $knowledgeItemA->id)
            ->where('version_no', 1)
            ->firstOrFail();
        $chunkA = $knowledgeItemA->chunks()->create([
            'knowledge_item_version_id' => $versionA->id,
            'chunk_index' => 0,
            'content' => 'Kundescoping dokumentasjon A.',
            'start_offset' => 0,
            'end_offset' => 28,
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_APPROVED,
        ]);
        SavedNoticeAiEvidence::query()->create([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'knowledge_item_id' => $knowledgeItemA->id,
            'knowledge_item_chunk_id' => $chunkA->id,
            'knowledge_item_version_id' => $versionA->id,
            'match_type' => SavedNoticeAiEvidence::MATCH_TYPE_AUTO_MATCH,
            'match_score' => 4,
            'match_rank' => 1,
            'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED,
            'is_primary' => false,
            'created_by_user_id' => $contextA['user']->id,
        ]);

        // Customer B's knowledge item — must NOT appear in Customer A's payload.
        $knowledgeItemB = $this->createKnowledgeItem($contextB['customer'], [
            'title' => 'Kundescopetest B',
            'content' => 'Kundescoping dokumentasjon B.',
            'is_active' => true,
        ]);

        $response = $this->actingAs($contextA['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirements = collect(data_get($page, 'props.requirements', []))->keyBy('id');
        $sources = collect(data_get($requirements->get($requirement->id), 'knowledge_sources_sent_to_ai', []));

        $this->assertCount(1, $sources);
        $this->assertSame($knowledgeItemA->id, $sources->first()['knowledge_item_id']);
        $this->assertFalse(
            $sources->contains(fn (array $s): bool => $s['knowledge_item_id'] === $knowledgeItemB->id),
            'Customer B knowledge item must not appear in Customer A\'s sources payload.'
        );
    }
}
