# Procynia — avhengighetsaudit, oktober 2026

**Type:** kartlegging og utbedringsplan. Kartleggingen endret ingen pakker. Gjennomførte runder logges i §11.
**Dato:** 16. oktober 2026
**Commit:** `b4e14566` (`main`, lik `origin/main`)
**Forrige runde:** [`security-audit-2026-08.md`](security-audit-2026-08.md) — F-07 (Composer) og F-11 (npm) lukket
29.08.2026 med 0 advisories. Funnene under er publisert etter det.

Hvert punkt er merket **[Observert]** (kommandoutdata, kode eller advisory-tekst) eller **[Vurdering]**
(skjønn eller antakelse som ikke er verifisert mot kjørende miljø).

---

## 1. Sammendrag

| Prioritet | Antall | Funn |
|---|---|---|
| P0 | 0 | — |
| P1 | 1 | `symfony/http-foundation` — uautentisert ReDoS via `Cache-Control` på hver forespørsel |
| P2 | 1 | `league/commonmark` — to advisories, nås bare via systemgenererte e-poster |
| P3 | 7 | `filament/filament` (MFA er ikke i bruk), `laravel/framework` (kun ved `APP_DEBUG=true`), og alle fem npm-funn (kjører bare ved bygging eller i utvikling) |

Alle ni kan rettes med oppdateringer innenfor eksisterende versjonskrav i `composer.json` og
`package.json`. Ingen major-oppgradering trengs, og det er ingen pakker uten vedlikehold
(`abandoned: []`).

Anbefalt rekkefølge: **1)** `symfony/http-foundation` → **2)** `league/commonmark` + `laravel/framework`
→ **3)** `filament/filament` → **4)** npm-lockfila. Se §8.

---

## 2. Git- og CI-status

**[Observert]**

- Branch `main`, HEAD `b4e14566540df488ea31800970dbddffe8651d2d`, lik `origin/main`. Working tree var
  rent før denne rapporten ble skrevet.
- Workflow: `.github/workflows/dependency-audit.yml`, med utløsere `pull_request`, `push` til `main`
  og `schedule` kl. 05:30 UTC hver dag.
  - **Composer advisories:** `ubuntu-latest`, PHP 8.4 via setup-php (med bcmath, gd, intl og zip),
    `composer install --no-scripts` fra `composer.lock`, deretter `composer audit`.
  - **npm advisories:** Node 22, `npm ci --no-audit --no-fund`, deretter `npm run test:unit`,
    `npm run lint:a11y` og `npm audit`.
  - Ingen ignore-liste, terskel eller `continue-on-error`. Ethvert funn feiler bygget, slik policyen
    i workflowen sier.
- Siste kjøring jeg kunne lese, på `56c66f7f` (push til `main`, 16.10.2026 15:56 UTC):
  - Jobb «npm advisories» feilet. Steget `npm audit` feilet; enhetstester og a11y-lint besto.
  - Jobb «Composer advisories» feilet i steget `composer audit`.
  - De tidsstyrte kjøringene 15.10 og 16.10 (`bc9070c2`) feilet også.
- Kjøringen for `b4e14566`: **ikke verifisert.** `gh` er ikke installert. Det uautentiserte GitHub-API-et
  traff kvoten (60 kall per time) under polling etter merge, og kvoten nullstilles kl. 22:21. Lockfilene
  er identiske med `56c66f7f`, så **[Vurdering]** det ventede utfallet er de samme to audit-feilene.
- Lokalt, mot samme lockfiler, ga `npm audit` 5 funn og `composer audit` 5 advisories (exit 1 for
  begge). Detaljer i §3 og §4.
- `main` er ikke beskyttet: API-et rapporterer `protected: false`. Ingen kontroller er formelt påkrevd.

---

## 3. npm-funn

**[Observert]** `npm audit --json`: 5 sårbare pakker (2 critical, 2 high, 1 moderate) av 496 avhengigheter.

| Pakke | Installert | Severity | Advisory | Direkte? | Trukket inn av | Sikker versjon | Fiks innenfor dagens krav? |
|---|---|---|---|---|---|---|---|
| `shell-quote` | 1.9.0 | critical (CVSS 8.1) | [GHSA-pqg4-j6r4-53mv](https://github.com/advisories/GHSA-pqg4-j6r4-53mv) — kommando-injeksjon i `quote()` (CWE-78) | transitiv | `concurrently@9.2.4`, som låser `shell-quote@1.9.0` | ≥ 1.11.0 (siste 1.12.0) | Ja, via forelder (se `concurrently`) |
| `concurrently` | 9.2.4 | critical (arvet) | via `shell-quote` | **direkte**, devDependency `^9.0.1` | rotprosjektet | 9.2.5 (låser `shell-quote@1.12.0`) | Ja, minor innenfor `^9` |
| `browserslist` | 4.28.1 | high (CVSS 7.5) | [GHSA-c83g-rgw3-j3cx](https://github.com/advisories/GHSA-c83g-rgw3-j3cx) (ubegrenset minnevekst, CWE-770); [GHSA-73wf-gq98-2v4g](https://github.com/advisories/GHSA-73wf-gq98-2v4g) (krasj/prototype-skriving via `browserslist-stats.json`, CWE-1321) | transitiv | `@babel/helper-compilation-targets` ← `@babel/core` ← `@vitejs/plugin-react` | ≥ 4.28.7 (siste 4.29.3) | Ja, kravet er `^4.24.0` |
| `source-map-js` | 1.2.1 | high (CVSS 7.5) | [GHSA-68fv-2mgg-jv7q](https://github.com/advisories/GHSA-68fv-2mgg-jv7q) — event-loop-DoS via offsets i indekserte source maps (CWE-1284) | transitiv | `postcss@8.5.26` (Vite, Tailwind, autoprefixer), `@tailwindcss/node@4.2.2` | 1.2.2 | Ja, kravet er `^1.2.1` |
| `baseline-browser-mapping` | 2.10.10 | moderate | [GHSA-w5vr-8v7q-w6rv](https://github.com/advisories/GHSA-w5vr-8v7q-w6rv) — prosessen avsluttes på ugyldig input (CWE-705) | transitiv | `browserslist@4.28.1` | ≥ 2.11.0 (siste 2.11.29) | Ja, kravet er `^2.9.0` (`browserslist@4.29.x` krever `^2.11.26`) |

### Produksjon eller utvikling

**[Observert]**

- `npm audit --omit=dev` gir 3 funn: `browserslist`, `source-map-js` og `baseline-browser-mapping`.
  Grunnen er at `@vitejs/plugin-react` står under `dependencies` i `package.json`, så npm regner
  Babel-treet som «prod». `shell-quote` og `concurrently` regnes som rene dev-avhengigheter.
- Produksjonsbildet (`docker/production/Dockerfile`) bruker Node bare i byggesteget
  `FROM node:22-bookworm-slim AS frontend`, med `npm ci` og `npm run build`. Kjørebildet får bare
  `public/build` (`COPY --from=frontend /build/public/build ...`). Ingen `node_modules` og ingen
  Node-prosess følger med i produksjon.
- `concurrently` brukes bare av `composer dev`, til lokal utvikling.

**[Vurdering]** Ingen av de fem npm-pakkene kjører i produksjon, og ingen av dem behandler input fra
brukere eller internett. De leser prosjektets egne filer under bygging: kildekode, CSS, source maps
og Browserslist-konfigurasjon.

- `shell-quote` er det eneste funnet med kodekjøring som konsekvens. Det krever at en angriper styrer
  argumentene `concurrently` får via `composer dev`, og de er hardkodet i `composer.json`.

Alle fem er derfor **P3**. De er likevel verdt å rette raskt, fordi de holder CI rød og skjuler nye
funn, og fordi rettelsen bare endrer lockfila.

---

## 4. Composer-funn

**[Observert]** `composer audit --format=json`: 5 advisories i 4 pakker, ingen abandoned.

| Pakke | Installert | Severity | Advisory | Direkte? | Krevd av | Første sikre versjon | Innenfor dagens krav? |
|---|---|---|---|---|---|---|---|
| `symfony/http-foundation` | v8.1.5 | ikke oppgitt | [CVE-2026-108082](https://symfony.com/cve-2026-108082) (PKSA-xmwy-rmx5-m7h4) — ReDoS i `HeaderUtils::split()` via request-headeren `Cache-Control` | transitiv | `laravel/framework` (`^7.4.0 \|\| ^8.0.0`), `symfony/http-kernel`, `fruitcake/php-cors` | **8.1.9** (se merknad) | Ja |
| `league/commonmark` | 2.10.0 | high | [GHSA-3q6v-r5mr-hxv8](https://github.com/advisories/GHSA-3q6v-r5mr-hxv8) — kvadratisk DoS i GFM-tabelludvidelsen | transitiv | `laravel/framework` (`^2.8.1`) | 2.10.2 (siste 2.10.3) | Ja |
| `league/commonmark` | 2.10.0 | medium | [GHSA-97jj-33gv-5xf9](https://github.com/advisories/GHSA-97jj-33gv-5xf9) — omgåelse av `DisallowedRawHtml` | transitiv | som over | 2.10.2 | Ja |
| `filament/filament` | v5.7.6 | medium (CVSS 5.4) | [GHSA-7m6h-rg42-m449](https://github.com/advisories/GHSA-7m6h-rg42-m449) / CVE-2026-104181 — MFA-handlinger (app) krever ikke passord på nytt | **direkte** (`^5.4`) | rotprosjektet | **5.8.3** (se merknad) | Ja, minor innenfor `^5` |
| `laravel/framework` | v13.29.0 | low | [GHSA-jh5r-qr3c-85q8](https://github.com/advisories/GHSA-jh5r-qr3c-85q8) / CVE-2026-102279 — XSS i debug-siden | **direkte** (`^13.0`) | rotprosjektet | 13.30.0 (siste 13.37.0) | Ja |

**[Observert] Merknader til versjonene:**

- **Symfony:** `composer audit` oppgir `<8.1.7`. Advisory-teksten sier at 8.1.7 og 8.1.8 innførte en
  regresjon: `HeaderUtils::split('foo bar', ' ')` returnerte én verdi. Den korrigerte versjonen er
  **8.1.9**, og 8.1.9 finnes i registeret.
- **Filament:** `composer audit` oppgir `<5.8.2`. GitHub-advisoryen oppgir **5.8.3** som patchet versjon.
  Bruk 5.8.3 eller nyere.

Alle fem ligger i produksjonsinstallasjonen, siden produksjon kjører `composer install --no-dev` og
ingen av pakkene er dev-avhengigheter.

---

## 5. Risiko for Procynia

### P1 — `symfony/http-foundation`, CVE-2026-108082

**[Observert]**

- `vendor/symfony/http-foundation/HeaderBag.php:150` parser `Cache-Control` med en gang headeren settes
  (`parseCacheControl()`, som kaller `HeaderUtils::split($header, ',=')`). Dette skjer når Laravel
  bygger `Request`-objektet for hver HTTP-forespørsel, før ruting, middleware og autentisering.
- Det gjelder også uautentiserte ruter som `/login`, de offentlige sidene, helsesjekker og
  Stripe-webhooken.
- Advisoryen: én forespørsel med 17 påfølgende mellomrom koster om lag 813 ms CPU på PHP 8.5, og
  kostnaden dobles for hvert ekstra mellomrom. Fra 18 mellomrom treffes `pcre.backtrack_limit`, og
  `split()` returnerer en tom liste. Konsekvensen er tjenestenekt, ikke kodekjøring eller datalekkasje.
  Ingen workaround er oppgitt.
- Produksjons-nginx (`docker/production/nginx.conf`) setter ikke `large_client_header_buffers`.
  Standarden er 4 × 8 KB, og det stopper ikke en kort header med en slik mellomromsrekke.

**[Vurdering]**

- Appen er eksponert mot internett, angrepet krever verken innlogging eller kunnskap om appen, og
  hver forespørsel binder en PHP-FPM-arbeider. Noen få samtidige forespørsler kan tømme arbeidspoolen.
- Det er ikke P0, fordi konsekvensen er begrenset til tilgjengelighet. Hver forespørsel kan heller
  ikke ta lengre tid enn grensen backtrack-limit setter.
- Det er heller ikke P2: det trengs verken spesielle forutsetninger eller tilganger, og sårbar kode
  kjører garantert.
- Jeg har ikke verifisert hvilke grenser Azure Container Apps-ingressen setter.

**Prioritet P1:** rettes først, som egen liten endring.

### P2 — `league/commonmark`, to advisories

**[Observert]**

- Procynia kaller ikke CommonMark direkte. Det finnes ingen treff på `CommonMark`, `Str::markdown` eller
  `MarkdownConverter` i `app/`, `config/` eller `resources/views`.
- Wiki og AI-svar rendres i nettleseren med `react-markdown` og `remark-gfm`, ikke med denne pakken.
- Pakken nås via Laravels `Illuminate\Mail\Markdown`, som bruker `TableExtension` og
  `html_input => escape`. Det skjer i to e-postvarsler, `AiQuotaNotification` og
  `SubscriptionPaymentFailedNotification`, med innhold som systemet lager selv.
- Filament kaller `Str::markdown` på tekster utviklerne har skrevet (6 steder i vendor).

**[Vurdering]**

- DoS-en i tabellutvidelsen krever stor, konstruert Markdown-input. Ingen kjent vei lar en bruker
  sende slik input til CommonMark i dag.
- `DisallowedRawHtml`-omgåelsen gjelder GFM-konvertereren. Laravels e-poster bruker `html_input: escape`
  og ikke den utvidelsen.
- Høy severity, men lav faktisk eksponering, derfor P2 og ikke P1. Den blir viktigere hvis Procynia
  senere rendrer Markdown fra brukere på serveren, for eksempel i e-post med Wiki-innhold.

**Prioritet P2.**

### P3 — `filament/filament`, CVE-2026-104181

**[Observert]**

- Admin-panelet (`app/Providers/Filament/AdminPanelProvider.php`) og `User`-modellen konfigurerer ikke
  `multiFactorAuthentication`. Det er ingen treff på MFA-relaterte API-er.
- Advisoryen krever en innlogget sesjon og gjelder bare app-basert MFA.

**[Vurdering]** Den sårbare funksjonen er ikke slått på, og Filament-panelet er dessuten begrenset til
interne administratorer (F-03, august). Rettes for å holde versjonen oppdatert og CI grønn, men uten
hastverk. Hvis app-basert MFA aktiveres i Filament senere, må dette være rettet først.

**Prioritet P3.**

### P3 — `laravel/framework`, CVE-2026-102279

**[Observert]** Debug-siden vises bare med `APP_DEBUG=true`. Produksjon setter `APP_DEBUG=false` både i
`docker/production/Dockerfile` og i `infra/main.bicep`.

**[Vurdering]** Ikke utnyttbart i staging eller produksjon slik de er konfigurert. Lokale utviklingsmiljøer
er berørt.

**Prioritet P3.** Rettes sammen med CommonMark, fordi samme `composer update laravel/framework` tar begge.

### P3 — alle npm-funn

Se §3. Pakkene kjører bare under bygging og i lokal utvikling, og de behandler ikke input fra brukere.

---

## 6. Anbefalt utbedring per funn

| # | Funn | Tiltak | Kommando (forslag) | Endrer |
|---|---|---|---|---|
| 1 | `symfony/http-foundation` | Oppdater transitiv avhengighet direkte | `composer update symfony/http-foundation` til ≥ 8.1.9 | `composer.lock` |
| 2 | `league/commonmark` | Oppdater transitiv avhengighet | `composer update league/commonmark` til ≥ 2.10.2 | `composer.lock` |
| 3 | `laravel/framework` | Oppdater direkte avhengighet innenfor `^13.0` | `composer update laravel/framework` til ≥ 13.30.0. Forelderen tar også med CommonMark og http-foundation hvis de ikke er oppdatert før. | `composer.lock` |
| 4 | `filament/filament` | Oppdater direkte avhengighet innenfor `^5.4` | `composer update "filament/*"` til ≥ 5.8.3, deretter `php artisan filament:upgrade` | `composer.lock`, publiserte assets i `public/` (37 sporede Filament-filer) |
| 5 | `concurrently` / `shell-quote` | Oppdater direkte dev-avhengighet | `npm update concurrently` til 9.2.5 (låser `shell-quote@1.12.0`) | `package-lock.json` |
| 6 | `browserslist`, `baseline-browser-mapping` | Oppdater transitive avhengigheter innenfor eksisterende krav | `npm update browserslist baseline-browser-mapping` | `package-lock.json` |
| 7 | `source-map-js` | Oppdater transitiv avhengighet | `npm update source-map-js` til 1.2.2 | `package-lock.json` |

**Ikke anbefalt:**

- `npm audit fix --force`: kan gjøre major-oppgraderinger som ingen av funnene trenger.
- En global `composer update`: drar med alle pakker, mens fire målrettede oppdateringer holder.
- Ignore-lister eller lempeligere audit-kontroller.

Ingen funn krever major-oppgradering, ny avhengighet eller midlertidige risikotiltak, siden sikre
versjoner finnes for alle.

**[Vurdering] Mens funn 1 venter:** en grense i nginx gir ikke reell beskyttelse, fordi angrepet bare
trenger et kort mellomromsmønster. Ventetiden bør derfor holdes kort.

---

## 7. Kompatibilitet

**[Observert]** Versjonsspennene under er sjekket mot registeret. Endringslogger er **ikke** lest i detalj.
Det som står om innhold er vurderinger.

| Område | Berøres av | Vurdering |
|---|---|---|
| Laravel | #1–#3 (13.29 → 13.30+, http-foundation 8.1.5 → 8.1.9) | Patch- og minorversjoner i samme major. 13.37 er siste 13.x, og 13.30.x er minste sikre. **Anbefaling:** velg minste sikre versjon (13.30.1) hvis endringsloggen for 13.30–13.37 har noe uventet. Avhengigheten `symfony/http-foundation ^7.4 \|\| ^8.0` er uendret. Symfony-advisoryens regresjon (8.1.7–8.1.8) unngås ved å gå til 8.1.9. Procynias egen kode bruker bare `HeaderUtils::makeDisposition` (`app/Support/PrivateFiles/PrivateFileResponse.php`), ikke `split`. Om vendor-pakker splitter på mellomrom er ikke kontrollert. |
| Inertia og React | ingen | Ingen av pakkene er runtime-avhengigheter for React eller Inertia. `@vitejs/plugin-react` beholder sin versjon; bare Babel-treets `browserslist` endres. |
| Filament | #4 (5.7.6 → 5.8.3+) | Minor-oppgradering. Kan endre markup og CSS i admin-panelet, og publiserte assets i `public/` blir oppdatert. WCAG-arbeidet (fase 6) testet Filament i mørk modus og med a11y-krav, så E2E-testene for admin må kjøres. |
| Tailwind og Vite | #6–#7 | `source-map-js` og `browserslist` brukes av PostCSS, Tailwind og Babel under bygging. Bare patch og minor, så byggeresultatet bør bli identisk eller nesten identisk. Browserslist 4.29 kan gi en litt annen målliste for autoprefixer. |
| Playwright | ingen | Ikke berørt. |
| Wiki-graf og Sigma | ingen | `sigma` og `graphology` er ikke berørt. |
| AI og dokumentbehandling | #1–#3 indirekte | Ingen direkte bruk av de sårbare API-ene. Filopplasting går gjennom `Request` og `UploadedFile` fra http-foundation, så opplastingstester bør med i testplanen. |
| E-post | #2–#3 | Laravels Markdown-e-poster bruker CommonMark, og varslingstestene dekker dem. |

---

## 8. Prioritert gjennomføringsrekkefølge

Hver runde er én liten commit som kan testes for seg. Rundene er uavhengige, bortsett fra at runde 2
naturlig tar med noe av runde 1 hvis runde 1 er hoppet over.

1. **Runde 1 — P1:** `symfony/http-foundation` → 8.1.9.
2. **Runde 2 — P2 og P3:** `league/commonmark` → 2.10.2+ og `laravel/framework` → 13.30+.
3. **Runde 3 — P3:** `filament/*` → 5.8.3+ og `filament:upgrade`.
4. **Runde 4 — P3:** npm-lockfila (`concurrently`, `browserslist`, `baseline-browser-mapping`,
   `source-map-js`) i én commit.
5. **Avslutning:** én samlet regresjon. Både `composer audit` og `npm audit` skal være rene, og CI skal
   være grønn på `main`.

Begrunnelse:

- Runde 1 er den eneste med reell, uautentisert produksjonseksponering, og den er minst.
- Runde 2 er lav eksponering, men en direkte avhengighet med bred kodeflate. Den bør skilles fra
  Filament, så det er tydelig hva som eventuelt brekker.
- Runde 3 berører UI og publiserte assets og trenger egne E2E-tester.
- Runde 4 er isolert fra PHP-siden.

---

## 9. Testplan

### Målrettet etter hver runde

| Runde | Tester |
|---|---|
| 1 http-foundation | `composer audit`. `php artisan test` på `tests/Feature/Security`, `tests/Feature/Auth` (innlogging, throttling, klient-IP), `PublicWebsiteTest` og `PublicRegistrationTest`, Stripe-webhook-testene, `AiControllerTest` og Wiki-kildeopplasting (`UploadedFile`), og `tests/Feature/Health`. Manuell kontroll: en forespørsel med `Cache-Control: no-cache, max-age=0` gir samme oppførsel som før. |
| 2 CommonMark og Laravel | Det samme som runde 1, pluss varslings- og e-posttestene (`AiQuotaNotification`, `SubscriptionPaymentFailedNotification`, `BidWorkflowNotificationTest`), `tests/Unit`, og valideringstestene fra arbeidspakke 2 (`tests/Unit/Validation`, `CustomerUserManagementTest`). |
| 3 Filament | `tests/Feature/Filament`, `AdminPanelAccessGateTest`, og E2E `admin.spec.js` og `a11y-admin.spec.js`. Kontroller at `git diff public/` bare inneholder Filaments egne assets. |
| 4 npm | `npm ci`, `npm run build`, `npm run test:unit`, `npm run lint:a11y`, `npm audit`, og ett kort E2E-røyktest (for eksempel `auth.spec.js` og `app-navigation.spec.js`) mot nybygde assets. `public/hot` må fjernes eller dev-serveren stoppes først. |

### Samlet regresjon, én gang etter siste runde

Samme pakke som ble kjørt for arbeidspakke 2 (de berørte modulene, Filament, Auth, Unit; 3609 tester).
I tillegg kommer E2E-spesifikasjonene for admin, autentisering og navigasjon, `composer audit`,
`npm audit`, og en CI-kjøring på `main`.

Full regresjon skal ikke kjøres for hver runde.

---

## 10. Funn som krever større endringer

Ingen. Alle funnene kan rettes innenfor dagens versjonskrav.

**Observasjoner utenfor selve funnene:**

- `@vitejs/plugin-react` står under `dependencies` selv om den bare brukes til bygging. Dermed regner
  npm byggeverktøy som produksjonsrisiko. Å flytte den til `devDependencies` er en ryddeoppgave, ikke
  en sikkerhetsrettelse, og påvirker ikke Docker-bygget, som kjører `npm ci` uten `--omit=dev`.
- Workflowen viser varsler om at Node 20-baserte actions (`actions/checkout@v4`, `actions/setup-node@v4`)
  tvinges over på Node 24, og at `ubuntu-latest` flyttes til Ubuntu 26 fra 19.10.2026. Ingen av delene
  feiler i dag, men workflowen bør følges med etter 19.10.
- CI har vært rød på `main` i minst tre dager. Så lenge den er rød, gir et nytt funn ikke noe nytt
  signal. Det er hovedgrunnen til å rette P3-funnene raskt, selv om risikoen hver for seg er lav.

---

## 11. Utbedringslogg

### Runde 1 — `symfony/http-foundation` v8.1.5 → v8.1.9 (16.10.2026, branch `fix/dependency-security-2026-10`)

**[Observert]**

- **Advisory bekreftet** mot [symfony.com/cve-2026-108082](https://symfony.com/cve-2026-108082) (publisert 15.10.2026)
  og `composer audit` (PKSA-xmwy-rmx5-m7h4). Første rettelse kom i 8.1.7. Regresjonen der
  `split('foo bar', ' ')` ga én verdi gjelder 8.1.7–8.1.8 og er rettet i **8.1.9**. Ingen workaround er oppgitt.
- **Kommando:** `composer update symfony/http-foundation` uten `-W`. Lock-operasjoner: 1 oppdatering, 0 nye,
  0 fjernet. Ingen transitive endringer, fordi 8.1.9 har samme krav som 8.1.5 (`php >=8.4.1`,
  `symfony/deprecation-contracts`, `symfony/polyfill-mbstring`). `composer.json` er uendret.
- `composer.lock` endrer bare pakkeoppføringen for `symfony/http-foundation` (versjon, referanse, tid).
  Composer 2.10 i containeren endret også `plugin-api-version`. Den linja er rullet tilbake for å holde diffen
  minimal.
- **Endringer i vendor fra 8.1.5 til 8.1.9:**
  - `HeaderUtils::split()` bruker possessive kvantorer.
  - `Request` endrer port-stripping for IPv4 i klient-IP og matcher trusted hosts med oppslag og regex.
  - `BinaryFileResponse` godtar bare kjente `X-Sendfile-Type`-verdier.
  - Symfonys egne sesjonshåndterere er endret. Laravel bruker sine egne, så disse endringene berører ikke appen.
  - `UriSigner`, `IpUtils` og `PathRequestMatcher` har mindre rettelser.
- **`composer audit`:** `symfony/http-foundation` rapporteres ikke lenger. Det gjenstår 4 advisories i 3 pakker
  (`league/commonmark` ×2, `filament/filament`, `laravel/framework`), og de hører til runde 2 og 3.
- **Direkte kontroll av `split()`:**
  - `a` + n mellomrom + `b` med n = 17, 18, 30 og 5000 gir under 0,01 ms og riktig resultat. Før ga 18 eller
    flere mellomrom en tom liste.
  - `split('foo bar', ' ')` gir `['foo', 'bar']`.
  - `Cache-Control: no-cache, max-age=0` parses som før.
- **HTTP mot kjørende app** etter omstart av PHP-FPM:
  - `/login` gir 200, både uten header, med normal `Cache-Control` og med 40 mellomrom.
  - `/up` gir 200, og `/app` uten innlogging gir 302 til `/login`.
  - POST `/login` uten CSRF-token gir 419. Med token og feil passord gir den 302, og valideringsfeilen
    leveres via sesjonen.
- **Tester:** 354 bestått (2172 assertions) i `tests/Feature/Security`, `Auth`, `Health`,
  `StripeWebhookSignatureTest`, `PublicWebsiteTest`, `PublicRegistrationTest`, `CustomerFrontendAccessTest`,
  `HttpsTlsProductionConfigTest`, `AiControllerTest`, `WikiSourceControllerTest`, `WikiSourceControllerImageTest`,
  `EnterpriseWikiSourceOwnerGateTest`, `SupplierImportTest` og `AdminPanelAccessGateTest`.

