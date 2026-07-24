const TONE_CLASSES = {
    amber: 'border-amber-200 bg-amber-50 text-amber-900',
    red:   'border-red-200 bg-red-50 text-red-900',
    blue:  'border-blue-200 bg-blue-50 text-blue-900',
    slate: 'border-slate-200 bg-slate-50 text-slate-700',
};

export default function AlertBox({ tone = 'amber', title, className, children }) {
    const toneClasses = TONE_CLASSES[tone] ?? TONE_CLASSES.amber;
    return (
        <div className={['rounded-2xl border px-4 py-3 text-base leading-6', toneClasses, className].filter(Boolean).join(' ')}>
            {title ? <div className="font-semibold">{title}</div> : null}
            {children}
        </div>
    );
}
