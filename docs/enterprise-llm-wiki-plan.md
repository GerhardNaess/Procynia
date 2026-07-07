# Enterprise LLM Wiki — Arkitektur- og implementeringsplan

Versjon: 0.1 (utkast)
Dato: 2026-07-07
Status: Fase 0 fullført / Fase 1 fullført (2026-07-07)

---

## 1. Formål

Enterprise LLM Wiki er et parallelt kunnskapssystem for Procynia, inspirert av Karpathy LLM Wiki-metoden, men tilpasset enterprise-krav: kundescoping, rollestyrt godkjenning, sporbar revisjon, og kildebevis på påstandsnivå.

Systemet er ikke en erstatning for dagens Kunnskapsbase eller RAG-pipeline. Det er et komplementært lag som:

- leser fra godkjente, eksisterende kunnskapskilder uten å endre dem
- bruker AI til å generere strukturerte wiki-sider med én konkret påstand per setning
- knytter hvert faktum tilbake til kildedokument og tekstutdrag
- lar menneskelige brukere godkjenne, justere og arkivere innhold
- gir grunnlag for å sammenligne wiki-baserte svar mot dagens RAG i fremtidige faser

Kjerneprinsipper:

- AI foreslår. Mennesker godkjenner.
- Ingen påstand uten kilde.
- Ingen skrivetilgang til eksisterende modeller eller tabeller.
- Kundeisolasjon fra dag én.

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

## 3. Første read-only kilde

**Pilot-kilde:** `KnowledgeItemVersion.extracted_text`

Vilkår for lesing:

- `approval_status = 'approved'`
- `is_current = true`
- Kun for kunder med `ai_usage_enabled = true` på det tilknyttede `KnowledgeItem`

Ingen re-ekstraksjon av dokumenttekst i pilot. `extracted_text` er allerede prosessert og godkjent av mennesker — dette er den sikreste og enkleste inngangen.

Fremtidige faser kan legge til:

- Fase 2: `SavedNoticeAiDocument.extracted_text` (saksdokumenter)
- Fase 3: `Notice`-data og CPV-mønstre fra Doffin
- Fase 4: Egne runbooks og prosessdokumenter

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

Immutable versjonering. Ny versjon opprettes alltid — eksisterende versjoner overskrives aldri.

| Felt | Type | Beskrivelse |
|---|---|---|
| `id` | bigint PK | |
| `enterprise_wiki_page_id` | bigint FK | |
| `version_number` | integer | Monotont stigende per side |
| `is_current` | boolean | Kun én current per side |
| `content_markdown` | text | Full markdown-tekst for siden |
| `generated_by_model` | varchar | Modellidentifikator, f.eks. `gpt-5` |
| `generation_prompt_hash` | varchar | For reproduserbarhet |
| `created_at` | timestamp | |

---

### 4.3 `enterprise_wiki_claims`

Én konkret påstand per rad. En side kan ha mange påstander. Påstander er kjerneenheten i wiki-systemet.

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

## 7. Wiki-ingest-flyt (pilot)

```
1. Bruker velger en godkjent KnowledgeItemVersion
   └─ Filtert: approval_status = 'approved' AND is_current = true
   └─ Kunden må ha ai_usage_enabled = true

2. Manuell trigger: Artisan-kommando eller fremtidig UI-knapp
   └─ Oppretter enterprise_wiki_ingest_runs med status = queued

3. Orchestrator-jobb startes (ProcessEnterpriseWikiIngest)
   └─ Leser extracted_text fra KnowledgeItemVersion
   └─ Deler tekst i seksjoner (H1/H2 boundary eller fast tegngrense)
   └─ Oppretter draft EnterpriseWikiPage og EnterpriseWikiPageVersion (is_current=false)
   └─ Oppretter enterprise_wiki_ingest_sections-rader (én per seksjon)
   └─ Setter ingest_run.status = sections_planned
   └─ Dispatcher én ProcessEnterpriseWikiSection per seksjon

4. Per-seksjon-jobb (ProcessEnterpriseWikiSection)
   └─ AI mottar: seksjonstekst + instruksjon om JSON-output
   └─ AI returnerer:
       {
         "proposed_topic": "...",
         "claims": [
           {
             "text": "...",
             "confidence": "high|medium|low|uncertain",
             "excerpt": "...",
             "conflict_note": null
           }
         ]
       }
   └─ Backend validerer JSON-schema
   └─ Backend oppretter enterprise_wiki_claims-rader
   └─ Backend oppretter enterprise_wiki_source_references per claim
       (source_type = 'knowledge_item_version', source_id = versjons-ID)

5. Finalisering (FinalizeEnterpriseWikiIngest)
   └─ Venter til alle enterprise_wiki_ingest_sections er i terminal tilstand
   └─ Avbryter tidlig (no-op) dersom noen seksjoner fortsatt er pending/running
   └─ Assembler content_markdown fra claims (én claim per avsnitt, kilde inlinert)
   └─ Oppdaterer EnterpriseWikiPageVersion: content_markdown, is_current=true
   └─ Oppdaterer EnterpriseWikiPage: status=pending_review
   └─ Oppdaterer enterprise_wiki_ingest_runs: status=completed, finished_at

6. Ingen wiki-output kobles til eksisterende RAG, kravsvar eller AI workspace
```

**Viktig prinsipp:** AI returnerer JSON med claim-liste. AI returnerer aldri friformulert markdown direkte. Backend assembler wiki-siden fra strukturert output. Dette speiler chunking-filosofien i `docs/chunking-strategy.md`.

---

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

## 10. Første pilotfase (Fase 1)

Minste implementerbare pilot:

| Element | Innhold |
|---|---|
| **Ny sidefamilie** | `enterprise_wiki_pages`, `enterprise_wiki_page_versions`, `enterprise_wiki_claims`, `enterprise_wiki_source_references`, `enterprise_wiki_ingest_runs` |
| **Kilde** | Kun `KnowledgeItemVersion.extracted_text` med `approval_status = approved AND is_current = true` |
| **Trigger** | Manuell Artisan-kommando: `php artisan wiki:ingest --customer=X --version-id=Y` |
| **Output** | `enterprise_wiki_pages` med status `draft`, claims og kildehenvisninger |
| **UI** | Enkel `/app/wiki`-side som lister sider og viser claims med kildelenker |
| **Godkjenning** | System Owner kan sette `approved` / `rejected` |
| **RAG-kobling** | Ingen — wiki-output brukes ikke i dagens AI-svarflyt |

Mål med pilot: Verifisere at AI produserer brukbare, sporbare claims fra godkjent kunnskapsinnhold, og at godkjenningsflyten er god nok for System Owners.

---

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

### Fase 2 — UI for wiki-sider og claims
- `/app/wiki` — liste over wiki-sider for kunden
- `/app/wiki/{page}` — detaljvisning med claims og kildelenker
- Statusindikator per side
- Ingen godkjenningshandlinger ennå

### Fase 3 — Godkjenning og statusflyt
- System Owner kan godkjenne/avvise sider
- Statusflyt: draft → pending_review → approved/rejected
- Eventuelt: godkjenning per enkeltpåstand (avhenger av beslutning i §11)
- Historikk: vis alle versjoner av en side

### Fase 4 — Lint og helsekontroll
- Lint-kontroller beskrevet i §9
- `enterprise_wiki_lint_findings`-tabell
- Helseindikator per side i UI
- Scheduled lint-job (konfigurerbar frekvens)

### Fase 5 — Sammenligning mot dagens RAG
- Parallell visning: wiki-svar vs. RAG-svar for samme requirement
- Bruker kan velge foretrukket svar og gi tilbakemelding
- Metrikker: kildedekning, konfidensmerking, brukerpreferanse

### Fase 6 — Vurdering av wiki som svargrunnlag
- Evaluere om wiki-claims kan brukes som supplement eller erstatning for embedding-søk
- Vurdere hybrid retrieval: wiki-oppslag + RAG
- Beslutning basert på data fra fase 5
- Ingen endring av eksisterende RAG uten separat ADR

---

## Appendix A — Modellreferanser (read-only)

Disse eksisterende modellene leses av wiki-systemet, men endres ikke:

| Modell | Felt som leses | Bruk |
|---|---|---|
| `KnowledgeItemVersion` | `extracted_text`, `approval_status`, `is_current`, `original_filename`, `file_hash_sha256` | Primærkilde for pilot-ingest |
| `KnowledgeItem` | `ai_usage_enabled`, `title`, `customer_id` | Tilgangskontroll og visningsnavn |
| `Customer` | `id`, `language` | Kundeisolasjon og språkvalg |
| `User` | `role`, `bid_role` | Rollestyring |

---

## Appendix B — AI-promptdesign (retningslinjer)

Wiki-ingest-prompten skal:

- Instruere AI til å returnere JSON, ikke markdown
- Gi AI én seksjon av gangen (ikke hele dokumentet)
- Instruere AI til å returnere `null` som `conflict_note` dersom ingen konflikt oppdages
- Instruere AI til å bruke `confidence = uncertain` fremfor å gjette
- Ikke be AI om å sitere tekst ordrett — eksklarer hentes fra `extracted_text` i backend basert på claim-identifikasjon
- Inkludere `languageCode` i prompt (fra `CustomerContext::resolveLanguageCode()`)

Eksempel på ønsket JSON-output per seksjon:

```json
{
  "proposed_topic": "HMS-kompetansekrav",
  "claims": [
    {
      "text": "Virksomheten har ISO 45001-sertifisering for HMS-styringssystem.",
      "confidence": "high",
      "excerpt": "Selskapet ble ISO 45001-sertifisert i 2022 gjennom DNV GL.",
      "conflict_note": null
    },
    {
      "text": "Antall ansatte med godkjent HMS-kurs er ikke dokumentert i kilden.",
      "confidence": "uncertain",
      "excerpt": null,
      "conflict_note": null
    }
  ]
}
```

Viktig: AI returnerer aldri ferdig markdown. Backend assembler wiki-siden fra claim-listen.
