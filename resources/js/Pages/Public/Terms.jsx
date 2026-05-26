import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import PublicLayout from '../../Layouts/PublicLayout';

function BackToRegistration() {
    const [show, setShow] = useState(false);
    useEffect(() => {
        setShow(new URLSearchParams(window.location.search).get('fra') === 'registrer');
    }, []);
    if (!show) return null;
    return (
        <div className="mb-8 flex items-center gap-3 rounded-2xl border border-blue-100 bg-blue-50 px-5 py-3.5">
            <button
                type="button"
                onClick={() => window.history.back()}
                className="inline-flex items-center gap-2 text-sm font-semibold text-blue-700 hover:text-blue-900"
            >
                <svg viewBox="0 0 20 20" fill="none" className="h-4 w-4" aria-hidden="true">
                    <path d="M12.5 5 7.5 10l5 5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
                Tilbake til registrering
            </button>
            <span className="text-slate-300">|</span>
            <span className="text-sm text-blue-600">Les gjerne ferdig og trykk tilbake for å fortsette.</span>
        </div>
    );
}

const UPDATED = '24. mai 2026';

function Verify() {
    return (
        <span className="ml-1.5 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[0.7rem] font-semibold text-amber-700">
            ⚠ verifiseres
        </span>
    );
}

function Section({ id, num, title, children }) {
    return (
        <section id={id} className="scroll-mt-24 border-t border-slate-100 pt-10 pb-2">
            <div className="mb-4 flex items-baseline gap-3">
                <span className="font-mono text-xs font-semibold text-blue-400">{num}</span>
                <h2 className="text-lg font-semibold text-slate-950">{title}</h2>
            </div>
            <div className="space-y-3 text-sm leading-7 text-slate-600">
                {children}
            </div>
        </section>
    );
}

function TOC({ items }) {
    return (
        <aside className="hidden lg:block">
            <div className="sticky top-24 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                <p className="mb-3 text-[0.65rem] font-semibold uppercase tracking-widest text-slate-400">
                    Innhold
                </p>
                <nav className="space-y-0.5">
                    {items.map((item) => (
                        <a
                            key={item.id}
                            href={`#${item.id}`}
                            className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs text-slate-500 transition hover:bg-slate-50 hover:text-slate-900"
                        >
                            <span className="w-5 shrink-0 font-mono text-[0.65rem] text-slate-300">
                                {item.num}
                            </span>
                            <span>{item.short}</span>
                        </a>
                    ))}
                </nav>
            </div>
        </aside>
    );
}

const TOC_ITEMS = [
    { id: 'om-vilkarene',      num: '01', short: 'Om vilkårene' },
    { id: 'hvem',              num: '02', short: 'Hvem er det for' },
    { id: 'konto',             num: '03', short: 'Konto og registrering' },
    { id: 'representasjon',    num: '04', short: 'Representasjon' },
    { id: 'abonnement',        num: '05', short: 'Konto og abonnement' },
    { id: 'betaling',          num: '06', short: 'Betaling' },
    { id: 'tillatt',           num: '07', short: 'Tillatt bruk' },
    { id: 'forbudt',           num: '08', short: 'Forbudt bruk' },
    { id: 'ai',                num: '09', short: 'AI og brukeransvar' },
    { id: 'kundedata',         num: '10', short: 'Kundedata' },
    { id: 'ip',                num: '11', short: 'IP-rettigheter' },
    { id: 'underleverandorer', num: '12', short: 'Underleverandører' },
    { id: 'tilgjengelighet',   num: '13', short: 'Tilgjengelighet' },
    { id: 'endringer',         num: '14', short: 'Endringer' },
    { id: 'oppsigelse',        num: '15', short: 'Oppsigelse' },
    { id: 'eksport',           num: '16', short: 'Dataeksport' },
    { id: 'ansvar',            num: '17', short: 'Ansvarsbegrensning' },
    { id: 'force',             num: '18', short: 'Force majeure' },
    { id: 'lovvalg',           num: '19', short: 'Lovvalg' },
];

export default function Terms() {
    return (
        <PublicLayout title="Brukervilkår">
            <BackToRegistration />
            <div className="max-w-3xl">
                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600">
                    Juridisk
                </p>
                <h1 className="mt-2 text-4xl font-semibold tracking-tight text-slate-950 sm:text-5xl">
                    Brukervilkår
                </h1>
                <p className="mt-4 text-lg leading-8 text-slate-600">
                    Disse vilkårene regulerer tilgangen til og bruken av Procynia. Les dem nøye
                    før du tar tjenesten i bruk på vegne av din virksomhet.
                </p>
                <p className="mt-3 text-sm text-slate-400">Sist oppdatert: {UPDATED}</p>
            </div>

            <div className="mt-8 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-900">
                <strong className="font-semibold">Utkast – må juridisk kvalitetssikres før publisering.</strong>{' '}
                Punkter merket{' '}
                <span className="inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[0.7rem] font-semibold text-amber-700">
                    ⚠ verifiseres
                </span>{' '}
                forutsetter teknisk eller juridisk avklaring.
            </div>

            <div className="mt-12 grid grid-cols-1 gap-8 lg:grid-cols-[220px_1fr]">
                <TOC items={TOC_ITEMS} />

                <div>
                    <Section id="om-vilkarene" num="01" title="Om disse vilkårene">
                        <p>
                            Disse brukervilkårene regulerer avtaleforholdet mellom din virksomhet og
                            [Procynia AS / org.nr. settes inn] («Procynia»). Ved å opprette konto eller
                            ta tjenesten i bruk aksepterer du vilkårene på vegne av virksomheten du
                            representerer.
                        </p>
                        <p>
                            Dersom du ikke har nødvendig fullmakt til å binde virksomheten, skal du ikke
                            bruke tjenesten. Procynia forbeholder seg retten til å oppdatere disse
                            vilkårene. Vesentlige endringer varsles med rimelig frist.
                        </p>
                    </Section>

                    <Section id="hvem" num="02" title="Hvem tjenesten er for">
                        <p>
                            Procynia er utviklet for virksomheter som arbeider profesjonelt med anbud,
                            anskaffelser, kravarbeid og dokumenthåndtering. Tjenesten er ikke beregnet
                            på privatpersoner.
                        </p>
                        <p>
                            For å opprette konto må du representere en registrert virksomhet og handle i
                            egenskap av din rolle der. Brukerkontoer er tilknyttet virksomheten, ikke den
                            enkelte ansatte.
                        </p>
                    </Section>

                    <Section id="konto" num="03" title="Kontoetablering og registrering">
                        <p>
                            For å bruke Procynia må du opprette en konto med fullstendige og korrekte
                            opplysninger om deg selv og virksomheten. Du er ansvarlig for å holde
                            opplysningene oppdaterte.
                        </p>
                        <p>
                            Påloggingsinformasjon er personlig og skal ikke deles med andre. Virksomheten
                            er ansvarlig for alle handlinger som gjennomføres under kontoen, uavhengig av
                            hvem som faktisk foretar handlingen.
                        </p>
                    </Section>

                    <Section id="representasjon" num="04" title="Virksomhetsrepresentasjon">
                        <p>
                            Konto opprettes av en navngitt kontaktperson som tildeles rollen System Owner.
                            Du bekrefter ved registrering at du har nødvendig fullmakt til å inngå avtale
                            på vegne av virksomheten.
                        </p>
                        <p>
                            System Owner administrerer øvrige brukere og tilgangsrettigheter via
                            rettighetsstyringen i tjenesten. Ansvaret for korrekt brukeradministrasjon
                            hviler på virksomheten.
                        </p>
                    </Section>

                    <Section id="abonnement" num="05" title="Gratis konto og abonnement">
                        <p>
                            Du kan opprette en konto uten kostnad. Betalte funksjoner, herunder
                            AI-støtte, aktiveres først etter at et abonnement er inngått og betalt.
                        </p>
                        <p>
                            Abonnement velges i et eget steg etter kontoetablering. Procynia forbeholder
                            seg retten til å justere abonnementspriser og -betingelser med rimelig
                            forhåndsvarsel til eksisterende kunder.
                        </p>
                    </Section>

                    <Section id="betaling" num="06" title="Betaling og fakturering">
                        <p>
                            Abonnementet faktureres i henhold til valgt plan og betalingssyklus. Betaling
                            gjennomføres via betalingsmetoden som er registrert på kontoen. Forfalt
                            betaling som ikke gjøres opp kan medføre midlertidig suspensjon av betalte
                            funksjoner.
                        </p>
                        <p>
                            Detaljer om kanselleringsbetingelser og eventuelle refusjoner fremgår av
                            planvilkårene som presenteres ved abonnementstegning.{' '}
                            <Verify />
                        </p>
                    </Section>

                    <Section id="tillatt" num="07" title="Tillatt bruk">
                        <p>
                            Du kan bruke Procynia til å administrere anbud, anskaffelser, krav,
                            dokumentasjon og tilhørende arbeidsprosesser innenfor din virksomhet. Bruk
                            av tjenesten er tillatt i den utstrekning den er i tråd med norsk lov og
                            disse vilkårene.
                        </p>
                    </Section>

                    <Section id="forbudt" num="08" title="Forbudt bruk">
                        <p>Det er ikke tillatt å:</p>
                        <ul className="mt-2 space-y-1.5 pl-5 list-disc marker:text-slate-300">
                            <li>benytte tjenesten til ulovlige formål eller i strid med disse vilkårene</li>
                            <li>dele tilgang med uautoriserte parter utenfor din virksomhet</li>
                            <li>laste opp innhold som krenker tredjeparts rettigheter eller inneholder skadelig kode</li>
                            <li>forsøke å omgå tilgangsstyring, sikkerhetstiltak eller autentiseringsmekanismer</li>
                            <li>gjennomføre reverse engineering av tjenestens kode, algoritmer eller forretningslogikk</li>
                            <li>benytte tjenesten som grunnlag for utvikling av konkurrerende løsninger</li>
                            <li>misbruke AI-funksjonalitet til å generere villedende, ulovlig eller skadelig innhold</li>
                        </ul>
                    </Section>

                    <Section id="ai" num="09" title="AI-støtte og brukeransvar">
                        <p>
                            Procynia tilbyr AI-støttet analyse, kravekstrahering og innholdsgenerering
                            som hjelpemiddel i tilbud- og anskaffelsesprosesser. All AI-generert output
                            er et arbeidsmateriale som krever faglig gjennomgang og godkjenning av ansvarlig
                            person i din virksomhet.
                        </p>
                        <p>
                            Du er selv ansvarlig for å vurdere, korrigere og godkjenne alt innhold som
                            brukes i tilbud, dokumenter og bindende forpliktelser. Procynia erstatter ikke
                            juridisk, faglig eller kommersiell rådgivning, og tar ikke ansvar for
                            beslutninger fattet på grunnlag av AI-generert innhold uten tilstrekkelig
                            menneskelig kontroll.
                        </p>
                    </Section>

                    <Section id="kundedata" num="10" title="Kundedata og konfidensialitet">
                        <p>
                            Procynia behandler dataene din virksomhet laster inn utelukkende for å levere
                            tjenesten til deg. Data fra én kunde er ikke tilgjengelig for andre kunder i
                            systemet, og kundedata benyttes ikke til å trene eller forbedre generelle
                            AI-modeller.
                        </p>
                        <p>
                            Se{' '}
                            <Link href="/personvern" className="text-blue-600 hover:underline">
                                personvernerklæringen
                            </Link>{' '}
                            for fullstendig beskrivelse av databehandlingen.
                        </p>
                    </Section>

                    <Section id="ip" num="11" title="Immaterielle rettigheter">
                        <p>
                            Tjenestens kode, design, merkenavn, metodikk og øvrig innhold utviklet av
                            Procynia tilhører Procynia og er beskyttet etter gjeldende rett om
                            immaterielle rettigheter.
                        </p>
                        <p>
                            Du gis en begrenset, ikke-eksklusiv og ikke-overførbar rett til å bruke
                            tjenesten i henhold til disse vilkårene. Dokumenter, data og innhold som din
                            virksomhet laster opp, forblir din virksomhets eiendom.
                        </p>
                    </Section>

                    <Section id="underleverandorer" num="12" title="Underleverandører">
                        <p>
                            Procynia kan benytte underleverandører for å levere deler av tjenesten,
                            herunder leverandører av skyinfrastruktur, betalingsløsning og AI-tjenester.
                            Alle underleverandører er bundet av databehandleravtaler og
                            konfidensialitetsforpliktelser tilsvarende Procynias egne.
                        </p>
                        <p>
                            Oppdatert oversikt over underleverandører vil bli publisert på en dedikert
                            informasjonsside.{' '}
                            <Verify />
                        </p>
                    </Section>

                    <Section id="tilgjengelighet" num="13" title="Tilgjengelighet og vedlikehold">
                        <p>
                            Procynia tilstreber høy tilgjengelighet, men kan ikke garantere avbruddfri
                            drift. Planlagte vedlikeholdsarbeider varsles i rimelig tid via e-post eller
                            melding i tjenesten.
                        </p>
                        <p>
                            Konkrete SLA-forpliktelser per abonnementsnivå beskrives ved lansering av
                            aktuelle planer.{' '}
                            <Verify />
                        </p>
                    </Section>

                    <Section id="endringer" num="14" title="Endringer i tjenesten">
                        <p>
                            Procynia kan endre, videreutvikle eller avvikle deler av tjenesten. Vesentlige
                            endringer som påvirker eksisterende bruk, varsles med rimelig frist. Fortsatt
                            bruk etter varslingsperioden anses som aksept av endringene.
                        </p>
                    </Section>

                    <Section id="oppsigelse" num="15" title="Oppsigelse">
                        <p>
                            Du kan si opp abonnementet i tråd med valgt plans oppsigelsesvilkår. Procynia
                            kan si opp tilgangen til tjenesten med rimelig varsel dersom du bryter disse
                            vilkårene eller ved misbruk.
                        </p>
                        <p>
                            Ved oppsigelse beholdes kontodata i en begrenset periode og slettes deretter.
                            Eksakt lagringsperiode etter opphør fastsettes ved juridisk gjennomgang.{' '}
                            <Verify />
                        </p>
                    </Section>

                    <Section id="eksport" num="16" title="Dataeksport">
                        <p>
                            Din virksomhet kan eksportere egne data fra tjenesten via eksportfunksjonene
                            i kontomiljøet. Tilgjengelige formater og prosedyrer beskrives i tjenestens
                            dokumentasjon.{' '}
                            <Verify />
                        </p>
                    </Section>

                    <Section id="ansvar" num="17" title="Ansvarsbegrensning">
                        <p>
                            Procynias samlede ansvar overfor din virksomhet er begrenset til direkte tap
                            og kan ikke overstige det beløp du faktisk har betalt for tjenesten i de 12
                            månedene som forutgår skadehendelsen.
                        </p>
                        <p>
                            Procynia er ikke ansvarlig for indirekte tap, følgeskader, tapt fortjeneste,
                            tapte data eller tredjepartstap. Ansvarsbegrensningene gjelder ikke ved grov
                            uaktsomhet eller forsett fra Procynias side.
                        </p>
                    </Section>

                    <Section id="force" num="18" title="Force majeure">
                        <p>
                            Ingen av partene er ansvarlig for forsinkelse eller manglende oppfyllelse av
                            forpliktelser som skyldes forhold utenfor rimelig kontroll, herunder strømbrudd,
                            naturhendelser, myndighetsvedtak, cyberangrep mot ekstern infrastruktur eller
                            svikt hos nødvendige underleverandører.
                        </p>
                    </Section>

                    <Section id="lovvalg" num="19" title="Lovvalg og tvisteløsning">
                        <p>
                            Disse vilkårene er underlagt norsk rett. Tvister som oppstår i forbindelse
                            med avtaleforholdet, skal i første omgang søkes løst i minnelighet mellom
                            partene.
                        </p>
                        <p>
                            Dersom en tvist ikke kan løses i minnelighet, avgjøres den av norske domstoler
                            med verneting i [Procynias hjemsted].{' '}
                            <Verify />
                        </p>
                    </Section>
                </div>
            </div>
        </PublicLayout>
    );
}
