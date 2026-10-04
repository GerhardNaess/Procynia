import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-sm font-semibold text-slate-700';

/**
 * Kontekst: the Kvalitet processes and activities this risk belongs to. Rendered only when the
 * server sent `context` — which it does only to someone who can read Kvalitet. Process and step
 * names are Kvalitet's, read live; removing a link leaves both in place. Kvalitet itself never
 * shows this link.
 */
export default function RiskContextPanel({ riskId, context, options, canLink, tr }) {
    const tc = tr.context ?? {};
    const [linking, setLinking] = useState(false);
    const form = useForm({ quality_item_id: '', activity_key: '' });

    const selectedProcess = options.find((option) => String(option.id) === String(form.data.quality_item_id));
    const label = (item) => (item.code ? `${item.code} · ${item.title}` : item.title);
    const activityLabel = (activity) => (activity.role ? `${activity.label} (${activity.role})` : activity.label);
    // Linking what is already linked would be a no-op; the choice is disabled instead.
    const choiceTaken = selectedProcess !== undefined && (form.data.activity_key === ''
        ? selectedProcess.linked
        : Boolean(selectedProcess.activities.find((activity) => activity.key === form.data.activity_key)?.linked));

    const close = () => { setLinking(false); form.reset(); form.clearErrors(); };

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/risk/risks/${riskId}/context`, { preserveScroll: true, onSuccess: close });
    };

    const unlink = (url) => {
        if (! window.confirm(tc.unlink_confirm ?? 'Fjerne koblingen? Prosessen og aktiviteten blir værende i Kvalitet.')) {
            return;
        }

        router.delete(url, { preserveScroll: true });
    };

    return (
        <section className={CARD}>
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="text-lg font-semibold text-slate-950">{tc.title ?? 'Kontekst'}</h2>
                    <p className="mt-1 text-sm text-slate-600">
                        {tc.description ?? 'Prosesser og aktiviteter i Kvalitet der risikoen hører hjemme. Vises bare her — Kvalitet viser ikke hvilke risikoer som er koblet.'}
                    </p>
                </div>
                {canLink && ! linking && (
                    <button type="button" onClick={() => setLinking(true)} className={SECONDARY_ACTION}>
                        {tc.link ?? 'Koble prosess'}
                    </button>
                )}
            </div>

            {linking && (
                <form onSubmit={submit} className="mt-4 space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    {options.length === 0 ? (
                        <p className="text-base text-slate-700">{tc.no_options ?? 'Det finnes ingen prosesser i Kvalitet å koble til.'}</p>
                    ) : (
                        <>
                            <div>
                                <label htmlFor="risk-context-process" className={LABEL}>{tc.choose_process ?? 'Prosess'}</label>
                                <select
                                    id="risk-context-process"
                                    value={form.data.quality_item_id}
                                    onChange={(event) => form.setData({ quality_item_id: event.target.value, activity_key: '' })}
                                    className={`mt-1 ${INPUT}`}
                                >
                                    <option value="">{tc.choose_process_placeholder ?? 'Velg en prosess …'}</option>
                                    {options.map((option) => (
                                        <option key={option.id} value={option.id}>{label(option)}</option>
                                    ))}
                                </select>
                                {form.errors.quality_item_id && (
                                    <p className="mt-1 text-sm text-rose-700">{form.errors.quality_item_id}</p>
                                )}
                            </div>
                            {selectedProcess && (
                                <div>
                                    <label htmlFor="risk-context-activity" className={LABEL}>{tc.choose_activity ?? 'Aktivitet (valgfritt)'}</label>
                                    <select
                                        id="risk-context-activity"
                                        value={form.data.activity_key}
                                        onChange={(event) => form.setData('activity_key', event.target.value)}
                                        className={`mt-1 ${INPUT}`}
                                    >
                                        <option value="">
                                            {tc.whole_process ?? 'Hele prosessen'}
                                            {selectedProcess.linked ? ` — ${tc.already_linked ?? 'allerede koblet'}` : ''}
                                        </option>
                                        {selectedProcess.activities.map((activity) => (
                                            <option key={activity.key} value={activity.key} disabled={activity.linked}>
                                                {activityLabel(activity)}
                                                {activity.linked ? ` — ${tc.already_linked ?? 'allerede koblet'}` : ''}
                                            </option>
                                        ))}
                                    </select>
                                    {selectedProcess.activities.length === 0 && (
                                        <p className="mt-1 text-sm text-slate-600">{tc.no_activities ?? 'Prosessen har ingen aktiviteter i flyten.'}</p>
                                    )}
                                    {form.errors.activity_key && (
                                        <p className="mt-1 text-sm text-rose-700">{form.errors.activity_key}</p>
                                    )}
                                </div>
                            )}
                        </>
                    )}
                    <div className="flex flex-wrap gap-2">
                        {options.length > 0 && (
                            <button type="submit" disabled={form.processing || ! selectedProcess || choiceTaken} className={PRIMARY_ACTION}>
                                {tc.save ?? 'Koble'}
                            </button>
                        )}
                        <button type="button" onClick={close} className={SECONDARY_ACTION}>
                            {tc.cancel ?? 'Avbryt'}
                        </button>
                    </div>
                </form>
            )}

            {context.length === 0 ? (
                <p className="mt-4 text-base text-slate-600">{tc.empty ?? 'Risikoen er ikke koblet til noen prosess eller aktivitet.'}</p>
            ) : (
                <ul className="mt-4 divide-y divide-slate-100">
                    {context.map((process) => (
                        <li key={process.id} className="space-y-2 py-3">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <a href={process.url} className="text-base font-semibold text-violet-700 hover:text-violet-900">
                                        {label(process)}
                                    </a>
                                    {process.whole_process && (
                                        <p className="text-sm text-slate-600">{tc.process_linked ?? 'Hele prosessen'}</p>
                                    )}
                                </div>
                                {canLink && process.whole_process && (
                                    <button
                                        type="button"
                                        onClick={() => unlink(`/app/risk/risks/${riskId}/context/processes/${process.id}`)}
                                        className={DESTRUCTIVE_ACTION}
                                        aria-label={`${tc.unlink ?? 'Fjern kobling'}: ${label(process)}`}
                                    >
                                        {tc.unlink ?? 'Fjern kobling'}
                                    </button>
                                )}
                            </div>
                            {process.activities.length > 0 && (
                                <ul className="space-y-2 border-l-2 border-slate-200 pl-4" aria-label={tc.activities ?? 'Aktiviteter'}>
                                    {process.activities.map((activity) => (
                                        <li key={activity.id} className="flex flex-wrap items-center justify-between gap-3">
                                            <a href={activity.url} className="text-base text-violet-700 hover:text-violet-900">
                                                {activityLabel(activity)}
                                            </a>
                                            {canLink && (
                                                <button
                                                    type="button"
                                                    onClick={() => unlink(`/app/risk/risks/${riskId}/context/activities/${activity.id}`)}
                                                    className={DESTRUCTIVE_ACTION}
                                                    aria-label={`${tc.unlink ?? 'Fjern kobling'}: ${activity.label}`}
                                                >
                                                    {tc.unlink ?? 'Fjern kobling'}
                                                </button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
