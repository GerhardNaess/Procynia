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
        amber:   'border-amber-200 bg-amber-50 text-amber-700',
        violet:  'border-violet-200 bg-violet-50 text-violet-700',
    };
    return (
        <span className={classNames('inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold', colors[color] ?? colors.slate)}>
            {children}
        </span>
    );
}

const FIELD_LABEL = 'block text-sm font-semibold text-slate-700';
const FIELD_CONTROL = 'w-full rounded-xl border border-field-border px-3 py-2 text-sm text-slate-900 placeholder:text-slate-500 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100';
const FIELD_ERROR = 'mt-1 text-xs text-rose-700';

function CreateTemplateModal({ isOpen, onClose, a11y }) {
    const form = useForm({ name: '', description: '' });
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);

    function handleSubmit(e) {
        e.preventDefault();
        form.post('/app/go-no-go-templates', {
            onSuccess: () => { form.reset(); onClose(); },
            onError: focusFirstError,
        });
    }

    return (
        <ActionDialog
            isOpen={isOpen}
            onClose={onClose}
            closeDisabled={form.processing}
            titleId="create-template-title"
            descriptionId="create-template-required"
            size="sm"
            padded={false}
        >
            <DialogTitleBar
                titleId="create-template-title"
                title="Ny vurderingsmal"
                onClose={onClose}
                closeLabel={a11y.close_dialog ?? 'Lukk dialog'}
            />
            <form ref={formRef} onSubmit={handleSubmit} className="px-6 py-6 space-y-4">
                <div id="create-template-required">
                    <RequiredFieldsNote text={a11y.required_fields} />
                </div>
                <FormField
                    id="create-template-name"
                    label="Malnavn"
                    required
                    error={form.errors.name}
                    labelClassName={FIELD_LABEL}
                    labelRowClassName="mb-1 flex items-center gap-1.5"
                    controlClassName=""
                    errorClassName={FIELD_ERROR}
                >
                    {(field) => (
                        <input
                            {...field}
                            type="text"
                            value={form.data.name}
                            onChange={e => form.setData('name', e.target.value)}
                            placeholder="F.eks. Offentlig sektor 2026"
                            className={FIELD_CONTROL}
                        />
                    )}
                </FormField>
                <FormField
                    id="create-template-description"
                    label="Beskrivelse"
                    error={form.errors.description}
                    labelClassName={FIELD_LABEL}
                    labelRowClassName="mb-1 flex items-center gap-1.5"
                    controlClassName=""
                    errorClassName={FIELD_ERROR}
                >
                    {(field) => (
                        <textarea
                            {...field}
                            rows={2}
                            value={form.data.description}
                            onChange={e => form.setData('description', e.target.value)}
                            placeholder="Valgfri beskrivelse av når denne malen brukes"
                            className={`resize-none ${FIELD_CONTROL}`}
                        />
                    )}
                </FormField>
                <div className="flex justify-end gap-3 pt-2">
                    <button type="button" onClick={onClose} className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:border-slate-300">Avbryt</button>
                    <button
                        type="submit"
                        disabled={form.processing || !form.data.name.trim()}
                        className={`rounded-xl px-4 py-2 text-sm font-semibold shadow-sm disabled:opacity-50 ${PRIMARY_COLOURS}`}
                    >
                        Opprett mal
                    </button>
                </div>
            </form>
        </ActionDialog>
    );
}

export default function GoNoGoTemplatesIndex({ templates }) {
    const { translations = {} } = usePage().props;
    const a11y = translations.a11y ?? {};
    const [showCreate, setShowCreate] = useState(false);

    function handleToggleActive(template) {
        router.patch(template.toggle_active_url, {}, { preserveScroll: true });
    }

    function handleSetDefault(template) {
        router.patch(template.set_default_url, {}, { preserveScroll: true });
    }

    return (
        <CustomerAppLayout title="Vurderingsmaler" showPageTitle={false}>
            <CreateTemplateModal isOpen={showCreate} onClose={() => setShowCreate(false)} a11y={a11y} />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-slate-950">Vurderingsmaler</h1>
                        <p className="mt-1 text-sm text-slate-600">
                            Definer vurderingspunkter og vekting for Go/No-go-fasen. Kun System Owner kan administrere maler.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setShowCreate(true)}
                        className={`shrink-0 inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold shadow-sm ${PRIMARY_COLOURS}`}
                    >
                        + Ny mal
                    </button>
                </div>

                {/* Template list */}
                {templates.length > 0 && (
                <ul className="space-y-3" aria-label={a11y.templates_list ?? 'Vurderingsmaler'}>
                    {templates.map(template => (
                        // An inactive template used to be faded with opacity-60, which took every text on
                        // the card under 4.5:1 (A11Y-017). The «Inaktiv» badge says it; the card is only tinted.
                        <li
                            key={template.id}
                            className={classNames(
                                'rounded-3xl border p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]',
                                template.is_active ? 'border-slate-200 bg-white' : 'border-slate-200 bg-slate-50',
                            )}
                        >
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-base font-semibold text-slate-900">{template.name}</span>
                                        {template.is_default && <Badge color="violet">Standard</Badge>}
                                        {!template.is_active && <Badge color="amber">Inaktiv</Badge>}
                                    </div>
                                    {template.description && (
                                        <p className="mt-0.5 text-sm text-slate-600">{template.description}</p>
                                    )}
                                    <p className="mt-1 text-xs text-slate-600">{template.criteria_count} vurderingspunkt{template.criteria_count !== 1 ? 'er' : ''}</p>
                                </div>

                                <div className="flex shrink-0 flex-wrap items-center gap-2">
                                    {!template.is_default && template.is_active && (
                                        <button
                                            type="button"
                                            onClick={() => handleSetDefault(template)}
                                            aria-label={`Sett som standard: ${template.name}`}
                                            className="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:border-violet-200 hover:text-violet-700"
                                        >
                                            Sett som standard
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        onClick={() => handleToggleActive(template)}
                                        disabled={template.is_default && template.is_active}
                                        aria-label={`${template.is_active ? 'Deaktiver' : 'Aktiver'} ${template.name}`}
                                        className="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:border-slate-300 disabled:cursor-not-allowed disabled:opacity-40"
                                    >
                                        {template.is_active ? 'Deaktiver' : 'Aktiver'}
                                    </button>
                                    <a
                                        href={template.edit_url}
                                        aria-label={`Rediger ${template.name}`}
                                        className="rounded-full border border-violet-200 bg-violet-50 px-3 py-1.5 text-xs font-semibold text-violet-700 hover:bg-violet-100"
                                    >
                                        Rediger
                                    </a>
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>
                )}

                {templates.length === 0 && (
                    <div className="rounded-3xl border border-slate-200 bg-slate-50 px-6 py-10 text-center text-sm text-slate-600">
                        Ingen vurderingsmaler funnet.
                    </div>
                )}
            </div>
        </CustomerAppLayout>
    );
}
