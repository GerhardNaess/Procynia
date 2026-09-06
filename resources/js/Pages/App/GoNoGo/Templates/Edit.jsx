import { router, useForm, usePage } from '@inertiajs/react';
import { PRIMARY_COLOURS } from '../../../../Support/actionStyles';
import { useState } from 'react';
import CustomerAppLayout from '../../../../Layouts/CustomerAppLayout';

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
        <span className={classNames('inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold', colors[color] ?? colors.slate)}>
            {children}
        </span>
    );
}

function LabeledField({ label, required, error, children }) {
    return (
        <div>
            <label className="block text-sm font-semibold text-slate-700 mb-1">
                {label}{required && <span className="ml-0.5 text-rose-500">*</span>}
            </label>
            {children}
            {error && <p className="mt-1 text-xs text-rose-600">{error}</p>}
        </div>
    );
}

function TextField({ value, onChange, placeholder, rows, maxLength }) {
    if (rows) {
        return (
            <textarea
                rows={rows}
                value={value}
                onChange={onChange}
                placeholder={placeholder}
                maxLength={maxLength}
                className="w-full resize-none rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
            />
        );
    }
    return (
        <input
            type="text"
            value={value}
            onChange={onChange}
            placeholder={placeholder}
            maxLength={maxLength}
            className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
        />
    );
}

function CriterionModal({ criterion, templateId, criteriaStoreUrl, onClose }) {
    const isNew = !criterion;
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
            form.post(criteriaStoreUrl, { data, onSuccess: onClose });
        } else {
            form.put(criterion.update_url, { data, onSuccess: onClose });
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

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center overflow-hidden bg-slate-950/45 px-4 py-4">
            <div className="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-[0_20px_60px_rgba(15,23,42,0.18)]">
                <div className="shrink-0 flex items-center justify-between border-b border-slate-200 px-6 py-5">
                    <h2 className="text-xl font-semibold text-slate-950">
                        {isNew ? 'Legg til vurderingspunkt' : 'Rediger vurderingspunkt'}
                    </h2>
                    <button type="button" onClick={onClose} className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:text-slate-900">×</button>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto px-6 py-6">
                    <form id="criterion-form" onSubmit={handleSubmit} className="space-y-4">
                        <div className="grid grid-cols-[1fr_auto_auto] gap-3 items-end">
                            <LabeledField label="Tittel" required error={form.errors.title}>
                                <TextField
                                    value={form.data.title}
                                    onChange={e => form.setData('title', e.target.value)}
                                    placeholder="F.eks. Strategisk relevans"
                                    maxLength={255}
                                />
                            </LabeledField>
                            <LabeledField label="Vekt (1–5)" required error={form.errors.weight}>
                                <select
                                    value={form.data.weight}
                                    onChange={e => form.setData('weight', e.target.value)}
                                    className="w-20 rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                >
                                    {[1, 2, 3, 4, 5].map(w => <option key={w} value={w}>{w}</option>)}
                                </select>
                            </LabeledField>
                            {!isNew && (
                                <LabeledField label="Sortering" error={form.errors.sort_order}>
                                    <input
                                        type="number"
                                        min="1" max="999"
                                        value={form.data.sort_order}
                                        onChange={e => form.setData('sort_order', e.target.value)}
                                        className="w-20 rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                                    />
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
                            <span className="text-sm font-semibold text-slate-700">Reversert scoring <span className="font-normal text-slate-500">(Lav = positivt, f.eks. Risiko)</span></span>
                        </label>

                        <LabeledField label="Kort forklaring" error={form.errors.short_description}>
                            <TextField rows={2} value={form.data.short_description} onChange={e => form.setData('short_description', e.target.value)} placeholder="Vises under tittelen i vurderingsskjemaet" maxLength={500} />
                        </LabeledField>

                        <div className="border-t border-slate-100 pt-4">
                            <p className="mb-3 text-xs font-semibold uppercase tracking-widest text-slate-400">Vurderingshjelp (sidepanel)</p>
                            <div className="space-y-3">
                                {helpFields.map(({ key, label }) => (
                                    <LabeledField key={key} label={label} error={form.errors[key]}>
                                        <TextField rows={2} value={form.data[key]} onChange={e => form.setData(key, e.target.value)} placeholder={label} maxLength={2000} />
                                    </LabeledField>
                                ))}
                            </div>
                        </div>
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
        </div>
    );
}

export default function GoNoGoTemplatesEdit({ template, criteria }) {
    const { flash = {} } = usePage().props;
    const form = useForm({ name: template.name, description: template.description ?? '' });
    const [editingCriterion, setEditingCriterion] = useState(null); // null=closed, false=new, object=existing
    const [showCriterionModal, setShowCriterionModal] = useState(false);

    function handleUpdateTemplate(e) {
        e.preventDefault();
        form.put(template.update_url);
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
                />
            )}

            <div className="space-y-6">
                {/* Back */}
                <a href="/app/go-no-go-templates" className="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 hover:text-slate-900">
                    ← Tilbake til maloversikt
                </a>

                {/* Flash */}
                {(flash.success || flash.error) && (
                    <div className={classNames(
                        'rounded-2xl border px-4 py-3 text-sm font-medium',
                        flash.error ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800',
                    )}>
                        {flash.error ?? flash.success}
                    </div>
                )}

                {/* Template metadata */}
                <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="mb-4 flex flex-wrap items-center gap-2">
                        <h1 className="text-xl font-semibold text-slate-950">Malinformasjon</h1>
                        {template.is_default && <Badge color="violet">Standard</Badge>}
                        {!template.is_active && <Badge color="amber">Inaktiv</Badge>}
                    </div>

                    <form onSubmit={handleUpdateTemplate} className="space-y-4">
                        <div>
                            <label className="block text-sm font-semibold text-slate-700 mb-1">Malnavn <span className="text-rose-500">*</span></label>
                            <input
                                type="text"
                                value={form.data.name}
                                onChange={e => form.setData('name', e.target.value)}
                                className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                            />
                            {form.errors.name && <p className="mt-1 text-xs text-rose-600">{form.errors.name}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-semibold text-slate-700 mb-1">Beskrivelse</label>
                            <textarea
                                rows={2}
                                value={form.data.description}
                                onChange={e => form.setData('description', e.target.value)}
                                className="w-full resize-none rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                            />
                        </div>
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
                                    className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-500 hover:border-slate-300"
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
                            <p className="mt-0.5 text-sm text-slate-500">{activeCriteria.length} aktive punkt{activeCriteria.length !== 1 ? 'er' : ''}</p>
                        </div>
                        <button
                            type="button"
                            onClick={openNew}
                            className={`shrink-0 inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-semibold shadow-sm ${PRIMARY_COLOURS}`}
                        >
                            + Legg til punkt
                        </button>
                    </div>

                    <div className="space-y-2">
                        {activeCriteria.map((criterion, idx) => (
                            <CriterionRow
                                key={criterion.id}
                                criterion={criterion}
                                index={idx + 1}
                                onEdit={() => openEdit(criterion)}
                                onToggle={() => handleToggleCriterion(criterion)}
                            />
                        ))}

                        {activeCriteria.length === 0 && (
                            <div className="rounded-2xl border border-slate-100 bg-slate-50 px-5 py-6 text-center text-sm text-slate-400">
                                Ingen aktive vurderingspunkter. Legg til ett for å bruke denne malen.
                            </div>
                        )}
                    </div>

                    {inactiveCriteria.length > 0 && (
                        <div className="mt-4 border-t border-slate-100 pt-4">
                            <p className="mb-2 text-xs font-semibold uppercase tracking-widest text-slate-400">Deaktiverte punkter</p>
                            <div className="space-y-2 opacity-60">
                                {inactiveCriteria.map((criterion, idx) => (
                                    <CriterionRow
                                        key={criterion.id}
                                        criterion={criterion}
                                        index={'–'}
                                        onEdit={() => openEdit(criterion)}
                                        onToggle={() => handleToggleCriterion(criterion)}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </CustomerAppLayout>
    );
}

function CriterionRow({ criterion, index, onEdit, onToggle }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50/60 px-4 py-3">
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">{index}</span>
                    <span className="text-sm font-semibold text-slate-900">{criterion.title}</span>
                    <span className="inline-flex items-center rounded border border-slate-200 bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500">
                        Vekt {criterion.weight}{criterion.is_score_reversed ? ' · reversert' : ''}
                    </span>
                </div>
                {criterion.short_description && (
                    <p className="mt-0.5 text-xs leading-5 text-slate-500 line-clamp-1">{criterion.short_description}</p>
                )}
            </div>
            <div className="flex shrink-0 items-center gap-2">
                <button
                    type="button"
                    onClick={onEdit}
                    className="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-600 hover:border-violet-200 hover:text-violet-700"
                >
                    Rediger
                </button>
                <button
                    type="button"
                    onClick={onToggle}
                    className="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-500 hover:border-slate-300"
                >
                    {criterion.is_active ? 'Deaktiver' : 'Aktiver'}
                </button>
            </div>
        </div>
    );
}
