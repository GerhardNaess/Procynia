import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import ActionDialog from './ActionDialog';
import {
    DESTRUCTIVE_CONFIRM,
    SECONDARY_ACTION,
} from '../../Support/actionStyles';

/**
 * The `⋯` menu on one row of the Kvalitet list.
 *
 * Deleting a whole styrende dokument is done here and only here. It is not on the document's own
 * page: a page about one document is not the place to decide that document should not exist, and
 * from the list the consequence is plain — one row among its siblings, about to stop being one.
 * The Flyt tab's "Slett flyt" is a different action on a different object and lives in
 * ProcessFlowPanel.
 *
 * The confirmation is a dialog rather than window.confirm because what the delete keeps matters as
 * much as what it removes: the articles the process's activities were the source of stay in Wiki,
 * and a one-line browser prompt has no room to say so. See QualityItemService::deleteItem.
 *
 * @param {object} item  The row's item. `id`, `title` and `quality_type`, plus
 *                       `produced_wiki_page_count` and `can_delete_produced_wiki_pages`, which
 *                       decide whether the dialog asks what should happen to the knowledge.
 * @param {string} [tab] The Kvalitet tab to come back to. Deleting from the Prosesser list should
 *                       leave the user in Prosesser, not bounce them to Oversikt.
 */
export default function QualityItemActions({ tq, item, tab = null }) {
    const [isMenuOpen, setIsMenuOpen] = useState(false);
    const [isConfirmOpen, setIsConfirmOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [deleteWikiPages, setDeleteWikiPages] = useState(false);
    const [menuPosition, setMenuPosition] = useState(null);
    const menuRef = useRef(null);
    const triggerRef = useRef(null);
    const cancelRef = useRef(null);

    const isProcess = item.quality_type === 'process';
    // A control is not a document, and the dialog never calls it one.
    const isControl = item.quality_type === 'control';
    const producedWikiPageCount = item.produced_wiki_page_count ?? 0;
    const hasProducedWikiPages = producedWikiPageCount > 0;
    // The choice is only offered when the backend says this reader may actually take it. Offering
    // it otherwise would mean putting a radio button on a request the controller refuses.
    const mayChooseWikiDeletion = hasProducedWikiPages && Boolean(item.can_delete_produced_wiki_pages);

    // Fixed rather than absolute, measured off the trigger. The menu lives inside a table cell
    // under `overflow-x-auto`, and a scroll container clips on both axes — an absolutely positioned
    // menu would be cut off at the row it belongs to. Fixed escapes the container, at the cost of
    // having to close on scroll and resize rather than follow the trigger.
    useEffect(() => {
        if (! isMenuOpen) {
            return undefined;
        }

        const place = () => {
            const rect = triggerRef.current?.getBoundingClientRect();

            if (rect) {
                setMenuPosition({ top: rect.bottom + 8, right: window.innerWidth - rect.right });
            }
        };

        const close = () => setIsMenuOpen(false);

        const handlePointerDown = (event) => {
            if (! menuRef.current?.contains(event.target) && ! triggerRef.current?.contains(event.target)) {
                setIsMenuOpen(false);
            }
        };

        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                setIsMenuOpen(false);
                triggerRef.current?.focus();
            }
        };

        place();
        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);
        window.addEventListener('resize', close);
        window.addEventListener('scroll', close, true);

        return () => {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
            window.removeEventListener('resize', close);
            window.removeEventListener('scroll', close, true);
        };
    }, [isMenuOpen]);

    const menuLabel = tq.actions_menu ?? 'Handlinger';
    const deleteLabel = isProcess
        ? (tq.delete_process ?? 'Slett prosess')
        : isControl
            ? (tq.delete_control ?? 'Slett kontroll')
            : (tq.delete_item ?? 'Slett dokument');

    return (
        <>
            <button
                ref={triggerRef}
                type="button"
                aria-haspopup="menu"
                aria-expanded={isMenuOpen}
                aria-label={`${menuLabel} – ${item.title}`}
                className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-transparent text-slate-500 transition hover:border-slate-200 hover:bg-slate-50 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-500"
                onClick={() => setIsMenuOpen((open) => ! open)}
            >
                <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M4 10a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Zm4.5 0a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Zm4.5 0a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Z" />
                </svg>
            </button>

            {isMenuOpen && menuPosition && (
                <div
                    ref={menuRef}
                    role="menu"
                    style={{ top: `${menuPosition.top}px`, right: `${menuPosition.right}px` }}
                    className="fixed z-40 w-60 rounded-2xl border border-slate-200 bg-white p-1.5 text-left shadow-lg"
                >
                    <button
                        type="button"
                        role="menuitem"
                        className="block w-full rounded-xl px-3 py-2 text-left text-base font-semibold text-rose-700 transition hover:bg-rose-50"
                        onClick={() => {
                            setIsMenuOpen(false);
                            // Keeping the knowledge is the default, re-asserted every time the
                            // dialog opens: a choice made and abandoned must never carry over.
                            setDeleteWikiPages(false);
                            setIsConfirmOpen(true);
                        }}
                    >
                        {deleteLabel}
                    </button>
                </div>
            )}

            <ActionDialog
                isOpen={isConfirmOpen}
                onClose={() => setIsConfirmOpen(false)}
                closeDisabled={deleting}
                titleId={`quality-item-delete-title-${item.id}`}
                initialFocusRef={cancelRef}
                returnFocusRef={triggerRef}
            >
                {/* The dialog is fixed, but it is still a DOM descendant of whatever opened it —
                    in the list, a right-aligned table cell, whose text-align it would otherwise
                    inherit and read ragged-left. The dialog sets its own alignment. */}
                <div className="text-left">
                    <h2
                        id={`quality-item-delete-title-${item.id}`}
                        className="text-xl font-semibold tracking-tight text-slate-950"
                    >
                        {isProcess
                            ? (tq.delete_dialog_title_process ?? 'Slett prosessen?')
                            : isControl
                                ? (tq.delete_dialog_title_control ?? 'Slett kontrollen?')
                                : (tq.delete_dialog_title_item ?? 'Slett dokumentet?')}
                    </h2>
                    <p className="mt-2 text-base leading-6 text-slate-600">{item.title}</p>

                    <dl className="mt-5 space-y-4">
                        <div>
                            <dt className="text-sm font-semibold uppercase tracking-wide text-rose-700">
                                {tq.delete_dialog_removed_heading ?? 'Dette slettes'}
                            </dt>
                            <dd className="mt-1 text-base leading-6 text-slate-700">
                                {isProcess
                                    ? (tq.delete_dialog_removed_process ?? 'Prosessen, prosessflyten med aktivitetene sine, relasjonene og koblingene til Wiki-sider og filer — og prosessen med aktivitetene sine i kunnskapsgrafen.')
                                    : isControl
                                        ? (tq.delete_dialog_removed_control ?? 'Kontrollen, plasseringene på aktiviteter, evidensen som er registrert og koblingene til Wiki-sider og filer.')
                                        : (tq.delete_dialog_removed_item ?? 'Dokumentet, strukturen, relasjonene og koblingene til Wiki-sider og filer.')}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-sm font-semibold uppercase tracking-wide text-slate-500">
                                {tq.delete_dialog_kept_heading ?? 'Dette beholdes'}
                            </dt>
                            <dd className="mt-1 text-base leading-6 text-slate-700">
                                {deleteWikiPages
                                    ? (tq.delete_dialog_kept_body_without_wiki ?? 'Kildedokumentene med filene sine, og alle andre Wiki-sider. Bare sidene denne prosessen selv har produsert følger med.')
                                    : (tq.delete_dialog_kept_body ?? 'Wiki-sidene, artiklene aktivitetene har vært kilde til, og filene i kildedokumentene. Kunnskap som allerede er produsert forsvinner ikke med prosessen.')}
                            </dd>
                        </div>
                    </dl>

                    {hasProducedWikiPages && (
                        <WikiKnowledgeChoice
                            tq={tq}
                            itemId={item.id}
                            count={producedWikiPageCount}
                            mayChoose={mayChooseWikiDeletion}
                            deleteWikiPages={deleteWikiPages}
                            onChange={setDeleteWikiPages}
                            disabled={deleting}
                        />
                    )}

                    <p className="mt-4 text-base font-semibold text-slate-700">
                        {tq.delete_dialog_irreversible ?? 'Handlingen kan ikke angres.'}
                    </p>

                    <div className="mt-6 flex flex-wrap gap-3">
                        <button
                            type="button"
                            className={DESTRUCTIVE_CONFIRM}
                            disabled={deleting}
                            onClick={() => {
                                setDeleting(true);
                                // The controller redirects to the Kvalitet index itself, so there is
                                // nothing to navigate to here — only a reason to keep the dialog from
                                // being used twice while the request is in flight.
                                // Query string rather than a DELETE body: `tab` already travels
                                // this way, and the two belong together.
                                const query = new URLSearchParams();

                                if (tab) {
                                    query.set('tab', tab);
                                }

                                if (deleteWikiPages) {
                                    query.set('delete_wiki_pages', '1');
                                }

                                const suffix = query.toString();

                                router.delete(
                                    `/app/quality/items/${item.id}${suffix ? `?${suffix}` : ''}`,
                                    { onFinish: () => setDeleting(false) },
                                );
                            }}
                        >
                            {deleting
                                ? (tq.delete_dialog_deleting ?? 'Sletter …')
                                : (tq.delete_dialog_confirm ?? 'Slett')}
                        </button>
                        <button
                            ref={cancelRef}
                            type="button"
                            className={SECONDARY_ACTION}
                            disabled={deleting}
                            onClick={() => setIsConfirmOpen(false)}
                        >
                            {tq.delete_dialog_cancel ?? 'Avbryt'}
                        </button>
                    </div>
                </div>
            </ActionDialog>
        </>
    );
}

/**
 * What happens to the knowledge when the process goes.
 *
 * Two separate decisions in one dialog, and the second one is only ever asked when there is
 * something to decide about: a process that produced no Wiki pages gets the ordinary confirmation
 * and nothing more. The default is to keep, because the articles are the virksomhet's and were
 * knowledge before the process was deleted — losing them has to be chosen, never defaulted into.
 *
 * `mayChoose` is the backend's answer to whether this reader may delete every one of those pages
 * (Wiki's own authority, not Kvalitet's). When it is false the section still appears — the count is
 * worth knowing either way — but as a statement rather than a question.
 */
function WikiKnowledgeChoice({ tq, itemId, count, mayChoose, deleteWikiPages, onChange, disabled }) {
    const countText = count === 1
        ? (tq.delete_dialog_wiki_count_one ?? 'Prosessen har produsert én Wiki-side.')
        : (tq.delete_dialog_wiki_count_many ?? 'Prosessen har produsert :count Wiki-sider.').replace(':count', count);

    return (
        <section className="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <h3 className="text-sm font-semibold uppercase tracking-wide text-slate-500">
                {tq.delete_dialog_wiki_heading ?? 'Wiki-kunnskap fra denne prosessen'}
            </h3>
            <p className="mt-1 text-base leading-6 text-slate-700">{countText}</p>

            {! mayChoose && (
                <p className="mt-2 text-base leading-6 text-slate-600">
                    {tq.delete_dialog_wiki_no_permission ?? 'Sidene blir stående i Wiki. Du har ikke tilgang til å slette dem herfra.'}
                </p>
            )}

            {mayChoose && (
                <div className="mt-3 space-y-3">
                    <label className="flex gap-3" htmlFor={`quality-wiki-keep-${itemId}`}>
                        <input
                            id={`quality-wiki-keep-${itemId}`}
                            type="radio"
                            name={`quality-wiki-choice-${itemId}`}
                            className="mt-1.5 h-4 w-4 shrink-0 accent-violet-600"
                            checked={! deleteWikiPages}
                            disabled={disabled}
                            onChange={() => onChange(false)}
                        />
                        <span>
                            <span className="block text-base font-semibold text-slate-900">
                                {tq.delete_dialog_wiki_keep ?? 'Behold Wiki-kunnskapen'}
                            </span>
                            <span className="block text-sm leading-5 text-slate-600">
                                {tq.delete_dialog_wiki_keep_hint ?? 'Sidene blir stående i Wiki. Kunnskapen er virksomhetens, ikke prosessens.'}
                            </span>
                        </span>
                    </label>

                    <label className="flex gap-3" htmlFor={`quality-wiki-delete-${itemId}`}>
                        <input
                            id={`quality-wiki-delete-${itemId}`}
                            type="radio"
                            name={`quality-wiki-choice-${itemId}`}
                            className="mt-1.5 h-4 w-4 shrink-0 accent-rose-600"
                            checked={deleteWikiPages}
                            disabled={disabled}
                            onChange={() => onChange(true)}
                        />
                        <span>
                            <span className="block text-base font-semibold text-rose-700">
                                {tq.delete_dialog_wiki_delete ?? 'Slett Wiki-sidene som denne prosessen har produsert'}
                            </span>
                            <span className="block text-sm leading-5 text-slate-600">
                                {tq.delete_dialog_wiki_delete_hint ?? 'Bare sider som kan spores til denne prosessen. Kildedokumentene og alle andre Wiki-sider beholdes.'}
                            </span>
                        </span>
                    </label>
                </div>
            )}
        </section>
    );
}
