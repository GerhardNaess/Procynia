export default function FormButtonRow({ children, className }) {
    return (
        <div className={['flex flex-col gap-3 sm:flex-row sm:justify-end', className].filter(Boolean).join(' ')}>
            {children}
        </div>
    );
}
