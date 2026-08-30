# Procynia — sikkerhetskontroller

Beskriver hvilke sikkerhetskontroller som faktisk finnes i Procynia i dag, hvem som eier dem, og
hvordan de verifiseres.

Dokumentet beskriver **nåsituasjon**, ikke ønsket arkitektur. Kontroller som ikke er verifisert står
oppført som nettopp det.

Detaljert teknisk gjennomgang med funn:
[`docs/security/security-audit-2026-08.md`](../security/security-audit-2026-08.md).
Sist verifisert mot kode: commit `9b3730a`, august 2026.
F-01 (innlogging) lukket og verifisert august 2026 — se §1.1, §1.2 og §9.1.
F-02 (Stripe webhook) lukket og verifisert august 2026 — se §6.1.
F-03 (Filament panelnivå-gate) lukket og verifisert august 2026 — se §2.1.

---

## Statusnøkkel

| Status | Betydning |
|---|---|
| **Implementert** | Verifisert i kode eller konfigurasjon |
| **Delvis** | Finnes, men dekker ikke hele løsningen |
| **Planlagt** | Besluttet, ikke bygget |
| **Må verifiseres i produksjon** | Kan ikke avgjøres fra repoet |
| **Mangler** | Kontrollen finnes ikke |

---

## 1. Autentisering

| Kontroll | Status | Verifiseres ved |
|---|---|---|
| Sesjonsbasert innlogging (Laravel `web`-guard) | **Implementert** | `config/auth.php`, `AuthenticatedSessionController` |
| Passord hashet (bcrypt) | **Implementert** | `User::casts()` → `'password' => 'hashed'` |
| Inaktive kontoer avvises ved innlogging | **Implementert** | `Auth::attempt([... 'is_active' => true])` |
| Session regenereres etter innlogging | **Implementert** | `$request->session()->regenerate()` |
| Frontend-tilgang krever kundetilknytning | **Implementert** | `EnsureCustomerFrontendAccess` middleware |
| Brute-force-beskyttelse på innlogging | **Implementert** | 5 forsøk per minutt per (e-post + klient-IP); `AuthenticatedSessionController` — se §1.1 |
| Kontolockout | **Bevisst ikke innført** | Tidsbegrenset sperre i stedet for permanent lås — se §1.1 |
| MFA / to-faktor | **Mangler** | Planlagt via Entra ID |
| E-postverifisering | **Mangler** | Ingen `MustVerifyEmail` på `User` |
| Passord-reset | **Implementert** | Laravel password broker, `config/auth.php:95-101` (60 min utløp) |
| SSO / føderert innlogging | **Planlagt** | Se §11 |

**Ansvar:** utviklingsteamet.
**Kjent svakhet:** passord er fortsatt eneste faktor. MFA hører hjemme i Entra ID-arbeidet.

### 1.1 Brute-force-beskyttelse på `/login`

| | Verdi |
|---|---|
| Grense | **5 forsøk per minutt** |
| Nedkjøling | **60 sekunder**, utløper av seg selv |
| Nøkkel | `login:{normalisert e-post}\|{klient-IP}` |
| Konfigurasjon | `config/procynia.php` → `procynia.auth.login` |
| Teller opp ved | Hvert avvist innloggingsforsøk |
| Nullstilles ved | Vellykket innlogging |

**Ingen permanent kontolås.** Sperren er en tidsbegrenset rate limit som utløper av seg selv. En
permanent lås ville gitt en angriper et tjenestenektangrep mot enhver konto hvis adresse de kjenner.

Verdiene er bevisst **ikke** env-overstyrbare. En sikkerhetsgrense som kan heves stille per miljø,
blir hevet.

**Nøkkelen inneholder aldri passord eller andre credentials.**

Fordi nøkkelen omfatter både adresse og IP, kan gjetting mot én konto fra ett sted ikke sperre samme
konto fra andre steder, og kan ikke bruke opp en annen kontos kvote.

**Deaktiverte kontoer:** `is_active` er en del av selve credential-sammenligningen, så et korrekt
passord for en deaktivert konto feiler på nøyaktig samme måte som et feil passord — samme melding, og
forsøket teller likt mot grensen. Det finnes ingen billigere sonderingsvei og ingen omgåelse.

**Interne kontoer uten kundeområde-tilgang:** riktige credentials som avvises fordi kontoen ikke kan
bruke kundeområdet, teller også mot grensen og nullstiller den ikke. Ellers ville én gyldig intern
credential fungert som et nullstillingsorakel for telleren.

**Verifiseres ved:** `tests/Feature/Auth/LoginThrottleAndLoggingTest.php` (18 tester).

**Klient-IP:** tidligere kunne IP-delen av nøkkelen settes av klienten selv. Lukket august 2026 —
se §1.2.

### 1.2 Trusted proxies og klient-IP

| Kontroll | Status | Verifiseres ved |
|---|---|---|
| Laravel stoler på klientkontrollerte forwarding-headere | **Fjernet** | `trustProxies(at: '*')` er borte fra `bootstrap/app.php` |
| Trusted proxies er konfigurerbare, tomme som standard | **Implementert** | `config/procynia.php` → `procynia.security.trusted_proxies`, satt via `AppServiceProvider` |
| nginx normaliserer forwarding-headere | **Implementert** | Begge nginx-konfigurasjoner |
| Klient kan velge egen IP | **Nei** | `tests/Feature/Security/TrustedProxyTest.php` |

**Hvorfor `trustProxies(at: '*')` var risikabelt.** Symfony avgjør klient-IP ved å gå gjennom
`X-Forwarded-For` fra høyre mot venstre og returnere den første adressen som *ikke* tilhører en
trusted proxy. Med `'*'` var alle adresser trusted, så gjennomgangen gikk helt til venstre og
returnerte den verdien klienten selv hadde skrevet inn. `request()->ip()` ble dermed en verdi
klienten kontrollerte — og siden login-limiteren nøkles på e-post + IP, ga hver ny headerverdi en ny
kvote.

**Hva nginx gjør nå.** Begge konfigurasjoner setter:

```
fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;
fastcgi_param HTTP_X_REAL_IP       $remote_addr;
```

`$proxy_add_x_forwarded_for` **legger til** peer-en nginx faktisk tok imot forbindelsen fra, bakerst i
kjeden. Den overskriver ikke: i on-prem-oppsettet har den ytre reverse proxyen allerede lagt ekte
klientadresse i headeren, og overskriving ville kastet den. Ved å legge til i stedet er den
høyreste oppføringen alltid observert av nginx, aldri påstått av den som ringer. `X-Real-IP` har
ingen kjedesemantikk og overskrives derfor helt.

**Hvem Laravel stoler på.** `TRUSTED_PROXIES` (kommaseparert IP/CIDR), tom som standard.

| Miljø | Verdi | Effekt |
|---|---|---|
| Lokal utvikling | tom | Klient-IP tas fra `REMOTE_ADDR`. Ingen forwarding-header blir trodd. |
| On-prem produksjon | Docker-gatewayen slik containeren ser den | **Må settes.** Uten den ser Laravel ikke ekte klient-IP, og HTTPS-gjenkjenning fra `X-Forwarded-Proto` slutter å virke. |
| Azure | Container Apps ingress-området | **Må verifiseres mot faktisk ingress/proxykjede før produksjonssetting.** Ikke gjettet her. |

Sett aldri `*`. Bruk eksakt adresse eller et smalt CIDR — ikke et helt RFC1918-område, siden
containeren nås gjennom Docker-gatewayen og et bredt område ville gjort enhver klient på det
nettverket til en trusted proxy.

**Sideeffekter som er testdekket:** `X-Forwarded-Proto`/HTTPS-gjenkjenning og `X-Forwarded-Host`
styres av samme trust-innstilling. Testene dekker begge retninger — at en utrusted klient verken kan
påstå HTTPS eller overstyre host, og at en trusted proxy fortsatt kan rapportere begge korrekt.

---

## 2. Autorisasjon

| Kontroll | Status | Verifiseres ved |
|---|---|---|
| Systemroller (`users.role`) | **Implementert** | `super_admin`, `customer_admin`, `user` |
| Bid-roller (`users.bid_role`) | **Implementert** | `system_owner`, `bid_manager`, `contributor`, `viewer` |
| QA som additiv egenskap (`users.is_qa`) | **Implementert** | `Customer::roleHasPermission()` |
| Konfigurerbare kundetillatelser | **Implementert** | `customers.permission_settings` (JSON), Tilganger-fanen |
| Saksynlighet per bruker | **Implementert** | `SavedNoticeAccessService::applyVisibility()` |
| Laravel Policies / Gates | **Mangler** | Ingen `app/Policies/`; autorisasjon er 106 `abort_unless()`-kall i controllere — se audit F-04 |
| Filament-panelet gated på panelnivå | **Implementert** | `User::canAccessPanel()` krever aktiv bruker med administrativ rolle — se §2.1 |

**Ansvar:** utviklingsteamet.
**Verifisering:** alle 79 filer under `app/Filament/` ble gjennomgått i august 2026 — samtlige
implementerer `canAccess()` eller `canView()`. Panelnivå-gaten under gjør dette til andre lag, ikke
eneste lag.

### 2.1 Adgangskontroll til Filament-panelet

**Filament er Procynias interne administrasjonsflate. Det er ikke en kundeportal.**

**Kundeadministratorer administrerer egne brukere gjennom den kundeavgrensede applikasjonsflaten
(`/app/users`) og har ikke tilgang til Filament.**

Tre lag, i denne rekkefølgen:

```
Filament panel gate  (User::canAccessPanel)
        |
        v
resource/page canAccess() / canView()
        |
        v
record-level canEdit() / canDelete()
```

**Lag 1 — panelnivå.** `User::canAccessPanel()` krever:

```
is_active  AND  CustomerContext::isInternalAdmin()
```

der `isInternalAdmin()` er den autoritative definisjonen: `role = super_admin` **og**
`customer_id = null`. Ingen andre roller slipper gjennom.

| Rolle | Panelnivå | Kundeflate |
|---|---|---|
| Aktiv intern admin (`super_admin`, `customer_id = null`) | **Slipper inn** | — |
| Inaktiv intern admin | Avvist | — |
| `super_admin` med `customer_id` | Avvist | Ikke intern admin per definisjonen |
| Aktiv `customer_admin` | **Avvist** | Administrerer egne brukere via `/app/users` |
| Aktiv vanlig kundebruker (`role = user`) | Avvist | Bruker kundeflaten |
| QA-flagget kundebruker | Avvist | QA er en tilleggsegenskap i kundeflaten |
| Uautentisert | Avvist | — |

**Respons ved avvisning:** HTTP **403**, Filaments standardoppførsel. Ingen egen feilside, og
feilmeldingen avslører ikke rollelogikk.

**Historikk.** Gaten sjekket opprinnelig kun `is_active`, slik at enhver aktiv bruker kunne åpne
`/admin`. Ingen data ble eksponert — alle resources, pages og widgets har egen `canAccess()`/
`canView()` — men kontrollen var opt-in per fil, og Filaments standard er å tillate. Den ble først
strammet til «administrativ rolle», og deretter til intern admin som en bevisst produktbeslutning.
`docs/operations/admin-guide.md` beskrev allerede denne modellen; det var koden som lå etter.

**`UserResource`.** Gikk fra `canManageUsers()` (som også slapp inn kundeadministratorer) til
`isInternalAdmin()`, som resten av panelet. Den var den siste parallelle veien inn for
kundeadministratorer. `canEdit()`, `canDelete()` og den kundescopede `getEloquentQuery()` er beholdt
som dybdeforsvar selv om de nå er teknisk overflødige.

**Kundeadministrasjon flyttet ikke — den lå alltid i kundeflaten.**
`App\Http\Controllers\App\UserController` (`/app/users`) er kundeavgrenset ved konstruksjon:
`scopedCustomerUsersQuery()` og `scopedCustomerUser()` filtrerer på `customer_id`, og
`canManageCustomerUsers()` styrer hvem som får administrere. Dekket av
`tests/Feature/App/CustomerUserManagementTest.php`, inkludert at en kundeadministrator kun ser
brukere hos egen kunde.

**Lag 2 og 3 er ikke svekket.** Ingen `canAccess()`, `canView()`, `canCreate()`, `canEdit()` eller
`canDelete()` er fjernet. Alle 31 resources og 15 pages gater nå på samme interne admin-prinsipp, så
lagene er enige i stedet for å motsi hverandre.

**Verifiseres ved:** `tests/Feature/Filament/AdminPanelAccessGateTest.php` (17 tester) og
`tests/Feature/Filament/CustomerAdminNavigationTest.php` (som også beviser at kundeadministratoren
bruker kundeflaten i stedet).

---

## 3. Kunde- og tenantisolasjon

Dette er Procynias viktigste sikkerhetsegenskap.

| Kontroll | Status | Verifiseres ved |
|---|---|---|
| Bruker permanent bundet til én kunde | **Implementert** | `users.customer_id`; ingen kundebyttefunksjon finnes |
| Sentral tenant-resolver | **Implementert** | `app/Support/CustomerContext.php` |
| Eierskap re-utledes etter route model binding | **Implementert** | Mønster: `->where('customer_id', $customerId)->whereKey($model->id)->firstOrFail()` |
| Nøstede ressurser hentes gjennom verifisert forelder | **Implementert** | F.eks. `$record->aiDocuments()->whereKey($document->id)->firstOrFail()` |
| Globale tenant-scopes på modeller | **Mangler** | Isolasjon hviler på controller-disiplin, ikke på modellnivå |
| Scoped route bindings | **Mangler** | `scopeBindings()` brukes ikke |
| Testdekning for kryss-kunde-tilgang | **Implementert** | Kryss-kunde-scenarier i 20+ testfiler |

**Ansvar:** utviklingsteamet.
**Verifisering:** ved gjennomgangen i august 2026 ble det **ikke funnet noen vei** for en bruker hos
kunde A til data hos kunde B.

**Regel for ny kode:** en rute-bundet modell skal aldri brukes direkte. Hent entiteten på nytt gjennom
en kundescopet spørring, eller verifiser eierskap eksplisitt før bruk.

---

## 4. Dokument- og filhåndtering

| Kontroll | Status | Verifiseres ved |
|---|---|---|
| Opplasting kun til privat disk | **Implementert** | `Storage::disk('local')` → `storage/app/private`; null bruk av `disk('public')` |
| Dokumenter ikke tilgjengelige direkte fra webserver | **Implementert** | Ingen `public/storage`-symlink; nginx `/storage/` peker på en annen katalog |
| Nedlasting går gjennom autorisert controller | **Implementert** | Eierskap re-utledes før filen leveres |
| Filtypevalidering | **Implementert** | `mimes:pdf,docx,xlsx` (innholdsbasert via finfo) |
| Størrelsesgrense | **Implementert** | 20 MB i validator, under PHP (50 MB) og nginx (50 MB) |
| Trygge lagrede filnavn | **Implementert** | `Str::ulid().'.'.$ext` via `putFileAs()` — ingen path traversal |
| Antivirus-/malwareskanning | **Mangler** | Ingen skanning av kundeopplastede filer |

**Ansvar:** utviklingsteamet.

---

## 5. AI og OpenAI

| Kontroll | Status | Verifiseres ved |
|---|---|---|
| API-nøkkel fra konfigurasjon, aldri hardkodet | **Implementert** | `config('services.openai.api_key')` |
| Prompter og svar logges ikke | **Implementert** | `OpenAiClient::logFailure()` logger kun metadata og svarlengde |
| Prompter/svar persisteres ikke i database | **Implementert** | Ingen prompt-felter i modellene |
| Eksplisitte timeouts på alle AI-kall | **Implementert** | 120 s (`createResponse`), 180 s (`get`/`post`), connect ≤ 10 s |
| Kundeinnhold sendes til OpenAI | **Ja — dokumentert forhold** | Dokumenttekst inngår i ekstraksjon og Wiki-generering |
| Menneskelig godkjenning før Wiki-innhold blir autoritativt | **Implementert** | Wiki-godkjenningsflyt, jf. `docs/enterprise-wiki-architecture.md` |
| Prompt injection-vurdering | **Ikke gjennomført** | Promptkonstruksjonen er ikke sikkerhetsgjennomgått |
| Kostnadskontroll / rate limiting på AI-bruk | **Delvis** | `AI_RATE_LIMIT_*` brukes til varsling, ikke som stoppmekanisme |

**Ansvar:** utviklingsteamet.
**Databehandling:** at kundens dokumentinnhold sendes til OpenAI er en databehandlingsbeslutning som
må være dekket i kundeavtale og databehandleravtale. Ikke en teknisk kontroll.

---

## 6. Web- og applikasjonssikkerhet

| Kontroll | Status | Verifiseres ved |
|---|---|---|
| CSRF-beskyttelse | **Implementert** | Laravel default; kun `stripe/webhook` unntatt — se §6.1 for hvorfor det er riktig |
| SQL injection-beskyttelse | **Implementert** | Eloquent/query builder gjennomgående |
| Proxy-headere håndteres sikkert | **Implementert** | Konfigurerbare trusted proxies, tom som standard — se §1.2 |
| Sesjonscookie `HttpOnly` | **Implementert** | `config/session.php` default `true` |
| Sesjonscookie `SameSite=lax` | **Implementert** | `config/session.php` |
| Sesjonscookie `Secure` | **Implementert** | Default `true` utenfor `local`, uavhengig av proxy-deteksjon — se §6.3 |
| Security headers (CSP, HSTS, X-Frame-Options m.fl.) | **Implementert** | Globalt via `AddSecurityHeaders`; enforcing CSP i produksjon — se §6.2 |
| `APP_DEBUG=false` i produksjon | **Må verifiseres i produksjon** | Azure-kontrakten setter den; on-prem ukjent |
| Stripe webhook-signaturverifisering | **Implementert** | Fail-closed: ingen verifisert signatur, ingen behandling — se §6.1 |

### 6.2 Globale security headers

Satt av `app/Http/Middleware/AddSecurityHeaders.php`, registrert globalt i `bootstrap/app.php`.
Policyen ligger i `config('procynia.security.headers')`. Full begrunnelse: audit F-05.

| Header | Verdi | Gjelder |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | alle responser |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | alle responser |
| `X-Frame-Options` | `DENY` | alle responser |
| `Permissions-Policy` | kamera, mikrofon, geolokasjon, betaling, USB m.fl. deaktivert | alle responser |
| `Strict-Transport-Security` | `max-age=31536000` | **kun HTTPS** |
| `Content-Security-Policy` | se under | **kun `text/html`** |

`X-XSS-Protection` settes bevisst ikke — utfaset og erstattet av CSP.

**CSP, kundefrontenden (produksjon):**

```
default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none';
form-action 'self'; script-src 'self'; style-src 'self' 'unsafe-inline';
img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self';
frame-src 'self'; worker-src 'self' blob:; manifest-src 'self'
```

Ingen wildcards og ingen eksterne origins. `script-src 'self'` uten `unsafe-inline`/`unsafe-eval` —
Inertia/React trenger ingen av delene i produksjon. `frame-src 'self'` er nødvendig for
AI-dokumentvisningens iframe mot eget origin.

**CSP, `/admin` (Filament) — residual risk:** `script-src` utvides med `'unsafe-inline'`
(Filaments inline `<script>`-blokker) og `'unsafe-eval'` (Alpine i `livewire.js` bruker
`new Function`), og `img-src` med `https://ui-avatars.com` (Filaments avatar-provider). Akseptert
fordi panelet er internt-admin-only (F-03), og lettelsen er scopet — kundeflaten beholder den
strenge policyen, og `object-src`/`frame-ancestors`/`base-uri` er uendret også på `/admin`.

**Utvikling:** CSP sendes som `Content-Security-Policy-Report-Only` mens Vite dev-server kjører.
CSP kan ikke uttrykke IPv6-literaler, og `public/hot` peker på `http://[::1]:5173`, så en enforcing
policy ville gitt blank side lokalt. Utvikleren ser fortsatt brudd i konsollen. Produksjon er
alltid enforcing — dev-branchen krever både `APP_ENV=local` og at `public/hot` finnes.

**HSTS:** uten `preload` (enveisdør, driftsbeslutning) og uten `includeSubDomains` (avventer at
alle subdomener er kjent HTTPS-only). Sendes kun når `$request->isSecure()`, som bak proxy avhenger
av trusted-proxy-listen i §1.2.

**Testdekning:** `tests/Feature/Security/SecurityHeadersTest.php` — 13 tester som pinner
direktivinnhold, fravær av wildcards, HSTS kun over HTTPS, og at admin-lettelsen ikke lekker.

### 6.3 Sessionscookie

| Attributt | Verdi |
|---|---|
| `Secure` | `true` utenfor `local`; følger forespørselen i `local` |
| `HttpOnly` | `true` |
| `SameSite` | `lax` |
| `Path` | `/` |
| `Domain` | ikke satt (host-only) |
| Navn | `procynia-session` |

`config/session.php` defaulter `secure` til `true` når `APP_ENV` er noe annet enn `local`:

```php
'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'local' ? null : true),
```

**Hvorfor ikke bare la Laravel-standarden stå:** den var `env('SESSION_SECURE_COOKIE')` uten
default, altså `null`. `null` betyr «match forespørselen», ikke «usikker» — men i produksjon
termineres TLS i nginx eller Container Apps ingress, og appen nås over ren HTTP med
`X-Forwarded-Proto: https`. Den headeren tros kun for proxyer i trusted-lista (§1.2), som er tom som
standard. På et deploy uten `TRUSTED_PROXIES` gikk sessionscookien derfor ut **uten `Secure`** selv
om nettleserforbindelsen var HTTPS. Den nye defaulten fjerner koblingen til runtime-HTTPS-deteksjon.

**Lokal utvikling** er upåvirket: `APP_ENV=local` beholder `null`, så `http://localhost` fungerer,
og en utvikler som kjører HTTPS lokalt får fortsatt `Secure`.

**Eksplisitt override virker fortsatt**, også `false`. Det er bevisst — en operatør som feilsøker en
uvanlig ingress kan trenge det. Poenget med F-06 var en *glemt* variabel, og en glemt variabel lander
nå trygt.

**Produksjon:** Azure setter `SESSION_SECURE_COOKIE` eksplisitt i `infra/main.bicep`. On-prem satte
den ingen steder — produksjonsimaget har `APP_ENV=production`, så defaulten slår inn av seg selv.
`.env.example` setter den bevisst ikke, siden filen leveres med `APP_ENV=local`.

**Testdekning:** `tests/Feature/Security/SessionCookieSecurityTest.php` — 8 tester, inkludert den
terminerte TLS-forespørselen fra utrustet proxy og en strukturell guard mot at Laravels bare
`env()`-linje gjeninnføres.

### 6.4 Microsoft Entra ID (SSO)

Entra autentiserer identiteten. **Procynia eier fortsatt customer, roller og autorisasjon** — ingen
Entra-gruppe, app-rolle eller directory-rolle mappes til `role`, `bid_role` eller `is_qa`.

Full beskrivelse: [`docs/operations/entra-sso.md`](entra-sso.md).

Kjernepunkter for drift:

| | |
|---|---|
| Flyt | OIDC Authorization Code med confidential client. Ingen implicit flow |
| Token-validering | signatur (tenant-JWKS), `iss`, `aud`, `exp`, `nonce`, `tid` |
| Identitetsnøkkel | `oid`-claim + tenant — ikke e-post |
| Provisjonering | ingen. Brukeren må finnes i Procynia, ellers avvises innlogging |
| Multi-tenant | én `identity_providers`-rad per (kunde, tenant); `customer_id = null` er Procynias egen |
| Sesjon | vanlig herdet Laravel-sesjon (F-06), `session()->regenerate()` etter innlogging |
| Token-lagring | **ingen** — access/refresh token kastes etter identitetsoppslag |
| Secrets | kun `AUTH_ENTRA_CLIENT_SECRET`; tenant/client id og issuer ligger i databasen |

**Kundeisolasjon:** provideren utledes fra det signerte `tid`-claimet, ikke fra sesjonen eller en
query-parameter. Førstegangskobling skjer kun innenfor providerens egen kunde, og kunden
re-verifiseres ved **hver** innlogging — en bruker som flyttes til en annen kunde mister koblingen.

**Login-moduser** styres av `AUTH_ENTRA_ENABLED` og `AUTH_LOCAL_LOGIN_ENABLED`, uavhengig av
`APP_ENV`. Lokal utvikling trenger ikke Azure: standard er Entra av, lokal login på. Er begge av,
avvises konfigurasjonen som en utestengning.

**Break-glass:** behold minst én intern administrator med lokal innlogging, slik at en tenant-feil
eller utløpt client secret ikke stenger ute dem som skal rette den.

**Enumeration:** alle avvisninger gir samme melding til bruker; årsaken logges internt.

### 6.1 Stripe webhook — fail-closed signaturverifisering

Webhook-endepunktet `POST /stripe/webhook` er uautentisert og CSRF-unntatt av nødvendighet: Stripe har
ingen Laravel-sesjon. **Stripe-signaturen er derfor den eneste kontrollen** som skiller en ekte
hendelse fra en forfalsket.

CSRF skal fortsatt være av for denne ruten. Å kreve CSRF-token ville brutt alle ekte webhooks, og
ville uansett ikke gitt sikkerhet — Stripe kan ikke levere et token. Sikkerheten kommer fra
signaturen.

| Tilfelle | Respons | Behandles hendelsen? |
|---|---|---|
| Secret mangler eller er tom | **503** | Nei |
| `Stripe-Signature` mangler | **403** | Nei |
| Signatur ugyldig eller laget med feil secret | **403** | Nei |
| Secret satt og signatur gyldig | **200** | Ja |

Statuskodene følger `EnsureHealthToken`: 503 når kontrollen ikke er konfigurert, 403 når legitimasjonen
er feil. 503 lar dessuten Stripe fortsette å prøve på nytt, så hendelser går ikke tapt i vinduet
mellom en deploy og at secreten settes.

**Konfigurasjon:** `STRIPE_WEBHOOK_SECRET` → `config('cashier.webhook.secret')`. Påkrevd i produksjon
når Stripe/fakturering er i bruk. Ikke nødvendig i miljøer uten Stripe — endepunktet mottar da ingen
kall, og svarer uansett 503.

**Implementasjon:** `App\Http\Middleware\VerifyStripeWebhookSignature`, registrert ubetinget i
`StripeWebhookController::__construct()`. Selve HMAC-sammenligningen delegeres til Cashier og Stripes
egen SDK — signaturverifisering er ikke reimplementert.

**Hva som logges ved avvisning:** `event` (`stripe_webhook_rejected`) og `reason`
(`missing_webhook_secret` eller `invalid_signature`).

**Hva som aldri logges:** webhook-secreten, rå payload, `Stripe-Signature`-headeren, betalingsdetaljer
eller kundeopplysninger. Stripes egen unntaksmelding logges heller ikke, siden den kan gjengi deler av
signaturheaderen. Verifisert av egen test.

**Verifiseres ved:** `tests/Feature/Billing/StripeWebhookSignatureTest.php` (14 tester).

**Oppfølging:** manglende webhook-secret oppdages i dag først når Stripe kaller. En sjekk i
`ops:runtime-check` ville flyttet det til deploy-tidspunktet. Ikke bygget — se auditrapporten.

---

## 7. Database, kø og infrastruktur

| Kontroll | Status | Verifiseres ved |
|---|---|---|
| PostgreSQL ikke eksponert på nettverk (lokalt) | **Implementert** | `docker-compose.yml` binder `127.0.0.1:5433` |
| Redis ikke eksponert på nettverk (lokalt) | **Implementert** | `docker-compose.yml` binder `127.0.0.1:6380` |
| Redis-autentisering | **Implementert** | `requirepass` påkrevd; Compose og `ops:runtime-check` er fail-closed — se §7.1 |
| Redis-port ikke publisert i produksjon | **Implementert** | `docker-compose.prod.yml` fjerner publiseringen |
| Jobber overfører ID-er, ikke serialiserte modeller | **Implementert** | Alle 23 jobbklasser |
| Jobber kan ikke forveksle kunde | **Implementert** | Kunde utledes fra entiteten; ingen jobb tar både kunde-ID og entitets-ID |
| TLS til database | **Implementert (Azure) / må verifiseres (on-prem)** | `DB_SSLMODE=require` i Azure-kontrakten |
| Databasebrukerens privilegienivå | **Må verifiseres i produksjon** | — |
| Backup faktisk kjørt og gjenopprettingstestet | **Må verifiseres i produksjon** | Se `docs/operations/backup-restore.md` |

---


### 7.1 Redis-autentisering

Redis holder **sesjoner** (db 0), **alle ni køer** (db 0) og **cache** inkl. F-01s
innloggingsbegrensning (db 1). Fram til august 2026 kjørte den uten `requirepass` — alt som nådde
porten fikk `PONG`. Se audit F-08 for full begrunnelse.

| Lag | Kontroll |
|---|---|
| Compose | `redis-server --requirepass "$REDIS_PASSWORD"`, med `${REDIS_PASSWORD:?…}` slik at stacken **nekter å starte** uten credential |
| Healthcheck | `redis-cli --no-auth-warning -a "$REDIS_PASSWORD" ping` — må autentisere, ellers rapporteres containeren unhealthy |
| Laravel | `config/database.php` sender `REDIS_PASSWORD` på både `default`- og `cache`-connection |
| Distribusjon | Alle ni Redis-brukende tjenester arver credentialen via `env_file: .env` |
| Produksjon | `docker-compose.prod.yml` fjerner portpubliseringen (`ports: !override []`) |
| Runtime | `ops:runtime-check` feiler kritisk hvis produksjon mangler credential eller bruker en placeholder |

**Lokal utvikling krever også passord** (modell A, paritet). Sett `REDIS_PASSWORD` i `.env` ved
onboarding — `openssl rand -base64 32`. `.env.example` inneholder ingen verdi.

**Verifisering:**

```
docker exec procynia-redis redis-cli PING
  -> NOAUTH Authentication required.

docker exec procynia-redis sh -c 'redis-cli --no-auth-warning -a "$REDIS_PASSWORD" PING'
  -> PONG
```

**Merk ved deploy:** credentialen når containere via miljøet, så en `docker compose up -d` som ikke
recreater alle Redis-brukende tjenester etterlater de gamle i restart-loop med `NOAUTH`. Recreat alle
ni, ikke bare `app`.

**Azure:** Managed Redis krever TLS og nøkkel, levert som `REDIS_URL` fra Key Vault. Runtime-sjekken
håndterer den formen — bærer URL-en credentialen i userinfo, advarer den i stedet for å feile.

## 8. Secrets

| Kontroll | Status | Verifiseres ved |
|---|---|---|
| `.env` ikke i versjonskontroll | **Implementert** | `.gitignore`; ingen `.env` tracked |
| Ingen secrets i git-historikken | **Implementert** | Ingen `.env`-, `.pem`- eller `.key`-filer noensinne lagt til |
| Ingen hardkodede API-nøkler i kildekode | **Implementert** | Mønstersøk uten treff |
| Lokalt utviklerpassord i repoet | **Kjent avvik** | `.env.testing` og lokal seeder — kun lokal utvikling, se audit F-09 |
| Secrets fra Key Vault i Azure | **Planlagt** | `docs/azure-runtime-contract.md` §2 |
| Rotasjonsrutine for secrets | **Mangler** | Ingen dokumentert rutine |

---

## 9. Logging og revisjon

| Kontroll | Status | Verifiseres ved |
|---|---|---|
| Applikasjonslogging | **Implementert** | Laravel-logg; `stderr` i containermiljø |
| Sensitive data holdes ute av logg | **Implementert** | Ingen dokumenttekst, prompter eller nøkler i `Log::`-kall |
| Driftshendelser logges | **Implementert** | Backup, kø-heartbeats, AI-feil |
| Innloggingshendelser logges | **Implementert** | `authentication_succeeded`, `authentication_failed`, `authentication_throttled` — se §9.1 |
| Revisjonsspor for administrative handlinger | **Delvis** | Enkelte handlinger logges; ingen samlet audit trail |
| Revisjonsspor for sletting og godkjenning | **Delvis** | Wiki-godkjenning har sporbarhet; ikke generelt |
| Loggretensjon og tilgangskontroll | **Må verifiseres i produksjon** | — |

### 9.1 Sikkerhetshendelser fra innlogging

Alle tre skrives med prefikset `[PROCYNIA][AUTH]` og et `event`-felt i strukturert kontekst.

| Hendelse | Nivå | Felter |
|---|---|---|
| `authentication_succeeded` | `info` | `user_id`, `customer_id`, `ip`, `user_agent` |
| `authentication_failed` | `warning` | `email`, `ip`, `user_agent`, `reason` |
| `authentication_throttled` | `warning` | `email`, `ip`, `user_agent`, `available_in_seconds` |

**Logges aldri:** passord, credentials-arrayet, request-body, sesjons-ID, cookies, CSRF-token eller
API-nøkler. Verifisert av en egen test som fanger alt som skrives under en innlogging og bekrefter at
det innsendte passordet ikke forekommer.

`customer_id` er `null` for interne kontoer uten kundetilknytning, og skrives som `null`.

`user_agent` avkortes til 255 tegn. Alle brukerkontrollerte verdier skrives som strukturert kontekst,
aldri interpolert inn i loggmeldingen.

**Om `reason`:** `invalid_credentials` dekker bevisst tre tilfeller under ett — ukjent adresse, feil
passord og deaktivert konto — fordi applikasjonen selv ikke skiller dem. Å splitte dem i loggen ville
gjenskapt nøyaktig det skillet HTTP-responsen er nøye med å ikke gjøre.
`customer_frontend_access_denied` skilles ut, fordi den grenen krever riktig passord for å nås og
derfor ikke avslører noe nytt — samtidig som den lar drift skille en feilrettet intern innlogging fra
passordgjetting.

**Ansvar:** utviklingsteamet.

---

## 10. Avhengigheter

| Kontroll | Status | Verifiseres ved |
|---|---|---|
| Låste avhengigheter | **Implementert** | `composer.lock`, `package-lock.json` |
| Sårbarhetsskanning i CI | **Implementert** | `.github/workflows/dependency-audit.yml` kjører `composer audit` og `npm audit` på PR og push til `main`. Advisories feiler builden; ingen ignore-liste |
| Kjente sårbarheter håndtert (Composer) | **Implementert** | 47 → **0** advisories etter tre oppgraderingsrunder 29.08.2026. `composer audit` er ren — se F-07 og §10.1 |
| Kjente sårbarheter håndtert (npm) | **Implementert** | 43 → **0** advisories etter fire oppgraderingsrunder 29.08.2026. `npm audit` er ren — se F-11 og §10.2 |

**Kommando for verifisering:** `composer audit` og `npm audit`.

### 10.1 Composer-advisories — F-07 LUKKET

**`composer audit` er ren.** Tre oppgraderingsrunder gjennomført 29. august 2026. Full analyse,
advisory-tabeller og testgrunnlag: `docs/security/security-audit-2026-08.md`.

```
før:   47 advisories / 16 pakker  (9 høye)
etter:  0 advisories
```

| Runde | Gruppe | Etter | Nøkkelversjoner |
|---|---|---|---|
| P0 | Laravel | 17 | `laravel/framework` v13.2.0 → **v13.29.0**; Symfony 8.0.x → 8.1.x; `league/commonmark` 2.8.2 → 2.10.0; `guzzlehttp/guzzle` 7.10.0 → 7.15.5 |
| P1 | Filament | 6 | `filament/*` v5.4.1 → **v5.7.6** (11 pakker); `symfony/html-sanitizer` v8.0.7 → **v8.1.1**; `livewire/livewire` v4.2.1 → v4.4.2 |
| P2 | Dompdf | **0** | `dompdf/dompdf` v3.1.5 → **v3.1.6**; `sabberworm/php-css-parser` v9.3.0 → v9.4.0 |

Kommandoene:

```
composer update laravel/framework --with-all-dependencies --with "guzzlehttp/guzzle:^7.15.2"
composer update filament/filament  --with-all-dependencies --with "guzzlehttp/guzzle:^7.15.2"
composer update dompdf/dompdf      --with-all-dependencies
```

Guzzle-kappingen i P0 og P1 er bevisst: uten den løfter Composer Guzzle/PSR-7/promises over
major-grenser som verken Laravel eller Filament krever. P2 trengte ingen kapping.

**`composer.json` er byte-identisk gjennom alle tre rundene** — ingen constraint måtte endres.
Ingen forlatte pakker.

**Merk ved deploy:** Filament-oppgraderingen republiserte sine kompilerte assets under
`public/js/filament/` og `public/css/filament/` (21 sporede filer). Disse må følge med i deploy,
ellers serverer adminpanelet JS som ikke matcher PHP-koden.

#### Dompdf-konfigurasjon

Dompdf brukes to steder: `app/Services/Ai/DocumentPreviewService.php` (forhåndsvisning av
kundeopplastet `.docx`, via `app/Http/Controllers/App/AiController.php`) og Cashiers
`DompdfInvoiceRenderer` i `config/cashier.php:107` for Stripe-fakturaer.

Preview-veien er den eksponerte: kundekontrollert dokumentinnhold konverteres til HTML og rendres.
Innstillingene er `isRemoteEnabled = false`, `isHtml5ParserEnabled = true`,
`defaultFont = DejaVu Sans`. Ingen eksplisitt `chroot` settes — Dompdfs default gjelder.
Opplastingsgrensen er `max:20480` (20 MB).

`tests/Feature/Security/DompdfPreviewFileAccessTest.php` vokter dette: den bruker samme `Options`
som preview-tjenesten og verifiserer at en lokal fil ikke kan trekkes inn i PDF-en via `<img>`,
absolutt sti, `@font-face`, CSS `background-image` eller SVG `href`.

### 10.2 npm-advisories — F-11 LUKKET

**`npm audit` er ren.** Fire oppgraderingsrunder gjennomført 29. august 2026. Full analyse:
`docs/security/security-audit-2026-08.md`. Node v18.15.0, npm 9.5.0.

```
før:  43 advisories / 10 pakker  (2 kritiske)
etter: 0 advisories
```

| Runde | Gruppe | Etter | Nøkkelversjoner |
|---|---|---|---|
| P0 | axios | 13 | `axios` 1.13.6 → **1.20.0**, `follow-redirects` → 1.16.0, `form-data` → 4.0.6 |
| P1 | concurrently | 11 | `concurrently` 9.2.1 → **9.2.4**, `shell-quote` 1.8.3 → **1.9.0** |
| P2 | postcss/babel | 4 | `postcss` 8.5.8 → **8.5.26**, `nanoid` → 3.3.18, `@babel/core` → 7.29.7 |
| P3 | vite/esbuild | **0** | `vite` 5.4.21 → **6.4.3**, `esbuild` 0.21.5 → **0.25.12** |

Kommandoene:

```
npm update axios
npm update concurrently
npm update postcss @babel/core
# package.json: "vite": "^5.4.14" -> "^6.4.3"
npm install
```

**`package.json` ble endret én gang i hele løpet** — Vite-constraintet i P3. Alt annet ble løst
innenfor eksisterende constraints med `npm update <pakke>`, som skriver kun lockfilen.
`vite.config.js`, frontend-kode og PHP-kode er urørt gjennom alle fire rundene.

**Viktig korreksjon (P0):** den opprinnelige auditen sa at ingen sårbar npm-kode når nettleseren.
Det stemte ikke for `axios` — den importeres i `resources/js/bootstrap.js`, brukes som
`window.axios` i kundefrontenden, og lå i den bygde bundlen. Plasseringen under `devDependencies`
sier ingenting; Vite bundler etter importgrafen.

#### Vite 6 — hva som ble kontrollert

Alle kompatibilitetsgater ble sjekket før oppgraderingen: `vite@6.4.3` engines
`^18.0.0 || ^20.0.0 || >=22.0.0` (Node 18.15.0 OK), `laravel-vite-plugin@1.3.0` peer `^5 || ^6`,
`@vitejs/plugin-react@4.7.0` peer opptil `^7`, `@tailwindcss/vite@4.2.2` peer `^5.2 || ^6 || ^7 || ^8`.
Ingen plugin måtte oppgraderes, og verken `--force`, `--legacy-peer-deps` eller `engine-strict=false`
ble brukt. 6.4.3 er både minste trygge og nyeste 6.x.

Verifisert: produksjonsbygg grønt (939 moduler, 6,35 s) med alle tre entrypoints i manifestet;
43 unit-tester; Vite 6 dev-server startet midlertidig på port 5199 med fungerende HMR
(`/@vite/client` 200) og React-plugin (`/@react-refresh` 200), stoppet ryddig; 14 Playwright-tester
mot Vite 6-bundlen. `public/hot` ble overskrevet av dev-server-testen og gjenopprettet
byte-identisk (checksum verifisert).

**Produksjon krever ingen endring.** `docker/production/Dockerfile` bygger frontend på
`node:22-bookworm-slim` med `npm ci` og en gate på `test -f public/build/manifest.json`. Node 22
dekker Vite 6 med margin.

**Merk:** `concurrently@9.2.4` pinner fortsatt `shell-quote` eksakt (`1.9.0`). En framtidig
`shell-quote`-advisory vil derfor kreve en ny `concurrently`-oppgradering.

#### Node-baseline — standardisert på Node 22

**Repoet er standardisert 29. august 2026.** Dette er livssyklus/support, ikke en advisory.

| Flate | Node |
|---|---|
| CI (`.github/workflows/dependency-audit.yml`) | 22 |
| Produksjonsbygg (`docker/production/Dockerfile`, frontend-stage) | 22 (`node:22-bookworm-slim`) |
| Lokal utvikling — prosjektkrav | 22 (`engines` i `package.json`, `.nvmrc`) |

Kravet er uttrykt som `"engines": { "node": ">=22 <23" }`. Øvre grense er bevisst: uten den ville
Node 23/24 stilltiende regnes som støttet uten at noen har testet det. `.nvmrc` inneholder `22`.
`engine-strict` er **ikke** slått på — npm advarer ved feil major, men blokkerer ikke, slik at en
utvikler midt i et bytte ikke låses ute. Å gjøre det blokkerende er en egen beslutning.

**Node 18 er ikke lenger en støttet Procynia-versjon.**

Skill mellom to ting:

- **Prosjektbaseline:** Node 22, standardisert og håndhevet i repoet. Ferdig.
- **Denne utviklerhosten:** kjører fortsatt fysisk Node v18.15.0. Den må oppgraderes manuelt av
  utvikleren; det ble bevisst ikke gjort automatisk. Fram til da vil `npm` gi
  `EBADENGINE`-advarsel — det er beviset på at kravet er uttrykt, ikke en feil.

Frontend-testene kjøres med `npm run test:unit`. Kommandoen sender filmønsteret i anførselstegn slik
at Node — ikke skallet — utfører globbingen; Node 22 tolker et rent katalogargument som en fil som
skal lastes, så den eldre formen `node --test resources/js` fant ingenting. Verifisert grønn på
Node 22: 232 tester i 43 suiter.

Node 22 fjerner samtidig den eksisterende advarselen om at `@tailwindcss/oxide@4.2.2` krever
Node ≥ 20, og er en forutsetning for en eventuell framtidig Vite 8.

#### 10.3 CI-gate for dependency-advisories

**Implementert 29. august 2026:** `.github/workflows/dependency-audit.yml`.

Dette var repoets **første** CI-oppsett — det fantes ingen GitHub Actions, GitLab CI, Azure
Pipelines eller Jenkins fra før. Workflowen er derfor bevisst smal og bygger ikke ut noen bredere
CI-arkitektur.

| | |
|---|---|
| Plattform | GitHub Actions (`origin` er `github.com/GerhardNaess/Procynia`) |
| Trigger | `pull_request` og `push` til `main` |
| Jobber | `composer-audit` og `npm-audit`, parallelle og uavhengige |
| PHP | 8.4 — samme som `docker/php/Dockerfile` |
| Node | 22 — samme som frontend-stagen i `docker/production/Dockerfile` |
| Rettigheter | `contents: read` |

```
composer-audit:  checkout -> setup-php 8.4 -> composer install (lockfil, --no-scripts) -> composer audit
npm-audit:       checkout -> setup-node 22 -> npm ci --no-audit --no-fund              -> npm audit
```

**Policy: én advisory er nok til å feile builden.** Ingen ignore-liste, ingen severity-terskel
(`--ignore-severity`, `audit-level`), ingen `continue-on-error`, ingen `|| true`. Å akseptere en
konkret advisory midlertidig skal være en dokumentert risikobeslutning, ikke en stille default i
workflow-filen.

Gaten installerer fra lockfilene (`composer install`, `npm ci`) og oppdaterer aldri avhengigheter.
Den kjører verken database, Redis eller frontend-bygg — den trenger bare PHP, Node og de to
lockfilene, som holder den rask og fri for flakiness som ikke handler om dependency-sikkerhet.
`composer install` kjøres med `--no-scripts` fordi `package:discover` og `filament:upgrade` ellers
ville krevd en bootbar app med `APP_KEY`; installasjonen beholdes likevel fordi den samtidig
beviser at lockfilen fortsatt lar seg installere.

`setup-php` må eksplisitt be om `bcmath`, `gd`, `intl` og `zip` — de er ikke med i standardsettet,
men kreves av `moneyphp/money`, `phpoffice/phpword`, `filament/support` og `openspout/openspout`.
Uten dem stopper `composer install` på platform-krav før auditen i det hele tatt kjører.

Ikke med i denne runden, som egne beslutninger: scheduled/cron-scan (advisory-databaser endrer seg
uten at koden gjør det), og Dependabot/Renovate for automatiske oppgraderings-PR-er. Vi
automatiserer deteksjon først.

#### Gjenstående tiltak

Begge auditene er rene, CI-gaten er på plass, og Node-baselinen er standardisert på 22.
Det eneste utestående på dette området er en manuell handling utenfor repoet: **utviklerhosten
kjører fortsatt fysisk Node 18.15.0** og må oppgraderes til Node 22 (se §10.2).

---

## 11. Planlagt

Besluttet, men ikke bygget. Ingenting av dette finnes i koden i dag.

### Microsoft Entra ID / SSO
Prinsipp: **Entra ID avgjør hvem brukeren er. Procynia avgjør hva brukeren får gjøre.**

Dagens `users`-tabell forutsetter lokal passordautentisering og har ingen kobling til en ekstern
identitetsleverandør. Fremtidig modell krever nye tabeller for identitetsleverandør per kunde og for
kobling mellom Procynia-bruker og ekstern identitet, samt at passord blir valgfritt.

Se strukturvurdering i [`docs/security/security-audit-2026-08.md`](../security/security-audit-2026-08.md).

### Azure-sikkerhet
Managed Identity mot Key Vault, Blob og øvrige tjenester i stedet for statiske credentials;
private endpoints; brannmurbegrensning; TLS gjennomgående. Se
[`docs/azure-runtime-contract.md`](../azure-runtime-contract.md) og
[`infra/README.md`](../../infra/README.md).

### Øvrig
- Policies for kjernemodellene
- Samlet audit trail for administrative handlinger
- Malwareskanning av opplastede dokumenter
- Automatisk avhengighetsskanning i CI

---

## 12. Hva som ikke er dekket av denne gjennomgangen

- Penetrasjonstesting mot kjørende miljø
- Sikkerhetsgjennomgang av AI-promptkonstruksjon (prompt injection)
- Produksjonsinfrastruktur: TLS, brannmur, nettverkssegmentering, faktisk logglagring
- Fysisk og organisatorisk sikkerhet
- GDPR-vurdering og databehandleravtaler
- Hendelseshåndteringsrutine (finnes ikke som dokument i dag)

---

## 12. Security Baseline Closure — 30. august 2026

Baseline-arbeidet er avsluttet. Alt som gjelder **dagens applikasjon og dagens deploymodell** er
lukket eller eksplisitt klassifisert. Full begrunnelse: `docs/security/security-audit-2026-08.md`,
seksjonen «Security Baseline Closure».

### LUKKET

F-01 brute-force · trusted proxy · F-02 Stripe webhook · F-03 Filament-tilgang ·
**F-04 autorisasjon** · F-05 security headers/CSP · F-06 sessionscookie · F-07 Composer ·
F-08 Redis-auth · F-11 npm · dependency-audit i CI (nå også **daglig scheduled**) · Node 22 ·
**AI prompt injection** · **ui-avatars.com-lekkasjen**

### Autorisasjon (F-04) — hvordan den faktisk håndheves

Kundefrontenden bruker route model binding, men stoler aldri på den bundne instansen. Hver action
re-deriverer posten fra tenanten:

```php
$record = $this->scopedDocument($customerId, $knowledgeItem->id);
```

Den bundne modellen leverer en id. En annen kundes post gir **404, ikke 403** — den er usynlig, ikke
bare forbudt. Alle 84 bindende actions er verifisert tenant-håndhevet.

Policies ble bevisst ikke innført: de ville kjørt etter et uscopet oppslag og dermed flyttet
håndhevingen utover. `tests/Feature/Security/CrossTenantIsolationTest.php` vokter mekanismen med sju
atferdstester og to strukturelle.

### AI-sikkerhet

Modellen er ikke en security boundary. Kontrollene ligger utenfor den: retrieval er kundescoped i
SQL før noe sendes, modellen har **ingen tools eller function calling**, ingenting den returnerer
dispatches som kode, og strukturert output valideres mot JSON-schema. I tillegg merkes untrusted
innhold konsekvent som data via `App\Services\Ai\AiPromptSecurity`.

### AKSEPTERT RESIDUAL RISIKO

- `style-src 'unsafe-inline'` og `/admin`-ens `script-src 'unsafe-inline' 'unsafe-eval'` — upstream
  Filament-behov uten nonce-støtte; panelet er internt-admin-only og lettelsen er scopet.
- F-09 lokalt testpassord, F-10 `APP_DEBUG` i eksempelfil, F-12 Filament-versjon for innlogget
  intern admin.
- Arkivbomber: 20 MB-grense og købasert parsing framfor eksplisitte entry-count-grenser.

### FREMTIDIG ARKITEKTUR

Ett spor: **Azure-migrering og identitet** — private endpoints, Key Vault, Managed Identity,
Redis TLS, Entra ID/SSO. Endrer plattformen, ikke dagens applikasjonssikkerhet.

### BLOKKERT AV EKSTERN INFRASTRUKTUR

**Malwareskanning.** Ingen scanner finnes i dagens runtime (verifisert: ingen `clamscan`/`clamd` på
host eller i container). Upload-pipelinen er hardnet så langt det går uten scanner. Løses med
Defender for Storage i Azure eller en egen scanner-sidecar som driftsoppgave.

### Status

**Ingen kjente åpne HIGH/CRITICAL funn i dagens applikasjon. Security baseline: LUKKET.**
