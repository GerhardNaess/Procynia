import { useRef, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { RequiredFieldsNote } from '../../../../Components/App/FormField';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import { refocusIfLost } from '../../../../Support/focusManagement';
import { HINT, INPUT, LABEL } from '../../../../Support/formStyles';
import { rowErrorId, trackRowAction, useRowActionError } from '../../../../Support/rowActionError';
import RequiredMark from '../RequiredMark';
import FieldError from '../FieldError';
import { fieldStateProps, removalFocusCandidates } from '../complianceA11y';
import { useFocusOnRejectedSubmit, usePanelSwitchFocus } from '../useComplianceFocus';
import { countLabel, sourceKindLabel } from './complianceRequirement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const editButtonId = (id) => `compliance-source-edit-${id}`;
const deleteButtonId = (id) => `compliance-source-delete-${id}`;

const EMPTY = { name: '', version: '', kind: '', description: '' };

function SourceForm({ form, onSubmit, onCancel, kinds, heading, panelKey, ts, tr }) {
    const formRef = useRef(null);
    useFocusOnRejectedSubmit(form, formRef);

    return (
        <form ref={formRef} onSubmit={onSubmit} className="space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-4" aria-label={heading} data-inline-panel={panelKey}>
            <h3 className="text-lg font-semibold text-slate-950">{heading}</h3>
            <div className="grid gap-4 md:grid-cols-2">
                <div>
                    <label htmlFor="compliance-source-name" className={LABEL}>{ts.field_name ?? 'Navn'}<RequiredMark /></label>
                    <input
                        id="compliance-source-name"
                        type="text"
                        required
                        aria-required="true"
                        {...fieldStateProps('compliance-source-name', form.errors.name, ['compliance-source-name-hint'])}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <p id="compliance-source-name-hint" className={HINT}>{ts.field_name_hint ?? 'For eksempel «ISO 27001» eller «Arbeidsmiljøloven».'}</p>
                    <FieldError field="compliance-source-name" message={form.errors.name} />
                </div>
                <div>
                    <label htmlFor="compliance-source-version" className={LABEL}>{ts.field_version ?? 'Versjon'}</label>
                    <input
                        id="compliance-source-version"
                        type="text"
                        maxLength={100}
                        {...fieldStateProps('compliance-source-version', form.errors.version, ['compliance-source-version-hint'])}
                        value={form.data.version}
                        onChange={(event) => form.setData('version', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <p id="compliance-source-version-hint" className={HINT}>{ts.field_version_hint ?? 'Valgfritt. For eksempel «2022».'}</p>
                    <FieldError field="compliance-source-version" message={form.errors.version} />
                </div>
                <div>
                    <label htmlFor="compliance-source-kind" className={LABEL}>{ts.field_kind ?? 'Type'}<RequiredMark /></label>
                    <select
                        id="compliance-source-kind"
                        required
                        aria-required="true"
                        {...fieldStateProps('compliance-source-kind', form.errors.kind)}
                        value={form.data.kind}
                        onChange={(event) => form.setData('kind', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{ts.choose_kind ?? 'Velg type'}</option>
                        {kinds.map((kind) => (
                            <option key={kind} value={kind}>{sourceKindLabel(kind, tr)}</option>
                        ))}
                    </select>
                    <FieldError field="compliance-source-kind" message={form.errors.kind} />
                </div>
                <div className="md:col-span-2">
                    <label htmlFor="compliance-source-description" className={LABEL}>{ts.field_description ?? 'Beskrivelse'}</label>
                    <textarea
                        id="compliance-source-description"
                        rows={2}
                        {...fieldStateProps('compliance-source-description', form.errors.description, ['compliance-source-description-hint'])}
                        value={form.data.description}
                        onChange={(event) => form.setData('description', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <p id="compliance-source-description-hint" className={HINT}>{ts.field_description_hint ?? 'Valgfritt. Hva kilden dekker.'}</p>
                    <FieldError field="compliance-source-description" message={form.errors.description} />
                </div>
            </div>
            <RequiredFieldsNote text={tr.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />
            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onCancel} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (tr.save ?? 'Lagre')}
                </button>
            </div>
        </form>
    );
}

/**
 * Kravkilder, opened from the Krav register: the list, and — with compliance.edit — Ny kravkilde
 * and Rediger, and — with compliance.delete — Slett for a source no requirement uses. One form at a
 * time. Not a page or main area of its own: sources only exist for the requirements under them.
 */
export default function ComplianceSources({ sources = [], kinds = [], canEdit = false, onClose, headingRef = null, tr }) {
    const ts = tr.sources ?? {};
    // null: just the list. 'new': Ny kravkilde. A number: that source is being edited.
    const [editing, setEditing] = useState(null);
    const form = useForm(EMPTY);
    const sectionRef = useRef(null);
    const rowError = useRowActionError(usePage().props.flash?.error ?? null);

    // The form opens in place of Ny kravkilde or the row's Rediger; focus goes into it and back (A11Y-014).
    usePanelSwitchFocus(editing === null ? null : String(editing), {
        containerRef: sectionRef,
        returnTargets: (key) => (key === 'new' ? ['compliance-source-create', 'compliance-sources-heading'] : [editButtonId(key), 'compliance-sources-heading']),
    });

    const open = (source) => {
        form.clearErrors();
        form.setData(source
            ? { name: source.name ?? '', version: source.version ?? '', kind: source.kind ?? '', description: source.description ?? '' }
            : EMPTY);
        setEditing(source ? source.id : 'new');
    };

    const close = () => {
        setEditing(null);
        form.reset();
        form.clearErrors();
    };

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: close };

        if (editing === 'new') {
            form.post('/app/compliance/sources', options);
        } else {
            form.patch(`/app/compliance/sources/${editing}`, options);
        }
    };

    const destroy = (source) => {
        if (! window.confirm(ts.delete_confirm ?? 'Slett kravkilden? Dette kan ikke angres.')) {
            return;
        }

        // A source still in use is refused with a message beside the button (A11Y-137); a removed
        // one hands focus to the next source, the previous one, or the heading (A11Y-014).
        const candidates = removalFocusCandidates(sources.map((item) => item.id), source.id, [editButtonId, deleteButtonId], ['compliance-sources-heading']);
        router.delete(`/app/compliance/sources/${source.id}`, trackRowAction(`compliance-source-delete-${source.id}`, {
            preserveScroll: true,
            onSuccess: () => refocusIfLost(candidates),
        }));
    };

    const formFor = (heading) => (
        <SourceForm form={form} onSubmit={submit} onCancel={close} kinds={kinds} heading={heading} panelKey={String(editing)} ts={ts} tr={tr} />
    );

    return (
        <section ref={sectionRef} className={CARD} aria-labelledby="compliance-sources-heading" data-testid="compliance-sources">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 ref={headingRef} id="compliance-sources-heading" className="text-xl font-semibold text-slate-950">{ts.heading ?? 'Kravkilder'}</h2>
                    <p className="mt-1 text-base text-slate-600">{ts.intro ?? ''}</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {canEdit && editing === null && (
                        <button id="compliance-source-create" type="button" onClick={() => open(null)} className={PRIMARY_ACTION}>{ts.create ?? 'Ny kravkilde'}</button>
                    )}
                    <button type="button" onClick={onClose} className={SECONDARY_ACTION}>{ts.close ?? 'Lukk'}</button>
                </div>
            </div>

            {editing === 'new' && <div className="mt-4">{formFor(ts.create_heading ?? 'Ny kravkilde')}</div>}

            {sources.length === 0 ? (
                editing === null && <p className="mt-4 text-base text-slate-600">{ts.empty ?? 'Ingen kravkilder ennå.'}</p>
            ) : (
                <ul className="mt-4 divide-y divide-slate-100" data-testid="compliance-source-list">
                    {sources.map((source) => (
                        <li key={source.id} className="py-4">
                            {editing === source.id ? formFor(ts.edit_heading ?? 'Rediger kravkilde') : (
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0 space-y-1">
                                        <p className="break-words text-base font-semibold text-slate-950">{source.label}</p>
                                        <p className="text-base text-slate-600">
                                            {sourceKindLabel(source.kind, tr)}
                                            {source.from_library && ` · ${tr.library?.source_from_library ?? 'Fra kravbiblioteket'}`}
                                            {' · '}
                                            {countLabel(source.requirement_count, ts.requirement_count_one ?? '1 krav', ts.requirement_count ?? ':count krav')}
                                        </p>
                                        {source.description && <p className="whitespace-pre-line break-words text-base text-slate-700">{source.description}</p>}
                                    </div>
                                    {editing === null && (canEdit || source.can_delete) && (
                                        <div className="flex flex-wrap gap-2">
                                            {/* An activated framework's source follows Kravbiblioteket; the server refuses an edit too. */}
                                            {canEdit && ! source.from_library && (
                                                <button id={editButtonId(source.id)} type="button" onClick={() => open(source)} className={SECONDARY_ACTION} aria-label={`${ts.edit ?? 'Rediger'} ${source.label}`}>
                                                    {ts.edit ?? 'Rediger'}
                                                </button>
                                            )}
                                            {source.can_delete && (
                                                <button
                                                    id={deleteButtonId(source.id)}
                                                    type="button"
                                                    onClick={() => destroy(source)}
                                                    className={DESTRUCTIVE_ACTION}
                                                    aria-label={`${ts.delete ?? 'Slett'} ${source.label}`}
                                                    aria-describedby={rowError(`compliance-source-delete-${source.id}`) ? rowErrorId(`compliance-source-delete-${source.id}`) : undefined}
                                                >
                                                    {ts.delete ?? 'Slett'}
                                                </button>
                                            )}
                                        </div>
                                    )}
                                    {rowError(`compliance-source-delete-${source.id}`) && (
                                        <p id={rowErrorId(`compliance-source-delete-${source.id}`)} className="basis-full text-base text-rose-700">
                                            {rowError(`compliance-source-delete-${source.id}`)}
                                        </p>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
