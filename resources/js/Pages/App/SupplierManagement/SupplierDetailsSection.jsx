/**
 * One section under «Detaljer» on Oversikt: a heading that opens and closes it, with a one-line
 * summary so a closed section still says what it holds.
 *
 * The header is a single native button inside a heading, so Enter, Space, focus and `aria-expanded`
 * come from the platform (the pattern of Kundemiljø → Tilganger). A closed section keeps its content
 * mounted behind `hidden`: a form being filled in keeps what was typed when the section is closed,
 * and nothing is lost by opening and closing it.
 *
 * `headingId` is the section's anchor — «Trenger oppfølging» and other in-page links point at it, and
 * Show.jsx opens the section before it scrolls there.
 */
export default function SupplierDetailsSection({ headingId, testId, title, summary, open, onToggle, children }) {
    const panelId = `${headingId}-panel`;

    return (
        <section className="min-w-0 border-t border-slate-200 first:border-t-0" data-testid={testId} data-open={open ? '1' : '0'}>
            <h3 id={headingId} className="scroll-mt-4">
                <button
                    type="button"
                    aria-expanded={open}
                    aria-controls={panelId}
                    id={`${headingId}-toggle`}
                    onClick={onToggle}
                    className="flex min-h-12 w-full min-w-0 items-start gap-3 px-1 py-3 text-left hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-violet-500"
                    data-testid={`${testId}-toggle`}
                >
                    <svg
                        aria-hidden="true"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                        className={`mt-1 h-5 w-5 shrink-0 text-slate-500 transition-transform ${open ? 'rotate-90' : ''}`}
                    >
                        <path fillRule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clipRule="evenodd" />
                    </svg>
                    <span className="min-w-0 flex-1">
                        <span className="block text-lg font-semibold text-slate-950">{title}</span>
                        {summary && <span className="mt-0.5 block break-words text-base text-slate-600" data-testid={`${testId}-summary`}>{summary}</span>}
                    </span>
                </button>
            </h3>
            <div id={panelId} hidden={! open} className="pb-5 pl-1 pr-1 sm:pl-9">
                {children}
            </div>
        </section>
    );
}
