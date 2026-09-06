<?php

namespace Tests\Feature\App\Wiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\Ai\Wiki\RequirementWikiCatalogBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * What "approved Wiki knowledge" means for a bid answer.
 *
 * It used to mean document-owner sign-off on the page's CURRENT version. That had two consequences
 * nobody wanted: a page dropped out of the catalog the moment a run regenerated it, and the same
 * gate was being re-evaluated forever against work in progress.
 *
 * The rule is now one term: `enterprise_wiki_pages.published_version_id`. Sign-off is what permits a
 * version to be published; publication is what makes it knowledge. Once a version is published the
 * decision stands, and later work on the page cannot retroactively withdraw it.
 *
 * Spør Wiki and requirement answers now share this rule. There is no wider exploratory read model:
 * being allowed to READ a draft page is not the same as having the AI present it as documented fact.
 */
class RequirementWikiApprovedKnowledgeContractTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use DatabaseTransactions;

    /** @return list<array<string, mixed>> */
    private function catalog(int $customerId): array
    {
        return app(RequirementWikiCatalogBuilder::class)->build($customerId);
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    public function test_a_published_page_is_available_to_requirement_answers(): void
    {
        $customer = $this->createWikiCustomer();
        $this->createWikiPageWithVersion($customer, 'Samhandlingsmodell', 'Innhold om samhandling.');

        $this->assertCount(1, $this->catalog($customer->id));
    }

    public function test_a_page_that_was_never_published_is_kept_out(): void
    {
        $customer = $this->createWikiCustomer();
        $this->createWikiPageWithVersion(
            $customer,
            'Uferdig side',
            'Innhold.',
            withDocumentOwnerApproval: false,
        );

        $this->assertSame([], $this->catalog($customer->id));
    }

    public function test_signing_off_without_publishing_is_not_enough(): void
    {
        // Sign-off permits publication; it is not publication. A version the document owners have
        // cleared but nobody approved at page level is still not knowledge.
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Klarert men upublisert', 'Innhold.');
        $this->approveWikiPageVersionAsDocumentOwner($this->currentVersion($page));
        $page->forceFill(['published_version_id' => null])->save();

        $this->assertSame([], $this->catalog($customer->id));
    }

    public function test_regenerating_a_page_does_not_withdraw_the_published_version(): void
    {
        // The opposite of the old contract, and the point of the change: a page under revision keeps
        // answering from what was approved, instead of falling silent until the new work is done.
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Single Point of Contact (SPOC)', 'Første versjon.');
        $published = $this->currentVersion($page);

        $published->forceFill(['is_current' => false])->save();
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => 'Andre versjon med nytt innhold.',
        ]);

        $catalog = $this->catalog($customer->id);

        $this->assertCount(1, $catalog);
        $this->assertStringContainsString('Første versjon.', $catalog[0]['content_markdown']);
        $this->assertStringNotContainsString('Andre versjon', $catalog[0]['content_markdown']);
        $this->assertSame($published->id, (int) $page->fresh()->published_version_id);
    }

    public function test_publishing_the_regenerated_version_switches_the_answer_source(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'SPOC', 'Første versjon.');
        $this->currentVersion($page)->forceFill(['is_current' => false])->save();

        $second = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => 'Andre versjon med nytt innhold.',
        ]);
        $page->forceFill(['published_version_id' => $second->id])->save();

        $catalog = $this->catalog($customer->id);

        $this->assertCount(1, $catalog, 'one page, one entry — never both versions');
        $this->assertStringContainsString('Andre versjon', $catalog[0]['content_markdown']);
    }

    public function test_a_page_being_revised_still_answers_from_its_published_version(): void
    {
        $customer = $this->createWikiCustomer();

        foreach ([EnterpriseWikiPage::STATUS_DRAFT, EnterpriseWikiPage::STATUS_PENDING_REVIEW, EnterpriseWikiPage::STATUS_REJECTED] as $status) {
            $page = $this->createWikiPageWithVersion($customer, 'Side '.$status, 'Innhold.');
            $page->forceFill(['status' => $status])->save();
        }

        $this->assertCount(3, $this->catalog($customer->id), 'status describes the working version, not the published one');
    }

    public function test_archived_and_superseded_pages_stay_out_even_when_published(): void
    {
        // Retiring a page is a different act from revising it.
        $customer = $this->createWikiCustomer();

        foreach ([EnterpriseWikiPage::STATUS_ARCHIVED, EnterpriseWikiPage::STATUS_SUPERSEDED] as $status) {
            $page = $this->createWikiPageWithVersion($customer, 'Side '.$status, 'Innhold.');
            $page->forceFill(['status' => $status])->save();
        }

        $this->assertSame([], $this->catalog($customer->id));
    }

    public function test_another_customers_published_page_is_never_visible(): void
    {
        $customerA = $this->createWikiCustomer('Kunde A');
        $customerB = $this->createWikiCustomer('Kunde B');
        $this->createWikiPageWithVersion($customerA, 'Samhandling', 'Innhold.');

        $this->assertCount(1, $this->catalog($customerA->id));
        $this->assertSame([], $this->catalog($customerB->id));
    }

    public function test_ask_wiki_and_requirement_answers_share_one_rule(): void
    {
        // Previously Spør Wiki could ground answers in a draft page the reader was allowed to open.
        // Reading unreviewed content is one thing; having the AI present it as documented fact is
        // another, and the catalog can no longer be widened to allow it.
        $customer = $this->createWikiCustomer();
        $this->createWikiPageWithVersion(
            $customer,
            'Ikke publisert side',
            'Innhold.',
            ['status' => EnterpriseWikiPage::STATUS_DRAFT],
            withDocumentOwnerApproval: false,
        );

        $this->assertSame([], $this->catalog($customer->id));
    }
}
