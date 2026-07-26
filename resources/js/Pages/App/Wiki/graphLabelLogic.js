// Canvas fillText never wraps text — a long node title would otherwise draw as one long
// line straight across neighboring nodes. `measureWidth` is injected (rather than a canvas
// context) so this stays a pure, unit-testable function; Graph.jsx calls it with
// `(text) => context.measureText(text).width`.
export function truncateLabelToWidth(measureWidth, label, maxWidthPx) {
    if (measureWidth(label) <= maxWidthPx) {
        return label;
    }

    const ellipsis = '…';
    let end = label.length;

    while (end > 0 && measureWidth(label.slice(0, end).trimEnd() + ellipsis) > maxWidthPx) {
        end -= 1;
    }

    return end > 0 ? label.slice(0, end).trimEnd() + ellipsis : ellipsis;
}
