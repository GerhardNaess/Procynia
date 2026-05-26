import { useState } from 'react';
import { Link } from '@inertiajs/react';
import PublicLayout from '../../Layouts/PublicLayout';

function ChevronIcon({ open }) {
    return (
        <svg
            viewBox="0 0 20 20"
            fill="none"
            className={`h-5 w-5 shrink-0 text-slate-400 transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
            aria-hidden="true"
        >
            <path d="m5 7.5 5 5 5-5" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function FaqItem({ question, children }) {
    const [open, setOpen] = useState(false);

    return (
        <div className="border-b border-slate-100 last:border-0">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="flex w-full items-center justify-between gap-4 py-5 text-left"
                aria-expanded={open}
            >
                <span className="text-sm font-semibold text-slate-900">{question}</span>
                <ChevronIcon open={open} />
            </button>
            {open && (
                <div className="pb-5 text-sm leading-7 text-slate-500">{children}</div>
            )}
        </div>
    );
}

function FaqGroup({ title, children }) {
    return (
        <div className="mb-12">
            <h2 className="mb-4 text-xs font-semibold uppercase tracking-widest text-slate-400">{title}</h2>
            <div className="rounded-2xl border border-slate-100 bg-white px-6 shadow-sm">
                {children}
            </div>
        </div>
    );
}

export default function Faq() {
    return (
        <PublicLayout title="Ofte stilte spørsmål">
            {/* Hero */}
            <div className="mb-12 text-center">
                <div className="mb-3 text-sm font-semibold uppercase tracking-widest text-blue-600">FAQ</div>
                <h1 className="text-4xl font-semibold tracking-tight text-slate-950 sm:text-5xl">Ofte stilte spørsmål</h1>
                <p className="mx-auto mt-4 max-w-xl text-lg leading-8 text-slate-500">
                    Finner du ikke svaret? Send oss en e-post på{' '}
                    <a href="mailto:hei@procynia.no" className="text-blue-600 hover:underline">hei@procynia.no</a>.
                </p>
            </div>

            <div className="mx-auto max-w-2xl">

                <FaqGroup title="Kom i gang">
                    <FaqItem question="Hva er Procynia?">
                        <p>
                            Procynia er en norsk plattform for smarte anskaffelser og anbudshåndtering. Vi hjelper bedrifter med å finne relevante anbud fra Doffin, organisere anbudsprosessen, og bruke AI til å analysere kravdokumenter og generere svanutkast basert på din egen kunnskapsbase.
                        </p>
                    </FaqItem>
                    <FaqItem question="Hvem passer Procynia for?">
                        <p>
                            Procynia passer for bedrifter som jevnlig svarer på offentlige anbud. Typiske brukere er bid managers, selgere, presales-ressurser og fageksperter som bidrar i anbudsprosesser. Plattformen er utviklet for norske virksomheter, men støtter også engelsk.
                        </p>
                    </FaqItem>
                    <FaqItem question="Hvordan oppretter jeg konto?">
                        <p>
                            Gå til{' '}
                            <Link href="/registrer" className="text-blue-600 hover:underline">procynia.no/registrer</Link>{' '}
                            og fyll inn informasjon om din bedrift. Kontoen er gratis å opprette – du velger abonnement etter at kontoen er etablert. Det kreves ingen kredittkort for registrering.
                        </p>
                    </FaqItem>
                    <FaqItem question="Kan jeg prøve Procynia gratis?">
                        <p>
                            Ja. Du kan opprette konto og utforske plattformen uten å binde deg til et abonnement. Abonnement velges etter kontoetablering. Ta kontakt på{' '}
                            <a href="mailto:hei@procynia.no" className="text-blue-600 hover:underline">hei@procynia.no</a>{' '}
                            for mer informasjon om tilgjengelige planer.
                        </p>
                    </FaqItem>
                </FaqGroup>

                <FaqGroup title="Abonnement og priser">
                    <FaqItem question="Hva koster Procynia?">
                        <p>
                            Vi tilbyr ulike abonnementsplaner tilpasset teamstørrelse og bruksbehov. Se{' '}
                            <Link href="/priser" className="text-blue-600 hover:underline">prissiden</Link>{' '}
                            for oppdatert prisinformasjon.
                        </p>
                    </FaqItem>
                    <FaqItem question="Kan jeg avbestille abonnementet når som helst?">
                        <p>
                            Ja. Du kan si opp abonnementet når som helst. Abonnementet løper ut inneværende periode, og du beholder tilgang frem til periodens slutt. Det er ingen bindingstid utover valgt abonnementsperiode.
                        </p>
                    </FaqItem>
                    <FaqItem question="Tilbyr dere rabatt for større team eller offentlige virksomheter?">
                        <p>
                            Ja, vi tilbyr volumrabatter og tilpassede avtaler for større organisasjoner. Kontakt oss på{' '}
                            <a href="mailto:salg@procynia.no" className="text-blue-600 hover:underline">salg@procynia.no</a>{' '}
                            for et tilbud.
                        </p>
                    </FaqItem>
                </FaqGroup>

                <FaqGroup title="AI og kunnskapsbase">
                    <FaqItem question="Hvordan fungerer AI-funksjonen?">
                        <p>
                            Du laster opp anbudsdokumenter (kravspesifikasjoner, konkurransegrunnlag o.l.), og Procynia bruker AI til å identifisere og strukturere krav. For hvert krav henter systemet relevant materiale fra din kunnskapsbase og genererer et svanutkast som er forankret i dine egne dokumenter.
                        </p>
                        <p className="mt-2">
                            AI-output er alltid merket som maskinprodusert og skal kvalitetssikres av ansvarlig person før bruk i et anbud.
                        </p>
                    </FaqItem>
                    <FaqItem question="Hva kan jeg legge i kunnskapsbasen?">
                        <p>
                            Du kan laste opp Word-dokumenter (.docx), PDF-filer og Excel-ark (.xlsx). Typisk innhold er tjenestebeskrivelser, standardtekster, policydokumenter, CV-er, referanser og annet gjenbrukbart anbudsmateriale. Maksimal filstørrelse er 20 MB per fil.
                        </p>
                    </FaqItem>
                    <FaqItem question="Er AI-svarene pålitelige?">
                        <p>
                            AI-svarene er forankret i dine egne dokumenter – systemet viser hvilke deler av kunnskapsbasen som er brukt som kilde. Dette gjør det mulig å spore og verifisere all output. Procynia er bygget etter prinsippet om at AI støtter struktur, men ikke erstatter menneskelig vurdering.
                        </p>
                        <p className="mt-2">
                            Systemet skiller tydelig mellom innhold som er dokumentert, faglig inferert og manglende dokumentasjon.
                        </p>
                    </FaqItem>
                    <FaqItem question="Brukes innholdet mitt til å trene AI-modeller?">
                        <p>
                            Nei. Innholdet du laster opp brukes kun til å levere tjenesten til deg. Vi deler ikke kundedata med tredjepart for opplæringsformål. Se{' '}
                            <Link href="/personvern" className="text-blue-600 hover:underline">personvernerklæringen</Link>{' '}
                            og{' '}
                            <Link href="/betingelser" className="text-blue-600 hover:underline">brukervilkårene</Link>{' '}
                            for detaljer.
                        </p>
                    </FaqItem>
                </FaqGroup>

                <FaqGroup title="Sikkerhet og personvern">
                    <FaqItem question="Er dataene mine trygge?">
                        <p>
                            Ja. All data er kryptert under overføring (TLS) og i ro. Tilgangskontroll sikrer at brukere kun har tilgang til sin kundes data. Se{' '}
                            <Link href="/sikkerhet" className="text-blue-600 hover:underline">sikkerhetssiden</Link>{' '}
                            for en fullstendig oversikt over tiltak.
                        </p>
                    </FaqItem>
                    <FaqItem question="Er Procynia GDPR-kompatibel?">
                        <p>
                            Ja. Procynia opptrer som databehandler for personopplysninger i kundedata, og vi inngår databehandleravtale (DPA) som en del av kundeforholdet. Se{' '}
                            <Link href="/personvern" className="text-blue-600 hover:underline">personvernerklæringen</Link>{' '}
                            for detaljer om behandlingsgrunnlag og rettigheter.
                        </p>
                    </FaqItem>
                    <FaqItem question="Kan jeg slette kontoen og dataene mine?">
                        <p>
                            Ja. Du kan be om sletting av konto og tilhørende data ved å kontakte{' '}
                            <a href="mailto:personvern@procynia.no" className="text-blue-600 hover:underline">personvern@procynia.no</a>.
                            Data slettes innen 30 dager, med unntak av data vi er lovpålagt å oppbevare (som regnskapsdata).
                        </p>
                    </FaqItem>
                </FaqGroup>

                <FaqGroup title="Support og kontakt">
                    <FaqItem question="Hvordan får jeg hjelp?">
                        <p>
                            Send oss en e-post på{' '}
                            <a href="mailto:support@procynia.no" className="text-blue-600 hover:underline">support@procynia.no</a>.
                            Vi svarer normalt innen 1–2 virkedager.
                        </p>
                    </FaqItem>
                    <FaqItem question="Tilbyr dere onboarding eller opplæring?">
                        <p>
                            Ja. Vi tilbyr onboarding for nye kunder og kan gi tilpasset opplæring for team. Ta kontakt med{' '}
                            <a href="mailto:salg@procynia.no" className="text-blue-600 hover:underline">salg@procynia.no</a>{' '}
                            for å høre mer.
                        </p>
                    </FaqItem>
                </FaqGroup>

            </div>
        </PublicLayout>
    );
}
