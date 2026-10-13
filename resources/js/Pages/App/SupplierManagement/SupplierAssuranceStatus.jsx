import StatusBadge from '../../../Components/App/StatusBadge';
import { formatLongDate } from '../Improvements/improvementStatus';
import {
    DECISION_TONES,
    applicableText,
    decisionByline,
    decisionLabel,
    historyCount,
    openMandatory,
    snapshotSummary,
    stateNow,
    summaryGroups,
} from './assuranceStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const HINT = 'text-base text-slate-600';

/**
 * Kravstatus at the top of Krav og kvalifikasjoner: the state the requirements that apply now are
 * in, as the server computed it — how many apply, the groups, and the mandatory requirements not
 * accepted. A fact about the requirements, never an approval of the supplier. No percentage, no
 * «14/18», no progress bar. Nothing when no requirement applies; the requirement list says so.
 */
export default function SupplierRequirementStatus({ state, tr }) {
    const a = tr.assurance ?? {};
    const now = stateNow(state, tr);

    if (! now) {
        return null;
    }

    const groups = summaryGroups(state.counts ?? {}, tr);
    const open = openMandatory(state.open_mandatory ?? [], tr);

    return (
        <section className={CARD} aria-labelledby="supplier-requirement-status-heading" data-testid="supplier-requirement-status">
            <div className="flex flex-wrap items-center gap-3">
                <h2 id="supplier-requirement-status-heading" className="text-xl font-semibold text-slate-950">{a.heading ?? 'Kravstatus'}</h2>
                <span data-testid="requirement-status-label"><StatusBadge tone={now.tone}>{now.label}</StatusBadge></span>
            </div>
            <p className={`mt-1 ${HINT}`}>{a.intro}</p>
            <p className="mt-3 break-words text-base text-slate-800" data-testid="requirement-status-summary">
                <span className="font-semibold text-slate-900">{applicableText(state.applicable_count, tr)}</span>
                {groups.length > 0 && ` · ${groups.map((group) => group.text).join(' · ')}`}
            </p>
            {open && (
                <div className="mt-2" data-testid="requirement-status-open-mandatory">
                    <p className="break-words text-base font-semibold text-slate-900">{open.heading}</p>
                    <ul className="list-disc pl-5 text-base text-slate-800">
                        {open.items.map((item) => (
                            <li key={item.id} className="break-words">
                                <a href={`#control-requirement-${item.id}`} className="text-violet-700 hover:text-violet-900">{item.text}</a>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}

/** One earlier decision: what, when, by whom, why — and the state it was taken on, as it was. */
function DecisionEntry({ entry, locale, tr }) {
    const a = tr.assurance ?? {};
    const snapshot = snapshotSummary(entry.snapshot, tr);

    return (
        <li className="min-w-0 border-l-2 border-slate-200 pl-3" data-testid="decision-history-entry">
            <div className="flex flex-wrap items-center gap-2">
                <StatusBadge tone={DECISION_TONES[entry.decision] ?? 'slate'}>{decisionLabel(entry.decision, tr)}</StatusBadge>
                <span className="text-base text-slate-600">{decisionByline(entry, tr, locale)}</span>
            </div>
            <p className="mt-1 whitespace-pre-line break-words text-base text-slate-900">{tr.control?.reason_label ?? 'Begrunnelse'}: {entry.rationale}</p>
            {entry.follow_up_note && (
                <p className="mt-1 whitespace-pre-line break-words text-base text-slate-900">{a.follow_up ?? 'Følges opp'}: {entry.follow_up_note}</p>
            )}
            <div className="mt-2 rounded-lg border border-slate-200 bg-white p-3" data-testid="decision-snapshot">
                <p className="text-base font-semibold text-slate-900">{a.snapshot_heading ?? 'Tilstand da beslutningen ble tatt'}</p>
                {snapshot.state && <p className="break-words text-base text-slate-800">{snapshot.state}</p>}
                <p className="break-words text-base text-slate-700">
                    {[snapshot.applicable, ...snapshot.groups.map((group) => group.text)].join(' · ')}
                </p>
                {snapshot.unmet.length > 0 ? (
                    <>
                        <p className="mt-1 text-base text-slate-700">{a.snapshot_unmet ?? 'Obligatoriske og viktige krav som ikke var dokumentert:'}</p>
                        <ul className="list-disc pl-5 text-base text-slate-800">
                            {snapshot.unmet.map((row) => <li key={row.id} className="break-words">{row.text}</li>)}
                        </ul>
                    </>
                ) : (
                    <p className="mt-1 text-base text-slate-700">{a.snapshot_none_unmet ?? 'Alle obligatoriske og viktige krav var dokumentert.'}</p>
                )}
            </div>
            {entry.recorded_at && <p className="mt-1 text-base text-slate-600">{(a.registered_at ?? 'Registrert :date').replace(':date', formatLongDate(entry.recorded_at, locale))}</p>}
        </li>
    );
}

/**
 * Tidligere kontrollbeslutninger, under Historikk: the decisions registered before Registrer
 * beslutning was retired, newest first, read-only. History, never the state now — Kravstatus is on
 * Krav og kvalifikasjoner. Nothing for a supplier that never had one.
 */
export function SupplierDecisionHistory({ history = [], locale = 'no', tr }) {
    const a = tr.assurance ?? {};

    if (history.length === 0) {
        return null;
    }

    return (
        <section className={CARD} aria-labelledby="supplier-decision-history-heading" data-testid="decision-history">
            <h2 id="supplier-decision-history-heading" className="text-xl font-semibold text-slate-950">
                {a.history_heading ?? 'Tidligere kontrollbeslutninger'} ({historyCount(history.length, tr)})
            </h2>
            <p className={`mt-1 ${HINT}`}>{a.history_intro ?? 'Samlede kontrollbeslutninger registreres ikke lenger. Beslutningene her ble registrert før det, og står som historikk.'}</p>
            <ol className="mt-4 space-y-4">
                {history.map((entry) => <DecisionEntry key={entry.id} entry={entry} locale={locale} tr={tr} />)}
            </ol>
        </section>
    );
}
