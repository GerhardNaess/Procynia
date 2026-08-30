# AI Usage Guard and cost telemetry

## Formål
Procynia logger AI-operasjoner for intern innsikt og varsler om uvanlig høyt tempo før en AI-operasjon starter. Fase 1 har i tillegg et append-only attempt-ledger (`ai_usage_attempts`) for faktiske providerforsøk. Dette er fortsatt ikke kommersiell stopp, full AI-credit-måling eller fakturering.

## Fase 1: provider attempts

Én rad i `ai_usage_attempts` representerer ett faktisk providerforsøk. Den inneholder kunde-/bruker-attribusjon når konteksten er kjent, feature/operation, ressurs- og Wiki-run-korrelasjon, modell/provider, request-id, tokenbruk, latency og outcome. Prompt, Wiki-innhold, modellsvaret, API-nøkler og Authorization-headere lagres aldri.

Responses API-kall måles sentralt i `OpenAiClient`; eldre kall som trenger rå HTTP-respons går gjennom samme meter. `EmbeddingService` måler embedding-kall separat fordi den trenger å beholde sin eksisterende kontrollerte feilrespons. Enterprise Wiki sine kapasitet/retry-forsøk scopes med ingest-run og kunde, slik at hvert retry blir en separat attempt. `enterprise_wiki_ingest_runs.input_tokens` og `output_tokens` summeres fra vellykkede, scoped Wiki-forsøk.

Dette dekker requirements og requirement answers, grounding/judge, assessment, knowledge summary/metadata/vocabulary, metadata-retrieval, Excel-struktur, Enterprise Wiki-klientene, Wiki Ask og response-baserte queue-/scheduler-flows fordi de alle går via `OpenAiClient`. Embeddings fra AI- og Knowledge-base-flows går via `EmbeddingService`. Forsøk uten en eksplisitt `AiCallContext` er fortsatt synlige som `unclassified`; Fase 2 skal gjøre kontekstattribusjon obligatorisk ved en felles provider-gateway.

`ai_token_events` beholdes som eksisterende suksessbaserte token-ledger for rapportering. Det erstattes ikke av attempt-ledgeret, fordi failed, timeout og uncertain-forsøk er en annen operasjonell sannhet.

## Wiki Ask

`POST /app/wiki/ask` kontrollerer nå server-side entitlement med `BillingEntitlementService::canUseAiOffer()`. Ruten er dessuten rate-begrenset per bruker og per kunde over et 15-minutters vindu (standard: 10 spørsmål per bruker og 60 per kunde). Grensene ligger under `procynia.ai.wiki_ask`; 429-svar og sikkerhetslogg inneholder bare reason, interne customer/user-id-er og operation — aldri spørsmålet eller svaret.

Et vellykket Wiki-spørsmål kan gjøre to AI-kall: retrieval-plan og grounded answer. Begge registreres separat med `feature=wiki`, customer, user, operation, latency, modell og provider request-id når leverandøren returnerer den.

## Konfigurasjon
Følgende `.env`-variabler styrer grensene:
- `AI_RATE_LIMIT_USER_PER_MINUTE`
- `AI_RATE_LIMIT_USER_DECAY_SECONDS`
- `AI_RATE_LIMIT_CUSTOMER_DECAY_SECONDS`

`AI_RATE_LIMIT_CUSTOMER_PER_HOUR` er deprecated og brukes ikke lenger som en stoppmekanisme.

Standardverdiene ligger i `config/procynia.php`.

## Hva grensene betyr
- Brukergrensen varsler når en bruker kjører mer enn fem AI-operasjoner i løpet av ett minutt. Operasjonen fortsetter.
- Kunde-/timeverdien er ikke lenger en stoppmekanisme. Den gamle modellen med operasjonsstopp er avviklet.
- Grenser og tidsvinduer skal vurderes ut fra faktisk bruksmønster over tid, ikke som økonomisk styring.

## Usage events
Procynia lagrer ikke-sensitive `ai_usage_events` for tillatte AI-operasjoner og historiske blokkeringer fra eldre data.

Feltet lagrer kun:
- kunde
- bruker
- operation key
- status
- limit type
- operation count

Følgende lagres ikke:
- prompt
- dokumenttekst
- kravtekst
- svartekst
- chunkinnhold
- API-nøkler

## Intern AI-bruk og varsler
Filament-admin har nå en intern side under `Drift` med navnet `AI-bruksmønster og varsler`.
Den viser aggregert AI-bruk per kunde og per bruker, samt høy aktivitet, varsler og historiske blokkeringer fordelt på `ai_usage_events`.
Siden brukes til drift og observasjon, ikke økonomi.

Visningen bruker kundens inkluderte AI-referanseverdi når den er tydelig definert i kunde- eller plan-data, men den er en intern oversikt over bruksmønster og historiske varsler, ikke økonomi.
Hvis referanseverdi ikke er definert, vises dette eksplisitt som `Ikke definert` i stedet for at systemet gjetter.

Dette er intern styring og bruksmønsteroppfølging, ikke fakturagrunnlag og ikke Stripe usage billing.

## AVVIK-010
Full AI-credit- og bruksmønsteroppfølging hører til AVVIK-010. Denne delen av systemet viser faktisk AI-bruk, høy aktivitet og historiske varsler. Systemet lagrer fortsatt kun trygge bruksdata som grunnlag for senere vurdering.

## Fase 2: commercial quota og runtime stop

Én commercial AI-credit er én unik AI-aktivert `SavedNotice` per kunde per kalendermåned, i appens timezone. `customer_ai_case_usages` er fortsatt den historiske source of truth for committed forbruk. Videre AI-arbeid på den samme saken i samme måned bruker ikke en ny credit, også når kunden ellers har nådd quotaen.

`AiQuotaPolicyResolver` gir alle nedstrøms kall én eksplisitt entitlement-policy: `none`, `finite` eller `unlimited`. `BillingEntitlementService` er fortsatt kilden for plan og feature. Null i planens `included_ai_credits` betyr eksplisitt unlimited; null eller 0 blir aldri brukt som en implisitt saldo i cost-control.

`AiCostControlService` er den sentrale server-side grensen før leverandørkall. Ved finite SavedNotice-arbeid opprettes først en row i `customer_ai_quota_periods` for kalendermåneden. Den låses med database-lås (`FOR UPDATE`) og saldo beregnes fra committed usage pluss aktive `reserved`/`uncertain` reservasjoner i `customer_ai_usage_reservations`. Først etter committed reservasjon kan provider-kallet starte. Ti samtidige forsøk på én siste credit gir dermed én reservasjon og ni `AI_QUOTA_EXHAUSTED`-avvisninger.

En credit knyttes til saken, ikke til kallet: flere parallelle providerkall for den *samme* SavedNotice holder til sammen én plass i kvoten, mens hver ny SavedNotice krever sin egen. Ved vellykket providerrespons finaliseres reservasjonen og den eksisterende `customer_ai_case_usages`-ledgeren oppdateres idempotent. Sikker feil frigir reservasjonen. Timeout, connection-feil og 5xx markeres `uncertain` og beholdes til en senere reconciliation; reservasjoner slettes aldri. Extra credits kan settes som et administrativt periodetall (`extra_credits`) uten kjøpsflyt. Planopp- og nedgraderinger beregnes dynamisk mot den samme usage-historikken.

Alle AI-kall gjennom `OpenAiClient` sjekker entitlement, customer suspend og global stop rett før HTTP. `customers.ai_access_status` er `enabled` eller `suspended`; `ai_runtime_controls.global_ai_stop` er én database-backed emergency switch. Manglende runtime-control-row fail-closed. Endringer gjøres gjennom `AiRuntimeControlService` og skriver `billing_events` med actor, før/etter og reason. Det kreves verken deploy, restart eller nøkkelendring. Wiki Ask har i tillegg en presentation-safe precheck, men bruker aldri SavedNotice-credit.

Begge bryterne betjenes i drift med `php artisan ai:runtime-control` (`status`, `global-stop`, `global-resume`, `suspend-customer`, `resume-customer`). `--actor` registrerer hvem som utførte endringen i `billing_events`. En rikere adminflate hører til en senere fase; kommandoen finnes for at bryterne faktisk skal kunne brukes uten deploy.

`AI_NOT_INCLUDED`, `AI_QUOTA_EXHAUSTED`, `AI_CUSTOMER_SUSPENDED` og `AI_GLOBAL_STOP` er de stabile block-kodene. De skal oversettes i UI; interne providerfeil skal ikke eksponeres. Queue-arbeid sjekkes på nytt i providergrensen og kan derfor ikke bruke en gammel dispatch-beslutning. Scheduled Enterprise Wiki maintenance beholder run/customer-kontekst når den kan utløse AI.

## Provider-context coverage

Kontekst settes ved inngangen til arbeidet, ikke ved hvert AI-kall: HTTP-controllere setter den fra innlogget bruker, tjenester setter den rundt en konkret operasjon, og queue jobs setter den i `handle()` via `App\Support\Ai\RunsInAiCallContext`. Nestede kall arver den. Det er dette som gjør at en jobb som ble lagt i kø for timer siden fortsatt håndheves mot kundens *nåværende* status.

| Flow | Klassifisert | Credit policy | Customer/global guard |
| --- | --- | --- | --- |
| SavedNotice requirement extraction (queue) | ja | commercial | ja/ja |
| SavedNotice answer, Wiki answer og assessment | ja | commercial | ja/ja |
| Enterprise Wiki ingest, seksjoner og finalize (queue) | ja | non-credit | ja/ja |
| Enterprise Wiki claim verification, page generation, maintainer batches, post-ingest QA, reconciliation (queue) | ja | non-credit | ja/ja |
| Enterprise Wiki maintenance cycle (scheduler) | ja | non-credit | ja/ja |
| Wiki Ask | ja | non-credit | ja/ja |
| Knowledge chunk metadata (queue) | ja | non-credit | ja/ja |
| Embeddings og øvrig knowledge-arbeid utenfor de flowene over | nei | non-credit | global alltid; customer kun når en ytre kontekst finnes |

`OpenAiClient` logger en driftshendelse for en kallkjede uten customer context og behandler den som eksplisitt systemarbeid. Global stop gjelder likevel alltid, fordi den evalueres før kundeoppslaget.

## Fase 3: kundevarsling og kunde-UX

### Statusnivåer

`AiQuotaStatusService` er den eneste kilden til kundens kommersielle posisjon. Billing-siden, AI-arbeidsflaten og varslingen leser den samme beregningen — ingen av dem regner quota selv.

| Status | Når | Blokkerer AI? |
| --- | --- | --- |
| `normal` | under warning-terskelen | nei |
| `warning` | fra `AI_QUOTA_WARNING_PERCENT` (standard 80 %) | nei |
| `critical` | fra `AI_QUOTA_CRITICAL_PERCENT` (standard 90 %) | nei |
| `exhausted` | ingen gjenstående kapasitet | ja, for **nye** AI-saker |
| `suspended` | `customers.ai_access_status = suspended` | ja, alt |

`warning` og `critical` er varselnivåer, ikke stopp. Ordet «soft stop» brukes ikke i UI, fordi ingenting stoppes der. Tersklene ligger i `config/procynia.php` under `procynia.ai.quota`; 100 % er ikke konfigurerbart, fordi det er reservasjonsledgerens faktiske hard stop.

Prosenten regnes av **AI-saker**, ikke providerkall: unike AI-aktiverte SavedNotices i perioden mot `included_ai_credits` pluss `customer_ai_quota_periods.extra_credits`. `ai_usage_events` og `ai_usage_attempts` er operasjonell telemetri og inngår aldri i dette tallet.

En sak med aktiv reservasjon teller som forbrukt. Ellers ville kunden se «1 igjen» mens en kjørende jobb allerede holder den siste crediten.

`unlimited` får aldri prosent, gjenstående eller `exhausted`. `none` (plan uten AI) vises som «ikke inkludert», ikke som oppbrukt kvote.

### Varsling

| Hendelse | Mottaker | Kanal | Dedupe |
| --- | --- | --- | --- |
| 80 % (`quota_warning`) | aktive System Owners | in-app + e-post | kunde + event + periode |
| 90 % (`quota_critical`) | aktive System Owners | in-app + e-post | kunde + event + periode |
| 100 % (`quota_exhausted`) | aktive System Owners | in-app + e-post | kunde + event + periode |
| AI suspendert | aktive System Owners | in-app + e-post | ingen — en reell adminhandling |
| AI gjenåpnet | aktive System Owners | in-app + e-post | ingen — en reell adminhandling |

Terskelvurdering skjer i `AiCostControlService::finalize()`, rett etter at en credit faktisk er committet — den ene reelle kommersielle tilstandsendringen. Den kjører utenfor transaksjonen og kan aldri kaste tilbake i AI-kallet.

Dedupe er `customer_ai_notification_states` med unik `(customer_id, event_key, period_start)`. Det er en tabell og ikke cache, fordi en deploy eller cache-flush ikke skal kunne sende 80 %-e-posten om igjen. Historikk slettes aldri; ny kalendermåned er ny `period_start` og åpner tersklene på nytt.

Krysser kunden flere terskler i ett hopp, sendes bare det sterkeste nye varselet. De svakere registreres som passert, slik at de ikke kan utløses senere i samme periode. En planendring nullstiller ikke dedupe.

Har kunden ingen aktiv System Owner, registreres varselet **ikke** som sendt. Det logges operasjonelt og gir en intern `AdminNotification` (`ai_quota_no_recipient`, dedupet). Får kunden en System Owner senere i perioden, leveres varselet én gang.

Global stop varsler ikke kunden. Det er en driftshendelse, ikke en opplysning om kundens konto.

### Kunde-UX

`/app/billing` har en egen AI-kapasitet-seksjon med plan, inkludert, ekstra kapasitet, brukt, gjenstående, periode, neste reset og status. Autorisasjonen er uendret (`canManageCustomerBilling()`: System Owner og Bid Manager). AI-arbeidsflaten viser den samme tilstanden som én linje.

Status formidles alltid med tekst og ikke bare farge: badge-etikett, statusbeskrivelse og en tekstlabel til progressbaren. Unlimited får ingen progressbar, fordi en full bar ville si det motsatte av sannheten.

### Hard stop og entitlement

De fire årsakskodene har hver sin melding via `AiCostControlPresenter` — de krever ulik handling og skal ikke kollapse til én generisk tekst:

| Kode | Betydning for kunden |
| --- | --- |
| `AI_NOT_INCLUDED` | abonnementet inkluderer ikke funksjonen |
| `AI_QUOTA_EXHAUSTED` | periodens kapasitet er brukt |
| `AI_CUSTOMER_SUSPENDED` | tilgangen er midlertidig suspendert |
| `AI_GLOBAL_STOP` | AI er midlertidig utilgjengelig |

`AI_QUOTA_EXHAUSTED` sier eksplisitt at allerede aktiverte anbud kan arbeides videre med, og at bare **nye** AI-saker er blokkert. Det er Fase 2s faktiske policy, og meldingen må ikke motsi den. Interne exception-meldinger, modellnavn, tokens og providernavn eksponeres aldri.

`warning` deles nå fra `HandleInertiaRequests`. Den ble tidligere flashet i backend og forsvant før frontend.

### Ikke del av Fase 3

Global og per-kunde NOK-budsjett, betalingsstatus-policy (`past_due`/`unpaid`), Stripe metered billing, automatisk overage, self-service kjøp av credits og anomali-deteksjon.

## Fase 4: intern adminflate og operatørkontroll

### Adminflater

Per kunde: **Kunder → (kunde) → AI-kontroll** (`ManageCustomerAiControl`). Viser plan, kvotetype, inkludert, ekstra kapasitet, brukt, reservert, gjenstående, periode, AI-tilgangsstatus, usikre reservasjoner, kapasitetshistorikk og revisjonsspor. Handlinger: suspender AI, gjenopprett AI, endre AI-kapasitet.

Globalt: **Drift → AI-bruksmønster og varsler** (`AiUsageCapacity`) eier global AI-stopp. Er stoppen aktiv, vises et rødt banner øverst med hvem som satte den, når og hvorfor — den skal ikke kunne overses.

Adminflatene beregner ingenting selv. De leser `AiQuotaStatusService` og skriver kun gjennom `AiRuntimeControlService` og `AiCreditAdjustmentService`; en handling som gikk utenom disse ville hoppet over både revisjonssporet og kundevarselet.

### Kapasitetsendringer

`customer_ai_credit_adjustments` er append-only. Rader endres eller slettes aldri. `customer_ai_quota_periods.extra_credits` er en projeksjon som skrives om fra summen av ledgeren i samme låste transaksjon — ledgeren er sannheten, kolonnen er hurtigveien for autorisasjonsstien.

Beløpet er fortegnet. En negativ justering reduserer den reelle kapasiteten, ikke bare en «ekstra»-bøtte: base 10 med justering −5 gir kapasitet 5. Selve kapasiteten gulves på 0, aldri negativ. Historisk forbruk (`customer_ai_case_usages`) endres aldri — det finnes ingen CRUD for ledger-rader.

Justeringer gjelder inneværende periode. System Owner varsles gjennom den samme Fase 3-mekanismen.

### Revisjonsspor

`billing_events` med `source = ai_cost_control`. Alle handlinger krever begrunnelse, og aktør registreres.

| Event type | Utløses av |
| --- | --- |
| `ai_customer_suspended` / `ai_customer_resumed` | admin-UI eller `ai:runtime-control` |
| `ai_global_stop_enabled` / `ai_global_stop_disabled` | admin-UI eller `ai:runtime-control` |
| `ai_credits_adjusted` | `AiCreditAdjustmentService` |
| `ai_operator_override_used` | en operatørkommando som faktisk omgikk en kommersiell guard |

### Operatørkommandoer

De fem manuelle kommandoene setter nå eksplisitt `AiCallContext` med kunde, feature, operasjon, ressurs og korrelasjon. Ingen av dem er `unclassified`.

| Kommando | Operasjon | Normal guard | Override | Global stop |
| --- | --- | --- | --- | --- |
| `wiki:generate-applied-pages` | `operator.wiki.generate_applied_pages` | håndheves | tillatt | stopper alltid |
| `wiki:verify-page-claims` | `operator.wiki.verify_page_claims` | håndheves | tillatt | stopper alltid |
| `wiki:recover-document-flow` | `operator.wiki.recover_document_flow` | håndheves | tillatt | stopper alltid |
| `wiki:maintainer-decision` | `operator.wiki.maintainer_decision` | håndheves | tillatt | stopper alltid |
| `wiki:inspect-requirement-answer` | `operator.wiki.inspect_requirement_answer` | håndheves | tillatt | stopper alltid |

Standard er å respektere alle guards. Override krever begge deler eksplisitt:

```
--cost-control-override --actor=<intern super admin> --override-reason="..."
```

Mangler aktør eller begrunnelse, avvises kjøringen. Aktør må være intern Procynia super admin med `customer_id = null` — en kundebruker kan aldri stå som aktør bak en overstyring, verken her eller i `ai:runtime-control`.

Override slakker på entitlement (`AI_NOT_INCLUDED`), kvote (`AI_QUOTA_EXHAUSTED`) og kundesuspensjon (`AI_CUSTOMER_SUSPENDED`) — de tre kommersielle grensene. Entitlement er tatt med bevisst: uten det ville en gjenoppretting være umulig for en kunde som nedgraderte etter at kjøringen låste seg. Hver bypass skriver `ai_operator_override_used` med aktør, begrunnelse og hvilken guard som ble omgått, og reservasjonen tas fortsatt — en overstyring endrer hva som er tillatt, ikke hva som registreres.

**Global emergency stop kan ikke omgås.** Den evalueres før kundeoppslaget og har ingen override-sti. Et break-glass for global stopp er bevisst ikke bygget; det krever eget design.

### Rettet: intern kapasitetsvisning

Kapasitetskolonnen i `AiUsageCapacity` sammenlignet tidligere en 30-dagers telling av AI-*operasjoner* mot antall AI-*saker* planen inkluderer — ulike enheter over ulike vinduer — slik at vanlig aktivitet kunne få en kunde til å framstå som over grensen. Den leser nå `AiQuotaStatusService` for inneværende kalenderperiode. Operasjonstellingene står igjen som ren aktivitet, og token/NOK-rapporteringen på **AI-forbruk** er urørt: kommersiell kvote og operasjonell tokenkostnad blandes ikke i ett tall.

### Usikre reservasjoner

Kunder med `uncertain`-reservasjoner får dette synliggjort på AI-kontroll-siden med antall og forklaring på at kapasiteten fortsatt holdes fordi providerkallet kan ha blitt utført. Det finnes bevisst ingen «frigi»-knapp: full reconciliation krever egen policy og hører til en senere fase.

### Ikke del av Fase 4

Stripe metered billing, automatisk overage, anomali-deteksjon, forecasting og reconciliation av usikre reservasjoner.

## Fase 5: operasjonell NOK-beskyttelse, pris/FX-policy og betalingsstatus

### To lag, ikke ett tall

| | Kommersiell kvote | Operasjonelt budsjett |
| --- | --- | --- |
| Enhet | AI-saker (credits) | NOK providerkostnad |
| Beskytter | kunden fikk det de kjøpte | Procynias egen økonomi |
| Synlig for | kunden og admin | **kun** intern admin |
| Kan stoppe unlimited-plan | nei | **ja** |
| Kan stoppe allerede aktivert sak | nei | **ja** |

De to byttes aldri mot hverandre. Et NOK-budsjett er ikke en erstatning for credits, og credits gir ingen rett til ubegrenset providerkostnad. Kunden ser fortsatt bare credits — NOK-tall er driftsdata, ikke fakturagrunnlag.

### Kostnadstilstander

| Status | Betydning |
| --- | --- |
| `known` | fersk pris og fersk kurs |
| `estimated` | priset, men med gammel pris eller kurs — padd med sikkerhetsmargin |
| `unknown` | modellen kan ikke prises. **Aldri 0 kr** |
| `uncertain` | leverandøren kan ha utført arbeidet (timeout/5xx) |

`unknown` og `uncertain` er forskjellige problemer: det første er et prisproblem, det andre et utfallsproblem.

### Prissnapshot

Kostnaden fryses på `ai_usage_attempts` — samme rad som allerede har modell, tokens og utfall. Valgt framfor en parallell kostnadsledger fordi en andre tabell bare ville vært et join unna de samme fakta og kunne drifte fra dem. Snapshotet lagrer pris-id, pris per 1M inn/ut, valuta, FX-kurs og kursdato, slik at en senere priskorreksjon ikke kan skrive om grunnlaget for en beslutning som allerede er tatt.

`ai_token_events` og `AiTokenCostEstimator` er urørt og priser fortsatt den eldre rapporteringsledgeren. Historiske rader uten snapshot beregnes som før.

### Ukjent modellpris

Mangler modellen pris, blokkeres kallet **før** leverandøren kontaktes, forbruket registreres som `unknown`, og intern admin varsles (dedupet per modell per dag).

Ett unntak, bevisst: er priskatalogen **helt tom**, er prisbasert håndheving ikke konfigurert i det hele tatt. Å tolke det som «alle modeller mangler pris» ville gjort et nytt eller feilkonfigurert miljø til full AI-nedetid. Den tilstanden varsles i stedet, kritisk og separat (`ai_model_price_catalogue_empty`). Så snart katalogen har minst én pris, er ukjent modell en hard stop.

### Prisalder

`AI_MODEL_PRICE_WARNING_AGE_DAYS` (90) og `AI_MODEL_PRICE_CRITICAL_AGE_DAYS` (180). Priser vedlikeholdes bevisst, ikke skrapes daglig — et kvartal uten endring er normalt. Gammel pris blokkerer ikke, men padder estimatet med `AI_MODEL_PRICE_STALE_SAFETY_MARGIN_PERCENT` (20 %) før det belastes et sikkerhetsbudsjett.

### FX-policy

`ExchangeRate::findForDate()` bruker historisk kurs på hendelsesdato og faller tilbake til siste kjente. Fase 5 legger friskhet på toppen:

| Tilstand | Terskel | Handling |
| --- | --- | --- |
| fersk | < `AI_FX_WARNING_AGE_DAYS` (3) | kurs brukes som den er |
| stale warning | ≥ 3 dager | kurs brukes, padd med `AI_FX_SAFETY_MARGIN_PERCENT` (10 %) |
| stale critical | ≥ `AI_FX_CRITICAL_AGE_DAYS` (14) | som over, pluss adminvarsel |
| mangler helt | ingen kurs registrert | konservativ `AI_FX_FALLBACK_USD_NOK_RATE` (12,0) + margin, kritisk varsel |

Tre dager absorberer en normal helg pluss en helligdag — Norges Bank publiserer ikke daglig. AI stoppes aldri fordi dagens kurs ikke er hentet når en nylig gyldig kurs finnes. Manglende kurs gir aldri 0 kr.

### Sikkerhetsbudsjetter

Fire vinduer: kunde daglig, kunde månedlig, global daglig, global månedlig. Ingen er obligatoriske — uten grense gjelder ingen operasjonell stopp for det vinduet.

Grensene er runtime-innstillinger i databasen, ikke env: en operatør må kunne endre dem midt i en hendelse uten deploy. Kundegrenser i `customer_ai_operational_limits`, globale på den eksisterende `ai_runtime_controls`-raden — samme sted som nødstoppen, fordi begge ender alle AI-kall.

Løpende forbruk holdes i `ai_operational_budget_periods` (scope × vindu × periode) med `committed_nok`, `reserved_nok` og `unknown_cost_count`. Et aggregat framfor en SUM over `ai_usage_attempts` fordi håndheving må kunne låses: ti samtidige kall skal ikke kunne lese samme saldo og alle konkludere med at det er plass. Attempt-ledgeren er fortsatt den reviderbare detaljen bak totalene.

Egne årsakskoder, aldri commercial quota-koden: `AI_CUSTOMER_DAILY_BUDGET_EXHAUSTED`, `AI_CUSTOMER_MONTHLY_BUDGET_EXHAUSTED`, `AI_GLOBAL_DAILY_BUDGET_EXHAUSTED`, `AI_GLOBAL_MONTHLY_BUDGET_EXHAUSTED`.

### Reservasjon

Før kallet kjennes ikke tokenforbruket. Derfor reserveres et bevisst pessimistisk estimat: tokentak per operasjon fra ett register (`procynia.ai.operation_estimates`), ganget med pris og kurs, padd med `AI_OPERATIONAL_RESERVATION_SAFETY_MARGIN_PERCENT` (25 %). Målet er ikke presisjon, men at ett enkelt kall ikke kan lande langt over grensen fordi vi bare teller etterpå.

Livssyklus: **reserved** → **committed** (faktisk kostnad fra snapshotet), **released** (sikker feil før eller uten leverandørarbeid), eller **uncertain** (timeout/5xx → reservasjonen beholdes som påløpt). En usikker kostnad frigis aldri automatisk.

### Manuell nødstopp vs budsjettstopp

| | Manuell global stopp | Global budsjettstopp |
| --- | --- | --- |
| Utløses av | operatør | systemet selv |
| Betyr | «vi stopper nå» | «planlagt forbruk er brukt opp» |
| Kan omgås | nei | nei |
| Kundemelding | «AI er midlertidig utilgjengelig» | samme |

Begge finnes, begge stopper alt, og intern admin ser hvilken som gjelder. Kunden ser samme nøytrale melding for begge — det er driftsstatus, ikke noe om kundens konto.

### Betalingsstatus

Leses fra Cashiers `subscriptions.stripe_status` rett før hvert providerkall, ikke fra et snapshot tatt ved dispatch. Webhooken holder tilstanden fersk, men håndhevingen slår opp selv.

| Stripe-status | AI-policy |
| --- | --- |
| `active` | tillatt |
| `trialing` | tillatt |
| `past_due` | tillatt i `AI_PAYMENT_PAST_DUE_GRACE_DAYS` (7) fra faktisk overgang, deretter blokkert |
| `unpaid` | blokkert (`AI_PAYMENT_UNPAID`) |
| `incomplete` / `incomplete_expired` | blokkert (`AI_PAYMENT_INCOMPLETE`) |
| `canceled` | ingen betalingsblokk — plan/entitlement avgjør som før |
| ingen Stripe-kobling | ingen betalingsblokk — lokal plan avgjør som før |

Grace-vinduet måles fra da abonnementet faktisk gikk til `past_due`, ikke «nå minus sju dager» — ellers ville klokka startet på nytt ved hvert kall. Mangler tidsstempelet, velges fail-safe: kunden får grace, fordi å stenge en betalende kunde på et manglende felt er den verre feilen.

System Owner varsles allerede av `invoice.payment_failed`-webhooken. Fase 5 legger ikke en andre kunde-e-post på samme hendelse; betalingsblokkeringen varsles internt, dedupet per kunde per dag.

### Interne varsler

| Hendelse | Mottaker | Kanal | Dedupe |
| --- | --- | --- | --- |
| kunde-/globalt budsjett 80 % og 90 % | intern admin | AdminNotification | scope + vindu + periode |
| kunde-/globalt budsjett brukt opp | intern admin | AdminNotification | årsak + scope + periode |
| ukjent modellpris | intern admin | AdminNotification | provider + modell + dag |
| tom priskatalog | intern admin | AdminNotification | dag |
| kritisk gammel pris | intern admin | AdminNotification | provider + modell + dag |
| kritisk gammel / manglende FX | intern admin | AdminNotification | valuta + dag |
| betalingsfrist og betalingsblokk | intern admin | AdminNotification | kunde + tilstand + dag |

Alle er tilstandsvarsler, ikke ett per avvist kall: en kunde som prøver i loop gir ett varsel.

### Override-presedens

| Guard | Operatøroverstyring |
| --- | --- |
| manuell global stopp | **aldri** |
| globalt budsjett (daglig/månedlig) | **aldri** |
| kundens operasjonelle budsjett | nei — hev grensen i admin i stedet |
| ukjent modellpris | ja, auditert (`cost_status` forblir `unknown`) |
| betalingsblokk | ja, kun for recovery-kjøringer |
| kundesuspensjon, entitlement, kvote | ja, som i Fase 4 |

Plattformnivået er kodet som `AiCostControlException::PLATFORM_REASONS` og avvises i selve override-stien, uansett hvor den kalles fra.

### Ikke del av Fase 5

Stripe metered billing, automatisk overage, kundevendt NOK-faktura, forecasting, anomali-deteksjon, self-service kjøp, full reconciliation av usikre reservasjoner, Azure-kostnadsintegrasjon og provider-budsjett-API.
