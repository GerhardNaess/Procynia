import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DISCLOSURE_INLINE, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { useFocusFirstError } from '../../../Support/focusManagement';
import { INPUT as FIELD_INPUT, TEXTAREA } from '../../../Support/formStyles';
import { formatLongDate } from '../Improvements/improvementStatus';
import SupplierField, { BUSY, FieldError, RequiredNote, RequiredStar, groupA11y } from './SupplierField';
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
const INPUT = `mt-1 ${FIELD_INPUT}`;

/**
 * Kontroller krav (docs/supplier-assurance-v2-plan.md §8, §10.2): the person reads the requirement,
 * names the documentation they relied on, chooses the result and explains it. Choosing a document
 * is evidence, not a conclusion — nothing here picks the result. The documentation itself is never
 * edited here; without any, the person is sent to Dokumentasjon.
 *
 * The groups and fields are tied to their hints and errors, a refused save puts focus on the first
 * wrong field (A11Y-013), and «Registrer kontroll» stays focusable while it cannot be used yet:
 * aria-disabled, described by why (A11Y-143).
 */
export function EvaluationForm({ supplierId, row, options, onDone, locale = 'no', tr }) {
    const c = tr.control ?? {};
    const e = c.evaluation ?? {};
    const form = useForm(evaluationFormData(row.id, options.today));
    const missing = evaluationMissing(form.data);
    const id = (field) => `evaluation-${row.id}-${field}`;
    const documents = options.documents ?? [];
    const formRef = useRef(null);
    const focusError = useFocusFirstError(formRef);
    const documentGroup = groupA11y(id('documents'), { hint: Boolean(e.documents_hint), error: form.errors.document_ids });
    const statusGroup = groupA11y(id('status'), { error: form.errors.status });
    const requiresDocument = missing.includes('document');

    const send = (event) => {
        event.preventDefault();

        if (form.processing || missing.length > 0) {
            return;
        }

        form.post(`/app/supplier-management/${supplierId}/requirement-evaluations`, { preserveScroll: true, onSuccess: onDone, onError: focusError });
    };

    return (
        <form ref={formRef} onSubmit={send} className="mt-4 min-w-0 space-y-5 rounded-xl border border-slate-200 bg-slate-50 p-4" data-testid="evaluation-form">
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

            <fieldset className="min-w-0" {...documentGroup.groupProps}>
                <legend className={LABEL}>{e.documents ?? 'Dokumentasjon lagt til grunn'}</legend>
                {e.documents_hint && <p id={documentGroup.hintId} className={HINT}>{e.documents_hint}</p>}
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
                                            {...documentGroup.optionProps}
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
                <FieldError id={documentGroup.errorId} message={form.errors.document_ids} className={ERROR} />
            </fieldset>

            <fieldset className="min-w-0" {...statusGroup.groupProps}>
                <legend className={LABEL}>{e.status ?? 'Kontrollresultat'}<RequiredStar /></legend>
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
                                {...statusGroup.optionProps}
                            />
                            <span className="min-w-0 break-words">
                                <span className="font-semibold">{displayStatusLabel(status, tr)}</span>
                                <span className="block text-slate-600">{e.status_hints?.[status]}</span>
                            </span>
                        </label>
                    ))}
                </div>
                <FieldError id={statusGroup.errorId} message={form.errors.status} className={ERROR} />
                {requiresDocument && <p id={id('requires-document')} className="text-base text-amber-800" data-testid="evaluation-requires-document">{e.requires_document ?? 'Velg minst ett dokument for å registrere «Dokumentert».'}</p>}
            </fieldset>

            {form.data.status === 'temporarily_accepted' && (
                <SupplierField id={id('accepted-until')} label={e.accepted_until ?? 'Akseptert til'} labelClassName={LABEL} hint={e.accepted_until_hint} hintClassName={HINT} error={form.errors.accepted_until} errorClassName={ERROR} required>
                    {(field) => (
                        <input
                            {...field}
                            type="date"
                            required
                            min={options.today}
                            max={options.max_accepted_until}
                            value={form.data.accepted_until}
                            onChange={(event) => form.setData('accepted_until', event.target.value)}
                            className={`${INPUT} sm:max-w-xs`}
                        />
                    )}
                </SupplierField>
            )}

            <SupplierField id={id('rationale')} label={e.rationale ?? 'Begrunnelse'} labelClassName={LABEL} hint={e.rationale_hint} hintClassName={HINT} error={form.errors.rationale} errorClassName={ERROR} required>
                {(field) => <textarea {...field} rows={4} required value={form.data.rationale} onChange={(event) => form.setData('rationale', event.target.value)} className={`mt-1 ${TEXTAREA}`} />}
            </SupplierField>

            <SupplierField id={id('evaluated-on')} label={e.evaluated_on ?? 'Kontrolldato'} labelClassName={LABEL} hint={e.evaluated_on_hint} hintClassName={HINT} error={form.errors.evaluated_on} errorClassName={ERROR} required>
                {(field) => (
                    <input
                        {...field}
                        type="date"
                        required
                        max={options.today}
                        value={form.data.evaluated_on}
                        onChange={(event) => form.setData('evaluated_on', event.target.value)}
                        className={`${INPUT} sm:max-w-xs`}
                    />
                )}
            </SupplierField>
            <FieldError message={form.errors.requirement_id} className={ERROR} summary />

            <RequiredNote tr={tr} />

            <div className="flex flex-wrap gap-2">
                <button
                    type="submit"
                    aria-disabled={form.processing || missing.length > 0 || undefined}
                    aria-describedby={requiresDocument ? id('requires-document') : undefined}
                    className={`${PRIMARY_ACTION} ${BUSY}`}
                >
                    {e.submit ?? 'Registrer kontroll'}
                </button>
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
