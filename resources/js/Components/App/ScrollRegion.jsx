import { useCallback, useEffect, useRef, useState } from 'react';

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
 * Fase 4 adds the vertical case: a box with a capped height that scrolls its content — a list of
 * requirements, a generated answer. `axis="y"` measures height instead, adds no overflow or margin
 * classes of its own (the caller's className already sets max-h and overflow-y-auto) and, like the
 * horizontal box, joins the Tab order only while its content is taller than the box.
 *
 * @param {string}  label        What the table is, e.g. «Brukere». Names the region when it scrolls.
 * @param {string}  [className]  Spacing from the surroundings (mt-4 …), or the box's own look.
 * @param {boolean} [edgeRoom]   false for a box that draws its own border and padding, where the
 *                               negative margin would move the border.
 * Fase 5 adds `axis="both"` for a box with a capped height around a table that can also be wider
 * than the box (Kildedokumenter, Wiki-sider): it joins the Tab order when it overflows either way,
 * and like `y` leaves the overflow classes to the caller.
 *
 * @param {'x'|'y'|'both'} [axis] Which way the box scrolls. 'x' (default) for tables.
 * @param {object}  [innerRef]   Receives the scroll box element, for callers that read its content.
 */
export default function ScrollRegion({ label, className = '', edgeRoom = true, axis = 'x', innerRef = null, children, ...rest }) {
    const ownRef = useRef(null);
    const [scrollable, setScrollable] = useState(false);
    const vertical = axis === 'y';
    const both = axis === 'both';
    const overflows = (element) => {
        const tall = element.scrollHeight > element.clientHeight + 1;
        const wide = element.scrollWidth > element.clientWidth + 1;

        return both ? tall || wide : (vertical ? tall : wide);
    };

    const setRef = useCallback((element) => {
        ownRef.current = element;

        if (innerRef) {
            innerRef.current = element;
        }
    }, [innerRef]);

    useEffect(() => {
        const element = ownRef.current;

        if (! element) {
            return undefined;
        }

        const measure = () => setScrollable(overflows(element));
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
        // `overflows` only reads `axis`, so `axis` is the whole dependency.
    }, [axis]);

    // Content that changes without resizing the box or its first child — another requirement added to
    // a capped list — is caught here: the parent re-rendered, so measure again.
    useEffect(() => {
        const element = ownRef.current;

        if (! element) {
            return;
        }

        const next = overflows(element);

        setScrollable((current) => (current === next ? current : next));
    });

    const boxClasses = vertical || both
        ? className
        : `relative overflow-x-auto ${edgeRoom ? '-mx-1 px-1' : ''} ${className}`;

    return (
        <div
            {...rest}
            ref={setRef}
            data-scroll-region=""
            className={boxClasses.replace(/\s+/g, ' ').trim()}
            {...(scrollable ? { role: 'region', 'aria-label': label, tabIndex: 0 } : {})}
        >
            {children}
        </div>
    );
}
