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

Global og per-kunde NOK-budsjett, betalingsstatus-policy (`past_due`/`unpaid`), Stripe metered billing, automatisk overage, self-service kjøp av credits, anomali-deteksjon og full intern adminflate. Den interne `AiUsageCapacity`-siden måler fortsatt operasjonstelling mot AI-saker og bør legges om når adminflaten bygges i Fase 4.
