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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * wiki:resync-wikilink-graph — rebuilds link_type=wikilink graph edges from a page's current
 * version content, mirroring the exact drift found for "Kunnskapsforvaltning (ITIL)" in run 39:
 * EnterpriseWikiClaimContentRepairService replaced a page's content (dropping an inline
 * [[wikilink]]) without ever calling materializeWikilinksForPage(), leaving a stale graph edge.
 */
class EnterpriseWikiResyncWikilinkGraphCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_fails_when_run_id_is_not_numeric(): void
    {
        $this->artisan('wiki:resync-wikilink-graph', ['--run-id' => 'abc'])
            ->expectsOutputToContain('must be numeric')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_run_not_found(): void
    {
        $this->artisan('wiki:resync-wikilink-graph', ['--run-id' => 99999])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    public function test_stale_wikilink_edge_is_removed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunApplied($customer, $this->createDocument($customer));
        $target = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Target');
        $source = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Source', "# Source\n\nNo link here anymore.");

        // Simulates the drift: a graph edge exists from an OLD version that DID link, but the
        // current version's content no longer contains the [[wikilink]].
        $this->createLink($customer, $source, $target);

        $this->artisan('wiki:resync-wikilink-graph', ['--run-id' => $run->id])
            ->expectsOutputToContain('Stale links removed (would be):      1')
            ->assertExitCode(0);

        $this->assertTrue($this->linkExists($customer, $source, $target));

        $this->artisan('wiki:resync-wikilink-graph', ['--run-id' => $run->id, '--apply' => true]);

        $this->assertFalse($this->linkExists($customer, $source, $target));
    }

    public function test_existing_correct_link_is_preserved(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunApplied($customer, $this->createDocument($customer));
        $target = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Target');
        $source = $this->createVersionedPage(
            $customer,
            $run,
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'Source',
            "# Source\n\nSee [[{$target->slug}|Target]] for details.",
        );

        $this->createLink($customer, $source, $target);

        $this->artisan('wiki:resync-wikilink-graph', ['--run-id' => $run->id, '--apply' => true]);

        $this->assertTrue($this->linkExists($customer, $source, $target));
    }

    public function test_missing_link_is_created(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunApplied($customer, $this->createDocument($customer));
        $target = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Target');
        $source = $this->createVersionedPage(
            $customer,
            $run,
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'Source',
            "# Source\n\nSee [[{$target->slug}|Target]] for details.",
        );

        $this->assertFalse($this->linkExists($customer, $source, $target));

        $this->artisan('wiki:resync-wikilink-graph', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Links created:            1');

        $this->assertTrue($this->linkExists($customer, $source, $target));
    }

    public function test_dry_run_does_not_persist_any_change(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunApplied($customer, $this->createDocument($customer));
        $target = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Target');
        $source = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Source', "# Source\n\nNo link.");
        $this->createLink($customer, $source, $target);

        $this->artisan('wiki:resync-wikilink-graph', ['--run-id' => $run->id]);

        $this->assertTrue($this->linkExists($customer, $source, $target));
    }

    public function test_resync_is_idempotent(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunApplied($customer, $this->createDocument($customer));
        $target = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Target');
        $source = $this->createVersionedPage(
            $customer,
            $run,
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'Source',
            "# Source\n\nSee [[{$target->slug}|Target]] for details.",
        );

        $this->artisan('wiki:resync-wikilink-graph', ['--run-id' => $run->id, '--apply' => true]);

        $this->artisan('wiki:resync-wikilink-graph', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Links created:            0')
            ->expectsOutputToContain('Stale links removed:      0');

        $this->assertSame(
            1,
            EnterpriseWikiPageLink::query()
                ->where('from_page_id', $source->id)
                ->where('to_page_id', $target->id)
                ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
                ->count(),
        );
    }

    public function test_qa_finding_is_resolved_after_resync(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunApplied($customer, $this->createDocument($customer));
        $target = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Target');
        $source = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Source', "# Source\n\nNo link here anymore.");
        $this->createLink($customer, $source, $target);

        app(EnterpriseWikiAppliedRunLintService::class)->lint($run->fresh());

        $finding = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_STALE_WIKILINK_GRAPH_EDGE)
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame(EnterpriseWikiLintFinding::STATUS_OPEN, $finding->status);

        $this->artisan('wiki:resync-wikilink-graph', ['--run-id' => $run->id, '--apply' => true]);

        $this->assertSame(EnterpriseWikiLintFinding::STATUS_RESOLVED, $finding->fresh()->status);
    }

    public function test_other_customers_are_not_affected(): void
    {
        $customerA = $this->createCustomer('Resync A');
        $customerB = $this->createCustomer('Resync B');

        $runA = $this->createRunApplied($customerA, $this->createDocument($customerA));
        $targetA = $this->createVersionedPage($customerA, $runA, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Target A');
        $sourceA = $this->createVersionedPage($customerA, $runA, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Source A', "# Source A\n\nNo link.");
        $this->createLink($customerA, $sourceA, $targetA);

        $runB = $this->createRunApplied($customerB, $this->createDocument($customerB));
        $targetB = $this->createVersionedPage($customerB, $runB, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Target B');
        $sourceB = $this->createVersionedPage($customerB, $runB, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Source B', "# Source B\n\nNo link.");
        $this->createLink($customerB, $sourceB, $targetB);

        $this->artisan('wiki:resync-wikilink-graph', ['--run-id' => $runA->id, '--apply' => true]);

        $this->assertFalse($this->linkExists($customerA, $sourceA, $targetA));
        $this->assertTrue($this->linkExists($customerB, $sourceB, $targetB));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Wikilink Resync AS'): Customer
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
            'extracted_text' => 'Source text for wikilink graph resync tests.',
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

    private function createVersionedPage(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        string $pageType,
        string $title,
        ?string $markdown = null,
    ): EnterpriseWikiPage {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown ?? "# {$title}\n\nContent.",
            'generated_by_model' => 'gpt-5',
        ]);

        return $page->refresh();
    }

    private function createLink(Customer $customer, EnterpriseWikiPage $from, EnterpriseWikiPage $to): EnterpriseWikiPageLink
    {
        return EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $from->id,
            'to_page_id' => $to->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);
    }

    private function linkExists(Customer $customer, EnterpriseWikiPage $from, EnterpriseWikiPage $to): bool
    {
        return EnterpriseWikiPageLink::query()
            ->where('customer_id', $customer->id)
            ->where('from_page_id', $from->id)
            ->where('to_page_id', $to->id)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->exists();
    }
}
