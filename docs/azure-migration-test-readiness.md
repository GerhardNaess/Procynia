# Azure-migrering — testberedskap

Denne rapporten svarer på ett spørsmål:

> Kan Procynia kjøre korrekt i en Azure-lignende containerarkitektur uten å være avhengig av dagens lokale Docker Compose-oppsett?

Den dekker ikke all funksjonalitet. Den dekker de delene som faktisk er utsatt når vi går fra lokal Compose til Azure Container Apps med ekstern PostgreSQL, ekstern Redis, delt filstorage og containerbasert runtime.

Infrastrukturen selv er dokumentert i [`infra/README.md`](../infra/README.md). Denne filen handler om **verifisering**.

---

## 1. Hva som er nytt, og hvor det ligger

| Artefakt | Hva den beviser |
|---|---|
| `tests/Feature/Azure/ContainerRuntimeContractTest.php` | PHP-versjon, extensions, poppler-binærer, opplastingsgrenser |
| `tests/Feature/Azure/QueueTopologyContractTest.php` | Køtopologi henger sammen på tvers av jobbklasser, Compose, Bicep og scheduler |
| `tests/Feature/Azure/RedisRuntimeContractTest.php` | `REDIS_URL`/TLS-kontrakt og at cache + kø kan dele database 0 |
| `tests/Feature/Azure/SharedStorageHandoffTest.php` | Web → delt fil → separat prosess → PostgreSQL → tilbake via applaget |
| `tests/Feature/Azure/StatelessRuntimeContractTest.php` | Boot uten `.env`, stderr-logging, ingen lokale host-antakelser |
| `tests/Feature/Azure/MigrationJobAndPgvectorContractTest.php` | Migrations som egen jobb, idempotens, pgvector |
| `tests/Feature/Azure/SchedulerContractTest.php` | Én scheduler-instans, og Azure-kontrakten for `procynia:backup` |
| `tests/Feature/Operations/LegacyBackupRuntimeGuardTest.php` | Runtime-guarden som stenger legacy Compose-backup i Azure |
| `tests/Feature/Azure/support/*.php` | Hjelpeprosesser som kjøres som **egne OS-prosesser**, ikke i testprosessen |
| `scripts/azure-readiness/azure-smoke.sh` | Det som krever mer enn én container |

Kjør dem slik:

```bash
# PHPUnit-pakken (krever at Docker-stacken kjører)
docker exec procynia-app php artisan test tests/Feature/Azure

# Container-til-container-verifisering
./scripts/azure-readiness/azure-smoke.sh

# Inkludert restart-testene (avbryter lokal utvikling)
./scripts/azure-readiness/azure-smoke.sh --with-restarts
```

**Databasesikkerhet:** hele PHPUnit-pakken kjører mot `procynia_test`. `Tests\TestCase` verifiser dette live med `select current_database()` før hver eneste test, ikke bare mot config. Smoke-scriptet nekter å migrere før det selv har bekreftet at overstyringen faktisk lander på `procynia_test`, og skriver aldri til `procynia`.

---

## 2. Gap-analyse

### Det som allerede var dekket før denne oppgaven

| Område | Eksisterende dekning |
|---|---|
| Poppler-binærer via config, ikke hardkodet sti | `tests/Feature/PdftotextConfigTest.php` |
| PDF/DOCX/XLSX-parsing | `tests/Unit/DocumentTextExtractorTest.php` (omfattende), `tests/Unit/Services/KnowledgeDocumentStructureParserTest.php` |
| Kø-/scheduler-heartbeats og helseendepunkt | `tests/Feature/Ops/*`, `tests/Feature/Health/ProcyniaHealthEndpointsTest.php` |
| Claim-verification-køens jobbsemantikk | `tests/Feature/App/Wiki/EnterpriseWikiClaimVerificationQueueTest.php` |
| Doffin-config uten beta-default | `tests/Feature/DoffinProductionConfigTest.php` |
| HTTPS/proxy-dokumentasjon | `tests/Feature/HttpsTlsProductionConfigTest.php` (merk: dens `trustProxies`-assertion er utdatert etter F-01 — se `docs/security/security-audit-2026-08.md`) |
| Testdatabase-sikkerhet | `tests/TestCase.php`, `tests/Unit/RefreshDatabaseRealLifecycleSafetyTest.php` |

Parser-testene er **ikke** duplisert. Der formatene allerede var dekket, er det lagt til runtime-sjekker for eksterne binærer og extensions i stedet.

### Konkrete gap som ble funnet

| # | Gap | Hvorfor det betydde noe for Azure | Lukket av |
|---|---|---|---|
| 1 | Ingenting bandt køtopologien i `docker-compose.yml` til worker-arrayet i Bicep | En kø uten Container App ville fått jobber som lå i Redis for alltid, uten feilmelding noe sted | `QueueTopologyContractTest` |
| 2 | Ingen test på `timeout < retry_after` per kø | Laravel ville frigitt en 35-minutters AI-jobb midt i kjøring og kjørt den to ganger | `QueueTopologyContractTest` |
| 3 | Ingen test på at cache og kø kan dele Redis-database 0 | Azure Managed Redis har bare database 0; dagens `REDIS_CACHE_DB=1` kan ikke bli med over | `RedisRuntimeContractTest` |
| 4 | Ingen test på `tls://`-håndtering i `REDIS_URL` | Tilkoblingen til Managed Redis ville blitt forsøkt i klartekst | `RedisRuntimeContractTest` |
| 5 | Alle eksisterende opplastingstester brukte `Storage::fake('local')` | Nettopp det som skjuler spørsmålet om delt lagring | `SharedStorageHandoffTest` |
| 6 | Ingen test på at applikasjonen kan starte uten `.env` | Produksjonsimaget vil ikke ha noen `.env` | `StatelessRuntimeContractTest` |
| 7 | Ingen test på `LOG_CHANNEL=stderr` | Container Apps samler logg fra stdout/stderr, ikke fra fil | `StatelessRuntimeContractTest` |
| 8 | Ingen test på at migrations ikke kjøres ved boot, og at de er idempotente | Hver web-replica ville kappløpt om å migrere ved cold start | `MigrationJobAndPgvectorContractTest` |
| 9 | Ingen test på at opplastingsgrensene henger sammen på tvers av lag | Feil rekkefølge gir rå 413 fra ingress i stedet for valideringsmelding | `ContainerRuntimeContractTest` |
| 10 | `procynia:backup` var kjent som et problem, men ikke maskinelt sjekket | Blokkerte Azure-scheduleren | `SchedulerContractTest` + smoke-script — **blokkeren er nå lukket, se seksjon 5** |

---

## 3. Matrise

Statuskolonnen skiller bevisst mellom fire ting:

- **BEVIST** — kjørt mot ekte runtime lokalt (ekte Redis, ekte PostgreSQL, ekte `pdftotext`, ekte separate prosesser/containere)
- **STATISK** — kontrollert i kildekode/config, ikke kjørt
- **BLOKKERT** — kan ikke verifiseres meningsfullt før vi har Azure staging
- **AVVIK** — funnet problem

| Område | Lokal verifisering | Resultat | Må testes i Azure staging |
|---|---|---|---|
| Container-runtime: PHP-versjon | `ContainerRuntimeContractTest` mot kjørende container | **BEVIST** — PHP 8.4.23, matcher `php:8.4-fpm-bookworm` | At produksjonsimaget bruker samme base |
| Container-runtime: PHP extensions | 17 påkrevde extensions lastet + finnes i Dockerfile | **BEVIST** | Samme sjekk mot produksjonsimaget |
| Container-runtime: poppler | Alle fire binærer kjørbare; `pdftotext -v` kjørt på ekte | **BEVIST** | Samme sjekk mot produksjonsimaget |
| Container-runtime: uten bind mount | — | **BLOKKERT** — produksjonsimage finnes ikke ennå | Bygg image, kjør smoke-scriptets image-seksjon |
| Frontend build i image | — | **BLOKKERT** | `public/build/manifest.json` i imaget |
| Web health `/up` | Ekte HTTP mot web-container | **BEVIST** — 200 | Container Apps readiness/liveness probe |
| `APP_DEBUG=false` + HTTPS `APP_URL` | Ekte boot uten `.env`, kun env-variabler | **BEVIST** | Ekte ingress-terminert HTTPS |
| Proxy/forwarded headers | Ingen trusted proxies som standard; `TRUSTED_PROXIES` per miljø. `tests/Feature/Security/TrustedProxyTest.php` | **BEVIST** — spoofet header vinner ikke | **Ingress-området må verifiseres** og settes i `TRUSTED_PROXIES`, ellers løser alle forespørsler til ingress-adressen |
| `SESSION_SECURE_COOKIE` | Boot-probe leser `session.secure = true` | **BEVIST** | Ekte cookie over HTTPS |
| PostgreSQL: host fra env | Boot-probe med Azure-lignende hostnavn | **BEVIST** | Ekte Flexible Server-hostnavn |
| PostgreSQL: ingen lokale antakelser | Skann av `app/` for hardkodet host | **BEVIST** — ingen treff | — |
| PostgreSQL: `DB_SSLMODE` flyter inn | Boot-probe leser `sslmode = require` | **BEVIST** | **TLS-handshake** — lokal Postgres har `ssl = off`, så dette er *ikke* verifisert lokalt |
| PostgreSQL: migrations idempotente | Egen prosess, `migrate --force` to ganger mot `procynia_test` | **BEVIST** — andre kjøring: "Nothing to migrate" | Mot tom Flexible Server |
| pgvector: hvilken migration | Nøyaktig én migration oppretter extension | **BEVIST** | — |
| pgvector: ingen superuser | Ingen `ALTER SYSTEM`/`CREATE ROLE`/`shared_preload_libraries` | **BEVIST** | Ekte kjøring som Azure-admin uten superuser |
| pgvector: `IF NOT EXISTS` | Kildekontroll | **BEVIST** | Re-kjøring mot database som allerede har extension |
| pgvector: faktisk brukbar | Ekte spørring mot `procynia_test`: extension 0.8.2, `vector`-kolonne, `<=>`-operator | **BEVIST** | Etter `azure.extensions=VECTOR` er aktivert |
| Redis: config fra `REDIS_URL` | Begge connections leser `REDIS_URL` | **BEVIST** | — |
| Redis: `tls://`-scheme | Ekte `RedisManager::parseConnectionConfiguration` | **BEVIST** — promoteres til phpredis TLS | **TLS-handshake mot Managed Redis** |
| Redis: database 0 for cache + kø | Ekte Redis, begge connections tvunget til db 0, med guard | **BEVIST** — ingen nøkkelkollisjon | Samme mot Managed Redis (`EnterpriseCluster`) |
| Redis: kø skriv/les | Ekte jobb pushet og poppet | **BEVIST** | Samme mot Managed Redis |
| Redis: session | Ekte session-handler mot Redis | **BEVIST** | Samme mot Managed Redis |
| Delt lagring: web → worker | Ekte opplasting, ekte disk, **separat OS-prosess** leser samme fil, ekte `pdftotext` | **BEVIST** | **Azure Files SMB** — låsing, `rename()`, latens |
| Delt lagring: negativ kontroll | Worker uten mount feiler tydelig | **BEVIST** | — |
| Delt lagring: container → container | Smoke-script: app- og worker-container, begge veier, identisk `Storage::path()` | **BEVIST** | Azure Files mount i to Container Apps |
| Tempfiler container-lokale | `/tmp` ikke delt mellom containere; `tempnam()` utenfor mount | **BEVIST** | Samme i Container Apps |
| Dokumentprosessering: PDF | Ekte `pdftotext` i handoff-testen | **BEVIST** | Mot fil på Azure Files |
| Dokumentprosessering: DOCX/XLSX | ZipArchive-round-trip + PhpWord til stede; parsing dekket fra før | **BEVIST** | Mot fil på Azure Files |
| Køer: navn og topologi | Jobbklasser ↔ Compose ↔ Bicep ↔ scheduler | **BEVIST** — ni køer, alle konsistente | At Container Appene faktisk konsumerer dem |
| Køer: timeout/tries/backoff/retry_after | Invariant per kø | **BEVIST** | — |
| Lange AI/Wiki-jobber: konfig | Se tabell i seksjon 4 | **BEVIST** | **Revisjonsbytte midt i en 35-minutters jobb** |
| Lange AI/Wiki-jobber: ingen scale-to-zero | Alle workers har fast replica-antall i Bicep | **STATISK** | Ekte KEDA-/replica-oppførsel |
| Scheduler: én instans | Bicep `minReplicas: 1 maxReplicas: 1` | **STATISK** | Ekte replica-antall |
| Scheduler: ingen overlapp | Alle planlagte commands bruker `withoutOverlapping` | **BEVIST** | Revisjonsbytte |
| Scheduler: `Europe/Oslo` | PHP-ini + Bicep-parameter | **BEVIST** | `TZ` i kjørende container |
| Logging: `stderr`-kanal | Ekte prosess, ekte logglinje på STDERR | **BEVIST** | Innsamling i Log Analytics |
| Logging: ingen fil-avhengighet | Ingen kode i `app/`/`routes/` leser `laravel.log` | **BEVIST** | — |
| Secrets: boot uten `.env` | Ekte boot fra base path uten `.env` | **BEVIST** | Key Vault-referanser i Container Apps |
| Secrets: `.env` ikke i image | `.dockerignore` ekskluderer `.env` | **BEVIST** | Inspiser bygget image |
| Secrets: `APP_KEY` regenereres ikke | Ingen `key:generate` i runtime eller composer-hooks | **BEVIST** | — |
| State: restart beholder session | Smoke-script `--with-restarts` | **BEVIST** (opt-in) | Container Apps revision restart |
| State: restart beholder kø | Smoke-script `--with-restarts` | **BEVIST** (opt-in) | Container Apps revision restart |
| Flere web-replikaer | App- og worker-container deler DB, Redis og storage; ingen lokal session-state | **BEVIST** (som state-sikkerhet) | Ekte 2+ replicas bak ingress |
| Migrations som egen jobb | Ingen migrering ved boot; egen prosess migrerer | **BEVIST** | Container Apps Job |
| Integrasjoner: OpenAI/Doffin/Stripe | Base-URL-er fra config, HTTPS, timeouts | **STATISK** | Ekte utgående HTTPS fra Container Apps |
| Opplastingsgrenser | App 20 MB ≤ PHP 50 MB ≤ nginx `client_max_body_size` 50 MB | **BEVIST** | **Container Apps ingress-grense** |
| `procynia:backup` | Runtime-guard: scheduler, command og service stopper Compose-scriptet; ekte kjøring i container bekrefter det | **BEVIST — blokker lukket, se seksjon 5** | Bekreft at `PROCYNIA_LEGACY_BACKUP_ENABLED=false` er satt på containerne |

---

## 4. Timeout-kjeden for lange AI/Wiki-jobber

Verifisert av `QueueTopologyContractTest`. Invarianten er:

```
max(jobb-timeout)  ≤  worker --timeout  <  REDIS_QUEUE_RETRY_AFTER
```

| Kø | Største jobb-timeout | Worker `--timeout` | `retry_after` | Azure grace | Tries |
|---|---|---|---|---|---|
| `supplier-harvests`, `supplier-lookups`, `default` | — | 120 | 420 | 180 | 3 |
| `ai-requirements` | 2100 | 2100 | 2700 | 600 | 1 |
| `enterprise-wiki` | 1860 | 1860 | 2100 | 600 | 1 |
| `enterprise-wiki-reconciliation` | 1800 | 1800 | 2100 | 600 | 1 |
| `enterprise-wiki-claim-verification` | 1800 | 1800 | 2100 | 600 | 1 |
| `enterprise-wiki-maintainer-batches` | 1800 | 1800 | 2100 | 600 | 1 |
| `enterprise-wiki-pages` | 420 | 420 | 480 | 480 | 1 |

**Ingen inkonsistens funnet.** Alle ni køer holder invarianten, og Compose og Bicep er enige om timeout, retry_after og tries i både staging og production.

**Én reell begrensning, ikke en feil:** Azure tillater maksimalt 600 sekunders `terminationGracePeriodSeconds`. `ai-requirements` kan kjøre i 2100 sekunder. En ny revisjon kan derfor drepe en AI- eller Wiki-jobb midt i kjøringen. Testen krever at enhver worker med `timeout > grace` bruker Azures maksimum, så eksponeringen er så liten plattformen tillater — men den forsvinner ikke. **Deploy workloads når køene er drenert.**

HTTP-siden: PHP kjører med `max_execution_time=0`, så det finnes ingen HTTP-timeout som kan være kortere enn worker-timeouten. Lange jobber kjøres aldri i en HTTP-request.

---

## 5. LUKKET: `procynia:backup` kan ikke lenger kjøre i Azure

Dette var den eneste harde blokkeren. Den er nå lukket med en eksplisitt runtime-guard.

### Hvorfor den fantes

1. `routes/console.php` kjørte `Schedule::command('procynia:backup')->hourly()`.
2. `BackupService::runBackup()` kjører `scripts/backup-production.sh`.
3. Det scriptet krever `command -v docker` og kjører `docker compose exec -T postgres pg_dump`.
4. Det finnes ingen docker CLI inne i containeren — smoke-scriptet bekrefter dette.
5. Det finnes ingen compose-prosjekt å `exec` inn i fra en Container App.

### Hvorfor `backup_enabled` ikke var nok

Auditen avdekket at databaseflagget aldri beskyttet manuelle kjøringer. `runBackup()` kortsluttet kun
når `$type === TYPE_SCHEDULED`:

```php
if (! $setting->backup_enabled && $type === BackupRun::TYPE_SCHEDULED) { ... }
```

En administrator som trykket «Kjør manuell backup» i Filament ville derfor startet
`docker compose` uansett hva `backup_enabled` sto på. I tillegg kan en database migrert fra Compose
ankomme Azure med `backup_enabled = true`.

Dette er to forskjellige spørsmål, og de må ikke blandes:

| Flagg | Spørsmål | Hvor |
|---|---|---|
| `backup_settings.backup_enabled` | Har en operatør slått på backup? | Database |
| `procynia.backup.legacy_enabled` | Kan denne runtime kjøre mekanismen i det hele tatt? | Config |

Runtime-guarden vinner alltid, og det er hele poenget med fixen.

### Hvordan den er lukket

Ny config-verdi `procynia.backup.legacy_enabled`, lest fra `PROCYNIA_LEGACY_BACKUP_ENABLED`.

**Default er `true`.** En uspesifisert verdi betyr et eksisterende Compose-miljø, og å stoppe backup
der i stillhet ville vært verre enn å kreve at Azure er eksplisitt. Azure er eksplisitt:
`infra/main.bicep` har `param legacyBackupEnabled bool = false`, begge `.bicepparam` setter den
eksplisitt til `false`, og verdien deles ut til **alle** containere — ikke bare scheduleren — fordi en
manuell kjøring ellers kunne nå scriptet fra web eller en worker.

Guarden ligger på tre nivåer:

| Nivå | Effekt når deaktivert |
|---|---|
| `routes/console.php` | `procynia:backup` blir ikke registrert i scheduleren i det hele tatt |
| `RunBackup` (`php artisan procynia:backup`) | Skriver en tydelig melding og returnerer exit 0 |
| `BackupService::runBackup()` | Returnerer en `skipped` `BackupRun` og starter aldri prosessen — gjelder **alle** trigger-typer |

`BackupService::evaluateStatus()` rapporterer i tillegg `legacy_backup_disabled` i stedet for
`backup_overdue`/`no_scheduler_heartbeat`, slik at en migrert database med `backup_enabled = true`
ikke gir falsk alarm i Azure. Filament-siden skjuler knappen for manuell backup og viser en `skipped`
kjøring som advarsel, ikke som feil.

### Hva som ikke er gjort

Det er **ikke** bygget noen ny Laravel-basert backup-jobb for Azure. Scriptet er ikke fjernet, og
eksisterende Compose-miljøer er uendret. Målarkitekturen står i [`infra/README.md`](../infra/README.md):
Azure PostgreSQL automated backup med point-in-time restore (7 dager staging / 35 dager production),
blob soft delete og versioning, og Azure Files soft delete.

### Gjenværende backup-risiko i Azure

| Risiko | Status |
|---|---|
| En Azure-container mangler `PROCYNIA_LEGACY_BACKUP_ENABLED` | Lav — IaC setter den på alle containere, og `SchedulerContractTest` sjekker det. Skulle den likevel mangle, faller den tilbake til `true` og en manuell kjøring ville feile med «docker: not found» — synlig, ikke stille |
| Azure PostgreSQL PITR er ikke verifisert | Åpen — krever staging. Restore-øvelse er ikke gjennomført |
| Ingen backup av Azure Files-innholdet utover soft delete | Åpen — akseptert i denne fasen; Blob med versioning er target state |
| Restore-prosedyre for Azure er ikke skrevet | Åpen — `docs/operations/backup-restore.md` beskriver Compose-prosedyren |

## 6. Hva som ikke kan bevises før Azure staging

Disse står igjen med vilje. Ingen av dem er mocket bort.

| # | Forhold | Hvorfor lokal test ikke holder |
|---|---|---|
| 1 | **PostgreSQL TLS** | Lokal Postgres kjører med `ssl = off`. `DB_SSLMODE=require` er verifisert som config, men handshaken er ikke kjørt. |
| 2 | **Redis TLS + Managed Redis** | Lokal Redis er redis:7-alpine uten TLS. `tls://`-håndteringen er verifisert i Laravel; selve tilkoblingen er ikke. |
| 3 | **Azure Files SMB-semantikk** | Et lokalt volum er ikke SMB. Fillåsing, `rename()`, og latens under poppler-prosesser kan oppføre seg annerledes. |
| 4 | **Produksjonsimage uten bind mount** | Imaget finnes ikke ennå. Smoke-scriptet har sjekken klar. |
| 5 | **Container Apps ingress-grense** | Ingress har egen request body-grense som ikke kan settes fra Bicep. En 413 derfra ser ut som en applikasjonsfeil. |
| 6 | **Revisjonsbytte midt i lang jobb** | Krever ekte Container Apps-revisjoner. |
| 7 | **Ekte flere web-replicas bak ingress** | Lokalt finnes bare én web-container. State-sikkerheten er bevist; lastfordelingen er ikke. |
| 8 | **Key Vault secret-referanser** | Krever ekte managed identity og RBAC. |
| 9 | **Log Analytics-innsamling** | stderr er bevist; innsamlingen er Azure-side. |
| 10 | **Utgående HTTPS til OpenAI/Doffin** | Krever ekte egress fra Container Apps. |

---

## 7. Anbefalt staging-smoke-test når Azure tenant finnes

Kjør i denne rekkefølgen. Stopp ved første feil.

**Fase 0 — før workloads deployes**
1. `./infra/deploy.sh staging` — validate + what-if, ingen endring.
2. `./infra/deploy.sh staging --apply` — plattform.
3. Seed Key Vault-secrets (`infra/README.md` punkt 9).
4. Bygg og push `procynia-app` og `procynia-web`. Kjør så smoke-scriptets image-seksjon lokalt mot det bygde imaget — den er allerede skrevet for dette.

**Fase 1 — database**
5. Bekreft `azure.extensions` inneholder `VECTOR`.
6. Kjør migration job: `php artisan migrate --force` som Container Apps Job.
7. `select extname, extversion from pg_extension where extname='vector';` skal gi en rad.
8. Kjør migration job **en gang til** — skal si «Nothing to migrate».
9. Bekreft at appen kobler med `DB_SSLMODE=require` (dekker gap 1).

**Fase 2 — Redis**
10. Fra en kjørende container: `php artisan tinker` → `Redis::connection('default')->ping()` (dekker gap 2).
11. Bekreft at `Cache::store('redis')->put()/get()` og `Queue::connection('redis')->size()` virker samtidig (database 0).

**Fase 3 — workloads**
12. `./infra/deploy.sh staging --apply --with-apps`.
13. `GET https://<web-fqdn>/up` → 200.
14. Bekreft at alle ni worker-Container Apps har en kjørende replica.
15. `GET /ops/health/queues/{queue}` for hver av de ni køene, med `PROCYNIA_HEALTH_TOKEN`.
16. `GET /ops/health/queue-scheduler` → 200.

**Fase 4 — delt lagring (den viktigste)**
17. Last opp et ekte PDF-dokument via web-UI.
18. Bekreft at `enterprise_wiki_documents.file_path` er satt og teksten er ekstrahert.
19. `az containerapp exec` inn i en **worker** og bekreft at samme fil er lesbar på samme sti (dekker gap 3).
20. Kjør en ekte Wiki-ingest og bekreft at den fullfører.

**Fase 5 — state og restart**
21. Logg inn, deploy en ny revisjon av web, bekreft at sesjonen overlever (dekker gap 7).
22. Skaler web til 2 replicas, gjør flere requests, bekreft at ingenting krever replica-affinitet.
23. Start en lang Wiki-jobb, deploy en ny worker-revisjon, og **observer** hva som skjer med jobben (dekker gap 6). Dette er en observasjon, ikke en pass/fail — den bestemmer deploy-rutinen.

**Fase 6 — grenser og drift**
24. Last opp en fil rett under 20 MB og bekreft at den går gjennom ingress (dekker gap 5).
25. Bekreft at logger fra web, en worker og scheduleren dukker opp i Log Analytics (dekker gap 9).
26. Bekreft at et OpenAI- og et Doffin-kall lykkes fra en worker (dekker gap 10).
27. **Bekreft at `PROCYNIA_LEGACY_BACKUP_ENABLED=false` er satt på web, workers og scheduler**, og at
    `php artisan procynia:backup` i en container svarer «Legacy Compose backup is disabled» (seksjon 5).
28. Bekreft at Azure PostgreSQL point-in-time restore faktisk fungerer med en restore-øvelse mot en
    engangsserver. Dette er den eneste gjenværende backup-risikoen som krever staging.

---

## 8. Begrensninger i denne testpakken

Sagt rett ut, slik at ingen leser mer trygghet inn i den enn den gir:

- Separate **prosesser** er ikke separate **containere**, og separate containere på én host er ikke separate **Container Apps**.
- Et lokalt filsystem er ikke **Azure Files SMB**.
- `RedisManager`-testene beviser at Laravel tolker en `tls://`-URL riktig. De beviser ingenting om Azure Managed Redis.
- Databaseskrivingen i `SharedStorageHandoffTest` skjer i testprosessen, ikke i worker-prosessen, fordi suiten kjører i en `RefreshDatabase`-transaksjon som en barneprosess ikke kan se. Det som verifiseres på tvers av prosesser er **filsystemet**, som er det Azure Files faktisk endrer.
- Restart-testene er opt-in og kjøres ikke som standard, fordi de avbryter det lokale utviklingsmiljøet.
