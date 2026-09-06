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
        amber:   'border-amber-200 bg-amber-50 text-amber-700',
        violet:  'border-violet-200 bg-violet-50 text-violet-700',
    };
    return (
        <span className={classNames('inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold', colors[color] ?? colors.slate)}>
            {children}
        </span>
    );
}

function CreateTemplateModal({ isOpen, onClose }) {
    const form = useForm({ name: '', description: '' });

    function handleSubmit(e) {
        e.preventDefault();
        form.post('/app/go-no-go-templates', {
            onSuccess: () => { form.reset(); onClose(); },
        });
    }

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 px-4">
            <div className="w-full max-w-lg overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-[0_20px_60px_rgba(15,23,42,0.18)]">
                <div className="flex items-center justify-between border-b border-slate-200 px-6 py-5">
                    <h2 className="text-xl font-semibold text-slate-950">Ny vurderingsmal</h2>
                    <button type="button" onClick={onClose} className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:text-slate-900">×</button>
                </div>
                <form onSubmit={handleSubmit} className="px-6 py-6 space-y-4">
                    <div>
                        <label className="block text-sm font-semibold text-slate-700 mb-1">Malnavn <span className="text-rose-500">*</span></label>
                        <input
                            type="text"
                            value={form.data.name}
                            onChange={e => form.setData('name', e.target.value)}
                            placeholder="F.eks. Offentlig sektor 2026"
                            className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                        />
                        {form.errors.name && <p className="mt-1 text-xs text-rose-600">{form.errors.name}</p>}
                    </div>
                    <div>
                        <label className="block text-sm font-semibold text-slate-700 mb-1">Beskrivelse</label>
                        <textarea
                            rows={2}
                            value={form.data.description}
                            onChange={e => form.setData('description', e.target.value)}
                            placeholder="Valgfri beskrivelse av når denne malen brukes"
                            className="w-full resize-none rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-100"
                        />
                    </div>
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
            </div>
        </div>
    );
}

export default function GoNoGoTemplatesIndex({ templates }) {
    const { flash = {} } = usePage().props;
    const [showCreate, setShowCreate] = useState(false);

    function handleToggleActive(template) {
        router.patch(template.toggle_active_url, {}, { preserveScroll: true });
    }

    function handleSetDefault(template) {
        router.patch(template.set_default_url, {}, { preserveScroll: true });
    }

    return (
        <CustomerAppLayout title="Vurderingsmaler">
            <CreateTemplateModal isOpen={showCreate} onClose={() => setShowCreate(false)} />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-slate-950">Vurderingsmaler</h1>
                        <p className="mt-1 text-sm text-slate-500">
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

                {/* Flash */}
                {(flash.success || flash.error) && (
                    <div className={classNames(
                        'rounded-2xl border px-4 py-3 text-sm font-medium',
                        flash.error ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800',
                    )}>
                        {flash.error ?? flash.success}
                    </div>
                )}

                {/* Template list */}
                <div className="space-y-3">
                    {templates.map(template => (
                        <div
                            key={template.id}
                            className={classNames(
                                'rounded-3xl border bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]',
                                template.is_active ? 'border-slate-200' : 'border-slate-200 opacity-60',
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
                                        <p className="mt-0.5 text-sm text-slate-500">{template.description}</p>
                                    )}
                                    <p className="mt-1 text-xs text-slate-400">{template.criteria_count} vurderingspunkt{template.criteria_count !== 1 ? 'er' : ''}</p>
                                </div>

                                <div className="flex shrink-0 flex-wrap items-center gap-2">
                                    {!template.is_default && template.is_active && (
                                        <button
                                            type="button"
                                            onClick={() => handleSetDefault(template)}
                                            className="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:border-violet-200 hover:text-violet-700"
                                        >
                                            Sett som standard
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        onClick={() => handleToggleActive(template)}
                                        disabled={template.is_default && template.is_active}
                                        className="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:border-slate-300 disabled:cursor-not-allowed disabled:opacity-40"
                                    >
                                        {template.is_active ? 'Deaktiver' : 'Aktiver'}
                                    </button>
                                    <a
                                        href={template.edit_url}
                                        className="rounded-full border border-violet-200 bg-violet-50 px-3 py-1.5 text-xs font-semibold text-violet-700 hover:bg-violet-100"
                                    >
                                        Rediger
                                    </a>
                                </div>
                            </div>
                        </div>
                    ))}

                    {templates.length === 0 && (
                        <div className="rounded-3xl border border-slate-200 bg-slate-50 px-6 py-10 text-center text-sm text-slate-400">
                            Ingen vurderingsmaler funnet.
                        </div>
                    )}
                </div>
            </div>
        </CustomerAppLayout>
    );
}
