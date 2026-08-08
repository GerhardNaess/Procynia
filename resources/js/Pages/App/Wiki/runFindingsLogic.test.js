import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import {
    RUN_TIMELINE_STEPS,
    ACTIVE_WIKI_RUN_STATUSES,
    matchesFindingsLocalFilter,
    getRunTimelineState,
    getEscalationCopy,
    isRunStalled,
    isActiveWikiRun,
    formatFindingUserId,
    hasActiveWikiRunForTab,
    activeWikiRunLikeObjectsForTab,
} from './runFindingsLogic.js';

describe('formatFindingUserId', () => {
    test('strips the claim-defect prefix, leaving only the stable numeric id', () => {
        assert.equal(formatFindingUserId('claim-defect-5378'), '5378');
    });

    test('strips the best-practice prefix, leaving only the stable numeric id', () => {
        assert.equal(formatFindingUserId('best-practice-5390'), '5390');
    });

    test('strips the lint prefix, leaving only the stable numeric id', () => {
        assert.equal(formatFindingUserId('lint-82'), '82');
    });

    test('same input always produces the same output (list and detail view use identical formatting)', () => {
        assert.equal(formatFindingUserId('claim-defect-5378'), formatFindingUserId('claim-defect-5378'));
    });

    test('falls back to the full original id for an unrecognized prefix, never an empty value', () => {
        assert.equal(formatFindingUserId('unknown-format-123'), 'unknown-format-123');
    });

    test('falls back to the full original id when a known prefix is followed by a non-numeric remainder', () => {
        assert.equal(formatFindingUserId('claim-defect-abc'), 'claim-defect-abc');
    });

    test('handles null/undefined without throwing, never returning an empty-looking value silently', () => {
        assert.equal(formatFindingUserId(null), '');
        assert.equal(formatFindingUserId(undefined), '');
    });
});

describe('matchesFindingsLocalFilter', () => {
    test('"open" matches every open status a finding can carry, from every source', () => {
        // lint finding statuses
        assert.equal(matchesFindingsLocalFilter({ status: 'requires_action' }, 'open'), true);
        assert.equal(matchesFindingsLocalFilter({ status: 'open' }, 'open'), true);
        // best-practice suggestion status
        assert.equal(matchesFindingsLocalFilter({ status: 'pending_review' }, 'open'), true);
        // claim QA signal statuses (v0.10, docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat —
        // v0.10") — voluntary, never blocking, but must still surface under "Åpne" for QA.
        assert.equal(matchesFindingsLocalFilter({ status: 'open_for_qa_review' }, 'open'), true);
        assert.equal(matchesFindingsLocalFilter({ status: 'flagged_for_review' }, 'open'), true);
    });

    test('"open" excludes resolved/informative/superseded statuses', () => {
        for (const status of ['resolved', 'approved', 'approved_edited', 'rejected', 'informative', 'superseded']) {
            assert.equal(matchesFindingsLocalFilter({ status }, 'open'), false, `expected ${status} to be excluded from "open"`);
        }
    });

    test('"blocking" matches purely on blocks_run, regardless of status — a claim QA signal never sets blocks_run true (v0.10)', () => {
        assert.equal(matchesFindingsLocalFilter({ status: 'requires_action', blocks_run: true }, 'blocking'), true);
        assert.equal(matchesFindingsLocalFilter({ status: 'open_for_qa_review', blocks_run: false }, 'blocking'), false);
        assert.equal(matchesFindingsLocalFilter({ status: 'pending_review', blocks_run: false }, 'blocking'), false);
    });

    test('"resolved" matches every decided/closed status', () => {
        for (const status of ['resolved', 'approved', 'approved_edited', 'rejected']) {
            assert.equal(matchesFindingsLocalFilter({ status }, 'resolved'), true);
        }
        assert.equal(matchesFindingsLocalFilter({ status: 'open' }, 'resolved'), false);
    });

    test('"informative" matches informative and superseded', () => {
        assert.equal(matchesFindingsLocalFilter({ status: 'informative' }, 'informative'), true);
        assert.equal(matchesFindingsLocalFilter({ status: 'superseded' }, 'informative'), true);
        assert.equal(matchesFindingsLocalFilter({ status: 'open' }, 'informative'), false);
    });

    test('unknown/"all" filter key matches everything', () => {
        assert.equal(matchesFindingsLocalFilter({ status: 'anything' }, 'all'), true);
    });
});

describe('getRunTimelineState — escalated run points at QA, never at Dokumenteier', () => {
    const qaIndex = RUN_TIMELINE_STEPS.findIndex((step) => step.key === 'qa');
    const dokumenteierIndex = RUN_TIMELINE_STEPS.findIndex((step) => step.key === 'awaiting_document_owner_approval');

    test('an escalated run (e.g. repair_required from a claim-integrity defect) marks QA as the error step', () => {
        const run = { status: 'escalated', qa_status: 'repair_required' };

        assert.equal(getRunTimelineState(run, qaIndex), 'error');
    });

    test('an escalated run never marks Dokumenteier as the error step', () => {
        const run = { status: 'escalated', qa_status: 'repair_required' };

        assert.notEqual(getRunTimelineState(run, dokumenteierIndex), 'error');
        assert.equal(getRunTimelineState(run, dokumenteierIndex), 'empty');
    });

    test('an escalated run marks every step before QA as done', () => {
        const run = { status: 'escalated', qa_status: 'escalated' };

        for (let i = 0; i < qaIndex; i++) {
            assert.equal(getRunTimelineState(run, i), 'done', `step ${i} should be done`);
        }
    });

    // Wiki run-3 (A): completed + a real approved requirement -> Dokumenteier renders green.
    test('a completed run with an actually-approved requirement marks Dokumenteier done', () => {
        const run = {
            status: 'completed',
            document_owner_approval: { required_count: 1, approved_count: 1, rejected_count: 0, pending_count: 0 },
        };

        assert.equal(getRunTimelineState(run, dokumenteierIndex), 'done');
    });

    // Wiki run-3 (E): completed still marks every earlier step done — only the last step is
    // decided by real approval evidence instead of the blanket rule.
    test('a completed run still marks every step before Dokumenteier done, regardless of approval evidence', () => {
        const run = {
            status: 'completed',
            document_owner_approval: { required_count: 0, approved_count: 0, rejected_count: 0, pending_count: 0 },
        };

        for (let i = 0; i < dokumenteierIndex; i++) {
            assert.equal(getRunTimelineState(run, i), 'done', `step ${i} should be done`);
        }
    });

    // Wiki run-3 (D): completed but no page ever required a Document Owner decision -> neutral,
    // never green, since a 'completed' run.status alone never proves a human approved anything.
    test('a completed run with no live approval requirement marks Dokumenteier not_required, never done', () => {
        const run = {
            status: 'completed',
            document_owner_approval: { required_count: 0, approved_count: 0, rejected_count: 0, pending_count: 0 },
        };

        assert.equal(getRunTimelineState(run, dokumenteierIndex), 'not_required');
    });

    // Wiki run-3: a completed run with no document_owner_approval prop at all (e.g. an older
    // cached response) must default to the same safe not_required rendering, never to done.
    test('a completed run missing the document_owner_approval prop entirely defaults to not_required', () => {
        const run = { status: 'completed' };

        assert.equal(getRunTimelineState(run, dokumenteierIndex), 'not_required');
    });

    // Wiki run-3 (B): awaiting_document_owner_approval + a genuinely pending requirement ->
    // Dokumenteier renders as still waiting on a human.
    test('a run awaiting document owner approval with a pending requirement marks that last step as waiting, not error', () => {
        const run = {
            status: 'awaiting_document_owner_approval',
            document_owner_approval: { required_count: 1, approved_count: 0, rejected_count: 0, pending_count: 1 },
        };

        assert.equal(getRunTimelineState(run, dokumenteierIndex), 'waiting');
    });

    // Wiki run-3 (C): a rejected requirement must render as the step's error state. The run
    // completion gate never lets 'completed' happen while a rejection is outstanding
    // (EnterpriseWikiDocumentOwnerApprovalService::evaluateRunCompletionGate()), so this is only
    // ever observed while run.status is still 'awaiting_document_owner_approval'.
    test('a rejected requirement marks Dokumenteier as the error step', () => {
        const run = {
            status: 'awaiting_document_owner_approval',
            document_owner_approval: { required_count: 1, approved_count: 0, rejected_count: 1, pending_count: 0 },
        };

        assert.equal(getRunTimelineState(run, dokumenteierIndex), 'error');
    });

    test('an active run in generating_pages marks that step active and later steps empty', () => {
        const run = { status: 'generating_pages' };
        const pagesIndex = RUN_TIMELINE_STEPS.findIndex((step) => step.key === 'generating_pages');

        assert.equal(getRunTimelineState(run, pagesIndex), 'active');
        assert.equal(getRunTimelineState(run, pagesIndex + 1), 'empty');
    });
});

describe('getRunTimelineState — failed run uses failed_phase, not the generic status (Wiki run-588)', () => {
    const stepIndex = (key) => RUN_TIMELINE_STEPS.findIndex((step) => step.key === key);

    // 15. The exact run-588 shape: failed during maintainer_decision.
    test('failed in maintainer_decision shows Beslutning as error and every later step empty', () => {
        const run = { status: 'failed', failed_phase: 'maintainer_decision' };

        assert.equal(getRunTimelineState(run, stepIndex('queued')), 'done');
        assert.equal(getRunTimelineState(run, stepIndex('maintainer_decision')), 'error');
        assert.equal(getRunTimelineState(run, stepIndex('applying')), 'empty');
        assert.equal(getRunTimelineState(run, stepIndex('generating_pages')), 'empty');
        assert.equal(getRunTimelineState(run, stepIndex('verification_linking')), 'empty');
        assert.equal(getRunTimelineState(run, stepIndex('qa')), 'empty');
        assert.equal(getRunTimelineState(run, stepIndex('awaiting_document_owner_approval')), 'empty');
    });

    // 16. Failed in generating_pages: earlier steps done, Sider red, later steps empty.
    test('failed in generating_pages shows Sider as error and earlier steps done', () => {
        const run = { status: 'failed', failed_phase: 'generating_pages' };

        assert.equal(getRunTimelineState(run, stepIndex('queued')), 'done');
        assert.equal(getRunTimelineState(run, stepIndex('maintainer_decision')), 'done');
        assert.equal(getRunTimelineState(run, stepIndex('applying')), 'done');
        assert.equal(getRunTimelineState(run, stepIndex('generating_pages')), 'error');
        assert.equal(getRunTimelineState(run, stepIndex('verification_linking')), 'empty');
        assert.equal(getRunTimelineState(run, stepIndex('qa')), 'empty');
    });

    test('failed_phase generating_concept_entity_pages is aliased to the Sider step, same as the active-run alias', () => {
        const run = { status: 'failed', failed_phase: 'generating_concept_entity_pages' };

        assert.equal(getRunTimelineState(run, stepIndex('generating_pages')), 'error');
    });

    // 17. Failed in verification_linking.
    test('failed in verification_linking shows Verifisering as error', () => {
        const run = { status: 'failed', failed_phase: 'verification_linking' };

        assert.equal(getRunTimelineState(run, stepIndex('applying')), 'done');
        assert.equal(getRunTimelineState(run, stepIndex('generating_pages')), 'done');
        assert.equal(getRunTimelineState(run, stepIndex('verification_linking')), 'error');
        assert.equal(getRunTimelineState(run, stepIndex('qa')), 'empty');
    });

    // 18. Failed in qa.
    test('failed in qa shows QA as error', () => {
        const run = { status: 'failed', failed_phase: 'qa' };

        assert.equal(getRunTimelineState(run, stepIndex('verification_linking')), 'done');
        assert.equal(getRunTimelineState(run, stepIndex('qa')), 'error');
        assert.equal(getRunTimelineState(run, stepIndex('awaiting_document_owner_approval')), 'empty');
    });

    // 19. Failed in the document-owner phase.
    test('failed in awaiting_document_owner_approval shows Dokumenteier as error', () => {
        const run = { status: 'failed', failed_phase: 'awaiting_document_owner_approval' };

        assert.equal(getRunTimelineState(run, stepIndex('qa')), 'done');
        assert.equal(getRunTimelineState(run, stepIndex('awaiting_document_owner_approval')), 'error');
    });

    // 20. No failed_phase at all — must never mark all-but-last as done (the run-588 bug itself).
    test('failed with no failed_phase marks every step neutral, never done', () => {
        const run = { status: 'failed', failed_phase: null };

        for (let i = 0; i < RUN_TIMELINE_STEPS.length; i++) {
            assert.equal(getRunTimelineState(run, i), 'empty', `step ${i} should be neutral, not done or error`);
        }
    });

    // 21. An older failed run recorded before this field existed (failed_phase key absent entirely).
    test('an older failed run without a failed_phase field at all is rendered the same conservative way', () => {
        const run = { status: 'failed' };

        for (let i = 0; i < RUN_TIMELINE_STEPS.length; i++) {
            assert.equal(getRunTimelineState(run, i), 'empty');
        }
    });

    test('an unrecognized failed_phase value is treated the same as missing, never as a match', () => {
        const run = { status: 'failed', failed_phase: 'some_future_unknown_phase' };

        for (let i = 0; i < RUN_TIMELINE_STEPS.length; i++) {
            assert.equal(getRunTimelineState(run, i), 'empty');
        }
    });
});

describe('getRunTimelineState — verifying_claims/post_claim_verification map to the Verifisering step (Wiki run status visibility fix)', () => {
    const verificationIndex = RUN_TIMELINE_STEPS.findIndex((step) => step.key === 'verification_linking');

    test('verifying_claims marks the Verifisering step active, never blank', () => {
        const run = { status: 'verifying_claims' };

        assert.equal(getRunTimelineState(run, verificationIndex), 'active');
    });

    test('post_claim_verification marks the Verifisering step active, never blank', () => {
        const run = { status: 'post_claim_verification' };

        assert.equal(getRunTimelineState(run, verificationIndex), 'active');
    });

    test('verifying_claims marks every earlier step done and every later step empty — the timeline is never fully blank', () => {
        const run = { status: 'verifying_claims' };

        for (let i = 0; i < verificationIndex; i++) {
            assert.equal(getRunTimelineState(run, i), 'done', `step ${i} should be done`);
        }
        assert.equal(getRunTimelineState(run, verificationIndex + 1), 'empty');
    });

    test('a genuinely unknown/future status never crashes and renders every step neutral, not blank-as-error', () => {
        const run = { status: 'some_future_unknown_status' };

        for (let i = 0; i < RUN_TIMELINE_STEPS.length; i++) {
            assert.doesNotThrow(() => getRunTimelineState(run, i));
            assert.equal(getRunTimelineState(run, i), 'empty');
        }
    });
});

describe('ACTIVE_WIKI_RUN_STATUSES / isActiveWikiRun — polling contract (Wiki run status visibility fix)', () => {
    // Wiki run-XXX: EnterpriseWikiIngestRun::EXPECTS_AUTOMATIC_PROGRESS_STATUSES on the backend —
    // every one of these must keep the frontend polling, or a run can silently stop refreshing.
    const backendAutomaticProgressStatuses = [
        'running',
        'sections_planned',
        'maintainer_decision',
        'applying',
        'generating_pages',
        'generating_concept_entity_pages',
        'verification_linking',
        'verifying_claims',
        'post_claim_verification',
        'qa',
    ];

    test('every backend automatic-progress status is active (keeps polling)', () => {
        for (const status of backendAutomaticProgressStatuses) {
            assert.equal(isActiveWikiRun({ status }), true, `expected ${status} to be active`);
        }
    });

    test('verifying_claims and post_claim_verification are active — the exact statuses that previously stopped polling', () => {
        assert.equal(isActiveWikiRun({ status: 'verifying_claims' }), true);
        assert.equal(isActiveWikiRun({ status: 'post_claim_verification' }), true);
    });

    test('awaiting_document_owner_approval is kept active — existing product intent (an approval completed elsewhere must still be picked up)', () => {
        assert.equal(isActiveWikiRun({ status: 'awaiting_document_owner_approval' }), true);
    });

    test('every terminal status stops polling', () => {
        for (const status of ['completed', 'failed', 'escalated', 'cancelled']) {
            assert.equal(isActiveWikiRun({ status }), false, `expected ${status} to be inactive`);
        }
    });

    test('a null/undefined run is never active', () => {
        assert.equal(isActiveWikiRun(null), false);
        assert.equal(isActiveWikiRun(undefined), false);
    });

    test('an unknown/future status is never active — safe fallback, never crashes', () => {
        assert.doesNotThrow(() => isActiveWikiRun({ status: 'some_future_unknown_status' }));
        assert.equal(isActiveWikiRun({ status: 'some_future_unknown_status' }), false);
    });

    test('ACTIVE_WIKI_RUN_STATUSES contains every backend automatic-progress status', () => {
        for (const status of backendAutomaticProgressStatuses) {
            assert.ok(ACTIVE_WIKI_RUN_STATUSES.includes(status), `expected ACTIVE_WIKI_RUN_STATUSES to include ${status}`);
        }
    });
});

/**
 * Wiki run-4: run 4 reached status=awaiting_document_owner_approval, qa_status=passed in the
 * database, but the Kilder (sources) tab kept showing the earlier 'post_claim_verification' badge
 * indefinitely. Root cause: Index.jsx computed its polling gate (hasActiveWikiRun) by reading
 * `.status` directly off whatever list the active tab shows — correct for the Kjøringer tab
 * (`runs` items ARE ingest runs) but wrong for the Kilder tab (`sources` items are documents; the
 * ingest run status lives nested at `source.latest_ingest_run`, and `source.status` is always
 * undefined). A permanently-false polling gate for that tab meant the 5s poll never fired at all —
 * not a stale copy of data, but a gate that never let a fresh fetch happen in the first place.
 * activeWikiRunLikeObjectsForTab()/hasActiveWikiRunForTab() replace that inline per-tab check.
 */
describe('activeWikiRunLikeObjectsForTab / hasActiveWikiRunForTab — per-tab polling gate (Wiki run-4)', () => {
    test('Kilder tab: reads the nested latest_ingest_run, never the (non-existent) source.status', () => {
        const sources = [
            { id: 10, document_status: 'extracted', latest_ingest_run: { id: 4, status: 'post_claim_verification' } },
        ];

        assert.deepEqual(activeWikiRunLikeObjectsForTab('sources', { sources }), [{ id: 4, status: 'post_claim_verification' }]);
    });

    test('Kilder tab: a source with no ingest run yet is skipped, never crashes on the missing nested object', () => {
        const sources = [{ id: 11, document_status: 'pending', latest_ingest_run: null }];

        assert.doesNotThrow(() => hasActiveWikiRunForTab('sources', { sources }));
        assert.equal(hasActiveWikiRunForTab('sources', { sources }), false);
    });

    test('Kjøringer tab: run objects are used directly, not nested', () => {
        const runs = [{ id: 4, status: 'post_claim_verification' }];

        assert.deepEqual(activeWikiRunLikeObjectsForTab('runs', { runs }), runs);
    });

    test('an unrelated tab (e.g. pages/quality) never polls off either list', () => {
        assert.deepEqual(activeWikiRunLikeObjectsForTab('pages', { sources: [{ status: 'queued' }], runs: [{ status: 'queued' }] }), []);
        assert.equal(hasActiveWikiRunForTab('pages', { sources: [{ status: 'queued' }], runs: [{ status: 'queued' }] }), false);
    });

    // The exact run-4 transition: a poll response replacing 'post_claim_verification' with
    // 'awaiting_document_owner_approval' must keep the Kilder tab's polling gate open (both are
    // active per ACTIVE_WIKI_RUN_STATUSES) — proving the fix does not merely start polling, but
    // keeps it running long enough for the real transition to land — and each call is derived
    // fresh from whatever is passed in, so a new poll response is never shadowed by an old one.
    test('run-4 transition: post_claim_verification -> awaiting_document_owner_approval stays active on the Kilder tab throughout', () => {
        const sourcesBeforePoll = [
            { id: 4, document_status: 'extracted', latest_ingest_run: { id: 4, status: 'post_claim_verification', qa_status: null } },
        ];
        const sourcesAfterPoll = [
            { id: 4, document_status: 'extracted', latest_ingest_run: { id: 4, status: 'awaiting_document_owner_approval', qa_status: 'passed' } },
        ];

        assert.equal(hasActiveWikiRunForTab('sources', { sources: sourcesBeforePoll }), true, 'still active before the poll lands');
        assert.equal(hasActiveWikiRunForTab('sources', { sources: sourcesAfterPoll }), true, 'still active once the poll response replaces the old status');

        // The new props are used as-is — the old 'post_claim_verification' value is never retained
        // once sourcesAfterPoll is what's passed in (no local caching/merging inside the helper).
        const [afterRun] = activeWikiRunLikeObjectsForTab('sources', { sources: sourcesAfterPoll });
        assert.equal(afterRun.status, 'awaiting_document_owner_approval');
        assert.notEqual(afterRun.status, 'post_claim_verification');
    });

    // Demonstrates the actual bug being fixed: the previous inline check
    // (`sources.some((s) => ACTIVE_WIKI_RUN_STATUSES.includes(s.status))`) reads `.status` directly
    // off the source object rather than off `source.latest_ingest_run` — this reproduces that
    // exact wrong check to prove it always evaluates to false, unlike the fixed helper above.
    test('reproduces the pre-fix bug: checking source.status directly is always false, even while its run is active', () => {
        const sources = [
            { id: 4, document_status: 'extracted', latest_ingest_run: { id: 4, status: 'awaiting_document_owner_approval' } },
        ];

        const buggyHasActiveRun = sources.some(isActiveWikiRun) || sources.some((s) => s?.status === 'queued');
        assert.equal(buggyHasActiveRun, false, 'the old bug: source.status is always undefined, so this never detects an active run');

        assert.equal(hasActiveWikiRunForTab('sources', { sources }), true, 'the fix: reads the nested latest_ingest_run instead');
    });
});

/**
 * Wiki run-11: the Kjøringer tab kept rendering a run's first snapshot ("Vedlikeholdersbeslutning
 * behandles", 0 pages, 0 findings) long after the backend had taken it all the way to
 * awaiting_document_owner_approval with 3 generated pages. The backend and the built bundle were
 * both verified correct — the poll request returns the fresh `runs` prop — so the defect was the
 * gate deciding whether that poll ever runs.
 */
describe('hasActiveWikiRunForTab — the polling gate must never deadlock the Kjøringer tab (Wiki run-11)', () => {
    // The required end-to-end behaviour: a run observed at maintainer_decision must keep the gate
    // open across every intermediate status, all the way to awaiting_document_owner_approval, so
    // each poll replaces the run prop with the newer one and the row stops showing the decision.
    test('the gate stays open for every status between maintainer_decision and awaiting_document_owner_approval', () => {
        const pipeline = [
            'maintainer_decision',
            'applying',
            'generating_pages',
            'generating_concept_entity_pages',
            'verification_linking',
            'verifying_claims',
            'post_claim_verification',
            'qa',
            'awaiting_document_owner_approval',
        ];

        for (const status of pipeline) {
            assert.equal(
                hasActiveWikiRunForTab('runs', { runs: [{ id: 11, status }] }),
                true,
                `polling must stay on while run 11 is ${status}`,
            );
        }
    });

    // The actual root cause. decision_only leaves that state when the maintainer decision is
    // APPLIED — routinely somewhere this tab never sees (the Kilder tab, another session, the
    // API) — after which the run completes the whole pipeline on its own. Gating polling on it
    // was a deadlock, not a delay: only the poll can refresh the run data the gate reads.
    test('a run sitting in decision_only keeps polling, so an apply elsewhere is picked up', () => {
        assert.equal(hasActiveWikiRunForTab('runs', { runs: [{ id: 11, status: 'decision_only' }] }), true);
    });

    test('the same holds on the Kilder tab, where the run status is nested', () => {
        const sources = [{ id: 11, document_status: 'extracted', latest_ingest_run: { id: 11, status: 'decision_only' } }];

        assert.equal(hasActiveWikiRunForTab('sources', { sources }), true);
    });

    // decision_only must stay OUT of ACTIVE_WIKI_RUN_STATUSES: that list also drives timeline
    // state, the activity block's aria-live, and stalled detection, none of which this fix
    // touches. Only the polling gate treats it as unresolved.
    test('decision_only is a polling-gate concern only — it is never an "actively processing" status', () => {
        assert.equal(ACTIVE_WIKI_RUN_STATUSES.includes('decision_only'), false);
        assert.equal(isActiveWikiRun({ status: 'decision_only' }), false);
    });

    test('genuinely terminal runs still stop the polling gate', () => {
        for (const status of ['completed', 'failed', 'escalated', 'cancelled']) {
            assert.equal(
                hasActiveWikiRunForTab('runs', { runs: [{ id: 11, status }] }),
                false,
                `${status} must not keep polling`,
            );
        }
    });
});

describe('isRunStalled — requires BOTH an actively-processing status AND a long gap since progress', () => {
    const NOW = new Date('2026-07-31T12:00:00Z').getTime();
    const twentyMinutesAgo = new Date(NOW - 20 * 60_000).toISOString();
    const twoMinutesAgo = new Date(NOW - 2 * 60_000).toISOString();

    test('a queued run with no progress past the threshold can be flagged stalled', () => {
        const run = { status: 'queued', expects_automatic_progress: true, last_progress_at: twentyMinutesAgo };

        assert.equal(isRunStalled(run, 15, NOW), true);
    });

    test('an active generation status (generating_pages) with no progress past the threshold can be flagged stalled', () => {
        const run = { status: 'generating_pages', expects_automatic_progress: true, last_progress_at: twentyMinutesAgo };

        assert.equal(isRunStalled(run, 15, NOW), true);
    });

    test('an active run with recent progress is not flagged stalled', () => {
        const run = { status: 'generating_pages', expects_automatic_progress: true, last_progress_at: twoMinutesAgo };

        assert.equal(isRunStalled(run, 15, NOW), false);
    });

    test('awaiting_document_owner_approval is never flagged stalled, no matter how long it has waited', () => {
        const run = { status: 'awaiting_document_owner_approval', expects_automatic_progress: false, last_progress_at: twentyMinutesAgo };

        assert.equal(isRunStalled(run, 15, NOW), false);

        // Even an implausibly long wait must not flip this — the backend flag alone decides.
        const longWait = { ...run, last_progress_at: new Date(NOW - 60 * 24 * 60_000).toISOString() };
        assert.equal(isRunStalled(longWait, 15, NOW), false);
    });

    test('decision_only is never flagged stalled', () => {
        const run = { status: 'decision_only', expects_automatic_progress: false, last_progress_at: twentyMinutesAgo };

        assert.equal(isRunStalled(run, 15, NOW), false);
    });

    test('completed, failed, escalated, and cancelled are never flagged stalled', () => {
        for (const status of ['completed', 'failed', 'escalated', 'cancelled']) {
            const run = { status, expects_automatic_progress: false, last_progress_at: twentyMinutesAgo };
            assert.equal(isRunStalled(run, 15, NOW), false, `expected ${status} to never be stalled`);
        }
    });

    test('a missing expects_automatic_progress flag (undefined) is treated as not expecting progress', () => {
        const run = { status: 'generating_pages', last_progress_at: twentyMinutesAgo };

        assert.equal(isRunStalled(run, 15, NOW), false);
    });

    test('no run at all is never flagged stalled', () => {
        assert.equal(isRunStalled(null, 15, NOW), false);
        assert.equal(isRunStalled(undefined, 15, NOW), false);
    });
});

describe('getEscalationCopy — explains an escalated run instead of repeating the word "Eskalert"', () => {
    // Cause 1: claim-integrity repair path — EnterpriseWikiDocumentFlowService::
    // escalateRunForClaimIntegrityRepair() stores a specific, human-readable reason on the run
    // itself. This is the exact combination found on the real run this fix was built from
    // (source document "Masterdata ITIL.docx", run id 39): status=escalated, qa_status=passed via
    // 15 automatic maintenance-cycle retries that updated qa_status without ever reconciling the
    // run's own `status` column back (see EnterpriseWikiMaintenanceCycleService::processRun()) —
    // 3 of the run's 25 total findings are still open and blocking.
    test('claim-integrity cause: error_message becomes the primary reason, the status/qa_status drift becomes a distinct secondary line, and the blocking count is reported against the true total', () => {
        const run = {
            error_message: 'Wiki-siden ble stanset fordi systemet fant innhold som ikke kunne bekreftes mot kildegrunnlaget. Automatisk reparasjon vil bli forsøkt.',
            findings_explanation: 'Kjøringen har 3 åpne blokkerende funn, men står som bestått. Statusen må synkroniseres.',
            findings_open_blocking_count: 3,
            lint_count: 25,
        };

        const copy = getEscalationCopy(run, {});

        assert.equal(copy.primaryReason, run.error_message);
        assert.equal(copy.secondaryReason, run.findings_explanation);
        assert.equal(copy.blockingCount, 3);
        assert.match(copy.blockingSummary, /3/);
        assert.match(copy.blockingSummary, /25/);
    });

    // Cause 2: no stored error_message (the plain escalateRun() path clears it) — the QA
    // consistency check (EnterpriseWikiRunFindingsService::buildExplanation()) is the only
    // available explanation, and becomes the primary reason on its own (no secondary line, since
    // there is nothing else to add).
    test('QA-only cause: findings_explanation alone becomes the primary reason, with no secondary line', () => {
        const run = {
            error_message: null,
            findings_explanation: 'Ingen åpne blokkerende funn lenger, men kjøringens status er ikke oppdatert ennå.',
            findings_open_blocking_count: 0,
            lint_count: 4,
        };

        const copy = getEscalationCopy(run, {});

        assert.equal(copy.primaryReason, run.findings_explanation);
        assert.equal(copy.secondaryReason, null);
        assert.equal(copy.blockingCount, 0);
    });

    // Cause 3: both fields happen to hold the identical string — never show the same sentence
    // twice, matching the whole point of this fix (the row already showed "Eskalert" three times).
    test('identical error_message and findings_explanation never duplicate into two lines', () => {
        const run = {
            error_message: 'Samme forklaring.',
            findings_explanation: 'Samme forklaring.',
            findings_open_blocking_count: 1,
            lint_count: 1,
        };

        const copy = getEscalationCopy(run, {});

        assert.equal(copy.primaryReason, 'Samme forklaring.');
        assert.equal(copy.secondaryReason, null);
    });

    // Cause 4: neither field is populated (should not normally happen given buildSummary() always
    // computes an explanation, but must never crash or fabricate a reason) — falls back to the
    // same word the status badge already shows, from translations, or its hard-coded default.
    test('no data available falls back to the plain "Eskalert" word, never a guessed reason', () => {
        const run = { error_message: null, findings_explanation: null, findings_open_blocking_count: 0, lint_count: 0 };

        assert.equal(getEscalationCopy(run, {}).primaryReason, 'Eskalert');
        assert.equal(getEscalationCopy(run, { ingest_activity_escalated: 'Escalated' }).primaryReason, 'Escalated');
    });

    test('blockingSummary uses the non-blocking translation and blockingCount is 0 when nothing blocks', () => {
        const run = { error_message: null, findings_explanation: 'x', findings_open_blocking_count: 0, lint_count: 21 };

        const copy = getEscalationCopy(run, { ingest_activity_escalated_not_blocking: 'Ikke blokkerende.' });

        assert.equal(copy.blockingCount, 0);
        assert.equal(copy.blockingSummary, 'Ikke blokkerende.');
    });

    test('blockingSummary substitutes :count and :total from the provided translation template', () => {
        const run = { error_message: 'x', findings_explanation: null, findings_open_blocking_count: 2, lint_count: 9 };

        const copy = getEscalationCopy(run, { ingest_activity_escalated_blocking: ':count of :total blocks completion' });

        assert.equal(copy.blockingSummary, '2 of 9 blocks completion');
    });
});
