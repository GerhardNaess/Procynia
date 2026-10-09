import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import { SECONDARY_ACTION } from '../../../Support/actionStyles';
import { searchRows, wikiGuidanceVisible, wikiNoteLabel, wikiSearchUrl } from './wikiGuidanceRules';

const INPUT = 'mt-1 min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LINK = 'break-words font-semibold text-violet-700 hover:text-violet-900';
const SMALL_BUTTON = 'min-h-10 rounded-xl border border-slate-200 bg-white px-3 py-1 text-base font-semibold text-slate-900 hover:bg-slate-50';

/** Søk og knytt til: a title search in the person's own Enterprise Wiki, one click per result. */
function LinkForm({ requirementId, guidance, onDone, tr }) {
    const w = tr.wiki_guidance ?? {};
    const { errors = {} } = usePage().props;
    const [term, setTerm] = useState('');
    const [state, setState] = useState({ status: 'loading', pages: [], hasMore: false });
    const [processing, setProcessing] = useState(false);
    const inputId = `wiki-guidance-search-${requirementId}`;

    useEffect(() => {
        const controller = new AbortController();
        const timer = setTimeout(() => {
            setState((current) => ({ ...current, status: 'loading' }));
            fetch(wikiSearchUrl(term), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal })
                .then((response) => {
                    if (! response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    return response.json();
                })
                .then((data) => setState({ status: 'ready', pages: data.pages ?? [], hasMore: Boolean(data.has_more) }))
                .catch((error) => {
                    if (error.name !== 'AbortError') {
                        setState({ status: 'error', pages: [], hasMore: false });
                    }
                });
        }, 250);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term]);

    const link = (page) => {
        router.post(`/app/supplier-management/control-requirements/${requirementId}/wiki-pages`, { wiki_page_id: page.id }, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: onDone,
        });
    };

    const rows = searchRows(state.pages, guidance);

    return (
        <div className="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3" data-testid="wiki-guidance-search">
            <label htmlFor={inputId} className="block text-base font-semibold text-slate-900">{w.search_label ?? 'Søk i Enterprise Wiki'}</label>
            <p id={`${inputId}-hint`} className="text-base text-slate-600">{w.search_hint}</p>
            <input id={inputId} type="search" value={term} onChange={(event) => setTerm(event.target.value)} aria-describedby={`${inputId}-hint`} className={INPUT} autoComplete="off" />
            {errors.wiki_page_id && <p className="mt-1 text-base text-rose-700">{errors.wiki_page_id}</p>}
            <div className="mt-2" aria-live="polite">
                {state.status === 'loading' && <p className="text-base text-slate-600">{w.searching ?? 'Søker …'}</p>}
                {state.status === 'error' && <p className="text-base text-rose-700">{w.search_error ?? 'Søket feilet. Prøv igjen.'}</p>}
                {state.status === 'ready' && rows.length === 0 && <p className="text-base text-slate-600">{w.no_results ?? 'Ingen artikler passer søket.'}</p>}
                {state.status === 'ready' && rows.length > 0 && (
                    <ul className="space-y-2">
                        {rows.map(({ page, linked }) => (
                            <li key={page.id} className="flex min-w-0 flex-wrap items-center justify-between gap-2" data-testid="wiki-guidance-result">
                                <span className="min-w-0 break-words text-base text-slate-900">
                                    {page.title}
                                    {wikiNoteLabel(page.note, tr) && <span className="text-slate-600"> · {wikiNoteLabel(page.note, tr)}</span>}
                                </span>
                                {linked
                                    ? <span className="text-base text-slate-600" data-testid="wiki-guidance-result-linked">{w.already_linked ?? 'Allerede knyttet til kravet'}</span>
                                    : <button type="button" onClick={() => link(page)} disabled={processing} className={SMALL_BUTTON}>{w.link ?? 'Knytt til'}</button>}
                            </li>
                        ))}
                    </ul>
                )}
                {state.status === 'ready' && state.hasMore && <p className="mt-2 text-base text-slate-600" data-testid="wiki-guidance-more">{w.more_results ?? 'Viser de 20 første treffene. Skriv mer av tittelen for å finne riktig artikkel.'}</p>}
            </div>
            <button type="button" onClick={onDone} className={`mt-3 ${SECONDARY_ACTION}`}>{tr.cancel ?? 'Avbryt'}</button>
        </div>
    );
}

/**
 * Veiledning fra Enterprise Wiki (supplier-assurance-v2-plan §28): the Wiki pages linked to a control
 * requirement, each opening in the Wiki. Not shown at all when the server sent no guidance (no Wiki
 * read access), and not shown empty to someone who can only read. Linking and removing are offered
 * when `canManage` (supplier.assure + Wiki read access); the server checks again.
 */
export default function WikiGuidance({ requirementId, guidance, canManage = false, tr }) {
    const w = tr.wiki_guidance ?? {};
    const [open, setOpen] = useState(false);

    if (! wikiGuidanceVisible(guidance, canManage)) {
        return null;
    }

    const remove = (page) => {
        if (window.confirm(w.remove_confirm ?? 'Fjerne artikkelen som veiledning for kravet? Artikkelen i Enterprise Wiki endres ikke.')) {
            router.delete(`/app/supplier-management/control-requirements/${requirementId}/wiki-pages/${page.id}`, { preserveScroll: true });
        }
    };

    return (
        <div className="mt-3 min-w-0 border-t border-slate-100 pt-3" data-testid="wiki-guidance">
            <h3 className="text-base font-semibold text-slate-900">{w.heading ?? 'Veiledning fra Enterprise Wiki'}</h3>
            {guidance.length === 0 ? (
                <p className="text-base text-slate-600" data-testid="wiki-guidance-none">{w.none ?? 'Ingen veiledning er knyttet til kravet.'}</p>
            ) : (
                <ul className="mt-1 space-y-1">
                    {guidance.map((page) => (
                        <li key={page.id} className="flex min-w-0 flex-wrap items-center gap-2" data-testid="wiki-guidance-page">
                            <a href={page.url} target="_blank" rel="noopener noreferrer" className={`text-base ${LINK}`} aria-label={`${page.title} ${w.opens_in_wiki ?? '(åpnes i Enterprise Wiki)'}`}>
                                {page.title}<span aria-hidden="true"> ↗</span>
                            </a>
                            {wikiNoteLabel(page.note, tr) && <StatusBadge tone={page.note === 'archived' ? 'amber' : 'slate'}>{wikiNoteLabel(page.note, tr)}</StatusBadge>}
                            {canManage && (
                                <button type="button" onClick={() => remove(page)} className="min-h-10 text-base font-semibold text-slate-600 underline hover:text-slate-900">{w.remove ?? 'Fjern'}</button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {canManage && ! open && (
                <button type="button" onClick={() => setOpen(true)} className={`mt-2 ${SECONDARY_ACTION}`}>{w.add ?? 'Knytt til artikkel'}</button>
            )}
            {canManage && open && <LinkForm requirementId={requirementId} guidance={guidance} onDone={() => setOpen(false)} tr={tr} />}
        </div>
    );
}
