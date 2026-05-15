# E2E-testing i Procynia

## Verktøy

**Playwright** (`@playwright/test`) — kjøres med Chromium.

Konfigurasjonsfil: `playwright.config.js`
Testfiler: `tests/e2e/`

---

## Forutsetninger

### 1. Node-avhengigheter

```bash
npm install
```

### 2. Playwright-nettlesere

```bash
npx playwright install chromium
```

### 3. Appserver kjører

E2E-testene krever at appen er oppe og tilgjengelig. Standardadressen er `http://localhost:8080`.

**Med Docker (anbefalt):**

```bash
docker compose up -d
```

**Uten Docker (lokal PHP):**

```bash
composer dev
```

---

## Testdata

E2E-testene bruker en dedikert seeder som oppretter stabile testbrukere.

Seederen kjøres automatisk som en Playwright global setup før testene starter, men kan også kjøres manuelt:

**Med Docker:**

```bash
docker compose exec app php artisan db:seed --class=E2ETestSeeder
```

**Uten Docker:**

```bash
php artisan db:seed --class=E2ETestSeeder
```

Seederen er idempotent — trygt å kjøre flere ganger.

### Testbrukere

| Rolle | E-post | Passord |
|---|---|---|
| Super admin (intern, ingen kunde) | `e2e.superadmin@procynia.test` | `E2eAdmin123!` |
| System owner (kundeadmin med faktureringstilgang) | `e2e.systemowner@procynia.test` | `E2eUser123!` |
| Vanlig bruker (contributor) | `e2e.user@procynia.test` | `E2eUser123!` |

Alle brukere bruker ikke-reelle `.procynia.test`-domener og kan ikke tilhøre ekte kontoer.

---

## Kjøre testene

### Headless (standard, for CI og script-kjøring)

```bash
npm run test:e2e
```

### Med synlig nettleser (for utvikling og feilsøking)

```bash
npm run test:e2e:headed
```

### Interaktivt UI

```bash
npm run test:e2e:ui
```

### Egendefinert app-URL

```bash
PLAYWRIGHT_BASE_URL=http://localhost:8000 npm run test:e2e
```

---

## Hva testene dekker

| Testfil | Brukerflyt |
|---|---|
| `auth.spec.js` | Login-side laster, kundebruker logger inn, super admin logger inn, ugyldig innlogging, uautorisert bruker redirectes |
| `admin.spec.js` | Super admin får tilgang til Filament-panel og avviksregister, kundebruker blokkeres fra adminressurser, uautentisert bruker redirectes til admin-login |
| `app-navigation.spec.js` | Kunngjøringer, dashboard og AI-workspace laster for autentisert kundebruker; vanlig bruker blokkeres fra billing |
| `billing.spec.js` | System owner kan åpne faktureringssiden |

---

## Ekstern API-isolasjon

E2E-testene gjør **ingen** ekte kall til:

- OpenAI (AI-siden laster kun oversikt over eksisterende saker)
- Stripe (billing-controlleren bruker `rescue()` og viser tom tilstand)
- Doffin
- E-postleverandør (SMTP)

---

## Kjøring i Docker-miljø

Playwright kjøres fra host-maskinen og retter seg mot appen på `http://localhost:8080` (Docker nginx).

Seederen må kjøres inne i Docker-kontaineren siden den kobler til Docker-databasen:

```bash
docker compose exec app php artisan db:seed --class=E2ETestSeeder
npm run test:e2e
```

---

## Avgrensninger i første lag

Dette er det første stabile E2E-laget. Det dekker ikke:
- Dokumentopplasting med ekte filer
- AI-generering (krever ekte OpenAI-kall)
- Stripe-betalingsflyt
- Doffin-søk
- Mobil viewport
- Alle app-sider

Testlaget kan utvides etterhvert som produktet modnes.
