<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Safe repair for existing article/summary pairs missing their mutual [[wikilink]] — never
 * regenerates the document, never touches ambiguous pairs, and always goes through the ordinary
 * page-version flow (bump version_number, keep every existing block byte-for-byte). Run 39 is
 * this feature's real-world verification basis (see final report) but is never referenced here.
 */
class EnterpriseWikiRepairArticleSummaryLinksCommandTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // New/missing link is repaired
    // =========================================================================

    public function test_repairs_missing_link_for_unambiguous_pair(): void
    {
        $customer = $this->createCustomer();
        [$run, $article, $summary] = $this->createUnlinkedPair($customer);

        Artisan::call('wiki:repair-article-summary-links', ['--run-id' => $run->id, '--apply' => true]);

        $articleMarkdown = $this->currentMarkdown($article);
        $summaryMarkdown = $this->currentMarkdown($summary);

        $this->assertStringContainsString('[['.$summary->slug.'|', $articleMarkdown);
        $this->assertStringContainsString('[['.$article->slug.'|', $summaryMarkdown);
        $this->assertTrue($this->linkExists($customer, $article, $summary, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY));
        $this->assertTrue($this->linkExists($customer, $summary, $article, EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ARTICLE));
    }

    public function test_repair_creates_a_new_page_version_not_an_in_place_edit(): void
    {
        $customer = $this->createCustomer();
        [$run, $article] = $this->createUnlinkedPair($customer);
        $originalVersionId = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)->where('is_current', true)->value('id');

        Artisan::call('wiki:repair-article-summary-links', ['--run-id' => $run->id, '--apply' => true]);

        $current = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)->where('is_current', true)->first();

        $this->assertNotSame($originalVersionId, $current->id);
        $this->assertSame(2, $current->version_number);
        $this->assertFalse((bool) EnterpriseWikiPageVersion::query()->find($originalVersionId)->is_current);
    }

    public function test_dry_run_does_not_persist_any_change(): void
    {
        $customer = $this->createCustomer();
        [$run, $article] = $this->createUnlinkedPair($customer);
        $versionsBefore = EnterpriseWikiPageVersion::query()->count();

        Artisan::call('wiki:repair-article-summary-links', ['--run-id' => $run->id]);

        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());
        $this->assertFalse(
            EnterpriseWikiPageLink::query()->where('from_page_id', $article->id)->exists(),
        );
    }

    // =========================================================================
    // No duplication for already-linked pairs
    // =========================================================================

    public function test_does_not_duplicate_an_existing_link(): void
    {
        $customer = $this->createCustomer();
        [$run, $article, $summary] = $this->createLinkedPair($customer);
        $versionsBefore = EnterpriseWikiPageVersion::query()->count();

        Artisan::call('wiki:repair-article-summary-links', ['--run-id' => $run->id, '--apply' => true]);

        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());
        $this->assertSame(
            1,
            EnterpriseWikiPageLink::query()
                ->where('from_page_id', $article->id)
                ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY)
                ->count(),
        );
    }

    // =========================================================================
    // User-edited content is preserved
    // =========================================================================

    public function test_preserves_user_edited_content_and_only_appends_a_new_block(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRunApplied($customer, $document);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'User Edited Article');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag');
        $this->addPageToRun($run, $article);
        $this->addPageToRun($run, $summary);

        $userBlocks = [
            ['block_key' => 'block-0001', 'position' => 0, 'markdown' => '# User Edited Article'],
            ['block_key' => 'block-0002', 'position' => 1, 'markdown' => 'A paragraph the user carefully wrote and edited by hand.'],
        ];
        $this->createVersion($article, $userBlocks);
        $this->createVersion($summary, [
            ['block_key' => 'block-0001', 'position' => 0, 'markdown' => '# Sammendrag'],
        ]);

        Artisan::call('wiki:repair-article-summary-links', ['--run-id' => $run->id, '--apply' => true]);

        $current = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)->where('is_current', true)->first();

        $this->assertSame('# User Edited Article', $current->content_blocks_json[0]['markdown']);
        $this->assertSame(
            'A paragraph the user carefully wrote and edited by hand.',
            $current->content_blocks_json[1]['markdown'],
        );
        $this->assertCount(3, $current->content_blocks_json);
        $this->assertStringContainsString('[['.$summary->slug.'|', $current->content_blocks_json[2]['markdown']);
    }

    public function test_user_written_link_with_custom_anchor_text_is_not_touched(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRunApplied($customer, $document);
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel Med Egen Lenke');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag');
        $this->addPageToRun($run, $article);
        $this->addPageToRun($run, $summary);

        $customLinkText = "# Artikkel Med Egen Lenke\n\nFor et raskt overblikk, [[{$summary->slug}|klikk her]].";
        $this->createVersion($article, [
            ['block_key' => 'block-0001', 'position' => 0, 'markdown' => $customLinkText],
        ]);
        $this->createVersion($summary, [
            ['block_key' => 'block-0001', 'position' => 0, 'markdown' => "# Sammendrag\n\n[[{$article->slug}|full artikkel]]"],
        ]);

        $versionsBefore = EnterpriseWikiPageVersion::query()->count();

        Artisan::call('wiki:repair-article-summary-links', ['--run-id' => $run->id, '--apply' => true]);

        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());
        $this->assertSame($customLinkText, $this->currentMarkdown($article));
    }

    // =========================================================================
    // Ambiguous pairs are never touched automatically
    // =========================================================================

    public function test_ambiguous_pair_with_two_articles_is_not_changed(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run = $this->createRunApplied($customer, $document);
        $articleA = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel A');
        $articleB = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel B');
        $summary = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag');
        $versionsBefore = EnterpriseWikiPageVersion::query()->count();

        Artisan::call('wiki:repair-article-summary-links', ['--run-id' => $run->id, '--apply' => true]);

        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());
        $this->assertFalse(EnterpriseWikiPageLink::query()->where('from_page_id', $articleA->id)->exists());
        $this->assertFalse(EnterpriseWikiPageLink::query()->where('from_page_id', $articleB->id)->exists());
        $this->assertFalse(EnterpriseWikiPageLink::query()->where('from_page_id', $summary->id)->exists());
    }

    public function test_page_with_conflicting_existing_structural_link_is_not_changed(): void
    {
        // article already structurally linked to a DIFFERENT summary (e.g. reused across runs) —
        // must never be silently repointed to this run's summary.
        $customer = $this->createCustomer();
        [$run, $article, $summary] = $this->createUnlinkedPair($customer);
        $otherSummary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Annet sammendrag');
        $this->createVersion($otherSummary, [['block_key' => 'block-0001', 'position' => 0, 'markdown' => '# Annet sammendrag']]);

        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $article->id,
            'to_page_id' => $otherSummary->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);

        $versionsBefore = EnterpriseWikiPageVersion::query()->count();

        Artisan::call('wiki:repair-article-summary-links', ['--run-id' => $run->id, '--apply' => true]);

        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());
        $this->assertFalse($this->linkExists($customer, $article, $summary, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY));
    }

    // =========================================================================
    // Idempotent
    // =========================================================================

    public function test_repair_is_idempotent(): void
    {
        $customer = $this->createCustomer();
        [$run] = $this->createUnlinkedPair($customer);

        Artisan::call('wiki:repair-article-summary-links', ['--run-id' => $run->id, '--apply' => true]);
        $versionsAfterFirst = EnterpriseWikiPageVersion::query()->count();
        $linksAfterFirst = EnterpriseWikiPageLink::query()->count();

        Artisan::call('wiki:repair-article-summary-links', ['--run-id' => $run->id, '--apply' => true]);

        $this->assertSame($versionsAfterFirst, EnterpriseWikiPageVersion::query()->count());
        $this->assertSame($linksAfterFirst, EnterpriseWikiPageLink::query()->count());
    }

    // =========================================================================
    // Customer isolation
    // =========================================================================

    public function test_other_customers_are_not_affected(): void
    {
        $customerA = $this->createCustomer('Customer A');
        $customerB = $this->createCustomer('Customer B');
        [$runA] = $this->createUnlinkedPair($customerA);
        [, $articleB, $summaryB] = $this->createUnlinkedPair($customerB);

        Artisan::call('wiki:repair-article-summary-links', ['--run-id' => $runA->id, '--apply' => true]);

        $this->assertStringNotContainsString('[[', $this->currentMarkdown($articleB));
        $this->assertFalse(EnterpriseWikiPageLink::query()->where('customer_id', $customerB->id)->exists());
    }

    public function test_sweeping_all_runs_still_respects_customer_scoping(): void
    {
        $customerA = $this->createCustomer('Customer A Sweep');
        $customerB = $this->createCustomer('Customer B Sweep');
        [, $articleA, $summaryA] = $this->createUnlinkedPair($customerA);
        [, $articleB, $summaryB] = $this->createUnlinkedPair($customerB);

        Artisan::call('wiki:repair-article-summary-links', ['--apply' => true]);

        $this->assertStringContainsString('[['.$summaryA->slug.'|', $this->currentMarkdown($articleA));
        $this->assertStringContainsString('[['.$summaryB->slug.'|', $this->currentMarkdown($articleB));
        $this->assertTrue($this->linkExists($customerA, $articleA, $summaryA, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY));
        $this->assertTrue($this->linkExists($customerB, $articleB, $summaryB, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY));
    }

    // =========================================================================
    // QA findings resolve after repair
    // =========================================================================

    public function test_qa_finding_is_resolved_after_repair(): void
    {
        $customer = $this->createCustomer();
        [$run, $article, $summary] = $this->createUnlinkedPair($customer);

        app(EnterpriseWikiAppliedRunLintService::class)->lint($run);

        $this->assertTrue(
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $article->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_ARTICLE_WITHOUT_SUMMARY_LINK)
                ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
                ->exists(),
        );
        $this->assertTrue(
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $summary->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_SUMMARY_WITHOUT_ARTICLE_LINK)
                ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
                ->exists(),
        );

        Artisan::call('wiki:repair-article-summary-links', ['--run-id' => $run->id, '--apply' => true]);

        $this->assertFalse(
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $article->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_ARTICLE_WITHOUT_SUMMARY_LINK)
                ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
                ->exists(),
        );
        $this->assertFalse(
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $summary->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_SUMMARY_WITHOUT_ARTICLE_LINK)
                ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
                ->exists(),
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Article Summary Repair AS'): Customer
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
            'extracted_text' => 'Source text for article/summary link repair tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createRunApplied(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function addPageToRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function createVersion(EnterpriseWikiPage $page, array $blocks): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => implode("\n\n", array_column($blocks, 'markdown')),
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'gpt-5',
        ]);
    }

    private function createVersionedPage(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        string $pageType,
        string $title,
    ): EnterpriseWikiPage {
        $page = $this->createPage($customer, $pageType, $title);
        $this->addPageToRun($run, $page);
        $this->createVersion($page, [
            ['block_key' => 'block-0001', 'position' => 0, 'markdown' => "# {$title}\n\nContent."],
        ]);

        return $page;
    }

    /**
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPage}
     */
    private function createUnlinkedPair(Customer $customer): array
    {
        $document = $this->createDocument($customer);
        $run = $this->createRunApplied($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Test Artikkel '.Str::random(4));
        $summary = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Test Sammendrag '.Str::random(4));

        return [$run, $article, $summary];
    }

    /**
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPage}
     */
    private function createLinkedPair(Customer $customer): array
    {
        [$run, $article, $summary] = $this->createUnlinkedPair($customer);

        Artisan::call('wiki:repair-article-summary-links', ['--run-id' => $run->id, '--apply' => true]);

        return [$run, $article->fresh(), $summary->fresh()];
    }

    private function currentMarkdown(EnterpriseWikiPage $page): string
    {
        return (string) EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->value('content_markdown');
    }

    private function linkExists(
        Customer $customer,
        EnterpriseWikiPage $from,
        EnterpriseWikiPage $to,
        string $linkType,
    ): bool {
        return EnterpriseWikiPageLink::query()
            ->where('customer_id', $customer->id)
            ->where('from_page_id', $from->id)
            ->where('to_page_id', $to->id)
            ->where('link_type', $linkType)
            ->exists();
    }
}
