import { useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { useFocusFirstError } from '../../../Support/focusManagement';
import { fieldA11y } from '../../../Support/formFieldA11y';
import { ERROR, INPUT } from '../../../Support/formStyles';
import useInlinePanelFocus from '../Risk/useInlinePanelFocus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

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

    // The button is hidden while the text is edited: focus to the field, and back (A11Y-014).
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);
    useInlinePanelFocus(editing, { panelRef: formRef, returnIds: ['improvement-cause-edit', 'improvement-cause-heading'] });
    const field = fieldA11y({ id: 'improvement-cause', hasHint: true, hasError: Boolean(form.errors.cause_analysis) });

    const save = (event) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        form.put(`/app/improvements/${caseId}/cause`, { preserveScroll: true, onSuccess: () => setEditing(false), onError: () => focusFirstError() });
    };

    return (
        <section className={CARD} aria-labelledby="improvement-cause-heading" data-testid="improvement-cause">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <h2 id="improvement-cause-heading" className="text-xl font-semibold text-slate-950">{t.heading ?? 'Årsak og bakgrunn'}</h2>
                {canEdit && ! editing && (
                    <button id="improvement-cause-edit" type="button" onClick={() => setEditing(true)} className={SECONDARY_ACTION}>
                        {text ? (t.edit ?? 'Endre') : (t.add ?? 'Beskriv årsak og bakgrunn')}
                    </button>
                )}
            </div>

            {editing ? (
                <form ref={formRef} onSubmit={save} className="mt-3 space-y-4">
                    <div>
                        <label htmlFor="improvement-cause" className="block text-base font-semibold text-slate-700">{t.heading ?? 'Årsak og bakgrunn'}</label>
                        <p id={field.hintId} className="mt-1 text-base text-slate-600">{hint}</p>
                        <textarea
                            {...field.controlProps}
                            rows={4}
                            value={form.data.cause_analysis}
                            onChange={(event) => form.setData('cause_analysis', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        />
                        {form.errors.cause_analysis && <p id={field.errorId} className={ERROR}>{form.errors.cause_analysis}</p>}
                    </div>
                    <div className="flex flex-wrap justify-end gap-3">
                        <button type="button" onClick={() => { setEditing(false); form.reset(); form.clearErrors(); }} className={SECONDARY_ACTION}>
                            {tr.cancel ?? 'Avbryt'}
                        </button>
                        <button type="submit" aria-disabled={form.processing || undefined} className={PRIMARY_ACTION}>
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
