<?php

namespace Tests\Unit\Services\Ai;

use App\Models\EnterpriseWikiPage;
use App\Services\Ai\Wiki\RequirementWikiCatalogBuilder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

class RequirementWikiCatalogBuilderTest extends TestCase
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

    public function test_only_approved_pages_from_the_given_customer_are_included(): void
    {
        $customer = $this->createWikiCustomer();
        $this->createWikiPageWithVersion($customer, 'Godkjent side', 'Innhold i den godkjente siden.');

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);

        $this->assertCount(1, $catalog);
        $this->assertSame('Godkjent side', $catalog[0]['title']);
    }

    public function test_pages_with_nothing_published_are_excluded(): void
    {
        // Draft status alone no longer decides: a page being revised can still have a published
        // version worth answering from. Having published nothing is what keeps it out.
        $customer = $this->createWikiCustomer();
        $this->createWikiPageWithVersion(
            $customer,
            'Kladd',
            'Innhold i kladden.',
            ['status' => EnterpriseWikiPage::STATUS_DRAFT],
            withDocumentOwnerApproval: false,
        );

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);

        $this->assertSame([], $catalog);
    }

    public function test_pages_from_another_customer_are_excluded(): void
    {
        $customerA = $this->createWikiCustomer('Customer A');
        $customerB = $this->createWikiCustomer('Customer B');
        $this->createWikiPageWithVersion($customerB, 'Side hos B', 'Innhold hos kunde B.');

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customerA->id);

        $this->assertSame([], $catalog);
    }

    public function test_pages_without_a_current_version_are_excluded(): void
    {
        $customer = $this->createWikiCustomer();
        $this->createWikiPage($customer, 'Side uten versjon');

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);

        $this->assertSame([], $catalog);
    }

    public function test_pages_with_empty_content_markdown_are_excluded(): void
    {
        $customer = $this->createWikiCustomer();
        $this->createWikiPageWithVersion($customer, 'Tom side', '   ');

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);

        $this->assertSame([], $catalog);
    }

    public function test_headings_and_excerpt_are_extracted(): void
    {
        $customer = $this->createWikiCustomer();
        $this->createWikiPageWithVersion(
            $customer,
            'Prosessdokument',
            "# Prosessdokument\n\nDette er innledningen som beskriver formålet med prosessen.\n\n## Første del\n\nInnhold i første del.\n\n## Andre del\n\nInnhold i andre del.",
        );

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);

        $this->assertSame(['Prosessdokument', 'Første del', 'Andre del'], $catalog[0]['headings']);
        $this->assertStringContainsString('Dette er innledningen', $catalog[0]['excerpt']);
    }

    public function test_outgoing_and_backlink_counts_are_correct(): void
    {
        $customer = $this->createWikiCustomer();
        $pageA = $this->createWikiPageWithVersion($customer, 'Side A', 'Innhold A som lenker til [[side-b]] og [[side-c]].');
        $pageB = $this->createWikiPageWithVersion($customer, 'Side B', 'Innhold B.');
        $pageC = $this->createWikiPageWithVersion($customer, 'Side C', 'Innhold C.');
        $this->createWikilink($customer, $pageA, $pageB);
        $this->createWikilink($customer, $pageA, $pageC);
        $this->createWikilink($customer, $pageB, $pageA);

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);
        $byId = collect($catalog)->keyBy('page_id');

        $this->assertSame(2, $byId[$pageA->id]['outgoing_link_count']);
        $this->assertSame(1, $byId[$pageA->id]['backlink_count']);
        $this->assertSame(1, $byId[$pageB->id]['outgoing_link_count']);
        $this->assertSame(1, $byId[$pageB->id]['backlink_count']);
    }

    public function test_content_markdown_is_carried_for_downstream_ranking_and_reading(): void
    {
        $customer = $this->createWikiCustomer();
        $this->createWikiPageWithVersion($customer, 'Side', 'Fullstendig innhold på siden.');

        $catalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id);

        $this->assertStringContainsString('Fullstendig innhold', $catalog[0]['content_markdown']);
    }
}
