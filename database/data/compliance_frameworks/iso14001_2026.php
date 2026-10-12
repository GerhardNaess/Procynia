<?php

/*
 * Kravbiblioteket — ISO 14001:2026 (fjerde utgave, publisert 15. april 2026). Read by
 * ComplianceFrameworkLibraryImporter through ComplianceFrameworkLibrarySeeder; never by a request.
 *
 * COPYRIGHT. ISO 14001 is copyrighted by ISO (in Norway sold as NS-EN ISO 14001 by Standard Norge).
 * Nothing here is the standard's text: the clause numbers and the structure are facts about the
 * standard; titles, descriptions and guidance are Procynia's own, condensed Norwegian wording.
 *
 * EDITION. ISO 14001:2026 only. It replaces ISO 14001:2015 and its climate amendment (A1:2024);
 * certificates to the 2015 edition stay valid through a transition ending in 2029. The 2015 edition
 * is deliberately not offered: new work should follow the current edition. Main structural changes
 * from 2015, as published: 6.1.4 Risikoer og muligheter is its own clause and planning of actions
 * moved to 6.1.5; 6.3 Planlegging av endringer is new; 9.3 is split into 9.3.1–9.3.3; continual
 * improvement is merged into 10.1, so there is no 10.3.
 *
 * VERIFICATION. 'content_verified' stays false. The clause structure was checked against public,
 * consistent summaries of the 2026 edition (ISO's announcement and certification bodies'
 * clause-by-clause comparisons), not against a licensed copy; descriptions follow those summaries
 * and are kept general where the exact 2026 wording is not public. See verification_note.
 *
 * Every requirement in chapters 4–10 is rule = mandatory: the standard has no optional clauses.
 *
 * STABILITY. 'key' and every clause number are stable identities (node_key). Never renumber or
 * reuse one; a clause that should go is removed from this file and the importer withdraws it.
 *
 * Fields: see iso9001_2015.php.
 */

return [
    'key' => 'iso14001:2026',
    'catalog_key' => 'iso14001',
    'reference' => 'ISO 14001',
    'edition' => '2026',
    'name' => 'ISO 14001 – Miljøstyring',
    'description' => 'Den internasjonale standarden for miljøstyring. Den stiller krav til hvordan virksomheten forstår sin kontekst og miljøforhold, kartlegger miljøaspekter og samsvarsforpliktelser, setter miljømål, styrer driften og beredskapen, overvåker og evaluerer miljøprestasjonen og forbedrer seg. Kravene i kapittel 4 til 10 kan sertifiseres av et akkreditert sertifiseringsorgan.',
    'scope_note' => 'Kravene gjelder innenfor det omfanget dere har fastsatt for miljøstyringssystemet (punkt 4.3). Standarden har ingen valgfrie krav: alle punktene i kapittel 4–10 gjelder innenfor omfanget, men hvor omfattende arbeidet må være, avhenger av virksomhetens miljøaspekter og samsvarsforpliktelser.',
    'content_verified' => false,
    'verification_note' => 'Gjelder ISO 14001:2026, publisert 15. april 2026. Punktstrukturen (kapittel 4–10 med underpunkter) er kontrollert mot offentlig tilgjengelige sammenstillinger av endringene fra 2015-utgaven, ikke mot en lisensiert utgave. Titler, kravbeskrivelser og veiledning er Procynias egne, sammenfattede formuleringer og er ikke standardens tekst. De er ennå ikke faglig kvalitetssikret. Bruk standarden selv som grunnlag ved sertifisering.',
    'position' => 30,

    'chapters' => [
        [
            'clause' => '4',
            'title' => 'Organisasjonens kontekst',
            'children' => [
                [
                    'clause' => '4.1',
                    'title' => 'Forstå organisasjonen og dens kontekst',
                    'description' => 'Virksomheten skal kartlegge de interne og eksterne forholdene som er relevante for formålet, og som påvirker evnen til å oppnå det miljøstyringssystemet skal gi. Dette omfatter miljøforhold som virksomheten påvirker eller blir påvirket av, for eksempel klima og klimaendringer, forurensning, naturmangfold og økosystemer og tilgang på naturressurser. Forholdene skal følges med på og gjennomgås jevnlig.',
                    'guidance' => 'Løses ofte med en kontekstanalyse som har en egen del om miljøforhold, og som oppdateres i strategiarbeidet og i ledelsens gjennomgang. Revisor ser etter at miljøforholdene er vurdert konkret for egen virksomhet, ikke bare listet opp generelt.',
                    'documentation' => ['Kontekstanalyse med vurdering av miljøforhold og klimaendringer', 'Referat fra ledelsens gjennomgang'],
                    'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['risk', 'management_review'],
                ],
                [
                    'clause' => '4.2',
                    'title' => 'Forstå behovene og forventningene til interessenter',
                    'description' => 'Virksomheten skal fastsette hvilke interessenter som er relevante for miljøstyringssystemet, hvilke behov og forventninger de har, og hvilke av disse som blir forpliktelser virksomheten må eller velger å etterleve. Behov og forventninger som blir slike forpliktelser, skal håndteres gjennom miljøstyringssystemet.',
                    'guidance' => 'Typiske interessenter er myndigheter, naboer og lokalsamfunn, kunder og oppdragsgivere, eiere, ansatte og leverandører. Oppdragsgiveres miljøkrav i offentlige anskaffelser er ofte viktige. Vis tydelig hvilke forventninger som er gjort til forpliktelser, slik at de følges opp under 6.1.3.',
                    'documentation' => ['Interessentanalyse med behov og forventninger', 'Oversikt over forventninger som er gjort til forpliktelser'],
                    'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => [],
                ],
                [
                    'clause' => '4.3',
                    'title' => 'Fastsette omfanget av miljøstyringssystemet',
                    'description' => 'Virksomheten skal fastsette grensene for miljøstyringssystemet og hva det gjelder for, ut fra konteksten (4.1), forpliktelsene (6.1.3), organisatoriske enheter, funksjoner og fysiske grenser, aktiviteter, produkter og tjenester, og egen myndighet og evne til å styre og påvirke. Omfanget skal være tilgjengelig som dokumentert informasjon.',
                    'guidance' => 'Omfanget må ikke avgrenses slik at aktiviteter med vesentlig miljøpåvirkning eller forpliktelser holdes utenfor. Revisor sammenligner omfangsbeskrivelsen med det som faktisk skjer på lokasjoner og i leveranser.',
                    'documentation' => ['Omfangsbeskrivelse for miljøstyringssystemet', 'Oversikt over lokasjoner og aktiviteter som omfattes'],
                    'type' => 'activity', 'info' => 'maintain', 'rule' => 'mandatory', 'modules' => [],
                ],
                [
                    'clause' => '4.4',
                    'title' => 'Miljøstyringssystemet',
                    'description' => 'Virksomheten skal etablere, implementere, vedlikeholde og kontinuerlig forbedre et miljøstyringssystem med de prosessene som trengs og hvordan de virker sammen, for å oppnå tiltenkte resultater og forbedre miljøprestasjonen. Kunnskapen fra 4.1 og 4.2 skal brukes når systemet bygges og vedlikeholdes.',
                    'guidance' => 'Et prosesskart som viser hvor miljøkravene er bygd inn i de vanlige arbeidsprosessene, er en god måte å vise dette på. En vanlig svakhet er et miljøsystem som lever på siden av driften.',
                    'documentation' => ['Prosesskart', 'Beskrivelse av miljøstyringssystemet og hvordan det henger sammen med øvrig styring'],
                    'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['quality'],
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
                    'description' => 'Toppledelsen skal vise lederskap og forpliktelse til miljøstyringssystemet. Det innebærer blant annet å ta ansvar for at systemet er effektivt, sørge for at miljøpolitikk og miljømål passer med strategien, integrere miljøkravene i virksomhetens prosesser, sikre ressurser, kommunisere hvorfor miljøstyring er viktig og fremme forbedring. Andre relevante ledere og roller skal støttes i å vise lederskap innenfor sitt ansvar.',
                    'guidance' => 'Revisor intervjuer normalt toppledelsen. Bevis kan være referater fra ledermøter og ledelsens gjennomgang, ressursbeslutninger og hvordan ledelsen følger opp miljømål, avvik og etterlevelse.',
                    'documentation' => ['Referater fra ledermøter', 'Referat fra ledelsens gjennomgang', 'Beslutninger om ressurser til miljøarbeidet'],
                    'type' => 'leadership', 'info' => null, 'rule' => 'mandatory', 'modules' => ['management_review'],
                ],
                [
                    'clause' => '5.2',
                    'title' => 'Miljøpolitikk',
                    'description' => 'Toppledelsen skal fastsette, implementere og vedlikeholde en miljøpolitikk som passer formålet og konteksten, gir rammer for miljømålene og inneholder forpliktelser til å beskytte miljøet, herunder å forebygge forurensning, til å oppfylle forpliktelsene og til kontinuerlig forbedring. Politikken skal være dokumentert, kommunisert internt og tilgjengelig for interessenter.',
                    'guidance' => 'Politikken bør være kort og konkret nok til at den faktisk styrer miljømålene. Revisor sjekker at de tre forpliktelsene finnes, at ansatte kjenner politikken, og at den er tilgjengelig eksternt, for eksempel på nettsiden.',
                    'documentation' => ['Godkjent miljøpolitikk', 'Publisering på intranett og nettside'],
                    'type' => 'leadership', 'info' => 'maintain', 'rule' => 'mandatory', 'modules' => ['wiki'],
                ],
                [
                    'clause' => '5.3',
                    'title' => 'Roller, ansvar og myndighet',
                    'description' => 'Toppledelsen skal sørge for at ansvar og myndighet for relevante roller er tildelt og kommunisert. Det skal være tildelt ansvar for at miljøstyringssystemet oppfyller kravene i standarden, og for å rapportere til toppledelsen om hvordan systemet og miljøprestasjonen utvikler seg.',
                    'guidance' => 'Ofte en miljøansvarlig eller miljøkoordinator, men ansvaret for miljø i den daglige driften bør ligge hos linjelederne og prosesseierne. Revisor ser etter at ansvaret er kjent, ikke bare skrevet ned.',
                    'documentation' => ['Organisasjonskart', 'Rollebeskrivelser med miljøansvar', 'Utnevnelse av miljøansvarlig'],
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
                            'title' => 'Generelt om planleggingen',
                            'description' => 'Virksomheten skal etablere og gjennomføre en planleggingsprosess som samler arbeidet med miljøaspekter, forpliktelser og risikoer og muligheter (6.1.2–6.1.5). Planleggingen skal ta hensyn til konteksten (4.1), interessentenes behov og forventninger (4.2) og omfanget av systemet (4.3).',
                            'guidance' => 'Vis hvordan planleggingen henger sammen: fra kontekst og interessenter, via aspekter og forpliktelser, til risikoer og konkrete tiltak. Et fast årshjul for oppdatering gjør det lettere å vise at prosessen faktisk gjennomføres.',
                            'documentation' => ['Beskrivelse av planleggingsprosessen', 'Årshjul for miljøplanlegging'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['risk', 'quality'],
                        ],
                        [
                            'clause' => '6.1.2',
                            'title' => 'Miljøaspekter',
                            'description' => 'Virksomheten skal kartlegge miljøaspektene ved sine aktiviteter, produkter og tjenester som den kan styre eller påvirke, og tilhørende miljøpåvirkninger, i et livsløpsperspektiv. Kartleggingen skal omfatte normale og unormale driftsforhold, rimelig forutsigbare nødsituasjoner og planlagte endringer (6.3). Virksomheten skal fastsette kriterier for hva som er vesentlige miljøaspekter, bruke dem og kommunisere de vesentlige aspektene internt.',
                            'guidance' => 'Typiske aspekter er energibruk, utslipp til luft og vann, avfall, kjemikalier, transport, innkjøp og bruk og avhending av egne produkter. Revisor ser etter faste, etterprøvbare vesentlighetskriterier, at hele livsløpet er vurdert og at registeret oppdateres ved endringer. Vanlige svakheter er at bare egen drift er vurdert, eller at unormale forhold og nødsituasjoner mangler.',
                            'documentation' => ['Register over miljøaspekter og miljøpåvirkninger', 'Kriterier for vesentlighet', 'Oversikt over vesentlige miljøaspekter'],
                            'type' => 'activity', 'info' => 'maintain', 'rule' => 'mandatory', 'modules' => ['risk', 'quality'],
                        ],
                        [
                            'clause' => '6.1.3',
                            'title' => 'Forpliktelser',
                            'description' => 'Virksomheten skal kartlegge og ha tilgang til forpliktelsene knyttet til sine miljøaspekter, det vil si lovkrav og andre krav som virksomheten må eller velger å etterleve. Virksomheten skal fastsette hvordan forpliktelsene gjelder for egen virksomhet, og ta hensyn til dem i miljøstyringssystemet.',
                            'guidance' => 'Lag et lovregister som er koblet til aspektene, for eksempel forurensningsloven, avfallsregler og eventuelle utslippstillatelser eller vilkår fra myndighetene, i tillegg til kundekrav, bransjeavtaler og frivillige forpliktelser. Utpek hvem som følger med på endringer. Revisor ser etter at registeret er oppdatert og at kravene faktisk er omsatt i rutiner.',
                            'documentation' => ['Register over forpliktelser (lov- og andre krav)', 'Tillatelser og vilkår fra myndighetene', 'Rutine for å følge med på regelverksendringer'],
                            'type' => 'activity', 'info' => 'maintain', 'rule' => 'mandatory', 'modules' => ['risk', 'wiki'],
                        ],
                        [
                            'clause' => '6.1.4',
                            'title' => 'Risikoer og muligheter',
                            'description' => 'Virksomheten skal fastsette risikoer og muligheter knyttet til miljøaspektene, forpliktelsene og andre forhold fra 4.1 og 4.2 som må håndteres. Målet er å sikre at miljøstyringssystemet oppnår tiltenkte resultater, forebygge eller redusere uønskede virkninger, og oppnå kontinuerlig forbedring.',
                            'guidance' => 'Bruk gjerne samme risikometode som ellers i virksomheten, men gjør miljørisikoene synlige og knyttet til aspektene og forpliktelsene. Husk muligheter også, for eksempel mindre ressursbruk eller nye kundekrav som kan gi konkurransefortrinn.',
                            'documentation' => ['Risikoregister med miljørisikoer og muligheter', 'Risikovurderinger knyttet til miljøaspekter'],
                            'type' => 'activity', 'info' => 'maintain', 'rule' => 'mandatory', 'modules' => ['risk'],
                        ],
                        [
                            'clause' => '6.1.5',
                            'title' => 'Planlegge tiltak',
                            'description' => 'Virksomheten skal planlegge tiltak for å håndtere de vesentlige miljøaspektene, forpliktelsene og risikoene og mulighetene. Den skal planlegge hvordan tiltakene bygges inn i miljøstyringssystemet og andre forretningsprosesser, og hvordan virkningen av dem evalueres. Teknologiske muligheter og økonomiske og driftsmessige forhold skal tas i betraktning.',
                            'guidance' => 'Hvert vesentlig aspekt, hver forpliktelse og hver vesentlig risiko bør kunne spores til et tiltak, et mål, en rutine eller en beredskapsplan. Revisor ser etter at tiltakene har ansvarlig, frist og oppfølging.',
                            'documentation' => ['Tiltaksplan koblet til aspekter, forpliktelser og risikoer', 'Oppfølging av tiltakenes virkning'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['risk', 'objectives'],
                        ],
                    ],
                ],
                [
                    'clause' => '6.2',
                    'title' => 'Miljømål og planlegging for å nå dem',
                    'children' => [
                        [
                            'clause' => '6.2.1',
                            'title' => 'Miljømål',
                            'description' => 'Virksomheten skal fastsette miljømål for relevante funksjoner og nivåer, med utgangspunkt i de vesentlige miljøaspektene, forpliktelsene og risikoene og mulighetene. Målene skal være i tråd med miljøpolitikken, målbare der det er praktisk mulig, fulgt opp, kommunisert og oppdatert ved behov.',
                            'guidance' => 'Gode miljømål er konkrete, for eksempel redusert energibruk, mindre restavfall eller færre utslippshendelser, med utgangspunkt, målverdi og tidsfrist. Revisor sjekker at målene henger sammen med de vesentlige aspektene og ikke bare er generelle ambisjoner.',
                            'documentation' => ['Oversikt over miljømål med indikatorer og målverdier', 'Rapportering på måloppnåelse'],
                            'type' => 'activity', 'info' => 'maintain', 'rule' => 'mandatory', 'modules' => ['objectives'],
                        ],
                        [
                            'clause' => '6.2.2',
                            'title' => 'Planlegge tiltak for å nå miljømålene',
                            'description' => 'For hvert miljømål skal virksomheten planlegge hva som skal gjøres, hvilke ressurser som trengs, hvem som er ansvarlig, når det skal være ferdig og hvordan resultatene skal evalueres, herunder med hvilke indikatorer. Virksomheten skal vurdere hvordan tiltakene bygges inn i forretningsprosessene.',
                            'guidance' => 'En enkel handlingsplan per mål med tiltak, ansvarlig, frist og indikator er vanlig. Revisor ser etter at planene faktisk følges opp gjennom året.',
                            'documentation' => ['Handlingsplaner for miljømålene', 'Statusrapporter på tiltak og indikatorer'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['objectives'],
                        ],
                    ],
                ],
                [
                    'clause' => '6.3',
                    'title' => 'Planlegging av endringer',
                    'description' => 'Når virksomheten fastslår at det er behov for endringer i miljøstyringssystemet, skal endringene gjennomføres på en planlagt måte. Formålet med endringen, mulige konsekvenser, betydningen for miljøaspektene og forpliktelsene, ressurser og ansvar skal vurderes før endringen gjennomføres.',
                    'guidance' => 'Typiske endringer er nye lokasjoner, nye prosesser eller produkter, omorganisering og nytt regelverk. En enkel sjekkliste for endringer som også spør om miljøaspekter og forpliktelser er ofte nok.',
                    'documentation' => ['Endringsvurderinger', 'Sjekkliste for planlagte endringer'],
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
                    'description' => 'Virksomheten skal fastsette og stille til rådighet de ressursene som trengs for å etablere, implementere, vedlikeholde og kontinuerlig forbedre miljøstyringssystemet.',
                    'guidance' => 'Ressurser omfatter folk, kompetanse, tid, budsjett, infrastruktur og teknologi. Revisor ser etter at ressursbehovet vurderes, for eksempel i budsjettprosessen og ledelsens gjennomgang.',
                    'documentation' => ['Budsjett eller ressursplan for miljøarbeidet', 'Referat fra ledelsens gjennomgang'],
                    'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['management_review'],
                ],
                [
                    'clause' => '7.2',
                    'title' => 'Kompetanse',
                    'description' => 'Virksomheten skal fastsette hvilken kompetanse som trengs hos personer som utfører arbeid som påvirker miljøprestasjonen og evnen til å oppfylle forpliktelsene, og sørge for at de har den. Opplæringsbehov knyttet til miljøaspektene og miljøstyringssystemet skal fastsettes, nødvendige tiltak gjennomføres og virkningen evalueres. Dokumentert informasjon skal oppbevares som bevis på kompetanse.',
                    'guidance' => 'Typiske eksempler er opplæring i avfallshåndtering, kjemikaliehåndtering, beredskap og miljøkrav i innkjøp. Revisor ser etter en kompetanseoversikt for nøkkelroller og bevis på gjennomført opplæring.',
                    'documentation' => ['Kompetansekrav for roller med miljøpåvirkning', 'Opplæringsregister', 'Kursbevis og sertifikater'],
                    'type' => 'activity', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => [],
                ],
                [
                    'clause' => '7.3',
                    'title' => 'Bevissthet',
                    'description' => 'Personer som arbeider under virksomhetens styring skal kjenne miljøpolitikken, de vesentlige miljøaspektene og påvirkningene knyttet til eget arbeid, sitt bidrag til at miljøstyringssystemet fungerer, og konsekvensene av å ikke følge kravene, herunder forpliktelsene.',
                    'guidance' => 'Gjelder også innleide og andre som arbeider for virksomheten. Revisor spør gjerne ansatte direkte om hvilke miljøaspekter som gjelder for deres arbeid.',
                    'documentation' => ['Introduksjonsprogram med miljødel', 'Registrering av gjennomgått informasjon'],
                    'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => [],
                ],
                [
                    'clause' => '7.4',
                    'title' => 'Kommunikasjon',
                    'children' => [
                        [
                            'clause' => '7.4.1',
                            'title' => 'Generelt om kommunikasjon',
                            'description' => 'Virksomheten skal etablere prosesser for intern og ekstern kommunikasjon om miljøstyringssystemet: hva, når, med hvem og hvordan det kommuniseres. Forpliktelsene skal tas hensyn til, og miljøinformasjon som kommuniseres skal være pålitelig og samsvare med informasjon fra miljøstyringssystemet. Relevante henvendelser skal besvares, og bevis på kommunikasjonen skal oppbevares i nødvendig omfang.',
                            'guidance' => 'En enkel kommunikasjonsplan er ofte nok. Vær særlig nøye med at miljøpåstander utad stemmer med egne målinger, slik at virksomheten ikke kommer med udokumenterte påstander.',
                            'documentation' => ['Kommunikasjonsplan for miljø', 'Logg over eksterne henvendelser og svar'],
                            'type' => 'activity', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => [],
                        ],
                        [
                            'clause' => '7.4.2',
                            'title' => 'Intern kommunikasjon',
                            'description' => 'Virksomheten skal kommunisere relevant informasjon om miljøstyringssystemet internt på tvers av nivåer og funksjoner, også om endringer i systemet. Kommunikasjonen skal gjøre det mulig for de som arbeider under virksomhetens styring å bidra til forbedring.',
                            'guidance' => 'Typiske kanaler er intranett, avdelingsmøter og tavlemøter. Vis at ansatte har en enkel måte å melde inn forbedringsforslag og miljøhendelser på.',
                            'documentation' => ['Intranettsider om miljø', 'Referater fra møter der miljø er tema'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['wiki'],
                        ],
                        [
                            'clause' => '7.4.3',
                            'title' => 'Ekstern kommunikasjon',
                            'description' => 'Virksomheten skal kommunisere relevant informasjon om miljøstyringssystemet eksternt slik kommunikasjonsprosessen fastsetter, og slik forpliktelsene krever.',
                            'guidance' => 'Eksempler er rapportering til myndigheter etter tillatelser, svar til kunder og oppdragsgivere om miljøkrav, og informasjon til naboer. Revisor ser etter at pålagt rapportering er levert i tide.',
                            'documentation' => ['Rapporter til myndigheter', 'Miljørapport eller bærekraftsrapport', 'Svar på kunders miljøspørsmål'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => [],
                        ],
                    ],
                ],
                [
                    'clause' => '7.5',
                    'title' => 'Dokumentert informasjon',
                    'children' => [
                        [
                            'clause' => '7.5.1',
                            'title' => 'Generelt om dokumentert informasjon',
                            'description' => 'Miljøstyringssystemet skal omfatte den dokumenterte informasjonen standarden krever, og den virksomheten selv mener er nødvendig for at systemet skal være effektivt.',
                            'guidance' => 'Omfanget avhenger av størrelse, aktiviteter, kompleksitet, forpliktelser og kompetanse. En oversikt over hvilke dokumenter og registreringer systemet består av gjør det enkelt å vise sammenhengen.',
                            'documentation' => ['Dokumentoversikt for miljøstyringssystemet'],
                            'type' => 'documented_information', 'info' => null, 'rule' => 'mandatory', 'modules' => ['wiki', 'quality'],
                        ],
                        [
                            'clause' => '7.5.2',
                            'title' => 'Utarbeide og oppdatere dokumentert informasjon',
                            'description' => 'Når dokumentert informasjon utarbeides eller oppdateres, skal virksomheten sørge for passende identifikasjon og beskrivelse (for eksempel tittel, dato, forfatter eller referansenummer), passende format og medium, og gjennomgang og godkjenning av egnethet og tilstrekkelighet.',
                            'guidance' => 'Revisor ser etter at dokumentene har eier, versjon og godkjenning. Et dokumentsystem med innebygd godkjenningsflyt dekker kravet enkelt.',
                            'documentation' => ['Dokumenter med versjon, eier og godkjenning'],
                            'type' => 'documented_information', 'info' => null, 'rule' => 'mandatory', 'modules' => ['wiki'],
                        ],
                        [
                            'clause' => '7.5.3',
                            'title' => 'Styring av dokumentert informasjon',
                            'description' => 'Dokumentert informasjon skal styres slik at den er tilgjengelig og egnet når og der den trengs, og at den er tilstrekkelig beskyttet. Virksomheten skal styre distribusjon, tilgang, lagring, versjonskontroll, oppbevaringstid og avhending. Dokumenter fra eksterne kilder som trengs for systemet skal identifiseres og styres.',
                            'guidance' => 'Eksterne dokumenter er typisk tillatelser, myndighetsvedtak og sikkerhetsdatablader. Revisor ser etter at ansatte bruker gjeldende versjon og at det er fastsatt hvor lenge registreringer oppbevares.',
                            'documentation' => ['Rutine for dokumentstyring', 'Oversikt over eksterne dokumenter', 'Oppbevaringsplan for registreringer'],
                            'type' => 'documented_information', 'info' => null, 'rule' => 'mandatory', 'modules' => ['wiki'],
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
                    'description' => 'Virksomheten skal planlegge, gjennomføre og styre de prosessene som trengs for å oppfylle kravene i miljøstyringssystemet og gjennomføre tiltakene fra 6.1 og 6.2, blant annet med driftskriterier. Eksternt leverte prosesser, produkter og tjenester skal styres eller påvirkes. Med et livsløpsperspektiv skal miljøkrav tas inn i innkjøp og formidles til eksterne leverandører, og miljøhensyn skal vurderes i utvikling av produkter og tjenester. Dokumentert informasjon skal vedlikeholdes i nødvendig omfang for å gi tillit til at prosessene gjennomføres som planlagt.',
                    'guidance' => 'Typisk styring er rutiner for avfallssortering, kjemikaliehåndtering, vedlikehold av renseanlegg og miljøkrav i innkjøp og kontrakter. Revisor ser etter at driftskriteriene er konkrete, at de vesentlige aspektene faktisk er dekket av rutiner, og at leverandører og underleverandører med miljøpåvirkning følges opp.',
                    'documentation' => ['Driftsrutiner med miljøkriterier', 'Miljøkrav i innkjøp og kontrakter', 'Oppfølging av leverandører'],
                    'type' => 'activity', 'info' => 'maintain', 'rule' => 'mandatory', 'modules' => ['quality', 'supplier'],
                ],
                [
                    'clause' => '8.2',
                    'title' => 'Beredskap og respons på nødsituasjoner',
                    'description' => 'Virksomheten skal være forberedt på og kunne håndtere de nødsituasjonene som er identifisert under 6.1.2. Den skal planlegge tiltak for å forebygge eller begrense negative miljøpåvirkninger, håndtere faktiske nødsituasjoner, øve på beredskapen der det er praktisk mulig, og gjennomgå og oppdatere planene jevnlig, særlig etter hendelser og øvelser. Dokumentert informasjon skal vedlikeholdes i nødvendig omfang.',
                    'guidance' => 'Typiske scenarier er kjemikaliesøl, brann, lekkasje og utslipp til vann eller grunn. Revisor ser etter beredskapsplaner for de aktuelle scenariene, tilgjengelig utstyr, gjennomførte øvelser og at erfaringer fra hendelser følges opp.',
                    'documentation' => ['Beredskapsplan for miljøhendelser', 'Øvelsesrapporter', 'Hendelsesrapporter med oppfølging'],
                    'type' => 'activity', 'info' => 'maintain', 'rule' => 'mandatory', 'modules' => ['risk', 'improvements'],
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
                            'title' => 'Generelt om overvåking og måling',
                            'description' => 'Virksomheten skal fastsette hva som skal overvåkes og måles, hvilke metoder som brukes, kriterier og indikatorer for å evaluere miljøprestasjonen, og når resultatene skal analyseres og evalueres. Måleutstyr skal være kalibrert eller verifisert der det trengs. Virksomheten skal evaluere miljøprestasjonen og hvor effektivt systemet er, og oppbevare dokumentert informasjon om resultatene.',
                            'guidance' => 'Typiske målinger er energiforbruk, avfallsmengder og sorteringsgrad, utslippsmålinger og antall miljøhendelser. Revisor ser etter at målingene dekker de vesentlige aspektene og forpliktelsene, og at resultatene brukes til beslutninger.',
                            'documentation' => ['Måleplan for miljøindikatorer', 'Måleresultater og analyser', 'Kalibreringsbevis for måleutstyr'],
                            'type' => 'activity', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => ['objectives'],
                        ],
                        [
                            'clause' => '9.1.2',
                            'title' => 'Evaluering av etterlevelse',
                            'description' => 'Virksomheten skal planlegge og gjennomføre evaluering av om forpliktelsene oppfylles, fastsette hvor ofte det skjer, og gjennomføre tiltak ved behov. Virksomheten skal kjenne sin egen etterlevelsesstatus, og dokumentert informasjon om resultatene skal oppbevares.',
                            'guidance' => 'Gå gjennom lovregisteret jevnlig og vurder hvert krav, for eksempel etter forurensningsloven, avfallsregler og vilkår i utslippstillatelser, med bevis for samsvar. Revisor ser etter at evalueringen er gjort, at avvik er fulgt opp, og at virksomheten kan forklare sin status.',
                            'documentation' => ['Etterlevelsesevaluering per forpliktelse', 'Bevis for samsvar (målinger, rapporter, tilsynsrapporter)', 'Tiltak ved manglende etterlevelse'],
                            'type' => 'activity', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => ['improvements'],
                        ],
                    ],
                ],
                [
                    'clause' => '9.2',
                    'title' => 'Internrevisjon',
                    'children' => [
                        [
                            'clause' => '9.2.1',
                            'title' => 'Generelt om internrevisjon',
                            'description' => 'Virksomheten skal gjennomføre internrevisjoner med planlagte mellomrom for å få vite om miljøstyringssystemet oppfyller egne krav og kravene i standarden, og om det er effektivt implementert og vedlikeholdt.',
                            'guidance' => 'Revisjonene bør dekke hele systemet over en periode, med vekt på de vesentlige aspektene og forpliktelsene. Revisor ser etter at internrevisjonene finner reelle forbedringspunkter, ikke bare bekrefter at alt er i orden.',
                            'documentation' => ['Revisjonsrapporter'],
                            'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['compliance_audits'],
                        ],
                        [
                            'clause' => '9.2.2',
                            'title' => 'Internrevisjonsprogram',
                            'description' => 'Virksomheten skal etablere og gjennomføre et internrevisjonsprogram med hyppighet, metoder, ansvar, planlegging og rapportering, som tar hensyn til miljømessig betydning, endringer og tidligere revisjonsresultater. For hver revisjon skal mål, kriterier og omfang fastsettes, revisorene skal være objektive og upartiske, og resultatene skal rapporteres til relevant ledelse. Dokumentert informasjon skal oppbevares som bevis på programmet og resultatene.',
                            'guidance' => 'En årsplan som viser hvilke områder som revideres når, og hvorfor, er vanlig. Revisor sjekker at internrevisorene ikke reviderer eget arbeid, og at funn følges opp.',
                            'documentation' => ['Revisjonsprogram', 'Revisjonsplaner med mål, kriterier og omfang', 'Revisjonsrapporter med funn og oppfølging'],
                            'type' => 'activity', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => ['compliance_audits'],
                        ],
                    ],
                ],
                [
                    'clause' => '9.3',
                    'title' => 'Ledelsens gjennomgang',
                    'children' => [
                        [
                            'clause' => '9.3.1',
                            'title' => 'Generelt om ledelsens gjennomgang',
                            'description' => 'Toppledelsen skal gjennomgå miljøstyringssystemet med planlagte mellomrom for å sikre at det fortsatt er egnet, tilstrekkelig og effektivt.',
                            'guidance' => 'Gjennomføres ofte én eller to ganger i året, gjerne sammen med andre styringssystemer. Revisor ser etter at toppledelsen faktisk deltar.',
                            'documentation' => ['Møteplan for ledelsens gjennomgang'],
                            'type' => 'leadership', 'info' => null, 'rule' => 'mandatory', 'modules' => ['management_review'],
                        ],
                        [
                            'clause' => '9.3.2',
                            'title' => 'Grunnlag for ledelsens gjennomgang',
                            'description' => 'Gjennomgangen skal ta for seg status for tiltak fra tidligere gjennomganger og endringer i forhold som er relevante for systemet, blant annet kontekst, interessenters behov og forventninger, forpliktelser, vesentlige miljøaspekter og risikoer og muligheter. Den skal også ta for seg måloppnåelse, miljøprestasjon (avvik og korrigerende tiltak, måleresultater, etterlevelse og revisjonsresultater), tilstrekkeligheten av ressurser, relevant kommunikasjon fra interessenter og muligheter for forbedring.',
                            'guidance' => 'En fast agenda som følger punktene gjør det enkelt å vise at alt er dekket. Revisor sjekker at grunnlaget faktisk er behandlet, ikke bare listet opp.',
                            'documentation' => ['Agenda og grunnlagsdokumenter for ledelsens gjennomgang'],
                            'type' => 'leadership', 'info' => null, 'rule' => 'mandatory', 'modules' => ['management_review'],
                        ],
                        [
                            'clause' => '9.3.3',
                            'title' => 'Resultater av ledelsens gjennomgang',
                            'description' => 'Resultatet av gjennomgangen skal omfatte konklusjoner om systemets egnethet, tilstrekkelighet og effektivitet, beslutninger om forbedring, behov for endringer i systemet og ressurser, tiltak der miljømål ikke er nådd, og eventuelle konsekvenser for strategien. Dokumentert informasjon skal oppbevares som bevis på resultatene.',
                            'guidance' => 'Referatet bør inneholde konkrete beslutninger med ansvarlig og frist. Revisor ser etter at beslutningene følges opp til neste gjennomgang.',
                            'documentation' => ['Referat fra ledelsens gjennomgang med beslutninger', 'Tiltaksliste med ansvar og frist'],
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
                    'title' => 'Kontinuerlig forbedring',
                    'description' => 'Virksomheten skal finne muligheter for forbedring og gjennomføre nødvendige tiltak for å oppnå tiltenkte resultater. Den skal kontinuerlig forbedre miljøstyringssystemets egnethet, tilstrekkelighet og effektivitet for å forbedre miljøprestasjonen.',
                    'guidance' => 'Kildene til forbedring er blant annet måleresultater, revisjoner, avvik, etterlevelsesevaluering og ledelsens gjennomgang. Revisor ser etter at miljøprestasjonen faktisk utvikler seg over tid, ikke bare at systemet finnes.',
                    'documentation' => ['Forbedringslogg', 'Utvikling i miljøindikatorer over tid'],
                    'type' => 'activity', 'info' => null, 'rule' => 'mandatory', 'modules' => ['improvements', 'objectives'],
                ],
                [
                    'clause' => '10.2',
                    'title' => 'Avvik og korrigerende tiltak',
                    'description' => 'Når et avvik oppstår, skal virksomheten reagere på det, styre og rette det, og håndtere konsekvensene, herunder å begrense negative miljøpåvirkninger. Virksomheten skal finne årsaken og vurdere om lignende avvik finnes eller kan oppstå, gjennomføre nødvendige tiltak, evaluere om tiltakene virket og endre miljøstyringssystemet ved behov. Dokumentert informasjon om avvikene, tiltakene og resultatene skal oppbevares.',
                    'guidance' => 'Avvik kan være brudd på forpliktelser, miljøhendelser, funn fra revisjoner eller klager fra naboer. Revisor ser etter årsaksanalyse, at tiltakene retter seg mot årsaken og at effekten er verifisert.',
                    'documentation' => ['Avviksregister', 'Årsaksanalyser', 'Verifisering av tiltakenes effekt'],
                    'type' => 'activity', 'info' => 'retain', 'rule' => 'mandatory', 'modules' => ['improvements'],
                ],
            ],
        ],
    ],
];
