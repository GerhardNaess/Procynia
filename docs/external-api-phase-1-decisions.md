# Eksternt API – beslutningsgrunnlag for fase 1

**Status:** API fase 1 er **lesebasert**. Bygget: autentisering, kundetildeling, feilformat, `GET /api/v1/me`, `GET /api/v1/risks` (§15), D13 med beskyttelse av integrasjonsidentiteter (§16), kontrollen 2026-10-24 (§17) `GET /api/v1/deviations` (§18) og `GET /api/v1/objectives` og `GET /api/v1/kpis` (§19). Mål og KPI venter på vurdering. Ingen flere moduler før den. Ekte Entra-, Azure- og Siggjo-testing er utsatt fordi miljøene ikke er tilgjengelige. Ingenting er satt i produksjon.
**Dato:** 2026-10-23
**Gren:** `feature/external-api-foundation`
**Bygger på:** [`external-api-architecture.md`](external-api-architecture.md) (særlig §4, §5, §8.3, §9 og §15), [`external-api-phase-0-plan.md`](external-api-phase-0-plan.md) §2n, [`operations/entra-sso.md`](operations/entra-sso.md), [`operations/security.md`](operations/security.md) §9 og [`AGENTS.md`](../AGENTS.md).

Dokumentet er et kort grunnlag for å ta de åpne arkitekturbeslutningene. Arkitekturdokumentet er fortsatt referansen for detaljene. Der dette dokumentet anbefaler noe annet enn arkitekturdokumentet, står det eksplisitt.

Merking:

- **[BESLUTTET]** – tatt tidligere, med referanse. Tas ikke opp igjen.
- **[ANBEFALT]** – må godkjennes. Alternativer og konsekvenser står ved siden av.
- **[UTSATT]** – trengs ikke for første leveranse. Besluttes når behovet er konkret.

Grunnprinsipp for anbefalingene: **den enkleste løsningen som er sikker for lesing, og ingenting som bare trengs for skriving, delegert modus eller kopiering med persontilgang før det behovet finnes.**

---

## 1. Sammendrag

| # | Beslutning | Anbefaling |
|---|---|---|
| D1 | Identitetsmodell | Modell C: integrasjonsbruker i `users` (`role = integration`), én per (klient, kunde), med egen legitimasjon i `api_clients` og `api_client_customer_grants`. |
| D2 | Tilgangsmodus i fase 1 | **Bare applikasjonsmodus.** Delegert modus bygges når et personlig dashboard eller «Mine oppgaver» faktisk skal brukes. |
| D3 | Entra-registrering | Egen app-registrering «Procynia API» (`api://procynia`), én app-rolle `Procynia.Read.All`, v2-tokens. |
| D4 | Entra-invarianten | App-rollen er bare et tak for API-operasjoner. Hva integrasjonen ser, bestemmes av Procynia-roller og fagområder. Presiseres i `entra-sso.md`. |
| D5 | Klientlegitimasjon | Siggjo autentiserer med managed identity eller federert legitimasjon. Ingen klienthemmeligheter. |
| D6 | Kundevalg | Headeren `Procynia-Customer` velger blant aktive tildelinger. Én klientregistrering for alle Siggjo-kunder. |
| D7 | Data til Siggjo | Agenter leser live. Dashboards henter et fullt øyeblikksbilde av det integrasjonen ser, og erstatter det ved hver synkronisering. Ingen tilgangsprojeksjon eller endringslogg i første leveranse. |
| D8 | Felt i svarene | Strukturerte felt, tittel og eier. Lang fritekst og kommentarer holdes utenfor til DPIA er gjort. |
| D9 | Første endepunkter | `me`, Risiko, Mål og KPI, Avvik og forbedringer, Revisjoner, Etterlevelsesstatus og samlet oppmerksomhet. Bare `GET`. |
| D10 | Logging | Strukturert logg til stderr (Log Analytics) og `last_used_at` på tildelingen. Ingen `api_request_log`-tabell før skrive-API. |
| D11 | AI på API-kanalen | Alle AI-kall nektes når forespørselen kommer via API-et. Det gjør A23 teknisk uoppnåelig, ikke bare urutet. |
| D12 | API Management eller WAF | Ikke i pilot med testdata. Besluttes før første kunde med ekte data. |
| D13 | Registrering av klienter | Interne administratorer registrerer klienter i Filament. Kundens System Owner gir og trekker tildelinger. |
| D14 | Personvern | DPIA og databehandleravtaler før ekte kundedata går til Siggjo. Blokkerer ikke bygging og pilot på testkunde. |

A10 løses med én felles feilrenderer for `api/*` (§8). A23 og A46 holdes utilgjengelige med fire uavhengige lag (§9).

---

## 2. Allerede besluttet

Disse ligger fast og påvirker alle anbefalingene under:

| Beslutning | Kilde |
|---|---|
| Første API-versjon er kontrollert lesetilgang. | Arkitektur §8.3 (2026-10-23) |
| Integrasjoner og AI-agenter tar aldri endelige godkjenninger, publiseringer eller andre formelle beslutninger (B1). | Arkitektur §7.3 |
| Menneskeforbeholdte beslutninger er ikke tilgjengelige via API-et, heller ikke i delegert modus (B2). | Arkitektur §7.3 |
| Listen over menneskeforbeholdte rettigheter, og at den trekkes fra for integrasjoner. | Arkitektur §7.2, fase 0 §4.1 |
| Vakten (`HumanDecisionGuard`) nekter `role = integration` og enhver forespørsel der `ActorChannel::isApi()` er sann. Databasetriggerne nekter integrasjonsaktører i 44 beslutningskolonner. | Fase 0 §2n, K4–K5 |
| A23 og A46 eksponeres ikke før kravene i fase 0 §2n er oppfylt. | Fase 0 §2n |
| Anbud og Wiki er utenfor v1. Leverandører og Ledelsens gjennomgang kan komme i v1.1. | Arkitektur §8.3 |
| Ingen globale scopes. Kundeseparasjon er tilgangstjenestene og regelen om aldri route-bundet modell. | Arkitektur K8, audit F-04 |
| Kopi uten tilgangsavgrensning (alternativ C i arkitektur §9.2) er forkastet. | Arkitektur §9.2 |
| Ingen vektorindeks over Wiki-innhold hos Siggjo uten egen beslutning. Utgangspunktet er nei. | Arkitektur K13, §15.9 |

---

## 3. Identitetsmodell (D1, D2)

### D1 – Modell C **[ANBEFALT]**

Som arkitektur §4.2: `api_clients` (én ekstern applikasjon), `api_client_customer_grants` (kunden har gitt klienten tilgang) og én `users`-rad med `role = integration` per (klient, kunde).

| Alternativ | Konsekvens |
|---|---|
| **C (anbefalt)** | Tjenester, rettigheter, fagområder og historikk virker uendret, fordi aktøren er en `User`. Fase 0 har allerede gjort det trygt: vakten, rettighetstrekket og triggerne kjenner `role = integration`. Det som gjenstår, er menneske-sperren i arkitektur §4.3 (`User::scopeHumans()`, varsler, lisens, «Brukere», `resolvedBidRole()`). |
| A – vanlig `users`-rad | Telles i lisens, kan bli eier, får varsler, faller tilbake til `contributor` i Anbud. Forkastet. |
| B – egen `Principal`-modell | Hundrevis av `User $actor`-signaturer og historikk-FK-er må endres. Forkastet. |

Integrasjonsbrukeren får vanlige kunderoller og fagområder. System Owner velger dem i den nye fanen **Kundemiljø → Integrasjoner**. Selv om en bred rolle tildeles, kan integrasjonen aldri få en menneskeforbeholdt rettighet (fase 0, K4). Etterlevelse og Leverandøroppfølging krever eksplisitt rolle, også for integrasjonen.

**Anbefaling for rollevalg:** Ingen ny rolletype. System Owner lager eller velger en lesende rolle i den eksisterende rolleredigeringen. Det holder fordi v1 bare har `GET`-ruter, og fordi rettighetstrekket gjelder uansett.

### D2 – Bare applikasjonsmodus i fase 1 **[ANBEFALT]** (avvik fra arkitekturen)

Arkitektur §5.3 beskriver både delegert modus og applikasjonsmodus. Anbefalingen er å bygge bare applikasjonsmodus nå.

| | Applikasjonsmodus | Delegert modus |
|---|---|---|
| Hva Siggjo trenger | Dashboards for virksomheten og agenter som leser kundens data | Personlige dashboards og «Mine oppgaver» |
| Hva som må bygges | Klientvalidering, tildeling, integrasjonsbruker | I tillegg: brukerkobling `oid` → `user_identities`, samtykke, on-behalf-of-flyt hos Siggjo, frontend-tilgangskontroll per kall |
| Kjent behov i dag | Ja | Ikke konkretisert |

**Konsekvens av å utsette delegert modus:**
- `GET /my-tasks` utgår fra første leveranse (gir bare mening for en person).
- `ApiRequestContext` lages slik at modus er et felt. Delegert modus kan legges til uten å endre vakten, fordi vakten allerede nekter all API-kanal (B2).
- Ingen delegerte scopes defineres i Entra før de trengs.

**Alternativ:** bygge begge modusene nå, som i arkitektur §14 fase 1. Det gir mer kode og flere sikkerhetstester for en bruk som ikke er bestilt.

---

## 4. Microsoft Entra ID, scopes, roller og kundetilgang (D3–D6)

### D3 – Egen registrering for API-et **[ANBEFALT]** (arkitektur §15.3)

- Ny app-registrering «Procynia API», adskilt fra innloggingsappen (`AUTH_ENTRA_CLIENT_ID`). Da kan API-tilgang og innlogging styres, roteres og slås av hver for seg.
- App ID URI `api://procynia`. Manifestet setter `accessTokenAcceptedVersion: 2`, slik at alle tokens har samme format og `iss`.
- Multi-tenant, fordi Siggjos identitet ligger i Advanias tenant og ikke i Procynias.
- **Én app-rolle:** `Procynia.Read.All` (type `Application`). Ingen delegerte scopes før D2 åpner for det. Ingen rolle per modul.

**Konsekvens av én rolle:** Entra kan ikke begrense en klient til enkelte moduler. Det er bevisst. Modulvalget ligger i Procynia-rollene og i modulpakken til kunden, og det er der det skal styres. En egen rolle per modul ville gjort Entra til en ny tilgangskilde (se D4).

### D4 – App-rollen er et tak, ikke en tildeling **[ANBEFALT]** (arkitektur §5.4, §15.2)

- `Procynia.Read.All` betyr bare «kan kalle leseendepunktene». Om integrasjonen får se en bestemt risiko, avgjøres av integrasjonsbrukerens Procynia-roller og fagområder.
- Effektive scopes = tokenets roller ∩ tildelingens scopes ∩ klientens tak (arkitektur §5.3 punkt 7).
- `entra-sso.md` presiseres med én setning: «Entra-app-roller på Procynia API gir bare adgang til å kalle API-et. De tildeler aldri Procynia-roller, rettigheter eller fagområder.»

**Alternativ:** forby app-roller helt og styre alt med `api_clients`. Da må tokenet fortsatt valideres, men Entra-administratoren hos klienten kan ikke se eller begrense hva klienten har lov til. Det gir dårligere kontroll hos Advania og ingen sikkerhetsgevinst.

### D5 – Ingen klienthemmeligheter **[ANBEFALT]** (nytt)

- Siggjo-komponenter i Azure (Foundry-agenter, synkroniseringsjobben) bruker **managed identity**. Utenfor Azure brukes **federert legitimasjon** (workload identity federation) eller sertifikat.
- Klienthemmeligheter tillates ikke i `api_clients`. Procynia lagrer bare app-ID (`azp`) og hjem-tenant, aldri en hemmelighet.

**Konsekvens:** Ingen hemmelighet å lekke, rotere eller oppbevare i Procynia. Siggjo må sette opp app-rolletildelingen for sin managed identity i Advanias tenant. Det er en engangsjobb for Advanias Entra-administrator.

### D6 – Kunden velges fra aktive tildelinger **[ANBEFALT]** (arkitektur §5.3 punkt 5)

Siggjos token kommer fra Advanias tenant og sier ikke hvilken kunde kallet gjelder. Validering per forespørsel:

```text
1. Signatur (RS256, JWKS for tokenets tid), aud = api://procynia, exp/nbf, iss = …/{tid}/v2.0
2. (azp, tid) må finnes i api_clients som (entra_app_id, home_tenant_id), med is_enabled = true
   → begge må stemme. Det er dette som gjør multi-tenant trygt: en fremmed app med samme rolle avvises.
3. Ingen scp-claim, og roles ∋ Procynia.Read.All → applikasjonsmodus
4. Procynia-Customer: <customer_id> → api_client_customer_grants (klient + kunde, revoked_at IS NULL)
   → integration_user_id. Headeren er bare en velger. Uten aktiv tildeling: 403, samme svar som for en kunde som ikke finnes.
5. Kunden er aktiv, integrasjonsbrukeren er aktiv
6. Auth::setUser(integrasjonsbruker), ApiRequestContext bindes som ActorChannel, ingen sesjon
```

| Alternativ | Konsekvens |
|---|---|
| **Header blant tildelinger (anbefalt)** | Én registrering og én identitet hos Siggjo for alle kunder. Kunden avledes alltid fra en tildeling som kundens System Owner har gitt. |
| Én app-registrering per kunde | Tokenet binder selv kunden, og en lekket identitet rammer bare én kunde. Men hver nye kunde krever ny registrering og rolletildeling i Entra, og Siggjo må holde én identitet per kunde. |

**Konsekvens av anbefalingen:** En kompromittert Siggjo-identitet gir lesetilgang hos alle kunder som har gitt tildeling. Det begrenses av D5 (ingen hemmelighet å stjele), lesetilgang alene, ratebegrensning per kunde, og at hver kunde kan trekke tildelingen. Velger dere per-kunde-registrering senere, endres bare trinn 4.

---

## 5. Kundeseparasjon i hvert kall

Ingen nye regler. Det som er nytt, er at API-stacken må følge dem og at testene beviser det.

| Lag | Hvordan | Status |
|---|---|---|
| Kunde fra identitet, aldri fra input | Kunden kommer fra tildelingen (D6). `CustomerContext` leser `Auth::user()`, som er integrasjonsbrukeren med `customer_id`. | Tildelingen **[MÅ UTVIKLES]**, `CustomerContext` **[FINNES]** |
| Poster hentes via tilgangstjenesten | `findVisible*()` med aktøren. Ingen route model binding mot kundeeide modeller. Annen kundes id gir 404. | Tjenestene **[FINNES]** |
| Lister bygges fra `visible*()` | Filtre og markører legges oppå builderen. Markøren er opak og inneholder bare siste id og filtrene, aldri kunden. Den kan derfor ikke peke ut av builderen. | **[MÅ UTVIKLES]** |
| Modul | `EnsureModuleIsEnabled` med `api.v1.*` i `route_modules`. Gir allerede 403 som JSON. | **[MÅ ENDRES]** (kartet) |
| Rettighet og fagområde | `CustomerPermissionService`, `*AccessService::can*()` og fagområdeslogikken med integrasjonsbrukeren. | **[FINNES]** |
| Strukturell test | `CrossTenantIsolationTest` utvides til `app/Http/Controllers/Api/**`. Ny `ApiRouteContractTest` (§9). | **[MÅ UTVIKLES]** |
| Atferdstest | Per endepunkt: token for kunde A med id-er, filtre og markører fra kunde B gir 404 eller tom liste. Tildeling som er trukket tilbake gir 403 i neste kall. | **[MÅ UTVIKLES]** |

Det trengs ingen globale scopes og ingen ny tilgangsmotor.

---

## 6. Data til Siggjos dashboards og AI-agenter (D7, D8)

### D7 – To bruksmønstre, ett API **[ANBEFALT]** (arkitektur §15.5, avvik fra §9.3)

**Agenter (Foundry):** leser live fra API-et i applikasjonsmodus. Hvert verktøy svarer til ett endepunkt. Agenten ser nøyaktig det integrasjonsbrukeren ser. Ingen kopi.

**Dashboards (Siggjos PostgreSQL):** en synkroniseringsjobb hos Siggjo henter *hele* listen for hver modul, med sidevis henting, og **erstatter** kundens data i Siggjo ved hver kjøring.

- **Slettinger håndteres av seg selv:** det som ikke kommer med i neste øyeblikksbilde, fjernes. Det løser arkitektur K9 (harde slettinger og FK-kaskader uten hendelser) uten endringslogg.
- **Tilgangsendringer håndteres av seg selv:** endres integrasjonsbrukerens roller eller fagområder, gir neste øyeblikksbilde det nye omfanget. Trekkes tildelingen, får Siggjo 403 og skal slette kundens data innen fristen i databehandleravtalen.
- **Hvem i Siggjo som ser dataene:** integrasjonens synlige data er *ett* datasett. Kunden bestemmer, i avtalen med Siggjo, hvilket publikum dashboardet har (for eksempel ledergruppen). System Owner velger roller og fagområder for integrasjonen ut fra det publikummet.

| Alternativ | Konsekvens |
|---|---|
| **Øyeblikksbilde med kundebestemt publikum (anbefalt)** | Ingenting nytt i Procynia utover lese-API-et. Ingen tilgangslogikk hos Siggjo. Forsinkelse = synkroniseringsintervallet (forslag: hver time). Passer når dashboardet har én målgruppe per kunde. |
| Tilgangsprojeksjon, endringslogg og RLS (arkitektur §9.3, alternativ B) | Riktig når ett dashboard skal vise *ulike* data til ulike personer. Krever `access-projection`, `api_change_events` med tombstones, avstemming, kobling `oid` → `user_id` hos Siggjo og RLS-regler. Mye mer å bygge og teste. |
| Bare live-kall fra dashboards (alternativ A) | Krever delegert modus (D2) for personlige visninger. Egnet senere for personlige dashboards. |

**Konsekvens av anbefalingen:** Ansvaret for hvem som ser dashboardet, ligger i avtalen mellom kunden og Siggjo, ikke i Procynias regler. Det er akseptabelt så lenge datasettet aldri er større enn det kunden selv har valgt for integrasjonen. Det er ikke alternativ C, som er forkastet: der så alle i kunden alt. Trenger én kunde ulike visninger per person, er det et konkret behov for alternativ B, og det besluttes da.

**Volum:** øyeblikksbilde fungerer for dagens størrelser (hundrer til noen tusen rader per modul). Blir én modul for stor til å hentes hver time, er det signalet for en endringslogg.

### D8 – Felt i svarene **[ANBEFALT]** (arkitektur §9.3 punkt 8)

- **Med:** id-er, tittel, status, nivåer og poeng, datoer, fagområde, eier (id og navn), tellere og avledet tilstand (for eksempel `is_review_overdue`, KPI-status).
- **Ikke med i v1:** beskrivelser, begrunnelser, kommentarer, vedlegg og personnavn utover eier.
- Svarene har stabile id-er, ingen webruter (arkitektur §8.2).

**Konsekvens:** agentene kan svare på «hvilke risikoer er høye og over frist», men ikke på «hva handler denne risikoen om». Fritekst legges til som eget felt når DPIA og databehandleravtale med Siggjos AI-leverandør er på plass (D14). Det er en utvidelse uten brudd i `v1`.

---

## 7. Første leveranse: lesebaserte endepunkter (D9)

**[ANBEFALT]** Bare `GET`. Alle under `/api/v1`, alle med samme middleware og scope.

| Endepunkt | Tilgang via | Presentasjon | Arbeid |
|---|---|---|---|
| `GET /me` | Integrasjonsbruker, tildeling, moduler, effektive rettigheter, fagområder | Ny | Liten |
| `GET /risks`, `GET /risks/{id}` | `RiskAccessService::visibleRisks`/`findVisible` | `RiskPresenter` trekkes ut av `RiskController` | Middels |
| `GET /objectives`, `GET /objectives/{id}` (med KPI-er og status) | `ObjectiveAccessService::visibleObjectives`/`visibleKpis` | `KpiPresenter` **[FINNES]**, målraden trekkes ut | Liten–middels |
| `GET /kpis/{id}/measurements` | `findVisibleKpi` | `KpiPresenter::history` **[FINNES]** | Liten |
| `GET /improvement-cases`, `GET /improvement-cases/{id}` | `ImprovementCaseAccessService::visibleCases`/`findVisible` | `ImprovementCasePresenter` trekkes ut, `now()` erstattes med `$today` | Middels |
| `GET /compliance/audits`, `GET /compliance/audits/{id}` | `ComplianceAccessService::visibleAudits`/`findVisibleAudit` | `ComplianceAuditPresenter` trekkes ut | Middels |
| `GET /compliance/status` | `visibleRequirements`, `ComplianceStatusResolver` | Aggregeringen trekkes ut av `ComplianceAssessmentController::index` | Middels |
| `GET /attention` | Oppmerksomhetstjenestene per modul, bare for moduler aktøren har | Ny samling over eksisterende tjenester | Liten–middels |

Presentasjonsklassene tas i bruk av både web og API i samme commit, slik at grensesnittene ikke glir fra hverandre (arkitektur §8.2).

**Ikke i første leveranse:**

| Utelatt | Hvorfor |
|---|---|
| `GET /my-tasks` | Bare meningsfullt i delegert modus (D2) |
| `GET /access-projection`, `GET /changes`, `GET /{entity}/ids` | Trengs ikke med øyeblikksbilde (D7) |
| Leverandører, Ledelsens gjennomgang | v1.1, samme mønster |
| Wiki, Anbud, Kvalitet | Arkitektur §8.3, A23, A46 |

**Til avklaring med Siggjo:** hvilke moduler pilotdashboardet faktisk trenger. Trenger piloten bare Risiko og Mål og KPI, leveres de først og resten etter.

**Fellesregler** (arkitektur §8.4): markørbasert henting (`limit` ≤ 200), UTC i ISO 8601, `meta.as_of` og `meta.request_id`, OpenAPI 3.1 i repoet, ratebegrensning per (klient, kunde) med 429 og `Retry-After`.

---

## 8. A10 – felles feilformat

**Status:** `bootstrap/app.php` har en tom `withExceptions`. Tjenestene kaster `HumanDecisionRefused` (arver `AuthorizationException`), `RiskHistoryProtected` og `EnterpriseWikiReviewConflict` (arver `ConflictHttpException`), `ValidationException` og, i Wiki, `HttpException` via `abort()` (A1).

**[ANBEFALT]** Én renderer i `withExceptions` som bare gjelder `api/*`. Webgrensesnittet påvirkes ikke.

| Unntak | Status | `error.code` |
|---|---|---|
| Ugyldig eller manglende token | 401 | `unauthenticated` |
| `AuthorizationException` (inkl. `HumanDecisionRefused`), manglende modul, scope eller tildeling | 403 | `forbidden` |
| `ModelNotFoundException`, `NotFoundHttpException`, ikke synlig | 404 | `not_found` |
| `ConflictHttpException` og arvinger | 409 | `conflict` |
| `ValidationException` | 422 | `validation_failed` (med feltene) |
| `ThrottleRequestsException` | 429 | `rate_limited` |
| Annen `HttpException` (A1) | Unntakets status | Fra fast liste, ellers `error` |
| Alt annet | 500 | `server_error` |

Regler:
- Format: `{"error": {"code": "…", "message": "…", "request_id": "…"}}`.
- Meldingen er en fast tekst per kode. Den røper aldri modul, post eller person (B10). Unntakets egen melding sendes ikke ut, bortsett fra feltene i 422.
- 403 og 404 skilles ikke fra «finnes ikke» utover det tilgangstjenestene allerede gjør.

**Konsekvens:** I et rent lese-API er det i praksis 401, 403, 404, 422 og 429 som forekommer. 409 og `HumanDecisionRefused` blir relevante først ved skriving, men rendereren dekker dem fra start. Én testklasse per kode.

**Alternativ:** egne domeneunntak i stedet for `abort()` i Wiki (A1). Ikke nødvendig nå, siden Wiki ikke er i API-et.

---

## 9. A23 og A46 – teknisk utilgjengelige

Ingen av dem kan nås gjennom en enkelt glemt regel. Fire uavhengige lag:

| Lag | Hvordan | Dekker |
|---|---|---|
| 1. Ingen rute | Første leveranse har bare `GET`. `ApiRouteContractTest` feiler hvis en `api.v1.*`-rute har en annen metode enn `GET`/`HEAD`. | A23 og A46 (begge er skriving eller AI-arbeid) |
| 2. Ingen modul | `route_modules` for `api.v1.*` har ikke Kvalitet, Wiki eller Anbud. `ApiRouteContractTest` feiler hvis en API-rute peker til de modulene. | A23 og A46 |
| 3. Ingen kode | `ApiRouteContractTest` skanner `app/Http/Controllers/Api/**` og nekter referanser til `QualityProcessFlowInterpreter`, `QualityProcessDescriptionClarifier`, `QualityProcessFlowChangeProposer`, `EnterpriseWikiQuestionAnswerService`, `NoticeController` og `SavedNotice`. | A23 og A46 |
| 4a. Ingen AI på API-kanalen (D11) | `AiCallContextPolicy` nekter ethvert AI-kall når `ActorChannel::isApi()` er sann. Én sjekk der alle AI-kall allerede passerer, og én test. | A23, også via en vei ingen har tenkt på |
| 4b. Ingen webinngang | En integrasjon slipper ikke inn i kundefrontenden (`canAccessCustomerFrontend`, A9). Webrutene for A23 og A46 kan derfor ikke kalles med integrasjonsbrukeren. | A23 og A46 |

### D11 – AI nektes på API-kanalen **[ANBEFALT]** (nytt)

**Hvorfor:** Lese-API-et skal aldri starte AI-arbeid. Lag 1–3 gjør A23 urutet, men en presentasjonsklasse eller oppmerksomhetstjeneste som i fremtiden kaller en AI-tjeneste, ville gått rett gjennom. Lag 4a stopper det der pengene brukes.

**Hvor:** `OpenAiClient` er eneste vei ut til modellen og kaller allerede `AiCallContextPolicy::enforce()` før hvert kall. Sperren gjelder kall i selve API-forespørselen. En køjobb kjører uten API-kanal, men et lese-API kan ikke starte køjobber, fordi det ikke har skriveruter (lag 1).

**Konsekvens:** Når et AI-endepunkt eventuelt åpnes (A23 løst, egen beslutning), erstattes forbudet med en eksplisitt liste over tillatte operasjoner og `strict` kontekstkontroll (arkitektur K12). Til da finnes det ingen.

**Alternativ:** bare lag 1–3. Enklere med én test mindre, men A23 er da beskyttet av konvensjon i stedet for av koden.

**A46 trenger ikke besluttes nå.** Om «Flytt til historikk» med utfall er en menneskelig beslutning, avgjøres når Anbud skal inn i API-et. Lag 1–3 og 4b holder den utenfor til da. Det er ingen `HumanDecision` for A46, så vakten er ikke et av lagene.

---

## 10. Logging av autentisering, tilgang og bruk (D10)

**[ANBEFALT]** (avvik fra arkitektur §10.3, som foreslår en `api_request_log`-tabell)

| Hva | Hvor | Felter |
|---|---|---|
| Hver API-forespørsel | Strukturert logg, prefiks `[PROCYNIA][API]`, `event = api_request`, til stderr og Log Analytics | `request_id`, `api_client_id`, `customer_id`, `actor_user_id`, `mode`, rutenavn, metode, status, varighet, antall rader |
| Avvist token eller tildeling | `[PROCYNIA][AUTH]`, `event = api_token_rejected`, nivå `warning` | `reason` (fast liste: `invalid_signature`, `wrong_audience`, `expired`, `unknown_client`, `client_disabled`, `mode_not_allowed`, `no_active_grant`, `actor_inactive`), `azp`, `tid`, `ip` |
| Ratebegrensning | `[PROCYNIA][API]`, `event = api_throttled` | `api_client_id`, `customer_id`, `retry_after` |
| Tildeling gitt, endret eller trukket tilbake | `customer_access_changes` via `CustomerAccessChangeRecorder` **[FINNES]** | Aktør (System Owner), før og etter |
| Siste bruk | `api_client_customer_grants.last_used_at`, oppdatert høyst én gang per minutt | Vises i **Kundemiljø → Integrasjoner** |

**Logges aldri:** token, `Authorization`-header, forespørsels- eller svarinnhold, verdier i spørrestrengen (bare navnene på filtrene). Samme regel og samme type test som innloggingen i `security.md` §9.1.

| Alternativ | Konsekvens |
|---|---|
| **Logg + `last_used_at` (anbefalt)** | Ingen ny tabell som vokser med hver forespørsel. Drift ser alt i Log Analytics. Kunden ser hvem som har tilgang, hvem som ga den og når den sist ble brukt. |
| `api_request_log` i databasen | Kunden kan få en bruksrapport i Procynia. Krever retensjon, indekser og opprydding. Trengs senest ved skrive-API (fase 4), når påvirkede id-er skal kunne etterprøves sammen med domenehistorikken. |

**Forutsetning før produksjon:** retensjon og lesetilgang i Log Analytics må være satt (`security.md` §9: «Må verifiseres i produksjon»).

---

## 11. Verifisering før produksjonssetting

Fase 0 er godkjent uten disse. Ingen av dem blokkerer at fase 1 bygges. Alle må være gjort før koden settes i produksjon.

| # | Verifisering | Når | Hvordan | Godkjent når |
|---|---|---|---|---|
| V1 | Redis-testene (`RedisRuntimeContractTest`, tre tester) | Før merge av `feature/external-api-foundation` til `main` | Kjøres mot en egen Redis-instans med passord og egen database, ikke mot utviklingsstakkens DB 0. Enklest: en Redis-tjeneste i det isolerte E2E-miljøet (`scripts/e2e-env.sh`), med `REDIS_PASSWORD` gitt til testprosessen. | 3 av 3 bestått, ingen hoppet over |
| V2 | Ekte AI-kall og Wiki-kjøring med køarbeidere | Før merge til `main`, etter V1 | På utviklingsstakken med en egen testkunde, etter omstart av alle `queue-*`-arbeidere. Minst: én Wiki-kjøring fra opplasting til publisert side via tildelt kontrollør, én «Overfør til Wiki», ett kravsvar og én vurdering i Anbud, ett AI-forslag i Kvalitet og ett «Spør Wiki»-svar. Kostnaden er liten, men det er ekte OpenAI-kall og må startes eksplisitt. | Alle fullført uten feil. Beslutningskolonnene har menneskelig aktør. Ingen `unattributed` i AI-bruksloggen. |
| V3 | Migreringer og deploy i Azure | Før produksjonsdeploy av fase 0, i staging først | (1) Datakontrollen fra steg 19 kjøres mot en kopi av produksjonsdata. (2) Migreringene 000005–000014 kjøres i staging. (3) Triggerne og funksjonene kontrolleres i katalogen. (4) `down()` prøves i staging. (5) Røyktest av beslutningsflytene i alle ni moduler. (6) Produksjon i et vedlikeholdsvindu med backup rett før. | Staging og produksjon har samme triggere. Røyktesten er grønn. Ingen rader stoppet av triggerne. |
| V4 | Integrasjons- og sikkerhetstester for API-et | Automatiske: i fase 1 og 2, som en del av hver commit. Ekte Entra: før første kunde med ekte data. | Automatisk: arkitektur §11.2 punkt 1–4, 6 og 9, `ApiRouteContractTest` (§9), feilformatet (§8) og D11. Med ekte Entra i staging: gyldig token fra en managed identity, feil `aud`, annen tenant, fremmed app med samme rolle, deaktivert klient, trukket tildeling, og `Procynia-Customer` for en kunde uten tildeling. | Alle automatiske tester grønne. Staging-sjekklisten signert. |

V1–V3 hører til fase 0 og kan gjøres parallelt med bygging av fase 1. V4 hører til API-et. API-et eksponeres ikke mot en kunde med ekte data før V1–V4 og D14 er oppfylt.

---

## 12. Eksisterende kode som gjenbrukes

| Behov | Gjenbrukes | Status |
|---|---|---|
| JWKS og signatur | `EntraOidcClient::jwks()` og `firebase/php-jwt`. JWKS-hentingen trekkes ut slik at innlogging og API deler den. | **[MÅ ENDRES]** (uttrekk) |
| Entra-konfigurasjon | `EntraConfig` | **[FINNES]** |
| Kanal og vakt | `ActorChannel` (bundet som `scoped` i `AppServiceProvider`), `HumanDecisionGuard`. `ApiRequestContext` implementerer `ActorChannel`. | **[FINNES]** |
| Integrasjonskontotypen | `User::ROLE_INTEGRATION`, `isIntegration()`, rettighetstrekket i `CustomerPermissionService` | **[FINNES]**. Menneske-sperren i arkitektur §4.3 **[MÅ ENDRES]** |
| Rettigheter og fagområder | `CustomerPermissionService`, `CustomerPermissionCatalog`, fagområdeslogikken i Risiko, Mål og Avvik | **[FINNES]** |
| Tilgang per modul | `RiskAccessService`, `ObjectiveAccessService`, `ImprovementCaseAccessService`, `ComplianceAccessService` (`visible*`, `findVisible*`) | **[FINNES]** |
| Avledet tilstand | `RiskAssessment::latestForRisks`, `RiskScoringPolicy`, `RiskReviewSchedule`, `KpiTargetPolicy`, `KpiMeasurementResolver`, `ImprovementActionVerificationResolver`, `ComplianceStatusResolver` | **[FINNES]** |
| Oppmerksomhet | `RiskAttentionService`, `ObjectiveAttentionService`, `ImprovementAttentionService`, `ComplianceAttentionService`, `ComplianceAuditAttentionService` | **[FINNES]** |
| Presentasjon | `KpiPresenter` | **[FINNES]**. Risiko, Avvik, Revisjon og etterlevelsesstatus **[MÅ ENDRES]** (uttrekk) |
| Modulsperre | `EnsureModuleIsEnabled`, `config/procynia_modules.php` → `route_modules` | **[MÅ ENDRES]** (kart) |
| Ratebegrensning | `RateLimiter::for(...)` som `entra-auth` i `AppServiceProvider` | **[FINNES]** (mønster) |
| Revisjonsspor for tildelinger | `CustomerAccessChangeRecorder`, `customer_access_changes` | **[FINNES]** |
| AI-sperre (D11) | `AiCallContextPolicy` | **[MÅ ENDRES]** (én sjekk) |
| Tester | `CrossTenantIsolationTest`, `HumanDecisionContractTest`, `EntraAuthenticationTest` (testnøkler og falsk JWKS), `UsesProjectPostgresConnection`, `ModuleEntitlementService::activatePackage` | **[FINNES]** |
| Senere | `MyTasksService` (delegert modus), `ManagementReviewPresenter` og `SupplierAccessService` (v1.1) | **[FINNES]** |

Ingen ny tilgangsmotor, ingen ny rettighetsmodell og ingen ny historikkmodell.

---

## 13. Beslutninger som må tas

**Før fase 1 kan starte:**

| # | Beslutning | Anbefaling | Arkitektur |
|---|---|---|---|
| D1 | Identitetsmodell | C | §15.1 |
| D2 | Bare applikasjonsmodus i fase 1 | Ja | Nytt |
| D3 | Egen Entra-registrering, én app-rolle | Ja | §15.3 |
| D4 | App-rollen som tak, presisering i `entra-sso.md` | Ja | §15.2 |
| D5 | Ingen klienthemmeligheter | Ja | Nytt |
| D6 | Kundevalg via header blant aktive tildelinger | Ja | §5.3 |
| D10 | Logg og `last_used_at`, ingen loggtabell ennå | Ja | Avvik fra §10.3 |
| D11 | AI nektes på API-kanalen | Ja | Nytt |
| D13 | Interne administratorer registrerer klienter, System Owner gir tildeling | Ja | §15.8 (første del) |

**Før lese-endepunktene (fase 2):**

| # | Beslutning | Anbefaling |
|---|---|---|
| D7 | Øyeblikksbilde med kundebestemt publikum | Ja (§15.5) |
| D8 | Bare strukturerte felt i v1 | Ja |
| D9 | Endepunktene i §7, med modulrekkefølge avklart med Siggjo | Ja |

**Før første kunde med ekte data:**

| # | Beslutning | Anbefaling |
|---|---|---|
| D12 | API Management eller Front Door med WAF | Besluttes da. Ikke nødvendig for pilot med testdata, fordi applikasjonen selv validerer token, kunde og rate. (§15.7, K14) |
| D14 | DPIA og databehandleravtaler med Advania/Siggjo og Siggjos AI-leverandør | Påkrevd (K15) |

**[UTSATT]** – trengs ikke nå:

- Delegert modus og «Mine oppgaver» (D2).
- Tilgangsprojeksjon, endringslogg og RLS hos Siggjo (D7, alternativ B).
- Generisk forslagsmodell (§15.6) og alt annet som skriver.
- Om A46 er en menneskelig beslutning (§9).
- Avdelingsomfang for integrasjoner i Anbud.
- Vektorindeks over Wiki hos Siggjo (§15.9). Utgangspunktet er nei.
- Kommersiell pakking av integrasjoner (§15.8, andre del). Bør avklares før første betalende kunde, men påvirker ikke arkitekturen.

---

## 14. Rekkefølge etter godkjenning

Til orientering. Starter ikke før §13 er godkjent.

1. **Fase 1 – ramme:** menneske-sperren (`scopeHumans()`, varsler, lisens, «Brukere», `resolvedBidRole()`), `api_clients` og `api_client_customer_grants` med Filament og **Kundemiljø → Integrasjoner**, tokenvalidering for applikasjonsmodus, `ApiRequestContext`, `routes/api.php` med middleware, feilformatet (A10), ratebegrensning, logging, D11, `GET /me`, OpenAPI-skjelett, `ApiRouteContractTest`.
2. **Fase 2 – lesing:** presentasjonsklassene trukket ut og brukt av web og API, deretter endepunktene i §7 i rekkefølgen Siggjo trenger.
3. **Pilot:** én testkunde, Siggjos managed identity i staging, V4 med ekte Entra.

Full PHP-suite kjøres som milepæl ved slutten av fase 1 og fase 2, ikke underveis.

---

## 15. Gjennomføring av første leveranse (2026-10-23)

Bygget på `feature/external-api-foundation` etter instruks 2026-10-23: applikasjonsautentisering via Entra, kundetildeling med integrasjonsidentitet, bare lesing, ingen menneskelige beslutninger, ingen AI, A23 og A46 utilgjengelige.

### Hva som er bygget

| Del | Innhold |
|---|---|
| Feilformat (A10) | `ApiErrorResponder` for alt under `/api`. Fast melding per kode, aldri unntakets egen tekst. `X-Request-Id` på alle svar. Webgrensesnittet er uendret. |
| Autentisering (D2–D5) | `EntraAccessTokenValidator`: v2-token, bare RS256, issuer for tokenets tenant, godkjent audience, utløp, og paret (`azp`, `tid`) må være en aktivert rad i `api_clients`. Delegert token eller manglende app-rolle gir 403. En tenant uten klient avvises før nøkler hentes. `EntraJwksProvider` deles med innloggingen. |
| Kanal (B2, D11) | Alt under `/api` er API-kanal, avgjort av stien før autentisering. `HumanDecisionGuard` nekter alle beslutninger der, og `AiCallContextPolicy` nekter alle AI-kall. |
| Tildeling (D1, D6) | `api_client_customer_grants`: én aktiv tildeling per (klient, kunde), én integrasjonsidentitet per tildeling. `Procynia-Customer` velger blant aktive tildelinger. Tildeling som peker på en person eller en annen kundes identitet nektes. |
| Logging (D10) | `[PROCYNIA][API] api_request` per forespørsel, `[PROCYNIA][AUTH] api_token_rejected` og `api_access_refused` med grunn, `api_throttled`. Aldri token, header, innhold eller spørreverdier. `last_used_at` på tildelingen, høyst én gang per minutt. |
| Ratebegrensning | Per (klient, kunde), `API_RATE_LIMIT_PER_MINUTE` (standard 240). |
| CORS | Stengt (`config/cors.php`). API-et kalles fra server til server. |
| `GET /api/v1/me` | Klient, kunde, modus, identitet og Risiko: bestilt, lesbar, fagområder. |
| `GET /api/v1/risks` | `RiskAccessService::visibleRisks()` for identiteten. Filtre `status`, `business_area_id`, markør og `limit` ≤ 200. Bare strukturerte felt (D8), med vurdering og periodisk gjennomgang fra `RiskAssessment::latestForRisks`, `RiskScoringPolicy` og `RiskReviewSchedule`. |
| Kontrakt | `ApiRouteContractTest`: bare `GET`, hele mellomvarestakken i rekkefølge, bare tjente moduler (`risk`), ingen modellbinding, ingen referanse til tjenestene bak A23 og A46. |

Konfigurasjon: `API_ENTRA_AUDIENCES` (tom = API-et er stengt), `API_ENTRA_READ_ROLE`, `API_RATE_LIMIT_PER_MINUTE`.

Migreringer: `2026_10_23_000015_create_api_clients_table`, `2026_10_23_000016_create_api_client_customer_grants_table`.

### Avvik fra beslutningsgrunnlaget

- **Ingen `allowed_modes`, `max_scopes` eller `granted_scopes`.** Med bare applikasjonsmodus (D2) og én app-rolle (D3) er snittet i D4 trivielt. Kolonnene legges til når en andre rolle eller delegert modus kommer.
- **Én liste, ikke detalj.** Første Risiko-leveranse er `GET /risks`. `GET /risks/{id}` er ikke bygget.
- **Presentasjonen deles ikke med webgrensesnittet ennå.** `Api\Presenters\RiskPresenter` bruker de samme domeneklassene som registeret, men webkontrolleren er ikke endret, slik at brukeropplevelsen er uendret. Radene har ulikt innhold (webraden har fritekst og ruter).
- **`azpacr` kontrolleres ikke.** D5 (ingen klienthemmeligheter) håndheves i Advanias registrering. Om tokenets `azpacr` skal kreves lik `2`, bør verifiseres mot et ekte token fra Siggjos managed identity først.

### Åpent

- **D13** er besluttet og gjennomført 2026-10-24, se §16.
- **Observasjon, innlogging:** se §16, «Entra: `alg` i Microsofts nøkkelliste». Verifisert, og det krever egen retting.
- V1–V4 (§11) gjelder fortsatt.

---

## 16. D13 og beskyttelse av integrasjonsidentiteter (2026-10-24)

### D13 – besluttet ansvarsmodell

1. Interne Procynia-plattformadministratorer registrerer, aktiverer og deaktiverer klientapplikasjoner i Filament.
2. Kundens System Owner gir og trekker tilbake tilgangen til en godkjent applikasjon for egen kunde.
3. System Owner bestemmer moduler og leserettigheter gjennom kundens egne roller, innenfor det kunden har.
4. En klientregistrering gir aldri tilgang til kundedata.
5. Alle tildelinger og endringer kontrolleres mot riktig kunde og registreres med aktør og tidspunkt.
6. En deaktivert klient eller tilbakekalt tildeling avvises ved neste kall.

### Klientadministrasjon (punkt 1, 4, 5, 6)

- **Filament → Kunder → API-klienter:** registrere, endre navn og beskrivelse, aktivere og deaktivere. Ingen sletting.
- **Kontrollen ligger i tjenesten** (`ApiClientAdministration`), ikke i skjermene. Den krever en aktiv intern administrator som handler interaktivt, ikke via API-kanalen. Filament-sidene skriver bare gjennom tjenesten.
- **Entra-identiteten er fast etter registrering.** Applikasjons-ID og tenant kan ikke endres, fordi tildelingene er gitt til akkurat den applikasjonen. En annen applikasjon er en ny klient.
- **Revisjonsspor:** `api_client_changes` (`registered`, `changed`, `enabled`, `disabled`) med aktør, før og etter. Databasen nekter endring og sletting.
- Deaktivering virker ved neste kall. Kundenes tildelinger beholdes.

### Kundetildeling (punkt 2, 3, 5, 6)

- **Kundemiljø → Integrasjoner**, synlig bare for System Owner. Fanen viser godkjente applikasjoner, applikasjoner med tilgang, rollene og hva integrasjonen faktisk kan lese gjennom API-et (samme oppsummering som `GET /api/v1/me`).
- **Gi tilgang** oppretter tildelingen og integrasjonsidentiteten, og gir den rollene System Owner velger blant kundens aktive roller. **Endre roller** endrer dem. **Trekk tilbake** setter `revoked_at`, deaktiverer identiteten og beholder tildelingen som historikk. Tilgang kan gis på nytt, med en ny identitet.
- **Kontrollen ligger i tjenesten** (`ApiClientGrantService`). Den krever kundens egen aktive System Owner som handler interaktivt. Kunden er alltid System Ownerens egen, aldri en kunde fra forespørselen. En annen kundes tildeling eller rolle finnes ikke (404 eller valideringsfeil).
- **Revisjonsspor:** `customer_access_changes` med emnetypen `api_client_grant` (`api_grant_created`, `api_grant_roles_changed`, `api_grant_revoked`), med aktør, før og etter.
- **Bare godkjente applikasjoner:** en deaktivert eller ukjent applikasjon kan ikke tildeles, og en applikasjon har høyst én aktiv tildeling per kunde.

### Rettighetene kan ikke overstige lesing og kundens moduler

- **En integrasjon har bare leserettigheter.** `CustomerPermissionCatalog::integrationPermissions()` er `*.view`-nøklene. `CustomerPermissionService` og fagområdetjenestene (Risiko, Mål og KPI, Avvik) gir en integrasjon ingenting annet, uansett hvilke roller den får. Alle menneskeforbeholdte rettigheter ligger utenfor.
- **Endring fra fase 0:** før kunne en integrasjon med en rettighet som ikke er menneskeforbeholdt (for eksempel `risk.edit`, `quality.edit`, `wiki.knowledge.handoff`), skrive gjennom tjenestene. Nå kan den ikke det. 14 tester som festet den gamle regelen, er skrevet om. Skal integrasjoner senere kunne foreslå (fase 4), er det denne ene listen som utvides, bevisst.
- **Moduler:** API-ruten sjekker kundens modulpakke (`EnsureModuleIsEnabled`). En rolle med `risk.view` hos en kunde uten Risiko gir ingenting, og `/me` viser modulen som ikke bestilt.

### Integrasjonsidentiteter behandles aldri som mennesker (arkitektur §4.3)

| Krav | Hvordan |
|---|---|
| Kan ikke logge inn | `HumanUsersScope` holder identiteten utenfor all Eloquent-lesing av brukere: passord, sesjon og Entra-kobling finner den ikke. I tillegg nekter `canAccessCustomerFrontend()` og `canAccessPanel()`. |
| Vises ikke som ansatt og kan ikke velges som eier eller godkjenner | Samme scope: velgere, «Brukere», eiervalidering og relasjoner. De fire `isValidOwner()`-kontrollene nekter den også når de får raden direkte. Brukerskjermene gir 404. |
| Ingen personvarsler | `UserNotificationWriter` nekter integrasjonsmottakere. `routeNotificationForMail()` gir `null`, så ingen e-post sendes. |
| Teller ikke som lisens | Lisenstellingen teller bare `customer_admin` og `user`, og scopet utelater den uansett. |
| Ingen menneskelige beslutningsrettigheter | Bare leserettigheter (over). `isSystemOwner()`, `isQa()`, `isWikiApprover()` og hele anbudsmatrisen er usanne for en integrasjon, uansett hva raden sier. Vakten og databasetriggerne fra fase 0 gjelder fortsatt. |
| Rettighetsendringer følger kundens tilgangskontroll | Bare gjennom Kundemiljø → Integrasjoner (System Owner), registrert i `customer_access_changes`. «Brukere» når ikke identiteten. |

Bare integrasjonens egen administrasjon leser forbi scopet: `User::integrationIdentities()`, `ApiClientCustomerGrant::integrationUser()` og `ApiClientGrantService`. En strukturell test nekter alle andre steder.

### Endringer i brukeropplevelsen

- **Ny fane Kundemiljø → Integrasjoner** for System Owner, med hjelpetekst i sidens PageHelp (steg for steg). Andre roller ser ingen endring.
- **Ny side i Filament:** Kunder → API-klienter, for interne administratorer.
- Ingen andre skjermer eller arbeidsflyter er endret for personer.

### Migreringer

- `2026_10_24_000001_create_api_client_changes_table`: revisjonsspor for klienter, med trigger som nekter endring og sletting.
- `2026_10_24_000002_record_api_client_grant_changes`: tillater emnetypen `api_client_grant` i `customer_access_changes`. `down()` beholder den utvidede kontrollen så lenge slike rader finnes, som `2026_10_23_000012`.

Ingen klienter eller tildelinger er opprettet i utviklings- eller produksjonsmiljøet. E2E-specen oppretter sin egen applikasjon og rolle i det isolerte E2E-miljøet og fjerner dem etterpå.

### Tester (2026-10-24)

- **Nye PHP-tester:** `IntegrationIdentityProtectionTest` (7), `ApiClientAdministrationTest` (9), `ApiClientGrantAdministrationTest` (9). De dekker blant annet at bare interne administratorer registrerer klienter, at en System Owner bare kan tildele for egen kunde, at ingen får tilgang uten aktiv tildeling, at rettighetene ikke går ut over lesing og kundens moduler, at tilbakekalling og deaktivering stopper neste kall, at integrasjonsidentiteter ikke behandles som ansatte, at kundeseparasjonen holder når én klient har flere kunder, og at reglene holder også ved direkte tjenestekall.
- **Mutasjonssjekker** (alle fanget, og koden er gjenopprettet): scopet fjernet, den gamle rettighetsregelen gjeninnført, kontrollen fjernet i `register()`, System Owner-kravet fjernet, og kundegrensen på tildelingen fjernet.
- **Hele PHP-suiten:** 8663 tester, 3 hoppet over (Redis, fra før). To feil var testfiksturer med håndlagde SQLite-skjemaer som manglet `users.role` og tildelingstabellen. Fiksturene er rettet, og de fire berørte testfilene består (49). Suiten er ikke kjørt i sin helhet på nytt etter rettingen.
- **JS:** 2134 av 2134.
- **E2E** (isolert miljø): `customer-integrations.spec.js` (ny), Kundemiljø-specene, innlogging, navigasjon, admin-tilgjengelighet og to Risiko-reiser. Den fjerde fanen gjorde fanelinja bredere enn en telefon. Fanelinja bryter nå over flere linjer, og alle berørte specer består.

### Entra: `alg` i Microsofts nøkkelliste

**Verifisert 2026-10-24** mot Microsofts offentlige nøkkelliste (`https://login.microsoftonline.com/common/discovery/v2.0/keys` og en tenant-spesifikk liste):

- Nøklene har feltene `kty`, `use`, `kid`, `x5t`, `n`, `e`, `x5c`, `issuer` og `cloud_instance_name`. **De har ikke `alg`.** Algoritmen står bare i tokenets header (`RS256`). Microsofts dokumentasjon er ikke gjennomgått; funnet bygger på den faktiske listen.
- `firebase/php-jwt` 7.1 krever en algoritme per nøkkel. `JWK::parseKeySet($jwks)` uten standardalgoritme kaster `UnexpectedValueException: JWK must contain an "alg" parameter` på den faktiske listen. Med `JWK::parseKeySet($jwks, 'RS256')` leses alle nøklene.

**Konsekvens:** `EntraOidcClient::validateIdToken()` (vanlig innlogging med Entra) bruker `parseKeySet($jwks)` uten standardalgoritme og vil avvise **hvert** ekte ID-token. Entra-innlogging virker derfor ikke mot Microsoft i dag. Testene fanget det ikke, fordi de enten erstatter OIDC-klienten eller bruker en nøkkelliste med `alg`.

**Det krevde egen retting, og den er gjort 2026-10-24 (§17).** Opprinnelig vurdering:

- Rettingen er å oppgi `'RS256'` som standardalgoritme, slik API-et gjør. Det svekker ikke signaturvalideringen: biblioteket krever fortsatt at tokenets `alg` er lik nøkkelens, så bare RS256-signerte tokens godtas, og `none` og HMAC avvises.
- Rettingen bør komme med en test som bruker en nøkkelliste uten `alg` (som Microsofts).
- API-et påvirkes ikke. `EntraAccessTokenValidator` oppgir RS256 selv, og testene bruker allerede en nøkkelliste uten `alg`.

### Gjenstår for testing med ekte Entra-token fra Siggjo

Dette kan ikke testes uten Siggjos og Advanias registreringer:

1. **App-registrering i Entra** for «Procynia API»: App ID URI, app-rollen `Procynia.Read.All` og `accessTokenAcceptedVersion: 2` i manifestet.
2. **Konfigurasjon i staging:** `API_ENTRA_AUDIENCES` med API-appens klient-ID (v2-tokens har klient-ID-en som `aud`, ikke App ID URI-en).
3. **Siggjos identitet:** managed identity eller app-registrering i Advanias tenant, med rolletildeling `Procynia.Read.All`. Registreres i Filament med applikasjons-ID og tenant-ID.
4. **Tildeling** i Kundemiljø → Integrasjoner hos en testkunde, med en lese-rolle.
5. **Verifisere på et ekte token:** `ver` er `2.0`, `iss` er `https://login.microsoftonline.com/{tid}/v2.0`, `azp` er Siggjos applikasjons-ID, `roles` inneholder `Procynia.Read.All`, og det finnes ingen `scp`. Kontroller også `idtyp` og `azpacr`. Avklar om `azpacr = 2` skal kreves (D5).
6. **Negative prøver i staging (V4):** feil `aud`, token fra en annen tenant, fremmed app med samme rolle, deaktivert klient, tilbakekalt tildeling og `Procynia-Customer` for en kunde uten tildeling.
7. **Nøkkelrotasjon:** at en ny `kid` fra Microsoft blir godtatt innen cache-tiden (1 time).

---

## 17. Kontroll før flere endepunkter (2026-10-24)

### Rammer som gjelder

- **API fase 1 er lesebasert.** Ingen API-rute skriver, og en integrasjon har bare leserettigheter (`*.view`), både i API-et og i domenetjenestene. *(Oppdatert 2026-10-24: én navngitt skriveoperasjon finnes nå, se §20. Leserettighetene er uendret.)*
- **Avgrensede skriveoperasjoner er fortsatt et arkitekturmål.** Senere skal AI-agenter kunne registrere og foreslå, men aldri ta menneskelige beslutninger. Modellen står under. Ingenting er åpnet.
- **Ekte Entra-, Azure- og Siggjo-testing er utsatt** fordi miljøene ikke er tilgjengelige. Det som gjenstår, står i §16 («Gjenstår for testing med ekte Entra-token fra Siggjo») og i V1–V4 (§11).
- **Lokal testing fortsetter med simulerte tokens og isolerte testdata.** Testene signerer med et nøkkelpar laget i testen, nøkkellisten fra Microsoft er falsk, og E2E bruker egne data i det isolerte miljøet. Ingen test krever Entra, Azure eller produksjonsdata.

### 1. Integrasjoner og fremtidige skriverettigheter

**Hvor begrensningen ligger i dag.** Regelen «en integrasjon leser bare» ligger ett sted og leses tre steder:

| Sted | Hva |
|---|---|
| `CustomerPermissionCatalog::integrationPermissions()` | Listen over nøkler en integrasjon kan ha: de åtte `*.view`-nøklene. |
| `CustomerPermissionService::has()` / `effectivePermissions()` | Alle rettighetssjekker som går via katalogen (Kvalitet, Wiki, Etterlevelse, Leverandør, Ledelsens gjennomgang). |
| `withheldFromIntegration()` i `RiskAccessService`, `ObjectiveAccessService` og `ImprovementCaseAccessService` | Fagområdetjenestene, som leser roller direkte (`BusinessAreaGrants`). |

Anbudsmatrisen (`User::tenderMatrixAllows()`) og System Owner, QA og Wiki-godkjenner er usanne for en integrasjon uansett. Det er ikke en del av lese-regelen, og skal ikke utvides.

**Konsekvens i domenetjenestene i dag.** Alle skrivetjenester spør tilgangstjenesten med aktøren. En integrasjon nektes derfor med 403 i hver av dem, også ved direkte tjenestekall. Det er bevist i tjenestetestene som ble skrevet om i §16. Ingen jobb, kommando eller webrute skriver som en integrasjon, så det er ingen eksisterende arbeidsflyt som påvirkes. Integrasjonsidentiteter har aldri eksistert i utviklings- eller produksjonsdatabasen.

**Kan utvides kontrollert, uten å endre `HumanDecisionGuard` eller svekke kundeseparasjonen.** Det følger av at de tre lagene er uavhengige:

1. **Menneskelige beslutninger:** `HumanDecisionGuard` nekter enhver integrasjon og enhver forespørsel på API-kanalen, uavhengig av rettigheter. Databasetriggerne (`human_decision_actor`, 44 kolonner) nekter integrasjonsaktører uavhengig av applikasjonen. Ingen av dem leser `integrationPermissions()`, så en utvidelse av listen berører dem ikke.
2. **Kundeseparasjon:** aktøren er tildelingens integrasjonsidentitet, med kunden fra tildelingen. Tjenestene kontrollerer kunden selv (`canInArea`, `may()`/`authorize()` med egen kunde). En rettighet sier *hva*, aldri *hvilken kunde*.
3. **Rettighetsnøkler:** bare dette laget endres ved en utvidelse.

**Anbefalt utvidelsesmodell (ikke bygget):**

1. **To lister i katalogen:** `integrationPermissions()` (lesing, som i dag) og en ny `machineWritablePermissions()`, som er de skrivenøklene Procynia tillater for maskiner i det hele tatt. En test krever at den andre listen ikke overlapper `humanOnlyPermissions()`.
2. **Eksplisitt samtykke per tildeling.** System Owner slår på bestemte skriverettigheter for en tildeling, som et eget valg ved siden av rollene (for eksempel `api_client_customer_grants.write_permissions` eller en egen koblingstabell). Rollene ble gitt i tro på at integrasjonen bare leser, så en rolle alene skal aldri gi skriving.
3. **Effektive rettigheter** for en integrasjon = rollenes nøkler ∩ (lesenøkler ∪ (tildelingens skrivevalg ∩ `machineWritablePermissions()`)). Beregnes ett sted i `CustomerPermissionService`. De tre `withheldFromIntegration()`-metodene kaller den i stedet for å ha egen kopi.
4. **API-siden:** egen app-rolle `Procynia.Propose.All` som tak (D4). `Idempotency-Key` på alle skrivekall. `api_request_log` med påvirkede id-er (D10 sa: senest ved skrive-API). `ApiRouteContractTest` tillater `POST` bare på navngitte forslagsruter.

**Arkitekturutfordringer som må løses før skriving åpnes:**

| # | Utfordring | Hvorfor |
|---|---|---|
| S1 | **Nøklene er grove.** De ti nøklene som kunne vært aktuelle (`quality.create`, `quality.edit`, `wiki.knowledge.handoff`, `risk.create`, `risk.edit`, `objective.edit`, `improvement.edit`, `compliance.edit`, `supplier.edit`, `management_review.edit`), dekker også endring av eksisterende registrert innhold. `risk.edit` endrer for eksempel felt, status, tiltak og koblinger. | En agent som skal *foreslå*, bør ikke kunne skrive om en registrert risiko. Anbefaling: smale, formålsbygde operasjoner (avvik opprettes som «Åpen», kunnskapsoverføring som kildedokument, en generisk forslagsmodell, se arkitektur §10.1) framfor å gi eksisterende redigeringsnøkler. |
| S2 | **Visning av opphav.** 145 relasjoner til `User`, hvorav minst 38 er `created_by`/`uploaded_by`/`changed_by`, går gjennom `HumanUsersScope`. En post opprettet av en integrasjon vil vise opphavet som tomt. | Opphavet må vises som «Integrasjon: …» der historikk vises. Det krever en egen visningsregel eller en uscopet relasjon på de feltene som kan få en integrasjon som aktør. Det er ingen feil i dag, fordi ingen integrasjon skriver. |
| S3 | **Tidligere produktbeslutninger:** B6 (ingen agentopplasting av kildedokumenter) og B7 (ingen maskinregistrerte KPI-målinger). | Må tas opp igjen eksplisitt dersom slike operasjoner skal tillates. |
| S4 | **AI-kostnad.** D11 nekter AI på API-kanalen. En forslagsoperasjon som starter AI-arbeid i en køjobb (for eksempel en Wiki-kjøring etter kunnskapsoverføring), kjører utenfor kanalen. | Det må besluttes om agentutløst AI-arbeid er tillatt, og hvordan kostnaden henføres og begrenses. |
| S5 | **Samtidighet og konflikter.** Kvalitet krever `base_hash` (A22, A32). Andre moduler bruker radlås. | Skriveendepunktene må gi 409 med felles feilformat (A10 dekker formatet). |
| S6 | **Varsler.** En integrasjon kan aldri være eier eller mottaker. Den kan likevel utløse varsler til personer, for eksempel ved å sette en person som eier. | Bør være et bevisst valg per operasjon. |

### 2. Entra-innlogging rettet

- **Feilen:** Microsofts nøkkelliste har ikke `alg` (§16). `EntraOidcClient::validateIdToken()` leste nøklene uten standardalgoritme, og hver ekte innlogging med Entra feilet.
- **Rettingen:** tokenets header må oppgi `RS256` før noen nøkkel hentes, og nøklene leses som RS256-nøkler (`JWK::parseKeySet($jwks, 'RS256')`). Biblioteket krever fortsatt at tokenets algoritme er lik nøkkelens, så `none`, HMAC og RS512 avvises. Kontrollene av signatur, issuer (som også låser v2-endepunktet), audience, tenant, nonce og utløp er uendret.
- **Regresjonstest:** `EntraIdTokenValidationTest` bruker en nøkkelliste formet som Microsofts (`kty`, `use`, `kid`, `x5t`, `n`, `e`, `x5c`, `issuer`, `cloud_instance_name`, ingen `alg`) og to nøkler. Den dekker gyldig token, `none`, HS256, RS512 og uleselig token (avvist før nøkler hentes), fremmed nøkkel, ukjent `kid`, feil nonce, audience, issuer, v1-issuer og tenant, utløpt, ikke ennå gyldig, manglende utløp, og en nøkkel som oppgir en annen algoritme. Testen feiler uten rettingen.
- **Gjenstår:** verifisering mot et ekte Entra-token. Den krever en registrert tenant og er utsatt (§16).

### 3. Kontroll av integrasjonsidentitetene

| Område | Resultat |
|---|---|
| Historiske beslutninger og aktørreferanser | Uendret. Ingen integrasjonsidentitet har eksistert utenfor tester, og en integrasjon kan ikke skrive eller beslutte, så ingen historikk peker på en. Beslutningskolonnene er beskyttet av databasetriggerne. Opphavsvisning ved fremtidig skriving er S2 over. |
| Tilgangs- og revisjonslogger | `CustomerAccessChangeRecorder::userSnapshot()` bruker `fresh()`, som går forbi scopet. Tildelingene logges med aktør (System Owner) og integrasjonens id i øyeblikksbildet. `api_client_changes` og `customer_access_changes` er uendret og beskyttet. Testet. |
| Kundeseparasjon | Scopet legger bare til `role != 'integration'`. Kunden kommer fortsatt fra tildelingen og kontrolleres i tjenestene. Alle tester for kundeseparasjon består. |
| Tjenester som må gjenkjenne en integrasjon | `HumanDecisionGuard` og `CustomerPermissionService` får brukerobjektet direkte. Tildelingens relasjon og `User::integrationIdentities()` går forbi scopet med vilje. Køjobber gjenoppretter modeller uten globale scopes. Testet. |
| Kundesletting | Ingen applikasjonsvei sletter kunder. Ved sletting i databasen setter `users.customer_id` seg til `NULL` for alle brukere, også personer, og tildelingene slettes med kunden. En integrasjon uten tildeling kan ikke autentisere. Uendret oppførsel, ingen retting. |
| **Funnet feil** | `UniqueUserEmail` leste brukere gjennom scopet, så en integrasjons adresse så ledig ut. En kollisjon ville gitt databasefeil (500) i stedet for valideringsfeil. **Rettet**: unikhet gjelder hele tabellen, og regelen står på listen i den strukturelle testen. |

### 4. Regresjonstesting

**PHP, hele suiten**, kjørt på `609f72f6`, etter rettingen av SQLite-fiksturene, Entra-rettingen og rettingen av e-postunikheten. Kommando: `docker exec procynia-app php -d memory_limit=-1 vendor/bin/phpunit`.

- **8667 tester, 56 718 assertions: 0 feilet, 0 feil (errors), 3 hoppet over.** Tid 38:38.
- **Hoppet over, ikke verifisert:** de tre kjente testene i `RedisRuntimeContractTest` (`test_cache_and_queue_coexist_on_database_zero_without_colliding`, `test_a_real_job_pushed_to_redis_can_be_popped_back`, `test_session_can_be_driven_by_redis`). Årsak: «NOAUTH Authentication required». Testen kobler til den lokale Redis-en uten passord. Uendret fra fase 0, og fortsatt V1 (§11).
- **Ingen andre feil eller hoppede tester.**

**Tilleggstester for Entra:** `EntraIdTokenValidationTest` (4 tester, 19 assertions), `EntraAuthenticationTest` og hele `tests/Feature/Api` består. Mutasjonssjekk: uten standardalgoritmen feiler regresjonstesten.

**Kontrollen av integrasjonsidentitetene:** `IntegrationIdentityProtectionTest` har nå 9 tester. Mutasjonssjekk: uten rettingen i `UniqueUserEmail` feiler testen for adressen.

**JS:** ikke kjørt på nytt. Ingen frontendkode er endret siden forrige kjøring (2134 av 2134).

**E2E**, i det isolerte miljøet: `auth.spec.js`, `roles-on-user-edit.spec.js` og `customer-integrations.spec.js`, 8 av 8. De berøres av innloggingen og av e-postvalideringen ved redigering av brukere.

### Endringer i brukeropplevelsen

- **Innlogging med Entra virker nå mot Microsofts faktiske nøkler.** Før feilet den alltid ved validering av ID-tokenet. Det er ennå ikke verifisert mot en ekte tenant. Innlogging med passord er uendret.
- Ingen andre synlige endringer.

---

## 18. `GET /api/v1/deviations` – avvik (2026-10-24)

### Kartlegging av Avvik og forbedringer

| Spørsmål | Svar i koden |
|---|---|
| Kundeseparasjon | `ImprovementCaseAccessService::visibleCases()` filtrerer på aktørens `customer_id` og fagområdene aktøren når. En sak utenfor er fraværende (404 eller ikke listet), aldri «forbudt». |
| Lesetilgang | `improvement.view` på en aktiv kunderolle, i rollens fagområder (eksplisitte eller «Alle»), via `BusinessAreaGrants`. System Owner har ingen implisitte fagområder. En integrasjon har bare lesenøkler (§16). |
| Typer | Én modell for avvik og forbedringer (`ImprovementCase::type`: `deviation`, `improvement`). Endepunktet gir **bare avvik**. |
| Status | `open`, `in_progress`, `closed`, `cancelled`. Endres bare gjennom `ImprovementCaseLifecycleService`, med historikk i `improvement_case_status_changes`. |
| Alvorlighetsgrad | **Finnes ikke** i modulen. Ingen kolonne og ingen regel, så API-et viser ingen. |
| Frister | `due_date` (frist) og `occurred_at` (hendelsesdato, bare for avvik). Fristen er passert fra dagen etter, og bare for aktive saker. |
| Oppfølging | Tiltak (`ImprovementAction`, med status og frist) og effektverifisering. `ImprovementAttentionService` har seks faste regler for «Trenger oppmerksomhet». |
| Gjenbrukt | `visibleCases()`, `canOpenModule()`, `ImprovementAttentionService::findingsForCases()`, tiltakstellingen fra registeret (`withCount`) og modulsperren (`route_modules`). |

### Tilgangskrav

1. Gyldig applikasjonstoken med `Procynia.Read.All` (§4).
2. Aktiv tildeling for kunden i `Procynia-Customer` (§4, D6).
3. Kunden har modulen Avvik og forbedringer (`api.v1.deviations.` → `improvements`). Den inngår i Basis.
4. Integrasjonsidentiteten har `improvement.view` gjennom en aktiv rolle. Listen omfatter bare rollens fagområder.

Mangler 1 gir 401. Mangler 2–4 gir 403 i felles feilformat (A10). En annen kundes data finnes ikke.

### Forespørsel

```text
GET /api/v1/deviations?status=open&business_area_id=7&overdue=true&limit=50&cursor=…
Authorization: Bearer <Entra-token>
Procynia-Customer: 42
```

| Parameter | Verdier | Merknad |
|---|---|---|
| `status` | `open`, `in_progress`, `closed`, `cancelled` | Valgfri |
| `business_area_id` | heltall | Smalner bare inn. Et fagområde utenfor tilgangen, eller fra en annen kunde, gir tom liste. |
| `overdue` | `true`, `false` | `true`: aktiv sak med frist før dagens dato, samme regel som «Trenger oppmerksomhet». |
| `limit` | 1–200, standard 50 | |
| `cursor` | `meta.next_cursor` fra forrige side | Opak. Bærer bare siste id, aldri kunde eller filtre. |

Sortering: stigende `id`, stabil for markørsidene. Ugyldige verdier gir 422 `validation_failed` med feltnavnet.

### Svar

```json
{
  "data": [
    {
      "id": 1093,
      "title": "Feil i rutine for tilgangskontroll",
      "status": "open",
      "business_area": { "id": 7, "name": "Drift" },
      "owner": { "id": 233, "name": "Eva Eier" },
      "occurred_on": "2026-10-04",
      "due_on": "2026-10-22",
      "is_overdue": true,
      "closed_at": null,
      "follow_up": {
        "actions_total": 2,
        "actions_open": 2,
        "attention": ["case_overdue", "action_overdue"]
      },
      "created_at": "2026-10-04T08:12:00Z",
      "updated_at": "2026-10-20T13:40:11Z"
    }
  ],
  "meta": { "as_of": "2026-10-24T07:00:00Z", "request_id": "01JB…", "limit": 50, "next_cursor": null }
}
```

| Felt | Innhold |
|---|---|
| `id`, `title`, `status` | Saken. Tittelen er kort og registrert som overskrift. |
| `business_area` | Fagområdet (id og navn). |
| `owner` | Ansvarlig (id og navn), eller `null`. Som for Risiko (D8). |
| `occurred_on`, `due_on` | Hendelsesdato og frist (dato), eller `null`. |
| `is_overdue` | Fristen er passert og saken er aktiv. |
| `closed_at` | Når saken ble lukket eller avbrutt (UTC), eller `null`. |
| `follow_up.actions_total`, `actions_open` | Antall tiltak, og antall som er planlagt eller under arbeid. |
| `follow_up.attention` | Koder fra «Trenger oppmerksomhet» for saken og tiltakene: `case_overdue`, `action_overdue`, `case_owner_missing`, `action_owner_missing`, `awaiting_verification`, `not_effective`. Tom for avsluttede saker. |
| `created_at`, `updated_at` | Tidspunkter (UTC). |

**Ikke med:** beskrivelse, årsaksanalyse, avslutningsnotat, tiltakenes tekst, effektvurderingens tekst, hvem som meldte og hvem som lukket saken, koblinger, dokumenter og webruter. Forbedringer er ikke med.

### Andre endringer

- `GET /api/v1/me` og Kundemiljø → Integrasjoner viser nå også `deviations` (bestilt, lesbar, fagområder) i tilgangsoppsummeringen. Dette er et tillegg i `v1`, ikke et brudd.
- Markøren er felles for listene (`ApiCursor`, trukket ut av Risiko-endepunktet uten endret oppførsel).
- Ingen migreringer.

### Sikkerhet og tester

- `ApiDeviationReadTest` har 11 tester med simulerte Entra-tokens og syntetiske data. De dekker strukturerte felt og fravær av fritekst og personer utover ansvarlig, at bare avvik vises, avsluttet sak, fagområder, én applikasjon med flere kunder (filter, markør og kunde uten tildeling), manglende `improvement.view` og manglende modul (403), filtre, sider, ugyldige verdier (422), ingen skrive- eller beslutningsruter, ingen utgående kall bortsett fra nøkkellisten, og logglinjen.
- **Mutasjonssjekker**, alle fanget: kundegrensen fjernet (spørring uten `visibleCases`), kravet om `improvement.view` fjernet, avviksfilteret fjernet og modulkartet fjernet.
- **Kjørt:**
  - PHP: hele `tests/Feature/Api`, alle `Improvement*`-testene, `IntegrationIdentityProtectionTest`, `CustomerEnvironmentPageTest`, `CrossTenantIsolationTest`, `NavigationEntitlementMatrixTest` og `CustomerModuleEntitlementTest`, til sammen 291 bestått.
  - JS: 2134 av 2134.
  - E2E: `customer-integrations.spec.js` og `auth.spec.js`, 7 av 7.
- **Ikke kjørt:** hele PHP-suiten. Endringen er et nytt leseendepunkt og et tillegg i tilgangsoppsummeringen, og de relevante testene over dekker det.
- **Gjenstår fortsatt:** ekte Entra-, Azure- og Siggjo-testing (§16, §17). Lokal testing fortsetter med simulerte tokens og isolerte data.

---

## 19. `GET /api/v1/objectives` og `GET /api/v1/kpis` – Mål og KPI (2026-10-24)

### Beslutning: forbedringer får et eget endepunkt senere

`GET /api/v1/deviations` gir fortsatt **bare avvik** (§18). Forbedringsforslag skal senere få et eget `GET /api/v1/improvements`, på samme modell (`ImprovementCase` med `type = improvement`) og samme tilgangskontroll (`ImprovementCaseAccessService::visibleCases()`, `improvement.view`, fagområder, modulen `improvements`). Det er **besluttet, men ikke bygget**. Når det bygges, gjelder samme feltvalg og samme vurdering av personopplysninger som for avvik (se under).

### Kartlegging av Mål og KPI

| Spørsmål | Svar i koden |
|---|---|
| Mål | `objectives`: tittel, beskrivelse, fagområde (påkrevd), ansvarlig (`owner_user_id`, kan bli `null` når brukeren slettes), måldato (valgfri), status og avslutning (`closed_at`, `closed_by_user_id`, `closing_note`). Historikk i `objective_status_changes`. |
| Målstatus | `active`, `achieved` (Nådd), `not_achieved` (Ikke nådd), `cancelled` (Avbrutt). Endres bare gjennom `ObjectiveLifecycleService`. |
| KPI og tilknytning | `kpis`: hører til nøyaktig ett mål (`objective_id`, sammensatt fremmednøkkel med kunden). En KPI har **ikke eget fagområde**: den arver målets. |
| KPI-status | `active`, `retired` (Avsluttet). Historikk i `kpi_status_changes`. |
| Ansvar | KPI-ens egen ansvarlig er valgfri. Uten den svarer målets ansvarlige for KPI-en (`Kpi::responsible()`), som presentasjon, aldri lagret. |
| Målverdi | `target_min`, `target_max`, `tolerance` (eksakte desimaler). Måltypen (`at_least`, `at_most`, `range`, `exact`) lagres ikke, den avledes av grensene (`KpiTarget::type()`). Startverdi (`baseline_value`, `baseline_date`) når målverdien er satt som endring. |
| Enhet | `percent`, `percent_change`, `count`, `number`, `currency` (med `currency_code`), `hours`, `days`. `unit_label` bare for antall og tall. |
| Målinger og perioder | `kpi_measurements`: én rad per registrering, med periode (`period_start`, `period_end`), verdi og et øyeblikksbilde av målverdien. Retting = ny rad for samme periode. Tilbaketrekking med begrunnelse. Hvilken måling som teller, avgjøres bare av `KpiMeasurementResolver`. Frekvens: `weekly`, `monthly`, `quarterly`, `yearly` eller ingen, med `reporting_grace_days`. |
| Måloppnåelse | `KpiTargetPolicy`: `on_target`, `attention`, `off_target`. Uten måling: `not_measured` (`KpiPresenter::NOT_MEASURED`). Registerets «2 av 3 KPI-er på mål» er `KpiPresenter::indicator()` over aktive KPI-er. |
| Oppmerksomhet | `ObjectiveAttentionService`, fire regler: `kpi_off_target`, `measurement_missing` (KPI), `target_date_passed`, `owner_missing` (mål). Bare aktive KPI-er under aktive mål. Et KPI-funn gjør aldri målet til et funn. |
| Lesetilgang | `objective.view` på en aktiv kunderolle, i rollens fagområder (eksplisitte eller «Alle»), via `BusinessAreaGrants`. `ObjectiveAccessService::visibleObjectives()` og `visibleKpis()` er eneste inngang. System Owner har ingen implisitte fagområder. En integrasjon har bare `objective.view` (§16): `objective.measure` og `objective.delete` holdes alltid tilbake. |
| Modul | `objectives`, i pakkene `governance`, `iso` og `grc`. |

### Datamodellvalg: målinger i KPI-listen

Målingene returneres **i hver KPI-rad**, ikke gjennom et eget endepunkt:

- `latest_measurement`: den siste målingen som teller.
- `measurements`: den gjeldende målingen for **de siste 24 målte periodene**, eldste først. Det er det samme vinduet som «Utvikling over tid» på KPI-siden.

Begrunnelse: et dashboard får mål, KPI-er, siste verdi, resultat og en tidsserie med to kall og én markør per liste. Ingen nestet paginering. Svaret er avgrenset: høyst 200 KPI-er × 24 punkter per side.

Ikke med: rettede (erstattede) og tilbaketrukne rader, kommentarer, hvem som registrerte eller trakk tilbake og begrunnelsen for tilbaketrekking. Eldre perioder enn de 24 siste er ikke tilgjengelige i `v1`. Trengs hele historikken, er `GET /api/v1/kpis/{id}/measurements` (§7) en utvidelse uten brudd.

Det beregnes ingen nye verdier og innføres ingen nye klassifiseringer. Alt kommer fra domeneklassene over.

### Tilgangskrav

Samme for begge endepunkter:

1. Gyldig applikasjonstoken med `Procynia.Read.All` (§4).
2. Aktiv tildeling for kunden i `Procynia-Customer` (§4, D6).
3. Kunden har modulen Mål og KPI (`api.v1.objectives.` og `api.v1.kpis.` → `objectives`).
4. Integrasjonsidentiteten har `objective.view` gjennom en aktiv rolle. Listene omfatter bare rollens fagområder. En KPI vises bare når målet vises.

Mangler 1 gir 401. Mangler 2–4 gir 403 i felles feilformat (A10). En annen kundes data finnes ikke.

### `GET /api/v1/objectives`

```text
GET /api/v1/objectives?status=active&business_area_id=7&limit=50&cursor=…
Authorization: Bearer <Entra-token>
Procynia-Customer: 42
```

| Parameter | Verdier | Merknad |
|---|---|---|
| `status` | `active`, `achieved`, `not_achieved`, `cancelled` | Valgfri |
| `business_area_id` | heltall | Smalner bare inn. Et fagområde utenfor tilgangen, eller fra en annen kunde, gir tom liste. |
| `limit` | 1–200, standard 50 | |
| `cursor` | `meta.next_cursor` fra forrige side | Opak. Bærer bare siste id (`ApiCursor`). |

```json
{
  "data": [
    {
      "id": 311,
      "title": "Økt kundetilfredshet",
      "status": "active",
      "business_area": { "id": 7, "name": "Salg" },
      "owner": { "id": 233, "name": "Eva Eier" },
      "target_date": "2026-12-31",
      "closed_at": null,
      "kpis": { "total": 3, "active": 2, "on_target": 1, "needing_attention": 1 },
      "attention": [],
      "created_at": "2026-10-05T08:12:00Z",
      "updated_at": "2026-10-14T13:40:11Z"
    }
  ],
  "meta": { "as_of": "2026-10-24T07:00:00Z", "request_id": "01JB…", "limit": 50, "next_cursor": null }
}
```

| Felt | Innhold |
|---|---|
| `id`, `title`, `status` | Målet. |
| `business_area` | Fagområdet (id og navn). |
| `owner` | Ansvarlig (id og navn), eller `null`. |
| `target_date` | Måldato, eller `null` for et løpende mål. |
| `closed_at` | Når målet ble lukket (UTC), eller `null`. |
| `kpis.total` | Alle KPI-er under målet, avsluttede medregnet. |
| `kpis.active`, `kpis.on_target` | Registerets «N av M KPI-er på mål»: aktive KPI-er, og hvor mange av dem som har siste måling på mål mot dagens målverdi. |
| `kpis.needing_attention` | Antall KPI-er med funn i «Trenger oppmerksomhet». |
| `attention` | Målets egne koder: `target_date_passed`, `owner_missing`. Tom for lukkede mål. |
| `created_at`, `updated_at` | Tidspunkter (UTC). |

**Ikke med:** beskrivelse, avslutningsnotat, hvem som lukket målet, statushistorikken, prosesskontekst og webruter.

### `GET /api/v1/kpis`

```text
GET /api/v1/kpis?objective_id=311&status=active&business_area_id=7&limit=50&cursor=…
```

| Parameter | Verdier | Merknad |
|---|---|---|
| `objective_id` | heltall | Smalner inn til ett mål. Et mål utenfor tilgangen gir tom liste. |
| `status` | `active`, `retired` | Valgfri |
| `business_area_id` | heltall | Målets fagområde. Smalner bare inn. |
| `limit`, `cursor` | Som over | Sortering: stigende `id`. |

```json
{
  "data": [
    {
      "id": 902,
      "objective_id": 311,
      "business_area": { "id": 7, "name": "Salg" },
      "title": "Oppetid kundeportal",
      "status": "active",
      "owner": { "id": 233, "name": "Eva Eier", "inherited": true },
      "unit": "percent",
      "unit_label": null,
      "currency_code": null,
      "target": { "type": "at_least", "min": "95", "max": null, "tolerance": "2" },
      "baseline": null,
      "frequency": "monthly",
      "reporting_grace_days": 7,
      "latest_measurement": {
        "period_start": "2026-08-01", "period_end": "2026-08-31",
        "value": "94", "recorded_at": "2026-09-03T09:00:00Z"
      },
      "result": "attention",
      "measurements": [
        { "period_start": "2026-07-01", "period_end": "2026-07-31", "value": "90", "recorded_at": "2026-08-02T10:00:00Z", "result": "on_target" },
        { "period_start": "2026-08-01", "period_end": "2026-08-31", "value": "94", "recorded_at": "2026-09-03T09:00:00Z", "result": "on_target" }
      ],
      "attention": ["kpi_off_target", "measurement_missing"],
      "created_at": "2026-07-01T00:00:00Z",
      "updated_at": "2026-10-14T13:40:11Z"
    }
  ],
  "meta": { "as_of": "2026-10-24T07:00:00Z", "request_id": "01JB…", "limit": 50, "next_cursor": null }
}
```

| Felt | Innhold |
|---|---|
| `objective_id`, `business_area` | Målet KPI-en hører til, og målets fagområde. |
| `owner` | Ansvarlig. `inherited: true` når KPI-en ikke har egen ansvarlig og målets ansvarlige svarer for den. `null` når ingen er satt. |
| `unit`, `unit_label`, `currency_code` | Enheten som lagret. |
| `target` | Måltype og grenser som eksakte desimalstrenger uten etterfølgende nuller. `tolerance` er rommet utenfor målet. |
| `baseline` | Startverdi og dato når målverdien er satt som endring, ellers `null`. Endringsprosenten er ikke med, den kan avledes. |
| `frequency`, `reporting_grace_days` | Målerytme og frist etter periodeslutt. `frequency: null` betyr ingen fast rytme. |
| `latest_measurement` | Siste måling som teller, eller `null`. |
| `result` | Siste måling mot **dagens** målverdi: `on_target`, `attention`, `off_target` eller `not_measured`. Samme som KPI-siden. |
| `measurements[].result` | Hver periode mot målverdien **som gjaldt da den ble registrert**, som i Målehistorikk. Er målverdien skjerpet siden, kan samme verdi være `on_target` her og `attention` i `result`. Begge er riktige. |
| `attention` | `kpi_off_target`, `measurement_missing`. Bare for aktive KPI-er under aktive mål. |

Tall er strenger med eksakte desimaler, aldri flyttall og aldri formatert for et språk.

**Ikke med:** beskrivelse, målingskommentarer, hvem som registrerte eller trakk tilbake, begrunnelse for tilbaketrekking, erstattede og tilbaketrukne rader, prosess- og aktivitetskoblinger og webruter.

### Personopplysninger og fritekst

Vurderingen gjelder Mål og KPI, og også de eksisterende endepunktene for Risiko og Avvik. Ingen av dem er bygget om nå.

| Felt | Personopplysning? | Vurdering |
|---|---|---|
| Ansvarlig (`owner.id`, `owner.name`) | **Ja.** Navn på en ansatt i arbeidsrollen. | Nødvendig for formålet («hvem eier dette»). D8 tillater eier. Ingen e-post, ingen andre personer. Personer som registrerte, meldte, lukket eller trakk tilbake er utelatt i alle tre modulene. |
| Titler (`title`) | **Kan inneholde.** Fritekst, kort, skrevet av kunden. | Mål og KPI: lav sannsynlighet («Økt kundetilfredshet»). Risiko: middels («Nøkkelperson slutter»). **Avvik: høyest.** En avvikstittel kan beskrive en hendelse med en navngitt person, og i verste fall helseopplysninger («Ansatt skadet i trapp»), som er en særlig kategori (personvernforordningen art. 9). Procynia kan ikke avgjøre teknisk om en tittel inneholder personopplysninger. |
| `unit_label` | Svært lite sannsynlig | Kort enhetsnavn («saker»). |
| Måleverdier | **Indirekte, i små enheter.** | Et tall er normalt aggregert. En KPI om for eksempel sykefravær i et team på tre kan likevel peke på enkeltpersoner. |
| Fagområdenavn | Normalt ikke | Organisasjonsbegrep. |
| Integrasjonsidentitetens navn (`/me`) | Nei | Navnet på integrasjonen, ikke en person. |

**Før reelle kundedata brukes:**

1. Databehandleravtalen med Siggjo skal behandle **ansvarliges navn og titler som personopplysninger**, og titler i Avvik som mulig bærer av særlige kategorier.
2. Kunden skal informeres om at titler og ansvarlige sendes til integrasjonen, og få en kort skriveveiledning: titler beskriver saken, ikke personen. Det gjelder særlig Avvik.
3. DPIA etter D14 dekker titler og navn, ikke bare fritekstfeltene som er utelatt.
4. Krever en kunde det, er neste steg en innstilling per tildeling som utelater titler og navn (`title` og `owner.name` blir `null`, id-ene beholdes). Den er **ikke bygget**, og innføres ikke uten et konkret behov.

### Andre endringer

- `GET /api/v1/me` og Kundemiljø → Integrasjoner viser nå også `objectives` (bestilt, lesbar, fagområder). Linjen heter «Mål og KPI». Dette er et tillegg i `v1`, ikke et brudd.
- `ApiRouteContractTest` tjener nå også modulen `objectives`.
- **Ingen migreringer.**

### Endringer i brukeropplevelsen

- Kundemiljø → Integrasjoner viser «Mål og KPI: <fagområder>» for en integrasjon som har `objective.view`. Ingen andre synlige endringer. Mål og KPI-sidene er uendret.

### Sikkerhet og tester

- `ApiObjectiveKpiReadTest` har 13 tester med simulerte Entra-tokens og syntetiske data. De dekker:
  - strukturerte felt og fravær av fritekst og andre personer enn ansvarlig;
  - lukket mål;
  - målinger: rettet måling, tilbaketrukket måling, resultat nå og ved registrering, oppmerksomhetskoder, og vinduet på 24 perioder;
  - arvet og egen ansvarlig, og startverdi;
  - fagområder, én applikasjon med flere kunder (filter, mål-id, markør og kunde uten tildeling) og `/me`;
  - manglende `objective.view` og manglende modul (403);
  - filtre, sider og ugyldige verdier (422);
  - ingen skrive-, målings- eller beslutningsruter, ingen utgående kall bortsett fra nøkkellisten, og logglinjen.
- **Mutasjonssjekker**, alle fanget: målene hentet uten `visibleObjectives`, KPI-ene hentet uten `visibleKpis`, kravet om `objective.view` fjernet, modulkartet fjernet, historikk vurdert mot dagens målverdi i stedet for øyeblikksbildet, og vinduet på 24 perioder fjernet.
- **Kjørt:**
  - PHP: hele `tests/Feature/Api` (109), alle `Objective*`- og `Kpi*`-testene i `tests/Feature/App`, `tests/Unit/Objectives`, `ObjectivePageHelpTranslationsTest`, `IntegrationIdentityProtectionTest`, `CustomerEnvironmentPageTest`, `CrossTenantIsolationTest`, `NavigationEntitlementMatrixTest` og `CustomerModuleEntitlementTest` (448). Til sammen 557 bestått.
  - JS: hele suiten, 2135 av 2135. Én ny test for etiketten.
  - E2E: `customer-integrations.spec.js` og `auth.spec.js`, 7 av 7.
- **Ikke kjørt:** hele PHP-suiten. Endringen er to nye leseendepunkter og et tillegg i tilgangsoppsummeringen, og de relevante testene over dekker det. Siste fulle kjøring er fortsatt §17 (8667 tester, 0 feilet).

### Begrensninger og utsatt testing

- Bare lister. `GET /objectives/{id}` og `GET /kpis/{id}/measurements` (§7) er ikke bygget.
- Målinger eldre enn de 24 siste periodene er ikke tilgjengelige.
- `result` og oppmerksomhet beregnes ved lesing, med dagens dato på serveren. Et øyeblikksbilde hos Siggjo er riktig per `meta.as_of`.
- Prosess- og aktivitetskoblinger (Kvalitet) er ikke med.
- **Utsatt:** ekte Entra-, Azure- og Siggjo-testing (§16, §17). Lokal testing fortsetter med simulerte tokens og isolerte data.

---

## 20. Første skriveoperasjon: risikotiltak (2026-10-24)

Besluttet av brukeren 2026-10-24. Den enkleste sikre løsningen: en ekstern AI-agent kan lese som før og utføre én konkret skriveoperasjon gjennom en eksisterende domenetjeneste. Denne seksjonen erstatter anbefalingene i §17 punkt 1 der de er forskjellige: ingen `machineWritablePermissions()`, ingen `Idempotency-Key`, ingen `api_request_log`, og app-rollen heter `Procynia.Write.All`, ikke `Procynia.Propose.All`.

### Modellen for operasjonstillatelser (generell)

En integrasjon kan skrive bare gjennom en **navngitt operasjon**. Tre uavhengige betingelser må alle være oppfylt:

| # | Betingelse | Hvor | Hvem bestemmer |
|---|---|---|---|
| 1 | Tokenet har app-rollen `Procynia.Write.All` (i tillegg til `Procynia.Read.All`) | `EntraAccessTokenValidator` → `ApiRequestContext::$writeRole`; `api.write` (`RequireApiWriteRole`) på skriveruter | Kundens Entra-administrator (tak, D4) |
| 2 | Operasjonen er slått på for kundetildelingen | `api_client_customer_grants.allowed_operations` (jsonb, standard `[]`) | Kundens System Owner, Kundemiljø → Integrasjoner |
| 3 | Domenets egne regler for posten | Domenets tilgangstjeneste (her `RiskAccessService`) og domenetjenesten | Kundens roller og fagområder, forretningsreglene |

- **Katalogen** `App\Support\ApiOperationCatalog` er lukket. Den inneholder bare `risk.treatment_action.create`, med modulen (`risk`) og etiketten. En ny operasjon er en ny rad der, domenesjekken som spør etter den, og etiketten. Ingen ny tabell, ingen nye roller.
- **Kontrollen** `App\Services\Api\IntegrationOperationAuthority::allows($actor, $operation)` spørres fra domenets tilgangstjeneste, ikke fra kontrolleren. Den leser alt på nytt og nekter når én betingelse mangler: aktiv integrasjonsidentitet; operasjonen finnes i katalogen; forespørselen kom gjennom API-et som denne identiteten med skriverollen; tildelingen er aktiv, for egen kunde, og klienten er aktivert og er den som kaller; operasjonen er slått på; kunden er aktiv og har modulen. **Utenfor en API-forespørsel skriver en integrasjon ingenting**, også ved direkte kall til domenetjenesten.
- **Roller gir aldri en operasjon.** Rollene ble valgt for lesing. `CustomerPermissionCatalog::integrationPermissions()` er uendret (bare `*.view`), så en integrasjon har fortsatt aldri `risk.edit` eller en annen skrivenøkkel, uansett roller.
- **Skillet mot ordinære brukere.** En person får `false` fra `IntegrationOperationAuthority` og bruker sine vanlige rettigheter, som kontrollen ikke leser. Ingen regel for personer er endret.
- **Menneskelige beslutninger** er uendret: `HumanDecisionGuard` nekter fortsatt enhver integrasjon og enhver forespørsel på API-kanalen, og databasetriggerne (`human_decision_actor`) står. Ingen operasjon kan være en menneskelig beslutning.

### Administrasjon

- `ApiClientGrantService::updateOperations()` erstatter hele listen. Bare kundens egen System Owner, interaktivt, på egen kundes aktive tildeling (samme regel som roller og tilbaketrekking). En verdi utenfor katalogen avviser hele endringen (422). Uendret valg registrerer ingenting.
- Hver endring er en rad i `customer_access_changes` med `change_type = api_grant_operations_changed`, aktør og før/etter (`allowed_operations` er nå med i øyeblikksbildet av tildelingen).
- Kundemiljø → Integrasjoner viser under hver tildeling «Kan registrere gjennom API-et» med avkrysningen **Tillat opprettelse av risikotiltak**. Den lagres med en gang (`PUT /app/customer-environment/integrations/{grantId}/operations`). Innledningen og «Slik gjør du det» er oppdatert (no/en).

### `POST /api/v1/risks/{riskId}/treatment-actions`

Kontrolleren (`Api\V1\RiskTreatmentActionController`) gjør bare dette: finner risikoen med `RiskAccessService::findVisible()` (annen kunde, annet fagområde eller ukjent id gir 404), spør `canCreateTreatmentAction()` (403), validerer med de samme reglene som risikosiden (`RiskTreatmentService::rules()`), kaller `RiskTreatmentService::create()` og svarer.

**Tilgang.** `RiskAccessService::canCreateTreatmentAction()`:
- person: `risk.edit` i risikoens fagområde, som før;
- integrasjon: `risk.view` i risikoens fagområde **og** `IntegrationOperationAuthority::allows(..., 'risk.treatment_action.create')`.

`RiskTreatmentService::create()` spør `assertCanCreateTreatmentAction()` selv. `update()`, `complete()` og `reopen()` spør fortsatt `assertCanEdit()`, som en integrasjon aldri består. Det samme gjelder `RiskCreator::update()`, `RiskLifecycleService::delete()`, vurdering og aksept (de to siste også `HumanDecisionGuard`).

**Forespørsel** (JSON). Bare disse feltene leses; andre felt ignoreres:

| Felt | Regel |
|---|---|
| `title` | påkrevd, tekst, maks 255 |
| `owner_user_id` | påkrevd, heltall; aktiv person i risikoens kunde som kan se risikoen (samme kontroll som risikosiden) |
| `due_at` | påkrevd, `YYYY-MM-DD` |

**Svar** `201 Created`:

```json
{
  "data": {
    "id": 812,
    "risk_id": 41,
    "title": "Reservestrøm i serverrommet",
    "status": "open",
    "owner": { "id": 17, "name": "Eva Eier" },
    "due_at": "2026-12-01",
    "created_at": "2026-10-24T09:12:03Z"
  },
  "meta": { "as_of": "2026-10-24T09:12:03Z", "request_id": "…" }
}
```

Feil i felles format (A10): 401 (token), 403 (manglende skriverolle, operasjon ikke tillatt, tilbakekalt tildeling), 404 (risiko ikke synlig), 422 (`error.fields`, f.eks. `owner_user_id`).

**Forretningsregler, uendret.** Tiltaket er et faktisk, åpent tiltak (`status = open`), ikke et utkast. `customer_id` er risikoens. `created_by` og `updated_by` er integrasjonsidentiteten. Ansvarlig valideres som på risikosiden, og varselet «Du er ansvarlig for tiltaket …» sendes som før (`AssignmentNotifier`, med `assigned_by_user_id` = integrasjonsidentiteten). Tiltak kan opprettes på en lukket risiko, som på risikosiden. En risiko med tiltak beskyttes mot sletting som før (B3). Risikoens felt, status, vurderinger og aksept endres ikke.

**Opphav.** Risikosiden viser «Registrert av integrasjonen <applikasjon>» ved et tiltak som er opprettet av en integrasjon. Navnet leses fra tildelingen (`api_client_customer_grants` → `api_clients.name`) ved hjelp av `created_by`; brukertabellen leses ikke forbi `HumanUsersScope`. Ingen ny opphavstjeneste. Andre moduler er uendret.

**Logging.** Den eksisterende logglinjen per forespørsel (D10) har klient, kunde, aktør, rute, metode, status og `rows = 1`. En nektet skriving uten skriverollen logges som `api_access_refused` med `reason = missing_write_role`.

### Kjente begrensninger

- **Duplikater.** Opprettelsen er **ikke idempotent**. Et gjentatt `POST` (for eksempel en klient som prøver igjen etter tidsavbrudd) oppretter et nytt tiltak. Det er en akseptert begrensning for denne første, kontrollerte operasjonen. Det finnes ingen `Idempotency-Key`, ingen idempotenstabell og ingen automatisk gjentakelse. Klienter bør ikke prøve skrivekall på nytt automatisk. Duplikatbeskyttelse vurderes separat dersom bruksmønsteret krever det.
- Ingen endring, fullføring eller gjenåpning av tiltak via API-et, og ingen `outcome_note`.
- Én operasjon. Ingen andre moduler har operasjoner.

### Hva som er verifisert lokalt, og hva som gjenstår

- **Lokalt, med simulerte Entra-tokens** (testnøkkel, falsk nøkkelliste): skriverollen, operasjonstillatelsen, tildeling, klient, kundeseparasjon, fagområder, validering, varsel, opphavsvisning, og at alle andre skriveveier er stengt.
- **Gjenstår før produksjon:** app-rollen `Procynia.Write.All` må opprettes på API-registreringen i Entra og tildeles Siggjos tjenesteprinsipal, og et ekte token må verifiseres mot Procynia (sammen med §16 «Gjenstår for testing med ekte Entra-token fra Siggjo» og V1–V4 i §11). Migreringen må kjøres i Azure.

### Migrering

- `2026_10_24_000003_add_allowed_operations_to_api_client_customer_grants`: `jsonb allowed_operations NOT NULL DEFAULT '[]'`. Ingen andre databaseendringer.

### Tester

Se sluttrapporten for denne leveransen. Nye testfiler: `IntegrationOperationAuthorityTest`, `ApiRiskTreatmentActionCreateTest`; utvidet: `ApiClientGrantAdministrationTest`, `ApiClientAuthenticationTest`, `ApiRouteContractTest` (GET overalt, unntatt navngitte skriveruter bak `api.write`), `customer-integrations.spec.js`.

---

## 21. Produktbeslutninger etter fase 3 (2026-10-24)

Fase 3 er godkjent som lokalt fullført, med restpunktene i [`external-api-coverage-status.md`](external-api-coverage-status.md) (funn 17 og 19). Produkteier har tatt to beslutninger.

### D15 – KVA-02: bare godkjent prosessflyt **[BESLUTTET]**

**Beslutning:** Integrasjoner får bare se den **godkjente prosessflyten**, aldri arbeidsversjonen.

**Hva det betyr i koden:**

- Den godkjente flyten er den nyeste revisjonen i `quality_process_revisions` (`QualityProcessBlueprintService::latestRevision()`). Revisjoner kan ikke endres eller slettes.
- Arbeidsversjonen er `quality_process_blueprints`. Ingenting som leses derfra, skal gå til API-et:
  - flyten selv
  - aktivitetsnavn og roller
  - om det finnes ikke-godkjente endringer (`has_unpublished_changes`)
  - vurderinger som bygger på arbeidsversjonen
- En prosess uten godkjent revisjon har ingen flyt i API-et. Det samme gjelder en utgått prosess, fordi revisjonene da er historikk og ikke gjeldende.

**Hva det ikke endrer:** Webgrensesnittet og Kvalitets egne regler er uendret. Beslutningen gjelder bare hva API-et viser.

### D16 – Ledelsens gjennomgang: ingen beslutningstekster **[BESLUTTET]**

**Beslutning:** Beslutnings- og tiltakstekster i Ledelsens gjennomgang eksponeres ikke i API-et før innholdet er vurdert som egnet.

API-et oppfyller dette allerede i dag: `GET /api/v1/management-reviews/{reviewId}` viser hver beslutning med type, seksjon, ansvarlig, frist, oppfølging og status, ikke med teksten. Det samme gjelder fullføringsnotater. Feltet `text` kan tas opp igjen som en egen beslutning, for eksempel sammen med DPIA (D14).

### Konsekvenser for restpunktene

| Funksjon | Før | Etter D15 | Det som gjenstår |
|---|---|---|---|
| **RIS-02** – kontroller og prosesser på en risiko | Aktivitetsnavn og roller holdt tilbake | **Blokkeringen fjernet.** Aktivitetene kan navngis fra den godkjente revisjonen. | Implementering. Regler: navn bare når prosessen har en godkjent revisjon og ikke er utgått, og bare for aktiviteter som finnes i den revisjonen. En kobling til en aktivitet som bare finnes i arbeidsversjonen, telles, men navngis ikke. |
| **MOK-06** – prosessene en KPI måler | Som RIS-02 | **Blokkeringen fjernet**, med samme regler. | Implementering |
| **KVA-04** – Kvalitets oppmerksomhet | `controls_without_activity` holdt tilbake | **Ikke fjernet.** Kvalitets regel vurderer plasseringer mot arbeidsversjonen, og D15 utelukker den. En variant mot godkjent versjon ville gitt API-et en annen regel enn «Trenger oppmerksomhet» på websiden. | Ny beslutning (D17, åpen): skal funnet utelates i API-et, skal Kvalitets regel endres til godkjent versjon for alle, eller skal API-et få en egen variant? |
| **KVA-02** – ett element med prosessflyt | Kunne ikke startes | **Beslutningen er tatt.** Bare den godkjente revisjonen. | Implementering i fase 3. Ikke startet. |
| **KVA-01 / KVA-03** (allerede implementert) | Uten publiseringsstatus og plasseringer | Kan utvides med fakta fra den godkjente revisjonen: revisjonsnummer, godkjent tidspunkt, og plasseringer på aktiviteter som finnes i den. Aldri med `has_unpublished_changes`. | Valgfri utvidelse, ikke en manglende A-funksjon. |

Ingen ny API-operasjon er bygget som følge av beslutningene, og faseplanen er uendret.
