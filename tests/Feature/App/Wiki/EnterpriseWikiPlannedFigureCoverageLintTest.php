<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wiki run-587: EnterpriseWikiAppliedRunLintService::checkPlannedFigureCoverage() is the
 * defense-in-depth layer behind EnterpriseWikiGenerateAppliedPagesService's inline generation-time
 * figure check — it re-checks whatever content actually persisted against the page's own
 * planned_figures, and a required-figure finding blocks qa_status from ever reaching "passed" via
 * the existing EnterpriseWikiLintFinding::isBlocking() predicate (severity=error) — no new QA logic
 * was introduced.
 */
class EnterpriseWikiPlannedFigureCoverageLintTest extends TestCase
{
    use RefreshDatabase;

    public function test_lint_creates_a_blocking_finding_for_a_missing_required_figure(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, [$this->plannedFigure('img1', required: true)], contentMarkdown: '# Concept');
        $page = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->firstOrFail();

        $counts = app(EnterpriseWikiAppliedRunLintService::class)->lint($run);

        $this->assertSame(1, $counts['errors']);

        $finding = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING)
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame(EnterpriseWikiLintFinding::SEVERITY_ERROR, $finding->severity);
        $this->assertStringContainsString('img1', $finding->message);
        $this->assertTrue($finding->isBlocking());
    }

    public function test_open_required_figure_finding_blocks_qa_from_passing(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, [$this->plannedFigure('img1', required: true)], contentMarkdown: '# Concept');

        app(EnterpriseWikiAppliedRunLintService::class)->lint($run);
        $this->markStepsComplete($run);

        $evaluation = app(EnterpriseWikiPostIngestQaService::class)->evaluate($run->fresh());

        $this->assertContains('critical_lint_findings_or_broken_links', $evaluation['critical_defects']);
        $this->assertNotSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $evaluation['verdict']);
    }

    public function test_missing_optional_figure_is_a_warning_and_does_not_block_qa(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, [$this->plannedFigure('img1', required: false)], contentMarkdown: '# Concept');
        $page = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->firstOrFail();

        $counts = app(EnterpriseWikiAppliedRunLintService::class)->lint($run);

        $this->assertSame(0, $counts['errors']);

        $finding = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING)
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame(EnterpriseWikiLintFinding::SEVERITY_WARNING, $finding->severity);
        $this->assertFalse($finding->isBlocking());

        $this->markStepsComplete($run);
        $evaluation = app(EnterpriseWikiPostIngestQaService::class)->evaluate($run->fresh());

        $this->assertNotContains('critical_lint_findings_or_broken_links', $evaluation['critical_defects']);
    }

    public function test_finding_is_resolved_once_a_new_version_actually_materializes_the_figure(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, [$this->plannedFigure('img1', required: true)], contentMarkdown: '# Concept');
        $page = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->firstOrFail();

        $lintService = app(EnterpriseWikiAppliedRunLintService::class);
        $lintService->lint($run);

        $this->assertSame(
            1,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING)
                ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
                ->count(),
        );

        EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->update(['is_current' => false]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => "# Concept\n\n**Figur**\n_Kilde: dok.docx_",
            'content_blocks_json' => [$this->imageBlock('img1')],
            'generated_by_model' => 'gpt-5',
        ]);

        $lintService->lint($run->fresh());

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING)
                ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
                ->count(),
        );
    }

    public function test_duplicate_image_block_on_one_page_is_a_blocking_finding(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun(
            $customer,
            [$this->plannedFigure('img1', required: false)],
            contentMarkdown: "# Concept\n\n**Figur**\n_Kilde: dok.docx_\n\n**Figur**\n_Kilde: dok.docx_",
            contentBlocks: [$this->imageBlock('img1'), $this->imageBlock('img1')],
        );
        $page = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->firstOrFail();

        $counts = app(EnterpriseWikiAppliedRunLintService::class)->lint($run);

        $this->assertSame(1, $counts['errors']);

        $finding = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_DUPLICATE)
            ->first();

        $this->assertNotNull($finding);
        $this->assertTrue($finding->isBlocking());
    }

    /**
     * Wiki run-593: a figure belongs to the source document, not to one owner page — the same
     * source_element_key planned AND materialized on both the article and a concept page produces
     * no planned_figure_wrong_page finding for either. This is the exact false-positive shape the
     * run-593 incident hit (img4 planned onto both an article-type and a concept-type page).
     */
    public function test_same_figure_planned_and_materialized_on_article_and_concept_produces_no_wrong_page_finding(): void
    {
        $customer = $this->createCustomer();

        [$run] = $this->createMultiPageRun($customer, [
            [
                'type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
                'title' => 'Masterdata Samhandling',
                'plannedFigures' => [$this->plannedFigure('img4', required: false)],
                'contentMarkdown' => "# Masterdata Samhandling\n\n**Figur**\n_Kilde: dok.docx_",
                'contentBlocks' => [$this->imageBlock('img4')],
            ],
            [
                'type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
                'title' => 'Styringsnivåer',
                'plannedFigures' => [$this->plannedFigure('img4', required: true)],
                'contentMarkdown' => "# Styringsnivåer\n\n**Figur**\n_Kilde: dok.docx_",
                'contentBlocks' => [$this->imageBlock('img4')],
            ],
        ]);

        $counts = app(EnterpriseWikiAppliedRunLintService::class)->lint($run);

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_WRONG_PAGE)
                ->count(),
        );
        $this->assertSame(0, $counts['errors']);
    }

    /**
     * Wiki run-593: the same figure planned and materialized on TWO concept pages at once (no
     * article/summary involved) is equally legitimate — the rule is never "compare against a
     * single global owner", regardless of which page types share the figure.
     */
    public function test_same_figure_planned_on_multiple_concept_pages_produces_no_wrong_page_finding(): void
    {
        $customer = $this->createCustomer();

        [$run] = $this->createMultiPageRun($customer, [
            [
                'type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
                'title' => 'Styrings- og samhandlingsmodell',
                'plannedFigures' => [$this->plannedFigure('img1', required: false)],
                'contentMarkdown' => "# Styrings- og samhandlingsmodell\n\n**Figur**\n_Kilde: dok.docx_",
                'contentBlocks' => [$this->imageBlock('img1')],
            ],
            [
                'type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
                'title' => 'Samhandlingsarenaer',
                'plannedFigures' => [$this->plannedFigure('img1', required: false)],
                'contentMarkdown' => "# Samhandlingsarenaer\n\n**Figur**\n_Kilde: dok.docx_",
                'contentBlocks' => [$this->imageBlock('img1')],
            ],
        ]);

        app(EnterpriseWikiAppliedRunLintService::class)->lint($run);

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_WRONG_PAGE)
                ->count(),
        );
    }

    /**
     * A materialized image block with no source_element_key at all (missing/invalid) is silently
     * skipped by the cross-page check — never crashes, never produces a wrong_page finding.
     */
    public function test_materialized_image_block_with_no_source_element_key_is_never_flagged_wrong_page(): void
    {
        $customer = $this->createCustomer();

        $blockWithoutKey = [
            'block_type' => 'image',
            'markdown' => "**Figur**\n_Kilde: dok.docx_",
            'source_element_key' => '',
            'source_element_type' => 'image',
            'image_data' => ['caption' => 'Figur', 'alt_text' => 'Figur'],
        ];

        [$run] = $this->createMultiPageRun($customer, [
            [
                'type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
                'title' => 'Styringsmodell',
                'plannedFigures' => [],
                'contentMarkdown' => "# Styringsmodell\n\n**Figur**\n_Kilde: dok.docx_",
                'contentBlocks' => [$blockWithoutKey],
            ],
        ]);

        $counts = app(EnterpriseWikiAppliedRunLintService::class)->lint($run);

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_WRONG_PAGE)
                ->count(),
        );
        $this->assertSame(0, $counts['errors']);
    }

    /**
     * The exact run-593 shape: img4 planned onto and materialized on both the source article and
     * a concept page ("Masterdata Samhandling" / "Styringsnivåer (strategisk, taktisk,
     * operativt)"), alongside other concept pages with their own independent figures — the whole
     * run passes with zero planned_figure_wrong_page findings under the new rule.
     */
    public function test_run_593_like_figure_shared_across_multiple_planned_pages_passes(): void
    {
        $customer = $this->createCustomer();

        [$run] = $this->createMultiPageRun($customer, [
            [
                'type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
                'title' => 'Masterdata Samhandling',
                'plannedFigures' => [$this->plannedFigure('img4', required: false)],
                'contentMarkdown' => "# Masterdata Samhandling\n\n**Figur**\n_Kilde: dok.docx_",
                'contentBlocks' => [$this->imageBlock('img4')],
            ],
            [
                'type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
                'title' => 'Styringsnivåer (strategisk, taktisk, operativt)',
                'plannedFigures' => [
                    $this->plannedFigure('img4', required: true),
                    $this->plannedFigure('img1', required: false),
                ],
                'contentMarkdown' => "# Styringsnivåer\n\n**Figur**\n_Kilde: dok.docx_\n\n**Figur**\n_Kilde: dok.docx_",
                'contentBlocks' => [$this->imageBlock('img4'), $this->imageBlock('img1')],
            ],
            [
                'type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
                'title' => 'Kundeteam',
                'plannedFigures' => [$this->plannedFigure('img3', required: false)],
                'contentMarkdown' => "# Kundeteam\n\n**Figur**\n_Kilde: dok.docx_",
                'contentBlocks' => [$this->imageBlock('img3')],
            ],
        ]);

        $counts = app(EnterpriseWikiAppliedRunLintService::class)->lint($run);

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_ingest_run_id', $run->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_WRONG_PAGE)
                ->count(),
        );
        $this->assertSame(0, $counts['errors']);
    }

    /**
     * A figure planned onto page A but materialized on page B (article/summary keep the
     * unrestricted "any cited image" behavior, so this is possible there even though concept/entity
     * pages are gated) is a cross-page defect only run-wide visibility can detect — still true
     * under the new document-scoped rule: page B's own planned_figures never included this key.
     */
    public function test_figure_materialized_on_a_different_page_than_planned_is_flagged_wrong_page(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $planningTarget = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Styringsmodell');
        $wrongPage = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => [
                'source_article' => ['action' => 'create', 'title' => $wrongPage->title, 'proposed_slug' => $wrongPage->slug, 'reason' => 'r'],
                'source_summary' => ['action' => 'create', 'title' => 'S', 'proposed_slug' => 's', 'reason' => 'r'],
                'concept_pages' => [[
                    'action' => 'create',
                    'page_id' => null,
                    'title' => $planningTarget->title,
                    'proposed_slug' => $planningTarget->slug,
                    'reason' => 'r',
                    'owned_topics' => [],
                    'reference_only_topics' => [],
                    'excluded_topics' => [],
                    'related_page_guidance' => [],
                    'planned_figures' => [$this->plannedFigure('img1', required: true)],
                ]],
                'entity_pages' => [],
                'no_action_reason' => null,
                'warnings' => [],
            ],
        ]);

        foreach ([$planningTarget, $wrongPage] as $page) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            ]);
        }

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $planningTarget->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Styringsmodell',
            'generated_by_model' => 'gpt-5',
        ]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $wrongPage->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Artikkel\n\n**Figur**\n_Kilde: dok.docx_",
            'content_blocks_json' => [$this->imageBlock('img1')],
            'generated_by_model' => 'gpt-5',
        ]);

        app(EnterpriseWikiAppliedRunLintService::class)->lint($run);

        $finding = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_page_id', $wrongPage->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_WRONG_PAGE)
            ->first();

        $this->assertNotNull($finding);
        $this->assertTrue($finding->isBlocking());
    }

    /**
     * Fase 8K-3 companion to the run-30 planned-section regression: plannedFiguresForPage() reads
     * the same source_article/source_summary slots and needs the same identity guard. Here a false
     * match is worse than a spurious finding — it would register the patched page as a legitimate
     * owner of the figure in checkPlannedFigureCrossPageAssignment()'s $plannedPageIdsByKey and
     * thereby MASK a real wrong-page materialization.
     */
    public function test_patched_existing_article_does_not_inherit_source_article_planned_figures(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $sourceArticle = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Change Notice Alpha');
        $patchedArticle = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Operating Procedure Beta');

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => [
                'source_article' => [
                    'action' => 'create',
                    'title' => $sourceArticle->title,
                    'proposed_slug' => $sourceArticle->slug,
                    'reason' => 'r',
                    'planned_figures' => [$this->plannedFigure('img1', required: true)],
                ],
                'source_summary' => ['action' => 'create', 'title' => 'S', 'proposed_slug' => 's', 'reason' => 'r'],
                'concept_pages' => [],
                'entity_pages' => [],
                'no_action_reason' => null,
                'warnings' => [],
            ],
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $sourceArticle->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $patchedArticle->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_PATCHED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        // The source article correctly materializes its own planned figure.
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $sourceArticle->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Change Notice Alpha\n\n**Figur**\n_Kilde: dok.docx_",
            'content_blocks_json' => [$this->imageBlock('img1')],
            'generated_by_model' => 'gpt-5',
        ]);

        // The patched page carries no image at all, so it must produce no figure finding.
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $patchedArticle->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => "# Operating Procedure Beta\n\nExisting procedure text.",
            'generated_by_model' => 'deterministic/section-patch',
        ]);

        app(EnterpriseWikiAppliedRunLintService::class)->lint($run);

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $patchedArticle->id)
                ->whereIn('code', [
                    EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING,
                    EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_WRONG_PAGE,
                ])
                ->count(),
            'A patched existing article must not inherit the new document source_article\'s required figure.',
        );

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $sourceArticle->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING)
                ->count(),
            'The real source article satisfied its own planned figure and must stay clean.',
        );
    }

    public function test_figure_planned_for_source_article_is_flagged_wrong_page_on_a_patched_article(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $sourceArticle = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Change Notice Alpha');
        $patchedArticle = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Operating Procedure Beta');

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => [
                'source_article' => [
                    'action' => 'create',
                    'title' => $sourceArticle->title,
                    'proposed_slug' => $sourceArticle->slug,
                    'reason' => 'r',
                    'planned_figures' => [$this->plannedFigure('img1', required: true)],
                ],
                'source_summary' => ['action' => 'create', 'title' => 'S', 'proposed_slug' => 's', 'reason' => 'r'],
                'concept_pages' => [],
                'entity_pages' => [],
                'no_action_reason' => null,
                'warnings' => [],
            ],
        ]);

        foreach ([[$sourceArticle, EnterpriseWikiIngestRunPage::ACTION_CREATED], [$patchedArticle, EnterpriseWikiIngestRunPage::ACTION_PATCHED]] as [$page, $action]) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => $action,
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            ]);
        }

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $sourceArticle->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Change Notice Alpha',
            'generated_by_model' => 'gpt-5',
        ]);

        // The figure planned for the source article ended up on the patched page instead. The
        // identity guard is what keeps this detectable: without it the patched page would count as
        // a planned owner of img1 and this real defect would be silently accepted.
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $patchedArticle->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => "# Operating Procedure Beta\n\n**Figur**\n_Kilde: dok.docx_",
            'content_blocks_json' => [$this->imageBlock('img1')],
            'generated_by_model' => 'deterministic/section-patch',
        ]);

        app(EnterpriseWikiAppliedRunLintService::class)->lint($run);

        $finding = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_page_id', $patchedArticle->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_WRONG_PAGE)
            ->first();

        $this->assertNotNull($finding, 'A figure planned for the source article but materialized on a patched page must still be flagged.');
        $this->assertTrue($finding->isBlocking());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Figure Coverage Lint Test AS'): Customer
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
            'extracted_text' => 'Dokumentet beskriver styringsmodellen i detalj.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    /**
     * Wiki run-593: builds a run with an arbitrary set of pages (article/summary/concept/entity),
     * each with its own planned_figures and already-materialized content — for
     * checkPlannedFigureCrossPageAssignment()'s document-scoped rule, which needs several pages'
     * own plans visible at once rather than createAppliedRun()'s single concept page.
     *
     * @param  list<array{type: string, title: string, plannedFigures: list<array<string, mixed>>, contentMarkdown: string, contentBlocks: list<array<string, mixed>>}>  $pageSpecs
     * @return array{0: EnterpriseWikiIngestRun, 1: array<string, EnterpriseWikiPage>}
     */
    private function createMultiPageRun(Customer $customer, array $pageSpecs): array
    {
        $document = $this->createDocument($customer);
        $pages = [];

        foreach ($pageSpecs as $spec) {
            $pages[$spec['title']] = $this->createPage($customer, $spec['type'], $spec['title']);
        }

        $specsByType = collect($pageSpecs)->groupBy('type');
        $articleSpec = $specsByType->get(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, collect())->first();
        $summarySpec = $specsByType->get(EnterpriseWikiPage::PAGE_TYPE_SUMMARY, collect())->first();

        $decisionJson = [
            'source_article' => $articleSpec !== null
                ? [
                    'action' => 'create', 'title' => $pages[$articleSpec['title']]->title,
                    'proposed_slug' => $pages[$articleSpec['title']]->slug, 'reason' => 'r',
                    'planned_figures' => $articleSpec['plannedFigures'],
                ]
                : ['action' => 'create', 'title' => 'A', 'proposed_slug' => 'a', 'reason' => 'r'],
            'source_summary' => $summarySpec !== null
                ? [
                    'action' => 'create', 'title' => $pages[$summarySpec['title']]->title,
                    'proposed_slug' => $pages[$summarySpec['title']]->slug, 'reason' => 'r',
                    'planned_figures' => $summarySpec['plannedFigures'],
                ]
                : ['action' => 'create', 'title' => 'S', 'proposed_slug' => 's', 'reason' => 'r'],
            'concept_pages' => $specsByType->get(EnterpriseWikiPage::PAGE_TYPE_CONCEPT, collect())->map(fn (array $spec): array => [
                'action' => 'create',
                'page_id' => null,
                'title' => $pages[$spec['title']]->title,
                'proposed_slug' => $pages[$spec['title']]->slug,
                'reason' => 'r',
                'owned_topics' => [],
                'reference_only_topics' => [],
                'excluded_topics' => [],
                'related_page_guidance' => [],
                'planned_figures' => $spec['plannedFigures'],
            ])->values()->all(),
            'entity_pages' => $specsByType->get(EnterpriseWikiPage::PAGE_TYPE_ENTITY, collect())->map(fn (array $spec): array => [
                'action' => 'create',
                'page_id' => null,
                'title' => $pages[$spec['title']]->title,
                'proposed_slug' => $pages[$spec['title']]->slug,
                'reason' => 'r',
                'planned_figures' => $spec['plannedFigures'],
            ])->values()->all(),
            'no_action_reason' => null,
            'warnings' => [],
        ];

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => $decisionJson,
        ]);

        foreach ($pageSpecs as $spec) {
            $page = $pages[$spec['title']];

            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            ]);

            EnterpriseWikiPageVersion::query()->create([
                'enterprise_wiki_page_id' => $page->id,
                'version_number' => 1,
                'is_current' => true,
                'content_markdown' => $spec['contentMarkdown'],
                'content_blocks_json' => $spec['contentBlocks'],
                'generated_by_model' => 'gpt-5',
            ]);
        }

        return [$run, $pages];
    }

    /**
     * Builds a run with a single concept page whose planned_figures is $plannedFigures, and a
     * current version whose content_markdown/content_blocks_json are given directly — this test
     * suite is about the lint/QA-blocking mechanism, not the classification/materialization
     * heuristics (see EnterpriseWikiPlannedFigureCoverageValidatorTest for those).
     */
    private function createAppliedRun(
        Customer $customer,
        array $plannedFigures,
        string $contentMarkdown,
        array $contentBlocks = [],
    ): EnterpriseWikiIngestRun {
        $document = $this->createDocument($customer);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => [
                'source_article' => ['action' => 'create', 'title' => 'A', 'proposed_slug' => 'a', 'reason' => 'r'],
                'source_summary' => ['action' => 'create', 'title' => 'S', 'proposed_slug' => 's', 'reason' => 'r'],
                'concept_pages' => [[
                    'action' => 'create',
                    'page_id' => null,
                    'title' => 'Styringsmodell',
                    'proposed_slug' => 'styringsmodell',
                    'reason' => 'r',
                    'owned_topics' => [],
                    'reference_only_topics' => [],
                    'excluded_topics' => [],
                    'related_page_guidance' => [],
                    'planned_figures' => $plannedFigures,
                ]],
                'entity_pages' => [],
                'no_action_reason' => null,
                'warnings' => [],
            ],
        ]);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'styringsmodell',
            'title' => 'Styringsmodell',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $contentMarkdown,
            'content_blocks_json' => $contentBlocks,
            'generated_by_model' => 'gpt-5',
        ]);

        return $run;
    }

    private function plannedFigure(string $sourceElementKey, bool $required): array
    {
        return [
            'source_element_key' => $sourceElementKey,
            'classification' => 'diagram',
            'section_placement' => null,
            'purpose' => 'Illustrates the governance model.',
            'required' => $required,
            'caption_hint' => null,
        ];
    }

    private function imageBlock(string $sourceElementKey): array
    {
        return [
            'block_type' => 'image',
            'markdown' => "**Figur**\n_Kilde: dok.docx_",
            'source_element_key' => $sourceElementKey,
            'source_element_type' => 'image',
            'image_data' => [
                'source_image_key' => $sourceElementKey,
                'caption' => 'Figur',
                'alt_text' => 'Figur som viser styringsmodellen',
            ],
        ];
    }

    private function markStepsComplete(EnterpriseWikiIngestRun $run): void
    {
        EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->update([
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
                'claims_extracted_at' => now(),
                'claims_claimed_at' => null,
            ]);
    }
}
