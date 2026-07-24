import { Head, Link, usePage } from '@inertiajs/react';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

export default function PublicLayout({ children, title = '' }) {
    const page = usePage();
    const { appName, translations = {}, locale = 'no', flash = {} } = page.props;
    const publicText = translations.public ?? {};
    const nav = publicText.nav ?? {};
    const footer = publicText.footer ?? {};
    const contact = publicText.contactDetails ?? {};
    const currentPath = String(page.url ?? '').split('?')[0];

    const navigation = [
        { label: nav.product ?? 'Produkt', href: '/' },
        { label: nav.features ?? 'Funksjoner', href: '/funksjoner' },
        { label: nav.resources ?? 'Ressurser', href: '/kontakt' },
        { label: nav.about ?? 'Om oss', href: '/sikkerhet' },
        { label: nav.pricing ?? 'Priser', href: '/priser' },
    ];

    const activeLinkClass = 'text-slate-950';
    const inactiveLinkClass = 'text-slate-600 transition hover:text-slate-950';
    const localeLabel = String(locale ?? 'no').toUpperCase();

    return (
        <>
            <Head title={title !== '' ? `${title} · ${appName}` : appName} />
            <div className="min-h-screen bg-slate-50 text-slate-950">
                <header className="border-b border-slate-200/80 bg-white/90 backdrop-blur">
                    <div className="grid w-full grid-cols-[auto_1fr_auto] items-center gap-6 px-6 py-4 lg:px-10 xl:px-14">
                        <Link href="/" className="flex items-center">
                            <img
                                src="/images/procynia_logo.png?v=2"
                                alt={appName}
                                style={{ height: '40px', width: 'auto', maxWidth: '160px' }}
                                className="object-contain"
                            />
                        </Link>
                        <nav className="hidden items-center justify-center gap-8 text-base font-medium lg:flex">
                            {navigation.map((item) => {
                                const isActive = currentPath === item.href;

                                return (
                                    <Link
                                        key={item.href}
                                        href={item.href}
                                        className={classNames(
                                            isActive ? activeLinkClass : inactiveLinkClass,
                                            'rounded-full px-1 py-1',
                                        )}
                                        aria-current={isActive ? 'page' : undefined}
                                    >
                                        {item.label}
                                    </Link>
                                );
                            })}
                        </nav>
                        <div className="flex items-center gap-3">
                            <div className="hidden items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-base font-medium text-slate-700 md:flex">
                                <svg viewBox="0 0 24 24" className="h-5 w-5 text-slate-700" fill="none" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="1.6" />
                                    <path d="M3 12h18" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
                                    <path d="M12 3c2.7 2.7 4.2 5.7 4.2 9s-1.5 6.3-4.2 9c-2.7-2.7-4.2-5.7-4.2-9S9.3 5.7 12 3Z" stroke="currentColor" strokeWidth="1.6" strokeLinejoin="round" />
                                </svg>
                                <span>{localeLabel}</span>
                                <span aria-hidden="true" className="text-slate-500">⌄</span>
                            </div>
                            <Link
                                href="/login"
                                className="inline-flex items-center rounded-full border border-slate-300 bg-white px-5 py-2.5 text-base font-semibold text-slate-950 transition hover:border-slate-400 hover:bg-slate-50"
                            >
                                {nav.login ?? 'Logg inn'}
                            </Link>
                        </div>
                    </div>
                </header>

                <main className="mx-auto max-w-6xl px-6 py-14 lg:px-8">
                    {(flash.success || flash.error) ? (
                        <div
                            className={classNames(
                                'mb-8 rounded-2xl border px-4 py-3 text-base font-medium shadow-sm',
                                flash.error
                                    ? 'border-rose-200 bg-rose-50 text-rose-800'
                                    : 'border-emerald-200 bg-emerald-50 text-emerald-800',
                            )}
                        >
                            {flash.error ?? flash.success}
                        </div>
                    ) : null}
                    {children}
                </main>

                <footer className="border-t border-slate-200 bg-white">
                    <div className="mx-auto grid max-w-6xl gap-8 px-6 py-10 lg:grid-cols-[1.2fr_1fr_1fr] lg:px-8">
                        <div className="space-y-4">
                            <div className="text-base font-semibold uppercase tracking-[0.28em] text-slate-600">{appName}</div>
                            <p className="max-w-xl text-base leading-7 text-slate-600">
                                {footer.company_note ?? ''}
                            </p>
                        </div>

                        <div className="space-y-4">
                            <div className="text-base font-semibold uppercase tracking-[0.24em] text-slate-600">
                                {footer.contact_label ?? 'Kontakt'}
                            </div>
                            <div className="space-y-2 text-base text-slate-600">
                                {contact.general_email ? (
                                    <a className="block transition hover:text-slate-950" href={`mailto:${contact.general_email}`}>
                                        {contact.general_email}
                                    </a>
                                ) : null}
                                {contact.sales_email ? (
                                    <a className="block transition hover:text-slate-950" href={`mailto:${contact.sales_email}`}>
                                        {contact.sales_email}
                                    </a>
                                ) : null}
                                {contact.support_email ? (
                                    <a className="block transition hover:text-slate-950" href={`mailto:${contact.support_email}`}>
                                        {contact.support_email}
                                    </a>
                                ) : null}
                                {contact.privacy_email ? (
                                    <a className="block transition hover:text-slate-950" href={`mailto:${contact.privacy_email}`}>
                                        {contact.privacy_email}
                                    </a>
                                ) : null}
                                {contact.phone ? (
                                    <a className="block transition hover:text-slate-950" href={`tel:${String(contact.phone).replace(/\s+/g, '')}`}>
                                        {contact.phone}
                                    </a>
                                ) : null}
                            </div>
                        </div>

                        <div className="space-y-4">
                            <div className="text-base font-semibold uppercase tracking-[0.24em] text-slate-600">{locale === 'en' ? 'Legal' : 'Juridisk'}</div>
                            <div className="flex flex-wrap gap-3 text-base font-medium">
                                <Link href="/personvern" className="text-slate-600 transition hover:text-slate-950">
                                    {footer.privacy ?? 'Personvern'}
                                </Link>
                                <Link href="/betingelser" className="text-slate-600 transition hover:text-slate-950">
                                    {footer.terms ?? 'Betingelser'}
                                </Link>
                                <Link href="/faq" className="text-slate-600 transition hover:text-slate-950">
                                    FAQ
                                </Link>
                            </div>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
