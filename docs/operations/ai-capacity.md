# Felles AI-kapasitet

Kort produkt- og driftsnotat. Ingen tall her er kommersielle beslutninger.

## Produktmodell (låst)

**Basis + opsjoner + AI-kapasitet — tre separate kommersielle dimensjoner.**

- **Basis** er grunnproduktet: Wiki, Kvalitet, Avvik og forbedringer. Egen pris. Basis gir **ikke** AI-kapasitet.
- **Opsjoner** gir funksjonalitet: Risiko, Mål og KPI, Etterlevelse og revisjon, Leverandøroppfølging, Anbud. Egne priser. En opsjon gir aldri AI-kapasitet og har aldri egen pool. Å bestille eller avbestille en opsjon endrer ikke antall AI-enheter. Styring / ISO / GRC er bare bestillingssnarveier.
- **AI-kapasitet** er en egen dimensjon: kundens valgte AI-kapasitetsnivå (eller en kundeoverstyring). **Én felles AI-pool per kunde** som alle AI-funksjoner i alle moduler bruker.
- **Gamle abonnementsplaner** (free/pro/max/ultra/enterprise) er ikke kilde til AI-kapasitet. De lever under panseret for brukergrenser, Anbud AI-sak-kvoten (`included_ai_credits`), Stripe legacy mapping og admin.

**AI-nivåer og priser er ikke endelig fastsatt.** Nivåene under er tekniske plassholdere til produksjonsdata er samlet inn.

## Kilde for inkludert kapasitet

`CustomerAiCapacityService::resolveIncluded()`, i denne rekkefølgen (`source` i payload og rapport):

1. `override` — `customers.included_ai_units`: eksplisitt kundeoverstyring per faktureringsperiode (Enterprise/særskilt kapasitet). Admin → Kunder → AI-kontroll → «Sett AI-enheter», revisjonslogget. Går foran nivået.
2. `tier` — `customers.ai_capacity_tier`: en nøkkel i `config/ai_customer_capacity.php` `tiers`, `included_units_per_month` × periodens lengde (12 for årlig). Admin → AI-kontroll → «Velg AI-kapasitetsnivå», revisjonslogget (`ai_capacity_tier_changed`). Merket `is_provisional` så lenge `tiers_provisional` er `true`; kunden ser «AI-kapasiteten er under innfasing, og nivået kan bli justert.»
3. `unconfigured` — verken overstyring eller nivå. Ingen kommersiell grense: forbruket registreres og observeres (`capacity_verdict = unmetered`), men stoppes aldri. Kunden ser «AI-kapasitet er ikke konfigurert ennå» og forbruket i perioden, aldri «0 av 0».

Basis er **ikke** fallback. En nøkkel som ikke finnes i katalogen gir `unconfigured`.

### AI-kapasitetsnivåer (plassholdere)

| Nøkkel | Internt navn | Enheter/mnd | Status |
|---|---|---|---|
| `level_1` | Level 1 (placeholder) | 1 000 | teknisk plassholder |
| `level_2` | Level 2 (placeholder) | 2 500 | teknisk plassholder |
| `level_3` | Level 3 (placeholder) | 5 000 | teknisk plassholder |

- Nøytrale nøkler med vilje; kommersielle navn, antall nivåer og enheter besluttes senere.
- `active: false` skjuler nivået i admin, men en kunde som allerede har det beholder det.
- Ingen pris på nivåene og ingen Stripe-pris for AI-kapasitet ennå. Når prisen besluttes: egen `BillingProduct`/Stripe price per nivå, koblet til nøkkelen.

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

Anbefaling (ikke en runtime-regel): samle minst **14 dager**, helst **én full faktureringsperiode** for representative kunder, før inkludert kapasitet og `nok_per_unit` låses. Rapporten viser valgt nivå, inkluderte enheter og kilde (`override` / `tier` / `unconfigured`) per kunde. Systemet justerer aldri `nok_per_unit`, nivåene eller operasjonsestimater selv. Dette bestemmes eksplisitt.
