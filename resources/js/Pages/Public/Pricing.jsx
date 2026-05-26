import { usePage } from '@inertiajs/react';
import PublicLayout from '../../Layouts/PublicLayout';

export default function Pricing() {
    const { translations = {} } = usePage().props;
    const text = translations.public?.pricing ?? {};
    const plans = [
        { title: text.card_1_title, body: text.card_1_body },
        { title: text.card_2_title, body: text.card_2_body },
        { title: text.card_3_title, body: text.card_3_body },
    ].filter((plan) => plan.title && plan.body);

    return (
        <PublicLayout title={text.title ?? 'Priser'}>
            <section className="max-w-3xl space-y-4">
                <h1 className="text-4xl font-semibold tracking-tight text-slate-950">{text.title}</h1>
                <p className="text-lg leading-8 text-slate-600">{text.lead}</p>
            </section>

            <section className="mt-10 rounded-3xl border border-amber-200 bg-amber-50 p-6 text-sm leading-7 text-amber-900">
                {text.note}
            </section>

            <section className="mt-12 grid gap-6 md:grid-cols-3">
                {plans.map((plan) => (
                    <article key={plan.title} className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm shadow-slate-200/50">
                        <h2 className="text-lg font-semibold text-slate-950">{plan.title}</h2>
                        <p className="mt-3 text-sm leading-7 text-slate-600">{plan.body}</p>
                    </article>
                ))}
            </section>
        </PublicLayout>
    );
}
