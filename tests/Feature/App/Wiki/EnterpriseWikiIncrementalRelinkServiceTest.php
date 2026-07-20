<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiIngest;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageRelinkAttempt;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiLinkRevisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiGraphDataService;
use App\Services\EnterpriseWiki\EnterpriseWikiIncrementalRelinkService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageTraversalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 8I-5: incremental relinking of existing pages when a concept/entity page is
 * created or updated.
 */
class EnterpriseWikiIncrementalRelinkServiceTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // 1: new concept page finds an existing relevant article
    // =========================================================================

    public function test_new_concept_page_finds_existing_relevant_article_as_candidate(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $trigger = $this->createPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This article discusses the Business Case in detail.');
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockAiRevision('This article discusses the [[business-case|Business Case]] in detail.', changed: true);

        $result = $this->service()->relinkForRun($run);

        $this->assertSame(1, $result['triggers_processed']);
        $this->assertSame(1, $result['candidates_considered']);
        $this->assertSame(1, $result['applied']);

        $this->assertDatabaseHas('enterprise_wiki_page_relink_attempts', [
            'enterprise_wiki_ingest_run_id' => $run->id,
            'trigger_page_id' => $trigger->id,
            'candidate_page_id' => $article->id,
            'status' => EnterpriseWikiPageRelinkAttempt::STATUS_APPLIED,
        ]);
    }

    // =========================================================================
    // 2: existing plain-text mention becomes an inline wikilink
    // =========================================================================

    public function test_existing_plain_text_mention_becomes_inline_wikilink(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $trigger = $this->createPage($customer, 'prosjekteier', 'Prosjekteier', EnterpriseWikiPage::PAGE_TYPE_ENTITY);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'The Prosjekteier owns this deliverable.');
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockAiRevision('The [[prosjekteier|Prosjekteier]] owns this deliverable.', changed: true);

        $this->service()->relinkForRun($run);

        $current = $this->currentVersion($article);
        $this->assertStringContainsString('[[prosjekteier|Prosjekteier]]', $current->content_markdown);
        $this->assertSame(2, $current->version_number);
    }

    // =========================================================================
    // 3: irrelevant page is not changed
    // =========================================================================

    public function test_irrelevant_page_without_a_mention_is_not_considered_a_candidate(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $trigger = $this->createPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $unrelated = $this->createVersionedPage($customer, 'unrelated', 'Unrelated', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This page is about something else entirely.');
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $result = $this->service()->relinkForRun($run);

        $this->assertSame(0, $result['candidates_considered']);
        $this->assertDatabaseMissing('enterprise_wiki_page_relink_attempts', [
            'candidate_page_id' => $unrelated->id,
        ]);
        $this->assertSame(1, $this->currentVersion($unrelated)->version_number);
    }

    // =========================================================================
    // 4: existing valid link is not duplicated
    // =========================================================================

    public function test_existing_valid_wikilink_is_not_duplicated(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $trigger = $this->createPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'See [[business-case|Business Case]] for details.');
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $result = $this->service()->relinkForRun($run);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $this->currentVersion($article)->version_number);

        $this->assertDatabaseHas('enterprise_wiki_page_relink_attempts', [
            'candidate_page_id' => $article->id,
            'status' => EnterpriseWikiPageRelinkAttempt::STATUS_SKIPPED,
            'reason' => EnterpriseWikiPageRelinkAttempt::REASON_ALREADY_LINKED,
        ]);
    }

    // =========================================================================
    // 5: unknown slug in the AI revision is rejected
    // =========================================================================

    public function test_unknown_slug_in_revision_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $trigger = $this->createPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This mentions Business Case here.');
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockAiRevision('This mentions [[does-not-exist]] here.', changed: true);

        $result = $this->service()->relinkForRun($run);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $this->currentVersion($article)->version_number);
        $this->assertDatabaseHas('enterprise_wiki_page_relink_attempts', [
            'candidate_page_id' => $article->id,
            'status' => EnterpriseWikiPageRelinkAttempt::STATUS_FAILED,
            'reason' => EnterpriseWikiPageRelinkAttempt::REASON_INVALID_REVISION,
        ]);
    }

    // =========================================================================
    // 6: self-link in the AI revision is rejected
    // =========================================================================

    public function test_self_link_in_revision_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $trigger = $this->createPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This mentions Business Case here.');
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockAiRevision('This mentions [[artikkel|Business Case]] here.', changed: true);

        $result = $this->service()->relinkForRun($run);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $this->currentVersion($article)->version_number);
        $this->assertDatabaseHas('enterprise_wiki_page_relink_attempts', [
            'candidate_page_id' => $article->id,
            'status' => EnterpriseWikiPageRelinkAttempt::STATUS_FAILED,
            'reason' => EnterpriseWikiPageRelinkAttempt::REASON_INVALID_REVISION,
        ]);
    }

    // =========================================================================
    // 7: a page belonging to another customer is never considered
    // =========================================================================

    public function test_other_customers_page_is_never_considered_a_candidate(): void
    {
        $customerA = $this->createCustomer('Customer A');
        $customerB = $this->createCustomer('Customer B');
        $document = $this->createDocument($customerA);
        $trigger = $this->createPage($customerA, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $foreignPage = $this->createVersionedPage($customerB, 'foreign', 'Foreign', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This also discusses Business Case.');
        $run = $this->createAppliedRun($customerA, $document, [$trigger]);

        $result = $this->service()->relinkForRun($run);

        $this->assertSame(0, $result['candidates_considered']);
        $this->assertDatabaseMissing('enterprise_wiki_page_relink_attempts', [
            'candidate_page_id' => $foreignPage->id,
        ]);
    }

    // =========================================================================
    // 8: no textual change from the AI produces no new version
    // =========================================================================

    public function test_ai_declining_a_change_creates_no_new_version(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $trigger = $this->createPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Business Case is mentioned only in passing here.');
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockAiRevision('Business Case is mentioned only in passing here.', changed: false);

        $result = $this->service()->relinkForRun($run);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $this->currentVersion($article)->version_number);
        $this->assertDatabaseHas('enterprise_wiki_page_relink_attempts', [
            'candidate_page_id' => $article->id,
            'status' => EnterpriseWikiPageRelinkAttempt::STATUS_SKIPPED,
            'reason' => EnterpriseWikiPageRelinkAttempt::REASON_NO_SEMANTIC_IMPROVEMENT,
        ]);
    }

    // =========================================================================
    // 9 + 10: an actual change creates exactly one new current version, and the
    // old version is preserved (not deleted, no longer current)
    // =========================================================================

    public function test_an_actual_change_creates_exactly_one_new_current_version_and_preserves_the_old_one(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $trigger = $this->createPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This discusses Business Case here.');
        $originalVersionId = $this->currentVersion($article)->id;
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockAiRevision('This discusses [[business-case|Business Case]] here.', changed: true);

        $this->service()->relinkForRun($run);

        $this->assertSame(
            2,
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count(),
        );

        $oldVersion = EnterpriseWikiPageVersion::query()->find($originalVersionId);
        $this->assertNotNull($oldVersion);
        $this->assertFalse($oldVersion->is_current);
        $this->assertSame('This discusses Business Case here.', $oldVersion->content_markdown);

        $newVersion = $this->currentVersion($article);
        $this->assertSame(2, $newVersion->version_number);
        $this->assertTrue($newVersion->is_current);
    }

    // =========================================================================
    // 11: canonical links are re-materialized after the new version
    // =========================================================================

    public function test_canonical_wikilink_is_materialized_after_relinking(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $trigger = $this->createPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This discusses Business Case here.');
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockAiRevision('This discusses [[business-case|Business Case]] here.', changed: true);

        $this->service()->relinkForRun($run);

        $this->assertDatabaseHas('enterprise_wiki_page_links', [
            'customer_id' => $customer->id,
            'from_page_id' => $article->id,
            'to_page_id' => $trigger->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
        ]);
    }

    // =========================================================================
    // 12: backlinks, traversal and graph reflect the new link
    // =========================================================================

    public function test_backlinks_traversal_and_graph_reflect_the_new_link(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $trigger = $this->createPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This discusses Business Case here.');
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockAiRevision('This discusses [[business-case|Business Case]] here.', changed: true);

        $this->service()->relinkForRun($run);

        $incoming = app(EnterpriseWikiPageTraversalService::class)->incoming($trigger);
        $this->assertTrue($incoming->pluck('id')->contains($article->id));

        $graph = app(EnterpriseWikiGraphDataService::class)->build($customer->id, EnterpriseWikiPage::STATUSES, pageId: $trigger->id);
        $edgeExists = collect($graph['edges'])->contains(
            fn (array $edge) => $edge['from_page_id'] === $article->id && $edge['to_page_id'] === $trigger->id,
        );
        $this->assertTrue($edgeExists);
    }

    // =========================================================================
    // 13: double dispatch does not create a second version
    // =========================================================================

    public function test_double_dispatch_does_not_create_a_second_version(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $trigger = $this->createPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This discusses Business Case here.');
        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockAiRevision('This discusses [[business-case|Business Case]] here.', changed: true, times: 1);

        $this->service()->relinkForRun($run);
        $this->service()->relinkForRun($run);

        $this->assertSame(
            2,
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count(),
        );
        $this->assertSame(
            1,
            EnterpriseWikiPageRelinkAttempt::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('trigger_page_id', $trigger->id)
                ->where('candidate_page_id', $article->id)
                ->count(),
        );
    }

    // =========================================================================
    // 14: candidate cap is enforced
    // =========================================================================

    public function test_candidate_cap_is_enforced(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $trigger = $this->createPage($customer, 'business-case', 'Business Case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);

        $extra = EnterpriseWikiIncrementalRelinkService::MAX_CANDIDATES_PER_TRIGGER + 5;

        for ($i = 0; $i < $extra; $i++) {
            $this->createVersionedPage($customer, "page-{$i}", "Page {$i}", EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This mentions Business Case in its text.');
        }

        $run = $this->createAppliedRun($customer, $document, [$trigger]);

        $this->mockAiRevision('This mentions Business Case in its text.', changed: false, times: EnterpriseWikiIncrementalRelinkService::MAX_CANDIDATES_PER_TRIGGER);

        $result = $this->service()->relinkForRun($run);

        $this->assertSame(EnterpriseWikiIncrementalRelinkService::MAX_CANDIDATES_PER_TRIGGER, $result['candidates_considered']);
    }

    // =========================================================================
    // 15: legacy ProcessEnterpriseWikiIngest is untouched
    // =========================================================================

    public function test_process_enterprise_wiki_ingest_not_modified(): void
    {
        $reflection = new \ReflectionClass(ProcessEnterpriseWikiIngest::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString('EnterpriseWikiIncrementalRelinkService', $source);
        $this->assertStringNotContainsString('WikiLinkRevisionAiClient', $source);
        $this->assertStringNotContainsString('relinkForRun', $source);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): EnterpriseWikiIncrementalRelinkService
    {
        return app(EnterpriseWikiIncrementalRelinkService::class);
    }

    private function mockAiRevision(string $markdown, bool $changed, int $times = 1): void
    {
        $this->mock(WikiLinkRevisionAiClient::class)
            ->shouldReceive('reviseLinks')
            ->times($times)
            ->andReturn(['changed' => $changed, 'markdown' => $markdown]);
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    private function createCustomer(string $name = 'Test AS'): Customer
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
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'This is the extracted text from the source document.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createPage(
        Customer $customer,
        string $slug,
        string $title,
        string $pageType = EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
    ): EnterpriseWikiPage {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug,
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createVersionedPage(
        Customer $customer,
        string $slug,
        string $title,
        string $pageType,
        string $content,
    ): EnterpriseWikiPage {
        $page = $this->createPage($customer, $slug, $title, $pageType);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $content,
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }

    private function createAppliedRun(Customer $customer, EnterpriseWikiDocument $document, array $triggerPages): EnterpriseWikiIngestRun
    {
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);

        foreach ($triggerPages as $page) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            ]);
        }

        return $run;
    }
}
