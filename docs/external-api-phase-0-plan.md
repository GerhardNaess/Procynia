# Eksternt API – implementeringsplan for fase 0

**Status:** Planen er godkjent 2026-10-22 med beslutningene B5–B9 (§1). Implementeringen er startet i rekkefølgen i §5.

**Fremdrift:**
- Ferdig:
  - steg 1 (`3a0d009d`)
  - steg 3a (`7962a744`), 3b (`dcb7d823`) og 3c (`5eac50a4`), med E2E (`a11y-wiki.spec.js`)
  - F1c (`2af1ba86`)
  - steg 2 (`cd42a7f2`)
  - steg 4 / F3a (`d6f58377`)
  - F1d (`4e827d93`)
  - steg 5 (`9b5a5673`, `be1aba2b`)
  - steg 6 (`9541ef63`, regresjon rettet i `f10a0f81`)
  - steg 7 (`37c239c5`)
  - KTF-3 (`be3f517c`)
  - A4 (`e4e56f50`)
  - steg 9, Risiko (`cc3a4e90`, `595deea4`, `b03fd473`, `4c3cd3bf`, E2E-retting `f5e66bf6`), se §2c
  - steg 10, Avvik og forbedringer (`45ece813`, `5079e978`, `6a6df31d`, `9059f765`), se §2d
  - steg 11, Ledelsens gjennomgang (`34d4c8ba`, `3d89f627`, `6f5431fb`), se §2e
  - isolert E2E-miljø (`cef890b2`), se `docs/testing/e2e-environment.md`
  - steg 12, Kvalitet (`f958fe57`, `686dba14`, `60fd0d16`), se §2f
  - oppfølging av A19–A23 og JS-grunnlaget (`ef1e0ee6`, `67e3cf2b`, `37f84480`, `7ca3b7a5`, `03428309`), se §2g
  - sletting, koblinger og evidens i Kvalitet: A19/A28, A29, A20 (del) og A27 (`41a7fb78`, `b34dec1a`, `770eb2f0`), se §2h
  - A32 (`3ff1b8a0`) og A31 (`5d9c0d03`), se §2i
  - steg 13, Etterlevelse og revisjon (`3b66c55d`, `5228b3e3`, `7ccb97f3`), se §2i
  - A34 (`db5699e1`) og A35 (`4ecae0ce`), se §2i
  - steg 14, Leverandøroppfølging (`9e757eca`, `1ffb9586`, `87c3131e`, E2E-retting `5e887aaf`), se §2j
  - A36, kritikalitet og livsløp (`334380d8`), se §2j
  - steg 15, Mål og KPI (`3a6131f5`, `27405c7a`, `f3072ba1`, `2d9aee4a`), se §2k
- Wiki-delen av fase 0 og Risiko (steg 9) er gjennomført og godkjent 2026-10-22.
- Avvik og forbedringer (steg 10) er gjennomført og godkjent 2026-10-22.
- Ledelsens gjennomgang (steg 11) er gjennomført og godkjent 2026-10-22.
- Kvalitet (steg 12) er gjennomført og godkjent 2026-10-22.
- Oppfølgingen av A19–A23 (§2g) er gjennomført og godkjent 2026-10-22.
- Reglene for sletting, koblinger og evidens i Kvalitet (§2h) er gjennomført og godkjent 2026-10-22.
- A31 (behold, kontrollert) og A32 (versjonskontroll påkrevd) er gjennomført 2026-10-22, se §2i.
- Etterlevelse og revisjon (steg 13) er gjennomført og godkjent 2026-10-22.
- A34 og A35 er gjennomført og godkjent 2026-10-23 (§2i). Etterlevelse og revisjon er ferdig i fase 0.
- Leverandøroppfølging (steg 14) er gjennomført og godkjent 2026-10-23 (§2j).
- A36 er gjennomført og godkjent 2026-10-23, og A37 og A38 er besluttet (§2j). Leverandøroppfølging er ferdig i fase 0.
- Mål og KPI (steg 15) er gjennomført 2026-10-23 (§2k). A39 og A40 venter på beslutning. Anbud startes ikke før resultatet er vurdert.
- F1a, F1b og O6 er rettet.
- F1c (`2af1ba86`) og F1d (`4e827d93`) er rettet.
- Kjente testfeil på grunnlinjen: [`docs/testing/known-test-failures.md`](testing/known-test-failures.md) (KTF-1, KTF-2).
- Full Wiki-suite etter steg 7 (2026-10-22): 2913 bestått, 18 feilet. Alle 18 er KTF-1 eller KTF-2 og fantes før fase 0.
- KTF-1, KTF-2 og KTF-3 er løst. A4 er implementert (§2b).
- Verifisering etter A4 (2026-10-22):
  - Wiki-suiten for PHP (`--filter=Wiki`, 4406 tester): alle bestått, 0 feilet.
  - JS-suiten: 2130 av 2130 bestått.
  - Alle Wiki-spesifikasjoner i E2E (21 filer): 175 av 175 bestått.
  - Frontendbygg og Pint på berørte filer er rene.
  - F1a–F1d er beskyttet av testene som rettet dem, og alle består: `WikiEditLockedDuringReviewTest`, `WikiPublishIsExplicitTest`, `EnterpriseWikiClaimContentRepairServiceTest`, `WikiClaimTextDecisionDuringReviewTest`, `WikiBestPracticeSectionDecisionTest`, `EnterpriseWikiSourceBasedBlockVerificationTest` og `WikiClaimTextDecisionOnPublishedVersionTest`.
**Dato:** 2026-10-22
**Gren:** `feature/external-api-foundation`
**Bygger på:** [`external-api-architecture.md`](external-api-architecture.md) §4.3, §7 og §14, [`AGENTS.md`](../AGENTS.md), [`enterprise-wiki-architecture.md`](enterprise-wiki-architecture.md) og [`enterprise-wiki-approval-model.md`](enterprise-wiki-approval-model.md).

Fase 0 bygger ikke API-et. Den flytter ansvaret for menneskelige beslutninger fra kontrollerne ned i domenelaget. Da kan et senere API, og enhver annen ny inngang, gjenbruke tjenestene uten å arve et sikkerhetshull. Alt i fasen gjør også webgrensesnittet sikrere.

---

## 1. Produktbeslutninger (2026-10-22)

| # | Beslutning | Konsekvens for fase 0 |
|---|---|---|
| B1 | **Integrasjoner og AI-agenter utfører aldri endelige godkjenninger, publiseringer eller andre formelle beslutninger.** De kan opprette utkast og forslag gjennom egne, avgrensede rettigheter. Kildeadministrasjon, kunnskapsoverføring og formell godkjenning skal være adskilte rettigheter. | `humanOnlyPermissions()` (§4.1). `wiki.source.manage` deles: ny rettighet `wiki.knowledge.handoff` for kunnskapsoverføring (§4.2). |
| B2 | **Menneskeforbeholdte beslutninger er ikke tilgjengelige via API-et, heller ikke i delegert modus.** | `HumanDecisionGuard` nekter når forespørselen kommer fra API-et, uansett modus (§4.3). |
| B3 | **En risiko med vurderinger, aksepter eller annen beslutningshistorikk kan ikke slettes permanent.** Historikken bevares. Arkivering eller deaktivering brukes i stedet. | `Risk::isDeletable()` og en sperre i databasen. «Lukket» (`Risk::STATUS_CLOSED`) er alternativet (§4.6). |
| B4 | **Godkjenning og avvisning av krav i Anbud krever en eksplisitt rettighet og en identifiserbar menneskelig aktør.** Lesetilgang til saken er ikke nok. | Ny rettighet i Anbuds rettighetsmatrise, krav om `canManage` på saken, og påkrevd aktør (§4.7). |
| B5 | **Sideeieren kan trekke tilbake en Wiki-side som er sendt til godkjenning.** <br>– Innhold som ligger til godkjenning, kan ikke redigeres direkte. <br>– Tilbaketrekkingen registreres med aktør og tidspunkt. <br>– Det tidligere godkjenningsoppdraget og beslutningsmulighetene ugyldiggjøres. <br>– Siden kan deretter redigeres og sendes til ny godkjenning. <br>– Publisert versjon er uendret. <br>– Fireøyneprinsippet og reglene for tildelt kontrollør bevares. | §4.9. Retter F1a. |
| B6 | Agenter kan ikke laste opp kildedokumenter i fase 0. Det vurderes som en egen funksjon senere. | Ingen endring (§9, Å2 lukket). |
| B7 | Integrasjoner kan ikke registrere KPI-målinger i fase 0. En senere løsning kan tillate det med egne rettigheter, identifiserbar kilde og sporbarhet. | `objective.measure` er menneskeforbeholdt (§4.1). |
| B8 | Lukkede risikoer vises som i dag i fase 0. | Ingen endring i registeret. |
| B9 | **En registrert godkjenning eller avvisning fra en dokumenteier kan ikke overskrives uten historikk.** En ny beslutning registreres som en egen, sporbar hendelse. | §4.10. Retter O1. |
| B10 | **Kvalitet: koblinger fra andre moduler (A19/A28).** En prosess eller kontroll som Risiko, Mål og KPI, Avvik eller Etterlevelse peker på, slettes ikke, heller ikke av en som ikke ser modulen. Meldingen er generell, elementet settes til «Utgått», og koblingene bevares. Erstatter den tidligere regelen om at Kvalitet ikke skal påvirkes av skjulte koblinger. | §2h. |
| B11 | **Kvalitet: evidens (A29).** Evidens trekkes tilbake og slettes aldri. Hvem og når registreres, og hendelsen logges (`quality_item_events`). Et element med evidens slettes ikke. | §2h. |

**Føringer for gjennomføringen (2026-10-22):**

- En generell Wiki-godkjenner skal ikke overta en tildelt kontrollørs beslutning uten eksplisitt hjemmel i eksisterende regler.
- AI-genererte forslag skal holdes adskilt fra ordinær redigering av innhold.
- Alle eksisterende publiseringsinvarianter i Enterprise Wiki skal bevares.
- Den publiserte versjonen skal være uendret gjennom redigering, tilbaketrekking og ny godkjenningsrunde.
- Rettighetskontroller skal aldri svekkes for å få testene grønne.

---

## 2. Funn utenfor fase 0 (verifisert 2026-10-22)

Hvert funn er bekreftet eller avkreftet med en isolert test. Testene beskriver dagens oppførsel. De feiler den dagen feilen rettes, og skal da skrives om til den nye regelen. Ingen produksjonskode er endret.

**Publisert Wiki-innhold er ikke berørt av noen av funnene.** Grounding, «Spør Wiki» og anbudssvar leser bare `published_version_id` (`RequirementWikiResearchService.php:375–385`, `RequirementWikiCatalogBuilder`). Ingen av funnene publiserer en ugjennomgått versjon.

| Id | Funn | Status | Alvor | Test |
|---|---|---|---|---|
| **F1a** *(rettet i 3b, `dcb7d823`)* | Sideeieren kan trykke «Rediger» på en side de har sendt til godkjenning. Redigeringen blir gjeldende versjon uten tildeling, fordi tildelingen ligger på den innsendte versjonen. Følger: <br>– Den tildelte kontrolløren kan ikke lenger publisere (409). <br>– *Enhver* Wiki-godkjenner, også en som aldri ble tildelt, kan sende siden tilbake, fordi `WikiController::mayActOnCurrentVersion()` faller tilbake til `true` for en versjon uten kontrollør. <br>– Eneste vei videre er å sende tilbake og så sende inn på nytt. | **Bekreftet feil** | Høy. Fireøyneprinsippet ved overlevering brytes. Det publiseres ingenting. | `tests/Feature/App/Wiki/WikiManualEditDuringReviewFindingTest.php` |
| **F1b** *(rettet i 3b, `dcb7d823`)* | Påstandsreparasjon (`EnterpriseWikiClaimContentRepairService::applyManualMixedBlockEdit`) promoterer den nye versjonen uten `PublicationSettlementService::afterManualEdit()`. Følger: <br>– En publisert side beholder status `approved` med en upublisert gjeldende versjon. Statustjenesten rapporterer den som `blocked`, fordi `submit()` bare tar imot utkast. Endringen har altså ingen vei til godkjenning. <br>– Promoteringen skriver `is_current` direkte, utenom `EnterpriseWikiPageVersionWriter`. Den omgår dermed også sperren mot åpen godkjenning. <br>– Handlingen tilbys i grensesnittet for sider fra en anvendt kjøring, uten statussperre (`WikiController::manualMixedBlockEditContext`). | **Bekreftet feil** (tjenestenivå). Hele veien gjennom grensesnittet er ikke reprodusert. | Middels–høy. Arbeidsflyten står fast, og endringen blir ikke gjennomgått. | `EnterpriseWikiClaimContentRepairServiceTest::test_manual_mixed_block_edit_leaves_a_published_page_approved_with_unpublished_changes` |
| **F2** | `FinalizeEnterpriseWikiIngest` (:189) setter `status = draft` uten betingelse. | **Ikke en feil i praksis.** Jobben nås bare via den eldre CLI-stien `wiki:ingest-document`. Den lager alltid en *ny* side der eneste versjon ikke er gjeldende før Finalize, og `submit()` krever gjeldende versjon (422). En fullført kjøring skrives aldri på nytt. | Lav. Bør likevel få en statussjekk, slik at regelen ikke hviler på rekkefølge alene. | `FinalizeEnterpriseWikiIngestTest::test_a_page_still_waiting_for_finalize_cannot_be_sent_for_review` og `::test_a_repeated_finalize_leaves_a_page_submitted_after_the_run_in_review` |
| **F3a** *(rettet i steg 4, `d6f58377`)* | Sletting av en prosess med «slett også Wiki-sidene» sjekker bare sideeierskap, ikke `wiki.delete`. En sideeier med `quality.delete` men uten `wiki.delete` sletter dermed via Kvalitet en side Wiki selv nekter dem å slette. | **Bekreftet feil** | Middels. Det er en omgåelse av en rettighet, men ikke av eierskap. | `QualityItemTest::test_a_page_owner_without_wiki_delete_removes_produced_pages_through_quality` |
| **F3b** | Kvalitet laster opp filer til det felles dokumentarkivet (`EnterpriseWikiDocument`) med bare `quality.create`/`quality.edit`, uten `wiki.source.manage`. | **Bevisst valg** (dokumentert i `QualityController::storeDocument`). Det starter ingen Wiki-kjøring og lager ikke noe Wiki-innhold. Opplasteren blir dokumenteier. | Krever avklaring. Med B1 bør det besluttes om en integrasjon skal kunne bli dokumenteier via denne veien (nei, jf. §4.3 om eiervalg). | Ingen. Oppførselen er dokumentert i koden. |
| **F4** | Endringer i anbudsrolle, QA-flagg, Wiki-godkjenner, kunderoller, rollens rettigheter og Tilganger-matrisen lagrer ikke hvem som gjorde dem, verken i database eller logg. | **Bekreftet mangel** | Høy for fase 1. Roller blir veien inn til menneskeforbeholdte rettigheter. | `tests/Feature/Security/AdministrativeChangeAuditTrailTest.php`. Testen skanner alle 158 aktørkolonner i skjemaet. |

### Andre observasjoner fra kartleggingen (kontrollert i koden, ikke testet)

| Id | Observasjon | Vurdering |
|---|---|---|
| O1 | `EnterpriseWikiDocumentOwnerApprovalService::decide()` kaller ikke `canDecide()`, sjekker ikke om raden allerede er avgjort, og låser ikke raden. En ny beslutning overskriver den forrige. | Tas i fase 0 (§5, steg 5). Dokumenteier-godkjenning sperrer ikke lenger publisering, så dette gjelder sporbarhet. |
| O2 | `RiskController::destroy()` sletter risikoen. Databasekaskade fjerner vurderinger og aksepter, og modellvaktene kjører ikke ved kaskade. | **Løst** av B3 (`b03fd473`, §2c). |
| O3 | `AiController::updateRequirementReviewStatus()` sjekker bare synlighet og AI-tilgang. Lesere kan godkjenne krav. | Løses av B4 (§4.7). |
| O4 | `WikiSourceController::ingest()` sjekker ikke eierskap og lagrer ikke hvem som startet kjøringen. `cancelRun` lagrer aktøren bare som fritekst i `error_message`. | Sporbarhet. `started_by_user_id`/`cancelled_by_user_id` foreslås i §4.8 (valgfritt). |
| O5 | `approve()` og `reject()` bruker ulike myndighetsregler. System Owner kan sende tilbake, men ikke publisere en versjon de ikke er tildelt. | Bevisst (`User::canFinalApproveEnterpriseWikiVersion`, docblokk). Skal bevares uendret. |
| O6 *(rettet i 3c, `5eac50a4`)* | `WikiController::mayActOnCurrentVersion()` returnerer `true` for en versjon uten kontrollør. Dermed kan *enhver* Wiki-godkjenner sende den tilbake. Dette motsier `User::canReviewEnterpriseWikiVersion()` («a missing reviewer is broken state, not a licence»). | Strammes inn i steg 3. Bare tildelt kontrollør eller System Owner kan returnere. Sider som allerede står i denne tilstanden, frigjøres med «Trekk tilbake» (B5). |
| F1c *(rettet, `2af1ba86`)* | Påstandsbeslutninger («Rediger og godkjenn», «Fjern teksten», beste praksis) endrer teksten i den gjeldende versjonen *direkte*, uten ny versjon, også mens siden ligger til godkjenning. Tildelingen beholdes, så kontrolløren vurderer det som står ved godkjenning. Dette er funnbehandling som hører til gjennomgangen, ikke ordinær redigering. | **Besluttet 2026-10-22:** under godkjenning tar bare tildelt kontrollør tekstendrende påstandsbeslutninger. Kvalitetssikrer, sideeier, innsender og System Owner beholder beslutningene som ikke endrer teksten. Endringen spores (aktør, tidspunkt, gammel og ny tekst), vises for sideeier, og havner i versjonen kontrolløren godkjenner. Kontrolløren må fortsatt ha rettigheten til å behandle påstander (QA). |
| **F1d** *(rettet, `4e827d93`)* | **Bekreftet 2026-10-22.** Når en side er publisert og gjeldende versjon *er* den publiserte, skriver tekstendrende påstandsbeslutninger direkte i den publiserte versjonen. Siden står fortsatt som «godkjent», og ingen har gjennomgått endringen. Dette rammer publisert innhold som svar og grounding bygger på. | Høy. **Besluttet og rettet:** beslutningen lager en ny arbeidsversjon via `EnterpriseWikiPageVersionWriter` (`forkPublishedVersionForClaimDecision`). Alle påstander kopieres, siden går til utkast, og publisert versjon og påstandene i den, som grounding leser, er uendret. Samtidige beslutninger gir 409. Test: `WikiClaimTextDecisionOnPublishedVersionTest`. |

### Arkitekturfunn under steg 5–7 (2026-10-22)

| Id | Funn | Vurdering |
|---|---|---|
| A1 | Godkjenningstjenestene for Wiki nekter fortsatt med `abort()` (HTTP-unntak), fordi koden ble flyttet uendret og statuskodene skal være like. | Godtatt i fase 0. API-laget i fase 1 må gjøre om `HttpException` til sitt eget feilformat. Domeneunntak kan innføres senere uten endret oppførsel. |
| A2 | Noen Wiki-handlinger for kildeadministrasjon går ennå ikke gjennom vakten: opplasting (`EnterpriseWikiDocumentUploadService::store(?int, ?int)`), bytte av dokumenteier (skrives i `WikiSourceController::updateOwner`), start av kjøring (`startForDocument` uten aktør) og ny kjøring av mislykkede steg (`attempt()` uten aktør). `HumanDecision` har verdiene `WIKI_SOURCE_UPLOAD`, `WIKI_SOURCE_OWNER_CHANGE` og `WIKI_RUN_START`, men de er ikke koblet til. | Ikke godkjenningsflyt. Kontrollerne sjekker `wiki.source.manage`. Kobles til i steg 20, kontrakttesten, eller før en API-rute åpnes for kildeadministrasjon. Det skal den ikke i v1. |
| A3 | Regresjonen i steg 6 (`f10a0f81`) ble bare fanget av `WikiControllerTest`. Den var holdt utenfor de målrettede listene på grunn av KTF-1. | KTF-1 bør rettes før steg 9, så hele `WikiControllerTest` igjen kan brukes som sikkerhetsnett. |
| A4 | Påstandsbeslutninger som ikke endrer tekst («Behold teksten», blokkering), skriver fortsatt `approval_status` og blokkering på påstander i den publiserte versjonen. | Vurdert (§2a) og **implementert** 2026-10-22 (§2b, `e4e56f50`). Statusendringer skjer fortsatt på stedet. Hver endring, også systemets egne og versjonskopier, står i en beskyttet logg, skrevet i samme transaksjon. |
| A5 | Endepunktene for dokumenteiers godkjenning og avvisning har ingen kaller i grensesnittet lenger. | Herdet i steg 5. Om de skal fjernes, er et eget spørsmål. Fjerning reduserer angrepsflaten. |

### 2a. Vurdering av A4 – statusendringer på publiserte påstander (2026-10-22)

**Hva som kan endres etter publisering**

Innholdet i en publisert versjon (`content_markdown`, `content_blocks_json`, `claim_text`) kan ikke lenger endres, etter F1c og F1d. Kvalitetsmetadata på påstandene i versjonen kan fortsatt endres:

| Felt | Endres av |
|---|---|
| `approval_status`, `approved_by_user_id`, `approved_at`, `approval_comment` | «Behold teksten» og avvis uten tekstfjerning (`EnterpriseWikiClaimDecisionService::approve/reject`), «Angre» (`unapprove`), kaskade over sider for beste praksis, og to systemstier som nullstiller til `pending`: `EnterpriseWikiAppliedRunLintService::resetClaimDecisionAfterFirstSourceReference` og `EnterpriseWikiDocumentDeletionService` når en påstand mister sin eneste kilde |
| `blocking_override`, `blocking_override_by_user_id`, `blocking_override_at` | Beslutning om blokkering (`setBlockingOverride`) |
| `review_metadata` | `visible_wiki_link_result` ved godkjenning, `text_removed_by_decision` |
| Kildereferanser (`enterprise_wiki_source_references`) | «Koble kilde» (`attachSourceReference`) |
| Lintfunn | Løses og gjenåpnes sammen med beslutningene |

**Hvem som leser feltene**

- **Ikke svarmotoren.** Grounding, Spør Wiki og anbudssvar leser `claim_text` og `content_origin` fra den publiserte versjonen (`RequirementWikiResearchService`, `RequirementWikiCatalogBuilder`), aldri status eller blokkering.
- **QA og arbeidsflyt:**
  - kvalitetsstatus og fremdrift for kvalitetssikring på siden
  - QA-funn i Kjøringer (`EnterpriseWikiRunFindingsService`)
  - «Mine oppgaver» (`EnterpriseWikiQaTaskService`)
  - kjøringens QA-utfall (`EnterpriseWikiPostIngestQaService`, blant annet blokkering)
  - antall påstander i publiseringsstatusen
  - sperren mot publisering ved uløste kildefunn, som bare gjelder versjonen som ligger til godkjenning
- Alle disse leser gjeldende versjon (`is_current`). For en publisert side er det den publiserte versjonen.

**Historikk og sporbarhet**

Hver beslutning fra en person skrives til `enterprise_wiki_claim_decisions` med aktør, tidspunkt, forrige og ny tilstand og kommentar. Fra F1c gjelder det også gammel og ny tekst. Tre hull er bekreftet i koden:

1. **To systemstier nullstiller beslutninger uten å skrive i loggen.** `resetClaimDecisionAfterFirstSourceReference` gjør det ved første kildereferanse, og dokumentslettingen ved tap av eneste kilde. Begge bruker `$claim->update()` direkte, så tilstanden kan ikke rekonstrueres fra loggen.
2. **«Koble kilde» lagrer ingen aktør og skriver ingen rad i loggen.**
3. **`enterprise_wiki_claim_decisions` er append-only bare etter konvensjon.** Tabellen har verken modellvakt eller trigger, i motsetning til historikktabellene i de andre modulene og dokumenteiers beslutninger (steg 5).

**Er en publisert versjon et uforanderlig øyeblikksbilde?**

- **For innholdet: ja.** Det er det svar og lesere bygger på, og etter F1d kan det ikke endres uten en ny versjon og ny godkjenning.
- **For kvalitetsstatusen: nei.** Den er levende og skal være det. Kvalitetssikring kan fortsette etter publisering (sidens egen hjelpetekst sier det, og produktreglene sier at QA ikke stopper publisering).
- **Statusen på publiseringstidspunktet** kan rekonstrueres fra loggen bare når hullene 1–3 er tettet.

**Egne hendelser eller ny arbeidsversjon?**

Ny arbeidsversjon for statusendringer anbefales ikke:

- Hver «Behold teksten» og hver blokkering på en publisert side ville sette siden til utkast og kreve ny godkjenningsrunde, selv om ingenting leserne ser, har endret seg.
- Det er byråkrati uten noe spørsmål å besvare, og det strider mot `PublicationSettlementService` («purely technical maintenance … sending a published page back for review … would be bureaucracy») og mot QA-flyten.
- Loggen er allerede den riktige mekanismen for hendelser. Den må bare bli komplett og beskyttet.

**Anbefaling (implementert 2026-10-22, se §2b)**

1. Statusendringer skjer fortsatt på stedet, på den gjeldende eller publiserte versjonen.
2. Systemnullstillinger skriver en rad i loggen: aktør `NULL`, ny `decision_type` (for eksempel `system_reset`) og årsak i `comment`.
3. «Koble kilde» skriver en rad i loggen (`decision_type = source_reference`) med aktør og referansen som ny tilstand.
4. `enterprise_wiki_claim_decisions` får modellvakt og trigger som nekter UPDATE og DELETE, med unntak for kaskade fra påstanden eller kunden og nulling av slettet bruker. Det er samme mønster som i steg 5.
5. En test kontrollerer at hver kodesti som skriver `approval_status` eller `blocking_override`, også skriver en rad i loggen (strukturell test, som i `HumanDecisionContractTest`).

**Konsekvens for QA-arbeidsflyten:** ingen synlig endring. QA arbeider som i dag. Det eneste nye er at systemnullstillinger og kildekoblinger blir synlige i historikken.

### 2b. A4 implementert – fullstendig historikk for påstandsstatus (2026-10-22)

**Commit:** `e4e56f50`. **Beslutning (godkjent 2026-10-22):** kvalitetsmetadata på publiserte versjoner kan fortsatt oppdateres uten ny arbeidsversjon. Publisert tekst er fortsatt uforanderlig (F1c/F1d). Publiserings- og godkjenningsreglene er ikke endret.

**Hva som er gjort**

| Krav | Løsning |
|---|---|
| 1. Systemnullstillinger i loggen | `EnterpriseWikiClaimDecisionRecorder` er eneste kode som endrer `approval_status`/`approved_*` og `blocking_override*` på en påstand som finnes. De to nullstillingene (`reset_on_first_source`, `reset_on_lost_source`) skriver en hendelse. Det gjør også kopiering av en påstand til en ny versjon (`copied_to_new_version`), som var et fjerde hull: kopien fikk status uten historikk (påstand 547 i utviklingsdata). |
| 2. Aktør og tidspunkt på kildekoblinger | `enterprise_wiki_source_references` har `linked_by_type` (`user`, `system`, `unrecorded`), `linked_by_user_id`, `linked_by_process` og `linked_at`. «Koble kilde» lagrer personen. De fem stegene i pipelinen lagrer sitt navn. En kopi beholder opplysningene fra koblingen den kopierer. «Koble kilde» skriver i tillegg en hendelse (`source_reference`) i loggen. |
| 3. Beskyttet logg | Modellvakt og PostgreSQL-trigger nekter UPDATE og DELETE. Unntakene er nulling av en slettet bruker og kaskade når påstanden selv slettes. Samme mønster som dokumenteiers beslutninger (steg 5). `decided_by_user_id` hadde `cascadeOnDelete`: sletting av en bruker slettet beslutningene. Nå `nullOnDelete`. |
| 4. Atomisk | Opptakeren skriver hendelsen og endringen i én transaksjon med lås på påstandsraden. Forrige tilstand leses fra den låste raden. «Angre», blokkering og «Koble kilde» (kobling, hendelse og eventuell nullstilling) går nå også i én transaksjon. Det gjorde de ikke før. |
| 5. Tester som avdekker hull | Se under. |

**Systemhendelser uten innlogget person**

- Hver hendelse har `actor_type`: `user` (en person, `decided_by_user_id`) eller `system` (Procynia selv, `system_process` navngir regelen).
- En systemhendelse har aldri `decided_by_user_id`. Det finnes ingen systembruker eller stedfortreder.
- `initiated_by_user_id` er personen som utløste regelen, når det finnes en: den som koblet kilden eller slettet dokumentet. Er nullstillingen utløst av verifisering eller avstemming i pipelinen, er feltet tomt.
- En systemhendelse lagrer alt den nullstilte (hvem som godkjente, når, kommentaren). Ingen persons beslutningsrad sier det.
- Databasen håndhever regelen med `ewcd_actor_check`. Modellen nekter i tillegg en personhendelse uten person når den skrives. Databasen kan ikke skille den fra en hendelse der personen er slettet senere.
- Avvik fra anbefalingen i §2a: `decision_type` er fortsatt aksen (`approval_status`, `blocking_override`). Ny `decision_type` brukes ikke for systemnullstillinger. Den som leser historikken for en akse, får da med seg alle endringer uansett aktør. Årsaken står i `system_process`, ikke i `comment`, som er forbeholdt det en person skrev.

**Migrering (`2026_10_22_000004_complete_enterprise_wiki_claim_decision_history`)**

- Kontrollert på utviklingsdata før sperrene ble innført:
  - 54 hendelser, alle med person.
  - 119 påstander, hvorav 55 besluttet.
  - Én besluttet påstand uten historikk: en versjonskopi.
  - Ingen påstand der siste hendelse og gjeldende tilstand var ulike.
  - 38 kildekoblinger uten aktør.
- Eksisterende hendelser får `actor_type = user`.
- Hver påstand der gjeldende godkjenning eller blokkering avviker fra siste hendelse, får én systemhendelse `history_backfill`. På utviklingsdata gjaldt det 1 påstand. Etterfyllingen kan kjøres igjen uten å legge til noe.
- Eldre kildekoblinger blir `unrecorded` med `linked_at = created_at`. Migreringen gjetter ikke hvem som laget dem.
- Migreringen kan rulles tilbake. Systemhendelser og hendelser uten person fjernes da, siden den gamle kolonnen ikke kan holde dem.
- Utviklingsdatabasen og testdatabasen er migrert.

**Hva som ikke er endret**

- Ingen synlig endring i QA-arbeidsflyten. Loggen har ingen leser i grensesnittet. Beslutninger, statuser, funn, «Mine oppgaver» og publiseringsstatus leser de samme feltene som før.
- Publiserings- og godkjenningsreglene og F1a–F1d er uendret.
- En modellvakt på `EnterpriseWikiClaim` som nekter statusendringer utenom opptakeren, er vurdert og ikke innført. Elleve testfiksturer setter status direkte for å bygge opp en tilstand, og de måtte da skrive falsk historikk. Kontrakttesten finner slik kode i `app/`.

**Tester**

- `EnterpriseWikiClaimDecisionHistoryTest` (9 tester):
  - en persons beslutninger på en publisert side, der teksten og versjonen er uendret
  - «Koble kilde» og nullstillingen den utløser
  - nullstilling fra pipelinen uten person
  - sletting av eneste kilde
  - versjonskopi
  - atomisitet i begge retninger
  - uforanderlig logg (modell og database, sletting av bruker og påstand)
  - aktørregelen
  - etterfyllingen i migreringen
- Hjelperen `assertHistoryEndsAtCurrentState` kontrollerer invarianten: leser man bare historikken, kommer man fram til påstandens gjeldende godkjenning og blokkering.
- `EnterpriseWikiClaimStatusHistoryContractTest` (3 tester) leser alle PHP-filer i `app/` som arbeider med påstander:
  - Den feiler på `update()`, `fill()`, `forceFill()` og tilordning av statuskolonnene utenom opptakeren.
  - Den feiler på `create()` med annen status enn `pending`.
  - Den feiler på nye steder som holder kolonnene uten gjennomgått begrunnelse.
  - Den feiler på kildekoblinger som ikke sier hvem som laget dem.
- Mutasjonssjekket:
  - nullstilling uten opptaker gir 2 feil
  - kopi uten hendelse gir 1 feil
  - opptaker uten transaksjon gir 1 feil
  - direkte statusskriving i en ny fil fanges av kontrakttesten
- `EnterpriseWikiClaimContentRepairServiceTest`: påstanden om at kopien «ikke har noen beslutninger», er skrevet om. Personens beslutning kopieres fortsatt ikke. Kopien har én systemhendelse som peker til originalen.

---

### 2c. Risiko (steg 9) – kartlegging og gjennomføring (2026-10-22)

**Kartlegging før steg 9**

| Handling | Rettighet | Kontrollert i | Merknad |
|---|---|---|---|
| Se, liste, søke | `risk.view` i fagområdet | Domenet (`RiskAccessService::visibleRisks`) | Uendret. En skjult risiko er 404. |
| Registrere | `risk.create` i valgt fagområde | Domenet (`RiskCreator::create`) | Uendret. |
| Endre, lukke, flytte | `risk.edit` | **Bare kontrolleren** (`RiskController::update`) | `RiskCreator` sjekket målområde og eier, ikke selve endringsretten. |
| Vurdere | `risk.assess` (menneskeforbeholdt) | **Bare kontrolleren**, som også skrev vurderingen | |
| Akseptere og tilbakekalle | `risk.accept` (menneskeforbeholdt) | **Bare kontrolleren** | `RiskAcceptanceService` stolte på kalleren. |
| Slette | `risk.delete` (menneskeforbeholdt) | **Bare kontrolleren** | Kaskade fjernet vurderinger, aksepter og tiltak (O2). |
| Tiltak | `risk.edit` | **Bare kontrolleren** | Tjenesten sjekket bare ansvarlig person. |
| Koble til kontroll, prosess og aktivitet | `risk.edit` og lesetilgang til Kvalitet | **Bare kontrolleren** | |
| Kunnskapsartikkel til Wiki | `risk.edit` og `wiki.knowledge.handoff` | Domenet (`WikiKnowledgeHandoffService`, steg 2) | Uendret. |

**Gjennomført**

| Commit | Innhold |
|---|---|
| `cc3a4e90` | En integrasjon får ingen menneskeforbeholdt risikonøkkel via fagområder (A6). |
| `595deea4` | `RiskAssessmentService::record` (ny) og `RiskAcceptanceService::accept`/`revoke` kaller `HumanDecisionGuard` (`RISK_ASSESS`, `RISK_ACCEPT`) og deretter `canAssess`/`canAccept`, vurdert på risikoen slik den står under radlåsen. |
| `b03fd473` | B3: `Risk::isDeletable()`, `RiskLifecycleService::delete` (vakt `RISK_DELETE`, `canDelete`, historikkregelen under radlåsen, 409), modellvakt, databasetriggere og visning på risikosiden. |
| `4c3cd3bf` | Endring, lukking og flytting (`RiskCreator::update`), tiltak og koblinger til Kvalitet sjekker `risk.edit` i fagområdet i tjenesten. Dette er ikke menneskeforbeholdte handlinger, så en integrasjon med `risk.edit` kan utføre dem. |
| `f5e66bf6` | To E2E-feil etter Wiki-stegene (A7). |

**Sikkerhetsregler**

1. Vurdering, aksept, tilbakekalling og sletting går bare gjennom sine tjenester. Hver tjeneste:
   - kaller vakten først. Den nekter integrasjoner, inaktive personer og alt som kommer via API-kanalen, også i delegert modus;
   - sjekker deretter tilgangen selv: beslutningsnøkkelen og `risk.view` i risikoens fagområde. Hvert par (nøkkel, fagområde) må komme fra én og samme rolle, men nøkkelen og `risk.view` kan komme fra hver sin rolle. Formuleringen «from the same role» i commitmeldingen til `595deea4` er upresis;
   - vurderer risikoen slik den står nå, under radlåsen. En risiko som nettopp er flyttet ut av fagområdet, nektes.
2. Andre kunders risikoer er utilgjengelige. Det gjelder også for en rolle med «Alle». Kontrolleren svarer 404, og tjenesten nekter med 403.
3. System Owner får fortsatt ingen risikodata uten en rolle (tidligere besluttet). En System Owner uten rolle nektes alle beslutninger.
4. Kontrollerne beholder sine sjekker først, så statuskoder og meldinger er uendret. Tjenestenes sjekker er forsvar i dybden.

**Historikk (B3)**

- En risiko med vurdering, aksept (også tilbakekalt) eller tiltak kan ikke slettes. Det håndheves på fire nivåer:
  - tjenesten (409);
  - HTTP (feilmelding);
  - modellen (`LogicException`);
  - databasen (`risks_keep_decision_history`).
- Kundesletting virker som før: kunden er borte når kaskaden kommer til risikoen, og da slipper triggeren den gjennom.
- `risk_assessments` kan aldri endres, og `risk_acceptances` kan bare tilbakekalles, én gang. Databasen håndhever begge deler med `*_immutable`-triggere. Begge slettes bare sammen med risikoen eller kunden, og nulling av en slettet bruker er tillatt.
- På risikosiden erstattes «Slett risiko» av en begrunnelse som peker til «Lukket» under «Rediger». Hjelpeteksten har fått punktet «Lukke eller slette» (norsk og engelsk).
- En risiko uten historikk slettes som før.

**Migrering og data**

- `2026_10_22_000005_keep_risk_decision_history` oppretter bare triggere og funksjoner. Ingen rader endres.
- Kontrollert før sperrene ble innført, på utviklingsdatabasen:
  - 1 risiko, ingen vurderinger, aksepter eller tiltak;
  - ingen vurderinger eller aksepter uten aktør;
  - ingen rader på tvers av kunder.
- Migreringen kan rulles tilbake. Det er prøvd på testdatabasen.

**Arkitekturfunn**

| # | Funn | Vurdering |
|---|---|---|
| A6 | `BusinessAreaGrants` leser rettighetsparene direkte fra rollene. Fratrekket av menneskeforbeholdte nøkler for integrasjoner (`CustomerPermissionService::has`) gjaldt derfor ikke der. En integrasjon med `risk.accept` i en rolle fikk nøkkelen i rollens fagområder. | Rettet for Risiko (`cc3a4e90`). Mål og KPI bruker samme klasse (`objective.measure`, `objective.delete`) og kontrolleres i steg 15. |
| A7 | Wiki-stegene brøt to E2E-tester i andre moduler: `risk-journey.spec.js` (`wiki.knowledge.handoff`, `cd42a7f2`) og oppryddingen i `ManagementReviewE2EFixture` (kildeadministrasjon i tjenesten, `be1aba2b`). Wiki-verifiseringen kjørte bare Wiki-spesifikasjonene. | Rettet i `f5e66bf6`. Når en delt rettighet endres, kjøres E2E for modulene som bruker den. |
| A8 | E2E-data i den felles E2E-kunden må kunne ryddes, også når den har historikk. | `RiskTestDataPurge` (bare `tests/Support`) slår av triggeren i sin egen transaksjon med en eksplisitt DDL-setning. Triggeren har ingen bakdør, og en test kontrollerer at ingen kode i `app/` gjør det samme. |
| A9 | En integrasjon slipper ikke inn i kundefrontenden (403). | Fase 1 må gå via tjenestene, ikke via webkontrollerne. |
| A10 | Tjenestene nekter med `HumanDecisionRefused`/`AuthorizationException` (403), `RiskHistoryProtected` (409) og `ValidationException` (422). | API-laget i fase 1 oversetter dem til sitt eget feilformat, som for Wiki (A1). |
| A11 | Aktørtriggerne for `risk_acceptances.accepted_by_user_id`/`revoked_by_user_id` og `risk_assessments.assessed_by` (§4.8) er ikke laget. | Planlagt i steg 19, felles for alle moduler. Tjenestevakten dekker det i mellomtiden. |
| A12 | `RiskReviewSchedule`-spesifikasjonen flyttet en vurdering bakover i tid med UPDATE. | Databasen nekter det nå. Spesifikasjonen registrerer i stedet vurderingen med datoen (`RiskE2EFixture::recordAssessmentMadeOn`). |

**Tester**

- `RiskHumanDecisionTest` (8):
  - integrasjonen får ingen menneskeforbeholdt nøkkel;
  - en beslutter kan ta alle fire beslutninger;
  - disse nektes alle fire, og ingenting skrives: integrasjon, API-kanal (delegert), inaktiv person, manglende nøkkel, annet fagområde, manglende synlighet, nøkler i to roller, System Owner uten rolle og annen kunde (både eksplisitt fagområde og «Alle»);
  - en risiko som er flyttet, vurderes der den står nå.
- `RiskHistoryProtectionTest` (6): B3 i tjeneste, HTTP, modell og SQL; lukking i stedet; uforanderlige vurderinger og aksepter; kundesletting; ryddestien.
- `RiskServiceAccessTest` (5): endring, tiltak og koblinger; integrasjon med `risk.edit`; nekting på alle elleve skrivinger; tiltak under feil risiko; lesetilgang til Kvalitet.
- Tre kaskadetester er skrevet om til B3: `RiskAssessmentTest`, `RiskAcceptanceTest` og `RiskTreatmentActionTest`.
- E2E `risk-delete-history.spec.js`.
- Kjøringer 2026-10-22:
  - PHP: alle testfiler som berører Risiko (27 filer, 253 tester), alle bestått.
  - JS: 2130 av 2130 bestått.
  - E2E første runde: alle Risiko-spesifikasjoner pluss Leverandør, Ledelsens gjennomgang og Mine oppgaver, der fiksturene er endret. 50 av 53 bestått. De tre feilene er A7 (to) og A12, alle rettet og kjørt på nytt med grønt resultat.
  - E2E etter siste commit: Risiko-spesifikasjonene, 15 av 16 bestått. `risk-access.spec.js` feilet én gang på et navigasjonskappløp i spesifikasjonen («interrupted by another navigation» etter lagring av rolle). Den besto 3 av 3 ved ny kjøring og i første runde, og den er ikke berørt av endringene.
- Mutasjonssjekket: vakt, tilgang, låst lesing, historikkregel, modellvakt, triggere, tiltakseierskap og Kvalitet-tilgang.

**Føring for API-fasen: AI-agenter i Risiko (besluttet 2026-10-22)**

- **Utgangspunkt:** en AI-agent (integrasjonsidentitet) skal kunne lese risikoer i fagområdene den er gitt, og opprette *forslag*.
- Den skal ikke automatisk få generell `risk.edit`.
- Et forslag blir en del av registeret først når en person med rett til det har tatt det i bruk.
- **Dagens modell er uendret i fase 0.** `risk.edit` er ikke menneskeforbeholdt. En integrasjon med en rolle som har `risk.edit`, kan derfor i dag endre, lukke og flytte risikoer og arbeide med tiltak og koblinger (`RiskServiceAccessTest::test_an_integration_with_risk_edit_may_edit`). Det er ikke det samme som at den skal få det.
- **Konsekvens for fase 1:**
  - Integrasjonsroller får ikke `risk.edit` som standard.
  - «Forslag» utformes som et eget begrep, for eksempel en egen status eller tabell for foreslåtte risikoer, vurderinger og tiltak, og tas i bruk av en person.
  - Om `risk.create` skal gi et forslag eller en registrert risiko når aktøren er en integrasjon, besluttes da.
  - Vurdering, aksept og sletting forblir menneskeforbeholdt, uansett rolle (§2c).

**Klar for videre integrasjon**

- Ja for lesing og for menneskelige beslutninger. De nektes i domenet for enhver ny inngang.
- Skrivinger som ikke er beslutninger, ligger også i tjenestene. Et API kan dermed gjenbruke dem uten å kopiere regler.
- Gjenstår før fase 1: A10 (feilformat) og steg 19 (aktørtriggere).

### 2d. Avvik og forbedringer (steg 10) – kartlegging og gjennomføring (2026-10-22)

**Kartlegging før steg 10**

| Handling | Rettighet | Kontrollert i | Merknad |
|---|---|---|---|
| Se, liste, søke | `improvement.view` i fagområdet | Domenet (`ImprovementCaseAccessService::visibleCases`) | Uendret. En skjult sak er 404. |
| Registrere | `improvement.edit` i valgt fagområde | Domenet (`ImprovementCaseCreator::create`) | Uendret. Gjelder også overføring fra Etterlevelse, Ledelsens gjennomgang og Leverandør. |
| Endre sak og årsaksanalyse | `improvement.edit` | **Bare kontrolleren**, som også skrev endringen | Regelen om at en avsluttet sak må gjenåpnes først, lå også bare i kontrolleren og uten lås. |
| Start behandling | `improvement.edit` | **Bare kontrolleren** | Tjenesten låste saken og kontrollerte overgangen. |
| Lukk, Avbryt og Gjenåpne | `improvement.close` (menneskeforbeholdt) | **Bare kontrolleren** | Tjenesten skrev historikk med aktør i samme transaksjon. |
| Effektverifisering | `improvement.close` | **Bare kontrolleren** | Tjenesten skrev en uforanderlig rad med aktør. |
| Slette sak | `improvement.delete` (menneskeforbeholdt) | **Bare kontrolleren**, som også slettet | `isDeletable()` i kontrolleren. |
| Tiltak: opprette, endre, slette, livsløp | `improvement.edit` | **Bare kontrolleren** | Ansvarlig person ble kontrollert i kontrolleren. |
| Kvalitet-kontekst | `improvement.edit` og lesetilgang til Kvalitet | **Bare kontrolleren** | |

**Gjennomført**

| Commit | Innhold |
|---|---|
| `45ece813` | En integrasjon får ingen menneskeforbeholdt avviksnøkkel via fagområder. Dette er A6 for Avvik. |
| `5079e978` | Beslutningene sjekkes i tjenestene: `close`, `cancel`, `reopen` og `verify` med vakten `IMPROVEMENT_CLOSE`, og `delete` (ny, flyttet fra kontrolleren) med `IMPROVEMENT_DELETE`. Deretter `canClose`/`canDelete` på saken under radlåsen. `start` sjekker `improvement.edit` på samme måte. |
| `6a6df31d` | Historikken kan ikke slettes, verken i SQL, via Eloquent eller via kaskade (A13). |
| `9059f765` | Endring av sak og årsaksanalyse, tiltak og Kvalitet-kontekst sjekker `improvement.edit` og regelen for avsluttet sak i tjenestene, under radlåsen. Dette er ikke menneskeforbeholdt, så en integrasjon med `improvement.edit` kan gjøre det. |

**Sikkerhetsregler**

1. Lukk, Avbryt, Gjenåpne, Effektverifisering og Slett går bare gjennom sine tjenester. Hver tjeneste:
   - kaller vakten først. Den nekter integrasjoner, inaktive personer og API-kanalen, også i delegert modus;
   - sjekker deretter beslutningsnøkkelen og `improvement.view` i sakens fagområde, på saken slik den står under radlåsen.
2. Andre kunders saker er utilgjengelige, også med «Alle». Kontrolleren svarer 404 og tjenesten 403.
3. System Owner uten rolle har ingen saksdata og ingen beslutninger. Det er uendret og nå testet i tjenesten.
4. Livsløpsreglene er uendret og ligger i tjenestene, under lås:
   - lukking krever at alle tiltak er avsluttet og alle fullførte tiltak er verifisert med effekt;
   - avbrytelse krever avsluttede tiltak;
   - en avsluttet sak og dens tiltak endres ikke før saken er gjenåpnet.
5. Hver statusendring for sak og tiltak og hver effektverifisering skrives med aktør, i samme transaksjon som endringen. Det gjorde den også før. Aktøren nulles når personen slettes, og raden blir stående.

**Historikk og sletting (A13)**

- Historikktabellene var bare beskyttet mot UPDATE.
- En DELETE i SQL fjernet statusendringer og effektverifiseringer. Sletting av en sak eller et tiltak, i SQL eller via Eloquent forbi `isDeletable()`, tok med seg hele historikken gjennom kaskaden.
- **Nå:**
  - En sak med statusendring eller tiltak kan ikke slettes, og et tiltak med statusendring eller verifisering kan ikke slettes. Det håndheves i modellen og i databasen.
  - De tre historikktabellene slettes bare sammen med saken eller tiltaket.
  - Kundesletting virker som før.
- **Ingen ny forretningsregel:** `isDeletable()` krevde allerede åpen eller planlagt status og ingen historikk. Saker og tiltak som er registrert ved en feil, slettes som før. Endringen hindrer bare omgåelse.
- **Konsekvens:** E2E-fiksturene for Avvik, Etterlevelse, navigasjon og kunnskapsoverføring rydder nå sine egne saker med `ImprovementTestDataPurge`. Det er samme mønster som `ComplianceE2EFixture` og `RiskTestDataPurge`.

**Migrering og data**

- `2026_10_22_000006_keep_improvement_history` oppretter bare triggere og funksjoner.
- Kontrollert på utviklingsdatabasen: ingen avviksdata (0 saker, tiltak, statusendringer og verifiseringer). Tallene er de samme før og etter.
- Migreringen kan rulles tilbake. Det er prøvd på testdatabasen.

**Arkitekturfunn**

| # | Funn | Vurdering |
|---|---|---|
| A13 | Historikktriggerne i Avvik dekket bare UPDATE. Sletting fjernet historikk, direkte eller gjennom kaskade. | Rettet (`6a6df31d`). Andre moduler med `*_status_changes` bør kontrolleres på samme måte når de tas: Kvalitet, Ledelsens gjennomgang, Leverandør og Mål/KPI. |
| A14 | Regelen om at en avsluttet sak må gjenåpnes før den endres, lå i kontrolleren og uten lås for endring av sak og årsaksanalyse. | Ligger nå i tjenesten under lås (`9059f765`). |
| A6 | Samme hull som i Risiko, i `ImprovementCaseAccessService`. | Rettet (`45ece813`). Rettet for Mål og KPI i steg 15 (`3a6131f5`, §2k). |

**Tester**

- `ImprovementHumanDecisionTest` (8):
  - integrasjonen får ingen menneskeforbeholdt nøkkel;
  - en beslutter kan ta alle fem beslutningene, med historikk under eget navn;
  - disse nektes, og ingenting skrives: integrasjon, API-kanal (delegert), inaktiv person, bare `improvement.edit`, annet fagområde, manglende synlighet, synlighet og nøkler i ulike fagområder, System Owner uten rolle og annen kunde (både eksplisitt fagområde og «Alle»);
  - en sak som er flyttet, vurderes der den står nå.
- `ImprovementHistoryProtectionTest` (7): sletting uten historikk som før; sak og tiltak med historikk nektes i tjeneste, modell og SQL; historikkrader kan ikke slettes eller endres; aktør på hver rad; kundesletting; ryddestien.
- `ImprovementServiceAccessTest` (6):
  - redigerer og integrasjon med `improvement.edit` kan;
  - nekting på alle ti skrivinger for personer uten rett og for andre kunder;
  - en avsluttet sak kan ikke bearbeides gjennom noen tjeneste;
  - ansvarlig person og lesetilgang til Kvalitet sjekkes i tjenesten.
- Mutasjonssjekket: vakt, tilgangsregler, låst lesing, triggere, modellvakter, regelen for avsluttet sak og kontrollen av ansvarlig person.
- Kjøringer 2026-10-22:
  - PHP: alle testfiler som berører Avvik (48 filer), 462 av 462 bestått. I tillegg Risiko-testene.
  - JS: 2130 av 2130 bestått.
  - Frontendbygg og Pint er rene.
  - E2E for Avvik, Etterlevelse, navigasjon og kunnskapsoverføring, der fiksturene er endret: 60 av 61 bestått.
    - Testen som feilet, er «Kravbibliotek» i `a11y-grc-compliance.spec.js`. Den feilet i innloggingen (`loginAs` ble stående på `/login`), før noen avviksfunksjon var tatt i bruk.
    - Ved ny kjøring besto spesifikasjonen 3 av 3.
    - Sporet gikk tapt fordi den nye kjøringen tømte `test-results`. Det er notert under KTF-4: kopier sporet før en ny kjøring.
  - En første E2E-kjøring ble startet fra feil mappe (uten konfigurasjon og med 8 parallelle arbeidere) og ble avbrutt. Resultatet er ikke brukt.

**Klar for senere API-integrasjon**

- Ja. Lesing, beslutninger og arbeid med saker og tiltak ligger i domenet med samme regler for enhver inngang.
- Gjenstår før fase 1: A10 (feilformat) og steg 19 (aktørtriggere for `improvement_cases.closed_by_user_id` og `improvement_action_verifications.verified_by_user_id`).
**Føring for API-fasen: AI-agenter i Avvik og forbedringer (besluttet 2026-10-22)**

- **Utgangspunkt:** en AI-agent (integrasjonsidentitet) skal kunne lese avvik og forbedringssaker i fagområdene den er gitt, og opprette *forslag* til forbedringer eller tiltak.
- Den skal ikke automatisk få generell `improvement.edit`.
- Et forslag blir en sak eller et tiltak først når en person med rett til det har tatt det i bruk.
- **Dagens modell er uendret i fase 0.** `improvement.edit` er ikke menneskeforbeholdt. En integrasjon med en rolle som har `improvement.edit`, kan derfor i dag endre saker og tiltak og registrere nye (`ImprovementServiceAccessTest::test_an_integration_with_improvement_edit_may_work_a_case`).
- **Konsekvens for fase 1:**
  - Integrasjonsroller får ikke `improvement.edit` som standard.
  - «Forslag» utformes som i Risiko (§2c). Det bør være samme begrep i begge moduler.
  - Lukk, Avbryt, Gjenåpne, Effektverifisering og Slett forblir menneskeforbeholdt, uansett rolle.

### 2e. Ledelsens gjennomgang (steg 11) – kartlegging og gjennomføring (2026-10-22)

**Kartlegging før steg 11**

- Modulen er ikke avgrenset av fagområder. Tilgang gis med nøklene `management_review.*` på kunden og modulen.
- `ManagementReviewAccessService` bruker `CustomerPermissionService::has()`. Fratrekket for integrasjoner gjelder derfor allerede. Hullet A6 fra Risiko og Avvik finnes ikke her.

| Handling | Rettighet | Kontrollert i | Merknad |
|---|---|---|---|
| Ferdigstill | `management_review.finalize` (menneskeforbeholdt) | **Bare kontrolleren** | Tjenesten låste, sjekket klarhet med ferdigstillerens egen tilgang, frøs grunnlaget og skrev hendelse. |
| Tillegg etter ferdigstilling | `management_review.finalize` | **Bare kontrolleren** | Tillegget og hendelsen var uforanderlige. |
| Slette utkast | `management_review.delete` (menneskeforbeholdt) | **Bare kontrolleren** | `isDeletable()` og slettehendelsen lå i tjenesten. |
| Utkast, konklusjon, deltakere, seksjoner, neste gjennomgang og eier | `management_review.edit` | **Bare kontrolleren** | Seksjonsvurdering sjekket allerede synlighet til seksjonen i tjenesten. |
| Beslutninger og tiltak: opprette, endre, slette, omfordele, overføre og koble | `management_review.edit` | **Bare kontrolleren** | Overføring til Avvik går via `ImprovementCaseCreator`, som sjekker fagområdet selv. |
| Oppfølging av tiltak (fullfør, avbryt, gjenåpne) | Tiltakets eier eller `management_review.edit` | `canFollowUp` bare i kontrolleren | |

**Gjennomført**

| Commit | Innhold |
|---|---|
| `34d4c8ba` | Ferdigstilling og tillegg bruker vakten `MANAGEMENT_REVIEW_FINALIZE`, og sletting bruker `MANAGEMENT_REVIEW_DELETE`. Deretter kjøres `canFinalizeReview`/`canDeleteReview` på den låste gjennomgangen. Det krever nøkkel, modul og visning i personens egen kunde. |
| `3d89f627` | Alle ordinære skrivinger sjekker `management_review.edit` i tjenesten, på den låste gjennomgangen (`lockedEditable`). Oppfølging av tiltak sjekker `canFollowUp` for egen kunde. Tilgang sjekkes før eiervalidering. |
| `6f5431fb` | En gjennomgang kan bare bli ferdigstilt med frosset grunnlag og ferdigstiller (A15). |

**Sikkerhetsregler**

1. Ferdigstilling, tillegg og sletting går bare gjennom tjenestene:
   - vakten nekter integrasjoner, inaktive personer og API-kanalen, også i delegert modus;
   - deretter kreves riktig nøkkel for en gjennomgang i egen kunde.
2. Andre kunders gjennomganger er utilgjengelige. Det gjelder også kundens System Owner. Kontrolleren svarer 404 og tjenesten 403.
3. **Uendret regel:** System Owner har alle nøkler i dette domenet, som ikke krever eksplisitt tildeling.
   - System Owner består vakten og tilgangskontrollen.
   - Klarhet beregnes likevel med ferdigstillerens egen tilgang. Uten en rolle som når de beskyttede seksjonene, er gjennomgangen ikke klar for System Owner, slik som før.
   - Testen viser begge deler.
4. Ordinær redigering er ikke menneskeforbeholdt. En integrasjon med `management_review.edit` kan redigere et utkast. Ferdigstilling, tillegg og sletting kan den aldri.

**Historikk og dataintegritet**

| Spørsmål | Svar |
|---|---|
| Er en ferdigstilt gjennomgang et uforanderlig øyeblikksbilde? | Ja. Databasen låste allerede gjennomgangen, beslutningene, grunnlaget, tilleggene og hendelsene. Bare eier, neste gjennomgang og oppfølging av tiltak kan endres, og hver slik endring er en hendelse. **Nytt:** overgangen til ferdigstilt krever grunnlag og ferdigstiller (A15). |
| Registreres tillegg uten å endre tidligere beslutninger? | Ja. Et tillegg er en egen, uforanderlig rad og en hendelse. Konklusjon og beslutninger står urørt (testet). |
| Kan ferdigstilte gjennomganger eller beslutninger slettes indirekte? | Nei. Det gjelder ferdigstilt gjennomgang, beslutninger i en ferdigstilt gjennomgang og beslutninger som er overført til Avvik. En sak i Avvik som en beslutning peker på, kan heller ikke slettes. Bare sletting av kunden tar alt med seg. |
| Kan historikk endres eller slettes direkte i databasen? | Nei. Det gjelder grunnlaget, tilleggene og hendelsene. Når et utkast slettes, blir slettehendelsen stående (testet). |
| Har endringene riktig aktør og tidspunkt? | Ja for hendelser, tillegg, ferdigstilling, oppfølging og seksjonsvurderinger. Deltakere i et utkast har ingen egen aktør. Det er godtatt, fordi et utkast ikke er historikk og blir låst ved ferdigstilling. |

**Migrering og data**

- `2026_10_22_000007_finalize_management_review_only_with_its_snapshot` legger bare til en trigger på overgangen.
- Utviklingsdatabasen har ett utkast og ingen ferdigstilt gjennomgang. Ingen rader endres.
- Migreringen kan rulles tilbake. Det er prøvd på testdatabasen.

**Arkitekturfunn**

| # | Funn | Vurdering |
|---|---|---|
| A15 | En gjennomgang kunne settes fra utkast til ferdigstilt i SQL uten frosset grunnlag og uten ferdigstiller. CHECK krevde bare dato og navn. | Rettet (`6f5431fb`). |
| A16 | Tillegg bruker samme nøkkel som ferdigstilling (`management_review.finalize`). Det finnes ingen egen nøkkel for å registrere tillegg. | Godtatt. Tillegg er en formell rettelse av det som ble besluttet, og ble behandlet slik i planen (§4.4). Kan vurderes skilt ut senere. |
| A17 | **Etterlevelse (gjelder steg 13):**<br>– `compliance_audit_check_requirements` peker på `compliance_audit_requirements` (`audit_id`, `requirement_id`) med `NO ACTION`.<br>– Når en revisjon slettes, går både omfanget og kontrollhandlingene med i kaskaden. Om slettingen lykkes, avhenger av rekkefølgen PostgreSQL utløser kaskadene i, og den følger triggerens OID.<br>– I utviklingsdatabasen, som er migrert over tid, går det bra. I en database som er migrert på nytt (E2E-databasen, og produksjon i Azure), feiler slettingen med brudd på fremmednøkkel.<br>– Funnet ble avdekket av det isolerte E2E-miljøet. | **Ikke rettet i modulen.** Etterlevelse startes ikke før det er godkjent. E2E-fiksturen sletter koblingene eksplisitt først. Det bør vurderes i steg 13 om sletting av en revisjon (og sletting av en kunde) i produksjon rammes. Mulig retting: `ON DELETE CASCADE` på koblingen, eller sletting i fast rekkefølge i tjenesten. |
| A6 | Finnes ikke her. Tilgangstjenesten bruker `CustomerPermissionService::has()`. | – |

**Tester**

- `ManagementReviewHumanDecisionTest` (7):
  - en leder kan ta alle tre beslutningene, med ferdigstiller, grunnlag og forfatter registrert;
  - System Owner består tilgangskontrollen og møter deretter klarhetskravet som før;
  - disse nektes, og ingenting skrives: integrasjon, API-kanal (delegert), inaktiv person, lese- og redigeringstilgang uten beslutningsnøkkel, bare lesetilgang, nøkler uten visning, og leder eller System Owner i en annen kunde.
- `ManagementReviewServiceAccessTest` (5):
  - redigerer og integrasjon med `management_review.edit` kan;
  - nekting på alle elleve skrivinger for personer uten rett og for andre kunder;
  - en leser kan ikke opprette;
  - oppfølging av tiltak er bare for eieren eller en redigerer.
- `ManagementReviewHistoryIntegrityTest` (5): overgangsregelen; et uforanderlig øyeblikksbilde med tillegg ved siden av; hendelser med aktør og tid; slettehendelsen; kundesletting.
- Mutasjonssjekket: vakt, tilgangsregler for beslutninger og redigering, oppfølging av tiltak (også kundekontrollen) og den nye triggeren.
- Kjøringer 2026-10-22:
  - PHP: alle testfiler som berører Ledelsens gjennomgang (11 filer), 94 av 94 bestått.
  - JS: 2130 av 2130 bestått. En første kjøring, samtidig med E2E, rapporterte 970 bestått og 0 feilet. **Forklart senere (§2g):** hele `resources/js/Pages` (1160 tester) ble ikke funnet. Ny kjøring alene ga hele suiten.
  - Frontendbygg og Pint er rene.
  - E2E for Ledelsens gjennomgang, kunnskapsoverføring og Mine oppgaver: 6 av 7 bestått.
    - Feilen er en uventet ny innlasting av siden midt i reisetesten. Den er dokumentert med spor under KTF-4 og er ikke knyttet til endringene.
    - `management-review.spec.js` besto 4 av 4 ved ny kjøring.

**Klar for senere API-integrasjon**

- Ja. Beslutninger, redigering og oppfølging har samme regler i domenet for enhver inngang.
- Gjenstår før fase 1: A10 (feilformat) og steg 19 (aktørtriggere for `management_reviews.finalized_by_user_id` og `management_review_amendments.created_by_user_id`).

**Føring for API-fasen: AI-agenter i Ledelsens gjennomgang (besluttet 2026-10-22)**

- **Utgangspunkt:** en AI-agent (integrasjonsidentitet) skal ikke få generell `management_review.edit` som standard.
- **Senere** kan agenter, gjennom avgrensede API-operasjoner:
  - lese relevant informasjon, for eksempel grunnlaget og tidligere gjennomganger i kundemiljøet;
  - forberede forslag til en gjennomgang, til beslutninger eller til tiltak.
- Et forslag blir en del av gjennomgangen først når en person med `management_review.edit` tar det i bruk.
- Ferdigstilling, tillegg og sletting forblir menneskeforbeholdt, uansett rolle.
- **Dagens modell er uendret i fase 0.** `management_review.edit` er ikke menneskeforbeholdt. En integrasjon med en rolle som har nøkkelen, kan i dag redigere et utkast (`ManagementReviewServiceAccessTest::test_an_integration_with_management_review_edit_may_edit_a_draft`).
- **Konsekvens for fase 1:**
  - Integrasjonsroller får ikke `management_review.edit` som standard.
  - «Forslag» utformes som i Risiko og Avvik (§2c og §2d).

### 2f. Kvalitet (steg 12) – kartlegging og gjennomføring (2026-10-22)

**Kartlegging før steg 12**

- Modulen er ikke avgrenset av fagområder. Tilgang gis med nøklene `quality.*` på kunden og modulen.
- System Owner har alle `quality.*`-nøkler uten rolle. Kvalitet er ikke et domene som krever eksplisitt tildeling.
- Alle rettighetskontroller lå i `QualityController` (`may()`/`authorizePermission`). Tjenestene sjekket bare at radene tilhørte kunden de fikk oppgitt.
- Godkjenning, godkjenning av AI-forslag og sletting tok `?User`. En aktør på `null` ga godkjente versjoner uten godkjenner.

| Handling | Rettighet | Kontrollert i | Merknad |
|---|---|---|---|
| Godkjenne prosessflyt (`approve`) | `quality.approve` (menneskeforbeholdt) | **Bare kontrolleren** | Tjenesten låste arbeidsversjonen og skrev en uforanderlig versjon (`quality_process_revisions`). |
| Ta i bruk AI-forslag (`adoptFlowProposal`, `acceptFlowChange`) | `quality.edit` | **Bare kontrolleren** | `store(..., SOURCE_AI)`. |
| Slette prosess, dokument eller kontroll (`deleteItem`) | `quality.delete` (menneskeforbeholdt) | **Bare kontrolleren** | Wiki-sidene prosessen har produsert, slettes først når det er valgt (Wikis egen regel, F3a). |
| Slette eller forkaste flyt (`blueprints->delete`) | `quality.delete` | **Bare kontrolleren** | Etter første godkjenning er dette «Forkast arbeidsversjon». De godkjente versjonene beholdes. |
| Opprette dokument, laste opp fil, skrive artikkel fra aktivitet | `quality.create` | **Bare kontrolleren** | Artikkelen overføres til Wiki. Wiki sjekker sin halvdel (`wiki.knowledge.handoff`) selv. |
| Alle andre skrivinger: metadata, struktur, kontrollfelt, relasjoner, Wiki-koblinger, dokumentkoblinger, evidens, kontroller på aktiviteter, verktøy, avklaringer, manuell flyt | `quality.edit` | **Bare kontrolleren** | En kontroll som registreres fra en aktivitet, opprettes under `quality.edit`, ikke `quality.create`. |

**Gjennomført**

| Commit | Innhold |
|---|---|
| `f958fe57` | Godkjenning (`QUALITY_FLOW_APPROVE`), godkjenning av AI-forslag (`QUALITY_FLOW_ADOPT`) og sletting (`QUALITY_DELETE`) går gjennom vakten og deretter riktig nøkkel i tjenestene. Aktøren er påkrevd. Ny `QualityAccessService`: egen kunde, Kvalitet-modulen og nøkkelen. Avklaringer sjekker `quality.edit`. |
| `686dba14` | Alle ordinære skrivinger sjekker nøkkelen i tjenesten, med påkrevd aktør. `linkDocument` godtar `quality.create` eller `quality.edit`, som i grensesnittet. Kontroll fra aktivitet går via `createActivityControl` under `quality.edit`. |
| `60fd0d16` | Godkjente prosessversjoner bevares (A18): en godkjent prosess settes til «Utgått» i stedet for å slettes – i menyen, kontrolleren, tjenesten og databasen. Versjonene kan verken endres eller slettes. |

**Sikkerhetsregler**

1. Godkjenning, godkjenning av AI-forslag og sletting går bare gjennom tjenestene:
   - vakten nekter integrasjoner, inaktive personer og API-kanalen, også i delegert modus;
   - deretter kreves riktig nøkkel i personens egen kunde, med Kvalitet-modulen.
2. Å ta i bruk et AI-forslag er en menneskelig beslutning, selv om nøkkelen (`quality.edit`) ikke er menneskeforbeholdt. Manuell lagring av en arbeidsversjon er ordinær redigering, og en integrasjon med `quality.edit` kan gjøre det (testet). Godkjenning krever alltid en person.
3. AI-forslag skilles fra godkjenning:
   - et forslag lagres aldri før en person tar det i bruk;
   - en versjon som er tatt i bruk, er et utkast til den er godkjent;
   - kilden (`ai` eller `manual`) følger versjonen inn i den godkjente versjonen.
4. Andre kunders data er utilgjengelige. Det gjelder også kundens System Owner. Uten Kvalitet-modulen gir nøklene ingen tilgang i tjenestene (nytt; kontrolleren stolte på ruten).
5. Koblinger mot Enterprise Wiki omgår ikke Wiki:
   - sletting av produserte Wiki-sider går gjennom `EnterpriseWikiPageDeletionService` (F3a);
   - artikler fra aktiviteter går gjennom `WikiKnowledgeHandoffService`, som sjekker `wiki.knowledge.handoff`;
   - Kvalitet sjekker sin egen halvdel (`quality.create`) i tjenesten, også for AI-utkastet, som koster AI selv om ingenting lagres.
6. **Uendret regel:** System Owner har alle `quality.*`-nøkler og består vakten og tilgangskontrollen i egen kunde.

**Historikk og dataintegritet**

| Spørsmål | Svar |
|---|---|
| Bevares tidligere godkjente prosessversjoner? | **Nå ja.** Før fulgte alle godkjente versjoner med når prosessen ble slettet, fordi kaskaden fra `quality_items` gikk forbi modellens vakt (A18). En prosess med en godkjent versjon kan ikke slettes. Den settes til «Utgått», og versjonene står. |
| Kan godkjente versjoner endres eller slettes direkte i databasen? | Nei (ny trigger). Det eneste som kan endres, er fremmednøkkelen til en slettet bruker (`approved_by_user_id` blir `NULL`). Navnet på den som godkjente, står i `approved_by_name`. |
| Kan historikk slettes indirekte? | Ikke for godkjente versjoner. Bare sletting av kunden tar dem med. «Forkast arbeidsversjon» rører bare arbeidsversjonen (testet). Se A19 om kontroller med evidens. |
| Har godkjenninger riktig aktør og tidspunkt? | Ja. Godkjenner, navn og tidspunkt er påkrevd i tjenesten. Aktørtriggeren for `quality_process_revisions.approved_by_user_id` og `quality_process_blueprints.approved_by_user_id` hører til steg 19. |
| Samtidighet | Godkjenning låser arbeidsversjonen (`lockForUpdate`), og et dobbeltklikk gir ingen ny versjon. Trigger på prosessen fanger en godkjenning som kommer mellom kontrollen og slettingen. Se A21 og A22. |

**Migrering og data**

- `2026_10_22_000008_keep_approved_quality_process_versions` legger til triggere på `quality_items` (DELETE) og `quality_process_revisions` (UPDATE og DELETE).
- Utviklingsdatabasen har 5 godkjente versjoner fordelt på 3 prosesser. Ingen rader endres. De tre prosessene kan etter dette bare settes til «Utgått».
- Migreringen kan rulles tilbake. Det er prøvd på testdatabasen (også som mutasjonssjekk).
- Kjørt på utviklings-, test- og E2E-databasen.

**Arkitekturfunn**

| # | Funn | Vurdering |
|---|---|---|
| A18 | Godkjente prosessversjoner fulgte med når prosessen ble slettet, i SQL eller gjennom `QualityItemService::deleteItem`. Modellens vakt (`QualityProcessRevision`) gjelder ikke kaskader. | Rettet (steg 12, del 3). |
| A19 | En kontroll med evidens kan slettes med `quality.delete`. Evidensen (`quality_item_documents`) og koblingene fra Risiko (`risk_controls`), Etterlevelse (`compliance_requirement_controls`), Avvik og Mål/KPI følger med i kaskaden. Det samme gjelder koblingene til en prosess som ikke er godkjent. | **Fulgt opp, se §2g.** Kontroller med registrert evidens settes til «Utgått» og slettes ikke (`7ca3b7a5`). Koblinger fra Risiko og Etterlevelse er en åpen beslutning. |
| A20 | Kvalitet har ingen hendelseslogg for dokumenter. Hvem som endret status, eier eller innhold, og når, registreres ikke. Bare `last_reviewed_by_user_id`, godkjente versjoner og opphavet til artikler finnes. | **Vurdert, se §2g.** Anbefaling om uforanderlig hendelseslogg. Ikke implementert. |
| A21 | `deleteItem` sletter de produserte Wiki-sidene før prosessen, utenfor én transaksjon. Hvis slettingen av prosessen deretter nektes (for eksempel fordi den ble godkjent i mellomtiden), er sidene borte og prosessen står. | **Rettet, se §2g** (`67e3cf2b`). |
| A22 | `acceptFlowChange` sjekker `base_hash` og lagrer deretter i to steg, uten lås mellom. En samtidig endring i mellomtiden kan bli overskrevet. | **Rettet, se §2g** (`37f84480`). |
| A23 | AI-tolkning og forslag til endring av flyt (`interpretFlow`, `proposeFlowChange`) skriver ikke, men bruker AI. De sjekkes bare i kontrolleren, og tjenestene tar ingen aktør. | **Vurdert, se §2g.** Oversikt og anbefaling. Ikke implementert. |
| A24 | To testfiler (`QualityToolTest`, `QualityActivityControlTest`) feilet allerede på `HEAD` fordi en redigerer i Kvalitet slettet et Wiki-dokument direkte. Det krever Wiki-kildeadministrasjon etter `be1aba2b`. | Rettet i testene: System Owner sletter dokumentet. Regelen i Wiki er riktig. |
| A25 | **Det isolerte E2E-miljøet (oppgave 1):** 4 Kvalitet-spesifikasjoner feilet der, uavhengig av steg 12.<br>– Tre trengte AI-tolkning, og miljøet har med vilje ingen OpenAI-nøkkel.<br>– Én trengte en bruker med `quality.view`, som før kom fra rollene i utviklingsdatabasen.<br>– De var ikke med i verifiseringen av miljøet (`cef890b2`). | Rettet i spesifikasjonene.<br>– `quality-flow-interpret` sier til siden at AI er tilgjengelig, slik `quality-flow-clarify` gjør.<br>– `quality-flow-clarify-sticks` og `quality-flow-describe-without-flow` hopper over med begrunnelse når serveren ikke har AI. De gjør eller trenger ekte AI-kall og er ikke verifisert i miljøet.<br>– `quality-new-process` gir og fjerner selv en rolle med bare `quality.view`.<br>Se `docs/testing/e2e-environment.md`, «Begrensninger». |
| A26 | Radmenyen i Kvalitet lukkes ved rulling. Når raden ligger under synsfeltet, ruller Playwrights klikk den fram, og menyen lukkes rett etter at den åpnet. | Rettet i `quality-process-row-actions`: raden rulles fram, og testen venter to bildeoppdateringer før klikket (bekreftet: feiler uten, besto 3 av 3 med). En person klikker på en synlig rad, så det er ikke rettet i komponenten. |
| A6 | Finnes ikke her. `QualityAccessService` bruker `CustomerPermissionService::has()`, så `quality.approve` og `quality.delete` holdes unna integrasjoner. | – |

**Tester**

- `QualityHumanDecisionTest` (7):
  - en person med nøklene tar alle beslutningene, og godkjenningen bærer navnet deres;
  - en integrasjon kan lagre en arbeidsversjon manuelt, men ikke ta i bruk AI-forslag, godkjenne eller slette;
  - disse nektes, og ingenting skrives: API-kanal (delegert), inaktiv person, lesetilgang, redigering uten godkjennings- og slettenøkkel, en annen kundes person med alle nøkler og en annen kundes System Owner, og en kunde uten Kvalitet-modulen.
- `QualityServiceAuthorizationTest` (5):
  - 24 skrivinger kalles direkte på tjenestene;
  - nektes for en leser, for en annen kundes redigerer og for en kunde uten modulen, og ingenting skrives;
  - hver nøkkel åpner nøyaktig sine egne skrivinger;
  - fil kan kobles med begge nøklene, og kontroll fra aktivitet med `quality.edit`.
- `QualityProcessVersionIntegrityTest` (7): tjenesten nekter før Wiki røres; «Utgått» beholder versjonene; menyen og kontrolleren; «Forkast arbeidsversjon»; databasen; slettet godkjenner; kundesletting.
- Mutasjonssjekket:
  - vakten og nøkkelen for hver beslutning, modul- og kundekontrollen;
  - hver av de 27 tilgangskontrollene i tjenestene. Alle fanges unntatt to, som dekkes av en indre kontroll på samme nøkkel (`createActivityControl` inne i `add()`);
  - kontrollen i tjenesten, kontrollen i kontrolleren, flagget i radene og triggerne.
- Kjøringer 2026-10-22:
  - PHP: alle testfiler som berører Kvalitet (31 filer), 498 av 498 bestått.
  - JS: rapportert som 970 av 970 bestått. **Feil:** det var en delvis kjøring der `resources/js/Pages` manglet (§2g). Hele suiten er 2130 tester, og 2130 av 2130 består (2026-10-22, etter `ef1e0ee6`).
  - Frontendbygg og Pint på berørte filer er rene.
  - E2E i det isolerte miljøet, på en nullstilt database: alle Kvalitet-spesifikasjoner, `compliance-quality`, `risk-controls` og `risk-context` (24 filer, 54 tester).
    - To kjøringer etter hverandre: 51 bestått, 3 hoppet over, 0 feilet. Telt fra Playwrights JSON-rapport.
    - De 3 som hoppes over, trenger ekte AI i serveren, og miljøet har ingen nøkkel (A25). De er **ikke verifisert** i dette steget.
    - Ny test: `quality-process-row-actions` viser at en godkjent prosess ikke tilbyr sletting.
    - **Rettelse:** En tidligere telling («48 bestått, 1 hoppet over») var feil. Filteret fanget ikke linjen med feilede tester. 4 spesifikasjoner feilet allerede da (A25).

**Klar for senere API-integrasjon**

- Ja for beslutninger og ordinære skrivinger: samme regler i domenet for enhver inngang.
- Gjenstår før fase 1: A10 (feilformat), A23 (§2g) og steg 19 (aktørtriggere for godkjennerkolonnene i Kvalitet). A22 er rettet.
- **Føring, som for Risiko, Avvik og Ledelsens gjennomgang:** en AI-agent får ikke `quality.edit` som standard. Forslag til flyt kan senere komme gjennom avgrensede API-operasjoner, men blir en del av prosessen først når en person tar dem i bruk. Godkjenning og sletting forblir menneskeforbeholdt.

### 2g. Oppfølging av A19–A23 og JS-grunnlaget (2026-10-22)

**Beslutning (2026-10-22):** Kontroller med registrert evidens eller relevant historikk skal ikke kunne slettes permanent gjennom ordinær brukerfunksjonalitet. A21 prioriteres. A20 og A23 vurderes og dokumenteres, men implementeres ikke nå.

| Commit | Innhold |
|---|---|
| `ef1e0ee6` | JS-testene: `npm run test:unit` finner testfilene selv og skriver ut antallet per katalog (se «JS-grunnlaget»). |
| `67e3cf2b` | A21: prosessen og Wiki-sidene den har produsert, slettes i én transaksjon. |
| `37f84480` | A22: et AI-forslag tas bare i bruk på versjonen det ble laget for. |
| `7ca3b7a5` | A19: en kontroll med registrert evidens settes til «Utgått» og slettes ikke. |
| `03428309` | E2E-fiksturer rydder Kvalitet-data gjennom en testhjelper som slår av historikktriggerne i sin egen transaksjon (`QualityTestDataPurge`). |

#### A21 – sletting på tvers av Kvalitet og Wiki (rettet)

- **Før:** Wiki-sidene ble slettet og committet før prosessen. Alt som deretter stoppet slettingen av prosessen, ga en halv sletting: sidene borte og prosessen stående. Det kunne være en godkjenning som kom imellom, at Wiki feilet sent, eller en annen feil.
- **Nå:** `QualityItemService::deleteItem` gjør dette i én transaksjon:
  1. låser prosessraden (`FOR UPDATE`);
  2. leser reglene på nytt under låsen (`deletionBlocker()`);
  3. sletter sidene gjennom `EnterpriseWikiPageDeletionService`, og deretter prosessen.
- Feiler noe etter at sidene er slettet, rulles sidene tilbake.
- **Rettighetene i begge moduler er uendret:**
  - `quality.delete` og vakten sjekkes før transaksjonen.
  - Wikis egen regel (`wiki.delete` og eierskap til hver side) sjekkes inne i `deletePages`, under samme transaksjon.
- **Samtidighet:**
  - En godkjenning av samme prosess setter inn en versjon. Den trenger en lås på prosessen og venter derfor til slettingen er ferdig. Deretter feiler den, fordi prosessen er borte.
  - En godkjenning som ble committet først, fanges av kontrollen under låsen og av databasetriggeren.
  - Det samme gjelder en ny artikkel fra en av prosessens aktiviteter.
- **Etter commit, og bare da:** grafprojeksjonene (begge moduler) og Wikis logglinje «Deleted Wiki pages». En tilbakerullet sletting logges ikke lenger som utført.
- **Historikk:** godkjente versjoner og prosesser med evidens beskyttes som før (steg 12 og A19). Kildedokumentene røres aldri.
- **Tester:** `QualityWikiDeletionAtomicityTest` (5) legger inn feil rett etter at Wiki har slettet sidene:
  - en godkjenning imellom, slik at databasen nekter å slette prosessen;
  - at Wiki feiler lukket, slik at dialogen sier fra;
  - en vilkårlig annen feil;
  - at Wikis egen rettighet nekter.

  Ingenting slettes i noen av tilfellene. Uten transaksjonen feiler 3 av 5.
- **Gjenstår:** En side som en Wiki-kjøring lager fra prosessens kildedokument *etter* at slettingen startet, er ikke med i valget og blir stående. Det er ingen inkonsistens: siden er vanlig Wiki-kunnskap, og opphavsraden følger prosessen.
- **Ærlig om låsen:** Med én databaseforbindelse kan ikke testene vise låsen (mutasjonen overlever). Det er transaksjonen og triggeren som sikrer at slettingen er hel. Låsen gjør bare at en samtidig godkjenning venter eller avvises før Wiki røres.

#### A22 – samtidige endringer ved AI-forslag (rettet)

- **Før:**
  - «Godta endringer» sammenlignet versjonen og lagret deretter i et eget steg, uten lås.
  - «Bruk denne prosessflyten» (tolkning) hadde ingen versjonskontroll. En flyt som var lagret i mellomtiden, ble overskrevet uten varsel.
- **Nå:**
  - Hvert AI-forslag bærer avtrykket av arbeidsversjonen det ble laget for (`QualityProcessBlueprintService::versionHash()`, én definisjon). Avtrykket leses før modellen spørres.
  - `store()` krever avtrykket for `SOURCE_AI` og sammenligner det under lås, i samme transaksjon som skrivingen.
  - Låserekkefølgen er den samme som i `approve()`: arbeidsversjonen først, og prosessen bare når det ikke finnes noen arbeidsversjon ennå. Da kan ikke de to vente på hverandre i motsatt rekkefølge.
- **Hvis flyten er endret:**
  - Forslaget tas ikke i bruk, og den nyere flyten beholdes.
  - Brukeren får meldingen «Prosessflyten er endret etter at forslaget ble laget, så forslaget ble ikke tatt i bruk. Den lagrede flyten er beholdt. Se over den og lag et nytt forslag ut fra flyten slik den er nå.»
  - Beskrivelsen står igjen i feltet, så et nytt forslag mot gjeldende flyt er ett klikk unna.
- **Tester:** `QualityFlowProposalVersionTest` (5), og tolkningstesten kontrollerer avtrykket på forslaget. Mutasjonssjekket: uten kontrollen feiler 3 tester, og med feil avtrykk på forslaget feiler 4.
- **Ikke endret, nytt funn A27:** Manuell lagring av flyten er fortsatt «siste lagring vinner». To personer som redigerer samtidig, kan overskrive hverandre. Det er ordinær redigering og ikke en del av A22.

#### A19 – sletting av kontroller med evidens (gjennomført; koblinger åpne)

**Kartlegging av hva en sletting tok med seg:**

| Det som peker på kontrollen | Ved sletting | Synlig for Kvalitet? |
|---|---|---|
| Evidens (`quality_item_documents`, `evidence`) | Slettet (kaskade) | Ja, Kvalitets egen |
| Plasseringer på aktiviteter (`quality_activity_controls`) | Slettet | Ja, Kvalitets egen struktur |
| Verktøy (`quality_item_documents`, `tool`) | Slettet (selve filen og verktøyet står) | Ja |
| Risiko (`risk_controls`) | Slettet (risikoen står) | **Nei.** Besluttet tidligere (`0798aa5`, `RiskControlLinkTest`) |
| Krav i Etterlevelse (`compliance_requirement_controls`) | Slettet (kravet står) | Nei. Etterlevelse krever eksplisitt tildeling |
| Revisjonsfunn (`compliance_audit_findings.control_item_id`) | Satt til `NULL` (funnet står) | Nei |

**Status:** «Utgått» (`QualityItem::STATUS_RETIRED`) finnes allerede og gjør det beslutningen ber om. Derfor er ingen ny status innført.
- En utgått kontroll kan ikke plasseres på nye aktiviteter.
- Den tilbys ikke i velgerne i Etterlevelse og revisjon, i oppmerksomhetslisten eller i Mine oppgaver.
- Alt som allerede er knyttet til den, blir stående.

**Gjennomført (`7ca3b7a5`):**
- En kontroll med registrert evidens kan ikke slettes. Den settes til «Utgått», og evidensen, plasseringene og koblingene beholdes.
- Regelen gjelder i radmenyen (grunnen vises i stedet for «Slett kontroll»), kontrolleren, tjenesten (også under låsen) og databasen (migrering `2026_10_22_000009`).
- Bare sletting av hele kunden tar kontrollen med.
- **Data:** 2 av 114 kontroller i utviklingsdatabasen har evidens. Ingen rader er endret.

**Åpen beslutning – koblinger fra Risiko og Etterlevelse:** Disse blokkerer ikke sletting, som før. Det er en konflikt med en tidligere beslutning:
- Kvalitet skal ikke få vite om koblinger fra Risiko, og sletting i Kvalitet skal ikke nektes på grunn av en kobling brukeren ikke ser (`RiskControlLinkTest::test_nothing_about_the_link_reaches_kvalitet`).
- Etterlevelse krever eksplisitt tildeling, så en Kvalitet-bruker ser heller ikke kravkoblinger og revisjonsfunn.
- Å nekte med en melding som nevner koblingen, ville avsløre at den finnes.

Alternativer:
1. **Behold som nå** (anbefalt inntil videre). Koblingen fjernes når kontrollen slettes, men risikoen, kravet og funnet står.
2. **Nekt med en nøytral melding** («Kontrollen er i bruk andre steder og kan ikke slettes. Sett den til «Utgått».»). Det avslører bare at noe peker på kontrollen, ikke hva. Det opphever den tidligere beslutningen for Risiko, og testene må endres.
3. **Nekt bare når brukeren selv kan se koblingen**, og behold kaskaden ellers. Det gir ulik oppførsel for ulike brukere, og det fraråder jeg.

**Gjenstår:** Evidens kan fortsatt fjernes enkeltvis («Fjern», en eksisterende funksjon for å rette feilregistrering). Når all evidens er fjernet, kan kontrollen slettes. Det lukkes best med A20: å fjerne evidens blir en tilbaketrekking som står i historikken.

**Tester:** `QualityControlHistoryTest` (6). Mutasjonssjekket: regelen i tjenesten og triggeren.

#### A20 – revisjonshistorikk i Kvalitet (anbefaling, ikke implementert)

**I dag:**
- Godkjente flytversjoner er uforanderlige (`quality_process_revisions`, steg 12).
- Opphavet til artikler fra aktiviteter er registrert (`quality_activity_wiki_pages`).
- Ellers registreres bare `last_reviewed_by_user_id`, `created_by_user_id` og `generated_by_user_id`/`approved_by_user_id`.
- Statusendringer, eierskifte, sletting, evidens som fjernes og koblinger som fjernes, etterlater ingen spor.

**Anbefaling:** én tabell, `quality_item_events`, etter samme mønster som `management_review_events` og historikktabellene i Avvik:
- en rad per hendelse, med `customer_id`, `quality_item_id` (beholdes etter sletting), type og tittel i øyeblikksbilde, `event`, `actor_user_id`, `actor_name`, `occurred_at` og `metadata` (fra/til);
- skrevet i tjenestene, i samme transaksjon som endringen;
- en databasetrigger som nekter UPDATE og DELETE, unntatt når kunden slettes, og fremmednøkkelen til en slettet bruker (`actor_user_id` blir `NULL`).

**Hva som bør ha uforanderlig historikk:**

| Prioritet | Endring | Hvorfor |
|---|---|---|
| Må | Statusendring (inkludert «Utgått» og tilbake) | Når et styrende dokument eller en kontroll ble tatt ut av bruk, og av hvem |
| Må | Sletting av element, med tittel, type og kode i øyeblikksbilde | Raden forsvinner, så hendelsen er det eneste sporet |
| Må | Evidens registrert og fjernet (fjerning blir tilbaketrekking med begrunnelse, og raden står) | Evidens er beviset for at kontrollen er utført, og lukker restpunktet i A19 |
| Må | Flyt: AI-forslag tatt i bruk (med versjonsavtrykk), arbeidsversjon forkastet, flyt slettet | Godkjenningen er allerede en uforanderlig versjon. Det som leder fram til den og bort fra den, er det ikke |
| Må | Eierskifte og registrert gjennomgang (dato, intervall, av hvem) | Ansvar og gjennomgang er det revisjoner spør etter (ISO 9001, 7.5) |
| Bør | Kontroll plassert på eller fjernet fra en aktivitet; styrende dokument koblet eller fjernet (`governs`) | Viser hvordan styringen av prosessen har endret seg |
| Bør | Endring av tittel, kode og formål (fra/til) | Sporbarhet for innholdet i et styrende dokument |
| Kan | Struktur (steg, inn/ut, sjekkpunkter), Wiki-koblinger, dokumentkoblinger, verktøy | Arbeidsinnhold. Hendelse uten fullt innhold holder |
| Ikke | Hver lagring av arbeidsversjonen av flyten; avklaringer | Arbeidsversjonen er et utkast. Godkjente versjoner er historikken |

Dette bør gjøres før API-fasen åpner Kvalitet for integrasjoner. Da registreres også hva en integrasjon har endret, med aktør.

#### A23 – AI-operasjoner som bare kontrolleres i kontrolleren (oversikt, ikke implementert)

Kartlagt 2026-10-22 for alle operasjoner i `config/ai_operations.php`. Stikkprøver er kontrollert i koden. Forklaring til kolonnen «Hvor»:
- **C:** tilgangen sjekkes bare i kontrolleren, og tjenesten tar ingen aktør.
- **S:** tilgangen sjekkes også i tjenesten.
- **J:** operasjonen kjører i køjobber som startes av en handling som er kontrollert.

`AiCallContextScope`/`AiCallContextPolicy` setter og håndhever bare kundekonteksten. `AiUsageGuard` og kostnadskontrollen er kvoter. Ingen av dem er tilgangskontroll.

| Operasjoner | Inngang | Kontroll i dag | Hvor |
|---|---|---|---|
| `quality.interpret_process` | `QualityController::interpretFlow` | `quality.edit` | C |
| `quality.clarify_process` (og tolkning) | `QualityController::answerFlowClarification` | `quality.edit` | C |
| `quality.propose_process_change` | `QualityController::proposeFlowChange` | `quality.edit` | C |
| `quality.draft_activity_article` | `QualityController::draftActivityArticle` | `quality.create` og `wiki.knowledge.handoff`. `quality.create` også i `QualityActivityArticleService::draft` (steg 12) | **S** |
| Artikkel fra aktivitet til Wiki-kjøring | `storeActivityArticle` → `WikiKnowledgeHandoffService::deposit` | `quality.create` og `wiki.knowledge.handoff`, begge i tjenestene | **S**, deretter J |
| `tender.excel_structure_discovery`, `tender.requirement_extraction`, `tender.requirement_relevance` | `AiController::storeDocuments`, deretter jobber | Synlighet til saken (`SavedNoticeAccessService`) og AI-tilbud (`canUseAiOffer`). **Ingen rettighetsnøkkel** | C, deretter J |
| `tender.requirement_answer`, `_research`, `_alignment`, `_answer_revision`; `wiki.navigation_plan` | `AiController::generateRequirementWikiAnswer` | Som over. Tjenesten får `int $userId`, ikke en `User` | C |
| `tender.requirement_assessment`, `_research` | `AiController::refreshAssessments` | Som over | C |
| `wiki.ask.*`, `wiki.navigation_plan` | `WikiAskController::ask` | `wiki.view`, `canUseAiOffer` og hastighetsgrense. Synlige statuser beregnes i kontrolleren og sendes inn | C |
| Hele Wiki-kjøringen (`wiki.maintainer_decision`, `generate_page`, `repair_*`, `extract_page_claims`, `verify_claim`, `review_*`, `revise_*`, `propose_inbound_links`, `classify_cross_page_consistency`) | `WikiSourceController::ingest`, deretter `EnterpriseWikiDocumentFlowService::startForDocument` | `wiki.source.manage` i kontrolleren. `startForDocument(int, int)` tar ingen aktør | C, deretter J |
| Samme (ny kjøring av feilede steg) | `WikiController::retryMaintainerDecision`, `retryFailedPages`, deretter `*FailureRecoveryService::attempt` | `wiki.source.manage` og dokumentrett i kontrolleren | C, deretter J |
| Samme (overføring fra Risiko, Avvik, LG, Etterlevelse, Leverandør og Mål) | `WikiKnowledgeHandoffController::store`, deretter `handOff`/`deposit` | `canHandOff` og `mayDeposit` i tjenesten | **S**, deretter J |
| `wiki.extract_page_claims`, `wiki.verify_claim` (manuell redigering) | `WikiController::updateManualMixedBlockEdit`, `updateWorkingVersion` | Vakt og `wiki.review`/`wiki.edit` i tjenestene (steg 5 og 6) | **S** |
| `wiki.verify_claim` (avstemming av kilder etter opplasting) | `WikiSourceController::store`; i Kvalitet `storeItem`, `storeDocument` og `storeTool` | Wiki: `wiki.source.manage`. **Kvalitet: bare `quality.create`/`quality.edit`** (F3b) | C, deretter J |
| Eldre `wiki.extract_section_claims`, `wiki.generate_article` og driftskommandoer | Bare konsoll og planlagte jobber | Operatørkontekst, ingen rettighet. Forventet | – |

Ingen nettrute starter AI-arbeid helt uten kontroll.

**Anbefaling: tilgangen flyttes inn i tjenestene, etter mønsteret fra steg 9–12.**

1. **Kvalitet (lite, kan tas først):**
   - `QualityProcessFlowInterpreter::interpret`, `QualityProcessDescriptionClarifier::integrate` og `QualityProcessFlowChangeProposer::propose` tar `User $actor`.
   - De kaller `QualityAccessService::authorize($actor, quality.edit, $customerId)` før modellen spørres, slik `QualityActivityArticleService::draft` gjør nå.
   - Forslagene er ikke menneskeforbeholdt. En integrasjon med `quality.edit` kan be om et forslag, mens det å ta det i bruk forblir menneskelig (`QUALITY_FLOW_ADOPT`).
2. **Wiki-kjøringen:**
   - `EnterpriseWikiDocumentFlowService::startForDocument` og gjenopprettingstjenestene tar en aktør og kontrollerer selv `wiki.source.manage` og dokumentretten (`EnterpriseWikiSourceAuthority`).
   - Systemkall (planlagte jobber og konsoll) går gjennom en egen, eksplisitt systeminngang, slik at «ingen aktør» aldri er en stille standard.
   - Overføring av kunnskap går allerede gjennom `deposit`, som kontrollerer.
3. **Spør Wiki:** `EnterpriseWikiQuestionAnswerService::ask` tar `User` og avleder selv `wiki.view` og synlige statuser, i stedet for å få statusene fra kontrolleren.
4. **Anbud:**
   - Det finnes ingen `tender.*`-nøkkel i rettighetskatalogen. AI-arbeid på en sak styres av synligheten til saken og AI-tilbudet.
   - Før API-fasen bør det besluttes om det skal finnes en egen nøkkel for å starte AI-arbeid på en sak (for eksempel `tender.ai.run`). Uansett bør tjenestene få `User` og selv kontrollere synligheten til saken (`SavedNoticeAccessService`) og AI-tilbudet.
   - Godkjenning og avvisning av krav er allerede menneskeforbeholdt (B4).
5. **Kvalitetsopplasting som starter avstemming i Wiki (F3b):** dette er fortsatt en beslutning for fase 1, som før.
6. **Felles:**
   - Hver AI-tjeneste som startes av en person, tar `User $actor` og sjekker tilgang før kostnad påløper.
   - En test per operasjon kaller tjenesten direkte med en person uten nøkkel og med en annen kundes person, og viser at ingen AI-kall går ut (`Http::preventStrayRequests()`, som i `QualityServiceAuthorizationTest`).

#### JS-grunnlaget: 2130 eller 970 tester

- **Den komplette suiten er 2130 tester i 103 filer**, fordelt på `Components` 5, `Layouts` 4, `Pages/App` 66 og `Support` 28 (2026-10-22).
- **To kjøringer rapporterte 970 tester og 0 feil.** Telling per fil viser at 970 er nøyaktig suiten uten `resources/js/Pages`: 86 + 133 + 751 = 970, mens `Pages` alene har 1160.
- **Årsak:** `node --test "resources/js/**/*.test.js"` lar Node gjøre globbingen, og den hoppet stille over en hel katalog. Fem nye kjøringer ga 2130. Utløseren er ikke reprodusert. En forbigående lesefeil i Docker-monteringen på macOS er den mest sannsynlige.
- **Retting (`ef1e0ee6`):** `scripts/run-js-unit-tests.mjs` finner filene selv. En katalog som ikke kan leses, stopper kjøringen. Antallet filer per katalog skrives ut før testene kjøres, så en delvis kjøring synes.
- **Rettelse:** Rapporten for steg 12 («JS: 970 av 970») var en slik delvis kjøring.

#### Tester og kjøringer (2026-10-22)

- **PHP:** alle testfiler som berører Kvalitet, og Risikos og Etterlevelses koblinger til kontroller (36 filer): 519 av 519 bestått. Wiki-slettingen er kjørt i tillegg (8 filer, 85 av 85).
- **Nye testfiler:** `QualityWikiDeletionAtomicityTest` (5), `QualityFlowProposalVersionTest` (5) og `QualityControlHistoryTest` (6).
- **Mutasjonssjekket:**
  - transaksjonen i A21;
  - versjonskontrollen og avtrykket på forslaget i A22;
  - evidensregelen i tjenesten og triggeren i A19.
  - Låsen i A21 overlever mutasjonen, som forklart over.
- **JS:** 2130 av 2130 bestått i 103 filer. Frontendbygg og Pint på berørte filer er rene.
- **E2E i det isolerte miljøet:** alle spesifikasjoner for Kvalitet og Etterlevelse, `risk-controls` og `risk-context` (36 filer, 72 tester). Telt fra Playwrights JSON-rapport.
  - Første kjøring: 60 bestått, 8 feilet og 4 hoppet over. Alle 8 feilene skyldtes A30 (opprydding og følgefeil) eller den tilsiktede endringen i `quality-overview-sections`.
  - Etter `03428309`: de 7 berørte filene ga 17 bestått og 1 hoppet over. Hele settet ga deretter **69 bestått, 0 feilet og 3 hoppet over**.
  - **De 3 som hoppes over, er ikke bestått og ikke verifisert.** Alle trenger AI-tolkning, som miljøet med vilje ikke har: A11Y-013 i `a11y-grc-quality`, `quality-flow-clarify-sticks` og `quality-flow-describe-without-flow`.

#### Nye funn

| # | Funn | Vurdering |
|---|---|---|
| A27 | Manuell lagring av prosessflyten er «siste lagring vinner». To samtidige redigeringer kan overskrive hverandre uten varsel. | Ikke endret. Kan løses med samme versjonsavtrykk som i A22 (lagre mot versjonen som ble åpnet). Bør vurderes før integrasjoner kan skrive flyt. |
| A28 | En prosess som ikke er godkjent, og som Risiko, Mål/KPI, Avvik eller Etterlevelse peker på, kan slettes. Koblingene følger med i kaskaden. | Samme åpne spørsmål som koblingene i A19, og bør besluttes sammen med dem. |
| A29 | Den eksisterende funksjonen «Fjern» på evidens gjør at en kontroll kan tømmes for evidens og deretter slettes. | Se A19 «Gjenstår» og A20. |
| A30 | Tre E2E-fiksturer ryddet opp ved å slette kontroller med evidens. Det nekter databasen nå. De mislykkede oppryddingene ga også følgefeil (tidsavbrudd i Etterlevelse-spesifikasjoner). | Rettet (`03428309`). |

### 2h. Sletting, koblinger og evidens i Kvalitet: beslutninger og gjennomføring (2026-10-22)

**Produktbeslutninger (2026-10-22, se også §1, B10 og B11):**
- **A19/A28, alternativ 2:** En prosess eller kontroll som andre moduler peker på, kan ikke slettes. Det gjelder også når den som sletter, ikke har tilgang til modulen med koblingen. Meldingen er generell. Dette er en bevisst endring av regelen om at Kvalitet ikke skal påvirkes av skjulte koblinger (`0798aa5`, §2g).
- **A29:** Evidens trekkes tilbake og slettes aldri. Det registreres hvem som gjorde det og når.
- **A20:** Bare den delen av hendelsesloggen som A29 trenger, bygges nå.
- **A27:** Versjonskontroll ved manuell redigering gjennomføres hvis den er avgrenset. Den var det.
- **A23:** Beholdes som dokumentert punkt (§2g). Ingen nye API-er eller agentfunksjoner.
- **Forenkling (besluttet samme dag, etter gjennomføringen nedenfor):**
  - Det som ikke har vært brukt, kan slettes som før.
  - Det som har vært brukt, beholder historikken og settes til «Utgått».
  - Brukeren arbeider som normalt, mens historikk, tilgang og dataintegritet ivaretas automatisk.
  - Ingen nye statuser, godkjenningssteg, begrunnelsesfelt eller skjermbilder.
  - Sikkerhetsreglene, triggerne og tilgangskontrollen er uendret. Bare det brukeren ser, er forenklet (`bf4bde95`).

| Commit | Innhold |
|---|---|
| `41a7fb78` | A19/A28: et element som andre moduler peker på, settes til «Utgått» og slettes ikke. Meldingen er generell. |
| `b34dec1a` | A29 og A20: evidens trekkes tilbake, og hendelsesloggen `quality_item_events` er innført. |
| `770eb2f0` | A27: manuell lagring av flyten overskriver ikke en flyt som er lagret i mellomtiden. |
| `bf4bde95` | Forenkling: «Er i bruk» med «Sett til Utgått» i menyen, «Fjern» og «Historikk» for evidens, og kortere meldinger. |

#### A19/A28 – koblinger fra andre moduler

**Hva som blokkerer sletting, i tjenesten (`QualityItemService::deletionBlockers()`) og i databasen (`quality_items_keep_cross_module_links`, samme liste):**

| Element | Koblinger |
|---|---|
| Prosess (også aktivitetene i den) | `risk_processes`, `risk_activities`, `kpi_processes`, `kpi_activities`, `improvement_case_processes`, `improvement_case_activities`, `compliance_requirement_processes`, `compliance_audit_processes`, `compliance_audit_findings.quality_process_id` |
| Kontroll | `risk_controls`, `compliance_requirement_controls`, `compliance_audit_findings.control_item_id` |

- **Status:** Elementet settes til «Utgått» (`QualityItem::STATUS_RETIRED`), som allerede finnes for prosesser og kontroller. Ingen ny status trengs.
  - En utgått prosess eller kontroll tilbys ikke for nye koblinger i Kvalitet, Etterlevelse og revisjon, oppmerksomhetslisten eller Mine oppgaver.
  - Koblingene som finnes, blir stående.
- **Hva som fortsatt fjerner en kobling:**
  - Den andre modulen sletter sin egen post (en risiko, et krav eller en sak). Det er den modulens beslutning, og den tar koblingen med.
  - Kunden slettes.
- **Rettighetskontroll og kundeseparasjon er uendret:**
  - Vakten og `quality.delete` sjekkes før noen regel leses.
  - En integrasjon kan ikke slette i det hele tatt (`QUALITY_DELETE` er menneskeforbeholdt).
  - En annen kunde avvises før koblingene leses.
- **Det brukeren ser (forenklet, `bf4bde95`):**
  - Menyen sier «Er i bruk og kan ikke slettes.» og tilbyr **«Sett til Utgått»**. Det er ett klikk, uten dialog, og den vanlige statusendringen (`PATCH`, `quality.edit`). Uten `quality.edit` vises bare setningen.
  - Et forsøk på å slette likevel får «Er i bruk og kan ikke slettes. Sett til «Utgått» i stedet.» (`in_use_not_deletable`).
  - Det er **én** grunn for alle årsaker: godkjent versjon, evidens og koblinger fra andre moduler. Grunnen sier aldri hvorfor, og nevner aldri modul, risiko, sak, krav eller person.
  - Radene har `in_use` (sann/usann) i stedet for en setning.

**Kan sletteregelen lekke informasjon? Vurdering og tiltak:**

| Kanal | Vurdering | Tiltak |
|---|---|---|
| Teksten i avvisningen | Kan røpe modul, post eller antall | Én setning for alle årsaker, også de brukeren selv kan se (forenkling). Testet mot en liste med avslørende ord. |
| Flagget i radmenyen (`in_use`) | En leser kunne utledet skjulte koblinger fra sidedataene | Beregnes bare for den som har `quality.delete`. En leser får `false` (testet), og menyen vises ikke for en leser. |
| Forskjellig svar avhengig av hva brukeren ser | Kunne røpe hvilken modul koblingen kommer fra | Samme status, omdirigering og melding uansett om brukeren ser modulen eller ikke (testet). |
| Rekkefølgen på kontrollene | En annen kunde eller en leser kunne prøve seg fram | Tilgang sjekkes først. Leseren får 403, en annen kunde 404 eller 403, og en integrasjon nektes av vakten før koblingene leses. |
| Egne grunner (godkjent versjon, evidens) | Kan brukeren se selv | Etter forenklingen samme setning som alle andre. Svaret skiller ikke mellom årsaker. |
| Selve avslaget | Den som sletter, får vite at *noe* peker på elementet | **Gjenstår, og er godtatt i beslutningen.** Det røper ikke hva. Den som sletter, har allerede `quality.delete` i samme kunde. |
| Tid | Svartiden kan variere litt med antall koblinger | Ikke tiltak. Forskjellen er liten og røper ikke modul. Konstant tid står ikke i forhold. |
| Databasefeil | Triggermeldingen nevner bare elementets id | Vises ikke for brukeren. Appen avviser før databasen. |

**Data:** Utviklingsdatabasen har én prosess (`risk_processes`) og én kontroll (`risk_controls`) med koblinger. De kan nå bare settes til «Utgått». Ingen rader er endret.

**Tester:**
- `QualityCrossModuleLinkTest` (7):
  - hver av de tolv koblingene blokkerer, i tjenesten og i databasen, med samme setning;
  - en skjult kobling nevnes aldri og vises aldri for en leser;
  - svaret er det samme uansett hva den som sletter, ser;
  - en integrasjon kommer ikke rundt regelen;
  - en annen kunde avvises først;
  - «Utgått» beholder koblingen, og den andre modulen bestemmer fortsatt over sin egen post;
  - sletting av kunden tar alt med.
- Åtte eksisterende tester beskrev den gamle regelen og beskriver nå den nye (Risiko, KPI, Avvik og Etterlevelse).
- Mutasjonssjekket: en tabell fjernet fra listen, grunnen beregnet for en leser, og triggeren.

#### A29 – evidens trekkes tilbake

- **Modell:** Evidens er fortsatt en rad i `quality_item_documents` (`relation_type = evidence`). Raden får `withdrawn_at`, `withdrawn_by_user_id` og `withdrawn_by_name`. Ingen ny tabell for evidens.
- **Handlingen:** Knappen heter **«Fjern»**, som før (forenklet, `bf4bde95`). For brukeren er det en vanlig fjerning, mens systemet trekker evidensen tilbake i stedet for å slette den.
  - Raden blir stående.
  - Tilbaketrekkingen er endelig. En ny tilbaketrekking avvises, og to samtidige registrerer bare én (radlås).
  - Det samme registrert på nytt blir en ny rad. Unik-indeksen gjelder nå bare aktive rader.
  - Andre dokumentkoblinger enn evidens fjernes som før.
- **Visning (forenklet):**
  - Aktiv evidens vises som før. Fjernet evidens forsvinner fra listen.
  - Historikken ligger under en lukket **«Historikk (n fjernet)»**, med «Fjernet :dato av :navn». Den tegnes først når den åpnes.
  - Det samme gjelder i dokumentlisten. Det er ingen egne seksjoner, merker eller nye begreper.
- **Hva som teller:** Tilbaketrukket evidens teller ikke lenger, verken i antallet i registeret, i «Kontroller uten evidens» eller i det Risiko og Etterlevelse viser. Ledelsens gjennomgang teller *registrert* evidens i perioden, og det er uendret.
- **Beskyttelse:**
  - Et element med evidens, tilbaketrukket evidens inkludert og uansett type (ikke bare kontroller), slettes ikke («Utgått»).
  - En evidensrad kan aldri slettes mens elementet og kunden finnes. Den kan bare endres ved tilbaketrekking, ved frigjøring fra en slettet fil (eksisterende regel) og når en slettet bruker blir `NULL`.
  - En tilbaketrekking kan ikke angres eller skrives om.
- **Eksisterende data:** De 2 evidensradene i utviklingsdatabasen står uendret som aktive. Ingen hendelser er fylt inn bakover. En rads `created_at` og `created_by_user_id` er registreringen.

#### A20 – hendelseslogg (den delen A29 trenger)

- **Tabellen `quality_item_events`:**
  - Kolonner: `customer_id`, `quality_item_id` (uten fremmednøkkel, så historikken overlever elementet), `quality_item_document_id`, `event`, `actor_user_id`, `actor_name`, `occurred_at` og `metadata`.
  - Den skrives i samme transaksjon som endringen.
  - Trigger: ingen endring og ingen sletting, unntatt at en slettet bruker blir `NULL` og at kunden slettes.
- **Hendelser nå:** `evidence_registered` (med tittel og fil) og `evidence_withdrawn`.
- **Slik kommer resten inn i samme modell** (anbefalt rekkefølge fra §2g, ikke bygget):

| Hendelse | `event` | Metadata | Skrives i |
|---|---|---|---|
| Statusendring | `status_changed` | `from`, `to` | `QualityItemService::updateItem` |
| Eierskifte | `owner_changed` | `from_user_id`/`from_name`, `to_user_id`/`to_name` | `updateItem` |
| Gjennomgang registrert | `review_recorded` | `reviewed_at`, `interval_months`, `next_review_at` | `updateItem` (`reviewAttributes`) |
| Element slettet | `item_deleted` | type, tittel, kode og antall Wiki-sider tatt med | `deleteItem`, før slettingen, i samme transaksjon. Raden overlever fordi det ikke er noen fremmednøkkel. |
| AI-forslag tatt i bruk / arbeidsversjon forkastet / flyt slettet | `flow_adopted`, `flow_discarded`, `flow_deleted` | kilde og versjonsavtrykk | `QualityProcessBlueprintService` |
| Kontroll plassert eller fjernet; styrende dokument koblet eller fjernet | `control_placed`, `control_unplaced`, `governs_linked`, `governs_unlinked` | aktivitet og element | de respektive tjenestene |

- **Felles for alle:** én metode i tjenestene, i samme transaksjon som endringen, med aktøren fra tjenestekallet. Det betyr at en integrasjon også står med navn.
- **Visning:** en historikkfane på elementet kan lese tabellen direkte. Den bør bygges sammen med statushendelsene.

#### A27 – samtidig manuell redigering (rettet)

- **Avgrenset, derfor gjennomført:**
  - Arbeidsversjonen sender `version_hash` til siden.
  - Alle fire manuelle lagringer sender det tilbake: «Lagre struktur», dialogen for et steg, «+» på en pil og «Flytt steg».
  - `store()` sjekker det under samme lås som AI-forslag (A22).
- **Ved konflikt (forenklet tekst):** «Noen andre har lagret flyten i mellomtiden. Last inn siden på nytt.»
  - Meldingen vises under «Lagre struktur» eller i stegdialogen.
  - For et AI-forslag: «Flyten er endret siden forslaget ble laget. Lag et nytt forslag.»
  - Editoren beholder endringene, fordi tilstanden bevares bare når lagringen avvises.
- **Gjenstår:** `base_hash` er valgfri på endepunktet. Siden sender den alltid, men en klient uten den får fortsatt «siste lagring vinner». Den må bli påkrevd før integrasjoner kan skrive flyt (fase 1).
- **Tester:** to nye tester i `QualityFlowProposalVersionTest` (lagring imellom; første flyt). Mutasjonssjekket.

#### A23 – AI-operasjoner

Uendret: dokumentert i §2g for senere arbeid.

#### Tester og kjøringer (2026-10-22)

- **PHP:** alle testfiler som berører Kvalitet, Risiko, KPI, Avvik og Etterlevelses koblinger til Kvalitet, og sletting av Wiki-dokumenter (43 filer): 589 av 589 bestått før forenklingen og etter den. Etter forenklingen er det i tillegg én ny test for «Sett til Utgått» (`QualityCrossModuleLinkTest`, 8 av 8).
- **JS:** 2130 av 2130 bestått i 103 filer. Frontendbygg, ESLint og Pint på berørte filer er rene.
- **Migreringer:** `2026_10_22_000010` og `000011` er kjørt på utviklings-, test- og E2E-databasen. `000011` er rullet tilbake og migrert på nytt i testdatabasen.
- **E2E i det isolerte miljøet:** alle spesifikasjoner for Kvalitet og Etterlevelse, `risk-controls`, `risk-context`, `risk-journey`, `objective-kpi-context`, Avvik og `my-tasks-governance` (43 filer, 82 tester). Telt fra Playwrights JSON-rapport.
  - **Før forenklingen:** 76 bestått, 1 feilet og 5 hoppet over.
    - Feilen var «removing evidence keeps focus in the list» i `a11y-grc-quality`. Den lette etter «Fjern», mens knappen het «Trekk tilbake».
    - 2 av de 5 hoppet over som følge av feilen (filen kjører i serie).
  - **Etter forenklingen (`bf4bde95`):** **79 bestått, 0 feilet og 3 hoppet over.**
  - **De 3 som hoppes over, er ikke bestått og ikke verifisert.** Alle trenger AI-tolkning, som E2E-miljøet med vilje ikke har: A11Y-013 i `a11y-grc-quality`, `quality-flow-clarify-sticks` og `quality-flow-describe-without-flow`.

#### Nye funn

| # | Funn | Vurdering |
|---|---|---|
| A31 | Når et steg fjernes fra flyten, eller flyten slettes eller forkastes, fjernes aktivitetskoblingene fra Risiko, KPI og Avvik til det steget (`QualityActivityLinkCleanup`). Det er en eksisterende, bevisst regel (en kobling til en aktivitet som ikke finnes, peker ikke på noe), men den går ikke via sletteregelen i B10. | **Besluttet 2026-10-22: behold.** Forutsetningene er kontrollert og testet (§2i, `5d9c0d03`). |
| A32 | `base_hash` er valgfri ved manuell lagring (A27). | **Rettet** (§2i, `3ff1b8a0`): påkrevd for all lagring av flyt. |
| A33 | Evidens kan ikke redigeres (tittel eller beskrivelse). En feilregistrering rettes med «Fjern» og ny registrering. | Godtatt. Ingen begrunnelsesfelt (forenkling). |

### 2i. Etterlevelse og revisjon (steg 13) – kartlegging og gjennomføring (2026-10-22)

**Beslutninger før steget (2026-10-22):**
- **A31 – koblinger ved fjerning av prosess-steg:** Dagens oppførsel beholdes. Forutsetningene er kontrollert og holder (se under).
- **A32 – versjonskontroll:** Versjonskontroll er obligatorisk for alle operasjoner som endrer prosessflyten, også gjennom fremtidige API-er. Den var avgrenset og er gjennomført (`3ff1b8a0`).

#### A31 – kontroll av forutsetningene (`5d9c0d03`)

| Forutsetning | Resultat |
|---|---|
| Risikoen, KPI-en og avvikssaken slettes ikke | Holder. `QualityActivityLinkCleanup` sletter bare radene i `risk_activities`, `kpi_activities` og `improvement_case_activities`. |
| Godkjente prosessversjoner bevares | Holder. Opprydningen leser bare arbeidsversjonen. `quality_process_revisions` røres ikke, og den godkjente versjonen inneholder fortsatt steget. |
| Nødvendig beslutningshistorikk bevares | Holder. Ingen historikktabell peker på koblingsradene: risikovurderinger, aksepter, KPI-målinger og statusendringer i Avvik står uendret. |

- **Test:** `QualityCrossModuleLinkTest::test_removing_a_step_keeps_the_records_their_history_and_the_approved_version`. Testen bruker en godkjent prosess, en risiko med vurdering og aksept, en KPI med måling og en sak med statusendring. Etter at steget er fjernet, er bare koblingene borte.
- De eksisterende testene i Risiko, KPI og Avvik (`…QualityContext…Test`) dekket allerede at posten står.
- **Merknad, ikke endret:** Når et steg fjernes bare fra arbeidsversjonen, forsvinner koblingen med en gang, selv om den godkjente versjonen fortsatt har steget. Forkastes arbeidsversjonen etterpå, kommer steget tilbake, men koblingen gjør det ikke. Det er dagens regel, og den er beholdt.

#### A32 – versjonskontroll på all lagring av flyt (`3ff1b8a0`)

- **Kartlegging:** `QualityProcessBlueprintService::store()` er den eneste som skriver en endret flyt. Den kalles fra tre steder:
  - manuell lagring (`updateBlueprint`);
  - «Bruk denne prosessflyten» (`adoptFlowProposal`);
  - «Godta endringer» (`acceptFlowChange`).
- Godkjenning og «Forkast arbeidsversjon» endrer ikke innholdet i flyten. De låser og leser arbeidsversjonen.
- **Endring:**
  - `basedOn` er en påkrevd parameter uten `null` i `store()`. Det finnes dermed ingen måte å kalle tjenesten uten versjon på.
  - `base_hash` er påkrevd på endepunktet for manuell lagring.
  - AI-forslag krevde det allerede (A22).
- **Brukerne merker ingenting:** Siden sendte allerede versjonen ved alle fire manuelle lagringer. Redigering vises bare når det finnes en flyt.
- **Tester:**
  - `QualityFlowProposalVersionTest::test_every_flow_write_without_a_version_is_refused`: en lagring uten versjon nektes med 422 på begge endepunktene, og tjenesten kan ikke kalles uten.
  - Testfiksturene leser versjonen gjennom `Tests\Support\QualityFlowVersion`.
  - Mutasjonssjekket: et endepunkt som faller tilbake til «siste lagring vinner», fanges.
- Dette lukker «Gjenstår» i A27 og funn A32.

#### Kartlegging før steg 13

- Modulen er kundevid, uten fagområder. Nøklene er `compliance.view`/`edit`/`assess`/`audit`/`delete`.
- System Owner har nøklene bare gjennom en egen rolle (`explicitGrantDomains()`).
- `ComplianceAccessService` bruker `CustomerPermissionService::has()`. Integrasjoner får derfor ingen menneskeforbeholdte nøkler (ingen A6-luke).

| Handling | Rettighet | Kontrollert i | Merknad |
|---|---|---|---|
| Vurder etterlevelse | `compliance.assess` (menneskeforbeholdt) | **Bare kontrolleren** | Tjenesten låste kravet og skrev en uforanderlig vurdering. |
| Start, Fullfør, Avbryt, Gjenåpne revisjon | `compliance.audit` (menneskeforbeholdt) | **Bare kontrolleren** | Tjenesten låste revisjonen og skrev historikk. |
| Ny revisjon, Rediger revisjon | `compliance.audit` | **Bare kontrolleren**, som også skrev | Feltene som kan redigeres, ble lest uten lås. |
| Scope, kontrollhandlinger, revisjonsfunn, «Følg opp i Avvik» | `compliance.audit` | Nøkkelen i tjenesten, **ikke kunden eller modulen** | Funn og kontrollhandling ble låst bare på id, ikke som del av revisjonen. |
| Slett krav, revisjon, kilde | `compliance.delete` (menneskeforbeholdt) | **Bare kontrolleren**, som også slettet | |
| Krav, kilder, Sett som utgått/Gjenåpne, Relevans, «Aktiver for virksomheten» | `compliance.edit` | **Bare kontrolleren** (krav og kilder ble skrevet der) | |
| Kvalitet-koblinger | `compliance.edit` og lesetilgang til Kvalitet | Nøkkelen i tjenesten, ikke kunden eller modulen | |
| Overfør revisjon til Wiki | `compliance.audit` og `wiki.knowledge.handoff` | Wiki-delen i tjenesten. Revisjonsdelen sjekket bare nøkkelen. | |

**Historikk før steget:** Dette var allerede beskyttet i databasen. Alt nekter UPDATE og DELETE så lenge kunden finnes:
- vurderinger (`compliance_assessments`);
- statusendringer for krav og revisjoner;
- funn som er overlevert til Avvik.

Et krav med historikk, med plass i et scope eller med funn kan ikke slettes, heller ikke i SQL (triggere og `NO ACTION`). Det samme gjelder en revisjon med historikk. Funn A13 (manglende DELETE-trigger) finnes ikke her.

#### Gjennomført

| Commit | Innhold |
|---|---|
| `3b66c55d` | Vurdering, revisjonens livsløp og alt revisjonsarbeid går gjennom vakten (`COMPLIANCE_ASSESS`, `COMPLIANCE_AUDIT`). Sletting går gjennom `COMPLIANCE_DELETE`. Deretter kreves nøkkelen i postens egen kunde, med modulen. Sletting er flyttet fra kontrollerne til tjenestene, og `isDeletable()` leses under radlås. Nye tjenester: `ComplianceAuditService` og `ComplianceSourceService`. Funn og kontrollhandlinger nås bare som del av revisjonen de sendes inn med. |
| `5228b3e3` | Ordinære skrivinger sjekker `compliance.edit` i tjenesten: krav (ny `ComplianceRequirementService`), kilder, relevans, aktivering og Kvalitet-koblinger. |
| `7ccb97f3` | «Overfør til Wiki» fra en revisjon krever `compliance.audit` i revisjonens kunde, med modulen. |

#### Sikkerhetsregler

1. **Formelle beslutninger tas av personer.** Det gjelder etterlevelsesvurdering, revisjonens livsløp, planlegging og gjennomføring av revisjonen (scope, kontrollhandlinger og funn), overlevering av funn til Avvik og all sletting.
   - Vakten nekter integrasjoner, inaktive personer og API-kanalen, også i delegert modus.
   - Deretter kreves riktig nøkkel.
2. **Kundeseparasjon og modul gjelder i tjenesten.** Hver skriving krever:
   - `compliance.view` og nøkkelen;
   - aktørens egen kunde, som må være postens kunde;
   - at kunden har modulen.

   En annen kundes person og System Owner nektes, også med en rolle som har alle nøkler. En kunde uten modulen nektes.
3. **System Owner uten rolle har ingen beslutninger.** Det er uendret, og nå testet i tjenesten.
4. **Funn og kontrollhandlinger følger revisjonen.** En post som tilhører en annen revisjon, også i samme kunde, nås ikke (404). Ingenting skrives.
5. **Det som har vært brukt, beholder historikken.** Tjenestene leser slettereglene på nytt under lås:
   - et vurdert krav, et krav i scope og et krav med funn slettes ikke;
   - en revisjon som er startet, slettes ikke;
   - en kilde i bruk slettes ikke.

   Databasen nekter det samme.
6. **Oppfølging gjennom Avvik er uendret og strammet inn:**
   - Overleveringen krever `compliance.audit` i revisjonens kunde.
   - Avvik sjekker sin halvdel, `improvement.edit` i valgt fagområde, i `ImprovementCaseCreator`.
   - Funnet låses som del av revisjonen, og et overlevert funn er fryst (trigger).
7. **Ordinært registerarbeid** (`compliance.edit`) er ikke menneskeforbeholdt. En integrasjon som har nøkkelen, kan gjøre det (testet). Se føringen nedenfor.
8. **Brukerne merker ingen endring.** Kontrollerne sjekker fortsatt først, så statuskoder og meldinger er uendret. Ingen nye statuser, skjermbilder eller felt.

#### A17 – kaskaderekkefølge ved sletting (kontrollert, ikke endret)

- **Kundesletting virker:**
  - Revisjoner med kontrollhandlinger, scope og funn er kontrollert direkte i SQL, både i den nymigrerte E2E-databasen og i testdatabasen, i en transaksjon som ble rullet tilbake.
  - Ny test: `ComplianceHumanDecisionTest::test_deleting_the_customer_takes_audits_with_checks_and_findings_along`.
- **Sletting av én revisjon med kontrollhandlinger feiler på fremmednøkkelen, men det er ikke en feil i praksis:**
  - Kontrollhandlinger kan bare registreres mens revisjonen pågår.
  - En revisjon som har vært startet, har historikk og kan uansett ikke slettes, verken i appen eller i databasen.
- **Ingen migrering.**

#### Tester

- `ComplianceHumanDecisionTest` (11):
  - en person med nøklene tar alle beslutningene, og historikken bærer navnet deres;
  - 19 beslutninger kalles direkte på tjenestene, og disse nektes uten at noe skrives: integrasjon (som heller ikke får nøklene), API-kanal (delegert), inaktiv person, leser, redigerer, hver nøkkel for seg, nøklene uten `compliance.view`, en annen kundes person og System Owner, System Owner uten rolle og en kunde uten modulen;
  - funn og kontrollhandling via en annen revisjon;
  - det som har historikk, slettes ikke;
  - kundesletting (A17).
- `ComplianceServiceAuthorizationTest` (6):
  - redigerer og integrasjon med `compliance.edit` kan gjøre registerarbeid;
  - 12 skrivinger nektes for leser, for en annen kundes redigerer og System Owner og for en kunde uten modul, og ingenting skrives;
  - en kilde fra en annen kunde kan ikke brukes;
  - teksten i et bibliotekskrav endres ikke;
  - et utgått krav endres ikke før det er gjenåpnet;
  - overføring til Wiki.
- To fiksturer brukte aktører uten nøkkelen de nå trenger. De er rettet: vurdering gjøres av en med `compliance.assess`, og «Sett som utgått» av en redigerer.
- **Mutasjonssjekket, 30 mutanter, alle fanges:**
  - vakten og tilgangsregelen i hver tjeneste;
  - kunde-, modul- og visningskontrollen i `may()`;
  - at funn og kontrollhandlinger må tilhøre revisjonen;
  - `isDeletable()` under lås;
  - bibliotekfeltene, kildens kunde og regelen for utgått krav.

#### Tester og kjøringer (2026-10-22)

- **PHP:** alle testfiler for Etterlevelse og revisjon og alle testfiler som berører modulen fra andre moduler, i alt 370 av 370 bestått. Kvalitet-filene som berører flyten (A32, 21 filer): 329 av 329. Kunnskapsoverføring (`--filter` KnowledgeHandoff/WikiKnowledge): 49 av 49.
- **JS:** 2130 av 2130 bestått i 103 filer. Frontendbygget er rent. Pint er rent på berørte filer.
- **E2E i det isolerte miljøet:** alle spesifikasjoner for Etterlevelse og revisjon og Kvalitet, `a11y-grc-compliance`, `a11y-grc-quality`, `a11y-grc-improvements`, `knowledge-handoff`, `my-tasks-governance` og `management-review` (38 filer, 77 tester). Telt fra Playwrights JSON-rapport.
  - Første kjøring: **72 bestått, 1 feilet, 4 hoppet over.**
    - Feilen var et tidsavbrudd i axe-analysen i nettleseren (`a11y-grc-compliance`, «Kravregister and Etterlevelsesvurdering»), ikke et svar fra serveren. Kjøringen tok 5,2 timer i stedet for rundt én, og maskinen gikk mest sannsynlig i dvale underveis.
    - Én av de fire som ble hoppet over, kjørte ikke fordi filen kjører i serie. Sporet ligger i `test-results/run-2026-10-22T21-41-20-469Z/`.
  - `a11y-grc-compliance` kjørt på nytt for seg to ganger, med `caffeinate`: 3 av 3 bestått begge gangene.
  - **Resultat: 74 bestått, 0 feilet, 3 hoppet over, til sammen 77.**
  - **Rettelse (2026-10-23):** Rapporten sa først «75 bestått». Én test i `a11y-grc-compliance` var da talt to ganger: den besto i første kjøring og igjen i den nye kjøringen. Riktig opptelling, per unik test:
    - første kjøring: 72 bestått, 1 tidsavbrudd, 4 hoppet over (3 AI-tester og 1 som ikke kjørte fordi filen kjører i serie);
    - den nye kjøringen gjorde de 2 som ikke hadde bestått, grønne;
    - 72 + 2 = 74 bestått, pluss 3 hoppet over = 77.
  - **De 3 som hoppes over, er ikke bestått og ikke verifisert.** Alle trenger AI-tolkning, som E2E-miljøet med vilje ikke har: A11Y-013 i `a11y-grc-quality`, `quality-flow-clarify-sticks` og `quality-flow-describe-without-flow`.

#### Nye funn som krevde beslutning (A34 og A35 er besluttet og gjennomført, se under)

| # | Funn | Anbefaling |
|---|---|---|
| A34 | **Etter «Gjenåpne» kan en fullført revisjon endres uten spor.** Konklusjonen kan skrives om, og funn som ikke er overlevert, og kontrollhandlinger kan endres eller slettes. Gjenåpningen registreres med begrunnelse, men ikke hva som ble endret. Den forrige konklusjonen overskrives når revisjonen fullføres på nytt. | Behold «Gjenåpne» som rettemekanisme, men bevar automatisk det som var levert: <br>– lagre konklusjonen på statusendringen ved «Fullfør», slik at hver fullføring står i historikken; <br>– et funn som var med i en fullført revisjon, slettes ikke etter gjenåpning, men kan rettes. <br>Ingen nye statuser eller skjermbilder. Krever en liten migrering. |
| A35 | **Relevans («Ikke relevant», og kontrollvalg i vedlegg A) er en del av erklæringen om anvendelse, men behandles som ordinær redigering.** Den krever bare `compliance.edit`, som ikke er menneskeforbeholdt. Det er ingen historikk: bare siste valg, av hvem og når, står på kravet. Et krav som settes til «Ikke relevant», faller ut av vurderinger og av automatisk utvalg til revisjoner. | Behandle «Ikke relevant» som en formell beslutning: vakten og en eksisterende nøkkel (`compliance.assess`), med uforanderlig historikk over valgene, etter samme mønster som statusendringer. Alternativ: behold som i dag og løs det med føringen for agenter nedenfor. |

#### A34 og A35 – beslutninger og gjennomføring (2026-10-23)

**Beslutning (2026-10-23):** A34 og A35 lukkes med en enkel, avgrenset løsning. Sikkerhet og historikk håndteres i bakgrunnen. Ingen nye skjermbilder, godkjenninger eller versjonssystem.

| Commit | Innhold |
|---|---|
| `db5699e1` | A34: konklusjonen fra hver fullføring bevares. Funn fra en fullført revisjon slettes aldri. Retting av slike funn spores. |
| `4ecae0ce` | A35: Relevans og kontrollvalg er en menneskelig beslutning, og hvert valg bevares i historikken. |

**Kartlegging av historikktabellene:**

| Behov | Eksisterende mekanisme | Valg |
|---|---|---|
| Konklusjonen ved hver fullføring | `compliance_audit_status_changes`, uforanderlig, én rad per «Fullfør» | **Gjenbrukt:** ny kolonne `conclusion` på raden. Triggeren dekker den. |
| Funn fra en fullført revisjon | `compliance_audit_findings`. Bare overleverte funn var fryst. | **Ny trigger** på samme tabell. |
| Tidligere verdier ved retting av funn | Ingen | **Ny tabell** `compliance_audit_finding_changes`, bare nye rader, etter samme mønster som de andre historikktabellene |
| Relevansvalg | Bare siste valg på kravet (`applicability_set_by`/`_at`) | **Ny tabell** `compliance_requirement_applicability_changes`, etter samme mønster som `compliance_requirement_status_changes` |

**A34 – revisjonshistorikk:**
- **Konklusjon:** «Fullfør» skriver konklusjonen på sin egen statusendring. Den kan verken endres eller slettes. Revisjonen har fortsatt bare den gjeldende konklusjonen i feltet, slik den alltid har hatt.
- **Gjenåpnet revisjon:** redigeres som før (tittel, konklusjon, scope, kontrollhandlinger og funn).
- **Funn som var med i en fullført revisjon**, det vil si registrert senest da revisjonen ble fullført:
  - kan ikke slettes, verken i tjenesten eller i databasen;
  - kan rettes som før;
  - hver retting som endrer noe, skriver de tidligere verdiene (type, tittel, beskrivelse og koblinger), aktør og tidspunkt.
- **Funn registrert etter gjenåpningen:** arbeides med som før, uten historikk, og kan slettes.
- **Tilgang:** retting er revisjonsarbeid. Vakten og `compliance.audit` i revisjonens kunde gjelder som før (steg 13).
- **Ikke med (bevisst avgrenset):** kontrollhandlinger og revisjonsfeltene ellers (tittel, formål, datoer) får ingen egen historikk.

**A35 – Relevans og kontrollvalg:**
- Ny `HumanDecision::COMPLIANCE_APPLICABILITY`. `ComplianceApplicabilityService::set()` kaller vakten først:
  - ingen integrasjon og ingen API-kall, heller ikke i delegert modus;
  - deretter `compliance.edit` i kravets kunde, med modulen.
- **Nøkkelen er uendret** (`compliance.edit`), så hvem som kan bestemme, og skjermbildet, er som før. Beslutningen er menneskeforbeholdt selv om nøkkelen ikke er det, på samme måte som `QUALITY_FLOW_ADOPT`. `HumanDecisionGuardTest` tillater disse to.
- **Historikk:** hvert valg som endrer relevans eller begrunnelse, skrives med fra, til, begrunnelse, aktør og tidspunkt, i samme transaksjon. Startpunktet fra biblioteket ved aktivering er ikke en beslutning og gir ingen rad.
- **Sletting:** et krav med relevanshistorikk slettes ikke (`isDeletable()`, og triggeren nekter kaskaden). Det settes som utgått.

**Endringer i brukeropplevelsen:**
- **Den eneste synlige endringen:** På et funn som var med i en fullført revisjon, tilbys ikke «Slett» lenger etter at revisjonen er gjenåpnet. «Rediger» står som før. Et forsøk på å slette likevel får meldingen «Funnet var med i en fullført revisjon og kan ikke slettes. Du kan rette det.»
- Ellers er alt uendret: skjemaer, knapper, meldinger og hvem som kan gjøre hva. Historikken lagres i bakgrunnen og vises ikke i et nytt skjermbilde.

**Migreringer:**
- `2026_10_23_000001_keep_compliance_audit_history` (A34):
  - ny kolonne `conclusion` med tilbakefylling;
  - triggeren for statusendringer er utvidet;
  - ny trigger `compliance_audit_findings_keep_completed`;
  - ny tabell `compliance_audit_finding_changes` med trigger.
- `2026_10_23_000002_keep_compliance_relevance_history` (A35): ny tabell `compliance_requirement_applicability_changes` med trigger.
- **Tilbakefylling:** Den siste fullføringen av revisjoner som er fullført nå, får revisjonens konklusjon. En fullført revisjon kan ikke redigeres, så det er konklusjonen den ble fullført med. Tidligere fullføringer av gjenåpnede revisjoner ble aldri lagret og står tomme.
- **Utviklingsdatabasen:** 1 revisjon, ingen fullført og ingen funn. Ingen rader endret.
- **Kjørt** på utviklings-, test- og E2E-databasen. Begge migreringene er rullet tilbake og migrert på nytt i testdatabasen.
- E2E-fiksturen slår av de nye triggerne i sin egen transaksjon når den rydder.

**Tester:**
- `ComplianceAuditHistoryTest` (5):
  - hver konklusjon bevares, og den gjenåpnede revisjonen redigeres som før;
  - et funn fra en fullført revisjon kan ikke slettes (side, HTTP, tjeneste og SQL), men kan rettes, med historikk;
  - en lagring uten endring gir ingen rad, og historikken kan ikke endres eller slettes;
  - et funn registrert etter gjenåpning arbeides med som før;
  - integrasjon, API-kanal, en annen kunde og en leser nektes, og ingenting skrives;
  - kundesletting virker.
- `ComplianceRelevanceDecisionTest` (5):
  - hvert relevansvalg og kontrollvalg (ISO 9001 og vedlegg A i ISO/IEC 27001) bevares med aktør og tidspunkt, og samme valg på nytt gir ingen rad;
  - integrasjon og API-kanal nektes;
  - en leser, en annen kundes redigerer og System Owner, og en kunde uten modul nektes;
  - historikken kan ikke endres eller slettes, og et krav med historikk slettes ikke;
  - kundesletting virker.
- **Mutasjonssjekket, alle fanges:**
  - A34: konklusjonen på statusendringen, sletteregelen, at historikk skrives bare ved endring og bare for funn fra en fullført revisjon, `can_delete` og tidsregelen;
  - A35: vakten, tilgangsregelen, at historikk skrives bare ved endring, og `isDeletable()`;
  - databasen: hver av de fire triggerne slått av, og konklusjonskolonnen fjernet fra triggerfunksjonen.

**Kjøringer (2026-10-23):**
- **PHP:** alle testfiler for Etterlevelse og revisjon, filene som berører modulen fra andre moduler, og `tests/Unit/Services/Security`: 393 av 393 bestått.
- **JS:** 2130 av 2130 bestått. Frontendbygget og Pint er rene.
- **E2E i det isolerte miljøet:** alle spesifikasjoner for Etterlevelse og revisjon, `a11y-grc-compliance`, `knowledge-handoff`, `my-tasks-governance` og `management-review` (16 filer, 26 tester). **26 bestått, 0 feilet, 0 hoppet over.** Telt fra Playwrights JSON-rapport, med `caffeinate`.
- **AI-tester som ikke er kjørt:** A11Y-013 i `a11y-grc-quality`, `quality-flow-clarify-sticks` og `quality-flow-describe-without-flow`. De er uendret siden steg 13, fordi A34 og A35 ikke berører Kvalitet, og hoppes over i miljøet uten AI. De er ikke verifisert.

**Lav, ikke rettet:** Endring av scope leser revisjonens status uten lås. En endring som kommer samtidig med «Fullfør», kan legge til eller fjerne et krav i scope rett etter fullføringen. Risikoen er liten, og det gir ikke tap av historikk.

**Klar for senere API-integrasjon**

- Ja. Lesing, beslutninger og registerarbeid ligger i domenet med samme regler for enhver inngang.
- Gjenstår før fase 1:
  - A10 (feilformat);
  - steg 19 (aktørtriggere for `compliance_assessments.assessed_by_user_id` og `compliance_audit_status_changes.changed_by_user_id`);
  - A34 og A35 er gjennomført (2026-10-23).
- **Føring (anbefalt, som for de andre modulene):**
  - En AI-agent får ikke `compliance.edit` som standard.
  - Forslag til krav og vurderinger kan senere komme gjennom avgrensede API-operasjoner. De blir registrert først når en person tar dem i bruk.
  - Vurdering, revisjon og sletting forblir menneskeforbeholdt.

### 2j. Leverandøroppfølging (steg 14) – kartlegging og gjennomføring (2026-10-23)

#### Kartlegging før steget

- Modulen er kundevid, uten fagområder. Nøklene er `supplier.view`/`edit`/`assess`/`assure`/`delete`. `assess`, `assure` og `delete` er menneskeforbeholdte.
- System Owner har nøklene bare gjennom en egen rolle (`explicitGrantDomains()`).
- `SupplierAccessService` bruker `CustomerPermissionService::has()`. Integrasjoner får derfor ingen menneskeforbeholdte nøkler.
- Ingen leverandørtjeneste kalte `HumanDecisionGuard`.

**Formelle beslutninger og ordinær registrering:**

| Handling | Rettighet | Art | Kontrollert før steget |
|---|---|---|---|
| Vurder leverandør (`SupplierAssessmentService::record`) | `supplier.assess` | Formell vurdering | **Bare kontrolleren.** Tjenesten sjekket verken nøkkel eller leverandørens kunde. |
| Slett leverandør (`SupplierLifecycleService::delete`) | `supplier.delete` | Formell beslutning | **Bare kontrolleren.** Tjenesten leste `isDeletable()` under lås. |
| Kontroller krav, Bekreft kravene på nytt | `supplier.assure` | Formell vurdering | Nøkkelen i tjenesten, leverandøren via `visibleSuppliers()`. **Ikke modulen.** |
| Legg til krav / Gjelder ikke denne leverandøren / Tilbake til automatisk vurdering | `supplier.assure` | Formell beslutning (hva som gjelder) | Som over |
| Aktsomhetsvurdering | `supplier.assure` | Formell vurdering | Som over |
| Kontrollkrav (katalog, krav for én leverandør, Sett som utgått, Slett), kravmaler, Wiki-veiledning | `supplier.assure` | Registerarbeid, men på en menneskeforbeholdt nøkkel | Nøkkelen i tjenesten, aktørens kunde. **Ikke modulen.** |
| Registrer leverandør, Rediger | `supplier.edit` | Ordinær registrering | **Bare kontrolleren**, som også skrev endringene i stamdata |
| Endre kritikalitet | `supplier.edit` | Klassifisering med begrunnelse og historikk | **Bare kontrolleren** |
| Ta i bruk, Avslutt, Gjenåpne | `supplier.edit` | Livsløp med historikk | **Bare kontrolleren** |
| Leverandørprofil | `supplier.edit` | Ordinær registrering med historikk | Nøkkelen i tjenesten. **Ikke modulen.** |
| Dokumentasjon (registrere, rette, fornye, fil, slette) | `supplier.edit` eller `supplier.assure` | Ordinær registrering | **Bare kontrolleren.** `delete()` tok ikke aktør. |
| Følg opp i Avvik / Opprett risiko, Koble til, Fjern koblingen | `supplier.edit` (fra kontroll eller aktsomhet: `supplier.assure`), og den andre modulens nøkkel | Overlevering | `abort_unless` på nøkkelen. **Ikke leverandørens kunde eller modulen.** Den andre modulen sjekker sin halvdel. |
| Excel-import | `supplier.edit` | Ordinær registrering | `execute` sjekket nøkkelen, `upload` ingenting |
| Overfør til Wiki | `supplier.assure` og `wiki.knowledge.handoff` | Overlevering | Wiki-delen i tjenesten. Leverandørdelen sjekket bare nøkkelen, **ikke leverandørens kunde eller modulen.** |

**Historikk før steget:** Allerede beskyttet i databasen. Alle 11 historikktabellene nekter UPDATE og DELETE så lenge kunden finnes (triggere):
- leverandørvurderinger, kontroller med dokumentasjonsøyeblikksbilder, unntak og tillegg, aktsomhetsvurderinger og den avviklede kontrollbeslutningen;
- statusendringer, kritikalitetsendringer, profilendringer, statusendringer for kontrollkrav og slettelogg.

En leverandør med historikk kan ikke slettes, heller ikke i SQL (sammensatt fremmednøkkel med `NO ACTION`). Dokumentasjon som en kontroll bygger på, slettes ikke (tjeneste og `NO ACTION`). Kontrollbeslutningen er avviklet. Ingen kode skriver den.

**Koblinger til andre moduler:** Avvik (`supplier_improvement_cases`), Risiko (`supplier_risks`), Etterlevelse (forankring av kontrollkrav, og tidligere koblinger som bare kan leses), Wiki (veiledning og kunnskapsoverføring), Ledelsens gjennomgåelse (`SuppliersSection`, bare lesing) og Mine oppgaver (bare lesing). Ingen av dem endres av steget.

#### Gjennomført

| Commit | Innhold |
|---|---|
| `9e757eca` | `SupplierAccessService::may()`/`authorize()`: nøkkelen, `supplier.view`, aktørens kunde (leverandørens) og modulen. Vurder leverandør (`SUPPLIER_ASSESS`), Slett leverandør (`SUPPLIER_DELETE`) og alt arbeid under `supplier.assure` (`SUPPLIER_ASSURE`) kaller vakten og deretter regelen. |
| `1ffb9586` | Ordinært registerarbeid sjekker `supplier.edit` i tjenesten. Stamdataendring er flyttet fra kontrolleren til `SupplierRegistration::update()`, med lås. Dokumentasjon krever `supplier.edit` eller `supplier.assure`, og `delete()` tar aktør og leser raden på nytt under lås. Koblinger og overleveringer låser leverandøren i dens egen kunde. Excel-importen sjekker også ved opplasting. |
| `87c3131e` | «Overfør til Wiki» fra en leverandør krever `supplier.assure` i leverandørens kunde, med modulen. |
| `5e887aaf` | E2E: `supplier-import` lagrer filene der E2E-appen leser dem. Bare testen er endret. |

#### Sikkerhetsregler

1. **Formelle beslutninger tas av personer.** Leverandørvurdering, kontroll av krav, hva som gjelder for en leverandør (tillegg og unntak), aktsomhetsvurdering, kontrollkravkatalogen og sletting av leverandør.
   - Vakten nekter integrasjoner, inaktive personer og API-kanalen, også i delegert modus.
   - Deretter kreves riktig nøkkel.
2. **Kundeseparasjon og modul gjelder i tjenesten.** Hver skriving krever:
   - `supplier.view` og nøkkelen;
   - aktørens egen kunde, som må være leverandørens;
   - at kunden har modulen.

   En annen kundes person og System Owner nektes, også med en rolle som har alle nøkler. En kunde uten modulen nektes. Låsene leser leverandøren i aktørens kunde.
3. **System Owner uten rolle har ingen beslutninger.** Uendret, og nå testet i tjenesten.
4. **Det som er besluttet, beholder historikken.** Uendret og testet: en leverandør med en vurdering slettes ikke (tjeneste og SQL), og vurderingen kan ikke endres eller slettes.
5. **Ordinært registerarbeid** (`supplier.edit`) er ikke menneskeforbeholdt. En integrasjon som har nøkkelen, kan gjøre det (testet). Kritikalitet og livsløp er unntatt etter A36. Se A36 og A37.
6. **Overleveringer** til Avvik, Risiko og Wiki er ikke beslutninger og går ikke gjennom vakten. Den andre modulen sjekker sin halvdel, og resultatet behandles videre der av personer. Fra en kontroll eller aktsomhetsvurdering kreves `supplier.assure`, som en integrasjon aldri har.
7. **Brukerne merker ingen endring.** Kontrollerne sjekker fortsatt først, så statuskoder og meldinger er uendret. Ingen nye statuser, skjermbilder, felt eller dialoger.

#### Tester

- `SupplierHumanDecisionTest` (9):
  - en person med nøklene tar alle beslutningene, og historikken bærer navnet deres;
  - 12 beslutninger kalles direkte på tjenestene, og disse nektes uten at noe skrives: integrasjon (som heller ikke får nøklene), API-kanal (delegert), inaktiv person, leser, redigerer, hver nøkkel for seg, nøklene uten `supplier.view`, en annen kundes person og System Owner, System Owner uten rolle og en kunde uten modulen;
  - en leverandør med vurdering slettes ikke, og vurderingen kan ikke endres eller slettes i SQL.
- `SupplierServiceAuthorizationTest` (5):
  - en redigerer og en integrasjon med `supplier.edit` kan gjøre registerarbeid;
  - 14 skrivinger nektes for leser, `assess`, `delete`, `assure` (unntatt dokumentasjon), nøklene uten `supplier.view`, en annen kundes redigerer og System Owner og en kunde uten modul, og ingenting skrives;
  - overføring til Wiki.
- To testdobler i `SupplierImportTest` sender den nye konstruktørparameteren til `SupplierRegistration`.
- **Mutasjonssjekket, 28 mutanter, alle fanges:**
  - kunde-, modul- og visningskontrollen i `may()`, og regelen for dokumentasjon;
  - vakten og regelen i hver beslutningstjeneste;
  - `isDeletable()` ved sletting av leverandør;
  - regelen i hver tjeneste for registerarbeid, i koblingene og i kunnskapsoverføringen.
- **Ikke dekket av mutasjon:** sjekken ved opplasting i Excel-importen. Den ligger før den eksisterende sjekken i `execute`, som er dekket av `SupplierImportTest`.

#### Tester og kjøringer (2026-10-23)

- **Grunnlinje før steget:** alle testfiler for Leverandøroppfølging, `WikiKnowledgeHandoffTest` og `tests/Unit/Services/Security`: 300 av 300 bestått.
- **PHP etter steget:** samme filer, de to nye, oversettelsestesten og filene som berører modulen fra andre moduler (Ledelsens gjennomgåelse, Mine oppgaver, pakkebytte, kunnskapsintegrasjon i Wiki): 419 av 419 bestått, ingen hoppet over.
- **JS:** 2130 av 2130 bestått. Ingen frontendendring, så bygget er ikke kjørt på nytt. Pint er rent på berørte filer.
- **E2E i det isolerte miljøet:** alle spesifikasjoner for Leverandøroppfølging, `a11y-grc-suppliers`, `my-tasks-suppliers`, `knowledge-handoff` og `management-review` (10 filer, 36 tester). Telt fra Playwrights JSON-rapport, med `caffeinate`.
  - Første kjøring: **35 bestått, 1 feilet, 0 hoppet over.**
  - Feilen var i fiksturen, ikke i appen: `supplier-import` lagret malen under `storage/app` på verten, men i det isolerte miljøet er containerens `storage/` vertens `.e2e/storage` (`cef890b2`). Fiksturen fant derfor ikke filen. Feilen oppstår før noen leverandørtjeneste kjøres, og skyldes ikke steget. Den er rettet i en egen commit (`5e887aaf`).
  - `supplier-import` kjørt på nytt etter rettingen: 1 av 1 bestått.
  - **Resultat, per unik test: 36 bestått, 0 feilet, 0 hoppet over, til sammen 36.**

**Migreringer:** ingen.

**Endringer i brukeropplevelsen:** ingen. Skjemaer, knapper, meldinger, statuskoder og hvem som kan gjøre hva er som før. Den eneste forskjellen i oppførsel gjelder samtidige handlinger: lagres stamdata i samme øyeblikk som leverandøren avsluttes, avvises lagringen med den eksisterende meldingen «Leverandøren er avsluttet. Gjenåpne den før du endrer den.». Før ble den lagret på en avsluttet leverandør.

#### Funn som krevde beslutning (A36 er gjennomført, A37 og A38 er besluttet, se under)

| # | Funn | Anbefaling |
|---|---|---|
| A36 | **Kritikalitet og livsløp er registerarbeid (`supplier.edit`), ikke menneskeforbeholdt.** «Endre kritikalitet» avgjør hvilke kontrollkrav som gjelder automatisk, og når leverandøren skal vurderes på nytt. «Ta i bruk» og «Avslutt» er beslutninger om å bruke leverandøren. En integrasjon med `supplier.edit` kan i dag gjøre dette. Historikken er fullstendig (aktør, begrunnelse, før og etter). | Behandle «Endre kritikalitet», «Ta i bruk», «Avslutt» og «Gjenåpne» som menneskelige beslutninger: vakten, nøkkelen uendret, etter samme mønster som A35 (`COMPLIANCE_APPLICABILITY`). Ingen endring i skjermbilder, og ingen migrering. Alternativ: behold som i dag og løs det med føringen for agenter nedenfor. |
| A37 | **Leverandørprofilen styrer hvilke kontrollkrav som gjelder, og endres med `supplier.edit`.** Den som kontrollerer (`supplier.assure`), kan med vilje ikke endre profilen. En integrasjon med `supplier.edit` kan. Hver lagring står i uforanderlig historikk med aktør. | Behold som registerarbeid. Profilen er fakta om leverandøren, og et senere API kan foreslå svar. Avgjøres sammen med A36 når omfanget av API v1 er kjent. |
| A38 | **Dokumentasjon som ingen kontroll bygger på, kan slettes uten spor.** Filen slettes også. Regelen er «registrert ved en feil», og dokumentasjon en kontroll bygger på, er beskyttet. | Lav. Behold. Ønskes spor, kan sletting logges på samme måte som `supplier_deletions`. Det krever en liten migrering. |

**Lav, ikke rettet:** Endringer i stamdata (blant annet intern ansvarlig) har ingen egen historikk. Bare siste verdi og `updated_by` lagres. Det er ordinært registerarbeid.

#### A36–A38 – beslutninger og gjennomføring (2026-10-23)

**Beslutning (2026-10-23):**
- **A36 – gjennomføres.** Kritikalitet og livsløpshandlingene «Ta i bruk», «Avslutt» og «Gjenåpne» krever en autorisert person. Rettighetskravet er uendret.
- **A37 – beholdes foreløpig.** Leverandørprofilen er fortsatt vanlig registerarbeid med `supplier.edit`.
- **A38 – dagens løsning beholdes.** Ingen ny historikk og ingen migrering.

**A36 – gjennomført** (commit `fix(suppliers): criticality and lifecycle are a person's decision (A36)`):
- To nye beslutninger: `HumanDecision::SUPPLIER_CRITICALITY` og `SUPPLIER_LIFECYCLE`. Begge bygger på `supplier.edit`, på samme måte som `COMPLIANCE_APPLICABILITY` (A35). `HumanDecisionGuardTest` tillater disse to.
- `SupplierCriticalityService::change()` og alle tre overgangene i `SupplierLifecycleService` kaller vakten først, deretter `supplier.edit` i leverandørens kunde, med modulen.
- **Den indirekte veien er lukket.** «Registrer leverandør» og Excel-importen kan opprette en leverandør som allerede er «Aktiv», og med kritikalitet. Det er de samme beslutningene. `SupplierRegistration::register()` kaller derfor vakten for `SUPPLIER_LIFECYCLE` når startstatus er «Aktiv», og for `SUPPLIER_CRITICALITY` når kritikalitet er oppgitt.
- En leverandør registrert «Under vurdering» og «Ikke vurdert» er fortsatt registerarbeid. En integrasjon med `supplier.edit` kan gjøre det.
- Registreringsskjemaet sender alltid begge deler. For personer er alt som før.
- **Kontrollert at A36 ikke kan omgås:**
  - `status`, `criticality` og kritikalitetsfeltene kan ikke masseoppdateres (`Supplier::$fillable`). En stamdataendring som sender dem, endrer dem ikke (testet).
  - Profilen skriver aldri status eller kritikalitet (testet).
  - Importen ignorerer startstatus, kritikalitet og profil for eksisterende leverandører. Nye leverandører går gjennom `register()`.

**A37 – beholdes foreløpig:**
- Profilen er fakta om leverandøren og styrer hvilke kontrollkrav som gjelder automatisk. Hver lagring står i uforanderlig historikk med aktør.
- Profilen kan ikke endre status eller kritikalitet. A36 kan derfor ikke omgås gjennom den (testet).
- **Før et fremtidig skrive-API får skrive profilen,** må det vurderes hvordan profilendringer som påvirker kontrollkrav skal behandles. Mulighetene er forslag som en person tar i bruk, eller en egen beslutning. Ingen ny godkjenningsmekanisme nå.

**A38 – dagens løsning beholdes:**
- Dokumentasjon som ikke inngår i beskyttet kontroll- eller beslutningshistorikk, kan fortsatt slettes av den som har `supplier.edit` eller `supplier.assure`.
- Dokumentasjon som en kontroll bygger på, og raden som fornyer den, er fortsatt beskyttet. Tjenesten leser regelen under lås, og databasen nekter med `NO ACTION`.
- Ingen ny historikk og ingen migrering.

**Endringer i brukeropplevelsen:** ingen. Skjermbilder, roller, statuser, arbeidsflyt og meldinger er uendret. Den som har `supplier.edit`, tar beslutningene som før.

**Migreringer:** ingen.

**Tester:**
- `SupplierCriticalityLifecycleDecisionTest` (8):
  - en person med `supplier.edit` tar alle beslutningene, og historikken bærer navnet deres;
  - seks beslutninger nektes, og ingenting skrives: endre kritikalitet, ta i bruk, avslutt, gjenåpne, registrere som «Aktiv» og registrere med kritikalitet. Nektet er integrasjon med `supplier.edit`, API-kanal (delegert), inaktiv person, personer uten `supplier.edit` (leser, `assess`, `assure`, `delete`), nøkkelen uten `supplier.view`, System Owner uten rolle, en annen kundes redigerer og System Owner, og en kunde uten modul;
  - registerarbeid endrer aldri status eller kritikalitet, verken stamdata (også når feltene sendes med) eller profil.
- `SupplierServiceAuthorizationTest`: testen der en integrasjon gjør registerarbeid, gjelder nå registrering «Under vurdering», stamdata, profil og dokumentasjon. Før omfattet den også livsløp og kritikalitet.
- To testdobler i `SupplierImportTest` sender vakten til `SupplierRegistration`.
- **Mutasjonssjekket, 9 mutanter, alle fanges:**
  - vakten og regelen i kritikalitet og i livsløpet;
  - vakten ved registrering som «Aktiv» og med kritikalitet, og betingelsene for dem (feil status, betingelsen slått av);
  - `status` og `criticality` gjort masseoppdaterbare.

**Kjøringer (2026-10-23):**
- **PHP:** samme filer som for steg 14, pluss den nye testfilen: 427 av 427 bestått, ingen hoppet over.
- **JS:** 2130 av 2130 bestått. Ingen frontendendring. Pint er rent på berørte filer.
- **E2E i det isolerte miljøet:** de samme 10 filene som for steg 14 (36 tester), i én kjøring. **36 bestått, 0 feilet, 0 hoppet over.** Telt fra Playwrights JSON-rapport, med `caffeinate`. `supplier-management` dekker livsløp og kritikalitet, og `supplier-import` dekker registrering med status og kritikalitet.

**Klar for senere API-integrasjon**

- Ja. Lesing, beslutninger og registerarbeid ligger i domenet med samme regler for enhver inngang.
- Gjenstår før fase 1:
  - A10 (feilformat);
  - steg 19 (aktørtriggere for `supplier_assessments.assessed_by_user_id`, `supplier_requirement_evaluations.evaluated_by_user_id`, `supplier_requirement_overrides.created_by_user_id` og `supplier_due_diligence_assessments.assessed_by_user_id`);
  - A37: hvordan profilendringer som påvirker kontrollkrav skal behandles, før et skrive-API får skrive profilen.
- **Føring (anbefalt, som for de andre modulene):**
  - En AI-agent får ikke `supplier.edit` som standard.
  - Forslag til leverandører, profilsvar og dokumentasjon kan senere komme gjennom avgrensede API-operasjoner. De blir registrert først når en person tar dem i bruk.
  - Vurdering, kontroll, aktsomhet, kontrollkrav, kritikalitet, livsløp og sletting forblir menneskeforbeholdt.

### 2k. Mål og KPI (steg 15) – kartlegging og gjennomføring (2026-10-23)

#### Kartlegging før steget

- Modulen styres av fagområder, som Risiko og Avvik. Nøklene er `objective.view`/`edit`/`measure`/`delete`. `measure` (B7) og `delete` er menneskeforbeholdte. Et mål har ett fagområde, og en KPI arver det fra målet.
- `ObjectiveAccessService` leser parene (rettighet, fagområde) direkte fra rollene (`BusinessAreaGrants`). Fratrekket for integrasjoner i `CustomerPermissionService::has()` gjaldt derfor ikke her (**A6**, samme hull som i Risiko og Avvik).
- System Owner har ingen tilgang til mål uten en rolle, som i Risiko. Uendret.
- Ingen tjeneste i modulen kalte `HumanDecisionGuard`, og ingen sjekket rettighet, kunde eller modul. Alt lå i kontrollerne.

**Formelle beslutninger og ordinær registrering:**

| Handling | Rettighet | Art | Kontrollert før steget |
|---|---|---|---|
| Registrer måling (`KpiMeasurementService::record`), også retting av en periode | `objective.measure` | Menneskelig beslutning (B7): verdien styrer KPI-status og oppmerksomhet | **Bare kontrolleren** |
| Trekk tilbake måling (`withdraw`) | `objective.measure` | Menneskelig beslutning | **Bare kontrolleren** |
| Slett mål, Slett KPI | `objective.delete` | Formell beslutning | **Bare kontrolleren**, som slettet selv |
| Nytt mål, Rediger (også flytting mellom fagområder og eier) | `objective.edit` | Ordinær registrering | **Bare kontrolleren**, som skrev selv |
| Ny KPI, Rediger KPI (målverdi, enhet, frekvens, eier) | `objective.edit` | Ordinær registrering | **Bare kontrolleren**, som skrev selv |
| Lukk mål (Nådd, Ikke nådd, Avbrutt), Gjenåpne | `objective.edit` | Livsløp med historikk. Se A39. | **Bare kontrolleren** |
| Avslutt KPI, Gjenåpne KPI | `objective.edit` | Livsløp med historikk. Se A39. | **Bare kontrolleren** |
| Endre kobling (KPI → Kvalitet-prosess og aktivitet) | `objective.edit` og Kvalitet-lesing | Ordinær registrering | **Bare kontrolleren** |
| Overfør til Wiki | `objective.edit` og `wiki.knowledge.handoff` | Overlevering | I tjenesten (`WikiKnowledgeHandoffService` → `ObjectiveKnowledgeSource::canHandOff` = `canEdit`): kunde og fagområde, **ikke modulen** |

**Historikk før steget:** Målinger (`kpi_measurements`) og statusendringer (`objective_status_changes`, `kpi_status_changes`) var beskyttet **bare av modellene**. Det samme hullet som A13 i Avvik:
- SQL utenom modellene kunne endre eller slette målinger og statusendringer.
- Fremmednøklene kaskaderer. En KPI eller et mål som ble slettet i SQL, eller gjennom Eloquent utenom `isDeletable()`, tok målingene med seg. Modellvaktene kjører ikke ved kaskade.
- Produktregelen var riktig: en KPI med måling (også tilbaketrukket) avsluttes og slettes ikke, og et mål med målinger lukkes. Hver måling har et øyeblikksbilde av målverdien den ble vurdert mot.

**Koblinger til andre moduler:** Kvalitet (`kpi_processes`, `kpi_activities`; prosessen beskyttes av B10, og koblinger til et steg som fjernes, ryddes av A31), Wiki (overføring), Ledelsens gjennomgåelse (`ObjectivesSection`, bare lesing), Mine oppgaver (`ObjectiveTaskSource`, bare lesing) og varsler (`AssignmentNotifier`, `UserNotificationAccessScope`, bare lesing). Ingen import, masseoppdatering, admin-ressurs, kommando eller jobb skriver i modulen.

#### Gjennomført

| Commit | Innhold |
|---|---|
| `3a6131f5` | **A6.** `ObjectiveAccessService::areaIdsFor()` og `reachesAllAreas()` holder menneskeforbeholdte nøkler (`objective.measure`, `objective.delete`) unna integrasjoner. `view` og `edit` når fagområdene som før. |
| `27405c7a` | Målinger og sletting i tjenestene. `KpiMeasurementService::record`/`withdraw` kaller vakten (`OBJECTIVE_MEASURE`) og deretter `objective.measure` på målet slik det står under lås. `ObjectiveLifecycleService::delete` og `KpiLifecycleService::delete` (nye) kaller vakten (`OBJECTIVE_DELETE`), `objective.delete` og historikkregelen under lås (409). `ObjectiveAccessService::authorize()`, og `canInArea()`, og dermed alle `can*()`, krever at kunden har modulen. |
| `f3072ba1` | Registerarbeid i tjenestene. `ObjectiveRegistration` (ny) og `KpiRegistration` (ny) har skrivingen som lå i kontrollerne, med `objective.edit`, eierregelen, flytteregelen og enhetslåsen, under lås. `KpiRegistration` skriver bare KPI-ens egne felt, aldri kunde, mål eller status. Lukk mål, Gjenåpne, Avslutt KPI, Gjenåpne KPI og Endre kobling sjekker `objective.edit` (og Kvalitet-lesing) i tjenesten. |
| `2d9aee4a` | Historikken i databasen (migrering `2026_10_23_000003`). En KPI med måling slettes ikke, og gjennom kaskaden heller ikke et mål med målinger. En måling endres bare ved å trekkes tilbake, én gang, og slettes bare med KPI-en. Statusendringer endres aldri. Å nulle en slettet bruker er tillatt, og kundens egen sletting tar alt med seg. Modellvakter i `Kpi` og `Objective`. E2E-opprydding gjennom `ObjectiveTestDataPurge`. |

#### Sikkerhetsregler

1. **Formelle beslutninger tas av personer.** Registrere og trekke tilbake målinger (B7), og slette mål og KPI-er.
   - Vakten nekter integrasjoner, inaktive personer og API-kanalen, også i delegert modus.
   - Deretter kreves nøkkelen i målets fagområde.
2. **Kundeseparasjon, fagområde og modul gjelder i tjenesten.** Hver skriving krever:
   - nøkkelen og `objective.view` i målets fagområde, fra samme rolle;
   - aktørens egen kunde, som må være målets;
   - at kunden har modulen.

   En annen kundes person og System Owner nektes, også med «Alle». En kunde uten modulen nektes. Tjenestene vurderer målet slik det står under lås. Et mål som nettopp er flyttet til et annet fagområde, vurderes der det står nå.
3. **System Owner uten rolle har verken beslutninger eller registerarbeid.** Uendret, og nå testet i tjenestene.
4. **Det som er målt, beholder historikken.** En KPI med måling og et mål med målinger slettes ikke, verken i tjenesten, modellen eller SQL. Målinger endres bare ved én tilbaketrekking. Statushistorikken er uforanderlig.
5. **Ordinært registerarbeid** (`objective.edit`) er ikke menneskeforbeholdt. En integrasjon som har nøkkelen, kan gjøre det (testet). Se A39.
6. **Overføring til Wiki** er ikke en beslutning. Tjenesten sjekker `objective.edit` i målets kunde og fagområde, nå også modulen. Resultatet behandles av personer i Wiki.
7. **Brukerne merker ingen endring.** Kontrollerne sjekker fortsatt først, så statuskoder og meldinger er uendret. Ingen nye statuser, skjermbilder, felt eller dialoger.

#### Tester

- `ObjectiveHumanDecisionTest` (11):
  - en integrasjon får ikke `measure` eller `delete` gjennom fagområdene, men `view` og `edit` som før;
  - en person med nøklene tar alle beslutningene, og historikken bærer navnet deres;
  - et mål eller en KPI med måling slettes ikke av tjenesten (409);
  - fire beslutninger kalles direkte på tjenestene og nektes, uten at noe skrives: integrasjon, API-kanal (delegert), inaktiv person, leser og redigerer, beslutningsnøkler i et annet fagområde, nøklene uten `objective.view`, nøkler og visning fra to roller, System Owner uten rolle, en annen kundes person, «Alle» og System Owner, og en kunde uten modul;
  - hver beslutningsnøkkel gir bare sine egne beslutninger;
  - et mål som nettopp er flyttet, vurderes der det står nå.
- `ObjectiveServiceAuthorizationTest` (9):
  - en redigerer og en integrasjon med `objective.edit` gjør registerarbeidet;
  - ti skrivinger nektes for leser, måler, sletter, redigering uten visning, et annet fagområde, System Owner uten rolle, en annen kundes redigerer og System Owner, og en kunde uten modul, og ingenting skrives;
  - Endre kobling krever Kvalitet-lesing;
  - statusreglene (lukket mål, avsluttet KPI), eierregelen og flytteregelen holdes av tjenestene;
  - registreringen skriver aldri kunde, mål eller status.
- `ObjectiveHistoryProtectionTest` (8): sletting uten målinger som før (også lukket mål og avsluttet KPI); tjeneste, modell og SQL nekter med historikk; alle endringer av en måling utenom én tilbaketrekking nektes; statushistorikken er uforanderlig; aktøren nulles når personen slettes; kundens sletting; oppryddingsveien.
- **Skrevet om:** `KpiMeasurementTest::test_an_objective_with_measurement_history_is_closed_not_deleted` låste den gamle regelen (en direkte sletting i databasen fjerner et mål med historikk). Den rydder nå gjennom `ObjectiveTestDataPurge`.
- **Fikstur:** kunden i `ObjectiveAccessServiceTest` har nå modulen, som alle andre fiksturer i modulen.
- **Mutasjonssjekket, 51 mutanter:**
  - A6 og tilgang (5): integrasjonsfratrekket i begge metodene, modulen, `authorize()`. Kundesjekken i `canInArea()` overlever, og er ekvivalent: fagområdene hentes fra aktørens egen kunde, så en annen kundes fagområde kan aldri treffe. Den beholdes som forsvar i dybden.
  - Beslutninger (12): vakten og regelen i hver tjeneste, feil nøkkel, historikkregelen og vurdering på gammel kopi. Den siste overlevde først fordi testens KPI ikke hadde målet lastet. Testen ble gjort lik kontrolleren og fanger den nå.
  - Registerarbeid (18): regelen i hver tjeneste, statusreglene, eierregelen, flytting til et fagområde uten `objective.edit`, egne felt, Kvalitet-lesing og overføringen til Wiki. Flyttemutanten overlevde først fordi eierregelen også slo til. En egen test fanger den nå.
  - Historikk (16): hver del av triggerne, unntaket for kundens sletting og for nulling av slettet bruker, og begge modellvaktene. Migreringen ble rullet tilbake og kjørt på nytt for hver mutant.
  - **Alle fanges, unntatt den ekvivalente kundesjekken.**

#### Tester og kjøringer (2026-10-23)

- **Grunnlinje før steget:** alle testfiler for Mål og KPI, filene som berører modulen fra andre moduler (fagområdeslettinger, moduler og pakker, varsler, Styring, Mine oppgaver, navigasjon, Kvalitet-koblinger, kunnskapsoverføring), `RiskAccessTest`, `RiskHumanDecisionTest`, `ImprovementHumanDecisionTest`, oversettelsestesten, `tests/Unit/Objectives` og `tests/Unit/Services/Security`: **514 av 514 bestått.**
- **PHP etter steget:** de samme filene, de tre nye, historikktestene i Risiko og Avvik, og alle filer som berører Ledelsens gjennomgåelse, Mine oppgaver, varsler, kunnskapsoverføring eller `HumanDecision` (75 filer): **1023 av 1023 bestått, ingen hoppet over.**
- **JS:** 2130 av 2130 bestått. Ingen frontendendring, så bygget er ikke kjørt på nytt (det er nyere enn siste frontendendring). Pint er rent på berørte filer.
- **E2E i det isolerte miljøet:** alle spesifikasjoner for Mål og KPI (9 filer), `my-tasks-governance`, `package-change`, `permission-sections` og `app-navigation` (13 filer, 48 tester), i én kjøring. Telt fra Playwrights JSON-rapport, med `caffeinate`.
  - **32 bestått, 16 feilet, 0 hoppet over.** Alle spesifikasjonene for Mål og KPI og de tre andre består.
  - **Alle 16 feilene er i `app-navigation`, og de skyldes ikke steget.** Feilen gjentar seg når filen kjøres alene (17 bestått, 16 feilet). Testene forventer at den delte brukeren `e2e.user` ser Wiki og Styring. I det isolerte miljøet har brukeren ingen roller, fordi `E2ETestSeeder` bevisst ikke lager roller. Dermed er `wiki.view`, `quality.view`, `risk.view` og `objective.view` alle `false` (kontrollert i E2E-appen). Menyen avhenger av `CustomerPermissionService`, som steget ikke endrer. Diffen fra `334380d8` berører bare filer i Mål og KPI.
  - **Ikke verifisert mot grunnlinjen med en egen kjøring.** Spesifikasjonen er ikke kjørt i det isolerte miljøet i noe tidligere steg. Den bør få en forutsetning (en rolle for `e2e.user`, som de andre spesifikasjonene lager selv) og føres som kjent testfeil til det er gjort. Ikke rettet her, fordi det ligger utenfor Mål og KPI.

**Migreringer:** én, `2026_10_23_000003_keep_objective_and_kpi_history`. Den legger bare til triggere og endrer ingen rader (utviklingsdatabasen: 2 mål, 1 KPI, 0 målinger). Den er reverserbar (`down()` er kjørt for hver av de 16 historikkmutantene). Kjørt på `procynia_test` og utviklingsdatabasen. E2E-databasen migreres av `scripts/e2e-env.sh up`.

**Endringer i brukeropplevelsen:** ingen. Skjemaer, knapper, meldinger, statuskoder og hvem som kan gjøre hva er som før. To forskjeller gjelder bare samtidige handlinger:
- En måling som registreres i samme øyeblikk som målet lukkes eller slettes, venter på den andre handlingen, i stedet for å bli lagret under et lukket mål.
- En ny KPI eller en endring som lagres i samme øyeblikk som målet lukkes, avvises med den eksisterende meldingen om at målet er lukket.

#### Funn som krever beslutning

| # | Funn | Anbefaling |
|---|---|---|
| A39 | **«Lukk mål» er registerarbeid (`objective.edit`), ikke menneskeforbeholdt.** Utfallet (Nådd, Ikke nådd, Avbrutt) er virksomhetens konklusjon om måloppnåelse. Det vises i Ledelsens gjennomgåelse og overføres til Wiki. «Avslutt KPI» avgjør at KPI-en ikke lenger skal måles, og fjerner den fra oppmerksomhet og Mine oppgaver. En integrasjon med `objective.edit` kan i dag gjøre begge deler og gjenåpne. Historikken er fullstendig (aktør, tidspunkt, utfall, begrunnelse). | Behandle «Lukk mål», «Gjenåpne», «Avslutt KPI» og «Gjenåpne KPI» som menneskelige beslutninger, etter samme mønster som A36: én ny verdi, `HumanDecision::OBJECTIVE_LIFECYCLE`, på `objective.edit`. Ingen endring i skjermbilder og ingen migrering. Alternativ: behold som i dag og løs det med føringen for agenter nedenfor. |
| A40 | **Et mål eller en KPI uten målinger kan slettes med statushistorikken sin.** Et mål som er lukket som «Nådd», med begrunnelse, kan slettes så lenge ingen KPI har målinger. Statusendringene følger med i kaskaden. Regelen er «registrert ved en feil», og den gjelder bare målinger. I Avvik (A13) og Risiko (B3) hindrer også statushistorikk sletting. | Lav–middels. Enkleste konsekvente løsning: et mål eller en KPI med statusendring slettes ikke (`isDeletable()`, og triggerne utvidet). Det er en synlig endring: «Slett» forsvinner etter første lukking. Alternativ: behold dagens regel. |

**Lav, ikke rettet:**
- Endringer i målverdi, toleranse, frekvens, eier og fagområde har ingen egen historikk. Bare siste verdi og `updated_by` lagres. Hver måling har likevel et øyeblikksbilde av målverdien den ble vurdert mot, så vurderingene i historikken står. Det er ordinært registerarbeid, som stamdata i Leverandøroppfølging.
- Koblinger til Kvalitet (`kpi_processes`, `kpi_activities`) har ingen historikk. De er ordinært registerarbeid.

**Klar for senere API-integrasjon**

- Ja. Lesing, beslutninger og registerarbeid ligger i domenet med samme regler for enhver inngang.
- Gjenstår før fase 1:
  - A10 (feilformat);
  - steg 19 (aktørtrigger for `kpi_measurements.recorded_by_user_id`);
  - A39.
- **Føring (anbefalt, som for de andre modulene):**
  - En AI-agent får ikke `objective.edit` som standard.
  - Forslag til mål, KPI-er og målverdier kan senere komme gjennom avgrensede API-operasjoner. De blir registrert først når en person tar dem i bruk.
  - Målinger (B7) og sletting forblir menneskeforbeholdt. Lukking og avslutning avhenger av A39.

## 3. Avgrensning

**Med i fase 0:**

1. Katalogen: `humanOnlyPermissions()` og den nye rettigheten `wiki.knowledge.handoff`.
2. `HumanDecision`, `HumanDecisionGuard` og `ActorChannel`.
3. Rettighetskontroll og menneskesperre i alle beslutningstjenester (§4.4).
4. Fjerning av null-aktører fra beslutninger.
5. Wiki-arbeidsflyten trukket ut av `WikiController` og `WikiClaimController` til tjenester.
6. Retting av F1a, F1b og F3a, siden de ligger i beslutningsflyten fase 0 likevel flytter. F2 får en statussjekk.
7. B3 (sletting av risiko) og B4 (godkjenning av krav).
8. Databasetriggere for beslutningskolonnene.
9. Revisjonsspor for tilgangsendringer (F4).
10. `HumanDecisionContractTest`.

**Ikke med i fase 0:**

- `role = integration` som brukbar kontotype, `User::scopeHumans()`, varselsperre og lisenstelling. Det hører til fase 1. Fase 0 legger bare inn konstanten og predikatet, slik at vakten og triggerne kan testes.
- API-ruter, tokenvalidering og `ApiRequestContext`.
- F3b (Kvalitets opplasting): uendret, men med en test som viser at en integrasjon aldri blir dokumenteier (fase 1).
- Retting av eksisterende sider uten oversettelser, og andre refaktoreringer som ikke henger sammen med dette.

**Uendret oppførsel for personer:** Ingen arbeidsflyt endres for mennesker, bortsett fra de fire produktbeslutningene og rettingen av F1a, F1b og F3a. Statuskoder, meldinger og fireøyneregelen i Wiki skal være de samme.

---

## 4. Design

### 4.1 Menneskeforbeholdte rettigheter

`CustomerPermissionCatalog::humanOnlyPermissions(): list<string>` og `isHumanOnly(string $key): bool`, i samme stil som `explicitGrantDomains()`:

```text
wiki.edit, wiki.review, wiki.approve, wiki.delete, wiki.source.manage,
quality.approve, quality.delete,
risk.assess, risk.accept, risk.delete,
objective.measure, objective.delete,
improvement.close, improvement.delete,
compliance.assess, compliance.audit, compliance.delete,
supplier.assess, supplier.assure, supplier.delete,
management_review.finalize, management_review.delete
```

Endringer fra §7.2 i arkitekturdokumentet:

- **`wiki.edit` er lagt til.** En manuell redigering av arbeidsversjonen merkes `CONTENT_ORIGIN_HUMAN_AUTHORED`. Hvis en agent gjorde den, ville opphavet være feil. Agenter når Wiki bare via kunnskapsoverføring (B1).
- **`objective.measure` er lagt til.** En måling er en registrert verdi som styrer KPI-status og oppmerksomhet. Den er verken et utkast eller et forslag. Om maskinregistrerte målinger skal tillates med egen kildemarkering, er et åpent spørsmål (§9, Å3).
- **`wiki.knowledge.handoff` er ikke med** (§4.2).

`CustomerPermissionService::has()` og `effectivePermissions()` trekker fra `humanOnlyPermissions()` når `$user->isIntegration()`. Trekket skjer *før* System Owner-snarveien i `has()` (:88–99).

### 4.2 Deling av `wiki.source.manage`

| Rettighet | Dekker | Menneskeforbeholdt |
|---|---|---|
| `wiki.source.manage` | Laste opp og slette kildedokumenter, bytte dokumenteier, starte, avbryte, prøve på nytt og fjerne kjøringer | Ja |
| **`wiki.knowledge.handoff`** (ny) | «Overfør til Wiki» fra Risiko, Avvik, Ledelsens gjennomgåelse, Etterlevelse og Kvalitet (`WikiKnowledgeHandoffService`, `QualityActivityArticleService`) | Nei. Resultatet er et kildedokument som går gjennom vanlig kjøring og menneskelig godkjenning. |

- Migrasjonen gir `wiki.knowledge.handoff` til hver rolle som har `wiki.source.manage` i dag, så dagens arbeidsflyt beholdes. Den må være reverserbar.
- `WikiKnowledgeHandoffService::canHandOff()` og `QualityController::authorizeActivityArticleHandover()` bytter fra `WIKI_SOURCE_MANAGE` til `WIKI_KNOWLEDGE_HANDOFF`.
- Oversettelser under `customer_env.roles.permissions.*` på norsk og engelsk.
- `deposit()` gjør aldri en integrasjon til dokumenteier. Det holder at `canBeEnterpriseWikiDocumentOwner()` returnerer `false` for integrasjoner, som er fase 1, men fase 0 får en test.

### 4.3 `HumanDecisionGuard`

```php
enum HumanDecision: string  // app/Support/HumanDecision.php
{
    case WIKI_PAGE_SUBMIT = 'wiki.page.submit';          // wiki.edit
    case WIKI_PAGE_APPROVE = 'wiki.page.approve';        // wiki.approve
    case WIKI_PAGE_RETURN = 'wiki.page.return';          // wiki.review
    case WIKI_PAGE_EDIT = 'wiki.page.edit';              // wiki.edit
    case WIKI_PAGE_DELETE = 'wiki.page.delete';          // wiki.delete
    case WIKI_CLAIM_DECIDE = 'wiki.claim.decide';        // wiki.review
    case WIKI_QA_ASSIGN = 'wiki.qa.assign';              // wiki.review
    case WIKI_DOCUMENT_OWNER_DECIDE = 'wiki.document_owner.decide'; // eierskap, wiki.view
    case WIKI_SOURCE_DELETE = 'wiki.source.delete';      // wiki.source.manage
    case WIKI_RUN_CANCEL = 'wiki.run.cancel';            // wiki.source.manage
    // … én verdi per beslutning i §4.4, hver med permission(): ?string
}
```

`App\Services\Security\HumanDecisionGuard::assert(?User $actor, HumanDecision $decision): User`

- Nekter med `HumanDecisionRefused`, som arver fra `AuthorizationException` og dermed gir 403, når:
  1. `$actor === null`, eller aktøren er inaktiv;
  2. `$actor->isIntegration()` (`User::ROLE_INTEGRATION = 'integration'`; i fase 0 finnes ingen slike rader);
  3. `ActorChannel::isApi()` er sann. `ActorChannel` er et grensesnitt bundet i containeren. Fase 0 leverer bare `InteractiveActorChannel` (alltid `false`). `ApiRequestContext` i fase 1 implementerer det, også for delegert modus (B2).
- Returnerer aktøren som `User`, slik at tjenesten kan bruke den uten nye null-sjekker.
- **`users.role = NULL` er et menneske.** Kolonnen er nullbar og har ingen CHECK, og mange fikstures har `NULL`.
- Vakten gir aldri rettigheter. Tjenesten kaller vakten først og deretter sin egen tilgangsregel, inne i samme transaksjon og radlås som skrivingen.
- Systemstier (jobber, kommandoer, seeders) fatter ingen menneskelige beslutninger og kaller ikke vakten. Kartleggingen fant ingen systemsti som publiserer, godkjenner eller avgjør. Nullstilling av en påstand til `pending` og kopiering av en eksisterende beslutning til en ny versjon (`claimClonePayload`) er ikke beslutninger.

### 4.4 Beslutningstjenester

Mønsteret i hver tjeneste:

```php
public function accept(User $actor, Risk $risk, …): RiskAcceptance
{
    $actor = $this->guard->assert($actor, HumanDecision::RISK_ACCEPT);
    $this->authorize($this->access->canAccept($actor, $risk));   // AuthorizationException → 403
    return DB::transaction(function () { … });
}
```

Kontrolleren beholder sine sjekker *før* tjenestekallet, slik at statuskoder og meldinger er uendret. Sikkerheten ligger i tjenesten.

| Domene | Tjeneste og metode (ny eller endret) | Tilgangsregel som flyttes inn | Fra |
|---|---|---|---|
| **Wiki** | **Ny** `EnterpriseWikiPageReviewService`: `submit`, `approve`, `returnToOwner`, `reopenRejected`, `assignQa` | `WIKI_EDIT`/`APPROVE`/`REVIEW`, `canApproveWikiPages`, `finalApprovalBlocker`, `mayActOnCurrentVersion`, `canSubmitEnterpriseWikiPage`, `isEligibleQaUser` | `WikiController` :2955–3330, :3455–3692 |
| | **Ny** `EnterpriseWikiClaimDecisionService`: `approve`, `reject`, `unapprove`, `setBlockingOverride`, `attachSourceReference` | `canHandleClaimForPage`, `abortIfAlreadyDecided`, kaskader | `WikiClaimController` :63–681 |
| | `EnterpriseWikiDocumentOwnerApprovalService::decide` | `canDecide`, sperre for allerede avgjort rad (O1), radlås | Kontroller :103 |
| | `EnterpriseWikiClaimContentRepairService::applyWorkingVersionBlockEdits` / `applyManualMixedBlockEdit` | Finnes (`canSubmitEnterpriseWikiPage` / `canApproveWikiClaims`). Ny: `wiki.edit`/`wiki.review` i tjenesten, regel for åpen godkjenning (F1a), oppgjør av publisering (F1b) | Kontroller :2302, :2147 |
| | `EnterpriseWikiPageDeletionService::deletePage(s)` | `wiki.delete` og `canDeleteEnterpriseWikiPage`. Gjelder også kallet fra Kvalitet (F3a). | `WikiController::destroy`, `QualityController::destroyItem` |
| | `EnterpriseWikiDocumentDeletionService::delete`, `EnterpriseWikiDocumentFlowService::cancelRun`, `EnterpriseWikiRunDismissalService::dismiss` | `wiki.source.manage` og `canDeleteEnterpriseWikiDocument` | `WikiSourceController`, `WikiController` |
| | `WikiKnowledgeHandoffService::handOff` / `deposit` | `canHandOff` (med `wiki.knowledge.handoff`) og grensen på 20 | `WikiKnowledgeHandoffController` |
| **Risiko** *(gjort, §2c)* | `RiskAcceptanceService::accept`/`revoke` | **Ny** `RiskAccessService::canAccept` | `RiskAcceptanceController:73` |
| | **Ny** `RiskAssessmentService::record` (trukket ut) | **Ny** `canAssess` | `RiskAssessmentController:41–54` |
| | **Ny** `RiskLifecycleService::delete` | `can(RISK_DELETE)` og `isDeletable()` (B3) | `RiskController:249–256` |
| **Avvik** *(gjort, §2d)* | `ImprovementCaseLifecycleService::close`/`cancel`/`reopen`, `ImprovementActionLifecycleService::verify` | `canClose` | `ImprovementCaseController:343–377`, `ImprovementActionController:147` |
| | `ImprovementCaseLifecycleService::delete` (ny) | `canDelete` og `isDeletable` | `ImprovementCaseController:388–399` |
| **Mål/KPI** | `KpiMeasurementService::record`/`withdraw` | `canMeasure` | `KpiMeasurementController:119–123` |
| | `ObjectiveLifecycleService::delete`, `KpiLifecycleService::delete` (nye) | `canDelete` og `isDeletable` | `ObjectiveController:310`, `KpiController:197` |
| **Etterlevelse** | `ComplianceAssessmentService::assess` | `canAssess` | `ComplianceRequirementController:363` |
| | `ComplianceAuditLifecycleService::start`/`complete`/`cancel`/`reopen` | `canAudit` | `ComplianceAuditController:286–333` |
| | `ComplianceRequirementLifecycleService::delete`, `ComplianceAuditLifecycleService::delete` (nye) | `canDelete` | `ComplianceRequirementController:450`, `ComplianceAuditController:504` |
| **Leverandør** *(gjort, §2j)* | `SupplierAssessmentService::record` | `canAssess` | `SupplierAssessmentController:36` |
| | `SupplierLifecycleService::delete` | `canDelete` | `SupplierManagementController:530` |
| | `supplier.assure`-tjenestene | **Sjekker allerede selv.** Får bare vakten. | – |
| **Ledelsens gjennomgåelse** *(gjort, §2e)* | `ManagementReviewFinalizationService::finalize`, `ManagementReviewService::addAmendment` | `canFinalize` | `ManagementReviewController:217, :248` |
| | `ManagementReviewService::delete` | `canDelete` | `ManagementReviewController:156` |
| **Kvalitet** | `QualityProcessBlueprintService::approve` | `quality.approve` | `QualityController:1004` |
| | Godta AI-forslag til flyt (`adoptFlowProposal`, `acceptFlowChange`) | `quality.edit`. Beslutningen `QUALITY_FLOW_ADOPT` er menneskeforbeholdt selv om rettigheten ikke er det. | `QualityController:689, :944` |
| | `QualityItemService::deleteItem` | `quality.delete` | `QualityController:393` |
| **Anbud** | `SavedNoticeStatusService::transition` (ny, trukket ut av `NoticeController:1281`), `SavedNoticeNoGoDecisionService` | `canManage` på saken | `NoticeController::updateSavedNoticeStatus` |
| | `RequirementEditorService::transitionRequirementReviewStatus` og wrapperne | B4 (§4.7) | `AiController:792, :867` |

### 4.5 Null-aktører

Disse skal kreve `User $actor`:

- `QualityProcessBlueprintService::approve` og `store`
- `QualityItemService::updateItem`
- `QualityFlowClarificationService::dismiss`/`resolve`
- `RequirementEditorService::transitionRequirementReviewStatus` og `approveRequirement`/`rejectRequirement`/`restoreRequirement`
- `SavedNotice::transitionBidStatus`. Erstattes av `SavedNoticeStatusService::transition(User $actor, …)`, som skriver historikk (§4.8).

Disse forblir nullbare og dokumenteres som systemstier: `ModuleEntitlementService::grantDefaultPackage`, `ComplianceFrameworkActivationService::sync` (seeder), `AiRuntimeControlService` (CLI), `BillingService`, `DoffinNoticeWorkflowService` og `RequirementEditorService::createAiCandidate`.

### 4.6 Sletting av risiko (B3)

- `Risk::isDeletable()` er sann bare når risikoen ikke har noen `risk_assessments`, `risk_acceptances` (også tilbakekalte) eller `risk_treatment_actions`. Samme mønster som `Kpi::isDeletable()`.
- `RiskLifecycleService::delete()` nekter ellers, og webgrensesnittet viser «Slett» deaktivert med begrunnelse og peker til «Lukket».
- «Arkivering» er dagens status `Risk::STATUS_CLOSED`. Ingen ny status innføres i fase 0. Om lukkede risikoer skal skjules fra registeret som standard, er et åpent spørsmål (§9, Å4).
- **Forsvar i databasen:** en `BEFORE DELETE`-trigger på `risks` nekter når det finnes vurderinger eller aksepter. Unntaket er sletting av kunden: `NOT EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)`, samme mønster som `supplier_assurance_decisions`.
- Eksisterende testfikstures som sletter risikoer med historikk, må tilpasses.

### 4.7 Godkjenning av krav i Anbud (B4)

Anbud bruker rollebaserte rettigheter (`users.bid_role` og Tilganger-matrisen `customers.permission_settings`), ikke `CustomerPermissionCatalog`. Den nye regelen bruker den eksisterende modellen:

1. **Ny rettighet** `Customer::PERMISSION_DECIDE_BID_REQUIREMENTS = 'decide_bid_requirements'` i `DEFAULT_PERMISSION_SETTINGS`. Standard er `['system_owner', 'bid_manager', 'contributor']`, så alle som kan behandle saker i dag, beholder muligheten. Den vises og kan strammes inn i Kundemiljø → Tilganger, og QA-kolonnen virker som for de andre.
2. **Saksnivå:** `SavedNoticeAccessService::canManage($actor, $notice)`. Dermed er lesere utelukket, både anbudsrollen `viewer` og per-sak-tilgang `viewer`.
3. **Ny metode** `User::canDecideBidRequirements()` speiler `canApproveWikiClaims()`.
4. **Ny** `HumanDecision::TENDER_REQUIREMENT_DECIDE` med påkrevd aktør. `approved_by_user_id`/`rejected_by_user_id` og revisjonens `changed_by_user_id` er da aldri `NULL` for nye beslutninger.
5. **Grensesnittet** skjuler eller deaktiverer «Godkjenn/Avvis» (også «Avvis alle») når rettigheten mangler. Sideprop `can_decide_requirements` deles fra kontrolleren.

Konsekvens for dagens arbeidsflyt: Brukere med bare lesetilgang mister godkjenningen. Det er beslutningen. Alle andre beholder den til en System Owner endrer matrisen.

### 4.8 Databasetiltak

| Tiltak | Tabeller | Merknad |
|---|---|---|
| Delt funksjon `assert_human_decision_actor(bigint, text)` og `BEFORE INSERT OR UPDATE OF <kolonne>`-triggere | **Wiki:** `enterprise_wiki_pages.reviewed_by_user_id`; `enterprise_wiki_claims.approved_by_user_id`, `blocking_override_by_user_id`; `enterprise_wiki_claim_decisions.decided_by_user_id`; `enterprise_wiki_page_version_document_owner_approvals.decided_by_user_id`, `overridden_by_user_id`; `enterprise_wiki_page_versions.submitted_by_user_id`, `reviewer_user_id`. <br>**Risiko:** `risk_acceptances.accepted_by_user_id`, `revoked_by_user_id`; `risk_assessments.assessed_by`. <br>**Avvik:** `improvement_cases.closed_by_user_id`; `improvement_action_verifications.verified_by_user_id`. <br>**Mål/KPI:** `kpi_measurements.recorded_by_user_id`. <br>**Etterlevelse:** `compliance_assessments.assessed_by_user_id`; `compliance_audit_status_changes.changed_by_user_id`. <br>**Leverandør:** `supplier_assessments.assessed_by_user_id`; `supplier_requirement_evaluations.evaluated_by_user_id`; `supplier_requirement_overrides.created_by_user_id`; `supplier_due_diligence_assessments.assessed_by_user_id`. <br>**Ledelsens gjennomgåelse:** `management_reviews.finalized_by_user_id`; `management_review_amendments.created_by_user_id`. <br>**Kvalitet:** `quality_process_revisions.approved_by_user_id`; `quality_process_blueprints.approved_by_user_id`. <br>**Anbud:** `saved_notice_ai_requirements.approved_by_user_id`, `rejected_by_user_id` | Nekter bare `role = 'integration'`. NULL tillates, fordi `nullOnDelete` må virke. Kopier av menneskelige beslutninger passerer. `CREATE OR REPLACE`, pgsql-vakt, og `down()` fjerner triggere og funksjon. |
| `BEFORE DELETE` på `risks` | `risks` | B3, §4.6 |
| **Ny** `saved_notice_status_changes` (append-only, trigger mot UPDATE/DELETE) | `saved_notice_id`, `from_status`, `to_status`, `changed_by_user_id`, `changed_at`, `reason` | Samme mønster som `*_status_changes` i andre moduler. Erstatter manglende aktør i `transitionBidStatus`. |
| **Ny** `customer_access_changes` (append-only) | `customer_id`, `actor_user_id`, `subject_type` (user/role/permission_settings), `subject_id`, `change_type`, `before` (jsonb), `after` (jsonb), `created_at` | F4. Skrives fra `UserController::update`/`store`/`toggleActive`/`syncCustomerRoles`, `CustomerRoleController` og `CustomerEnvironmentController::updatePermissions`. Bare rettighetsrelevante felt, ingen e-post eller passord. |
| Valgfritt: `enterprise_wiki_ingest_runs.started_by_user_id`, `cancelled_by_user_id` | | O4. Kan utsettes uten å påvirke sikkerheten. |
| Datakontroll før triggere | Lesespørring mot dev-databasen: godkjente eller avgjorte rader uten aktør | Ingen NOT NULL- eller CHECK-endringer på eksisterende data i fase 0. |

### 4.9 Trekke tilbake fra godkjenning (B5)

- **Handling:** «Trekk tilbake» på Wiki-siden, `PATCH /app/wiki/{slug}/withdraw`. Den vises for den som kan sende siden inn (`canSubmitEnterpriseWikiPage`: sideeier eller System Owner, med `wiki.edit`) når siden har status `pending_review`.
- **Tjeneste:** `EnterpriseWikiPageReviewService::withdraw(User $actor, EnterpriseWikiPage $page, ?string $reason)` (ny). Den kaller `HumanDecisionGuard` (`WIKI_PAGE_WITHDRAW`) og rettighetsregelen, og gjør alt under sidelåsen:
  1. siden står fortsatt i `pending_review`, ellers 409;
  2. en `EnterpriseWikiPageReviewEvent` med `event_type = withdrawn`, `actor_role = page_owner`, aktør, tidspunkt og valgfri begrunnelse, knyttet til den innsendte versjonen. Hvem versjonen var tildelt, går ikke tapt: det ligger i den nye kolonnen `assigned_reviewer_user_id` på hendelsen;
  3. tildelingen på versjonen fjernes (`submitted_by_user_id`, `submitted_at`, `reviewer_user_id` settes til `NULL`, som i `reopenRejectedPage`). Dermed har verken kontrolløren eller andre noen beslutning å ta;
  4. siden settes til `draft`. `published_version_id`, `reviewed_*` og QA-tildelingen er uendret.
- **Tilgang:** ikke kontrolløren og ikke en annen godkjenner. Det er innsenderens handling. Kontrolløren har «Send tilbake».
- **Visning:** historikken over returer (`changesRequestedPayload`) viser bare `changes_requested`. Tilbaketrekkingen gir ikke et «Sendt tilbake»-banner, men oppgaven i «Mine oppgaver» forsvinner, fordi den aggregeres fra status.
- **Varsel:** kontrolløren får et varsel i bjella om at siden er trukket tilbake. Ellers står et ulest varsel om en godkjenning som ikke lenger finnes.

### 4.10 Dokumenteiers beslutninger som hendelser (B9)

- Ny append-only tabell `enterprise_wiki_document_owner_decisions` med approval-rad, beslutning, kommentar, aktør, overstyring og tidspunkt. Triggeren nekter UPDATE og DELETE, med unntak for kundesletting.
- `decide()` skriver alltid en ny rad. Raden i `…_document_owner_approvals` holder siste tilstand, som i dag, men en endring av en avgjort rad krever begrunnelse og logges som en ny hendelse. Den overskriver aldri uten spor.
- Del av steg 5.

---

## 5. Rekkefølge og commits

Hver linje er én commit. Hver commit kjører sine målrettede tester før den lages.

| Steg | Commit | Innhold | Avhenger av |
|---|---|---|---|
| 0 | – | Grunnlinje: kjør testfilene i §6.3 på HEAD og noter feil som finnes fra før (worktree-metoden). | – |
| 1 | `feat(security): add human-only permissions and HumanDecisionGuard` | `ROLE_INTEGRATION`, `User::isIntegration()`, `humanOnlyPermissions()`, trekket i `has()`/`effectivePermissions()`, `HumanDecision`, `HumanDecisionGuard`, `ActorChannel` + `InteractiveActorChannel`, `HumanDecisionRefused` og enhetstester | 0 |
| 2 | `feat(wiki): separate knowledge handoff from source management` | `wiki.knowledge.handoff`, datamigrasjon, oversettelser, `canHandOff` og Kvalitets overføring bytter rettighet, og `handOff`/`deposit` kaller `canHandOff` selv | 1 |
| 3a | `feat(wiki): let the page owner withdraw a page from review` | B5, §4.9: rute, tjeneste, hendelse, varsel, knapp, oversettelser og PageHelp | 1 |
| 3b | `fix(wiki): no direct edits under review; settle publication after claim repair` | **F1a og F1b.** Gjelder `EnterpriseWikiClaimContentRepairService` (begge stier) og `workingVersionEditContext`/`manualMixedBlockEditContext`. Testene for F1a og F1b skrives om til den nye regelen. | 3a |
| 3c | `fix(wiki): only the assigned reviewer decides a version in review` | O6 | 3a |
| 4 | `fix(quality): require wiki.delete before deleting produced Wiki pages` | **F3a**: sjekken legges i `EnterpriseWikiPageDeletionService` (felles for Wiki og Kvalitet) og i `QualityController::destroyItem` for en god feilmelding. Testen skrives om til «nektes, ingenting slettes». | 1 |
| 5 | `refactor(wiki): move document-owner, deletion and run decisions into services` | O1 og B9 (§4.10): `decide` med `canDecide`, radlås og hendelsestabell. Slettetjenester, `cancelRun` og `dismiss` får vakt og tilgang. | 1 |
| 6 | `refactor(wiki): extract page review workflow into EnterpriseWikiPageReviewService` | submit, approve, return, reopen, QA-tildeling. Koden flyttes uendret. `EnterpriseWikiPublicationInvariantArchitectureTest` godkjenner den nye metoden i stedet for `WikiController::approve`. | 3, 5 |
| 7 | `refactor(wiki): extract claim decisions into EnterpriseWikiClaimDecisionService` | approve, reject, unapprove, blocking, kildereferanse, med kaskader i samme transaksjon | 6 |
| 8 | `fix(wiki): guard FinalizeEnterpriseWikiIngest against pages in review` | **F2**: oppdater bare `status IN (draft)`. Testene får en ekstra påstand. | – |
| 9 | `refactor(risk): decisions and deletion in services; keep risk history` | `canAccept`/`canAssess`, `RiskAssessmentService`, `RiskLifecycleService::delete`, **B3** med trigger. **Gjort** som fire commits (§2c). | 1 |
| 10 | `refactor(improvements): guard close, cancel, reopen, verify and delete` | **Gjort** som fire commits (§2d) | 1 |
| 11 | `refactor(management-review): guard finalize, amendment and delete` | **Gjort** som tre commits (§2e) | 1 |
| 12 | `refactor(quality): require an actor and guard flow approval and adoption` | Null-aktører og `QUALITY_FLOW_ADOPT` | 1 |
| 13 | `refactor(compliance): guard assessment, audit lifecycle and delete` | **Gjort** som tre commits (§2i) | 1 |
| 14 | `refactor(suppliers): guard assessment and delete; guard assure services` | **Gjort** som tre commits (§2j) | 1 |
| 15 | `refactor(objectives): guard measurements and delete` | **Gjort** som fire commits (§2k) | 1 |
| 16 | `feat(tender): require an explicit permission to decide requirements` | **B4**, `RequirementEditorService` uten null-aktør, rettighet i Tilganger, grensesnitt | 1 |
| 17 | `feat(tender): record who changes a bid status` | `SavedNoticeStatusService` og `saved_notice_status_changes` | 1 |
| 18 | `feat(security): audit trail for access changes` | **F4**: `customer_access_changes`. `AdministrativeChangeAuditTrailTest` skrives om til «sporet skrives». | – |
| 19 | `feat(security): reject integration actors in decision columns` | Triggerne i §4.8 og datakontrollen | 9–17 |
| 20 | `test(security): HumanDecisionContractTest` | §6.1 | 1–19 |
| 21 | `docs: phase 0 complete` | Status i `external-api-architecture.md` §7/§14, `docs/operations/security.md`, `enterprise-wiki-approval-model.md` (F1-regelen) | 20 |
| 22 | – | Merge-gate: full PHP-suite og relevante E2E-tester. Jeg spør før kjøring. | 21 |

Steg 3, 4 og 8 kan tas først fordi de retter bekreftede feil. Steg 9–17 er uavhengige av hverandre og kan tas i valgfri rekkefølge. Anbefalt rekkefølge følger risiko.

**Regel for F1a og F1b (steg 3), besluttet (B5):**

- *En side som ligger til godkjenning, kan ikke redigeres.* «Rediger» og påstandsreparasjon vises deaktivert med en begrunnelse. Tjenesten nekter med 409.
- For å endre noe trekker eieren siden tilbake («Trekk tilbake», §4.9), eller kontrolløren sender den tilbake.
- Påstandsreparasjon på en publisert side kaller `afterManualEdit()`, og siden går tilbake til utkast, som ved vanlig redigering.
- En versjon uten kontrollør kan ikke avgjøres av en generell godkjenner (O6).

Steg 3 deles i tre commits:

- 3a: «Trekk tilbake»
- 3b: ingen direkte redigering under godkjenning, og oppgjør av publisering etter påstandsreparasjon
- 3c: innstramming av O6

---

## 6. Teststrategi

### 6.1 Nye tester

1. **`HumanDecisionGuardTest`** (enhet): null-aktør, inaktiv aktør, `role = integration` og API-kanal nektes. `role = NULL`, `user`, `customer_admin` og `super_admin` slippes gjennom.
2. **`CustomerPermissionCatalogHumanOnlyTest`:**
   - `humanOnlyPermissions()` er en delmengde av `all()`, og `wiki.knowledge.handoff` er ikke med.
   - En integrasjon får aldri en menneskeforbeholdt rettighet, heller ikke med System Owner-rolle eller en rolle som har alle rettigheter.
3. **`HumanDecisionContractTest`** (strukturell, i stil med `EnterpriseWikiPublicationInvariantArchitectureTest`):
   - Hver `HumanDecision` har en registrert tjenestemetode, og kilden til metoden inneholder `->assert(` med samme enum-verdi.
   - Hver menneskeforbeholdt rettighet har minst én `HumanDecision`.
   - Hver beslutningsmetode kaster for null-aktør, integrasjonsaktør og API-kanal. Testen er datadrevet og bruker en fake `ActorChannel`.
   - Ingen kontroller skriver beslutningskolonnene direkte (skanner etter `'approved_by_user_id' =>`, `'reviewed_by_user_id' =>` og lignende i `app/Http/Controllers`).
4. **Tilgang på tjenestenivå** per domene: tjenesten kalles direkte med et menneske uten rettighet og skal gi `AuthorizationException`. Det beviser at sikkerheten ikke ligger i kontrolleren.
5. **Triggertester** på Postgres med savepoint-mønsteret fra `ImprovementActionVerificationTest::assertRefused`. Integrasjonsaktør nektes, menneske og NULL tillates, og sletting av en kunde kaskaderer fortsatt.
6. **B3:** en risiko med vurdering eller aksept kan ikke slettes (service, HTTP og trigger), mens en risiko uten historikk kan det.
7. **B4:**
   - En leser, enten anbudsrollen `viewer` eller per-sak-`viewer`, får 403.
   - En bidragsyter uten rettigheten i matrisen får 403, og en med den får godkjenne.
   - Aktøren lagres.
   - «Avvis alle» følger samme regel.
8. **Brukerreisen** (AGENTS.md): én test per rettet arbeidsflyt som viser at brukeren kan fullføre oppgaven. Det gjelder sideeier → godkjenning → publisering etter en reparasjon (F1b), og trekk tilbake → rediger → send inn på nytt (F1a).

### 6.2 Funn-testene

De fem testene fra §2 er karakteriseringstester. De skrives om i commiten som retter funnet, aldri før, slik at overgangen er synlig i historikken.

### 6.3 Regresjon per steg (målrettet, ikke hele suiter)

| Steg | Testfiler |
|---|---|
| 1, 2 | `CustomerRolePermissionTest`, `WikiPermissionTest`, `WikiKnowledgeHandoffTest`, `RiskWikiKnowledgeTest`, `ManagementReviewWikiKnowledgeTest`, `QualityActivityControlTest`, `NavigationEntitlementMatrixTest`; E2E `knowledge-handoff.spec.js` |
| 3 | `WikiWorkingVersionEditControllerTest`, `WikiManualMixedBlockEditControllerTest`, `WikiAuthoritativeManualEditTest`, `EnterpriseWikiClaimContentRepairServiceTest`, `EnterpriseWikiOpenReviewProtectionTest`, `EnterpriseWikiPublishedVersionTest` |
| 4 | `QualityItemTest`, `EnterpriseWikiPageDeletionTest` |
| 5–7 | `EnterpriseWikiFinalReviewTest`, `EnterpriseWikiReviewAssignmentTest`, `EnterpriseWikiReviewerCandidateTest`, `EnterpriseWikiPageReviewCapabilityTest`, `EnterpriseWikiSystemOwnerApprovalTest`, `EnterpriseWikiChangesRequestedTest`, `WikiPublishIsExplicitTest`, `EnterpriseWikiQaAssignmentTest`, `EnterpriseWikiReviewNotificationTest`, `EnterpriseWikiPublicationInvariantArchitectureTest`, `WikiClaimControllerTest`, `WikiClaimBlockingOverrideTest`, `WikiBestPracticeSectionDecisionTest`, `WikiQaFindingWorkflowTest`, `EnterpriseWikiDocumentOwnerApprovalFlowTest`, `WikiSourceControllerTest`, `WikiRunDismissalTest`, `EnterpriseWikiDocumentFlowServiceTest`, `InfoCenterWikiReviewTaskTest`, `InfoCenterWikiQaTaskTest`; E2E `wiki-runs-*.spec.js` |
| 8 | `FinalizeEnterpriseWikiIngestTest`, `EnterpriseWikiIngestFlowTest` |
| 9 | `RiskAccessTest`, `RiskAccessCharacterizationTest` og risikotestene for aksept, vurdering og tiltak |
| 10–15 | Modulens egne feature-tester, for eksempel `ImprovementCaseTest`, `ManagementReviewTest`, `QualityPermissionTest`, `ComplianceAssessmentTest`, `SupplierAccessTest`, `ObjectiveTest`, `KpiMeasurementTest` |
| 16, 17 | `AiControllerTest` (krav), `CustomerSavedNoticeWorklistTest` (status og No-Go), `GoNoGoAssessmentTest` |
| 18 | `CustomerQaAccessTest`, `CustomerRolePermissionTest`, `AdministrativeChangeAuditTrailTest` |
| 19 | Triggertestene, og migreringen kjøres med `--env=testing` |

Testene kjøres med `docker exec procynia-app php artisan test …`. JS-tester kjøres i `node:22`-Docker. Frontendendringer krever `npm run build` før E2E.

---

## 7. Sikkerhetskrav (akseptansekriterier)

Fase 0 er ferdig når alle punktene er oppfylt og har test:

1. **K1** Hver beslutning i §4.4 går gjennom en tjenestemetode som kaller `HumanDecisionGuard::assert()` og sin egen tilgangsregel før skriving, i samme transaksjon. `HumanDecisionContractTest` er grønn.
2. **K2** Ingen kontroller skriver en beslutningskolonne direkte (strukturell test).
3. **K3** Ingen beslutningstjeneste tar `?User` eller en standardverdi `null` for aktøren. Unntakene i §4.5 er dokumentert som systemstier.
4. **K4** En integrasjonsaktør og enhver forespørsel med API-kanal nektes alle beslutninger, også når aktøren har System Owner-rolle eller en rolle som har alle rettigheter, og også i delegert modus.
5. **K5** Databasen nekter integrasjonsaktører i alle beslutningskolonnene i §4.8.
6. **K6** F1a: en side som ligger til godkjenning, kan ikke få en ny gjeldende versjon fra en person, og bare den tildelte kontrolløren (eller System Owner ved retur) kan avgjøre den. Sideeieren kan trekke den tilbake. Det registreres med aktør og tidspunkt, ugyldiggjør tildelingen, og publisert versjon er uendret (B5).
7. **K7** F1b: enhver manuell eller reparert versjon av en publisert side setter siden til utkast, og den kan sendes til godkjenning.
8. **K8** F3a: sletting av en Wiki-side krever `wiki.delete` og eierskap, uansett inngang.
9. **K9** B3: en risiko med historikk kan ikke slettes, verken via tjeneste, HTTP eller direkte SQL. Kundesletting virker fortsatt.
10. **K10** B4: krav-godkjenning krever `decide_bid_requirements` og `canManage`, og aktøren lagres alltid.
11. **K11** F4: hver endring av anbudsrolle, QA, Wiki-godkjenner, kunderoller, rollens rettigheter og Tilganger-matrisen gir en rad i `customer_access_changes` med aktør.
12. **K12** `wiki.knowledge.handoff` er skilt fra `wiki.source.manage`, og eksisterende roller har beholdt muligheten.
13. **K13** Ingen endret oppførsel for personer utover K6–K12. Regresjonstestene i §6.3 er grønne mot grunnlinjen fra steg 0.
14. **K14** Merge-gate: full PHP-suite og relevante E2E-tester er grønne, eller avvikene er bevist eksisterende fra før.

---

## 8. Regresjonsrisiko

| Risiko | Tiltak |
|---|---|
| Statuskoder eller meldinger endres fordi tjenesten sjekker i en annen rekkefølge enn kontrolleren | Kontrollerens sjekker beholdes først. Tjenestens sjekker er forsvar i dybden og skal aldri være de som slår til ved vanlig bruk. |
| Kaskader i påstandsbeslutninger (beste praksis på tvers av sider, samme seksjon) mister transaksjonen | Hele kaskaden flyttes uendret inn i tjenestens transaksjon (steg 7). Eksisterende kaskadetester er grønne. |
| Myndighetsforskjellen mellom godkjenn og returner (O5) forsvinner | Begge reglene flyttes uendret. `EnterpriseWikiSystemOwnerApprovalTest` dekker forskjellen. |
| To godkjenningslag i Wiki (katalogen og den eldre matrisen `canApproveWikiPages`/`canApproveWikiClaims`) | Begge flyttes med, i samme rekkefølge. |
| `EnterpriseWikiPublicationInvariantArchitectureTest` godkjenner bare `WikiController::approve` | Den nye metoden godkjennes i samme commit som flyttingen. Det er en bevisst flytting, ikke en svekkelse. |
| Triggere bryter fikstures som setter `role = NULL` eller bruker `nullOnDelete` | Triggerne nekter bare `integration`, og NULL tillates. |
| `migrate:fresh` etterlater funksjoner | `CREATE OR REPLACE` og `DROP FUNCTION IF EXISTS` i `down()` |
| B3 bryter tester som sletter risikoer med historikk | Fiksturene tilpasses i commiten. Funnet er tilsiktet. |
| B4 tar godkjenning fra lesere som bruker den i dag | Tilsiktet (B4). Standard i matrisen beholder alle som kan behandle saker. |
| F1a-regelen stopper en sideeier som i dag redigerer under godkjenning | Tilsiktet. Grensesnittet forklarer hvorfor og hva som er neste steg. |
| Datamigrasjonen for `wiki.knowledge.handoff` mister en rolle | Testen sammenligner rollesettet før og etter, og `down()` reverserer. |
| Kjente feilende tester fra før tilskrives fase 0 | Grunnlinje via git worktree i steg 0 |
| Køarbeidere kjører gammel kode | Restart `queue-*` før en ekte kjøring brukes som bevis. |

---

## 9. Åpne spørsmål

| # | Spørsmål | Anbefaling |
|---|---|---|
| Å1 | Trekke tilbake fra godkjenning | **Besluttet: B5** |
| Å2 | Agentopplasting av kildedokumenter | **Besluttet: B6** (ikke i fase 0) |
| Å3 | KPI-målinger fra integrasjoner | **Besluttet: B7** (ikke i fase 0) |
| Å4 | Visning av lukkede risikoer | **Besluttet: B8** (uendret) |
| Å5 | Dokumenteiers beslutning kan endres | **Besluttet: B9** (ny beslutning er en egen hendelse, aldri en overskriving) |
| Å6 | F1c: hvem kan endre tekst via påstandsbeslutninger under godkjenning | **Besluttet:** bare tildelt kontrollør (`2af1ba86`). |
| Å7 | F1d | **Besluttet 2026-10-22:** ny arbeidsversjon, publisert versjon uendret (`4e827d93`). |

---

## 10. Verifisering av funnene

Kjørt 2026-10-22 på `feature/external-api-foundation`:

```text
docker exec procynia-app php artisan test \
  tests/Feature/App/Wiki/WikiManualEditDuringReviewFindingTest.php \
  tests/Feature/App/Wiki/EnterpriseWikiClaimContentRepairServiceTest.php \
  tests/Feature/App/Wiki/FinalizeEnterpriseWikiIngestTest.php \
  tests/Feature/App/QualityItemTest.php \
  tests/Feature/Security/AdministrativeChangeAuditTrailTest.php
→ 80 passed (483 assertions)
```
