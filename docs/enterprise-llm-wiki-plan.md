# Enterprise LLM Wiki — Arkitektur- og implementeringsplan

Versjon: 0.5
Dato: 2026-07-08
Status: Infrastruktur fullført (Fase 0–4B) · AI-integrasjon fullført (Fase 5) · Lokal E2E verifisert (Fase 6) · Produksjonsrunbook fullført (Fase 7, aktivering utsatt) · Article-first UI/fullført start (Fase 8D, commits 94f6541 og 94f5721) · Backend artikkelgenerering teknisk implementert (Fase 8C, commits 956206d, 5029cb0 og 4ea8fb6) · **Neste: Fase 8E Karpathy-alignment — page types/schema/index/log/backlinks/compile decision**

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
| **Fase 8E** | **Karpathy-alignment: page types/schema/index/log/backlinks/compile decision** | **Neste steg** |
| Fase 8F | Review og godkjennings-UX | Gjenstår — etter Fase 8E |
| Fase 8G | Kontrollert produksjonsaktivering | Gjenstår — sist |
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

### Fase 7 — Produksjonsrunbook — Fullført (aktivering utsatt til Fase 8G)

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

### Fase 8F — Review og godkjennings-UX for wiki-maintainer-output

Mål: reviewer godkjenner tematisk wikiinnhold etter at riktig maintainer-flyt finnes.

Krav:
- godkjenning knyttes til riktig sideversjon
- verifikasjonslag støtter beslutningen
- åpne lint `error` skal vurderes før godkjenning
- reviewer skal kunne se hvilke kilder som endret hvilke sider
- ikke auto-publiser

### Fase 8G — Kontrollert produksjonsaktivering — Gjenstår til etter Fase 8F

> **Ikke neste steg.** Produksjonsaktivering skjer først etter at Fase 8E–8F er gjennomført og wiki-maintainer-opplevelsen er verifisert.

Mål: bruk eksisterende runbook fra Fase 7 kontrollert i produksjon.

Krav:
- `ENTERPRISE_WIKI_AI_ENABLED=true` aktiveres etter at maintainer-flyten fungerer
- første produksjonsbruk med kort dokument
- queue restart/config clear er del av runbook (se Fase 7)
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

