<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentWikiAnswerStalenessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

class EnterpriseWikiDocumentWikiAnswerStalenessServiceTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use UsesProjectPostgresConnection;

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

    public function test_marks_answers_stale_when_a_cited_source_page_is_deleted(): void
    {
        $customer = $this->createWikiCustomer();
        $savedNotice = $this->createSavedNotice($customer->id);
        $aiDocument = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($aiDocument, 'Beskriv prosessen.');
        $requirement = $this->createRequirement($savedNotice, $aiDocument, $chunk);
        $document = $this->createDocument($customer);
        $page = $this->createWikiPageWithVersion($customer, 'Incident Management', 'Innhold.');

        $answer = $this->createWikiAnswer($requirement, [
            [
                'enterprise_wiki_page_id' => $page->id,
                'page_title' => $page->title,
                'page_slug' => $page->slug,
                'page_type' => $page->page_type,
                'selection_type' => 'direct_search',
                'discovered_from_page_id' => null,
                'discovered_from_title' => null,
                'link_direction' => null,
                'supporting_claim_ids' => [],
            ],
        ]);

        $service = app(EnterpriseWikiDocumentWikiAnswerStalenessService::class);

        $preview = $service->previewDeletionImpact($document, collect([123]), collect([$page->id]));
        $this->assertSame(1, $preview['stale_wiki_answer_count']);

        $result = $service->markAnswersStaleForDeletedDocument($document, collect([123]), collect([$page->id]));
        $this->assertSame(1, $result['stale_wiki_answer_count']);

        $answer->refresh();

        $this->assertTrue($answer->isStale());
        $this->assertSame(SavedNoticeAiRequirementWikiAnswer::STALE_REASON_SOURCE_DOCUMENT_DELETED, $answer->stale_reason);
        $this->assertSame('deleted-source.pdf', $answer->stale_context['deleted_document_name']);
        $this->assertSame('answer body', $answer->answer_text);

        $repeat = $service->markAnswersStaleForDeletedDocument($document, collect([123]), collect([$page->id]));
        $this->assertSame(0, $repeat['stale_wiki_answer_count']);
    }

    public function test_marks_answers_stale_when_a_shared_page_claim_is_removed(): void
    {
        $customer = $this->createWikiCustomer();
        $savedNotice = $this->createSavedNotice($customer->id);
        $aiDocument = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($aiDocument, 'Beskriv prosessen.');
        $requirement = $this->createRequirement($savedNotice, $aiDocument, $chunk);
        $document = $this->createDocument($customer);
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');
        $claim = $this->createWikiClaim($page, 'Problem Management gjennomfører rotårsaksanalyse.');

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => 'deleted-source.pdf',
            'excerpt' => 'Rotårsaksanalyse er beskrevet i dokumentet.',
            'source_hash' => hash('sha256', 'claim-ref'),
        ]);

        $answer = $this->createWikiAnswer($requirement, [
            [
                'enterprise_wiki_page_id' => $page->id,
                'page_title' => $page->title,
                'page_slug' => $page->slug,
                'page_type' => $page->page_type,
                'selection_type' => 'direct_search',
                'discovered_from_page_id' => null,
                'discovered_from_title' => null,
                'link_direction' => null,
                'supporting_claim_ids' => [$claim->id],
            ],
        ]);

        $service = app(EnterpriseWikiDocumentWikiAnswerStalenessService::class);
        $result = $service->markAnswersStaleForDeletedDocument($document, collect([456]), collect());

        $this->assertSame(1, $result['stale_wiki_answer_count']);
        $answer->refresh();
        $this->assertTrue($answer->isStale());
        $this->assertSame([$claim->id], $answer->stale_context['matched_claim_ids']);
    }

    public function test_unrelated_answers_are_not_marked_stale(): void
    {
        $customer = $this->createWikiCustomer();
        $savedNotice = $this->createSavedNotice($customer->id);
        $aiDocument = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($aiDocument, 'Beskriv prosessen.');
        $requirement = $this->createRequirement($savedNotice, $aiDocument, $chunk);
        $document = $this->createDocument($customer);
        $otherPage = $this->createWikiPageWithVersion($customer, 'Urelatert', 'Innhold.');

        $answer = $this->createWikiAnswer($requirement, [
            [
                'enterprise_wiki_page_id' => $otherPage->id,
                'page_title' => $otherPage->title,
                'page_slug' => $otherPage->slug,
                'page_type' => $otherPage->page_type,
                'selection_type' => 'direct_search',
                'discovered_from_page_id' => null,
                'discovered_from_title' => null,
                'link_direction' => null,
                'supporting_claim_ids' => [],
            ],
        ], 'Uavhengig svar.');

        $service = app(EnterpriseWikiDocumentWikiAnswerStalenessService::class);
        $result = $service->markAnswersStaleForDeletedDocument($document, collect([1]), collect([999999]));

        $this->assertSame(0, $result['stale_wiki_answer_count']);
        $answer->refresh();
        $this->assertFalse($answer->isStale());
        $this->assertSame('Uavhengig svar.', $answer->answer_text);
    }

    public function test_shared_page_with_another_live_source_does_not_become_stale(): void
    {
        $customer = $this->createWikiCustomer();
        $savedNotice = $this->createSavedNotice($customer->id);
        $aiDocument = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($aiDocument, 'Beskriv prosessen.');
        $requirement = $this->createRequirement($savedNotice, $aiDocument, $chunk);
        $deletedDocument = $this->createDocument($customer);
        $otherDocument = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'other-source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/other-source.pdf',
            'file_hash_sha256' => hash('sha256', 'other-source'),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            'extracted_text' => 'Annen dokumentkilde.',
        ]);
        $page = $this->createWikiPageWithVersion($customer, 'Problem Management', 'Innhold.');
        $claim = $this->createWikiClaim($page, 'Problem Management gjennomfører rotårsaksanalyse.');

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $deletedDocument->id,
            'source_label' => 'deleted-source.pdf',
            'excerpt' => 'Rotårsaksanalyse er beskrevet i dokumentet som slettes.',
            'source_hash' => hash('sha256', 'claim-ref-deleted'),
        ]);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $otherDocument->id,
            'source_label' => 'other-source.pdf',
            'excerpt' => 'Rotårsaksanalyse er også beskrevet i en annen kilde.',
            'source_hash' => hash('sha256', 'claim-ref-other'),
        ]);

        $answer = $this->createWikiAnswer($requirement, [
            [
                'enterprise_wiki_page_id' => $page->id,
                'page_title' => $page->title,
                'page_slug' => $page->slug,
                'page_type' => $page->page_type,
                'selection_type' => 'direct_search',
                'discovered_from_page_id' => null,
                'discovered_from_title' => null,
                'link_direction' => null,
                'supporting_claim_ids' => [$claim->id],
            ],
        ], 'Svar som fortsatt er forankret i en annen kilde.');

        $service = app(EnterpriseWikiDocumentWikiAnswerStalenessService::class);
        $result = $service->markAnswersStaleForDeletedDocument($deletedDocument, collect([77]), collect());

        $this->assertSame(0, $result['stale_wiki_answer_count']);
        $answer->refresh();
        $this->assertFalse($answer->isStale());
        $this->assertSame('Svar som fortsatt er forankret i en annen kilde.', $answer->answer_text);
    }

    private function createSavedNotice(int $customerId): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customerId,
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'wiki-stale-'.uniqid(),
            'title' => 'Wiki stale test',
            'buyer_name' => 'Procynia',
            'external_url' => 'https://example.invalid',
            'summary' => 'Kort oppsummering',
            'publication_date' => '2026-03-20 00:00:00',
            'deadline' => '2026-04-20 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
        ]);
    }

    private function createAiDocument(SavedNotice $savedNotice): SavedNoticeAiDocument
    {
        return SavedNoticeAiDocument::query()->create([
            'saved_notice_id' => $savedNotice->id,
            'original_filename' => 'analysis.pdf',
            'stored_path' => sprintf('saved-notices/%d/ai-documents/analysis.pdf', $savedNotice->id),
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_UPLOADED,
        ]);
    }

    private function createAiDocumentChunk(SavedNoticeAiDocument $document, string $content): SavedNoticeAiDocumentChunk
    {
        return SavedNoticeAiDocumentChunk::query()->create([
            'saved_notice_ai_document_id' => $document->id,
            'chunk_index' => 0,
            'content' => $content,
            'char_start' => 0,
            'char_end' => mb_strlen($content, 'UTF-8'),
            'word_count' => count(preg_split('/\s+/u', trim($content)) ?: []),
        ]);
    }

    private function createRequirement(
        SavedNotice $savedNotice,
        SavedNoticeAiDocument $document,
        SavedNoticeAiDocumentChunk $chunk,
    ): SavedNoticeAiRequirement {
        return SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $savedNotice->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_ai_document_chunk_id' => $chunk->id,
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'requirement_identifier' => 'REQ-1',
            'requirement_text' => 'Beskriv prosessen.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
            'published_at' => now(),
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'deleted-source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/deleted-source.pdf',
            'file_hash_sha256' => hash('sha256', 'deleted-source'),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            'extracted_text' => 'Dokumentinnhold.',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     */
    private function createWikiAnswer(
        SavedNoticeAiRequirement $requirement,
        array $sources,
        string $answerText = 'answer body',
    ): SavedNoticeAiRequirementWikiAnswer {
        return SavedNoticeAiRequirementWikiAnswer::query()->create([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'coverage_status' => SavedNoticeAiRequirementWikiAnswer::COVERAGE_FULL,
            'answer_text' => $answerText,
            'sources' => $sources,
            'model' => 'gpt-4.1-mini',
            'research_trace' => ['answer' => ['answer_sections' => []], 'research' => ['pages' => []]],
            'alignment_trace' => ['sections' => [], 'coverage_status' => 'full', 'has_possible_conflict' => false, 'revision' => ['attempted' => false, 'section_keys' => []]],
            'has_possible_conflict' => false,
            'generated_at' => now(),
        ]);
    }
}
