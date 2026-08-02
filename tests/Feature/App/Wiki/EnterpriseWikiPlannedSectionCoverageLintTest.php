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
