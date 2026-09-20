<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\RequirementWikiCatalogBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Spør Wiki" answers from what the Wiki currently says, not only from what has been published.
 *
 * THE BUG THIS PINS DOWN. A user asked "Fortell hvordan vi bruker Google Cloud i leveransene" and
 * was told there was no information — while looking at a Google Cloud page describing exactly that.
 * The page was a working version with no published version, and retrieval was built to consider
 * only pages naming a published one. On that customer every one of the 35 pages was still a draft,
 * so the catalog was empty, retrieval returned nothing, and the feature short-circuited to
 * "insufficient evidence" without ever calling the model — which is why the answer came back
 * instantly.
 *
 * The rule now has two modes and the surfaces differ on purpose: drafting a tender answer still
 * grounds only in published knowledge, because what goes into a bid must be something someone
 * approved. Asking your own Wiki what it says is a different question, and answering "that does not
 * exist" about a page the user can open and read is simply wrong.
 *
 * Deterministic on purpose: this asserts what retrieval makes available, never how the model
 * phrases an answer.
 */
class WikiAskWorkingVersionRetrievalTest extends TestCase
{
    use DatabaseTransactions;

    private const GOOGLE_CLOUD_TEXT = 'Google Cloud Functions og Cloud Run kan benyttes som '
        .'integrasjons- og automasjonskomponenter i SOC-leveranser.';

    // =========================================================================
    // The reported case
    // =========================================================================

    public function test_a_draft_google_cloud_page_is_retrievable_for_ask_wiki(): void
    {
        $customer = $this->customer();
        $page = $this->pageWithWorkingVersionOnly($customer, 'Google Cloud', self::GOOGLE_CLOUD_TEXT);

        $entry = $this->askCatalogEntry($customer, $page);

        $this->assertNotNull($entry, 'the page the user was reading must be answerable');
        $this->assertStringContainsString('Google Cloud Functions', $entry['content_markdown']);
        $this->assertStringContainsString('Cloud Run', $entry['content_markdown']);
        $this->assertStringContainsString('SOC-leveranser', $entry['content_markdown']);
    }

    /**
     * The exact shape of the reported customer: nothing published anywhere. Before the fix the
     * catalog was empty, so no question could be answered at all.
     */
    public function test_a_wiki_where_nothing_is_published_still_answers(): void
    {
        $customer = $this->customer();
        $this->pageWithWorkingVersionOnly($customer, 'Google Cloud', self::GOOGLE_CLOUD_TEXT);
        $this->pageWithWorkingVersionOnly($customer, 'Hybrid SOC-arkitektur', 'Kundens SIEM kombineres med plattformen.');

        $this->assertCount(2, $this->askCatalog($customer));
        $this->assertSame([], $this->publishedCatalog($customer), 'and tender drafting still has nothing');
    }

    // =========================================================================
    // Which version answers
    // =========================================================================

    public function test_a_newer_working_version_answers_instead_of_an_older_published_one(): void
    {
        $customer = $this->customer();
        $page = $this->page($customer, 'Google Cloud', EnterpriseWikiPage::STATUS_DRAFT);
        $published = $this->version($page, 1, 'Gammel tekst om plattformen.', isCurrent: false);
        $this->version($page, 2, self::GOOGLE_CLOUD_TEXT, isCurrent: true);
        $page->forceFill(['published_version_id' => $published->id])->save();

        $entry = $this->askCatalogEntry($customer, $page->fresh());

        $this->assertStringContainsString('Cloud Run', $entry['content_markdown'], 'the newest version wins');
        $this->assertStringNotContainsString('Gammel tekst', $entry['content_markdown']);
    }

    /** The same page, drafting a tender answer: only what was approved may be used. */
    public function test_tender_drafting_still_reads_the_published_version_of_that_page(): void
    {
        $customer = $this->customer();
        $page = $this->page($customer, 'Google Cloud', EnterpriseWikiPage::STATUS_DRAFT);
        $published = $this->version($page, 1, 'Gammel tekst om plattformen.', isCurrent: false);
        $this->version($page, 2, self::GOOGLE_CLOUD_TEXT, isCurrent: true);
        $page->forceFill(['published_version_id' => $published->id])->save();

        $entry = collect($this->publishedCatalog($customer))->firstWhere('page_id', $page->id);

        $this->assertStringContainsString('Gammel tekst', $entry['content_markdown']);
        $this->assertStringNotContainsString('Cloud Run', $entry['content_markdown']);
    }

    public function test_a_published_page_with_no_newer_draft_is_unaffected(): void
    {
        $customer = $this->customer();
        $page = $this->page($customer, 'Google Cloud', EnterpriseWikiPage::STATUS_APPROVED);
        $published = $this->version($page, 1, self::GOOGLE_CLOUD_TEXT, isCurrent: true);
        $page->forceFill(['published_version_id' => $published->id])->save();

        $this->assertStringContainsString('Cloud Run', $this->askCatalogEntry($customer, $page->fresh())['content_markdown']);
    }

    // =========================================================================
    // What must NOT become answerable
    // =========================================================================

    public function test_another_customers_draft_never_leaks_in(): void
    {
        $own = $this->customer();
        $other = $this->customer();
        $this->pageWithWorkingVersionOnly($other, 'Google Cloud', self::GOOGLE_CLOUD_TEXT);

        $this->assertSame([], $this->askCatalog($own), 'the customer id is the isolation boundary');
    }

    /**
     * Widening which VERSION of a page is read must not widen which PAGES may be read: the asking
     * user's own visible statuses still decide that.
     */
    public function test_a_status_the_user_cannot_read_stays_out(): void
    {
        $customer = $this->customer();
        $this->pageWithWorkingVersionOnly($customer, 'Google Cloud', self::GOOGLE_CLOUD_TEXT);

        $catalog = app(RequirementWikiCatalogBuilder::class)->build(
            $customer->id,
            RequirementWikiCatalogBuilder::GROUNDING_CURRENT_KNOWLEDGE,
            [EnterpriseWikiPage::STATUS_APPROVED],
        );

        $this->assertSame([], $catalog);
    }

    public function test_a_user_who_may_read_nothing_gets_nothing(): void
    {
        $customer = $this->customer();
        $this->pageWithWorkingVersionOnly($customer, 'Google Cloud', self::GOOGLE_CLOUD_TEXT);

        // An empty visible set means "this user reads nothing" and must stay empty rather than
        // degrade into "no filter at all".
        $catalog = app(RequirementWikiCatalogBuilder::class)->build(
            $customer->id,
            RequirementWikiCatalogBuilder::GROUNDING_CURRENT_KNOWLEDGE,
            [],
        );

        $this->assertSame([], $catalog);
    }

    /** @return array<string, array{string}> */
    public static function retiredStatuses(): array
    {
        return [
            'archived' => [EnterpriseWikiPage::STATUS_ARCHIVED],
            'superseded' => [EnterpriseWikiPage::STATUS_SUPERSEDED],
        ];
    }

    public function test_retired_pages_stay_out_even_as_working_versions(): void
    {
        foreach (array_column(self::retiredStatuses(), 0) as $status) {
            $customer = $this->customer();
            $page = $this->pageWithWorkingVersionOnly($customer, 'Google Cloud', self::GOOGLE_CLOUD_TEXT);
            $page->forceFill(['status' => $status])->save();

            $this->assertSame([], $this->askCatalog($customer), "{$status} is retired, not merely unpublished");
        }
    }

    public function test_an_empty_working_version_cannot_answer_anything(): void
    {
        $customer = $this->customer();
        $page = $this->page($customer, 'Google Cloud', EnterpriseWikiPage::STATUS_DRAFT);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '',
            'generated_by_model' => 'gpt-5',
        ]);

        $this->assertSame([], $this->askCatalog($customer));
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /** @return list<array<string, mixed>> */
    private function askCatalog(Customer $customer): array
    {
        return app(RequirementWikiCatalogBuilder::class)->build(
            $customer->id,
            RequirementWikiCatalogBuilder::GROUNDING_CURRENT_KNOWLEDGE,
            EnterpriseWikiPage::STATUSES,
        );
    }

    /** @return list<array<string, mixed>> */
    private function publishedCatalog(Customer $customer): array
    {
        return app(RequirementWikiCatalogBuilder::class)->build($customer->id);
    }

    /** @return ?array<string, mixed> */
    private function askCatalogEntry(Customer $customer, EnterpriseWikiPage $page): ?array
    {
        return collect($this->askCatalog($customer))->firstWhere('page_id', $page->id);
    }

    private function pageWithWorkingVersionOnly(Customer $customer, string $title, string $text): EnterpriseWikiPage
    {
        $page = $this->page($customer, $title, EnterpriseWikiPage::STATUS_DRAFT);
        $this->version($page, 1, $text, isCurrent: true);

        return $page->fresh();
    }

    private function page(Customer $customer, string $title, string $status): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ENTITY,
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
            'content_markdown' => "# {$page->title}\n\n## Integrasjoner og automasjon i SOC\n\n{$text}",
            'generated_by_model' => 'gpt-5',
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Ask Wiki Working Version AS',
            'slug' => 'ask-wiki-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
