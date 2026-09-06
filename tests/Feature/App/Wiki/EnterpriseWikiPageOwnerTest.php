<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiPageOwnerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Wiki page ownership is inherited once, from the document whose run created the page, and then
 * stands on its own. It is not a mirror of that document's current owner and not a function of who
 * contributed last — both of those would quietly move responsibility onto people who never took it.
 *
 * Document-owner approval answers a different question per version and is deliberately untouched
 * here. See docs/enterprise-wiki-approval-model.md §3.
 */
class EnterpriseWikiPageOwnerTest extends TestCase
{
    use DatabaseTransactions;

    private EnterpriseWikiPageOwnerService $owners;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owners = app(EnterpriseWikiPageOwnerService::class);
    }

    // A. a new page inherits the creating document's owner
    public function test_a_run_from_a_document_confers_that_documents_owner(): void
    {
        $customer = $this->customer();
        $ownerX = $this->user($customer);
        $run = $this->ingestRun($customer, $this->document($customer, $ownerX));

        $this->assertSame($ownerX->id, $this->owners->ownerUserIdForRun($run));
    }

    // B + E. later contributions never take ownership away
    public function test_a_later_run_from_another_document_does_not_change_the_owner(): void
    {
        $customer = $this->customer();
        $ownerX = $this->user($customer);
        $ownerY = $this->user($customer);

        $page = $this->page($customer, $ownerX);
        $creatingRun = $this->ingestRun($customer, $this->document($customer, $ownerX));
        $this->pivot($creatingRun, $page, EnterpriseWikiIngestRunPage::ACTION_CREATED);

        // Two further documents enrich the same page.
        foreach ([$ownerY, $this->user($customer)] as $contributor) {
            $laterRun = $this->ingestRun($customer, $this->document($customer, $contributor));
            $this->pivot($laterRun, $page, EnterpriseWikiIngestRunPage::ACTION_UPDATED);
        }

        $this->owners->backfillMissingOwners($customer->id);

        $this->assertSame($ownerX->id, (int) $page->fresh()->owner_user_id);
    }

    // C. an existing owner is never overwritten
    public function test_backfill_never_overwrites_an_owner_that_is_already_set(): void
    {
        $customer = $this->customer();
        $standing = $this->user($customer);
        $documentOwner = $this->user($customer);

        $page = $this->page($customer, $standing);
        $run = $this->ingestRun($customer, $this->document($customer, $documentOwner));
        $this->pivot($run, $page, EnterpriseWikiIngestRunPage::ACTION_CREATED);

        $result = $this->owners->backfillMissingOwners($customer->id);

        $this->assertSame($standing->id, (int) $page->fresh()->owner_user_id);
        $this->assertSame(0, $result['assigned']);
    }

    // D. an unambiguous origin is backfilled
    public function test_backfill_assigns_the_original_documents_owner(): void
    {
        $customer = $this->customer();
        $ownerX = $this->user($customer);

        $page = $this->page($customer, null);
        $run = $this->ingestRun($customer, $this->document($customer, $ownerX));
        $this->pivot($run, $page, EnterpriseWikiIngestRunPage::ACTION_CREATED);

        $result = $this->owners->backfillMissingOwners($customer->id);

        $this->assertSame(1, $result['assigned']);
        $this->assertSame($ownerX->id, (int) $page->fresh()->owner_user_id);
    }

    public function test_backfill_is_idempotent(): void
    {
        $customer = $this->customer();
        $ownerX = $this->user($customer);
        $page = $this->page($customer, null);
        $this->pivot($this->ingestRun($customer, $this->document($customer, $ownerX)), $page, EnterpriseWikiIngestRunPage::ACTION_CREATED);

        $first = $this->owners->backfillMissingOwners($customer->id);
        $second = $this->owners->backfillMissingOwners($customer->id);

        $this->assertSame(1, $first['assigned']);
        $this->assertSame(0, $second['assigned'], 'a second run changes nothing');
        $this->assertSame($ownerX->id, (int) $page->fresh()->owner_user_id);
    }

    // F. a handover of the original document does not move page ownership
    public function test_handing_over_the_original_document_leaves_the_page_owner_alone(): void
    {
        $customer = $this->customer();
        $ownerX = $this->user($customer);
        $ownerY = $this->user($customer);

        $document = $this->document($customer, $ownerX);
        $page = $this->page($customer, null);
        $this->pivot($this->ingestRun($customer, $document), $page, EnterpriseWikiIngestRunPage::ACTION_CREATED);
        $this->owners->backfillMissingOwners($customer->id);
        $this->assertSame($ownerX->id, (int) $page->fresh()->owner_user_id);

        $document->forceFill(['owner_user_id' => $ownerY->id])->save();

        // Nothing in the document-owner sync path touches page ownership, and a re-run of backfill
        // skips the page because it already has an owner.
        $this->owners->backfillMissingOwners($customer->id);

        $this->assertSame(
            $ownerX->id,
            (int) $page->fresh()->owner_user_id,
            'page ownership is a standing responsibility, not a mirror of the document',
        );
    }

    // G. ambiguity is never guessed
    #[DataProvider('ambiguousOrigins')]
    public function test_an_ambiguous_origin_leaves_the_page_unowned(string $situation): void
    {
        $customer = $this->customer();
        $page = $this->page($customer, null);

        match ($situation) {
            'no_created_row' => $this->pivot(
                $this->ingestRun($customer, $this->document($customer, $this->user($customer))),
                $page,
                EnterpriseWikiIngestRunPage::ACTION_UPDATED,
            ),
            'multiple_created_rows' => collect(range(1, 2))->each(fn () => $this->pivot(
                $this->ingestRun($customer, $this->document($customer, $this->user($customer))),
                $page,
                EnterpriseWikiIngestRunPage::ACTION_CREATED,
            )),
            'document_without_owner' => $this->pivot(
                $this->ingestRun($customer, $this->document($customer, null)),
                $page,
                EnterpriseWikiIngestRunPage::ACTION_CREATED,
            ),
            'non_document_source' => $this->pivot(
                $this->ingestRun($customer, null, EnterpriseWikiIngestRun::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION),
                $page,
                EnterpriseWikiIngestRunPage::ACTION_CREATED,
            ),
        };

        $result = $this->owners->backfillMissingOwners($customer->id);

        $this->assertSame(0, $result['assigned'], "{$situation} must not be guessed");
        $this->assertNull($page->fresh()->owner_user_id);
        $this->assertContains($page->id, $result['skipped_page_ids']);
        $this->assertSame(1, $this->owners->unownedPageDiagnostics($customer->id)[$situation]);
    }

    /** @return array<string, array{0: string}> */
    public static function ambiguousOrigins(): array
    {
        return [
            'only an updated row' => ['no_created_row'],
            'two created rows' => ['multiple_created_rows'],
            'the document has no owner' => ['document_without_owner'],
            'the source is a knowledge item version' => ['non_document_source'],
        ];
    }

    // I. the knowledge-item path is refused explicitly, not filled in from somewhere else
    public function test_a_knowledge_item_version_source_confers_no_owner(): void
    {
        $customer = $this->customer();
        $run = $this->ingestRun($customer, null, EnterpriseWikiIngestRun::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION);

        $this->assertNull(
            $this->owners->ownerUserIdForRun($run),
            'knowledge item ownership is a different responsibility and is not borrowed here',
        );
    }

    // H. ownership never crosses a customer boundary
    public function test_a_document_belonging_to_another_customer_confers_no_owner(): void
    {
        $customer = $this->customer();
        $otherCustomer = $this->customer('Annen Kunde AS');
        $foreignDocument = $this->document($otherCustomer, $this->user($otherCustomer));

        // A run in our customer pointing at another customer's document id.
        $run = $this->ingestRun($customer, $foreignDocument);

        $this->assertNull($this->owners->ownerUserIdForRun($run));
    }

    public function test_backfill_never_reaches_across_customers(): void
    {
        $customer = $this->customer();
        $otherCustomer = $this->customer('Annen Kunde AS');

        $page = $this->page($customer, null);
        $foreignDocument = $this->document($otherCustomer, $this->user($otherCustomer));
        $this->pivot($this->ingestRun($otherCustomer, $foreignDocument), $page, EnterpriseWikiIngestRunPage::ACTION_CREATED);

        $result = $this->owners->backfillMissingOwners($customer->id);

        $this->assertSame(0, $result['assigned']);
        $this->assertNull($page->fresh()->owner_user_id);
    }

    public function test_the_command_reports_and_writes_the_same_thing(): void
    {
        $customer = $this->customer();
        $ownerX = $this->user($customer);
        $page = $this->page($customer, null);
        $this->pivot($this->ingestRun($customer, $this->document($customer, $ownerX)), $page, EnterpriseWikiIngestRunPage::ACTION_CREATED);

        $this->artisan('enterprise-wiki:backfill-page-owners', ['--customer' => $customer->id, '--dry-run' => true])
            ->assertSuccessful();
        $this->assertNull($page->fresh()->owner_user_id, 'a dry run writes nothing');

        $this->artisan('enterprise-wiki:backfill-page-owners', ['--customer' => $customer->id])
            ->assertSuccessful();
        $this->assertSame($ownerX->id, (int) $page->fresh()->owner_user_id);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function customer(string $name = 'Sideeier Test AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function user(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Bruker '.Str::random(5),
            'email' => Str::lower(Str::random(8)).'@sideeier.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function document(Customer $customer, ?User $owner): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner?->id,
            'original_filename' => 'kilde-'.Str::random(4).'.docx',
            'file_path' => 'wiki-documents/'.$customer->id.'/'.Str::random(16).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function page(Customer $customer, ?User $owner): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner?->id,
            'slug' => 'sideeier-'.Str::lower(Str::random(6)),
            'title' => 'Sideeier Side',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function ingestRun(
        Customer $customer,
        ?EnterpriseWikiDocument $document,
        string $sourceType = EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
    ): EnterpriseWikiIngestRun {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => $sourceType,
            'source_id' => $document?->id ?? 1,
            'status' => EnterpriseWikiIngestRun::STATUS_COMPLETED,
        ]);
    }

    private function pivot(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page, string $action): EnterpriseWikiIngestRunPage
    {
        return EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => $action,
        ]);
    }
}
