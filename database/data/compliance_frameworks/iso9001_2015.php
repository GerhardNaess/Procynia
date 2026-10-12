<?php

/*
 * Kravbiblioteket — ISO 9001:2015 inkludert endring 1:2024 (klimaendringer i 4.1 og 4.2).
 * Read by ComplianceFrameworkLibraryImporter through ComplianceFrameworkLibrarySeeder; never by a
 * request.
 *
 * COPYRIGHT. ISO 9001 is copyrighted by ISO (in Norway sold as NS-EN ISO 9001 by Standard Norge).
 * Nothing here is the standard's text: the clause numbers and the structure are facts about the
 * standard; titles, descriptions and guidance are Procynia's own, condensed Norwegian wording of
 * what each clause requires. They are a working aid, not a substitute for the standard — the
 * customer needs its own licensed copy to claim conformity.
 *
 * EDITION. One edition only: ISO 9001:2015 with Amendment 1:2024. ISO 9001:2026 (published
 * 2026-09-16) is not mixed in; it becomes its own framework with its own key when its content has
 * been prepared from a licensed copy. Certificates to the 2015 edition stay valid until the end of
 * the transition (2029-09-30).
 *
 * VERIFICATION. 'content_verified' stays false until a quality professional has checked every
 * description against a licensed copy of the standard. The clause numbering (chapters 4–10 and their
 * sub-clauses) was checked against the published table of contents of the 2015 edition; see
 * verification_note.
 *
 * STABILITY. 'key' and every clause number are stable identities: they become node_key on the
 * library node and a customer's requirement points at that node for good. Never renumber or reuse
 * one; a clause that should go is removed from this file, and the importer withdraws it.
 *
 * Fields of an assessable requirement (a leaf):
 *  - type: activity (virksomheten skal gjøre noe) | leadership (toppledelsen skal vise eller sikre
 *    noe) | documented_information (kravet gjelder selve den dokumenterte informasjonen);
 *  - info: what documented information the clause itself demands — maintain (vedlikeholde, i.e.
 *    styrende dokumenter), retain (oppbevare, i.e. registreringer som bevis), both, or null;
 *  - rule: mandatory | conditional. Per 4.3, a requirement may be determined not applicable only
 *    when that does not affect the ability or responsibility to deliver conforming products and
 *    services and to enhance customer satisfaction, and every such determination is justified.
 *    conditional marks the clauses whose own wording, or the standard's guidance on applicability
 *    (Annex A.5), makes them depend on what the organisation actually does: measurement
 *    traceability, design and development, property belonging to others and post-delivery
 *    activities. Everything else is mandatory within the QMS's scope;
 *  - modules: where in Procynia the work is done, as navigation hints only (see
 *    ComplianceFrameworkPresenter::MODULE_LINKS).
 */

return [
    'key' => 'iso9001:2015',
    'catalog_key' => 'iso9001',
    'reference' => 'ISO 9001',
    'edition' => '2015 + A1:2024',
    'name' => 'ISO 9001 – Kvalitetsstyring',
    'description' => 'Den internasjonale standarden for kvalitetsstyring. Den stiller krav til hvordan virksomheten forstår sin kontekst, tar lederskap, planlegger, sikrer ressurser, styrer leveransene, evaluerer resultatene og forbedrer seg – slik at kundene får produkter og tjenester som oppfyller kravene. Kravene i kapittel 4 til 10 kan sertifiseres av et akkreditert sertifiseringsorgan.',
    'scope_note' => 'Kravene gjelder innenfor det omfanget dere har fastsatt for kvalitetsstyringssystemet (punkt 4.3). Et krav kan bare vurderes som ikke relevant når det ikke påvirker evnen eller ansvaret for å levere produkter og tjenester i samsvar med krav og å øke kundetilfredsheten – og det må alltid begrunnes.',
    'content_verified' => false,
    'verification_note' => 'Punktstrukturen (kapittel 4–10 med underpunkter) er kontrollert mot den publiserte innholdsfortegnelsen for ISO 9001:2015 og endring 1:2024. Kravbeskrivelsene er Procynias egne, sammenfattede formuleringer og er ikke standardens tekst. De er ennå ikke faglig kvalitetssikret mot en lisensiert utgave av standarden. Bruk standarden selv som grunnlag ved sertifisering.',
    'position' => 10,

    'chapters' => [
        [
            'clause' => '4',
            'title' => 'Organisasjonens kontekst',
            'children' => [
                [
                    'clause' => '4.1',
                    'title' => 'Forstå organisasjonen og dens kontekst',
                    'description' => 'Virksomheten skal kartlegge de interne og eksterne forholdene som er relevante for formålet og strategien, og som påvirker evnen til å oppnå det kvalitetsstyringssystemet skal gi. Forholdene skal følges med på og gjennomgås jevnlig. Etter endringen i 2024 skal virksomheten også ta stilling til om klimaendringer er et relevant forhold.',
                    'guidance' => 'Løses ofte med en kontekstanalyse, for eksempel SWOT eller PESTEL, som oppdateres i strategiarbeidet og i ledelsens gjennomgang. Standarden krever ikke et bestemt dokument, men dere må kunne vise at vurderingen er gjort og holdes oppdatert – også vurderingen av klimaendringer, selv om konklusjonen er at de ikke er relevante.',
                    'documentation' => ['Kontekstanalyse (for eksempel SWOT eller PESTEL)', 'Vurdering av om klimaendringer er relevante', 'Referat fra ledelsens gjennomgang'],
                    'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['risk', 'management_review'],
                ],
                [
                    'clause' => '4.2',
                    'title' => 'Forstå behovene og forventningene til interessenter',
                    'description' => 'Virksomheten skal fastsette hvilke interessenter som er relevante for kvalitetsstyringssystemet, og hvilke av deres krav som er relevante. Informasjonen om interessentene og kravene deres skal følges med på og gjennomgås jevnlig. Relevante interessenter kan også ha krav knyttet til klimaendringer.',
                    'guidance' => 'Typiske interessenter er kunder og oppdragsgivere, myndigheter, eiere, ansatte, leverandører og samarbeidspartnere. For virksomheter som leverer i offentlige anskaffelser er oppdragsgivernes krav i konkurransegrunnlag og kontrakter en sentral del av dette.',
                    'documentation' => ['Interessentanalyse', 'Oversikt over relevante lov-, myndighets- og kontraktskrav'],
                    'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => [],
                ],
                [
                    'clause' => '4.3',
                    'title' => 'Fastsette omfanget av kvalitetsstyringssystemet',
                    'description' => 'Virksomheten skal fastsette grensene for kvalitetsstyringssystemet og hva det gjelder for, ut fra konteksten (4.1), interessentenes krav (4.2) og egne produkter og tjenester. Omfanget skal være tilgjengelig som dokumentert informasjon, angi hvilke produkter og tjenester det dekker, og begrunne hvert krav i standarden som virksomheten mener ikke kan anvendes.',
                    'guidance' => 'Et krav kan bare holdes utenfor når det ikke påvirker evnen eller ansvaret for å levere i samsvar med krav og å øke kundetilfredsheten. Begrunnelsene dere gir for «Ikke relevant» i dette kravregisteret bør stemme med omfangsbeskrivelsen.',
                    'documentation' => ['Omfangsbeskrivelse for kvalitetsstyringssystemet', 'Begrunnelse for krav som ikke anvendes'],
                    'type' => 'activity', 'info' => 'maintain', 'rule' => 'mandatory', 'modules' => [],
                ],
                [
                    'clause' => '4.4',
                    'title' => 'Kvalitetsstyringssystemet og prosessene',
                    'children' => [
                        [
                            'clause' => '4.4.1',
                            'title' => 'Etablere og forbedre systemet og prosessene',
                            'description' => 'Virksomheten skal etablere, implementere, vedlikeholde og kontinuerlig forbedre et kvalitetsstyringssystem med de prosessene som trengs, og hvordan de virker sammen. For hver prosess skal det blant annet fastsettes input og output, rekkefølge og samspill, kriterier og metoder for styring, nødvendige ressurser, ansvar og myndighet, risikoer og muligheter, og hvordan prosessen evalueres og forbedres.',
                            'guidance' => 'Et prosesskart med hovedprosesser og støtteprosesser, der hver prosess har en eier, er den vanligste måten å vise dette på. Revisor vil se at prosessene faktisk styres og følges opp, ikke bare at de er tegnet.',
                            'documentation' => ['Prosesskart', 'Prosessbeskrivelser med eier, kriterier og indikatorer'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['quality'],
                        ],
                        [
                            'clause' => '4.4.2',
                            'title' => 'Dokumentert informasjon om prosessene',
                            'description' => 'Virksomheten skal i nødvendig omfang vedlikeholde dokumentert informasjon som støtter at prosessene fungerer, og oppbevare dokumentert informasjon som gir tillit til at prosessene gjennomføres som planlagt.',
                            'guidance' => 'Omfanget bestemmer virksomheten selv ut fra størrelse, kompleksitet og kompetanse. Styrende dokumenter (rutiner, prosessbeskrivelser) vedlikeholdes; registreringer (logger, referater, sjekklister) oppbevares som bevis.',
                            'documentation' => ['Rutiner og prosessbeskrivelser', 'Registreringer som viser at prosessene er fulgt'],
                            'type' => 'documented_information', 'info' => 'both', 'rule' => 'mandatory', 'modules' => ['quality'],
                        ],
                    ],
                ],
            ],
        ],
        [
            'clause' => '5',
            'title' => 'Lederskap',
            'children' => [
                [
                    'clause' => '5.1',
                    'title' => 'Lederskap og forpliktelse',
                    'children' => [
                        [
                            'clause' => '5.1.1',
                            'title' => 'Toppledelsens lederskap og forpliktelse',
                            'description' => 'Toppledelsen skal vise lederskap og forpliktelse til kvalitetsstyringssystemet. Det innebærer blant annet å ta ansvar for at systemet er effektivt, sørge for at kvalitetspolitikk og kvalitetsmål er fastsatt og passer med strategien, integrere kravene i virksomhetens prosesser, fremme prosesstilnærming og risikobasert tenkning, sikre ressurser, kommunisere hvorfor kvalitetsstyring er viktig, engasjere og støtte medarbeiderne, fremme forbedring og støtte andre ledere i deres ansvar.',
                            'guidance' => 'Revisor intervjuer normalt toppledelsen. Bevis kan være referater fra ledermøter og ledelsens gjennomgang, ressursbeslutninger og hvordan ledelsen følger opp mål og avvik.',
                            'documentation' => ['Referater fra ledermøter', 'Referat fra ledelsens gjennomgang', 'Beslutninger om ressurser'],
                            'type' => 'leadership', 'info' => null, 'rule' => 'mandatory', 'modules' => ['management_review'],
                        ],
                        [
                            'clause' => '5.1.2',
                            'title' => 'Kundefokus',
                            'description' => 'Toppledelsen skal vise lederskap og forpliktelse når det gjelder kundefokus: kundekrav og gjeldende lov- og myndighetskrav skal være fastsatt, forstått og oppfylt; risikoer og muligheter som kan påvirke samsvar og kundetilfredshet skal være fastsatt og håndtert; og det skal holdes fokus på å øke kundetilfredsheten.',
                            'guidance' => 'Vis hvordan ledelsen følger med på kundetilfredshet, klager og leveransekvalitet, og hvordan det fører til beslutninger.',
                            'documentation' => ['Resultater fra kundetilfredshetsmålinger', 'Oversikt over kundekrav og lovkrav'],
                            'type' => 'leadership', 'info' => null, 'rule' => 'mandatory', 'modules' => ['objectives'],
                        ],
                    ],
                ],
                [
                    'clause' => '5.2',
                    'title' => 'Kvalitetspolitikk',
                    'children' => [
                        [
                            'clause' => '5.2.1',
                            'title' => 'Utarbeide kvalitetspolitikken',
                            'description' => 'Toppledelsen skal etablere, implementere og vedlikeholde en kvalitetspolitikk som passer virksomhetens formål og kontekst og støtter strategien, gir rammer for å fastsette kvalitetsmål, og inneholder en forpliktelse til å oppfylle gjeldende krav og til kontinuerlig forbedring.',
                            'guidance' => 'Politikken bør være kort og konkret nok til at medarbeiderne kjenner seg igjen i den. Kontroller at begge forpliktelsene – å oppfylle krav og å forbedre seg – faktisk står der.',
                            'documentation' => ['Kvalitetspolitikk godkjent av toppledelsen'],
                            'type' => 'leadership', 'info' => null, 'rule' => 'mandatory', 'modules' => ['quality'],
                        ],
                        [
                            'clause' => '5.2.2',
                            'title' => 'Kommunisere kvalitetspolitikken',
                            'description' => 'Kvalitetspolitikken skal være tilgjengelig og vedlikeholdt som dokumentert informasjon, kommuniseres, forstås og anvendes i virksomheten, og være tilgjengelig for relevante interessenter når det er hensiktsmessig.',
                            'guidance' => 'Publiser politikken der medarbeiderne finner den, og ta den opp i introduksjon og opplæring. Revisor kan spørre medarbeidere hva politikken betyr for deres arbeid.',
                            'documentation' => ['Publisert kvalitetspolitikk', 'Plan eller bevis for kommunikasjon av politikken'],
                            'type' => 'documented_information', 'info' => 'maintain', 'rule' => 'mandatory', 'modules' => ['quality'],
                        ],
                    ],
                ],
                [
                    'clause' => '5.3',
                    'title' => 'Roller, ansvar og myndighet i organisasjonen',
                    'description' => 'Toppledelsen skal sørge for at ansvar og myndighet for relevante roller er tildelt, kommunisert og forstått. Det skal blant annet tildeles ansvar for at systemet samsvarer med standarden, at prosessene gir tilsiktede resultater, at systemets ytelse og forbedringsmuligheter rapporteres til toppledelsen, at kundefokus fremmes, og at systemet holdes helhetlig når endringer planlegges og gjennomføres.',
                    'guidance' => 'Standarden krever ikke lenger en egen «kvalitetsleder», men noen må ha ansvaret. Organisasjonskart, prosesseierskap og rollebeskrivelser er vanlige bevis.',
                    'documentation' => ['Organisasjonskart', 'Rollebeskrivelser', 'Oversikt over prosesseiere'],
                    'type' => 'leadership', 'info' => null, 'rule' => 'mandatory', 'modules' => ['quality'],
                ],
            ],
        ],
        [
            'clause' => '6',
            'title' => 'Planlegging',
            'children' => [
                [
                    'clause' => '6.1',
                    'title' => 'Tiltak for å håndtere risikoer og muligheter',
                    'children' => [
                        [
                            'clause' => '6.1.1',
                            'title' => 'Fastsette risikoer og muligheter',
                            'description' => 'Når kvalitetsstyringssystemet planlegges, skal virksomheten ta hensyn til forholdene i 4.1 og kravene i 4.2, og fastsette hvilke risikoer og muligheter som må håndteres for å sikre at systemet gir tilsiktede resultater, forsterke ønskede virkninger, forebygge eller redusere uønskede virkninger, og oppnå forbedring.',
                            'guidance' => 'Standarden krever ikke en bestemt risikometode. Et risikoregister med vurderinger knyttet til konteksten og prosessene er en vanlig og god løsning.',
                            'documentation' => ['Risikoregister med risikoer og muligheter', 'Kobling mellom risikoer og kontekst/interessenter'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['risk'],
                        ],
                        [
                            'clause' => '6.1.2',
                            'title' => 'Planlegge tiltak og evaluere effekten',
                            'description' => 'Virksomheten skal planlegge tiltak for å håndtere risikoene og mulighetene, hvordan tiltakene integreres og gjennomføres i prosessene, og hvordan effekten av dem evalueres. Tiltakene skal stå i forhold til den mulige virkningen på om produktene og tjenestene samsvarer med krav.',
                            'guidance' => 'Vis at tiltakene har eier og frist, at de faktisk er gjennomført, og at effekten er vurdert – for eksempel ved ny risikovurdering.',
                            'documentation' => ['Tiltaksplan for risikoer og muligheter', 'Oppfølging og effektvurdering av tiltak'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['risk'],
                        ],
                    ],
                ],
                [
                    'clause' => '6.2',
                    'title' => 'Kvalitetsmål og planlegging for å nå dem',
                    'children' => [
                        [
                            'clause' => '6.2.1',
                            'title' => 'Fastsette kvalitetsmål',
                            'description' => 'Virksomheten skal fastsette kvalitetsmål for relevante funksjoner, nivåer og prosesser. Målene skal være i tråd med kvalitetspolitikken, være målbare, ta hensyn til gjeldende krav, være relevante for samsvar og kundetilfredshet, følges opp, kommuniseres og oppdateres. Virksomheten skal vedlikeholde dokumentert informasjon om kvalitetsmålene.',
                            'guidance' => 'Gode kvalitetsmål har en indikator, et målnivå og en eier. Målene bør henge sammen med politikken og med de viktigste risikoene.',
                            'documentation' => ['Oversikt over kvalitetsmål med indikatorer og målnivå'],
                            'type' => 'activity', 'info' => 'maintain', 'rule' => 'mandatory', 'modules' => ['objectives'],
                        ],
                        [
                            'clause' => '6.2.2',
                            'title' => 'Planlegge hvordan målene skal nås',
                            'description' => 'For hvert kvalitetsmål skal virksomheten fastsette hva som skal gjøres, hvilke ressurser som trengs, hvem som er ansvarlig, når det skal være fullført, og hvordan resultatene skal evalueres.',
                            'guidance' => 'En enkel handlingsplan per mål er nok, så lenge den har ansvarlig, frist og en måte å måle resultatet på.',
                            'documentation' => ['Handlingsplaner for kvalitetsmålene', 'Oppfølging av måloppnåelse'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['objectives'],
                        ],
                    ],
                ],
                [
                    'clause' => '6.3',
                    'title' => 'Planlegging av endringer',
                    'description' => 'Når virksomheten ser behov for endringer i kvalitetsstyringssystemet, skal endringene gjennomføres på en planlagt måte. Det skal tas hensyn til formålet med endringen og mulige konsekvenser, at systemet forblir helhetlig, tilgangen på ressurser, og fordeling eller omfordeling av ansvar og myndighet.',
                    'guidance' => 'Gjelder for eksempel omorganiseringer, nye systemer, nye tjenester eller endrede prosesser. Vis at konsekvenser og risiko er vurdert før endringen ble gjennomført.',
                    'documentation' => ['Endringsplaner med konsekvens- og risikovurdering'],
                    'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['quality', 'risk'],
                ],
            ],
        ],
        [
            'clause' => '7',
            'title' => 'Støtte',
            'children' => [
                [
                    'clause' => '7.1',
                    'title' => 'Ressurser',
                    'children' => [
                        [
                            'clause' => '7.1.1',
                            'title' => 'Ressurser – generelt',
                            'description' => 'Virksomheten skal fastsette og stille til rådighet ressursene som trengs for å etablere, implementere, vedlikeholde og kontinuerlig forbedre kvalitetsstyringssystemet. Den skal vurdere både kapasiteten i og begrensningene ved egne ressurser, og hva som må skaffes fra eksterne leverandører.',
                            'guidance' => 'Ressursbehovet tas gjerne opp i budsjettprosessen og i ledelsens gjennomgang.',
                            'documentation' => ['Budsjett og bemanningsplan', 'Ressursbeslutninger fra ledelsens gjennomgang'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['management_review'],
                        ],
                        [
                            'clause' => '7.1.2',
                            'title' => 'Personell',
                            'description' => 'Virksomheten skal fastsette og stille til rådighet det personellet som trengs for å drive kvalitetsstyringssystemet effektivt og for å drive og styre prosessene.',
                            'guidance' => 'Vis at bemanningen er vurdert opp mot oppgavene, for eksempel i bemanningsplaner eller kapasitetsoversikter.',
                            'documentation' => ['Bemannings- eller kapasitetsplan'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => [],
                        ],
                        [
                            'clause' => '7.1.3',
                            'title' => 'Infrastruktur',
                            'description' => 'Virksomheten skal fastsette, stille til rådighet og vedlikeholde den infrastrukturen som trengs for å drive prosessene og levere produkter og tjenester i samsvar med krav – for eksempel bygninger, utstyr, programvare, transport og informasjons- og kommunikasjonsteknologi.',
                            'guidance' => 'For tjenestevirksomheter er IKT-systemer ofte den viktigste infrastrukturen. Vis at den vedlikeholdes, for eksempel gjennom vedlikeholdsplaner eller driftsavtaler.',
                            'documentation' => ['Oversikt over kritisk infrastruktur og systemer', 'Vedlikeholdsplaner eller driftsavtaler'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => [],
                        ],
                        [
                            'clause' => '7.1.4',
                            'title' => 'Miljø for gjennomføring av prosessene',
                            'description' => 'Virksomheten skal fastsette, stille til rådighet og vedlikeholde det arbeidsmiljøet som trengs for å drive prosessene og levere i samsvar med krav. Det kan omfatte sosiale, psykologiske og fysiske forhold.',
                            'guidance' => 'Arbeidsmiljøkartlegginger, medarbeiderundersøkelser og HMS-rutiner er vanlige bevis.',
                            'documentation' => ['Arbeidsmiljøkartlegging eller medarbeiderundersøkelse', 'HMS-rutiner'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => [],
                        ],
                        [
                            'clause' => '7.1.5',
                            'title' => 'Ressurser for overvåking og måling',
                            'children' => [
                                [
                                    'clause' => '7.1.5.1',
                                    'title' => 'Egnede ressurser for overvåking og måling',
                                    'description' => 'Når overvåking eller måling brukes for å verifisere at produkter og tjenester samsvarer med krav, skal virksomheten sørge for at ressursene som brukes, er egnet for formålet og vedlikeholdes slik at de fortsatt er egnet. Virksomheten skal oppbevare dokumentert informasjon som bevis på at ressursene er egnet.',
                                    'guidance' => 'Gjelder både måleutstyr og andre metoder, som sjekklister, testverktøy og undersøkelser. Vis hvordan dere vet at metodene gir pålitelige resultater.',
                                    'documentation' => ['Oversikt over måle- og kontrollmetoder', 'Bevis på at metodene er egnet'],
                                    'type' => 'activity', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => [],
                                ],
                                [
                                    'clause' => '7.1.5.2',
                                    'title' => 'Sporbarhet for målinger',
                                    'description' => 'Når sporbarhet for målinger er et krav, eller virksomheten anser det nødvendig for å kunne stole på måleresultatene, skal måleutstyret kalibreres eller verifiseres mot målestandarder med sporbarhet til internasjonale eller nasjonale standarder (eller et annet dokumentert grunnlag), identifiseres slik at status kan fastslås, og beskyttes mot justeringer, skade eller forringelse. Viser utstyret seg å være uegnet, skal virksomheten vurdere om tidligere resultater er påvirket og iverksette nødvendige tiltak.',
                                    'guidance' => 'Gjelder virksomheter som bruker kalibrert måleutstyr. Mange rene tjenestevirksomheter kan vurdere punktet som ikke relevant – men begrunnelsen må vise at det ikke påvirker samsvar.',
                                    'documentation' => ['Kalibreringsplan og kalibreringsbevis', 'Register over måleutstyr'],
                                    'type' => 'activity', 'info' => 'retain', 'rule' => 'conditional', 'modules' => [],
                                ],
                            ],
                        ],
                        [
                            'clause' => '7.1.6',
                            'title' => 'Organisasjonens kunnskap',
                            'description' => 'Virksomheten skal fastsette den kunnskapen som trengs for å drive prosessene og levere i samsvar med krav. Kunnskapen skal vedlikeholdes og gjøres tilgjengelig i nødvendig omfang. Når behovene endres, skal virksomheten vurdere hva den allerede vet, og hvordan den skaffer seg nødvendig tilleggskunnskap og oppdateringer.',
                            'guidance' => 'Kunnskap kan være interne erfaringer, læring fra prosjekter og avvik, standarder og fagkunnskap. Et strukturert kunnskapsgrunnlag – som Procynia Wiki – gjør kunnskapen tilgjengelig og sporbar.',
                            'documentation' => ['Kunnskapsbase eller wiki', 'Erfaringsoverføring fra prosjekter og avvik'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['wiki'],
                        ],
                    ],
                ],
                [
                    'clause' => '7.2',
                    'title' => 'Kompetanse',
                    'description' => 'Virksomheten skal fastsette hvilken kompetanse som trengs hos personer som utfører arbeid som påvirker kvalitetsstyringssystemets ytelse og effektivitet, sørge for at de er kompetente ut fra utdanning, opplæring eller erfaring, iverksette tiltak for å skaffe kompetanse der det trengs, og evaluere om tiltakene har virket. Virksomheten skal oppbevare dokumentert informasjon som bevis på kompetanse.',
                    'guidance' => 'Vanlig bevis er kompetansekrav per rolle, CV-er, kursbevis og opplæringsplaner. CV-er og referanser brukes ofte også i tilbud, så de bør holdes oppdatert ett sted.',
                    'documentation' => ['Kompetansekrav per rolle', 'CV-er og kursbevis', 'Opplæringsplan og evaluering av opplæring'],
                    'type' => 'activity', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => ['wiki'],
                ],
                [
                    'clause' => '7.3',
                    'title' => 'Bevissthet',
                    'description' => 'Personer som arbeider under virksomhetens kontroll, skal være kjent med kvalitetspolitikken, relevante kvalitetsmål, sitt eget bidrag til at kvalitetsstyringssystemet er effektivt og fordelene ved bedre ytelse, og konsekvensene av ikke å følge kravene i systemet.',
                    'guidance' => 'Gjelder også innleide og underleverandører som arbeider for dere. Introduksjonsprogram, avdelingsmøter og intervjuer under internrevisjon kan vise dette.',
                    'documentation' => ['Introduksjonsprogram for nyansatte', 'Bevis på gjennomgang av politikk og mål'],
                    'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['quality', 'objectives'],
                ],
                [
                    'clause' => '7.4',
                    'title' => 'Kommunikasjon',
                    'description' => 'Virksomheten skal fastsette den interne og eksterne kommunikasjonen som er relevant for kvalitetsstyringssystemet: hva det skal kommuniseres om, når, med hvem, hvordan, og hvem som kommuniserer.',
                    'guidance' => 'En enkel kommunikasjonsmatrise dekker ofte kravet.',
                    'documentation' => ['Kommunikasjonsplan eller kommunikasjonsmatrise'],
                    'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => [],
                ],
                [
                    'clause' => '7.5',
                    'title' => 'Dokumentert informasjon',
                    'children' => [
                        [
                            'clause' => '7.5.1',
                            'title' => 'Dokumentert informasjon – generelt',
                            'description' => 'Kvalitetsstyringssystemet skal omfatte den dokumenterte informasjonen standarden krever, og den dokumenterte informasjonen virksomheten selv mener trengs for at systemet skal være effektivt. Omfanget kan variere med virksomhetens størrelse og aktiviteter, prosessenes kompleksitet og samspill, og personellets kompetanse.',
                            'guidance' => 'Standarden krever ingen kvalitetshåndbok. Lag en oversikt over hvilke styrende dokumenter og registreringer dere har, og hvor de finnes.',
                            'documentation' => ['Dokumentoversikt eller dokumentregister'],
                            'type' => 'documented_information', 'info' => null, 'rule' => 'mandatory', 'modules' => ['quality', 'wiki'],
                        ],
                        [
                            'clause' => '7.5.2',
                            'title' => 'Utarbeide og oppdatere dokumentert informasjon',
                            'description' => 'Når dokumentert informasjon utarbeides og oppdateres, skal virksomheten sørge for hensiktsmessig identifikasjon og beskrivelse (for eksempel tittel, dato, forfatter eller referansenummer), format og medium, og gjennomgang og godkjenning av om innholdet er egnet og tilstrekkelig.',
                            'guidance' => 'Vis at dokumenter har eier, versjon og godkjenning før de tas i bruk.',
                            'documentation' => ['Rutine for dokumentstyring', 'Dokumenter med versjon og godkjenning'],
                            'type' => 'documented_information', 'info' => null, 'rule' => 'mandatory', 'modules' => ['quality'],
                        ],
                        [
                            'clause' => '7.5.3',
                            'title' => 'Styring av dokumentert informasjon',
                            'children' => [
                                [
                                    'clause' => '7.5.3.1',
                                    'title' => 'Tilgjengelighet og beskyttelse',
                                    'description' => 'Dokumentert informasjon som kvalitetsstyringssystemet og standarden krever, skal styres slik at den er tilgjengelig og egnet for bruk der og når den trengs, og tilstrekkelig beskyttet – for eksempel mot tap av konfidensialitet, feil bruk eller tap av integritet.',
                                    'guidance' => 'Tilgangsstyring, sikkerhetskopiering og ett felles sted for gjeldende versjoner er typiske tiltak.',
                                    'documentation' => ['Tilgangsstyring for dokumentsystemet', 'Rutine for sikkerhetskopiering'],
                                    'type' => 'documented_information', 'info' => null, 'rule' => 'mandatory', 'modules' => ['quality'],
                                ],
                                [
                                    'clause' => '7.5.3.2',
                                    'title' => 'Distribusjon, endring, lagring og avhending',
                                    'description' => 'Virksomheten skal i relevant omfang håndtere distribusjon, tilgang, gjenfinning og bruk, lagring og bevaring (også at informasjonen forblir lesbar), endringsstyring som versjonskontroll, og oppbevaringstid og avhending. Dokumentert informasjon av ekstern opprinnelse som trengs for systemet, skal identifiseres og styres. Registreringer som oppbevares som bevis på samsvar, skal beskyttes mot utilsiktede endringer.',
                                    'guidance' => 'Husk eksterne dokumenter som standarder, kundekrav og lovverk. Registreringer som brukes som bevis, skal ikke kunne endres i ettertid uten at det synes.',
                                    'documentation' => ['Rutine for dokumentstyring med oppbevaringstider', 'Oversikt over eksterne dokumenter'],
                                    'type' => 'documented_information', 'info' => null, 'rule' => 'mandatory', 'modules' => ['quality'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        [
            'clause' => '8',
            'title' => 'Drift',
            'children' => [
                [
                    'clause' => '8.1',
                    'title' => 'Planlegging og styring av driften',
                    'description' => 'Virksomheten skal planlegge, implementere og styre prosessene som trengs for å oppfylle kravene til produktene og tjenestene, og for å gjennomføre tiltakene fra kapittel 6. Det innebærer blant annet å fastsette krav til produktene og tjenestene, kriterier for prosessene og for godkjenning, nødvendige ressurser, styring i tråd med kriteriene, og dokumentert informasjon i nødvendig omfang. Planlagte endringer skal styres, konsekvensene av utilsiktede endringer vurderes, og prosesser som er satt ut, skal være styrt.',
                    'guidance' => 'Prosjektplaner, leveranseplaner og arbeidsbeskrivelser er typiske bevis. For tilbudsarbeid er dette planleggingen fra kunngjøring til levert tilbud.',
                    'documentation' => ['Leveranse- eller prosjektplaner', 'Arbeidsbeskrivelser og godkjenningskriterier'],
                    'type' => 'activity', 'info' => 'both', 'rule' => 'mandatory', 'modules' => ['quality'],
                ],
                [
                    'clause' => '8.2',
                    'title' => 'Krav til produkter og tjenester',
                    'children' => [
                        [
                            'clause' => '8.2.1',
                            'title' => 'Kommunikasjon med kunder',
                            'description' => 'Kommunikasjonen med kunder skal omfatte informasjon om produktene og tjenestene, håndtering av forespørsler, kontrakter og bestillinger med endringer, innhenting av kundenes tilbakemeldinger – også klager –, håndtering av kundens eiendom, og særskilte krav til beredskapstiltak når det er relevant.',
                            'guidance' => 'Vis hvordan kundehenvendelser og klager tas imot og følges opp, og hvem som har ansvaret.',
                            'documentation' => ['Rutine for kundekommunikasjon og klagebehandling', 'Logg over klager'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['improvements'],
                        ],
                        [
                            'clause' => '8.2.2',
                            'title' => 'Fastsette krav til produkter og tjenester',
                            'description' => 'Når virksomheten fastsetter kravene til produktene og tjenestene den tilbyr kunder, skal den sørge for at kravene er definert – også gjeldende lov- og myndighetskrav og krav virksomheten selv mener er nødvendige – og at den kan innfri det den lover om produktene og tjenestene.',
                            'guidance' => 'Tjenestebeskrivelser, produktspesifikasjoner og standardvilkår viser hva dere lover. Kontroller at markedsføring og tilbud ikke lover mer enn dere kan levere.',
                            'documentation' => ['Tjeneste- og produktbeskrivelser', 'Oversikt over gjeldende lov- og myndighetskrav'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => [],
                        ],
                        [
                            'clause' => '8.2.3',
                            'title' => 'Gjennomgang av krav til produkter og tjenester',
                            'children' => [
                                [
                                    'clause' => '8.2.3.1',
                                    'title' => 'Gjennomgang før forpliktelse',
                                    'description' => 'Før virksomheten forplikter seg til å levere til en kunde, skal den gjennomgå at den kan oppfylle kravene. Gjennomgangen skal omfatte kundens uttalte krav (også om levering og oppfølging etter levering), krav som ikke er uttalt men nødvendige for kjent bruk, krav virksomheten selv stiller, gjeldende lov- og myndighetskrav, og avvik mellom kontrakts- eller bestillingskravene og det som er uttrykt tidligere. Udokumenterte kundekrav skal bekreftes før de aksepteres.',
                                    'guidance' => 'For virksomheter som leverer tilbud i offentlige anskaffelser er dette kravgjennomgangen før tilbudet sendes og før kontrakten signeres: kan vi oppfylle alle krav i konkurransegrunnlaget, og er forbehold avklart?',
                                    'documentation' => ['Kravgjennomgang eller sjekkliste før tilbud', 'Go/no-go-beslutning', 'Kontraktsgjennomgang'],
                                    'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['tender'],
                                ],
                                [
                                    'clause' => '8.2.3.2',
                                    'title' => 'Dokumentasjon av gjennomgangen',
                                    'description' => 'Virksomheten skal i relevant omfang oppbevare dokumentert informasjon om resultatet av gjennomgangen og om eventuelle nye krav til produktene og tjenestene.',
                                    'guidance' => 'Lagre resultatet av kravgjennomgangen sammen med tilbudet eller kontrakten, slik at det kan spores i ettertid.',
                                    'documentation' => ['Dokumentert resultat av kravgjennomgang', 'Avklaringer og nye krav fra kunden'],
                                    'type' => 'documented_information', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => ['tender'],
                                ],
                            ],
                        ],
                        [
                            'clause' => '8.2.4',
                            'title' => 'Endringer i krav til produkter og tjenester',
                            'description' => 'Når kravene til produkter og tjenester endres, skal virksomheten sørge for at relevant dokumentert informasjon blir endret, og at berørte personer blir gjort kjent med de endrede kravene.',
                            'guidance' => 'Gjelder for eksempel endringsordrer, avklaringer i konkurranser og kontraktsendringer.',
                            'documentation' => ['Endringslogg for kontrakter og bestillinger'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => [],
                        ],
                    ],
                ],
                [
                    'clause' => '8.3',
                    'title' => 'Utforming og utvikling av produkter og tjenester',
                    'children' => [
                        [
                            'clause' => '8.3.1',
                            'title' => 'Utvikling – generelt',
                            'description' => 'Virksomheten skal etablere, implementere og vedlikeholde en prosess for utforming og utvikling som er egnet for å sikre at produktene og tjenestene senere kan leveres.',
                            'guidance' => 'Gjelder virksomheter som selv utformer eller utvikler produkter og tjenester. Leverer virksomheten bare etter kundens spesifikasjoner, kan hele punkt 8.3 vurderes som ikke relevant – men det må begrunnes.',
                            'documentation' => ['Beskrivelse av utviklingsprosessen'],
                            'type' => 'activity', 'info' => null, 'rule' => 'conditional', 'modules' => ['quality'],
                        ],
                        [
                            'clause' => '8.3.2',
                            'title' => 'Planlegging av utvikling',
                            'description' => 'Når faser og styring av utviklingen fastsettes, skal virksomheten ta hensyn til blant annet aktivitetenes art, varighet og kompleksitet, nødvendige faser og gjennomganger, verifisering og validering, ansvar og myndighet, interne og eksterne ressurser, grensesnitt mellom de involverte, involvering av kunder og brukere, krav til senere levering, hvilket styringsnivå kunder og andre interessenter forventer, og hvilken dokumentert informasjon som trengs for å vise at kravene er oppfylt.',
                            'guidance' => 'En prosjektmodell med faser og beslutningspunkter dekker ofte kravet.',
                            'documentation' => ['Utviklingsplan eller prosjektmodell med faser'],
                            'type' => 'activity', 'info' => null, 'rule' => 'conditional', 'modules' => [],
                        ],
                        [
                            'clause' => '8.3.3',
                            'title' => 'Inndata til utvikling',
                            'description' => 'Virksomheten skal fastsette kravene som er vesentlige for det som skal utvikles, blant annet funksjons- og ytelseskrav, erfaringer fra tidligere lignende utvikling, lov- og myndighetskrav, standarder og praksis virksomheten har forpliktet seg til, og mulige konsekvenser av feil. Inndataene skal være tilstrekkelige, fullstendige og entydige, motstridende krav skal løses, og dokumentert informasjon om inndataene skal oppbevares.',
                            'guidance' => 'Kravspesifikasjoner og brukerhistorier er typiske inndata.',
                            'documentation' => ['Kravspesifikasjon'],
                            'type' => 'activity', 'info' => 'retain', 'rule' => 'conditional', 'modules' => [],
                        ],
                        [
                            'clause' => '8.3.4',
                            'title' => 'Styring av utviklingen',
                            'description' => 'Utviklingen skal styres slik at resultatene som skal oppnås, er definert; gjennomganger vurderer om resultatene oppfyller kravene; verifisering bekrefter at resultatet oppfyller inndatakravene; validering bekrefter at produktet eller tjenesten fungerer for tiltenkt bruk; og nødvendige tiltak iverksettes for problemer som avdekkes. Dokumentert informasjon om disse aktivitetene skal oppbevares.',
                            'guidance' => 'Testprotokoller, gjennomgangsreferater og akseptansetester er vanlige bevis.',
                            'documentation' => ['Referater fra gjennomganger', 'Test- og valideringsprotokoller'],
                            'type' => 'activity', 'info' => 'retain', 'rule' => 'conditional', 'modules' => [],
                        ],
                        [
                            'clause' => '8.3.5',
                            'title' => 'Resultater fra utviklingen',
                            'description' => 'Resultatene fra utviklingen skal oppfylle inndatakravene, være tilstrekkelige for de senere prosessene for levering, inneholde eller vise til krav til overvåking og måling og akseptkriterier, og angi de egenskapene som er vesentlige for tiltenkt formål og for sikker og riktig bruk. Dokumentert informasjon om resultatene skal oppbevares.',
                            'guidance' => 'For eksempel tegninger, spesifikasjoner, tjenestebeskrivelser og brukerdokumentasjon.',
                            'documentation' => ['Spesifikasjoner og brukerdokumentasjon', 'Akseptkriterier'],
                            'type' => 'activity', 'info' => 'retain', 'rule' => 'conditional', 'modules' => [],
                        ],
                        [
                            'clause' => '8.3.6',
                            'title' => 'Endringer under utvikling',
                            'description' => 'Endringer som gjøres under eller etter utviklingen, skal identifiseres, gjennomgås og styres i nødvendig omfang for å unngå at de svekker samsvaret med krav. Dokumentert informasjon om endringene, gjennomgangene, hvem som godkjente dem, og tiltak for å hindre negative virkninger skal oppbevares.',
                            'guidance' => 'En endringslogg med godkjenning dekker ofte kravet.',
                            'documentation' => ['Endringslogg med godkjenning'],
                            'type' => 'activity', 'info' => 'retain', 'rule' => 'conditional', 'modules' => [],
                        ],
                    ],
                ],
                [
                    'clause' => '8.4',
                    'title' => 'Styring av eksternt leverte prosesser, produkter og tjenester',
                    'children' => [
                        [
                            'clause' => '8.4.1',
                            'title' => 'Eksterne leverandører – generelt',
                            'description' => 'Virksomheten skal sørge for at eksternt leverte prosesser, produkter og tjenester samsvarer med krav, og fastsette hvilken styring som gjelder når de inngår i egne leveranser, leveres direkte til kunder på virksomhetens vegne, eller er resultat av at en prosess er satt ut. Virksomheten skal fastsette og bruke kriterier for å evaluere, velge ut, følge opp og evaluere leverandørene på nytt, og oppbevare dokumentert informasjon om dette og om nødvendige tiltak.',
                            'guidance' => 'Et leverandørregister med kritikalitet, evalueringskriterier og jevnlig oppfølging er den vanlige løsningen. Husk også underleverandører i tilbud.',
                            'documentation' => ['Leverandørregister', 'Kriterier for valg og evaluering av leverandører', 'Leverandørevalueringer'],
                            'type' => 'activity', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => ['supplier'],
                        ],
                        [
                            'clause' => '8.4.2',
                            'title' => 'Type og omfang av styring',
                            'description' => 'Virksomheten skal sørge for at det som leveres eksternt, ikke svekker evnen til å levere i samsvar med krav. Eksternt leverte prosesser skal forbli innenfor kvalitetsstyringssystemet, og virksomheten skal fastsette styringen både av leverandøren og av det som leveres, ut fra mulig påvirkning og hvor godt leverandørens egen styring virker, samt hvilken verifisering som trengs.',
                            'guidance' => 'Jo mer kritisk leveransen er, jo tettere oppfølging. Mottakskontroll, revisjoner hos leverandør og SLA-oppfølging er vanlige virkemidler.',
                            'documentation' => ['Oppfølgingsplan per leverandør', 'Mottakskontroll eller SLA-rapporter'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['supplier'],
                        ],
                        [
                            'clause' => '8.4.3',
                            'title' => 'Informasjon til eksterne leverandører',
                            'description' => 'Virksomheten skal sikre at kravene er tilstrekkelige før de kommuniseres til leverandøren, og kommunisere krav til hva som skal leveres; godkjenning av produkter, tjenester, metoder, prosesser, utstyr og frigivelse; kompetanse og eventuelle kvalifikasjoner; samhandlingen med virksomheten; styring og oppfølging av leverandørens ytelse; og verifisering eller validering virksomheten eller kunden vil gjennomføre hos leverandøren.',
                            'guidance' => 'Bestillinger, kontrakter og kravspesifikasjoner til leverandører er typiske bevis.',
                            'documentation' => ['Kontrakter og bestillinger med krav til leverandøren'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['supplier'],
                        ],
                    ],
                ],
                [
                    'clause' => '8.5',
                    'title' => 'Produksjon og tjenesteyting',
                    'children' => [
                        [
                            'clause' => '8.5.1',
                            'title' => 'Styrte forhold for produksjon og tjenesteyting',
                            'description' => 'Produksjon og tjenesteyting skal skje under styrte forhold. Der det er relevant, omfatter det tilgjengelig dokumentert informasjon om produktenes og tjenestenes egenskaper og resultatene som skal oppnås; egnede ressurser for overvåking og måling og bruk av dem; kontroll på egnede trinn; egnet infrastruktur og miljø; kompetent personell; validering og jevnlig revalidering av prosesser der resultatet ikke kan kontrolleres i etterkant; tiltak for å forebygge menneskelige feil; og aktiviteter for frigivelse, levering og oppfølging etter levering.',
                            'guidance' => 'Arbeidsbeskrivelser, sjekklister og kontrollpunkter i leveranseprosessen er typiske bevis.',
                            'documentation' => ['Arbeidsbeskrivelser og sjekklister', 'Kontrollpunkter i leveranseprosessen'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['quality'],
                        ],
                        [
                            'clause' => '8.5.2',
                            'title' => 'Identifikasjon og sporbarhet',
                            'description' => 'Virksomheten skal der det er nødvendig, bruke egnede metoder for å identifisere det som produseres eller leveres, slik at samsvar med krav kan sikres, og identifisere status med hensyn til kontroll gjennom hele produksjonen og tjenesteytingen. Når sporbarhet er et krav, skal virksomheten styre den unike identifikasjonen og oppbevare dokumentert informasjon som trengs for å sikre sporbarheten.',
                            'guidance' => 'For tjenester kan dette være saksnumre, versjonsnumre eller status i et sakssystem.',
                            'documentation' => ['Merking, saksnummer- eller versjonsrutiner', 'Sporbarhetsregistreringer der det kreves'],
                            'type' => 'activity', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => [],
                        ],
                        [
                            'clause' => '8.5.3',
                            'title' => 'Eiendom som tilhører kunder eller eksterne leverandører',
                            'description' => 'Virksomheten skal ta vare på eiendom som tilhører kunder eller eksterne leverandører, mens den er under virksomhetens kontroll eller i bruk. Eiendommen skal identifiseres, verifiseres, beskyttes og sikres. Blir den tapt, skadet eller funnet uegnet, skal eieren informeres, og dokumentert informasjon om hva som har skjedd, skal oppbevares. Eiendom kan også være immaterielle verdier og personopplysninger.',
                            'guidance' => 'Gjelder virksomheter som har kundens utstyr, materialer, data eller personopplysninger i hende. Mange har det – for eksempel kundedata i et system – så vurder punktet nøye før det settes som ikke relevant.',
                            'documentation' => ['Rutine for håndtering av kundens eiendom og data', 'Registreringer av tap eller skade'],
                            'type' => 'activity', 'info' => 'retain', 'rule' => 'conditional', 'modules' => [],
                        ],
                        [
                            'clause' => '8.5.4',
                            'title' => 'Bevaring',
                            'description' => 'Virksomheten skal bevare det som produseres eller leveres, i nødvendig omfang under produksjon og tjenesteyting for å sikre samsvar med krav. Bevaring kan omfatte identifikasjon, håndtering, beskyttelse mot forurensning, emballering, lagring, overføring og transport.',
                            'guidance' => 'For tjenester og digitale leveranser kan dette være sikker lagring, sikkerhetskopiering og trygg overføring av data.',
                            'documentation' => ['Rutiner for lagring, håndtering og transport', 'Rutiner for sikkerhetskopiering av leveranser'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => [],
                        ],
                        [
                            'clause' => '8.5.5',
                            'title' => 'Aktiviteter etter levering',
                            'description' => 'Virksomheten skal oppfylle kravene til aktiviteter etter levering av produktene og tjenestene. Omfanget skal fastsettes ut fra lov- og myndighetskrav, mulige uønskede konsekvenser, produktenes og tjenestenes art, bruk og levetid, kundens krav og tilbakemeldinger fra kunder.',
                            'guidance' => 'For eksempel garanti, vedlikehold, support og avtalt oppfølging. Har virksomheten ingen slike forpliktelser, kan punktet vurderes som ikke relevant med begrunnelse.',
                            'documentation' => ['Garanti-, service- eller supportavtaler', 'Rutine for oppfølging etter levering'],
                            'type' => 'activity', 'info' => null, 'rule' => 'conditional', 'modules' => [],
                        ],
                        [
                            'clause' => '8.5.6',
                            'title' => 'Styring av endringer i produksjon og tjenesteyting',
                            'description' => 'Virksomheten skal gjennomgå og styre endringer i produksjon eller tjenesteyting i nødvendig omfang for å sikre at kravene fortsatt oppfylles. Dokumentert informasjon om resultatet av gjennomgangen, hvem som godkjente endringen, og nødvendige tiltak skal oppbevares.',
                            'guidance' => 'Endringsrutiner (for eksempel change management i IT-drift) er vanlige bevis.',
                            'documentation' => ['Endringsrutine', 'Endringslogg med godkjenning'],
                            'type' => 'activity', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => ['quality'],
                        ],
                    ],
                ],
                [
                    'clause' => '8.6',
                    'title' => 'Frigivelse av produkter og tjenester',
                    'description' => 'Virksomheten skal gjennomføre planlagte kontroller på egnede trinn for å verifisere at kravene til produktene og tjenestene er oppfylt. Frigivelse til kunden skal ikke skje før kontrollene er gjennomført med tilfredsstillende resultat, med mindre en relevant myndighet – eller eventuelt kunden – har godkjent noe annet. Dokumentert informasjon om frigivelsen skal oppbevares, med bevis for samsvar med akseptkriteriene og sporbarhet til hvem som godkjente frigivelsen.',
                    'guidance' => 'For tilbudsarbeid tilsvarer dette kvalitetssikring og godkjenning før tilbudet sendes. For tjenester kan det være leveransegodkjenning eller akseptansetest.',
                    'documentation' => ['Sluttkontroll eller sjekkliste før levering', 'Godkjenning med navn og dato'],
                    'type' => 'activity', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => ['quality', 'tender'],
                ],
                [
                    'clause' => '8.7',
                    'title' => 'Styring av avvikende output',
                    'children' => [
                        [
                            'clause' => '8.7.1',
                            'title' => 'Håndtering av avvikende output',
                            'description' => 'Virksomheten skal sørge for at det som ikke samsvarer med krav, identifiseres og styres slik at det ikke blir brukt eller levert ved en feil. Det skal iverksettes egnede tiltak ut fra avvikets art og virkning, også når avvik oppdages etter levering – for eksempel korrigering, atskillelse eller stans, informasjon til kunden, eller godkjenning for bruk på særvilkår. Når avvikende output er korrigert, skal samsvar med kravene verifiseres på nytt.',
                            'guidance' => 'Gjelder feil i leveranser, ikke avvik i systemet som sådan (de håndteres etter 10.2). Avviksregistrering med tiltak og verifisering er vanlig bevis.',
                            'documentation' => ['Rutine for håndtering av avvikende leveranser', 'Avviksregistreringer'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['improvements'],
                        ],
                        [
                            'clause' => '8.7.2',
                            'title' => 'Dokumentasjon av avvikende output',
                            'description' => 'Virksomheten skal oppbevare dokumentert informasjon som beskriver avviket, tiltakene som ble iverksatt, eventuelle særvilkår som er gitt, og hvem som besluttet hvordan avviket skulle håndteres.',
                            'guidance' => 'En avviksregistrering med beskrivelse, tiltak og beslutningstaker dekker kravet.',
                            'documentation' => ['Avviksregistreringer med tiltak og beslutningstaker'],
                            'type' => 'documented_information', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => ['improvements'],
                        ],
                    ],
                ],
            ],
        ],
        [
            'clause' => '9',
            'title' => 'Evaluering av prestasjon',
            'children' => [
                [
                    'clause' => '9.1',
                    'title' => 'Overvåking, måling, analyse og evaluering',
                    'children' => [
                        [
                            'clause' => '9.1.1',
                            'title' => 'Overvåking og måling – generelt',
                            'description' => 'Virksomheten skal fastsette hva som skal overvåkes og måles, hvilke metoder som skal gi gyldige resultater, når det skal overvåkes og måles, og når resultatene skal analyseres og evalueres. Kvalitetsstyringssystemets ytelse og effektivitet skal evalueres, og dokumentert informasjon skal oppbevares som bevis på resultatene.',
                            'guidance' => 'Et sett med KPI-er med målemetode, hyppighet og ansvarlig er den vanlige løsningen.',
                            'documentation' => ['Oversikt over indikatorer med målemetode og hyppighet', 'Måleresultater'],
                            'type' => 'activity', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => ['objectives'],
                        ],
                        [
                            'clause' => '9.1.2',
                            'title' => 'Kundetilfredshet',
                            'description' => 'Virksomheten skal følge med på hvordan kundene oppfatter at deres behov og forventninger er oppfylt, og fastsette metoder for å innhente, følge opp og gjennomgå denne informasjonen.',
                            'guidance' => 'Kundeundersøkelser, kundemøter, klager, tilbakemeldinger fra oppdragsgivere og resultatet av tilbudsevalueringer kan alle brukes.',
                            'documentation' => ['Kundeundersøkelser eller referater fra kundemøter', 'Analyse av klager og tilbakemeldinger'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['objectives'],
                        ],
                        [
                            'clause' => '9.1.3',
                            'title' => 'Analyse og evaluering',
                            'description' => 'Virksomheten skal analysere og evaluere relevante data og informasjon fra overvåking og måling. Resultatene skal brukes til å vurdere blant annet om produktene og tjenestene samsvarer med krav, kundetilfredsheten, systemets ytelse og effektivitet, om planleggingen er gjennomført effektivt, effekten av tiltak mot risikoer og muligheter, eksterne leverandørers ytelse, og behovet for forbedringer.',
                            'guidance' => 'Analysen samles gjerne som grunnlag for ledelsens gjennomgang.',
                            'documentation' => ['Analyserapport eller dashboard', 'Grunnlag for ledelsens gjennomgang'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['objectives', 'risk', 'supplier'],
                        ],
                    ],
                ],
                [
                    'clause' => '9.2',
                    'title' => 'Internrevisjon',
                    'children' => [
                        [
                            'clause' => '9.2.1',
                            'title' => 'Gjennomføre internrevisjoner',
                            'description' => 'Virksomheten skal gjennomføre internrevisjoner med planlagte mellomrom for å få vite om kvalitetsstyringssystemet samsvarer med virksomhetens egne krav og kravene i standarden, og om det er effektivt implementert og vedlikeholdt.',
                            'guidance' => 'Alle deler av systemet bør revideres i løpet av en sertifikatperiode. Revisjonene kan planlegges og gjennomføres under Revisjoner i Procynia.',
                            'documentation' => ['Revisjonsrapporter'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['compliance_audits'],
                        ],
                        [
                            'clause' => '9.2.2',
                            'title' => 'Revisjonsprogram og gjennomføring',
                            'description' => 'Virksomheten skal planlegge, etablere, implementere og vedlikeholde et revisjonsprogram med hyppighet, metoder, ansvar, planleggingskrav og rapportering, ut fra hvor viktige prosessene er, endringer som påvirker virksomheten, og resultater fra tidligere revisjoner. For hver revisjon skal kriterier og omfang fastsettes, revisorer velges slik at revisjonen blir objektiv og upartisk, resultatene rapporteres til relevant ledelse, og nødvendige korrigeringer og korrigerende tiltak iverksettes uten unødig opphold. Dokumentert informasjon skal oppbevares som bevis på gjennomføringen og resultatene.',
                            'guidance' => 'Revisor skal ikke revidere sitt eget arbeid. Funn fra revisjoner kan sendes videre til Avvik og forbedringer for oppfølging.',
                            'documentation' => ['Revisjonsprogram for perioden', 'Revisjonsplaner og -rapporter', 'Oppfølging av revisjonsfunn'],
                            'type' => 'activity', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => ['compliance_audits', 'improvements'],
                        ],
                    ],
                ],
                [
                    'clause' => '9.3',
                    'title' => 'Ledelsens gjennomgang',
                    'children' => [
                        [
                            'clause' => '9.3.1',
                            'title' => 'Gjennomføre ledelsens gjennomgang',
                            'description' => 'Toppledelsen skal gjennomgå kvalitetsstyringssystemet med planlagte mellomrom for å sikre at det fortsatt er egnet, tilstrekkelig og effektivt, og i tråd med virksomhetens strategiske retning.',
                            'guidance' => 'De fleste gjennomfører ledelsens gjennomgang minst én gang i året.',
                            'documentation' => ['Plan for ledelsens gjennomgang', 'Innkalling og referat'],
                            'type' => 'leadership', 'info' => null, 'rule' => 'mandatory', 'modules' => ['management_review'],
                        ],
                        [
                            'clause' => '9.3.2',
                            'title' => 'Grunnlag for ledelsens gjennomgang',
                            'description' => 'Ledelsens gjennomgang skal planlegges og gjennomføres med blant annet status for tiltak fra tidligere gjennomganger; endringer i interne og eksterne forhold; informasjon om systemets ytelse og effektivitet (kundetilfredshet og tilbakemeldinger fra interessenter, måloppnåelse, prosessenes ytelse og samsvar for produkter og tjenester, avvik og korrigerende tiltak, resultater fra overvåking og måling, revisjonsresultater og eksterne leverandørers ytelse); om ressursene er tilstrekkelige; effekten av tiltak mot risikoer og muligheter; og muligheter for forbedring.',
                            'guidance' => 'Bruk en fast agenda som dekker alle temaene, slik at ingenting faller ut.',
                            'documentation' => ['Agenda og underlag som dekker alle innspillene'],
                            'type' => 'leadership', 'info' => null, 'rule' => 'mandatory', 'modules' => ['management_review'],
                        ],
                        [
                            'clause' => '9.3.3',
                            'title' => 'Resultat av ledelsens gjennomgang',
                            'description' => 'Resultatet av ledelsens gjennomgang skal omfatte beslutninger og tiltak om forbedringsmuligheter, eventuelle behov for endringer i kvalitetsstyringssystemet, og ressursbehov. Dokumentert informasjon skal oppbevares som bevis på resultatene.',
                            'guidance' => 'Referatet bør vise konkrete beslutninger med ansvarlig og frist, ikke bare at temaene ble diskutert.',
                            'documentation' => ['Referat med beslutninger, tiltak og ressursbehov'],
                            'type' => 'leadership', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => ['management_review'],
                        ],
                    ],
                ],
            ],
        ],
        [
            'clause' => '10',
            'title' => 'Forbedring',
            'children' => [
                [
                    'clause' => '10.1',
                    'title' => 'Forbedring – generelt',
                    'description' => 'Virksomheten skal fastsette og velge ut forbedringsmuligheter og iverksette de tiltakene som trengs for å oppfylle kundekravene og øke kundetilfredsheten. Det omfatter å forbedre produkter og tjenester – også med tanke på fremtidige behov –, å korrigere, forebygge eller redusere uønskede virkninger, og å forbedre kvalitetsstyringssystemets ytelse og effektivitet.',
                    'guidance' => 'Forbedringer kan komme fra avvik, revisjoner, kundetilbakemeldinger, tapte og vunne konkurranser og forslag fra medarbeidere.',
                    'documentation' => ['Forbedringslogg eller forbedringsforslag', 'Gjennomførte forbedringstiltak'],
                    'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['improvements'],
                ],
                [
                    'clause' => '10.2',
                    'title' => 'Avvik og korrigerende tiltak',
                    'children' => [
                        [
                            'clause' => '10.2.1',
                            'title' => 'Håndtere avvik og korrigerende tiltak',
                            'description' => 'Når et avvik oppstår, også fra klager, skal virksomheten reagere på det – styre og korrigere avviket og håndtere konsekvensene –, vurdere om det trengs tiltak for å fjerne årsaken slik at avviket ikke gjentar seg eller oppstår andre steder, iverksette nødvendige tiltak, gjennomgå om de korrigerende tiltakene har virket, oppdatere risikoer og muligheter ved behov, og endre kvalitetsstyringssystemet ved behov. Tiltakene skal stå i forhold til virkningene av avvikene.',
                            'guidance' => 'Årsaksanalyse og effektverifisering er det revisor oftest finner mangler ved. Registrer avvik med årsak, tiltak og verifisering av effekt.',
                            'documentation' => ['Rutine for avvikshåndtering', 'Avvik med årsaksanalyse, tiltak og effektverifisering'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['improvements', 'risk'],
                        ],
                        [
                            'clause' => '10.2.2',
                            'title' => 'Dokumentasjon av avvik og tiltak',
                            'description' => 'Virksomheten skal oppbevare dokumentert informasjon som bevis på hva slags avvik som har oppstått, hvilke tiltak som er iverksatt, og resultatet av korrigerende tiltak.',
                            'guidance' => 'En avvikslogg med historikk dekker kravet.',
                            'documentation' => ['Avvikslogg med tiltak og resultat'],
                            'type' => 'documented_information', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => ['improvements'],
                        ],
                    ],
                ],
                [
                    'clause' => '10.3',
                    'title' => 'Kontinuerlig forbedring',
                    'description' => 'Virksomheten skal kontinuerlig forbedre kvalitetsstyringssystemets egnethet, tilstrekkelighet og effektivitet. Resultater fra analyse og evaluering og fra ledelsens gjennomgang skal brukes til å vurdere om det finnes behov eller muligheter som skal følges opp som en del av den kontinuerlige forbedringen.',
                    'guidance' => 'Vis sammenhengen: måling og analyse → ledelsens gjennomgang → beslutninger → gjennomførte forbedringer.',
                    'documentation' => ['Beslutninger om forbedring fra ledelsens gjennomgang', 'Status for forbedringstiltak'],
                    'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['improvements', 'management_review'],
                ],
            ],
        ],
    ],
];
