<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiBuildPageLinksCommandTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Argument validation
    // =========================================================================

    public function test_command_fails_when_run_id_is_missing(): void
    {
        $this->artisan('wiki:build-page-links')
            ->expectsOutputToContain('--run-id is required')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_run_not_found(): void
    {
        $this->artisan('wiki:build-page-links', ['--run-id' => 99999])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    // =========================================================================
    // Guard: run not applied
    // =========================================================================

    public function test_command_fails_when_run_not_applied(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createRunPending($customer);

        $this->artisan('wiki:build-page-links', ['--run-id' => $run->id])
            ->expectsOutputToContain("only 'applied'")
            ->assertExitCode(1);
    }

    // =========================================================================
    // Happy path: exits zero
    // =========================================================================

    public function test_command_exits_zero_on_success(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createFullRun($customer);

        $this->artisan('wiki:build-page-links', ['--run-id' => $run->id])
            ->assertExitCode(0);
    }

    // =========================================================================
    // Link creation: all 10 link types
    // =========================================================================

    public function test_command_creates_article_to_summary_link(): void
    {
        $customer                              = $this->createCustomer();
        [$run, $article, $summary]             = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $this->assertTrue($this->linkExists($customer, $article, $summary, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY));
    }

    public function test_command_creates_summary_to_article_backlink(): void
    {
        $customer                  = $this->createCustomer();
        [$run, $article, $summary] = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $this->assertTrue($this->linkExists($customer, $summary, $article, EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ARTICLE));
    }

    public function test_command_creates_article_to_concept_link(): void
    {
        $customer                             = $this->createCustomer();
        [$run, $article, , $concept]          = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $this->assertTrue($this->linkExists($customer, $article, $concept, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_CONCEPT));
    }

    public function test_command_creates_concept_to_article_backlink(): void
    {
        $customer                    = $this->createCustomer();
        [$run, $article, , $concept] = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $this->assertTrue($this->linkExists($customer, $concept, $article, EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_ARTICLE));
    }

    public function test_command_creates_article_to_entity_link(): void
    {
        $customer                               = $this->createCustomer();
        [$run, $article, , , $entity]           = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $this->assertTrue($this->linkExists($customer, $article, $entity, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_ENTITY));
    }

    public function test_command_creates_entity_to_article_backlink(): void
    {
        $customer                      = $this->createCustomer();
        [$run, $article, , , $entity]  = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $this->assertTrue($this->linkExists($customer, $entity, $article, EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_ARTICLE));
    }

    public function test_command_creates_summary_to_concept_link(): void
    {
        $customer                              = $this->createCustomer();
        [$run, , $summary, $concept]           = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $this->assertTrue($this->linkExists($customer, $summary, $concept, EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_CONCEPT));
    }

    public function test_command_creates_concept_to_summary_backlink(): void
    {
        $customer                     = $this->createCustomer();
        [$run, , $summary, $concept]  = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $this->assertTrue($this->linkExists($customer, $concept, $summary, EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_SUMMARY));
    }

    public function test_command_creates_summary_to_entity_link(): void
    {
        $customer                               = $this->createCustomer();
        [$run, , $summary, , $entity]           = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $this->assertTrue($this->linkExists($customer, $summary, $entity, EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ENTITY));
    }

    public function test_command_creates_entity_to_summary_backlink(): void
    {
        $customer                      = $this->createCustomer();
        [$run, , $summary, , $entity]  = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $this->assertTrue($this->linkExists($customer, $entity, $summary, EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_SUMMARY));
    }

    // =========================================================================
    // Idempotency
    // =========================================================================

    public function test_command_is_idempotent_on_rerun(): void
    {
        $customer  = $this->createCustomer();
        [$run]     = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);
        $countAfterFirst = EnterpriseWikiPageLink::query()->count();

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);
        $countAfterSecond = EnterpriseWikiPageLink::query()->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_command_reports_skipped_count_on_rerun(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);
        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Links skipped:', Artisan::output());
    }

    // =========================================================================
    // Edge cases: runs without certain page types
    // =========================================================================

    public function test_command_handles_run_without_concept_pages(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run      = $this->createRunApplied($customer, $document);

        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $summary = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        // article↔summary = 2 links, no concept/entity links
        $this->assertSame(2, EnterpriseWikiPageLink::query()->count());
        $this->assertTrue($this->linkExists($customer, $article, $summary, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY));
        $this->assertTrue($this->linkExists($customer, $summary, $article, EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ARTICLE));
    }

    public function test_command_handles_run_without_entity_pages(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run      = $this->createRunApplied($customer, $document);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Concept');

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        // article↔summary + article↔concept + summary↔concept = 2+2+2 = 6 links
        $this->assertSame(6, EnterpriseWikiPageLink::query()->count());
    }

    public function test_command_handles_page_without_current_version(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run      = $this->createRunApplied($customer, $document);

        // Article has a version; summary has no version
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $summary = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $this->addPageToRun($run, $summary);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        // Links are still created; from_page_version_id / to_page_version_id may be null
        $this->assertTrue(
            EnterpriseWikiPageLink::query()
                ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY)
                ->exists()
        );
        $this->assertStringContainsString('Missing versions: 1', Artisan::output());
    }

    // =========================================================================
    // Link field values
    // =========================================================================

    public function test_link_has_correct_customer_id(): void
    {
        $customer                  = $this->createCustomer();
        [$run, $article, $summary] = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $link = EnterpriseWikiPageLink::query()
            ->where('from_page_id', $article->id)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY)
            ->first();

        $this->assertSame($customer->id, $link->customer_id);
    }

    public function test_link_has_correct_source(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $link = EnterpriseWikiPageLink::query()->first();

        $this->assertSame(EnterpriseWikiPageLink::SOURCE_DETERMINISTIC, $link->source);
    }

    public function test_link_has_correct_from_and_to_page_ids(): void
    {
        $customer                  = $this->createCustomer();
        [$run, $article, $summary] = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $link = EnterpriseWikiPageLink::query()
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY)
            ->first();

        $this->assertSame($article->id, $link->from_page_id);
        $this->assertSame($summary->id, $link->to_page_id);
    }

    public function test_link_has_version_ids_when_versions_exist(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $link = EnterpriseWikiPageLink::query()
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY)
            ->first();

        $this->assertNotNull($link->from_page_version_id);
        $this->assertNotNull($link->to_page_version_id);
    }

    // =========================================================================
    // CLI output
    // =========================================================================

    public function test_command_outputs_pages_checked_count(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        // Full run has 4 pages (article + summary + concept + entity)
        $this->assertStringContainsString('Pages checked:    4', Artisan::output());
    }

    public function test_command_outputs_links_created_count(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        // 1 article × 1 summary: 2 links
        // 1 article × 1 concept: 2 links
        // 1 article × 1 entity:  2 links
        // 1 summary × 1 concept: 2 links
        // 1 summary × 1 entity:  2 links
        // Total: 10 links
        $this->assertStringContainsString('Links created:    10', Artisan::output());
    }

    public function test_command_outputs_links_skipped_count_on_rerun(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);
        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Links skipped:    10', Artisan::output());
    }

    // =========================================================================
    // No side effects
    // =========================================================================

    public function test_command_does_not_modify_run_status(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createFullRun($customer);

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $this->assertSame(
            EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            $run->fresh()->maintainer_decision_status,
        );
    }

    public function test_command_does_not_touch_claims(): void
    {
        $customer    = $this->createCustomer();
        [$run]       = $this->createFullRun($customer);
        $claimsBefore = EnterpriseWikiClaim::query()->count();

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
    }

    public function test_command_does_not_touch_source_references(): void
    {
        $customer  = $this->createCustomer();
        [$run]     = $this->createFullRun($customer);
        $refsBefore = EnterpriseWikiSourceReference::query()->count();

        Artisan::call('wiki:build-page-links', ['--run-id' => $run->id]);

        $this->assertSame($refsBefore, EnterpriseWikiSourceReference::query()->count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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
            'name'             => $name,
            'slug'             => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'language_id'      => $language->id,
            'nationality_id'   => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active'        => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id'       => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path'         => 'customers/' . $customer->id . '/wiki/' . Str::random(8) . '.pdf',
            'file_hash_sha256'  => hash('sha256', Str::random(32)),
            'extracted_text'    => 'Source text for link building tests.',
            'document_status'   => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createRunPending(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid'                             => Str::uuid()->toString(),
            'customer_id'                      => $customer->id,
            'trigger_type'                     => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                      => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                        => $document->id,
            'status'                           => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status'       => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function createRunApplied(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid'                             => Str::uuid()->toString(),
            'customer_id'                      => $customer->id,
            'trigger_type'                     => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                      => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                        => $document->id,
            'status'                           => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status'       => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id'      => $customer->id,
            'slug'             => Str::slug($title) . '-' . Str::lower(Str::random(4)),
            'title'            => $title,
            'page_type'        => $pageType,
            'status'           => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by'     => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function addPageToRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id'       => $page->id,
            'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);
    }

    private function createVersionedPage(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        string $pageType,
        string $title,
    ): EnterpriseWikiPage {
        $page = $this->createPage($customer, $pageType, $title);
        $this->addPageToRun($run, $page);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number'          => 1,
            'is_current'              => true,
            'content_markdown'        => "# {$title}\n\nContent.",
            'generated_by_model'      => 'gpt-5',
        ]);

        return $page;
    }

    /**
     * Applied run with article + summary + concept + entity, all with current versions.
     *
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPage, 3: EnterpriseWikiPage, 4: EnterpriseWikiPage, 5: EnterpriseWikiDocument}
     */
    private function createFullRun(Customer $customer): array
    {
        $document = $this->createDocument($customer);
        $run      = $this->createRunApplied($customer, $document);

        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Test Article');
        $summary = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Test Summary');
        $concept = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Test Concept');
        $entity  = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ENTITY, 'Test Entity');

        return [$run, $article, $summary, $concept, $entity, $document];
    }

    private function linkExists(
        Customer $customer,
        EnterpriseWikiPage $from,
        EnterpriseWikiPage $to,
        string $linkType,
    ): bool {
        return EnterpriseWikiPageLink::query()
            ->where('customer_id', $customer->id)
            ->where('from_page_id', $from->id)
            ->where('to_page_id', $to->id)
            ->where('link_type', $linkType)
            ->exists();
    }
}
