import { usePage } from '@inertiajs/react';
import PageHelpButton from '../../../Components/App/PageHelpButton';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function SectionTabs({ activeTab, onChange, showPermissions = false }) {
    const tabs = [
        { key: 'departments', label: 'Avdelinger' },
        { key: 'users', label: 'Brukere' },
        ...(showPermissions ? [{ key: 'permissions', label: 'Tilganger' }] : []),
    ];

    return (
        <div className="inline-flex rounded-2xl border border-slate-200 bg-white p-1 shadow-[0_8px_24px_rgba(15,23,42,0.04)]">
            {tabs.map((tab) => {
                const isActive = activeTab === tab.key;

                return (
                    <button
                        key={tab.key}
                        type="button"
                        onClick={() => onChange(tab.key)}
                        className={classNames(
                            'rounded-xl px-4 py-2.5 text-base font-semibold transition',
                            isActive
                                ? 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-200'
                                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                        )}
                    >
                        {tab.label}
                    </button>
                );
            })}
        </div>
    );
}

export default function CustomerEnvironmentHeader({ activeTab, onChangeTab, showPermissions = false }) {
    const { translations = {} } = usePage().props;
    const tce = translations?.customer_env ?? {};

    return (
        <>
            <section className="space-y-1.5">
                <div className="flex items-center gap-3">
                    <h1 className="text-4xl font-semibold tracking-tight text-slate-950">Kundemiljø</h1>
                    <PageHelpButton
                        buttonLabel={tce.page_help_button ?? 'Hjelp'}
                        title={tce.page_help_title ?? 'Kundemiljø'}
                        intro={tce.page_help_intro}
                        sections={[
                            {
                                title: tce.page_help_section_what ?? 'Hva er Kundemiljø?',
                                items: [
                                    {
                                        title: tce.page_help_item_what_title ?? 'Administrasjon avgrenset til eget kundemiljø',
                                        text: tce.page_help_item_what_body ?? 'Kundemiljø er området der avdelinger, brukere og tilganger vedlikeholdes for virksomheten din. Administrasjonen er avgrenset til eget kundemiljø — brukere kan bare se og administrere data som tilhører egen kunde. Dette håndheves i backend, ikke bare i brukergrensesnittet.',
                                    },
                                ],
                            },
                            {
                                title: tce.page_help_section_departments ?? 'Avdelinger',
                                items: [
                                    {
                                        title: tce.page_help_item_departments_title ?? 'Organiser brukere og ansvar',
                                        text: tce.page_help_item_departments_body ?? 'Avdelinger brukes til å organisere brukere og ansvar internt. Bruk Opprett avdeling for å legge til en ny, Rediger for å endre navn eller beskrivelse, og Deaktiver for å ta en avdeling ut av aktiv bruk uten å fjerne historikk.',
                                    },
                                ],
                            },
                            {
                                title: tce.page_help_section_users ?? 'Brukere',
                                items: [
                                    {
                                        title: tce.page_help_item_users_title ?? 'Tilgang til kundemiljøet',
                                        text: tce.page_help_item_users_body ?? 'Brukere er personene som har tilgang til kundemiljøet. Avhengig av rettigheter kan administratorer se og administrere brukere, roller og tilknytning til avdeling. Gå jevnlig gjennom brukerlisten, spesielt når ansatte bytter rolle eller slutter.',
                                    },
                                ],
                            },
                            {
                                title: tce.page_help_section_permissions ?? 'Tilganger',
                                items: [
                                    {
                                        title: tce.page_help_item_permissions_title ?? 'Rollestyrte rettigheter — kun for System Owner',
                                        text: tce.page_help_item_permissions_body ?? 'Tilganger-fanen er kun synlig for System Owner. Den viser og lar deg styre hvilke bid-roller som kan opprette avdelinger, opprette brukere og se alle saker. System Owner har alltid full tilgang og kan ikke fratas rettigheter. Vær forsiktig med endringer.',
                                    },
                                ],
                            },
                            {
                                title: tce.page_help_section_recommendation ?? 'Praktisk anbefaling',
                                items: [
                                    {
                                        title: tce.page_help_item_recommendation_title ?? 'Hold strukturen enkel',
                                        text: tce.page_help_item_recommendation_body ?? 'Opprett bare avdelinger som faktisk brukes. Gå jevnlig gjennom brukere og tilganger. Deaktiver gamle avdelinger eller brukere fremfor å slette historikk.',
                                    },
                                ],
                            },
                        ]}
                    />
                </div>
                <p className="max-w-3xl text-base leading-7 text-slate-600">
                    Administrer avdelinger og brukere for deres virksomhet. All administrasjon er begrenset til eget kundemiljø.
                </p>
            </section>

            <SectionTabs activeTab={activeTab} onChange={onChangeTab} showPermissions={showPermissions} />
        </>
    );
}
