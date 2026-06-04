<?php

namespace Tests\Unit;

use App\Data\Ai\Requirements\RequirementEditData;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\RequirementExtractionCall;
use App\Models\RequirementExtractionRun;
use App\Models\CustomerAiCaseUsage;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\SavedNoticeAiRequirement;
use App\Models\User;
use App\Jobs\Ai\Requirements\FinalizeRequirementExtractionRun;
use App\Jobs\Ai\Requirements\ProcessRequirementExtractionChunk;
use App\Jobs\Ai\Requirements\ProcessRequirementExtractionRun;
use App\Services\Ai\Requirements\FullDocumentRequirementExtractionPrompt;
use App\Models\AiTokenEvent;
use App\Services\Ai\AiUsageGuard;
use App\Services\Ai\Requirements\RequirementCandidateExtractor;
use App\Services\Ai\Requirements\RequirementEditorService;
use App\Services\Ai\Requirements\RequirementExtractionRunService;
use App\Services\Ai\Requirements\RequirementLoader;
use Closure;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\TimeoutExceededException;
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
        Carbon::setTestNow();

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
        $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.', 1);

        $manualRequirement = app(RequirementEditorService::class)->createManualRequirement(
            $savedNotice,
            RequirementEditData::fromArray([
                'requirement_text' => 'Leverandøren skal beskrive løsning og bemanning.',
                'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
                'reason' => 'Manual note',
            ]),
            $context['user'],
        );

        $existingAiRequirement = $this->createAiRequirementRow($savedNotice, $document, null, [
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

        $this->assertCount(1, $chunks);
        $this->assertStringStartsWith('2. Veiledning om leverandørens besvarelse', trim((string) $chunks[0]->content));
        $this->assertStringContainsString('Leverandøren skal skrive tydelig og kort.', $chunks[0]->content);
        $this->assertStringContainsString('3. Kravområde 2', $chunks[0]->content);
        $this->assertStringContainsString('1-1.S.2 Leverandøren skal beskrive løsning og bemanning.', $chunks[0]->content);
        $this->assertStringNotContainsString('Innholdsfortegnelse', $chunks[0]->content);
        $this->assertGreaterThan(0, $chunks[0]->char_start);
        Http::assertSentCount(1);
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

        $existingAiRequirement = $this->createAiRequirementRow($savedNotice, $document, null, [
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
        Queue::fake();
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
        $this->assertSame('document_split', $failedRun->failure_stage);
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

    public function test_it_reuses_a_recent_processing_run_without_dispatching_a_new_job(): void
    {
        Queue::fake();

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-1003A', 'Recent processing target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, now()->subMinutes(5)->toDateTimeString());

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'recent-processing.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/recent-processing.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'text_extracted_at' => now()->subMinutes(5)->toDateTimeString(),
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
            'queued_at' => now()->subMinutes(5)->toDateTimeString(),
            'processing_started_at' => now()->subMinutes(5)->toDateTimeString(),
            'processing_finished_at' => null,
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.');
        $existingRequirement = $this->createAiRequirementRow($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => now()->subMinutes(4)->toDateTimeString(),
        ]);

        $existingRun = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'queued_at' => now()->subMinutes(5)->toDateTimeString(),
            'started_at' => now()->subMinutes(5)->toDateTimeString(),
            'last_heartbeat_at' => now()->subMinutes(5)->toDateTimeString(),
        ]);
        $this->touchRequirementExtractionRun($existingRun, now()->subMinutes(5)->toDateTimeString());
        $this->touchAiDocumentProcessing($document, now()->subMinutes(5)->toDateTimeString());

        $runService = app(RequirementExtractionRunService::class);
        $returnedRun = $runService->createQueuedRunForDocument($document->refresh());

        $this->assertSame($existingRun->id, $returnedRun->id);
        $this->assertSame(1, RequirementExtractionRun::query()->where('saved_notice_ai_document_id', $document->id)->count());
        $this->assertSame(RequirementExtractionRun::STATUS_PROCESSING, $existingRun->refresh()->status);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_QUEUED, $document->refresh()->processing_status);
        $this->assertSame(SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED, $existingRequirement->refresh()->publication_status);
        Queue::assertNothingPushed();
    }

    public function test_it_marks_a_stale_processing_run_failed_and_dispatches_a_replacement_job(): void
    {
        Queue::fake();

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-1003B', 'Stale processing target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, now()->subMinutes(25)->toDateTimeString());

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'stale-processing.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/stale-processing.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'text_extracted_at' => now()->subMinutes(25)->toDateTimeString(),
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
            'queued_at' => now()->subMinutes(25)->toDateTimeString(),
            'processing_started_at' => now()->subMinutes(25)->toDateTimeString(),
            'processing_finished_at' => null,
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.');
        $existingRequirement = $this->createAiRequirementRow($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => now()->subMinutes(24)->toDateTimeString(),
        ]);

        $staleRun = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'queued_at' => now()->subMinutes(25)->toDateTimeString(),
            'started_at' => now()->subMinutes(25)->toDateTimeString(),
            'last_heartbeat_at' => now()->subMinutes(25)->toDateTimeString(),
        ]);
        $this->touchRequirementExtractionRun($staleRun, now()->subMinutes(25)->toDateTimeString());
        $this->touchAiDocumentProcessing($document, now()->subMinutes(25)->toDateTimeString());

        $runService = app(RequirementExtractionRunService::class);
        $replacementRun = $runService->createQueuedRunForDocument($document->refresh());

        $staleRun->refresh();
        $replacementRun->refresh();

        $this->assertSame(RequirementExtractionRun::STATUS_FAILED, $staleRun->status);
        $this->assertSame('stale_active_run', $staleRun->failure_stage);
        $this->assertSame('stale_run', $staleRun->error_type);
        $this->assertSame('Requirement extraction run was marked as failed because it was stuck in processing without completing.', $staleRun->error_message);
        $this->assertNotNull($staleRun->finished_at);
        $this->assertSame(2, RequirementExtractionRun::query()->where('saved_notice_ai_document_id', $document->id)->count());
        $this->assertSame(RequirementExtractionRun::STATUS_QUEUED, $replacementRun->status);
        $this->assertNotSame($staleRun->id, $replacementRun->id);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_QUEUED, $document->refresh()->processing_status);
        $this->assertSame(SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED, $existingRequirement->refresh()->publication_status);

        Queue::assertPushed(ProcessRequirementExtractionRun::class, function (ProcessRequirementExtractionRun $job) use ($replacementRun): bool {
            return $job->runId === $replacementRun->id
                && $job->queue === 'ai-requirements';
        });

        Queue::assertPushed(ProcessRequirementExtractionRun::class, function (ProcessRequirementExtractionRun $job) use ($replacementRun): bool {
            return $job->runId === $replacementRun->id
                && $job->queue === 'ai-requirements'
                && $job->tries === 1
                && $job->timeout === 1800
                && $job->failOnTimeout === true;
        });
    }

    public function test_process_requirement_extraction_run_uses_the_expected_timeout_contract(): void
    {
        $job = new ProcessRequirementExtractionRun(123);

        $this->assertSame('ai-requirements', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertSame(1800, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
    }

    public function test_process_requirement_extraction_chunk_uses_the_expected_queue_contract(): void
    {
        $jobFromCallOnly = new ProcessRequirementExtractionChunk(456);
        $jobFromRunAndCall = new ProcessRequirementExtractionChunk(456, 123);

        $this->assertSame('ai-requirements', $jobFromCallOnly->queue);
        $this->assertSame(456, $jobFromCallOnly->callId);
        $this->assertNull($jobFromCallOnly->runId);
        $this->assertSame(600, $jobFromCallOnly->timeout);
        $this->assertTrue($jobFromCallOnly->failOnTimeout);
        $this->assertSame('ai-requirements', $jobFromRunAndCall->queue);
        $this->assertSame(456, $jobFromRunAndCall->callId);
        $this->assertSame(123, $jobFromRunAndCall->runId);
        $this->assertSame(600, $jobFromRunAndCall->timeout);
        $this->assertTrue($jobFromRunAndCall->failOnTimeout);
    }

    public function test_finalize_requirement_extraction_run_uses_the_expected_queue_contract(): void
    {
        $job = new FinalizeRequirementExtractionRun(123);

        $this->assertSame('ai-requirements', $job->queue);
        $this->assertSame(123, $job->runId);
        $this->assertSame(1, $job->tries);
    }

    public function test_finalize_requirement_extraction_run_does_not_complete_while_calls_are_queued_or_running(): void
    {
        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-2001', 'Finalize active calls target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-07 09:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'finalize-active-calls.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/finalize-active-calls.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager. Leverandøren skal beskrive løsning.',
            'text_extracted_at' => '2026-04-07 09:01:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
            'queued_at' => '2026-04-07 09:02:00',
            'processing_started_at' => '2026-04-07 09:02:00',
            'processing_finished_at' => null,
        ]);
        $chunkOne = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.', 0);
        $chunkTwo = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsning.', 1);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'queued_at' => '2026-04-07 09:02:00',
            'started_at' => '2026-04-07 09:02:00',
            'last_heartbeat_at' => '2026-04-07 09:03:00',
        ]);

        $this->createRequirementExtractionCall($run, $document, $chunkOne, [
            'status' => RequirementExtractionCall::STATUS_QUEUED,
        ]);
        $this->createRequirementExtractionCall($run, $document, $chunkTwo, [
            'status' => RequirementExtractionCall::STATUS_RUNNING,
            'started_at' => '2026-04-07 09:03:30',
        ]);

        $job = new FinalizeRequirementExtractionRun($run->id);
        $job->handle(app(RequirementExtractionRunService::class));

        $this->assertSame(RequirementExtractionRun::STATUS_PROCESSING, $run->refresh()->status);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING, $document->refresh()->processing_status);
    }

    public function test_finalize_requirement_extraction_run_marks_run_and_document_failed_when_a_call_failed(): void
    {
        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-2002', 'Finalize failed call target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-07 10:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'finalize-failed-call.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/finalize-failed-call.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager. Leverandøren skal beskrive løsning.',
            'text_extracted_at' => '2026-04-07 10:01:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
            'queued_at' => '2026-04-07 10:02:00',
            'processing_started_at' => '2026-04-07 10:02:00',
            'processing_finished_at' => null,
        ]);
        $chunkOne = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.', 0);
        $chunkTwo = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsning.', 1);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'queued_at' => '2026-04-07 10:02:00',
            'started_at' => '2026-04-07 10:02:00',
            'last_heartbeat_at' => '2026-04-07 10:03:00',
        ]);

        $this->createRequirementExtractionCall($run, $document, $chunkOne, [
            'status' => RequirementExtractionCall::STATUS_COMPLETED,
            'started_at' => '2026-04-07 10:03:15',
            'finished_at' => '2026-04-07 10:03:45',
        ]);
        $this->createRequirementExtractionCall($run, $document, $chunkTwo, [
            'status' => RequirementExtractionCall::STATUS_FAILED,
            'error_type' => 'chunk_extraction_failed',
            'error_message' => 'Requirement extraction chunk failed.',
            'started_at' => '2026-04-07 10:04:15',
            'finished_at' => '2026-04-07 10:04:45',
        ]);

        $job = new FinalizeRequirementExtractionRun($run->id);
        $job->handle(app(RequirementExtractionRunService::class));

        $run->refresh();
        $document->refresh();

        $this->assertSame(RequirementExtractionRun::STATUS_FAILED, $run->status);
        $this->assertSame('chunk_extraction', $run->failure_stage);
        $this->assertSame('chunk_extraction_failed', $run->error_type);
        $this->assertSame('Requirement extraction chunk failed.', $run->error_message);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_FAILED, $document->processing_status);
        $this->assertSame('chunk_extraction_failed', $document->processing_error_type);
        $this->assertSame('Requirement extraction chunk failed.', $document->processing_error_message);
    }

    public function test_finalize_requirement_extraction_run_completes_when_all_calls_are_completed(): void
    {
        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-2003', 'Finalize completed target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-07 11:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'finalize-completed.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/finalize-completed.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager. Leverandøren skal beskrive løsning.',
            'text_extracted_at' => '2026-04-07 11:01:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
            'queued_at' => '2026-04-07 11:02:00',
            'processing_started_at' => '2026-04-07 11:02:00',
            'processing_finished_at' => null,
        ]);
        $chunkOne = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.', 0);
        $chunkTwo = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsning.', 1);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'queued_at' => '2026-04-07 11:02:00',
            'started_at' => '2026-04-07 11:02:00',
            'last_heartbeat_at' => '2026-04-07 11:03:00',
        ]);

        $this->createRequirementExtractionCall($run, $document, $chunkOne, [
            'status' => RequirementExtractionCall::STATUS_COMPLETED,
            'started_at' => '2026-04-07 11:03:15',
            'finished_at' => '2026-04-07 11:03:45',
        ]);
        $this->createRequirementExtractionCall($run, $document, $chunkTwo, [
            'status' => RequirementExtractionCall::STATUS_COMPLETED,
            'started_at' => '2026-04-07 11:04:15',
            'finished_at' => '2026-04-07 11:04:45',
        ]);

        $job = new FinalizeRequirementExtractionRun($run->id);
        $job->handle(app(RequirementExtractionRunService::class));

        $run->refresh();
        $document->refresh();

        $this->assertSame(RequirementExtractionRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED, $document->processing_status);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($document->processing_finished_at);

        $publishedRequirementsBeforeReplay = SavedNoticeAiRequirement::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED)
            ->count();

        $job->handle(app(RequirementExtractionRunService::class));

        $this->assertSame($publishedRequirementsBeforeReplay, SavedNoticeAiRequirement::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED)
            ->count());
        $this->assertSame(RequirementExtractionRun::STATUS_COMPLETED, $run->refresh()->status);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED, $document->refresh()->processing_status);
    }

    public function test_finalize_requirement_extraction_run_marks_merging_before_publishing_and_completes_the_run(): void
    {
        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-2003A', 'Finalize merging target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-07 11:30:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'finalize-merging.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/finalize-merging.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'text_extracted_at' => '2026-04-07 11:31:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
            'queued_at' => '2026-04-07 11:32:00',
            'processing_started_at' => '2026-04-07 11:32:00',
            'processing_finished_at' => null,
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.', 0);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'queued_at' => '2026-04-07 11:32:00',
            'started_at' => '2026-04-07 11:32:00',
            'last_heartbeat_at' => '2026-04-07 11:33:00',
        ]);

        $this->createRequirementExtractionCall($run, $document, $chunk, [
            'status' => RequirementExtractionCall::STATUS_COMPLETED,
            'started_at' => '2026-04-07 11:33:15',
            'finished_at' => '2026-04-07 11:33:45',
        ]);
        $this->createAiRequirementRow($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED,
            'extraction_run_id' => $run->id,
            'published_at' => null,
        ]);

        $realService = new RequirementExtractionRunService(
            app(RequirementCandidateExtractor::class),
            app(RequirementEditorService::class),
        );

        $spyService = new class(
            app(RequirementCandidateExtractor::class),
            app(RequirementEditorService::class),
            $realService,
            $this,
        ) extends RequirementExtractionRunService {
            /**
             * @var Closure|null
             */
            public ?Closure $promoteAssertion = null;

            public function __construct(
                RequirementCandidateExtractor $candidateExtractor,
                RequirementEditorService $requirementEditorService,
                private readonly RequirementExtractionRunService $realService,
                private readonly RequirementExtractionRunServiceTest $testCase,
            ) {
                parent::__construct($candidateExtractor, $requirementEditorService);
            }

            public function promoteRun(RequirementExtractionRun $run, SavedNoticeAiDocument $document): int
            {
                if ($this->promoteAssertion instanceof Closure) {
                    ($this->promoteAssertion)($run, $document);
                }

                return $this->realService->promoteRun($run, $document);
            }
        };

        $spyService->promoteAssertion = function (RequirementExtractionRun $run, SavedNoticeAiDocument $document) use ($realService): void {
            $freshRun = RequirementExtractionRun::query()->findOrFail($run->id);
            $freshDocument = SavedNoticeAiDocument::query()->findOrFail($document->id);

            $this->assertSame(RequirementExtractionRun::STATUS_MERGING, $freshRun->status);
            $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_MERGING, $freshDocument->processing_status);
            $this->assertNull($freshRun->finished_at);
            $this->assertNull($freshDocument->processing_finished_at);
        };

        $job = new FinalizeRequirementExtractionRun($run->id);
        $job->handle($spyService);

        $run->refresh();
        $document->refresh();

        $publishedRequirements = SavedNoticeAiRequirement::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED)
            ->get();

        $this->assertSame(RequirementExtractionRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED, $document->processing_status);
        $this->assertCount(1, $publishedRequirements);
        $this->assertSame($chunk->id, $publishedRequirements[0]->saved_notice_ai_document_chunk_id);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($document->processing_finished_at);
    }

    public function test_process_requirement_extraction_chunk_processes_one_call_and_stages_requirements_with_the_chunk_id(): void
    {
        Queue::fake();
        $this->fakeOpenAiFullDocumentResponse([
            $this->buildFullDocumentCandidate('Leverandøren skal levere dokumentasjon innen 10 dager.', [
                'requirement_identifier' => '1.1',
                'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
                'obligation_type' => 'must',
                'interpretation_risk' => 'low',
                'source_reference_text' => 'Bilag 1 punkt 2.7',
            ]),
        ]);

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-2004A', 'Chunk processing target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-07 12:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'chunk-processing.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/chunk-processing.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'text_extracted_at' => '2026-04-07 12:01:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
            'queued_at' => '2026-04-07 12:02:00',
            'processing_started_at' => '2026-04-07 12:02:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.', 0);
        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'queued_at' => '2026-04-07 12:02:00',
            'started_at' => '2026-04-07 12:02:00',
            'last_heartbeat_at' => '2026-04-07 12:02:30',
        ]);
        $call = $this->createRequirementExtractionCall($run, $document, $chunk, [
            'status' => RequirementExtractionCall::STATUS_QUEUED,
        ]);

        $service = app(RequirementExtractionRunService::class);
        $service->processRunCall($call->id);

        $call->refresh();
        $run->refresh();
        $document->refresh();

        $stagedRequirements = SavedNoticeAiRequirement::query()
            ->where('extraction_run_id', $run->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED)
            ->get();

        $this->assertSame(RequirementExtractionCall::STATUS_COMPLETED, $call->status);
        $this->assertNotNull($call->finished_at);
        $this->assertSame(1, $run->candidate_count);
        $this->assertSame(1, $run->persisted_requirement_count);
        $this->assertSame(1, $run->openai_call_count);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING, $document->processing_status);
        $this->assertCount(1, $stagedRequirements);
        $this->assertSame($chunk->id, $stagedRequirements[0]->saved_notice_ai_document_chunk_id);
        $this->assertSame($run->id, $stagedRequirements[0]->extraction_run_id);
        Queue::assertPushed(FinalizeRequirementExtractionRun::class, 1);
        Http::assertSentCount(1);
    }

    public function test_process_requirement_extraction_chunk_is_idempotent_when_the_call_is_already_completed(): void
    {
        Queue::fake();
        Http::fake();

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-2004B', 'Chunk replay target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-07 12:30:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'chunk-replay.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/chunk-replay.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => 'Leverandøren skal beskrive løsning og bemanning.',
            'text_extracted_at' => '2026-04-07 12:31:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
            'queued_at' => '2026-04-07 12:32:00',
            'processing_started_at' => '2026-04-07 12:32:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsning og bemanning.', 0);
        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'queued_at' => '2026-04-07 12:32:00',
            'started_at' => '2026-04-07 12:32:00',
            'last_heartbeat_at' => '2026-04-07 12:32:30',
        ]);
        $call = $this->createRequirementExtractionCall($run, $document, $chunk, [
            'status' => RequirementExtractionCall::STATUS_COMPLETED,
            'request_id' => 'req-chunk-replay',
            'response_id' => 'resp-chunk-replay',
            'status_code' => 200,
            'input_tokens' => 50,
            'output_tokens' => 20,
            'total_tokens' => 70,
            'elapsed_ms' => 150,
            'started_at' => '2026-04-07 12:32:15',
            'finished_at' => '2026-04-07 12:32:45',
        ]);
        $this->createAiRequirementRow($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal beskrive løsning og bemanning.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED,
            'extraction_run_id' => $run->id,
            'published_at' => null,
        ]);

        $service = app(RequirementExtractionRunService::class);
        $service->processRunCall($call->id);

        $this->assertSame(1, SavedNoticeAiRequirement::query()
            ->where('extraction_run_id', $run->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED)
            ->count());
        $this->assertSame(RequirementExtractionCall::STATUS_COMPLETED, $call->refresh()->status);
        Http::assertNothingSent();
        Queue::assertPushed(FinalizeRequirementExtractionRun::class, 1);
    }

    public function test_process_requirement_extraction_chunk_failed_call_triggers_finalize_failure_flow(): void
    {
        Queue::fake();
        $this->fakeOpenAiFullDocumentResponse([], 503, 120, 42);

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-2004C', 'Chunk failure target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-07 13:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'chunk-failure.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/chunk-failure.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'text_extracted_at' => '2026-04-07 13:01:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
            'queued_at' => '2026-04-07 13:02:00',
            'processing_started_at' => '2026-04-07 13:02:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.', 0);
        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'queued_at' => '2026-04-07 13:02:00',
            'started_at' => '2026-04-07 13:02:00',
            'last_heartbeat_at' => '2026-04-07 13:02:30',
        ]);
        $call = $this->createRequirementExtractionCall($run, $document, $chunk, [
            'status' => RequirementExtractionCall::STATUS_QUEUED,
        ]);

        $service = app(RequirementExtractionRunService::class);
        $service->processRunCall($call->id);

        $call->refresh();
        $run->refresh();
        $document->refresh();

        $this->assertSame(RequirementExtractionCall::STATUS_FAILED, $call->status);
        $this->assertSame(503, $call->status_code);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING, $document->processing_status);
        $this->assertSame(RequirementExtractionRun::STATUS_PROCESSING, $run->status);
        Queue::assertPushed(FinalizeRequirementExtractionRun::class, 1);

        $finalize = new FinalizeRequirementExtractionRun($run->id);
        $finalize->handle($service);

        $this->assertSame(RequirementExtractionRun::STATUS_FAILED, $run->refresh()->status);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_FAILED, $document->refresh()->processing_status);
        $this->assertSame('chunk_extraction', $run->refresh()->failure_stage);
        $this->assertSame('upstream_error', $run->refresh()->error_type);
    }

    public function test_orchestrator_chunk_and_finalize_happy_path_completes_run(): void
    {
        Queue::fake();
        Http::fake(function (Request $request) {
            $payload = json_decode((string) $request->body(), true);
            $inputText = (string) data_get($payload, 'input.1.content.0.text', '');

            if (str_contains($inputText, 'dokumentasjon')) {
                return Http::response([
                    'id' => 'resp_chunk_a',
                    'object' => 'response',
                    'status' => 'completed',
                    'output_text' => json_encode([
                        'candidates' => [
                            $this->buildFullDocumentCandidate('Leverandøren skal levere dokumentasjon innen 10 dager.', [
                                'requirement_identifier' => '1.1',
                                'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
                                'obligation_type' => 'must',
                                'interpretation_risk' => 'low',
                                'source_reference_text' => 'Bilag 1 punkt 2.7',
                            ]),
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'usage' => [
                        'input_tokens' => 120,
                        'output_tokens' => 42,
                        'total_tokens' => 162,
                    ],
                ], 200, ['x-request-id' => 'req_chunk_a']);
            }

            return Http::response([
                'id' => 'resp_chunk_b',
                'object' => 'response',
                'status' => 'completed',
                'output_text' => json_encode([
                    'candidates' => [
                        $this->buildFullDocumentCandidate('Leverandøren skal beskrive løsning og bemanning.', [
                            'requirement_identifier' => '1.2',
                            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
                            'obligation_type' => 'must',
                            'interpretation_risk' => 'low',
                            'source_reference_text' => 'Bilag 1 punkt 2.8',
                        ]),
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'usage' => [
                    'input_tokens' => 120,
                    'output_tokens' => 42,
                    'total_tokens' => 162,
                ],
            ], 200, ['x-request-id' => 'req_chunk_b']);
        });

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-2004D', 'Happy path target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-07 14:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'happy-path.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/happy-path.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => "1. Leveringskrav\nLeverandøren skal levere dokumentasjon innen 10 dager.\n\n2. Løsning og bemanning\nLeverandøren skal beskrive løsning og bemanning.",
            'text_extracted_at' => '2026-04-07 14:01:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_TEXT_EXTRACTED,
        ]);
        $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.', 0);
        $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsning og bemanning.', 1);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_QUEUED,
            'queued_at' => '2026-04-07 14:02:00',
            'started_at' => null,
            'last_heartbeat_at' => '2026-04-07 14:02:00',
        ]);

        $service = app(RequirementExtractionRunService::class);
        $service->orchestrateRunChunks($run);

        $calls = RequirementExtractionCall::query()
            ->where('requirement_extraction_run_id', $run->id)
            ->orderBy('saved_notice_ai_document_chunk_id')
            ->get();

        $this->assertCount(2, $calls);
        $this->assertSame(RequirementExtractionCall::STATUS_QUEUED, $calls[0]->status);
        $this->assertSame(RequirementExtractionCall::STATUS_QUEUED, $calls[1]->status);
        Queue::assertPushed(ProcessRequirementExtractionChunk::class, 2);

        $service->processRunCall($calls[0]->id);
        $this->assertSame(RequirementExtractionCall::STATUS_COMPLETED, $calls[0]->refresh()->status);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING, $document->refresh()->processing_status);

        $service->processRunCall($calls[1]->id);
        $this->assertSame(RequirementExtractionCall::STATUS_COMPLETED, $calls[1]->refresh()->status);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING, $document->refresh()->processing_status);

        Queue::assertPushed(FinalizeRequirementExtractionRun::class, 2);

        $finalize = new FinalizeRequirementExtractionRun($run->id);
        $finalize->handle($service);

        $run->refresh();
        $document->refresh();

        $publishedRequirements = SavedNoticeAiRequirement::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED)
            ->orderBy('id')
            ->get();

        $this->assertSame(RequirementExtractionRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED, $document->processing_status);
        $this->assertCount(2, $publishedRequirements);
        $this->assertSame($calls[0]->saved_notice_ai_document_chunk_id, $publishedRequirements[0]->saved_notice_ai_document_chunk_id);
        $this->assertSame($calls[1]->saved_notice_ai_document_chunk_id, $publishedRequirements[1]->saved_notice_ai_document_chunk_id);
        $this->assertSame(0, SavedNoticeAiRequirement::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED)
            ->count());
    }

    public function test_process_requirement_extraction_run_orchestrates_chunks_without_openai_and_dispatches_chunk_jobs(): void
    {
        Queue::fake();
        Http::fake();

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-2004', 'Orchestration target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-07 12:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'orchestration-target.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/orchestration-target.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => "1. Leveringskrav\nLeverandøren skal levere dokumentasjon innen 10 dager.\n\n2. Tekniske krav\nLeverandøren skal beskrive løsning.",
            'text_extracted_at' => '2026-04-07 12:01:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_TEXT_EXTRACTED,
        ]);
        $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.', 0);
        $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsning.', 1);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_QUEUED,
            'queued_at' => '2026-04-07 12:02:00',
            'started_at' => null,
            'last_heartbeat_at' => '2026-04-07 12:02:00',
        ]);

        $job = new ProcessRequirementExtractionRun($run->id);
        $job->handle(app(RequirementExtractionRunService::class));

        $run->refresh();
        $document->refresh();

        $calls = RequirementExtractionCall::query()
            ->where('requirement_extraction_run_id', $run->id)
            ->orderBy('saved_notice_ai_document_chunk_id')
            ->get();

        $this->assertSame(RequirementExtractionRun::STATUS_PROCESSING, $run->status);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING, $document->processing_status);
        $this->assertNotSame(RequirementExtractionRun::STATUS_COMPLETED, $run->status);
        $this->assertCount(2, $calls);
        $this->assertSame(RequirementExtractionCall::STATUS_QUEUED, $calls[0]->status);
        $this->assertSame(RequirementExtractionCall::STATUS_QUEUED, $calls[1]->status);
        Queue::assertPushed(ProcessRequirementExtractionChunk::class, 2);
        Http::assertNothingSent();
    }

    public function test_orchestrating_the_same_run_twice_does_not_duplicate_call_rows(): void
    {
        Queue::fake();
        Http::fake();

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-2005', 'Replay orchestration target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-04-07 13:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'replay-orchestration.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/replay-orchestration.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => "1. Leveringskrav\nLeverandøren skal levere dokumentasjon innen 10 dager.\n\n2. Tekniske krav\nLeverandøren skal beskrive løsning.",
            'text_extracted_at' => '2026-04-07 13:01:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_TEXT_EXTRACTED,
        ]);
        $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.', 0);
        $this->createAiDocumentChunk($document, 'Leverandøren skal beskrive løsning.', 1);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_QUEUED,
            'queued_at' => '2026-04-07 13:02:00',
            'started_at' => null,
            'last_heartbeat_at' => '2026-04-07 13:02:00',
        ]);

        $job = new ProcessRequirementExtractionRun($run->id);
        $service = app(RequirementExtractionRunService::class);
        $job->handle($service);
        $job->handle($service);

        $this->assertSame(2, RequirementExtractionCall::query()->where('requirement_extraction_run_id', $run->id)->count());
        $this->assertSame(2, SavedNoticeAiDocumentChunk::query()->where('saved_notice_ai_document_id', $document->id)->count());
        $this->assertSame(RequirementExtractionRun::STATUS_PROCESSING, $run->refresh()->status);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING, $document->refresh()->processing_status);
        Http::assertNothingSent();
    }

    public function test_orchestrate_run_chunks_always_rebuilds_chunks_and_replaces_stale_chunk(): void
    {
        Queue::fake();
        Http::fake();

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-STALE-1', 'Stale chunk rebuild target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-05-28 12:00:00');

        $twoSectionText = "1. Leveringskrav\nLeverandøren skal levere dokumentasjon innen 10 dager.\n\n2. Tekniske krav\nLeverandøren skal beskrive løsning.";

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'stale-chunk-rebuild.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/stale-chunk-rebuild.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => $twoSectionText,
            'text_extracted_at' => '2026-05-28 12:01:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_TEXT_EXTRACTED,
        ]);

        // Stale chunk created at upload time — covers entire document as one blob, with no calls referencing it.
        $staleChunk = $this->createAiDocumentChunk($document, $twoSectionText, 0);
        $staleChunkId = $staleChunk->id;

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_QUEUED,
            'queued_at' => '2026-05-28 12:02:00',
            'started_at' => null,
            'last_heartbeat_at' => '2026-05-28 12:02:00',
        ]);

        $service = app(RequirementExtractionRunService::class);
        $service->orchestrateRunChunks($run);

        $freshChunks = SavedNoticeAiDocumentChunk::query()
            ->where('saved_notice_ai_document_id', $document->id)
            ->orderBy('chunk_index')
            ->get();

        $calls = RequirementExtractionCall::query()
            ->where('requirement_extraction_run_id', $run->id)
            ->orderBy('id')
            ->get();

        // Stale chunk must be gone.
        $this->assertNull(SavedNoticeAiDocumentChunk::query()->find($staleChunkId));

        // DocumentSplitPlanner produces 2 sections from the 2-H1 text → 2 fresh chunks.
        $this->assertCount(2, $freshChunks);

        // One call per fresh chunk.
        $this->assertCount(2, $calls);

        // No call references the stale chunk.
        $callChunkIds = $calls->pluck('saved_notice_ai_document_chunk_id')->all();
        $this->assertNotContains($staleChunkId, $callChunkIds);

        // Each call references one of the fresh chunks.
        $freshChunkIds = $freshChunks->pluck('id')->all();
        $this->assertContains($calls[0]->saved_notice_ai_document_chunk_id, $freshChunkIds);
        $this->assertContains($calls[1]->saved_notice_ai_document_chunk_id, $freshChunkIds);

        $this->assertSame(RequirementExtractionCall::STATUS_QUEUED, $calls[0]->status);
        $this->assertSame(RequirementExtractionCall::STATUS_QUEUED, $calls[1]->status);

        Queue::assertPushed(ProcessRequirementExtractionChunk::class, 2);
        Http::assertNothingSent();
    }

    public function test_document_status_is_set_to_processing_when_orchestration_begins(): void
    {
        Queue::fake();
        Http::fake();

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-DOCSTATUS-1', 'Status processing target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-05-28 13:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'status-processing.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/status-processing.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => "1. Krav\nLeverandøren skal levere dokumentasjon.",
            'text_extracted_at' => '2026-05-28 13:01:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_TEXT_EXTRACTED,
        ]);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_QUEUED,
            'queued_at' => '2026-05-28 13:02:00',
            'started_at' => null,
            'last_heartbeat_at' => '2026-05-28 13:02:00',
        ]);

        app(RequirementExtractionRunService::class)->orchestrateRunChunks($run);

        $this->assertSame(
            SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
            $document->refresh()->processing_status,
        );
    }

    public function test_document_status_is_set_to_failed_when_chunk_call_fails_via_finalize(): void
    {
        Queue::fake();

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-DOCSTATUS-2', 'Status failed target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-05-28 13:10:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'status-failed.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/status-failed.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal levere.',
            'text_extracted_at' => '2026-05-28 13:11:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
            'processing_started_at' => '2026-05-28 13:12:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere.', 0);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'queued_at' => '2026-05-28 13:12:00',
            'started_at' => '2026-05-28 13:12:00',
            'last_heartbeat_at' => '2026-05-28 13:12:30',
        ]);
        $this->createRequirementExtractionCall($run, $document, $chunk, [
            'status' => RequirementExtractionCall::STATUS_FAILED,
            'error_type' => 'truncated_response',
            'error_message' => 'AI response appears to have been truncated.',
        ]);

        $finalize = new FinalizeRequirementExtractionRun($run->id);
        $finalize->handle(app(RequirementExtractionRunService::class));

        $document->refresh();

        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_FAILED, $document->processing_status);
        $this->assertSame('truncated_response', $document->processing_error_type);
        $this->assertSame('AI response appears to have been truncated.', $document->processing_error_message);
    }

    public function test_document_status_is_set_to_completed_and_error_fields_cleared_when_finalize_succeeds(): void
    {
        Queue::fake();

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-DOCSTATUS-3', 'Status completed target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-05-28 13:20:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'status-completed.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/status-completed.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal levere.',
            'text_extracted_at' => '2026-05-28 13:21:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
            'processing_started_at' => '2026-05-28 13:22:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere.', 0);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'queued_at' => '2026-05-28 13:22:00',
            'started_at' => '2026-05-28 13:22:00',
            'last_heartbeat_at' => '2026-05-28 13:22:30',
        ]);
        $this->createRequirementExtractionCall($run, $document, $chunk, [
            'status' => RequirementExtractionCall::STATUS_COMPLETED,
        ]);

        $finalize = new FinalizeRequirementExtractionRun($run->id);
        $finalize->handle(app(RequirementExtractionRunService::class));

        $document->refresh();

        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED, $document->processing_status);
        $this->assertNull($document->processing_error_type);
        $this->assertNull($document->processing_error_message);
        $this->assertNotNull($document->processing_finished_at);
    }

    public function test_document_status_becomes_completed_when_new_run_succeeds_after_prior_failed_run(): void
    {
        Queue::fake();

        $twoSectionText = "1. Leveringskrav\nLeverandøren skal levere dokumentasjon innen 10 dager.\n\n2. Tekniske krav\nLeverandøren skal beskrive løsning.";

        $this->fakeOpenAiFullDocumentResponse([
            $this->buildFullDocumentCandidate('Leverandøren skal levere dokumentasjon innen 10 dager.', [
                'requirement_identifier' => '1.1',
                'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
                'obligation_type' => 'must',
                'interpretation_risk' => 'low',
            ]),
        ]);

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-DOCSTATUS-4', 'Failed-to-completed target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-05-28 14:00:00');

        // Document is stuck in failed state from a previous run.
        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'failed-to-completed.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/failed-to-completed.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => $twoSectionText,
            'text_extracted_at' => '2026-05-28 14:01:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_FAILED,
            'processing_error_type' => 'truncated_response',
            'processing_error_message' => 'AI response appears to have been truncated at the configured output token limit before valid JSON could be parsed.',
            'processing_finished_at' => '2026-05-28 14:02:00',
        ]);

        // Historical failed run — must survive unchanged.
        $oldRun = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_FAILED,
            'failure_stage' => 'chunk_extraction',
            'error_type' => 'truncated_response',
            'error_message' => 'AI response appears to have been truncated.',
            'queued_at' => '2026-05-28 14:00:30',
            'started_at' => '2026-05-28 14:00:35',
            'finished_at' => '2026-05-28 14:02:00',
            'last_heartbeat_at' => '2026-05-28 14:02:00',
        ]);

        // New run triggered after the failure.
        $newRun = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_QUEUED,
            'queued_at' => '2026-05-28 14:03:00',
            'started_at' => null,
            'last_heartbeat_at' => '2026-05-28 14:03:00',
        ]);

        $service = app(RequirementExtractionRunService::class);
        $service->orchestrateRunChunks($newRun);

        $calls = RequirementExtractionCall::query()
            ->where('requirement_extraction_run_id', $newRun->id)
            ->orderBy('id')
            ->get();

        // Document cleared from failed to processing when new run started.
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING, $document->refresh()->processing_status);
        $this->assertNull($document->processing_error_type);
        $this->assertNull($document->processing_error_message);

        // 2 H1 sections → 2 chunks → 2 calls.
        $this->assertCount(2, $calls);

        // Process both chunk calls.
        $service->processRunCall($calls[0]->id);
        $service->processRunCall($calls[1]->id);

        // Finalize the new run.
        (new FinalizeRequirementExtractionRun($newRun->id))->handle($service);

        $newRun->refresh();
        $document->refresh();
        $oldRun->refresh();

        // New run completed.
        $this->assertSame(RequirementExtractionRun::STATUS_COMPLETED, $newRun->status);

        // Document is completed — the main regression assertion.
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED, $document->processing_status);
        $this->assertNull($document->processing_error_type);
        $this->assertNull($document->processing_error_message);
        $this->assertNotNull($document->processing_finished_at);

        // Old failed run must not be modified.
        $this->assertSame(RequirementExtractionRun::STATUS_FAILED, $oldRun->status);
        $this->assertSame('truncated_response', $oldRun->error_type);
    }

    public function test_it_marks_an_active_run_failed_when_the_job_failed_hook_receives_a_timeout_exception(): void
    {
        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-1003C', 'Hook timeout target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, now()->subMinutes(25)->toDateTimeString());

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'hook-timeout.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/hook-timeout.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'text_extracted_at' => now()->subMinutes(25)->toDateTimeString(),
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
            'queued_at' => now()->subMinutes(25)->toDateTimeString(),
            'processing_started_at' => now()->subMinutes(25)->toDateTimeString(),
            'processing_finished_at' => null,
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.');
        $existingRequirement = $this->createAiRequirementRow($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => now()->subMinutes(24)->toDateTimeString(),
        ]);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'queued_at' => now()->subMinutes(25)->toDateTimeString(),
            'started_at' => now()->subMinutes(25)->toDateTimeString(),
            'last_heartbeat_at' => now()->subMinutes(25)->toDateTimeString(),
        ]);
        $this->touchRequirementExtractionRun($run, now()->subMinutes(25)->toDateTimeString());
        $this->touchAiDocumentProcessing($document, now()->subMinutes(25)->toDateTimeString());

        $job = new ProcessRequirementExtractionRun($run->id);
        $job->failed(new TimeoutExceededException('The worker timed out while waiting for the OpenAI response.'));

        $run->refresh();
        $document->refresh();
        $existingRequirement->refresh();

        $this->assertSame(RequirementExtractionRun::STATUS_FAILED, $run->status);
        $this->assertSame('worker_timeout', $run->failure_stage);
        $this->assertSame('timeout', $run->error_type);
        $this->assertSame('Requirement extraction job timed out while waiting for the OpenAI request to complete.', $run->error_message);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_FAILED, $document->processing_status);
        $this->assertNotNull($document->processing_finished_at);
        $this->assertSame(SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED, $existingRequirement->publication_status);
        $this->assertSame(1, SavedNoticeAiRequirement::query()->where('saved_notice_id', $savedNotice->id)->count());
    }

    public function test_it_does_not_overwrite_a_completed_run_when_the_job_failed_hook_receives_an_exception(): void
    {
        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-1003D', 'Completed hook target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, now()->subMinutes(10)->toDateTimeString());

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'hook-completed.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/hook-completed.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'text_extracted_at' => now()->subMinutes(10)->toDateTimeString(),
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED,
            'queued_at' => now()->subMinutes(10)->toDateTimeString(),
            'processing_started_at' => now()->subMinutes(10)->toDateTimeString(),
            'processing_finished_at' => now()->subMinutes(9)->toDateTimeString(),
        ]);
        $existingRequirement = $this->createAiRequirementRow($savedNotice, $document, null, [
            'requirement_text' => 'Existing published requirement',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => now()->subMinutes(9)->toDateTimeString(),
        ]);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_COMPLETED,
            'queued_at' => now()->subMinutes(10)->toDateTimeString(),
            'started_at' => now()->subMinutes(10)->toDateTimeString(),
            'finished_at' => now()->subMinutes(9)->toDateTimeString(),
            'last_heartbeat_at' => now()->subMinutes(9)->toDateTimeString(),
        ]);
        $this->touchRequirementExtractionRun($run, now()->subMinutes(10)->toDateTimeString());

        $job = new ProcessRequirementExtractionRun($run->id);
        $job->failed(new \RuntimeException('forced failure after completion'));

        $run->refresh();
        $document->refresh();
        $existingRequirement->refresh();

        $this->assertSame(RequirementExtractionRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->failure_stage);
        $this->assertNull($run->error_type);
        $this->assertNull($run->error_message);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED, $document->processing_status);
        $this->assertSame(SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED, $existingRequirement->publication_status);
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

    public function test_process_run_call_stores_truncated_response_on_call_run_and_document_when_output_hits_token_limit(): void
    {
        Queue::fake();

        $truncatedJson = '{"candidates":[{"requirement_identifier":"1.1","parent_reference":null,"original_text":"Leverandøren skal levere dokumentasjon innen 10 dager."';

        Http::fake([
            '*' => Http::response([
                'id' => 'resp_truncated_propagation',
                'object' => 'response',
                'status' => 'completed',
                'output_text' => $truncatedJson,
                'usage' => [
                    'input_tokens' => 150,
                    'output_tokens' => FullDocumentRequirementExtractionPrompt::maxOutputTokens(),
                    'total_tokens' => 150 + FullDocumentRequirementExtractionPrompt::maxOutputTokens(),
                ],
            ], 200, [
                'x-request-id' => 'req_truncated_propagation',
            ]),
        ]);

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-2005', 'Truncated propagation target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-05-28 10:00:00');

        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'truncated-propagation.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/truncated-propagation.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => 'Leverandøren skal levere dokumentasjon innen 10 dager.',
            'text_extracted_at' => '2026-05-28 10:01:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
            'queued_at' => '2026-05-28 10:02:00',
            'processing_started_at' => '2026-05-28 10:02:00',
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.', 0);
        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'queued_at' => '2026-05-28 10:02:00',
            'started_at' => '2026-05-28 10:02:00',
            'last_heartbeat_at' => '2026-05-28 10:02:30',
        ]);
        $call = $this->createRequirementExtractionCall($run, $document, $chunk, [
            'status' => RequirementExtractionCall::STATUS_QUEUED,
        ]);

        $service = app(RequirementExtractionRunService::class);
        $service->processRunCall($call->id);

        $call->refresh();
        $run->refresh();
        $document->refresh();

        $this->assertSame(RequirementExtractionCall::STATUS_FAILED, $call->status);
        $this->assertSame('truncated_response', $call->error_type);
        $this->assertSame(
            'AI response appears to have been truncated at the configured output token limit before valid JSON could be parsed.',
            $call->error_message,
        );
        $this->assertSame(200, $call->status_code);
        $this->assertSame(FullDocumentRequirementExtractionPrompt::maxOutputTokens(), $call->output_tokens);
        $this->assertSame(RequirementExtractionRun::STATUS_PROCESSING, $run->status);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING, $document->processing_status);

        Queue::assertPushed(FinalizeRequirementExtractionRun::class, 1);

        $finalize = new FinalizeRequirementExtractionRun($run->id);
        $finalize->handle($service);

        $run->refresh();
        $document->refresh();

        $this->assertSame(RequirementExtractionRun::STATUS_FAILED, $run->status);
        $this->assertSame('chunk_extraction', $run->failure_stage);
        $this->assertSame('truncated_response', $run->error_type);
        $this->assertSame(
            'AI response appears to have been truncated at the configured output token limit before valid JSON could be parsed.',
            $run->error_message,
        );
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_FAILED, $document->processing_status);
        $this->assertSame('truncated_response', $document->processing_error_type);
        $this->assertSame(
            'AI response appears to have been truncated at the configured output token limit before valid JSON could be parsed.',
            $document->processing_error_message,
        );
    }

    public function test_finalize_deduplicates_cross_chunk_duplicates_before_promotion(): void
    {
        Queue::fake();

        $sameCandidate = $this->buildFullDocumentCandidate('Leverandøren skal levere dokumentasjon innen 10 dager.', [
            'requirement_identifier' => '1.1',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'obligation_type' => 'must',
            'interpretation_risk' => 'low',
        ]);

        Http::fake([
            '*' => Http::response([
                'id' => 'resp_dedup',
                'object' => 'response',
                'status' => 'completed',
                'output_text' => json_encode(['candidates' => [$sameCandidate]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'usage' => [
                    'input_tokens' => 120,
                    'output_tokens' => 42,
                    'total_tokens' => 162,
                ],
            ], 200, ['x-request-id' => 'req_dedup']),
        ]);

        $context = $this->customerContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-RUN-DEDUP-1', 'Cross-chunk dedup target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $this->touchSavedNotice($savedNotice, '2026-05-28 11:00:00');

        $documentText = "1. Leveringskrav\nLeverandøren skal levere dokumentasjon innen 10 dager.\n\n2. Kravgjentakelse\nLeverandøren skal levere dokumentasjon innen 10 dager.";
        $document = $this->createAiDocument($savedNotice, [
            'uploaded_by_user_id' => $context['user']->id,
            'original_filename' => 'cross-chunk-dedup.docx',
            'stored_path' => 'saved-notices/'.$savedNotice->id.'/ai-documents/cross-chunk-dedup.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
            'extracted_text' => $documentText,
            'text_extracted_at' => '2026-05-28 11:01:00',
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_TEXT_EXTRACTED,
        ]);
        $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.', 0);
        $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen 10 dager.', 1);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_QUEUED,
            'queued_at' => '2026-05-28 11:02:00',
            'started_at' => null,
            'last_heartbeat_at' => '2026-05-28 11:02:00',
        ]);

        $service = app(RequirementExtractionRunService::class);
        $service->orchestrateRunChunks($run);

        $calls = RequirementExtractionCall::query()
            ->where('requirement_extraction_run_id', $run->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $calls);

        $service->processRunCall($calls[0]->id);
        $service->processRunCall($calls[1]->id);

        $stagedBeforeFinalize = SavedNoticeAiRequirement::query()
            ->where('extraction_run_id', $run->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED)
            ->count();

        $this->assertSame(2, $stagedBeforeFinalize, 'Both chunks must have staged one row each before finalization');

        $finalize = new FinalizeRequirementExtractionRun($run->id);
        $finalize->handle($service);

        $run->refresh();
        $document->refresh();

        $publishedRequirements = SavedNoticeAiRequirement::query()
            ->where('extraction_run_id', $run->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED)
            ->get();

        $this->assertSame(RequirementExtractionRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED, $document->processing_status);
        $this->assertCount(1, $publishedRequirements, 'Dedup must collapse the two identical staged candidates into one published requirement');
        $this->assertSame(1, $run->candidate_count);
        $this->assertSame(1, $run->persisted_requirement_count);
        $this->assertSame(0, SavedNoticeAiRequirement::query()
            ->where('extraction_run_id', $run->id)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED)
            ->count());
    }

    public function test_completed_run_creates_one_ai_token_event_with_correct_fields(): void
    {
        $context = $this->customerContext('Token Event Extraction AS');
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-TOKEN-RUN-001', 'Token event target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $document = $this->createAiDocument($savedNotice, [
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon.', 0);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'input_tokens_total' => 1200,
            'output_tokens_total' => 450,
            'total_tokens_total' => 1650,
        ]);

        $this->createRequirementExtractionCall($run, $document, $chunk, [
            'status' => RequirementExtractionCall::STATUS_COMPLETED,
            'model' => 'gpt-4.1-mini',
        ]);

        $job = new FinalizeRequirementExtractionRun($run->id);
        $job->handle(app(RequirementExtractionRunService::class));

        $run->refresh();
        $this->assertSame(RequirementExtractionRun::STATUS_COMPLETED, $run->status);

        $event = AiTokenEvent::query()
            ->where('requirement_extraction_run_id', $run->id)
            ->first();

        $this->assertNotNull($event, 'One ai_token_events row must be created for a completed run with tokens.');
        $this->assertSame($context['customer']->id, $event->customer_id);
        $this->assertNull($event->user_id, 'user_id must be null since async job has no user context.');
        $this->assertSame(AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD, $event->operation_key);
        $this->assertSame(1200, $event->input_tokens);
        $this->assertSame(450, $event->output_tokens);
        $this->assertSame(1650, $event->total_tokens);
        $this->assertSame($savedNotice->id, $event->saved_notice_id);
        $this->assertSame($document->id, $event->saved_notice_ai_document_id);
        $this->assertSame($run->id, $event->requirement_extraction_run_id);
        $this->assertSame('gpt-4.1-mini', $event->model);
    }

    public function test_completed_run_records_one_ai_case_usage_with_correct_fields(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04 10:00:00'));

        $context = $this->customerContext('Case Usage Extraction AS');
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-CASE-USAGE-RUN-001', 'Case usage target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $document = $this->createAiDocument($savedNotice, [
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon.', 0);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'input_tokens_total' => 1200,
            'output_tokens_total' => 450,
            'total_tokens_total' => 1650,
        ]);

        $this->createRequirementExtractionCall($run, $document, $chunk, [
            'status' => RequirementExtractionCall::STATUS_COMPLETED,
            'model' => 'gpt-4.1-mini',
        ]);

        $job = new FinalizeRequirementExtractionRun($run->id);
        $job->handle(app(RequirementExtractionRunService::class));

        $caseUsage = CustomerAiCaseUsage::query()
            ->where('customer_id', $context['customer']->id)
            ->where('saved_notice_id', $savedNotice->id)
            ->where('source_operation_key', AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD)
            ->first();

        $this->assertNotNull($caseUsage, 'One customer_ai_case_usages row must be created for a completed extraction run.');
        $this->assertSame($context['customer']->id, $caseUsage->customer_id);
        $this->assertSame($savedNotice->id, $caseUsage->saved_notice_id);
        $this->assertNull($caseUsage->activated_by_user_id, 'Async extraction completion has no safe user context.');
        $this->assertSame(AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD, $caseUsage->source_operation_key);
        $this->assertSame('2026-06-01', $caseUsage->period_start?->toDateString());
        $this->assertSame('2026-06-30', $caseUsage->period_end?->toDateString());
        $this->assertNull($caseUsage->source_ai_usage_event_id);
        $this->assertNull($caseUsage->source_ai_token_event_id);
    }

    public function test_failed_run_does_not_create_ai_token_event(): void
    {
        $context = $this->customerContext('Token Fail Extraction AS');
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-TOKEN-RUN-002', 'Token fail target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $document = $this->createAiDocument($savedNotice, [
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon.', 0);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'input_tokens_total' => 800,
            'output_tokens_total' => 0,
            'total_tokens_total' => 800,
        ]);

        $this->createRequirementExtractionCall($run, $document, $chunk, [
            'status' => RequirementExtractionCall::STATUS_FAILED,
            'error_type' => 'truncated_response',
            'error_message' => 'OpenAI response was truncated.',
        ]);

        $job = new FinalizeRequirementExtractionRun($run->id);
        $job->handle(app(RequirementExtractionRunService::class));

        $this->assertSame(RequirementExtractionRun::STATUS_FAILED, $run->refresh()->status);

        $eventCount = AiTokenEvent::query()
            ->where('requirement_extraction_run_id', $run->id)
            ->count();

        $this->assertSame(0, $eventCount, 'No ai_token_events must be created when the run fails.');
        $this->assertSame(0, CustomerAiCaseUsage::query()->count(), 'No customer_ai_case_usages row must be created when the run fails.');
    }

    public function test_completed_run_with_zero_tokens_does_not_create_token_event(): void
    {
        $context = $this->customerContext('Zero Token Extraction AS');
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-TOKEN-RUN-003', 'Zero token target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $document = $this->createAiDocument($savedNotice, [
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon.', 0);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'input_tokens_total' => 0,
            'output_tokens_total' => 0,
            'total_tokens_total' => 0,
        ]);

        $this->createRequirementExtractionCall($run, $document, $chunk, [
            'status' => RequirementExtractionCall::STATUS_COMPLETED,
        ]);

        $job = new FinalizeRequirementExtractionRun($run->id);
        $job->handle(app(RequirementExtractionRunService::class));

        $this->assertSame(RequirementExtractionRun::STATUS_COMPLETED, $run->refresh()->status);
        $this->assertSame(0, AiTokenEvent::query()->where('requirement_extraction_run_id', $run->id)->count(),
            'No token event must be created when total_tokens_total is zero.');
    }

    public function test_completed_run_does_not_create_duplicate_token_event_on_second_finalize(): void
    {
        $context = $this->customerContext('Dedup Token Extraction AS');
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'AI-TOKEN-RUN-004', 'Dedup token target', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $document = $this->createAiDocument($savedNotice, [
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
        ]);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon.', 0);

        $run = $this->createRequirementExtractionRun($document, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'input_tokens_total' => 500,
            'output_tokens_total' => 200,
            'total_tokens_total' => 700,
        ]);

        $this->createRequirementExtractionCall($run, $document, $chunk, [
            'status' => RequirementExtractionCall::STATUS_COMPLETED,
        ]);

        $service = app(RequirementExtractionRunService::class);

        $job = new FinalizeRequirementExtractionRun($run->id);
        $job->handle($service);

        $this->assertSame(1, AiTokenEvent::query()->where('requirement_extraction_run_id', $run->id)->count(),
            'One token event after first finalize.');

        $service->promoteRun($run->fresh(), $document->fresh());

        $this->assertSame(1, AiTokenEvent::query()->where('requirement_extraction_run_id', $run->id)->count(),
            'Still one token event after second promote — no duplicate.');
        $this->assertSame(1, CustomerAiCaseUsage::query()
            ->where('customer_id', $context['customer']->id)
            ->where('saved_notice_id', $savedNotice->id)
            ->count(), 'Still one case usage row after second promote — no duplicate.');
    }

    public function test_token_event_has_correct_customer_id_and_saved_notice_id(): void
    {
        $contextA = $this->customerContext('Customer A Token');
        $noticeA = $this->createSavedNotice($contextA['customer']->id, 'AI-TOKEN-RUN-005A', 'Customer A notice', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $documentA = $this->createAiDocument($noticeA, [
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
        ]);
        $chunkA = $this->createAiDocumentChunk($documentA, 'Leverandøren skal levere.', 0);
        $runA = $this->createRequirementExtractionRun($documentA, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'input_tokens_total' => 300,
            'output_tokens_total' => 100,
            'total_tokens_total' => 400,
        ]);
        $this->createRequirementExtractionCall($runA, $documentA, $chunkA, [
            'status' => RequirementExtractionCall::STATUS_COMPLETED,
        ]);

        $contextB = $this->customerContext('Customer B Token');
        $noticeB = $this->createSavedNotice($contextB['customer']->id, 'AI-TOKEN-RUN-005B', 'Customer B notice', [
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
        ]);
        $documentB = $this->createAiDocument($noticeB, [
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_PROCESSING,
        ]);
        $chunkB = $this->createAiDocumentChunk($documentB, 'Leverandøren skal beskrive.', 0);
        $runB = $this->createRequirementExtractionRun($documentB, [
            'status' => RequirementExtractionRun::STATUS_PROCESSING,
            'input_tokens_total' => 600,
            'output_tokens_total' => 200,
            'total_tokens_total' => 800,
        ]);
        $this->createRequirementExtractionCall($runB, $documentB, $chunkB, [
            'status' => RequirementExtractionCall::STATUS_COMPLETED,
        ]);

        $service = app(RequirementExtractionRunService::class);
        (new FinalizeRequirementExtractionRun($runA->id))->handle($service);
        (new FinalizeRequirementExtractionRun($runB->id))->handle($service);

        $eventA = AiTokenEvent::query()->where('requirement_extraction_run_id', $runA->id)->first();
        $eventB = AiTokenEvent::query()->where('requirement_extraction_run_id', $runB->id)->first();

        $this->assertSame($contextA['customer']->id, $eventA->customer_id);
        $this->assertSame($noticeA->id, $eventA->saved_notice_id);
        $this->assertSame(400, $eventA->total_tokens);

        $this->assertSame($contextB['customer']->id, $eventB->customer_id);
        $this->assertSame($noticeB->id, $eventB->saved_notice_id);
        $this->assertSame(800, $eventB->total_tokens);
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

    private function createRequirementExtractionRun(
        SavedNoticeAiDocument $document,
        array $overrides = [],
    ): RequirementExtractionRun {
        return RequirementExtractionRun::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'saved_notice_id' => $document->saved_notice_id,
            'saved_notice_ai_document_id' => $document->id,
            'status' => $overrides['status'] ?? RequirementExtractionRun::STATUS_PROCESSING,
            'strategy' => RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION,
            'prompt_version' => FullDocumentRequirementExtractionPrompt::promptVersion(),
            'model' => FullDocumentRequirementExtractionPrompt::model(),
            'failure_stage' => $overrides['failure_stage'] ?? null,
            'error_type' => $overrides['error_type'] ?? null,
            'error_message' => $overrides['error_message'] ?? null,
            'candidate_count' => $overrides['candidate_count'] ?? 0,
            'persisted_requirement_count' => $overrides['persisted_requirement_count'] ?? 0,
            'openai_call_count' => $overrides['openai_call_count'] ?? 0,
            'input_tokens_total' => $overrides['input_tokens_total'] ?? 0,
            'output_tokens_total' => $overrides['output_tokens_total'] ?? 0,
            'total_tokens_total' => $overrides['total_tokens_total'] ?? 0,
            'queued_at' => $overrides['queued_at'] ?? now(),
            'started_at' => $overrides['started_at'] ?? null,
            'finished_at' => $overrides['finished_at'] ?? null,
            'last_heartbeat_at' => $overrides['last_heartbeat_at'] ?? now(),
        ], $overrides));
    }

    private function createRequirementExtractionCall(
        RequirementExtractionRun $run,
        SavedNoticeAiDocument $document,
        ?SavedNoticeAiDocumentChunk $chunk = null,
        array $overrides = [],
    ): RequirementExtractionCall {
        $status = (string) ($overrides['status'] ?? RequirementExtractionCall::STATUS_QUEUED);

        return RequirementExtractionCall::query()->create(array_merge([
            'requirement_extraction_run_id' => $run->id,
            'saved_notice_id' => $document->saved_notice_id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_ai_document_chunk_id' => $chunk?->id,
            'status' => $status,
            'strategy' => $overrides['strategy'] ?? RequirementExtractionRun::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION,
            'prompt_version' => $overrides['prompt_version'] ?? FullDocumentRequirementExtractionPrompt::promptVersion(),
            'model' => $overrides['model'] ?? FullDocumentRequirementExtractionPrompt::model(),
            'request_id' => $overrides['request_id'] ?? null,
            'response_id' => $overrides['response_id'] ?? null,
            'status_code' => $overrides['status_code'] ?? null,
            'input_tokens' => $overrides['input_tokens'] ?? null,
            'output_tokens' => $overrides['output_tokens'] ?? null,
            'total_tokens' => $overrides['total_tokens'] ?? null,
            'elapsed_ms' => $overrides['elapsed_ms'] ?? null,
            'error_type' => $overrides['error_type'] ?? null,
            'error_message' => $overrides['error_message'] ?? null,
            'started_at' => $overrides['started_at'] ?? ($status === RequirementExtractionCall::STATUS_RUNNING ? now() : null),
            'finished_at' => $overrides['finished_at'] ?? null,
        ], $overrides));
    }

    private function touchRequirementExtractionRun(RequirementExtractionRun $run, string $timestamp): RequirementExtractionRun
    {
        DB::table('requirement_extraction_runs')
            ->where('id', $run->id)
            ->update([
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'queued_at' => $timestamp,
                'started_at' => $timestamp,
                'last_heartbeat_at' => $timestamp,
            ]);

        return $run->refresh();
    }

    private function touchAiDocumentProcessing(SavedNoticeAiDocument $document, string $timestamp): SavedNoticeAiDocument
    {
        DB::table('saved_notice_ai_documents')
            ->where('id', $document->id)
            ->update([
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'queued_at' => $timestamp,
                'processing_started_at' => $timestamp,
            ]);

        return $document->refresh();
    }

    private function createAiRequirementRow(
        SavedNotice $savedNotice,
        SavedNoticeAiDocument $document,
        ?SavedNoticeAiDocumentChunk $chunk,
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
            'saved_notice_ai_document_chunk_id' => $chunk?->id,
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
            'source_reference' => $overrides['source_reference'] ?? array_filter([
                'saved_notice_id' => $savedNotice->id,
                'saved_notice_ai_document_id' => $document->id,
                'saved_notice_ai_document_chunk_id' => $chunk?->id,
                'document_filename' => $document->original_filename,
                'source_block_id' => $chunk !== null
                    ? sprintf('saved-notice-ai-document-%d-chunk-%d', $document->id, $chunk->id)
                    : sprintf('saved-notice-ai-document-%d', $document->id),
                'source_block_index' => $chunk?->chunk_index,
                'source_chunk_ids' => $chunk !== null ? [$chunk->id] : null,
            ], static fn (mixed $value): bool => $value !== null),
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
