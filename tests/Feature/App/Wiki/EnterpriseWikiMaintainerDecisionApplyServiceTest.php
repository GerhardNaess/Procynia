<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionApplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiMaintainerDecisionApplyServiceTest extends TestCase
{
    use RefreshDatabase;

    private EnterpriseWikiMaintainerDecisionApplyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EnterpriseWikiMaintainerDecisionApplyService::class);
    }

    // =========================================================================
    // Page creation — page_type per entry
    // =========================================================================

    public function test_apply_creates_article_page_with_correct_page_type(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        $this->service->apply($run);

        $page = EnterpriseWikiPage::query()
            ->where('customer_id', $customer->id)
            ->where('page_type', EnterpriseWikiPage::PAGE_TYPE_ARTICLE)
            ->first();

        $this->assertNotNull($page, 'Article page should be created.');
        $this->assertSame('Test Artikkel', $page->title);
        $this->assertSame('test-artikkel-ab1c2d', $page->slug);
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status);
        $this->assertSame(EnterpriseWikiPage::GENERATED_BY_AI_JOB, $page->generated_by);
    }

    public function test_apply_creates_summary_page_with_correct_page_type(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        $this->service->apply($run);

        $page = EnterpriseWikiPage::query()
            ->where('customer_id', $customer->id)
            ->where('page_type', EnterpriseWikiPage::PAGE_TYPE_SUMMARY)
            ->first();

        $this->assertNotNull($page, 'Summary page should be created.');
        $this->assertSame('Sammendrag: Test Artikkel', $page->title);
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status);
    }

    public function test_apply_creates_concept_pages_with_correct_page_type(): void
    {
        $customer = $this->createCustomer();
        $decision = $this->baseDecision([
            'concept_pages' => [
                ['action' => 'create', 'page_id' => null, 'title' => 'Konsept A', 'proposed_slug' => 'konsept-a-xy1z', 'reason' => 'New concept.'],
                ['action' => 'create', 'page_id' => null, 'title' => 'Konsept B', 'proposed_slug' => 'konsept-b-xy2z', 'reason' => 'Another concept.'],
            ],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        $this->service->apply($run);

        $conceptPages = EnterpriseWikiPage::query()
            ->where('customer_id', $customer->id)
            ->where('page_type', EnterpriseWikiPage::PAGE_TYPE_CONCEPT)
            ->get();

        $this->assertCount(2, $conceptPages);
        $this->assertTrue($conceptPages->contains('slug', 'konsept-a-xy1z'));
        $this->assertTrue($conceptPages->contains('slug', 'konsept-b-xy2z'));
    }

    public function test_apply_creates_entity_pages_with_correct_page_type(): void
    {
        $customer = $this->createCustomer();
        $decision = $this->baseDecision([
            'entity_pages' => [
                ['action' => 'create', 'page_id' => null, 'title' => 'Entitet X', 'proposed_slug' => 'entitet-x-aa1b', 'reason' => 'New entity.'],
            ],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        $this->service->apply($run);

        $page = EnterpriseWikiPage::query()
            ->where('customer_id', $customer->id)
            ->where('page_type', EnterpriseWikiPage::PAGE_TYPE_ENTITY)
            ->first();

        $this->assertNotNull($page);
        $this->assertSame('Entitet X', $page->title);
    }

    // =========================================================================
    // update action
    // =========================================================================

    public function test_apply_update_action_uses_existing_page_and_creates_no_duplicate(): void
    {
        $customer = $this->createCustomer();
        $existingPage = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Eksisterende Konsept');
        $pagesBefore = EnterpriseWikiPage::query()->count();

        $decision = $this->baseDecision([
            'concept_pages' => [
                [
                    'action'        => 'update',
                    'page_id'       => $existingPage->id,
                    'title'         => 'Eksisterende Konsept',
                    'proposed_slug' => 'eksisterende-konsept-xy1z',
                    'reason'        => 'Updating.',
                ],
            ],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        $this->service->apply($run);

        $this->assertSame($pagesBefore + 2, EnterpriseWikiPage::query()->count(), 'Only source_article and source_summary should be created; concept page must not be duplicated.');
    }

    // =========================================================================
    // Pivot rows
    // =========================================================================

    public function test_apply_writes_pivot_rows_for_all_affected_pages(): void
    {
        $customer = $this->createCustomer();
        $decision = $this->baseDecision([
            'concept_pages' => [
                ['action' => 'create', 'page_id' => null, 'title' => 'Konsept', 'proposed_slug' => 'konsept-xy1z', 'reason' => 'New.'],
            ],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        $this->service->apply($run);

        // base decision has source_article + source_summary; plus 1 concept = 3 total
        $pivotCount = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->count();

        $this->assertSame(3, $pivotCount);
    }

    public function test_apply_create_pivot_row_has_action_created(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        $this->service->apply($run);

        $pivots = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->get();

        foreach ($pivots as $pivot) {
            $this->assertSame(EnterpriseWikiIngestRunPage::ACTION_CREATED, $pivot->action);
        }
    }

    public function test_apply_update_pivot_row_has_action_updated(): void
    {
        $customer = $this->createCustomer();
        $existingPage = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Delt Konsept');

        $decision = $this->baseDecision([
            'concept_pages' => [
                [
                    'action'        => 'update',
                    'page_id'       => $existingPage->id,
                    'title'         => 'Delt Konsept',
                    'proposed_slug' => 'delt-konsept-xy1z',
                    'reason'        => 'Updating.',
                ],
            ],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        $this->service->apply($run);

        $pivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $existingPage->id)
            ->first();

        $this->assertNotNull($pivot);
        $this->assertSame(EnterpriseWikiIngestRunPage::ACTION_UPDATED, $pivot->action);
    }

    // =========================================================================
    // Customer isolation
    // =========================================================================

    public function test_apply_customer_isolation_blocks_cross_customer_page_id(): void
    {
        $customer = $this->createCustomer('Eigen kunde');
        $other = $this->createCustomer('Annen kunde');
        $foreignPage = $this->createPage($other, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Fremmed Konsept');

        $decision = $this->baseDecision([
            'concept_pages' => [
                [
                    'action'        => 'update',
                    'page_id'       => $foreignPage->id,
                    'title'         => 'Fremmed Konsept',
                    'proposed_slug' => 'fremmed-konsept-xy1z',
                    'reason'        => 'Trying cross-customer update.',
                ],
            ],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found for customer/');

        $this->service->apply($run);
    }

    // =========================================================================
    // No side-effects (no page_versions / claims / source_references)
    // =========================================================================

    public function test_apply_does_not_create_page_versions(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        $versionsBefore = EnterpriseWikiPageVersion::query()->count();

        $this->service->apply($run);

        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());
    }

    public function test_apply_does_not_create_claims(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        $claimsBefore = EnterpriseWikiClaim::query()->count();

        $this->service->apply($run);

        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
    }

    public function test_apply_does_not_create_source_references(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        $refsBefore = EnterpriseWikiSourceReference::query()->count();

        $this->service->apply($run);

        $this->assertSame($refsBefore, EnterpriseWikiSourceReference::query()->count());
    }

    // =========================================================================
    // Idempotency and guard conditions
    // =========================================================================

    public function test_apply_sets_maintainer_decision_status_to_applied(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        $this->service->apply($run);

        $this->assertSame(
            EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            $run->fresh()->maintainer_decision_status
        );
    }

    public function test_apply_throws_when_already_applied(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());
        $this->service->apply($run);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already been applied/');

        $this->service->apply($run->fresh());
    }

    public function test_apply_throws_when_run_status_not_decision_only(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid'         => Str::uuid()->toString(),
            'customer_id'  => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'  => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'    => $document->id,
            'status'       => EnterpriseWikiIngestRun::STATUS_COMPLETED,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/expected \[decision_only, maintainer_decision, applying\]/');

        $this->service->apply($run);
    }

    public function test_apply_accepts_run_in_applying_status(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision(), EnterpriseWikiIngestRun::STATUS_APPLYING);

        $result = $this->service->apply($run);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(
            EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            $run->fresh()->maintainer_decision_status
        );
    }

    public function test_apply_throws_when_no_maintainer_decision_json(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid'                       => Str::uuid()->toString(),
            'customer_id'                => $customer->id,
            'trigger_type'               => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                  => $document->id,
            'status'                     => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no maintainer_decision_json/');

        $this->service->apply($run);
    }

    public function test_apply_returns_correct_created_and_updated_counts(): void
    {
        $customer = $this->createCustomer();
        $existingPage = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Delt Konsept');

        $decision = $this->baseDecision([
            'concept_pages' => [
                [
                    'action'        => 'update',
                    'page_id'       => $existingPage->id,
                    'title'         => 'Delt Konsept',
                    'proposed_slug' => 'delt-konsept-xy1z',
                    'reason'        => 'Updating.',
                ],
            ],
            'entity_pages' => [
                ['action' => 'create', 'page_id' => null, 'title' => 'Entitet Y', 'proposed_slug' => 'entitet-y-bb2c', 'reason' => 'New.'],
            ],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        $result = $this->service->apply($run);

        // source_article + source_summary + entity = 3 created; concept = 1 updated
        $this->assertSame(3, $result['created']);
        $this->assertSame(1, $result['updated']);
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
            'original_filename' => 'test.pdf',
            'file_path'         => 'customers/' . $customer->id . '/wiki/' . Str::random(8) . '.pdf',
            'file_hash_sha256'  => hash('sha256', Str::random(32)),
            'document_status'   => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
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

    private function createDecisionOnlyRun(
        Customer $customer,
        array $decision,
        string $status = EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
    ): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid'                             => Str::uuid()->toString(),
            'customer_id'                      => $customer->id,
            'trigger_type'                     => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                      => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                        => $document->id,
            'status'                           => $status,
            'maintainer_decision_json'         => $decision,
            'maintainer_decision_status'       => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function baseDecision(array $overrides = []): array
    {
        return array_merge([
            'source_article' => [
                'action'        => 'create',
                'title'         => 'Test Artikkel',
                'proposed_slug' => 'test-artikkel-ab1c2d',
                'reason'        => 'New article.',
            ],
            'source_summary' => [
                'action'        => 'create',
                'title'         => 'Sammendrag: Test Artikkel',
                'proposed_slug' => 'sammendrag-test-artikkel-ab1c2d',
                'reason'        => 'Companion summary.',
            ],
            'concept_pages'    => [],
            'entity_pages'     => [],
            'no_action_reason' => null,
            'warnings'         => [],
        ], $overrides);
    }
}
