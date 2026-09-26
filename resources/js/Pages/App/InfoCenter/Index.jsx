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

/**
 * The tone a panel's number carries.
 *
 * It used to tint the whole panel — border, background and text — which left nothing for the
 * selected state to say. Now that a panel can be the active view, the tint belongs to the
 * selection; the tone the backend sends still distinguishes the four counts, on the number alone.
 */
function summaryToneClassName(tone) {
    switch (tone) {
        case 'danger':
            return 'text-rose-700';
        case 'indigo':
            return 'text-indigo-700';
        case 'amber':
            return 'text-amber-800';
        case 'violet':
            return 'text-violet-700';
        default:
            return 'text-slate-900';
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

/**
 * The explanations that used to hang off the tab row, keyed by what they explain.
 *
 * They move with the thing they describe rather than being rewritten: "Mine oppgaver" means the
 * same whether it is read on a tab or on a panel. `due_soon` reuses the sentence the page help
 * already gives that counter, so no new wording was invented for it either.
 *
 * `decision` and `clarification` had no tab and therefore no text to inherit. Theirs says the one
 * thing the panel body cannot: these two count across every case the reader can see, whoever owns
 * the item, while the panels either side of them count only what is theirs. Four counters in a row
 * invite the assumption that they are all about you, and two of them are not.
 */
const INFO_CENTER_HELP_TEXTS = {
    my_tasks: 'Åpne aksjoner og oppfølginger som er tildelt deg.',
    awaiting_response: 'Aksjoner du har sendt ut og fortsatt venter svar på fra andre.',
    outbound: 'Aksjoner og oppfølginger du har opprettet, også tidligere og lukkede.',
    inbound: 'Informasjon og oppfølginger som har kommet inn til deg eller saken.',
    due_soon: 'Viser åpne punkter med nær frist.',
    decision: 'Åpne beslutningspunkter i alle saker du har tilgang til, uansett hvem de er tildelt. Lukkede beslutninger telles ikke med.',
    clarification: 'Åpne avklaringer i alle saker du har tilgang til, uansett hvem de er tildelt. Lukkede avklaringer telles ikke med.',
};

/**
 * One counter at the top of the page, and — where the count names a list — the way into it.
 *
 * The page used to show these four panels and then a separate row of buttons underneath, so the
 * thing the eye landed on first was not the thing you could act on. A panel whose key matches one
 * of the backend's views is now that view's control; the rest stay plain counters.
 *
 * That split is not arbitrary. `my_tasks` and `awaiting_response` are views the controller already
 * filters by; `decision`, `clarification` and `due_soon` are counted over the same visible set but
 * have no view behind them, and inventing one would be a filtering rule this page is not allowed
 * to add. A panel that cannot open a list therefore does not pretend it can — it has no hover, no
 * focus ring and no pointer.
 *
 * The info button is a sibling of the link rather than a child of it: nesting one interactive
 * element inside another is invalid markup, and it is also what keeps a click on the "i" from
 * selecting the panel underneath it.
 */
function SummaryPanel({ item, href, isActive }) {
    const helpText = INFO_CENTER_HELP_TEXTS[item.key];
    const toneClassName = summaryToneClassName(item.tone);

    const body = (
        <>
            <div className="text-base font-semibold leading-6 text-slate-900">
                {item.label}
            </div>
            <div className={classNames('mt-2 text-[30px] font-semibold leading-none tabular-nums', toneClassName)}>
                {item.count}
            </div>
            <p className="mt-2 text-base leading-6 text-slate-600">
                {item.description}
            </p>
        </>
    );

    const frame = classNames(
        'block h-full rounded-2xl border px-4 py-4 pr-11 transition',
        isActive
            ? 'border-violet-300 bg-violet-50/70 ring-1 ring-violet-200'
            : 'border-slate-200 bg-white',
    );

    return (
        <div className="relative min-w-60 flex-1" data-testid={`info-center-panel-${item.key}`}>
            {href ? (
                <Link
                    href={href}
                    aria-current={isActive ? 'true' : undefined}
                    data-active={isActive ? 'true' : 'false'}
                    className={classNames(
                        frame,
                        'hover:border-slate-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600',
                        isActive ? 'hover:border-violet-300' : '',
                    )}
                >
                    {body}
                </Link>
            ) : (
                <div className={frame}>{body}</div>
            )}

            {helpText ? (
                <span className="absolute right-3 top-3 z-10">
                    <InfoHint label={`Vis forklaring for ${item.label}`} text={helpText} />
                </span>
            ) : null}
        </div>
    );
}

/**
 * A view with no counter of its own.
 *
 * "Opprettet av meg" and "Innkommende" are real views, but nothing at the top of the page counts
 * them, so they have no panel to become. They keep their place as a quiet pair beside the list
 * heading — the list they change is right there — rather than as a second navigation row, which is
 * what this page was meant to lose.
 */
function SecondaryViewLink({ option, isActive }) {
    const helpText = INFO_CENTER_HELP_TEXTS[option.value];

    return (
        <span className="inline-flex items-center gap-1">
            <Link
                href={option.href}
                aria-current={isActive ? 'true' : undefined}
                data-active={isActive ? 'true' : 'false'}
                className={classNames(
                    'inline-flex min-h-9 items-center rounded-lg px-2.5 text-base font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600',
                    isActive
                        ? 'bg-violet-50 font-semibold text-violet-700'
                        : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950',
                )}
            >
                {option.label}
            </Link>
            {helpText ? (
                <InfoHint label={`Vis forklaring for ${option.label}`} text={helpText} />
            ) : null}
        </span>
    );
}

/**
 * Wiki work, shown in the same shape as an ordinary task.
 *
 * It comes from a different domain — a page version, not a bid case — but the person reading this
 * list does not care where the work is stored, only that it is theirs and still open. The card
 * therefore mirrors the ordinary one and simply names its own context: a Wiki page rather than a
 * saved notice.
 *
 * Two kinds share it, and the difference is stated rather than implied: reviewing decides whether
 * the whole page is published, quality assuring decides whether its individual claims hold. The
 * type chip and the detail row are what tell them apart.
 *
 * Deliberately no status chip beyond the type. Each task exists only while its work is outstanding
 * — until the claims are decided, or until the page is approved or sent back — so its presence in
 * the list IS its status.
 */
function WikiTaskCard({ task, locale }) {
    const detailUrl = task.action_url ?? '#';
    const isReview = task.type === 'wiki_review';

    return (
        <article
            className="rounded-[22px] border border-slate-200 bg-white px-4 py-4 shadow-[0_6px_16px_rgba(15,23,42,0.03)]"
            data-testid={isReview ? 'info-center-wiki-review-task' : 'info-center-wiki-qa-task'}
        >
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0 space-y-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <span
                            className={
                                isReview
                                    ? 'inline-flex items-center rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-inset ring-indigo-200'
                                    : 'inline-flex items-center rounded-full bg-violet-100 px-2.5 py-1 text-xs font-semibold text-violet-700 ring-1 ring-inset ring-violet-200'
                            }
                        >
                            {task.type_label}
                        </span>
                        <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-inset ring-slate-200">
                            Tildelt deg
                        </span>
                    </div>

                    <Link
                        href={detailUrl}
                        className="block text-lg font-semibold tracking-tight text-slate-950 transition hover:text-violet-700"
                    >
                        {task.subject_label}
                    </Link>

                    <div className="flex flex-wrap items-center gap-2 text-sm text-slate-500">
                        <span>
                            Wiki-side:{' '}
                            <Link href={detailUrl} className="font-medium text-slate-700 transition hover:text-violet-700">
                                {task.page_title ?? 'Ukjent side'}
                            </Link>
                        </span>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                {isReview ? 'Sendt av' : 'Påstander'}
                            </div>
                            <div className="mt-1 text-sm font-medium text-slate-900">
                                {isReview
                                    ? (task.submitted_by ?? 'Ukjent')
                                    : `${task.claims_handled} av ${task.claims_total} behandlet`}
                            </div>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                {isReview ? 'Sendt inn' : 'Tildelt'}
                            </div>
                            <div className="mt-1 text-sm font-medium text-slate-900">
                                {formatDate(isReview ? task.submitted_at : task.assigned_at, locale)}
                            </div>
                        </div>
                    </div>
                </div>

                <div className="shrink-0">
                    <Link
                        href={detailUrl}
                        className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-300 hover:text-slate-950"
                    >
                        Åpne Wiki-side
                    </Link>
                </div>
            </div>
        </article>
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
    const wikiTasks = infoCenter?.wiki_tasks ?? [];
    const pagination = infoCenter?.pagination ?? {};
    const activeOption = viewOptions.find((option) => option.value === activeView) ?? viewOptions[0] ?? null;
    // A panel becomes that view's control when the backend counts something it also filters by.
    // The lookup is by key, so a panel the controller adds later needs no change here.
    const viewByKey = new Map(viewOptions.map((option) => [option.value, option]));
    // Whatever is left has no counter to live in, and keeps a quiet place beside the list instead.
    const secondaryViews = viewOptions.filter(
        (option) => ! summaryItems.some((item) => item.key === option.value),
    );
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
                        <div className="mt-5 flex flex-wrap gap-3" data-testid="info-center-panels">
                            {summaryItems.map((item) => (
                                <SummaryPanel
                                    key={item.key}
                                    item={item}
                                    href={viewByKey.get(item.key)?.href ?? null}
                                    isActive={item.key === activeView}
                                />
                            ))}
                        </div>
                    ) : null}
                </section>

                <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-900">
                                {activeOption?.label ?? 'Infosenter'}
                            </h2>
                            <div className="mt-1 text-[1.7rem] font-semibold tracking-tight text-slate-950">
                                {pagination.total ?? items.length}{' '}
                                {Number(pagination.total ?? items.length) === 1 ? countLabelSingular : countLabelPlural}
                            </div>
                        </div>

                        <div className="flex flex-col items-start gap-2 sm:items-end">
                            {secondaryViews.length ? (
                                <div
                                    className="flex flex-wrap items-center gap-x-1 gap-y-1"
                                    data-testid="info-center-secondary-views"
                                >
                                    <span className="mr-1 text-base text-slate-500">Vis også</span>
                                    {secondaryViews.map((option) => (
                                        <SecondaryViewLink
                                            key={option.value}
                                            option={option}
                                            isActive={option.value === activeView}
                                        />
                                    ))}
                                </div>
                            ) : null}

                            {pagination.from && pagination.to ? (
                                <div className="text-sm text-slate-500">
                                    Viser {pagination.from}–{pagination.to}
                                </div>
                            ) : null}
                        </div>
                    </div>

                    {wikiTasks.length > 0 ? (
                        <div className="mb-3.5 space-y-3.5">
                            {wikiTasks.map((task) => (
                                <WikiTaskCard key={task.id} task={task} locale={locale} />
                            ))}
                        </div>
                    ) : null}

                    {items.length === 0 && wikiTasks.length === 0 ? (
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
                                                className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
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
