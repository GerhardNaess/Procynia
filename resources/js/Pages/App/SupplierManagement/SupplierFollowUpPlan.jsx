import { useState } from 'react';
import { followUpEntryDate, followUpEntryText, followUpView } from './assuranceFollowUp';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * «Neste kontroller» (docs/supplier-assurance-v2-plan.md §14, §22.1): what falls due for this
 * supplier, nearest first and overdue before anything else, as SupplierFollowUpPlan computed it. Each
 * line says what — a new control, a document to renew, a temporary acceptance, documentation, the
 * next supplier assessment — and for which requirement or document; a requirement's line links to its
 * row. Requirements controlled on change have no date and are listed apart. Read-only: nothing
 * happens here, or anywhere, when a date passes. Not shown when there is nothing to list.
 */
export default function SupplierFollowUpPlan({ plan, formatDate, tr }) {
    const f = tr.follow_up ?? {};
    const [expanded, setExpanded] = useState(false);
    const view = followUpView(plan, expanded);

    if (! view.visible) {
        return null;
    }

    return (
        <section className={CARD} aria-labelledby="supplier-follow-up-heading" data-testid="supplier-follow-up">
            <h2 id="supplier-follow-up-heading" className="text-xl font-semibold text-slate-950">{f.heading ?? 'Neste kontroller'}</h2>
            <p className="mt-1 text-base text-slate-600">{f.intro}</p>

            {view.items.length > 0 && (
                <ul className="mt-4 divide-y divide-slate-100" data-testid="follow-up-entries">
                    {view.items.map((entry, index) => {
                        const text = followUpEntryText(entry, tr);

                        return (
                            <li key={`${entry.kind}-${entry.requirement?.id ?? ''}-${entry.document?.id ?? ''}-${index}`} className="flex flex-col gap-1 py-2 sm:flex-row sm:items-baseline sm:gap-4" data-testid="follow-up-entry" data-kind={entry.kind} data-overdue={entry.overdue ? '1' : '0'}>
                                <span className={`shrink-0 text-base sm:w-48 ${entry.overdue ? 'font-semibold text-amber-800' : 'text-slate-700'}`}>
                                    {followUpEntryDate(entry, tr, formatDate)}
                                    {entry.overdue && <> · {f.overdue ?? 'Forfalt'}</>}
                                </span>
                                <span className="min-w-0 break-words text-base text-slate-900">
                                    {entry.requirement
                                        ? <a href={`#control-requirement-${entry.requirement.id}`} className="text-slate-900 underline decoration-slate-300 underline-offset-2 hover:text-violet-800">{text}</a>
                                        : text}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            )}

            {view.hasMore && (
                <button
                    type="button"
                    className="mt-2 text-base font-semibold text-violet-700 hover:text-violet-900"
                    aria-expanded={expanded}
                    onClick={() => setExpanded((value) => ! value)}
                >
                    {expanded ? (f.show_fewer ?? 'Vis færre') : (f.show_all ?? 'Vis alle :count').replace(':count', String(view.count))}
                </button>
            )}

            {view.onChange.length > 0 && (
                <p className="mt-3 break-words text-base text-slate-700" data-testid="follow-up-on-change">
                    {(f.on_change ?? 'Kontrolleres ved endring: :titles').replace(':titles', view.onChange.map((row) => row.title).join(', '))}
                </p>
            )}
        </section>
    );
}
