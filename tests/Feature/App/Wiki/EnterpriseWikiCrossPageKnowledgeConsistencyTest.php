<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiCrossPageConsistencyAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Services\EnterpriseWiki\EnterpriseWikiCanonicalOwnershipValidator;
use App\Services\EnterpriseWiki\EnterpriseWikiCrossPageConsistencyService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionApplyService;
use App\Services\EnterpriseWiki\EnterpriseWikiPatchApplicationService;
use App\Services\EnterpriseWiki\EnterpriseWikiPatchCandidateService;
use App\Services\EnterpriseWiki\EnterpriseWikiPatchTargetResolver;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Architecture probe: does the pipeline distinguish a page that OWNS concrete, change-exposed
 * substance from a page that merely EXPLAINS the topic — and does anything guarantee that all
 * owners converge after an authoritative change?
 *
 * Deliberately synthetic ("Nimbus Platform", 120 → 150 units). No production vocabulary, and no
 * production logic is touched: every service under test is the real one, and the only thing this
 * file supplies is Wiki state, a change document, and candidate decision payloads.
 *
 * This is a CHARACTERIZATION test. Where it documents a gap it asserts the CURRENT behaviour, so
 * the assertion flips the day the gap is closed — that failure is the signal, not a regression.
 */
class EnterpriseWikiCrossPageKnowledgeConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_VALUE = '120 units';

    private const NEW_VALUE = '150 units';

    /** The exact sentence PAGE A owns, quoted verbatim by the replace targets below. */
    private const OWNED_SENTENCE_A = 'The maximum supported capacity is 120 units.';

    private const OWNED_SENTENCE_C = 'Operators must ensure that deployed capacity does not exceed 120 units.';

    private const CHANGE_TEXT = 'Capacity Decision Record CDR-0007. Effective immediately, the maximum '
        .'supported capacity for Nimbus Platform changes from 120 units to 150 units. This decision '
        .'supersedes the previously stated limit and does not introduce a new independent topic. All '
        .'operational documentation that states the supported capacity must reflect 150 units.';

    // =========================================================================
    // DELTEST 1 — canonical ownership vs conceptual/reference knowledge
    // =========================================================================

    public function test_canonical_owner_accepts_a_substance_changed_replace(): void
    {
        [$customer, , $pages] = $this->existingWiki();

        $errors = $this->resolver()->resolveForCustomer($customer->id, $this->decision([
            $this->replaceTarget($pages['owner_a'], 'Capacity limits', self::OWNED_SENTENCE_A),
        ]))['errors'];

        $this->assertSame([], $errors, 'the canonical owner must accept a substance_changed replace');
    }

    public function test_concept_page_can_be_preserved_as_reference_only(): void
    {
        [$customer, , $pages] = $this->existingWiki();

        $errors = $this->resolver()->resolveForCustomer($customer->id, $this->decision([
            $this->preserveTarget($pages['concept_b']),
        ]))['errors'];

        $this->assertSame([], $errors, 'a concept page must be expressible as reference_only/preserve');
    }

    public function test_concept_page_cannot_be_retrofitted_as_owner_of_substance_it_never_stated(): void
    {
        [$customer, , $pages] = $this->existingWiki();

        // The structural safeguard: PAGE B never stated the old concrete value, so a replace that
        // claims to supersede it cannot be expressed at all. This is what stops a conceptual page
        // from silently becoming the owner of a concrete threshold.
        $errors = $this->resolver()->resolveForCustomer($customer->id, $this->decision([
            $this->replaceTarget($pages['concept_b'], null, self::OWNED_SENTENCE_A),
        ]))['errors'];

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString(
            'not present verbatim',
            implode(' | ', $errors),
            'the contract must refuse to supersede text the page does not contain',
        );
    }

    public function test_a_new_page_cannot_be_created_for_a_topic_an_existing_page_already_owns(): void
    {
        [, , $pages] = $this->existingWiki();

        $decision = $this->decision([]);
        $decision['concept_candidates'] = [$this->candidateEntry([
            'name' => 'Capacity Management',
            'decision' => 'create',
            // An existing page already owns this topic, so the topic is not independent.
            'relationship' => 'topic_extended',
            'existing_owner_page_id' => $pages['concept_b']->id,
        ])];
        $decision['concept_pages'] = [$this->pageEntry([
            'title' => 'Capacity Management',
            'proposed_slug' => 'capacity-management-dup',
            'owned_topics' => ['Capacity management'],
        ])];

        $issues = $this->ownershipValidator()->findIssues($decision, $this->indexContextFor($pages));

        $this->assertNotSame([], $issues, 'the create-gate must refuse a duplicate owner for an existing topic');
        $this->assertStringContainsString(
            'independent_new_topic',
            implode(' | ', $issues),
            'the gate must state that a new canonical page requires an independent topic',
        );
    }

    public function test_two_pages_cannot_both_be_planned_as_canonical_owner_of_one_topic(): void
    {
        [, , $pages] = $this->existingWiki();

        $decision = $this->decision([
            $this->replaceTarget($pages['owner_a'], 'Capacity limits', self::OWNED_SENTENCE_A),
        ]);

        // The same page is both patched as an existing owner and planned as a fresh concept page.
        $decision['concept_pages'] = [$this->pageEntry([
            'title' => $pages['owner_a']->title,
            'proposed_slug' => $pages['owner_a']->slug,
            'owned_topics' => ['Supported capacity limit'],
        ])];

        $issues = $this->ownershipValidator()->findIssues($decision, $this->indexContextFor($pages));

        $this->assertNotSame([], $issues, 'one page must not be both a patch target and a planned page');
    }

    // =========================================================================
    // DELTEST 2 — one change, several legitimate owners
    // =========================================================================

    public function test_one_change_can_target_several_legitimate_owners(): void
    {
        [$customer, $document, $pages] = $this->existingWiki();

        $decision = $this->decision([
            $this->replaceTarget($pages['owner_a'], 'Capacity limits', self::OWNED_SENTENCE_A),
            $this->replaceTarget($pages['owner_c'], 'Operating constraints', self::OWNED_SENTENCE_C),
            $this->preserveTarget($pages['concept_b']),
        ]);

        $this->assertSame([], $this->resolver()->resolveForCustomer($customer->id, $decision)['errors']);

        $run = $this->runWithDecision($customer, $document, $decision);
        $this->applyService()->apply($run);
        $result = app(EnterpriseWikiPatchApplicationService::class)->applyForRun($run->fresh());

        $this->assertSame([], $result['failures'], 'patching reported failures');
        $this->assertSame(2, $result['pages_patched'], 'both real owners must be patched by one change: '.json_encode($result));
        $this->assertSame(2, $result['targets_applied']);

        foreach (['owner_a', 'owner_c'] as $key) {
            $markdown = $this->currentMarkdown($pages[$key]);
            $this->assertStringContainsString(self::NEW_VALUE, $markdown);
            $this->assertStringNotContainsString(self::OLD_VALUE, $markdown);
        }

        // The preserved concept page must not have been touched at all.
        $this->assertSame(1, $pages['concept_b']->versions()->count(), 'preserve must write no version');
        $this->assertStringNotContainsString(self::NEW_VALUE, $this->currentMarkdown($pages['concept_b']));
        $this->assertStringNotContainsString(self::OLD_VALUE, $this->currentMarkdown($pages['concept_b']));
    }

    // =========================================================================
    // DELTEST 3 — candidate coverage, using the REAL discovery service
    // =========================================================================

    public function test_candidate_discovery_cannot_cover_every_real_owner(): void
    {
        [, $document, $pages] = $this->existingWiki();

        $candidates = app(EnterpriseWikiPatchCandidateService::class)->findForDocument($document);
        $candidateIds = array_column($candidates, 'page_id');

        $this->reportRanking($candidates, $pages);

        $this->assertLessThanOrEqual(
            EnterpriseWikiPatchCandidateService::MAX_CANDIDATES,
            count($candidates),
            'candidate discovery must respect its own cap',
        );

        // Which pages actually assert the old value as CURRENT knowledge (the history page is
        // excluded — it states the old value only as a superseded reference).
        $realOwners = $this->realOwnerKeys();
        $this->assertGreaterThan(
            EnterpriseWikiPatchCandidateService::MAX_CANDIDATES,
            count($realOwners),
            'the fixture must present more real owners than the cap can carry',
        );

        $uncovered = array_values(array_filter(
            $realOwners,
            fn (string $key): bool => ! in_array($pages[$key]->id, $candidateIds, true),
        ));

        // Not hardcoded to a specific page — measured from the real ranking.
        $this->assertNotSame(
            [],
            $uncovered,
            'CHARACTERIZATION: with more real owners than MAX_CANDIDATES, at least one owner is '
            .'never offered to the maintainer. This is a candidate coverage gap, not a patch engine '
            .'defect — the engine is never given the chance to touch these pages.',
        );
    }

    public function test_a_historical_reference_page_competes_for_candidate_slots(): void
    {
        [, $document, $pages] = $this->existingWiki();

        $candidates = app(EnterpriseWikiPatchCandidateService::class)->findForDocument($document);
        $candidateIds = array_column($candidates, 'page_id');

        // The substance signal is numeric-anchored and has no notion of tense, so a page that only
        // records the change historically scores like a real owner and can occupy a capped slot.
        // Measured from the real ranking, not assumed: the history page scores HIGHEST of all
        // (substance=2 — it contains both the old and the new value, matching more numeric bigrams
        // than any real owner) and therefore occupies a capped slot ahead of pages that actually
        // need patching. Ordering is deterministic, so this is a stable assertion.
        $this->assertContains(
            $pages['history_f']->id,
            $candidateIds,
            'CHARACTERIZATION: a purely historical page is offered as a patch candidate.',
        );

        $ownersOutside = array_values(array_filter(
            $this->realOwnerKeys(),
            fn (string $key): bool => ! in_array($pages[$key]->id, $candidateIds, true),
        ));

        $this->assertNotSame(
            [],
            $ownersOutside,
            'CHARACTERIZATION: the historical page holds a candidate slot while real owners fall '
            .'outside the cap — the ranking has no notion of tense.',
        );
    }

    // =========================================================================
    // DELTEST 4 — post-patch cross-page state
    // =========================================================================

    public function test_pipeline_does_not_guarantee_cross_page_knowledge_consistency(): void
    {
        [$customer, $document, $pages] = $this->existingWiki();

        // Patch only what candidate discovery could actually offer — i.e. the best case the current
        // pipeline can reach without a human naming extra pages.
        $candidateIds = array_column(
            app(EnterpriseWikiPatchCandidateService::class)->findForDocument($document),
            'page_id',
        );

        $targets = [];

        foreach ($this->realOwnerKeys() as $key) {
            $page = $pages[$key];

            if (! in_array($page->id, $candidateIds, true)) {
                continue;
            }

            $targets[] = $this->replaceTarget($page, $this->headingFor($key), $this->ownedSentenceFor($key));
        }

        $this->assertNotSame([], $targets, 'at least one real owner must be reachable');

        $decision = $this->decision($targets);
        $this->assertSame([], $this->resolver()->resolveForCustomer($customer->id, $decision)['errors']);

        $run = $this->runWithDecision($customer, $document, $decision);
        $this->applyService()->apply($run);
        app(EnterpriseWikiPatchApplicationService::class)->applyForRun($run->fresh());

        // A: every targeted owner is now correct.
        foreach ($targets as $target) {
            $markdown = $this->currentMarkdown(EnterpriseWikiPage::query()->findOrFail($target['target_page_id']));
            $this->assertStringContainsString(self::NEW_VALUE, $markdown);
            $this->assertStringNotContainsString(self::OLD_VALUE, $markdown);
        }

        // B: the concept page stayed conceptual — no concrete value was duplicated into it.
        $this->assertStringNotContainsString(self::OLD_VALUE, $this->currentMarkdown($pages['concept_b']));
        $this->assertStringNotContainsString(self::NEW_VALUE, $this->currentMarkdown($pages['concept_b']));

        // C: sweep EVERY current page for the old value.
        $stillStale = $this->pagesAssertingOldValue($customer);
        $targetedIds = array_column($targets, 'target_page_id');
        $staleOwners = array_values(array_filter(
            $stillStale,
            fn (array $row): bool => ! in_array($row['id'], $targetedIds, true) && $row['key'] !== 'history_f',
        ));

        $this->reportPostPatchState($customer, $targetedIds, $stillStale);

        $this->assertNotSame(
            [],
            $staleOwners,
            'CHARACTERIZATION — Current 8K-2/8K-3 can make the selected owners correct, but the '
            .'pipeline does not yet guarantee cross-page knowledge consistency. Pages left stating '
            .'the old value: '.json_encode(array_column($staleOwners, 'title')),
        );
    }

    /**
     * CLOSED by the Fase 8K-4 first slice. This test previously documented the opposite: that nothing
     * reported the contradiction and QA passed it through. The post-patch cross-page pass now catches
     * it, so the assertions are inverted rather than deleted — the file keeps a record of what the
     * gap was and what closing it changed.
     */
    public function test_the_cross_page_check_blocks_on_the_surviving_stale_substance(): void
    {
        config()->set('services.enterprise_wiki.ai_enabled', true);

        [$customer, $document, $pages] = $this->existingWiki();

        $decision = $this->decision([
            $this->replaceTarget($pages['owner_a'], 'Capacity limits', self::OWNED_SENTENCE_A),
        ]);

        $run = $this->runWithDecision($customer, $document, $decision);
        $this->applyService()->apply($run);
        app(EnterpriseWikiPatchApplicationService::class)->applyForRun($run->fresh());

        $this->giveCreatedPagesContent($run);

        $run->update(['maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED]);
        app(EnterpriseWikiAppliedRunLintService::class)->lint($run->fresh());

        // Owners C/D/E still say 120 units while A says 150 — a direct contradiction inside the
        // same customer's Wiki. 8K-4 detects it; it does not (yet) remediate it.
        $this->assertNotSame([], $this->pagesAssertingOldValue($customer));

        // Page-aware double: what a correct classifier would say for each page. A blanket
        // "everything is a current assertion" stub would not be able to show that the historical
        // page is treated differently, which is half the point of this test.
        $historyTitle = $pages['history_f']->title;

        $this->mock(WikiCrossPageConsistencyAiClient::class)
            ->shouldReceive('classify')
            ->andReturnUsing(static fn (array $occurrence): array => [
                'classification' => $occurrence['page_title'] === $historyTitle
                    ? WikiCrossPageConsistencyAiClient::CLASSIFICATION_HISTORICAL_ASSERTION
                    : WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION,
                'confidence' => WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH,
                'evidence_excerpt' => 'stubbed excerpt',
                'reason' => 'stubbed',
                'model' => 'stub/1.0',
            ]);

        app(EnterpriseWikiCrossPageConsistencyService::class)->checkForRun($run->fresh());

        $blocking = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
            ->blocking()
            ->get();

        $this->assertGreaterThan(
            0,
            $blocking->count(),
            'the non-targeted owners still asserting the old value must now be reported',
        );

        $this->assertContains(
            EnterpriseWikiLintFinding::CODE_STALE_CURRENT_ASSERTION,
            $blocking->pluck('code')->all(),
        );

        // Every page still asserting the old value as current must be named by a finding — the
        // point of the safety net is coverage, not a single example.
        $flaggedPageIds = $blocking->pluck('enterprise_wiki_page_id')->all();

        foreach (['owner_c', 'owner_d'] as $key) {
            $this->assertContains(
                $pages[$key]->id,
                $flaggedPageIds,
                "the untargeted owner [{$key}] must be reported",
            );
        }

        // The historical page must NOT be among them.
        $this->assertNotContains($pages['history_f']->id, $flaggedPageIds);

        $evaluation = app(EnterpriseWikiPostIngestQaService::class)->evaluate($run->fresh());

        $this->assertContains(
            'critical_lint_findings_or_broken_links',
            $evaluation['critical_defects'],
            'QA must no longer pass a self-contradicting Wiki through — via existing aggregation, no special-casing.',
        );
    }

    /**
     * The current-state principle, asserted on real patched output: a canonical page must end up
     * stating current truth only, never carrying "was X, now Y" forward.
     */
    public function test_a_patched_canonical_page_states_only_current_truth(): void
    {
        [$customer, $document, $pages] = $this->existingWiki();

        $decision = $this->decision([
            $this->replaceTarget($pages['owner_a'], 'Capacity limits', self::OWNED_SENTENCE_A),
        ]);

        $run = $this->runWithDecision($customer, $document, $decision);
        $this->applyService()->apply($run);
        app(EnterpriseWikiPatchApplicationService::class)->applyForRun($run->fresh());

        $markdown = $this->currentMarkdown($pages['owner_a']);

        $this->assertStringContainsString(self::NEW_VALUE, $markdown);
        $this->assertStringNotContainsString(
            self::OLD_VALUE,
            $markdown,
            'a patched canonical page must not retain the superseded value alongside the new one — '
            .'the change history belongs in the version log and the source document',
        );
    }

    // =========================================================================
    // DELTEST 5 — history is not staleness
    // =========================================================================

    public function test_a_historical_assertion_must_not_be_treated_as_stale(): void
    {
        [$customer, , $pages] = $this->existingWiki();

        $history = $this->currentMarkdown($pages['history_f']);

        // It contains the old value, so any naive "grep the old value and patch it" rule would
        // rewrite it — and would corrupt a correct historical record.
        $this->assertStringContainsString(self::OLD_VALUE, $history);
        $this->assertStringContainsString(self::NEW_VALUE, $history);
        $this->assertStringContainsString('previously', $history);

        $naiveHits = array_column($this->pagesAssertingOldValue($customer), 'key');

        $this->assertContains(
            'history_f',
            $naiveHits,
            'a pure old-value sweep cannot tell a historical record from a stale assertion — so 8K-4 '
            .'must classify current-vs-historical, not grep and patch',
        );
    }

    public function test_a_replace_on_the_history_page_would_destroy_a_correct_record(): void
    {
        [$customer, , $pages] = $this->existingWiki();

        // Expressible by the contract (the page does contain the text), which is exactly why the
        // decision layer — not a sweep — has to decide. Recorded so a future 8K-4 cannot claim the
        // contract already prevents this.
        $errors = $this->resolver()->resolveForCustomer($customer->id, $this->decision([
            $this->replaceTarget($pages['history_f'], null, 'previously 120 units'),
        ]))['errors'];

        $this->assertSame(
            [],
            $errors,
            'CHARACTERIZATION: nothing structural stops a patch from rewriting a historical '
            .'reference — the safeguard is semantic and therefore still missing.',
        );
    }

    // =========================================================================
    // Fixture
    // =========================================================================

    /**
     * @return array{0: Customer, 1: EnterpriseWikiDocument, 2: array<string, EnterpriseWikiPage>}
     */
    private function existingWiki(): array
    {
        $customer = $this->createCustomer();

        // PAGE A — canonical operational owner of the concrete value.
        $ownerA = $this->createPage($customer, 'Nimbus Capacity Procedure', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->createVersion($ownerA, [
            '# Nimbus Capacity Procedure',
            'This procedure governs how capacity is provisioned and approved for the platform.',
            '## Capacity limits',
            self::OWNED_SENTENCE_A.' Requests beyond the supported limit require an approved exception.',
        ]);

        // PAGE B — concept/reference page. Explains the topic, owns no concrete value.
        $conceptB = $this->createPage($customer, 'Capacity Management', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $this->createVersion($conceptB, [
            '# Capacity Management',
            'Capacity management describes how service capacity is planned, measured, and reviewed.',
            'The current product-specific capacity limit is maintained in '
                ."[[{$ownerA->slug}|Nimbus Capacity Procedure]].",
        ]);

        // PAGE C — second real owner, operational duplication of the same substance.
        $ownerC = $this->createPage($customer, 'Nimbus Operations Guide', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->createVersion($ownerC, [
            '# Nimbus Operations Guide',
            'Day-to-day operating guidance for the platform.',
            '## Operating constraints',
            self::OWNED_SENTENCE_C.' Breaches must be escalated the same working day.',
        ]);

        // PAGE D — third real owner.
        $ownerD = $this->createPage($customer, 'Nimbus Deployment Checklist', EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
        $this->createVersion($ownerD, [
            '# Nimbus Deployment Checklist',
            'Checks to complete before a deployment is approved.',
            '## Pre-deployment checks',
            'Confirm that the requested capacity stays within 120 units before approving a deployment.',
        ]);

        // PAGE E — the product entity, also stating the concrete value.
        $ownerE = $this->createPage($customer, 'Nimbus Platform', EnterpriseWikiPage::PAGE_TYPE_ENTITY);
        $this->createVersion($ownerE, [
            '# Nimbus Platform',
            'Nimbus Platform is the shared delivery platform used across the organisation. '
                .'Its supported capacity is 120 units.',
        ]);

        // PAGE F — historical record. Contains the old value but is NOT stale.
        $historyF = $this->createPage($customer, 'Capacity Change History', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $this->createVersion($historyF, [
            '# Capacity Change History',
            'The capacity limit was previously 120 units and is now 150 units.',
        ]);

        // PAGE G — unrelated, must never be a candidate.
        $unrelated = $this->createPage($customer, 'Backup Retention Policy', EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
        $this->createVersion($unrelated, [
            '# Backup Retention Policy',
            'Backups are retained according to the agreed schedule.',
        ]);

        $this->link($customer, $conceptB, $ownerA);
        $this->link($customer, $ownerA, $ownerE);

        $document = $this->createDocument($customer, self::CHANGE_TEXT);

        return [$customer, $document, [
            'owner_a' => $ownerA,
            'concept_b' => $conceptB,
            'owner_c' => $ownerC,
            'owner_d' => $ownerD,
            'owner_e' => $ownerE,
            'history_f' => $historyF,
            'unrelated_g' => $unrelated,
        ]];
    }

    /** Pages whose CURRENT text asserts the old value as present fact. */
    private function realOwnerKeys(): array
    {
        return ['owner_a', 'owner_c', 'owner_d', 'owner_e'];
    }

    private function headingFor(string $key): ?string
    {
        return match ($key) {
            'owner_a' => 'Capacity limits',
            'owner_c' => 'Operating constraints',
            'owner_d' => 'Pre-deployment checks',
            default => null,
        };
    }

    private function ownedSentenceFor(string $key): string
    {
        return match ($key) {
            'owner_a' => self::OWNED_SENTENCE_A,
            'owner_c' => self::OWNED_SENTENCE_C,
            'owner_d' => 'stays within 120 units',
            default => 'supported capacity is 120 units',
        };
    }

    /** @return list<array{id: int, key: string, title: string}> */
    private function pagesAssertingOldValue(Customer $customer): array
    {
        $rows = [];
        $byId = [];

        foreach ($this->existingWikiKeysById($customer) as $id => $key) {
            $byId[$id] = $key;
        }

        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customer->id)
            ->get();

        foreach ($pages as $page) {
            $version = $page->versions()->where('is_current', true)->first();

            if ($version === null) {
                continue;
            }

            if (str_contains((string) $version->content_markdown, self::OLD_VALUE)) {
                $rows[] = [
                    'id' => (int) $page->id,
                    'key' => $byId[$page->id] ?? 'unknown',
                    'title' => (string) $page->title,
                ];
            }
        }

        return $rows;
    }

    /** @return array<int, string> */
    private function existingWikiKeysById(Customer $customer): array
    {
        $map = [
            'Nimbus Capacity Procedure' => 'owner_a',
            'Capacity Management' => 'concept_b',
            'Nimbus Operations Guide' => 'owner_c',
            'Nimbus Deployment Checklist' => 'owner_d',
            'Nimbus Platform' => 'owner_e',
            'Capacity Change History' => 'history_f',
            'Backup Retention Policy' => 'unrelated_g',
        ];

        $byId = [];

        foreach (EnterpriseWikiPage::query()->where('customer_id', $customer->id)->get() as $page) {
            $byId[(int) $page->id] = $map[$page->title] ?? 'created_by_run';
        }

        return $byId;
    }

    /**
     * Prints the cross-page state after patching, so the surviving contradiction is visible in the
     * test output rather than only inside an assertion message.
     *
     * @param  list<int>  $targetedIds
     * @param  list<array{id: int, key: string, title: string}>  $stillStale
     */
    private function reportPostPatchState(Customer $customer, array $targetedIds, array $stillStale): void
    {
        $lines = ['', '--- Post-patch cross-page state ---'];

        foreach (EnterpriseWikiPage::query()->where('customer_id', $customer->id)->orderBy('id')->get() as $page) {
            $version = $page->versions()->where('is_current', true)->first();

            if ($version === null) {
                continue;
            }

            $markdown = (string) $version->content_markdown;
            $hasOld = str_contains($markdown, self::OLD_VALUE);
            $hasNew = str_contains($markdown, self::NEW_VALUE);

            $lines[] = sprintf(
                '  id=%-4d %-8s old=%-3s new=%-3s %-34s %s',
                $page->id,
                $page->page_type,
                $hasOld ? 'YES' : 'no',
                $hasNew ? 'YES' : 'no',
                $page->title,
                in_array((int) $page->id, $targetedIds, true) ? '<- patched' : '',
            );
        }

        $lines[] = sprintf('  pages still asserting "%s": %d', self::OLD_VALUE, count($stillStale));

        fwrite(STDERR, implode("\n", $lines)."\n");
    }

    /** Prints the measured ranking so the coverage gap is inspectable, not merely asserted. */
    private function reportRanking(array $candidates, array $pages): void
    {
        $keyById = [];

        foreach ($pages as $key => $page) {
            $keyById[$page->id] = $key;
        }

        $lines = ['', '--- REAL candidate ranking (MAX_CANDIDATES='
            .EnterpriseWikiPatchCandidateService::MAX_CANDIDATES.') ---'];

        foreach ($candidates as $rank => $candidate) {
            $tier = match (true) {
                $candidate['mention_count'] > 0 && $candidate['substance_match_count'] > 0 => 3,
                $candidate['substance_match_count'] > 0 => 2,
                $candidate['mention_count'] > 0 => 1,
                default => 0,
            };

            $lines[] = sprintf(
                '#%d  id=%-4d tier=%d substance=%-3d mentions=%-3d degree=%-3d %-32s [%s]',
                $rank + 1,
                $candidate['page_id'],
                $tier,
                $candidate['substance_match_count'],
                $candidate['mention_count'],
                $candidate['neighbour_degree'],
                $candidate['title'],
                $keyById[$candidate['page_id']] ?? '?',
            );
        }

        $selected = array_column($candidates, 'page_id');

        foreach ($this->realOwnerKeys() as $key) {
            if (! in_array($pages[$key]->id, $selected, true)) {
                $lines[] = sprintf('OUTSIDE CAP  id=%-4d %-32s [%s]', $pages[$key]->id, $pages[$key]->title, $key);
            }
        }

        fwrite(STDERR, implode("\n", $lines)."\n");
    }

    // =========================================================================
    // Decision payload helpers
    // =========================================================================

    /** @param list<array<string, mixed>> $targets @return array<string, mixed> */
    private function decision(array $targets): array
    {
        return [
            'source_article' => $this->pageEntry([
                'title' => 'Capacity Decision Record CDR-0007',
                'proposed_slug' => 'capacity-decision-record-cdr-0007',
                'owned_topics' => ['Decision identity and effect', 'Revised capacity limit'],
            ], source: true),
            'source_summary' => $this->pageEntry([
                'title' => 'Summary: Capacity Decision Record CDR-0007',
                'proposed_slug' => 'summary-capacity-decision-record-cdr-0007',
                'owned_topics' => [],
            ], source: true),
            'concept_candidates' => [],
            'concept_pages' => [],
            'entity_pages' => [],
            'patch_targets' => $targets,
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function replaceTarget(EnterpriseWikiPage $page, ?string $heading, string $superseded): array
    {
        return [
            'target_page_id' => $page->id,
            'target_page_title' => $page->title,
            'target_page_type' => $page->page_type,
            'target_topic' => 'Supported capacity limit',
            'target_heading' => $heading,
            'relationship' => 'substance_changed',
            'operation' => 'replace',
            'superseded_substance' => $superseded,
            'replacement_substance' => str_replace(self::OLD_VALUE, self::NEW_VALUE, $superseded),
            'source_element_keys' => ['paragraph-2'],
            'preserve_topics' => [],
            'reason' => 'The source explicitly supersedes the previous supported capacity.',
        ];
    }

    /** @return array<string, mixed> */
    private function preserveTarget(EnterpriseWikiPage $page): array
    {
        return [
            'target_page_id' => $page->id,
            'target_page_title' => $page->title,
            'target_page_type' => $page->page_type,
            'target_topic' => 'Capacity management concept',
            'target_heading' => null,
            'relationship' => 'reference_only',
            'operation' => 'preserve',
            'superseded_substance' => null,
            'replacement_substance' => null,
            'source_element_keys' => [],
            'preserve_topics' => ['Capacity management concept'],
            'reason' => 'The page explains the concept and defers the concrete limit to its owner.',
        ];
    }

    /** @return array<string, mixed> */
    private function pageEntry(array $overrides = [], bool $source = false): array
    {
        $entry = array_merge([
            'action' => 'create',
            'page_id' => null,
            'title' => 'New Topic',
            'proposed_slug' => 'new-topic',
            'reason' => 'x',
            'owned_topics' => ['The topic itself'],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ], $overrides);

        if ($source) {
            unset($entry['page_id']);
        }

        return $entry;
    }

    /** @return array<string, mixed> */
    private function candidateEntry(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Topic',
            'concept_type' => 'praksis',
            'independent_reason' => 'A separately defined practice.',
            'mentioned_context' => 'section 4',
            'existing_page_title' => null,
            'decision' => 'create',
            'justification' => 'The source describes the practice.',
            'owning_page_title' => null,
            'necessary_for_article' => true,
            'has_separate_source_evidence' => true,
            'has_reuse_value' => true,
            'relationship' => 'independent_new_topic',
            'existing_owner_page_id' => null,
        ], $overrides);
    }

    // =========================================================================
    // Infrastructure helpers
    // =========================================================================

    private function resolver(): EnterpriseWikiPatchTargetResolver
    {
        return app(EnterpriseWikiPatchTargetResolver::class);
    }

    private function ownershipValidator(): EnterpriseWikiCanonicalOwnershipValidator
    {
        return app(EnterpriseWikiCanonicalOwnershipValidator::class);
    }

    /** @param array<string, EnterpriseWikiPage> $pages @return list<array<string, mixed>> */
    private function indexContextFor(array $pages): array
    {
        return array_values(array_map(fn (EnterpriseWikiPage $page): array => [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'page_type' => $page->page_type,
        ], $pages));
    }

    private function applyService(): EnterpriseWikiMaintainerDecisionApplyService
    {
        return app(EnterpriseWikiMaintainerDecisionApplyService::class);
    }

    private function currentMarkdown(EnterpriseWikiPage $page): string
    {
        return (string) $page->versions()->where('is_current', true)->firstOrFail()->content_markdown;
    }

    /** QA requires the run's own article/summary to exist with content. */
    private function giveCreatedPagesContent(EnterpriseWikiIngestRun $run): void
    {
        $pivots = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        foreach ($pivots as $pivot) {
            $page = $pivot->page;

            if ($page === null || $page->versions()->where('is_current', true)->exists()) {
                continue;
            }

            EnterpriseWikiPageVersion::query()->create([
                'enterprise_wiki_page_id' => $page->id,
                'version_number' => 1,
                'is_current' => true,
                'content_markdown' => "# {$page->title}\n\n## Decision identity and effect\n\n"
                    ."The decision record identifies the change and states when it takes effect.\n\n"
                    ."## Revised capacity limit\n\nThe supported capacity is now 150 units.",
                'generated_by_model' => 'gpt-5',
            ]);
        }

        // QA escalates on unfinished continuation steps BEFORE it ever looks at critical defects, so
        // a fixture that wants to observe the defect verdict has to present a run whose steps are
        // actually complete.
        EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->update([
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
                'claims_extracted_at' => now(),
                'claims_claimed_at' => null,
            ]);
    }

    private function link(Customer $customer, EnterpriseWikiPage $from, EnterpriseWikiPage $to): void
    {
        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $from->id,
            'to_page_id' => $to->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
            'source' => 'deterministic',
            'confidence' => 'certain',
        ]);
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

    private function createDocument(Customer $customer, string $text): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'capacity-decision.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => $text,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createPage(Customer $customer, string $title, string $pageType): EnterpriseWikiPage
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
     * The patch engine deliberately refuses to derive blocks from markdown, so existing pages must
     * carry a real content_blocks_json — provenance included, so its preservation is observable.
     *
     * @param  list<string>  $parts
     */
    private function createVersion(EnterpriseWikiPage $page, array $parts): EnterpriseWikiPageVersion
    {
        $origin = $this->originDocument($page->customer_id);
        $blocks = [];

        foreach ($parts as $index => $markdown) {
            $isHeading = str_starts_with($markdown, '#');

            $blocks[] = [
                'block_key' => 'block-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'position' => $index,
                'markdown' => $markdown,
                'content_origin' => $isHeading ? 'structural' : 'source_based',
                'source_type' => $isHeading ? null : 'enterprise_wiki_document',
                'source_id' => $isHeading ? null : $origin->id,
                'source_label' => $isHeading ? null : $origin->original_filename,
                'source_hash' => $isHeading ? null : $origin->file_hash_sha256,
                'document_version_hash' => $isHeading ? null : $origin->file_hash_sha256,
                'source_element_key' => $isHeading ? null : 'paragraph-'.$index,
                'source_element_type' => $isHeading ? null : 'paragraph',
                'source_row_key' => null,
                'source_excerpt' => $isHeading ? null : $markdown,
                'page_reference' => $isHeading ? null : 'Section',
                'source_elements' => $isHeading ? [] : [[
                    'source_type' => 'enterprise_wiki_document',
                    'source_id' => $origin->id,
                    'source_label' => $origin->original_filename,
                    'source_hash' => $origin->file_hash_sha256,
                    'document_version_hash' => $origin->file_hash_sha256,
                    'source_element_key' => 'paragraph-'.$index,
                    'source_element_type' => 'paragraph',
                    'source_row_key' => null,
                    'source_excerpt' => $markdown,
                    'page_reference' => 'Section',
                ]],
                'best_practice_reason' => null,
                'link_intents' => [],
            ];
        }

        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => implode("\n\n", $parts),
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'gpt-5',
        ]);
    }

    /** One shared "where the existing Wiki came from" document, distinct from the change document. */
    private function originDocument(int $customerId): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->firstOrCreate(
            ['customer_id' => $customerId, 'original_filename' => 'existing-baseline.docx'],
            [
                'file_path' => 'customers/'.$customerId.'/wiki/'.Str::random(8).'.docx',
                'file_hash_sha256' => hash('sha256', 'baseline-'.$customerId),
                'extracted_text' => 'Baseline documentation for the existing Wiki state.',
                'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            ],
        );
    }

    private function runWithDecision(Customer $customer, EnterpriseWikiDocument $document, array $decision): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'trigger_type' => 'manual',
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', 'enterprise_wiki_document:'.$document->id),
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_json' => $decision,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at' => now(),
        ]);
    }
}
