# Eksternt API – beslutningssak for fase 7 (Anbud)

**Status:** Til beslutning. Ingenting er implementert.
**Dato:** 2026-10-24
**Gren:** `feature/external-api-foundation`
**Bygger på:** [`external-api-coverage-status.md`](external-api-coverage-status.md) (Anbud, fase 7, funn 2, 3, 6 og 28), [`external-api-phase-1-decisions.md`](external-api-phase-1-decisions.md) (D1, D2, D8, D11, D13, D19), [`external-api-architecture.md`](external-api-architecture.md) (K4, K8, K16, A46).

Anbefalingene følger samme grunnprinsipp som fase 1: den enkleste løsningen som er sikker for lesing. Det lages ingen nye generelle rammeverk. Alt bygger på mekanismer som allerede finnes.

---

## 1. Sammendrag

| # | Beslutning | Anbefaling |
|---|---|---|
| D23 | Lesetilgang | Ny leseoperasjon `tender.read` i `ApiOperationCatalog`, slått på per tildeling av System Owner (som `wiki.ask`). Første leveranse er ANB-01 og ANB-02, bare `GET`. |
| D24 | Kundeseparasjon og avdelinger | Egen lesevei for integrasjoner i `SavedNoticeAccessService`. Kunden kommer fra tildelingen. Integrasjonen leser hele kundens Anbud. Avgrensning til avdelinger er utsatt. |
| D25 | K16 | Ingen datamigrering. Feltene er interne adminfelt som ingen kundeflate bruker. De skjules ved serialisering, og API-et viser bare et fast feltutvalg fra kunngjøringen. |
| D26 | Menneskelige beslutninger og Bid Manager | `isBidManager()` nekter integrasjoner, og `resolvedBidRole()` gir `viewer` for en integrasjon. Alt saksarbeid er da stengt i domenetjenestene, ikke bare i ruten. |
| D27 | Felt i svarene | D8 gjelder: struktur, status, frister og ansvarlige. Kravtekst, svartekst, kommentarer og filer er ikke med. |
| D28 | Utenfor fase 7 | ANB-03–09 er utsatt. ANB-11 og ANB-12 eksponeres aldri. AI holdes sperret (D11). |

---

## 2. Funn fra koden (2026-10-24)

1. **Anbud har ingen rettighet i `CustomerPermissionCatalog`.** Personer får tilgang gjennom `bid_role`, `bid_manager_scope`, tilknytning og kundens `permission_settings` (`view_all_cases`). Integrasjonene leser de andre modulene gjennom katalogens `*.view`-nøkler. Den mekanismen finnes ikke for Anbud.
2. **`SavedNoticeAccessService::applyVisibility` gir ingen treff for en integrasjon.** Grunnen er at `canAccessCustomerFrontend()` er usann for `role = integration`. Det samme gjelder `manageableQueryFor`, `mayWork` og `authorizeWork`. Saksarbeidet er altså allerede stengt for integrasjoner, men bare indirekte.
3. **`isBidManager()` sperrer ikke integrasjoner** (funn 2). `resolvedBidRole()` faller tilbake til `contributor`. `cockpitScopeQueryFor` gir hele kunden til `isBidManager()`, uten å gå gjennom sperren for kundefrontenden.
4. **K16-feltene brukes bare internt.** `internal_status`, `internal_comment`, `assigned_to_user_id`, `decision_by_user_id`, `status_changed_at` og `status_changed_by_user_id` på `notices` leses og skrives bare av Filament (`NoticeResource`, `EditNotice`, `NoticeAttentionResource`) gjennom `DoffinNoticeWorkflowService`. De leses også i `DoffinRelevanceService::learningAdjustment`. Ingen kontroller i `App/` og ingen React-side bruker dem. Kundens egen arbeidsflyt ligger kundeavgrenset på `saved_notices` (`bid_status`, ansvarlige, frister) og `notice_attentions`.
5. **Statusendringen i Filament virker ikke for interne administratorer.** `DoffinNoticeWorkflowService::updateStatus` krever en aktør med `customer_id`. Bare `super_admin` uten kunde kommer inn i panelet (`CustomerContext::isInternalAdmin`). Feltet er derfor i praksis et levn fra tiden før kundeavgrensningen.
6. **`RequirementLoader::loadForCase` tar bare en saks-ID.** Den sjekker ikke tilgang og må bare kalles etter at saken er funnet gjennom en lesevei.
7. **`HumanDecisionGuard` dekker allerede budstatus og kravgodkjenning** (`TENDER_BID_STATUS`, `TENDER_REQUIREMENT_DECIDE`). «Flytt til historikk» (A46) er fortsatt ikke en `HumanDecision`.
8. **`CustomerContext::scopeNoticeDiscovery` avgrenser ikke etter `notice_attentions`.** Den krever bare en kunde og parsede kunngjøringer. Del 3 i dekningsoversikten sier at synligheten følger `notice_attentions`. Det stemmer ikke med koden.

---

## 3. Beslutningene

### D23 – Lesetilgang til saker og krav [ANBEFALT]

**Anbefaling:** Legg til én ny rad i `ApiOperationCatalog`:

- **Navn:** `tender.read`
- **Innstillinger:** `module = tender`, `writes = false`
- **Etikett:** «Lese anbudssaker og krav»

System Owner slår den på per tildeling i Kundemiljø → Integrasjoner, på samme måte som `wiki.ask`. Lesingen krever ikke skriverollen i Entra.

Første leveranse:

| Endepunkt | Funksjon | Domenekilde |
|---|---|---|
| `GET /api/v1/tender/cases` | ANB-01, liste med markørpaginering (`ApiCursor`). Filtrene er `bid_status` og `archived` (standard: aktive). | Ny lesevei (D24) og `SavedNoticeWorkflowSignals` |
| `GET /api/v1/tender/cases/{id}` | ANB-01, én sak med frister og signaler | Som over |
| `GET /api/v1/tender/cases/{id}/requirements` | ANB-02, publiserte krav med status | `RequirementLoader::loadForCase` etter at saken er funnet |

`/me` og Kundemiljø → Integrasjoner får en linje `tender` i `ApiAccessSummary`. Linjen har samme form som for andre moduler som gjelder hele kunden (`customerWide`). Den er lesbar når modulen er bestilt og operasjonen er slått på.

**Hvorfor en operasjon og ikke en ny rettighet i katalogen:** En nøkkel som `tender.view` i katalogen ville ikke bety noe for personer, fordi deres tilgang til Anbud styres av `bid_role`. Å flytte personenes tilgang inn i katalogen er en stor omlegging av Anbud. Den er ikke nødvendig for å lese. Operasjonslisten er allerede den kontrollerte veien for «noe utover vanlig lesing som System Owner velger eksplisitt». `wiki.ask` viser at den også brukes for lesing.

**Konsekvens:** Kommentaren i `ApiOperationCatalog` («beyond reading») må presiseres. Den skal si at listen også gjelder lesing som ikke går gjennom katalogens rettigheter.

**Alternativ som er forkastet:** Et eget Anbud-domene i `CustomerPermissionCatalog` med roller for integrasjoner. Det er riktig på lang sikt, men det krever at personenes modell for `bid_role` blir lagt om først.

### D24 – Kundeseparasjon og avdelingstilgang [ANBEFALT]

**Anbefaling:** Lag en egen, smal lesevei i `SavedNoticeAccessService`:

- `integrationReadableQuery(User $identity): Builder`
- `findIntegrationReadable(User $identity, int $id): ?SavedNotice`

`applyVisibility` endres ikke. Den nye veien:

1. Krever `isIntegration()`, en aktiv identitet, `customer_id` og `hasModule('tender')` på kunden. Den krever også at `IntegrationOperationAuthority` tillater `tender.read`. Ellers gir den `whereRaw('1 = 0')`.
2. Avgrenser til `customer_id = $identity->customer_id`. Kunden kommer fra tildelingen (`ResolveApiCustomerGrant`), aldri fra forespørselen. Det brukes aldri en modell bundet i ruten (K8). En sak hos en annen kunde gir 404.
3. Gir hele kundens saker, med og uten avdeling, inkludert private forespørsler. Det er samme form som for Etterlevelse, Leverandører og Ledelsens gjennomgang.
4. Krav leses bare gjennom en sak som er funnet med `findIntegrationReadable`. Først da kalles `RequirementLoader::loadForCase($case->id)`.

Begrunnelse for hele kunden:

- Tilgangen er et eksplisitt valg fra System Owner per tildeling.
- Avdelingsfilteret for personer bygger på `bid_manager_departments` og primærtilknytning. En integrasjon har ingen av delene.
- Avgrensning til utvalgte avdelinger krever et nytt felt på tildelingen. Det er ny tilgangsdata.

**[UTSATT]** Avdelingsavgrensning for integrasjoner. Den tas opp når en kunde trenger at én integrasjon ser bare noen avdelinger. Løsningen blir da en liste med avdelinger på `api_client_customer_grants` som brukes i steg 3. Det blir ikke en ny rolle.

**Tester som må finnes:**

- annen kunde gir 404
- modul ikke bestilt gir 403
- operasjonen slått av gir 403
- inaktiv identitet nektes
- krav på en sak hos en annen kunde gir 404
- arkiverte saker vises bare med filteret

### D25 – K16: kundespesifikke arbeidsflytfelt på globale kunngjøringer [ANBEFALT]

Funn 4 og 5 viser at feltene ikke er kundens arbeidsflyt. De er et gammelt internt adminfelt på delte data. Kundens arbeidsflyt finnes allerede kundeavgrenset på `saved_notices` og `notice_attentions`.

**Anbefaling (ingen migrering):**

1. **Skjul feltene ved serialisering.** De seks feltene legges i `Notice::$hidden`. Filament leser attributtene direkte og påvirkes ikke. Ingen `toArray()`, Inertia-prop eller JSON-respons kan da få dem med ved et uhell.
2. **API-et serialiserer aldri `Notice`-modellen.** Presentasjonen for saken tar et fast utvalg av Doffin-felt fra kunngjøringen: tittel, oppdragsgiver, Doffin-ID, CPV, kunngjøringstype og publiseringsdato. Arbeidsflyten leses bare fra `saved_notices`.
3. **Kontraktstest.** Ingen respons under `/api/v1/tender/*` inneholder disse nøklene.
4. **Arkitekturdokumentet oppdateres.** K16 omklassifiseres til «interne adminfelt på delte data, holdes utenfor all kundesynlig og ekstern serialisering».

**Alternativ som er forkastet:** Flytte feltene til en kundeavgrenset tabell. Det bygger en kundefunksjon som ingen bruker. `notice_decisions` (`customer_id`, `department_id`) finnes allerede hvis kundene en gang trenger å sortere kunngjøringene selv.

**[UTSATT]** Opprydding i den gamle Filament-arbeidsflyten og i `learningAdjustment` (funn 5). Det er en egen sak, og den hindrer ikke fase 7.

### D26 – Integrasjoner utelukkes fra menneskelige beslutninger og Bid Manager-roller [ANBEFALT]

**Anbefaling:** Bruk to endringer i `User`. Begge følger mønsteret som allerede gjelder for `isSystemOwner()` og `isQa()`.

1. `isBidManager()` returnerer `! $this->isIntegration() && …`. Da gir `cockpitScopeQueryFor`, `managedDepartmentIds`-grenen i `applyVisibility` og alle andre kontroller av Bid Manager nei.
2. `resolvedBidRole()` returnerer `BID_ROLE_VIEWER` for en integrasjon, uansett hva som ligger i `bid_role`. Dette lukker K4 for Anbud. Alle kontroller av typen `resolvedBidRole() !== VIEWER` stenger da: `mayCreateCase`, `mayWork`, kommentarer og arkivering. Det skjer i tillegg til sperren for kundefrontenden. Saksarbeidet stenges dermed i domenetjenestene (`authorizeWork`), ikke bare fordi ruten mangler.

Det som allerede holder, og som ikke endres:

- `HumanDecisionGuard` for `TENDER_BID_STATUS` og `TENDER_REQUIREMENT_DECIDE`
- databasetriggerne for beslutningskolonner
- `HumanUsersScope`, som holder integrasjonsidentiteter ute av valglistene for tilbudsansvarlig, mulighetseier og ansvarlig på krav

**A46:** «Flytt til historikk» med utfall eksponeres ikke, og lesing berører det ikke. Utfallet på arkiverte saker kan leses. Å gjøre arkiveringen til en `HumanDecision` er en forutsetning for ANB-05 og ANB-06, men ikke for fase 7.

**Tester som må finnes:**

- En integrasjon med `bid_role = bid_manager` og selskapsomfang i databasen er ikke Bid Manager, har tom `cockpitScopeQueryFor` og får nei fra `mayWork` på egen kundes sak.
- `authorizeWork` kaster unntak.

### D27 – Felt i svarene [ANBEFALT]

D8 gjelder uendret.

**Sak:**

- `id` og tittel
- kildetype (kunngjøring eller privat forespørsel)
- `bid_status`
- avdeling (`id`, navn)
- navn på tilbudsansvarlig og mulighetseier
- frister: tilbud, spørsmål, RFI og RFP
- signaler fra `SavedNoticeWorkflowSignals`: aktiv eller inaktiv, frister innen terskel og siste aktivitet
- utfall og tidspunkt for arkiverte saker
- Doffin-utvalget fra D25

**Krav:**

- `id` og kravidentifikator
- `requirement_type`
- `review_status` og `work_status`
- navn på ansvarlig
- dekning (`answer_draft_coverage`)
- om det finnes et svarutkast (ja eller nei)
- kildedokument (navn)

**Ikke med:**

- kravtekst og svartekst
- fasekommentarer og infopunkter
- Go/No-go-begrunnelser
- `internal_comment`
- filer og AI-spor

Kravteksten kommer fra oppdragsgivers dokumenter, men for private forespørsler kan den være fortrolig. Den vurderes sammen med DPIA (D14).

### D28 – Utenfor fase 7 [ANBEFALT]

| Funksjon | Status | Grunn |
|---|---|---|
| ANB-03 Søk i kunngjøringer | Utsatt | Funn 8: synligheten er ikke avgrenset per kunde slik dokumentasjonen sier. Det må avklares først. |
| ANB-04 Dokumenter | Utsatt | Filer, og skriving ved lesing (funn 6) |
| ANB-05–07 Saks- og kravarbeid | Utsatt | Skrivingen ligger i kontrollerne. A46. |
| ANB-08 AI | Sperret | D11. Egen nøkkel for Anbud-AI er ikke besluttet. |
| ANB-09 Word-eksport | Utsatt | Fil |
| ANB-10 Søkeprofiler | Ikke anbefalt | Kundeadministrasjon |
| ANB-11, ANB-12 | Aldri | `HumanDecision` |

---

## 4. Domenetjenester som gjenbrukes

| Tjeneste | Bruk |
|---|---|
| `SavedNoticeAccessService` | Får den nye leseveien (D24). `mayWork` og `authorizeWork` er uendret og nekter integrasjoner (D26). |
| `RequirementLoader::loadForCase` | Krav for en sak som allerede er funnet |
| `SavedNoticeWorkflowSignals` | Aktiv eller inaktiv, frister og siste aktivitet |
| `IntegrationOperationAuthority`, `ApiOperationCatalog` | `tender.read` (D23) |
| `ModuleEntitlementService` | Modulen `tender` er bestilt. |
| `ApiAccessSummary` | Linjen `tender` i `/me` og Kundemiljø |
| `ApiCursor`, `ApiResponse`, `ApiErrorResponder` | Paginering, svarform og feilformat |
| `HumanDecisionGuard` | Uendret. Er allerede på plass for budstatus og kravgodkjenning. |
| `ApiClientCustomerGrant` / `ResolveApiCustomerGrant` | Kunden fra tildelingen |

Nye klasser er bare en presentasjonsklasse per ressurs under `App\Services\Api\Presenters` og én kontroller under `Api\V1\Tender`. Det er det samme mønsteret som i fasene 2–6.

---

## 5. Ferdig når

- D23–D28 er godkjent.
- ANB-01 og ANB-02 er bygget med testene fra D24 og D26 og kontraktstesten fra D25.
- `openapi.yaml` er utvidet uten ny versjon.
- Funn 2, 3 og 28 i dekningsoversikten er lukket. Funn 8 her er ført inn som nytt funn der.
