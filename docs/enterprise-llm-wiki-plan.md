# Enterprise LLM Wiki — Arkitektur- og implementeringsplan

Versjon: 0.4
Dato: 2026-07-08
Status: Infrastruktur fullført (Fase 0–4B) · AI-integrasjon fullført (Fase 5) · Lokal E2E verifisert (Fase 6) · Produksjonsrunbook fullført (Fase 7, aktivering utsatt) · Article-first UI startet (Fase 8D) · Artikkelgenerering implementert (Fase 8C, commits 956206d–4ea8fb6) · **v0.4 korrigering: «claims → én artikkel» er for smalt. Neste steg: schema/index/log-audit og minste grunnlag for maintainer workflow.**

> **Arkitekturkorrigering (v0.2):** Enterprise Wiki skal være et fullstendig parallelt system uten avhengighet av Kunnskapsbase eller RAG-pipeline. Dagens `KnowledgeItemVersion`-baserte ingest er midlertidig bootstrap/import og regnes **ikke** som permanent primærflyt. Se §3, §7 og Fase 4A for korrekt langsiktig arkitektur.
>
> **Målbildekorrigering (v0.3):** Enterprise Wiki skal ikke være en claim-liste. Den skal være en Karpathy-inspirert, Markdown-first LLM Wiki: raw sources inn, lesbare wikiartikler ut. Claims, excerpts, source references, lint og helsekontroll er verifikasjonsgrunnmur rundt artikkelen — ikke selve sluttproduktet. Phase 4A/4B/1F/1G/1H ga nødvendig infrastruktur, men **Wiki Article Layer mangler** og må bygges før produksjonsaktivering.
>
> **Arkitekturkorrigering (v0.4):** Fase 8C implementerte «claims fra én kilde → én AI-generert artikkel per kilde». Det er en gyldig teknisk byggestein, men modellen er konseptuelt for smal. Riktig Karpathy-modell er et *wiki maintainer*-system: raw sources er immutable, wiki pages er tematiske entiteter (ikke en-til-en med kildedokumenter), én kilde kan oppdatere N relevante sider, og et schema/instruction-lag styrer page types, navngiving og lenkestruktur. Index og log er sentrale komponenter som mangler. Se §v0.4 for full kartlegging og anbefalt neste steg.

## 1. Formål

Enterprise LLM Wiki er et parallelt kunnskapssystem for Procynia, inspirert av Karpathy LLM Wiki-metoden, men tilpasset enterprise-krav: kundescoping, rollestyrt godkjenning, sporbar revisjon, kildebevis og trygg menneskelig review.

Det sentrale produktet er **lesbare wikiartikler**, ikke claim-lister.

Systemet skal fungere som et kompilert kunnskapslag:

```text
raw source documents
→ extraction
→ article generation
→ readable Markdown wiki pages
→ verification layer: claims, excerpts, source references, lint and health checks
→ human review and approval
```

Systemet er ikke en erstatning for dagens Kunnskapsbase eller RAG-pipeline. Det er et separat wiki-lag som:

- tar inn Enterprise Wiki-egne kilder uten å endre Kunnskapsbase/RAG
- bruker AI til å generere sammenhengende, strukturerte wikiartikler i Markdown
- lager påstander, excerpts og kildehenvisninger som kontrollgrunnlag for artikkelen
- lar menneskelige brukere lese, vurdere, redigere, godkjenne eller avvise innhold
- gjør godkjent kunnskap lett å lese, søke, lenke til og vedlikeholde over tid
- først i senere faser kan sammenlignes mot dagens RAG — uten automatisk kobling

Kjerneprinsipper:

- **Wikiartikkel først.** Brukeren skal møte en lesbar side med overskrifter, sammendrag og brødtekst.
- **Verifikasjon som støtte.** Claims, excerpts, kildekoblinger og lint skal støtte review — ikke være hovedopplevelsen.
- AI foreslår. Mennesker godkjenner.
- Ingen sentrale påstander uten kildegrunnlag.
- Ingen skrivetilgang til eksisterende Kunnskapsbase/RAG-modeller eller tabeller.
- Kundeisolasjon fra dag én.

Konsekvens av v0.3-korrigeringen:

- Eksisterende claim extraction beholdes som grounding/review-lag.
- Eksisterende lint/helsekontroll beholdes.
- Eksisterende upload/extract/ingest-grunnmur beholdes.
- Dagens claim-baserte visning er **ikke** ferdig wiki-opplevelse.
- Neste hovedspor er å bygge **Wiki Article Layer**.

## v0.4 Korrigering: Wiki Maintainer-modell

**Dato:** 2026-07-08

Fase 8C implementerte `WikiArticleAiClient` og `content_markdown`-generering. Det er teknisk velfungerende kode. Men den underliggende modellen — *én kilde produserer én wikiartikkel* — er konseptuelt for smal og stemmer ikke med Karpathy/LLM Wiki-modellen.

> **Viktig bugnotat (verifisert under E2E-test 2026-07-08):** `gpt-5` støtter ikke `temperature`-parameteren i Responses API. Kjøring med `temperature: 0.3` gir HTTP 400. `WikiArticleAiClient::buildPayload()` må fjerne `temperature`-feltet. Dette er en linje kode — men er ikke del av dette docs-steget.

### Hva Karpathy-modellen faktisk er

```text
raw sources (immutable input)
  → wiki maintainer AI
      leser: source content + existing page index
      beslutter: hvilke sider oppdateres? hvilke nye sider opprettes?
  → for hver berørt side:
      henter: eksisterende content_markdown + relevante kildeseksjoner
      genererer: oppdatert Markdown-artikkel
  → logger: hvilke sider ble endret av hvilken kilde (ingest run log)
  → lint: contradictions mellom sider, orphan pages, stale content, manglende kryssreferanser
  → query: leser index + relevante sider — ikke rådokumenter
```

Sentrale egenskaper som skiller dette fra dagens modell:

- **Raw sources er immutable.** `EnterpriseWikiDocument.extracted_text` endres aldri etter ekstraksjon.
- **Wiki pages er tematiske entiteter — ikke kopier av kildedokumenter.** En side heter «ISO-sertifiseringer», «Nøkkelpersoner», «Referanseprosjekter» — ikke «selskapsinfo.docx». Sidetittelen er begrep, ikke filnavn.
- **Schema/instruction-lag definerer reglene.** Hva slags sider finnes? Hvilke felt er påkrevd? Hvordan lenkes sider til hverandre? Dette er config/instruksjon som AI-maintainer følger — ikke kode.
- **Index gjør AI-maintainer orientert.** Før AI beslutter om en kilde skal opprette ny side eller oppdatere eksisterende, leser den en oversikt over eksisterende sider med titler, slug, temaer og korte summarier.
- **Én kilde kan påvirke N sider.** Et selskapsdokument kan oppdatere «Kompetanseprofil», «ISO-sertifiseringer» og «Referanseprosjekter» i samme ingest-run.
- **Log dokumenterer kilde→side-endringer.** Hvilke sider ble opprettet eller oppdatert av hvilken kilde og ingest-run.
- **Lint finner systemnivåfeil — ikke bare per-side.** Contradictions mellom sider, orphan pages uten lenker, stale content og manglende kryssreferanser.
- **Query leser index og wiki-sider, ikke rådokumenter.** Brukere og AI-svar-generering starter fra wiki, ikke fra uploaded sources.

### Hva eksisterende kode kan gjenbrukes

| Komponent | Status | Gjenbruk |
|---|---|---|
| `EnterpriseWikiDocument` | Implementert | Immutable raw source — riktig konsept, beholdes som-er |
| `EnterpriseWikiPage` + `EnterpriseWikiPageVersion` | Implementert | Tematisk side + versjonering — riktig datamodell |
| `content_markdown` på `EnterpriseWikiPageVersion` | Implementert | Riktig felt for Markdown-artikkelinnhold |
| `WikiArticleAiClient` | Implementert (Fase 8C) | Kan gjenbrukes for per-side generering — ingest-løkken bygges rundt den |
| `EnterpriseWikiClaim` + `enterprise_wiki_source_references` | Implementert | Verifikasjonslag — beholdes |
| `enterprise_wiki_ingest_runs` | Implementert | Log-grunnlag per ingest-run — kan utvides til å dekke hvilke sider som ble berørt |
| `enterprise_wiki_lint_findings` | Implementert | Per-side helsekontroll — kan utvides til cross-page lint |
| `enterprise_wiki_page_topics` | Implementert | Kan inngå i page index |
| `ProcessEnterpriseWikiIngest` / `ProcessEnterpriseWikiSection` / `FinalizeEnterpriseWikiIngest` | Implementert | Orchestrator/seksjonsmønster kan gjenbrukes; finalize må bygges ut for multi-page routing |

### Hva som mangler

| Manglende komponent | Beskrivelse | Blokkerer |
|---|---|---|
| **Schema/instruction-lag** | Definisjon av page types (entity, concept, source summary), navngiving, felt og lenkekonvensjoner. Config/instruksjon som maintainer-AI følger. | Riktig tematisk routing |
| **Page index** | Søkbar oversikt over eksisterende sider (tittel, slug, topics, summary) som maintainer-AI leser før ingest-beslutning. Kan baseres på `enterprise_wiki_pages` + `enterprise_wiki_page_topics`. | Index-awareness |
| **Ingest decision logic** | «Hvilke eksisterende sider er relevante for denne kilden?» + «Trenger noen nye sider opprettes?» Returnerer (update/create, side-id) per berørt side. | Multi-page routing |
| **Multi-page ingest** | Én ingest run → N sider. Dagens pipeline: én run → én side. Krever endring i orchestrator og finalize. | Karpathy-modell |
| **Log per (run, page)** | Dokumenterer hvilke sider som ble opprettet/oppdatert av hvilken kilde. Kan implementeres som pivot-tabell eller utvidelse av `enterprise_wiki_ingest_runs`. | Sporbarhet |
| **Query-flyt** | Brukerflyt og AI-flyt som starter fra wiki-index og relevante sider — ikke fra rådokumenter. | Wiki som svargrunnlag |
| **Cross-page lint** | Finner contradictions mellom sider, orphan pages og manglende kryssreferanser (utover dagens per-side-lint). | Wiki-konsistens |

### Anbefalt neste steg

**Ikke neste steg:**
- Review/approval UX
- Produksjonsaktivering
- Mer prompt-polering av `WikiArticleAiClient`

**Anbefalt neste steg (i rekkefølge):**

1. **Schema/instruction-audit** (docs) — definer hvilke page types som skal finnes for en typisk Procynia-kunde. Eksempel: `company_overview`, `certifications`, `key_personnel`, `reference_projects`, `service_portfolio`, `domain_expertise`. Hva er navngivingsregelen? Hva lenkes til hva? Ingen kode i dette steget.

2. **Index-vurdering** (docs/kode) — vurder om `enterprise_wiki_pages` (tittel, slug) + `enterprise_wiki_page_topics` (emner) er tilstrekkelig som page index for maintainer-AI, eller om et `summary`-felt eller eget indeks-format trengs. Minimal kodeendring.

3. **Minste kodeendring for multi-page routing** — kan orchestratoren returnere N side-targets fra én source, og finalize skrive til N sider? Vurder om det krever ny pivot-tabell `enterprise_wiki_ingest_run_pages` eller kun en utvidelse av `enterprise_wiki_ingest_runs.enterprise_wiki_page_id` til en-til-mange-relasjon.

4. **Log-design** — er én ny pivottabell `enterprise_wiki_ingest_run_pages` (run_id, page_id, action: created|updated) tilstrekkelig som log? Eller skal `enterprise_wiki_ingest_runs` beholdes som primær log-entitet med N side-referanser?

Trinn 1 og 2 er ren docs/design. Trinn 3 og 4 er smal arkitekturvurdering — ingen kode og ingen migrasjoner i dette steget.

---

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

### 3.2 Ønsket primærflyt (Fase 4A + v0.3 — Enterprise Wiki egne kilder og artikkellag)

Enterprise Wiki skal ha sin **egen** upload/import-flyt, fullstendig uavhengig av `KnowledgeItem`, `KnowledgeItemVersion` og `KnowledgeItemChunk`.

Korrigert målarkitektur etter v0.3:

```text
Enterprise Wiki source upload/import
→ Enterprise Wiki extraction (tekstekstraksjon fra docx/pdf)
→ Enterprise Wiki section planning
→ Article generation (lesbar Markdown-artikkel)
→ Verification extraction (claims, excerpts, source references)
→ Lint/health checks
→ Enterprise Wiki page pending_review
→ Human review and approval
```

Primærkilder i Fase 4A:

- `EnterpriseWikiDocument` — egen modell, ingen FK mot Kunnskapsbase
- `source_type = 'enterprise_wiki_document'` på `enterprise_wiki_source_references`
- `source_type = 'enterprise_wiki_document'` på `enterprise_wiki_ingest_runs`

Viktig skille:

- **Artikkelen** er hovedproduktet og lagres som `content_markdown` på `enterprise_wiki_page_versions`.
- **Claims** er et verifikasjonslag knyttet til artikkelen.
- **Source references** dokumenterer hvor claims og sentrale artikkelutsagn kommer fra.
- **Lint/helsekontroll** hjelper reviewer å finne mangler før godkjenning.

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

Representerer én wiki-side, scoped til én kunde. En side tilsvarer ett faglig tema (f.eks. «HMS-kompetansekrav», «Referanseprosjekter innen bygg», «Sikkerhetsgodkjenning»).

| Felt | Type | Beskrivelse |
|---|---|---|
| `id` | bigint PK | |
| `customer_id` | bigint FK → customers | Kundeisolasjon |
| `department_id` | bigint FK nullable | Avdelingsscoping (valgfritt i pilot) |
| `slug` | varchar | URL-vennlig identifikator, unik per kunde |
| `title` | varchar | Sidetittel |
| `scope` | enum | `company`, `domain`, `project` |
| `status` | enum | Se §6 |
| `owner_user_id` | bigint FK nullable | Ansvarlig redaktør |
| `generated_by` | enum | `ai_job`, `manual` |
| `last_source_hash` | varchar nullable | SHA-256 av kildedokumenter brukt — oppdager utdatert innhold |
| `reviewed_at` | timestamp nullable | |
| `reviewed_by_user_id` | bigint FK nullable | |
| `archived_at` | timestamp nullable | |
| `created_at`, `updated_at` | timestamps | |

---

### 4.2 `enterprise_wiki_page_versions`

Immutable versjonering. Ny versjon opprettes alltid — eksisterende versjoner overskrives aldri. Dette er den autoritative lagringen av selve wikiartikkelen.

| Felt | Type | Beskrivelse |
|---|---|---|
| `id` | bigint PK | |
| `enterprise_wiki_page_id` | bigint FK | |
| `version_number` | integer | Monotont stigende per side |
| `is_current` | boolean | Kun én current per side |
| `content_markdown` | text | Full lesbar Markdown-artikkel for siden — primær wiki-opplevelse |
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
| `source_type` | enum | `knowledge_item_version`, `saved_notice_document`, `doffin_notice`, `manual` |
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
| `enterprise_wiki_page_id` | bigint FK nullable | Kobling til side (null hvis ny side ikke ble opprettet) |
| `trigger_type` | enum | `manual`, `schedule`, `source_change` |
| `source_type` | enum | `knowledge_item_version`, osv. |
| `source_id` | bigint | Hvilken kilde som ble brukt |
| `status` | enum | `queued`, `running`, `sections_planned`, `completed`, `failed` |
| `model_used` | varchar | |
| `input_tokens` | integer nullable | |
| `output_tokens` | integer nullable | |
| `cost_estimate_nok` | decimal nullable | |
| `error_message` | text nullable | |
| `started_at`, `finished_at` | timestamps nullable | |
| `created_at` | timestamp | |

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

1. **Wikiartikkel-laget** — primær visning: lesbar Markdown-side med tittel, sammendrag, seksjoner, avsnitt og struktur.
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

### 7.0 Status etter v0.3-korrigering

Eksisterende flyt har bygget en god verifikasjonsgrunnmur, men den produserer ikke en fullverdig Karpathy-inspirert wikiopplevelse. Dagens finalize-logikk assembler i praksis `content_markdown` fra claims. Det gir sporbarhet, men ikke en god wikiartikkel.

Korrigert mål:

- `ProcessEnterpriseWikiSection` kan fortsatt ekstrahere claims.
- Claims skal fortsatt lagres og brukes til review/lint.
- Det manglende laget er **article generation**: en strukturert, lesbar Markdown-artikkel som bruker kildematerialet og/eller claims som grunnlag.
- `/app/wiki/{slug}` skal vise artikkelen som hovedinnhold og claims som sekundær verifikasjon.

### 7.1 Flyt A: Bootstrap/import via Kunnskapsbase (midlertidig — Fase 1)

> **Status:** Implementert og fungerende. Regnes som midlertidig bootstrap — skal ikke promoteres til permanent primærflyt i UI. Se §3.1 for begrensningsbeskrivelse.
>
> **v0.3-korrigering:** Flyt A kan beholdes som legacy/import, men skal ikke definere målbildet for wikiopplevelsen.

```
1. Bruker velger en godkjent KnowledgeItemVersion
   └─ Filtert: approval_status = 'approved' AND is_current = true
   └─ Kunden må ha ai_usage_enabled = true

2. Manuell trigger: Artisan-kommando (php artisan wiki:ingest --customer=X --version-id=Y)
   └─ Oppretter enterprise_wiki_ingest_runs med status = queued

3. Orchestrator-jobb startes (ProcessEnterpriseWikiIngest)
   └─ Leser extracted_text fra KnowledgeItemVersion
   └─ Deler tekst i seksjoner
   └─ Oppretter draft EnterpriseWikiPage og EnterpriseWikiPageVersion
   └─ Oppretter enterprise_wiki_ingest_sections-rader
   └─ Dispatcher én ProcessEnterpriseWikiSection per seksjon

4. Per-seksjon-jobb
   └─ Ekstraherer claims og source references
   └─ Lagrer verifikasjonsgrunnlag

5. Finalisering
   └─ Dagens implementering: assembler content_markdown fra claims
   └─ Korrigert mål: content_markdown skal være en lesbar wikiartikkel
   └─ Claims/source references skal være review-lag rundt artikkelen

6. Ingen wiki-output kobles til eksisterende RAG, kravsvar eller AI workspace
```

### 7.2 Ønsket primærflyt: Enterprise Wiki egne kilder + Wiki Article Layer

```
1. Bruker laster opp dokument direkte i Enterprise Wiki
   └─ Egen upload-route: POST /app/wiki/sources
   └─ Oppretter EnterpriseWikiDocument
   └─ Lagrer fil, filnavn, SHA-256, customer_id

2. Tekstekstraksjon
   └─ Resultatet lagres på EnterpriseWikiDocument
   └─ Ingen skriving til Kunnskapsbase

3. Ingest startes fra UI
   └─ Oppretter enterprise_wiki_ingest_runs
       (source_type = 'enterprise_wiki_document', source_id = EnterpriseWikiDocument.id)

4. Seksjonsplanlegging
   └─ Deler kildetekst i håndterbare seksjoner
   └─ Beholder heading/section metadata for senere artikkelstruktur

5. AI-verifikasjonslag
   └─ Ekstraherer claims, excerpts og conflict_note per seksjon
   └─ Lagrer enterprise_wiki_claims og enterprise_wiki_source_references

6. AI-artikkellag
   └─ Genererer lesbar Markdown-artikkel fra kildetekst/claims
   └─ Artikkelen har tittel, sammendrag, seksjoner og sammenhengende brødtekst
   └─ Artikkelen skal ikke inneholde ubegrunnede påstander

7. Finalize
   └─ Lagrer Markdown-artikkelen i EnterpriseWikiPageVersion.content_markdown
   └─ Setter siden til pending_review
   └─ Knytter claims/source references/lint til siden som review-grunnlag

8. Review
   └─ Brukeren leser artikkelen
   └─ Brukeren kontrollerer claims/kilder/lint
   └─ System Owner godkjenner eller avviser
```

**Viktig prinsipp etter v0.3:** AI kan returnere strukturert JSON som inneholder både `article` og `claims`. `article.markdown` er wikiens hovedinnhold. `claims` er verifikasjonsgrunnlag. AI skal ikke levere uvalidert, fri tekst uten schema, men systemet må heller ikke redusere wikiopplevelsen til en claim-liste.

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
| `FinalizeEnterpriseWikiIngest` | `enterprise-wiki` | Slår sammen resultater, oppdaterer side og ingest-run |

Ny queue: `enterprise-wiki`. Holdes separat fra `ai-requirements` for å unngå ressurskonflikter med produksjonsflyt.

---

## 9. Lint og helsekontroll

Planlagte lint-kontroller som kan kjøres on-demand eller som scheduled job:

| Check-type | Alvorlighet | Beskrivelse |
|---|---|---|
| `claim_without_source` | error | Claim-rad mangler minst én source_reference |
| `page_without_approved_source` | error | Wiki-side er bygget fra en kilde som ikke lenger er `approved` |
| `conflicting_claims` | warning | To claims på samme side motsier hverandre (AI-flagget) |
| `source_version_superseded` | warning | Kildeversjon har `approval_status = superseded` |
| `stale_approved_page` | warning | Godkjent side, men kildens `file_hash_sha256` er endret siden `last_source_hash` ble beregnet |
| `orphan_page` | info | Wiki-side uten noen claims |
| `low_confidence_page` | info | Mer enn halvparten av claims har `confidence = low` eller `uncertain` |
| `missing_owner` | info | `owner_user_id` er null |
| `overdue_review` | warning | Godkjent side uten review de siste N dager (konfigurerbart) |
| `missing_topics` | info | Siden har ingen `enterprise_wiki_page_topics`-rader |

Lint-resultater lagres i `enterprise_wiki_lint_findings`. UI viser helseindikator per side.

---

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

### 10.2 Korrigert pilotmål etter v0.3

Det opprinnelige pilotmålet var for smalt. Å vise claims med kildelenker er ikke nok.

Korrigert pilotmål:

| Element | Korrigert mål |
|---|---|
| **Primær output** | Lesbar Markdown-wikiartikkel |
| **Sekundær output** | Claims, excerpts, kildereferanser og lint |
| **Primær UI** | Artikkelside som ligner en wiki |
| **Review UI** | Verifikasjonslag rundt artikkelen |
| **Godkjenning** | Godkjenning av artikkel, støttet av claim-/kildekontroll |
| **Produksjonsaktivering** | Først etter at artikkellaget fungerer |

Mål med neste pilot: verifisere at Enterprise Wiki produserer lesbare, nyttige, kildegrunnede wikiartikler som kan reviewes trygt av mennesker.

## 11. Åpne beslutninger

Disse spørsmålene må avgjøres før kode skrives:

1. **Navn i UI** — «Enterprise Wiki», «Kunnskapswiki» eller annet? Norsk eller engelsk i UI-strenger?

2. **Godkjenning: Hvem?** — Kun System Owner i pilot, eller også Bid Manager? Skal godkjenning per side være nok, eller kreves det godkjenning per enkeltpåstand?

3. **Avdelingsscoping fra start?** — Skal `department_id` brukes i pilot, eller kun selskapsbred wiki?

4. **Superadmin-tilgang** — Skal interne superadmins se kundewiki via Filament, eller holder det med produksjonsdata i Filament-logger?

5. **RAG-sammenligning** — Når og hvordan skal wiki-svar sammenlignes mot dagens RAG? Feature-flag, separat side, eller parallell visning i AI workspace?

6. **Kostnadsstyring** — Maksimalt antall tokens per ingest-run? Maksimalt antall sider per dag per kunde? Skal cost_estimate_nok vises i UI?

7. **Enkeltpåstand-godkjenning** — Skal brukere kunne godkjenne/avvise individuelle claims, eller kun hele siden?

8. **Kildeutdrag-lengde** — Hvor langt bør `excerpt` maksimalt være? (Forslag: 500 tegn)

9. **Automatisk re-ingest** — Skal wiki-sider automatisk trigge ny generering når kildeversjon oppdateres, eller kun manuelt i pilot?

10. **Konflikthåndtering** — Når `conflict_flag = true`: skal brukeren løse konflikten manuelt, eller skal AI foreslå sammenslåing?

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
| Fase 6 | Lokal E2E-verifisering | Fullført |
| Fase 7 | Produksjonsrunbook | Fullført — aktivering utsatt |
| Fase 8A | Article Layer Analyse | Fullført |
| Fase 8B | Artikkelgenerering-spesifikasjon | Fullført |
| Fase 8C | Backend artikkelgenerering | Fullført (commits 956206d–4ea8fb6) — se §v0.4 for korrigering |
| Fase 8D | Wiki Article UI | Startet (commit 94f5721) |
| Fase 8E | Review og godkjennings-UX | Utsatt — ikke neste steg, se §v0.4 |
| Fase 8F | Kontrollert produksjonsaktivering | Utsatt — sist, etter maintainer-modell er ferdig |
| **Fase 8M** | **Schema/index/log-audit og minste grunnlag for maintainer workflow** | **Neste steg (v0.4 korrigering)** |
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

### Fase 7 — Produksjonsrunbook — Fullført (aktivering utsatt til Fase 8E er ferdig)

Kjøreklar aktiveringsguide for produksjonsmiljø. Forutsetter at Fase 6 (lokal E2E-verifisering) er gjennomført og godkjent.

> **v0.3-korrigering:** Denne runbooken er beholdt som teknisk aktiveringsgrunnlag, men faktisk produksjonsaktivering skal vente til Wiki Article Layer er ferdig (Fase 8A–8E). Dagens claim-baserte `content_markdown` er ikke riktig sluttprodukt — artikkellaget mangler.

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

## Fase 8 — Wiki Article Layer

Målet er å gå fra claim-dump til en ekte Karpathy-inspirert wikiopplevelse:

```
raw sources → AI-artikkelgenerering → lesbar wikiartikkel
→ claims/sources/lint som verifikasjonslag rundt artikkelen
→ human review og godkjenning
```

### Fase 8A — Article Layer Analyse — Fullført

Mål: kartlegg nøyaktig hvor dagens implementering gjør claims til hovedinnhold.

Scope gjennomgått:
- `FinalizeEnterpriseWikiIngest::assembleContentMarkdown()` — produserer en claim-dump, ikke en artikkel
- `EnterpriseWikiPageVersion.content_markdown` — feltet finnes og er riktig sted for artikkelen
- `WikiController::show()` — passerte `content_markdown` men det ble ikke brukt i UI
- `resources/js/Pages/App/Wiki/Show.jsx` — ignorerte `content_markdown`, viste bare claims

Funn: arkitektur og datamodell er riktig. Gap er at `assembleContentMarkdown()` lager en claim-liste, og UI viste ikke `content_markdown` som primærinnhold. Minste trygge vei: (1) fikse UI til å rendre `content_markdown` som artikkel (Fase 8D), (2) erstatte `assembleContentMarkdown()` med AI-generert artikkel (Fase 8C).

### Fase 8B — Artikkelgenerering-spesifikasjon — Fullført

> Spesifikasjonsfase. Ingen kodeendringer i denne fasen.

#### Avgrensninger

- Ingen ny databaseendring — `content_markdown` finnes allerede på `enterprise_wiki_page_versions`.
- Ingen kobling til Kunnskapsbase/RAG, `KnowledgeItem`, `KnowledgeItemVersion` eller `KnowledgeItemChunk`.
- Ingen produksjonsaktivering.
- Claims er ikke sluttproduktet — de er verifikasjonslaget.
- `enterprise_wiki_page_versions.content_markdown` er det autoritative feltet for wikiartikkelinnhold.

#### Nåværende gap

`FinalizeEnterpriseWikiIngest::handle()` kaller `EnterpriseWikiIngestService::assembleContentMarkdown()` som produserer:

```
# {pageTitle}
<!-- wiki-ingest-run:N -->
{claim_text}
> *Kilde: {sourceLabel} — «{excerpt}»*
...
```

Dette er ikke en lesbar wikiartikkel. Det er en formatert claim-liste. UI-en (Fase 8D) er klar til å rendre
`content_markdown` som artikkel — men innholdet som genereres er ikke det rette.

#### Løsning: WikiArticleAiClient i FinalizeEnterpriseWikiIngest

Ny klasse `WikiArticleAiClient` (speiler `WikiSectionAiClient`). Metoden `generateArticle()` kalles av
`FinalizeEnterpriseWikiIngest` etter at alle seksjonsjobber er fullført — i stedet for `assembleContentMarkdown()`.

Claims og source references i DB røres ikke av dette steget. De forblir som verifikasjonslag.

#### Plassering i pipeline

```
ProcessEnterpriseWikiIngest      (uendret)
  → ProcessEnterpriseWikiSection (uendret — ekstraherer claims og source references per seksjon)
  → FinalizeEnterpriseWikiIngest (ENDRES i Fase 8C: erstatter assembleContentMarkdown()
                                   med WikiArticleAiClient::generateArticle())
```

`assembleContentMarkdown()` i `EnterpriseWikiIngestService` beholdes inntil videre for eksisterende tester, men
kalles ikke lenger fra finalize-jobben etter Fase 8C.

#### Klassestruktur etter Fase 8C

```
app/Services/Ai/Wiki/
  WikiSectionAiClient.php          (uendret — claim extraction, gpt-4.1-mini)
  WikiArticleAiClient.php          (ny — artikkelgenerering, gpt-5)
  EnterpriseWikiIngestService.php  (beholder assembleContentMarkdown() men kalles ikke fra finalize)

app/Jobs/Ai/Wiki/
  FinalizeEnterpriseWikiIngest.php (endres — injiserer og bruker WikiArticleAiClient)
```

#### Input til WikiArticleAiClient::generateArticle()

Alle claims for page version hentes fra DB inne i `FinalizeEnterpriseWikiIngest::handle()`:

```php
$claimsData = EnterpriseWikiClaim::query()
    ->with('sourceReferences')
    ->where('enterprise_wiki_page_version_id', $pageVersion->id)
    ->orderBy('position_order')
    ->get()
    ->map(fn($c) => [
        'text'       => $c->claim_text,
        'confidence' => $c->confidence,
        'excerpt'    => $c->sourceReferences->first()?->excerpt ?? '',
        'source'     => $c->sourceReferences->first()?->source_label ?? '',
    ])
    ->all();

$articleResult = $articleClient->generateArticle(
    pageTitle: $pageTitle,
    claims: $claimsData,
    languageCode: $languageCode,
);
```

`languageCode` hentes fra `$run->customer->language->code` inne i jobben (samme mønster som i AI-svarflyt).

#### Modell og temperatur

- **Modell:** `gpt-5` — per CLAUDE.md er dette modellen for synteseoppgaver og answer draft generation.
- **Temperatur:** 0.3 — litt frihet for syntese; ikke 0 som for ren claim-ekstraksjon.
- **Maks output tokens:** 4 000 — tilstrekkelig for en wiki-side med 10–20 seksjoner.

#### Output-format — strict JSON schema

Minimalt schema. Kun `article.markdown` er required — dette er det eneste feltet som mappes til `content_markdown`.

```json
{
  "article": {
    "markdown": "## Oversikt\n\nVirksomheten leverer...\n\n## HMS\n\n..."
  }
}
```

PHP schema for Responses API:

```php
[
    'type' => 'object',
    'properties' => [
        'article' => [
            'type' => 'object',
            'properties' => [
                'markdown' => ['type' => 'string'],
            ],
            'required' => ['markdown'],
            'additionalProperties' => false,
        ],
    ],
    'required' => ['article'],
    'additionalProperties' => false,
]
```

**Begrunnelse for minimalt schema:** Komplekst schema med `title`, `summary`, `sections[]` øker prompt-kompleksitet
og risiko for schema-brudd. Tittelen er allerede på `enterprise_wiki_pages.title`. `markdown` er det eneste
feltet som trengs.

#### Prompt-design

System-prompt (developer role):

```
You are a wiki article writer.
Write a coherent, readable wiki article in {languageName} based on the provided claims and source excerpts.
Rules:
- Use ## headings for sections and write flowing prose paragraphs, not bullet lists.
- Synthesise overlapping claims into coherent text — do not repeat the same fact twice.
- Do not introduce facts not supported by the provided claims and excerpts.
- Do not mention that the article is AI-generated within the article text itself.
- Return only JSON matching the schema. No text before or after JSON.
```

User-prompt (en strukturert claim-liste):

```
Page title: {pageTitle}

Claims and source evidence:

1. {claim_text} [confidence: high]
   Source: {source_label} — "{excerpt}"

2. {claim_text} [confidence: medium]
   Source: {source_label} — "{excerpt}"

[uncertain claims inkluderes men merkes med [confidence: uncertain]]
```

Claims sorteres etter `position_order` (bevarer dokumentrekkefølge).

#### Mapping til content_markdown

`article.markdown` fra AI-responsen skrives direkte til `pageVersion->content_markdown`.

Artikkelen begynner typisk med `## Oversikt` eller tilsvarende første seksjon — AI styrer struktur.
Sidetittelen vises separat i UI fra `page.title`, så ingen `# {pageTitle}` prepend.
Wiki-ingest-run-kommentar (`<!-- wiki-ingest-run:N -->`) fra `assembleContentMarkdown()` utelates.

#### Håndtering av tomme/svake grunnlag

| Situasjon | Håndtering |
|---|---|
| Ingen claims i DB | Run feiler med `No claims were stored` — eksisterende oppførsel beholdes |
| Alle claims er `uncertain` | Artikkelen genereres; lint-sjekk `low_confidence_page` plukker opp |
| `article.markdown` er tom streng | Run feiler med `WikiArticleAiClient: generated article was empty` |
| AI returnerer ugyldig JSON | `RuntimeException` — run feiler (samme mønster som `WikiSectionAiClient`) |
| Færre enn 3 claims | Artikkelen genereres — ingen minimumsgrense |
| OpenAI-timeout | `FinalizeEnterpriseWikiIngest.$timeout = 120` er tilstrekkelig for ett API-kall |

Eksisterende `if ($markdown === '')` i finalize erstattes med tilsvarende sjekk på `$articleResult['article']['markdown']`.

#### Claims-laget er uendret

`ProcessEnterpriseWikiSection` og `WikiSectionAiClient::fetchClaims()` endres **ikke**.

Claims lagres i `enterprise_wiki_claims` som før. Source references lagres i `enterprise_wiki_source_references` som
før. Lint-regler kjører som før. `WikiController::show()` sender claims som `claims`-prop som før.

Artikkelen er et nytt synteselag skrevet av en ny AI-klient på toppen av eksisterende claims.

#### Teststrategi for Fase 8C

Automatiserte tester bruker mock av `WikiArticleAiClient` — ingen ekte API-kall.

| Test | Hva sjekkes |
|---|---|
| Gyldig artikkel-respons | `generateArticle()` returnerer riktig `article.markdown` |
| `content_markdown` lagres i page version | Finalize skriver `article.markdown` til `enterprise_wiki_page_versions` |
| Tom `article.markdown` feiler run | Run får `status = failed`, `error_message` settes |
| Ugyldig JSON fra OpenAI | `RuntimeException` propageres, run feiles |
| 0 claims feiler run | Eksisterende oppførsel beholdes |
| Claims og source references røres ikke | DB-data er uendret etter finalize |
| `assembleContentMarkdown()` kalles ikke | Mock eller assertion verifiserer at gammel metode ikke påkalles |
| Kunnskapsbase-tabeller røres ikke | Ingen referanse til `knowledge_items`/`knowledge_item_versions` i ny kode |

Eksisterende `FinalizeEnterpriseWikiIngestTest`-tester som mocker `assembleContentMarkdown()` oppdateres i Fase 8C
til å mocke `WikiArticleAiClient::generateArticle()` i stedet.

### Fase 8C — Backend artikkelgenerering — Fullført (commits 956206d–4ea8fb6)

Implementert:
- `WikiArticleAiClient::generateArticle()` (gpt-5, strict JSON schema, format-validering)
- `FinalizeEnterpriseWikiIngest` injiserer og bruker `WikiArticleAiClient` i stedet for `assembleContentMarkdown()`
- Guard: ingest feiler rent hvis `ENTERPRISE_WIKI_AI_ENABLED=false` etter at jobben er queued
- `validateArticle()` avviser HTML-kommentarer, ≥2 `Kilde:`-linjer og ≥3 blockquote-linjer
- Mock-baserte tester for alle scenarier — ingen ekte API-kall

**Kjent feil verifisert under E2E-test (2026-07-08):** `gpt-5` støtter ikke `temperature`-parameteren i Responses API (HTTP 400). `WikiArticleAiClient::buildPayload()` sender `temperature: 0.3` — dette må fjernes. Én linje kodeendring, ingen migrasjon.

**v0.4-vurdering:** Fase 8C implementerte en gyldig byggestein (per-side artikkelgenerering fra claims). Men modellen «claims fra én kilde → én side» er for smal for Karpathy-modellen. Se §v0.4 for riktig neste retning.

### Fase 8D — Wiki Article UI — Startet (commit 94f6541)

Mål: gjør `/app/wiki/{slug}` til en reell wikiartikkel-side.

**Status:** Article-first UI-omstrukturering fullført i commit `94f6541`:
- `content_markdown` rendres nå som primær wikiartikkel via `react-markdown` med `.wiki-article`-styling
- Claims, sources og lint er sekundære — samlet i kollapsbar "Verifikasjonsgrunnlag"-seksjon (lukket som standard)
- `react-markdown` v10 lagt til som avhengighet
- Nye lang-nøkler: `article_heading`, `article_ai_label`, `article_empty`, `high_volume_warning`
- Prop `content_markdown` er nå eksplisitt i controller og bekreftet med test

**Gjenstår:** Backend-generering av faktisk wikiartikkel (Fase 8C). Frem til Fase 8C er ferdig vil `content_markdown` fortsatt inneholde claim-dump fra `assembleContentMarkdown()` — UI er klar til å rendre riktig artikkel så snart backend leverer det.

### Fase 8E — Review og godkjennings-UX for artikkelinnhold

Mål: reviewer godkjenner artikkelen, ikke bare en claim-liste.

Krav:
- godkjenning knyttes til artikkelversjon
- verifikasjonslag støtter beslutningen
- åpne lint `error` skal vurderes før godkjenning
- ikke auto-publiser

### Fase 8F — Kontrollert produksjonsaktivering — Gjenstår til etter Fase 8E

> **Ikke neste steg.** Produksjonsaktivering skjer først etter at Fase 8B–8E er gjennomført og wikiartikkel-opplevelsen er verifisert.

Mål: bruk eksisterende runbook fra Fase 7 kontrollert i produksjon.

Krav:
- `ENTERPRISE_WIKI_AI_ENABLED=true` aktiveres etter at artikkelgenerering fungerer
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

## Appendix B — AI-promptdesign (retningslinjer)

### B.1 v0.3-prinsipp

Wiki-generering skal ikke lenger stoppe ved claim extraction.

AI-output skal være strukturert og validerbar, men må inneholde et faktisk artikkellag:

- `article` — lesbar wikiartikkel
- `claims` — verifikasjonsgrunnlag
- `source_coverage` — oversikt over hvilke kilder/seksjoner som er dekket
- `warnings` — usikkerheter eller hull modellen oppdaget

Claims er ikke sluttproduktet. De støtter review, kildekontroll og lint.

### B.2 Strukturert output

AI skal returnere strict JSON, ikke uvalidert fri tekst.

Eksempel på ønsket overordnet output:

```json
{
  "article": {
    "title": "HMS-kompetanse",
    "summary": "Kort sammendrag av hva kildene dokumenterer om HMS-kompetanse.",
    "markdown": "## Oversikt

Sammenhengende wikiartikkel...",
    "sections": [
      {
        "heading": "Oversikt",
        "markdown": "Sammenhengende avsnitt med kildegrunnede fakta."
      }
    ]
  },
  "claims": [
    {
      "text": "Virksomheten har ISO 45001-sertifisering for HMS-styringssystem.",
      "confidence": "high",
      "excerpt": "Selskapet ble ISO 45001-sertifisert i 2022 gjennom DNV GL.",
      "conflict_note": null
    }
  ],
  "source_coverage": [
    {
      "source_label": "hms-policy.docx",
      "covered": true,
      "notes": null
    }
  ],
  "warnings": []
}
```

### B.3 Artikkelregler

Artikkelen skal:

- skrives på kundens språk
- være Markdown
- ha tydelige overskrifter
- ha sammenhengende brødtekst
- syntetisere overlappende kildetekst
- unngå marketing-språk uten kilde
- ikke inneholde fakta som ikke støttes av kildene
- ikke nevne at den er AI-generert i selve artikkelteksten
- kunne leses uten at brukeren åpner claim-listen

### B.4 Claim-/verifikasjonsregler

Claims skal:

- være konkrete og verifiserbare
- ha `confidence`
- ha `excerpt`
- ha `conflict_note` ved konflikt
- brukes til review, ikke som artikkelens primære layout

### B.5 Teststrategi

Automatiserte tester skal bruke mock/fake OpenAI-klient.

Tester skal dekke:

- gyldig artikkel-JSON
- `article.markdown` lagres som `content_markdown`
- claims lagres med source references
- manglende `article.markdown` gir feil
- claims uten excerpt håndteres i verifikasjonslaget
- ingen ekte nettverkskall i tester
- Kunnskapsbase/RAG-tabeller røres ikke
