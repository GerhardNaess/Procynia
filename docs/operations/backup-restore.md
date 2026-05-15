# Backup og restore – Procynia

## Revisjonslogg

| Dato       | Ansvarlig    | Merknad                               |
|------------|--------------|---------------------------------------|
| 2026-05-15 | Gerhard Næss | Første versjon opprettet (AVVIK-007)  |

**Neste revisjon:** Kvartalsvis, eller etter vesentlig endring i driftsoppsett  
**Eier:** Teknisk ansvarlig for Procynia

---

## 1. Formål og omfang

Denne guiden beskriver backup- og restore-prosedyrene for Procynia i produksjon. Den dekker:

- Hvilke data som tas backup av
- Backup-frekvens og oppbevaringspolicy
- Automatisering og gjennomføring
- Restore-prosedyre steg for steg
- Etterkontroll etter restore
- Sikkerhet og tilgangskontroll
- Vedlikehold og verifisering

Guiden gjelder alle med teknisk driftsansvar for Procynia og skal kunne følges av en annen teknisk person uten forhåndskunnskaper om opprinnelig oppsett.

---

## 2. Mål for gjenoppretting (RPO og RTO)

| Parameter | Mål        | Forklaring                                                                   |
|-----------|------------|------------------------------------------------------------------------------|
| **RPO**   | 1 time     | Maksimalt akseptabelt datatap. Backup tas minst hver time.                   |
| **RTO**   | 4 timer    | Maksimal gjenopprettingstid fra hendelse oppdages til systemet er operativt. |

Disse målene gjelder for databasedata. Konfigurasjon (`.env`, secrets) har egne retningslinjer; se seksjon 6.

---

## 3. Hva tas backup av

| Hva                        | Hvor på serveren          | Backup-metode          | Prioritet |
|----------------------------|---------------------------|------------------------|-----------|
| PostgreSQL-database        | Docker-volume `pgdata`    | `pg_dump` SQL-tekstformat | Kritisk   |
| Opplastede filer           | `storage/app/`            | `tar.gz`-arkiv         | Kritisk   |
| `.env`-filen               | Ikke i repo, manuelt bevart | Separat rutine        | Kritisk   |
| `bootstrap/cache/`         | Kan regenereres            | Ikke nødvendig         | Lav       |
| Git-repository             | GitHub                    | Push til remote        | Lav       |

**`.env` og secrets tas ikke backup av dette scriptet.** Se seksjon 6 for retningslinjer om secrets.

---

## 4. Oppbevaringspolicy

| Kategori         | Oppbevaringstid | Formål                                              |
|------------------|-----------------|-----------------------------------------------------|
| Timesvis         | 48 timer        | Rask gjenoppretting etter nylig feil                |
| Daglig           | 14 dager        | Gjenoppretting ved feil oppdaget samme dag/uke      |
| Ukentlig         | 8 uker          | Gjenoppretting ved langsomme feil                   |
| Månedlig         | 12 måneder      | Langtidsoppbevaring og revisjon                     |
| Pre-deploy       | 30 dager        | Sikkerhetsnett før deploy                           |
| Pre-deploy (stor) | 90 dager       | Større migrasjoner eller vesentlige strukturendringer |

Rydd opp i gamle backuper regelmessig — se seksjon 12.

---

## 5. Backup-skript

### Plassering

```
scripts/backup-production.sh
scripts/restore-production-backup.sh
```

Begge skript kjøres fra prosjektets rotkatalog.

### Backup-skript – bruk

```bash
# Standard backup til /backup/procynia
./scripts/backup-production.sh

# Backup til egendefinert katalog
./scripts/backup-production.sh /mnt/offsite/procynia-backup
```

Skriptet:
1. Leser `POSTGRES_USER`, `POSTGRES_DB` og `POSTGRES_PASSWORD` fra `.env`
2. Tar PostgreSQL-dump via `docker compose exec -T postgres pg_dump`
3. Tar komprimert arkiv av `storage/app/`
4. Logger alle operasjoner til loggfil i backup-katalogen

Genererte filer:

```
/backup/procynia/procynia_db_<timestamp>.sql
/backup/procynia/procynia_storage_<timestamp>.tar.gz
/backup/procynia/backup_<timestamp>.log
```

### Restore-skript – bruk

```bash
# Restore kun database
./scripts/restore-production-backup.sh /backup/procynia/procynia_db_20260515_120000.sql

# Restore database og storage
./scripts/restore-production-backup.sh \
    /backup/procynia/procynia_db_20260515_120000.sql \
    /backup/procynia/procynia_storage_20260515_120000.tar.gz

# Restore uten interaktiv bekreftelse (for automatisert testing)
FORCE=1 ./scripts/restore-production-backup.sh /backup/procynia/procynia_db_20260515_120000.sql
```

Restore-skriptet:
1. Ber om skriftlig bekreftelse fra operatøren (med mindre `FORCE=1`)
2. Stopper `app`, `queue` og `scheduler`
3. Terminerer eksisterende databasetilkoblinger
4. Dropper og gjenskaper databasen
4. Restorer database fra SQL-dump
5. Restorer `storage/app/` fra arkiv (hvis angitt)
6. Starter tjenestene igjen
7. Viser `docker compose ps` som etterkontroll

---

## 6. Secrets og `.env`

`.env`-filen inneholder produksjonshemmeligheter og tas **ikke** backup av backup-skriptet.

Retningslinjer for secrets:

- `.env` skal lagres i en godkjent secret manager (f.eks. Bitwarden for Teams, HashiCorp Vault, eller tilsvarende)
- `.env` kopieres aldri til backup-katalogen
- Ny server-oppsett: `.env` hentes fra secret manager, aldri fra backup-katalogen
- Minst to personer i organisasjonen skal ha tilgang til produksjons-`.env`

---

## 7. Automatisk backup med cron

Timesvis backup settes opp med cron på produksjonsserveren. Cron-jobb kjøres som brukeren som eier Docker-prosessen.

Vis gjeldende cron-jobber:

```bash
crontab -l
```

Sett opp automatisk backup:

```bash
crontab -e
```

Legg til følgende linjer:

```cron
# Procynia – timesvis backup
0 * * * * cd /var/www/procynia && ./scripts/backup-production.sh /backup/procynia >> /backup/procynia/cron.log 2>&1

# Procynia – rydde gamle backuper (se seksjon 12)
30 3 * * * find /backup/procynia -name "procynia_db_*.sql" -mtime +14 -delete && find /backup/procynia -name "procynia_storage_*.tar.gz" -mtime +14 -delete
```

Tilpass stiene etter faktisk serveroppsett.

**Kontroller at cron kjøres etter reboot:**

```bash
crontab -l
docker compose ps
```

---

## 8. Pre-deploy backup

Backup skal alltid tas rett før deploy. Dette er en del av deploy-prosedyren i `docs/operations/production-deploy.md`.

```bash
./scripts/backup-production.sh /backup/procynia/pre-deploy
```

Pre-deploy backup lagres i `/backup/procynia/pre-deploy/` og oppbevares i 30 dager (90 dager for store migrasjoner).

---

## 9. Manuell backup utenom rutine

Ta backup når som helst:

```bash
./scripts/backup-production.sh
```

Eller med egendefinert katalog:

```bash
./scripts/backup-production.sh /mnt/offsite/procynia/$(date +%Y-%m-%d)
```

---

## 10. Restore – steg for steg

### 10.1 Identifiser årsak og omfang

Før restore starter:

1. Identifiser hva som er galt — er det datatap, korrupsjon, feilaktig deploy, eller annet?
2. Finn riktig backup-fil basert på tidspunkt for hendelsen
3. Vurder om kun database, kun storage, eller begge deler trenger restore

### 10.2 Ta ny backup av nåværende tilstand

Selv om nåværende tilstand er skadet, ta backup av det som er der:

```bash
./scripts/backup-production.sh /backup/procynia/pre-restore
```

Dette gir mulighet til å gå tilbake dersom restore-forsøket forverrer situasjonen.

### 10.3 Velg backup-fil

```bash
ls -lth /backup/procynia/
```

Velg `procynia_db_<timestamp>.sql` fra tidspunktet rett før hendelsen.

### 10.4 Kjør restore

```bash
./scripts/restore-production-backup.sh \
    /backup/procynia/procynia_db_<timestamp>.sql \
    /backup/procynia/procynia_storage_<timestamp>.tar.gz
```

Skriv `ja` ved bekreftelsesprompt.

### 10.5 Etterkontroll

Se seksjon 11.

---

## 11. Etterkontroll etter restore

Kjør følgende sjekkliste etter alle restore-operasjoner:

### Tjenestestatus

```bash
docker compose ps
```

Alle tjenester skal vise `running` eller `healthy`.

### Applikasjonsrespons

```bash
curl -I https://app.procynia.no
```

Forventet: `HTTP/2 200` eller redirect til login.

### Database-tilkobling

```bash
docker compose exec app php artisan tinker --execute="DB::select('SELECT count(*) FROM users');"
```

Returnerer antall brukere — skal være > 0 i produksjon.

### Queue og scheduler

```bash
curl -s -H "X-Procynia-Health-Token: <token>" https://app.procynia.no/ops/health/queue-scheduler
```

Forventet: `{"status":"ok"}` (kan ta noen minutter etter oppstart).

### Loggsjekk

```bash
docker logs procynia-app --tail=50
docker logs procynia-queue --tail=20
```

Ingen kritiske feil eller unhandled exceptions.

### Funksjonell manuell test

Logg inn som intern admin og verifiser at:
- Kundeoversikt viser data
- Siste kjente sak er tilgjengelig
- Avviksregisteret laster

---

## 12. Vedlikehold av backup-katalogen

Rydd jevnlig opp i gamle backup-filer for å unngå at disken fylles opp.

### Fjern backuper eldre enn 14 dager (daglig rutine)

```bash
find /backup/procynia -name "procynia_db_*.sql" -mtime +14 -delete
find /backup/procynia -name "procynia_storage_*.tar.gz" -mtime +14 -delete
find /backup/procynia -name "backup_*.log" -mtime +30 -delete
```

### Sjekk diskforbruk

```bash
du -sh /backup/procynia/
df -h /backup
```

Dersom disken er over 80 % full, rydd ut eller flytt backuper til eksternt lager.

---

## 13. Ekstern lagring

Backup-filer på lokal disk er ikke tilstrekkelig alene. Backupene skal kopieres til eksternt lager.

Anbefalt oppsett:

- Objektlagring (S3-kompatibel, Azure Blob eller tilsvarende)
- Ekstern NAS med replikering
- Minimum: separat disk på separat fysisk maskin

Eksempel med `rclone` til S3:

```bash
rclone copy /backup/procynia/ s3:procynia-backups/$(hostname)/$(date +%Y/%m/%d)/
```

Tilpass til faktisk leverandør og konfigurasjon.

---

## 14. Verifisering av backup (månedlig)

Backup er ikke tilstrekkelig med mindre restore er testet. Gjennomfør månedlig verifisering:

1. Hent en tilfeldig backup-fil (velg fra forrige uke)
2. Sett opp et isolert testmiljø (separat Docker Compose-instans med annen port)
3. Kjør restore mot testmiljøet:
   ```bash
   FORCE=1 ./scripts/restore-production-backup.sh \
       /backup/procynia/procynia_db_<testfil>.sql
   ```
4. Start testmiljøet og logg inn
5. Verifiser at data er tilstede og applikasjonen fungerer
6. Dokumenter verifiseringen i avviksregisteret eller driftslogg

Test **aldri** direkte på produksjonsdatabasen uten at full ny backup er tatt rett før.

---

## 15. Sikkerhet

- **Backup-filer inneholder produksjonsdata.** Behandle dem som sensitive.
- Backup-katalogen skal ha begrenset tilgang: kun driftsansvarlige.
- Backup-filer skal **ikke** legges i Git-repository.
- `*.sql`, `*.dump`, `*.tar.gz` i backup-katalogen er dekket av `.gitignore`.
- Ekstern lagring skal beskyttes med tilgangskontroll og kryptering i ro.
- Backup-filer sendes aldri på e-post eller usikrede kanaler.

---

## 16. Ansvar og rutine

| Ansvar                              | Hvem                        | Frekvens        |
|-------------------------------------|-----------------------------|-----------------|
| Cron-backup kjøres og er oppdatert  | Teknisk ansvarlig           | Månedlig sjekk  |
| Ekstern kopi verifisert             | Teknisk ansvarlig           | Månedlig        |
| Restore-test i isolert miljø        | Teknisk ansvarlig           | Månedlig        |
| Pre-deploy backup                   | Den som deployer            | Hver deploy     |
| Secrets i secret manager            | Teknisk ansvarlig           | Ved endring     |

---

## 17. Tilknyttede dokumenter

- `docs/operations/production-deploy.md` — full deploy-prosedyre inkl. pre-deploy backup
- `docs/operations/queues.md` — queue worker og scheduler
- `scripts/backup-production.sh` — backup-skript
- `scripts/restore-production-backup.sh` — restore-skript

---

## 18. Admin-administrasjon (midlertidig)

Backup styres midlertidig fra **Admin → Drift → Backup og restore**.

### Tilgang

Kun Super Admin (intern admin uten tilknyttet kunde) har tilgang til siden.

### Start og stopp av planlagt backup

Start/Stopp i Admin styrer Procynias `backup_enabled`-innstilling i databasen — **ikke** hostens cron direkte.

- **Start backup** setter `backup_enabled = true`. Planlagte backuper kjøres automatisk av Laravel scheduler etter konfigurert schedule (timesvis).
- **Stopp backup** setter `backup_enabled = false`. Planlagte backuper hoppes over, men artisan-cron og scheduler fortsetter å kjøre.

For at planlagt backup faktisk skal kjøre, må Laravel scheduler fortsatt være aktiv på serveren (se seksjon 7).

### Hva siden viser

- Backup aktivert: Ja/Nei
- Siste scheduler-heartbeat (oppdateres av `procynia:backup`-kommandoen ved hver kjøring)
- Siste vellykkede backup
- Siste feilede backup
- Antall backupfiler funnet i backup-katalogen
- RPO: 1 time / RTO: 4 timer
- Siste backup-kjøringer (type, status, varighet, filnavn, feilmelding)
- Backupfiler i backup-katalogen (filnavn, størrelse, sist endret)
- Restore-instruksjon med lenke til denne dokumentasjonen

### Manuell backup fra Admin

Super Admin kan kjøre manuell backup direkte fra siden via «Kjør manuell backup»-knappen. Backup-kjøringen registreres i `backup_runs`-tabellen.

### Systemstatus-varsler

Systemstatus-siden (`Admin → Drift → Systemstatus`) viser varsel dersom:

- Backup er aktivert, men scheduler ikke har rapportert heartbeat
- Siste vellykkede backup er eldre enn 1 time (RPO-brudd)
- Siste backup-kjøring feilet
- Backup-katalogen ikke finnes
- Ingen backupfiler er funnet
- Backup er stoppet manuelt

E-post/SMS-varsling er ikke implementert ennå og dokumenteres som et fremtidig avvik.

### Midlertidig løsning

Denne Admin-løsningen er beregnet for **pilot og tidlig drift**. Ved moden produksjon skal backup håndteres av et profesjonelt backup-/restore-system, for eksempel:

- Managed database med PITR/WAL (point-in-time recovery)
- Ekstern backupplattform med automatisk replikering og varsling
- Hosted database-tjeneste med innebygd backup

---

## 19. Kjente begrensninger

- Backup-skriptet bruker SQL-tekstformat (`pg_dump` uten `--format=custom`), noe som gir større filer men bedre portabilitet.
- Restore krever at `postgres`-containeren er oppe. Dersom hele Docker-infrastrukturen er nede, må postgres startes manuelt først: `docker compose start postgres`.
- Cron-backup forutsetter at Docker Compose kjøres som brukeren som eier cron-jobben. Dersom Docker kjøres med `sudo`, må cron-jobben justeres tilsvarende.
- Backup av `.env` er operatørens ansvar og dekkes ikke av skriptene.
