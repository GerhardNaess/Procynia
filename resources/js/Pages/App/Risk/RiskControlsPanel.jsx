import { useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { refocusIfLost, useFocusFirstError } from '../../../Support/focusManagement';
import { fieldA11y } from '../../../Support/formFieldA11y';
import { ERROR, INPUT, LABEL } from '../../../Support/formStyles';
import { removalFocusCandidates } from './riskA11y';
import { CONTROL_FILTER_THRESHOLD, controlLabel, controlOptionLabel, filterControls } from './riskControlOptions';
import useInlinePanelFocus from './useInlinePanelFocus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

const STATUS_TONES = { draft: 'slate', active: 'emerald', under_review: 'amber', retired: 'slate' };

/**
 * The Kvalitet controls that handle this risk. Rendered only when the server sent `controls` —
 * which it does only to someone who can read controls in Kvalitet. The control is shown as
 * Kvalitet has it now and opened there; nothing about it lives on the risk. Removing a link leaves
 * the control in place.
 *
 * Each control carries where it sits in Kvalitet's flows (process › activity), shown in the picker
 * and on the linked list; with many controls the picker also gets a search field.
 */
export default function RiskControlsPanel({ riskId, controls, options, canLink, tr, qualityStatuses = {} }) {
    const tc = tr.controls ?? {};
    const [linking, setLinking] = useState(false);
    const [query, setQuery] = useState('');
    const form = useForm({ quality_item_id: '' });
    const searchable = options.length > CONTROL_FILTER_THRESHOLD;
    const shown = searchable ? filterControls(options, query, form.data.quality_item_id) : options;
    // «Koble kontroll» is hidden while its form is open: focus in, and back to it (A11Y-014).
    const panelRef = useRef(null);
    const focusFirstError = useFocusFirstError(panelRef);
    useInlinePanelFocus(linking, { panelRef, returnIds: ['risk-control-link', 'risk-controls-heading'] });

    const close = () => { setLinking(false); setQuery(''); form.reset(); form.clearErrors(); };

    const submit = (event) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        form.post(`/app/risk/risks/${riskId}/controls`, {
            preserveScroll: true,
            onSuccess: close,
            onError: () => focusFirstError(),
        });
    };

    // The removed control's row and button go: focus to the next control's button, else the previous
    // one, else «Koble kontroll» or the heading — never the page (2.4.3, A11Y-014).
    const unlink = (control) => {
        if (! window.confirm(tc.unlink_confirm ?? 'Fjerne koblingen? Kontrollen blir værende i Kvalitet.')) {
            return;
        }

        const candidates = removalFocusCandidates(
            controls.map((item) => `risk-control-${item.id}-unlink`),
            `risk-control-${control.id}-unlink`,
            ['risk-control-link', 'risk-controls-heading'],
        );

        router.delete(`/app/risk/risks/${riskId}/controls/${control.id}`, {
            preserveScroll: true,
            onSuccess: () => refocusIfLost(candidates),
        });
    };

    const select = fieldA11y({ id: 'risk-control', hasError: Boolean(form.errors.quality_item_id) });

    return (
        <section className={CARD}>
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 id="risk-controls-heading" className="text-lg font-semibold text-slate-950">{tc.title ?? 'Kontroller som håndterer risikoen'}</h2>
                    <p className="mt-1 text-base text-slate-600">
                        {tc.description ?? 'Kontroller fra Kvalitet som bidrar til å håndtere risikoen. Ta dem med når du vurderer restrisiko.'}
                    </p>
                </div>
                {canLink && ! linking && (
                    <button id="risk-control-link" type="button" onClick={() => setLinking(true)} className={SECONDARY_ACTION}>
                        {tc.link ?? 'Koble kontroll'}
                    </button>
                )}
            </div>

            {linking && (
                <form ref={panelRef} onSubmit={submit} className="mt-4 space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    {options.length === 0 ? (
                        <p className="text-base text-slate-700">{tc.no_options ?? 'Det finnes ingen flere kontroller i Kvalitet å koble til.'}</p>
                    ) : (
                        <div className="space-y-3">
                            {searchable && (
                                <div>
                                    <label htmlFor="risk-control-filter" className={LABEL}>{tc.filter_label ?? 'Søk etter kontroll'}</label>
                                    <input
                                        id="risk-control-filter"
                                        type="search"
                                        aria-describedby={shown.length === 0 ? 'risk-control-filter-none' : undefined}
                                        value={query}
                                        onChange={(event) => setQuery(event.target.value)}
                                        placeholder={tc.filter_placeholder ?? 'Navn, kode, prosess eller aktivitet'}
                                        className={`mt-1 ${INPUT}`}
                                    />
                                    {shown.length === 0 && (
                                        <p id="risk-control-filter-none" className="mt-1 text-base text-slate-600">{tc.filter_none ?? 'Ingen kontroller passer søket.'}</p>
                                    )}
                                </div>
                            )}
                            <div>
                                <label htmlFor="risk-control" className={LABEL}>{tc.choose ?? 'Velg kontroll'}</label>
                                <select
                                    {...select.controlProps}
                                    value={form.data.quality_item_id}
                                    onChange={(event) => form.setData('quality_item_id', event.target.value)}
                                    className={`mt-1 ${INPUT}`}
                                >
                                    <option value="">{tc.choose_placeholder ?? 'Velg en kontroll …'}</option>
                                    {shown.map((option) => (
                                        <option key={option.id} value={option.id}>{controlOptionLabel(option)}</option>
                                    ))}
                                </select>
                            </div>
                            {form.errors.quality_item_id && (
                                <p id={select.errorId} className={ERROR}>{form.errors.quality_item_id}</p>
                            )}
                        </div>
                    )}
                    <div className="flex flex-wrap gap-2">
                        {options.length > 0 && (
                            <button type="submit" disabled={form.data.quality_item_id === ''} aria-disabled={form.processing || undefined} className={PRIMARY_ACTION}>
                                {tc.save ?? 'Koble'}
                            </button>
                        )}
                        <button type="button" onClick={close} className={SECONDARY_ACTION}>
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
                                    <a id={`risk-control-${control.id}-name`} href={control.url} className="text-base font-semibold text-violet-700 hover:text-violet-900">
                                        {controlLabel(control)}
                                    </a>
                                    <StatusBadge tone={STATUS_TONES[control.status] ?? 'slate'}>
                                        {qualityStatuses[control.status] ?? control.status}
                                    </StatusBadge>
                                </div>
                                {control.placements?.length > 0 && (
                                    <p className="text-base text-slate-600">
                                        <span className="font-semibold">{tc.used_in ?? 'Brukes i'}:</span> {control.placements.join('; ')}
                                    </p>
                                )}
                                {control.criterion && (
                                    <p className="text-base text-slate-600">
                                        <span className="font-semibold">{tc.criterion ?? 'Kriterium'}:</span> {control.criterion}
                                    </p>
                                )}
                            </div>
                            {canLink && (
                                <button
                                    id={`risk-control-${control.id}-unlink`}
                                    type="button"
                                    aria-describedby={`risk-control-${control.id}-name`}
                                    onClick={() => unlink(control)}
                                    className={DESTRUCTIVE_ACTION}
                                >
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
