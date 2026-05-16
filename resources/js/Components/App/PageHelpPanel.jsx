import { useEffect, useState } from 'react';

/**
 * Slide-in help panel. Not a focus-trapping modal — the page remains interactive.
 * Closes on Escape, backdrop click, or the × button.
 *
 * @param {string}            id        HTML id for aria-controls on the paired button.
 * @param {string}            title     Panel heading.
 * @param {string}            [intro]   Optional introductory paragraph.
 * @param {Array}             sections  Array of { title?, items: [{ title?, text }] }.
 * @param {boolean}           isOpen
 * @param {function}          onClose
 */
export default function PageHelpPanel({ id, title, intro, sections = [], isOpen, onClose }) {
    const [topOffset, setTopOffset] = useState(0);

    useEffect(() => {
        if (!isOpen) return undefined;

        function measureHeader() {
            const header = document.querySelector('header');
            setTopOffset(header ? Math.round(header.getBoundingClientRect().bottom) : 0);
        }

        function onKeyDown(event) {
            if (event.key === 'Escape') onClose();
        }

        measureHeader();
        window.addEventListener('resize', measureHeader);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            window.removeEventListener('resize', measureHeader);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [isOpen, onClose]);

    if (!isOpen) return null;

    return (
        <>
            <div
                className="fixed inset-0 z-40 bg-slate-950/10"
                aria-hidden="true"
                onClick={onClose}
            />

            <div
                id={id}
                role="dialog"
                aria-label={title}
                style={{ top: topOffset }}
                className="fixed right-0 bottom-0 z-50 flex w-full max-w-sm flex-col overflow-y-auto bg-white shadow-[0_0_40px_rgba(15,23,42,0.15)] sm:max-w-md"
            >
                <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
                    <div>
                        <div className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">
                            Hjelp
                        </div>
                        <h2 className="mt-0.5 text-lg font-semibold tracking-tight text-slate-950">
                            {title}
                        </h2>
                    </div>
                    <button
                        type="button"
                        aria-label="Lukk hjelpepanel"
                        onClick={onClose}
                        className="mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:border-slate-300 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-300"
                    >
                        <svg viewBox="0 0 16 16" fill="none" aria-hidden="true" className="h-3.5 w-3.5">
                            <path d="M3 3l10 10M13 3L3 13" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
                        </svg>
                    </button>
                </div>

                <div className="flex-1 space-y-6 px-6 py-6">
                    {intro ? (
                        <p className="text-sm leading-6 text-slate-500">{intro}</p>
                    ) : null}

                    {sections.map((section, sectionIndex) => (
                        <div key={sectionIndex} className="space-y-2.5">
                            {section.title ? (
                                <h3 className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-400">
                                    {section.title}
                                </h3>
                            ) : null}

                            {(section.items ?? []).map((item, itemIndex) => (
                                <div
                                    key={itemIndex}
                                    className="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3"
                                >
                                    {item.title ? (
                                        <div className="mb-1 text-sm font-semibold text-slate-700">
                                            {item.title}
                                        </div>
                                    ) : null}
                                    <p className="text-sm leading-5 text-slate-500">{item.text}</p>
                                </div>
                            ))}
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}
