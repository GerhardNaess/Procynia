import PublicLayout from '../../Layouts/PublicLayout';

function Verify() {
    return (
        <span className="ml-1.5 inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200">
            ⚠ verifiseres
        </span>
    );
}

function LockIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-6 w-6" aria-hidden="true">
            <rect x="5" y="11" width="14" height="10" rx="2" stroke="currentColor" strokeWidth="1.7" />
            <path d="M8 11V7a4 4 0 0 1 8 0v4" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
            <circle cx="12" cy="16" r="1.2" fill="currentColor" />
        </svg>
    );
}

function ShieldIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-6 w-6" aria-hidden="true">
            <path d="M12 4.5 18.5 7v5.1c0 4-2.5 6.7-6.5 8-4-1.3-6.5-4-6.5-8V7L12 4.5Z" stroke="currentColor" strokeWidth="1.7" strokeLinejoin="round" />
            <path d="m9.2 12.2 1.7 1.7 3.8-4" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function KeyIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-6 w-6" aria-hidden="true">
            <circle cx="8.5" cy="12" r="4.5" stroke="currentColor" strokeWidth="1.7" />
            <path d="m13 12 7 0M18 12v2.5" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
        </svg>
    );
}

function EyeIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-6 w-6" aria-hidden="true">
            <path d="M2.5 12C4.5 7.5 8 5 12 5s7.5 2.5 9.5 7c-2 4.5-5.5 7-9.5 7s-7.5-2.5-9.5-7Z" stroke="currentColor" strokeWidth="1.7" />
            <circle cx="12" cy="12" r="2.8" stroke="currentColor" strokeWidth="1.7" />
        </svg>
    );
}

function ServerIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-6 w-6" aria-hidden="true">
            <rect x="3" y="4" width="18" height="6" rx="1.5" stroke="currentColor" strokeWidth="1.7" />
            <rect x="3" y="14" width="18" height="6" rx="1.5" stroke="currentColor" strokeWidth="1.7" />
            <circle cx="7" cy="7" r="1" fill="currentColor" />
            <circle cx="7" cy="17" r="1" fill="currentColor" />
        </svg>
    );
}

function RefreshIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-6 w-6" aria-hidden="true">
            <path d="M4 12a8 8 0 0 1 14-5.2" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
            <path d="M20 12a8 8 0 0 1-14 5.2" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
            <path d="m18 4.5-.3 3-3-.3" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" />
            <path d="m6 19.5.3-3 3 .3" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function BellIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-6 w-6" aria-hidden="true">
            <path d="M6 10a6 6 0 1 1 12 0c0 3.5 1.5 5 2 6H4c.5-1 2-2.5 2-6Z" stroke="currentColor" strokeWidth="1.7" strokeLinejoin="round" />
            <path d="M10 19a2 2 0 0 0 4 0" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
        </svg>
    );
}

function UsersIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-6 w-6" aria-hidden="true">
            <circle cx="9" cy="8" r="3.5" stroke="currentColor" strokeWidth="1.7" />
            <path d="M2 20c0-3.3 3.1-6 7-6s7 2.7 7 6" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
            <path d="M17 11c2 0 4 1.5 4 4" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
            <circle cx="18" cy="7.5" r="2.5" stroke="currentColor" strokeWidth="1.7" />
        </svg>
    );
}

const CARDS = [
    {
        Icon: LockIcon,
        title: 'Kryptering i transitt og i ro',
        color: 'text-blue-600',
        bg: 'bg-blue-50',
        verify: false,
        body: 'All kommunikasjon mellom nettleser og server er kryptert med TLS 1.2 eller nyere. Data lagret i databasen er kryptert i ro.',
        verifyText: null,
    },
    {
        Icon: KeyIcon,
        title: 'Autentisering og tilgangskontroll',
        color: 'text-violet-600',
        bg: 'bg-violet-50',
        verify: false,
        body: 'Passord lagres som bcrypt-hasher. Sesjonshåndtering følger Laravel-standarder. Rollebasert tilgangskontroll sikrer at brukere kun ser data de har tilgang til.',
        verifyText: null,
    },
    {
        Icon: ShieldIcon,
        title: 'Isolasjon mellom kunder',
        color: 'text-emerald-600',
        bg: 'bg-emerald-50',
        verify: false,
        body: 'Alle databasespørringer er begrenset til kundens egne data. Det er ikke mulig å se eller aksessere data fra andre kunder.',
        verifyText: null,
    },
    {
        Icon: ServerIcon,
        title: 'Infrastruktur og hosting',
        color: 'text-sky-600',
        bg: 'bg-sky-50',
        verify: true,
        body: 'Applikasjonen kjøres på skyinfrastruktur med høy tilgjengelighet. Produksjonsmiljøet er adskilt fra test- og utviklingsmiljøer.',
        verifyText: 'Hostingleverandør og sertifiseringer (ISO 27001 e.l.) verifiseres',
    },
    {
        Icon: RefreshIcon,
        title: 'Sikkerhetskopiering og gjenoppretting',
        color: 'text-orange-600',
        bg: 'bg-orange-50',
        verify: true,
        body: 'Databasen sikkerhetskopieres regelmessig. Kopier lagres kryptert og er gjenopprettbare ved feil.',
        verifyText: 'Hyppighet og oppbevaringstid verifiseres',
    },
    {
        Icon: EyeIcon,
        title: 'Logging og overvåkning',
        color: 'text-pink-600',
        bg: 'bg-pink-50',
        verify: true,
        body: 'Hendelseslogger og feilsporing gir innsyn i applikasjonsatferd. Logger oppbevares i begrenset tid og er tilgangsbegrenset.',
        verifyText: 'Loggretensjonstid og varslingsverktøy verifiseres',
    },
    {
        Icon: BellIcon,
        title: 'Hendelseshåndtering',
        color: 'text-rose-600',
        bg: 'bg-rose-50',
        verify: false,
        body: 'Vi har definerte prosedyrer for håndtering av sikkerhetsbrudd. Relevante myndigheter varsles innen 72 timer ved brudd på personopplysningssikkerheten, jf. GDPR art. 33.',
        verifyText: null,
    },
    {
        Icon: UsersIcon,
        title: 'Organisatoriske tiltak',
        color: 'text-teal-600',
        bg: 'bg-teal-50',
        verify: true,
        body: 'Ansatte med tilgang til produksjonsdata er underlagt konfidensialitetsforpliktelser. Tilgang tildeles etter behov og revurderes jevnlig.',
        verifyText: 'Internrevisjons- og opplæringsrutiner verifiseres',
    },
];

export default function Security() {
    return (
        <PublicLayout title="Sikkerhet">
            {/* Hero */}
            <div className="mb-10">
                <div className="mb-3 text-sm font-semibold uppercase tracking-widest text-blue-600">Sikkerhet</div>
                <h1 className="text-4xl font-semibold tracking-tight text-slate-950 sm:text-5xl">Slik beskytter vi dataene dine</h1>
                <p className="mt-4 max-w-2xl text-lg leading-8 text-slate-500">
                    Procynia er bygget for å håndtere sensitive forretningsdokumenter og anbudsmateriale. Sikkerhet og personvern er en integrert del av plattformen, ikke en ettertanke.
                </p>
            </div>

            {/* Draft warning */}
            <div className="mb-10 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-800">
                <span className="font-semibold">Utkast</span> – tekniske detaljer er merket med <span className="font-semibold">⚠ verifiseres</span> og må bekreftes av teknisk team før publisering.
            </div>

            {/* Card grid */}
            <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-2">
                {CARDS.map(({ Icon, title, color, bg, body, verify, verifyText }) => (
                    <div key={title} className="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                        <div className={`mb-4 flex h-11 w-11 items-center justify-center rounded-2xl ${bg} ${color}`}>
                            <Icon />
                        </div>
                        <h3 className="mb-2 text-base font-semibold text-slate-900">
                            {title}
                            {verify && <Verify />}
                        </h3>
                        <p className="text-sm leading-6 text-slate-500">{body}</p>
                        {verifyText && (
                            <p className="mt-3 text-xs text-amber-600">⚠ {verifyText}</p>
                        )}
                    </div>
                ))}
            </div>

            {/* Responsible disclosure */}
            <div className="mt-16 rounded-2xl border border-blue-100 bg-blue-50 px-8 py-8">
                <h2 className="mb-3 text-xl font-semibold text-slate-950">Ansvarlig varsling av sikkerhetssårbarheter</h2>
                <p className="text-sm leading-7 text-slate-600">
                    Oppdager du en sårbarhet i Procynia? Vi setter stor pris på ansvarlig varsling. Send en beskrivelse av sårbarheten til{' '}
                    <a href="mailto:sikkerhet@procynia.no" className="text-blue-600 hover:underline">sikkerhet@procynia.no</a>.
                    Vi bekrefter mottak innen 2 virkedager og har som mål å rette kritiske sårbarheter innen 30 dager.
                </p>
                <p className="mt-3 text-sm leading-7 text-slate-600">
                    Vi ber om at du ikke deler informasjonen offentlig før vi har hatt mulighet til å rette forholdet. Misbruk av sårbarheter er ikke tillatt.
                </p>
            </div>

            {/* GDPR note */}
            <div className="mt-6 rounded-2xl border border-slate-100 bg-slate-50 px-8 py-8">
                <h2 className="mb-3 text-xl font-semibold text-slate-950">GDPR og databehandleravtale</h2>
                <p className="text-sm leading-7 text-slate-600">
                    Procynia opptrer som databehandler for personopplysninger i dokumenter og data som kunder laster opp. En databehandleravtale (DPA) inngås som del av kundeforholdet og oppfyller kravene i GDPR artikkel 28.
                </p>
                <p className="mt-3 text-sm leading-7 text-slate-600">
                    Spørsmål om personvern og GDPR rettes til{' '}
                    <a href="mailto:personvern@procynia.no" className="text-blue-600 hover:underline">personvern@procynia.no</a>.
                </p>
            </div>
        </PublicLayout>
    );
}
