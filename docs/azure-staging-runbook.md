# Procynia — Azure staging runbook

Operativ oppskrift for første staging-deploy. Følg fasene i rekkefølge.

Hvert steg har **handling**, **forventet resultat** og **STOPP hvis**. «STOPP» betyr: ikke gå videre,
ikke improviser, gå til [rollback](#rollback).

Forutsetninger: [`infra/README.md`](../infra/README.md) og
[`docs/azure-runtime-contract.md`](azure-runtime-contract.md).

> Denne runbooken er skrevet før første kjøring. Steg som ikke er utført ennå er merket **UPRØVD**.
> Forvent å måtte justere den under første gjennomkjøring — noter avvik underveis.

---

## Fase 1 — Azure-forutsetninger

| # | Handling | Forventet | STOPP hvis |
|---|---|---|---|
| 1.1 | `az version` | Azure CLI ≥ 2.60 | CLI mangler → `brew install azure-cli` |
| 1.2 | `az bicep install` | Bicep tilgjengelig | Feiler |
| 1.3 | `az login` | Nettleser-innlogging fullført | Feil tenant |
| 1.4 | `az account list -o table` | Riktig subscription synlig | Subscription mangler |
| 1.5 | `export PROCYNIA_SUBSCRIPTION="<id>"` | — | — |
| 1.6 | Bekreft resource providers er registrert: `Microsoft.App`, `Microsoft.DBforPostgreSQL`, `Microsoft.Cache`, `Microsoft.ContainerRegistry`, `Microsoft.KeyVault`, `Microsoft.OperationalInsights`, `Microsoft.Insights` | Alle `Registered` | Noen mangler → `az provider register --namespace <ns>` |
| 1.7 | Bekreft at kontoen har `Owner`, eller `Contributor` + `User Access Administrator` | — | Manglende rettigheter — Bicep oppretter rolletildelinger |

---

## Fase 2 — Infrastruktur

| # | Handling | Forventet | STOPP hvis |
|---|---|---|---|
| 2.1 | `./infra/validate.sh` | «Local validation passed» | Byggefeil |
| 2.2 | `export PROCYNIA_PG_ADMIN_PASSWORD='<sterkt passord>'` | — | Kortere enn 12 tegn |
| 2.3 | `./infra/deploy.sh staging` | Validate + what-if, **ingen endring** | Validering feiler |
| 2.4 | **Les hele what-if-outputen.** Bekreft: 1 ACR, 1 Key Vault, 1 Storage, 1 PostgreSQL, 1 Redis, 1 Log Analytics, 1 App Insights, 1 Container Apps Environment, 1 managed identity | Ingen sletting, ingen uventet ressurs | Noe skal slettes |
| 2.5 | `./infra/deploy.sh staging --apply` | Deployment fullført, outputs skrevet ut | Feiler → se rollback R1 |
| 2.6 | Noter fra outputs: `containerRegistryName`, `keyVaultName`, `postgresHost`, `containerAppsDefaultDomain`, `containerAppsOutboundIp` | — | — |

**Ingen Container Apps deployes i denne fasen.** `deployWorkloads` er `false` til fase 7.

---

## Fase 3 — Secrets

Bicep har allerede lagt inn `DB-PASSWORD` og `REDIS-URL`. Resten settes her.

| # | Handling | Forventet | STOPP hvis |
|---|---|---|---|
| 3.1 | `KV=$(az deployment group show -g rg-procynia-staging-norwayeast -n <deployment> --query properties.outputs.keyVaultName.value -o tsv)` | Navn returnert | Tomt |
| 3.2 | Generer APP_KEY: `docker run --rm procynia-app:production php artisan key:generate --show` | `base64:...` | — |
| 3.3 | `az keyvault secret set --vault-name "$KV" --name APP-KEY --value "base64:..."` | Lagret | 403 → RBAC-propagering, vent 60 s og prøv igjen |
| 3.4 | `az keyvault secret set --vault-name "$KV" --name OPENAI-API-KEY --value "sk-..."` | Lagret | — |
| 3.5 | `az keyvault secret set --vault-name "$KV" --name DOFFIN-API-KEY --value "..."` | Lagret | — |
| 3.6 | `az keyvault secret set --vault-name "$KV" --name PROCYNIA-HEALTH-TOKEN --value "$(openssl rand -hex 32)"` | Lagret | — |
| 3.7 | `az keyvault secret list --vault-name "$KV" --query "[].name" -o tsv` | Alle seks navn til stede | Noen mangler |

**APP_KEY skrives ned ett sted trygt.** Mister vi den, er alle krypterte kolonner tapt.
Ikke skriv secret-verdier i terminal-historikk du beholder.

---

## Fase 4 — Images

| # | Handling | Forventet | STOPP hvis |
|---|---|---|---|
| 4.1 | `./scripts/azure-readiness/production-image-smoke.sh` | **Alle checks PASS** lokalt | Én eneste FAIL |
| 4.2 | `ACR=$(az deployment group show ... --query properties.outputs.containerRegistryName.value -o tsv)` | Navn | — |
| 4.3 | `az acr login --name "$ACR"` | Login succeeded | — |
| 4.4 | `TAG=$(git rev-parse --short HEAD)` — bruk commit-sha, **aldri `latest`** | — | Arbeidstreet er skittent |
| 4.5 | `docker build -f docker/production/Dockerfile --target app -t "$ACR.azurecr.io/procynia-app:$TAG" .` | Bygget | — |
| 4.6 | `docker build -f docker/production/Dockerfile --target web -t "$ACR.azurecr.io/procynia-web:$TAG" .` | Bygget | — |
| 4.7 | `docker push "$ACR.azurecr.io/procynia-app:$TAG"` og `...procynia-web:$TAG` | Pushet | — |
| 4.8 | `az acr repository show-manifests --name "$ACR" --repository procynia-app --detail --query "[?tags[0]=='$TAG'].digest"` | Digest returnert — **noter den** | Tom |
| 4.9 | `export PROCYNIA_IMAGE_TAG="$TAG"` | — | — |

Digesten er sporbarheten: den, ikke taggen, sier hva som faktisk kjører.

---

## Fase 5 — Database

**Rekkefølgen er obligatorisk.** pgvector kan ikke opprettes før Azure tillater det.

| # | Handling | Forventet | STOPP hvis |
|---|---|---|---|
| 5.1 | `az postgres flexible-server parameter show -g <rg> -s <server> --name azure.extensions --query value -o tsv` | Inneholder `VECTOR` | Gjør ikke → fase 2 kjørte ikke ferdig |
| 5.2 | **UPRØVD.** Importer staging-kopi: `pg_dump` fra dagens database → `pg_restore` mot Azure. Kjøres fra en maskin som slipper gjennom brannmuren. | Restore uten feil | Feiler → R2 |
| 5.3 | Opprett migration job (se `infra/README.md` punkt 11) og kjør den | `migrate --force` exit 0 | Feiler → R2 |
| 5.4 | `select extname, extversion from pg_extension where extname='vector';` | Én rad | Tom → 5.1 |
| 5.5 | Kjør migration job **på nytt** | «Nothing to migrate» | Noe migreres → ikke idempotent |
| 5.6 | `select count(*) from customers;` mot Azure vs. kilde | Samme antall | Avvik |

Ved import av eksisterende data: `vector`-extensionen må finnes **før** restore hvis dumpen
inneholder `vector`-kolonner.

---

## Fase 6 — Filer

| # | Handling | Forventet | STOPP hvis |
|---|---|---|---|
| 6.1 | Tell kildefiler: `find storage/app/private -type f \| wc -l` | Tall notert | — |
| 6.2 | **UPRØVD.** `az storage file upload-batch --destination procynia-storage --source storage/app --account-name <sa>` | Fullført | Feiler → R5 |
| 6.3 | Tell i Azure Files | Samme antall som 6.1 | Avvik |
| 6.4 | Verifiser sha256 på 5–10 representative filer, inkludert den største | Identiske | Avvik |

`storage/framework/**` og `storage/logs/**` skal **ikke** migreres — de er container-lokale.

---

## Fase 7 — Workloads

Rekkefølgen er bevisst: web sist av de trafikkbærende, scheduler helt sist.

| # | Handling | Forventet | STOPP hvis |
|---|---|---|---|
| 7.1 | `./infra/deploy.sh staging --apply --with-apps` | Alle ni container apps + web + scheduler opprettet | Feiler → R1 |
| 7.2 | `az containerapp list -g <rg> --query "[].{name:name,replicas:properties.runningStatus}" -o table` | Alle `Running` | Noen i `Failed` → R3 |
| 7.3 | `az containerapp logs show -n procynia-stg-web -g <rg> --tail 50` | Ingen fatale feil | Boot-loop → R3 |
| 7.4 | Bekreft scheduler har **nøyaktig én** replica | 1 | Flere → stopp, dobbelkjøring av alle scheduled jobs |

---

## Fase 8 — Runtime checks

| # | Handling | Forventet | STOPP hvis |
|---|---|---|---|
| 8.1 | `az containerapp exec -n procynia-stg-w-default -g <rg> --command "php artisan ops:runtime-check --azure"` | **Alle kritiske PASS**, exit 0 | Én FAIL |
| 8.2 | `curl -si https://<web-fqdn>/up` | 200 | Annet → R3 |
| 8.3 | Preflight bekrefter PostgreSQL, pgvector, Redis, storage, pdftotext | PASS på alle | — |
| 8.4 | Bekreft `DB_SSLMODE=require` faktisk brukes (preflight kobler til; TLS er implisitt) | PASS PostgreSQL | — |
| 8.5 | Bekreft logging: `az containerapp logs show` viser applikasjonslogg | Logglinjer synlige | Tomt → LOG_CHANNEL feil |
| 8.6 | `curl -H "X-Procynia-Health-Token: <token>" https://<fqdn>/ops/health/queue-scheduler` | 200 | 503 |
| 8.7 | Samme for hver av de ni køene via `/ops/health/queues/{queue}` | 200 | 503 på noen |

---

## Fase 9 — Funksjonell smoke

| # | Handling | Forventet | STOPP hvis |
|---|---|---|---|
| 9.1 | Logg inn i UI | Sesjon opprettes, ingen redirect-loop | Loop → `SESSION_SECURE_COOKIE`/`APP_URL` |
| 9.2 | Last opp en PDF som Wiki-kilde | Lagret, tekst ekstrahert | Ingen tekst → R6 |
| 9.3 | Last opp en DOCX | Samme | R6 |
| 9.4 | Last opp en XLSX i Kunnskapsbase | Samme | R6 |
| 9.5 | **Last opp en fil rett under 20 MB** | Går gjennom | 413 → dokumenter Container Apps ingress-grensen |
| 9.6 | Last opp en fil over 20 MB | Valideringsmelding, **ikke** rå 413 | Rå 413 → grensekjeden er feil |
| 9.7 | `az containerapp exec` inn i en **worker**: bekreft at filen fra 9.2 er lesbar på samme sti | Fil funnet | Ikke funnet → R5 |
| 9.8 | Kjør en Wiki-ingest | Fullfører | Feiler → R7 |
| 9.9 | Kjør en AI requirements-ekstraksjon | Fullfører | Feiler → R7 |
| 9.10 | Bekreft resultatene i UI | Synlige og korrekte | — |

---

## Fase 10 — Runtime-oppførsel

| # | Handling | Forventet | STOPP hvis |
|---|---|---|---|
| 10.1 | Restart web (`az containerapp revision restart`) | Kommer opp, `/up` 200 | — |
| 10.2 | Bekreft at innloggingssesjonen fra 9.1 overlevde | Fortsatt innlogget | Utlogget → sesjon ligger ikke i Redis |
| 10.3 | Skaler web til 2 replicas, gjør 10+ requests | Alle lykkes | Feil → replica-lokal state |
| 10.4 | Restart en worker | Kommer opp | — |
| 10.5 | Bekreft at filen fra 9.2 fortsatt er lesbar | Ja | R5 |
| 10.6 | **Observasjon, ikke pass/fail:** start en lang Wiki-jobb, deploy en ny worker-revisjon, se hva som skjer med jobben | Noter oppførselen | — |

10.6 bestemmer deploy-rutinen: Azure gir maks 600 s termination grace, mens en `ai-requirements`-jobb
kan kjøre i 2 100 s.

---

## Fase 11 — Drift

| # | Handling | Forventet | STOPP hvis |
|---|---|---|---|
| 11.1 | Vent 5 min, sjekk scheduler-heartbeat via `/ops/health/queue-scheduler` | Fersk | Stale |
| 11.2 | Bekreft `PROCYNIA_LEGACY_BACKUP_ENABLED=false` på web, alle workers og scheduler | `false` overalt | Mangler noe sted |
| 11.3 | `az containerapp exec -n procynia-stg-scheduler -g <rg> --command "php artisan procynia:backup"` | «Legacy Compose backup is disabled», exit 0 | Forsøker docker |
| 11.4 | Bekreft i Log Analytics at logger fra web, worker og scheduler kommer inn | Alle tre synlige | Mangler |
| 11.5 | Bekreft at et OpenAI- og et Doffin-kall lykkes fra en worker | OK | Egress blokkert |

---

## Fase 12 — Backup

| # | Handling | Forventet | STOPP hvis |
|---|---|---|---|
| 12.1 | `az postgres flexible-server show -g <rg> -n <server> --query "backup"` | `backupRetentionDays: 7`, `geoRedundantBackup: Disabled` (staging) | Avvik fra `staging.bicepparam` |
| 12.2 | Bekreft point-in-time restore er tilgjengelig i portalen | Earliest restore point vises | Ikke tilgjengelig |
| 12.3 | Bekreft blob soft delete og versioning på storage account | Aktivert | — |
| 12.4 | Bekreft Azure Files soft delete | Aktivert | — |
| 12.5 | **Planlegg restore-øvelse.** Ikke gjennomført ennå. | Dato satt | — |

12.5 er den eneste gjenværende backup-risikoen. En backup som aldri er restaurert er en antakelse.

---

## Rollback

Staging har ingen brukere, så rollback er «rydd opp og prøv igjen» — ikke en nødprosedyre. Ingen av
disse endrer dagens Compose-produksjon.

### R1 — Bicep deployment feiler
Deploymentet er **incremental**; ingenting slettes. Les feilmeldingen, rett param-filen, kjør
`./infra/deploy.sh staging` (uten `--apply`) på nytt til what-if er ren. Vanlige årsaker: SKU ikke
tilgjengelig i regionen, globalt unikt navn opptatt, manglende `User Access Administrator`.
Ressursgruppen kan stå — en delvis deployment blokkerer ikke neste forsøk.

### R2 — Migration job feiler
Databasen er ny og tom for staging. Slett innholdet med `DROP SCHEMA public CASCADE; CREATE SCHEMA public;`
mot **staging-serveren** — aldri mot noe annet — og kjør fase 5 på nytt. Er `vector` årsaken, gå
tilbake til 5.1: allowlisten er ikke aktiv.

### R3 — App image starter ikke
Sett `deployWorkloads = false` og deploy på nytt: plattformen blir stående, workloadene forsvinner.
Feilsøk lokalt med `production-image-smoke.sh`, som kjører nøyaktig samme image. Vanligste årsak er
en manglende env-variabel eller en Key Vault-secret som ikke finnes — `ops:runtime-check --azure`
peker på hvilken.

### R4 — Redis fungerer ikke
Sjekk `REDIS-URL` i Key Vault: den skal være `tls://default:<key>@<host>:10000/0`. Feil scheme
(`tcp://`) eller manglende `/0` er de to typiske feilene. Redis kan gjenopprettes uten å røre resten
— oppdater secreten og restart workloadene.

### R5 — Shared storage fungerer ikke
Bekreft at Azure Files-mounten er på plass i **alle** container apps, ikke bare web. Uten mount ser
en worker en tom katalog i stedet for å feile, så symptomet er «dokumentet finnes ikke» heller enn en
feilmelding. Ingen data går tapt: kildefilene ligger fortsatt lokalt.

### R6 — Dokumentprosessering feiler
Kjør `ops:runtime-check --azure` i den aktuelle containeren. Feiler `pdftotext`, er det imaget som er
galt, ikke Azure. Er binæren OK men teksten tom, sjekk at filen faktisk er lesbar fra den containeren
(R5).

### R7 — Wiki/AI worker feiler
Sjekk i rekkefølge: `OPENAI-API-KEY` finnes i Key Vault → egress fungerer (11.5) →
`ENTERPRISE_WIKI_AI_ENABLED` → køen har en kjørende worker (8.7). Feilede jobber ligger i
`failed_jobs` og kan kjøres på nytt; ingenting går tapt.

### Prinsipp for senere production-cutover

Ikke implementert nå, men planen skal allerede være kjent:

1. **Det gamle miljøet beholdes kjørende og tilgjengelig gjennom hele cutover.** Det slås ikke av
   samtidig med at det nye slås på.
2. **DNS endres ikke før smoke-testen er godkjent** i det nye miljøet. DNS er den siste bryteren, ikke
   den første.
3. **Databasecutover krever et eksplisitt vedlikeholdsvindu** med en definert siste sync. Uten et
   vindu der skriving er stanset, finnes det ikke noe konsistent punkt å migrere fra.
4. **Rollback er å peke DNS tilbake**, ikke å migrere data baklengs. Bakoverkopiering av data som er
   skrevet i det nye miljøet er ikke en plan — det er et datatapsscenario. Derfor er punkt 2 og 3
   ufravikelige.
5. Rollback-vinduet varer til vi bevisst erklærer det gamle miljøet nedlagt.

---

## Utenfor scope før staging

Disse forbedringene skal **ikke** startes nå. De er reelle, men de gjør staging senere og mer
risikabelt, ikke bedre:

Full Blob-refaktorering · Front Door · WAF · private endpoints · full VNet-topologi · avansert
KEDA-autoskalering · distributed tracing · Azure OpenAI · større kø-refaktoreringer · generell
Laravel-modernisering · urelaterte Wiki-feil.

Arkitekturen er laget slik at alle kan innføres etterpå — se «Nettverk» i `infra/README.md`.
