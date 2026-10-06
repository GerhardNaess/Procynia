import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import StatusBadge from '../../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import { qualityItemLabel } from '../Requirements/complianceQuality';
import { filterRequirementOptions, requirementLabel } from './complianceAudit';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';
const HINT = 'mt-1 text-base text-slate-600';
const ERROR = 'mt-1 text-base text-rose-700';

/**
 * Legg til krav: tick one or more requirements — searchable, retired ones marked — or take every
 * active requirement of one source at once. Both write fixed rows; the source is not remembered.
 */
function RequirementPicker({ auditId, options, onDone, ta }) {
    const [query, setQuery] = useState('');
    const pick = useForm({ requirement_ids: [] });
    const fromSource = useForm({ source_id: '' });

    const selected = pick.data.requirement_ids;
    const visible = filterRequirementOptions(options.requirements ?? [], query, selected);
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
                <form onSubmit={submitPick} className="space-y-3" data-testid="compliance-audit-requirement-form">
                    <div>
                        <label htmlFor="compliance-audit-requirement-filter" className={LABEL}>{ta.requirements_filter ?? 'Søk'}</label>
                        <input
                            id="compliance-audit-requirement-filter"
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={ta.requirements_filter_placeholder ?? 'Referanse, tittel eller kravkilde'}
                            className={`mt-1 ${INPUT}`}
                        />
                    </div>
                    <fieldset>
                        <legend className={LABEL}>{ta.requirements_choose ?? 'Velg krav'}</legend>
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
                                                onChange={() => toggle(option.id)}
                                                className="mt-1 h-5 w-5 shrink-0 rounded border-slate-300"
                                            />
                                            <span className="min-w-0 break-words">
                                                <span className="font-semibold">{requirementLabel(option)}</span>
                                                {option.source_label && <span className="block text-slate-600">{option.source_label}</span>}
                                                {option.status === 'retired' && (
                                                    <span className="mt-1 block"><StatusBadge tone="slate">{ta.retired_requirement ?? 'Utgått'}</StatusBadge></span>
                                                )}
                                            </span>
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </fieldset>
                    {pick.errors.requirement_ids && <p className={ERROR}>{pick.errors.requirement_ids}</p>}
                    <div className="flex flex-wrap items-center justify-end gap-3">
                        <span className="text-base text-slate-600">{(ta.requirements_selected ?? ':count valgt').replace(':count', String(selected.length))}</span>
                        <button type="submit" disabled={pick.processing || selected.length === 0} className={PRIMARY_ACTION}>
                            {ta.requirements_add_selected ?? 'Legg til valgte krav'}
                        </button>
                    </div>
                </form>
            )}

            {(options.sources ?? []).length > 0 && (
                <form onSubmit={submitSource} className="space-y-3 border-t border-slate-100 pt-4" data-testid="compliance-audit-source-form">
                    <div>
                        <label htmlFor="compliance-audit-source" className={LABEL}>{ta.requirements_from_source ?? 'Legg til fra kravkilde'}</label>
                        <p id="compliance-audit-source-hint" className={HINT}>
                            {ta.requirements_from_source_hint ?? 'Legger til alle aktive krav fra kilden som ikke allerede er i scope.'}
                        </p>
                        <select
                            id="compliance-audit-source"
                            aria-describedby="compliance-audit-source-hint"
                            value={fromSource.data.source_id}
                            onChange={(event) => fromSource.setData('source_id', event.target.value)}
                            className={`mt-1 ${INPUT}`}
                        >
                            <option value="">{ta.choose_source ?? 'Velg kravkilde'}</option>
                            {options.sources.map((source) => (
                                <option key={source.id} value={source.id}>
                                    {(ta.requirements_from_source_option ?? ':source (:count aktive krav)').replace(':source', source.label).replace(':count', String(source.count))}
                                </option>
                            ))}
                        </select>
                        {fromSource.errors.source_id && <p className={ERROR}>{fromSource.errors.source_id}</p>}
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
 * Krav i scope: the requirements the audit checks, read live. A retired requirement stays and says
 * so. Adding and removing is offered only when the server said this person may change the scope.
 */
export function AuditRequirementScope({ audit, requirements = [], options = null, canManage = false, ta }) {
    const [adding, setAdding] = useState(false);

    const remove = (requirement) => {
        if (! window.confirm(ta.remove_requirement_confirm ?? 'Fjerne kravet fra scope? Kravet blir værende i registeret.')) {
            return;
        }

        router.delete(`/app/compliance/audits/${audit.id}/requirements/${requirement.id}`, { preserveScroll: true });
    };

    return (
        <section className={CARD} aria-labelledby="compliance-audit-requirements-heading" data-testid="compliance-audit-requirements">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 id="compliance-audit-requirements-heading" className="text-xl font-semibold text-slate-950">{ta.requirements_heading ?? 'Krav i scope'}</h2>
                    <p className="mt-1 text-base text-slate-600">{ta.requirements_intro ?? 'Kravene revisjonen skal kontrollere.'}</p>
                </div>
                {canManage && ! adding && (
                    <button type="button" onClick={() => setAdding(true)} className={SECONDARY_ACTION}>{ta.requirements_add ?? 'Legg til krav'}</button>
                )}
            </div>

            {canManage && adding && options && (
                <RequirementPicker auditId={audit.id} options={options} onDone={() => setAdding(false)} ta={ta} />
            )}

            {requirements.length === 0 ? (
                <p className="mt-4 text-base text-slate-700">{ta.requirements_empty ?? 'Ingen krav er lagt til i scope.'}</p>
            ) : (
                <ul className="mt-4 divide-y divide-slate-100">
                    {requirements.map((requirement) => (
                        <li key={requirement.id} className="flex flex-wrap items-start justify-between gap-3 py-3" data-testid="compliance-audit-requirement">
                            <div className="min-w-0 space-y-1">
                                <Link href={requirement.url} className="block break-words text-base font-semibold text-violet-700 hover:text-violet-900">
                                    {requirementLabel(requirement)}
                                </Link>
                                {requirement.source_label && <p className="break-words text-base text-slate-600">{requirement.source_label}</p>}
                                {requirement.status === 'retired' && <StatusBadge tone="slate">{ta.retired_requirement ?? 'Utgått'}</StatusBadge>}
                            </div>
                            {canManage && (
                                <button type="button" onClick={() => remove(requirement)} className={SECONDARY_ACTION}>{ta.remove ?? 'Fjern fra scope'}</button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/**
 * Prosesser i scope: the Kvalitet processes the audit looks at, read live. Drawn only when the server
 * sent them — for someone who can read Kvalitet — so without it the page says nothing at all about
 * processes, not even that there are none.
 */
export function AuditProcessScope({ audit, processes, options = null, canManage = false, ta }) {
    const [adding, setAdding] = useState(false);
    const form = useForm({ quality_process_id: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/compliance/audits/${audit.id}/processes`, {
            preserveScroll: true,
            onSuccess: () => { setAdding(false); form.reset(); },
        });
    };

    const remove = (process) => {
        if (! window.confirm(ta.remove_process_confirm ?? 'Fjerne prosessen fra scope? Prosessen blir værende i Kvalitet.')) {
            return;
        }

        router.delete(`/app/compliance/audits/${audit.id}/processes/${process.id}`, { preserveScroll: true });
    };

    return (
        <section className={CARD} aria-labelledby="compliance-audit-processes-heading" data-testid="compliance-audit-processes">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 id="compliance-audit-processes-heading" className="text-xl font-semibold text-slate-950">{ta.processes_heading ?? 'Prosesser i scope'}</h2>
                    <p className="mt-1 text-base text-slate-600">{ta.processes_intro ?? 'Prosessene i Kvalitet som revisjonen ser på.'}</p>
                </div>
                {canManage && ! adding && (
                    <button type="button" onClick={() => setAdding(true)} className={SECONDARY_ACTION}>{ta.processes_add ?? 'Legg til prosess'}</button>
                )}
            </div>

            {canManage && adding && options && (
                options.length === 0 ? (
                    <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 p-4">
                        <p className="text-base text-slate-700">{ta.processes_no_options ?? 'Det finnes ingen flere prosesser i Kvalitet å legge til.'}</p>
                        <button type="button" onClick={() => setAdding(false)} className={SECONDARY_ACTION}>{ta.cancel ?? 'Avbryt'}</button>
                    </div>
                ) : (
                    <form onSubmit={submit} className="mt-4 space-y-3 rounded-2xl border border-slate-200 p-4" data-testid="compliance-audit-process-form">
                        <div>
                            <label htmlFor="compliance-audit-process" className={LABEL}>{ta.processes_choose ?? 'Velg prosess'}</label>
                            <select
                                id="compliance-audit-process"
                                required
                                value={form.data.quality_process_id}
                                onChange={(event) => form.setData('quality_process_id', event.target.value)}
                                className={`mt-1 ${INPUT}`}
                            >
                                <option value="">{ta.processes_choose ?? 'Velg prosess'}</option>
                                {options.map((process) => (
                                    <option key={process.id} value={process.id}>{qualityItemLabel(process)}</option>
                                ))}
                            </select>
                            {form.errors.quality_process_id && <p className={ERROR}>{form.errors.quality_process_id}</p>}
                        </div>
                        <div className="flex flex-wrap justify-end gap-3">
                            <button type="button" onClick={() => { setAdding(false); form.reset(); form.clearErrors(); }} className={SECONDARY_ACTION}>{ta.cancel ?? 'Avbryt'}</button>
                            <button type="submit" disabled={form.processing || form.data.quality_process_id === ''} className={PRIMARY_ACTION}>{ta.processes_add_submit ?? 'Legg til'}</button>
                        </div>
                    </form>
                )
            )}

            {processes.length === 0 ? (
                <p className="mt-4 text-base text-slate-700">{ta.processes_empty ?? 'Ingen prosesser er lagt til i scope.'}</p>
            ) : (
                <ul className="mt-4 divide-y divide-slate-100">
                    {processes.map((process) => (
                        <li key={process.id} className="flex flex-wrap items-start justify-between gap-3 py-3" data-testid="compliance-audit-process">
                            <div className="min-w-0 space-y-1">
                                <Link href={process.url} className="block break-words text-base font-semibold text-violet-700 hover:text-violet-900">
                                    {qualityItemLabel(process)}
                                </Link>
                                {process.status === 'retired' && <StatusBadge tone="slate">{ta.retired_process ?? 'Utgått i Kvalitet'}</StatusBadge>}
                            </div>
                            {canManage && (
                                <button type="button" onClick={() => remove(process)} className={SECONDARY_ACTION}>{ta.remove ?? 'Fjern fra scope'}</button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
