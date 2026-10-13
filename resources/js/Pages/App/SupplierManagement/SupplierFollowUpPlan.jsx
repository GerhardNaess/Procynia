import { followUpEntryDate, followUpEntryText, followUpView } from './assuranceFollowUp';

/**
 * «Neste kontroller» (docs/supplier-assurance-v2-plan.md §14, §22.1): what falls due for this
 * supplier, nearest first and overdue before anything else, as SupplierFollowUpPlan computed it. Each
 * line says what — a new control, a document to renew, a temporary acceptance, documentation, the
 * next supplier assessment — and for which requirement or document; a requirement's line links to its
 * row. Requirements controlled on change have no date and are listed apart. Read-only: nothing
 * happens here, or anywhere, when a date passes.
 *
 * The whole list, opened from «Neste kontroll» among the key facts on Oversikt, which shows the
 * nearest entry. Nothing when there is nothing to list.
 */
export default function SupplierFollowUpPlan({ plan, formatDate, tr }) {
    const f = tr.follow_up ?? {};
    const view = followUpView(plan, true);

    if (! view.visible) {
        return null;
    }

    return (
        <div data-testid="supplier-follow-up">
            {view.items.length > 0 && (
                <ul className="divide-y divide-slate-100" data-testid="follow-up-entries">
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

            {view.onChange.length > 0 && (
                <p className="mt-2 break-words text-base text-slate-700" data-testid="follow-up-on-change">
                    {(f.on_change ?? 'Kontrolleres ved endring: :titles').replace(':titles', view.onChange.map((row) => row.title).join(', '))}
                </p>
            )}
        </div>
    );
}
