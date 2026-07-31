import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { RUN_TIMELINE_STEPS, matchesFindingsLocalFilter, getRunTimelineState, getEscalationCopy, isRunStalled } from './runFindingsLogic.js';

describe('matchesFindingsLocalFilter', () => {
    test('"open" matches every open status a finding can carry, from every source', () => {
        // lint finding statuses
        assert.equal(matchesFindingsLocalFilter({ status: 'requires_action' }, 'open'), true);
        assert.equal(matchesFindingsLocalFilter({ status: 'open' }, 'open'), true);
        // best-practice suggestion status
        assert.equal(matchesFindingsLocalFilter({ status: 'pending_review' }, 'open'), true);
        // claim-integrity defect statuses — previously missing, so a genuinely open, blocking
        // claim defect silently disappeared from the "Åpne" tab despite being counted in
        // summary.open_blocking.
        assert.equal(matchesFindingsLocalFilter({ status: 'requires_decision' }, 'open'), true);
        assert.equal(matchesFindingsLocalFilter({ status: 'user_blocking' }, 'open'), true);
    });

    test('"open" excludes resolved/informative/superseded statuses', () => {
        for (const status of ['resolved', 'approved', 'approved_edited', 'rejected', 'informative', 'superseded']) {
            assert.equal(matchesFindingsLocalFilter({ status }, 'open'), false, `expected ${status} to be excluded from "open"`);
        }
    });

    test('"blocking" matches purely on blocks_run, regardless of status', () => {
        assert.equal(matchesFindingsLocalFilter({ status: 'requires_decision', blocks_run: true }, 'blocking'), true);
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

    test('a completed run marks every step done, including Dokumenteier', () => {
        const run = { status: 'completed' };

        for (let i = 0; i < RUN_TIMELINE_STEPS.length; i++) {
            assert.equal(getRunTimelineState(run, i), 'done');
        }
    });

    test('a run awaiting document owner approval marks that last step as waiting, not error', () => {
        const run = { status: 'awaiting_document_owner_approval' };

        assert.equal(getRunTimelineState(run, dokumenteierIndex), 'waiting');
    });

    test('an active run in generating_pages marks that step active and later steps empty', () => {
        const run = { status: 'generating_pages' };
        const pagesIndex = RUN_TIMELINE_STEPS.findIndex((step) => step.key === 'generating_pages');

        assert.equal(getRunTimelineState(run, pagesIndex), 'active');
        assert.equal(getRunTimelineState(run, pagesIndex + 1), 'empty');
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
