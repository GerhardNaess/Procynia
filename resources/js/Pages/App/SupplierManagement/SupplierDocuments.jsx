import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { formatLongDate } from '../Improvements/improvementStatus';
import RequiredMark from '../Risk/RequiredMark';
import {
    DOCUMENT_STATUS_TONES,
    documentFormData,
    documentStatusLabel,
    documentTypeLabel,
    fileActions,
    fileSummary,
    locationHref,
} from './supplierManagement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const TERM = 'text-base font-semibold text-slate-600';
const VALUE = 'mt-1 break-words text-base text-slate-900';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-900';
const HINT = 'mt-1 text-base text-slate-600';
const ERROR = 'mt-1 text-base text-rose-700';
const FILE_INPUT = 'mt-1 block w-full min-w-0 text-base text-slate-900 file:mr-3 file:min-h-10 file:rounded-xl file:border file:border-slate-200 file:bg-white file:px-3 file:py-2 file:text-base file:font-semibold file:text-slate-900';

const fill = (text, values) => Object.entries(values).reduce((out, [key, value]) => out.replace(`:${key}`, String(value)), text ?? '');

/** The file input, with what is accepted said in words. */
function FileField({ id, accept, form, required = false, d }) {
    return (
        <div>
            <label htmlFor={id} className={LABEL}>{d.file ?? 'Fil'}{required && <RequiredMark />}</label>
            <p id={`${id}-hint`} className={HINT}>{required ? (d.file_hint_required ?? 'PDF, Word (.docx), Excel (.xlsx), PNG eller JPG. Maks 20 MB.') : (d.file_hint ?? 'Valgfritt. PDF, Word (.docx), Excel (.xlsx), PNG eller JPG. Maks 20 MB.')}</p>
            <input
                id={id}
                type="file"
                accept={accept}
                required={required}
                aria-required={required || undefined}
                aria-describedby={`${id}-hint`}
                onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                className={FILE_INPUT}
                data-testid="document-file-input"
            />
            {form.progress && <progress value={form.progress.percentage} max="100" className="mt-2 w-full">{form.progress.percentage}%</progress>}
            {form.errors.file && <p className={ERROR}>{form.errors.file}</p>}
        </div>
    );
}

/** Last opp fil / Erstatt fil on a row that may change its file. */
function FileForm({ supplierId, document, replace, accept, onDone, tr }) {
    const d = tr.documents ?? {};
    const form = useForm({ file: null });

    const send = (event) => {
        event.preventDefault();
        form.post(`/app/supplier-management/${supplierId}/documents/${document.id}/file`, { preserveScroll: true, forceFormData: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={send} className="mt-4 space-y-4 border-t border-slate-100 pt-4" data-testid="document-file-form">
            <div>
                <h3 className="text-lg font-semibold text-slate-950">{replace ? (d.file_replace_heading ?? 'Erstatt fil') : (d.file_upload_heading ?? 'Last opp fil')}</h3>
                {replace && <p className={HINT}>{d.file_replace_intro ?? 'Den nye filen erstatter den forrige. Er dette en ny utgave av dokumentet, bruk «Registrer fornyet» i stedet.'}</p>}
            </div>
            <FileField id={`supplier-document-${document.id}-file`} accept={accept} form={form} required d={d} />
            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                <button type="submit" disabled={form.processing || ! form.data.file} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (d.file_submit ?? 'Last opp')}
                </button>
            </div>
        </form>
    );
}

/** The row's file: download it, what it is and who uploaded it — or that there is none. */
function DocumentFile({ document, date, locale, d }) {
    const file = document.file;
    const uploaded = file?.uploaded_at
        ? fill(file.uploaded_by_name ? (d.file_uploaded ?? 'Lastet opp :date av :name') : (d.file_uploaded_no_name ?? 'Lastet opp :date'), { date: date(file.uploaded_at), name: file.uploaded_by_name ?? '' })
        : null;

    return (
        <div className="mt-3 min-w-0" data-testid="document-file">
            <p className={TERM}>{d.file ?? 'Fil'}</p>
            {file ? (
                <>
                    {file.downloadable ? (
                        <a href={file.download_url} download className="mt-1 inline-block break-words text-base font-semibold text-violet-700 hover:text-violet-900" data-testid="document-file-download">
                            {fill(d.file_download ?? 'Last ned «:name»', { name: file.name })}
                        </a>
                    ) : (
                        <p className="mt-1 break-words text-base text-slate-900">{file.name} – {d.file_blocked ?? 'kan ikke lastes ned før filen er kontrollert for skadelig innhold.'}</p>
                    )}
                    <p className="mt-1 text-base text-slate-600" data-testid="document-file-summary">{[fileSummary(file, locale), uploaded].filter(Boolean).join(' · ')}</p>
                    {document.file_locked && <p className="mt-1 text-base text-slate-600" data-testid="document-file-locked">{d.file_locked ?? 'Filen er brukt som grunnlag i en kontroll og kan ikke byttes eller fjernes. Har dere fått en ny utgave, bruk «Registrer fornyet».'}</p>}
                </>
            ) : (
                <p className="mt-1 text-base text-slate-600" data-testid="document-file-none">{d.file_none ?? 'Ingen fil er lastet opp.'}</p>
            )}
        </div>
    );
}

/** Where the document is kept: a link when it is a web address, otherwise the reference as text. */
function Location({ location, d }) {
    if (! location) {
        return <span className="text-slate-600">{d.no_location ?? 'Plassering er ikke registrert'}</span>;
    }

    const href = locationHref(location);

    return href
        ? <a href={href} target="_blank" rel="noopener noreferrer" className="break-all font-semibold text-violet-700 hover:text-violet-900">{location}</a>
        : <span className="whitespace-pre-line">{location}</span>;
}

/**
 * Legg til, Rediger and Registrer fornyet share one form. A renewal keeps the type of the document
 * it renews, so the type is shown, not asked.
 */
function DocumentForm({ supplierId, mode, document, types, standards = [], accept, onDone, tr }) {
    const d = tr.documents ?? {};
    // Legg til and Registrer fornyet may bring the file along; Rediger changes the description only.
    const form = useForm(mode === 'edit' ? documentFormData(mode, document) : { ...documentFormData(mode, document), file: null });
    const id = (field) => `supplier-document-${field}`;

    const send = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone, forceFormData: mode !== 'edit' && form.data.file !== null };

        if (mode === 'edit') {
            form.patch(`/app/supplier-management/${supplierId}/documents/${document.id}`, options);
        } else if (mode === 'renew') {
            form.post(`/app/supplier-management/${supplierId}/documents/${document.id}/renew`, options);
        } else {
            form.post(`/app/supplier-management/${supplierId}/documents`, options);
        }
    };

    const heading = { create: d.add_heading ?? 'Legg til dokumentasjon', edit: d.edit_heading ?? 'Rediger dokumentasjon', renew: d.renew_heading ?? 'Registrer fornyet dokumentasjon' }[mode];

    return (
        <form onSubmit={send} className="mt-4 space-y-5 border-t border-slate-100 pt-5" data-testid="document-form">
            <div>
                <h3 className="text-lg font-semibold text-slate-950">{heading}</h3>
                {mode === 'renew' && <p className={HINT}>{d.renew_intro ?? 'Registrer den nye utgaven med ny gyldighet og plassering. Den forrige blir stående og markeres som erstattet.'}</p>}
                {mode === 'edit' && document?.deletable === false && <p className={HINT} data-testid="document-edit-used-hint">{d.edit_used_hint}</p>}
            </div>

            {mode === 'renew' ? (
                <div>
                    <p className={LABEL}>{d.type ?? 'Type'}</p>
                    <p className={VALUE}>{documentTypeLabel(document.document_type, tr)}</p>
                </div>
            ) : (
                <div>
                    <label htmlFor={id('type')} className={LABEL}>{d.type ?? 'Type'}<RequiredMark /></label>
                    <select
                        id={id('type')}
                        required
                        aria-required="true"
                        value={form.data.document_type}
                        onChange={(event) => form.setData('document_type', event.target.value)}
                        className={`mt-1 ${INPUT} md:max-w-md`}
                    >
                        <option value="">{d.choose_type ?? 'Velg type'}</option>
                        {types.map((type) => <option key={type} value={type}>{documentTypeLabel(type, tr)}</option>)}
                    </select>
                    {form.errors.document_type && <p className={ERROR}>{form.errors.document_type}</p>}
                </div>
            )}

            <div>
                <label htmlFor={id('title')} className={LABEL}>{d.title ?? 'Navn'}<RequiredMark /></label>
                <p id={id('title-hint')} className={HINT}>{d.title_hint ?? 'For eksempel «ISO 27001-sertifikat 2026» eller «Rammeavtale drift».'}</p>
                <input
                    id={id('title')}
                    type="text"
                    required
                    aria-required="true"
                    aria-describedby={id('title-hint')}
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.title && <p className={ERROR}>{form.errors.title}</p>}
            </div>

            <div>
                <label htmlFor={id('standard')} className={LABEL}>{d.standard ?? 'Standard'}</label>
                <p id={id('standard-hint')} className={HINT}>{d.standard_hint ?? 'Valgfritt. For eksempel «ISO 27001» eller «Miljøfyrtårn».'}</p>
                <input
                    id={id('standard')}
                    type="text"
                    list={id('standard-options')}
                    aria-describedby={id('standard-hint')}
                    value={form.data.standard}
                    onChange={(event) => form.setData('standard', event.target.value)}
                    className={`mt-1 ${INPUT} md:max-w-md`}
                />
                <datalist id={id('standard-options')}>
                    {standards.map((standard) => <option key={standard} value={standard} />)}
                </datalist>
                {form.errors.standard && <p className={ERROR}>{form.errors.standard}</p>}
            </div>

            <div>
                <label htmlFor={id('location')} className={LABEL}>{d.location ?? 'Hvor ligger dokumentet?'}</label>
                <p id={id('location-hint')} className={HINT}>{d.location_hint ?? 'Arkivreferanse, SharePoint-lenke eller saksnummer. Selve dokumentet lastes ikke opp her.'}</p>
                <input
                    id={id('location')}
                    type="text"
                    aria-describedby={id('location-hint')}
                    value={form.data.location}
                    onChange={(event) => form.setData('location', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.location && <p className={ERROR}>{form.errors.location}</p>}
            </div>

            {mode !== 'edit' && <FileField id={id('file')} accept={accept} form={form} d={d} />}

            <div className="grid gap-5 sm:grid-cols-2">
                <div>
                    <label htmlFor={id('valid-from')} className={LABEL}>{d.valid_from ?? 'Gyldig fra'}</label>
                    <p className={HINT}>{d.optional ?? 'Valgfritt.'}</p>
                    <input
                        id={id('valid-from')}
                        type="date"
                        value={form.data.valid_from}
                        onChange={(event) => form.setData('valid_from', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {form.errors.valid_from && <p className={ERROR}>{form.errors.valid_from}</p>}
                </div>
                <div>
                    <label htmlFor={id('valid-until')} className={LABEL}>{d.valid_until ?? 'Gyldig til'}</label>
                    <p className={HINT}>{d.valid_until_hint ?? 'La stå tom hvis dokumentet ikke utløper.'}</p>
                    <input
                        id={id('valid-until')}
                        type="date"
                        min={form.data.valid_from || undefined}
                        value={form.data.valid_until}
                        onChange={(event) => form.setData('valid_until', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {form.errors.valid_until && <p className={ERROR}>{form.errors.valid_until}</p>}
                </div>
            </div>

            <div>
                <label htmlFor={id('comment')} className={LABEL}>{d.comment ?? 'Kommentar'}</label>
                <p className={HINT}>{d.optional ?? 'Valgfritt.'}</p>
                <textarea
                    id={id('comment')}
                    rows={3}
                    value={form.data.comment}
                    onChange={(event) => form.setData('comment', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.comment && <p className={ERROR}>{form.errors.comment}</p>}
            </div>

            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (d.submit ?? 'Lagre dokumentasjon')}
                </button>
            </div>
        </form>
    );
}

/**
 * Bekreft kravene på nytt (docs/supplier-assurance-v2-plan.md §10.3): on a renewed document, the
 * requirements whose control rests on an earlier edition — all chosen to begin with. Each one chosen
 * gets a new control with the same result, this edition and today's date; nothing happens unless the
 * person sends it.
 */
function ReconfirmForm({ supplierId, document, requirements, onDone, tr }) {
    const r = tr.control?.reconfirm ?? {};
    const form = useForm({ requirement_ids: requirements.map((requirement) => requirement.id), rationale: '' });
    const id = `reconfirm-${document.id}`;

    const toggle = (requirementId) => form.setData('requirement_ids', form.data.requirement_ids.includes(requirementId)
        ? form.data.requirement_ids.filter((item) => item !== requirementId)
        : [...form.data.requirement_ids, requirementId]);

    const send = (event) => {
        event.preventDefault();
        form.post(`/app/supplier-management/${supplierId}/documents/${document.id}/reconfirm`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={send} className="mt-4 space-y-4 border-t border-slate-100 pt-4" data-testid="reconfirm-form">
            <h3 className="text-lg font-semibold text-slate-950">{r.heading ?? 'Bekreft kravene på nytt'}</h3>
            <p className={HINT}>{r.intro}</p>
            <fieldset>
                <legend className={LABEL}>{r.requirements ?? 'Krav'}<RequiredMark /></legend>
                {requirements.map((requirement) => (
                    <label key={requirement.id} className="flex min-h-10 items-start gap-3 py-1 text-base text-slate-900">
                        <input type="checkbox" checked={form.data.requirement_ids.includes(requirement.id)} onChange={() => toggle(requirement.id)} className="mt-1 h-5 w-5 shrink-0" />
                        <span className="min-w-0 break-words">{requirement.title}</span>
                    </label>
                ))}
                {form.errors.requirement_ids && <p className={ERROR}>{form.errors.requirement_ids}</p>}
            </fieldset>
            <div>
                <label htmlFor={`${id}-rationale`} className={LABEL}>{tr.control?.reason_label ?? 'Begrunnelse'}<RequiredMark /></label>
                <textarea id={`${id}-rationale`} rows={3} required aria-required value={form.data.rationale} onChange={(event) => form.setData('rationale', event.target.value)} className={`mt-1 ${INPUT}`} />
                {form.errors.rationale && <p className={ERROR}>{form.errors.rationale}</p>}
            </div>
            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                <button type="submit" disabled={form.processing || form.data.requirement_ids.length === 0 || ! form.data.rationale.trim()} className={PRIMARY_ACTION}>{r.submit ?? 'Bekreft kravene'}</button>
            </div>
        </form>
    );
}

/**
 * Dokumentasjon on the supplier page: which documentation exists, where it is kept, how long it is
 * valid and whether it has expired — and, since v2.1, the one private file a row may carry, always
 * downloaded through the module's own route. Current rows first, replaced ones after them, each as a
 * card so nothing scrolls sideways on a phone. A row a control rests on keeps its file and says why.
 *
 * Legg til, Rediger, Registrer fornyet and Slett are offered only when the server says this person
 * may change the documentation; for an ended supplier someone with the right is told why not. A row
 * used in a control is never offered Slett, and says why; a renewed edition offers «Bekreft kravene
 * på nytt» when the server lists requirements for it.
 */
export default function SupplierDocuments({ supplierId, supplierStatus, documents = [], types = [], standards = [], accept = '.pdf,.docx,.xlsx,.png,.jpg,.jpeg', reconfirmable = {}, permissions = {}, locale, tr }) {
    const d = tr.documents ?? {};
    // What is open: { mode: 'create' } or { mode: 'edit'|'renew'|'reconfirm', document }, one at a time.
    const [open, setOpen] = useState(null);
    const canManage = permissions.can_manage_documents ?? false;
    const date = (iso) => formatLongDate(iso, locale);

    const removeFile = (document) => {
        if (window.confirm(d.file_remove_confirm ?? 'Fjerne filen? Dokumentasjonsraden blir stående uten fil.')) {
            router.delete(`/app/supplier-management/${supplierId}/documents/${document.id}/file`, { preserveScroll: true });
        }
    };

    const destroy = (document) => {
        if (! window.confirm(d.delete_confirm ?? 'Slette denne dokumentasjonen? Bruk dette bare når den ble registrert ved en feil. Er dokumentet fornyet, bruk «Registrer fornyet» i stedet.')) {
            return;
        }

        router.delete(`/app/supplier-management/${supplierId}/documents/${document.id}`, { preserveScroll: true });
    };

    return (
        <section className={CARD} aria-labelledby="supplier-documents-heading" data-testid="supplier-documents">
            <h2 id="supplier-documents-heading" className="text-xl font-semibold text-slate-950">{d.heading ?? 'Dokumentasjon'}</h2>
            <p className="mt-1 text-base text-slate-600">{d.intro ?? 'Hvilken dokumentasjon som finnes for leverandøren, hvor den ligger og når den utløper. Selve dokumentene ligger der dere arkiverer dem – her registrerer dere bare hvor de finnes.'}</p>

            {documents.length === 0 ? (
                <p className="mt-4 text-base text-slate-800" data-testid="documents-none">{d.none ?? 'Ingen dokumentasjon er registrert.'}</p>
            ) : (
                <ul className="mt-4 space-y-3" data-testid="documents-list">
                    {documents.map((document) => (
                        <li key={document.id} className="rounded-xl border border-slate-200 px-4 py-3" data-testid="document-entry">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="text-base font-semibold text-slate-600">{documentTypeLabel(document.document_type, tr)}</p>
                                    <p className="break-words text-lg font-semibold text-slate-950">{document.title}</p>
                                    {document.standard && <p className="break-words text-base text-slate-700" data-testid="document-standard">{d.standard ?? 'Standard'}: {document.standard}</p>}
                                </div>
                                <StatusBadge tone={DOCUMENT_STATUS_TONES[document.status] ?? 'slate'}>{documentStatusLabel(document.status, tr)}</StatusBadge>
                            </div>
                            <dl className="mt-3 grid gap-3 sm:grid-cols-3">
                                <div className="min-w-0 sm:col-span-3">
                                    <dt className={TERM}>{d.location_short ?? 'Plassering'}</dt>
                                    <dd className={VALUE} data-testid="document-location"><Location location={document.location} d={d} /></dd>
                                </div>
                                {document.valid_from && (
                                    <div className="min-w-0">
                                        <dt className={TERM}>{d.valid_from ?? 'Gyldig fra'}</dt>
                                        <dd className={VALUE}>{date(document.valid_from)}</dd>
                                    </div>
                                )}
                                <div className="min-w-0">
                                    <dt className={TERM}>{d.valid_until ?? 'Gyldig til'}</dt>
                                    <dd className={VALUE} data-testid="document-valid-until">
                                        {document.valid_until ? date(document.valid_until) : (d.statuses?.no_expiry ?? 'Ingen utløpsdato')}
                                    </dd>
                                </div>
                                {document.comment && (
                                    <div className="min-w-0 sm:col-span-3">
                                        <dt className={TERM}>{d.comment ?? 'Kommentar'}</dt>
                                        <dd className={`${VALUE} whitespace-pre-line`}>{document.comment}</dd>
                                    </div>
                                )}
                            </dl>
                            <DocumentFile document={document} date={date} locale={locale} d={d} />
                            {document.status === 'replaced' && <p className="mt-2 text-base text-slate-600">{d.replaced_hint ?? 'Erstattet av en fornyet utgave.'}</p>}
                            {document.deletable === false && <p className="mt-2 text-base text-slate-600" data-testid="document-used-in-control">{d.used_in_control}</p>}

                            {canManage && open === null && (
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <button type="button" onClick={() => setOpen({ mode: 'edit', document })} className={SECONDARY_ACTION}>{d.edit ?? 'Rediger'}</button>
                                    {document.status !== 'replaced' && (
                                        <button type="button" onClick={() => setOpen({ mode: 'renew', document })} className={SECONDARY_ACTION}>{d.renew ?? 'Registrer fornyet'}</button>
                                    )}
                                    {fileActions(document, canManage).upload && (
                                        <button type="button" onClick={() => setOpen({ mode: 'file', document })} className={SECONDARY_ACTION}>{d.file_upload ?? 'Last opp fil'}</button>
                                    )}
                                    {fileActions(document, canManage).replace && (
                                        <button type="button" onClick={() => setOpen({ mode: 'file', document })} className={SECONDARY_ACTION}>{d.file_replace ?? 'Erstatt fil'}</button>
                                    )}
                                    {fileActions(document, canManage).remove && (
                                        <button type="button" onClick={() => removeFile(document)} className={SECONDARY_ACTION}>{d.file_remove ?? 'Fjern fil'}</button>
                                    )}
                                    {(reconfirmable[document.id] ?? []).length > 0 && (
                                        <button type="button" onClick={() => setOpen({ mode: 'reconfirm', document })} className={PRIMARY_ACTION}>{tr.control?.reconfirm?.open ?? 'Bekreft kravene på nytt'}</button>
                                    )}
                                    {document.deletable !== false && (
                                        <button type="button" onClick={() => destroy(document)} className={DESTRUCTIVE_ACTION}>{d.delete ?? 'Slett'}</button>
                                    )}
                                </div>
                            )}
                            {open?.mode === 'reconfirm' && open.document.id === document.id && (
                                <ReconfirmForm supplierId={supplierId} document={document} requirements={reconfirmable[document.id] ?? []} onDone={() => setOpen(null)} tr={tr} />
                            )}
                            {canManage && open?.mode === 'file' && open.document.id === document.id && (
                                <FileForm supplierId={supplierId} document={document} replace={Boolean(document.file)} accept={accept} onDone={() => setOpen(null)} tr={tr} />
                            )}
                            {canManage && open?.document?.id === document.id && ! ['reconfirm', 'file'].includes(open.mode) && (
                                <DocumentForm supplierId={supplierId} mode={open.mode} document={document} types={types} standards={standards} accept={accept} onDone={() => setOpen(null)} tr={tr} />
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canManage && open === null && (
                <button type="button" onClick={() => setOpen({ mode: 'create' })} className={`mt-4 ${PRIMARY_ACTION}`}>{d.add ?? 'Legg til dokumentasjon'}</button>
            )}
            {canManage && open?.mode === 'create' && (
                <DocumentForm supplierId={supplierId} mode="create" document={null} types={types} standards={standards} accept={accept} onDone={() => setOpen(null)} tr={tr} />
            )}
            {! canManage && permissions.has_document_right && supplierStatus === 'ended' && (
                <p className="mt-4 text-base text-slate-600" data-testid="documents-read-only">{d.reopen_to_change ?? 'Leverandøren er avsluttet. Gjenåpne den for å endre dokumentasjonen.'}</p>
            )}
        </section>
    );
}
