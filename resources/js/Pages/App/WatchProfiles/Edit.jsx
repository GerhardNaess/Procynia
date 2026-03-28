import { useForm } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import WatchProfileForm from './WatchProfileForm';

export default function WatchProfilesEdit({ watchProfile, departmentOptions }) {
    const form = useForm({
        name: watchProfile.name,
        description: watchProfile.description || '',
        is_active: watchProfile.is_active,
        department_id: watchProfile.department_id,
        keywords: watchProfile.keywords || '',
        cpv_codes: watchProfile.cpv_codes || [],
    });

    const submit = (event) => {
        event.preventDefault();
        form.put(watchProfile.update_url);
    };

    return (
        <CustomerAppLayout title="Rediger Watch Profile" showPageTitle={false}>
            <WatchProfileForm
                title="Rediger Watch Profile"
                subtitle="Oppdater profilens navn, avdeling, nøkkelord og CPV-regler uten å endre den underliggende Watch Profile-logikken."
                form={form}
                departmentOptions={departmentOptions}
                backHref="/app/watch-profiles"
                submitLabel="Lagre endringer"
                submitMethod="update"
                deleteUrl={watchProfile.delete_url}
                onSubmit={submit}
            />
        </CustomerAppLayout>
    );
}
