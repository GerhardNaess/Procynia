<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiLinkCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_page_is_never_in_the_catalog(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel');
        $run = $this->createAppliedRun($customer, $document, [$article]);

        $result = $this->service()->buildForPage($run, $article);

        $this->assertNotContains($article->slug, array_column($result['catalog'], 'slug'));
    }

    public function test_all_other_applied_run_pages_are_in_the_catalog(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel');
        $summary = $this->createPage($customer, 'sammendrag', 'Sammendrag', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
        $concept = $this->createPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $run = $this->createAppliedRun($customer, $document, [$article, $summary, $concept]);

        $result = $this->service()->buildForPage($run, $article);

        $slugs = array_column($result['catalog'], 'slug');
        $this->assertContains($summary->slug, $slugs);
        $this->assertContains($concept->slug, $slugs);
        $this->assertSame(2, $result['run_page_count']);
    }

    public function test_relevant_existing_customer_pages_can_be_included(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel');
        $run = $this->createAppliedRun($customer, $document, [$article]);

        $existing = $this->createExistingPage($customer, 'eksisterende-side', 'Eksisterende side');

        $result = $this->service()->buildForPage($run, $article);

        $this->assertContains($existing->slug, array_column($result['catalog'], 'slug'));
        $this->assertSame(0, $result['run_page_count']);
    }

    public function test_other_customers_pages_are_never_included(): void
    {
        $customerA = $this->createCustomer('Customer A');
        $customerB = $this->createCustomer('Customer B');
        $document = $this->createDocument($customerA);
        $article = $this->createPage($customerA, 'artikkel', 'Artikkel');
        $run = $this->createAppliedRun($customerA, $document, [$article]);

        $otherCustomerPage = $this->createPage($customerB, 'business-case', 'Business Case');

        $result = $this->service()->buildForPage($run, $article);

        $this->assertNotContains($otherCustomerPage->slug, array_column($result['catalog'], 'slug'));
    }

    public function test_catalog_cap_never_removes_applied_run_pages(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel');

        $runPages = [$article];
        for ($i = 0; $i < 5; $i++) {
            $runPages[] = $this->createPage($customer, "run-page-{$i}", "Run page {$i}", EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        }
        $run = $this->createAppliedRun($customer, $document, $runPages);

        // More "other" customer pages than the cap, to prove the cap only trims these.
        for ($i = 0; $i < EnterpriseWikiLinkCatalogService::MAX_OTHER_PAGES + 10; $i++) {
            $this->createExistingPage($customer, "other-page-{$i}", "Other page {$i}");
        }

        $result = $this->service()->buildForPage($run, $article);

        $runPageSlugs = collect($runPages)->reject(fn (EnterpriseWikiPage $p) => $p->id === $article->id)->pluck('slug');
        $catalogSlugs = array_column($result['catalog'], 'slug');

        foreach ($runPageSlugs as $slug) {
            $this->assertContains($slug, $catalogSlugs, "Expected run page slug [{$slug}] to always be in the catalog.");
        }

        $this->assertSame(5, $result['run_page_count']);
        $this->assertSame(5 + EnterpriseWikiLinkCatalogService::MAX_OTHER_PAGES, count($catalogSlugs));
    }

    public function test_an_incomplete_shell_from_another_run_is_never_offered_as_a_link_target(): void
    {
        // Arbeidspakke A5 (docs/enterprise-wiki-knowledge-integration-plan.md §5).
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel');
        $run = $this->createAppliedRun($customer, $document, [$article]);
        $existing = $this->createExistingPage($customer, 'leverandorstyring', 'Leverandørstyring');
        $shell = $this->createPage($customer, 'feilet-artikkel', 'Feilet artikkel');

        $result = $this->service()->buildForPage($run, $article);
        $slugs = array_column($result['catalog'], 'slug');

        $this->assertContains($existing->slug, $slugs);
        $this->assertNotContains($shell->slug, $slugs);
        $this->assertNotContains($shell->slug, array_column($this->service()->buildForCustomer($customer->id)['catalog'], 'slug'));
    }

    public function test_a_run_page_that_failed_to_generate_is_dropped_but_a_pending_one_stays(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $summary = $this->createPage($customer, 'sammendrag', 'Sammendrag', EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
        $failedArticle = $this->createPage($customer, 'artikkel', 'Artikkel');
        $pendingConcept = $this->createPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $run = $this->createAppliedRun($customer, $document, [$summary, $failedArticle, $pendingConcept]);
        EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $failedArticle->id)
            ->update(['generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED]);

        $result = $this->service()->buildForPage($run, $summary);
        $slugs = array_column($result['catalog'], 'slug');

        // The deferred concept is still to be written and must stay linkable; the failed
        // article is an empty shell.
        $this->assertContains($pendingConcept->slug, $slugs);
        $this->assertNotContains($failedArticle->slug, $slugs);
        $this->assertSame(1, $result['run_page_count']);
    }

    public function test_catalog_entries_carry_a_bounded_excerpt_but_never_content_markdown(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel');
        $run = $this->createAppliedRun($customer, $document, [$article]);
        $existing = $this->createExistingPage($customer, 'business-case', 'Business Case');
        EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $existing->id)->update([
            'content_markdown' => "# Business Case\n\n".str_repeat('Lønnsomhet og gevinster vurderes [[annen-side|samlet]]. ', 40),
        ]);

        $result = $this->service()->buildForPage($run, $article);

        $this->assertNotEmpty($result['catalog']);
        foreach ($result['catalog'] as $entry) {
            $this->assertSame(['page_id', 'slug', 'title', 'page_type', 'excerpt', 'tier'], array_keys($entry));
            $this->assertArrayNotHasKey('content_markdown', $entry);
        }

        $entry = collect($result['catalog'])->firstWhere('page_id', $existing->id);
        $this->assertLessThanOrEqual(EnterpriseWikiLinkCatalogService::EXCERPT_MAX_LENGTH + 1, mb_strlen($entry['excerpt']));
        $this->assertStringNotContainsString('[[', $entry['excerpt'], 'link syntax never reaches the model through an excerpt');
    }

    public function test_a_planned_older_page_is_included_even_when_far_outside_the_most_recent_pages(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'leverandorkontroll', 'Leverandørkontroll');
        $older = $this->createExistingPage($customer, 'leverandorvurdering', 'Leverandørvurdering');
        EnterpriseWikiPage::query()->whereKey($older->id)->update(['updated_at' => now()->subYears(2)]);

        for ($i = 0; $i < EnterpriseWikiLinkCatalogService::MAX_OTHER_PAGES + 15; $i++) {
            $this->createExistingPage($customer, "nyere-side-{$i}", "Nyere side {$i}");
        }

        $run = $this->createAppliedRun($customer, $document, [$article], $this->decisionWithGuidance([
            ['page_title' => 'Leverandørvurdering', 'page_id' => $older->id, 'relationship' => 'Lenk for vurderingsmetoden.'],
        ]));

        $result = $this->service()->buildForPage($run, $article);
        $entry = collect($result['catalog'])->firstWhere('page_id', $older->id);

        $this->assertNotNull($entry, 'the planner named it, so recency can never push it out');
        $this->assertSame(EnterpriseWikiLinkCatalogService::TIER_PLANNED, $entry['tier']);
        $this->assertSame([$older->id], $result['planned_references']['page_ids']);
        $this->assertSame(1, $result['tier_counts']['planned']);
        $this->assertSame(EnterpriseWikiLinkCatalogService::MAX_OTHER_PAGES, $result['tier_counts']['planned'] + $result['tier_counts']['neighbour'] + $result['tier_counts']['recent']);
    }

    public function test_without_planner_references_the_catalog_still_offers_recent_pages(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel');
        $existing = $this->createExistingPage($customer, 'business-case', 'Business Case');
        $run = $this->createAppliedRun($customer, $document, [$article]);

        $result = $this->service()->buildForPage($run, $article);

        $this->assertSame(EnterpriseWikiLinkCatalogService::TIER_RECENT, collect($result['catalog'])->firstWhere('page_id', $existing->id)['tier']);
        $this->assertSame(['page_ids' => [], 'rejected' => []], $result['planned_references']);
    }

    public function test_invalid_planner_page_ids_are_discarded_with_a_reason_and_never_offered(): void
    {
        $customer = $this->createCustomer();
        $other = $this->createCustomer('Annen AS');
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel');
        $foreign = $this->createExistingPage($other, 'fremmed', 'Fremmed side');
        $archived = $this->createExistingPage($customer, 'arkivert', 'Arkivert side');
        $archived->update(['status' => EnterpriseWikiPage::STATUS_ARCHIVED]);
        $superseded = $this->createExistingPage($customer, 'erstattet', 'Erstattet side');
        $superseded->update(['status' => EnterpriseWikiPage::STATUS_SUPERSEDED]);
        $shell = $this->createPage($customer, 'skall', 'Skall uten innhold');
        $valid = $this->createExistingPage($customer, 'gyldig', 'Gyldig side');

        $run = $this->createAppliedRun($customer, $document, [$article], $this->decisionWithGuidance([
            ['page_title' => 'Fremmed side', 'page_id' => $foreign->id, 'relationship' => 'x'],
            ['page_title' => 'Arkivert side', 'page_id' => $archived->id, 'relationship' => 'x'],
            ['page_title' => 'Erstattet side', 'page_id' => $superseded->id, 'relationship' => 'x'],
            ['page_title' => 'Skall uten innhold', 'page_id' => $shell->id, 'relationship' => 'x'],
            ['page_title' => 'Artikkel', 'page_id' => $article->id, 'relationship' => 'x'],
            ['page_title' => 'Finnes ikke', 'page_id' => 999999, 'relationship' => 'x'],
            ['page_title' => 'Gyldig side', 'page_id' => $valid->id, 'relationship' => 'x'],
            ['page_title' => 'Gyldig side', 'page_id' => $valid->id, 'relationship' => 'igjen'],
        ]));

        $result = $this->service()->buildForPage($run, $article);
        $ids = array_column($result['catalog'], 'page_id');

        $this->assertSame([$valid->id], $result['planned_references']['page_ids']);
        $reasons = collect($result['planned_references']['rejected'])->mapWithKeys(fn (array $r) => [$r['page_id'] => $r['reason']])->all();
        $this->assertSame('cross_customer', $reasons[$foreign->id]);
        $this->assertSame('inactive_status', $reasons[$archived->id]);
        $this->assertSame('inactive_status', $reasons[$superseded->id]);
        $this->assertSame('no_content', $reasons[$shell->id]);
        $this->assertSame('self', $reasons[$article->id]);
        $this->assertSame('not_found', $reasons[999999]);
        $this->assertSame('duplicate', $reasons[$valid->id]);

        foreach ([$foreign->id, $archived->id, $superseded->id, $shell->id, $article->id] as $neverOffered) {
            $this->assertNotContains($neverOffered, $ids);
        }
        $this->assertSame(count($ids), count(array_unique($ids)), 'no page is offered twice');
    }

    public function test_a_legacy_guidance_title_without_page_id_resolves_by_exact_title_only(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel');
        $exact = $this->createExistingPage($customer, 'risikovurdering', 'Risikovurdering');
        $this->createExistingPage($customer, 'risikovurdering-av-leverandorer', 'Risikovurdering av leverandører');

        $run = $this->createAppliedRun($customer, $document, [$article], $this->decisionWithGuidance([
            ['page_title' => 'risikovurdering', 'relationship' => 'Legacy uten page_id.'],
            ['page_title' => 'Risiko', 'relationship' => 'Ingen eksakt match — aldri gjettet.'],
        ]));

        $result = $this->service()->buildForPage($run, $article);

        $this->assertSame([$exact->id], $result['planned_references']['page_ids']);
    }

    public function test_one_hop_neighbours_of_planned_pages_are_offered_before_recent_pages(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel');
        $planned = $this->createExistingPage($customer, 'planlagt', 'Planlagt');
        $neighbour = $this->createExistingPage($customer, 'nabo', 'Nabo');
        $archivedNeighbour = $this->createExistingPage($customer, 'arkivert-nabo', 'Arkivert nabo');
        $archivedNeighbour->update(['status' => EnterpriseWikiPage::STATUS_ARCHIVED]);

        foreach ([$neighbour, $archivedNeighbour] as $to) {
            EnterpriseWikiPageLink::query()->create([
                'customer_id' => $customer->id,
                'from_page_id' => $to->id,
                'to_page_id' => $planned->id,
                'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
                'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
                'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
            ]);
        }

        $run = $this->createAppliedRun($customer, $document, [$article], $this->decisionWithGuidance([
            ['page_title' => 'Planlagt', 'page_id' => $planned->id, 'relationship' => 'x'],
        ]));

        $catalog = collect($this->service()->buildForPage($run, $article)['catalog']);

        $this->assertSame(EnterpriseWikiLinkCatalogService::TIER_NEIGHBOUR, $catalog->firstWhere('page_id', $neighbour->id)['tier']);
        $this->assertNull($catalog->firstWhere('page_id', $archivedNeighbour->id));
    }

    public function test_archived_and_superseded_pages_are_never_offered_in_any_tier(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createPage($customer, 'artikkel', 'Artikkel');
        $archived = $this->createExistingPage($customer, 'arkivert', 'Arkivert');
        $archived->update(['status' => EnterpriseWikiPage::STATUS_ARCHIVED]);
        $superseded = $this->createExistingPage($customer, 'erstattet', 'Erstattet');
        $superseded->update(['status' => EnterpriseWikiPage::STATUS_SUPERSEDED]);
        $run = $this->createAppliedRun($customer, $document, [$article]);

        $ids = array_column($this->service()->buildForPage($run, $article)['catalog'], 'page_id');

        $this->assertNotContains($archived->id, $ids);
        $this->assertNotContains($superseded->id, $ids);
        $this->assertNotContains($archived->id, array_column($this->service()->buildForCustomer($customer->id)['catalog'], 'page_id'));
    }

    /**
     * @param  list<array<string, mixed>>  $guidance
     * @return array<string, mixed>
     */
    private function decisionWithGuidance(array $guidance): array
    {
        return [
            'source_article' => [
                'action' => 'create',
                'title' => 'Artikkel',
                'proposed_slug' => 'artikkel',
                'reason' => 'Test.',
                'related_page_guidance' => $guidance,
            ],
            'concept_pages' => [],
            'entity_pages' => [],
        ];
    }

    private function service(): EnterpriseWikiLinkCatalogService
    {
        return app(EnterpriseWikiLinkCatalogService::class);
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

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'This is the extracted text from the source document.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createPage(
        Customer $customer,
        string $slug,
        string $title,
        string $pageType = EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
    ): EnterpriseWikiPage {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug,
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    /**
     * An existing Wiki page outside the run: it has a current version, as every real one does.
     */
    private function createExistingPage(Customer $customer, string $slug, string $title): EnterpriseWikiPage
    {
        $page = $this->createPage($customer, $slug, $title);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# '.$title,
        ]);

        return $page;
    }

    private function createAppliedRun(Customer $customer, EnterpriseWikiDocument $document, array $pages, ?array $decision = null): EnterpriseWikiIngestRun
    {
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => $decision,
        ]);

        foreach ($pages as $page) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            ]);
        }

        return $run;
    }
}
