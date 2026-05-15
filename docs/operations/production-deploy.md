# Produksjonsdeploy-guide for Procynia

## Revisjonslogg

| Dato       | Ansvarlig      | Merknad                              |
|------------|----------------|--------------------------------------|
| 2026-05-15 | Gerhard Næss   | Første versjon opprettet (AVVIK-005) |
| 2026-05-15 | Gerhard Næss   | AVVIK-029 lukket: HTTPS/TLS-rutine utvidet med TrustProxies, certbot, konkrete verifikasjonskommandoer og sjekk av interne porter |

**Neste revisjon:** Før første produksjonsdeploy  
**Eier:** Teknisk ansvarlig for Procynia

---

## 1. Formål og omfang

Denne guiden beskriver hvordan Procynia settes opp, deployes, verifiseres og vedlikeholdes i produksjon. Den dekker:

- Miljø og forutsetninger
- Secrets og miljøvariabler
- Docker Compose-oppsett for produksjon
- Første deploy steg for steg
- Migrasjoner
- Queue worker og scheduler
- HTTPS/TLS
- Backup og restore
- Health checks og etterkontroll
- Rollback

Guiden gjelder alle som har teknisk driftsansvar for Procynia. En teknisk ansvarlig skal kunne følge den steg for steg uten å måtte gjette på rekkefølge, kommandoer eller sikkerhetstiltak.

---

## 2. Forutsetninger

Følgende skal være på plass før produksjonsdeploy starter:

### Server og runtime

- En Linux-server (Ubuntu 22.04 LTS eller tilsvarende anbefales)
- Docker Engine (nyeste stabile versjon)
- Docker Compose V2 (`docker compose`, ikke `docker-compose`)
- Git installert på serveren
- Tilgang til koderepository (SSH-nøkkel eller deploy token)

### Nettverksoppsett

- Et domenenavn pekt mot serverens IP-adresse
- Åpne porter: `80` og `443` mot internett; interne porter (`5433`, `6380`) skal ikke eksponeres mot internett
- En reverse proxy (Nginx, Traefik eller tilsvarende) som terminerer TLS og videreformidler til Procynias webcontainer

### TLS/HTTPS

- Gyldig TLS-sertifikat for domenet (f.eks. via Let's Encrypt/Certbot)
- Reverse proxy konfigurert til å redirect HTTP til HTTPS og terminere TLS

### Ekstern tilgang og API-nøkler

- PostgreSQL-database (enten som Docker-container eller ekstern managed database)
- Redis (enten som Docker-container eller ekstern managed service)
- SMTP-leverandør konfigurert og testet dersom e-post skal sendes
- OpenAI API-nøkkel dersom AI-funksjoner skal brukes
- Stripe-nøkler og priser konfigurert dersom billing skal aktiveres
- Doffin API-nøkkel dersom anbudshøsting skal brukes

### Dokumenthåndtering

`poppler-utils` (inkluderer `pdftotext`) er installert i Procynias PHP-container via Dockerfile. Binærstien styres av:

```dotenv
PDFTOTEXT_BINARY=/usr/bin/pdftotext
```

Dersom PDF-ekstraksjon ikke brukes aktivt, kan variabelen utelates — men PDF-opplastinger vil gi tom tekst uten advarsel i loggen.

### Backup

- Backup-lokasjon valgt og tilgjengelig (f.eks. offsite S3, Azure Blob, lokal disk med replikering)
- Backup-rutine planlagt og dokumentert før første deploy

### Tilgang

- Teknisk ansvarlig har SSH-tilgang til produksjonsserver
- `sudo`-rettigheter til Docker

---

## 3. Miljøvariabler og secrets

### Prinsipp

Produksjon skal aldri inneholde hardkodede credentials, passord, tokens eller API-nøkler i kode, Dockerfile eller versjonskontrollerte filer. Alle sensitive verdier settes i `.env` på serveren.

`.env` er listet i `.gitignore` og skal aldri committes.

### Kategorier som skal settes i produksjon

Bruk `.env.example` som mal. Erstatt alle `change_me`- og testplaatsholdere med faktiske produksjonsverdier.

#### Applikasjon

```dotenv
APP_NAME=Procynia
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:<generer med php artisan key:generate>
APP_URL=https://app.procynia.no
```

`APP_DEBUG=false` er obligatorisk i produksjon. Debug-modus eksponerer stack traces, konfigurasjon og interne detaljer til alle brukere.

#### Docker / PostgreSQL-tjeneste

```dotenv
POSTGRES_DB=procynia
POSTGRES_USER=<produksjonsbruker>
POSTGRES_PASSWORD=<sterkt tilfeldig passord>
```

Disse brukes av både PostgreSQL-containeren og Laravel-tilkoblingen. Verdiene må matche.

#### Database (Laravel)

```dotenv
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=procynia
DB_USERNAME=<samme som POSTGRES_USER>
DB_PASSWORD=<samme som POSTGRES_PASSWORD>
```

Dersom produksjon bruker en ekstern managed database, sett `DB_HOST` til databaseserverens hostname i stedet for `postgres`.

#### Redis

```dotenv
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=<sett passord dersom Redis er passordbeskyttet>
```

Dersom produksjon bruker en ekstern managed Redis, sett `REDIS_HOST` til ekstern hostname.

#### Queue, cache og session

```dotenv
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
```

#### Logging

```dotenv
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=error
```

Sett `LOG_LEVEL=error` i produksjon for å unngå at logger fylles med debug-informasjon. Vurder `LOG_CHANNEL=stderr` for loggaggregering.

#### E-post

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=<smtp-server>
MAIL_PORT=587
MAIL_USERNAME=<smtp-bruker>
MAIL_PASSWORD=<smtp-passord>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@procynia.no
MAIL_FROM_NAME="Procynia"
```

#### Stripe / Cashier (kun dersom billing er aktivert)

```dotenv
STRIPE_KEY=pk_live_<nøkkel>
STRIPE_SECRET=sk_live_<nøkkel>
STRIPE_WEBHOOK_SECRET=whsec_<nøkkel>
CASHIER_CURRENCY=nok
CASHIER_CURRENCY_LOCALE=nb_NO
STRIPE_PRICE_PRO_MONTHLY=price_<live-pris-id>
STRIPE_PRICE_PRO_YEARLY=price_<live-pris-id>
STRIPE_PRICE_MAX_MONTHLY=price_<live-pris-id>
STRIPE_PRICE_MAX_YEARLY=price_<live-pris-id>
STRIPE_PRICE_ULTRA_MONTHLY=price_<live-pris-id>
STRIPE_PRICE_ULTRA_YEARLY=price_<live-pris-id>
```

Bruk `pk_live_` og `sk_live_`, ikke testkeys, i produksjon.

#### OpenAI (kun dersom AI er aktivert)

```dotenv
OPENAI_API_KEY=<api-nøkkel>
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_MODEL=gpt-4.1-mini
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
```

#### Doffin (kun dersom anbudshøsting er aktivert)

```dotenv
DOFFIN_API_KEY=<api-nøkkel>
DOFFIN_BASE_URL=https://api.doffin.no
```

**Produksjonskrav:** `DOFFIN_BASE_URL` må alltid settes eksplisitt til `https://api.doffin.no` i produksjon. Variabelen har ingen fallback-verdi i kode – manglende konfigurasjon feiler tydelig. Lokal utvikling kan bruke `https://betaapi.doffin.no`, men kun ved bevisst og eksplisitt valg i lokal `.env`.

#### Overvåkning og health

```dotenv
UPTIME_KUMA_URL=https://<din-kuma-instans>
PROCYNIA_HEALTH_TOKEN=<tilfeldig, sterkt token>
```

`PROCYNIA_HEALTH_TOKEN` brukes for å beskytte ops-health-endepunktene. Generer et tilfeldig token med f.eks. `openssl rand -hex 32`.

---

## 4. Docker Compose for produksjon

### Lokal utvikling vs. produksjon

`docker-compose.yml` er konfigurert for lokal utvikling og har `APP_ENV: local` og `APP_DEBUG: "true"` hardkodet i `environment:`-blokkene for `app`-, `queue`- og `scheduler`-tjenestene.

I Docker Compose tar inline `environment:`-verdier forrang over `env_file:`. Det betyr at selv om `.env` har `APP_ENV=production` og `APP_DEBUG=false`, vil de hardkodede lokale verdiene overstyre. Av denne grunn skal produksjon aldri startes med `docker-compose.yml` alene.

`docker-compose.prod.yml` finnes i repoet og overstyrer disse verdiene eksplisitt for `app`, `queue` og `scheduler`:

```yaml
services:
    app:
        environment:
            APP_ENV: production
            APP_DEBUG: "false"
            APP_URL: "${APP_URL}"

    queue:
        environment:
            APP_ENV: production
            APP_DEBUG: "false"
            APP_URL: "${APP_URL}"

    scheduler:
        environment:
            APP_ENV: production
            APP_DEBUG: "false"
            APP_URL: "${APP_URL}"
```

`${APP_URL}` leses fra `.env` på serveren. Filen inneholder ingen secrets og kan versjonskontrolleres.

**Produksjon skal alltid startes med begge filene:**

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

`docker-compose.yml` alene brukes kun for lokal utvikling.

### Tjenester i produksjon

Følgende tjenester skal kjøre:

| Tjeneste    | Funksjon                              | Eksponert port   |
|-------------|---------------------------------------|------------------|
| `app`       | PHP-FPM runtime                       | Ingen (intern)   |
| `web`       | Nginx front end                       | 8080 (se nedenfor) |
| `postgres`  | PostgreSQL database                   | Ingen eksternt   |
| `redis`     | Queue, cache og session               | Ingen eksternt   |
| `queue`     | Laravel queue worker                  | Ingen            |
| `scheduler` | Laravel scheduler                     | Ingen            |

I produksjon skal `web`-containeren ikke eksponere port `8080` direkte mot internett. En ekstern reverse proxy (f.eks. Nginx på serveren) skal terminere TLS og videresende til `web` på port `8080`.

Dersom produksjon bruker ekstern managed database eller Redis, kan `postgres`- og/eller `redis`-tjenestene fjernes fra compose-filen.

---

## 5. Første deploy

Følg disse stegene i angitt rekkefølge.

### 5.1 Ta backup

Dersom det finnes eksisterende data, ta backup av database og `storage/` før deploy starter. Se seksjon 9.

### 5.2 Hent kode

```bash
git clone <repo-url> /var/www/procynia
cd /var/www/procynia
```

Ved oppgradering av eksisterende installasjon:

```bash
cd /var/www/procynia
git pull origin main
```

### 5.3 Sett miljøvariabler

```bash
cp .env.example .env
# Fyll ut alle nødvendige verdier i .env
nano .env
```

Generer applikasjonsnøkkel:

```bash
docker compose run --rm app php artisan key:generate
```

Kjøres kun én gang. Kjøres ikke på nytt etter nøkkelen er satt — det ugyldiggjør eksisterende kryptert data.

### 5.4 Bygg og start containere

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

Kontroller at alle tjenester er oppe:

```bash
docker compose ps
```

Alle tjenester skal vise status `running` eller `healthy`.

### 5.5 Kjør migrasjoner

```bash
docker compose exec app php artisan migrate --force
```

`--force` er nødvendig i produksjon. Kontroller at migrasjoner fullføres uten feil.

### 5.6 Optimaliser Laravel

```bash
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache
docker compose exec app php artisan event:cache
```

Disse stegene må kjøres på nytt ved enhver deploy som endrer konfigurasjon, ruter, views eller events.

### 5.7 Restart queue worker

```bash
docker compose exec app php artisan queue:restart
```

Queue workeren plukker opp signalet og stopper ved neste naturlige pause. Docker-tjenesten starter den automatisk igjen.

### 5.8 Verifiser health

Se seksjon 10 for komplett sjekkliste.

---

## 6. Migrasjoner

### Rutine

- Migrasjoner skal alltid kjøres etter backup og etter at applikasjonskoden er oppdatert.
- Migrasjoner kjøres med `--force` i produksjon.
- Kontroller alltid at migrasjonen fullføres uten feil før applikasjonen åpnes for bruk.

```bash
docker compose exec app php artisan migrate --force
```

### Destructive migrasjoner

Migrasjoner som sletter kolonner, tabeller eller data krever særskilt vurdering:

- Kjør alltid full backup rett før.
- Vurder om migrasjonen kan reverseres med en `down()`-metode.
- Vurder om nedetid er nødvendig for å unngå konflikter mot kjørende kode.
- Test migrasjonen i staging før produksjon.

### Statussjekk

```bash
docker compose exec app php artisan migrate:status
```

Viser hvilke migrasjoner som er kjørt og hvilke som venter.

---

## 7. Queue worker og scheduler

### Queue worker

Queue workeren kjører som tjenesten `procynia-queue`. Den starter automatisk med Docker Compose og restartes av Docker ved serverrestart.

Queue worker bruker følgende retry-strategi:

| Parameter | Verdi | Betydning |
|-----------|-------|-----------|
| `--tries` | `3` | Jobber forsøkes opptil 3 ganger |
| `--backoff` | `60` | 60 sekunder ventetid mellom forsøk |
| `--timeout` | `120` | Jobber avbrytes etter 120 sekunder |
| `--sleep` | `3` | Worker venter 3 sekunder mellom runder når køen er tom |

Jobber som feiler alle 3 forsøk havner i `failed_jobs`-tabellen og kan undersøkes og retries manuelt.

Etter deploy skal queue workeren alltid restartes:

```bash
docker compose exec app php artisan queue:restart
```

Dette signaliserer at aktive workers skal stoppe etter å ha fullført pågående jobb, og ny prosess plukker opp med oppdatert kode.

Kontroller at workeren kjører:

```bash
docker logs procynia-queue --tail=20
```

Forventet output ved oppstart:

```text
[Procynia][Queue] Starting queue worker connection=redis queues=supplier-harvests,supplier-lookups,ai-requirements,default tries=3 backoff=60 timeout=120
```

### Scheduler

Scheduleren kjører som tjenesten `procynia-scheduler` og kjører `php artisan schedule:work` kontinuerlig.

Kontroller at scheduleren kjører:

```bash
docker logs procynia-scheduler --tail=20
```

Forventet output:

```text
[Procynia][Scheduler] Starting scheduler worker
```

### Failed jobs

Kontroller at det ikke finnes feilende jobber:

```bash
docker compose exec app php artisan queue:failed
```

Retry en enkelt job:

```bash
docker compose exec app php artisan queue:retry <job-id>
```

Retry alle feilede jobber:

```bash
docker compose exec app php artisan queue:retry all
```

### Aktive køer

Queue workeren prosesserer følgende køer i prioritert rekkefølge:

1. `supplier-harvests`
2. `supplier-lookups`
3. `ai-requirements`
4. `default`

---

## 8. HTTPS / TLS

### Krav

Produksjon skal alltid eksponeres via HTTPS. HTTP på port 80 skal redirectes til HTTPS.

- `APP_URL` i `.env` skal bruke `https://` — f.eks. `APP_URL=https://app.procynia.no`.
- Gyldig TLS-sertifikat skal være installert og fornyet automatisk.
- TLS termineres i reverse proxy. Procynias webcontainer (`port 8080`) skal ikke eksponeres direkte mot internett.
- Interne porter (`8080`, `5433`, `6380`) skal ikke eksponeres mot internett.

### Reverse proxy

Sett opp en Nginx-instans på vertsmaskinen (utenfor Docker) som:

1. Lytter på port `80` og `443`
2. Videreformidler til `127.0.0.1:8080` (Procynias `web`-container)
3. Terminerer TLS med sertifikat fra Let's Encrypt eller tilsvarende

Eksempel på Nginx reverse proxy-konfigurasjon:

```nginx
server {
    listen 80;
    server_name app.procynia.no;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name app.procynia.no;

    ssl_certificate /etc/letsencrypt/live/app.procynia.no/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.procynia.no/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### Laravel og forwarded headers

Procynia er konfigurert til å stole på forwarded headers fra reverse proxy (`bootstrap/app.php` → `trustProxies(at: '*')`). Applikasjonen gjenkjenner korrekt HTTPS-forespørsler og genererer `https://`-URLer når proxyen sender `X-Forwarded-Proto: https`.

### Sertifikatfornyelse

Ved bruk av Let's Encrypt/Certbot aktiveres automatisk fornyelse med:

```bash
certbot renew --dry-run   # Testgjennomgang uten faktisk fornyelse
certbot renew             # Faktisk fornyelse
```

Certbot legger vanligvis til en cron-jobb eller systemd-timer automatisk. Verifiser at fornyelse er aktivt:

```bash
systemctl list-timers | grep certbot
```

### Kontroller etter TLS-oppsett

Kjør følgende kommandoer etter at TLS er satt opp eller endret:

**HTTPS-respons:**

```bash
curl -I https://app.procynia.no
```

Forventet: `HTTP/2 200` eller `302` redirect til innloggingssiden. Sertifikatet skal være gyldig (ingen `curl`-advarsel).

**HTTP → HTTPS redirect:**

```bash
curl -I http://app.procynia.no
```

Forventet: `301 Moved Permanently` med `Location: https://app.procynia.no/...`

**APP_URL samsvarer med faktisk domene:**

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan config:show app.url
```

Forventet: `https://app.procynia.no`

**Interne porter er ikke eksponert mot internett:**

```bash
ss -tlnp | grep -E '5433|6380|8080'
```

Disse portene skal kun ha `127.0.0.1` som bind-adresse — ikke `0.0.0.0`.

Dersom domenet eller sertifikatet endres, skal hele TLS-sjekklisten ovenfor kjøres på nytt.

---

## 9. Backup og restore

Full dokumentasjon: **`docs/operations/backup-restore.md`**

### Hurtigreferanse

| Parameter | Mål     |
|-----------|---------|
| RPO       | 1 time  |
| RTO       | 4 timer |

### Pre-deploy backup (obligatorisk)

Ta alltid backup rett før deploy:

```bash
./scripts/backup-production.sh /backup/procynia/pre-deploy
```

### Planlagt backup

Konfigurer automatisk timesvis backup med cron. Se `docs/operations/backup-restore.md` seksjon 7.

### Restore

```bash
./scripts/restore-production-backup.sh \
    /backup/procynia/procynia_db_<timestamp>.sql \
    /backup/procynia/procynia_storage_<timestamp>.tar.gz
```

Se `docs/operations/backup-restore.md` for fullstendig restore-prosedyre, etterkontroll og verifisering.

---

## 10. Health checks og etterkontroll

Kjør følgende sjekkliste etter enhver produksjonsdeploy:

### Tjenestestatus

```bash
docker compose ps
```

Alle tjenester skal vise `running` eller `healthy`.

### Applikasjonsrespons

```bash
curl -I https://app.procynia.no
```

Forventet HTTP-statuskode: `200` eller redirect til innloggingsside.

### Queue og scheduler heartbeat

```bash
curl -H "X-Procynia-Health-Token: <PROCYNIA_HEALTH_TOKEN>" https://app.procynia.no/ops/health/queue-scheduler
```

Forventet svar: `{"status":"ok"}` med HTTP `200`.

Per-kø health:

```bash
curl -H "X-Procynia-Health-Token: <PROCYNIA_HEALTH_TOKEN>" https://app.procynia.no/ops/health/queues/default
curl -H "X-Procynia-Health-Token: <PROCYNIA_HEALTH_TOKEN>" https://app.procynia.no/ops/health/queues/ai-requirements
curl -H "X-Procynia-Health-Token: <PROCYNIA_HEALTH_TOKEN>" https://app.procynia.no/ops/health/queues/supplier-harvests
curl -H "X-Procynia-Health-Token: <PROCYNIA_HEALTH_TOKEN>" https://app.procynia.no/ops/health/queues/supplier-lookups
```

### Database

```bash
docker compose exec app php artisan db:show
```

Skal returnere database-informasjon uten feil.

### Innlogging

- Åpne `https://app.procynia.no` i nettleser
- Logg inn som intern admin
- Kontroller at adminpanelet under `/admin` er tilgjengelig

### Logger

```bash
docker logs procynia-app --tail=50
docker logs procynia-queue --tail=50
docker logs procynia-scheduler --tail=50
```

Kontroller at det ikke finnes `ERROR`- eller `CRITICAL`-logglinjer etter deploy.

### Failed jobs etter deploy

```bash
docker compose exec app php artisan queue:failed
```

Ingen nye failed jobs skal ha oppstått som følge av deploy.

### Komplett sjekkliste

- [ ] Alle Docker-tjenester er oppe og `healthy`
- [ ] Applikasjonen svarer på HTTPS
- [ ] Innlogging fungerer
- [ ] Adminpanel `/admin` er tilgjengelig
- [ ] Queue heartbeat er `ok`
- [ ] Scheduler heartbeat er `ok`
- [ ] Per-kø health er `ok` for alle køer
- [ ] Database er tilgjengelig
- [ ] Redis er tilgjengelig
- [ ] Logger har ingen kritiske feil
- [ ] Ingen nye failed jobs etter deploy
- [ ] AI-funksjoner fungerer (dersom aktivert)
- [ ] Doffin-integrasjon fungerer (dersom aktivert)
- [ ] Billing/Stripe fungerer (dersom aktivert)

---

## 11. Rollback

### Koderollback

Dersom ny kode introduserer kritiske feil:

```bash
cd /var/www/procynia
git log --oneline -10        # finn forrige stabile commit
git checkout <forrige-commit-hash>
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
docker compose exec app php artisan queue:restart
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
```

### Database rollback

**Advarsel:** Database-rollback er risikabelt og kan føre til datatap. Aldri rull tilbake migrasjoner uten at full backup er tatt rett før deploy.

Dersom en migrering må reverseres:

```bash
docker compose exec app php artisan migrate:rollback --step=1
```

Kjøres kun dersom `down()`-metoden er implementert og testet for den aktuelle migrasjonen. Destruktive migrasjoner som dropper tabeller eller kolonner kan ikke reverseres uten restore fra backup.

**Dersom migrate:rollback ikke er mulig:** Restore database fra backup tatt rett før deploy.

```bash
docker compose stop app queue scheduler
docker compose exec -T postgres psql -U <db-bruker> <db-navn> < /backup/procynia_<pre-deploy-timestamp>.sql
docker compose start app queue scheduler
```

### Cache etter rollback

```bash
docker compose exec app php artisan config:clear
docker compose exec app php artisan route:clear
docker compose exec app php artisan view:clear
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
```

### Queue restart etter rollback

```bash
docker compose exec app php artisan queue:restart
```

### Verifisering etter rollback

Kjør komplett sjekkliste fra seksjon 10 etter rollback.

---

## 12. Sikkerhetsregler

Følgende regler er obligatoriske i produksjon og skal ikke fravikes:

- **Ingen secrets i Git.** `.env`, passord, API-nøkler, private sertifikater og tokens skal aldri committes.
- **`APP_DEBUG=false` i produksjon.** Debug-modus eksponerer intern informasjon for alle brukere.
- **`APP_ENV=production` i produksjon.** Mange Laravel-sikkerhetskontroller er avhengige av denne.
- **Minst mulig eksponerte porter.** Interne porter for database (`5433`) og Redis (`6380`) skal ikke eksponeres mot internett.
- **Kun nødvendige admin-tilganger.** Produksjonsserver skal ha begrenset SSH-tilgang.
- **Logger skal ikke inneholde sensitive data.** Kontroller at `LOG_LEVEL` ikke er `debug` i produksjon.
- **Produksjon skal bruke HTTPS.** Sertifikat skal fornyes automatisk.
- **Backup skal være beskyttet.** Backup-filer skal lagres sikkert og tilgangsbegrenses.
- **`PROCYNIA_HEALTH_TOKEN` skal være satt** og brukes av Uptime Kuma og overvåkningssystemer.

---

## 13. Åpne avvik med relevans for produksjon

Følgende avvik er registrert og har direkte innvirkning på produksjonssettingen. De er ikke lukket per dato for denne guiden og må håndteres separat:

| Avvik      | Tittel                                         | Status  | Prioritet |
|------------|------------------------------------------------|---------|-----------|
| AVVIK-003  | APP_DEBUG aktiv i Docker-konfigurasjon         | Lukket  | Høy       |
| AVVIK-004  | Ops-health-endepunkter er åpne                 | Lukket  | Høy       |
| AVVIK-006  | Queue worker bruker tries=1 og timeout=0       | Lukket  | Høy       |
| AVVIK-007  | Manglende produksjonsbackup og restore-rutine  | Lukket  | Kritisk   |
| AVVIK-008  | Doffin peker mot beta-API som standard         | Lukket  | Høy       |
| AVVIK-029  | Ingen tydelig produksjons-HTTPS/TLS-rutine     | Lukket  | Kritisk   |

AVVIK-003 er lukket ved opprettelse av `docker-compose.prod.yml`. AVVIK-007 er lukket ved opprettelse av backup/restore-skript og `docs/operations/backup-restore.md`. AVVIK-008 er lukket ved fjerning av beta-default i `config/doffin.php` og oppdatering av `.env.example`. AVVIK-029 er lukket ved utvidelse av seksjon 8 med TrustProxies, certbot-fornyelse, konkrete verifikasjonskommandoer og sjekk av interne porter. Øvrige åpne avvik behandles som egne oppgaver i avviksregisteret.
