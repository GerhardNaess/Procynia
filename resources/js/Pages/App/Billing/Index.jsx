import { router, usePage } from '@inertiajs/react';
import { PRIMARY_COLOURS, SECONDARY_COLOURS, WARNING_COLOURS } from '../../../Support/actionStyles';
import { useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import AiCapacityCard from '../../../Components/App/AiCapacityCard';
import AlertBox from '../../../Components/App/AlertBox';
import InfoHint from '../../../Components/App/InfoHint';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import StatusBadge from '../../../Components/App/StatusBadge';
import { packageActionLabel, packageConfirmation, packageStatus, splitPackages } from '../../../Support/packagePresentation';

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

function ConfirmDialog({ isOpen, title, message, onConfirm, onCancel, confirmLabel = 'Bekreft', cancelLabel = 'Avbryt', warning = false }) {
    if (!isOpen) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 px-4">
            <div role="dialog" aria-modal="true" aria-label={title} className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
                <h3 className="text-base font-semibold text-slate-900">{title}</h3>
                {(Array.isArray(message) ? message : [message]).map((line) => (
                    <p key={line} className="mt-2 text-base leading-6 text-slate-600">{line}</p>
                ))}
                <div className="mt-5 flex justify-end gap-3">
                    <button
                        onClick={onCancel}
                        className={`rounded-lg px-4 py-2 text-base font-medium ${SECONDARY_COLOURS}`}
                    >
                        {cancelLabel}
                    </button>
                    <button
                        onClick={onConfirm}
                        className={classNames(
                            'rounded-lg px-4 py-2 text-base font-medium',
                            warning ? WARNING_COLOURS : PRIMARY_COLOURS
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
        subscription,
        invoices = [],
        billing_lines: billingLines = [],
        // The shared AI capacity, in AI units (CustomerAiCapacityService). No tokens, no money.
        ai_capacity: aiCapacity = null,
        // Resolved by ModuleEntitlementService. The page renders this verdict; it never decides
        // on its own which packages or modules are active.
        module_packages: modulePackages = [],
        translations = {},
        flash,
        locale = 'nb-NO',
    } = page;

    const tb = translations.billing ?? {};
    const cardText = tb.subscription_card ?? {};
    const intervalLabels = tb.interval_labels ?? {};
    const aiCapacityText = tb.ai_capacity ?? {};
    const summaryText = tb.summary ?? {};
    const alertText = tb.alerts ?? {};
    const modulesText = tb.modules ?? {};
    const packageLabels = modulesText.package_labels ?? {};
    const packageDescriptions = modulesText.package_descriptions ?? {};
    const moduleLabels = modulesText.module_labels ?? {};
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
    // Bestill or Avbestill waiting for confirmation: the package's key.
    const [confirmPackageKey, setConfirmPackageKey] = useState(null);

    const sortedInvoices = [...invoices].sort((left, right) => (right.date_sort ?? 0) - (left.date_sort ?? 0));
    // The page names the product, Basis — never the legacy plan tier the backend still keeps.
    const hasRegisteredSubscription = Boolean(subscription);
    const hasProcyniaServices = billingLines.length > 0;
    const productLabel = cardText.product ?? 'Basis';
    const currentIntervalLabel = normalizeKey(subscription?.billing_interval) === 'yearly'
        ? (intervalLabels.yearly ?? 'Årlig')
        : (intervalLabels.monthly ?? 'Månedlig');
    const isEnding = Boolean(subscription?.cancel_at_period_end);

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

    const subscriptionSummaryValue = hasRegisteredSubscription
        ? `${productLabel} · ${currentIntervalLabel}`
        : (summaryText.no_active_subscription ?? 'Ingen aktivt abonnement');

    const procyniaServicesValue = formatCount(billingLines.length);

    const showAddonsWithoutSubscriptionWarning = !hasRegisteredSubscription && hasProcyniaServices;
    const handleCancel = () => {
        router.post('/app/billing/cancel', {}, {
            preserveScroll: true,
            onSuccess: () => setConfirmCancel(false),
        });
    };

    const resolvePackageName = (key) => packageLabels[key] ?? key;
    const resolveModuleLabel = (key) => moduleLabels[key] ?? key;
    const { base: basePackage, options: optionPackages } = splitPackages(modulePackages);
    const confirmPackage = modulePackages.find((entry) => entry.key === confirmPackageKey) ?? null;
    const confirmation = confirmPackage ? packageConfirmation(confirmPackage, modulesText, resolvePackageName) : null;

    const handlePackageConfirm = () => {
        if (!confirmPackage) {
            return;
        }

        const verb = confirmPackage.action === 'cancel' ? 'cancel' : 'request';

        router.post(`/app/billing/packages/${confirmPackage.key}/${verb}`, {}, {
            preserveScroll: true,
            onFinish: () => setConfirmPackageKey(null),
        });
    };

    const renderPackageStatus = (entry) => {
        const presentation = packageStatus(entry, modulesText);

        return (
            <div data-testid={`package-status-${entry.key}`}>
                <StatusBadge tone={presentation.tone}>{presentation.label}</StatusBadge>
                {entry.status === 'requested' && entry.requested_at && (
                    <div className="mt-1 text-base leading-6 text-slate-600">
                        {(modulesText.requested_at ?? 'Bestilt :date').replace(':date', formatDate(entry.requested_at))}
                    </div>
                )}
                {entry.status === 'active' && entry.activated_at && (
                    <div className="mt-1 text-base leading-6 text-slate-600">
                        {(modulesText.activated_at ?? 'Aktivert :date').replace(':date', formatDate(entry.activated_at))}
                    </div>
                )}
            </div>
        );
    };

    const renderPackageAction = (entry) => {
        const label = packageActionLabel(entry, modulesText);

        if (!label) {
            return null;
        }

        return (
            <button
                type="button"
                onClick={() => setConfirmPackageKey(entry.key)}
                className={`whitespace-nowrap rounded-lg px-4 py-2 text-base font-medium ${entry.action === 'cancel' ? SECONDARY_COLOURS : PRIMARY_COLOURS}`}
            >
                {label}
            </button>
        );
    };

    const handleResume = () => {
        router.post('/app/billing/resume', {}, {
            preserveScroll: true,
            onSuccess: () => setConfirmResume(false),
        });
    };

    return (
        <CustomerAppLayout title={tb.title ?? 'Abonnement'} showPageTitle={false}>
            <div className="space-y-7">
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

                <section className="space-y-1.5">
                    <div className="flex items-center gap-3">
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">{tb.title ?? 'Abonnement'}</h1>
                        <PageHelpButton
                            buttonLabel={tb.page_help_button ?? 'Hjelp'}
                            title={tb.page_help_title ?? 'Om abonnement, tilleggstjenester og fakturaer'}
                            intro={tb.page_help_intro ?? 'Abonnementet består av Basis, valgfrie opsjoner og en separat AI-kapasitet. Alle AI-funksjoner i Procynia bruker den samme AI-kapasiteten.'}
                            sections={[
                                {
                                    title: tb.page_help_section_overview ?? 'Hva du finner her',
                                    items: [
                                        {
                                            title: tb.page_help_item_subscription_title ?? 'Abonnement',
                                            text: tb.page_help_item_subscription_text ?? 'Viser at Basis er aktiv, hvordan abonnementet faktureres og hvor mange brukere som er inkludert. Her kan abonnementet også sies opp.',
                                        },
                                        {
                                            title: tb.page_help_item_ai_capacity_title ?? 'AI-kapasitet',
                                            text: tb.page_help_item_ai_capacity_text ?? 'AI-kapasiteten er en egen del av abonnementet, atskilt fra Basis og opsjonene. Den brukes når Procynia benytter AI til analyse, generering eller bearbeiding av innhold. Alle AI-funksjoner bruker den samme kapasiteten, og den endres ikke når opsjoner bestilles eller avbestilles.',
                                        },
                                        {
                                            title: tb.page_help_item_modules_title ?? 'Moduler og pakker',
                                            text: tb.page_help_item_modules_text ?? 'Viser hvilke pakker kundemiljøet har, og hvilke moduler hver pakke aktiverer.',
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
                    <p className="max-w-3xl text-base leading-7 text-slate-600">
                        {tb.subtitle ?? 'Oversikt over abonnement, tilleggstjenester og fakturaer.'}
                    </p>
                    <p className="max-w-3xl text-base leading-7 text-slate-600">
                        {tb.intro ?? 'Her ser du kundens abonnement, tilleggstjenester og fakturering. Fakturaer og PDF-er vises når de finnes.'}
                    </p>
                </section>

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

                {showAddonsWithoutSubscriptionWarning && (
                    <AlertBox>
                        {alertText.addons_without_subscription ?? 'Kontoen har aktive tillegg, men ingen aktivt abonnement. Kontakt Procynia dersom abonnementet skal aktiveres eller endres.'}
                    </AlertBox>
                )}

                <section data-testid="subscription-card" className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="flex items-center gap-2">
                        <h2 className="text-base font-semibold text-slate-900">
                            {cardText.heading ?? 'Abonnement'}
                        </h2>
                        <InfoHint
                            size="sm"
                            label="Vis forklaring for abonnement"
                            text={cardText.hint ?? 'Abonnementet består av Basis, valgfrie opsjoner og en separat AI-kapasitet. Opsjonene bestilles under Moduler og pakker.'}
                        />
                    </div>

                    {hasRegisteredSubscription ? (
                        <>
                            <div className="mt-3 flex flex-wrap items-center gap-3">
                                <span className="text-xl font-semibold text-slate-950">{productLabel}</span>
                                <StatusBadge tone={isEnding ? 'amber' : 'green'}>
                                    {isEnding
                                        ? (cardText.status_ending ?? 'Avsluttes ved periodeslutt')
                                        : (cardText.status_active ?? 'Aktiv')}
                                </StatusBadge>
                            </div>

                            <dl className="mt-4 grid grid-cols-[auto_minmax(0,1fr)] gap-x-8 gap-y-2 text-base">
                                <dt className="text-slate-600">{cardText.billing_interval ?? 'Fakturering'}</dt>
                                <dd className="font-medium text-slate-900">{currentIntervalLabel}</dd>

                                {subscription.included_users !== null && subscription.included_users !== undefined && (
                                    <>
                                        <dt className="text-slate-600">{cardText.included_users ?? 'Inkluderte brukere'}</dt>
                                        <dd className="font-medium text-slate-900">{subscription.included_users}</dd>
                                    </>
                                )}

                                {subscription.period_end && (
                                    <>
                                        <dt className="text-slate-600">
                                            {isEnding ? (cardText.ends_at ?? 'Avsluttes') : (cardText.next_invoice ?? 'Neste fakturadato')}
                                        </dt>
                                        <dd className="font-medium text-slate-900">{formatDate(subscription.period_end)}</dd>
                                    </>
                                )}
                            </dl>

                            <div className="mt-5 flex flex-wrap gap-3">
                                {subscription.status === 'active' && !isEnding && (
                                    <button
                                        onClick={() => setConfirmCancel(true)}
                                        className={`rounded-lg px-4 py-2 text-base font-medium ${WARNING_COLOURS}`}
                                    >
                                        {tb.cancel ?? 'Si opp abonnement'}
                                    </button>
                                )}
                                {isEnding && (
                                    <button
                                        onClick={() => setConfirmResume(true)}
                                        className={`rounded-lg px-4 py-2 text-base font-medium ${PRIMARY_COLOURS}`}
                                    >
                                        {tb.resume ?? 'Gjenoppta abonnement'}
                                    </button>
                                )}
                            </div>
                        </>
                    ) : (
                        <p className="mt-3 text-base leading-6 text-slate-600">
                            {cardText.empty ?? 'Ingen aktivt abonnement er registrert.'}
                        </p>
                    )}
                </section>

                <section data-testid="module-packages" className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="flex items-center gap-2">
                        <h2 className="text-base font-semibold text-slate-900">
                            {modulesText.heading ?? 'Moduler og pakker'}
                        </h2>
                        <InfoHint size="sm" label="Vis forklaring for moduler og pakker" text={modulesText.hint} />
                    </div>
                    <p className="mt-2 text-base leading-6 text-slate-600">
                        {modulesText.help ?? 'Basis er grunnpakken i Procynia. Du kan i tillegg bestille de modulene virksomheten trenger. Opsjoner kan aktiveres og avbestilles uavhengig av hverandre. Avbestilling sletter ikke data.'}
                    </p>

                    {basePackage && (
                        <div data-testid={`package-row-${basePackage.key}`} className="mt-5 rounded-xl border border-slate-200 p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <h3 className="text-base font-semibold text-slate-900">{modulesText.base_heading ?? 'Basis'}</h3>
                                    <p className="mt-1 text-base leading-6 text-slate-600">
                                        {modulesText.base_help ?? 'Basis er grunnpakken i Procynia.'}
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-start gap-3">
                                    {renderPackageStatus(basePackage)}
                                    {renderPackageAction(basePackage)}
                                </div>
                            </div>
                            <div className="mt-3 flex flex-wrap gap-1.5">
                                {basePackage.modules.map((moduleKey) => (
                                    <span
                                        key={moduleKey}
                                        className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-base font-medium leading-6 text-slate-700"
                                    >
                                        {resolveModuleLabel(moduleKey)}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}

                    <h3 className="mt-6 text-base font-semibold text-slate-900">{modulesText.options_heading ?? 'Opsjoner'}</h3>
                    <p className="mt-1 text-base leading-6 text-slate-600">
                        {modulesText.options_help ?? 'Bestill og avbestill hver modul for seg.'}
                    </p>
                    <ul className="mt-3 divide-y divide-slate-100 rounded-xl border border-slate-200">
                        {optionPackages.map((entry) => (
                            <li
                                key={entry.key}
                                data-testid={`package-row-${entry.key}`}
                                className="grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_10rem_9rem] sm:items-start"
                            >
                                <div className="min-w-0">
                                    <div className="font-medium text-slate-900">{resolvePackageName(entry.key)}</div>
                                    {packageDescriptions[entry.key] && (
                                        <p className="mt-1 text-base leading-6 text-slate-600">{packageDescriptions[entry.key]}</p>
                                    )}
                                </div>
                                {renderPackageStatus(entry)}
                                <div className="sm:text-right">{renderPackageAction(entry)}</div>
                            </li>
                        ))}
                    </ul>
                </section>

                {/* The third, separate part of the subscription: one AI pool, not sized by Basis or the options. */}
                <AiCapacityCard capacity={aiCapacity} texts={aiCapacityText} locale={locale} />

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

            <ConfirmDialog
                isOpen={confirmCancel}
                title={tb.cancel_confirm_title ?? 'Si opp abonnement'}
                message={tb.cancel_confirm_message ?? 'Abonnementet avsluttes automatisk ved slutten av inneværende periode. Du beholder tilgang til da.'}
                onConfirm={handleCancel}
                onCancel={() => setConfirmCancel(false)}
                confirmLabel={tb.cancel ?? 'Si opp abonnement'}
                cancelLabel={tb.cancel_button ?? 'Avbryt'}
                warning
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

            <ConfirmDialog
                isOpen={Boolean(confirmation)}
                title={confirmation?.title ?? ''}
                message={confirmation?.message ?? ''}
                onConfirm={handlePackageConfirm}
                onCancel={() => setConfirmPackageKey(null)}
                confirmLabel={confirmation?.confirmLabel}
                cancelLabel={tb.cancel_button ?? 'Avbryt'}
                warning={confirmation?.warning ?? false}
            />
        </CustomerAppLayout>
    );
}
