<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\RequirementWikiCatalogBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Retrieval answers from published Wiki knowledge and nothing else.
 *
 * `is_current` is the version the pipeline is working on; `published_version_id` is the version
 * readers may rely on. Retrieval used to read the first, which meant a page fell out of the catalog
 * the moment a new draft landed, and — worse — could serve content nobody had approved.
 *
 * The rule now has one term: a page is retrievable when it names a published version. Page status is
 * deliberately not part of it. A page can be pending_review or rejected while an earlier approved
 * version keeps answering questions, because status describes what is happening to the WORKING
 * version, not what has been published.
 *
 * See docs/enterprise-wiki-approval-model.md §6.
 */
class EnterpriseWikiPublishedRetrievalTest extends TestCase
{
    use DatabaseTransactions;

    private const PUBLISHED_TEXT = 'Godkjent innhold om leveransemodellen.';

    private const WORKING_TEXT = 'Ugodkjent utkast om leveransemodellen.';

    // A + E. nothing published means nothing to retrieve
    public function test_a_page_with_no_published_version_is_not_retrievable(): void
    {
        [$customer, $page] = $this->pageWithWorkingVersionOnly();

        $this->assertSame([], $this->catalogPageIds($customer), 'a page nobody has published is invisible');
        $this->assertNotNull($page->currentVersion()->first(), 'even though it has a working version');
    }

    // B, C, D, G, H. an earlier published version keeps answering, whatever the page status
    #[DataProvider('workingVersionStatuses')]
    public function test_a_published_version_keeps_answering_while_a_new_one_is_worked_on(string $status): void
    {
        [$customer, $page, $published] = $this->pageWithPublishedAndWorkingVersion($status);

        $entry = $this->catalogEntry($customer, $page);

        $this->assertNotNull($entry, "status {$status} must not hide published knowledge");
        $this->assertStringContainsString(self::PUBLISHED_TEXT, $entry['content_markdown']);
        $this->assertStringNotContainsString(self::WORKING_TEXT, $entry['content_markdown']);
        $this->assertSame($published->id, (int) $page->fresh()->published_version_id);
    }

    /** @return array<string, array{0: string}> */
    public static function workingVersionStatuses(): array
    {
        return [
            'a draft is being written' => [EnterpriseWikiPage::STATUS_DRAFT],
            'it is out for review' => [EnterpriseWikiPage::STATUS_PENDING_REVIEW],
            'changes were requested' => [EnterpriseWikiPage::STATUS_REJECTED],
            'it is approved' => [EnterpriseWikiPage::STATUS_APPROVED],
        ];
    }

    // F. one page, one entry — never both versions
    public function test_a_page_appears_exactly_once(): void
    {
        [$customer, $page] = $this->pageWithPublishedAndWorkingVersion(EnterpriseWikiPage::STATUS_PENDING_REVIEW);

        $matching = collect($this->catalog($customer))->where('page_id', $page->id);

        $this->assertCount(1, $matching);
    }

    // E (publication moves). approving the new version switches the source
    public function test_publishing_the_new_version_switches_retrieval_to_it(): void
    {
        [$customer, $page, , $working] = $this->pageWithPublishedAndWorkingVersion(EnterpriseWikiPage::STATUS_PENDING_REVIEW);

        $page->forceFill(['published_version_id' => $working->id, 'status' => EnterpriseWikiPage::STATUS_APPROVED])->save();

        $entry = $this->catalogEntry($customer, $page);
        $this->assertStringContainsString(self::WORKING_TEXT, $entry['content_markdown'], 'the newly published version answers now');
        $this->assertStringNotContainsString(self::PUBLISHED_TEXT, $entry['content_markdown'], 'the previous one no longer does');
    }

    // I. status approved without a published version is inconsistent, and excluded
    public function test_an_approved_page_with_nothing_published_is_excluded(): void
    {
        [$customer, $page] = $this->pageWithWorkingVersionOnly();
        $page->forceFill(['status' => EnterpriseWikiPage::STATUS_APPROVED])->save();

        $this->assertSame([], $this->catalogPageIds($customer), 'published_version_id decides, not status');
    }

    // Q. losing the published version fails closed rather than falling back to the draft
    public function test_deleting_the_published_version_makes_the_page_unretrievable(): void
    {
        [$customer, $page, $published] = $this->pageWithPublishedAndWorkingVersion(EnterpriseWikiPage::STATUS_DRAFT);

        // The foreign key is nullOnDelete, so a dangling pointer cannot even be represented — the
        // page simply stops naming a published version, and drops out. It never falls back to the
        // working version, which is the failure mode that matters.
        $published->delete();

        $this->assertNull($page->fresh()->published_version_id);
        $this->assertSame([], $this->catalogPageIds($customer), 'a lost published version must not fall back to the draft');
    }

    public function test_a_published_pointer_to_another_pages_version_excludes_the_page(): void
    {
        [$customer, $page] = $this->pageWithPublishedAndWorkingVersion(EnterpriseWikiPage::STATUS_DRAFT);
        [, $otherPage, $otherPublished] = $this->pageWithPublishedAndWorkingVersion(EnterpriseWikiPage::STATUS_DRAFT, $customer);

        $page->forceFill(['published_version_id' => $otherPublished->id])->save();

        $ids = $this->catalogPageIds($customer);
        $this->assertNotContains($page->id, $ids, 'a pointer into another page is corruption, not content');
        $this->assertContains($otherPage->id, $ids, 'the healthy page is unaffected');
    }

    // R. being newer is not being published
    public function test_a_higher_version_number_alone_does_not_make_a_version_retrievable(): void
    {
        [$customer, $page, $published, $working] = $this->pageWithPublishedAndWorkingVersion(EnterpriseWikiPage::STATUS_DRAFT);

        $this->assertGreaterThan($published->version_number, $working->version_number);
        $this->assertStringContainsString(self::PUBLISHED_TEXT, $this->catalogEntry($customer, $page)['content_markdown']);
    }

    // L + M. working content cannot leak in
    public function test_claims_and_blocks_from_the_working_version_never_leak_into_the_catalog(): void
    {
        [$customer, $page, $published, $working] = $this->pageWithPublishedAndWorkingVersion(EnterpriseWikiPage::STATUS_PENDING_REVIEW);
        $this->claim($page, $published, 'Publisert påstand om leveransemodell.');
        $this->claim($page, $working, 'Ugodkjent påstand om leveransemodell.');

        $entry = $this->catalogEntry($customer, $page);

        $this->assertStringNotContainsString(self::WORKING_TEXT, $entry['content_markdown']);
        $this->assertStringNotContainsString('Ugodkjent påstand', json_encode($entry, JSON_UNESCAPED_UNICODE));
    }

    // P. the customer boundary is unchanged
    public function test_another_customers_published_page_is_never_retrievable(): void
    {
        [$customer] = $this->pageWithPublishedAndWorkingVersion(EnterpriseWikiPage::STATUS_DRAFT);
        [, $foreignPage] = $this->pageWithPublishedAndWorkingVersion(EnterpriseWikiPage::STATUS_APPROVED);

        $this->assertNotContains($foreignPage->id, $this->catalogPageIds($customer));
    }

    // J + K. publication is a decision that stands
    public function test_pending_owner_approvals_on_the_new_version_do_not_withdraw_published_knowledge(): void
    {
        // The document-owner gate is what allows a version to be published. Once that has happened,
        // sign-offs outstanding on a LATER working version say nothing about the published one.
        [$customer, $page] = $this->pageWithPublishedAndWorkingVersion(EnterpriseWikiPage::STATUS_PENDING_REVIEW);

        $entry = $this->catalogEntry($customer, $page);

        $this->assertNotNull($entry);
        $this->assertStringContainsString(self::PUBLISHED_TEXT, $entry['content_markdown']);
    }

    // S. a return leaves published knowledge alone
    public function test_requesting_changes_does_not_withdraw_published_knowledge(): void
    {
        [$customer, $page] = $this->pageWithPublishedAndWorkingVersion(EnterpriseWikiPage::STATUS_REJECTED);

        $this->assertStringContainsString(
            self::PUBLISHED_TEXT,
            $this->catalogEntry($customer, $page)['content_markdown'],
        );
    }

    public function test_archived_pages_stay_out_even_when_something_is_published(): void
    {
        // Archiving retires a page outright, which is different from revising it.
        [$customer, $page] = $this->pageWithPublishedAndWorkingVersion(EnterpriseWikiPage::STATUS_DRAFT);
        $page->forceFill(['status' => EnterpriseWikiPage::STATUS_ARCHIVED])->save();

        $this->assertSame([], $this->catalogPageIds($customer));
    }

    // O. the builder can no longer be asked for anything but published content
    public function test_the_catalog_builder_takes_no_status_or_approval_parameters(): void
    {
        $build = new \ReflectionMethod(RequirementWikiCatalogBuilder::class, 'build');

        $this->assertSame(
            ['customerId'],
            array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $build->getParameters()),
            'widening retrieval into draft content must not be expressible',
        );
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /** @return array{0: Customer, 1: EnterpriseWikiPage} */
    private function pageWithWorkingVersionOnly(): array
    {
        $customer = $this->customer();
        $page = $this->page($customer, EnterpriseWikiPage::STATUS_DRAFT);
        $this->version($page, 1, self::WORKING_TEXT, isCurrent: true);

        return [$customer, $page->fresh()];
    }

    /**
     * A page with an approved, published version and a newer working version on top of it.
     *
     * @return array{0: Customer, 1: EnterpriseWikiPage, 2: EnterpriseWikiPageVersion, 3: EnterpriseWikiPageVersion}
     */
    private function pageWithPublishedAndWorkingVersion(string $status, ?Customer $customer = null): array
    {
        $customer ??= $this->customer();
        $page = $this->page($customer, $status);

        $published = $this->version($page, 1, self::PUBLISHED_TEXT, isCurrent: false);
        $working = $this->version($page, 2, self::WORKING_TEXT, isCurrent: true);

        $page->forceFill(['published_version_id' => $published->id])->save();

        return [$customer, $page->fresh(), $published, $working];
    }

    /** @return list<array<string, mixed>> */
    private function catalog(Customer $customer): array
    {
        return app(RequirementWikiCatalogBuilder::class)->build($customer->id);
    }

    /** @return list<int> */
    private function catalogPageIds(Customer $customer): array
    {
        return collect($this->catalog($customer))->pluck('page_id')->map(static fn ($id): int => (int) $id)->all();
    }

    /** @return array<string, mixed>|null */
    private function catalogEntry(Customer $customer, EnterpriseWikiPage $page): ?array
    {
        return collect($this->catalog($customer))->firstWhere('page_id', $page->id);
    }

    private function page(Customer $customer, string $status): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'publisert-'.Str::lower(Str::random(6)),
            'title' => 'Leveransemodell',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => $status,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function version(EnterpriseWikiPage $page, int $number, string $text, bool $isCurrent): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => $number,
            'is_current' => $isCurrent,
            'content_markdown' => "# Leveransemodell\n\n{$text}",
            'generated_by_model' => 'gpt-5',
        ]);
    }

    private function claim(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, string $text): EnterpriseWikiClaim
    {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Publisert Retrieval AS',
            'slug' => 'publisert-retrieval-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }
}
