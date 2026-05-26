import { Link } from '@inertiajs/react';
import PublicLayout from '../../Layouts/PublicLayout';

function ArrowIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={className} aria-hidden="true">
            <path d="M4 10h11" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
            <path d="m11 5 5 5-5 5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function PlayIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={className} aria-hidden="true">
            <circle cx="10" cy="10" r="8" stroke="currentColor" strokeWidth="1.7" />
            <path d="M8.4 6.9v6.2l5-3.1-5-3.1Z" fill="currentColor" />
        </svg>
    );
}

function TimeIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 24 24" fill="none" className={className} aria-hidden="true">
            <circle cx="12" cy="12" r="8.5" stroke="currentColor" strokeWidth="1.7" />
            <path d="M12 7.5V12l2.8 1.9" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function GrowthIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 24 24" fill="none" className={className} aria-hidden="true">
            <path d="M5 18.5h14" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
            <path d="M7.5 16v-4.5" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
            <path d="M12 16V8" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
            <path d="M16.5 16v-6" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
            <path d="m7.5 11.5 4.5-4 3 2 3.5-4" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function ShieldIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 24 24" fill="none" className={className} aria-hidden="true">
            <path d="M12 4.5 18.5 7v5.1c0 4-2.5 6.7-6.5 8-4-1.3-6.5-4-6.5-8V7L12 4.5Z" stroke="currentColor" strokeWidth="1.7" strokeLinejoin="round" />
            <path d="m9.2 12.2 1.7 1.7 3.8-4" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function CheckIcon({ className = '' }) {
    return (
        <svg viewBox="0 0 20 20" fill="none" className={className} aria-hidden="true">
            <path d="m4.6 10.3 3.1 3.1 7.8-7.8" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function Benefit({ icon: Icon, title, body }) {
    return (
        <div className="flex items-start gap-4">
            <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
                <Icon className="h-10 w-10" />
            </div>
            <div>
                <p className="text-base font-semibold text-slate-900">{title}</p>
                <p className="mt-1 text-base leading-7 text-slate-500">{body}</p>
            </div>
        </div>
    );
}

export default function Home() {
    const benefits = [
        {
            icon: TimeIcon,
            title: 'Spar tid',
            body: 'Finn relevante anbud raskere og reduser manuelt arbeid i tilbudsprosessen.',
        },
        {
            icon: GrowthIcon,
            title: 'Prioriter riktig',
            body: 'Vurder muligheter tidligere og bruk ressursene på anbudene som faktisk er verdt å vinne.',
        },
        {
            icon: ShieldIcon,
            title: 'Få kontroll',
            body: 'Samle krav, dokumentasjon og vurderinger på ett sted – før tilbudet sendes.',
        },
    ];

    const checklist = ['Kom i gang på få minutter', 'Ingen betalingskort kreves', 'Abonnement velges etter kontoetablering'];

    return (
        <PublicLayout title="Procynia">
            {/* ── Hero ──────────────────────────────────────────── */}
            <section
                className="relative -mt-14 overflow-hidden"
                style={{
                    width: '100vw',
                    marginLeft: 'calc(-50vw + 50%)',
                    backgroundImage: "url('/images/reference-homepage.png')",
                    backgroundSize: 'cover',
                    backgroundPosition: 'center right',
                }}
            >
                {/* Hvit gradient fra venstre → transparent mot høyre for lesbarhet */}
                <div
                    className="absolute inset-0"
                    style={{
                        background:
                            'linear-gradient(to right, rgba(239,246,255,1) 0%, rgba(239,246,255,0.97) 38%, rgba(239,246,255,0.85) 55%, rgba(239,246,255,0.2) 72%, rgba(239,246,255,0) 85%)',
                    }}
                    aria-hidden="true"
                />

                <div className="relative z-10 w-full px-6 pb-12 pt-6 lg:px-10 lg:pb-16 lg:pt-8 xl:px-14">
                    <div className="max-w-2xl">
                        <div className="inline-flex rounded-full border border-blue-100 bg-blue-50 px-5 py-2 text-base font-medium text-blue-700">
                            For anbud. For vekst. For fremtiden.
                        </div>

                        <h1 className="mt-7 text-5xl font-semibold leading-[1.04] tracking-[-0.045em] text-slate-950 sm:text-6xl lg:text-[3.75rem]">
                            <span className="block">Smarte anskaffelser.</span>
                            <span className="block text-blue-600">Større verdi.</span>
                        </h1>

                        <p className="mt-6 max-w-lg text-lg leading-8 text-slate-600">
                            Procynia hjelper virksomheter med å finne, vurdere og besvare anbud mer effektivt – med bedre kontroll på krav, dokumentasjon og beslutninger.
                        </p>

                        <div className="mt-8 flex flex-col gap-3 sm:flex-row">
                            <Link
                                href="/registrer"
                                className="inline-flex items-center justify-center gap-3 rounded-xl bg-blue-600 px-7 py-3.5 text-base font-semibold text-white shadow-lg shadow-blue-500/30 transition hover:bg-blue-700"
                            >
                                Kom i gang
                                <ArrowIcon className="h-4 w-4" />
                            </Link>
                            <Link
                                href="#hvordan"
                                className="inline-flex items-center justify-center gap-3 rounded-xl border border-slate-200 bg-white/90 px-7 py-3.5 text-base font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-white"
                            >
                                <PlayIcon className="h-4 w-4 text-blue-600" />
                                Se hvordan det fungerer
                            </Link>
                        </div>

                        <div className="mt-12 grid gap-7 sm:grid-cols-3">
                            {benefits.map((b) => (
                                <Benefit key={b.title} icon={b.icon} title={b.title} body={b.body} />
                            ))}
                        </div>
                    </div>
                </div>
            </section>

            {/* ── CTA ───────────────────────────────────────────── */}
            <section
                id="hvordan"
                className="relative -mb-14 overflow-hidden bg-blue-50/50 pb-20 pt-10 text-center"
                style={{ width: '100vw', marginLeft: 'calc(-50vw + 50%)' }}
            >
                <div className="w-full px-6 lg:px-10 xl:px-14">
                    <div className="mx-auto max-w-4xl">
                        <h2 className="text-3xl font-semibold tracking-tight text-slate-950 sm:text-[2.5rem]">
                            Klar for bedre kontroll på neste anbud?
                        </h2>
                        <p className="mx-auto mt-4 max-w-lg text-lg leading-8 text-slate-600">
                            Opprett konto og se hvordan Procynia kan støtte virksomheten fra kunngjøring til ferdig tilbud.
                        </p>
                        <div className="mt-8">
                            <Link
                                href="/registrer"
                                className="inline-flex items-center justify-center gap-3 rounded-xl bg-blue-600 px-8 py-4 text-base font-semibold text-white shadow-lg shadow-blue-500/30 transition hover:bg-blue-700"
                            >
                                Opprett gratis konto
                                <ArrowIcon className="h-4 w-4" />
                            </Link>
                        </div>
                        <div className="mt-8 flex flex-wrap justify-center gap-6 text-base text-slate-500">
                            {checklist.map((item) => (
                                <div key={item} className="flex items-center gap-2">
                                    <CheckIcon className="h-4 w-4 text-blue-500" />
                                    <span>{item}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
