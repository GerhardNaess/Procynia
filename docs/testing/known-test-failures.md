# Kjente testfeil

Tester som feiler på grunnlinjen og ikke skyldes arbeidet som pågår. Hver feil er en egen oppgave. De rettes ikke som del av annet arbeid, og hele testsuiten skal være grønn før merge til `main`.

## Status

| Id | Status | Rettet i |
|---|---|---|
| KTF-1 | **Løst 2026-10-22.** Testfiksturen er rettet, applikasjonen er uendret. | `898a4c08` |
| KTF-2 | **Løst 2026-10-22.** Testene kontrollerer nå dagens komponenter og den faktiske navigasjonsfunksjonen. | `2d133715` |
| KTF-3 | **Løst 2026-10-22.** Testen følger dagens Kjøringer-fane. Grensesnittet er uendret. | `be3f517c` (testen), denne loggen |
| KTF-4 | **Åpen – mulig ustabilitet.** `risk-access.spec.js` feilet én gang, ikke reprodusert. | – |
| KTF-5 | **Åpen – mulig problem i testinfrastrukturen.** Innloggingen i `a11y-grc-compliance.spec.js` hang i 15 sekunder, mens PHP-FPM-poolen var full. | – |

KTF-4 og KTF-5 står åpne som mulig ustabilitet i E2E. Ingen kjente feil står åpne i PHP eller JS. Dokumentet beholdes som logg, og nye kjente feil føres inn her.

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

## KTF-4 – E2E: `risk-access.spec.js` avbrutt av en navigering (mulig ustabilitet)

**Registrert:** 2026-10-22, i kjøringen av alle Risiko-spesifikasjonene etter `4c3cd3bf` (15 av 16 bestått).

### Hva som skjedde

Testen «a role with a fagområde lets its holder register and read risks there» feilet på linje 62:

```text
page.goto: Navigation to "…/app/customer-environment?tab=users" is interrupted by another navigation to "…/app/customer-environment?tab=permissions"
```

- Steget går fra Tilganger til Brukere, rett etter at rolle nummer to er lagret og et skjermbilde av hele siden er tatt.
- Ingen Risiko-handling er utført på dette tidspunktet. Feilen har derfor ikke noe med endringene i steg 9 å gjøre.

### Undersøkt

| Hypotese | Resultat |
|---|---|
| Spesifikasjonen er ustabil alene | 3 av 3 og deretter 15 av 15 bestått med den opprinnelige spesifikasjonen, også med spor ved feil. Den besto også i den første store E2E-kjøringen. |
| Serveren sender `?tab=users` videre til `permissions` | Nei. `CustomerEnvironmentController::index` sender ingen videre, og rolle-lagringen setter ingen sesjonsverdi. |
| Inertia lagrer rulleposisjonen med en forsinket `history.replaceState` (100 ms), for eksempel etter skjermbildet, og avbryter `goto` | **Avkreftet.** Ingen avbrudd i 30 forsøk: skjermbilde av hele siden, rulling og en direkte `replaceState` 30 ms inn i `goto`. Playwright regner ikke `replaceState` som en navigering. |
| Bakgrunnsforespørsler (polling, utsatte props, forhåndshenting) på siden | Ingen finnes i Kundemiljø eller i oppsettet. |

Den gjenstående muligheten er en sen Inertia-navigering til `?tab=permissions`, det vil si svaret på en rolle-lagring som kom etter at kontrollene hadde passert, for eksempel en dobbel innsending under høy last. Det er ikke bevist.

### Tiltak

- Spesifikasjonen er **ikke** endret. Årsaken er ukjent, og en endring kunne skjule den uten å rette noe.
- `playwright.config.cjs` hadde `trace: 'on-first-retry'` med `retries: 0`, og tok dermed aldri spor. Den har nå `trace: 'retain-on-failure'`, slik at neste feil etterlater et spor som viser hvilken forespørsel som navigerte. Se «Bevaring av spor» nedenfor.
- Feiler testen igjen, leses sporet. Er årsaken en dobbel lagring, er det en feil i grensesnittet og ikke i testen.
- En ny kjøring tømte tidligere `test-results/`, og sporet etter KTF-5 gikk tapt på den måten. Det er rettet. Se «Bevaring av spor».

## KTF-5 – E2E: innlogging hang mens PHP-FPM-poolen var full (mulig problem i testinfrastrukturen)

**Registrert:** 2026-10-22, i kjøringen av E2E-spesifikasjonene for Avvik og Etterlevelse etter `9059f765` (60 av 61 bestått).

### Hva som skjedde

- `a11y-grc-compliance.spec.js`, «Kravbibliotek: headings follow the tree, and the tree speaks its hits», feilet i `loginAs`. Siden ble stående på `/login` i 15 sekunder.
- Ingen avviks- eller etterlevelsesfunksjon var tatt i bruk da feilen kom. Spesifikasjonen besto 3 av 3 ved ny kjøring.
- Sporet gikk tapt fordi den nye kjøringen tømte `test-results`.

### Funn i serverloggene (container-loggene til nginx og PHP-FPM)

| Tid (UTC) | Hendelse |
|---|---|
| 14:25:25 | PHP-FPM: `WARNING: [pool www] server reached pm.max_children setting (5), consider raising it` |
| 14:26:20–22 | De forrige innloggingene i samme kjøring fikk 302 på under ett sekund. |
| 14:26:23 | Uptime Kuma traff fem helsesjekker for køer (`/ops/health/queues/*`) samtidig. Alle fikk 503, mens E2E-trafikken pågikk. |
| 14:26:36 | `POST /login` fikk **499** (nettleseren lukket forbindelsen før svaret kom). Dette er den eneste av 411 innlogginger i perioden som ikke fikk 302. |

- **Mulig årsak, ikke bevist:** PHP-FPM har bare fem arbeidere (`pm.max_children = 5`). Store Inertia-sider på omtrent 625 kB fra Etterlevelse, sammen med helsesjekkene, fylte poolen, og innloggingen ble stående i kø til Playwright ga opp etter 15 sekunder.
- Poolen ble også full sju andre ganger de siste tre døgnene. Hver gang er det logget som én advarsel, uten varighet. Det finnes ingen langsom-logg som viser hvilke forespørsler som opptok arbeiderne.

### Sammenheng med KTF-4

Trolig ingen.

- KTF-4 (`risk-access.spec.js`) har ingen 499 og ingen full pool i sitt tidsvindu. Det er ingen 499 mellom 12:15 og 14:26 UTC.
- Feiltypen er også en annen: en avbrutt navigering, ikke et svar som uteble.
- Begge oppstod i lange kjøringer på delt utviklingsmiljø, men det er ikke funnet noen felles årsak.

### Tiltak

- **Ingen endring i PHP-FPM-oppsettet eller i testene.** Årsaken er ikke dokumentert. Å heve `pm.max_children` eller slå av helsesjekkene under E2E vil være naturlige forsøk dersom feilen kommer igjen med samme signatur.
- Spor og serverlogger tas nå vare på automatisk (se nedenfor). Neste forekomst kan dermed avgjøre saken.

## Bevaring av spor (fra 2026-10-22)

- Hver kjøring skriver til sin egen mappe, `test-results/run-<tidspunkt>/` (`outputDir` i `playwright.config.cjs`). En senere kjøring tømmer derfor aldri spor fra en tidligere.
- `preserveOutput: 'failures-only'` beholder bare utdata fra tester som feilet: `trace.zip`, `error-context.md` og skjermbilder.
- `tests/e2e/global-teardown.js` skriver `server-logs.txt` i mappen til hver test som feilet. Filen inneholder container-loggene fra nginx og PHP-FPM, med standard ut og feil, fra tre minutter før testen til rett etter den.
  - Den kjører uansett hvilken `--reporter` som er valgt. En egen reporter ville ikke gjort det, fordi `--reporter` på kommandolinjen erstatter de konfigurerte.
  - Den feiler aldri selve kjøringen.
- Mappene ligger i `test-results/`, som ikke spores av git. De slettes for hånd når feilen er undersøkt.
- Testet med en spesifikasjon som feilet med vilje: spor, feilkontekst og serverlogg ble beholdt, og den beståtte testen etterlot ingenting.
- `storage/logs/laravel.log` på verten ble tømt i løpet av 2026-10-22, utenfor dette arbeidet. Container-loggene er derfor den kilden som er brukt.

## Før merge til `main`

- ~~Rett KTF-1 og KTF-2.~~ Gjort. ~~KTF-3.~~ Gjort.
- Kjør hele PHP-suiten (`docker exec procynia-app php artisan test`).
- Kjør JS-suiten (`node:22`-Docker).
- Kjør relevante E2E-spesifikasjoner.
- Nye feil som dukker opp, bevises som eksisterende fra før med worktree-metoden, eller rettes. Ingen feil står uforklart.
