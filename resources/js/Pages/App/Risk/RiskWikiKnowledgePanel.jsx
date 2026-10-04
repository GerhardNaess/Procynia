import { useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import ActionDialog from '../../../Components/App/ActionDialog';
import StatusBadge from '../../../Components/App/StatusBadge';
import RequiredMark from './RequiredMark';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-sm font-semibold text-slate-700';

/**
 * Knowledge handed over from this risk to Enterprise Wiki. The dialog starts empty on purpose:
 * nothing of the risk is prefilled — the person writes what may be shared, and the server stores it
 * as an ordinary Wiki source. Entries are read live from the Wiki; a link is only sent to someone
 * who may read the Wiki.
 */
export default function RiskWikiKnowledgePanel({ riskId, entries, canCreate, tr }) {
    const tw = tr.wiki_knowledge ?? {};
    const [open, setOpen] = useState(false);
    const triggerRef = useRef(null);
    const form = useForm({ title: '', markdown: '' });

    const close = () => {
        setOpen(false);
        form.reset();
        form.clearErrors();
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/risk/risks/${riskId}/wiki-knowledge`, {
            preserveScroll: true,
            onSuccess: () => close(),
        });
    };

    return (
        <section className={CARD}>
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="text-lg font-semibold text-slate-950">{tw.title ?? 'Kunnskap delt til Wiki'}</h2>
                    <p className="mt-1 text-sm text-slate-600">
                        {tw.description ?? 'Gjenbrukbar læring fra denne risikoen, delt med Enterprise Wiki. Innholdet gjennomgås i Wiki før det publiseres.'}
                    </p>
                </div>
                {canCreate && (
                    <button ref={triggerRef} type="button" onClick={() => setOpen(true)} className={SECONDARY_ACTION}>
                        {tw.create ?? 'Lag kunnskapsartikkel'}
                    </button>
                )}
            </div>

            {entries.length === 0 ? (
                <p className="mt-4 text-base text-slate-600">{tw.empty ?? 'Ingen kunnskap er delt fra denne risikoen ennå.'}</p>
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

            <ActionDialog
                isOpen={open}
                onClose={close}
                closeDisabled={form.processing}
                titleId={`risk-wiki-knowledge-title-${riskId}`}
                returnFocusRef={triggerRef}
            >
                <form onSubmit={submit} className="space-y-4 text-left">
                    <h2 id={`risk-wiki-knowledge-title-${riskId}`} className="text-xl font-semibold tracking-tight text-slate-950">
                        {tw.dialog_title ?? 'Lag kunnskapsartikkel'}
                    </h2>
                    <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                        <p className="text-base font-semibold text-amber-900">
                            {tw.help ?? 'Del gjenbrukbar læring – ikke sensitive detaljer om sårbarheter, hendelser eller virksomhetens risikostatus.'}
                        </p>
                        <p className="mt-1 text-sm text-amber-900">
                            {tw.help_detail ?? 'Ingenting fra risikoen kopieres automatisk. Teksten gjennomgås i Wiki før noe publiseres.'}
                        </p>
                    </div>
                    <div>
                        <label htmlFor="risk-wiki-knowledge-title" className={LABEL}>{tw.field_title ?? 'Tittel'}<RequiredMark /></label>
                        <input
                            id="risk-wiki-knowledge-title"
                            type="text"
                            aria-required="true"
                            value={form.data.title}
                            onChange={(event) => form.setData('title', event.target.value)}
                            maxLength={255}
                            className={`mt-1 ${INPUT}`}
                        />
                        {form.errors.title && <p className="mt-1 text-sm text-rose-700">{form.errors.title}</p>}
                    </div>
                    <div>
                        <label htmlFor="risk-wiki-knowledge-markdown" className={LABEL}>{tw.field_markdown ?? 'Kunnskapsinnhold'}<RequiredMark /></label>
                        <p id="risk-wiki-knowledge-markdown-hint" className="text-sm text-slate-600">
                            {tw.field_markdown_help ?? 'Skriv med egne ord, slik at andre kan bruke læringen.'}
                        </p>
                        <textarea
                            id="risk-wiki-knowledge-markdown"
                            aria-required="true"
                            aria-describedby="risk-wiki-knowledge-markdown-hint"
                            value={form.data.markdown}
                            onChange={(event) => form.setData('markdown', event.target.value)}
                            rows={10}
                            maxLength={12000}
                            className={`mt-1 ${INPUT}`}
                        />
                        {form.errors.markdown && <p className="mt-1 text-sm text-rose-700">{form.errors.markdown}</p>}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="submit"
                            disabled={form.processing || form.data.title.trim() === '' || form.data.markdown.trim() === ''}
                            className={PRIMARY_ACTION}
                        >
                            {tw.submit ?? 'Send til Wiki'}
                        </button>
                        <button type="button" onClick={close} disabled={form.processing} className={SECONDARY_ACTION}>
                            {tw.cancel ?? 'Avbryt'}
                        </button>
                    </div>
                </form>
            </ActionDialog>
        </section>
    );
}
