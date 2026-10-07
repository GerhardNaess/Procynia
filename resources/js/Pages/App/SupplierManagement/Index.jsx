import { usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import { supplierHelp } from './supplierHelp';

/**
 * Leverandøroppfølging → Leverandører: the register.
 *
 * The server has already decided that the person may be here (the `supplier` module and
 * supplier.view); nothing on this page decides access. There are no supplier records yet, so the
 * register is its empty state — and no «Registrer leverandør», since there is nothing to register
 * into until the register exists.
 */
export default function SupplierManagementIndex() {
    const { translations = {} } = usePage().props;
    const tr = translations?.supplier_management ?? {};

    return (
        <CustomerAppLayout title={tr.index_title ?? 'Leverandører'} showPageTitle={false}>
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <p className="text-base font-semibold text-violet-700">{tr.module_name ?? 'Leverandøroppfølging'}</p>
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">{tr.index_heading ?? 'Leverandører'}</h1>
                        <p className="max-w-3xl text-base leading-6 text-slate-600">
                            {tr.index_intro ?? 'Leverandørene virksomheten er avhengig av, hvem hos dere som følger dem opp, og hvor viktige de er.'}
                        </p>
                    </div>
                    <PageHelpButton {...supplierHelp(tr, 'index')} />
                </header>

                <EmptyStateBox
                    title={tr.empty_title ?? 'Ingen leverandører er registrert ennå'}
                    description={tr.empty_text ?? 'Når leverandører registreres, vises de her med intern ansvarlig, kritikalitet og neste vurdering.'}
                />
            </div>
        </CustomerAppLayout>
    );
}
