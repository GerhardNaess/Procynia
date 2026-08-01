<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiCanonicalFact;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Closes the "17 vs 1" inconsistency reported for run 41: the Kjøringer "Funn" badge
 * (WikiController::loadRunsTab()) used to compute its own hand-rolled, incomplete SQL
 * approximation of "is this claim a user-facing addition" (only excluding
 * deterministic-reason mismatches, not unverified links or self-reported-check mismatches),
 * while the detail panel (EnterpriseWikiRunFindingsService, via GET .../findings) already used
 * the real, single-source-of-truth predicate (EnterpriseWikiClaimFindingExplainer::
 * isUserFacingAddition()). The badge is now sourced from the exact same canonical
 * EnterpriseWikiRunFindingsService::buildForRun() call the panel uses — see
 * WikiController::loadRunsTab().
 *
 * These tests prove the canonical collection is used consistently for: the "Funn" column
 * counter, the panel's total/summary buckets, and the informational claim QA signal reporting
 * (EnterpriseWikiPostIngestQaService::findOpenClaimQaSignals(), gated on isUserFacingAddition() —
 * verified here as a regression guard, not a new behavior). Since v0.10 (docs/enterprise-llm-wiki-plan.md,
 * "Arkitekturnotat — v0.10") no claim QA signal ever gates the run; only a genuinely blocking lint
 * finding can. Internal/raw claim signals stay in the database and in
 * EnterpriseWikiPostIngestQaService's own diagnostics; they are never counted or shown here.
 */
class EnterpriseWikiRunFindingsConsistencyTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Acceptance A: 16 hidden internal findings + 1 user-facing best-practice suggestion
    // =========================================================================

    public function test_hidden_internal_signals_and_one_best_practice_suggestion_stay_consistent_and_do_not_escalate(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);

        // 16 raw/internal signals, spread across every hidden category the predicate excludes —
        // none of these may ever become a user-facing row or count toward "Funn".
        for ($i = 0; $i < 4; $i++) {
            $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR);
        }
        for ($i = 0; $i < 4; $i++) {
            // Never reached a verdict at all — technical uncertainty, not a content error.
            $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT);
        }
        foreach (['negation_mismatch', 'modality_mismatch', 'actor_mismatch', 'scope_mismatch'] as $reason) {
            $claim = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, contentBlockKey: 'block-'.$reason, reviewMetadata: [
                'classification_basis' => 'semantic_verification',
                'verdict' => 'not_supported',
                'deterministic_reason' => $reason,
            ]);
            $this->createSourceReference($claim, $document);
        }
        for ($i = 0; $i < 4; $i++) {
            $claim = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, contentBlockKey: 'block-self-'.$i, reviewMetadata: [
                'classification_basis' => 'semantic_verification',
                'verdict' => 'not_supported',
                'checks' => ['action' => 'mismatch'],
            ]);
            $this->createSourceReference($claim, $document);
        }

        $this->assertSame(16, EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $version->id)->count());

        // The one genuine, user-facing case: a best-practice suggestion, pending review.
        $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE);

        $this->markStepsComplete($run);
        $qaResult = app(EnterpriseWikiPostIngestQaService::class)->runForRun($run);
        app(EnterpriseWikiDocumentFlowService::class)->finalizeFromExistingQaResult($run->fresh());

        $this->assertSame([], $qaResult['claim_qa_signals'], 'The 16 hidden internal signals must never surface, even informationally.');
        $run->refresh();
        $this->assertNotSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->status, 'A run must not escalate because of hidden internal signals plus one non-blocking suggestion.');

        [$badgeCount, $panel] = $this->fetchBadgeAndPanel($user, $run);

        $this->assertSame(1, $badgeCount, 'Funn column must show only the one user-facing best-practice suggestion.');
        $this->assertSame(1, $panel['summary']['total']);
        $this->assertSame(0, $panel['summary']['open_blocking']);
        $this->assertSame(1, $panel['summary']['best_practice_pending']);
        $this->assertCount(1, $panel['findings']);
        $this->assertSame('best_practice_suggestion', $panel['findings'][0]['category']);
        $this->assertFalse($panel['findings'][0]['blocks_run']);
        $this->assertSame($badgeCount, $panel['summary']['total'], 'The badge and the panel total must never disagree.');
    }

    // =========================================================================
    // Acceptance B: only hidden internal findings, nothing user-facing at all
    // =========================================================================

    public function test_only_hidden_internal_signals_produce_zero_user_facing_findings_and_do_not_escalate(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);

        $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR);
        $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT);
        $claim = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, contentBlockKey: 'block-neg', reviewMetadata: [
            'classification_basis' => 'semantic_verification',
            'verdict' => 'not_supported',
            'deterministic_reason' => 'negation_mismatch',
        ]);
        $this->createSourceReference($claim, $document);

        $this->markStepsComplete($run);
        $qaResult = app(EnterpriseWikiPostIngestQaService::class)->runForRun($run);
        app(EnterpriseWikiDocumentFlowService::class)->finalizeFromExistingQaResult($run->fresh());

        $this->assertSame([], $qaResult['claim_qa_signals']);
        $run->refresh();
        $this->assertNotSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->status);

        [$badgeCount, $panel] = $this->fetchBadgeAndPanel($user, $run);

        $this->assertSame(0, $badgeCount);
        $this->assertSame(0, $panel['summary']['total']);
        $this->assertSame([], $panel['findings']);
    }

    // =========================================================================
    // Acceptance C: one genuine, user-facing claim QA signal — reported, but never blocking (v0.10)
    // =========================================================================

    public function test_one_genuine_claim_qa_signal_is_counted_but_never_escalates(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);

        // Reached a real semantic verdict (block key + source reference + a verdict), no
        // deterministic or self-reported mismatch dimension flagged — a genuine, confirmed
        // "not confirmed by source" case per EnterpriseWikiClaimFindingExplainer.
        $claim = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, contentBlockKey: 'block-real', reviewMetadata: [
            'classification_basis' => 'semantic_verification',
            'verdict' => 'not_supported',
            'reason' => 'The source describes a different process than the one claimed.',
        ]);
        $this->createSourceReference($claim, $document);

        $this->markStepsComplete($run);
        $qaResult = app(EnterpriseWikiPostIngestQaService::class)->runForRun($run);
        app(EnterpriseWikiDocumentFlowService::class)->finalizeFromExistingQaResult($run->fresh());

        $this->assertContains('open_unsupported_generated_content_claims', $qaResult['claim_qa_signals']);
        $run->refresh();
        // This fixture's pivot rows never set generated_page_version_id, so
        // EnterpriseWikiDocumentOwnerApprovalService::evaluateRunCompletionGate() finds no run
        // pages to gate on and the run completes outright — the point proven here is that it no
        // longer gets stuck at "escalated" the way the pre-v0.10 repair_required path did.
        $this->assertNotSame(EnterpriseWikiIngestRun::STATUS_ESCALATED, $run->status);
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);

        [$badgeCount, $panel] = $this->fetchBadgeAndPanel($user, $run);

        $this->assertSame(1, $badgeCount);
        $this->assertSame(1, $panel['summary']['total']);
        $this->assertSame(0, $panel['summary']['open_blocking']);
        $this->assertSame(1, $panel['summary']['open_qa_review']);
        $this->assertCount(1, $panel['findings']);
        $this->assertFalse($panel['findings'][0]['blocks_run']);
        $this->assertSame('open_for_qa_review', $panel['findings'][0]['status']);
        $this->assertSame($badgeCount, $panel['summary']['total']);
    }

    // =========================================================================
    // Acceptance D: resolving a user-facing finding moves it from Open to Resolved
    // =========================================================================

    public function test_resolving_a_user_facing_lint_finding_moves_it_from_open_to_resolved(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);

        $finding = EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'code' => EnterpriseWikiLintFinding::CODE_EMPTY_PAGE_CONTENT,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message' => 'Testfunn for konsistenstest.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);

        [$badgeBefore, $panelBefore] = $this->fetchBadgeAndPanel($user, $run);

        $this->assertSame(1, $badgeBefore);
        $this->assertSame(1, $panelBefore['summary']['total']);
        $this->assertSame(1, $panelBefore['summary']['open_non_blocking']);
        $this->assertSame(0, $panelBefore['summary']['resolved']);
        $this->assertSame('open', $panelBefore['findings'][0]['status']);

        $finding->update(['status' => EnterpriseWikiLintFinding::STATUS_RESOLVED, 'resolved_at' => now()]);

        [$badgeAfter, $panelAfter] = $this->fetchBadgeAndPanel($user, $run);

        // Total never changes when a finding is resolved — it remains counted, just recategorized
        // (EnterpriseWikiRunFindingsService is "the one place that must show resolved/historical
        // findings too", see its own class docblock).
        $this->assertSame(1, $badgeAfter);
        $this->assertSame(1, $panelAfter['summary']['total']);
        $this->assertSame(0, $panelAfter['summary']['open_non_blocking']);
        $this->assertSame(1, $panelAfter['summary']['resolved']);
        $this->assertSame('resolved', $panelAfter['findings'][0]['status']);
        $this->assertSame($badgeBefore, $badgeAfter, 'Resolving a finding must not change the total Funn count.');
    }

    // =========================================================================
    // Acceptance E: no UI surface ever disagrees, across a genuinely mixed scenario
    // =========================================================================

    public function test_no_surface_shows_a_different_count_than_the_rows_actually_available(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);

        // Hidden (never counted anywhere):
        $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR);
        $hiddenMismatch = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, contentBlockKey: 'block-hidden', reviewMetadata: [
            'classification_basis' => 'semantic_verification',
            'verdict' => 'not_supported',
            'deterministic_reason' => 'subject_mismatch',
        ]);
        $this->createSourceReference($hiddenMismatch, $document);

        // User-facing, non-blocking:
        $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE);
        EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $article->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'code' => EnterpriseWikiLintFinding::CODE_EMPTY_PAGE_CONTENT,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_INFO,
            'message' => 'Informativt testfunn.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);

        // User-facing claim QA signal — reported, but never blocking (v0.10):
        $qaSignalClaim = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, contentBlockKey: 'block-real', reviewMetadata: [
            'classification_basis' => 'semantic_verification',
            'verdict' => 'not_supported',
            'reason' => 'Confirmed deviation from source.',
        ]);
        $this->createSourceReference($qaSignalClaim, $document);

        [$badgeCount, $panel] = $this->fetchBadgeAndPanel($user, $run);

        // Exactly 3 user-facing rows exist: best-practice suggestion, informative lint finding,
        // open claim QA signal — the 2 hidden signals never appear anywhere.
        $this->assertSame(3, $badgeCount);
        $this->assertSame(3, $panel['summary']['total']);
        $this->assertCount(3, $panel['findings']);
        $this->assertSame($badgeCount, $panel['summary']['total']);
        $this->assertSame($panel['summary']['total'], count($panel['findings']), 'summary.total must always equal the number of rows actually returned.');

        // No claim-based row ever blocks (v0.10) — only a genuinely blocking lint finding could,
        // and none exists in this fixture.
        $blockingRows = array_values(array_filter($panel['findings'], fn (array $f): bool => $f['blocks_run']));
        $this->assertCount(0, $blockingRows);
        $this->assertSame(0, $panel['summary']['open_blocking']);
        $this->assertSame(1, $panel['summary']['open_qa_review']);

        // Of the 3 user-facing rows, only the best-practice suggestion (pending_review) and the
        // open claim QA signal (open_for_qa_review) are "open" — the informative lint finding is
        // its own separate, non-open category (both here and in the frontend's matching filter).
        $openRows = array_values(array_filter(
            $panel['findings'],
            fn (array $f): bool => in_array($f['status'], ['requires_action', 'open', 'pending_review', 'open_for_qa_review', 'flagged_for_review'], true),
        ));
        $this->assertCount(2, $openRows);
        $informativeRows = array_values(array_filter($panel['findings'], fn (array $f): bool => $f['status'] === 'informative'));
        $this->assertCount(1, $informativeRows);
        $this->assertSame(3, count($openRows) + count($informativeRows), 'Every user-facing row must land in exactly one filter bucket.');
    }

    // =========================================================================
    // Acceptance F: every finding has a stable, existing-domain id (never a row index)
    // =========================================================================

    public function test_claim_defect_finding_id_is_stable_across_separate_requests_and_matches_the_claim(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);

        $claim = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, contentBlockKey: 'block-real', reviewMetadata: [
            'classification_basis' => 'semantic_verification',
            'verdict' => 'not_supported',
            'reason' => 'Confirmed deviation from source.',
        ]);
        $this->createSourceReference($claim, $document);

        [, $panelFirst] = $this->fetchBadgeAndPanel($user, $run);
        [, $panelSecond] = $this->fetchBadgeAndPanel($user, $run);

        $this->assertSame("claim-defect-{$claim->id}", $panelFirst['findings'][0]['id']);
        $this->assertSame($panelFirst['findings'][0]['id'], $panelSecond['findings'][0]['id'], 'The id must not change across separate requests (reloads).');
    }

    public function test_best_practice_finding_id_is_stable_and_matches_the_primary_claim(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);

        $claim = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, contentBlockKey: 'block-bp');

        [, $panelFirst] = $this->fetchBadgeAndPanel($user, $run);
        [, $panelSecond] = $this->fetchBadgeAndPanel($user, $run);

        $this->assertSame("best-practice-{$claim->id}", $panelFirst['findings'][0]['id']);
        $this->assertSame($panelFirst['findings'][0]['id'], $panelSecond['findings'][0]['id']);
    }

    public function test_two_textually_similar_best_practice_findings_have_different_ids(): void
    {
        // Del 2 of the finding-id task: findings that read almost identically must still be
        // individually addressable — two distinct blocks, near-identical wording.
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);

        $claimA = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, contentBlockKey: 'block-a');
        $claimA->update(['claim_text' => 'Det anbefales å gjennomføre kvartalsvise tilgangsgjennomganger.']);
        $claimB = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, contentBlockKey: 'block-b');
        $claimB->update(['claim_text' => 'Det anbefales å gjennomføre kvartalsvise tilgangsgjennomganger og logge resultatet.']);

        [, $panel] = $this->fetchBadgeAndPanel($user, $run);

        $this->assertCount(2, $panel['findings']);
        $ids = collect($panel['findings'])->pluck('id')->all();
        $this->assertSame(["best-practice-{$claimA->id}", "best-practice-{$claimB->id}"], $ids);
        $this->assertNotSame($ids[0], $ids[1], 'Textually similar findings on different blocks must keep distinct ids.');
    }

    public function test_canonical_fact_grouped_claim_defect_keeps_one_stable_id_across_occurrences(): void
    {
        // Several claims (different pages/blocks) sharing the same canonical fact are grouped
        // into ONE finding (EnterpriseWikiRunFindingsService::claimDefectGroupKey()) — the id must
        // stay the same, stable "primary" occurrence's id across requests, not fluctuate.
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $document = $this->createDocument($customer);
        $run = $this->createAppliedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');
        $version = $this->currentVersion($article);

        $fact = EnterpriseWikiCanonicalFact::query()->create([
            'customer_id' => $customer->id,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'source_element_keys' => [],
            'source_element_keys_hash' => hash('sha256', Str::random(32)),
            'normalized_fingerprint' => hash('sha256', 'shared-fact'),
            'canonical_text' => 'Kunden har fem godkjente eskaleringsnivåer.',
            'verification_status' => 'verified_unsupported',
            'verification_reason' => 'Ikke støttet av kilden.',
            'verified_at' => now(),
        ]);

        $claimOne = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, contentBlockKey: 'block-one', reviewMetadata: [
            'classification_basis' => 'semantic_verification',
            'verdict' => 'not_supported',
        ]);
        $claimOne->update(['canonical_fact_id' => $fact->id]);
        $claimTwo = $this->createClaim($article, $version, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, contentBlockKey: 'block-two', reviewMetadata: [
            'classification_basis' => 'semantic_verification',
            'verdict' => 'not_supported',
        ]);
        $claimTwo->update(['canonical_fact_id' => $fact->id]);

        [, $panelFirst] = $this->fetchBadgeAndPanel($user, $run);
        [, $panelSecond] = $this->fetchBadgeAndPanel($user, $run);

        $this->assertCount(1, $panelFirst['findings'], 'Both occurrences of the same fact must be one finding.');
        $this->assertSame(2, $panelFirst['findings'][0]['claim_count']);
        $expectedPrimaryId = min($claimOne->id, $claimTwo->id);
        $this->assertSame("claim-defect-{$expectedPrimaryId}", $panelFirst['findings'][0]['id']);
        $this->assertSame($panelFirst['findings'][0]['id'], $panelSecond['findings'][0]['id'], 'The grouped finding id must stay stable across requests.');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @return array{0: int, 1: array{summary: array<string, mixed>, findings: list<array<string, mixed>>}}
     */
    private function fetchBadgeAndPanel(User $user, EnterpriseWikiIngestRun $run): array
    {
        $badgeCount = null;
        $this->actingAs($user)->get('/app/wiki?tab=runs')->assertViewHas('page', function (array $inertia) use ($run, &$badgeCount): bool {
            $badgeCount = collect(data_get($inertia, 'props.runs', []))->firstWhere('id', $run->id)['lint_count'] ?? null;

            return true;
        });

        $panelResponse = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");
        $panelResponse->assertOk();

        return [$badgeCount, $panelResponse->json()];
    }

    private function createCustomer(string $name = 'Findings Consistency Test AS'): Customer
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

    private function createUser(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'System Owner',
            'email' => Str::lower(Str::random(8)).'@findings-consistency-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
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
            'extracted_text' => 'Source document text for findings consistency tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createAppliedRun(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_QA,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
        ]);
    }

    private function createVersionedPage(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        string $pageType,
        string $title,
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
            'content_markdown' => "# {$title}\n\nContent.",
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }

    private function currentVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
    }

    private function createClaim(
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        string $contentOrigin,
        ?string $contentBlockKey = null,
        ?array $reviewMetadata = null,
    ): EnterpriseWikiClaim {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Test claim.',
            'content_origin' => $contentOrigin,
            'content_block_key' => $contentBlockKey,
            'review_metadata' => $reviewMetadata,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);
    }

    private function createSourceReference(EnterpriseWikiClaim $claim, EnterpriseWikiDocument $document): EnterpriseWikiSourceReference
    {
        return EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'excerpt' => 'Utdrag for '.$document->original_filename,
        ]);
    }

    private function markStepsComplete(EnterpriseWikiIngestRun $run): void
    {
        EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->update([
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
                'claims_extracted_at' => now(),
                'claims_claimed_at' => null,
                'claims_claim_token' => null,
            ]);

        $pageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->pluck('enterprise_wiki_page_id');

        EnterpriseWikiClaim::query()
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->whereNull('verified_at')
            ->update(['verified_at' => now(), 'verification_claimed_at' => null, 'verification_claim_token' => null]);
    }
}
