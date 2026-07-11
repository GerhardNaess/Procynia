<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiWikilinkMaterializationTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Happy path: valid slug resolution
    // =========================================================================

    public function test_valid_slug_at_same_customer_creates_exactly_one_link(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPageWithVersion($customer, 'artikkel', '# Artikkel\n\nSe [[business-case]] for detaljer.');
        $target = $this->createPageWithVersion($customer, 'business-case', '# Business case');

        $result = $this->service()->materializeWikilinksForPage($source);

        $this->assertSame(1, $result['valid_links']);
        $this->assertSame(
            1,
            EnterpriseWikiPageLink::query()
                ->where('from_page_id', $source->id)
                ->where('to_page_id', $target->id)
                ->count(),
        );
    }

    public function test_link_type_is_canonical_wikilink_type(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPageWithVersion($customer, 'artikkel', 'Se [[business-case]].');
        $target = $this->createPageWithVersion($customer, 'business-case', '# Business case');

        $this->service()->materializeWikilinksForPage($source);

        $link = EnterpriseWikiPageLink::query()
            ->where('from_page_id', $source->id)
            ->where('to_page_id', $target->id)
            ->first();

        $this->assertNotNull($link);
        $this->assertSame(EnterpriseWikiPageLink::LINK_TYPE_WIKILINK, $link->link_type);
        $this->assertSame(EnterpriseWikiPageLink::SOURCE_DETERMINISTIC, $link->source);
    }

    public function test_from_and_to_page_ids_are_correct(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPageWithVersion($customer, 'artikkel', 'Se [[business-case]].');
        $target = $this->createPageWithVersion($customer, 'business-case', '# Business case');

        $this->service()->materializeWikilinksForPage($source);

        $link = EnterpriseWikiPageLink::query()
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->first();

        $this->assertSame($source->id, $link->from_page_id);
        $this->assertSame($target->id, $link->to_page_id);
        $this->assertSame($customer->id, $link->customer_id);
    }

    public function test_from_and_to_version_ids_are_correct(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPageWithVersion($customer, 'artikkel', 'Se [[business-case]].');
        $target = $this->createPageWithVersion($customer, 'business-case', '# Business case');

        $sourceVersion = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $source->id)->where('is_current', true)->first();
        $targetVersion = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $target->id)->where('is_current', true)->first();

        $this->service()->materializeWikilinksForPage($source);

        $link = EnterpriseWikiPageLink::query()
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->first();

        $this->assertSame($sourceVersion->id, $link->from_page_version_id);
        $this->assertSame($targetVersion->id, $link->to_page_version_id);
    }

    // =========================================================================
    // Broken / self / cross-customer
    // =========================================================================

    public function test_unknown_slug_creates_no_link(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPageWithVersion($customer, 'artikkel', 'Se [[does-not-exist]].');

        $result = $this->service()->materializeWikilinksForPage($source);

        $this->assertSame(0, $result['valid_links']);
        $this->assertSame(['does-not-exist'], $result['broken_slugs']);
        $this->assertSame(0, EnterpriseWikiPageLink::query()->count());
    }

    public function test_cross_customer_slug_creates_no_link(): void
    {
        $customerA = $this->createCustomer('Customer A');
        $customerB = $this->createCustomer('Customer B');
        $source = $this->createPageWithVersion($customerA, 'artikkel', 'Se [[business-case]].');
        $this->createPageWithVersion($customerB, 'business-case', '# Business case for B');

        $result = $this->service()->materializeWikilinksForPage($source);

        $this->assertSame(0, $result['valid_links']);
        $this->assertSame(['business-case'], $result['broken_slugs']);
        $this->assertSame(0, EnterpriseWikiPageLink::query()->count());
    }

    public function test_self_link_creates_no_link(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPageWithVersion($customer, 'artikkel', 'See [[artikkel]] for the same page.');

        $result = $this->service()->materializeWikilinksForPage($source);

        $this->assertSame(0, $result['valid_links']);
        $this->assertSame(['artikkel'], $result['self_link_slugs']);
        $this->assertSame(0, EnterpriseWikiPageLink::query()->count());
    }

    // =========================================================================
    // Deduplication
    // =========================================================================

    public function test_same_target_mentioned_multiple_times_creates_one_relation(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPageWithVersion(
            $customer,
            'artikkel',
            'First [[business-case]] mention, second [[business-case]] mention.',
        );
        $this->createPageWithVersion($customer, 'business-case', '# Business case');

        $result = $this->service()->materializeWikilinksForPage($source);

        $this->assertSame(2, $result['occurrences_found']);
        $this->assertSame(1, $result['valid_links']);
        $this->assertSame(1, EnterpriseWikiPageLink::query()->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)->count());
    }

    public function test_two_different_targets_create_two_relations(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPageWithVersion(
            $customer,
            'artikkel',
            'See [[business-case]] and [[prosjekteier|prosjekteieren]].',
        );
        $this->createPageWithVersion($customer, 'business-case', '# Business case');
        $this->createPageWithVersion($customer, 'prosjekteier', '# Prosjekteier');

        $result = $this->service()->materializeWikilinksForPage($source);

        $this->assertSame(2, $result['valid_links']);
        $this->assertSame(2, EnterpriseWikiPageLink::query()->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)->count());
    }

    // =========================================================================
    // Idempotency and current-version tracking
    // =========================================================================

    public function test_materializing_twice_creates_no_duplicates(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPageWithVersion($customer, 'artikkel', 'Se [[business-case]].');
        $this->createPageWithVersion($customer, 'business-case', '# Business case');

        $this->service()->materializeWikilinksForPage($source);
        $this->service()->materializeWikilinksForPage($source);

        $this->assertSame(1, EnterpriseWikiPageLink::query()->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)->count());
    }

    public function test_new_current_version_of_target_updates_to_page_version_id(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPageWithVersion($customer, 'artikkel', 'Se [[business-case]].');
        $target = $this->createPageWithVersion($customer, 'business-case', '# Business case v1');

        $this->service()->materializeWikilinksForPage($source);

        $oldTargetVersionId = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $target->id)->where('is_current', true)->first()->id;

        // Target gets a new current version (simulating a later regeneration).
        EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $target->id)->update(['is_current' => false]);
        $newTargetVersion = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $target->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => '# Business case v2',
            'generated_by_model' => 'gpt-5',
        ]);

        $this->service()->materializeWikilinksForPage($source);

        $link = EnterpriseWikiPageLink::query()
            ->where('from_page_id', $source->id)
            ->where('to_page_id', $target->id)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->first();

        $this->assertNotSame($oldTargetVersionId, $link->to_page_version_id);
        $this->assertSame($newTargetVersion->id, $link->to_page_version_id);
        $this->assertSame(1, EnterpriseWikiPageLink::query()->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)->count());
    }

    public function test_removed_wikilink_in_new_current_markdown_removes_stale_relation(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPageWithVersion($customer, 'artikkel', 'Se [[business-case]].');
        $this->createPageWithVersion($customer, 'business-case', '# Business case');

        $this->service()->materializeWikilinksForPage($source);
        $this->assertSame(1, EnterpriseWikiPageLink::query()->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)->count());

        // Source gets a new current version that no longer mentions the target.
        EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $source->id)->update(['is_current' => false]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $source->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => 'No links here anymore.',
            'generated_by_model' => 'gpt-5',
        ]);

        $result = $this->service()->materializeWikilinksForPage($source->fresh());

        $this->assertSame(1, $result['stale_links_removed']);
        $this->assertSame(0, EnterpriseWikiPageLink::query()->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)->count());
    }

    public function test_other_link_type_rows_are_not_deleted_when_wikilinks_are_removed(): void
    {
        $customer = $this->createCustomer();
        $article = $this->createPageWithVersion($customer, 'artikkel', 'Se [[business-case]].', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $summary = $this->createPageWithVersion($customer, 'sammendrag', '# Sammendrag', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
        $this->createPageWithVersion($customer, 'business-case', '# Business case');

        // A structural link of a different type between article and summary.
        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $article->id,
            'to_page_id' => $summary->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);

        $this->service()->materializeWikilinksForPage($article);

        // Now remove the wikilink from the article's markdown entirely.
        EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->update(['is_current' => false]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => 'No links anymore.',
            'generated_by_model' => 'gpt-5',
        ]);

        $this->service()->materializeWikilinksForPage($article->fresh());

        $this->assertSame(0, EnterpriseWikiPageLink::query()->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)->count());
        $this->assertSame(
            1,
            EnterpriseWikiPageLink::query()->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY)->count(),
        );
    }

    public function test_empty_markdown_only_removes_this_pages_stale_wikilink_relations(): void
    {
        $customer = $this->createCustomer();
        $source = $this->createPageWithVersion($customer, 'artikkel', 'Se [[business-case]].');
        $otherSource = $this->createPageWithVersion($customer, 'sammendrag', 'Se ogsaa [[business-case]].');
        $this->createPageWithVersion($customer, 'business-case', '# Business case');

        $this->service()->materializeWikilinksForPage($source);
        $this->service()->materializeWikilinksForPage($otherSource);
        $this->assertSame(2, EnterpriseWikiPageLink::query()->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)->count());

        EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $source->id)->update(['is_current' => false]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $source->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => '',
            'generated_by_model' => 'gpt-5',
        ]);

        $this->service()->materializeWikilinksForPage($source->fresh());

        $this->assertSame(
            0,
            EnterpriseWikiPageLink::query()->where('from_page_id', $source->id)->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)->count(),
        );
        $this->assertSame(
            1,
            EnterpriseWikiPageLink::query()->where('from_page_id', $otherSource->id)->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)->count(),
        );
    }

    public function test_no_full_mesh_or_combinatoric_wikilinks_are_created(): void
    {
        $customer = $this->createCustomer();
        $article = $this->createPageWithVersion($customer, 'artikkel', 'Se [[business-case]].', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->createPageWithVersion($customer, 'business-case', '# Business case', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        // Unrelated pages the article's markdown never mentions.
        $this->createPageWithVersion($customer, 'sammendrag', '# Sammendrag', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
        $this->createPageWithVersion($customer, 'entitet', '# Entitet', EnterpriseWikiPage::PAGE_TYPE_ENTITY);

        $this->service()->materializeWikilinksForPage($article);

        // Only the one page actually mentioned in content_markdown gets a wikilink row.
        $this->assertSame(1, EnterpriseWikiPageLink::query()->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)->count());
    }

    // =========================================================================
    // materializeWikilinksForRun() — per-run wrapper
    // =========================================================================

    public function test_materialize_for_run_processes_every_applied_page(): void
    {
        $customer = $this->createCustomer();
        $document = \App\Models\EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'irrelevant',
            'document_status' => \App\Models\EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
        ]);

        $article = $this->createPageWithVersion($customer, 'artikkel', 'Se [[business-case]].', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $concept = $this->createPageWithVersion($customer, 'business-case', 'Se [[artikkel]].', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);

        foreach ([$article, $concept] as $page) {
            \App\Models\EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => \App\Models\EnterpriseWikiIngestRunPage::ACTION_CREATED,
            ]);
        }

        $result = $this->service()->materializeWikilinksForRun($run);

        $this->assertSame(2, $result['pages_processed']);
        $this->assertSame(2, $result['valid_links']);
        $this->assertSame(2, EnterpriseWikiPageLink::query()->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)->count());

        $link = EnterpriseWikiPageLink::query()
            ->where('from_page_id', $article->id)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->first();
        $this->assertSame($run->id, $link->enterprise_wiki_ingest_run_id);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): EnterpriseWikiBuildPageLinksService
    {
        return app(EnterpriseWikiBuildPageLinksService::class);
    }

    private function createCustomer(string $name = 'Test AS'): Customer
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

    private function createPageWithVersion(
        Customer $customer,
        string $slug,
        string $markdown,
        string $pageType = EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
    ): EnterpriseWikiPage {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug,
            'title' => ucfirst($slug),
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown,
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }
}
