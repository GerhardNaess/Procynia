import { Link, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import { SECONDARY_ACTION } from '../../../Support/actionStyles';
import { formatDateTime } from '../Compliance/Requirements/complianceRequirement';
import { supplierHelp } from './supplierHelp';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const REGISTER_URL = '/app/supplier-management';

/**
 * Leverandører → Slettede leverandører: the deletion log, read-only and newest first — the supplier's
 * name, when it was deleted and by whom. Reached from the register's header, only with
 * supplier.delete; the server refuses it otherwise. Nothing links to a supplier: none is left.
 */
export default function DeletedSuppliers() {
    const { translations = {}, deletions = [], locale = 'no' } = usePage().props;
    const tr = translations?.supplier_management ?? {};
    const td = tr.deleted ?? {};

    return (
        <CustomerAppLayout title={td.title ?? 'Slettede leverandører'} showPageTitle={false}>
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">{td.heading ?? 'Slettede leverandører'}</h1>
                        <p className="max-w-3xl text-base leading-6 text-slate-600">{td.intro}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <PageHelpButton {...supplierHelp(tr, 'deleted')} />
                        <Link href={REGISTER_URL} className={SECONDARY_ACTION}>{td.back ?? 'Til leverandører'}</Link>
                    </div>
                </header>

                {deletions.length === 0 ? (
                    <EmptyStateBox title={td.empty ?? 'Ingen leverandører er slettet.'} description={td.empty_hint ?? ''} />
                ) : (
                    <section className={CARD} aria-label={td.heading ?? 'Slettede leverandører'}>
                        <ol className="divide-y divide-slate-100" data-testid="supplier-deletions">
                            {deletions.map((entry) => (
                                <li key={entry.id} className="min-w-0 py-3 first:pt-0 last:pb-0" data-testid="supplier-deletion">
                                    <p className="break-words text-base font-semibold text-slate-950">{entry.supplier_name}</p>
                                    <p className="break-words text-base text-slate-600">
                                        {(td.deleted_by ?? 'Slettet :date av :name')
                                            .replace(':date', formatDateTime(entry.deleted_at, locale))
                                            .replace(':name', entry.deleted_by_name ?? tr.unknown_user ?? 'en tidligere bruker')}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </section>
                )}
            </div>
        </CustomerAppLayout>
    );
}
