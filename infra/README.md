# Procynia på Azure — infrastruktur som kode

Denne katalogen inneholder hele Azure-infrastrukturen for Procynia som Bicep.
Den oppretter ingen ressurser av seg selv: alt går gjennom `deploy.sh`, og
`deploy.sh` gjør ingenting uten `--apply`.

Denne fasen dekker **infrastruktur**. Den migrerer ikke data, endrer ikke
applikasjonskode, og deployer ikke produksjon.

---

## 1. Hva infrastrukturen består av

```
infra/
├── main.bicep                  Orkestrering, navnestrategi, env-var-kontrakt, secret-kontrakt
├── types.bicep                 Delte typer (queueWorker, secretBinding, firewallRule)
├── modules/
│   ├── monitoring.bicep                 Log Analytics + Application Insights
│   ├── identity.bicep                   User-assigned managed identity
│   ├── registry.bicep                   Azure Container Registry + AcrPull
│   ├── key-vault.bicep                  Key Vault (RBAC) + rolletildelinger + DB-PASSWORD
│   ├── storage.bicep                    Storage Account + Azure Files share + blob container
│   ├── postgres.bicep                   PostgreSQL Flexible Server 16 + vector-allowlist
│   ├── redis.bicep                       Azure Managed Redis + REDIS-URL i Key Vault
│   ├── container-apps-environment.bicep Container Apps Environment + Azure Files-mount
│   └── container-apps.bicep             web, queue workers, scheduler
├── environments/
│   ├── staging.bicepparam      Små, billige defaults
│   └── production.bicepparam   Konservative defaults (aldri deployet ennå)
├── deploy.sh                   validate → what-if → (valgfritt) deploy
├── validate.sh                 Offline build/kontroll, krever ikke Azure-login
└── README.md
```

Azure-ressurser som dekkes:

| Ressurs | Type | Rolle |
|---|---|---|
| Container Registry | `Microsoft.ContainerRegistry/registries` | Images for web og app |
| Container Apps Environment | `Microsoft.App/managedEnvironments` | Delt runtime + Azure Files-definisjon |
| Container Apps | `Microsoft.App/containerApps` | 1 web + 7 queue workers + 1 scheduler |
| PostgreSQL Flexible Server | `Microsoft.DBforPostgreSQL/flexibleServers` | PG 16, database, `azure.extensions=VECTOR` |
| Azure Managed Redis | `Microsoft.Cache/redisEnterprise` | Queue, cache, session |
| Storage Account | `Microsoft.Storage/storageAccounts` | Azure Files share + blob container |
| Key Vault | `Microsoft.KeyVault/vaults` | Alle applikasjonssecrets |
| Log Analytics | `Microsoft.OperationalInsights/workspaces` | Container-logger |
| Application Insights | `Microsoft.Insights/components` | Klargjort, ikke instrumentert ennå |
| Managed Identity | `Microsoft.ManagedIdentity/userAssignedIdentities` | ACR pull + Key Vault-lesing |

OpenAI er fortsatt ekstern tjeneste. Azure OpenAI er ikke del av denne fasen.
E-post er ikke bygget om; begge miljøer starter på `MAIL_MAILER=log`.

### Navnestrategi

Alt utledes fra `application=procynia`, `environmentName` og `location`:

| Ressurs | Mønster | Eksempel (staging) |
|---|---|---|
| Resource group | `rg-procynia-<env>-<region>` | `rg-procynia-staging-norwayeast` |
| Log Analytics | `log-procynia-<env>` | `log-procynia-staging` |
| App Insights | `appi-procynia-<env>` | `appi-procynia-staging` |
| Managed identity | `id-procynia-<env>` | `id-procynia-staging` |
| Container registry | `acrprocynia<env><suffix>` | `acrprocyniastagingab12c` |
| Key Vault | `kv-procynia-<kort>-<suffix>` | `kv-procynia-stg-ab12c` |
| Storage | `stprocynia<kort><suffix>` | `stprocyniastgab12c` |
| PostgreSQL | `psql-procynia-<env>-<suffix>` | `psql-procynia-staging-ab12c` |
| Redis | `redis-procynia-<env>-<suffix>` | `redis-procynia-staging-ab12c` |
| Container Apps Env | `cae-procynia-<env>` | `cae-procynia-staging` |
| Container Apps | `procynia-<kort>-<rolle>` | `procynia-stg-web` |

`<suffix>` er `take(uniqueString(resourceGroup().id), 5)` — deterministisk per
resource group, og nødvendig fordi ACR-, Key Vault-, Storage-, PostgreSQL- og
Redis-navn er globalt unike. `<kort>` er `stg` / `prd`, brukt der Azure har
harde navnelengdegrenser.

Region er parameterstyrt (`location`, default `norwayeast`) og kan overstyres
med `PROCYNIA_AZURE_LOCATION`.

Alle ressurser tagges med `application=procynia`, `environment=<env>` og
`managed-by=bicep`. Container Apps får i tillegg `workload` og — for workers —
`queues`.

---

## 2. Forutsetninger

- Azure-subscription med rettigheter til å opprette resource groups og
  rolletildelinger (`Owner` eller `Contributor` + `User Access Administrator`).
- `Microsoft.App`, `Microsoft.DBforPostgreSQL`, `Microsoft.Cache`,
  `Microsoft.ContainerRegistry`, `Microsoft.KeyVault`, `Microsoft.OperationalInsights`
  og `Microsoft.Insights` registrert på subscription.
- Region med kapasitet for Azure Managed Redis og Container Apps.
  `norwayeast` er default, men verifiser SKU-tilgjengelighet med what-if.

---

## 3. Azure CLI / Bicep

```bash
# Azure CLI
brew install azure-cli          # macOS
az bicep install                # eller: az bicep upgrade
az version
```

`validate.sh` bruker `bicep` fra `PATH` hvis den finnes, ellers `az bicep`.
Du trenger også `python3` (brukes til parameterkontroll) og gjerne `shellcheck`.

---

## 4. Velge subscription

```bash
az login
az account list --output table
export PROCYNIA_SUBSCRIPTION="<subscription-id eller navn>"
```

`deploy.sh` setter subscription hvis `PROCYNIA_SUBSCRIPTION` er satt, og skriver
alltid ut aktiv subscription, tenant, region og resource group før noe skjer.

---

## 5. Validere lokalt

```bash
./infra/validate.sh
```

Dette krever ingen Azure-login og oppretter ingenting. Det kontrollerer at:

- `main.bicep` og alle moduler kompilerer
- begge `.bicepparam` kompilerer
- alle template-parametere uten default faktisk er satt i begge miljøfiler
- `deploy.sh` og `validate.sh` består `bash -n`, og `shellcheck` hvis installert

Forskjellen mot Azure-validering: lokal build sier ingenting om SKU-tilgjengelighet
i regionen, kvote, navnekollisjoner på globalt unike navn, RBAC-rettigheter, eller
hva en endring faktisk gjør med eksisterende ressurser. Det krever punkt 6.

---

## 6. Kjøre what-if

```bash
export PROCYNIA_PG_ADMIN_PASSWORD='<sterkt passord>'
./infra/deploy.sh staging
```

Uten `--apply` kjører scriptet `az deployment group validate` og
`az deployment group what-if` og stopper der. Ingenting endres.

Hvis resource group ikke finnes ennå, kan ikke Azure kjøre validate/what-if.
Scriptet sier det tydelig og avslutter uten å opprette noe.

---

## 7. Deploye staging

Deployment er tofaset, fordi Container Apps ikke kan starte før imaget finnes i
ACR og secrets finnes i Key Vault.

### Fase 1 — plattform

```bash
export PROCYNIA_SUBSCRIPTION='<subscription>'
export PROCYNIA_PG_ADMIN_PASSWORD='<sterkt passord>'

./infra/deploy.sh staging --apply
```

Dette oppretter resource group, ACR, Log Analytics, Application Insights,
Key Vault, Storage (Files + blob), PostgreSQL, Redis, managed identity med
rolletildelinger, og Container Apps Environment med Azure Files-mounten.
`DB-PASSWORD` og `REDIS-URL` legges i Key Vault av deploymentet.

### Fase 2 — secrets

Se punkt 9.

### Fase 3 — images

```bash
ACR="$(az deployment group show -g rg-procynia-staging-norwayeast \
        -n <deployment-navn> --query properties.outputs.containerRegistryName.value -o tsv)"

az acr login --name "$ACR"
# Bygg og push procynia-web:<tag> og procynia-app:<tag> — se "Image-kontrakt" nedenfor.
```

### Fase 4 — database-migrering

Se punkt 11. Migreringen er det som oppretter `vector`-extensionen.

### Fase 5 — workloads

```bash
export PROCYNIA_IMAGE_TAG='<immutable tag>'
./infra/deploy.sh staging --apply --with-apps
```

---

## 8. Deploye production senere

Samme script, samme rekkefølge:

```bash
export PROCYNIA_SUBSCRIPTION='<production-subscription>'
export PROCYNIA_PG_ADMIN_PASSWORD='<sterkt passord>'
export PROCYNIA_IMAGE_TAG='<immutable tag eller digest>'

./infra/deploy.sh production                    # bare what-if
./infra/deploy.sh production --apply            # plattform
./infra/deploy.sh production --apply --with-apps
```

`production.bicepparam` er aldri deployet. Behandle første kjøring som en
what-if-gjennomgang, ikke en release. `deploy.sh` nekter å deploye production
workloads uten `PROCYNIA_IMAGE_TAG` satt, nettopp for å unngå `latest`.

### Staging vs production

| | staging | production |
|---|---|---|
| ACR | Basic | Standard |
| Log Analytics retention | 30 dager, 2 GB/dag cap | 90 dager, ingen cap |
| Key Vault purge protection | av (miljøet skal kunne rives) | på (irreversibelt) |
| Key Vault soft delete | 7 dager | 90 dager |
| Storage | Standard_LRS, 100 GiB share | Standard_ZRS, 500 GiB share |
| Files soft delete | 7 dager | 30 dager |
| Blob soft delete | 14 dager | 30 dager |
| PostgreSQL | Burstable `Standard_B1ms`, 32 GiB | GeneralPurpose `Standard_D2ds_v5`, 128 GiB |
| PostgreSQL backup | 7 dager, ikke geo-redundant | 35 dager, geo-redundant |
| PostgreSQL HA | Disabled (ikke støttet på Burstable) | ZoneRedundant |
| Redis | `Balanced_B0`, ingen HA | `Balanced_B1`, HA på |
| Web | 0.5 vCPU / 1 GiB, 1–2 replicas | 1.0 vCPU / 2 GiB, 2–6 replicas |
| Worker-prosesser | redusert (2/2/2) | som Compose (4/3/4) |
| Worker-ressurser | 0.25–0.5 vCPU | 0.5–1.0 vCPU |
| Doffin scheduled import | av | på |

Alt dette er parametere. Å endre dimensjonering krever ingen kodeendring.

---

## 9. Secrets

**Ingen secret-verdi ligger i repoet, i `.bicepparam`, eller i noe Bicep-output.**

Tre kategorier:

**a) Secrets Bicep provisjonerer selv**

| Key Vault-secret | Kilde |
|---|---|
| `DB-PASSWORD` | `@secure()`-parameter fra `PROCYNIA_PG_ADMIN_PASSWORD` |
| `REDIS-URL` | Leses fra Redis-ressursen som nettopp ble opprettet |

PostgreSQL kan ikke opprettes uten administratorpassord, så Bicep må kjenne det
uansett. `REDIS-URL` bygges som
`tls://default:<key>@<host>:10000/0` og skrives rett i Key Vault.

**b) Secrets du setter selv, etter fase 1**

```bash
KV="$(az deployment group show -g rg-procynia-staging-norwayeast \
       -n <deployment-navn> --query properties.outputs.keyVaultName.value -o tsv)"

# APP_KEY: generer med Laravel og bruk hele "base64:..."-strengen
az keyvault secret set --vault-name "$KV" --name APP-KEY                --value "base64:..."
az keyvault secret set --vault-name "$KV" --name OPENAI-API-KEY         --value "sk-..."
az keyvault secret set --vault-name "$KV" --name DOFFIN-API-KEY         --value "..."
az keyvault secret set --vault-name "$KV" --name PROCYNIA-HEALTH-TOKEN  --value "$(openssl rand -hex 32)"
```

Deploymentet skriver ut nøyaktig hvilke secrets som mangler i outputen
`keyVaultSecretsRequiringOperatorInput`.

**c) Secrets som er forberedt, men avslått**

- Stripe (`STRIPE-KEY`, `STRIPE-SECRET`, `STRIPE-WEBHOOK-SECRET`) — Laravel Cashier
  er installert, men fakturering er ikke bygget. Sett `includeStripeSecrets = true`
  når det skjer.
- Mail (`MAIL-USERNAME`, `MAIL-PASSWORD`) — sett `includeMailSecrets = true` når en
  reell mailer erstatter `MAIL_MAILER=log`.

### Hvordan containerne får secrets

Key Vault bruker Azure RBAC, ikke access policies. En **user-assigned managed
identity** (`id-procynia-<env>`) har:

- `AcrPull` på Container Registry
- `Key Vault Secrets User` på Key Vault
- `Storage Blob Data Contributor` og `Storage File Data SMB Share Contributor`
  på Storage Account (for blob-fasen senere)

Hver Container App refererer secreten som `keyVaultUrl` + `identity`, slik at
verdien hentes ved revisjonsstart og aldri lagres i templaten. Identityen er
user-assigned og ikke system-assigned nettopp fordi rolletildelingene må finnes
*før* appene opprettes.

Den som deployer får `Key Vault Secrets Officer` automatisk (`deploy.sh` slår opp
`az ad signed-in-user show`), slik at `az keyvault secret set` fungerer umiddelbart.
RBAC-propagering kan ta et halvt minutt; får du 403 rett etter fase 1, prøv igjen.

---

## 10. Hva som IKKE er automatisert ennå

- **Production Dockerfile.** Se «Image-kontrakt» under.
- **Database-migrering.** Ingen migration job kjøres av Bicep. Se punkt 11.
- **`CREATE EXTENSION vector`.** Kjøres av Laravel-migreringen, ikke av Bicep.
- **Datamigrering og filmigrering.** Punkt 11 og 12.
- **DNS og custom domain.** Container Apps default-hostname brukes.
- **Ny Laravel-basert backup-jobb for Azure.** Det finnes ingen, og det skal ikke lages en.
  Backup i Azure er PostgreSQL automated backup med point-in-time restore
  (`postgresBackupRetentionDays`: 7 dager staging / 35 dager production), blob soft delete og
  versioning, og Azure Files soft delete — alt konfigurert deklarativt i denne Bicep-en.
- **Private endpoints / VNet / Front Door / WAF.** Se «Nettverk» under.
- **App Insights-instrumentering.** Komponenten opprettes, men ingenting i
  applikasjonen rapporterer til den ennå.
- **CI/CD.** Ingen pipeline. `deploy.sh` kjøres manuelt.
- **Destroy.** Det finnes bevisst ikke noe destroy-script. Se punkt 16.

### Image-kontrakt

Bicep bygger ingen images. To repositories forventes i ACR:

**`procynia-app:<tag>`** — workers og scheduler.
- Kan være dagens `docker/php/Dockerfile` med applikasjonskoden kopiert inn
  (i stedet for bind-mountet) og dependencies installert.
- Må ha `php` på `PATH` og applikasjonen på `/var/www/html`.
- Må ha `poppler-utils` (`pdftotext`, `pdftohtml`, `pdfimages`, `pdfinfo` på
  `/usr/bin/`), `pdo_pgsql`, `pgsql`, `redis`, `intl`, `gd`, `zip`, `xsl`,
  `pcntl`, `bcmath`, `exif` — altså samme extension-sett som dagens Dockerfile.
- PHP 8.4 (dagens base er `php:8.4-fpm-bookworm`).
- Starter ingen HTTP-server. Container Apps overstyrer `command`/`args`.

**`procynia-web:<tag>`** — web.
- Må lytte på HTTP på én port (`webTargetPort`, default 8080) og svare 200 på `GET /up`.
- **Dette avviker fra `docker-compose.yml`,** som kjører nginx og php-fpm som to
  containere med `fastcgi_pass app:9000` og Dockers interne DNS-resolver.
  Container Apps ingress går til én port, så web trenger nginx og php-fpm i samme
  image (eller en tilsvarende single-process-server). Å bygge det er ikke del av
  denne oppgaven; kontrakten er dokumentert her slik at Container Apps-konfigurasjonen
  er riktig når imaget finnes.
- Samme extensions og systempakker som `procynia-app`: web-laget laster ned og
  forhåndsviser dokumenter og bruker de samme poppler-binærene.

Alle env-variabler og secrets settes av Container Apps. Imaget skal ikke inneholde
en `.env` med miljøverdier.

### Legacy Compose-backup er deaktivert i Azure

`procynia:backup` kjøres fra Laravel-scheduleren og ender i `scripts/backup-production.sh`, som kjører
`docker compose exec -T postgres pg_dump`. Container Apps har verken Docker CLI eller et
compose-prosjekt, så mekanismen kan ikke fungere her.

Den er derfor slått av med en eksplisitt applikasjonskontrakt, ikke med heuristikk:

```
PROCYNIA_LEGACY_BACKUP_ENABLED=false
```

Verdien settes av `param legacyBackupEnabled` (default `false`), og er en del av den **delte**
env-var-kontrakten — altså satt på web, alle workers og scheduleren. Grunnen til at den ikke bare
settes på scheduleren: en manuell `php artisan procynia:backup`, eller knappen «Kjør manuell backup» i
admin-panelet, ville ellers nådd scriptet fra hvilken som helst container.

Dette er bevisst **ikke** det samme som `backup_settings.backup_enabled` i databasen. Det flagget sier
om en operatør har slått på backup; dette sier om runtime i det hele tatt kan kjøre mekanismen. En
database migrert fra Compose kan ankomme Azure med `backup_enabled = true`, og runtime-guarden stopper
den uansett.

Utenfor Azure er default `true`, slik at eksisterende Compose-miljøer er uendret.

Se `docs/azure-migration-test-readiness.md` seksjon 5 for verifiseringen.

### Runtime env-var-kontrakt

Settes på web, alle workers og scheduler:

```
APP_NAME, APP_ENV=<staging|production>, APP_DEBUG=false, APP_URL
LOG_CHANNEL=stderr, LOG_LEVEL=info, LOG_DEPRECATIONS_CHANNEL=null
DB_CONNECTION=pgsql, DB_HOST, DB_PORT=5432, DB_DATABASE, DB_USERNAME, DB_SSLMODE=require
REDIS_CLIENT=phpredis, REDIS_DB=0, REDIS_CACHE_DB=0
CACHE_STORE=redis, SESSION_DRIVER=redis, SESSION_CONNECTION=default, SESSION_STORE=redis
SESSION_SECURE_COOKIE=true, QUEUE_CONNECTION=redis
FILESYSTEM_DISK=local
MAIL_MAILER=log
TZ=Europe/Oslo
PDFTOTEXT_BINARY, PDFTOHTML_BINARY, PDFIMAGES_BINARY, PDFINFO_BINARY  (/usr/bin/...)
DOFFIN_BASE_URL, DOFFIN_SCHEDULED_IMPORT_ENABLED, DOFFIN_WATCH_INBOX_DISCOVERY_ENABLED
ENTERPRISE_WIKI_AI_ENABLED
PROCYNIA_LEGACY_BACKUP_ENABLED=false  (se eget avsnitt over)
PROCYNIA_ROLE  (web | queue-worker | scheduler)
```

Per workload: `REDIS_QUEUE_RETRY_AFTER` (og `PROCYNIA_QUEUES` på workers).

Fra Key Vault: `APP_KEY`, `DB_PASSWORD`, `REDIS_URL`, `OPENAI_API_KEY`,
`DOFFIN_API_KEY`, `PROCYNIA_HEALTH_TOKEN`.

Bevisst *ikke* satt, fordi Laravel-configen allerede har riktig default:
`APP_LOCALE` (`no`), `OPENAI_BASE_URL`, `OPENAI_MODEL`, `OPENAI_EMBEDDING_MODEL`,
`BCRYPT_ROUNDS`, `DOFFIN_*`-endepunkter.
Satt fordi configen *ikke* har default: `PDF*_BINARY` og `DOFFIN_BASE_URL`
(`config/services.php` og `config/doffin.php` leser `env()` uten fallback).

---

## 11. Databasemigrering og restore senere

**Rekkefølgen for pgvector er obligatorisk:**

1. **Azure PostgreSQL opprettes** — `deploy.sh <env> --apply` (fase 1).
2. **`vector` legges i allowlist** — `azure.extensions = VECTOR` settes
   deklarativt av `modules/postgres.bicep`. Uten dette avvises
   `CREATE EXTENSION vector` av Azure uansett hvilke rettigheter brukeren har.
3. **Migration job kjøres** — manuelt i denne fasen:
   ```bash
   az containerapp job create \
     --name procynia-stg-migrate \
     --resource-group rg-procynia-staging-norwayeast \
     --environment cae-procynia-staging \
     --trigger-type Manual --replica-timeout 1800 \
     --image "<acr>.azurecr.io/procynia-app:<tag>" \
     --command /bin/sh --args '-lc' 'php artisan migrate --force'
     # + samme env/secrets/identity som Container Appene
   ```
   Alternativt `az containerapp exec` inn i en kjørende worker, eller en
   engangs-container fra samme image.
4. **Laravel-migreringene oppretter extensionen.**
   `database/migrations/2026_05_21_000001_add_pgvector_embedding_column_to_knowledge_item_chunks_table.php`
   kjører `CREATE EXTENSION IF NOT EXISTS vector`, legger til
   `embedding_vector_pgvector vector(1536)` og en ivfflat-indeks. Bicep gjør
   ingen av disse tingene — det er applikasjonens ansvar, og steget må derfor
   ligge etter punkt 2.

Verifiser etterpå:

```sql
SELECT extname, extversion FROM pg_extension WHERE extname = 'vector';
```

**Datamigrering (senere fase, ikke gjort her):** dump fra dagens PostgreSQL 16
og restore mot Azure. `pg_dump`/`pg_restore` med samme major-versjon, kjørt fra
en maskin som har nettverkstilgang gjennom PostgreSQL-brannmuren. `vector`-
extensionen må finnes i mål-databasen *før* restore hvis dumpen inneholder
`vector`-kolonner.

**Restore i Azure:** point-in-time restore på Flexible Server, innenfor
`postgresBackupRetentionDays` (7 i staging, 35 i production). Dette oppretter en
ny server; connection-strings må oppdateres etterpå. Docker/Compose-backupen er
ikke Azure-backup og skal ikke brukes som sådan.

---

## 12. Filmigrering senere

Ingen filer kopieres i denne fasen. Når det skal skje:

1. Mount Azure Files-sharen lokalt eller bruk `azcopy` / `az storage file upload-batch`.
2. Kopier `storage/app/private/**` og `storage/app/public/**` inn i sharen slik at
   strukturen blir identisk relativt til mount-punktet
   `/var/www/html/storage/app`.
3. Verifiser at nedlasting og forhåndsvisning fungerer i staging før production.

`storage/framework/**` og `storage/logs/**` skal **ikke** migreres — de er
container-lokale, og logger går til stderr.

---

## 13. Hvorfor Azure Files brukes midlertidig

Procynia løser fysiske filstier og gir dem videre til eksterne prosesser. Ni steder
i `app/` kaller `Storage::disk('local')->path(...)`, blant annet:

- `app/Http/Controllers/App/KnowledgeBaseController.php`
- `app/Http/Controllers/App/WikiSourceController.php`
- `app/Http/Controllers/App/AiController.php`
- `app/Services/Ai/DocumentPreviewService.php`
- `app/Services/Ai/Requirements/RequirementAnswerBasisService.php`
- `app/Http/Controllers/Admin/OperationalRunbookAttachmentDownloadController.php`

`app/Services/DocumentTextExtractor.php` sender slike stier til `pdftotext`,
`pdftohtml`, `pdfimages` og `pdfinfo`. Disse prosessene kan ikke lese en URL —
de trenger en ekte POSIX-sti.

Samtidig må web, alle workers og scheduleren se *samme* fil: en Wiki-kjøring
laster opp via web og prosesserer i `enterprise-wiki`-workeren.

Azure Files SMB, mountet på samme sti i alle containere, er det som gjør dagens
kodeveier kjørbare i Azure uten å endre applikasjonslogikk. Mount-punktet er
`/var/www/html/storage/app`, som dekker både `local`-disken
(`storage_path('app/private')`) og `public`-disken. `storage/framework/**` og
`storage/logs/**` blir bevisst liggende på containerens eget filsystem, der
SMB-latens ikke kan skade compiled views og cache.

Kjente begrensninger å regne med: SMB har høyere latens enn lokal disk, og
POSIX-semantikk for låsing og `rename()` er ikke identisk.

## 14. Hvorfor Blob er target state

Blob Storage er riktig langsiktig lagring for dokumenter: billigere, versjonert,
soft delete, ubegrenset skalering, ingen SMB-semantikk, og ingen delt mutable
filsystemtilstand mellom containere.

Derfor opprettes blob-containeren (`documents`) nå, med versioning og soft delete,
og managed identity har allerede `Storage Blob Data Contributor`.

Men applikasjonen er **ikke** bygget om. Ingenting skriver til blob ennå.
Migreringen krever at de fysiske filstiene over erstattes med strøm- eller
temp-fil-baserte kodeveier, spesielt rundt poppler-prosessene. Det er en egen
oppgave. Å late som om blob virker i dag ville vært feil.

---

## 15. Web, workers, scheduler — og lange AI/Wiki-jobber

Workload-topologien er hentet fra `docker-compose.yml`, ikke fra en generisk
Laravel-mal.

| Container App | Køer | Prosesser (stg/prod) | `--timeout` | `retry_after` |
|---|---|---|---|---|
| `procynia-<k>-web` | — | — | — | 420 |
| `procynia-<k>-w-default` | `supplier-harvests,supplier-lookups,default` | 1 / 2 | 120 | 420 |
| `procynia-<k>-w-ai-req` | `ai-requirements` | 1 / 1 | 2100 | 2700 |
| `procynia-<k>-w-wiki` | `enterprise-wiki` | 1 / 1 | 1860 | 2100 |
| `procynia-<k>-w-wiki-recon` | `enterprise-wiki-reconciliation` | 1 / 1 | 1800 | 2100 |
| `procynia-<k>-w-wiki-claims` | `enterprise-wiki-claim-verification` | 2 / 4 | 1800 | 2100 |
| `procynia-<k>-w-wiki-batches` | `enterprise-wiki-maintainer-batches` | 2 / 3 | 1800 | 2100 |
| `procynia-<k>-w-wiki-pages` | `enterprise-wiki-pages` | 2 / 4 | 420 | 480 |
| `procynia-<k>-scheduler` | — | — | — | — |

### Hvorfor én Container App per kø, og ikke én samlet worker

Fordi hver `queue-*`-tjeneste i `docker-compose.yml` setter sin egen
`REDIS_QUEUE_RETRY_AFTER` (420 / 2700 / 2100 / 480). Den verdien konfigurerer
Redis-**køtilkoblingen**, ikke worker-kallet, så to køer med ulik `retry_after`
kan ikke dele én container uten at køsemantikken endres. En 30-minutters
Wiki-jobb ville blitt frigitt og kjørt på nytt hvis den delte tilkobling med
`enterprise-wiki-pages` (`retry_after=480`).

I tillegg kjører flere Compose-tjenester mer enn én `queue:work`-prosess i samme
container (4 for claim verification, 3 for maintainer batches, 4 for pages). Den
parallelliteten er reprodusert via `processes`-feltet, slik at en Wiki-kjøring
beholder samvirket den er tunet for. Worker-kommandoen er en direkte
gjenskaping av Compose-kommandoblokken, inkludert `trap 'kill -TERM 0' TERM INT`.

Topologien er en `queueWorker[]`-parameter per miljø, så en ny kø er én ny
oppføring i `environments/<env>.bicepparam` — ingen Bicep-endring.

### Hvorfor lange AI/Wiki-workers har konservativ skalering

`ai-requirements` kjører med `--timeout=2100` (35 minutter) og `enterprise-wiki`
med `--timeout=1860`. Én jobb kan altså legitimt okkupere en worker i over en
halvtime, og kan ikke trygt termineres midt i kjøringen.

Derfor:

- **`minReplicas = maxReplicas`** for alle workers, og **ingen scale rule**.
  Ingen KEDA-scaler, ingen scale-to-zero, ingenting som kan evakuere en replica
  midt i en jobb.
- **`terminationGracePeriodSeconds = 600`** (Azures maksimum) på de lange
  workerne, så `queue:work` får den tiden Azure i det hele tatt tillater etter
  SIGTERM.
- **Egen ressurskonfigurasjon per worker**, ikke delt med generell worker, og
  hevbar via parametre uten kodeendring.

**Viktig driftsforbehold:** 600 sekunder er mindre enn 2100. En ny revisjon —
altså hver `--with-apps`-deployment — kan derfor drepe en AI- eller Wiki-jobb som
er midt i kjøringen. Deploy workloads når køene er drenert, eller aksepter at
jobben må kjøres om. Container Apps har ingen mekanisme som venter 35 minutter på
en replica.

Autoskalering av workers på kølengde er en senere forbedring. Det krever en
KEDA Redis-scaler med egen secret-referanse, og for de lange køene må det
kombineres med en drenerings-strategi før det er trygt. Statisk lavt
replica-antall er det riktige første steget.

### Scheduler

Nøyaktig én replica per miljø (`minReplicas = maxReplicas = 1`), som kjører
`php artisan schedule:work`. `TZ=Europe/Oslo` er satt, i tråd med
`date.timezone` i `docker/php/conf.d/local.ini` og
`->timezone('Europe/Oslo')` i `routes/console.php`. Det opprettes aldri mer enn
én scheduler.

### Web

Ekstern HTTPS ingress med `allowInsecure: false`. Startup-, readiness- og
liveness-probes går alle mot `/up` (`bootstrap/app.php` → `health: '/up'`), som
ikke krever token. Sesjoner ligger i Redis, så ingen session affinity er nødvendig.
`trustProxies(at: '*')` er allerede satt i `bootstrap/app.php`, som er det
Container Apps-ingress krever.

### Redis

Azure Managed Redis (`Microsoft.Cache/redisEnterprise`), ikke Azure Cache for
Redis. To valg verdt å kjenne:

- **`clusteringPolicy: EnterpriseCluster`.** Procynia bruker `phpredis` som ikke er
  cluster-aware. Mot et `OSSCluster`-endepunkt ville klienten fått MOVED-redirects
  den ikke kan følge.
- **Én logisk database.** `docker-compose.yml` skiller cache på `REDIS_CACHE_DB=1`.
  Azure Managed Redis eksponerer bare database 0, så `REDIS_DB` og
  `REDIS_CACHE_DB` settes begge til `0`, og `REDIS_URL` avsluttes med `/0`.
  Cache-, session- og kønøkler holdes fortsatt fra hverandre av
  Laravels egne key-prefixer (`config/cache.php` `prefix`,
  `config/database.php` `redis.options.prefix`). Ingen applikasjonsendring kreves.
- **`evictionPolicy: NoEviction`.** Redis er også købackend; en evictet kønøkkel
  ville vært en tapt jobb.

Applikasjonen får hele tilkoblingen som én `REDIS_URL`-secret
(`tls://default:<key>@<host>:10000/0`). Laravels `RedisManager` løfter
`tls`-scheme til phpredis' TLS-modus, og `config/database.php` leser allerede
`REDIS_URL` for både `default`- og `cache`-tilkoblingen. Redis-nøkkelen er aldri
et Bicep-output.

### Monitorering

Alle containere logger til stderr (`LOG_CHANNEL=stderr`) og havner i Log Analytics
via Container Apps Environment. Det gir container stdout/stderr, Laravel-feil,
container-restarts, CPU/minne, og revisjonsstatus.

Container Apps-probes støtter bare `httpGet` og `tcpSocket`, ikke `exec`. Compose-
healthchecks basert på `php artisan ops:queue-heartbeat-status` kan derfor ikke
gjenskapes som probes. Scheduleren fortsetter å skrive kø-heartbeats
(`OpsQueueHeartbeatJob` hvert minutt for alle ni køer), og worker-liveness
observeres via de token-beskyttede endepunktene på web:

```
GET /ops/health/queues/{queue}
GET /ops/health/queue-scheduler
```

Application Insights opprettes, men ingenting i applikasjonen rapporterer til den
ennå. Distribuert tracing er bevisst ikke bygget ut i denne fasen. Availability
tests mot `/up` er neste naturlige steg.

### Nettverk

Ingen VNet, ingen private endpoints, ingen Front Door, ingen WAF i denne versjonen.
PostgreSQL har `AllowAllAzureServices` (0.0.0.0) fordi Container Apps uten
VNet-integrasjon går ut fra Azures adresseområde.

**Dette er en bevisst midlertidig åpning, ikke en anbefalt sluttilstand.**
Arkitekturen er laget slik at den kan strammes inn uten omskriving:

- `containerAppsOutboundIp` er et output, så PostgreSQL-brannmuren kan snevres
  inn til den ene adressen.
- `postgresAdditionalFirewallRules` tar en typet liste for operator-IP-er.
- Alle datatjenester har `publicNetworkAccess` som en enkelt egenskap per modul,
  som byttes til `Disabled` når private endpoints innføres.
- Container Apps Environment har `zoneRedundant: false` fordi zone redundancy
  krever VNet-integrasjon — én parameter å endre samtidig.

Før production bærer reelle kunde­data bør PostgreSQL-brannmuren snevres inn eller
erstattes med private endpoint.

---

## 16. Rive infrastrukturen manuelt

Det finnes bevisst **ikke** noe destroy-script, og `deploy.sh` sletter aldri noe.
Hvis et miljø en dag faktisk skal fjernes, gjøres det manuelt og med vitende vilje:

```bash
# 1. Sjekk hva som ligger der
az resource list -g rg-procynia-staging-norwayeast -o table

# 2. Sikre det som skal beholdes: databasedump, filer fra Azure Files, secrets.

# 3. Slett resource group (irreversibelt for alt uten soft delete)
az group delete --name rg-procynia-staging-norwayeast
```

Vær klar over:

- **Key Vault** har soft delete. Navnet er reservert i retention-perioden
  (7 dager staging / 90 dager production) og må purges før det kan gjenbrukes:
  `az keyvault purge --name <navn> --location <region>`.
- **Key Vault i production har purge protection**, som ikke kan skrus av. Vaulten
  kan *ikke* purges før retention-perioden er over. Det er hensikten.
- **PostgreSQL** slettes med resource group. Automatiske backups forsvinner med
  serveren; ta en `pg_dump` først hvis dataene har verdi.
- **Storage** soft delete beskytter blobs og shares inne i kontoen, men ikke mot
  sletting av selve kontoen.
- Gjør aldri dette mot production uten eksplisitt beslutning.
