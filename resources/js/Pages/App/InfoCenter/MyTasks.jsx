import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { filterTaskGroups, moduleFilterOptions, moduleLabel, supplierReasonText, taskReasonText, visibleTaskGroups } from './myTaskLabels';

function formatDay(value, locale) {
    if (!value) {
        return null;
    }

    return new Intl.DateTimeFormat(locale, { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value));
}

/**
 * One supplier the person is intern ansvarlig for, with every reason it needs follow-up.
 *
 * One card per supplier, never one per signal. A reason the person may not act on says so where it
 * stands, and the card says it once in words — the task is still theirs to see to, and the page does
 * not pretend they can do what their role does not allow.
 */
export function SupplierTaskCard({ task, locale, t = {}, categories = {}, moduleLabels = {} }) {
    const reasons = Array.isArray(task.reasons) ? task.reasons : [];
    const dueOn = formatDay(task.due_on, locale);

    return (
        <article
            className="rounded-[22px] border border-slate-200 bg-white px-4 py-4 shadow-[0_6px_16px_rgba(15,23,42,0.03)]"
            data-testid="info-center-supplier-task"
        >
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0 space-y-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="inline-flex items-center rounded-full bg-teal-100 px-2.5 py-1 text-xs font-semibold text-teal-800 ring-1 ring-inset ring-teal-200">
                            {moduleLabel('supplier', moduleLabels)}
                        </span>
                        <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-inset ring-slate-200">
                            {t.assigned_to_you ?? 'Tildelt deg'}
                        </span>
                        {task.can_act ? null : (
                            <span
                                className="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900 ring-1 ring-inset ring-amber-200"
                                data-testid="info-center-supplier-task-missing-permission"
                            >
                                {t.missing_permission ?? 'Mangler rettighet'}
                            </span>
                        )}
                    </div>

                    <Link
                        href={task.action_url ?? '#'}
                        className="block text-lg font-semibold tracking-tight text-slate-950 transition hover:text-violet-700"
                    >
                        {t.supplier_heading ?? 'Følg opp leverandør'}: {task.title}
                    </Link>

                    <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                            {t.supplier_reasons ?? 'Krever oppfølging'}
                        </div>
                        <ul className="mt-2 space-y-1.5">
                            {reasons.map((reason, index) => (
                                <li
                                    key={`${reason.key}-${index}`}
                                    className="flex flex-wrap items-baseline gap-x-2 text-base text-slate-900"
                                    data-testid="info-center-supplier-task-reason"
                                >
                                    <span className={reason.overdue ? 'font-semibold text-rose-700' : 'font-medium'}>
                                        {supplierReasonText(reason, categories, t)}
                                    </span>
                                    {reason.due_on ? (
                                        <span className="text-sm text-slate-600">{formatDay(reason.due_on, locale)}</span>
                                    ) : null}
                                    {reason.can_act ? null : (
                                        <span className="text-sm font-medium text-amber-900">
                                            {t.missing_permission ?? 'Mangler rettighet'}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>

                    {task.can_act ? null : (
                        <p className="max-w-3xl text-sm leading-6 text-slate-700">
                            {t.missing_permission_text ?? 'Du kan se leverandøren, men mangler rettigheten som trengs for minst ett av punktene.'}
                        </p>
                    )}
                </div>

                <div className="flex shrink-0 flex-col items-start gap-2 lg:items-end">
                    <div className="text-sm text-slate-600">
                        {t.due ?? 'Frist'}: <span className="font-semibold text-slate-900">{dueOn ?? (t.no_due_date ?? 'Ingen frist')}</span>
                    </div>
                    <Link
                        href={task.action_url ?? '#'}
                        className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-300 hover:text-slate-950"
                    >
                        {t.supplier_open ?? 'Åpne leverandør'}
                    </Link>
                </div>
            </div>
        </article>
    );
}

/**
 * One task from a styringsmodul — Risiko, Avvik og forbedringer, Etterlevelse og revisjon, Kvalitet,
 * Mål og KPI. One shape for all five: what it is, what it is about, why it needs follow-up, by when,
 * and whether the person can act. The words are the modules' own (info_center_page.my_tasks).
 */
export function GovernanceTaskCard({ task, locale, t = {} }) {
    const reasons = Array.isArray(task.reasons) ? task.reasons : [];
    const dueOn = formatDay(task.due_on, locale);
    const typeLabel = t.types?.[task.type] ?? moduleLabel(task.module, t.modules);
    const showContext = task.subject_title && task.subject_title !== task.title;

    return (
        <article
            className="rounded-[22px] border border-slate-200 bg-white px-4 py-4 shadow-[0_6px_16px_rgba(15,23,42,0.03)]"
            data-testid="info-center-governance-task"
            data-module={task.module}
        >
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0 space-y-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="inline-flex items-center rounded-full bg-sky-100 px-2.5 py-1 text-xs font-semibold text-sky-900 ring-1 ring-inset ring-sky-200">
                            {moduleLabel(task.module, t.modules)}
                        </span>
                        <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-inset ring-slate-200">
                            {t.assigned_to_you ?? 'Tildelt deg'}
                        </span>
                        {task.can_act ? null : (
                            <span
                                className="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900 ring-1 ring-inset ring-amber-200"
                                data-testid="info-center-task-missing-permission"
                            >
                                {t.missing_permission ?? 'Mangler rettighet'}
                            </span>
                        )}
                    </div>

                    <Link
                        href={task.action_url ?? '#'}
                        className="block text-lg font-semibold tracking-tight text-slate-950 transition hover:text-violet-700"
                    >
                        {typeLabel}: {task.title}
                    </Link>

                    {showContext ? (
                        <div className="text-base text-slate-600">
                            {t.context?.[task.module] ?? moduleLabel(task.module, t.modules)}: <span className="font-medium text-slate-800">{task.subject_title}</span>
                        </div>
                    ) : null}

                    {reasons.length ? (
                        <ul className="space-y-1.5" aria-label={t.reasons_heading ?? 'Hvorfor'}>
                            {reasons.map((reason, index) => (
                                <li key={`${reason.key}-${index}`} className="flex flex-wrap items-baseline gap-x-2 text-base text-slate-900" data-testid="info-center-task-reason">
                                    <span className={reason.overdue ? 'font-semibold text-rose-700' : 'font-medium'}>
                                        {taskReasonText(task, reason, t)}
                                    </span>
                                    {reason.due_on ? <span className="text-sm text-slate-600">{formatDay(reason.due_on, locale)}</span> : null}
                                    {reason.can_act ? null : (
                                        <span className="text-sm font-medium text-amber-900">{t.missing_permission ?? 'Mangler rettighet'}</span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    ) : null}

                    {task.can_act ? null : (
                        <p className="max-w-3xl text-sm leading-6 text-slate-700">
                            {t.missing_permission_text_generic ?? 'Du kan se dette, men mangler rettigheten som trengs for minst ett av punktene. Be en administrator om tilgang, eller avtal hvem som gjør det.'}
                        </p>
                    )}
                </div>

                <div className="flex shrink-0 flex-col items-start gap-2 lg:items-end">
                    <div className="text-sm text-slate-600">
                        {t.due ?? 'Frist'}: <span className="font-semibold text-slate-900">{dueOn ?? (t.no_due_date ?? 'Ingen frist')}</span>
                    </div>
                    <Link
                        href={task.action_url ?? '#'}
                        className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-300 hover:text-slate-950"
                    >
                        {t.open ?? 'Åpne'}
                    </Link>
                </div>
            </div>
        </article>
    );
}

/**
 * «Mine oppgaver», grouped Forfalt / Denne uken / Senere / Uten frist. Each module keeps its own
 * card — renderTask decides which — so an Anbud aksjon, a Wiki review and a supplier read as what
 * they are while sharing one order and one set of deadlines.
 *
 * The module filter narrows the same groups in the browser: every task is already on the page, so
 * choosing a module never asks the server again and never shows a module the person cannot see.
 */
export default function MyTaskGroups({ myTasks, t = {}, renderTask }) {
    const [module, setModule] = useState(null);
    const options = moduleFilterOptions(myTasks, t.modules);
    const groups = visibleTaskGroups(filterTaskGroups(myTasks, module), t.groups);
    const total = Number(myTasks?.count ?? 0);

    if (total === 0) {
        return (
            <div className="rounded-[22px] border border-dashed border-slate-300 bg-slate-50 px-6 py-14 text-center">
                <div className="text-lg font-semibold text-slate-900">{t.empty_title ?? 'Ingen åpne oppgaver'}</div>
                <p className="mt-2 text-base text-slate-600">
                    {t.empty_text ?? 'Når noe blir tildelt deg, vises det her til arbeidet er gjort.'}
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-6" data-testid="info-center-my-tasks">
            {options.length ? (
                <div className="flex flex-wrap items-center gap-2" role="group" aria-label={t.filter_label ?? 'Vis oppgaver fra'} data-testid="info-center-my-tasks-filter">
                    <span className="mr-1 text-base text-slate-600">{t.filter_label ?? 'Vis oppgaver fra'}</span>
                    {[{ key: null, label: t.filter_all ?? 'Alle moduler', count: total }, ...options].map((option) => (
                        <button
                            key={option.key ?? 'all'}
                            type="button"
                            aria-pressed={module === option.key}
                            onClick={() => setModule(option.key)}
                            className={module === option.key
                                ? 'inline-flex min-h-10 items-center rounded-full border border-violet-300 bg-violet-50 px-3 py-1.5 text-sm font-semibold text-violet-800'
                                : 'inline-flex min-h-10 items-center rounded-full border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:border-slate-300'}
                        >
                            {option.label} <span className="ml-1.5 text-slate-500">{option.count}</span>
                        </button>
                    ))}
                </div>
            ) : null}

            {groups.length === 0 ? (
                <p className="rounded-[22px] border border-dashed border-slate-300 bg-slate-50 px-6 py-8 text-center text-base text-slate-600" data-testid="info-center-my-tasks-filter-empty">
                    {t.filter_empty ?? 'Ingen åpne oppgaver fra denne modulen.'}
                </p>
            ) : groups.map((group) => (
                <section key={group.key} aria-labelledby={`my-tasks-${group.key}`} data-testid={`info-center-my-tasks-${group.key}`}>
                    <h3
                        id={`my-tasks-${group.key}`}
                        className={group.key === 'overdue' ? 'mb-3 text-base font-semibold text-rose-700' : 'mb-3 text-base font-semibold text-slate-900'}
                    >
                        {group.label} <span className="font-normal text-slate-600">({group.tasks.length})</span>
                    </h3>
                    <div className="space-y-3.5">
                        {group.tasks.map((task) => (
                            <div key={task.id}>{renderTask(task)}</div>
                        ))}
                    </div>
                </section>
            ))}
        </div>
    );
}
