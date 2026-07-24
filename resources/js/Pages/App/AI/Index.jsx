import { Link, usePage } from '@inertiajs/react';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import AlertBox from '../../../Components/App/AlertBox';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';
import StatusBadge from '../../../Components/App/StatusBadge';

const AI_STATUS_TONE = {
    not_started: 'slate',
    ready: 'emerald',
    in_review: 'violet',
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
    const {
        locale = 'nb-NO',
        translations = {},
        auth = {},
        can_use_ai_offer: canUseAiOffer = true,
    } = usePage().props;
    const tai = translations?.ai ?? {};
    const rows = Array.isArray(analysisCases) ? analysisCases : [];
    const currentUser = auth.user ?? null;
    const billingHref = currentUser?.is_system_owner ? '/app/billing' : null;

    const AI_STATUS_LABEL = {
        not_started: tai.ai_status_not_started ?? 'Ikke startet',
        ready: tai.ai_status_ready ?? 'Påbegynt',
        in_review: tai.ai_status_in_review ?? 'Under vurdering',
    };

    return (
        <CustomerAppLayout title={pageTitle} showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <div className="text-base font-medium uppercase tracking-[0.16em] text-slate-600">
                        {tai.overview ?? 'Oversikt'}
                    </div>
                    <div className="flex items-center gap-3">
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">
                            {tai.overview ?? 'Oversikt'}
                        </h1>
                        <PageHelpButton
                            buttonLabel={tai.help_button ?? 'Hjelp'}
                            title={tai.help_panel_title ?? 'Om AI-oversikten'}
                            intro={tai.help_panel_intro ?? 'AI-arbeidsflaten samler sakene som er klare for AI-assistert anbudsarbeid, og gir deg tilgang til krav, kunnskapsgrunnlag og svarutkast.'}
                            sections={[
                                {
                                    title: tai.help_section_cases ?? 'Saker',
                                    items: [
                                        {
                                            title: tai.help_item_cases_ready_title ?? 'Klare saker',
                                            text: tai.help_item_cases_ready_text ?? 'Saker vises her når de er lagret og har nok struktur for AI-assistert arbeid.',
                                        },
                                    ],
                                },
                                {
                                    title: tai.help_section_status ?? 'AI-status',
                                    items: [
                                        {
                                            title: tai.ai_status_not_started ?? 'Ikke startet',
                                            text: tai.help_item_status_not_started ?? 'Ingen AI-kjøringer er startet for saken ennå.',
                                        },
                                        {
                                            title: tai.ai_status_ready ?? 'Påbegynt',
                                            text: tai.help_item_status_ready ?? 'AI-arbeid er i gang, men ikke ferdig gjennomgått av teamet.',
                                        },
                                        {
                                            title: tai.ai_status_in_review ?? 'Under vurdering',
                                            text: tai.help_item_status_in_review ?? 'Saken er under aktiv gjennomgang og kvalitetssikring av teamet.',
                                        },
                                    ],
                                },
                            ]}
                        />
                    </div>
                    <p className="max-w-3xl text-base leading-7 text-slate-600">
                        {tai.index_subtitle ?? 'Her jobber du med konkrete anbudssaker, krav og kunnskapsgrunnlag.'}
                    </p>
                </section>

                {!canUseAiOffer ? (
                    <AlertBox title={tai.ai_access_unavailable_title ?? 'AI er ikke tilgjengelig'}>
                        <p className="mt-1">
                            {tai.ai_access_unavailable_message ?? 'AI er ikke tilgjengelig for abonnementet ditt. System Owner kan aktivere eller endre abonnement under Abonnement.'}
                        </p>
                        {billingHref ? (
                            <a
                                href={billingHref}
                                className="mt-3 inline-flex items-center justify-center rounded-full border border-amber-300 bg-white px-4 py-2 text-base font-semibold text-amber-900 transition hover:bg-amber-100"
                            >
                                {tai.ai_access_billing_cta ?? 'Gå til Abonnement'}
                            </a>
                        ) : null}
                    </AlertBox>
                ) : null}

                <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="space-y-5">
                        <div className="space-y-2">
                            <div className="text-base font-medium uppercase tracking-[0.16em] text-slate-600">
                                {tai.cases_nav ?? 'Saker'}
                            </div>
                            <h2 className="text-2xl font-semibold tracking-tight text-slate-950">
                                {tai.cases_ready_title ?? 'Saker klare for arbeid med AI-assistanse'}
                            </h2>
                            <p className="max-w-3xl text-base leading-6 text-slate-600">
                                {tai.cases_ready_subtitle ?? 'Disse sakene har nok struktur til at arbeid kan skje videre i saken.'}
                            </p>
                        </div>

                        {rows.length > 0 ? (
                            <div className="overflow-hidden rounded-[22px] border border-slate-200">
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-slate-200">
                                        <thead className="bg-slate-50">
                                            <tr>
                                                <th className="px-5 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    {tai.col_case ?? 'Sak'}
                                                </th>
                                                <th className="px-5 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    {tai.col_responsible ?? 'Ansvarlig'}
                                                </th>
                                                <th className="px-5 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    {tai.col_phase ?? 'Fase'}
                                                </th>
                                                <th className="px-5 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    {tai.col_ai_status ?? 'AI-status'}
                                                </th>
                                                <th className="px-5 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    {tai.col_updated ?? 'Oppdatert'}
                                                </th>
                                                <th className="px-5 py-3 text-right text-base font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                    {tai.col_action ?? 'Handling'}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-200 bg-white">
                                            {rows.map((analysisCase) => {
                                                const aiStatusTone = AI_STATUS_TONE[analysisCase.ai_status] ?? 'slate';
                                                const aiStatusLabel = AI_STATUS_LABEL[analysisCase.ai_status] ?? AI_STATUS_LABEL.not_started;
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
                                                                    <div className="text-base text-slate-600">
                                                                        {tai.ref_prefix ?? 'Ref.'} {analysisCase.reference}
                                                                    </div>
                                                                ) : null}
                                                            </div>
                                                        </td>
                                                        <td className="px-5 py-4 text-base text-slate-600">
                                                            {analysisCase.owner_name}
                                                        </td>
                                                        <td className="px-5 py-4 text-base text-slate-600">
                                                            {analysisCase.stage_label}
                                                        </td>
                                                        <td className="px-5 py-4">
                                                            <StatusBadge tone={aiStatusTone}>
                                                                {aiStatusLabel}
                                                            </StatusBadge>
                                                        </td>
                                                        <td className="px-5 py-4 text-base text-slate-600">
                                                            {updatedAtLabel}
                                                        </td>
                                                        <td className="px-5 py-4 text-right">
                                                            <Link
                                                                href={analysisCase.action_url}
                                                                className="inline-flex rounded-xl border border-violet-200 bg-violet-50 px-3.5 py-2 text-base font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                                            >
                                                                {tai.open_case ?? 'Åpne sak'}
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
                            <EmptyStateBox
                                title={tai.no_cases_title ?? 'Ingen saker er klare for oversikt ennå'}
                                description={tai.no_cases_subtitle ?? 'Lagrede saker vil dukke opp her når de har nok struktur til videre arbeid.'}
                            />
                        )}
                    </div>
                </section>
            </div>
        </CustomerAppLayout>
    );
}
