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
     * A figure planned onto page A but materialized on page B (article/summary keep the
     * unrestricted "any cited image" behavior, so this is possible there even though concept/entity
     * pages are gated) is a cross-page defect only run-wide visibility can detect.
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
