import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import AlertBox from '../../../Components/App/AlertBox';
import FilePickerField from '../../../Components/App/FilePickerField';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import StatusBadge from '../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import SupplierTabs from './SupplierTabs';
import { supplierHelp } from './supplierHelp';
import { categoryLabel, criticalityLabel, statusLabel as supplierStatusLabel } from './supplierManagement';
import { formatLongDate } from '../Improvements/improvementStatus';
import {
    IMPORT_STATUS_TONES,
    actionLabel,
    changeLine,
    confirmSummary,
    fill,
    filterRows,
    importStep,
    rowAction,
    statusCounts,
    statusLabel,
} from './supplierImport';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const REGISTER_URL = '/app/supplier-management';
const IMPORT_URL = '/app/supplier-management/import';
const OUTCOME_TONES = { created: 'emerald', updated: 'sky', skipped: 'slate', rejected: 'rose' };

function Steps({ step, ti }) {
    const s = ti.steps ?? {};
    const steps = [
        ['upload', s.upload ?? 'Last opp'],
        ['review', s.review ?? 'Kontroller'],
        ['confirm', s.confirm ?? 'Bekreft'],
    ];
    const order = { upload: 0, review: 1, confirm: 2, result: 3 };

    return (
        <ol aria-label={s.label ?? 'Steg i importen'} className="flex flex-wrap gap-2" data-testid="import-steps">
            {steps.map(([key, label], index) => {
                const current = key === step;
                const done = order[step] > index;

                return (
                    <li
                        key={key}
                        aria-current={current ? 'step' : undefined}
                        className={`inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-base font-semibold ${current ? 'border-violet-300 bg-violet-50 text-violet-800' : done ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-white text-slate-600'}`}
                    >
                        <span aria-hidden="true">{done ? '✓' : index + 1}</span>
                        {label}
                        {current && <span className="sr-only">({s.current ?? 'nåværende steg'})</span>}
                    </li>
                );
            })}
        </ol>
    );
}

function UploadStep({ ti, limits, templateUrl }) {
    const u = ti.upload ?? {};
    const form = useForm({ file: null });
    const [inputKey, setInputKey] = useState(0);

    const submit = (event) => {
        event.preventDefault();
        form.post(IMPORT_URL, {
            forceFormData: true,
            onError: () => { form.setData('file', null); setInputKey((key) => key + 1); },
        });
    };

    return (
        <div className="grid gap-6 lg:grid-cols-2">
            <section className={`${CARD} space-y-4`} aria-labelledby="import-template-heading">
                <h2 id="import-template-heading" className="text-xl font-semibold text-slate-950">{u.template_heading ?? '1. Last ned malen'}</h2>
                <p className="text-base leading-6 text-slate-700">{u.template_text}</p>
                <a href={templateUrl} className={SECONDARY_ACTION} data-testid="import-template-download">{u.template_button ?? 'Last ned Excel-mal'}</a>
                <div className="space-y-2 border-t border-slate-200 pt-4">
                    <h3 className="text-base font-semibold text-slate-900">{u.what_heading ?? 'Dette kan importeres'}</h3>
                    <ul className="list-disc space-y-1 pl-5 text-base leading-6 text-slate-700">
                        {(u.what_items ?? []).map((item) => <li key={item}>{item}</li>)}
                    </ul>
                    <h3 className="pt-2 text-base font-semibold text-slate-900">{u.not_heading ?? 'Dette importeres ikke'}</h3>
                    <p className="text-base leading-6 text-slate-700">{u.not_text}</p>
                </div>
            </section>

            <section className={`${CARD} space-y-4`} aria-labelledby="import-file-heading">
                <h2 id="import-file-heading" className="text-xl font-semibold text-slate-950">{u.file_heading ?? '2. Last opp filen'}</h2>
                <form onSubmit={submit} className="space-y-4">
                    <FilePickerField
                        id="supplier-import-file"
                        label={u.file_label ?? 'Excel-fil (.xlsx)'}
                        accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        inputKey={inputKey}
                        file={form.data.file}
                        buttonLabel={u.choose_file ?? 'Velg fil'}
                        emptyLabel={u.no_file ?? 'Ingen fil valgt'}
                        help={fill(u.file_hint ?? 'Bare .xlsx, maks :mb MB og :rows leverandører.', { mb: limits.max_megabytes, rows: limits.max_rows })}
                        error={form.errors.file}
                        disabled={form.processing}
                        onChange={(file) => form.setData('file', file)}
                    />
                    <button type="submit" className={PRIMARY_ACTION} disabled={form.processing || ! form.data.file}>
                        {form.processing ? (u.uploading ?? 'Leser filen...') : (u.submit ?? 'Last opp og kontroller')}
                    </button>
                </form>
            </section>
        </div>
    );
}

function RowCard({ row, ti, tr, updateExisting }) {
    const r = ti.review ?? {};
    const v = row.values ?? {};
    const action = rowAction(row, updateExisting);
    const errors = (row.messages ?? []).filter((message) => message.type === 'error');
    const notices = (row.messages ?? []).filter((message) => message.type !== 'error');
    const contact = [v.contact_name, v.contact_email, v.contact_phone].filter(Boolean).join(' · ');

    return (
        <li className="rounded-2xl border border-slate-200 p-4" data-testid="import-row" data-status={row.status}>
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-base font-semibold text-slate-600">{fill(r.row_label ?? 'Rad :row', { row: row.row })}</span>
                <StatusBadge tone={IMPORT_STATUS_TONES[row.status] ?? 'slate'}>{statusLabel(row.status, ti)}</StatusBadge>
                <span className={`text-base font-semibold ${action === 'skip' ? 'text-slate-600' : 'text-violet-800'}`} data-testid="import-row-action">
                    → {actionLabel(action, ti)}
                </span>
            </div>

            <p className="mt-2 text-lg font-semibold text-slate-950">{v.name || '—'}</p>
            <p className="text-base text-slate-700">{v.organization_number ?? (r.no_org ?? 'Uten organisasjonsnummer')}</p>

            <dl className="mt-2 grid gap-x-6 gap-y-1 text-base sm:grid-cols-2">
                {(v.category || v.criticality || row.status === 'new') && (
                    <div className="min-w-0">
                        <dt className="font-semibold text-slate-700">{r.col_category ?? 'Kategori og profil'}</dt>
                        <dd className="text-slate-700">
                            {[
                                v.category ? categoryLabel(v.category, tr) : null,
                                row.status === 'new' ? (v.criticality ? criticalityLabel(v.criticality, tr) : (tr.not_classified ?? 'Ikke vurdert')) : null,
                                v.initial_status ? supplierStatusLabel(v.initial_status, tr) : null,
                                v.profile_answers > 0 ? fill(r.profile_answers ?? ':count svar i leverandørprofilen', { count: v.profile_answers }) : null,
                            ].filter(Boolean).join(' · ') || '—'}
                        </dd>
                    </div>
                )}
                {(v.owner || v.owner_text) && (
                    <div className="min-w-0">
                        <dt className="font-semibold text-slate-700">{r.col_owner ?? 'Intern ansvarlig'}</dt>
                        <dd className="break-words text-slate-700">
                            {v.owner ? `${v.owner.name}${v.owner.is_default ? ` ${r.owner_default ?? '(deg)'}` : ''}` : v.owner_text}
                        </dd>
                    </div>
                )}
                {contact && (
                    <div className="min-w-0 sm:col-span-2">
                        <dt className="font-semibold text-slate-700">{r.col_contact ?? 'Kontakt'}</dt>
                        <dd className="break-words text-slate-700">{contact}</dd>
                    </div>
                )}
            </dl>

            {row.existing && (
                <p className="mt-2 text-base text-slate-700">
                    {r.in_register ?? 'I registeret:'}{' '}
                    <Link href={row.existing.url} className="font-semibold text-violet-800 underline">{row.existing.name}</Link>
                    {' '}({supplierStatusLabel(row.existing.status, tr)})
                </p>
            )}

            {errors.length > 0 && (
                <ul className="mt-2 space-y-1 text-base text-rose-700" data-testid="import-row-errors">
                    {errors.map((message) => <li key={message.text}>{message.text}</li>)}
                </ul>
            )}
            {notices.length > 0 && (
                <ul className="mt-2 space-y-1 text-base text-slate-700">
                    {notices.map((message) => <li key={message.text}>{message.text}</li>)}
                </ul>
            )}

            {row.can_update && (row.changes ?? []).length > 0 && (
                <div className="mt-3 rounded-xl bg-slate-50 p-3" data-testid="import-row-changes">
                    <p className="text-base font-semibold text-slate-800">{r.changes_heading ?? 'Endringer hvis eksisterende leverandører oppdateres'}</p>
                    <ul className="mt-1 space-y-1 text-base text-slate-700">
                        {row.changes.map((change) => (
                            <li key={change.field} className="break-words">{changeLine(change, ti, (value) => categoryLabel(value, tr))}</li>
                        ))}
                    </ul>
                </div>
            )}
        </li>
    );
}

function ReviewStep({ importData, ti, tr, updateExisting, setUpdateExisting, onContinue }) {
    const r = ti.review ?? {};
    const [filter, setFilter] = useState('');
    const rows = importData.rows ?? [];
    const shown = filterRows(rows, filter);

    const cancel = () => {
        if (window.confirm(r.cancel_confirm ?? 'Vil du avbryte importen?')) {
            router.delete(`${IMPORT_URL}/${importData.id}`);
        }
    };

    return (
        <section className={`${CARD} space-y-4`} aria-labelledby="import-review-heading">
            <div className="space-y-1">
                <h2 id="import-review-heading" className="text-xl font-semibold text-slate-950">{r.heading ?? 'Kontroller radene'}</h2>
                <p className="text-base text-slate-700">{r.intro}</p>
                <p className="break-all text-base text-slate-600">{r.file ?? 'Fil'}: {importData.file_name}</p>
            </div>

            {(importData.ignored_columns ?? []).length > 0 && (
                <AlertBox tone="amber">
                    {fill(r.ignored_columns ?? 'Disse kolonnene ble ikke gjenkjent og importeres ikke: :columns', { columns: importData.ignored_columns.map((column) => `«${column}»`).join(', ') })}
                </AlertBox>
            )}

            <div className="flex flex-wrap items-end gap-4">
                <div className="min-w-0">
                    <label htmlFor="import-status-filter" className="block text-base font-semibold text-slate-700">{r.filter_label ?? 'Vis'}</label>
                    <select id="import-status-filter" value={filter} onChange={(event) => setFilter(event.target.value)} className={`mt-1 ${INPUT}`}>
                        <option value="">{fill(r.filter_all ?? 'Alle rader (:count)', { count: rows.length })}</option>
                        {statusCounts(rows).map(({ status, count }) => (
                            <option key={status} value={status}>{`${statusLabel(status, ti)} (${count})`}</option>
                        ))}
                    </select>
                </div>
            </div>

            {(importData.summary?.existing ?? 0) > 0 && (
                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <label className="flex items-start gap-3 text-base text-slate-900">
                        <input
                            type="checkbox"
                            checked={updateExisting}
                            onChange={(event) => setUpdateExisting(event.target.checked)}
                            disabled={(importData.summary?.updatable ?? 0) === 0}
                            className="mt-1 h-5 w-5 shrink-0 rounded border-slate-300"
                            data-testid="import-update-existing"
                        />
                        <span>
                            <span className="block font-semibold">{r.update_existing_label ?? 'Oppdater eksisterende leverandører med verdiene fra filen'}</span>
                            <span className="block text-slate-700">{r.update_existing_hint}</span>
                        </span>
                    </label>
                </div>
            )}

            {shown.length === 0
                ? <p className="text-base text-slate-700">{r.no_rows_in_filter ?? 'Ingen rader med denne statusen.'}</p>
                : (
                    <ul className="space-y-3" data-testid="import-rows">
                        {shown.map((row) => <RowCard key={row.row} row={row} ti={ti} tr={tr} updateExisting={updateExisting} />)}
                    </ul>
                )}

            <div className="flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                <button type="button" onClick={onContinue} className={PRIMARY_ACTION}>{r.continue ?? 'Fortsett'}</button>
                <button type="button" onClick={cancel} className={SECONDARY_ACTION}>{r.cancel ?? 'Avbryt import'}</button>
            </div>
        </section>
    );
}

function ConfirmStep({ importData, ti, updateExisting, onBack }) {
    const c = ti.confirm ?? {};
    const summary = confirmSummary(importData.summary, updateExisting);
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        if (processing) {
            return;
        }

        router.post(`${IMPORT_URL}/${importData.id}/execute`, {
            update_existing: updateExisting,
            preview_hash: importData.preview_hash,
        }, {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });
    };

    const lines = [
        ['new', c.new ?? 'Nye leverandører som opprettes', summary.created],
        ['existing', c.existing ?? 'Eksisterende leverandører', summary.existing],
        ...(summary.existing > 0
            ? [['updated', updateExisting ? (c.updated ?? 'Oppdateres med verdier fra filen') : (c.not_updated ?? 'Oppdatering av eksisterende er ikke valgt'), summary.updated]]
            : []),
        ['errors', c.errors ?? 'Rader med feil', summary.errors],
        ['skipped', c.skipped ?? 'Rader som hoppes over', summary.skipped],
    ];

    return (
        <section className={`${CARD} space-y-4`} aria-labelledby="import-confirm-heading">
            <div className="space-y-1">
                <h2 id="import-confirm-heading" className="text-xl font-semibold text-slate-950">{c.heading ?? 'Bekreft importen'}</h2>
                <p className="text-base text-slate-700">{c.intro}</p>
            </div>

            <dl className="divide-y divide-slate-200 rounded-2xl border border-slate-200" data-testid="import-summary">
                {lines.map(([key, label, count]) => (
                    <div key={key} className="flex items-center justify-between gap-4 px-4 py-3 text-base" data-testid={`import-summary-${key}`}>
                        <dt className="text-slate-700">{label}</dt>
                        <dd className="text-lg font-semibold text-slate-950">{count}</dd>
                    </div>
                ))}
            </dl>

            {summary.importable === 0
                ? <AlertBox tone="amber">{c.nothing}</AlertBox>
                : <p className="text-base text-slate-700">{c.no_notifications}</p>}

            <div className="flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                <button type="button" onClick={submit} className={PRIMARY_ACTION} disabled={processing || summary.importable === 0} data-testid="import-confirm">
                    {processing ? (c.importing ?? 'Importerer...') : (c.submit ?? 'Bekreft og importer')}
                </button>
                <button type="button" onClick={onBack} className={SECONDARY_ACTION} disabled={processing}>{c.back ?? 'Tilbake til kontroll'}</button>
            </div>
        </section>
    );
}

function ResultStep({ importData, ti, locale }) {
    const r = ti.result ?? {};
    const result = importData.result ?? {};
    const tiles = ['created', 'updated', 'skipped', 'rejected'];

    return (
        <section className={`${CARD} space-y-4`} aria-labelledby="import-result-heading">
            <div className="space-y-1">
                <h2 id="import-result-heading" className="text-xl font-semibold text-slate-950">{r.heading ?? 'Importen er fullført'}</h2>
                <p className="break-words text-base text-slate-700">
                    {fill(r.intro ?? ':name importerte :file :date.', {
                        name: importData.completed_by_name ?? '—',
                        file: importData.file_name,
                        date: formatLongDate(importData.completed_at, locale),
                    })}
                </p>
            </div>

            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4" data-testid="import-result-counts">
                {tiles.map((key) => (
                    <div key={key} className="rounded-2xl border border-slate-200 p-4" data-testid={`import-result-${key}`}>
                        <dt className="text-base text-slate-700">{r[key] ?? key}</dt>
                        <dd className="text-2xl font-semibold text-slate-950">{result[key] ?? 0}</dd>
                    </div>
                ))}
            </dl>

            <ul className="space-y-2" data-testid="import-result-rows">
                {(result.rows ?? []).map((row) => (
                    <li key={row.row} className="flex flex-wrap items-start gap-x-3 gap-y-1 rounded-2xl border border-slate-200 p-3 text-base">
                        <span className="font-semibold text-slate-600">{fill(ti.review?.row_label ?? 'Rad :row', { row: row.row })}</span>
                        <StatusBadge tone={OUTCOME_TONES[row.outcome] ?? 'slate'}>{r.outcomes?.[row.outcome] ?? row.outcome}</StatusBadge>
                        <span className="min-w-0 break-words font-semibold text-slate-950">
                            {row.url ? <Link href={row.url} className="text-violet-800 underline">{row.name || '—'}</Link> : (row.name || '—')}
                        </span>
                        {row.reason && <span className="min-w-0 text-slate-700">{r.reasons?.[row.reason] ?? ''}</span>}
                        {(row.messages ?? []).length > 0 && (
                            <ul className="w-full space-y-1 text-rose-700">
                                {row.messages.map((message) => <li key={message}>{message}</li>)}
                            </ul>
                        )}
                    </li>
                ))}
            </ul>

            <div className="flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                <Link href={REGISTER_URL} className={PRIMARY_ACTION}>{r.open_register ?? 'Til leverandører'}</Link>
                <Link href={IMPORT_URL} className={SECONDARY_ACTION}>{r.new_import ?? 'Importer en ny fil'}</Link>
            </div>
        </section>
    );
}

/**
 * Leverandører → Importer leverandører: Last opp → Kontroller → Bekreft, then the result. The server
 * reads the file, says what each row is and decides again when the import is confirmed; this page only
 * shows it and carries the one choice, «Oppdater eksisterende». Nothing is written before Bekreft.
 */
export default function SupplierImport() {
    const {
        translations = {},
        import: importData = null,
        limits = { max_rows: 1000, max_megabytes: 5 },
        template_url: templateUrl = `${IMPORT_URL}/template`,
        locale = 'no',
    } = usePage().props;

    const tr = translations?.supplier_management ?? {};
    const ti = tr.import ?? {};
    const [confirming, setConfirming] = useState(false);
    const [updateExisting, setUpdateExisting] = useState(false);
    const step = importStep(importData, confirming);

    return (
        <CustomerAppLayout title={ti.title ?? 'Importer leverandører'} showPageTitle={false}>
            <div className="space-y-6">
                <SupplierTabs current="suppliers" tr={tr} />
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <p className="text-base font-semibold text-violet-700">{tr.module_name ?? 'Leverandøroppfølging'}</p>
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">{ti.heading ?? 'Importer leverandører'}</h1>
                        <p className="max-w-3xl text-base leading-6 text-slate-600">{ti.intro}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <PageHelpButton {...supplierHelp(tr, 'import')} />
                        <Link href={REGISTER_URL} className={SECONDARY_ACTION}>{ti.back ?? 'Til leverandører'}</Link>
                    </div>
                </header>

                {step !== 'result' && <Steps step={step} ti={ti} />}

                {step === 'upload' && <UploadStep ti={ti} limits={limits} templateUrl={templateUrl} />}
                {step === 'review' && (
                    <ReviewStep
                        importData={importData}
                        ti={ti}
                        tr={tr}
                        updateExisting={updateExisting}
                        setUpdateExisting={setUpdateExisting}
                        onContinue={() => { setConfirming(true); window.scrollTo({ top: 0 }); }}
                    />
                )}
                {step === 'confirm' && <ConfirmStep importData={importData} ti={ti} updateExisting={updateExisting} onBack={() => setConfirming(false)} />}
                {step === 'result' && <ResultStep importData={importData} ti={ti} locale={locale} />}
            </div>
        </CustomerAppLayout>
    );
}
