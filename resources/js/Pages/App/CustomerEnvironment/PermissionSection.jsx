import { ModuleIcon } from '../../../Components/App/ModuleSidebar';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

/**
 * The rail's icon for each permission domain, so a module looks the same here as in the menu.
 * A domain without an entry simply has no icon.
 */
const DOMAIN_ICONS = {
    base: 'tenders',
    quality: 'quality',
    wiki: 'wiki',
    risk: 'risk',
    objective: 'objectives',
    improvement: 'improvements',
    compliance: 'compliance',
    supplier: 'suppliers',
    management_review: 'management_review',
};

/**
 * One module's permissions in Kundemiljø → Tilganger, as a collapsible card.
 *
 * The header is a single native button inside a heading, so Enter and Space, focus and
 * `aria-expanded` come from the platform rather than from key handlers. A closed section keeps
 * its matrix in the DOM behind `hidden` — the matrix holds no state of its own, but the region
 * the button controls must exist for `aria-controls` to point at.
 */
export default function PermissionSection({ sectionKey, title, subtitle, meta, open, onToggle, children }) {
    const buttonId = `permission-section-${sectionKey}-button`;
    const panelId = `permission-section-${sectionKey}`;
    const icon = DOMAIN_ICONS[sectionKey];

    return (
        <section
            data-testid={`permission-section-${sectionKey}`}
            className={classNames(
                'rounded-2xl border bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)] transition-colors',
                open ? 'border-violet-200' : 'border-slate-200',
            )}
        >
            <h3>
                <button
                    type="button"
                    id={buttonId}
                    aria-expanded={open}
                    aria-controls={panelId}
                    onClick={onToggle}
                    className="flex w-full min-w-0 items-start gap-3 rounded-2xl px-4 py-4 text-left sm:items-center sm:gap-4 sm:px-5 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-violet-400"
                >
                    {icon ? (
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-50 text-violet-700">
                            <ModuleIcon moduleKey={icon} />
                        </span>
                    ) : null}
                    <span className="min-w-0 flex-1">
                        <span className="block text-lg font-semibold text-slate-950">{title}</span>
                        {subtitle ? (
                            <span className="mt-0.5 block text-base leading-6 text-slate-600">{subtitle}</span>
                        ) : null}
                        {meta ? (
                            <span className="mt-1 block text-base leading-6 text-slate-500">{meta}</span>
                        ) : null}
                    </span>
                    <svg
                        className={classNames('h-5 w-5 shrink-0 text-slate-500 transition-transform', open ? 'rotate-180' : '')}
                        viewBox="0 0 20 20"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1.8"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        aria-hidden="true"
                    >
                        <path d="m5 7.5 5 5 5-5" />
                    </svg>
                </button>
            </h3>
            <div
                id={panelId}
                role="region"
                aria-labelledby={buttonId}
                hidden={!open}
                className="border-t border-slate-100 px-4 pb-5 pt-4 sm:px-5"
            >
                {children}
            </div>
        </section>
    );
}

/**
 * Shared table classes, so the bid-role matrix and every domain matrix read as one system:
 * a tinted header row, roomy rows with a soft hover, and a role column that stays put while
 * a wide matrix scrolls sideways inside its own box.
 */
export const MATRIX = {
    // `relative` keeps anything absolutely positioned inside from widening the page on phones.
    wrapper: 'relative overflow-x-auto rounded-xl border border-slate-200',
    table: 'w-full border-collapse text-base',
    headRow: 'border-b border-slate-200 bg-slate-50',
    stickyHead: 'sticky left-0 z-10 bg-slate-50 px-4 py-3 text-left align-bottom text-base font-semibold text-slate-700',
    head: 'min-w-[9rem] px-3 py-3 text-center align-bottom text-base font-semibold leading-6 text-slate-700 [text-wrap:balance]',
    headLeft: 'min-w-[10rem] px-3 py-3 text-left align-bottom text-base font-semibold leading-6 text-slate-700',
    body: 'divide-y divide-slate-100',
    row: 'group transition-colors hover:bg-slate-50',
    stickyCell: 'sticky left-0 z-10 bg-white px-4 py-3.5 text-left align-middle font-normal text-slate-900 transition-colors group-hover:bg-slate-50',
    cell: 'px-3 py-3.5 text-center align-middle',
    checkbox: 'h-5 w-5 cursor-pointer rounded border-slate-300 text-violet-600 focus:ring-violet-300 disabled:cursor-not-allowed disabled:opacity-50',
};
