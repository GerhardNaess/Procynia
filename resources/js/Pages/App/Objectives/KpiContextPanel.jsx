import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';

const processLabel = (item) => (item.code ? `${item.code} · ${item.title}` : item.title);
const activityLabel = (activity) => (activity.role ? `${activity.label} (${activity.role})` : activity.label);

/** What the KPI measures in the chosen process now: the starting point of Endre kobling. */
function linkedChoice(option) {
    return {
        whole_process: Boolean(option?.linked),
        activity_keys: (option?.activities ?? []).filter((activity) => activity.linked).map((activity) => activity.key),
    };
}

/**
 * Prosess og aktivitet: the Kvalitet processes and activities this KPI measures. Rendered only when
 * the server sent `context` — which it does only to someone who can read Kvalitet. Names are
 * Kvalitet's, read live. Endre kobling works one process at a time: tick the whole process and/or
 * its activities; clearing every box removes the process from the KPI. Kvalitet never shows this.
 */
export default function KpiContextPanel({ baseUrl, context, options, canLink, tr }) {
    const tc = tr.context ?? {};
    const [editing, setEditing] = useState(false);
    const [processId, setProcessId] = useState('');
    const form = useForm({ whole_process: false, activity_keys: [] });

    const selected = options.find((option) => String(option.id) === String(processId));

    const choose = (id) => {
        setProcessId(id);
        form.setData(linkedChoice(options.find((option) => String(option.id) === String(id))));
        form.clearErrors();
    };

    const open = () => {
        setEditing(true);
        // Start on the first process the KPI already measures, if any.
        choose(context.length > 0 ? String(context[0].id) : '');
    };

    const close = () => { setEditing(false); setProcessId(''); form.reset(); form.clearErrors(); };

    const toggleActivity = (key) => {
        const keys = form.data.activity_keys;
        form.setData('activity_keys', keys.includes(key) ? keys.filter((item) => item !== key) : [...keys, key]);
    };

    const submit = (event) => {
        event.preventDefault();
        form.put(`${baseUrl}/processes/${processId}`, { preserveScroll: true, onSuccess: close });
    };

    return (
        <section className={CARD} aria-labelledby="kpi-context-heading" data-testid="kpi-context">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 id="kpi-context-heading" className="text-lg font-semibold text-slate-950">{tc.title ?? 'Prosess og aktivitet'}</h2>
                    <p className="mt-1 text-base text-slate-600">
                        {tc.description ?? 'Hva KPI-en måler i virksomheten. Navnene hentes fra Kvalitet.'}
                    </p>
                </div>
                {canLink && ! editing && (
                    <button type="button" onClick={open} className={SECONDARY_ACTION}>{tc.edit ?? 'Endre kobling'}</button>
                )}
            </div>

            {editing && (
                <form onSubmit={submit} className="mt-4 space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    {options.length === 0 ? (
                        <p className="text-base text-slate-700">{tc.no_options ?? 'Det finnes ingen prosesser i Kvalitet å koble til.'}</p>
                    ) : (
                        <>
                            <div>
                                <label htmlFor="kpi-context-process" className={LABEL}>{tc.choose_process ?? 'Prosess'}</label>
                                <select
                                    id="kpi-context-process"
                                    value={processId}
                                    onChange={(event) => choose(event.target.value)}
                                    className={`mt-1 ${INPUT}`}
                                >
                                    <option value="">{tc.choose_process_placeholder ?? 'Velg en prosess …'}</option>
                                    {options.map((option) => (
                                        <option key={option.id} value={option.id}>{processLabel(option)}</option>
                                    ))}
                                </select>
                                {form.errors.quality_process_id && (
                                    <p className="mt-1 text-base text-rose-700">{form.errors.quality_process_id}</p>
                                )}
                            </div>
                            {selected && (
                                <fieldset className="space-y-2">
                                    <legend className={LABEL}>{tc.measures_legend ?? 'Hva måler KPI-en i denne prosessen?'}</legend>
                                    <label className="flex items-center gap-2 text-base text-slate-900">
                                        <input
                                            type="checkbox"
                                            checked={form.data.whole_process}
                                            onChange={(event) => form.setData('whole_process', event.target.checked)}
                                            className="h-4 w-4 rounded border-slate-300"
                                        />
                                        {tc.whole_process ?? 'Hele prosessen'}
                                    </label>
                                    {selected.activities.map((activity) => (
                                        <label key={activity.key} className="flex items-center gap-2 pl-4 text-base text-slate-900">
                                            <input
                                                type="checkbox"
                                                checked={form.data.activity_keys.includes(activity.key)}
                                                onChange={() => toggleActivity(activity.key)}
                                                className="h-4 w-4 rounded border-slate-300"
                                            />
                                            {activityLabel(activity)}
                                        </label>
                                    ))}
                                    {selected.activities.length === 0 && (
                                        <p className="text-base text-slate-600">{tc.no_activities ?? 'Prosessen har ingen aktiviteter i flyten.'}</p>
                                    )}
                                    {form.errors.activity_keys && (
                                        <p className="text-base text-rose-700">{form.errors.activity_keys}</p>
                                    )}
                                    <p className="text-base text-slate-600">{tc.remove_hint ?? 'Fjern alle avkrysninger for å fjerne prosessen fra KPI-en.'}</p>
                                </fieldset>
                            )}
                        </>
                    )}
                    <div className="flex flex-wrap gap-2">
                        {options.length > 0 && (
                            <button type="submit" disabled={form.processing || ! selected} className={PRIMARY_ACTION}>
                                {tc.save ?? 'Lagre kobling'}
                            </button>
                        )}
                        <button type="button" onClick={close} className={SECONDARY_ACTION}>{tc.cancel ?? 'Avbryt'}</button>
                    </div>
                </form>
            )}

            {context.length === 0 ? (
                <p className="mt-4 text-base text-slate-600">{tc.empty ?? 'KPI-en er ikke koblet til noen prosess eller aktivitet.'}</p>
            ) : (
                <ul className="mt-4 divide-y divide-slate-100">
                    {context.map((process) => (
                        <li key={process.id} className="space-y-2 py-3" data-testid="kpi-context-process">
                            <div>
                                <p className="text-base font-semibold text-slate-600">{tc.process_heading ?? 'Prosess'}</p>
                                <a href={process.url} className="text-base font-semibold text-violet-700 hover:text-violet-900">
                                    {processLabel(process)}
                                </a>
                                {process.whole_process && (
                                    <p className="text-base text-slate-600">{tc.whole_process ?? 'Hele prosessen'}</p>
                                )}
                            </div>
                            {process.activities.length > 0 && (
                                <div>
                                    <p className="text-base font-semibold text-slate-600">{tc.activities_heading ?? 'Aktiviteter'}</p>
                                    <ul className="mt-1 space-y-1 border-l-2 border-slate-200 pl-4" aria-label={tc.activities_heading ?? 'Aktiviteter'}>
                                        {process.activities.map((activity) => (
                                            <li key={activity.id}>
                                                <a href={activity.url} className="text-base text-violet-700 hover:text-violet-900">
                                                    {activityLabel(activity)}
                                                </a>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
