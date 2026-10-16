import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import FlashMessages from '../Components/App/FlashMessages';
import SkipLink from '../Components/App/SkipLink';
import { MAIN_CONTENT_ID } from '../Support/routeFocus';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

export default function PublicLayout({ children, title = '' }) {
    const page = usePage();
    const { appName, translations = {}, locale = 'no', flash = {} } = page.props;
    const publicText = translations.public ?? {};
    const a11y = translations.a11y ?? {};
    const [isMenuOpen, setIsMenuOpen] = useState(false);
    const menuButtonRef = useRef(null);
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
    const menuLabel = a11y.public_navigation ?? 'Hovedmeny';

    // The small-screen menu closes on Escape and hands focus back to its button.
    useEffect(() => {
        if (! isMenuOpen) {
            return undefined;
        }

        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                setIsMenuOpen(false);
                menuButtonRef.current?.focus();
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [isMenuOpen]);

    const renderLink = (item, className) => {
        const isActive = currentPath === item.href;

        return (
            <Link
                key={item.href}
                href={item.href}
                className={classNames(isActive ? activeLinkClass : inactiveLinkClass, className)}
                aria-current={isActive ? 'page' : undefined}
            >
                {item.label}
            </Link>
        );
    };

    return (
        <>
            {/* The home page is called the app's own name; «Procynia · Procynia» says nothing twice. */}
            <Head title={title !== '' && title !== appName ? `${title} · ${appName}` : appName} />
            <SkipLink label={a11y.skip_to_content ?? 'Hopp til hovedinnhold'} />
            <div className="min-h-screen bg-slate-50 text-slate-950">
                <header className="border-b border-slate-200/80 bg-white/90 backdrop-blur">
                    {/* From lg up: logo, menu, Logg inn in one row. Below lg the menu folds behind
                        «Meny» (A11Y-022), and the row may wrap rather than push Logg inn off a phone. */}
                    <div className="flex w-full flex-wrap items-center justify-between gap-x-6 gap-y-3 px-4 py-4 sm:px-6 lg:grid lg:grid-cols-[auto_1fr_auto] lg:px-10 xl:px-14">
                        <Link href="/" className="flex items-center">
                            <img
                                src="/images/procynia_logo.png?v=2"
                                alt={appName}
                                style={{ height: '40px', width: 'auto', maxWidth: '160px' }}
                                className="object-contain"
                            />
                        </Link>
                        <nav aria-label={menuLabel} className="hidden items-center justify-center gap-8 text-base font-medium lg:flex">
                            {navigation.map((item) => renderLink(item, 'rounded-full px-1 py-1'))}
                        </nav>
                        {/* There used to be a language pill here, drawn like a dropdown («NO ⌄») that
                            did nothing (A11Y-122): a control that is not one. The public pages have no
                            language choice, so it is gone until they do. */}
                        <div className="flex items-center gap-3">
                            <Link
                                href="/login"
                                className="inline-flex items-center rounded-full border border-slate-300 bg-white px-5 py-2.5 text-base font-semibold text-slate-950 transition hover:border-slate-400 hover:bg-slate-50"
                            >
                                {nav.login ?? 'Logg inn'}
                            </Link>
                            {/* Last in the row, so Tab goes from «Meny» straight into the menu below. */}
                            <button
                                ref={menuButtonRef}
                                type="button"
                                onClick={() => setIsMenuOpen((open) => ! open)}
                                aria-expanded={isMenuOpen}
                                aria-controls="public-mobile-menu"
                                data-testid="public-menu-button"
                                className="inline-flex items-center gap-2 rounded-full border border-slate-300 bg-white px-4 py-2.5 text-base font-semibold text-slate-950 transition hover:border-slate-400 hover:bg-slate-50 lg:hidden"
                            >
                                <svg viewBox="0 0 20 20" className="h-5 w-5" fill="none" aria-hidden="true">
                                    {isMenuOpen ? (
                                        <path d="M5.5 5.5l9 9M14.5 5.5l-9 9" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
                                    ) : (
                                        <path d="M3.5 6h13M3.5 10h13M3.5 14h13" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
                                    )}
                                </svg>
                                {a11y.public_menu ?? 'Meny'}
                            </button>
                        </div>
                    </div>

                    {/* The same links, as a list under the header row: ordinary navigation that
                        opens and closes, not a dialog. Not rendered at all while closed, so Tab never
                        lands in a menu nobody can see. */}
                    {isMenuOpen ? (
                        <nav id="public-mobile-menu" aria-label={menuLabel} className="border-t border-slate-200/80 px-4 py-3 sm:px-6 lg:hidden">
                            <ul className="space-y-1 text-base font-medium">
                                {navigation.map((item) => (
                                    <li key={item.href}>
                                        {renderLink(item, 'block rounded-xl px-3 py-2.5 hover:bg-slate-100')}
                                    </li>
                                ))}
                            </ul>
                        </nav>
                    ) : null}
                </header>

                <main id={MAIN_CONTENT_ID} tabIndex={-1} className="mx-auto max-w-6xl px-4 py-14 sm:px-6 lg:px-8">
                    <FlashMessages flash={flash} closeLabel={a11y.close_message ?? 'Lukk melding'} bannerClassName="mb-8" />
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
