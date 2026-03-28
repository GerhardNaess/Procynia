import { useForm } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import WatchProfileForm from './WatchProfileForm';

export default function WatchProfilesCreate({ departmentOptions, storeUrl }) {
    const form = useForm({
        name: '',
        description: '',
        is_active: true,
        department_id: null,
        keywords: '',
        cpv_codes: [],
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(storeUrl);
    };

    return (
        <CustomerAppLayout title="Legg til Watch Profile" showPageTitle={false}>
            <WatchProfileForm
                title="Legg til Watch Profile"
                subtitle="Opprett en kunde-scoped Watch Profile med valgfri avdeling, nøkkelord og CPV-regler med vekt."
                form={form}
                departmentOptions={departmentOptions}
                backHref="/app/watch-profiles"
                submitLabel="Opprett Watch Profile"
                submitMethod="create"
                onSubmit={submit}
            />
        </CustomerAppLayout>
    );
}
