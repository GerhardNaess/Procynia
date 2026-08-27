# Procynia — kostnadsestimat, Azure staging

Basert på SKU-ene som faktisk står i `infra/environments/staging.bicepparam` per i dag.

**Dette er et estimat, ikke et tilbud.** Azure-priser varierer med region, avtaletype (MCA/EA/CSP),
valutakurs og over tid. Tallene under er i USD, listepris, Norway East, og er ment å gi
størrelsesorden — ikke to desimaler. Verifiser mot
[Azure Pricing Calculator](https://azure.microsoft.com/pricing/calculator/) med deres egen avtale før
noe budsjetteres.

---

## 1. Hovedfunnet først

**De alltid-på workerne er den største enkeltposten i staging — større enn databasen, Redis, ACR og
storage til sammen.**

Container Apps-oppsettet holder **3,75 vCPU og 7,5 GiB oppe døgnet rundt**:

| Workload | vCPU | Minne | Replicas |
|---|---|---|---|
| web | 0,5 | 1 GiB | 1 (min) |
| w-default | 0,25 | 0,5 GiB | 1 |
| w-ai-req | 0,5 | 1 GiB | 1 |
| w-wiki | 0,5 | 1 GiB | 1 |
| w-wiki-recon | 0,25 | 0,5 GiB | 1 |
| w-wiki-claims | 0,5 | 1 GiB | 1 |
| w-wiki-batches | 0,5 | 1 GiB | 1 |
| w-wiki-pages | 0,5 | 1 GiB | 1 |
| scheduler | 0,25 | 0,5 GiB | 1 |
| **Sum** | **3,75** | **7,5 GiB** | **9 replicas** |

Dette er en direkte konsekvens av en bevisst arkitekturbeslutning: ingen worker kan scale-to-zero,
fordi en `ai-requirements`-jobb kan kjøre i 35 minutter og ikke tåler å bli evakuert. Ni køer med
hver sin `REDIS_QUEUE_RETRY_AFTER` kan heller ikke slås sammen uten å endre køsemantikken.

Beslutningen er riktig for **production**. For **staging** er den dyrere enn nødvendig — se §4.

---

## 2. Estimat per tjeneste

### Container Apps — den variable og dominerende posten

Consumption-profilen faktureres per vCPU-sekund og GiB-sekund, med to satser: **aktiv** (replica
gjør arbeid) og **idle** (replica lever, men bruker nesten ingen CPU). Køworkere som poller Redis
hvert 3. sekund havner et sted mellom — hvor, avhenger av hvor mye som faktisk kjøres.

Med ~2,6 millioner sekunder i måneden og det månedlige gratiskvantumet trukket fra:

| Scenario | Grovt per måned |
|---|---|
| Alt på idle-sats (staging står stort sett stille) | **~$85** |
| Alt på aktiv sats (kontinuerlig arbeid) | **~$290** |

Realistisk staging ligger nærmere den lave enden, men **dette er den posten som må måles, ikke
estimeres.** Første månedsfaktura er svaret.

### Faste poster

| Tjeneste | SKU (staging) | Grovt per måned |
|---|---|---|
| PostgreSQL Flexible Server | Burstable `Standard_B1ms`, 32 GiB, 7 dagers backup, ingen HA | **~$20** |
| Azure Managed Redis | `Balanced_B0` (0,5 GB, ingen HA) | **~$40–45** |
| Container Registry | Basic | **~$5** |
| Storage Account | Standard_LRS, Files + blob | **~$2–8** (avhenger av faktisk lagret volum) |
| Managed Identity, Key Vault (standard, få operasjoner) | — | **< $1** |
| **Sum faste** | | **~$70–80** |

### Log Analytics — variabel, og lett å undervurdere

Ni containere som logger på `info` til stdout. Ingestion faktureres per GB.

| Scenario | Grovt per måned |
|---|---|
| Rolig staging (~0,1 GB/dag) | **~$10** |
| Aktiv testing (~0,5 GB/dag) | **~$40** |
| Ved dagskvoten på 2 GB/dag | **~$165** (tak) |

`logAnalyticsDailyQuotaGb = 2` i staging er allerede satt som et tak nettopp for å hindre at en
loop-loggende container gir en overraskelsesfaktura. Taket dropper logger når det treffes — det er
ønsket oppførsel i staging, men verdt å vite.

---

## 3. Samlet

| | Per måned |
|---|---|
| Faste tjenester | ~$70–80 |
| Container Apps (compute) | ~$85–290 |
| Log Analytics | ~$10–165 |
| **Staging totalt** | **~$165 (rolig) til ~$535 (verste fall)** |

Realistisk forventning for et staging-miljø som brukes i arbeidstiden: **$180–280 per måned**.

Spennet er ærlig, ikke unnvikende. To av tre poster er bruksavhengige, og ingen av dem kan
forutsies presist før miljøet faktisk kjører.

---

## 4. Konkret kostnadsreduksjon i staging

**Skaler workerne til null mellom testøktene.** Regelen om «ingen scale-to-zero» finnes for å
beskytte *jobber som kjører* — ikke for å kreve at staging står oppe hele døgnet.

```bash
# Etter en testøkt
for app in w-default w-ai-req w-wiki w-wiki-recon w-wiki-claims w-wiki-batches w-wiki-pages; do
  az containerapp update -n "procynia-stg-${app}" -g rg-procynia-staging-norwayeast \
    --min-replicas 0 --max-replicas 0
done

# Før neste testøkt: sett tilbake til 1/1
```

Gjøres dette utenfor arbeidstid og i helger, faller Container Apps-posten med grovt **60–70 %**.

Viktig: gjør dette **kun i staging**, og **kun når køene er tomme**. I production er alltid-på
kravet reelt.

Andre lovlige knapper, i prioritert rekkefølge:

1. **Log Analytics-retention** fra 30 til 7 dager i staging — liten effekt på ingestion-kost, men
   reduserer lagringskost.
2. **Slå av Application Insights** (`deployApplicationInsights = false`) til det faktisk brukes.
   Ingenting i applikasjonen instrumenterer mot den ennå.
3. **Riv hele staging-miljøet** mellom milepæler. Alt er i Bicep; `az group delete` og en ny
   `--apply` gjenoppretter det. Husk at Key Vault har soft delete — staging har derfor bevisst
   `keyVaultPurgeProtection = false` og 7 dagers retention, nettopp for at navnet skal kunne
   gjenbrukes.

Det som **ikke** er en lovlig knapp: å slå sammen køworkere, eller å skru på scale-to-zero for
AI/Wiki-workerne. Det ville gjort staging til et dårligere speil av production akkurat der risikoen
er størst.

---

## 5. Production — kort

Ikke estimert i detalj her, men størrelsesordenen er vesentlig høyere:

- PostgreSQL `Standard_D2ds_v5` GeneralPurpose med ZoneRedundant HA er **omtrent 8–10×** Burstable
  B1ms, før geo-redundant backup.
- Redis `Balanced_B1` med HA er omtrent **2–3×** B0.
- Container Apps: 7,0 vCPU alltid på mot stagings 3,75, pluss web som skalerer til 6 replicas.
- Log Analytics uten dagskvote.

Gjør et eget estimat i Pricing Calculator før production-budsjettet settes. Ikke ekstrapoler fra
staging-tallene her.

---

## 6. Hva som ville endret dette

- Faktisk avtaletype (CSP/MCA-rabatter kan være betydelige).
- Reserved capacity for PostgreSQL — 1 eller 3 års binding gir vesentlig lavere pris, men er ikke
  aktuelt for staging.
- Første måneds faktiske Container Apps-forbruk, som er den eneste måten å avgjøre idle-vs-aktiv.
- Om staging faktisk skrus ned utenom arbeidstid (§4).
