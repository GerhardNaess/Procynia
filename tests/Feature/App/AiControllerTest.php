<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\KnowledgeItem;
use App\Models\SavedNotice;
use App\Models\SavedNoticeInfoItem;
use App\Models\SavedNoticePhaseComment;
use App\Models\BidSubmission;
use App\Models\Nationality;
use App\Models\KnowledgeItemChunk;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\SavedNoticeAiEvidence;
use App\Models\SavedNoticeAiRequirementAssessment;
use App\Models\SavedNoticeAiRequirement;
use App\Services\OpenAi\EmbeddingService;
use App\Services\RequirementExtractor;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
    }

    protected function tearDown(): void
    {
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
                && data_get($page, 'props.pageTitle') === 'AI-arbeid'
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
                && data_get($page, 'props.pageTitle') === 'AI-arbeid'
                && data_get($page, 'props.analysisCases') === []
                && ! array_key_exists('tabs', data_get($page, 'props', []))
                && ! array_key_exists('activeTab', data_get($page, 'props', []));
        });

        $documentsResponse = $this->actingAs($context['user'])->get('/app/ai?tab=documents');
        $documentsResponse->assertOk();
        $documentsResponse->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/AI/Index'
                && data_get($page, 'props.pageTitle') === 'AI-arbeid'
                && data_get($page, 'props.analysisCases') === []
                && ! array_key_exists('tabs', data_get($page, 'props', []))
                && ! array_key_exists('activeTab', data_get($page, 'props', []));
        });

        $fallbackResponse = $this->actingAs($context['user'])->get('/app/ai?tab=bogus');
        $fallbackResponse->assertOk();
        $fallbackResponse->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/AI/Index'
                && data_get($page, 'props.pageTitle') === 'AI-arbeid'
                && data_get($page, 'props.analysisCases') === []
                && ! array_key_exists('tabs', data_get($page, 'props', []))
                && ! array_key_exists('activeTab', data_get($page, 'props', []));
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
                && data_get($page, 'props.pageTitle') === 'AI-arbeid'
                && $analysisCases->count() === 3
                && $analysisCases->pluck('id')->all() === [$inReviewNotice->id, $readyNotice->id, $notStartedNotice->id]
                && $analysisById->get($notStartedNotice->id)['ai_status'] === 'not_started'
                && $analysisById->get($readyNotice->id)['ai_status'] === 'ready'
                && $analysisById->get($inReviewNotice->id)['ai_status'] === 'in_review'
                && $analysisById->get($notStartedNotice->id)['owner_name'] === 'Not assigned'
                && $analysisById->get($readyNotice->id)['owner_name'] === 'Ready Owner'
                && $analysisById->get($inReviewNotice->id)['owner_name'] === 'Review Manager'
                && $analysisById->get($notStartedNotice->id)['stage_label'] === SavedNotice::BID_STATUS_LABELS[SavedNotice::BID_STATUS_DISCOVERED]
                && $analysisById->get($readyNotice->id)['stage_label'] === SavedNotice::BID_STATUS_LABELS[SavedNotice::BID_STATUS_QUALIFYING]
                && $analysisById->get($inReviewNotice->id)['stage_label'] === SavedNotice::BID_STATUS_LABELS[SavedNotice::BID_STATUS_GO_NO_GO]
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
                && data_get($page, 'props.pageTitle') === 'AI-arbeid · Case view target'
                && data_get($page, 'props.ai_status') === 'ready'
                && data_get($page, 'props.search_query') === ''
                && data_get($page, 'props.search_results') === []
                && data_get($page, 'props.search_url') === route('app.ai.show', ['savedNotice' => $savedNotice->id])
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

    public function test_ai_case_view_searches_document_chunks_within_the_visible_saved_notice_only(): void
    {
        $context = $this->customerAdminContext();
        $owner = User::factory()->create([
            'name' => 'Search Owner',
            'email' => 'search.owner@example.test',
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2002', 'Search target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'opportunity_owner_user_id' => $owner->id,
            'reference_number' => 'REF-2002',
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 10:15:00');

        $targetDocument = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'target-pack.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/target-pack.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => 'Procynia tender workspace needs review. The PROCYNIA team confirms the tender scope.',
            'text_extracted_at' => '2026-04-06 10:20:00',
        ]);
        $targetDocument->chunks()->createMany([
            [
                'chunk_index' => 0,
                'content' => 'Procynia tender workspace needs review.',
                'char_start' => 0,
                'char_end' => 39,
                'word_count' => 5,
            ],
            [
                'chunk_index' => 1,
                'content' => 'The PROCYNIA team confirms the tender scope.',
                'char_start' => 40,
                'char_end' => 85,
                'word_count' => 7,
            ],
        ]);

        $otherSavedNotice = $this->createSavedNotice($context['customer']->id, 'AI-2003', 'Other search case', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'opportunity_owner_user_id' => $owner->id,
        ]);
        $this->touchSavedNotice($otherSavedNotice, '2026-04-06 10:05:00');
        $otherDocument = $this->createAiDocument($otherSavedNotice, [
            'uploaded_by_user_id' => $owner->id,
            'original_filename' => 'other-pack.docx',
            'stored_path' => 'saved-notices/'.$otherSavedNotice->id.'/ai-documents/other-pack.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => 'Procynia tender workspace appears in another case too.',
            'text_extracted_at' => '2026-04-06 10:21:00',
        ]);
        $otherDocument->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Procynia tender workspace appears in another case too.',
            'char_start' => 0,
            'char_end' => 54,
            'word_count' => 8,
        ]);

        $response = $this->actingAs($context['user'])->get(route('app.ai.show', [
            'savedNotice' => $savedNotice->id,
            'search' => '  PROCYNIA  ',
        ]));

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($savedNotice): bool {
            $searchResults = collect(data_get($page, 'props.search_results', []));

            return data_get($page, 'component') === 'App/AI/Show'
                && data_get($page, 'props.search_query') === 'PROCYNIA'
                && data_get($page, 'props.search_url') === route('app.ai.show', ['savedNotice' => $savedNotice->id])
                && $searchResults->count() === 2
                && $searchResults->pluck('document_filename')->all() === ['target-pack.docx', 'target-pack.docx']
                && $searchResults->pluck('chunk_index')->all() === [0, 1]
                && filled($searchResults->first()['snippet'])
                && str_contains($searchResults->first()['snippet'], 'Procynia tender workspace')
                && ! $searchResults->contains(fn (array $result): bool => $result['document_filename'] === 'other-pack.docx');
        });
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

        $document = $this->createDocxUpload('requirements-pack.docx', implode("\n\n", [
            'Dokumentasjon må vedlegges.',
            'Tilbudet må leveres innen tilbudsfrist.',
            'Tilbudet må leveres innen tilbudsfrist.',
            'Skytjeneste skal avsluttes eller tilbakeføres til Kunden ved opphør av avtalen.',
            'Leverandøren skal beskrive løsningen.',
        ]));

        $this->actingAs($context['user'])
            ->post(route('app.ai.documents.store', ['savedNotice' => $savedNotice->id]), [
                'documents' => [$document],
            ])
            ->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $savedDocument = SavedNoticeAiDocument::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('original_filename', 'requirements-pack.docx')
            ->firstOrFail();

        $chunks = SavedNoticeAiDocumentChunk::query()
            ->where('saved_notice_ai_document_id', $savedDocument->id)
            ->orderBy('chunk_index')
            ->get();

        $requirements = SavedNoticeAiRequirement::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('saved_notice_ai_document_id', $savedDocument->id)
            ->orderBy('id')
            ->get();

        $this->assertSame(1, $chunks->count());
        $this->assertSame(4, $requirements->count());
        $this->assertSame(
            [
                SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
                SavedNoticeAiRequirement::REQUIREMENT_TYPE_ADMINISTRATIVE,
                SavedNoticeAiRequirement::REQUIREMENT_TYPE_MANDATORY,
                SavedNoticeAiRequirement::REQUIREMENT_TYPE_MANDATORY,
            ],
            $requirements->pluck('requirement_type')->all(),
        );
        $this->assertSame(
            [
                'Dokumentasjon må vedlegges.',
                'Tilbudet må leveres innen tilbudsfrist.',
                'Skytjeneste skal avsluttes eller tilbakeføres til Kunden ved opphør av avtalen.',
                'Leverandøren skal beskrive løsningen.',
            ],
            $requirements->pluck('requirement_text')->all(),
        );
        $this->assertSame(
            [
                SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
                SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
                SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
                SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            ],
            $requirements->pluck('review_status')->all(),
        );
        $this->assertSame(
            [
                SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
                SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
                SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
                SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            ],
            $requirements->pluck('extraction_method')->all(),
        );
        $this->assertSame([$savedDocument->id, $savedDocument->id, $savedDocument->id, $savedDocument->id], $requirements->pluck('saved_notice_ai_document_id')->all());
        $this->assertSame([$chunks->first()->id, $chunks->first()->id, $chunks->first()->id, $chunks->first()->id], $requirements->pluck('saved_notice_ai_document_chunk_id')->all());

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));

        $response->assertOk();
        $page = $this->inertiaPageFromResponse($response);
        $requirements = collect(data_get($page, 'props.requirements', []));
        $requirementsByText = $requirements->keyBy('requirement_text');

        $this->assertSame('App/AI/Show', data_get($page, 'component'));
        $this->assertSame(4, data_get($page, 'props.requirements_count'));
        $this->assertCount(4, $requirements);
        $this->assertSame(SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION, $requirementsByText->get('Dokumentasjon må vedlegges.')['requirement_type']);
        $this->assertSame(SavedNoticeAiRequirement::REVIEW_STATUS_PENDING, $requirementsByText->get('Dokumentasjon må vedlegges.')['review_status']);
        $this->assertSame(route('app.ai.requirements.review-status.update', [
            'savedNotice' => $savedNotice->id,
            'requirement' => $requirements->first()['id'],
        ]), $requirementsByText->get('Dokumentasjon må vedlegges.')['review_status_update_url']);
        $this->assertSame('requirements-pack.docx', $requirementsByText->get('Dokumentasjon må vedlegges.')['document_filename']);
        $this->assertSame(0, $requirementsByText->get('Dokumentasjon må vedlegges.')['chunk_index']);
        $this->assertSame(SavedNoticeAiRequirement::REQUIREMENT_TYPE_ADMINISTRATIVE, $requirementsByText->get('Tilbudet må leveres innen tilbudsfrist.')['requirement_type']);
        $this->assertSame(SavedNoticeAiRequirement::REQUIREMENT_TYPE_MANDATORY, $requirementsByText->get('Skytjeneste skal avsluttes eller tilbakeføres til Kunden ved opphør av avtalen.')['requirement_type']);
        $this->assertSame(SavedNoticeAiRequirement::REQUIREMENT_TYPE_MANDATORY, $requirementsByText->get('Leverandøren skal beskrive løsningen.')['requirement_type']);
        $this->assertSame(1, $requirements->filter(fn (array $requirement): bool => $requirement['requirement_text'] === 'Tilbudet må leveres innen tilbudsfrist.')->count());
    }

    public function test_ai_documents_upload_persists_files_and_redirects_back_to_the_case_view(): void
    {
        Storage::fake('local');

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
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_UPLOADED, $documents->first()->processing_status);
        $this->assertSame('', (string) $requirementsDocument->extracted_text);
        $this->assertNotNull($requirementsDocument->text_extracted_at);
        $this->assertStringStartsWith('saved-notices/'.$savedNotice->id.'/ai-documents/', $requirementsDocument->stored_path);
        $this->assertSame($longText, $scopeNoteDocument->extracted_text);
        $this->assertNotNull($scopeNoteDocument->text_extracted_at);
        $this->assertStringStartsWith('saved-notices/'.$savedNotice->id.'/ai-documents/', $scopeNoteDocument->stored_path);
        $this->assertSame(0, $requirementsChunks->count());
        $this->assertGreaterThan(1, $scopeNoteChunks->count());
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
                    && $documents->first()['processing_status'] === SavedNoticeAiDocument::PROCESSING_STATUS_UPLOADED
                    && $documentsByFilename->get('requirements-list.docx')['has_extracted_text'] === false
                    && $documentsByFilename->get('scope-note.docx')['has_extracted_text'] === true
                    && $documentsByFilename->get('requirements-list.docx')['chunk_count'] === 0
                    && $documentsByFilename->get('scope-note.docx')['chunk_count'] > 1
                    && $documentsByFilename->get('scope-note.docx')['delete_url'] === route('app.ai.documents.destroy', [
                        'savedNotice' => $savedNotice->id,
                        'document' => $documentsByFilename->get('scope-note.docx')['id'],
                    ]);
            });
    }

    public function test_ai_documents_upload_keeps_empty_extracted_text_when_parsing_fails(): void
    {
        Storage::fake('local');

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

        $document = SavedNoticeAiDocument::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->firstOrFail();

        $this->assertSame('', (string) $document->extracted_text);
        $this->assertNotNull($document->text_extracted_at);
        $this->assertStringStartsWith('saved-notices/'.$savedNotice->id.'/ai-documents/', $document->stored_path);
        $this->assertSame(0, SavedNoticeAiDocumentChunk::query()->where('saved_notice_ai_document_id', $document->id)->count());

        $this->actingAs($context['user'])
            ->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->assertViewHas('page', function (array $page): bool {
                $documents = collect(data_get($page, 'props.documents', []));

                return $documents->count() === 1
                    && $documents->first()['has_extracted_text'] === false
                    && $documents->first()['chunk_count'] === 0;
            });
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

        $this->assertSame(2, data_get($page, 'props.requirements_count'));
        $this->assertTrue($assignedUserOptions->contains(fn (array $option): bool => $option['value'] === $assignedUser->id));
        $this->assertSame(SavedNoticeAiRequirement::WORK_STATUS_IN_PROGRESS, $requirements->get($confirmedRequirement->id)['work_status']);
        $this->assertSame(
            SavedNoticeAiRequirement::WORK_STATUS_LABELS[SavedNoticeAiRequirement::WORK_STATUS_IN_PROGRESS],
            $requirements->get($confirmedRequirement->id)['work_status_label'],
        );
        $this->assertSame($assignedUser->id, $requirements->get($confirmedRequirement->id)['assigned_user']['id']);
        $this->assertSame($assignedUser->name, $requirements->get($confirmedRequirement->id)['assigned_user']['name']);
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
        $this->assertSame(1, data_get($requirementsOverview, 'pending_total'));
        $this->assertSame(1, data_get($requirementsOverview, 'rejected_total'));
        $this->assertSame(1, data_get($requirementsOverview, 'not_started_total'));
        $this->assertSame(1, data_get($requirementsOverview, 'in_progress_total'));
        $this->assertSame(2, data_get($requirementsOverview, 'done_total'));
        $this->assertSame(2, data_get($requirementsOverview, 'unassigned_confirmed_total'));
        $this->assertSame(6, $requirements->count());
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
            return [
                'ok' => true,
                'embedding' => [0.11, 0.22, 0.33],
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
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-4009', 'Assessment target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
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

    private function customerAdminContext(string $customerName = 'Procynia AS'): array
    {
        $customer = $this->createCustomer($customerName);

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
    private function createInfoItem(int $savedNoticeId, int $ownerUserId, int $createdByUserId, string $subject): SavedNoticeInfoItem
    {
        $attributes = [
            'saved_notice_id' => $savedNoticeId,
            'type' => SavedNoticeInfoItem::TYPE_NOTE,
            'direction' => SavedNoticeInfoItem::DIRECTION_INTERNAL,
            'channel' => SavedNoticeInfoItem::CHANNEL_MANUAL,
            'subject' => $subject,
            'body' => 'AI analysis foundation item.',
            'status' => SavedNoticeInfoItem::STATUS_OPEN,
            'requires_response' => false,
            'owner_user_id' => $ownerUserId,
            'created_by_user_id' => $createdByUserId,
        ];

        if (Schema::hasColumn('saved_notice_info_items', 'closed_at')) {
            $attributes['closed_at'] = null;
        }

        if (Schema::hasColumn('saved_notice_info_items', 'closure_comment')) {
            $attributes['closure_comment'] = null;
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
        return SavedNoticeAiRequirement::query()->create(array_merge([
            'saved_notice_id' => $savedNotice->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_ai_document_chunk_id' => $chunk->id,
            'requirement_text' => $overrides['requirement_text'] ?? 'Dokumentasjon må vedlegges.',
            'requirement_type' => $overrides['requirement_type'] ?? SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => $overrides['extraction_method'] ?? SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'review_status' => $overrides['review_status'] ?? SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'work_status' => $overrides['work_status'] ?? SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
            'assigned_user_id' => $overrides['assigned_user_id'] ?? null,
        ], $overrides));
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
