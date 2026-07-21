import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { RUN_TIMELINE_STEPS, matchesFindingsLocalFilter, getRunTimelineState } from './runFindingsLogic.js';

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
