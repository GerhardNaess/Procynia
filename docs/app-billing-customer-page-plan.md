# Plan: Opprydding av kundesiden /app/billing

## Mål

Gjør kundesiden `/app/billing` til en ren kundeside for ett firma, ikke en teknisk eller brukerorientert billing-oversikt.

Produktmodellen er firmabasert:

- 1 firma/kunde
- 1 hovedabonnement
- flere brukere under samme abonnement
- felles AI-kvote på firmanivå
- ekstra AI-pakker kjøpes på firmanivå
- eventuelle ekstra brukerpakker kjøpes på firmanivå

Kundesiden `/app/billing` skal kun handle om:

- `Abonnement`
- `Tilleggstjenester`
- `Fakturering`
  - utestående beløp
  - fakturahistorikk

Kundesiden skal ikke vise:

- teknisk betalingskobling
- Stripe/Cashier-status
- brukertilganger
- registrerte brukere
- brukerabonnementer
- Pro/Max/Ultra per bruker

## Kartleggingsfunn

- `/app/billing` bygger i dag på `Customer` snapshots og aktive `CustomerBillingLine`-rader.
- Hovedabonnement kommer fra aktiv baseplanlinje.
- Inkludert AI vises i dag fra `customers.included_ai_credits`.
- Operativ entitlement-logikk prioriterer `billing_prices.included_ai_offers` før customer snapshot og config.
- Ekstra AI-pakker på firmanivå er ikke tydelig modellert ennå.
- Ekstra brukere finnes delvis via `user_seat`.
- `/app/billing` er i dag system-owner-only og må bli en ekte kundeside med riktig tilgangsstyring.

## Faseinndeling

### 1. Gjør `/app/billing` til ekte kundeside med riktig tilgangsstyring

- Tilgangen skal knyttes til kunde/firma og riktig rolle, ikke bare system owner.
- Siden skal kunne brukes av kundeansvarlige uten å eksponere interne admin- eller betalingsdetaljer.
- Denne fasen kommer før all UI-opprydding, slik at den nye siden har riktig målgruppe fra start.

Trolig senere endring:

- `app/Http/Controllers/App/BillingController.php`
- `resources/js/Pages/App/Billing/Index.jsx`
- eventuelt policy/gate eller tilsvarende tilgangssjekk i app-laget
- `tests/Feature/App/BillingControllerTest.php`

### 2. Rydd datakilde for abonnement og inkludert AI-kapasitet

- Hovedabonnement skal utledes fra aktiv baseplanlinje og vises som firmaets primære abonnement.
- Kundeprofilens snapshot-felter skal ikke være førstevalg når aktive billing-linjer finnes.
- Inkludert AI skal ha én tydelig prioritet:
  - først eksplisitte entitlements fra `billing_prices.included_ai_offers`
  - deretter customer snapshot
  - deretter config fallback
- Dette må støtte en firmabasert AI-kvote, ikke per-bruker-planer.
- Ekstra AI-pakker må kunne representeres som firma-tillegg, ikke som brukerabonnementer.

Trolig senere endring:

- `app/Services/Billing/BillingService.php`
- `app/Services/Billing/BillingEntitlementService.php`
- `app/Http/Controllers/App/BillingController.php`
- `tests/Unit/Services/BillingServiceTest.php`
- `tests/Unit/Services/BillingEntitlementServiceTest.php`

### 3. Klassifiser Tilleggstjenester kundevendt

- Tilleggstjenester skal bare vise reelle tillegg utover hovedabonnementet.
- Intern teknikk, Stripe/Cashier-termer og kildeinfo skal skjules.
- Engangstillegg og abonnementstillegg skal beskrives kundevendt.
- Eventuelle ekstra AI-pakker og ekstra brukerpakker skal kunne havne her som firma-tillegg når de er modellert, men uten å vise brukerlister.

Trolig senere endring:

- `resources/js/Pages/App/Billing/Index.jsx`
- `app/Http/Controllers/App/BillingController.php`
- eventuelt `app/Services/Billing/BillingService.php`
- `lang/no/procynia.php`
- `lang/en/procynia.php`

### 4. Vis fakturering kundevendt med utestående beløp og fakturahistorikk

- Fakturering-delen skal vise kundens utestående beløp tydelig.
- Historikken skal vise fakturaer på en måte som er forståelig for kunde.
- Siden skal ikke vise teknisk betalingskobling eller intern provider-status.
- Denne delen skal være lesbar også når kunden ikke har aktivt abonnement.

Trolig senere endring:

- `resources/js/Pages/App/Billing/Index.jsx`
- `app/Http/Controllers/App/BillingController.php`
- eventuelt billing-relaterte views/transformers som bygger invoice payload

### 5. Planlegg firmabasert modell for ekstra AI-pakker, men ikke implementer nå

- Ekstra AI-pakker skal forstås som firmaets samlede kapasitet.
- Når dette implementeres, skal det påvirke kundens totale AI-kvote på tvers av brukere.
- Modellen må støtte kjøp, fornyelse og visning uten å blande inn brukerabonnementer.

Trolig senere endring:

- billing entitlement / subscription model
- `CustomerBillingLine`-payload
- `BillingService` og eventuell ny add-on modell
- admin-flate for drift eller kundeadministrasjon ved behov

### 6. Planlegg eventuell visning av ekstra brukerpakker, men ikke vis registrerte brukere eller brukerrettigheter

- Ekstra brukerpakker skal være en firma-ressurs, ikke en per-bruker planvisning.
- `/app/billing` skal ikke vise registrerte brukere, brukerabonnementer eller detaljerte brukerrettigheter.
- Hvis det senere blir behov for å vise ekstra brukerpakker, skal det være som en firmabasert kapasitet eller add-on.

Trolig senere endring:

- `resources/js/Pages/App/Billing/Index.jsx`
- `app/Http/Controllers/App/BillingController.php`
- `app/Services/Billing/BillingEntitlementService.php`

## Filer som sannsynligvis må endres senere

- `resources/js/Pages/App/Billing/Index.jsx`
- `app/Http/Controllers/App/BillingController.php`
- `app/Services/Billing/BillingService.php`
- `app/Services/Billing/BillingEntitlementService.php`
- `lang/no/procynia.php`
- `lang/en/procynia.php`
- `tests/Feature/App/BillingControllerTest.php`
- `tests/Unit/Services/BillingServiceTest.php`
- `tests/Unit/Services/BillingEntitlementServiceTest.php`

## Tester som må kjøres senere

- `docker compose exec -T app php artisan test tests/Feature/App/BillingControllerTest.php`
- `docker compose exec -T app php artisan test tests/Unit/Services/BillingServiceTest.php`
- `docker compose exec -T app php artisan test tests/Unit/Services/BillingEntitlementServiceTest.php`

Testene bør senere kontrollere at:

- hovedabonnement vises riktig når det finnes
- `Ingen aktivt abonnement` bare vises når det faktisk mangler abonnement
- `Tilleggstjenester` ikke viser hovedabonnementet
- fakturering viser utestående beløp og historikk kundevendt
- kundevisningen ikke lekker intern billing-terminologi
- registrerte brukere og brukerrettigheter ikke vises som del av kundesiden

## Avgrensning

- Denne planen gjelder kun kundesiden `/app/billing`.
- Den skal ikke påvirke admin/Filament.
- Den skal ikke endre datamodell, Stripe/Cashier, billing service-logikk eller kommersiell logikk før planlagt kodeoppgave.
- Den skal ikke foreslå commit i seg selv.
- Den er kun et plan- og avklaringsdokument for senere opprydding.
