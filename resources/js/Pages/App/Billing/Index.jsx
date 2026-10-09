import { router, usePage } from '@inertiajs/react';
import { PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION } from '../../../Support/actionStyles';
import { useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import AiCapacityCard from '../../../Components/App/AiCapacityCard';
import AlertBox from '../../../Components/App/AlertBox';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import StatusBadge from '../../../Components/App/StatusBadge';
import { packageActionLabel, packageConfirmation, packageStatus, splitPackages } from '../../../Support/packagePresentation';

/**
 * One type scale for the whole page, so a section heading always outranks the text explaining it
 * and a value always outranks its label. Section headings had shrunk to body size and read like
 * metadata under the intro.
 */
const SECTION_CARD = 'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6';
const SECTION_HEADING = 'text-[1.375rem] font-semibold leading-8 tracking-tight text-slate-950';
const SECTION_HELP = 'mt-1 max-w-3xl text-base leading-7 text-slate-600';
const FACT_LABEL = 'text-base text-slate-600';
const FACT_VALUE = 'mt-0.5 text-lg font-semibold text-slate-900';
// Only the primary role carries its own focus ring; the others get the same one here.
const FOCUS_RING = 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600';

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


function ConfirmDialog({ isOpen, title, message, onConfirm, onCancel, confirmLabel = 'Bekreft', cancelLabel = 'Avbryt', warning = false }) {
    if (!isOpen) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 px-4">
            <div role="dialog" aria-modal="true" aria-label={title} className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
                <h3 className="text-lg font-semibold text-slate-950">{title}</h3>
                {(Array.isArray(message) ? message : [message]).map((line) => (
                    <p key={line} className="mt-2 text-base leading-6 text-slate-600">{line}</p>
                ))}
                <div className="mt-5 flex justify-end gap-3">
                    <button
                        onClick={onCancel}
                        className={`${SECONDARY_ACTION} ${FOCUS_RING}`}
                    >
                        {cancelLabel}
                    </button>
                    <button
                        onClick={onConfirm}
                        className={warning ? `${WARNING_ACTION} ${FOCUS_RING}` : PRIMARY_ACTION}
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
        // What each AI capacity level would include for this customer, in AI units.
        ai_capacity_levels: aiCapacityLevels = [],
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
    // "Wiki, Kvalitet og Avvik og forbedringer" — said once, in the Basis card.
    const basisModules = basePackage?.modules?.length
        ? new Intl.ListFormat(locale, { style: 'long', type: 'conjunction' }).format(basePackage.modules.map((key) => resolveModuleLabel(key)))
        : null;
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
            <div data-testid={`package-status-${entry.key}`} className="flex flex-col items-start gap-1 sm:items-end">
                <StatusBadge tone={presentation.tone}>{presentation.label}</StatusBadge>
                {entry.status === 'requested' && entry.requested_at && (
                    <div className="text-base leading-6 text-slate-600">
                        {(modulesText.requested_at ?? 'Bestilt :date').replace(':date', formatDate(entry.requested_at))}
                    </div>
                )}
                {entry.status === 'active' && entry.activated_at && (
                    <div className="text-base leading-6 text-slate-600">
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
                className={`whitespace-nowrap ${entry.action === 'cancel' ? `${SECONDARY_ACTION} ${FOCUS_RING}` : PRIMARY_ACTION}`}
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
                            title={tb.page_help_title ?? 'Om abonnementet'}
                            intro={tb.page_help_intro ?? 'Abonnementet består av Basis, valgfrie opsjoner og separat AI-kapasitet. Basis er grunnproduktet. Opsjoner gir tilgang til flere moduler. AI-kapasiteten deles av alle funksjoner som bruker AI.'}
                            sections={[
                                {
                                    title: tb.page_help_section_overview ?? 'Hva du finner her',
                                    items: [
                                        {
                                            title: tb.page_help_item_subscription_title ?? 'Basis',
                                            text: tb.page_help_item_subscription_text ?? 'Grunnproduktet: hvordan abonnementet faktureres og hvor mange brukere som er inkludert. Her kan abonnementet også sies opp.',
                                        },
                                        {
                                            title: tb.page_help_item_modules_title ?? 'Opsjoner',
                                            text: tb.page_help_item_modules_text ?? 'Moduler som kan bestilles og avbestilles hver for seg. Avbestilling sletter ikke data.',
                                        },
                                        {
                                            title: tb.page_help_item_ai_capacity_title ?? 'AI-kapasitet',
                                            text: tb.page_help_item_ai_capacity_text ?? 'AI-kapasiteten beregnes ut fra virksomhetens brukere og aktive moduler. Valgt nivå bestemmer hvor mye ekstra kapasitet virksomheten har. Alle AI-funksjoner bruker den samme kapasiteten.',
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
                        {tb.subtitle ?? 'Oversikt over Basis, opsjoner, AI-kapasitet og fakturaer.'}
                    </p>
                </section>

                {showAddonsWithoutSubscriptionWarning && (
                    <AlertBox>
                        {alertText.addons_without_subscription ?? 'Kontoen har aktive fakturerte tjenester, men ingen aktivt abonnement. Kontakt Procynia dersom abonnementet skal aktiveres eller endres.'}
                    </AlertBox>
                )}

                {/* 1. Basis: the base product and how the subscription is billed. Never options or AI capacity. */}
                <section data-testid="subscription-card" className={SECTION_CARD}>
                    <div data-testid={basePackage ? `package-row-${basePackage.key}` : undefined}>
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                            <h2 className={SECTION_HEADING}>{productLabel}</h2>
                            <div data-testid={basePackage ? `package-status-${basePackage.key}` : undefined}>
                                {hasRegisteredSubscription ? (
                                    <StatusBadge tone={isEnding ? 'amber' : 'green'}>
                                        {isEnding
                                            ? (cardText.status_ending ?? 'Avsluttes ved periodeslutt')
                                            : (cardText.status_active ?? 'Aktiv')}
                                    </StatusBadge>
                                ) : basePackage && (
                                    <StatusBadge tone={packageStatus(basePackage, modulesText).tone}>
                                        {packageStatus(basePackage, modulesText).label}
                                    </StatusBadge>
                                )}
                            </div>
                        </div>
                        {basisModules && (
                            <p className={SECTION_HELP}>
                                {(cardText.contains ?? 'Inneholder :modules.').replace(':modules', basisModules)}
                            </p>
                        )}
                    </div>

                    {hasRegisteredSubscription ? (
                        <>
                            <dl data-testid="subscription-facts" className="mt-4 grid grid-cols-2 gap-x-8 gap-y-4 sm:flex sm:flex-wrap sm:gap-x-12">
                                <div>
                                    <dt className={FACT_LABEL}>{cardText.billing_interval ?? 'Fakturering'}</dt>
                                    <dd className={FACT_VALUE}>{currentIntervalLabel}</dd>
                                </div>

                                {subscription.included_users !== null && subscription.included_users !== undefined && (
                                    <div>
                                        <dt className={FACT_LABEL}>{cardText.included_users ?? 'Inkluderte brukere'}</dt>
                                        <dd className={FACT_VALUE}>{subscription.included_users}</dd>
                                    </div>
                                )}

                                {subscription.period_end && (
                                    <div>
                                        <dt className={FACT_LABEL}>
                                            {isEnding ? (cardText.ends_at ?? 'Avsluttes') : (cardText.next_invoice ?? 'Neste fakturadato')}
                                        </dt>
                                        <dd className={FACT_VALUE}>{formatDate(subscription.period_end)}</dd>
                                    </div>
                                )}
                            </dl>

                            <div className="mt-5 flex flex-wrap gap-3">
                                {subscription.status === 'active' && !isEnding && (
                                    <button
                                        type="button"
                                        onClick={() => setConfirmCancel(true)}
                                        className={`${WARNING_ACTION} ${FOCUS_RING}`}
                                    >
                                        {tb.cancel ?? 'Si opp abonnement'}
                                    </button>
                                )}
                                {isEnding && (
                                    <button
                                        type="button"
                                        onClick={() => setConfirmResume(true)}
                                        className={PRIMARY_ACTION}
                                    >
                                        {tb.resume ?? 'Gjenoppta abonnement'}
                                    </button>
                                )}
                            </div>
                        </>
                    ) : (
                        <>
                            <p className="mt-3 text-base leading-7 text-slate-600">
                                {cardText.empty ?? 'Ingen aktivt abonnement er registrert.'}
                            </p>
                            {basePackage && packageActionLabel(basePackage, modulesText) && (
                                <div className="mt-4">{renderPackageAction(basePackage)}</div>
                            )}
                        </>
                    )}
                </section>

                {/* 2. Opsjoner: each option on its own. What Basis contains is not repeated here. */}
                <section data-testid="module-packages" className={SECTION_CARD}>
                    <h2 className={SECTION_HEADING}>
                        {modulesText.options_heading ?? 'Opsjoner'}
                    </h2>
                    <p className={SECTION_HELP}>
                        {modulesText.options_help ?? 'Bestill og avbestill hver opsjon for seg. Brukere får tilgang gjennom rollene sine. Avbestilling sletter ikke data.'}
                    </p>
                    <ul className="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200">
                        {optionPackages.map((entry) => (
                            <li
                                key={entry.key}
                                data-testid={`package-row-${entry.key}`}
                                className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:gap-6"
                            >
                                <div className="min-w-0 sm:flex-1">
                                    <h3 className="text-lg font-semibold text-slate-900">{resolvePackageName(entry.key)}</h3>
                                    {packageDescriptions[entry.key] && (
                                        <p className="mt-0.5 text-base leading-6 text-slate-600">{packageDescriptions[entry.key]}</p>
                                    )}
                                </div>
                                {/* Status and action belong to the row, but never outweigh the module's name. */}
                                <div data-testid={`package-side-${entry.key}`} className="flex flex-wrap items-center gap-3 sm:shrink-0 sm:justify-end">
                                    {renderPackageStatus(entry)}
                                    {renderPackageAction(entry)}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>

                {/* 3. AI-kapasitet: one pool for every module, not sized by Basis or the options. */}
                <AiCapacityCard capacity={aiCapacity} levels={aiCapacityLevels} texts={aiCapacityText} locale={locale} />

                {/* Invoiced services outside Basis and the options (seats, one-off services). Only when there are any. */}
                {hasProcyniaServices && (
                    <section data-testid="other-services" className={SECTION_CARD}>
                        <h2 className={SECTION_HEADING}>
                            {servicesText.heading ?? 'Andre fakturerte tjenester'}
                        </h2>
                        <p className={SECTION_HELP}>
                            {servicesText.help ?? 'Tjenester som faktureres i tillegg til Basis og opsjonene.'}
                        </p>
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-base">
                                <thead>
                                    <tr className="border-b border-slate-200 text-left text-base font-medium text-slate-600">
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
                    </section>
                )}

                <section data-testid="invoices" className={SECTION_CARD}>
                    <h2 className={SECTION_HEADING}>
                        {invoicesText.heading ?? 'Fakturaer og betalinger'}
                    </h2>
                    <p className={SECTION_HELP}>
                        {invoicesText.help ?? 'Her finner du utestående beløp, fakturahistorikk og eventuelle PDF-er.'}
                    </p>

                    <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div className={FACT_LABEL}>
                            {invoicesText.outstanding_label ?? 'Utestående beløp'}
                        </div>
                        <div className={FACT_VALUE}>
                            {outstandingAmountLabel ?? (invoicesText.no_outstanding ?? 'Ingen utestående beløp registrert.')}
                        </div>
                    </div>

                    {sortedInvoices.length > 0 ? (
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-base">
                                <thead>
                                    <tr className="border-b border-slate-200 text-left text-base font-medium text-slate-600">
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
                                                        className={`rounded text-base font-medium text-blue-700 hover:underline ${FOCUS_RING}`}
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
                        <p className="mt-4 text-base leading-7 text-slate-600">
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
