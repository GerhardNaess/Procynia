import { usePage } from '@inertiajs/react';
import PublicLayout from '../../Layouts/PublicLayout';

export default function Contact() {
    const { translations = {} } = usePage().props;
    const publicText = translations.public ?? {};
    const text = publicText.contact ?? {};
    const contact = publicText.contactDetails ?? {};

    const contactCards = [
        {
            label: text.general_label,
            value: contact.general_email,
            href: contact.general_email ? `mailto:${contact.general_email}` : null,
        },
        {
            label: text.sales_label,
            value: contact.sales_email,
            href: contact.sales_email ? `mailto:${contact.sales_email}` : null,
        },
        {
            label: text.support_label,
            value: contact.support_email,
            href: contact.support_email ? `mailto:${contact.support_email}` : null,
        },
        {
            label: text.privacy_label,
            value: contact.privacy_email,
            href: contact.privacy_email ? `mailto:${contact.privacy_email}` : null,
        },
        {
            label: text.phone_label,
            value: contact.phone,
            href: contact.phone ? `tel:${String(contact.phone).replace(/\s+/g, '')}` : null,
        },
    ].filter((item) => item.value);

    return (
        <PublicLayout title={text.title ?? 'Kontakt'}>
            <section className="max-w-3xl space-y-4">
                <h1 className="text-4xl font-semibold tracking-tight text-slate-950">{text.title}</h1>
                <p className="text-lg leading-8 text-slate-600">{text.lead}</p>
            </section>

            <section className="mt-12 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                {contactCards.map((card) => (
                    <article key={card.label} className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm shadow-slate-200/50">
                        <div className="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">{card.label}</div>
                        {card.href ? (
                            <a className="mt-4 block text-lg font-medium text-slate-950 transition hover:text-slate-700" href={card.href}>
                                {card.value}
                            </a>
                        ) : (
                            <div className="mt-4 text-lg font-medium text-slate-950">{card.value}</div>
                        )}
                    </article>
                ))}
            </section>

            <section className="mt-10 rounded-3xl border border-slate-200 bg-white p-6 text-sm leading-7 text-slate-600 shadow-sm shadow-slate-200/50">
                {text.note}
            </section>
        </PublicLayout>
    );
}
