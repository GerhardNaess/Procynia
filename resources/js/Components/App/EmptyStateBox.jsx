export default function EmptyStateBox({ title, description, className, children }) {
    return (
        <div className={['rounded-[24px] border border-dashed border-slate-300 bg-slate-50 p-6 text-center', className].filter(Boolean).join(' ')}>
            {title ? <div className="text-base font-semibold text-slate-900">{title}</div> : null}
            {description ? <p className="mt-2 text-base leading-6 text-slate-600">{description}</p> : null}
            {children}
        </div>
    );
}
