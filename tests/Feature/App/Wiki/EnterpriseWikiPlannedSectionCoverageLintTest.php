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
 * Wiki run-586: EnterpriseWikiAppliedRunLintService::checkPlannedSectionCoverage() is the
 * defense-in-depth layer behind EnterpriseWikiGenerateAppliedPagesService's inline generation-time
 * check — it re-checks whatever content actually persisted (from ANY source, including a future
 * code path this task didn't touch) against the page's own owned_topics, and its findings block
 * qa_status from ever reaching "passed" via the existing EnterpriseWikiLintFinding::isBlocking()
 * predicate (severity=error) — no new QA logic was introduced.
 */
class EnterpriseWikiPlannedSectionCoverageLintTest extends TestCase
{
    use RefreshDatabase;

    public function test_lint_creates_a_blocking_finding_for_an_empty_planned_section(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, [
            'Roller i styringsmodellen',
            'Møtefora og beslutningsflyt',
        ]);
        $page = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->firstOrFail();

        $counts = app(EnterpriseWikiAppliedRunLintService::class)->lint($run);

        $this->assertSame(1, $counts['errors']);

        $finding = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_EMPTY)
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame(EnterpriseWikiLintFinding::SEVERITY_ERROR, $finding->severity);
        $this->assertSame(EnterpriseWikiLintFinding::STATUS_OPEN, $finding->status);
        $this->assertStringContainsString('Møtefora og beslutningsflyt', $finding->message);
        $this->assertTrue($finding->isBlocking());
    }

    public function test_open_planned_section_finding_blocks_qa_from_passing(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, ['Roller i styringsmodellen', 'Møtefora og beslutningsflyt']);

        app(EnterpriseWikiAppliedRunLintService::class)->lint($run);

        $this->markStepsComplete($run);

        $evaluation = app(EnterpriseWikiPostIngestQaService::class)->evaluate($run->fresh());

        $this->assertContains('critical_lint_findings_or_broken_links', $evaluation['critical_defects']);
        $this->assertNotSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $evaluation['verdict']);
    }

    public function test_finding_is_resolved_once_a_new_version_actually_fills_the_section(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, ['Roller i styringsmodellen', 'Møtefora og beslutningsflyt']);
        $page = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->firstOrFail();

        $lintService = app(EnterpriseWikiAppliedRunLintService::class);
        $lintService->lint($run);

        $openBefore = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_EMPTY)
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
            ->count();
        $this->assertSame(1, $openBefore);

        // A new current version actually fills both sections.
        EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->update(['is_current' => false]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => "# Samhandlings- og styringsmodell\n\nInnledende avsnitt.\n\n"
                ."## Roller i styringsmodellen\n\nDelivery Executive har overordnet ansvar.\n\n"
                ."## Møtefora og beslutningsflyt\n\nStrategisk forum møtes årlig.",
            'generated_by_model' => 'gpt-5',
        ]);

        $lintService->lint($run->fresh());

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_EMPTY)
                ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
                ->count(),
            'The finding tied to the superseded (empty) version must be resolved once a new, complete version exists.',
        );
    }

    public function test_missing_section_with_no_source_grounding_creates_no_blocking_finding(): void
    {
        $customer = $this->createCustomer();
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Dette dokumentet handler kun om roller og ansvar, ingen andre tema.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

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
                    'title' => 'Samhandlings- og styringsmodell',
                    'proposed_slug' => 'samhandlings-og-styringsmodell',
                    'reason' => 'r',
                    'owned_topics' => ['Roller i styringsmodellen', 'Budsjettoppfølging'],
                    'reference_only_topics' => [],
                    'excluded_topics' => [],
                    'related_page_guidance' => [],
                ]],
                'entity_pages' => [],
                'no_action_reason' => null,
                'warnings' => [],
            ],
        ]);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'samhandlings-og-styringsmodell',
            'title' => 'Samhandlings- og styringsmodell',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Samhandlings- og styringsmodell\n\nInnledende avsnitt.\n\n"
                ."## Roller i styringsmodellen\n\nDelivery Executive har overordnet ansvar.",
            'generated_by_model' => 'gpt-5',
        ]);

        app(EnterpriseWikiAppliedRunLintService::class)->lint($run);

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->whereIn('code', [
                    EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_MISSING,
                    EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_EMPTY,
                ])
                ->count(),
            '"Budsjettoppfølging" has no detectable grounding in the source text — this must be a non-blocking planning signal, not a lint finding.',
        );
    }

    // =========================================================================
    // Fase 8K-3 regression: source_article identity (observation run 30)
    // =========================================================================

    public function test_patched_existing_article_does_not_inherit_source_article_planned_sections(): void
    {
        $customer = $this->createCustomer();
        $fixture = $this->createSourceArticleWithPatchedArticlesRun($customer);

        app(EnterpriseWikiAppliedRunLintService::class)->lint($fixture['run']);

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $fixture['patched_pages'][0]->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_MISSING)
                ->count(),
            'Run 30 regression: an existing article page that this run merely PATCHED must never be '
            .'checked against the new document source_article\'s owned_topics — those planned '
            .'sections belong to the newly created source article page.',
        );
    }

    public function test_patched_article_with_entirely_different_headings_produces_no_finding(): void
    {
        $customer = $this->createCustomer();
        $fixture = $this->createSourceArticleWithPatchedArticlesRun($customer);
        $patched = $fixture['patched_pages'][0];

        app(EnterpriseWikiAppliedRunLintService::class)->lint($fixture['run']);

        // The patched page's own headings ("Availability targets"/"Incident response") share no
        // normalized substring with the planned topics ("Decision details"/"Effective date"), so
        // before the identity guard this page produced exactly the run-30 blocking finding.
        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $patched->id)
                ->whereIn('code', [
                    EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_MISSING,
                    EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_EMPTY,
                    EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_ONLY_LINKS,
                    EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_BELOW_MINIMUM_SUBSTANCE,
                ])
                ->count(),
        );
    }

    public function test_actual_source_article_is_still_checked_and_still_blocks(): void
    {
        $customer = $this->createCustomer();
        $fixture = $this->createSourceArticleWithPatchedArticlesRun($customer, sourceArticleMarkdown: $this->sourceArticleMarkdownMissingSecondSection());

        $counts = app(EnterpriseWikiAppliedRunLintService::class)->lint($fixture['run']);

        $finding = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_page_id', $fixture['source_page']->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_MISSING)
            ->first();

        $this->assertNotNull($finding, 'The real source_article page must still be validated against its own planned sections.');
        $this->assertSame(EnterpriseWikiLintFinding::SEVERITY_ERROR, $finding->severity);
        $this->assertTrue($finding->isBlocking(), 'The rule must stay blocking on the page it actually applies to.');
        $this->assertStringContainsString('Effective date', $finding->message);
        $this->assertSame(1, $counts['errors']);
    }

    public function test_several_patched_articles_in_one_run_never_inherit_source_article_topics(): void
    {
        $customer = $this->createCustomer();
        $fixture = $this->createSourceArticleWithPatchedArticlesRun($customer, patchedArticles: [
            ['title' => 'Operating Procedure Beta', 'slug' => 'operating-procedure-beta'],
            ['title' => 'Operating Procedure Gamma', 'slug' => 'operating-procedure-gamma'],
        ]);

        app(EnterpriseWikiAppliedRunLintService::class)->lint($fixture['run']);

        // Fase 8K-3 makes "several article pages in one run" a legitimate shape — the old
        // "a run has at most one article, therefore it is the source article" assumption is dead.
        $this->assertCount(2, $fixture['patched_pages']);

        foreach ($fixture['patched_pages'] as $patched) {
            $this->assertSame(
                0,
                EnterpriseWikiLintFinding::query()
                    ->where('enterprise_wiki_page_id', $patched->id)
                    ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_MISSING)
                    ->count(),
                "Patched article [{$patched->slug}] must not inherit source_article owned_topics.",
            );
        }

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_ingest_run_id', $fixture['run']->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_MISSING)
                ->count(),
        );
    }

    public function test_qa_passes_when_the_false_patched_article_finding_was_the_only_blocker(): void
    {
        $customer = $this->createCustomer();
        $fixture = $this->createSourceArticleWithPatchedArticlesRun($customer);

        app(EnterpriseWikiAppliedRunLintService::class)->lint($fixture['run']);
        $this->markStepsComplete($fixture['run']);

        $evaluation = app(EnterpriseWikiPostIngestQaService::class)->evaluate($fixture['run']->fresh());

        // No QA special-casing was added — removing the false blocking lint finding is enough for
        // the existing aggregation in EnterpriseWikiPostIngestQaService::findCriticalDefects().
        $this->assertNotContains('critical_lint_findings_or_broken_links', $evaluation['critical_defects']);
        $this->assertSame([], $evaluation['critical_defects']);
    }

    public function test_source_article_identity_falls_back_to_title_without_a_proposed_slug(): void
    {
        $customer = $this->createCustomer();
        $fixture = $this->createSourceArticleWithPatchedArticlesRun(
            $customer,
            sourceArticleMarkdown: $this->sourceArticleMarkdownMissingSecondSection(),
            sourceArticleProposedSlug: '',
        );

        app(EnterpriseWikiAppliedRunLintService::class)->lint($fixture['run']);

        $this->assertSame(
            1,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $fixture['source_page']->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_MISSING)
                ->count(),
            'A decision payload with no proposed_slug must fall back to an exact title match rather '
            .'than silently disabling a blocking check.',
        );

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $fixture['patched_pages'][0]->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_MISSING)
                ->count(),
        );
    }

    public function test_article_whose_slug_and_title_both_differ_never_matches_source_article(): void
    {
        $customer = $this->createCustomer();
        $fixture = $this->createSourceArticleWithPatchedArticlesRun($customer);

        // Make the created source article page itself unreachable by both identities: the decision
        // still describes change-notice-alpha, but no page in the run carries that slug or title.
        $fixture['source_page']->update([
            'slug' => 'unrelated-renamed-article',
            'title' => 'Unrelated Renamed Article',
        ]);

        app(EnterpriseWikiAppliedRunLintService::class)->lint($fixture['run']->fresh());

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_ingest_run_id', $fixture['run']->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_MISSING)
                ->count(),
            'An unrelated title/slug must not match the source_article entry — no page in the run '
            .'may inherit its planned sections by page_type alone.',
        );
    }

    public function test_source_article_match_is_scoped_to_the_runs_own_customer(): void
    {
        $otherCustomer = $this->createCustomer('Other Tenant AS');

        // Another tenant owns a page with the exact same slug and title as this run's source
        // article. Lint only ever reads pages through this run's pivot rows, so it must be inert.
        $foreign = EnterpriseWikiPage::query()->create([
            'customer_id' => $otherCustomer->id,
            'slug' => 'change-notice-alpha',
            'title' => 'Change Notice Alpha',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $foreign->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Change Notice Alpha\n\nForeign tenant content with no planned sections at all.",
            'generated_by_model' => 'gpt-5',
        ]);

        $customer = $this->createCustomer();
        $fixture = $this->createSourceArticleWithPatchedArticlesRun($customer);

        app(EnterpriseWikiAppliedRunLintService::class)->lint($fixture['run']);

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $foreign->id)
                ->count(),
            'No finding may be written against another customer\'s page.',
        );
    }

    public function test_broken_wikilink_detection_is_unchanged_on_a_patched_article(): void
    {
        $customer = $this->createCustomer();
        $fixture = $this->createSourceArticleWithPatchedArticlesRun($customer);
        $patched = $fixture['patched_pages'][0];

        EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $patched->id)->update(['is_current' => false]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $patched->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => $this->patchedArticleMarkdown('Operating Procedure Beta')
                ."\n\nSee also [[no-such-page-anywhere]] for details.",
            'generated_by_model' => 'deterministic/section-patch',
        ]);

        app(EnterpriseWikiAppliedRunLintService::class)->lint($fixture['run']->fresh());

        $broken = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_page_id', $patched->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_BROKEN_WIKILINK)
            ->first();

        $this->assertNotNull($broken, 'Broken-wikilink detection must be untouched by the identity fix.');
        $this->assertTrue($broken->isBlocking());
        $this->assertStringContainsString('no-such-page-anywhere', $broken->message);

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $patched->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_MISSING)
                ->count(),
        );
    }

    public function test_concept_page_entry_matching_is_unchanged(): void
    {
        // Regression guard for section 11: concept/entity pages already matched their own decision
        // entry by title and therefore never had the run-30 defect. That must stay true.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, ['Roller i styringsmodellen', 'Møtefora og beslutningsflyt']);
        $page = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->firstOrFail();

        $counts = app(EnterpriseWikiAppliedRunLintService::class)->lint($run);

        $this->assertSame(1, $counts['errors']);
        $this->assertSame(
            1,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_EMPTY)
                ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
                ->count(),
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Section Coverage Lint Test AS'): Customer
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

    /**
     * Builds a run with a single concept page whose owned_topics match $ownedTopics, and a
     * current version replicating the run-586 shape: both `## ` headings present, both empty.
     * The document's extracted_text explicitly names both topics, so both count as source-grounded
     * (this fixture is about proving the finding/QA-blocking mechanism, not the grounding
     * heuristic itself — see EnterpriseWikiPlannedSectionCoverageValidatorTest for that).
     *
     * @param  list<string>  $ownedTopics
     */
    private function createAppliedRun(Customer $customer, array $ownedTopics): EnterpriseWikiIngestRun
    {
        $sourceText = 'Dokumentet beskriver '.implode(' og ', $ownedTopics).' i detalj, med roller, agenda og frekvens.';

        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => $sourceText,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

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
                    'title' => 'Samhandlings- og styringsmodell',
                    'proposed_slug' => 'samhandlings-og-styringsmodell',
                    'reason' => 'r',
                    'owned_topics' => $ownedTopics,
                    'reference_only_topics' => [],
                    'excluded_topics' => [],
                    'related_page_guidance' => [],
                ]],
                'entity_pages' => [],
                'no_action_reason' => null,
                'warnings' => [],
            ],
        ]);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'samhandlings-og-styringsmodell',
            'title' => 'Samhandlings- og styringsmodell',
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

        $headings = implode("\n\n", array_map(fn (string $topic): string => "## {$topic}", $ownedTopics));

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Samhandlings- og styringsmodell\n\nInnledende avsnitt.\n\n{$headings}",
            'generated_by_model' => 'gpt-5',
        ]);

        return $run;
    }

    /**
     * The observation-run-30 shape: a run whose pivot rows carry BOTH the newly created
     * source_article page AND one or more pre-existing article pages that Fase 8K-3 patched. Before
     * the source_* identity guard, every one of those article pages was checked against
     * source_article.owned_topics purely because page_type was "article".
     *
     * @param  list<array{title: string, slug: string}>  $patchedArticles
     * @return array{run: EnterpriseWikiIngestRun, source_page: EnterpriseWikiPage, summary_page: EnterpriseWikiPage, patched_pages: list<EnterpriseWikiPage>}
     */
    private function createSourceArticleWithPatchedArticlesRun(
        Customer $customer,
        ?string $sourceArticleMarkdown = null,
        string $sourceArticleProposedSlug = 'change-notice-alpha',
        array $patchedArticles = [['title' => 'Operating Procedure Beta', 'slug' => 'operating-procedure-beta']],
    ): array {
        $ownedTopics = ['Decision details', 'Effective date'];

        // Both planned topics are named in the source text, so a missing heading counts as
        // source-grounded and therefore blocking (EnterpriseWikiPlannedSectionCoverageValidator::isBlocking()).
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'change-notice.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'This change notice records the decision details and the effective date '
                .'for the tightened operating requirements of the platform.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $sourceArticleEntry = [
            'action' => 'create',
            'title' => 'Change Notice Alpha',
            'reason' => 'r',
            'owned_topics' => $ownedTopics,
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ];

        if ($sourceArticleProposedSlug !== '') {
            $sourceArticleEntry['proposed_slug'] = $sourceArticleProposedSlug;
        }

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
                'source_article' => $sourceArticleEntry,
                'source_summary' => [
                    'action' => 'create',
                    'title' => 'Change Notice Alpha Summary',
                    'proposed_slug' => 'change-notice-alpha-summary',
                    'reason' => 'r',
                    'owned_topics' => [],
                    'reference_only_topics' => [],
                    'excluded_topics' => [],
                    'related_page_guidance' => [],
                    'planned_figures' => [],
                ],
                'concept_pages' => [],
                'entity_pages' => [],
                'no_action_reason' => null,
                'warnings' => [],
            ],
        ]);

        $sourcePage = $this->createRunPage(
            $customer,
            $run,
            'change-notice-alpha',
            'Change Notice Alpha',
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            EnterpriseWikiIngestRunPage::ACTION_CREATED,
            $sourceArticleMarkdown ?? $this->sourceArticleMarkdownComplete(),
            'gpt-5',
        );

        $summaryPage = $this->createRunPage(
            $customer,
            $run,
            'change-notice-alpha-summary',
            'Change Notice Alpha Summary',
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
            EnterpriseWikiIngestRunPage::ACTION_CREATED,
            "# Change Notice Alpha Summary\n\nThe change notice tightens availability and response requirements from the stated effective date.",
            'gpt-5',
        );

        $patchedPages = [];

        foreach ($patchedArticles as $spec) {
            $patchedPages[] = $this->createRunPage(
                $customer,
                $run,
                $spec['slug'],
                $spec['title'],
                EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
                EnterpriseWikiIngestRunPage::ACTION_PATCHED,
                $this->patchedArticleMarkdown($spec['title']),
                'deterministic/section-patch',
            );
        }

        return [
            'run' => $run,
            'source_page' => $sourcePage,
            'summary_page' => $summaryPage,
            'patched_pages' => $patchedPages,
        ];
    }

    private function createRunPage(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        string $slug,
        string $title,
        string $pageType,
        string $action,
        string $markdown,
        string $generatedByModel,
    ): EnterpriseWikiPage {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug,
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => $action,
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown,
            'generated_by_model' => $generatedByModel,
        ]);

        return $page;
    }

    private function sourceArticleMarkdownComplete(): string
    {
        return "# Change Notice Alpha\n\nThis change notice records a tightening of the operating requirements.\n\n"
            ."## Decision details\n\nThe change was decided by the service owner and recorded under reference FG-DEC-027.\n\n"
            .'## Effective date'."\n\nThe tightened requirements take effect from the first day of the following month.";
    }

    private function sourceArticleMarkdownMissingSecondSection(): string
    {
        return "# Change Notice Alpha\n\nThis change notice records a tightening of the operating requirements.\n\n"
            .'## Decision details'."\n\nThe change was decided by the service owner and recorded under reference FG-DEC-027.";
    }

    /**
     * Headings deliberately share no normalized substring with either planned topic — this is the
     * exact run-30 page-49 shape (an existing procedure page whose real sections have nothing to do
     * with the change note's planned sections).
     */
    private function patchedArticleMarkdown(string $title): string
    {
        return "# {$title}\n\nThis existing procedure governs day-to-day operation of the platform.\n\n"
            ."## Availability targets\n\nMonthly availability is measured across the whole service and reported by the operations lead.\n\n"
            .'## Incident response'."\n\nIncidents are classified and confirmed by the operations team within the agreed window.";
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
