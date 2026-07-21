/**
 * Pure, dependency-free logic shared by the Kjøringer (runs) tab in Index.jsx — extracted so it
 * can be unit-tested with Node's built-in test runner (no bundler, no React) instead of only via
 * manual browser inspection. Every finding rendered here comes from the same canonical collection
 * the backend now uses everywhere (EnterpriseWikiRunFindingsService::buildForRun()) — this module
 * only decides how to bucket/display that already-consistent data, never re-derives which claims
 * count as user-facing.
 */

export const RUN_TIMELINE_STEPS = [
    { key: 'queued', labelKey: 'ingest_timeline_queue', fallback: 'Kø' },
    { key: 'maintainer_decision', labelKey: 'ingest_timeline_decision', fallback: 'Beslutning' },
    { key: 'applying', labelKey: 'ingest_timeline_apply', fallback: 'Anvendelse' },
    { key: 'generating_pages', labelKey: 'ingest_timeline_pages', fallback: 'Sider' },
    { key: 'verification_linking', labelKey: 'ingest_timeline_verification', fallback: 'Verifisering' },
    { key: 'qa', labelKey: 'ingest_timeline_qa', fallback: 'QA' },
    { key: 'awaiting_document_owner_approval', labelKey: 'ingest_timeline_owner_approval', fallback: 'Dokumenteier' },
];

/**
 * A finding's `status` is set by EnterpriseWikiRunFindingsService from one of three sources (lint
 * finding, claim-integrity defect, or best-practice suggestion), and each source has its own status
 * vocabulary — 'open' filter must match ALL of them, not just the lint-finding ones. Missing
 * 'requires_decision'/'user_blocking' here previously made a genuinely open, blocking claim defect
 * disappear from the "Åpne" tab even though it was correctly counted in summary.open_blocking.
 */
export function matchesFindingsLocalFilter(finding, filterKey) {
    switch (filterKey) {
        case 'open':
            return finding.status === 'requires_action'
                || finding.status === 'open'
                || finding.status === 'pending_review'
                || finding.status === 'requires_decision'
                || finding.status === 'user_blocking';
        case 'blocking':
            return finding.blocks_run;
        case 'resolved':
            return finding.status === 'resolved' || finding.status === 'approved' || finding.status === 'approved_edited' || finding.status === 'rejected';
        case 'informative':
            return finding.status === 'informative' || finding.status === 'superseded';
        default:
            return true;
    }
}

/**
 * Renders the per-run step timeline. 'escalated' is a terminal status that never literally
 * matches any RUN_TIMELINE_STEPS key, so a naive "find the step matching run.status" lookup
 * always misses for it — and previously fell back to marking the LAST step (Dokumenteier) as the
 * error location for every escalated run, regardless of why it escalated. In the current
 * architecture 'escalated' is only ever set from EnterpriseWikiDocumentFlowService::escalateRun()/
 * escalateRunForClaimIntegrityRepair(), both called exclusively from finalizeFromExistingQaResult()
 * reacting to qa_status escalated/repair_required — i.e. a run in this state ALWAYS stopped at the
 * QA step and NEVER reached Document Owner review, so the QA step (not Dokumenteier) is where the
 * error belongs.
 *
 * 'failed' is deliberately NOT given the same treatment: it can originate from many different
 * pipeline stages (maintainer decision, apply, page generation, wikilink materialization, or a QA
 * technical failure), and none of that is recoverable from run.status alone — the previous
 * fallback (mark the last step red) is kept as-is for that case pending a reliable per-step failure
 * signal.
 */
export function getRunTimelineState(run, stepIndex) {
    if (!run) return 'empty';

    if (run.status === 'decision_only') {
        return stepIndex === 0 ? 'done' : 'empty';
    }

    if (run.status === 'completed') {
        return 'done';
    }

    if (run.status === 'escalated') {
        const qaIndex = RUN_TIMELINE_STEPS.findIndex((step) => step.key === 'qa');

        if (stepIndex < qaIndex) return 'done';
        if (stepIndex === qaIndex) return 'error';

        return 'empty';
    }

    const currentIndex = RUN_TIMELINE_STEPS.findIndex((step) => step.key === run.status
        || (step.key === 'queued' && ['queued', 'running', 'sections_planned'].includes(run.status))
        || (step.key === 'generating_pages' && run.status === 'generating_concept_entity_pages'));

    if (run.status === 'failed') {
        if (currentIndex === -1) {
            return stepIndex < RUN_TIMELINE_STEPS.length - 1 ? 'done' : 'error';
        }
        if (stepIndex < currentIndex) return 'done';
        if (stepIndex === currentIndex) return 'error';

        return 'empty';
    }

    if (run.status === 'awaiting_document_owner_approval') {
        return stepIndex < RUN_TIMELINE_STEPS.length - 1 ? 'done' : 'waiting';
    }

    if (currentIndex === -1) return 'empty';
    if (stepIndex < currentIndex) return 'done';
    if (stepIndex === currentIndex) {
        return run.status === 'queued' ? 'waiting' : 'active';
    }

    return 'empty';
}
