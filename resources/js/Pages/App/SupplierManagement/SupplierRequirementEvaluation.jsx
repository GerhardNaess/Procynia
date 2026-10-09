import { useForm } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DISCLOSURE_INLINE, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { formatLongDate } from '../Improvements/improvementStatus';
import RequiredMark from '../Risk/RequiredMark';
import {
    DISPLAY_STATUS_TONES,
    displayStatusLabel,
    documentOption,
    evaluationFormData,
    evaluationMissing,
    snapshotDocument,
    toggleDocument,
} from './requirementEvaluations';

const HINT = 'text-base text-slate-600';
const ERROR = 'text-base text-rose-700';
const LABEL = 'block text-base font-semibold text-slate-900';
const INPUT = 'mt-1 min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';

/**
 * Kontroller krav (docs/supplier-assurance-v2-plan.md §8, §10.2): the person reads the requirement,
 * names the documentation they relied on, chooses the result and explains it. Choosing a document
 * is evidence, not a conclusion — nothing here picks the result. The documentation itself is never
 * edited here; without any, the person is sent to Dokumentasjon.
 */
export function EvaluationForm({ supplierId, row, options, onDone, locale = 'no', tr }) {
    const c = tr.control ?? {};
    const e = c.evaluation ?? {};
    const form = useForm(evaluationFormData(row.id, options.today));
    const missing = evaluationMissing(form.data);
    const id = (field) => `evaluation-${row.id}-${field}`;
    const documents = options.documents ?? [];

    const send = (event) => {
        event.preventDefault();
        form.post(`/app/supplier-management/${supplierId}/requirement-evaluations`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={send} className="mt-4 min-w-0 space-y-5 rounded-xl border border-slate-200 bg-slate-50 p-4" data-testid="evaluation-form">
            <div className="min-w-0">
                <h4 className="text-lg font-semibold text-slate-950">{e.heading ?? 'Kontroller krav'}</h4>
                <p className="break-words text-base font-semibold text-slate-900">{row.title}</p>
                <p className={HINT}>{e.intro}</p>
            </div>

            {(row.guidance || row.basis_text) && (
                <dl className="min-w-0 space-y-2" data-testid="evaluation-requirement">
                    {row.guidance && (
                        <div>
                            <dt className="text-base font-semibold text-slate-600">{e.guidance ?? 'Slik kontrollerer vi det'}</dt>
                            <dd className="whitespace-pre-line break-words text-base text-slate-900">{row.guidance}</dd>
                        </div>
                    )}
                    {row.basis_text && (
                        <div>
                            <dt className="text-base font-semibold text-slate-600">{c.basis ?? 'Grunnlag'}</dt>
                            <dd className="break-words text-base text-slate-900">{row.basis_text}</dd>
                        </div>
                    )}
                </dl>
            )}

            <fieldset className="min-w-0">
                <legend className={LABEL}>{e.documents ?? 'Dokumentasjon lagt til grunn'}</legend>
                <p className={HINT}>{e.documents_hint}</p>
                {documents.length === 0 ? (
                    <div className="mt-2" data-testid="evaluation-no-documents">
                        <p className="text-base text-slate-800">{e.documents_none ?? 'Ingen dokumentasjon er registrert for leverandøren.'}</p>
                        <p className={HINT}>{e.documents_register}</p>
                        <a href="#supplier-documents-heading" className="text-base font-semibold text-violet-700 hover:text-violet-900">{e.go_to_documents ?? 'Gå til Dokumentasjon'}</a>
                    </div>
                ) : (
                    <ul className="mt-2 space-y-2">
                        {documents.map((document) => {
                            const option = documentOption(document, tr);

                            return (
                                <li key={document.id}>
                                    <label className="flex min-h-10 items-start gap-3 rounded-xl border border-slate-200 bg-white p-3 text-base text-slate-900" data-testid="evaluation-document-option">
                                        <input
                                            type="checkbox"
                                            checked={form.data.document_ids.includes(document.id)}
                                            onChange={() => form.setData('document_ids', toggleDocument(form.data.document_ids, document.id))}
                                            className="mt-1 h-5 w-5 shrink-0"
                                        />
                                        <span className="min-w-0 break-words">
                                            <span className="block text-slate-600">{option.type}</span>
                                            <span className="block font-semibold">{option.title}</span>
                                            {option.status && <span className="block text-amber-800">{option.status}</span>}
                                        </span>
                                    </label>
                                </li>
                            );
                        })}
                    </ul>
                )}
                {form.errors.document_ids && <p className={ERROR}>{form.errors.document_ids}</p>}
            </fieldset>

            <fieldset className="min-w-0">
                <legend className={LABEL}>{e.status ?? 'Kontrollresultat'}<RequiredMark /></legend>
                <div className="mt-1 space-y-1">
                    {(options.statuses ?? []).map((status) => (
                        <label key={status} className="flex min-h-10 items-start gap-3 py-1 text-base text-slate-900">
                            <input
                                type="radio"
                                name={id('status')}
                                value={status}
                                checked={form.data.status === status}
                                onChange={() => form.setData('status', status)}
                                className="mt-1 h-5 w-5 shrink-0"
                            />
                            <span className="min-w-0 break-words">
                                <span className="font-semibold">{displayStatusLabel(status, tr)}</span>
                                <span className="block text-slate-600">{e.status_hints?.[status]}</span>
                            </span>
                        </label>
                    ))}
                </div>
                {form.errors.status && <p className={ERROR}>{form.errors.status}</p>}
                {missing.includes('document') && <p className="text-base text-amber-800" data-testid="evaluation-requires-document">{e.requires_document ?? 'Velg minst ett dokument for å registrere «Dokumentert».'}</p>}
            </fieldset>

            {form.data.status === 'temporarily_accepted' && (
                <div>
                    <label htmlFor={id('accepted-until')} className={LABEL}>{e.accepted_until ?? 'Akseptert til'}<RequiredMark /></label>
                    <p className={HINT}>{e.accepted_until_hint}</p>
                    <input
                        id={id('accepted-until')}
                        type="date"
                        required
                        min={options.today}
                        max={options.max_accepted_until}
                        value={form.data.accepted_until}
                        onChange={(event) => form.setData('accepted_until', event.target.value)}
                        className={`${INPUT} sm:max-w-xs`}
                    />
                    {form.errors.accepted_until && <p className={ERROR}>{form.errors.accepted_until}</p>}
                </div>
            )}

            <div>
                <label htmlFor={id('rationale')} className={LABEL}>{e.rationale ?? 'Begrunnelse'}<RequiredMark /></label>
                <p className={HINT}>{e.rationale_hint}</p>
                <textarea id={id('rationale')} rows={4} required aria-required value={form.data.rationale} onChange={(event) => form.setData('rationale', event.target.value)} className={INPUT} />
                {form.errors.rationale && <p className={ERROR}>{form.errors.rationale}</p>}
            </div>

            <div>
                <label htmlFor={id('evaluated-on')} className={LABEL}>{e.evaluated_on ?? 'Kontrolldato'}<RequiredMark /></label>
                <p className={HINT}>{e.evaluated_on_hint}</p>
                <input
                    id={id('evaluated-on')}
                    type="date"
                    required
                    max={options.today}
                    value={form.data.evaluated_on}
                    onChange={(event) => form.setData('evaluated_on', event.target.value)}
                    className={`${INPUT} sm:max-w-xs`}
                />
                {form.errors.evaluated_on && <p className={ERROR}>{form.errors.evaluated_on}</p>}
            </div>
            {form.errors.requirement_id && <p className={ERROR}>{form.errors.requirement_id}</p>}

            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing || missing.length > 0} className={PRIMARY_ACTION}>{e.submit ?? 'Registrer kontroll'}</button>
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
            </div>
        </form>
    );
}

/** One document as it was at the control, and — kept apart, labelled «Nå» — what differs today. */
function SnapshotDocument({ document, locale, tr }) {
    const ev = tr.control?.evaluations ?? {};
    const d = tr.documents ?? {};
    const doc = snapshotDocument(document, tr);
    const validity = [
        doc.validFrom && `${d.valid_from ?? 'Gyldig fra'} ${formatLongDate(doc.validFrom, locale)}`,
        doc.validUntil && `${d.valid_until ?? 'Gyldig til'} ${formatLongDate(doc.validUntil, locale)}`,
    ].filter(Boolean).join(' · ');

    return (
        <li className="min-w-0 rounded-lg border border-slate-200 bg-white p-3" data-testid="evaluation-snapshot-document">
            <p className="text-base text-slate-600">{doc.type}</p>
            <p className="break-words text-base font-semibold text-slate-900" data-testid="snapshot-title">{doc.title}{doc.standard ? ` (${doc.standard})` : ''}</p>
            {validity && <p className="break-words text-base text-slate-700" data-testid="snapshot-validity">{validity}</p>}
            {doc.location && <p className="break-all text-base text-slate-700">{d.location_short ?? 'Plassering'}: {doc.location}</p>}
            {doc.fileName && (
                <p className="break-all text-base text-slate-700" data-testid="snapshot-file">
                    {d.file ?? 'Fil'}: {doc.fileName} · <span title={doc.fileSha256}>{d.file_checksum ?? 'SHA-256'} {doc.fileSha256?.slice(0, 12)}…</span>
                </p>
            )}
            {doc.now && (
                <div className="mt-2 border-t border-slate-100 pt-2" data-testid="snapshot-now">
                    {doc.changed && <p className="text-base text-amber-800">{ev.changed_since ?? 'Dokumentet er endret etter kontrollen.'}</p>}
                    <p className="break-words text-base text-slate-700">
                        <span className="font-semibold">{ev.now ?? 'Nå'}:</span> {doc.now.title} · {doc.now.status}
                    </p>
                </div>
            )}
        </li>
    );
}

/**
 * The controls of one requirement, newest first. Each one reads on its own: the date, the result,
 * who controlled it, why, and the documentation as it was registered then — never rewritten.
 */
export function EvaluationHistory({ evaluations = [], locale = 'no', tr, testId = 'evaluation-history' }) {
    const c = tr.control ?? {};
    const ev = c.evaluations ?? {};

    if (evaluations.length === 0) {
        return null;
    }

    return (
        <details className="mt-3 min-w-0" data-testid={testId}>
            <summary className={`${DISCLOSURE_INLINE} cursor-pointer`}>{ev.heading ?? 'Kontroller'} ({evaluations.length})</summary>
            <ol className="mt-3 space-y-3">
                {evaluations.map((entry) => (
                    <li key={entry.id} className="min-w-0 border-l-2 border-slate-200 pl-3" data-testid="evaluation-entry">
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge tone={DISPLAY_STATUS_TONES[entry.status] ?? 'slate'}>{displayStatusLabel(entry.status, tr)}</StatusBadge>
                            <span className="text-base text-slate-600">
                                {(ev.controlled ?? ':date – kontrollert av :name')
                                    .replace(':date', formatLongDate(entry.evaluated_on, locale))
                                    .replace(':name', entry.evaluated_by_name ?? tr.unknown_user ?? 'en tidligere bruker')}
                            </span>
                        </div>
                        {entry.accepted_until && (
                            <p className="mt-1 text-base text-slate-700">{(c.accepted_until ?? 'Akseptert til :date').replace(':date', formatLongDate(entry.accepted_until, locale))}</p>
                        )}
                        <p className="mt-1 whitespace-pre-line break-words text-base text-slate-900">{c.reason_label ?? 'Begrunnelse'}: {entry.rationale}</p>
                        <p className="mt-1 break-words text-base text-slate-600">{ev.applied_because ?? 'Gjaldt ved kontrollen'}: {entry.applicability_reason}</p>
                        <p className="mt-2 text-base font-semibold text-slate-900">{ev.documents_at_control ?? 'Dokumentasjon ved kontrollen'}</p>
                        {entry.documents.length === 0 ? (
                            <p className="text-base text-slate-700">{ev.no_documents ?? 'Ingen dokumentasjon oppgitt.'}</p>
                        ) : (
                            <>
                                <p className={HINT}>{ev.snapshot_note}</p>
                                <ul className="mt-2 space-y-2">
                                    {entry.documents.map((document) => <SnapshotDocument key={document.id} document={document} locale={locale} tr={tr} />)}
                                </ul>
                            </>
                        )}
                    </li>
                ))}
            </ol>
        </details>
    );
}
