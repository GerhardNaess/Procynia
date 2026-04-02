import { usePage } from '@inertiajs/react';
import BidPipelineOverview from '../../../Components/App/BidPipelineOverview';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

export default function DashboardIndex({ pipeline = null }) {
    const { locale = 'nb-NO' } = usePage().props;

    return (
        <CustomerAppLayout title="Oversikt" showPageTitle={false}>
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Oversikt</h1>
                    <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                        Porteføljeoversikten gir deg et samlet cockpit-blikk på hvor sakene ligger i bid-prosessen akkurat nå.
                    </p>
                </section>

                <BidPipelineOverview pipeline={pipeline} locale={locale} />
            </div>
        </CustomerAppLayout>
    );
}
