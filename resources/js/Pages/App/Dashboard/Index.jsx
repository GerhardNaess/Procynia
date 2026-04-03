import { usePage } from '@inertiajs/react';
import DashboardCockpit from '../../../Components/App/DashboardCockpit';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

export default function DashboardIndex({ cockpit = null }) {
    const { locale = 'nb-NO' } = usePage().props;

    return (
        <CustomerAppLayout title="Oversikt" showPageTitle={false}>
            <DashboardCockpit cockpit={cockpit} locale={locale} />
        </CustomerAppLayout>
    );
}
