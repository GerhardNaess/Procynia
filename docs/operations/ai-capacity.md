# Felles AI-kapasitet

Kort produkt- og driftsnotat. Ingen tall her er kommersielle beslutninger.

## Produktmodell (låst)

**Basis + opsjoner + AI-kapasitet.** Én felles AI-pool per kunde som alle AI-funksjoner i alle moduler bruker.

- **Basis** er grunnproduktet: Wiki, Kvalitet, Avvik og forbedringer.
- **Opsjoner** gir funksjonalitet: Risiko, Mål og KPI, Etterlevelse og revisjon, Leverandøroppfølging, Anbud. Styring / ISO / GRC er bare bestillingssnarveier.
- **AI-kapasitet** = kundens **grunnkapasitet** × kundens valgte **nivå**.
  - Grunnkapasiteten beregnes fra faste vekter for Basis, antall aktive brukere og hver aktiv opsjon. Vektene **dimensjonerer** den ene poolen; ingen modul får egen pool eller kvote.
  - Nivået (Nivå 1/2/3) er kundens kommersielle valg, gjort på Abonnement. Det er en multiplikator, ikke et fast antall enheter.
- **Gamle abonnementsplaner** (free/pro/max/ultra/enterprise) er ikke kilde til AI-kapasitet. De lever under panseret for brukergrenser, Anbud AI-sak-kvoten (`included_ai_credits`), Stripe legacy mapping og admin.

**Vekter, multiplikatorer og priser er ikke endelig fastsatt.** Alle verdier i `config/ai_customer_capacity.php` er manuelt kalibrerte plassholdere.

## Formel

```
grunnkapasitet/mnd = base.basis (hvis Basis er aktiv)
                   + base.per_user × aktive brukere
                   + Σ base.options[<pakke>] for aktive opsjoner
inkludert/mnd      = round(grunnkapasitet/mnd × tiers.<nivå>.multiplier)   (halv opp, hele enheter)
inkludert/periode  = inkludert/mnd × 12 for årlig periode, × 1 ellers
```

- Aktive brukere = `BillingEntitlementService::currentBillableUsers()` (aktive `customer_admin`/`user`).
- Beregningen er tilstandsløs (`AiBaseCapacityCalculator` + `CustomerAiCapacityService`): ny bruker, fjernet bruker, bestilt eller avbestilt opsjon endrer grunnkapasiteten ved neste lesing. Valgt nivå endres **aldri** av systemet — verken av bruksmønster eller av endringer i brukere/moduler.

### Plassholderverdier (manuelt kalibrert)

| Faktor | AI-enheter/mnd |
|---|---|
| `basis` | 500 |
| `per_user` | 50 |
| `options.risk` | 150 |
| `options.objectives` | 100 |
| `options.compliance` | 150 |
| `options.supplier` | 150 |
| `options.tender` | 400 |

| Nivå | Kunden ser | `multiplier` |
|---|---|---|
| `level_1` | Nivå 1 | 1,00 (standard, `default_tier`) |
| `level_2` | Nivå 2 | 1,50 |
| `level_3` | Nivå 3 | 2,00 |

Kunden ser aldri vekter, multiplikator, `nok_per_unit`, kostnad, tokens eller modell — bare nivå og AI-enheter (inkludert, brukt, gjenstår).

## Kilde for inkludert kapasitet

`CustomerAiCapacityService::resolveIncluded()`, i denne rekkefølgen (`source` i payload og rapport):

1. `override` — `customers.included_ai_units`: eksplisitt kundeoverstyring per faktureringsperiode (Enterprise/særskilt kapasitet). Admin → Kunder → AI-kontroll → «Sett AI-enheter», revisjonslogget. Går foran nivået. Kunden ser «AI-kapasiteten er særskilt konfigurert for virksomheten.» og kan ikke endre nivå.
2. `tier` — grunnkapasitet × multiplikatoren til `customers.ai_capacity_tier`, eller `default_tier` (Nivå 1) når kunden ikke har valgt (eller har en nøkkel som ikke lenger finnes). Merket `is_provisional` så lenge `tiers_provisional` er `true`; kunden ser «AI-kapasiteten er under innfasing, og nivåene kan bli justert.»
3. `unconfigured` — bare når ingen nivå gjelder (ingen `default_tier`). Ingen kommersiell grense: forbruket registreres og observeres (`capacity_verdict = unmetered`). Ikke en del av normal drift.

### Nivåbytte

- Kunden bytter nivå på Abonnement → AI-kapasitet → «Endre nivå». Samme rettighet som å bestille/avbestille opsjoner og si opp abonnementet (`User::canManageCustomerBilling()`). Ikke mulig ved kundeoverstyring.
- Admin kan også sette nivå (AI-kontroll → «Velg AI-kapasitetsnivå»); tomt felt = standardnivå.
- **Gjelder umiddelbart** for inneværende faktureringsperiode (v1). Forbruk slettes ikke og perioden nullstilles ikke; inkludert kapasitet regnes ut på nytt for hele perioden. Det finnes ingen pris ennå, så det er ingenting å proratere.
- Revisjonslogg: `billing_events` med `event_type = ai_capacity_tier_changed`, `source = ai_cost_control`, aktør, tidspunkt, gammelt/nytt nivå og gammel/ny kapasitet.
- `active: false` skjuler et nivå fra valg, men en kunde som allerede har det beholder det.
- Ingen pris på nivåene og ingen Stripe-pris for AI-kapasitet ennå. Når prisen besluttes: egen `BillingProduct`/Stripe price per nivå, koblet til nøkkelen; kortet og nivåvalget har plass til pris per periode.

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

Anbefaling (ikke en runtime-regel): samle minst **14 dager**, helst **én full faktureringsperiode** for representative kunder, før inkludert kapasitet og `nok_per_unit` låses. Rapporten viser valgt nivå, inkluderte enheter og kilde (`override` / `tier` / `unconfigured`) per kunde. Systemet justerer aldri `nok_per_unit`, vektene, multiplikatorene eller operasjonsestimater selv. Rapporten er bare beslutningsgrunnlag; endringer gjøres manuelt i config.
