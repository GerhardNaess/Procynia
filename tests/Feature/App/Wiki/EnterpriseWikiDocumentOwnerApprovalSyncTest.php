<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentOwnerApprovalService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Document-owner approval rows are keyed by (version, owner, source_documents_hash), so an ordinary
 * lifecycle event — a document changing owner, or a second document joining the version — stops the
 * previous row being required without removing it. Sync was purely additive and left it behind.
 *
 * That orphan was indistinguishable from a live requirement. approvedCurrentVersionPageIds() demands
 * every row on the current version be approved, so a single orphaned PENDING row excluded the page
 * from approved knowledge permanently: it was no longer in the requirement set, so it never appeared
 * on any approval surface and nobody could ever decide it. That page then silently dropped out of
 * RequirementWikiCatalogBuilder, the Wiki grounding catalog.
 *
 * The fix marks such rows superseded rather than deleting them — the decision they record is real and
 * has to stay auditable. These tests pin the requirement set, the retirement, and the audit trail.
 */
class EnterpriseWikiDocumentOwnerApprovalSyncTest extends TestCase
{
    use DatabaseTransactions;

    private EnterpriseWikiDocumentOwnerApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EnterpriseWikiDocumentOwnerApprovalService::class);
    }

    // A. one document, one owner
    public function test_one_document_with_one_owner_produces_one_active_requirement(): void
    {
        [$customer, $version] = $this->versionWithDocuments($owners = 1);

        $this->service->syncForPageVersion($version);

        $active = $this->activeRows($version);
        $this->assertCount(1, $active);
        $this->assertSame([$customer->documents[0]->id], $active->first()->source_document_ids);
        $this->assertSame(
            EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING,
            $active->first()->approval_status,
        );
        $this->assertSame($owners, $this->allRows($version)->count());
    }

    // B. owner change X -> Y
    public function test_an_owner_change_retires_the_former_owners_requirement_and_creates_the_new_one(): void
    {
        [$customer, $version] = $this->versionWithDocuments(1);
        $document = $customer->documents[0];
        $ownerX = $document->owner_user_id;
        $ownerY = $this->user($customer);

        $this->service->syncForPageVersion($version);
        $this->approveActive($version);

        $document->forceFill(['owner_user_id' => $ownerY->id])->save();
        $this->service->syncForDocument($document->fresh());

        $active = $this->activeRows($version);
        $this->assertCount(1, $active, 'exactly one owner is required after the handover');
        $this->assertSame($ownerY->id, (int) $active->first()->document_owner_user_id);
        $this->assertSame(
            EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING,
            $active->first()->approval_status,
            'the new owner has not vouched for anything yet',
        );

        $former = $this->allRows($version)->firstWhere('document_owner_user_id', $ownerX);
        $this->assertNotNull($former->superseded_at, 'the former owner is no longer an active requirement');
    }

    // C. two documents, two owners
    public function test_two_documents_with_different_owners_produce_one_requirement_each(): void
    {
        [$customer, $version] = $this->versionWithDocuments(2);

        $this->service->syncForPageVersion($version);

        $active = $this->activeRows($version);
        $this->assertCount(2, $active);
        $this->assertEqualsCanonicalizing(
            $customer->documents->pluck('owner_user_id')->map(fn ($id) => (int) $id)->all(),
            $active->pluck('document_owner_user_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    // D. two documents, same owner
    public function test_two_documents_with_the_same_owner_collapse_into_one_requirement(): void
    {
        [$customer, $version] = $this->versionWithDocuments(2);
        $shared = $customer->documents[0]->owner_user_id;
        $customer->documents[1]->forceFill(['owner_user_id' => $shared])->save();

        $this->service->syncForPageVersion($version);

        $active = $this->activeRows($version);
        $this->assertCount(1, $active, 'one owner, one requirement — not one per document');
        $this->assertEqualsCanonicalizing(
            $customer->documents->pluck('id')->all(),
            $active->first()->source_document_ids,
            'the requirement covers both documents',
        );
    }

    // E. two owners consolidated onto one
    public function test_consolidating_both_documents_onto_one_owner_leaves_a_single_active_requirement(): void
    {
        [$customer, $version] = $this->versionWithDocuments(2);
        $this->service->syncForPageVersion($version);
        $this->assertCount(2, $this->activeRows($version));

        $keeper = (int) $customer->documents[0]->owner_user_id;
        $handedOver = $customer->documents[1];
        $formerOwner = (int) $handedOver->owner_user_id;
        $handedOver->forceFill(['owner_user_id' => $keeper])->save();
        $this->service->syncForDocument($handedOver->fresh());

        $active = $this->activeRows($version);
        $this->assertCount(1, $active);
        $this->assertSame($keeper, (int) $active->first()->document_owner_user_id);
        $this->assertEqualsCanonicalizing(
            $customer->documents->pluck('id')->all(),
            $active->first()->source_document_ids,
            'source_document_ids is the union of both documents',
        );

        $this->assertNotContains(
            $formerOwner,
            $active->pluck('document_owner_user_id')->map(fn ($id) => (int) $id)->all(),
            'the former owner is not still a required approver',
        );
    }

    // F. a document stops contributing
    public function test_a_document_that_no_longer_contributes_stops_being_an_active_requirement(): void
    {
        [$customer, $version] = $this->versionWithDocuments(2);
        $this->service->syncForPageVersion($version);
        $this->assertCount(2, $this->activeRows($version));

        $dropped = $customer->documents[1];
        EnterpriseWikiSourceReference::query()
            ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->where('source_id', $dropped->id)
            ->delete();

        $this->service->syncForPageVersion($version->fresh());

        $active = $this->activeRows($version);
        $this->assertCount(1, $active);
        $this->assertSame((int) $customer->documents[0]->owner_user_id, (int) $active->first()->document_owner_user_id);
    }

    // G. no duplicates, no stale active rows, and repeated syncs are stable
    public function test_repeated_syncs_never_duplicate_or_revive_stale_requirements(): void
    {
        [$customer, $version] = $this->versionWithDocuments(2);

        for ($i = 0; $i < 3; $i++) {
            $this->service->syncForPageVersion($version->fresh());
        }

        $this->assertCount(2, $this->activeRows($version));
        $this->assertCount(2, $this->allRows($version), 'no duplicate rows are written');

        $handedOver = $customer->documents[1];
        $handedOver->forceFill(['owner_user_id' => $customer->documents[0]->owner_user_id])->save();

        for ($i = 0; $i < 3; $i++) {
            $this->service->syncForDocument($handedOver->fresh());
        }

        $this->assertCount(1, $this->activeRows($version), 'a stale requirement never comes back');
    }

    // H. what happens to an approval when ownership moves
    public function test_a_former_owners_approval_is_kept_as_history_but_no_longer_counts(): void
    {
        [$customer, $version] = $this->versionWithDocuments(1);
        $document = $customer->documents[0];
        $ownerX = (int) $document->owner_user_id;

        $this->service->syncForPageVersion($version);
        $this->approveActive($version);
        $this->assertContains(
            $version->enterprise_wiki_page_id,
            $this->service->approvedCurrentVersionPageIds($customer->id),
        );

        $document->forceFill(['owner_user_id' => $this->user($customer)->id])->save();
        $this->service->syncForDocument($document->fresh());

        $former = $this->allRows($version)->firstWhere('document_owner_user_id', $ownerX);
        $this->assertSame(
            EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED,
            $former->approval_status,
            'the decision that was actually made is preserved',
        );
        $this->assertNotNull($former->decided_at);
        $this->assertNotNull($former->superseded_at, 'but it is retired as a requirement');

        $this->assertNotContains(
            $version->enterprise_wiki_page_id,
            $this->service->approvedCurrentVersionPageIds($customer->id),
            'the page is no longer approved on the strength of the former owner alone',
        );
    }

    public function test_a_superseded_row_can_no_longer_be_decided_by_anyone(): void
    {
        [$customer, $version] = $this->versionWithDocuments(1);
        $document = $customer->documents[0];
        $ownerX = User::query()->findOrFail($document->owner_user_id);

        $this->service->syncForPageVersion($version);
        $document->forceFill(['owner_user_id' => $this->user($customer)->id])->save();
        $this->service->syncForDocument($document->fresh());

        $stale = $this->allRows($version)->firstWhere('document_owner_user_id', $ownerX->id);
        $systemOwner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->assertFalse($this->service->canDecide($stale, $ownerX), 'the former owner cannot decide it');
        $this->assertFalse($this->service->canDecide($stale, $systemOwner), 'nor can a System Owner');
    }

    /**
     * The regression this whole change exists for: with every currently required approval granted,
     * the page must count as approved knowledge. Before the fix an orphaned pending row blocked it
     * for good, and no user action could clear it.
     */
    public function test_granting_every_current_requirement_is_enough_to_approve_the_page(): void
    {
        [$customer, $version] = $this->versionWithDocuments(1);
        $document = $customer->documents[0];

        $this->service->syncForPageVersion($version);
        $this->approveActive($version);

        // Hand the document over, then add a second document — both orphan a row.
        $ownerY = $this->user($customer);
        $document->forceFill(['owner_user_id' => $ownerY->id])->save();
        $this->service->syncForDocument($document->fresh());

        $second = $this->document($customer, $ownerY, 'b.docx');
        $this->sourceReference($this->claim($version, 'Påstand B.'), $second);
        $this->service->syncForPageVersion($version->fresh());

        $this->approveActive($version);

        $this->assertContains(
            $version->enterprise_wiki_page_id,
            $this->service->approvedCurrentVersionPageIds($customer->id),
            'every live requirement is granted, so the page is approved knowledge',
        );
    }

    public function test_a_requirement_that_becomes_relevant_again_reuses_its_row(): void
    {
        [$customer, $version] = $this->versionWithDocuments(1);
        $document = $customer->documents[0];
        $ownerX = (int) $document->owner_user_id;
        $ownerY = $this->user($customer);

        $this->service->syncForPageVersion($version);
        $document->forceFill(['owner_user_id' => $ownerY->id])->save();
        $this->service->syncForDocument($document->fresh());

        // Handed back.
        $document->forceFill(['owner_user_id' => $ownerX])->save();
        $this->service->syncForDocument($document->fresh());

        $active = $this->activeRows($version);
        $this->assertCount(1, $active);
        $this->assertSame($ownerX, (int) $active->first()->document_owner_user_id);
        $this->assertCount(2, $this->allRows($version), 'the original row is reused, not duplicated');
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * A current page version supported by `$documentCount` documents, each with its own owner.
     * The documents are exposed on the customer as `->documents` for convenience.
     *
     * @return array{0: Customer, 1: EnterpriseWikiPageVersion}
     */
    private function versionWithDocuments(int $documentCount): array
    {
        $customer = $this->customer();
        $page = $this->page($customer);
        $version = $this->version($page);

        $documents = collect(range(1, $documentCount))->map(function (int $index) use ($customer, $version): EnterpriseWikiDocument {
            $document = $this->document($customer, $this->user($customer), "kilde-{$index}.docx");
            $this->sourceReference($this->claim($version, "Påstand {$index}."), $document);

            return $document;
        });

        $customer->setRelation('documents', $documents);

        return [$customer, $version];
    }

    /** @return EloquentCollection<int, EnterpriseWikiPageVersionDocumentOwnerApproval> */
    private function allRows(EnterpriseWikiPageVersion $version): EloquentCollection
    {
        return EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->orderBy('id')
            ->get();
    }

    /** @return EloquentCollection<int, EnterpriseWikiPageVersionDocumentOwnerApproval> */
    private function activeRows(EnterpriseWikiPageVersion $version): EloquentCollection
    {
        return $this->allRows($version)->whereNull('superseded_at')->values();
    }

    private function approveActive(EnterpriseWikiPageVersion $version): void
    {
        foreach ($this->activeRows($version) as $row) {
            $row->forceFill([
                'approval_status' => EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED,
                'decided_at' => now(),
                'decided_by_user_id' => $row->document_owner_user_id,
            ])->save();
        }
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Sync Test AS',
            'slug' => 'sync-test-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function user(Customer $customer, string $bidRole = User::BID_ROLE_CONTRIBUTOR): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(8)).'@sync.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function document(Customer $customer, ?User $owner, string $filename): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner?->id,
            'original_filename' => $filename,
            'file_path' => 'wiki-documents/'.$customer->id.'/'.Str::random(16).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function page(Customer $customer): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'sync-side-'.Str::lower(Str::random(6)),
            'title' => 'Sync Side',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function version(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Sync Side',
            'generated_by_model' => 'gpt-5',
        ]);
    }

    private function claim(EnterpriseWikiPageVersion $version, string $text): EnterpriseWikiClaim
    {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $version->enterprise_wiki_page_id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);
    }

    private function sourceReference(EnterpriseWikiClaim $claim, EnterpriseWikiDocument $document): EnterpriseWikiSourceReference
    {
        return EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'excerpt' => 'Utdrag',
        ]);
    }
}
