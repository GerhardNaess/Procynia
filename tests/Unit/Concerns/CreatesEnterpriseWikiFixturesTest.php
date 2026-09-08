<?php

namespace Tests\Unit\Concerns;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * The fixture builders decide, for most of the Wiki suite, whether a page counts as knowledge the
 * AI may present as documented fact. They used to publish everything by default, so a test could
 * assert "retrieval found this page" without ever setting up a publication flow — green for a
 * reason the test never stated. These are the guarantees that keep that from coming back.
 */
class CreatesEnterpriseWikiFixturesTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    public function test_the_default_page_is_an_unpublished_draft_with_a_working_version(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Arbeidsside', 'Innhold under arbeid.');

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status);
        $this->assertNull(
            $page->published_version_id,
            'The default fixture must never publish — publication is what makes a page answerable.',
        );

        $version = $this->currentVersion($page);
        $this->assertSame(1, (int) $version->version_number);
        $this->assertTrue((bool) $version->is_current);
        $this->assertSame('Innhold under arbeid.', $version->content_markdown);

        $this->assertSame(
            0,
            EnterpriseWikiPageVersionDocumentOwnerApproval::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->count(),
            'The default fixture must not sign anything off on the document owner’s behalf.',
        );
    }

    public function test_a_page_without_a_version_is_also_left_unpublished(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPage($customer, 'Side uten versjon');

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status);
        $this->assertNull($page->published_version_id);
        $this->assertSame(
            0,
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->count(),
        );
    }

    public function test_the_published_helper_publishes_the_current_version(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createPublishedWikiPage($customer, 'Publisert side', 'Dokumentert innhold.');

        $version = $this->currentVersion($page);

        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status);
        $this->assertSame(
            $version->id,
            (int) $page->published_version_id,
            'The publication pointer must name the current version — it is the whole retrieval rule.',
        );

        $approval = EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->firstOrFail();

        $this->assertSame(
            EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED,
            $approval->approval_status,
        );
        $this->assertNotNull($approval->decided_at);
        $this->assertSame($page->customer_id, $approval->customer_id);
    }

    public function test_publishing_leaves_an_explicit_page_status_alone(): void
    {
        // A page under revision keeps answering from what was approved, so "published" and "draft"
        // are not mutually exclusive — and the fixture must be able to express that.
        $customer = $this->createWikiCustomer();
        $page = $this->createPublishedWikiPage(
            $customer,
            'Side under revisjon',
            'Publisert innhold.',
            ['status' => EnterpriseWikiPage::STATUS_DRAFT],
        );

        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status);
        $this->assertSame($this->currentVersion($page)->id, (int) $page->published_version_id);
    }

    public function test_publishing_an_existing_draft_page_is_the_same_thing(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Senere publisert', 'Innhold.');

        $this->assertNull($page->published_version_id);

        $published = $this->publishWikiPage($page);

        $this->assertSame($this->currentVersion($page)->id, (int) $published->published_version_id);
    }
}
