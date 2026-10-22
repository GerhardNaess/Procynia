# Eksternt API – implementeringsplan for fase 0

**Status:** Planen er godkjent 2026-10-22 med beslutningene B5–B9 (§1). Implementeringen er startet i rekkefølgen i §5.

**Fremdrift:**
- Ferdig: steg 1 (`3a0d009d`), 3a (`7962a744`), 3b (`dcb7d823`), 3c (`5eac50a4`), E2E (`a11y-wiki.spec.js`), F1c (`2af1ba86`), steg 2 (`cd42a7f2`) og steg 4 / F3a (`d6f58377`).
- F1a, F1b og O6 er rettet.
- F1c er rettet (`2af1ba86`). F1d er bekreftet og venter på beslutning (Å7).
- Kjente testfeil på grunnlinjen: [`docs/testing/known-test-failures.md`](testing/known-test-failures.md) (KTF-1).
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
| O2 | `RiskController::destroy()` sletter risikoen. Databasekaskade fjerner vurderinger og aksepter, og modellvaktene kjører ikke ved kaskade. | Løses av B3 (§4.6). |
| O3 | `AiController::updateRequirementReviewStatus()` sjekker bare synlighet og AI-tilgang. Lesere kan godkjenne krav. | Løses av B4 (§4.7). |
| O4 | `WikiSourceController::ingest()` sjekker ikke eierskap og lagrer ikke hvem som startet kjøringen. `cancelRun` lagrer aktøren bare som fritekst i `error_message`. | Sporbarhet. `started_by_user_id`/`cancelled_by_user_id` foreslås i §4.8 (valgfritt). |
| O5 | `approve()` og `reject()` bruker ulike myndighetsregler. System Owner kan sende tilbake, men ikke publisere en versjon de ikke er tildelt. | Bevisst (`User::canFinalApproveEnterpriseWikiVersion`, docblokk). Skal bevares uendret. |
| O6 *(rettet i 3c, `5eac50a4`)* | `WikiController::mayActOnCurrentVersion()` returnerer `true` for en versjon uten kontrollør. Dermed kan *enhver* Wiki-godkjenner sende den tilbake. Dette motsier `User::canReviewEnterpriseWikiVersion()` («a missing reviewer is broken state, not a licence»). | Strammes inn i steg 3. Bare tildelt kontrollør eller System Owner kan returnere. Sider som allerede står i denne tilstanden, frigjøres med «Trekk tilbake» (B5). |
| F1c *(rettet, `2af1ba86`)* | Påstandsbeslutninger («Rediger og godkjenn», «Fjern teksten», beste praksis) endrer teksten i den gjeldende versjonen *direkte*, uten ny versjon, også mens siden ligger til godkjenning. Tildelingen beholdes, så kontrolløren vurderer det som står ved godkjenning. Dette er funnbehandling som hører til gjennomgangen, ikke ordinær redigering. | **Besluttet 2026-10-22:** under godkjenning tar bare tildelt kontrollør tekstendrende påstandsbeslutninger. Kvalitetssikrer, sideeier, innsender og System Owner beholder beslutningene som ikke endrer teksten. Endringen spores (aktør, tidspunkt, gammel og ny tekst), vises for sideeier, og havner i versjonen kontrolløren godkjenner. Kontrolløren må fortsatt ha rettigheten til å behandle påstander (QA). |
| **F1d** | **Bekreftet 2026-10-22.** Når en side er publisert og gjeldende versjon *er* den publiserte, skriver tekstendrende påstandsbeslutninger direkte i den publiserte versjonen. Siden står fortsatt som «godkjent», og ingen har gjennomgått endringen. Dette rammer publisert innhold som svar og grounding bygger på. | Høy. Ikke rettet: rettingen endrer arbeidsflyten og krever beslutning (§9, Å7). Test: `WikiClaimTextDecisionOnPublishedVersionFindingTest`. |

---

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
| **Risiko** | `RiskAcceptanceService::accept`/`revoke` | **Ny** `RiskAccessService::canAccept` | `RiskAcceptanceController:73` |
| | **Ny** `RiskAssessmentService::record` (trukket ut) | **Ny** `canAssess` | `RiskAssessmentController:41–54` |
| | **Ny** `RiskLifecycleService::delete` | `can(RISK_DELETE)` og `isDeletable()` (B3) | `RiskController:249–256` |
| **Avvik** | `ImprovementCaseLifecycleService::close`/`cancel`/`reopen`, `ImprovementActionLifecycleService::verify` | `canClose` | `ImprovementCaseController:343–377`, `ImprovementActionController:147` |
| | `ImprovementCaseLifecycleService::delete` (ny) | `canDelete` og `isDeletable` | `ImprovementCaseController:388–399` |
| **Mål/KPI** | `KpiMeasurementService::record`/`withdraw` | `canMeasure` | `KpiMeasurementController:119–123` |
| | `ObjectiveLifecycleService::delete`, `KpiLifecycleService::delete` (nye) | `canDelete` og `isDeletable` | `ObjectiveController:310`, `KpiController:197` |
| **Etterlevelse** | `ComplianceAssessmentService::assess` | `canAssess` | `ComplianceRequirementController:363` |
| | `ComplianceAuditLifecycleService::start`/`complete`/`cancel`/`reopen` | `canAudit` | `ComplianceAuditController:286–333` |
| | `ComplianceRequirementLifecycleService::delete`, `ComplianceAuditLifecycleService::delete` (nye) | `canDelete` | `ComplianceRequirementController:450`, `ComplianceAuditController:504` |
| **Leverandør** | `SupplierAssessmentService::record` | `canAssess` | `SupplierAssessmentController:36` |
| | `SupplierLifecycleService::delete` | `canDelete` | `SupplierManagementController:530` |
| | `supplier.assure`-tjenestene | **Sjekker allerede selv.** Får bare vakten. | – |
| **Ledelsens gjennomgåelse** | `ManagementReviewFinalizationService::finalize`, `ManagementReviewService::addAmendment` | `canFinalize` | `ManagementReviewController:217, :248` |
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
| 9 | `refactor(risk): decisions and deletion in services; keep risk history` | `canAccept`/`canAssess`, `RiskAssessmentService`, `RiskLifecycleService::delete`, **B3** med trigger | 1 |
| 10 | `refactor(improvements): guard close, cancel, reopen, verify and delete` | | 1 |
| 11 | `refactor(management-review): guard finalize, amendment and delete` | | 1 |
| 12 | `refactor(quality): require an actor and guard flow approval and adoption` | Null-aktører og `QUALITY_FLOW_ADOPT` | 1 |
| 13 | `refactor(compliance): guard assessment, audit lifecycle and delete` | | 1 |
| 14 | `refactor(suppliers): guard assessment and delete; guard assure services` | | 1 |
| 15 | `refactor(objectives): guard measurements and delete` | | 1 |
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
| Å7 | F1d: hva skal skje når en tekstendrende påstandsbeslutning gjelder den publiserte versjonen? | Anbefaling: beslutningen skriver en ny arbeidsversjon gjennom `EnterpriseWikiPageVersionWriter`. Den publiserte versjonen fortsetter å gjelde, og siden går til utkast via `afterManualEdit()`, som ved vanlig redigering. Alternativ: nekt tekstendringer på publisert versjon og henvis til «Rediger artikkel». |

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
