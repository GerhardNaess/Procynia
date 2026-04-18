<?php

namespace Tests\Unit;

use App\Data\Ai\Requirements\RequirementEditData;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\RequirementExtractionCall;
use App\Models\RequirementExtractionRun;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\SavedNoticeAiRequirement;
use App\Models\User;
use App\Services\Ai\Requirements\RequirementEditorService;
use App\Services\Ai\Requirements\RequirementExtractionRunService;
use App\Services\Ai\Requirements\RequirementLoader;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class RequirementExtractionRunServiceTest extends TestCase
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

    public function test_it_promotes_staged_rows_and_leaves_manual_rows_visible_on_success(): void
    {
        Queue::fake();

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-1001', 'Async success target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 10:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'async-success.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/async-success.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager. Leverandøren skal levere dokumentasjon innen 10 dager.',
            'text_extracted_at' => '2026-04-06 10:01:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_TEXT_EXTRACTED,
        ]);
        $chunkOne = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.');
        $chunkTwo = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.', 1);

        $manualRequirement = app(RequirementEditorService::class)->createManualRequirement(
            $savedNotice,
            RequirementEditData::fromArray([
                'requirement_text' => 'Leverandøren skal beskrive løsning og bemanning.',
                'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
                'reason' => 'Manual note',
            ]),
            $context['user'],
        );

        $existingAiRequirement = $this->createAiRequirementRow($savedNotice, $document, $chunkOne, [
            'requirement_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => '2026-04-06 10:02:00',
        ]);

        $this->fakeOpenAiFullDocumentResponse([
            $this->buildFullDocumentCandidate('Leverandøren skal levere dokumentasjon innen 10 dager.', [
                'requirement_identifier' => '1.1',
                'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
                'obligation_type' => 'must',
                'interpretation_risk' => 'low',
                'source_reference_text' => 'Bilag 1 punkt 2.7',
            ]),
        ]);

        $runService = app(RequirementExtractionRunService::class);
        $run = $runService->createQueuedRunForDocument($document);
        $runService->processRun($run);

        $manualRequirement->refresh();
        $existingAiRequirement->refresh();

        $newPublishedRequirements = SavedNoticeAiRequirement::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED)
            ->orderBy('id')
            ->get();

        $this->assertSame(SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED, $manualRequirement->publication_status);
        $this->assertSame(SavedNoticeAiRequirement::PUBLICATION_STATUS_SUPERSEDED, $existingAiRequirement->publication_status);
        $this->assertCount(2, $newPublishedRequirements);
        $this->assertSame(1, $newPublishedRequirements->where('source_type', SavedNoticeAiRequirement::SOURCE_TYPE_MANUAL)->count());
        $this->assertSame(1, $newPublishedRequirements->where('source_type', SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE)->count());
        $this->assertSame(SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED, $newPublishedRequirements->firstWhere('source_type', SavedNoticeAiRequirement::SOURCE_TYPE_MANUAL)->publication_status);
        $this->assertSame(SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED, $newPublishedRequirements->firstWhere('source_type', SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE)->publication_status);
        $this->assertCount(2, app(RequirementLoader::class)->loadForCase($savedNotice->id));
        $this->assertCount(0, app(RequirementLoader::class)->loadApprovedForCase($savedNotice->id));
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED, $document->refresh()->processing_status);
        $completedRun = $run->refresh();
        $this->assertSame(RequirementExtractionRun::STATUS_COMPLETED, $completedRun->status);
        $this->assertNull($completedRun->failure_stage);
        $this->assertSame(1, RequirementExtractionCall::query()->where('requirement_extraction_run_id', $run->id)->count());
        $this->assertSame(RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION, $completedRun->strategy);
        $this->assertSame(1, $completedRun->openai_call_count);
        $this->assertSame(1, $completedRun->persisted_requirement_count);
        $this->assertSame(1, $completedRun->candidate_count);
        $aiRequirement = $newPublishedRequirements->firstWhere('source_type', SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE);
        $this->assertNotNull($aiRequirement);
        $this->assertNull($aiRequirement->saved_notice_ai_document_chunk_id);
        $this->assertTrue(str_starts_with($aiRequirement->source_reference['source_block_id'], sprintf('saved-notice-ai-document-%d-phase-1-', $document->id)));
        Http::assertSentCount(1);
    }

    public function test_it_skips_front_matter_and_persists_only_requirement_chunks(): void
    {
        Queue::fake();

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-1001A', 'Split planning target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 09:30:00');

        $documentText = implode("\n\n", [
            'Innholdsfortegnelse',
            '1. Kravområde 1 .... 2',
            '2. Veiledning om leverandørens besvarelse .... 3',
            '3. Kravområde 2 .... 4',
            '1. Kravområde 1' . "\n" . '1-1.S.1 Leverandøren skal levere dokumentasjon innen 10 dager.',
            '2. Veiledning om leverandørens besvarelse' . "\n" . 'Leverandøren skal skrive tydelig og kort.',
            '3. Kravområde 2' . "\n" . '1-1.S.2 Leverandøren skal beskrive løsning og bemanning.',
        ]);

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'split-front-matter.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/split-front-matter.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => $documentText,
            'text_extracted_at' => '2026-04-06 09:31:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_TEXT_EXTRACTED,
        ]);

        $this->fakeOpenAiFullDocumentResponse([
            $this->buildFullDocumentCandidate('Leverandøren skal levere dokumentasjon innen 10 dager.', [
                'requirement_identifier' => '1-1.S.1',
                'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
                'obligation_type' => 'must',
                'interpretation_risk' => 'low',
                'source_reference_text' => '1-1.S.1',
            ]),
        ]);

        $runService = app(RequirementExtractionRunService::class);
        $run = $runService->createQueuedRunForDocument($document);
        $runService->processRun($run);

        $chunks = SavedNoticeAiDocumentChunk::query()
            ->where('saved_notice_ai_document_id', $document->id)
            ->orderBy('chunk_index')
            ->get();

        $this->assertCount(2, $chunks);
        $this->assertStringStartsWith('2. Veiledning om leverandørens besvarelse', trim((string) $chunks[0]->content));
        $this->assertStringContainsString('Leverandøren skal skrive tydelig og kort.', $chunks[0]->content);
        $this->assertStringNotContainsString('Innholdsfortegnelse', $chunks[0]->content);
        $this->assertStringStartsWith('3. Kravområde 2', trim((string) $chunks[1]->content));
        $this->assertStringContainsString('1-1.S.2 Leverandøren skal beskrive løsning og bemanning.', $chunks[1]->content);
        $this->assertStringContainsString('Leverandøren skal beskrive løsning og bemanning.', $chunks[1]->content);
        $this->assertGreaterThan(0, $chunks[0]->char_start);
        Http::assertSentCount(2);
    }

    public function test_it_preserves_existing_published_rows_when_the_run_fails(): void
    {
        Queue::fake();

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-1002', 'Async failure target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 11:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'async-failure.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/async-failure.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal beskrive løsning og bemanning. Leverandøren skal beskrive løsning og bemanning.',
            'text_extracted_at' => '2026-04-06 11:01:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_TEXT_EXTRACTED,
        ]);
        $chunkOne = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsning og bemanning.');
        $chunkTwo = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsning og bemanning.', 1);

        $existingAiRequirement = $this->createAiRequirementRow($savedNotice, $document, $chunkOne, [
            'requirement_text' => 'Leverandøren skal beskrive løsning og bemanning.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => '2026-04-06 11:02:00',
        ]);

        $this->fakeOpenAiFullDocumentResponse([], 503, 120, 42);

        $runService = app(RequirementExtractionRunService::class);
        $run = $runService->createQueuedRunForDocument($document);
        $runService->processRun($run);

        $existingAiRequirement->refresh();

        $this->assertSame(SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED, $existingAiRequirement->publication_status);
        $failedRun = $run->refresh();
        $this->assertSame(RequirementExtractionRun::STATUS_FAILED, $failedRun->status);
        $this->assertSame('openai_http_status', $failedRun->failure_stage);
        $this->assertSame('upstream_error', $failedRun->error_type);
        $this->assertSame(RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION, $failedRun->strategy);
        $this->assertSame(1, $failedRun->openai_call_count);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_FAILED, $document->refresh()->processing_status);
        $calls = RequirementExtractionCall::query()
            ->where('requirement_extraction_run_id', $run->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(1, $calls);
        $this->assertSame(RequirementExtractionCall::STATUS_FAILED, $calls[0]->status);
        $this->assertSame(503, $calls[0]->status_code);
        $this->assertCount(1, app(RequirementLoader::class)->loadForCase($savedNotice->id));
        $this->assertCount(1, SavedNoticeAiRequirement::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED)
            ->get());
        $this->assertSame(0, SavedNoticeAiRequirement::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED)
            ->count());
        Http::assertSentCount(1);
    }

    public function test_it_fails_without_openai_when_extracted_text_is_missing_and_preserves_published_rows(): void
    {
        Http::fake();

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-1002B', 'Missing text target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 11:15:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'missing-text.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/missing-text.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => '',
            'text_extracted_at' => '2026-04-06 11:16:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_TEXT_EXTRACTED,
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Placeholder chunk text.');

        $existingAiRequirement = $this->createAiRequirementRow($savedNotice, $document, $chunk, [
            'requirement_text' => 'Existing published AI requirement',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => '2026-04-06 11:17:00',
        ]);

        $runService = app(RequirementExtractionRunService::class);
        $run = $runService->createQueuedRunForDocument($document);
        $runService->processRun($run);

        Http::assertNothingSent();

        $existingAiRequirement->refresh();

        $this->assertSame(SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED, $existingAiRequirement->publication_status);
        $failedRun = $run->refresh();
        $this->assertSame(RequirementExtractionRun::STATUS_FAILED, $failedRun->status);
        $this->assertSame('prompt_build', $failedRun->failure_stage);
        $this->assertSame('invalid_request', $failedRun->error_type);
        $this->assertSame(RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION, $failedRun->strategy);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_FAILED, $document->refresh()->processing_status);
        $this->assertSame(0, RequirementExtractionCall::query()->where('requirement_extraction_run_id', $run->id)->count());
        $this->assertCount(1, app(RequirementLoader::class)->loadForCase($savedNotice->id));
        $this->assertCount(1, SavedNoticeAiRequirement::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED)
            ->get());
        $this->assertSame(0, SavedNoticeAiRequirement::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED)
            ->count());
    }

    public function test_it_marks_phase_one_runs_as_timed_out_when_the_http_client_times_out(): void
    {
        Queue::fake();

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-1002C', 'Timeout target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 11:25:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'timeout-target.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/timeout-target.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'text_extracted_at' => '2026-04-06 11:26:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_TEXT_EXTRACTED,
        ]);

        $requestCount = 0;

        Http::fake(function (Request $request) use (&$requestCount) {
            $requestCount++;
            throw new ConnectionException('cURL error 28: Operation timed out after 180001 milliseconds with 0 bytes received.');
        });

        $runService = app(RequirementExtractionRunService::class);
        $run = $runService->createQueuedRunForDocument($document);
        $runService->processRun($run);

        $failedRun = $run->refresh();

        $this->assertSame(RequirementExtractionRun::STATUS_FAILED, $failedRun->status);
        $this->assertSame('openai_timeout', $failedRun->failure_stage);
        $this->assertSame('timeout', $failedRun->error_type);
        $this->assertSame(RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION, $failedRun->strategy);
        $this->assertSame(1, $failedRun->openai_call_count);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_FAILED, $document->refresh()->processing_status);
        $this->assertSame(1, RequirementExtractionCall::query()->where('requirement_extraction_run_id', $run->id)->count());

        $call = RequirementExtractionCall::query()->where('requirement_extraction_run_id', $run->id)->firstOrFail();

        $this->assertSame(RequirementExtractionCall::STATUS_FAILED, $call->status);
        $this->assertSame('timeout', $call->error_type);
        $this->assertSame(1, $requestCount);
    }

    public function test_it_is_idempotent_after_completion_and_does_not_duplicate_publication(): void
    {
        Queue::fake();

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-1003', 'Async idempotency target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 12:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'async-idempotency.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/async-idempotency.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager. Leverandøren skal levere dokumentasjon innen 10 dager.',
            'text_extracted_at' => '2026-04-06 12:01:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_TEXT_EXTRACTED,
        ]);
        $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.');
        $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.', 1);

        $this->fakeOpenAiFullDocumentResponse([
            $this->buildFullDocumentCandidate('Leverandøren skal levere dokumentasjon innen 10 dager.', [
                'requirement_identifier' => '1.1',
                'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
                'obligation_type' => 'must',
                'interpretation_risk' => 'low',
                'source_reference_text' => 'Bilag 1 punkt 2.7',
            ]),
        ]);

        $runService = app(RequirementExtractionRunService::class);
        $run = $runService->createQueuedRunForDocument($document);
        $runService->processRun($run);
        $runService->processRun($run->refresh());

        $publishedRequirements = SavedNoticeAiRequirement::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED)
            ->get();

        $this->assertCount(1, $publishedRequirements);
        $this->assertSame(RequirementExtractionRun::STATUS_COMPLETED, $run->refresh()->status);
        $this->assertSame(1, RequirementExtractionCall::query()->where('requirement_extraction_run_id', $run->id)->count());
        $this->assertSame(1, $run->refresh()->openai_call_count);
        $this->assertSame(0, SavedNoticeAiRequirement::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED)
            ->count());
        $this->assertSame(0, SavedNoticeAiRequirement::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_SUPERSEDED)
            ->count());
        $this->assertSame(1, app(RequirementLoader::class)->loadForCase($savedNotice->id)->count());
        Http::assertSentCount(1);
    }

    public function test_it_excludes_staged_and_superseded_rows_from_business_reads(): void
    {
        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-1004', 'Async loader target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-06 13:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'async-loader.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/async-loader.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Kravgrunnlag.',
            'text_extracted_at' => '2026-04-06 13:01:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Kravgrunnlag.');

        $publishedAiRequirement = $this->createAiRequirementRow($savedNotice, $document, $chunk, [
            'requirement_text' => 'Publisert AI-krav',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
        ]);

        $manualRequirement = app(RequirementEditorService::class)->createManualRequirement(
            $savedNotice,
            RequirementEditData::fromArray([
                'requirement_text' => 'Publisert manuelt krav',
                'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_MANDATORY,
                'reason' => 'Manual loader check',
            ]),
            $context['user'],
        );
        $manualRequirement = app(RequirementEditorService::class)->approveRequirement($manualRequirement, $context['user']);

        $stagedRequirement = $this->createAiRequirementRow($savedNotice, $document, $chunk, [
            'requirement_text' => 'Staged AI-krav',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
            'published_at' => null,
        ]);

        $supersededRequirement = $this->createAiRequirementRow($savedNotice, $document, $chunk, [
            'requirement_text' => 'Superseded AI-krav',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_SUPERSEDED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'published_at' => '2026-04-06 13:02:00',
            'superseded_at' => '2026-04-06 13:03:00',
        ]);

        $loader = app(RequirementLoader::class);
        $published = $loader->loadForCase($savedNotice->id);
        $approved = $loader->loadApprovedForCase($savedNotice->id);

        $this->assertCount(2, $published);
        $this->assertTrue($published->contains(fn (SavedNoticeAiRequirement $requirement): bool => $requirement->id === $publishedAiRequirement->id));
        $this->assertTrue($published->contains(fn (SavedNoticeAiRequirement $requirement): bool => $requirement->id === $manualRequirement->id));
        $this->assertFalse($published->contains(fn (SavedNoticeAiRequirement $requirement): bool => $requirement->id === $stagedRequirement->id));
        $this->assertFalse($published->contains(fn (SavedNoticeAiRequirement $requirement): bool => $requirement->id === $supersededRequirement->id));
        $this->assertCount(2, $approved);
        $this->assertTrue($approved->contains(fn (SavedNoticeAiRequirement $requirement): bool => $requirement->id === $publishedAiRequirement->id));
        $this->assertTrue($approved->contains(fn (SavedNoticeAiRequirement $requirement): bool => $requirement->id === $manualRequirement->id));
        $this->assertFalse($approved->contains(fn (SavedNoticeAiRequirement $requirement): bool => $requirement->id === $stagedRequirement->id));
        $this->assertFalse($approved->contains(fn (SavedNoticeAiRequirement $requirement): bool => $requirement->id === $supersededRequirement->id));
    }

    private function customerContext(string $customerName = 'Procynia AS'): array
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
            'extracted_text' => $overrides['extracted_text'] ?? '',
            'text_extracted_at' => $overrides['text_extracted_at'] ?? null,
            'queued_at' => $overrides['queued_at'] ?? null,
            'processing_started_at' => $overrides['processing_started_at'] ?? null,
            'processing_finished_at' => $overrides['processing_finished_at'] ?? null,
            'processing_error_type' => $overrides['processing_error_type'] ?? null,
            'processing_error_message' => $overrides['processing_error_message'] ?? null,
        ], $overrides));
    }

    private function createAiDocumentChunk(SavedNoticeAiDocument $document, string $content, int $chunkIndex = 0): SavedNoticeAiDocumentChunk
    {
        return $document->chunks()->create([
            'chunk_index' => $chunkIndex,
            'content' => $content,
            'char_start' => 0,
            'char_end' => mb_strlen($content, 'UTF-8'),
            'word_count' => count(preg_split('/\s+/u', trim($content)) ?: []),
        ]);
    }

    private function createAiRequirementRow(
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
            : (in_array($publicationStatus, [SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED, SavedNoticeAiRequirement::PUBLICATION_STATUS_SUPERSEDED], true) ? now() : null);

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
            'source_reference' => $overrides['source_reference'] ?? [
                'saved_notice_id' => $savedNotice->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_ai_document_chunk_id' => $chunk->id,
                'document_filename' => $document->original_filename,
                'source_block_id' => sprintf('saved-notice-ai-document-%d-chunk-%d', $document->id, $chunk->id),
                'source_block_index' => (int) $chunk->chunk_index,
                'source_chunk_ids' => [$chunk->id],
            ],
            'extraction_metadata' => $overrides['extraction_metadata'] ?? [
                'extraction_method' => $overrides['extraction_method'] ?? SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            ],
            'original_candidate_snapshot' => $overrides['original_candidate_snapshot'] ?? null,
            'current_requirement_snapshot' => $overrides['current_requirement_snapshot'] ?? null,
        ], $overrides));
    }

    private function fakeOpenAiFullDocumentResponse(array $candidates, int $status = 200, int $inputTokens = 120, int $outputTokens = 42): void
    {
        $json = json_encode([
            'candidates' => array_values($candidates),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($json)) {
            throw new \RuntimeException('Unable to build fake OpenAI response.');
        }

        Http::fake([
            '*' => Http::response([
                'id' => 'resp_requirement_extraction',
                'object' => 'response',
                'status' => 'completed',
                'output_text' => $json,
                'usage' => [
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'total_tokens' => $inputTokens + $outputTokens,
                ],
            ], $status, [
                'x-request-id' => 'req_requirement_extraction',
            ]),
        ]);
    }

    private function openAiStructuredResponse(array $fields, int $inputTokens = 40, int $outputTokens = 12): array
    {
        $json = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($json)) {
            throw new \RuntimeException('Unable to build a fake OpenAI response.');
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

    private function fakeOpenAiStructuredBlockResponse(callable $resolver, int $status = 200): void
    {
        Http::fake(function (Request $request) use ($resolver, $status) {
            $requestPayload = json_decode((string) $request->body(), true);

            if (! is_array($requestPayload)) {
                throw new \RuntimeException('Unable to decode the fake OpenAI request payload.');
            }

            $inputText = (string) data_get($requestPayload, 'input.1.content.0.text', '');
            $decodedInput = json_decode($inputText, true);

            $promptContext = [
                'prompt_name' => (string) data_get($requestPayload, 'text.format.name', ''),
                'prompt_text' => (string) data_get($requestPayload, 'input.0.content.0.text', ''),
                'input_text' => $inputText,
                'request_payload' => $requestPayload,
                'document' => is_array($decodedInput) ? data_get($decodedInput, 'document', []) : [],
                'blocks' => is_array($decodedInput) ? data_get($decodedInput, 'blocks', []) : [],
                'block' => is_array($decodedInput) ? data_get($decodedInput, 'blocks.0', []) : [],
                'model' => data_get($requestPayload, 'model'),
            ];

            $response = $resolver($promptContext, $request);

            if (! is_array($response) || ! array_key_exists('body', $response) || ! is_array($response['body'])) {
                throw new \RuntimeException('The fake OpenAI resolver must return an array with a body key.');
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

    private function buildFullDocumentCandidate(string $originalText, array $overrides = []): array
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
