# Intern admin-guide for Procynia

## Revisjonslogg

| Dato       | Ansvarlig    | Merknad                                              |
|------------|--------------|------------------------------------------------------|
| 2026-05-16 | Gerhard Næss | Første versjon opprettet (AVVIK-026)                 |

**Neste revisjon:** Ved vesentlige endringer i adminflater eller driftsoppsett  
**Eier:** Teknisk ansvarlig for Procynia

---

## 1. Formål

Denne guiden beskriver hvordan intern admin bruker og følger opp de sentrale adminfunksjonene i Procynia. Guiden skal gjøre intern drift mindre personavhengig og gi en teknisk eller operativ driftsansvarlig nok informasjon til å bruke og vedlikeholde:

- Drift og systemstatus
- Avviksregister
- Backup og recovery
- Billing og fakturering
- AI-bruksmønster og varsler
- Produksjonskontroll og rutiner

Guiden gir ikke skjermbyskrivelser for hvert felt. Den gir kontekst, arbeidsflyt og administrativ oversikt.

---

## 2. Målgruppe

Guiden er for **intern Super Admin** og teknisk driftsansvarlig for Procynia.

Den er **ikke** en sluttbrukerguide for kunder. Kunder bruker den kundevendte appen på `/app/`, ikke adminpanelet på `/admin/`. Disse overflatene er adskilt og skal forbli adskilt.

---

## 3. Tilgang og roller

### Rollemodell

Procynia skiller mellom to typer tilgang:

| Rolle           | `users.role`    | `customer_id` | Tilgang                                 |
|-----------------|-----------------|---------------|-----------------------------------------|
| Intern Super Admin | `super_admin` | `null`        | Fullt adminpanel (`/admin/`)            |
| Kundeadmin      | `customer_admin`| Satt          | Kundeapp (`/app/`), ikke adminpanel     |
| Vanlig bruker   | `user`          | Satt          | Kundeapp (`/app/`), ikke adminpanel     |

**`isInternalAdmin()`** i kodebasen returnerer `true` kun for brukere med `role = 'super_admin'` og `customer_id = null`. Alle interne adminflater sjekker dette før tilgang gis.

### Opprett intern Super Admin

En intern Super Admin skal:
- Ha `role = 'super_admin'`
- Ikke ha `customer_id` (skal stå `null`)
- Autentisere via Filament-innlogging på `/admin/login`

Vanlig kundeinnlogging via `/login` avviser brukere uten kundetilknytning. Super Admin-brukere kan **ikke** logge inn via `/login`.

### Merk

- Kundeadmins og vanlige brukere er kundebrukere. De logger inn på `/login` og kommer til `/app/`.
- Super Admins er interne Procynia-ansatte eller driftsansvarlige. De logger inn på `/admin/login`.
- Tilgangsseparasjonen er håndhevet i koden — det er ikke kun konfigurasjon.

---

## 4. Oversikt over adminområder

Adminpanelet (`/admin/`) er organisert i navigasjonsgrupper:

### Drift

| Side                    | Funksjon                                                         |
|-------------------------|------------------------------------------------------------------|
| Systemstatus            | Samlet oversikt over applikasjonens helsetilstand                |
| Backup og recovery      | Styre og overvåke planlagt og manuell backup                     |
| Queue and scheduler     | Overvåke queue-workers og scheduler-heartbeat                    |
| Incidents               | Registrere og følge opp driftshendelser                          |
| Overvåkning             | Tilleggsinformasjon om systemovervåkning                         |
| Avvik og forbedringer   | Avviksregister for operasjonelle og tekniske avvik               |
| Driftsrutiner           | Strukturerte driftsrutiner og sjekklister                        |

### Fakturering

| Side / ressurs          | Funksjon                                                         |
|-------------------------|------------------------------------------------------------------|
| Billing-oversikt        | Oversikt over alle kunder med abonnementsstatus og plan          |
| AI-bruksmønster og varsler | AI-bruk per kunde og bruker, høy aktivitet, varsler og historiske blokkeringer |
| Tjenestekatalog         | Priser på tjenester (BillingPrice)                               |
| Planer og tjenester     | Produktdefinisjoner (BillingProduct)                             |

### Per kunde

Via **Kundeoversikten** kan man åpne en kundes billing-detaljside. Her finnes informasjon om abonnement, brukerbaserte nivåer og fakturastatus per kunde.

---

## 5. Avviksregister

### Formål

Avviksregisteret (**Avvik og forbedringer** i Drift-gruppen) er den operative kilden til sannhet for sikkerhetsfunn, tekniske forbedringer, driftstiltak og produktforbedringer. Det brukes av intern admin – ikke av kunder.

### Hva et avvik skal inneholde

| Felt                   | Innhold                                                        |
|------------------------|----------------------------------------------------------------|
| Kode                   | Unik kode, format `AVVIK-NNN` – settes automatisk             |
| Tittel                 | Kort, beskrivende tittel                                       |
| Kategori               | Velg riktig kategori (se under)                                |
| Alvorlighet            | Kritisk, Høy, Middels eller Lav                                |
| Beskrivelse            | Hva er avviket? Hva er observert?                              |
| Konsekvens             | Hva er risikoen eller konsekvensen hvis avviket ikke rettes?   |
| Anbefalt tiltak        | Konkret tiltak som skal gjennomføres                           |
| Akseptansekriterier    | Hva skal til for at avviket kan lukkes?                        |
| Kilde / kildedato      | Referanse til rapport, test eller observasjon                  |
| Commit                 | Commit-hash for endringssettet som løser avviket               |
| Verifiseringsnotat     | Dokumentasjon av at tiltaket er kontrollert og virker          |

### Kategorier

Sikkerhet · Drift · Docker · Database · AI · Billing · Brukeropplevelse · Dokumentasjon · Testing · Teknisk gjeld · Produkt · Integrasjoner · Dokumenthåndtering · Annet

### Statusflyt

```
Ny → Planlagt → Pågår → Klar for verifisering → Verifisert → Lukket
                                                            ↘ Utsatt
```

| Norsk status             | Teknisk verdi             | Betyr                                      |
|--------------------------|---------------------------|--------------------------------------------|
| Ny                       | `new`                     | Registrert, ikke prioritert                |
| Planlagt                 | `planned`                 | Planlagt, venter på gjennomføring          |
| Pågår                    | `in_progress`             | Under arbeid                               |
| Klar for verifisering    | `ready_for_verification`  | Tiltak implementert, venter kontroll       |
| Verifisert               | `verified`                | Kontrollert, ikke formelt lukket ennå      |
| Lukket                   | `closed`                  | Ferdigstilt og lukket                      |
| Utsatt                   | `postponed`               | Midlertidig på pause                       |

Tidsstemplene (`Startet`, `Klar for verifisering`, `Verifisert`, `Lukket`) settes automatisk av systemet når status endres.

### Når et avvik kan lukkes

Et avvik kan lukkes når:

1. Tiltaket er implementert og verifisert.
2. `commit_hash` er fylt inn med relevant commit.
3. `verification_notes` er fylt inn med konkret beskrivelse av etterkontroll.
4. Produksjonskritiske avvik har test eller dokumentert etterkontroll der det er mulig.

**Lukking skal bygge på verifikasjon, ikke kun antagelse.** Et avvik lukkes ikke bare fordi arbeidet er ferdig – det lukkes når det er sjekket at det faktisk virker.

### Koder gjenbrukes ikke

`AVVIK-NNN`-koder er permanente. Lukkede avvik slettes ikke, de arkiveres. En lukket kode tildeles aldri et nytt avvik.

---

## 6. Backup og recovery

### Plassering i admin

**Drift → Backup og recovery**

Siden er kun tilgjengelig for intern Super Admin.

### Hva siden viser

- Om planlagt backup er aktivert (av/på-toggle)
- Siste vellykkede backup og siste mislykkede backup
- Scheduler heartbeat-status
- Liste over eksisterende backupfiler på serveren
- Snarlenke til gjenopprettingsinstruksjoner

### Planlagt backup

Planlagt backup kjøres av Laravel Scheduler via en container (`procynia-scheduler`). Admin-siden lar deg aktivere eller deaktivere planlagt backup ved å endre en databaseinnstilling. Selve cron-kjøringen er satt opp på server-nivå og er ikke avhengig av at man er innlogget.

### Manuell backup

Via siden kan admin utløse en manuell backup umiddelbart, uten å vente på neste planlagte kjøring. Brukes ved f.eks. kritiske endringer eller før deploy.

### Scheduler heartbeat

Heartbeat-statusen viser om scheduleren faktisk kjører og registrerer aktivitet. Hvis heartbeat er gammelt eller fraværende, kan planlagte jobber (inkludert backup) ha stoppet. Se [Queue and scheduler](#drift) i adminpanelet og `docs/operations/queues.md` for feilsøking.

### RPO og RTO i Procynia

| Parameter | Mål      | Forklaring                                                       |
|-----------|----------|------------------------------------------------------------------|
| RPO       | 1 time   | Maksimalt akseptabelt datatap. Backup tas minst hver time.       |
| RTO       | 4 timer  | Maksimal gjenopprettingstid fra hendelse til systemet er oppe.   |

### Håndtering av backupvarsler

Hvis Backup og recovery-siden viser feil eller manglende heartbeat:
1. Sjekk at `procynia-scheduler` og `procynia-queue` Docker-containers kjører.
2. Sjekk `docker compose logs procynia-scheduler` for feilmeldinger.
3. Sjekk `docker compose logs procynia-queue` for failed jobs.
4. Kjør manuell backup hvis automatisk backup har stoppet.
5. Opprett avvik i avviksregisteret med funn og tiltak.

### `.env` og secrets

`.env`-filen og secrets inngår **ikke** i ordinære backupfiler. De må bevares separat etter egen rutine. Backup- og restore-siden har ingen restore-knapp; restore er en kontrollert driftsprosedyre som utføres fra server eller annet egnet driftsmiljø. Se `docs/operations/backup-restore.md` seksjon 6 for retningslinjer om secrets.

### Merk

For produksjon bør en moden driftsprosess bruke et dedikert backup- og restore-system. Nåværende løsning er tilpasset tidlig fase. Se `docs/operations/backup-restore.md` for full prosedyre.

---

## 7. Billing og fakturering

### Plassering i admin

**Fakturering**-gruppen i adminpanelet inneholder:

- **Billing-oversikt** – alle kunder, abonnementsstatus, plan og eventuelle prøveperiodeslutt
- **AI-bruksmønster og varsler** – AI-bruk per kunde og bruker, høy aktivitet og historiske varsler (se seksjon 8)
- **Tjenestekatalog** – prisdefinisjon per tjeneste (BillingPrice)
- **Planer og tjenester** – produktdefinisjoner (BillingProduct)

Per-kunde billing nås fra kundeoversikten ved å åpne en kunde og velge billing-fanen.

### Tjenestekatalog og produkter

- **Tjenestekatalog** definerer prisstrukturen for tjenester som faktureres.
- **Planer og tjenester** definerer hvilke produkter som tilbys i Procynia.

Disse administreres av intern admin og er ikke synlige for kunder.

### Per-kunde billing

Fra kundeoversikten kan admin:
- Se abonnementsstatus per kunde
- Se brukerbaserte nivåer og grenser
- Se fakturerings- og betalingsstatus

Betalingsdata er koblet mot Stripe som betalingsbackend. Procynia-appen henter og viser data fra Stripe – betalingslogikken eies av Stripe, ikke av Procynia-kodebasen.

### Prinsipp: kunden ser ikke Stripe-teknikk

Kunden skal ikke eksponeres for Stripe-terminologi eller tekniske detaljer i den kundevendte appen. Billing-admin er internt.

---

## 8. AI-bruksmønster og varsler

### Plassering i admin

**Drift → AI-bruksmønster og varsler**

### Hva siden viser

- AI-bruk per kunde og per bruker
- Antall AI-operasjoner, høy aktivitet, varsler og historiske blokkeringer
- Bruker-tempo med varselgrense fra `.env`-konfigurasjonen

Siden bygger på tabellen `ai_usage_events`. Alle hendelser er logget der for intern aktivitetsoppfølging og varsler.

### Hva siden ikke lagrer

Siden lagrer og viser **ikke**:
- Prompt-tekst
- Kravtekst
- Dokumenttekst
- Svartekst eller chunkinnhold
- API-nøkler

### Formål

Siden er et internt aktivitets- og varselverktøy – ikke Stripe usage billing eller kundefakturering. Tallene brukes til:
- Å oppdage uvanlig høyt tempo
- Å følge opp historiske blokkeringer
- Å bygge erfaring om faktisk AI-forbruk over tid

Varselfunksjonen justeres i `.env` via `AI_RATE_LIMIT_USER_PER_MINUTE`. `AI_RATE_LIMIT_CUSTOMER_PER_HOUR` er deprecated og brukes ikke lenger som stoppmekanisme. Se `docs/operations/ai-usage.md` for konfigurasjon og bakgrunn.

---

## 9. Produksjonskontroll

Etter deploy bør intern admin gjennomføre følgende kontroller. For fullstendig deploy-prosedyre, se `docs/operations/production-deploy.md`.

### Minimumskontroller etter deploy

- [ ] Docker-tjenester kjører: `docker compose ps`
- [ ] Applikasjonen svarer på forventet URL
- [ ] Health-endepunkter svarer korrekt (med riktig token)
- [ ] Queue-workers er aktive: sjekk `procynia-queue`-container og Queue and scheduler i admin
- [ ] Scheduler-heartbeat er oppdatert: sjekk Backup og recovery og Systemstatus i admin
- [ ] Backupstatus er ok: sjekk Backup og recovery i admin
- [ ] Ingen nye failed jobs: sjekk Systemstatus i admin
- [ ] Logger har ingen kritiske feil: `docker compose logs app`
- [ ] AI-bruk har ingen uventede varselstopper: sjekk AI-bruksmønster og varsler
- [ ] Billing-side laster uten feil

---

## 10. Daglige rutiner

En kort daglig sjekkliste for intern admin:

- [ ] Sjekk Systemstatus for overall helsetilstand
- [ ] Sjekk backupstatus i Backup og recovery
- [ ] Sjekk om det finnes failed jobs (vises i Systemstatus)
- [ ] Sjekk om det er nye kritiske avvik i Avvik og forbedringer
- [ ] Sjekk AI-bruksmønster og varsler ved tegn på uvanlig høy AI-aktivitet
- [ ] Sjekk billing ved tegn på betalings- eller abonnementsavvik

---

## 11. Ukentlige rutiner

- Gjennomgå åpne avvik i Avvik og forbedringer. Er de riktig prioritert og oppdatert?
- Sjekk backuphistorikk og verifiser at planlagte backuper har kjørt.
- Sjekk AI-bruksmønster og varsler per kunde – er det kunder med mange historiske blokkeringer eller høy aktivitet?
- Sjekk billingstatus for aktive kunder.
- Vurder om eksisterende dokumentasjon fortsatt stemmer med faktisk oppsett.

---

## 12. Månedlige rutiner

- Test eller verifiser restore-prosessen etter gjeldende backup-rutine (se `docs/operations/backup-restore.md`).
- Gjennomgå åpne produksjonsrisikoer. Er det avvik som bør eskaleres?
- Gjennomgå hvem som har tilgang til adminpanelet. Er listen korrekt?
- Gjennomgå underliggende driftsdokumentasjon (`docs/operations/`). Er innholdet oppdatert?
- Vurder om varselterskelen for bruker-tempo bør justeres basert på faktisk bruk (bruk data fra AI-bruksmønster og varsler).

---

## 13. Håndtering av feil

### Opprett avvik

Ved alle driftsfeil eller observerte risikoer, opprett et nytt avvik i **Avvik og forbedringer**:
1. Klikk «Nytt avvik» i adminpanelet.
2. Velg riktig kategori og alvorlighet.
3. Beskriv hva som er observert, konsekvens og anbefalt tiltak.
4. Sett ansvarlig bruker.
5. Oppdater status etter hvert som arbeidet går fremover.

### Eskaler feil ved

- Kritisk alvorlighet eller direkte brukerdata-risiko.
- Backup mangler over mer enn ett tidsvindu (RPO-brudd).
- Queue har stoppet og jobber hoper seg opp.
- Systemstatus viser vedvarende feil uten åpenbar årsak.

### Backup og restore

Hvis en restore er nødvendig, følg prosedyren i `docs/operations/backup-restore.md`. Ikke forsøk restore uten å ha lest prosedyren.

### Queue-undersøkelse

Hvis queue-workers er stoppet eller failed jobs vokser:
1. Sjekk `docker compose ps` – kjører `procynia-queue`?
2. Sjekk `docker compose logs procynia-queue` for feilmeldinger.
3. Kjør `docker compose restart procynia-queue` hvis container har krasjet.
4. Undersøk failed jobs i Systemstatus – hva er feilmeldingen?
5. Kjør jobs på nytt med `php artisan queue:retry all` etter at rotårsak er rettet.

### Deploy rollback

Vurder rollback hvis:
- Kritiske funksjoner er brutt etter ny deploy.
- Migrasjoner har latt data i inkonsistent tilstand.

Se `docs/operations/production-deploy.md` for rollback-prosedyre. Dokumenter rotårsak i avviksregisteret.

### Rotårsak dokumenteres i avviket

Alle feil som løses skal ha dokumentert rotårsak i `verification_notes`-feltet på avviket. «Fungerer igjen» er ikke tilstrekkelig.

---

## 14. Avgrensninger

- Denne guiden er **intern admin-dokumentasjon** for teknisk driftsansvarlig.
- Den erstatter ikke produksjonsdeploy-guiden (`docs/operations/production-deploy.md`).
- Den erstatter ikke juridisk GDPR- eller personverndokumentasjon.
- Den erstatter ikke kundevendt brukerveiledning.
- Den inneholder ingen passord, API-nøkler, tokens eller reelle kundedata.
- Guiden skal oppdateres når adminflater eller driftsoppsett endres vesentlig.
