# Enterprise LLM Wiki — Arkitektur- og implementeringsplan

Versjon: 0.5
Dato: 2026-07-10
Status: Infrastruktur fullført (Fase 0–4B) · AI-integrasjon fullført (Fase 5) · Lokal E2E verifisert (Fase 6) · Produksjonsrunbook fullført (Fase 7, aktivering utsatt) · Article-first UI/fullført start (Fase 8D, commits 94f6541 og 94f5721) · Backend artikkelgenerering teknisk implementert (Fase 8C, commits 956206d, 5029cb0 og 4ea8fb6) · Fase 8E-10–8E-20 fullført (commit dd071f6) · Fase 8F-0–8F-5 fullført (forvaltnings- og kontrollflate) · 8G-1–8G-7 fullført · **8H-kjerne delfase 1 + delfase 2 fullført (kildemonitoring, intelligent retry, dyp reparasjon) — 8H-utvidelse gjenstår**

> **Arkitekturkorrigering (v0.2):** Enterprise Wiki skal være et fullstendig parallelt system uten avhengighet av Kunnskapsbase eller RAG-pipeline. Dagens `KnowledgeItemVersion`-baserte ingest er midlertidig bootstrap/import og regnes **ikke** som permanent primærflyt. Se §3, §7 og Fase 4A for korrekt langsiktig arkitektur.
>
> **Målbildekorrigering (v0.3):** Enterprise Wiki skal ikke være en claim-liste. Den skal være en Markdown-first LLM Wiki: raw sources inn, lesbare wikiartikler ut. Claims, excerpts, source references, lint og helsekontroll er verifikasjonsgrunnmur rundt artikkelen — ikke selve sluttproduktet.
>
> **Karpathy-korrigering (v0.5):** Enterprise Wiki skal ligge tettest mulig på Karpathy Wiki-modellen: `raw source → compile/maintainer → article per source + summary per source + dedupliserte concept/entity pages → index/log/backlinks/lint`. Tidligere v0.4-formuleringer som antydet at `article per source` i seg selv er feil, er korrigert. Feilen er ikke at en kilde får en artikkel; feilen er å stoppe der. Én kilde skal normalt få source article og source summary, og samtidig kunne oppdatere flere felles concept/entity-sider. Filnavn er fortsatt kildemetadata, ikke automatisk wiki-identitet.

## 1. Formål

Enterprise LLM Wiki er et parallelt kunnskapssystem for Procynia, inspirert av Karpathy Wiki-mønsteret og tilpasset enterprise-krav: kundescoping, rollestyrt godkjenning, sporbar revisjon, kildebevis og trygg menneskelig review.

Det sentrale produktet er **en kompilert, vedlikeholdt Markdown-wiki**, ikke dokumentoppsummeringer alene og ikke claim-lister.

Systemet skal fungere som et kompilert og vedlikeholdt kunnskapslag:

```text
raw source documents
→ immutable source store
→ schema-guided compile/maintainer workflow
→ source article page per source
→ source summary page per source
→ shared concept/entity pages deduped across sources
→ maintained index, log, backlinks and lint
→ claims/source references as verification layer
→ human review and approval
→ approved wiki pages become future AI context
```

Systemet er ikke en erstatning for dagens Kunnskapsbase eller RAG-pipeline. Det er et separat wiki-lag som:

- tar inn Enterprise Wiki-egne kilder uten å endre Kunnskapsbase/RAG
- beholder råkilder som originalt kildegrunnlag
- bruker AI som wiki-compiler/forvalter, ikke bare som enkel artikkelgenerator
- lager én source article og én kort source summary per kilde
- oppretter og oppdaterer concept/entity-sider på tvers av kilder
- finner relevante eksisterende wiki-sider før nye concept/entity-sider opprettes
- lagrer lesbart Markdown-innhold i `enterprise_wiki_page_versions.content_markdown`
- bruker claims, excerpts, source references og lint som verifikasjonslag rundt wikiinnholdet
- lar mennesker lese, vurdere, redigere, godkjenne eller avvise wikiinnhold
- først i senere faser kan sammenlignes mot dagens RAG — uten automatisk kobling

Kjerneprinsipper:

- **Karpathy-layout først.** `article`, `summary`, `concept`, `index`, `log` og `backlinks` er egne logiske wiki-lag.
- **Article per source er riktig.** En råkilde skal normalt få en full source writeup.
- **Summary per source er riktig.** En råkilde skal normalt få et kort TL;DR-sammendrag.
- **Concept/entity-sider er compounding-laget.** Disse dedupliserer og kobler kunnskap på tvers av kilder.
- **Index før ny concept/entity-side.** Systemet skal undersøke eksisterende wiki-sider før det foreslår nye felles sider.
- **Én kilde kan påvirke mange sider.** En opplasting kan opprette/oppdatere source article, source summary og flere concept/entity-sider.
- **Råkilder er immutable.** Kilder leses og spores, men endres ikke av AI.
- **Verifikasjon som støtte.** Claims, excerpts, kildekoblinger og lint skal støtte review — ikke være hovedopplevelsen.
- AI foreslår. Mennesker godkjenner.
- Ingen sentrale påstander uten kildegrunnlag.
- Ingen skrivetilgang til eksisterende Kunnskapsbase/RAG-modeller eller tabeller.
- Kundeisolasjon fra dag én.

Konsekvens av v0.5-korrigeringen:

- Eksisterende claim extraction beholdes som grounding/review-lag.
- Eksisterende lint/helsekontroll beholdes.
- Eksisterende upload/extract/ingest-grunnmur beholdes.
- `WikiArticleAiClient` beholdes som teknisk writer/valideringspunkt for `article`-sider.
- Dagens `source → one page → article`-flyt er for smal, men `article per source` er fortsatt riktig som ett av flere output-lag.
- Neste hovedspor er **Karpathy-alignment: page types, schema, index, log, backlinks og compile decision**.

## 2. Ikke-rør-grenser

Følgende eksisterende komponenter skal ikke endres, berøres eller avhenge av dette systemet i noen fase:

| Komponent | Begrunnelse |
|---|---|
| `KnowledgeItem` og alle relasjoner | Produksjonsmodell, ikke i scope |
| `KnowledgeItemVersion` | Leses kun read-only |
| `KnowledgeItemChunk` | Leses ikke i pilot |
| Eksisterende embedding-kolonner | Ingen ny indeksering av disse |
| `MetadataCandidateRetrievalService` og retrieval-pipeline | Ingen endring av RAG |
| `SavedNoticeAiEvidence` | Ingen kobling fra wiki |
| Dagens kravsvar og AI workspace | Ingen wiki-output inn i svarflyt |
| `SavedNoticeAiDocument`-flyt | Leses ikke i pilot |
| `/app/billing` | Uten scope |
| Filament admin-ressurser | Uten scope |
| Eksisterende migrasjoner | Kun nye migrasjoner for `enterprise_wiki_*` |

Alle nye tabeller bruker prefikset `enterprise_wiki_` for å markere tydelig separasjon.

---

## 3. Kildearkitektur

### 3.1 Midlertidig bootstrap-kilde (Fase 1 — skal ikke være permanent primærflyt)

**Bootstrap-kilde:** `KnowledgeItemVersion.extracted_text`

Vilkår for lesing:

- `approval_status = 'approved'`
- `is_current = true`
- Kun for kunder med `ai_usage_enabled = true` på det tilknyttede `KnowledgeItem`

Ingen re-ekstraksjon av dokumenttekst i pilot. `extracted_text` er allerede prosessert og godkjent av mennesker — dette er den enkleste inngangen for å verifisere at Wiki-pipeline fungerer.

> **Viktig begrensning:** Denne tilnærmingen bruker Kunnskapsbase som datakilde og skaper dermed en implisitt kobling mellom de to systemene. Det er **ikke** ønsket langsiktig arkitektur. Så snart Fase 4A er implementert, skal `KnowledgeItemVersion`-ingest degraderes til en eksplisitt legacy/import-kommando og ikke eksponeres som primærflyt i UI.

### 3.2 Ønsket primærflyt (Fase 4A + v0.5 — Enterprise Wiki egne kilder og Karpathy compile)

Enterprise Wiki skal ha sin **egen** upload/import-flyt, fullstendig uavhengig av `KnowledgeItem`, `KnowledgeItemVersion` og `KnowledgeItemChunk`.

Korrigert målarkitektur etter v0.5:

```text
Enterprise Wiki source upload/import
→ Enterprise Wiki extraction
→ immutable raw source
→ schema-guided compile decision
→ create/update source article page
→ create/update source summary page
→ create/update relevant shared concept/entity pages
→ write new page versions with content_markdown
→ preserve claims/source references/lint as verification layer
→ update index/log/backlinks/health state
→ human review and approval
```

Primærkilder i Fase 4A:

- `EnterpriseWikiDocument` — egen modell, ingen FK mot Kunnskapsbase
- `source_type = 'enterprise_wiki_document'` på `enterprise_wiki_source_references`
- `source_type = 'enterprise_wiki_document'` på `enterprise_wiki_ingest_runs`

Viktig skille:

- **Råkilden** er originaldokumentet og beholdes som kildegrunnlag.
- **Source article** er full writeup for én råkilde.
- **Source summary** er kort TL;DR for én råkilde.
- **Concept/entity-side** er delt tematisk kunnskap på tvers av kilder.
- **Index** er systemvedlikeholdt katalog over article/summary/concept/entity-sider.
- **Log** er append-only operasjonslogg.
- **Backlinks** er systemgenerert lenkegraf.
- **Artikkelinnhold** lagres som `content_markdown` på `enterprise_wiki_page_versions`.
- **Claims** er verifikasjonsenheter som støtter artikkel, reviewer og lint.
- **Source references** dokumenterer hvor claims og sentrale artikkelutsagn kommer fra.
- **Lint/helsekontroll** hjelper reviewer å finne mangler før godkjenning.

Forbud i målmodellen:

- Filnavn skal ikke automatisk bli wiki-tittel eller slug.
- Én kilde skal ikke bare bli én isolert wiki-side.
- Source article skal ikke erstatte concept/entity-laget.
- Claims/excerpts skal ikke dumpes inn som artikkeltekst.
- Review/approval og produksjonsaktivering skal ikke bygges videre før Karpathy compile-modellen er avklart.

Mulige tilleggskilder i senere faser (egne vurderinger — krever ikke Kunnskapsbase):

- `SavedNoticeAiDocument.extracted_text` (saksdokumenter fra AI-workspace, kun hvis separat plan åpner for det)
- `Notice`-data og CPV-mønstre fra Doffin
- Ekstern URL-import eller manuell tekstinput

### 3.3 Kunnskapsbase som eventuell legacy-importkilde

`KnowledgeItemVersion`-ingest beholdes etter Fase 4A, men **kun** som en eksplisitt Artisan-kommando for migrering og import av eksisterende Kunnskapsbase-innhold inn i Wiki. Det skal ikke være en flyt brukere kan starte fra UI uten tydelig "importer fra Kunnskapsbase"-merking.

Kunnskapsbase og Enterprise Wiki er separate systemer. Ingen automatisk synkronisering.

---

## 4. Datamodell

Alle tabeller er nye og bruker prefikset `enterprise_wiki_`. De har ingen fremmednøkler inn i eksisterende Kunnskapsbase-tabeller, kun lesbare referanser som lagres som `source_type` + `source_id`-par.

---

### 4.1 `enterprise_wiki_pages`

Representerer én wiki-side, scoped til én kunde. Etter v0.5 må siden ha en eksplisitt eller logisk `page_type` som speiler Karpathy-layouten.

Første ønskede page types:

| `page_type` | Karpathy-lag | Beskrivelse |
|---|---|---|
| `article` | `wiki/articles/<slug>.md` | Full source writeup for én `EnterpriseWikiDocument` |
| `summary` | `wiki/summaries/<slug>.md` | Kort TL;DR for én `EnterpriseWikiDocument`, maks ca. 200 ord |
| `concept` | `wiki/concepts/<concept>.md` | Deduplisert tematisk side på tvers av kilder |
| `entity` | konseptvariant | Kunde, leverandør, person, produkt, system eller annen navngitt entitet |
| `index` | `wiki/index.md` | Systemvedlikeholdt innholdskatalog |
| `backlinks` | `wiki/backlinks.md` | Systemgenerert lenkeoversikt, eventuelt ikke synlig som normal side |

Felt:

| Felt | Type | Beskrivelse |
|---|---|---|
| `id` | bigint PK | |
| `customer_id` | bigint FK → customers | Kundeisolasjon |
| `department_id` | bigint FK nullable | Avdelingsscoping (valgfritt i pilot) |
| `page_type` | enum nullable i overgang | `article`, `summary`, `concept`, `entity`, `index`, `backlinks` |
| `slug` | varchar | URL-vennlig identifikator, unik per kunde og helst per page type |
| `title` | varchar | Sidetittel. Må ikke blindt kopieres fra filnavn |
| `scope` | enum | `company`, `domain`, `project` |
| `status` | enum | Se §6 |
| `owner_user_id` | bigint FK nullable | Ansvarlig redaktør |
| `generated_by` | enum | `ai_job`, `manual` |
| `last_source_hash` | varchar nullable | SHA-256 av kildedokumenter brukt — oppdager utdatert innhold |
| `reviewed_at` | timestamp nullable | |
| `reviewed_by_user_id` | bigint FK nullable | |
| `archived_at` | timestamp nullable | |
| `created_at`, `updated_at` | timestamps | |

Midlertidig før migrasjon: `page_type` kan defineres i schema/kontrakt og utledes fra slug/status/koblinger. Første kodefase bør audite om eksisterende tabell kan bære dette uten DB-endring.

---

### 4.2 `enterprise_wiki_page_versions`

Immutable versjonering. Ny versjon opprettes alltid — eksisterende versjoner overskrives aldri. Dette er den autoritative lagringen av selve wikiinnholdet for alle page types.

| Felt | Type | Beskrivelse |
|---|---|---|
| `id` | bigint PK | |
| `enterprise_wiki_page_id` | bigint FK | |
| `version_number` | integer | Monotont stigende per side |
| `is_current` | boolean | Kun én current per side |
| `content_markdown` | text | Full Markdown for siden. For `article`: full writeup. For `summary`: kort TL;DR. For `concept/entity`: deduplisert tematisk kunnskap |
| `generated_by_model` | varchar | Modellidentifikator, f.eks. `gpt-5` |
| `generation_prompt_hash` | varchar | For reproduserbarhet |
| `created_at` | timestamp | |

---

### 4.3 `enterprise_wiki_claims`

Én konkret, verifiserbar påstand per rad. En side kan ha mange påstander. Etter v0.3 er påstander **ikke** sluttproduktet; de er verifikasjonsenheter som støtter wikiartikkelen, reviewer og lint.

| Felt | Type | Beskrivelse |
|---|---|---|
| `id` | bigint PK | |
| `enterprise_wiki_page_id` | bigint FK | |
| `enterprise_wiki_page_version_id` | bigint FK | Hvilken versjon påstanden tilhører |
| `claim_text` | text | Én setning, én påstand |
| `position_order` | integer | Rekkefølge på siden |
| `confidence` | enum | `high`, `medium`, `low`, `uncertain` |
| `conflict_flag` | boolean | Konflikt oppdaget mot annen kilde |
| `approval_status` | enum | `pending`, `approved`, `rejected` |
| `approved_by_user_id` | bigint FK nullable | |
| `approved_at` | timestamp nullable | |
| `created_at` | timestamp | |

---

### 4.4 `enterprise_wiki_source_references`

Kildebevis på påstandsnivå. Ingen påstand kan eksistere uten minst én kildereferanse.

| Felt | Type | Beskrivelse |
|---|---|---|
| `id` | bigint PK | |
| `enterprise_wiki_claim_id` | bigint FK | |
| `source_type` | enum | `enterprise_wiki_document`, `knowledge_item_version`, `saved_notice_document`, `doffin_notice`, `manual` |
| `source_id` | bigint | Polymorf referanse-ID |
| `source_label` | varchar | Menneskelesbart navn (filnavn, tittel, kunngjøringsnummer) |
| `source_hash` | varchar | SHA-256 av kildeinnholdet på ingest-tidspunktet |
| `excerpt` | text | Relevant tekstutdrag fra kilden |
| `page_reference` | varchar nullable | Sidenummer eller avsnitt |
| `created_at` | timestamp | |

Constraint: Minst én rad per `enterprise_wiki_claim_id`. Håndheves i applikasjonslaget ved ingest.

---

### 4.5 `enterprise_wiki_ingest_runs`

Sporing av alle AI-genereringsjobber. Ingen produksjonsdata uten sporbar run.

| Felt | Type | Beskrivelse |
|---|---|---|
| `id` | bigint PK | |
| `uuid` | uuid | Ekstern identifikator for jobber |
| `customer_id` | bigint FK | |
| `enterprise_wiki_page_id` | bigint FK nullable | Legacy/enkel kobling til én side. For Karpathy-modellen er dette for smalt og må suppleres av run→pages mapping |
| `trigger_type` | enum | `manual`, `schedule`, `source_change` |
| `source_type` | enum | `enterprise_wiki_document`, `knowledge_item_version`, osv. |
| `source_id` | bigint | Hvilken kilde som ble brukt |
| `status` | enum | `queued`, `running`, `sections_planned`, `completed`, `failed` |
| `model_used` | varchar | |
| `input_tokens` | integer nullable | |
| `output_tokens` | integer nullable | |
| `cost_estimate_nok` | decimal nullable | |
| `error_message` | text nullable | |
| `started_at`, `finished_at` | timestamps nullable | |
| `created_at` | timestamp | |

v0.5-merknad: Karpathy-modellen krever at én run kan berøre flere sider (`article`, `summary`, `concept`, `entity`). Eksisterende `enterprise_wiki_page_id` kan beholdes for historikk, men må ikke være eneste sporingsmekanisme i endelig compile-flyt.

---

### 4.6 `enterprise_wiki_ingest_sections`

Intern arbeidstabell for per-seksjon arbeidssporing under ingest. Opprettes av orchestratoren og brukes til å styre parallelle seksjonsjobber og aggregere resultat i finalize. Inneholder ikke godkjent wiki-output.

| Felt | Type | Beskrivelse |
|---|---|---|
| `id` | bigint PK | |
| `enterprise_wiki_ingest_run_id` | bigint FK | |
| `section_index` | integer | Indeks i seksjonslisten (0-basert) |
| `heading` | varchar nullable | H2-overskrift dersom tilgjengelig |
| `status` | enum | `pending`, `running`, `completed`, `failed` |
| `error_message` | text nullable | Feilmelding ved mislykket seksjon |
| `created_at`, `updated_at` | timestamps | |

*Fase 1-tillegg:* Ikke beskrevet i original plan/ADR. Lagt til under implementering for å støtte parallell seksjonsbehandling og trygg finalize-logikk med `lockForUpdate`.

---

### 4.7 `enterprise_wiki_lint_findings`

Resultater fra helsekontroll. Se §9.

| Felt | Type | Beskrivelse |
|---|---|---|
| `id` | bigint PK | |
| `enterprise_wiki_page_id` | bigint FK | |
| `enterprise_wiki_claim_id` | bigint FK nullable | Kan peke på spesifikk påstand |
| `check_type` | enum | Se §9 for verdier |
| `severity` | enum | `info`, `warning`, `error` |
| `detail_text` | text | Forklaring av funnet |
| `resolved_at` | timestamp nullable | |
| `created_at` | timestamp | |

---

### 4.8 `enterprise_wiki_page_topics`

Temaklassifisering generert av AI. Brukes til filtrering og gruppering.

| Felt | Type | Beskrivelse |
|---|---|---|
| `id` | bigint PK | |
| `enterprise_wiki_page_id` | bigint FK | |
| `topic_label` | varchar | F.eks. «HMS», «Referanser», «Sikkerhet» |
| `confidence` | float | AI-konfidens for koblingen |
| `created_at` | timestamp | |


### 4.9 Karpathy-lag over eksisterende datamodell (ingen DB-endring før audit)

v0.5 innfører en logisk modell som ligger tettest mulig på Karpathy Wiki. Første steg er å spesifisere og audite dette mot eksisterende tabeller før nye migrasjoner vurderes.

| Karpathy-lag | Procynia-komponent nå | Status |
|---|---|---|
| `raw/` | `EnterpriseWikiDocument` | Finnes |
| `wiki/articles/*.md` | `EnterpriseWikiPage` + `EnterpriseWikiPageVersion.content_markdown` med `page_type=article` | Mangler eksplisitt page type / source mapping |
| `wiki/summaries/*.md` | `EnterpriseWikiPage` + `EnterpriseWikiPageVersion.content_markdown` med `page_type=summary` | Mangler |
| `wiki/concepts/*.md` | `EnterpriseWikiPage` + `EnterpriseWikiPageVersion.content_markdown` med `page_type=concept` | Mangler |
| `wiki/entities/*.md` | `EnterpriseWikiPage` + `EnterpriseWikiPageVersion.content_markdown` med `page_type=entity` | Ikke i Karpathy-repoets konkrete SCHEMA, men vanlig Obsidian/LLM Wiki-tilpasning |
| `wiki/index.md` | `EnterpriseWikiPage(page_type=index)` eller dynamisk index service | Mangler eksplisitt kontrakt |
| `wiki/log.md` | `EnterpriseWikiIngestRun` + eventuelt ny append-only operation log | Finnes delvis |
| `wiki/backlinks.md` | Systemgenerert lenkegraf/lint-output | Mangler |
| Verification | `EnterpriseWikiClaim` + `EnterpriseWikiSourceReference` | Finnes |
| Lint/health | `EnterpriseWikiLintFinding` + `wiki:lint` | Finnes, men må utvides med broken links/orphans/stubs/backlinks |
| Schema/instructions | Ingen egen klasse/kontrakt ennå | Mangler |
| Compile decision | Hvilke article/summary/concept/entity-sider skal opprettes/oppdateres | Mangler |
| Multi-page source impact | Én kilde kan endre flere sider | Mangler i flyt |

Fase 8E skal først forsøke å utnytte eksisterende tabeller. Ny databaseendring skal bare foreslås hvis audit viser at eksisterende `enterprise_wiki_*`-modeller ikke kan bære Karpathy-modellen.

---

## 5. Enterprise-krav

### 5.1 Kundeisolasjon

Alle `enterprise_wiki_*`-tabeller inneholder `customer_id`. Alle spørringer scopes til autentisert kunde via `CustomerContext`. Ingen krysskundedata er mulig i applikasjonslaget.

### 5.2 Avdelingsscoping

`enterprise_wiki_pages.department_id` er nullable. I pilot kan alle wiki-sider være selskapsbrede (`department_id = null`). Avdelingsscoping aktiveres i fase 2 dersom behov bekreftes (se §11 for åpent spørsmål).

### 5.3 Rollestyring

| Handling | Rolle |
|---|---|
| Se godkjente wiki-sider | Alle autentiserte brukere i kunden |
| Se draft/pending_review | System Owner, Bid Manager |
| Godkjenne side | System Owner (fase 1), vurdere Bid Manager i fase 3 |
| Godkjenne enkeltpåstand | Avgjøres i fase 3 (se §11) |
| Starte manuell ingest | System Owner |
| Arkivere side | System Owner |

### 5.4 Revisjonsspor

- Ny versjon (`enterprise_wiki_page_versions`) opprettes ved hver generering
- Eksisterende versjoner overskrives aldri
- `enterprise_wiki_ingest_runs` logger modell, tokens og kilde for alle AI-kjøringer
- `enterprise_wiki_claims.approved_by_user_id` + `approved_at` registrerer hvem som godkjente

---

## UX-prinsipper for trygg wiki og verifikasjon

Enterprise Wiki må ikke presentere AI-generert innhold som ferdig sannhet før det er kvalitetssikret.

UI-et skal ha to tydelige lag:

1. **Wikiinnhold-laget** — primær visning: lesbar Markdown-side med riktig page type (`article`, `summary`, `concept`, `entity` eller `index`).
2. **Verifikasjonslaget** — sekundær visning: claims, excerpts, kildekoblinger, kvalitetssignaler, konflikter og lint.

Bindende UX-prinsipper:

- `/app/wiki/{slug}` skal primært se ut som en wikiartikkel, ikke som en rå claim-tabell.
- Brukeren skal alltid se status: `draft`, `pending_review`, `approved` eller `rejected`.
- Pending innhold skal tydelig merkes som utkast.
- Godkjenning skal ikke være en «blind» knapp.
- Brukeren skal kunne åpne verifikasjonsgrunnlaget for artikkelen.
- Påstander skal kunne spores tilbake til raw kildedokument.
- Kildegrunnlag skal være synlig nok til at bruker kan kontrollere kvalitet.
- Svake, manglende eller uklare kilder skal vises tydelig.
- Det skal være vanskelig å godkjenne innhold ved et uhell.
- Bid Manager kan lese og vurdere, men System Owner godkjenner i piloten.

Claims, source references og lint skal altså være **review-støtte**, ikke hovedproduktet.

Ny funksjonalitet som gjør claim-listen til primær wiki-opplevelse er ikke i tråd med v0.3-målbildet.

## 6. Statusmodell

```
draft
  → pending_review   (bruker sender til gjennomgang)
  → archived         (bruker forkaster uten review)

pending_review
  → approved         (godkjent av System Owner)
  → rejected         (avvist — siden beholdes men markeres avvist)
  → draft            (sendt tilbake for revidering)

approved
  → superseded       (erstattet av ny versjon, gammel beholdes i historikk)
  → archived         (manuelt arkivert)

rejected
  → draft            (tatt opp igjen for redigering)
  → archived

archived
  (terminal, ingen overganger — med mindre ny ingest gjenoppretter som draft)
```

Ny AI-generering mot en eksisterende `approved`-side oppretter alltid en ny `enterprise_wiki_page_versions`-rad med status `draft`. Den gamle `approved`-versjonen forblir aktiv og synlig inntil ny versjon godkjennes og den gamle flyttes til `superseded`.

---

## 7. Wiki-ingest-flyt

### 7.0 Status etter v0.5-korrigering

Eksisterende flyt har bygget en god teknisk grunnmur:

- egne Enterprise Wiki-kilder
- tekstekstraksjon
- seksjonsjobber
- claim extraction
- source references
- lint/helsekontroll
- `content_markdown`
- article-first UI
- OpenAI-klienter bak config-flagg

Men dagens hovedmodell er fortsatt for smal. Den kan ende i:

```text
source document → one wiki page → one generated article
```

Det er ikke nok i Karpathy-modellen.

Korrigert mål:

```text
source document
→ compile decision
→ source article + source summary
→ update/create shared concept/entity pages
→ update index/log/backlinks/lint
```

Konsekvenser:

- `ProcessEnterpriseWikiSection` kan fortsatt ekstrahere claims.
- Claims skal fortsatt lagres og brukes til review/lint.
- `WikiArticleAiClient` kan brukes som writer for `article`-sider.
- Det manglende laget er **Karpathy compile workflow**: schema, page types, index, log, backlinks og beslutning om hvilke sider som skal oppdateres/opprettes.
- `/app/wiki/{slug}` skal vise wikiinnhold som hovedinnhold og claims som sekundær verifikasjon.

### 7.1 Flyt A: Bootstrap/import via Kunnskapsbase (midlertidig — Fase 1)

> **Status:** Implementert og fungerende. Regnes som midlertidig bootstrap — skal ikke promoteres til permanent primærflyt i UI. Se §3.1 for begrensningsbeskrivelse.
>
> **v0.5-korrigering:** Flyt A kan beholdes som legacy/import, men skal ikke definere målbildet for wikiopplevelsen.

```
1. Bruker velger en godkjent KnowledgeItemVersion
   └─ Filtert: approval_status = 'approved' AND is_current = true
   └─ Kunden må ha ai_usage_enabled = true

2. Manuell trigger: Artisan-kommando (php artisan wiki:ingest --customer=X --version-id=Y)
   └─ Oppretter enterprise_wiki_ingest_runs med status = queued

3. Orchestrator-jobb startes (ProcessEnterpriseWikiIngest)
   └─ Leser extracted_text fra KnowledgeItemVersion
   └─ Deler tekst i seksjoner
   └─ Dagens implementering kan opprette draft-side tidlig
   └─ Dette er akseptabelt for legacy/import, men ikke målmodell

4. Per-seksjon-jobb
   └─ Ekstraherer claims og source references
   └─ Lagrer verifikasjonsgrunnlag

5. Finalisering
   └─ Teknisk article generation finnes etter Fase 8C
   └─ Men endelig mål krever maintainer decision før sideidentitet bestemmes

6. Ingen wiki-output kobles til eksisterende RAG, kravsvar eller AI workspace
```

### 7.2 Ønsket primærflyt: Enterprise Wiki egne kilder + Karpathy compile

```
1. Bruker laster opp dokument direkte i Enterprise Wiki
   └─ Egen upload-route: POST /app/wiki/sources
   └─ Oppretter EnterpriseWikiDocument
   └─ Lagrer fil, filnavn, SHA-256, customer_id

2. Tekstekstraksjon
   └─ Resultatet lagres på EnterpriseWikiDocument
   └─ Ingen skriving til Kunnskapsbase

3. Ingest/compile startes fra UI
   └─ Oppretter enterprise_wiki_ingest_runs
       (source_type = 'enterprise_wiki_document', source_id = EnterpriseWikiDocument.id)

4. Maintainer schema lastes
   └─ Regler for page types, front matter/logiske metadata, navngiving, lenking, index, log, compile og lint
   └─ Filnavn kan brukes som kildeetikett, men ikke blindt som tittel/slug

5. Index lookup
   └─ Systemet henter eksisterende pages, titles, slugs, page_type, topics, status og korte sammendrag
   └─ AI vurderer hvilke concept/entity-sider kilden berører

6. Compile decision
   └─ Returnerer strukturert beslutning:
       - source_article: create/update
       - source_summary: create/update
       - update_existing_pages[]
       - create_new_pages[]
       - no_action_reason hvis kilden ikke gir nyttig wikiinnhold
       - warnings/conflicts

7. Verification extraction
   └─ Ekstraherer claims, excerpts og conflict_note
   └─ Lagrer enterprise_wiki_claims og enterprise_wiki_source_references

8. Page writing/update
   └─ Oppretter/oppdaterer article-side for kilden
   └─ Oppretter/oppdaterer summary-side for kilden
   └─ Oppretter/oppdaterer relevante concept/entity-sider
   └─ For hver berørt side opprettes ny EnterpriseWikiPageVersion
   └─ content_markdown skrives som lesbar Markdown
   └─ Claims/source references knyttes til riktig sideversjon der de brukes som verifikasjon

9. Log/index/backlinks/lint
   └─ Run-logg dokumenterer hvilke sider kilden endret eller foreslo
   └─ Index/sideoversikt oppdateres
   └─ Backlinks/lenkegraf bygges eller oppdateres
   └─ Lint sjekker manglende kilder, konflikter, stale claims, orphan pages, broken links, stub pages og missing cross-references

10. Review
   └─ Brukeren leser article/summary/concept/entity-sider
   └─ Brukeren kontrollerer claims/kilder/lint
   └─ System Owner godkjenner eller avviser
```

**Viktig prinsipp etter v0.5:** AI kan returnere strukturert JSON som inneholder compile-beslutning, Markdown-innhold og verifikasjonsgrunnlag. `content_markdown` er fortsatt hovedinnholdet på en wiki-side, men output må deles i Karpathy-lag: `article`, `summary`, `concept/entity`, `index`, `log` og `backlinks`.

## 8. Queue-mønster

Speiler eksisterende Procynia-mønster fra `ProcessRequirementExtractionRun`:

```
ProcessEnterpriseWikiIngest          (orchestrator)
  └─ Dispatcher
       ├─ ProcessEnterpriseWikiSection (én per H2-seksjon eller tegngrense)
       ├─ ProcessEnterpriseWikiSection
       └─ ProcessEnterpriseWikiSection
            └─ FinalizeEnterpriseWikiIngest (etter alle seksjoner)
```

| Jobbnavn | Køy | Formål |
|---|---|---|
| `ProcessEnterpriseWikiIngest` | `enterprise-wiki` | Orchestrator: leser kilde, splitter, dispatcherer seksjonsjobber |
| `ProcessEnterpriseWikiSection` | `enterprise-wiki` | Sender én seksjon til AI, lagrer claims og kilder |
| `FinalizeEnterpriseWikiIngest` | `enterprise-wiki` | Slår sammen resultater. Midlertidig én side; skal senere koordinere article/summary/concept/entity-output og ingest-run |

Ny queue: `enterprise-wiki`. Holdes separat fra `ai-requirements` for å unngå ressurskonflikter med produksjonsflyt.

---

## 9. Lint og helsekontroll

Lint skal etter v0.5 speile Karpathy-linteren bedre: først deterministisk strukturscan, deretter eventuelt LLM-basert semantisk vurdering.

Planlagte lint-kontroller som kan kjøres on-demand eller som scheduled job:

| Check-type | Alvorlighet | Beskrivelse |
|---|---|---|
| `claim_without_source` | error | Claim-rad mangler minst én source_reference |
| `source_reference_missing_excerpt` | warning | Kildereferanse finnes, men mangler excerpt |
| `document_ingest_failed` | warning | Kildedokument feilet ingest/extract |
| `broken_wiki_link` | error | `[[wikilink]]` peker til side som ikke finnes |
| `orphan_page` | info | Wiki-side uten inbound links |
| `stub_page` | info | Side er for kort/tynn til å være nyttig |
| `missing_backlinks` | info | Backlinks/lenkegraf er ikke oppdatert |
| `conflicting_claims` | warning | To claims eller sider motsier hverandre |
| `source_version_superseded` | warning | Kildeversjon har `approval_status = superseded` |
| `stale_approved_page` | warning | Godkjent side, men kildens `file_hash_sha256` er endret siden `last_source_hash` ble beregnet |
| `low_confidence_page` | info | Mer enn halvparten av claims har `confidence = low` eller `uncertain` |
| `missing_owner` | info | `owner_user_id` er null |
| `overdue_review` | warning | Godkjent side uten review de siste N dager (konfigurerbart) |
| `missing_topics` | info | Siden har ingen `enterprise_wiki_page_topics`-rader |

Lint-resultater lagres i `enterprise_wiki_lint_findings`. UI viser helseindikator per side.

## 10. Første pilotfase og rebaselining

### 10.1 Opprinnelig pilotfase (gjennomført som teknisk grunnmur)

| Element | Innhold |
|---|---|
| **Ny sidefamilie** | `enterprise_wiki_pages`, `enterprise_wiki_page_versions`, `enterprise_wiki_claims`, `enterprise_wiki_source_references`, `enterprise_wiki_ingest_runs` |
| **Kilde** | `KnowledgeItemVersion.extracted_text` i bootstrap, senere `EnterpriseWikiDocument` som primærkilde |
| **Trigger** | Artisan-kommando i bootstrap, senere UI-ingest fra Enterprise Wiki-kilde |
| **Output** | `enterprise_wiki_pages` med status `pending_review`, claims og kildehenvisninger |
| **UI** | `/app/wiki` med kilder, sider, claims, kildelenker og helsekontroll |
| **Godkjenning** | System Owner kan sette `approved` / `rejected` |
| **RAG-kobling** | Ingen — wiki-output brukes ikke i dagens AI-svarflyt |

Dette ga en nødvendig teknisk grunnmur.

### 10.2 Korrigert pilotmål etter v0.5

Det opprinnelige pilotmålet var for smalt. Å vise claims med kildelenker er ikke nok. Å generere én artikkel fra én kilde er nyttig, men bare ett lag i Karpathy-modellen.

Korrigert pilotmål:

| Element | Korrigert mål |
|---|---|
| **Primær output** | Karpathy-kompatibel wiki med article, summary og concept/entity-sider |
| **Source behavior** | Én kilde får source article + source summary og kan oppdatere/opprette flere concept/entity-sider |
| **Index** | Eksisterende wiki-sider vurderes før nye concept/entity-sider opprettes |
| **Schema** | Eget maintainer-regelverk styrer page types, navngiving, lenking, ingest/compile, query og lint |
| **Backlinks** | Lenkegraf bygges/vedlikeholdes systematisk |
| **Sekundær output** | Claims, excerpts, kildereferanser og lint |
| **Primær UI** | Wiki-side som ligner en lesbar wiki, med riktig page type |
| **Review UI** | Verifikasjonslag rundt artikkel/concept/summary |
| **Godkjenning** | Godkjenning av wikiinnhold, støttet av claim-/kildekontroll |
| **Produksjonsaktivering** | Først etter at Karpathy compile-flyten fungerer |

Mål med neste pilot: verifisere at Enterprise Wiki kan bruke en opplastet kilde til å produsere source article, source summary, relevante concept/entity-oppdateringer, index/log/backlinks og sporbar verifikasjon.

## 11. Åpne beslutninger

Disse spørsmålene må avklares før videre kode utover audit/plankorrigering:

1. **Schema/instruction contract** — Skal schemaet implementeres som PHP prompt-/kontraktklasse, markdown-fil i repo, config, databasekonfigurasjon eller en kombinasjon?

2. **Page types** — Skal `page_type` legges fysisk på `enterprise_wiki_pages` nå, eller først defineres i schema og simuleres i kode? Minimum ønsket modell: `article`, `summary`, `concept`, `entity`, `index`, `backlinks`.

3. **Source article mapping** — Hvordan kobles én `EnterpriseWikiDocument` stabilt til sin `article`-side uten at filnavn blir wiki-identitet?

4. **Source summary mapping** — Skal `summary` være egen page type, egen tabell eller metadatafelt på source article? Karpathy peker mot egen summary-side.

5. **Concept/entity-sider** — Skal `entity` være egen page type eller behandles som `concept` med tag/topic?

6. **Index-format** — Skal første index være dynamisk bygget fra `enterprise_wiki_pages`, `enterprise_wiki_page_topics`, status og korte sammendrag, eller skal det finnes en eksplisitt index-side?

7. **Logg over operasjoner** — Holder `enterprise_wiki_ingest_runs` alene, eller trengs en append-only operation log som speiler `wiki/log.md`?

8. **Backlinks** — Skal backlinks lagres som egen tabell, genereres fra `[[wikilinks]]` ved lint, eller vises som lint-/index-output?

9. **Compile decision JSON** — Hvilket strict JSON-format skal styre `source_article`, `source_summary`, `update_existing_pages[]`, `create_new_pages[]`, warnings og no-op?

10. **Multi-page ingest** — Hvordan skal én `EnterpriseWikiDocument` kunne påvirke flere `EnterpriseWikiPage`-rader uten stor refaktor?

11. **Update existing vs create new** — Hvilke regler skal hindre duplikatsider og kildefilnavn som artikkeltittel?

12. **Review boundary** — Skal reviewer godkjenne hele siden, per sideversjon, per kildeendring eller per claim?

13. **Navn i UI** — «Enterprise Wiki», «Kunnskapswiki» eller annet? Norsk eller engelsk i UI-strenger?

14. **Godkjenning: Hvem?** — Kun System Owner i pilot, eller også Bid Manager?

15. **Avdelingsscoping fra start?** — Skal `department_id` brukes i pilot, eller kun selskapsbred wiki?

16. **RAG-sammenligning** — Når og hvordan skal wiki-svar sammenlignes mot dagens RAG? Feature-flag, separat side, eller parallell visning i AI workspace?

17. **Kostnadsstyring** — Maksimalt antall tokens per ingest-run? Maksimalt antall sider per dag per kunde? Skal cost_estimate_nok vises i UI?

18. **Kildeutdrag-lengde** — Hvor langt bør `excerpt` maksimalt være? (Forslag: 500 tegn)

19. **Automatisk re-ingest** — Skal wiki-sider automatisk trigge ny generering når kildeversjon oppdateres, eller kun manuelt i pilot?

20. **Konflikthåndtering** — Når `conflict_flag = true`: skal brukeren løse konflikten manuelt, eller skal AI foreslå sammenslåing?

---

## 12. Faseinndeling

| Fase | Innhold | Status |
|---|---|---|
| Fase 0 | Plan/ADR | Fullført |
| Fase 1 | Datamodell og read-only ingest | Fullført |
| Fase 2 | Claim-/verifikasjons-UI (grunnmur) | Fullført som grunnmur |
| Fase 3B | Minimal approval-UI | Fullført |
| Fase 4A | Enterprise Wiki egne kilder | Fullført |
| Fase 4B | Lint og helsekontroll | Fullført |
| Fase 5 | WikiSectionAiClient OpenAI-integrasjon | Fullført |
| Fase 6 | Lokal E2E-verifisering | Fullført — viste claim-grunnmur, ikke endelig wiki-modell |
| Fase 7 | Produksjonsrunbook | Fullført — aktivering utsatt |
| Fase 8A | Article Layer Analyse | Fullført |
| Fase 8B | Artikkelgenerering-spesifikasjon | Fullført som article-writer-byggestein, men mangler summary/concepts/index/log/backlinks |
| Fase 8C | Backend artikkelgenerering | Fullført teknisk som article-lag, men ikke full Karpathy compile-modell |
| Fase 8D | Wiki Article UI | Fullført/startet article-first; må utvides til page types senere |
| Fase 8E | Karpathy-alignment: page types/schema/index/log/backlinks/compile decision | Fullført (8E-10–8E-20) |
| **Fase 8F** | **Enterprise Wiki forvaltning av kilder, kjøringer og generert innhold** | **8F-0–8F-5 fullført (forvaltningsflate komplett) · 8F-6 og 8F-7 parkert som unntaksfunksjoner** |
| Fase 8G | Enterprise Wiki coverage/eval og post-ingest QA | 8G-1–8G-7 fullført |
| Fase 8H | Continuous Enterprise Wiki Maintainer Loop | **8H-kjerne delfase 1 + 2 fullført** (kildemonitoring, intelligent retry, dyp reparasjon av claims/refs/lenker) · 8H-utvidelse gjenstår |
| Produksjonsaktivering | Kontrollert aktivering — etter 8G og 8H | Sist — ikke aktiv fase |
| Fase 9 | Sammenligning mot RAG | Fremtidig |
| Fase 10 | Wiki som svargrunnlag | Fremtidig |

### Fase 0 — Plan/ADR *(denne filen)* — Fullført
- Arkitektur- og konsekvenskartlegging
- Datamodell-design
- Beslutningslogg (se §11)
- Ingen kode

### Fase 1 — Datamodell og read-only ingest — Fullført (2026-07-07)

**Commits:** `02a18cb` · `aeb41f1` · `778b931` · `e132318` · `15c06b6` · `c7e7593`

Implementert:
- Migrasjoner for `enterprise_wiki_*`-tabeller (inkl. `enterprise_wiki_ingest_sections`, se §4.6)
- Eloquent-modeller: `EnterpriseWikiPage`, `EnterpriseWikiPageVersion`, `EnterpriseWikiClaim`, `EnterpriseWikiSourceReference`, `EnterpriseWikiIngestRun`, `EnterpriseWikiIngestSection`
- Artisan-kommando: `php artisan wiki:ingest --customer=X --version-id=Y`
- Queue-jobber: `ProcessEnterpriseWikiIngest`, `ProcessEnterpriseWikiSection`, `FinalizeEnterpriseWikiIngest`
- Kun `enterprise-wiki`-queue — ingen kobling til `ai-requirements`
- 73 feature-tester, alle grønne

Fase 1-garantier (alle verifisert):
- Ingen ekte AI-kall — `WikiSectionAiClient::fetchClaims()` er stub (kaster RuntimeException)
- Ingen kobling til produksjons-RAG eller `ai-requirements`-queue
- `KnowledgeItemVersion` leses kun read-only — ingen endringer i `KnowledgeItem`, `KnowledgeItemVersion` eller `KnowledgeItemChunk`
- Claims forblir `approval_status = pending` etter finalize
- Wiki-side settes til `pending_review` av finalize — `approved` krever menneskelig handling

Avvik fra opprinnelig plan:
- Orchestrator oppretter draft-side og draft-versjon atomisk før seksjonsjobber (ikke finalize som §7 steg 5 opprinnelig beskrev)
- `sections_planned` lagt til som intern run-status (mellom `running` og `completed`)
- `enterprise_wiki_ingest_sections` lagt til for per-seksjon arbeidssporing (se §4.6)
- `source_hash` finnes også på `enterprise_wiki_source_references`-rader

### Fase 2 — Historisk claim-/verifikasjons-UI — Fullført som grunnmur, men ikke sluttprodukt

Opprinnelig Fase 2 beskrev `/app/wiki` som liste over sider og `/app/wiki/{page}` som detaljvisning med claims og kildelenker.

v0.3-korrigering:

- Dette er nyttig review-/verifikasjons-UI.
- Dette er **ikke** en ferdig Enterprise Wiki-opplevelse.
- Neste hovedspor er ikke mer claim-UI, men **Wiki Article Layer**.

### Fase 3 — Godkjenning og statusflyt

#### Fase 3B — Minimal approval-UI — Fullført (2026-07-07)

**Commit:** `9c07259`

Implementert:
- `submit`, `approve`, `reject`-metoder i `WikiController`
- Statusoverganger: `draft → pending_review`, `pending_review → approved/rejected`, `rejected → draft`
- System Owner godkjenner og avviser sider; andre roller ser informasjonsmelding for `pending_review`
- Flash-meldinger for alle handlinger
- Approval-seksjon i `Show.jsx` styres av `auth.user.is_system_owner` fra delt Inertia-data

Gjenstår i Fase 3:
- Godkjenning per enkeltpåstand (avhenger av §11, punkt 7)
- Historikk: vis alle versjoner av en side

### Fase 4A — Enterprise Wiki egne kilder

> **Korrekt langsiktig arkitektur.** Etter denne fasen er Enterprise Wiki et fullstendig parallelt system uten avhengighet av Kunnskapsbase eller RAG.

#### Implementert — Fase 4A-5–4A-9 — Fullført (2026-07-07)

| Fase | Commit | Innhold |
|---|---|---|
| 4A-5 | `d584f43` | Upload-UI for Enterprise Wiki-kildedokumenter (`POST /app/wiki/sources`) |
| 4A-6 | `00a2c71` | Visning av opplastede kildedokumenter på `/app/wiki` |
| 4A-7 | `100b17d` | Ingest-action fra UI (`POST /app/wiki/sources/{document}/ingest`) |
| 4A-8 | `1ce529d` | Ingest-statusvisning per kilde med fargede statuskoder |
| 4A-9 | `08f0f6c` | Kobling fra kilde til genererte wiki-utkast med navigasjonslenker |

Implementert:
- `EnterpriseWikiDocument`-modell og `enterprise_wiki_documents`-tabell — ingen FK mot Kunnskapsbase
- Upload-route (`POST /app/wiki/sources`), `WikiSourceController`, tekstekstraksjon via `EnterpriseWikiIngestService`
- Ingest-route (`POST /app/wiki/sources/{document}/ingest`), kun for `document_status = 'extracted'`
- `source_type = 'enterprise_wiki_document'` støttet i `enterprise_wiki_ingest_runs`
- Kildetabell på `/app/wiki` med filnavn, dokumentstatus, ingest-status og navigasjonslenker til genererte sider
- Kundeisolasjon håndheves i alle spørringer og kontrollere

Opprinnelig planbeskrivelse:

Ny modell og tabell:

- `EnterpriseWikiDocument` — ny kilde-modell med `customer_id`, `original_filename`, `file_path`, `extracted_text`, `file_hash_sha256`, `uploaded_by_user_id`, `document_status`
- Ingen FK mot `knowledge_items`, `knowledge_item_versions` eller `knowledge_item_chunks`
- Tabell: `enterprise_wiki_documents` (prefix bevart)

Upload/import-flyt:

- Ny route: `POST /app/wiki/sources` — bruker laster opp dokument direkte i Enterprise Wiki
- Tekstekstraksjon: gjenbruker eksisterende ekstraksjonsmekanisme (docx/pdf) — resultatet lagres på `EnterpriseWikiDocument`, ikke i Kunnskapsbase
- Trigger: UI-knapp i Enterprise Wiki for å starte ingest fra eget dokument

Ingest-tilpasninger:

- `enterprise_wiki_ingest_runs.source_type` utvides med `enterprise_wiki_document`
- `enterprise_wiki_source_references.source_type` utvides med `enterprise_wiki_document`
- Eksisterende `knowledge_item_version`-verdier beholdes for historikk og legacy-import
- Ingen endring av eksisterende `ProcessEnterpriseWikiIngest` — ny `source_type`-gren legges til

Etter Fase 4A:

- `KnowledgeItemVersion`-ingest (Flyt A) finnes kun som legacy/import-kommando (`php artisan wiki:ingest`)
- Kommandoen er ikke eksponert i UI uten tydelig "importer fra Kunnskapsbase"-merking
- Wiki og Kunnskapsbase er separate systemer — ingen automatisk synkronisering

### Fase 4B — Lint og helsekontroll

#### Fase 4B-1 — Verifikasjonsvisning — Fullført (2026-07-08)

Commit: `44ea5cb`

Implementert:
- Verifikasjonsgrunnlag på `/app/wiki/{slug}`: hver påstand vises med kildedokument, avsnittref, tekstutdrag og advarsel hvis kildereferanser mangler
- Utkast/under-gjennomgang-banner for `draft`- og `pending_review`-sider

#### Fase 4B-2 — Kildenedlasting — Fullført (2026-07-08)

Commit: `bb20ee9`

Implementert:
- `GET /app/wiki/sources/{document}/download` — scoped til innlogget kunde, aldri rå storage path i frontend
- `download_url` genereres av backend for kildereferanser med `source_type = enterprise_wiki_document`
- Nedlastingslenke fra kildereferanse på wiki-detaljesiden og fra kildedokument-listen på `/app/wiki`

#### Fase 4B-3 — Kvalitetsindikatorer for påstander — Fullført

Commit: `b92e38a`

Implementert:
- Read-only kvalitetsindikator per påstand på `/app/wiki/{slug}`
- "Kilde funnet" når påstanden har kildereferanse med tekstutdrag
- "Mangler utdrag" når kildereferanse finnes, men excerpt mangler
- "Mangler kilde" når påstanden ikke har kildereferanser
- Indikatorene bruker eksisterende claims/source references-data
- Ingen backend/controller-endringer
- Ingen migrasjoner, lint-service, AI-kall, graf, ingest-endring eller claim approval

#### Fase 4B-4 — Forventningsstyring for deaktivert wiki-generering — Fullført (2026-07-08)

- `WikiSectionAiClient::isAvailable()` returnerer `false` frem til Fase 5 er implementert
- `WikiController::index()` sender `wiki_generation_available: false` som Inertia-prop
- `WikiSourceController::ingest()` blokkerer kall og returnerer brukervennlig flash-error når ikke tilgjengelig — ingen jobb dispatches
- Frontend deaktiverer "Generer wiki-utkast"-knappen og viser "Wiki-generering er ikke aktivert ennå." under knappen
- Ingen RuntimeException eksponeres mot brukeren lenger

#### Fase 4B — Helsekontroll (2026-07-08)

Teknisk og dokumentasjonsmessig kontroll gjennomført etter Fase 4B-1–4B-4:

- Git-historikk ren, alle Fase 4B-commits på plass
- Build OK, 85/85 tester grønne
- Alle Enterprise Wiki-ruter under `app/wiki`, ingen lekkasje mot andre resurser
- `KnowledgeItem`/`KnowledgeItemVersion`-referanser i ingest-kode er isolert bak `else`-gren eksplisitt merket `legacy/bootstrap — Kunnskapsbase-import` — ingen aktiv kobling fra ny `EnterpriseWikiDocument`-sti
- `WikiSectionAiClient::isAvailable()` returnerer `false`, ingest blokkeres før queue-dispatch
- Ingen ekte AI-kall, ingen RAG/Kunnskapsbase/billing/Filament/AI workspace berørt

#### Fase 4B-5A — Backend lint-grunnlag — Fullført (2026-07-08)

Implementert:
- Migration: `enterprise_wiki_lint_findings` med FK til customer, page, claim, document + indekser
- Model: `EnterpriseWikiLintFinding` med konstanter for `code`, `severity`, `status`
- Service: `EnterpriseWikiLintService` med 3 lint-regler:
  - `claim_missing_source` — error (pending_review/approved) eller warning (draft)
  - `source_reference_missing_excerpt` — warning
  - `document_ingest_failed` — warning
- Idempotens: upsert ved unike nøkler (null-aware), close-stale for resolved findings
- Artisan-kommando: `wiki:lint --customer=ID --page=ID` (ingen opsjoner = alle aktive kunder)
- 14 nye tester i `tests/Feature/Console/EnterpriseWikiLintCommandTest.php` (14/14 passed)
- Ingen ekte AI-kall, ingen RAG/Kunnskapsbase/billing/Filament/AI workspace berørt

#### Fase 4B-5B — UI helseindikator — Fullført (2026-07-08)

Implementert:
- `WikiController::index()`: ny prop `lint_health` med `{error, warning, info, total}` for aktiv customer (kun `status=open`, customer-scoped)
- `WikiController::show()`: ny prop `lint_findings` med åpne funn for aktuell side (customer-scoped, sortert error → warning → info)
- `Index.jsx`: `LintHealthBar`-komponent — viser "Ingen åpne helsefunn" (grønn) eller severity-badges per type
- `Show.jsx`: Helsekontroll-seksjon med funn-liste eller "Ingen åpne helsefunn for denne siden."
- 8 nye tester i `WikiControllerTest` (alle passing)
- Duplikat-nøkkel `source_open_document` fjernet fra begge lang-filer
- Ingen ekte AI-kall, ingen RAG/Kunnskapsbase/billing/Filament/AI workspace berørt

#### Fase 4B-5C — Scheduled lint-job — Fullført (2026-07-08)

Implementert:
- `routes/console.php`: `Schedule::command('wiki:lint')->dailyAt('02:30')->withoutOverlapping()`
- Kjøres daglig kl. 02:30 for alle aktive customers uten --customer/--page (no-args mode)
- Inaktive customers hoppes over (existing `where('is_active', true)` i command)
- 3 nye tester: no-args på tom DB, no-args linter alle aktive customers, inaktive customers hoppes over
- Ingen ekte AI-kall, ingen RAG/Kunnskapsbase/billing/Filament/AI workspace berørt

**Fase 4B fullført.** Alle delmål gjennomført:
4B-1 Verifikasjonsvisning · 4B-2 Kildenedlasting · 4B-3 Kvalitetsindikatorer · 4B-4 Forventningsstyring · 4B-5A Backend lint · 4B-5B UI helseindikator · 4B-5C Scheduled lint

### Fase 5 — WikiSectionAiClient OpenAI-integrasjon — Fullført

> **v0.3-status:** Fase 5 implementerte claim extraction-klienten og config-gating. Dette er nødvendig verifikasjonsgrunnmur, men ikke nok til å produsere Karpathy-inspirerte wikiartikler.

#### Fase 5.0 — Implementeringsspesifikasjon (2026-07-08)

Spesifiserer det eneste manglende leddet som blokkerer wiki-generering:
`WikiSectionAiClient::fetchClaims()` og tilhørende aktivering.

---

##### Prompt-regler

Systemrollen (`developer`) instruerer modellen:

- Kun ekstrahere påstander fra den oppgitte seksjonsteksten — aldri finne opp informasjon
- Aldri bruke ekstern kunnskap eller generell domenekunnskap som kilde
- Hver claim skal være én konkret, verifiserbar setning på vanlig norsk (eller kundespråk)
- Hvert `excerpt` skal være et nært tekstutdrag fra seksjonsteksten (ordrett eller minimalt omformulert)
- `confidence` settes etter støtten i teksten: `high` = eksplisitt og entydig, `medium` = rimelig støtte, `low` = implisitt/usikkert grunnlag, `uncertain` = svak støtte
- `conflict_note` brukes kun når seksjonsteksten inneholder to utsagn som eksplisitt motsier hverandre
- Hvis seksjonsteksten ikke inneholder konkrete, verifiserbare påstander: returner tom `claims`-liste
- Ikke slå sammen informasjon fra ulike seksjoner — én seksjon, én jobb

Brukerrollen (`user`) sender:
```
Seksjonstekst (overskrift: {heading}):

{sectionText}
```

Språk injiseres i system-prompten: `Returner claims på {languageName}.`

---

##### Exakt JSON-format (Responses API, strict JSON schema)

`fetchClaims()` returnerer:

```json
{
  "claims": [
    {
      "text": "Virksomheten leverer konsulentbistand innen offentlig sektor.",
      "confidence": "high",
      "excerpt": "Virksomheten leverer konsulentbistand innen offentlig sektor.",
      "conflict_note": null
    }
  ]
}
```

`confidence` enum: `["high", "medium", "low", "uncertain"]`
`conflict_note`: `string | null`

Responses API-payload:

```php
[
    'model' => 'gpt-4.1-mini',
    'input' => [
        ['role' => 'developer', 'content' => [['type' => 'input_text', 'text' => $systemPrompt]]],
        ['role' => 'user',      'content' => [['type' => 'input_text', 'text' => $userPrompt]]],
    ],
    'text' => [
        'format' => [
            'type' => 'json_schema',
            'name' => 'wiki_section_claims',
            'strict' => true,
            'schema' => self::schema(),
        ],
    ],
    'temperature' => 0,
    'max_output_tokens' => 2000,
]
```

---

##### Grenser

| Grense | Verdi | Kilde |
|---|---|---|
| Maks input-tegn per seksjon | 3 000 | `EnterpriseWikiSectionParser::MAX_SECTION_CHARS` — enforces upstream |
| Maks claims per seksjon | 15 | Prompt-instruksjon + schema `maxItems` |
| Maks excerpt-lengde | 500 tegn | `EnterpriseWikiSectionParser::MAX_EXCERPT_CHARS` — enforces i parser |
| Maks output tokens | 2 000 | Rikelig for 15 claims × ~100 tokens |
| Modell | `gpt-4.1-mini` | Extraction-modell per CLAUDE.md |
| Temperature | 0 | Deterministisk ekstraksjon |

Seksjonen er allerede splittet og begrenset til 3 000 tegn av `splitIntoSections()` — ingen ekstra truncation i `fetchClaims()`.

---

##### Feilhåndtering

| Feiltype | Håndtering |
|---|---|
| OpenAI HTTP-feil | `OpenAiClient` kaster `RuntimeException` → bobler opp til `ProcessEnterpriseWikiSection::handle()` → `failSection()` + seksjonen merkes `failed` |
| Malformed JSON / strict schema-brudd | `OpenAiClient::send()` kaster `RuntimeException` (eksisterende logikk) |
| Tom `claims`-liste | Gyldig respons — seksjonen merkes `completed` uten claims |
| OpenAI timeout | `ProcessEnterpriseWikiSection.$timeout = 600` — queue-job feiler etter 10 min |
| `sectionText` tom streng | `fetchClaims()` returnerer `['claims' => []]` uten API-kall |

Ingen custom retry-logikk i `fetchClaims()` — existerende `$tries = 1` på job-nivå er tilstrekkelig i pilot.

Logging: ett `Log::info`-kall ved suksess med `section_id`, `claim_count`, `input_tokens`, `output_tokens`.
Ingen seksjon- eller kundespesifikk tekst i logg-felter.

---

##### Teststrategi (mock — ingen ekte API-kall)

`WikiSectionAiClient` mottar `OpenAiClient` via konstruktør-injeksjon. Tests bruker `$this->mock(OpenAiClient::class)`.

| Test | Hva sjekkes |
|---|---|
| Gyldig respons med claims | `fetchClaims()` returnerer riktig array-struktur |
| Tom claims-liste | Tom array returneres, ingen exception |
| Tom sectionText | Returnerer `['claims' => []]` uten API-kall |
| Malformed JSON fra OpenAI | `RuntimeException` kastes |
| OpenAI HTTP-feil | `RuntimeException` kastes |
| `isAvailable()` forblir false | Ingen endring av flagg i dette steget |

Eksisterende `ProcessEnterpriseWikiSectionTest` bekrefter downstream-integrering via mock — ingen endringer der nødvendig.

---

##### Implementeringssteg — Alle fullført

**A — Dark deploy med tester (ingen UI-endring)**
- Implementer `fetchClaims()` med reell Responses API-kall
- Injiser `OpenAiClient` via konstruktør
- Legg til privat `languageName(string $code): string`-helper
- Legg til `WikiSectionClaimsPrompt`-klasse for prompt/schema/konstanter (speiler `FullDocumentRequirementExtractionPrompt`-mønsteret)
- `isAvailable()` forblir `false`
- Alle tester bruker mock

**B — Manuell lokal verifikasjon**
- Aktiver midlertidig i `.env` eller med feature-flag (ikke i kode)
- Kjør manuell ingest mot ett testdokument
- Verifiser claims, excerpts og confidence i databasen
- Verifiser logging og token-telling

**C — Config-gatet aktivering**
- `isAvailable()` leser eksplisitt config/env-flagg
- Default er fortsatt av
- Frontend og backend slutter bare å blokkere ingest når flagget eksplisitt er aktivert
- v0.3: produksjonsaktivering skal ikke skje før Wiki Article Layer er implementert

---

##### Åpne beslutninger som ikke blokkerer Fase 5

- §11 punkt 6 (maks tokens per full run): håndteres per-seksjon i pilot — tilstrekkelig for nå
- §11 punkt 9 (automatisk re-ingest): utsettes til etter pilot
- §11 punkt 10 (konflikthåndtering): `conflict_flag` settes allerede, UI viser det — ingen ny handling kreves i Fase 5

### Fase 6 — Lokal E2E-verifisering — Fullført

Manuell lokal verifisering gjennomført mot Masterdata Prosjekt.docx:
- 291 claims generert, 0 åpne lint-funn
- Wiki-side opprettet som `pending_review`
- Claims, excerpts og confidence verifisert i database
- `content_markdown` populert av `assembleContentMarkdown()` (claim-dump, ikke artikkel — erstattes i Fase 8C)

### Fase 7 — Produksjonsrunbook — Fullført (aktivering utsatt — ikke del av 8G)

Kjøreklar aktiveringsguide for produksjonsmiljø. Forutsetter at Fase 6 (lokal E2E-verifisering) er gjennomført og godkjent.

> **v0.5-korrigering:** Denne runbooken er beholdt som teknisk aktiveringsgrunnlag, men faktisk produksjonsaktivering skal vente til Karpathy compile-modellen er implementert og verifisert. Dagens tekniske article generation er bare article-laget og er ikke riktig sluttprodukt alene.

#### Forutsetninger

Før aktivering i produksjon:

- [x] Fase 6 lokal E2E-verifisering er gjennomført — claims, excerpts og confidence er verifisert mot testdokument
- [ ] Alle migrasjoner er kjørt i produksjon, inkludert `enterprise_wiki_lint_findings`-migrasjonen (`2026_07_08_000001_create_enterprise_wiki_lint_findings_table`)
- [ ] Queue worker for `enterprise-wiki`-køen kjører i produksjon
- [ ] System Owner-bruker er tilgjengelig for første gjennomgang

Sjekk kjørte migrasjoner:

```bash
php artisan migrate:status | grep enterprise_wiki
```

#### Aktiveringssekvens

**1. Sett environment-variabelen**

Legg til i `.env` (produksjon):

```
ENTERPRISE_WIKI_AI_ENABLED=true
```

Standard er `false`. Variabelen leses av `config('services.enterprise_wiki.ai_enabled')` — definert i `config/services.php`.

**2. Rydd config-cache**

```bash
php artisan config:clear
```

Uten dette vil kjørende prosesser lese gammel cachet konfigurasjon.

**3. Restart queue worker**

```bash
php artisan queue:restart
```

Lange kjørende queue workers cacher PHP-klassedefinitioner ved oppstart. `queue:restart` sender et graceful stopsignal — eksisterende jobber fullføres, deretter starter supervisor/worker-prosessen på nytt med ny konfigurasjon. Worker vil ikke plukke opp `ENTERPRISE_WIKI_AI_ENABLED=true` uten restart.

Bekreft at ny worker-prosess er startet:

```bash
ps aux | grep "queue:work"
```

**4. Verifiser at flagget er aktivt**

```bash
php artisan config:show services.enterprise_wiki
```

Forventet output: `ai_enabled: true`

#### Første kontrollerte bruk

Første ingest i produksjon bør gjøres med **ett enkelt, kortere kildedokument** (anbefalt: 5–15 sider). Dette begrenser antall claims og gir et håndterbart grunnlag for første gjennomgang.

Fremgangsmåte:

1. Last opp kildedokumentet via `/app/wiki` → «Last opp kilde»
2. Vent til dokument-status viser «Ekstrahert»
3. Trykk «Generer wiki-utkast» for kilden
4. Overvåk ingest-status på `/app/wiki`
5. Når ingest er fullført: wiki-siden settes til `pending_review` — **ikke godkjent automatisk**

Wiki-siden er ikke synlig for vanlige brukere som `approved` innhold før en System Owner har godkjent den.

#### Reviewer-sjekkliste for første godkjenning

Gå til `/app/wiki/{slug}` og kontroller:

- [ ] **Claim-tekst** — Er påstandene konkrete og korrekte? Beskriver de faktiske forhold fra kildedokumentet?
- [ ] **Excerpt** — Stemmer tekstutdraget overens med kilden? Er det hentet verbatimt eller nært fra kildeteksten?
- [ ] **Kildereferanser** — Har alle påstander minst én kildereferanse? Ingen påstand skal mangle kilde.
- [ ] **Confidence-nivå** — Er `high`/`medium`/`low`/`uncertain`-merkingen rimelig? `uncertain` betyr svak tekstlig støtte — vurder om påstanden skal avvises.
- [ ] **Kvalitetsindikatorer** — Kontroller at «Kilde funnet»-indikatoren er grønn for alle påstander. Rødt «Mangler kilde» er alltid en feil.
- [ ] **Helsekontroll** — Sjekk Helsekontroll-seksjonen på siden. Ingen `error`-funn skal være åpne ved godkjenning.
- [ ] **Konfliktnoter** — Hvis `conflict_note` er satt på en påstand, les konflikten nøye og vurder om siden likevel er riktig.

Dersom gjennomgangen avdekker vesentlige feil: bruk «Avvis» og logg begrunnelsen. Godkjenn ikke sider med åpne `error`-funn i helsekontroll.

#### Claim-volum for store dokumenter

Et normalt dokument med 20 seksjoner kan generere opptil **15 claims × 20 seksjoner = 300 påstander**. Store policy-dokumenter eller lange tjenestebeskrivelser gir høyt volum.

Dette er forventet atferd. Planlegg gjennomgangskapasitet før ingest av store dokumenter.

#### Reversering

For å deaktivere wiki-generering:

1. Sett `ENTERPRISE_WIKI_AI_ENABLED=false` i `.env`
2. Kjør `php artisan config:clear`
3. Kjør `php artisan queue:restart`

Allerede genererte wiki-sider og claims slettes **ikke** ved deaktivering. Ingest-knappen blokkeres igjen og viser «Wiki-generering er ikke aktivert ennå.» Eksisterende `pending_review`-sider kan fortsatt gjennomgås og godkjennes.

#### Logg og overvåking

- Ingest-kjøringer logges i `enterprise_wiki_ingest_runs` med `model_used`, `input_tokens`, `output_tokens` og `cost_estimate_nok`
- Seksjonsstatus loggføres i `enterprise_wiki_ingest_sections`
- Queue-logger finnes i `storage/logs/laravel.log`
- Helsekontroll kjøres daglig kl. 02:30 via `wiki:lint` (Fase 4B-5C)

---

## Fase 8 — Karpathy-alignment og Wiki Maintainer Layer

Målet er å gå fra dokumentbasert article generation til en Karpathy-kompatibel wiki-compiler:

```text
raw sources → schema → compile decision
→ source article + source summary
→ shared concept/entity pages
→ index/log/backlinks/lint
→ content_markdown as readable wiki
→ claims/sources as verification layer
→ review/approval
```

Fase 8A–8D ga viktige byggesteiner, men v0.5 korrigerer retningen: `article per source` er riktig, men ikke tilstrekkelig. Neste arbeid er ikke review/approval UX og ikke produksjonsaktivering. Neste arbeid er å få Karpathy page types, schema, index, log, backlinks og compile decision på plass.

### Fase 8A — Article Layer Analyse — Fullført

Mål: kartlegg nøyaktig hvor dagens implementering gjør claims til hovedinnhold.

Scope gjennomgått:
- `FinalizeEnterpriseWikiIngest::assembleContentMarkdown()` — produserte en claim-dump, ikke en artikkel
- `EnterpriseWikiPageVersion.content_markdown` — feltet finnes og er riktig sted for wikiinnhold
- `WikiController::show()` — passerte `content_markdown` men det ble ikke brukt i UI
- `resources/js/Pages/App/Wiki/Show.jsx` — ignorerte `content_markdown`, viste bare claims

v0.5-funn: Analysen identifiserte riktig felt (`content_markdown`), men ikke hele Karpathy-modellen. Gapet er større enn article generation: systemet mangler page types, source summary, concept/entity-sider, index, log, backlinks og compile decision.

### Fase 8B — Artikkelgenerering-spesifikasjon — Fullført som article-writer-byggestein

> Spesifikasjonsfase. Ingen kodeendringer i denne fasen.

Fase 8B definerte `WikiArticleAiClient` og bruk av `content_markdown`. Dette var nyttig for å fjerne claim-dump fra artikkelinnholdet.

v0.5-korrigering:

- `content_markdown` er fortsatt riktig felt for wikiinnhold.
- `WikiArticleAiClient` kan beholdes som teknisk writer for `article`-sider.
- Spesifikasjonen må ikke forstås som full Karpathy-modell.
- Manglende ledd er compile decision: source article, source summary, concept/entity updates, index/log/backlinks.
- Filnavn skal ikke blindt være artikkeltittel eller slug.

### Fase 8C — Backend artikkelgenerering — Fullført teknisk som article-lag

**Commits:** `956206d`, `5029cb0`, `4ea8fb6`

Implementert:
- `WikiArticleAiClient` for article generation
- config-guard slik at disabled AI ikke gir uryddig runtime-brudd
- validering mot HTML-kommentarer, `Kilde:`/`Source:`/`Ref:`-dump og excerpt-/blockquote-dump
- tester for klient og finalize-flyt

v0.5-status:
- Committene beholdes.
- Dette er nyttig teknisk grunnlag og guardrails.
- Dette dekker `wiki/articles`-laget delvis.
- Dette dekker ikke `wiki/summaries`, `wiki/concepts`, `wiki/index.md`, `wiki/log.md` eller `wiki/backlinks.md`.
- Videre arbeid skal ikke bare forbedre article-prompten, men korrigere compile-modellen.

### Fase 8D — Wiki Article UI — Fullført/startet

**Commits:** `94f6541`, `94f5721`

Implementert:
- `content_markdown` rendres som primær wikiartikkel via `react-markdown`
- Claims, sources og lint er sekundære i kollapsbar "Verifikasjonsgrunnlag"-seksjon
- Godkjente sider viser ikke draft-/AI-merker i toppen
- Helsekontroll er flyttet inn i verifikasjonslaget

v0.5-status:
- UI-retningen er riktig for lesing av `article`-sider.
- UI løser ikke manglende `summary`, `concept/entity`, `index`, `log` eller `backlinks`.
- Review/approval UX skal vente til Karpathy-output er riktig.

### Fase 8E — Karpathy-alignment: page types/schema/index/log/backlinks/compile decision — Neste steg

Mål: etablere minste trygge grunnlag for at Enterprise Wiki fungerer som Karpathy-kompatibel wiki-compiler, ikke bare dokumentoppsummerer.

Dette er først en audit og smal implementeringsspesifikasjon. Ikke start stor refaktor.

Oppgaver:

1. **Audit source-as-single-page-identitet**
   - Finn hvor source/document/filename i dag blir `EnterpriseWikiPage.title` eller `slug`.
   - Finn hvor page/version opprettes før compile decision.
   - Finn hvordan `enterprise_wiki_ingest_runs.enterprise_wiki_page_id` begrenser én kilde til én side.
   - Finn om eksisterende kildelenker fra `/app/wiki` antar én generert side per kilde.

2. **Definer page type-kontrakt**
   - Minimum: `article`, `summary`, `concept`, `entity`, `index`, `backlinks`.
   - Avklar om `page_type` må bli DB-felt nå eller kan simuleres i første steg.
   - Avklar unikhet: slug per customer eller per customer+page_type.

3. **Definer schema/instruction contract**
   - Regler for page types, front matter/logiske metadata, navngiving, lenking, index, ingest/compile, query og lint.
   - Filnavn er kun source metadata.
   - Ingen hardkodede temaer.

4. **Definer index contract**
   - Hvilke felter fra eksisterende pages skal AI se før den velger concept/entity-side?
   - Minimum: page id, page_type, title, slug, status, topics, kort summary/markdown excerpt, lint health.

5. **Definer compile decision JSON**
   - `source_article`
   - `source_summary`
   - `update_existing_pages[]`
   - `create_new_pages[]`
   - `no_action_reason`
   - `warnings/conflicts`
   - `source_coverage`

6. **Definer log/backlinks/trace**
   - Hvordan dokumenteres at én kilde oppdaterte flere sider?
   - Avklar om eksisterende `enterprise_wiki_ingest_runs` holder midlertidig eller om ny relation trengs senere.
   - Avklar første backlinks-løsning: tabell, generert markdown-side eller lint-output.

7. **Minste kodeendring etter audit**
   - Prioriter å stoppe blind filnavn→title/slug.
   - Prioriter page type-kontrakt.
   - Prioriter index-first lookup før nye concept/entity-sider.
   - Ikke gjør DB-endring før audit viser konkret behov.

Akseptansekriterier:
- Planen sier tydelig at `article per source` er del av Karpathy-modellen.
- Planen sier tydelig at `article per source` ikke er nok alene.
- Planen peker ikke lenger på review/approval UX som neste steg.
- Fase 8B/8C er merket som nyttig article-lag, men ikke full Karpathy compile-modell.
- Neste kodeendring er basert på faktisk audit av eksisterende `EnterpriseWikiPage`-opprettelse og kildelenker.
- Kunnskapsbase/RAG, billing, admin/Filament og AI workspace er urørt.

#### Fase 8E-10 — Manual apply command for maintainer decision — Fullført

**Commit:** `520ef49`

Implementert:
- Artisan-kommando `wiki:apply-maintainer-decision --run-id=ID`
- Kommandoen er utelukkende en tynn wrapper rundt `EnterpriseWikiMaintainerDecisionApplyService::apply()`
- Henter `EnterpriseWikiIngestRun` via ID og videresender til servicen
- Skriver tydelig CLI-output: hvilke sider som ble created, hvilke som ble updated, bekreftelse på at run er marked applied
- Alle guards (feil status, null JSON, allerede applied, manglende run) håndteres av servicen og oversettes til CLI-feil
- Ingen OpenAI-kall
- Ingen innholdsgenerering / ingen `content_markdown`
- Ingen `EnterpriseWikiPageVersion` opprettes
- Ingen claims eller source references skrives
- `ProcessEnterpriseWikiIngest` er uendret
- Ingen UI-knapp
- 15 tester (argument-validering, vellykket apply, already-applied guard, feil status/JSON, side-effects)

#### Fase 8E-11 — Read-only inspection of applied page skeletons — Fullført

**Commit:** `3d95dfc`

Implementert:
- Artisan-kommando `wiki:inspect-applied-decision --run-id=ID`
- Leser applied `EnterpriseWikiIngestRun`, tilhørende `enterprise_wiki_ingest_run_pages` og koblede `EnterpriseWikiPage`-rader
- Viser per side: page id, page type, title/slug, pivot action (`created`/`updated`) og page status
- Oppsummerer antall article, summary, concept, entity og totalt antall koblede sider
- Validerer customer-scope read-only: gir feil hvis en koblet side tilhører annen customer enn run
- Gir tydelig advarsel (exit 0) hvis run ikke er applied ennå
- Gir feil hvis run ikke finnes eller `--run-id` mangler
- Skriver ingenting til databasen
- Test bekrefter at `enterprise_wiki_pages`, `enterprise_wiki_page_versions`, `enterprise_wiki_claims`, `enterprise_wiki_ingest_run_pages` og `enterprise_wiki_ingest_runs` har identisk radtall før og etter kjøring
- 14 tester

#### Fase 8E-12 — Generate article + summary page versions, backend-only/manual — Fullført

**Commit:** `fb49fcd`

Implementert:
- Artisan-kommando `wiki:generate-applied-pages --run-id=ID`
- Ny `WikiPageContentAiClient` (`app/Services/Ai/Wiki/WikiPageContentAiClient.php`) — slim gpt-5-klient som tar `pageTitle`, `pageType` (article/summary), `sourceText` og `languageCode`, og returnerer validert Markdown via strukturert JSON-output. Skiller prompt mellom article og summary. Samme forbud mot HTML-kommentarer, kildelinjer og blockquotes som `WikiArticleAiClient`.
- Ny `EnterpriseWikiGenerateAppliedPagesService` (`app/Services/EnterpriseWiki/EnterpriseWikiGenerateAppliedPagesService.php`) — orkestrerer generering for applied run. Leser `extracted_text` fra `EnterpriseWikiDocument` (customer-scopet via `source_id`). Løser `languageCode` fra customer sin `Language`. Itererer pivot-rader, hopper over concept/entity, hopper over sider som allerede har versjon (idempotens). Skriver én `EnterpriseWikiPageVersion` per eligible side med `is_current=true`, `version_number` auto-inkrementert og `generated_by_model = 'gpt-5'`. Returnerer `{generated, skipped}`.
- Kommandoen er en tynn wrapper: validerer `--run-id`, finner run, delegerer til service, skriver `[WIKI_GENERATE] Run [N] — Generated: X, Skipped: Y`.
- Ingen claims, ingen source references, ingen UI, ingen endring i `ProcessEnterpriseWikiIngest`
- Ingen ekte AI-kall i tester — `WikiPageContentAiClient` mockes i `setUp()` via Laravel service container
- 16 tester

#### Fase 8E-13 — Generate concept/entity page versions, backend-only/manual — Fullført

**Commit:** `6ec73a6`

Implementert:
- Eksisterende `wiki:generate-applied-pages --run-id=ID` er utvidet til å dekke alle fire sidetyper: `article`, `summary`, `concept` og `entity`
- `EnterpriseWikiGenerateAppliedPagesService` bruker nå to-pass-logikk: article og summary genereres i pass 1, deretter concept og entity i pass 2 — slik at article/summary-innhold er tilgjengelig som kontekst for concept/entity-generering
- `WikiPageContentAiClient` støtter nå egne developer-prompts for `concept` og `entity`, og har fått valgfri `$additionalContext`-parameter som sendes med i user-prompt for concept/entity
- Kontekst for concept/entity bygges av: article/summary `content_markdown` fra DB + maintainer-beslutningens `reason` for siden (matchet på tittel fra `maintainer_decision_json`)
- CLI-output viser per-type resultat: `Article: X generated`, `Summary: Y generated`, `Concept: Z generated`, `Entity: W generated`, `Skipped: S`
- Eksisterende versjoner skippes fortsatt trygt (idempotens via `exists()`-sjekk)
- Ingen claims, ingen source references, ingen UI, ingen endring i `ProcessEnterpriseWikiIngest`
- Ingen ekte AI-kall i tester — `WikiPageContentAiClient` mockes i `setUp()` via Laravel service container
- 23 tester

#### Fase 8E-14 — Extract claims from generated Enterprise Wiki page versions — Fullført

**Commit implementering:** `ff79158` · **Commit confidence-kontrakt:** `ad5b079`

Implementert:
- Artisan-kommando `wiki:extract-page-claims --run-id=ID`
- Ny `WikiPageClaimExtractionAiClient` (`app/Services/Ai/Wiki/WikiPageClaimExtractionAiClient.php`) — gpt-4.1-mini, temperature 0, ekstraherer claims fra `content_markdown` i genererte wiki-sider. Bruker string confidence enum (`high`/`medium`/`low`/`uncertain`) — samme som `WikiSectionAiClient` og `EnterpriseWikiClaim::CONFIDENCE_*`. 8E-14-spesifikasjonens `0.0` var illustrativ; numerisk float er bevisst utsatt (krever migrasjon).
- Ny `EnterpriseWikiExtractPageClaimsService` — laster pivot-rader, finner current `EnterpriseWikiPageVersion` per side, ekstraherer claims via AI-klient, skriver `EnterpriseWikiClaim`-rader med `claim_text`, `confidence`, `conflict_flag`, `position_order`, `approval_status = pending`
- Idempotent via versjonsnivå-sjekk: sider der current version allerede har claims hoppes over
- Sider uten current version hoppes over med tydelig teller i output
- CLI-output: `Pages processed: X`, `Claims created: Y`, `Pages skipped: Z`
- Ingen source references, ingen lint, ingen UI, ingen endring i `ProcessEnterpriseWikiIngest`
- `WikiPageClaimExtractionAiClient` mockes i `setUp()` — ingen ekte OpenAI-kall i tester
- 23 tester

#### Fase 8E-15 — Verify claims against source document, write source references — Fullført

**Commit:** `cf5c520`

Implementert:
- Artisan-kommando `wiki:verify-page-claims --run-id=ID`
- Ny `WikiClaimVerificationAiClient` (`app/Services/Ai/Wiki/WikiClaimVerificationAiClient.php`) — gpt-4.1-mini, temperature 0, tar `claimText` + `sourceText`, returnerer `{supported: bool, excerpt: string}`. Excerpt er verbatim sitat fra kildedokumentet — AI oppfinner ikke tekst.
- Ny `EnterpriseWikiVerifyPageClaimsService` — laster kildedokument via `run->source_id`, itererer pivot-rader → sider → current versions → claims. For hver claim: idempotenssjekk (eksisterende source reference hoppes over), kaller AI, skriver `EnterpriseWikiSourceReference` med `source_type = SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT`, `source_id`, `source_label = original_filename`, `source_hash = file_hash_sha256`, `excerpt`.
- **Source context er `EnterpriseWikiDocument.extracted_text` — ikke `EnterpriseWikiPageVersion.content_markdown`.** AI-klienten sjekker om påstanden finnes i originalkilden, ikke om den er konsistent med det AI selv har generert. `content_markdown` brukes kun til å slå opp claim-rader; det sendes aldri til verifikasjonsmodellen.
- Idempotent på claim-nivå: claims som allerede har source reference hoppes over (`skipped`-teller)
- Claims der AI ikke finner støtte resulterer ikke i source reference (`no_support`-teller)
- CLI-output: `Pages checked`, `Claims checked`, `References created`, `Skipped`, `No support found`
- Sider uten current version eller uten claims hoppes over stille
- Ingen claims, ingen lint, ingen backlinks, ingen UI, ingen endring i `ProcessEnterpriseWikiIngest`
- `WikiClaimVerificationAiClient` mockes i `setUp()` — ingen ekte OpenAI-kall i tester
- 23 tester

#### Fase 8E-16 — Enterprise Wiki page linking, backlinks og traversal index — Fullført

**Commit:** `9d22159`

Implementert:
- Migrasjon: `enterprise_wiki_page_links` med unik indeks på `(customer_id, from_page_id, to_page_id, link_type)`
- Modell `EnterpriseWikiPageLink` — 10 link type-konstanter (`article_to_summary`, `summary_to_article`, `article_to_concept`, `concept_to_article`, `article_to_entity`, `entity_to_article`, `summary_to_concept`, `concept_to_summary`, `summary_to_entity`, `entity_to_summary`). Kilde-konstanter `SOURCE_DETERMINISTIC` / `SOURCE_MAINTAINER_DECISION`. Confidence-konstanter `CONFIDENCE_CERTAIN` / `CONFIDENCE_INFERRED`.
- `EnterpriseWikiBuildPageLinksService` — deterministisk, ingen OpenAI. Laster pivot-rader for en run, kategoriserer sider etter page type, bygger alle kombinasjoner i begge retninger. Bruker `firstOrCreate` + `wasRecentlyCreated` for idempotens og telling av created vs. skipped.
- Artisan-kommando `wiki:build-page-links --run-id=ID` — guard på `maintainer_decision_status = applied`, full CLI-output (pages checked, links created, links skipped, missing versions, failed).
- `EnterpriseWikiPageTraversalService` — read-only. Eksponerer `outgoing()`, `incoming()`, `relatedArticles()`, `relatedConcepts()`, `relatedEntities()`. Alle metoder er rene forward-queries på `from_page_id` eller `to_page_id`, customer-scopet. Ingen reverse joins nødvendig fordi begge retninger lagres som eksplisitte rader.
- 37 tester (29 kommando + 8 traversal). Ingen endring i claims, source references, lint, `ProcessEnterpriseWikiIngest` eller Kunnskapsbase/RAG.

**Design: `EnterpriseWikiPageLink` er en kanonisk customer-scoped sidegraf — ikke per-run historikk.**  
Den unikke indeksen sikrer at det kun finnes én kant av hver type mellom to sider per kunde, uavhengig av hvor mange runs som har blitt kjørt. `enterprise_wiki_ingest_run_id` er nullable og viser kun hvilken run som _først_ opprettet linken — det er ikke et eierskap og betyr ikke at linken slettes eller endres ved en ny run. Grafen er kumulativ: kanter akkumuleres over tid, duplikater hoppes over med `wasRecentlyCreated = false`. Dette skiller modellen fra pivot-tabellen `enterprise_wiki_ingest_run_pages`, som er per-run historikk.

#### Fase 8E-17 — Enterprise Wiki lint / quality checks for applied runs — Fullført

**Commit:** (se nedenfor)

Implementert:
- Migrasjon: `2026_07_09_000002_add_run_version_metadata_to_enterprise_wiki_lint_findings_table.php` — legger til `enterprise_wiki_ingest_run_id` (nullable FK, nullOnDelete), `enterprise_wiki_page_version_id` (nullable FK, nullOnDelete), `metadata` (json nullable), og indeks `ewlf_ingest_run_idx` på eksisterende tabell `enterprise_wiki_lint_findings`.
- Modell `EnterpriseWikiLintFinding` — 16 nye kode-konstanter for 8E-17-sjekker (se liste nedenfor). `CODES`-array utvidet til 19 totalt. Nye fillable-felter og casts. Nye relasjoner `run()` og `version()`.
- `EnterpriseWikiAppliedRunLintService` (`app/Services/EnterpriseWiki/`) — ny service, skiller seg fra eksisterende `EnterpriseWikiLintService` (som ligger i `app/Services/Ai/Wiki/`). Ingen OpenAI-kall. Ingen innholdsgenerering. Leser eksisterende data og skriver `EnterpriseWikiLintFinding`-rader.
- Artisan-kommando `wiki:lint-applied-run --run-id=ID` — guard på `maintainer_decision_status = applied`. Full CLI-output med prefix `[WIKI_LINT]`.
- 33 tester. Ingen endring i claims, source references, page links, `ProcessEnterpriseWikiIngest` eller Kunnskapsbase/RAG.

**18 lint-sjekker fordelt på 5 kategorier:**

*Run-nivå (3):*
- `applied_run_without_pages` — run uten noen sider (error)
- `applied_run_without_article` — run uten article page (warning)
- `applied_run_without_summary` — run uten summary page (warning)

*Side/versjon (2):*
- `missing_current_version` — side uten current version (error)
- `empty_page_content` — current version med tom `content_markdown` (error)

*Claims (3, inkl. 2 gjenbrukte koder fra eksisterende lint):*
- `page_without_claims` — side med innhold men ingen claims (warning)
- `claim_missing_source` *(gjenbrukt)* — claim uten source reference (warning)
- `source_reference_missing_excerpt` *(gjenbrukt)* — source reference uten excerpt (warning)

*Source reference-integritet (2):*
- `source_reference_without_document` — source_id peker på ikke-eksisterende dokument (error)
- `source_reference_customer_mismatch` — dokument tilhører feil kunde (error)

*Lenker (8):*
- `page_without_outgoing_links` — side uten utgående lenker (warning)
- `page_without_incoming_links` — side uten innkommende lenker (warning)
- `article_without_summary_link` — article uten `article_to_summary`-lenke (warning)
- `summary_without_article_link` — summary uten `summary_to_article`-lenke (warning)
- `article_without_concept_or_entity_links` — article uten concept/entity-lenker (info)
- `orphan_concept_page` — concept uten lenker tilbake til article/summary (warning)
- `orphan_entity_page` — entity uten lenker tilbake til article/summary (warning)
- `missing_reverse_link` — utgående lenke mangler forventet reverslenke (warning)

**Design: idempotens og stale resolution.**
- Upsert-nøkkel: `{customer_id, enterprise_wiki_ingest_run_id, enterprise_wiki_page_id, enterprise_wiki_page_version_id, enterprise_wiki_claim_id, code}` — manuell query (ingen DB unique constraint, håndterer NULL-felt). Allerede åpen finding teller som skipped; resolved finding gjenåpnes og teller som created.
- Stale resolution: etter lint-passet lukkes alle åpne findings for denne run-id som ikke ble rørt i denne kjøringen. Findings fra `wiki:lint` (run_id = NULL) røres aldri.

#### Fase 8E-18 — Read-only Enterprise Wiki traversal UI — Fullført

**Commit:** `865ab24`

Implementert:
- `WikiController::show()` utvidet: `EnterpriseWikiPageTraversalService` injisert i konstruktøren. Nye Inertia-props: `page_type` (i `page`-objektet), `outgoing_links`, `incoming_links`, `related_articles`, `related_concepts`, `related_entities`, `lint_summary`. Alle data er customer-scoped via traversal-servicen.
- `WikiShow.jsx` utvidet:
  - `page_type`-badge i sideheader (ved siden av status-badge), med fargekoding per type (violet=article, sky=summary, teal=concept, orange=entity)
  - Ny **Navigasjon**-seksjon (alltid synlig, mellom artikkelinnhold og verifikasjon): viser klikkbare side-chips gruppert etter semantisk relasjon — sammendrag, kildeartikkel, konsepter, entiteter, relaterte artikler, baklenker. Chips har fargedot basert på page_type for visuell orientering.
  - `lint_summary` som kompakte tellere (errors + warnings) på "Verifikasjonsgrunnlag"-knappen, med grønt OK-badge når 0 funn.
- 17 nye i18n-nøkler i `lang/no/procynia.php` og `lang/en/procynia.php` (page types, traversal labels).
- 12 nye tester i `WikiControllerTest.php` — dekker page_type, outgoing/incoming links, related_*, lint_summary, customer scoping, tom-side-håndtering og no-side-effects.

**Traversal-navigasjon:**
- article → sammendrag (`summaryLinks` fra outgoing_links filtrert på page_type=summary)
- article → konsepter (`related_concepts`)
- article → entiteter (`related_entities`)
- summary → kildeartikkel (`articleLinks` fra outgoing_links filtrert på page_type=article)
- summary → konsepter/entiteter (`related_concepts` / `related_entities`)
- concept/entity → relaterte artikler (`related_articles`)
- alle → baklenker (`incoming_links`)

#### Fase 8E-19 — Enterprise Wiki graph data foundation — Fullført

**Implementeringscommit:** `1486007` — Add Enterprise Wiki graph data foundation (Phase 8E-19)

Implementert:
- `EnterpriseWikiGraphDataService` (`app/Services/EnterpriseWiki/`) — bygger stabilt grafpayload (nodes, edges, summary, scope) fra det kanoniske `EnterpriseWikiPageLink`-grafen. Tre scope-varianter:
  - **customer-wide** — alle sider og kanter for kunden (ingen parametre)
  - **run-scoped** — sider fra `enterprise_wiki_ingest_run_pages`-pivot for én applied run; kanter kun der begge endepunkter er i scope (`?run_id=`)
  - **neighborhood** — valgt side + direkte naboer (inn og ut); kanter mellom alle i dette settet (`?page_id=`, vinner over `run_id` ved konflikt)
- `WikiGraphDataController` (`app/Http/Controllers/App/`) — JSON-endepunkt; ugyldig `run_id` (ikke-applied run, ukjent, feil kunde) eller ugyldig `page_id` (ukjent, feil kunde) gir `422 Unprocessable Entity`
- Rute `GET /app/wiki/graph-data` lagt inn **foran** `/{slug}` i wiki-routegruppen
- Stabilt JSON-kontrakt:
  - **Nodes** = `EnterpriseWikiPage`-rader. Node-ID: `"page-{id}"`. Feltene: `page_id, slug, title, page_type, url, current_version_id, claim_count, source_reference_count, lint_error_count, lint_warning_count, status` (error/warning/ok)
  - **Edges** = `EnterpriseWikiPageLink`-rader. Edge-ID: `"link-{id}"`. Feltene: `link_id, source, target, from_page_id, to_page_id, link_type, confidence`
  - Summary: `node_count, edge_count, article_count, summary_count, concept_count, entity_count, lint_error_count, lint_warning_count, orphan_count`
  - Scope: `type, run_id, page_id`
- Alle telleoperasjoner er bulk-aggregert (ingen N+1)
- Helt read-only — ingen skriving, ingen OpenAI
- 39 tester i `tests/Feature/App/Wiki/WikiGraphDataControllerTest.php` — dekker: authentication, customer-wide shape, node-payload, edge-payload, summary, run-scoped (inklusive scope-validering), neighborhood (inklusive en-hopp-grense), scope-prioritet (page_id vinner), customer-scoping og no-side-effects

**Avgrensninger (8E-19):**
- Ingen graph-UI ble bygget — endepunktet returnerer ren JSON, ingen React-komponent, ingen canvas/SVG/WebGL
- Ingen frontend-biblioteker ble lagt til (ikke sigma, graphology, d3, cytoscape, react-force-graph)
- Ingen layout-algoritme i backend
- Graph-UI er et bevisst utsatt neste steg

#### Fase 8E-20 — Read-only Enterprise Wiki Graph View UI — Fullført

**Implementeringscommit:** `dd071f6` — Add Enterprise Wiki graph view UI (Phase 8E-20)

Implementert:
- `WikiGraphController` (`app/Http/Controllers/App/`) — Inertia-endepunkt, sender `initialRunId` og `initialPageId` som props fra query params
- Rute `GET /app/wiki/graph` lagt inn i wiki-routegruppen (etter `/graph-data`, før `/{slug}`)
- `Graph.jsx` (`resources/js/Pages/App/Wiki/`) — read-only Sigma.js + Graphology graph view:
  - Henter data fra `GET /app/wiki/graph-data` (8E-19 endpoint) via `fetch()` i browser
  - Bygger Graphology-graf fra JSON-payload
  - Kjører ForceAtlas2 layout i browser (150 iterasjoner, `inferSettings`)
  - Rendrer med Sigma v3 — ingen backend layout-algoritme
  - Nodes = `EnterpriseWikiPage`, Edges = `EnterpriseWikiPageLink`
- Fargekoding av noder per `page_type`: article (violet), summary (sky), concept (teal), entity (orange)
- Nodestørrelse basert på `page_type` + grad (antall koblinger)
- Lint-status-ring i tegnforklaring (error/warning/ok)
- FilterPanel: page type-avkryssing, status-avkryssing, vis/skjul orphans
- SummaryPanel: viser `summary`-feltet fra graph-data-payload
- NodePanel (sidepanel ved klikk): title, page_type, status, claim_count, source_reference_count, lint_error_count, lint_warning_count, "Åpne side"-lenke
- Zoom og pan via Sigma innebygd; "Tilpass visning" knapp kaller `animatedReset()`
- Scopes: customer-wide (default), `?run_id=` (applied run), `?page_id=` (neighborhood) — `page_id` vinner over `run_id` i tråd med 8E-19
- Tom wiki / alle filtrert bort / 422 fra ugyldig scope — viser brukerforståelig feilmelding
- "Grafvisning"-knapp lagt til på Wiki index-siden
- 32 nye i18n-nøkler (wiki.graph_*) i `lang/no/procynia.php` og `lang/en/procynia.php`
- 13 tester i `tests/Feature/App/Wiki/WikiGraphControllerTest.php`

**Frontend-pakker (ny):**
- `sigma@3.0.3`
- `graphology@0.26.0`
- `graphology-layout-forceatlas2@0.10.1`

**Avgrensninger (8E-20):**
- Read-only — ingen editor, ingen AI-kall, ingen godkjenningsflyt, ingen ny linkbygging, ingen ny lint-kjøring
- Ingen produksjonsaktivering, ingen admin/Filament-endringer, ingen billing
- Ingen 3D-graf — 2D ForceAtlas2 i browser
- Ingen backend layout-algoritme
- `GET /app/wiki/graph-data` er fortsatt den eneste datakilden for grafen

### Fase 8F — Enterprise Wiki forvaltning av kilder, kjøringer og generert innhold

Fase 8F gir System Owner verktøy til å forvalte hele Enterprise Wiki-livssyklusen: navigere mellom flater via tabs, søke og filtrere wiki-sider, slette kildedokumenter trygt, følge kjøringshistorikk, lese kvalitetsfunn og redigere innhold som ny versjon. Godkjenningsflyten (submit/approve/reject) er allerede implementert i Fase 3B og berøres ikke.

**Masterdata-prinsipp:**
- `EnterpriseWikiDocument` er master for opplastede råkilder.
- `EnterpriseWikiPage` er avledet innhold generert fra ett eller flere kildedokumenter.
- Sletting skjer primært fra kildedokumenter — ikke fra enkelt-genererte sider.
- Sletting av et kildedokument kaskader til sole-source sider og rydder opp i all avledet data.

**Grenser — røres ikke i 8F:**
- `KnowledgeItem`, `KnowledgeItemVersion`, `KnowledgeItemChunk`
- Dagens Kunnskapsbase/RAG-pipeline
- Billing
- Admin/Filament
- AI workspace
- `ProcessEnterpriseWikiIngest`

#### Fase 8F-0 — Docs/plan — Fullført

Teknisk plan dokumentert og committet til `docs/enterprise-llm-wiki-plan.md`.

#### Fase 8F-1 — Tab-navigasjon og splitting av /app/wiki — Fullført (commit e9360fa)

**Resultat:** `/app/wiki` er delt i fire tabs via URL-param `?tab=X`. Grafvisning lenker videre til `/app/wiki/graph`. Backend er tab-bevisst og ugyldig tab faller trygt tilbake til `pages`. 607 tester bestått / 1288 assertions. Build OK.

| Tab | URL | Primærdata |
|---|---|---|
| Wiki-sider | `?tab=pages` (default) | `EnterpriseWikiPage` |
| Kildedokumenter | `?tab=sources` | `EnterpriseWikiDocument` med handlinger |
| Kjøringer | `?tab=runs` | `EnterpriseWikiIngestRun` — read-only |
| Kvalitet | `?tab=quality` | `EnterpriseWikiLintFinding` — read-only |
| Grafvisning | `/app/wiki/graph` | Lenke i tab-linja |

`WikiController::index()` laster kun data for aktiv tab. `lint_health` alltid beregnet (tab-badge). 15 eksisterende sources-tester oppdatert til `?tab=sources`. 7 nye tab-navigasjonstester lagt til.

Ikke-scope: Ingen pipeline-handlingsknapper i Kjøringer-tab — det er Fase 8F-4.

#### Fase 8F-2 — Søk og filtrering — Fullført (commit 101885a)

**Resultat:** Wiki-sider-tab har fritekstsøk (title/slug, case-insensitive), `page_type`-filter, `status`-filter (begrenset til rollebaserte synlige statuser), lint-filter (errors/warnings/ok via `whereHas`/`whereDoesntHave`), sortering og paginering (25 per side). Kildedokumenter-tab har søk i `original_filename` og `document_status`-filter. Alle filtre i URL-parametre; ugyldige verdier ignoreres trygt. 629 tester bestått / 1332 assertions. Build OK.

Kjøringer-tab og Kvalitet-tab fikk ikke filter i 8F-2 — utsatt til eventuell separat fase.

#### Fase 8F-3 — Trygg sletting av EnterpriseWikiDocument

**Implementeringscommit:** c7b3853
**Status:** Fase 8F-3 fullført

**Oppsummering:**
- `EnterpriseWikiDocument` kan nå slettes trygt fra Kildedokumenter-tabben
- Preview-steg (`GET /sources/{doc}/delete-preview`) viser antall kjøringer, sole-source-sider og delte sider — ingen sletting skjer
- Slettemodal krever eksplisitt bekreftelse etter preview
- Sole-source pages og tilhørende Enterprise Wiki-data (claims, versjoner, lint findings) slettes via DB-cascade i transaksjon
- Delte concept/entity-sider beholdes intakt
- Source references som peker på slettet dokument fjernes fra alle sider
- In-progress runs (`queued`/`running`/`sections_planned`) blokkerer sletting
- `canDelete`-sjekk i frontend utvidet til `!isInProgress` (ikke lenger begrenset til dokumenter uten genererte sider)

**Cascade-rekkefølge i transaksjon:**
1. Lint findings for sole-source pages
2. Lint findings for dokumentets runs
3. Lint findings direkte koblet til dokumentet
4. Source references som peker på dette dokumentet (på alle siders claims)
5. Sole-source pages slettes → DB-cascade: claims, source refs, versjoner, page links, run_pages
6. Ingest sections og runs
7. Fil fra Storage
8. Dokumentet

**Tester:** 642 passed / 1384 assertions
**Build:** OK

#### Fase 8F-4 — Kjøringshistorikk — Fullført (commit c275bb3)

**Status:** Fase 8F-4 fullført

Kjøringer-tabben viser nå `EnterpriseWikiIngestRun`-historikk med kildefil, status, maintainer decision-status, side-/section-/lint-counts og tidspunkt. Filter på `run_status`, `run_decision` og `run_src` er lagt til. Kildedokumenter-tabben lenker til filtrert kjøringshistorikk per dokument via `?tab=runs&run_src={id}`.

Implementert:
- Backend: `loadRunsTab` returnerer `pages_count`, `sections_count`, `lint_count` (correlated subquery), `source_document_filename`, `finished_at` og aktive filter-verdier (`runs_filters`)
- URL-baserte filtere: `run_status`, `run_decision` (pending/applied/none), `run_src` (document-ID) — ugyldige verdier ignoreres stille
- Frontend: 9-kolonne-tabell med filterbar (status-dropdown, beslutning-dropdown, aktiv-kilde-chip med slett-knapp, nullstill-knapp)
- 7 tester: customer-scoping, counts, alle filtertyper, ugyldig-filter-sikkerhet, `runs_filters`-prop

Tester: 650 passed / 1400 assertions · Build: OK

#### Fase 8F-5 — Read-only Kvalitet-tab — Fullført (commit b32502a)

**Status:** Fase 8F-5 fullført

Kvalitet-tabben viser nå åpne `EnterpriseWikiLintFinding`-rader read-only med severity, check_type, melding, side, sidetype, kildefil, run_id og dato. Filter på severity, check_type og page_type er lagt til via URL-parametre. Ugyldige filterverdier ignoreres stille. Customer-scope beholdes.

Implementert:
- Backend: `loadQualityTab` tar imot `Request`, filtrerer på `q_severity`, `q_code`, `q_page_type`; eager-loader `page` (med `page_type`) og `run` (med `source_id`); løser opp kildefilnavn via `EnterpriseWikiDocument`; returnerer `quality_filters`-prop
- Frontend: 8-kolonne-tabell med filterbar (severity, sjekk-kode, sidetype + nullstill); nye kolonner: sidetype, kildefil, kjørings-ID (lenke til kjøringer-tab); `WikiIndex` tar imot og sender `quality_filters` til `QualityTab`
- 8 tester: severity-filter, kode-filter, sidetype-filter, ugyldig-filter-sikkerhet, `quality_filters`-prop, page_type + run_id eksponering, tom tilstand, gjest redirect

Tester: 658 passed / 1416 assertions · Build: OK

---

#### Retningsnotat etter 8F-0–8F-5 {#8F-retning}

**Fase 8F-1 til 8F-5 utgjør den komplette forvaltnings- og kontrollflaten** for Enterprise Wiki i piloten: tab-navigasjon, wiki-sider med søk og filter, kildedokumenter med opplasting og trygg sletting, kjøringshistorikk med filtre, og read-only kvalitetstab.

**Viktig prinsipp:** Enterprise Wiki skal normalt vedlikeholdes *automatisk* av Karpathy-pipeline — ikke som et manuelt CMS. Maintainer-jobben er å laste opp kildedokumenter, observere resultatet, og godkjenne/avvise — ikke å redigere wikiinnhold linje for linje. Redigering og regenerering (8F-6 og 8F-7) er unntaksfunksjoner for feilretting, ikke hoveddriften.

**Neste hovedretning etter 8F-5:** coverage/eval og continuous maintainer loop:
- Evalueringsramme: automatisk måling av hvor stor andel av wikisidene som har godkjent innhold, fullstendige claims og lav lint-score
- Continuous maintainer loop: automatisk kjøring av pipeline på nye og endrede kildedokumenter, med beslutningsforslag klart for System Owner-godkjenning
- Disse fasene er **ikke startet** — de avklares og spesifiseres separat

**Fase 8F-6 og 8F-7 er parkert som unntaksfunksjoner.** De implementeres ikke som en del av normaldriften. Avklares separat ved behov.

#### Fase 8F-6 — Redigering som ny EnterpriseWikiPageVersion — Parkert

> **Parkert.** Enterprise Wiki vedlikeholdes automatisk av pipeline, ikke manuelt. Redigering er en unntaksfunksjon for feilretting. Implementeres ikke uten eksplisitt instruksjon.

Foreløpig scope (for fremtidig referanse):
- System Owner kan redigere `content_markdown` på en eksisterende wiki-side
- Redigering oppretter alltid ny `EnterpriseWikiPageVersion` — eksisterende versjon overskrives aldri
- Approved-sider flyttes til `draft` etter redigering og må godkjennes på nytt

#### Fase 8F-7 — Regenerering / ny dokumentversjon — Parkert

> **Parkert.** Scope avklares separat. Implementeres ikke uten eksplisitt instruksjon.

Foreløpig scope (for fremtidig referanse):
- Laste opp revidert versjon av eksisterende kildedokument og trigge ny pipeline-kjøring
- Krever datamodell-audit og mulig migrasjon

---

**Teststrategi for 8F:**
Alle backend-tester er feature-tester. Ingen Cypress/E2E. Ingen ekte OpenAI-kall. `KnowledgeItem`/`KnowledgeItemVersion`/`KnowledgeItemChunk` verifiseres urørt etter sletteoperasjoner.

### Fase 8G — Enterprise Wiki coverage/eval

> **8G-1–8G-7 fullført — 8H (continuous maintainer-loop) gjenstår — start ikke uten instruksjon.**

Mål: automatisk måling av wiki-dekning og innholdskvalitet som et **QA-signal til maintaineren** — ikke et brukerrettet dashboard. Coverage validerer at forventede wiki-artefakter er opprettet og brukbare etter en ingest. Systemet identifiserer avvik; maintainer-loopen (8H) håndterer reparasjon.

#### QA som arkitektonisk senter

Post-ingest QA er **completion gate** for alle applied ingest-kjøringer. QA er delt i tre nivåer med ulike ansvarsområder.

**Nivå 1 — Teknisk QA** *(8G-3, fullført)*
- Applied run finnes og er korrekt applied
- Article og summary finnes via lineage
- Current version finnes for forventede sidetyper
- `content_markdown` er ikke tomt
- Nødvendige tjenester (coverage, lint) kan kjøres uten unntak

**Nivå 2 — Strukturell QA** *(del av 8G-3, fullført)*
- Claims, source references og page links er konsistente
- Lineage er sporbar gjennom Document → IngestRun → run_pages → Page
- Lint-funn med `severity = error` blokkerer godkjenning
- Coverage-summary lagres i `qa_result`

**Nivå 3 — Semantisk QA** *(8G-4/8G-5, fullført)*
- Article og summary vurderes mot `EnterpriseWikiDocument.extracted_text`
- Sentrale temaer og fakta fra originalkilden er dekket
- Generert innhold inneholder ikke påstander uten støtte i kilden
- Viktige krav, begreper, aktører eller avhengigheter er ikke utelatt
- Summary er en dekkende komprimering av kildens hovedinnhold

**Presisering (etter 8G-4):** `qa_status = passed` krever at *alle tre nivåene* er bestått — teknisk, strukturell og semantisk QA. Semantisk QA er obligatorisk når `ENTERPRISE_WIKI_AI_ENABLED=true`; uten at AI er aktivert kan `passed` ikke oppnås.

**Fullstendig flyt (implementert i 8G-5):**

```
raw source (EnterpriseWikiDocument.extracted_text)
→ generer article + summary + concept/entity-sider
→ teknisk og strukturell QA (8G-3)
→ semantisk AI-QA mot originalkilden (8G-4)
  → diagnose: quality_score, coverage_score, unsupported_claims, missing_topics
    → ved avvik: målrettet revisjon med QA-diagnose som tilleggsinstruks (8G-5)
      → re-kjør semantisk QA
        → passed: qa_status = semantisk godkjent
        → fortsatt feil: eskalert til System Owner
→ daglige snapshots og trendhistorikk (8G-6)
→ maintainer loop (8H) → kontinuerlig kildemonitoring
```

En applied run regnes ikke som ferdig før all QA er bestått. Runs som er `escalated` eller `failed` krever eksplisitt operator-handling (`--retry`) eller 8H-basert intelligens.

#### Strategisk ramme: Coverage som QA-prosess

Coverage er primært en kvalitetskontroll for Enterprise Wiki-genereringen, ikke en manuell arbeidsliste for kunden.

**Prinsipp 1 — QA-prosess, ikke dashboard:**
Coverage måler om genereringen har levert det den skal. En ingest anses ikke som komplett før de genererte wiki-artefaktene har bestått nødvendige QA-kontroller.

**Prinsipp 2 — Forventede artefakter:**
Coverage validerer at følgende er opprettet og brukbare per kildedokument:
- `article`-side med gjeldende versjon og innhold
- `summary`-side med gjeldende versjon og innhold
- `concept`- og `entity`-sider der kildedokumentet tilsier det
- `claims` med `source_reference` og excerpt
- `page_links` (inn- og utgående)

**Prinsipp 3 — Lint + coverage = QA-lag:**
Lint og coverage utgjør til sammen QA-laget for Enterprise Wiki. Lint kontrollerer strukturell og semantisk korrekthet per side; coverage kontrollerer at ingest-kjøringen som helhet har levert forventet output.

**Prinsipp 4 — Maintainer-loopen reparerer, kunden ser helsestatus:**
Når QA avdekker avvik, skal Procynias maintainer-loop (8H) forsøke å:
1. identifisere årsaken (manglende artefakt, tom versjon, brutt lenke, osv.)
2. regenerere eller reparere innholdet automatisk
3. kjøre QA på nytt
4. eskalere til System Owner dersom automatisk reparasjon ikke lykkes

Kunden skal primært se:
- samlet helsestatus for wikien
- hva Procynia har reparert automatisk
- avvik som krever menneskelig vurdering

**Prinsipp 5 — Arkitektonisk retning, ikke nåværende ingest-kobling:**
Denne QA-modellen beskriver den arkitektoniske retningen for Enterprise Wiki. Den skal **ikke** implementeres ved å endre `ProcessEnterpriseWikiIngest` i denne fasen. Eventuell kobling mellom ingest og post-ingest QA skjer gjennom en separat orchestrator-fase etter 8G og 8H er etablert.

#### Premiss og grenser

Enterprise Wiki er ikke et manuelt CMS. Coverage/eval er et automatisk målesystem som viser tilstand, ikke en oppgaveliste. Eneste legitime UI-handlinger forblir: laste opp ny kilde, slette feil kilde, og godkjenne/avvise en side.

#### Datagrunnlag (eksisterende — ingen ny modell nødvendig for 8G-1)

Kilde-til-side-lineage følger denne kjeden:

```
EnterpriseWikiDocument
  → EnterpriseWikiIngestRun   (source_id = document.id, maintainer_decision_status)
    → enterprise_wiki_ingest_run_pages   (pivot: run_id → page_id, action)
      → EnterpriseWikiPage   (page_type, status, last_source_hash)
```

All nødvendig dekningsmåling kan gjøres via spørringer langs denne kjeden. Det er **ikke** aktuelt å legge til en `source_document_id` direkte på `EnterpriseWikiPage` — det ville modellert én-til-én der virkeligheten er mange-til-mange (en side kan over tid ha blitt berørt av kjøringer fra flere dokumenter). Eventuell fremtidig forbedring av lineage-sporbarhet bør være en eksplisitt many-to-many page-source lineage-modell, ikke en enkelt FK.

Øvrig tilgjengelig data: `EnterpriseWikiPageVersion` (is_current, content_markdown), `EnterpriseWikiClaim` (confidence, conflict_flag), `EnterpriseWikiSourceReference` (excerpt), `EnterpriseWikiLintFinding` (severity, code, status), `EnterpriseWikiPageLink` (link_type).

#### Om page_type og semantisk klassifisering

`page_type`-feltet (`article`, `summary`, `concept`, `entity`, `index`, `backlinks`) er **grove wiki-side-typer** som speiler Karpathy-layouten — ikke en komplett statisk kategorimodell. Verdiene dekker strukturell rolle, ikke semantisk innhold.

Senere semantisk klassifisering (f.eks. «HMS», «Referanser», «Sertifisering») må være fleksibel og ikke bindes til `page_type`-enumet. Naturlig løsning er metadata/tags eller et eget `semantic_kind`-felt — avklares separat og er ikke en del av 8G.

#### Hva coverage/eval måler

**Lag 1 — Kildedekning:** Har en applied kjøring produsert forventet output for hvert kildedokument? Minimum forventet: én `article` og én `summary` med `status = approved`. Mangler én: gap.

**Lag 2 — Side-kvalitet per side:**
- Har siden en current version med innhold?
- Antall claims, andel med source reference + excerpt (`claim_coverage_pct`)
- Åpne lint-funn (error/warning/info)
- Koblingsstatus (innkommende og utgående lenker via `EnterpriseWikiPageLink`)

Score per side (forslag):
```
grønn  = approved + 0 errors + claim_coverage ≥ 80%
gul    = approved + 0 errors + claim_coverage < 80%, eller draft/pending med 0 errors
rød    = errors > 0, eller approved uten claims, eller mangler current version
```

**Lag 3 — Graf-kohesjon:** Isolerte sider, manglende symmetriske lenker, orphan concept/entity-sider. Dekkes i stor grad av eksisterende lint-koder (`orphan_concept_page`, `missing_reverse_link`, `article_without_summary_link` osv.).

#### Del-faser

**8G-0 — Plan/kartlegging** *(denne fasen — fullført som samtale, ikke committet som kode)*
Teknisk kartlegging av datamodell, score-design og faseinndeling.

**8G-1 — Coverage service + CLI** ✅ *fullført — commit 6708a60 — 677 tester passerer — build OK*

`EnterpriseWikiCoverageService` beregner coverage on-demand fra eksisterende Enterprise Wiki-data. `wiki:coverage --customer=<id>` skriver lesbar CLI-rapport med fire dimensjoner:
- **Kildedekning:** extracted docs → applied runs → article/summary pages (gaps per dokument)
- **Sidekvalitet:** totalt, per page_type, per status, med/uten current version og content, med/uten claims
- **Claim coverage:** claims med/uten source reference, coverage-prosent
- **Lint og graf:** åpne funn per severity, orphan pages

Ingen nye tabeller, migrasjoner, UI eller AI-kall. Lineage via Document→IngestRun→run_pages→Page.

**8G-2 — Read-only coverage-visning i Kvalitet-tab** ✅ *fullført — commits c57ef78 / 8340324 / 0b45fe5 / a143a3e — 687 tester / 1500 assertions — build OK*

Kvalitet-tabben viser nå read-only coverage/eval basert på `EnterpriseWikiCoverageService`. `CoveragePanel` rendres øverst i Kvalitet-tab med fire seksjoner:
- **Kildedekning:** extracted docs, med applied run, artikkel/sammendrag-side opprettet, artikkel/sammendrag med gjeldende innhold
- **Sidekvalitet:** totalt, uten gjeldende versjon, uten innhold, uten claims
- **Claim-dekning:** dekningsgrad (terskelbasert farging: ≥ 80 % emerald, ≥ 50 % amber, < 50 % rose), med/uten kildereferanse
- **Graf og struktur:** åpne feil (rose), åpne advarsler (amber), foreldreløse sider (amber)

Gap-liste per dokument vises under statistikken. Positive helsetall vises nøytralt, faktiske avvik markeres med warning/error. Ingen nye ruter, ingen skrivehandlinger — kun utvidede props på eksisterende endepunkt.

#### Kildedekning — to lag (implementert i 0b45fe5)

Kildedekning skiller mellom strukturell dekning og innholdsdekning:

| Lag | Hva som sjekkes | Felter |
|---|---|---|
| Strukturell | article/summary-side finnes via lineage (Document → IngestRun → run_pages → Page) | `documents_with_article`, `documents_with_summary` |
| Innhold | siden har `is_current = true`-versjon med ikke-tomt `content_markdown` | `documents_with_article_content`, `documents_with_summary_content` |

En kilde med article/summary-side som mangler versjon eller innhold vises som gap — ikke som ok.

**Coverage-gap-typer (source_coverage.gaps[].missing):**

| Kode | Betyr |
|---|---|
| `applied_run` | Ingen applied kjøring for dokumentet |
| `article_missing` | Ingen article-side funnet via lineage |
| `article_missing_current_version` | Article-side finnes, men mangler `is_current`-versjon |
| `article_missing_content` | Article-side har versjon, men `content_markdown` er tomt |
| `summary_missing` | Ingen summary-side funnet via lineage |
| `summary_missing_current_version` | Summary-side finnes, men mangler `is_current`-versjon |
| `summary_missing_content` | Summary-side har versjon, men `content_markdown` er tomt |

Commit `a143a3e` presiserte labels fra «Artikkel med innhold» til «Artikkel med gjeldende innhold».

**8G-3 — Post-ingest QA-orchestrator** ✅ *fullført — commit 1c5f3a6 — 724 tester / 1587 assertions — build OK*

**Implementert:**

Migrasjon: `2026_07_10_000001_add_qa_fields_to_enterprise_wiki_ingest_runs_table.php` — legger til `qa_status`, `qa_started_at`, `qa_completed_at`, `qa_attempt_count`, `qa_last_error`, `qa_result` på `enterprise_wiki_ingest_runs`.

Filer:
- `app/Services/EnterpriseWiki/EnterpriseWikiPostIngestQaService.php` — QA-orchestrator
- `app/Jobs/EnterpriseWiki/RunPostIngestQa.php` — tynn queue-wrapper
- `app/Console/Commands/EnterpriseWikiRunPostIngestQa.php` — Artisan-kommando
- `routes/console.php` — scheduled command lagt til

**QA-flyt (faktisk implementert):**
```
applied run
→ atomic DB-claim → qa_status = running
  → sjekk article + summary eksisterer med current version og innhold
    → ved innholdsgap: qa_status = repair_required, kjør generate()
      → re-sjekk article + summary
  → kjør EnterpriseWikiAppliedRunLintService (skriv findings til DB)
  → kjør EnterpriseWikiCoverageService (customer-wide metrics)
  → sjekk åpne lint-feil med severity=error
    → ved innholdsgap ELLER åpne lint-feil → qa_status = escalated
    → ellers → qa_status = passed
  → lagre qa_result JSON med checks, repair, coverage, lint, open_lint_errors
```

**QA-status på `enterprise_wiki_ingest_runs`:**

| Verdi | Betydning |
|---|---|
| `null` | QA ikke kjørt ennå |
| `pending` | Eksplisitt markert for QA — behandles av scheduled polling |
| `running` | QA kjøres nå — atomic guard hindrer parallell kjøring |
| `passed` | Alle sjekker bestått, ingen åpne lint-feil med severity=error |
| `repair_required` | Transient tilstand — settes rett før generate()-kall, løses i samme run |
| `escalated` | Innholdsgap som ikke lot seg reparere, eller åpne lint-feil — krever menneskelig vurdering |
| `failed` | Uventet unntak under QA — krever eksplisitt retry |

**Triggermekanisme — scheduled polling (ingen observer):**

`ProcessEnterpriseWikiIngest` er urørt. QA er et separat lag som kjøres uavhengig:

```bash
# Scheduled: kjøres automatisk hvert 15. minutt via routes/console.php
wiki:run-post-ingest-qa --all-pending

# Enkelt run — manuell kjøring eller test
wiki:run-post-ingest-qa --run-id=<id>

# Eksplisitt retry av failed eller escalated
wiki:run-post-ingest-qa --run-id=<id> --retry
wiki:run-post-ingest-qa --all-pending --retry
```

**Scope for automatisk pickup (`--all-pending` uten `--retry`):**
- `qa_status IS NULL` — aldri kjørt
- `qa_status = pending` — venter
- `qa_status = repair_required` — stuck transient (prosess krasjet)

`failed` og `escalated` plukkes **ikke** opp automatisk — krever eksplisitt `--retry`.

**Lint-feil som gate:**

`passed` krever at det ikke finnes åpne `EnterpriseWikiLintFinding`-rader med `severity = error` for run etter at lint er kjørt. Lint-warnings lagres i `qa_result` men blokkerer ikke `passed`.

**Repair — én runde per QA-kjøring:**

`EnterpriseWikiGenerateAppliedPagesService::generate()` kalles én gang ved innholdsgap. QA sjekkes på nytt etter repair. Det er ingen sløyfe — mislykket repair gir direkte `escalated`.

**Kjent begrensning:**
`generate()` hopper over sider som allerede har en versjon, selv om `content_markdown` er tomt. En side med en eksisterende tom versjon kan ikke repareres automatisk av denne tjenesten og resulterer i `escalated`. Dette er en bevisst begrensning i første versjon — dypere repair av eksisterende versjoner er et 8H-ansvar.

**Avvik som ikke repareres i 8G-3:**
Claims, source references, page links og concept/entity-koblinger repareres ikke her. Semantiske feil i innholdet håndteres av semantisk QA og repair (8G-4/8G-5). Dypere strukturell reparasjon er et 8H-ansvar.

**Placement:**
- Service: `app/Services/EnterpriseWiki/EnterpriseWikiPostIngestQaService.php`
- Job: `app/Jobs/EnterpriseWiki/RunPostIngestQa.php`
- Kommando: `app/Console/Commands/EnterpriseWikiRunPostIngestQa.php`
- Queue: `enterprise-wiki` (eksisterende)
- Scheduled: `routes/console.php` → `everyFifteenMinutes()->withoutOverlapping()`

**Gjenbruk:**
- `EnterpriseWikiCoverageService` — dekningsberegning (customer-wide, lagres i qa_result)
- `EnterpriseWikiAppliedRunLintService` — lint-sjekker (skriver findings til DB)
- `EnterpriseWikiGenerateAppliedPagesService` — reparasjon av manglende/versjonløse sider

**8G-4 — Semantisk AI-QA mot originalkilden** ✅ *fullført — se commit nedenfor*

Semantisk QA er en AI-drevet gjennomgang av generert wikiinnhold mot `EnterpriseWikiDocument.extracted_text`. Målet er ikke å sjekke om article og summary er like — begge er genererte artefakter. Målet er å vurdere begge mot den autoritative originalkilden.

**`EnterpriseWikiDocument.extracted_text` som referanseramme:**

`extracted_text` er den primære referansen for semantisk QA. Article og summary er genererte artefakter som skal vurderes mot kilden, ikke mot hverandre. QA-reviewer bruker kilden, ikke de genererte sidene, som fasit.

**Reviewer-rollen:**

QA-reviewer-agenten:
- Mottar `extracted_text` fra `EnterpriseWikiDocument` og `content_markdown` fra gjeldende `EnterpriseWikiPageVersion`
- Vurderer innholdet kritisk mot originalkilden
- Returnerer en strukturert diagnose — skriver ikke direkte til gjeldende sideversjon
- Er en separat komponent fra generator og repair/reviser

**Strukturert semantisk QA-resultat:**

| Felt | Type | Innhold |
|---|---|---|
| `pass` | bool | Om innholdet består semantisk QA |
| `quality_score` | float 0–1 | Samlet kvalitetsvurdering |
| `coverage_score` | float 0–1 | Andel av kildens hovedinnhold som er dekket |
| `factual_consistency_score` | float 0–1 | Andel av påstander med støtte i kilden |
| `unsupported_claims` | array | Konkrete påstander uten kildedekning |
| `missing_topics` | array | Viktige temaer fra kilden som mangler |
| `missing_key_facts` | array | Konkrete fakta, krav, aktører eller avhengigheter som er utelatt |
| `critique` | string | Fritekst QA-kommentar til generatoren |
| `recommended_repair_action` | enum | `none`, `targeted_revision`, `full_regeneration`, `escalate` |
| `confidence` | float 0–1 | Reviewerens sikkerhet på vurderingen |
| `model` | string | Modell og prompt-versjon brukt i vurderingen |
| `source_hash` | string | `file_hash_sha256` fra kilden på vurderingstidspunktet |
| `page_version_id` | bigint | `EnterpriseWikiPageVersion.id` som ble vurdert |

Databaseskjema er ikke låst i denne planoppdateringen. Sporbarhetsdata (`model`, `source_hash`, `page_version_id`) er påkrevd fra dag én.

**Triggere for videre reparasjon (til 8G-5):**

Semantisk repair trigges ved ett eller flere av følgende:
- Sentrale temaer fra kilden mangler i generert innhold
- Viktige fakta, krav, aktører eller avhengigheter er utelatt
- Generert innhold inneholder påstander uten støtte i kilden
- Summary dekker ikke kildens hovedinnhold tilstrekkelig
- `quality_score` eller `coverage_score` er under konfigurert terskel

**Implementert:**

Filer:
- `app/Services/Ai/Wiki/WikiSemanticQaAiClient.php` — AI-reviewer (modell: `gpt-4.1-mini`, JSON schema output, temperature 0)
- `app/Services/EnterpriseWiki/EnterpriseWikiSemanticQaService.php` — orkestrerer kildehenting og AI-kall
- `app/Services/EnterpriseWiki/EnterpriseWikiPostIngestQaService.php` — utvidet med semantisk QA-lag

QA-resultater lagres i `qa_result` på `enterprise_wiki_ingest_runs` under nøkkelen `semantic_qa`.

Semantisk QA kjøres kun når `ENTERPRISE_WIKI_AI_ENABLED=true` og tech/strukturell QA har passert. Dersom AI ikke er aktivert når strukturell QA har bestått, settes `qa_status = failed` med en forklarende `qa_last_error`. En kjøring kan **ikke** nå `passed` uten at semantisk QA faktisk er gjennomført.

Statusmapping:
- `pass = true` → `passed`
- `pass = false`, action `targeted_revision`/`full_regeneration` → `repair_required`
- `pass = false`, action `escalate` eller kilde mangler/er tom → `escalated`

Tester: `tests/Feature/App/Wiki/EnterpriseWikiSemanticQaServiceTest.php` — 16 tester, 51 assertions.

**8G-5 — Målrettet critique/revise-reparasjon og re-evaluering** ✅ *fullført*

**Repair/reviser-rollen:**

Repair/reviser-agenten:
- Mottar originalkilden (`extracted_text`), eksisterende `content_markdown` og QA-diagnosen fra 8G-4
- Lager en forbedret versjon av innholdet, målrettet mot de konkrete manglene i diagnosen
- Kjøres med QA-diagnosen som tilleggsinstruks — ikke med identisk prompt som første generering
- Bruker `gpt-5` (samme modell som generator)
- Skriver ny `EnterpriseWikiPageVersion` (immutable — eksisterende versjon bevares med `is_current=false`)

**Critique/revise-loop (implementert i `executeQa()`):**

```
Semantisk QA (8G-4) → diagnose
  → pass=true → qa_status = passed
  → pass=false, action = targeted_revision / full_regeneration
      → WikiSemanticReviserAiClient::revise() med diagnose som tilleggsinstruks
          → ny EnterpriseWikiPageVersion opprettes (atomisk transaksjon)
          → re-kjør alle tre nivåer (tech + strukturell + semantisk QA)
              → passed → qa_status = passed
              → feil etter repair → qa_status = escalated (ingen ny repair-runde)
      → repair mislykkes (graceful) → qa_status = escalated
  → pass=false, action = escalate → qa_status = escalated (ingen repair)
```

**Maks én revisjon per QA-syklus:**

Maksimalt **én** målrettet revisjon per QA-kjøring. `resolvePostRepairStatus()` returnerer aldri `repair_required` — resultatet etter repair er alltid `passed` eller `escalated`. Mekanismen er designbasert (ikke en teller): repair-blokken kjøres kun én gang innen `executeQa()`.

**Sporingsnøkler i `qa_result`:**

| Nøkkel | Innhold |
|---|---|
| `semantic_qa` | Opprinnelig semantisk QA-diagnose (8G-4) — bevares uendret |
| `semantic_repair_attempted` | `true` dersom 8G-5-reparasjon ble forsøkt |
| `semantic_repair_result` | `{success, page_id, page_version_id, previous_version_id, model, reason}` |
| `semantic_qa_post_repair` | Semantisk QA-resultat etter reparasjon, eller `null` ved strukturell feil |

**Rolleoversikt (alle fire roller):**

| Rolle | Ansvar |
|---|---|
| Generator (`WikiPageContentAiClient`) | Lager første versjon av article/summary (8G-3) |
| QA/reviewer (`WikiSemanticQaAiClient`) | Vurderer innholdet kritisk mot originalkilden, returnerer strukturert diagnose (8G-4) |
| Repair/reviser (`WikiSemanticReviserAiClient`) | Mottar diagnose + kilden + eksisterende innhold, produserer forbedret versjon (8G-5) |
| Orchestrator (`EnterpriseWikiPostIngestQaService`) | Styrer rekkefølge, begrenser forsøk, kjører QA på nytt, eskalerer |

**Implementerte filer:**
- `app/Services/Ai/Wiki/WikiSemanticReviserAiClient.php` — ny AI-klient (gpt-5, prompt_version 1.0)
- `app/Services/EnterpriseWiki/EnterpriseWikiSemanticRepairService.php` — ny repair-service
- `app/Services/EnterpriseWiki/EnterpriseWikiPostIngestQaService.php` — utvidet med 8G-5-blokk og `resolvePostRepairStatus()`
- `tests/Feature/App/Wiki/EnterpriseWikiSemanticRepairServiceTest.php` — ny testfil (18 tester, 98 assertions)

**Graceful failure-koder (repair returnerer `success=false`):**

| Kode | Årsak |
|---|---|
| `repair_action_not_repairable` | Action er `escalate` eller annet ikke-repererbart |
| `source_type_not_supported` | Kun `enterprise_wiki_document` støttes |
| `source_document_not_found` | Kildedokument finnes ikke i DB |
| `source_text_empty` | `extracted_text` er tom |
| `article_version_not_found` | Ingen gjeldende artikkelversjon funnet |
| `article_content_empty` | Artikkelinnhold er tomt |

Alle graceful failures → `qa_status = escalated`. AI-exceptions propagerer til `runForRun()` catch → `qa_status = failed`.

**8G-6 — Snapshots og trendhistorikk**

> **Fullført (commit se nedenfor). Per-run QA-snapshots implementert.**

**Implementert design — per-run snapshots (ikke daglige aggregater):**

8G-6 lagrer ett uforanderlig snapshot per avsluttet QA-forsøk (terminal status: `passed`, `failed`, `escalated`). Snapshots opprettes av `EnterpriseWikiQaSnapshotService` og kalles fra `EnterpriseWikiPostIngestQaService` etter at `qa_status` er satt. Feil i snapshot-oppretting er isolert med try/catch og logger, og skjuler aldri QA-resultatet.

**Filer:**
- `database/migrations/2026_07_10_000002_create_enterprise_wiki_qa_snapshots_table.php`
- `app/Models/EnterpriseWikiQaSnapshot.php`
- `app/Services/EnterpriseWiki/EnterpriseWikiQaSnapshotService.php`
- `app/Services/EnterpriseWiki/EnterpriseWikiPostIngestQaService.php` (utvidet med `snapshotService`)

**Tabell: `enterprise_wiki_qa_snapshots`**
- Unik constraint: `(enterprise_wiki_ingest_run_id, qa_attempt_count)` — idempotens per forsøk
- Indekser: `(customer_id, snapshotted_at)`, `(customer_id, qa_status)` — trendspørringer
- Teknisk/strukturell: `technical_qa_passed`, `structural_qa_passed`, `open_lint_errors`, `lint_error_count`, `lint_warning_count`
- Semantisk QA (8G-4): `semantic_qa_ran`, `semantic_pass`, `semantic_quality_score`, `semantic_coverage_score`, `semantic_factual_score`, `semantic_missing_topics_count`, `semantic_missing_key_facts_count`, `semantic_unsupported_claims_count`, `semantic_source_hash`, `semantic_page_version_id`, `semantic_model`, `semantic_prompt_version`
- Repair (8G-5): `semantic_repair_attempted`, `semantic_repair_success`, `semantic_repair_previous_version_id`, `semantic_repair_new_version_id`, `semantic_repair_model`
- Post-repair re-evaluering: `semantic_post_repair_pass`, `semantic_post_repair_quality_score`, `semantic_post_repair_coverage_score`, `semantic_post_repair_factual_score`

**Når opprettes snapshot:**
- Etter AI-deaktivert early-return (`failed`)
- Etter normal QA-fullføring (`passed`, `escalated`, `failed`)
- Ved uventet exception i `runForRun()` catch-blokk (`failed`)
- `repair_required` er en mellomtilstand — oppretter ikke eget snapshot

**Idempotens:**
`firstOrCreate`-mønster via eksplisitt `first()` + `create()`. Nytt QA-forsøk (retry) inkrementerer `qa_attempt_count` og oppretter nytt snapshot med ny `qa_attempt_count`.

**Tester:** `tests/Feature/App/Wiki/EnterpriseWikiQaSnapshotServiceTest.php` — 14 tester, 76 assertions.

**8G-7 — Lineage-forbedring**

> **Fullført.**

**Lukket gap 1 — 8G-5 brukte `is_current`-oppslag i stedet for diagnostisert versjon:**

`EnterpriseWikiSemanticRepairService::repair()` brukte tidligere et nytt `is_current = true`-oppslag for å finne article-versjonen som skulle revideres. Dette betyr at repair-tjenesten potensielt bearbeidet en annen versjon enn den 8G-4 faktisk vurderte. Fikset ved å bruke `$semanticQaDiagnosis['page_version_id']` som autoritativ referanse. Dersom IDen mangler eller versjonen ikke finnes, returneres et graceful failure-resultat (`diagnosed_version_id_missing` / `diagnosed_version_not_found`).

**Lukket gap 2 — Post-repair-versjonen ikke registrert i QA-snapshot:**

`EnterpriseWikiQaSnapshot` manglet feltet `semantic_post_repair_page_version_id` — versjons-IDen som re-evalueringen (8G-4 kjøring 2) faktisk vurderte. Lagt til via migrasjon, oppdatert modell, og `buildAttributes()` i `EnterpriseWikiQaSnapshotService`.

**Eksisterende lineage (allerede på plass — ikke endret):**

Kjeden `originalkilde → ingest-run → side → sideversjon → semantisk QA → revisjon → ny sideversjon → re-evaluering → QA-snapshot` var allerede sporbar via:
- `EnterpriseWikiIngestRun.source_id` + `source_type` → `EnterpriseWikiDocument`
- `EnterpriseWikiIngestRunPage`-pivot → `EnterpriseWikiPage`
- `qa_result['semantic_qa']['page_version_id']` — diagnostisert versjon
- `qa_result['semantic_qa']['source_hash']` — kildedokumentets hash
- `qa_result['semantic_repair_result']['previous_version_id']` — versjon sendt til revisjon
- `qa_result['semantic_repair_result']['page_version_id']` — ny versjon etter revisjon
- `qa_result['semantic_qa_post_repair']['page_version_id']` — re-evaluert versjon
- `EnterpriseWikiQaSnapshot.semantic_page_version_id` — diagnostisert versjon i snapshot
- `EnterpriseWikiQaSnapshot.semantic_repair_previous_version_id` + `semantic_repair_new_version_id`
- `EnterpriseWikiQaSnapshot.semantic_post_repair_page_version_id` ← **ny (8G-7)**

**Filer:**
- `database/migrations/2026_07_10_000003_add_post_repair_version_to_qa_snapshots.php`
- `app/Models/EnterpriseWikiQaSnapshot.php`
- `app/Services/EnterpriseWiki/EnterpriseWikiQaSnapshotService.php`
- `app/Services/EnterpriseWiki/EnterpriseWikiSemanticRepairService.php`
- `tests/Feature/App/Wiki/EnterpriseWikiLineageTest.php` — 13 tester, 66 assertions

#### Grunnlaget for 8H — og grensen mot 8G-3/8G-4/8G-5

**Hva 8G-3 allerede gjør (ikke gjenta i 8H):**
- QA-statusmaskin (`qa_status`) med atomic claim-guard
- Innholdssjekker: article + summary eksisterer med current version og innhold
- Coverage-beregning (customer-wide) og lint (per-run) lagret i `qa_result`
- Enkel reparasjon: én runde med `generate()` ved manglende/versjonløs side
- Re-sjekk etter repair, deretter eskalering hvis gap gjenstår
- Lint-feil (`severity = error`) som gate — blokkerer `passed`
- Eksplisitt retry-mekanisme: `--retry` for `failed`/`escalated`
- Scheduled polling hvert 15. minutt for `null`/`pending`/`repair_required`

**Hva 8G-4 og 8G-5 skal legge til (ikke dekket av 8G-3):**
- Semantisk vurdering av generert innhold mot `extracted_text` (8G-4): quality_score, coverage_score, unsupported_claims, missing_topics
- Diagnosebasert repair med QA-critiquen som tilleggsinstruks (8G-5): ikke blind re-generering
- Ny `EnterpriseWikiPageVersion` ved revisjon — ikke overskriving av eksisterende versjon
- Begrensning til én automatisk revisjon; eskalering ved vedvarende avvik

**Hva 8H skal legge til (ikke dekket av 8G-3 gjennom 8G-5):**
- Kontinuerlig kildemonitoring: detektere at et kildedokument er endret (`file_hash_sha256` vs `last_source_hash`) og trigge ny ingest
- Intelligent beslutning om når og om det er verdt å retry `escalated`-kjøringer (når kilden er endret, ikke automatisk)
- Dypere reparasjon av claims, source references, page links og concept/entity-koblinger
- Terskel- og trendbaserte beslutninger basert på historiske snapshots (avhenger av 8G-6)

**Avhengigheter:**

| Lag | Avhenger av |
|---|---|
| 8H-kjerne (kildemonitoring, endringsdeteksjon, triggert ingest/QA) | 8G-3 og 8G-5 — teknisk/strukturell + semantisk QA er grunnlag for meningsfull 8H-reparasjon |
| 8H-utvidelse (terskelbasert repair, regresjonsdeteksjon) | 8G-6 — snapshot-historikk nødvendig for trendbaserte beslutninger |

**8H-loopen (arkitektonisk mål etter 8G-5):**
```
ny eller endret kildedokument oppdaget
  → trigger ny ingest → ProcessEnterpriseWikiIngest (uendret)
    → teknisk og strukturell QA (8G-3) → qa_status
    → semantisk AI-QA mot originalkilden (8G-4) → diagnose
      → ved avvik: targeted revision (8G-5) → re-QA
        → passed: logg, oppdater snapshot (8G-6)
        → escalated: 8H vurderer dypere reparasjon
            → reparasjon av claims/links/source references
              → re-kjør QA
                → passed: logg reparasjon, oppdater snapshot
                → fortsatt feil: eskaler til System Owner med forklaring
```

Kunden ser kun sluttresultatet: hva ble reparert, og hva krever menneskelig vurdering. Selve reparasjonslogikken er intern og kobles **ikke** til `ProcessEnterpriseWikiIngest`.

### Fase 8H — Continuous Enterprise Wiki Maintainer Loop

> **8H-kjerne delfase 1 fullført.** 8H-kjerne delfase 2 (dypere reparasjon) og 8H-utvidelse gjenstår — start ikke uten instruksjon.

Mål: automatisk pipeline-kjøring ved nye eller endrede kildedokumenter, med ferdig beslutningsforslag klart for System Owner-godkjenning — fra manuell pilot til operasjonelt system.

#### 8H-kjerne delfase 1 — Kildemonitoring + intelligent retry (fullført)

Implementert: kildemonitoring og intelligent retry-beslutning for `escalated` kjøringer.

**Deteksjonssignal:** `EnterpriseWikiDocument.file_hash_sha256` ≠ `EnterpriseWikiIngestRun.maintenance_source_hash`

**Idempotens:** `maintenance_source_hash` settes på kjøringen *før* retry-kallet. Samme kildehash trigges ikke mer enn én gang — neste forsøk skjer kun når dokumentet endrer seg igjen.

**Nye felt på `enterprise_wiki_ingest_runs`:**
- `maintenance_triggered_at` — tidsstempel for siste maintenanceforsøk
- `maintenance_source_hash` — kildehash benyttet ved siste maintenanceforsøk (idempotensgaranti)

**Nye filer:**
- `app/Services/EnterpriseWiki/EnterpriseWikiMaintenanceCycleService.php`
- `app/Console/Commands/EnterpriseWikiMaintenanceCycle.php` (`wiki:maintenance-cycle`)
- `database/migrations/2026_07_10_000004_add_maintenance_fields_to_enterprise_wiki_ingest_runs.php`
- `tests/Feature/App/Wiki/EnterpriseWikiMaintenanceCycleTest.php` (9 tester, 28 assertions)

**Scheduler:** `wiki:maintenance-cycle` kjøres hvert 30. minutt med `withoutOverlapping()`.

**Kjørelogikk:**
1. Finn alle applied kjøringer med `qa_status = escalated` og `source_type = enterprise_wiki_document`
2. For hver: hent `file_hash_sha256` fra kilddokumentet
3. Hopp over hvis hash er null eller uendret siden siste maintenance
4. Sett `maintenance_source_hash` og `maintenance_triggered_at`, kall `runForRun($run->fresh(), retry: true)`
5. Feil ved QA-retry: logg og teller som `failed` — masker ikke feilen, og lar neste syklus forsøke igjen

#### 8H-kjerne delfase 2 — Dyp reparasjon av claims, source references og page links (fullført)

Implementert: diagnose og målrettet reparasjon av strukturelle wiki-komponenter etter at intelligent retry fortsatt gir `escalated`.

**Deteksjon:** diagnosen sjekker per run:
- Claims: sider med current version men ingen claims → kaller `EnterpriseWikiExtractPageClaimsService::extract()`
- Source references: claims uten kildereferanse → kaller `EnterpriseWikiVerifyPageClaimsService::verify()`
- Page links: sider uten outbound-lenker → kaller `EnterpriseWikiBuildPageLinksService::build()`

Alle tre tjenester er idempotente — de fyller kun inn det som mangler.

**Idempotens:** `deep_repair_source_hash` settes på kjøringen *før* reparasjon. Samme kildehash gir maksimalt ett forsøk — neste forsøk er mulig kun etter at kilden endres igjen.

**QA etter reparasjon:** full post-ingest QA kjøres på nytt via `runForRun(retry: true)`, inkludert teknisk QA, lint, semantisk AI-QA og snapshot.

**Resultat:** `passed`, `escalated` (fortsatt avvik) eller `failed` (teknisk feil).

**Sporbarhet:** `deep_repair_result` (JSON) lagres på kjøringen med `attempted`, `components_repaired`, `qa_status`. QA-snapshot opprettes av re-evalueringsrunden.

**Nye felt på `enterprise_wiki_ingest_runs`:**
- `deep_repair_attempted_at` — tidsstempel for dyp reparasjonsforsøk
- `deep_repair_source_hash` — kildehash benyttet (idempotensgaranti)
- `deep_repair_result` — JSON med diagnose og resultat

**Nye filer:**
- `app/Services/EnterpriseWiki/EnterpriseWikiDeepRepairService.php`
- `database/migrations/2026_07_10_000005_add_deep_repair_fields_to_enterprise_wiki_ingest_runs.php`
- `tests/Feature/App/Wiki/EnterpriseWikiDeepRepairServiceTest.php` (15 tester, 47 assertions)

**Oppdaterte filer:**
- `app/Services/EnterpriseWiki/EnterpriseWikiMaintenanceCycleService.php` — kaller `deepRepairService->attempt()` hvis run fortsatt er `escalated` etter intelligent retry
- `app/Models/EnterpriseWikiIngestRun.php` — tre nye felt i `$fillable` og `$casts`

#### 8H-utvidelse *(avhenger av 8G-6):*
- Terskelbasert repair-beslutning: trigger reparasjon når coverage-score faller under historisk terskel
- Regresjonsdeteksjon: fang fall i aggregert wikihelse etter nye ingest-kjøringer
- Historikkbasert eskalering: eskaler til System Owner med trendkontekst, ikke bare øyeblikksbilde

Roadmap-rekkefølge: 8G-3 (fullført) → 8G-4 (semantisk AI-QA) → 8G-5 (critique/revise) → 8G-6 (snapshots) → 8G-7 (lineage) → 8H-kjerne delfase 1 (fullført) → 8H-kjerne delfase 2 → 8H-utvidelse.

### Produksjonsaktivering — Etter 8G og 8H

> **Ikke aktiv fase.** Produksjonsaktivering skjer etter at coverage/eval (8G) og continuous maintainer loop (8H) er implementert og verifisert. Eksisterende runbook fra Fase 7 brukes som teknisk grunnlag.

Krav (uendret fra Fase 7-runbook):
- `ENTERPRISE_WIKI_AI_ENABLED=true` aktiveres kontrollert
- første produksjonsbruk med kort dokument
- reversering via `ENTERPRISE_WIKI_AI_ENABLED=false`

### Fase 9 — Sammenligning mot dagens RAG
- Parallell visning: wiki-svar vs. RAG-svar for samme requirement
- Bruker kan velge foretrukket svar og gi tilbakemelding
- Metrikker: kildedekning, konfidensmerking, brukerpreferanse

### Fase 10 — Vurdering av wiki som svargrunnlag
- Evaluere om wiki-claims kan brukes som supplement eller erstatning for embedding-søk
- Vurdere hybrid retrieval: wiki-oppslag + RAG
- Beslutning basert på data fra fase 5
- Ingen endring av eksisterende RAG uten separat ADR

---

## Appendix A — Modellreferanser

### A.1 Midlertidige read-only referanser (bootstrap/import — Flyt A)

Disse eksisterende modellene leses kun av `wiki:ingest`-kommandoen (Flyt A). De er **ikke** del av den ønskede primærflyten og skal ikke legges til i ny Wiki-kode.

| Modell | Felt som leses | Bruk |
|---|---|---|
| `KnowledgeItemVersion` | `extracted_text`, `approval_status`, `is_current`, `original_filename`, `file_hash_sha256` | Bootstrap-ingest (midlertidig — se §3.1) |
| `KnowledgeItem` | `ai_usage_enabled`, `title`, `customer_id` | Tilgangskontroll og visningsnavn for bootstrap |

### A.2 Alltid-tilgjengelige modeller

| Modell | Felt som leses | Bruk |
|---|---|---|
| `Customer` | `id`, `language` | Kundeisolasjon og språkvalg |
| `User` | `role`, `bid_role` | Rollestyring |

### A.3 Egne Wiki-modeller (primærflyt — Fase 4A)

| Modell | Tabell | Bruk |
|---|---|---|
| `EnterpriseWikiDocument` | `enterprise_wiki_documents` | Primærkilde etter Fase 4A — ingen FK mot Kunnskapsbase |

---

## Appendix B — AI-promptdesign og maintainer-kontrakter (retningslinjer)

### B.1 v0.5-prinsipp

Wiki-generering skal ikke stoppe ved claim extraction eller én article prompt.

AI-output skal være strukturert og validerbar, men må styre en Karpathy-kompatibel compile-flyt:

- `schema` — regler for page types, navngiving, lenking, ingest/compile, query og lint
- `index_context` — eksisterende sider som kan oppdateres
- `compile_decision` — hvilke sider skal oppdateres/opprettes, eller hvorfor ingen handling gjøres
- `source_article` — full writeup for råkilden
- `source_summary` — kort TL;DR for råkilden
- `concept_entity_updates` — felles sider som oppdateres/opprettes på tvers av kilder
- `claims` — verifikasjonsgrunnlag
- `source_coverage` — hvilke kilder/seksjoner som støtter endringen
- `warnings` — usikkerheter, konflikter eller hull modellen oppdaget

Claims er ikke sluttproduktet. De støtter review, kildekontroll og lint.

### B.2 Compile decision — ønsket strict JSON-format

Eksempel på ønsket overordnet output for beslutningslaget:

```json
{
  "compile_decision": {
    "source_article": {
      "action": "create_or_update",
      "title": "Menneskelig tittel avledet fra kilden, ikke filnavn",
      "proposed_slug": "menneskelig-tittel",
      "reason": "Hver råkilde får en full source writeup."
    },
    "source_summary": {
      "action": "create_or_update",
      "title": "Kort sammendrag: Menneskelig tittel",
      "proposed_slug": "menneskelig-tittel-summary",
      "max_words": 200
    },
    "update_existing_pages": [
      {
        "page_id": 123,
        "page_type": "concept",
        "reason": "Kilden inneholder ny informasjon som hører hjemme på eksisterende concept-side.",
        "change_type": "extend"
      }
    ],
    "create_new_pages": [
      {
        "page_type": "concept",
        "title": "Tematisk sidetittel avledet fra innholdet",
        "reason": "Ingen eksisterende side dekker dette temaet.",
        "proposed_slug": "tematisk-sidetittel"
      }
    ],
    "no_action_reason": null,
    "warnings": []
  }
}
```

Regler:
- `source_article` og `source_summary` er normalt forventet for en gyldig ny råkilde.
- `title` må være innholdsavledet og menneskelig.
- `title` må ikke være blind kopi av source filename.
- `proposed_slug` må ikke ende på dokumentextension.
- Én kilde kan returnere både `update_existing_pages` og `create_new_pages`.
- Tom beslutning må ha `no_action_reason`.

### B.3 Page-output per side

Eksempel på ønsket output per sideversjon:

```json
{
  "page": {
    "page_type": "article",
    "title": "Menneskelig sidetittel",
    "summary": "Kort sammendrag av hva siden dekker.",
    "markdown": "## Oversikt\n\nSammenhengende wikiartikkel...",
    "concepts": ["hms", "referanseprosjekter"],
    "links": ["[[hms]]", "[[referanseprosjekter]]"]
  },
  "claims": [
    {
      "text": "Én konkret, verifiserbar påstand.",
      "confidence": "high",
      "excerpt": "Relevant tekstutdrag fra kilden.",
      "conflict_note": null
    }
  ],
  "source_coverage": [
    {
      "source_label": "source.docx",
      "covered": true,
      "notes": null
    }
  ],
  "warnings": []
}
```

### B.4 Page-regler

Alle sider skal:

- skrives på kundens språk
- være Markdown
- ha tydelige overskrifter
- bruke `[[wikilinks]]` bare til sider som finnes eller opprettes i samme compile-run
- ha sammenhengende brødtekst der page type krever det
- syntetisere overlappende kildetekst
- ikke inneholde fakta som ikke støttes av kildene
- ikke nevne at den er AI-generert i selve wikiinnholdet
- ikke inneholde HTML-kommentarer
- ikke inneholde `Kilde:`/`Source:`/`Ref:`-dump i artikkelteksten
- kunne leses uten at brukeren åpner claim-listen

Spesifikke regler:

| Page type | Regler |
|---|---|
| `article` | Full writeup for én råkilde. Kan lenke til concepts/entities. |
| `summary` | Kort TL;DR for én råkilde, normalt maks 200 ord. |
| `concept` | Deduplisert tematisk side på tvers av kilder. Skal oppdateres inkrementelt. |
| `entity` | Navngitt kunde, leverandør, produkt, person, system eller prosjekt. |
| `index` | Katalog over article/summary/concept/entity gruppert etter tema. |
| `backlinks` | Systemgenerert lenkeoversikt. Ikke manuelt redigert. |

### B.5 Claim-/verifikasjonsregler

Claims skal:

- være konkrete og verifiserbare
- ha `confidence`
- ha `excerpt`
- ha `conflict_note` ved konflikt
- brukes til review, ikke som artikkelens primære layout

### B.6 Teststrategi

Automatiserte tester skal bruke mock/fake OpenAI-klient.

Tester skal dekke:

- compile decision med source article
- compile decision med source summary
- compile decision med update existing concept/entity page
- compile decision med create new concept/entity page
- ingen blind filnavnbruk som wiki title/slug
- én kilde kan påvirke flere sider
- gyldig page-output JSON for `article`
- gyldig page-output JSON for `summary`
- gyldig page-output JSON for `concept`
- `page.markdown` lagres som `content_markdown`
- claims lagres med source references
- manglende `page.markdown` gir feil
- claims uten excerpt håndteres i verifikasjonslaget
- ugyldig output med HTML-kommentarer eller source-dump avvises
- broken wikilinks oppdages av lint
- orphan/stub pages rapporteres av lint
- backlinks kan bygges/rebygges
- ingen ekte nettverkskall i tester
- Kunnskapsbase/RAG-tabeller røres ikke

