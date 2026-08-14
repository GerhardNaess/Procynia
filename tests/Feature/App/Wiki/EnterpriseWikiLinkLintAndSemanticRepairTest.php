<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiIngest;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageLinkQaAttempt;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiLinkRevisionAiClient;
use App\Services\Ai\Wiki\WikiLinkSemanticQaAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
use App\Services\EnterpriseWiki\EnterpriseWikiGraphDataService;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkSemanticRepairService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageContentBlockService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageTraversalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 8I-6: deterministic wikilink lint codes, and AI-assisted semantic QA/repair of
 * inline wikilinks.
 */
class EnterpriseWikiLinkLintAndSemanticRepairTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Deterministic lint codes
    // =========================================================================

    public function test_broken_and_cross_customer_wikilink_is_detected(): void
    {
        $customerA = $this->createCustomer('Customer A');
        $customerB = $this->createCustomer('Customer B');
        $document = $this->createDocument($customerA);
        $foreignPage = $this->createPage($customerB, 'foreign-page', 'Foreign Page');
        $article = $this->createVersionedPage($customerA, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'See [[foreign-page]] here.');
        $run = $this->createAppliedRun($customerA, $document, [$article]);

        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_BROKEN_WIKILINK,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_CROSS_CUSTOMER_WIKILINK,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);

        $this->assertNotNull($foreignPage);
    }

    public function test_malformed_wikilink_is_detected(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'See [[]] here.');
        $run = $this->createAppliedRun($customer, $document, [$article]);

        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_MALFORMED_WIKILINK,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_self_wikilink_is_detected(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'See [[artikkel]] here.');
        $run = $this->createAppliedRun($customer, $document, [$article]);

        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_SELF_WIKILINK,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_persisted_wikilink_intent_marker_is_a_blocking_integrity_finding(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createVersionedPage(
            $customer,
            'artikkel',
            'Artikkel',
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'See {{wiki_link:intent-1|the target}} here.',
        );
        $run = $this->createAppliedRun($customer, $document, [$article]);

        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_UNMATERIALIZED_WIKILINK_MARKER,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_graph_connectivity_is_observed_without_creating_legacy_link_coverage_findings(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $conceptA = $this->createVersionedPage($customer, 'concept-a', 'Concept A', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'See [[concept-b|Concept B]].');
        $conceptB = $this->createVersionedPage($customer, 'concept-b', 'Concept B', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'See [[concept-a|Concept A]].');
        $article = $this->createVersionedPage($customer, 'article', 'Article', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'See [[concept-a|Concept A]] and [[summary|Summary]].');
        $summary = $this->createVersionedPage($customer, 'summary', 'Summary', EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'See [[concept-b|Concept B]] and [[article|Article]].');
        $run = $this->createAppliedRun($customer, $document, [$article, $summary, $conceptA, $conceptB]);

        app(EnterpriseWikiBuildPageLinksService::class)->materializeWikilinksForRun($run);

        $this->lintService()->lint($run);

        foreach ([
            EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE,
            EnterpriseWikiLintFinding::CODE_CONCEPT_WITHOUT_INCOMING_WIKILINK,
            EnterpriseWikiLintFinding::CODE_ARTICLE_WITHOUT_CONCEPT_OR_ENTITY_LINKS,
            EnterpriseWikiLintFinding::CODE_RUN_TARGETS_AVAILABLE_BUT_NOT_LINKED,
            EnterpriseWikiLintFinding::CODE_MISSING_REVERSE_LINK,
        ] as $code) {
            $this->assertDatabaseMissing('enterprise_wiki_lint_findings', [
                'enterprise_wiki_ingest_run_id' => $run->id,
                'code' => $code,
                'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            ]);
        }
    }

    public function test_missing_wikilink_materialization_is_detected(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $target = $this->createVersionedPage($customer, 'target-page', 'Target Page', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Target.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'See [[target-page|Target Page]] here.');
        $run = $this->createAppliedRun($customer, $document, [$article, $target]);

        // Deliberately do NOT materialize — the current version has a valid wikilink but zero
        // EnterpriseWikiPageLink rows exist yet.
        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_MISSING_WIKILINK_MATERIALIZATION,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_wikilink_projection_mismatch_is_detected_when_under_materialized(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $targetA = $this->createVersionedPage($customer, 'target-a', 'Target A', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A.');
        $targetB = $this->createVersionedPage($customer, 'target-b', 'Target B', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'B.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'See [[target-a]] and [[target-b]] here.');
        $run = $this->createAppliedRun($customer, $document, [$article, $targetA, $targetB]);

        // Materialize only one of the two valid links, simulating drift.
        $this->createPageLinkRow($customer, $article, $targetA, $run);

        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_WIKILINK_PROJECTION_MISMATCH,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_stale_wikilink_graph_edge_is_detected_when_over_materialized(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $target = $this->createVersionedPage($customer, 'target-page', 'Target Page', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Target.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This content no longer links to anything.');
        $run = $this->createAppliedRun($customer, $document, [$article, $target]);

        // A materialized row exists from an earlier version, but the current content dropped it.
        $this->createPageLinkRow($customer, $article, $target, $run);

        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_STALE_WIKILINK_GRAPH_EDGE,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_stale_projection_is_resolved_deterministically_after_rematerializing(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $target = $this->createVersionedPage($customer, 'target-page', 'Target Page', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Target.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'No links here anymore.');
        $run = $this->createAppliedRun($customer, $document, [$article, $target]);

        $this->createPageLinkRow($customer, $article, $target, $run);

        $this->lintService()->lint($run);
        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_STALE_WIKILINK_GRAPH_EDGE,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);

        // Deterministic repair: re-materializing from current content_markdown (no AI involved)
        // removes the stale row.
        app(EnterpriseWikiBuildPageLinksService::class)->materializeWikilinksForPage($article, $run->id);
        $this->lintService()->lint($run);

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_STALE_WIKILINK_GRAPH_EDGE,
            'status' => EnterpriseWikiLintFinding::STATUS_RESOLVED,
        ]);
    }

    // =========================================================================
    // Semantic QA + repair
    // =========================================================================

    public function test_cancelled_run_skips_semantic_repair_without_ai_or_writes(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Original content.');
        $run = $this->createAppliedRun($customer, $document, [$article]);
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_CANCELLED]);

        $this->mock(WikiLinkSemanticQaAiClient::class)->shouldNotReceive('review');
        $this->mock(WikiLinkRevisionAiClient::class)->shouldNotReceive('reviseLinks');

        $result = $this->repairService()->repairForRun($run->fresh());

        $this->assertSame(['pages_reviewed' => 0, 'applied' => 0, 'skipped' => 0, 'failed' => 0], $result);
        $this->assertDatabaseCount('enterprise_wiki_page_link_qa_attempts', 0);
        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count());
    }

    public function test_semantic_qa_recommends_a_missing_link_and_repair_adds_it(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This mentions Konsept without linking it.');
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        $this->mockRevision('This mentions [[konsept|Konsept]] without linking it.', changed: true);

        $result = $this->repairService()->repairForRun($run);

        $this->assertSame(1, $result['applied']);
        $this->assertDatabaseHas('enterprise_wiki_page_links', [
            'customer_id' => $customer->id,
            'from_page_id' => $article->id,
            'to_page_id' => $concept->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
        ]);
    }

    public function test_repair_removes_a_flagged_invalid_link(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This [[konsept|Konsept]] link is wrong here.');
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: [], remove: ['konsept']);
        $this->mockRevision('This Konsept link is wrong here.', changed: true);

        $result = $this->repairService()->repairForRun($run);

        $this->assertSame(1, $result['applied']);
        $this->assertDatabaseMissing('enterprise_wiki_page_links', [
            'customer_id' => $customer->id,
            'from_page_id' => $article->id,
            'to_page_id' => $concept->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
        ]);
    }

    public function test_repair_rejects_an_unrequested_added_link(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $other = $this->createVersionedPage($customer, 'annen-side', 'Annen Side', EnterpriseWikiPage::PAGE_TYPE_ENTITY, 'An entity.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This mentions Konsept without linking it.');
        $run = $this->createAppliedRun($customer, $document, [$article, $concept, $other]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        // AI links the wrong (unrequested) target instead of the one asked for.
        $this->mockRevision('This mentions [[annen-side|Konsept]] without linking it.', changed: true);

        $result = $this->repairService()->repairForRun($run);

        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseMissing('enterprise_wiki_page_links', [
            'from_page_id' => $article->id,
            'to_page_id' => $other->id,
        ]);
        $this->assertSame(1, $this->currentVersion($article)->version_number);
    }

    public function test_repair_rejects_a_revision_introducing_a_broken_link(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This mentions Konsept without linking it.');
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        $this->mockRevision('This mentions [[does-not-exist|Konsept]] without linking it.', changed: true);

        $result = $this->repairService()->repairForRun($run);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $this->currentVersion($article)->version_number);
    }

    public function test_repair_rejects_a_revision_introducing_a_self_link(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This mentions Konsept without linking it.');
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        $this->mockRevision('This mentions [[artikkel|Konsept]] without linking it.', changed: true);

        $result = $this->repairService()->repairForRun($run);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $this->currentVersion($article)->version_number);
    }

    #[DataProvider('structuralHeadingMutations')]
    public function test_repair_rejects_any_structural_heading_mutation(string $original, string $revised): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $serviceManagement = $this->createVersionedPage($customer, 'service-management', 'Service Management', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, $original);
        $run = $this->createAppliedRun($customer, $document, [$article, $concept, $serviceManagement]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept', 'service-management'], remove: []);
        $this->mockRevision($revised, changed: true);

        $result = $this->repairService()->repairForRun($run);

        $this->assertSame(1, $result['failed']);
        $this->assertSame($original, $this->currentVersion($article)->content_markdown);
        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count());
        $this->assertDatabaseHas('enterprise_wiki_page_link_qa_attempts', [
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $article->id,
            'status' => EnterpriseWikiPageLinkQaAttempt::STATUS_FAILED,
            'reason' => EnterpriseWikiPageLinkQaAttempt::REASON_INVALID_REVISION,
        ]);
    }

    /** @return array<string, array{string, string}> */
    public static function structuralHeadingMutations(): array
    {
        $original = "# Service Management\n\n## Problem review roles\n\n### Review preparation\n\nThe service owner participates in the review.";

        return [
            'H1 wikilink insertion' => [$original, "# [[service-management|Service Management]]\n\n## Problem review roles\n\n### Review preparation\n\nThe service owner participates in the review."],
            'H2 wikilink insertion' => [$original, "# Service Management\n\n## Problem review [[konsept|roles]]\n\n### Review preparation\n\nThe service owner participates in the review."],
            'H3 wikilink insertion' => [$original, "# Service Management\n\n## Problem review roles\n\n### Review [[konsept|preparation]]\n\nThe service owner participates in the review."],
            'heading rename' => [$original, "# Service Management\n\n## Problem review responsibilities\n\n### Review preparation\n\nThe service owner participates in the review."],
            'heading deletion' => [$original, "# Service Management\n\n### Review preparation\n\nThe service owner participates in the review."],
            'heading insertion' => [$original, "# Service Management\n\n## Problem review roles\n\n## Extra section\n\n### Review preparation\n\nThe service owner participates in the review."],
            'heading reorder' => [$original, "# Service Management\n\n### Review preparation\n\n## Problem review roles\n\nThe service owner participates in the review."],
            'heading level change' => [$original, "# Service Management\n\n### Problem review roles\n\n### Review preparation\n\nThe service owner participates in the review."],
        ];
    }

    public function test_repair_allows_a_body_wikilink_without_changing_headings_and_keeps_planned_section_lint_green(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'service-owner', 'Service Owner', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $original = "# Service Management\n\n## Minimum content and review roles\n\nThe service owner participates in the review.";
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, $original);
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);
        $run->update(['maintainer_decision_json' => [
            'source_article' => [
                'proposed_slug' => $article->slug,
                'owned_topics' => ['Minimum content and review roles'],
            ],
        ]]);

        $revised = "# Service Management\n\n## Minimum content and review roles\n\nThe [[service-owner|service owner]] participates in the review.";
        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['service-owner'], remove: []);
        $this->mockRevision($revised, changed: true);

        $result = $this->repairService()->repairForRun($run);

        $this->assertSame(1, $result['applied']);
        $this->assertSame($revised, $this->currentVersion($article)->content_markdown);
        $this->assertDatabaseHas('enterprise_wiki_page_links', [
            'from_page_id' => $article->id,
            'to_page_id' => $concept->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
        ]);
        $this->assertDatabaseMissing('enterprise_wiki_lint_findings', [
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_MISSING,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_run_39_style_heading_mutation_rejects_the_entire_repair_atomically(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $roles = $this->createVersionedPage($customer, 'review-roles', 'Review Roles', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $original = "# Problem Management\n\n## Minimum content and review roles\n\nReview roles are recorded for every problem.";
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, $original);
        $run = $this->createAppliedRun($customer, $document, [$article, $roles]);
        $run->update(['maintainer_decision_json' => [
            'source_article' => [
                'proposed_slug' => $article->slug,
                'owned_topics' => ['Minimum content and review roles'],
            ],
        ]]);

        $revised = "# Problem Management\n\n## Minimum content and [[review-roles|review roles]]\n\nReview [[review-roles|roles]] are recorded for every problem.";
        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['review-roles'], remove: []);
        $this->mockRevision($revised, changed: true);

        $result = $this->repairService()->repairForRun($run);

        $this->assertSame(1, $result['failed']);
        $this->assertSame($original, $this->currentVersion($article)->content_markdown);
        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count());
        $this->assertDatabaseMissing('enterprise_wiki_page_links', [
            'from_page_id' => $article->id,
            'to_page_id' => $roles->id,
        ]);

        $this->lintService()->lint($run->fresh());
        $this->assertDatabaseMissing('enterprise_wiki_lint_findings', [
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_MISSING,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_no_change_assessment_creates_no_version(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This content is fine as-is.');
        $run = $this->createAppliedRun($customer, $document, [$article]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'no_change', missing: [], remove: []);

        $result = $this->repairService()->repairForRun($run);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $this->currentVersion($article)->version_number);
    }

    public function test_changed_repair_creates_exactly_one_version_and_rematerializes(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This mentions Konsept without linking it.');
        $originalVersionId = $this->currentVersion($article)->id;
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        $this->mockRevision('This mentions [[konsept|Konsept]] without linking it.', changed: true);

        $this->repairService()->repairForRun($run);

        $this->assertSame(
            2,
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count(),
        );

        $oldVersion = EnterpriseWikiPageVersion::query()->find($originalVersionId);
        $this->assertFalse($oldVersion->is_current);

        $incoming = app(EnterpriseWikiPageTraversalService::class)->incoming($concept);
        $this->assertTrue($incoming->pluck('id')->contains($article->id));

        $graph = app(EnterpriseWikiGraphDataService::class)->build($customer->id, EnterpriseWikiPage::STATUSES, pageId: $concept->id);
        $edgeExists = collect($graph['edges'])->contains(
            fn (array $edge) => $edge['from_page_id'] === $article->id && $edge['to_page_id'] === $concept->id,
        );
        $this->assertTrue($edgeExists);
    }

    public function test_double_repair_does_not_create_a_second_version(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This mentions Konsept without linking it.');
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        $this->mockRevision('This mentions [[konsept|Konsept]] without linking it.', changed: true, times: 1);

        $this->repairService()->repairForRun($run);
        $this->repairService()->repairForRun($run);

        $this->assertSame(
            2,
            EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->count(),
        );
        $this->assertSame(
            1,
            EnterpriseWikiPageLinkQaAttempt::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('enterprise_wiki_page_id', $article->id)
                ->count(),
        );
    }

    public function test_a_new_lint_round_is_green_after_repair(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');
        $article = $this->createVersionedPage($customer, 'artikkel', 'Artikkel', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'This mentions Konsept without linking it.');
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        // Semantic QA may identify a real missing relation, but the absence of any link is not
        // itself a lint defect merely because another run page exists.
        $this->lintService()->lint($run);
        $this->assertDatabaseMissing('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_RUN_TARGETS_AVAILABLE_BUT_NOT_LINKED,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        $this->mockRevision('This mentions [[konsept|Konsept]] without linking it.', changed: true);

        // repairForRun() re-runs deterministic lint internally after applying the repair.
        $this->repairService()->repairForRun($run);

        $this->assertDatabaseMissing('enterprise_wiki_lint_findings', [
            'enterprise_wiki_page_id' => $article->id,
            'code' => EnterpriseWikiLintFinding::CODE_RUN_TARGETS_AVAILABLE_BUT_NOT_LINKED,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    // =========================================================================
    // Block provenance preserved after semantic repair (regression: a link-semantic repair used
    // to write a new current version with content_markdown only, never content_blocks_json —
    // see EnterpriseWikiPageVersionBlockProvenanceRepairService's docblock and
    // EnterpriseWikiRepairPageVersionBlockProvenanceCommandTest for the manual-sweep coverage of
    // the exact same drift).
    // =========================================================================

    public function test_repair_restores_block_provenance_for_the_new_version(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');

        $blocks = [
            $this->sourceBasedBlock('block-0001', 0, 'Første avsnitt om Konsept uten lenke ennå.'),
            $this->sourceBasedBlock('block-0002', 1, 'Andre avsnitt med mer innhold.'),
        ];
        $article = $this->createVersionedPage(
            $customer,
            'artikkel',
            'Artikkel',
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            "Første avsnitt om Konsept uten lenke ennå.\n\nAndre avsnitt med mer innhold.",
            $blocks,
        );
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        $this->mockRevision(
            "Første avsnitt om [[konsept|Konsept]] uten lenke ennå.\n\nAndre avsnitt med mer innhold.",
            changed: true,
        );

        $result = $this->repairService()->repairForRun($run);

        $this->assertSame(1, $result['applied']);

        $newVersion = $this->currentVersion($article);
        $newBlocks = collect($newVersion->content_blocks_json);

        $this->assertCount(2, $newBlocks);
        $this->assertSame('source_based', $newBlocks->firstWhere('block_key', 'block-0001')['content_origin']);
        $this->assertSame('source_based', $newBlocks->firstWhere('block_key', 'block-0002')['content_origin']);
        $this->assertNotEmpty($newBlocks->firstWhere('block_key', 'block-0001')['source_elements']);
    }

    public function test_restored_blocks_let_a_real_excerpt_anchor_to_the_correct_block(): void
    {
        // Proves the fix actually unblocks downstream claim anchoring — not just that
        // content_blocks_json is non-empty — by running the exact same matching method claim
        // extraction uses (EnterpriseWikiPageContentBlockService::findUniqueBlockForExcerpt())
        // against the newly repaired version.
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');

        $blocks = [
            $this->sourceBasedBlock('block-0001', 0, 'Første avsnitt om Konsept uten lenke ennå.'),
            $this->sourceBasedBlock('block-0002', 1, 'Andre avsnitt med mer innhold.'),
        ];
        $article = $this->createVersionedPage(
            $customer,
            'artikkel',
            'Artikkel',
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            "Første avsnitt om Konsept uten lenke ennå.\n\nAndre avsnitt med mer innhold.",
            $blocks,
        );
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        $this->mockRevision(
            "Første avsnitt om [[konsept|Konsept]] uten lenke ennå.\n\nAndre avsnitt med mer innhold.",
            changed: true,
        );

        $this->repairService()->repairForRun($run);

        $newVersion = $this->currentVersion($article);
        $match = app(EnterpriseWikiPageContentBlockService::class)
            ->findUniqueBlockForExcerpt($newVersion, 'Andre avsnitt med mer innhold.');

        $this->assertNotNull($match);
        $this->assertSame('block-0002', $match['block_key']);
    }

    public function test_a_revision_that_would_drop_the_pages_blocks_is_declined_rather_than_promoted(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');

        $blocks = [
            $this->sourceBasedBlock('block-0001', 0, 'Første avsnitt.'),
            $this->sourceBasedBlock('block-0002', 1, 'Andre avsnitt om Konsept.'),
        ];
        $article = $this->createVersionedPage(
            $customer,
            'artikkel',
            'Artikkel',
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            "Første avsnitt.\n\nAndre avsnitt om Konsept.",
            $blocks,
        );
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        // The revision merges both paragraphs into a single segment — segment count (1) no
        // longer matches the prior block count (2), so reconstruction must refuse rather than
        // guess. Promoting the revision anyway would leave the page with NO content blocks, which
        // is where its image figures, source provenance and claim anchors live: run 54 lost a
        // required figure exactly this way. The link improvement is declined instead, and the page
        // keeps the version that still has its blocks.
        $this->mockRevision('Første avsnitt. Andre avsnitt om [[konsept|Konsept]].', changed: true);

        $result = $this->repairService()->repairForRun($run);

        $this->assertSame(0, $result['applied']);
        $this->assertCount(2, $this->currentVersion($article)->content_blocks_json ?? []);
        $this->assertStringNotContainsString('[[konsept|Konsept]]', (string) $this->currentVersion($article)->content_markdown);

        $attempt = EnterpriseWikiPageLinkQaAttempt::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->latest('id')
            ->first();

        $this->assertSame(EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED, $attempt->status);
        $this->assertSame(EnterpriseWikiPageLinkQaAttempt::REASON_BLOCK_PROVENANCE_AT_RISK, $attempt->reason);
    }

    public function test_prior_version_blocks_are_never_touched_by_block_provenance_restore(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $concept = $this->createVersionedPage($customer, 'konsept', 'Konsept', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'A concept.');

        $blocks = [$this->sourceBasedBlock('block-0001', 0, 'Innhold om Konsept uten lenke.')];
        $article = $this->createVersionedPage(
            $customer,
            'artikkel',
            'Artikkel',
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'Innhold om Konsept uten lenke.',
            $blocks,
        );
        $originalVersion = $this->currentVersion($article);
        $run = $this->createAppliedRun($customer, $document, [$article, $concept]);

        $this->mockQaReview(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'repair_recommended', missing: ['konsept'], remove: []);
        $this->mockRevision('Innhold om [[konsept|Konsept]] uten lenke.', changed: true);

        $this->repairService()->repairForRun($run);

        $originalVersion->refresh();
        $this->assertFalse((bool) $originalVersion->is_current);
        $this->assertSame($blocks, $originalVersion->content_blocks_json);
    }

    public function test_process_enterprise_wiki_ingest_not_modified(): void
    {
        $reflection = new \ReflectionClass(ProcessEnterpriseWikiIngest::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString('EnterpriseWikiLinkSemanticRepairService', $source);
        $this->assertStringNotContainsString('WikiLinkSemanticQaAiClient', $source);
        $this->assertStringNotContainsString('repairForRun', $source);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function lintService(): EnterpriseWikiAppliedRunLintService
    {
        return app(EnterpriseWikiAppliedRunLintService::class);
    }

    private function repairService(): EnterpriseWikiLinkSemanticRepairService
    {
        return app(EnterpriseWikiLinkSemanticRepairService::class);
    }

    /**
     * Mocks WikiLinkSemanticQaAiClient::review() so that only the given page type gets the
     * requested assessment — every other page reviewed in the same run (repairForRun() reviews
     * every pivot page) gets a neutral "no_change" response instead.
     */
    private function mockQaReview(string $targetPageType, string $assessment, array $missing, array $remove): void
    {
        $this->mock(WikiLinkSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturnUsing(function (string $content, string $pageType) use ($targetPageType, $assessment, $missing, $remove): array {
                if ($pageType !== $targetPageType) {
                    return [
                        'assessment' => 'no_change',
                        'missing_link_slugs' => [],
                        'remove_link_slugs' => [],
                        'critique' => '',
                        'model' => 'gpt-4.1-mini/1.0',
                    ];
                }

                return [
                    'assessment' => $assessment,
                    'missing_link_slugs' => $missing,
                    'remove_link_slugs' => $remove,
                    'critique' => 'Test critique.',
                    'model' => 'gpt-4.1-mini/1.0',
                ];
            });
    }

    private function mockRevision(string $markdown, bool $changed, int $times = 1): void
    {
        $this->mock(WikiLinkRevisionAiClient::class)
            ->shouldReceive('reviseLinks')
            ->times($times)
            ->andReturn(['changed' => $changed, 'markdown' => $markdown]);
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    private function createPageLinkRow(
        Customer $customer,
        EnterpriseWikiPage $from,
        EnterpriseWikiPage $to,
        EnterpriseWikiIngestRun $run,
    ): EnterpriseWikiPageLink {
        return EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'from_page_id' => $from->id,
            'to_page_id' => $to->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);
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
     * @param  list<array<string, mixed>>|null  $blocks
     */
    private function createVersionedPage(
        Customer $customer,
        string $slug,
        string $title,
        string $pageType,
        string $content,
        ?array $blocks = null,
    ): EnterpriseWikiPage {
        $page = $this->createPage($customer, $slug, $title, $pageType);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $content,
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }

    private function sourceBasedBlock(string $blockKey, int $position, string $markdown): array
    {
        return [
            'block_key' => $blockKey,
            'position' => $position,
            'markdown' => $markdown,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_id' => 456,
            'source_label' => 'source.docx',
            'source_elements' => [[
                'source_type' => 'enterprise_wiki_document',
                'source_id' => 456,
                'source_element_key' => 'paragraph-'.$position,
                'source_excerpt' => $markdown,
            ]],
        ];
    }

    private function createAppliedRun(Customer $customer, EnterpriseWikiDocument $document, array $pages): EnterpriseWikiIngestRun
    {
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
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
