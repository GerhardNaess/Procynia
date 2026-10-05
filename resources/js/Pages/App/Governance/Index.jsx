import { Link, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import { ModuleIcon } from '../../../Components/App/ModuleSidebar';

function fill(template, replacements) {
    return Object.entries(replacements).reduce(
        (text, [token, value]) => text.replaceAll(`:${token}`, value),
        String(template ?? ''),
    );
}

const FALLBACK_DESCRIPTIONS = {
    quality: 'Prosesser og aktiviteter som beskriver hvordan virksomheten arbeider.',
    risk: 'Identifiser, vurder og følg opp risiko.',
    objectives: 'Sett mål, følg resultater og se hva som trenger oppmerksomhet.',
    improvements: 'Registrer, behandle og følg opp avvik og forbedringsmuligheter.',
};

/**
 * Styring — a door to each governance module the person can open, and nothing more.
 *
 * Not a dashboard: the cards carry no counts, no attention and no status, and the page asks the
 * server for nothing but which modules to show (GovernanceController). Each module already has its
 * own overview and its own PageHelp; this page only gets the person there.
 */
export default function GovernanceIndex({ modules = [] }) {
    const { translations = {} } = usePage().props;
    const tg = translations?.governance ?? {};
    const tm = translations?.navigation?.modules ?? {};
    const descriptions = tg.descriptions ?? {};

    return (
        <CustomerAppLayout title={tg.page_title ?? 'Styring'} showPageTitle={false}>
            <header className="mb-7">
                <h1 className="text-3xl font-semibold tracking-tight text-slate-900">
                    {tg.page_title ?? 'Styring'}
                </h1>
                <p className="mt-2 max-w-2xl text-base leading-7 text-slate-600">
                    {tg.intro ?? 'Styring samler virksomhetens prosesser, risiko, mål og forbedringsarbeid på ett sted. Velg området du vil arbeide med.'}
                </p>
            </header>

            <ul data-testid="governance-module-cards" className="grid gap-5 sm:grid-cols-2">
                {modules.map((module) => {
                    const title = tm[module.key] ?? module.key;

                    return (
                        <li key={module.key}>
                            <Link
                                href={module.href}
                                data-testid={`governance-module-${module.key}`}
                                className="group flex h-full flex-col rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition hover:-translate-y-0.5 hover:border-violet-200 hover:shadow-[0_8px_24px_rgba(15,23,42,0.08)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-500"
                            >
                                <span className="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50 text-violet-700">
                                    <ModuleIcon moduleKey={module.key} className="h-5 w-5" />
                                </span>

                                <h2 className="mt-4 text-lg font-semibold text-slate-900">{title}</h2>
                                <p className="mt-1.5 text-base leading-7 text-slate-600">
                                    {descriptions[module.key] ?? FALLBACK_DESCRIPTIONS[module.key] ?? ''}
                                </p>

                                <span className="mt-auto pt-5 text-base font-semibold text-violet-700 group-hover:text-violet-800">
                                    {fill(tg.open_module ?? 'Åpne :module', { module: title })} <span aria-hidden="true">→</span>
                                </span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </CustomerAppLayout>
    );
}
