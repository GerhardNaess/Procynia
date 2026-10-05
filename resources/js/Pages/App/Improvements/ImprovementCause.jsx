import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';

/**
 * Årsak og bakgrunn: why the avvik happened, or what lies behind the forbedring. One optional text,
 * written while the case is open or under arbeid and left as it stood once it has ended. The hint
 * follows the case's type.
 */
export default function ImprovementCause({ caseId, type, text, canEdit, tr }) {
    const t = tr.cause ?? {};
    const [editing, setEditing] = useState(false);
    const form = useForm({ cause_analysis: text ?? '' });
    const hint = type === 'improvement'
        ? (t.hint_improvement ?? 'Beskriv bakgrunnen for forbedringen og hva som bør endres.')
        : (t.hint_deviation ?? 'Beskriv hvorfor avviket oppstod, dersom årsaken er kjent.');

    const save = (event) => {
        event.preventDefault();
        form.put(`/app/improvements/${caseId}/cause`, { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    return (
        <section className={CARD} aria-labelledby="improvement-cause-heading" data-testid="improvement-cause">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <h2 id="improvement-cause-heading" className="text-xl font-semibold text-slate-950">{t.heading ?? 'Årsak og bakgrunn'}</h2>
                {canEdit && ! editing && (
                    <button type="button" onClick={() => setEditing(true)} className={SECONDARY_ACTION}>
                        {text ? (t.edit ?? 'Endre') : (t.add ?? 'Beskriv årsak og bakgrunn')}
                    </button>
                )}
            </div>

            {editing ? (
                <form onSubmit={save} className="mt-3 space-y-4">
                    <div>
                        <label htmlFor="improvement-cause" className="block text-base font-semibold text-slate-700">{t.heading ?? 'Årsak og bakgrunn'}</label>
                        <p id="improvement-cause-hint" className="mt-1 text-base text-slate-600">{hint}</p>
                        <textarea
                            id="improvement-cause"
                            rows={4}
                            aria-describedby="improvement-cause-hint"
                            value={form.data.cause_analysis}
                            onChange={(event) => form.setData('cause_analysis', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        />
                        {form.errors.cause_analysis && <p className="mt-1 text-base text-rose-700">{form.errors.cause_analysis}</p>}
                    </div>
                    <div className="flex flex-wrap justify-end gap-3">
                        <button type="button" onClick={() => { setEditing(false); form.reset(); form.clearErrors(); }} className={SECONDARY_ACTION}>
                            {tr.cancel ?? 'Avbryt'}
                        </button>
                        <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                            {form.processing ? (tr.saving ?? 'Lagrer...') : (t.save ?? 'Lagre')}
                        </button>
                    </div>
                </form>
            ) : text ? (
                <p className="mt-3 whitespace-pre-line break-words text-base leading-7 text-slate-800" data-testid="improvement-cause-text">{text}</p>
            ) : (
                <div className="mt-3 space-y-1">
                    <p className="text-base text-slate-600">{t.empty ?? 'Ikke beskrevet ennå.'}</p>
                    {canEdit && <p className="text-base text-slate-600">{hint}</p>}
                </div>
            )}
        </section>
    );
}
