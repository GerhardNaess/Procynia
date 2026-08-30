import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import AiQuotaCard from '../../../Components/App/AiQuotaCard';
import AlertBox from '../../../Components/App/AlertBox';
import InfoHint from '../../../Components/App/InfoHint';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import StatusBadge from '../../../Components/App/StatusBadge';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

const STATUS_BADGE_TONES = {
    active: 'green',
    trialing: 'blue',
    past_due: 'amber',
    unpaid: 'amber',
    open: 'amber',
    pending: 'amber',
    draft: 'slate',
    paid: 'green',
    cancelled: 'slate',
    canceled: 'slate',
    void: 'slate',
    incomplete: 'amber',
    incomplete_expired: 'slate',
    uncollectible: 'slate',
    inactive: 'slate',
    default: 'slate',
};

function normalizeKey(value) {
    return String(value ?? '').toLowerCase();
}

function resolveLabel(value, labels, fallback) {
    const key = normalizeKey(value);
    return labels?.[key] ?? fallback;
}


function SummaryCard({ label, value, hint, hintLabel }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex items-center gap-1.5 text-base font-semibold uppercase tracking-[0.16em] text-slate-600">
                <span>{label}</span>
                {hint && (
                    <InfoHint
                        size="sm"
                        label={hintLabel ?? `Vis forklaring for ${label}`}
                        text={hint}
                    />
                )}
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
                <p className="mt-2 text-base leading-6 text-slate-600">{message}</p>
                <div className="mt-5 flex justify-end gap-3">
                    <button
                        onClick={onCancel}
                        className="rounded-lg border border-slate-200 px-4 py-2 text-base font-medium text-slate-700 hover:bg-slate-50"
                    >
                        {cancelLabel}
                    </button>
                    <button
                        onClick={onConfirm}
                        className={classNames(
                            'rounded-lg px-4 py-2 text-base font-medium text-white',
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
        ai_quota: aiQuota = null,
        translations = {},
        errors = {},
        flash,
        locale = 'nb-NO',
    } = page;

    const tb = translations.billing ?? {};
    const aiQuotaText = translations.ai_quota ?? {};
    const planChangeText = tb.plan_change ?? {};
    const summaryText = tb.summary ?? {};
    const alertText = tb.alerts ?? {};
    const subscriptionText = tb.stripe_subscription ?? {};
    const servicesText = tb.procynia_services ?? {};
    const servicesTableText = servicesText.table ?? {};
    const invoicesText = tb.invoices ?? {};
    const invoicesTableText = invoicesText.table ?? {};
    const statusLabels = tb.status_labels ?? {};
    const lineTypeLabels = tb.billing_line_type_labels ?? {};
    const billingLineStatusLabels = tb.billing_line_status_labels ?? {};
    const summaryHints = tb.summary_hints ?? {};

    const [confirmCancel, setConfirmCancel] = useState(false);
    const [confirmResume, setConfirmResume] = useState(false);
    const [planChangeOpen, setPlanChangeOpen] = useState(false);
    const [planChangeStep, setPlanChangeStep] = useState('selection');
    const [selectedPlanKey, setSelectedPlanKey] = useState('');
    const [selectedInterval, setSelectedInterval] = useState('monthly');

    const sortedInvoices = [...invoices].sort((left, right) => (right.date_sort ?? 0) - (left.date_sort ?? 0));
    const hasRegisteredSubscription = Boolean(subscription?.plan || subscription?.plan_label);
    const hasProcyniaServices = billingLines.length > 0;
    const currentPlanKey = normalizeKey(subscription?.plan ?? customerPlan.plan);
    const currentPlanLabel = subscription?.plan_label
        ?? customerPlan.plan_label
        ?? planChangeText.current_plan
        ?? 'Nåværende abonnement';
    const currentIntervalKey = normalizeKey(subscription?.billing_interval ?? customerPlan.billing_interval ?? 'monthly');
    const currentIntervalLabel = currentIntervalKey === 'yearly'
        ? (planChangeText.yearly ?? 'Årlig')
        : (planChangeText.monthly ?? 'Månedlig');
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
    const resolveLineTypeLabel = (interval) => (normalizeKey(interval) === 'one_time'
        ? (lineTypeLabels.one_time ?? 'Engangstjeneste')
        : (lineTypeLabels.recurring ?? 'Løpende'));
    const resolveBillingLineStatusLabel = (line) => {
        if (normalizeKey(line.interval) !== 'one_time') {
            return resolveStatusLabel(line.status);
        }

        if (normalizeKey(line.status) === 'paid') {
            return billingLineStatusLabels.paid ?? statusLabels.paid ?? 'Betalt';
        }

        if (line.stripe_invoice_id) {
            return billingLineStatusLabels.invoiced ?? 'Fakturert';
        }

        return billingLineStatusLabels.registered ?? 'Registrert';
    };
    const resolveBillingLineStatusTone = (line) => {
        if (normalizeKey(line.interval) !== 'one_time') {
            return STATUS_BADGE_TONES[normalizeKey(line.status)] ?? 'slate';
        }

        if (normalizeKey(line.status) === 'paid') {
            return STATUS_BADGE_TONES.paid ?? 'green';
        }

        return 'slate';
    };
    const resolveBillingLineLabel = (line) => line.billing_price
        ?? line.billing_product
        ?? summaryText.not_available
        ?? 'Ikke tilgjengelig';

    const formatCount = (count) => {
        if (count === 0) {
            return summaryText.none ?? 'Ingen';
        }

        const template = count === 1 ? summaryText.active_one : summaryText.active_many;
        return (template ?? ':count aktive').replace(':count', String(count));
    };

    const formatMoney = (value) => new Intl.NumberFormat(locale).format(Number(value ?? 0));

    const formatPlanIntervalPrice = (value, interval) => {
        if (value === null || value === undefined) {
            return summaryText.not_available ?? 'Ikke tilgjengelig';
        }

        const intervalUnit = normalizeKey(interval) === 'yearly'
            ? (planChangeText.yearly_unit ?? 'year')
            : (planChangeText.monthly_unit ?? 'month');

        return `${formatMoney(value)} kr/${intervalUnit}`;
    };

    const isOutstandingInvoice = (status) => new Set(['open', 'unpaid', 'past_due', 'incomplete', 'incomplete_expired'])
        .has(normalizeKey(status));

    const outstandingInvoices = sortedInvoices.filter((invoice) => isOutstandingInvoice(invoice.status));
    const outstandingAmount = outstandingInvoices.reduce((sum, invoice) => sum + Number(invoice.amount_due ?? 0), 0);
    const outstandingCurrency = (outstandingInvoices[0]?.currency ?? sortedInvoices[0]?.currency ?? 'NOK').toUpperCase();
    const outstandingAmountLabel = outstandingAmount > 0
        ? new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: outstandingCurrency,
            maximumFractionDigits: 0,
        }).format(outstandingAmount)
        : null;

    const buildPlanLabel = (plan) => {
        const intervalPrices = (plan.intervals ?? [])
            .map((interval) => formatPlanIntervalPrice(interval.price_nok, interval.interval))
            .join(' · ');
        const currentBadge = plan.is_current ? ` (${planChangeText.current_badge ?? 'Nåværende'})` : '';

        return `${plan.name}${intervalPrices ? ` — ${intervalPrices}` : ''}${currentBadge}`;
    };

    const subscriptionSummaryValue = `${currentPlanLabel}${currentIntervalLabel ? ` · ${currentIntervalLabel}` : ''}`;

    const procyniaServicesValue = formatCount(billingLines.length);

    const showAddonsWithoutSubscriptionWarning = !hasRegisteredSubscription && hasProcyniaServices;
    const currentPlanOption = availablePlans.find((plan) => normalizeKey(plan.key) === currentPlanKey) ?? null;
    const currentPlanIntervalOption = currentPlanOption?.intervals?.find((option) => normalizeKey(option.interval) === currentIntervalKey)
        ?? currentPlanOption?.intervals?.[0]
        ?? null;
    const currentPlanSummary = currentPlanOption ? {
        label: planChangeText.current_plan ?? 'Nåværende abonnement',
        name: currentPlanLabel,
        intervalLabel: currentIntervalLabel,
        priceLabel: currentPlanIntervalOption?.price_nok !== undefined && currentPlanIntervalOption?.price_nok !== null
            ? formatPlanIntervalPrice(currentPlanIntervalOption.price_nok, currentPlanIntervalOption.interval)
            : null,
        includedUsers: currentPlanOption.included_users ?? null,
        includedAiCredits: currentPlanOption.included_ai_credits ?? null,
    } : null;
    const selectedPlanSummary = selectedPlan ? {
        label: planChangeText.selected_plan ?? 'Valgt abonnement',
        name: selectedPlan.name,
        intervalLabel: selectedIntervalOption?.label
            ?? (normalizeKey(selectedInterval) === 'yearly'
                ? (planChangeText.yearly ?? 'Årlig')
                : (planChangeText.monthly ?? 'Månedlig')),
        priceLabel: selectedIntervalOption
            ? formatPlanIntervalPrice(selectedIntervalOption.price_nok, selectedIntervalOption.interval)
            : null,
        includedUsers: selectedPlan.included_users ?? null,
        includedAiCredits: selectedPlan.included_ai_credits ?? null,
    } : null;
    const isSamePlanSelection = normalizeKey(selectedPlanKey) === currentPlanKey
        && normalizeKey(selectedInterval) === currentIntervalKey;
    const planPreviewSummary = isSamePlanSelection
        ? (currentPlanSummary ?? selectedPlanSummary)
        : (selectedPlanSummary ?? currentPlanSummary);
    const planPreviewIntervalTitle = isSamePlanSelection
        ? (planChangeText.current_interval ?? 'Nåværende faktureringsperiode')
        : (planChangeText.selected_interval ?? 'Valgt intervall');
    const planPreviewIntervalLabel = isSamePlanSelection
        ? currentIntervalLabel
        : (selectedPlanSummary?.intervalLabel ?? currentIntervalLabel);
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
        <CustomerAppLayout title={tb.title ?? 'Abonnement'}>
            <div className="mx-auto max-w-6xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
                {flash?.success && (
                    <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-base leading-6 text-green-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-base leading-6 text-red-800">
                        {flash.error}
                    </div>
                )}

                <header className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-900">
                            {tb.title ?? 'Abonnement'}
                        </h1>
                        <PageHelpButton
                            buttonLabel={tb.page_help_button ?? 'Hjelp'}
                            title={tb.page_help_title ?? 'Om abonnement, tilleggstjenester og fakturaer'}
                            intro={tb.page_help_intro ?? 'Siden gir deg oversikt over abonnement, tilleggstjenester og fakturahistorikk.'}
                            sections={[
                                {
                                    title: tb.page_help_section_overview ?? 'Hva du finner her',
                                    items: [
                                        {
                                            title: tb.page_help_item_subscription_title ?? 'Abonnement',
                                            text: tb.page_help_item_subscription_text ?? 'Viser plan, periode, inkluderte brukere og AI-kapasitet.',
                                        },
                                        {
                                            title: tb.page_help_item_services_title ?? 'Tilleggstjenester',
                                            text: tb.page_help_item_services_text ?? 'Viser tilleggstjenester som er knyttet til kunden.',
                                        },
                                        {
                                            title: tb.page_help_item_invoices_title ?? 'Fakturaer og betalinger',
                                            text: tb.page_help_item_invoices_text ?? 'Viser utestående beløp, fakturahistorikk og eventuelle PDF-er.',
                                        },
                                    ],
                                },
                            ]}
                        />
                    </div>
                    <p className="max-w-4xl text-base leading-6 text-slate-600">
                        {tb.subtitle ?? 'Oversikt over abonnement, tilleggstjenester og fakturaer.'}
                    </p>
                    <p className="max-w-4xl text-base leading-6 text-slate-600">
                        {tb.intro ?? 'Her ser du kundens abonnement, tilleggstjenester og fakturering. Fakturaer og PDF-er vises når de finnes.'}
                    </p>
                </header>

                <section className="grid gap-4 md:grid-cols-2">
                    <SummaryCard
                        label={summaryText.subscription ?? 'Abonnement'}
                        value={subscriptionSummaryValue}
                        hint={summaryHints.subscription}
                    />
                    <SummaryCard
                        label={summaryText.procynia_services ?? 'Tilleggstjenester'}
                        value={procyniaServicesValue}
                        hint={summaryHints.addons}
                    />
                </section>

                <AiQuotaCard quota={aiQuota} texts={aiQuotaText} locale={locale} />

                {showAddonsWithoutSubscriptionWarning && (
                    <AlertBox>
                        {alertText.addons_without_subscription ?? 'Kontoen har aktive tillegg, men ingen aktivt abonnement. Kontakt Procynia dersom abonnementet skal aktiveres eller endres.'}
                    </AlertBox>
                )}

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="flex items-center gap-2">
                        <h2 className="text-base font-semibold text-slate-900">
                            {subscriptionText.heading ?? 'Abonnement'}
                        </h2>
                        <InfoHint size="sm" label="Vis forklaring for abonnement" text={tb.hint_subscription} />
                    </div>

                    <div className="mt-4 space-y-4">
                        <p className="text-base leading-6 text-slate-600">
                            {hasRegisteredSubscription
                                ? (subscriptionText.registered ?? 'Abonnementet er registrert.')
                                : (subscriptionText.empty ?? 'Ingen aktivt abonnement er registrert.')}
                        </p>

                        <dl className="grid grid-cols-1 gap-x-8 gap-y-4 text-base md:grid-cols-2">
                            <dt className="text-slate-600">{subscriptionText.plan ?? 'Abonnement'}</dt>
                            <dd className="font-medium text-slate-900">{currentPlanLabel}</dd>

                            <dt className="text-slate-600">{subscriptionText.interval ?? 'Intervall'}</dt>
                            <dd className="font-medium text-slate-900">{currentIntervalLabel}</dd>

                            {subscription?.included_users !== undefined && subscription?.included_users !== null && (
                                <>
                                    <dt className="text-slate-600">{subscriptionText.included_users ?? 'Inkluderte brukere'}</dt>
                                    <dd className="font-medium text-slate-900">{subscription.included_users}</dd>
                                </>
                            )}

                            {subscription?.included_ai_credits !== undefined && subscription?.included_ai_credits !== null && (
                                <>
                                    <dt className="flex items-center gap-1.5 text-slate-600">
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
                                    className="rounded-lg border border-blue-200 px-4 py-2 text-base font-medium text-blue-700 hover:bg-blue-50"
                                >
                                    {planChangeText.button ?? 'Endre abonnement'}
                                </button>
                            )}
                            {subscription?.status === 'active' && !subscription.cancel_at_period_end && (
                                <button
                                    onClick={() => setConfirmCancel(true)}
                                    className="rounded-lg border border-red-200 px-4 py-2 text-base font-medium text-red-700 hover:bg-red-50"
                                >
                                    {tb.cancel ?? 'Si opp abonnement'}
                                </button>
                            )}
                            {subscription?.cancel_at_period_end && (
                                <button
                                    onClick={() => setConfirmResume(true)}
                                    className="rounded-lg border border-green-200 px-4 py-2 text-base font-medium text-green-700 hover:bg-green-50"
                                >
                                    {tb.resume ?? 'Gjenoppta abonnement'}
                                </button>
                            )}
                        </div>
                    </div>
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="flex items-center gap-2">
                        <h2 className="text-base font-semibold text-slate-900">
                            {servicesText.heading ?? 'Tilleggstjenester'}
                        </h2>
                        <InfoHint size="sm" label="Vis forklaring for tilleggstjenester" text={tb.hint_procynia_services} />
                    </div>

                    {billingLines.length > 0 ? (
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-base">
                                <thead>
                                    <tr className="border-b border-slate-100 text-left text-base font-medium uppercase tracking-wide text-slate-600">
                                        <th className="pb-2 pr-4">{servicesTableText.service ?? 'Tjeneste'}</th>
                                        <th className="pb-2 pr-4">{servicesTableText.type ?? 'Type'}</th>
                                        <th className="pb-2 pr-4">{servicesTableText.status ?? 'Status'}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {billingLines.map((line) => (
                                        <tr key={line.id}>
                                            <td className="py-3 pr-4 font-medium text-slate-900">
                                                {resolveBillingLineLabel(line)}
                                            </td>
                                            <td className="py-3 pr-4">
                                                <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-base font-medium leading-6 text-slate-700">
                                                    {resolveLineTypeLabel(line.interval)}
                                                </span>
                                            </td>
                                            <td className="py-3 pr-4">
                                                <StatusBadge tone={resolveBillingLineStatusTone(line)}>
                                                    {resolveBillingLineStatusLabel(line)}
                                                </StatusBadge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <p className="mt-4 text-base leading-6 text-slate-600">
                            {servicesText.empty ?? 'Ingen tilleggstjenester registrert. Tilleggstjenester beskriver ekstra tjenester som er knyttet til abonnementet, men er ikke økonomisk fasit.'}
                        </p>
                    )}
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="text-base font-semibold text-slate-900">
                        {invoicesText.heading ?? 'Fakturaer og betalinger'}
                    </h2>
                    <p className="mt-2 text-base leading-6 text-slate-600">
                        {invoicesText.help ?? 'Her finner du utestående beløp, fakturahistorikk og eventuelle PDF-er.'}
                    </p>

                    <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div className="text-base font-semibold uppercase tracking-[0.16em] text-slate-600">
                            {invoicesText.outstanding_label ?? 'Utestående beløp'}
                        </div>
                        <div className="mt-2 text-base font-semibold text-slate-900">
                            {outstandingAmountLabel ?? (invoicesText.no_outstanding ?? 'Ingen utestående beløp registrert.')}
                        </div>
                    </div>

                    {sortedInvoices.length > 0 ? (
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-base">
                                <thead>
                                    <tr className="border-b border-slate-100 text-left text-base font-medium uppercase tracking-wide text-slate-600">
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
                                            <td className="py-3 pr-4 font-mono text-base text-slate-700">
                                                {invoice.number ?? '—'}
                                            </td>
                                            <td className="py-3 pr-4 text-slate-700">{formatDate(invoice.date)}</td>
                                            <td className="py-3 pr-4 text-slate-700">
                                                {invoice.amount_due} {invoice.currency}
                                            </td>
                                            <td className="py-3 pr-4">
                                                <StatusBadge tone={STATUS_BADGE_TONES[normalizeKey(invoice.status)] ?? 'slate'}>{resolveStatusLabel(invoice.status)}</StatusBadge>
                                            </td>
                                            <td className="py-3">
                                                {invoice.invoice_pdf || invoice.hosted_invoice_url ? (
                                                    <a
                                                        href={invoice.invoice_pdf ?? invoice.hosted_invoice_url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="text-base font-medium text-blue-700 hover:underline"
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
                        <p className="mt-4 text-base leading-6 text-slate-600">
                            {invoicesText.empty ?? 'Ingen fakturaer tilgjengelig.'}
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
                                    ? (planChangeText.confirm ?? 'Bekreft abonnementsendring')
                                    : (planChangeText.heading ?? 'Endre abonnement')}
                            </h3>
                            <p className="text-base leading-6 text-slate-600">
                                {planChangeStep === 'confirm'
                                    ? (planChangeText.confirm_intro ?? 'Du er i ferd med å endre abonnementet.')
                                    : (planChangeText.description ?? 'Velg et nytt abonnement.')}
                            </p>
                        </div>

                        {planChangeError && (
                            <div className="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-base leading-6 text-red-700">
                                {planChangeError}
                            </div>
                        )}

                        {planChangeStep === 'selection' ? (
                            <>
                                <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div className="text-base font-semibold uppercase tracking-[0.16em] text-slate-600">
                                        {planPreviewSummary?.label ?? planChangeText.current_plan ?? 'Nåværende abonnement'}
                                    </div>
                                    <div className="mt-2 text-base font-semibold text-slate-900">
                                        {planPreviewSummary?.name ?? currentPlanLabel}
                                    </div>
                                    <div className="mt-1 text-base text-slate-600">
                                        {planPreviewIntervalTitle}: {planPreviewIntervalLabel}
                                    </div>
                                    {planPreviewSummary && (
                                        <dl className="mt-4 grid gap-x-8 gap-y-3 text-base md:grid-cols-2">
                                            {planPreviewSummary.priceLabel && (
                                                <div>
                                                    <dt className="text-slate-600">
                                                        {planChangeText.price ?? 'Pris'}
                                                    </dt>
                                                    <dd className="mt-1 font-medium text-slate-900">
                                                        {planPreviewSummary.priceLabel}
                                                    </dd>
                                                </div>
                                            )}
                                            {planPreviewSummary.includedUsers !== null && planPreviewSummary.includedUsers !== undefined && (
                                                <div>
                                                    <dt className="text-slate-600">
                                                        {subscriptionText.included_users ?? 'Inkluderte brukere'}
                                                    </dt>
                                                    <dd className="mt-1 font-medium text-slate-900">
                                                        {planPreviewSummary.includedUsers}
                                                    </dd>
                                                </div>
                                            )}
                                            {planPreviewSummary.includedAiCredits !== null && planPreviewSummary.includedAiCredits !== undefined && (
                                                <div>
                                                    <dt className="text-slate-600">
                                                        {subscriptionText.included_ai_credits ?? 'Inkluderte KI-tilbud'}
                                                    </dt>
                                                    <dd className="mt-1 font-medium text-slate-900">
                                                        {planPreviewSummary.includedAiCredits}
                                                    </dd>
                                                </div>
                                            )}
                                        </dl>
                                    )}
                                </div>

                                <div className="mt-5 grid gap-5 lg:grid-cols-2">
                                    <div>
                                        <label className="block text-base font-medium text-slate-700">
                                            {planChangeText.select_plan ?? 'Velg nytt abonnement'}
                                        </label>
                                        <select
                                            value={selectedPlanKey}
                                            onChange={(event) => handlePlanSelection(event.target.value)}
                                            className="mt-2 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                                        >
                                            {availablePlans.map((plan) => (
                                                <option key={plan.key} value={plan.key}>
                                                    {buildPlanLabel(plan)}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.plan && (
                                            <p className="mt-2 text-base text-red-700">{errors.plan}</p>
                                        )}
                                    </div>

                                    <div>
                                        <div className="block text-base font-medium text-slate-700">
                                            {planChangeText.select_interval ?? 'Velg faktureringsperiode'}
                                        </div>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {selectedPlanIntervals.map((interval) => (
                                                <button
                                                    key={interval.interval}
                                                    type="button"
                                                    onClick={() => setSelectedInterval(normalizeKey(interval.interval))}
                                                    className={classNames(
                                                        'rounded-full border px-3 py-2 text-base font-medium transition',
                                                        normalizeKey(interval.interval) === selectedInterval
                                                            ? 'border-blue-300 bg-blue-50 text-blue-800'
                                                            : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                                                    )}
                                                >
                                                    <span className="block">{interval.label}</span>
                                                    <span className="block text-base font-normal text-slate-600">
                                                        {formatPlanIntervalPrice(interval.price_nok, interval.interval)}
                                                    </span>
                                                </button>
                                            ))}
                                        </div>
                                        {errors.interval && (
                                            <p className="mt-2 text-base text-red-700">{errors.interval}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="mt-6 flex flex-wrap justify-end gap-3">
                                    <button
                                        onClick={closePlanChangeModal}
                                        className="rounded-lg border border-slate-200 px-4 py-2 text-base font-medium text-slate-700 hover:bg-slate-50"
                                    >
                                        {planChangeText.cancel ?? 'Avbryt'}
                                    </button>
                                    <button
                                        onClick={openPlanChangeConfirmation}
                                        disabled={!canConfirmPlanChange}
                                        className={classNames(
                                            'rounded-lg px-4 py-2 text-base font-medium text-white',
                                            canConfirmPlanChange ? 'bg-blue-600 hover:bg-blue-700' : 'cursor-not-allowed bg-slate-300'
                                        )}
                                    >
                                        {planChangeText.next_step ?? 'Fortsett'}
                                    </button>
                                </div>
                            </>
                        ) : (
                            <>
                                <AlertBox className="mt-4">
                                    <p className="font-medium leading-6">
                                        {planChangeText.confirm_note ?? 'Når du bekrefter, oppdateres abonnementet. Det kan endre hvilke tilleggstjenester og tilganger som er aktive.'}
                                    </p>
                                </AlertBox>

                                {selectedPlanSummary && (
                                    <div className="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-base text-slate-900">
                                        <div className="text-base font-semibold uppercase tracking-[0.16em] text-slate-600">
                                            {planChangeText.summary ?? 'Oppsummering'}
                                        </div>
                                        <div className="mt-3 grid gap-3 sm:grid-cols-[1fr_auto]">
                                            <div className="text-slate-600">
                                                {planChangeText.from_plan ?? 'Fra abonnement'}
                                            </div>
                                            <div className="font-semibold text-slate-900">
                                                {currentPlanLabel}
                                            </div>
                                            <div className="text-slate-600">
                                                {planChangeText.to_plan ?? 'Til abonnement'}
                                            </div>
                                            <div className="font-semibold text-slate-900">
                                                {selectedPlanSummary.name}
                                            </div>
                                            <div className="text-slate-600">
                                                {planChangeText.billing_interval ?? 'Intervall'}
                                            </div>
                                            <div className="font-semibold text-slate-900">
                                                {selectedPlanSummary.intervalLabel}
                                            </div>
                                            {selectedPlanSummary.priceLabel && (
                                                <>
                                                    <div className="text-slate-600">
                                                        {planChangeText.price ?? 'Pris'}
                                                    </div>
                                                    <div className="font-semibold text-slate-900">
                                                        {selectedPlanSummary.priceLabel}
                                                    </div>
                                                </>
                                            )}
                                            {selectedPlanSummary.includedUsers !== null && selectedPlanSummary.includedUsers !== undefined && (
                                                <>
                                                    <div className="text-slate-600">
                                                        {planChangeText.included_users ?? 'Inkluderte brukere'}
                                                    </div>
                                                    <div className="font-semibold text-slate-900">
                                                        {selectedPlanSummary.includedUsers}
                                                    </div>
                                                </>
                                            )}
                                            {selectedPlanSummary.includedAiCredits !== null && selectedPlanSummary.includedAiCredits !== undefined && (
                                                <>
                                                    <div className="text-slate-600">
                                                        {planChangeText.included_ai_credits ?? 'Inkluderte KI-tilbud'}
                                                    </div>
                                                    <div className="font-semibold text-slate-900">
                                                        {selectedPlanSummary.includedAiCredits}
                                                    </div>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                )}

                                <div className="mt-6 flex flex-wrap justify-end gap-3">
                                    <button
                                        onClick={() => setPlanChangeStep('selection')}
                                        className="rounded-lg border border-slate-200 px-4 py-2 text-base font-medium text-slate-700 hover:bg-slate-50"
                                    >
                                        {planChangeText.back ?? 'Gå tilbake'}
                                    </button>
                                    <button
                                        onClick={handlePlanChangeSubmit}
                                        disabled={!canConfirmPlanChange}
                                        className={classNames(
                                            'rounded-lg px-4 py-2 text-base font-medium text-white',
                                            canConfirmPlanChange ? 'bg-blue-600 hover:bg-blue-700' : 'cursor-not-allowed bg-slate-300'
                                        )}
                                    >
                                        {planChangeText.confirm ?? 'Bekreft abonnementsendring'}
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
                confirmLabel={tb.cancel ?? 'Si opp abonnement'}
                cancelLabel={tb.cancel_button ?? 'Avbryt'}
                danger
            />

            <ConfirmDialog
                isOpen={confirmResume}
                title={tb.resume_confirm_title ?? 'Gjenoppta abonnement'}
                message={tb.resume_confirm_message ?? 'Oppsigelsen trekkes tilbake og abonnementet fortsetter som normalt.'}
                onConfirm={handleResume}
                onCancel={() => setConfirmResume(false)}
                confirmLabel={tb.resume ?? 'Gjenoppta abonnement'}
                cancelLabel={tb.cancel_button ?? 'Avbryt'}
            />
        </CustomerAppLayout>
    );
}
