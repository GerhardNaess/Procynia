import { focusElement } from '../../Support/focusManagement';
import { MAIN_CONTENT_ID } from '../../Support/routeFocus';

/**
 * «Hopp til hovedinnhold» — the first Tab stop of every page with a layout (WCAG 2.4.1 Bypass Blocks;
 * docs/wcag-accessibility-plan.md A11Y-100).
 *
 * Out of sight until it has keyboard focus, so a mouse user never sees it. Activating it moves focus to
 * <main id="main-content" tabindex="-1">, and the next Tab continues inside the page's own content —
 * past the header, the module navigation and the rail. It does that in script rather than through the
 * `#main-content` fragment alone: the fragment would change the URL Inertia keeps in history, and not
 * every browser moves the Tab position along with the scroll. The href stays as the fallback.
 */
export default function SkipLink({ label, targetId = MAIN_CONTENT_ID }) {
    return (
        <a
            href={`#${targetId}`}
            onClick={(event) => {
                const target = document.getElementById(targetId);

                if (target) {
                    event.preventDefault();
                    focusElement(target);
                }
            }}
            data-testid="skip-link"
            className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[90] focus:rounded-xl focus:bg-white focus:px-4 focus:py-3 focus:text-base focus:font-semibold focus:text-violet-700 focus:shadow-lg"
        >
            {label}
        </a>
    );
}
