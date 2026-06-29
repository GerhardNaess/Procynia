# Plan: Opprydding av kundesiden /app/billing

## Mål

Gjør kundesiden `/app/billing` tydelig nok til at en kunde forstår forskjellen på:

- `Abonnement` som hovedplan
- `Tilleggstjenester` som ekstra kostnader eller tjenester utover hovedplanen
- `Brukertilganger` som hvilke brukere som har tilgang til funksjoner og tjenester

Siden skal være enkel å lese, og den skal ikke blande hovedabonnement, tillegg og brukertilganger i samme visning.

## Feil som planen skal dekke

1. `Ingen aktivt abonnement` vises samtidig som `Ultra årlig` ser ut som en aktiv linje.
2. `Ultra årlig` vises under `Tilleggstjenester`, selv om det i praksis ser ut som kundens hovedabonnement.
3. Knappen `Endre abonnement` vises selv når kunden sies å ikke ha aktivt abonnement.
4. `Tilleggstjenester` blander hovedabonnement og tilleggstjenester.
5. Kolonnen `Kilde` er intern og bør ikke vises til kunde.
6. `Beskrivelse` gjentar bare navnet og gir liten eller ingen kundeverdi.
7. Engangstillegg vises som `Aktiv`, som er uklart for kunden.
8. `Brukertilganger` har for mange kolonner.
9. `Tjeneste` og `Tilgang` i `Brukertilganger` sier nesten det samme.
10. `Tildelt av` er intern informasjon og bør trolig ikke vises til kunde.
11. Pris-/periodeord som `månedlig` lekker inn i `Brukertilganger`.
12. Siden forklarer ikke tydelig forskjellen på `Abonnement`, `Tilleggstjenester` og `Brukertilganger`.
13. Bekreftelsesdialogen for abonnementsendring gjentar samme budskap og forklarer ikke tydelig hva som faktisk endres.
14. Infohint-tekster bruker ulik typografi og blir for dominerende når de vises i caps lock.

Viktigste hovedfeil:

- `Ultra årlig` må ikke vises som `Tilleggstjeneste` hvis det egentlig er kundens hovedabonnement.

## Prinsipper for ny visning

- Kundesiden skal bruke korte, tydelige begreper.
- Hovedabonnement skal stå tydelig separat fra andre tjenester.
- Tilleggstjenester skal bare vise reelle tillegg utover hovedplanen.
- Brukertilganger skal vise hvem som har tilgang, ikke intern billing- eller betalingsdetalj.
- Bekreftelsesdialogen for abonnementsendring skal være kort og konkret: den skal forklare at endringen oppdaterer abonnementet og kan påvirke hvilke tilleggstjenester og brukertilganger som er aktive, uten å gjenta samme melding flere ganger.
- Intern teknikk som `Kilde`, Stripe-id-er, billing-begreper og betalingsprovider-termer skal skjules når de ikke er nødvendige for kunden.
- Engangstillegg skal beskrives som type tillegg, ikke som egen hovedkategori.
- Infohint og hjelpetekster skal bruke normal setningstekst og samme typografi på hele siden, slik at de støtter innholdet uten å dominere visuelt.

## 1. Abonnement

Abonnement-delen skal vise kundens hovedplan, og bare den.

- Hovedabonnementet må identifiseres fra den mest autoritative kundedataen, ikke bare fra aktive linjer.
- Dersom kunden har et faktisk abonnement, skal det vises her som hovedabonnement, for eksempel `Ultra årlig`.
- Dersom kunden ikke har et abonnement, skal teksten `Ingen aktivt abonnement` bare vises når det faktisk ikke finnes et aktivt abonnement.
- Knappen `Endre abonnement` skal bare vises når det finnes et abonnement som kan endres, eller når vi bevisst vil tilby opprettelse av nytt abonnement.
- `Abonnement`-delen skal ikke gjenta tilleggstjenester eller brukertilganger.
- Tekster som nevner teknisk betalingsløsning skal kun brukes hvis de hjelper kunden å forstå abonnementets status, ikke som hovedbegrep.
- Endringsdialogen for abonnement skal vise selve abonnementet, faktureringsperioden og relevante betingelser som inkluderte brukere og eventuelle andre planvilkår før endringen bekreftes.
- Valgsteget i abonnementsdialogen skal oppdatere samme informasjonsboks når brukeren velger ny plan, slik at kunden ser detaljene for valgt abonnement uten at en ekstra boks dukker opp under.

Trolig senere endring:

- `resources/js/Pages/App/Billing/Index.jsx` må få tydeligere skille mellom `subscription` og øvrige kundelinjer.
- `app/Http/Controllers/App/BillingController.php` kan måtte levere et mer strukturert payload for hovedabonnement.
- `app/Services/Billing/BillingService.php` og `app/Services/Billing/BillingEntitlementService.php` kan måtte brukes til å avgjøre hva som faktisk er hovedabonnementet.

## 2. Tilleggstjenester

Tilleggstjenester skal bare vise ting som kommer i tillegg til hovedabonnementet. I UI skal denne delen hete `Tilleggstjenester`, ikke `Tillegg`.

- Hovedabonnementet må filtreres bort fra denne listen.
- Dersom `Ultra årlig` er hovedabonnementet, skal det ikke vises som tilleggstjeneste.
- Kolonnen `Kilde` skal fjernes fra kundevisningen.
- `Beskrivelse` skal fjernes dersom den bare gjentar tjenestenavnet, eller erstattes med en faktisk nyttig forklaring.
- Engangstillegg skal merkes tydelig som `Engang`, ikke bare som `Aktiv`.
- Tilleggstjenester skal bruke kundevendte ord som forklarer hva tjenesten er og hva kunden får.
- Intern billing-terminologi skal ikke vises i tabellen.

Trolig senere endring:

- `resources/js/Pages/App/Billing/Index.jsx` må bygge en egen visning for reelle tilleggstjenester.
- `BillingController` kan måtte sende en allerede filtrert liste med tilleggstjenester.
- Eventuelt må `BillingService` eller `BillingEntitlementService` hjelpe til med å skille hovedabonnement fra tillegg.

## 3. Brukertilganger

Brukertilganger skal vise hvem som har tilgang til tjenester eller nivåer, og ikke mer enn nødvendig.

- Tabellen bør ha færre kolonner enn i dag.
- `Tjeneste` og `Tilgang` bør ikke stå som to nesten like kolonner.
- `Tildelt av` bør fjernes fra kundevisningen dersom den bare er intern kontrollinformasjon.
- Pris-/periodeord som `månedlig` skal ikke vises her.
- Brukertilganger skal forklare aktiv tilgang, ikke intern produktstruktur.
- Det bør være tydelig hvilken bruker tilgangen gjelder for, og hva tilgangen faktisk er.

Trolig senere endring:

- `resources/js/Pages/App/Billing/Index.jsx` må redusere kolonner og forenkle radinnholdet.
- `app/Http/Controllers/App/BillingController.php` kan måtte levere en enklere og tydeligere `service_levels`-payload.
- `lang/no/procynia.php` og `lang/en/procynia.php` må justeres slik at språk og kolonner matcher den nye visningen.

## 4. Filer som sannsynligvis må endres senere

- `resources/js/Pages/App/Billing/Index.jsx`
- `app/Http/Controllers/App/BillingController.php`
- `app/Services/Billing/BillingService.php`
- `app/Services/Billing/BillingEntitlementService.php`
- `lang/no/procynia.php`
- `lang/en/procynia.php`
- `tests/Feature/App/BillingControllerTest.php`
- `tests/Unit/Services/BillingServiceTest.php`
- `tests/Unit/Services/BillingEntitlementServiceTest.php`

## 5. Tester som må kjøres senere

- `docker compose exec -T app php artisan test tests/Feature/App/BillingControllerTest.php`
- `docker compose exec -T app php artisan test tests/Unit/Services/BillingServiceTest.php`
- `docker compose exec -T app php artisan test tests/Unit/Services/BillingEntitlementServiceTest.php`

Testene bør senere kontrollere at:

- hovedabonnement vises riktig når det finnes
- `Ingen aktivt abonnement` bare vises når det faktisk mangler abonnement
- `Ultra årlig` ikke havner som tillegg hvis det er hovedabonnement
- kolonnen `Kilde` er borte
- `Brukertilganger` har færre og tydeligere kolonner
- kundevisningen ikke lekker intern billing-terminologi

## Avgrensning

- Denne planen gjelder kun kundesiden `/app/billing`.
- Den skal ikke påvirke admin/Filament.
- Den skal ikke endre datamodell, Stripe/Cashier, billing service-logikk eller kommersiell logikk.
- Den skal ikke foreslå commit i seg selv.
- Den er kun et plan- og avklaringsdokument for senere opprydding.
