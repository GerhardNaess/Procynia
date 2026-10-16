import { useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import StatusBadge from '../../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import { refocusIfLost, usePanelFocus } from '../../../../Support/focusManagement';
import { HINT, INPUT, LABEL } from '../../../../Support/formStyles';
import FieldError from '../FieldError';
import { fieldStateProps, groupStateProps, optionInvalid, removalFocusCandidates } from '../complianceA11y';
import { qualityItemLabel } from '../Requirements/complianceQuality';
import { useFocusOnRejectedSubmit } from '../useComplianceFocus';
import { filterRequirementOptions, relevanceLabel, requirementLabel } from './complianceAudit';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
// A row in a scope list: its name, and its «Fjern fra omfanget». `kind` is 'requirement' or 'process'.
const scopeNameId = (kind, id) => `compliance-audit-${kind}-name-${id}`;
const scopeRemoveId = (kind, id) => `compliance-audit-${kind}-remove-${id}`;

/**
 * Retired, and where the requirement stands on relevance when it is not Gjelder: what automatic
 * selection leaves out, so choosing it is visibly deliberate.
 */
function RequirementMarks({ requirement, ta, withHint = false }) {
    const relevance = relevanceLabel(requirement, ta);

    if (requirement.status !== 'retired' && ! relevance) {
        return null;
    }

    return (
        <span className="mt-1 flex flex-wrap items-center gap-2">
            {requirement.status === 'retired' && <StatusBadge tone="slate">{ta.retired_requirement ?? 'Utgått'}</StatusBadge>}
            {relevance && <span data-testid="compliance-audit-requirement-relevance"><StatusBadge tone="amber">{relevance}</StatusBadge></span>}
            {relevance && withHint && <span className="text-base text-slate-600">{ta.relevance_hint ?? 'Tas ikke med automatisk. Velger du kravet, er det et bevisst valg.'}</span>}
        </span>
    );
}

/** The searchable checkbox list of requirements, shared by Legg til krav and Ny revisjon. */
function RequirementChecklist({ id, options, selected, onToggle, ta, error = null }) {
    const [query, setQuery] = useState('');
    const visible = filterRequirementOptions(options, query, selected);

    return (
        <>
            <div>
                <label htmlFor={`${id}-filter`} className={LABEL}>{ta.requirements_filter ?? 'Søk'}</label>
                <input
                    id={`${id}-filter`}
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder={ta.requirements_filter_placeholder ?? 'Referanse, tittel eller kravkilde'}
                    className={`mt-1 ${INPUT}`}
                />
            </div>
            {visible.length === 0 ? (
                <p className="mt-2 text-base text-slate-600">{ta.requirements_filter_none ?? 'Ingen krav passer søket.'}</p>
            ) : (
                <ul className="mt-2 max-h-80 divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200">
                    {visible.map((option) => (
                        <li key={option.id}>
                            <label className="flex min-h-10 items-start gap-3 px-3 py-2 text-base text-slate-800">
                                <input
                                    type="checkbox"
                                    value={option.id}
                                    checked={selected.includes(option.id)}
                                    onChange={() => onToggle(option.id)}
                                    aria-invalid={optionInvalid(error)}
                                    className="mt-1 h-5 w-5 shrink-0 rounded border-slate-300"
                                />
                                <span className="min-w-0 break-words">
                                    <span className="font-semibold">{requirementLabel(option)}</span>
                                    <RequirementMarks requirement={option} ta={ta} />
                                </span>
                            </label>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}

/**
 * Ny revisjon's part of planning the scope: tick kravkilder — each with the number of requirements
 * that apply, which is exactly what is taken — and/or single requirements. Nothing here is saved on
 * its own; the audit and its scope are created in one step.
 */
export function PlanningRequirementSelection({ options, form, ta }) {
    const sources = options?.sources ?? [];
    const requirements = options?.requirements ?? [];
    const sourceIds = form.data.source_ids ?? [];
    const requirementIds = form.data.requirement_ids ?? [];

    if (sources.length === 0 && requirements.length === 0) {
        return null;
    }

    const toggle = (field, list, id) => form.setData(field, list.includes(id) ? list.filter((value) => value !== id) : [...list, id]);

    return (
        <section className="space-y-4 rounded-2xl border border-slate-200 p-4" aria-labelledby="compliance-audit-create-requirements-heading" aria-describedby="compliance-audit-create-requirements-hint" data-testid="compliance-audit-create-requirements">
            <div>
                <h3 id="compliance-audit-create-requirements-heading" className="text-lg font-semibold text-slate-950">{ta.create_requirements_heading ?? 'Krav i omfanget'}</h3>
                <p id="compliance-audit-create-requirements-hint" className={HINT}>{ta.create_requirements_hint ?? 'Valgfritt. Velg kravkilder for å ta med alle krav som gjelder virksomheten, og eller enkeltkrav.'}</p>
            </div>

            {sources.length > 0 && (
                <fieldset {...groupStateProps('compliance-audit-create-sources', form.errors.source_ids, ['compliance-audit-create-sources-hint'])}>
                    <legend className={LABEL}>{ta.create_sources_legend ?? 'Legg til fra kravkilde'}</legend>
                    <p id="compliance-audit-create-sources-hint" className={HINT}>{ta.requirements_from_source_hint ?? 'Legger til kravene i kilden som gjelder virksomheten.'}</p>
                    <ul className="mt-2 space-y-1">
                        {sources.map((source) => (
                            <li key={source.id}>
                                <label className="flex min-h-10 items-start gap-3 text-base text-slate-800">
                                    <input
                                        type="checkbox"
                                        checked={sourceIds.includes(source.id)}
                                        onChange={() => toggle('source_ids', sourceIds, source.id)}
                                        aria-invalid={optionInvalid(form.errors.source_ids)}
                                        className="mt-1 h-5 w-5 shrink-0 rounded border-slate-300"
                                    />
                                    <span className="min-w-0 break-words">
                                        {(ta.create_source_option ?? ':source – :count krav som gjelder').replace(':source', source.label).replace(':count', String(source.count))}
                                    </span>
                                </label>
                            </li>
                        ))}
                    </ul>
                    <FieldError field="compliance-audit-create-sources" message={form.errors.source_ids} />
                </fieldset>
            )}

            {requirements.length > 0 && (
                <fieldset className="space-y-2" {...groupStateProps('compliance-audit-create-requirement', form.errors.requirement_ids)}>
                    <legend className={LABEL}>{ta.create_pick_legend ?? 'Velg enkeltkrav'}</legend>
                    <RequirementChecklist
                        id="compliance-audit-create-requirement"
                        options={requirements}
                        selected={requirementIds}
                        onToggle={(id) => toggle('requirement_ids', requirementIds, id)}
                        ta={ta}
                        error={form.errors.requirement_ids}
                    />
                    <FieldError field="compliance-audit-create-requirement" message={form.errors.requirement_ids} />
                </fieldset>
            )}

            <p className="text-base text-slate-700" data-testid="compliance-audit-create-selected">
                {(ta.create_selected_summary ?? ':sources kravkilder og :count enkeltkrav er valgt.').replace(':sources', String(sourceIds.length)).replace(':count', String(requirementIds.length))}
            </p>
        </section>
    );
}

/**
 * Legg til krav: tick one or more requirements — searchable, retired ones and those outside the
 * automatic selection marked — or take every requirement of one source that applies at once. Both
 * write fixed rows; the source is not remembered.
 */
function RequirementPicker({ auditId, options, onDone, ta }) {
    const pick = useForm({ requirement_ids: [] });
    const fromSource = useForm({ source_id: '' });
    const pickRef = useRef(null);
    const fromSourceRef = useRef(null);
    useFocusOnRejectedSubmit(pick, pickRef);
    useFocusOnRejectedSubmit(fromSource, fromSourceRef);

    const selected = pick.data.requirement_ids;
    const toggle = (id) => pick.setData('requirement_ids', selected.includes(id) ? selected.filter((value) => value !== id) : [...selected, id]);

    const submitPick = (event) => {
        event.preventDefault();
        pick.post(`/app/compliance/audits/${auditId}/requirements`, { preserveScroll: true, onSuccess: onDone });
    };

    const submitSource = (event) => {
        event.preventDefault();
        fromSource.post(`/app/compliance/audits/${auditId}/requirements/from-source`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <div className="mt-4 space-y-6 rounded-2xl border border-slate-200 p-4" data-testid="compliance-audit-requirement-picker">
            {(options.requirements ?? []).length === 0 ? (
                <p className="text-base text-slate-700">{ta.requirements_no_options ?? 'Det finnes ingen flere krav å legge til.'}</p>
            ) : (
                <form ref={pickRef} onSubmit={submitPick} className="space-y-3" data-testid="compliance-audit-requirement-form">
                    <fieldset className="space-y-2" {...groupStateProps('compliance-audit-requirement', pick.errors.requirement_ids)}>
                        <legend className={LABEL}>{ta.requirements_choose ?? 'Velg krav'}</legend>
                        <RequirementChecklist id="compliance-audit-requirement" options={options.requirements ?? []} selected={selected} onToggle={toggle} ta={ta} error={pick.errors.requirement_ids} />
                    </fieldset>
                    <FieldError field="compliance-audit-requirement" message={pick.errors.requirement_ids} />
                    <div className="flex flex-wrap items-center justify-end gap-3">
                        <span className="text-base text-slate-600">{(ta.requirements_selected ?? ':count valgt').replace(':count', String(selected.length))}</span>
                        <button type="submit" disabled={pick.processing || selected.length === 0} className={PRIMARY_ACTION}>
                            {ta.requirements_add_selected ?? 'Legg til valgte krav'}
                        </button>
                    </div>
                </form>
            )}

            {(options.sources ?? []).length > 0 && (
                <form ref={fromSourceRef} onSubmit={submitSource} className="space-y-3 border-t border-slate-100 pt-4" data-testid="compliance-audit-source-form">
                    <div>
                        <label htmlFor="compliance-audit-source" className={LABEL}>{ta.requirements_from_source ?? 'Legg til fra kravkilde'}</label>
                        <p id="compliance-audit-source-hint" className={HINT}>
                            {ta.requirements_from_source_hint ?? 'Legger til kravene i kilden som gjelder virksomheten og ikke allerede er med.'}
                        </p>
                        <select
                            id="compliance-audit-source"
                            {...fieldStateProps('compliance-audit-source', fromSource.errors.source_id, ['compliance-audit-source-hint'])}
                            value={fromSource.data.source_id}
                            onChange={(event) => fromSource.setData('source_id', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        >
                            <option value="">{ta.choose_source ?? 'Velg kravkilde'}</option>
                            {options.sources.map((source) => (
                                <option key={source.id} value={source.id}>
                                    {(ta.requirements_from_source_option ?? ':source (:count krav som gjelder)').replace(':source', source.label).replace(':count', String(source.count))}
                                </option>
                            ))}
                        </select>
                        <FieldError field="compliance-audit-source" message={fromSource.errors.source_id} />
                    </div>
                    <div className="flex justify-end">
                        <button type="submit" disabled={fromSource.processing || fromSource.data.source_id === ''} className={SECONDARY_ACTION}>
                            {ta.requirements_from_source_submit ?? 'Legg til fra kilde'}
                        </button>
                    </div>
                </form>
            )}

            <div className="flex justify-end">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{ta.cancel ?? 'Avbryt'}</button>
            </div>
        </div>
    );
}

/**
 * Krav i omfanget: the requirements the audit checks, read live, each with its origin. A retired
 * requirement stays and says so, as does one chosen outside the automatic selection. Adding and
 * removing is offered only when the server said this person may change the scope.
 */
export function AuditRequirementScope({ audit, requirements = [], options = null, canManage = false, ta }) {
    const [adding, setAdding] = useState(false);
    // Legg til krav opens in place of its button: focus goes into the picker, and back (A11Y-014).
    const pickerRef = useRef(null);
    const addRef = useRef(null);
    usePanelFocus(adding, { panelRef: pickerRef, returnFocusRef: addRef });

    const remove = (requirement) => {
        if (! window.confirm(ta.remove_requirement_confirm ?? 'Fjerne kravet fra omfanget? Kravet blir værende i registeret.')) {
            return;
        }

        // The next requirement's «Fjern fra omfanget», the previous one's, or the section (A11Y-014).
        const candidates = removalFocusCandidates(requirements.map((item) => item.id), requirement.id, [(id) => scopeRemoveId('requirement', id)], ['compliance-audit-requirements-add', 'compliance-audit-requirements-heading']);
        router.delete(`/app/compliance/audits/${audit.id}/requirements/${requirement.id}`, { preserveScroll: true, onSuccess: () => refocusIfLost(candidates) });
    };

    return (
        <section className={CARD} aria-labelledby="compliance-audit-requirements-heading" data-testid="compliance-audit-requirements">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 id="compliance-audit-requirements-heading" className="text-xl font-semibold text-slate-950">{ta.requirements_heading ?? 'Krav i omfanget'}</h2>
                    <p className="mt-1 text-base text-slate-600">{ta.requirements_intro ?? 'Kravene revisjonen skal kontrollere.'}</p>
                </div>
                {canManage && ! adding && (
                    <button ref={addRef} id="compliance-audit-requirements-add" type="button" onClick={() => setAdding(true)} className={SECONDARY_ACTION}>{ta.requirements_add ?? 'Legg til krav'}</button>
                )}
            </div>

            {canManage && adding && options && (
                <div ref={pickerRef}>
                    <RequirementPicker auditId={audit.id} options={options} onDone={() => setAdding(false)} ta={ta} />
                </div>
            )}

            {requirements.length === 0 ? (
                <p className="mt-4 text-base text-slate-700">{ta.requirements_empty ?? 'Ingen krav er lagt til i omfanget.'}</p>
            ) : (
                <ul className="mt-4 divide-y divide-slate-100">
                    {requirements.map((requirement) => (
                        <li key={requirement.id} className="flex flex-wrap items-start justify-between gap-3 py-3" data-testid="compliance-audit-requirement">
                            <div className="min-w-0 space-y-1">
                                <Link id={scopeNameId('requirement', requirement.id)} href={requirement.url} className="block break-words text-base font-semibold text-violet-700 hover:text-violet-900">
                                    {requirementLabel(requirement)}
                                </Link>
                                <RequirementMarks requirement={requirement} ta={ta} />
                            </div>
                            {canManage && (
                                <button type="button" onClick={() => remove(requirement)} id={scopeRemoveId('requirement', requirement.id)} aria-describedby={scopeNameId('requirement', requirement.id)} className={SECONDARY_ACTION}>{ta.remove ?? 'Fjern fra omfanget'}</button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/**
 * Prosesser i omfanget: the Kvalitet processes the audit looks at, read live. Drawn only when the server
 * sent them — for someone who can read Kvalitet — so without it the page says nothing at all about
 * processes, not even that there are none.
 */
export function AuditProcessScope({ audit, processes, options = null, canManage = false, ta }) {
    const [adding, setAdding] = useState(false);
    const form = useForm({ quality_process_id: '' });
    const panelRef = useRef(null);
    const addRef = useRef(null);
    const formRef = useRef(null);
    usePanelFocus(adding, { panelRef, returnFocusRef: addRef });
    useFocusOnRejectedSubmit(form, formRef);

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/compliance/audits/${audit.id}/processes`, {
            preserveScroll: true,
            onSuccess: () => { setAdding(false); form.reset(); },
        });
    };

    const remove = (process) => {
        if (! window.confirm(ta.remove_process_confirm ?? 'Fjerne prosessen fra omfanget? Prosessen blir værende i Kvalitet.')) {
            return;
        }

        const candidates = removalFocusCandidates(processes.map((item) => item.id), process.id, [(id) => scopeRemoveId('process', id)], ['compliance-audit-processes-add', 'compliance-audit-processes-heading']);
        router.delete(`/app/compliance/audits/${audit.id}/processes/${process.id}`, { preserveScroll: true, onSuccess: () => refocusIfLost(candidates) });
    };

    return (
        <section className={CARD} aria-labelledby="compliance-audit-processes-heading" data-testid="compliance-audit-processes">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 id="compliance-audit-processes-heading" className="text-xl font-semibold text-slate-950">{ta.processes_heading ?? 'Prosesser i omfanget'}</h2>
                    <p className="mt-1 text-base text-slate-600">{ta.processes_intro ?? 'Prosessene i Kvalitet som revisjonen ser på.'}</p>
                </div>
                {canManage && ! adding && (
                    <button ref={addRef} id="compliance-audit-processes-add" type="button" onClick={() => setAdding(true)} className={SECONDARY_ACTION}>{ta.processes_add ?? 'Legg til prosess'}</button>
                )}
            </div>

            {canManage && adding && options && (
                options.length === 0 ? (
                    <div ref={panelRef} className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 p-4">
                        <p className="text-base text-slate-700">{ta.processes_no_options ?? 'Det finnes ingen flere prosesser i Kvalitet å legge til.'}</p>
                        <button type="button" onClick={() => setAdding(false)} className={SECONDARY_ACTION}>{ta.cancel ?? 'Avbryt'}</button>
                    </div>
                ) : (
                    <form ref={(element) => { panelRef.current = element; formRef.current = element; }} onSubmit={submit} className="mt-4 space-y-3 rounded-2xl border border-slate-200 p-4" data-testid="compliance-audit-process-form">
                        <div>
                            <label htmlFor="compliance-audit-process" className={LABEL}>{ta.processes_choose ?? 'Velg prosess'}</label>
                            <select
                                id="compliance-audit-process"
                                required
                                {...fieldStateProps('compliance-audit-process', form.errors.quality_process_id)}
                                value={form.data.quality_process_id}
                                onChange={(event) => form.setData('quality_process_id', event.target.value)}
                                className={`mt-1 ${INPUT}`}
                            >
                                <option value="">{ta.processes_choose ?? 'Velg prosess'}</option>
                                {options.map((process) => (
                                    <option key={process.id} value={process.id}>{qualityItemLabel(process)}</option>
                                ))}
                            </select>
                            <FieldError field="compliance-audit-process" message={form.errors.quality_process_id} />
                        </div>
                        <div className="flex flex-wrap justify-end gap-3">
                            <button type="button" onClick={() => { setAdding(false); form.reset(); form.clearErrors(); }} className={SECONDARY_ACTION}>{ta.cancel ?? 'Avbryt'}</button>
                            <button type="submit" disabled={form.processing || form.data.quality_process_id === ''} className={PRIMARY_ACTION}>{ta.processes_add_submit ?? 'Legg til'}</button>
                        </div>
                    </form>
                )
            )}

            {processes.length === 0 ? (
                <p className="mt-4 text-base text-slate-700">{ta.processes_empty ?? 'Ingen prosesser er lagt til i omfanget.'}</p>
            ) : (
                <ul className="mt-4 divide-y divide-slate-100">
                    {processes.map((process) => (
                        <li key={process.id} className="flex flex-wrap items-start justify-between gap-3 py-3" data-testid="compliance-audit-process">
                            <div className="min-w-0 space-y-1">
                                <Link id={scopeNameId('process', process.id)} href={process.url} className="block break-words text-base font-semibold text-violet-700 hover:text-violet-900">
                                    {qualityItemLabel(process)}
                                </Link>
                                {process.status === 'retired' && <StatusBadge tone="slate">{ta.retired_process ?? 'Utgått i Kvalitet'}</StatusBadge>}
                            </div>
                            {canManage && (
                                <button type="button" onClick={() => remove(process)} id={scopeRemoveId('process', process.id)} aria-describedby={scopeNameId('process', process.id)} className={SECONDARY_ACTION}>{ta.remove ?? 'Fjern fra omfanget'}</button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
