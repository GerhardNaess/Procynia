import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import ActionDialog from '../../../Components/App/ActionDialog';
import InfoHint from '../../../Components/App/InfoHint';
import {
    DISCLOSURE_INLINE,
    PRIMARY_ACTION,
    SECONDARY_ACTION,
    WARNING_ACTION,
} from '../../../Support/actionStyles';

/**
 * The review state of one Wiki page, and whatever the viewer can actually do about it.
 *
 * The page answers six questions in order: what is published, what is being worked on, who owns it,
 * who are we waiting for, what was I asked to change, and what can I do now. Anything that is not an
 * answer to one of those is left out — a reader who is not part of the workflow sees almost nothing.
 *
 * The distinction the whole panel exists to make: `published_version_id` is what readers and Spør
 * Wiki rely on, and it keeps serving while a newer version is drafted, reviewed or sent back. A page
 * that says "Endringer kreves" has not lost its published knowledge, and the UI must not imply it.
 */

const MIN_REASON = 10;
const MAX_REASON = 2000;

/** Why final approval is unavailable, in words the person reading them can act on. */
function blockerMessage(blocker, gate, tw) {
    switch (blocker) {
        case 'source_owners_pending': {
            const waitingFor = (gate?.requirements ?? [])
                .filter((requirement) => requirement.status === 'pending')
                .map((requirement) => requirement.owner?.name)
                .filter(Boolean);

            return waitingFor.length > 0
                ? `${tw.review_waiting_for_owners ?? 'Venter på dokumenteiergodkjenning fra'} ${waitingFor.join(', ')}.`
                : (tw.review_waiting_for_owners_generic ?? 'Venter på dokumenteiergodkjenning.');
        }
        case 'own_submission':
            return tw.review_blocked_own_submission ?? 'Du kan ikke godkjenne en versjon du selv har sendt inn.';
        case 'not_assigned':
            return tw.review_blocked_not_assigned ?? 'En annen er tildelt som kontrollør for denne versjonen.';
        case 'missing_assignment':
            return tw.review_blocked_missing_assignment ?? 'Versjonen mangler en gyldig innsending. Gjenåpne siden og send den inn på nytt.';
        default:
            // not_in_review and missing_capability are not this reader's problem to solve, so they
            // are simply not mentioned.
            return null;
    }
}

function OwnerLine({ label, name }) {
    if (! name) {
        return null;
    }

    return (
        <span className="text-slate-600">
            {label} <span className="font-medium text-slate-900">{name}</span>
        </span>
    );
}

const REQUIREMENT_STATUS = {
    pending: { label: 'Venter', cls: 'bg-amber-100 text-amber-800' },
    approved: { label: 'Godkjent', cls: 'bg-emerald-100 text-emerald-700' },
    rejected: { label: 'Endringer kreves', cls: 'bg-rose-100 text-rose-700' },
};

export default function WikiReviewPanel({ page, currentVersion, reviewAssignment, tw = {}, isSystemOwner = false, currentUserId = null }) {
    const [processing, setProcessing] = useState(null);
    const [isSubmitOpen, setIsSubmitOpen] = useState(false);
    const [reviewerId, setReviewerId] = useState('');
    const [changesTarget, setChangesTarget] = useState(null);
    const [reason, setReason] = useState('');
    const [showHistory, setShowHistory] = useState(false);

    const submitTriggerRef = useRef(null);
    const reviewerSelectRef = useRef(null);
    const reasonRef = useRef(null);

    if (! reviewAssignment) {
        return null;
    }

    const gate = reviewAssignment.source_owner_gate;
    const changes = reviewAssignment.changes_requested ?? { is_returned: false, latest: null, history: [] };
    const eligibleReviewers = reviewAssignment.eligible_reviewers ?? [];
    const requirements = gate?.requirements ?? [];

    const isReturned = page.status === 'rejected';
    const isInReview = page.status === 'pending_review';
    const canSubmit = reviewAssignment.can_submit && page.status === 'draft';
    const canReopen = reviewAssignment.can_submit && isReturned;
    const blocker = blockerMessage(reviewAssignment.final_approval_blocker, gate, tw);
    const showsAnything = canSubmit || canReopen || isInReview || isReturned || requirements.length > 0;

    if (! showsAnything) {
        return null;
    }

    const run = (key, url, data = {}) => {
        if (processing) return;
        setProcessing(key);
        router.patch(url, data, {
            preserveScroll: true,
            onFinish: () => setProcessing(null),
            onSuccess: () => {
                setIsSubmitOpen(false);
                setChangesTarget(null);
                setReason('');
            },
        });
    };

    const submit = () => run('submit', `/app/wiki/${page.slug}/submit`, { reviewer_user_id: Number(reviewerId) });
    const reopen = () => run('reopen', `/app/wiki/${page.slug}/submit`);
    const approve = () => run('approve', `/app/wiki/${page.slug}/approve`);
    const approveRequirement = (id) => run(`req-${id}`, `/app/wiki/${page.slug}/document-owner-approvals/${id}/approve`);

    const requestChanges = () => {
        const trimmed = reason.trim();

        if (trimmed.length < MIN_REASON) return;

        if (changesTarget === 'page') {
            run('reject', `/app/wiki/${page.slug}/reject`, { reason: trimmed });

            return;
        }

        run(`req-${changesTarget}`, `/app/wiki/${page.slug}/document-owner-approvals/${changesTarget}/reject`, { comment: trimmed });
    };

    const busy = processing !== null;
    const reasonTooShort = reason.trim().length < MIN_REASON;

    return (
        <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_4px_14px_rgba(15,23,42,0.04)]">
            {/* What readers get versus what is being worked on. The single most misread thing on
                this page, so it comes first and is stated plainly. */}
            <div className="flex flex-wrap items-baseline gap-x-6 gap-y-1 text-sm">
                {reviewAssignment.published_version_id ? (
                    <span className="text-slate-600">
                        {tw.review_published_version ?? 'Publisert versjon'}{' '}
                        <span className="font-semibold text-emerald-700">
                            v{reviewAssignment.published_version_number ?? currentVersion?.version_number}
                        </span>
                    </span>
                ) : (
                    <span className="text-slate-500">
                        {tw.review_nothing_published ?? 'Ingen publisert versjon ennå'}
                    </span>
                )}

                {currentVersion && reviewAssignment.published_version_id !== currentVersion.id && (
                    <span className="text-slate-600">
                        {tw.review_working_version ?? 'Arbeidsversjon'}{' '}
                        <span className="font-semibold text-slate-900">v{currentVersion.version_number}</span>
                    </span>
                )}
            </div>

            {reviewAssignment.published_version_id && currentVersion
                && reviewAssignment.published_version_id !== currentVersion.id && (
                <p className="text-sm text-slate-500">
                    {tw.review_published_still_serves
                        ?? 'Den publiserte versjonen er fortsatt den brukerne og Spør Wiki får. Arbeidsversjonen påvirker ingenting før den er godkjent.'}
                </p>
            )}

            {/* Responsibility, only where there is something to say. */}
            {(page.owner?.name || reviewAssignment.submitted_by || reviewAssignment.reviewer) && (
                <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    <OwnerLine label={tw.review_page_owner ?? 'Sideeier:'} name={page.owner?.name} />
                    <OwnerLine label={tw.review_submitted_by ?? 'Sendt inn av:'} name={reviewAssignment.submitted_by?.name} />
                    <OwnerLine label={tw.review_reviewer ?? 'Kontrollør:'} name={reviewAssignment.reviewer?.name} />
                </div>
            )}

            {/* What the owner was asked to change. Nearest the top, because it is the reason the
                page is sitting still. */}
            {isReturned && changes.latest && (
                <div className="rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <p className="text-sm font-semibold text-amber-900">
                        {tw.review_changes_requested ?? 'Endringer kreves'}
                    </p>
                    <p className="mt-1 text-sm text-amber-900">{changes.latest.reason}</p>
                    <p className="mt-2 text-xs text-amber-800">
                        {changes.latest.actor?.name ?? (tw.review_unknown_actor ?? 'Ukjent')}
                        {' · '}
                        {changes.latest.actor_role === 'document_owner'
                            ? (tw.review_role_document_owner ?? 'dokumenteier')
                            : (tw.review_role_reviewer ?? 'kontrollør')}
                    </p>

                    {changes.history.length > 1 && (
                        <>
                            <button
                                type="button"
                                onClick={() => setShowHistory((open) => ! open)}
                                aria-expanded={showHistory}
                                aria-controls="wiki-changes-history"
                                className={`${DISCLOSURE_INLINE} mt-3`}
                            >
                                {showHistory
                                    ? (tw.review_hide_history ?? 'Skjul tidligere tilbakemeldinger')
                                    : (tw.review_show_history ?? 'Vis tidligere tilbakemeldinger')}
                            </button>

                            <ul id="wiki-changes-history" hidden={! showHistory} className="mt-3 space-y-2">
                                {changes.history.slice(1).map((event) => (
                                    <li key={event.id} className="rounded-lg bg-white/70 px-3 py-2 text-sm text-amber-900">
                                        {event.reason}
                                        <span className="mt-1 block text-xs text-amber-800">
                                            {event.actor?.name ?? (tw.review_unknown_actor ?? 'Ukjent')}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </div>
            )}

            {/* Who still has to vouch for their own source documents. Not final approval — the
                wording and the placement both keep that distinction. */}
            {isInReview && requirements.length > 0 && (
                <div className="space-y-2">
                    <p className="text-sm font-semibold text-slate-900">
                        {tw.review_source_owner_heading ?? 'Kildegrunnlag'}
                    </p>

                    <ul className="space-y-2">
                        {requirements.map((requirement) => {
                            const status = REQUIREMENT_STATUS[requirement.status] ?? REQUIREMENT_STATUS.pending;

                            return (
                                <li
                                    key={requirement.id}
                                    className="flex flex-col gap-2 rounded-xl border border-slate-200 px-3 py-2 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div className="min-w-0 text-sm">
                                        <span className="font-medium text-slate-900">
                                            {requirement.owner?.name ?? (tw.review_owner_missing ?? 'Mangler dokumenteier')}
                                        </span>
                                        <span className={`ml-2 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${status.cls}`}>
                                            {status.label}
                                        </span>
                                        <span className="mt-0.5 block text-xs text-slate-500">
                                            {(requirement.source_document_ids ?? []).length === 1
                                                ? (tw.review_one_document ?? '1 kildedokument')
                                                : `${(requirement.source_document_ids ?? []).length} ${tw.review_documents ?? 'kildedokumenter'}`}
                                            {requirement.decided_by ? ` · ${requirement.decided_by}` : ''}
                                        </span>
                                    </div>

                                    {requirement.can_decide && requirement.status === 'pending' && (
                                        <div className="flex shrink-0 flex-wrap gap-2">
                                            <button
                                                type="button"
                                                disabled={busy}
                                                onClick={() => approveRequirement(requirement.id)}
                                                className={`${PRIMARY_ACTION} min-h-9 px-3 py-1.5 text-sm`}
                                            >
                                                {tw.review_approve_source ?? 'Godkjenn kildeinnhold'}
                                            </button>
                                            <button
                                                type="button"
                                                disabled={busy}
                                                onClick={() => { setChangesTarget(requirement.id); setReason(''); }}
                                                className={`${WARNING_ACTION} min-h-9 px-3 py-1.5 text-sm`}
                                            >
                                                {tw.review_request_changes ?? 'Be om endringer'}
                                            </button>
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}

            {/* Actions. */}
            <div className="flex flex-wrap items-center gap-2">
                {canSubmit && (
                    <>
                        <button
                            ref={submitTriggerRef}
                            type="button"
                            disabled={busy || eligibleReviewers.length === 0}
                            onClick={() => { setReviewerId(eligibleReviewers.length === 1 ? String(eligibleReviewers[0].id) : ''); setIsSubmitOpen(true); }}
                            className={PRIMARY_ACTION}
                        >
                            {tw.submit_button ?? 'Send til gjennomgang'}
                        </button>
                        <InfoHint
                            size="sm"
                            label={tw.show_page_help_submit_hint_label ?? 'Vis forklaring for Send til gjennomgang'}
                            text={tw.show_page_help_submit_hint ?? 'Du velger en kontrollør. Dokumenteiere må godkjenne innhold fra sine kilder, og kontrolløren publiserer til slutt.'}
                        />
                    </>
                )}

                {canSubmit && eligibleReviewers.length === 0 && (
                    <p className="text-sm text-slate-600">
                        {tw.review_no_eligible_reviewers
                            ?? 'Ingen andre har rettighet til å godkjenne Wiki-sider ennå, så siden kan ikke sendes videre.'}
                    </p>
                )}

                {canReopen && (
                    <button
                        type="button"
                        disabled={busy}
                        onClick={reopen}
                        className={PRIMARY_ACTION}
                    >
                        {tw.reopen_button ?? 'Gjenåpne for redigering'}
                    </button>
                )}

                {isInReview && reviewAssignment.can_approve_final && (
                    <>
                        <button
                            type="button"
                            disabled={busy}
                            onClick={approve}
                            className={PRIMARY_ACTION}
                        >
                            {isSystemOwner && reviewAssignment.reviewer && currentUserId !== null
                                && reviewAssignment.reviewer.id !== currentUserId
                                ? (tw.review_approve_as_system_owner ?? 'Godkjenn og publiser som System Owner')
                                : (tw.review_approve_and_publish ?? 'Godkjenn og publiser')}
                        </button>
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => { setChangesTarget('page'); setReason(''); }}
                            className={WARNING_ACTION}
                        >
                            {tw.review_request_changes ?? 'Be om endringer'}
                        </button>
                    </>
                )}
            </div>

            {isInReview && ! reviewAssignment.can_approve_final && blocker && (
                <p className="text-sm text-slate-600">{blocker}</p>
            )}

            {/* Choosing who takes over. Never implicit, even when only one person is possible. */}
            <ActionDialog
                isOpen={isSubmitOpen}
                onClose={() => setIsSubmitOpen(false)}
                closeDisabled={busy}
                titleId="wiki-submit-title"
                initialFocusRef={reviewerSelectRef}
                returnFocusRef={submitTriggerRef}
            >
                <h2 id="wiki-submit-title" className="text-xl font-semibold tracking-tight text-slate-950">
                    {tw.submit_button ?? 'Send til gjennomgang'}
                </h2>
                <p className="mt-2 text-base leading-6 text-slate-600">
                    {tw.review_submit_description ?? 'Velg hvem som skal kontrollere denne versjonen. Kontrolløren får beskjed og blir ansvarlig for å godkjenne eller be om endringer.'}
                </p>

                <label className="mt-4 block space-y-1.5" htmlFor="wiki-reviewer">
                    <span className="text-base font-medium text-slate-800">{tw.review_reviewer ?? 'Kontrollør'}</span>
                    <select
                        ref={reviewerSelectRef}
                        id="wiki-reviewer"
                        value={reviewerId}
                        onChange={(event) => setReviewerId(event.target.value)}
                        className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-base text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                    >
                        <option value="">{tw.review_choose_reviewer ?? 'Velg kontrollør'}</option>
                        {eligibleReviewers.map((reviewer) => (
                            <option key={reviewer.id} value={reviewer.id}>{reviewer.name}</option>
                        ))}
                    </select>
                </label>

                <div className="mt-6 flex flex-wrap gap-3">
                    <button type="button" disabled={busy || reviewerId === ''} onClick={submit} className={PRIMARY_ACTION}>
                        {tw.submit_button ?? 'Send til gjennomgang'}
                    </button>
                    <button type="button" disabled={busy} onClick={() => setIsSubmitOpen(false)} className={SECONDARY_ACTION}>
                        {tw.cancel ?? 'Avbryt'}
                    </button>
                </div>
            </ActionDialog>

            {/* Asking for changes. The reason is what the owner works from, so it is required. */}
            <ActionDialog
                isOpen={changesTarget !== null}
                onClose={() => setChangesTarget(null)}
                closeDisabled={busy}
                titleId="wiki-changes-title"
                initialFocusRef={reasonRef}
            >
                <h2 id="wiki-changes-title" className="text-xl font-semibold tracking-tight text-slate-950">
                    {tw.review_request_changes ?? 'Be om endringer'}
                </h2>
                <p className="mt-2 text-base leading-6 text-slate-600">
                    {changesTarget === 'page'
                        ? (tw.review_request_changes_page ?? 'Siden sendes tilbake til sideeier. Den publiserte versjonen berøres ikke.')
                        : (tw.review_request_changes_source ?? 'Kildegrunnlaget avvises og siden sendes tilbake til sideeier. Den publiserte versjonen berøres ikke.')}
                </p>

                <label className="mt-4 block space-y-1.5" htmlFor="wiki-change-reason">
                    <span className="text-base font-medium text-slate-800">{tw.review_reason ?? 'Begrunnelse'}</span>
                    <textarea
                        ref={reasonRef}
                        id="wiki-change-reason"
                        rows={4}
                        maxLength={MAX_REASON}
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        aria-describedby="wiki-change-reason-help"
                        className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-base text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                    />
                    <span id="wiki-change-reason-help" className="block text-sm text-slate-500">
                        {tw.review_reason_help ?? 'Beskriv hva som må rettes.'} {reason.trim().length}/{MAX_REASON}
                    </span>
                </label>

                <div className="mt-6 flex flex-wrap gap-3">
                    <button type="button" disabled={busy || reasonTooShort} onClick={requestChanges} className={WARNING_ACTION}>
                        {tw.review_request_changes ?? 'Be om endringer'}
                    </button>
                    <button type="button" disabled={busy} onClick={() => setChangesTarget(null)} className={SECONDARY_ACTION}>
                        {tw.cancel ?? 'Avbryt'}
                    </button>
                </div>
            </ActionDialog>
        </section>
    );
}
