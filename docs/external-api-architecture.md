# Eksternt Procynia-API – arkitektur og implementeringsplan

**Status:** Forslag til arkitekturbeslutning. Fase 0 (herding, ingen API) er gjennomført og godkjent 2026-10-23 (§7.4, §14). Godkjenningen er ikke en produksjonsgodkjenning. Selve API-et, fase 1 og senere, er ikke implementert. Produktbeslutningene for fase 0 er tatt 2026-10-22 (§7.3). Implementeringsplanen og sluttstatusen står i [`external-api-phase-0-plan.md`](external-api-phase-0-plan.md) §2n.
**Dato:** 2026-10-22
**Første konsument:** Siggjo (Advanias AI- og utviklingsmiljø: dashboards i PostgreSQL/Claude, agenter i Microsoft Foundry).
**Mål:** Et generelt, tilgangsstyrt API som også kan brukes av andre integrasjoner.

Dokumentet bygger på [`AGENTS.md`](../AGENTS.md), [`docs/enterprise-wiki-architecture.md`](enterprise-wiki-architecture.md), [`docs/customer-tenancy.md`](customer-tenancy.md), [`docs/operations/entra-sso.md`](operations/entra-sso.md), [`docs/operations/security.md`](operations/security.md), [`docs/security/security-audit-2026-08.md`](security/security-audit-2026-08.md) og en gjennomgang av koden per 2026-10-22.

Gjennom hele dokumentet brukes merkingen:

- **[FINNES]** – eksisterer i kodebasen i dag.
- **[MÅ UTVIKLES]** – finnes ikke og må bygges.
- **[MÅ ENDRES]** – finnes, men må endres før API-et kan bygges trygt.
- **[BESLUTNING]** – krever eksplisitt arkitektur- eller produktbeslutning før implementering.

---

## 1. Sammendrag

1. **API-et er en ny inngang til eksisterende regler, ikke en ny motor.** Alle kall går gjennom de samme `*AccessService`-klassene, domenetjenestene og forretningsreglene som webgrensesnittet bruker.
2. **Maskinidentiteter er en egen kontotype, ikke personbrukere.** De representeres som en `users`-rad med `role = integration`, slik at eksisterende tjenester (som tar `User $actor`) og rettighetskatalogen kan gjenbrukes. Kontotypen er sentralt ekskludert fra alt som forutsetter et menneske: innlogging, lisenstelling, eiervalg, varsler og oppgaver. Selve legitimasjonen og koblingen til den eksterne applikasjonen ligger i egne tabeller.
3. **To tilgangsmodi:** *delegert* (klienten handler på vegne av en innlogget person og ser nøyaktig det personen ser) og *applikasjon* (klienten handler som sin egen integrasjonsidentitet med egne, avgrensede roller).
4. **Entra ID utvides fra innlogging til API-autentisering.** Tokenets scopes og app-roller er bare et *tak* for hvilke API-operasjoner klienten kan kalle. Hva identiteten faktisk får se og gjøre, bestemmes fortsatt bare av Procynias egne roller. Det viderefører prinsippet i `entra-sso.md`: «Entra authenticates the identity. Procynia decides everything else.»
5. **Menneskelige beslutninger beskyttes i domenelaget, ikke i grensesnittet.** I dag håndheves de fleste beslutningsrettigheter bare i kontrollerne. Det er den største konflikten med et API, og den må løses **før** API-et får skriveoperasjoner.
6. **Første versjon er kun lesing:** KPI og mål, risiko, avvik og forbedringer, revisjoner og etterlevelsesstatus, oppmerksomhetslister og «Mine oppgaver».
7. **Siggjos datamodell er en tilgangsstyrt kopi, ikke en ny kilde.** Procynia eksporterer data *og* en ferdig beregnet tilgangsprojeksjon. Endringer i rettigheter og slettinger propageres via en endringslogg med tombstones og periodisk avstemming.

---

## 2. Dagens utgangspunkt

| Område | Status i dag | Referanse |
|---|---|---|
| API-ruter | **Finnes ikke.** Bare `routes/web.php` og `routes/console.php`. | – |
| Token-autentisering | **Finnes ikke.** Ingen Sanctum, Passport eller API-nøkler. Bare `web`-session-guard. | `config/auth.php:40-45` |
| Maskin-endepunkter | Stripe-webhook (signatur) og helse-endepunkter (`health.token`). | `routes/web.php:80,83,96` |
| Entra ID | OIDC authorization code flow for *innlogging*. `id_token` valideres mot tenantens JWKS. | `app/Services/Auth/EntraOidcClient.php:93-139` |
| Kundekontekst | `CustomerContext` leser kunden fra `Auth::user()`, ikke fra sesjonen. Fungerer derfor også for token-autentiserte forespørsler. | `app/Support/CustomerContext.php:15-35` |
| Rettigheter | `CustomerPermissionService` med 39 rettigheter i 8 domener. Eksplisitt tildeling for `compliance` og `supplier`. Fagområdestyring for `risk`, `objective` og `improvement`. | `app/Services/Permissions/CustomerPermissionService.php`, `app/Support/CustomerPermissionCatalog.php:186-282` |
| Tilgangstjenester | Alle tar `User`: `visibleRisks`, `visibleObjectives`, `visibleKpis`, `visibleCases`, `visibleAudits`, `visibleRequirements` med tilhørende `findVisible*`. | §8 |
| Kundeseparasjon | Ingen globale scopes. Isolasjon er en *konvensjon* i kontrollerne: en route-bundet modell gir bare id-en, og posten hentes på nytt kundeavgrenset. Annen kundes post gir 404. | `docs/security/security-audit-2026-08.md:1818-1843` |
| Revisjonsspor | Ingen samlet audit trail. Historikk per domene, delvis beskyttet av PG-triggere. | `docs/operations/security.md` §9 og §11 |
| Hendelser og outbox | Ingen domenehendelser, ingen outbox. Én observer: `AssignmentObserver`. | `app/Providers/AppServiceProvider.php:102` |
| Sletting | Ingen `SoftDeletes`. Alle slettinger er harde, med FK-kaskader. Bare leverandører har slettelogg (`supplier_deletions`). | – |
| Infrastruktur | Ingen API Management, WAF, Front Door eller VNet. MFA mangler. | `infra/README.md:646`, `docs/operations/security.md` §1 |

---

## 3. Arkitekturoversikt

```text
Ekstern klient (Siggjo-dashboard, Foundry-agent, MCP-klient, annen integrasjon)
        │  HTTPS + Bearer-token (Entra ID, v2 access token)
        ▼
/api/v1/*  (routes/api.php, egen middleware-stack, ingen sesjon/CSRF)
  1. api.authenticate   valider token → ApiClient + modus → aktør (User)       [MÅ UTVIKLES]
  2. api.customer       kunde aktiv, kundetildeling aktiv, aktør aktiv         [MÅ UTVIKLES]
  3. api.module         modulrettighet (gjenbruker route_modules-kartet)        [MÅ ENDRES]
  4. api.scope:<scope>  tokenets scope-tak for operasjonen                      [MÅ UTVIKLES]
  5. throttle:api-*     ratebegrensning per klient og kunde                     [MÅ UTVIKLES]
  6. api.log            strukturert forespørselslogg                            [MÅ UTVIKLES]
        ▼
API-kontroller (tynn): validerer input, kaller tilgangstjeneste, presenterer
        ▼
Eksisterende *AccessService + domenetjenester + forretningsregler         [FINNES]
        ▼
HumanDecisionGuard i domenelaget (beslutninger nektes for maskin og API)   [MÅ UTVIKLES]
        ▼
PostgreSQL (samme database, samme kundeavgrensning)
```

Tre prinsipper styrer resten av dokumentet:

1. **Én sannhet for regler.** API-kontrollere inneholder ingen forretnings- eller tilgangslogikk som ikke også brukes av webgrensesnittet.
2. **Aktøren er alltid en `User`.** I delegert modus er det personen. I applikasjonsmodus er det integrasjonsidentitetens `User`-rad. Det gjør at alle tjenester som tar `User $actor`, og all historikk som lagrer `*_by_user_id`, fungerer uendret.
3. **Kanalen er alltid kjent.** En `ApiRequestContext` (klient, modus, scopes, korrelasjons-id) er tilgjengelig i domenelaget, slik at sentrale vakter kan skille API-kall fra interaktive økter.

---

## 4. Identitetsmodell (punkt 1)

### 4.1 Alternativer som er vurdert

| Alternativ | Fordel | Ulempe | Vurdering |
|---|---|---|---|
| A. Maskin som vanlig `users`-rad (`role=user`) | Full gjenbruk uten endring | Telles i lisens, kan velges som eier, får varsler, kan få `system_owner`, `bid_role` faller tilbake til `contributor`. | **Forkastet** |
| B. Helt separat `Principal`-modell | Ren modell | Alle tjenester tar `User $actor`. Må endre hundrevis av signaturer og historikk-FK-er. Bryter «gjenbruk før nyutvikling». | **Forkastet** |
| C. Egen kontotype i `users` + separat legitimasjon | Gjenbruk av tjenester, rettigheter og historikk. Maskinen kan skilles ut sentralt. | Krever en sentral «menneske-sperre» gjennom koden. | **Anbefalt** |

### 4.2 Anbefalt modell

```text
api_clients                  – én ekstern applikasjon (f.eks. «Siggjo dashboards»)    [MÅ UTVIKLES]
  id, name, description, issuer_type (entra), entra_app_id (azp/appid),
  home_tenant_id, allowed_modes (delegated|application|both),
  max_scopes (tak), is_enabled, created_by_user_id (intern admin)

api_client_customer_grants   – kunden har gitt klienten tilgang                       [MÅ UTVIKLES]
  id, api_client_id, customer_id, integration_user_id (FK users),
  granted_scopes, granted_by_user_id (System Owner), granted_at,
  revoked_at, revoked_by_user_id

users (eksisterende)                                                                  [MÅ ENDRES]
  role = 'integration' (ny verdi), customer_id = kunden,
  name = «Integrasjon: Siggjo dashboards», email = syntetisk og ikke-routbar
  (f.eks. integration-<uuid>@integrations.procynia.invalid), password = tilfeldig og ubrukelig,
  bid_role = NULL (se §4.4), is_active styrer om identiteten virker
```

Slik henger det sammen:

- **Én integrasjonsidentitet per (klient, kunde).** Siggjo opptrer for mange kunder, men får en egen integrasjonsbruker hos hver kunde. Den har egne kunderoller og fagområder, og ingen data på tvers av kunder.
- **Roller gjenbrukes.** Integrasjonsbrukeren får vanlige kunderoller (`customer_user_roles`) og fagområder (`customer_role_business_areas`). `CustomerPermissionService`, fagområdeslogikken og eksplisitt tildeling for Etterlevelse og Leverandøroppfølging virker da uendret.
- **Historikk og sporbarhet.** Når en integrasjon oppretter noe, lagres integrasjonsbrukeren i `created_by` eller `changed_by_user_id`. Endringen er dermed sporbar til en navngitt integrasjon uten nye kolonner.

### 4.3 Sentral menneske-sperre

Kartleggingen viser hva en ikke-menneskelig `users`-rad ville påvirket. Hvert punkt må løses sentralt, ikke ved å huske det i hver enkelt spørring:

| Berørt område | Dagens kode | Tiltak |
|---|---|---|
| Passordinnlogging | `AuthenticatedSessionController.php:88-100` | Avvis eksplisitt `role=integration`. Gir samme feilmelding som andre avvisninger. |
| Frontendtilgang | `User::canAccessCustomerFrontend` (`User.php:808-816`) krever `role ∈ {customer_admin, user}` | Integrasjon faller automatisk utenfor. Dekkes med test. |
| Entra-innlogging | `EntraIdentityResolver::guardUser` (186-207) | Faller utenfor via frontendsjekken. Dekkes med test. |
| Lisenstelling | `BillingEntitlementService::currentBillableUsers` (129-135) teller `customer_admin` og `user` | Integrasjon telles ikke. Dekkes med test. Eventuell egen prising av integrasjoner er en produktbeslutning. |
| Eier- og kontrollørvalg | `ownerCandidates` og `isValidOwner` (Compliance 154/169, Supplier 147/162, Improvement 185/200) og om lag 48 andre `User::query()`-steder | **[MÅ ENDRES]** Innfør `User::scopeHumans()` og bruk den i alle velgere. Legg til en strukturell test som avviser nye velgere uten den. |
| Varsler | `UserNotificationWriter` (:53), `AssignmentNotifier` (81-130), `AiQuotaNotificationService` (210, 227), Stripe-varsler til System Owner | **[MÅ ENDRES]** `UserNotificationWriter` og e-postutsendere avviser integrasjonsmottakere sentralt. |
| Mine oppgaver | `TaskDeadlineReminderService.php:143`, MyTask-kilder | Integrasjon kan aldri være eier, så den får ingen oppgaver. Påminnelser filtreres med `humans()`. |
| Brukeradministrasjon | `CustomerContext::canManageCustomerUser` (183-227) | **[MÅ ENDRES]** Integrasjonsbrukere skjules fra «Brukere» og administreres bare via «Integrasjoner». |
| Kunderoller | `customer_user_roles` | **[MÅ ENDRES]** Roller som tildeles en integrasjon, får menneskeforbeholdte rettigheter fjernet effektivt (§7). |

### 4.4 Anbudsrollen

`User::resolvedBidRole()` (`User.php:660-667`) faller tilbake til `contributor` når verdien er tom eller ukjent. Det er en **konflikt**. En integrasjonsbruker uten `bid_role` ville stille fått bidragsyterrettigheter i Anbud.

- **[MÅ ENDRES]** For `role = integration` skal `resolvedBidRole()` returnere `null`, og alle `bid_role`-sjekker skal behandle `null` som «ingen anbudstilgang».
- Anbud holdes utenfor API-ets første versjoner (§8.3).

---

## 5. Autentisering med Entra ID (punkt 2)

### 5.1 Hva som kan gjenbrukes

| Komponent | Status | Gjenbruk |
|---|---|---|
| JWKS-henting og cache per tenant | **[FINNES]** `EntraOidcClient::jwks()` (160-178) | Trekkes ut som `EntraJwksProvider`. |
| Signaturvalidering (`firebase/php-jwt`, `JWK::parseKeySet`) | **[FINNES]** | Gjenbrukes. |
| Kontroll av issuer og `tid` mot `identity_providers` | **[FINNES]** | Trekkes ut som felles validering. |
| `identity_providers` (kunde ↔ Entra-tenant) | **[FINNES]** | Brukes til å finne kunden i delegert modus. |
| `user_identities` (`oid` ↔ bruker) | **[FINNES]** | Brukes til å finne personen i delegert modus. |
| Nonce-krav og `aud = client_id` | **[FINNES]**, men hardkodet for `id_token` | Gjelder ikke access tokens. Ny validator. |
| Kontroll av `scp`, `roles`, `azp` og `idtyp` | **[MÅ UTVIKLES]** | Ny `EntraAccessTokenValidator`. |

### 5.2 Registreringer i Entra

- **Procynia API** registreres som en egen, multi-tenant app med App ID URI, for eksempel `api://procynia`. **[MÅ UTVIKLES]**
  - **Delegerte scopes** (brukeren samtykker): `Procynia.Read`, senere `Procynia.Propose`.
  - **App-roller** (applikasjonstillatelser): `Procynia.Read.All`, senere `Procynia.Propose.All`.
- **Klientapplikasjoner** (Siggjo og andre) har egne app-registreringer, og app-ID-en deres registreres i `api_clients`.
- **[BESLUTNING]** Procynias eksisterende innloggingsapp (`AUTH_ENTRA_CLIENT_ID`) bør være en *annen* registrering enn API-appen. Da kan innlogging og API-tilgang styres og roteres uavhengig av hverandre.

### 5.3 Validering per forespørsel

```text
1. Bearer-token → header → kid → JWKS for tenant (iss) → signatur (kun RS256)
2. aud == api://procynia ; iss == https://login.microsoftonline.com/{tid}/v2.0 ; exp/nbf med liten klokkeslakk
3. azp/appid → api_clients (is_enabled) ; ellers 401
4. Modus:
   - idtyp == "app" (eller ingen scp) → applikasjonsmodus ; krever roles ∋ Procynia.*.All
   - scp finnes                     → delegert modus      ; krever scp ∋ Procynia.*
   Modus må være tillatt for klienten (api_clients.allowed_modes)
5. Kunde:
   - Delegert:     tid → identity_providers (enabled) → customer ; oid → user_identities → User
                   (ingen førstegangskobling via API – personen må ha logget inn i Procynia først)
   - Applikasjon:  Procynia-Customer-header → api_client_customer_grants (aktiv for klient+kunde)
                   → integration_user_id. Headeren er bare en *velger* blant aktive tildelinger,
                   den gir aldri tilgang alene.
6. Aktør: is_active ; customer.is_active ; delegert aktør må ha canAccessCustomerFrontend()
7. Effektive scopes = token-scopes ∩ granted_scopes ∩ api_clients.max_scopes
8. Auth::setUser(aktør) ; ApiRequestContext bindes i containeren ; ingen sesjon opprettes
```

Kontroll av kundekonteksten i applikasjonsmodus: Siggjos tjenesteidentitet hører hjemme i Advanias tenant, ikke i kundens. Tokenet kan derfor ikke selv si hvilken kunde det gjelder. Kunden avledes fra en aktiv, eksplisitt tildeling som kundens System Owner har gitt. Det er forenlig med regelen om at kunden aldri bestemmes av fritt input.

### 5.4 Forholdet til Entra-invarianten

`entra-sso.md` sier at ingen Entra-grupper, app-roller eller katalogroller kobles til Procynia-roller.

- **[BESLUTNING]** Forslaget bruker Entra-scopes og app-roller *bare som et tak for API-operasjoner*. For eksempel gir `Procynia.Read.All` lov til å kalle leseendepunkter. Om identiteten får se en bestemt risiko, avgjøres fortsatt bare av Procynia-roller og fagområder.
- Invarianten bør presiseres eksplisitt i `entra-sso.md` når beslutningen er tatt.

### 5.5 Andre identitetsleverandører

For integrasjoner som ikke bruker Entra (punkt 9) innføres et grensesnitt `ApiTokenValidator`. Senere kan Procynia utstede egne klientlegitimasjoner via OAuth 2.0 client credentials, eller en annen IdP kan legges til. API-laget bryr seg bare om resultatet: klient, modus, aktør, kunde og scopes.

---

## 6. Tilgangskontroll på alle API-kall (punkt 3)

Hvert kall passerer seks kontroller i fast rekkefølge. Alle fire tilgangsdimensjonene håndheves, og ingen av dem er nye regler.

| Dimensjon | Hvor den håndheves | Status |
|---|---|---|
| **Kundeseparasjon** | `api.authenticate` (aktørens `customer_id`) + tilgangstjenestenes `scoped()` + regelen «aldri route-bundet modell». Annen kundes id gir 404. | Reglene **[FINNES]**. API-stacken **[MÅ UTVIKLES]**. |
| **Modulrettighet** | `EnsureModuleIsEnabled` utvides til `api.v1.*`-rutenavn i `config/procynia_modules.php` (`route_modules`). Returnerer allerede 403 som JSON for forespørsler som ikke er Inertia. | **[MÅ ENDRES]** (kartoppføringer) |
| **Modulspesifikke rettigheter** | `CustomerPermissionService::has()` og `*AccessService::can*()` med aktøren. Eksplisitt tildeling for Etterlevelse og Leverandøroppfølging følger automatisk. | **[FINNES]** |
| **Fagområder** | `RiskAccessService::visibleRisks` (:111), `ObjectiveAccessService::visibleObjectives` (:105) og `visibleKpis` (:129, arver fra mål), `ImprovementCaseAccessService::visibleCases` (:113). Integrasjonsbrukerens roller har egne fagområder eller «Alle». | **[FINNES]** |
| **Avdelinger** | Påvirker bare Anbud (`SavedNoticeAccessService::applyVisibility`, 142-219). Utenfor API v1. Senere må det besluttes eksplisitt om en integrasjon skal ha avdelingsomfang. | **[BESLUTNING]** før Anbud-API |
| **Scope-tak** | `api.scope:<scope>` på hver rute. Lese-scope er nødvendig for GET og forslags-scope for POST. | **[MÅ UTVIKLES]** |

Regler for API-kontrollere:

1. Hent alltid poster via `findVisible*()` med aktøren. Bruk aldri route model binding direkte.
2. Lister bygges alltid fra `visible*()`-builderen. Filtre legges *oppå* builderen, aldri i stedet for den.
3. Ingen kontroller kaller `Model::query()` på kundeeide tabeller uten tilgangstjenesten.
4. Svar inneholder ikke webruter (`route('app.…')`). Presentasjonsklassene får en API-variant (§8.2).

---

## 7. Beskyttelse av menneskelige godkjenninger (punkt 5)

### 7.1 Funn: beslutningene håndheves i kontrollerne

Kartleggingen viser at de fleste beslutningsrettighetene bare kontrolleres i webkontrollerne. Domenetjenestene håndhever forretningsregler, men ikke *hvem* som får gjøre det. Et nytt grensesnitt som kaller tjenestene direkte, ville gå forbi kontrollene.

| Beslutning | Rettighetskontroll i dag | Tjeneste | Risiko |
|---|---|---|---|
| Wiki: send til godkjenning, godkjenn, avvis | Bare kontroller (`WikiController::submit` :2955, `approve` :3070, `reject` :3134, `finalApprovalBlocker` :3455). Publiseringen skrives direkte i kontrolleren (:3113). | Ingen | **Høy** |
| Wiki: påstandsbeslutninger | Bare kontroller (`WikiClaimController` :63/:123/:463, skriving i private metoder :629/:673) | Ingen | **Høy** |
| Wiki: kildeeiers godkjenning | Kontroller. `EnterpriseWikiDocumentOwnerApprovalService::decide` (:290) kaller ikke `canDecide` selv. | Delvis | Middels |
| Wiki: opplasting og sletting av kilder | Kontroller. Tjenestene har ingen tilgangskontroll. | Delvis | Middels |
| Wiki: kunnskapsoverføring | Kontroller. `WikiKnowledgeHandoffService::handOff` (:128) kaller ikke `canHandOff` (:75). | Delvis | Middels |
| Risiko: aksept av restrisiko | Kontroller (`RiskAcceptanceController` :73). Tjenesten har forretningsregler. | `RiskAcceptanceService` | **Høy** |
| Risiko: ny vurdering | Kontroller, med direkte `create` uten tjeneste | Ingen | Middels |
| Risiko: sletting | Kontroller (`RiskController::destroy` :249) | Ingen | Middels |
| Avvik: lukk, kanseller, gjenåpne, effektverifiser | Kontroller (`canClose`) | Livsløpstjenester har regler | **Høy** |
| KPI: registrer eller trekk måling | Kontroller (`canMeasure`) | `KpiMeasurementService` | Lav–middels |
| Etterlevelse: vurdering, revisjonsløp, aktivering | Kontroller | Tjenester har regler | Middels |
| Leverandør: vurdering | Kontroller | `SupplierAssessmentService` uten tilgangskontroll | Middels |
| Ledelsens gjennomgang: ferdigstill, tillegg | Kontroller (`canFinalize`) | Kontrollerer bare at gjennomgangen er klar | **Høy** |
| Kvalitet: godkjenn prosessflyt | Kontroller. `QualityProcessBlueprintService::approve(…, ?User $actor = null)` (:134) godtar **null-aktør** (:188). | Delvis | **Høy** |
| Anbud: Go/No-go, statusoverganger | Kontroller. `SavedNotice::transitionBidStatus` (:607) lagrer ingen aktør. | Delvis | Middels |
| Anbud: bekreft eller avvis krav | Kontroller. `RequirementEditorService::transitionRequirementReviewStatus(…, ?User $changedBy = null)` (:313) godtar null-aktør. | Delvis | Middels |

Kartleggingen ble kontrollert mot koden 2026-10-22. I tillegg til tabellen over gjelder:

- Sletting av kildedokumenter og sider, avbrudd og fjerning av kjøringer og dokumenteiers beslutning skjer i tjenester som tar `User`, men ikke sjekker rettigheter.
- Risikovurdering, sletting av risiko og avvik, og statusoverganger i Anbud skrives direkte i kontrolleren.
- Godkjenning av krav i Anbud sjekker bare synlighet, så lesere kan godkjenne.
- Sletting av risiko fjerner vurderinger og aksepter via databasekaskade.

Fullstendig tabell over tjenester og filer: [`external-api-phase-0-plan.md`](external-api-phase-0-plan.md) §4.4.

Disse tjenestene kontrollerer allerede selv og kan gjenbrukes trygt: `ImprovementCaseCreator::create` (:55, :72/:82), tjenestene for `supplier.assure`, `ComplianceAuditFindingHandoffService::handOff` (:84) og `ManagementReviewDecisionService` sine `handOff`/`link`.

### 7.2 Anbefalt mekanisme

**1. Merk menneskeforbeholdte rettigheter i katalogen.** **[MÅ ENDRES]**

`CustomerPermissionCatalog` får `humanOnlyPermissions()`:

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

- **Besluttet 2026-10-22 (§7.3, B1):** Alle godkjenninger, publiseringer, faglige vurderinger og slettinger er forbeholdt mennesker. Lesing og opprettelse av utkast og forslag er det ikke.
- `wiki.edit` er med fordi en manuell redigering merkes som menneskeskrevet innhold (`CONTENT_ORIGIN_HUMAN_AUTHORED`).
- `objective.measure` er med fordi en måling er en registrert verdi, ikke et forslag.
- Kunnskapsoverføring får en egen rettighet, `wiki.knowledge.handoff`, som *ikke* er menneskeforbeholdt. Den skilles fra `wiki.source.manage` (kildeadministrasjon), som er det.

**2. Fjern rettighetene i den effektive rettighetsberegningen.** **[MÅ ENDRES]**

- `CustomerPermissionService::effectivePermissions()` trekker fra `humanOnlyPermissions()` når aktøren har `role = integration`, *eller* når forespørselen kommer via API (også i delegert modus).
- Dermed gjelder sperren uansett hvilke roller System Owner tildeler, og uansett hvilken tjeneste som spør.

**3. Innfør `HumanDecisionGuard` i domenelaget.** **[MÅ UTVIKLES]**

Hver beslutningstjeneste kaller `HumanDecisionGuard::assert($actor, Decision::RISK_ACCEPT)` før skriving. Vakten avviser hvis:
- aktøren er `integration`, eller
- det finnes en aktiv `ApiRequestContext` (også delegert: en agent som handler på vegne av en person, skal ikke kunne godkjenne på personens vegne), eller
- aktøren er `null`.

Sperren for aktiv `ApiRequestContext` gjelder også delegert modus. Det er besluttet (§7.3, B2).

**4. Flytt rettighetskontrollen ned i tjenestene.** **[MÅ ENDRES]**

- Alle beslutninger i tabellen i §7.1 får en tjeneste som tar `User $actor` (ikke `?User`) og selv kaller tilgangstjenesten og `HumanDecisionGuard`.
- Kontrollerne beholder sin kontroll for å gi riktig HTTP-svar, men sikkerheten ligger i tjenesten.
- Wiki-arbeidsflyten (send, godkjenn, avvis, påstandsbeslutninger) må trekkes ut av `WikiController` og `WikiClaimController` til egne tjenester. Det er den største enkeltjobben.

**5. Fjern null-aktører.** **[MÅ ENDRES]**

`QualityProcessBlueprintService::approve` og `RequirementEditorService::transitionRequirementReviewStatus` skal kreve `User $actor`. `SavedNotice::transitionBidStatus` skal lagre aktøren.

**6. Forsvar i dybden i databasen.** **[MÅ UTVIKLES]**, anbefalt for de mest kritiske

En `BEFORE INSERT/UPDATE`-trigger avviser at beslutningskolonner peker til en `users`-rad med `role = integration`. Det gjelder blant annet `risk_acceptances.accepted_by_user_id`, publiseringsfeltene i `enterprise_wiki_pages`, beslutningsfeltene i `management_reviews` og `enterprise_wiki_claim_decisions.decided_by_user_id`. Mønsteret finnes allerede for historikktabellene.

**7. Ingen API-ruter for beslutninger.** API-et eksponerer aldri endepunkter for godkjenning, uansett hva som skjer med punktene over.

### 7.3 Produktbeslutninger og verifiserte funn (2026-10-22)

| # | Beslutning |
|---|---|
| B1 | Integrasjoner og AI-agenter utfører aldri endelige godkjenninger, publiseringer eller andre formelle beslutninger. De kan opprette utkast og forslag gjennom egne, avgrensede rettigheter. Kildeadministrasjon, kunnskapsoverføring og formell godkjenning er adskilte rettigheter. |
| B2 | Menneskeforbeholdte beslutninger er ikke tilgjengelige via API-et, heller ikke når en agent handler på vegne av en autentisert person. |
| B3 | En risiko med vurderinger, aksepter eller annen beslutningshistorikk kan ikke slettes permanent. Historikken bevares, og «Lukket» brukes i stedet. |
| B4 | Godkjenning og avvisning av krav i Anbud krever en eksplisitt rettighet (`decide_bid_requirements` i Tilganger-matrisen) og en identifiserbar menneskelig aktør. Lesetilgang til saken er ikke nok. |
| B10 | **Kvalitet (2026-10-22):** En prosess eller kontroll som andre moduler peker på (Risiko, Mål og KPI, Avvik og forbedringer, Etterlevelse og revisjon), kan ikke slettes. Det gjelder også når den som sletter, ikke har tilgang til modulen som har koblingen. Avvisningen er én generell setning som aldri røper modul, post eller person. Elementet settes til «Utgått», og koblingene bevares. **Dette erstatter den tidligere regelen om at Kvalitet ikke skal påvirkes av koblinger den ikke ser.** |
| B11 | **Kvalitet (2026-10-22):** Godkjente prosessversjoner og registrert evidens er historikk. Et element med slik historikk slettes ikke. Det settes til «Utgått». Evidens fjernes ikke, men trekkes tilbake med aktør og tidspunkt, og hendelsen registreres i `quality_item_events`. Reglene håndheves også av databasetriggere, slik at ingen inngang (SQL, jobb eller API) eller kaskade kan omgå dem. Bare sletting av kunden tar historikken med. |

Funn fra kartleggingen er verifisert med isolerte tester:

- **F1a og F1b** (bekreftet): manuell redigering av en side som ligger til godkjenning, og påstandsreparasjon på en publisert side, bryter godkjenningsflyten. Ingenting ugjennomgått publiseres.
- **F2** (avkreftet i praksis): `FinalizeEnterpriseWikiIngest` kan ikke nå en side som ligger til godkjenning.
- **F3a** (bekreftet): Kvalitet sletter Wiki-sider uten `wiki.delete`.
- **F4** (bekreftet): endringer i roller, QA og Tilganger har ikke revisjonsspor.

Detaljer, tester og rettingsrekkefølge står i [`external-api-phase-0-plan.md`](external-api-phase-0-plan.md) §2.

### 7.4 Status etter fase 0 (2026-10-23)

Tabellen i §7.1 beskriver utgangspunktet. Etter fase 0 gjelder:

- **[FINNES]** `HumanDecision`, `HumanDecisionGuard` og `ActorChannel`. Vakten nekter null-aktør, inaktiv aktør, `role = integration` og enhver forespørsel med API-kanal, også i delegert modus.
- **[FINNES]** Alle beslutningene i §7.1, i alle ni moduler, går gjennom en tjenestemetode som tar `User $actor`, kaller vakten og sin egen tilgangsregel før skriving, og kontrollerer at personen tilhører kunden. `HumanDecisionContractTest` fører registeret (99 metoder, 40 beslutninger) og kontrollerer at ingen kontroller skriver en beslutningskolonne, og at ingen jobb, kommando eller lytter kaller en beslutningsmetode.
- **[FINNES]** `humanOnlyPermissions()` trekkes fra for integrasjoner i `CustomerPermissionService` og i områdetilgangen (Risiko, Avvik, Mål og KPI).
- **[FINNES]** Triggeren `human_decision_actor` nekter integrasjonsaktører i 44 beslutningskolonner i 36 tabeller. Historikktabellene er beskyttet mot endring og sletting.
- **[FINNES]** Revisjonsspor for tilgangsendringer (`customer_access_changes`), statusendringer i Anbud og dokumenteiers beslutninger.
- **[MÅ ENDRES] før eksponering i API-et:**
  - **A23:** Kvalitets AI-forslag og «Spør Wiki» kontrollerer tilgang bare i kontrolleren.
  - **A46:** «Flytt til historikk» med utfall skrives i kontrolleren og er ikke en `HumanDecision`.
- **[MÅ UTVIKLES] i fase 1:** felles feilformat for domeneunntakene (A10).

---

## 8. Første lese-API (punkt 6)

### 8.1 Tjenester som egner seg

| Område | Tilgang | Avledet tilstand | Oppmerksomhet | Presentasjon i dag |
|---|---|---|---|---|
| Risiko | `RiskAccessService::visibleRisks` (:111), `findVisible` (:123) | `RiskAssessment::latestForRisks` (`RiskAssessment.php:73`), `RiskScoringPolicy::evaluate` (:62, ren), `RiskReviewSchedule` (:40/:54/:63) | `RiskAttentionService::findingsForRisks` (:118) | Inline i `RiskController::riskRow` (:286), `assessmentRow` (:311) og `residualLevel` (:339). **Må trekkes ut.** |
| Mål og KPI | `ObjectiveAccessService::visibleObjectives` (:105), `visibleKpis` (:129), `findVisibleKpi` (:140) | `KpiTargetPolicy::evaluate` (:31, ren), `KpiMeasurementSchedule::for` (:44), `KpiMeasurementResolver::latestForKpis` (:109), `KpiGrowth` | `ObjectiveAttentionService::findingsForObjectives` (:168) | **`KpiPresenter` finnes** (`row` :65, `detail` :126, `history` :203). `ObjectiveController::objectiveRow` (:428) må trekkes ut. |
| Avvik og forbedringer | `ImprovementCaseAccessService::visibleCases` (:113), `findVisible` (:125) | `ImprovementActionVerificationResolver::forActions` (:31) | `ImprovementAttentionService::findingsForCases` (:165) | Inline (`caseRow` :448, `actionRow` :477). `is_overdue` bruker `now()` inline. **Må trekkes ut.** |
| Revisjoner | `ComplianceAccessService::visibleAudits` (:108), `findVisibleAudit` | Status i kolonnen. Overganger via `ComplianceAuditLifecycleService`. | `ComplianceAuditAttentionService::reasonsForAudits` (:59) | Inline (`ComplianceAuditController::row` :639). **Må trekkes ut.** |
| Etterlevelsesstatus | `ComplianceAccessService::visibleRequirements` (:76) | `ComplianceStatusResolver::latestForRequirements` (:44) og `summarize` (:109), `ComplianceAssessmentOverview` (ren) | `ComplianceAttentionService::reasonsForRequirements` (:85) | Aggregeringen er inline i `ComplianceAssessmentController::index` (:41-147). **Må trekkes ut.** |
| Mine oppgaver | `MyTasksService::tasksFor(User, int, ?today)` (:65) | `MyTask::toArray` | – | **Finnes.** Gir bare mening i delegert modus. |

### 8.2 Presentasjonslag

- **[MÅ ENDRES]** Radlogikken trekkes ut av kontrollerne til presentasjonsklasser, for eksempel `RiskPresenter`, `ImprovementCasePresenter` og `ComplianceAuditPresenter`. Webkontrollerne og API-kontrollerne bruker de samme klassene, slik at de to grensesnittene ikke glir fra hverandre.
- Presentasjonsklassene tar `CarbonInterface $today` inn i stedet for å kalle `now()`, slik `KpiPresenter` og oppmerksomhetstjenestene allerede gjør.
- Webruter legges til av webkontrolleren. API-svar inneholder stabile id-er i stedet for `url`.

### 8.3 Holdt utenfor v1

**Besluttet 2026-10-23:** Første API-versjon er kontrollert lesetilgang. Ingen menneskelig beslutning kan utføres av en integrasjon, heller ikke ved delegert autentisering. Funksjonene i A23 (Kvalitets AI-forslag og «Spør Wiki») og A46 («Flytt til historikk» med utfall) eksponeres ikke før kravene i [`external-api-phase-0-plan.md`](external-api-phase-0-plan.md) §2n er oppfylt.

| Område | Begrunnelse |
|---|---|
| **Anbud** | Avdelingsbasert synlighet, uavklart oppførsel for integrasjoner (§4.4), og gamle arbeidsflytfelt på globale `notices` som deles mellom kunder (`customer-tenancy.md`). |
| **Wiki** | Lesing skal gå via den felles navigasjons- og retrieval-kjernen og bare bruke gjeldende innhold (`AGENTS.md`). Et Wiki-endepunkt er en egen fase og må gjennom Wiki-sjekklisten (`enterprise-wiki-architecture.md` §J). **Fase 6 (2026-10-24, D18–D22):** publiserte sider og «Spør Wiki» over publisert kunnskap er levert lokalt gjennom Wikiens egen katalog og kjerne. Sjekklisten er gjennomgått i `external-api-phase-1-decisions.md` §23. Kunnskapsoverføring er utsatt (D22). |
| **Leverandører og Ledelsens gjennomgang** | Kan legges til i v1.1 med samme mønster (`SupplierAccessService`, `ManagementReviewAccessService`, `SectionBuilder`). |

### 8.4 Eksempelendepunkter (v1)

```text
GET /api/v1/me                                    aktør, kunde, modus, moduler, effektive rettigheter, fagområder
GET /api/v1/risks?status=&business_area_id=&level=&cursor=&limit=
GET /api/v1/risks/{id}                            inkl. siste iboende/rest-vurdering, tiltak, neste revurdering
GET /api/v1/risks/attention
GET /api/v1/objectives?status=&business_area_id=&cursor=
GET /api/v1/objectives/{id}                       inkl. KPI-er med status
GET /api/v1/kpis/{id}/measurements?from=&to=      målinger (trukne merkes, slettes ikke)
GET /api/v1/improvement-cases?status=&type=avvik|forbedring&business_area_id=&cursor=
GET /api/v1/improvement-cases/{id}                inkl. tiltak og effektverifisering
GET /api/v1/compliance/audits?status=&cursor=
GET /api/v1/compliance/audits/{id}                inkl. funn (type, oppfølgingsstatus)
GET /api/v1/compliance/status?source_id=          etterlevelsesstatus aggregert per kilde/standard
GET /api/v1/attention                             samlet oppmerksomhet over moduler aktøren har tilgang til
GET /api/v1/my-tasks                              kun delegert modus
```

Eksempelsvar:

```json
GET /api/v1/risks/4812
{
  "data": {
    "id": 4812,
    "title": "Nøkkelperson slutter",
    "status": "under_treatment",
    "business_area": { "id": 7, "name": "Leveranse" },
    "owner": { "id": 233, "name": "Kari Nordmann" },
    "inherent": { "likelihood": 4, "consequence": 4, "level": "high", "assessed_at": "2026-09-02T10:14:00Z" },
    "residual":  { "likelihood": 2, "consequence": 4, "level": "medium", "assessed_at": "2026-10-01T08:30:00Z" },
    "acceptance": { "accepted": true, "expires_on": "2027-10-01" },
    "next_review_on": "2027-04-01",
    "is_review_overdue": false,
    "open_treatment_actions": 1,
    "updated_at": "2026-10-01T08:30:00Z"
  },
  "meta": { "as_of": "2026-10-22T07:00:00Z", "request_id": "01JB…" }
}
```

Fellesregler:

- **Feil** har formatet `{"error": {"code": "not_found", "message": "…", "request_id": "…"}}`.
  - 401: ugyldig token.
  - 403: manglende scope eller modul.
  - 404: ikke synlig. Det skilles bevisst ikke mellom «finnes ikke» og «ingen tilgang».
- **Sidevis henting** bruker markører (`cursor`, `limit` ≤ 200).
- Alle tidspunkter er i UTC (ISO 8601). `meta.as_of` angir når svaret ble beregnet.
- Kontrakten beskrives i en OpenAPI 3.1-spesifikasjon som versjoneres sammen med koden.

---

## 9. Siggjos PostgreSQL-datamodell (punkt 4)

### 9.1 Problemet

Når data kopieres til en annen database, forsvinner Procynias tilgangskontroll med mindre den også kopieres. Tre ting må holdes korrekte: *hvem som ser hva*, *endringer i rettigheter* og *slettinger*.

### 9.2 Alternativer

| Alternativ | Tilgangskontroll | Ferskhet | Kompleksitet | Vurdering |
|---|---|---|---|---|
| A. Ingen kopi. Dashboards henter live via API i delegert modus. | Perfekt: Procynia avgjør hvert kall. | Live | Lav | **Anbefalt for personlige dashboards** |
| B. Kopi av kundedata + tilgangsprojeksjon. Siggjo håndhever Row-Level Security. | Riktig hvis projeksjonen holdes oppdatert | Minutter | Middels–høy | **Anbefalt for virksomhetsdashboards**, etter beslutning |
| C. Kopi uten tilgangsprojeksjon (alle i kunden ser alt) | Bryter fagområder og eksplisitt tildeling | – | Lav | **Forkastet** |

### 9.3 Anbefalt løsning for alternativ B

Procynia er fortsatt eneste kilde. Siggjos datamodell er en tilgangsstyrt *kopi* med kjent maksimal forsinkelse.

**1. Data hentes i applikasjonsmodus.**
- Integrasjonsbrukeren hos kunden må ha leserettigheter med fagområdet «Alle» for modulene som skal eksporteres. Det er en bevisst handling fra kundens System Owner.
- Eksplisitt tildelte domener (Etterlevelse og Leverandøroppfølging) eksporteres bare hvis de er tildelt.

**2. Hver post merkes med tilgangsnøkkel.** `customer_id`, `module`, og `business_area_id` der det finnes.

**3. Tilgangsprojeksjon.** **[MÅ UTVIKLES]**

`GET /api/v1/access-projection` gir den *ferdig beregnede* tilgangen per person i kunden:

```json
{ "user_id": 233, "is_active": true, "modules": {
    "risk":        { "view": true, "business_area_ids": [7, 9] },
    "objective":   { "view": true, "business_area_ids": "all" },
    "improvement": { "view": true, "business_area_ids": [7] },
    "compliance":  { "view": false } },
  "access_version": 1182 }
```

- Siggjo håndhever dette med PostgreSQL Row-Level Security. Den skal **ikke** beregne Procynias regler selv: implisitt System Owner, eksplisitt tildeling og fagområdet «Alle» er allerede løst opp.
- Dashboardbrukerens identitet må komme fra den samme Entra-identiteten (`oid` ↔ `user_identities`), slik at Siggjo-brukeren kobles sikkert til `user_id`.

**4. Endringslogg (outbox).** **[MÅ UTVIKLES]**

- `api_change_events(id, customer_id, entity_type, entity_id, change: upsert|delete|access, occurred_at)`.
- Hendelsene skrives i samme transaksjon som endringen.
- Registreringspunktet er det samme som `AssignmentObserver` bruker (`AppServiceProvider.php:102`), utvidet med `deleted`.
- `GET /api/v1/changes?since=<event_id>` gir hendelsene. Klienten henter postene på nytt, eller fjerner dem ved `delete`.

**5. Tilgangsendringer gir en `access`-hendelse.** Det gjelder endringer i rolle-tildeling, rollens rettigheter, rollens fagområder, deaktivering av en bruker og tilbaketrekking av en kundetildeling. Siggjo henter da tilgangsprojeksjonen på nytt. `access_version` brukes til å oppdage at kopien er utdatert.

**6. Sletting.**
- Alle slettinger er harde, og FK-kaskader utløser **ikke** Eloquent-hendelser. Barnetabeller (vurderinger, tiltak, målinger) forsvinner da uten at observeren ser det.
- Løsning: En slettet *forelder* gir `delete` med kjent kaskadesemantikk (dokumentert per enhet). I tillegg kjøres en **daglig avstemming** der `GET /api/v1/{entity}/ids` gir alle gyldige id-er, og Siggjo fjerner det som mangler.
- **Merk:** Forelderens `updated_at` endres ikke når en barnerad legges til. Endringsloggen må derfor også registrere barnetabellene, eller registrere endringen på forelderen.

**7. Tilbaketrekking.** Når kundetildelingen trekkes tilbake, får Siggjo en `access`-hendelse og skal slette alle data for kunden innen en avtalt frist. Dette må reguleres i databehandleravtalen.

**8. Dataminimering.**
- Eksporter bare felt som dashboards trenger.
- Fritekst (beskrivelser, årsaker) og personnavn utover eier holdes utenfor som standard.
- **[BESLUTNING]** Det må gjøres en personvernvurdering (DPIA), og det trengs databehandleravtale med Advania/Siggjo og med AI-leverandøren Siggjo bruker. Claude er en annen leverandør enn OpenAI, som Procynia bruker i dag.

---

## 10. Utvidelse: forslag, idempotens, sporbarhet og MCP (punkt 7)

> **Status 2026-10-24:** Den første skriveoperasjonen er bygget, forenklet i forhold til dette avsnittet: navngitte operasjoner per kundetildeling (`ApiOperationCatalog`, `allowed_operations`), app-rollen `Procynia.Write.All` som tak, og `POST /api/v1/risks/{riskId}/treatment-actions` gjennom `RiskTreatmentService::create()`. Ingen `Idempotency-Key` og ingen `api_request_log` ennå; gjentatte kall kan gi duplikater. Se `external-api-phase-1-decisions.md` §20. Fase 4 la til `improvement.case.create` og `improvement.action.create` for Avvik og forbedringer på samme måte (§22).

### 10.1 Forslag i stedet for beslutninger

Agenter skal kunne *foreslå*, ikke *beslutte*. I prioritert rekkefølge:

1. **Gjenbruk eksisterende «trygge» opprettelser** som allerede kontrollerer selv og ender i en tilstand et menneske må ta stilling til:
   - `ImprovementCaseCreator::create` → saken opprettes som «Åpen». Eier og behandling er menneskelig.
   - `WikiKnowledgeHandoffService` → innholdet blir et *kildedokument* som går gjennom vanlig kjøring og menneskelig godkjenning. Dette er den eneste lovlige veien inn i Wiki: agenter skriver aldri sider direkte. Tjenesten må først kalle `canHandOff` og maksimumsgrensen selv (§7.1). Den krever rettigheten `wiki.knowledge.handoff`, ikke `wiki.source.manage` (§7.3, B1). En integrasjon blir aldri dokumenteier.
2. **[BESLUTNING]** Én generisk forslagstype (`agent_suggestions`) som vises i «Mine oppgaver» hos ansvarlig person, og som bare et menneske kan ta i bruk eller avvise. Den gjelder for eksempel «foreslå ny risikovurdering» eller «foreslå revisjon av Wiki-side».
   - Den skal være *én* modell og én `MyTaskSource`, ikke en per modul.
   - Det er en ny modell, og AGENTS.md («unngå unødvendige modeller») krever at behovet er dokumentert først.
   - Mønsteret finnes allerede i Kvalitet: forslag til prosessflyt med `base_hash`-kontroll, som lagres som ikke-godkjent arbeidsversjon.

### 10.2 Idempotens

- Alle skrivekall krever headeren `Idempotency-Key`. **[MÅ UTVIKLES]**
- Tabell `api_idempotency_keys(api_client_id, customer_id, key, request_hash, response_status, response_body, created_at)` med 24 timers levetid.
- Samme nøkkel med samme forespørsel gir samme svar. Samme nøkkel med *annen* forespørsel gir 422.

### 10.3 Sporbarhet

- **Forespørselslogg** `api_request_log`: klient, kunde, aktør, modus, rute, status, påvirkede id-er, `request_id`, varighet. Ingen token, ingen innhold og ingen fritekst, i tråd med loggreglene i `security.md` §9. **[MÅ UTVIKLES]**
- **Opphav i domenedata:** `created_by`/`changed_by_user_id` peker til integrasjonsbrukeren, og navnet viser hvilken integrasjon det er. Agenthandlinger kan dermed spores i eksisterende historikk.
- **Hendelseslogg i Kvalitet:** `quality_item_events` er en uforanderlig logg per element: hendelse, aktør, navn, tidspunkt og metadata. Den er startet med registrering og tilbaketrekking av evidens (B11). Statusendringer, eierskifte, gjennomganger og sletting skal inn i samme modell før API-et kan skrive i Kvalitet (`external-api-phase-0-plan.md` §2h).
- **Samtidighet:** En skriving fra API-et til prosessflyt i Kvalitet skal sende versjonen den bygger på (`base_hash`). AI-forslag krever det allerede. For manuell lagring er den valgfri på dagens endepunkt og skal bli påkrevd for integrasjoner (§2h, A27).
- **AI-kall utløst via API** skal kjøre med `AiCallContext` og streng kontekstkontroll. I dag er standardmodus `warn` (`config/ai_operations.php`), og den bør være `strict` for alle kall utløst via API.

### 10.4 MCP (Model Context Protocol)

- **[MÅ UTVIKLES]** Et tynt MCP-lag over API-et. Hvert verktøy svarer til ett API-endepunkt og bruker samme token og samme kontroller.
- Ingen MCP-verktøy for beslutninger. Forslagsverktøy kommer tidligst etter §10.1.
- Et fremtidig «Spør Wiki»-verktøy skal kalle den felles Wiki-kjernen (`AGENTS.md`: én navigasjons- og retrieval-kjerne, bare gjeldende innhold, «utilstrekkelig grunnlag» er et gyldig svar). Det skal ikke eksponere rå dokumentutdrag.
- Agentløkkene kjører hos klienten, for eksempel i Foundry. Procynia kjører ingen ukontrollerte, rekursive agentløkker, i tråd med Wiki-invariantene.

---

## 11. Tester (punkt 8)

### 11.1 Gjenbruk

| Mønster | Fil | Bruk for API |
|---|---|---|
| Strukturell isolasjonsskanning | `tests/Feature/Security/CrossTenantIsolationTest.php` | Utvid globben til `app/Http/Controllers/Api/**`, og krev tilgangstjeneste-markør for hver bundet modell. |
| Tilgangstester per modul | `RiskAccessTest`, `ObjectiveAccessServiceTest`, `ImprovementTenantIsolationTest`, `SupplierAccessTest`, `CustomerRolePermissionTest` | Kjør de samme scenarioene mot API-endepunktene: fagområder, eksplisitt tildeling, System Owner. |
| Modulrettigheter | `CustomerModuleEntitlementTest`, `NavigationEntitlementMatrixTest` | Matrise over API-ruter og pakker. |
| Entra | `tests/Feature/Security/EntraAuthenticationTest.php` | Testnøkkelpar og falsk JWKS (`Http::fake`) for access tokens. |
| Kontraktstester | `OutboundIntegrationContractTest`, `QueueTopologyContractTest` | Ny `ApiRouteContractTest` (§11.2). |
| Testoppsett | `UsesProjectPostgresConnection`, `GrantsWikiPermissions`, `Create*Scenarios`, `ModuleEntitlementService::activatePackage`, `$customersHoldTenderPackage` | Gjenbrukes uendret. |

### 11.2 Nye sikkerhetstester (krav før produksjon)

1. **Tokenvalidering:**
   - feil signatur, `alg=none` eller HS256
   - feil `aud`, feil `iss` eller `tid`, utløpt token, `nbf` i fremtiden
   - ukjent eller deaktivert klient, modus som ikke er tillatt
   - delegert token for en deaktivert eller ukoblet bruker
   - applikasjonstoken uten aktiv kundetildeling, og `Procynia-Customer` som peker til en annen kunde
2. **`ApiRouteContractTest`:**
   - hver `api.v1.*`-rute har hele middleware-stacken og et scope
   - ingen API-rute bruker route model binding mot kundeeide modeller
   - ingen API-rute treffer en beslutning
   - OpenAPI-spesifikasjonen og rutene stemmer overens
3. **Kundeseparasjon per endepunkt:** Et token for kunde A med id-er fra kunde B gir alltid 404. Det gjelder også lister, filtre og markører.
4. **Fagområder og eksplisitt tildeling:** Integrasjonsbrukeren ser bare sine fagområder. Etterlevelse uten egen rolle gir 403, også om rollen ellers er bred.
5. **`HumanDecisionContractTest`:**
   - For hver rettighet i `humanOnlyPermissions()` finnes en beslutningstjeneste som kaller `HumanDecisionGuard`.
   - Tjenesten kaster unntak med integrasjonsaktør, med aktiv `ApiRequestContext` (delegert) og med `null`.
   - Databasetriggerne avviser integrasjonsaktører.
6. **Menneske-sperren:** Integrasjonsbrukeren
   - kan ikke logge inn med passord eller Entra
   - telles ikke i lisens
   - vises ikke i eier- eller kontrollørvelgere (strukturell test for `humans()`)
   - får ingen varsler eller oppgaver
   - vises ikke under «Brukere»
7. **Tilgangsprojeksjon og endringslogg:**
   - Rolle- eller fagområdeendring gir en `access`-hendelse.
   - Deaktivert bruker er borte fra projeksjonen.
   - En slettet forelder gir `delete`.
   - Avstemmingen finner kaskadeslettede barn.
8. **Idempotens og ratebegrensning:** Ny nøkkel gir ny handling, samme nøkkel gir samme svar, endret forespørsel med samme nøkkel gir 422. 429 med `Retry-After`.
9. **Logging:** Token, Authorization-header og innhold havner aldri i logg eller `api_request_log`.

---

## 12. Et generelt API, ikke et Siggjo-API (punkt 9)

- **Navn og ruter er produktnøytrale:** «Procynia API», `/api/v1`. Siggjo er bare én rad i `api_clients`.
- **Kunden styrer tilgangen** i en ny fane **Kundemiljø → Integrasjoner** (System Owner). Der gis og trekkes tildelinger, roller og fagområder tildeles integrasjonsidentiteten, og hendelseslogg og siste bruk vises.
- **Interne administratorer** registrerer `api_clients` i Filament. Kunden kan ikke registrere nye klienter selv i første omgang. **[BESLUTNING]**
- **Scope-katalogen er generell:** `procynia.read`, `procynia.propose` og senere scopes per modul hvis behovet oppstår.
- **Versjonering:** Ingen brytende endringer innenfor `v1`. Utfasing varsles med `Deprecation`- og `Sunset`-headere.
- **Andre identitetsleverandører** via `ApiTokenValidator` (§5.5).
- **[BESLUTNING]** Om integrasjoner skal være en egen kommersiell modul eller et tilvalg i pakkemodellen (`config/procynia_modules.php`).

---

## 13. Konflikter med dagens arkitektur

| # | Konflikt | Konsekvens | Anbefalt håndtering |
|---|---|---|---|
| K1 | Beslutningsrettigheter håndheves i kontrollerne (§7.1) | Et API ville gå forbi dem | Flytt kontrollene ned og innfør `HumanDecisionGuard` **før** skrivende API. Lese-API påvirkes ikke. |
| K2 | Wiki-arbeidsflyten ligger i `WikiController`/`WikiClaimController` uten tjenestelag | Kan ikke gjenbrukes trygt | Trekk ut til tjenester. Ingen Wiki-skriving via API før det er gjort. |
| K3 | Null-aktør godtas (`QualityProcessBlueprintService::approve`, `RequirementEditorService`), og `transitionBidStatus` lagrer ingen aktør | Beslutning uten ansvarlig | Krev `User $actor`. |
| K4 | `users.role` har tre verdier, og `resolvedBidRole()` faller tilbake til `contributor` | Integrasjon kan få stille anbudstilgang | Ny rolle `integration`. Tilbakefallet endres for den rollen. |
| K5 | E-post er påkrevd og globalt unik, passord er påkrevd | Maskinrader trenger syntetiske verdier | Syntetisk `.invalid`-adresse, ubrukelig passord og eksplisitt innloggingsavvisning. |
| K6 | Velgere, varsler og lisenstelling forutsetter at alle `users` er mennesker | Integrasjon kan bli eier eller motta varsler | `User::scopeHumans()` med strukturell test (§4.3). |
| K7 | Entra-invarianten: ingen Entra-roller kobles til Procynia-roller | App-roller og scopes i API-et kan oppfattes som brudd | Presiser at scopes bare er tak, aldri tildeling (§5.4). |
| K8 | Kundeseparasjon er en kontrollerkonvensjon, ikke globale scopes | API-et dobler flaten der konvensjonen må følges | Samme konvensjon og utvidet strukturell test. Globale scopes er bevisst forkastet (audit F-04) og innføres ikke. |
| K9 | Harde slettinger og FK-kaskader uten hendelser, og `updated_at` på forelder endres ikke ved nye barnerader | Kopier i Siggjo kan bli stående med slettede data | Outbox, tombstones og daglig avstemming (§9.3). |
| K10 | Ingen samlet audit trail | API-handlinger kan ikke etterprøves samlet | `api_request_log`, og første steg mot den planlagte audit trailen. |
| K11 | Radlogikk og webruter i kontrollerne | Dobbel logikk mellom web og API | Felles presentasjonsklasser (§8.2). |
| K12 | AI-kontekstkontroll står på `warn` | Agentutløste AI-kall kan kjøre uten kundekontekst | `strict` for alle API-utløste kall. |
| K13 | Wiki-invarianter: felles retrieval-kjerne, ingen rå utdrag, ingen vektordatabase uten beslutning, ingen ukontrollerte agentløkker | En Siggjo- eller agentindeks over Wiki-innhold vil være et parallelt kunnskapslag | Wiki eksponeres bare via den felles kjernen. En egen vektorindeks hos Siggjo krever eksplisitt arkitekturbeslutning. |
| K14 | Infrastruktur uten API Management, WAF eller VNet. MFA mangler. Ingress-område ikke verifisert. | Offentlig API-flate uten et ekstra lag | **[BESLUTNING]** Azure API Management eller Front Door med WAF foran `/api`. Uavhengig av det: ratebegrensning og logging i appen. |
| K15 | Ingen DPIA eller databehandleravtaler dokumentert. Ny AI-leverandør (Claude) via Siggjo. | Juridisk hinder for kopiering til Siggjo | DPIA og databehandleravtaler før fase 3. |
| K16 | `notices` har arbeidsflytfelt som deles mellom kunder | Anbud-API kan lekke arbeidsflyttilstand | Anbud holdes utenfor til feltene er kundeavgrenset. |
| K17 | Manuelle Wiki-endringer tar ikke hensyn til åpen godkjenning eller publisering (F1a, F1b) | Fireøyneprinsippet ved overlevering kan brytes, og en endring på en publisert side får ingen vei til godkjenning | Rettes i fase 0, steg 3. |
| K18 | `wiki.source.manage` dekker både kildeadministrasjon og kunnskapsoverføring | Fase 4 ville gi agenter sletting av kilder for å kunne overføre kunnskap | Ny rettighet `wiki.knowledge.handoff` (B1). |

---

## 14. Faseinndelt implementeringsplan

Hver fase avsluttes med målrettede tester. Full testsuite kjøres bare som milepæl ved slutten av fase 1, 2 og 4, i tråd med testregimet i `AGENTS.md`.

### Fase 0 – Beslutninger og herding (ingen API)

**Status 2026-10-23:** Herdingen i punkt 3 er gjennomført, med `HumanDecisionContractTest`. Arkitekturbeslutningene i punkt 1 utenom listen over menneskeforbeholdte rettigheter, og personvernet i punkt 2, er fortsatt åpne (§15). Sluttstatus, restpunkter og sperrer: [`external-api-phase-0-plan.md`](external-api-phase-0-plan.md) §2n.

Detaljert plan, med rekkefølge, commits og akseptansekriterier: [`external-api-phase-0-plan.md`](external-api-phase-0-plan.md). Punkt 1 «liste over menneskeforbeholdte rettigheter» er besluttet (§7.3).

1. Arkitekturbeslutninger:
   - identitetsmodell (§4)
   - scope-tak versus Entra-invarianten (§5.4)
   - liste over menneskeforbeholdte rettigheter (§7.2)
   - Siggjo-dataflyt, alternativ A og/eller B (§9)
   - API Management eller WAF (K14)
2. Personvern: DPIA og databehandleravtaler (K15).
3. Herding som også gjør webgrensesnittet sikrere:
   - `humanOnlyPermissions()` i katalogen
   - `HumanDecisionGuard`
   - rettighetskontroll flyttet ned i beslutningstjenestene
   - null-aktører fjernet
   - Wiki-arbeidsflyten trukket ut i tjenester
   - databasetriggere for de mest kritiske beslutningskolonnene
   - `wiki.knowledge.handoff` skilt fra `wiki.source.manage` (B1)
   - risiko med historikk kan ikke slettes (B3)
   - eksplisitt rettighet for godkjenning av krav i Anbud (B4)
   - retting av F1a, F1b, F2 og F3a, og revisjonsspor for tilgangsendringer (F4)

   Tester: `HumanDecisionContractTest` og eksisterende modultester.

### Fase 1 – Identitet og API-ramme

Forutsetning: A23 og A46 holdes utenfor til kravene i fase 0-planen §2n er oppfylt. A10 (feilformat) bygges her.

1. `role = integration`, `User::scopeHumans()` med strukturell test, og menneske-sperren (§4.3, §4.4).
2. `api_clients` og `api_client_customer_grants`. Filament for interne administratorer og **Kundemiljø → Integrasjoner** for System Owner.
3. `EntraAccessTokenValidator` (felles JWKS og issuer-logikk trukket ut av `EntraOidcClient`) og `ApiRequestContext`.
4. `routes/api.php` med middleware-stacken (§3), `api.v1.*` i `route_modules`, ratebegrensere og `api_request_log`.
5. `GET /api/v1/me` og OpenAPI-skjelett.

   Tester: §11.2 punkt 1, 2, 6 og 9.

### Fase 2 – Lese-API v1

1. Presentasjonsklasser trukket ut for risiko, mål, avvik, revisjoner og etterlevelsesstatus, og tatt i bruk av både web og API.
2. Endepunktene i §8.4, inkludert `attention`, og `my-tasks` i delegert modus.

   Tester: §11.2 punkt 3 og 4, og API-varianter av modultestene.

### Fase 3 – Siggjo dataintegrasjon (pilot på testkunde)

1. Personlige dashboards via delegert API (alternativ A).
2. Ved positiv beslutning om alternativ B:
   - `access-projection`
   - `api_change_events` (outbox) med `access`- og `delete`-hendelser
   - `GET /changes`
   - id-endepunkter for avstemming
   - dokumentert kaskadesemantikk og RLS-veiledning for Siggjo

   Tester: §11.2 punkt 7.

### Fase 4 – Forslag (skriving)

1. Idempotens (§10.2) og `procynia.propose`-scope.
2. Trygge opprettelser: avvik via `ImprovementCaseCreator` og kunnskapsoverføring via `WikiKnowledgeHandoffService`, etter at `canHandOff` er flyttet inn i tjenesten.
3. Eventuelt generisk `agent_suggestions` i «Mine oppgaver», etter beslutning (§10.1).

   Tester: §11.2 punkt 8, og at forslag aldri endrer en godkjent tilstand.

### Fase 5 – MCP og agenter

1. MCP-lag over lese- og forslagsendepunktene.
2. Pilotagenter i Foundry, for eksempel en risikoagent som bare leser og foreslår.
3. Eventuelt et «Spør Wiki»-verktøy via den felles Wiki-kjernen, etter Wiki-sjekklisten.

### Fase 6 – Generalisering

1. Andre identitetsleverandører eller Procynia-utstedte klientlegitimasjoner (`ApiTokenValidator`).
2. Utgående webhooks basert på samme outbox.
3. Anbud-API når K16 og avdelingsomfanget er avklart.
4. Kommersiell pakking av integrasjoner.

---

## 15. Åpne beslutninger

Beslutningsgrunnlag med anbefalinger, alternativer og konsekvenser for punktene under: [`external-api-phase-1-decisions.md`](external-api-phase-1-decisions.md) (2026-10-23, til beslutning).

1. Identitetsmodell C (§4) – anbefalt.
2. Scopes og app-roller som tak, og presisering av Entra-invarianten (§5.4).
3. Egen Entra-registrering for API-et (§5.2).
4. ~~Endelig liste over menneskeforbeholdte rettigheter (§7.2).~~ Besluttet 2026-10-22 (§7.3). Det samme gjelder sperren i delegert modus, sletting av risiko og godkjenning av krav i Anbud.
5. Siggjo-dataflyt: A, B eller begge (§9.2). Kopiering krever DPIA og databehandleravtale.
6. Generisk forslagsmodell (§10.1).
7. API Management eller WAF foran API-et (K14).
8. ~~Hvem som kan registrere API-klienter (§12).~~ Besluttet 2026-10-24 (D13): interne administratorer registrerer, kundens System Owner gir tildeling. Se [`external-api-phase-1-decisions.md`](external-api-phase-1-decisions.md) §16. Kommersiell pakking er fortsatt åpen.
9. Om en vektorindeks over Wiki-innhold hos Siggjo skal tillates (K13). Utgangspunktet er nei.
