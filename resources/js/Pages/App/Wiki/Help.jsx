import { Link, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

function SectionCard({ section }) {
    return (
        <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">
                {section.title}
            </h2>

            <div className="mt-4 grid gap-3">
                {(section.items ?? []).map((item) => (
                    <article key={item.title} className="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3">
                        <h3 className="text-sm font-semibold text-slate-800">
                            {item.title}
                        </h3>
                        <p className="mt-1 text-sm leading-6 text-slate-600">
                            {item.text}
                        </p>
                    </article>
                ))}
            </div>
        </section>
    );
}

export default function WikiHelp({ help = null, back_url: backUrl = '/app/wiki' }) {
    const { translations = {} } = usePage().props;
    const tw = translations?.wiki ?? {};
    const content = help ?? tw.help ?? {};
    const sections = content.sections ?? [];

    return (
        <CustomerAppLayout title={content.title ?? tw.help_title ?? 'Slik fungerer Wiki-sider'} showPageTitle={false}>
            <div className="space-y-6">
                <section className="rounded-3xl border border-violet-100 bg-gradient-to-br from-violet-50 via-white to-sky-50 p-6 shadow-sm md:p-8">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="max-w-3xl space-y-3">
                            <div className="text-[11px] font-semibold uppercase tracking-[0.14em] text-violet-500">
                                Hjelpeside
                            </div>
                            <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                                {content.title ?? tw.help_title ?? 'Slik fungerer Wiki-sider'}
                            </h1>
                            <p className="text-base leading-7 text-slate-600">
                                {content.intro ?? tw.help_intro ?? 'Denne siden forklarer hvordan kildedokumenter blir til Wiki-sider, hvordan Dokumenteier godkjenner materialet, og hvorfor noen statuser kan vise at systemet fortsatt synkroniserer.'}
                            </p>
                        </div>

                        <Link
                            href={backUrl}
                            className="inline-flex items-center justify-center rounded-full border border-violet-200 bg-white px-4 py-2 text-sm font-semibold text-violet-700 shadow-sm transition hover:border-violet-300 hover:bg-violet-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-300"
                        >
                            {content.back_to_wiki ?? tw.help_back_to_wiki ?? 'Til Wiki-sider'}
                        </Link>
                    </div>
                </section>

                <div className="grid gap-4 lg:grid-cols-2">
                    {sections.map((section) => (
                        <SectionCard key={section.title} section={section} />
                    ))}
                </div>
            </div>
        </CustomerAppLayout>
    );
}
