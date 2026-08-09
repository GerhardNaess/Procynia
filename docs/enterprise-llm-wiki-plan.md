# Enterprise LLM Wiki — Arkitektur- og implementeringsplan

Versjon: 0.26
Dato: 2026-08-09
Status: Infrastruktur fullført (Fase 0–4B) · AI-integrasjon fullført (Fase 5) · Lokal E2E verifisert (Fase 6) · Produksjonsrunbook fullført (Fase 7, aktivering utsatt) · Article-first UI/fullført start (Fase 8D, commits 94f6541 og 94f5721) · Backend artikkelgenerering teknisk implementert (Fase 8C, commits 956206d, 5029cb0 og 4ea8fb6) · Fase 8E-10–8E-20 teknisk implementert, men **8E-16/8E-19/8E-20 sin lenke-/grafmodell er korrigert i v0.6 — se Fase 8I** · Fase 8F-0–8F-5 fullført (forvaltnings- og kontrollflate) · 8G-1–8G-7 fullført · 8H-kjerne delfase 1 + delfase 2 fullført (kildemonitoring, intelligent retry, dyp reparasjon) · 8H-utvidelse fullført (snapshot-basert terskelreparasjon og regresjonsdeteksjon) · Runtimeflyten (staged page-generation queues, commit `b6ccd87`) teknisk verifisert · **Fase 8I-1/8I-2 (canonical wikilink-syntax, parser, materialisering) fullført, commit `d0a608d` · Fase 8I-3/8I-4 (rendering, backlinks, canonical traversal, Wiki-aware generation) fullført, commit `ab35d52` — backend produserte korrekt `rendered_markdown`, men inline wikilinks var ikke reelt runtime-verifisert som synlig klikkbare i UI før commit `2a3ad16` (se eget avsnitt) — og LLM-generert innhold skriver og valideres mot en tillatt sidekatalog før persistens · Fase 8I-5 (incremental relinking av eksisterende sider) fullført, commit `716477e` · Fase 8I-6 (deterministisk lenke-lint og semantisk QA/repair av lenker) fullført, commit `014861f` · Inline wikilink-visning i UI reelt runtime-verifisert og rettet, commit `2a3ad16` — **Fase 8I er dermed komplett** · Post-ingest QA redegjort til deterministisk sluttkontroll, rettet 2026-07-13 · Manuell claim-godkjenning + automatisk kildegjenfinning mot nye dokumenter fullført, commits `a58bd40`/`7070d32` · QA-tilgang (tilleggsrolle) for claim-godkjenning fullført, commit `af1dcb6` · **Arkitekturbeslutning oppdatert 2026-08-06: claims gjelder Procynias egne påstander og innvendinger; direkte kildeinnhold skal spores via provenance, kildekobling og blokk-/elementreferanser, ikke via claims** · **Produktstrategi presisert (v0.10, 2026-08-01): claims/QA er en frivillig, ikke-blokkerende kvalitetssløyfe for Procynias egne påstander og innvendinger — bindende for alt videre arbeid** · **v0.10 realisert i kode (2026-08-01): QA-verdikt/Dokumenteier-godkjenning aldri lenger claim-gatet, Funn-panelet grupperer per canonical_fact_id, run-vid claim-takk (`max_new_claims_per_run`) lagt til, UI-språk rettet — se realiseringsnotatet ved slutten av dokumentet** · **Første reelle kompletterende dokumentimport (run 20, 2026-08-09) avdekket et generelt ownership-gap. Korrigert 2026-08-09 etter kontroll av faktisk produksjonskall: Wiki HAR allerede structured prose provenance i sidegenereringen (paragraph/list_item/table_row/image med `source_element_key`); gapet er at maintainer decision — som fordeler `owned_topics` — ikke får den samme katalogen. **Fase 8J-1B levert, commit `e0dba82`** — maintaineren får nå samme adresserbare source catalog som sidegenerering, bevist aktiv i run 23. Fler-element-proveniens per blokk viste seg å være eksisterende arkitektur, ikke et gap. Neste steg er observasjon på flere gyldige testtilfeller før 8J-2 designes; 8J-1A gjenstår som selvstendig robusthetsarbeid. Se Fase 8J og Arkitekturnotat v0.12**

> **Arkitekturkorrigering (v0.2):** Enterprise Wiki skal være et fullstendig parallelt system uten avhengighet av Kunnskapsbase eller RAG-pipeline. Dagens `KnowledgeItemVersion`-baserte ingest er midlertidig bootstrap/import og regnes **ikke** som permanent primærflyt. Se §3, §7 og Fase 4A for korrekt langsiktig arkitektur.
>
> **Målbildekorrigering (v0.3):** Enterprise Wiki skal ikke være en claim-liste. Den skal være en Markdown-first LLM Wiki: raw sources inn, lesbare wikiartikler ut. Claims, excerpts, source references, lint og helsekontroll er verifikasjonsgrunnmur rundt artikkelen — ikke selve sluttproduktet.
>
> **Karpathy-korrigering (v0.5):** Enterprise Wiki skal ligge tettest mulig på Karpathy Wiki-modellen: `raw source → compile/maintainer → article per source + summary per source + dedupliserte concept/entity pages → index/log/backlinks/lint`. Tidligere v0.4-formuleringer som antydet at `article per source` i seg selv er feil, er korrigert. Feilen er ikke at en kilde får en artikkel; feilen er å stoppe der. Én kilde skal normalt få source article og source summary, og samtidig kunne oppdatere flere felles concept/entity-sider. Filnavn er fortsatt kildemetadata, ikke automatisk wiki-identitet.
>
> **Lenkekorrigering (v0.6, 2026-07-11):** Runtimeflyten kan nå faktisk gjennomføres og generere article, summary, concept og entity (Responses API-herding `fa01995` + staged page-generation queues `b6ccd87`). Men genererte sider er i dag isolerte artikler uten inline `[[wikilinks]]` i brødteksten. `EnterpriseWikiPageLink` (Fase 8E-16) bygges i dag som en mekanisk kombinasjon av alle page-type-par i en run — ikke avledet fra faktiske lenker i innholdet — og grafen (8E-19/8E-20) viser derfor nodes uten reelle kanter. Dette er et brudd på Karpathy Wiki-premisset: Wikien vedlikeholdes ikke som et sammenhengende kunnskapsnettverk. **Sidegenerering alene er ikke det samme som en Wiki.** Se det dedikerte arkitekturnotatet rett under, §4.10–4.11 og Fase 8I for korrigert modell og videre arbeid.
>
> **Kvalitetsstrategikorrigering (v0.7, 2026-07-21):** Procynia har lov til å bruke beste praksis og tilføre nyttig tekst utover kildedokumentet. Det eneste brukeren skal varsles om er konkrete tekstområder systemet har lagt til som ikke kan støttes av kilden — aldri interne verifiseringskategorier (negasjon, modalitet, aktør, scope, teknisk usikkerhet). Denne regelen er bindende og overstyrer tidligere claim-/verifiseringsspråk der de er i konflikt. Se det dedikerte arkitekturnotatet ved slutten av dokumentet (rett før «Produksjonsaktivering») for full presisering. Gjelder nye dokumentimporter — eksisterende kjøringer migreres ikke.
>
> **Arkitekturbeslutning (v0.11, 2026-08-06):** Claims skal ikke brukes til å modellere direkte kildeutsagn. Når kildedokumentet selv sier noe eksplisitt, skal det gå direkte inn i Wikien med kildekobling, provenance, dokumentreferanse, blokk-/elementreferanse og revisjonsspor. Claims er reservert for Procynias egne påstander, vurderinger, anbefalinger og innvendinger. «Beste praksis» er en systemgenerert klassifisering av Procynias tilføyelser, ikke en etikett på kildedokumentets egne fakta. Denne beslutningen erstatter alle tidligere formuleringer i dokumentet som antyder at direkte kildefakta skal claim-verifiseres.
>
> **Proveniens-/eierskapskorrigering (v0.12, 2026-08-09):** Eierskap i Enterprise Wiki gjelder **semantisk substans**, ikke fysiske dokumentelementer og ikke temanavn. Målbildet «én substansiell kunnskapsenhet skal ha én canonical owner» står ved lag, men kan i dag **ikke håndheves server-side**: både sidegenereringen og — etter 8J-1B (commit `e0dba82`) — maintainer decision har nå adresserbar kildeproveniens. Det som fortsatt mangler er ikke infrastruktur, men **semantisk granularitet**: hvilket faktum eller tekstspan innenfor et element som bærer den eide substansen. (To tidligere formuleringer er korrigert 2026-08-09: at Wiki kun hadde flat `extracted_text` som grunnlag, og at fler-element-proveniens per blokk manglet. Begge var feil — se Fase 8J.) Inntil den semantiske modellen er designet skal ownership **ikke** håndheves med tekstheuristikk (title-substring, fuzzy matching, ordlister, domenebegreper). Riktig rekkefølge er: (1) strukturerte source elements inn i Wiki ✔, (2) observer og mål faktisk bruk, (3) design semantisk fact/span-eierskap, (4) håndhev deretter en generisk ownership-invariant før sidegenerering. Se Fase 8J og det dedikerte arkitekturnotatet ved slutten av dokumentet.
>
> **Presisering (v0.10, 2026-08-01):** claims/QA er en frivillig, ikke-blokkerende kvalitetssløyfe for Procynias egne påstander og innvendinger. QA-portvakten er rettet slik at interne signaler aldri alene setter en kjøring til `repair_required`; bare en reell brukersak eller en eksplisitt menneskelig blokkeringsbeslutning gjør det. Se det dedikerte arkitekturnotatet ved slutten av dokumentet.

## Arkitekturnotat — v0.6 kurskorrigering: Karpathy-lenking og inkrementelt vedlikehold

Dette notatet oppsummerer kurskorrigeringen datert 2026-07-11. Det er et tillegg, ikke en sletting: alle tidligere faser, commits, tester og implementerte komponenter beskrevet lenger ned i dette dokumentet **står ved lag** som historisk og teknisk grunnlag. Det som korrigeres er hvilken konklusjon som kan trekkes av dem.

**Hva som er bekreftet fungerende (runtimeflyt):**

- Enterprise Wiki kan nå kjøre hele dokumentflyten uten å velte på ett tregt OpenAI-kall (Responses API-herding `fa01995`, staged page-generation queues `b6ccd87`, to-pass rekkefølge article+summary → concept+entity → claims/verifisering/QA).
- Systemet genererer faktisk `article`, `summary`, `concept` og `entity`-sider med lagret `content_markdown`.

**Hva som mangler, og hvorfor det er alvorlig:**

- Genererte sider er isolerte artikler. Det finnes ingen semantiske inline `[[wikilinks]]` i selve brødteksten.
- `EnterpriseWikiPageLink` (Fase 8E-16) er i dag en **mekanisk kombinatorikk** av page-type-par innenfor én run (alle article↔summary, article↔concept, osv.) — ikke en projeksjon av faktiske lenker i innholdet.
- Grafen (Fase 8E-19/8E-20) viser derfor nodes med kanter som ikke reflekterer noe reelt semantisk forhold — «edges uten mening» snarere enn «nodes uten edges».
- Ord og begreper i teksten peker ikke til andre wiki-sider. Systemet vedlikeholder ikke Wikien som et sammenhengende kunnskapsnettverk.
- Dette er et brudd på Karpathy Wiki-konseptet, som har vært premisset for Enterprise Wiki siden v0.5.

**Prinsipiell korreksjon (bindende fra v0.6):**

1. Enterprise Wiki er **ikke ferdig** når sider bare er generert.
2. En wiki-side må inneholde semantiske inline wiki-lenker i selve brødteksten. Kanonisk format: `[[target-slug]]`, og ved behov `[[target-slug|synlig tekst]]` (eks.: `[[business-case]]`, `[[prosjekteier|prosjekteieren]]`, `[[prosjektstyring#roller]]`).
3. `EnterpriseWikiPageVersion.content_markdown` er og blir den kanoniske innholdskilden.
4. Inline wikilinks i current page version er den kanoniske kilden til relasjoner mellom sider.
5. `EnterpriseWikiPageLink` skal være en **databaseprojeksjon** av faktiske wikilinks i sideinnholdet — ikke en separat AI-generert relasjonssannhet.
6. Backlinks avledes deterministisk fra wikilinkene. Graph edges avledes fra de samme relasjonene.
7. Markdown, backlinks og graf skal **aldri** ha tre separate sannheter — kun én kilde (innholdet) og to deriverte projeksjoner (backlinks, graf).
8. LLM-maintainer/compiler skal lese den eksisterende kundewikien når ny informasjon integreres, og skal kunne legge inn nye inline-lenker i eksisterende sider der et begrep allerede omtales.
9. Linking er en del av selve wiki-kompileringen og -vedlikeholdet — ikke et etterfølgende, frikoblet graph-steg.

**Referansemodell (Karpathy-kompatibel):**

```text
raw source
→ compiler/maintainer leser eksisterende Wiki
→ oppretter eller oppdaterer article, summary og concepts
→ skriver inline [[wiki-links]] i Markdown
→ parser/scanner validerer lenkene
→ backlinks og graph avledes fra de faktiske wikilinkene
→ linter finner broken links, orphans og manglende koblinger
→ senere kilder kan føre til relinking og oppdatering av eksisterende sider
```

**Konsekvens:** Dette er den nye hovedblockeren, se Fase 8I. Den skal prioriteres foran videre graph-visualisering, generell resume-arkitektur eller andre utvidelser.

## 1. Formål

Enterprise LLM Wiki er et parallelt kunnskapssystem for Procynia, inspirert av Karpathy Wiki-mønsteret og tilpasset enterprise-krav: kundescoping, rollestyrt godkjenning, sporbar revisjon, kildebevis og trygg menneskelig review.

Det sentrale produktet er **en kompilert, vedlikeholdt Markdown-wiki**, ikke dokumentoppsummeringer alene og ikke claim-lister. Direkte kildeutsagn går inn som wikiinnhold med provenance; claims brukes bare når Procynia selv legger til, vurderer eller utfordrer innhold.

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
- bruker claims for Procynias egne påstander og innvendinger, samt excerpts, source references, provenance og lint som verifikasjonslag rundt wikiinnholdet
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
- Ingen Procynia-påstander uten kildegrunnlag eller tydelig beste-praksis-/innvendingmerking.
- Ingen skrivetilgang til eksisterende Kunnskapsbase/RAG-modeller eller tabeller.
- Kundeisolasjon fra dag én.

Konsekvens av v0.5-korrigeringen:

- Eksisterende claim extraction beholdes som et lag for Procynias egne påstander, vurderinger og innvendinger.
- Eksisterende lint/helsekontroll beholdes.
- Eksisterende upload/extract/ingest-grunnmur beholdes.
- `WikiArticleAiClient` beholdes som teknisk writer/valideringspunkt for `article`-sider.
- Dagens `source → one page → article`-flyt er for smal, men `article per source` er fortsatt riktig som ett av flere output-lag.
- Neste hovedspor er **Karpathy-alignment: page types, schema, index, log, backlinks og compile decision**.

Konsekvens av v0.6-korrigeringen (se arkitekturnotatet foran §1):

- Sidegenerering (article/summary/concept/entity) beholdes uendret som teknisk grunnlag — runtimeflyten fungerer.
- `EnterpriseWikiPageLink`, graph-endepunktet (8E-19) og graph-UI (8E-20) beholdes som modell/infrastruktur, men lenkebyggingen (`EnterpriseWikiBuildPageLinksService`) må korrigeres fra mekanisk kombinatorikk til deterministisk parsing av inline `[[wikilinks]]`.
- Neste hovedspor er **ikke** graph-visualisering, generell resume-arkitektur eller andre utvidelser. Neste hovedspor er Fase 8I: inline wiki-lenker, deterministisk parsing, materialiserte relasjoner, backlinks og inkrementelt vedlikehold av eksisterende sider.
- **Oppdatert (v0.12, 2026-08-09):** Fase 8I er fullført. **Fase 8J-1B er levert** (commit `e0dba82`) — maintainer decision får nå samme adresserbare source catalog som sidegenerering. Structured prose provenance og fler-element-proveniens per blokk var allerede på plass. Neste hovedspor er observasjon av 8J-1B på flere gyldige testtilfeller, deretter design av 8J-2; 8J-1A gjenstår som selvstendig robusthetsarbeid.

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

Korrigert målarkitektur etter v0.6 (utvider v0.5 med eksplisitt lenking — se arkitekturnotatet foran §1):

```text
Enterprise Wiki source upload/import
→ Enterprise Wiki extraction
→ immutable raw source
→ read existing Wiki catalog and relevant current page versions
→ schema-guided compile decision
→ create/update source article page
→ create/update source summary page
→ create/update relevant shared concept/entity pages
→ write new page versions with content_markdown, including inline [[wikilinks]]
→ deterministic parse and resolve of wikilinks
→ materialize EnterpriseWikiPageLink from resolved wikilinks
→ derive backlinks and graph edges from EnterpriseWikiPageLink
→ preserve claims/source references/lint as verification layer
→ update index/log/health state
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
- **Backlinks** er en deterministisk avledet lenkegraf — projisert fra inline `[[wikilinks]]` i `content_markdown`, ikke en uavhengig AI-generert sannhet (v0.6, se §4.10).
- **Artikkelinnhold** lagres som `content_markdown` på `enterprise_wiki_page_versions`.
- **Claims** er verifikasjonsenheter som støtter artikkel, reviewer og lint.
- **Source references** dokumenterer hvor claims og sentrale, direkte kildeutsagn kommer fra.
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

Én konkret Procynia-påstand, vurdering eller innvending per rad. En side kan ha mange slike rader. Direkte kildeutsagn skal normalt ikke bli claims; de skal inn i wikiinnholdet med provenance og kildekobling. Claims er verifikasjonsenheter for Procynias egne påstander og utfordringer, og støtter reviewer og lint.

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

Kildebevis på claim- og kildeutsagnsnivå. Når en claim finnes, skal den ha minst én kildereferanse; direkte kildeutsagn skal ha provenance og blokkreferanse selv om de ikke er claims.

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
| `source_element_key` | varchar nullable | Stabil nøkkel for kildeelementet utdraget kommer fra (f.eks. `paragraph-3`, `listitem-1`) |
| `source_element_type` | varchar nullable | `paragraph`, `list_item`, `table_row`, `image`, `manual` |
| `source_row_key` | varchar nullable | Radnøkkel for tabellkilder |
| `source_cell_key` | varchar nullable | Cellenøkkel for tabellkilder |
| `source_column_key` | varchar nullable | Kolonnenøkkel for tabellkilder |
| `created_at` | timestamp | |

Constraint: Minst én rad per `enterprise_wiki_claim_id`. Håndheves i applikasjonslaget ved ingest. Direkte kildeinnhold uten claim får i stedet provenance-/referansesporing på sideversjonen.

> **Merk (v0.12, korrigert 2026-08-09):** element-kolonnene over finnes allerede i databasen, og `EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPES` støtter allerede `paragraph`/`list_item`/`table_row`/`image`/`manual`. **Både produsent- og konsumentsiden er i drift for DOCX:** `EnterpriseWikiDocumentSourceElementService::inspect()` produserer reelle elementer, og genererte blokker bærer faktiske `source_element_key`-verdier (`paragraph-*`, `listitem-*`, `tbl*-row*`). En tidligere formulering her hevdet at produsentsiden manglet og at nøklene var tomme eller syntetiske — **det var feil**, se korreksjonsboksen i Fase 8J. Gapet i beslutningslaget er lukket av Fase 8J-1B (commit `e0dba82`). Merk også at **fler-element-proveniens per blokk allerede finnes** i `content_blocks_json.source_elements` — de flate feltene er kun primary-alias til første element. Ingen ny parallell proveniensmodell skal innføres for Wiki.

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

> **v0.6-status på `wiki/backlinks.md`-raden over:** Ikke lenger "mangler" i betydningen "ingen tabell finnes" — `enterprise_wiki_page_links` og `EnterpriseWikiPageLink` finnes (Fase 8E-16). Det som mangler er at raden **avledes fra faktiske inline wikilinks** i stedet for mekanisk kombinatorikk. Se §4.10.

---

### 4.10 Wiki-lenker: kanonisk kilde og projeksjon (v0.6-korrigering)

Denne underseksjonen er bindende og korrigerer Fase 8E-16 (se også arkitekturnotatet foran §1).

**Kanonisk kilde:**

- `EnterpriseWikiPageVersion.content_markdown` er den kanoniske innholdskilden for en side.
- Inline wikilinks i `content_markdown` for **current** version er den kanoniske kilden til relasjoner mellom sider — ikke en separat AI-generert relasjonsbeslutning.

**Lenkesyntaks:**

| Format | Betydning |
|---|---|
| `[[target-slug]]` | Lenke til side med gitt slug, synlig tekst = tittelen på målsiden |
| `[[target-slug\|synlig tekst]]` | Lenke til side med gitt slug, med eksplisitt ankertekst |
| `[[target-slug#section]]` | Valgfritt — kun hvis parser/renderer kan håndtere seksjonsanker trygt |

Eksempler: `[[business-case]]`, `[[prosjekteier|prosjekteieren]]`, `[[prosjektstyring#roller]]`.

**Projeksjon, ikke egen sannhet:**

- `EnterpriseWikiPageLink` skal være en **databaseprojeksjon** av de faktiske wikilinkene i sideinnholdet — raden eksisterer *fordi* teksten inneholder en lenke, ikke fordi en AI eller en kombinatorisk regel har besluttet at to sider "bør" henge sammen.
- Backlinks skal avledes deterministisk fra de samme wikilinkene (invers retning av samme projeksjon).
- Graph edges skal avledes fra de samme relasjonene som backlinks — samme datasett, to visninger.
- Konsekvens: `content_markdown`, backlinks og graf skal **aldri** representere tre uavhengige sannheter. Det finnes én kilde (innholdet) og to deterministisk avledede projeksjoner.

**Hva dette endrer i praksis for `EnterpriseWikiBuildPageLinksService` (Fase 8E-16):**

- I dag: bygger alle kombinasjoner av page-type-par innenfor én run sine pivot-rader (mekanisk full mesh mellom article/summary/concept/entity).
- Etter v0.6: skal parse `content_markdown` for `[[wikilinks]]`, resolve til `to_page_id` scoped til kunde, og kun materialisere `EnterpriseWikiPageLink`-rader for lenker som faktisk finnes i teksten.
- Modellen `EnterpriseWikiPageLink`, migrasjonen, graph-endepunktet (8E-19) og graph-UI (8E-20) beholdes uendret som infrastruktur — det er kun *kilden* til radene i tabellen som korrigeres.

Se Fase 8I for full implementeringsplan.

### 4.11 Karpathy-kompatibel komponentmapping (v0.6)

| Karpathy Wiki | Procynia |
|---|---|
| Markdown page | `EnterpriseWikiPageVersion.content_markdown` |
| WikiCompilerAgent | Enterprise Wiki maintainer/compiler-flyt (`EnterpriseWikiMaintainerDecisionService` + generatorene) |
| `[[wikilinks]]` | `[[slug]]` / `[[slug\|anchor]]` i `content_markdown` |
| WikiScanner | Deterministisk wiki-lenke-parser (Fase 8I, del C) |
| WikiLinterAgent | Enterprise Wiki lint + semantisk vedlikehold (`EnterpriseWikiAppliedRunLintService`, semantic QA) |
| `backlinks.md` | Databaseavledede backlinks (Fase 8I, del F) |
| filesystem graph | `EnterpriseWikiPageLink` + graph-endepunkt (8E-19/8E-20), nå avledet fra faktiske wikilinks |

Databasebruk i stedet for flate filer er en **implementasjonsforskjell**, ikke en endring av Karpathy-konseptet. Alle syv radene over representerer samme logiske lag som i referansemodellen.

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
2. **Verifikasjonslaget** — sekundær visning: Procynia-claims, excerpts, kildekoblinger, kvalitetssignaler, konflikter og lint.

Bindende UX-prinsipper:

- `/app/wiki/{slug}` skal primært se ut som en wikiartikkel, ikke som en rå claim-tabell.
- Brukeren skal alltid se status: `draft`, `pending_review`, `approved` eller `rejected`.
- Pending innhold skal tydelig merkes som utkast.
- Godkjenning skal ikke være en «blind» knapp.
- Brukeren skal kunne åpne verifikasjonsgrunnlaget for artikkelen.
- Procynia-påstander skal kunne spores tilbake til raw kildedokument.
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

- `ProcessEnterpriseWikiSection` kan fortsatt ekstrahere claims for Procynias egne påstander, vurderinger og innvendinger.
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

7. Assertion and provenance capture
   └─ Ekstraherer Procynia-påstander, vurderinger og innvendinger der det er relevant
   └─ Lagrer enterprise_wiki_claims og enterprise_wiki_source_references

8. Page writing/update (v0.6: inkluderer inline wikilinks)
   └─ Oppretter/oppdaterer article-side for kilden
   └─ Oppretter/oppdaterer summary-side for kilden
   └─ Oppretter/oppdaterer relevante concept/entity-sider, med kontekst om relevante eksisterende sider (index lookup)
   └─ For hver berørt side opprettes ny EnterpriseWikiPageVersion
   └─ content_markdown skrives som lesbar Markdown, **med semantiske inline `[[wikilinks]]` i brødteksten** der begreper allerede omtales andre steder i Wikien
   └─ Claims/source references knyttes til riktig sideversjon der de brukes som verifikasjon; direkte kildeutsagn får provenance og side-/blokkreferanse, ikke claim-fisering

9. Deterministisk lenkeparsing og materialisering (v0.6 — nytt steg, se Fase 8I)
   └─ Parser `content_markdown` for `[[wikilinks]]` per oppdatert/opprettet side
   └─ Resolver slugs scoped til kunde, rapporterer broken links
   └─ Materialiserer `EnterpriseWikiPageLink`-rader fra de resolvede lenkene (projeksjon, ikke egen AI-beslutning)
   └─ Bygger backlinks og graph edges fra samme materialiserte relasjoner

10. Log/index/lint
   └─ Run-logg dokumenterer hvilke sider kilden endret eller foreslo
   └─ Index/sideoversikt oppdateres
   └─ Lint sjekker manglende kilder, konflikter, stale claims, orphan pages, broken wikilinks, stub pages og manglende inline-koblinger til relevante concepts/entities

11. Review
   └─ Brukeren leser article/summary/concept/entity-sider, med klikkbare inline-lenker
   └─ Brukeren kontrollerer Procynia-claims, kilder, provenance og lint
   └─ System Owner godkjenner eller avviser
```

**Viktig prinsipp etter v0.6:** AI kan returnere strukturert JSON som inneholder compile-beslutning, Markdown-innhold (med inline wikilinks) og verifikasjonsgrunnlag. `content_markdown` er hovedinnholdet på en wiki-side og den kanoniske kilden til relasjoner. Output deles i Karpathy-lag: `article`, `summary`, `concept/entity`, `index`, `log`. Backlinks og graf er **ikke** egne AI-output-lag lenger — de avledes deterministisk av wikilinkene i `content_markdown` (se §4.10). Claims skal kun brukes for Procynias egne påstander, vurderinger og innvendinger.

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
| `ProcessEnterpriseWikiSection` | `enterprise-wiki` | Sender én seksjon til AI, lagrer claims for Procynias egne påstander/innvendinger og kilder |
| `FinalizeEnterpriseWikiIngest` | `enterprise-wiki` | Slår sammen resultater. Midlertidig én side; skal senere koordinere article/summary/concept/entity-output og ingest-run |

Ny queue: `enterprise-wiki`. Holdes separat fra `ai-requirements` for å unngå ressurskonflikter med produksjonsflyt.

---

## 9. Lint og helsekontroll

Lint skal etter v0.5 speile Karpathy-linteren bedre: først deterministisk strukturscan, deretter eventuelt LLM-basert semantisk vurdering.

Planlagte lint-kontroller som kan kjøres on-demand eller som scheduled job:

| Check-type | Alvorlighet | Beskrivelse |
|---|---|---|
| `claim_without_source` | error | Procynia-claim-rad mangler minst én source_reference |
| `source_reference_missing_excerpt` | warning | Kildereferanse finnes, men mangler excerpt |
| `document_ingest_failed` | warning | Kildedokument feilet ingest/extract |
| `broken_wiki_link` | error | `[[wikilink]]` peker til side som ikke finnes |
| `orphan_page` | info | Wiki-side uten inbound links |
| `stub_page` | info | Side er for kort/tynn til å være nyttig |
| `missing_backlinks` | info | Backlinks/lenkegraf er ikke oppdatert |
| `conflicting_claims` | warning | To Procynia-claims eller sider motsier hverandre |
| `source_version_superseded` | warning | Kildeversjon har `approval_status = superseded` |
| `stale_approved_page` | warning | Godkjent side, men kildens `file_hash_sha256` er endret siden `last_source_hash` ble beregnet |
| `low_confidence_page` | info | Mer enn halvparten av Procynia-claims har `confidence = low` eller `uncertain` |
| `missing_owner` | info | `owner_user_id` er null |
| `overdue_review` | warning | Godkjent side uten review de siste N dager (konfigurerbart) |
| `missing_topics` | info | Siden har ingen `enterprise_wiki_page_topics`-rader |

Lint-resultater lagres i `enterprise_wiki_lint_findings`. UI viser helseindikator per side.

> **v0.6-presisering:** `broken_wiki_link` og `missing_backlinks` forutsetter den korrigerte modellen i §4.10 — de skal sjekke faktiske inline `[[wikilinks]]` i `content_markdown` mot materialiserte `EnterpriseWikiPageLink`-rader, ikke mot en mekanisk kombinatorikk av page types. Fase 8E-17s lenke-relaterte sjekker (`page_without_outgoing_links`, `orphan_concept_page`, `orphan_entity_page`, `article_without_concept_or_entity_links`, m.fl.) må oppdateres til å reflektere dette når Fase 8I er implementert — se der.

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
| **Sekundær output** | Procynia-claims, excerpts, kildereferanser og lint |
| **Primær UI** | Wiki-side som ligner en lesbar wiki, med riktig page type |
| **Review UI** | Verifikasjonslag rundt artikkel/concept/summary |
| **Godkjenning** | Godkjenning av wikiinnhold, støttet av claim-/kildekontroll for Procynias egne påstander |
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

8. **Backlinks** — ~~Skal backlinks lagres som egen tabell, genereres fra `[[wikilinks]]` ved lint, eller vises som lint-/index-output?~~ **Avklart i v0.6:** Backlinks lagres via den eksisterende `EnterpriseWikiPageLink`-tabellen, men materialiseres deterministisk fra faktiske `[[wikilinks]]` i `content_markdown` — verken ved lint alene eller som ren visning uten lagret projeksjon. Se §4.10 og Fase 8I.

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

21. **RequirementWiki*-tjenestenes claim-opphavsfiltrering (identifisert v0.8, 2026-07-21)** — `RequirementWikiResearchService`, `RequirementWikiAnswerService` og `RequirementWikiPageRanker` leser i dag `EnterpriseWikiClaim` uten å filtrere på `content_origin`, slik at `best_practice`/`unsupported_generated_content`-claims brukes identisk med `source_based`-claims som grunnlag for genererte Wiki-svar. Fantes før v0.7/v0.8 og er ikke rettet — se v0.8-notatet over. Bør avklares: skal disse tjenestene skille grunnlagstype eksplisitt i svaret (f.eks. markere hvilke deler som er beste praksis vs. dokumentert), og hvordan uten en større ombygging av de 3 AI-vendte tjenestene?

22. **Eierskapsenhet for kildesubstans (identifisert v0.12, 2026-08-09)** — Hvilken proveniensenhet skal `owned_topics` forankres i? `source_element_key` alene er avvist (ett avsnitt/list item bærer legitimt flere uavhengige fakta). Kandidat er `source_element_key` + verifisert excerpt/span. **Merk at fler-element-proveniens per blokk allerede finnes** — det åpne spørsmålet gjelder granulariteten *innenfor* et element, ikke om elementene registreres. Åpne underspørsmål: er `excerpt` alene tilstrekkelig, bør `char_start`/`char_end` brukes, kan claim-/fact-identitet gjenbrukes, eller trengs en egen source-fact-modell? Skal avgjøres **etter** at 8J-1B er observert på flere gyldige testtilfeller — ikke før.

23. **Persistensform for strukturerte source elements (identifisert v0.12, 2026-08-09)** — Skal `text_elements` og `tables` persisteres i separate kolonner på `enterprise_wiki_documents` (som `saved_notice_ai_documents` allerede gjør), eller kan `DocumentTextExtractor`-kontrakten lagres samlet uten å miste semantikk? Avgjøres i implementeringsfasen for **8J-1A** — planen låser bevisst ikke antall kolonner på forhånd. Merk at dette er persistens/caching av eksisterende parser-output, ikke innføring av ny proveniens.

25. **Serialiseringsform for maintainer source catalog (identifisert 2026-08-09, 8J-1B)** — Skal flat `sourceText` **erstattes** av en kompakt keyed katalog i maintainer-prompten, eller skal en hybrid brukes der fulltekst ikke dupliseres? Hvordan representeres tabeller og figurer i samme kontrakt uten å bryte dagens `figureCandidates`-håndtering? Avgjøres i implementeringsfasen og skal måles på tokenbruk.

24. **Cross-version identitet for source element keys (identifisert v0.12, 2026-08-09)** — Dagens `paragraph-N`/`listitem-N` er posisjonelle og stabile kun innenfor samme dokumentversjon. Trengs sterkere kryssversjons-matching (content hash, `heading_path` + ordinal innen seksjon, annet) når et dokument lastes opp på nytt i revidert form? Skal **ikke** blokkere 8J trinn 1.

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
| Fase 8E | Karpathy-alignment: page types/schema/index/log/backlinks/compile decision | Teknisk implementert (8E-10–8E-20), men **8E-16/8E-19/8E-20 sin lenke-/grafmodell er konseptuelt korrigert i v0.6** — se Fase 8I |
| **Fase 8F** | **Enterprise Wiki forvaltning av kilder, kjøringer og generert innhold** | **8F-0–8F-5 fullført (forvaltningsflate komplett) · 8F-6 og 8F-7 parkert som unntaksfunksjoner** |
| Fase 8G | Enterprise Wiki coverage/eval og post-ingest QA | 8G-1–8G-7 fullført |
| Fase 8H | Continuous Enterprise Wiki Maintainer Loop | **8H-kjerne delfase 1 + 2 fullført** (kildemonitoring, intelligent retry, dyp reparasjon av claims/refs/lenker) · **8H-utvidelse fullført** (snapshot-basert terskelreparasjon og regresjonsdeteksjon) |
| Runtimeflyt (queue-splitting) | Staged page-generation queues (article+summary → concept+entity → verifisering) | **Fullført** (commit `b6ccd87`) — teknisk grunnmur for at sidegenerering faktisk fullfører |
| **Fase 8I** | **Karpathy Wiki linking and incremental maintenance** | **Fullført (8I-1–8I-6), commits `d0a608d`, `ab35d52`, `716477e`, `014861f`.** Se egen seksjon under. |
| **Fase 8J** | **Structured prose provenance and semantic ownership** | **8J-1B LEVERT** (commit `e0dba82`, bevist aktiv i run 23) — maintainer decision får adresserbar source catalog. Structured prose provenance og **full fler-element-proveniens per blokk** var allerede eksisterende arkitektur (korrigert 2026-08-09). **8J-1A** (persister/cache parser-output — robusthet/ytelse) gjenstår som selvstendig arbeid utenfor kritisk sti. **8J-2** (semantic fact/span ownership) utsatt til 8J-1B er observert på flere gyldige testtilfeller. Se egen seksjon under og Arkitekturnotat v0.12. |
| Produksjonsaktivering | Kontrollert aktivering — etter 8G, 8H **og 8I** | Sist — ikke aktiv fase. 8J er **ikke** en ny gate for produksjonsaktivering (se 8J-seksjonen). |
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

> **v0.6-korrigering (2026-07-11):** Migrasjonen, modellen, `EnterpriseWikiPageTraversalService` og de 37 testene over står ved lag som teknisk grunnlag — de er ikke feil i seg selv. Men `EnterpriseWikiBuildPageLinksService`s **kilde til rader** er feil konsept: "bygger alle kombinasjoner i begge retninger" betyr at enhver article i en run lenkes mekanisk til enhver summary/concept/entity i samme run, uavhengig av om noe faktisk henger sammen semantisk. Dette er full-mesh-kobling, ikke en wiki-lenkegraf, og det strider mot v0.6-prinsippet i §4.10 om at `EnterpriseWikiPageLink` skal være en projeksjon av faktiske inline `[[wikilinks]]`. Fase 8I erstatter kombinatorikk-logikken i denne servicen med deterministisk parsing av `content_markdown`. Link type-konstantene, unik-indeksen og traversal-servicen forventes gjenbrukt uendret.

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

> **v0.6-korrigering:** Run-, side-, claims- og source reference-sjekkene over er upåvirket og gyldige som de er. De 8 **lenke**-sjekkene (`page_without_outgoing_links`, `article_without_summary_link`, `orphan_concept_page`, `missing_reverse_link` m.fl.) leser i dag `EnterpriseWikiPageLink`-rader bygget av 8E-16s kombinatorikk, og gir derfor falske "OK"-resultater: en side kan se ut som lenket selv om ingen inline `[[wikilink]]` faktisk peker til den. Selve `EnterpriseWikiAppliedRunLintService`-koden for disse 8 sjekkene forventes gjenbrukt, men vil først måle noe reelt når Fase 8I korrigerer datakilden. Se §9 for tilsvarende presisering av de generelle lint-kodene.

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

> **v0.6-korrigering:** `WikiController::show()`, `WikiShow.jsx` og `EnterpriseWikiPageTraversalService` er teknisk korrekte og krever ingen kodeendring. Men fordi de leser `EnterpriseWikiPageLink` som i dag kommer fra 8E-16s kombinatorikk, viser Navigasjon-seksjonen i praksis relasjoner som ikke reflekterer noe faktisk lenket innhold — en article kan vises med "relaterte konsepter" den aldri nevner. Dette retter seg selv når Fase 8I korrigerer datakilden; UI-koden endres ikke.

#### Fase 8E-19 — Enterprise Wiki graph data foundation — Fullført

**Implementeringscommit:** `1486007` — Add Enterprise Wiki graph data foundation (Phase 8E-19)

Implementert:
- `EnterpriseWikiGraphDataService` (`app/Services/EnterpriseWiki/`) — bygger stabilt grafpayload (nodes, edges, summary, scope) fra `EnterpriseWikiPageLink`-tabellen (se v0.6-korrigering under for hva denne tabellen faktisk inneholder i dag). Tre scope-varianter:
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

> **v0.6-korrigering:** Servicen, endepunktet, JSON-kontrakten og de 39 testene er teknisk korrekte og beholdes uendret. Men fordi edge-datasettet (`EnterpriseWikiPageLink`) i dag kommer fra 8E-16s kombinatorikk, er edges i grafen i praksis uten semantisk mening — grafen viser derfor **nodes med kanter som ikke reflekterer noe reelt forhold**, ikke "nodes uten edges". Endepunktet trenger ingen kodeendring når Fase 8I korrigerer kilden til `EnterpriseWikiPageLink` — det er allerede bygget for å konsumere denne tabellen som eneste sannhet.

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

> **v0.6-korrigering:** UI-komponenten beholdes uendret — den er en ren visning av 8E-19s JSON-kontrakt og trenger ingen endring. Det som var visuelt misvisende er datagrunnlaget, ikke visningen: en graf bygget på 8E-16s kombinatorikk vil vise et tett, sammenkoblet nettverk selv om ingen sider faktisk er lenket i innholdet. Grafvisningen blir først meningsfull når Fase 8I er implementert.

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

> **Presisert i v0.10 (2026-08-01):** beskrivelsen under av QA som «completion gate» er historisk korrekt for hvordan 8G ble bygget, men er **opphevet som produktprinsipp**. Claims og QA er en frivillig kvalitetssløyfe — en kjørings/sides tilgjengelighet for bruk skal aldri avhenge av at QA er fullført. Se Arkitekturnotat v0.10 for den bindende strategien.

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

> **8H-kjerne delfase 1 + delfase 2 fullført.** 8H-utvidelse fullført (snapshot-basert terskelreparasjon og regresjonsdeteksjon).

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

> **v0.6-korrigering:** Diagnose- og reparasjonslogikken for claims og source references er upåvirket. "Page links: sider uten outbound-lenker → kaller `EnterpriseWikiBuildPageLinksService::build()`" fyller i dag inn manglende lenker via 8E-16s kombinatorikk — det reparerer altså at en side *mangler rader i tabellen*, ikke at siden faktisk *mangler en semantisk lenke i innholdet*. `EnterpriseWikiDeepRepairService` selv trenger ingen endring: når Fase 8I korrigerer `EnterpriseWikiBuildPageLinksService`, reparerer dette kallet automatisk de riktige lenkene i stedet for kombinatorikk-lenker.

#### 8H-utvidelse *(avhenger av 8G-6) — fullført:*

Implementert snapshot-basert threshold maintenance for QA-regresjoner:

- Terskelbasert repair-beslutning: quality, coverage og factual drop på 0.10, samt økning i unsupported claims, missing topics, missing key facts og lint errors på 1
- Regresjonsdeteksjon: immutabel QA-snapshot-historikk sammenlignes innenfor samme kunde, kilde og page-type-signatur
- Historikkbasert eskalering: samme signal og source hash eskaleres uten ny reparasjonsrunde
- Audit trail: regnegrunnlag, terskler, signaler, metric deltas og final status lagres i `enterprise_wiki_qa_regressions`
- Maintenance loop: `wiki:maintenance-cycle` skanner terminale QA-snapshots etter source-change retry og fører regression summary i loggen

Nye filer:

- `app/Models/EnterpriseWikiQaRegression.php`
- `app/Services/EnterpriseWiki/EnterpriseWikiQaRegressionPolicy.php`
- `app/Services/EnterpriseWiki/EnterpriseWikiQaRegressionService.php`
- `database/migrations/2026_07_10_000007_create_enterprise_wiki_qa_regressions_table.php`
- `tests/Unit/Services/EnterpriseWiki/EnterpriseWikiQaRegressionPolicyTest.php`
- `tests/Feature/App/Wiki/EnterpriseWikiQaRegressionServiceTest.php`

Oppdaterte filer:

- `app/Models/EnterpriseWikiQaSnapshot.php` — regression-relasjon for immutable snapshots
- `app/Services/EnterpriseWiki/EnterpriseWikiMaintenanceCycleService.php` — kaller snapshot-regresjonsskann etter source-change retry
- `app/Console/Commands/EnterpriseWikiMaintenanceCycle.php` — beskriver snapshot-regresjoner i command help

Roadmap-rekkefølge: 8G-3 (fullført) → 8G-4 (semantisk AI-QA) → 8G-5 (critique/revise) → 8G-6 (snapshots) → 8G-7 (lineage) → 8H-kjerne delfase 1 (fullført) → 8H-kjerne delfase 2 (fullført) → 8H-utvidelse (fullført) → **Fase 8I (fullført, 8I-1–8I-6)**.

### Fase 8I — Karpathy Wiki linking and incremental maintenance

> **Status (2026-07-11): Fase 8I (8I-1 til 8I-6) fullført.** Karpathy Wiki-konseptet — inline wikilinks, backlinks, canonical traversal/graph, Wiki-aware generering, incremental relinking, og deterministisk lint + semantisk repair av lenker — er nå levert i sin helhet. Ingen gjenstående blocker fra 8I.
>
> **Oppdatert (v0.12, 2026-08-09):** formuleringen «neste hovedspor er graph-visualisering eller generell resume-arkitektur» er erstattet. Run 20 (første reelle kompletterende dokumentimport) viste at beslutningslaget manglet adresserbar kildeproveniens. **Fase 8J-1B er nå levert** (commit `e0dba82`); neste hovedspor er observasjon av den før 8J-2 designes.
>
> - **8I-1 (canonical wikilink-syntax, parser, validering)** og **8I-2 (deterministisk materialisering til `EnterpriseWikiPageLink`)** — fullført, commit `d0a608d`.
> - **8I-3 (rendering av inline wikilinks, backlinks, canonical traversal)** og **8I-4 (Wiki-aware LLM-generering av inline wikilinks)** — fullført, commit `ab35d52`.

**Mål:** Sider skal inneholde semantiske inline `[[wikilinks]]` i brødteksten, lenkene skal parses deterministisk og materialiseres som `EnterpriseWikiPageLink`, og backlinks/graf skal avledes fra samme datasett. Når en ny concept/entity-side opprettes, skal maintainer/compiler kunne relinke eksisterende sider som allerede omtaler begrepet.

Claims og source references forblir verifikasjonslaget — de er ikke hovedinnholdet og erstatter ikke wikilinking (uendret prinsipp fra v0.3/v0.5).

**A. Wiki-link syntax — fullført (8I-1)**
- `EnterpriseWikiLinkParser` (`app/Services/EnterpriseWiki/`) støtter `[[slug]]` og `[[slug|anchor text]]`, deterministisk, ingen DB/LLM.
- `[[slug#section]]` er bevisst ikke implementert — `#section` behandles som bokstavelig del av sluggen.
- `EnterpriseWikiLinkResolver` klassifiserer hver forekomst `valid`/`broken`/`self_link`, customer-scoped — ukjent slug og cross-customer slug er umulig å skille (begge `broken`).
- Ingen lenker til ukjente slugs eller self-links materialiseres.

**B. Wiki-aware generation — fullført (8I-4)**
- `EnterpriseWikiLinkCatalogService::buildForPage()` gir hver page-generator en customer-scoped katalog (`slug`, `title`, `page_type`): alle andre applied pages i runen (aldri avkortet) pluss inntil `MAX_OTHER_PAGES = 50` andre eksisterende kundesider (nyest oppdatert først).
- `WikiPageContentAiClient` sender katalogen som «ALLOWED WIKILINK TARGETS» i user-prompten og instruerer eksplisitt bruk av `[[slug|naturlig visningstekst]]`/`[[slug]]` i brødteksten, semantisk relevant, ikke mekanisk.
- Modell/`max_output_tokens`/`reasoning.effort`/`store`/JSON-schema (`page.markdown` only) er uendret.

**C. Deterministic parser — fullført (8I-1)**
- Parser leser `content_markdown` direkte (`EnterpriseWikiLinkParser::parse()`), ingen reparasjon av ugyldig markup.
- `EnterpriseWikiLinkResolver` resolver slugs scoped til customer og rapporterer broken/self-link.
- `resolve()` dedupliserer til én logisk relasjon per target; `resolveOccurrences()` (nytt i 8I-3) gir per-forekomst-detalj til rendering uten å endre `resolve()`s kontrakt.
- Lenker i teksten er fortsatt kanonisk kilde — ingen egen side-tabell med duplikat sannhet.

**D. Materialized relations — fullført (8I-2)**
- `EnterpriseWikiBuildPageLinksService::materializeWikilinksForPage()`/`materializeWikilinksForRun()` avleder `EnterpriseWikiPageLink(link_type=wikilink)` fra current page version, med stale-fjerning når en lenke forsvinner fra ny current markdown.
- `link_type = wikilink` er ny, eksplisitt konstant på `EnterpriseWikiPageLink` — erstatter 8E-16s kombinatorikk-logikk i den nye dokumentflyten (den gamle `build()`-metoden finnes fortsatt for `wiki:build-page-links`-kommandoen og deep repair, men kalles ikke lenger fra `EnterpriseWikiDocumentFlowService`).
- Graph edges (8E-19/8E-20) og traversal (se F) bruker samme `EnterpriseWikiPageLink`, filtrert til `link_type=wikilink`.

**E. Renderer — fullført (8I-3)**
- Ny `EnterpriseWikiWikilinkRenderer` (`app/Services/EnterpriseWiki/`) gjenbruker `EnterpriseWikiLinkParser`/`EnterpriseWikiLinkResolver` (via ny `resolveOccurrences()`) til å transformere `[[slug]]`/`[[slug|anchor]]` til vanlige Markdown-lenker (`[anchor](/app/wiki/slug)`) i et avledet `rendered_markdown`-felt — `content_markdown` i databasen er urørt.
- `WikiController::show()` sender både `content_markdown` (canonical) og `rendered_markdown` (avledet); `Show.jsx` bruker `rendered_markdown` i eksisterende `ReactMarkdown`-oppsett, med fallback til `content_markdown`.
- Broken/self-link-forekomster rendres som vanlig tekst (ankertekst uten klammer), ikke som ugyldig lenke. Wikilinks i fenced code/inline code transformeres aldri.
- Ingen nytt Markdown-bibliotek lagt til.

**F. Backlinks — fullført (8I-3)**
- `WikiController::show()` sender en dedikert `backlinks`-prop: `EnterpriseWikiPageLink where to_page_id=siden, link_type=wikilink`, customer-scoped, med source `title`/`slug`/`page_type`.
- `Show.jsx` viser dette i en egen «Lenket fra»-seksjon (gjenbruker eksisterende `LinkedPageList`-komponent). Den tidligere «Baklenker»-raden i Navigasjon-seksjonen er fjernet for å unngå duplikatvisning, siden `EnterpriseWikiPageTraversalService::incoming()` nå også er wikilink-only (se under).
- Ingen separat AI-generert backlink-tekst.

**Canonical traversal — fullført (8I-3, ikke i opprinnelig A-I-liste, men del av leveransen)**
- `EnterpriseWikiPageTraversalService` (`outgoing`, `incoming`, `relatedArticles`, `relatedConcepts`, `relatedEntities`) filtrerer nå alle spørringer til `link_type = wikilink`. Historiske kombinatoriske rader slettes ikke, men ignoreres av all canonical Wiki-navigasjon.

**G. Incremental relinking — fullført (8I-5, commit `716477e`)**
- Ny `EnterpriseWikiIncrementalRelinkService`, kjørt fra `EnterpriseWikiDocumentFlowService::continueAfterPagesGenerated()` rett etter `performMaterializeWikilinks()`: når en run oppretter/oppdaterer en concept- eller entity-side (pivot `action` created/updated), søkes det etter eksisterende customer-sider (utenfor runen) hvis current `content_markdown` nevner triggersidens tittel — deterministisk tekstsøk, ikke RAG/embeddings.
- Kandidater er cappet på `EnterpriseWikiIncrementalRelinkService::MAX_CANDIDATES_PER_TRIGGER = 10` per trigger-side, ordnet etter sist oppdatert.
- Ny `WikiLinkRevisionAiClient` (delt med 8I-6) får bare étt tillatt mål (triggersiden) og avgjør selv (`changed: bool`) om en naturlig lenke er berettiget — ingen mekanisk lenking av alle forekomster, ingen full mesh.
- Deterministisk validering før persistens gjenbruker `EnterpriseWikiLinkParser`/`EnterpriseWikiLinkResolver` nøyaktig som i 8I-4: avviser ukjent slug, self-link, cross-customer og malformed forsøk, og avviser i tillegg enhver revisjon som dropper en tidligere gyldig wikilink. Ingen reparasjon — en ugyldig revisjon forkastes, siden endres ikke.
- Ny idempotens-/proveniens-tabell `enterprise_wiki_page_relink_attempts` (unik på run+trigger+kandidat) gjør et gjentatt/dobbelt kall til `relinkForRun()` et rent no-op — ingen ny AI-kall, ingen ny page version.
- Ved faktisk endring: ny immutable `EnterpriseWikiPageVersion` (gammel versjon bevares, `is_current=false`), etterfulgt av `EnterpriseWikiBuildPageLinksService::materializeWikilinksForPage()` — samme canonical materialisering som all annen wikilink-skriving, ingen separat relasjonssannhet.
- 14 nye tester i `EnterpriseWikiIncrementalRelinkServiceTest` (kandidatvalg, dedupe/allerede-lenket, cap, cross-customer, ugyldig revisjon, ingen-endring, ny version + bevart gammel version, materialisering, backlinks/traversal/graph reflekterer ny lenke, dobbel dispatch, `ProcessEnterpriseWikiIngest` urørt). Full suite: 1009 passed, 2733 assertions.

**H. Lint — fullført (8I-6, commit `014861f`)**
- 10 nye deterministiske `EnterpriseWikiLintFinding`-koder lagt til i `EnterpriseWikiAppliedRunLintService` (kjøres allerede fra `continueAfterPagesGenerated()`, ingen ny kall-site): `broken_wikilink`, `malformed_wikilink`, `self_wikilink`, `cross_customer_wikilink` (global slug-oppslag på tvers av kunder, kun for diagnostikk — selve resolveren forblir customer-scoped), `concept_without_incoming_wikilink`, `entity_without_incoming_wikilink`, `run_targets_available_but_not_linked`, `missing_wikilink_materialization` (gyldig lenke i innhold, ingen materialisert rad i det hele tatt), `wikilink_projection_mismatch` (under-materialisert — innhold har lenke, rad mangler), `stale_wikilink_graph_edge` (over-materialisert — rad finnes, innhold har ikke lenken lenger).
- Alt avledes deterministisk fra `content_markdown → EnterpriseWikiLinkParser → EnterpriseWikiLinkResolver → EnterpriseWikiPageLink` — aldri fra graph eller maintainer decision som egen sannhet.
- Stale/under-materialisering repareres deterministisk ved å kalle eksisterende `EnterpriseWikiBuildPageLinksService::materializeWikilinksForPage()` på nytt (ingen AI involvert) — verifisert med egen test.

**I. QA — fullført (8I-6, commit `014861f`)**
- Ny `WikiLinkSemanticQaAiClient` vurderer en sides wikilinking (manglende sentrale lenker, unaturlig ankertekst, over-lenking, feil mål) mot en eksplisitt katalog (samme `EnterpriseWikiLinkCatalogService` som B/8I-4) og returnerer `assessment` (`no_change`/`repair_recommended`) + `missing_link_slugs`/`remove_link_slugs` — aldri en oppdiktet slug (klientens egen validering avviser slugs utenfor katalogen).
- Ny `EnterpriseWikiLinkSemanticRepairService::repairForRun()` kjører QA per side i runen, og ved `repair_recommended` ber `WikiLinkRevisionAiClient` (delt med 8I-5) om nøyaktig de forespurte endringene. Revisjonen valideres deterministisk før persistens: avviser malformed/broken/self/cross-customer, og avviser i tillegg enhver lenke lagt til eller fjernet som IKKE eksplisitt var bedt om av QA-diagnosen — modellen kan altså aldri gjøre mer enn det som ble spurt om.
- Ett repair-forsøk per (run, side) håndheves av ny idempotens-tabell `enterprise_wiki_page_link_qa_attempts` (unik på run+side) — samme mønster som `EnterpriseWikiSemanticRepairService` (8G-5) sitt "ett automatisk forsøk"-prinsipp.
- Ved faktisk endring: ny immutable page version, rematerialisering, og runens deterministiske lint kjøres på nytt (kun når noe faktisk ble reparert) slik at neste lint-runde reflekterer den fikserte tilstanden.
- Kjøres som eget steg i `EnterpriseWikiDocumentFlowService::continueAfterPagesGenerated()` rett etter lint og før post-ingest QA — endrer ikke QA_STATUS-tilstandsmaskinen fra 8G/8H, rører ikke Responses-decoder, køarkitektur eller to-pass-rekkefølgen.
- 20 nye tester i `EnterpriseWikiLinkLintAndSemanticRepairTest` (hver lintkode, stale-projeksjon reparert deterministisk, QA finner manglende lenke, repair legger til/fjerner lenke, repair avviser uforespurt tillegg/broken/self-link, uendret-vurdering gir ingen version, endring gir én version + rematerialisering + backlink/traversal/graph oppdatert, dobbel repair gir ikke dobbel version, ny lint-runde blir grønn, `ProcessEnterpriseWikiIngest` urørt). Full suite: 1029 passed, 2779 assertions.

**Viktige grenser (bindende for Fase 8I, bekreftet overholdt i 8I-1–8I-6):**
- En separat graph-AI skal ikke bestemme relasjoner.
- Maintainer decision alene skal ikke bli canonical graph.
- Claims skal ikke bli wiki-lenker.
- Backlinks skal ikke lagres som manuelt generert tekst.
- Frontend skal ikke bygge graph fra heuristikk.
- Det skal ikke opprettes full mesh mellom alle pages (dette er nettopp feilen 8I retter opp).
- Kunnskapsbase/RAG skal ikke blandes inn.
- `KnowledgeItem`, `KnowledgeItemVersion`, `KnowledgeItemChunk`, dagens Kunnskapsbase/RAG, billing, Filament/admin, AI workspace og legacy `ProcessEnterpriseWikiIngest` røres ikke.

### Fase 8J — Structured prose provenance and semantic ownership

> **Status (oppdatert 2026-08-09 etter run 23):** **8J-1B er levert** (commit `e0dba82`) og bevist aktiv i en reell kjøring. **8J-1A** (persister eksisterende parser-output — robusthet/ytelse) gjenstår som selvstendig, uavhengig arbeid. **8J-2** (semantic fact/span ownership) er bevisst utsatt til 8J-1B er observert på flere gyldige testtilfeller.
>
> Utløst av run 20, den første reelle importen av et *kompletterende* dokument etter at grunnlagsdokumentet allerede var importert. **Fasen ble opprinnelig definert på et feil premiss** — at Wiki manglet structured prose provenance. Premisset er korrigert etter kontroll av det faktiske produksjonskallet; se korreksjonsboksen rett under. Se Arkitekturnotat v0.12 for beslutningen og den korrigerte run 20-læringen.

**Mål:** gi Enterprise Wiki den strukturerte kildeproveniensen som kreves for at eierskap senere kan avgjøres på substansnivå — uten språkspesifikke regler, topic-title-heuristikk, regex mot domenebegreper eller fuzzy tekstmatching som hovedmekanisme.

Fasen er delt i to trinn som **ikke** skal implementeres sammen.

---

#### Faktisk arkitekturstatus (verifisert 2026-08-09) — korrigerer et tidligere feilaktig premiss

> **Korreksjon.** En tidligere formulering i denne fasen sa at strukturert granularitet «bestilles aldri av Wiki», at Wiki-opplasting kun gir flat tekst, og at `EnterpriseWikiDocumentSourceElementService` derfor faller tilbake på `wholeDocumentElement()` (`document-21-full-text`). **Dette var feil.** Feilen oppsto i utredningens diagnoseskript, som kalte `sourceElementsForGeneration($document, [])` med en tom elementliste og leste resultatet som produksjonsadferd. Produksjonskoden sender ikke en tom liste. Formuleringen er erstattet av avsnittet under, etter kontroll av det faktiske produksjonskallet. Historikken beholdes her bevisst, slik at det er sporbart hvorfor fasen først ble definert feil.

**Enterprise Wiki har allerede structured prose provenance i genereringskjeden.** Verifisert i kode og mot ekte data:

- `EnterpriseWikiDocumentSourceElementService::inspect()` returnerer allerede strukturerte elementer fra DOCX: `paragraph`, `list_item`, `table_row` og `image`, hver med `source_element_key`, `source_element_type`, seksjonskontekst (`section_number`/`section_title`), `page_reference` og faktisk tekst.
- `EnterpriseWikiGenerateAppliedPagesService` sender disse videre på alle sine kallsteder: `sourceElementsForGeneration($document, $this->sourceElementService->inspect($document)['elements'])`.
- Proveniensen lander nedstrøms. Målt på gjeldende sideversjoner: **48 av 89 blokker bærer en reell `source_element_key`** (`paragraph`: 31, `list_item`: 17). De 41 uten nøkkel er `structural`-blokker (overskrifter og krysshenvisninger), som korrekt ikke har kildegrunnlag.
- `wholeDocumentElement()` er dermed **ikke** normaltilstanden for DOCX. Den er en reell fallback for formater uten strukturell parser og for dokumenter der filen ikke lar seg parse.

**Genererte blokker har allerede FULL fler-element-proveniens.** Verifisert i kode og mot persisterte data (2026-08-09):

- AI-kontrakten bruker **`source_element_keys[]`** og **`source_element_types[]`** — begge arrays, begge `required` i det strenge skjemaet. Ingen singularform finnes i AI-kontrakten.
- Parsing (`parseBlocksResponse()`) validerer at listen er et array og ikke-tom for `source_based`, men **trunkerer eller dedupliserer aldri**.
- `EnterpriseWikiPageContentBlockService::buildBlocksFromStructuredResult()` resolver **hver** nøkkel mot katalogen (hard feil ved ukjent nøkkel) og persisterer **alle** i `content_blocks_json.source_elements`.
- Hvert element i `source_elements` bærer sin egen `source_element_key`, `source_element_type`, `source_row_key`, `source_excerpt`, `page_reference`, `source_type`, `source_id`, `source_label`, `source_hash` og `document_version_hash`.
- De flate feltene `source_element_key`/`source_element_type`/`source_excerpt` er **kun en primary/convenience-alias til første element**, beholdt for bakoverkompatibilitet — ikke den komplette proveniensen.
- Begge representasjoner er allerede i aktiv bruk: `EnterpriseWikiVerifyPageClaimsService`, `EnterpriseWikiDocumentOwnerApprovalService`, `EnterpriseWikiRepairRunClaimSourceLinksService`, `EnterpriseWikiImageBlockBuilder` og `EnterpriseWikiTableBlockBuilder` leser `source_elements`-listen.

Målt på reelle blokker (run 23, brukt som regresjonseksempel — aldri som regel):

```text
block-0002  flat=paragraph-2   source_elements=4  [paragraph-2, tbl0-row0, tbl0-row1, tbl0-row2]
block-0005  flat=paragraph-3   source_elements=4  [paragraph-3, listitem-0, listitem-1, listitem-2]
block-0009  flat=listitem-3    source_elements=3  [listitem-3, listitem-4, listitem-5]
block-0007  flat=paragraph-4   source_elements=2  [paragraph-4, paragraph-5]
```

> **Korreksjon (2026-08-09).** En tidligere analyse konkluderte at proveniens «kollapset» fra flere nøkler til én, fordi den kun leste det flate `source_element_key`-feltet. **Det var feil** — ingen reduksjon skjer noe sted i datapathen. **Fler-element-proveniens er eksisterende arkitektur, ikke fremtidig arbeid, og krever ingen databaseendring:** alt ligger allerede i `content_blocks_json`.

**Det som faktisk gjenstår:**

1. Katalogen **persisteres ikke** — den re-parses fra originalfilen på disk ved hvert behov (`resolveDocumentPath()` brukes flere steder i tjenesten, som igjen kalles fra flere flyter). Robusthet/ytelse, ikke et proveniensgap. → **8J-1A**, uavhengig arbeid
2. ~~**Maintainer decision får ikke katalogen.**~~ → **8J-1B, LEVERT** (commit `e0dba82`). Beslutningslaget mottar nå samme adresserbare katalog som sidegenerering.

---

#### Trinn 1 — todelt *(8J-1B levert; 8J-1A gjenstår som selvstendig arbeid)*

**Felles, bindende rammer for begge delmål:**

1. Behold `extracted_text` uendret. Alt nytt kommer **additivt ved siden av**, aldri som erstatning.
2. Behold `wholeDocumentElement()` som eksplisitt fallback for formater uten strukturell parser og for dokumenter som ikke lar seg parse.
3. Gjenbruk eksisterende parserkontrakt og eksisterende Wiki-proveniensmodell. **Ingen ny parallell kilde-ID-modell skal innføres** — `enterprise_wiki_source_references` har allerede `source_element_key`, `source_element_type`, `source_row_key`, `source_cell_key`, `source_column_key` og `excerpt`, og `EnterpriseWikiSourceReference` støtter allerede typene `paragraph`, `list_item`, `table_row`, `image`, `manual` (se §4.4). Ingen ny DOCX-parser.

---

##### 8J-1A — Persist structured parse result *(robusthet og ytelse)*

**Dette introduserer ikke structured provenance. Den finnes allerede.** 8J-1A er persistens/caching av eksisterende parser-output.

**Dagens situasjon:** source elements re-parses fra original-DOCX på disk hver gang `inspect()` og de relaterte akssessorene (`tablesForDocument()`, `imagesForDocument()`, `imageBySourceKey()`) kalles. Samme dokument kan dermed parses flere ganger innenfor én kjøring og på tvers av flyter.

**Planlagt forbedring:**

- Persister parser-resultatet additivt på `EnterpriseWikiDocument` eller en passende relatert struktur.
- Behold `extracted_text`.
- Behold fallback når persistert struktur mangler — **re-parsing forblir en gyldig vei**.
- Eksisterende dokumenter skal fortsatt fungere uten backfill; persistert struktur skal aldri bli en hard forutsetning for gamle dokumenter.

**Begrunnelse:** redusere gjentatt parsing, gjøre proveniens mindre avhengig av at originalfilen fortsatt ligger på disk, gi stabil input gjennom en hel kjøring, og bedre ytelse og robusthet.

**Merk:** 8J-1A er **ikke** årsaken til run 20-feilen, og løser den ikke. Den støtter samme grunnlag, men er en uavhengig forbedring.

Planen låser fortsatt ikke antall JSON-kolonner. Implementeringsfasen skal avgjøre om `text_elements` og `tables` bør persisteres separat (slik `saved_notice_ai_documents` allerede gjør med `structured_text_elements` + `structured_tables`) eller samlet uten tap av semantikk. **Prinsipp: følg eksisterende parserkontrakt, ikke et kunstig mål om færrest mulig kolonner.**

---

##### 8J-1B — Expose structured source catalog to maintainer decision — **LEVERT** (commit `e0dba82`)

**Dette var den reelle forutsetningen for alt videre ownership-arbeid.**

**Gapet som ble lukket:** `EnterpriseWikiMaintainerDecisionAiClient::decide()` mottar `sourceMeta`, flat `sourceText`, `indexContext`, `languageCode` og `figureCandidates`. Den får ikke den samme adresserbare prose-elementkatalogen som sidegenerering får. Beslutningen som fordeler `owned_topics` mellom sider har derfor ingen serverside-adresserbar proveniens å knytte temaene til — og kan ikke uttrykke, og validatoren kan ikke kontrollere, hvilken kildesubstans et tema faktisk bygger på.

**Planlagt endring:** maintainer decision skal motta en kompakt, adresserbar source catalog med minst:

- source element key
- type
- seksjonskontekst
- tekst

Konseptuelt:

```text
[paragraph-3] (2. <seksjon>) <tekst>
[listitem-0]  (2. <seksjon>) <tekst>
```

Eksakt serialisering låses **ikke** her — dersom eksisterende promptarkitektur har en bedre etablert kompakt form, brukes den.

**Krav:**

- Teksten skal ikke sendes dobbelt unødvendig — unngå både full `sourceText` og full duplikatkatalog når de inneholder samme innhold.
- Tokenbruk skal måles.
- Katalogen skal være språk- og domeneuavhengig.
- `figureCandidates` skal fortsatt håndteres korrekt.
- Tabeller skal vurderes innenfor samme source-element-kontrakt, ikke som en sidevei.

**Avgrensning (overholdt):** 8J-1B endret **ikke** `owned_topics`-schemaet og håndhever **ikke** ownership. Den ga maintaineren adresserbar kildeproveniens, slik at neste fase kan bygges på strukturert grunnlag i stedet for tekstheuristikk.

**Levert oppførsel.** Maintainer decision mottar nå `paragraph`, `list_item` og `table_row` med stabil `source_element_key`, seksjonskontekst og elementtekst. Bilder håndteres fortsatt separat gjennom `FIGURE CANDIDATES` — en egen, korrekt kontrakt med klassifisering og `required`-flagg som ikke skal presses inn i prosakatalogen. Når strukturert katalog finnes brukes `SOURCE ELEMENTS`, og flat `SOURCE TEXT` sendes **ikke** i tillegg. Uten strukturerte elementer (eldre dokumenter, PDF/XLSX, uparsbar fil) faller prompten tilbake på flat `SOURCE TEXT` byte-identisk med oppførselen før 8J-1B.

**Observasjon fra run 23 (første reelle kjøring med 8J-1B bevist aktiv):**

- Workerne ble restartet **etter** at koden lå på disk, og kjøringen startet 4 minutter senere — ingen prosess kunne ha gammel kode lastet.
- Deterministisk reproduksjon av prompten uten AI-kall bekreftet `SOURCE ELEMENTS` til stede, `SOURCE TEXT:` fraværende, **16 av 16** elementer i katalogen.
- 5 sider generert; alle kontrollerte sider `valid_first_pass`; ingen `planned_section_only_links`; `repairPlannedSections()` aldri kalt; ingen ownership-kollisjon av run 20-typen.

> **Dette er ett datapunkt.** Det beviser at 8J-1B fungerer teknisk og at kjøringen gikk gjennom — det beviser **ikke** deterministisk ownership-stabilitet, og det viser **ikke** at forbedringen skyldes source catalog alene. Run 23 hadde flere endrede variabler samtidig (ny katalog, nytt dokument-ID, endret wiki-indeks), og maintainer decision har dokumentert AI-varians. Effekten av katalogen på ownership-stabilitet er fortsatt **UKJENT**.

**Persistensform — bevisst ikke låst i planen.** Konseptuelt:

```text
enterprise_wiki_documents
  extracted_text            eksisterende, uendret
  structured_text_elements  ny/additiv
  structured_tables         ny/additiv dersom parserkontrakten tilsier separat persistens
```

**`EnterpriseWikiDocumentSourceElementService` — oppførsel (uendret prinsipp, gjelder både i dag og etter 8J-1A):**

```text
strukturerte elementer finnes  → eksponer paragraph, list_item, table_row, image og øvrige støttede typer   ← dagens normaltilstand for DOCX
strukturerte elementer mangler → whole-document fallback                                                     ← format-/parserstøtte og gamle data
```

**Bevis på at eksisterende parser har riktig grunnleggende granularitet.** Kjørt read-only mot testdokumentet fra run 20 leverte dagens parser, uendret, 5 headings + 13 `text_elements` + 1 tabell (3 rader) — herunder eget element for introduksjonsavsnittet under en heading, eget list item for registreringsinnhold, eget list item for gjennomgangsintervall, eget list item for kjente feil i kunnskapsbasen, egne avsnitt per påfølgende heading-seksjon, egne list items for måling/rapportering og eget avsnitt for forbedringsloggen. Hvert element bar `element_key`, `element_type` og `section_title`.

> Dette er dokumentert **kun som regresjonseksempel**. Verken dokumentnavn, seksjonstitler, elementnøkler eller domenebegreper fra dette dokumentet skal hardkodes i produksjonsarkitektur.

**Hva trinn 1 faktisk tilfører — presisert.** Strukturerte source elements ligger allerede til grunn for claim provenance, source references, verification, claim/source reconciliation, lint, page generation grounding og repair grounding. Disse gevinstene er **allerede tatt ut** og skal ikke krediteres 8J. Det trinn 1 tilfører er:

- **8J-1A:** færre gjentatte parseoperasjoner, stabil elementkatalog gjennom en hel kjøring, og proveniens som ikke lenger forutsetter at originalfilen fortsatt ligger uendret på disk.
- **8J-1B (levert):** adresserbar kildeproveniens i beslutningslaget — den ene komponenten i kjeden som manglet den, og forutsetningen for at trinn 2 kan bygges strukturelt.

Begge er verdifulle uavhengig av trinn 2. 8J-1B var den funksjonelle forutsetningen for generisk ownership, og er nå oppfylt.

**Downstream-komponenter som skal gjennomgås i implementeringsfasen.** Ikke anta at alle trenger kodeendring — de skal gjennomgås fordi de forbruker eller påvirkes av source-element-strukturen:

- `WikiSourceController` (opplastingsstien)
- `DocumentTextExtractor`
- `enterprise_wiki_documents` (persistens)
- `EnterpriseWikiDocumentSourceElementService`
- `EnterpriseWikiGenerateAppliedPagesService`
- `EnterpriseWikiExtractPageClaimsService`
- `EnterpriseWikiVerifyPageClaimsService`
- `EnterpriseWikiClaimSourceReconciliationService`
- `EnterpriseWikiSourceReference`
- relevante lint-regler
- tester for gammel whole-document fallback
- tester for structured source elements

**Tokenøkonomi — gjelder maintainer input (8J-1B), ikke sidegenerering.** Sidegenerering mangler ikke kompakte source elements; den har dem allerede. Problemet var at maintainer decision fikk flat `sourceText` **uten** adresserbare prose-nøkler. Valget falt på (a): katalogen **erstatter** flat `SOURCE TEXT` når strukturerte elementer finnes — katalogen er det samme dokumentinnholdet, bare adresserbart, så å sende begge ville sendt hele dokumentet to ganger.

**Faktisk måling fra den implementerte, optimaliserte 8J-1B-katalogen** (erstatter et tidligere anslag på ca. 1,11× som stammet fra et enklere prøveformat — uten preamble, uten typefelt, med færre elementtyper og uten table rows):

| Dokument | Gammel kildeblokk | Ny kildeblokk | Endring | Send-begge (forkastet) |
|---|---|---|---|---|
| Testdokument A | 2 076 tegn | 2 823 tegn | **+36,0 %** | +136 % |
| Testdokument B | 1 931 tegn | 2 725 tegn | **+41,1 %** | +141 % |

**Kildeinnholdet sendes fortsatt kun én gang** — summen av elementtekstene er ca. 4 % *mindre* enn flatteksten den erstatter, altså full dekning uten duplisering.

> **+36/+41 % er ikke en generell fast prosent.** Tallene er målt på **to små testdokumenter** (ca. 2 KB). Overheaden består av en **fast preamble på ca. 330 tegn** og ca. **22 tegn per element** for `[key] (type)`-prefikset. På et 2 KB-dokument utgjør den faste preamblen alene ~16 %; på et stort dokument er den under 1 %. Prosentandelen forventes derfor å falle betydelig med dokumentstørrelse, mot per-element-overheaden som eneste vesentlige bidrag. Faktisk payload skal fortsatt måles i implementerings- og regresjonstester — ikke antas fra disse to tallene.

Seksjonskontekst grupperes under `# `-linjer i stedet for å gjentas per element; den optimaliseringen alene tok overheaden ned fra ca. +48/+57 % til +36/+41 %.

Arkitekturprinsipp (uendret og bindende): når structured source elements brukes som AI-kontekst, sendes dokumentinnholdet normalt **én gang** i kompakt nøkkelform, f.eks. `[element-key] (type) text`. Samme innhold skal ikke dupliseres både som flat tekst og full strukturert katalog. Pretty JSON unngås — det målte ca. 2,7× flatteksten der den kompakte formen ligger nær den. Kodebasen har allerede lært denne leksen én gang — `WikiPageContentAiClient::repairUserPrompt()` sender bevisst ikke begge, etter at run 6 traff `max_output_tokens`.

**Formatstøtte — ærlig avgrensning.** Dagens rike strukturerte parser er **DOCX-spesifikk**. PDF (via `pdftotext`) og XLSX har ingen tilsvarende strukturell segmentering og bruker fallback. Fallback gjelder altså **format-/parserstøtte og gamle data — ikke fordi Wiki generelt mangler structured provenance**. Dette er ikke en arkitekturfeil. Den generelle modellen er:

> **Use the best structural provenance available for the source format; otherwise use an explicit whole-document fallback.**

Framtidige PDF/XLSX-parserforbedringer skal kunne plugges inn i samme source-element-kontrakt uten redesign av Wiki.

**Stabilitetsgaranti for source element keys — presist formulert.** Dagens nøkler (`paragraph-N`, `listitem-N`, `heading-N`) er **posisjonelle ordinaler** tildelt i dokumentrekkefølge per type.

| Hendelse | Konsekvens |
|---|---|
| Tekst rettes inne i et element | Nøkkel stabil |
| Element legges til på slutten | Eksisterende nøkler stabile |
| Element settes inn tidlig i dokumentet | Alle senere nøkler av samme type forskyves |
| Heading flyttes | Nøkkel stabil, men `section_title`-konteksten endres |
| Dokumentet lastes opp på nytt som ny versjon | Ny `EnterpriseWikiDocument`-rad → egne nøkler, ingen kryssversjons-identitet |

Garantien er altså: **stabil innenfor samme dokumentversjon så lenge tidligere elementrekkefølge ikke endres.** Det er tilstrekkelig for proveniens innen én konkret `EnterpriseWikiDocument`-versjon, for maintainer decision mot én konkret versjon, og for claim/source-linking innen samme versjon.

Planen påstår **ikke** at disse er permanente cross-version-identiteter. Sterkere cross-version-matching (content hash, `heading_path` + ordinal innen seksjon, eller annet) kan vurderes separat senere, men **skal ikke blokkere trinn 1**.

**Bakoverkompatibilitet (bindende krav):**

- Gamle Enterprise Wiki-dokumenter skal fortsatt fungere — uten persistert struktur skal **re-parsing forbli en gyldig vei**.
- Eksisterende `extracted_text` beholdes.
- `wholeDocumentElement()` forblir en gyldig fallback — for formater uten strukturell parser og for dokumenter som ikke lar seg parse.
- Eksisterende source references må fortsatt kunne leses.
- **Ingen backfill skal være nødvendig** for at applikasjonen starter og fungerer. Persistert struktur skal aldri bli en hard forutsetning.
- Alt nytt innføres additivt — ingen breaking change.

> **Presisering:** fallback-kravene over handler om formatstøtte og eldre data. De skal ikke leses som at Wiki mangler structured provenance for DOCX — den finnes og er i bruk (se «Faktisk arkitekturstatus» over).

**Teststrategi for trinn 1 — minimum.** Merk at punkt 1-4 og 10-13 dekker adferd som allerede fungerer; de skrives som **regresjonsvern** rundt en fungerende kjede, ikke som verifikasjon av ny funksjonalitet.

*Regresjonsvern (eksisterende adferd som ikke skal endres):*

1. DOCX paragraph elements eksponeres av `inspect()` med `element_key`/`element_type`
2. DOCX list-item elements likeså
3. heading/seksjonskontekst følger elementet
4. table row provenance
5. eksisterende claim extraction fortsatt fungerer
6. claim verification fungerer uendret
7. source references peker til reelle element keys
8. lint fungerer
9. eksisterende Wiki-suite fortsatt grønn

*8J-1A (persistens):*

10. parser-resultat persisteres additivt ved opplasting når formatet støtter det
11. `extracted_text` er uendret
12. dokument uten persistert struktur faller tilbake på re-parsing og gir identisk elementliste
13. format uten strukturell parser (PDF/XLSX) bruker `wholeDocumentElement()`
14. ingen backfill kreves for at eksisterende dokumenter fungerer

*8J-1B (maintainer catalog) — implementert, dekket av tester i `EnterpriseWikiMaintainerDecisionAiClientTest`:*

15. maintainer decision mottar en adresserbar katalog med key/type/seksjon/tekst
16. katalogen er språk- og domeneuavhengig (ingen ordlister, ingen hardkodede titler)
17. `figureCandidates` håndteres fortsatt korrekt
18. tabeller representeres innenfor samme source-element-kontrakt
19. **samme dokumenttekst sendes ikke både som full flat `sourceText` og som full katalog** — tokenbruk måles

---

#### Trinn 2 (8J-2) — Semantic ownership / source facts *(senere fase — skal ikke implementeres sammen med trinn 1)*

**Fler-element-proveniens finnes allerede og skal IKKE bygges som del av 8J-2** (se «Faktisk arkitekturstatus» over): en blokk registrerer allerede alle source elements den bygger på, hver med egen nøkkel, type og utdrag, i `content_blocks_json.source_elements`. Ingen databaseendring kreves for det.

Trinn 2 handler om et **annet** granularitetsproblem. Vi vet hvilke source elements en blokk bygger på, men vi vet ikke nødvendigvis:

- hvilket konkret **faktum** i elementet som støtter hvilket `owned_topic`
- hvilket **tekstspan/utdrag** innenfor elementet som representerer den eide substansen
- hvordan **én kilde med flere fakta** fordeles mellom flere canonical owners

Det er dette nivået 8J-2 skal undersøke — ikke om elementene er registrert.

**Følgende framtidige invariant er eksplisitt avvist:**

> ~~«One `source_element_key` may belong to only one `owned_topic`.»~~

Regelen er for grov og gir falske positive. Ett paragraph/list item kan legitimt inneholde flere uavhengige fakta som støtter forskjellige Wiki-temaer. Observerte moteksempeltyper i ekte data:

- prosessregel + rolle i samme setning
- frekvens + ansvar i samme setning
- årsak + midlertidig løsning + ansvarlig + dato i ett list item
- **ett enkelt avsnitt som legitimt utgjør grunnlaget for tre forskjellige `owned_topics` på samme side** — og hvor siden faktisk genererte feilfritt. En element-eksklusivitetsregel ville underkjent en side som beviselig fungerte.

Eierskap skal derfor modelleres som et **semantisk proveniensproblem**, ikke et fysisk elementproblem. Foreløpig kandidatmodell:

```text
source_element_key  +  verified excerpt / span / fact identity
```

Wiki har allerede relevant presedens å gjenbruke: `EnterpriseWikiSourceReference(source_element_key, excerpt)` er nøyaktig dette paret, og requirement-extraction-koden har allerede et mønster for å avstemme AI-returnert tekst mot serverautoritative source elements (`RequirementCandidateExtractor::reconcileCandidatesWithTextElements()`). Dette dokumenteres som sannsynlig gjenbruksretning.

**Eksplisitt ikke låst ennå:**

> **Do not change `owned_topics` from `string[]` to an object schema yet.**

Før en slik schemaendring godkjennes må vi vite:

- om `excerpt` alene er tilstrekkelig
- om offsets (`char_start`/`char_end`) bør brukes
- om claim-/fact-identitet kan gjenbrukes
- om en egen source-fact-modell er nødvendig

8J-1B er nå levert, så forutsetningen er oppfylt. Det som gjenstår før 8J-2 designes er **observasjonsgrunnlag**, ikke infrastruktur: flere gyldige testtilfeller som viser om katalogen faktisk stabiliserer ownership, og hvor godt beslutningslaget klarer å knytte `owned_topics` til adresserbare kildeenheter.

**Om måling av varians:** produktet tillater ikke flere ordinære importer av samme dokument, og planen skal **ikke** foreskrive omgåelse av den dokument-unikheten. Varians på identisk input må derfor måles enten via en eksisterende ikke-persisterende maintainer-dry-run, eller via separate legitime dokumentversjoner/testfixtures.

---

#### Avhengigheter og rekkefølge

```text
[allerede på plass]  structured source elements i sidegenerering
[allerede på plass]  FULL fler-element-proveniens per blokk (content_blocks_json.source_elements)
[LEVERT]  8J-1B   maintainer decision får samme adresserbare katalog   (commit e0dba82)
        │
        ↓  observer 8J-1B på flere gyldige testtilfeller
        ↓  vurder ownership-stabilitet vs. AI-varians
8J-2    semantic fact/span ownership — design på faktisk observert behov, deretter eventuelt schema
        ↓
Generisk ownership-invariant håndhevet før sidegenerering

8J-1A   persister/cache parser-output — selvstendig robusthets-/ytelsesarbeid, når som helst
```

**Ingen ny proveniens-subfase skal legges inn mellom 8J-1B og 8J-2.** En slik ble vurdert og forkastet: den bygget på den feilaktige antakelsen at fler-element-proveniens manglet.

8J-1A står utenfor den kritiske stien mot 8J-2 og kan gjøres uavhengig.

**Hva som eksplisitt IKKE skal gjøres som del av 8J:**

- `EnterpriseWikiPlannedSectionCoverageValidator` skal stå uendret.
- `planned_section_only_links` skal **ikke** svekkes.
- Page-generation-prompten skal ikke endres som symptomfix.
- `EnterpriseWikiMaintainerDecisionConsistencyValidator` skal **ikke** få en ownership-invariant før proveniensmodellen kan støtte den strukturelt.
- Ingen fuzzy ownership-matching, norsk ordmatching, domenespesifikke regler, title-substring-regler eller regex mot kjente labels.

**Forhold til maintainer decision.** Målbildet «one substantive knowledge unit should have one canonical owner» beholdes. Presiseringen er at dagens decision-schema ikke har tilstrekkelig proveniens til å håndheve det server-side uten skjøre tekstheuristikker. Validatoren skal først få en sterkere ownership-invariant når proveniensmodellen faktisk kan bære den.

**Eget prompt-gap (senere forbedringspunkt, ikke del av trinn 1).** Dagens `PAGE RESPONSIBILITY`-logikk sier presist at `owned_topics` er det den aktuelle siden skal forklare i dybden, og at listen skal være kort. Men prompten uttrykker **ikke** tydelig nok en *global* invariant over alle planlagte sider om at samme kildesubstans ikke skal eies flere steder — eksklusiviteten er formulert per side, aldri som en betingelse over unionen. Registreres som forbedringspunkt. **Prompt-presisering alene er ikke tilstrekkelig som garanti** — validatoren må på sikt kunne håndheve regelen strukturelt.

#### Observasjonsstatus etter run 23 (2026-08-09)

**BEVIST**

- 8J-1B var aktiv (worker-restart etter kodelevering + deterministisk prompt-reproduksjon uten AI-kall)
- Structured source catalog nådde maintainer decision, 16 av 16 elementer
- `SOURCE ELEMENTS` brukt, flat `SOURCE TEXT` ikke sendt i tillegg
- 5 sider generert; alle kontrollerte sider `valid_first_pass`
- Ingen `planned_section_only_links`, ingen repair, ingen ownership-kollisjon av run 20-typen
- Full fler-element-proveniens allerede persistert per blokk

**INFERERT**

- Source catalog *kan* ha bidratt til en mer granulær maintainer-plan (flere kandidater, mer presis ansvarsfordeling) — men flere variabler endret seg samtidig

**UKJENT**

- Om source catalog faktisk stabiliserer ownership over flere kjøringer
- Hvor stor del av planforskjellen som skyldes AI-varians framfor katalogen

#### Separate observasjoner (ikke del av 8J-2)

**Varians i best-practice-produksjon.** Én kjøring ga 5 beste-praksis-forslag; en senere kjøring på tilsvarende materiale ga **0 best_practice-blokker**. Verifisert ved parselaget (`[WIKI_PAGE_CONTENT_ORIGINS]`) at modellen returnerte null forslag på samtlige sider — ikke filtrert bort nedstrøms. Null best_practice-blokker gir null claims, som igjen gir null source references; det er korrekt kjedeoppførsel gitt inputen, ikke en feil. **Dette skal ikke leses som bedre kvalitet** — den faglige merverdien uteble. Registrert som en åpen stabilitets-/kvalitetsobservasjon, uavhengig av eierskapsarbeidet.

**Lint: mulig falskt positivt på artikkellenker.** `EnterpriseWikiAppliedRunLintService::checkArticleLinks()` sjekker kun typede `article_to_concept`/`article_to_entity`-relasjoner og mangler wikilink-fallbacken som `checkConceptLinks()` har og som entity-sjekken fikk i commit `bec838d`. Resultatet er at `article_without_concept_or_entity_links` kan rapporteres selv når artikkelen faktisk har inline wikilenker til concept-/entity-sider. Separat fra 8J, og **ikke** en forutsetning for 8J-2.

#### Generelle arkitekturkrav (bindende for hele 8J)

Retningen skal være kundeuavhengig, språkuavhengig og domeneuavhengig, og skal ikke være bundet til noe enkeltdokument, noe fagdomene eller run 20. Den skal være kompatibel med framtidige parsertyper. Dokumentene fra run 20 brukes utelukkende som regresjonscase.

8J er **ikke** en ny gate for produksjonsaktivering: trinn 1 forbedrer proveniens additivt uten å endre kjøreflyten, og trinn 2 er en senere designoppgave.

### Runtime-feil fra Enterprise Wiki run 18 — rettet (2026-07-12, commit `a34d172`)

**Bekreftet feil:**
- Article generation fullførte, men summary generation feilet med `EnterpriseWikiInvalidWikilinksException`: "invalid wikilink slug(s): Advania" — modellen skrev sidetittelen `[[Advania]]` i stedet for den kanoniske, ulikt casede sluggen `[[advania|Advania]]`. Den tillatte katalogen inneholdt 14 andre pages og var altså korrekt bygget — problemet var en nær-miss i modellens output, ikke katalogen.
- Fordi article/summary-fasen feilet, ble canonical wikilink-materialisering aldri kjørt for runen.
- Runen endte med `status=failed` og en konkret feilmelding, men `qa_status` ble aldri satt (QA hadde aldri startet). `EnterpriseWikiPostIngestQaService::findPendingRuns()`/`runForRun()` filtrerte kun på `qa_status` (`whereNull('qa_status')` eller eksplisitte QA-statuser) — aldri på run-flytens hoved-`status`. Dette gjorde at det planlagte `wiki:run-post-ingest-qa --all-pending`-kallet (hvert 15. minutt) plukket opp runen på nytt, kjørte QA sin egen `attemptRepair()` → `EnterpriseWikiGenerateAppliedPagesService::generate()` (som **ikke** validerer wikilinks) og genererte de manglende sidene selv — runen endte deretter som `escalated` i stedet for å forbli `failed` med sin opprinnelige, konkrete feil.
- Vedlikeholdsloggen skrev "Source changed — triggering QA retry" med `current_hash` og `prev_hash` som så identiske ut: `EnterpriseWikiMaintenanceCycleService::processRun()` kalte `$run->update([...'maintenance_source_hash' => $currentHash])` (som muterer modellinstansen) **før** loggkallet leste `$run->maintenance_source_hash` — loggen viste dermed alltid den nye hashen på begge steder.

**Rettelse — trygg wikilink-kanonisering (`EnterpriseWikiWikilinkCanonicalizer`, ny):**
- Kjøres i `EnterpriseWikiGenerateAppliedPagesService::generatePageForRun()` rett etter AI-kallet og før `validateWikilinks()`. Tre trygge, deterministiske matcher mot den eksakte katalogen som ble sendt til modellen: (1) eksakt canonical slug — uendret, (2) case-insensitiv eksakt slug-match dersom nøyaktig én katalogside matcher, (3) eksakt sidetittel, case-insensitivt, dersom nøyaktig én katalogside matcher. `[[Advania]]` → `[[advania|Advania]]` når katalogen har `slug=advania, title=Advania`.
- Ingen fuzzy/partial matching, ingen automatisk sideopprettelse. Tvetydig match (flere katalogsider matcher), ukjent target, self-link og cross-customer target forblir uendret av canonicalizeren og avvises fortsatt av eksisterende `EnterpriseWikiLinkResolver`/`validateWikilinks()` nøyaktig som før.
- Anchor-tekst bevares alltid; fenced/inline code og vanlige Markdown-lenker røres aldri (gjenbruker samme kode-splitting-mønster som `EnterpriseWikiWikilinkRenderer`).
- `WikiPageContentAiClient`s katalog-prompt viser nå eksakte, kopierbare `[[slug|Title]]`-eksempler per target, og developer-prompten instruerer modellen eksplisitt om å kopiere slug-delen bokstavelig i stedet for å skrive den fra hukommelsen.
- Modell, tokenbudsjett, reasoning, Responses-decoder og køer er uendret.

**Rettelse — maintenance-gating (`EnterpriseWikiPostIngestQaService::scopeToRunsReadyForQa()`, ny):**
- Ny regel: en run med hoved-`status=failed` og `qa_status` fortsatt `null` ekskluderes fra `findPendingRuns()`, `findRetryableRuns()` og selve den atomiske claim-spørringen i `runForRun()` — uavhengig av `--retry`. Det er nøyaktig denne kombinasjonen som betyr at feilen skjedde tidligere i den ordinære dokumentflyten (maintainer decision, apply, page generation, wikilink validation, materialisering) og at QA aldri fikk sjansen til å starte.
- Enhver run hvor `qa_status` allerede er satt (QA har startet/kjørt) forblir kvalifisert uavhengig av hoved-`status` — dette lar `decision_only`-runer (hvis hoved-`status` aldri forlater `decision_only`, siden den tilstandsmaskinen kun finnes i `EnterpriseWikiDocumentFlowService`) fortsette å fungere akkurat som før.
- `EnterpriseWikiDeepRepairService::attempt()` og `EnterpriseWikiMaintenanceCycleService::findEscalatedWithDocumentSource()` er **ikke** endret med en egen `status`-sjekk — de er allerede korrekt beskyttet transitivt: en run som rammes av regelen over når aldri `qa_status=escalated`, så deep repair blir aldri kalt for den.
- Hash-bugen er rettet ved å fange `maintenance_source_hash` i en lokal variabel *før* `$run->update()` muterer modellen, og ved å bruke en egen loggmelding ("First maintenance check for this run") når det ikke finnes noen reell forrige hash å sammenligne med.

**Observability:** `GenerateEnterpriseWikiAppliedPage::markPivotFailed()` lagrer nå `generation_error` med opprinnelig exception-type prefikset (`[EnterpriseWikiInvalidWikilinksException] ...`), og `FinalizeEnterpriseWikiPageGeneration::markRunFailed()` bygger `run.error_message` fra fase + per-side tittel/page_type/årsak — feilen er nå forståelig direkte fra `run.error_message` uten stacktrace eller kø-logg.

**Tester:** 15 nye tester i `EnterpriseWikiWikilinkCanonicalizerTest` (unit), 5 nye i `EnterpriseWikiGeneratePageWikilinkValidationTest`, 2 nye i `WikiPageContentAiClientTest`, 3 nye i `EnterpriseWikiMaintenanceCycleTest` (hash-logg-nøyaktighet), 8 nye i `EnterpriseWikiPostIngestQaGatingTest` (gating + observability + legacy-vakt). Full suite: 1062 passed, 2840 assertions, 0 failed.

### Runtime-feil: inline wikilinks ikke synlig klikkbare i UI — rettet (2026-07-12, commit `2a3ad16`)

**Presisering av tidligere status:** Statuslinjen øverst i dette dokumentet påsto at "inline wikilinks er nå klikkbare i UI" etter commit `ab35d52` (8I-3/8I-4). Dette var korrekt for backend-transformasjonen, men var ikke reelt runtime-verifisert i nettleser — en reell produksjonsside («Risikostyring i prosjekter») viste at ordene i brødteksten (PRINCE2-prinsipper, styringsgruppe, Advania m.fl.) så ut som vanlig tekst.

**Rotårsak (bekreftet direkte mot database og renderer, ikke antatt):** `content_markdown` inneholdt allerede korrekte `[[slug|anchor]]`-wikilinks, og `EnterpriseWikiWikilinkRenderer` transformerte dem korrekt til standard Markdown-lenker (`[Advania](/app/wiki/advania)`) — backend-kjeden (controller → `rendered_markdown` → Inertia-prop) var allerede riktig koblet. Feilen lå i frontend: `Show.jsx` sendte ingen `components`-prop til `ReactMarkdown`, så lenkene ble rendret som vanlige `<a href>`-tagger uten noen `& a`-stil i `.wiki-article`-CSS-blokken — Tailwinds base-reset fjerner default lenkefarge/understrek, så lenkene var teknisk klikkbare, men visuelt umulige å skille fra brødtekst. I tillegg utløste en ren `<a href>` full side-reload i stedet for Inertia-navigasjon.

**Rettelse:** Ny `WikiArticleLink`-komponent i `Show.jsx`, koblet via `<ReactMarkdown components={{ a: WikiArticleLink }}>`. Interne `/app/wiki/{slug}`-lenker rendres nå med eksisterende Inertia `Link` (samme mønster som «Lenket fra»/«Navigasjon» allerede brukte) og synlig, men rolig stil (fiolett lenkefarge, diskret understrek, tydelig hover/focus-visible) — gjenbruker eksisterende Tailwind-tokens, ingen nytt bibliotek. Eksterne lenker rendres uendret som vanlig `<a>`. Ingen backend-endring var nødvendig — renderer, controller og `rendered_markdown`-kontrakten var allerede korrekte og gjelder uendret for alle sidetyper og alle genereringsveier (vanlig generering, incremental relink, semantic repair), siden renderingen kun leser current `content_markdown` uavhengig av opprinnelse.

**Tester:** 7 nye tester i `WikiControllerTest` (uendret `content_markdown`, `rendered_markdown` inneholder intern lenke, ankertekst synlig, deterministisk visningstekst for bar slug, samme renderer for alle fire sidetyper, incremental relink-version og semantic repair-version rendres begge med inline-lenker). Full suite: 1069 passed, 2859 assertions, 0 failed.

### Runtime-feil fra Enterprise Wiki run 23: maintainer apply ikke idempotent — rettet (2026-07-12, commit `223c085`)

**Bekreftet feil:** Nye runs feilet i `EnterpriseWikiMaintainerDecisionApplyService::apply()` med `SQLSTATE[23505] duplicate key value violates unique constraint enterprise_wiki_pages_customer_id_slug_unique`, fordi en tidligere run hadde rukket å opprette page-skeletons før den feilet i et senere steg — en ny run for samme dokument kunne ikke gjenbruke disse sidene.

**Rotårsak — hvorfor run 23 sa `update` men gjorde INSERT:** `EnterpriseWikiMaintainerDecisionPrompt`s JSON-skjema har to ulike entry-typer: `sourcePageSchema` (brukt for `source_article`/`source_summary`) har **ingen `page_id`-felt i det hele tatt**, mens `sharedPageSchema` (concept/entity) krever `page_id` for `update`. Apply-tjenestens gamle betingelse `if ($action === 'update' && $pageId !== null)` kunne derfor ALDRI være sann for `source_article`/`source_summary` — `page_id` er strukturelt alltid `null` for disse, uansett hva `action` sier. Enhver `action=update`-beslutning for kildeartikkel/sammendrag falt dermed alltid gjennom til `else`-grenen og forsøkte en blind `EnterpriseWikiPage::create()`.

**Lookup-/gjenbruksregler:** For hvert decision-element slås det nå opp i denne rekkefølgen: (1) eksplisitt `page_id`-hint (kun mulig for concept/entity `update`) — validert til å tilhøre kunden; (2) kanonisk oppslag på `(customer_id, slug)` — vinner alltid over decisionens egen create/update-antakelse, siden `source_article`/`source_summary` aldri kan bære en `page_id`; (3) opprettelse, kun når ingen av de to første finner noe. Ved gjenbruk oppdateres kun `page_type` og `last_source_hash` (aldri tittel, slug, status, current page version eller historikk).

**Samtidighet:** Slug-oppslaget bruker `lockForUpdate()` når raden finnes. Opprettelsen skjer i en nestet `DB::transaction()` (Laravel-savepoint) slik at en reell `UniqueConstraintViolationException` — to runs som forsøker samme nye slug samtidig — kan fanges uten å forgifte den ytre transaksjonen; ved fangst hentes den eksisterende raden på nytt og behandles deterministisk som `updated`. Ingen tilfeldig ny slug, ingen nytt hash-suffiks. Unique constraint på `(customer_id, slug)` er urørt og forblir siste sikkerhetsnett.

**Pivot-regler:** `enterprise_wiki_ingest_run_pages` skrives via `firstOrCreate` på `(run_id, page_id)` — samme run kan ikke få en duplikat pivot-rad selv om to decision-elementer skulle peke på samme side. `action=updated` når siden ble gjenbrukt, `action=created` kun når denne konkrete apply-operasjonen faktisk opprettet raden.

**Observability:** Ny strukturert logg `[WIKI_DOCUMENT_FLOW] Existing page reused during maintainer apply.` med `run_id`, `page_id`, `customer_id`, `slug`, `page_type`, `requested_action`, `applied_action=updated` — ingen dokumentinnhold logges.

**Tester:** 18 nye tester i `EnterpriseWikiMaintainerDecisionApplyServiceTest` (create/update-reuse via slug, gjenbruk fra tidligere delvis fullført run, current version og page id bevart, page count øker ikke ved retry, pivot-regler, customer-scoping, unique constraint fortsatt håndhevet, race-lignende scenario). 3 nye i `EnterpriseWikiMaintainerApplyIdempotencyIntegrationTest` (full stagede dokumentflyt reproduserer run 23-scenariet ende-til-ende uten unntak, staged page generation dispatcher normalt etter gjenbruk, legacy `ProcessEnterpriseWikiIngest` urørt). Full suite: 1090 passed, 2908 assertions, 0 failed.

### Runtime-feil fra Enterprise Wiki run 24: race condition mellom continuation og planlagt post-ingest QA — rettet (2026-07-12, commit `8b169fb`)

**Bekreftet feil:** `ContinueEnterpriseWikiDocumentFlowAfterPages` kjørte materialize → incremental relink → claims → verify → lint → semantic repair for run 24 mens runen fortsatt hadde `status=verification_linking`. Den planlagte `wiki:run-post-ingest-qa --all-pending` (hvert 15. minutt) klarte å claime samme run i dette vinduet — siden `EnterpriseWikiPostIngestQaService::scopeToRunsReadyForQa()` (fra forrige rettelse) kun ekskluderte `status=failed && qa_status=null`, ikke aktive flyt-statuser — satte `qa_status=passed`. Da continuation-jobben selv nådde `performPostIngestQa()`, feilet dens egen atomiske claim (siden `qa_status` ikke lenger var `null`/`pending`/`repair_required`), og `runForRun()` returnerte `null`. Den gamle koden tolket `null` ubetinget som en fatal feil og kastet `InvalidArgumentException: Post-ingest QA did not claim run [24]`. Runen ble stående med `status=verification_linking`, `qa_status=passed`, `finished_at=null`, `error_message=null`.

**Scheduler-gating (før/etter):** Ny `EnterpriseWikiPostIngestQaService::ACTIVE_DOCUMENT_FLOW_STATUSES` (`queued`, `running`, `sections_planned`, `maintainer_decision`, `applying`, `generating_pages`, `generating_concept_entity_pages`, `verification_linking` — alle `NON_TERMINAL_STATUSES` unntatt `qa` og `decision_only`) ekskluderes nå fra `findPendingRuns()`/`findRetryableRuns()` (ikke fra `runForRun()`s egen claim-spørring, som fortsatt må kunne claimes av continuation selv når den nettopp har satt `status=qa`). `decision_only`-runer og maintenance/regression-retry er upåvirket, siden de aldri har `status` i denne mengden.

**Idempotent continuation-QA:** `performPostIngestQa()` returnerer nå `bool` i stedet for å kaste ved manglende claim. Når `runForRun()` returnerer `null`, klassifiseres runen deterministisk: `qa_status` allerede `passed`/`escalated`/`failed` → behandlet som fullført (ingen nytt forsøk, ingen duplikat snapshot, fortsetter til `finalizeFromQaResult()`); `qa_status` fortsatt `null`/`pending`/`running`/`repair_required` → ekte busy-tilstand, en ny `ContinueEnterpriseWikiDocumentFlowAfterPages`-jobb dispatches med 30 sekunders forsinkelse, og gjeldende kall returnerer uten å markere runen fullført eller feilet.

**Failure-håndtering:** `ContinueEnterpriseWikiDocumentFlowAfterPages::failed()` er herdet med strukturert logg `[WIKI_DOCUMENT_FLOW] Continuation job failed.` (run_id, status, qa_status, phase, exception, error) og overskriver aldri en allerede-terminal run. `EnterpriseWikiDocumentFlowService::markRunFailed()` inkluderer nå `phase` og `exception`-type i sin logg.

**Tester:** 27 nye tester i `EnterpriseWikiPostIngestQaRaceConditionTest`, inkludert en run-24-lik integrasjonstest (`verification_linking` + `qa_status=passed` + `qa_attempt_count=1` + `finished_at=null` → ny continuation-kall fullfører runen uten duplikate page versions, page links, claims eller QA-snapshots). Full suite: 1117 passed, 3046 assertions, 0 failed.

### Presisering: failure-semantikken over var ufullstendig — den virkelige run 24-tilstanden avdekket at `markRunFailed()` overskrev passed QA (rettet 2026-07-12, commit `06797b7`)

**Den virkelige databasetilstanden etter run 24s opprinnelige queue-feil** var `status=failed`, **`qa_status=failed`** (ikke `passed`, som forrige avsnitt antok), `finished_at` satt, `error_message="Post-ingest QA did not claim run [24]."`. Loggen viste at semantisk QA faktisk hadde fullført med `qa_status=passed` og et snapshot (id 7) FØR queue-feilen. Rotårsaken var ikke i `ContinueEnterpriseWikiDocumentFlowAfterPages::failed()` (som aldri rørte `qa_status`), men i `EnterpriseWikiDocumentFlowService::markRunFailed()`: `continueAfterPagesGenerated()`s catch-blokk kalte `markRunFailed($run, $e, qaContext: $currentStage === STATUS_QA)` — siden unntaket skjedde mens `$currentStage === STATUS_QA`, ble `qaContext=true`, og `markRunFailed()` satte da **ubetinget** `qa_status=failed`, uavhengig av at `qa_status` allerede var `passed` fra en reell, fullført QA-kjøring.

**Endring i failure-semantikken:** `markRunFailed()` skiller nå eksplisitt mellom to atskilte tilstander — run execution status (`status`) og semantisk QA-resultat (`qa_status`/`qa_result`/snapshot). Før `qa_status` overskrives til `failed` (kun når `qaContext=true`), sjekkes om `qa_status` allerede er et terminalt, legitimt resultat (`passed`, `escalated` eller `failed`) — i så fall røres `qa_status`, `qa_completed_at`, `qa_last_error` og det tilhørende snapshotet ikke i det hele tatt. `status` settes fortsatt til `failed` med `finished_at` og `error_message` som før, uansett.

**Ny regresjonstest** reproduserer nøyaktig run 24s reelle tilstand: `qa_status=passed` + snapshot finnes → et uventet unntak skjer mens `$currentStage === STATUS_QA` → `status=failed`, men `qa_status` forblir `passed`, `qa_attempt_count` og snapshotet er uendret.

**Ny kontrollert recovery-kommando** `wiki:recover-document-flow --run-id=<id>` (`app/Console/Commands/EnterpriseWikiRecoverDocumentFlow.php`) — en smal, ett-formåls-kommando (ikke en generell «sett status»-kommando) som bruker QA-snapshotet som sannhetsgrunnlag. Nekter recovery med mindre ALLE av disse stemmer: run finnes og er ikke allerede `completed`; `status=failed`; `error_message` matcher det kjente race-mønsteret (`Post-ingest QA did not claim run [id].`); et snapshot for run + gjeldende `qa_attempt_count` finnes med `qa_status=passed`; snapshotets `customer_id` matcher runens; run har applied pages, minst én current page version, minst én canonical page link og minst én claim. Ved bestått gjenoppretter den `status=verification_linking`, `qa_status=passed`, `qa_attempt_count` fra snapshotet, `finished_at=null`, `error_message=null`, og dispatcher en FERSK `ContinueEnterpriseWikiDocumentFlowAfterPages`-jobb (aldri den gamle failed-job-payloaden). Støtter `--dry-run` for å bekrefte at alle guards består uten å endre noe.

**Tester:** 15 nye tester i `EnterpriseWikiRecoverDocumentFlowCommandTest` (hver guard isolert, success-sti, `--dry-run`, ingen duplikater, legacy-vakt). 2 nye i `EnterpriseWikiPostIngestQaRaceConditionTest`: én reproduserer nøyaktig run 24s reelle failure→preservation-sekvens, én dekker full rekkefølge fra `verification_linking` gjennom feil, recovery og fersk continuation til `completed` uten duplikater, samt én for allerede korruptert gammel tilstand som gjenoppretter fra snapshotet. Full suite: 1135 passed, 3124 assertions, 0 failed.

### Steg-nivå idempotens og generell resume/recovery (rettet 2026-07-12)

**Bakgrunn (kun regresjonstest-kontekst — run 24s data er slettet, ikke en fortsatt recoverbar run):** de to foregående rettelsene fikset ett spesifikt hendelsesforløp (run 24s race + `qa_status`-overskriving). Denne rettelsen generaliserer løsningen: enhver `continueAfterPagesGenerated()`-kjøring — duplicate job, queue retry, worker restart, delvis claim-ekstraksjon, delvis verifikasjon, reell QA-feil eller -eskalering — skal kunne gjenopptas uten å gjenta allerede fullførte steg, og `wiki:recover-document-flow` skal velge riktig neste steg i stedet for alltid å restarte hele pipelinen fra `verification_linking`.

**Rotårsak:** to av seks continuation-steg manglet et pålitelig fullføringsbevis. `EnterpriseWikiExtractPageClaimsService` og `EnterpriseWikiVerifyPageClaimsService` brukte radeksistens (`claims finnes for versjon` / `source reference finnes for claim`) som bevis på at steget var ferdig. Dette bryter sammen i to tilfeller som ikke kan skilles fra "ikke startet" ut fra radantall alene: (1) en side kan lovlig gi null claims, og en påstand kan lovlig verifiseres som ikke-støttet uten noen gang å få en source reference — begge tilfeller ser identiske ut til "ikke behandlet ennå"; (2) en prosess kan krasje midt i skrivingen av N claims for én side, og etterlate et delvis sett som feilaktig tolkes som "ferdig" siden *noen* rader finnes. De fire andre stegene (materialize wikilinks, incremental relink, lint, link semantic repair) hadde allerede enten full deterministisk re-derivering eller et eksplisitt reservasjons-checkpoint (`EnterpriseWikiPageRelinkAttempt`/`EnterpriseWikiPageLinkQaAttempt`) og krevde ingen endring.

**Nye eksplisitte checkpoints (minimale, ikke en generell workflow-motor):**
- `enterprise_wiki_ingest_run_pages.claims_extracted_at` (nullable timestamp) — settes én gang per (run, side) i samme databasetransaksjon som selve claim-radene, uansett om AI returnerte 0 eller N claims. Erstatter radeksistens som fullføringsbevis for ekstraksjon.
- `enterprise_wiki_claims.verified_at` (nullable timestamp) — settes én gang per claim i samme transaksjon som en eventuell source reference, uansett om claimet ble funnet støttet eller ikke. Erstatter "har source reference" som fullføringsbevis for verifikasjon, og stopper uendelig re-verifisering av ikke-støttede claims ved hver continuation-kjøring.

Begge tjenestene beholder et defensivt fallback-sjekk (finnes claims/reference allerede uten at checkpointet er satt — f.eks. fra data skrevet før denne kolonnen fantes) som setter checkpointet i stedet for å kalle AI på nytt.

**Continuation-jobbens robusthet** (`EnterpriseWikiDocumentFlowService::continueAfterPagesGenerated()`): et kort `lockForUpdate()`-vindu ved oppstart bekrefter atomisk at runen ikke allerede er terminal, uten å holde låsen over noen AI-kall. Hvert steg re-kjøres alltid uforandret fra toppen — det trengs ingen egen "hvilket steg skal jeg hoppe til"-beregning i continuation selv, fordi hvert steg nå beviselig er trygt å kjøre på nytt mot arbeid det allerede har fullført (se stegmatrisen i sluttrapporten/commit-meldingen). En ny defensiv sjekk rett før finalisering hopper over finalize dersom runen allerede ble gjort terminal av et konkurrerende kall. `finalizeFromQaResult()` er gjort `public` og omdøpt til `finalizeFromExistingQaResult()` slik at recovery-kommandoen kan kalle den direkte.

**`wiki:recover-document-flow` er redesignet fra ett-hendelses-recovery til en generell resume-plan:** kommandoen observerer runens checkpoints og artifacts (sider, current versions, links, claims, QA-snapshot for gjeldende `qa_attempt_count`) og velger deterministisk ett av to utfall:
- **`direct_finalize`** — `qa_status` er allerede terminal (`passed`/`escalated`/`failed`) OG et matchende snapshot finnes (samme `qa_status`, samme `customer_id`) OG — kun for `passed` — de artifacts en fullført pipeline krever (current version, links, claims) finnes. Kaller `finalizeFromExistingQaResult()` direkte: ingen steg kjøres på nytt, ingen AI-kall, ingen jobb dispatches.
- **`resume_continuation`** — `qa_status` nådde aldri en terminal tilstand (feilen skjedde under eller før QA). Runen tilbakestilles til `verification_linking` (uten å røre `qa_status`/`qa_attempt_count`/eksisterende artifacts), og en FERSK `ContinueEnterpriseWikiDocumentFlowAfterPages`-jobb dispatches. Trygt fordi hvert steg i continuation nå er selvstendig idempotent. Dersom `qa_status=running` mens runen står `failed` (beviset på at arbeideren som eide QA-claimet døde uten å nå en terminal tilstand), frigjøres claimet til `pending` før dispatch — ellers ville en gjenopptatt continuation løpe uendelig i QA-busy-retry-sløyfen, siden `running` aldri kan reclaimes av den ordinære claim-spørringen.

Kommandoen nekter fortsatt eksplisitt (ingen generell «sett status»-kommando) når: run ikke finnes; run er allerede `completed`/`escalated`; `status` ikke er `failed`; `maintainer_decision_status` ikke er `applied`; runen har ingen applied sider; `qa_status` er terminal men snapshotet mangler eller ikke matcher; eller (kun for `passed`) påkrevde artifacts mangler. Den brukte tidligere en regex-match på det spesifikke run-24-feilteksten som hard gate — denne er fjernet, siden kommandoen nå må håndtere enhver teknisk feil, ikke kun det ene historiske mønsteret. `--dry-run` kjører hele observasjons- og planleggings-logikken (ingen AI-kall involvert i selve beslutningen) og skriver ut checkpoints, artifacts, valgt neste steg og om direkte finalize er tillatt, uten å endre database eller dispatche noen jobb.

**Tester:** 4 nye i `EnterpriseWikiClaimStepResumabilityTest` (delvis ekstraksjon på tvers av sider uten duplikater, checkpoint satt selv ved 0 claims, delvis verifikasjon på tvers av claims uten duplikate references, checkpoint satt for ikke-støttede claims uten reference — begge tester bekrefter AI ikke kalles på nytt for allerede fullførte deler). `EnterpriseWikiRecoverDocumentFlowCommandTest` er skrevet om fullstendig til den nye kontrakten (24 tester: guards, direct-finalize for passed/escalated/failed, resume-continuation inkl. stale `qa_status=running`-frigjøring, dry-run for begge grener, legacy-vakt). `EnterpriseWikiPostIngestQaRaceConditionTest` oppdatert: de to testene som forventet at recovery alltid dispatcher en jobb er endret til å forvente direkte finalisering (ingen dispatch) når QA allerede er terminal, pluss én ny test for resume-via-continuation når QA aldri startet. Full Enterprise Wiki-suite: 1148 passed, 3178 assertions, 0 failed.

### Reservasjon/lease for claim-ekstraksjon og -verifikasjon under reell samtidighet (rettet 2026-07-12)

**Bakgrunn:** forrige rettelse (steg-nivå idempotens) lukket sekvensiell gjentakelse korrekt, men rapporterte en kjent begrensning: to *sanntids-samtidige* continuation-jobber kunne i teorien begge se `claims_extracted_at`/`verified_at` som `null` på samme tid, begge kalle AI for samme side/claim, og begge forsøke å skrive — siden det ikke fantes noen reservasjon *før* AI-kallet, bare et checkpoint *etter*. Dette bryter det eksplisitte kravet om at to samtidige continuation-jobs skal være trygge.

**Reservasjon/lease-design:** hvert av de to stegene får et eget par nye, nullable felt — samme mønster som allerede brukes for QA (`qa_started_at`/`qa_completed_at`):
- `enterprise_wiki_ingest_run_pages.claims_claimed_at` + `claims_claim_token` (per run-side)
- `enterprise_wiki_claims.verification_claimed_at` + `verification_claim_token` (per claim)

**Atomisk reservasjon (før AI-kallet):** en enkelt betinget SQL `UPDATE` fungerer som compare-and-swap, uten å åpne en transaksjon eller holde en radlås:

```sql
UPDATE enterprise_wiki_ingest_run_pages
SET claims_claimed_at = now(), claims_claim_token = :new_token
WHERE id = :row_id
  AND claims_extracted_at IS NULL
  AND (claims_claimed_at IS NULL OR claims_claimed_at < :stale_threshold)
```

Under PostgreSQL READ COMMITTED serialiseres to samtidige `UPDATE`-kall mot samme rad via radlåsen; når den første committer, re-evaluerer den andre sin WHERE-klausul mot den nå oppdaterte raden (EvalPlanQual) — siden `claims_claimed_at` nettopp ble satt til `now()` av den første, matcher ikke lenger den andres betingelse, og `0` rader oppdateres for den. Kun den som faktisk oppdaterte raden fortsetter til AI-kallet; den andre gjenkjenner utfallet (`completed` hvis `claims_extracted_at` nå er satt, ellers `busy`) og gjør ikke noe AI-kall. Samme mønster for `enterprise_wiki_claims.verification_claimed_at`/`verification_claim_token`.

**Persistering med token-validering (etter AI-kallet):** claims/reference og fullførings-checkpointet skrives i en kort transaksjon som re-validerer, under radlås, at reservasjonstokenet fortsatt eies av denne workeren (`WHERE id = ... AND claims_claim_token = :token`). Finner den ingen rad (tokenet er overskrevet av en annen worker som har overtatt en utløpt lease), skrives ingenting og resultatet rapporteres som `busy` — akkurat som om reservasjonen aldri hadde blitt tatt. En AI-feil mellom reservasjon og persistering frigjør leasen eksplisitt (samme token-sjekk) i stedet for å vente på timeout.

**Stale-lease-regel (rettet 2026-07-12 — 600 sekunder var utrygt):** lease-varigheten ble først satt til 600 sekunder. Det er FEIL: continuation-jobben (`ContinueEnterpriseWikiDocumentFlowAfterPages::TIMEOUT_SECONDS = 1800`) har en egen timeout på 1800 sekunder, og reservasjonen tas midt inne i denne jobbens ENESTE kjøring — en fortsatt levende worker kan derfor legitimt fortsatt være midt i AI-kallet ved ethvert tidspunkt helt opp til 1800 sekunder etter at reservasjonen ble tatt. En lease på 600 sekunder lot en annen worker overta og starte et NYTT AI-kall for samme side/claim mens den første fortsatt var innenfor sin tillatte kjøretid — nøyaktig det denne reservasjonsmekanismen finnes for å hindre. Tokenkontrollen ved lagring beskyttet databasen mot at den gamle workerens SKRIVING vant, men hindret ikke selve det ekstra, dupliserte AI-kallet.

**Invariant:** `LEASE_SECONDS > ContinueEnterpriseWikiDocumentFlowAfterPages::TIMEOUT_SECONDS`. Lease-konstanten er nå avledet direkte fra jobbens timeout-konstant pluss en eksplisitt sikkerhetsmargin, ikke et separat, hardkodet tall som kan drifte fra timeouten over tid:

```php
// ContinueEnterpriseWikiDocumentFlowAfterPages
public const TIMEOUT_SECONDS = 1800;

// EnterpriseWikiExtractPageClaimsService / EnterpriseWikiVerifyPageClaimsService
private const TIMEOUT_SAFETY_MARGIN_SECONDS = 300;
private const LEASE_SECONDS = ContinueEnterpriseWikiDocumentFlowAfterPages::TIMEOUT_SECONDS + self::TIMEOUT_SAFETY_MARGIN_SECONDS; // = 2100
```

Marginen (300s) dekker kø-skedulering, klokkeskjevhet mellom workere, og Laravels egen respittid før en timet-ut jobb faktisk drepes — den er IKKE kilden til hvor lenge et legitimt AI-kall får lov å vare (det er jobbens `TIMEOUT_SECONDS` alene som avgjør det). Lease er dermed 2100 sekunder for begge steg — lenger enn jobbens fulle 1800-sekunders kjøretid, så en fortsatt levende worker kan aldri miste reservasjonen sin før jobben selv ville blitt terminert. Samtidig er 2100 sekunder fortsatt kort nok til at en reelt død worker (drept prosess, ingen sjanse til å frigjøre) ikke blokkerer siden/claimet uendelig — reservasjonen frigjøres senest når den neste continuation-jobben (dispatchet av selve busy-mekanismen, se under) observerer at leasen er utløpt.

Invarianten håndheves av `EnterpriseWikiClaimStepLeaseTest::test_lease_duration_exceeds_continuation_job_timeout_by_a_safety_margin()`, som leser de faktiske klassekonstantene via reflection (ikke hardkodede tall) og feiler dersom noen i fremtiden reduserer marginen til under 300 sekunder eller på annen måte lar lease-varigheten falle under jobbens timeout.

En ny worker overtar en lease bare når `claims_claimed_at`/`verification_claimed_at` er eldre enn denne (nå trygge) grensen — reservasjonsspørringen er den ENESTE plassen dette sjekkes, så en gammel worker som senere prøver å lagre blir alltid avvist av token-sjekken uansett hvor lenge det er siden. Et fullført checkpoint (`claims_extracted_at`/`verified_at` satt) kan aldri reserveres eller overtas, uansett hva reservasjonsfeltene inneholder — reservasjonsspørringens `WHERE ... IS NULL`-betingelse på selve checkpointet er ubetinget og sjekkes uavhengig av lease-alder.

**Continuation ved aktiv reservasjon:** `performExtractPageClaims()`/`performVerifyPageClaims()` returnerer nå `bool` (samme mønster som `performPostIngestQa()`). Finner ekstraksjons- eller verifikasjonstjenesten minst én rad/claim som er aktivt (ikke-utløpt) reservert av en annen worker, rapporteres `busy > 0` i resultatet — continuation dispatcher da en forsinket ny `ContinueEnterpriseWikiDocumentFlowAfterPages`-jobb (samme `STEP_BUSY_RETRY_DELAY_SECONDS = 30` som brukes for QA-busy) og returnerer umiddelbart, uten å gå videre til senere steg, uten å markere runen `failed`, og uten noe AI-kall. Alt arbeid denne workeren FAKTISK kunne reservere blir likevel gjort — bare de aktivt reserverte radene/claimsene venter til neste forsøk.

**Tester:** `EnterpriseWikiClaimStepLeaseTest` utvidet fra 8 til 11 tester — de opprinnelige 8 (aktiv reservasjon blokkerer en annen worker for ekstraksjon og verifikasjon, utløpt lease overtas og gammelt token avvises ved lagring for begge steg via `persist()`-reflection, et fullført checkpoint kan aldri reserveres selv med gjenværende reservasjonsfelt for begge steg via `reserve()`-reflection, to fulle continuation-kjøringer uten duplikater, continuation som møter en aktiv reservasjon utsetter seg selv) pluss 3 nye: en reservasjon eldre enn det tidligere (utrygge) 600s-nivået men fortsatt innenfor den nye leasen skal FORTSATT blokkere en annen worker og IKKE kalle AI (ekstraksjon og verifikasjon separat), og selve timeout-invarianten (`LEASE_SECONDS > TIMEOUT_SECONDS` + konkret 300s-margin, lest via reflection). De to stale-lease-testene (3/4) er oppdatert til å bruke en alder avledet fra den faktiske `LEASE_SECONDS`-konstanten (ikke et hardkodet, nå-utdatert tall). Full Enterprise Wiki-suite: 1159 passed, 3254 assertions, 0 failed.

**Kjent begrensning fjernet:** den forrige begrensningen (ingen reservasjonslås for sanntids-samtidighet) er lukket av denne rettelsen.

### Post-ingest QA redesignet til en minimal, deterministisk sluttkontroll (rettet 2026-07-13)

**Bakgrunn — run 28:** en reell OpenAI-timeout inne i `WikiSemanticReviserAiClient` (semantisk repair, del av den gamle "Level 3.5"-flyten i `EnterpriseWikiPostIngestQaService::executeQa()`) kastet en teknisk exception som ble fanget av `runForRun()`s ytre catch-blokk. Den blokken satte `qa_status=failed` **med et tomt `$result`-array** — snapshotet for run 28 viser derfor `technical_qa_passed=false`/`structural_qa_passed=false`, selv om alle 10 sider faktisk hadde ferdig innhold, alle continuation-steg var fullført, og lint ikke hadde noen åpne feil. Dette er nøyaktig klassen av feil beskrevet i tidligere runtime-notater: en teknisk feil under QA ble registrert som et faglig (innholdsmessig) `failed`-resultat.

**Redesign:** `EnterpriseWikiPostIngestQaService` bruker ikke lenger OpenAI i det hele tatt. `EnterpriseWikiSemanticQaService`, `EnterpriseWikiSemanticRepairService`, `WikiSemanticQaAiClient`, `WikiSemanticReviserAiClient` og `EnterpriseWikiGenerateAppliedPagesService` er fjernet fra orkestratorens konstruktør og fra `executeQa()` — ingen tekstgenerering, ingen omskriving av sider, ingen ny innholdsanalyse. Disse klassene selv er **ikke** slettet eller endret — `EnterpriseWikiSemanticQaService`/`EnterpriseWikiSemanticRepairService` brukes fortsatt aktivt av `EnterpriseWikiQaRegressionService` (8H kontinuerlig vedlikeholds-/regresjonsløkke), en helt separat mekanisme fra post-ingest QA.

**Ny, ren deterministisk sjekk** (`EnterpriseWikiPostIngestQaService::evaluate()` — rent lesende, ingen skriving, ingen AI-kall, kan derfor kalles trygt for prediksjon):
1. Alle sidene runnen skulle lage har en ferdig (current, ikke-tom) sideversjon — pluss at artikkel/sammendrag spesifikt finnes med innhold.
2. Alle continuation-steg er registrert fullført: sidegenerering (`generation_status=completed`), claim-ekstraksjon (`claims_extracted_at`), claim-verifikasjon (`EnterpriseWikiClaim.verified_at`) for hver side/claim i runnen.
3. Ingen aktiv ekstraksjons-/verifikasjonslease (`claims_claimed_at`/`claims_claim_token`, `verification_claimed_at`/`verification_claim_token` er null).
4. Ingen åpen kritisk lint-funn (severity=error, eller kode=`broken_wikilink` spesifikt) er registrert for runnen. Lint kjøres IKKE på nytt av QA — den leser kun funn continuation-pipelinens egen lint-fase allerede har skrevet, slik at QA forblir en ren sluttkontroll og ikke et aktivt steg.

**Resultat:** alt over stemmer → `qa_status=passed` → `finalizeFromExistingQaResult()` setter `status=completed`. Et konkret, forstått avvik (1 eller 4) → `qa_status=failed`. Noe kan ikke avgjøres sikkert ennå (2, 3, ingen sider i det hele tatt, eller en uventet teknisk exception under selve QA-kjøringen) → `qa_status=escalated` — **aldri** `failed`. Dette gjelder både `runForRun()`s ytre catch-blokk og snapshot-lagringens egen feilhåndtering: en teknisk feil under QA eskalerer nå alltid, uansett hvor den oppstår.

**Recovery oppdatert til å revalidere i stedet for å stole på gammel tilstand:** siden QA nå er billig og fullstendig deterministisk, trenger `wiki:recover-document-flow` ikke lenger stole på et potensielt korrupt/utdatert `qa_status`/snapshot. Kommandoen kaller `EnterpriseWikiPostIngestQaService::evaluate()` (les-only) for å beregne en prediksjon, og velger:
- **`revalidate_and_finalize`** — alle continuation-steg er komplette (evaluate() sitt `incomplete_steps` er tomt). Kjører den ekte, deterministiske `runForRun($run, retry: true)` (fanger ev. `qa_status=running` fastlåst av en død worker og frigjør den til `pending` først) og finaliserer runen fra det FAKTISKE, ferske resultatet — uten å kjøre sidegenerering, linking, claim-ekstraksjon, verifikasjon eller noen AI-kall på nytt.
- **`resume_continuation`** — et steg er genuint ikke fullført ennå. Tilbakestiller til `verification_linking` og dispatcher en fersk continuation-jobb, som trygt gjenopptar arbeidet (se steg-nivå idempotens over).

Dette gjør recovery enklere enn før: de gamle snapshot-matching-guardene (finnes snapshot, matcher qa_status/customer_id) er fjernet — de fantes bare for å kompensere for at man ikke turte re-kjøre en AI-drevet QA. Nå re-evalueres alltid fra faktisk gjeldende tilstand.

**Run 28 dry-run (verifisert, ingen faktisk recovery kjørt):**
```
[WIKI_RECOVERY] Observed state for run [28]:
[WIKI_RECOVERY]   status=failed qa_status=failed qa_attempt_count=1 pages=10
[WIKI_RECOVERY]   predicted verdict=passed incomplete_steps=[] critical_defects=[]
[WIKI_RECOVERY] Chosen next step: revalidate_and_finalize
```

**Sidegevinst i Deep Repair:** `EnterpriseWikiDeepRepairService` diagnostiserte `source_references`-behov FØR claim-ekstraksjon kjørte i samme reparasjonsforsøk — så nyekstraherte claims fikk aldri en verifiseringsrunde i samme pass. Under den gamle (løsere) QA-modellen var dette usynlig; under den nye strengere fullførings-sjekken hindret det runden i noensinne å nå `passed` etter én reparasjon. Rettet: verifisering kjøres nå alltid når claims nettopp ble (re-)ekstrahert i samme forsøk, ikke bare når opprinnelig diagnose fant manglende referanser.

**`EnterpriseWikiSemanticQaService`/`EnterpriseWikiSemanticRepairService`/`WikiSemanticQaAiClient`/`WikiSemanticReviserAiClient`/`EnterpriseWikiGenerateAppliedPagesService` er IKKE slettet** — kun fjernet fra post-ingest QA-orkestratorens konstruktør/`executeQa()`. De to første brukes fortsatt aktivt av `EnterpriseWikiQaRegressionService` (8H); den siste av `GenerateEnterpriseWikiAppliedPage`-jobben og `wiki:generate-applied-pages`-kommandoen — begge helt separate fra post-ingest QA.

**Tester:** `EnterpriseWikiPostIngestQaServiceTest` skrevet fullstendig om til den nye deterministiske modellen (29 tester: passed/failed/escalated for alle fire sjekkene, retry-semantikk, kundeisolasjon). `EnterpriseWikiRecoverDocumentFlowCommandTest` skrevet om til `revalidate_and_finalize`/`resume_continuation` (17 tester, ingen snapshot-matching-guards lenger). `EnterpriseWikiPostIngestQaCommandTest` (13), `EnterpriseWikiDeepRepairServiceTest` (15), `EnterpriseWikiQaSnapshotServiceTest` (15), `EnterpriseWikiSemanticQaServiceTest` (7 — kun direkte kall mot tjenesten, all orkestrator-integrasjon fjernet), `EnterpriseWikiSemanticRepairServiceTest` (10 — samme mønster), `EnterpriseWikiLineageTest` (8 — traceability-testene som gjaldt orkestratorens gamle semantic-repair-kjede er enten flyttet til å kalle tjenestene direkte eller fjernet der de ikke lenger har noe motstykke) er alle oppdatert til den nye modellen. `EnterpriseWikiMaintenanceCycleTest`, `EnterpriseWikiQaRegressionServiceTest` og `EnterpriseWikiPostIngestQaGatingTest` krevde ingen endring. Full Enterprise Wiki-suite: 1130 passed, 3092 assertions, 0 failed.

### Manuell kildegodkjenning + automatisk kildegjenfinning mot nye dokumenter (lagt til 2026-07-13)

**Bakgrunn:** en claim uten `EnterpriseWikiSourceReference` fikk varselet «Claim has no source reference» permanent — selv i tilfeller der (a) en System Owner faktisk hadde vurdert påstanden og visste den stemte, uten at det fantes et dokument som ordrett underbygger den, eller (b) et dokument som ordrett underbygger claimen ble lastet opp *etter* at claimen ble ekstrahert, og ingen mekanisme noensinne sjekket eldre claims på nytt mot nyere dokumenter.

**To uavhengige mekanismer, begge uten å bygge en ny parallell verifikasjonsmotor:**

**1. Manuell godkjenning (`WikiClaimController::approve()`/`unapprove()`):** gjenbruker de allerede eksisterende, men tidligere ubrukte, feltene `EnterpriseWikiClaim.approval_status`/`approved_by_user_id`/`approved_at` (satt til `pending` ved ekstraksjon, aldri endret noe annet sted før nå) — pluss ett nytt felt `approval_comment` (fritekst, valgfri). System Owner (samme tilgangskontroll som `WikiController::approve()`/`reject()` — inline `isSystemOwner()`-sjekk, Bid Manager forblir read-only) kan godkjenne en claim som mangler kilde; dette oppretter **aldri** en `EnterpriseWikiSourceReference` — det er en eksplisitt, sporbar menneskelig vurdering, ikke et substitutt for dokumentbevis. `unapprove()` nullstiller de fire feltene. Godkjenning/angring kaller umiddelbart `EnterpriseWikiAppliedRunLintService::resolveClaimMissingSourceFinding()`/`reopenClaimMissingSourceFindingIfStillMissing()` slik at UI reflekterer endringen uten å vente på neste lint-kjøring.

**2. Automatisk kildegjenfinning mot nye dokumenter (`EnterpriseWikiClaimSourceReconciliationService`):** når `WikiSourceController::store()` lykkes med tekstekstraksjon (`document_status=extracted`), dispatches `ReconcileEnterpriseWikiClaimSourcesForDocument` (kø `enterprise-wiki`, `tries=1`). Jobben henter alle claims for samme kunde som mangler en reell kildereferanse (`doesntHave('sourceReferences')` — dette omfatter **både** helt uverifiserte claims og manuelt godkjente claims) og kaller **samme** `WikiClaimVerificationAiClient::verifyClaim()` som brukes ved ordinær claim-verifikasjon i pipelinen. Finner AI-en et ekte, ordrett belegg i det nye dokumentet, opprettes én `EnterpriseWikiSourceReference` (med excerpt og lenke til dokumentet) — aldri en ny claim, aldri en ny side- eller sideversjon, aldri en regenerering av wiki-siden. En manuelt godkjent claim som får en reell kilde beholder hele godkjenningssporet (`approval_status`/`approved_by_user_id`/`approved_at`/`approval_comment` røres aldri av denne tjenesten) — kun `sourceStatus()`s prioritet endres til å vise den sterkere, nå tilgjengelige kilden.

**Hvorfor `verified_at` ikke er nok som sjekkpunkt:** en claim kan ha blitt verifisert mot ett dokument, og senere trenger sjekk mot et helt annet (nytt) dokument — `verified_at` er ett tidsstempel og kan ikke skille «sjekket mot dokument A» fra «sjekket mot dokument B». Ny tabell `enterprise_wiki_claim_source_reconciliation_attempts` — én rad per (claim, dokument)-par, unik constraint på `(enterprise_wiki_claim_id, enterprise_wiki_document_id)` — er det faktiske sjekkpunktet. Kolonner: `status` (pending/running/completed — livssyklus), `result` (nullable: supported/unsupported/error — kun satt når `status=completed`), `enterprise_wiki_source_reference_id` (nullable FK til den opprettede referansen), `claimed_at`/`claim_token` (reservasjons-lease), `attempted_at`, `error_message`.

**Reservasjon/lease-protokoll (samme mønster som claim-ekstraksjon/-verifikasjon over):** første forsøk gjør en `INSERT` og fanger unique-violation (`23505`) hvis raden allerede finnes — deretter et forsøk på å gjenerobre raden via en betinget `UPDATE` (kun hvis `status=pending`, eller `status=running` med utløpt lease). Leasen er `ReconcileEnterpriseWikiClaimSourcesForDocument::TIMEOUT_SECONDS` (1800s) + 300s margin. Persistering av en støttet claim skjer under `lockForUpdate()` med token-sjekk, slik at en jobb som mister leasen midt i et AI-kall ikke kan overskrive en annen workers resultat. En allerede fullført rad (uansett resultat, inkludert `error`) blokkerer nye AI-kall for det samme (claim, dokument)-paret permanent — dobbel dispatch/retry gir aldri dobbelt AI-kall, dobbel kildereferanse eller flere forsøksrader. Alt skopes strengt på `customer_id` gjennom hele kjeden (claim → side → kunde).

**Lint:** `EnterpriseWikiClaim::sourceStatus()` er nå eneste kilde til sannhet for om «Claim has no source reference» skal vises — prioritet: reell kildereferanse (funnet/mangler-excerpt) > manuell godkjenning > mangler kilde. Både `EnterpriseWikiAppliedRunLintService` (run-scoped) og den eldre `App\Services\Ai\Wiki\EnterpriseWikiLintService` (`wiki:lint`) bruker `needsSourceWarning()` (= `sourceStatus() === missing_source`) i stedet for den tidligere rene `sourceReferences->isEmpty()`-sjekken.

**UI (`resources/js/Pages/App/Wiki/Show.jsx`):** per-claim-badge skiller nå eksplisitt «Kilde funnet» / «Mangler utdrag» / «Manuelt godkjent» / «Mangler kilde» (`source_status`-feltet fra `WikiController::show()`). `claim_summary` har en ny bøtte `manually_approved`. System Owner ser «Godkjenn manuelt» (med valgfritt kommentarfelt) på claims uten kilde, og «Angre godkjenning» + godkjenningsmetadata (hvem/når/kommentar) på manuelt godkjente claims. Helsekontroll-listen grupperer nå identiske lint-funn (samme `code`) til én sammenslått linje (f.eks. «7 påstander mangler kilde») med en «Vis detaljer»-veksling, i stedet for å gjenta samme varsel per claim.

**Tester:** 22 nye tester fordelt på tre filer — `WikiClaimControllerTest` (10: tilgangskontroll for godkjenn/angre, varsel fjernes/gjenopprettes/gjenåpnes, guard mot godkjenning når kilde allerede finnes, guard mot angring når ikke godkjent, kundeisolasjon), `EnterpriseWikiClaimSourceReconciliationServiceTest` (11: kildereferanse opprettes med korrekt excerpt/dokumentlenke, ingen endring ved manglende støtte, ingen dobbelt AI-kall/referanse/forsøksrad ved dobbel kjøring, streng kundeisolasjon, `verified_at` alene stopper ikke ny sjekk, manuelt godkjent claim kan få reell kilde senere uten å miste godkjenningssporet, ingen sider/versjoner/claims regenereres, lint-funn løses automatisk, claim med eksisterende kilde sjekkes aldri på nytt), `WikiShowClaimStatusTest` (1: `claim_summary` og per-claim `source_status`/godkjenningsmetadata i Inertia-responsen). Full Enterprise Wiki-suite: 1152 passed, 3148 assertions, 0 failed.

### QA-tilgang for claim-godkjenning — utvider claim-autorisasjonen utover System Owner (lagt til 2026-07-13)

**Bakgrunn:** manuell claim-godkjenning (forrige seksjon) var hardkodet System Owner-only. Kunder ønsket at en egen QA-funksjon — en tilleggsegenskap, ikke en ny hovedrolle — skulle kunne gis til Bid Manager-, Contributor- eller andre brukere spesifikt for å kvalitetssikre Wiki-påstander, uten å måtte gjøre dem til System Owner (som gir mye bredere tilgang) og uten å bygge et nytt generelt rollesystem.

**Datamodell:** ny kolonne `users.is_qa` (boolean, default `false`, reverserbar migrasjon). QA er additiv og lagres per bruker — siden en bruker kun tilhører én kunde (`users.customer_id`), er `is_qa` allerede kundescopet på samme måte som `bid_role`; ingen egen kundetilknytnings-/pivot-tabell var nødvendig. Effektive tilganger = hovedrolle ∪ QA (hvis satt) ∪ «Alle» — se `Customer::roleHasPermission(string $bidRole, string $permission, bool $isQa = false)`, hvor `"qa"` er en gyldig verdi i en tilgangs `roles`-liste (på linje med `bid_manager`/`contributor`/`all`), IKKE en verdi `$bidRole` selv kan ha.

**Ny tilgang i galleriet:** `Customer::PERMISSION_APPROVE_WIKI_CLAIMS = 'approve_wiki_claims'`, standard `['system_owner', 'qa']` (visningsnavn «Godkjenne Wiki-påstander»). QA-kolonnen er lagt til i `CustomerEnvironmentController::permissionSettingsPayload()`s `role_columns` og virker generisk for ALLE rader i galleriet — de tre eksisterende tilgangene (`create_departments`, `create_users`, `view_all_cases`) er også oppdatert til å sende `$user->isQa()` inn i `roleHasPermission()`, slik at QA-kolonnen faktisk virker uansett hvilken rad en kunde huker av den for, ikke bare den nye.

**Claim-autorisasjon:** `WikiClaimController::approve()`/`unapprove()` bruker nå `! $user?->isSystemOwner() && ! $user?->canApproveWikiClaims()` i stedet for den gamle rene `isSystemOwner()`-sjekken. `User::canApproveWikiClaims()` følger samme mønster som `canViewAllCasesViaSettings()` (`canAccessCustomerFrontend()`-guard + `Customer::roleHasPermission(...)`). Audit-feltene (`approval_status`/`approved_by_user_id`/`approved_at`/`approval_comment`) er urørt — de lagrer alltid den faktiske bruker-IDen til den som utførte handlingen, uavhengig av om det var via System Owner-status eller QA. Fjernes QA fra brukeren senere, viser tidligere godkjenninger fortsatt korrekt person/tidspunkt/kommentar (feltene endres kun av en ny approve()/unapprove()-handling).

**Viktig skille bevart:** `WikiController::approve()`/`reject()`/`submit()` (hel side) er UENDRET og forblir System Owner-only — QA er en egen, smalere rettighet og gjenbruker aldri sidegodkjenningens autorisasjon. `WikiController::visibleStatuses()` er derimot utvidet til å inkludere `canApproveWikiClaims()` slik at en Contributor+QA (som ellers ikke ville hatt review-tilgang) kan åpne draft/pending_review-siden og verifikasjonsgrunnlaget som trengs for å behandle claims — dette er den eneste ekstra lesetilgangen QA gir; det åpner ikke Wiki-administrasjon, dokumentimport, kildesletting, regenerering, arkivering eller kundeadministrasjon.

**Brukeradministrasjon:** QA slås av/på i eksisterende «Brukere»-fane (`UserController::store()`/`update()`) med samme autorisasjon som å redigere `bid_role` (kun System Owner, aldri på seg selv) — `is_qa` er lagt til i `abortIfBidManagerAttemptsProtectedUserFields()`s beskyttede felt-liste, slik at en Bid Manager-aktør får 403 hvis feltet i det hele tatt sendes med i requesten.

**UI:** brukerlisten (både «Kundemiljø → Brukere» og den dedikerte `/app/users`-siden) viser et «QA»-merke ved siden av rollebadgen. Rediger/Opprett-bruker-skjemaene har en QA-avkrysningsboks (kun synlig når aktøren har lov til å redigere rollen — samme betingelse som rollefeltet). Wiki-siden (`Show.jsx`) leser en ny delt `auth.user.can_approve_wiki_claims`-prop (fra `HandleInertiaRequests`, beregnet via samme `canApproveWikiClaims()`) for å vise/skjule «Godkjenn manuelt»/«Angre godkjenning» — dette er nøyaktig samme regel som backend-endepunktet håndhever, så frontend er ren presentasjon og direkte HTTP-kall er fortsatt beskyttet uavhengig av UI.

**Tester:** 45 nye/oppdaterte tester — `CustomerQaAccessTest` (19: QA-tildeling og fjerning, hovedrolle uendret, kundeisolasjon, uautorisert tildeling, effektive tilganger for alle rollekombinasjoner, «Alle»-tilgang, Tilganger-tab QA-kolonne og konfigurasjon, is_qa/can_approve_wiki_claims-props), `WikiClaimControllerTest` (6 nye: QA-bruker godkjenner/angrer, Bid Manager+QA og Contributor+QA godkjenner, uautorisert forsøk endrer ingen audit-felt, QA-bruker fra annen kunde får 404), `WikiControllerTest` (7 nye: Contributor+QA ser draft/pending_review, Contributor+QA og Bid Manager+QA kan IKKE godkjenne/avvise hel side). Full Enterprise Wiki-suite: 1163 passed, 3179 assertions, 0 failed. Kundemiljø-suite (`CustomerQaAccessTest`, `CustomerUserManagementTest`, `CustomerEnvironmentPageTest`, `UserBidRoleTest`): 72 passed; 2 forhåndseksisterende, urelaterte feil i `CustomerEnvironmentPageTest` (bekreftet uendret før/etter denne oppgaven via `git stash`) er ikke rettet, jf. oppgavens avgrensning.

### Presisering: godkjenningsfeltene på en claim er gjeldende tilstand, ikke et fullstendig revisjonsspor (2026-07-13)

De to seksjonene over beskriver at `approval_status`/`approved_by_user_id`/`approved_at`/`approval_comment` «lagrer den faktiske bruker-IDen til den som utførte handlingen» og at fjerning av QA fra en bruker ikke endrer en *eksisterende, fortsatt gjeldende* godkjenning. Dette er korrekt, men må ikke leses som at det finnes et fullstendig, historisk revisjonsspor over alle godkjennings-/tilbaketrekkingshendelser på en claim.

`WikiClaimController::unapprove()` nullstiller alle fire feltene direkte tilbake til `pending`/`null` — det finnes ingen egen historikk-/loggtabell som bevarer forrige tilstand. Konkret: godkjennes en claim av bruker A, angres den deretter, og godkjennes den så på nytt av bruker B, finnes det **ingen** lagret spor av at bruker A noensinne godkjente den — den informasjonen er permanent overskrevet av `unapprove()`, og senere av den nye godkjenningen. Feltene representerer altså kun **siste kjente godkjenningshandling** på claimen, ikke en append-only hendelseslogg over alle godkjenninger og tilbaketrekkinger.

Dersom et fullstendig revisjonsspor over alle godkjennings-/angre-hendelser (hvem, når, kommentar, for hver enkelt hendelse — også de som senere er tilbaketrukket) blir et faktisk krav, må det modelleres som en egen, append-only historikktabell (f.eks. `enterprise_wiki_claim_approval_events`). Dette er ikke bygget, og er utenfor scope for det som er levert til nå.

## Arkitekturnotat — v0.7: Bindende kvalitetsstrategi for tillegg utover kildedokumentet (2026-07-21)

**Bindende produktregel, overordnet enhver tidligere claim- eller verifiseringsstrategi i dette dokumentet der de er i konflikt:**

> Procynia har lov til å bruke beste praksis og tilføre nyttig tekst som ikke står i kildedokumentet. Det eneste brukeren skal varsles om, er konkrete tekstområder som systemet har lagt til, og som ikke kan støttes av kildedokumentet. Dette er ikke nødvendigvis en feil — det kan være et nyttig beste-praksis-tillegg — men brukeren må tydelig kunne se at teksten ikke kommer fra kilden. Det eneste brukerrettede spørsmålet er: «Er dette konkrete tekstområdet støttet av kildedokumentet?» Brukeren skal ikke måtte forstå claims eller interne verifiseringskategorier (negasjon, modalitet, aktør, scope, teknisk usikkerhet o.l.) for å bruke Wikien.

**Hva dette korrigerer:** §B.5 («Claims skal... brukes til review») og verifikasjonsspråket i Fase 8G/8H (som behandler `unsupported_generated_content` som en kvalitetsdefekt på linje med interne motsigelses-/plausibilitetssjekker) må leses i lys av denne presiseringen for alt som faktisk er et **tillegg** (tekst systemet har lagt til utover kilden), til forskjell fra en **motsigelse** (tekst som aktivt strider mot kilden). Et tillegg er ikke en feil som må rettes eller eskaleres — det er et forslag brukeren avgjør. QA-blokkeringslogikken (`EnterpriseWikiPostIngestQaService::findClaimIntegrityDefects()`) er urørt av denne presiseringen; den avgjør om en *kjøring* trenger reparasjon, ikke hva som vises som en brukersak i funnlisten.

**Prinsipiell korreksjon (bindende fra v0.7):**

1. Et tekstområde som ikke er kildestøttet, men er en nyttig beste-praksis-/domenekunnskaps-tilføyelse, skal **beholdes** på siden — ikke fjernes eller blokkeres automatisk.
2. Det markeres alltid **direkte på Wiki-siden**, tydelig og lesbart, uten at brukeren må åpne et eget verifikasjonspanel for å oppdage det.
3. Interne verifiseringskategorier (`negation_mismatch`, `modality_mismatch`, `subject_mismatch`, `scope_mismatch`, `actor_mismatch`, selvrapporterte AI-sjekkdimensjoner, `technical_uncertainty`/`internal_error`) er **aldri** egne brukersaker. De kan beholdes internt for teknisk diagnostikk (i `EnterpriseWikiClaim.review_metadata`), men skal ikke opprette funn i funnlisten.
4. Flere interne claims som peker til samme sammenhengende tekstområde (`content_block_key`) skal aldri bli flere brukeroppgaver — gruppér på tekstankeret, én sak per område.
5. Enhver brukersak av denne typen skal ha en direkte «Gå til tekst»-handling som åpner riktig Wiki-side og fremhever nøyaktig det markerte området — gjenbruk av eksisterende `?claim_id=`-navigasjon (`WikiController::buildReviewReference()`, `Show.jsx`), ikke en ny mekanisme.
6. Brukeren kan godkjenne (behold), redigere (behold med endret tekst) eller fjerne (tekstområdet tas ut av siden) — enhver av disse handlingene lukker saken. Ingen av handlingene krever at brukeren forstår hvorfor systemet opprinnelig flagget teksten.
7. Denne regelen gjelder implementasjonsmessig kun **nye** dokumentimporter etter at den er implementert — eksisterende kjøringer og sider migreres ikke, og regenereres ikke, som en konsekvens av denne presiseringen alene.

**Teknisk implementasjon:** gjenbruker eksisterende `content_blocks_json`/`content_block_key`/`source_elements`, eksisterende `EnterpriseWikiClaim.content_origin` (`best_practice`/`unsupported_generated_content`), eksisterende godkjenn/rediger/avvis-flyt (`WikiClaimController`), og eksisterende Kjøringer→Funn-panel (`EnterpriseWikiRunFindingsService`) — se `EnterpriseWikiClaimFindingExplainer::isUserFacingAddition()` for det ene, sentrale filteret som avgjør hva som er en brukersak versus en intern diagnostikk-detalj. Ingen ny claim- eller markeringsmodell er innført.

## Arkitekturnotat — v0.8: historisk claim-kartlegging (superseded av v0.11) (2026-07-21)

> **Presisering (v0.11, 2026-08-06):** Dette notatet er beholdt som historisk kontekst for de gamle claims-fløyene i systemet. Den bindende regelen nå er at direkte kildeutsagn ikke blir claims; claims brukes kun for Procynias egne påstander, vurderinger, anbefalinger og innvendinger. Se arkitekturbeslutningen øverst i dokumentet for gjeldende modell.

**Bindende presisering, som en direkte konsekvens av v0.11:** v0.7 gjorde negasjon/modalitet/aktør/scope/subjekt-avvik og teknisk usikkerhet usynlige som *brukersaker*. Det betyr fortsatt ikke at direkte kildeutsagn skal claim-fiseres. Claims er nå avgrenset til Procynias egne påstander, vurderinger, anbefalinger og innvendinger. Direkte kildeutsagn skal spores via provenance, kildekobling og blokk-/elementreferanser.

**Historisk regel, ikke gjeldende produktregel:**

> Claims ble tidligere behandlet som et bredt, maskinlesbart kunnskapslag. Den modellen er nå avgrenset: claims skal kun brukes for Procynias egne påstander, vurderinger, anbefalinger og innvendinger. Direkte kildeutsagn skal ikke representeres som claims; de skal gå direkte inn i wikiinnholdet med provenance, dokumentreferanse og blokk-/elementreferanser. Beste-praksis-innhold er fortsatt tillatt, men er en klassifisering av Procynias egne tilføyelser, ikke en etikett på kildedokumentets egne fakta.

**Kartlegging (read-only, 2026-07-21) — hva som faktisk bruker claims i dag:**

| Prosess | Bruker claims? | Detaljer |
|---|---|---|
| Wikilink-materialisering (`EnterpriseWikiBuildPageLinksService`, `EnterpriseWikiLinkParser`/`Resolver`) | **Nei** | Jobber utelukkende mot `content_markdown`/`[[wikilinks]]`. Ingen claim-referanse i noen av disse filene. |
| Kunnskapsgraf (`EnterpriseWikiGraphDataService`, `EnterpriseWikiPageTraversalService`) | Delvis | Claims brukes kun som et visnings-metrikk (`claim_count` per node) — kanter kommer utelukkende fra `EnterpriseWikiPageLink` (`link_type=wikilink`). |
| Begrep/entitet-relasjonsbygging | **Nei** | Ingen dedikert tjeneste bygger begrep-/entitet-/rolle-relasjoner fra claims i dag. |
| Wiki-side-liste/søk (`WikiController::loadPagesTab()`) | Delvis | Kun `withCount('claims')` for visningsbadges — ingen filtrering, ingen rangeringsrolle. |
| Wiki-baserte svar (Fase 9, `RequirementWikiResearchService`, `RequirementWikiAnswerService`, `RequirementWikiPageRanker`) | **Ja** | Disse 3 av 11 `RequirementWiki*`-tjenestene leser faktisk `EnterpriseWikiClaim` (støtteclaims, claim-tekst til AI-svar, claim-hit-count for rangering). |

**Påvirket av commit 39d62c3?** Nei. 39d62c3 endret kun `EnterpriseWikiRunFindingsService` (Kjøringer→Funn-visning) og la til `EnterpriseWikiClaimFindingExplainer::isUserFacingAddition()`. Ingen av prosessene i tabellen over kaller noen av disse to — bekreftet ved kildekodesøk. Claims-tabellen (`enterprise_wiki_claims`) og alle rader i den er urørt av 39d62c3. Det finnes derfor intet konkret gap forårsaket av 39d62c3 å rette i relasjonsbyggingen.

**Kjent, forhåndseksisterende gap (ikke forårsaket av 39d62c3, ikke rettet i denne oppgaven):** De 3 `RequirementWiki*`-tjenestene som faktisk bruker claims i Wiki-svar, filtrerer i dag **ikke** på `content_origin` — en `best_practice`- eller `unsupported_generated_content`-claim brukes identisk med en `source_based`-claim som grunnlag for et generert svar. Dette er i spenning med regelen over («skal aldri fremstilles som dokumentert kundefakta»), men er utenfor scope for denne oppgavens avgrensede rettelse (fantes før 39d62c3, og å rette det er en egen, ikke-triviell endring i 3 AI-vendte tjenester — se §11 «Åpne beslutninger» for videre arbeid).

**QA-portvakt-retting (v0.8):** `EnterpriseWikiPostIngestQaService::findClaimIntegrityDefects()` og `EnterpriseWikiDocumentOwnerApprovalService::hasActiveClaimIntegrityDefects()` satte tidligere en kjøring til `repair_required` (og undertrykte Dokumenteier-godkjenning) alene på bakgrunn av et internt sammenligningssignal (negasjon/modalitet/aktør/scope/subjekt-avvik) — nøyaktig den skjulte klassifiseringen v0.7 sa aldri skal være en brukersak, men som frem til nå fortsatt kunne stoppe en kjøring teknisk. Begge steder krever nå `EnterpriseWikiClaimFindingExplainer::isUserFacingAddition($claim)` i tillegg til `blockingState()['blocks_gate']` — men kun når ingen eksplisitt `blocking_override` er satt; en autorisert brukers eksplisitte beslutning om å blokkere teller fortsatt, uansett kategori (det er ikke lenger «alene» en skjult klassifisering, men en bekreftet menneskelig beslutning). Reelle tekniske flytfeil (manglende artikkel/sammendrag, manglende gjeldende sideversjon, ufullført jobbkjede, aktivt lease, lint-feil) er urørt av denne endringen — de går gjennom helt andre sjekker (`runChecks()`, lint) som ikke er endret.

## Arkitekturnotat — v0.9: proveniens lukket i Wiki-baserte svar (2026-07-21)

**Bakgrunn:** v0.8 kartla og dokumenterte, men rettet bevisst ikke, at de 3 `RequirementWiki*`-tjenestene som faktisk bruker claims i Wiki-svar ikke skilte på `content_origin` — en `best_practice`-claim ble brukt identisk med en `source_based`-claim. Dette notatet lukker det konkrete gapet.

**Bindende regel (uendret produktprinsipp, nå håndhevet i kode):**

> Claims brukes fortsatt aktivt til søk, rangering, resonnering og Wiki-baserte svar. I denne modellen er claims Procynias egne påstander eller innvendinger; `source_based` betyr at claimen er støttet av kundens kildedokumenter, `best_practice` betyr at claimen er Procynias egen anbefaling/tilføyelse, og `mixed` betyr at et svar kombinerer begge. Beste praksis skal ikke fjernes fra søk/resonnering, men skal aldri fremstilles som dokumentert kundefakta eller brukes som kildebevis for en kundepåstand.

**Hvor proveniens forsvant (bevist read-only før implementering):** `RequirementWikiResearchService::supportingClaimIds()` returnerte bare claim-id-er uten `content_origin`. Dette fløt uendret gjennom `RequirementWikiAnswerService::claimTextsByPageId()` → `pagesForAi()` — en flat `claim_texts`-liste uten opprinnelse — inn i alle tre AI-klienter, der `RequirementWikiAnswerAiClient` merket blokken «VERIFIED FACTS» uansett faktisk `content_origin`. `alignment_status` (aligned/partially_aligned/best_practice/possible_conflict) er en AI-vurdering av tekstlig/semantisk forankring i Wiki-siden — aldri avledet av claims' faktiske `content_origin`.

**Retting (samme filer nevnt i v0.8-kartleggingen, ingen parallell svarmotor bygget):**
- `RequirementWikiResearchService::supportingClaimsByOrigin()` (erstatter `supportingClaimIds()`) returnerer claim-id-er delt i `source_based`/`best_practice`/`all`, og ekskluderer `unclassified`/`unsupported_generated_content`/`internal_error` fra begge grunnlagsbøtter — disse er QA-flaggede tilstander, aldri gyldig grunnlag for et generert svar, verken som fakta eller som forslag.
- `RequirementWikiAnswerService::pagesForAi()` bærer nå `source_based_claim_texts` og `best_practice_claim_texts` separat i stedet for én flat `claim_texts`-liste.
- `RequirementWikiAnswerAiClient`, `RequirementWikiAlignmentAiClient` og `RequirementWikiAnswerRevisionAiClient` bygger nå to tydelig merkede blokker («SOURCE-DOCUMENTED FACTS» / «BEST-PRACTICE SUGGESTIONS») i stedet for én udifferensiert «VERIFIED FACTS»-blokk, og svarpromptet instruerer eksplisitt om at beste-praksis-innhold aldri skal fremstilles som eksisterende kundefakta.
- `RequirementWikiAnswerService::computeSectionsProvenance()` beregner **deterministisk** — aldri via AI-selvrapportering — en `provenance_type` (`source_based`/`best_practice`/`mixed`) per svarseksjon, ut fra hvilke siterte sider faktisk hadde `source_based`- vs. `best_practice`-claims. En seksjon uten sidesitering er alltid `best_practice`. Dette er en EGEN akse fra `alignment_status`: alignment vurderer tekstlig forankring i Wiki-siden, provenance vurderer om det konkrete faktainnholdet er kundedokumentert.
- Toppnivå `has_source_based_support` (i `alignment_trace`) er sann når minst én seksjon er `source_based`/`mixed`. Frontend viser en tydelig «ikke dokumentert i kildedokumentene»-merknad når denne er usann.
- `sourcesPayload()` bærer `has_source_based_claims`/`has_best_practice_claims` per kilde, slik at UI kan vise kildekobling kun for det som faktisk er kildedokumentert.
- `RequirementWikiPageRanker::claimHitCountsByPageId()` vekter `source_based`-treff høyere enn `best_practice`-treff (`SCORE_PER_CLAIM_HIT=3` vs. `SCORE_PER_BEST_PRACTICE_CLAIM_HIT=1`) — kun for rangering, ikke for sitering, så eksisterende `unclassified`-fixtures/tester er upåvirket. Tittel-/overskrift-/innholdstreff (hovedsignalene) er uendret, så beste-praksis-sider fortsatt kan rangere høyt for anbefalingsspørsmål.

**Ikke endret:** Kjøring 39 er ikke reparert. Commits `39d62c3` og `2ebedf6` sin Kjøringer→Funn-logikk, badge-tekst og QA-portvakt er urørt — dette notatet gjelder utelukkende Wiki-svar-generering (Fase 9), en helt separat kodesti.

## Arkitekturnotat — v0.10: Claims og QA som frivillig, ikke-blokkerende kvalitetssløyfe (2026-08-01)

**Bindende produktregel, overordnet enhver tidligere claim-, QA- eller godkjenningsstrategi i dette dokumentet der de er i konflikt:**

> Claims er en frivillig, ikke-blokkerende kvalitetssløyfe for kunder som ønsker å bruke intern spisskompetanse til å profesjonalisere og forbedre Enterprise Wiki over tid. I den gjeldende modellen er dette Procynias egne claims, ikke direkte kildefakta. Ubehandlede claims er aldri en feiltilstand — verken for siden, kjøringen eller wikien som helhet.

**Bakgrunn:** v0.7–v0.9 fjernet interne verifiseringssignaler (negasjon/modalitet/aktør/scope/subjekt, teknisk usikkerhet) som *brukersaker*, men rørte ikke selve gate-logikken: `EnterpriseWikiPostIngestQaService`/8G-modellen fortsetter å beskrive QA som en obligatorisk "completion gate", og `EnterpriseWikiClaimFindingExplainer::buildContentFinding()` markerer fortsatt enhver gjenværende brukersak (inkludert den mer forbeholdne kategorien "mulig avvik") som blokkerende. Dette notatet er den bindende presiseringen av at **selve blokkeringen** — ikke bare hvilke claim-kategorier som vises som brukersaker — er i strid med produktstrategien, og opphever den delen av tidligere faseplaner.

**Primær bruker og hovedflyt:** Procynias primære brukere er normalt selgere som bruker Enterprise Wiki til å lage svar på anbud. Selgerens arbeid skal ikke blokkeres eller forsinkes av claims eller QA-behandling. Wiki skal kunne genereres, publiseres, brukes i anbudsarbeid og forbedres videre uten at claims først må behandles.

**Beste praksis (videreført, uendret som prinsipp):** Procynia kan bevisst supplere kildedokumentet med beste praksis. All vesentlig tekst Procynia legger til utover kildedokumentet skal merkes tydelig og konsekvent som beste praksis (eksisterende `content_origin = best_practice`-merking, jf. v0.7). Det er ikke i seg selv en feil at innhold mangler i kildedokumentet når tilføyelsen er bevisst, tydelig merket, og ikke presenteres som om den kommer fra kilden. Eksisterende beste-praksis-merking er en sentral, videreført del av løsningen — ikke noe dette notatet endrer.

**Claims — presisert rolle:** Claim-motoren har normalt ikke bedre bransjekunnskap enn den modellen som genererte Wiki-innholdet, og skal derfor ikke late som den sikkert kan avgjøre om merket beste praksis er faglig riktig eller galt. Claims er i stedet utvalgte faglige kontrollpunkter i en **frivillig** QA-prosess. Claims skal:
- være få og vesentlige, ikke uttømmende
- være dedupliserte — samme faglige forhold skal ikke opprettes som mange separate claims/funn bare fordi det forekommer på flere Wiki-sider (se «tekniske konsekvenser» under — ikke løst i dagens kode)
- prioritere innhold der kundens spisskompetanse kan tilføre verdi
- kunne knyttes til relevant Wiki-tekst og kildegrunnlag
- fremstå som forbedringsmuligheter, aldri som feilstatus
- aldri blokkere Wiki-generering, publisering eller bruk

**QA-rollen — presisert:** QA kan bestå av kundens egne fagpersoner med spisskompetanse. QA-prosessen er en verdiøkende mulighet for kunder som ønsker å profesjonalisere og forbedre Wiki over tid — ikke en obligatorisk godkjenningsport. QA skal kunne se den aktuelle påstanden, se hvor den brukes, se relevant kildegrunnlag, se om teksten er merket beste praksis, godkjenne påstanden, redigere eller foreslå forbedret tekst, avvise eller fjerne innhold, og tilføre kundespesifikk fagkunnskap. Når QA gjør en godkjent endring, oppdateres relevant Wiki-innhold på en kontrollert og sporbar måte (gjenbruker eksisterende godkjenn/rediger/avvis-flyt, ingen ny mekanisme).

**Ikke-blokkerende prinsipp (absolutt):**
- Ubehandlede claims skal ikke blokkere noe.
- Claims skal ikke sette en Wiki-kjøring i feilstatus.
- Claims skal ikke hindre publisering.
- Claims skal ikke hindre bruk av Wiki i anbudsarbeid.
- Selgere skal normalt ikke måtte forholde seg til claims.
- Manglende QA-behandling betyr ikke at Wiki er ugyldig.
- QA er kontinuerlig forbedring, ikke en obligatorisk godkjenningsport.

**Rollefordeling:**
- Procynia genererer Wiki og merker beste praksis.
- Claim-prosessen velger ut få, vesentlige faglige kontrollpunkter.
- Kundens QA-fagpersoner tilfører den faglige vurderingen.
- Godkjente QA-endringer forbedrer Wiki over tid.
- Selgerne kan fortsette å bruke Wiki uavhengig av QA-køen.

**Hva dette opphever/presiserer i tidligere deler av dette dokumentet — les i lys av v0.10 der de er i konflikt:**
- **Fase 8G, «QA som arkitektonisk senter»:** setningene «Post-ingest QA er **completion gate** for alle applied ingest-kjøringer» og «En applied run regnes ikke som ferdig før all QA er bestått» beskriver en gjennomført teknisk fase korrekt historisk, men uttrykker en obligatorisk-portvakt-strategi som v0.10 opphever som produktprinsipp. En kjørings/sides tilgjengelighet for bruk i anbudsarbeid skal aldri avhenge av at QA er fullført.
- **Fase 8G, «Hva coverage/eval måler»:** `claim_coverage_pct` og rød/gul/grønn-scoringen (der «approved uten claims» gir rødt) kan fortsatt vises som et **informativt helsesignal**, men skal aldri tolkes eller presenteres som at siden er ugyldig, feil, eller ikke kan brukes.
- **v0.8-tittelen** (historisk; nå superseded av v0.11): claims skal ikke fjernes som datastruktur, men de er ikke et generelt kunnskapslag for direkte kildefakta. De skal kun brukes for Procynias egne påstander og innvendinger, ikke som et obligatorisk mellomlager for alt Wiki-innhold.
- **§B.5 Claim-/verifikasjonsregler:** oppdatert til å vise til dette notatet — se nedenfor.

**Tekniske konsekvenser identifisert for senere arbeid (dokumentert her, ikke implementert i v0.10-notatet selv — se realiseringen rett under):**
- `EnterpriseWikiPostIngestQaService::findClaimIntegrityDefects()` setter i dag `qa_status = repair_required` (og `escalateRun()` kan følge av det) basert på blokkerende claim-funn — denne koblingen mellom claim-funn og kjøringens QA-status/tilgjengelighet står i motsetning til v0.10s ikke-blokkerende prinsipp og bør frikobles i en senere kodeendring.
- `EnterpriseWikiDocumentOwnerApprovalService::hasActiveClaimIntegrityDefects()` undertrykker Dokumenteier-godkjenning basert på aktive claim-funn — bør vurderes på nytt i lys av v0.10 (godkjenning/publisering bør ikke avhenge av ubehandlede claims).
- Det finnes i dag ingen deduplisering av claim-/QA-funn på tvers av Wiki-sider når samme fakta gjentas (kun `best_practice`-funn grupperes per blokk, jf. 39d62c3; `unsupported_generated_content`- og gjenbrukte-kanonisk-fakta-funn får fortsatt ett funn per side-forekomst). v0.10s krav om at claims skal være «få, vesentlige og dedupliserte» krever en fremtidig endring i `EnterpriseWikiRunFindingsService`/`EnterpriseWikiClaimFindingExplainer`, ikke gjort her.
- `WikiPageClaimExtractionAiClient::MAX_CLAIMS = 20` gjelder per side, uten en øvre grense per kjøring/dokument — i spenning med kravet om at claims skal være «få og vesentlige»; en fremtidig innstramming er ikke gjort her.
- Status-/UI-tekst som i dag kan fremstå som at en kjøring har «feilet» eller «ikke er ferdig» pga. claims/QA (`qa_status = escalated/failed/repair_required` i Kjøringer-fanen) bør vurderes for språk som tydelig reflekterer at claims/QA er frivillig — ikke endret her.

## Realisering av v0.10 (2026-08-01): claims/QA gjort ikke-blokkerende i kode

Alle fem tekniske konsekvenser v0.10 identifiserte over er nå implementert. Ingen av v0.10-notatets øvrige produktbeslutninger er endret av dette — dette notatet dokumenterer bare at koden nå faktisk oppfører seg slik v0.10 krevde.

**Ferdig:**
1. `EnterpriseWikiPostIngestQaService::findClaimIntegrityDefects()` → omdøpt `findOpenClaimQaSignals()` og gjort rent informativt. `evaluate()` returnerer aldri lenger `QA_STATUS_REPAIR_REQUIRED` — en teknisk sunn kjøring når alltid `qa_status = passed`, uansett åpne claim-QA-signaler. `EnterpriseWikiDocumentFlowService::escalateRunForClaimIntegrityRepair()` er fjernet; `finalizeFromExistingQaResult()`s `REPAIR_REQUIRED`-gren behandler nå historiske rader med den (gamle) statusverdien identisk med `passed`, uten å skrive om dem.
2. `EnterpriseWikiDocumentOwnerApprovalService::hasActiveClaimIntegrityDefects()` → omdøpt `hasOpenClaimQaSignals()`/`hasOpenClaimQaSignalsForVersion()`, gjort rent informativt. `buildRequirementGroupsFromClaims()` bygger nå alltid godkjenningskrav fra claims' faktiske kildereferanser, uavhengig av åpne claim-QA-signaler — godkjenning undertrykkes ikke lenger.
3. Claim-/QA-funn på Kjøringer→Funn-panelet grupperes nå per `canonical_fact_id` (`EnterpriseWikiRunFindingsService::claimDefectGroupKey()`) — samme underliggende faktum på flere sider blir én QA-sak med en `occurrences`-liste over alle berørte sider/claims, ikke ett funn per side. `blocks_run`/`blocks_page` er alltid `false` for claim-baserte funn; status er omdøpt til `open_for_qa_review`/`flagged_for_review` (aldri «blocking»).
4. Nytt konfigurerbart tak `services.enterprise_wiki.max_new_claims_per_run` (env `ENTERPRISE_WIKI_MAX_NEW_CLAIMS_PER_RUN`, standard 60 — 3× `MAX_CLAIMS`/side, dekker de to obligatoriske sidene artikkel+sammendrag med god margin) i `EnterpriseWikiExtractPageClaimsService::extract()`. Når taket nås, fullføres siden normalt (`claims_extracted_at` settes, ingen feilstatus) — kun nye claims utover taket utelates, og hendelsen logges. `MAX_CLAIMS = 20` per side selv er ikke endret; taket er en egen, run-vid grense lagt oppå den, ikke en erstatning.
5. Status-/UI-tekst oppdatert (`lang/no|en/procynia.php`, `resources/js/Pages/App/Wiki/Index.jsx`/`Show.jsx`): `document_owner_blocked_by_quality` → `document_owner_qa_review_open` (og en egen `document_owner_processing` for det ekte «fortsatt under generering»-tilfellet), `runs_pages_summary_blocked`/`blocked_by_quality`-tilstanden → `pending_review`/`qa_review_open`, `runs_findings_explanation_*` skiller nå eksplisitt mellom ekte blokkerende (kun teknisk lint) og åpne QA-punkter (aldri blokkerende).

**Ingen skjemaendring var nødvendig.** `status` (teknisk kjøringsstatus), det eksisterende `qa_status`-feltet, og det allerede live-beregnede `EnterpriseWikiRunFindingsService`-sammendraget er tilstrekkelig til å uttrykke de tre atskilte tilstandene v0.10 krever (kjøring fullført / Wiki tilgjengelig / N åpne QA-punkter) — ingen ny kolonne eller migrasjon var nødvendig.

**Bakoverkompatibilitet:** Historiske rader med `qa_status = repair_required`, `status = escalated`, eller `document_owner_summary.state = blocked_by_quality` er ikke migrert eller slettet — de leses/tolkes korrekt av den oppdaterte koden ved neste live beregning (f.eks. et nytt `wiki:recover-document-flow`-kall), uten noen masseoppdatering.

**Gjenstår (ikke del av denne realiseringen):**
- Eksisterende `blocking_override`-veksling på et claim («Ja, blokkerer» / «Nei, blokkerer ikke») er beholdt strukturelt uendret — feltet har ingen funksjonell gate-effekt lenger (siden ingen forbruker lenger bruker det til å avgjøre noe), men selve UI-mekanismen for å sette det ble ikke fjernet eller redesignet i denne oppgaven.
- Ingen automatisk bakoverkompatibel statusomskriving av allerede-eskalerte historiske kjøringer (f.eks. kjøring 565) er gjort — de forblir synlig «eskalert» i Kjøringer-listen som historisk faktum; Wiki-sidene de produserte er uansett fullt lesbare og brukbare uavhengig av dette (sideslesetilgang er allerede uavhengig av godkjennings-/kjøringsstatus, jf. commit 2098416).

### Blokkproveniens tapt etter semantisk reparasjon — rettet (2026-08-01)

**Bakgrunn:** enkelte claims på concept-/entity-sider fikk tom `content_block_key` og `content_origin = unsupported_generated_content`, selv om claim-teksten fantes ordrett i sidens `content_markdown`. Dette hindret `EnterpriseWikiVerifyPageClaimsService::isPositiveBestPracticeSuggestion()` fra å klassifisere reelt legitimt beste-praksis-innhold som sådan, fordi metoden bevisst krever et gyldig blokkanker (uendret i denne oppgaven).

**Rotårsak:** `EnterpriseWikiLinkSemanticRepairService::writeNewCurrentVersion()` og `EnterpriseWikiSemanticRepairService::createRevisedVersion()` opprettet begge en ny gjeldende `EnterpriseWikiPageVersion` med kun `content_markdown` satt — `content_blocks_json` ble aldri videreført, uavhengig av sidetype. I praksis rammet dette nesten utelukkende concept-/entity-sider, fordi lenke-semantisk QA i det observerte datagrunnlaget kun faktisk anbefalte og utførte reparasjon på disse sidetypene — ikke fordi mekanismen selv skiller på `page_type`.

**Fiks:** begge tjenestene kaller nå `EnterpriseWikiPageVersionBlockProvenanceRepairService::repairPageVersion()` (ny, targeted single-page-version inngang til den allerede eksisterende blokkproveniens-reparasjonstjenesten — se `wiki:repair-page-version-block-provenance`) rett etter at den nye versjonen er persistert, i samme databasetransaksjon. Rekonstruksjonen og claim-tilkoblingen gjenbrukes uendret (ingen duplisert matching-logikk); en tvetydig eller umulig rekonstruksjon logges og etterlater versjonen ubetjent (aldri gjettet), uten å blokkere den ellers vellykkede reparasjonen. `isPositiveBestPracticeSuggestion()` og kravet om gyldig blokkanker er ikke endret.

**Tester:** ny `EnterpriseWikiPageVersionBlockProvenanceRepairServiceTest` (7, dekker entydig/tvetydig/allerede-reparert), utvidet `EnterpriseWikiLinkLintAndSemanticRepairTest` (4 nye), utvidet `EnterpriseWikiSemanticRepairServiceTest` (4 nye), og to nye tester i `EnterpriseWikiPostIngestQaServiceTest` som bekrefter både at sikkerhetsregelen fortsatt står og at en reell beste-praksis-claim nå kan reddes etter at ankeret er gjenopprettet.

**Historiske tilfeller:** denne oppgaven retter kun nye tilfeller fremover. `wiki:repair-page-version-block-provenance --apply` finnes fortsatt for målrettet opprydding av eksisterende data, men ble ikke kjørt mot utviklings- eller produksjonsdata som del av dette arbeidet.

### Beste-praksis-QA gruppert etter faglig seksjon, ikke enkeltsetning (2026-08-01)

**Bindende prinsipp:** Beste-praksis-QA skjer på meningsfulle seksjoner, ikke på enkeltsetninger.

**Bakgrunn:** Sidegenerering (`EnterpriseWikiPageContentBlockService::buildBlocksFromStructuredResult()`) lager bevisst én `content_blocks_json`-blokk per overskrift og én per påfølgende avsnitt — en reell strukturell inndeling, ikke en feil. Claim-ekstraksjon lager claims per AI-identifisert påstand, aldri per blokk, så en overskrift-blokk (som «## Begrepsramme: ITIL og Incident management») får normalt ingen claims i det hele tatt, mens avsnittet rett under får en eller flere. Eksisterende gruppering i `EnterpriseWikiRunFindingsService` (v0.7 regel #4) grupperte allerede flere claims i SAMME blokk til ett funn, men grupperte aldri en overskrift sammen med avsnittet(ene) under den — resultatet var ett QA-funn per avsnitt, uten seksjonstittel, i stedet for ett funn per faglig seksjon («Om illustrasjonen», «Begrepsramme: ITIL og Incident management», «Samhandling mellom kunde og leverandør i hendelseshåndtering», «Bruksområde for illustrasjonen» på siden «Incident Management Illustration»).

**Fiks:** ny `EnterpriseWikiBestPracticeSectionService::mapBlocksToSections()` utleder seksjonsgrenser deterministisk fra data som allerede finnes — blokkrekkefølge, hver blokks egen ledende markdown-overskriftslinje (nivå + tekst), og `content_origin`. En seksjon er en overskriftsblokk pluss alle påfølgende `best_practice`-blokker, frem til neste overskrift på samme eller høyere nivå; en dypere underoverskrift nester inn i samme seksjon; en `source_based`- (eller annen ikke-beste-praksis-)blokk bryter seksjonen umiddelbart. Ingen ny AI-runde, ingen ny kolonne, ingen semantisk tekstsammenligning. Tjenesten gjenbrukes uendret to steder, slik at logikken aldri dupliseres:
- `EnterpriseWikiRunFindingsService::additionGroupKey()` grupperer beste-praksis-claims per seksjon (side-versjon + overskriftens blokknøkkel) i stedet for rå `content_block_key`. Funnet får seksjonens overskriftstekst som tittel, og en samlet `section_text` av alle underliggende claims i lesbar rekkefølge. Den primære claimen (laveste `position_order`, så laveste id — samme stabile ID-mønster som før) driver fortsatt godkjenn/rediger/avvis (`WikiClaimController` er uendret); øvrige claims i seksjonen forblir sporbare via `technical.claim_ids` og `block_keys`, akkurat som `occurrences` for claim-defekter.
- `WikiController::renderedContentBlocks()` stempler hver blokk med samme `section_key`/`section_heading` før den sendes til frontend. `Show.jsx` (via en ny, ren `groupContentBlocksBySection()`-hjelper) grupperer kun konsekutive blokker med samme allerede-beregnede `section_key` til ÉN sammenhengende «Beste praksis»-ramme — selve seksjonsdeteksjonen er aldri duplisert i JavaScript.

**Uendret:** `content_block_key`-kravet i `isPositiveBestPracticeSuggestion()`, claim-klassifisering, dokumenteiergodkjenning, QA-roller, og alle andre funntyper («Mulig innholdsavvik» osv.) — kun beste-praksis-funnenes gruppering og presentasjon er endret.

**Tester:** `EnterpriseWikiBestPracticeSectionServiceTest` (7, dekker seksjonsgrense-reglene direkte), `EnterpriseWikiBestPracticeSectionFindingsTest` (5, dekker gruppering/telling/isolasjon av ekte avvik/sidetyper i `EnterpriseWikiRunFindingsService`), og `wikiBestPracticeSectionLogic.test.js` (6, ren frontend-gruppering). Full relevant regresjon (234 tester på tvers av `EnterpriseWikiRunFindingsConsistencyTest`, `EnterpriseWikiBestPracticeSuggestionTest`, claim-ekstraksjon/-verifikasjon, `WikiControllerTest`, m.fl.) bekreftet uendret.

### Tydelig faglig sideansvar for å redusere gjentakelse mellom Wiki-sider (2026-08-01)

**Bakgrunn:** en read-only analyse av run 570 (`Incident Management Illustration`, `Sammendrag`, `Incident Management`, `ITIL`) viste at samme faglige innhold (rask gjenoppretting, kunde–leverandør-samhandling, prosessillustrasjon som felles referanse) gjentas nesten ordrett på flere sider. Rotårsak: maintainer decision oppga kun *hvorfor* en side skulle opprettes (`reason`), aldri hvilket faglig ansvar den skulle ha; alle sidetyper fikk samme fulle kildedokument; concept/entity-sider fikk i tillegg hele artikkel-/sammendragsteksten som «berikelse» uten grense; sammendraget ble generert direkte fra rådokumentet i stedet for som en kondensering av den ferdige artikkelen; og eksisterende regel mot gjentakelse («Do not repeat the same fact across multiple sections») gjaldt kun innad i én side.

**Fiks:** `EnterpriseWikiMaintainerDecisionPrompt` utvidet med `content_responsibility`/`must_not_repeat`/`related_page_guidance` per planlagt side (source_article, source_summary, concept_pages[], entity_pages[]) — required i det strenge OpenAI-skjemaet (samme mønster som `concept_pages`/`entity_pages`/`warnings`), men behandlet som valgfritt av PHP-validatoren for bakoverkompatibilitet med eksisterende lagrede beslutninger. `EnterpriseWikiMaintainerDecisionAiClient`s prompt instruerer maintaineren (som allerede ser alle planlagte sider samtidig i én beslutning) til å tildele hver side et distinkt ansvar.

`EnterpriseWikiGenerateAppliedPagesService::buildArticleSummaryContextForRun()` (ny, gjenbrukt av både `generatePageForRun()` — produksjonsstien — og `generate()`) gir nå artikkel- og sammendragssider samme type ansvarskontekst som concept/entity-sider allerede fikk, via en delt `responsibilityGuidance()`-formatterer. Sammendraget får i tillegg søskenartikkelens ferdige `content_markdown` som kontekst når den allerede finnes («Finished article to summarize...»); `EnterpriseWikiDocumentFlowService::beginGeneratingPages()` dispatcher nå artikkel-jobben før sammendrags-jobben (best effort, ingen ny kø-arkitektur) for å maksimere sjansen for at artikkelen er ferdig når sammendraget genereres — med en trygg, korrekt fallback til rådokumentet når den ikke er det.

`WikiPageContentAiClient`s prompt for alle fire sidetyper fikk en ny delt PAGE RESPONSIBILITY-regel: hold seg til eget ansvar, og gi maks én kort setning pluss en intern wikilenke for temaer en annen side eier. Sammendragets prompt instruerer eksplisitt å basere seg på den ferdige artikkelen når den er gitt, ikke uavhengig fra rådokumentet.

**Uendret:** claims-, QA- og funnlogikk, `content_block_key`-kravet, dokumenteiergodkjenning, eksisterende Wiki-sider (ingen ny import kjørt). Ingen ny kø-arkitektur eller run-status er innført — kun dispatch-rekkefølgen innad i eksisterende fase 1.

**Tester:** 16 nye i `EnterpriseWikiMaintainerDecisionPromptTest` (skjema/validering av de nye feltene), 5 nye i `GenerateEnterpriseWikiAppliedPageJobTest` (ansvarskontekst for artikkel/concept, ferdig-artikkel-kontekst for sammendrag med og uten fallback), 1 ny i `EnterpriseWikiDocumentFlowServiceTest` (dispatch-rekkefølge), 4 nye i `WikiPageContentAiClientTest` (promptinnhold per sidetype). Full relevant regresjon (336 tester på tvers av maintainer decision, sidegenerering, wikilink-validering, blokkproveniens, beste-praksis-seksjonering, semantisk reparasjon m.fl.) bekreftet uendret.

### Strukturert temaansvar (owned/reference-only/excluded) for å hindre overproduksjon (2026-08-01)

**Bakgrunn:** en reell import (run 572, etter at Wiki-køene ble restartet til forrige commit) viste at det todelte fritekst-ansvaret (`content_responsibility`/`must_not_repeat`) fungerte for å FORDELE ansvar mellom sider, men ikke for å BEGRENSE bredden på hver side: konseptsiden «ITIL Incident Management» fikk selv tildelt et for bredt `content_responsibility` (definisjon, kjerneprosess/roller, klassifisering/eskalering, OG måleparametre/Problem/Change Management-grensesnitt) og genererte deretter 4 avsnitt med detaljert ITSM-fagstoff — langt utover det et par korte kildesetninger om en illustrasjon skulle gi grunnlag for.

**Fiks:** erstattet den todelte modellen med tre eksplisitte lister per planlagt side i `EnterpriseWikiMaintainerDecisionPrompt`: `owned_topics` (forklares i dybde — bevisst kort, typisk 1-3 punkter, proporsjonalt med hva kildedokumentet faktisk krever), `reference_only_topics` (nevnes kort + lenke, aldri forklares), og `excluded_topics` (skal ALDRI nevnes på siden i det hele tatt — den nye, avgjørende mekanismen mot at en konsept-/entitetsside vokser til «en komplett lærebokside» bare fordi temaet er beslektet). `related_page_guidance` er uendret. `EnterpriseWikiMaintainerDecisionAiClient`s prompt instruerer nå eksplisitt: ved tvil om et beslektet tema hører til `owned_topics` eller `excluded_topics`, foretrekk `excluded_topics`.

`EnterpriseWikiGenerateAppliedPagesService::responsibilityGuidance()` formaterer nå de tre nivåene med tydelig, bindende språk («EXCLUDED — do not mention these at all»). `WikiPageContentAiClient`s prompt for alle fire sidetyper fikk: en bindende eksklusjonsregel (aldri nevne, ikke engang med én setning), et tak på reference-only-temaer (maks én kort setning + lenke, aldri egen overskrift), en strukturell breddebegrensning der antall `##`-seksjoner på concept/entity-sider maksimalt skal tilsvare antall `owned_topics`-punkter, en nødvendighetsregel for beste-praksis-innhold («a thin source document justifies a thin page» — ikke inkluder generelt fagstoff bare fordi det er korrekt og beslektet), og et eksplisitt forbud mot å gjenta samme setning på siden eller kopiere ordlyd fra en annen sides kontekst.

**Uendret:** claims-, QA- og funnlogikk. Ingen ny AI-runde — samme to eksisterende AI-kall (maintainer decision, sidegenerering) fikk kun rikere skjema/prompt.

**Tester:** `EnterpriseWikiMaintainerDecisionPromptTest` oppdatert til de nye feltnavnene pluss dedikerte tester for `excluded_topics`-validering. `WikiPageContentAiClientTest` fikk 6 nye tester (bindende eksklusjon, tak på reference-only, seksjonstak knyttet til owned_topics, nødvendighetsregel, anti-duplisering). `GenerateEnterpriseWikiAppliedPageJobTest` oppdatert til å verifisere at alle tre nivåer faktisk når `additionalContext`. Full relevant regresjon (345 tester) bekreftet uendret.

**Begrensning:** run 572 (det observerte tilfellet) ble generert med den GAMLE todelte modellen og er ikke re-generert i denne oppgaven — en ny import kreves for å bekrefte effekten på ekte AI-output; ikke gjort her.

> **Korrigert av v0.12 (2026-08-09) — hva som fortsatt gjelder, og hva testen lærte oss.** Den tredelte modellen (`owned_topics`/`reference_only_topics`/`excluded_topics`) står ved lag og fungerer etter hensikten for å *fordele og begrense* ansvar mellom sider. Run 20 viste imidlertid en grense den ikke er bygget for: modellen uttrykker eksklusivitet **per side**, aldri som en invariant over unionen av alle planlagte sider. To sider kan derfor hver for seg ha et helt legitimt `owned_topics`, samtidig som de konkurrerer om den **samme kildesubstansen** — noe som først blir synlig ved sidegenerering (`planned_section_only_links`). `owned_topics` er dessuten fri prosa uten strukturert kobling til kildeenheter, så kollisjonen kan ikke oppdages server-side i dag. Se Fase 8J og Arkitekturnotat v0.12. Ingenting i denne seksjonen er reversert.

## Arkitekturnotat — v0.12: eierskap gjelder semantisk substans, ikke dokumentelementer (2026-08-09)

**Bakgrunn — run 20 som arkitektur-testcase.** Dette var den første reelle importen av et *kompletterende* dokument etter at grunnlagsdokumentet allerede var importert. Den skal leses som en test av arkitekturen, ikke som et enkeltstående kodecase — dokumentene og domenet er irrelevante for konklusjonen.

> **Korrigert 2026-08-09.** En tidligere versjon av dette notatet konkluderte at run 20 avdekket at Enterprise Wiki mangler structured source provenance. **Det var feil** — se korreksjonsboksen i Fase 8J. Wiki har structured provenance i genereringskjeden. Punktlisten under er den korrigerte læringen.

**Hva testen viste, generisk formulert:**

1. Et første grunnlagsdokument kan importeres og gi en sammenhengende Wiki.
2. Et kompletterende dokument tilfører samtidig tre ting: berikelse av eksisterende kunnskap, nye concepts, og nye kryssrelasjoner.
3. **Structured provenance finnes allerede i generation layer** — paragraph/list_item/table_row/image med `source_element_key` og `source_element_type` når kilden er DOCX.
4. **Maintainer decision, som fordeler `owned_topics`, får ikke den samme katalogen.** Den ser flat `sourceText`.
5. Flere `owned_topics` kan derfor konkurrere om **samme kildesubstans** — på tvers av sider og innad på én side — uten at noen serverside-adresserbar proveniens kan fange det.
6. Page generation mottar dermed en allerede selvmotsigende plan.
7. Modellen kan da følge reference-only-regelen («maks én setning + lenke for et tema en annen side eier») og produsere en link-only-seksjon.
8. Coverage-validatoren stopper dette korrekt (`planned_section_only_links`) — valideringen gjør jobben sin.
9. Repair kan ikke reparere en plan som fortsatt er selvmotsigende. Observert utfall: `issues_resolved: 0`.
10. Problemet kan ikke løses robust med topic-title-matching eller med source-element-eksklusivitet.

**Konklusjonen styrkes:** fiksen hører hjemme **før** sidegenerering — men først når maintainer decision har nok adresserbar proveniens til at eierskap kan uttrykkes generisk. Det er 8J-1B.

**Det avgjørende funnet:** eierskap gjelder **semantisk substans**, ikke bare fysisk dokumentelement og ikke temanavn.

**Hvorfor strukturelle regler ikke er nok i dag.** Run 20 inneholdt to *strukturelt identiske* konfigurasjoner med motsatt utfall: to artikkel-temaer som begge navnga et konsept som samme beslutning opprettet en canonical owner for — den ene feilet, den andre genererte feilfritt. Enhver regel bygget på tilgjengelig struktur (create/reference_only-beslutning, `owning_page_title`, de tre temanivåene, `related_page_guidance`, `EnterpriseWikiConceptIdentityMatcher`) klassifiserer disse likt. Den ville enten flagget begge — og dermed brutt legitim berikelse — eller ingen, og dermed ikke fanget noe. Forskjellen var **ikke strukturell**: den lå i hvor mye distinkt kildesubstans som var igjen til hvert tema etter at de andre sidene hadde gjort krav på sitt.

`EnterpriseWikiConceptIdentityMatcher` er dessuten per design en **tittel↔tittel**-matcher med token-subset-regel. Den matcher ikke et sammensatt fritekst-`owned_topic` mot en konsepttittel, og skal ikke utvides til delvis overlapp for å få den til.

**Bindende konsekvenser:**

- Målbildet «én substansiell kunnskapsenhet skal ha én canonical owner» beholdes som produktmål.
- Det skal **ikke** håndheves med tekstheuristikk. Ingen fuzzy matching, ingen ordlister, ingen title-substring-regler, ingen domene- eller språkspesifikke regler.
- Invarianten «én `source_element_key` per `owned_topic`» er **avvist** — se Fase 8J trinn 2 for moteksemplene.
- `EnterpriseWikiPlannedSectionCoverageValidator` står uendret, og `planned_section_only_links` svekkes ikke. Symptomet er reelt; det er planen som er feil, ikke valideringen.
- Riktig rekkefølge er: strukturerte source elements → observer/mål → design semantisk fact/span-eierskap → håndhev generisk invariant før sidegenerering.

**Ikke endret av dette notatet:** run 20 er ikke reparert eller kjørt på nytt. Ingen produksjonskode, migrasjon, prompt eller validator er endret som del av v0.12 — dette er en plan- og retningsbeslutning. Neste implementeringssteg er Fase 8J trinn 1.

### Produksjonsaktivering — Etter 8G, 8H og 8I

> **Ikke aktiv fase.** Produksjonsaktivering skjer etter at coverage/eval (8G), continuous maintainer loop (8H) **og Karpathy-lenking/inkrementelt vedlikehold (8I)** er implementert og verifisert. Eksisterende runbook fra Fase 7 brukes som teknisk grunnlag. 8I er lagt til i v0.6 fordi produksjonsaktivering av en wiki uten fungerende inline-lenker ville aktivert et system som ikke oppfyller Karpathy-premisset.

Krav (uendret fra Fase 7-runbook):
- `ENTERPRISE_WIKI_AI_ENABLED=true` aktiveres kontrollert
- første produksjonsbruk med kort dokument
- reversering via `ENTERPRISE_WIKI_AI_ENABLED=false`

### Fase 9 — Sammenligning mot dagens RAG

> **Ikke implementert — kun produktbeslutning og modell er låst (2026-07-13).** Ingen kode, migrasjon, React- eller testfil er endret som del av denne beslutningen. Retrieval-strategi, promptdesign, datamodell for lagring av wiki-svar, nøyaktig UI-plassering og tilgangsstyring er ikke avgjort og krever egen avklaring før implementering starter.

**Produktbeslutning:** Procynia skal fortsatt bruke de samme allerede ekstraherte kravene som dagens svarfunksjon allerede bruker. Det skal ikke opprettes:

- en ny kravsekstraksjon
- en parallell kravtabell
- kopier av krav
- en ny anbuds- eller dokumentflyt

**Modell:**

```text
Samme ekstraherte krav
        |
        +→ eksisterende svarmotor
        |
        +→ Enterprise Wiki-svarmotor
```

**UI-retning:** hvert eksisterende ekstrahert krav får en ny knapp, «Generer Wiki-svar», i tillegg til den eksisterende svarknappen. Den eksisterende svarfunksjonen beholdes helt uendret — Wiki-svaret kommer i tillegg, ikke som erstatning.

**Videre arbeid i denne fasen (som før, fortsatt gjeldende retning):**
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
    "markdown": "## Oversikt\n\nSammenhengende wikiartikkel som nevner [[hms]] og [[referanseprosjekter]] der de er semantisk relevante..."
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

> **v0.6-korrigering:** Tidligere versjoner av dette eksempelet hadde separate `concepts`/`links`-array-felter ved siden av `markdown`. Det er fjernet — wikilinks skal kun uttrykkes inline i `markdown`-strengen (`[[slug]]` / `[[slug|anchor]]`), aldri som et parallelt strukturert felt. `content_markdown` skal være den eneste kilden en deterministisk parser leser lenker fra (se §4.10); et separat `links`-felt ville skapt nøyaktig den doble sannheten v0.6 forbyr.

### B.4 Page-regler

Alle sider skal:

- skrives på kundens språk
- være Markdown
- ha tydelige overskrifter
- bruke `[[wikilinks]]` semantisk relevant til sider som finnes i wikien fra før, opprettes i samme compile-run, eller identifiseres via index lookup som del av compile decision — ikke bare til sider i samme run (v0.6, se Fase 8I del B og G)
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

Se arkitekturbeslutningen og Arkitekturnotat v0.10 for den bindende produktstrategien: claims er en frivillig, ikke-blokkerende kvalitetssløyfe for Procynias egne påstander og innvendinger, aldri en obligatorisk godkjenningsport og aldri en automatisk faglig fasit over merket beste praksis eller direkte kildefakta.

Claims skal:

- være konkrete og verifiserbare
- ha `confidence`
- ha `excerpt`
- ha `conflict_note` ved konflikt
- brukes til frivillig review, ikke som artikkelens primære layout, og aldri som en blokkerende gate for generering, publisering eller bruk

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

**v0.6-tillegg (Fase 8I):**

- deterministisk parser finner alle `[[slug]]`- og `[[slug|anchor]]`-forekomster i `content_markdown`
- parser rapporterer broken link for slug som ikke finnes hos kunden
- parser ignorerer self-links (side som lenker til seg selv)
- `EnterpriseWikiPageLink` materialiseres kun for lenker som faktisk finnes i teksten — ingen kombinatorikk-rader
- ny current version trigger ny parsing og oppdaterte relasjoner for den siden
- backlinks og graph edges leser samme materialiserte relasjoner (ingen avvik mellom de to)
- concept/entity-generering mottar katalog over relevante eksisterende sider og kan lenke dem
- incremental relinking: ny concept/entity-side kan foreslå oppdatering av en eksisterende side som allerede omtaler begrepet, uten å røre urelaterte sider
- renderer gjør `[[wikilinks]]` til klikkbare interne lenker til `/app/wiki/{slug}` med bevart ankertekst
- ukjent slug i renderer gir ikke en ugyldig/knekt intern lenke
