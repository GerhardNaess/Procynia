import { useForm } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import WatchProfileForm from './WatchProfileForm';

export default function WatchProfilesCreate({ ownerOptions, defaultOwnerScope, departmentOptions, storeUrl }) {
    const form = useForm({
        owner_scope: defaultOwnerScope,
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
                subtitle="Opprett en personlig eller avdelingsscopet watch profile som brukes direkte mot Doffin live search."
                form={form}
                ownerOptions={ownerOptions}
                departmentOptions={departmentOptions}
                backHref="/app/watch-profiles"
                submitLabel="Opprett Watch Profile"
                submitMethod="create"
                onSubmit={submit}
            />
        </CustomerAppLayout>
    );
}
