import { useRef, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import ActionDialog from './ActionDialog';
import StatusBadge from './StatusBadge';
import FormField, { FieldsetField, RequiredFieldsNote } from './FormField';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../Support/actionStyles';
import { INPUT, LABEL, TEXTAREA } from '../../Support/formStyles';
import { useFocusFirstError } from '../../Support/focusManagement';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * «Lag kunnskapsartikkel» — the one Wiki handoff every module shares.
 *
 * The record offers its knowledge as sections built on the server from the module's own data. The
 * person chooses which sections may be shared — none is preselected — and writes the lesson in
 * their own words. Only the chosen section keys, the title and that text are sent; the server
 * rebuilds the sections from the record. The result is a Wiki source that goes through the Wiki's
 * own processing and review before anything is published.
 *
 * A draft may also carry a `context` that always goes with the source (shown, never a choice) and a
 * `notice` for the person handing over.
 *
 * `handoff` is WikiKnowledgeHandoffService::panel(): { can_create, entries, submit_url, draft, limits }.
 *
 * Accessibility (WCAG Fase 5): the fields go through FormField, so a refused hand-over marks the field,
 * ties the message to it and puts focus there (useFocusFirstError); the sections are a named group.
 */
export default function WikiKnowledgeHandoffPanel({ handoff, idPrefix }) {
    const { translations = {} } = usePage().props;
    const t = translations?.knowledge_handoff ?? {};
    const entries = handoff?.entries ?? [];
    const draft = handoff?.draft ?? null;
    const limits = handoff?.limits ?? {};
    const canCreate = Boolean(handoff?.can_create && handoff?.submit_url && draft);
    const [open, setOpen] = useState(false);
    const ta = translations?.a11y ?? {};
    const triggerRef = useRef(null);
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);
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
            onError: () => focusFirstError(),
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
                    <form ref={formRef} onSubmit={submit} className="space-y-4 text-left">
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
                            {draft.notice && (
                                <p className="mt-2 text-base text-amber-900" data-testid="knowledge-handoff-notice">{draft.notice}</p>
                            )}
                        </div>
                        <RequiredFieldsNote text={ta.required_fields} className="text-base text-slate-600" />
                        <FormField
                            id={`${idPrefix}-knowledge-handoff-field-title`}
                            label={t.field_title ?? 'Tittel'}
                            error={form.errors.title}
                            required
                        >
                            {(field) => (
                                <input
                                    {...field}
                                    type="text"
                                    value={form.data.title}
                                    onChange={(event) => form.setData('title', event.target.value)}
                                    maxLength={limits.title ?? 255}
                                    className={INPUT}
                                />
                            )}
                        </FormField>
                        <FormField
                            id={`${idPrefix}-knowledge-handoff-field-learning`}
                            label={t.field_learning ?? 'Læringspunkter'}
                            hint={t.field_learning_help ?? 'Hva bør andre vite eller gjøre annerledes? Skriv med egne ord.'}
                            error={form.errors.learning}
                            required
                        >
                            {(field) => (
                                <textarea
                                    {...field}
                                    value={form.data.learning}
                                    onChange={(event) => form.setData('learning', event.target.value)}
                                    rows={6}
                                    maxLength={limits.learning ?? 12000}
                                    className={TEXTAREA}
                                />
                            )}
                        </FormField>
                        {draft.context && (
                            <div data-testid="knowledge-handoff-context">
                                <p className={LABEL}>{t.context_legend ?? 'Tas alltid med'}</p>
                                <p className="text-base text-slate-600">{t.context_help ?? 'Gir sammenhengen det som deles, skal leses i.'}</p>
                                <div className="mt-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                                    <span className="block text-base font-semibold text-slate-900">{draft.context.heading}</span>
                                    <span className="mt-1 block space-y-1 text-base text-slate-600">
                                        {draft.context.lines.map((line, index) => (
                                            <span key={index} className="block break-words">{line}</span>
                                        ))}
                                    </span>
                                </div>
                            </div>
                        )}
                        {draft.sections.length > 0 && (
                            <FieldsetField
                                id={`${idPrefix}-knowledge-handoff-sections`}
                                legend={t.sections_legend ?? 'Ta med fra kilden'}
                                hint={t.sections_help ?? 'Hentes fra registreringen slik den er nå. Huk av det som kan deles.'}
                                error={form.errors.sections}
                            >
                                {(optionProps) => (
                                    <ul className="mt-2 space-y-2">
                                        {draft.sections.map((section) => {
                                            const inputId = `${idPrefix}-knowledge-handoff-section-${section.key}`;

                                            // The heading names the checkbox; the lines under it are its
                                            // description, so the name stays short.
                                            return (
                                                <li key={section.key} className="flex items-start gap-3 rounded-xl border border-slate-200 p-3">
                                                    <input
                                                        {...optionProps}
                                                        id={inputId}
                                                        type="checkbox"
                                                        aria-describedby={`${inputId}-lines`}
                                                        checked={form.data.sections.includes(section.key)}
                                                        onChange={() => toggleSection(section.key)}
                                                        className="mt-1 h-4 w-4 shrink-0 rounded border-slate-500"
                                                    />
                                                    <div className="min-w-0">
                                                        <label htmlFor={inputId} className="block text-base font-semibold text-slate-900">
                                                            {section.heading}
                                                        </label>
                                                        <span id={`${inputId}-lines`} className="mt-1 block space-y-1 text-base text-slate-600">
                                                            {section.lines.map((line, index) => (
                                                                <span key={index} className="block break-words">{line}</span>
                                                            ))}
                                                        </span>
                                                    </div>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </FieldsetField>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="submit"
                                disabled={form.data.title.trim() === '' || form.data.learning.trim() === ''}
                                aria-disabled={form.processing || undefined}
                                onClick={(event) => {
                                    // aria-disabled while sending: `disabled` would drop focus to the page.
                                    if (form.processing) {
                                        event.preventDefault();
                                    }
                                }}
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
