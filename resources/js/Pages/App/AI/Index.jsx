import { Link, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

const AI_STATUS_META = {
    not_started: {
        label: 'Ikke startet',
        className: 'bg-slate-100 text-slate-700 ring-slate-200',
    },
    ready: {
        label: 'Klar',
        className: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
    },
    in_review: {
        label: 'Under vurdering',
        className: 'bg-violet-100 text-violet-700 ring-violet-200',
    },
};

function formatDate(value, locale) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}

/**
 * Purpose: Render the AI workspace landing page for customer case work.
 * Inputs: pageTitle and analysisCases props from the AI controller.
 * Returns: A customer-app page component for the AI workspace overview.
 * Side effects: Navigation to cases happens through Inertia links.
 */
export default function AiIndex({ pageTitle = 'Oversikt', analysisCases = [] }) {
    const { locale = 'nb-NO' } = usePage().props;
    const rows = Array.isArray(analysisCases) ? analysisCases : [];

    return (
        <CustomerAppLayout title={pageTitle} showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                        Oversikt
                    </div>
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                        Oversikt
                    </h1>
                    <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                        Her jobber du med konkrete anbudssaker, krav og kunnskapsgrunnlag.
                    </p>
                </section>

                <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="space-y-5">
                        <div className="space-y-2">
                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                Saker
                            </div>
                            <h2 className="text-2xl font-semibold tracking-tight text-slate-950">
                                Saker klare for oversikt
                            </h2>
                            <p className="max-w-3xl text-sm leading-6 text-slate-500">
                                Disse sakene har nok struktur til at arbeid kan skje videre i saken.
                            </p>
                        </div>

                        {rows.length > 0 ? (
                            <div className="overflow-hidden rounded-[22px] border border-slate-200">
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-slate-200">
                                        <thead className="bg-slate-50">
                                            <tr>
                                                <th className="px-5 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                    Sak
                                                </th>
                                                <th className="px-5 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                    Ansvarlig
                                                </th>
                                                <th className="px-5 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                    Fase
                                                </th>
                                                <th className="px-5 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                    AI-status
                                                </th>
                                                <th className="px-5 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                    Oppdatert
                                                </th>
                                                <th className="px-5 py-3 text-right text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                    Handling
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-200 bg-white">
                                            {rows.map((analysisCase) => {
                                                const aiStatusMeta = AI_STATUS_META[analysisCase.ai_status] ?? AI_STATUS_META.not_started;
                                                const updatedAtLabel = analysisCase.updated_at
                                                    ? formatDate(analysisCase.updated_at, locale)
                                                    : '—';

                                                return (
                                                    <tr key={analysisCase.id} className="align-top">
                                                        <td className="px-5 py-4">
                                                            <div className="space-y-1">
                                                                <div className="font-semibold text-slate-950">
                                                                    {analysisCase.title}
                                                                </div>
                                                                {analysisCase.reference ? (
                                                                    <div className="text-xs text-slate-400">
                                                                        Ref. {analysisCase.reference}
                                                                    </div>
                                                                ) : null}
                                                            </div>
                                                        </td>
                                                        <td className="px-5 py-4 text-sm text-slate-600">
                                                            {analysisCase.owner_name}
                                                        </td>
                                                        <td className="px-5 py-4 text-sm text-slate-600">
                                                            {analysisCase.stage_label}
                                                        </td>
                                                        <td className="px-5 py-4">
                                                            <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${aiStatusMeta.className}`}>
                                                                {aiStatusMeta.label}
                                                            </span>
                                                        </td>
                                                        <td className="px-5 py-4 text-sm text-slate-600">
                                                            {updatedAtLabel}
                                                        </td>
                                                        <td className="px-5 py-4 text-right">
                                                            <Link
                                                                href={analysisCase.action_url}
                                                                className="inline-flex rounded-xl border border-violet-200 bg-violet-50 px-3.5 py-2 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                                            >
                                                                Åpne sak
                                                            </Link>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        ) : (
                            <div className="rounded-[22px] border border-dashed border-slate-300 bg-slate-50 px-6 py-12">
                                <div className="text-lg font-semibold text-slate-900">
                                    Ingen saker er klare for oversikt ennå
                                </div>
                                <p className="mt-2 text-sm text-slate-500">
                                    Lagrede saker vil dukke opp her når de har nok struktur til videre arbeid.
                                </p>
                            </div>
                        )}
                    </div>
                </section>
            </div>
        </CustomerAppLayout>
    );
}
