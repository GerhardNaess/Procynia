<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Jobs\EnterpriseWiki\GenerateEnterpriseWikiAppliedPage;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiGenerateAppliedPagesService;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkIntegrationCheckService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Arbeidspakke B, B4/B5: whether the relation work was done is recorded by the backend, and a run
 * is never reported as integrated when it was not (docs/enterprise-wiki-knowledge-integration-plan.md
 * §6.3, §7.1).
 */
class EnterpriseWikiLinkAssessmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_links_are_marked_assessed_only_for_generated_pages_whose_rows_were_materialized(): void
    {
        [$customer, $run] = $this->runFixture();
        $done = $this->pageInRun($customer, $run, 'Ferdig', ['schema' => 1, 'outcome' => 'no_relevant_links']);
        $failed = $this->pageInRun($customer, $run, 'Feilet', null, EnterpriseWikiIngestRunPage::GENERATION_STATUS_FAILED, withVersion: false);
        $pending = $this->pageInRun($customer, $run, 'Venter', null, EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING, withVersion: false);

        app(EnterpriseWikiBuildPageLinksService::class)->materializeWikilinksForRun($run);

        $this->assertNotNull($this->pivot($run, $done)->links_assessed_at);
        $this->assertNull($this->pivot($run, $failed)->links_assessed_at);
        $this->assertNull($this->pivot($run, $pending)->links_assessed_at);

        // Repeated materialization (a retried continuation) never rewrites or accumulates the assessment.
        $before = $this->pivot($run, $done)->link_assessment;
        app(EnterpriseWikiBuildPageLinksService::class)->materializeWikilinksForRun($run);
        $this->assertSame($before, $this->pivot($run, $done)->link_assessment);
    }

    public function test_a_page_without_a_link_assessment_escalates_the_run_and_never_passes(): void
    {
        [$customer, $run] = $this->runFixture();
        $this->pageInRun($customer, $run, 'Artikkel', ['schema' => 1, 'outcome' => 'linked'], pageType: EnterpriseWikiPage::PAGE_TYPE_ARTICLE, assessed: false);
        $this->pageInRun($customer, $run, 'Sammendrag', ['schema' => 1, 'outcome' => 'no_relevant_links'], pageType: EnterpriseWikiPage::PAGE_TYPE_SUMMARY);

        $evaluation = app(EnterpriseWikiPostIngestQaService::class)->evaluate($run);

        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $evaluation['verdict']);
        $this->assertSame(['link_assessment_incomplete'], $evaluation['incomplete_steps']);
        $this->assertSame(EnterpriseWikiLinkIntegrationCheckService::STATUS_NOT_COMPLETED, $evaluation['checks']['link_integration']['status']);
    }

    public function test_rows_that_disagree_with_the_text_escalate_the_run(): void
    {
        [$customer, $run] = $this->runFixture();
        $article = $this->pageInRun($customer, $run, 'Artikkel', ['schema' => 1, 'outcome' => 'linked'], pageType: EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $summary = $this->pageInRun($customer, $run, 'Sammendrag', ['schema' => 1, 'outcome' => 'no_relevant_links'], pageType: EnterpriseWikiPage::PAGE_TYPE_SUMMARY);
        EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $article->id)->update(['content_markdown' => "# Artikkel\n\nSe [[{$summary->slug}|sammendraget]]."]);

        $evaluation = app(EnterpriseWikiPostIngestQaService::class)->evaluate($run);

        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $evaluation['verdict']);
        $this->assertSame(['link_integration_mismatch'], $evaluation['incomplete_steps']);

        // Materializing the rows resolves it.
        app(EnterpriseWikiBuildPageLinksService::class)->materializeWikilinksForPage($article, $run->id);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, app(EnterpriseWikiPostIngestQaService::class)->evaluate($run)['verdict']);
    }

    public function test_a_page_whose_proposals_were_all_rejected_does_not_block_but_is_never_reported_as_checked(): void
    {
        [$customer, $run] = $this->runFixture();
        $this->pageInRun($customer, $run, 'Artikkel', ['schema' => 1, 'outcome' => 'rejected_only'], pageType: EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->pageInRun($customer, $run, 'Sammendrag', ['schema' => 1, 'outcome' => 'no_candidates'], pageType: EnterpriseWikiPage::PAGE_TYPE_SUMMARY);

        $evaluation = app(EnterpriseWikiPostIngestQaService::class)->evaluate($run);

        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $evaluation['verdict']);
        $this->assertSame(EnterpriseWikiLinkIntegrationCheckService::STATUS_NEEDS_FOLLOWUP, $evaluation['checks']['link_integration']['status']);
        $this->assertSame(['rejected_only' => 1, 'no_candidates' => 1], $evaluation['checks']['link_integration']['outcomes']);
    }

    public function test_a_regenerated_page_replaces_its_assessment_and_must_be_materialized_again(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => true]);
        Queue::fake();
        [$customer, $run] = $this->runFixture(EnterpriseWikiIngestRun::STATUS_GENERATING_PAGES);
        $page = $this->pageInRun($customer, $run, 'Artikkel', ['schema' => 1, 'outcome' => 'linked', 'proposed' => 9], EnterpriseWikiIngestRunPage::GENERATION_STATUS_PENDING, withVersion: false);
        $this->pivot($run, $page)->update(['links_assessed_at' => now()]);

        $this->mock(WikiPageContentAiClient::class)->shouldReceive('generatePageFromSource')->once()->andReturn([
            'markdown' => "# Artikkel\n\nNy tekst.",
            'blocks' => [['markdown' => "# Artikkel\n\nNy tekst.", 'content_origin' => 'structural', 'source_element_keys' => [], 'source_element_types' => [], 'best_practice_reason' => null, 'link_intents' => []]],
            'best_practice_review' => [],
        ]);

        (new GenerateEnterpriseWikiAppliedPage($run->id, $page->id))->handle(app(EnterpriseWikiGenerateAppliedPagesService::class));

        $pivot = $this->pivot($run, $page);
        $this->assertSame(0, $pivot->link_assessment['proposed'], 'the old assessment is not reused or added to');
        $this->assertNull($pivot->links_assessed_at, 'not assessed until the continuation materialized the new version');
    }

    public function test_a_failing_relation_step_fails_the_run_and_leaves_the_page_unassessed(): void
    {
        Queue::fake();
        [$customer, $run] = $this->runFixture(EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING);
        $page = $this->pageInRun($customer, $run, 'Artikkel', ['schema' => 1, 'outcome' => 'linked'], assessed: false);

        $this->mock(EnterpriseWikiBuildPageLinksService::class)->shouldReceive('materializeWikilinksForRun')->once()->andThrow(new RuntimeException('database unavailable'));

        try {
            (new ContinueEnterpriseWikiDocumentFlowAfterPages($run->id))->handle(app(EnterpriseWikiDocumentFlowService::class));
        } catch (RuntimeException) {
            // The continuation records the failure and rethrows.
        }

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertNull($this->pivot($run, $page)->links_assessed_at);
        $this->assertSame(EnterpriseWikiLinkIntegrationCheckService::STATUS_NOT_COMPLETED, app(EnterpriseWikiLinkIntegrationCheckService::class)->check($run)['status']);
        $this->assertSame(0, EnterpriseWikiPageLink::query()->where('from_page_id', $page->id)->count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return array{0: Customer, 1: EnterpriseWikiIngestRun} */
    private function runFixture(string $status = EnterpriseWikiIngestRun::STATUS_QA): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create([
            'name' => 'Vurdering AS',
            'slug' => 'vurdering-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'kilde.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => $status,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => [],
        ]);

        return [$customer, $run];
    }

    /** @param  array<string, mixed>|null  $assessment */
    private function pageInRun(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        string $title,
        ?array $assessment,
        string $generationStatus = EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        bool $withVersion = true,
        string $pageType = EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
        bool $assessed = true,
    ): EnterpriseWikiPage {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        if ($withVersion) {
            EnterpriseWikiPageVersion::query()->create([
                'enterprise_wiki_page_id' => $page->id,
                'version_number' => 1,
                'is_current' => true,
                'content_markdown' => "# {$title}\n\nInnhold.",
                'generated_by_model' => 'gpt-5',
            ]);
        }

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            'generation_status' => $generationStatus,
            'claims_extracted_at' => now(),
            'link_assessment' => $assessment,
            'links_assessed_at' => $assessment !== null && $assessed && $generationStatus === EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED ? now() : null,
        ]);

        return $page;
    }

    private function pivot(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): EnterpriseWikiIngestRunPage
    {
        return EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $page->id)
            ->firstOrFail();
    }
}
