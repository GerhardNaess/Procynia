import { useRef, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import ActionDialog from './ActionDialog';
import StatusBadge from './StatusBadge';
import RequiredMark from '../../Pages/App/Risk/RequiredMark';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../Support/actionStyles';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';

/**
 * «Lag kunnskapsartikkel» — the one Wiki handoff every module shares.
 *
 * The record offers its knowledge as sections built on the server from the module's own data. The
 * person chooses which sections may be shared — none is preselected — and writes the lesson in
 * their own words. Only the chosen section keys, the title and that text are sent; the server
 * rebuilds the sections from the record. The result is a Wiki source that goes through the Wiki's
 * own processing and review before anything is published.
 *
 * `handoff` is WikiKnowledgeHandoffService::panel(): { can_create, entries, submit_url, draft, limits }.
 */
export default function WikiKnowledgeHandoffPanel({ handoff, idPrefix }) {
    const { translations = {} } = usePage().props;
    const t = translations?.knowledge_handoff ?? {};
    const entries = handoff?.entries ?? [];
    const draft = handoff?.draft ?? null;
    const limits = handoff?.limits ?? {};
    const canCreate = Boolean(handoff?.can_create && handoff?.submit_url && draft);
    const [open, setOpen] = useState(false);
    const triggerRef = useRef(null);
    const form = useForm({ title: draft?.title ?? '', learning: '', sections: [] });

    const close = () => {
        setOpen(false);
        form.reset();
        form.clearErrors();
    };

    const toggleSection = (key) => {
        form.setData('sections', form.data.sections.includes(key)
            ? form.data.sections.filter((value) => value !== key)
            : [...form.data.sections, key]);
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(handoff.submit_url, {
            preserveScroll: true,
            onSuccess: () => close(),
        });
    };

    const titleId = `${idPrefix}-knowledge-handoff-title`;

    // Someone who can neither hand over nor see anything handed over has nothing to read here.
    if (!canCreate && entries.length === 0) {
        return null;
    }

    return (
        <section className={CARD}>
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="text-lg font-semibold text-slate-950">{t.title ?? 'Kunnskap delt til Wiki'}</h2>
                    <p className="mt-1 text-base text-slate-600">
                        {t.description ?? 'Gjenbrukbar læring herfra, delt med Enterprise Wiki. Innholdet bearbeides og gjennomgås i Wiki før det publiseres.'}
                    </p>
                </div>
                {canCreate && !limits.reached && (
                    <button ref={triggerRef} type="button" onClick={() => setOpen(true)} className={SECONDARY_ACTION}>
                        {t.create ?? 'Lag kunnskapsartikkel'}
                    </button>
                )}
            </div>

            {canCreate && limits.reached && (
                <p className="mt-3 text-base text-slate-600">{t.limit_reached ?? 'Grensen for antall kunnskapsartikler herfra er nådd.'}</p>
            )}

            {entries.length === 0 ? (
                <p className="mt-4 text-base text-slate-600">{t.empty ?? 'Ingen kunnskap er delt herfra ennå.'}</p>
            ) : (
                <ul className="mt-4 divide-y divide-slate-100">
                    {entries.map((entry) => (
                        <li key={entry.key} className="flex flex-wrap items-center gap-2 py-3">
                            {entry.url ? (
                                <a href={entry.url} className="text-base font-semibold text-violet-700 hover:text-violet-900">
                                    {entry.title}
                                </a>
                            ) : (
                                <span className="text-base font-semibold text-slate-900">{entry.title}</span>
                            )}
                            <StatusBadge tone={entry.kind === 'page' ? 'violet' : 'slate'}>{entry.state_label}</StatusBadge>
                        </li>
                    ))}
                </ul>
            )}

            {canCreate && (
                <ActionDialog
                    isOpen={open}
                    onClose={close}
                    closeDisabled={form.processing}
                    titleId={titleId}
                    returnFocusRef={triggerRef}
                >
                    <form onSubmit={submit} className="space-y-4 text-left">
                        <h2 id={titleId} className="text-xl font-semibold tracking-tight text-slate-950">
                            {t.dialog_title ?? 'Lag kunnskapsartikkel'}
                        </h2>
                        <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                            <p className="text-base font-semibold text-amber-900">
                                {t.help ?? 'Del gjenbrukbar læring – ikke sensitive detaljer.'}
                            </p>
                            <p className="mt-1 text-base text-amber-900">
                                {t.help_detail ?? 'Velg hva som kan deles. Ingenting er valgt på forhånd, og ingenting publiseres før det er gjennomgått i Wiki.'}
                            </p>
                        </div>
                        <div>
                            <label htmlFor={`${idPrefix}-knowledge-handoff-field-title`} className={LABEL}>{t.field_title ?? 'Tittel'}<RequiredMark /></label>
                            <input
                                id={`${idPrefix}-knowledge-handoff-field-title`}
                                type="text"
                                aria-required="true"
                                value={form.data.title}
                                onChange={(event) => form.setData('title', event.target.value)}
                                maxLength={limits.title ?? 255}
                                className={`mt-1 ${INPUT}`}
                            />
                            {form.errors.title && <p className="mt-1 text-base text-rose-700">{form.errors.title}</p>}
                        </div>
                        <div>
                            <label htmlFor={`${idPrefix}-knowledge-handoff-field-learning`} className={LABEL}>{t.field_learning ?? 'Læringspunkter'}<RequiredMark /></label>
                            <p id={`${idPrefix}-knowledge-handoff-field-learning-hint`} className="text-base text-slate-600">
                                {t.field_learning_help ?? 'Hva bør andre vite eller gjøre annerledes? Skriv med egne ord.'}
                            </p>
                            <textarea
                                id={`${idPrefix}-knowledge-handoff-field-learning`}
                                aria-required="true"
                                aria-describedby={`${idPrefix}-knowledge-handoff-field-learning-hint`}
                                value={form.data.learning}
                                onChange={(event) => form.setData('learning', event.target.value)}
                                rows={6}
                                maxLength={limits.learning ?? 12000}
                                className={`mt-1 ${INPUT}`}
                            />
                            {form.errors.learning && <p className="mt-1 text-base text-rose-700">{form.errors.learning}</p>}
                        </div>
                        {draft.sections.length > 0 && (
                            <fieldset>
                                <legend className={LABEL}>{t.sections_legend ?? 'Ta med fra kilden'}</legend>
                                <p className="text-base text-slate-600">{t.sections_help ?? 'Hentes fra registreringen slik den er nå. Huk av det som kan deles.'}</p>
                                <ul className="mt-2 space-y-2">
                                    {draft.sections.map((section) => {
                                        const inputId = `${idPrefix}-knowledge-handoff-section-${section.key}`;

                                        return (
                                            <li key={section.key} className="rounded-xl border border-slate-200 p-3">
                                                <label htmlFor={inputId} className="flex items-start gap-3">
                                                    <input
                                                        id={inputId}
                                                        type="checkbox"
                                                        checked={form.data.sections.includes(section.key)}
                                                        onChange={() => toggleSection(section.key)}
                                                        className="mt-1 h-4 w-4 rounded border-slate-300"
                                                    />
                                                    <span className="min-w-0">
                                                        <span className="block text-base font-semibold text-slate-900">{section.heading}</span>
                                                        <span className="mt-1 block space-y-1 text-base text-slate-600">
                                                            {section.lines.map((line, index) => (
                                                                <span key={index} className="block break-words">{line}</span>
                                                            ))}
                                                        </span>
                                                    </span>
                                                </label>
                                            </li>
                                        );
                                    })}
                                </ul>
                                {form.errors.sections && <p className="mt-1 text-base text-rose-700">{form.errors.sections}</p>}
                            </fieldset>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="submit"
                                disabled={form.processing || form.data.title.trim() === '' || form.data.learning.trim() === ''}
                                className={PRIMARY_ACTION}
                            >
                                {t.submit ?? 'Send til Wiki'}
                            </button>
                            <button type="button" onClick={close} disabled={form.processing} className={SECONDARY_ACTION}>
                                {t.cancel ?? 'Avbryt'}
                            </button>
                        </div>
                    </form>
                </ActionDialog>
            )}
        </section>
    );
}
