# Procynia — sikkerhetsaudit, august 2026

**Type:** read-only kodegjennomgang
**Commit:** `9b3730a` (branch `main`)
**Omfang:** autentisering, autorisasjon, tenant-isolasjon, filhåndtering, AI-flyt, web-/Laravel-sikkerhet, database, kø, secrets, logging, avhengigheter
**Ikke gjort:** ingen kodeendringer, ingen migrasjoner, ingen penetrasjonstesting mot kjørende miljø, ingen produksjonsverifisering

Denne rapporten inneholder tekniske detaljer. Den permanente kontrolldokumentasjonen ligger i
[`docs/operations/security.md`](../operations/security.md).

---

## Executive summary

Procynia er i vesentlig bedre sikkerhetsmessig forfatning enn en typisk Laravel-applikasjon på samme
modenhetsnivå, på det området som betyr mest for en fler-kundeløsning: **kundeisolasjon**.

Vi fant **ingen kritiske funn** og **ingen bevis for at kunde A kan nå kunde Bs data**. Isolasjonen
er implementert med et konsekvent «re-utled eierskap»-mønster som nøytraliserer den vanligste
IDOR-klassen i Laravel, og den er dekket av reell testkode.

De reelle svakhetene ligger et annet sted enn i tenant-modellen:

1. ~~**Ingen brute-force-beskyttelse på innlogging.**~~ **Lukket august 2026** — se F-01. Passord er
   fortsatt eneste faktor (MFA hører hjemme i Entra ID-arbeidet), men gjetting er nå begrenset og
   logget, og klient-IP kan ikke lenger forfalskes.
2. ~~**Stripe-webhooken feiler åpent.**~~ **Lukket august 2026** — se F-02. Verifiseringen er nå ubetinget.
3. **Autorisasjon er imperativ, ikke deklarativ.** 106 spredte `abort_unless()`-kall, null Policies.
   Korrektheten avhenger av at hver enkelt controller husker sjekken. I dag husker de det — men
   ingenting håndhever det.
4. **Ingen security headers**, og **47 åpne Composer-advisories** (9 høye).

Ingen av disse krever umiddelbar nedstengning. Punkt 1 og 2 bør lukkes før flere kunder tas inn.

---

## Funnoversikt

| ID | Severity | Område | Funn | Bevis | Risiko | Anbefalt tiltak |
|----|----------|--------|------|-------|--------|-----------------|
| F-01 | ~~**Høy**~~ **LUKKET** | Autentisering | Ingen rate limiting eller lockout på `/login` | `routes/web.php:135-136`, `AuthenticatedSessionController::store()` | Ubegrenset passordgjetting mot alle kontoer | **Lukket august 2026** — se F-01 nedenfor |
| F-02 | ~~**Høy**~~ **LUKKET** | Billing / integrasjon | Stripe webhook-signatur verifiseres kun hvis `STRIPE_WEBHOOK_SECRET` er satt — feiler åpent | `vendor/laravel/cashier/.../WebhookController.php:28-32`, `routes/web.php:42` | Forfalskede billing-hendelser mot uautentisert endepunkt | **Lukket august 2026** — se F-02 nedenfor |
| F-03 | ~~Middels~~ **LUKKET** | Admin / autorisasjon | Filament-panelet slipper inn enhver aktiv bruker; tilgangskontroll er opt-in per fil | `User::canAccessPanel()` (`app/Models/User.php:111-114`) | Én ny resource uten `canAccess()` eksponerer kryss-kunde admindata | **Lukket august 2026** — panelet er nå begrenset til interne administratorer, se F-03 nedenfor |
| F-04 | Middels | Autorisasjon | **LUKKET 30.08.2026** — re-derivation bekreftet som canonical mekanisme, med strukturell guard | `tests/Feature/Security/CrossTenantIsolationTest.php` | Ingen kjernemodell avhenger av kontrollerdisiplin alene lenger | Ingen |
| F-05 | Middels | Web | **LUKKET 29.08.2026** — globale security headers med enforcing CSP | `app/Http/Middleware/AddSecurityHeaders.php`; `tests/Feature/Security/SecurityHeadersTest.php` | Clickjacking, MIME-sniffing og manglende XSS-dybdeforsvar er adressert | Ingen — se residual risk for `/admin` |
| F-06 | Middels | Session | **LUKKET 30.08.2026** — sikker default utenfor `local` | `config/session.php`; `tests/Feature/Security/SessionCookieSecurityTest.php` | En glemt env-variabel kan ikke lenger gi sessionscookie uten `Secure` | Ingen |
| F-07 | Middels | Avhengigheter | **LUKKET 29.08.2026** — 47 → 0 advisories | `composer audit` | Alle tre rundene gjennomført: P0 Laravel, P1 Filament, P2 Dompdf. `composer audit` er ren | Ingen — sett opp `composer audit` i CI |
| F-08 | Middels | Infrastruktur | **LUKKET 30.08.2026** — Redis krever `requirepass`, fail-closed i Compose og runtime-check | `docker-compose.yml`; `RuntimePreflightService::checkRedisAuthentication()`; `tests/Feature/Security/RedisSecurityConfigTest.php` | Uautentisert Redis med sesjoner og køer er stengt | Ingen |
| F-09 | Lav | Secrets | Lokalt utviklerpassord committet i repoet | `.env.testing:25`, `AdvaniaLocalDevSeeder.php:17`, 5 e2e-specs | Lav i seg selv; risiko kun ved gjenbruk | Bekreft at verdien aldri brukes utenfor lokal utvikling |
| F-10 | Lav | Konfigurasjon | `APP_DEBUG=true` og `LOG_LEVEL=debug` som eksempel-defaults | `.env.example:16,34` | Stack traces og verbose logg hvis kopiert til produksjon | Kommenter eksplisitt i `.env.example` |
| F-11 | Middels | Avhengigheter | **LUKKET 29.08.2026** — 43 → 0 advisories | `npm audit`; `package.json`; `public/build` | Alle fire rundene gjennomført: P0 axios, P1 concurrently, P2 postcss/babel, P3 vite/esbuild. `npm audit` er ren | Ingen |
| — | — | Avhengigheter | **Dependency security CI gate = IMPLEMENTERT** 29.08.2026 | `.github/workflows/dependency-audit.yml` | `composer audit` og `npm audit` kjører på PR og push til `main`; advisories feiler builden | Ingen |
| F-12 | Lav | Informasjonseksponering | `FilamentInfoWidget` viser Filament-versjon til enhver aktiv bruker | `AdminPanelProvider.php:44` | Versjonsavsløring letter målrettet angrep | Fjern widget, eller lukk F-03 |

---

## F-01 — Ingen brute-force-beskyttelse på innlogging

### Funn
`/login` har ingen rate limiting, ingen kontolockout og ingen MFA. Passord er eneste autentiseringsfaktor.

### Bevis
- `routes/web.php:135-136` — ruten registreres uten `->middleware('throttle:…')`:
  ```php
  Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
  Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
  ```
- `app/Http/Controllers/Auth/AuthenticatedSessionController::store()` validerer og kaller
  `Auth::attempt()` direkte. Ingen `RateLimiter`, ingen `ensureIsNotRateLimited()`.
- Eneste definerte rate limiter i hele kodebasen gjelder registrering:
  `app/Providers/AppServiceProvider.php:40` → `RateLimiter::for('public-registration', …)`.

Laravels egen Breeze-scaffolding inkluderer throttling i `LoginRequest`. Den filen finnes ikke her —
innloggingen er skrevet for hånd og fikk aldri beskyttelsen med seg.

### Angrepsscenario
En angriper med en e-postadresse hos en Procynia-kunde kjører credential stuffing mot `/login`.
Ingenting bremser forsøkene, ingenting låser kontoen, og ingenting varsler. Gjenbrukte passord fra
tidligere lekkasjer er den realistiske innfallsvinkelen.

### Konsekvens
Full kontoovertakelse. En kompromittert konto gir tilgang til kundens anbud, kravdokumenter,
kunnskapsbase og Wiki — altså kundens konkurransesensitive materiale.

### Tiltak — implementert august 2026

**Status: LUKKET.**

| | Valg |
|---|---|
| Grense | 5 forsøk per minutt |
| Nedkjøling | 60 sekunder, utløper av seg selv |
| Nøkkel | `login:{normalisert e-post}\|{klient-IP}` — aldri passord |
| Konfigurasjon | `config/procynia.php` → `procynia.auth.login`, bevisst ikke env-overstyrbar |
| Teller opp | Ved hvert avvist forsøk, inkludert deaktivert konto og avvist kundeområde-tilgang |
| Nullstilles | Kun ved fullt vellykket innlogging |

Ingen permanent kontolås ble innført — en slik lås ville vært et tjenestenektangrep mot enhver konto
hvis adresse en angriper kjenner.

Mekanismen ligger i `AuthenticatedSessionController` med Laravels `RateLimiter`, ikke som
`throttle:`-middleware. Prosjektet bruker middleware-varianten for `public-registration`, men den
teller hver forespørsel og kan ikke nullstilles fra controlleren — begge deler kreves her.

Samtidig ble sikkerhetslogging lagt til: `authentication_succeeded`, `authentication_failed` og
`authentication_throttled`. Før dette logget innloggingsflyten **ingenting** — verifisert ved søk
etter `Log::` i controlleren før endringen. Uten logg kunne verken et gjetteforsøk eller en vellykket
kontoovertakelse oppdages i ettertid.

Verifisert av `tests/Feature/Auth/LoginThrottleAndLoggingTest.php` — 18 tester, 76 assertions.

### Klient-IP kunne forfalskes — lukket august 2026

**Rotårsak.** To forhold sammen:

1. `bootstrap/app.php` satte `trustProxies(at: '*')`.
2. Verken `docker/nginx/default.conf` eller `docker/production/nginx.conf` satte eller normaliserte
   `X-Forwarded-For`; nginx videresendte klientens egne headere til php-fpm som `HTTP_*`-parametre.

Symfony avgjør klient-IP ved å gå gjennom `X-Forwarded-For` fra høyre mot venstre og returnere den
første adressen som ikke tilhører en trusted proxy. Med `'*'` var alle trusted, så gjennomgangen gikk
helt til venstre og returnerte klientens egen påstand.

**Målt før endringen** (`curl -H 'X-Forwarded-For: 1.2.3.4' http://localhost:8080/...`):

```
REMOTE_ADDR            192.168.65.1      <- peer nginx faktisk tok imot
HTTP_X_FORWARDED_FOR   1.2.3.4           <- klientens egen verdi, uendret
HTTP_X_REAL_IP         9.9.9.9           <- klientens egen verdi, uendret
```

Konsekvens for F-01: login-limiteren nøkles på e-post + IP, så hver ny headerverdi ga en ny kvote.

**Lokal proxykjede, verifisert:**

```
Browser -> host :8080 -> procynia-web (nginx) -> app:9000 (php-fpm) -> Laravel
```

nginx setter `REMOTE_ADDR = $remote_addr`, altså peer-en den tok imot forbindelsen fra — ikke sin egen
adresse. Fordi containeren nås gjennom Docker sin publiserte port, er den peer-en alltid
Docker-gatewayen (`192.168.65.1` på Docker Desktop; `172.20.0.1`-området på Linux — Compose definerer
ikke noe eget nettverk, så `procynia_default` er `172.20.0.0/16`).

**On-prem produksjon har et lag til**, dokumentert i `docs/operations/production-deploy.md`: en ytre
nginx på verten terminerer TLS og gjør `proxy_pass` til `127.0.0.1:8080` med `X-Forwarded-For` og
`X-Forwarded-Proto`. Den kjeden må fortsatt virke — derfor kunne løsningen ikke bare være å kaste
`X-Forwarded-For`.

**Endring i nginx (begge konfigurasjoner):**

```
fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;
fastcgi_param HTTP_X_REAL_IP       $remote_addr;
```

`$proxy_add_x_forwarded_for` legger til den observerte peer-en bakerst i kjeden i stedet for å
overskrive. Det bevarer ekte klientadresse i on-prem-kjeden, samtidig som den høyreste oppføringen
alltid er observert av nginx og aldri påstått av den som ringer. `X-Real-IP` har ingen
kjedesemantikk og overskrives helt.

**Endring i Laravel:**

| | Før | Etter |
|---|---|---|
| Konfigurasjon | `trustProxies(at: '*')` i `bootstrap/app.php` | `config('procynia.security.trusted_proxies')`, satt via `AppServiceProvider::boot()` |
| Standardverdi | alle peers trusted | **tom — ingen trusted** |
| Kilde for klient-IP | `X-Forwarded-For` (klientkontrollert) | `REMOTE_ADDR` (observert av nginx) |

Konfigurert i en service provider fordi konfigurasjon ikke er lastet når middleware-closuren i
`bootstrap/app.php` kjører.

**Målt etter endringen**, samme forespørsel:

```
REMOTE_ADDR            192.168.65.1
HTTP_X_FORWARDED_FOR   1.2.3.4, 192.168.65.1     <- peer lagt til bakerst
HTTP_X_REAL_IP         192.168.65.1              <- forfalskning borte
```

Med tom trusted-proxy-liste ignorerer Laravel `X-Forwarded-For` helt og bruker `REMOTE_ADDR`.

**Verifisert av** `tests/Feature/Security/TrustedProxyTest.php` — 14 tester, blant annet at samme
klient med tre ulike `X-Forwarded-For`-verdier havner i **én** limiter-kvote, og at riktig passord
fortsatt avvises etter at grensen er brukt opp med roterende header.

### Miljøforskjeller

| Miljø | `TRUSTED_PROXIES` | Status |
|---|---|---|
| Lokal utvikling | tom | Verifisert |
| On-prem produksjon | Docker-gatewayen slik containeren ser den | **Må settes ved neste deploy.** Uten den mistes ekte klient-IP og HTTPS-gjenkjenning |
| Azure Container Apps | ingress-området | **Må verifiseres mot faktisk ingress/proxykjede før produksjonssetting.** Ikke fastslått fra repoet, og ikke gjettet |

Azure-siden er forberedt, ikke fullført: produksjons-nginx normaliserer headerne på samme måte, men
med tom trusted-proxy-liste vil alle forespørsler løse til ingress-adressen. Det gjør login-limiteren
til én global kvote per e-post i stedet for per klient — trygt, men grovere enn ønsket. Verdien må
settes når ingress-området er verifisert.

### Sidefunn — en eksisterende test er nå grønn av feil grunn

`tests/Feature/HttpsTlsProductionConfigTest::test_bootstrap_trusts_proxy_forwarded_headers` asserterer
at `bootstrap/app.php` inneholder strengen `trustProxies`. Etter F-01 konfigurerer ikke filen
trusted proxies lenger — den forklarer i en kommentar hvorfor `trustProxies(at: '*')` ble fjernet.
Testen passerer altså fortsatt, men matcher nå kommentaren i stedet for en faktisk konfigurasjon.

**Rettet august 2026**, som hygiene-punkt sammen med F-02. Testen heter nå
`test_trusted_proxies_come_from_configuration_and_not_from_a_wildcard()` og stripper kommentarlinjer
før den asserterer. Den kontrollerer fire ting i stedet for én: at `trustProxies(` ikke forekommer i
kjørende kode, at `procynia.security.trusted_proxies` finnes, at listen leses fra `TRUSTED_PROXIES`,
og at `TrustProxies::at()` faktisk anvender den.

### Sidefunn — testmiljøet bruker ekte Redis-cache

Under testarbeidet viste det seg at `phpunit.xml` ber om `CACHE_STORE=array`, men suiten faktisk
resolver `Illuminate\Cache\RedisStore`. Det betyr at cache-avhengig tilstand — inkludert
rate-limiter-tellere — overlever mellom testkjøringer, og at Redis' TTL ikke påvirkes av Laravels
tidsreise-hjelpere.

`LoginThrottleAndLoggingTest` pinner derfor seg selv til array-storen i `setUp()`. Det underliggende
forholdet er ikke rettet — det ligger utenfor F-01 — men bør ses på, siden det gjør enhver
cache-avhengig test upålitelig.

---

## F-02 — Stripe-webhooken feiler åpent

### Funn
Signaturverifisering av Stripe-webhooks er betinget. Er `STRIPE_WEBHOOK_SECRET` tom eller uatt,
registreres verifiseringsmiddleware ikke, og endepunktet tar imot uautentiserte forespørsler.

### Bevis
- `routes/web.php:42` — ruten er registrert manuelt, uten middleware:
  ```php
  Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])->name('cashier.webhook');
  ```
- `bootstrap/app.php:33-35` — CSRF er eksplisitt slått av for `stripe/webhook` (korrekt for webhooks,
  men fjerner ett lag).
- `vendor/laravel/cashier/src/Http/Controllers/WebhookController.php:28-32`:
  ```php
  public function __construct() {
      if (config('cashier.webhook.secret')) {
          $this->middleware(VerifyWebhookSignature::class);
      }
  }
  ```
- `config/cashier.php:50` — `'secret' => env('STRIPE_WEBHOOK_SECRET')`. Ingen fallback, ingen guard.

`app/Http/Controllers/StripeWebhookController::handleWebhook()` muterer reell tilstand: den kaller
`BillingService::closeAccountBilling()`, `syncSubscriptionFromStripe()` og
`SubscriptionService::syncPlanMeta()`.

### Angrepsscenario
Forutsetter at `STRIPE_WEBHOOK_SECRET` mangler i miljøet. En angriper som kjenner URL-en poster et
konstruert `customer.subscription.updated`- eller `deleted`-event og endrer abonnementstilstand for
en kunde — for eksempel oppgraderer sin egen plan eller avslutter en annen kundes abonnement.

Merk: `.env.example` inneholder plassholderen `whsec_`, som er *truthy*. En deployment som kopierer
eksempelfilen får derfor verifisering påslått (og alle ekte webhooks avvist — synlig feil). Det
farlige tilfellet er en deployment der variabelen utelates helt.

### Konsekvens
Manipulering av abonnement og fakturering. Ikke tilgang til kundedata, men direkte økonomisk og
avtalemessig påvirkning.

### Bekreftet empirisk før fiksen

Auditrapporten ble ikke tatt for gitt. En test ble skrevet først, med `cashier.webhook.secret` satt
til `null` og en payload signert med en angrepervalgt secret:

```
Expected response status code [503] but received 200.
```

**HTTP 200.** Hendelsen ble akseptert og behandlet. Svaret på spørsmålet «blir signaturverifisering
helt hoppet over når secreten mangler» er altså ja — bekreftet i kjørende kode, ikke bare i lesning.

Kodeveien: `Laravel\Cashier\Http\Controllers\WebhookController::__construct()` registrerer
`VerifyWebhookSignature` kun inne i `if (config('cashier.webhook.secret'))`. Er verdien falsy,
registreres middleware aldri, og `handleWebhook()` kjører på en uautentisert, CSRF-unntatt POST.

### Tiltak — implementert august 2026

**Status: LUKKET.**

Ny middleware `App\Http\Middleware\VerifyStripeWebhookSignature`, registrert **ubetinget** i
`StripeWebhookController::__construct()`. `parent::__construct()` kalles bevisst ikke, siden det er
nettopp den betingede registreringen som var feilen.

| Tilfelle | Respons | Behandles? |
|---|---|---|
| Secret mangler / tom / kun mellomrom | 503 | Nei |
| `Stripe-Signature` mangler | 403 | Nei |
| Signatur ugyldig eller feil secret | 403 | Nei |
| Signatur for en annen payload | 403 | Nei |
| Secret satt og signatur gyldig | 200 | Ja |

Statuskodene følger `EnsureHealthToken`, prosjektets eksisterende presedens: 503 når kontrollen ikke er
konfigurert, 403 når legitimasjonen er feil. 503 lar i tillegg Stripe prøve på nytt, så hendelser går
ikke tapt mellom en deploy og at secreten settes.

Selve HMAC-sammenligningen delegeres til Cashier og Stripes egen SDK. Å reimplementere
signaturverifisering ville vært en større risiko enn den som ble fikset.

**Logging ved avvisning:** `event = stripe_webhook_rejected`, `reason = missing_webhook_secret |
invalid_signature`. Aldri secreten, rå payload, signaturheaderen eller betalingsdetaljer — heller ikke
Stripes unntaksmelding, som kan gjengi deler av headeren. Egen test fanger alt som skrives under en
avvist forespørsel og bekrefter at ingen av delene forekommer.

**CSRF er fortsatt av for ruten**, og skal være det. Stripe kan ikke levere et CSRF-token; å kreve det
ville brutt alle ekte webhooks uten å gi sikkerhet. Signaturen er autentiseringen.

**`.env.example` endret:** `STRIPE_WEBHOOK_SECRET=whsec_` → tom verdi. Plassholderen var truthy og fikk
konfigurasjonen til å se komplett ut, samtidig som hver ekte webhook ville blitt avvist med 403
«ugyldig signatur» — en feilmelding som peker feil vei. Tom verdi gir 503 «ikke konfigurert», som sier
hva som faktisk mangler.

**Verifisert av** `tests/Feature/Billing/StripeWebhookSignatureTest.php` — 14 tester med ekte
HMAC-SHA256-signaturer i Stripes format, verifisert av Stripes egen SDK. Ingenting er mocket.

### Gjenstående oppfølging

Manglende webhook-secret oppdages i dag først når Stripe kaller. En sjekk i `ops:runtime-check`
(`--azure`-profilen har allerede tilsvarende for andre secrets) ville flyttet oppdagelsen til
deploy-tidspunktet. Ikke bygget her — oppgaven ba om runtime fail-closed først, og det er på plass.

**Kan ikke verifiseres fra repoet alene:** om `STRIPE_WEBHOOK_SECRET` faktisk er satt i dagens
produksjon. Konsekvensen av at den mangler er nå 503 og loggført avvisning, ikke stille behandling.

---

## F-03 — Filament-panelet slipper inn enhver aktiv bruker

### Funn
`/admin` er dokumentert som «internal admins only». Inngangsporten sjekker kun at brukeren er aktiv.

### Bevis
`app/Models/User.php:111-114`:
```php
public function canAccessPanel(Panel $panel): bool
{
    return (bool) $this->is_active;
}
```
`app/Providers/Filament/AdminPanelProvider.php:47-62` har ingen ekstra autorisasjonsmiddleware, kun
`Authenticate::class`. `Dashboard::class` er registrert ubetinget.

### Hva som faktisk begrenser skaden i dag
Vi gikk gjennom **alle 79 filene** under `app/Filament/`:

- Alle Resources implementerer `canAccess()` — 0 mangler
- Alle Pages implementerer `canAccess()` — 0 mangler
- Begge Widgets implementerer `canView(): isInternalAdmin()` (`MrrWidget.php:15-18`,
  `PlanDistributionWidget.php:19-22`)

En vanlig kundebruker som åpner `/admin` i dag får derfor en tom dashboard med `AccountWidget`
(egne kontodata) og `FilamentInfoWidget` (Filament-versjon). **Ingen kundedata eksponeres.**

Derfor er dette klassifisert som Middels, ikke Høy: den faktiske eksponeringen i dag er minimal.

### Angrepsscenario
Det realistiske scenariet er ikke et angrep, men en regresjon: neste Filament-resource som legges til
uten `canAccess()` er umiddelbart tilgjengelig for enhver aktiv kundebruker. Filaments default er
«tillat». Ingen test dekker panelinngangen — vi søkte etter tester som treffer `/admin` og fant ingen.

### Konsekvens
Ved en slik regresjon: kryss-kunde lesetilgang til admindata (kunder, brukere, notices, sync-logger),
avhengig av hvilken resource det gjelder.

### Tiltak — implementert august 2026

**Status: LUKKET.**

`User::canAccessPanel()` krever nå `is_active && (isSuperAdmin() || isCustomerAdmin())`.

**Bekreftet empirisk før fiksen.** Testene ble skrevet først og kjørt mot uendret kode: sju feilet,
alle med samme årsak — en aktiv kundebruker passerte panelgaten:

```
An ordinary customer user must not pass the panel gate.
Failed asserting that true is false.
```

Alle femten passerer etter endringen.

### Sluttilstand: kun interne administratorer

Gaten ble innført i to trinn. Første trinn var `is_active && (super_admin || customer_admin)`, som
bevarte kundeadministratorers tilgang til `UserResource`. Det reiste et produktspørsmål — bør
kundeadministratorer i det hele tatt være i Filament? — som ble besvart **nei**.

**Endelig betingelse:**

```php
// User::canAccessPanel()
is_active && CustomerContext::isInternalAdmin($this)
```

`isInternalAdmin()` er den autoritative definisjonen: `role = super_admin` og `customer_id = null`.
Verifisert at ingen andre roller slipper gjennom.

`UserResource::canAccess()` gikk samtidig fra `canManageUsers()` til `isInternalAdmin()`. Den var den
siste parallelle veien inn i panelet for kundeadministratorer.

### Forutsetningen som ble kontrollert først

Å stenge Filament for kundeadministratorer fjerner bare en funksjon dersom den ikke finnes andre
steder. Den finnes:

`App\Http\Controllers\App\UserController` (`/app/users`) gir kundeadministratorer full,
kundeavgrenset brukeradministrasjon — index, create, edit, update, toggle-active — filtrert på
`customer_id` gjennom `scopedCustomerUsersQuery()` og `scopedCustomerUser()`.
`tests/Feature/App/CustomerUserManagementTest.php` dekker dette, inkludert
`test_customer_admin_only_sees_users_from_the_same_customer`.

Ingen funksjon gikk tapt. Kundeadministratorer fikk én vei i stedet for to, og den gjenværende er den
som er tenant-scopet ved konstruksjon.

**`docs/operations/admin-guide.md` beskrev allerede denne modellen** — «Kundeadmin … Kundeapp
(`/app/`), ikke adminpanel», og «alle interne adminflater sjekker `isInternalAdmin()`». Det var koden
som lå etter dokumentasjonen, ikke omvendt.

Kontrollert i utviklingsdatabasen: alle `super_admin`-kontoer har `customer_id = null`. Ingen reell
konto låses ute av innstrammingen.

### Test som ble endret, og hvorfor

`CustomerAdminNavigationTest::test_user_resource_access_requires_manage_users_permission()`
asserterte `assertTrue(UserResource::canAccess())` for en kundeadministrator. Den forventningen er nå
feil.

Assertionen ble **invertert, ikke slettet**, og testen heter nå
`test_user_resource_access_requires_internal_admin()`. Ved siden av den ble
`test_a_customer_admin_administers_users_in_the_customer_frontend_instead()` lagt til, som beviser at
evnen ikke forsvant: kundeadministratoren når `/app/users` og `/app/users/create`, og avvises fra
Filaments `UserResource` med 403.

### Dybdeforsvar beholdt

Ingen `canAccess()`, `canView()`, `canCreate()`, `canEdit()` eller `canDelete()` fjernet.
`UserResource::canEdit()`, `canDelete()`, `sanitizeFormData()` og den kundescopede
`getEloquentQuery()` er beholdt selv om de nå er teknisk overflødige — de koster ingenting og er et
sikkerhetsnett dersom paneltilgangen noen gang endres igjen.

Alle 31 resources og 15 pages gater nå på samme prinsipp, så lagene er enige i stedet for å motsi
hverandre. Det var nettopp uenigheten mellom dem som gjorde funnet vanskelig å vurdere.

**Verifisert av** `AdminPanelAccessGateTest` (17 tester) og `CustomerAdminNavigationTest` (10).
Regresjon: hele Filament-suiten pluss auth og kundeflate — 281 tester / 1302 assertions — og
kundeadministrasjonen i kundeflaten, 72 tester / 201 assertions.

---

## F-04 — Ingen autorisasjonsabstraksjon

### Funn
Applikasjonen har ingen Laravel Policies eller Gates. All autorisasjon er imperative sjekker i
controllere.

### Bevis
- `app/Policies/` finnes ikke
- 0 treff på `authorize(`, `Gate::allows`, `Gate::denies` i `app/Http/Controllers/`
- 106 treff på `abort_unless(` / `abort_if(` i `app/Http/Controllers/`
- Ingen `AuthServiceProvider`-policy-registrering

### Vurdering
Dette er en arkitektonisk observasjon, ikke en utnyttbar sårbarhet. Vi verifiserte at de faktiske
sjekkene er til stede og korrekte der vi så etter. Men kontrollen er ikke sentralisert, og det finnes
ingen mekanisme som fanger en glemt sjekk.

### Tiltak
Innfør Policies for kjernemodellene (`SavedNotice`, `KnowledgeItem`, `EnterpriseWikiPage`,
`EnterpriseWikiDocument`, `User`). Eksisterende `SavedNoticeAccessService` er allerede halvveis dit
og er et godt utgangspunkt.

---

## F-05 — Security headers — LUKKET

### Funn (august 2026)
Ingen Content-Security-Policy, HSTS, X-Frame-Options, X-Content-Type-Options eller Referrer-Policy
ble satt globalt. Søk i `app/`, `config/`, `docker/nginx/default.conf` og
`docker/production/nginx.conf` ga ett eneste treff:
`app/Http/Controllers/App/WikiSourceController.php:231` satte `X-Content-Type-Options: nosniff` på
én enkelt filrespons. Bekreftet med `curl` mot `/login`, `/app`, `/admin` og `/stripe/webhook`:
**null security headers**.

### Tiltak — implementert 29. august 2026

`app/Http/Middleware/AddSecurityHeaders.php`, registrert globalt i `bootstrap/app.php` med
`$middleware->append()`. Global framfor `web`-gruppen, slik at også Filaments egen middleware-stack
og ikke-web-responser dekkes. Policyen ligger som verdi i
`config('procynia.security.headers')`, ikke som kontrollflyt.

Valget falt på Laravel-middleware framfor nginx fordi headerne da gjelder likt lokalt, i test og
bak nginx — en nginx-only løsning ville ikke eksistert i utvikling eller i testene.

| Header | Verdi | Gjelder |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | alle responser |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | alle responser |
| `X-Frame-Options` | `DENY` | alle responser |
| `Permissions-Policy` | `accelerometer=(), autoplay=(), camera=(), display-capture=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), usb=(), xr-spatial-tracking=()` | alle responser |
| `Strict-Transport-Security` | `max-age=31536000` | **kun HTTPS-forespørsler** |
| `Content-Security-Policy` | se under | **kun `text/html`-responser** |

CSP settes kun på HTML fordi den styrer hvordan en nettleser tolker et dokument; på en JSON-payload
eller en PDF-strøm er den bytes uten effekt. De øvrige headerne settes overalt — `nosniff` betyr
noe også på en nedlasting.

`X-XSS-Protection` er bevisst **ikke** gjeninnført: den er utfaset, filteret har selv vært en
sårbarhetskilde, og CSP erstatter den. En test vokter at den ikke kommer tilbake.

### Content-Security-Policy

Grunnpolicy (kundefrontenden, produksjon):

```
default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none';
form-action 'self'; script-src 'self'; style-src 'self' 'unsafe-inline';
img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self';
frame-src 'self'; worker-src 'self' blob:; manifest-src 'self'
```

Kjernen er `script-src 'self'` **uten** `unsafe-inline` og `unsafe-eval`. Kundefrontenden er
Inertia + React, og produksjonsbygget sender kun lenkede modulskript — den trenger verken inline
script eller eval. Ingen wildcards noe sted; en test vokter det.

`frame-ancestors 'none'` betyr at ingen får ramme Procynia, og henger sammen med
`X-Frame-Options: DENY`. `frame-src 'self'` er en annen sak og er nødvendig: AI-dokumentvisningen
rammer inn en PDF fra eget origin
(`resources/js/Pages/App/AI/DocumentPreview.jsx` — prosjektets eneste `<iframe>`).

**Ingen eksterne origins i kundefrontenden.** Stripe kjører kun server-side; det finnes ingen
`js.stripe.com` eller annen Stripe-ressurs i frontend, så ingen Stripe-origin er whitelistet.

#### Filament (`/admin`) — avvik og residual risk

```
script-src 'self' 'unsafe-inline' 'unsafe-eval'
img-src   'self' data: blob: https://ui-avatars.com
```

Begge er dokumenterte nødvendigheter, ikke bekvemmelighet:

- `'unsafe-inline'`: Filament rendrer inline `<script>`-blokker (dark-mode-bootstrap,
  `window.filamentData`). Panelet har ingen nonce-mekanisme å hekte seg på.
- `'unsafe-eval'`: Alpine, som ligger bundlet inne i `livewire.js`, evaluerer direktiver med
  `new Function`/`AsyncFunction` — verifisert i den serverte filen.
- `https://ui-avatars.com`: funnet som et faktisk CSP-brudd i nettleser, ikke antatt. Filaments
  standard avatar-provider laster brukeravatarer derfra.

**Residual risk:** `/admin` har svakere XSS-dybdeforsvar enn kundeflaten. Det er akseptert fordi
panelet er begrenset til interne administratorer (funn F-03), og fordi avviket er *scopet* — de
strukturelle beskyttelsene (`object-src 'none'`, `frame-ancestors 'none'`, `base-uri 'self'`) står
uendret på `/admin`, og kundeflaten beholder den strenge policyen. En test vokter at
admin-lettelsen ikke lekker over.

**Eget oppfølgingspunkt, ikke F-05:** ui-avatars.com-kallet sender interne brukernavn til en
tredjepart ved hvert admin-sidevisning. Å bytte til en lokal avatar-provider ville fjernet både
CSP-unntaket og den lekkasjen, men det er en funksjonell endring.

#### Utvikling: report-only, ikke enforcing

I produksjon er CSP **enforcing**. Lokalt, mens Vite dev-server kjører, sendes samme policy som
`Content-Security-Policy-Report-Only`.

Grunnen er konkret og verdt å kjenne til: **CSPs host-source-grammatikk kan ikke uttrykke
IPv6-literaler.** Vite binder til `localhost`, som på macOS slår opp til `::1`, og
`laravel-vite-plugin` skriver derfor `http://[::1]:5173` inn i `public/hot`. Nettleseren forkaster
hele kilden — «contains an invalid source: 'http://[::1]:5173'. It will be ignored» — og en
enforcing policy blokkerer da dev-serverens moduler. Det ble oppdaget i nettleser under arbeidet:
`/login` ble helt blank med fire `script-src-elem`/`style-src-elem`-brudd.

Report-only i utvikling betyr at utvikleren fortsatt ser brudd i konsollen, men ikke får en
ødelagt side. Produksjonspolicyen svekkes ikke for å holde HMR i live.

Dev-branchen krever både `app()->environment('local')` **og** at `public/hot` finnes, så en
gjenglemt hot-fil i et produksjonsimage kan ikke utvide policyen, og testene tester alltid den
policyen som faktisk shippes.

### Verifikasjon

**Nettleser (Playwright, `securitypolicyviolation`-eventer):** null CSP-brudd og null
console-errors med enforcing policy, målt på `/login`, kundeinnlogging, `/app/dashboard`,
`/app/notices`, `/app/ai`, samt Filament-dashboard, `/admin/customers`, `/admin/users` og etter
Alpine-interaksjon. Det eneste bruddet som ble funnet underveis — ui-avatars.com — ble rettet med
minste nødvendige policyendring og deretter re-verifisert til null.

**Tester:** `tests/Feature/Security/SecurityHeadersTest.php`, 13 tester / 82 assertions. De pinner
policyens innhold, ikke bare at headeren finnes: enkeltdirektiver, fravær av wildcards, at
kundeflaten ikke har `unsafe-*`, at admin-lettelsen ikke lekker, at HSTS finnes over HTTPS og
**ikke** over HTTP, at HSTS aldri får `preload`, at CSP ikke settes på JSON, og at
`X-XSS-Protection` ikke gjeninnføres.

**Regresjon:** 102 tester passerte samlet — security headers, login-throttling (F-01), trusted
proxy, Stripe-webhook (F-02), Filament access gate (F-03), Filament-navigasjon og
HTTPS/TLS-konfigurasjon. `npm run build` grønn (939 moduler), `npm run test:unit` 43 passerte, og
14 Playwright-tester mot **enforcing** CSP i produksjonsmodus.

Stripe-webhooken er upåvirket: signaturverifisering og responsen er uendret, og CSP settes ikke på
den responsen.

### HSTS — bevisste utelatelser

`max-age=31536000`, sendt kun når `$request->isSecure()`. Uten `preload` og uten
`includeSubDomains`:

- `preload` er en enveisdør — å komme av nettlesernes preload-liste tar måneder. Det er en
  driftsbeslutning, ikke en kodebeslutning.
- `includeSubDomains` venter til alle Procynia-subdomener er kjent HTTPS-only; å legge den til for
  tidlig ville brutt et eventuelt subdomene som fortsatt går på HTTP.

`isSecure()` respekterer `X-Forwarded-Proto` kun for proxyer i den herdede trusted-proxy-listen
(F-01/trusted proxy), så en klient kan ikke fremtvinge HSTS-headeren. Trusted proxy-oppsettet ble
ikke endret.

---

## F-06 — Session cookie `Secure` — LUKKET

### Funn (august 2026)
`config/session.php` — `'secure' => env('SESSION_SECURE_COOKIE')`, uten default.

### Korrigert forståelse av risikoen

Førsteutkastet konkluderte med at «cookien settes uten `Secure`-flagget» når variabelen er uatt.
Det er ikke helt riktig, og den faktiske risikoen viste seg å være smalere — men verre.

`null` betyr ikke «usikker». Laravel sender verdien rett inn i Symfonys `Cookie`, som leser `null`
som **«match forespørselen»** (`secureDefault`). Målt oppførsel før endringen:

| Scenario | `Secure` |
|---|---|
| HTTPS direkte | `true` |
| HTTP direkte | `false` |
| **HTTP + `X-Forwarded-Proto: https`, uten trusted proxy** | **`false`** |

Den tredje raden er den som betyr noe, for den er nøyaktig Procynias produksjonsform: TLS
termineres i nginx eller Container Apps ingress, og appen nås over ren HTTP med
`X-Forwarded-Proto: https`. Laravel tror på den headeren kun for proxyer i trusted-lista, og den
lista er **tom som standard** etter herdingen i F-01. På et deploy der `TRUSTED_PROXIES` aldri ble
satt, ville sessionscookien altså gått ut **uten `Secure`** selv om nettleserforbindelsen var HTTPS.

Konsekvensen av en cookie uten `Secure`: nettleseren sender den også over ren HTTP. Ett enkelt
uoppmerksomt `http://`-kall mot domenet — en gammel bokmerkelenke, en redirect, en aktiv angriper på
nettverket — er nok til at sessions-ID-en går i klartekst og kan kapres.

### Tiltak — implementert 30. august 2026

```php
'secure' => env(
    'SESSION_SECURE_COOKIE',
    env('APP_ENV') === 'local' ? null : true,
),
```

Utenfor `local` er flagget `true` **uavhengig av hvordan forespørselen ser ut**. Det fjerner hele
koblingen til runtime-HTTPS-deteksjon, så en manglende `TRUSTED_PROXIES` ikke lenger kan degradere
cookien.

Lokalt beholdes `null` framfor `false`. Det bevarer den nyttige halvdelen av den gamle oppførselen:
`http://localhost` fungerer, og en utvikler som kjører HTTPS lokalt får fortsatt `Secure`.

En eksplisitt `SESSION_SECURE_COOKIE` vinner fortsatt, også `false`. Det er bevisst: en operatør som
feilsøker en uvanlig ingress kan trenge det, og det er en villet handling. F-06 handlet om at
variabelen blir **glemt**, og en glemt variabel lander nå på trygg side.

### Cookie-attributter (uendret, kontrollert)

| Attributt | Verdi |
|---|---|
| `Secure` | `true` utenfor `local`; følger forespørselen i `local` |
| `HttpOnly` | `true` |
| `SameSite` | `lax` |
| `Path` | `/` |
| `Domain` | ikke satt (host-only) |
| Navn | `procynia-session` |

`HttpOnly` og `SameSite=lax` var allerede trygge defaults og ble ikke rørt. Cookie-navnet ble ikke
endret, og `__Host-`/`__Secure-`-prefikser ble ikke innført — større kompatibilitetsflate, egen
vurdering.

`AuthenticatedSessionController::store()` kaller fortsatt `session()->regenerate()` etter vellykket
innlogging, som stenger session fixation.

### Produksjonskonfigurasjon

- **Azure:** `infra/main.bicep` setter `SESSION_SECURE_COOKIE` eksplisitt; kontrakten krever `true`.
- **On-prem:** ble aldri satt noe sted. Det var det reelle hullet. Produksjonsimaget har
  `ENV APP_ENV=production` (`docker/production/Dockerfile`) og `docker-compose.prod.yml` setter
  `APP_ENV: production`, så den nye defaulten slår inn av seg selv.
- **`.env.example`:** variabelen settes bevisst **ikke** der. Filen leveres med `APP_ENV=local`, så
  en `true` der ville brutt lokal HTTP-utvikling. Den er dokumentert med en kommentar i stedet.

### Verifikasjon

Oppførselen ble målt før og etter, ikke lest ut av config-filen. Etter endringen er `Secure=true` i
alle tre scenariene over, inkludert proxy-tilfellet.

`tests/Feature/Security/SessionCookieSecurityTest.php` — 8 tester / 20 assertions: produksjonsdefault
uten env-variabel, `Secure` over HTTPS, **den terminerte TLS-forespørselen fra utrustet proxy**,
øvrige cookie-attributter, lokal HTTP som fortsatt fungerer, lokal HTTPS som fortsatt får `Secure`,
at eksplisitt override virker, og en strukturell guard som feiler hvis noen gjeninnfører Laravels
`'secure' => env('SESSION_SECURE_COOKIE'),`.

Verifisert med `php artisan config:cache` (og ryddet opp etterpå). Faktisk kjørende app over lokal
HTTP gir fortsatt `httponly; samesite=lax` uten `secure`, som ønsket.

Regresjon: 108 tester passerte — session cookie, security headers (F-05), login-throttling (F-01),
trusted proxy, Stripe-webhook (F-02), Filament access gate (F-03), HTTPS/TLS-config og
Azure stateless runtime-kontrakt.

---

## F-07 / F-11 — Avhengigheter

### Composer — F-07 LUKKET

**Status: LUKKET.** Tre oppgraderingsrunder gjennomført 29. august 2026: P0 `laravel/framework`,
P1 Filament-gruppen og P2 Dompdf. `composer audit` er ren.

| | Utgangspunkt | Etter P0 | Etter P1 | Etter P2 |
|---|---|---|---|---|
| Advisories | 47 | 17 | 6 | **0** |
| Berørte pakker | 16 | 6 | 1 | **0** |
| Høye advisories | 9 | 1 | 0 | **0** |

**Alle 47 advisories lukket, inkludert alle ni høye.** `composer.json` er byte-identisk gjennom
alle tre rundene — kun `composer.lock` (og Filaments publiserte assets) er berørt.

#### P0 — Laravel-gruppen, 29. august 2026

| | Før | Etter |
|---|---|---|
| `laravel/framework` | v13.2.0 | **v13.29.0** |
| Advisories | 47 i 16 pakker | 17 i 6 pakker |
| Høye | 9 | 1 |

#### Oppgraderingskommando

```
composer update laravel/framework --with-all-dependencies --with "guzzlehttp/guzzle:^7.15.2"
```

``--with-all-dependencies`` alene ville løftet Guzzle til 8.1.0, PSR-7 til 3.1.0 og
`guzzlehttp/promises` til 3.0.2 — tre major-hopp som ikke kreves av Laravel 13.29, kun et resultat
av at Composer velger høyeste tillatte versjon. Guzzle ble derfor kappet til 7.x-linjen. Det gir
samme sikkerhetsresultat (7.15.5 ligger over det trygge gulvet 7.15.2) med vesentlig mindre
BC-risiko. Filament og Dompdf ble ikke berørt.

Resultat: **1 ny låst pakke, 49 oppgraderinger, 0 fjernet, 0 nedgraderinger.**

#### Status for de ni høye

| Advisory | Pakke | Før | Etter | Status |
|---|---|---|---|---|
| PKSA-3r5d-mb8f-1qw9 — CRLF i `email`-regelen | `laravel/framework` | v13.2.0 | v13.29.0 | **Lukket** |
| CVE-2026-71488 — kvadratisk DoS | `league/commonmark` | 2.8.2 | 2.10.0 | **Lukket** |
| PKSA-1q6p-sqkj-8mmj — dupliserte fotnoter | `league/commonmark` | 2.8.2 | 2.10.0 | **Lukket** |
| PKSA-cqd6-fg4n-nxpf — kolliderende slugs | `league/commonmark` | 2.8.2 | 2.10.0 | **Lukket** |
| PKSA-mc58-w91n-f5gv — attributtblokker | `league/commonmark` | 2.8.2 | 2.10.0 | **Lukket** |
| CVE-2026-45075 — HEAD-bypass | `symfony/http-kernel` | v8.0.7 | v8.1.5 | **Lukket** |
| CVE-2026-45067 — CRLF i `Mime\Address` | `symfony/mime` | v8.0.7 | v8.1.5 | **Lukket** |
| CVE-2026-69246 — ikke-kanonisk host | `guzzlehttp/guzzle` | 7.10.0 | 7.15.5 | **Lukket** |
| CVE-2026-48505 — MFA-koder gjenbrukbare | `filament/filament` | v5.4.1 | v5.4.1 | **Fortsatt åpen** |

Den ene gjenstående høye er den som ble vurdert som **ikke eksponert** i analysen: Filament MFA er
ikke aktivert i Procynia.

#### Pakker som flyttet

`laravel/framework` er den eneste direkte avhengigheten i settet. Alle øvrige er transitive.

| Gruppe | Pakker |
|---|---|
| Laravel | `framework` v13.2.0→v13.29.0, `prompts`, `serializable-closure` |
| Symfony | `http-kernel`, `mime`, `mailer`, `http-foundation`, `routing`, `console`, `translation`, `process`, `string`, `uid`, `var-dumper`, `finder`, `error-handler`, `event-dispatcher`, `css-selector`, `clock` — alle v8.0.x→v8.1.x |
| Symfony contracts/polyfills | `deprecation-`, `event-dispatcher-`, `service-`, `translation-contracts`; ni `polyfill-*`, samt ny `polyfill-php86` |
| Guzzle | `guzzle` 7.10.0→7.15.5, `psr7` 2.9.0→2.13.1, `promises` 2.3.0→2.5.3, `uri-template` v1.0.5→v2.0.1 |
| League | `commonmark` 2.8.2→2.10.0, `flysystem` + `flysystem-local` 3.35.3, `mime-type-detection` |
| Øvrige | `nesbot/carbon`, `ramsey/uuid`, `brick/math`, `vlucas/phpdotenv`, `nette/*`, `phpoption`, `graham-campbell/result-type`, `voku/portable-ascii` |

Symfony gikk til 8.1.x (minor) og ikke 8.0.12/13 (patch) som analysen antok. Symfonys BC-løfte
gjelder for minor-versjoner, og hele testsuiten er kjørt på resultatet.

#### Testresultat

Full testsuite kjørt sekvensielt på oppgradert lock: **4974 passerte, 113 feilet (22 777 assertions)**.

De 113 feilene ble verifisert som **pre-eksisterende, ikke regresjoner**. Metode: den samme
delmengden på 34 testklasser ble kjørt isolert mot begge lock-filer.

| | `laravel/framework` | Feilet | Passerte |
|---|---|---|---|
| Gammel lock | v13.2.0 | 113 | 812 |
| Ny lock | v13.29.0 | 114 | 811 |

113 av feilene er **identiske testnavn** på begge versjoner. Den ene differansen
(`replace file rejects when same hash exists on same document`) passerer isolert på **begge**
versjoner og er en rekkefølge-/kaskadeeffekt i en testklasse som allerede feiler, ikke en regresjon.

Feilene ligger nesten utelukkende i Enterprise Wiki-området og tilhører en kjent, pågående
arbeidsstrøm. Alle sikkerhets- og infrastrukturområder er grønne: auth, trusted proxy,
Stripe-webhook, Filament-tilgang, Azure-kontrakter, køtopologi, Doffin, notifications, Billing.

#### P1 — Filament-gruppen, 29. august 2026

Andre og siste planlagte oppgraderingsrunde før dompdf. `composer.json` fortsatt uendret.

| | Før | Etter |
|---|---|---|
| `filament/filament` | v5.4.1 | **v5.7.6** |
| `symfony/html-sanitizer` | v8.0.7 | **v8.1.1** |
| `livewire/livewire` | v4.2.1 | **v4.4.2** |
| Advisories | 17 i 6 pakker | **6 i 1 pakke** |
| Høye | 1 | **0** |

Kommando:

```
composer update filament/filament --with-all-dependencies --with "guzzlehttp/guzzle:^7.15.2"
```

Samme Guzzle-kapping som i P0: uten den løfter `-W` Guzzle til 8.1.0, PSR-7 til 3.1.0 og
`promises` til 3.0.2, og fjerner `ralouphie/getallheaders` — tre major-hopp som Filament ikke
krever. Laravel, Guzzle og dompdf står uendret etter oppgraderingen.

Resultat: **2 nye pakker, 24 oppgraderinger, 0 fjernet.**

To major-hopp ble akseptert etter kontroll: `pragmarx/google2fa-qrcode` v3→v4 og
`hamcrest/hamcrest-php` v2→v3. Begge ble verifisert som *ikke* påkrevd (resolusjonen går opp med
dem kappet), men de ble likevel sluppet gjennom: qrcode-pakken er kun nåbar via Filament MFA, som
ikke er aktivert, og hamcrest er dev-only testinfrastruktur under Mockery. Å kappe dem ville lagt
til constraint-kompleksitet uten å redusere reell risiko.

##### Pakker som flyttet

| Gruppe | Pakker |
|---|---|
| Filament (11) | `filament`, `actions`, `forms`, `infolists`, `notifications`, `query-builder`, `schemas`, `support`, `tables`, `widgets` — alle v5.4.1→**v5.7.6** |
| Symfony | `html-sanitizer` v8.0.7→**v8.1.1** |
| Livewire | `livewire/livewire` v4.2.1→v4.4.2, `danharrin/livewire-rate-limiting` v2.2.1 |
| UI/render | `blade-ui-kit/blade-icons`, `masterminds/html5`, `spatie/shiki-php`, `ueberdosis/tiptap-php`, `spatie/invade`, `spatie/laravel-package-tools` |
| MFA-relatert | `pragmarx/google2fa` v9.1.0, `pragmarx/google2fa-qrcode` v3.0.0→v4.0.0 |
| Dev | `mockery/mockery` 1.6.15, `hamcrest/hamcrest-php` v2.1.1→v3.0.0 |
| Nye | `anourvalar/eloquent-serialize` 1.3.11, `ryangjchandler/blade-capture-directive` v1.1.1 |

Kun `filament/filament` er en direkte avhengighet i produksjon (`mockery/mockery` er direkte i
require-dev). Filament publiserte samtidig sine kompilerte assets på nytt under
`public/js/filament/` og `public/css/filament/` — 21 sporede filer. Det er påkrevd, ellers
serverer panelet JS som ikke matcher den installerte PHP-koden.

##### Advisory-status

| Pakke | Før | Etter |
|---|---|---|
| `filament/filament` | 3 (1 høy) | **0** |
| `filament/actions` | 1 | **0** |
| `filament/infolists` | 1 | **0** |
| `filament/tables` | 1 | **0** |
| `symfony/html-sanitizer` | 5 | **0** |
| `dompdf/dompdf` | 6 | 6 (uendret) |

**CVE-2026-48505** (MFA-gjenopprettingskoder, den siste høye i hele prosjektet) er lukket.
Alle fem `symfony/html-sanitizer`-advisories er lukket samtidig — pakken kom fra
`filament/support` og flyttet derfor først nå.

Procynia bruker ikke `symfony/html-sanitizer`, tiptap, shiki eller masterminds/html5 direkte;
de er kun Filament-interne. Det finnes ingen `app/Livewire`-katalog — Livewire brukes utelukkende
av Filament.

##### Testresultat

| Område | Resultat |
|---|---|
| `tests/Feature/Filament` (hele) | **249 passerte** (1195 assertions) |
| F-03 tilgangsgate (panel + navigasjon) | **27 passerte** (63 assertions) |
| Auth + Security + Billing | **46 passerte** (148 assertions) |
| Kundefrontend + brukeradministrasjon + registrering | **93 passerte** (354 assertions) |
| Full suite | **4974 passerte, 113 feilet** (22 777 assertions) |

De 113 feilene er **nøyaktig de samme testnavnene** som den etablerte Enterprise Wiki-baselinen fra
P0 — 0 nye, 0 forsvunne. Ingen Filament-, auth-, security- eller billing-klasse feiler.

F-03 er eksplisitt reverifisert etter oppgraderingen: aktiv intern admin slipper inn; inaktiv
intern admin, `customer_admin`, `super_admin` med `customer_id`, alle `bid_role`-verdier og
QA-flagget avvises; `UserResource` og `CustomerResource` krever intern admin; kundeadministrator
administrerer fortsatt brukere i kundefrontenden.

#### P2 — Dompdf, 29. august 2026

Siste runde. `composer.json` uendret gjennom alle tre.

| | Før | Etter |
|---|---|---|
| `dompdf/dompdf` | v3.1.5 | **v3.1.6** |
| `sabberworm/php-css-parser` | v9.3.0 | v9.4.0 |
| Advisories | 6 i 1 pakke | **0** |

Kommando:

```
composer update dompdf/dompdf --with-all-dependencies
```

Ingen Guzzle-kapping nødvendig her — dry-run ga **0 installs, 2 updates, 0 removals**, og hverken
Laravel, Filament, Guzzle, Livewire eller Symfony ble berørt. `sabberworm/php-css-parser` er
Dompdfs CSS-parser og eneste transitive avhengighet som måtte flyttes.

##### De seks advisories — alle lukket

Alle seks var `<3.1.6`, altså lukket av samme patch.

| CVE | Severity | Berørt funksjon | Status |
|---|---|---|---|
| CVE-2026-56722 | Middels | Lokal fillesing via SVG kodet som data-URI | **Lukket** |
| CVE-2026-59943 | Middels | Innebygde SVG-bilder kan avsløre filers og katalogers eksistens | **Lukket** |
| CVE-2026-59942 | Middels | DoS via ressursuttømming med overdimensjonerte bitmaps | **Lukket** |
| CVE-2026-59941 | Middels | Ukontrollert ressursforbruk basert på deklarerte BMP-dimensjoner | **Lukket** |
| CVE-2026-55555 | Lav | File existence oracle via `@font-face`-deklarasjon | **Lukket** |
| CVE-2026-55554 | Lav | Omgåelse av chroot-validering | **Lukket** |

##### Hvordan Procynia faktisk bruker Dompdf

To steder, begge i produksjon:

1. **`app/Services/Ai/DocumentPreviewService.php`** — `generateDocxPreviewPdf()` (linje 153–200).
   En kundeopplastet `.docx` konverteres til HTML med PHPWord, og HTML-en sendes til Dompdf.
   Kalles fra `app/Http/Controllers/App/AiController.php` (`app.ai.documents.preview`).
2. **`config/cashier.php:107`** — Cashiers `DompdfInvoiceRenderer` for Stripe-fakturaer. Innholdet
   kommer fra Stripe, ikke fra kunden.

Preview-veien er den relevante angrepsflaten: **kundekontrollert dokumentinnhold ender i HTML-en
som rendres**, og det er nøyaktig `<img>`, SVG, `@font-face` og CSS `url()` de seks advisories
handler om.

Konfigurasjonen som settes (`DocumentPreviewService.php:178–181`):

```php
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);   // eksterne ressurser avslått
$options->set('defaultFont', 'DejaVu Sans');
```

Ingen eksplisitt `chroot` settes noe sted i `app/` eller `config/` — Dompdfs egen default gjelder.
`isRemoteEnabled = false` stenger remote-URL-klassen, men ikke data-URI-SVG eller
`@font-face`-orakler; det er patchen som lukker dem.

Eksisterende ressursbegrensning er uendret: opplastede dokumenter er validert til
`max:20480` (20 MB) i `AiController.php:347` og `:598`.

##### Verifisert PDF-oppførsel

Ny regresjonstest: `tests/Feature/Security/DompdfPreviewFileAccessTest.php`. Den bruker nøyaktig
samme `Options` som `DocumentPreviewService`, og pinner to egenskaper:

- rendereren produserer fortsatt en gyldig PDF (`%PDF-`-header)
- en lokal fil kan ikke trekkes inn i PDF-en via fem referansevektorer:
  `<img src="file://…">`, `<img src="/absolutt/sti">`, `@font-face src:url(file://…)`,
  `background-image:url(file://…)` og `<svg><image href="file://…">`

Testen skriver en ufarlig fixture med en kanariestreng til en midlertidig fil og verifiserer at
strengen aldri havner i PDF-bytene. Ingen ekte eller sensitive filer brukes.

Resultat: **7 passerte**. Ingen av de fem vektorene lekket.

#### Composer-sikkerhet — samlet resultat

```
før:  47 advisories / 16 pakker  (9 høye)
etter: 0 advisories
```

| Runde | Gruppe | Advisories etter | Nøkkelversjoner |
|---|---|---|---|
| P0 | Laravel-gruppen | 47 → 17 | `laravel/framework` v13.2.0 → v13.29.0, Symfony 8.0.x → 8.1.x, `league/commonmark` 2.8.2 → 2.10.0, `guzzlehttp/guzzle` 7.10.0 → 7.15.5 |
| P1 | Filament-gruppen | 17 → 6 | `filament/*` v5.4.1 → v5.7.6, `symfony/html-sanitizer` v8.0.7 → v8.1.1, `livewire/livewire` v4.2.1 → v4.4.2 |
| P2 | Dompdf | 6 → **0** | `dompdf/dompdf` v3.1.5 → v3.1.6, `sabberworm/php-css-parser` v9.3.0 → v9.4.0 |

**`composer.json` er byte-identisk gjennom alle tre rundene** — ingen constraint måtte endres.
Ingen forlatte pakker. Alle ni opprinnelige høye advisories er lukket.

Gjenstående tiltak på dette området er ikke en oppgradering, men automatisering:
`composer audit` kjøres ikke i CI, så en ny advisory vil ikke fanges opp av seg selv.


### npm — F-11 LUKKET

**Status: LUKKET.** Fire oppgraderingsrunder gjennomført 29. august 2026: P0 axios, P1
concurrently, P2 postcss/babel og P3 vite/esbuild. `npm audit` er ren. Node v18.15.0, npm 9.5.0.

| | Utgangspunkt | Etter P0 | Etter P1 | Etter P2 | Etter P3 |
|---|---|---|---|---|---|
| Advisories | 43 | 13 | 11 | 4 | **0** |
| Berørte pakker | 10 | 7 | 5 | 2 | **0** |
| Kritiske | 2 | 2 | 0 | 0 | **0** |
| Sårbare pakker i nettleseren | 1 (`axios`) | 0 | 0 | 0 | **0** |

**Alle 43 advisories lukket.** `package.json` måtte endres én gang i hele løpet — Vite-constraintet
i P3. Alt annet ble løst innenfor eksisterende constraints.

> **Korreksjon av førsteutkastet.** Den opprinnelige auditen slo fast at «ingen av dem sendes til
> nettleseren» og at all risiko lå i byggekjeden. **Det var feil for `axios`.** Selv om `axios`
> står under `devDependencies`, importeres den i `resources/js/bootstrap.js`, eksponeres som
> `window.axios`, brukes i `CustomerAppLayout.jsx` og `Pages/App/AI/Show.jsx`, og finnes igjen i
> den bygde bundlen. Den trekkes dessuten inn som peer-avhengighet av `@inertiajs/core`
> (`^1.13.2`) og `laravel-precognition` (`^1.4.0`), som begge er runtime. Skillet
> `dependencies`/`devDependencies` sier ingenting om hva som havner i nettleseren — Vite bundler
> etter importgrafen.

#### P0 — axios-gruppen, 29. august 2026

Kommando:

```
npm update axios
```

`npm update <pakke>` respekterer constraintet i `package.json` (`^1.11.0`) og skriver kun
lockfilen. Alternativet `npm install axios@^1.18.0 --save-dev` ville omskrevet `package.json`
unødvendig, siden dagens constraint allerede dekker en trygg versjon.

Planen ble først beregnet i en kopi utenfor repoet med `--package-lock-only`, for å se den
nøyaktige lockfile-effekten før noe ble skrevet. (`npm update axios --dry-run` rapporterte
«added 69 packages» — det var npm som avstemte `node_modules` mot plattformbinærer, ikke
lockfile-endringer.)

Faktisk resultat: **6 endret, 2 lagt til, 0 fjernet.**

| Pakke | Før | Etter | Direkte/transitiv | Årsak |
|---|---|---|---|---|
| `axios` | 1.13.6 | **1.20.0** | direkte (devDep) + peer fra `@inertiajs/core` | målpakken |
| `follow-redirects` | 1.15.11 | **1.16.0** | transitiv ← `axios` | `axios@1.18+` krever `^1.16.0` |
| `form-data` | 4.0.5 | **4.0.6** | transitiv ← `axios` | innenfor `^4.0.5` |
| `proxy-from-env` | 1.1.0 | 2.1.0 | transitiv ← `axios` | `axios@1.18+` krever `^2.1.0` |
| `es-object-atoms` | 1.1.1 | 1.1.2 | transitiv | patch |
| `hasown` | 2.0.2 | 2.0.4 | transitiv | patch |
| `https-proxy-agent` | — | 5.0.1 | **ny**, transitiv ← `axios` | ny avhengighet i axios 1.18+ |
| `agent-base` | — | 6.0.2 | **ny**, transitiv | under `https-proxy-agent` |

Ingen av gruppene som er reservert til senere runder ble berørt: `vite`, `esbuild`, `postcss`,
`nanoid`, `concurrently`, `shell-quote` og `@babel/core` står uendret.

##### Advisory-status

| Pakke | Før | Etter |
|---|---|---|
| `axios` | 28 | **0** |
| `form-data` | 1 | **0** |
| `follow-redirects` | 1 | **0** |

##### Verifikasjon

`npm run build` grønn: 941 moduler, 5,95 s. Bundlen vokste fra 1 709,44 kB til 1 723,90 kB
(405 → 410 kB gzip) — forventet for en nyere axios. Bundlestørrelsesadvarselen er den samme
ytelsesadvarselen som før og ble ikke rørt.

`npm run test:unit`: **43 passerte, 0 feilet** — identisk med baselinen.

Playwright mot den bygde bundlen: **14 passerte** (`auth`, `app-navigation`, `billing`,
`ai-answer-markdown-table`) — innlogging, kundefrontend, AI-arbeidsflate, Filament-innlogging,
autorisasjonssperrer og Wiki-svarrendering.

Målrettet nettleserkontroll av selve axios-oppgraderingen:

```
window.axios.VERSION = 1.20.0
xsrfCookieName = XSRF-TOKEN   xsrfHeaderName = X-XSRF-TOKEN
X-Requested-With = XMLHttpRequest   XSRF-TOKEN-cookie til stede
axios.patch('/app/notifications/read-all') -> HTTP 200
```

Det siste er den faktiske kodeveien fra `CustomerAppLayout.jsx` (`mark_all_read_url`), og en 200 —
ikke 419 — viser at CSRF fungerer gjennom den nye axios-versjonen. Ingen kodeendring var
nødvendig: axios 1.20 fester fortsatt XSRF-token automatisk for same-origin
(`withXSRFToken = 1` når `isURLSameOrigin`), og standardnavnene matcher Laravels.

De Node-spesifikke avhengighetene når fortsatt ikke nettleseren — `follow-redirects`,
`https-proxy-agent` og `agent-base` har 0 treff i den bygde bundlen.

**Driftsmerknad:** en Vite dev-server som kjørte da oppgraderingen ble gjort, serverer fortsatt
axios 1.13.6 fra sin `node_modules/.vite`-cache. Den re-optimaliserer ved omstart. Produksjons­bygget
er upåvirket.

#### P1 — concurrently-gruppen, 29. august 2026

Kommando:

```
npm update concurrently
```

Samme prinsipp som P0: `npm update <pakke>` respekterer `^9.0.1` i `package.json` og skriver kun
lockfilen.

Faktisk resultat: **2 endret, 0 lagt til, 0 fjernet** — den minste endringen i hele F-07/F-11-arbeidet.

| Pakke | Før | Etter | Direkte/transitiv | Årsak |
|---|---|---|---|---|
| `concurrently` | 9.2.1 | **9.2.4** | direkte (devDep) | målpakken |
| `shell-quote` | 1.8.3 | **1.9.0** | transitiv ← `concurrently` | `concurrently` pinner den eksakt |

Ingen andre pakker ble berørt — `postcss`, `nanoid`, `@babel/core`, `vite`, `esbuild`, `axios`,
`form-data` og `follow-redirects` står uendret.

##### Advisory-status

| Advisory | Severity | Berørt | Status |
|---|---|---|---|
| GHSA-w7jw-789q-3m8p — `quote()` escaper ikke linjeskift i `.op`-verdier | **Kritisk** | `>=1.1.0 <=1.8.3` | **Lukket** |
| GHSA-395f-4hp3-45gv — kvadratisk DoS i `parse()` | Høy | `<=1.8.4` | **Lukket** |

`concurrently` hadde ingen egne advisories; den arvet severity fra `shell-quote`. Begge pakkene er
nå borte fra `npm audit`, og **det finnes ikke lenger noen kritisk npm-advisory i prosjektet**.

##### Faktisk bruk og verifikasjon

`concurrently` brukes ett sted: `composer dev` i `composer.json`, som starter fire prosesser
parallelt.

```
npx concurrently -c "#93c5fd,#c4b5fd,#fb7185,#fdba74" \
  "php artisan serve" \
  "php artisan queue:work --queue=… --tries=1 --timeout=0" \
  "php artisan pail --timeout=0" \
  "npm run dev" \
  --names=server,queue,logs,vite --kill-others
```

Ingen npm-script bruker den, og ingen Procynia-kode importerer `shell-quote` — den er ren
dev-tooling og når aldri nettleseren.

`composer dev` ble **ikke** kjørt, fordi en Vite dev-server allerede kjørte på `[::1]:5173` og
`composer dev` ville startet en konkurrerende instans over brukerens aktive miljø. I stedet ble
`concurrently` kjørt direkte med samme flagg-oppsett og ufarlige kommandoer:

```
[server] SERVER-OK
[queue]  QUEUE-OK
[logs]   LOGS-OK
--> Sending SIGTERM to other processes..
[vite] … exited with code SIGTERM
```

Det verifiserer det `composer dev` faktisk er avhengig av: fire parallelle prosesser, `--names`,
fargeoppsettet og `--kill-others`. Ingen prosess ble liggende igjen, og dev-serveren på 5173
(PID 14192) var urørt etterpå.

`npm run test:unit` 43 passerte, `npm run build` grønn (6,53 s) med identisk bundle-hash som før —
som forventet, siden dev-tooling ikke inngår i bundlen.

**Merk:** `concurrently@9.2.4` pinner fortsatt `shell-quote` til en eksakt versjon (`1.9.0`).
En framtidig `shell-quote`-advisory vil derfor på nytt kreve at `concurrently` oppgraderes — den
kan ikke patches transitivt alene.

#### P2 — postcss / nanoid / @babel/core, 29. august 2026

Kommando:

```
npm update postcss @babel/core
```

`nanoid` fulgte transitivt under `postcss` (som krever `^3.3.11`) — den ble **ikke** lagt til i
`package.json` for å tvinge versjonen.

Faktisk resultat: **18 endret, 0 lagt til, 0 fjernet.**

| Pakke | Før | Etter | Direkte/transitiv | Årsak |
|---|---|---|---|---|
| `postcss` | 8.5.8 | **8.5.26** | direkte (devDep) | målpakke; `^8.5.6` dekket |
| `nanoid` | 3.3.11 | **3.3.18** | transitiv ← `postcss` | fulgte automatisk |
| `@babel/core` | 7.29.0 | **7.29.7** | transitiv ← `@vitejs/plugin-react` | målpakke |
| 15 øvrige `@babel/*` | 7.27–7.29.2 | 7.29.7 / 7.29.8 | transitive | Babel-monorepoet slippes i lås |

`vite` (5.4.21) og `esbuild` (0.21.5) står **uendret**, likeså `axios`, `concurrently` og
`shell-quote`.

##### Advisory-status

| Advisory | Severity | Berørt | Status |
|---|---|---|---|
| GHSA-6g55-p6wh-862q — vilkårlig fillesing via `sourceMappingURL` i CSS-kommentar | Høy | `<=8.5.11` | **Lukket** |
| GHSA-r28c-9q8g-f849 — path traversal ved auto-lasting av forrige source map | Høy | `<=8.5.17` | **Lukket** |
| GHSA-fxqj-rqcc-2cmp — ufullstendig fiks av GHSA-6g55 når `from` ikke er satt | Moderat | `<=8.5.22` | **Lukket** |
| GHSA-qx2v-qp2m-jg93 — XSS via uescapet `</style>` i stringify-output | Moderat | `<8.5.10` | **Lukket** |
| GHSA-2v37-7h3g-55p8 — `nanoid` egendefinerte generatorer kan loope når size er 0 | Høy | `<3.3.18` | **Lukket** |
| GHSA-28wg-ghj8-5hjv — `nanoid` ikke-sikre generatorer kan loope ved negativ size | Høy | `<3.3.16` | **Lukket** |
| GHSA-4x5r-pxfx-6jf8 — `@babel/core` vilkårlig fillesing via `sourceMappingURL` | Lav | `<=7.29.0` | **Lukket** |

##### Eksponering (bekreftet på nytt)

Alle sju er build-time. Fellesnevneren i seks av dem er `sourceMappingURL`-drevet fillesing, som
forutsetter angriperkontrollert CSS- eller JS-kilde i builden. Det finnes ikke her:

- Vite bygger tre faste inputs: `resources/css/app.css`, `resources/css/filament/admin/theme.css`
  og `resources/js/app.js`.
- Det finnes **to** CSS-filer i `resources/`, begge prosjekteide, uten `sourceMappingURL`.
- Sourcemaps er ikke slått på i `vite.config.js`.
- De 87 JS/JSX-filene er prosjekteide, og det finnes ingen egen Babel-konfigurasjon —
  `@vitejs/plugin-react` bruker sine defaults.
- `postcss.config.js` laster kun `autoprefixer`.

Kundedata blir aldri rå CSS- eller JS-input under bygget. XSS-advisoryen i PostCSS' stringify-output
gjelder tilsvarende CSS vi selv eier.

##### Verifikasjon

`npm run build` grønn: 946 moduler, 6,58 s — og **byte-identisk output** med før oppgraderingen:

```
public/build/assets/app-DZjBN_zQ.css      116.46 kB │ gzip:  20.10 kB
public/build/assets/theme-bvdLe4SB.css    648.29 kB │ gzip:  67.66 kB
public/build/assets/app-DByMP_-G.js     1,723.90 kB │ gzip: 409.56 kB
```

Samme filnavn-hasher, samme størrelser, samme modultall. Det er sterkere bevis enn en grønn build
alene: PostCSS 8.5.26 produserer identisk CSS (både hoved-CSS og Filament-temaet), og
Babel 7.29.7/7.29.8 produserer identisk JSX-transformasjon. Ingen PostCSS-exception, ingen
manglende plugin, ingen Babel-inkompatibilitet. Eneste advarsel er den kjente
bundlestørrelsesadvarselen, som er uendret og utenfor scope.

`npm run test:unit`: 43 passerte.

E2E ble ikke kjørt, og det er godt begrunnet her: P2 flytter kun build-time-pakker, og den
**identiske bundle-hashen** viser at nettleseren mottar nøyaktig de samme bytene som før. Runtime-
dependency-grafen er uendret. Prosjektet har ingen screenshot- eller snapshot-baserte
UI-regresjonstester, og identisk CSS-output gjør visuell regresjon utelukket i praksis.

Kjørt på Node v18.15.0 — P2 krever ingen Node-oppgradering.

#### P3 — vite / esbuild, 29. august 2026

Siste runde, og den eneste i hele F-07/F-11-arbeidet som krevde en `package.json`-endring.

| Pakke | Før | Etter |
|---|---|---|
| `vite` | 5.4.21 | **6.4.3** |
| `esbuild` | 0.21.5 | **0.25.12** |

##### Versjonsvalg

Alle tre Vite-advisories gjelder `<=6.4.2`, så minste trygge versjon er **6.4.3**. Den er samtidig
den nyeste 6.x-utgivelsen — det finnes ingen andre 6.x over 6.4.3 — så valget er entydig og
innebærer ingen ekstra breaking changes utover selve major-hoppet 5 → 6. Vite 7 og 8 ble ikke
vurdert som mål: `vite@8` krever Node `^20.19 || >=22.12`, som utviklerhosten (Node 18.15.0) ikke
oppfyller, og bryter peer-kravene til `laravel-vite-plugin`.

##### Kompatibilitetsgater — alle kontrollert før mutasjon

| Gate | Krav | Faktisk | OK |
|---|---|---|---|
| Node | `vite@6.4.3` engines: `^18.0.0 \|\| ^20.0.0 \|\| >=22.0.0` | Node v18.15.0 | ja |
| `laravel-vite-plugin@1.3.0` | peer `vite: ^5.0.0 \|\| ^6.0.0` | 6.4.3 | ja |
| `@vitejs/plugin-react@4.7.0` | peer `vite: ^4.2 \|\| ^5 \|\| ^6 \|\| ^7` | 6.4.3 | ja |
| `@tailwindcss/vite@4.2.2` | peer `vite: ^5.2 \|\| ^6 \|\| ^7 \|\| ^8` | 6.4.3 | ja |
| esbuild | advisory gjelder `<=0.24.2`; `vite@6.4.3` krever `^0.25.0` | 0.25.12 | ja |

Ingen plugin måtte oppgraderes, og verken `--force`, `--legacy-peer-deps` eller
`engine-strict=false` ble brukt.

##### Breaking changes — påvirker de Procynia?

`vite.config.js` bruker kun stabile API-er som er uendret fra Vite 5 til 6: `defineConfig`,
plugin-arrayet (`laravel`, `react`, `tailwindcss`) og `server.watch.ignored`. Ingen av de
Vite 6-relevante bruddene treffer noe Procynia faktisk bruker — ingen SSR-oppsett, ingen Sass,
ingen egen `resolve.conditions`, ingen manuell manifest-håndtering (den eies av
`laravel-vite-plugin`), ingen egendefinerte env-variabler i config.

> **Krever dagens Procynia-konfigurasjon kodeendringer for Vite 6? Nei.**
> `vite.config.js` er byte-identisk før og etter (`4cbc68db…`).

##### Kommando

```
# package.json: "vite": "^5.4.14" -> "^6.4.3"
npm install
```

Resultat: **25 endret, 8 lagt til, 0 fjernet.** Endringene er `vite`, `esbuild` og de 23
`@esbuild/*`-plattformbinærene som følger esbuild i lås. Nye pakker er tre nye
`@esbuild/*`-plattformmål samt `tinyglobby` med nestede `fdir`/`picomatch` — nye avhengigheter i
Vite 6. Ingen andre pakker flyttet: `axios`, `concurrently`, `shell-quote`, `postcss`, `nanoid`,
`@babel/core`, React, `laravel-vite-plugin` og `@vitejs/plugin-react` står uendret.

##### Advisory-status

| Advisory | Severity | Berørt | Gjelder | Status |
|---|---|---|---|---|
| GHSA-fx2h-pf6j-xcff — `server.fs.deny`-omgåelse på Windows alternate paths | Høy | `<=6.4.2` | dev-server | **Lukket** |
| GHSA-4w7w-66w2-5vf9 — path traversal i optimized deps `.map`-håndtering | Moderat | `<=6.4.1` | dev-server | **Lukket** |
| GHSA-v6wh-96g9-6wx3 — `launch-editor` NTLMv2-hashlekkasje via UNC på Windows | Moderat | `<=6.4.2` | dev-server | **Lukket** |
| GHSA-67mh-4wv8-2f99 — esbuild lar enhver nettside sende forespørsler til dev-serveren | Moderat | `<=0.24.2` | dev-server | **Lukket** |

Alle fire gjaldt dev-serveren, ikke produksjonsbygget. Eksponeringen var uansett begrenset:
`vite.config.js` setter ingen `server.host`, og dev-serveren binder til localhost — bekreftet ved
at Vite 6 startet med «Network: use --host to expose» og lyttet på `[::1]`. To av dem er dessuten
Windows-spesifikke, og plattformen her er macOS/Docker.

##### Verifikasjon

**Produksjonsbygg** grønt på `vite v6.4.3`: 939 moduler (fra 946), 6,35 s.

```
public/build/assets/app-62Ic0QdX.css      116.44 kB │ gzip:  20.10 kB
public/build/assets/theme-C_ALGDhL.css    647.89 kB │ gzip:  67.65 kB
public/build/assets/app-BNBsOyaE.js     1,730.39 kB │ gzip: 411.50 kB
```

Asset-hasher endret seg, som forventet ved et major-hopp. Størrelsene er praktisk talt uendret:
hoved-CSS −0,02 kB, Filament-temaet −0,40 kB, JS +6,49 kB. Ingen feil eller
config-deprecation-advarsler; eneste advarsel er den kjente bundlestørrelsesadvarselen.

**Manifest** (`public/build/manifest.json`) har fortsatt alle tre entrypoints med `isEntry: true` —
`resources/css/app.css`, `resources/css/filament/admin/theme.css` og `resources/js/app.js`. Laravel-
integrasjonen trengte ingen endring.

**CSS/PostCSS:** begge CSS-assetene genereres, `autoprefixer` kjørte uten feil.
**React:** JSX bygges av `@vitejs/plugin-react@4.7.0` under Vite 6 uten feil.
**Unit-tester:** 43 passerte.

**Dev-server og HMR** ble testet uten å forstyrre brukerens miljø. En Vite 5-prosess kjørte allerede
på `[::1]:5173` (PID 14192); den ble ikke brukt som bevis og ikke rørt. I stedet ble Vite 6 startet
midlertidig på port 5199:

```
VITE v6.4.3  ready in 407 ms
➜  Local:   http://localhost:5199/
➜  Network: use --host to expose
LARAVEL v13.29.0  plugin v1.3.0
```

`/@vite/client` svarte 200 med `HMRClient`, `createHotContext` og `hot.accept` (HMR initialiserer),
`/@react-refresh` svarte 200 (React-pluginen lastet), og `/resources/js/app.js` svarte 200. Ingen
config-deprecation eller feil. Testserveren ble stoppet ryddig, port 5199 er fri, og PID 14192
overlevde.

`laravel-vite-plugin` skriver `public/hot` når dev-serveren starter, så filen ble midlertidig
overskrevet til `:5199`. Den ble gjenopprettet byte-identisk
(`c5e5af6ff91a3c183bd88d3e63f105ef7f6cc7a3`, innhold `http://[::1]:5173`) og verifisert med checksum.

**E2E:** 14 Playwright-tester passerte mot Vite 6-bundlen (`app-BNBsOyaE.js`) — `auth`,
`app-navigation`, `billing` og `ai-answer-markdown-table`. Det dekker app-lasting, navigasjon,
innlogging, React-hydrering, autorisasjonssperrer og axios-kommunikasjon.

##### Produksjonsbygget

> **Krever Vite 6 endringer i produksjonsoppsettet? Nei.**

`docker/production/Dockerfile` bygger frontend i en egen stage på `node:22-bookworm-slim`, kjører
`npm ci --no-audit --no-fund` mot lockfilen og deretter `npm run build` med en gate på
`test -f public/build/manifest.json`. Node 22 dekker Vite 6 med god margin, lockfilen er allerede
oppdatert, og manifestet genereres som før. Ingen produksjonsimage ble bygget eller publisert.

Merk at produksjon bygger på Node 22 mens utviklerhosten kjører Node 18 — Vite 6 støtter begge.
Det er derfor Node 18 EOL er et utviklermiljø-spørsmål, ikke en produksjonsblokker.

#### npm-sikkerhet — samlet resultat

```
før:  43 advisories / 10 pakker  (2 kritiske, 5 høye på pakkenivå)
etter: 0 advisories
```

| Runde | Gruppe | Advisories etter | Nøkkelversjoner |
|---|---|---|---|
| P0 | axios | 43 → 13 | `axios` 1.13.6 → 1.20.0, `follow-redirects` → 1.16.0, `form-data` → 4.0.6 |
| P1 | concurrently | 13 → 11 | `concurrently` 9.2.1 → 9.2.4, `shell-quote` 1.8.3 → 1.9.0 |
| P2 | postcss/babel | 11 → 4 | `postcss` 8.5.8 → 8.5.26, `nanoid` → 3.3.18, `@babel/core` → 7.29.7 |
| P3 | vite/esbuild | 4 → **0** | `vite` 5.4.21 → 6.4.3, `esbuild` 0.21.5 → 0.25.12 |

`package.json` ble endret **én gang** i hele løpet: Vite-constraintet i P3. `vite.config.js`,
frontend-kode og PHP-kode er urørt gjennom alle fire rundene.

**Node 18 — oppfølgingspunktet er delvis løst 29. august 2026.** Dette er livssyklus/support, ikke
en advisory, og det var aldri en del av F-11.

| | Status |
|---|---|
| **Prosjektbaseline** | **LØST.** Repoet er standardisert på Node 22: `"engines": { "node": ">=22 <23" }` i `package.json`, `.nvmrc` = `22`, CI på 22, produksjonsbygg på `node:22-bookworm-slim` |
| **Denne utviklerhosten** | **IKKE LØST.** Maskinen kjører fortsatt fysisk Node v18.15.0 og må oppgraderes manuelt av utvikleren |

Skillet er viktig: repoet uttrykker nå kravet konsistent på tvers av lokal utvikling, CI og
produksjon, men en maskin blir ikke oppgradert av en `engines`-blokk. Fram til hosten byttes vil
`npm` gi `EBADENGINE` med `required: { node: '>=22 <23' }` — det er beviset på at kravet er
uttrykt, ikke en feil. **Node 18 er ikke lenger en støttet Procynia-versjon.**

#### CI-gate — implementert 29. august 2026

Begge sporene er nå automatisert i `.github/workflows/dependency-audit.yml`.

**Dette var repoets første CI-oppsett.** Kartleggingen fant ingen `.github/`, `.gitlab-ci.yml`,
`azure-pipelines.yml`, `Jenkinsfile` eller annen pipeline — dependency-auditene hadde altså aldri
kjørt annet enn manuelt. Plattformvalget er ikke en antakelse: `origin` peker på
`github.com/GerhardNaess/Procynia`, og ingen dokumentasjon nevner en annen CI-plattform.

| | |
|---|---|
| Trigger | `pull_request`, `push` til `main` |
| Jobber | `composer-audit`, `npm-audit` — parallelle og uavhengige, så det er umiddelbart synlig hvilket økosystem som brøt |
| PHP | 8.4, som `docker/php/Dockerfile` |
| Node | 22, som frontend-stagen i `docker/production/Dockerfile` |

Policy: **én advisory feiler builden.** Ingen ignore-liste, ingen severity-terskel, ingen
`continue-on-error`, ingen `|| true`. Gaten installerer fra lockfilene og oppdaterer aldri
avhengigheter — den skal oppdage problemet, ikke mutere seg ut av det.

To detaljer som var nødvendige for at gaten faktisk virker:

- `setup-php` må eksplisitt laste `bcmath`, `gd`, `intl` og `zip`. De er ikke i standardsettet, men
  kreves av `moneyphp/money`, `phpoffice/phpword`, `filament/support` og `openspout/openspout`;
  uten dem stopper `composer install` på platform-krav før auditen kjører.
- `composer install` kjøres med `--no-scripts`, siden `package:discover` og `filament:upgrade`
  ellers ville krevd en bootbar app med `APP_KEY`. Installasjonen beholdes fordi den samtidig
  beviser at lockfilen fortsatt lar seg installere.

Bevisst utsatt til egne beslutninger: scheduled/cron-scan (advisory-databaser endrer seg uten at
koden gjør det) og Dependabot/Renovate. Deteksjon først, automatisk oppgradering senere.

#### Advisory-oversikt (utgangspunkt før P0)

| Pakke | Installert | Severity | Advisories | Første trygge | Direkte/transitiv |
|---|---|---|---|---|---|
| `axios` | 1.13.6 | Høy | 28 | **1.18.0** (minor) | direkte (devDep) + via `@inertiajs/core` |
| `postcss` | 8.5.8 | Høy | 4 | **8.5.23** (patch) | direkte (devDep) |
| `vite` | 5.4.21 | Høy | 3 | **6.4.3** (major) | direkte (devDep) |
| `nanoid` | 3.3.11 | Høy | 2 | **3.3.18** (patch) | transitiv ← `postcss` |
| `shell-quote` | 1.8.3 | **Kritisk** | 2 | **1.9.0** | transitiv ← `concurrently` (eksakt pin `1.8.3`) |
| `form-data` | 4.0.5 | Høy | 1 | **4.0.6** (patch) | transitiv ← `axios` |
| `follow-redirects` | 1.15.11 | Moderat | 1 | **1.16.0** | transitiv ← `axios` |
| `esbuild` | 0.21.5 | Moderat | 1 | **0.25.x** (via vite 6) | transitiv ← `vite` |
| `@babel/core` | 7.29.0 | Lav | 1 | **7.29.6** (patch) | transitiv ← `@vitejs/plugin-react` |
| `concurrently` | 9.2.1 | Kritisk | 0 egne | **9.2.4** (patch) | direkte (devDep) — arver fra `shell-quote` |

#### Runtime kontra byggekjede

Verifisert mot den faktisk bygde bundlen (`public/build/assets/*.js`):

| Pakke | Klassifisering | Bevis |
|---|---|---|
| `axios` | **runtime i browser** | finnes i bundlen; `window.axios` brukes fra to sider |
| `form-data`, `follow-redirects` | build/transitiv, **ikke i bundlen** | 0 treff i `public/build`; brukes kun av axios' Node-adapter |
| `postcss`, `nanoid` | build-time | CSS-prosessering under `vite build` |
| `vite`, `esbuild` | build + dev-server | ikke i bundlen |
| `shell-quote`, `concurrently` | dev tooling | kjøres av `composer dev` |
| `@babel/core` | build-time | JSX-transform via `@vitejs/plugin-react` |

Den bygde axios-koden bruker **XMLHttpRequest-adapteren** (10 treff, ingen Node-http-adapter). De
axios-advisories som gjelder Node-adapteren — NO_PROXY/SSRF, `Proxy-Authorization`-lekkasje ved
redirect, `maxBodyLength`/`maxContentLength`, HTTP/2 — treffer derfor **ikke** nettleserbundlen.
Det som treffer er prototype-pollution-gadgetene: `withXSRFToken`-lekkasje, header-injeksjon,
`parseReviver`-respons­tampering, `validateStatus`-omgåelse og config-merge-gadgetene.

#### Faktisk Procynia-eksponering

| Pakke | Eksponering | Begrunnelse |
|---|---|---|
| `axios` | **Eksponert** | Sendes til nettleseren og bærer all XHR i kundefrontenden. Prototype-pollution-gadgetene forutsetter en pollution-primitiv et annet sted, men XSRF-token-lekkasjen er direkte relevant siden Procynia bruker XSRF |
| `shell-quote` / `concurrently` | Begrenset | Kun utviklermaskin via `composer dev`. Kritisk oppstrøms, men input er utviklerens egen |
| `vite` / `esbuild` | Begrenset | Dev-serveren binder til `http://[::1]:5173` (se `public/hot`); ingen `server.host` i `vite.config.js`, altså ikke nettverkseksponert. esbuild-advisoryen forutsetter en nåbar dev-server. Vite-advisoryen `server.fs.deny`-omgåelse gjelder **Windows**; plattformen her er macOS/Docker |
| `postcss` / `nanoid` | Begrenset | Build-time. XSS-advisoryen gjelder CSS-stringify-output, og input er Tailwind og egne filer — ikke brukerinnhold. `sourceMappingURL`-lesing forutsetter angriperkontrollert CSS |
| `form-data` / `follow-redirects` | Ikke funnet eksponering | Ikke i bundlen; kun axios' Node-adapter, som Procynia ikke bruker |
| `@babel/core` | Ikke funnet eksponering | Build-time filelesing via `sourceMappingURL`, forutsetter angriperkontrollerte kildefiler |

Markdown- og Wiki-visningen bruker `react-markdown` og `remark-gfm` — **ingen av dem har
advisories**. Det finnes én `dangerouslySetInnerHTML` (`Pages/App/AI/KnowledgeBase/Show.jsx:1897`,
`table_html`), men ingen av de 43 advisories går den veien; den vurderes ikke videre her.

#### Oppgraderingsgrupper

| | Gruppe | Advisories | Hopp | `package.json`-endring | Status |
|---|---|---|---|---|---|
| **P0** | `axios` 1.13.6 → 1.20.0 (dro `form-data` 4.0.6 + `follow-redirects` 1.16.0) | **30** | minor | nei, `^1.11.0` dekket | **GJENNOMFØRT** |
| **P1** | `concurrently` 9.2.1 → 9.2.4 (dro `shell-quote` 1.9.0) | **2** (1 kritisk) | patch | nei, `^9.0.1` dekket | **GJENNOMFØRT** |
| **P2** | `postcss` 8.5.8 → 8.5.26 (dro `nanoid` 3.3.18) + `@babel/core` 7.29.7 | **7** | patch | nei | **GJENNOMFØRT** |
| **P3** | `vite` 5.4.21 → 6.4.3 (dro `esbuild` 0.25.12) | **4** | major | **ja**, `^5.4.14` → `^6.4.3` | **GJENNOMFØRT** |

Alle fire rundene er gjennomført. **`npm audit` er ren — 0 advisories.**

#### Om `npm audit fix`

`npm audit fix` uten `--force` ville løst alt utenom `vite`/`esbuild`. **`npm audit fix --force`
er høy risiko og må ikke kjøres:** den installerer `vite@8.2.2`, som

- krever Node `^20.19.0 || >=22.12.0` — maskinen her kjører **Node v18.15.0**
- bryter `laravel-vite-plugin@1.3.0` (peer `^5.0.0 || ^6.0.0`)
- bryter `@vitejs/plugin-react@4.7.0` (peer opptil `^7.0.0`)

`vite@6.4.3` er den minste trygge versjonen — advisories gjelder `<=6.4.2` — og den er kompatibel
med begge plugins og med Node 18 (`engines: ^18.0.0 || ^20.0.0 || >=22.0.0`). Vite 8 er derfor en
separat, større jobb som også må ta Node-versjonen.

#### Byggebaseline og testdekning

`npm run build` er grønn i dag: vite 5.4.21, 941 moduler, 7,31 s. Én advarsel, som er
ytelsesrelatert og ikke sikkerhet: hovedbundlen er 1 709 kB (405 kB gzip), over Vites 500 kB-grense.
Ikke rørt.

`npm run test:unit` (`node --test resources/js`, 11 testfiler): **43 passerte, 0 feilet.**

Tilgjengelig ved faktisk oppgradering:

| Gruppe | Testbehov |
|---|---|
| P0 axios | `npm run test:unit`; Playwright `tests/e2e/` (auth, app-navigation, billing, ai-answer-markdown-table) — krever kjørende app på `localhost:8080`; manuelt: varsler i `CustomerAppLayout`, AI-krav i `Show.jsx` |
| P1 concurrently | `composer dev` starter fortsatt server + kø + vite |
| P2 postcss | `npm run build`, visuell kontroll av Tailwind-CSS og Filament-tema |
| P3 vite | `npm run build` + `npm run dev` med HMR, asset-lasting, `public/hot`-flyt |

#### Deprecated og Node-versjon

`npm audit` rapporterer ingen deprecated eller abandoned pakker. Separat fra advisories:
**Node v18.15.0 er EOL siden april 2025**, og `package.json` har ingen `engines`-blokk som fester
Node-versjonen. Det er ikke en advisory, men det blokkerer Vite 8 og bør håndteres i egen runde.

### Tiltak
Composer-delen er ferdig (F-07 lukket). **F-11 er delvis lukket:** P0 `axios` og P1 `concurrently`
er gjennomført. Ingen sårbar npm-pakke når nettleseren, og ingen kritiske advisories gjenstår.

Begge sporene er nå ferdige: `composer audit` og `npm audit` er begge rene. Det gjenstående
tiltaket er ikke en oppgradering, men å gjøre dem til CI-gates — i dag kjøres ingen av dem
automatisk, så neste advisory oppdages først ved en manuell gjennomgang.

Separat: **Node-baselinen** er nå standardisert på Node 22 i repoet (se over). Selve
utviklerhosten kjører fortsatt Node 18 og må oppgraderes manuelt.

Uavhengig av begge sporene: `composer audit` og `npm audit` bør inn i CI, ellers oppdages neste
advisory først ved en manuell gjennomgang.

---

## F-08 — Redis-autentisering — LUKKET

### Funn (august 2026)
`docker-compose.yml` — `redis-server --appendonly yes`, ingen `requirepass`. `.env.example` hadde
`REDIS_PASSWORD=null`. Bekreftet ved kjøring før endring:

```
$ redis-cli PING
PONG                      # uten credentials
$ redis-cli CONFIG GET requirepass
(tom)
db0: 922 nøkler           # sesjoner + køer
db1: 11 nøkler            # cache
```

### Hva Redis faktisk brukes til

| Funksjon | Redis? | Connection |
|---|---|---|
| Sessions | ja | `default` (db 0) — `SESSION_DRIVER=redis`, `SESSION_CONNECTION=default` |
| Køer (alle ni) | ja | `default` (db 0) — `QUEUE_CONNECTION=redis` |
| Cache | ja | `cache` (db 1) — `CACHE_STORE=redis` |
| Rate limiting (F-01 login throttle) | ja, indirekte | via cache-store |
| Locks | ja, indirekte | via cache-store |
| Broadcasting | nei | ingen `config/broadcasting.php` i prosjektet |

### Risiko

Porten var bundet til loopback (`127.0.0.1:6380:6379`), så eksponeringen krevde en prosess på verten
eller på Docker-nettet. Men konsekvensen var alvorlig: sesjonsnøkler ligger i db 0, så lesetilgang
gir overtakelse av en innlogget sesjon — inkludert en System Owner. Skrivetilgang til køene betyr å
kunne injisere eller endre jobbpayloads for AI- og Wiki-pipelinen, som refererer kundedokumenter.
Cache/rate-limiting i db 1 betyr at F-01s innloggingsbegrensning kunne nullstilles utenfra.

### Tiltak — implementert 30. august 2026

**Auth-modell: `requirepass`, ikke ACL.** Prosjektet har én applikasjon og én Redis-rolle; ACL-brukere
ville lagt til brukeradministrasjon uten å skille noe som faktisk er skilt. `requirepass` er også det
Azure Managed Redis-modellen tilsvarer (én nøkkel), så lokal og framtidig sky er formlike.

```yaml
redis:
    environment:
        REDIS_PASSWORD: "${REDIS_PASSWORD:?REDIS_PASSWORD must be set — …}"
    command:
        - sh
        - -c
        - exec redis-server --appendonly yes --requirepass "$$REDIS_PASSWORD"
    healthcheck:
        test:
            - CMD-SHELL
            - redis-cli --no-auth-warning -a "$$REDIS_PASSWORD" ping | grep -q PONG
```

`${REDIS_PASSWORD:?...}` gjør Compose **fail-closed**: mangler variabelen, nekter stacken å starte
i stedet for å komme opp med en åpen Redis. Verifisert.

Healthchecken måtte autentisere — uten det ville containeren rapportert unhealthy selv om Redis
fungerte. `--no-auth-warning` holder passordet ute av healthcheck-output.

**Trade-off (bevisst):** passordet gis som argument til `redis-server` og er dermed synlig i
prosesslista *inne i containeren*. Å lese det krever exec-tilgang til containeren, som er et langt
høyere privilegium enn nettverksrekkevidden F-08 handler om. En config-fil-indireksjon ville skjult
det fra `ps`, men koster et entrypoint og en rendret fil uten gevinst mot den trusselmodellen.

**Laravel:** ingen ny config var nødvendig. `config/database.php` leste allerede `REDIS_PASSWORD` på
begge connections (`default` og `cache`). Credentialen når alle ni tjenester gjennom
`env_file: .env`, som var mekanismen som allerede fantes.

**Portekponering:** `docker-compose.prod.yml` fjerner publiseringen helt
(`redis: ports: !override []`). Autentisering erstatter ikke nettverksisolasjon. Lokalt beholdes
`127.0.0.1:6380:6379` for `redis-cli` under feilsøking — loopback, aldri rutbar.

**Runtime-gate (fail-closed):** `RuntimePreflightService::checkRedisAuthentication()`, med i
`ops:runtime-check`. Compose dekker containerbasert Redis; en deploy som peker på ekstern Redis har
ingen slik gate, og da er dette den eneste. Den avviser også placeholderne `null`, `none`, `false`
og blanke verdier — det er nettopp det en kopiert `.env`-linje etterlater, og Redis ville godtatt
strengen `null` som passord.

| Miljø | `REDIS_PASSWORD` | Resultat |
|---|---|---|
| local | tom | WARN (ikke kritisk) |
| production | tom | **FAIL, kritisk** |
| production | `null` (placeholder) | **FAIL, kritisk** |
| production | ekte verdi | PASS |
| production | ulik verdi på `default` og `cache` | **FAIL, kritisk** |

Sjekken returnerer aldri selve credentialen.

### Lokal utvikling — modell A (paritet)

Lokal Redis krever også passord. Det gir samme oppførsel lokalt som i produksjon, og auth testes
kontinuerlig i stedet for kun ved deploy. Kostnaden er én linje i `.env` ved onboarding, dokumentert
i `.env.example` med `openssl rand -base64 32`. `.env.example` inneholder ingen verdi.

### Verifikasjon

```
uten credentials -> NOAUTH Authentication required.
med credentials  -> PONG
```

Data overlevde omstarten (920 nøkler i db 0, appendonly + volum). Laravel-runtime verifisert:
`Redis::connection('default')->ping()` OK, cache write/read/forget OK, sesjoner opprettes fortsatt
over HTTP (DBSIZE vokste, nøkler under `procynia-database-procynia-cache-…`), og alle ni tjenester
kjører uten `NOAUTH` i loggen.

Underveis ble auth-en demonstrert utilsiktet, men nyttig: de seks Wiki-køarbeiderne som ikke var
recreatet krasjet i restart-loop med `NOAUTH` fordi de fortsatt hadde den gamle `.env`-verdien.
De koblet seg altså **ikke** til uten credential — de feilet. Etter recreate kjører alle rent.
Merk for deploy: en `docker compose up -d` som ikke recreater alle Redis-brukende tjenester vil gi
akkurat dette bildet.

`tests/Feature/Security/RedisSecurityConfigTest.php` — 13 tester: at begge connections leser
`REDIS_PASSWORD`, at session-storet bruker en connection med credential, at ingen literal Redis-secret
finnes i sporet config, hele fail-closed-matrisen over, at sjekken ikke returnerer credentialen, at
Compose krever passord og feiler uten, at healthchecken autentiserer, at produksjon ikke publiserer
porten, og at hver Redis-brukende tjeneste arver `.env`.

Regresjon: 107 av 108 tester passerte — session cookie (F-06), security headers (F-05),
login-throttling (F-01), trusted proxy, Stripe-webhook (F-02), køtopologi og Azure-runtimekontrakt.
Den ene feilende (`RuntimeStatusServiceTest > snapshot compiles runtime data`) er i den kjente
baselinen og feiler på en locale-forskjell (`'om 45 minutter'` mot `'in 45 minutes'`), uten relasjon
til Redis.

### Azure

Allerede dekket av kontrakten og IaC: Azure Managed Redis krever TLS og nøkkel, levert som
`REDIS_URL` fra Key Vault (`infra/main.bicep`). Lagene er bevisst adskilte — F-08 gjelder
auth for dagens container-Redis; TLS og private endpoints hører til Azure-migreringen. `checkRedisAuthentication()`
håndterer `REDIS_URL`-formen eksplisitt: bærer URL-en credentialen i userinfo-delen, er et tomt
`password`-felt ikke bevis på manglende auth, og sjekken advarer i stedet for å feile.

---

## Områder gjennomgått uten funn

Dette er like viktig som funnlisten.

### Kundeisolasjon — sterk
Route model binding er **ikke** scoped (`scopeBindings()` brukes ikke, ingen
`resolveRouteBinding()`-overstyringer, ingen globale tenant-scopes). Isolasjonen hviler i stedet på et
konsekvent **re-utledningsmønster**: den rute-bundne modellen forkastes, og entiteten hentes på nytt
gjennom en kundescopet spørring.

`AiController::visibleAiSavedNotice()` (`app/Http/Controllers/App/AiController.php:3775-3784`):
```php
return $this->savedNoticeAccess->visibleQueryFor($user)
    ->where('customer_id', $customerId)
    ->whereNull('archived_at')
    ->whereKey($savedNotice->id)
    ->firstOrFail();
```
Kalles i **alle** 19+ metodene i controlleren. Nøstede bindinger re-utledes gjennom den verifiserte
forelderen, f.eks. `downloadDocument()` (`:670-697`):
```php
$record = $this->visibleAiSavedNotice($request, $savedNotice);
$ownedDocument = $record->aiDocuments()->whereKey($document->id)->firstOrFail();
```

`SavedNoticeAccessService::applyVisibility()` (`app/Services/SavedNoticeAccessService.php:100-145`)
tvinger `where('customer_id', $user->customer_id)` og legger på rollebasert synlighet over det.

Vi skrev et statisk søk som lette etter anti-mønsteret «rute-bundet barnemodell brukt direkte uten
re-utledning». Fire kandidater ble undersøkt manuelt og var alle korrekt beskyttet med en
tilsvarende, gyldig sjekk:

| Sted | Kontroll |
|---|---|
| `KnowledgeBaseController::approveVersion()` `:688` | `abort_unless($version->knowledge_item_id === $record->id, 404)` |
| `KnowledgeBaseController::rejectVersion()` | samme mønster |
| `KnowledgeBaseController::showChunkImage()` `:2584-2587` | `abort_unless($knowledgeItem->customer_id === $customerId, 403)` + `scopedChunk()` |
| `WikiClaimController::sourceDocumentElements()` `:652-653` | `abort_unless($document->customer_id === currentCustomerId(), 404)` |

**En bruker er permanent bundet til én kunde** (`users.customer_id`). Det finnes ingen
kundebytte-funksjon, som fjerner en hel klasse av tenant-forvirringsfeil.

### Filhåndtering — solid
- Alle opplastinger går til `Storage::disk('local')` → `storage/app/private`. **Null** treff på
  `disk('public')` i `app/`.
- `nginx location /storage/` peker på `storage/app/public/` — en annen katalog. Ingen
  `public/storage`-symlink finnes.
- **Kundedokumenter er ikke tilgjengelige direkte fra webserveren.** Nedlasting går alltid gjennom en
  autorisert controller.
- Lagrede filnavn genereres som `Str::ulid().'.'.$ext` via `putFileAs()` — ingen path traversal fra
  brukerkontrollerte filnavn.
- Validering: `mimes:pdf,docx,xlsx` + `max:20480` (20 MB). Laravels `mimes:` validerer mot innhold
  via finfo, ikke bare filendelse.

Ikke vurdert: antivirus-/malware-skanning finnes ikke. Det er en bevisst mangel å ta stilling til,
ikke et funn i seg selv.

### AI-flyt — påfallende god logghygiene
`OpenAiClient::logFailure()` (`app/Services/OpenAi/OpenAiClient.php:208-224`) logger endpoint, status,
request-id, feilmelding, feiltype og **`raw_body_length`** — aldri selve svarkroppen. Vi fant ingen
`Log::`-kall noe sted i `app/` som inkluderer `extracted_text`, `content_markdown`, `prompt` eller
`claim_text` (523 `Log::`-kall gjennomgått med mønstersøk).

Ingen prompt- eller responspersistering i modellene.

Åpent spørsmål vi **ikke** har verifisert: om dokumentinnhold kan påvirke systeminstruksjoner
(prompt injection) i Wiki-genereringen. Enterprise Wiki har provenance- og verifiseringsmekanismer,
men vi har ikke gjennomgått promptkonstruksjonen i denne omgangen. Bør være egen oppgave.

### Køer — ingen tenant-forvirring mulig
Alle 23 jobbklasser tar **ID-er, ikke serialiserte modeller** (`public readonly int $runId` osv.).
Ingen jobb tar både en `customerId` og en entitets-ID — kunden utledes alltid fra entiteten selv, så
det finnes ingen uoverensstemmelse å utnytte. Jobbpayloads inneholder ingen sensitive data.

### Infrastruktur
PostgreSQL og Redis er begge bundet til loopback (`127.0.0.1:5433`, `127.0.0.1:6380`) — ikke eksponert
på nettverket.

### Secrets
`.env` er ikke tracked og er dekket av `.gitignore:3-5`. Ingen treff på `sk-…`, `whsec_…` eller
private nøkler i tracked kildekode. Ingen `.env`-, `.pem`- eller `.key`-filer er noensinne lagt til i
git-historikken.

### Health-endepunkter
`EnsureHealthToken` bruker `hash_equals()` — timing-sikker sammenligning — og feiler lukket med 503
når token ikke er konfigurert.

---

## Testdekning for sikkerhet

### Finnes i dag
Kryss-kunde-scenarier er dekket i minst 20 testfiler, blant annet:
`tests/Feature/App/AiControllerTest.php`, `tests/Feature/App/WikiControllerTest.php`,
`tests/Feature/App/Wiki/WikiClaimControllerTest.php`,
`tests/Feature/App/KnowledgeBaseAiUsageControllerTest.php`,
`tests/Feature/App/RequirementDeletionTest.php`,
`tests/Feature/App/CustomerWatchProfileManagementTest.php`,
`tests/Feature/CustomerFrontendAccessTest.php`,
`tests/Feature/Filament/BackupRecoveryPageTest.php` (rolletilgang til admin-side).

### Mangler — bør lages senere
1. ~~**`/admin` panelinngang**~~ — dekket av `tests/Feature/Filament/AdminPanelAccessGateTest.php` (F-03 lukket).
2. ~~**Brute force**~~ — dekket av `tests/Feature/Auth/LoginThrottleAndLoggingTest.php` (F-01 lukket).
3. ~~**Stripe webhook uten secret**~~ — dekket av `tests/Feature/Billing/StripeWebhookSignatureTest.php` (F-02 lukket).
4. **Systematisk IDOR-sveip** — en datadrevet test som for hver rute med `{model}` verifiserer at en
   fremmed kundes ID gir 403/404. I dag er dekningen god, men per-controller og manuell.
5. **Security headers** — ingen test når de innføres.
   ~~**Trusted proxies**~~ — dekket av `tests/Feature/Security/TrustedProxyTest.php`.
6. **Direkte filtilgang** — ingen test på at `storage/app/private` ikke kan nås via HTTP.

---

## Forhold som ikke kan verifiseres fra repoet

- Om `SESSION_SECURE_COOKIE`, `STRIPE_WEBHOOK_SECRET` og `APP_DEBUG` er korrekt satt i produksjon
- TLS-terminering, sertifikater og HSTS i dagens produksjonsoppsett
- PostgreSQL-brukerens faktiske privilegienivå i produksjon
- Nettverkseksponering av produksjonsdatabasen
- Om backup faktisk kjøres og er testet gjenopprettet
- Faktisk logglagring, -retensjon og tilgangskontroll
- Om Redis i produksjon har autentisering

---

# Security Baseline Closure — 30. august 2026

Denne seksjonen avslutter baseline-arbeidet. Alt som gjelder **dagens Procynia-applikasjon og
dagens deploymodell** er nå enten lukket eller eksplisitt klassifisert. Rester er ikke nye
småoppgaver; de er plassert i én av tre kategorier med akseptkriterium.

## LUKKET

| Funn | Kontroll |
|---|---|
| F-01 Brute-force på `/login` | Rate limiting per e-post+IP, sikkerhetslogging uten credentials |
| Trusted proxy / IP-spoofing | Config-drevet liste, tom som standard; `trustProxies(at: '*')` fjernet |
| F-02 Stripe webhook | Fail-closed signaturverifisering, 503 uten secret |
| F-03 Filament-tilgang | Sentral gate; kun interne administratorer |
| F-04 Autorisasjon | Re-derivation som canonical mekanisme + strukturell guard (se under) |
| F-05 Security headers | Seks headere globalt, enforcing CSP i produksjon |
| F-06 Sessionscookie | `Secure` som default utenfor `local`, uavhengig av proxy-deteksjon |
| F-07 Composer-advisories | 47 → 0 |
| F-08 Redis-autentisering | `requirepass`, fail-closed i Compose og runtime-check |
| F-11 npm-advisories | 43 → 0 |
| Dependency-audit i CI | PR, push til `main` **og daglig scheduled scan** |
| Node-baseline | Node 22 i CI, produksjonsbygg og prosjektmetadata |
| AI prompt injection | Delt trust-boundary-konvensjon; ingen tools; ingen dynamisk dispatch |
| ui-avatars.com-lekkasjen | Lokal SVG-avatar; CSP-unntaket fjernet |

### F-04 — hvorfor ikke Policies

Funnet var «ingen Policies/Gates; 107 imperative `abort_unless`». Konklusjonen etter kartlegging er
at Policies ville flyttet håndhevingen **utover**, ikke innover.

Kundefrontenden bruker implicit route model binding, men **stoler aldri på den bundne instansen**.
Hver action re-deriverer posten fra tenanten før bruk:

```php
$record = $this->scopedDocument($customerId, $knowledgeItem->id);
```

Den bundne modellen leverer en id, ikke mer. En annen kundes post kan derfor ikke nås: den scopede
spørringen returnerer ingenting, og forespørselen gir **404, ikke 403** — posten er usynlig, ikke
bare forbudt. En Policy ville kjørt *etter* at et uscopet oppslag allerede hadde lyktes.

Verifisert: alle 84 actions i `app/Http/Controllers/App/` som binder en modell re-deriverer eller
sammenligner `customer_id` eksplisitt. Ingen mangler håndheving.

Det imperative mønsteret manglet én ting: en garanti for at disiplinen holder.
`tests/Feature/Security/CrossTenantIsolationTest.php` leverer den — sju atferdstester
(les/oppdater/slett/liste på tvers av to kunder, både System Owner og Contributor) og to
strukturelle: at hver bundet modell er tenant-håndhevet, og at scoped oppslag er normen.

**Korreksjon:** en tidligere formulering i dette dokumentet hevdet at kundefrontenden ikke bruker
implicit binding. Det er feil — den brukes 84 steder. Poenget er at den ikke *stoles på*.

### AI / prompt injection

Dataflyt: `untrusted kilde → ekstraksjon → retrieval → prompt assembly → modell → validering →
persistering → UI`.

Tillitsnivåer i prompt assembly:

| Nivå | Kilde |
|---|---|
| Trusted system instruction | Procynias developer-prompt |
| Trusted application metadata | kunde-/språkkontekst utledet server-side |
| **Untrusted** | anbudsdokumenter, opplastede filer, kunnskapsbase, Wiki-innhold, fritekst |
| Model-generated | alt modellen returnerer |

**Kan dokumentinnhold instruere modellen til å omgå Procynia-policy?** Ikke på noen måte som gir
tilgang eller handling. Kontrollene ligger utenfor modellen:

1. **Autorisasjon skjer før prompt.** Retrieval er kundescoped i SQL
   (`where('knowledge_items.customer_id', $customerId)`). En prompt kan ikke be om en annen kundes
   dokumenter, fordi de radene aldri ble hentet. Modellen er ikke en security boundary.
2. **Modellen kan ikke handle.** Ingen tools, ingen function calling i `OpenAiClient` — verifisert
   med test. Den produserer tekst.
3. **Ingenting dispatches som kode.** Ingen `eval`, `call_user_func` eller dynamisk kall i
   `app/Services/Ai` — verifisert med test.
4. **Strukturert output valideres** mot JSON-schema (58 bruksteder) før persistering.
5. **Defence in depth:** `App\Services\Ai\AiPromptSecurity` samler én konvensjon som forteller
   modellen at innholdet er data, ikke instruksjoner. Konvensjonen fantes allerede i
   `WikiQuestionAnswerAiClient`; den er nå delt og påført ekstraksjons- og vurderingspromptene som
   konsumerer kundedokumenter.

Testene later ikke som de beviser at en språkmodell ikke kan jailbreakes. De pinner
applikasjonskontrollene.

## AKSEPTERT RESIDUAL RISIKO

| Rest | Vurdering | Akseptkriterium |
|---|---|---|
| `style-src 'unsafe-inline'` i CSP | Filament injiserer `<style>`-blokker og har ingen nonce-mekanisme. Inline *style* kan ikke eksekvere JavaScript | Revurderes hvis Filament får nonce-støtte. Å bygge egen nonce-arkitektur er ikke forsvarlig for gevinsten |
| `script-src 'unsafe-inline' 'unsafe-eval'` på `/admin` | Filament rendrer inline `<script>`; Alpine i `livewire.js` bruker `new Function`. Upstream framework-behov | Panelet er internt-admin-only (F-03), og lettelsen er scopet — kundeflaten beholder streng policy, og `object-src`/`frame-ancestors`/`base-uri` er uendret også på `/admin` |
| F-09 lokalt utviklerpassord i repo | Kun `.env.testing` og e2e-seeder; ingen produksjonsverdi | Gjelder testfixtures. Rotasjon er meningsløs for en verdi som per design er offentlig |
| F-10 `APP_DEBUG=true` i `.env.example` | Eksempelfil for lokal utvikling; produksjonsimaget setter `APP_ENV=production`/`APP_DEBUG=false` | Deploy-guiden krever eksplisitt `false`; `ops:runtime-check` verifiserer |
| F-12 Filament-versjon synlig for admin | Kun for interne administratorer etter innlogging (F-03) | Versjonsinformasjon til en autentisert intern admin er ikke en eksponering |
| Arkiv-/dokumentbomber | `.docx`/`.xlsx` parses av PhpWord/openspout med 20 MB opplastingsgrense; ingen eksplisitt entry-count- eller ratio-grense | Grensen på 20 MB begrenser praktisk ekspansjon, og parsing skjer i kø, ikke i request. Revurderes hvis parser-DoS observeres |

## FREMTIDIG ARKITEKTUR

Ett spor, ikke en liste: **Azure-migrering og identitet.**

Private endpoints, Key Vault, Managed Identity, Container Apps ingress-policy, Redis TLS og
Entra ID/SSO hører alle til den migreringen. Dagens deployment er sikret innenfor sitt eget scope —
disse endrer plattformen, ikke applikasjonens sikkerhet i dag. Dagens rollemodell
(`role` + `bid_role` + `is_qa`) er tilstrekkelig og skal ikke bygges om før SSO faktisk innføres.

## BLOKKERT AV EKSTERN INFRASTRUKTUR

**Malwareskanning av opplastede dokumenter.**

Verifisert: ingen scanner finnes i dagens runtime — `clamscan`, `clamdscan` og `freshclam` mangler
både på host og i containeren, og stacken har ingen scanner-tjeneste. Å innføre ClamAV er en egen
driftskomponent (daemon, signaturoppdatering, minne, oppetid), ikke en kodeendring.

Upload-pipelinen er hardnet så langt det går uten scanner: extension-allowlist og MIME-validering
(`mimes:pdf,docx,xlsx`), 20 MB grense, privat disk utenfor webroot (`storage/app/private`),
nedlasting kun gjennom autorisert controller med `attachment`-disposition, `nosniff` globalt (F-05),
og bildeservering med eksplisitt MIME.

Akseptkriterium: løses når Azure-migreringen gir Defender for Storage eller tilsvarende, eller
dersom en scanner-sidecar besluttes som egen driftsoppgave. Klassifiseres ikke som åpent
applikasjonsfunn.

## Konklusjon

**Finnes det kjente åpne HIGH/CRITICAL sikkerhetsfunn i dagens Procynia-app? Nei.**

`composer audit` = 0. `npm audit` = 0. Alle høye og middels funn fra auditen er lukket. Restene er
lave, dokumenterte og klassifiserte.

**Security baseline: LUKKET.**

