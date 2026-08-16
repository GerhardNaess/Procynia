<?php

namespace Tests\Feature\App\Wiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Services\Ai\Wiki\RequirementWikiCatalogBuilder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * What "approved current Wiki knowledge" means for a bid answer.
 *
 * The ingest flow ends at run status 'awaiting_document_owner_approval' and never advances
 * enterprise_wiki_pages.status past 'draft', so page status cannot be the approval signal. The
 * authoritative one is document-owner sign-off on the page's CURRENT version — version-scoped, so
 * regenerating a page drops it back out until the owners sign the new version.
 */
class RequirementWikiApprovedKnowledgeContractTest extends TestCase
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

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    /** The requirement-answer catalog: current knowledge statuses plus the sign-off gate. */
    private function answerCatalog(int $customerId): array
    {
        return app(RequirementWikiCatalogBuilder::class)->build(
            $customerId,
            RequirementWikiCatalogBuilder::CURRENT_KNOWLEDGE_STATUSES,
            true,
        );
    }

    public function test_a_page_whose_current_version_is_signed_off_is_available_to_requirement_answers(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Samhandlings- og styringsmodell', 'Innhold om samhandling.', [
            // Exactly the state the real Wiki is in: never submitted for the legacy page review.
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
        ]);
        $this->approveWikiPageVersionAsDocumentOwner($this->currentVersion($page));

        $catalog = $this->answerCatalog($customer->id);

        $this->assertCount(1, $catalog);
        $this->assertSame('Samhandlings- og styringsmodell', $catalog[0]['title']);
    }

    public function test_a_page_nobody_has_signed_off_is_kept_out(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Ansvarsmatrise', 'Innhold.', [
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
        ], withDocumentOwnerApproval: false);
        $this->approveWikiPageVersionAsDocumentOwner($this->currentVersion($page), EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING);

        $this->assertSame([], $this->answerCatalog($customer->id));
    }

    public function test_one_rejected_owner_keeps_the_page_out_even_when_another_approved(): void
    {
        $customer = $this->createWikiCustomer();
        // The fixture signs off one owner; a second owner on the same version rejects.
        $page = $this->createWikiPageWithVersion($customer, 'Delt side', 'Innhold fra to dokumenter.');
        $this->approveWikiPageVersionAsDocumentOwner(
            $this->currentVersion($page),
            EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_REJECTED,
        );

        $this->assertSame([], $this->answerCatalog($customer->id));
    }

    public function test_a_version_with_no_approval_requirement_is_not_approved_by_default(): void
    {
        $customer = $this->createWikiCustomer();
        // page.status = approved (legacy lifecycle) but no owner has ever signed the version.
        $this->createWikiPageWithVersion($customer, 'Gammel godkjent side', 'Innhold.', [
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
        ], withDocumentOwnerApproval: false);

        $this->assertSame([], $this->answerCatalog($customer->id));
    }

    public function test_sign_off_on_an_older_version_does_not_carry_over_to_a_regenerated_page(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Single Point of Contact (SPOC)', 'Første versjon.');
        $oldVersion = $this->currentVersion($page);

        $this->assertCount(1, $this->answerCatalog($customer->id));

        // A later run regenerates the page: version 2 becomes current, unapproved.
        $oldVersion->forceFill(['is_current' => false])->save();
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => 'Andre versjon med nytt innhold.',
        ]);

        $this->assertSame(
            [],
            $this->answerCatalog($customer->id),
            'Approval belongs to the version that was read, never to the page.',
        );
    }

    public function test_a_rejected_or_archived_page_stays_out_even_when_signed_off(): void
    {
        $customer = $this->createWikiCustomer();

        foreach ([EnterpriseWikiPage::STATUS_REJECTED, EnterpriseWikiPage::STATUS_ARCHIVED, EnterpriseWikiPage::STATUS_SUPERSEDED] as $status) {
            $page = $this->createWikiPageWithVersion($customer, 'Side '.$status, 'Innhold.', ['status' => $status]);
            $this->approveWikiPageVersionAsDocumentOwner($this->currentVersion($page));
        }

        $this->assertSame([], $this->answerCatalog($customer->id));
    }

    public function test_another_customers_signed_off_page_is_never_visible(): void
    {
        $customerA = $this->createWikiCustomer('Kunde A');
        $customerB = $this->createWikiCustomer('Kunde B');
        $page = $this->createWikiPageWithVersion($customerA, 'Samhandling', 'Innhold.');
        $this->approveWikiPageVersionAsDocumentOwner($this->currentVersion($page));

        $this->assertCount(1, $this->answerCatalog($customerA->id));
        $this->assertSame([], $this->answerCatalog($customerB->id));
    }

    public function test_ask_wiki_semantics_are_unchanged_by_the_sign_off_gate(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Ikke signert side', 'Innhold.', [
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
        ], withDocumentOwnerApproval: false);
        $this->approveWikiPageVersionAsDocumentOwner($this->currentVersion($page), EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING);

        // "Spør Wiki" passes the reader's own visible statuses and no approval requirement — an
        // exploratory read model, deliberately wider than what a bid answer may cite.
        $askWikiCatalog = app(RequirementWikiCatalogBuilder::class)->build($customer->id, [
            EnterpriseWikiPage::STATUS_DRAFT,
            EnterpriseWikiPage::STATUS_PENDING_REVIEW,
            EnterpriseWikiPage::STATUS_APPROVED,
        ]);

        $this->assertCount(1, $askWikiCatalog);
        $this->assertSame([], $this->answerCatalog($customer->id));
    }
}
