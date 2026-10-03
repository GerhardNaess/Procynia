import { Link, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import { ModuleIcon } from '../../../Components/App/ModuleSidebar';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function fill(template, replacements) {
    return Object.entries(replacements).reduce(
        (text, [token, value]) => text.replaceAll(`:${token}`, value),
        String(template ?? ''),
    );
}

const FALLBACK_METRIC_LABELS = {
    pages: 'Wiki-sider',
    pending_review: 'Til gjennomgang',
    active_cases: 'Aktive saker',
    submitted_cases: 'Leverte tilbud',
};

const FALLBACK_DESCRIPTIONS = {
    wiki: 'Virksomhetens dokumenterte kunnskap — kilder, godkjente sider og det som venter på gjennomgang.',
    tenders: 'Fra kunngjøring til levert tilbud: muligheter, ansvar, krav og besvarelse.',
    quality: 'Kvalitetssikring av krav, svar og dokumentasjon før tilbudet sendes. Bygges nå; arbeidet ligger foreløpig i Wiki.',
};

/**
 * One module, as a card you can walk into.
 *
 * The whole card is the link rather than a button in a corner: there is exactly one thing to do
 * with it, and making the target the size of the card is what lets this page be scanned instead
 * of read. A module still being built gets the same card with no numbers and a quieter badge —
 * it is a real destination with a real landing page, not a dimmed placeholder, so it must not
 * look broken, only unfinished.
 */
function ModuleCard({ module, title, description, metrics, stateLabel, openLabel }) {
    const isBuilding = module.state === 'building';

    return (
        <Link
            href={module.href}
            data-testid={`home-module-${module.key}`}
            className={classNames(
                'group flex min-h-[13rem] flex-col rounded-2xl border bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition',
                'hover:-translate-y-0.5 hover:border-violet-200 hover:shadow-[0_8px_24px_rgba(15,23,42,0.08)]',
                'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-500',
                isBuilding ? 'border-slate-200/80' : 'border-slate-200/80',
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <span
                    className={classNames(
                        'inline-flex h-10 w-10 items-center justify-center rounded-xl',
                        isBuilding ? 'bg-slate-100 text-slate-500' : 'bg-violet-50 text-violet-700',
                    )}
                >
                    <ModuleIcon moduleKey={module.key} className="h-5 w-5" />
                </span>

                <span
                    data-testid={`home-module-${module.key}-state`}
                    className={classNames(
                        'rounded-full px-2.5 py-1 text-xs font-semibold',
                        isBuilding ? 'bg-slate-100 text-slate-500' : 'bg-emerald-50 text-emerald-700',
                    )}
                >
                    {stateLabel}
                </span>
            </div>

            <h2 className="mt-4 text-lg font-semibold text-slate-900">{title}</h2>
            <p className="mt-1.5 text-sm leading-6 text-slate-600">{description}</p>

            {metrics.length > 0 ? (
                <dl className="mt-5 flex flex-wrap gap-x-8 gap-y-3">
                    {metrics.map((metric) => (
                        <div key={metric.key} data-testid={`home-metric-${module.key}-${metric.key}`}>
                            <dt className="text-xs font-medium uppercase tracking-wide text-slate-400">
                                {metric.label}
                            </dt>
                            <dd className="mt-0.5 text-2xl font-semibold tabular-nums text-slate-900">
                                {metric.value}
                            </dd>
                        </div>
                    ))}
                </dl>
            ) : null}

            <span className="mt-auto pt-5 text-sm font-semibold text-violet-700 group-hover:text-violet-800">
                {openLabel} <span aria-hidden="true">→</span>
            </span>
        </Link>
    );
}

/**
 * Hjem.
 *
 * Cross-module on purpose: this page says where Procynia stands, and every module says what to do
 * next. It carries no Anbud widgets — the bid cockpit it used to be is now Bid Status, inside
 * Anbud, where its pipeline and its numbers mean something.
 */
export default function HomeIndex({ modules = [] }) {
    const { translations = {}, auth } = usePage().props;
    const th = translations?.home ?? {};
    const tm = translations?.navigation?.modules ?? {};
    const metricLabels = th.metrics ?? {};
    const descriptions = th.descriptions ?? {};
    const firstName = String(auth?.user?.name ?? '').trim().split(' ')[0] ?? '';

    return (
        <CustomerAppLayout title={th.page_title ?? 'Hjem'} showPageTitle={false}>
            <header className="mb-7">
                <h1 className="text-3xl font-semibold tracking-tight text-slate-900">
                    {firstName === ''
                        ? (th.page_title ?? 'Hjem')
                        : fill(th.greeting ?? 'Hei, :name', { name: firstName })}
                </h1>
                <p className="mt-2 max-w-2xl text-base leading-7 text-slate-600">
                    {th.intro ?? 'Status på tvers av modulene du jobber i. Velg en modul for å gå videre.'}
                </p>
            </header>

            <div
                data-testid="home-module-cards"
                className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3"
            >
                {modules.map((module) => {
                    const title = tm[module.key] ?? module.key;

                    return (
                        <ModuleCard
                            key={module.key}
                            module={module}
                            title={title}
                            description={descriptions[module.key] ?? FALLBACK_DESCRIPTIONS[module.key] ?? ''}
                            stateLabel={module.state === 'building'
                                ? (th.state_building ?? 'Under arbeid')
                                : (th.state_live ?? 'I drift')}
                            openLabel={fill(th.open_module ?? 'Åpne :module', { module: title })}
                            metrics={(module.metrics ?? []).map((metric) => ({
                                ...metric,
                                label: metricLabels[metric.key] ?? FALLBACK_METRIC_LABELS[metric.key] ?? metric.key,
                            }))}
                        />
                    );
                })}
            </div>
        </CustomerAppLayout>
    );
}
