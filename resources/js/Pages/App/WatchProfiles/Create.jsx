import { useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import WatchProfileForm from './WatchProfileForm';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import { getWatchProfileHelpSections } from './watchProfileHelp';

export default function WatchProfilesCreate({ ownerOptions, defaultOwnerScope, departmentOptions, cpvSuggestionsUrl, storeUrl }) {
    const { translations = {} } = usePage().props;
    const wp = translations?.watch_profile_page ?? {};
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
                <section className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-1.5">
                        <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Legg til Watch Profile</h1>
                        <p className="max-w-3xl text-base leading-7 text-slate-600">
                            Opprett en personlig eller avdelingsscopet watch profile som brukes direkte mot Doffin live search.
                        </p>
                    </div>
                    <PageHelpButton
                        buttonLabel={wp.page_help_button ?? 'Hjelp'}
                        title={wp.form_page_help_title ?? 'Om Watch Profile'}
                        intro={wp.form_page_help_intro ?? 'En Watch Profile bestemmer hvilke kunngjøringer som fanges opp for deg. Kriteriene du setter her avgjør hva som gir treff.'}
                        sections={getWatchProfileHelpSections(wp)}
                    />
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
