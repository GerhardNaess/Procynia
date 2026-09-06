import { useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import WatchProfileForm from './WatchProfileForm';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import { getWatchProfileHelpSections } from './watchProfileHelp';

export default function WatchProfilesEdit({ watchProfile, ownerOptions, departmentOptions, cpvSuggestionsUrl }) {
    const { translations = {} } = usePage().props;
    const wp = translations?.watch_profile_page ?? {};
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
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Rediger Watch Profile</h1>
                        <PageHelpButton
                            buttonLabel={wp.page_help_button ?? 'Hjelp'}
                            title={wp.form_page_help_title ?? 'Om Watch Profile'}
                            intro={wp.form_page_help_intro ?? 'En Watch Profile bestemmer hvilke kunngjøringer som fanges opp for deg. Kriteriene du setter her avgjør hva som gir treff.'}
                            sections={getWatchProfileHelpSections(wp)}
                        />
                    </div>
                    <p className="max-w-3xl text-base leading-7 text-slate-600">
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
