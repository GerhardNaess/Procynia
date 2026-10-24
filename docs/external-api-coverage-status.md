# Procynia API – status og plan for full API-dekning

**Sist oppdatert:** 2026-10-24
**Grunnlag:** koden på grenen `feature/external-api-foundation` (fase 3 med D15–D17, AVF-04 og AVF-05 i fase 4, og WIK-01–03 i fase 6 med D18–D22), `routes/api.php`, `routes/web.php` (kundefrontenden), domenetjenestene i `app/Services/`, registeret i `tests/Feature/Security/HumanDecisionContractTest.php` og arkitekturdokumentene [`external-api-architecture.md`](external-api-architecture.md), [`external-api-phase-0-plan.md`](external-api-phase-0-plan.md) og [`external-api-phase-1-decisions.md`](external-api-phase-1-decisions.md).
**Faseplan:** vedtatt 2026-10-24 (del 1 «Vedtatt faseplan» og del 4).
**Formål:** én samlet oversikt over hva som er ferdig, hva som mangler, rekkefølgen videre og beslutningene som gjenstår. Dokumentet er et styringsdokument. De tekniske detaljene og begrunnelsene står i arkitekturdokumentene.

> **Full API-dekning betyr ikke at AI-agenter skal kunne overta menneskelige beslutninger.** Godkjenninger, publiseringer, faglige vurderinger, aksept av risiko og sletting er forbeholdt mennesker (B1, B2). De er ikke en del av API-et og telles ikke som manglende dekning.

---

## Del 1 – Ledelsesdashboard

### Samlet status

| | |
|---|---|
| **Prosjektstatus** | 🟧 **Delvis dekket.** API-fundamentet er bygget og testet lokalt. Åtte av ni moduler har API-støtte. Lesingen i styringsmodulene er fullført lokalt i fase 2 og 3, etter beslutningene D15–D17. Fase 4 har levert registrering av avvik, forbedringsforslag og tiltak lokalt. Fase 6 har levert publisert Wiki-kunnskap og «Spør Wiki» lokalt, etter D18–D22. **Ingenting er i produksjon.** |
| **API-operasjoner i dag** | **37 operasjoner:** 31 for lesing, 5 for skriving og 1 AI-spørsmål («Spør Wiki»), se tabellen under. Skrivingen og «Spør Wiki» går gjennom fire navngitte operasjoner som System Owner slår på per integrasjon. |
| **Lokalt verifisert** | Ja. Alle 204 API-tester består (kjørt 2026-10-24, etter fase 6). Siste fulle kjøring av PHP-suiten ga 8667 tester og 0 feil (2026-10-24, før OpenAPI og fase 2 og 3). |
| **Produksjonsklart** | **Nei.** Grenen er ikke flettet inn i `main`, migreringene er ikke kjørt i Azure, og API-et er ikke prøvd med et ekte token fra Microsoft Entra ID eller med Siggjo. |
| **API-spesifikasjon** | 🟩 OpenAPI 3.1 for alle 37 operasjoner i [`api/openapi.yaml`](api/openapi.yaml), validert og låst mot rutene med en kontrakttest. 335 ekte svar fra API-testene er kontrollert mot skjemaene uten avvik (2026-10-24, etter fase 3), og 26 svar fra Wiki-testene etter fase 6. |
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
| 15 | Ledelsens gjennomganger (`GET /api/v1/management-reviews`) | Lesing | Ledelsens gjennomgang | Ja | Nei |
| 16 | Én gjennomgang med seksjoner, beslutninger og tiltak (`GET /api/v1/management-reviews/{id}`) | Lesing | Ledelsens gjennomgang | Ja | Nei |
| 17 | Kravregister (`GET /api/v1/compliance/requirements`) | Lesing | Etterlevelse og revisjon | Ja | Nei |
| 18 | Ett krav med vurderingshistorikk (`GET /api/v1/compliance/requirements/{id}`) | Lesing | Etterlevelse og revisjon | Ja | Nei |
| 19 | Etterlevelsesstatus per kilde (`GET /api/v1/compliance/status`) | Lesing | Etterlevelse og revisjon | Ja | Nei |
| 20 | Revisjoner (`GET /api/v1/compliance/audits`) | Lesing | Etterlevelse og revisjon | Ja | Nei |
| 21 | Én revisjon med scope, kontrollhandlinger og funn (`GET /api/v1/compliance/audits/{id}`) | Lesing | Etterlevelse og revisjon | Ja | Nei |
| 22 | Kravbiblioteket (`GET /api/v1/compliance/frameworks`) | Lesing | Etterlevelse og revisjon | Ja | Nei |
| 23 | Én standard med kapitler og krav (`GET /api/v1/compliance/frameworks/{id}`) | Lesing | Etterlevelse og revisjon | Ja | Nei |
| 24 | Leverandørregister (`GET /api/v1/suppliers`) | Lesing | Leverandøroppfølging | Ja | Nei |
| 25 | Én leverandør med krav, aktsomhet, plan og koblinger (`GET /api/v1/suppliers/{id}`) | Lesing | Leverandøroppfølging | Ja | Nei |
| 26 | Kontrollkrav (`GET /api/v1/suppliers/control-requirements`) | Lesing | Leverandøroppfølging | Ja | Nei |
| 27 | Kvalitetsregister (`GET /api/v1/quality/items`) | Lesing | Kvalitet | Ja | Nei |
| 28 | Kontrollregister (`GET /api/v1/quality/controls`) | Lesing | Kvalitet | Ja | Nei |
| 29 | Én KPI med prosessene den måler (`GET /api/v1/kpis/{id}`) | Lesing | Mål og KPI | Ja | Nei |
| 30 | Ett kvalitetselement med godkjent prosessflyt (`GET /api/v1/quality/items/{id}`) | Lesing | Kvalitet | Ja | Nei |
| 31 | Registrer avvik (`POST /api/v1/deviations`) | Skriving | Avvik og forbedringer | Ja | Nei |
| 32 | Registrer forbedringsforslag (`POST /api/v1/improvements`) | Skriving | Avvik og forbedringer | Ja | Nei |
| 33 | Opprett tiltak på et avvik (`POST /api/v1/deviations/{id}/actions`) | Skriving | Avvik og forbedringer | Ja | Nei |
| 34 | Opprett tiltak på en forbedring (`POST /api/v1/improvements/{id}/actions`) | Skriving | Avvik og forbedringer | Ja | Nei |
| 35 | Publiserte Wiki-sider (`GET /api/v1/wiki/pages`) | Lesing | Enterprise Wiki | Ja | Nei |
| 36 | Én publisert Wiki-side med lenker (`GET /api/v1/wiki/pages/{id}`) | Lesing | Enterprise Wiki | Ja | Nei |
| 37 | Spør Wiki over publisert kunnskap (`POST /api/v1/wiki/ask`) | AI-spørsmål (skriver ikke) | Enterprise Wiki | Ja | Nei |

«Lokalt verifisert» betyr automatiske tester med simulerte Entra-tokens og testdata. Det betyr ikke at operasjonen er prøvd mot Microsoft, Azure eller Siggjo.

### API-fundamentet

| Del | Status | Kommentar |
|---|---|---|
| Innlogging for applikasjoner (Entra ID, applikasjonsmodus) | 🟧 Delvis dekket | Bygget og testet lokalt. Ikke prøvd med ekte token. |
| Innlogging på vegne av en person (delegert modus) | ⬜ Ikke startet | Utsatt etter beslutning D2. Trengs for «Mine oppgaver» og personlige dashboards. |
| Kundetildeling og integrasjonsidentitet | 🟩 Fullt dekket (lokalt) | System Owner gir og trekker tilgang i **Kundemiljø → Integrasjoner**. Interne administratorer registrerer applikasjoner i Filament. Alt logges. |
| Integrasjoner behandles aldri som mennesker | 🟩 Fullt dekket (lokalt) | Ingen innlogging, lisens, varsler, eierskap eller beslutningsrett. |
| Sperre for menneskelige beslutninger | 🟩 Fullt dekket (lokalt) | Sperren i domenelaget og i databasen gjelder alle ni moduler. |
| Sperre for AI-kall via API-et | 🟩 Fullt dekket (lokalt) | Bare «Spør Wiki» kan bruke AI, og bare de tre Wiki-operasjonene fra den ruten (`ApiAiOperations`, D19). Kundens AI-kapasitet er en hard grense på API-kanalen, også ved samtidige kall (D20). |
| Felles feilformat, logging, ratebegrensning | 🟩 Fullt dekket (lokalt) | |
| Modell for navngitte operasjoner | 🟧 Delvis dekket | Modellen er generell. Katalogen har fire operasjoner: `risk.treatment_action.create`, `improvement.case.create`, `improvement.action.create` og `wiki.ask`. Bare `wiki.ask` skriver ingenting, og den krever derfor ikke skriverollen (D19). |
| Beskyttelse mot dobbelregistrering (idempotens) | ⬜ Ikke startet | Et gjentatt skrivekall gir duplikat. Akseptert for de tre operasjonene, slik som for risikotiltak (funn 23). Ingen ny mekanisme er bygget. |
| API-spesifikasjon (OpenAPI) | 🟩 Fullt dekket (lokalt) | [`api/openapi.yaml`](api/openapi.yaml) beskriver de seks operasjonene. Validert mot OpenAPI 3.1 med Redocly CLI. `ApiOpenApiContractTest` låser stier, metoder, app-roller, enums og feilkoder mot koden. Vedlikehold: [`api/README.md`](api/README.md). Ikke publisert til Siggjo ennå. |
| Detaljvisning av enkeltposter (`/{id}`) | 🟩 Fullt dekket (lokalt) for de sju modulene med API | Kvalitet viser bare godkjent prosessflyt (D15). |
| Samlet oppmerksomhetsliste | 🟩 Fullt dekket (lokalt) for modulene med oppmerksomhetstjeneste | `GET /api/v1/attention` for Risiko, Avvik og forbedringer, Mål og KPI, Etterlevelse og revisjon, Leverandøroppfølging og Kvalitet. Ledelsens gjennomgang har ingen oppmerksomhetstjeneste. `controls_without_activity` er utelatt (D17). |
| Drift: WAF/API Management, Log Analytics-retensjon | ⬜ Ikke startet | Beslutning D12 tas før første kunde med ekte data. |
| Personvern: DPIA og databehandleravtaler | ⬜ Ikke startet | D14. Kreves før ekte kundedata går til Siggjo. Blokkerer ikke pilot med testdata. |

### Status per modul

| Modul | Status | Lesing i API | Skriving i API | Det viktigste som mangler |
|---|---|---|---|---|
| Risiko | 🟧 Delvis dekket | Register, én risiko med kontroller og prosesser, oppmerksomhet | Opprett tiltak | Alle A-funksjoner er levert lokalt. Ikke produksjonsklar. Registrering av risiko (fase 4). |
| Mål og KPI | 🟧 Delvis dekket | Mål, ett mål med KPI-er, KPI-er, én KPI med prosesser, full målehistorikk, oppmerksomhet | – | Alle A-funksjoner er levert lokalt. Ikke produksjonsklar. |
| Avvik og forbedringer | 🟧 Delvis dekket | Avvik, forbedringer, én sak med tiltak, oppmerksomhet | Registrer avvik eller forbedringsforslag, opprett tiltak (navngitte operasjoner) | Alle A-funksjoner er levert lokalt. Ikke produksjonsklar. |
| Kvalitet | 🟧 Delvis dekket | Register, kontroller, ett element med godkjent prosessflyt, oppmerksomhet | – | Alle A-funksjoner er levert lokalt. Ikke produksjonsklar. |
| Etterlevelse og revisjon | 🟧 Delvis dekket | Kravregister, status, revisjoner, kravbibliotek, oppmerksomhet | – | Alle A-funksjoner er levert lokalt. Ikke produksjonsklar. |
| Leverandøroppfølging | 🟧 Delvis dekket | Register, én leverandør, kontrollkrav, oppmerksomhet | – | Alle A-funksjoner er levert lokalt. Ikke produksjonsklar. |
| Ledelsens gjennomgang | 🟧 Delvis dekket | Gjennomganger, én gjennomgang med seksjoner, beslutninger og tiltak | – | Alle A-funksjoner er levert lokalt. Ikke produksjonsklar. |
| Enterprise Wiki | 🟧 Delvis dekket | Publiserte sider, én publisert side med lenker, «Spør Wiki» fra publisert kunnskap (D18–D21) | – | Kunnskapsoverføring (WIK-05) er utsatt (D22). Ikke produksjonsklar. |
| Anbud | 🟥 Blokkert | – | – | Fire kjente hindre må løses først (se under) |

Ingen modul er «Fullt dekket». Det betyr at alle funksjoner i kategori A (se del 3) har API-støtte, er verifisert og er produksjonsklare.

### Implementert, planlagt og blokkert

Grunnlaget er de 117 funksjonene i del 3: **32 A-funksjoner, 51 B-funksjoner og 34 C-funksjoner.** Hver funksjon har nøyaktig én av statusene under.

| Status | Betydning | A | B | C | Sum |
|---|---|---|---|---|---|
| 🟩 **Implementert** | Endepunktet finnes og er verifisert lokalt. Ikke i produksjon. | 32 | 4 | – | 36 |
| 🟦 **Planlagt** | Har en fase i den vedtatte planen og kan bygges uten ny beslutning. | – | – | – | – |
| 🟥 **Blokkert** | Har en fase, men kan ikke startes eller fullføres før en navngitt beslutning eller hindring er løst. | – | 39 | – | 39 |
| ⬛ **Ikke anbefalt** | Ikke planlagt. Tas bare opp igjen etter egen beslutning. | – | 8 | – | 8 |
| ⛔ **Utenfor API-et** | Formell menneskelig beslutning. Kan aldri utføres av en integrasjon og teller ikke som manglende. | – | – | 34 | 34 |
| **Sum** | | **32** | **51** | **34** | **117** |

**Implementert (32 A-funksjoner og 4 B-funksjoner):** RIS-01–04, MOK-01–06, AVF-01–05, FEL-01–03, ETR-01–05, LEV-01–03, LDG-01–03, KVA-01, KVA-03 og KVA-04, og B-funksjonene KVA-02 og WIK-01–03.

- Fase 2 leverte RIS-03, MOK-03, MOK-04, resten av MOK-05, AVF-02, AVF-03, FEL-02 og resten av FEL-03.
- Fase 3 leverte ETR-01–05, LEV-01–03, LDG-01–03, KVA-01 og KVA-03, og utvidet FEL-02.
- Etter D15–D17 er RIS-02, MOK-06, KVA-04 og KVA-02 fullført (2026-10-24).
- Fase 4 leverte AVF-04 og AVF-05 (2026-10-24). Alle 32 A-funksjoner er nå implementert lokalt.
- Fase 6 leverte WIK-01–03 etter D18–D22 (2026-10-24).

**Planlagt:** ingen. AVF-04 og AVF-05 er levert (fase 4). Fellesforutsetningene ble avgjort slik: ingen idempotens (som for risikotiltak), opphavet vises i Avvik og forbedringer, og varslene følger webgrensesnittets regel (funn 23–25).

**Blokkert (39 B-funksjoner):** alle B-funksjoner som har en fase, unntatt KVA-02 og WIK-01–03. WIK-05 og kunnskapsoverføringen i modulene er utsatt (D22). Hindringen står i kolonnen «Avhengigheter» i del 3, og de samlede beslutningene står i «Viktigste avhengigheter og beslutninger» under. Hele Anbud-modulen er blokkert (funn 2, 3 og 6 i del 5, og K16).

**Ikke anbefalt (8 B-funksjoner):** RIS-06, ETR-07, LEV-04, LDG-04, LDG-05, WIK-04, ANB-10, og FEL-06 (permanent utenfor API-et).

### Vedtatt faseplan

Faseplanen ble vedtatt 2026-10-24. Endringer i rekkefølge eller innhold krever ny beslutning og føres inn her.

| Fase | Mål | Moduler | Status |
|---|---|---|---|
| **1** | Gjøre dagens API produksjonsklart for pilot | Fundamentet | 🟧 Påbegynt. OpenAPI-spesifikasjonen er ferdig. Entra, staging og V4 venter på Advania og Siggjo. |
| **2** | Fullføre lesing i modulene som er påbegynt | Risiko, Mål og KPI, Avvik og forbedringer | 🟩 Fullført lokalt 2026-10-24. RIS-02 ble fullført etter D15. Ikke produksjonsklar. |
| **3** | Lesing i resten av styringsmodulene | Etterlevelse og revisjon, Leverandøroppfølging, Ledelsens gjennomgang, Kvalitet | 🟩 Fullført lokalt 2026-10-24, med restpunktene løst etter D15–D17. Ikke produksjonsklar. |
| **4** | Avgrensede registreringer (utkast og forslag) | Styringsmodulene | 🟧 Påbegynt. AVF-04 og AVF-05 levert lokalt 2026-10-24. Andre registreringer (for eksempel RIS-05) krever beslutning per operasjon. |
| **5** | Tilgang på vegne av en person | Mine oppgaver, personlige dashboards | 🟥 Blokkert av D2. Kontrollert 2026-10-24: ingen av fasens funksjoner kan bygges før D2 er opphevet (funn 27). Ingenting er implementert. |
| **6** | Wiki gjennom den felles kunnskapskjernen | Enterprise Wiki | 🟧 Levert lokalt 2026-10-24: WIK-01–03 etter D18–D22. Kunnskapsoverføring (WIK-05) er utsatt (D22, S4). Ikke produksjonsklar. |
| **7** | Anbud | Anbud | 🟥 Blokkert av K16, `isBidManager()`, avdelingsomfang og A46. Kontrollert 2026-10-24: ingen funksjon er godkjent, og hindrene står fortsatt i koden (funn 28). Ingenting er implementert. |
| **8** | Kopi av data og agentlag | Siggjos datamodell, MCP | 🟥 Blokkert av D7 og D14. Kontrollert 2026-10-24: alternativ B er ikke valgt (D7), og MCP-laget er ikke besluttet (funn 29). Ingenting er implementert. |

### Vedtatt rekkefølge for de neste leveransene

| # | Leveranse | Fase | Kan gjøres lokalt | Status |
|---|---|---|---|---|
| 1 | Statusdokumentet med vedtatt faseplan (dette dokumentet) | – | Ja | 🟩 Ferdig |
| 2 | **OpenAPI 3.1-spesifikasjon for de seks eksisterende operasjonene**, med kontraktstest mot rutene | 1 | Ja | 🟩 Ferdig lokalt 2026-10-24 ([`api/openapi.yaml`](api/openapi.yaml)) |
| 3 | Fase 2: fullføre API-dekningen for lesing i Risiko, Avvik og forbedringer og Mål og KPI | 2 | Ja | 🟧 Levert lokalt 2026-10-24. Åtte av ni funksjoner er ferdige. RIS-02 er delvis levert (funn 17). |
| 4 | Fase 3: lesing i Etterlevelse og revisjon, Leverandøroppfølging, Ledelsens gjennomgang og Kvalitet | 3 | Ja | 🟧 Levert lokalt 2026-10-24. 13 av 15 funksjoner er ferdige. MOK-06 og KVA-04 er delvis levert (funn 17). |
| – | Merge etter V1–V3, Entra-registrering, Siggjos identitet, staging og V4 | 1 | Nei | 🟥 Venter på produkteier, Advania og Siggjo. Løper parallelt. |

Etter fase 2 er de tre modulene fullt dekket **for lesing**. Med MOK-06 (fase 3) og AVF-04 og AVF-05 (fase 4) har de nå alle A-funksjoner lokalt.

Detaljer står i del 4.

### Viktigste avhengigheter og beslutninger

| # | Avhengighet eller beslutning | Blokkerer | Hvem |
|---|---|---|---|
| 1 | API-registrering i Entra, Siggjos identitet og staging-miljø | Fase 1 (pilot) | Advania / Siggjo |
| 2 | Merge av `feature/external-api-foundation` til `main`, med de åpne verifiseringene V1–V3 | Fase 1 | Produkteier |
| 3 | DPIA og databehandleravtaler (D14) | Ekte kundedata i Siggjo | Produkteier / juridisk |
| 4 | WAF eller API Management (D12) | Første kunde med ekte data | Produkteier / drift |
| 5 | Idempotens og visning av opphav i alle moduler | Fase 4 (flere skriveoperasjoner). For AVF-04 og AVF-05: ingen idempotens, opphav vist i Avvik og forbedringer (funn 23 og 24). | Arkitektur |
| 6 | Hvilke registreringer agenter skal få gjøre, én operasjon om gangen | Fase 4 | Produkteier |
| 7 | B6 og B7: skal agenter kunne laste opp kildedokumenter eller registrere KPI-målinger? I dag er svaret nei. | Fase 4 | Produkteier |
| 8 | Om agentutløst AI-arbeid er tillatt, og hvordan kostnaden begrenses (S4). **Løst for «Spør Wiki» (D20).** Gjenstår for kunnskapsoverføring, som starter en Wiki-kjøring (D22). | Fase 4 og kunnskapsoverføring i fase 6 | Produkteier |
| 9 | A23: Kvalitets AI-forslag og «Spør Wiki» må kontrollere tilgang i tjenesten. **Løst for «Spør Wiki»** (`askAs()`, D19). Kvalitets AI-forslag gjenstår. | Kvalitets AI-forslag | Arkitektur |
| 10 | Anbud: delte arbeidsflytfelt på kunngjøringer (K16), avdelingsomfang og A46 | Fase 7 | Produkteier / arkitektur |
| 11 | ~~KVA-02: skal API-et vise godkjent versjon av prosessflyten, arbeidsversjonen eller begge?~~ **Besluttet 2026-10-24 (D15): bare godkjent versjon.** | Ingenting lenger. RIS-02, MOK-06 og KVA-02 kan implementeres. | Produkteier |
| 12 | ~~Skal beslutningstekstene i Ledelsens gjennomgang eksponeres?~~ **Besluttet 2026-10-24 (D16): nei, ikke før innholdet er vurdert som egnet.** | Ingenting. API-et oppfyller dette allerede. | Produkteier |
| 14 | **D2: skal delegert modus (tilgang på vegne av en person) bygges?** I dag gjelder D2: bare applikasjonsmodus. Hvis ja: hvilke scopes, om FEL-05 (varsler) skal være med, og om LDG-06 (statusoppdatering på egne tiltak) skal være en skriveoperasjon i delegert modus. | Hele fase 5 (FEL-04, FEL-05, LDG-06). Se funn 27. | Produkteier |
| 15 | ~~Fase 6: hvordan skal integrasjoner få tilgang til Wiki-kunnskap?~~ **Besluttet 2026-10-24 (D18–D22):** bare publisert versjon, «Spør Wiki» som egen operasjon, hard kapasitetsstopp, sitater med utdrag, kunnskapsoverføring utsatt. | Ingenting for WIK-01–03. WIK-05 venter på S4. | Produkteier |
| 13 | ~~D17: hvordan skal `controls_without_activity` vurderes i API-et?~~ **Besluttet 2026-10-24 (D17): utelates fra API-et.** Kvalitets regler og visning endres ikke, og det lages ingen egen API-regel. | Ingenting. KVA-04 er fullført. | Produkteier |

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
| **Risiko** | Risikoregister, én risiko med vurderinger, tiltak og kontroller, oppmerksomhet, registrering av tiltak | Register og én risiko med vurderingshistorikk, tiltak, aksept av restrisiko, Kvalitet-koblinger og oppmerksomhetskoder | Opprett tiltak (navngitt operasjon) | Mulig registrering av ny risiko (fase 4). | Vurdering, aksept av restrisiko og sletting er menneskelige beslutninger. Fagområdene styrer synligheten. | Lav–middels | 2, 4 |
| **Mål og KPI** | Mål, KPI-er, måloppnåelse, målehistorikk, oppmerksomhet | Mål, ett mål med KPI-er, KPI-er med siste 24 målinger, full målehistorikk, oppmerksomhetskoder | – | Ingen A-funksjon | Målinger, avslutning og sletting er menneskelige beslutninger (B7). Fagområdene styrer synligheten. | Lav | 2 |
| **Avvik og forbedringer** | Avvik, forbedringsforslag, tiltak, effektverifisering, registrering av avvik | Avvik og forbedringer hver for seg, én sak med tiltak og effektverifisering, oppmerksomhetskoder | Registrer avvik eller forbedringsforslag, opprett tiltak (navngitte operasjoner) | Ingen A-funksjon | Lukking, effektverifisering og sletting er menneskelige beslutninger. Avvikstitler kan inneholde særlige kategorier av personopplysninger. | Lav–middels | 2, 4 |
| **Kvalitet** | Prosesser, rutiner, kontroller, evidens, godkjent prosessflyt, oppmerksomhet | Register, kontroller med evidens og verktøy, ett element med relasjoner, publisering og godkjent prosessflyt (D15), oppmerksomhet | – | Ingen A-funksjon. `controls_without_activity` er utelatt (D17). | Godkjenning, innføring av AI-forslag og sletting er menneskelige beslutninger. AI-forslagene er sperret (A23, D11). Prosessflyt krever versjonskontroll (`base_hash`) ved skriving. | Middels–høy | 3 |
| **Etterlevelse og revisjon** | Kravregister, etterlevelsesstatus per standard, revisjoner og funn, oppmerksomhet | Kravregister, status per kilde, revisjoner med scope, kontrollhandlinger og funn, kravbibliotek, oppmerksomhet | – | Ingen A-funksjon. Kvalitet-konteksten (prosesser og kontroller) er utelatt. | Vurdering, relevans og **hele revisjonsløpet** (også opprettelse) er menneskelige beslutninger. Modulen krever eksplisitt tildeling. | Middels | 3 |
| **Leverandøroppfølging** | Leverandørregister, kritikalitet, kontrollstatus, oppfølgingsplan | Register, én leverandør med krav, aktsomhet, oppfølgingsplan og koblinger, kontrollkrav, oppmerksomhet | – | Ingen A-funksjon | Registrering, kritikalitet, livsløp, vurderinger og kontrollarbeid er menneskelige beslutninger. Krever eksplisitt tildeling. Dokumentfiler skannes ikke i dag. | Middels | 3 |
| **Ledelsens gjennomgang** | Gjennomganger, seksjoner, beslutninger og tiltak med status | Gjennomganger, én gjennomgang med seksjoner, beslutninger og tiltak | – | Ingen A-funksjon. Grunnlagstallene og beslutningstekstene er utelatt (funn 19). | Ferdigstilling og sletting er menneskelige beslutninger. Seksjoner fra andre moduler vises bare med tilgang til modulen. | Lav–middels (presentasjonsklassen finnes) | 3 |
| **Enterprise Wiki** | Lese publisert kunnskap, navigere, stille spørsmål, overføre kunnskap fra andre moduler | Publiserte sider, én publisert side med lenker, «Spør Wiki» fra publisert kunnskap (D18–D21) | – | Kunnskapsoverføring (WIK-05), utsatt (D22) | All redigering, godkjenning, kildeadministrasjon og alle kjøringer er menneskelige beslutninger. Bare publisert versjon, aldri arbeidsversjonen (D18). «Spør Wiki» bruker kundens AI-kapasitet, som er en hard grense på API-kanalen (D20). | Høy | 6 |
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
| Risiko | 4 | 4 | 5 | 3 |
| Mål og KPI | 6 | 6 | 4 | 3 |
| Avvik og forbedringer | 5 | 5 | 4 | 3 |
| Kvalitet | 3 | 3 (+ KVA-02, B) | 7 | 4 |
| Etterlevelse og revisjon | 5 | 5 | 4 | 4 |
| Leverandøroppfølging | 3 | 3 | 5 | 5 |
| Ledelsens gjennomgang | 3 | 3 | 4 | 2 |
| Enterprise Wiki | 0 | 0 (+ WIK-01–03, B) | 5 | 8 |
| Anbud | 0 | 0 | 10 | 2 |
| Felles | 3 | 3 | 3 | 0 |
| **Sum** | **32** | **32** | **51** | **34** |

Kartleggingen dekker 311 webruter i de ni modulene (68 lesende, 243 skrivende) og 35 felles ruter (blant annet Kundemiljø, brukere, varsler, fakturering og Hjem). Ruter som bare viser et skjema, er ikke egne funksjoner.

### Risiko

Tilgang: `RiskAccessService` (`visibleRisks`, `findVisible`, `can*`) med fagområder.

| ID | Funksjon | Domenetjeneste | API-endepunkt | API-status | Integrasjon i dag | Kat. | Avhengigheter | Fase |
|---|---|---|---|---|---|---|---|---|
| RIS-01 | Les risikoregister | `RiskAccessService::visibleRisks`, `RiskAssessment::latestForRisks`, `RiskScoringPolicy`, `RiskReviewSchedule` | `GET /api/v1/risks` | Implementert | Ja | A | – | Ferdig |
| RIS-02 | Les én risiko: vurderingshistorikk, tiltak, aksept, kontroller, prosesskontekst | `findVisible`, `RiskTreatmentService::actionsFor`, `RiskAcceptanceService::decisionFor`, `RiskControlService::linkedControls`, `RiskQualityContextService::linkedContext` | `GET /api/v1/risks/{riskId}` | Implementert | Ja (lesing) | A | Vurderinger, tiltak, aksept, og med `quality.view` kontroller og prosesser med aktivitetene navngitt fra den godkjente flyten (D15). Uten fritekst og personer (D8). | Ferdig |
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
| MOK-06 | KPI-ens kobling til prosess og aktivitet (lesing) | `KpiQualityContextService::linkedContext` | `GET /api/v1/kpis/{kpiId}` (`processes`) | Implementert | Ja (med `quality.view`) | A | Med `quality.view`. Aktivitetene navngis fra den godkjente flyten (D15). | Ferdig |
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
| AVF-04 | Registrer avvik eller forbedringsforslag (opprettes som «Åpen») | `ImprovementCaseCreator::create` | `POST /api/v1/deviations`, `POST /api/v1/improvements` | Implementert | Ja (operasjonen `improvement.case.create`) | A | Ingen idempotens. Opphavet vises på saksiden. Beskrivelsen skrives, men leses aldri tilbake. Ekte Entra-rolle `Procynia.Write.All` gjenstår. | Ferdig |
| AVF-05 | Opprett tiltak på en sak | `ImprovementActionLifecycleService::create` | `POST /api/v1/deviations/{caseId}/actions`, `POST /api/v1/improvements/{caseId}/actions` | Implementert | Ja (operasjonen `improvement.action.create`) | A | Samme mønster som risikotiltak. Tjenesten validerer ansvarlig selv. Feltreglene ligger i tjenesten (`rules()`, `fields()`). Ingen idempotens. | Ferdig |
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
| KVA-01 | Les kvalitetsregister: prosesser, rutiner, kontroller, dokumenter | Ingen. Inline i `QualityController::index`. | `GET /api/v1/quality/items` | Implementert | Ja (`quality.view`) | A | Kundeavgrenset lesing under Kvalitets egen leseregel (`QualityProcessContextReader::canRead`). Uten publiseringsstatus (KVA-02). | Ferdig |
| KVA-02 | Les ett element med prosessflyt, koblinger og publiseringsstatus | Inline i `QualityController::show`, `QualityProcessBlueprintService::publicationState`/`history` | `GET /api/v1/quality/items/{itemId}` | Implementert | Ja (lesing) | B | Bare den godkjente revisjonen (D15). Aldri arbeidsversjonen eller `has_unpublished_changes`. | Ferdig |
| KVA-03 | Les kontrollregister med evidens og verktøy | Inline, `QualityToolService::forControl` | `GET /api/v1/quality/controls` | Implementert | Ja (lesing) | A | Frekvens, aktiv evidens (antall, siste) og verktøy. Uten plasseringer i prosessflyt (KVA-02), fritekst og filer. | Ferdig |
| KVA-04 | Kvalitetsoppmerksomhet | `QualityAttentionService::findings` (per kunde) | Kodene på radene, `GET /api/v1/attention` | Implementert | Ja (lesing) | A | Tre funn. `controls_without_activity` er utelatt for godt (D17). | Ferdig |
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
| ETR-01 | Les kravregister og krav | `visibleRequirements`, `findVisibleRequirement`, `ComplianceRegisterGroups` | `GET /api/v1/compliance/requirements`, `/{requirementId}` | Implementert | Ja (`compliance.view`) | A | Med vurderingshistorikk (resultat og tid). Uten kravtekst, begrunnelser og personer (D8). | Ferdig |
| ETR-02 | Etterlevelsesstatus per kilde eller standard | `ComplianceStatusResolver::latestForRequirements`/`summarize`, `ComplianceAssessmentOverview` | `GET /api/v1/compliance/status` | Implementert | Ja (lesing) | A | Etterlevelsesvurderingens tall og grupper (`ComplianceAssessmentOverview`) | Ferdig |
| ETR-03 | Les revisjoner med scope, kontrollhandlinger og funn | `visibleAudits`, `findVisibleAudit` | `GET /api/v1/compliance/audits`, `/{auditId}` | Implementert | Ja (lesing) | A | Scope, kontrollhandlinger og funn fra revisjonssidens egne tjenester. Uten fritekst, revisorens navn og Kvalitet-kontekst (D8). | Ferdig |
| ETR-04 | Les kravbibliotek og standarder | `ComplianceFrameworkPresenter` | `GET /api/v1/compliance/frameworks`, `/{frameworkId}` | Implementert | Ja (lesing) | A | Uten bibliotekets beskrivelser og veiledning | Ferdig |
| ETR-05 | Oppmerksomhet for krav og revisjoner | `ComplianceAttentionService::reasonsForRequirements`, `ComplianceAuditAttentionService::reasonsForAudits` | Kodene på radene, `GET /api/v1/attention` | Implementert | Ja (lesing) | A | Tjenestenes egne regler, også `not_assessed` for et bibliotekskrav med åpen relevans (funn 20) | Ferdig |
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
| LEV-01 | Les leverandørregister med kritikalitet, status og kontrollstatus | `visibleSuppliers`, `SupplierAssuranceResolver::forSuppliers`, `SupplierAttentionService::findingsForSuppliers` | `GET /api/v1/suppliers` | Implementert | Ja (`supplier.view`) | A | Uten leveransebeskrivelse, kontaktopplysninger og notater (D8) | Ferdig |
| LEV-02 | Les én leverandør: krav, kontroller, aktsomhet, oppfølgingsplan, koblinger | `findVisibleSupplier`, `SupplierRequirementPayload`, `SupplierFollowUpPlan`, `SupplierDueDiligenceService::payload` | `GET /api/v1/suppliers/{supplierId}` | Implementert | Ja (lesing) | A | Krav, kontrollstatus, aktsomhet, oppfølgingsplan og id-er for koblinger (null uten lesetilgang til modulen). Uten dokumenter, filer, fritekst og personer. | Ferdig |
| LEV-03 | Les katalogen over kontrollkrav | `SupplierRequirementPayload::catalogue` | `GET /api/v1/suppliers/control-requirements` | Implementert | Ja (lesing) | A | – | Ferdig |
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
| LDG-01 | Les gjennomganger (liste) | `visibleReviews` | `GET /api/v1/management-reviews` | Implementert | Ja (`management_review.view`) | A | – | Ferdig |
| LDG-02 | Les én gjennomgang med seksjoner | `ManagementReviewPresenter::show`, `ManagementReviewBasisService::live`/`snapshot` | `GET /api/v1/management-reviews/{reviewId}` | Implementert | Ja (lesing) | A | Gjennomgangssidens egne porter (`ManagementReviewPresenter::show`). Seksjonenes tilstand og vurdering, uten grunnlagstallene og fritekst (funn 19). | Ferdig |
| LDG-03 | Les beslutninger og tiltak med status | `ManagementReviewDecisionService` | `decision_list` i `GET /api/v1/management-reviews/{reviewId}` | Implementert | Ja (lesing) | A | Uten beslutningstekst (funn 19). `openActions()` er ikke gjenbrukt. | Ferdig |
| LDG-04 | Rapport og PDF | `ManagementReviewReportBuilder::document` | – | – | Nei | B | Fil og fritekst (D8) | Ikke anbefalt nå |
| LDG-05 | Opprett eller endre gjennomgang: seksjonsvurdering, konklusjon, deltakere, neste gjennomgang | `ManagementReviewService::*` | – | – | Nei | B | Innholdet er ledelsens egen vurdering | Ikke anbefalt |
| LDG-06 | Beslutninger og tiltak: opprett, endre, status, ny ansvarlig, følg opp i Avvik | `ManagementReviewDecisionService::*` | – | – | Nei | B | Bare statusoppdatering fra ansvarlig kan være aktuelt, og bare i delegert modus | 5 |
| LDG-07 | Kunnskapsartikkel til Wiki | `WikiKnowledgeHandoffService::handOff` | – | – | Nei | B | S4 | 6 |
| LDG-08 | Ferdigstill og legg til tillegg | `ManagementReviewFinalizationService::finalize`, `ManagementReviewService::addAmendment` | – | – | Aldri | C | `MANAGEMENT_REVIEW_FINALIZE` | – |
| LDG-09 | Slett gjennomgang | `ManagementReviewService::delete` | – | – | Aldri | C | `MANAGEMENT_REVIEW_DELETE` | – |

### Enterprise Wiki

Wiki eksponeres bare gjennom den felles navigasjons- og retrieval-kjernen, med gjeldende innhold og etter Wiki-sjekklisten (`enterprise-wiki-architecture.md` §J, `AGENTS.md`). Visningen av en side (`WikiController::show`) er inline og **skriver ved lesing** (`syncForPageVersion`). Den brukes derfor ikke. API-et leser Wikiens egen katalog i `GROUNDING_PUBLISHED_ONLY` gjennom `EnterpriseWikiPublishedKnowledgeReader`, og bare publiserte versjoner (D18).

| ID | Funksjon | Domenetjeneste | API-endepunkt | API-status | Integrasjon i dag | Kat. | Avhengigheter | Fase |
|---|---|---|---|---|---|---|---|---|
| WIK-01 | Les publiserte Wiki-sider | `EnterpriseWikiPublishedKnowledgeReader::pages`/`page` over `RequirementWikiCatalogBuilder` (`GROUNDING_PUBLISHED_ONLY`) | `GET /api/v1/wiki/pages`, `GET /api/v1/wiki/pages/{pageId}` | Implementert | Ja (`wiki.view`) | B | Bare publisert versjon (D18). Sidestatus er ikke et filter. | Ferdig |
| WIK-02 | Navigasjon, relasjoner og graf | `EnterpriseWikiLinkParser` over den publiserte teksten | `links` i `GET /api/v1/wiki/pages/{pageId}` | Implementert | Ja (`wiki.view`) | B | Lenker til publiserte sider, lest fra den publiserte teksten. Lenketabellen følger arbeidsversjonen og brukes ikke. Tilbakelenker og graf er ikke med. | Ferdig |
| WIK-03 | Spør Wiki | `EnterpriseWikiQuestionAnswerService::askAs` (felles kjerne, tilgang i tjenesten) | `POST /api/v1/wiki/ask` | Implementert | Ja, med operasjonen `wiki.ask` | B | Publisert grunnlag (D18), egen operasjon (D19), hard kapasitetsstopp og egen ratebegrensning (D20), sitater med publisert versjon (D21). | Ferdig |
| WIK-04 | Kildedokumenter og kjøringer (lesing) | Inline, `EnterpriseWikiRunFindingsService` | – | – | Nei | B | Administrativ informasjon. Behov ikke dokumentert. | Ikke anbefalt nå |
| WIK-05 | Kunnskapsoverføring fra andre moduler (blir et kildedokument som går gjennom vanlig kjøring og menneskelig godkjenning) | `WikiKnowledgeHandoffService::handOff` (egen rettighet `wiki.knowledge.handoff`, ingen menneskesperre) | – | – | Nei | B | Den eneste lovlige veien inn i Wiki for agenter (arkitektur §10.1). S4: utløser en full Wiki-kjøring med AI. Utsatt (D22). | 6 |
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
| FEL-02 | Samlet oppmerksomhet på tvers av moduler | `RiskAttentionService`, `ObjectiveAttentionService`, `ImprovementAttentionService` (fase 2). `ComplianceAttentionService`, `ComplianceAuditAttentionService`, `SupplierAttentionService`, `QualityAttentionService` (fase 3). | `GET /api/v1/attention` | Implementert | Ja (lesing) | A | Bare moduler aktøren har tilgang til. Ledelsens gjennomgang har ingen egen oppmerksomhetstjeneste. | Ferdig (fase 2 og 3) |
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
| **Ferdig når** | Hver vedtatte operasjon står i `ApiOperationCatalog`, kan slås på av System Owner, viser opphav og er dekket av tester. Ingen C-funksjon er berørt. Idempotens: ikke bygget, akseptert per operasjon (funn 23). |
| **Status** | AVF-04 og AVF-05 levert lokalt 2026-10-24 (`external-api-phase-1-decisions.md` §22). |

### Fase 5 – Tilgang på vegne av en person

| | |
|---|---|
| **Mål** | Personlige dashboards og «Mine oppgaver» viser nøyaktig det personen selv ser. |
| **Moduler** | Felles (FEL-04, eventuelt FEL-05), statusoppdatering på egne tiltak (LDG-06) |
| **Leveranser** | Delegert modus (scopes, kobling via `user_identities`) og `GET /api/v1/my-tasks` |
| **Avhengigheter** | Beslutning om å oppheve D2. Fase 1. |
| **Kompleksitet** | Middels–høy |
| **Ferdig når** | Et delegert token ser det samme som personen i webgrensesnittet. Sperren for menneskelige beslutninger gjelder fortsatt (B2), og tester viser det. |
| **Status** | 🟥 Ikke startet. Kontrollert 2026-10-24: alle tre funksjonene er B-funksjoner som bare gir mening i delegert modus, og D2 er ikke opphevet. Det finnes ingen uavhengig del som kan bygges først (funn 27). |

### Fase 6 – Wiki gjennom den felles kunnskapskjernen

| | |
|---|---|
| **Mål** | Agenter kan lese gjeldende Wiki-kunnskap, stille spørsmål og overføre kunnskap via vanlig menneskelig godkjenning. |
| **Moduler** | Enterprise Wiki, og kunnskapsoverføring fra alle styringsmodulene |
| **Leveranser** | En lesetjeneste for sider uten skriving ved lesing (WIK-01, WIK-02). «Spør Wiki» etter at A23 er løst (WIK-03). Kunnskapsoverføring som navngitt operasjon (WIK-05 og tilsvarende i modulene). Erstatning for D11 med en eksplisitt liste over tillatte AI-operasjoner og streng kontekstkontroll. |
| **Avhengigheter** | Arkitekturbeslutning etter Wiki-sjekklisten. A23. Beslutning om AI-kostnad (S4). Ingen vektorindeks hos Siggjo (K13). |
| **Kompleksitet** | Høy |
| **Ferdig når** | Wiki-sjekklisten er gjennomgått. Bare gjeldende innhold leveres, «utilstrekkelig grunnlag» er et gyldig svar, og AI-bruk via API-et er sporbar og begrenset per kunde. |
| **Status** | 🟧 Levert lokalt 2026-10-24 etter D18–D22: WIK-01–03 (funn 30). Kunnskapsoverføring (WIK-05 og tilsvarende i modulene) er utsatt (D22). Ikke produksjonsklar. Se `external-api-phase-1-decisions.md` §23. |

### Fase 7 – Anbud

| | |
|---|---|
| **Mål** | Lesing av anbudsstatus, krav og frister for integrasjoner. Eventuelt kravarbeid senere. |
| **Moduler** | Anbud |
| **Leveranser** | Retting av K16 (kundeavgrensede arbeidsflytfelt). `isBidManager()` og tilsvarende sjekker nekter integrasjoner. Beslutning om avdelingsomfang. Ny lesevei i `SavedNoticeAccessService`. Saksarbeid flyttet fra kontrollerne til tjenester. Beslutning om A46 og egen nøkkel for Anbud-AI. |
| **Avhengigheter** | Produkt- og arkitekturbeslutninger over |
| **Kompleksitet** | Høy |
| **Ferdig når** | ANB-01–03 er tilgjengelige med tester for avdelinger og kundeseparasjon, og alle hindre i «Anbud» under funn er lukket. |
| **Status** | 🟥 Ikke startet. Kontrollert 2026-10-24: ANB-01–09 er B-funksjoner uten beslutning, ANB-10 er ikke anbefalt, og ANB-11–12 er C. Ingen kan bygges med dagens arkitektur (funn 28). |

### Fase 8 – Kopi av data og agentlag

| | |
|---|---|
| **Mål** | Virksomhetsdashboards med kopi av data hos Siggjo (hvis valgt), og et MCP-lag for agenter. |
| **Moduler** | Alle som har lese-API |
| **Leveranser** | Ved valg av alternativ B: tilgangsprojeksjon, endringslogg og id-endepunkter for avstemming. Et tynt MCP-lag over eksisterende endepunkter. |
| **Avhengigheter** | D7 (alternativ B), D14 (DPIA), databehandleravtaler |
| **Kompleksitet** | Høy |
| **Ferdig når** | Rettighetsendringer og slettinger når Siggjo innen avtalt frist. Hvert MCP-verktøy svarer til ett API-endepunkt, og ingen verktøy tar beslutninger. |
| **Status** | 🟥 Ikke startet. Kontrollert 2026-10-24: D7 valgte øyeblikksbilde uten tilgangsprojeksjon, så leveransene for alternativ B er ikke bestilt. MCP-laget er ikke besluttet som leveranse (funn 29). |

---

## Del 5 – Funn fra denne gjennomgangen

Kontrollert i koden 2026-10-24. Funn 1, 13 og 17 er løst (se radene). Funn 13–16 kom fra fase 2, funn 17–22 fra fase 3, funn 23–26 fra fase 4, funn 27 fra fase 5, funn 28–29 fra kontrollen av fase 7 og 8, og funn 30 fra fase 6.

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
| 13 | **RIS-02: Kvalitet-koblingene er ikke levert.** **Løst:** kontroller og prosesser er levert i fase 3, og aktivitetene navngis fra den godkjente flyten etter D15 (funn 17). Risikoens kontroller og prosesskontekst er Kvalitets data. Kvalitet er ikke en modul API-et betjener (`ApiRouteContractTest`), feltvalget for Kvalitet er ikke vurdert, og KVA-02 (godkjent versjon eller arbeidsversjon) er ikke besluttet. Fase 2 skulle ikke endre Kvalitet. | RIS-02 er delvis levert. Koblingene kan leveres sammen med MOK-06 når Kvalitet kan leses (fase 3). | Fase 3 |
| 14 | **Presentasjonen er ikke delt med webgrensesnittet.** Fase 2 forutsatte at presentasjonen ble trukket ut av webkontrollerne. API-presentasjonsklassene bruker de samme domenetjenestene som websidene (tilgang, oppmerksomhet, vurdering, tiltak, aksept, verifisering, målinger), men webkontrollerne er ikke endret. Oppgaven sa at ordinære brukeres funksjonalitet ikke skulle endres og at omfattende refaktorering ikke skulle gjøres. | Feltutvalget kan gli fra webgrensesnittet, men reglene kan ikke det. Funn 10 står fortsatt. | Senere opprydding |
| 15 | **`/me` har ingen egen linje for forbedringer.** `deviations` i `/me` og Kundemiljø → Integrasjoner gjelder både `/deviations` og `/improvements`: samme modul og samme rettighet. Linjen er ikke endret, så Kundemiljø er uendret. | Kan forveksles. Beskrevet i spesifikasjonen. | Dokumentasjon |
| 16 | **Tre svar er ikke paginert:** `GET /attention`, `GET /business-areas` og `GET /kpis/{id}/measurements` returnerer hele listen. De er avgrenset av det aktøren kan se, og målehistorikken av én KPI. | Store kunder kan få store svar fra `/attention`. | Følges med i pilot |
| 17 | **KVA-02: besluttet 2026-10-24 (D15) – bare godkjent prosessflyt. Løst.** Den godkjente flyten er den nyeste revisjonen (`latestRevision()`) for en prosess som ikke er utgått. RIS-02 og MOK-06 navngir aktivitetene fra den. KVA-02 er bygget som `GET /quality/items/{itemId}`. `controls_without_activity` er utelatt for godt (D17). Arbeidsversjonen, aktivitetsnavn som ikke er godkjent, og `has_unpublished_changes` er aldri med. Det kontrolleres i `ApiQualityReadTest`. | Ingen restpunkter. Se `external-api-phase-1-decisions.md` §21. | – |
| 18 | **Kvalitet har fortsatt ingen lesetjeneste i `QualityAccessService`.** Fase 3-planen forutsatte `visible*`/`findVisible*` der. API-et leser kundens elementer direkte under Kvalitets egen leseregel (`QualityProcessContextReader::canRead`, som brukes av alle moduler som leser Kvalitet). Kvalitet har ingen fagområder, så resultatet er det samme. | Ingen ny tilgangsmekanisme. En samlet lesetjeneste kan komme med KVA-02. | Senere opprydding |
| 19 | **Ledelsens gjennomgang: grunnlagstallene og beslutningstekstene er utelatt.** Grunnlaget er andre modulers data og kan leses gjennom deres egne endepunkter. **Besluttet 2026-10-24 (D16):** beslutnings- og tiltakstekster eksponeres ikke før innholdet er vurdert som egnet. API-et oppfyller dette allerede. | Ingen endring nå. Kan tas opp igjen sammen med DPIA (D14). | – |
| 20 | **Etterlevelse: oppmerksomhetstjenesten gir `not_assessed` for et bibliotekskrav med åpen relevans,** mens Etterlevelsesvurdering grupperer det som `relevance_open`. API-et speiler begge tjenestene uendret: `attention` følger `ComplianceAttentionService`, og `compliance.group` følger `ComplianceAssessmentOverview`. | Kan virke motstridende for klienten. Beskrevet i spesifikasjonen. | Dokumentasjon |
| 21 | **Kundemiljø → Integrasjoner viser fire nye linjer, og Avvik-linjen heter nå «Avvik og forbedringer».** Linjene kommer fra samme oppsummering som `/me`. Moduler uten fagområder vises som «hele kundemiljøet» og har `business_areas: null` i `/me`. | Synlig endring for System Owner, i tråd med hva integrasjonen faktisk kan lese. | – |
| 22 | **Organisasjonsnummer er med i leverandørregisteret.** Det er et offentlig identifikator-felt, men for enkeltpersonforetak kan det peke på en person. | Tas med i DPIA (D14). | Personvern |
| 23 | **AVF-04 og AVF-05 er ikke idempotente.** Fase 4-planen satte idempotens som fellesforutsetning. Oppdraget for fase 4 sa «ingen nye generelle mekanismer eller databaser», og en idempotensnøkkel krever en ny tabell. Operasjonene følger derfor samme vedtak som risikotiltak (§20): et gjentatt kall gir en ny sak eller et nytt tiltak. | Klienter må ikke prøve skrivekall på nytt automatisk. Må avgjøres av produkteier før flere skriveoperasjoner, eller før bruksmønsteret gjør duplikater til et problem. | Fase 4 |
| 24 | **Opphavet vises bare i Avvik og forbedringer.** Saksiden viser «Registrert av: Integrasjonen …» for en sak, og ved tiltak som en integrasjon har registrert. Navnet leses fra tildelingen (`ApiClientCustomerGrant::clientNamesFor`), som risikosiden nå også bruker. Statushistorikk, varsler og andre moduler som viser saken (for eksempel Ledelsens gjennomgang) viser fortsatt ingen avsender. | Ingen feil, fordi integrasjonen bare registrerer. Andre steder må vurderes hvis integrasjoner får flere operasjoner. | Fase 4 |
| 25 | **Varsler følger webgrensesnittets regel.** Den ansvarlige får det vanlige varselet om tildeling (`AssignmentObserver`), med integrasjonen som `assigned_by_user_id`, akkurat som for risikotiltak. Det er ikke laget en egen varselregel for integrasjoner (S6). | System Owner bestemmer hvem som kan få varsler fra en integrasjon gjennom hvilke fagområder den kan lese. | Fase 4 |
| 26 | **Beskrivelsen er fritekst som skrives, men aldri leses tilbake.** Domeneregelen krever beskrivelse på en sak. API-et tar den imot og lagrer den, men svarene inneholder den ikke (D8). | Integrasjonen kan ikke kontrollere hva den skrev. Tas med i DPIA (D14), fordi avvik kan inneholde særlige kategorier av personopplysninger. | Personvern |
| 27 | **Fase 5 er blokkert i sin helhet av D2.** FEL-04 (Mine oppgaver), FEL-05 (varsler) og LDG-06 (statusoppdatering på egne tiltak) gjelder en bestemt person og har ingen mening for en integrasjon i applikasjonsmodus. Delegert modus krever i tillegg en ny innloggingsvei (delegerte scopes i Entra, kobling `oid` → `user_identities`, on-behalf-of hos Siggjo), som er både en produktbeslutning og en ny generell sikkerhetsmekanisme. Ingen av delene er bygget, og `EntraAccessTokenValidator` avviser fortsatt delegerte tokens med 403. | Ingen kode er endret i fase 5. Fasen kan startes når produkteier har opphevet D2 og tatt stilling til FEL-05 og LDG-06 (avhengighet 14 i del 1). Verifisering krever også Entra-registreringen fra fase 1. | Fase 5 |
| 28 | **Fase 7 er blokkert i sin helhet.** Ingen av Anbud-funksjonene er godkjent: ANB-01–09 er B-funksjoner, ANB-10 er ikke anbefalt, og ANB-11 og ANB-12 er C. Hindrene står fortsatt i koden: K16 (`internal_status`, `decision_by_user_id` og `assigned_to_user_id` ligger på den globale `Notice`), `User::isBidManager()` faller tilbake til `contributor` og sperrer ikke integrasjoner (funn 2), `SavedNoticeAccessService::applyVisibility` gir ingen treff for en integrasjon og har ingen `findVisible*`, og avdelingsomfanget for en integrasjon er ikke besluttet. Selv lesing (ANB-01–03) krever en ny lesevei og en beslutning om avdelinger. Retting av K16 er en datamodellendring med migrering av delte felt. | Ingen kode er endret i fase 7. Fasen kan startes når produkteier har besluttet avdelingsomfang og A46, og arkitekturen har godkjent rettingen av K16 og sperren i `isBidManager()` (avhengighet 10 i del 1). | Fase 7 |
| 29 | **Fase 8 er blokkert i sin helhet.** Leveransene for kopi av data gjelder bare alternativ B, og D7 valgte øyeblikksbilde med kundebestemt publikum. Dashboards kan allerede hente øyeblikksbildet gjennom dagens leseendepunkter. MCP-laget er beskrevet i arkitekturen (§10.4), men er ikke besluttet som leveranse, og det vil være et nytt rammeverk. D14 (DPIA) gjelder før ekte kundedata går til Siggjo. | Ingen kode er endret i fase 8. Fasen krever en beslutning om alternativ B eller om MCP-laget. | Fase 8 |
| 30 | **Fase 6: Wiki-kjernen var bygget for personer.** «Spør Wiki» grunnet i nyeste versjon, også upublisert. Sitatene pekte på sidens nåværende versjon, ikke den som ble lest. Tilgangen lå bare i kontrolleren (A23), og navigasjonskallet fikk kundekonteksten fra den innloggede brukeren. **Løst:** grunnlaget følger den som spør (publisert for integrasjoner), sitatene bruker katalogens versjon, tilgangen ligger i `askAs()`, og hele forløpet kjører i kundens kontekst. Kapasiteten håndheves på API-kanalen uansett modus, og en kunde uten definert kapasitet nektes (fail closed). | Websiden er uendret. En integrasjon kan få et annet svar enn en person (D18). | Fase 6 |
