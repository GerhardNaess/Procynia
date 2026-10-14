import { useLayoutEffect } from 'react';

/** Gutter kept between a panel and the window edge, as the layout's own side padding on a phone. */
export const VIEWPORT_GUTTER = 16;

/**
 * How far a panel must move sideways to lie inside the window with `gutter` to spare: positive to the
 * right, negative to the left, 0 when it already fits. A panel wider than the room left is aligned to
 * the left gutter. Pure, so it is unit-tested.
 */
export function horizontalShift({ left, right }, viewportWidth, gutter = VIEWPORT_GUTTER) {
    if (left < gutter) {
        return gutter - left;
    }

    if (right > viewportWidth - gutter) {
        return Math.max(gutter - left, viewportWidth - gutter - right);
    }

    return 0;
}

/**
 * Keeps an open drop-down panel inside the window (WCAG 1.4.10 Reflow, 2.4.11 Focus Not Obscured).
 *
 * The header's panels hang from the right edge of their button. On a phone, or at 400 % zoom, the
 * header wraps and the button is no longer at the right edge, so a panel anchored to it can reach past
 * the left side of the window — the user menu at 320 px began 32 px off-screen. This nudges the panel
 * back in with a transform, measured after layout and again on resize; it does not change where the
 * panel is anchored on wider screens, where it already fits.
 *
 * @param {{ current: HTMLElement|null }} ref The panel.
 * @param {boolean} isOpen
 */
export function useKeepInViewport(ref, isOpen) {
    useLayoutEffect(() => {
        if (! isOpen) {
            return undefined;
        }

        const place = () => {
            const element = ref.current;

            if (! element) {
                return;
            }

            element.style.transform = '';
            const shift = horizontalShift(element.getBoundingClientRect(), window.innerWidth);

            if (shift !== 0) {
                element.style.transform = `translateX(${Math.round(shift)}px)`;
            }
        };

        place();
        window.addEventListener('resize', place);

        return () => window.removeEventListener('resize', place);
    }, [ref, isOpen]);
}
