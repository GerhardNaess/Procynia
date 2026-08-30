# AI cost control — driftsrunbook

Kort nok til å brukes under en hendelse. Full arkitektur og begrunnelser står i
[`ai-usage.md`](ai-usage.md).

---

## 1. To lag, aldri blandet

| | Kommersiell kvote | Operasjonelt budsjett |
| --- | --- | --- |
| Enhet | AI-saker (credits) | NOK providerkostnad |
| Beskytter | at kunden får det de kjøpte | Procynias egen økonomi |
| Synlig for | kunde og admin | **kun** intern admin |
| Stopper unlimited-plan | nei | **ja** |
| Stopper allerede aktivert sak | nei | **ja** |

Én credit = én unik AI-aktivert SavedNotice per kunde per kalendermåned.

---

## 2. Hvorfor er AI stoppet? — diagnoserekkefølge

Guarden evaluerer i denne rekkefølgen. Den **første** som treffer er årsaken.

| # | Årsakskode | Betyr | Hvor du fikser det |
| --- | --- | --- | --- |
| 1 | `AI_GLOBAL_STOP` | manuell nødstopp er på | Drift → AI-bruksmønster → *Deaktiver global AI-stopp* |
| 2 | `AI_MODEL_PRICE_UNKNOWN` | modellen mangler pris | `php artisan ai:sync-model-prices` |
| 3 | `AI_GLOBAL_DAILY_BUDGET_EXHAUSTED` / `..._MONTHLY_...` | plattformens NOK-tak er nådd | Drift → AI-bruksmønster → *Sett globalt NOK-budsjett* |
| 4 | `AI_CUSTOMER_DAILY_BUDGET_EXHAUSTED` / `..._MONTHLY_...` | kundens NOK-tak er nådd | Kunder → (kunde) → AI-kontroll → *Sett NOK-sikkerhetsbudsjett* |
| 5 | `AI_PAYMENT_UNPAID` / `AI_PAYMENT_INCOMPLETE` | abonnementsstatus | Stripe + Abonnement-siden |
| 6 | `AI_CUSTOMER_SUSPENDED` | kunden er suspendert | Kunder → (kunde) → AI-kontroll → *Gjenopprett AI* |
| 7 | `AI_NOT_INCLUDED` | planen har ikke AI | endre abonnement |
| 8 | `AI_QUOTA_EXHAUSTED` | periodens AI-saker er brukt | AI-kontroll → *Endre AI-kapasitet*, eller vent på ny måned |

Kunden ser aldri kode 1–4 som teknisk detalj — bare «AI er midlertidig utilgjengelig».

**Rask status:**

```bash
php artisan ops:runtime-check          # skjema, singleton, priskatalog, FX, config
php artisan ai:runtime-control status  # global stopp + suspenderte kunder
php artisan ai:cost-control-health     # usikre hold + upriset forbruk
```

---

## 3. «Alle kunder er blokkert»

Sjekk i denne rekkefølgen:

1. **Manuell global stopp?** → `ai:runtime-control status`. Slå av med
   `php artisan ai:runtime-control global-resume --actor=<id> --reason="..."` eller i admin-UI.
2. **Globalt NOK-budsjett nådd?** → Drift → AI-bruksmønster viser rødt banner med påløpt/grense.
3. **Tom priskatalog?** → `ops:runtime-check` gir FAIL på *AI model pricing* når budsjett-håndheving
   er på. Kjør `php artisan ai:sync-model-prices`.
4. **Skjema ikke migrert?** → `ops:runtime-check` gir FAIL på *AI cost control schema*. Guarden
   feiler da med databasefeil, ikke kontrollert stopp. Kjør migrasjonene.
5. **FX?** FX stopper aldri AI alene — manglende kurs gir konservativ fallback, ikke blokkering.

---

## 4. «Én kunde er blokkert»

```bash
php artisan ai:runtime-control status
```

Åpne **Kunder → (kunde) → AI-kontroll**. Siden viser begge lag side om side:

- **Kommersiell kvote:** inkludert, ekstra, brukt, reservert, gjenstående, periode, status.
- **Operasjonelt budsjett:** daglig/månedlig grense, påløpt, reservert, ukjent-antall, prosent.
- **Betalingsstatus** og hva den betyr for AI-tilgang.
- **Revisjonsspor:** hvem gjorde hva, når, med hvilken begrunnelse.

Handlinger (alle krever begrunnelse og logges med aktør):

| Situasjon | Handling |
| --- | --- |
| kvoten er brukt opp, kunden skal ha mer | *Endre AI-kapasitet* (+N) |
| kapasitet gitt ved en feil | *Endre AI-kapasitet* (−N) — historikk slettes aldri |
| NOK-taket er for lavt | *Sett NOK-sikkerhetsbudsjett* |
| kunden ble suspendert ved en feil | *Gjenopprett AI* |
| kunden må stoppes nå | *Suspender AI* |

**Merk:** en allerede aktivert SavedNotice bruker ikke ny credit og kan arbeides videre med selv når
kvoten er tom — men den kan fortsatt stoppes av et NOK-budsjett. Det er tilsiktet.

---

## 5. Betalingsblokkering

| Stripe-status | AI |
| --- | --- |
| `active`, `trialing` | tillatt |
| `past_due` | tillatt i grace (standard 7 dager fra faktisk overgang), deretter blokkert |
| `unpaid` | blokkert |
| `incomplete`, `incomplete_expired` | blokkert |
| `canceled` | ingen betalingsblokk — plan/entitlement avgjør |
| ingen Stripe-kobling | ingen betalingsblokk — lokal plan avgjør |

Grace måles fra `subscriptions.updated_at` da abonnementet gikk til `past_due` — ikke fra «nå», så
den restartes ikke ved hvert kall. Mangler tidsstempelet, gis grace (bevisst fail-safe).

Kunden er allerede varslet av `invoice.payment_failed`-webhooken. Ikke send en ekstra e-post manuelt.

---

## 6. Ukjent modellpris

Symptom: `AI_MODEL_PRICE_UNKNOWN` og intern varsling `ai_model_price_missing`.

```bash
php artisan ai:sync-model-prices
php artisan ops:runtime-check   # verifiser at katalogen nå er konfigurert
```

Haster det å fullføre en fastlåst kjøring før prisen er på plass, kan en operatør overstyre:

```bash
php artisan wiki:recover-document-flow --run-id=<id> \
  --cost-control-override --actor=<intern super admin> --override-reason="..."
```

Kostnaden registreres da fortsatt som `unknown`, aldri som 0.

---

## 7. Gammel eller manglende valutakurs

FX stopper aldri AI. Politikken er konservativ, ikke blokkerende:

| Tilstand | Handling |
| --- | --- |
| < 3 dager | kurs brukes som den er |
| ≥ 3 dager | kurs brukes, padres 10 % |
| ≥ 14 dager | som over + intern varsling |
| ingen kurs registrert | fast fallback (12,0) + margin, kritisk varsling |

```bash
php artisan exchange-rates:sync
```

Fallback-kursen er en **sikkerhetsverdi for budsjettformål**, ikke en rapporteringssannhet. Ikke bruk
den i kundekommunikasjon.

---

## 8. Usikre reservasjoner

Et kall som fikk timeout eller 5xx kan ha kostet penger. Reservasjonen blir da stående som
`uncertain` og **frigis aldri automatisk** — det er tilsiktet fail-safe.

`ai:cost-control-health` kjører hver time og varsler når slike hold er eldre enn 24 timer.

Ved vurdering:

1. Finn reservasjonen (kunde, sak, `reserved_at`, `failure_reason`).
2. Sjekk `ai_usage_attempts` for samme periode: fikk kallet `provider_request_id`? Da nådde det
   leverandøren og kostet sannsynligvis penger.
3. Kostet det penger → la holdet stå, eller gi kunden kompenserende kapasitet med
   *Endre AI-kapasitet*.
4. Kostet det ikke penger → gi kompenserende kapasitet. **Ikke** rediger reservasjonsraden;
   historikken er append-only.

Det finnes bevisst ingen «frigi»-knapp: hva et usikkert kall faktisk kostet er en menneskelig
vurdering.

---

## 9. Operatørkommandoer

Alle fem setter kundekontekst og respekterer alle guards som standard:

```
wiki:generate-applied-pages   wiki:verify-page-claims   wiki:recover-document-flow
wiki:maintainer-decision      wiki:inspect-requirement-answer
```

Overstyring krever begge deler, og aktør må være intern super admin (`customer_id = null`):

```bash
--cost-control-override --actor=<id eller e-post> --override-reason="..."
```

Overstyring kan lette: entitlement, kommersiell kvote, kundesuspensjon, betalingsblokk, ukjent pris.

Overstyring kan **aldri** lette: manuell global stopp, globalt daglig budsjett, globalt månedlig
budsjett. Hver bypass skriver `ai_operator_override_used` med aktør og begrunnelse.

---

## 10. Rollback

| Situasjon | Rollback |
| --- | --- |
| ny grense blokkerer feil | sett grensen tilbake i admin — trer i kraft ved neste kall, ingen deploy |
| global budsjettstopp var for stram | hev grensen, eller slå av `operational_budget_enabled` |
| suspend var feil | *Gjenopprett AI* |
| kapasitetsjustering var feil | ny motsatt justering — aldri redigering av gammel rad |
| kostnadskontrollen må av helt | slå av `operational_budget_enabled`; kommersiell kvote og kill switches står igjen |

Ingen av disse krever deploy eller omstart. Alle skriver revisjonsspor.

---

## 11. Eskalering

1. Kunde blokkert feil → intern admin med super admin-tilgang.
2. Alle kunder blokkert → sjekk seksjon 3 før noe annet.
3. Mistenkt kostnadslekkasje → aktiver manuell global stopp først, undersøk etterpå:
   ```bash
   php artisan ai:runtime-control global-stop --actor=<id> --reason="..."
   ```
   Den kan ikke omgås av noen kodevei, inkludert operatøroverstyring.
