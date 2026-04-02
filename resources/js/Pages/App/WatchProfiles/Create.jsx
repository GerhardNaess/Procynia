import { useForm } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import WatchProfileForm from './WatchProfileForm';

export default function WatchProfilesCreate({ ownerOptions, defaultOwnerScope, departmentOptions, cpvSuggestionsUrl, storeUrl }) {
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
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Legg til Watch Profile</h1>
                    <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                        Opprett en personlig eller avdelingsscopet watch profile som brukes direkte mot Doffin live search.
                    </p>
                </section>

                <WatchProfileForm
                    title="Legg til Watch Profile"
                    subtitle="Opprett en personlig eller avdelingsscopet watch profile som brukes direkte mot Doffin live search."
                    showHeader={false}
                    form={form}
                    ownerOptions={ownerOptions}
                    departmentOptions={departmentOptions}
                    cpvSuggestionsUrl={cpvSuggestionsUrl}
                    backHref="/app/watch-profiles"
                    submitLabel="Opprett Watch Profile"
                    submitMethod="create"
                    onSubmit={submit}
                />
            </div>
        </CustomerAppLayout>
    );
}
