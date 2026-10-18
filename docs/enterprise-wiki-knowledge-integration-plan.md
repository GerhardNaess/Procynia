# Enterprise Wiki – pålitelig kunnskapsintegrasjon, verifisering og varsling

Status (2026-10-18):

| Del | Arkitektur | Implementering | Tester |
|---|---|---|---|
| Arbeidspakke A – robust generering | Godkjent (D1, D3, D7 og publiseringsrettelsen) | Implementert, ikke committet (§16) | Testet (§16.3) |
| Arbeidspakke B – kunnskapskobling | Akseptert som planleggingsretning, ikke godkjent for implementering | Ikke påbegynt | – |
| Arbeidspakke C – varsler og resultatvisning | Akseptert som planleggingsretning, ikke godkjent for implementering | Ikke påbegynt | – |
| Test med ekte AI (D11) | Inngår i strategien | Ikke gjennomført, ikke godkjent for kjøring | – |
Bindende rammer: `AGENTS.md` (Enterprise Wiki architecture invariants) og
`docs/enterprise-wiki-architecture.md`. Forslaget er sjekket mot design-sjekklisten i §J der (se §14).

Merking i dokumentet: **[Finnes]** = verifisert eksisterende kode. **[Forslag]** = endring som krever godkjenning.

---

## 1. Kort konklusjon

1. **Fullføringsmodellen trenger ingen nye kjøringsstatuser.** Kjøringen har allerede de statusene vi trenger:
   - `completed` → «Fullført»
   - `escalated` → «Krever oppfølging»
   - `failed` → «Mislyktes»
   - `cancelled` → «Avbrutt»

   Det som mangler er to ting:
   - én ny dimensjon: *ble relasjonsarbeidet faktisk utført?*
   - et *historisk resultatbilde* av kjøringen.

   Begge løses med tre nye kolonner. Det trengs ingen nye tabeller.

2. **Ved delvis feil anbefales kontrollert delvis fullføring (B).** Da blir ikke vellykkede sider stående uten relasjonsbehandling, slik side 111 i kjøring 26 ble.
   - Den eksisterende, men utilkoblede, `EnterpriseWikiPageGenerationFailureRecoveryService` kobles på for *forbigående* feil, slik at man kan prøve igjen målrettet.
   - Kjøring 26 viser hvorfor ren gjenopptak (A) ikke er nok. Feilen var `planned_section_no_evidence`, altså en deterministisk plandefekt. En ny generering av samme side ville feilet på nøyaktig samme måte.

3. **Kandidatgrunnlaget for koblinger er feil kilde i dag.**
   - Planleggeren (maintainer decision) ser *hele* Wiki-indeksen med utdrag.
   - Sidegeneratoren får bare tittel, slug og type for de 50 sist endrede sidene, uten statusfilter.
   - Forslaget er å la planleggerens eksplisitte henvisninger (`related_page_guidance`) bære `page_id`, og å bygge katalogen rangert: kjøringssider → sider planleggeren pekte på → én hopp-naboer → aktualitet. Hver oppføring får et kort utdrag.

4. **Inngående relinking bør bytte kontrakt fra «skriv om hele markdown» til `link_intents` på eksisterende blokker.** Backend setter da inn lenken i *uendret* tekst og kontrollerer mekanisk at ingenting annet er endret. Dette fjerner risikoen for omskriving og innholdstap. Først da er det trygt å utvide utløserne til nye artikler.

5. **Varsel bygges på eksisterende `UserNotificationWriter` med en ny `wiki.run_*`-hendelse.** Bjella, panelet, tilgangsfilteret og navigasjonen fungerer uendret. Det som må bygges er to ting:
   - Ett sentralt punkt for terminal overgang. Statusen settes i dag fra rundt ti steder, og alltid via query builder, slik at en observer aldri ser endringen.
   - En mottaker. Kjøringen har ingen brukerkolonne i dag.

6. **«Se resultat» bruker eksisterende Kjøringer-fane.**
   - Lenken er `?tab=runs&focus_run={id}`.
   - Resultatet vises i et nytt tredje panel, «Resultat», ved siden av «Sider» og «Funn».
   - Panelet viser et lagret øyeblikksbilde av hva kjøringen gjorde, og et lag over som viser dagens tilstand.

---

## 2. Faktisk eksisterende arkitektur (verifisert)

### 2.1 Kjøring og orkestrering

- **Modell [Finnes]:** `App\Models\EnterpriseWikiIngestRun`, tabell `enterprise_wiki_ingest_runs`.
  - `TERMINAL_STATUSES` = completed, failed, escalated, cancelled.
  - `qa_status`: pending, running, passed, repair_required, failed, escalated.
  - Kjøringen har **ingen brukerkolonne** og **ingen oppsummerings- eller tellefelt**.
- **Per-side-plan [Finnes]:** `EnterpriseWikiIngestRunPage`, tabell `enterprise_wiki_ingest_run_pages`. Den er den varige sannheten for hva kjøringen berørte:
  - `action` (created / updated / patched)
  - `generation_status`
  - `generated_page_version_id` (en uforanderlig peker til versjonen kjøringen produserte)
  - `claims_extracted_at`
  - leasefelt
- **Koordinator [Finnes]:** `EnterpriseWikiDocumentFlowService`. Den bruker ikke `Bus::batch`. Den samler trådene med radlås og «siste jobb vinner», med forsinkede sentineller.
- **Flyten [Finnes]:**
  1. `RunEnterpriseWikiDocumentFlow` → `performMaintainerDecision` → `performApplyMaintainerDecision`. Her opprettes **sideskall** uten versjon, i `EnterpriseWikiMaintainerDecisionApplyService::resolvePage()`.
  2. `beginGeneratingPages` → `GenerateEnterpriseWikiAppliedPage`, én jobb per side (`tries=1`).
  3. `FinalizeEnterpriseWikiPageGeneration` lager bare article↔summary-strukturlenker. Deretter kjører enten utsatt concept/entity-bølge eller continuation.
  4. `ContinueEnterpriseWikiDocumentFlowAfterPages` → `continueAfterPagesGenerated()` kjører i rekkefølge:
     - patch targets
     - cross-page reconciliation
     - **`performMaterializeWikilinks`**: lenkerader skrives og graf-projeksjon sendes i kø
     - **`performIncrementalRelinking`**
     - claim-ekstraksjon og claim-verifisering
  5. `continueAfterClaimVerification()` kjører i rekkefølge:
     - `performAppliedRunLint`
     - **`performLinkSemanticRepair`**
     - cross-page consistency
     - `performPostIngestQa`
     - `finalizeFromExistingQaResult()`, som ender i `completeRun()`, `escalateRun()` eller `markRunFailed()`.
- **QA-dommen [Finnes]:** `EnterpriseWikiPostIngestQaService::evaluate()` er deterministisk og bruker ingen AI.
  - Uferdige steg, for eksempel `page_generation_incomplete`, gir **escalated**. Dette sjekkes *før* kritiske defekter.
  - Kritiske defekter, for eksempel manglende artikkel eller blokkerende lint, gir **failed**.
  - Ellers blir dommen **passed**. Claim-signaler endrer aldri dommen.

### 2.2 Delvis feil: hva skjedde med kjøring 26

Lest fra lokal database, kun lesing:

| Side | Type | generation_status | Versjon | Feil |
|---|---|---|---|---|
| 110 | article | failed | nei | `EnterpriseWikiPlannedSectionEvidenceMissingException`: `planned_section_no_evidence` |
| 111 | summary | completed | ja | – |

- Kjøringen står med `status=failed`, `failed_phase=NULL` og `qa_status=NULL`. Ingen av sidene har wikilink-rader.
- Mekanismen [Finnes]:
  - `FinalizeEnterpriseWikiPageGeneration::finalizeInitialPhase()` kaller sin egen private `markRunFailed()` så snart én pivot i bølgen er `failed`. Den setter verken `failed_phase` eller `transient_failure`.
  - Alt etter dette steget hoppes over: materialisering, relink, claims, lint og QA.
  - `EnterpriseWikiBuildPageLinksService::materializeWikilinksForRun()` returnerer tomt for terminale kjøringer. Side 111 beholder derfor `[[lenker]]` i teksten, inkludert article↔summary-lenken til skallet 110, men får ingen rader.
  - Utsatte concept/entity-pivoter blir stående `pending` for alltid.
- **Gjenopptak finnes delvis [Finnes]:**
  - `EnterpriseWikiPageGenerationFailureRecoveryService` (`evaluate`/`attempt`) prøver bare de feilede pivotene på nytt. Den har **ingen kaller**, bare tester.
  - Den nekter når andre pivoter er `pending`, altså når feilen skjedde i den første bølgen og det finnes utsatte sider.
  - `EnterpriseWikiEscalatedRunRecoveryService` håndterer eksplisitt *ikke* `page_generation_incomplete`.
  - `wiki:recover-document-flow` kan fortsette continuation, men genererer ikke feilede sider på nytt.

### 2.3 Sideskall

| Flate | Behandling av side uten gjeldende versjon [Finnes] |
|---|---|
| Sideliste (`WikiController::index`) | Vises. Publiseringsstatus blir `STATE_NO_VERSION` med blocker `publication_blocker_no_version`. |
| Graf (`EnterpriseWikiGraphDataService`) | **Vises som node**, med `current_version_id=null`, og telles som foreldreløs. |
| Wiki-indeks (`EnterpriseWikiIndexContextService`) | Tas med, med `excerpt=null`. |
| Lenkekatalog (`EnterpriseWikiLinkCatalogService`) | **Tas med som lenkemål**, også skall fra eldre feilede kjøringer. |
| Spør Wiki / tilbudsarbeid | Utelates (`answerableVersion()` krever innhold). |
| Claims, coverage, orphan-concept | Utelates (`whereHas('currentVersion')`). |
| Opprydding | Ingen. `cancelRun()` rører bevisst ikke sider. |

### 2.4 Kunnskapskobling

- **Planlegging [Finnes]:**
  - `EnterpriseWikiPlanningContext` → `EnterpriseWikiIndexContextService::buildForCustomer()`. Dette er *alle* kundens sider: tittel, slug, type, status og et utdrag på 200 tegn. Indeksen er **uten tak og uten statusfilter**.
  - Planleggeren returnerer `related_page_guidance` med formen `{page_title, relationship}`. Feltet har **ingen page_id**. Det blir ren fritekst i sidegenereringen (`EnterpriseWikiGenerateAppliedPagesService::responsibilityGuidance()`).
  - `reference_only_topics` blir en instruks om «én kort setning pluss lenke».
  - `patch_targets` har ingen lenkesemantikk. Det er deterministiske patcher (`EnterpriseWikiPatchApplicationService`).
- **Generering [Finnes]:**
  - `WikiPageContentAiClient` bruker strengt JSON-schema. `link_intents` er **påkrevd per blokk**, men kan være tom.
  - Hver intensjon har `{intent_id, target_page_id, anchor_text, reason}`. `target_page_id` er en enum av katalogens ID-er når katalogen ikke er tom.
  - Modellen har forbud mot å skrive `[[…]]` selv.
- **Katalog [Finnes]:** `EnterpriseWikiLinkCatalogService::buildForPage()` inneholder:
  - alle kjøringens sider, også de som ikke er generert ennå, og
  - inntil `MAX_OTHER_PAGES = 50` andre sider etter `orderByDesc('updated_at')`.

  Den har **ingen status- eller versjonsfilter og ingen utdrag**. Utvalget gjøres på aktualitet, ikke relevans.
- **Materialisering [Finnes]:** `EnterpriseWikiLinkIntentMaterializer` skriver `[[slug|anker]]` i markdown og beholder bare materialiserte intensjoner i `content_blocks_json`.
  - Ugyldig mål (ukjent, selv, annen kunde, ikke i katalogen) **kaster unntak og feiler hele siden**.
  - Ikke funnet anker gir en stille `skipped_anchor_not_found`, som bare logges.
  - Arkiverte eller erstattede mål godtas.
  - Avviste forslag lagres ingen steder.
- **Lenkerader [Finnes]:** `enterprise_wiki_page_links` er en *projeksjon av markdown*, og skrives bare av `EnterpriseWikiBuildPageLinksService::materializeWikilinksForPage()`. Det er også eneste sted som sender `ProjectEnterpriseWikiPageToGraph` i kø.
  - Unik nøkkel: `(customer_id, from_page_id, to_page_id, link_type)`.
  - `metadata` inneholder bare `anchor_text`. Det finnes ingen begrunnelseskolonne.
  - `source='maintainer_decision'` er definert, men skrives aldri.
- **Inngående relinking [Finnes]:** `EnterpriseWikiIncrementalRelinkService`.
  - Utløses bare av `concept`/`entity`.
  - Kandidater er ILIKE-treff på tittelen, maks 10.
  - `WikiLinkRevisionAiClient::reviseLinks` returnerer **hele markdown omskrevet**, med inndata avkortet til 10 000 tegn.
  - Validatoren sjekker lenker, men **ikke** at øvrig tekst er uendret.
  - Ny versjon skrives via `writeNewCurrentVersionRestoringBlocks`, og deretter `afterAutomatedContentChange()`: en godkjent side går tilbake til utkast mens publisert versjon fortsatt vises.
  - Idempotens: `enterprise_wiki_page_relink_attempts`.
- **Semantisk lenkereparasjon [Finnes]:** `EnterpriseWikiLinkSemanticRepairService` bruker `wiki.review_links` (gpt-4.1-mini) og `wiki.revise_links` (gpt-5).
  - Validatoren krever byte-identiske overskrifter og bare de forespurte endringene.
  - Én rad per (kjøring, side) i `enterprise_wiki_page_link_qa_attempts`.
- **Graf [Finnes]:**
  - `GET /app/wiki/graph-data` leser bare rader med `link_type=wikilink`.
  - Kantene viser `intents[]` (anker, `reason`, kontekst) fra kildesidens gjeldende `content_blocks_json`.
  - Neo4j-fokus projiseres fra radene.
- **Lint [Finnes]:** `EnterpriseWikiAppliedRunLintService` sender ut disse kodene:
  - `missing_wikilink_materialization`
  - `wikilink_projection_mismatch`
  - `stale_wikilink_graph_edge`
  - `broken_wikilink` (blokkerende)

  Kodene `page_without_outgoing_links` og `run_targets_available_but_not_linked` er definert, men **sendes ikke ut av noe**.
- **Kjente stier som etterlater rader i utakt [Finnes]:**
  - manuell redigering via `WikiController::updateWorkingVersion`
  - `EnterpriseWikiSemanticRepairService`
  - terminale kjøringer
- **Felles semantisk navigasjonskjerne [Finnes]:** `EnterpriseWikiSemanticRetrievalService` brukes av Spør Wiki og tilbudsarbeid, men ikke av lenking.
  - Sidelinje: `RequirementWikiCatalogBuilder` sorterer etter `page_id` stigende før taket på 100. Når Wiki-en er større enn 100 sider, ser navigasjonen bare de eldste. Dette ligger utenfor oppdraget, men bør meldes som egen sak.

### 2.5 Varsler

- **Skriving [Finnes]:** `App\Services\Notifications\UserNotificationWriter::notify()` er eneste skrivevei til bjella.
  - Mottaker må være aktiv og tilhøre samme kunde.
  - Aktøren varsles ikke om egne handlinger.
  - `firstOrCreate` på global unik `dedupe_key`.
  - `DB::afterCommit`.
  - Feil fanges med `rescue`.
- **Lesing [Finnes]:** `UserNotificationAccessScope` lar `wiki.*` passere når kunden har Wiki-modulen og brukeren har `wiki.view`.
- **Frontend [Finnes]:**
  - `NotificationBell.jsx` (domenet `wiki` er allerede merket «Wiki»).
  - Polling hvert 60. sekund i `CustomerAppLayout.jsx`.
  - Klikk på et varsel markerer det som lest og navigerer til `target_url`.
- **Wiki-hendelser i dag [Finnes]:** `EnterpriseWikiReviewNotificationService` (`wiki.review_assigned`, `changes_requested`, `page_published`, `qa_assigned`). Teksten er hardkodet norsk.
  - Nyere konvensjon er språknøkler i mottakerens språk, slik `AssignmentNotifier` og `SupplierNotificationService` gjør.
- **Ingen eksisterende varsel for Wiki-kjøringer.**
- **Prinsipp [Finnes]** (`docs/notifications-and-tasks-plan.md` §1): bjelle = «noe har skjedd», Mine oppgaver = «arbeid som fortsatt er ditt». En ferdig kjøring er en bjellehendelse.

### 2.6 Kjøringsvisning og tester

- **UI [Finnes]:**
  - Fanen `GET /app/wiki?tab=runs` støtter allerede `focus_run`.
  - Hver rad har panelene «Sider» (`GET /app/wiki/runs/{run}/pages`) og «Funn» (`GET /app/wiki/runs/{run}/findings`, `EnterpriseWikiRunFindingsService`).
  - Det finnes ingen lenketelling per kjøring. Ingen steder lenker til `/app/wiki/graph?run_id=`, selv om API-et støtter det.
- **Tester [Finnes]:**
  - `EnterpriseWikiWikilinkEndToEndIntegrationTest` er den eneste ende-til-ende-testen. Den bruker rå `[[wikilenker]]` med `'link_intents' => []`.
  - `EnterpriseWikiLinkIntentAnchorContractTest` og `EnterpriseWikiGeneratePageWikilinkValidationTest` tester materializeren, men ikke lenkerader eller graf.
  - **Ingen test går fra intensjon via materialisering til database og graf.**
  - Det finnes ingen kommando for evaluering eller test med ekte AI.

---

## 3. Foreslått fullføringsmodell

### 3.1 Arkitekturinvariant [Forslag – legges inn i `docs/enterprise-wiki-architecture.md`]

> En Wiki-kjøring rapporteres ikke som vellykket kunnskapsintegrasjon før relasjonsstegene for alle genererte sider er gjennomført og teknisk kontrollert. Teknisk kontroll er ikke faglig kvalitetssikring. En side uten gjeldende versjon er aldri en ferdig Wiki-artikkel.

### 3.2 Begrepene kobles til eksisterende data

| Begrep | Hvor det leses fra | Ny statusverdi? |
|---|---|---|
| Generering fullført | Alle pivoter har `generation_status=completed` | Nei |
| Relasjonsbehandling fullført | Ny pivot-checkpoint `links_assessed_at` (§7.1), satt etter siste materialisering for siden | Nei, en checkpoint |
| Kunnskapskobling verifisert | Ny deterministisk kontroll i QA (§7.3), lagret i `qa_result.checks.link_integration` | Nei |
| QA fullført | `qa_status ∈ {passed, repair_required, failed, escalated}` | Nei |
| Kjøring fullført | `status=completed` | Nei |
| Kjøring delvis fullført | `status=escalated` med `qa_result.incomplete_steps` ∋ `page_generation_incomplete` eller `link_integration_incomplete` | Nei |
| Kjøring feilet | `status=failed` | Nei |

**Ny QA-regel [Forslag]:** `EnterpriseWikiPostIngestQaService::findIncompleteSteps()` får to nye utfall:
- `link_assessment_incomplete`: en generert side mangler `links_assessed_at`.
- `link_integration_mismatch`: markdown, rader og graf stemmer ikke overens.

Begge gir **escalated**, aldri failed. Dette følger eksisterende prinsipp: teknisk ufullstendighet er escalated, en konkret defekt er failed.

**Ingenting endres i review-, publiserings- eller godkjenningsreglene.** Sidestatus og `published_version_id` berøres ikke av noe forslag i A eller C. B berører dem bare gjennom eksisterende `afterAutomatedContentChange()` (se §8, beslutning D4).

### 3.3 Publiseringsinvariant: verifisert i kode (tillegg 2026-10-18)

**[Finnes]**
- `published_version_id` og `status=approved` skrives **bare** i `WikiController::approve()`. Det er den eneste forekomsten i `app/`. Ingen automatisk skriver publiserer.
- `EnterpriseWikiPublicationSettlementService::afterAutomatedContentChange()` gjør bare én ting: en `approved` side går tilbake til `draft`, og `published_version_id` står urørt. Sider i `pending_review` og sider som aldri er publisert, lar den være i fred.
- Nye sider opprettes som `draft` (`EnterpriseWikiMaintainerDecisionApplyService::resolvePage()`). Delvis fullføring (D1) kan derfor aldri publisere en side. Det eneste den gjør, er å la utkast gå gjennom relasjons-, claim- og QA-stegene, som er de samme stegene en vellykket kjøring går gjennom i dag.

**Nytt funn (eksisterende hull, ikke innført av forslaget)**
- `EnterpriseWikiGenerateAppliedPagesService::generatePageForRun()` skriver ny gjeldende versjon uten å kalle `afterAutomatedContentChange()`. Ingen status sjekkes før skrivingen.
- Når en kjøring regenererer en **publisert** side (`action=updated`), blir siden stående som `approved` med en upublisert gjeldende versjon.
- `EnterpriseWikiPublicationStatusService` viser da tilstanden `published_with_changes` og blokkeringen `publication_blocker_edited_after_publish`. `WikiController::submit()` godtar bare `draft`, så den nye versjonen kan ikke sendes til review.
- Det skjer ingen publisering, men siden står fast.
- Den gamle legacy-stien (`FinalizeEnterpriseWikiIngest`) satte sidene til `draft`. Dokumentflyten gjør det ikke.
- **Forslag:** rettingen tas inn i arbeidspakke A. Den består i å kalle eksisterende `afterAutomatedContentChange()` etter at versjonen er skrevet i `generatePageForRun()`, akkurat slik alle andre automatiske skrivere gjør.
- Dette er en endring i review-tilstand. Den er derfor **ikke** godkjent som del av D1, og krever eget ja ved klarsignal for A. Det er ikke en ny D-beslutning, fordi den bare bruker en regel som finnes.

**Akseptansekriterier for invarianten (A og B)**
1. En arkitekturtest slår fast at ingen kode utenfor `WikiController::approve()` skriver `published_version_id` eller `status=approved` på `enterprise_wiki_pages`.
2. En delvis feilet kjøring etterlater alle nye sider som `draft`. Hver eksisterende publisert side den har endret, har uendret `published_version_id`.
3. En side i `pending_review` som en kjøring berører, beholder status og tildelt kontrollør. Eksisterende guard i review-flyten nekter godkjenning av en endret versjon.
4. Inngående relinking (B) mot en publisert side gir en ny `draft`-versjon. Publisert versjon vises uendret i Wiki-visning og Spør Wiki til en kontrollør godkjenner.

---

## 4. Delvis mislykket generering – anbefaling

### 4.1 Vurdering

| Konsekvens for | A: Gjenopptakbar (bare retry) | B: Kontrollert delvis fullføring |
|---|---|---|
| Sideversjoner | Vellykkede versjoner beholdes, men blir liggende uten oppfølging til noen retter årsaken | Vellykkede versjoner får full behandling straks |
| Kildehenvisninger og claims | Ekstraheres ikke før gjenopptak | Ekstraheres og verifiseres for genererte sider. Steget hopper allerede over sider uten versjon. |
| Verifisering, lint og QA | Kjører ikke | Kjører. QA eskalerer via eksisterende `page_generation_incomplete`. |
| Grafprojeksjon | Ingen rader, slik det skjedde med side 111 | Rader og projeksjon for genererte sider. Kanter til skall skjules (§5). |
| Review og publisering | Uendret | Uendret. Sidene er utkast som før. |
| Køstatus | Kjøringen er `failed`, og utsatte pivoter henger på `pending` | Den utsatte bølgen kjører, og kjøringen ender `escalated` |
| Retry | Virker bare ved forbigående feil. Kjøring 26 ville feilet igjen. | Retry av feilede sider etterpå, med de samme idempotente stegene |
| Duplikater | Beskyttet av eksisterende lease, token og `generated_page_version_id` | Samme beskyttelse. Alle continuation-steg er allerede idempotente per pivot og checkpoint. |
| Transaksjoner | Hver sideversjon skrives i sin egen transaksjon | Samme. Ingen ny transaksjonsgrense. |

### 4.2 Anbefaling: B, med målrettet retry for forbigående feil

Hvorfor B passer arkitekturen:

1. Continuation-stegene er allerede bygd for å hoppe over sider uten versjon og for å være idempotente per pivot (`claims_extracted_at`, `verified_at`, leaser). B tar i bruk egenskaper som finnes.
2. QA-tjenesten tolker allerede ufullstendig generering som `escalated` før den vurderer kritiske defekter. B gir derfor korrekt sluttstatus uten nye statuser.
3. A alene løser ikke kjøring 26, fordi en deterministisk plandefekt krever ny plan og ikke en ny generering. B sørger for at det som lyktes blir korrekt integrert, og gjør feilen synlig.
4. B utelukker ikke A. Den eksisterende retry-tjenesten kobles på etterpå for forbigående feil.

**Konkrete endringer [Forslag]:**

1. `FinalizeEnterpriseWikiPageGeneration::finalizeInitialPhase()` og `finalizeDeferredConceptEntityPhase()`:
   - Feilede pivoter stopper ikke lenger kjøringen.
   - Hvis bølgen har **minst én fullført side**, går kjøringen videre, både til utsatt bølge og til continuation.
   - Hvis **alle** sidene i bølgen feilet, beholdes dagens `markRunFailed()`. Den rettes til å sette `failed_phase='page_generation'` og `transient_failure` via `EnterpriseWikiTransientFailureClassifier`, slik service-varianten gjør.
   - Regelen for den utsatte bølgen: hvis den første bølgen hadde feil, dispatches likevel concept/entity-sidene. De er selvstendige sider, og feilen i én artikkel skal ikke holde dem tilbake.
2. `EnterpriseWikiPostIngestQaService`: ingen endring er nødvendig for at dommen blir `escalated`. Den eksisterende årsaksteksten utvides med navnene på de feilede sidene.
3. **Retry for forbigående feil:**
   - `EnterpriseWikiPageGenerationFailureRecoveryService::decide()` utvides til å godta `status=escalated` når `qa_result.incomplete_steps` bare inneholder `page_generation_incomplete`.
   - Den nekter når en feilet pivot er klassifisert som deterministisk, altså når klassifikatoren gir `transient_failure=false`. En ny pivotkolonne trengs ikke. Klassifiseringen avledes ved behov ved å kjøre `EnterpriseWikiTransientFailureClassifier::isTransient()` på det eksisterende feltet `generation_error`. Klassifikatoren er en tillatelsesliste: bare kjente markører som 429, timeout og 5xx regnes som forbigående. Alt annet, også `planned_section_no_evidence`, regnes som deterministisk.
   - Når en side er regenerert, sender recovery i kø `ContinueEnterpriseWikiDocumentFlowAfterPages`. Alle steg er idempotente, så bare den nye siden får reelt arbeid.
   - Tjenesten kobles til en knapp, «Prøv igjen for feilede sider», i Kjøringer. Den bruker samme autorisasjon som `retryMaintainerDecision`, altså `WIKI_SOURCE_MANAGE` og `canDeleteEnterpriseWikiDocument`.
   - `EnterpriseWikiEscalatedRunRecoveryService` delegerer `page_generation_incomplete` til denne tjenesten i stedet for å avvise.
4. **Deterministiske plandefekter** (`planned_section_no_evidence`, `EnterpriseWikiPageGenerationIncompleteException`) får ingen automatisk retry. Siden vises som ufullstendig med begrunnelse (§5). Ny planlegging av én side er en egen beslutning (D3).

**Kjøring 26 med forslaget:**
- 111 får rader, claims og QA.
- Kanten fra 111 til 110 skjules til 110 finnes.
- Kjøringen ender som «Krever oppfølging», med årsaken «Vurdering av betalingsbetingelser … kunne ikke genereres: planlagt seksjon mangler kildegrunnlag».

Eksisterende historiske kjøringer endres ikke automatisk.

---

## 5. Sideskall

**Prinsipp [Forslag]:** «ufullstendig» er en **avledet tilstand** og lagres ikke som status. Den betyr at siden ikke har noen `currentVersion`. Den eksisterende `EnterpriseWikiPublicationStatusService::STATE_NO_VERSION` er kilden.

| Flate | Forslag |
|---|---|
| Graf (`EnterpriseWikiGraphDataService`) | Skall utelates som noder, og kanter til dem skjules. `summary.incomplete_count` tas med, slik at det skjulte er synlig som tall. |
| Lenkekatalog | Skall fra *andre* kjøringer utelates. Egne kjøringssider beholdes, fordi den utsatte bølgen er avhengig av at artikler kan peke på konsepter som ikke er generert ennå. |
| Wiki-indeks | Beholdes, men får `has_content: false`, slik at planleggeren ikke behandler skallet som eksisterende kunnskap. |
| Sideliste og sidevisning | Merket **«Ufullstendig»** med årsak fra pivotens `generation_error`, oversatt til brukerspråk, og lenke til kjøringen. |
| Lint | Eksisterende `missing_current_version` brukes for sider i kjøringens pivot. Den er informativ, ikke blokkerende. |
| Opprydding | Ingen sletting. Når retry eller ny kjøring gir siden en versjon, forsvinner tilstanden av seg selv. |

Merk at `[[lenker]]` til et skall blir stående i markdown og vises allerede som lenker i dag. Forslaget skjuler bare graf-kanten og stryker skallet fra katalogen. Lenken blir gyldig av seg selv når siden fullføres, og ingen tekst må skrives om.

---

## 6. Kunnskapskobling mot eksisterende artikler

### 6.1 Hvordan systemet sikres å undersøke relevante eksisterende artikler

Tre trinn, alle avgrenset og observerbare:

1. **Planleggeren leser kartet, slik den gjør i dag.** [Forslag] `related_page_guidance` får et valgfritt felt `page_id` (nullable), som valideres mot indeksen.
   - Navnet på en eksisterende side blir da en entydig kandidat, ikke en tittel generatoren må gjette.
   - Dette er en prompt- og schema-endring (beslutning D5).
   - Backend løser i tillegg dagens titler deterministisk mot eksakt tittel eller slug, slik at eldre beslutninger også gir ID-er.
   - **Validering av `page_id` (tillegg).** Backend behandler AI-output som forslag, aldri som autoritative data. En `page_id` godtas bare når alle disse holder:
     1. `customer_id` er lik kjøringens kunde.
     2. Siden finnes.
     3. Status er ikke `archived` eller `superseded`.
     4. Siden har `currentVersion`, eller den er en side i samme kjøring.
     5. Den er ikke siden selv.
     6. Den er ikke allerede oppført i samme `related_page_guidance`.

     Ved brudd forkastes bare ID-en, med logging. Fritekstveiledningen beholdes. Beslutningen feiler ikke, og det koster ingen ekstra reparasjonsrunde.

     Er både `page_id` og `page_title` oppgitt og de ikke stemmer, vinner ID-en når den er gyldig. Avviket logges.

     Samme filtre gjelder igjen i `EnterpriseWikiLinkIntentMaterializer`. Planleggerens valg gir altså bare kandidatstatus, ikke en lenke.
2. **Katalogen rangeres etter relevans, ikke aktualitet** (§6.2).
3. **Generatoren vurderer og returnerer `link_intents` som i dag.** Det som er nytt er at *resultatet av vurderingen registreres* (§6.3).

### 6.2 Katalog over eksisterende kunnskap [Forslag]

`EnterpriseWikiLinkCatalogService::buildForPage()` bygges i lag med fast totalbudsjett (forslag: 60 oppføringer utenfor kjøringen):

| Lag | Kilde | Tak |
|---|---|---|
| 0 | Kjøringens egne sider, som i dag | Utenfor taket |
| 1 | Sider planleggeren pekte på for *denne* siden: `related_page_guidance.page_id`, `patch_targets.target_page_id` og eksisterende sider oppgitt i `concept_pages` eller `entity_pages` | Alle |
| 2 | Én hopps naboer til lag 1 via eksisterende `wikilink`-rader i begge retninger | 20 |
| 3 | Fyll etter `updated_at`, som i dag | Resten av budsjettet |

- **Filtre i alle lag:**
  - `customer_id`
  - status utenfor `archived` og `superseded`
  - har `currentVersion`; skall utelates, unntatt egne kjøringssider
- **Utdrag:** hver oppføring får et utdrag på 160 tegn. Det gjenbruker `EnterpriseWikiIndexContextService::extractExcerpt`, så det kommer ingen ny AI og ingen embeddings. Modellen kan da vurdere relevans og ikke bare tittel.
- **Logging:** `lag` logges per oppføring i `[WIKI_LINK_CATALOG]`, og lagres i pivotens vurderingsjournal (§6.3).
- **Materializeren:** `EnterpriseWikiLinkIntentMaterializer` avviser i tillegg arkiverte og erstattede mål.

Forholdet til invariantene:
- Ingen leksikalsk hard gate. Lag 3 er sikkerhetsnettet, og den semantiske avgjørelsen tas av planleggeren, som ser hele indeksen.
- Ingen vektordatabase.
- Ingen ny retrieval-motor. Den felles navigasjonskjernen (`EnterpriseWikiSemanticRetrievalService`) gjenbrukes ikke her. Den er bygd for spørsmål og ville gitt ett ekstra AI-kall per side. Planleggerens eksisterende kall gjør samme jobb gratis.

**Prompt og kostnad:** 60 oppføringer med utdrag er omtrent 60 × 70 = cirka 4 000 input-tokens per sidegenerering. Det er moderat sammenlignet med dagens prompt for `wiki.generate_page`. Tallet måles (§12).

**Skaleringsrisiko som meldes, men ikke løses her:** `EnterpriseWikiIndexContextService` har ingen tak, og hele indeksen sendes til planleggeren. Ved tusenvis av sider blir planleggerprompten dyr. Det trenger en egen arkitekturbeslutning om indeksering. Målepunktet foreslås i §12.

### 6.3 Registrere vurderingen: fem utfall [Forslag]

Ny pivotkolonne `link_assessment` (JSON) og `links_assessed_at` på `enterprise_wiki_ingest_run_pages`. Den skrives deterministisk av backend og er **aldri selvrapportert av AI**:

```json
{
  "catalog_size": 47,
  "catalog_by_tier": {"run": 4, "planned": 3, "neighbour": 6, "recent": 34},
  "offered_page_ids": [12, 40, 77],
  "proposed": 5,
  "materialized": [{"target_page_id": 40, "anchor_text": "...", "reason": "...", "intent_id": "..."}],
  "rejected": [{"target_page_id": 99, "outcome": "rejected_archived_target"}],
  "skipped_anchor_not_found": [{"target_page_id": 77, "intent_id": "..."}],
  "outcome": "linked"
}
```

| Utfall (`outcome`) | Deterministisk regel |
|---|---|
| `linked` (relevante koblinger funnet og materialisert) | `materialized ≠ []` |
| `no_relevant_links` (ingen relevante koblinger etter gjennomført vurdering) | AI-kallet ble fullført med ikke-tom katalog, `proposed = 0` |
| `no_candidates` (ingenting å vurdere mot) | Katalogen var tom |
| `rejected_only` (koblingsforslag avvist) | `proposed > 0`, `materialized = []` |
| Ikke gjennomført | `links_assessed_at IS NULL` |
| Behandling feilet | Pivot `generation_status=failed`, eller materialisering eller rader i utakt (§7.3) |

**Når en side regnes som kunnskapsintegrert (invariant A, tillegg)**

En side er kunnskapsintegrert når `links_assessed_at` er satt **og** `outcome` er en av `linked`, `no_relevant_links` eller `no_candidates` **og** lenkekontrollen i §7.1 er `ok` for siden.

Null lenker er bare godtatt når `outcome` er `no_relevant_links` eller `no_candidates`. Alle andre tilstander gjør at siden ikke regnes som integrert, og at kjøringen ikke kan få «Teknisk kontrollert»:
- `links_assessed_at IS NULL`, fordi steget ble hoppet over, feilet eller kjøringen ble avbrutt
- `rejected_only`
- avvik i lenkekontrollen

Tre presiseringer:
- **Patchede sider** (`action=patched`) går ikke gjennom sidegenerering. `EnterpriseWikiPatchApplicationService` beholder uendrede blokker og deres lenker byte-identisk. De får `outcome = "unchanged_by_patch"`, som settes deterministisk når patchen er lagret. De telles for seg i resultatet og regnes som integrert bare hvis lenkekontrollen er `ok`. Ny substans i en patch får ingen egen lenkevurdering i denne omgang. Det er en kjent begrensning som skal meldes, ikke skjules.
- **Retry:** når en side regenereres, overskrives `link_assessment` og `links_assessed_at` for den siden. Det beholdes ingen akkumulerte tellinger, så en retry kan ikke telle en lenke to ganger.
- **Versjoner etter generering i samme kjøring** (link semantic repair, cross-page reconciliation): vurderingen gjelder fortsatt. Lenkekontrollen i §7.1 kjøres mot sidens gjeldende versjon ved QA og fanger derfor endringer gjort etter genereringen.

**Endring i materializeren [Forslag]:** avvisning av *mål* skal registreres og forkastes, ikke kaste unntak. Det gjelder ukjent, slettet, arkivert og annen kunde. I dag feiler én hallusinert eller nylig slettet `target_page_id` hele siden. Brudd på kontrakten selv kaster fortsatt: ugyldig `intent_id`, `{{wiki_link:`-markør, tomt anker. Annen kunde logges i tillegg som warning. Det er en sikkerhetsrelevant hendelse, selv om den ikke kan skje så lenge schema-enumen gjelder.

**Valgfri, ikke anbefalt nå:** et felt i schema der modellen begrunner hvorfor den ikke fant noen lenker. Det ville vært selvrapportert av AI og ikke etterprøvbart (beslutning D6).

### 6.4 Inngående lenker [Forslag]

**Steg 1 – trygg kontrakt (forutsetning).** `EnterpriseWikiIncrementalRelinkService` bytter fra `WikiLinkRevisionAiClient::reviseLinks` (hel omskrevet markdown) til en ny operasjon, `wiki.propose_inbound_links`:

- **Inn:** kandidatsidens blokker (`block_key` og markdown, uten avkorting av blokkene som sendes) og den nye siden (tittel, type, utdrag).
- **Ut:** `link_intents` per blokk, `{block_key, anchor_text, target_page_id = ny side, reason}`. Høyst én per kandidatside.
- **Backend:** materializeren setter inn lenken i eksisterende tekst. Deretter kommer en **mekanisk invariant**: `stripWikilinks(ny) === stripWikilinks(gammel)` per blokk, og alle andre blokker er byte-identiske. Brudd gir avvist forsøk, ikke ny versjon.
- **Beskyttelse:**
  - Brukerredigert innhold berøres aldri utenfor ankeret.
  - Det er ingen 10 000-tegnsavkorting og ingen omskriving.
  - Provenance per blokk er uendret, fordi bare lenkemarkup legges til.

**Steg 2 – utvidede utløsere:**
- `TRIGGER_PAGE_TYPES` utvides med `article` når `action=created`.
- `summary` holdes utenfor, fordi summary er sammenkoblet med sin artikkel.

**Kandidater:**
- Sidene planleggeren pekte på for den nye siden (§6.2 lag 1). Dette er den viktigste kilden: leverandørstyring blir kandidat for den nye artikkelen om leverandørkontroll fordi planleggeren navngir den.
- Dagens ILIKE-treff på tittel.

**Sikkerhetsgrenser:**

| Grense | Verdi (forslag) |
|---|---|
| Kandidater per utløser | 5 (i dag 10) |
| Kandidater per kjøring totalt | 15 |
| Nye inngående lenker per kandidatside per kjøring | 1 |
| Kandidater som utelates | `pending_review`, `archived`, `superseded`, kjøringens egne sider, sider som allerede lenker til målet |
| Modell | `gpt-4.1-mini`. Oppgaven er å velge et anker, ikke å skrive. Kan endres i `config/ai_operations.php`. |
| Idempotens | Eksisterende `enterprise_wiki_page_relink_attempts` per (kjøring, utløser, kandidat) |
| Publisering | Eksisterende `afterAutomatedContentChange()`: publisert versjon fortsetter å vises, og den nye versjonen krever ordinær review. Beslutning D4. |

**Akseptansekriterier for inngående lenker:**
1. Ingen tegn utenfor `[[…|anker]]`-markupen endres i noen eksisterende side. Dette bevises av en test med uendret-tekst-invarianten.
2. Gjentatt kjøring gir ingen ny versjon og ingen duplikatrad.
3. En kandidat i `pending_review` får aldri ny versjon.
4. Hvert forsøk har en rad i `relink_attempts` med utfall og eventuell `created_page_version_id`.
5. Hver inngående lenke har `reason` i kandidatversjonens `content_blocks_json.link_intents`, slik at grafen viser begrunnelsen.

`performLinkSemanticRepair` beholdes, men bør på sikt over på samme intent-kontrakt. Det er ikke i omfang her, men skal meldes.

---

## 7. Verifisering av relasjoner

### 7.1 Teknisk verifisering (deterministisk, ny QA-kontroll) [Forslag]

Ny metode `EnterpriseWikiPostIngestQaService::checkLinkIntegration($run)`. Den kjøres i QA-steget, *etter* `performLinkSemanticRepair`, slik at alle lenkeendringer i kjøringen er med. For hver side i kjøringen som har gjeldende versjon:

1. `M` = gyldige `[[…]]` i gjeldende markdown, parset med `EnterpriseWikiLinkParser` og løst med `EnterpriseWikiLinkResolver`.
2. `R` = rader med `link_type=wikilink` fra siden.
3. `I` = materialiserte intensjoner i `content_blocks_json`.
4. Kontroller:
   - `M = R` (eksisterende lint-koder dekker dette: `missing_wikilink_materialization`, `wikilink_projection_mismatch`, `stale_wikilink_graph_edge`)
   - `I ⊆ M`
   - `links_assessed_at` er satt
5. Neo4j: projeksjonsjobben er sendt i kø. Fokus-API-et rapporterer selv om projeksjon er utilgjengelig, og verifiseringen krever ikke at Neo4j kjører.

Resultatet lagres i `qa_result.checks.link_integration` som `{status, pages_checked, mismatches[]}`. Avvik gir `escalated` med `link_integration_mismatch`.

### 7.2 Indikator: «Kunnskapskobling» [Forslag]

Indikatoren vises per kjøring og per side i resultatpanelet:

| Indikator | Regel | Betydning |
|---|---|---|
| **Teknisk kontrollert** | Kontrollen er `ok` og alle genererte sider har en vurdering | Arbeidet er utført, og lagrede relasjoner stemmer med teksten |
| **Ingen relevante koblinger** | Som over, og alle sider har `no_relevant_links` eller `no_candidates` | Vurdert. Ingen faglig grunnlag for koblinger ble funnet. |
| **Krever oppfølging** | `rejected_only` på en side, avvik i kontrollen, eller sider som feilet | Det er noe å se på |
| **Ikke fullført** | En side mangler vurdering, eller kjøringen ble avbrutt eller feilet før steget | Arbeidet er ikke utført |

Ordet **«Verifisert» brukes ikke**. «Teknisk kontrollert» sier bare at lagret struktur stemmer. Faglig riktighet ligger i eksisterende **review av sideversjon**: kontrolløren godkjenner versjonen med lenkene i. Resultatpanelet sier dette eksplisitt. Den eksisterende `wiki.review_links` (AI-lenke-QA) merkes «AI-vurdert» der den vises, aldri «verifisert».

### 7.3 Forhold til eksisterende Wiki-QA

- Ingen nye blokkerende regler. Bare `escalated` legges til ved teknisk ufullstendighet.
- Claims-flyten (verifisering og QA-tildeling) er uendret.
- Lenker er navigasjonssignaler, ikke bevis (AGENTS.md). Verifiseringen sier derfor ingenting om påstander.

---

## 8. Resultatdokumentasjon og datagrunnlag

### 8.1 Hva finnes i dag [Finnes]

| Behov | Kilde i dag | Historisk korrekt? |
|---|---|---|
| Dokument | `run.source_id` → `EnterpriseWikiDocument` | Ja, men filnavn kan endres eller dokumentet slettes |
| Nye eller oppdaterte sider | Pivot `action` og `generated_page_version_id` | Ja, versjonen er uforanderlig |
| Feilede sider | Pivot `generation_status` og `generation_error` | Ja |
| Nye lenker fra kjøringens sider | Differansen mellom wikilinks i `generated_page_version` og forrige versjon | Ja, begge er uforanderlige |
| Begrunnelse | `content_blocks_json.link_intents.reason` i den produserte versjonen | Ja, for lenker fra generering. Relink og reparasjon har i dag ingen begrunnelse. |
| Inngående lenker | `enterprise_wiki_page_relink_attempts.created_page_version_id` | Ja |
| Reparasjoner | `enterprise_wiki_page_link_qa_attempts` | Ja |
| QA og lint | `qa_result`, `enterprise_wiki_lint_findings.enterprise_wiki_ingest_run_id` | Ja |

Konklusjon: nesten alt kan **beregnes** fra uforanderlige data. Det som mangler er titler som de var da kjøringen ble fullført, og et lagret sammendrag som varselet og listen kan lese billig.

### 8.2 Minste datamodellendring [Forslag]

Én migrasjon. Ingen nye tabeller.

| Tabell | Kolonne | Formål |
|---|---|---|
| `enterprise_wiki_ingest_runs` | `initiated_by_user_id` (nullable FK `users`, null on delete) | Mottaker av varsel. Settes i `EnterpriseWikiDocumentFlowService::startForDocument()` fra `WikiSourceController::ingest`. |
| `enterprise_wiki_ingest_runs` | `result_snapshot` (json, nullable) | Lagret resultatbilde, skrevet én gang ved terminal overgang |
| `enterprise_wiki_ingest_run_pages` | `link_assessment` (json, nullable) og `links_assessed_at` (timestamp, nullable) | Vurderingsjournalen (§6.3) |

`result_snapshot` bygges av en ny `EnterpriseWikiRunResultSnapshotService`, utelukkende fra persistente data i §8.1:

```json
{
  "schema": 1,
  "captured_at": "...",
  "outcome": "completed|needs_followup|failed|cancelled",
  "documents": [{"id": 50, "filename": "..."}],
  "pages": [{"page_id": 1, "title": "...", "page_type": "article", "action": "created", "version_id": 9, "generation": "completed", "link_assessment": "linked"}],
  "links": [{"from_page_id": 1, "from_title": "...", "to_page_id": 2, "to_title": "...", "kind": "new_to_existing|within_run|inbound", "origin": "generation|inbound_relink|link_repair|article_summary", "reason": "...|null"}],
  "counts": {"pages_created": 4, "pages_updated": 3, "pages_failed": 0, "links_new": 7, "links_inbound": 2, "pages_without_links": 1},
  "link_integration": "checked|no_relevant_links|needs_followup|not_completed"
}
```

- `reason` hentes bare fra lagret intensjon. Mangler den, er verdien `null`, og UI viser «Ingen begrunnelse registrert». Begrunnelser konstrueres aldri i ettertid.
- Ved gjenopptak skrives et nytt øyeblikksbilde over det gamle. Varselet beholder sin egen tekst, slik at historikken i bjella er uendret.

### 8.4 Telleregler (invariant D, tillegg)

**Grunnregel:** et tall i varselet eller i resultatet skal aldri være større enn det databasen viser da kjøringen ble terminal. Tallene **beregnes på nytt fra tilstand** hver gang øyeblikksbildet bygges. De akkumuleres aldri underveis. Retry, dupliserte køhendelser og gjenopptak kan derfor ikke gi dobbelttelling.

**Definisjoner:**
- **Sluttversjon** for en side i kjøringen er sidens gjeldende versjon i øyeblikket overgangen skjer.
- **Utgangsversjon** er siste versjon av siden med `created_at < run.started_at`. Den finnes ikke for nye sider.

| Tall | Telleregel |
|---|---|
| Nye artikler | Pivoter med `action=created` og `generation_status=completed` |
| Oppdaterte artikler | Pivoter med `action ∈ {updated, patched}` og ferdig versjon |
| Feilede artikler | Pivoter med `generation_status=failed`, eller sider uten `currentVersion` |
| Nye materialiserte lenker | Unike par `(from_page_id, to_page_id)`, der `from` er en side i kjøringen, som (a) finnes i sluttversjonens lenkesett og (b) ikke i utgangsversjonens, og (c) har en `wikilink`-rad. Tre-kravet gjør at bare lenker som faktisk er materialisert, telles. |
| … fra nye til eksisterende | Delmengde der `to` ikke er en side i kjøringen |
| … innad i kjøringen | Delmengde der `to` er en side i kjøringen |
| Inngående lenker | Unike par fra `relink_attempts` med `created_page_version_id` og `status = applied` (`EnterpriseWikiPageRelinkAttempt::STATUS_APPLIED`), der paret ikke fantes i kandidatens forrige versjon og har rad |
| Artikler uten relasjoner | Sider i kjøringen med ferdig versjon og null utgående lenker i sluttversjonen, fordelt på vurderingsutfall |
| Avviste forslag | Summen av `link_assessment.rejected`. Vises separat og telles **aldri** som relasjoner. |
| Ufullstendige eller feilede relasjonssteg | Sider i kjøringen der `links_assessed_at IS NULL`, eller med avvik i lenkekontrollen |

Ytterligere regler:
- **Article↔summary.** Den mekaniske lenken fra `appendMutualLinkIfPaired`, og de strukturelle radene fra `buildArticleSummaryLinks`, er ikke relasjonsarbeid. De vises med opphavet «article_summary», men **holdes utenfor overskriftstallet «nye relasjoner»** i varselet.
- **Lenker til skall** (§5) telles ikke som materialiserte, fordi grafen skjuler dem. De vises som «venter på side».
- **Én rad per par.** Unikheten `ewpl_from_to_type_unique` gjør at to lenker fra samme side til samme mål er én relasjon.
- **Samtidige manuelle endringer** av en side mens kjøringen går, kan i sjeldne tilfeller gi en lenke i sluttversjonen som kjøringen ikke laget. Regelen teller den bare hvis sluttversjonen er skrevet av kjøringen: `generated_by_model` er en Wiki-pipeline-modell, eller versjonen er pivotens `generated_page_version_id`. Ellers merkes siden «endret manuelt under kjøringen» og telles ikke.

**Antall lenker er ikke et kvalitetsmål.** Ingen akseptansekriterier, terskler eller indikatorer er basert på antall. Tallene er beskrivende.

**Historiske kjøringer:** ingen backfill. Panelet viser «Resultatrapport finnes ikke for kjøringer før {dato}», med lenker til eksisterende «Sider» og «Funn» (beslutning D9).

### 8.3 Sikkerhet

- **Nytt endepunkt:** `GET /app/wiki/runs/{run}/result`. Autorisasjonen er den samme som for `runPages`: `WIKI_VIEW`, `abort_unless($run->customer_id === $customerId, 404)` og `source_type`.
- **Dagens tilstand legges oppå øyeblikksbildet ved lesing:** sider som er slettet, arkivert eller ikke synlige gis uten lenke, og vises med «finnes ikke lenger». Den historiske tittelen beholdes, fordi den alt var synlig for kunden da kjøringen gikk.
- **Ingen prompter, tokens, rå `error_message`, unntaksklasser eller loggdata eksponeres.**
  - Feilårsaker oversettes til brukertekst.
  - Tekniske detaljer vises bare der «Funn» viser dem i dag: `isSystemOwner() || canApproveWikiClaims()`.
- **Dokumentnavn følger eksisterende regel** for Kildedokumenter-fanen. Fil-lenken bruker eksisterende rute med egen tilgangskontroll.

---

## 9. Varsler

### 9.1 Ett sentralt punkt for terminal overgang [Forslag]

Status settes terminalt i dag fra disse stedene:
- `completeRun`, `escalateRun` og `markRunFailed` i `EnterpriseWikiDocumentFlowService`
- den private `markRunFailed` og `failed()` i `FinalizeEnterpriseWikiPageGeneration`
- `failed()` i `RunEnterpriseWikiDocumentFlow` og `ContinueEnterpriseWikiDocumentFlowAfterPages`
- `cancelRun`
- legacy-stien `FinalizeEnterpriseWikiIngest` og `ProcessEnterpriseWikiIngest`

Alle bruker query builder (`->nonTerminal()->update(...)`). Eloquent-observere fyrer derfor aldri.

**Forslag:** ny `EnterpriseWikiRunTerminalTransition::apply(int $runId, string $status, array $attributes): bool`.

1. Den kjører `nonTerminal()->update(...)`. **Bare kalleren som faktisk endrer raden (`affected > 0`) går videre.** Dette er beskyttelsen mot dupliserte køhendelser og samtidige jobber.
2. Den bygger `result_snapshot` i samme transaksjon.
3. Den kaller `EnterpriseWikiRunNotificationService::announce($run)`. Writeren utsetter selv innsettingen til etter commit.

Alle de nevnte stedene ruter gjennom denne. En arkitekturtest, i samme stil som `QueueTopologyContractTest`, sikrer at ingen kode utenfor tjenesten skriver terminal `status` til `enterprise_wiki_ingest_runs`.

### 9.2 Når og hva

| Status | Varsel (`event_type`) | Tittel (no) | Severity |
|---|---|---|---|
| `completed` | `wiki.run_completed` | Wiki-oppdatering fullført | info |
| `escalated` | `wiki.run_needs_followup` | Wiki-oppdatering krever oppfølging | warning |
| `failed` | `wiki.run_failed` | Wiki-oppdatering mislyktes | critical |
| `cancelled` | ingen (forslag) | – | – |

- **Ikke for tidlig:** `completed` settes bare etter QA og eier-avstemming. Øyeblikksbildet bygges før overgangen.
- **Melding:** tall fra `result_snapshot.counts`, aldri fra AI-tekst. Eksempel: «4 nye artikler · 3 oppdaterte artikler · 7 nye relasjoner». Er `link_integration ≠ checked`, legges det til: «Relasjonsarbeidet er ikke fullført».
- **Duplikatbeskyttelse:** `dedupe_key = wiki.run_finished:{run_id}:{finished_at_unix}:{user_id}`.
  - Retry av samme overgang gir samme nøkkel og ingen ny rad.
  - En senere legitim gjenopptakelse, for eksempel escalated → completed, setter ny `finished_at` og gir ett nytt sluttvarsel. Det er riktig, fordi utfallet faktisk har endret seg.
- **Mottaker** (forslag, beslutning D2):
  1. `initiated_by_user_id`
  2. hvis tom (planlagt kjøring, `source_change`, eldre kjøringer): dokumentets `owner_user_id`
  3. deretter `uploaded_by_user_id`

  Én mottaker per kjøring. Writeren avviser allerede inaktive brukere og brukere fra andre kunder, og `UserNotificationAccessScope` skjuler varselet uten `wiki.view`. Aktør er `null`, fordi det er en systemhendelse, slik at initiativtakeren faktisk får varselet.

  **Valg av mottaker (tillegg).** Mottakeren velges når kjøringen blir terminal, ikke når den starter. Kandidatene prøves i rekkefølge, og den **første som oppfyller alle kravene** blir mottaker:
  1. Brukeren finnes. FK-ene er `null on delete`, så slettede brukere faller bort av seg selv.
  2. `is_active`.
  3. `customer_id` er lik kjøringens. En bruker som har forlatt kunden, faller dermed bort.
  4. Kunden har Wiki-modulen.
  5. `CustomerPermissionService::has($user, CustomerPermissionCatalog::WIKI_VIEW)` er sann.

  Krav 4 og 5 sjekkes *før* skrivingen, slik at vi ikke skriver et varsel som tilgangsfilteret skjuler for alltid, mens neste kandidat kunne lest det.

  Finnes ingen gyldig mottaker, sendes ingen varsel. Det logges, og `result_snapshot.notification = {recipient_user_id: null, reason: "no_eligible_recipient"}` registreres. Resultatet er fortsatt tilgjengelig i Kjøringer for alle med `wiki.view`.

  **Autorisasjonen kontrolleres alltid på nytt ved åpning.** Varselet gir aldri tilgang i seg selv:
  - `UserNotificationAccessScope` skjuler varselet hvis tilgangen senere er fjernet.
  - Endepunktet `runs/{run}/result` sjekker `WIKI_VIEW` og kunde (404 ved annen kunde).
  - Lenkene til sider følger sidenes egne rutevakter.
- **Språk:** nøklene `procynia.wiki.run_notifications.{completed,needs_followup,failed}.{title,message}` i `lang/no` og `lang/en`. De løses med `CustomerContext::resolveLanguageCode($recipient)`, etter mønsteret i `AssignmentNotifier`.
- **Lenke:** `route('app.wiki.index', ['tab' => 'runs', 'focus_run' => $run->id, 'panel' => 'result'], false)`. Lenken er relativ og bruker eksisterende `focus_run`. `panel=result` er nytt og åpner resultatpanelet.
- **Etter at varselet er lest:** resultatet ligger på kjøringsraden og er uavhengig av varselet.

### 9.3 Pålitelig levering av sluttvarselet (tillegg, sluttkontroll av A)

**Slik virker A1 i dag (verifisert i kode):**
- Statusen skrives med én betinget `UPDATE … WHERE status NOT IN (terminal)`. Ved samtidige forsøk venter Postgres på radlåsen og vurderer `WHERE` på nytt, så nøyaktig én kaller får `affected = 1`. Overgangen er robust mot samtidige jobber og dupliserte køhendelser.
- `apply()` åpner ingen egen transaksjon. `afterTerminal()` kalles rett etter `UPDATE`:
  - inne i kallerens transaksjon for `cancelRun`, sidegenereringsfinalizeren og legacy-finalizeren
  - ellers etter at statusen allerede er committet: flow-tjenestens `completeRun`/`escalateRun`/`markRunFailed` og `failed()`-håndtererne
- Kroken kalles høyst én gang og aldri på nytt. Et senere forsøk ser en terminal rad og returnerer `false`.

**Kan sluttvarselet gå tapt? Ja.** Stopper prosessen etter at statusen er committet, men før `afterTerminal()` eller en `DB::afterCommit()`-callback har kjørt, kommer varselet aldri. Det samme gjelder hvis selve skrivingen feiler: `UserNotificationWriter` utsetter innsettingen til `DB::afterCommit()` og svelger feil med `rescue()`. §9.1 punkt 3 er derfor ikke tilstrekkelig alene.

Dette er ingen feil i statusbehandlingen i A, fordi kroken er tom. A er ikke endret for dette; bare kommentaren til kroken er rettet slik at den ikke lenger anbefaler `DB::afterCommit()` som eneste mekanisme.

**Anbefaling for C. Ingen ny infrastruktur:**
1. **En liten varselintensjon i samme transaksjon som statusen.**
   - `apply()` kjører `UPDATE` og kroken i én `DB::transaction`. Det blir et savepoint når kalleren allerede har en transaksjon.
   - Kroken velger mottaker etter §9.2 og lagrer mottakeren, `dedupe_key` og `delivered_at: null` i `result_snapshot.notification`.
   - Dette er bare lesing og skriving i databasen, uten AI eller nettverk.
   - Kroken må ikke kaste. En feil inne i kallerens transaksjon ruller tilbake overgangen, og kjøringen kan bli hengende. Tunge deler av øyeblikksbildet bygges derfor ikke i kroken.
2. **Idempotent levering som kan forsøkes på nytt.**
   - Etter commit skrives varselet med eksisterende `UserNotificationWriter` og `dedupe_key` (§9.2).
   - Den unike indeksen på `user_notifications.dedupe_key` gjør nye forsøk ufarlige.
   - `delivered_at` settes først når varselraden faktisk finnes.
3. **Nye forsøk gjennom en eksisterende planlagt kommando.**
   - `wiki:maintenance-cycle` kjører hvert 30. minutt. Den får et steg som leverer på nytt for terminale kjøringer der `notification.delivered_at` er tom og det finnes en mottaker.
   - Mottakeren hentes fra intensjonen og velges ikke på nytt, så nøkkelen er stabil.
   - Kjøringer fra før C har ingen intensjon og berøres ikke (D9).
4. **Ikke anbefalt:**
   - En egen outbox-tabell. Repoet har ingen, og kjøringsraden er allerede den varige kilden.
   - Bare `DB::afterCommit()` eller en jobb som sendes etter commit. Begge har det samme tapsvinduet, og køene har `after_commit=false`.

**Tester for C (tillegg):**
- Levering som feiler etter commit: neste kjøring av `wiki:maintenance-cycle` leverer nøyaktig ett varsel.
- Nye kjøringer av `wiki:maintenance-cycle`: ingen nye rader.
- Kroken kaster ikke inne i `cancelRun`s transaksjon.

---

## 10. Enkel resultatvisning [Forslag]

Visningen er et **tredje panel, «Resultat»**, på eksisterende `RunListItem` i `resources/js/Pages/App/Wiki/Index.jsx`. Det bruker samme lazy-mønster som «Sider» og «Funn». Det trengs ingen ny side og ingen dashboardmodul.

1. **Topplinje:** utfall, indikator for kunnskapskobling (§7.2), dokument og tidspunkt.
2. **Behandlet innhold:** nye, oppdaterte og feilede artikler, med lenker. Feilede artikler vises med brukerforståelig årsak.
3. **Kunnskapsintegrasjon:** tellinger fra øyeblikksbildet, delt i:
   - fra nye til eksisterende sider
   - innad i kjøringen
   - inngående
   - sider uten relasjoner, med vurderingsutfall per side
4. **Konkrete koblinger:** «Leverandørkontroll → Leverandøroppfølging», med begrunnelse når den er lagret. Det er en lenke «Vis i graf» til den eksisterende `/app/wiki/graph?run_id=`, som ikke var lenket fra noe sted.
5. **Historikk og nå:** en tekstlinje forklarer at resultatet beskriver kjøringen da den ble fullført. Sider som er endret siden, er merket «endret etter kjøringen» (gjeldende versjon er ikke lik `version_id`). Slettede sider er merket «finnes ikke lenger».

PageHelp for Wiki-siden utvides etter standarden: «Slik gjør du det» med punktene «Åpne Kjøringer → Resultat» og «Prøv igjen for feilede sider». Alle strenger er i18n med norsk fallback.

---

## 11. Teststrategi

### 11.1 Ny ende-til-ende-integrasjonstest (deterministisk) [Forslag]

Testen heter `tests/Feature/App/Wiki/EnterpriseWikiKnowledgeIntegrationEndToEndTest.php` og bruker RefreshDatabase, slik at `afterCommit` fyrer.

- **Mock-nivå:** `OpenAiClient` mockes for `wiki.generate_page`, ikke `WikiPageContentAiClient`. Da kjøres ekte schema-bygging (katalog-enum), ekte parsing og ekte materializer.
- **Maintainer decision** mockes på tjeneste-nivå med en fast beslutning, etter eksisterende mønster.
- **Jobbene** kjøres med `Queue::fake()` og manuell `handle()`, som i `EnterpriseWikiWikilinkEndToEndIntegrationTest`.

| # | Steg | Påstand |
|---|---|---|
| 1 | Kunde A med eksisterende sider *Leverandørstyring* og *Sikkerhetskrav*. Kunde B har en side med samme slug. | – |
| 2 | Ny kjøring for dokumentet *Leverandørkontroll* | Katalogen for den nye artikkelen inneholder begge eksisterende A-sider (lag 1 via `related_page_guidance.page_id`), og ingen B-side |
| 3 | Mock-svaret har `link_intents` mot begge, pluss ett mål som er arkivert | Arkivert mål er avvist og registrert i `link_assessment.rejected`, og siden **feiler ikke** |
| 4 | Materialisering | `[[leverandorstyring\|…]]` står i markdown. Intensjonene ligger i `content_blocks_json`. |
| 5 | Rader | `enterprise_wiki_page_links` har riktige `from`/`to`, `customer_id = A` og `link_type=wikilink` |
| 6 | Graf | `GET /app/wiki/graph-data?run_id=` returnerer nøyaktig de forventede kantene med `intents[].reason`, og `ProjectEnterpriseWikiPageToGraph` er sendt i kø |
| 7 | Inngående | *Leverandørstyring* får en ny versjon der den eneste forskjellen er lenkemarkupen (invarianten). Raden finnes. |
| 8 | Samsvar | `checkLinkIntegration` gir `ok`, og radsettet er likt markdown-settet |
| 9 | Idempotens | Continuation og materialisering kjøres på nytt: ingen nye rader, versjoner eller relink-forsøk |
| 10 | Øyeblikksbilde og varsel | `result_snapshot.counts` stemmer. Nøyaktig ett `wiki.run_completed` til initiativtakeren. Dobbel kjøring av overgangen gir fortsatt ett. |

En egen test for **delvis feil**: to sider, der den ene kaster `EnterpriseWikiPlannedSectionEvidenceMissingException`.
- Den andre får rader og claims.
- Kjøringen ender `escalated`, aldri `completed`.
- Varselet er `wiki.run_needs_followup`.
- Grafen viser ikke skallet og ikke kanten til det.
- Retry-tjenesten nekter fordi feilen er deterministisk.
- En variant med forbigående feil (timeout) skal gi vellykket retry og deretter `completed`, med to varsler med ulik `finished_at`.

**Andre tester:**
- `EnterpriseWikiRunTerminalTransitionTest`: samtidige kall gir én overgang og ett varsel.
- Arkitekturtesten fra §9.1.
- Tilgang til resultat-endepunktet: annen kunde gir 404, og uten `wiki.view` gir 403.
- Kontrakt- og invariant-tester for relink v2.

Etter kjent baseline kjøres bare målrettede tester, ikke hele Wiki-suiten (CLAUDE.md, testregime).

### 11.2 Kontrollert test med ekte AI [Forslag – kjøres ikke uten godkjenning]

- **Forutsetninger:**
  - En egen testkunde, «Wiki-evaluering (intern)», med Wiki-modul og én System Owner.
  - Ny operatørkommando `wiki:evaluate-knowledge-integration --customer=<id> [--dry-run]`, som bruker eksisterende `RunsOperatorAiCommand`.
  - Kommandoen nekter å kjøre mot en kunde som ikke er merket som evalueringskunde. Merkingen er en konfig-liste over kunde-ID-er i `config/procynia.php`, ikke en datamodellendring.
  - Køarbeiderne må restartes før kjøringen, slik at ny kode er lastet. Det gjøres bare etter godkjenning.
- **Testdata:** tre syntetiske `.docx` under `tests/Fixtures/wiki-eval/`. Det brukes ingen kundedata.
  1. *Leverandørstyring – policy*
  2. *Informasjonssikkerhet – sikkerhetskrav til leverandører*
  3. *Leverandørkontroll – prosedyre*. Dette dokumentet refererer faglig til begge de andre, men bruker delvis andre begreper, slik at vi tester semantikk og ikke leksikalsk treff.
- **Gjennomføring:** dokument 1 og 2 ingestes og fullføres. Deretter ingestes dokument 3.
- **Forventet resultat (gullstandard i fixture-JSON):**
  - Påkrevde koblinger: leverandørkontroll → leverandørstyring, og leverandørkontroll → sikkerhetskrav.
  - Ønsket inngående kobling: leverandørstyring → leverandørkontroll.
  - Forbudte koblinger: koblinger til de forsettlig urelaterte sidene i dokument 1, som har et eget avsnitt om reiseregninger.
- **Evaluering:** kommandoen skriver en rapport med:
  - presisjon og dekning mot gullstandarden
  - vurderingsutfall per side
  - tokens og varighet per fase, fra `ai_usage_attempts` og kjøringens tidsstempler
  - kostnad (`cost_estimate_nok`)

  Bestått betyr alle påkrevde koblinger og ingen forbudte. En manuell faglig gjennomlesing av begrunnelsene inngår.
- **Kostnadsramme:** høyst 3 fulle kjøringer per evaluering, med tak satt via eksisterende AI-kapasitet for testkunden (`CustomerAiCapacityService`). Det faktiske beløpet leses fra brukshovedboken etter første kjøring og rapporteres før en eventuell ny runde.
- **Risiko:**
  - Ekte modeller er ikke-deterministiske. Resultatet er en stikkprøve, ikke en regresjonstest.
  - Kjøringen bruker produksjonsnøkkelen mot OpenAI.
  - Køarbeiderne er delt med utviklingsdata.
- **Opprydding:** eksisterende `EnterpriseWikiDocumentDeletionService` brukes per dokument hos testkunden, deretter `wiki:graph-project --customer=<id>`. Siden alt er kundeskopet, berøres ingen reelle Wiki-artikler. Testkunden kan beholdes for senere evalueringer.

---

## 12. Risiko, ytelse og AI-kostnad

| Område | Effekt | Tiltak |
|---|---|---|
| AI-kall ved generering | Uendret antall | – |
| Tokens per sidegenerering | +cirka 4 000 input (utdrag i katalogen) | Måles. Utdraget kan kortes ned. |
| Inngående relinking | Opptil 15 kall à `gpt-4.1-mini` per kjøring, mot i dag opptil 10 × `gpt-5` per concept/entity | Tak per kjøring. Kontrakten med kun blokker gir kortere svar. |
| Planleggerprompt | Uendret, men vokser lineært med Wiki-en | Meldes som egen beslutning. Målepunkt: indeksstørrelse i tokens. |
| Kjøretid | Delvis feil går nå gjennom hele flyten i stedet for å stoppe | Forventet og ønsket |
| Køer | Ingen nye køer. Recovery bruker eksisterende `enterprise-wiki` og `enterprise-wiki-pages`. | `QueueTopologyContractTest` er uendret |
| Database | Katalog lag 2 er ett indeksert oppslag i `enterprise_wiki_page_links`. Øyeblikksbildet er en spørring per kjøring ved terminal overgang. Resultatpanelet leser JSON. | `ewpl_from_to_type_unique` og eksisterende indekser |
| Graf | Utelating av skall er et filter i SQL | – |
| Refaktorering av terminale overganger | Høyest risiko for regresjon. Omtrent ti steder ruter om. | Arkitekturtest og målrettede eksisterende tester for flyten |
| Materializeren slutter å kaste ved ugyldig mål | Kan skjule modellproblemer | Avvisningen registreres og telles i resultatet og i målepunktene |

**Målepunkter.** Dette er logging pluss det som lagres i `link_assessment` og `result_snapshot`. Det trengs ingen ny telemetri-infrastruktur.

- katalogstørrelse per lag
- andel tilbudte kandidater som ble lenket
- foreslåtte, materialiserte og avviste lenker, og lenker der ankeret ikke ble funnet
- sider uten lenker, fordelt på utfall
- inngående forsøk og andelen som ble akseptert
- tokens per operasjon (`ai_usage_attempts`)
- varighet per fase
- andel kjøringer som ender `escalated` på grunn av lenker

Disse gjør det mulig å dokumentere forbedring i relasjonskvalitet, kjøretid og kostnad før og etter.

---

## 13. Gjennomføringsplan

### Arbeidspakke A – Robust generering

1. **Berørte komponenter:**
   - `FinalizeEnterpriseWikiPageGeneration`
   - `EnterpriseWikiDocumentFlowService`
   - `EnterpriseWikiPageGenerationFailureRecoveryService`
   - `EnterpriseWikiEscalatedRunRecoveryService`
   - `EnterpriseWikiPostIngestQaService`
   - `EnterpriseWikiGraphDataService`
   - `EnterpriseWikiLinkCatalogService` (filter for skall)
   - `EnterpriseWikiIndexContextService` (`has_content`)
   - `WikiController` (retry-rute, merkingen «Ufullstendig»)
   - `Index.jsx` og `Show.jsx`
2. **Kodeendringer, i implementeringsrekkefølge (tillegg).** Hvert steg kan testes og committes for seg.
   1. **Terminal overgang.** `EnterpriseWikiRunTerminalTransition::apply()` med `affected > 0`-garantien. Alle terminale skrivere ruter om til den:
      - `completeRun`, `escalateRun` og `markRunFailed` i flow-tjenesten
      - `markRunFailed` og `failed()` i finalizeren
      - `failed()` i `RunEnterpriseWikiDocumentFlow` og `ContinueEnterpriseWikiDocumentFlowAfterPages`
      - `cancelRun`
      - legacy `FinalizeEnterpriseWikiIngest` og `ProcessEnterpriseWikiIngest`

      Tjenesten har en tom utvidelseskrok (`afterTerminal(EnterpriseWikiIngestRun $run)`) som C fyller med øyeblikksbilde og varsel. Arkitekturtesten skrives i samme steg. Steget skal ikke gi noen funksjonell endring.
   2. **Finalizer og delvis feil:** §4.2 punkt 1 og D7. Dette inkluderer:
      - `failed_phase='page_generation'` og `transient_failure` når alle sidene feiler.
      - `buildArticleSummaryLinks()` hopper over par der den ene siden mangler versjon.
      - Continuation-stegene som må tåle skall i pivoten, verifisert med test: `performApplyPatchTargets`, `performCrossPageReconciliation`, materialisering, relink, claims og lint.
   3. **Publiseringsoppgjør ved regenerering:** §3.3, nytt funn, som krever eget ja. `generatePageForRun()` kaller `afterAutomatedContentChange()`.
   4. **Retry:** §4.2 punkt 3. Dette omfatter:
      - utvidet `decide()`
      - delegering fra `EnterpriseWikiEscalatedRunRecoveryService`
      - ruten `PATCH /app/wiki/runs/{run}/retry-failed-pages` med samme autorisasjon som `retryMaintainerDecision`
      - knappen «Prøv igjen for feilede sider» i `RunActivityBlock`, vist bare når `evaluate()` sier at kjøringen kan gjenopptas
   5. **Sideskall:** §5. Endringen berører graf, katalog (for andre kjøringer), indeks (`has_content`), sideliste, sidevisning og «Ufullstendig» med årsak. Alle tekster er i18n.
3. **Datamodell:** ingen. Retry bruker eksisterende felt.
4. **Risiko:**
   - Endret semantikk i finalizer.
   - Utsatt bølge kjører etter delvis feil i første bølge.
   - Omrutingen av terminale overganger. Dette er den største risikoen, og den reduseres ved at steg 1 ikke endrer oppførsel og har egen test.
   - At steg 3 endrer review-tilstand for sider som er publisert og regenereres.
5. **Testplan:**
   - Delvis feil, deterministisk og forbigående.
   - Alle sider feilet gir fortsatt `failed` med `failed_phase`.
   - Idempotent retry.
   - Skall i graf, katalog og sideliste.
   - Arkitekturtester: én for terminal status (§9.1) og én for publiseringsinvarianten (§3.3).
   - Eksisterende `FinalizeEnterpriseWikiPageGenerationTest`, `GenerateEnterpriseWikiAppliedPageJobTest`, `EnterpriseWikiDocumentFlowServiceTest`, `EnterpriseWikiPageGenerationFailureRecoveryServiceTest`, `EnterpriseWikiPostIngestQaGatingTest` og `WikiGraphDataControllerTest` oppdateres der semantikken endres med vilje.

   **Negative tester (tillegg):**
   - Alle sider i første bølge feiler. Resultatet er `failed`, ingen continuation, ingen utsatt bølge, og `failed_phase=page_generation`.
   - Retry på deterministisk feil gir `notRecoverable`. Ingen jobb sendes, og status er uendret.
   - Retry på en kjøring som allerede er i gang, eller samtidige retry-kall, gir nøyaktig én ny bølge (`already_running` for den andre).
   - Retry på en kjøring fra en annen kunde gir 404. Bruker uten `WIKI_SOURCE_MANAGE` får 403.
   - Retry på en `cancelled` kjøring gir `alreadyComplete`, og ingenting skjer.
   - Finalizeren kjøres dobbelt for samme bølge. Resultatet er én overgang og én dispatch av continuation.
   - En lenke til et skall gir ingen kant i `graph-data`, verken kunde-, kjørings- eller nabolagsskopet.
   - Et skall fra en eldre feilet kjøring forekommer ikke i katalogen for en ny kjøring.
   - En publisert side i en delvis feilet kjøring beholder `published_version_id`.
6. **Akseptansekriterier:**
   - En kjøring med minst én vellykket side gir aldri vellykkede sider uten materialiserte rader.
   - En delvis feilet kjøring blir aldri `completed`.
   - Skall vises ikke som ferdige artikler i graf eller katalog, og vises som «Ufullstendig» i liste og visning.
   - Retry av forbigående feil regenererer bare feilede sider.
   - Ingen sider slettes.
   - **(tillegg)** Ingen side publiseres eller endrer `published_version_id` som følge av A. Se kriteriene i §3.3.
   - **(tillegg)** Alle terminale overganger går gjennom `EnterpriseWikiRunTerminalTransition`. Det håndheves av test.
   - **(tillegg)** Kjøring 26-mønsteret (artikkel feiler deterministisk, summary lykkes) gjenskapt i test gir:
     - status `escalated` og `qa_result.incomplete_steps ∋ page_generation_incomplete`
     - wikilink-rader for summary
     - ingen kant til artikkel-skallet
     - retry som nekter
7. **Avhengigheter:** ingen.
8. **Beslutninger:** D1, D3, D7, pluss eget ja til steg 3 (§3.3).

### Arbeidspakke B – Kunnskapskobling

1. **Berørte komponenter:**
   - `EnterpriseWikiMaintainerDecisionPrompt`, `EnterpriseWikiMaintainerDecisionAiClient` og `EnterpriseWikiMaintainerDecisionSplitCoordinator` (schema og prompt for `related_page_guidance.page_id`)
   - `EnterpriseWikiMaintainerDecisionConsistencyValidator`
   - `EnterpriseWikiLinkCatalogService`
   - `EnterpriseWikiLinkIntentMaterializer`
   - `EnterpriseWikiGenerateAppliedPagesService` (skriver `link_assessment`)
   - `EnterpriseWikiIncrementalRelinkService` og en ny AI-klient for `wiki.propose_inbound_links`
   - `config/ai_operations.php`
   - `EnterpriseWikiPostIngestQaService::checkLinkIntegration`
2. **Kodeendringer:** §6.1–6.4, §7.1.
3. **Datamodell:** `enterprise_wiki_ingest_run_pages.link_assessment` og `links_assessed_at`.
4. **Risiko:**
   - Prompt- og schema-endring i planleggeren.
   - Ny relink-kontrakt.
   - At materializeren slutter å kaste ved ugyldig mål.
5. **Testplan:** §11.1, pluss kontraktstester for katalogens lag, filtre, utdrag og budsjett. Ekte-AI-testen i §11.2 kjøres først etter godkjenning.
6. **Akseptansekriterier:**
   - Katalogen inneholder sider planleggeren pekte på, også når de er eldre enn de 50 sist endrede.
   - Alle fem vurderingsutfall er representert og deterministisk avledet.
   - Testen i §11.1 er grønn fra intensjon til graf.
   - Inngående lenker endrer bare lenkemarkup.
   - Ingen leksikalsk hard gate og ingen embeddings.
   - **(tillegg)** Hver generert side har `links_assessed_at` og et deterministisk `outcome` (§6.3). En kjøring der en side mangler vurdering, blir aldri `completed`.
   - **(tillegg)** UI, resultat og dokumentasjon sier «Teknisk kontrollert», aldri «Verifisert». Faglig godkjenning skjer bare via eksisterende versjonsreview.

   **Negative tester (tillegg):**
   - **Ugyldige mål fra AI:** mål fra annen kunde, slettet side, arkivert eller erstattet side, skall fra annen kjøring, selvlenke, og ID utenfor katalogen. Hvert tilfelle avvises og registreres i `link_assessment.rejected` med riktig `outcome`. Siden feiler ikke, og det blir ingen rad.
   - **Kontraktsbrudd:** ugyldig `intent_id`, `{{wiki_link:`-markør og tomt anker kaster fortsatt.
   - **Ankeret finnes ikke** i blokkteksten. Lenken registreres som `skipped_anchor_not_found`, uten rad og uten telling.
   - **Ugyldig `page_id` i `related_page_guidance`** (annen kunde, arkivert, selv, duplikat). ID-en forkastes og fritekstveiledningen beholdes. Beslutningen feiler ikke, og siden kommer ikke i katalogens lag 1.
   - **Tom katalog.** Utfallet er `no_candidates`, ikke `no_relevant_links`.
   - **Planleggeren pekte på en side som er eldre enn de 50 sist endrede.** Siden er med i katalogen.
   - **Inngående relink der AI-svaret endrer et tegn utenfor ankeret.** Forsøket avvises med `invalid_revision`, og ingen versjon skrives.
   - **Inngående relink mot en side i `pending_review`.** Ingen forsøk og ingen versjon.
   - **Gjentatt kjøring av continuation og QA.** Ingen nye rader, versjoner, relink-forsøk eller endring i `link_assessment`.
   - **Rad mangler for en lenke i markdown**, simulert ved å slette raden før QA. Lenkekontrollen gir avvik og kjøringen `escalated`.
   - **Graf-API for annen kunde.** Ingen av kantene er synlige.
7. **Avhengigheter:** A (skall-filter og fullføringsmodell).
8. **Beslutninger:** D4, D5, D6, D8.

### Arbeidspakke C – Varsler og resultatvisning

1. **Berørte komponenter:**
   - `EnterpriseWikiRunTerminalTransition` (fra A)
   - ny `EnterpriseWikiRunNotificationService` (bruker `UserNotificationWriter`)
   - ny `EnterpriseWikiRunResultSnapshotService`
   - `EnterpriseWikiDocumentFlowService::startForDocument` og `WikiSourceController::ingest` (initiativtaker)
   - `WikiController` (nytt endepunkt `runs/{run}/result` og `panel=result`)
   - `Index.jsx` (panelet «Resultat»)
   - lang-filer og `HandleInertiaRequests` (husk at `customer_env` er deklarert to ganger)
   - PageHelp
2. **Kodeendringer:** §8.2, §9, §10. Levering av sluttvarselet etter §9.3, med nye forsøk i `EnterpriseWikiMaintenanceCycleService`.
3. **Datamodell:** `enterprise_wiki_ingest_runs.initiated_by_user_id` og `result_snapshot`. Migrasjonen kan reverseres.
4. **Risiko:**
   - Mottaker mangler for planlagte kjøringer (fallback-kjeden i §9.2).
   - Øyeblikksbildet må bygges før overgangen uten å forsinke den merkbart.
5. **Testplan:**
   - Ett varsel per overgang, også ved samtidige kall.
   - Riktig mottaker og fallback.
   - Annen kunde ser ingenting.
   - Uten `wiki.view` er varselet skjult.
   - Lenken åpner riktig rad og panel.
   - Øyeblikksbildet etter at en side er slettet eller endret skiller mellom historikk og nå.
   - E2E: varselet åpner «Resultat» (egen fixture-kunde, jf. testhygiene).
6. **Akseptansekriterier:** kriteriene 7–12 i oppdraget (§18). Konkretisert (tillegg):
   - Varselet «Wiki-oppdatering fullført» sendes bare ved `status=completed`. Alle tall i meldingen er like `result_snapshot.counts` for samme kjøring, beregnet etter §8.4.
   - «Se resultat» åpner `?tab=runs&focus_run={id}&panel=result`. Panelet viser:
     - de samme tallene som varselet
     - konkrete koblinger med reelle titler, der hver kobling har en `wikilink`-rad ved øyeblikksbildet
     - begrunnelse bare når den er lagret
   - Etter at en side er redigert eller slettet, viser panelet fortsatt de historiske tallene og titlene, merket «endret etter kjøringen» eller «finnes ikke lenger». Varselteksten er uendret.

   **Negative tester (tillegg):**
   - **Samme terminale overgang to ganger** (dupliserte køhendelser og samtidige jobber): ett varsel.
   - **Gjenopptak escalated → completed:** to varsler med ulik `finished_at`. Ingen tredje ved retry av den siste overgangen.
   - **Initiativtaker inaktiv, flyttet til annen kunde, slettet eller uten `wiki.view`:** neste i kjeden blir mottaker. Ingen gyldige mottakere gir ingen varsel og `notification.reason` i øyeblikksbildet.
   - **`cancelled`:** ingen varsel (D10).
   - **`failed`:** tittelen «Wiki-oppdatering mislyktes», aldri «fullført».
   - **Resultat-endepunktet:** annen kunde gir 404, uten `WIKI_VIEW` gir 403, historisk kjøring uten øyeblikksbilde gir tekst om at rapport mangler, ikke tall.
   - **Øyeblikksbildet etter retry:** ingen dobbelttelling av lenker eller sider.
   - **Lenken i varselet** åpner bare resultatet hvis brukeren fortsatt har tilgang. Etter tap av `wiki.view` er varselet skjult.
7. **Avhengigheter:** A (overgangspunktet) og B (vurderingsdata). C kan startes parallelt med B med tellinger uten `link_integration` hvis det ønskes.

   **Presise avhengigheter (tillegg).**

   Fra A:
   - `EnterpriseWikiRunTerminalTransition` med utvidelseskroken. Uten denne finnes det ikke ett sted å sende ett varsel fra.
   - Delvis fullføring som gir `escalated`. Uten den finnes ikke utfallet «Krever oppfølging».
   - Skall-filteret. Uten det teller resultatet kanter til skall.

   Fra B, før C kan rapportere *kunnskapsintegrasjon* pålitelig:
   1. `link_assessment` og `links_assessed_at` på pivoten. Det er grunnlaget for «artikler uten relasjoner» fordelt på utfall, og for «avviste forslag».
   2. `checkLinkIntegration` i QA. Det er grunnlaget for indikatoren og `link_integration`.
   3. `reason` på inngående lenker gjennom relink v2. Uten det viser inngående lenker «Ingen begrunnelse registrert», som er korrekt, men gir mindre verdi.

   Det som **kan** leveres i C uten B:
   - nye, oppdaterte og feilede artikler
   - nye materialiserte lenker etter §8.4, som bare trenger versjoner og rader
   - varsel og mottaker

   Indikatoren vises da som «Ikke tilgjengelig for denne kjøringen», ikke som «Teknisk kontrollert».
8. **Beslutninger:** D2, D9, D10.

Rekkefølge: **A → B → C**, som anbefalt i oppdraget.

---

## 14. Arkitektursjekk (`docs/enterprise-wiki-architecture.md` §J)

1. **Wiki som autoritativ kilde:** ja. Ingen bypass via råchunks.
2. **AI kan fortsatt orientere seg:** ja, og bedre. Planleggerens indekslesing utnyttes nå også ved lenking.
3. **Ny hard gate:** nei. Lag 3 (aktualitet) beholdes som sikkerhetsnett, og ingen leksikalsk filtrering.
4. **Q&A og tilbudsarbeid:** uendret. Retrieval-kjernen berøres ikke.
5. **Avgrenset:** ja. Faste tak på katalog, naboer, relink og retry. Ingen rekursjon.
6. **Grounding:** uendret. Lenker er fortsatt bare navigasjon.
7. **Generisk:** ja. Ingen domene- eller synonymregler.
8. **Kompilering inn i Wiki-en:** ja. Kryssreferanser kompileres ved ingest.
9. **Krever eksplisitt beslutning:** prompt- og schema-endringen (D5) og relink-kontrakten (D8).
10. **Brudd på invarianter:** ingen identifisert.

---

## 15. Åpne beslutninger

Status per 2026-10-18: **D1, D3, D7 og publiseringsrettelsen (§3.3) er godkjent for arbeidspakke A og implementert.** D2, D4–D6 og D8–D11 er akseptert som planleggingsretning, men gir ikke tillatelse til å implementere B eller C eller til å kjøre tester med ekte AI. Klarsignal gis per beslutning.

| # | Beslutning | Type | Anbefaling | Status |
|---|---|---|---|---|
| D1 | Strategi for delvis feil | Arkitektur | **B** med målrettet retry for forbigående feil (§4.2). Publiseringsinvarianten er verifisert (§3.3). | Godkjent – implementert i A |
| D2 | Mottaker av sluttvarsel | Arkitektur (datamodell) | Initiativtaker → dokumenteier → opplaster. Én mottaker. Ny kolonne `initiated_by_user_id`. Kravene til mottaker står i §9.2. | Akseptert som retning – ikke godkjent for implementering |
| D3 | Deterministiske plandefekter (som kjøring 26) | Produkt og arkitektur | Ingen automatisk retry. Vises som «Ufullstendig» med årsak. Planlegging av én side på nytt utsettes til egen sak. | Godkjent – implementert i A |
| D4 | Inngående lenke i en godkjent eller publisert side | Arkitektur (styring) | Behold eksisterende regel: ny utkastversjon til ordinær review, publisert versjon vises fortsatt. Alternativ: bare forslag i resultatet, uten ny versjon. | Akseptert som retning – ikke godkjent for implementering |
| D5 | `page_id` i `related_page_guidance` (prompt og schema) | Arkitektur (AI-kontrakt) | Ja, med backend-validering etter §6.1 | Akseptert som retning – ikke godkjent for implementering |
| D6 | Modellens begrunnelse for «ingen lenker» | Arkitektur (prinsipp) | Nei, hold vurderingen deterministisk | Akseptert som retning – ikke godkjent for implementering |
| D7 | Utsatt concept/entity-bølge når en artikkel feilet | Implementeringsdetalj med synlig effekt | Kjør bølgen | Godkjent – implementert i A |
| D8 | Relink v2 (kontrakt med kun blokker) og utløser for `article` | Arkitektur (AI-kontrakt) | Ja, i den rekkefølgen | Akseptert som retning – ikke godkjent for implementering |
| D9 | Backfill av øyeblikksbilder for historiske kjøringer og sidene 102/104/106 | Operasjonell | Ingen backfill. Egen operatørkommando for 102/104/106 vurderes etter B. | Akseptert som retning – ikke godkjent for implementering |
| D10 | Varsel ved `cancelled` | Produkt (implementeringsdetalj) | Nei. Avbrudd er en brukerhandling og vises allerede i Kjøringer. | Akseptert som retning – ikke godkjent for implementering |
| D11 | Ekte-AI-evaluering (§11.2): testkunde, budsjett, tidspunkt | Operasjonell | Godkjennes separat etter B. Inngår i strategien, men kjøring er ikke godkjent. | Akseptert som retning – ikke godkjent for implementering |

Ved klarsignal for A trengs også et eget ja til **publiseringsoppgjøret ved regenerering (§3.3)**. Det er ikke en ny D-beslutning, fordi det bare tar i bruk en eksisterende regel.

**Beslutninger som bør tas før A starter, for å unngå omarbeiding (tillegg):**
- **D1 og D7.** Disse styrer finalizeren, som er As kjerne.
- **D2, bare beslutningen om kolonnen `initiated_by_user_id`.** Hvis kolonnen innføres i A sin migrasjon i stedet for i C, får kjøringer startet mens B utvikles en initiativtaker. Den er ellers uten effekt før C. Anbefaling: behold den i C, med mindre B-perioden blir lang.
- **D4 og D8.** Disse påvirker ikke A sin kode, men bestemmer om utvidelseskroken og skall-filteret må kjenne til inngående lenker. Det gjør de ikke i forslaget, så A kan starte uten dem.

**Meldes som egne saker, utenfor omfang:**
- `RequirementWikiCatalogBuilder` sorterer etter `page_id` stigende før taket på 100.
- `WikiController::updateWorkingVersion` re-materialiserer ikke lenker.
- `EnterpriseWikiSemanticRepairService` kan etterlate rader i utakt.
- Planleggerindeksen har ikke noe tak.
- Wiki-varsler er hardkodet norsk.

---

## 16. Implementeringsstatus – arbeidspakke A (2026-10-18)

Branch `feat/wiki-robust-generation` (fra `main` `ae0673d9`). **Ikke committet.** B og C er ikke påbegynt. Test med ekte AI er ikke gjennomført.

### 16.1 Implementert

| Steg | Hva | Hvor |
|---|---|---|
| A1 | Ett sentralt punkt for terminale overganger (`complete`/`escalate`/`fail`/`cancel`). Én betinget `UPDATE … WHERE status NOT IN (terminal)` avgjør eier. Senere eller samtidige forsøk er no-op og overskriver aldri status, `error_message`, `failed_phase` eller qa-felt. `afterTerminal()` er tom utvidelseskrok for C og kalles bare for eieren. 19 terminale skrivere er rutet om: flow-tjenesten, finalizeren, `failed()` i begge flow-jobber, `cancelRun` og legacy-jobbene. | `EnterpriseWikiRunTerminalTransition` |
| A2 | Kontrollert delvis fullføring (D1) og utsatt bølge etter feil (D7). Kjøringen feiler bare når ingen side i bølgen ble generert. Da settes `failed_phase` (`generating_pages`/`generating_concept_entity_pages`) og `transient_failure`. Ellers fortsetter flyten, og post-ingest QA eskalerer (`page_generation_incomplete`). QA-årsaken navngir de feilede sidene. | `FinalizeEnterpriseWikiPageGeneration`, `EnterpriseWikiPostIngestQaService` |
| A2 | Ingen relasjon materialiseres til et skall. Det gjelder både wikilink-rader (`EnterpriseWikiLinkResolver::materializableTargets()`, felles for materialisering og lint) og strukturelle article/summary-par. Incremental relink bruker aldri en feilet side som utløser. | `EnterpriseWikiBuildPageLinksService`, `EnterpriseWikiAppliedRunLintService`, `EnterpriseWikiIncrementalRelinkService` |
| A3 | Regenerering av en publisert side kaller eksisterende `afterAutomatedContentChange()` i samme transaksjon som versjonsskrivingen. Siden går til `draft`, publisert versjon vises fortsatt, den nye versjonen kan sendes til review, og ingenting publiseres automatisk. | `EnterpriseWikiGenerateAppliedPagesService::generatePageForRun()` |
| A4 | «Prøv igjen for feilede sider»: route `PATCH /app/wiki/runs/{run}/retry-failed-pages`, samme autorisasjon som eksisterende retry. Eksisterende recovery-tjeneste er utvidet til `escalated`. Plandefekter prøves aldri (D3). Blandede bølger håndteres uten dobbel dispatch, via `reservePageForDispatch()`. Gammel QA-dom nullstilles ved gjenopptak. Kjøringsraden får `failed_pages` (antall, feiltype, `can_retry` fra tjenestens egen `evaluate()`). | `EnterpriseWikiPageGenerationFailureRecoveryService`, `WikiController::retryFailedPages()`, `Index.jsx` |
| A5 | Skall vises ikke som noder i grafen. `summary.incomplete_count` viser hvor mange som er utelatt, og en eksplisitt forespurt side vises fortsatt. Skall tilbys ikke som lenkemål: verken skall fra andre kjøringer eller feilede sider i samme kjøring. Ventende sider i samme kjøring beholdes. Publiseringsetiketten for sider uten versjon er nå «Ufullstendig», og sidevisningen forklarer årsaken (plandefekt, midlertidig, ukjent, under generering) med lenke til kjøringen. Ingenting slettes. | `EnterpriseWikiGraphDataService`, `EnterpriseWikiLinkCatalogService`, `WikiController::incompletePageExplanation()`, `Show.jsx` |

### 16.2 Avvik fra planen (bevisste)

1. **`failed_phase`.** Verdien er den faktiske fasen (`generating_pages` eller `generating_concept_entity_pages`), ikke `page_generation`. `FAILED_PHASES` tillater bare eksisterende statusverdier.
2. **Retry-regel.** §4.2 sa «bare forbigående feil». Etter oppdraget for A4 gjelder dette i stedet:
   - Plandefekter tilbys aldri.
   - Forbigående og ukjente feil kan prøves igjen *manuelt*. UI sier hvilken av de to det er.
   - Det finnes **ingen automatisk retry**. Ingen eksisterende forsøksgrense finnes som kunne begrense en vedvarende «forbigående» feil. `EnterpriseWikiEscalatedRunRecoveryService` avviser derfor fortsatt `page_generation_incomplete`. Den eneste endringen i tjenesten er QA-rettelsen i §16.5.
3. **`has_content` i Wiki-indeksen er ikke lagt til.** Indeksen brukes bare i planleggerens prompt, så et nytt felt endrer hva AI ser, og A tillater ingen promptendring. Skall har allerede `excerpt: null`. Tas med i B sammen med D5.
4. **Lint-koden `missing_current_version`** er ikke tatt i bruk. «Ufullstendig» vises i stedet gjennom eksisterende publiseringsstatus og sidevisning.

### 16.3 Testet

- **Nye tester:**
  - `EnterpriseWikiRunTerminalTransitionTest` og `…ArchitectureTest`
  - `EnterpriseWikiPartialGenerationRegressionTest`: kjøring 26-variant, og retry av forbigående feil til `completed`
  - `EnterpriseWikiRegenerationPublicationTest`
  - `EnterpriseWikiPublicationInvariantArchitectureTest`
  - `WikiRetryFailedPagesControllerTest`
  - `EnterpriseWikiIncompletePageTest`
- **Eksisterende tester oppdatert der atferden er endret med vilje:**
  - `FinalizeEnterpriseWikiPageGenerationTest`
  - `EnterpriseWikiPostIngestQaGatingTest`
  - `EnterpriseWikiIncrementalRelinkServiceTest`
  - `EnterpriseWikiPageGenerationFailureRecoveryServiceTest`
  - `EnterpriseWikiLinkCatalogServiceTest`
  - `WikiGraphDataControllerTest`
  - `WikiControllerTest`
  - `EnterpriseWikiPublicationSettlementServiceTest`: generering er nå klassifisert som innholdsendrende skriver
  - E2E-fixturene `WikiGraphFilterE2EFixture` og `WikiGraphFocusE2EFixture`
- **Resultater:**
  - Hele `tests/Feature/App/Wiki` pluss `tests/Unit/Services/EnterpriseWiki` og `tests/Unit/Services/Ai`: 3703 bestått, 4 feilet. Av de fire feilet 1 på grunn av endringen, og den er rettet. De 3 andre er en kjent eksisterende feil i `WikiSecondaryNavigationOrderTest`, som leser `CustomerAppLayout.jsx`; verken filen eller testen er endret, og strengen testen leter etter finnes ikke i HEAD.
  - 26 Wiki-relaterte testfiler utenfor Wiki-katalogen: 691 bestått, 2 feilet. Begge var fixtures uten versjon, og de er rettet.
  - `WikiControllerTest` og settlement-testen kjørt på nytt: 260 bestått.
  - JS-enhetstester for Wiki: 524 bestått.
  - `npm run build`: ok.
  - E2E: `wiki-graph-filters` og `wiki-graph-focus`, 55 bestått. `wiki-runs-readability`, `wiki-runs-stalled-indicator` og `a11y-wiki`, 18 bestått.
- **E2E for blokken «Prøv igjen for feilede sider»** ble lagt til i sluttkontrollen (§16.5).

### 16.4 Gjenstående forhold

- **Blokkere for A:** ingen kjente.
- **Observert, utenfor A:**
  - ~~`EnterpriseWikiEscalatedRunRecoveryService` og `wiki:recover-document-flow` nullstiller ikke en terminal `qa_status` ved gjenopptak av continuation.~~ Rettet i sluttkontrollen (§16.5).
  - Pint-avviket i `EnterpriseWikiLinkResolver` (fullt kvalifisert returtype) er eksisterende og ikke rettet.
- **Hører til B:** `has_content` i indeksen, kandidatrangering (§6.2), `link_assessment` (§6.3), relink v2 (§6.4) og lenkekontroll i QA (§7.1).
- **Hører til C:** varsel og øyeblikksbilde i `EnterpriseWikiRunTerminalTransition::afterTerminal()`, `initiated_by_user_id` og resultatpanelet.

### 16.5 Sluttkontroll (2026-10-18)

**1. QA-dom ved gjenopptak: rettet.**
- **Problem:** `EnterpriseWikiEscalatedRunRecoveryService::resume()` og `wiki:recover-document-flow` (stien `resume_continuation` for `failed`) beholdt en registrert dom (`passed`/`escalated`/`failed`) i `qa_status`. Continuationens `runForRun()` gjør krav på QA bare når `qa_status` er `null`, `pending` eller `repair_required`. Flyten tolket derfor den gamle dommen som «allerede fullført» og avsluttet den gjenopptatte kjøringen med den. Gjenskapt i test: en kjøring som var eskalert på `verification_incomplete`, ble eskalert på nytt selv om verifiseringen var fullført.
- **Rettelse:** én regel, `EnterpriseWikiIngestRun::qaStatusForResumedContinuation()`, brukes av begge stiene:
  - en registrert dom blir `null`, som ved første forsøk og som A4-retry
  - en hengende `running` blir `pending`, som før
  - `pending` og `repair_required` står urørt
- **Bevart:** `qa_result`, `qa_last_error` og `qa_attempt_count` står som historikk til den nye dommen erstatter dem.
- **Urørt ved avvisning:** et gjenopptak som avvises (`stale_state`, `already_running`, `not_recoverable`) eller kjøres som `--dry-run`, endrer ikke dommen.
- **Samtidighet og idempotens:** uendret. Tjenesten låser raden, og et nytt forsøk ser at status ikke lenger er `escalated`.
- **Ingen endring i publisering:** publiserings- og review-regler er ikke endret.
- **Tester:**
  - `EnterpriseWikiEscalatedRunRecoveryServiceTest`: ende-til-ende gjennom ekte continuation og QA til `completed`/`passed`, der en publisert versjon står urørt; avviste gjenopptak beholder dommen; gjentatt forsøk nullstiller ikke den nye QA-kjøringen.
  - `EnterpriseWikiRecoverDocumentFlowCommandTest`: CLI for `escalated` og for `failed` med alle tre dommene; `--dry-run`; gjentatt kjøring; kundebruker som `--actor` avvises uten endring.
  - Feil kunde og manglende rettighet på den nye retry-ruten dekkes av `WikiRetryFailedPagesControllerTest`.
- **Kjent begrensning:** `failed`-stien i CLI-en oppdaterer uten betinget `WHERE status = failed`. To operatører som kjører kommandoen i samme øyeblikk, kan sende to continuation-jobber. Continuationen selv er idempotent (radlås, sjekkpunkter og atomisk QA-krav). Dette er eksisterende oppførsel og ikke endret.

**2. Terminale overganger og fremtidige varsler.** Se §9.3. A1 er robust ved samtidighet. Kroken kan ikke alene garantere sluttvarselet. Anbefalingen for C er dokumentert der. A er ikke endret utover kommentaren til `afterTerminal()`.

**3. «Prøv igjen for feilede sider».**
- **Kontrollert i `Index.jsx`:**
  - Knappen vises bare når backend sier `can_retry`. Det beregnes av tjenestens `evaluate()` og er usann uten rettigheten `WIKI_SOURCE_MANAGE`.
  - Plandefekter får forklaring, men ingen knapp.
  - Handlingen bekreftes i den felles dialogen, med vern mot dobbel innsending (`retryInFlight`).
  - Resultatet vises som flash: suksess eller feil.
  - Backend håndhever tilgang: 403 og 404.
  - Stil og fokusretur er de samme som for «Prøv sideplanleggingen på nytt».
- **Ny E2E:** `tests/e2e/wiki-runs-retry-failed-pages.spec.js` med fixture `WikiRunsRetryFailedPagesE2EFixture`. Den dekker:
  - plandefekt: forklaring og ingen knapp
  - forbigående feil: knapp, dialog, dobbeltklikk som gir én forespørsel og suksessmelding
  - avvisning fra backend: feilbanner og lukket dialog
- **Fanget opp i E2E:** retry-forespørselen besvares i nettleseren med den ekte Kjøringer-siden pluss flash, slik at ingen jobb (AI-kall) sendes. Testen feiler når vernet mot dobbel innsending fjernes (kontrollert).

**4. Testresultater (sluttkontroll):**

| Testsett | Bestått | Feilet |
|---|---|---|
| `tests/Feature/App/Wiki` | 2397 | 3 (kjent, `WikiSecondaryNavigationOrderTest`, ikke endret) |
| `tests/Unit/Services/EnterpriseWiki` | 885 | 0 |
| `tests/Unit/Services/Ai` | 430 | 0 |
| 45 Wiki-relaterte testfiler utenfor Wiki-katalogen | 898 | 0 |
| JS-enhetstester (`npm run test:unit`, alle) | 2081 | 0 |
| E2E `wiki-runs-retry-failed-pages` | 3 | 0 |

- **Ingen nye regresjoner.**
- **De tre tidligere testene som feilet fordi atferden var endret, består fortsatt.** Den ene lå i Wiki- og enhetssettene, de to andre i settet utenfor Wiki-katalogen. Alle tre settene har ingen andre feil enn de tre kjente.
- **Ikke kjørt på nytt i sluttkontrollen:** de øvrige E2E-spesifikasjonene fra §16.3. Ingen frontendkode er endret siden de ble kjørt.
- **Test med ekte AI (§11.2):** ikke gjennomført og ikke godkjent.

**5. Status:** A1–A5 er implementert og sluttkontrollert. Arbeidspakke B og C er ikke påbegynt. Endringene er ikke committet.
