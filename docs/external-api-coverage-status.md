# Procynia API – status og plan for full API-dekning

**Sist oppdatert:** 2026-10-24
**Grunnlag:** koden på grenen `feature/external-api-foundation` (commit `3db93474`, fase 2), `routes/api.php`, `routes/web.php` (kundefrontenden), domenetjenestene i `app/Services/`, registeret i `tests/Feature/Security/HumanDecisionContractTest.php` og arkitekturdokumentene [`external-api-architecture.md`](external-api-architecture.md), [`external-api-phase-0-plan.md`](external-api-phase-0-plan.md) og [`external-api-phase-1-decisions.md`](external-api-phase-1-decisions.md).
**Faseplan:** vedtatt 2026-10-24 (del 1 «Vedtatt faseplan» og del 4).
**Formål:** én samlet oversikt over hva som er ferdig, hva som mangler, rekkefølgen videre og beslutningene som gjenstår. Dokumentet er et styringsdokument. De tekniske detaljene og begrunnelsene står i arkitekturdokumentene.

> **Full API-dekning betyr ikke at AI-agenter skal kunne overta menneskelige beslutninger.** Godkjenninger, publiseringer, faglige vurderinger, aksept av risiko og sletting er forbeholdt mennesker (B1, B2). De er ikke en del av API-et og telles ikke som manglende dekning.

---

## Del 1 – Ledelsesdashboard

### Samlet status

| | |
|---|---|
| **Prosjektstatus** | 🟧 **Delvis dekket.** API-fundamentet er bygget og testet lokalt. Tre av ni moduler har API-støtte, og lesingen i dem er levert i fase 2 med ett unntak (RIS-02, Kvalitet-koblingene). **Ingenting er i produksjon.** |
| **API-operasjoner i dag** | **14 operasjoner:** 13 for lesing og 1 for skriving (se tabellen under). |
| **Lokalt verifisert** | Ja. Alle 155 API-tester består (kjørt 2026-10-24, etter fase 2). Siste fulle kjøring av PHP-suiten ga 8667 tester og 0 feil (2026-10-24, før OpenAPI og fase 2). |
| **Produksjonsklart** | **Nei.** Grenen er ikke flettet inn i `main`, migreringene er ikke kjørt i Azure, og API-et er ikke prøvd med et ekte token fra Microsoft Entra ID eller med Siggjo. |
| **API-spesifikasjon** | 🟩 OpenAPI 3.1 for alle 14 operasjoner i [`api/openapi.yaml`](api/openapi.yaml), validert og låst mot rutene med en kontrakttest. Ekte svar fra alle operasjonene er kontrollert mot skjemaene (2026-10-24). |
| **Neste milepæl** | **Pilotklar API på testkunde:** dagens seks operasjoner flettet inn, beskrevet i en API-spesifikasjon og prøvd med ekte Entra-token i staging (fase 1 under). |

### Implementerte API-operasjoner

| # | Operasjon | Type | Modul | Lokalt verifisert | Produksjonsklar |
|---|---|---|---|---|---|
| 1 | Hvem er jeg og hva har jeg tilgang til (`GET /api/v1/me`) | Lesing | Felles | Ja | Nei |
| 2 | Risikoregister (`GET /api/v1/risks`) | Lesing | Risiko | Ja | Nei |
| 3 | Avviksregister (`GET /api/v1/deviations`) | Lesing | Avvik og forbedringer | Ja | Nei |
| 4 | Målregister (`GET /api/v1/objectives`) | Lesing | Mål og KPI | Ja | Nei |
| 5 | KPI-er med siste 24 målinger (`GET /api/v1/kpis`) | Lesing | Mål og KPI | Ja | Nei |
| 6 | Opprett risikotiltak (`POST /api/v1/risks/{id}/treatment-actions`) | Skriving | Risiko | Ja | Nei |
| 7 | Én risiko med vurderinger, tiltak og aksept (`GET /api/v1/risks/{id}`) | Lesing | Risiko | Ja | Nei |
| 8 | Ett avvik med tiltak og effektverifisering (`GET /api/v1/deviations/{id}`) | Lesing | Avvik og forbedringer | Ja | Nei |
| 9 | Forbedringsforslag (`GET /api/v1/improvements`) | Lesing | Avvik og forbedringer | Ja | Nei |
| 10 | Én forbedring med tiltak og effektverifisering (`GET /api/v1/improvements/{id}`) | Lesing | Avvik og forbedringer | Ja | Nei |
| 11 | Ett mål med KPI-er (`GET /api/v1/objectives/{id}`) | Lesing | Mål og KPI | Ja | Nei |
| 12 | Full målehistorikk for én KPI (`GET /api/v1/kpis/{id}/measurements`) | Lesing | Mål og KPI | Ja | Nei |
| 13 | Samlet oppmerksomhet (`GET /api/v1/attention`) | Lesing | Felles | Ja | Nei |
| 14 | Fagområder (`GET /api/v1/business-areas`) | Lesing | Felles | Ja | Nei |

«Lokalt verifisert» betyr automatiske tester med simulerte Entra-tokens og testdata. Det betyr ikke at operasjonen er prøvd mot Microsoft, Azure eller Siggjo.

### API-fundamentet

| Del | Status | Kommentar |
|---|---|---|
| Innlogging for applikasjoner (Entra ID, applikasjonsmodus) | 🟧 Delvis dekket | Bygget og testet lokalt. Ikke prøvd med ekte token. |
| Innlogging på vegne av en person (delegert modus) | ⬜ Ikke startet | Utsatt etter beslutning D2. Trengs for «Mine oppgaver» og personlige dashboards. |
| Kundetildeling og integrasjonsidentitet | 🟩 Fullt dekket (lokalt) | System Owner gir og trekker tilgang i **Kundemiljø → Integrasjoner**. Interne administratorer registrerer applikasjoner i Filament. Alt logges. |
| Integrasjoner behandles aldri som mennesker | 🟩 Fullt dekket (lokalt) | Ingen innlogging, lisens, varsler, eierskap eller beslutningsrett. |
| Sperre for menneskelige beslutninger | 🟩 Fullt dekket (lokalt) | Sperren i domenelaget og i databasen gjelder alle ni moduler. |
| Sperre for AI-kall via API-et | 🟩 Fullt dekket (lokalt) | Ingen AI-kostnad kan utløses gjennom API-et i dag. |
| Felles feilformat, logging, ratebegrensning | 🟩 Fullt dekket (lokalt) | |
| Modell for navngitte skriveoperasjoner | 🟧 Delvis dekket | Modellen er generell. Katalogen har én operasjon. |
| Beskyttelse mot dobbelregistrering (idempotens) | ⬜ Ikke startet | Et gjentatt skrivekall gir duplikat. Akseptert for den ene operasjonen. Må avklares før flere skriveoperasjoner. |
| API-spesifikasjon (OpenAPI) | 🟩 Fullt dekket (lokalt) | [`api/openapi.yaml`](api/openapi.yaml) beskriver de seks operasjonene. Validert mot OpenAPI 3.1 med Redocly CLI. `ApiOpenApiContractTest` låser stier, metoder, app-roller, enums og feilkoder mot koden. Vedlikehold: [`api/README.md`](api/README.md). Ikke publisert til Siggjo ennå. |
| Detaljvisning av enkeltposter (`/{id}`) | 🟧 Delvis dekket | Finnes for Risiko, Avvik og forbedringer og Mål og KPI (fase 2). De andre modulene følger i fase 3. |
| Samlet oppmerksomhetsliste | 🟧 Delvis dekket | `GET /api/v1/attention` for Risiko, Avvik og forbedringer og Mål og KPI. Utvides med modulene i fase 3. |
| Drift: WAF/API Management, Log Analytics-retensjon | ⬜ Ikke startet | Beslutning D12 tas før første kunde med ekte data. |
| Personvern: DPIA og databehandleravtaler | ⬜ Ikke startet | D14. Kreves før ekte kundedata går til Siggjo. Blokkerer ikke pilot med testdata. |

### Status per modul

| Modul | Status | Lesing i API | Skriving i API | Det viktigste som mangler |
|---|---|---|---|---|
| Risiko | 🟧 Delvis dekket | Register, én risiko, oppmerksomhet | Opprett tiltak | Kvalitet-koblingene i RIS-02 (blokkert, se funn 13), registrering av risiko |
| Mål og KPI | 🟧 Delvis dekket | Mål, ett mål med KPI-er, KPI-er, full målehistorikk, oppmerksomhet | – | Kobling til prosess (MOK-06, fase 3) |
| Avvik og forbedringer | 🟧 Delvis dekket | Avvik, forbedringer, én sak med tiltak, oppmerksomhet | – | Registrering av avvik og tiltak (AVF-04, AVF-05, fase 4) |
| Kvalitet | ⬜ Ikke startet | – | – | Lesetjeneste for prosesser og kontroller |
| Etterlevelse og revisjon | ⬜ Ikke startet | – | – | Kravstatus og revisjoner |
| Leverandøroppfølging | ⬜ Ikke startet | – | – | Leverandørregister og kontrollstatus |
| Ledelsens gjennomgang | ⬜ Ikke startet | – | – | Gjennomganger og oppfølging av tiltak |
| Enterprise Wiki | ⬜ Ikke startet | – | – | Arkitekturbeslutning om lesing via den felles Wiki-kjernen |
| Anbud | 🟥 Blokkert | – | – | Fire kjente hindre må løses først (se under) |

Ingen modul er «Fullt dekket». Det betyr at alle funksjoner i kategori A (se del 3) har API-støtte, er verifisert og er produksjonsklare.

### Implementert, planlagt og blokkert

Grunnlaget er de 117 funksjonene i del 3: **32 A-funksjoner, 51 B-funksjoner og 34 C-funksjoner.** Hver funksjon har nøyaktig én av statusene under.

| Status | Betydning | A | B | C | Sum |
|---|---|---|---|---|---|
| 🟩 **Implementert** | Endepunktet finnes og er verifisert lokalt. Ikke i produksjon. | 14 | – | – | 14 |
| 🟦 **Planlagt** | Har en fase i den vedtatte planen og kan bygges uten ny beslutning. | 17 | – | – | 17 |
| 🟥 **Blokkert** | Har en fase, men kan ikke startes eller fullføres før en navngitt beslutning eller hindring er løst. | 1 | 43 | – | 44 |
| ⬛ **Ikke anbefalt** | Ikke planlagt. Tas bare opp igjen etter egen beslutning. | – | 8 | – | 8 |
| ⛔ **Utenfor API-et** | Formell menneskelig beslutning. Kan aldri utføres av en integrasjon og teller ikke som manglende. | – | – | 34 | 34 |
| **Sum** | | **32** | **51** | **34** | **117** |

**Implementert (14):** RIS-01, RIS-03, RIS-04, MOK-01–05, AVF-01–03, FEL-01–03. Fase 2 leverte RIS-03, MOK-03, MOK-04, resten av MOK-05, AVF-02, AVF-03, FEL-02 og resten av FEL-03 (2026-10-24).

**Planlagt (17 A-funksjoner):**

| Fase | Funksjoner | Antall |
|---|---|---|
| 3 | MOK-06, KVA-01, KVA-03, KVA-04, ETR-01–05, LEV-01–03, LDG-01–03 | 15 |
| 4 | AVF-04, AVF-05 (etter fellesforutsetningene idempotens, opphavsvisning og varselregel) | 2 |

**Blokkert (1 A-funksjon):** RIS-02 er delvis levert i fase 2 (`GET /api/v1/risks/{id}` med vurderinger, tiltak og aksept). Koblingene til Kvalitet (kontroller og prosesskontekst) er ikke levert, fordi Kvalitet ennå ikke kan leses gjennom API-et (funn 13).

**Blokkert (43 B-funksjoner):** alle B-funksjoner som har en fase. Hindringen står i kolonnen «Avhengigheter» i del 3, og de samlede beslutningene står i «Viktigste avhengigheter og beslutninger» under. Hele Anbud-modulen er blokkert (funn 2, 3 og 6 i del 5, og K16).

**Ikke anbefalt (8 B-funksjoner):** RIS-06, ETR-07, LEV-04, LDG-04, LDG-05, WIK-04, ANB-10, og FEL-06 (permanent utenfor API-et).

### Vedtatt faseplan

Faseplanen ble vedtatt 2026-10-24. Endringer i rekkefølge eller innhold krever ny beslutning og føres inn her.

| Fase | Mål | Moduler | Status |
|---|---|---|---|
| **1** | Gjøre dagens API produksjonsklart for pilot | Fundamentet | 🟧 Påbegynt. OpenAPI-spesifikasjonen er ferdig. Entra, staging og V4 venter på Advania og Siggjo. |
| **2** | Fullføre lesing i modulene som er påbegynt | Risiko, Mål og KPI, Avvik og forbedringer | 🟧 Levert lokalt 2026-10-24, med ett unntak: Kvalitet-koblingene i RIS-02 er blokkert (funn 13). |
| **3** | Lesing i resten av styringsmodulene | Etterlevelse og revisjon, Leverandøroppfølging, Ledelsens gjennomgang, Kvalitet | 🟦 Planlagt. KVA-02 venter på beslutning. |
| **4** | Avgrensede registreringer (utkast og forslag) | Styringsmodulene | 🟥 Blokkert av fellesforutsetningene og beslutning per operasjon |
| **5** | Tilgang på vegne av en person | Mine oppgaver, personlige dashboards | 🟥 Blokkert av D2 |
| **6** | Wiki gjennom den felles kunnskapskjernen | Enterprise Wiki | 🟥 Blokkert av Wiki-arkitekturbeslutning, A23 og S4 |
| **7** | Anbud | Anbud | 🟥 Blokkert av K16, `isBidManager()`, avdelingsomfang og A46 |
| **8** | Kopi av data og agentlag | Siggjos datamodell, MCP | 🟥 Blokkert av D7 og D14 |

### Vedtatt rekkefølge for de neste leveransene

| # | Leveranse | Fase | Kan gjøres lokalt | Status |
|---|---|---|---|---|
| 1 | Statusdokumentet med vedtatt faseplan (dette dokumentet) | – | Ja | 🟩 Ferdig |
| 2 | **OpenAPI 3.1-spesifikasjon for de seks eksisterende operasjonene**, med kontraktstest mot rutene | 1 | Ja | 🟩 Ferdig lokalt 2026-10-24 ([`api/openapi.yaml`](api/openapi.yaml)) |
| 3 | Fase 2: fullføre API-dekningen for lesing i Risiko, Avvik og forbedringer og Mål og KPI | 2 | Ja | 🟧 Levert lokalt 2026-10-24. Åtte av ni funksjoner er ferdige. RIS-02 er delvis levert (funn 13). |
| – | Merge etter V1–V3, Entra-registrering, Siggjos identitet, staging og V4 | 1 | Nei | 🟥 Venter på produkteier, Advania og Siggjo. Løper parallelt. |

Etter fase 2 er de tre modulene fullt dekket **for lesing**. Full A-dekning i disse modulene krever også MOK-06 (fase 3) og AVF-04 og AVF-05 (fase 4).

Detaljer står i del 4.

### Viktigste avhengigheter og beslutninger

| # | Avhengighet eller beslutning | Blokkerer | Hvem |
|---|---|---|---|
| 1 | API-registrering i Entra, Siggjos identitet og staging-miljø | Fase 1 (pilot) | Advania / Siggjo |
| 2 | Merge av `feature/external-api-foundation` til `main`, med de åpne verifiseringene V1–V3 | Fase 1 | Produkteier |
| 3 | DPIA og databehandleravtaler (D14) | Ekte kundedata i Siggjo | Produkteier / juridisk |
| 4 | WAF eller API Management (D12) | Første kunde med ekte data | Produkteier / drift |
| 5 | Idempotens og visning av opphav i alle moduler | Fase 4 (flere skriveoperasjoner) | Arkitektur |
| 6 | Hvilke registreringer agenter skal få gjøre, én operasjon om gangen | Fase 4 | Produkteier |
| 7 | B6 og B7: skal agenter kunne laste opp kildedokumenter eller registrere KPI-målinger? I dag er svaret nei. | Fase 4 | Produkteier |
| 8 | Om agentutløst AI-arbeid er tillatt, og hvordan kostnaden begrenses (S4) | Fase 4 og 6 | Produkteier |
| 9 | A23: Kvalitets AI-forslag og «Spør Wiki» må kontrollere tilgang i tjenesten | Fase 6 | Arkitektur |
| 10 | Anbud: delte arbeidsflytfelt på kunngjøringer (K16), avdelingsomfang og A46 | Fase 7 | Produkteier / arkitektur |

### Neste anbefalte milepæl

**Milepæl: «API klart for pilot på testkunde».** Den er nådd når:

1. Dagens seks operasjoner er flettet inn i `main` etter V1–V3 (fase 0-verifiseringene).
2. En OpenAPI-spesifikasjon beskriver de seks operasjonene, og en test sikrer at spesifikasjonen og rutene stemmer overens. **Ferdig lokalt 2026-10-24.**
3. «Procynia API» er registrert i Entra med rollene `Procynia.Read.All` og `Procynia.Write.All`. Siggjos identitet er registrert og har fått tilgang hos en testkunde.
4. Sjekklisten V4 med ekte token er gjennomført og signert i staging.

Punkt 3 og 4 avhenger av miljøer hos Advania og Siggjo. Fase 2 kan bygges og verifiseres lokalt i mellomtiden.

---

## Del 2 – Moduloversikt

Kompleksitet: **Lav** = gjenbruk av en eksisterende tjeneste og presentasjon. **Middels** = presentasjonen må trekkes ut av webkontrolleren, eller en mindre beslutning trengs. **Høy** = ny tjeneste, ny arkitekturbeslutning eller betydelig risiko.

| Modul | Viktigste funksjoner gjennom API | Implementert lesing | Implementert skriving | Mangler | Viktige sikkerhetsbegrensninger | Kompleksitet | Fase |
|---|---|---|---|---|---|---|---|
| **Risiko** | Risikoregister, én risiko med vurderinger, tiltak og kontroller, oppmerksomhet, registrering av tiltak | Register og én risiko med vurderingshistorikk, tiltak, aksept av restrisiko og oppmerksomhetskoder | Opprett tiltak (navngitt operasjon) | Koblinger til Kvalitet (blokkert, funn 13). Mulig registrering av ny risiko. | Vurdering, aksept av restrisiko og sletting er menneskelige beslutninger. Fagområdene styrer synligheten. | Lav–middels | 2, 4 |
| **Mål og KPI** | Mål, KPI-er, måloppnåelse, målehistorikk, oppmerksomhet | Mål, ett mål med KPI-er, KPI-er med siste 24 målinger, full målehistorikk, oppmerksomhetskoder | – | Koblinger til prosess (fase 3) | Målinger, avslutning og sletting er menneskelige beslutninger (B7). Fagområdene styrer synligheten. | Lav | 2 |
| **Avvik og forbedringer** | Avvik, forbedringsforslag, tiltak, effektverifisering, registrering av avvik | Avvik og forbedringer hver for seg, én sak med tiltak og effektverifisering, oppmerksomhetskoder | – | Registrering av avvik og tiltak (fase 4) | Lukking, effektverifisering og sletting er menneskelige beslutninger. Avvikstitler kan inneholde særlige kategorier av personopplysninger. | Lav–middels | 2, 4 |
| **Kvalitet** | Prosesser, rutiner, kontroller, evidens, godkjent prosessflyt, oppmerksomhet | – | – | Alt. Det finnes ingen lesetjeneste: lesingen ligger i `QualityController`. | Godkjenning, innføring av AI-forslag og sletting er menneskelige beslutninger. AI-forslagene er sperret (A23, D11). Prosessflyt krever versjonskontroll (`base_hash`) ved skriving. | Middels–høy | 3 |
| **Etterlevelse og revisjon** | Kravregister, etterlevelsesstatus per standard, revisjoner og funn, oppmerksomhet | – | – | Alt | Vurdering, relevans og **hele revisjonsløpet** (også opprettelse) er menneskelige beslutninger. Modulen krever eksplisitt tildeling. | Middels | 3 |
| **Leverandøroppfølging** | Leverandørregister, kritikalitet, kontrollstatus, oppfølgingsplan | – | – | Alt | Registrering, kritikalitet, livsløp, vurderinger og kontrollarbeid er menneskelige beslutninger. Krever eksplisitt tildeling. Dokumentfiler skannes ikke i dag. | Middels | 3 |
| **Ledelsens gjennomgang** | Gjennomganger, seksjoner, beslutninger og tiltak med status | – | – | Alt | Ferdigstilling og sletting er menneskelige beslutninger. Seksjoner fra andre moduler vises bare med tilgang til modulen. | Lav–middels (presentasjonsklassen finnes) | 3 |
| **Enterprise Wiki** | Lese publisert kunnskap, navigere, stille spørsmål, overføre kunnskap fra andre moduler | – | – | Alt | All redigering, godkjenning, kildeadministrasjon og alle kjøringer er menneskelige beslutninger. Lesing må gå gjennom den felles kunnskapskjernen og bare vise gjeldende innhold. «Spør Wiki» er sperret (A23). | Høy | 6 |
| **Anbud** | Saker og cockpit, krav og svarstatus, frister, Go/No-go-grunnlag | – | – | Alt | Budstatus og godkjenning av krav er menneskelige beslutninger. Avdelingsbasert synlighet. De fleste skrivinger ligger i kontrollerne, ikke i tjenester. | Høy | 7 |

---

## Del 3 – Teknisk dekningsmatrise

### Kategorier

- **A – Ordinær operasjon.** Kan gjøres tilgjengelig for integrasjoner med dagens arkitektur: lesing via `*.view` og tilgangstjenesten, eller en smal, navngitt operasjon i `ApiOperationCatalog` gjennom en domenetjeneste som selv kontrollerer tilgang.
- **B – Krever beslutning.** En ny produkt- eller arkitekturbeslutning må tas før funksjonen kan åpnes. Det gjelder for eksempel endring av registrert innhold, filer, AI-kostnad, personvern, delegert modus og Wiki- eller Anbudsarkitektur.
- **C – Formell menneskelig beslutning.** Tjenesten kaller `HumanDecisionGuard`, og databasetriggerne nekter integrasjonsaktører. Funksjonen kan **aldri** utføres av dagens integrasjoner, verken i applikasjonsmodus eller i delegert modus (B1, B2). Den teller ikke som manglende dekning.

### Kolonner

- **Integrasjon i dag:**
  - **Ja** = tillatt via leserettighet eller navngitt operasjon.
  - **Nei** = ikke tillatt i dag.
  - **Aldri** = kategori C.
- **API-status:**
  - **Implementert** = endepunktet finnes og er verifisert lokalt med automatiske tester.
  - **Delvis** = deler av dataene leveres.
  - **–** = ikke implementert.
- **Fase** viser til del 4:
  - **Ferdig** = implementert.
  - **Tall** = fasen funksjonen er planlagt i. For B-funksjoner er den blokkert til beslutningen i «Avhengigheter» er tatt.
  - **Ikke anbefalt** eller **Ikke aktuelt** = ikke planlagt.
  - **–** = kategori C, utenfor API-et.

### Opptelling (regel)

Én rad i matrisen er én brukerfunksjon. Nært beslektede ruter er slått sammen (for eksempel «fullfør» og «gjenåpne»).

| Modul | A | – av dem implementert | B | C |
|---|---|---|---|---|
| Risiko | 4 | 3 (+1 delvis) | 5 | 3 |
| Mål og KPI | 6 | 5 | 4 | 3 |
| Avvik og forbedringer | 5 | 3 | 4 | 3 |
| Kvalitet | 3 | 0 | 7 | 4 |
| Etterlevelse og revisjon | 5 | 0 | 4 | 4 |
| Leverandøroppfølging | 3 | 0 | 5 | 5 |
| Ledelsens gjennomgang | 3 | 0 | 4 | 2 |
| Enterprise Wiki | 0 | 0 | 5 | 8 |
| Anbud | 0 | 0 | 10 | 2 |
| Felles | 3 | 3 | 3 | 0 |
| **Sum** | **32** | **14 (+1 delvis)** | **51** | **34** |

Kartleggingen dekker 311 webruter i de ni modulene (68 lesende, 243 skrivende) og 35 felles ruter (blant annet Kundemiljø, brukere, varsler, fakturering og Hjem). Ruter som bare viser et skjema, er ikke egne funksjoner.

### Risiko

Tilgang: `RiskAccessService` (`visibleRisks`, `findVisible`, `can*`) med fagområder.

| ID | Funksjon | Domenetjeneste | API-endepunkt | API-status | Integrasjon i dag | Kat. | Avhengigheter | Fase |
|---|---|---|---|---|---|---|---|---|
| RIS-01 | Les risikoregister | `RiskAccessService::visibleRisks`, `RiskAssessment::latestForRisks`, `RiskScoringPolicy`, `RiskReviewSchedule` | `GET /api/v1/risks` | Implementert | Ja | A | – | Ferdig |
| RIS-02 | Les én risiko: vurderingshistorikk, tiltak, aksept, kontroller, prosesskontekst | `findVisible`, `RiskTreatmentService::actionsFor`, `RiskAcceptanceService::decisionFor`, `RiskControlService::linkedControls`, `RiskQualityContextService::linkedContext` | `GET /api/v1/risks/{riskId}` | Delvis | Ja (lesing) | A | Vurderinger, tiltak og aksept er levert, uten fritekst og uten hvem som vurderte eller aksepterte (D8). **Kontroller og prosesskontekst er ikke levert:** Kvalitet kan ikke leses gjennom API-et ennå (funn 13). | 2 (Kvalitet-koblingene blokkert) |
| RIS-03 | Risikoens oppmerksomhet | `RiskAttentionService::findingsForRisks` | `attention` på risikoradene, `GET /api/v1/attention` | Implementert | Ja (lesing) | A | – | Ferdig |
| RIS-04 | Opprett risikotiltak | `RiskTreatmentService::create` | `POST /api/v1/risks/{riskId}/treatment-actions` | Implementert | Ja (operasjonen `risk.treatment_action.create`) | A | Ingen idempotens. Ekte Entra-rolle `Procynia.Write.All` gjenstår. | Ferdig |
| RIS-05 | Registrer ny risiko | `RiskCreator::create` | – | – | Nei | B | En registrert risiko er ikke et utkast (S1). Opphav og varsler (S2, S6). | 4 |
| RIS-06 | Endre risiko | `RiskCreator::update` | – | – | Nei | B | S1: en agent bør ikke skrive om registrert innhold | Ikke anbefalt |
| RIS-07 | Endre, fullfør eller gjenåpne tiltak | `RiskTreatmentService::update`, `complete`, `reopen` | – | – | Nei | B | S1. Fullføring kan være en faglig vurdering. | 4 |
| RIS-08 | Koble kontroll eller prosess/aktivitet | `RiskControlService::link`/`unlink`, `RiskQualityContextService::link`/`unlink*` | – | – | Nei | B | Kvalitet må være lesbar først (fase 3) | 4 |
| RIS-09 | Kunnskapsartikkel til Wiki | `WikiKnowledgeHandoffService::handOff` | – | – | Nei | B | S4 (AI-kostnad i Wiki-kjøringen). Se WIK-05. | 6 |
| RIS-10 | Vurder risiko | `RiskAssessmentService::record` | – | – | Aldri | C | `RISK_ASSESS` | – |
| RIS-11 | Aksepter eller trekk tilbake restrisiko | `RiskAcceptanceService::accept`/`revoke` | – | – | Aldri | C | `RISK_ACCEPT` | – |
| RIS-12 | Slett risiko | `RiskLifecycleService::delete` | – | – | Aldri | C | `RISK_DELETE`, B3 | – |

### Mål og KPI

Tilgang: `ObjectiveAccessService` (`visibleObjectives`, `visibleKpis`, `findVisible`, `findVisibleKpi`) med fagområder. En KPI arver målets fagområde.

| ID | Funksjon | Domenetjeneste | API-endepunkt | API-status | Integrasjon i dag | Kat. | Avhengigheter | Fase |
|---|---|---|---|---|---|---|---|---|
| MOK-01 | Les målregister | `visibleObjectives`, `KpiPresenter::indicator` | `GET /api/v1/objectives` | Implementert | Ja | A | – | Ferdig |
| MOK-02 | Les KPI-er med målverdi, resultat og siste 24 målinger | `visibleKpis`, `KpiMeasurementResolver`, `KpiTargetPolicy` | `GET /api/v1/kpis` | Implementert | Ja | A | – | Ferdig |
| MOK-03 | Les ett mål i detalj | `findVisible`, `visibleKpis` | `GET /api/v1/objectives/{objectiveId}` (med KPI-ene) | Implementert | Ja (lesing) | A | – | Ferdig |
| MOK-04 | Full målehistorikk for én KPI | `visibleKpis`, `KpiMeasurementResolver::currentByPeriod` | `GET /api/v1/kpis/{kpiId}/measurements` | Implementert | Ja (lesing) | A | Gjeldende måling per periode, uten erstattede og tilbaketrukne rader (som i KPI-listen). De to `KpiPresenter`-klassene er ikke samlet (funn 10). | Ferdig |
| MOK-05 | Oppmerksomhet for mål og KPI | `ObjectiveAttentionService::findingsForObjectives` | Kodene på radene, `GET /api/v1/attention` | Implementert | Ja | A | – | Ferdig |
| MOK-06 | KPI-ens kobling til prosess og aktivitet (lesing) | `KpiQualityContextService::linkedContext` | – | – | Ja (med `quality.view`) | A | Kvalitet (fase 3) | 3 |
| MOK-07 | Opprett eller endre mål | `ObjectiveRegistration::create`/`update` | – | – | Nei | B | S1, S2 | 4 |
| MOK-08 | Opprett eller endre KPI | `KpiRegistration::create`/`update` | – | – | Nei | B | S1, S2 | 4 |
| MOK-09 | Koble KPI til prosess | `KpiQualityContextService::syncProcess` | – | – | Nei | B | Fase 3 | 4 |
| MOK-10 | Kunnskapsartikkel til Wiki | `WikiKnowledgeHandoffService::handOff` | – | – | Nei | B | S4 | 6 |
| MOK-11 | Registrer eller trekk tilbake måling | `KpiMeasurementService::record`/`withdraw` | – | – | Aldri | C | `OBJECTIVE_MEASURE`. B7 kan tas opp igjen som egen beslutning, med egen kildemarkering. | – |
| MOK-12 | Avslutt eller gjenåpne mål og KPI | `ObjectiveLifecycleService::close`/`reopen`, `KpiLifecycleService::retire`/`reopen` | – | – | Aldri | C | `OBJECTIVE_LIFECYCLE` | – |
| MOK-13 | Slett mål eller KPI | `ObjectiveLifecycleService::delete`, `KpiLifecycleService::delete` | – | – | Aldri | C | `OBJECTIVE_DELETE` | – |

### Avvik og forbedringer

Tilgang: `ImprovementCaseAccessService` (`visibleCases`, `findVisible`) med fagområder.

| ID | Funksjon | Domenetjeneste | API-endepunkt | API-status | Integrasjon i dag | Kat. | Avhengigheter | Fase |
|---|---|---|---|---|---|---|---|---|
| AVF-01 | Les avviksregister | `visibleCases` (type avvik), `ImprovementAttentionService::findingsForCases` | `GET /api/v1/deviations` | Implementert | Ja | A | – | Ferdig |
| AVF-02 | Les forbedringsforslag | `visibleCases` (type forbedring) | `GET /api/v1/improvements` | Implementert | Ja (lesing) | A | Samme feltvalg, filtre og tilgang som avvik | Ferdig |
| AVF-03 | Les én sak med tiltak og effektverifisering | `visibleCases` (sakens type), `ImprovementActionVerificationResolver::forActions` | `GET /api/v1/deviations/{caseId}`, `GET /api/v1/improvements/{caseId}` | Implementert | Ja (lesing) | A | Uten fritekst, notater og hvem som fullførte eller verifiserte (D8) | Ferdig |
| AVF-04 | Registrer avvik eller forbedringsforslag (opprettes som «Åpen») | `ImprovementCaseCreator::create` | – | – | Nei | A | Ny navngitt operasjon. Opphavsvisning (S2) og idempotens. Arkitekturen anbefaler dette som første forslagstype (§10.1). | 4 |
| AVF-05 | Opprett tiltak på en sak | `ImprovementActionLifecycleService::create` | – | – | Nei | A | Samme mønster som risikotiltak. Ansvarlig valideres delvis i kontrolleren og må samles i tjenesten. | 4 |
| AVF-06 | Endre sak, årsaksanalyse eller starte arbeid | `ImprovementCaseCreator::update`/`updateCause`, `ImprovementCaseLifecycleService::start` | – | – | Nei | B | S1 | 4 |
| AVF-07 | Endre, start, fullfør, avbryt, gjenåpne eller slett tiltak | `ImprovementActionLifecycleService::*` | – | – | Nei | B | S1 | 4 |
| AVF-08 | Koble sak til prosess | `ImprovementCaseQualityContextService::syncProcess` | – | – | Nei | B | Fase 3 | 4 |
| AVF-09 | Kunnskapsartikkel til Wiki | `WikiKnowledgeHandoffService::handOff` | – | – | Nei | B | S4 | 6 |
| AVF-10 | Lukk, avbryt eller gjenåpne sak | `ImprovementCaseLifecycleService::close`/`cancel`/`reopen` | – | – | Aldri | C | `IMPROVEMENT_CLOSE` | – |
| AVF-11 | Effektverifiser tiltak | `ImprovementActionLifecycleService::verify` | – | – | Aldri | C | `IMPROVEMENT_CLOSE` | – |
| AVF-12 | Slett sak | `ImprovementCaseLifecycleService::delete` | – | – | Aldri | C | `IMPROVEMENT_DELETE` | – |

### Kvalitet

Tilgang: `QualityAccessService` (`may`, `authorize`) for skriving. **Det finnes ingen `visible*`/`findVisible*`.** Lesingen er kundeavgrenset direkte i `QualityController`. Kvalitet har ikke fagområder.

| ID | Funksjon | Domenetjeneste | API-endepunkt | API-status | Integrasjon i dag | Kat. | Avhengigheter | Fase |
|---|---|---|---|---|---|---|---|---|
| KVA-01 | Les kvalitetsregister: prosesser, rutiner, kontroller, dokumenter | Ingen. Inline i `QualityController::index`. | – | – | Ja (`quality.view`) | A | En lesetjeneste og presentasjon må trekkes ut av kontrolleren | 3 |
| KVA-02 | Les ett element med prosessflyt, koblinger og publiseringsstatus | Inline i `QualityController::show`, `QualityProcessBlueprintService::publicationState`/`history` | – | – | Ja (lesing) | B | Beslutning: bare godkjent versjon, eller også arbeidsversjonen? | 3 |
| KVA-03 | Les kontrollregister med evidens og verktøy | Inline, `QualityToolService::forControl` | – | – | Ja (lesing) | A | Som KVA-01. Nedlasting av filer holdes utenfor. | 3 |
| KVA-04 | Kvalitetsoppmerksomhet | `QualityAttentionService::findings` (per kunde) | – | – | Ja (lesing) | A | Samlet `GET /attention` | 3 |
| KVA-05 | Opprett eller endre element, steg, sjekkliste, kontrolldetaljer | `QualityItemService::createItem`/`updateItem`/`replace*` | – | – | Nei | B | S1. Hendelsesloggen (A20) dekker bare evidens. | 4 |
| KVA-06 | Lagre prosessflyt manuelt | `QualityProcessBlueprintService::store` (`base_hash`) | – | – | Nei | B | S5 (samtidighet) | 4 |
| KVA-07 | AI-forslag: tolk beskrivelse, avklar, foreslå endring | `QualityProcessFlowInterpreter::interpret`, `QualityProcessDescriptionClarifier::integrate`, `QualityProcessFlowChangeProposer::propose` | – | – | Nei (sperret) | B | **A23:** tjenestene tar ingen aktør og kontrollerer ikke tilgang selv. D11 sperrer AI på API-kanalen. | 6 eller senere |
| KVA-08 | Koblinger: relasjoner, styrende dokumenter, Wiki-lenker, dokumentlenker, kontroller på aktivitet, verktøy | `QualityItemService::relate`/`linkWikiPage`/`linkDocument`, `QualityActivityControlService`, `QualityToolService::linkToControl` | – | – | Nei | B | S1, B10 | 4 |
| KVA-09 | Registrer evidens på kontroll | `QualityItemService::addControlEvidence` | – | – | Nei | B | Evidens er historikk (B11). Krever beslutning om maskinregistrert evidens. | 4 |
| KVA-10 | Aktivitetsartikkel til Wiki (utkast og overlevering) | `QualityActivityArticleService::draft`/`create` | – | – | Nei | B | AI (S4). `wiki.knowledge.handoff` kontrolleres bare i kontrolleren ved utkast. | 6 |
| KVA-11 | Last opp dokument eller verktøyfil | `EnterpriseWikiDocumentUploadService::store` | – | – | Aldri | C | `WIKI_SOURCE_UPLOAD`, B6 | – |
| KVA-12 | Ta i bruk AI-forslag eller godta endringsforslag | `QualityProcessBlueprintService::store` (kilde AI) | – | – | Aldri | C | `QUALITY_FLOW_ADOPT` | – |
| KVA-13 | Godkjenn prosessflyt | `QualityProcessBlueprintService::approve` | – | – | Aldri | C | `QUALITY_FLOW_APPROVE` | – |
| KVA-14 | Slett element eller flyt | `QualityItemService::deleteItem`, `QualityProcessBlueprintService::delete` | – | – | Aldri | C | `QUALITY_DELETE` | – |

### Etterlevelse og revisjon

Tilgang: `ComplianceAccessService` (`visibleRequirements`, `visibleSources`, `visibleAudits`, `findVisible*`). Modulen krever eksplisitt tildeling. `canOpenModule` kontrollerer ikke modulpakken (`canReadFromAnotherModule` gjør det). API-et har sin egen modulsperre (`customer.module`).

| ID | Funksjon | Domenetjeneste | API-endepunkt | API-status | Integrasjon i dag | Kat. | Avhengigheter | Fase |
|---|---|---|---|---|---|---|---|---|
| ETR-01 | Les kravregister og krav | `visibleRequirements`, `findVisibleRequirement`, `ComplianceRegisterGroups` | – | – | Ja (`compliance.view`) | A | Radene er inline. Presentasjonsklasse trengs. | 3 |
| ETR-02 | Etterlevelsesstatus per kilde eller standard | `ComplianceStatusResolver::latestForRequirements`/`summarize`, `ComplianceAssessmentOverview` | – | – | Ja (lesing) | A | Aggregeringen er inline i `ComplianceAssessmentController::index` | 3 |
| ETR-03 | Les revisjoner med scope, kontrollhandlinger og funn | `visibleAudits`, `findVisibleAudit` | – | – | Ja (lesing) | A | `ComplianceAuditController::row` er inline. Fritekst holdes utenfor (D8). | 3 |
| ETR-04 | Les kravbibliotek og standarder | `ComplianceFrameworkPresenter` | – | – | Ja (lesing) | A | – | 3 |
| ETR-05 | Oppmerksomhet for krav og revisjoner | `ComplianceAttentionService::reasonsForRequirements`, `ComplianceAuditAttentionService::reasonsForAudits` | – | – | Ja (lesing) | A | Ingen `overview(User)`. Må settes sammen som i `ComplianceSection`. | 3 |
| ETR-06 | Opprett, endre, sett utgått eller gjenåpne krav og kravkilder | `ComplianceRequirementService`, `ComplianceSourceService`, `ComplianceRequirementLifecycleService::retire`/`reopen` | – | – | Nei | B | S1 | 4 |
| ETR-07 | Aktiver standard fra biblioteket | `ComplianceFrameworkActivationService::activate` | – | – | Nei | B | Påvirker hele kundens kravgrunnlag | Ikke anbefalt |
| ETR-08 | Koble krav til prosess eller kontroll | `ComplianceQualityContextService::link*`/`unlink*` | – | – | Nei | B | Fase 3 | 4 |
| ETR-09 | Kunnskapsartikkel til Wiki | `WikiKnowledgeHandoffService::handOff` | – | – | Nei | B | S4 | 6 |
| ETR-10 | Vurder etterlevelse | `ComplianceAssessmentService::assess` | – | – | Aldri | C | `COMPLIANCE_ASSESS` | – |
| ETR-11 | Relevans og kontrollvalg | `ComplianceApplicabilityService::set` | – | – | Aldri | C | `COMPLIANCE_APPLICABILITY` | – |
| ETR-12 | Hele revisjonsløpet: opprett, scope, kontrollhandlinger, funn, start, fullfør, avbryt, gjenåpne, funn til Avvik | `ComplianceAuditService`, `ComplianceAuditScopeService`, `ComplianceAuditCheckService`, `ComplianceAuditFindingService`, `ComplianceAuditLifecycleService`, `ComplianceAuditFindingHandoffService` | – | – | Aldri | C | `COMPLIANCE_AUDIT` | – |
| ETR-13 | Slett krav, kravkilde eller revisjon | `ComplianceRequirementLifecycleService::delete`, `ComplianceSourceService::delete`, `ComplianceAuditLifecycleService::delete` | – | – | Aldri | C | `COMPLIANCE_DELETE` | – |

### Leverandøroppfølging

Tilgang: `SupplierAccessService` (`visibleSuppliers`, `findVisibleSupplier`). Modulen krever eksplisitt tildeling, og `canOpenModule` kontrollerer ikke modulpakken.

| ID | Funksjon | Domenetjeneste | API-endepunkt | API-status | Integrasjon i dag | Kat. | Avhengigheter | Fase |
|---|---|---|---|---|---|---|---|---|
| LEV-01 | Les leverandørregister med kritikalitet, status og kontrollstatus | `visibleSuppliers`, `SupplierAssuranceResolver::forSuppliers`, `SupplierAttentionService::findingsForSuppliers` | – | – | Ja (`supplier.view`) | A | Radene er inline. Kontaktopplysninger og notater holdes utenfor (D8). | 3 |
| LEV-02 | Les én leverandør: krav, kontroller, aktsomhet, oppfølgingsplan, koblinger | `findVisibleSupplier`, `SupplierRequirementPayload`, `SupplierFollowUpPlan`, `SupplierDueDiligenceService::payload` | – | – | Ja (lesing) | A | Stor inline-presentasjon i `SupplierManagementController::show` | 3 |
| LEV-03 | Les katalogen over kontrollkrav | `SupplierRequirementPayload::catalogue` | – | – | Ja (lesing) | A | – | 3 |
| LEV-04 | Last ned dokumentfil | Inline: `PrivateFileStore`, `PrivateFileResponse` | – | – | Nei | B | Filene virusskannes ikke (`require_clean_scan=false`). Krever DPIA. | Ikke anbefalt nå |
| LEV-05 | Endre leverandør eller leverandørprofil | `SupplierRegistration::update`, `SupplierProfileService::save` | – | – | Nei | B | S1, A37 | 4 |
| LEV-06 | Registrer, endre eller forny dokumentasjon (med fil) | `SupplierDocumentService::*` | – | – | Nei | B | Filer og skanning | 4 eller senere |
| LEV-07 | Opprett eller koble avvik og risiko fra leverandør | `SupplierImprovementHandoffService`, `SupplierRiskService` | – | – | Nei | B | AVF-04 og RIS-05 først | 4 |
| LEV-08 | Kunnskapsartikkel til Wiki | `WikiKnowledgeHandoffService::handOff` | – | – | Nei | B | S4 | 6 |
| LEV-09 | Registrer leverandør, enkeltvis eller via Excel-import | `SupplierRegistration::register`, `SupplierImportService::execute` | – | – | Aldri | C | `SUPPLIER_CRITICALITY`, `SUPPLIER_LIFECYCLE` | – |
| LEV-10 | Endre kritikalitet. Ta i bruk, avslutt eller gjenåpne. | `SupplierCriticalityService::change`, `SupplierLifecycleService::*` | – | – | Aldri | C | `SUPPLIER_CRITICALITY`, `SUPPLIER_LIFECYCLE` | – |
| LEV-11 | Vurder leverandør og gjennomfør aktsomhetsvurdering | `SupplierAssessmentService::record`, `SupplierDueDiligenceService::record` | – | – | Aldri | C | `SUPPLIER_ASSESS`, `SUPPLIER_ASSURE` | – |
| LEV-12 | Kontrollkrav, kontroller, overstyringer, kravmaler, Wiki-veiledning | `SupplierControlRequirementService`, `SupplierRequirementEvaluationService`, `SupplierRequirementOverrideService`, `SupplierRequirementTemplateLibrary`, `SupplierRequirementWikiGuidance` | – | – | Aldri | C | `SUPPLIER_ASSURE` | – |
| LEV-13 | Slett leverandør | `SupplierLifecycleService::delete` | – | – | Aldri | C | `SUPPLIER_DELETE` | – |

### Ledelsens gjennomgang

Tilgang: `ManagementReviewAccessService` (`visibleReviews`, `findVisible`). Presentasjonen finnes som `ManagementReviewPresenter` med seksjonsbyggere.

| ID | Funksjon | Domenetjeneste | API-endepunkt | API-status | Integrasjon i dag | Kat. | Avhengigheter | Fase |
|---|---|---|---|---|---|---|---|---|
| LDG-01 | Les gjennomganger (liste) | `visibleReviews` | – | – | Ja (`management_review.view`) | A | – | 3 |
| LDG-02 | Les én gjennomgang med seksjoner | `ManagementReviewPresenter::show`, `ManagementReviewBasisService::live`/`snapshot` | – | – | Ja (lesing) | A | Seksjonene fra andre moduler vises bare med tilgang til modulen | 3 |
| LDG-03 | Les beslutninger og tiltak med status | `ManagementReviewDecisionService` | – | – | Ja (lesing) | A | `openActions()` i registeret spør direkte på kunden, utenom `visibleReviews`, og kan ikke gjenbrukes uendret | 3 |
| LDG-04 | Rapport og PDF | `ManagementReviewReportBuilder::document` | – | – | Nei | B | Fil og fritekst (D8) | Ikke anbefalt nå |
| LDG-05 | Opprett eller endre gjennomgang: seksjonsvurdering, konklusjon, deltakere, neste gjennomgang | `ManagementReviewService::*` | – | – | Nei | B | Innholdet er ledelsens egen vurdering | Ikke anbefalt |
| LDG-06 | Beslutninger og tiltak: opprett, endre, status, ny ansvarlig, følg opp i Avvik | `ManagementReviewDecisionService::*` | – | – | Nei | B | Bare statusoppdatering fra ansvarlig kan være aktuelt, og bare i delegert modus | 5 |
| LDG-07 | Kunnskapsartikkel til Wiki | `WikiKnowledgeHandoffService::handOff` | – | – | Nei | B | S4 | 6 |
| LDG-08 | Ferdigstill og legg til tillegg | `ManagementReviewFinalizationService::finalize`, `ManagementReviewService::addAmendment` | – | – | Aldri | C | `MANAGEMENT_REVIEW_FINALIZE` | – |
| LDG-09 | Slett gjennomgang | `ManagementReviewService::delete` | – | – | Aldri | C | `MANAGEMENT_REVIEW_DELETE` | – |

### Enterprise Wiki

Wiki eksponeres bare gjennom den felles navigasjons- og retrieval-kjernen, med gjeldende innhold og etter Wiki-sjekklisten (`enterprise-wiki-architecture.md` §J, `AGENTS.md`). Visningen av en side (`WikiController::show`) er inline og **skriver ved lesing** (`syncForPageVersion`). Den kan derfor ikke gjenbrukes direkte i et lese-API.

| ID | Funksjon | Domenetjeneste | API-endepunkt | API-status | Integrasjon i dag | Kat. | Avhengigheter | Fase |
|---|---|---|---|---|---|---|---|---|
| WIK-01 | Les publiserte Wiki-sider | Ingen lesetjeneste. `RequirementWikiPageReader::read` brukes av AI-sporet. | – | – | Nei | B | Arkitekturbeslutning om API-lesing via den felles kjernen. `visibleEnterpriseWikiPageStatuses()` gir tom liste for integrasjoner. | 6 |
| WIK-02 | Navigasjon, relasjoner og graf | `EnterpriseWikiPageTraversalService`, `EnterpriseWikiGraphDataService` | – | – | Nei | B | Som WIK-01 | 6 |
| WIK-03 | Spør Wiki | `EnterpriseWikiQuestionAnswerService::ask` | – | – | Nei (sperret) | B | A23 og D11. AI-kostnad (S4). | 6 |
| WIK-04 | Kildedokumenter og kjøringer (lesing) | Inline, `EnterpriseWikiRunFindingsService` | – | – | Nei | B | Administrativ informasjon. Behov ikke dokumentert. | Ikke anbefalt nå |
| WIK-05 | Kunnskapsoverføring fra andre moduler (blir et kildedokument som går gjennom vanlig kjøring og menneskelig godkjenning) | `WikiKnowledgeHandoffService::handOff` (egen rettighet `wiki.knowledge.handoff`, ingen menneskesperre) | – | – | Nei | B | Den eneste lovlige veien inn i Wiki for agenter (arkitektur §10.1). S4: utløser en full Wiki-kjøring med AI. | 6 |
| WIK-06 | Last opp kilde, start eller prøv kjøring på nytt, endre dokumenteier | `EnterpriseWikiDocumentUploadService::store`, `EnterpriseWikiDocumentFlowService::startForDocumentAs`/`changeDocumentOwner`, `*FailureRecoveryService::retryAs` | – | – | Aldri | C | `WIKI_SOURCE_UPLOAD`, `WIKI_RUN_START`, `WIKI_SOURCE_OWNER_CHANGE`, B6 | – |
| WIK-07 | Avbryt eller fjern kjøring, slett kildedokument | `EnterpriseWikiDocumentFlowService::cancelRun`, `EnterpriseWikiRunDismissalService::dismiss`, `EnterpriseWikiDocumentDeletionService::delete` | – | – | Aldri | C | `WIKI_RUN_CANCEL`, `WIKI_RUN_DISMISS`, `WIKI_SOURCE_DELETE` | – |
| WIK-08 | Rediger arbeidsversjon | `EnterpriseWikiClaimContentRepairService::applyWorkingVersionBlockEdits` | – | – | Aldri | C | `WIKI_PAGE_EDIT` | – |
| WIK-09 | Send til godkjenning, trekk tilbake, tildel QA | `EnterpriseWikiPageReviewService::submit`/`withdraw`/`assignQa` | – | – | Aldri | C | `WIKI_PAGE_SUBMIT`, `WIKI_PAGE_WITHDRAW`, `WIKI_QA_ASSIGN` | – |
| WIK-10 | Godkjenn eller returner side | `EnterpriseWikiPageReviewService::approve`/`returnToOwner` | – | – | Aldri | C | `WIKI_PAGE_APPROVE`, `WIKI_PAGE_RETURN` | – |
| WIK-11 | Påstandsbeslutninger og reparasjon | `EnterpriseWikiClaimDecisionService::*`, `EnterpriseWikiClaimContentRepairService::applyManualMixedBlockEdit` | – | – | Aldri | C | `WIKI_CLAIM_DECIDE`, `WIKI_CLAIM_REPAIR` | – |
| WIK-12 | Dokumenteiers godkjenning eller avvisning | `EnterpriseWikiDocumentOwnerApprovalService::decide` | – | – | Aldri | C | `WIKI_DOCUMENT_OWNER_DECIDE` | – |
| WIK-13 | Slett side | `EnterpriseWikiPageDeletionService::deletePage` | – | – | Aldri | C | `WIKI_PAGE_DELETE` | – |

### Anbud

Tilgang: `SavedNoticeAccessService` (`visibleQueryFor`, `manageableQueryFor`). Den gir **ingen** treff for en integrasjon, fordi `applyVisibility` krever tilgang til kundefrontenden. Det finnes ingen `findVisible*`. De fleste skrivinger ligger i `NoticeController`, `GoNoGoAssessmentController` og `AiController`, ikke i tjenester.

| ID | Funksjon | Domenetjeneste | API-endepunkt | API-status | Integrasjon i dag | Kat. | Avhengigheter | Fase |
|---|---|---|---|---|---|---|---|---|
| ANB-01 | Les saker, bud-cockpit og én sak | `SavedNoticeAccessService::visibleQueryFor`/`cockpitScopeQueryFor`, `SavedNoticeWorkflowSignals` | – | – | Nei | B | Ny lesevei for integrasjoner, avdelingsomfang, K16 og sperren i `isBidManager()` (se funn) | 7 |
| ANB-02 | Les krav og svarstatus i en sak | `RequirementLoader::loadForCase` | – | – | Nei | B | Som ANB-01 | 7 |
| ANB-03 | Søk i kunngjøringer og varsler | `OpportunitySourceRegistry`, `CustomerContext::scopeNoticeDiscovery` | – | – | Nei | B | Synligheten følger `notice_attentions` | 7 |
| ANB-04 | Last ned konkurransedokumenter | `DoffinNoticeDocumentService::syncFromNotice` + inline | – | – | Nei | B | Filer, og skriving ved lesing | 7 |
| ANB-05 | Saksarbeid: lagre sak, frister, aksjoner, fasekommentarer, tilgang, innsendinger, Go/No-go-vurdering, tilbudsansvarlig | Inline i kontrollerne. `OpportunityRegistrar` ved lagring. | – | – | Nei | B | Må flyttes til tjenester før API | 7 |
| ANB-06 | Flytt til historikk med utfall (vunnet, tapt, No-Go) | Inline i `NoticeController::archiveSavedNotice` | – | – | Nei | B | A46: er utfallet en menneskelig beslutning? | 7 |
| ANB-07 | Kravarbeid: legg til, endre og slett krav, svargrunnlag, ansvarlig, arbeidsstatus, rediger svar | `RequirementEditorService`, `RequirementAnswerBasisService`, `RequirementWikiAnswerService::updateAnswerText` (ansvarlig og arbeidsstatus inline) | – | – | Nei | B | Egen nøkkel for Anbud-AI (fase 0-restpunkt) | 7 |
| ANB-08 | AI: uttrekk av krav, generer svar fra Wiki, oppdater vurderinger | `RequirementExtractionRunService`, `RequirementWikiAnswerService::generate`, `RequirementWikiAssessmentService::assessRequirement` | – | – | Nei (sperret) | B | D11, S4. Svar og vurderinger kjører synkront i forespørselen. | 7 |
| ANB-09 | Eksporter krav til Word | `RequirementWordExportService::build` | – | – | Nei | B | Fil | 7 |
| ANB-10 | Søkeprofiler, avdelinger og Go/No-go-maler | Inline | – | – | Nei | B | Kundeadministrasjon | Ikke anbefalt |
| ANB-11 | Godkjenn, avvis eller gjenopprett krav | `RequirementEditorService::transitionRequirementReviewStatus` m.fl. | – | – | Aldri | C | `TENDER_REQUIREMENT_DECIDE`, B4 | – |
| ANB-12 | Endre budstatus og gjenåpne etter No-Go | `SavedNoticeStatusService::transition`/`reopenAfterNoGo` | – | – | Aldri | C | `TENDER_BID_STATUS` | – |

### Felles

| ID | Funksjon | Domenetjeneste | API-endepunkt | API-status | Integrasjon i dag | Kat. | Avhengigheter | Fase |
|---|---|---|---|---|---|---|---|---|
| FEL-01 | Hvem er jeg, moduler, rettigheter og fagområder | `ApiAccessSummary` | `GET /api/v1/me` | Implementert | Ja | A | Utvides når nye moduler får API | Ferdig |
| FEL-02 | Samlet oppmerksomhet på tvers av moduler | `RiskAttentionService`, `ObjectiveAttentionService`, `ImprovementAttentionService` (fase 2). `SupplierAttentionService::overview`, `ComplianceAttentionService`, `ComplianceAuditAttentionService`, `QualityAttentionService` kommer i fase 3. | `GET /api/v1/attention` | Implementert | Ja (lesing) | A | Bare moduler aktøren har tilgang til. Ledelsens gjennomgang har ingen egen oppmerksomhetstjeneste. | Ferdig for fase 2, utvides i 3 |
| FEL-03 | Fagområder (referansedata) | `ApiAccessSummary::readableAreas` | `GET /api/v1/business-areas` | Implementert | Ja | A | Samme regel som `/me`, med «alle» skrevet ut | Ferdig |
| FEL-04 | Mine oppgaver | `MyTasksService::tasksFor` (ni `MyTaskSource`-er) | – | – | Nei | B | Delegert modus (D2). Gir ingen mening for en integrasjon. | 5 |
| FEL-05 | Varsler (bjellen) | `UserNotificationService` | – | – | Nei | B | Personlig. Bare i delegert modus, om i det hele tatt. | 5 eller aldri |
| FEL-06 | Kundeadministrasjon: brukere, roller, fagområder, Tilganger, integrasjonstildelinger | Inline i kontrollerne, `ApiClientGrantService` | – | – | Nei | B | **Anbefales permanent utenfor API-et.** En integrasjon skal aldri kunne administrere tilgang, heller ikke sin egen. | Ikke aktuelt |

---

## Del 4 – Utviklingsplan

Planen ble vedtatt 2026-10-24. Rekkefølgen for de nærmeste leveransene står i del 1 under «Vedtatt rekkefølge for de neste leveransene».

Prinsipper: bruk eksisterende domenetjenester og tilgangstjenester, gjenbruk mønsteret fra de seks operasjonene som finnes, og åpne skriving én navngitt operasjon om gangen. Presentasjonsklasser deles med webgrensesnittet når de trekkes ut, slik at de to ikke glir fra hverandre. Planen har ingen tidsestimater.

### Fase 1 – Dagens API produksjonsklart for pilot

| | |
|---|---|
| **Mål** | De seks operasjonene kan brukes av Siggjo mot en testkunde i staging. |
| **Moduler** | Fundamentet, Risiko, Mål og KPI, Avvik og forbedringer |
| **Leveranser** | OpenAPI 3.1-spesifikasjon for dagens operasjoner, med kontraktstest mot rutene (ferdig lokalt 2026-10-24). Merge til `main` etter V1–V3. Migreringene kjørt i staging. Entra-registrering med begge app-rollene. Siggjos identitet registrert og tildelt hos testkunden. |
| **Avhengigheter** | Advania og Siggjo (Entra og staging). Produkteierens godkjenning av merge. |
| **Kompleksitet** | Lav teknisk, høy ekstern avhengighet |
| **Ferdig når** | V1–V4 er gjennomført og signert. Et ekte token fra Siggjo gir riktig svar på alle seks operasjoner, og de negative prøvene i V4 avvises. Spesifikasjonen er publisert til Siggjo. |

### Fase 2 – Fullføre lesing i påbegynte moduler

| | |
|---|---|
| **Mål** | Alle A-funksjoner for lesing i Risiko, Mål og KPI og Avvik og forbedringer er tilgjengelige, pluss samlet oppmerksomhet. |
| **Moduler** | Risiko, Mål og KPI, Avvik og forbedringer, Felles |
| **Leveranser** | RIS-02, RIS-03, MOK-03, MOK-04, MOK-05, AVF-02, AVF-03, FEL-02 (for de tre modulene), FEL-03. Presentasjon trukket ut av webkontrollerne og delt med API-et. |
| **Avhengigheter** | Fase 1 for produksjon. Kan bygges lokalt parallelt. Feltvalg etter D8, uten fritekst. |
| **Kompleksitet** | Lav–middels |
| **Ferdig når** | De tre modulene er «Fullt dekket» for lesing: hver A-lesefunksjon har endepunkt, OpenAPI-beskrivelse og tester for kundeseparasjon, fagområder og manglende rettighet. |

### Fase 3 – Lesing i resten av styringsmodulene

| | |
|---|---|
| **Mål** | Etterlevelse og revisjon, Leverandøroppfølging, Ledelsens gjennomgang og Kvalitet kan leses gjennom API-et. |
| **Moduler** | Etterlevelse og revisjon, Leverandøroppfølging, Ledelsens gjennomgang, Kvalitet |
| **Leveranser** | ETR-01–05, LEV-01–03, LDG-01–03, KVA-01, KVA-03, KVA-04, MOK-06. En lesetjeneste for Kvalitet (`visible*`/`findVisible*` i `QualityAccessService`). FEL-02 utvidet. |
| **Avhengigheter** | Beslutning om KVA-02 (godkjent versjon eller arbeidsversjon). Kunden må gi integrasjonen eksplisitt tildeling for Etterlevelse og Leverandør. Rekkefølgen avklares med Siggjo. |
| **Kompleksitet** | Middels (Kvalitet høyest, Ledelsens gjennomgang lavest) |
| **Ferdig når** | Alle A-funksjoner i de fire modulene har endepunkt, spesifikasjon og tester, og ingen fritekst eller filer går ut uten beslutning. |

### Fase 4 – Avgrensede registreringer

| | |
|---|---|
| **Mål** | Agenter kan registrere utkast og forslag som mennesker deretter behandler. De tar aldri en beslutning. |
| **Moduler** | Avvik og forbedringer, Risiko, eventuelt de andre styringsmodulene |
| **Leveranser** | Felles forutsetninger først: idempotens for skrivekall, opphavsvisning («Registrert av integrasjonen …») i alle moduler som får skriving (S2), og en regel for varsler (S6). Deretter én navngitt operasjon om gangen, i anbefalt rekkefølge: AVF-04 (registrer avvik), AVF-05 (tiltak på sak), RIS-05 (registrer risiko). Andre B-funksjoner bare etter egen beslutning. |
| **Avhengigheter** | Produkteier beslutter hver operasjon. B6 og B7 tas eventuelt opp igjen. Eventuell generisk forslagsmodell (arkitektur §10.1). |
| **Kompleksitet** | Middels per operasjon, høy for fellesforutsetningene |
| **Ferdig når** | Hver vedtatte operasjon står i `ApiOperationCatalog`, kan slås på av System Owner, er idempotent, viser opphav og er dekket av tester. Ingen C-funksjon er berørt. |

### Fase 5 – Tilgang på vegne av en person

| | |
|---|---|
| **Mål** | Personlige dashboards og «Mine oppgaver» viser nøyaktig det personen selv ser. |
| **Moduler** | Felles (FEL-04, eventuelt FEL-05), statusoppdatering på egne tiltak (LDG-06) |
| **Leveranser** | Delegert modus (scopes, kobling via `user_identities`) og `GET /api/v1/my-tasks` |
| **Avhengigheter** | Beslutning om å oppheve D2. Fase 1. |
| **Kompleksitet** | Middels–høy |
| **Ferdig når** | Et delegert token ser det samme som personen i webgrensesnittet. Sperren for menneskelige beslutninger gjelder fortsatt (B2), og tester viser det. |

### Fase 6 – Wiki gjennom den felles kunnskapskjernen

| | |
|---|---|
| **Mål** | Agenter kan lese gjeldende Wiki-kunnskap, stille spørsmål og overføre kunnskap via vanlig menneskelig godkjenning. |
| **Moduler** | Enterprise Wiki, og kunnskapsoverføring fra alle styringsmodulene |
| **Leveranser** | En lesetjeneste for sider uten skriving ved lesing (WIK-01, WIK-02). «Spør Wiki» etter at A23 er løst (WIK-03). Kunnskapsoverføring som navngitt operasjon (WIK-05 og tilsvarende i modulene). Erstatning for D11 med en eksplisitt liste over tillatte AI-operasjoner og streng kontekstkontroll. |
| **Avhengigheter** | Arkitekturbeslutning etter Wiki-sjekklisten. A23. Beslutning om AI-kostnad (S4). Ingen vektorindeks hos Siggjo (K13). |
| **Kompleksitet** | Høy |
| **Ferdig når** | Wiki-sjekklisten er gjennomgått. Bare gjeldende innhold leveres, «utilstrekkelig grunnlag» er et gyldig svar, og AI-bruk via API-et er sporbar og begrenset per kunde. |

### Fase 7 – Anbud

| | |
|---|---|
| **Mål** | Lesing av anbudsstatus, krav og frister for integrasjoner. Eventuelt kravarbeid senere. |
| **Moduler** | Anbud |
| **Leveranser** | Retting av K16 (kundeavgrensede arbeidsflytfelt). `isBidManager()` og tilsvarende sjekker nekter integrasjoner. Beslutning om avdelingsomfang. Ny lesevei i `SavedNoticeAccessService`. Saksarbeid flyttet fra kontrollerne til tjenester. Beslutning om A46 og egen nøkkel for Anbud-AI. |
| **Avhengigheter** | Produkt- og arkitekturbeslutninger over |
| **Kompleksitet** | Høy |
| **Ferdig når** | ANB-01–03 er tilgjengelige med tester for avdelinger og kundeseparasjon, og alle hindre i «Anbud» under funn er lukket. |

### Fase 8 – Kopi av data og agentlag

| | |
|---|---|
| **Mål** | Virksomhetsdashboards med kopi av data hos Siggjo (hvis valgt), og et MCP-lag for agenter. |
| **Moduler** | Alle som har lese-API |
| **Leveranser** | Ved valg av alternativ B: tilgangsprojeksjon, endringslogg og id-endepunkter for avstemming. Et tynt MCP-lag over eksisterende endepunkter. |
| **Avhengigheter** | D7 (alternativ B), D14 (DPIA), databehandleravtaler |
| **Kompleksitet** | Høy |
| **Ferdig når** | Rettighetsendringer og slettinger når Siggjo innen avtalt frist. Hvert MCP-verktøy svarer til ett API-endepunkt, og ingen verktøy tar beslutninger. |

---

## Del 5 – Funn fra denne gjennomgangen

Kontrollert i koden 2026-10-24. Bare funn 1 er løst (se raden). Funn 13–16 kom fra fase 2.

| # | Funn | Betydning | Hører til |
|---|---|---|---|
| 1 | **Ingen OpenAPI-spesifikasjon.** Arkitekturen (§8.4, §11.2 punkt 2) forutsetter en, men den finnes ikke. **Løst 2026-10-24:** [`api/openapi.yaml`](api/openapi.yaml) og `ApiOpenApiContractTest`. | Klientene mangler en formell kontrakt, og kontraktstesten mot spesifikasjonen mangler. | Fase 1 |
| 2 | **`User::isBidManager()` tar ikke hensyn til integrasjoner.** `resolvedBidRole()` faller fortsatt tilbake til `contributor` (arkitektur K4). `isSystemOwner()` og `isQa()` er sperret. Integrasjoner holdes i dag ute av Anbud bare av sperren for kundefrontenden. | Ingen feil i dag, men den må rettes før Anbud får API. | Fase 7 |
| 3 | **K16 er ikke løst.** `internal_status`, `internal_comment`, `assigned_to_user_id` og `decision_by_user_id` ligger fortsatt på de globale `notices`. | Blokkerer Anbud-API. | Fase 7 |
| 4 | **A23 er åpen.** Kvalitets AI-forslag tar ingen aktør og kontrollerer tilgang bare i kontrolleren. | Blokkerer AI-funksjoner i Kvalitet og «Spør Wiki». D11 holder dem sperret. | Fase 6 |
| 5 | **Kvalitet har ingen lesetjeneste.** All lesing er inline i `QualityController`. Oppmerksomheten er avgrenset per kunde, ikke per bruker. | Den største tekniske jobben i fase 3 | Fase 3 |
| 6 | **Wiki-sider og konkurransedokumenter skriver ved lesing** (`WikiController::show`, `NoticeController::show`). | Webvisningen kan ikke gjenbrukes direkte i et lese-API. | Fase 6, 7 |
| 7 | **Leverandørdokumenter virusskannes ikke.** Alle filer har status `not_scanned`, og nedlasting er tillatt (`require_clean_scan=false`). | Filer holdes utenfor API-et til skanning finnes. | – |
| 8 | **Ledelsens gjennomgang:** `openActions()` i registeret spør direkte på kunden, utenom `visibleReviews`. | Må ikke gjenbrukes uendret i API-et. | Fase 3 |
| 9 | **I Etterlevelse er opprettelse av en revisjon også en menneskelig beslutning** (`COMPLIANCE_AUDIT`). | Revisjoner blir et rent lese-API. | – |
| 10 | **To `KpiPresenter`-klasser**, én for web og én for API. | Risiko for at web og API glir fra hverandre (arkitektur §8.2). | Fase 2 |
| 11 | **Statuslinjen i `external-api-phase-1-decisions.md`** sier at «Mål og KPI venter på vurdering» og nevner ikke skriveoperasjonen i §20. | Dokumentet er utdatert øverst. | Dokumentasjon |
| 12 | **K18 er løst.** `wiki.knowledge.handoff` er en egen rettighet i katalogen. Kunnskapsoverføring er bevisst uten menneskesperre, fordi resultatet går til menneskelig godkjenning i Wiki. | Ingen. Gjelder som forutsetning for WIK-05. | – |
| 13 | **RIS-02: Kvalitet-koblingene er ikke levert.** Risikoens kontroller og prosesskontekst er Kvalitets data. Kvalitet er ikke en modul API-et betjener (`ApiRouteContractTest`), feltvalget for Kvalitet er ikke vurdert, og KVA-02 (godkjent versjon eller arbeidsversjon) er ikke besluttet. Fase 2 skulle ikke endre Kvalitet. | RIS-02 er delvis levert. Koblingene kan leveres sammen med MOK-06 når Kvalitet kan leses (fase 3). | Fase 3 |
| 14 | **Presentasjonen er ikke delt med webgrensesnittet.** Fase 2 forutsatte at presentasjonen ble trukket ut av webkontrollerne. API-presentasjonsklassene bruker de samme domenetjenestene som websidene (tilgang, oppmerksomhet, vurdering, tiltak, aksept, verifisering, målinger), men webkontrollerne er ikke endret. Oppgaven sa at ordinære brukeres funksjonalitet ikke skulle endres og at omfattende refaktorering ikke skulle gjøres. | Feltutvalget kan gli fra webgrensesnittet, men reglene kan ikke det. Funn 10 står fortsatt. | Senere opprydding |
| 15 | **`/me` har ingen egen linje for forbedringer.** `deviations` i `/me` og Kundemiljø → Integrasjoner gjelder både `/deviations` og `/improvements`: samme modul og samme rettighet. Linjen er ikke endret, så Kundemiljø er uendret. | Kan forveksles. Beskrevet i spesifikasjonen. | Dokumentasjon |
| 16 | **Tre svar er ikke paginert:** `GET /attention`, `GET /business-areas` og `GET /kpis/{id}/measurements` returnerer hele listen. De er avgrenset av det aktøren kan se, og målehistorikken av én KPI. | Store kunder kan få store svar fra `/attention`. | Følges med i pilot |
