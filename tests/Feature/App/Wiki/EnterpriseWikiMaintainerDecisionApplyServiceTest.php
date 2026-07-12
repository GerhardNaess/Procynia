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
    // Runtime fix: idempotent apply via canonical (customer_id, slug) lookup
    //
    // source_article/source_summary carry no page_id at all in the maintainer decision
    // schema (see EnterpriseWikiMaintainerDecisionPrompt) — only concept/entity entries do.
    // So an action=update decision for the source article/summary can never be resolved by
    // page_id; the (customer_id, slug) lookup is what makes reuse possible.
    // =========================================================================

    public function test_create_action_creates_page_when_slug_does_not_exist(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createDecisionOnlyRun($customer, $this->baseDecision());

        $result = $this->service->apply($run);

        $this->assertSame(2, $result['created']);
        $this->assertTrue(
            EnterpriseWikiPage::query()->where('customer_id', $customer->id)->where('slug', 'test-artikkel-ab1c2d')->exists()
        );
    }

    public function test_update_action_without_page_id_reuses_existing_page_by_slug(): void
    {
        $customer = $this->createCustomer();
        $existingArticle = $this->createPageWithSlug($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Test Artikkel', 'test-artikkel-ab1c2d');
        $pagesBefore = EnterpriseWikiPage::query()->count();

        $decision = $this->baseDecision([
            'source_article' => [
                'action' => 'update',
                'title' => 'Test Artikkel',
                'proposed_slug' => 'test-artikkel-ab1c2d',
                'reason' => 'Already created by an earlier, partially-completed run.',
            ],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        $result = $this->service->apply($run);

        // Only source_article is overridden above — source_summary in the base decision still
        // proposes a fresh slug, so it is genuinely created; the article must not be.
        $this->assertSame($pagesBefore + 1, EnterpriseWikiPage::query()->count());
        $this->assertSame(1, $result['updated']);

        $pivot = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $existingArticle->id)
            ->first();
        $this->assertNotNull($pivot);
        $this->assertSame(EnterpriseWikiIngestRunPage::ACTION_UPDATED, $pivot->action);
    }

    public function test_update_action_does_not_insert_when_slug_already_exists(): void
    {
        $customer = $this->createCustomer();
        $existingArticle = $this->createPageWithSlug($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Test Artikkel', 'test-artikkel-ab1c2d');

        $decision = $this->baseDecision([
            'source_article' => [
                'action' => 'update',
                'title' => 'Test Artikkel',
                'proposed_slug' => 'test-artikkel-ab1c2d',
                'reason' => 'Update, no page_id in schema for source pages.',
            ],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        $this->service->apply($run);

        $matching = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->where('slug', 'test-artikkel-ab1c2d')->get();
        $this->assertCount(1, $matching, 'update must reuse the existing row, never INSERT a second one.');
        $this->assertSame($existingArticle->id, $matching->first()->id);
    }

    public function test_create_action_reuses_existing_page_from_earlier_partially_completed_run(): void
    {
        $customer = $this->createCustomer();
        $existingSummary = $this->createPageWithSlug($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag: Test Artikkel', 'sammendrag-test-artikkel-ab1c2d');
        $pagesBefore = EnterpriseWikiPage::query()->count();

        // A regenerated decision for a new run proposed "create" again for the exact same
        // canonical slug — the existing canonical page must win over this stale assumption.
        $decision = $this->baseDecision([
            'source_summary' => [
                'action' => 'create',
                'title' => 'Sammendrag: Test Artikkel',
                'proposed_slug' => 'sammendrag-test-artikkel-ab1c2d',
                'reason' => 'New summary.',
            ],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        $result = $this->service->apply($run);

        // Only source_summary is overridden above — source_article in the base decision still
        // proposes a fresh slug, so it is genuinely created; the summary must not be.
        $this->assertSame($pagesBefore + 1, EnterpriseWikiPage::query()->count());
        $this->assertSame($existingSummary->id, EnterpriseWikiPage::query()->where('slug', 'sammendrag-test-artikkel-ab1c2d')->first()->id);
        $this->assertSame(1, $result['updated']);
    }

    public function test_existing_current_page_version_is_retained_on_reuse(): void
    {
        $customer = $this->createCustomer();
        $existingArticle = $this->createPageWithSlug($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Test Artikkel', 'test-artikkel-ab1c2d');
        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $existingArticle->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Test Artikkel\n\nOriginalt innhold.",
        ]);

        $decision = $this->baseDecision([
            'source_article' => [
                'action' => 'update',
                'title' => 'Test Artikkel',
                'proposed_slug' => 'test-artikkel-ab1c2d',
                'reason' => 'Reuse.',
            ],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        $this->service->apply($run);

        $version->refresh();
        $this->assertTrue((bool) $version->is_current);
        $this->assertSame("# Test Artikkel\n\nOriginalt innhold.", $version->content_markdown);
        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $existingArticle->id)->count());
    }

    public function test_existing_page_id_is_retained_even_when_decision_says_create(): void
    {
        $customer = $this->createCustomer();
        $existingArticle = $this->createPageWithSlug($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Test Artikkel', 'test-artikkel-ab1c2d');

        $decision = $this->baseDecision([
            'source_article' => [
                'action' => 'create',
                'title' => 'Test Artikkel',
                'proposed_slug' => 'test-artikkel-ab1c2d',
                'reason' => 'Stale create assumption from the decision AI.',
            ],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        $this->service->apply($run);

        $this->assertSame($existingArticle->id, EnterpriseWikiPage::query()->where('slug', 'test-artikkel-ab1c2d')->first()->id);
    }

    public function test_page_count_does_not_increase_on_retry_for_the_same_document(): void
    {
        $customer = $this->createCustomer();
        $decision = $this->baseDecision();

        $runA = $this->createDecisionOnlyRun($customer, $decision);
        $this->service->apply($runA);
        $countAfterFirst = EnterpriseWikiPage::query()->count();

        // Simulates a retry after runA failed downstream: a new run, same document, same
        // maintainer decision (still proposing "create" for both source pages).
        $runB = $this->createDecisionOnlyRun($customer, $decision);
        $this->service->apply($runB);

        $this->assertSame($countAfterFirst, EnterpriseWikiPage::query()->count());
    }

    public function test_pivot_is_created_for_the_new_run_when_an_existing_page_is_reused(): void
    {
        $customer = $this->createCustomer();
        $decision = $this->baseDecision();

        $runA = $this->createDecisionOnlyRun($customer, $decision);
        $this->service->apply($runA);

        $runB = $this->createDecisionOnlyRun($customer, $decision);
        $this->service->apply($runB);

        $pivotCountForRunB = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $runB->id)
            ->count();

        $this->assertSame(2, $pivotCountForRunB);
    }

    public function test_pivot_action_is_updated_for_every_reused_page(): void
    {
        $customer = $this->createCustomer();
        $decision = $this->baseDecision();

        $runA = $this->createDecisionOnlyRun($customer, $decision);
        $this->service->apply($runA);

        $runB = $this->createDecisionOnlyRun($customer, $decision);
        $this->service->apply($runB);

        $pivots = EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_ingest_run_id', $runB->id)->get();

        $this->assertCount(2, $pivots);
        foreach ($pivots as $pivot) {
            $this->assertSame(EnterpriseWikiIngestRunPage::ACTION_UPDATED, $pivot->action);
        }
    }

    public function test_same_run_cannot_get_a_duplicate_pivot_row_for_the_same_page(): void
    {
        $customer = $this->createCustomer();
        $existingPage = $this->createPageWithSlug($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Delt Side', 'delt-side-ab1c2d');

        // Two different decision entries resolve to the exact same existing page within one run.
        $decision = $this->baseDecision([
            'source_article' => ['action' => 'update', 'title' => 'Delt Side', 'proposed_slug' => 'delt-side-ab1c2d', 'reason' => 'x'],
            'concept_pages' => [
                ['action' => 'update', 'page_id' => $existingPage->id, 'title' => 'Delt Side', 'proposed_slug' => 'delt-side-ab1c2d', 'reason' => 'Same page referenced twice.'],
            ],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        $this->service->apply($run);

        $pivotCount = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $existingPage->id)
            ->count();

        $this->assertSame(1, $pivotCount);
    }

    public function test_article_reuse_by_slug_is_idempotent(): void
    {
        $customer = $this->createCustomer();
        $existing = $this->createPageWithSlug($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Test Artikkel', 'test-artikkel-ab1c2d');
        $decision = $this->baseDecision([
            'source_article' => ['action' => 'update', 'title' => 'Test Artikkel', 'proposed_slug' => 'test-artikkel-ab1c2d', 'reason' => 'x'],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        $this->service->apply($run);

        $this->assertSame($existing->id, EnterpriseWikiPage::query()->where('slug', 'test-artikkel-ab1c2d')->first()->id);
    }

    public function test_summary_reuse_by_slug_is_idempotent(): void
    {
        $customer = $this->createCustomer();
        $existing = $this->createPageWithSlug($customer, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag', 'sammendrag-test-artikkel-ab1c2d');
        $decision = $this->baseDecision([
            'source_summary' => ['action' => 'update', 'title' => 'Sammendrag', 'proposed_slug' => 'sammendrag-test-artikkel-ab1c2d', 'reason' => 'x'],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        $this->service->apply($run);

        $this->assertSame($existing->id, EnterpriseWikiPage::query()->where('slug', 'sammendrag-test-artikkel-ab1c2d')->first()->id);
    }

    public function test_concept_reuse_by_slug_is_idempotent_when_decision_says_create(): void
    {
        $customer = $this->createCustomer();
        $existing = $this->createPageWithSlug($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Konsept', 'konsept-xy1z');
        $decision = $this->baseDecision([
            'concept_pages' => [
                ['action' => 'create', 'page_id' => null, 'title' => 'Konsept', 'proposed_slug' => 'konsept-xy1z', 'reason' => 'Stale create.'],
            ],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        $this->service->apply($run);

        $this->assertSame(1, EnterpriseWikiPage::query()->where('slug', 'konsept-xy1z')->count());
        $this->assertSame($existing->id, EnterpriseWikiPage::query()->where('slug', 'konsept-xy1z')->first()->id);
    }

    public function test_entity_reuse_by_slug_is_idempotent_when_decision_says_create(): void
    {
        $customer = $this->createCustomer();
        $existing = $this->createPageWithSlug($customer, EnterpriseWikiPage::PAGE_TYPE_ENTITY, 'Entitet', 'entitet-xy1z');
        $decision = $this->baseDecision([
            'entity_pages' => [
                ['action' => 'create', 'page_id' => null, 'title' => 'Entitet', 'proposed_slug' => 'entitet-xy1z', 'reason' => 'Stale create.'],
            ],
        ]);
        $run = $this->createDecisionOnlyRun($customer, $decision);

        $this->service->apply($run);

        $this->assertSame(1, EnterpriseWikiPage::query()->where('slug', 'entitet-xy1z')->count());
        $this->assertSame($existing->id, EnterpriseWikiPage::query()->where('slug', 'entitet-xy1z')->first()->id);
    }

    public function test_slug_lookup_is_scoped_to_customer(): void
    {
        $customerA = $this->createCustomer('Kunde A');
        $customerB = $this->createCustomer('Kunde B');
        $this->createPageWithSlug($customerB, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Test Artikkel', 'test-artikkel-ab1c2d');

        $run = $this->createDecisionOnlyRun($customerA, $this->baseDecision());

        $result = $this->service->apply($run);

        // customer A has no page with this slug yet, so it must be created — never reused
        // from customer B's row, and never rejected as an unexpected duplicate.
        $this->assertSame(2, $result['created']);
        $ownPage = EnterpriseWikiPage::query()->where('customer_id', $customerA->id)->where('slug', 'test-artikkel-ab1c2d')->first();
        $this->assertNotNull($ownPage);
        $this->assertNotSame(
            EnterpriseWikiPage::query()->where('customer_id', $customerB->id)->where('slug', 'test-artikkel-ab1c2d')->first()->id,
            $ownPage->id,
        );
    }

    public function test_same_slug_at_another_customer_is_unaffected(): void
    {
        $customerA = $this->createCustomer('Kunde A');
        $customerB = $this->createCustomer('Kunde B');
        $existingB = $this->createPageWithSlug($customerB, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Test Artikkel', 'test-artikkel-ab1c2d');

        $run = $this->createDecisionOnlyRun($customerA, $this->baseDecision());
        $this->service->apply($run);

        $existingB->refresh();
        $this->assertSame('Test Artikkel', $existingB->title);
        $this->assertSame(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, $existingB->page_type);
        $this->assertSame(1, EnterpriseWikiPage::query()->where('customer_id', $customerB->id)->count());
    }

    public function test_unique_constraint_on_customer_and_slug_is_still_enforced(): void
    {
        $customer = $this->createCustomer();
        $this->createPageWithSlug($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Første', 'samme-slug-ab1c2d');

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Bypasses the apply service entirely — proves the migration-level constraint itself
        // is untouched, independent of the service's own idempotency logic.
        EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'samme-slug-ab1c2d',
            'title' => 'Andre',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    public function test_two_applies_proposing_the_same_new_slug_do_not_throw_or_duplicate(): void
    {
        // Simulates the outcome of a race between two concurrent apply() calls for the same
        // (customer_id, slug): the second one must reuse deterministically rather than throw
        // UniqueConstraintViolationException or invent a new slug.
        $customer = $this->createCustomer();
        $decision = $this->baseDecision();

        $runA = $this->createDecisionOnlyRun($customer, $decision);
        $runB = $this->createDecisionOnlyRun($customer, $decision);

        $this->service->apply($runA);
        $result = $this->service->apply($runB);

        $this->assertSame(0, $result['created']);
        $this->assertSame(2, $result['updated']);
        $this->assertSame(2, EnterpriseWikiPage::query()->where('customer_id', $customer->id)->count());
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

    /**
     * Like createPage(), but with an exact slug — needed to simulate a page an earlier,
     * partially-completed run already created under the same canonical slug the current
     * decision proposes.
     */
    private function createPageWithSlug(Customer $customer, string $pageType, string $title, string $slug): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id'      => $customer->id,
            'slug'             => $slug,
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
