const TONE_CLASSES = {
    amber: 'border-amber-200 bg-amber-50 text-amber-900',
    red:   'border-red-200 bg-red-50 text-red-900',
    blue:  'border-blue-200 bg-blue-50 text-blue-900',
    slate: 'border-slate-200 bg-slate-50 text-slate-700',
};

/**
 * A tinted notice box.
 *
 * It has no role by default, on purpose (docs/wcag-accessibility-plan.md §5, Fase 6): every current use
 * is a notice that arrives with the page (a capped search, a user limit, a subscription without a
 * plan). A role="alert" on such content makes screen readers read it out on page load, as if
 * something had just gone wrong. A caller that inserts the box *in response to* an action — an error
 * after a click — passes `role="alert"` (or "status" for a neutral outcome) and gets it; `id` lets a
 * control point at the box with aria-describedby.
 */
export default function AlertBox({ tone = 'amber', title, className, role, id, children }) {
    const toneClasses = TONE_CLASSES[tone] ?? TONE_CLASSES.amber;
    return (
        <div id={id} role={role} className={['rounded-2xl border px-4 py-3 text-base leading-6', toneClasses, className].filter(Boolean).join(' ')}>
            {title ? <div className="font-semibold">{title}</div> : null}
            {children}
        </div>
    );
}
