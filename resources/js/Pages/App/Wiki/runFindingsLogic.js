/**
 * Pure, dependency-free logic shared by the Kjøringer (runs) tab in Index.jsx — extracted so it
 * can be unit-tested with Node's built-in test runner (no bundler, no React) instead of only via
 * manual browser inspection. Every finding rendered here comes from the same canonical collection
 * the backend now uses everywhere (EnterpriseWikiRunFindingsService::buildForRun()) — this module
 * only decides how to bucket/display that already-consistent data, never re-derives which claims
 * count as user-facing.
 */

/**
 * Technical id prefixes EnterpriseWikiRunFindingsService::buildForRun() prepends to the
 * underlying stable database id (claim id or lint finding id) to keep ids unique across finding
 * categories on the backend (e.g. 'claim-defect-5378') — never meant to be user-facing wording.
 */
const FINDING_ID_KNOWN_PREFIXES = ['claim-defect-', 'best-practice-', 'lint-'];

/**
 * Strips the backend's internal category prefix from a finding id, leaving only the stable
 * numeric database id a user can reasonably read as "the finding's ID" (e.g.
 * 'claim-defect-5378' -> '5378'). The full underlying id is never changed or lost anywhere else —
 * this only affects how it is PRESENTED. Falls back to the full original id whenever the value
 * doesn't match a known prefix, or what follows a known prefix isn't a plain numeric primary key,
 * so a user is never shown an empty/missing id.
 */
export function formatFindingUserId(id) {
    const raw = String(id ?? '');

    for (const prefix of FINDING_ID_KNOWN_PREFIXES) {
        if (raw.startsWith(prefix)) {
            const remainder = raw.slice(prefix.length);

            return /^\d+$/.test(remainder) ? remainder : raw;
        }
    }

    return raw;
}

/**
 * Resolves the finding a "Tilbake til funn" deep link points at, within one run's already-fetched
 * findings.
 *
 * The link carries the backend's internal id ('best-practice-5390' — see
 * EnterpriseWikiRunFindingsService::returnUrlForFinding()), but a user reading the UI only ever
 * sees the stripped number, so a hand-typed or hand-edited '?focus_finding=5390' resolves too.
 * The internal id is matched first: it is unambiguous, whereas the bare number can in principle
 * collide across categories (lint 41 and claim 41 are different findings). Returns null for a
 * missing, unknown or ambiguous id, which is what makes the deep link degrade into an ordinary
 * "open the run's findings" navigation rather than focusing the wrong row.
 */
export function resolveFocusedFinding(findings, focusFindingId) {
    const target = String(focusFindingId ?? '').trim();

    if (target === '' || !Array.isArray(findings)) {
        return null;
    }

    const exact = findings.find((finding) => String(finding?.id ?? '') === target);

    if (exact) {
        return exact;
    }

    const byUserId = findings.filter((finding) => formatFindingUserId(finding?.id) === target);

    return byUserId.length === 1 ? byUserId[0] : null;
}

/**
 * The local filter chip the panel must be on for the focused finding to be visible at all. The
 * panel defaults to 'open', so a deep link to an already-approved or resolved finding would
 * otherwise land on a row that is filtered away — the user would be returned to an empty-looking
 * list. Keeps the current filter whenever the finding already passes it, so arriving at an open
 * finding never silently widens the view.
 */
export function focusedFindingLocalFilter(finding, currentFilter) {
    if (!finding) {
        return currentFilter;
    }

    return matchesFindingsLocalFilter(finding, currentFilter) ? currentFilter : 'all';
}

export const RUN_TIMELINE_STEPS = [
    { key: 'queued', labelKey: 'ingest_timeline_queue', fallback: 'Kø' },
    { key: 'maintainer_decision', labelKey: 'ingest_timeline_decision', fallback: 'Beslutning' },
    { key: 'applying', labelKey: 'ingest_timeline_apply', fallback: 'Oppretter sidestruktur' },
    { key: 'generating_pages', labelKey: 'ingest_timeline_pages', fallback: 'Sider' },
    { key: 'verification_linking', labelKey: 'ingest_timeline_verification', fallback: 'Verifisering' },
    { key: 'qa', labelKey: 'ingest_timeline_qa', fallback: 'QA' },
    { key: 'awaiting_document_owner_approval', labelKey: 'ingest_timeline_owner_approval', fallback: 'Dokumenteier' },
];

/**
 * A finding's `status` is set by EnterpriseWikiRunFindingsService from one of three sources (lint
 * finding, claim QA signal, or best-practice suggestion), and each source has its own status
 * vocabulary — 'open' filter must match ALL of them, not just the lint-finding ones.
 * 'open_for_qa_review'/'flagged_for_review' (claim QA signals, v0.10 — docs/enterprise-llm-wiki-plan.md,
 * "Arkitekturnotat — v0.10") are voluntary, never blocking — they must still appear under "Åpne" so a
 * QA specialist can find them, but the 'blocking' filter below correctly never matches them since
 * finding.blocks_run is always false for a claim QA signal.
 */
export function matchesFindingsLocalFilter(finding, filterKey) {
    switch (filterKey) {
        case 'open':
            return finding.status === 'requires_action'
                || finding.status === 'open'
                || finding.status === 'pending_review'
                || finding.status === 'open_for_qa_review'
                || finding.status === 'flagged_for_review';
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
 * Matches a status-like value (run.status while active, or run.failed_phase for a failed run) to
 * its RUN_TIMELINE_STEPS index, applying the same aliasing either one may need: 'queued' covers
 * the early 'running'/'sections_planned' sub-states, 'generating_pages' also covers
 * 'generating_concept_entity_pages' (phase 2 of page generation), and 'verification_linking' also
 * covers 'verifying_claims' and 'post_claim_verification' — both are backend sub-phases of the
 * same broader verification stage (claim-by-claim verification, then the brief hand-off before
 * lint/semantic-repair/QA) and were previously entirely unmapped here, which made
 * getRunTimelineState() return 'empty' for every step (a fully blank timeline) whenever a run sat
 * in either status. Their own distinct status badge text ("Verifiserer påstander"/"Etterbehandler
 * påstander", INGEST_STATUS_LABELS in Index.jsx) is unaffected — this only fixes which existing
 * timeline step lights up. Returns -1 when nothing matches.
 */
function findTimelineStepIndex(statusLike) {
    return RUN_TIMELINE_STEPS.findIndex((step) => step.key === statusLike
        || (step.key === 'queued' && ['queued', 'running', 'sections_planned'].includes(statusLike))
        || (step.key === 'generating_pages' && statusLike === 'generating_concept_entity_pages')
        || (step.key === 'verification_linking' && ['verifying_claims', 'post_claim_verification'].includes(statusLike)));
}

/**
 * Every backend status (EnterpriseWikiIngestRun::EXPECTS_AUTOMATIC_PROGRESS_STATUSES) for which
 * the pipeline is expected to keep moving on its own without further human input, plus
 * 'awaiting_document_owner_approval' — technically a human-action wait, but one the frontend has
 * always kept polling for so an approval completed elsewhere (e.g. by the document owner in a
 * different session) is picked up automatically. Deliberately excludes 'decision_only' and
 * 'queued': 'decision_only' waits on an explicit user action from a different flow (never
 * resolves via background polling), and 'queued' is handled by callers via a direct
 * `status === 'queued'` check rather than this list, matching existing behavior.
 *
 * This is the single shared source for "should we keep polling for this run" — previously
 * duplicated ad hoc in Index.jsx as a plain local array that had silently drifted out of sync
 * with the backend enum (missing 'verifying_claims'/'post_claim_verification' entirely, which
 * made Sources/Runs-tab polling stop dead for a run sitting in either status).
 */
export const ACTIVE_WIKI_RUN_STATUSES = [
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
    'awaiting_document_owner_approval',
];

export function isActiveWikiRun(run) {
    return !!run && ACTIVE_WIKI_RUN_STATUSES.includes(run.status);
}

/**
 * Wiki run-4: the run-like objects the Kjøringer/Kilder polling gate (Index.jsx) must inspect for
 * "is anything still actively processing" — differ per tab. The Kjøringer tab's `runs` prop items
 * ARE ingest runs (each has its own `.status` directly). The Kilder tab's `sources` prop items are
 * NOT ingest runs — a source has its own `document_status` lifecycle field, and the actual ingest
 * run status lives nested at `source.latest_ingest_run`. Checking `source.status` directly (the
 * bug: it's always undefined) made the Kilder tab's polling gate permanently false, so a source's
 * badge could sit on a stale status (e.g. 'post_claim_verification') forever after the underlying
 * run had already moved on (e.g. to 'awaiting_document_owner_approval') — until a full manual page
 * reload re-fetched the true status. Every call recomputes fresh from whatever `sources`/`runs` are
 * passed in — there is no memoization here, so a new poll response is always reflected immediately.
 */
export function activeWikiRunLikeObjectsForTab(activeTab, { sources = [], runs = [] } = {}) {
    if (activeTab === 'sources') {
        return sources.map((source) => source?.latest_ingest_run).filter(Boolean);
    }

    if (activeTab === 'runs') {
        return runs;
    }

    return [];
}

/**
 * Statuses that are not themselves "actively processing" (so they stay out of
 * ACTIVE_WIKI_RUN_STATUSES, which also drives timeline/activity/aria-live semantics) but must
 * still keep the polling gate open, because the run can leave them without this browser tab doing
 * anything:
 *
 *  - 'queued': the worker picks it up on its own moments later.
 *  - 'decision_only' (Wiki run-11): waits for the maintainer decision to be APPLIED — which
 *    routinely happens somewhere this tab never sees (the Kilder tab, another session, or the
 *    API), after which the run runs the entire pipeline to completion automatically. This is the
 *    exact same argument ACTIVE_WIKI_RUN_STATUSES already documents for
 *    'awaiting_document_owner_approval' ("an approval completed elsewhere ... is picked up
 *    automatically"); excluding decision_only was inconsistent with it.
 *
 * Leaving decision_only out created a deadlock rather than a delay: the poll that would fetch the
 * run's new state is gated on run data that only that poll can refresh, so a Kjøringer tab opened
 * while a run sat in decision_only kept rendering that first snapshot — "Vedlikeholdersbeslutning
 * behandles", 0 pages, 0 findings — indefinitely, even after the run had reached
 * awaiting_document_owner_approval with its pages generated. Only a manual browser refresh
 * recovered it.
 */
const POLL_UNTIL_RESOLVED_STATUSES = ['queued', 'decision_only'];

export function hasActiveWikiRunForTab(activeTab, { sources = [], runs = [] } = {}) {
    const candidates = activeWikiRunLikeObjectsForTab(activeTab, { sources, runs });

    return candidates.some(isActiveWikiRun)
        || candidates.some((run) => POLL_UNTIL_RESOLVED_STATUSES.includes(run?.status));
}

/**
 * Wiki run-3: derives the Dokumenteiergodkjenning (last) timeline step's state from the run's
 * actual document-owner approval evidence (`run.document_owner_approval`, built by
 * WikiController::documentOwnerApprovalCountsForRun()) instead of ever inferring it from
 * run.status. A human approve/reject decision never transitions run.status itself, so
 * run.status === 'completed' only ever proves the technical pipeline (and any automated QA)
 * finished — it is never proof a Document Owner looked at anything. Distinguishes:
 *
 *   - 'not_required': no page this run produced currently carries a live approval requirement
 *     (e.g. only an open, non-blocking claim QA signal, or the produced version was since
 *     superseded by a newer run) — rendered neutrally, never green, since no human approval could
 *     possibly have happened here.
 *   - 'error': at least one required approval was rejected.
 *   - 'waiting': at least one required approval is still undecided (including an unassigned owner
 *     or an approval row not yet synced).
 *   - 'done': every required approval was actually approved by a human.
 */
function documentOwnerApprovalStepState(run) {
    const approval = run?.document_owner_approval;
    const requiredCount = approval?.required_count ?? 0;

    if (!approval || requiredCount === 0) {
        return 'not_required';
    }

    if ((approval.rejected_count ?? 0) > 0) {
        return 'error';
    }

    if ((approval.pending_count ?? 0) > 0) {
        return 'waiting';
    }

    return 'done';
}

/**
 * Renders the per-run step timeline. 'escalated' is a terminal status that never literally
 * matches any RUN_TIMELINE_STEPS key, so a naive "find the step matching run.status" lookup
 * always misses for it — and previously fell back to marking the LAST step (Dokumenteier) as the
 * error location for every escalated run, regardless of why it escalated. In the current
 * architecture 'escalated' is only ever set from EnterpriseWikiDocumentFlowService::escalateRun(),
 * called from finalizeFromExistingQaResult() reacting to qa_status=escalated (a genuine technical
 * QA outcome — since v0.10, docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat — v0.10", claim QA
 * signals never reach qa_status=repair_required/escalated on their own) — i.e. a run in this state
 * ALWAYS stopped at the QA step and NEVER reached Document Owner review, so the QA step (not
 * Dokumenteier) is where the error belongs.
 *
 * 'failed' (Wiki run-588): can originate from many different pipeline stages (maintainer
 * decision, apply, page generation, wikilink materialization, or a QA technical failure) — this
 * used to be unrecoverable from run.status alone (which is always the generic 'failed' by the time
 * the frontend sees it) and fell back to marking every step but the last as done, regardless of
 * where the run actually stopped. EnterpriseWikiDocumentFlowService::markRunFailed() now persists
 * the actual phase separately as run.failed_phase, so the real failing step is used directly: every
 * step before it is done, the failing step itself is the error, and every step after it is empty
 * (never reached — never implied complete). When failed_phase is missing or unrecognized (an older
 * run recorded before this field existed, or some future/unexpected value), every step is shown
 * neutrally ('empty') rather than guessing — the run's own overall status label elsewhere in the UI
 * already conveys "Feilet"; this per-step timeline must never imply verification/QA completed
 * without real data to back that up.
 */
export function getRunTimelineState(run, stepIndex) {
    if (!run) return 'empty';

    const lastStepIndex = RUN_TIMELINE_STEPS.length - 1;

    if (run.status === 'decision_only') {
        return stepIndex === 0 ? 'done' : 'empty';
    }

    // Wiki run-3: 'completed' proves every earlier pipeline step actually finished, but it must
    // never blanket-imply the last step (Dokumenteiergodkjenning) — that one is decided solely by
    // documentOwnerApprovalStepState() below, from real approval evidence.
    if (run.status === 'completed') {
        return stepIndex === lastStepIndex ? documentOwnerApprovalStepState(run) : 'done';
    }

    if (run.status === 'escalated') {
        const qaIndex = RUN_TIMELINE_STEPS.findIndex((step) => step.key === 'qa');

        if (stepIndex < qaIndex) return 'done';
        if (stepIndex === qaIndex) return 'error';

        return 'empty';
    }

    if (run.status === 'failed') {
        const failedIndex = findTimelineStepIndex(run.failed_phase);

        if (failedIndex === -1) {
            return 'empty';
        }

        if (stepIndex < failedIndex) return 'done';
        if (stepIndex === failedIndex) return 'error';

        return 'empty';
    }

    const currentIndex = findTimelineStepIndex(run.status);

    // EnterpriseWikiDocumentOwnerApprovalService::evaluateRunCompletionGate() never lets a run
    // leave this status while any approval is rejected (rejected keeps 'ready' false exactly like
    // pending does) — so a rejection is only ever visible to the frontend while run.status is
    // still 'awaiting_document_owner_approval', never once it reaches 'completed'. Uses the same
    // evidence-based state as the 'completed' branch above instead of a hardcoded 'waiting', so
    // that rejection surfaces as the step's error state rather than being shown as still pending.
    if (run.status === 'awaiting_document_owner_approval') {
        return stepIndex < lastStepIndex ? 'done' : documentOwnerApprovalStepState(run);
    }

    if (currentIndex === -1) return 'empty';
    if (stepIndex < currentIndex) return 'done';
    if (stepIndex === currentIndex) {
        return run.status === 'queued' ? 'waiting' : 'active';
    }

    return 'empty';
}

/**
 * A run's `status` badge already says "Eskalert" — historically RunActivityBlock's activity pill
 * and detail line both repeated that exact word for the escalated case (label === detail === the
 * literal Norwegian word for "escalated"), giving the row up to 3 identical chips with zero
 * explanation of why. This derives one real explanation instead, using data that is already
 * computed on every run row:
 *
 *   - run.error_message: the specific technical reason the run was escalated in the first place
 *     (markRunFailed() sets it for a genuine pipeline exception; plain escalateRun() calls clear
 *     it since v0.10 removed the claim-content-specific escalation path).
 *   - run.findings_explanation: EnterpriseWikiRunFindingsService::buildExplanation()'s live
 *     comparison of qa_status against the actual open-blocking-findings count — this is what
 *     catches (and plainly states) the maintenance-cycle drift where qa_status has since moved to
 *     "passed" via automatic retries without the run's own `status` ever being reconciled back from
 *     "escalated" (see EnterpriseWikiMaintenanceCycleService::processRun()).
 *
 * Both can be present and say different things (the original cause vs. the current inconsistency);
 * both are shown when that happens. Neither is guessed — a run with neither ends up on the same
 * generic fallback word the badge already shows, never a fabricated reason.
 */
/**
 * Whether a run's row should show the "Ser ut til å stå stille" warning and its explanation.
 * Requires BOTH halves — a status where the automatic pipeline still expects to make technical
 * progress (run.expects_automatic_progress, computed once centrally by
 * EnterpriseWikiIngestRun::expectsAutomaticProgress() and never re-derived from the status
 * string here), AND a genuinely long gap since the last recorded activity. A run waiting on a
 * human decision (e.g. awaiting_document_owner_approval) can sit idle for hours by design —
 * that is never "stalled", regardless of how long ago its last progress timestamp was.
 *
 * `now` is injectable (defaults to the real current time) purely so this stays deterministic
 * under a fixed clock in unit tests — callers never need to pass it themselves.
 */
export function isRunStalled(run, thresholdMinutes = 15, now = Date.now()) {
    if (!run || !run.expects_automatic_progress) {
        return false;
    }

    const progressAt = run.last_progress_at ?? run.updated_at ?? run.started_at ?? run.created_at;

    if (!progressAt) {
        return false;
    }

    const staleMinutes = Math.max(0, Math.round((now - new Date(progressAt).getTime()) / 60000));

    return staleMinutes >= thresholdMinutes;
}

export function getEscalationCopy(run, tw = {}) {
    const blockingCount = run?.findings_open_blocking_count ?? 0;
    const totalCount = run?.lint_count ?? 0;
    const primaryReason = run?.error_message || run?.findings_explanation || (tw.ingest_activity_escalated ?? 'Eskalert');
    const secondaryReason = (run?.error_message && run?.findings_explanation && run.findings_explanation !== primaryReason)
        ? run.findings_explanation
        : null;
    const blockingSummary = blockingCount > 0
        ? (tw.ingest_activity_escalated_blocking ?? ':count av :total funn er fortsatt åpne og blokkerer fullføring.')
            .replace(':count', blockingCount)
            .replace(':total', totalCount)
        : (tw.ingest_activity_escalated_not_blocking ?? 'Ingen åpne blokkerende funn lenger. Eskaleringen stopper ikke videre arbeid, men statusen bør oppdateres.');

    return { primaryReason, secondaryReason, blockingSummary, blockingCount };
}

// Wiki run-592: a maintainer_decision failure classified as a documented transient
// HTTP/network condition (timeout, connection reset, 429/5xx) gets a friendly, non-technical
// primary message instead of the raw exception text — the raw text is still shown, but only in a
// secondary "technical details" field the caller renders separately (never as the main message).
export function getTransientFailureCopy(run, tw = {}) {
    const errorMessage = run?.error_message ?? '';
    const isTimeout = /timeout|timed out|curl error 28/i.test(errorMessage);
    const primaryMessage = isTimeout
        ? (tw.run_transient_timeout_message ?? 'AI-tjenesten svarte ikke innen tidsgrensen under planleggingen av Wiki-sidene. Dokumentet og kildegrunnlaget er bevart. Du kan prøve beslutningsfasen på nytt uten å laste opp dokumentet igjen.')
        : (tw.run_transient_generic_message ?? 'En midlertidig kommunikasjonsfeil oppstod under planleggingen av Wiki-sidene. Dokumentet og kildegrunnlaget er bevart.');
    const failedInPhase = tw.run_transient_failed_in_phase ?? 'Feilen oppstod i fasen «Beslutning».';
    const attemptCount = run?.maintainer_decision_attempt_count ?? 0;
    const attemptSummary = attemptCount > 0
        ? (tw.run_transient_attempt_count ?? ':count forsøk totalt.').replace(':count', attemptCount)
        : null;
    const documentPreservedNote = tw.run_transient_document_preserved_note ?? 'Dokumentet er bevart. Ny opplasting er ikke nødvendig.';

    return { primaryMessage, failedInPhase, attemptSummary, documentPreservedNote, technicalDetails: errorMessage || null };
}
