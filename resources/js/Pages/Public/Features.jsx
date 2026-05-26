import { usePage } from '@inertiajs/react';
import PublicLayout from '../../Layouts/PublicLayout';

export default function Features() {
    const { translations = {} } = usePage().props;
    const text = translations.public?.features ?? {};
    const cards = [
        { title: text.card_1_title, body: text.card_1_body },
        { title: text.card_2_title, body: text.card_2_body },
        { title: text.card_3_title, body: text.card_3_body },
        { title: text.card_4_title, body: text.card_4_body },
        { title: text.card_5_title, body: text.card_5_body },
        { title: text.card_6_title, body: text.card_6_body },
    ].filter((card) => card.title && card.body);

    return (
        <PublicLayout title={text.title ?? 'Funksjoner'}>
            <section className="max-w-3xl space-y-4">
                <h1 className="text-4xl font-semibold tracking-tight text-slate-950">{text.title}</h1>
                <p className="text-lg leading-8 text-slate-600">{text.lead}</p>
            </section>

            <section className="mt-12 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                {cards.map((card) => (
                    <article key={card.title} className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm shadow-slate-200/50">
                        <h2 className="text-lg font-semibold text-slate-950">{card.title}</h2>
                        <p className="mt-3 text-sm leading-7 text-slate-600">{card.body}</p>
                    </article>
                ))}
            </section>
        </PublicLayout>
    );
}
