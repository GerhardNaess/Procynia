# Procynia — Azure runtime contract

Hva en Procynia-container må få av omverdenen for å fungere, og hva den ikke skal få.

Dette er kilden til sannhet for env-variabler i Azure Container Apps. Bicep-implementasjonen ligger i
[`infra/main.bicep`](../infra/main.bicep) (`sharedEnvironmentVariables` og `secretBindings`).
Verifiseringen ligger i `tests/Feature/Azure/` og i `php artisan ops:runtime-check`.

---

## 1. Runtime-fakta (verifisert, ikke antatt)

| | Verdi | Hvordan bekreftet |
|---|---|---|
| PHP | **8.4** (`php:8.4-fpm-bookworm`, container rapporterer 8.4.24) | `php -r 'echo PHP_VERSION;'` i imaget |
| PostgreSQL | **16** (`pgvector/pgvector:0.8.2-pg16` lokalt, Flexible Server 16 i Azure) | `select version()` |
| pgvector | **i bruk**, extension 0.8.2 | `select extversion from pg_extension where extname='vector'` |
| Node (kun byggetid) | 22 LTS | `docker/production/Dockerfile`, frontend-stage |
| Redis | 7 lokalt, Azure Managed Redis i Azure | `redis-cli ping` / `Redis::ping()` |
| Køer | 9 navngitte, hver med egen worker | `tests/Feature/Azure/QueueTopologyContractTest.php` |
| Poppler | `pdftotext`/`pdftohtml`/`pdfimages`/`pdfinfo` i `/usr/bin` | `ops:runtime-check` |

`CLAUDE.md` sa tidligere PHP 8.3 og at pgvector ikke var i bruk. Begge deler var feil målt mot
runtime og er rettet.

---

## 2. Env-kontrakt

Fire kategorier. **Required secret** kommer fra Key Vault; **required non-secret** settes som vanlig
container-env; **optional** har fornuftig default i Laravel; **image default** settes av imaget selv.

### Required secret — Key Vault-referanser

| Variabel | Key Vault-secret | Konsekvens hvis den mangler |
|---|---|---|
| `APP_KEY` | `APP-KEY` | Sesjoner og krypterte kolonner virker ikke. Genereres **aldri** automatisk. |
| `DB_PASSWORD` | `DB-PASSWORD` | Ingen databasetilkobling. |
| `REDIS_URL` | `REDIS-URL` | Ingen kø, cache eller sesjon. Format: `tls://default:<key>@<host>:10000/0` |
| `OPENAI_API_KEY` | `OPENAI-API-KEY` | All AI-funksjonalitet feiler. |
| `DOFFIN_API_KEY` | `DOFFIN-API-KEY` | Doffin-import feiler. |
| `PROCYNIA_HEALTH_TOKEN` | `PROCYNIA-HEALTH-TOKEN` | `/health/*` og `/ops/health/*` svarer 503. |

Valgfrie, av som standard: `STRIPE-KEY`/`STRIPE-SECRET`/`STRIPE-WEBHOOK-SECRET`
(`includeStripeSecrets`), `MAIL-USERNAME`/`MAIL-PASSWORD` (`includeMailSecrets`).

### Required non-secret

| Variabel | Staging | Production | Merknad |
|---|---|---|---|
| `APP_ENV` | `staging` | `production` | |
| `APP_DEBUG` | `false` | `false` | `ops:runtime-check` feiler hvis true utenfor local/testing |
| `APP_URL` | Container Apps FQDN | eget domene | Må være `https://` |
| `DB_CONNECTION` | `pgsql` | `pgsql` | |
| `DB_HOST` | Flexible Server FQDN | samme | |
| `DB_PORT` | `5432` | `5432` | |
| `DB_DATABASE` | `procynia` | `procynia` | |
| `DB_USERNAME` | `procynia_admin` | samme | |
| `DB_SSLMODE` | `require` | `require` | Azure krever TLS |
| `CACHE_STORE` | `redis` | `redis` | |
| `SESSION_DRIVER` | `redis` | `redis` | |
| `SESSION_CONNECTION` | `default` | `default` | |
| `SESSION_STORE` | `redis` | `redis` | |
| `SESSION_SECURE_COOKIE` | `true` | `true` | Ingress terminerer TLS |
| `QUEUE_CONNECTION` | `redis` | `redis` | |
| `REDIS_CLIENT` | `phpredis` | `phpredis` | |
| `REDIS_DB` | `0` | `0` | Azure Managed Redis har kun database 0 |
| `REDIS_CACHE_DB` | `0` | `0` | Samme grunn |
| `REDIS_QUEUE_RETRY_AFTER` | per worker | per worker | Se køtabellen i readiness-rapporten |
| `FILESYSTEM_DISK` | `local` | `local` | Blob er target state, ikke implementert |
| `LOG_CHANNEL` | `stderr` | `stderr` | Ingen persistent disk å logge til |
| `LOG_LEVEL` | `info` | `info` | |
| `TZ` | `Europe/Oslo` | `Europe/Oslo` | Scheduler bruker Oslo eksplisitt |
| `PROCYNIA_LEGACY_BACKUP_ENABLED` | `false` | `false` | Compose-backup kan ikke kjøre i Container Apps |
| `DOFFIN_BASE_URL` | `https://api.doffin.no` | samme | `config/doffin.php` har ingen default |

### Image default — settes av imaget, trenger ikke settes i Azure

`PDFTOTEXT_BINARY`, `PDFTOHTML_BINARY`, `PDFIMAGES_BINARY`, `PDFINFO_BINARY` → `/usr/bin/...`

Disse er egenskaper ved imaget, ikke ved miljøet. `config/services.php` leser dem uten fallback, så
uten dem har dokumentpipelinen ingen tekstekstraktor. IaC setter samme verdier, som er en ufarlig
no-op.

### Optional — bevisst ikke satt

`APP_LOCALE` (default `no`), `OPENAI_BASE_URL`/`OPENAI_MODEL`/`OPENAI_EMBEDDING_MODEL`,
`BCRYPT_ROUNDS`, øvrige `DOFFIN_*`-endepunkter. Laravel-configen har allerede riktig verdi.

`PROCYNIA_OPTIMIZE_ON_BOOT` (default `true`) styrer om entrypoint varmer config/route/view-cache.
Sett `false` for å feilsøke oppstart.

### Skal aldri settes

`APP_KEY` som literal i IaC · `.env`-fil i imaget (utelukket i `.dockerignore`) ·
`DB_URL`/`REDIS_URL` med credentials i klartekst i param-filer.

---

## 3. Opplastingsgrenser

Fire lag, som må stå i riktig rekkefølge — applikasjonen strammest, så en for stor fil gir en
valideringsmelding i stedet for en rå 413 fra proxyen.

| Lag | Grense | Hvor |
|---|---|---|
| Laravel-validator | **20 MB** (`max:20480` KB) | `WikiSourceController`, `KnowledgeBaseController` |
| PHP `upload_max_filesize` | 50 MB | `docker/production/php.ini` |
| PHP `post_max_size` | 50 MB | `docker/production/php.ini` |
| nginx `client_max_body_size` | 50 MB | `docker/production/nginx.conf` |
| Container Apps ingress | ikke konfigurerbar fra Bicep | Azure-side |

**20 MB er den faglig definerte Procynia-grensen** og er ikke endret. Den er allerede den strammeste,
så ingen grense ble hevet i denne fasen. Rekkefølgen håndheves av
`tests/Feature/Azure/ContainerRuntimeContractTest.php`.

Det ene gjenstående ukjente er ingress-grensen i Container Apps. Den kan ikke settes fra Bicep og må
verifiseres i staging med en fil rett under 20 MB — se runbookens fase 9.

---

## 4. Preflight

```bash
php artisan ops:runtime-check --azure
```

Kjøres inne i en container. Read-only bortsett fra en scratch-fil på storage-disken, som slettes
igjen. Skriver aldri en secret til output — sensitive verdier rapporteres som tilstedeværelse og
form, aldri innhold, og feilmeldinger fra PDO/Redis blir redigert før de vises.

Sjekker: `APP_KEY` · `APP_ENV`/`APP_DEBUG` · `APP_URL` · 17 PHP-extensions · PostgreSQL ·
pgvector · Redis · storage-disk skrivbar · shared storage path · fire poppler-binærer
(`pdftotext` faktisk kjørt) · logging-kanal · legacy backup · køtilkobling ·
OpenAI (opt-in via `--with-openai`, koster ett API-kall).

Exit 1 ved kritisk feil, så den kan brukes som gate i et deploy-steg. `--json` for maskinlesbar
output.

---

## 5. Image-kontrakt

To images, bygget fra `docker/production/Dockerfile`:

| Target | Bruk | Kommando |
|---|---|---|
| `--target app` | queue workers, scheduler, migration job | `php artisan queue:work ...` / `schedule:work` / `migrate` |
| `--target web` | HTTP, bak Container Apps ingress | supervisord → nginx (8080) + php-fpm (127.0.0.1:9000) |

`web` bygges `FROM app`, så en web-replica og en worker-replica kjører **byte-identisk**
applikasjonskode. Smoke-testen verifiserer dette med en sha256 over `app/`, `config/` og `routes/`.

Egenskaper som håndheves i selve builden (`RUN test ...`): applikasjonskode til stede, vendor-tre til
stede, `public/build/manifest.json` til stede, **ingen `.env`**, alle fire poppler-binærer kjørbare.

To ting er verdt å kjenne til:

- **`clear_env = no`** i php-fpm-poolen. php-fpm tømmer miljøet til worker-prosessene sine som
  standard. Under Compose gikk det upåaktet hen fordi alt også lå i en `.env`. I Container Apps
  finnes ingen `.env`, så uten dette ville PHP-prosessene ikke sett en eneste av
  Container Apps-variablene og applikasjonen ville bootet mot config-defaults — altså mot
  `127.0.0.1`.
- **Config caches ikke ved build**, kun i entrypoint. `config:cache` på byggetidspunktet ville bakt
  inn byggetidens defaults og gjort hver eneste runtime-variabel virkningsløs.

---

## 6. Hva som verifiserer hva

| Påstand | Verifisert av |
|---|---|
| Imaget kjører uten source bind mount | `scripts/azure-readiness/production-image-smoke.sh` |
| Web og worker har identisk kode | samme script, sha256-sammenligning |
| Env-kontrakten er komplett nok til å boote | `tests/Feature/Azure/StatelessRuntimeContractTest.php` |
| Runtime har alt den trenger | `php artisan ops:runtime-check --azure` |
| Køkontrakten henger sammen | `tests/Feature/Azure/QueueTopologyContractTest.php` |
| Opplastingsgrensene er i riktig rekkefølge | `tests/Feature/Azure/ContainerRuntimeContractTest.php` |
| Legacy backup kan ikke kjøre i Azure | `tests/Feature/Operations/LegacyBackupRuntimeGuardTest.php` |
