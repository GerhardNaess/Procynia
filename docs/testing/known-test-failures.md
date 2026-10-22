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

## Før merge til `main`

- Rett KTF-1.
- Kjør hele PHP-suiten (`docker exec procynia-app php artisan test`).
- Kjør JS-suiten (`node:22`-Docker).
- Kjør relevante E2E-spesifikasjoner.
- Nye feil som dukker opp, bevises som eksisterende fra før med worktree-metoden, eller rettes. Ingen feil står uforklart.
