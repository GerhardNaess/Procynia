import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

const STATUS_BADGE = {
    active: { label: 'Aktiv', classes: 'bg-green-100 text-green-800' },
    trialing: { label: 'Prøveperiode', classes: 'bg-blue-100 text-blue-800' },
    past_due: { label: 'Forfalt betaling', classes: 'bg-red-100 text-red-800' },
    canceled: { label: 'Avsluttet', classes: 'bg-gray-100 text-gray-600' },
    incomplete: { label: 'Ufullstendig', classes: 'bg-yellow-100 text-yellow-800' },
    incomplete_expired: { label: 'Utløpt', classes: 'bg-gray-100 text-gray-600' },
};

function StatusBadge({ status }) {
    const meta = STATUS_BADGE[status] ?? { label: status, classes: 'bg-gray-100 text-gray-600' };
    return (
        <span className={classNames('inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', meta.classes)}>
            {meta.label}
        </span>
    );
}

function ConfirmDialog({ isOpen, title, message, onConfirm, onCancel, confirmLabel = 'Bekreft', danger = false }) {
    if (!isOpen) return null;
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 px-4">
            <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
                <h3 className="text-base font-semibold text-slate-900">{title}</h3>
                <p className="mt-2 text-sm text-slate-600">{message}</p>
                <div className="mt-5 flex justify-end gap-3">
                    <button
                        onClick={onCancel}
                        className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                    >
                        Avbryt
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
    const { subscription, invoices, translations, flash } = usePage().props;
    const tb = translations?.billing ?? {};
    const locale = usePage().props.locale ?? 'nb-NO';

    const [confirmCancel, setConfirmCancel] = useState(false);
    const [confirmResume, setConfirmResume] = useState(false);

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

    const formatDate = (dateStr) => {
        if (!dateStr) return '—';
        return new Intl.DateTimeFormat(locale, { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(dateStr));
    };

    return (
        <CustomerAppLayout title={tb.title ?? 'Fakturering'}>
            <div className="mx-auto max-w-3xl space-y-8 px-4 py-8">

                {flash?.success && (
                    <div className="rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}

                {/* Nåværende abonnement */}
                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="text-base font-semibold text-slate-900">
                        {tb.current_plan ?? 'Nåværende abonnement'}
                    </h2>

                    {subscription ? (
                        <div className="mt-4 space-y-4">
                            <div className="flex flex-wrap items-center gap-3">
                                <StatusBadge status={subscription.status} />
                                {subscription.cancel_at_period_end && (
                                    <span className="inline-flex items-center rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-medium text-yellow-800">
                                        {tb.cancels_at_period_end ?? 'Avsluttes ved periodeslutt'}
                                    </span>
                                )}
                            </div>

                            <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                                {subscription.plan_label && (
                                    <>
                                        <dt className="text-slate-500">{tb.plan ?? 'Plan'}</dt>
                                        <dd className="font-medium text-slate-900">{subscription.plan_label}</dd>
                                    </>
                                )}
                                {subscription.current_period_end && (
                                    <>
                                        <dt className="text-slate-500">{tb.next_renewal ?? 'Neste fornyelse'}</dt>
                                        <dd className="font-medium text-slate-900">{formatDate(subscription.current_period_end)}</dd>
                                    </>
                                )}
                                {subscription.trial_ends_at && (
                                    <>
                                        <dt className="text-slate-500">{tb.trial_ends ?? 'Prøveperiode slutter'}</dt>
                                        <dd className="font-medium text-slate-900">{formatDate(subscription.trial_ends_at)}</dd>
                                    </>
                                )}
                            </dl>

                            <div className="flex gap-3 pt-2">
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
                        <p className="mt-4 text-sm text-slate-500">
                            {tb.no_subscription ?? 'Ingen aktivt abonnement. Ta kontakt med Procynia for å aktivere.'}
                        </p>
                    )}
                </section>

                {/* Fakturaer */}
                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="text-base font-semibold text-slate-900">
                        {tb.invoices_heading ?? 'Fakturaer'}
                    </h2>

                    {invoices && invoices.length > 0 ? (
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-slate-100 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                                        <th className="pb-2 pr-4">{tb.col_number ?? 'Fakturanr.'}</th>
                                        <th className="pb-2 pr-4">{tb.col_date ?? 'Dato'}</th>
                                        <th className="pb-2 pr-4">{tb.col_amount ?? 'Beløp'}</th>
                                        <th className="pb-2 pr-4">{tb.col_status ?? 'Status'}</th>
                                        <th className="pb-2">{tb.col_download ?? 'Last ned'}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {invoices.map((invoice) => (
                                        <tr key={invoice.id}>
                                            <td className="py-2.5 pr-4 font-mono text-xs text-slate-700">{invoice.number ?? invoice.id}</td>
                                            <td className="py-2.5 pr-4 text-slate-700">{formatDate(invoice.date)}</td>
                                            <td className="py-2.5 pr-4 text-slate-700">
                                                {invoice.amount_due} {invoice.currency}
                                            </td>
                                            <td className="py-2.5 pr-4">
                                                <span className={classNames(
                                                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                                    invoice.status === 'paid'
                                                        ? 'bg-green-100 text-green-700'
                                                        : 'bg-yellow-100 text-yellow-700'
                                                )}>
                                                    {invoice.status === 'paid'
                                                        ? (tb.status_paid ?? 'Betalt')
                                                        : (tb.status_open ?? 'Utestående')}
                                                </span>
                                            </td>
                                            <td className="py-2.5">
                                                {invoice.invoice_pdf ? (
                                                    <a
                                                        href={invoice.invoice_pdf}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="text-blue-600 hover:underline text-xs"
                                                    >
                                                        PDF
                                                    </a>
                                                ) : '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <p className="mt-4 text-sm text-slate-500">
                            {tb.no_invoices ?? 'Ingen fakturaer ennå.'}
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
                confirmLabel={tb.cancel ?? 'Si opp'}
                danger
            />

            <ConfirmDialog
                isOpen={confirmResume}
                title={tb.resume_confirm_title ?? 'Behold abonnement'}
                message={tb.resume_confirm_message ?? 'Oppsigelsen trekkes tilbake og abonnementet fortsetter som normalt.'}
                onConfirm={handleResume}
                onCancel={() => setConfirmResume(false)}
                confirmLabel={tb.resume ?? 'Behold abonnement'}
            />
        </CustomerAppLayout>
    );
}
