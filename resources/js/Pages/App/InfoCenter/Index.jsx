import { Link, router, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import InfoHint from '../../../Components/App/InfoHint';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function formatDate(value, locale, options = {}) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        ...options,
    }).format(new Date(value));
}

function statusBadgeClassName(status) {
    switch (status) {
        case 'open':
            return 'bg-emerald-100 text-emerald-700 ring-emerald-200';
        case 'waiting':
            return 'bg-amber-100 text-amber-800 ring-amber-200';
        case 'closed':
            return 'bg-slate-200 text-slate-700 ring-slate-300';
        default:
            return 'bg-slate-100 text-slate-700 ring-slate-200';
    }
}

function formatUser(user) {
    return user?.name ?? '—';
}

function heroToneClassName() {
    return 'border-slate-200 bg-white';
}

function summaryToneClassName(tone) {
    switch (tone) {
        case 'danger':
            return 'border-rose-200 bg-rose-50 text-rose-700';
        case 'indigo':
            return 'border-indigo-200 bg-indigo-50 text-indigo-700';
        case 'amber':
            return 'border-amber-200 bg-amber-50 text-amber-800';
        case 'violet':
            return 'border-violet-200 bg-violet-50 text-violet-700';
        default:
            return 'border-slate-200 bg-slate-50 text-slate-700';
    }
}

function infoCenterCountLabels(view) {
    switch (view) {
        case 'my_tasks':
            return ['oppgave', 'oppgaver'];
        case 'awaiting_response':
            return ['svaravventing', 'svaravventinger'];
        default:
            return ['oppfølgingspunkt', 'oppfølgingspunkter'];
    }
}

function infoCenterEmptyState(view) {
    switch (view) {
        case 'my_tasks':
            return {
                title: 'Ingen åpne oppgaver i denne visningen',
                description: 'Her vises aksjoner og oppfølginger som er tildelt deg.',
            };
        case 'awaiting_response':
            return {
                title: 'Ingen åpne svaravventinger i denne visningen',
                description: 'Her vises aksjoner du har sendt ut og fortsatt venter svar på.',
            };
        default:
            return {
                title: 'Ingen aksjoner eller oppfølginger i denne visningen',
                description: 'Når saker får nye aksjoner, avklaringer eller beslutninger, vises de her.',
            };
    }
}

const INFO_CENTER_TAB_HELP_TEXTS = {
    my_tasks: 'Åpne aksjoner og oppfølginger som er tildelt deg.',
    awaiting_response: 'Aksjoner du har sendt ut og fortsatt venter svar på fra andre.',
    outbound: 'Aksjoner og oppfølginger du har opprettet, også tidligere og lukkede.',
    inbound: 'Informasjon og oppfølginger som har kommet inn til deg eller saken.',
};

function InfoCenterViewTab({ option, activeView }) {
    const isActive = option.value === activeView;
    const helpText = INFO_CENTER_TAB_HELP_TEXTS[option.value];

    return (
        <div
            className={classNames(
                'inline-flex items-stretch overflow-visible rounded-xl border shadow-sm transition',
                isActive
                    ? 'border-violet-200 bg-violet-50'
                    : 'border-slate-200 bg-white hover:border-slate-300',
            )}
        >
            <Link
                key={option.value}
                href={option.href}
                className={classNames(
                    'inline-flex min-h-11 items-center justify-center px-4 py-2.5 text-sm font-semibold transition',
                    isActive
                        ? 'text-violet-700'
                        : 'text-slate-700 hover:text-slate-950',
                )}
            >
                {option.label}
            </Link>

            {helpText ? (
                <div className="flex items-center pr-3">
                    <InfoHint label={`Vis forklaring for ${option.label}`} text={helpText} />
                </div>
            ) : null}
        </div>
    );
}

export default function InfoCenterIndex({ infoCenter = null }) {
    const { locale = 'nb-NO', translations = {} } = usePage().props;
    const ic = translations?.info_center_page ?? {};
    const activeView = infoCenter?.active_view ?? 'my_tasks';
    const roleContext = infoCenter?.role_context ?? {};
    const viewOptions = infoCenter?.view_options ?? [];
    const summaryItems = infoCenter?.summary?.items ?? [];
    const items = infoCenter?.items ?? [];
    const pagination = infoCenter?.pagination ?? {};
    const activeOption = viewOptions.find((option) => option.value === activeView) ?? viewOptions[0] ?? null;
    const heroClassName = heroToneClassName();
    const [countLabelSingular, countLabelPlural] = infoCenterCountLabels(activeView);
    const emptyState = infoCenterEmptyState(activeView);

    const goToPage = (url) => {
        if (!url) {
            return;
        }

        router.visit(url, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    return (
        <CustomerAppLayout title="Infosenter" showPageTitle={false}>
            <div className="space-y-7">
                <section className={classNames('rounded-[28px] border p-6 shadow-[0_10px_26px_rgba(15,23,42,0.05)]', heroClassName)}>
                    <div className="flex flex-col gap-5">
                        <div className="space-y-4">
                            <div className="inline-flex items-center rounded-full border border-white/70 bg-white/80 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 shadow-sm">
                                {roleContext.label ?? 'Infosenter'}
                            </div>
                            <div className="space-y-2">
                                <div className="flex items-center gap-3">
                                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Infosenter</h1>
                                    <PageHelpButton
                                        buttonLabel={ic.page_help_button ?? 'Hjelp'}
                                        title={ic.page_help_title ?? 'Infosenter'}
                                        intro={ic.page_help_intro}
                                        sections={[
                                            {
                                                title: ic.page_help_section_views ?? 'Fanene i Infosenteret',
                                                items: [
                                                    { title: ic.page_help_item_my_tasks_title ?? 'Mine oppgaver', text: ic.page_help_item_my_tasks_text ?? 'Viser åpne oppgaver og oppfølginger som er tildelt deg.' },
                                                    { title: ic.page_help_item_awaiting_title ?? 'Venter på svar', text: ic.page_help_item_awaiting_text ?? 'Viser oppfølginger du har sendt ut, men som fortsatt venter på respons.' },
                                                    { title: ic.page_help_item_outbound_title ?? 'Opprettet av meg', text: ic.page_help_item_outbound_text ?? 'Viser oppgaver og oppfølginger du selv har opprettet.' },
                                                    { title: ic.page_help_item_inbound_title ?? 'Innkommende', text: ic.page_help_item_inbound_text ?? 'Viser oppfølginger eller forespørsler som kommer inn til deg.' },
                                                    { title: ic.page_help_item_deadline_title ?? 'Frister innen 7 dager', text: ic.page_help_item_deadline_text ?? 'Viser åpne punkter med nær frist.' },
                                                ],
                                            },
                                            {
                                                title: ic.page_help_section_practical ?? 'Praktisk bruk',
                                                items: [
                                                    { title: ic.page_help_item_practical_title ?? 'Daglig oppfølging', text: ic.page_help_item_practical_text ?? 'Bruk Infosenteret som din daglige personlige oppfølgingsliste. Bruk Arbeidsliste og sakssider til selve anbudssakene.' },
                                                ],
                                            },
                                        ]}
                                    />
                                </div>
                                <p className="max-w-3xl text-[15px] leading-7 text-slate-600">
                                    {roleContext.headline ?? 'Opprett og følg opp aksjoner, avklaringer og beslutninger på tvers av saker du har tilgang til.'}
                                </p>
                                <p className="max-w-3xl text-[14px] leading-7 text-slate-500">
                                    {roleContext.subheadline ?? 'Følg opp saker, avklaringer og beslutninger uten å åpne hver sak manuelt.'}
                                </p>
                                {roleContext.is_case_operational ? (
                                    <div className="inline-flex rounded-full border border-violet-200 bg-white/80 px-3 py-1 text-xs font-semibold text-violet-700 shadow-sm">
                                        Operativ visning aktivert av aktive saker
                                    </div>
                                ) : null}
                            </div>
                        </div>

                    </div>

                    {summaryItems.length ? (
                        <div className="mt-5 grid gap-3 md:grid-cols-3">
                            {summaryItems.map((item) => (
                                <div key={item.key} className={classNames('rounded-2xl border px-4 py-4 shadow-sm', summaryToneClassName(item.tone))}>
                                    <div className="text-xs font-semibold uppercase tracking-[0.12em] opacity-70">
                                        {item.label}
                                    </div>
                                    <div className="mt-2 text-3xl font-semibold tracking-tight">
                                        {item.count}
                                    </div>
                                    <p className="mt-2 text-sm leading-6 opacity-90">
                                        {item.description}
                                    </p>
                                </div>
                            ))}
                        </div>
                    ) : null}
                </section>

                <section className="rounded-[24px] border border-slate-200 bg-white p-5 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="flex flex-wrap gap-2.5">
                        {viewOptions.map((option) => (
                            <InfoCenterViewTab
                                key={option.value}
                                option={option}
                                activeView={activeView}
                            />
                        ))}
                    </div>
                </section>

                <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="mb-5 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">
                                {activeOption?.label ?? 'Infosenter'}
                            </div>
                            <div className="mt-1 text-[1.7rem] font-semibold tracking-tight text-slate-950">
                                {pagination.total ?? items.length}{' '}
                                {Number(pagination.total ?? items.length) === 1 ? countLabelSingular : countLabelPlural}
                            </div>
                        </div>

                        {pagination.from && pagination.to ? (
                            <div className="text-sm text-slate-500">
                                Viser {pagination.from}–{pagination.to}
                            </div>
                        ) : null}
                    </div>

                    {items.length === 0 ? (
                        <div className="rounded-[22px] border border-dashed border-slate-300 bg-slate-50 px-6 py-14 text-center">
                            <div className="text-lg font-semibold text-slate-900">{emptyState.title}</div>
                            <p className="mt-2 text-sm text-slate-500">{emptyState.description}</p>
                        </div>
                    ) : (
                        <div className="space-y-3.5">
                            {items.map((item) => {
                                const detailUrl = item.action_url ?? item.saved_notice?.show_url ?? '#';
                                const isAiRequirementTask = item.type === 'ai_requirement_responsibility';

                                return (
                                <article
                                    key={item.id}
                                    className="rounded-[22px] border border-slate-200 bg-white px-4 py-4 shadow-[0_6px_16px_rgba(15,23,42,0.03)]"
                                >
                                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                        <div className="min-w-0 space-y-3">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span
                                                    className={classNames(
                                                        'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset',
                                                        statusBadgeClassName(item.status),
                                                    )}
                                                >
                                                    {item.status_label}
                                                </span>
                                                <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-inset ring-slate-200">
                                                    {item.type_label}
                                                </span>
                                                <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-inset ring-slate-200">
                                                    {item.direction_label}
                                                </span>
                                                <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-inset ring-slate-200">
                                                    {item.channel_label}
                                                </span>
                                                {item.status !== 'closed' && item.requires_response ? (
                                                    <span className="inline-flex items-center rounded-full bg-violet-100 px-2.5 py-1 text-xs font-semibold text-violet-700 ring-1 ring-inset ring-violet-200">
                                                        Venter på svar
                                                    </span>
                                                ) : null}
                                            </div>

                                            <Link
                                                href={detailUrl}
                                                className="block text-lg font-semibold tracking-tight text-slate-950 transition hover:text-violet-700"
                                            >
                                                {item.subject_label}
                                            </Link>

                                            <div className="flex flex-wrap items-center gap-2 text-sm text-slate-500">
                                                <span>
                                                    Sak:{' '}
                                                    <Link
                                                        href={detailUrl}
                                                        className="font-medium text-slate-700 transition hover:text-violet-700"
                                                    >
                                                        {item.saved_notice?.title ?? 'Ukjent sak'}
                                                    </Link>
                                                </span>
                                                {item.saved_notice?.reference_number ? (
                                                    <span>· Referanse: {item.saved_notice.reference_number}</span>
                                                ) : null}
                                            </div>

                                            <p className="max-w-4xl text-sm leading-6 text-slate-700">
                                                {item.body_preview}
                                            </p>

                                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                                <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                                    <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">Ansvarlig</div>
                                                    <div className="mt-1 text-sm font-medium text-slate-900">{formatUser(item.owner)}</div>
                                                </div>
                                                <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                                    <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">Opprettet av</div>
                                                    <div className="mt-1 text-sm font-medium text-slate-900">{formatUser(item.created_by)}</div>
                                                </div>
                                                <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                                    <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">Oppfølgingsfrist</div>
                                                    <div className="mt-1 text-sm font-medium text-slate-900">
                                                        {item.response_due_at ? formatDate(item.response_due_at, locale) : '—'}
                                                    </div>
                                                </div>
                                                <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                                    <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">Opprettet</div>
                                                    <div className="mt-1 text-sm font-medium text-slate-900">
                                                        {formatDate(item.created_at, locale, {
                                                            hour: '2-digit',
                                                            minute: '2-digit',
                                                        })}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="flex shrink-0 flex-wrap gap-2 lg:justify-end">
                                            <Link
                                                href={detailUrl}
                                                className="inline-flex min-h-11 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
                                            >
                                                {isAiRequirementTask ? 'Åpne krav i AI' : 'Åpne sak'}
                                            </Link>
                                        </div>
                                    </div>
                                </article>
                                );
                            })}
                        </div>
                    )}

                    <div className="mt-5 flex flex-col gap-3 rounded-[20px] border border-slate-200 bg-white px-5 py-4 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            {pagination.from && pagination.to && pagination.total
                                ? `${pagination.from}–${pagination.to} av ${pagination.total}`
                                : `${items.length} oppfølgingspunkter`}
                        </div>

                        <div className="flex gap-2">
                            <button
                                type="button"
                                disabled={!pagination.prev_page_url}
                                onClick={() => goToPage(pagination.prev_page_url)}
                                className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:text-slate-300"
                            >
                                Forrige
                            </button>
                            <button
                                type="button"
                                disabled={!pagination.next_page_url}
                                onClick={() => goToPage(pagination.next_page_url)}
                                className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:text-slate-300"
                            >
                                Neste
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </CustomerAppLayout>
    );
}
