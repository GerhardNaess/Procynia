import { useId, useState } from 'react';
import PageHelpPanel from './PageHelpPanel';

/**
 * Self-contained help button that opens a PageHelpPanel beside the page header.
 * Drop this anywhere in a page header row — it manages its own open/close state.
 *
 * Usage:
 *   <PageHelpButton
 *       buttonLabel={tai.help_button ?? 'Hjelp'}
 *       title={tai.help_panel_title ?? 'Om denne siden'}
 *       intro={tai.help_panel_intro}
 *       sections={[{ title: 'Seksjon', items: [{ title: 'Tittel', text: 'Forklaring.' }] }]}
 *   />
 *
 * @param {string}   [buttonLabel='Hjelp']  Visible button text and aria-label.
 * @param {string}   title                  Panel heading (passed to PageHelpPanel).
 * @param {string}   [intro]                Optional introductory paragraph.
 * @param {Array}    [sections=[]]          Help sections — see PageHelpPanel for shape.
 */
export default function PageHelpButton({ buttonLabel = 'Hjelp', title, intro, sections = [] }) {
    const [isOpen, setIsOpen] = useState(false);
    const rawId = useId();
    const panelId = `page-help-${rawId.replace(/:/g, '')}`;

    return (
        <>
            <button
                type="button"
                aria-label={buttonLabel}
                aria-expanded={isOpen}
                aria-controls={isOpen ? panelId : undefined}
                onClick={() => setIsOpen((current) => !current)}
                className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 py-2 text-base font-semibold leading-6 text-slate-600 shadow-sm transition hover:border-violet-200 hover:text-violet-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-300"
            >
                <span
                    aria-hidden="true"
                    className="flex h-4 w-4 shrink-0 items-center justify-center rounded-full border border-current text-[9px] font-bold leading-none"
                >
                    ?
                </span>
                {buttonLabel}
            </button>

            <PageHelpPanel
                id={panelId}
                title={title ?? buttonLabel}
                intro={intro}
                sections={sections}
                isOpen={isOpen}
                onClose={() => setIsOpen(false)}
            />
        </>
    );
}
