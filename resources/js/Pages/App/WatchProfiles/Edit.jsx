import { useForm } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import WatchProfileForm from './WatchProfileForm';

export default function WatchProfilesEdit({ watchProfile, ownerOptions, departmentOptions, cpvSuggestionsUrl }) {
    const form = useForm({
        owner_scope: watchProfile.owner_scope,
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
            <div className="space-y-7">
                <section className="space-y-1.5">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Rediger Watch Profile</h1>
                    <p className="max-w-3xl text-[15px] leading-7 text-slate-500">
                        Oppdater eierskap, søkekriterier og status for watch profile-en uten å bruke lokal notice-matching som discovery.
                    </p>
                </section>

                <WatchProfileForm
                    title="Rediger Watch Profile"
                    subtitle="Oppdater eierskap, søkekriterier og status for watch profile-en uten å bruke lokal notice-matching som discovery."
                    showHeader={false}
                    form={form}
                    ownerOptions={ownerOptions}
                    departmentOptions={departmentOptions}
                    cpvSuggestionsUrl={cpvSuggestionsUrl}
                    backHref="/app/watch-profiles"
                    submitLabel="Lagre endringer"
                    submitMethod="update"
                    deleteUrl={watchProfile.delete_url}
                    onSubmit={submit}
                />
            </div>
        </CustomerAppLayout>
    );
}
