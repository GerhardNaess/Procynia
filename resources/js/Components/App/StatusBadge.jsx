const TONE_CLASSES = {
    slate:   'bg-slate-100 text-slate-700 ring-slate-200',
    green:   'bg-green-100 text-green-800 ring-green-200',
    emerald: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    amber:   'bg-amber-100 text-amber-800 ring-amber-200',
    blue:    'bg-blue-100 text-blue-700 ring-blue-200',
    violet:  'bg-violet-100 text-violet-700 ring-violet-200',
    sky:     'bg-sky-100 text-sky-700 ring-sky-200',
    rose:    'bg-rose-100 text-rose-700 ring-rose-200',
    purple:  'bg-violet-100 text-violet-700 ring-violet-200',
    red:     'bg-rose-100 text-rose-700 ring-rose-200',
    neutral: 'bg-slate-100 text-slate-700 ring-slate-200',
};

export default function StatusBadge({ tone = 'slate', className, children }) {
    const toneClasses = TONE_CLASSES[tone] ?? TONE_CLASSES.slate;
    return (
        <span className={['inline-flex items-center whitespace-nowrap rounded-full px-3 py-1.5 text-base font-semibold leading-6 ring-1 ring-inset', toneClasses, className].filter(Boolean).join(' ')}>
            {children}
        </span>
    );
}
