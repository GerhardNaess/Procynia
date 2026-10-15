import { router, useForm, usePage } from '@inertiajs/react';
import { PRIMARY_COLOURS } from '../../../../Support/actionStyles';
import { useRef, useState } from 'react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';
import ActionDialog, { DialogTitleBar } from '../../../../Components/App/ActionDialog';
import FormField, { RequiredFieldsNote } from '../../../../Components/App/FormField';
import { useFocusFirstError } from '../../../../Support/focusManagement';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function Badge({ children, color = 'slate' }) {
    const colors = {
        slate:   'border-slate-200 bg-slate-50 text-slate-600',
        emerald: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        violet:  'border-violet-200 bg-violet-50 text-violet-700',
        amber:   'border-amber-200 bg-amber-50 text-amber-700',
        rose:    'border-rose-200 bg-rose-50 text-rose-700',
    };
    return (
        <span className={classNames('inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold', colors[color] ?? colors.slate)}>
            {children}
        </span>
    );
}

const FIELD_CONTROL = 'w-full rounded-xl border border-field-border px-3 py-2 text-sm text-slate-900 placeholder:text-slate-500 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100';

/**
 * A field in this page's own type scale, wired through FormField: the label names the control,
 * the error is tied to it and marks it invalid (A11Y-041, A11Y-013).
 */
function LabeledField({ id, label, required, error, children }) {
    return (
        <FormField
            id={id}
            label={label}
            required={required}
            error={error}
            labelClassName="block text-sm font-semibold text-slate-700"
            labelRowClassName="mb-1 flex items-center gap-1.5"
            controlClassName=""
            errorClassName="mt-1 text-xs text-rose-700"
        >
            {children}
        </FormField>
    );
}

function TextField({ field, value, onChange, placeholder, rows, maxLength }) {
    if (rows) {
        return (
            <textarea
                {...field}
                rows={rows}
                value={value}
                onChange={onChange}
                placeholder={placeholder}
                maxLength={maxLength}
                className={`resize-none ${FIELD_CONTROL}`}
            />
        );
    }
    return (
        <input
            {...field}
            type="text"
            value={value}
            onChange={onChange}
            placeholder={placeholder}
            maxLength={maxLength}
            className={FIELD_CONTROL}
        />
    );
}

function CriterionModal({ criterion, templateId, criteriaStoreUrl, onClose, a11y }) {
    const isNew = !criterion;
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);
    const form = useForm({
        title:                    criterion?.title ?? '',
        short_description:        criterion?.short_description ?? '',
        help_what_is_assessed:    criterion?.help_what_is_assessed ?? '',
        help_why_it_matters:      criterion?.help_why_it_matters ?? '',
        help_what_to_investigate: criterion?.help_what_to_investigate ?? '',
        help_positive_indicators: criterion?.help_positive_indicators ?? '',
        help_warning_signs:       criterion?.help_warning_signs ?? '',
        help_example_assessment:  criterion?.help_example_assessment ?? '',
        weight:                   String(criterion?.weight ?? 2),
        is_score_reversed:        criterion?.is_score_reversed ?? false,
        sort_order:               String(criterion?.sort_order ?? ''),
    });

    function handleSubmit(e) {
        e.preventDefault();
        const data = { ...form.data, weight: Number(form.data.weight) };
        if (isNew) {
            form.post(criteriaStoreUrl, { data, onSuccess: onClose, onError: focusFirstError });
        } else {
            form.put(criterion.update_url, { data, onSuccess: onClose, onError: focusFirstError });
        }
    }

    const helpFields = [
        { key: 'help_what_is_assessed',    label: 'Hva vurderes?' },
        { key: 'help_why_it_matters',      label: 'Hvorfor er dette viktig?' },
        { key: 'help_what_to_investigate', label: 'Hva bør dere undersøke?' },
        { key: 'help_positive_indicators', label: 'Positive indikatorer' },
        { key: 'help_warning_signs',       label: 'Faresignaler' },
        { key: 'help_example_assessment',  label: 'Eksempel på god vurdering' },
    ];

    const title = isNew ? 'Legg til vurderingspunkt' : 'Rediger vurderingspunkt';

    return (
        <ActionDialog
            isOpen
            onClose={onClose}
            closeDisabled={form.processing}
            titleId="criterion-dialog-title"
            size="lg"
            padded={false}
        >
            <div className="flex max-h-[calc(100vh-2rem)] flex-col">
                <DialogTitleBar
                    titleId="criterion-dialog-title"
                    title={title}
                    onClose={onClose}
                    closeLabel={a11y.close_dialog ?? 'Lukk dialog'}
                />

                <div className="min-h-0 flex-1 overflow-y-auto px-6 py-6">
                    <form ref={formRef} id="criterion-form" onSubmit={handleSubmit} className="space-y-4">
                        <RequiredFieldsNote text={a11y.required_fields} />
                        <div className="grid grid-cols-[1fr_auto_auto] gap-3 items-end">
                            <LabeledField id="criterion-title" label="Tittel" required error={form.errors.title}>
                                {(field) => (
                                    <TextField
                                        field={field}
                                        value={form.data.title}
                                        onChange={e => form.setData('title', e.target.value)}
                                        placeholder="F.eks. Strategisk relevans"
                                        maxLength={255}
                                    />
                                )}
                            </LabeledField>
                            <LabeledField id="criterion-weight" label="Vekt (1–5)" required error={form.errors.weight}>
                                {(field) => (
                                    <select
                                        {...field}
                                        value={form.data.weight}
                                        onChange={e => form.setData('weight', e.target.value)}
                                        className="w-20 rounded-xl border border-field-border px-3 py-2 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                    >
                                        {[1, 2, 3, 4, 5].map(w => <option key={w} value={w}>{w}</option>)}
                                    </select>
                                )}
                            </LabeledField>
                            {!isNew && (
                                <LabeledField id="criterion-sort-order" label="Sortering" error={form.errors.sort_order}>
                                    {(field) => (
                                        <input
                                            {...field}
                                            type="number"
                                            min="1" max="999"
                                            value={form.data.sort_order}
                                            onChange={e => form.setData('sort_order', e.target.value)}
                                            className="w-20 rounded-xl border border-field-border px-3 py-2 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                        />
                                    )}
                                </LabeledField>
                            )}
                        </div>

                        <label className="flex items-center gap-3 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={form.data.is_score_reversed}
                                onChange={e => form.setData('is_score_reversed', e.target.checked)}
                                className="h-4 w-4 rounded border-slate-300 accent-violet-600"
                            />
                            <span className="text-sm font-semibold text-slate-700">Reversert scoring <span className="font-normal text-slate-600">(Lav = positivt, f.eks. Risiko)</span></span>
                        </label>

                        <LabeledField id="criterion-short-description" label="Kort forklaring" error={form.errors.short_description}>
                            {(field) => (
                                <TextField field={field} rows={2} value={form.data.short_description} onChange={e => form.setData('short_description', e.target.value)} placeholder="Vises under tittelen i vurderingsskjemaet" maxLength={500} />
                            )}
                        </LabeledField>

                        <fieldset className="border-t border-slate-100 pt-4">
                            <legend className="mb-3 text-xs font-semibold uppercase tracking-widest text-slate-600">Vurderingshjelp (sidepanel)</legend>
                            <div className="space-y-3">
                                {helpFields.map(({ key, label }) => (
                                    <LabeledField key={key} id={`criterion-${key}`} label={label} error={form.errors[key]}>
                                        {(field) => (
                                            <TextField field={field} rows={2} value={form.data[key]} onChange={e => form.setData(key, e.target.value)} placeholder={label} maxLength={2000} />
                                        )}
                                    </LabeledField>
                                ))}
                            </div>
                        </fieldset>
                    </form>
                </div>

                <div className="shrink-0 flex justify-end gap-3 border-t border-slate-200 bg-white px-6 py-5">
                    <button type="button" onClick={onClose} className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:border-slate-300">Avbryt</button>
                    <button
                        type="submit"
                        form="criterion-form"
                        disabled={form.processing || !form.data.title.trim()}
                        className={`rounded-xl px-4 py-2 text-sm font-semibold shadow-sm disabled:opacity-50 ${PRIMARY_COLOURS}`}
                    >
                        {isNew ? 'Legg til' : 'Lagre'}
                    </button>
                </div>
            </div>
        </ActionDialog>
    );
}

export default function GoNoGoTemplatesEdit({ template, criteria }) {
    const { translations = {} } = usePage().props;
    const a11y = translations.a11y ?? {};
    const form = useForm({ name: template.name, description: template.description ?? '' });
    const templateFormRef = useRef(null);
    const focusFirstTemplateError = useFocusFirstError(templateFormRef);
    const [editingCriterion, setEditingCriterion] = useState(null); // null=closed, false=new, object=existing
    const [showCriterionModal, setShowCriterionModal] = useState(false);

    function handleUpdateTemplate(e) {
        e.preventDefault();
        form.put(template.update_url, { onError: focusFirstTemplateError });
    }

    function handleToggleCriterion(criterion) {
        router.patch(criterion.toggle_active_url, {}, { preserveScroll: true });
    }

    function openEdit(criterion) {
        setEditingCriterion(criterion);
        setShowCriterionModal(true);
    }

    function openNew() {
        setEditingCriterion(null);
        setShowCriterionModal(true);
    }

    function closeCriterionModal() {
        setShowCriterionModal(false);
        setEditingCriterion(null);
    }

    const activeCriteria = criteria.filter(c => c.is_active);
    const inactiveCriteria = criteria.filter(c => !c.is_active);

    return (
        <CustomerAppLayout title={`Rediger: ${template.name}`}>
            {showCriterionModal && (
                <CriterionModal
                    criterion={editingCriterion}
                    templateId={template.id}
                    criteriaStoreUrl={template.criteria_store_url}
                    onClose={closeCriterionModal}
                    a11y={a11y}
                />
            )}

            <div className="space-y-6">
                {/* Template metadata */}
                <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="mb-4 flex flex-wrap items-center gap-2">
                        {/* h2: the layout's «Rediger: …» is the page's one h1 (A11Y-042). */}
                        <h2 className="text-xl font-semibold text-slate-950">Malinformasjon</h2>
                        {template.is_default && <Badge color="violet">Standard</Badge>}
                        {!template.is_active && <Badge color="amber">Inaktiv</Badge>}
                    </div>

                    <form ref={templateFormRef} onSubmit={handleUpdateTemplate} className="space-y-4">
                        <RequiredFieldsNote text={a11y.required_fields} />
                        <LabeledField id="template-name" label="Malnavn" required error={form.errors.name}>
                            {(field) => (
                                <TextField field={field} value={form.data.name} onChange={e => form.setData('name', e.target.value)} />
                            )}
                        </LabeledField>
                        <LabeledField id="template-description" label="Beskrivelse" error={form.errors.description}>
                            {(field) => (
                                <TextField field={field} rows={2} value={form.data.description} onChange={e => form.setData('description', e.target.value)} />
                            )}
                        </LabeledField>
                        <div className="flex flex-wrap items-center gap-3">
                            <button
                                type="submit"
                                disabled={form.processing}
                                className={`rounded-xl px-4 py-2 text-sm font-semibold shadow-sm disabled:opacity-50 ${PRIMARY_COLOURS}`}
                            >
                                Lagre
                            </button>
                            {!template.is_default && template.is_active && (
                                <button
                                    type="button"
                                    onClick={() => router.patch(template.set_default_url, {}, { preserveScroll: true })}
                                    className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:border-violet-200 hover:text-violet-700"
                                >
                                    Sett som standard
                                </button>
                            )}
                            {!(template.is_default && template.is_active) && (
                                <button
                                    type="button"
                                    onClick={() => router.patch(template.toggle_active_url, {}, { preserveScroll: true })}
                                    className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:border-slate-300"
                                >
                                    {template.is_active ? 'Deaktiver mal' : 'Aktiver mal'}
                                </button>
                            )}
                        </div>
                    </form>
                </div>

                {/* Criteria */}
                <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="mb-4 flex items-center justify-between gap-4">
                        <div>
                            <h2 className="text-xl font-semibold text-slate-950">Vurderingspunkter</h2>
                            <p className="mt-0.5 text-sm text-slate-600">{activeCriteria.length} aktive punkt{activeCriteria.length !== 1 ? 'er' : ''}</p>
                        </div>
                        <button
                            type="button"
                            onClick={openNew}
                            className={`shrink-0 inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-semibold shadow-sm ${PRIMARY_COLOURS}`}
                        >
                            + Legg til punkt
                        </button>
                    </div>

                    {activeCriteria.length > 0 && (
                    <ul className="space-y-2" aria-label="Aktive vurderingspunkter">
                        {activeCriteria.map((criterion, idx) => (
                            <CriterionRow
                                key={criterion.id}
                                criterion={criterion}
                                index={idx + 1}
                                onEdit={() => openEdit(criterion)}
                                onToggle={() => handleToggleCriterion(criterion)}
                            />
                        ))}
                    </ul>
                    )}

                    {activeCriteria.length === 0 && (
                        <div className="rounded-2xl border border-slate-100 bg-slate-50 px-5 py-6 text-center text-sm text-slate-600">
                            Ingen aktive vurderingspunkter. Legg til ett for å bruke denne malen.
                        </div>
                    )}

                    {inactiveCriteria.length > 0 && (
                        <div className="mt-4 border-t border-slate-100 pt-4">
                            <h3 id="inactive-criteria-heading" className="mb-2 text-xs font-semibold uppercase tracking-widest text-slate-600">Deaktiverte punkter</h3>
                            {/* No opacity-60 any more: it took the text under 4.5:1 (A11Y-017). The heading says they are off. */}
                            <ul className="space-y-2" aria-labelledby="inactive-criteria-heading">
                                {inactiveCriteria.map((criterion, idx) => (
                                    <CriterionRow
                                        key={criterion.id}
                                        criterion={criterion}
                                        index={'–'}
                                        onEdit={() => openEdit(criterion)}
                                        onToggle={() => handleToggleCriterion(criterion)}
                                    />
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </div>
        </CustomerAppLayout>
    );
}

function CriterionRow({ criterion, index, onEdit, onToggle }) {
    return (
        <li className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50/60 px-4 py-3">
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">{index}</span>
                    <span className="text-sm font-semibold text-slate-900">{criterion.title}</span>
                    <span className="inline-flex items-center rounded border border-slate-200 bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-600">
                        Vekt {criterion.weight}{criterion.is_score_reversed ? ' · reversert' : ''}
                    </span>
                </div>
                {criterion.short_description && (
                    <p className="mt-0.5 text-xs leading-5 text-slate-600 line-clamp-1">{criterion.short_description}</p>
                )}
            </div>
            <div className="flex shrink-0 items-center gap-2">
                <button
                    type="button"
                    onClick={onEdit}
                    aria-label={`Rediger ${criterion.title}`}
                    className="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-600 hover:border-violet-200 hover:text-violet-700"
                >
                    Rediger
                </button>
                <button
                    type="button"
                    onClick={onToggle}
                    aria-label={`${criterion.is_active ? 'Deaktiver' : 'Aktiver'} ${criterion.title}`}
                    className="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-600 hover:border-slate-300"
                >
                    {criterion.is_active ? 'Deaktiver' : 'Aktiver'}
                </button>
            </div>
        </li>
    );
}
