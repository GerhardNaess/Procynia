import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import ActionDialog from './ActionDialog';
import {
    DESTRUCTIVE_CONFIRM,
    DISCLOSURE_INLINE,
    SECONDARY_ACTION,
} from '../../Support/actionStyles';

/**
 * One styrende dokument's handlingsmeny, wherever the document is read.
 *
 * Two surfaces, one definition. On the detail page it sits in the header — not at the foot of
 * Styringsinformasjon, which a process replaces entirely with the Flyt tab, leaving a user looking
 * at the flow they wanted rid of with nowhere to act. In the Kvalitet list it is the row's own `⋯`,
 * so a process can be removed from the list it is cluttering without opening it first. The actions
 * and the confirmation have to read the same in both places, which is why this is a component
 * rather than the same markup twice.
 *
 * The confirmation is a dialog rather than window.confirm because what the delete keeps matters as
 * much as what it removes: the articles the process's activities were the source of stay in Wiki,
 * and a one-line browser prompt has no room to say so. See QualityItemService::deleteItem.
 *
 * @param {object} item     The row or page's item. Only `id`, `title` and `quality_type` are read.
 * @param {string} variant  'header' (labelled button) or 'row' (icon-only `⋯` in a table cell).
 * @param {string} [tab]    The Kvalitet tab to come back to. Deleting from the Prosesser list should
 *                          leave the user in Prosesser, not bounce them to Oversikt.
 */
export default function QualityItemActions({ tq, item, variant = 'header', tab = null }) {
    const [isMenuOpen, setIsMenuOpen] = useState(false);
    const [isConfirmOpen, setIsConfirmOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [menuPosition, setMenuPosition] = useState(null);
    const menuRef = useRef(null);
    const triggerRef = useRef(null);
    const cancelRef = useRef(null);

    const isProcess = item.quality_type === 'process';
    const isRow = variant === 'row';

    // Fixed rather than absolute, measured off the trigger. In the list the menu lives inside a
    // table cell under `overflow-x-auto`, and a scroll container clips on both axes — an absolutely
    // positioned menu would be cut off at the row it belongs to. Fixed escapes the container, at
    // the cost of having to close on scroll and resize rather than follow the trigger.
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
        : (tq.delete_item ?? 'Slett dokument');

    return (
        <>
            {isRow ? (
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
            ) : (
                <button
                    ref={triggerRef}
                    type="button"
                    aria-haspopup="menu"
                    aria-expanded={isMenuOpen}
                    className={DISCLOSURE_INLINE}
                    onClick={() => setIsMenuOpen((open) => ! open)}
                >
                    {menuLabel}
                    <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fillRule="evenodd" d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z" clipRule="evenodd" />
                    </svg>
                </button>
            )}

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
                                    : (tq.delete_dialog_removed_item ?? 'Dokumentet, strukturen, relasjonene og koblingene til Wiki-sider og filer.')}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-sm font-semibold uppercase tracking-wide text-slate-500">
                                {tq.delete_dialog_kept_heading ?? 'Dette beholdes'}
                            </dt>
                            <dd className="mt-1 text-base leading-6 text-slate-700">
                                {tq.delete_dialog_kept_body ?? 'Wiki-sidene, artiklene aktivitetene har vært kilde til, og filene i kildedokumentene. Kunnskap som allerede er produsert forsvinner ikke med prosessen.'}
                            </dd>
                        </div>
                    </dl>

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
                                router.delete(
                                    tab
                                        ? `/app/quality/items/${item.id}?tab=${encodeURIComponent(tab)}`
                                        : `/app/quality/items/${item.id}`,
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
