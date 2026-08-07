<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\EnterpriseWiki\EnterpriseWikiPageVersionWriter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * Covers the database invariants and the standardized write path added to close the Wiki
 * page-version race (duplicate version_number / multiple is_current rows possible when two
 * writers touched the same page without a shared lock). See
 * App\Services\EnterpriseWiki\EnterpriseWikiPageVersionWriter and the
 * add_authoritative_version_constraints_to_enterprise_wiki_page_versions_table migration.
 *
 * True cross-connection concurrency (does the page lock actually block a second writer?) is
 * covered separately in EnterpriseWikiPageVersionConcurrencyTest, which needs a second real
 * Postgres connection and therefore can't share this file's RefreshDatabase-wrapped transaction.
 */
class EnterpriseWikiPageVersionIntegrityTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    public function test_duplicate_version_number_for_the_same_page_is_rejected_by_the_database(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Constraint Page', 'v1');

        $this->expectException(QueryException::class);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => false,
            'content_markdown' => 'duplicate version_number',
        ]);
    }

    public function test_a_second_current_version_for_the_same_page_is_rejected_by_the_database(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Constraint Page', 'v1');

        $this->expectException(QueryException::class);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => 'second current version',
        ]);
    }

    public function test_a_second_current_version_for_a_different_page_is_allowed(): void
    {
        $customer = $this->createWikiCustomer();
        $pageOne = $this->createWikiPageWithVersion($customer, 'Page One', 'v1');
        $pageTwo = $this->createWikiPageWithVersion($customer, 'Page Two', 'v1');

        $this->assertTrue(
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $pageOne->id)->where('is_current', true)->exists()
        );
        $this->assertTrue(
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $pageTwo->id)->where('is_current', true)->exists()
        );
    }

    public function test_writer_creates_sequential_current_versions_and_demotes_the_previous_one(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Sequential Page', 'v1');
        $v1 = $this->currentVersion($page);

        $v2 = $this->writer()->writeNewCurrentVersion($page, ['content_markdown' => 'v2']);
        $v3 = $this->writer()->writeNewCurrentVersion($page, ['content_markdown' => 'v3']);

        $this->assertSame(2, $v2->version_number);
        $this->assertSame(3, $v3->version_number);
        $this->assertTrue((bool) $v3->refresh()->is_current);

        $this->assertFalse((bool) $v1->refresh()->is_current);
        $this->assertFalse((bool) $v2->refresh()->is_current);

        $this->assertSame(
            1,
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->where('is_current', true)->count(),
        );
    }

    public function test_writer_non_current_version_does_not_demote_the_existing_current_version(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Staged Page', 'v1');
        $v1 = $this->currentVersion($page);

        $staged = $this->writer()->writeNonCurrentVersion($page, [
            'content_markdown' => 'staged edit',
            'is_staged' => true,
        ]);

        $this->assertSame(2, $staged->version_number);
        $this->assertFalse((bool) $staged->is_current);
        $this->assertTrue((bool) $v1->refresh()->is_current);
    }

    public function test_writer_promotes_a_staged_version_and_demotes_the_previous_current(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Promote Page', 'v1');
        $v1 = $this->currentVersion($page);

        $staged = $this->writer()->writeNonCurrentVersion($page, [
            'content_markdown' => 'staged edit',
            'is_staged' => true,
        ]);

        $promoted = $this->writer()->promoteToCurrent($page, $staged);

        $this->assertTrue((bool) $promoted->is_current);
        $this->assertFalse((bool) $v1->refresh()->is_current);
        $this->assertSame(
            1,
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->where('is_current', true)->count(),
        );
    }

    /**
     * The version contract: an older run's generated_page_version_id must never be silently
     * moved by a later repair that creates a new current version for the same page — the pointer
     * stays bound to the exact version that run actually produced, while the page's current
     * version moves on independently. Run-detail views must keep reading the run's own pointer,
     * not is_current.
     */
    public function test_an_older_runs_generated_page_version_id_is_unaffected_by_a_later_repair(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Contract Page', 'v1 by run A');
        $v1 = $this->currentVersion($page);

        $runA = $this->createAppliedRun($customer);
        $pivotA = EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $runA->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generated_page_version_id' => $v1->id,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        // A later repair (on behalf of a different run/maintenance job) creates a new current
        // version for the same page — pivotA's pointer must not move.
        $v2 = $this->writer()->writeNewCurrentVersion($page, ['content_markdown' => 'v2 via repair']);

        $pivotA->refresh();

        $this->assertSame($v1->id, $pivotA->generated_page_version_id);
        $this->assertNotSame($v2->id, $pivotA->generated_page_version_id);

        $currentVersion = $page->currentVersion()->first();
        $this->assertSame($v2->id, $currentVersion->id);
        $this->assertNotSame($pivotA->generated_page_version_id, $currentVersion->id);
    }

    private function writer(): EnterpriseWikiPageVersionWriter
    {
        return app(EnterpriseWikiPageVersionWriter::class);
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    private function createAppliedRun(Customer $customer): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => 0,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
        ]);
    }
}
