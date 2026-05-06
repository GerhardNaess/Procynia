import { usePage } from '@inertiajs/react';
import DashboardCockpit from '../../../Components/App/DashboardCockpit';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

export default function DashboardIndex({ cockpit = null }) {
    const { locale = 'nb-NO', translations = {} } = usePage().props;
    const dashboardText = translations.dashboard?.cockpit ?? {};
    const commonText = translations.common ?? {};

    return (
        <CustomerAppLayout title={dashboardText.page_title} showPageTitle={false}>
            <DashboardCockpit cockpit={cockpit} locale={locale} texts={dashboardText} commonText={commonText} />
        </CustomerAppLayout>
    );
}
