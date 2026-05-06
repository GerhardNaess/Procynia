import { Link, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

function formatDateTime(value, locale) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

function formatContractValue(amount, currencyCode) {
    if (amount === null || amount === undefined || amount === '') {
        return null;
    }

    const numericValue = Number(amount);

    if (!Number.isFinite(numericValue)) {
        return null;
    }

    const formattedAmount = numericValue.toLocaleString('no-NO', {
        maximumFractionDigits: 0,
    });

    return currencyCode ? `${formattedAmount} ${currencyCode}` : formattedAmount;
}

export default function SupplierShow({ supplier, linkedNotices = [] }) {
    const locale = document.documentElement.lang || 'no';
    const { translations = {} } = usePage().props;
    const ts = translations?.suppliers ?? {};

    return (
        <CustomerAppLayout title={supplier.supplier_name} showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">{supplier.supplier_name}</h1>
                    <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                        {ts.detail_subtitle ?? 'Konkurrentdetaljer fra Doffin.'}
                    </p>
                </section>

                <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="flex justify-end">
                        <Link
                            href={supplier.back_url}
                            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                        >
                            {ts.back_button ?? 'Tilbake til konkurrenter'}
                        </Link>
                    </div>
                </section>

                <section className="grid gap-4 rounded-[24px] border border-slate-200 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.04)] md:grid-cols-2">
                    <div className="space-y-1">
                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{ts.supplier_name_label ?? 'Leverandørnavn'}</div>
                        <div className="text-base font-semibold text-slate-950">{supplier.supplier_name}</div>
                    </div>
                    <div className="space-y-1">
                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{ts.org_number_label ?? 'Organisasjonsnummer'}</div>
                        <div className="text-sm font-medium text-slate-900">{supplier.organization_number || '—'}</div>
                    </div>
                    <div className="space-y-1">
                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{ts.normalized_name_label ?? 'Normalisert navn'}</div>
                        <div className="text-sm font-medium text-slate-900 break-all">{supplier.normalized_name}</div>
                    </div>
                    <div className="space-y-1">
                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{ts.notices_label ?? 'Kunngjøringer'}</div>
                        <div className="text-sm font-medium text-slate-900">{supplier.notices_count}</div>
                    </div>
                    <div className="space-y-1 md:col-span-2">
                        <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{ts.updated_at_label ?? 'Oppdatert'}</div>
                        <div className="text-sm font-medium text-slate-900">{formatDateTime(supplier.updated_at, locale)}</div>
                    </div>
                </section>

                <section className="overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
                    <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                        <div>
                            <h2 className="text-xl font-semibold tracking-tight text-slate-950">{ts.linked_notices_title ?? 'Tilknyttede kunngjøringer'}</h2>
                            <p className="mt-1 text-sm text-slate-500">{ts.linked_notices_subtitle ?? 'Kunngjøringer der denne konkurrenten er registrert.'}</p>
                        </div>
                        <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">
                            {linkedNotices.length}
                        </span>
                    </div>

                    {linkedNotices.length === 0 ? (
                        <div className="px-6 py-10 text-sm text-slate-500">{ts.linked_notices_empty ?? 'Ingen tilknyttede kunngjøringer funnet.'}</div>
                    ) : (
                        <div className="max-h-[34rem] overflow-y-auto">
                            <div className="divide-y divide-slate-200">
                                {linkedNotices.map((notice) => {
                                    const formattedContractValue = formatContractValue(
                                        notice.estimated_value_amount,
                                        notice.estimated_value_currency_code,
                                    );

                                    return (
                                        <article key={notice.id} className="px-6 py-4">
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div className="min-w-0 flex-1 space-y-1.5">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        {notice.show_url ? (
                                                            <Link
                                                                href={notice.show_url}
                                                                className="text-sm font-semibold text-violet-700 transition hover:text-violet-800"
                                                            >
                                                                {notice.notice_id || (ts.unknown_notice ?? 'Ukjent kunngjøring')}
                                                            </Link>
                                                        ) : (
                                                            <span className="text-sm font-semibold text-slate-900">
                                                                {notice.notice_id || (ts.unknown_notice ?? 'Ukjent kunngjøring')}
                                                            </span>
                                                        )}

                                                        {notice.source ? (
                                                            <span className="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700 ring-1 ring-inset ring-slate-200">
                                                                {notice.source}
                                                            </span>
                                                        ) : null}
                                                    </div>

                                                    <div className="text-sm font-medium leading-5 text-slate-950">
                                                        {notice.heading || (ts.no_heading ?? 'Ingen overskrift tilgjengelig')}
                                                    </div>

                                                    {formattedContractValue || notice.contract_period_text || notice.short_description ? (
                                                        <div className="space-y-2">
                                                            {(formattedContractValue || notice.contract_period_text) ? (
                                                                <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-600">
                                                                    {formattedContractValue ? (
                                                                        <span>{ts.value_prefix ?? 'Verdi'}: {formattedContractValue}</span>
                                                                    ) : null}
                                                                    {notice.contract_period_text ? (
                                                                        <span>{ts.contract_period_prefix ?? 'Avtaleperiode'}: {notice.contract_period_text}</span>
                                                                    ) : null}
                                                                </div>
                                                            ) : null}

                                                            {notice.short_description ? (
                                                                <div className="text-sm leading-5 text-slate-600">
                                                                    {notice.short_description}
                                                                </div>
                                                            ) : null}
                                                        </div>
                                                    ) : null}
                                                </div>

                                                <div className="text-xs font-medium text-slate-500">
                                                    {formatDateTime(notice.publication_date, locale)}
                                                </div>
                                            </div>

                                            <div className="mt-3 grid gap-x-4 gap-y-2 md:grid-cols-2">
                                                <div className="space-y-1">
                                                    <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Oppdragsgiver</div>
                                                    <div className="text-sm text-slate-900">{notice.buyer_name || (ts.unknown_buyer ?? 'Ukjent oppdragsgiver')}</div>
                                                </div>

                                                <div className="space-y-1">
                                                    <div className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{ts.winner_lots_label ?? 'Vinnende delleveranser'}</div>
                                                    <div className="text-sm text-slate-900">
                                                        {notice.winner_lots.length > 0 ? notice.winner_lots.join(', ') : (ts.no_winner_lots ?? 'Ingen')}
                                                    </div>
                                                </div>
                                            </div>
                                        </article>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </section>
            </div>
        </CustomerAppLayout>
    );
}
