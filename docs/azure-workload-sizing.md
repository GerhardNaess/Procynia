# Procynia — Azure workload sizing

Grunnlag for hvor mye CPU og minne de første Azure Container Apps-workloadene skal få.

Reproduser med:

```bash
./scripts/azure-readiness/measure-workloads.sh
```

Alt under er merket **MÅLT** eller **ESTIMAT**. Skillet er ikke kosmetisk: AI-tunge stier er ikke
målt, fordi å måle dem ærlig koster penger.

---

## 1. Målt (produksjonsimage, ekte kodesti)

Kjørt i `procynia-app:production`, PHP 8.4.24, `memory_limit=512M`. Peak memory er
`memory_get_peak_usage(true)`, altså faktisk allokerte sider — ikke et estimat.

| Målepunkt | Tid | Peak memory | Detalj |
|---|---|---|---|
| Framework boot | 0,25 s | **48,5 MB** | Laravel-container bootet, ingen jobb kjørt |
| PDF-ekstraksjon, 10 sider | 0,017 s | 48,5 MB | 0,04 MB fil → 27 499 tegn |
| PDF-ekstraksjon, 50 sider | 0,052 s | 48,5 MB | 0,18 MB fil → 137 539 tegn |
| PDF-ekstraksjon, 150 sider | 0,100 s | **50,5 MB** | 0,53 MB fil → 412 840 tegn |
| DOCX-parsing, 200 avsnitt | 0,001 s | 48,5 MB | 15 488 tegn |
| DOCX-parsing, 1 000 avsnitt | 0,005 s | 48,5 MB | 77 888 tegn |

**Den viktigste konklusjonen: dokumentparsing er ikke flaskehalsen.** Et 150-siders dokument koster
2 MB over baseline og en tidel av et sekund. Framework boot dominerer minnebruken fullstendig, og
den er en fast kostnad per replica — ikke per jobb.

Sizing-gulvet for enhver worker er derfor ~50 MB + PHP-overhead. Alt over det handler om hva jobben
holder i minnet samtidig, ikke om filstørrelse.

---

## 2. Ikke målt — krever eksplisitt beslutning

En representativ `ai-requirements`-kjøring og en `enterprise-wiki`-kjøring domineres av
OpenAI-rundturer, ikke av lokal CPU. Å måle dem ærlig krever ekte completions mot ekte
anbudsdokumenter, og det koster penger. Målingen er derfor **ikke** gjort.

For å gjøre den trengs:

1. **Godkjenning for reell OpenAI-bruk.** Størrelsesorden: en full Wiki-kjøring er titalls
   `gpt-4.1-mini`-kall; svarutkast bruker `gpt-5`.
2. **Et representativt dokumentsett** som kan prosesseres i en engangsdatabase.
3. `ENTERPRISE_WIKI_AI_ENABLED=true` i målemiljøet.

Uten dette er AI-worker-sizingen under et **estimat**, avledet fra kjent kodeoppførsel — ikke fra
måling.

---

## 3. Timeout-kontrakt (deklarert)

| | Verdi | Kilde |
|---|---|---|
| PHP `max_execution_time` | 0 (ubegrenset) | `docker/production/php.ini` |
| PHP `memory_limit` | 512 MB | samme |
| OpenAI request timeout | 120 s (`createResponse`), 180 s (`get`/`post`) | `OpenAiClient` |
| OpenAI connect timeout | `min(timeout, 10)` s | samme |
| `ai-requirements` worker | `--timeout=2100`, `retry_after=2700` | `docker-compose.yml` + Bicep |
| `enterprise-wiki` worker | `--timeout=1860`, `retry_after=2100` | samme |
| Azure termination grace (maks) | 600 s | Container Apps-plattformgrense |

Ingen inkonsistens funnet: `max(jobb-timeout) ≤ worker --timeout < retry_after` holder for alle ni
køer, håndhevet av `QueueTopologyContractTest`.

**Den ene reelle spenningen:** en `ai-requirements`-jobb kan kjøre i 2 100 s, mens Azure maksimalt gir
600 s termination grace. En revisjonsutrulling kan derfor drepe en jobb midt i kjøring. Det er en
plattformgrense, ikke en feil — konsekvensen er at workloads bør deployes når køene er drenert.

---

## 4. Anbefalt første Azure-sizing

Container Apps krever `memory = cpu × 2 GiB`.

### Staging — bevisst nøkternt

| Workload | CPU | Minne | Replicas | Grunnlag |
|---|---|---|---|---|
| `web` | 0,5 | 1 GiB | 1–2 | ESTIMAT. Boot 48,5 MB målt; php-fpm kjører 4–20 workers, hver med samme gulv |
| `w-default` | 0,25 | 0,5 GiB | 1 | MÅLT-nært. Korte jobber, 120 s timeout |
| `w-ai-req` | 0,5 | 1 GiB | 1 | ESTIMAT — se seksjon 2 |
| `w-wiki` | 0,5 | 1 GiB | 1 | ESTIMAT |
| `w-wiki-recon` | 0,25 | 0,5 GiB | 1 | ESTIMAT |
| `w-wiki-claims` | 0,5 | 1 GiB | 1 (2 prosesser) | ESTIMAT |
| `w-wiki-batches` | 0,5 | 1 GiB | 1 (2 prosesser) | ESTIMAT |
| `w-wiki-pages` | 0,5 | 1 GiB | 1 (2 prosesser) | ESTIMAT |
| `scheduler` | 0,25 | 0,5 GiB | 1 | MÅLT-nært. Kjører bare `schedule:work` |

Dette er verdiene som allerede står i `infra/environments/staging.bicepparam`. Målingene motsier dem
ikke: 0,25 vCPU / 0,5 GiB gir ~10× headroom over målt peak for parsing-tunge, ikke-AI-workloads.

**Merk in-container-prosesser:** `w-wiki-claims`, `w-wiki-batches` og `w-wiki-pages` kjører 2 (staging)
til 4 (production) `queue:work`-prosesser i samme replica. Hver prosess betaler
framework-boot-gulvet på ~50 MB. 4 prosesser × 50 MB = 200 MB før noe arbeid gjøres, som er hvorfor
disse ikke bør ligge under 1 GiB.

### Production — konservativt, ikke overdimensjonert

Verdiene i `infra/environments/production.bicepparam` (1,0 vCPU / 2 GiB for AI- og Wiki-workers)
står uendret. De er et **estimat** og bør revideres etter første måned med reell telemetri fra Log
Analytics — det er første gang vi får ekte tall på AI-stiene.

### Hva som ville endret anbefalingen

- Måling av en ekte AI-kjøring (seksjon 2) — kan flytte AI-workerne opp eller ned.
- Et dokument som er vesentlig større enn 150 sider eller inneholder mange bilder; bildeuttrekk via
  `pdfimages` er ikke målt her.
- Flere enn 4 prosesser per worker-replica.

---

## 5. Metode

Målingene kjører i et engangscontainer fra produksjonsimaget, ett per målepunkt, uten database og
uten Redis. Kun målescriptet (`scripts/azure-readiness/measure-workloads.php`) mountes inn;
applikasjonen kommer fullt og helt fra imaget.

Målescriptet nekter å rapportere et resultat hvis `pdftotext` ikke er kjørbar eller ekstraksjonen
gir null tegn — en rask nullmåling ser ellers ut som et godt resultat.
