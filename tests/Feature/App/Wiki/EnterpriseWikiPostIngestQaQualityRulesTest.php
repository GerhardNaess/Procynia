<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLinkQaAttempt;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSectionMap;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSourceElementService;
use App\Services\EnterpriseWiki\EnterpriseWikiEscalatedRunRecoveryService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Services\EnterpriseWiki\EnterpriseWikiRunFindingsService;
use App\Services\EnterpriseWiki\EnterpriseWikiRunRecoveryResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\MarksWikiLinkAssessmentComplete;
use Tests\TestCase;

/**
 * Plan §18, P0-3: run 962 ended `completed` / QA `passed` with 0 findings although semantic link
 * QA failed on two pages, recommendations had become requirements and whole parts of the source
 * never reached a page. "Passed" now requires every mandatory control to have run and none of the
 * three serious quality findings; a control that could not run is never a control that passed.
 * The status model is unchanged: these escalate (a person decides), a critical defect still fails.
 */
class EnterpriseWikiPostIngestQaQualityRulesTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use MarksWikiLinkAssessmentComplete;
    use RefreshDatabase;

    public function test_a_technically_sound_run_with_every_control_completed_passes(): void
    {
        $scenario = $this->scenario();
        $this->linkQaAttempt($scenario, EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED, EnterpriseWikiPageLinkQaAttempt::REASON_NO_CHANGE_RECOMMENDED);

        $result = $this->qa()->runForRun($scenario['run']);

        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $scenario['run']->fresh()->qa_status);
        $this->assertSame([], $result['quality_findings']);
        $this->assertSame('completed', $result['checks']['semantic_link_qa']['status']);
    }

    public function test_semantic_link_qa_that_failed_is_not_completed_and_escalates(): void
    {
        $scenario = $this->scenario();
        $this->linkQaAttempt($scenario, EnterpriseWikiPageLinkQaAttempt::STATUS_FAILED, 'ai_error');

        $result = $this->qa()->runForRun($scenario['run']);
        $run = $scenario['run']->fresh();

        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
        $this->assertSame([EnterpriseWikiPostIngestQaService::FINDING_CONTROL_NOT_COMPLETED_SEMANTIC_LINK_QA], $result['quality_findings']);
        $this->assertSame([$scenario['article']->id], $result['checks']['semantic_link_qa']['failed_page_ids']);
        $this->assertStringContainsString('control_not_completed:semantic_link_qa', (string) $run->qa_last_error);

        // Never mistaken for a stale status to re-finalize, and explained by what was found.
        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_ESCALATED]);
        $recovery = app(EnterpriseWikiEscalatedRunRecoveryService::class)->evaluate($run->id);
        $this->assertSame(EnterpriseWikiRunRecoveryResult::OUTCOME_NOT_RECOVERABLE, $recovery->outcome);

        $explanation = app(EnterpriseWikiRunFindingsService::class)->buildForRun($run->fresh(), null, false)['summary']['explanation'];
        $this->assertSame(__('procynia.wiki.runs_findings_explanation_quality.control_not_completed_semantic_link_qa'), $explanation);
        $this->assertNotSame(__('procynia.wiki.runs_findings_explanation_needs_resync'), $explanation);
    }

    public function test_a_review_that_does_not_apply_is_not_a_technical_failure(): void
    {
        foreach ([
            [EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED, EnterpriseWikiPageLinkQaAttempt::REASON_AI_UNAVAILABLE, 'not_run'],
            [EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED, 'empty_content', 'not_run'],
            [EnterpriseWikiPageLinkQaAttempt::STATUS_FAILED, EnterpriseWikiPageLinkQaAttempt::REASON_INVALID_REVISION, 'completed'],
            [EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED, EnterpriseWikiPageLinkQaAttempt::REASON_BLOCK_PROVENANCE_AT_RISK, 'completed'],
        ] as [$status, $reason, $expected]) {
            $scenario = $this->scenario();
            $this->linkQaAttempt($scenario, $status, $reason);

            $result = $this->qa()->runForRun($scenario['run']);

            $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $scenario['run']->fresh()->qa_status, "{$status}/{$reason}");
            $this->assertSame($expected, $result['checks']['semantic_link_qa']['pages'][$scenario['article']->id], "{$status}/{$reason}");
            $this->assertSame([], $result['quality_findings'], "{$status}/{$reason}");
        }
    }

    public function test_a_reservation_left_by_a_crashed_worker_is_not_completed(): void
    {
        $scenario = $this->scenario();
        $this->linkQaAttempt($scenario, EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED, 'reserved');

        $this->qa()->runForRun($scenario['run']);

        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $scenario['run']->fresh()->qa_status);
    }

    public function test_an_undecided_normative_strengthening_escalates_until_someone_decides(): void
    {
        $scenario = $this->scenario();
        $claim = $this->normativeClaim($scenario);

        $result = $this->qa()->runForRun($scenario['run']);

        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $scenario['run']->fresh()->qa_status);
        $this->assertSame([EnterpriseWikiPostIngestQaService::FINDING_NORMATIVE_STRENGTHENING], $result['quality_findings']);
        $this->assertSame([$claim->id], $result['checks']['normative_fidelity']['open_strengthened_claim_ids']);

        // One picture everywhere: the claim that holds up publication is visible in Funn, and the
        // run's explanation names the actual cause.
        $findings = app(EnterpriseWikiRunFindingsService::class)->buildForRun($scenario['run']->fresh(), null, false);
        $this->assertContains($claim->id, array_column($findings['findings'], 'claim_id'));
        $this->assertSame(__('procynia.wiki.runs_findings_explanation_quality.normative_strengthening'), $findings['summary']['explanation']);

        // The same decision that releases the publication gate releases QA.
        $claim->update(['blocking_override' => false]);
        $result = $this->qa()->runForRun($scenario['run']->fresh(), retry: true);

        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $scenario['run']->fresh()->qa_status);
        $this->assertSame([], $result['quality_findings']);
    }

    public function test_diagnostic_modality_and_negation_signals_keep_their_existing_non_blocking_handling(): void
    {
        $scenario = $this->scenario();

        foreach (['modality_mismatch', 'negation_mismatch', 'subject_mismatch'] as $reason) {
            $this->claimOnArticle($scenario, ['classification_basis' => 'semantic_verification', 'verdict' => 'not_supported', 'reason' => 'x', 'deterministic_reason' => $reason]);
        }

        $result = $this->qa()->runForRun($scenario['run']);

        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $scenario['run']->fresh()->qa_status);
        $this->assertSame([], $result['claim_qa_signals']);
    }

    public function test_source_knowledge_left_out_of_the_plan_escalates_and_a_partial_gap_is_a_note(): void
    {
        $elements = $this->run962Elements();
        $this->mock(EnterpriseWikiDocumentSourceElementService::class)
            ->shouldReceive('inspect')
            ->andReturn(['elements' => $elements]);

        // The stored run 962 decision: whole sections are on no page.
        $scenario = $this->scenario(json_decode((string) file_get_contents(base_path('tests/Fixtures/EnterpriseWiki/run962/maintainer_decision.json')), true));

        $result = $this->qa()->runForRun($scenario['run']);

        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $scenario['run']->fresh()->qa_status);
        $this->assertSame([EnterpriseWikiPostIngestQaService::FINDING_SOURCE_SECTION_UNCOVERED], $result['quality_findings']);
        $this->assertSame('incomplete', $result['checks']['source_coverage']['status']);
        $this->assertNotEmpty($result['checks']['source_coverage']['uncovered_section_keys']);

        // Only part of one section missing: a recorded note, not an escalation.
        $sectionOf = EnterpriseWikiDocumentSectionMap::build($elements)['section_by_element'];
        $partial = [
            'source_article' => [
                'title' => 'A', 'action' => 'create',
                'owned_topics' => [['topic' => 'Alt unntatt ett avsnitt', 'source_element_keys' => ['paragraph-1']]],
                'excluded_sections' => array_values(array_map(
                    static fn (string $key): array => ['section_key' => $key, 'reason' => 'out_of_scope', 'justification' => 'Ikke relevant for denne testens dekning.', 'existing_page_id' => null],
                    array_values(array_diff(array_unique(array_values($sectionOf)), [$sectionOf['paragraph-1']])),
                )),
            ],
        ];
        $scenario = $this->scenario($partial);
        $result = $this->qa()->runForRun($scenario['run']);

        // Excluding everything else is itself excessive — escalates as well.
        $this->assertSame([EnterpriseWikiPostIngestQaService::FINDING_SOURCE_SECTION_UNCOVERED], $result['quality_findings']);
    }

    public function test_a_critical_defect_still_fails_before_any_quality_finding(): void
    {
        $scenario = $this->scenario();
        $this->linkQaAttempt($scenario, EnterpriseWikiPageLinkQaAttempt::STATUS_FAILED, 'ai_error');

        $version = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $scenario['article']->id)->where('is_current', true)->firstOrFail();
        EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $scenario['customer']->id,
            'enterprise_wiki_page_id' => $scenario['article']->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'enterprise_wiki_ingest_run_id' => $scenario['run']->id,
            'code' => EnterpriseWikiLintFinding::CODE_BROKEN_WIKILINK,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
            'message' => 'Broken link.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);

        $this->qa()->runForRun($scenario['run']);

        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $scenario['run']->fresh()->qa_status);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function qa(): EnterpriseWikiPostIngestQaService
    {
        return app(EnterpriseWikiPostIngestQaService::class);
    }

    /**
     * An applied run with an article and a summary, every continuation step complete.
     *
     * @param  array<string, mixed>  $decision
     * @return array{customer: Customer, run: EnterpriseWikiIngestRun, article: EnterpriseWikiPage, summary: EnterpriseWikiPage}
     */
    private function scenario(array $decision = ['pages' => []]): array
    {
        $customer = $this->createWikiCustomer('Kvalitet '.Str::random(4));
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'kilde.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Kildetekst.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => $decision,
        ]);

        $article = $this->versionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Artikkel');
        $summary = $this->versionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Sammendrag');

        EnterpriseWikiIngestRunPage::query()->where('enterprise_wiki_ingest_run_id', $run->id)->update([
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
            'claims_extracted_at' => now(),
        ]);
        $this->markLinkAssessmentComplete($run);

        return compact('customer', 'run', 'article', 'summary');
    }

    private function versionedPage(Customer $customer, EnterpriseWikiIngestRun $run, string $type, string $title): EnterpriseWikiPage
    {
        $page = $this->createWikiPage($customer, $title, ['page_type' => $type]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# {$title}\n\nInnhold.",
            'generated_by_model' => 'gpt-5',
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        return $page;
    }

    private function linkQaAttempt(array $scenario, string $status, string $reason): void
    {
        EnterpriseWikiPageLinkQaAttempt::query()->create([
            'customer_id' => $scenario['customer']->id,
            'enterprise_wiki_ingest_run_id' => $scenario['run']->id,
            'enterprise_wiki_page_id' => $scenario['article']->id,
            'status' => $status,
            'reason' => $reason,
            'attempted_at' => now(),
        ]);
        EnterpriseWikiPageLinkQaAttempt::query()->create([
            'customer_id' => $scenario['customer']->id,
            'enterprise_wiki_ingest_run_id' => $scenario['run']->id,
            'enterprise_wiki_page_id' => $scenario['summary']->id,
            'status' => EnterpriseWikiPageLinkQaAttempt::STATUS_SKIPPED,
            'reason' => EnterpriseWikiPageLinkQaAttempt::REASON_NO_CHANGE_RECOMMENDED,
            'attempted_at' => now(),
        ]);
    }

    private function normativeClaim(array $scenario): EnterpriseWikiClaim
    {
        return $this->claimOnArticle($scenario, [
            'classification_basis' => 'semantic_verification',
            'verdict' => 'not_supported',
            'reason' => 'Støttet av kilden.',
            'deterministic_reason' => 'normative_strengthening',
            'normative_check' => [
                'status' => 'strengthened',
                'source_sentence' => 'Forbedringssløyfen bør måles som en egen prosess.',
                'claim_sentence' => 'Forbedringssløyfen skal måles som en egen prosess.',
                'source_class' => 'recommendation',
                'claim_class' => 'obligation',
            ],
        ]);
    }

    /** @param array<string, mixed> $meta */
    private function claimOnArticle(array $scenario, array $meta): EnterpriseWikiClaim
    {
        $version = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $scenario['article']->id)->where('is_current', true)->firstOrFail();

        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $scenario['article']->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Forbedringssløyfen skal måles som en egen prosess.',
            'content_block_key' => 'block-0001',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'review_metadata' => $meta,
            'verified_at' => now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function run962Elements(): array
    {
        $extraction = json_decode((string) file_get_contents(base_path('tests/Fixtures/EnterpriseWiki/run962/extraction.json')), true);

        return array_map(static fn (array $element): array => [
            'source_element_key' => $element['element_key'],
            'source_element_type' => $element['element_type'],
            'section_number' => $element['section_number'],
            'section_title' => $element['section_title'],
            'reference_text' => $element['text'],
        ], $extraction['text_elements']);
    }
}
