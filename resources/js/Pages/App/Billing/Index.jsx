import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import InfoHint from '../../../Components/App/InfoHint';
import PageHelpButton from '../../../Components/App/PageHelpButton';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

const STATUS_BADGE_CLASSES = {
    active: 'bg-green-100 text-green-800',
    trialing: 'bg-blue-100 text-blue-800',
    past_due: 'bg-amber-100 text-amber-800',
    unpaid: 'bg-amber-100 text-amber-800',
    open: 'bg-amber-100 text-amber-800',
    pending: 'bg-amber-100 text-amber-800',
    draft: 'bg-slate-100 text-slate-600',
    paid: 'bg-green-100 text-green-800',
    cancelled: 'bg-slate-100 text-slate-600',
    canceled: 'bg-slate-100 text-slate-600',
    void: 'bg-slate-100 text-slate-600',
    incomplete: 'bg-amber-100 text-amber-800',
    incomplete_expired: 'bg-slate-100 text-slate-600',
    uncollectible: 'bg-slate-100 text-slate-600',
    inactive: 'bg-slate-100 text-slate-600',
    default: 'bg-slate-100 text-slate-600',
};

const ACTIVE_STRIPE_STATUSES = new Set(['active', 'trialing', 'past_due', 'unpaid']);

function normalizeKey(value) {
    return String(value ?? '').toLowerCase();
}

function isActiveStripeSubscription(status) {
    return ACTIVE_STRIPE_STATUSES.has(normalizeKey(status));
}

function resolveLabel(value, labels, fallback) {
    const key = normalizeKey(value);
    return labels?.[key] ?? fallback;
}

function StatusBadge({ status, label }) {
    const statusKey = normalizeKey(status);
    const classes = STATUS_BADGE_CLASSES[statusKey] ?? STATUS_BADGE_CLASSES.default;

    return (
        <span className={classNames('inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', classes)}>
            {label}
        </span>
    );
}

function SummaryCard({ label, value }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                {label}
            </div>
            <div className="mt-3 text-lg font-semibold text-slate-900">
                {value}
            </div>
        </div>
    );
}

function ConfirmDialog({ isOpen, title, message, onConfirm, onCancel, confirmLabel = 'Bekreft', cancelLabel = 'Avbryt', danger = false }) {
    if (!isOpen) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 px-4">
            <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
                <h3 className="text-base font-semibold text-slate-900">{title}</h3>
                <p className="mt-2 text-sm leading-6 text-slate-600">{message}</p>
                <div className="mt-5 flex justify-end gap-3">
                    <button
                        onClick={onCancel}
                        className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                    >
                        {cancelLabel}
                    </button>
                    <button
                        onClick={onConfirm}
                        className={classNames(
                            'rounded-lg px-4 py-2 text-sm font-medium text-white',
                            danger ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'
                        )}
                    >
                        {confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}

export default function BillingIndex() {
    const page = usePage().props;
    const {
        available_plans: availablePlans = [],
        customer_plan: customerPlan = {},
        subscription,
        invoices = [],
        billing_lines: billingLines = [],
        service_levels: serviceLevels = [],
        translations = {},
        errors = {},
        flash,
        locale = 'nb-NO',
    } = page;

    const tb = translations.billing ?? {};
    const planChangeText = tb.plan_change ?? {};
    const summaryText = tb.summary ?? {};
    const alertText = tb.alerts ?? {};
    const subscriptionText = tb.stripe_subscription ?? {};
    const servicesText = tb.procynia_services ?? {};
    const servicesTableText = servicesText.table ?? {};
    const levelsText = tb.procynia_levels ?? {};
    const levelsTableText = levelsText.table ?? {};
    const invoicesText = tb.invoices ?? {};
    const invoicesTableText = invoicesText.table ?? {};
    const statusLabels = tb.status_labels ?? {};
    const sourceLabels = tb.source_labels ?? {};
    const intervalLabels = tb.interval_labels ?? {};
    const serviceLevelLabels = tb.service_level_labels ?? {};
    const lineTypeLabels = tb.billing_line_type_labels ?? {};

    const [confirmCancel, setConfirmCancel] = useState(false);
    const [confirmResume, setConfirmResume] = useState(false);
    const [planChangeOpen, setPlanChangeOpen] = useState(false);
    const [planChangeStep, setPlanChangeStep] = useState('selection');
    const [selectedPlanKey, setSelectedPlanKey] = useState('');
    const [selectedInterval, setSelectedInterval] = useState('monthly');

    const sortedInvoices = [...invoices].sort((left, right) => (right.date_sort ?? 0) - (left.date_sort ?? 0));
    const hasActiveStripeSubscription = subscription ? isActiveStripeSubscription(subscription.status) : false;
    const hasProcyniaServices = billingLines.length > 0;
    const hasProcyniaLevels = serviceLevels.length > 0;
    const openStripeInvoices = sortedInvoices.filter((invoice) => invoice.status !== 'paid');
    const currentPlanKey = normalizeKey(subscription?.plan ?? customerPlan.plan);
    const currentPlanLabel = subscription?.plan_label
        ?? customerPlan.plan_label
        ?? planChangeText.current_plan
        ?? 'Nåværende plan';
    const currentIntervalKey = normalizeKey(subscription?.billing_interval ?? customerPlan.billing_interval ?? 'monthly');
    const canChangePlan = availablePlans.length > 0 && currentPlanKey !== 'enterprise';
    const selectedPlan = availablePlans.find((plan) => normalizeKey(plan.key) === selectedPlanKey) ?? null;
    const selectedPlanIntervals = selectedPlan?.intervals ?? [];
    const selectedIntervalOption = selectedPlanIntervals.find((option) => normalizeKey(option.interval) === selectedInterval)
        ?? selectedPlanIntervals[0]
        ?? null;

    const formatDate = (dateStr) => {
        if (!dateStr) {
            return '—';
        }

        return new Intl.DateTimeFormat(locale, { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(dateStr));
    };

    const resolveStatusLabel = (status) => resolveLabel(status, statusLabels, statusLabels.unknown ?? 'Ukjent');
    const resolveSourceLabel = (source) => resolveLabel(source, sourceLabels, summaryText.not_available ?? 'Ikke tilgjengelig fra Stripe');
    const resolveIntervalLabel = (interval) => resolveLabel(interval, intervalLabels, summaryText.not_available ?? 'Ikke tilgjengelig fra Stripe');
    const resolveLineTypeLabel = (interval) => (normalizeKey(interval) === 'one_time'
        ? (lineTypeLabels.one_time ?? 'Engang')
        : (lineTypeLabels.recurring ?? 'Løpende'));
    const resolveServiceLevelLabel = (levelKey) => resolveLabel(
        levelKey,
        serviceLevelLabels,
        summaryText.not_available ?? 'Ikke tilgjengelig fra Stripe'
    );
    const resolveBillingLineLabel = (line) => line.billing_price
        ?? line.billing_product
        ?? summaryText.not_available
        ?? 'Ikke tilgjengelig fra Stripe';

    const formatCount = (count) => {
        if (count === 0) {
            return summaryText.none ?? 'Ingen';
        }

        const template = count === 1 ? summaryText.active_one : summaryText.active_many;
        return (template ?? ':count aktive').replace(':count', String(count));
    };

    const formatOpenInvoiceCount = (count) => {
        if (count === 0) {
            return summaryText.no_outstanding ?? 'Ingen utestående Stripe-fakturaer';
        }

        const template = count === 1 ? summaryText.open_one : summaryText.open_many;
        return (template ?? ':count åpne Stripe-fakturaer').replace(':count', String(count));
    };

    const formatMoney = (value) => new Intl.NumberFormat(locale).format(Number(value ?? 0));

    const formatPlanIntervalPrice = (value, interval) => {
        if (value === null || value === undefined) {
            return summaryText.not_available ?? 'Ikke tilgjengelig fra Stripe';
        }

        const intervalUnit = normalizeKey(interval) === 'yearly'
            ? (planChangeText.yearly_unit ?? 'year')
            : (planChangeText.monthly_unit ?? 'month');

        return `${formatMoney(value)} kr/${intervalUnit}`;
    };

    const buildPlanLabel = (plan) => {
        const intervalPrices = (plan.intervals ?? [])
            .map((interval) => formatPlanIntervalPrice(interval.price_nok, interval.interval))
            .join(' · ');
        const currentBadge = plan.is_current ? ` (${planChangeText.current_badge ?? 'Nåværende'})` : '';

        return `${plan.name}${intervalPrices ? ` — ${intervalPrices}` : ''}${currentBadge}`;
    };

    const stripeSubscriptionValue = hasActiveStripeSubscription
        ? (subscription?.plan_label ?? summaryText.not_available ?? 'Ikke tilgjengelig fra Stripe')
        : (summaryText.no_active_subscription ?? 'Ingen aktiv Stripe-subscription');

    const stripePaymentValue = sortedInvoices.length === 0
        ? (summaryText.not_available ?? 'Ikke tilgjengelig fra Stripe')
        : formatOpenInvoiceCount(openStripeInvoices.length);

    const procyniaServicesValue = formatCount(billingLines.length);
    const procyniaLevelsValue = formatCount(serviceLevels.length);

    const showProcyniaStripeWarning = !hasActiveStripeSubscription && (hasProcyniaServices || hasProcyniaLevels);
    const currentIntervalLabel = currentIntervalKey === 'yearly'
        ? (planChangeText.yearly ?? 'Årlig')
        : (planChangeText.monthly ?? 'Månedlig');
    const selectedPlanSummary = selectedPlan ? {
        name: selectedPlan.name,
        intervalLabel: selectedIntervalOption?.label
            ?? (normalizeKey(selectedInterval) === 'yearly'
                ? (planChangeText.yearly ?? 'Årlig')
                : (planChangeText.monthly ?? 'Månedlig')),
        priceLabel: selectedIntervalOption
            ? formatPlanIntervalPrice(selectedIntervalOption.price_nok, selectedIntervalOption.interval)
            : null,
    } : null;
    const isSamePlanSelection = normalizeKey(selectedPlanKey) === currentPlanKey
        && normalizeKey(selectedInterval) === currentIntervalKey;
    const canConfirmPlanChange = Boolean(selectedPlanKey && selectedInterval && !isSamePlanSelection);
    const planChangeError = errors.plan ?? errors.interval ?? '';

    const handleCancel = () => {
        router.post('/app/billing/cancel', {}, {
            preserveScroll: true,
            onSuccess: () => setConfirmCancel(false),
        });
    };

    const handleResume = () => {
        router.post('/app/billing/resume', {}, {
            preserveScroll: true,
            onSuccess: () => setConfirmResume(false),
        });
    };

    const openPlanChangeModal = () => {
        if (!availablePlans.length) {
            return;
        }

        const initialPlan = availablePlans.find((plan) => normalizeKey(plan.key) === currentPlanKey) ?? availablePlans[0];
        const initialInterval = initialPlan?.intervals?.find((interval) => normalizeKey(interval.interval) === currentIntervalKey)?.interval
            ?? initialPlan?.intervals?.[0]?.interval
            ?? 'monthly';

        setSelectedPlanKey(initialPlan?.key ?? '');
        setSelectedInterval(normalizeKey(initialInterval));
        setPlanChangeStep('selection');
        setPlanChangeOpen(true);
    };

    const openPlanChangeConfirmation = () => {
        if (!canConfirmPlanChange) {
            return;
        }

        setPlanChangeStep('confirm');
    };

    const closePlanChangeModal = () => {
        setPlanChangeOpen(false);
        setPlanChangeStep('selection');
    };

    const handlePlanSelection = (planKey) => {
        const nextPlan = availablePlans.find((plan) => normalizeKey(plan.key) === normalizeKey(planKey));

        setSelectedPlanKey(planKey);

        if (!nextPlan) {
            return;
        }

        const preferredInterval = nextPlan.intervals?.find((interval) => normalizeKey(interval.interval) === selectedInterval)?.interval
            ?? nextPlan.intervals?.[0]?.interval
            ?? 'monthly';

        setSelectedInterval(normalizeKey(preferredInterval));
    };

    const handlePlanChangeSubmit = () => {
        if (!canConfirmPlanChange) {
            return;
        }

        router.post('/app/billing/change-plan', {
            plan: selectedPlanKey,
            interval: selectedInterval,
        }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: closePlanChangeModal,
        });
    };

    return (
        <CustomerAppLayout title={tb.title ?? 'Fakturering og abonnement'}>
            <div className="mx-auto max-w-6xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
                {flash?.success && (
                    <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        {flash.error}
                    </div>
                )}

                <header className="space-y-3">
                    <div className="flex items-center gap-3">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-900">
                            {tb.title ?? 'Fakturering og abonnement'}
                        </h1>
                        <PageHelpButton
                            buttonLabel={tb.page_help_button ?? 'Hjelp'}
                            title={tb.page_help_title ?? 'Om Fakturering'}
                            intro={tb.page_help_intro ?? 'Faktureringssiden gir deg oversikt over abonnement, tjenester, brukernivåer og betalingsstatus.'}
                            sections={[
                                {
                                    title: tb.page_help_section_overview ?? 'Hva du finner her',
                                    items: [
                                        {
                                            title: tb.page_help_item_subscription_title ?? 'Abonnement',
                                            text: tb.page_help_item_subscription_text ?? 'Viser gjeldende abonnement, neste fakturering og betalingsstatus.',
                                        },
                                        {
                                            title: tb.page_help_item_services_title ?? 'Tjenester og brukernivåer',
                                            text: tb.page_help_item_services_text ?? 'Viser hvilke tjenester som er aktive og antall brukere per nivå.',
                                        },
                                        {
                                            title: tb.page_help_item_invoices_title ?? 'Fakturaer',
                                            text: tb.page_help_item_invoices_text ?? 'Historikk over fakturaer med beløp, dato og status.',
                                        },
                                    ],
                                },
                            ]}
                        />
                    </div>
                    <p className="max-w-4xl text-sm leading-6 text-slate-600">
                        {tb.subtitle ?? 'Oversikt over abonnement, tjenester, brukernivåer og fakturaer fra Stripe.'}
                    </p>
                    <p className="max-w-4xl text-sm leading-6 text-slate-600">
                        {tb.intro ?? 'Stripe er økonomisk fasit for abonnement, fakturaer, betaling, utestående og forfall. Procynia er fasit for tjenester, tilgang og brukernivåer.'}
                    </p>
                </header>

                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <SummaryCard
                        label={summaryText.stripe_subscription ?? 'Abonnement fra Stripe'}
                        value={stripeSubscriptionValue}
                    />
                    <SummaryCard
                        label={summaryText.stripe_payment ?? 'Betaling fra Stripe'}
                        value={stripePaymentValue}
                    />
                    <SummaryCard
                        label={summaryText.procynia_services ?? 'Tjenester i Procynia'}
                        value={procyniaServicesValue}
                    />
                    <SummaryCard
                        label={summaryText.procynia_levels ?? 'Brukernivåer i Procynia'}
                        value={procyniaLevelsValue}
                    />
                </section>

                {showProcyniaStripeWarning && (
                    <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-900">
                        {alertText.services_without_subscription ?? 'Kontoen har aktive tjenester eller brukernivåer i Procynia, men ingen aktiv Stripe-subscription. Fakturaer, betaling og utestående håndteres via Stripe når abonnement eller faktura er opprettet.'}
                    </div>
                )}

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="flex items-center gap-2">
                        <h2 className="text-base font-semibold text-slate-900">
                            {subscriptionText.heading ?? 'Abonnement fra Stripe'}
                        </h2>
                        <InfoHint size="sm" label="Vis forklaring for Abonnement" text={tb.hint_subscription} />
                    </div>

                    {hasActiveStripeSubscription ? (
                        <div className="mt-4 space-y-4">
                            <div className="flex flex-wrap items-center gap-3">
                                <StatusBadge status={subscription.status} label={resolveStatusLabel(subscription.status)} />
                                {subscription.cancel_at_period_end && (
                                    <span className="inline-flex items-center rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-medium text-yellow-800">
                                        {subscriptionText.cancels_at_period_end ?? 'Avsluttes ved periodeslutt'}
                                    </span>
                                )}
                            </div>

                            <dl className="grid grid-cols-1 gap-x-8 gap-y-4 text-sm md:grid-cols-2">
                                {subscription.plan_label && (
                                    <>
                                        <dt className="text-slate-500">{subscriptionText.plan ?? 'Stripe-plan'}</dt>
                                        <dd className="font-medium text-slate-900">{subscription.plan_label}</dd>
                                    </>
                                )}
                                <dt className="text-slate-500">{subscriptionText.status ?? 'Stripe-status'}</dt>
                                <dd className="font-medium text-slate-900">{resolveStatusLabel(subscription.status)}</dd>

                                {subscription.billing_interval && (
                                    <>
                                        <dt className="text-slate-500">{subscriptionText.interval ?? 'Stripe-faktureringsintervall'}</dt>
                                        <dd className="font-medium text-slate-900">{resolveIntervalLabel(subscription.billing_interval)}</dd>
                                    </>
                                )}
                                {subscription.current_period_end && (
                                    <>
                                        <dt className="text-slate-500">{subscriptionText.next_renewal ?? 'Neste Stripe-fakturering'}</dt>
                                        <dd className="font-medium text-slate-900">{formatDate(subscription.current_period_end)}</dd>
                                    </>
                                )}
                                {subscription.trial_ends_at && (
                                    <>
                                        <dt className="text-slate-500">{subscriptionText.trial ?? 'Stripe-trial'}</dt>
                                        <dd className="font-medium text-slate-900">{formatDate(subscription.trial_ends_at)}</dd>
                                    </>
                                )}
                                {subscription.included_users !== undefined && subscription.included_users !== null && (
                                    <>
                                        <dt className="text-slate-500">{subscriptionText.included_users ?? 'Inkluderte brukere'}</dt>
                                        <dd className="font-medium text-slate-900">{subscription.included_users}</dd>
                                    </>
                                )}
                                {subscription.included_ai_credits !== undefined && subscription.included_ai_credits !== null && (
                                    <>
                                        <dt className="flex items-center gap-1.5 text-slate-500">
                                            {subscriptionText.included_ai_credits ?? 'Inkluderte KI-tilbud'}
                                            <InfoHint size="sm" label="Vis forklaring for KI-tilbud" text={tb.hint_ai_credits} />
                                        </dt>
                                        <dd className="font-medium text-slate-900">{subscription.included_ai_credits}</dd>
                                    </>
                                )}
                            </dl>

                            <div className="flex flex-wrap gap-3 pt-2">
                                {canChangePlan && (
                                    <button
                                        onClick={openPlanChangeModal}
                                        className="rounded-lg border border-blue-200 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-50"
                                    >
                                        {planChangeText.button ?? 'Endre plan'}
                                    </button>
                                )}
                                {subscription.status === 'active' && !subscription.cancel_at_period_end && (
                                    <button
                                        onClick={() => setConfirmCancel(true)}
                                        className="rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50"
                                    >
                                        {tb.cancel ?? 'Si opp abonnement'}
                                    </button>
                                )}
                                {subscription.cancel_at_period_end && (
                                    <button
                                        onClick={() => setConfirmResume(true)}
                                        className="rounded-lg border border-green-200 px-4 py-2 text-sm font-medium text-green-700 hover:bg-green-50"
                                    >
                                        {tb.resume ?? 'Behold abonnement'}
                                    </button>
                                )}
                            </div>
                        </div>
                    ) : (
                        <div className="mt-4 space-y-3">
                            <p className="text-sm leading-6 text-slate-600">
                                {subscriptionText.empty ?? 'Ingen aktiv Stripe-subscription. Ta kontakt med Procynia dersom abonnementet skal aktiveres eller endres.'}
                            </p>
                            {canChangePlan && (
                                <button
                                    onClick={openPlanChangeModal}
                                    className="rounded-lg border border-blue-200 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-50"
                                >
                                    {planChangeText.button ?? 'Endre plan'}
                                </button>
                            )}
                        </div>
                    )}
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="flex items-center gap-2">
                        <h2 className="text-base font-semibold text-slate-900">
                            {servicesText.heading ?? 'Tjenester i Procynia'}
                        </h2>
                        <InfoHint size="sm" label="Vis forklaring for Tjenester" text={tb.hint_procynia_services} />
                    </div>
                    <p className="mt-2 text-sm leading-6 text-slate-600">
                        {servicesText.help ?? 'Dette viser aktive tjenester og faktureringsgrunnlag i Procynia. Fakturaer og betalingsstatus håndteres via Stripe.'}
                    </p>

                    {billingLines.length > 0 ? (
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-slate-100 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                                        <th className="pb-2 pr-4">{servicesTableText.service ?? 'Tjeneste'}</th>
                                        <th className="pb-2 pr-4">{servicesTableText.description ?? 'Beskrivelse'}</th>
                                        <th className="pb-2 pr-4">{servicesTableText.quantity ?? 'Antall'}</th>
                                        <th className="pb-2 pr-4">{servicesTableText.user ?? 'Bruker'}</th>
                                        <th className="pb-2 pr-4">{servicesTableText.type ?? 'Type'}</th>
                                        <th className="pb-2 pr-4">{servicesTableText.status ?? 'Status'}</th>
                                        <th className="pb-2">{servicesTableText.source ?? 'Kilde'}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {billingLines.map((line) => (
                                        <tr key={line.id}>
                                            <td className="py-3 pr-4 font-medium text-slate-900">
                                                {resolveBillingLineLabel(line)}
                                            </td>
                                            <td className="py-3 pr-4 text-slate-700">
                                                {line.description ?? '—'}
                                            </td>
                                            <td className="py-3 pr-4 text-slate-700">
                                                {line.quantity}
                                            </td>
                                            <td className="py-3 pr-4 text-slate-700">
                                                {line.user_name ?? (line.user_id ? '—' : (servicesTableText.customer ?? 'Kunde'))}
                                            </td>
                                            <td className="py-3 pr-4">
                                                <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">
                                                    {resolveLineTypeLabel(line.interval)}
                                                </span>
                                            </td>
                                            <td className="py-3 pr-4">
                                                <StatusBadge status={line.status} label={resolveStatusLabel(line.status)} />
                                            </td>
                                            <td className="py-3">
                                                <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">
                                                    {resolveSourceLabel(line.source)}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <p className="mt-4 text-sm leading-6 text-slate-600">
                            {servicesText.empty ?? 'Ingen interne Procynia-billing-linjer registrert. Interne linjer beskriver tjenester, tilgang eller faktureringsgrunnlag, men er ikke økonomisk fasit.'}
                        </p>
                    )}
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="flex items-center gap-2">
                        <h2 className="text-base font-semibold text-slate-900">
                            {levelsText.heading ?? 'Brukernivåer i Procynia'}
                        </h2>
                        <InfoHint size="sm" label="Vis forklaring for Brukernivåer" text={tb.hint_procynia_levels} />
                    </div>
                    <p className="mt-2 text-sm leading-6 text-slate-600">
                        {levelsText.help ?? 'Dette viser hvilke brukere som har aktive produkt- eller KI-nivåer i Procynia.'}
                    </p>

                    {serviceLevels.length > 0 ? (
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-slate-100 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                                        <th className="pb-2 pr-4">{levelsTableText.user ?? 'Bruker'}</th>
                                        <th className="pb-2 pr-4">{levelsTableText.service ?? 'Tjeneste'}</th>
                                        <th className="pb-2 pr-4">{levelsTableText.level ?? 'Nivå'}</th>
                                        <th className="pb-2 pr-4">{levelsTableText.status ?? 'Status'}</th>
                                        <th className="pb-2">{levelsTableText.assigned_by ?? 'Tildelt av'}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {serviceLevels.map((level) => (
                                        <tr key={level.id}>
                                            <td className="py-3 pr-4 font-medium text-slate-900">
                                                {level.user_name ?? '—'}
                                            </td>
                                            <td className="py-3 pr-4 text-slate-700">
                                                {level.billing_price ?? level.billing_product ?? '—'}
                                            </td>
                                            <td className="py-3 pr-4 text-slate-700">
                                                {resolveServiceLevelLabel(level.level_key)}
                                            </td>
                                            <td className="py-3 pr-4">
                                                <StatusBadge status={level.status} label={resolveStatusLabel(level.status)} />
                                            </td>
                                            <td className="py-3 text-slate-700">
                                                {level.assigned_by ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <p className="mt-4 text-sm leading-6 text-slate-600">
                            {levelsText.empty ?? 'Ingen Procynia-brukernivåer registrert for kunden.'}
                        </p>
                    )}
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="text-base font-semibold text-slate-900">
                        {invoicesText.heading ?? 'Fakturaer fra Stripe'}
                    </h2>
                    <p className="mt-2 text-sm leading-6 text-slate-600">
                        {invoicesText.help ?? 'Fakturaer og PDF-er vises her når de er opprettet i Stripe.'}
                    </p>

                    {sortedInvoices.length > 0 ? (
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-slate-100 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                                        <th className="pb-2 pr-4">{invoicesTableText.number ?? 'Fakturanummer'}</th>
                                        <th className="pb-2 pr-4">{invoicesTableText.date ?? 'Dato'}</th>
                                        <th className="pb-2 pr-4">{invoicesTableText.amount ?? 'Beløp'}</th>
                                        <th className="pb-2 pr-4">{invoicesTableText.status ?? 'Status'}</th>
                                        <th className="pb-2">{invoicesTableText.download ?? 'PDF'}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {sortedInvoices.map((invoice) => (
                                        <tr key={invoice.id}>
                                            <td className="py-3 pr-4 font-mono text-xs text-slate-700">
                                                {invoice.number ?? '—'}
                                            </td>
                                            <td className="py-3 pr-4 text-slate-700">{formatDate(invoice.date)}</td>
                                            <td className="py-3 pr-4 text-slate-700">
                                                {invoice.amount_due} {invoice.currency}
                                            </td>
                                            <td className="py-3 pr-4">
                                                <StatusBadge status={invoice.status} label={resolveStatusLabel(invoice.status)} />
                                            </td>
                                            <td className="py-3">
                                                {invoice.invoice_pdf || invoice.hosted_invoice_url ? (
                                                    <a
                                                        href={invoice.invoice_pdf ?? invoice.hosted_invoice_url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="text-sm font-medium text-blue-600 hover:underline"
                                                    >
                                                        PDF
                                                    </a>
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <p className="mt-4 text-sm leading-6 text-slate-600">
                            {invoicesText.empty ?? 'Ingen Stripe-fakturaer funnet. Fakturaer og PDF-er vises her når de er opprettet i Stripe.'}
                        </p>
                    )}
                </section>
            </div>

            {planChangeOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 px-4 py-6">
                    <div className="max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
                        <div className="space-y-2">
                            <h3 className="text-lg font-semibold text-slate-900">
                                {planChangeStep === 'confirm'
                                    ? (planChangeText.confirm ?? 'Bekreft planendring')
                                    : (planChangeText.heading ?? 'Endre plan')}
                            </h3>
                            <p className="text-sm leading-6 text-slate-600">
                                {planChangeStep === 'confirm'
                                    ? (planChangeText.confirm_intro ?? 'Du er i ferd med å endre abonnementet.')
                                    : (planChangeText.description ?? 'Velg en ny plan. Fakturering håndteres via Stripe.')}
                            </p>
                            {planChangeStep === 'confirm' && (
                                <p className="text-sm leading-6 text-slate-600">
                                    {planChangeText.confirm_note ?? 'Endringen behandles via Stripe og kan påvirke videre fakturering.'}
                                </p>
                            )}
                        </div>

                        {planChangeError && (
                            <div className="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                                {planChangeError}
                            </div>
                        )}

                        {planChangeStep === 'selection' ? (
                            <>
                                <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                                        {planChangeText.current_plan ?? 'Nåværende plan'}
                                    </div>
                                    <div className="mt-2 text-sm font-semibold text-slate-900">
                                        {currentPlanLabel}
                                    </div>
                                    <div className="mt-1 text-sm text-slate-600">
                                        {planChangeText.current_interval ?? 'Nåværende faktureringsintervall'}: {currentIntervalLabel}
                                    </div>
                                </div>

                                <div className="mt-5 grid gap-5 lg:grid-cols-2">
                                    <div>
                                        <label className="block text-sm font-medium text-slate-700">
                                            {planChangeText.select_plan ?? 'Velg ny plan'}
                                        </label>
                                        <select
                                            value={selectedPlanKey}
                                            onChange={(event) => handlePlanSelection(event.target.value)}
                                            className="mt-2 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                                        >
                                            {availablePlans.map((plan) => (
                                                <option key={plan.key} value={plan.key}>
                                                    {buildPlanLabel(plan)}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.plan && (
                                            <p className="mt-2 text-sm text-red-600">{errors.plan}</p>
                                        )}
                                    </div>

                                    <div>
                                        <div className="block text-sm font-medium text-slate-700">
                                            {planChangeText.select_interval ?? 'Velg faktureringsintervall'}
                                        </div>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {selectedPlanIntervals.map((interval) => (
                                                <button
                                                    key={interval.interval}
                                                    type="button"
                                                    onClick={() => setSelectedInterval(normalizeKey(interval.interval))}
                                                    className={classNames(
                                                        'rounded-full border px-3 py-2 text-sm font-medium transition',
                                                        normalizeKey(interval.interval) === selectedInterval
                                                            ? 'border-blue-300 bg-blue-50 text-blue-800'
                                                            : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                                                    )}
                                                >
                                                    <span className="block">{interval.label}</span>
                                                    <span className="block text-xs font-normal text-slate-500">
                                                        {formatPlanIntervalPrice(interval.price_nok, interval.interval)}
                                                    </span>
                                                </button>
                                            ))}
                                        </div>
                                        {errors.interval && (
                                            <p className="mt-2 text-sm text-red-600">{errors.interval}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="mt-6 flex flex-wrap justify-end gap-3">
                                    <button
                                        onClick={closePlanChangeModal}
                                        className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                    >
                                        {planChangeText.cancel ?? 'Avbryt'}
                                    </button>
                                    <button
                                        onClick={openPlanChangeConfirmation}
                                        disabled={!canConfirmPlanChange}
                                        className={classNames(
                                            'rounded-lg px-4 py-2 text-sm font-medium text-white',
                                            canConfirmPlanChange ? 'bg-blue-600 hover:bg-blue-700' : 'cursor-not-allowed bg-slate-300'
                                        )}
                                    >
                                        {planChangeText.next_step ?? 'Fortsett'}
                                    </button>
                                </div>
                            </>
                        ) : (
                            <>
                                <div className="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">
                                    <p className="font-medium">
                                        {planChangeText.confirm_intro ?? 'Du er i ferd med å endre abonnementet.'}
                                    </p>
                                    <p className="mt-2">
                                        {planChangeText.confirm_note ?? 'Endringen behandles via Stripe og kan påvirke videre fakturering.'}
                                    </p>
                                </div>

                                {selectedPlanSummary && (
                                    <div className="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-900">
                                        <div className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                                            {planChangeText.summary ?? 'Oppsummering'}
                                        </div>
                                        <div className="mt-3 grid gap-3 sm:grid-cols-[1fr_auto]">
                                            <div className="text-slate-500">
                                                {planChangeText.from_plan ?? 'Fra plan'}
                                            </div>
                                            <div className="font-semibold text-slate-900">
                                                {currentPlanLabel}
                                            </div>
                                            <div className="text-slate-500">
                                                {planChangeText.to_plan ?? 'Til plan'}
                                            </div>
                                            <div className="font-semibold text-slate-900">
                                                {selectedPlanSummary.name}
                                            </div>
                                            <div className="text-slate-500">
                                                {planChangeText.billing_interval ?? 'Faktureringsintervall'}
                                            </div>
                                            <div className="font-semibold text-slate-900">
                                                {selectedPlanSummary.intervalLabel}
                                            </div>
                                            {selectedPlanSummary.priceLabel && (
                                                <>
                                                    <div className="text-slate-500">
                                                        {planChangeText.price ?? 'Pris'}
                                                    </div>
                                                    <div className="font-semibold text-slate-900">
                                                        {selectedPlanSummary.priceLabel}
                                                    </div>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                )}

                                <div className="mt-6 flex flex-wrap justify-end gap-3">
                                    <button
                                        onClick={() => setPlanChangeStep('selection')}
                                        className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                    >
                                        {planChangeText.back ?? 'Gå tilbake'}
                                    </button>
                                    <button
                                        onClick={handlePlanChangeSubmit}
                                        disabled={!canConfirmPlanChange}
                                        className={classNames(
                                            'rounded-lg px-4 py-2 text-sm font-medium text-white',
                                            canConfirmPlanChange ? 'bg-blue-600 hover:bg-blue-700' : 'cursor-not-allowed bg-slate-300'
                                        )}
                                    >
                                        {planChangeText.confirm ?? 'Bekreft planendring'}
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            )}

            <ConfirmDialog
                isOpen={confirmCancel}
                title={tb.cancel_confirm_title ?? 'Si opp abonnement'}
                message={tb.cancel_confirm_message ?? 'Abonnementet avsluttes automatisk ved slutten av inneværende periode. Du beholder tilgang til da.'}
                onConfirm={handleCancel}
                onCancel={() => setConfirmCancel(false)}
                confirmLabel={tb.cancel ?? 'Si opp'}
                cancelLabel={tb.cancel_button ?? 'Avbryt'}
                danger
            />

            <ConfirmDialog
                isOpen={confirmResume}
                title={tb.resume_confirm_title ?? 'Behold abonnement'}
                message={tb.resume_confirm_message ?? 'Oppsigelsen trekkes tilbake og abonnementet fortsetter som normalt.'}
                onConfirm={handleResume}
                onCancel={() => setConfirmResume(false)}
                confirmLabel={tb.resume ?? 'Behold abonnement'}
                cancelLabel={tb.cancel_button ?? 'Avbryt'}
            />
        </CustomerAppLayout>
    );
}
