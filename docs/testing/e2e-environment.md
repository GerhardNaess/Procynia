# Isolert E2E-miljø

Playwright-testene (`tests/e2e/`) kjører mot et eget miljø ved siden av utviklingsmiljøet. Miljøet har egen database, egen lagring, egen port og bygde frontendressurser. Utviklingsserveren, utviklingsdatabasen og Vite-serveren du bruker til daglig, berøres ikke.

Innført 2026-10-22, fordi ustabile tester (KTF-4) viste at siden under test ble lastet helt på nytt mens testene kjørte mot utviklingsmiljøet og Vite-serveren.

## Bruk

```bash
scripts/e2e-env.sh up            # bygger frontend, oppretter og migrerer procynia_e2e, starter e2e-app og e2e-web
npx playwright test tests/e2e/risk-access.spec.js
scripts/e2e-env.sh status        # containere, database, rolle og URL
scripts/e2e-env.sh down          # stopper og fjerner containerne; database og .e2e/storage beholdes
scripts/e2e-env.sh reset         # som down, og sletter i tillegg procynia_e2e og .e2e/storage
```

- `up --no-build` hopper over `npm run build`, når de bygde ressursene allerede er oppdatert. Etter endringer i `resources/js` eller `resources/css` må det bygges på nytt.
- PHP-endringer slår inn med en gang, siden koden er montert inn. Det gjelder ikke migreringer.
- Nye migreringer krever `scripts/e2e-env.sh up --no-build`, som kjører `migrate`.

## Slik er miljøet satt opp

| Del | E2E-miljøet | Utviklingsmiljøet (uendret) |
|---|---|---|
| Containere | `procynia-e2e-app` (PHP-FPM) og `procynia-e2e-web` (nginx), Compose-profilen `e2e` | `procynia-app`, `procynia-web` |
| URL | `http://127.0.0.1:8091` | `http://localhost:8080` |
| Database | `procynia_e2e` | `procynia` |
| Databaserolle | `procynia_test_user`, som ikke har CONNECT på utviklingsdatabasen | utviklerens rolle |
| Frontend | Bygde ressurser i `public/build` (`VITE_HOT_FILE` peker på en fil som aldri finnes) | Vite-serveren (`public/hot`) |
| Lagring | `.e2e/storage` (ikke sporet i git) | `storage/` |
| Cache, sesjoner og kø | Tabeller i `procynia_e2e`. Ingen køarbeider kjører. | Redis |
| Redis | Egne databaser (7 og 8), brukes ikke av standardoppsettet | 0 |
| AI | `OPENAI_API_KEY` er tom | utviklerens nøkkel |
| E-post | `log` | utviklerens oppsett |
| Logger | `.e2e/storage/logs/laravel.log` og container-loggene til E2E-containerne | `storage/logs`, containerloggene til utviklingsmiljøet |

- **nginx:** E2E-nginx bruker den samme konfigurasjonen som utviklingsmiljøet (`docker/nginx`), uendret. Den står bare på nettverket `e2e`, der aliaset `app` er E2E-PHP-containeren.
- **PHP-oppsett:** E2E-PHP leser `.env.testing`, som gir testrollen og passordet. Deretter overstyres det som en HTTP-server skal gjøre annerledes, i `docker-compose.yml`, tjenesten `e2e-app`. Utviklerens `.env` leses fortsatt, men alt som avgjør database, sesjon, cache, kø og ressurser, er overstyrt i containeren.
- **Testdatabasen:** `procynia_test` er fortsatt PHPUnit sin. E2E har en egen database, slik at `migrate:fresh` i PHPUnit-suiten aldri tømmer data midt i en E2E-kjøring. E2E-data havner heller aldri i PHPUnit sine tester.

## Sikringer

1. **Databasen:** E2E-rollen har ikke CONNECT på utviklingsdatabasen. `scripts/e2e-env.sh up` kontrollerer dette for databasen i `.env` (`DB_DATABASE`) og stopper hvis rollen har tilgang. Produksjonsdatabasen kan ikke nås fra utviklingsmaskinen.
2. **Playwright nekter å starte** (`tests/e2e/global-setup.js`) dersom:
   - E2E-containerne ikke kjører;
   - `baseURL` ikke er porten E2E-nginx publiserer;
   - E2E-appen ikke står på `procynia_e2e` med `APP_ENV=e2e`;
   - siden under test laster fra en Vite-server i stedet for de bygde ressursene.
3. **Ingen vei rundt:** Den gamle reserveløsningen, som kjørte `php artisan db:seed` på verten, er fjernet. Den leste `.env` og fylte utviklingsdatabasen.
4. **Ett kommandopunkt:** Alle fikstur-, seed- og tinker-kall går gjennom `tests/e2e/helpers/environment.js` (`artisanCommand`) til `e2e-app`. Ingen spesifikasjon kaller utviklingscontaineren direkte.
5. **Produksjon er uberørt.** `config('app.vite_hot_file')` (`VITE_HOT_FILE`) er ubrukt utenfor E2E-miljøet. `docker-compose.prod.yml` og Azure-oppsettet er ikke endret.

## Feilsøking og bevaring av spor

Uendret fra før (se `known-test-failures.md`, «Bevaring av spor»):

- Hver kjøring skriver til `test-results/run-<tid>/`, og bare tester som feiler, beholder utdata.
- `tests/e2e/global-teardown.js` legger `server-logs.txt` ved hver test som feilet. Filen inneholder loggene fra E2E-containerne `procynia-e2e-web` og `procynia-e2e-app`, og nå også E2E-serverens `laravel.log` for samme tidsrom.

## Data og fiksturer

- `E2ETestSeeder` lager E2E-kunden, brukerne, pakkene og faste Kvalitet-prosesser. Den lager nå også en fast kontroll (`E2E-KONTROLL`).
  - Uten kontrollen hoppet `risk-controls.spec.js` og Risiko-reisen over steget («No control in Kvalitet in this environment»). Tidligere fant de en kontroll blant dataene utviklingsdatabasen hadde samlet opp.
- Den første kjøringen i en database som er migrert fra bunnen, avdekket A17 (`external-api-phase-0-plan.md`): rekkefølgen kaskadene utløses i, gjorde at oppryddingen i `ComplianceE2EFixture` feilet.
  - Fiksturen sletter nå koblingene eksplisitt først.
  - Rettingen i modulen hører til Etterlevelse (steg 13).

## Verifisert 2026-10-22

- Spesifikasjonene for Risiko (alle), Ledelsens gjennomgang, Etterlevelse og Wiki-godkjenning er kjørt i miljøet. Utvalgte spesifikasjoner er gjentatt flere ganger. Se `known-test-failures.md`, KTF-4.
- Bare E2E-serveren fikk trafikk fra kjøringene. Utviklingsserveren fikk ingen.
- Data havnet bare i `procynia_e2e`.

## Begrensninger

- **Køjobber kjører ikke.** En spesifikasjon som trenger en jobb fullført (for eksempel en ekte Wiki-kjøring), må bruke en fikstur. Tidligere kjørte E2E mot utviklingsmiljøets køarbeidere, med AI.
- **Ny database etter `reset`:** `procynia_e2e` starter tom. `E2ETestSeeder` lager brukerne, og spesifikasjonene lager resten selv.
- **Én kjøring om gangen:** Playwright kjører med én arbeider (`workers: 1`), og miljøet er ment for én Playwright-kjøring om gangen.
