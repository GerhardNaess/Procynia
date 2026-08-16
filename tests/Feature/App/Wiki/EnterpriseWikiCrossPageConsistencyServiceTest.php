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
use App\Services\Ai\Wiki\WikiCrossPageConsistencyAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Services\EnterpriseWiki\EnterpriseWikiCrossPageConsistencyService;
use App\Services\EnterpriseWiki\EnterpriseWikiCrossPageReconciliationService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 8K-4, first slice — post-patch cross-page current-state consistency.
 *
 * Generic throughout: the fixture's "Nimbus Platform" / 120 → 150 units is only a value pair, and
 * nothing about it reaches production logic. The classifier is stubbed so the DETERMINISTIC layer
 * (seeding, page scope, prefilter, exemptions, severity mapping, idempotency, QA integration) is
 * what these tests actually pin down — that layer is where the safety properties live.
 */
class EnterpriseWikiCrossPageConsistencyServiceTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_SENTENCE = 'The maximum supported capacity is 120 units.';

    private const NEW_SENTENCE = 'The maximum supported capacity is 150 units.';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.enterprise_wiki.ai_enabled', true);
    }

    // =========================================================================
    // Seeding — driven by the run's own patch targets
    // =========================================================================

    public function test_only_substance_changed_replace_targets_seed_the_check(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $run = $this->createRun($customer, $document, [
            $this->replaceTarget(1, self::OLD_SENTENCE, self::NEW_SENTENCE),
            $this->preserveTarget(2),
            array_merge($this->replaceTarget(3, 'x', 'y'), ['relationship' => 'topic_extended']),
        ]);

        $assertions = app(EnterpriseWikiCrossPageConsistencyService::class)->changeAssertionsForRun($run);

        $this->assertCount(1, $assertions, 'only substance_changed + replace may seed a change assertion');
        $this->assertSame(self::OLD_SENTENCE, $assertions[0]['old']);
        $this->assertSame(self::NEW_SENTENCE, $assertions[0]['new']);
    }

    public function test_a_run_with_no_substance_change_produces_no_findings(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $this->createPageWithContent($customer, 'Stale Owner', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, self::OLD_SENTENCE);

        $run = $this->createRun($customer, $document, [$this->preserveTarget(1)]);

        $counts = $this->service()->checkForRun($run);

        $this->assertSame(0, $counts['assertions']);
        $this->assertSame(0, $counts['findings_created'], 'an all-preserve run has no superseded substance to look for');
    }

    // =========================================================================
    // Core cases
    // =========================================================================

    public function test_targeted_owner_that_was_updated_produces_no_finding(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $target = $this->createPageWithContent($customer, 'Canonical Procedure', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, self::NEW_SENTENCE);

        $run = $this->createRun($customer, $document, [$this->replaceTarget($target->id, self::OLD_SENTENCE, self::NEW_SENTENCE)]);

        // No stub: the old text is gone and the new text is present, so the deterministic layer
        // settles this without any classification call.
        $this->mock(WikiCrossPageConsistencyAiClient::class)->shouldNotReceive('classify');

        $counts = $this->service()->checkForRun($run);

        $this->assertSame(0, $counts['findings_created']);
        $this->assertSame(0, $counts['classified'], 'a resolved page must not cost a classification call');
    }

    public function test_non_targeted_real_owner_with_old_current_assertion_is_blocking(): void
    {
        [$run, $pages] = $this->scenarioWithUntargetedOwner();

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH);

        $counts = $this->service()->checkForRun($run);

        $finding = $this->findingOn($pages['untargeted'], EnterpriseWikiLintFinding::CODE_STALE_CURRENT_ASSERTION);

        $this->assertNotNull($finding, 'a non-targeted page still asserting the old value must be reported');
        $this->assertSame(EnterpriseWikiLintFinding::SEVERITY_ERROR, $finding->severity);
        $this->assertTrue($finding->isBlocking());
        $this->assertSame(1, $counts['errors']);
    }

    public function test_high_confidence_non_target_current_assertion_is_reconciled_before_final_check(): void
    {
        [$run, $pages] = $this->scenarioWithUntargetedOwner();

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH);

        $result = app(EnterpriseWikiCrossPageReconciliationService::class)->reconcileForRun($run);

        $this->assertSame(1, $result['discovered']);
        $this->assertSame(1, $result['validated']);
        $this->assertSame(1, $result['pages_patched']);
        $this->assertSame(1, $result['targets_applied']);
        $this->assertStringContainsString(self::NEW_SENTENCE, $pages['untargeted']->fresh()->currentVersion->content_markdown);
        $this->assertStringNotContainsString(self::OLD_SENTENCE, $pages['untargeted']->fresh()->currentVersion->content_markdown);

        $record = $run->fresh()->maintainer_decision_json['cross_page_reconciliation'] ?? [];
        $this->assertCount(1, $record['derived_patch_targets'] ?? []);
        $this->assertSame([], $record['unresolved'] ?? []);
    }

    public function test_paraphrased_numeric_assertion_with_unicode_dash_is_reconciled_as_a_bounded_token_replacement(): void
    {
        [$run, $pages] = $this->scenarioWithUntargetedOwner('Confirm P1‑capacity does not exceed 120 units before approving.');

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH);

        $result = app(EnterpriseWikiCrossPageReconciliationService::class)->reconcileForRun($run);

        $this->assertSame(1, $result['pages_patched']);
        $markdown = $pages['untargeted']->fresh()->currentVersion->content_markdown;
        $this->assertStringContainsString('P1‑capacity does not exceed 150 units before approving.', $markdown);
        $this->assertStringNotContainsString('120 units', $markdown);
    }

    public function test_ambiguous_paraphrased_value_fails_closed_and_is_not_patched(): void
    {
        [$run, $pages] = $this->scenarioWithUntargetedOwner('The 120 units operational threshold is checked against a separate 120 units planning value.');

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH);

        $result = app(EnterpriseWikiCrossPageReconciliationService::class)->reconcileForRun($run);

        $this->assertSame(1, $result['discovered']);
        $this->assertSame(0, $result['validated']);
        $this->assertSame(0, $result['pages_patched']);
        $this->assertGreaterThan(0, $result['unresolved']);
        $this->assertStringContainsString('120 units', $pages['untargeted']->fresh()->currentVersion->content_markdown);
    }

    public function test_two_current_pages_asserting_old_and_new_is_a_conflict(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $target = $this->createPageWithContent($customer, 'Canonical Procedure', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, self::NEW_SENTENCE);

        // One page asserts BOTH states as current — a self-contradiction rather than merely stale.
        $conflicting = $this->createPageWithContent(
            $customer,
            'Operations Guide',
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            self::OLD_SENTENCE."\n\nElsewhere the same guide states: ".self::NEW_SENTENCE,
        );

        $run = $this->createRun($customer, $document, [$this->replaceTarget($target->id, self::OLD_SENTENCE, self::NEW_SENTENCE)]);

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH);

        $this->service()->checkForRun($run);

        $finding = $this->findingOn($conflicting, EnterpriseWikiLintFinding::CODE_CROSS_PAGE_CURRENT_CONFLICT);

        $this->assertNotNull($finding);
        $this->assertTrue($finding->isBlocking());
    }

    public function test_concept_page_without_the_concrete_value_is_never_an_occurrence(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $target = $this->createPageWithContent($customer, 'Canonical Procedure', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, self::NEW_SENTENCE);

        $concept = $this->createPageWithContent(
            $customer,
            'Capacity Management',
            EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'Capacity management describes how capacity is planned and reviewed. The current limit is '
            .'defined in [[canonical-procedure|Canonical Procedure]].',
        );

        $run = $this->createRun($customer, $document, [$this->replaceTarget($target->id, self::OLD_SENTENCE, self::NEW_SENTENCE)]);

        $this->mock(WikiCrossPageConsistencyAiClient::class)->shouldNotReceive('classify');

        $this->service()->checkForRun($run);

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()->where('enterprise_wiki_page_id', $concept->id)->count(),
            'a page that never states the concrete value must not be flagged just for sharing the topic',
        );
    }

    public function test_source_change_document_page_is_exempt_without_any_classification_call(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $target = $this->createPageWithContent($customer, 'Canonical Procedure', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, self::NEW_SENTENCE);

        // The change record legitimately states old → new.
        $changeNote = $this->createPageWithContent(
            $customer,
            'Capacity Decision Record',
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'This record supersedes the previous limit. '.self::OLD_SENTENCE.' is replaced by '.self::NEW_SENTENCE,
            slug: 'capacity-decision-record',
        );

        $run = $this->createRun(
            $customer,
            $document,
            [$this->replaceTarget($target->id, self::OLD_SENTENCE, self::NEW_SENTENCE)],
            sourceArticleSlug: $changeNote->slug,
        );

        $this->mock(WikiCrossPageConsistencyAiClient::class)->shouldNotReceive('classify');

        $this->service()->checkForRun($run);

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()->where('enterprise_wiki_page_id', $changeNote->id)->count(),
            'a source/change-document page must be exempt structurally, not by title keywords',
        );
    }

    public function test_historical_wording_on_a_canonical_page_is_a_non_blocking_quality_finding(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $target = $this->createPageWithContent($customer, 'Canonical Procedure', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, self::NEW_SENTENCE);

        $historyWording = $this->createPageWithContent(
            $customer,
            'Operations Guide',
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'The capacity limit was previously 120 units and is now 150 units.',
        );

        $run = $this->createRun($customer, $document, [$this->replaceTarget($target->id, self::OLD_SENTENCE, self::NEW_SENTENCE)]);

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_HISTORICAL_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH);

        $counts = $this->service()->checkForRun($run);

        $finding = $this->findingOn($historyWording, EnterpriseWikiLintFinding::CODE_HISTORICAL_WORDING_IN_CURRENT_CANONICAL_CONTENT);

        $this->assertNotNull($finding, 'current canonical content carrying change history is a quality signal');
        $this->assertSame(EnterpriseWikiLintFinding::SEVERITY_WARNING, $finding->severity);
        $this->assertFalse($finding->isBlocking(), 'historical wording must not block a run in the first version');
        $this->assertSame(0, $counts['errors']);

        $this->assertNull(
            $this->findingOn($historyWording, EnterpriseWikiLintFinding::CODE_STALE_CURRENT_ASSERTION),
            'historical wording is a separate concern from stale knowledge and must never be reported as stale',
        );
    }

    public function test_old_value_in_purely_historical_context_is_not_stale(): void
    {
        [$run, $pages] = $this->scenarioWithUntargetedOwner(
            'Until the revision, the maximum supported capacity was 120 units.'
        );

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_HISTORICAL_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH);

        $counts = $this->service()->checkForRun($run);

        $this->assertSame(0, $counts['errors'], 'a purely historical mention must never be blocking');
        $this->assertNull($this->findingOn($pages['untargeted'], EnterpriseWikiLintFinding::CODE_STALE_CURRENT_ASSERTION));
    }

    public function test_medium_confidence_current_assertion_degrades_to_non_blocking_unknown(): void
    {
        [$run, $pages] = $this->scenarioWithUntargetedOwner();

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_MEDIUM);

        $counts = $this->service()->checkForRun($run);

        $finding = $this->findingOn($pages['untargeted'], EnterpriseWikiLintFinding::CODE_CROSS_PAGE_CONSISTENCY_UNKNOWN);

        $this->assertNotNull($finding, 'an uncertain reading must still be surfaced');
        $this->assertFalse($finding->isBlocking(), 'the system must not block on what it is unsure about');
        $this->assertSame(0, $counts['errors']);
    }

    public function test_unknown_classification_is_non_blocking(): void
    {
        [$run, $pages] = $this->scenarioWithUntargetedOwner();

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_UNKNOWN, WikiCrossPageConsistencyAiClient::CONFIDENCE_LOW);

        $this->service()->checkForRun($run);

        $this->assertFalse(
            $this->findingOn($pages['untargeted'], EnterpriseWikiLintFinding::CODE_CROSS_PAGE_CONSISTENCY_UNKNOWN)->isBlocking()
        );
    }

    public function test_a_classifier_failure_becomes_a_non_blocking_unknown(): void
    {
        [$run, $pages] = $this->scenarioWithUntargetedOwner();

        $this->mock(WikiCrossPageConsistencyAiClient::class)
            ->shouldReceive('classify')
            ->andThrow(new \RuntimeException('upstream unavailable'));

        $counts = $this->service()->checkForRun($run);

        $this->assertSame(0, $counts['errors'], 'a classifier outage must never block a technically sound run');
        $this->assertNotNull($this->findingOn($pages['untargeted'], EnterpriseWikiLintFinding::CODE_CROSS_PAGE_CONSISTENCY_UNKNOWN));
    }

    public function test_multiple_owners_all_updated_is_clean(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $a = $this->createPageWithContent($customer, 'Canonical Procedure', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, self::NEW_SENTENCE);
        $b = $this->createPageWithContent($customer, 'Operations Guide', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Operators must not exceed 150 units.');

        $run = $this->createRun($customer, $document, [
            $this->replaceTarget($a->id, self::OLD_SENTENCE, self::NEW_SENTENCE),
            $this->replaceTarget($b->id, 'must not exceed 120 units', 'must not exceed 150 units'),
        ]);

        $this->mock(WikiCrossPageConsistencyAiClient::class)->shouldNotReceive('classify');

        $counts = $this->service()->checkForRun($run);

        $this->assertSame(0, $counts['findings_created']);
    }

    // =========================================================================
    // Coverage — independent of candidate discovery's cap
    // =========================================================================

    public function test_an_owner_far_outside_the_candidate_cap_is_still_detected(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $target = $this->createPageWithContent($customer, 'Canonical Procedure', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, self::NEW_SENTENCE);

        // Far more pages than any candidate cap would ever offer the maintainer. Only the last one
        // carries the superseded value, so the post-check must have looked past the cap to find it.
        for ($i = 0; $i < 12; $i++) {
            $this->createPageWithContent($customer, "Unrelated Page {$i}", EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Unrelated content.');
        }

        $late = $this->createPageWithContent($customer, 'Late Owner', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, self::OLD_SENTENCE);

        $run = $this->createRun($customer, $document, [$this->replaceTarget($target->id, self::OLD_SENTENCE, self::NEW_SENTENCE)]);

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH);

        $counts = $this->service()->checkForRun($run);

        $this->assertGreaterThan(12, $counts['pages_considered'], 'the post-check must not inherit the candidate cap');
        $this->assertNotNull($this->findingOn($late, EnterpriseWikiLintFinding::CODE_STALE_CURRENT_ASSERTION));
    }

    public function test_a_paraphrased_restatement_is_found_by_value_anchor(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $target = $this->createPageWithContent($customer, 'Canonical Procedure', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, self::NEW_SENTENCE);

        // Different wording, same superseded value — an exact-substring-only sweep would miss it.
        $paraphrased = $this->createPageWithContent(
            $customer,
            'Deployment Checklist',
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'Confirm that the requested capacity stays within 120 units before approving.',
        );

        $run = $this->createRun($customer, $document, [$this->replaceTarget($target->id, self::OLD_SENTENCE, self::NEW_SENTENCE)]);

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH);

        $this->service()->checkForRun($run);

        $finding = $this->findingOn($paraphrased, EnterpriseWikiLintFinding::CODE_STALE_CURRENT_ASSERTION);

        $this->assertNotNull($finding);
        $this->assertSame('value_anchor', $finding->metadata['prefilter_signal'] ?? null);
    }

    // =========================================================================
    // Scope safety
    // =========================================================================

    public function test_another_customers_page_is_never_examined(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $target = $this->createPageWithContent($customer, 'Canonical Procedure', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, self::NEW_SENTENCE);

        $other = $this->createCustomer('Other Tenant AS');
        $foreign = $this->createPageWithContent($other, 'Foreign Owner', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, self::OLD_SENTENCE);

        $run = $this->createRun($customer, $document, [$this->replaceTarget($target->id, self::OLD_SENTENCE, self::NEW_SENTENCE)]);

        $this->mock(WikiCrossPageConsistencyAiClient::class)->shouldNotReceive('classify');

        $this->service()->checkForRun($run);

        $this->assertSame(0, EnterpriseWikiLintFinding::query()->where('enterprise_wiki_page_id', $foreign->id)->count());
    }

    public function test_archived_and_superseded_pages_are_ignored(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $target = $this->createPageWithContent($customer, 'Canonical Procedure', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, self::NEW_SENTENCE);

        $ignored = [];

        foreach ([EnterpriseWikiPage::STATUS_ARCHIVED, EnterpriseWikiPage::STATUS_SUPERSEDED, EnterpriseWikiPage::STATUS_REJECTED] as $index => $status) {
            $page = $this->createPageWithContent($customer, "Retired {$index}", EnterpriseWikiPage::PAGE_TYPE_ARTICLE, self::OLD_SENTENCE);
            $page->update(['status' => $status]);
            $ignored[] = $page;
        }

        $run = $this->createRun($customer, $document, [$this->replaceTarget($target->id, self::OLD_SENTENCE, self::NEW_SENTENCE)]);

        $this->mock(WikiCrossPageConsistencyAiClient::class)->shouldNotReceive('classify');

        $this->service()->checkForRun($run);

        foreach ($ignored as $page) {
            $this->assertSame(0, EnterpriseWikiLintFinding::query()->where('enterprise_wiki_page_id', $page->id)->count());
        }
    }

    public function test_only_the_current_version_is_examined(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $target = $this->createPageWithContent($customer, 'Canonical Procedure', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, self::NEW_SENTENCE);

        // The old value survives only in a superseded version — history by definition.
        $page = $this->createPageWithContent($customer, 'Already Fixed', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, self::OLD_SENTENCE);
        $page->versions()->update(['is_current' => false]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => self::NEW_SENTENCE,
            'generated_by_model' => 'deterministic/section-patch',
        ]);

        $run = $this->createRun($customer, $document, [$this->replaceTarget($target->id, self::OLD_SENTENCE, self::NEW_SENTENCE)]);

        $this->mock(WikiCrossPageConsistencyAiClient::class)->shouldNotReceive('classify');

        $this->service()->checkForRun($run);

        $this->assertSame(0, EnterpriseWikiLintFinding::query()->where('enterprise_wiki_page_id', $page->id)->count());
    }

    // =========================================================================
    // Detection only — no mutation
    // =========================================================================

    public function test_the_check_never_mutates_any_page(): void
    {
        [$run, $pages] = $this->scenarioWithUntargetedOwner();

        $before = EnterpriseWikiPageVersion::query()->orderBy('id')->get()
            ->map(fn (EnterpriseWikiPageVersion $v): string => $v->id.':'.md5((string) $v->content_markdown))
            ->all();
        $versionCountBefore = EnterpriseWikiPageVersion::query()->count();

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH);

        $this->service()->checkForRun($run);

        $after = EnterpriseWikiPageVersion::query()->orderBy('id')->get()
            ->map(fn (EnterpriseWikiPageVersion $v): string => $v->id.':'.md5((string) $v->content_markdown))
            ->all();

        $this->assertSame($before, $after, '8K-4 detection must not rewrite content');
        $this->assertSame($versionCountBefore, EnterpriseWikiPageVersion::query()->count(), 'no new version may be written');
        $this->assertNotNull($this->findingOn($pages['untargeted'], EnterpriseWikiLintFinding::CODE_STALE_CURRENT_ASSERTION));
    }

    public function test_provenance_of_the_examined_page_is_untouched(): void
    {
        [$run, $pages] = $this->scenarioWithUntargetedOwner();
        $before = $pages['untargeted']->versions()->where('is_current', true)->firstOrFail()->content_blocks_json;

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH);

        $this->service()->checkForRun($run);

        $this->assertSame(
            $before,
            $pages['untargeted']->fresh()->versions()->where('is_current', true)->firstOrFail()->content_blocks_json,
        );
    }

    // =========================================================================
    // Idempotency and scoping
    // =========================================================================

    public function test_rerunning_the_check_does_not_duplicate_findings(): void
    {
        [$run, $pages] = $this->scenarioWithUntargetedOwner();

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH);

        $this->service()->checkForRun($run);
        $this->service()->checkForRun($run->fresh());

        $this->assertSame(
            1,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $pages['untargeted']->id)
                ->where('code', EnterpriseWikiLintFinding::CODE_STALE_CURRENT_ASSERTION)
                ->count(),
        );
    }

    public function test_a_finding_is_resolved_once_the_page_no_longer_asserts_the_old_value(): void
    {
        [$run, $pages] = $this->scenarioWithUntargetedOwner();

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH);
        $this->service()->checkForRun($run);

        // Someone fixes the page by hand; a later pass must retract the finding.
        $pages['untargeted']->versions()->update(['is_current' => false]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $pages['untargeted']->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => self::NEW_SENTENCE,
            'generated_by_model' => 'manual',
        ]);

        $this->service()->checkForRun($run->fresh());

        $this->assertSame(
            0,
            EnterpriseWikiLintFinding::query()
                ->where('enterprise_wiki_page_id', $pages['untargeted']->id)
                ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
                ->count(),
        );
    }

    public function test_findings_are_scoped_to_the_run_and_the_examined_version(): void
    {
        [$run, $pages] = $this->scenarioWithUntargetedOwner();

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH);
        $this->service()->checkForRun($run);

        $finding = $this->findingOn($pages['untargeted'], EnterpriseWikiLintFinding::CODE_STALE_CURRENT_ASSERTION);
        $currentVersionId = $pages['untargeted']->versions()->where('is_current', true)->firstOrFail()->id;

        $this->assertSame($run->id, $finding->enterprise_wiki_ingest_run_id);
        $this->assertSame($currentVersionId, $finding->enterprise_wiki_page_version_id);
        $this->assertSame($run->customer_id, $finding->customer_id);
    }

    public function test_observability_metadata_explains_why_the_page_was_flagged(): void
    {
        [$run, $pages] = $this->scenarioWithUntargetedOwner();

        $this->stubClassification(
            WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION,
            WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH,
            reason: 'The sentence states the limit without any temporal qualifier.',
        );

        $this->service()->checkForRun($run);

        $metadata = $this->findingOn($pages['untargeted'], EnterpriseWikiLintFinding::CODE_STALE_CURRENT_ASSERTION)->metadata;

        foreach (['topic', 'old_substance', 'new_substance', 'prefilter_signal', 'classification', 'confidence', 'classified_by', 'reason'] as $key) {
            $this->assertArrayHasKey($key, $metadata, "metadata must record [{$key}] so the verdict is explainable after the run");
        }

        $this->assertSame('exact_old_substance', $metadata['prefilter_signal']);
        $this->assertSame('ai', $metadata['classified_by']);
        $this->assertFalse($metadata['is_patch_target']);
    }

    // =========================================================================
    // Applied-run lint must not resolve this pass's findings, or vice versa
    // =========================================================================

    public function test_applied_run_lint_does_not_resolve_cross_page_findings(): void
    {
        [$run, $pages] = $this->scenarioWithUntargetedOwner();

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH);
        $this->service()->checkForRun($run);

        $run->update(['maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED]);
        app(EnterpriseWikiAppliedRunLintService::class)->lint($run->fresh());

        $this->assertSame(
            EnterpriseWikiLintFinding::STATUS_OPEN,
            $this->findingOn($pages['untargeted'], EnterpriseWikiLintFinding::CODE_STALE_CURRENT_ASSERTION)->status,
            'the two passes own separate codes and must not resolve each other\'s findings',
        );
    }

    // =========================================================================
    // QA integration — via existing aggregation, no special-casing
    // =========================================================================

    public function test_qa_blocks_on_a_high_confidence_stale_assertion(): void
    {
        [$run] = $this->scenarioWithUntargetedOwner(qaReady: true);

        $this->stubClassification(WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH);
        $this->service()->checkForRun($run);

        $evaluation = app(EnterpriseWikiPostIngestQaService::class)->evaluate($run->fresh());

        $this->assertContains('critical_lint_findings_or_broken_links', $evaluation['critical_defects']);
    }

    public function test_qa_does_not_block_on_historical_wording_or_unknown(): void
    {
        foreach ([
            [WikiCrossPageConsistencyAiClient::CLASSIFICATION_HISTORICAL_ASSERTION, WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH],
            [WikiCrossPageConsistencyAiClient::CLASSIFICATION_UNKNOWN, WikiCrossPageConsistencyAiClient::CONFIDENCE_LOW],
        ] as [$classification, $confidence]) {
            $this->refreshDatabase();

            [$run] = $this->scenarioWithUntargetedOwner(
                'The capacity limit was previously 120 units and is now 150 units.',
                qaReady: true,
            );

            $this->stubClassification($classification, $confidence);
            $this->service()->checkForRun($run);

            $evaluation = app(EnterpriseWikiPostIngestQaService::class)->evaluate($run->fresh());

            $this->assertNotContains(
                'critical_lint_findings_or_broken_links',
                $evaluation['critical_defects'],
                "[{$classification}/{$confidence}] must never block QA",
            );
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): EnterpriseWikiCrossPageConsistencyService
    {
        return app(EnterpriseWikiCrossPageConsistencyService::class);
    }

    private function stubClassification(string $classification, string $confidence, string $reason = 'stubbed'): void
    {
        $this->mock(WikiCrossPageConsistencyAiClient::class)
            ->shouldReceive('classify')
            ->andReturn([
                'classification' => $classification,
                'confidence' => $confidence,
                'evidence_excerpt' => 'stubbed excerpt',
                'reason' => $reason,
                'model' => 'stub/1.0',
            ]);
    }

    /**
     * One patched (already-correct) owner plus one page the decision never targeted, which still
     * carries the superseded substance — the shape the characterization test proved goes undetected.
     *
     * @return array{0: EnterpriseWikiIngestRun, 1: array<string, EnterpriseWikiPage>}
     */
    private function scenarioWithUntargetedOwner(?string $untargetedContent = null, bool $qaReady = false): array
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $target = $this->createPageWithContent($customer, 'Canonical Procedure', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, self::NEW_SENTENCE);
        $untargeted = $this->createPageWithContent(
            $customer,
            'Operations Guide',
            EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            $untargetedContent ?? self::OLD_SENTENCE,
        );

        $run = $this->createRun($customer, $document, [$this->replaceTarget($target->id, self::OLD_SENTENCE, self::NEW_SENTENCE)]);

        if ($qaReady) {
            // QA requires the run's own article + summary to exist with content.
            $article = $this->createPageWithContent($customer, 'Decision Record', EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'The record states the change.');
            $summary = $this->createPageWithContent($customer, 'Summary: Decision Record', EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Short summary of the change.');

            foreach ([$article, $summary] as $page) {
                EnterpriseWikiIngestRunPage::query()->create([
                    'enterprise_wiki_ingest_run_id' => $run->id,
                    'enterprise_wiki_page_id' => $page->id,
                    'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
                    'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
                    'claims_extracted_at' => now(),
                ]);
            }

            $run->update(['maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED]);
            $run = $run->fresh();
        }

        return [$run, ['target' => $target, 'untargeted' => $untargeted]];
    }

    private function findingOn(EnterpriseWikiPage $page, string $code): ?EnterpriseWikiLintFinding
    {
        return EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('code', $code)
            ->first();
    }

    /** @param list<array<string, mixed>> $patchTargets */
    private function createRun(
        Customer $customer,
        EnterpriseWikiDocument $document,
        array $patchTargets,
        ?string $sourceArticleSlug = null,
    ): EnterpriseWikiIngestRun {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'trigger_type' => 'manual',
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_json' => [
                'source_article' => [
                    'action' => 'create',
                    'title' => 'Decision Record',
                    'proposed_slug' => $sourceArticleSlug ?? 'decision-record',
                    'reason' => 'r',
                    'owned_topics' => [],
                ],
                'source_summary' => [
                    'action' => 'create',
                    'title' => 'Summary: Decision Record',
                    'proposed_slug' => 'summary-decision-record',
                    'reason' => 'r',
                    'owned_topics' => [],
                ],
                'concept_pages' => [],
                'entity_pages' => [],
                'patch_targets' => $patchTargets,
                'no_action_reason' => null,
                'warnings' => [],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function replaceTarget(int $pageId, string $old, string $new): array
    {
        return [
            'target_page_id' => $pageId,
            'target_page_title' => 'Canonical Procedure',
            'target_page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'target_topic' => 'Supported capacity limit',
            'target_heading' => null,
            'relationship' => 'substance_changed',
            'operation' => 'replace',
            'superseded_substance' => $old,
            'replacement_substance' => $new,
            'source_element_keys' => ['paragraph-2'],
            'preserve_topics' => [],
            'reason' => 'The source supersedes the previous value.',
        ];
    }

    /** @return array<string, mixed> */
    private function preserveTarget(int $pageId): array
    {
        return [
            'target_page_id' => $pageId,
            'target_page_title' => 'Capacity Management',
            'target_page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'target_topic' => 'Capacity management concept',
            'target_heading' => null,
            'relationship' => 'reference_only',
            'operation' => 'preserve',
            'superseded_substance' => null,
            'replacement_substance' => null,
            'source_element_keys' => [],
            'preserve_topics' => ['Capacity management concept'],
            'reason' => 'Conceptual page defers the concrete value to its owner.',
        ];
    }

    private function createPageWithContent(
        Customer $customer,
        string $title,
        string $pageType,
        string $markdown,
        ?string $slug = null,
    ): EnterpriseWikiPage {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug ?? Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# {$title}\n\n{$markdown}",
            'content_blocks_json' => [
                ['block_key' => 'block-0001', 'position' => 0, 'markdown' => "# {$title}", 'content_origin' => 'structural', 'source_elements' => []],
                ['block_key' => 'block-0002', 'position' => 1, 'markdown' => $markdown, 'content_origin' => 'source_based', 'source_elements' => []],
            ],
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }

    private function createCustomer(string $name = 'Cross Page Consistency AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

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
            'original_filename' => 'capacity-decision.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'The maximum supported capacity changes from 120 units to 150 units.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }
}
