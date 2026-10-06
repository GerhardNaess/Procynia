import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import StatusBadge from '../../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import {
    LINK_FILTER_THRESHOLD,
    QUALITY_STATUS_TONES,
    controlFacts,
    evidenceAddedLabel,
    filterQualityOptions,
    qualityItemLabel,
    qualityOptionLabel,
} from './complianceQuality';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';
const TERM = 'text-base font-semibold text-slate-600';
const VALUE = 'mt-1 whitespace-pre-line break-words text-base text-slate-900';

/**
 * Legg til prosess / Legg til kontroll: one select of what Kvalitet has that is not linked yet,
 * with a search field once the list gets long. The server checks every id again.
 */
function LinkPicker({ kind, requirementId, options, onDone, tq }) {
    const field = kind === 'process' ? 'quality_process_id' : 'control_item_id';
    const form = useForm({ [field]: '' });
    const [query, setQuery] = useState('');
    const searchable = options.length > LINK_FILTER_THRESHOLD;
    const shown = searchable ? filterQualityOptions(options, query, form.data[field]) : options;
    const id = `compliance-${kind}-link`;

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/compliance/requirements/${requirementId}/${kind === 'process' ? 'processes' : 'controls'}`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="mt-3 space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4" data-testid={`${id}-form`}>
            {options.length === 0 ? (
                <p className="text-base text-slate-700">{kind === 'process' ? (tq.no_process_options ?? 'Det finnes ingen flere prosesser i Kvalitet å koble til.') : (tq.no_control_options ?? 'Det finnes ingen flere kontroller i Kvalitet å koble til.')}</p>
            ) : (
                <>
                    {searchable && (
                        <div>
                            <label htmlFor={`${id}-filter`} className={LABEL}>{tq.filter_label ?? 'Søk'}</label>
                            <input
                                id={`${id}-filter`}
                                type="search"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder={kind === 'process' ? (tq.filter_placeholder_process ?? 'Navn eller kode') : (tq.filter_placeholder_control ?? 'Navn, kode, prosess eller aktivitet')}
                                className={`mt-1 ${INPUT}`}
                            />
                            {shown.length === 0 && <p className="mt-1 text-base text-slate-600">{tq.filter_none ?? 'Ingenting passer søket.'}</p>}
                        </div>
                    )}
                    <div>
                        <label htmlFor={id} className={LABEL}>{kind === 'process' ? (tq.choose_process ?? 'Velg prosess') : (tq.choose_control ?? 'Velg kontroll')}</label>
                        <select id={id} value={form.data[field]} onChange={(event) => form.setData(field, event.target.value)} className={`mt-1 ${INPUT}`}>
                            <option value="">{tq.choose_placeholder ?? 'Velg …'}</option>
                            {shown.map((option) => <option key={option.id} value={option.id}>{qualityOptionLabel(option)}</option>)}
                        </select>
                    </div>
                </>
            )}
            {form.errors[field] && <p className="text-base text-rose-700">{form.errors[field]}</p>}
            {form.errors.requirement && <p className="text-base text-rose-700">{form.errors.requirement}</p>}
            <div className="flex flex-wrap gap-2">
                {options.length > 0 && (
                    <button type="submit" disabled={form.processing || form.data[field] === ''} className={PRIMARY_ACTION}>{tq.save ?? 'Koble'}</button>
                )}
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tq.cancel ?? 'Avbryt'}</button>
            </div>
        </form>
    );
}

/** One piece of evidence, as Kvalitet has it. Stacked, so it reads on a phone. */
function EvidenceEntry({ evidence, tq }) {
    const added = evidenceAddedLabel(evidence, tq);

    return (
        <li className="space-y-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2" data-testid="compliance-evidence">
            <p className="break-words text-base font-semibold text-slate-900">{evidence.title}</p>
            {evidence.description && <p className="whitespace-pre-line break-words text-base text-slate-700">{evidence.description}</p>}
            {evidence.download_url ? (
                <p className="break-words text-base">
                    <span className="font-semibold text-slate-600">{tq.evidence_file ?? 'Fil'}: </span>
                    <a href={evidence.download_url} className="font-semibold text-violet-700 hover:text-violet-900">{evidence.filename}</a>
                </p>
            ) : evidence.document_removed && (
                <p className="text-base text-slate-600">{tq.evidence_file_removed ?? 'Filen er slettet'}</p>
            )}
            {added && <p className="text-base text-slate-600">{added}</p>}
        </li>
    );
}

/** One linked control: its fields, where it sits in Kvalitet's flows, and its evidence. */
function ControlCard({ control, canManage, onUnlink, tq, qualityStatuses, frequencies }) {
    const facts = controlFacts(control, tq, frequencies);
    const retired = control.status === 'retired';

    return (
        <li className="space-y-3 rounded-2xl border border-slate-200 p-4" data-testid="compliance-control">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <a href={control.url} className="break-words text-base font-semibold text-violet-700 hover:text-violet-900">{qualityItemLabel(control)}</a>
                        <StatusBadge tone={QUALITY_STATUS_TONES[control.status] ?? 'slate'}>{qualityStatuses[control.status] ?? control.status}</StatusBadge>
                    </div>
                    {retired && <p className="text-base font-semibold text-amber-800">{tq.retired_control ?? 'Kontrollen er utgått i Kvalitet.'}</p>}
                </div>
                {canManage && (
                    <button type="button" onClick={() => onUnlink(control)} className={DESTRUCTIVE_ACTION}>{tq.unlink ?? 'Fjern kobling'}</button>
                )}
            </div>

            {facts.length > 0 && (
                <dl className="grid gap-3 sm:grid-cols-2">
                    {facts.map((fact) => (
                        <div key={fact.key}>
                            <dt className={TERM}>{fact.label}</dt>
                            <dd className={VALUE}>{fact.value}</dd>
                        </div>
                    ))}
                </dl>
            )}
            {control.placements?.length > 0 && (
                <p className="break-words text-base text-slate-700"><span className="font-semibold">{tq.used_in ?? 'Brukes i'}:</span> {control.placements.join('; ')}</p>
            )}

            <div>
                <h4 className="text-base font-semibold text-slate-900">{tq.evidence_heading ?? 'Evidens'}</h4>
                {control.evidence?.length > 0 ? (
                    <ul className="mt-2 space-y-2">
                        {control.evidence.map((evidence) => <EvidenceEntry key={evidence.id} evidence={evidence} tq={tq} />)}
                    </ul>
                ) : (
                    <p className="mt-1 text-base text-slate-600">{tq.evidence_empty ?? 'Ingen evidens er registrert på kontrollen.'}</p>
                )}
            </div>
        </li>
    );
}

/**
 * Hvordan kravet oppfylles: the Kvalitet processes and controls the requirement is met through,
 * read live from Kvalitet, with each control's evidence read-only.
 *
 * Rendered only when the server sent `context` — which it does only to someone who can read
 * Kvalitet. Without it the page has no trace of this section: no names, no count. Linking and
 * unlinking are offered only when the server says so (compliance.edit, Kvalitet read access, an
 * active requirement), and the server refuses them otherwise. Nothing here judges compliance: that
 * a control has evidence is shown, never turned into a result.
 */
export default function RequirementQualityContext({ item, context, options, canManage, tr, qualityTr = {} }) {
    const tq = { cancel: tr.cancel, ...(tr.quality ?? {}) };
    const qualityStatuses = qualityTr.statuses ?? {};
    const frequencies = qualityTr.frequencies ?? {};
    // Which picker is open: 'process', 'control' or none.
    const [picker, setPicker] = useState(null);
    const processes = context.processes ?? [];
    const controls = context.controls ?? [];
    const active = item.status === 'active';

    const unlink = (kind, linked) => {
        const message = kind === 'process'
            ? (tq.unlink_process_confirm ?? 'Fjerne koblingen? Prosessen blir værende i Kvalitet.')
            : (tq.unlink_control_confirm ?? 'Fjerne koblingen? Kontrollen og evidensen blir værende i Kvalitet.');

        if (! window.confirm(message)) {
            return;
        }

        router.delete(`/app/compliance/requirements/${item.id}/${kind === 'process' ? 'processes' : 'controls'}/${linked.id}`, { preserveScroll: true });
    };

    return (
        <section className={CARD} aria-labelledby="compliance-quality-heading" data-testid="compliance-quality">
            <h2 id="compliance-quality-heading" className="text-xl font-semibold text-slate-950">{tq.heading ?? 'Hvordan kravet oppfylles'}</h2>
            <p className="mt-1 text-base text-slate-600">{tq.intro ?? 'Prosessene og kontrollene i Kvalitet som kravet oppfylles gjennom.'}</p>
            {! active && (
                <p className="mt-3 text-base text-slate-700">{tq.retired_requirement_note ?? 'Kravet er utgått. Koblingene vises som de var, og kan ikke endres før kravet er gjenåpnet.'}</p>
            )}

            <div className="mt-5" data-testid="compliance-processes">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h3 className="text-lg font-semibold text-slate-950">{tq.processes_heading ?? 'Relevante prosesser'}</h3>
                    {canManage && picker === null && (
                        <button type="button" onClick={() => setPicker('process')} className={SECONDARY_ACTION}>{tq.link_process ?? 'Legg til prosess'}</button>
                    )}
                </div>
                {picker === 'process' && <LinkPicker kind="process" requirementId={item.id} options={options?.processes ?? []} onDone={() => setPicker(null)} tq={tq} />}
                {processes.length === 0 ? (
                    <p className="mt-2 text-base text-slate-600">{tq.processes_empty ?? 'Ingen prosesser er koblet til kravet.'}</p>
                ) : (
                    <ul className="mt-2 divide-y divide-slate-100">
                        {processes.map((process) => (
                            <li key={process.id} className="flex flex-wrap items-center justify-between gap-3 py-3" data-testid="compliance-process">
                                <div className="flex min-w-0 flex-wrap items-center gap-2">
                                    <a href={process.url} className="break-words text-base font-semibold text-violet-700 hover:text-violet-900">{qualityItemLabel(process)}</a>
                                    <StatusBadge tone={QUALITY_STATUS_TONES[process.status] ?? 'slate'}>{qualityStatuses[process.status] ?? process.status}</StatusBadge>
                                </div>
                                {canManage && (
                                    <button type="button" onClick={() => unlink('process', process)} className={DESTRUCTIVE_ACTION}>{tq.unlink ?? 'Fjern kobling'}</button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div className="mt-6 border-t border-slate-100 pt-5" data-testid="compliance-controls">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h3 className="text-lg font-semibold text-slate-950">{tq.controls_heading ?? 'Kontroller'}</h3>
                    {canManage && picker === null && (
                        <button type="button" onClick={() => setPicker('control')} className={SECONDARY_ACTION}>{tq.link_control ?? 'Legg til kontroll'}</button>
                    )}
                </div>
                {picker === 'control' && <LinkPicker kind="control" requirementId={item.id} options={options?.controls ?? []} onDone={() => setPicker(null)} tq={tq} />}
                {controls.length === 0 ? (
                    <p className="mt-2 text-base text-slate-600">{tq.controls_empty ?? 'Ingen kontroller er koblet til kravet.'}</p>
                ) : (
                    <>
                        <p className="mt-2 text-base text-slate-600">{tq.evidence_note ?? 'Evidensen vises fra Kvalitet og bestemmer ikke etterlevelsen. Etterlevelsen vurderes under Etterlevelse.'}</p>
                        <ul className="mt-3 space-y-3">
                            {controls.map((control) => (
                                <ControlCard
                                    key={control.id}
                                    control={control}
                                    canManage={canManage}
                                    onUnlink={(linked) => unlink('control', linked)}
                                    tq={tq}
                                    qualityStatuses={qualityStatuses}
                                    frequencies={frequencies}
                                />
                            ))}
                        </ul>
                    </>
                )}
            </div>
        </section>
    );
}
