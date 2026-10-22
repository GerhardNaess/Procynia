# Kjente testfeil

Tester som feiler på grunnlinjen og ikke skyldes arbeidet som pågår. Hver feil er en egen oppgave. De rettes ikke som del av annet arbeid, og hele testsuiten skal være grønn før merge til `main`.

## KTF-1 – Kjøringer-fanen i `WikiControllerTest` (9 tester)

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

### Foreslått retting (egen commit)

1. La `createIngestRun()` sette `started_at` og, for terminale statuser, `finished_at`, slik pipelinen gjør.
2. Behold én test som eksplisitt viser at en kjøring som «aldri kjørte», holdes utenfor listen. Den er dekket fra før i Kjøringer-testene fra `d3a6432b`, men dekningen bør kontrolleres.
3. Kjør `WikiControllerTest` i sin helhet.

### Avgrensning

Disse testene skal ikke endres som del av fase 0-arbeidet for eksternt API. Se [`docs/external-api-phase-0-plan.md`](../external-api-phase-0-plan.md).

## KTF-2 – kildekodetester for Wiki-navigasjon og kildedokumentrader (9 tester)

**Registrert:** 2026-10-22, i en full kjøring av Wiki-suiten (2913 bestått, 18 feilet: KTF-1 og KTF-2).
**Bevist eksisterende fra før:** testene leser bare kildefiler. Markørene de leter etter, manglet allerede på `235f908d`, før fase 0. `CustomerAppLayout.jsx` er ikke endret i fase 0.

### Tester som feiler

- `tests/Feature/App/Wiki/WikiSecondaryNavigationOrderTest.php` (3 tester). Den leter etter blokken `if (activeMainArea === 'wiki') {` i `resources/js/Layouts/CustomerAppLayout.jsx`. Blokken forsvant da sidemenyen ble samlet (`6b0f13cb`).
- `tests/Feature/App/Wiki/WikiSourceRowLayoutTest.php` (6 tester). Den leter etter `sourceOwnerLabel}` og eldre radstruktur i `resources/js/Pages/App/Wiki/Index.jsx`. Strukturen ble endret da kildedokumentradene ble forenklet (`086ad35e`): eier i dialog og handlinger i ⋯-panel.

### Årsak

Testene sjekker JSX-tekst, ikke oppførsel. Komponentene er skrevet om, men testene ble ikke oppdatert. Oppførselen de beskyttet, er dekket på nytt i `a11yPhase5.test.js` (`9974fa8e`) og E2E (`a11y-wiki.spec.js`). For navigasjonen dekkes den av navigasjonsmatrisen.

### Foreslått retting (egen commit)

Skriv testene om til de nåværende strukturene. Eventuelt kan de erstattes av JS-testene og E2E-testene som allerede dekker det samme. Avklar først at ingen påstand går tapt.

## Før merge til `main`

- Rett KTF-1 og KTF-2.
- Kjør hele PHP-suiten (`docker exec procynia-app php artisan test`).
- Kjør JS-suiten (`node:22`-Docker).
- Kjør relevante E2E-spesifikasjoner.
- Nye feil som dukker opp, bevises som eksisterende fra før med worktree-metoden, eller rettes. Ingen feil står uforklart.
