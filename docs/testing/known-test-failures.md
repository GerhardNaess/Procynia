# Kjente testfeil

Tester som feiler på grunnlinjen og ikke skyldes arbeidet som pågår. Hver feil er en egen oppgave. De rettes ikke som del av annet arbeid, og hele testsuiten skal være grønn før merge til `main`.

## Status

| Id | Status | Rettet i |
|---|---|---|
| KTF-1 | **Løst 2026-10-22.** Testfiksturen er rettet, applikasjonen er uendret. | `898a4c08` |
| KTF-2 | **Løst 2026-10-22.** Testene kontrollerer nå dagens komponenter og den faktiske navigasjonsfunksjonen. | `2d133715` |
| KTF-3 | **Løst 2026-10-22.** Testen følger dagens Kjøringer-fane. Grensesnittet er uendret. | KTF-3-commit |

Ingen kjente feil står åpne. Dokumentet beholdes som logg, og nye kjente feil føres inn her.

## KTF-1 – Kjøringer-fanen i `WikiControllerTest` (9 tester) — løst

**Registrert:** 2026-10-22 på `feature/external-api-foundation`.
**Bevist eksisterende fra før:** feiler også på `df83ffc4`, før all fase 0-kode for eksternt API.

### Tester som feiler

Alle ligger i `tests/Feature/App/WikiControllerTest.php`:

- `test_index_tab_runs_returns_runs_prop`
- `test_index_tab_runs_is_scoped_to_current_customer`
- `test_runs_tab_returns_runs_for_customer`
- `test_runs_tab_filter_by_status`
- `test_runs_tab_filter_by_decision_none`
- `test_runs_tab_filter_by_source_document`
- `test_runs_tab_invalid_filters_are_ignored`
- `test_runs_tab_row_for_non_escalated_run_still_gets_a_findings_explanation`
- `test_runs_tab_can_cancel_false_for_terminal_run`

### Årsak

Testhjelperen `createIngestRun()` oppretter kjøringer med status `completed`, men uten `started_at`, `finished_at`, `maintainer_decision_status`, feilmelding eller sider.

Commit `d3a6432b` (*fix(wiki): simplify run follow-up and resolve stale QA failures*) innførte en regel i `WikiController`, i spørringen for Kjøringer-listen («aldri kjørt»). Den skjuler nettopp slike kjøringer, fordi de har ingenting å vise eller følge opp. Kjøringene #27–#29 i dev-databasen var satt inn på den måten.

Testene forventer at kjøringene vises, og feiler derfor.

**Applikasjonen oppfører seg slik den skal. Det er testfiksturen som beskriver en tilstand pipelinen aldri lager**, siden pipelinen alltid setter `started_at`.

### Retting (`898a4c08`)

1. `createIngestRun()` setter `started_at` når kjøringen har forlatt køen, og `finished_at` for sluttstatusene, slik pipelinen og `EnterpriseWikiRunTerminalTransition` gjør.
2. Regelen om at en kjøring som «aldri kjørte» holdes utenfor listen, er uendret og fortsatt dekket av `WikiRunDismissalTest::test_a_run_recorded_as_completed_that_never_ran_is_left_out_of_the_list`.
3. `WikiControllerTest`: 252 av 252 bestått.

### Avgrensning

Disse testene skal ikke endres som del av fase 0-arbeidet for eksternt API. Se [`docs/external-api-phase-0-plan.md`](../external-api-phase-0-plan.md).

## KTF-2 – kildekodetester for Wiki-navigasjon og kildedokumentrader (9 tester) — løst

**Registrert:** 2026-10-22, i en full kjøring av Wiki-suiten (2913 bestått, 18 feilet: KTF-1 og KTF-2).
**Bevist eksisterende fra før:** testene leser bare kildefiler. Markørene de leter etter, manglet allerede på `235f908d`, før fase 0. `CustomerAppLayout.jsx` er ikke endret i fase 0.

### Tester som feiler

- `tests/Feature/App/Wiki/WikiSecondaryNavigationOrderTest.php` (3 tester). Den leter etter blokken `if (activeMainArea === 'wiki') {` i `resources/js/Layouts/CustomerAppLayout.jsx`. Blokken forsvant da sidemenyen ble samlet (`6b0f13cb`).
- `tests/Feature/App/Wiki/WikiSourceRowLayoutTest.php` (6 tester). Den leter etter `sourceOwnerLabel}` og eldre radstruktur i `resources/js/Pages/App/Wiki/Index.jsx`. Strukturen ble endret da kildedokumentradene ble forenklet (`086ad35e`): eier i dialog og handlinger i ⋯-panel.

### Årsak

Testene sjekker JSX-tekst, ikke oppførsel. Komponentene er skrevet om, men testene ble ikke oppdatert. Oppførselen de beskyttet, er dekket på nytt i `a11yPhase5.test.js` (`9974fa8e`) og E2E (`a11y-wiki.spec.js`). For navigasjonen dekkes den av navigasjonsmatrisen.

### Retting (`2d133715`)

Ingen kontroll er fjernet. Hver påstand er flyttet til den formen funksjonen har i dag:

- **`WikiSourceRowLayoutTest`** kontrollerer komponentene `SourceOwner`, `SourceRowActions`, `IngestStatusBadge` og eierdialogen:
  - Eieren vises som navn. Blyanten har tilgjengelig navn, sier hvilket dokument den gjelder, og ikonet er dekorativt.
  - Eier lagres eksplisitt fra en merket dialog med opptatt- og feiltilstand, til samme endepunkt. Valg i listen lagrer ingenting.
  - «Lag Wiki» er den synlige handlingen. Last ned og Slett ligger i ⋯-panelet.
  - Slett har destruktiv stil og krever forhåndsvisning og bekreftelse.
  - Raden lenker til Kjøringer bare når kjøringen krever oppfølging.
  - Mutasjonssjekket.
- **`WikiSecondaryNavigationOrderTest`** beholder serverens landingsfane. Rekkefølge, etiketter, oversatte etiketter, mål og aktivt punkt er flyttet til `headerNavigation.test.js`, som kaller den faktiske navigasjonsfunksjonen i `appNavigation.js`.

## KTF-3 – E2E: «Se detaljer» viser ikke den rå feilmeldingen direkte (1 test) — løst

**Registrert:** 2026-10-22 under verifiseringen etter KTF-1 og KTF-2 (73 av 74 bestått i Wiki-E2E).

### Test som feiler

`tests/e2e/wiki-source-upload-readability.spec.js`, test 17: «a failed run is one short Norwegian sentence, and «Se detaljer» opens the run with the full error».

### Årsak (sannsynlig, ikke bevist med kjøring på eldre commit)

- Første halvdel består: raden viser én kort norsk setning, og «Se detaljer» er beskrevet av filnavnet.
- Den andre halvdelen venter at `RuntimeException: Page generation failed` er synlig rett etter navigeringen til Kjøringer. Kjøringer-fanen ble forenklet etter at testen ble skrevet (`b91e0bb9`, `d3a6432b`, 20.–21.10.). Den utvidede raden forsvant, og den rå feilen ligger bak «Vis tekniske detaljer» (`47fbc663`), slik prinsippet i AGENTS.md om tekniske detaljer krever.
- Ingen commit i fase 0 har endret hvordan Kjøringer-fanen vises.

### Retting

Årsaken er bekreftet: testen var utdatert, ikke grensesnittet. Kjøringer-fanen åpnes filtrert på dokumentet («Filtrert på dokument», «Fjern kildefilter»), og den rå feilen ligger tre nivåer inne. Testen går nå denne veien:

1. Raden i Kildedokumenter viser én kort norsk setning og ingen `RuntimeException`. «Se detaljer» er beskrevet av filnavnet.
2. Kjøringer viser én kjøring for dokumentet, og den rå feilen er ikke på skjermen.
3. «Vis detaljer» (beskrevet av filnavnet), «Vis flere detaljer» og «Vis tekniske detaljer» åpnes med tastaturet (Enter og mellomrom). Hver knapp skifter navn og `aria-expanded`. Fokus blir på knappen.
4. Den rå feilen vises nøyaktig slik pipelinen lagret den (`WikiSourceUploadReadabilityE2EFixture::FAILED_RUN_ERROR`), ikke forkortet.
5. «Skjul tekniske detaljer» skjuler den igjen.

`wiki-source-upload-readability.spec.js`: 13 av 13 bestått.

## Før merge til `main`

- ~~Rett KTF-1 og KTF-2.~~ Gjort.
- Kjør hele PHP-suiten (`docker exec procynia-app php artisan test`).
- Kjør JS-suiten (`node:22`-Docker).
- Kjør relevante E2E-spesifikasjoner.
- Nye feil som dukker opp, bevises som eksisterende fra før med worktree-metoden, eller rettes. Ingen feil står uforklart.
