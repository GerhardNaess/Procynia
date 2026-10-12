import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function Chevron() {
    return (
        <svg className="h-4 w-4 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="none" aria-hidden="true">
            <path d="M8 5.5 12.5 10 8 14.5" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

/**
 * The page's place in Procynia, above its title — one component for every page.
 *
 * The trail itself is decided centrally (Support/appNavigation.js) and only drawn here: links for
 * the levels the person can open, plain text for the rest, and the page itself last, unlinked and
 * marked `aria-current="page"`.
 *
 * Long names are cut visually and keep their full text in `title`, so the accessible name is never
 * shortened. On a phone only the nearest parent and the page are shown; the levels above them sit
 * behind «…», which opens the whole trail in place. The row wraps instead of scrolling sideways.
 */
export default function Breadcrumbs({ items = [], ariaLabel = 'Brødsmulesti', showAllLabel = 'Vis hele stien' }) {
    const [expanded, setExpanded] = useState(false);
    const trailKey = items.map((item) => `${item.label}|${item.href ?? ''}`).join('>');

    // A new page starts folded again on a phone.
    useEffect(() => {
        setExpanded(false);
    }, [trailKey]);

    if (items.length === 0) {
        return null;
    }

    // Everything before the nearest parent folds away on a phone.
    const foldBefore = items.length - 2;
    const canFold = foldBefore > 0;

    return (
        <nav aria-label={ariaLabel} data-testid="breadcrumbs" className="mb-4 min-w-0">
            <ol className="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-1 text-base leading-6 text-slate-600">
                {canFold && ! expanded ? (
                    <li className="flex items-center gap-1.5 sm:hidden">
                        <button
                            type="button"
                            onClick={() => setExpanded(true)}
                            aria-label={showAllLabel}
                            title={showAllLabel}
                            data-testid="breadcrumbs-expand"
                            className="rounded-md px-1 font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-500"
                        >
                            …
                        </button>
                        <Chevron />
                    </li>
                ) : null}

                {items.map((item, index) => {
                    const isCurrent = index === items.length - 1;
                    const folded = canFold && ! expanded && index < foldBefore;

                    return (
                        <li
                            key={`${index}-${item.label}`}
                            data-testid="breadcrumb-item"
                            className={classNames('flex min-w-0 items-center gap-1.5', folded ? 'hidden sm:flex' : '')}
                        >
                            {isCurrent ? (
                                <span
                                    aria-current="page"
                                    title={item.label}
                                    className="block max-w-[16rem] truncate font-medium text-slate-900 sm:max-w-[28rem]"
                                >
                                    {item.label}
                                </span>
                            ) : item.href ? (
                                <Link
                                    href={item.href}
                                    title={item.label}
                                    className="block max-w-[12rem] truncate rounded-md text-slate-600 underline-offset-4 transition hover:text-slate-900 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-500 sm:max-w-[16rem]"
                                >
                                    {item.label}
                                </Link>
                            ) : (
                                <span title={item.label} className="block max-w-[12rem] truncate sm:max-w-[16rem]">
                                    {item.label}
                                </span>
                            )}
                            {! isCurrent ? <Chevron /> : null}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
