import { useEffect, useRef, useState } from 'react';

/**
 * The box a wide table scrolls sideways in (A11Y-110, and the clipped focus ring from Fase 1 §15.8.2).
 *
 * Two problems with a bare `overflow-x-auto` wrapper:
 *
 *   1. A scroll container clips on both axes. The focus outline is drawn 4 px outside a control
 *      (2 px offset + 2 px width), so a link in a first column or a button in a last column that
 *      touches the box's edge had its ring cut off on that side. This box keeps 4 px of room inside
 *      each edge and takes it back with a negative margin, so the table sits exactly where it did.
 *   2. When the table really is wider than the box, a keyboard user has to be able to scroll it.
 *      Chrome lets Tab land in a scroll container on its own; Safari and Firefox do not unless the
 *      box itself is focusable. While — and only while — the content overflows, the box becomes a
 *      named region in the Tab order (arrow keys then scroll it). A table that fits adds no tab stop
 *      and no landmark.
 *
 * @param {string}  label        What the table is, e.g. «Brukere». Names the region when it scrolls.
 * @param {string}  [className]  Spacing from the surroundings (mt-4 …), or the box's own look.
 * @param {boolean} [edgeRoom]   false for a box that draws its own border and padding, where the
 *                               negative margin would move the border.
 */
export default function ScrollRegion({ label, className = '', edgeRoom = true, children }) {
    const ref = useRef(null);
    const [scrollable, setScrollable] = useState(false);

    useEffect(() => {
        const element = ref.current;

        if (! element) {
            return undefined;
        }

        const measure = () => setScrollable(element.scrollWidth > element.clientWidth + 1);
        measure();

        if (typeof ResizeObserver === 'undefined') {
            window.addEventListener('resize', measure);

            return () => window.removeEventListener('resize', measure);
        }

        const observer = new ResizeObserver(measure);
        observer.observe(element);

        if (element.firstElementChild) {
            observer.observe(element.firstElementChild);
        }

        return () => observer.disconnect();
    }, []);

    return (
        <div
            ref={ref}
            data-scroll-region=""
            className={`relative overflow-x-auto ${edgeRoom ? '-mx-1 px-1' : ''} ${className}`.replace(/\s+/g, ' ').trim()}
            {...(scrollable ? { role: 'region', 'aria-label': label, tabIndex: 0 } : {})}
        >
            {children}
        </div>
    );
}
