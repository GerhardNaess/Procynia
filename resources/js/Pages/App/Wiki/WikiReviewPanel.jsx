import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import ActionDialog from '../../../Components/App/ActionDialog';
import { isStaleStateRefusal } from './wikiReviewRefusal';
import InfoHint from '../../../Components/App/InfoHint';
import {
    DISCLOSURE_INLINE,
    PRIMARY_ACTION,
    SECONDARY_ACTION,
    WARNING_ACTION,
} from '../../../Support/actionStyles';

/** One shape for the message, wherever the action was started from. */
function ActionError({ message }) {
    if (! message) {
        return null;
    }

    return (
        <p
            role="alert"
            data-testid="wiki-review-action-error"
            className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"
        >
            {message}
        </p>
    );
}

/** When a review decision was made, in the same short form the rest of the app uses. */
function formatReviewDate(value) {
    if (! value) {
        return null;
    }

    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime())
        ? null
        : new Intl.DateTimeFormat('nb-NO', { day: '2-digit', month: 'short', year: 'numeric' }).format(parsed);
}

const FALLBACK_ERROR = 'Handlingen kunne ikke fullføres. Last siden på nytt og prøv igjen.';

/** The first message Laravel sent back, whatever field it was attached to. */
function firstError(errors) {
    const values = Object.values(errors ?? {});

    return values.length > 0 ? String(values[0]) : null;
}

/**
 * What went wrong, said in terms of the work rather than the status code.
 *
 * Each of these is a real outcome of the review rules: the page moved on while the dialog was open,
 * somebody else took the assignment, the session expired. The person can act on every one of them,
 * but only if they are told which happened.
 */
function httpErrorMessage(status, tw) {
    switch (status) {
        case 403:
            return tw.review_error_forbidden
                ?? 'Du har ikke tilgang til å gjøre dette. Kontakt System Owner hvis dette er feil.';
        case 409:
            return tw.review_error_conflict
                ?? 'Noen andre har allerede handlet på denne versjonen. Siden er oppdatert med gjeldende status.';
        case 419:
            return tw.review_error_expired
                ?? 'Økten din er utløpt. Last siden på nytt og logg inn igjen.';
        case 422:
            return tw.review_error_state
                ?? 'Siden er ikke lenger i en tilstand som tillater dette. Siden er oppdatert med gjeldende status.';
        default:
            return tw.review_action_failed ?? FALLBACK_ERROR;
    }
}

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

/**
 * Why manual editing is unavailable, in words the page owner can act on. `not_authorized` returns
 * null on purpose: someone who may not edit should simply not see the action, while someone who
 * may edit but currently cannot deserves to know why rather than find the button missing.
 */
export function articleEditUnavailableText(reason, tw) {
    // Deliberately no AI-related reason: manual editing does not use AI, so whether it is enabled
    // has nothing to say about whether the page owner may write.
    if (reason === 'no_editable_version') {
        return tw.article_edit_unavailable_no_version
            ?? 'Denne versjonen kan ikke redigeres manuelt.';
    }

    return null;
}

/**
 * Where this page stands, and the one thing that has to happen next.
 *
 * Everything here comes from EnterpriseWikiPublicationStatusService, which reads the same gates
 * submit() and approve() enforce. Nothing is recomputed in the client — a second opinion about
 * whether a page can be published is exactly what this block exists to prevent.
 *
 * Claims appear under "Kvalitetsstatus" and never among the blockers: the domain does not gate
 * publication on them, so presenting them as a blocker would invent a rule.
 */
function PublicationBlock({ publication, tw }) {
    if (! publication) {
        return null;
    }

    const blockers = publication.blocking_reasons ?? [];

    return (
        <div className="space-y-3 border-b border-slate-100 pb-4" data-testid="wiki-publication-block">
            {/* The heading only. Where the page stands is stated once, beside the page title;
                repeating it here said the same word twice on one screen and invited the two to
                disagree. This card is for what happens next, not for what the page is. */}
            <span className="text-sm font-semibold uppercase tracking-[0.12em] text-slate-500">
                {tw.publication_heading ?? 'Publisering'}
            </span>

            <dl className="grid gap-x-8 gap-y-2 sm:grid-cols-2">
                {publication.claims_total > 0 && (
                    <div>
                        <dt className="text-sm text-slate-500">{tw.publication_quality_heading ?? 'Kvalitetsstatus'}</dt>
                        <dd className="text-base text-slate-900" data-testid="wiki-publication-claims">
                            {(tw.publication_claims_quality ?? ':approved av :total påstander godkjent')
                                .replace(':approved', publication.claims_approved ?? 0)
                                .replace(':total', publication.claims_total ?? 0)}
                        </dd>
                    </div>
                )}

                <div>
                    <dt className="text-sm text-slate-500">{tw.publication_next_label ?? 'Neste steg'}</dt>
                    <dd className="text-base text-slate-900" data-testid="wiki-publication-next-step">
                        {publication.next_step_label}
                    </dd>
                </div>
            </dl>

            {blockers.length > 0 && (
                <div data-testid="wiki-publication-blockers">
                    <p className="text-sm text-slate-500">{tw.publication_blocking_heading ?? 'Gjenstår'}</p>
                    <ul className="mt-1 space-y-1">
                        {blockers.map((reason) => (
                            <li key={reason} className="text-base text-amber-800">• {reason}</li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

/**
 * Who was asked to quality assure this version, and how far that work has got.
 *
 * Kept visibly apart from the publication block above it, because the two answer different
 * questions and conflating them is the mistake the whole role model exists to prevent: QA
 * contributes to quality, the Wiki approver decides whether the page is published. An unassigned
 * page is an ordinary page — QA is support, never a step the page is waiting on.
 *
 * Progress is the claims themselves. A version with no claims has nothing to quality assure, and
 * says so rather than offering work that does not exist.
 */
function QaAssignmentBlock({ qaAssignment, tw, busy, triggerRef, onOpen }) {
    if (! qaAssignment) {
        return null;
    }

    const claims = qaAssignment.claims ?? { total: 0, approved: 0, rejected: 0, pending: 0 };
    const assignee = qaAssignment.assignee;
    const hasClaims = (claims.total ?? 0) > 0;
    // Quality assurance is work on claims. A version that has none offers nobody anything to do,
    // so asking somebody to check it would be an empty request — the count the backend already
    // sends is what decides, rather than a second rule invented here.
    const canAssign = hasClaims
        && qaAssignment.can_assign
        && (qaAssignment.eligible_qa_users ?? []).length > 0;

    // Nothing to say: no claims to check and nobody asked to check them.
    if (! hasClaims && ! assignee && ! qaAssignment.can_assign) {
        return null;
    }

    return (
        <div className="space-y-2 border-b border-slate-100 pb-4" data-testid="wiki-qa-assignment">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <span className="text-sm font-semibold uppercase tracking-[0.12em] text-slate-500">
                    {tw.qa_heading ?? 'Kvalitetssikring'}
                </span>
                {/* Who is on it — but "Ikke tildelt" is only worth saying when there is something
                    to be tildelt. With no claims the next line already says why nobody is. */}
                {(hasClaims || assignee) && (
                    <span className="text-base text-slate-900" data-testid="wiki-qa-assignee">
                        {assignee
                            ? assignee.name
                            : (tw.qa_unassigned ?? 'Ikke tildelt')}
                    </span>
                )}
            </div>

            {/* The action belongs with the thing it acts on, so it sits beside the claim progress
                rather than pushed to the far edge of the card away from it. */}
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <p className="text-base text-slate-600" data-testid="wiki-qa-progress">
                    {! hasClaims
                        ? (tw.qa_no_claims ?? 'Ingen påstander å kvalitetssikre')
                        : (tw.qa_claims_progress ?? ':approved av :total påstander godkjent')
                            .replace(':approved', claims.approved ?? 0)
                            .replace(':total', claims.total ?? 0)}
                    {claims.rejected > 0 && (
                        <span className="ml-2 text-amber-700">
                            {(tw.qa_claims_rejected ?? ':count avvist').replace(':count', claims.rejected)}
                        </span>
                    )}
                </p>

                {canAssign && (
                    <button
                        ref={triggerRef}
                        type="button"
                        disabled={busy}
                        onClick={onOpen}
                        data-testid="wiki-qa-assign"
                        className="inline-flex min-h-9 items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {assignee
                            ? (tw.qa_change_button ?? 'Endre kvalitetssikrer')
                            : (tw.qa_assign_button ?? 'Tildel kvalitetssikrer')}
                    </button>
                )}
            </div>
        </div>
    );
}

export default function WikiReviewPanel({
    page,
    currentVersion,
    reviewAssignment,
    publication = null,
    qaAssignment = null,
    tw = {},
    isSystemOwner = false,
    currentUserId = null,
}) {
    const [processing, setProcessing] = useState(null);
    const [actionError, setActionError] = useState(null);
    const [isSubmitOpen, setIsSubmitOpen] = useState(false);
    const [isQaOpen, setIsQaOpen] = useState(false);
    const [reviewerId, setReviewerId] = useState('');
    const [qaUserId, setQaUserId] = useState('');
    const [changesTarget, setChangesTarget] = useState(null);
    const [reason, setReason] = useState('');
    const [showHistory, setShowHistory] = useState(false);

    const submitTriggerRef = useRef(null);
    const reviewerSelectRef = useRef(null);
    const qaTriggerRef = useRef(null);
    const qaSelectRef = useRef(null);
    const reasonRef = useRef(null);

    if (! reviewAssignment) {
        return null;
    }

    const changes = reviewAssignment.changes_requested ?? { is_returned: false, latest: null, history: [] };
    const eligibleReviewers = reviewAssignment.eligible_reviewers ?? [];

    const isReturned = page.status === 'rejected';
    const isInReview = page.status === 'pending_review';
    // The two review decisions have different conditions and always did. Publishing waits for the
    // document owners and refuses a version that changed after it was handed over; sending the page
    // back waits for neither, and is the way out of both. Gating them on one flag hid the only
    // action a blocked reviewer still had.
    const canSendBack = isInReview && reviewAssignment.can_send_back === true;
    // A System Owner can publish a draft without sending it anywhere first. Review stays on offer
    // beside it as the voluntary thing it is, rather than as the only way forward.
    const canPublishDraft = page.status === 'draft' && reviewAssignment.can_approve_final === true;
    // Being able to decide a page is not the same as having been asked to. A System Owner can
    // finish any page in their customer's Wiki, and telling them it is waiting on them would be
    // false — somebody else was named, and is presumably working on it.
    const isAssignedReviewer = currentUserId !== null
        && reviewAssignment.reviewer?.id === currentUserId;
    const isMyReview = isInReview && isAssignedReviewer && (canSendBack || reviewAssignment.can_approve_final);
    const actsAsSystemOwner = isInReview && ! isAssignedReviewer
        && (canSendBack || reviewAssignment.can_approve_final);
    const canSubmit = reviewAssignment.can_submit && page.status === 'draft';
    const canReopen = reviewAssignment.can_submit && isReturned;
    // Editing lives with the article text it changes, not here: this card is about where the page
    // stands, and a writing action in among the review decisions read as one of them.
    // A published page with nothing outstanding used to render nothing at all, which left the most
    // important fact about it — that it is published — the one thing the page never said.
    const showsAnything = canSubmit || canReopen || isInReview || isReturned || canPublishDraft
        || publication !== null;

    if (! showsAnything) {
        return null;
    }

    const run = (key, url, data = {}) => {
        if (processing) return;
        setProcessing(key);
        setActionError(null);

        // `processing` blocks a second click, and only onFinish clears it — so if the dispatch
        // itself throws before Inertia's promise chain exists, onFinish never runs and every
        // later click on the panel is swallowed in silence. The person is left with an enabled
        // button that does nothing and no way back except reloading the page.
        try {
            router.patch(url, data, {
                preserveScroll: true,
                onFinish: () => setProcessing(null),
                onSuccess: () => {
                    setIsSubmitOpen(false);
                    setIsQaOpen(false);
                    setChangesTarget(null);
                    setReason('');
                },
                // A refused handover used to leave the dialog sitting there saying nothing, which
                // reads exactly like a click that never happened. Validation errors and aborts
                // arrive through two different channels, so both are caught and both end up in the
                // same sentence.
                onError: (errors) => setActionError(firstError(errors) ?? (tw.review_action_failed ?? FALLBACK_ERROR)),
                // Returning false suppresses Inertia's raw error overlay: the person gets the reason
                // in the dialog they are standing in, in language about the work rather than the
                // protocol.
                onHttpException: (response) => {
                    const status = response?.status;
                    const isStale = isStaleStateRefusal(status, response?.data);

                    setActionError(isStale
                        ? httpErrorMessage(status, tw)
                        : (firstError(response?.data?.errors) ?? httpErrorMessage(status, tw)));

                    if (! isStale) {
                        return false;
                    }

                    // The page moved on while this view of it stood still — somebody else acted, or
                    // the tab has been open since before the change. Leaving the dialog up would
                    // keep offering an action the server will refuse every time, against a page
                    // that no longer looks the way the screen says it does.
                    //
                    // So the page is re-read from the server rather than patched locally: the panel
                    // then shows what is actually true, and the message explains why the action
                    // went away. Nothing is assumed about what the new state is.
                    setIsSubmitOpen(false);
                    setIsQaOpen(false);
                    setChangesTarget(null);
                    setReason('');
                    router.reload({ preserveScroll: true });

                    return false;
                },
            });
        } catch (error) {
            setProcessing(null);
            setActionError(tw.review_action_failed ?? FALLBACK_ERROR);
        }
    };

    const submit = () => run('submit', `/app/wiki/${page.slug}/submit`, { reviewer_user_id: Number(reviewerId) });
    const reopen = () => run('reopen', `/app/wiki/${page.slug}/submit`);
    const assignQa = () => run('qa', `/app/wiki/${page.slug}/qa-assignment`, { qa_user_id: Number(qaUserId) });
    const approve = () => run('approve', `/app/wiki/${page.slug}/approve`);
    const requestChanges = () => {
        const trimmed = reason.trim();

        if (trimmed.length < MIN_REASON) return;

        run('reject', `/app/wiki/${page.slug}/reject`, { reason: trimmed });
    };

    const busy = processing !== null;
    const reasonTooShort = reason.trim().length < MIN_REASON;

    return (
        <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_4px_14px_rgba(15,23,42,0.04)]">
            <PublicationBlock publication={publication} tw={tw} />

            <QaAssignmentBlock
                qaAssignment={qaAssignment}
                tw={tw}
                busy={busy}
                triggerRef={qaTriggerRef}
                onOpen={() => {
                    setQaUserId(qaAssignment?.assignee?.id ? String(qaAssignment.assignee.id) : '');
                    setIsQaOpen(true);
                }}
            />

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
                        {/* When it was said. A page that went round twice reads very differently
                            from one sent back this morning, and the owner cannot tell without it. */}
                        {formatReviewDate(changes.latest.created_at) && (
                            <>
                                {' · '}
                                {formatReviewDate(changes.latest.created_at)}
                            </>
                        )}
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

            {/* Whose move it is, said before the actions rather than left to be inferred from which
                buttons happen to be present. */}
            {isMyReview && (
                <div
                    data-testid="wiki-review-turn"
                    className="rounded-xl border border-violet-200 bg-violet-50 px-4 py-3"
                >
                    <p className="text-base font-semibold text-violet-900">
                        {tw.review_your_turn ?? 'Denne siden venter på din gjennomgang.'}
                    </p>
                    {reviewAssignment.reviewer?.name && (
                        <p className="mt-1 text-sm text-violet-800">
                            {tw.review_reviewer ?? 'Kontrollør:'} {reviewAssignment.reviewer.name}
                        </p>
                    )}
                </div>
            )}

            {/* Somebody else was asked; this reader can finish it anyway. Said plainly, so the
                action below is understood as stepping in rather than as their own queue. */}
            {actsAsSystemOwner && (
                <div
                    data-testid="wiki-review-standin"
                    className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3"
                >
                    <p className="text-base text-slate-800">
                        {reviewAssignment.reviewer?.name
                            ? `${tw.review_with_reviewer ?? 'Siden er til gjennomgang hos'} ${reviewAssignment.reviewer.name}.`
                            : (tw.review_in_review ?? 'Siden er til gjennomgang.')}
                    </p>
                    {reviewAssignment.can_approve_final && (
                        <p className="mt-1 text-sm text-slate-600">
                            {tw.review_system_owner_may_finish
                                ?? 'Som System Owner kan du ferdigstille siden uten å være tildelt kontrollør.'}
                        </p>
                    )}
                </div>
            )}

            {! isSubmitOpen && ! isQaOpen && changesTarget === null && <ActionError message={actionError} />}

            {/* Actions. */}
            <div className="flex flex-wrap items-center gap-2">
                {canSubmit && (
                    <>
                        <button
                            ref={submitTriggerRef}
                            type="button"
                            disabled={busy || eligibleReviewers.length === 0}
                            onClick={() => {
                                setActionError(null);
                                setReviewerId(eligibleReviewers.length === 1 ? String(eligibleReviewers[0].id) : '');
                                setIsSubmitOpen(true);
                            }}
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

                {/* Naming the permission and where it lives, because the old wording stated the
                    problem without saying it was solvable — and the permission it refers to is one
                    row below "Godkjenne Wiki-påstander", which is the one people grant by mistake.

                    An empty list has two causes and they need different sentences. Telling a lone
                    approver that "nobody can approve" sends them to the access screen to grant a
                    role they already hold; what they actually need is a second person. */}
                {canSubmit && eligibleReviewers.length === 0 && (
                    <p className="text-base leading-6 text-slate-600">
                        {reviewAssignment.actor_can_approve_wiki_pages
                            ? (tw.review_only_approver_is_you
                                ?? 'Du er den eneste som kan godkjenne Wiki-sider. En side må kontrolleres av en annen enn den som sender den inn, så gi minst én annen bruker rollen «Wiki-godkjenner» under Tilganger.')
                            : (tw.review_no_eligible_reviewers
                                ?? 'Ingen andre brukere kan godkjenne Wiki-siden. Gi minst én annen bruker rollen «Wiki-godkjenner» under Tilganger.')}
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

                {reviewAssignment.can_approve_final && (
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
                )}

                {/* Its own condition, because the backend has its own. A reviewer waiting on a
                    document owner, or holding a version that moved under them, can still send the
                    page back — and until now that was the one thing the panel never offered. */}
                {canSendBack && (
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => { setActionError(null); setChangesTarget('page'); setReason(''); }}
                        className={WARNING_ACTION}
                    >
                        {tw.review_send_back ?? 'Send tilbake'}
                    </button>
                )}
            </div>


            {/* Choosing who takes over. Never implicit, even when only one person is possible. */}
            <ActionDialog
                isOpen={isQaOpen}
                onClose={() => setIsQaOpen(false)}
                closeDisabled={busy}
                titleId="wiki-qa-title"
                initialFocusRef={qaSelectRef}
                returnFocusRef={qaTriggerRef}
            >
                <h2 id="wiki-qa-title" className="text-xl font-semibold tracking-tight text-slate-950">
                    {qaAssignment?.assignee
                        ? (tw.qa_change_button ?? 'Endre kvalitetssikrer')
                        : (tw.qa_assign_button ?? 'Tildel kvalitetssikrer')}
                </h2>
                <p className="mt-2 text-base leading-6 text-slate-600">
                    {tw.qa_assign_description
                        ?? 'Velg hvem som skal kvalitetssikre påstandene i denne versjonen. Vedkommende får beskjed. Kvalitetssikring er støtte i arbeidet og kreves ikke for å publisere siden.'}
                </p>

                <label className="mt-4 block space-y-1.5" htmlFor="wiki-qa-user">
                    <span className="text-base font-medium text-slate-800">{tw.qa_user_label ?? 'Kvalitetssikrer'}</span>
                    <select
                        ref={qaSelectRef}
                        id="wiki-qa-user"
                        value={qaUserId}
                        onChange={(event) => setQaUserId(event.target.value)}
                        className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-base text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                    >
                        <option value="">{tw.qa_choose_user ?? 'Velg kvalitetssikrer'}</option>
                        {(qaAssignment?.eligible_qa_users ?? []).map((candidate) => (
                            <option key={candidate.id} value={candidate.id}>{candidate.name}</option>
                        ))}
                    </select>
                </label>

                <div className="mt-6 flex flex-wrap gap-3">
                    <button type="button" disabled={busy || qaUserId === ''} onClick={assignQa} className={PRIMARY_ACTION}>
                        {qaAssignment?.assignee
                            ? (tw.qa_update_button ?? 'Oppdater')
                            : (tw.qa_send_button ?? 'Tildel')}
                    </button>
                    <button type="button" disabled={busy} onClick={() => setIsQaOpen(false)} className={SECONDARY_ACTION}>
                        {tw.cancel ?? 'Avbryt'}
                    </button>
                </div>
            </ActionDialog>

            <ActionDialog
                isOpen={isSubmitOpen}
                onClose={() => { setIsSubmitOpen(false); setActionError(null); }}
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

                {actionError && (
                    <div className="mt-4">
                        <ActionError message={actionError} />
                    </div>
                )}

                <div className="mt-6 flex flex-wrap gap-3">
                    <button type="button" disabled={busy || reviewerId === ''} onClick={submit} className={PRIMARY_ACTION}>
                        {processing === 'submit'
                            ? (tw.review_sending ?? 'Sender …')
                            : (tw.submit_button ?? 'Send til gjennomgang')}
                    </button>
                    <button type="button" disabled={busy} onClick={() => { setIsSubmitOpen(false); setActionError(null); }} className={SECONDARY_ACTION}>
                        {tw.cancel ?? 'Avbryt'}
                    </button>
                </div>
            </ActionDialog>

            {/* Sending the page back. One caller now: the reviewer returning the whole article.
                The comment is what the owner works from, so it is required. */}
            <ActionDialog
                isOpen={changesTarget !== null}
                onClose={() => { setChangesTarget(null); setActionError(null); }}
                closeDisabled={busy}
                titleId="wiki-changes-title"
                initialFocusRef={reasonRef}
            >
                <h2 id="wiki-changes-title" className="text-xl font-semibold tracking-tight text-slate-950">
                    {tw.review_send_back ?? 'Send tilbake'}
                </h2>
                <p className="mt-2 text-base leading-6 text-slate-600">
                    {tw.review_request_changes_page ?? 'Siden sendes tilbake til sideeier. Den publiserte versjonen berøres ikke.'}
                </p>

                <label className="mt-4 block space-y-1.5" htmlFor="wiki-change-reason">
                    <span className="text-base font-medium text-slate-800">
                        {tw.review_comment ?? 'Kommentar'}
                    </span>
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
                        {tw.review_comment_help ?? 'Forklar hva sideeier må endre før siden kan sendes inn på nytt.'}
                        {' '}{reason.trim().length}/{MAX_REASON}
                    </span>
                </label>

                {actionError && (
                    <div className="mt-4">
                        <ActionError message={actionError} />
                    </div>
                )}

                <div className="mt-6 flex flex-wrap gap-3">
                    <button type="button" disabled={busy || reasonTooShort} onClick={requestChanges} className={WARNING_ACTION}>
                        {tw.review_send_back ?? 'Send tilbake'}
                    </button>
                    <button type="button" disabled={busy} onClick={() => { setChangesTarget(null); setActionError(null); }} className={SECONDARY_ACTION}>
                        {tw.cancel ?? 'Avbryt'}
                    </button>
                </div>
            </ActionDialog>
        </section>
    );
}
