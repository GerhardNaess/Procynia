import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-sm font-semibold text-slate-700';

const STATUS_TONES = { draft: 'slate', active: 'emerald', under_review: 'amber', retired: 'slate' };

/**
 * The Kvalitet controls that handle this risk. Rendered only when the server sent `controls` —
 * which it does only to someone who can read controls in Kvalitet. The control is shown as
 * Kvalitet has it now and opened there; nothing about it lives on the risk. Removing a link leaves
 * the control in place.
 */
export default function RiskControlsPanel({ riskId, controls, options, canLink, tr, qualityStatuses = {} }) {
    const tc = tr.controls ?? {};
    const [linking, setLinking] = useState(false);
    const form = useForm({ quality_item_id: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/risk/risks/${riskId}/controls`, {
            preserveScroll: true,
            onSuccess: () => { setLinking(false); form.reset(); },
        });
    };

    const unlink = (control) => {
        if (! window.confirm(tc.unlink_confirm ?? 'Fjerne koblingen? Kontrollen blir værende i Kvalitet.')) {
            return;
        }

        router.delete(`/app/risk/risks/${riskId}/controls/${control.id}`, { preserveScroll: true });
    };

    const label = (control) => (control.code ? `${control.code} · ${control.title}` : control.title);

    return (
        <section className={CARD}>
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="text-lg font-semibold text-slate-950">{tc.title ?? 'Kontroller som håndterer risikoen'}</h2>
                    <p className="mt-1 text-sm text-slate-600">
                        {tc.description ?? 'Eksisterende kontroller fra Kvalitet. Koblingen endrer ikke risikovurderingen — restrisiko vurderes alltid manuelt.'}
                    </p>
                </div>
                {canLink && ! linking && (
                    <button type="button" onClick={() => setLinking(true)} className={SECONDARY_ACTION}>
                        {tc.link ?? 'Koble kontroll'}
                    </button>
                )}
            </div>

            {linking && (
                <form onSubmit={submit} className="mt-4 space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    {options.length === 0 ? (
                        <p className="text-base text-slate-700">{tc.no_options ?? 'Det finnes ingen flere kontroller i Kvalitet å koble til.'}</p>
                    ) : (
                        <div>
                            <label htmlFor="risk-control" className={LABEL}>{tc.choose ?? 'Velg kontroll'}</label>
                            <select
                                id="risk-control"
                                value={form.data.quality_item_id}
                                onChange={(event) => form.setData('quality_item_id', event.target.value)}
                                className={`mt-1 ${INPUT}`}
                            >
                                <option value="">{tc.choose_placeholder ?? 'Velg en kontroll …'}</option>
                                {options.map((option) => (
                                    <option key={option.id} value={option.id}>{label(option)}</option>
                                ))}
                            </select>
                            {form.errors.quality_item_id && (
                                <p className="mt-1 text-sm text-rose-700">{form.errors.quality_item_id}</p>
                            )}
                        </div>
                    )}
                    <div className="flex flex-wrap gap-2">
                        {options.length > 0 && (
                            <button type="submit" disabled={form.processing || form.data.quality_item_id === ''} className={PRIMARY_ACTION}>
                                {tc.save ?? 'Koble'}
                            </button>
                        )}
                        <button type="button" onClick={() => { setLinking(false); form.reset(); form.clearErrors(); }} className={SECONDARY_ACTION}>
                            {tc.cancel ?? 'Avbryt'}
                        </button>
                    </div>
                </form>
            )}

            {controls.length === 0 ? (
                <p className="mt-4 text-base text-slate-600">{tc.empty ?? 'Ingen kontroller er koblet til denne risikoen.'}</p>
            ) : (
                <ul className="mt-4 divide-y divide-slate-100">
                    {controls.map((control) => (
                        <li key={control.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
                            <div className="min-w-0 space-y-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <a href={control.url} className="text-base font-semibold text-violet-700 hover:text-violet-900">
                                        {label(control)}
                                    </a>
                                    <StatusBadge tone={STATUS_TONES[control.status] ?? 'slate'}>
                                        {qualityStatuses[control.status] ?? control.status}
                                    </StatusBadge>
                                </div>
                                {control.criterion && (
                                    <p className="text-sm text-slate-600">
                                        <span className="font-semibold">{tc.criterion ?? 'Kriterium'}:</span> {control.criterion}
                                    </p>
                                )}
                            </div>
                            {canLink && (
                                <button type="button" onClick={() => unlink(control)} className={DESTRUCTIVE_ACTION}>
                                    {tc.unlink ?? 'Fjern kobling'}
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
