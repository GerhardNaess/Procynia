# Felles AI-kapasitet

Kort produkt- og driftsnotat. Ingen tall her er kommersielle beslutninger.

## Produktregel

**Basis gir én felles AI-kapasitet. Alle AI-funksjoner i Procynia bruker samme pool. Opsjoner gir tilgang til funksjoner, ikke separate AI-pooler.**

- **Basis** (obligatorisk): Wiki, Kvalitet, Avvik og forbedringer. Basis er den eneste kommersielle kilden til inkludert AI-kapasitet.
- **Opsjoner**: Risiko, Mål og KPI, Etterlevelse og revisjon, Leverandøroppfølging, Anbud. En opsjon øker aldri kapasiteten og har aldri egen pool. Styring / ISO / GRC er bare bestillingssnarveier, ikke et tilgangs- eller kapasitetshierarki.
- **Gamle abonnementsplaner** (free/pro/max/ultra/enterprise) brukes ikke til AI-kapasitet. De styrer fortsatt Anbud AI-sak-kvoten (`included_ai_credits`) i overgangen.

## Kilde for inkludert kapasitet

`CustomerAiCapacityService::resolveIncluded()`, i denne rekkefølgen:

1. `customers.included_ai_units`: kundespesifikk mengde per faktureringsperiode. Dette er foreløpig mekanismen for Enterprise og annen særskilt kapasitet (Admin → Kunder → AI-kontroll → «Sett AI-enheter», revisjonslogget). Ingen egen Enterprise-prismotor.
2. Basis: `config/ai_customer_capacity.php` `basis.included_units_per_month` × periodens lengde (12 for årlig). **Teknisk plassholder** (2 000), merket `is_provisional`. Kunden ser «AI-kapasiteten er under innfasing, og nivået kan bli justert.»
3. Ingen: kunden er «unmetered». Forbruket registreres og observeres, men stoppes aldri.

`nok_per_unit` (0,10) er en **teknisk kalibreringsverdi**, ikke en pris. Den vises aldri for kunden, markedsføres ikke og skal ikke brukes som grunnlag for prisstrategi.

## Beregning og reservasjon

`ai_usage_attempts` (intern NOK) → `AiUnitConverter` → AI-enheter. Brukt = settled `cost_nok`. Reservert = `reserved_cost_nok` på pending/unresolved. Tilgjengelig = inkludert − brukt − reservert.

Reservasjonen er attempt-raden selv. `AiCostControlService::admit()` (kun brukt av `OpenAiClient`) evaluerer kapasiteten og oppretter den pending attempt-raden med estimatet i samme transaksjon som holder låsen på kunderaden. Samtidige kall for samme kunde venter på låsen og ser reservasjonen før de vurderes. Dette er verifisert med ekte parallelle prosesser: 8 samtidige kall med plass til 3 gir nøyaktig 3 innslipp (`CustomerAiCapacityConcurrencyTest`). Det finnes ingen egen reservasjonstabell. `authorize()` er en ren forhåndssjekk (Wiki Ask) og åpner ingenting.

## Modus og overgang

`AI_CUSTOMER_CAPACITY_ENFORCEMENT` = `off` | `observe` | `enforce`.

| | Nå | Senere |
|---|---|---|
| Felles kapasitet | `observe` (standard) | `enforce` |
| Anbud AI-sak-kvote | håndheves | fjernes som generell kommersiell begrensning |

- `observe`: hvert kall får `capacity_verdict` (`allow`, `warn`, `exhausted`, `insufficient`, `unmetered`) på attempt-raden. Kall som ville blitt avvist logges med `[AI_CAPACITY]` og går gjennom.
- `enforce`: `exhausted`/`insufficient` avvises før provider (`AI_CAPACITY_EXHAUSTED` / `AI_CAPACITY_INSUFFICIENT`). Operatør-override kan omgå. Teknisk trygg etter concurrency-fixen, men ikke slått på.
- Med `enforce` sjekkes et Anbud-kall med AI-kreditt mot både kapasiteten og AI-sak-kvoten, i den rekkefølgen, i `AiCostControlService::decideCustomer()`.

Strict (`AI_CONTEXT_ENFORCEMENT`) er en annen mekanisme: korrekt attribusjon. Capacity er kommersiell grense. De blandes ikke.

## Kalibrering før kommersiell beslutning

`php artisan ai:capacity-analysis` (se kommandoens `--help`). Rapporten gir forbruk, kostnad, enheter, «would have blocked», estimatpresisjon per operasjon og samtidighetstopp.

Anbefaling (ikke en runtime-regel): samle minst **14 dager**, helst **én full faktureringsperiode** for representative kunder, før inkludert kapasitet og `nok_per_unit` låses. Systemet justerer aldri `nok_per_unit`, `included_units_per_month` eller operasjonsestimater selv. Dette bestemmes eksplisitt.
