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

function Verify() {
    return (
        <span className="ml-2 inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200">
            ⚠ verifiseres
        </span>
    );
}

function Section({ id, num, title, children }) {
    return (
        <section id={id} className="scroll-mt-24 border-t border-slate-100 pt-10">
            <div className="mb-5 flex items-baseline gap-3">
                <span className="shrink-0 text-xs font-semibold uppercase tracking-widest text-slate-400">§{num}</span>
                <h2 className="text-xl font-semibold tracking-tight text-slate-950">{title}</h2>
            </div>
            <div className="space-y-4 text-sm leading-7 text-slate-600">{children}</div>
        </section>
    );
}

function TOC({ items }) {
    return (
        <aside className="hidden lg:block">
            <div className="sticky top-24 space-y-1">
                <p className="mb-3 text-xs font-semibold uppercase tracking-widest text-slate-400">Innhold</p>
                {items.map((item) => (
                    <a
                        key={item.id}
                        href={`#${item.id}`}
                        className="block rounded-lg px-3 py-1.5 text-sm text-slate-500 transition hover:bg-slate-100 hover:text-slate-950"
                    >
                        <span className="mr-2 text-xs text-slate-400">§{item.num}</span>
                        {item.short}
                    </a>
                ))}
            </div>
        </aside>
    );
}

const TOC_ITEMS = [
    { id: 'behandlingsansvarlig', num: 1, short: 'Behandlingsansvarlig' },
    { id: 'hvem-gjelder', num: 2, short: 'Hvem erklæringen gjelder' },
    { id: 'hva-vi-samler', num: 3, short: 'Opplysninger vi behandler' },
    { id: 'formal-grunnlag', num: 4, short: 'Formål og rettslig grunnlag' },
    { id: 'ai-behandling', num: 5, short: 'AI og dokumentbehandling' },
    { id: 'underleverandorer', num: 6, short: 'Underleverandører' },
    { id: 'overforing', num: 7, short: 'Overføring utenfor EU/EØS' },
    { id: 'lagringstid', num: 8, short: 'Lagringstid' },
    { id: 'sikkerhet', num: 9, short: 'Sikkerhetstiltak' },
    { id: 'rettigheter', num: 10, short: 'Dine rettigheter' },
    { id: 'klage', num: 11, short: 'Klage til Datatilsynet' },
    { id: 'endringer', num: 12, short: 'Endringer' },
];

export default function Privacy() {
    return (
        <PublicLayout title="Personvernerklæring">
            <BackToRegistration />
            {/* Hero */}
            <div className="mb-10">
                <div className="mb-3 text-sm font-semibold uppercase tracking-widest text-blue-600">Juridisk</div>
                <h1 className="text-4xl font-semibold tracking-tight text-slate-950 sm:text-5xl">Personvernerklæring</h1>
                <p className="mt-4 max-w-2xl text-lg leading-8 text-slate-500">
                    Denne erklæringen beskriver hvordan Procynia AS behandler personopplysninger i tråd med EUs personvernforordning (GDPR) og norsk personopplysningslov.
                </p>
                <p className="mt-2 text-sm text-slate-400">Sist oppdatert: 24. mai 2026 · Versjon 0.1</p>
            </div>

            {/* Draft warning */}
            <div className="mb-10 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-800">
                <span className="font-semibold">Utkast</span> – må juridisk kvalitetssikres før publisering. Innholdet er ikke bindende i nåværende form.
            </div>

            {/* Grid: TOC + content */}
            <div className="grid grid-cols-1 gap-10 lg:grid-cols-[220px_1fr] lg:gap-16">
                <TOC items={TOC_ITEMS} />

                <div className="space-y-12">
                    <Section id="behandlingsansvarlig" num={1} title="Behandlingsansvarlig">
                        <p>
                            Behandlingsansvarlig for personopplysningene som behandles i Procynia-plattformen er:
                        </p>
                        <div className="rounded-xl border border-slate-100 bg-slate-50 px-5 py-4 font-mono text-xs leading-6 text-slate-700">
                            Procynia AS<br />
                            Org.nr.: [SETT INN ORG.NR.]<br />
                            Adresse: [SETT INN ADRESSE]<br />
                            E-post: personvern@procynia.no<br />
                            Nettsted: procynia.no
                        </div>
                        <p>
                            Spørsmål om behandling av personopplysninger kan rettes til{' '}
                            <a href="mailto:personvern@procynia.no" className="text-blue-600 hover:underline">personvern@procynia.no</a>.
                        </p>
                    </Section>

                    <Section id="hvem-gjelder" num={2} title="Hvem erklæringen gjelder for">
                        <p>
                            Denne personvernerklæringen gjelder for:
                        </p>
                        <ul className="list-disc space-y-2 pl-5">
                            <li>Brukere som oppretter konto og logger inn i Procynia (inkl. System Owners, bid managers og contributors)</li>
                            <li>Kontaktpersoner i kundeorganisasjoner som benytter Procynia på vegne av sin arbeidsgiver</li>
                            <li>Besøkende på procynia.no og underdomener</li>
                        </ul>
                        <p>
                            Erklæringen gjelder ikke for opplysninger om juridiske enheter (bedrifter), kun for fysiske personer.
                        </p>
                    </Section>

                    <Section id="hva-vi-samler" num={3} title="Personopplysninger vi behandler">
                        <p>Vi behandler følgende kategorier av personopplysninger:</p>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-slate-200">
                                        <th className="pb-3 pr-6 font-semibold text-slate-700">Kategori</th>
                                        <th className="pb-3 font-semibold text-slate-700">Eksempler</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {[
                                        ['Identifikasjonsdata', 'Navn, e-postadresse, rolle i organisasjon'],
                                        ['Kontaktdata', 'E-postadresse, telefonnummer (valgfritt)'],
                                        ['Påloggingsdata', 'Kryptert passord, innloggingstidspunkter, IP-adresse'],
                                        ['Bruksdata', 'Sidevisninger, funksjonsbruk, klikk (anonymisert)'],
                                        ['Innhold lastet opp', 'Dokumenter og filer du laster opp til kunnskapsbasen eller anbud'],
                                        ['Kommunikasjonsdata', 'E-poster og meldinger sendt til support'],
                                    ].map(([cat, ex]) => (
                                        <tr key={cat}>
                                            <td className="py-2.5 pr-6 font-medium text-slate-700">{cat}</td>
                                            <td className="py-2.5 text-slate-500">{ex}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p>
                            Vi samler ikke inn særlige kategorier av personopplysninger (sensitive opplysninger som helse, politisk overbevisning o.l.) med mindre du frivillig legger det inn i dokumenter du selv laster opp.
                        </p>
                    </Section>

                    <Section id="formal-grunnlag" num={4} title="Formål og rettslig grunnlag">
                        <p>Vi behandler personopplysninger basert på følgende rettslige grunnlag etter GDPR artikkel 6:</p>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-slate-200">
                                        <th className="pb-3 pr-6 font-semibold text-slate-700">Formål</th>
                                        <th className="pb-3 pr-6 font-semibold text-slate-700">Grunnlag</th>
                                        <th className="pb-3 font-semibold text-slate-700">Hjemmel</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {[
                                        ['Levere og drifte tjenesten', 'Avtaleutførelse', 'Art. 6(1)(b)'],
                                        ['Brukerautentisering og sikkerhet', 'Avtaleutførelse / berettiget interesse', 'Art. 6(1)(b)/(f)'],
                                        ['Fakturering og betalingshåndtering', 'Avtaleutførelse / rettslig forpliktelse', 'Art. 6(1)(b)/(c)'],
                                        ['Produktforbedring og feilsøking', 'Berettiget interesse', 'Art. 6(1)(f)'],
                                        ['Markedskommunikasjon til eksisterende kunder', 'Berettiget interesse', 'Art. 6(1)(f)'],
                                        ['Markedskommunikasjon til nye kontakter', 'Samtykke', 'Art. 6(1)(a)'],
                                        ['Overholdelse av lovpålagte krav', 'Rettslig forpliktelse', 'Art. 6(1)(c)'],
                                    ].map(([purpose, basis, art]) => (
                                        <tr key={purpose}>
                                            <td className="py-2.5 pr-6 text-slate-700">{purpose}</td>
                                            <td className="py-2.5 pr-6 text-slate-500">{basis}</td>
                                            <td className="py-2.5 font-mono text-xs text-slate-400">{art}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Section>

                    <Section id="ai-behandling" num={5} title="AI og dokumentbehandling">
                        <p>
                            Procynia bruker kunstig intelligens (AI) for å analysere anbud og dokumenter du laster opp. Dette innebærer at innholdet i dokumentene sendes til en tredjeparts AI-leverandør for prosessering. Se §6 for informasjon om underleverandører.
                        </p>
                        <p>
                            AI-systemet genererer kravanalyser og svanutkast basert på innholdet i dokumentene. All AI-generert tekst er merket som maskinprodusert og skal kvalitetssikres av brukerne. Procynia lagrer ikke dokumentinnhold permanent hos AI-leverandøren.
                            <Verify />
                        </p>
                        <p>
                            Dersom dokumenter inneholder personopplysninger, er kunden behandlingsansvarlig for disse opplysningene, og Procynia handler som databehandler. En databehandleravtale (DPA) inngås som en del av kundeforholdet.
                        </p>
                    </Section>

                    <Section id="underleverandorer" num={6} title="Underleverandører og databehandlere">
                        <p>
                            Procynia benytter følgende kategorier av underleverandører som kan behandle personopplysninger på vegne av oss:
                        </p>
                        <ul className="list-disc space-y-2 pl-5">
                            <li><span className="font-medium text-slate-700">Skyinfrastruktur og hosting</span> – lagring av applikasjonsdata og databaser</li>
                            <li><span className="font-medium text-slate-700">AI-modeller</span> – prosessering av dokumentinnhold for kravanalyse og svargenerering <Verify /></li>
                            <li><span className="font-medium text-slate-700">E-posttjeneste</span> – transaksjons- og varslingse-post</li>
                            <li><span className="font-medium text-slate-700">Betalingsbehandler</span> – fakturering og abonnementshåndtering</li>
                            <li><span className="font-medium text-slate-700">Analyse og feilsporing</span> – anonym bruksstatistikk og feillogging <Verify /></li>
                        </ul>
                        <p>
                            En oppdatert liste over underleverandører er tilgjengelig på forespørsel. Vi inngår databehandleravtaler med alle underleverandører som behandler personopplysninger.
                        </p>
                    </Section>

                    <Section id="overforing" num={7} title="Overføring utenfor EU/EØS">
                        <p>
                            Deler av vår infrastruktur og AI-tjenester er lokalisert utenfor EU/EØS. Overføring skjer kun til land som EU-kommisjonen har godkjent som trygge, eller på grunnlag av standard kontraktsklausuler (SCCs) godkjent av EU-kommisjonen.
                            <Verify />
                        </p>
                        <p>
                            Du kan få informasjon om hvilke overføringer som gjelder og hvilke beskyttelsesmekanismer som er brukt ved å kontakte oss på{' '}
                            <a href="mailto:personvern@procynia.no" className="text-blue-600 hover:underline">personvern@procynia.no</a>.
                        </p>
                    </Section>

                    <Section id="lagringstid" num={8} title="Lagringstid">
                        <p>Vi lagrer personopplysninger kun så lenge det er nødvendig for formålet de ble samlet inn for:</p>
                        <ul className="list-disc space-y-2 pl-5">
                            <li><span className="font-medium text-slate-700">Kontodata</span> – så lenge kontoen er aktiv, pluss 30 dager etter sletting</li>
                            <li><span className="font-medium text-slate-700">Innhold og dokumenter</span> – slettes ved kontosletting, med mindre lengre oppbevaring er påkrevd av lov</li>
                            <li><span className="font-medium text-slate-700">Fakturerings- og regnskapsdata</span> – 5 år etter regnskapsårets slutt i henhold til bokføringsloven</li>
                            <li><span className="font-medium text-slate-700">Supportkorrespondanse</span> – 2 år etter avsluttet sak</li>
                            <li><span className="font-medium text-slate-700">Innloggings- og sikkerhetslogger</span> – 90 dager <Verify /></li>
                        </ul>
                    </Section>

                    <Section id="sikkerhet" num={9} title="Sikkerhetstiltak">
                        <p>
                            Procynia bruker tekniske og organisatoriske tiltak for å beskytte personopplysningene dine. Se{' '}
                            <Link href="/sikkerhet" className="text-blue-600 hover:underline">sikkerhetssiden</Link>{' '}
                            for en detaljert oversikt over våre sikkerhetstiltak.
                        </p>
                        <p>
                            Tiltak inkluderer blant annet: kryptering under overføring (TLS), kryptering i ro, tilgangskontroll og rolleseparasjon, og rutiner for håndtering av sikkerhetsbrudd.
                            <Verify />
                        </p>
                        <p>
                            Ved et sikkerhetsbrudd som berører personopplysninger vil vi varsle relevante tilsynsmyndigheter innen 72 timer og berørte registrerte uten ugrunnet opphold, i henhold til GDPR artikkel 33–34.
                        </p>
                    </Section>

                    <Section id="rettigheter" num={10} title="Dine rettigheter">
                        <p>Som registrert har du følgende rettigheter etter GDPR:</p>
                        <div className="space-y-3">
                            {[
                                ['Innsyn (art. 15)', 'Du kan be om en kopi av opplysningene vi har om deg.'],
                                ['Retting (art. 16)', 'Du kan be om at uriktige eller ufullstendige opplysninger rettes.'],
                                ['Sletting (art. 17)', 'Du kan be om at opplysninger om deg slettes («retten til å bli glemt»), med forbehold om lovpålagte lagringsplikt.'],
                                ['Begrensning (art. 18)', 'Du kan be om at behandlingen begrenses i visse situasjoner.'],
                                ['Dataportabilitet (art. 20)', 'Du kan be om å få utlevert opplysningene dine i et maskinlesbart format.'],
                                ['Innsigelse (art. 21)', 'Du kan protestere mot behandling basert på berettiget interesse.'],
                                ['Trekke tilbake samtykke', 'Dersom behandling er basert på samtykke, kan du trekke det tilbake når som helst.'],
                            ].map(([right, desc]) => (
                                <div key={right} className="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                                    <p className="font-medium text-slate-800">{right}</p>
                                    <p className="mt-0.5 text-slate-500">{desc}</p>
                                </div>
                            ))}
                        </div>
                        <p>
                            Send forespørsler om rettighetsutøvelse til{' '}
                            <a href="mailto:personvern@procynia.no" className="text-blue-600 hover:underline">personvern@procynia.no</a>.
                            Vi besvarer henvendelser innen 30 dager.
                        </p>
                    </Section>

                    <Section id="klage" num={11} title="Klage til Datatilsynet">
                        <p>
                            Dersom du mener at vår behandling av personopplysningene dine er i strid med personopplysningsloven eller GDPR, har du rett til å klage til Datatilsynet.
                        </p>
                        <div className="rounded-xl border border-slate-100 bg-slate-50 px-5 py-4 font-mono text-xs leading-6 text-slate-700">
                            Datatilsynet<br />
                            Postboks 458 Sentrum, 0105 Oslo<br />
                            Telefon: 22 39 69 00<br />
                            E-post: postkasse@datatilsynet.no<br />
                            Nettsted: datatilsynet.no
                        </div>
                        <p>
                            Vi oppfordrer deg til å kontakte oss direkte før du sender klage til Datatilsynet, slik at vi kan løse saken raskt.
                        </p>
                    </Section>

                    <Section id="endringer" num={12} title="Endringer i erklæringen">
                        <p>
                            Vi kan oppdatere denne erklæringen når tjenesten eller regelverket endres. Ved vesentlige endringer varsler vi registrerte via e-post og/eller synlig melding i tjenesten, med rimelig frist.
                        </p>
                        <p>
                            Gjeldende versjon er alltid tilgjengelig på{' '}
                            <Link href="/personvern" className="text-blue-600 hover:underline">procynia.no/personvern</Link>.
                            Dato for siste endring fremgår øverst i dokumentet.
                        </p>
                        <p>
                            Spørsmål om denne erklæringen rettes til{' '}
                            <a href="mailto:personvern@procynia.no" className="text-blue-600 hover:underline">personvern@procynia.no</a>.
                        </p>
                    </Section>
                </div>
            </div>
        </PublicLayout>
    );
}
