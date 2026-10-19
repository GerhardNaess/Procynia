<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\EnterpriseWikiPageUnderReviewException;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\User;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionApplyService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageVersionWriter;
use App\Services\EnterpriseWiki\EnterpriseWikiRunFindingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * A page in pending_review keeps its working version, reviewer and review history through every
 * automated step of a run (the generation and patch paths are covered next to their own fixtures,
 * in GenerateEnterpriseWikiAppliedPageJobTest and EnterpriseWikiPatchApplicationTest).
 *
 * The review assignment lives on the submitted version, so any machine-written version under an
 * open review strands it: the reviewer can no longer approve ("missing_assignment").
 */
class EnterpriseWikiOpenReviewProtectionTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    public function test_the_version_writer_refuses_an_automated_write_under_an_open_review(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->pageInReview($customer, 'Under gjennomgang');
        $submitted = $page->currentVersion;

        try {
            app(EnterpriseWikiPageVersionWriter::class)->writeNewCurrentVersion($page->id, ['content_markdown' => '# Maskinskrevet']);
            $this->fail('Expected the writer to refuse.');
        } catch (EnterpriseWikiPageUnderReviewException $e) {
            $this->assertSame($page->id, $e->pageId);
        }

        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->count());
        $this->assertSame($submitted->id, $page->fresh()->currentVersion->id);
        $this->assertTrue($submitted->fresh()->is_current);
    }

    public function test_a_write_made_on_a_persons_behalf_is_not_refused(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->pageInReview($customer, 'Under gjennomgang');

        $version = app(EnterpriseWikiPageVersionWriter::class)->writeNewCurrentVersion(
            $page->id,
            ['content_markdown' => '# Redigert av en person'],
            allowDuringReview: true,
        );

        $this->assertTrue($version->is_current);
        $this->assertSame(2, $version->version_number);
    }

    public function test_the_version_writer_still_writes_a_page_that_is_not_in_review(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Utkast', '# Utkast');

        $version = app(EnterpriseWikiPageVersionWriter::class)->writeNewCurrentVersion($page->id, ['content_markdown' => '# Ny versjon']);

        $this->assertTrue($version->is_current);
        $this->assertSame($version->id, $page->fresh()->currentVersion->id);
    }

    public function test_an_ai_update_of_a_page_in_review_is_deferred_on_that_page_never_rerouted_to_a_new_one(): void
    {
        Queue::fake();
        $customer = $this->createWikiCustomer();
        $concept = $this->pageInReview($customer, 'Incident Commander', ['page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT]);
        $sameSlug = $this->pageInReview($customer, 'Deteksjonsgap', ['page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT]);
        $hashesBefore = [$concept->last_source_hash, $sameSlug->last_source_hash];
        $pagesBefore = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->count();

        $run = $this->decisionOnlyRun($customer, [
            'source_article' => ['action' => 'create', 'title' => 'Et Dokument', 'proposed_slug' => 'et-dokument-ab1c2d', 'reason' => 'r'],
            'source_summary' => ['action' => 'create', 'title' => 'Sammendrag', 'proposed_slug' => 'sammendrag-ab1c2d', 'reason' => 'r'],
            'concept_pages' => [
                // Named by id …
                $this->conceptEntry('update', $concept->title, $concept->slug, $concept->id),
                // … and proposed as "new" under the existing page's exact slug.
                $this->conceptEntry('create', $sameSlug->title, $sameSlug->slug),
            ],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ]);

        app(EnterpriseWikiMaintainerDecisionApplyService::class)->apply($run);

        // Only the run's own article and summary are new — no look-alike concept appears.
        $this->assertSame($pagesBefore + 2, EnterpriseWikiPage::query()->where('customer_id', $customer->id)->count());

        foreach ([$concept, $sameSlug] as $index => $page) {
            $pivot = EnterpriseWikiIngestRunPage::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('enterprise_wiki_page_id', $page->id)
                ->first();

            $this->assertNotNull($pivot, 'the deferred update is recorded on the existing page');
            $this->assertSame(EnterpriseWikiIngestRunPage::ACTION_UPDATED, $pivot->action);

            $fresh = $page->fresh();
            $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $fresh->status);
            $this->assertSame($hashesBefore[$index], $fresh->last_source_hash, 'nothing on the page row moves while it is in review');
            $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->count());
        }
    }

    public function test_claim_extraction_leaves_a_page_in_review_alone(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->pageInReview($customer, 'Under gjennomgang');
        $run = $this->runWithPivot($customer, $page, EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED);

        $this->mock(WikiPageClaimExtractionAiClient::class)->shouldNotReceive('extractClaims');

        app(EnterpriseWikiExtractPageClaimsService::class)->extract($run);

        $this->assertFalse(EnterpriseWikiClaim::query()->where('enterprise_wiki_page_id', $page->id)->exists());
        $this->assertNull(
            EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_ingest_run_id', $run->id)->value('claims_extracted_at'),
            'no checkpoint: the run stays honestly incomplete for this page',
        );
    }

    public function test_the_run_findings_explain_the_deferred_update_by_page_name(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->pageInReview($customer, 'Incident Commander');
        $run = $this->runWithPivot($customer, $page, EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED, [
            'generation_error' => '[EnterpriseWikiPageUnderReviewException] '.(new EnterpriseWikiPageUnderReviewException($page->id))->getMessage(),
        ]);
        $run->update([
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
        ]);

        app()->setLocale('no');
        $explanation = app(EnterpriseWikiRunFindingsService::class)->buildForRun($run->fresh(), null, false)['summary']['explanation'];

        $this->assertSame('Oppdatering av «Incident Commander» ble ikke gjennomført fordi artikkelen er til gjennomgang.', $explanation);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * The state submit() leaves behind: a working version handed to a named reviewer, the page
     * pending_review.
     */
    private function pageInReview(Customer $customer, string $title, array $pageOverrides = []): EnterpriseWikiPage
    {
        $page = $this->createWikiPageWithVersion($customer, $title, '# '.$title, $pageOverrides, [
            'submitted_by_user_id' => $this->createUser($customer)->id,
            'submitted_at' => now(),
            'reviewer_user_id' => $this->createUser($customer)->id,
        ]);

        $page->forceFill(['status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW])->save();

        return $page->fresh();
    }

    private function createUser(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Review User',
            'email' => Str::lower(Str::random(10)).'@test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
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
            'extracted_text' => 'Kildetekst for vurderingsbeskyttelse.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function decisionOnlyRun(Customer $customer, array $decision): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $this->createDocument($customer)->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_json' => $decision,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function runWithPivot(Customer $customer, EnterpriseWikiPage $page, string $generationStatus, array $pivotOverrides = []): EnterpriseWikiIngestRun
    {
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $this->createDocument($customer)->id,
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);

        EnterpriseWikiIngestRunPage::query()->create(array_merge([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_UPDATED,
            'generation_status' => $generationStatus,
        ], $pivotOverrides));

        return $run;
    }

    /** @return array<string, mixed> */
    private function conceptEntry(string $action, string $title, string $slug, ?int $pageId = null): array
    {
        return [
            'action' => $action,
            'page_id' => $pageId,
            'title' => $title,
            'proposed_slug' => $slug,
            'reason' => 'Dokumentet berører denne siden.',
            'owned_topics' => [],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ];
    }
}
