# Plan: Universell utforming (WCAG 2.2 AA)

**Status: Fase 0 er ferdig. Fase 1 er committet (`4c594ebf`) og Fase 2 er committet (`683246a7`); manuell verifisering med skjermleser, Firefox og Safari gjenstår for begge (§15.8, §16.8). Fase 3 er implementert, automatisk verifisert, teknisk sluttkontrollert (§17.12) og committet (`5559fcb9`); manuell verifisering gjenstår, og OPPF-001 (én eksisterende E2E-feil) må lukkes før merge. Fase 4 er implementert, automatisk verifisert, teknisk sluttkontrollert (§18.14) og committet (`8240951e`). Fase 5 er implementert, automatisk verifisert, sluttkontrollert (§19.17) og committet; manuell verifisering gjenstår. OPPF-001 ble lukket i Fase 5; OPPF-002 og OPPF-003 er åpne og må løses før merge. Fase 6 er implementert, automatisk verifisert, sluttkontrollert (§20.16) og committet; manuell verifisering gjenstår. OPPF-001 ble gjenåpnet. Fase 7A (teknisk opprydding) er gjennomført 2026-10-16 (§21): OPPF-001–004 er rettet (OPPF-001 lukkes formelt etter samlet regresjon i 7C), ni WCAG-funn er rettet, resten er klassifisert. Ikke committet. Fase 7B, første manuelle runde (Safari, VoiceOver, zoom), er dokumentert (§22) uten nye feil; Utviklingsarbeidet er avsluttet 2026-10-16 etter prosjektbeslutning (§23): Fase 7C gjennomføres ikke, full regresjon på endelig kode er ikke kjørt, OPPF-001 er ikke formelt lukket, og åpne funn står åpne. Full WCAG 2.2 AA-etterlevelse er ikke dokumentert.**
Branch: `feature/wcag-accessibility`
Utgangspunkt: `bc9070c2` (2026-10-14)
Sist oppdatert: **2026-10-16**

Dette dokumentet er den styrende planen for tilgjengelighetsarbeidet. Det oppdateres når en fase
startes, fullføres eller endres. Funn-ID-ene (`A11Y-…`) er stabile og brukes i commits, tester og
statusoppdateringer.

---

## 1. Mål og omfang

### 1.1 Mål

Hele Procynia skal oppfylle **WCAG 2.2 nivå A og AA**. Dagens design, funksjonalitet, effektivitet og
arbeidsflyt skal beholdes. Procynia skal fortsatt være enkel og rask å bruke. Tilgjengelighet skal
oppnås gjennom bedre semantikk, fokusstyring og kontrast, ikke gjennom redesign.

### 1.2 Omfang

- Kundeflaten `/app` (Inertia + React): alle moduler.
- Offentlige sider (`/`, `/funksjoner`, `/priser`, `/sikkerhet`, `/kontakt`, `/faq`, `/betingelser`,
  `/personvern`, `/registrer`) og innlogging (`/login`).
- Administrasjonspanelet `/admin` (Filament 5): egne Blade-visninger og panelkonfigurasjon. Filaments
  kjernekomponenter regnes som leverandørkode, men de verifiseres med axe.
- Genererte dokumenter: utskriftsvisning og PDF for Ledelsens gjennomgåelse.

### 1.3 Rammer (gjelder alle faser)

Arbeidet skal ikke endre:

- forretningsprosesser, autorisasjon eller tilgangsstyring;
- API-er, ruter, databaseskjema eller lagrede data;
- funksjonalitet (ingen handlinger fjernes);
- det visuelle uttrykket mer enn nødvendig (kontrast og fokus kan gi små, synlige justeringer);
- ytelsen.

Dette er også rammer for arbeidet:

- Nye avhengigheter skal bare være utviklingsverktøy (se §9). Ingen nye komponentbiblioteker i
  produksjonskoden.
- Felles komponenter forbedres før sidene. Samme feil skal ikke rettes 40 ganger.
- Nye UI-strenger følger i18n-mønsteret i `CLAUDE.md` (no + en, norsk fallback).

---

## 2. Fasestatus

| Fase | Innhold | Status | Commit(s) |
|---|---|---|---|
| 0 | Kartlegging, funn og plan (dette dokumentet) | Ferdig 2026-10-14 | — |
| 1 | Felles komponenter og designregler | Implementert, automatisk verifisert og teknisk sluttkontrollert 2026-10-14. Manuell verifisering gjenstår (§15.8). | `4c594ebf` |
| 2 | Navigasjon, layout og standardvisninger | Implementert, automatisk verifisert og teknisk sluttkontrollert 2026-10-14 (§16.12). Manuell verifisering gjenstår (§16.8). | `683246a7` |
| 3 | Skjema, tabeller, dialoger og varsler | Implementert, automatisk verifisert og teknisk sluttkontrollert 2026-10-15 (§17.12). Manuell verifisering gjenstår (§17.8). Åpen før merge: OPPF-001. | `5559fcb9` |
| 4 | Anbud, dokumenter og AI-arbeidsflyter | Implementert, automatisk verifisert og teknisk sluttkontrollert 2026-10-15 (§18.14). Manuell verifisering gjenstår (§18.9). | `8240951e` |
| 5 | Enterprise Wiki og editor | Implementert, automatisk verifisert og sluttkontrollert 2026-10-16 (§19.17). Manuell verifisering gjenstår (§19.12). Åpne før merge: OPPF-002, OPPF-003. | `feat(a11y): improve enterprise wiki accessibility` |
| 6 | Kvalitet/GRC, administrasjon og resterende flater | Implementert, automatisk verifisert og sluttkontrollert 2026-10-16 (§20.16). Manuell verifisering gjenstår (§20.11). Åpne før merge: OPPF-001 (gjenåpnet), OPPF-002, OPPF-003, OPPF-004. | `feat(a11y): improve GRC and admin accessibility` |
| 7 | Samlet testing, regresjon og dokumentasjon | 7A (teknisk opprydding) gjennomført og automatisk verifisert 2026-10-16 (§21), ikke committet. 7B: første manuelle runde dokumentert 2026-10-16 (§22). 7C gjennomføres ikke (prosjektbeslutning, §23). | — |

---

## 3. Kartlagt arkitektur

| Område | Funn |
|---|---|
| Frontend | Inertia.js (`@inertiajs/react` ^3) + React 19 + Tailwind CSS 4 (`@tailwindcss/vite`). Sider lastes eagerly fra `resources/js/Pages/**` i `resources/js/app.js`. |
| Andre frontendbiblioteker | `react-markdown` + `remark-gfm` (AI-svar), `sigma` + `graphology` (Wiki-graf, canvas/WebGL). Ingen UI-komponentbibliotek (ingen Headless UI, Radix eller lignende). |
| Layouter | `resources/js/Layouts/CustomerAppLayout.jsx` (all `/app`), `resources/js/Layouts/PublicLayout.jsx` (offentlige sider). `Auth/Login.jsx` har ingen layout. |
| Felleskomponenter | `resources/js/Components/App/*` (34 komponenter, se §5). |
| Designregler | Ingen design-tokens. `resources/css/app.css` inneholder bare font og `.wiki-article`-typografi. Farger, fokus og skjemastiler ligger som Tailwind-klasser lokalt i hver fil. Det eneste delte stilsettet er `resources/js/Support/actionStyles.js` (knapperoller, 111 importer). `tailwind.config.js` er en v3-rest uten tokens. |
| Skjemastiler | `INPUT`/`LABEL`/`HINT`/`ERROR`-konstanter defineres lokalt i ca. 60 filer. |
| HTML-skall | `resources/views/app.blade.php`. `<html lang>` settes fra `app()->getLocale()` (`SetCustomerLocale`). Viewport tillater zoom. Tittel via `<title inertia>` + `<Head>` i layout. |
| Sidetitler | `CustomerAppLayout` setter `<Head title>`, og alle 53 sidene i layouten sender `title`. Det samme gjør `PublicLayout` og `Login`. **Fase 2 fant at titlene likevel aldri ble vist** (A11Y-129): `app.blade.php` hadde `<title inertia>`, Inertia v3 skriver `<title data-inertia>`, og nettleseren brukte den første. Rettet i Fase 2. |
| Administrasjon | Filament 5 (`app/Providers/Filament/AdminPanelProvider.php`), primærfarge `Color::Amber`, standard mørk modus. 18 sider og 14 ressurser. 23 egne Blade-visninger (ca. 5 200 linjer) i `resources/views/filament/**`. |
| PDF | `resources/views/management-review/report.blade.php` rendres med dompdf, og PDF-en er ikke tagget. Utskriftsvennlig HTML finnes i `Pages/App/ManagementReview/Report.jsx`. |
| Tester | PHPUnit (`composer test`, Docker). `node --test` for `resources/js/**/*.test.js` (85 filer, logikk). Playwright (`tests/e2e`, 95 spesifikasjoner, bare Chromium, `localhost:8080`). |
| A11y-testing etter Fase 1 | `@axe-core/playwright` (`tests/e2e/helpers/a11y.js`, scoped axe + fokusindikator), `eslint-plugin-jsx-a11y` (`npm run lint:a11y`, filliste i `eslint.config.js`, kjøres i CI-jobben `dependency-audit.yml`), `tests/e2e/a11y-foundation.spec.js`. |
| Eksisterende a11y-testing (før Fase 1) | Ingen regelmotor (axe, pa11y, Lighthouse). `tests/e2e/helpers/readability.js` sjekker minimum 16 px tekst og fravær av horisontal scroll på 1440 og 390 px (37 spesifikasjoner). Rolle-/navn-/fokusassertions finnes spredt (`getByRole` i 88 filer, `toBeFocused` i 10). |
| Linting | Ingen ESLint. Pint brukes bare for PHP. |
| CI | Bare `.github/workflows/dependency-audit.yml` (composer audit, `npm run test:unit`, npm audit). Ingen bygg, ingen PHP-tester og ingen E2E i CI. |

---

## 4. Brukerflater

Alle modulene under er implementert. Ingen sider i kodebasen er bare plassholdere. Planlagte moduler
vises i modulmenyen som ikke-klikkbare elementer (`ModuleSidebar.jsx`). Fakturering via Stripe/Cashier
er bygget for kundeflaten (`Billing`).

| Flate | Sider (`resources/js/Pages/…`) | Viktige mønstre |
|---|---|---|
| Innlogging | `Auth/Login.jsx` | Skjema, Entra-innlogging |
| Offentlige sider | `Public/*` (9) | Markedsføring, FAQ-trekkspill, registreringsskjema. Security, Privacy og Terms er utkast. |
| Hjem og dashboard | `App/Home`, `App/Dashboard` (+ `DashboardCockpit`), `App/Governance` | Modulkort, nøkkeltall, fristkalender |
| Oppfølging | `App/InfoCenter/*` (Oppfølging, Mine oppgaver) | Filtergrupper, oppgaveliste, paginering |
| Varsler | `NotificationBell` (header) | Panel, 60 s polling |
| Anbud | `App/Notices/*` (Index 3085 linjer, SavedShow 2464), `GoNoGoAssessment`, `CpvSelector`, `App/WatchProfiles/*`, `App/GoNoGo/Templates/*`, `App/Suppliers/*` | Livesøk, saker, Go/No-go-vurdering, CPV-kombinasjonsboks, egne modaler |
| AI-arbeidsflate | `App/AI/*` (Show 3982 linjer) | Dokumentopplasting, kravuttrekk med polling, svargenerering (axios, opptil 120 s), vurdering, proveniensmerker |
| Enterprise Wiki | `App/Wiki/*` (Index 3555, Show 3303, Graph 1465, Ask, WikiReviewPanel) | Kildedokumenter, kjøringer med polling, artikkel, påstander/QA, godkjenning og publisering, graf (Sigma) |
| Wiki-«editor» | `Wiki/Show.jsx` | Ingen rikteksteditor. Redigering skjer i rene Markdown-`textarea`-er per avsnitt, i enkeltblokk fra funn og i påstandstekst. AI-svar redigeres også i `textarea`. |
| Kvalitet | `App/Quality/*`, `ProcessFlowPanel`, `ProcessSwimlaneDiagram`, `ProcessFlowStepList` | SVG-prosessdiagram, strukturtabeller, AI-forslag |
| Risiko | `App/Risk/*` | Register, vurdering, tiltak, aksept |
| Mål og KPI | `App/Objectives/*` | Veiviser, `UnitInput`, SVG-trend |
| Avvik og forbedringer | `App/Improvements/*` | Saker, tiltak, effektverifisering |
| Etterlevelse og revisjon | `App/Compliance/**` | Kravregister, bibliotek (trekkspill), revisjon med ARIA-faner |
| Ledelsens gjennomgåelse | `App/ManagementReview/*` | Seksjonsnavigasjon, rapport/PDF |
| Leverandøroppfølging | `App/SupplierManagement/**` (52 filer) | Profilfaner, kontrollkrav, dokumenter, Excel-import |
| Kundemiljø / tilganger | `App/CustomerEnvironment/*`, `App/Users/*`, `App/Departments/*` | Tilgangsmatrise med autolagring, roller, fagområder |
| Abonnement | `App/Billing/*` | Pakker, AI-kapasitet, fakturaer, egne bekreftelsesdialoger |
| Administrasjon | Filament `/admin` | Ressurser (kjerne), egne driftssider (systemstatus, AI-forbruk, høsting, backup, hendelser) |

---

## 5. Felles komponenter

| Komponent | Bruk | Vurdering |
|---|---|---|
| `ActionDialog` | 13 filer | **God referanse.** Har `role=dialog`, `aria-modal`, `aria-labelledby`, fokusfelle, Escape og fokusretur. Mangler: z-index under headeren (`z-50` mot header `z-[60]`), ingen reservemål for fokus når utløseren forsvinner, og ingen størrelsesvariant. |
| `Breadcrumbs` | Layout | God (`nav` + `ol` + `aria-current`). |
| `SearchableMultiSelect` | 3 | God combobox/listbox. Svak fokusmarkering, og treffantall annonseres ikke. |
| `StatusBadge`, `EmptyStateBox`, `FormButtonRow` | 54 / 16 / 4 sider | Tekstbaserte, kontrast OK. |
| `AlertBox` | 6 | Har ingen rolle, noe som er et problem når boksen brukes til dynamiske feil. |
| `ModuleSidebar` | Layout | God navigasjon og `aria-current`. Ikonknapper har lav kontrast. Sammenslått meny er bare merket med `title`. |
| `PageHelpButton` / `PageHelpPanel` | 46 filer | Fungerer, men fokus flyttes ikke inn i panelet, fokusringen er svak, og strengene er hardkodet på norsk. |
| `InfoHint` / `ControlHint` / `hintTooltip` | 37 kall | `role=tooltip`, `describedby` og Escape er på plass. Mangler: 20 px trykkflate, glipp mellom utløser og panel ved musepeker, og panelet lukkes ved trykk. Plassert inne i `<label>` stjeler det etiketten (A11Y-001). |
| `NotificationBell` | Layout | Antall uleste annonseres ikke, kortenes navn overstyrer innholdet, panelet mangler rolle, og strengene er hardkodet på norsk. |
| `FilePickerField` | 3 | Fokus er usynlig, bildeteksten er ikke koblet til feltet, og feil og hjelp er ikke koblet. |
| `DepartmentCheckboxGroup` | 2 | Mangler `fieldset`/`legend`, og har grå tekst med lav kontrast. |
| `MultiSelectFilterDropdown` | Wiki-graf | Panelet er portalt utenfor tabrekkefølgen, og har små grå etiketter. |
| `QualityItemActions` | Kvalitet | `role=menu` uten tastaturmønsteret for menyer. |
| `AiCapacityLevelDialog` | Billing | Egen modal uten fokusstyring. |
| `DashboardCockpit` | Dashboard | Lenker nestet i knapper i kalenderen, detaljer vises bare ved hover, og dagnavn mangler kontekst. |
| `BidStatusPipeline` | Sak | «Fullført» vises bare med farge. |
| `ProcessSwimlaneDiagram` / `ProcessFlowPanel` / `ProcessFlowStepList` | Kvalitet | Interaktive noder ligger inne i `svg role="img"`, strukturtabellene har felt uten navn, og stegliste-alternativet mangler redigeringshandlinger. |
| `actionStyles.js` | 111 | Bare `PRIMARY` og `DISCLOSURE` har eksplisitt `focus-visible`. |
| `WikiKnowledgeHandoffPanel`, `ProcessFlowChangeCard`, `AiCapacityCard` | 1–6 | Stort sett gode (`aria-invalid`, `role=alert`, `progressbar`). Svak inputfokus. |

**Mangler i dag (foreslås opprettet):** et felles skjemafelt (`FormField`), felles inputstiler
(`formStyles.js`), en felles kunngjører (live region), en felles bekreftelsesdialog bygget på
`ActionDialog`, og en hook for fokus i innebygde paneler.

---

## 6. Status for universell utforming

### 6.1 Det som allerede er godt

- `<html lang>` settes, zoom er tillatt, og alle sider har tittel (3.1.1, 1.4.4, 2.4.2).
- Layouten har landemerker (`header`, `nav`, `aside`, `main`).
- Ingen dra-og-slipp (2.5.7 er ikke relevant).
- De fleste nyere moduler (Etterlevelse, Leverandør, Ledelsens gjennomgåelse, Risiko, Mål) bruker
  16 px tekst, `htmlFor`/`id`, `fieldset`/`legend`, trekkspill med `aria-expanded` og tabeller som går
  over til kort på mobil.
- Revisjonsfanene i `Compliance/Audits/Show.jsx` er korrekte ARIA-faner med piltaster.
- SVG-ikoner er nesten alltid `aria-hidden`.
- E2E-hjelperen `readability.js` gir allerede en base for lesbarhet og reflow.

### 6.2 Systemiske hull (gjelder hele applikasjonen)

1. **Fokusmarkering.** 242 `outline-none`. Inputfelt viser fokus bare som en kantfargeendring
   (slate-200 → slate-400/violet-300, under 3:1), og ringer i violet-100/200/300. I Windows høykontrast
   forsvinner fokus helt.
2. **Statusmeldinger annonseres ikke.** Flash for suksess (forsvinner etter 3 s) og feil i layouten
   mangler live region. Det samme gjelder AI-generering, opplasting, polling-overganger og
   søketreff.
3. **Skjemafeil er ikke koblet til felt.** Ca. 150+ feilmeldinger er rendret uten `aria-invalid` eller
   `aria-describedby`, og fokus flyttes ikke til første feil. Det finnes bare 3 `aria-invalid` i hele
   kodebasen.
4. **Fokus forsvinner i innebygde paneler.** Knapper som åpner et panel, avmonteres (`panel === null`
   og lignende), og fokus faller til `<body>`. Dette gjelder ca. 40 steder.
5. **Egne modaler uten dialogoppførsel.** 11 dialoger (se A11Y-002).
6. **Kontrast.** 139 `text-slate-400`, 56 `text-[10px]/[11px]`, ikoner i slate-400 og knapper i
   `*-600` med hvit tekst.
7. **Polling og asynkrone overganger** (AI-uttrekk 3 s, Wiki-kjøringer 5 s, bjelle 60 s) gir ingen eller
   for mye tilbakemelding.

---

## 7. Prioriterte funn

**Alvorlighetsgrader**

- **P0** hindrer en viktig oppgave.
- **P1** er et bekreftet avvik fra WCAG 2.2 A/AA.
- **P2** er en forbedring.

**Sikkerhet**

- **B** = bekreftet i koden.
- **S** = sannsynlig: koden peker på problemet, men effekten må verifiseres.
- **M** = krever manuell verifisering.

**Test** (kolonnen «Test» i tabellene)

- **K** = tastatur.
- **SR** = skjermleser (NVDA + Chrome/Firefox, VoiceOver + Safari).
- **axe** = automatisk regelkontroll.
- **Z** = zoom/reflow 320 px / 400 %.
- **V** = visuell kontroll / kontrastmåling.

### 7.1 P0 – kritiske barrierer

| ID | Område | Fil / komponent | Problem | WCAG | Sikkerhet | Løsning | Test |
|---|---|---|---|---|---|---|---|
| A11Y-001 | Brukere, overvåkingsprofiler | `Users/Edit.jsx:202-207, 303-308`, `Users/Create.jsx:256, 342`, `WatchProfiles/WatchProfileForm.jsx:172, 238-269` | `InfoHint`-knappen ligger inne i en omsluttende `<label>` foran feltet. Etiketten knyttes dermed til knappen, og select/textarea (Rolle, Ansvarsområde, Eierskap, Beskrivelse, Nøkkelord) får ikke noe navn. | 1.3.1, 4.1.2, 3.3.2 | B | `<label htmlFor>` + `id`. Flytt `InfoHint` ut av `<label>` (via `FormField`, Fase 1). | axe `label`; SR; klikk på etikett fokuserer feltet |
| A11Y-002 | Flere | `GoNoGo/Templates/Index.jsx:37` (`CreateTemplateModal`), `GoNoGo/Templates/Edit.jsx:98` (`CriterionModal`), `CustomerEnvironment/Index.jsx:26` (`EnvironmentModal`, 3 bruk), `Billing/Index.jsx:53` (`ConfirmDialog` ×3), `Components/App/AiCapacityLevelDialog.jsx`, `AI/Show.jsx:3843` («Velg ansvarlig»), `Wiki/Index.jsx:1302` (DecisionModal), `:2418` (slett kilde), `:2844` (avbryt kjøring), `:2876` (prøv på nytt) | Egne modaler uten fokusflytting, fokusfelle, Escape eller fokusretur. Flere mangler også `role=dialog` og `aria-labelledby`. «×»-knappen mangler navn. Tastaturbrukere blir stående bak overlegget ved sletting, avbryting, pakkeendring og opprettelse. | 2.1.1, 2.4.3, 4.1.2, 1.3.1 | B | Bytt til `ActionDialog`, utvidet med størrelse og reserve for fokus (Fase 1). | K: åpne, Tab/Shift+Tab, Escape, fokusretur; E2E |
| A11Y-003 | Anbud: Go/No-go | `Notices/GoNoGoAssessment.jsx:285-316` | Lav/Middels/Høy viser valgt verdi bare med farge. Knappene har ingen `aria-pressed` eller radiosemantikk og ingen kriteriekontekst. Kommentarfeltet har bare placeholder. Skjermleserbrukere kan ikke lese eller kontrollere vurderingen. | 1.4.1, 4.1.2, 1.3.1, 3.3.2 | B | `role="radiogroup"` med `aria-labelledby` = kriterietittel, `role="radio"` + `aria-checked` (eller native radio), synlig merke for valgt verdi og etikett på kommentar. | SR; høykontrast; axe |
| A11Y-004 | Kvalitet: prosessflyt | `ProcessSwimlaneDiagram.jsx:221-229` (svg `role="img"`), `:501-512, 641-653` (noder), `ProcessFlowPanel.jsx:2069-2097, 2203-2249` (strukturtabeller), `ProcessFlowStepList.jsx:15-123` | Å redigere, sette inn og flytte et steg kan bare startes fra diagrammet. Nodene er `role=button` inne i `role=img` og blir dermed presentasjonelle for skjermleser. Strukturtabellen, det eneste alternativet, har felt uten navn. Til sammen kan skjermleserbrukere ikke redigere en prosess. | 4.1.2, 1.3.1, 2.1.1 | B (kode) / S (AT-effekt) | `role="group"` + `aria-label` på svg, navn på alle strukturfelt, og knappene «Rediger steg» og «Legg til etter» i steglisten. | SR (NVDA/VoiceOver); axe `nested-interactive`, `label` |

### 7.2 P1 – WCAG-avvik

**Felles og layout**

| ID | Fil / komponent | Problem | WCAG | Sikkerhet | Løsning | Test |
|---|---|---|---|---|---|---|
| A11Y-010 | Hele `/app`: input-konstanter (ca. 60 filer), 116 × `focus:ring-violet-100`, 71 × bare `focus:border-*`, `ring-violet-200/300` (`PageHelpButton.jsx:40`, `InfoHint.jsx:77`, `RegisterGroups.jsx:79` m.fl.) | Fokusmarkeringen er for svak (under 3:1) og forsvinner i forced-colors. | 2.4.7, 1.4.11 | B (kode) / S (opplevd) | Felles `formStyles.js` med `focus-visible:outline-2 outline-offset-2 outline-violet-600`, global `:focus-visible`-regel i `app.css`, `outline-hidden` i stedet for `outline-none` der ring erstatter outline, og `focus-visible` i alle roller i `actionStyles.js`. | K + V; Playwright forced-colors |
| A11Y-011 | `FilePickerField.jsx:34-57`, `AI/Show.jsx:2302-2343`, `Wiki/Index.jsx:2021-2050` | Filvelgere: `sr-only`-input uten `peer-focus-visible`, så fokus er usynlig. Bildeteksten er ikke koblet. Feil og hjelp er ikke koblet eller annonsert. Wiki-filen har tomme `peer-`-klasser (fokusstilen er tapt). | 2.4.7, 1.3.1, 3.3.1, 4.1.3 | B | Samle på `FilePickerField` med fokusring, `aria-labelledby`, `aria-describedby`, `role=alert`/`status`, og `aria-disabled` i stedet for `disabled` under opplasting. | K; SR |
| A11Y-012 | `CustomerAppLayout.jsx:178-190, 627-636`, `PublicLayout.jsx:82-93` | Flash for suksess har ingen live region og skjules etter 3 s. Flash for feil mangler `role=alert`. | 4.1.3, 2.2.1 | B | Permanent `role=status`/`role=alert`-region i begge layoutene, lukkeknapp, og ingen automatisk skjuling (eller ≥ 20 s med pause ved hover/fokus). | SR etter lagring; E2E sjekker region |
| A11Y-013 | Hele `/app`: ca. 150 feilrenderinger (f.eks. `Risk/RiskForm.jsx:56…`, `Compliance/Audits/AuditForm.jsx:42`, `ManagementReview/ReviewForm.jsx:33`, `Users/Edit.jsx`, `WatchProfileForm.jsx`), `Auth/Login.jsx:75, 91`, `Public/Register.jsx:70-100` | Feilmeldinger er ikke koblet til feltet (`aria-describedby`, `aria-invalid`), annonseres ikke, og fokus flyttes ikke til første feil. Kvalitets `Field` legger feilen inne i `<label>`. | 3.3.1, 1.3.1, 4.1.3 | B | `FormField`/`FieldError` + `useFocusFirstError` (Inertia `onError`). | SR; E2E: innsending av ugyldig skjema → fokus på felt, `aria-invalid` |
| A11Y-014 | Ca. 40 steder: `Risk/*`, `Objectives/*`, `Improvements/*`, `Compliance/**`, `ManagementReview/*`, `SupplierManagement/*`, `AI/Show.jsx:2516-2583`, `Wiki/Show.jsx:2721-2966`, `Wiki/Show.jsx:1272-1474` | Fokus går tapt når en knapp som åpner eller lukker et panel, eller utløser en handling, avmonteres. Gjelder også påstandsgodkjenning (`router.patch` uten `preserveScroll`). | 2.4.3 | B | `useInlinePanel()`: fokuser panelets overskrift eller første felt ved åpning, og utløseren (eller et reservemål) ved lukking. `preserveScroll` + fokus til neste påstand. | K: `document.activeElement` etter handling; E2E |
| A11Y-015 | `SupplierManagement/Show.jsx:126-152`, `Compliance/Audits/Show.jsx:129`, `Wiki/Index.jsx:3155` | Lenker innad på siden scroller, men flytter ikke fokus. | 2.4.3 | B | `focusTarget(id)`-hjelper med `tabIndex=-1` på målet. | K |
| A11Y-016 | 139 × `text-slate-400`, 12 × `text-slate-300`, 56 × `text-[10px]/[11px]`. Eksempler: `DashboardCockpit.jsx:388, 434, 821`, `MultiSelectFilterDropdown.jsx:178, 243, 257`, `DepartmentCheckboxGroup.jsx:44`, `Users/Create.jsx:192` («Minst 8 tegn»), `InfoCenter/Index.jsx:285-399`, `Home/Index.jsx:80`, `Quality/Index.jsx:219`, `Public/Privacy.jsx`, `Public/Terms.jsx`, `Public/Faq.jsx:42`, `Wiki/*` (50) | Informasjonsbærende tekst har kontrast rundt 2,6:1. | 1.4.3 | B (beregnet) | Regel: informasjonstekst er minst `slate-600` (`slate-500` bare på hvitt). Ikke tekst under 12 px. | axe `color-contrast`; V |
| A11Y-017 | `Wiki/Show.jsx:1732, 2354, 2264, 2962`, `Wiki/Index.jsx:2530, 2555, 2499-2512`, `GoNoGo/Templates/Index.jsx:121`, `Templates/Edit.jsx:333` (`opacity-60`) | Knapper i `emerald/sky/amber-600` med hvit tekst (3,2–4,1:1). `opacity-60` på inaktive kort. | 1.4.3 | B (beregnet) | Ett trinn mørkere (`*-700`). «Inaktiv»-merke i stedet for opacity. | axe; V |
| A11Y-018 | `NotificationBell.jsx:315`, `ModuleSidebar.jsx:236, 396`, `CustomerAppLayout.jsx:467`, `ProcessSwimlaneDiagram.jsx:43-46, 440, 519`, `KpiTrend.jsx:70, 77` | Ikonknapper, diagramkanter og noder har kontrast under 3:1. | 1.4.11 | B (beregnet) | `slate-500`/`600` på strek og ikon. | V |
| A11Y-019 | Inputkanter `border-slate-200` (1,23:1), ugyldig `border-rose-400` | Feltgrensene er knapt synlige. | 1.4.11 | S | Felles feltfarge `field-border` (3,49:1) og `rose-600` for ugyldig (§17.12). | Rettet i Fase 3-sidene og `formStyles`; øvrige moduler tar fargen i bruk i Fase 4–6 |
| A11Y-020 | `CustomerAppLayout.jsx:376` (header `z-[60]`) mot toast `:628` og `ActionDialog.jsx:104` (`z-50`) | Toasten og dialoger ligger under den klebrige headeren. Headeren kan klikkes mens en `aria-modal`-dialog er åpen. Fokuserte elementer kan skjules under headeren. | 2.4.11, 4.1.2 | S / M | Fast z-index-skala (header 60, overlegg ≥ 70, toast 80), `scroll-padding-top` i `app.css`, og `inert` på appinnholdet mens en modal er åpen. | V + K (Shift+Tab under header) |
| A11Y-021 | `NotificationBell.jsx:166, 194, 260` | `aria-label` overstyrer antall uleste, kortnavnet skjuler innhold og dato, og panelet mangler rolle. | 1.3.1, 4.1.2 | B | «Varsler, N uleste» (i18n), fjern overstyringen på kortet, og bruk disclosure (ikke `aria-haspopup="dialog"`) eller ekte dialog. | SR |
| A11Y-022 | `PublicLayout.jsx:42` | Hovednavigasjonen er `hidden lg:flex` uten mobilmeny, så sidene er utilgjengelige under 1024 px eller ved 200–400 % zoom. | 1.4.10, 2.4.5 | B | Disclosure-meny under `lg`. | Z |
| A11Y-023 | `DashboardCockpit.jsx:464-494, 842-870` | Kalender: `<Link>` ligger inne i `<button>`, detaljer vises bare ved hover eller `focus-within`, dagens navn er bare «14 2», og valgt dag og «i dag» vises bare visuelt. | 4.1.2, 1.3.1, 1.4.13, 1.4.1 | B | Dagknapp med `aria-label` (full dato + antall), `aria-pressed`, `aria-current="date"`, og fristliste for valgt dag utenfor rutenettet. | K; SR |
| A11Y-024 | `BidStatusPipeline.jsx:60-88`, `ManagementReview/Show.jsx:312-316`, `Wiki/Index.jsx:277-289` (RunTimeline) | Status som «fullført» eller «gjenstår» vises bare med farge eller `aria-hidden`-ikon. | 1.4.1, 1.3.1 | B | `sr-only`-statustekst + `aria-current="step"`. | SR; gråtone |
| A11Y-025 | `hintTooltip.jsx:129`, `InfoHint.jsx:59-60`, `ControlHint.jsx:49-50` | Det er 8 px glipp mellom utløser og panel, så panelet lukkes før musepekeren når fram. | 1.4.13 | S | Padding-bro eller forsinket lukking. | V (mus) |
| A11Y-026 | `MultiSelectFilterDropdown.jsx:167-285` | Panelet er portalt til `body`. Med 8 eller færre valg kommer ikke Tab inn i panelet. | 2.4.3, 2.1.1 | B | Flytt alltid fokus til første kontroll, eller render inline og lukk ved focusout. | K (Wiki-graf) |
| A11Y-027 | `CustomerEnvironment/CustomerEnvironmentHeader.jsx:8-37`, `Quality/Item.jsx:293-310` | Fanelignende navigasjon uten `aria-current` gjør at aktiv fane bare vises visuelt. | 1.3.1, 4.1.2 | B | `<Link aria-current="page">` i `<nav aria-label>` (som `NavigationRow`). | SR |
| A11Y-028 | `CustomerEnvironment/Index.jsx:134-151`, `AI/Show.jsx:2364, 2498, 3268`, `Wiki/Index.jsx:686-692`, `Wiki/Show.jsx:3081, 3190, 3218`, `Notices/SavedShow.jsx:1288`, `Notices/Index.jsx:2212`, `Notices/Show.jsx:73, 119`, `DiscoveryNoticeCard.jsx:201` | Disclosure-knapper uten `aria-expanded`. `Wiki/Index.jsx:688` mangler dessuten fokusstil. | 4.1.2, 2.4.7 | B | `DisclosureButton`-mønster (`aria-expanded` + `aria-controls`). | axe; SR |
| A11Y-029 | `CustomerEnvironment/Index.jsx:363-388`, `CustomerRolesPanel.jsx:249-256` | Tilgangsmatrisen setter avkrysningsboksen `disabled` mens den lagrer automatisk, så fokus faller til `body` etter hver endring. | 2.4.3, 4.1.3 | S | `aria-disabled` + ignorer endringer under lagring, og kunngjør «Lagret». | K: Space → Tab |
| A11Y-129 | `resources/views/app.blade.php` | `<title inertia>` ved siden av Inertia v3s `<title data-inertia>`: alle sider het «Procynia». (Funnet i Fase 2.) | 2.4.2 | B | `<title data-inertia="">`. | Rettet (§16) |
| A11Y-130 | `DashboardCockpit.jsx` (pipeline) | Seks faser på rad: navn kuttet, sidelengs rulling ved 640–1024 px. (Fase 2.) | 1.4.10, 1.4.4 | B | Container queries. | Rettet (§16) |
| A11Y-131 | Brukermeny, varselpanel | Panel delvis utenfor vinduet ved 320 px. (Fase 2.) | 1.4.10 | B | `useKeepInViewport`. | Rettet (§16) |
| A11Y-132 | `CustomerAppLayout.jsx` header | Sticky header på alle bredder fyller vinduet ved 320 px / 400 %. (Fase 2.) | 1.4.10, 2.4.11 | B | Sticky fra `lg`. | Rettet (§16) |
| A11Y-133 | `FlashMessages.jsx` + skjema med `preserveScroll` | Feilbanneret er utenfor synsfeltet etter innsending lenger ned på siden. (Fase 2.) | 3.3.1 | S | Feil ved feltet + fokus (A11Y-013). | Delvis rettet i Fase 3 (§17.4): feltfeil i de migrerte skjemaene. Gjenstår: A11Y-137. |
| A11Y-135 | `AI/Show.jsx:2669, 3063` (Kravtype) | `InfoHint` inne i `<label>`, samme feil som A11Y-001. (Fase 3.) | 1.3.1, 4.1.2 | B | `FormField` med `info`. | Fase 4 |
| A11Y-136 | §10 i denne planen | Regelen «`slate-400` på hvitt er grensen» er feil i Tailwind 4: `slate-400` gir 2,63:1. `rose-600` gir 4,51:1 på hvitt og 4,31:1 på `slate-50`. (Fase 3.) | 1.4.3, 1.4.11 | B (beregnet) | §10 rettet: minst `slate-500` for kanter og ikoner, `rose-700` for feiltekst. | Rettet (§17) |
| A11Y-137 | Radhandlinger med `preserveScroll` som svarer med flash-feil (f.eks. Deaktiver i en lang tabell) | Feilen er ikke knyttet til et felt, og banneret står øverst, utenfor synsfeltet. Skjermleser får den (`role="alert"`). (Fase 3.) | 3.3.1 (seende) | S | Feilen vises også ved knappen som ble trykket, beskrevet av knappen (`Support/rowActionError.js`). Ikke global rulling eller fokusflytting. | Rettet for Fase 3-sidene (§17.12); andre moduler tar mønsteret i bruk i Fase 4–6 |
| A11Y-138 | `GoNoGo/Templates/Index.jsx`, `Templates/Edit.jsx`, `Billing/Index.jsx` | Siden viser flash-meldingen selv, i tillegg til layoutens `FlashMessages`. Meldingen står to ganger på skjermen (den leses én gang). (Fase 3.) | Beste praksis | B | Sidens egen kopi fjernet; layoutens `FlashMessages` er eneste kilde. | Rettet (§17.12) |
| A11Y-134 | `<Link>` med bare ny spørrestreng, samme tittel, uten `preserveState` | Fokus faller til `<body>`. (Fase 2.) | 2.4.3 | B | Fokus tilbake til samme kontroll etter oppdatering av samme side (`routeFocus.js`). | Rettet (§16.12) |
| A11Y-139 | `Notices/SavedShow.jsx` (midtkolonnen) | Saksvisningen hadde en egen `<main>` inne i layoutens `<main>`: to hovedlandemerker. (Fase 4.) | 1.3.1 | B | `<div>`. | Rettet (§18) |
| A11Y-140 | `Notices/Index.jsx` (publiseringsdato, historikkskjema) | En `<label>` omsluttet de to dato-etikettene (nestede etiketter, ugyldig HTML; bare første dato fikk navn). I historikkskjemaet hadde «Anskaffelsestype», «Oppfølging» og «Antall måneder» bildetekst, men ingen etikett: feltene var uten navn. (Fase 4.) | 1.3.1, 4.1.2 | B | `role="group"` med `aria-labelledby` for datoene; `FormField` for historikkfeltene. | Rettet (§18) |
| A11Y-141 | `Notices/GoNoGoAssessment.jsx` (foreløpig beslutningsgrunnlag) | Oppsummering og «Beslutningen tas av deg» med `opacity-90`/`opacity-75` på grønn/gul/rød tone: under 4,5:1 (axe). (Fase 4.) | 1.4.3 | B (axe) | Full tekstfarge. | Rettet (§18) |
| A11Y-142 | `GoNoGoAssessment.jsx` (vurderingsrad), `AI/Show.jsx` (kolonner, ansvarlig-filter, dokumentlistens topp) | Ved 320 px rullet siden sidelengs: vurderingsknappene lå i en boks som ikke kunne krympe, AI-sidens rutenett hadde et `auto`-spor som vokste med filterets `min-w-[16rem]`, og «Vis dokumentliste» kunne ikke bryte linjen. (Fase 4.) | 1.4.10 | B (E2E) | `min-w-0`/`flex-wrap`, `grid-cols-1` under `lg`, minimumsbredde først fra `sm`. | Rettet (§18) |
| A11Y-143 | Knapper som starter asynkront arbeid: «Lagre vurdering», «Lag svar», «Generer», «Kopier svar», «Lagre endring», «Last opp …», «Lagre» på kunngjøringskort | Knappen ble `disabled` mens arbeidet pågikk (eller etterpå), og tastaturfokus falt til `<body>`. (Fase 4.) | 2.4.3 | B | `aria-disabled` + vakt i handleren mens arbeidet pågår; `disabled` bare for faste forutsetninger (ingen fil valgt). | Rettet (§18) |
| A11Y-144 | `Notices/CpvSelector.jsx` | Forslagene var `button role="option"` i tabrekkefølgen (Tab gikk inn i listen og lukket den), listeboksen inneholdt overskrift, «Laster …» og «Ingen treff», og kombinasjonsboksen var `aria-expanded` med tom liste. (Fase 4.) | 4.1.2, 2.1.1 | B | `tabIndex=-1` på forslag, bare forslag i listeboksen, `aria-expanded` bare når det finnes forslag; antall treff kunngjøres. | Rettet (§18) |
| A11Y-145 | `AI/Show.jsx` («Velg ansvarlig») | Dialogen kan ikke åpnes fra dagens kort: `openResponsiblePicker` kalles ingen steder. Kode uten bruker. (Fase 4.) | — | B | Migrert til `ActionDialog` slik at den er riktig når den tas i bruk. Beslutning 2026-10-15 (§18.14): koden beholdes, dialogen aktiveres ikke, ingen ny knapp; produktbeslutning senere. | Informasjon (utsatt produktbeslutning) |
| A11Y-146 | `Notices/Index.jsx` (forretningsgjennomgang i fristskjemaet) | «Slett» på en gjennomgangsdato fjerner raden og knappen, og fokus faller til `<body>`. (Fase 4.) | 2.4.3 | B | Fokus til neste dato, ellers forrige, ellers «Legg til BR» (`businessReviewFocus.js`). | Rettet (§18.14) |
| A11Y-147 | `Notices/Index.jsx` («Legg til BR» i fristskjemaet) | Knappen leste en nøkkel som ikke finnes (`noticesText.addBusinessReview`) og var tom: ingen synlig tekst og intet tilgjengelig navn. Eksisterte før Fase 4. (Sluttkontroll Fase 4.) | 4.1.2, 2.4.6 | B | Riktig nøkkel (`businessReviewAdd`, «Legg til BR»). | Rettet (§18.14) |

**Moduler**

| ID | Fil / komponent | Problem | WCAG | Sikkerhet | Løsning | Test |
|---|---|---|---|---|---|---|
| A11Y-040 | `Notices/Index.jsx:1653-1661` | Livesøk: `outline-none focus:ring-0` uten erstatning gir usynlig fokus. Navnet kommer bare fra placeholder. | 2.4.7, 3.3.2 | B | `focus-within`-ring på omsluttende element + `sr-only`-etikett. | K |
| A11Y-041 | `GoNoGo/Templates/Index.jsx:45, 55`, `Templates/Edit.jsx:28, 248, 258`, `Suppliers/Index.jsx:158-189` | Etiketter er ikke koblet til feltene. Datofeltene mangler navn helt. | 1.3.1, 4.1.2 | B | `useId` + `htmlFor`. | axe `label` |
| A11Y-042 | `GoNoGo/Templates/Index.jsx:91/102`, `Templates/Edit.jsx:217/241`, `Wiki/Ask.jsx:70/74` | Doble h1 (layouten og siden). | 1.3.1, 2.4.6 | B | `showPageTitle={false}`. | axe; HeadingsMap |
| A11Y-043 | `Suppliers/Index.jsx:255-283` | Sorterbare kolonner uten `aria-sort`. | 1.3.1, 4.1.2 | B | `aria-sort` + `sr-only`-retning. | SR |
| A11Y-044 | `AI/Show.jsx:2826-2862` | Kravkortet `<article role="button">` inneholder knapper, lenker og felt (nestet interaktivitet). Aktivt krav vises bare med ring. | 4.1.2, 1.3.1 | B | Fjern rollen og bruk egen «Åpne svar»-knapp + `aria-current`. | axe `nested-interactive`; SR |
| A11Y-045 | `AI/Show.jsx:1764-1812, 3466-3477` | AI-generering (opptil 120 s): «genererer», feil og ferdig svar annonseres ikke. | 4.1.3 | B | Permanent `role=status` i svarpanelet, `role=alert` for feil, og fokus til svaroverskriften når svaret er klart. | SR |
| A11Y-046 | `Wiki/Show.jsx:2597 + 2861/2888`, `app/Services/Ai/Wiki/EnterpriseWikiIngestService.php:72`, `resources/css/app.css:24-41` | Artikkelens Markdown begynner med `# {tittel}`, som rendres som en ny h1 under sidens h1. Artikkelens h2 blandes med sidens h2. | 1.3.1, 2.4.6 | B | Delt `WikiMarkdown` med nivåforskyvning (h1→h2, …) og justerte `.wiki-article`-selektorer. Ingen endring i lagret innhold. | HeadingsMap; SR |
| A11Y-047 | `Wiki/Show.jsx:1717-1724, 2342-2348` | Kommentarfeltet ved godkjenning av påstand har bare placeholder. | 1.3.1, 3.3.2 | B | Etikett. | axe |
| A11Y-048 | `Wiki/Index.jsx:2288, 1550-1616, 2135, 2151, 2732, 2756` | Dokumenteier-select og filtre uten etikett. | 1.3.1, 4.1.2 | B | `aria-label`/`sr-only`-etiketter. | axe |
| A11Y-049 | `Wiki/Show.jsx:2756-2769` | Alle avsnittsfelt i redigeringsmodus heter «Rediger avsnitt». Fokus flyttes ikke ved start, lagring eller avbryt, og feil mangler `role=alert`. | 2.4.6, 2.4.3, 4.1.3 | B | «Rediger avsnitt N av M» / seksjonsnavn, fokusstyring og `role=alert`. | SR; K |
| A11Y-050 | `Wiki/Graph.jsx:1399-1404, 873-964`, `WikiGraphFocusView.jsx:391, 441` | Sigma-lerretet kan bare brukes med mus. Node- og relasjonspanel og kanttooltip kan ikke nås med tastatur, og det finnes ikke noe tekstalternativ. Dette dempes av at lenkede sider er listet på Wiki-siden. | 2.1.1, 1.1.1 | B | `role="img"` + oppsummering på lerretet, og en «Vis som liste»-visning med noder og relasjoner som åpner de samme panelene. | K; SR |
| A11Y-051 | `Auth/Login.jsx` | Mangler `<main>`. Feil annonseres ikke (dekkes også av A11Y-013). «eller» står i `slate-400`. | 1.3.1, 4.1.3, 1.4.3 | B | `<main>`, `role=alert` og kontrast. | SR; axe |
| A11Y-052 | `Pages/App/Objectives/KpiTrend.jsx:57-122` | Punktene er fokuserbare uten rolle inne i `role=img`, har `focus:outline-none`, og tooltipen kan ikke lukkes med Escape. Det finnes en tabell som tekstalternativ. | 4.1.2, 2.4.7, 1.4.13 | B / S | Gjør grafen til ren `role="img"` med oppsummering, uten tabstopp per punkt, og vis til tabellen. | SR |
| A11Y-053 | Filament: `resources/views/filament/infolists/attachment-table.blade.php:20-28` | Beskrivelsen åpnes bare via `@click` på `<tr>`. | 2.1.1, 4.1.2 | B | Knapp med `aria-expanded` i siste celle. | K |
| A11Y-054 | Filament: `ai-forbruk.blade.php:49-55`, `ai-profitability.blade.php:37-43` | Datofeltene Fra/Til mangler `for`/`id`. | 1.3.1, 4.1.2 | B | `id` + `for`. | axe |
| A11Y-055 | Filament: `AdminPanelProvider.php` (`Color::Amber`), `page-help.blade.php:38`, `attachment-table.blade.php:41`, `view-doffin-supplier.blade.php:66` | Amber-600 som lenketekst gir ca. 3:1. | 1.4.3 | S | Mørkere primærfarge (`Color::hex`) eller `text-primary-800` for lenker. | axe på `/admin` |
| A11Y-056 | Filament: 14 egne sider uten `dark:`-varianter | Mørk modus er aktiv som standard, så tekst på sidebakgrunnen får lav kontrast. | 1.4.3, 1.4.11 | S | Legg til `dark:`-varianter eller `->darkMode(false)` (beslutning i Fase 6). | axe med `colorScheme: 'dark'` |

### 7.3 P2 – forbedringer

| ID | Område / fil | Forbedring | WCAG / grunnlag |
|---|---|---|---|
| A11Y-100 | `CustomerAppLayout.jsx`, `PublicLayout.jsx` | Hopp-lenke «Hopp til hovedinnhold» og `<main id tabIndex=-1>`. | 2.4.1 (landemerker finnes, så kravet er formelt oppfylt) |
| A11Y-101 | `CustomerAppLayout.jsx:44` | `aria-label` på begge `NavigationRow`-`nav` (modul / side). | 1.3.1 |
| A11Y-102 | `app.js` | Ved Inertia-navigasjon: fokus til h1 eller kunngjøring av tittel. | 2.4.3 / 4.1.3 (beste praksis) |
| A11Y-103 | `CustomerAppLayout.jsx:444-457`, `QualityItemActions.jsx:99-134`, `Wiki/Show.jsx:880-915` | Fjern `role=menu`/`aria-haspopup="menu"` der menytastaturet mangler (disclosure-mønster), og returner fokus ved Escape. | 4.1.2 |
| A11Y-104 | `PageHelpPanel.jsx:44-68` | Fokus til panelet ved åpning og tilbake ved lukking, og oversatte `aria-label`-er. | 2.4.3, 3.1.2 |
| A11Y-105 | `NotificationBell`, `CustomerAppLayout.jsx:676-697`, `BidStatusPipeline`, `DepartmentCheckboxGroup`, `PageHelpPanel`, AI `Instructions`/`DocumentPreview`, Filament-hjelp | Hardkodet norsk vises i engelsk UI (lang = en). | 3.1.2 |
| A11Y-106 | `InfoHint.jsx:51` (32 av 37 kall), `CpvSelector.jsx:205`, diagrammets «+» (r=9) | Trykkflate under 24 px. Avstandsunntaket kan gjelde. | 2.5.8 (M) |
| A11Y-107 | 49 × `window.confirm`, 1 × `window.alert` | Felles bekreftelsesdialog på `ActionDialog` gir konsistens og oversatte knapper. Native dialoger er tilgjengelige, så dette er ikke et AA-krav. | Konsistens |
| A11Y-108 | 44 tabeller, 0 `<caption>`, tomme `<th>` (`ProcessFlowPanel.jsx:2063, 2181`, `BusinessAreasPanel.jsx:98`) | `sr-only`-caption eller `aria-label`, og tekst i handlingskolonnen. | 1.3.1 |
| A11Y-109 | Gjentatte «Rediger/Slett/Fjern/Vis/Åpne/PDF» i rader | `aria-label` med radkontekst, synlig tekst først. | 2.4.6, 2.5.3 |
| A11Y-110 | `overflow-x-auto`-tabeller og svarbokser, diagramflaten | `tabIndex=0` + `role=region` + `aria-label` på rullbare beholdere. | 2.1.1 (Safari) |
| A11Y-111 | Søketreff (Risiko, Avvik, Mål, Krav, Leverandør, `SearchableMultiSelect`, `Compliance/Library/Show.jsx`) | `role=status` på antall treff. | 4.1.3 |
| A11Y-112 | `Wiki/Index.jsx:638`, `AI/Show.jsx:2196` | Live regions som monteres sammen med innholdet, eller som oppdateres ved hver polling. Bytt til én permanent region som bare kunngjør statusoverganger. | 4.1.3 (M) |
| A11Y-113 | `Wiki/Show.jsx:722-727`, `ManagementReview/Report.jsx:66-74` | `<caption>` for Wiki-tabeller og `<th scope="row">` i rapportens nøkkeltabeller. | 1.3.1 |
| A11Y-114 | Wiki-artikkel uten `remark-gfm` | Felles renderer med GFM-tabeller (se A11Y-046). | 1.3.1 (S) |
| A11Y-115 | `AI/Show.jsx:2933-2943` | Fjern feil `aria-pressed` på «Lag svar». | 4.1.2 |
| A11Y-116 | `AI/Show.jsx:600-633` | Kopiering: gjenopprett fokus og kunngjør «Kopiert». | 2.4.3, 4.1.3 |
| A11Y-117 | `Wiki/Index.jsx:3233-3245`, `Wiki/Show.jsx:1758-1782, 2203` | `aria-pressed` på filterbrikker og velgere. | 4.1.2 |
| A11Y-118 | `WikiReviewPanel.jsx:90, 402-408` | Oppgi minimumslengden (10 tegn) for «Send tilbake». | 3.3.2 |
| A11Y-119 | `Compliance/Requirements/Index.jsx:195` → `RegisterGroups.jsx:76`, `Library/Show.jsx:106`, `Public/Security.jsx:171-189` | Hopp i overskriftsnivå. | 1.3.1 |
| A11Y-120 | `ManagementReview/ReviewForm.jsx:79`, `ReviewDecisions.jsx:179`, `CustomerRolesPanel.jsx:450-480`, `Wiki/Graph.jsx:286-325`, `ReviewForm.jsx:55-65` | Grupper uten `fieldset`/`legend` eller `role=group`, og hint som ikke er koblet. | 1.3.1 |
| A11Y-121 | 23 av 25 skjemaer | Forklaring av `*` («Felt merket med * må fylles ut»), og `aria-required` i Kvalitet. | 3.3.2 |
| A11Y-122 | `PublicLayout.jsx:62-70`, `Public/Home.jsx:96` | Falsk språkvelger. Tittelen blir «Procynia · Procynia». | 3.2.4, 2.4.2 |
| A11Y-123 | `app.css` | `@media (prefers-reduced-motion: reduce)` for overganger og spinnere. | 2.3.3 (AAA, frivillig) |
| A11Y-124 | `app.js` / layout | Oppdater `document.documentElement.lang` fra delt `locale` etter Inertia-besøk. | 3.1.1 (S) |
| A11Y-125 | Filament: `admin-notifications.blade.php`, `system-status.blade.php`, `backup-recovery.blade.php`, fremdriftsfelt, `wire:poll` | Alvorlighetsgrad bare med farge, manglende overskrifter, fremdriftsfelt uten rolle, og polling uten pause (vurder om den er «essensiell»). | 1.4.1, 1.3.1, 4.1.2, 2.2.2 |
| A11Y-126 | Ledelsens gjennomgåelse-PDF (dompdf) | Ikke tagget. Lenk til HTML-utskriftsversjonen som tilgjengelig alternativ ved PDF-nedlasting. | EN 301 549 kap. 10 |
| A11Y-127 | `Quality/Item.jsx`, `ProcessFlowPanel.jsx:1943` | Fjerning av en rolle sletter noder uten advarsel. Endringen er ulagret og kan derfor angres. | 3.3.4-nær |
| A11Y-128 | `ProcessSwimlaneDiagram.jsx:505, 645` | `focus-visible:outline` på SVG `<g>` vises ikke i Firefox/Safari. Tegn fokusringen i SVG. | 2.4.7 (M; P1 hvis bekreftet) |

---

## 8. Faseinndelt implementering

Hver fase er en selvstendig leveranse med egne commits og tester, og kan slås sammen uavhengig av de
andre. Rekkefølgen gir mest effekt med lavest risiko: de felles byggesteinene i Fase 1–3 retter
flertallet av funnene før de tunge modulfilene røres.

### Fase 1 – Felles komponenter og designregler

**Omfang:** delte stiler, primitiver og verktøy. Fasen endrer ingen sider ut over det som skjer
automatisk gjennom de delte komponentene.

**Forbedringer**

1. `resources/css/app.css`:
   - global `:focus-visible`-regel (2 px outline, `violet-600`, offset 2);
   - `scroll-padding-top` for den klebrige headeren;
   - z-index-variabler;
   - valgfritt `prefers-reduced-motion`.
2. `Support/formStyles.js`: `INPUT`, `SELECT`, `TEXTAREA`, `LABEL`, `HINT` og `ERROR` med synlig fokus
   (A11Y-010) og kontrastsikre kanter (A11Y-019).
3. `Support/actionStyles.js`: `focus-visible` på alle roller.
4. `Components/App/FormField.jsx` + `FieldError`: kobler `htmlFor`/`id`, hint, feil, `aria-invalid`,
   `aria-required` og stjernemarkering, og holder `InfoHint` utenfor `<label>`. I tillegg
   `useFocusFirstError` (A11Y-001, -013).
5. `ActionDialog`:
   - z-index over headeren;
   - `size`-variant;
   - reservemål for fokus når utløseren forsvinner;
   - valgfri tittel og lukkeknapp med navn.

   I tillegg `useConfirm()` som erstatning for `window.confirm` (forberedelse til A11Y-107).
6. `Components/App/LiveAnnouncer` + `useAnnounce()`: én permanent `polite`- og én `assertive`-region.
7. `useInlinePanel()` og `focusTarget(id)` (A11Y-014, -015).
8. `FilePickerField`: fokusring, `aria-labelledby`, `aria-describedby`, `role=alert`/`status` og
   fremdrift (A11Y-011).
9. `InfoHint`, `ControlHint` og `hintTooltip`: 24 px trykkflate, padding-bro og åpning ved trykk
   (A11Y-025, -106).
10. `DepartmentCheckboxGroup`, `MultiSelectFilterDropdown`, `SearchableMultiSelect`,
    `QualityItemActions`, `BidStatusPipeline`, `NotificationBell` og `PageHelpPanel` rettes
    (A11Y-021, -024, -026, -103, -104, -105).
11. Kontrastregler dokumenteres i §10.

**Berørte filer:** `resources/css/app.css`, `Support/actionStyles.js`, `Support/formStyles.js` (ny),
`Components/App/*` (de som er nevnt over) og `lang/{no,en}/procynia.php` for nye strenger.

**Avhengigheter:** ingen. Testverktøyet fra §9 (axe-hjelper og ESLint-regler) innføres i denne fasen,
slik at alle senere faser kan måles.

**Risiko:**
- Lav til middels. Den globale `:focus-visible`-regelen gir synlig endring overalt, og må kontrolleres
  visuelt mot eksisterende ringer slik at det ikke blir doble markeringer.
- Endringer i `ActionDialog` berører 13 filer. Eksisterende E2E-tester for dialoger fanger opp
  regresjoner.

**Teststrategi:**
- `node --test`-enhetstester for logikken i `FormField`/`useFocusFirstError`.
- Playwright-tester for `ActionDialog` (felle, Escape, retur, reservemål), `FilePickerField` (fokus
  synlig) og `InfoHint` (hover-bro).
- axe på en håndfull referansesider.

**Akseptansekriterier:**
- Alle felles komponenter i §5 har null axe-brudd i isolerte scenarier.
- Fokus er synlig (≥ 3:1) på alle interaktive felleskomponenter, også i forced-colors.
- Komponentene er dokumentert med en kort bruksnote i filhodet.

### Fase 2 – Navigasjon, layout og standardvisninger

**Omfang:** `CustomerAppLayout`, `PublicLayout`, `Login`, Hjem, Dashboard og Styring.

**Forbedringer:**
- Hopp-lenke og navngitte `nav` (A11Y-100, -101).
- Flash via kunngjøreren, uten automatisk skjuling, og med z-index-skala (A11Y-012, -020).
- Brukermenyen som disclosure (A11Y-103).
- Fokus eller kunngjøring ved sideskift, og `lang` ved språkbytte (A11Y-102, -124).
- Mobilmeny på offentlige sider og ekte eller fjernet språkvelger (A11Y-022, -122).
- `Login` med `<main>`, feilhåndtering og kontrast (A11Y-051).
- Dashboardkalenderen (A11Y-023).
- Kontrast i Hjem, Dashboard og `ModuleSidebar` (A11Y-016, -018).

**Berørte filer:** `Layouts/*`, `app.js`, `Auth/Login.jsx`, `Public/*`, `Home/Index.jsx`,
`DashboardCockpit.jsx`, `ModuleSidebar.jsx`.

**Avhengigheter:** Fase 1 (kunngjører, fokusstiler, z-index).

**Risiko:**
- Middels. Layouten brukes av alle sider. Endringer i flash-oppførselen påvirker E2E-tester som venter
  på at toasten forsvinner. Søk etter `toBeHidden` på flash.
- Navigasjonstestene (`headerNavigation.test.js`, `moduleSidebar.test.js`) må holdes grønne.

**Teststrategi:**
- E2E: hopp-lenken er første tabstopp, flash ligger i live region, fokus etter navigasjon, og
  mobilmeny på 320 px.
- Manuell skjermlesertest av header, bjelle og meny.

**Akseptansekriterier:**
- Ingen axe-brudd (`wcag2a`/`wcag2aa`/`wcag21aa`/`wcag22aa`) på `/login`, `/`, de offentlige sidene,
  `/app/dashboard` og `/app/bid-status`.
- Tastaturgjennomgang uten tap av fokus.
- Reflow uten tap av innhold eller navigasjon ved 320 px.

### Fase 3 – Skjema, tabeller, dialoger og varsler

**Omfang:** migrere alle egne modaler og skjemaer til primitivene fra Fase 1, i sidene som ikke er
modultunge: Brukere, Avdelinger, Kundemiljø/Tilganger, Abonnement, Go/No-go-maler, Overvåkingsprofiler,
Konkurrenter og Oppfølging/Mine oppgaver.

**Forbedringer:**
- A11Y-001 og A11Y-002 for GoNoGo, Kundemiljø, Billing og `AiCapacityLevelDialog`.
- A11Y-013 (feilkobling) og A11Y-027 (faner).
- A11Y-028 (disclosure), A11Y-029 (tilgangsmatrise).
- A11Y-041, -042, -043.
- Tabeller: A11Y-108, -109, -110.
- Søketreff: A11Y-111.
- A11Y-120.

**Berørte filer:** `Pages/App/{Users,Departments,CustomerEnvironment,Billing,GoNoGo,WatchProfiles,Suppliers,InfoCenter}/*`.

**Avhengigheter:** Fase 1, og Fase 2 (kunngjøreren i layouten).

**Risiko:**
- Middels. Tilgangsmatrisen og brukerredigering ligger nær autorisasjon. Bare markup og fokus endres,
  ikke hvilke handlinger som er tillatt.
- Eksisterende PHP-tester for tilganger skal være uendret grønne.

**Teststrategi:**
- E2E for hver modal: tastatur og fokusretur.
- Ugyldig innsending gir fokus på feltet.
- axe på hver side.
- `permission-sections.spec.js` og `package-change.spec.js` som regresjon.

**Akseptansekriterier:**
- Ingen egne modaler uten `ActionDialog` i disse modulene.
- Alle felt har programmatiske navn.
- Alle serverfeil er koblet til feltet og annonseres.
- Ingen axe-brudd.

### Fase 4 – Anbud, dokumenter og AI-arbeidsflyter

**Omfang:** `Notices/*` (inkl. Go/No-go-vurdering og CPV-velger), `AI/*` og `DiscoveryNoticeCard`.

**Forbedringer:**
- A11Y-003 (Go/No-go), A11Y-040 (livesøk).
- A11Y-044 (kravkort), A11Y-045 (AI-status).
- Opplasting via `FilePickerField` (A11Y-011).
- A11Y-002 («Velg ansvarlig»).
- A11Y-014 (fokus i bulk-bekreftelse).
- A11Y-112 (uttrekksbanner), A11Y-115, A11Y-116.
- Skjemafeil (A11Y-013) og kontrast (A11Y-016).

**Berørte filer:** `Notices/Index.jsx` (3085 linjer), `Notices/SavedShow.jsx` (2464),
`Notices/GoNoGoAssessment.jsx`, `Notices/CpvSelector.jsx`, `AI/Show.jsx` (3982),
`AI/Instructions.jsx`, `AI/DocumentPreview.jsx` og `AI/wikiAnswerMarkdown.js`.

**Avhengigheter:** Fase 1–2.

**Risiko:**
- Høy, fordi filene er svært store og arbeidsflyten er sentral.
- Endringene skal være kirurgiske (attributter, roller, fokus) uten omstrukturering.
- Polling og AI-kall endres ikke, bare tilbakemeldingen rundt dem.

**Teststrategi:**
- Eksisterende AI- og Notices-E2E (`ai-answer-markdown-table.spec.js` m.fl.) som regresjon.
- Nye tastaturtester for Go/No-go-vurdering, kravliste og ansvarlig-velger.
- Manuell skjermlesertest av en fullstendig flyt: last opp → uttrekk → generer svar → kopier.

**Akseptansekriterier:**
- En skjermleserbruker kan gjennomføre og lese en Go/No-go-vurdering og hele AI-svarflyten uten
  visuell hjelp.
- Statusoverganger annonseres én gang.
- Ingen axe-brudd.

### Fase 5 – Enterprise Wiki og editor

**Omfang:** `Wiki/*` (Index, Show, Graph, WikiGraphFocusView, Ask, WikiReviewPanel) og
`WikiKnowledgeHandoffPanel`.

**Forbedringer:**
- A11Y-002 (DecisionModal, slett kilde, avbryt kjøring, prøv på nytt → `ActionDialog`).
- A11Y-011 (kildeopplasting).
- A11Y-046 (overskriftsnivå i artikkelen via delt `WikiMarkdown`, uten endring i lagret Markdown).
- A11Y-047, -048 (etiketter).
- A11Y-049 (redigeringsmodus).
- A11Y-014 (fokus etter påstands- og godkjenningshandlinger, `preserveScroll`).
- A11Y-024 (kjøringstidslinje).
- A11Y-050 (graf: oppsummering + listevisning).
- A11Y-017 (knappekontrast).
- A11Y-112, -113, -114, -117, -118.

**Berørte filer:** `Pages/App/Wiki/*`, `Components/App/WikiKnowledgeHandoffPanel.jsx`,
`Components/App/MultiSelectFilterDropdown.jsx`, `resources/css/app.css` (`.wiki-article`).

**Avhengigheter:** Fase 1–2. Arkitekturen i `docs/enterprise-wiki-architecture.md` gjelder. Endringene
er rene presentasjonsendringer og skal ikke røre ingest, claims, godkjenningsmodell eller lagret
innhold. Ved tvil om at en endring berører invariantene: stopp og rapporter.

**Risiko:**
- Middels til høy. Store filer og et sammensatt QA- og godkjenningsløp.
- Listevisningen for grafen er ny UI og må holdes enkel: gjenbruk eksisterende paneler.

**Teststrategi:**
- De 13 Wiki-E2E-spesifikasjonene som regresjon (`wiki-graph-filters.spec.js` har allerede
  tastaturtester).
- Nye tester: tastaturflyt for påstandsgodkjenning, redigeringsmodus og dialoger.
- Overskriftsstruktur (én h1).
- Manuell skjermlesertest av gjennomgang → godkjenning → publisering.

**Akseptansekriterier:**
- En QA-bruker kan godkjenne alle påstander på en side med bare tastatur, uten fokustap.
- Artikkelen har én h1 og logisk struktur.
- Grafens innhold er tilgjengelig som liste.
- Ingen axe-brudd.

### Fase 6 – Kvalitet/GRC, administrasjon og resterende flater

**Omfang:**
- Kvalitet (prosessflyt), Risiko, Mål og KPI, Avvik og forbedringer, Etterlevelse og revisjon,
  Ledelsens gjennomgåelse og Leverandøroppfølging.
- Filament `/admin` og rapport-PDF.

**Forbedringer:**
- A11Y-004 (prosessdiagram + stegliste med handlinger + strukturfelt), A11Y-018 (diagramkontrast),
  A11Y-128.
- A11Y-052 (KPI-graf).
- Skjemamigrering til `FormField` i de ca. 34 GRC-filene (A11Y-013).
- Innebygde paneler (A11Y-014, -015).
- A11Y-024 (sjekkliste og importsteg), A11Y-119, -120, -121, -127.
- Filament: A11Y-053 til A11Y-056 og A11Y-125. Beslutning om mørk modus: støtte eller deaktivere.
- PDF: lenke til HTML-versjonen (A11Y-126).

**Berørte filer:** `Pages/App/{Quality,Risk,Objectives,Improvements,Compliance,ManagementReview,SupplierManagement}/**`,
`Components/App/Process*`, `app/Providers/Filament/AdminPanelProvider.php`, `resources/views/filament/**`.

**Avhengigheter:** Fase 1–2.

**Risiko:**
- Middels. Mange filer, men modulene er nyere og konsistente, så endringene er i stor grad mekaniske.
- Prosessdiagrammet er den mest komplekse enkeltkomponenten.
- Endret primærfarge i Filament er synlig for interne brukere.

**Teststrategi:**
- `readability.js`-baserte spesifikasjoner (37) får axe automatisk via hjelperen.
- Nye tester for diagramtastatur og stegliste.
- axe i lys og mørk modus på `/admin`.

**Akseptansekriterier:**
- Prosesser kan redigeres fullt ut med tastatur og skjermleser via steglisten.
- Ingen axe-brudd i GRC-modulene og de egne Filament-sidene.

### Fase 7 – Samlet testing, regresjon og dokumentasjon

**Omfang:** full verifisering og dokumentasjon av samsvar.

**Aktiviteter:**
1. Full E2E-suite + axe på alle hovedruter, i lys og mørk modus der det er relevant (merge gate).
2. Manuell revisjon etter sjekklisten i §9.3 på et representativt utvalg (minst én side per modul +
   alle kritiske flyter).
3. Oppdatere `docs/testing/e2e.md` (den er utdatert: konfigfilnavn, dekningstabell og mobil).
4. Tilgjengelighetsprinsipper i `CLAUDE.md`/`AGENTS.md`, slik at ny kode følger reglene.
5. Grunnlag for en tilgjengelighetserklæring (uu-tilsynet), med kjente avvik og begrunnede unntak
   (f.eks. 2D-diagram, PDF).
6. Oppdatere statusen i dette dokumentet.

**Akseptansekriterier:** se §11.

---

## 9. Teststrategi

### 9.1 Automatisert

| Verktøy | Hva det dekker | Innføring | Kostnad |
|---|---|---|---|
| `@axe-core/playwright` (1 dev-avhengighet) | Regelbrudd i rendret DOM: etiketter, navn, roller, kontrast, nestet interaktivitet, overskrifter, landemerker, `aria-*`-gyldighet | `tests/e2e/helpers/a11y.js` med `expectNoAxeViolations(page)`, taggene `wcag2a`, `wcag2aa`, `wcag21aa`, `wcag22aa`. Kalles fra `expectReadable()`, slik at de 37 eksisterende spesifikasjonene får axe gratis. Nytt `a11y-smoke.spec.js` besøker alle hovedruter, inkludert `/admin`. En baseline-liste med kjente brudd per regel og side krymper fase for fase. | Lav |
| `eslint` + `eslint-plugin-jsx-a11y` (+ `eslint-plugin-react`) | Statiske mønstre: `onClick` uten tastatur, manglende `htmlFor`, `alt`, `aria-*`-gyldighet, `no-autofocus`, nestet interaktivitet | Flat `eslint.config.js`, avgrenset til `resources/js/**/*.jsx` og bare a11y-regler (ingen stilregler). Nytt skript `npm run lint:a11y`. Starter som advarsel og blir feil per mappe etter hvert som fasene fullføres. | Lav |
| Playwright tastaturtester | Fokusfelle og -retur i dialoger, hopp-lenke, tabrekkefølge i nøkkelflyter, synlig fokus (`getComputedStyle(':focus-visible')`), fokus etter innebygde paneler og skjemafeil | Bygger videre på eksisterende `toBeFocused`-mønster | Middels |
| Playwright reflow og forced-colors | 320×256 viewport i `readability.js` (1.4.10), `page.emulateMedia({ forcedColors: 'active' })`, `colorScheme: 'dark'` for Filament | Utvidelse av hjelpere | Lav |
| `node --test` | Ren logikk i nye hooks (`useFocusFirstError`, kunngjører-kø) | Eksisterende oppsett | Lav |

**CI:**
- Bare `dependency-audit.yml` finnes. Legg `npm run lint:a11y` inn i npm-jobben der, siden den allerede
  kjører `test:unit` på Node 22.
- E2E med axe krever Docker, Postgres, Redis og en seedet app. Det kjøres som i dag: lokalt som
  merge gate (jf. regelen om fulle suiter). En egen CI-jobb kan vurderes senere.
- Sidefunn: `tests/js/*.test.mjs` fanges ikke av `test:unit`-globben og kjøres derfor aldri. Rettes
  separat, utenfor dette prosjektet.

Nye dev-avhengigheter (`@axe-core/playwright`, `eslint`, `eslint-plugin-jsx-a11y`,
`eslint-plugin-react`) godkjennes før Fase 1 starter.

### 9.2 Hva automatikk ikke kan bevise

axe finner typisk 30–40 % av WCAG-problemene. Følgende må testes manuelt:

- om fokusrekkefølgen er logisk, og om fokus havner på riktig sted etter handlinger;
- om kunngjøringer kommer, er forståelige og ikke støyer (polling);
- om tekstalternativer, etiketter og feilmeldinger er meningsfulle;
- om informasjon formidles bare med farge der axe ikke ser det (status, valgt tilstand);
- kontrast over gradienter, bilder og halvtransparente flater;
- reflow og zoom i sammensatte visninger (diagram, matrise, AI-arbeidsflate);
- tooltip- og hover-oppførsel (1.4.13) og trykkflater med avstandsunntak (2.5.8);
- skjermleseroppførsel for SVG og canvas.

### 9.3 Manuell sjekkliste (per fase, per berørt side)

1. **Tastatur:** alle handlinger kan utføres med Tab, Shift+Tab, Enter, Space, Escape og piltaster
   der mønsteret krever det. Fokus er alltid synlig og aldri skjult under headeren. Ingen
   tastaturfeller.
2. **Skjermleser:**
   - NVDA + Firefox/Chrome (Windows) og VoiceOver + Safari (macOS). JAWS stikkprøvevis.
   - Kontroller navn, rolle og tilstand, overskriftsliste, landemerker og kunngjøringer etter lagring
     og asynkrone handlinger.
3. **Zoom og reflow:**
   - 200 % tekstzoom.
   - 400 % / 320 CSS px bredde uten horisontal scroll (unntatt tabeller og diagram).
   - Bokmerke for tekstavstand (1.4.12).
4. **Visuelt:** kontrastmåling (tekst ≥ 4,5:1, stor tekst og grafikk ≥ 3:1), gråtonevisning (farge
   alene), Windows høykontrast / forced-colors.
5. **Mobil:** VoiceOver iOS / TalkBack på hovedflyter. Trykkflater.

---

## 10. Designregler (innføres i Fase 1)

| Regel | Verdi |
|---|---|
| Fokus | `focus-visible:outline-2 outline-offset-2 outline-violet-600` (≈ 5,6:1 på hvitt). Ikke `outline-none` uten en erstatning med minst 3:1. Bruk `outline-hidden` (Tailwind 4) når en ring erstatter outline. |
| Informasjonstekst | Minst `slate-600` på farget bakgrunn og minst `slate-500` på ren hvit. `slate-400`/`300` brukes bare for dekor eller deaktivert innhold. |
| Tekststørrelse | Ikke under 12 px (`text-xs`). Brødtekst og felt er 16 px (følger eksisterende lesbarhetsregel). |
| Fylte knapper med hvit tekst | `*-700` eller mørkere for emerald, sky og amber. `violet-600` er OK. |
| Kanter og ikoner (ikke-tekst) | Minst 3:1: `slate-500` (4,77:1 på hvitt). `slate-400` gir bare 2,63:1 i Tailwind 4 og holder ikke (rettet i Fase 3, A11Y-136). |
| Feiltekst | `rose-700` (6,06:1). `rose-600` gir 4,51:1 på hvitt og 4,31:1 på `slate-50` – for knapt. |
| Feltkant | `border-field-border` (`oklch(63% 0.042 257)`): 3,49:1 mot hvitt, 3,34:1 mot `slate-50`, 3,19:1 mot `slate-100`. Ugyldig felt: `rose-600` via `aria-invalid`-regelen i `app.css`. Deaktiverte felt er unntatt. |
| Feil etter radhandling | `trackRowAction` + `useRowActionError`: meldingen ved knappen, knappen beskrevet av den, banneret er eneste `role="alert"`. |
| Status | Alltid tekst (synlig eller `sr-only`) i tillegg til farge og ikon. |
| Z-index | Header 60, overlegg og dialog 70, toast og kunngjøring 80. |
| Trykkflate | Minst 24×24 px for interaktive mål. |
| Skjemafelt | Alltid via `FormField`. Feil gir `aria-invalid` + `aria-describedby`. Første feil fokuseres. |
| Dialoger | Alltid `ActionDialog`. Ingen nye `fixed inset-0`-modaler. Bakgrunnen gjøres `inert` av `ActionDialog` (Fase 3). Egen header/footer: `padded={false}` + `DialogTitleBar`. |
| Tabeller | Rullbare tabeller i `ScrollRegion` (navngitt region og tabstopp bare når de ruller, plass til fokusringen). `<th scope>`, og radhandlinger med radens navn. |
| Skjemafeil | `useFocusFirstError` fra Inertia `onError`, eller `errorsOnMount` for skjemaer med vanlig POST. Aldri fokusflytting ved annen validering. |
| Dynamiske endringer | Via `useAnnounce()`. Ingen live regions som monteres sammen med innholdet sitt. |

---

## 11. Akseptansekriterier for prosjektet

Prosjektet er ferdig når alle punktene under er oppfylt:

1. Ingen åpne P0- eller P1-funn. Eventuelle unntak er begrunnet og dokumentert i tilgjengelighetserklæringen.
2. axe (WCAG 2.2 A/AA-tagger) gir null brudd på alle hovedruter i `/app`, på de offentlige sidene, på
   `/login` og på de egne `/admin`-sidene. Baseline-listen er tom.
3. `npm run lint:a11y` gir ingen feil.
4. Nøkkelflytene er verifisert med tastatur og minst to skjermlesere:
   - innlogging;
   - finne kunngjøring → lagre sak → Go/No-go;
   - last opp dokument → uttrekk → generer og rediger svar;
   - Wiki: last opp kilde → kjøring → påstandsgodkjenning → publisering;
   - opprette og redigere prosess;
   - registrere risiko, avvik og KPI-måling;
   - revisjon;
   - brukeradministrasjon og tilganger.
5. Reflow ved 320 px uten tap av innhold eller funksjon (unntak: 2D-diagram og datatabeller med
   horisontal rulling som kan nås med tastatur).
6. Ingen endring i autorisasjon, API, database eller forretningslogikk. Eksisterende PHP- og
   E2E-suiter er grønne.
7. Designreglene i §10 er dokumentert i `CLAUDE.md`, så ny kode følger dem.

---

## 12. Teknisk kompleksitet

| Del | Kompleksitet | Begrunnelse |
|---|---|---|
| Fokusstiler, kontrast, `aria-*`-attributter | Lav | Mekaniske klasse- og attributtendringer. De kan i stor grad samles i delte stiler. |
| `FormField` + migrering | Middels | Ca. 60 filer har egne konstanter. Migreringen er mekanisk, men berører mange skjemaer. |
| Fokusstyring i innebygde paneler | Middels | Ca. 40 steder, hver med egen tilstandslogikk. |
| Dialogmigrering | Lav–middels | 11 dialoger. `ActionDialog` finnes allerede. |
| Kunngjører og polling | Middels | Må kunngjøre overganger, ikke hver polling-runde. |
| AI- og Notices-filer | Høy (risiko) | 2 500–4 000 linjer per fil. Små, presise endringer. |
| Prosessdiagram | Høy | SVG-semantikk, fokusring i SVG og nye handlinger i steglisten. |
| Wiki-graf (Sigma) | Middels–høy | Krever en ny listevisning som alternativ. |
| Filament | Lav–middels | Få egne visninger. Beslutning om mørk modus. |

Samlet: moderat. Det meste av effekten kommer fra Fase 1–2.

---

## 13. Ikke verifisert / åpne punkter

Følgende kunne ikke avgjøres statisk og verifiseres i den fasen som berører dem:

- Faktisk skjermleseroppførsel for `svg role="img"` med fokuserbare barn (A11Y-004, -052).
- Om outline på SVG `<g>` vises i Firefox og Safari (A11Y-128).
- Stabling av toast og dialog mot headeren, og fokus skjult under headeren (A11Y-020).
- Om fokus faktisk faller når avkrysningsboksen i tilgangsmatrisen deaktiveres (A11Y-029).
- Rendret kontrast for OKLCH-farger, halvtransparente flater og gradienter, og Filaments amber-palett
  (A11Y-055).
- Mørk modus i Filament (A11Y-056).
- Reflow ved 320 px / 400 % for tilgangsmatrisen og Kildedokumenter-tabellen (`table-fixed`, 1060 px).
  Saksvisningen og AI-arbeidsflaten er kontrollert i Fase 4 (§18.8); Kunngjøringer/Saksliste ved 320 px er
  ikke E2E-testet.
- Om andre Wiki-generatorer enn ingest også starter med `# tittel`, og om GFM-tabeller forekommer i
  artikler (A11Y-046, -114). (Fase 5: 44 av 51 versjoner starter med `# tittel`, håndteres ved rendering; tabeller kommer som strukturerte blokker, §19.5.)
- Trykkflater med avstandsunntak (A11Y-106).
- Hvordan `wire:poll` i Filament påvirker fokus og skjermleser.
- Tagging av PDF (forventet: ikke tagget).
- Om polling (`router.reload`) noen gang flytter fokus eller nullstiller felt mens brukeren skriver.
  (Fase 4: AI-uttrekkets polling flytter ikke fokus i E2E; skriving under polling er ikke testet.)
- Om skjermlesere faktisk leser kunngjøringene fra AI-arbeidsflaten én gang (§18.9).

**Status etter Fase 7A (§21):** OPPF-001–004 er rettet (OPPF-001 lukkes formelt etter 7C); A11Y-153, -156, -161, -172, -184, -193, -195 og -196 er rettet; gjenstående forhold står i §21.5 og beslutningspunktene i §21.7. Teksten under er historikk.

**Åpne oppfølgingsoppgaver:** OPPF-001 (gjenåpnet i sluttkontrollen av Fase 6, §20.16.3), OPPF-002 (ustabil `wiki-graph-labels.spec.js:82`, også på baseline) og OPPF-003 (eksisterende PHP-feil i `WikiQualityTabRemovalTest`), begge må løses før merge (§19.17.3). OPPF-001 ble lukket i §19.17.3, men er gjenåpnet (§20.16.3). Fra Fase 4: A11Y-145 (utsatt produktbeslutning om ansvarlig-velgeren, §18.14). Fra Fase 5: A11Y-153, -154, -156 (§19.9). Fra Fase 6: OPPF-004 (eksisterende PHP-feil i `CustomerCreatePageTest`, må løses før merge) og A11Y-160, -161, -172, -184, -186, -193, -195, -196, -214 (§20.8).

**Beslutninger som trengs før eller i fasene:**

1. Godkjenning av dev-avhengighetene i §9.1 (før Fase 1).
2. Filament mørk modus: legge til støtte eller deaktivere den (Fase 6). **Besluttet i Fase 6: støttes** (§20.4).
3. Om `window.confirm` skal erstattes overalt (A11Y-107, ikke et AA-krav).
4. Om offentlige sider som er utkast (Security, Privacy, Terms) tas med nå eller når innholdet er
   endelig.

---

## 14. Endringslogg

| Dato | Endring |
|---|---|
| 2026-10-16 | Avslutning (§23): utviklingsarbeidet avsluttes, 7C gjennomføres ikke; full regresjon ikke kjørt, OPPF-001 ikke formelt lukket, åpne funn beholdt. Fase 7A og 7B committet. |
| 2026-10-16 | Fase 7B, første runde (§22): brukerens manuelle kontroll i Safari på macOS – tastatur, fokus ved sideskift, PageHelp-dialog, zoom 200/400 %, VoiceOver i Wiki – uten rapporterte problemer. Ingen nye funn, ingen avvik lukket. Gjenstående kontroller prioritert (§22.6). Ingen kodeendringer. |
| 2026-10-16 | Fase 7A (§21): OPPF-001 (årsak påvist: sen tittel fra `<Head>` mot fokusavgjørelsen i `routeFocus.js`, rettet i appen), OPPF-002–004 rettet; A11Y-153, -156, -161, -172, -184, -193, -195, -196 og Wiki/Varsler-delen av -105/-155 rettet; systemisk restforhold (`disabled` under lagring, 126 kontroller) klassifisert; A11Y-145, -126, -154 som beslutningspunkter. Enhet 2065/0, målrettet PHP 327/0, målrettet E2E 125/0. Ikke committet. |
| 2026-10-16 | Sluttkontroll av Fase 6 (§20.16): ingen Fase 6-regresjoner; samlet E2E 255/1/0, feilen er OPPF-001, som er gjenåpnet; OPPF-002–004 åpne før merge. Committet. |
| 2026-10-16 | Fase 6 implementert og automatisk verifisert (§20): Kvalitet (diagram som navngitt gruppe, stegliste med redigering og innsetting, struktureditor med navn), Risiko, Avvik, Mål og KPI (graf som bilde med sammendrag), Ledelsens gjennomgåelse (statustekst, HTML-alternativ til PDF), Etterlevelse og revisjon, Leverandøroppfølging (import), offentlige sider og resterende felleskomponenter; Filament med mørk modus støttet, mørkere primærfarge, vedleggsknapp, driftssider. `lint:a11y` 189 filer. Nye funn A11Y-160 til A11Y-215, OPPF-004. Ikke committet. |
| 2026-10-16 | Sluttkontroll av Fase 5 (§19.17): ingen regresjoner; OPPF-001 lukket (kriteriet oppfylt, årsak ikke endelig funnet); OPPF-002 og ny OPPF-003 åpne før merge. Committet. |
| 2026-10-16 | Fase 5 implementert og automatisk verifisert (§19): grafens listevisning på samme data (graf ↔ liste, detaljer, relasjoner, fokusliste), artikkelens Markdown-overskrifter ett nivå ned (én h1), navngitte redigeringsfelt med feil på riktig avsnitt, påstandsbeslutninger uten fokustap, gjennomgangspanel med navn og fokusretur, fire Wiki-dialoger til `ActionDialog`, filtre og søk navngitt, kjøringsstatus som overganger, Spør Wiki og kunnskapsoverføringen. Nye funn A11Y-148 til A11Y-156, OPPF-002. OPPF-001-kriteriet oppfylt. Ikke committet. |
| 2026-10-15 | Teknisk sluttkontroll av Fase 4 (§18.14): A11Y-146 og ny A11Y-147 (tom «Legg til BR») rettet, dobbel opplesing av opplastingsfeil rettet, A11Y-145 utsatt som produktbeslutning, samlet regresjon 196/196. |
| 2026-10-15 | Fase 4 implementert og automatisk verifisert (§18): Go/No-go med gruppe og `aria-pressed`, saksforløp med tekstlig status, alle skjemaer i Notices/AI via `FormField` med fokus til første feil, kravkortet uten `role="button"`, statusoverganger for kravuttrekk og generering via `announce()`, `FilePickerField` for AI-opplasting, «Velg ansvarlig» som `ActionDialog`, vertikal `ScrollRegion`, CPV-listeboks, reflow ved 320 px. Nye funn A11Y-139 til A11Y-146. Ikke committet. |
| 2026-10-15 | Fase 3 godkjent for commit med én kjent E2E-feil (OPPF-001, eksisterende på `683246a7`, må rettes før merge). |
| 2026-10-15 | Teknisk sluttkontroll av Fase 3 (§17.12): A11Y-019 (feltkant 3,49:1), A11Y-137 (feil ved radhandlingen) og A11Y-138 (én flash-melding) rettet; `modalInert` sikret mot gjenværende `inert`. |
| 2026-10-15 | Fase 3 implementert og automatisk verifisert (§17): `ActionDialog` med `inert` bakgrunn og modalstabel, 9 egne dialoger (5 komponenter) migrert, `FormField` tatt i bruk i 10 skjemaer, `useFocusFirstError`, `ScrollRegion`, `InfoHint` med gyldig HTML og pekerbro, tilgangsmatrise uten fokustap. Nye funn A11Y-135 til A11Y-138. Ikke committet. |
| 2026-10-14 | Teknisk sluttkontroll av Fase 2 (§16.12): A11Y-134 rettet, tittelavlesning ved første sideskift rettet, samlet E2E-regresjon på endelig kode 161/0. |
| 2026-10-14 | Fase 2 implementert og automatisk verifisert (§16): hopp-lenke, navngitte landemerker, fokus ved sideskift, flash/toast, mobilmeny, innlogging, dashboardkalender, kontrast, sidetitler. Nye funn A11Y-129 til A11Y-134. Ikke committet. |
| 2026-10-14 | Teknisk sluttkontroll av Fase 1 (§15.8): to rettelser (kunngjøringer i rask rekkefølge, `descriptionId` på `ActionDialog`), E2E-timeout undersøkt, CI og avhengigheter kontrollert. Ikke committet. |
| 2026-10-14 | Fase 1 implementert og automatisk verifisert (§15). Ikke committet. |
| 2026-10-14 | Fase 0: kartlegging av hele kodebasen (statisk analyse av felles komponenter, alle moduler, Filament og testoppsett), funnliste og faseplan opprettet. |

---

## 15. Fase 1 – gjennomføringsrapport (2026-10-14)

Begrepene under betyr:

- **Kode** = implementert.
- **Auto** = verifisert av automatiske tester (enhet, lint, axe, E2E).
- **Manuell** = verifisert med tastatur, skjermleser, zoom eller visuelt av en person.

Ingen manuell verifisering med skjermleser er gjort. Sluttkontrollen i §15.8 skiller mellom automatisk verifisert, agentkontrollert i nettleser og ikke verifisert.

### 15.1 Beslutninger (fra gjennomgangen av Fase 0)

- Utviklingsavhengigheter er godkjent. Bare nødvendige pakker er installert. Det fantes ingen ESLint fra før, så det er ikke opprettet noe parallelt lint-oppsett.
- Mørk modus i Filament beholdes. Den kontrolleres og utbedres i Fase 6.
- `window.confirm` beholdes (A11Y-107 lukkes som «ikke tiltak» med mindre et konkret problem dukker opp).
- Alle offentlige sider er med. Uferdig juridisk og sikkerhetsrelatert innhold skal ikke omskrives.

### 15.2 Implementert

| Del | Hva | Filer | Status |
|---|---|---|---|
| A. Fokusmarkering | Én indikator for hele appen: 2 px `violet-600` outline (≈ 5,6:1), samme som `PRIMARY_COLOURS` brukte. Grunnregel i `@layer base`. En regel utenfor lag gjenoppretter outline på tastaturfokus for elementer med `outline-none`/`focus:outline-none`/`focus-visible:outline-none`. Alle 242 forekomster ble klassifisert (skript): 239 hadde erstatning under 3:1, og 3 hadde egen indikator med `violet-500` eller `sky-500`. Tekstfelt får outline 2 px innenfor kanten (`offset -2px`), så den ikke klippes av omsluttende elementer. Elementer kan reservere seg med `data-focus-indicator="own"`. | `resources/css/app.css` | Kode + auto (innlogging, dialog, filvelger). Manuell gjenstår. |
| B. Felles skjemastiler | `Support/formStyles.js` med `INPUT`, `TEXTAREA`, `LABEL`, `HINT` og `ERROR`, ordrett lik de 50 kopiene (ingen visuell endring). Sidene migreres når de berøres i senere faser. | `resources/js/Support/formStyles.js` | Kode |
| C. Skjemakomponenter | `fieldA11y()` bestemmer id-er og attributter: `id`, stabile `-hint`/`-error`-id-er, `aria-describedby` (hint før feil), `aria-invalid` bare ved feil, `aria-required`. `FormField` rendrer etiketten med `htmlFor`, og `info` (f.eks. `InfoHint`) står **utenfor** `<label>` (hindrer A11Y-001). Kontrollen leveres som render-funksjon. `focusFirstInvalidField()` for Inertia `onError`. Ingen skjemaer er migrert. | `Support/formFieldA11y.js`, `Components/App/FormField.jsx`, `Support/focusManagement.js` | Kode + auto (enhet) |
| D. Dialoger og fokus | `ActionDialog`: `z-[70]` (over headeren `z-[60]`), `size` (`md`/`lg`/`xl`), `padded`, `fallbackFocusRef` når utløseren er borte, `<body>` regnes ikke som retursted, bruker felles `focusableWithin`. Bakgrunnen er `role="presentation"`. `usePanelFocus()` for innebygde paneler (fokus inn ved åpning, tilbake eller til reserve ved lukking, bare ved overganger). `PageHelpPanel` tar fokus ved åpning og gir det tilbake til Hjelp-knappen. Sluttkontrollen la til `descriptionId` (`aria-describedby`) og en `focusin`-sikring i fellen (§15.8). | `Components/App/ActionDialog.jsx`, `Support/focusManagement.js`, `Components/App/PageHelpPanel.jsx`, `PageHelpButton.jsx` | Kode + auto (E2E) |
| E. Statusmeldinger | Tre vedvarende regioner opprettes i `<body>` ved oppstart: `polite`, `assertive` og `toast`. De bruker `aria-live` uten `role`, så de ikke havner i sidenes rolletre. `announce()` har duplikatfilter (1,5 s) og tømmes etter 7 s. Suksess-toasten portales inn i `toast`-regionen, slik at synlig tekst og opplest tekst er den samme, uten skjult kopi. Feil-flash har `role="alert"`. Sluttkontrollen rettet kunngjøringer i rask rekkefølge (§15.8). | `Support/announcer.js`, `app.js`, `Layouts/CustomerAppLayout.jsx` | Kode + auto (E2E: toasten i live region, teksten finnes én gang) |
| F. FilePickerField | Synlig fokus på knappen via `peer-focus-visible`. Navn = bildetekst + knapp («Excel-fil (.xlsx) Velg fil»). Beskrivelse = valgt fil + hint + feil. `aria-invalid` og `role="alert"` ved feil. Hint og feil har `slate-600`/`rose-700` (som `formStyles`). | `Components/App/FilePickerField.jsx` | Kode + auto (E2E + axe) |
| i18n | `procynia.a11y.help_eyebrow` og `help_close` (no/en), delt som `translations.a11y`. | `lang/{no,en}/procynia.php`, `HandleInertiaRequests.php` | Kode + auto |
| Verktøy | `@axe-core/playwright` ^4.13.0, `eslint` ^9.39.5 og `eslint-plugin-jsx-a11y` ^6.10.2 (dev). `npm run lint:a11y`. Lint-steg i `dependency-audit.yml`. | `package.json`, `package-lock.json`, `eslint.config.js`, `.github/workflows/dependency-audit.yml` | Kode + auto |

### 15.3 Tester og resultater

| Test | Resultat |
|---|---|
| `npm run test:unit` (Node 22, Docker) | 1631/1631. Ny: `resources/js/Support/a11yFoundation.test.js`. |
| `npm run lint:a11y` (Node 22) | 0 feil. Én reell feil i `ActionDialog`-bakgrunnen ble rettet med `role="presentation"`, ingen regel er slått av. |
| `php artisan test --filter=A11yTranslationsTest` | 2/2 (ny: `tests/Unit/A11yTranslationsTest.php`) |
| Pint på berørte PHP-filer | Bestått |
| `npm run build` | Bestått. Fokusreglene finnes i bygd CSS. |
| `tests/e2e/a11y-foundation.spec.js` (ny) | 3/3: fokusindikator på alle tabstopp i innloggingsskjemaet; filvelger (navn, beskrivelse, synlig fokus, feil som alert, axe på seksjonen); hjelpepanel (fokus inn/ut, axe); `ActionDialog` (fokusfelle, over headeren, Escape → utløser, axe) og suksess-toast i live region. |
| Regresjon, 12 spesifikasjoner (compliance-library, supplier-import, quality-tools, knowledge-handoff, quality-process-row-actions, info-center-help, wiki-graph-filters, objective-kpi-target-types, breadcrumbs, app-navigation, auth, billing) | 107/108 i samlet kjøring. Testen som feilet («both rows survive a phone») består isolert. Ved full kjøring av `app-navigation.spec.js` overskred «Basis, ISO, ISO + Anbud and GRC: each its own rail» tidsgrensen på 30 s (fire seedinger i én test). Den består med `--timeout=120000` (41,7 s) og hadde ingen påstandsfeil. Vurdert som tidsfølsom test, ikke forårsaket av Fase 1. Det er ikke bevist med baseline mot HEAD. **Oppfølging i sluttkontrollen (§15.8.4): ikke reprodusert, ikke knyttet til Fase 1.** |
| Full PHP- og E2E-suite | Ikke kjørt (merge gate, jf. prosjektregel) |

### 15.4 Funnstatus etter Fase 1

| ID | Status |
|---|---|
| A11Y-010 | Kode + auto. Gjenstår: manuell kontroll (Firefox/Safari, forced-colors, visuelt på alle moduler) og opprydding av doble indikatorer der gamle ringer står igjen (rent kosmetisk). Sluttkontrollen fant at ringen klippes på én side for lenker i første og knapper i siste kolonne i tabeller med `overflow-x-auto` (synlig på tre sider; Fase 3, §15.8.2). |
| A11Y-011 | `FilePickerField` lukket (kode + auto). Gjenstår: egne filfelt i `AI/Show.jsx` (Fase 4) og `Wiki/Index.jsx` (Fase 5), fokus etter feil og ny montering i `SupplierManagement/Import.jsx` (Fase 6), fremdriftsvisning. |
| A11Y-012 | Delvis: suksess annonseres via vedvarende region, feil er `role="alert"`. Gjenstår (Fase 2): toasten skjules etter 3 s, toastens z-index, warning-flash, flash i `PublicLayout`. Kunngjøringer i rask rekkefølge er rettet (§15.8.3). Om skjermlesere faktisk leser toasten er ikke verifisert. |
| A11Y-020 | Delvis: `ActionDialog` ligger over headeren (auto). Gjenstår: toast, `scroll-padding-top`, `inert` (Fase 2). |
| A11Y-104 | Lukket for `PageHelpButton` (kode + auto). `GoNoGoAssessment` bruker `PageHelpPanel` uten `returnFocusRef` (Fase 4). |
| A11Y-105 | Delvis: hjelpepanelets strenger er oversatt. Resten er åpent. |
| A11Y-001, -002, -013, -014 | Infrastrukturen finnes (`FormField`, `ActionDialog`-utvidelser, `usePanelFocus`, `focusFirstInvalidField`). Sidene er ikke migrert (Fase 3–6). Vurdering: alle 11 egne dialoger kan erstattes direkte med `ActionDialog` (`size="lg"` for de brede, `padded={false}` for dialoger med egen header- og footerlinje). |
| A11Y-052, -128 | Delvis: SVG-elementer med `focus:outline-none` (KPI-punkter) får nå outline via regelen. Uverifisert i Firefox/Safari. |
| A11Y-107 | Lukket som «ikke tiltak» (beslutning 3). |
| Øvrige | Uendret, åpne. |

### 15.5 Risikoer og kjente bivirkninger

- **Synlig endring:** tekstfelt matcher `:focus-visible` også ved museklikk, så alle tekstfelt får en 2 px fiolett kant når de har fokus. Tidligere ble kanten `slate-400` eller `violet-300`. Dette er bevisst (tydelig fokus), men det er den mest synlige endringen i Fase 1 og bør vurderes visuelt. Knapper får outline bare ved tastaturfokus.
- **Doble indikatorer:** elementer som beholder en gammel ring (`focus:ring-violet-100` og lignende) viser nå ring + outline. `SupplierDetailsSection.jsx:24` og `RequirementTemplates.jsx:117` hadde allerede en god ring (`violet-500`) og viser to. Kan ryddes med `data-focus-indicator="own"` når filene berøres (Fase 6).
- **Toasten er flyttet i DOM-en** (portal til `<body>`). Den ser lik ut og står på samme sted, men er ikke lenger inne i `<main>`.
- **ESLint 9 er merket «deprecated».** ESLint 10 er siste versjon, men `eslint-plugin-jsx-a11y` 6.10.2 støtter bare til og med ESLint 9. ESLint 9 starter ikke på vertens Node 18.15, så det kjøres med Node 22 (CI, Docker).
- **`npm audit` feiler allerede på HEAD** (5 advisories: `browserslist`, `baseline-browser-mapping`, `shell-quote` via `concurrently`, `source-map-js`). Dette er ikke innført av Fase 1, men CI-jobben `npm audit` vil feile til det rettes separat.
- **Tidsfølsomme E2E-tester** i `app-navigation.spec.js`: undersøkt i §15.8.4, ikke reprodusert og ikke knyttet til Fase 1.

### 15.6 Ikke gjennomført / ikke verifisert

- Ingen manuell verifisering: NVDA/JAWS/VoiceOver, Firefox og Safari, forced-colors/høykontrast, 200/400 % zoom.
- Om skjermlesere faktisk leser toasten i live-regionen etter Inertia-redirect (E2E bekrefter bare DOM-strukturen).
- Fokusindikatoren er ikke kontrollert visuelt i alle moduler.
- Filament er ikke berørt.

### 15.7 Anbefaling for Fase 2

1. Manuell kontroll av Fase 1 først: tastaturrunde og skjermleser på innlogging, ett skjema per modul, `ActionDialog`, hjelpepanel og en lagring med flash.
2. Fase 2 som planlagt i §8:
   - hopp-lenke og navngitte `nav`;
   - toasten uten auto-skjuling (lukkeknapp) og over headeren;
   - warning-flash og `PublicLayout`-flash via samme mønster;
   - `scroll-padding-top`;
   - brukermeny som disclosure;
   - mobilmeny på offentlige sider;
   - innlogging (`<main>`, feil via `FormField`);
   - dashboardkalender;
   - fokus eller kunngjøring ved sidebytte.
3. Utvid `eslint.config.js` og de axe-skopede E2E-kontrollene med hver fil og flate Fase 2 gjør ren.
4. Ta opp `npm audit`-funnene som egen sak før merge, siden CI ellers er rød.

### 15.8 Teknisk sluttkontroll av Fase 1 (2026-10-14)

Målrettet kontroll før godkjenning og commit. Branch `feature/wcag-accessibility`, utgangspunkt `bc9070c2`. Alt arbeid fra Fase 1 er bevart. Fase 2 er ikke startet.

Begrepene i denne delen:

- **Auto** = verifisert av en automatisk test som ligger i repoet.
- **Agent** = kontrollert av agenten i en ekte nettleser (Playwright-skript og skjermbilder). Dette er ikke manuell testing og erstatter den ikke.
- **Ikke verifisert** = ikke kontrollert.

#### 15.8.1 Rettelser

| # | Funn | Rettelse | Filer | Test |
|---|---|---|---|---|
| 1 | Kunngjøringer i rask rekkefølge (A11Y-012): `announce('A')` fulgt av `announce('B')` innen 50 ms skrev først A og deretter B i regionen. En skjermleser kan da lese den foreldede meldingen. Feilen ble påvist mot den gamle koden. | Hver kanal holder skrive- og tømmetimeren som venter. En ny melding avbryter begge, så bare den siste skrives, og den tømmes etter 7 s. | `Support/announcer.js` | Auto (enhet, med falsk DOM og manuell klokke) |
| 2 | `ActionDialog` kunne ikke knytte en beskrivelse til dialogen. Fokus lander på en knapp, så skjermleseren hørte tittel og knapp, men ikke konsekvensen. | Ny valgfri `descriptionId` → `aria-describedby`. Eksisterende bruk er uendret. | `Components/App/ActionDialog.jsx` | Auto (enhet) |
| 3 | Fokusfellen i `ActionDialog` fanget bare Tab på det siste elementet den selv kjenner. Safari hopper som standard over knapper og lenker, og kan flytte fokus fra et felt i dialogen til et felt utenfor. | En `focusin`-sikring henter fokus som havner utenfor tilbake inn i dialogen, i den retningen brukeren tabbet. Sikringen fjernes før fokus returneres til utløseren. Ingen av de 13 eksisterende dialogene har portalert innhold som kan få fokus, og begrensningen er dokumentert i filhodet. | `Components/App/ActionDialog.jsx` | Auto (enhet + E2E: programmatisk fokus til headeren trekkes tilbake) |
| 4 | Toppkommentaren i CI-jobben nevnte ikke lint-steget. | Kommentaren er oppdatert. Ingen steg er endret. | `.github/workflows/dependency-audit.yml` | YAML validert |

#### 15.8.2 Kontrollerte områder

**Fokusmarkering (`app.css`).** Agent: Chromium på `/login`, `/app/dashboard` og `/app/quality`, med tastatur og mus.

- Med tastatur er markeringen en 2 px `violet-600`-ramme på header, sidemeny, kort og knapper i hjelpepanelet.
- Museklikk på knapper og på Hjelp gir ingen markering (`:focus-visible` matcher ikke).
- Tekstfelt får markeringen også ved museklikk. Det er nettleserens regel for tekstfelt og er bevisst (§15.5).
- Med forced-colors (emulert i Chromium) tegnes markeringen i systemfarge. Det betyr at den ikke er avhengig av `box-shadow`.
- Fargeovergangen fra tekstfarge til fiolett tar 150 ms (Tailwind `transition`). E2E-hjelperen venter på den.
- Hjelpepanelet får en fiolett kant når det åpnes med tastatur, ikke med mus.

Ett funn: en skanning av 478 fokuserbare elementer på 9 sider fant 216 der ringen klippes på én side. Alle ligger på `/app/quality`: lenken i første kolonne og «Handlinger»-knappen i siste kolonne i tabeller med `overflow-x-auto`. Ringen er fortsatt synlig på tre sider, så 2.4.7 er oppfylt. Chromes egen fokusring ble klippet på samme sted før Fase 1, så dette er ingen regresjon. En global regel er ikke brukt, fordi den ville lagt ringen innenfor lenketeksten. Anbefalt løsning er felles tabellramme med innvendig luft i Fase 3. Doble indikatorer (gammel ring + ny ramme) finnes fortsatt der eldre `focus:ring-*` står igjen. Det er kosmetisk (§15.5).

**Skjemakomponenter (`FormField`, `formFieldA11y`, `formStyles`).** Kode og auto:

- `htmlFor`/`id` er på plass, og `aria-describedby` peker bare på hint og feil som faktisk rendres (samme betingelse).
- `aria-invalid` brukes bare ved feil, og `aria-required` ved påkrevd felt.
- ID-er avledes fra kontrollens id og er derfor stabile. `useId` gjøres trygg for CSS med `domSafeId` (React 19.2 gir `_r_1_`).
- `InfoHint` står utenfor `<label>`, så A11Y-001 kan ikke oppstå.
- `InfoHint` har fra før `<div role="tooltip">` inne i `<span>`. Det er formelt ugyldig HTML, men kommer ikke fra Fase 1, og axe melder det ikke. Det rettes med InfoHint-arbeidet (plan Fase 1 punkt 9, ikke utført).
- Feilteksten i `FormField` er ingen live region. Den høres når `focusFirstInvalidField()` flytter fokus til feltet, så skjemaer som tas i bruk må kalle den fra `onError` (Fase 3).
- Komponentene kan brukes videre uten endring.

**Dialoger og fokus.** Auto i Chromium:

- Fokus går inn ved åpning.
- Tab og Shift+Tab holdes inne.
- Escape lukker og gir fokus tilbake til utløseren.
- Dialogen ligger over headeren.
- axe finner ingen brudd.
- Fokus som flyttes ut, hentes tilbake (ny).

Reservemålet (`fallbackFocusRef`) er dekket av en enhetstest av utvalgsregelen. Rekkefølgen er analysert: React fjerner DOM-noder før `useEffect`-opprydding, så en utløser som fjernes i samme render som dialogen lukkes, regnes som borte. Det er ikke dekket av E2E før en side tar det i bruk (Fase 3).

`PageHelpPanel` er ikke modal, med vilje. Escape lukker det uansett hvor fokus står. Dialogtittelen gis med `aria-labelledby`, og beskrivelsen nå med `descriptionId` (rettelse 2).

**Statusmeldinger.** Auto:

- Toasten etter en redirect ligger i den vedvarende `polite`-regionen, og teksten finnes én gang.
- Feil-flash har `role="alert"`.
- De skjulte regionene er tomme, fordi ingen kode kaller `announce()` ennå. Det gir ingen skjulte duplikater.
- Regionene har ikke `role`, så de havner ikke i sidenes rolletre.
- Duplikatfilteret (1,5 s) og rask rekkefølge (rettelse 1) er dekket av enhetstester.

Ikke verifisert: at NVDA, JAWS og VoiceOver faktisk leser toasten og alerten etter en Inertia-redirect.

**Filopplasting (`FilePickerField`).** Auto (Chromium, og WebKit med full tastaturtilgang):

- Navnet er «Excel-fil (.xlsx) Velg fil».
- Beskrivelsen er filnavn, hint og feil.
- Tab gir synlig ring på den synlige knappen.
- En avvist fil gir `aria-invalid` og `role="alert"`.
- axe finner ingen brudd på seksjonen, verken før eller etter feil.

Filbehandlingen er ikke endret. Når inputen monteres på nytt (`inputKey`) etter en tilbakestilling, kan fokus falle til `<body>`. Det er registrert under A11Y-011 (Fase 6).

#### 15.8.3 Statusmeldinger ved raske endringer

Se rettelse 1. I tillegg: suksess-toasten bruker sin egen region, slik at den ikke konkurrerer med `announce()`. Samme suksessmelding to ganger etter hverandre blir lest begge gangene, fordi toasten fjernes etter 3 s og settes inn på nytt. Det er riktig. Toasten skjules fortsatt etter 3 s, og det er Fase 2.

#### 15.8.4 E2E-timeout i `app-navigation.spec.js`

- Seedingen er målt til ca. 1,5 s per `NavigationE2EFixture::packageCustomer` (fire kall ≈ 6 s). Resten er fire innlogginger og fire dashbord-lastinger i én test.
- Testen ble målt med Fase 1-koden og Vite i dev-modus. Isolert to ganger: ca. 17 s hver. Hele filen: 33/33, 15,8 s. Samlet regresjonskjøring: 20,2 s. Grensen er 30 s, og testen har aldri overskredet den her.
- Den tidligere timeouten og kjøringen på 41,7 s skjedde før en omstart av maskinen. Vite-dev-serveren startet 20:29, og belastningen var høy under oppstarten (load average 10). Forholdene kan ikke gjenskapes.
- Fase 1 endrer ikke seedingen eller innloggingen. På serversiden legger den bare til to oversatte strenger i `share()`. I klienten kjører den `ensureLiveRegions()` én gang og legger til CSS-regler. Ingen av delene gir målbar lasttid.
- **Vurdering:** ikke reprodusert og ikke knyttet til Fase 1. Testen bruker likevel to tredjedeler av budsjettet på en travel maskin. **Anbefaling:** del den i én test per pakke (fire tester med hver sin tidsgrense), som egen sak. Global timeout skal ikke økes.

#### 15.8.5 CI og sikkerhet

- `dependency-audit.yml`: `composer audit`, `npm ci`, enhetstestene og `npm audit` er uendret. Det eneste nye er steget `npm run lint:a11y`. Det finnes ingen `continue-on-error`, ingen ignoreringsliste og ingen terskel.
- CI-jobben er simulert i `node:22` med ren `npm ci` på Linux: installasjonen lyktes, 1635/1635 tester besto (etter siste rettelse), og lint ga 0 feil.
- Lint er bevist å stoppe en regresjon: fjernes `role="presentation"` i `ActionDialog`, feiler `no-static-element-interactions` med exit 1.
- Lockfilen har 172 nye pakker, alle dev-avhengigheter. Ingen eksisterende pakke har endret versjon, og ingen er fjernet.
- `npm audit --package-lock-only` gir **samme 5 sårbarheter på HEAD og på branchen** (1 moderat, 2 høye, 2 kritiske):

  | Pakke | Alvorlighet |
  |---|---|
  | `baseline-browser-mapping` | moderat |
  | `browserslist` | høy |
  | `source-map-js` | høy |
  | `shell-quote` (via `concurrently`) | kritisk |
  | `concurrently` | kritisk, via `shell-quote` |

  Fase 1 legger ikke til noen sårbarheter. Dette er ikke rettet her. `npm audit` feiler i CI til det rettes som egen sak.
- E2E kjøres ikke i CI. Fase 1-spesifikasjonen kjøres lokalt.

#### 15.8.6 Testresultater (etter rettelsene)

| Test | Resultat |
|---|---|
| `npm run test:unit` (Node 22, Docker) | 1635 bestått, 0 feilet, 0 hoppet over |
| `npm run lint:a11y` (Node 22) | 0 feil |
| CI-simulering (ren `npm ci`, Node 22, Linux) | installasjon OK, 1635/0, lint 0, `npm audit` exit 1 (5 eksisterende sårbarheter) |
| `php artisan test --filter='A11yTranslationsTest\|SharedTranslationsLocaleTest'` | 6 bestått (40 påstander) |
| Pint på berørte PHP-filer | Bestått (4 filer) |
| `npm run build` | Bestått, fokusregler og live-regioner finnes i bygget |
| E2E Chromium: `a11y-foundation` + 13 regresjonsspesifikasjoner (de 12 fra §15.3 og `compliance-library-frameworks`), før rettelse 3 | 112 bestått, 0 feilet, 0 hoppet over (629 s) |
| E2E Chromium: samme 14 spesifikasjoner, etter rettelse 3 | 111 bestått, 1 feilet, 0 hoppet over (621 s). Feilen: «a phone never gets a collapsed rail …» fikk `net::ERR_ABORTED` på `page.goto("/app/notices")`. Det er en avbrutt navigasjon, ingen påstandsfeil. Testen besto i første kjøring, og deretter isolert 3/3 og hele `app-navigation.spec.js` 33/33. Siden har ingen åpen dialog. Vurdert som ustabil, ikke regresjon. |
| E2E WebKit (Safari-motor): `a11y-foundation`, kopi med Option+Tab (Safari med full tastaturtilgang) | 3 bestått, 0 feilet |
| E2E WebKit: `a11y-foundation` med vanlig Tab | 3 feilet. Årsak: Safari hopper som standard over knapper og lenker med Tab, og Playwrights WebKit gjør det samme. Biblioteksiden har ingen felt utenfor dialogen, så Tab flytter fokus ut av dokumentet, og det kan ingen side hindre. Ikke en feil i appen. Safari-brukere som bruker tastatur må slå på full tastaturtilgang. |
| Reflow (agent, Chromium 320 og 640 px bredde) | Ingen sidelengs rulling (`scrollWidth` = bredde) på innlogging, dashboard og Kvalitet, ved 320 og 640 px. Hjelpepanelet fyller 320 px, «Lukk hjelpepanel» er synlig, fokus går inn i panelet, og Escape gir fokus tilbake til Hjelp. Faktisk nettleserzoom er ikke testet. |
| Firefox | Ikke kjørt: Playwright-Firefox er ikke installert |
| Full PHP- og E2E-suite | Ikke kjørt (merge gate) |

#### 15.8.7 Manuell kontroll – status og sjekkliste

| # | Kontroll | Status |
|---|---|---|
| 1 | Tastaturnavigasjon gjennom sentrale sider | Agent (Chromium: header, sidemeny, kort; WebKit med Option+Tab). Manuell: ikke verifisert. |
| 2 | Fokus med Tab og Shift+Tab | Auto (innlogging, dialog). Agent (dashboard). Manuell: ikke verifisert. |
| 3 | Dialoger med Enter, Escape og Tab | Auto (Chromium, WebKit). Manuell: ikke verifisert. |
| 4 | Fokus etter åpning og lukking av paneler | Auto (hjelpepanel, dialog). Manuell: ikke verifisert. |
| 5 | Filopplasting uten mus | Auto (Tab, ring og feil). Faktisk filvalg med Space/Enter i nettleserens dialog: ikke verifisert (Playwright setter filen direkte). |
| 6 | Skjermleser: status og feil | Ikke verifisert |
| 7 | Zoom 200 % og 400 % | Reflow ved 640/320 px: se 15.8.6. Faktisk nettleserzoom: ikke verifisert. |
| 8 | Reflow og små skjermer | Se 15.8.6 |
| 9 | Kontrast og høykontrast | Ringen er beregnet til ca. 5,6:1 på hvitt. forced-colors er emulert (agent). Windows-høykontrast: ikke verifisert. |
| 10 | Firefox, Safari, VoiceOver, NVDA | WebKit-motoren: auto/agent. Ekte Safari, Firefox, VoiceOver og NVDA: ikke verifisert. |

**Sjekkliste for manuell kontroll før Fase 1 regnes som manuelt verifisert:**

1. **Safari + VoiceOver (macOS)**, med «Trykk Tab for å markere hvert objekt» på:
   1. Logg inn bare med tastaturet.
   2. Gå til `/app/quality`, Tab til «Hjelp» og trykk Enter. VoiceOver skal lese «Om Kvalitet, dialog».
   3. Trykk Escape. Fokus skal stå på «Hjelp».
2. **Kravbibliotek** (`/app/compliance/library`):
   1. «Aktiver for virksomheten» åpner dialogen. VoiceOver/NVDA skal lese tittelen.
   2. Tab og Shift+Tab skal bli i dialogen. Escape skal gi fokus tilbake til knappen.
   3. Bekreft. Suksessmeldingen skal leses én gang uten at fokus flyttes.
3. **Leverandørimport** (`/app/supplier-management/import`):
   1. Tab til «Velg fil». Navnet skal leses som «Excel-fil (.xlsx) Velg fil», med hint.
   2. Space skal åpne nettleserens fildialog.
   3. Velg en `.txt`-fil og send inn. Feilen skal leses straks (alert).
4. **NVDA + Firefox og NVDA + Chrome (Windows):** samme tre flyter. Kontroller også at toasten leses etter redirect, og at feil-flash leses.
5. **Windows-høykontrast:** fokusringen synes på knapper, lenker og tekstfelt.
6. **Zoom:** nettleserzoom 200 % og 400 % på dashboard, Kvalitet med hjelpepanel og dialog. Ingen innhold skal falle bort, og bare tabeller skal rulle sidelengs.
7. **Visuell gjennomgang:** én side per modul med Tab, for å finne klippede eller doble ringer ut over de som er kjent (15.8.2).

#### 15.8.8 Gjenværende risiko

- Ingen skjermleser er testet. Live-regionene, alerten og dialognavnene er bare verifisert som DOM og tilgjengelighetstre.
- Ekte Safari og Firefox er ikke testet. WebKit i Playwright er nærmest Safari.
- Klippet ring i tabeller (15.8.2) og doble ringer: kosmetisk, men synlig. Rettes i Fase 3 og 6.
- `npm audit` er rød i CI (eksisterende, 5 sårbarheter) til det rettes som egen sak.
- Den tidsfølsomme testen i `app-navigation.spec.js` kan feile igjen på en travel maskin (15.8.4).
- Innhold som portaleres ut av en `ActionDialog`, kan ikke få fokus mens dialogen er åpen (bevisst, dokumentert).

#### 15.8.9 Anbefalinger for Fase 2

I tillegg til §15.7:

1. Ta i bruk `descriptionId` i dialogene som migreres i Fase 3.
2. Ta tabellrammen med innvendig luft for fokusringen med i Fase 3.
3. Del den tidsfølsomme navigasjonstesten i én test per pakke.
4. Rett `npm audit` som egen sak før merge.
5. Installer Playwright-Firefox og legg til et WebKit-prosjekt for `a11y-foundation`, med Option+Tab, slik at Safari-motoren dekkes automatisk.
6. Gjennomfør sjekklisten i 15.8.7 før Fase 2 bygger videre på live-regionene.

#### 15.8.10 Vurdering

Fase 1 er teknisk klar for commit. Grunnlaget er bekreftet automatisk: fokus, skjemakobling, dialog, statusregioner, filvelger og CI. Tre svakheter er funnet og rettet, og ingen regresjoner er funnet. Fase 1 er **ikke** manuelt verifisert med skjermleser, ekte Safari/Firefox eller nettleserzoom, og gir ikke grunnlag for å hevde WCAG-etterlevelse. Det bør stå i commit-meldingen.

---

## 16. Fase 2 – gjennomføringsrapport (2026-10-14)

Branch `feature/wcag-accessibility`, utgangspunkt `4c594ebf` (Fase 1). Begrepene er de samme som i §15.8: **Auto** = automatisk test i repoet, **Agent** = kontrollert av agenten i ekte nettleser (Playwright, Chromium), **Manuell** = kontrollert av en person. Ingen manuell verifisering er gjort.

### 16.1 Omfang

Fase 2 omfatter de felles layoutene (`CustomerAppLayout`, `PublicLayout`, `Auth/Login`), felles navigasjon (header, modulmeny, sidenavigasjon, brødsmulesti, brukermeny, varselbjelle), sideskift med Inertia, flash- og toastmeldinger og standardvisningene Hjem, Bid Status (dashboard) og Styring. Store skjemaer, egne dialoger, AI-arbeidsflaten og Wiki er ikke rørt (Fase 3–5).

Funn som hørte til fasen: A11Y-012, -016 (Hjem, Dashboard, offentlige sider), -018 (header og `ModuleSidebar`), -020, -021, -022, -023, -051, -100, -101, -102, -103, -122 og -124.

### 16.2 Implementert

| Del | Hva | Filer | Funn | Status |
|---|---|---|---|---|
| A. Hopp-lenke og hovedinnhold | «Hopp til hovedinnhold» er første tabstopp i begge layoutene. Den er usynlig til den får tastaturfokus, og flytter fokus til `<main id="main-content" tabindex="-1">` i skript (URL-en i Inertia-historikken endres ikke). Virker også etter Inertia-navigasjon. Innloggingssiden har ingen gjentatt navigasjon og derfor ingen hopp-lenke. | `Components/App/SkipLink.jsx` (ny), begge layoutene | A11Y-100 | Auto |
| B. Landemerker | Ett `banner`, ett `main`, ett `contentinfo`. Alle `nav` har navn: «Moduler» (modulmenyen), «Områder i modulen» og «Visninger i området» (`NavigationRow`), «Brødsmulesti», og «Hovedmeny» på offentlige sider. Innlogging har fått `<main>`. | `CustomerAppLayout.jsx`, `PublicLayout.jsx`, `Auth/Login.jsx` | A11Y-101, -051 | Auto (axe + E2E) |
| C. Sideskift | Felles lytter på Inertias `navigate`-hendelse (`Support/routeFocus.js`, startet i `app.js`). Etter et reelt sideskift (ny sidekomponent, ny sti eller ny dokumenttittel) flyttes fokus til sidens h1, eller til `<main>` uten h1, uten å endre scrollposisjonen. Søk, filtre, faner og paginering med samme tittel flytter ikke fokus, og fokus blir heller ikke flyttet når siden selv har lagt det i `<main>` eller i en modal dialog. Første innlasting røres ikke. Fokusflyttingen er selve kunngjøringen – tittelen sies ikke i tillegg i en live region. `<html lang>` oppdateres fra delt `locale` ved hver navigasjon. h1 og `main` som fokusmål får ingen fokusramme (de er ikke kontroller). | `Support/routeFocus.js` (ny), `app.js`, `app.css` | A11Y-102, -124 | Auto (enhet + E2E: rail, header-pill med bare spørrestreng, filter, tilbake-knapp) |
| D. Sidetitler | `<title inertia>` → `<title data-inertia="">` i `app.blade.php`. Før dette hadde alle sider to `<title>`-elementer og nettleseren viste «Procynia» overalt. Forsiden får «Procynia» i stedet for «Procynia · Procynia». | `resources/views/app.blade.php`, `PublicLayout.jsx` | A11Y-129 (ny), -122 | Auto |
| E. Flash og toast | Felles `FlashMessages` for begge layoutene. Suksess er en toast som skrives inn i den vedvarende `polite`-regionen fra Fase 1 (leses én gang, avbryter ikke). Den forsvinner ikke lenger etter 3 s: den står til brukeren lukker den («Lukk melding», eller Escape når ingen dialog eller meny er åpen) eller neste side erstatter den. Lukkeknappen ligger i en egen, stille plass ved siden av live-regionen (`#procynia-toast-actions`), så navnet ikke leses opp med meldingen. Toasten ligger over headeren (z 80) og slipper klikk gjennom (bare knappen tar imot pekeren). Lukking med knappen gir fokus tilbake dit brukeren var. Feil og advarsel er bannere øverst i innholdet med `role="alert"`. Offentlige sider bruker samme komponent (før: banner uten rolle). | `Components/App/FlashMessages.jsx` (ny), `Support/announcer.js`, `app.css`, begge layoutene | A11Y-012, -020 | Auto |
| F2. Følgerettinger | `PageHelpPanel` starter aldri over vinduet; `MultiSelectFilterDropdown` gir fokus tilbake uten å rulle (16.7). | `PageHelpPanel.jsx`, `MultiSelectFilterDropdown.jsx` | — | Auto |
| F. Sticky header og fokus under header | Headeren er sticky bare fra `lg` (1024 px). Under det – telefon og 200–400 % zoom – ruller den bort med siden; før tok den opptil to tredjedeler av vinduet ved 320 px. Layouten måler headeren og setter `--app-header-offset`, som `scroll-padding-top` i `app.css` bruker, slik at elementer som rulles fram med Tab, hopp-lenke eller ankerlenke havner under headeren, ikke bak den. | `CustomerAppLayout.jsx`, `app.css` | A11Y-020, A11Y-132 (ny) | Auto |
| G. Brukermeny | Disclosure i stedet for `aria-haspopup="menu"` uten menytastatur: `aria-expanded`, `aria-controls`, navn «{navn}, brukermeny». Escape lukker og gir fokus tilbake til knappen. Menyen lukkes når tastaturfokus forlater den, så den aldri dekker elementet fokus flyttes til. | `CustomerAppLayout.jsx` | A11Y-103 | Auto |
| H. Varselbjelle | Navnet bærer antall uleste («Varsler, 3 uleste», i18n). Ikke lenger `aria-haspopup="dialog"`; panelet er en navngitt region («Varsler»). Kortene får navnet fra eget innhold (tittel, melding, alvorlighet, område og dato) i stedet for «Åpne varsel: …». Escape og fokus ut fungerer som i brukermenyen. Etter «Slett alle uleste» går fokus tilbake til bjella (`fallbackFocusRef`). Slett-ikonet har `slate-500`. | `NotificationBell.jsx`, `CustomerAppLayout.jsx` | A11Y-021, -018 | Auto |
| I. Paneler innenfor vinduet | Brukermenyen og varselpanelet henger fra høyre kant av knappen. Ved 320 px brytes headeren, og brukermenyen begynte 32 px utenfor venstre kant. `useKeepInViewport` flytter et åpent panel inn i vinduet (16 px marg), og gjør det igjen ved endret størrelse. | `Support/useKeepInViewport.js` (ny), `CustomerAppLayout.jsx`, `NotificationBell.jsx` | A11Y-131 (ny) | Auto |
| J. Mobilmeny på offentlige sider | Under `lg` vises «Meny» (disclosure, `aria-expanded`, `aria-controls`). Lenkene vises som en liste under headerraden, og rendres ikke når menyen er lukket, så Tab aldri havner i skjult navigasjon. Tab går fra «Meny» rett inn i menyen. Escape lukker og gir fokus til knappen. Aktiv side har `aria-current="page"`. Headerraden brytes i stedet for å skyve «Logg inn» ut av en telefon. Den falske språkvelgeren («NO ⌄», et element uten funksjon) er fjernet. | `PublicLayout.jsx` | A11Y-022, -122 | Auto |
| K. Innlogging | `<main>`. E-post og passord er koblet til egne feil (`aria-describedby`, `aria-invalid`) med `fieldA11y` fra Fase 1, og fokus flyttes til første ugyldige felt når feilene er rendret. Microsoft-feil er `role="alert"`. «eller» og feiltekster har kontrast (`slate-600`, `rose-700`). | `Auth/Login.jsx` | A11Y-051, -013 (for innlogging) | Auto |
| L. Dashboardkalender | Dagknappene har fullt navn (`aria-label`: «onsdag 14. oktober 2026, 2 frister, i dag»), `aria-pressed` for valgt dag og `aria-current="date"` for i dag. Lenkene ligger ikke lenger i en hover-boks inne i knappen: fristene for valgt dag vises som lenker i en liste under kalenderen. Ukedagsraden er `aria-hidden` (hver knapp sier ukedagen). «I dag» har tydeligere ring (`violet-500`) og valgt dag mørkere kant. | `DashboardCockpit.jsx`, `dashboardLogic.js` (`calendarDayLabel`), `lang/*` | A11Y-023 | Auto |
| M. Pipeline på Bid Status | Fasene legges ut etter kortets bredde (container queries): én, to eller tre per rad, og én stripe med piler bare når alle seks navnene får plass. Før ble navnene kuttet («FORHAN…» ved 1280 px), og siden rullet sidelengs ved 640–1024 px. | `DashboardCockpit.jsx` | A11Y-130 (ny) | Auto + Agent (skjermbilder 320/640/1280) |
| N. Kontrast | Hjem: nøkkeltallsetiketter og «Under arbeid» `slate-600`. Bid Status: tall i tomme signaler og faser `slate-500`, kalenderhint `slate-600`. `ModuleSidebar`: ikonknapper `slate-500`. Bunntekst i appen `slate-600`. Offentlige sider: tekst i `slate-300/400`, `blue-400` og `amber-600` er gjort mørkere, tekst under 12 px er hevet til 12 px, og lenker i løpende tekst er understreket (`link-in-text-block`). Ingen innhold er endret. | `Home/Index.jsx`, `DashboardCockpit.jsx`, `ModuleSidebar.jsx`, `CustomerAppLayout.jsx`, `Public/{Home,Faq,Security,Terms,Privacy}.jsx` | A11Y-016, -018 | Auto (axe) |
| i18n | Nye strenger i `procynia.a11y.*` (hopp-lenke, navigasjonsnavn, brukermeny, varsler, lukk melding, hovedmeny, meny) og `procynia.dashboard.calendar.*` (dagnavn, valgt dag). `hover_hint` og infoteksten for kalenderen sier nå «Velg en dag» i stedet for «Hold over». | `lang/{no,en}/procynia.php` | — | Auto (`A11yTranslationsTest`) |
| Lint | `lint:a11y` dekker nå også de 12 filene over (layoutene, `FlashMessages`, `SkipLink`, `NotificationBell`, `ModuleSidebar`, `Breadcrumbs`, `DashboardCockpit`, `Login`, Hjem, Styring, Dashboard). Ingen regel er slått av. | `eslint.config.js` | — | Auto |

### 16.3 Nye funn

| ID | Fil / komponent | Problem | WCAG | Status |
|---|---|---|---|---|
| A11Y-129 | `resources/views/app.blade.php` | `<title inertia>` (Inertia v1/v2) ved siden av v3s `<title data-inertia>`: to titler, og alle sider het «Procynia». | 2.4.2 | Rettet (auto) |
| A11Y-130 | `DashboardCockpit.jsx` (pipeline) | Seks faser på rad fra `sm`: navn kuttet på desktop og sidelengs rulling ved 640–1024 px. | 1.4.10, 1.4.4 | Rettet (auto + agent) |
| A11Y-131 | `CustomerAppLayout.jsx`, `NotificationBell.jsx` | Brukermeny og varselpanel kunne havne delvis utenfor vinduet ved 320 px. | 1.4.10 | Rettet (auto) |
| A11Y-132 | `CustomerAppLayout.jsx` | Sticky header på alle bredder tok mesteparten av vinduet ved 320 px / 400 % zoom. | 1.4.10, 2.4.11 | Rettet (auto) |
| A11Y-133 | `FlashMessages.jsx` (alle skjema med `preserveScroll`) | Feil- og advarselsbanneret ligger øverst i innholdet. Etter en innsending med `preserveScroll` lenger ned på siden er det utenfor synsfeltet for seende brukere (skjermleser får det via `role="alert"`). | 3.3.1 (seende), beste praksis | Åpent – Fase 3 (feilkobling i skjema, A11Y-013, gir feilen ved feltet) |
| A11Y-134 | Sider med `<Link>` som bare endrer spørrestrengen, uten `preserveState`, og med samme tittel | Siden monteres på nytt og fokus faller til `<body>`. Felles sideskift-logikk flytter bevisst ikke fokus ved samme tittel (for ikke å flytte fokus ved søk og filter). | 2.4.3 | Rettet i sluttkontrollen (§16.12) |

### 16.4 Tester og resultater

| Test | Resultat |
|---|---|
| `npm run test:unit` (Node 22, Docker) | 1666 bestått, 0 feilet. Ny: `resources/js/Support/a11yNavigation.test.js` (31 tester: sideskift-regel, fokusvalg, `lang`, landemerker, brukermeny, toast-vert, kalendernavn, paneler i vinduet, hjelpepanel, dropdown-fokus). |
| `npm run lint:a11y` (Node 22) | 0 feil, 0 advarsler (17 filer i a11y-listen). |
| `npm run build` | Bestått. |
| PHP, målrettet (`A11yTranslationsTest`, `SharedTranslationsLocaleTest`, `AiQuotaCustomerUxTest`, innlogging/Entra, Dashboard/Home/Governance) | 86 bestått, 0 feilet. |
| PHP, bredere (`--filter='Public\|Inertia\|Translation\|Navigation\|Notification\|Registration'`) | 409 bestått, 4 feilet. De 4 (`WikiQualityTabRemovalTest`, `WikiSecondaryNavigationOrderTest`) leser `CustomerAppLayout.jsx` etter strenger (`wiki-pages`, Wiki-menyblokken) som flyttet til `Support/appNavigation.js` før denne branchen; strengene finnes heller ikke i HEAD-versjonen av filen. Eksisterende, ikke forårsaket av Fase 2. |
| Pint på berørte PHP-filer | Bestått. |
| E2E `a11y-navigation.spec.js` (ny, 8 tester) + `a11y-foundation.spec.js` (3), siste kjøring | 11 bestått, 0 feilet. |
| Agent (Chromium) | axe på hele sidene `/`, `/funksjoner`, `/priser`, `/sikkerhet`, `/kontakt`, `/faq`, `/betingelser`, `/personvern`, `/registrer`, `/login`, `/app/dashboard`, `/app/bid-status`, `/app/governance`: 0 brudd (før rettingene: kontrast og `link-in-text-block` på fem offentlige sider, kontrast i appens bunntekst). Skjermbilder av pipeline ved 320/640/1280 px. Sidebredde målt ved 320–1440 px. |
| E2E-regresjon, 23 spesifikasjoner (auth, app-navigation, breadcrumbs, dashboard-structure, info-hint, my-tasks-governance, my-tasks-suppliers, billing, info-center-help, wiki-navigation, compliance-library, compliance-library-frameworks, quality-page-help, live-filter-collapse, package-change, permission-sections, ai-capacity, supplier-import, quality-process-row-actions, knowledge-handoff, wiki-graph-filters, objective-kpi-target-types, wiki-tab-preservation) | Første kjøring: 138 bestått, 12 feilet. Alle 12 er undersøkt: 11 skyldtes tre regresjoner fra Fase 2 som er rettet (16.7), og 1 (`objective-kpi-target-types`, tidsavbrudd mens en navigasjon pågikk) besto ved ny kjøring uten endring og vurderes som ustabil. Etter rettingene: de 7 berørte spesifikasjonene på nytt 90 bestått / 7 feilet (de 7 var `quality-page-help` og `wiki-graph-filters`, kjørt før de to siste rettingene), deretter `quality-page-help` + `wiki-graph-filters` 54/0 og `wiki-graph-filters` alene 49/0. |
| Full PHP- og E2E-suite | Ikke kjørt (merge gate, jf. prosjektregel). |

### 16.5 Funnstatus etter Fase 2

Lukket betyr: utbedret og verifisert automatisk innenfor testomfanget i 16.4. Ingen av dem er manuelt verifisert med skjermleser.

| ID | Status |
|---|---|
| A11Y-012 | Lukket (auto). Toast uten tidsavbrudd, lukkeknapp med navn, Escape, leses én gang fra vedvarende region; feil og advarsel `role="alert"`; offentlige sider bruker samme komponent. Gjenstår manuelt: at NVDA/JAWS/VoiceOver faktisk leser toasten etter redirect. |
| A11Y-020 | Delvis. Lukket for toast (over headeren) og fokus under headeren (`scroll-padding-top`, sticky bare fra `lg`). Gjenstår: `inert` på bakgrunnen mens en modal er åpen. `ActionDialog` dekker headeren og holder fokus (Fase 1), men skjermleserens lesemodus kan fortsatt nå bakgrunnen der `aria-modal` ikke respekteres (Fase 3, sammen med dialogmigreringen). |
| A11Y-016 | Delvis. Lukket for Hjem, Bid Status, Styring, layoutene og de offentlige sidene (axe uten brudd). Øvrige moduler: åpent (Fase 3–6). |
| A11Y-018 | Delvis. Lukket for `ModuleSidebar`, brukerknappens pil og bjellas slett-ikon. Prosessdiagram og KPI-graf: Fase 6. |
| A11Y-021 | Lukket (auto). Gjenstår A11Y-105 for bjellas synlige tekster (hardkodet norsk). |
| A11Y-022 | Lukket (auto, 320 px). |
| A11Y-023 | Lukket (auto). Kalenderen er fortsatt 42 tabstopp (ett per dag); et rutenett med piltaster er en mulig forbedring, ikke et AA-krav. |
| A11Y-051 | Lukket (auto). |
| A11Y-100, -101, -102, -103, -124 | Lukket (auto). |
| A11Y-122 | Lukket (auto): falsk språkvelger fjernet, forsidetittel rettet. |
| A11Y-013 | Lukket for innlogging. Øvrige skjema: Fase 3–6. |
| A11Y-129, -130, -131, -132 | Nye, lukket (auto). |
| A11Y-133 | Ny, åpen (se 16.3 og 16.12). |
| A11Y-134 | Ny, rettet i sluttkontrollen (16.12). |
| A11Y-105 | Delvis: alle nye tilgjengelige navn er oversatt. Bjellas og slettedialogens synlige tekster er fortsatt hardkodet norsk. |
| Øvrige | Uendret. |

### 16.6 Synlige endringer i grensesnittet

- **Toasten forsvinner ikke etter 3 sekunder.** Den har en ×-knapp og lukkes med Escape, eller når brukeren går til en annen side. Den legger seg over headeren, men klikk går gjennom den.
- **Headeren er ikke lenger klebrig under 1024 px.** På telefon og nettbrett ruller den bort med siden.
- **Offentlige sider:** «Meny»-knapp under 1024 px; språkpillen «NO ⌄» er borte; lenker i løpende tekst er understreket; noen grå tekster er mørkere; sidepadding på telefon er 16 px.
- **Bid Status:** fasene vises i et rutenett (1–3 kolonner) i stedet for en kuttet stripe; fristene for en dag vises i en liste under kalenderen når dagen velges, i stedet for i en boks ved hover. Hintet sier «Velg en dag for å se fristene».
- **Fanetittelen i nettleseren** viser nå sidens navn («Risiko · Procynia»).
- **Hopp-lenken** synes bare når den får tastaturfokus.
- Små kontrastjusteringer i Hjem, sidemenyen og bunnteksten.

Ingen ruter, handlinger, tilganger eller data er endret.

### 16.7 Regresjoner og eksisterende feil

- **Funnet og rettet under fasen** (alle fanget av E2E-regresjonen, alle med vakt-test):
  1. Skjermlesertillegget «, brukermeny» i brukerknappen var absolutt posisjonert utenfor navnets klippeboks. Med langt brukernavn ble siden bredere enn vinduet (seks spesifikasjoner med `sidewaysOverflow` feilet). Rettet med `relative` på navnet.
  2. `PageHelpPanel` legger panelet under headerens nedre kant. Når headeren ikke lenger er sticky under `lg` og siden er rullet, ble kanten negativ og lukkeknappen havnet over vinduet (fire tester i `quality-page-help`). Rettet med `Math.max(0, …)`; ny E2E-kontroll åpner hjelpen etter rulling på 320 px.
  3. `MultiSelectFilterDropdown` gir fokus tilbake til utløseren når et klikk utenfor lukker panelet. Med `scroll-padding-top` rullet `focus()` siden mellom `mousedown` og `mouseup`, og klikket (f.eks. «Nullstill filtre» i Wiki-grafen) bommet (to tester i `wiki-graph-filters`). Rettet med `focus({ preventScroll: true })`. Samme mønster kan finnes andre steder (`focus()` uten `preventScroll` midt i en pekerhandling); se 16.9.
- **Testendringer som følger av bevisste endringer** (ingen skjuler en regresjon):
  - `my-tasks-governance.spec.js`, `my-tasks-suppliers.spec.js`: bjella heter nå «Varsler, N uleste» og kortene navngis av innholdet (A11Y-021).
  - `a11yFoundation.test.js`: flash-markupen er flyttet til `FlashMessages`; testmocken fikk `appendChild` for toast-verten.
  - `headerNavigation.test.js`, `notificationRefresh.test.js`: `aria-label`/`id` lagt til i markup som testene låser.
  - `cockpitLayout.test.js`: `slate-400` → `slate-500` (A11Y-016) og pipelinens nye container-oppsett (A11Y-130).
- **Eksisterende feil, ikke fra Fase 2:** de 4 PHP-testene for Wiki-navigasjon (16.4).

### 16.8 Manuell testing som gjenstår

I tillegg til sjekklisten for Fase 1 (§15.8.7):

1. **NVDA + Firefox/Chrome og VoiceOver + Safari:**
   1. Hopp-lenken: Tab fra adressefeltet, Enter, og neste Tab skal være i sideinnholdet.
   2. Bytt side fra modulmenyen: skjermleseren skal lese sidens overskrift. Søk i et register: ingenting skal leses opp utover søkeresultatet, og fokus skal bli i søkefeltet.
   3. Lagre noe som gir suksessmelding: meldingen leses én gang, uten «Lukk melding».
   4. Brukermenyen og bjella: navn, tilstand (utvidet/skjult), Escape tilbake til knappen. Bjella skal si antall uleste.
   5. Landemerkeliste: banner, navigasjon (navngitt), hovedinnhold, innholdsinformasjon.
   6. Bid Status-kalenderen: dagnavn og «trykket» ved valg; listen under kalenderen.
2. **Ekte Safari og Firefox:** hopp-lenken, fokus ved sideskift og at fokusrammen ikke vises på h1.
3. **Nettleserzoom 200 % og 400 %** (ikke bare smal viewport): header, mobilmeny, brukermeny, bjelle, hjelpepanel og toast.
4. **Windows-høykontrast:** toastens kant og lukkeknapp, hopp-lenken, aktiv side i menyene.
5. **iOS VoiceOver / Android TalkBack:** offentlig mobilmeny og app-headeren.

### 16.9 Risiko og begrensninger

- Fokusflytting ved sideskift bygger på en regel (komponent, sti, tittel). En side som bytter visning via spørrestreng **og** beholder tittelen, regnes som oppdatering: fokus flyttes ikke, men gjenopprettes til samme kontroll hvis det falt til `<body>` (A11Y-134, 16.12). Finnes ingen tvilling (kontrollen er borte etter oppdateringen), blir fokus stående på `<body>`. En side som endrer tittelen ved filtrering ville fått fokus flyttet; ingen slik side er funnet.
- Toasten blir stående til den lukkes. På telefon kan den dekke deler av øverste del av siden. Klikk går gjennom den, Escape lukker den, og neste side fjerner den.
- `scroll-padding-top` gjør at `focus()` på et element nær toppen ruller siden. Kode som flytter fokus midt i en pekerhandling (lukking ved klikk utenfor) bør bruke `preventScroll`; ett tilfelle er rettet (16.7), andre kan finnes i moduler som ikke er dekket av regresjonsutvalget.
- `useKeepInViewport` flytter panelene med `transform` etter måling. Ved svært rask endring av vindusstørrelse kan panelet stå et øyeblikk før det flyttes.
- Fokusfellen i dialoger og skjermleserens lesemodus: se A11Y-020.
- E2E kjører bare i Chromium. WebKit og Firefox er ikke kjørt for Fase 2.
- Fortsatt dokumentert fra Fase 1: ingen manuell skjermlesertest; ekte Safari og Firefox ikke testet; Windows-høykontrast ikke testet; nettleserzoom ikke testet; klippet fokusring i tabeller (Fase 3); `InfoHint` med `<div role="tooltip">` inne i `<span>` (senere fase); de 5 eksisterende npm-sårbarhetene skal håndteres separat før merge.

### 16.10 Anbefalinger for Fase 3

1. Gjennomfør den manuelle sjekklisten i 16.8 og §15.8.7 før Fase 3 bygger på sideskift og toast.
2. Migrer dialogene til `ActionDialog` og legg til `inert` på bakgrunnen (A11Y-002, -020).
3. Skjema: `FormField` + `focusFirstInvalidField` gjør A11Y-133 mindre viktig, fordi feilen da står ved feltet og fokus flyttes dit.
4. Ta tabellrammen for fokusring (§15.8.2) og A11Y-108–110.
5. Vurder per side om visninger via spørrestreng bør ha egen tittel, slik at de regnes som sideskift (A11Y-134 dekker fokus, ikke kunngjøring av ny visning).
6. Oversett bjellas synlige tekster (A11Y-105).
7. Legg til et WebKit-prosjekt for `a11y-foundation` og `a11y-navigation` med Option+Tab.

### 16.11 Vurdering

Fase 2 er teknisk klar for commit. Alle funn som tilhørte fasen er rettet, eller dokumentert som delvis med begrunnelse (A11Y-016, -018, -020, -105). Fire nye feil fra kartleggingen er rettet (A11Y-129–132), og to er registrert som åpne (A11Y-133, -134). Tre regresjoner som Fase 2 selv innførte, ble fanget av regresjonsutvalget og rettet med vakt-tester. Ingen ruter, tilganger, data eller forretningslogikk er endret. Fasen er **ikke** manuelt verifisert med skjermleser, ekte Safari/Firefox, nettleserzoom eller Windows-høykontrast, og gir ikke grunnlag for å hevde at Procynia oppfyller WCAG 2.2 AA. Full E2E- og PHP-suite er merge gate og er ikke kjørt.

### 16.12 Teknisk sluttkontroll av Fase 2 (2026-10-14)

Kontroll på den endelige Fase 2-koden før commit. Alle resultater under er fra kjøringer **etter** siste kodeendring; ingen tidligere resultat er gjenbrukt.

#### 16.12.1 Rettelser i sluttkontrollen

| # | Funn | Rettelse | Filer | Test |
|---|---|---|---|---|
| 1 | A11Y-134: en oppdatering av samme side uten `preserveState` (f.eks. Wiki-fanene `/app/wiki?tab=…` i headeren) monterte siden på nytt, og fokus falt til `<body>`. | `routeFocus.js` beskriver kontrollen som har fokus når et besøk starter (`start`: id, eller tag + `href`/`name`/`type`/`data-testid`/`aria-label` + tekst). Når besøket er ferdig (`finish`), uten sideskift, **og bare hvis fokus er på `<body>`**, får den gjengitte tvillingen fokus (`preventScroll`). Finnes ingen tvilling, røres ingenting. Fokus som fortsatt står på en kontroll, flyttes aldri. | `Support/routeFocus.js` | Auto: 4 enhetstester + E2E (Wiki-fanen «Kjøringer» har fokus etter besøket, tittelen er uendret) |
| 2 | Den første navigasjonen etter en full sidelasting ble tolket som sideskift: tittelen ble lest ved oppstart, før `<Head>` hadde satt den, og var fortsatt «Procynia». Fokus gikk da til h1 også ved bytte mellom faner på samme side. | Forrige sides tittel leses når et besøk starter, da er siden rendret. | `Support/routeFocus.js` | Auto (E2E over, som feilet før rettingen) |

#### 16.12.2 Gjennomgang av fokus og rulling

- **`routeFocus.js`.** Sideskift (komponent, sti eller tittel) → h1 eller `main`, med `preventScroll`, bare når siden ikke selv har lagt fokus i `main` eller i en modal dialog. Samme side → fokus står; bare tapt fokus gjenopprettes (rettelse 1). Historikk (tilbake/frem) går via `navigate` uten `start`/`finish` og behandles som sideskift. Polling (`router.reload`) har fokus på en kontroll eller i `<body>` ved start: i begge tilfeller flyttes ingenting.
- **`useKeepInViewport.js`.** Nullstiller `transform`, måler og flytter bare horisontalt, også ved endret vindusstørrelse. Gjelder brukermeny og varselpanel. Ingen påvirkning på bredere skjermer der panelet passer.
- **`scroll-padding-top` og `focus()` midt i et klikk.** Alle `focus()`-kall i komponenter med `mousedown`/`pointerdown`-håndterere er gjennomgått: `ActionDialog`, `hintTooltip`, `QualityItemActions`, `Wiki/Show` (menyen) og layoutens menyer gir fokus bare ved Escape eller tastatur. `UnitInput` fokuserer på `click` (etter `mouseup`). `SearchableMultiSelect` og `CpvSelector` fokuserer på `click`. Den eneste som fokuserte under `mousedown`, `MultiSelectFilterDropdown`, ble rettet i 16.7.
- **Dialoger.** `ActionDialog` (z 70 over headeren 60, fokusfelle, Escape, retur, `fallbackFocusRef`) er uendret fra Fase 1; bjellas slettedialog bruker nå `fallbackFocusRef`. `PageHelpPanel` starter aldri over vinduet (16.7).

#### 16.12.3 Toast og flash

Verifisert i E2E på endelig kode: suksessmeldingen står i den vedvarende `polite`-regionen og finnes én gang i DOM; lukkeknappen («Lukk melding») ligger utenfor live-regionen; toasten er der etter 4 s; vertens `z-index` er 80 og `pointer-events: none`, og knappen er det øverste elementet på sitt punkt; Escape lukker uten å flytte fokus; knappen med tastatur gir fokus tilbake dit brukeren var. Feil og advarsel er `role="alert"`. Ikke verifisert: at skjermlesere faktisk leser toasten (manuelt).

#### 16.12.4 A11Y-133 og A11Y-134

- **A11Y-134: rettet** (16.12.1).
- **A11Y-133: fortsatt åpent, bevisst.** Banneret ligger øverst i innholdet. Å rulle det inn i synsfeltet automatisk ville flyttet visningen bort fra skjemaet brukeren arbeider i, og ville vært en atferdsendring på tvers av alle moduler. Skjermlesere får feilen via `role="alert"`. Riktig løsning er feil ved feltet og fokus dit (`FormField` + `focusFirstInvalidField`, A11Y-013), som er Fase 3. Innloggingssiden har allerede dette.

#### 16.12.5 Testresultater (endelig kode)

| Test | Resultat |
|---|---|
| `npm run test:unit` (Node 22, Docker) | 1670 bestått, 0 feilet, 0 hoppet over |
| `npm run lint:a11y` (Node 22) | 0 feil, 0 advarsler |
| `npm run build` | Bestått |
| PHP, målrettet (`A11yTranslationsTest`, `SharedTranslationsLocaleTest`, `AiQuotaCustomerUxTest`, innlogging/Entra, Dashboard/Home/Governance, offentlige sider, tilgang) | 86 bestått, 0 feilet |
| PHP, bredere (`--filter='Public\|Inertia\|Translation\|Navigation\|Notification\|Registration'`) | 409 bestått, 4 feilet |
| Baseline for de 4 PHP-feilene, `4c594ebf` i eget git-worktree (egen `vendor`-kopi, bekreftet med reflection at klassene lastes fra baseline) | De samme 4 feiler: `WikiQualityTabRemovalTest::the_wiki_navigation_no_longer_offers_a_quality_tab` og `WikiSecondaryNavigationOrderTest` (3 tester). De leser `CustomerAppLayout.jsx` etter Wiki-menyen, som ligger i `Support/appNavigation.js`. **Eksisterende og uavhengige av Fase 2.** (Baseline-worktreet ga i tillegg 8 HTTP-feil fordi det mangler bygget Vite-manifest; de testene består i hovedtreet.) |
| E2E Chromium, samlet: de 23 regresjonsspesifikasjonene i 16.4 + `a11y-foundation` + `a11y-navigation` | **161 bestått, 0 feilet, 0 hoppet over** (12,9 min) |
| Full PHP- og E2E-suite | Ikke kjørt (merge gate) |

#### 16.12.6 Åpne funn og manuell verifisering

- Åpne fra Fase 2: A11Y-133 (Fase 3), A11Y-020 (`inert`, Fase 3), A11Y-016/-018 utenfor Fase 2-sidene, A11Y-105 (bjellas synlige tekster).
- Manuell verifisering etter 16.8 og §15.8.7 gjenstår i sin helhet: skjermleser (NVDA, VoiceOver, JAWS), ekte Safari og Firefox, nettleserzoom 200/400 %, Windows-høykontrast, iOS/Android.
- Fra Fase 1, fortsatt dokumentert: klippet fokusring i tabeller (Fase 3), `InfoHint`-strukturen, de 5 npm-sårbarhetene som håndteres separat før merge.

#### 16.12.7 Vurdering

Ingen uavklarte regresjoner. Den eneste testfeilen (4 PHP-tester) finnes på `4c594ebf` og er uavhengig av Fase 2. Fase 2 er teknisk klar for commit. Den er ikke manuelt verifisert og gir ikke grunnlag for å hevde at Procynia oppfyller WCAG 2.2 AA.

---

## 17. Fase 3 – gjennomføringsrapport (2026-10-15)

Branch `feature/wcag-accessibility`, utgangspunkt `683246a7` (Fase 2), rent arbeidstre ved start. Begrepene er de samme som i §15.8 og §16: **Kode** = implementert, **Auto** = automatisk test i repoet, **Agent** = kontrollert av agenten i ekte nettleser (Playwright, Chromium), **Manuell** = kontrollert av en person. Ingen manuell verifisering er gjort. Fase 3 er ikke committet.

### 17.1 Omfang og arbeidsmåte

Fase 3 omfatter felleskomponentene for skjema, dialog, tabell og status, og de sidene som ikke er modultunge: Brukere, Avdelinger, Kundemiljø (Avdelinger, Brukere, Tilganger, egne roller, fagområder), Abonnement, Go/No-go-maler, Watch Profiles, Konkurrenter og Oppfølging. AI-arbeidsflaten (Fase 4), Enterprise Wiki (Fase 5) og GRC-modulene (Fase 6) er ikke migrert. Eneste unntak er tabellrammen i Kvalitet (§17.2 F), fordi det var der den klippede fokusringen ble målt i Fase 1.

Funn som hørte til fasen: A11Y-001, -002 (GoNoGo, Kundemiljø, Billing, `AiCapacityLevelDialog`), -013, -016 og -017 (på de berørte sidene), -019 (vurdert), -020 (`inert`), -025, -027, -028, -029, -041, -042, -043, -106 (delvis), -108, -109, -110, -111, -120, -121 (de migrerte skjemaene), -133 og den klippede fokusringen fra §15.8.2.

Før kodeendringene ble det laget en intern oversikt per funn og per side. Hvert skjema og hver dialog er gjennomgått enkeltvis; ingenting er byttet ut mekanisk på tvers av kodebasen.

### 17.2 Implementert

| Del | Hva | Filer | Funn | Status |
|---|---|---|---|---|
| A. Inert bakgrunn og modalstabel | Når en `ActionDialog` åpnes, får alle søsken langs stien fra dialogen opp til `<body>` attributtet `inert`; dialogen selv og elementene som inneholder den forblir aktive. Dermed legges ikke `inert` på app-roten, som dialogen ligger inni. Live-regionene og toast-verten holdes utenfor, slik at statusmeldinger fortsatt leses mens en dialog er åpen. Elementer som var `inert` fra før, røres ikke. Ved lukking fjernes `inert` **før** fokus returneres (et inert element kan ikke få fokus). Nestede dialoger: en dialog som åpnes inne i et område en ytre dialog har gjort inert, frigjør det området så lenge den er åpen; bare den øverste dialogen svarer på Escape og Tab. Ikke-modale paneler (`PageHelpPanel`, menyer) er uendret. | `Support/modalInert.js` (ny), `Components/App/ActionDialog.jsx` | A11Y-020 | Kode + auto (enhet + E2E) |
| B. Dialoger migrert | `CreateTemplateModal` og `CriterionModal` (Go/No-go-maler), `EnvironmentModal` (Kundemiljø: avdeling, rolle, fagområde), `ConfirmDialog` ×3 (Abonnement: si opp, gjenoppta, bestill/avbestill) og `AiCapacityLevelDialog` er nå `ActionDialog`. De har dialogrolle, navn fra overskriften, beskrivelse der den finnes, fokus inn ved åpning, fokusfelle, Escape, fokusretur og inert bakgrunn. «×» har fått navnet «Lukk dialog» (`DialogTitleBar`, ny eksport i `ActionDialog`). Nye størrelser `xs`/`sm` gjør at dialogene beholder bredden de hadde. Native `window.confirm` er beholdt (beslutning 3). | `Pages/App/GoNoGo/Templates/{Index,Edit}.jsx`, `Pages/App/CustomerEnvironment/Index.jsx`, `Pages/App/Billing/Index.jsx`, `Components/App/AiCapacityLevelDialog.jsx`, `Components/App/ActionDialog.jsx` | A11Y-002 | Kode + auto |
| C. Skjemafelt | `FormField` har fått klasseoverstyring, slik at eldre sider kan ta i bruk koblingen uten å endre utseende, og to nye eksporter: `FieldsetField` (avkrysningsgruppe som `<fieldset>` navngitt med `aria-labelledby`, slik at en «i» ved spørsmålet ikke blir en del av gruppens navn) og `RequiredFieldsNote`. Tatt i bruk i: Brukere (opprett, rediger), Avdelinger (opprett, rediger), avdelingsdialogen i Kundemiljø, Watch Profile (opprett, rediger), Go/No-go-maler (ny mal, malinformasjon, vurderingspunkt). Rolle- og fagområdedialogen bruker `fieldA11y` direkte (feltene hadde allerede `htmlFor`). `DepartmentCheckboxGroup` er en navngitt gruppe. Rettighetene i rolledialogen er gruppert per modul med `fieldset`/`legend`. | `Components/App/FormField.jsx`, `Components/App/DepartmentCheckboxGroup.jsx`, sidene over | A11Y-001, -013, -041, -120, -121 | Kode + auto |
| D. Fokus ved avvist innsending | `useFocusFirstError(containerRef, { errorsOnMount })`. Fokus flyttes til første felt med `aria-invalid`, og nettleseren ruller det inn i synsfeltet (med `scroll-padding-top` fra Fase 2). Er feilen ikke knyttet til et felt, får feiloppsummeringen (`data-form-error-summary`) fokus. Fokus flyttes bare når det bes om det: fra Inertia `onError`, eller ved innlasting når siden kommer tilbake med feil fra et vanlig POST-skjema (Brukere). Feil som dukker opp eller endres mens brukeren skriver, flytter aldri fokus. Forespørselen er en teller i state, fordi `onError` kalles før React har rendret `aria-invalid`. | `Support/focusManagement.js` | A11Y-013, -133 | Kode + auto |
| E. `InfoHint` | Panelet er en `<span>` (gyldig inne i `label`, `h2`, `p`), ikke `<div>` i `<span>`. En usynlig `::before`-stripe dekker glippen på 8 px mellom «i» og panel, så panelet ikke lukkes på vei dit med mus. Et trykk som åpner hinten, lukker den ikke i samme bevegelse (berøring gir `mouseenter` + `click`; før åpnet og lukket ett trykk hinten). Den gamle `violet-300`-ringen (under 3:1, og dobbel med app-rammen) er fjernet; app-rammen fra Fase 1 gjelder. Alle `InfoHint` i Brukere og Watch Profile står nå utenfor `<label>`. | `Components/App/hintTooltip.jsx`, `InfoHint.jsx`, Brukere, `WatchProfileForm.jsx` | A11Y-001, -025, -106 (delvis) | Kode + auto |
| F. Tabellramme | `ScrollRegion`: rulleboksen har 4 px luft innenfor hver kant (og tilsvarende negativ marg, så tabellen står der den sto), slik at fokusrammen på en lenke i første kolonne eller en knapp i siste ikke klippes. Bare når tabellen faktisk er bredere enn boksen, blir boksen en navngitt region i tabrekkefølgen (piltaster ruller); en tabell som passer, gir ingen ekstra tabstopp eller landemerke. Fokusrammen på selve boksen tegnes innvendig, fordi kortet rundt ofte klipper. Ingen global CSS-endring for tabeller. Tatt i bruk i Kundemiljø (avdelinger, brukere, tilgangsmatriser, egne roller, fagområder), Brukere, Avdelinger, Watch Profiles, Konkurrenter, Abonnement (fakturaer, tjenester) og de tre tabellene i Kvalitet der klippingen ble målt. | `Components/App/ScrollRegion.jsx` (ny), `resources/css/app.css`, sidene over | §15.8.2, A11Y-110 | Kode + auto + agent |
| G. Tabellsemantikk | `<th scope="col">`, `<th scope="row">` for radens navn, `sr-only`-caption, tekst i tom handlingskolonne. Radhandlinger har radens navn («Rediger Kari Nordmann», «PDF for faktura 1042 (åpnes i ny fane)», «Vis Leverandør AS»), med synlig tekst først (2.5.3). Konkurrenter: `aria-sort` på sortert kolonne, knappenavn «Sorter etter …», pilen `aria-hidden`; sorteringsknappene er en render-funksjon og ikke en komponent laget per render, så fokus blir på knappen etter sortering. | Sidene over | A11Y-043, -108, -109 | Kode + auto |
| H. Faner og disclosure | Kundemiljøs Avdelinger/Brukere/Tilganger er en navngitt `<nav>` med `aria-current="page"` (navigasjon, ikke ARIA-faner). Den ubrukte `CollapsibleSection` (uten `aria-expanded`) er fjernet; modulseksjonene i Tilganger brukte allerede `PermissionSection` med `aria-expanded`. | `CustomerEnvironmentHeader.jsx`, `CustomerEnvironment/Index.jsx` | A11Y-027, -028 | Kode + auto |
| I. Tilgangsmatrisen | Avkrysningsboksen er `aria-disabled` mens raden lagres, ikke `disabled`, så fokus ikke faller til `<body>`; en endring mens raden lagres ignoreres. «Lagret» kunngjøres i den vedvarende `polite`-regionen, feil i `assertive`. Gjelder både anbudsrollematrisen og domenematrisene for egne roller. | `CustomerEnvironment/Index.jsx`, `CustomerRolesPanel.jsx` | A11Y-029 | Kode + auto |
| J. Statusmeldinger | Ingen nye live-regioner; alt går via `announce()` fra Fase 1. Antall treff kunngjøres når brukerfiltrene i Kundemiljø har satt seg (600 ms) og etter et søk i Konkurrenter, ikke ved sortering eller paginering. Lagring, sletting og bekreftelse bruker fortsatt flash/toast fra Fase 2 (leses én gang). Feil i skjema høres når fokus flyttes til feltet (feilen er feltets beskrivelse), uten egen kunngjøring – det ville gitt to opplesninger. | `CustomerEnvironment/Index.jsx`, `Suppliers/Index.jsx` | A11Y-111 | Kode + auto |
| K. Kontrast | Hjelpetekster `slate-400` → `slate-600` (Brukere, Avdelinger), etiketter i Oppfølging `slate-400` → `slate-600`, Konkurrenter `text-[11px] slate-500` → `text-xs slate-600`, tabellhoder `slate-500` → `slate-600` på `slate-50`, feiltekst `rose-600` → `rose-700` i de migrerte skjemaene, merker under 12 px → 12 px. Go/No-go: `opacity-60` på inaktive maler og punkter er erstattet med lys bakgrunn; «Inaktiv»-merket og overskriften «Deaktiverte punkter» sier det. Deaktiverte kontroller er ikke endret (unntatt i 1.4.3). | Sidene over | A11Y-016, -017 | Kode + beregnet |
| L. Overskrifter | Go/No-go-maler: én h1 (`showPageTitle={false}` på listen; «Malinformasjon» er h2 under layoutens «Rediger: …»). | `GoNoGo/Templates/{Index,Edit}.jsx` | A11Y-042 | Kode + auto |
| i18n | `procynia.a11y.*`: `close_dialog`, `required_fields`, `form_has_errors`, `saved`, `save_failed`, `results_count`, `results_none`, `environment_tabs`, `sort_by`, `sorted_ascending`, `sorted_descending`, `row_action`, `invoice_pdf`, `inactive`, `templates_list`, `table_*`. `customer_env.roles.business_areas.col_actions`. no + en. | `lang/{no,en}/procynia.php` | — | Auto (`A11yTranslationsTest`) |
| Lint | `lint:a11y` dekker 28 nye filer (45 totalt). Ingen regel er slått av. To reelle funn i rolledialogen (etikett med tittel og hint som ett navn) er rettet: tittelen navngir boksen, hinten beskriver den. | `eslint.config.js` | — | Auto |

### 17.3 Kontrast målt

Tailwind 4 bruker OKLCH. Verdiene under er regnet fra `node_modules/tailwindcss/theme.css` (sRGB, WCAG-formelen):

| Farge | På hvitt | På `slate-50` | Vurdering |
|---|---|---|---|
| `slate-200` (inputkant) | 1,23:1 | 1,18:1 | Under 3:1 (A11Y-019, se 17.6) |
| `slate-400` | 2,63:1 | 2,51:1 | Under 3:1 også for ikke-tekst (A11Y-136) |
| `slate-500` | 4,77:1 | 4,55:1 | Tekst OK, så vidt på `slate-50` |
| `slate-600` | 7,56:1 | 7,23:1 | OK |
| `rose-600` | 4,51:1 | 4,31:1 | For knapt; erstattet i migrerte skjema |
| `rose-700` | 6,06:1 | 5,78:1 | OK |
| `violet-600` (fokus) | 5,88:1 | 5,62:1 | OK |

### 17.4 A11Y-133 – feil utenfor synsfeltet

- **Rettet for feltfeil i de migrerte skjemaene.** Feilen står ved feltet, er koblet med `aria-describedby`, feltet er `aria-invalid`, og fokus flyttes dit (med rulling inn i synsfeltet) etter en avvist innsending, både med mus og tastatur. Auto: E2E for Watch Profile (knappen nederst, feltet øverst utenfor synsfeltet), Brukere (vanlig POST), avdelingsdialogen.
- **Ikke rettet: flash-feil etter handlinger på stedet** (radhandlinger med `preserveScroll`). De er ikke knyttet til et felt. Banneret står øverst med `role="alert"`, så skjermlesere får det, men seende brukere kan ikke se det. En global løsning som ruller til banneret ble vurdert og forkastet (atferdsendring i alle moduler, flytter visningen bort fra der brukeren arbeider). Registrert som A11Y-137, beslutning i Fase 7.

### 17.5 Tester og resultater

Resultatene er fra kjøringer på endelig kode.

| Test | Resultat |
|---|---|
| `npm run test:unit` (Node 22, Docker) | 1701 bestått, 0 feilet, 0 hoppet over. Ny: `resources/js/Support/a11yForms.test.js` (31 tester: inert-gangen med falsk DOM inkl. nesting og live-regioner, modalstabel, `hasErrors`, fokus til første feil, at fokus bare flyttes på forespørsel, `InfoHint` utenfor `<label>` i de migrerte skjemaene, panelet som `<span>`, trykk-regelen, dialogene på `ActionDialog`, grupper, faner, matrise, tabellramme, `aria-sort`, radnavn). Endret: `Billing/pageLayout.test.js` (dialogens bredde er nå `size="xs"`, samme `max-w-md`). |
| `npm run lint:a11y` (Node 22) | 0 feil, 0 advarsler (45 filer). |
| `npm run build` | Bestått. |
| PHP, målrettet (`A11yTranslationsTest`, `SharedTranslationsLocaleTest`, Billing ×3, `BusinessAreaDeletionTest`, `CustomerDepartmentManagementTest`, `CustomerEnvironmentPageTest`, `CustomerPermissionClearingTest`, `CustomerRolePermissionTest`, `CustomerUserManagementTest`, `CustomerWatchProfileManagementTest`, `GoNoGoTemplateManagementTest`, `InfoCenterModuleMatrixTest`, `CommercialOwnerRoleTest`, leverandørhøsting) | 202 bestått, 0 feilet (1587 påstander). I tillegg `GoNoGoAssessmentTest`, `QualityPermissionTest` og to Wiki-tester som leser JSX: 48 bestått, 0 feilet. |
| Pint på berørte PHP-filer (språkfilene) | Bestått. |
| E2E `a11y-forms.spec.js` (ny, 9 tester) | 9 bestått, 0 feilet: avvist innsending med mus og tastatur (fokus, `aria-invalid`, beskrivelse, synlig, skriving flytter ikke fokus), vanlig POST-skjema (Brukere), avdelingsdialogen (navn, beskrivelse, fokus inn, bakgrunn inert, programmatisk fokus til headeren hindres, Tab/Shift+Tab, feil ved feltet, Escape, fokusretur, bakgrunn aktiv igjen), Go/No-go-malene (én h1, dialognavn, «Lukk dialog», fokusretur), tilgangsmatrisen (fokus beholdt, «Lagret» i `polite`-regionen, tilbakestilt), tabell på telefon (navngitt region, tabstopp, innvendig ramme, piltast ruller, ingen sidelengs rulling av siden), fokusramme i Kvalitet-tabellen (≥ 4 px luft på begge sider), Konkurrenter (etiketter, `aria-sort`, fokus etter sortering), `InfoHint` (pekerbro, `<span>`, ett trykk åpner). axe uten brudd på skjema og dialoger med og uten feil, og på tabellregionen. |
| E2E-regresjon, 25 spesifikasjoner (`a11y-forms`, `a11y-foundation`, `a11y-navigation`, `billing`, `package-change`, `ai-capacity`, `permission-sections`, `roles-on-user-edit`, `info-hint`, `info-center-help`, `my-tasks-governance`, `my-tasks-suppliers`, `quality-process-row-actions`, `quality-page-help`, `quality-tools`, `compliance-library`, `supplier-management`, `knowledge-handoff`, `wiki-delete-awaiting-approval`, `app-navigation`, `breadcrumbs`, `dashboard-structure`, `live-filter-collapse`, `wiki-graph-filters`, `auth`) | Samlet kjøring: **168 bestått, 1 feilet, 0 hoppet over** (13,0 min). Feilen (`compliance-library`) var `net::ERR_ABORTED` på `page.goto`, ingen påstandsfeil. Den falt mens agenten endret kildefiler med Vite dev-serveren i gang (en sideomlasting avbrøt navigasjonen). Isolert på endelig kode: 1/1 bestått. Vurdert som ustabil på grunn av kjøremiljøet, ikke regresjon. Etter siste endring: `a11y-forms` + `roles-on-user-edit` 10/10. |
| Full PHP- og E2E-suite | Ikke kjørt (merge gate, jf. prosjektregel). |

### 17.6 Funnstatus etter Fase 3

Lukket betyr: utbedret og verifisert automatisk innenfor testomfanget i 17.5, på sidene i Fase 3. Ingen er manuelt verifisert.

| ID | Status |
|---|---|
| A11Y-001 | Lukket for Brukere og Watch Profiles (auto). Gjenstår i `AI/Show.jsx` (A11Y-135, Fase 4). |
| A11Y-002 | Lukket for GoNoGo, Kundemiljø, Billing og `AiCapacityLevelDialog` (auto). Gjenstår: «Velg ansvarlig» i `AI/Show.jsx` (Fase 4) og fire Wiki-dialoger (Fase 5). |
| A11Y-013 | Lukket for de migrerte skjemaene i Fase 3 (auto). GRC-skjemaene (Fase 6), Notices/AI (Fase 4) og Wiki (Fase 5) gjenstår. |
| A11Y-016, -017 | Lukket på sidene i Fase 3 (beregnet + axe). Øvrige moduler: Fase 4–6. |
| A11Y-019 | Ved implementering: vurdert, ikke endret. **Rettet i sluttkontrollen (§17.12.2).** |
| A11Y-020 | Lukket (auto): bakgrunnen er `inert` mens en `ActionDialog` er åpen, også for de 13 dialogene fra før. Gjenstår manuelt: skjermleserens lesemodus i NVDA/JAWS/VoiceOver. |
| A11Y-025 | Lukket (auto, mus i Chromium). |
| A11Y-027 | Lukket for Kundemiljø (auto). `Quality/Item.jsx` gjenstår (Fase 6). |
| A11Y-028 | Lukket for Kundemiljø (den omtalte komponenten var ubrukt og er fjernet). Øvrige steder: Fase 4–5. |
| A11Y-029 | Lukket (auto). |
| A11Y-041, -042, -043 | Lukket (auto). |
| A11Y-106 | Delvis: trykk på «i» lukker ikke hinten lenger. `size="sm"` er fortsatt 20 px; avstandsunntaket i 2.5.8 må vurderes manuelt per side. |
| A11Y-108, -109, -110 | Lukket på sidene i Fase 3 og i Kvalitets tre tabeller (auto). Øvrige tabeller: Fase 4–6, med `ScrollRegion`. |
| A11Y-111 | Lukket for Kundemiljø (brukere) og Konkurrenter. Øvrige registre: Fase 6. |
| A11Y-120 | Lukket for `DepartmentCheckboxGroup`, egne roller på Rediger bruker og rolledialogen. `ManagementReview`, `Wiki/Graph`: Fase 5–6. |
| A11Y-121 | Delvis: Go/No-go-skjemaene forklarer `*`. Brukere og Avdelinger har ingen påkrevde felt merket med `*`. GRC: Fase 6. |
| A11Y-133 | Delvis (17.4). Restdelen A11Y-137 er rettet i sluttkontrollen (§17.12.3). |
| Klippet fokusring i tabeller (§15.8.2) | Lukket for de målte tabellene (auto: ≥ 4 px luft). |
| A11Y-135–138 | Nye (se §7.2). A11Y-136 er rettet i §10; A11Y-137 og -138 i sluttkontrollen (§17.12). |

### 17.7 Synlige endringer

- **Dialoger:** samme innhold og bredde; avrundingen følger `ActionDialog` (`rounded-3xl`). AI-nivådialogen og Abonnements bekreftelser har litt mer luft (p-6 som før, `rounded-3xl` i stedet for `rounded-2xl`). Maks høyde er vinduets høyde minus 2 rem i stedet for 90/92 vh.
- **Go/No-go-maler:** inaktive maler og punkter er ikke lenger falmet (`opacity-60`), men har lys grå bakgrunn og merket «Inaktiv»/overskriften «Deaktiverte punkter». Et lite «Felt merket med * må fylles ut» står øverst i skjemaene. Mørkere grå tekst og 12 px i stedet for 10–11 px i merker.
- **Brukere, Avdelinger, Konkurrenter, Oppfølging:** hjelpetekster og små etiketter er mørkere grå; feiltekst litt mørkere rød.
- **`InfoHint`:** ved tastaturfokus vises app-rammen (2 px `violet-600`) i stedet for en svak lilla ring. Hinten lukkes ikke lenger på vei fra «i» til teksten.
- **Tabeller:** på smal skjerm kan en bred tabell få fokus (fiolett ramme innvendig) og rulles med piltaster. Ellers ingen endring.
- Ingen ruter, handlinger, tilganger, valideringsregler eller data er endret.

### 17.8 Manuell verifisering som gjenstår

I tillegg til §15.8.7 og §16.8:

1. **NVDA + Firefox/Chrome og VoiceOver + Safari:**
   1. Kundemiljø → Opprett avdeling: dialogen leses med navn og beskrivelse; i lesemodus skal ikke headeren eller tabellen bak kunne nås (A11Y-020). Escape gir fokus til knappen.
   2. Send tomt skjema (Watch Profile, Brukere, avdelingsdialogen): fokus på feltet, feilen leses som feltets beskrivelse, ingen dobbel opplesing.
   3. Tilganger: Space på en rettighet – «Lagret» leses én gang, fokus blir på boksen.
   4. Rediger bruker: «Brukeren tilhører disse avdelingene» leses som gruppe; Rolle har navnet «Rolle»; «Vis forklaring for Rolle» er en egen knapp.
   5. Konkurrenter: sorteringsrekkefølgen leses fra kolonneoverskriften; antall treff leses etter søk.
2. **Ekte Safari:** fokusfellen og `inert` sammen med Safaris standard Tab (uten full tastaturtilgang).
3. **Berøringsskjerm (iOS/Android):** ett trykk på «i» åpner hinten og den blir stående.
4. **Zoom 200/400 %:** dialogene i Kundemiljø og Go/No-go (rulling innenfor dialogen, «Lukk dialog» synlig).
5. **Visuelt:** dialogenes utseende mot før (17.7) og tabellene med innvendig ramme.

### 17.9 Kjente risikoer og begrensninger

- `inert` legges på elementene som finnes når dialogen åpnes. Innhold som monteres senere som søsken av dialogens forfedre (svært uvanlig), blir ikke inert; fokusfellen holder likevel fokus i dialogen.
- Innhold som en dialog portalerer til `<body>` (en nedtrekksliste), er ikke inert, men kan heller ikke få fokus (begrensning fra Fase 1, dokumentert i `ActionDialog`).
- `useFocusFirstError` er per skjema og må kalles fra `onError` i hvert skjema som migreres. Skjema som ikke er migrert, flytter ikke fokus.
- A11Y-137 og A11Y-019 er åpne og krever beslutning.
- Radhandlingenes navn følger den synlige teksten i hviletilstand («Deaktiver X»). Mens handlingen pågår, viser knappen «Deaktiverer…» og navnet er uendret i det korte øyeblikket.
- E2E kjører bare i Chromium.
- Fortsatt fra Fase 1–2: ingen manuell skjermlesertest, ekte Safari/Firefox ikke testet, Windows-høykontrast og nettleserzoom ikke testet, de 5 eksisterende npm-sårbarhetene håndteres separat før merge.

### 17.10 Anbefalinger for Fase 4

1. Gjennomfør den manuelle sjekklisten i 17.8 før Fase 4 bygger videre på `inert` og `useFocusFirstError`.
2. Bruk de nye byggesteinene direkte: `ActionDialog` (+ `DialogTitleBar`) for «Velg ansvarlig», `FormField` med `info` for Kravtype (A11Y-135), `useFocusFirstError` i kravskjemaene, `ScrollRegion` for svarbokser og tabeller i AI-arbeidsflaten.
3. AI-status (A11Y-045, -112): én overgang, én kunngjøring via `announce()`; ingen nye live-regioner.
4. Ta stilling til A11Y-019 (inputkant) og A11Y-137 (flash-feil etter handling på stedet) som felles beslutninger, slik at Fase 4–6 ikke løser dem hver for seg.
5. Fjern dobbel flash (A11Y-138) når sidene berøres.

### 17.11 Vurdering

Alle funn som tilhørte Fase 3 er rettet eller dokumentert som delvis med begrunnelse (A11Y-001, -002, -013, -019, -106, -121, -133). Fire nye funn er registrert (A11Y-135 til -138), og ett av dem (feil kontrastregel i §10) er rettet. Ingen ruter, tilganger, valideringsregler, data eller forretningslogikk er endret. Fasen er **ikke** manuelt verifisert med skjermleser, ekte Safari/Firefox, nettleserzoom, berøringsskjerm eller Windows-høykontrast, og gir ikke grunnlag for å hevde at Procynia oppfyller WCAG 2.2 AA. Full E2E- og PHP-suite er merge gate og er ikke kjørt.

### 17.12 Teknisk sluttkontroll av Fase 3 (2026-10-15)

Kontroll på den endelige Fase 3-koden før commit. Branch `feature/wcag-accessibility`, HEAD `683246a7`; alle Fase 3-endringer fra §17 er bevart, og ingen filer utenfor fasen er med. Alle resultater i 17.12.7 er fra kjøringer **etter** siste kodeendring; ingen kildefil ble endret mens E2E kjørte (jf. 17.5, der en endring under kjøringen avbrøt en navigasjon).

#### 17.12.1 Kodeendringer i sluttkontrollen

| # | Funn | Rettelse | Filer | Test |
|---|---|---|---|---|
| 1 | A11Y-019: feltkanten `slate-200` (1,23:1) | Felles feltfarge `--color-field-border` i `@theme` (utility `border-field-border`) og `rose-600` for felt med `aria-invalid` | `resources/css/app.css`, `Support/formStyles.js`, 13 komponenter og sider (17.12.2) | Enhet + E2E |
| 2 | A11Y-137: avvist radhandling bare i banneret øverst | `Support/rowActionError.js` (ny): meldingen vises ved knappen og beskriver den | `CustomerEnvironment/Index.jsx`, `BusinessAreasPanel.jsx`, `Users/Index.jsx` | Enhet + E2E |
| 3 | A11Y-138: flash-meldingen to ganger på skjermen | Sidenes egne kopier fjernet | `GoNoGo/Templates/{Index,Edit}.jsx`, `Billing/Index.jsx`, `Billing/pageLayout.test.js` | Enhet + E2E |
| 4 | `modalInert`: et unntak mellom «gjør inert» og registrert opprydding ville latt siden stå inert | `ActionDialog` angrer `inert` og stabelen hvis åpningen feiler. `modalInert` holder rede på alt den har gjort inert, og frigjør resten når siste dialog lukkes; en angrefunksjon som kalles to ganger gjør ingenting andre gang; et frigjort forfedreelement gjøres bare inert igjen så lenge en ytre dialog fortsatt er åpen. | `Support/modalInert.js`, `Components/App/ActionDialog.jsx` | Enhet + E2E |

#### 17.12.2 A11Y-019 – feltkant

- **Hvorfor kanten trengs:** feltene er hvite på hvite eller `slate-50`-kort. Etiketten står over feltet, men kanten er det eneste som viser hvor feltet er og hvor stort det er, så 1.4.11 gjelder.
- **Farge:** `oklch(63% 0.042 257)`, mellom `slate-400` og `slate-500` i samme fargetone, så lys som 3:1 tillater, for å bevare uttrykket. Beregnet (sRGB, WCAG-formelen): **3,49:1** mot hvitt, **3,34:1** mot `slate-50`, **3,19:1** mot `slate-100`. Til sammenligning: `slate-200` 1,23:1, `slate-300` 1,48:1, `slate-400` 2,63:1, `slate-500` 4,77:1 (for mørk for formålet).
- **Feiltilstand:** et felt med `aria-invalid="true"` får `rose-600`-kant (4,51:1) via én regel i `app.css` (utenfor lag, så den vinner over feltets egen kantklasse). Feilen sies fortsatt med tekst; kanten viser hvor.
- **Fokus:** uendret. Tastaturfokus er den fiolette 2 px-rammen (5,88:1), tegnet innenfor kanten. Feltenes gamle `focus:border-*` står igjen som musemarkering.
- **Omfang:** `formStyles.js` (`INPUT`, `TEXTAREA`), `SearchableMultiSelect` (delt) og alle tekstfelt og nedtrekkslister på Fase 3-sidene: Brukere, Avdelinger, Kundemiljø (filtre, avdelings-, rolle- og fagområdedialog), Go/No-go-maler, Watch Profiles (skjema og filtre), Konkurrenter. 45 klassestrenger, hver kontrollert som felt (alle har `focus:border-*`). Ikke mekanisk på tvers av kodebasen: AI, Wiki og GRC tar fargen i bruk i sine faser.
- **Unntak:** deaktiverte felt (e-post på Rediger bruker) og skrivebeskyttede visningsbokser beholder `slate-200` (1.4.11 gjelder ikke inaktive komponenter). Avkrysningsbokser og radioknapper tegnes av nettleseren og er ikke endret.
- **Auto:** enhetstest (token, `formStyles`, ingen `slate-200`-felt igjen i de kontrollerte filene, `aria-invalid`-regelen rører ikke outline) og E2E (beregnet kontrast for den rendrete kanten ≥ 3:1 i hvile og ≥ 4,5:1 som ugyldig, fiolett fokusramme beholdt).

#### 17.12.3 A11Y-137 – feil etter radhandlinger

- **Kartlagt:** radhandlinger med `preserveScroll` som kan avvises med flash-feil: Aktiver/Deaktiver bruker i Kundemiljø og på Brukere (`UserController`: egen bruker, brukergrense nådd, siste systemeier) og Slett fagområde (`BusinessAreaController`: i bruk). «Deaktiver» for standardmalen i Go/No-go avvises også av serveren, men knappen er deaktivert i grensesnittet og kan ikke trykkes. Status-knappen på Rediger bruker bevarer ikke rulleposisjonen, så siden lastes på nytt øverst der banneret står.
- **Løsning (i prioritert rekkefølge):**
  1. Lokal visning: `trackRowAction(key, options)` registrerer hvilken rad og handling som ble utført; `useRowActionError(flash.error)` gir meldingen til den raden. Den vises under knappen og er knappens `aria-describedby`.
  2. Kunngjøring via eksisterende mekanisme: banneret (`role="alert"`) er fortsatt den ene kunngjøringen. Den lokale teksten er **ikke** en live-region, så meldingen leses ikke to ganger.
  3. Fokus: blir på knappen (Fase 2-regelen i `routeFocus.js` setter den tilbake hvis raden rendres på nytt).
  4. Ingen rulling.
- Lageret ligger utenfor React, fordi et Inertia-besøk som ikke er GET monterer siden på nytt. Meldingen vises bare så lenge sidens `flash.error` er den samme, så neste besøk fjerner den.
- **Auto:** enhetstester (riktig rad, forsvinner med neste besøk, egne `onSuccess` bevares, ingen ekstra `role="alert"`) og E2E (fagområde med risiko seedet via `RiskE2EFixture`-navn: Slett gir banner + lokal melding, knappen beskrives av meldingen, fokus beholdt, rulling < 40 px, borte etter ny innlasting; data ryddet i `finally`).

#### 17.12.4 A11Y-138 – doble flash-meldinger

- **Årsak:** `GoNoGo/Templates/Index.jsx`, `Templates/Edit.jsx` og `Billing/Index.jsx` tegnet `flash.success`/`flash.error` selv, i tillegg til layoutens `FlashMessages` (Fase 2). Én hendelse ga to synlige meldinger. Kunngjøringen kom én gang, fordi sidenes kopi ikke var en live-region.
- **Rettet ved kilden:** sidenes kopier er fjernet. Suksess er layoutens toast (vedvarende `polite`-region, til den lukkes eller neste side), feil er layoutens `role="alert"`-banner. Ingen meldinger er borte: alle flash-typene sidene viste, vises av layouten. Ingen andre sider i `/app` tegner flash selv (Wiki leser bare `flash.error` i en tilbakemelding).
- **Auto:** enhetstest (ingen `flash.success`/`flash.error` i de tre sidene; layouten har toast og alert; `pageLayout.test.js` oppdatert) og E2E (egen mal seedet og slettet: Deaktiver gir toast, og teksten står én gang på siden).

#### 17.12.5 Dialoger og `inert` – gjennomgang

| Kontroll | Resultat |
|---|---|
| Én åpen modal | Søsken langs stien inert, dialogen og forfedrene aktive, live-regioner og toast aktive (enhet + E2E). |
| To åpne modaler | Nestet dialog i inert område frigjør forfedrene; ytre dialog inert bak den; bare øverste svarer på Escape/Tab; tilbake til ytre dialogs tilstand ved lukking (enhet). Lukking i feil rekkefølge etterlater ingenting inert (enhet, ny). Ingen side i appen åpner i dag to dialoger samtidig, så dette er ikke E2E-testet. |
| Escape og fokusretur | Escape lukker, fokus tilbake til utløseren, bakgrunnen aktiv igjen (E2E). |
| Fokusfelle | Tab/Shift+Tab holdes inne; programmatisk fokus til headeren hindres av `inert` (E2E). |
| Live-regioner | Ikke inert; «Lagret» og toast leses mens en dialog kan være åpen (enhet + E2E). |
| Portaler | Innhold portalert til `<body>` etter åpning blir ikke inert, men kan ikke få fokus (begrensning fra Fase 1, dokumentert). Ingen av dialogene i appen portalerer i dag. |
| Lukket av navigasjon | Dialog åpen, nettleserens Tilbake (Inertia-historikk): dialogen er borte, ingen `[inert]` igjen, siden kan brukes med tastatur (E2E, ny). |
| Feil under åpning | Angres (kode + enhetstest av strukturen). |
| Permanent `inert` | Sikringsnett: når siste dialog lukkes, frigjøres alt modulen har markert (enhet, ny). |

#### 17.12.6 Tabeller og `ScrollRegion` – gjennomgang

- Horisontal rulling med tastatur: brukertabellen ved 390 px er en navngitt region («Brukere»), får fokus og ruller med piltaster (E2E).
- Synlig fokus: regionen har innvendig fiolett ramme; lenke i første og knapp i siste kolonne i Kvalitet har ≥ 4 px luft (E2E).
- Ingen unødvendige tabstopp: avdelingstabellen på desktop er verken region eller tabstopp (E2E).
- Tilgjengelig navn: fra tabellens navn (`a11y.table_*` eller seksjonsoverskriften); axe uten brudd (E2E).
- Funksjon uendret: sortering (Konkurrenter, med fokus beholdt), radhandlinger (Kundemiljø, fagområder, Kvalitets radmeny i regresjonen) og matrisene (Tilganger) fungerer (E2E).

#### 17.12.7 Testresultater (endelig kode)

| Test | Resultat |
|---|---|
| `npm run test:unit` (Node 22, Docker) | **1713 bestått, 0 feilet, 0 hoppet over** (`a11yForms.test.js` har nå 43 tester) |
| `npm run lint:a11y` (Node 22) | **0 feil, 0 advarsler** (45 filer) |
| `npm run build` | Bestått; `border-field-border` finnes i bygd CSS |
| PHP, målrettet (som 17.5, pluss `GoNoGoAssessmentTest` og `QualityPermissionTest`) | **220 bestått, 0 feilet** (1660 påstander) |
| Pint (språkfilene) | Bestått |
| E2E Chromium, samlet: de 25 spesifikasjonene fra 17.5 (inkl. `a11y-forms`, nå 13 tester), endelig kode | **172 bestått, 1 feilet, 0 hoppet over** (173 tester, 14,1 min). Feilen er `a11y-navigation.spec.js` «a real page change moves focus…» (Fase 2-test), se 17.12.8. |
| Første samlede kjøring (forkastet) | 170 bestått, 3 feilet, 9,3 t: maskinen sov midt i kjøringen (applogg tom 05:40–14:47 UTC). To feil var innloggingstidsavbrudd i akkurat det tidsrommet (ingen «Login failed», ingen appfeil) = testmiljø. Den tredje («field borders») var en feil i den nye testen: kantfargen ble lest midt i Tailwinds 150 ms `transition`; testen venter nå på animasjonen, som `focusIndicator` gjør. Hele utvalget ble deretter kjørt på nytt med `caffeinate` (raden over). |
| Full PHP- og E2E-suite | Ikke kjørt (merge gate) |

#### 17.12.8 Gjenværende funn, manuell verifisering og risiko

- **Den ene E2E-feilen (eksisterende, ikke fra Fase 3, årsak uavklart):** `a11y-navigation.spec.js:155` stopper på linje 188: etter Kunngjøringer → Saksliste har ikke sidens h1 fått fokus innen 5 s.
  - **Baseline:** samme test mot `683246a7` i eget git-worktree (egen `vendor`-kopi, eget bygg uten Fase 3-CSS, servert med `artisan serve` på :8091): **3 av 3 feiler på samme påstand**. Feilen er altså ikke innført av Fase 3.
  - **Sondering:** isolert, på samme baseline, får h1 («I arbeid») fokus etter navigasjonen. Feilen opptrer bare i testens lange sekvens (Styring, hopp-lenke, deretter Saksliste).
  - **Ustabil i dag:** på Fase 3-koden gjentatt 5 ganger: 3 bestått, 2 feilet. Feilene kom på andre steg (Wiki-filteret på linje 199 og en `afterAll`-tidsavbrudd i `ComplianceE2EFixture`). Hver kjøring tok ca. 3 min mot ca. 20 s tidligere, med last rundt 6–7.
  - **Tidligere:** testen besto i Fase 2-sluttkontrollen og i Fase 3s første regresjon.
  - **Vurdering:** ikke rettet her. Påvirker ikke Fase 3. Bør undersøkes som egen sak: tidsavhengighet i `routeFocus` ved første navigasjon etter en hopp-lenke, eller testens faste ventetider (`waitForTimeout(300)`).

- **Åpne funn etter Fase 3:** A11Y-135 (Fase 4), A11Y-106 (delvis, trykkflate 20 px, manuell vurdering), A11Y-121 (delvis), og alle funn som tilhører Fase 4–7. Feltfargen og radfeil-mønsteret skal tas i bruk i modulene i Fase 4–6.
- **Manuell verifisering:** hele sjekklisten i 17.8 gjenstår, i tillegg til §15.8.7 og §16.8. Nytt punkt: med NVDA/VoiceOver, avvis en radhandling (Slett et fagområde som er i bruk) og kontroller at meldingen leses én gang, og at den høres som beskrivelse når fokus står på knappen.
- **Risiko før Fase 4:**
  - Feltfargen er en synlig endring (kantene er tydeligere) på Fase 3-sidene. Inntil Fase 4–6 er gjennomført, har de andre modulene fortsatt lysere kanter, så kantfargen varierer midlertidig mellom moduler.
  - `trackRowAction` må tas i bruk per handling. Radhandlinger som ikke bruker det, viser fortsatt bare banneret.
  - Det nestede dialogscenarioet er bare enhetstestet, fordi ingen side bruker det.
  - E2E kjører bare i Chromium.

#### 17.12.9 Oppfølgingsoppgave før merge: OPPF-001 – `a11y-navigation` i samlet regresjon

**Status:** åpen. Commit av Fase 3 er godkjent med denne feilen kjent (beslutning 2026-10-15). Det er **ikke** en generell aksept av feilede tester. Oppgaven skal være lukket før `feature/wcag-accessibility` merges til `main`.

- **Test:** `tests/e2e/a11y-navigation.spec.js:155` «a real page change moves focus to the new heading; a filter on the same page does not move it».
- **Symptom:** feiler på linje 188 (h1 etter Kunngjøringer → Saksliste har ikke fokus innen 5 s). Ellers ustabil på linje 199 (Wiki-filteret) og i `afterAll` (tidsavbrudd i `ComplianceE2EFixture`).
- **Kjent:** reprodusert 3/3 på `683246a7` uten Fase 3-kode (17.12.8). Isolert sondering viser at fokus havner på h1, så feilen avhenger av sekvensen i testen og/eller av miljøets hastighet.
- **Undersøk:**
  1. Om `routeFocus.js` kan miste fokusflyttingen ved første Inertia-navigasjon etter en hopp-lenke (fokus i `main`, regelen «siden har selv lagt fokus i `main`»).
  2. Om testens faste ventetider (`waitForTimeout(300)`) må erstattes av hendelsesbaserte ventinger.
  3. Om `ComplianceE2EFixture` i `afterAll` er for treg med dagens datamengde.
- **Akseptansekriterium:** testen består **stabilt i det samlede regresjonsutvalget** (de 25 spesifikasjonene i §17.12.7), ikke bare isolert:
  - minst tre påfølgende samlede kjøringer uten feil i denne testen;
  - ingen økt global timeout;
  - ingen deaktiverte eller svekkede påstander;
  - eventuell rettelse i appen (ikke bare i testen) dokumentert med årsak.

#### 17.12.10 Vurdering

Sluttkontrollen fant og rettet tre registrerte funn (A11Y-019, -137, -138) og én svakhet i `modalInert` (mulig gjenværende `inert`). Ingen ruter, tilganger, valideringsregler, data eller forretningslogikk er endret. Enhetstester, lint, bygg og PHP er grønne; E2E-utvalget har én feil, og den er reprodusert på `683246a7` og dermed ikke fra Fase 3. Fase 3 er teknisk klar for commit. Fasen er ikke manuelt verifisert og gir ikke grunnlag for å hevde at Procynia oppfyller WCAG 2.2 AA.

---

## 18. Fase 4 – gjennomføringsrapport (2026-10-15)

Branch `feature/wcag-accessibility`, utgangspunkt `5559fcb9` (Fase 3), rent arbeidstre ved start. Begrepene er de samme som i §17: **Kode** = implementert, **Auto** = automatisk test i repoet, **Agent** = kontrollert av agenten i ekte nettleser (Playwright, Chromium), **Manuell** = kontrollert av en person. Ingen manuell verifisering er gjort. Fase 4 er ikke committet.

### 18.1 Omfang og arbeidsmåte

Kartlagt før endringene (intern oversikt per fil og funn):

| Modul | Filer | Hva som finnes |
|---|---|---|
| Kunngjøringer, saksliste, historikk | `Notices/Index.jsx`, `Notices/CpvSelector.jsx`, `Components/App/DiscoveryNoticeCard.jsx` | Livesøk (Doffin/TED), filtre, CPV-kombinasjonsboks, privat forespørsel, frist- og historikkskjema per sak, arkivdialog, paginering, varsler |
| Saksvisning og Go/No-go | `Notices/SavedShow.jsx`, `Notices/GoNoGoAssessment.jsx`, `Components/App/BidStatusPipeline.jsx` | Saksforløp, fasekommentarer, informasjonssenter (aksjoner), statushandlinger med lukkeårsak, No-Go-dialog, gjenåpning, bid manager/eier (vanlig POST), saksinnsyn, arkivering, Go/No-go-vurdering |
| Kunngjøringsdetalj | `Notices/Show.jsx` | Beskrivelse, avdelingskontekst, CPV, dokumentnedlasting |
| AI-arbeidsflaten | `AI/Show.jsx`, `AI/Index.jsx`, `AI/Instructions.jsx`, `AI/DocumentPreview.jsx`, `AI/wikiAnswerMarkdown.js` | Dokumentopplasting, dokumentstatus og kravuttrekk med polling (3 s), kravliste med godkjenning/avvisning/sletting og arbeidsstatus, manuelt krav, redigering av krav, Wiki-svar (generering via axios, opptil 120 s), Markdown-visning og -redigering, kopiering, dokumentasjon/kilder, avansert vurdering, ansvarlig-velger |

Det finnes ingen dra-og-slipp-opplasting, ingen egen spørsmål/svar-modul (spørsmål og svar er kravene og Wiki-svarene i AI-arbeidsflaten), og ingen kvalitetsscore i tall; kvalitet vises som dekning, samsvar og proveniens (merker med tekst). «Godkjenning» i disse modulene er godkjenning av krav (Godkjenn / Til utkast / Avvis / Gjenopprett) og Go/No-go-beslutningen.

Endringene er gjort på presentasjonsnivå. Ingen prompter, modeller, AI-logikk, polling-intervaller, kø, parsing, ruter, kontrollere, validering, autorisasjon, data eller migrasjoner er endret. De store filene er ikke refaktorert; hver endring er avgrenset til attributter, etiketter, fokus og kunngjøringer.

### 18.2 Implementert

| Del | Hva | Filer | Funn | Status |
|---|---|---|---|---|
| A. Go/No-go | Hvert vurderingspunkts Lav/Middels/Høy er en navngitt gruppe (punktets tittel, beskrevet av kortbeskrivelsen) av veksleknapper med `aria-pressed`. Valgt verdi har hake i tillegg til farge. Et nytt trykk fjerner valget, som før (derfor ikke radio). Begrunnelsesfeltet heter «Begrunnelse for X (valgfritt)». «?» heter «Vurderingshjelp for X» og får fokus tilbake når hjelpepanelet lukkes. Foreløpig beslutningsgrunnlag kunngjøres når det dukker opp eller endres. «Lagre vurdering» forblir fokuserbar (`aria-disabled`), og en avvist lagring sier fra ved knappen (`role="alert"`). Seksjonen er en navngitt region. Fremdriftsstripen og fargeprikken er dekor (`aria-hidden`). | `Notices/GoNoGoAssessment.jsx` | A11Y-003, -104, -141, -142, -143 | Kode + auto (enhet + E2E) |
| B. Saksforløp | Stegene er en `<ol>` («Saksforløp»), nåværende steg `aria-current="step"`, og hvert steg sier «fullført», «nåværende steg» eller «gjenstår» (skjult tekst). «Steg N» 12 px `slate-600` (var 11 px `slate-500`). | `Components/App/BidStatusPipeline.jsx` | A11Y-024, -016 | Kode + auto |
| C. Saksvisningen | Én `main` (A11Y-139). Alle åtte skjemaer går via `FormField`: fasekommentar, ny aksjon (påkrevd beskrivelse merket), lukking av aksjon, lukkeårsak, bid manager og kommersiell eier (vanlig POST: seksjonen åpnes og feltet får fokus når siden kommer tilbake med feil), saksinnsyn, gjenåpning, No-Go og arkivering. Feil er koblet til feltet, felt er `aria-invalid`, og fokus går til første feil etter avvist innsending. Dialogene har beskrivelse (`descriptionId`). «Fjern tilgang» heter «Fjern tilgang <navn>», og fokus går videre til skjemaet når raden forsvinner. Disclosure-knapper har `aria-expanded`. | `Notices/SavedShow.jsx` | A11Y-013, -014, -028, -109, -121, -139 | Kode + auto |
| D. Kunngjøringer og saksliste | Livesøket heter «Live søk i …» (synlig tittel), ikke bare plassholder (A11Y-040). Publiseringsdato er en gruppe, ikke nestede etiketter (A11Y-140). Privat forespørsel, frister, privat sak og historikk går via `FormField`; tre historikkfelt fikk navn; påkrevde felt er merket; feiltekst `rose-700`. Antall treff kunngjøres etter søk, filter og kildebytte (ikke ved paginering eller polling). Kortknappene («Åpne sak», «Rediger frister», «Flytt til historikk», «Slett», «Åpne i Doffin») er beskrevet av kortets tittel. Avvist sletting vises ved knappen (`rowActionError`). Paginering er en navngitt `nav`. Varsel-kortene bruker h2, søketreff h3. | `Notices/Index.jsx`, `Components/App/DiscoveryNoticeCard.jsx` | A11Y-013, -040, -109, -111, -121, -137, -140 | Kode + auto |
| E. Kunngjøringskort | «Lagre» er `aria-disabled` når saken er lagret, og har stabil id, så fokus blir på (eller kommer tilbake til) knappen etter lagring. «Vis mer» har `aria-expanded`. | `Components/App/DiscoveryNoticeCard.jsx` | A11Y-028, -143 | Kode + auto |
| F. CPV-velger | Forslagene er utenfor tabrekkefølgen (`aria-activedescendant` styrer), listeboksen inneholder bare forslag, `aria-expanded` bare når det finnes forslag, og antall treff kunngjøres når et søk svarer. | `Notices/CpvSelector.jsx` | A11Y-144, -111 | Kode + auto |
| G. AI: dokumenter | Opplasting via `FilePickerField` (nytt: `multiple`, `files`, `busy`, `inputRef`, bakoverkompatibelt). Feltet heter «Velg filer», beskrives av valgte filer, tillatte filtyper og feil; feilen er `role="alert"` og feltet får fokus ved avvisning. Under opplasting er feltet og knappen `aria-disabled` (fokus beholdes). «Forhåndsvis» er beskrevet av filnavnet; dokumentlisten har `aria-expanded`. Dokumentstatus var allerede tekst («Venter på behandling», «Behandles», «Ferdigstiller krav», «Ferdig behandlet», «Feilet»), aldri bare farge. | `AI/Show.jsx`, `Components/App/FilePickerField.jsx` | A11Y-011, -028 | Kode + auto |
| H. AI: kravuttrekk | Banneret er ikke lenger en live-region som monteres med innhold og oppdateres ved hver polling (A11Y-112). En overgang kunngjøres én gang: «Kravuttrekket er startet.», «Kravuttrekket er ferdig. N krav er hentet ut.» (polite) og «Kravuttrekket feilet.» (assertive). Tilstanden siden åpnes i, kunngjøres ikke. | `AI/Show.jsx`, `AI/aiStatusAnnouncements.js` (ny), `Support/statusAnnouncement.js` (ny) | A11Y-045, -112 | Kode + auto (enhet + E2E med ekte polling) |
| I. AI: kravkort | Kortet er ikke lenger `role="button"` med knapper og felt inni (A11Y-044); kravteksten er knappen som åpner svaret, med `aria-current` og ring i `violet-500` (≥ 3:1) for det åpne kravet. «Lag svar» har ikke `aria-pressed` (A11Y-115) og er `aria-disabled` mens listen er låst. Rad-handlinger (godkjenn, til utkast, avvis, gjenopprett, arbeidsstatus, ansvarlig, analyser, avvis alle, slett alle, slett) gir fokus tilbake til samme kontroll eller til kravet når serveren har svart, men bare hvis fokus faktisk gikk tapt (`refocusIfLost`, ny i `focusManagement.js`). Bekreftelsespanelene (slett/avvis, enkelt og alle) får fokus ved åpning (navngitt gruppe) og gir det tilbake ved Avbryt. Redigering av krav og manuelt krav: fokus til første felt ved åpning, tilbake til knappen ved lukking. | `AI/Show.jsx`, `Support/focusManagement.js` | A11Y-014, -044, -115 | Kode + auto |
| J. AI: skjema | Manuelt krav og redigering av krav via `FormField`; «i» for Kravtype står utenfor etiketten (A11Y-135). Feltfeil koblet, fokus til første feil, skjemafeil som ikke gjelder et felt vises én gang som oppsummering. Promptfeltet er beskrevet av hinten. AI-instrukser via `FormField`. | `AI/Show.jsx`, `AI/Instructions.jsx` | A11Y-013, -135 | Kode + auto |
| K. AI: svar | «Svarutkastet genereres …» kunngjøres ved start og «Svarutkastet er klart.» når det er ferdig; selve teksten leses ikke opp, og fokus flyttes ikke. Feil er `role="alert"` i svarpanelet. Generer, Kopier og Lagre er `aria-disabled` mens de arbeider. «Rediger» flytter fokus til Markdown-feltet (beskrevet av hinten), Lagre/Avbryt gir det tilbake til «Rediger». «Svaret er lagret.» og «Svaret er kopiert.» kunngjøres; kopifeil er `role="alert"`. «Ekspertutkast», «Dokumentasjon og samsvar» og «Kilder» er h3 under panelets h2. Svarpanelet er en navngitt seksjon. Merker for dekning, samsvar og proveniens er tekst. | `AI/Show.jsx` | A11Y-045, -116 | Kode + auto (generering mocket) |
| L. Rulleflater | `ScrollRegion` har fått `axis="y"` (bakoverkompatibelt): kravlisten, svarpanelet og det rendrede svaret blir navngitte, fokuserbare regioner bare mens innholdet er høyere enn boksen. Kopieringen leser fortsatt bare boksens barn, så attributtene havner ikke på utklippstavlen. AI-oversiktens tabell bruker `ScrollRegion`, `<caption>`, `scope` og radens navn på «Åpne sak». | `Components/App/ScrollRegion.jsx`, `AI/Show.jsx`, `AI/Index.jsx` | A11Y-108, -109, -110 | Kode + auto |
| M. Dialog | «Velg ansvarlig» er `ActionDialog` (rolle, navn, beskrivelse, felle, Escape, fokusretur, inert bakgrunn, «Lukk dialog»). Merk A11Y-145: dialogen åpnes ikke fra dagens grensesnitt. | `AI/Show.jsx` | A11Y-002 | Kode + auto (enhet) |
| N. Reflow | Go/No-go-raden kan bryte linjen; AI-sidens kolonner og filter kan ikke presse siden bredere enn 320 px; dokumentlistens topp bryter linjen. | `GoNoGoAssessment.jsx`, `AI/Show.jsx` | A11Y-142 | Kode + auto (E2E 320/640) |
| O. Felt og kontrast | `border-field-border` på alle tekstfelt og nedtrekkslister i Fase 4-filene (vurdert enkeltvis; deaktiverte felt beholder sin stil). Feiltekst `rose-700`. Tekst under 12 px fjernet. | Fase 4-filene | A11Y-016, -019 | Kode + auto |
| i18n | `procynia.a11y.*`: `pipeline_label`, `step_number`, `step_completed`, `step_current`, `step_upcoming`, `gonogo_comment`, `gonogo_help`, `gonogo_verdict`, `gonogo_save_failed`, `ai_extraction_started`, `ai_extraction_completed`, `ai_extraction_failed`, `ai_answer_generating`, `ai_answer_ready`, `ai_answer_saved`, `ai_answer_copied`, `pagination`. no + en. | `lang/{no,en}/procynia.php` | — | Auto |
| Lint | `lint:a11y` dekker 11 nye filer (56 totalt). Ingen regel er slått av. De 6 reelle funnene i filene (kravkortet, egen modal, avkrysning i etikett uten tekst) er rettet. | `eslint.config.js` | — | Auto |

### 18.3 AI-status og dokumentstatus

- **Prinsipp:** én overgang, én kunngjøring, gjennom de faste regionene i `announcer.js`. Ingen nye live-regioner. Polling (3 s) og AI-kall (opptil 120 s) er uendret.
- **Kravuttrekk:** tilstanden reduseres til `processing`, `failed` eller `none` (`extractionState`). `useTransitionAnnouncement` taler bare når tilstanden endres etter første visning. Agent: med et dokument i «Behandles» gikk minst to polling-runder (6,5 s) uten kunngjøring; da dokumentet ble ferdig, kom «Kravuttrekket er ferdig. 2 krav er hentet ut.» én gang.
- **Generering:** kunngjøres ved start og ved ferdig svar fra handleren, ikke fra visningen; teksten i svaret leses ikke opp automatisk. Feil vises i panelet som `role="alert"` (ikke i tillegg kunngjort).
- **Fokus:** flyttes ikke når resultatet kommer. Dette avviker bevisst fra den opprinnelige løsningsteksten for A11Y-045 i §7.2 («fokus til svaroverskriften når svaret er klart»): brukeren kan ha gått videre mens et svar genereres i opptil to minutter, og et fokushopp ville avbryte arbeidet. Kunngjøringen sier at svaret er klart; overskriften «Ekspertutkast» er nå en h3 som kan nås med overskriftsnavigasjon.
- **Dokumentstatus:** var tekst fra før (`getDocumentStatusMeta`, `getRequirementStatusMeta`); statusmerkene er uendret.

### 18.4 Tester og resultater

Resultatene er fra kjøringer på endelig kode.

| Test | Resultat |
|---|---|
| `npm run test:unit` (Node 22, Docker) | **1766 bestått, 0 feilet, 0 hoppet over.** Ny: `resources/js/Support/a11yPhase4.test.js` (53 tester: statusoverganger, kravuttrekk, kunngjøringer ved generering/lagring/kopiering, `aria-disabled` på arbeidsknapper, kravkortet, InfoHint utenfor etikett, Go/No-go-gruppe/`aria-pressed`/navn/fokusretur/feil/dom, én `main`, saksforløp, livesøkets navn, ingen nestede etiketter, feltnavn i historikk, fokus til første feil i alle Fase 4-skjema, CPV-listeboks, «Lagre» på kort, «Velg ansvarlig» som `ActionDialog`, `FilePickerField` med flere filer, vertikal `ScrollRegion`, reflow-klasser, `refocusIfLost`, språknøkler). Tre eksisterende kildetester (`requirementDeletion`, `filterPanelLogic`, `sectionHierarchy`) låser markup; koden ble tilpasset dem, testene er uendret. |
| `npm run lint:a11y` (Node 22) | **0 feil, 0 advarsler** (56 filer). |
| `npm run build` | Bestått. |
| PHP, målrettet (`A11yTranslationsTest`, `SharedTranslationsLocaleTest`, `AiControllerTest`, `RequirementWikiAnswerControllerTest`, `RequirementDeletionTest`, `GoNoGoAssessmentTest`, `CustomerSavedNoticeWorklistTest`, `SavedNoticeSourceIdentityTest`, `LiveSearchSourceSelectionTest`, `MultiSourceOpportunity(Ui)Test`, `CommercialOwnerRoleTest`, `XlsxRequirementImportTest`, `AiQuotaCustomerUxTest`, `CustomerFrontendAccessTest`, `BidWorkflowNotificationTest`) | **355 bestått, 0 feilet** (2504 påstander). |
| Pint (språkfilene, ny fixture) | Bestått. |
| E2E `a11y-tenders-ai.spec.js` (ny, 9 tester, egen fixture `Tests\Support\A11yTenderAiE2EFixture`) | **9 bestått, 0 feilet.** Go/No-go med tastatur (gruppe, `aria-pressed`, hake, hjelp inn/ut, dom kunngjort, lagring med fokus beholdt, toast), saksforløp og én `main`; No-Go-dialog (fokus, påkrevd, avvist → fokus på årsak, Escape → utløser); privat forespørsel avvist → fokus, `aria-invalid`, beskrivelse; fasefilter kunngjør treff; AI-opplasting (navn, beskrivelse, ugyldig filtype avvist uten opplasting → fokus og alert), kravkortet (ingen `role="button"`, `aria-current`), manuelt krav (fokus inn, avvist → fokus), slett-bekreftelse (fokus inn og tilbake); generering mocket (kunngjort start/ferdig, fokus beholdt, `aria-disabled`), feilet generering (alert); redigering, lagring og kopiering (fokus og kunngjøring); kravuttrekk med ekte polling (ingen kunngjøring underveis, én ved ferdig); reflow 320 og 640 px. axe uten brudd på Go/No-go (urørt og utfylt), No-Go-dialog med feil, privat forespørsel med feil, opplastingsskjema, svarpanel, kravkort og manuelt krav med feil. Ingen ekte AI-kall. |
| E2E-regresjon, 29 spesifikasjoner (195 tester) | Kjøring 2: **195 bestått, 0 feilet**. Kjøring 1: én innloggingstidsavbrudd (18.5). |
| Full PHP- og E2E-suite | Ikke kjørt (merge gate, jf. prosjektregel). |

### 18.5 Regresjon og eksisterende feil

Utvalg: de 25 spesifikasjonene fra §17.12.7 pluss `a11y-tenders-ai`, `ai-answer-markdown-table`, `ai-experience` og `wiki-navigation` (29 spesifikasjoner, 195 tester), Chromium, endelig kode, Vite dev-server i gang, ingen kildeendringer under kjøringen.

| Kjøring | Resultat | Merknad |
|---|---|---|
| 1 | **186 bestått, 1 feilet, 8 ikke kjørt** (14,0 min) | Feilen var innloggingen i `beforeEach` for første test i `a11y-tenders-ai` (blir stående på `/login` i 15 s); ingen påstand nådde Fase 4-koden. Applogg: testen rett før (`a11y-navigation`, «a rejected sign-in …») logger med vilje feil passord; den neste innloggingen ble aldri registrert, og ingen strupe-hendelse finnes. Samme symptom som innloggingstidsavbruddene i §17.12.7. De 8 ble ikke kjørt fordi specen er seriell. Specen besto deretter isolert 9/9 på samme kode. Vurdert som testmiljø, ikke regresjon. |
| 2 | **195 bestått, 0 feilet** (16,0 min) | Hele utvalget grønt, også `a11y-navigation.spec.js:155` (OPPF-001). |

- **OPPF-001:** testen besto i begge kjøringene her (to samlede kjøringer på rad). Akseptansekriteriet i §17.12.9 krever tre påfølgende samlede kjøringer på det endelige utvalget før oppgaven lukkes, og at en eventuell rettelse dokumenteres; ingen kode er endret for den. Oppgaven er fortsatt **åpen**, men har nå to grønne datapunkter.
- **Eksisterende kildetester:** `requirementDeletion.test.js`, `filterPanelLogic.test.js` og `sectionHierarchy.test.js` låste markup som Fase 4 berørte (signaturen til `updateRequirementReviewStatus`, «Slett …» på CPV-brikker, `<h3>` uten attributter på sakskort). Koden ble tilpasset slik at testene står uendret.

### 18.6 Funnstatus etter Fase 4

Lukket betyr: utbedret og verifisert automatisk innenfor testomfanget i 18.4, på sidene i Fase 4. Ingen er manuelt verifisert.

| ID | Status |
|---|---|
| A11Y-002 | «Velg ansvarlig» lukket (auto, enhet; dialogen kan ikke åpnes i dag, se A11Y-145). Gjenstår: fire Wiki-dialoger (Fase 5). |
| A11Y-003 | Lukket (auto: enhet + E2E med tastatur og axe). |
| A11Y-011 | Lukket for AI-opplastingen (auto). Gjenstår: `Wiki/Index.jsx` (Fase 5), `SupplierManagement/Import.jsx` (Fase 6). |
| A11Y-013 | Lukket for alle skjemaer i Fase 4-filene (auto). GRC (Fase 6) og Wiki (Fase 5) gjenstår. |
| A11Y-014 | Lukket for AI-arbeidsflaten (bekreftelsespaneler, redigering, radhandlinger) og saksinnsyn (auto). A11Y-146 (forretningsgjennomgang) åpen. |
| A11Y-016, -019 | Lukket i Fase 4-filene (beregnet + axe på de testede flatene). To dekorative ikoner i `slate-400` står igjen (bygning ved oppdragsgiver), tekst følger av navnet ved siden av. |
| A11Y-024 | Lukket for `BidStatusPipeline` (auto). Wiki-tidslinjen (Fase 5) og Ledelsens gjennomgåelse (Fase 6) gjenstår. |
| A11Y-028 | Lukket for Notices (`Index`, `Show`, `SavedShow`), `DiscoveryNoticeCard` og AI-arbeidsflaten (auto, enhet). Wiki gjenstår (Fase 5). |
| A11Y-040 | Lukket (auto). |
| A11Y-044 | Lukket (auto). Synlig endring: et klikk på kortets kant åpner ikke lenger svaret; kravteksten, «Lag svar» og «Prompt» gjør. |
| A11Y-045 | Lukket med endret løsning (18.3): kunngjøring, ikke fokusflytting (auto). |
| A11Y-104 | Lukket for Go/No-go-hjelpen (auto). |
| A11Y-108, -109, -110 | Lukket i AI-oversikten, kravlisten, svarpanelet og kortene i Notices (auto). |
| A11Y-111 | Lukket for livesøk, saksliste/historikk og CPV-forslag (auto for sakslistens filter; livesøket mot Doffin er ikke E2E-testet fordi det kaller et eksternt API). |
| A11Y-112 | Lukket for AI-kravuttrekket (auto med ekte polling). `Wiki/Index.jsx:638` gjenstår (Fase 5). |
| A11Y-115, -116 | Lukket (auto). |
| A11Y-121 | Delvis: påkrevde felt merket og forklart i privat forespørsel, ny aksjon, lukkeårsak, No-Go og gjenåpning. GRC (Fase 6). |
| A11Y-133, -137 | Radfeil ved sletting av sak i saksliste og historikk (kode + enhet; avvisning er vanskelig å fremprovosere i E2E fordi knappen sjekker `can_delete` først). |
| A11Y-135 | Lukket (auto). |
| A11Y-139 – -144 | Nye, rettet (auto). |
| A11Y-145 | Ny, informasjon. Utsatt produktbeslutning (§18.14); blokkerer ikke Fase 4. |
| A11Y-146 | Rettet i sluttkontrollen (§18.14). |
| A11Y-147 | Ny, rettet i sluttkontrollen (§18.14). |
| A11Y-105 | Uendret delvis: hardkodet norsk står igjen i deler av Notices og AI (`DocumentPreview`, `Instructions`, CPV-tekster, `BidStatusPipeline`-etikettene, enkelte etiketter i sakskortene). Etter prosjektregelen er bare nye strenger oversatt; eksisterende sider oversettes når det bestilles. |

### 18.7 Synlige endringer

- **Go/No-go:** valgt vurdering har en hake foran teksten. Teksten i foreløpig beslutningsgrunnlag er ikke lenger falmet. På smal skjerm bryter vurderingsknappene linjen under punktets tittel i stedet for å presse siden bredere.
- **Saksforløp:** «Steg N» 12 px og mørkere grå.
- **AI-arbeidsflaten:** opplastingsfeltet er Procynias felles filvelger (sekundærknapp «Velg filer», valgte filer og tillatte filtyper under), med «Last opp …» ved siden av; den egne fiolette pillen og den doble «Velg filer»-teksten er borte. Det åpne kravet har en tydeligere ring (`violet-500` i stedet for `violet-300`). Et klikk på kortets kant åpner ikke svaret; kravteksten er klikkbar (og får fiolett tekst ved hover). Tekstfelt har tydeligere kant.
- **Notices og saksvisning:** tekstfelt har tydeligere kant, feiltekst litt mørkere rød, påkrevde felt har * og forklaringen «Felt merket med * må fylles ut.». Livesøkets ikon er `slate-500`. Avvist sletting vises også ved knappen.
- **Uendret:** ruter, handlinger, rekkefølgen av felt, polling, AI-svar og alle data.

### 18.8 Nettleserkontroll (agent)

Kontrollert i Chromium (Playwright) på `localhost:8080` med Vite dev-server:

- **Bredder:** 320 px og 640 px (E2E), 1440 px (alle testene). Ved 320 px er 400 % zoom av en 1280 px-skjerm dekket; 640 px tilsvarer 200 % av 1280 px. Ingen sidelengs rulling på saksvisningen eller AI-arbeidsflaten med dokumentliste, svar og skjema åpne (etter A11Y-142).
- **Tastatur:** Go/No-go (Space/Enter, Escape), No-Go-dialog, opplasting, kravkort, bekreftelsespaneler, svarredigering — fokus synlig (app-rammen fra Fase 1) og aldri tapt i de testede flytene.
- **Ikke kontrollert:** nettleserens egen zoom (Ctrl/Cmd +), Firefox, Safari, forced-colors.

### 18.9 Manuell testing som gjenstår

I tillegg til §15.8.7, §16.8 og §17.8:

1. **NVDA + Firefox/Chrome og VoiceOver + Safari:**
   1. Go/No-go: gruppen leses med punktets navn og beskrivelse, knappene som «trykket/ikke trykket», dommen leses én gang når siste punkt vurderes.
   2. AI: last opp et dokument og vent på uttrekket — «startet» og «ferdig» leses én gang hver, ikke ved hver polling; banneret kan leses i lesemodus.
   3. Lag svar: «genereres» og «klart» leses, svaret leses ikke automatisk, fokus blir der brukeren er.
   4. Kopier svar: «Svaret er kopiert.» leses; lim inn i Word og kontroller at innholdet er uendret (attributtene på rulleboksen skal ikke være med).
   5. Kravkortet: kravteksten leses som knapp, det åpne kravet som «gjeldende».
   6. CPV-velgeren: piltaster gjennom forslagene leses (aktiv etterkommer), Tab går videre ut av feltet.
2. **Ekte Safari:** at `aria-disabled`-knappene (Lag svar, Generer, Kopier, Lagre vurdering) beholder fokus også der, og at de vertikale regionene kan rulles med piltaster.
3. **Livesøk mot Doffin/TED:** at antall treff leses etter et søk (krever ekstern tjeneste, ikke E2E-testet).
4. **Zoom 200/400 %** med nettleserens egen zoom på saksvisningen og AI-arbeidsflaten.
5. **Visuelt:** opplastingsfeltet og ringen rundt det åpne kravet (18.7).

### 18.10 Kjente risikoer og begrensninger

- `refocusIfLost` og panel-fokus bygger på element-id-er (`ai-req-<id>-…`). En fremtidig endring som fjerner id-ene, gir tilbake fokustap uten at noe annet feiler; enhetstestene låser de viktigste.
- `aria-disabled`-knappene må vokte seg selv i handleren. Det er gjort for alle som ble endret; nye knapper som kopierer mønsteret, må gjøre det samme.
- Kunngjøring av antall treff i livesøket er ikke testet mot den eksterne tjenesten.
- «Velg ansvarlig» (A11Y-145) er bare enhetstestet, fordi den ikke kan åpnes.
- Polling-rekkefølge: hvis et dokument går fra «Behandles» via «Feilet» tilbake til «Behandles» (ny kjøring), kunngjøres både feil og ny start — ønsket, men ikke testet.
- E2E kjører bare i Chromium.

### 18.11 Oppfølgingsoppgaver

- **OPPF-001** (fra Fase 3) er fortsatt åpen; se 18.5 for hvordan testen oppførte seg i Fase 4-regresjonen. Akseptansekriteriet i §17.12.9 gjelder uendret.
- **A11Y-145:** produktbeslutning om ansvarlig-velgeren.
- ~~**A11Y-146:** fokus etter «Slett» på en forretningsgjennomgang.~~ Rettet i sluttkontrollen (§18.14).
- **A11Y-105:** oversettelse av eksisterende hardkodet norsk i Notices/AI når det bestilles.

### 18.12 Anbefalinger for Fase 5

1. Gjenbruk `useTransitionAnnouncement` for Wiki-kjøringene (A11Y-112 i `Wiki/Index.jsx`): reduser kjøringens status til noen få tilstander og kunngjør bare overganger.
2. Bruk `FilePickerField` med `busy` for kildeopplastingen (A11Y-011) og `ActionDialog` for de fire dialogene (A11Y-002).
3. Bruk `aria-disabled` + vakt for påstandsgodkjenning og publisering, og `refocusIfLost` etter `router.patch` på påstander (A11Y-014).
4. `ScrollRegion axis="y"` for artikkel- og påstandspaneler med fast høyde.
5. Kontroller reflow ved 320 px tidlig: Fase 4 fant tre tilfeller av «boks som ikke kan krympe» (A11Y-142).

### 18.13 Vurdering

Alle Fase 4-funnene fra planen er rettet eller dokumentert med begrunnelse: A11Y-003, -040, -044, -045 (endret løsning, 18.3), -112, -115, -116, -135 og Fase 4-delene av -002, -011, -013, -014, -016, -019, -024, -028, -104, -108–-111, -121, -137. Åtte nye funn er registrert (A11Y-139 til -146); seks er rettet, ett er informasjon (A11Y-145) og ett er åpent (A11Y-146). Ingen prompter, modeller, AI-logikk, polling, ruter, kontrollere, validering, tilganger, data eller migrasjoner er endret. Enhetstester, lint, bygg og målrettet PHP er grønne; den nye E2E-specen og den andre samlede regresjonen er grønne. Fase 4 er etter agentens vurdering teknisk klar for sluttkontroll, men er **ikke** sluttkontrollert, ikke manuelt verifisert med skjermleser, ekte Safari/Firefox, nettleserzoom eller Windows-høykontrast, og gir ikke grunnlag for å hevde at Procynia oppfyller WCAG 2.2 AA. Full E2E- og PHP-suite er merge gate og er ikke kjørt.

### 18.14 Teknisk sluttkontroll av Fase 4 (2026-10-15)

Kontroll på Fase 4-koden før commit. Branch `feature/wcag-accessibility`, HEAD `5559fcb9`; alle Fase 4-endringer fra 18.2 er bevart, og ingen fremmede filer er med (ignorerte lokale artefakter som `public/hot`, `test-results/` og `playwright-report/` er ikke en del av endringen). Alle resultater i 18.14.6 er fra kjøringer **etter** siste kodeendring.

#### 18.14.1 Kodeendringer i sluttkontrollen

| # | Funn | Rettelse | Filer | Test |
|---|---|---|---|---|
| 1 | A11Y-146: fokus etter «Slett» på en forretningsgjennomgang | Fokus til neste dato (som nå har samme posisjon), ellers forrige, ellers «Legg til BR». `refocusIfLost` har fått et valgfritt `always`: radene har posisjonsbasert `key`, så React gjenbrukte den trykkede «Slett» for neste rad — fokus gikk ikke tapt, men sto på en knapp som ville slettet neste dato ved et nytt Enter. Ingen endring i slettingen eller i skjemaet (endringen lagres fortsatt først ved «Lagre»). | `Notices/businessReviewFocus.js` (ny), `Notices/Index.jsx`, `Support/focusManagement.js` | Enhet + E2E |
| 2 | A11Y-147 (ny, eksisterende feil): «Legg til BR» var en tom knapp | Riktig oversettelsesnøkkel | `Notices/Index.jsx` | Enhet + E2E |
| 3 | Dobbel opplesing av opplastingsfeil: `FilePickerField` gjør feilen til `role="alert"` (Fase 1), og Fase 4 flytter i tillegg fokus til feltet, der feilen er beskrivelsen | Nytt valg `announceError` (standard `true`, uendret for de andre filvelgerne); AI-opplastingen bruker `false`, så feilen høres én gang, ved feltet (Fase 3-regelen) | `Components/App/FilePickerField.jsx`, `AI/Show.jsx` | Enhet + E2E |

Ingen andre kodeendringer. Ingen tester er svekket; Fase 1-testen som låser `role="alert"` i `FilePickerField` står uendret.

#### 18.14.2 A11Y-145 – «Velg ansvarlig»

Beslutning: koden beholdes, dialogen aktiveres ikke, ingen ny knapp eller navigasjon, funksjonaliteten slettes ikke. Om ansvarlig-velgeren skal tas i bruk eller fjernes, er en produktbeslutning som vurderes senere. Dialogen er `ActionDialog` og vil være tilgjengelig hvis den tas i bruk. Blokkerer ikke Fase 4.

#### 18.14.3 AI-status og kunngjøringer

| Kontroll | Resultat |
|---|---|
| Én kunngjøring per overgang | `transitionAnnouncement` taler bare når tilstanden endres og aldri for første observasjon (enhet). Kravuttrekk: ferdig kunngjort én gang etter ekte polling (E2E). |
| Ingen gjentakelse ved polling | Minst to polling-runder (6,5 s) i «Behandles» uten noe i `polite`/`assertive` (E2E). `announce()` har i tillegg duplikatfilteret fra Fase 1. |
| Svaret leses ikke automatisk | Bare «Svarutkastet genereres …» og «Svarutkastet er klart.» kunngjøres; svarteksten rendres utenfor live-regionene (kode + E2E). |
| Ingen fokusflytting | Fokus blir på «Lag svar» gjennom hele genereringen og etterpå (E2E). |
| Feil | Generering: `role="alert"` i svarpanelet (E2E). Kopiering: `role="alert"`. Uttrekk: assertive kunngjøring, banneret er ikke live. Opplasting: fokus + beskrivelse, ikke i tillegg alert (rettelse 3). |
| Lagring og kopiering | «Svaret er lagret.» og «Svaret er kopiert.» (E2E); fokus tilbake til «Rediger» etter lagring. |
| Ingen dobling med live-regioner | Ingen nye live-regioner; alt går gjennom `announce()`. Det gamle uttrekksbanneret (`role="status"`/`aria-live`) er fjernet. Toasten brukes bare for Inertia-flash (Go/No-go), ikke for axios-kallene. |

AI-generering og polling er ikke endret.

#### 18.14.4 Go/No-go og dokumentopplasting

| Kontroll | Resultat |
|---|---|
| Valgt vurdering ikke bare farge | `aria-pressed` + hake-ikon (E2E). |
| Navngitte grupper | `role="group"` med punktets tittel, beskrevet av kortbeskrivelsen (E2E). |
| Flere filer | `FilePickerField multiple`: valgte filnavn vises og er feltets beskrivelse (E2E med én fil, enhet for `Array.from(files)`). Flere filer samtidig er ikke E2E-testet. |
| Fokus under opplasting | Feltet og knappen er `aria-disabled` mens filene lastes opp, og `submitDocuments` avviser nye innsendinger (kode + enhet). En ekte opplasting er ikke E2E-testet, fordi den starter kravuttrekk med AI. |
| Feil ved opplasting | Ugyldig filtype avvises av valideringen; feltet er `aria-invalid`, beskrevet av feilen, og får fokus (E2E). |
| Dokumentstatus | Tekst: «Venter på behandling», «Behandles», «Ferdigstiller krav», «Ferdig behandlet», «Feilet»; kravstatus separat. Uendret. |
| Knapper beholder fokus | «Lagre vurdering», «Lag svar», «Generer», «Kopier svar», «Lagre endring» (E2E for tre av dem, kode for resten). |

Vurderingsregler, filbehandling og parsing er ikke endret.

#### 18.14.5 Skjema, tabeller og dialoger

- **Feil ved felt og fokus etter avvist innsending:** E2E for privat forespørsel, No-Go-dialogen, AI-opplasting og manuelt krav; enhetstest for at alle Fase 4-skjema kaller fokusrutinen.
- **Gyldig HTML:** ingen nestede `label`, ingen InfoHint i `label`, én `main`, listeboksen inneholder bare `option` (enhet + lint + axe).
- **Dialoger:** No-Go-dialogen har navn og beskrivelse, bakgrunnen er `inert` mens den er åpen, og ingenting er `inert` etter Escape; fokus tilbake til utløseren (E2E, ny i sluttkontrollen).
- **Tabeller:** AI-oversikten har `caption`, `scope="col"`/`"row"` og `ScrollRegion` (kode + enhet). Ingen AI-saker i E2E-kundens oversikt ble axe-testet som tabell.
- **Rulleregioner:** vertikale regioner er navngitte og fokuserbare bare når de ruller (enhet); axe uten `scrollable-region-focusable` på svarpanelet (E2E).
- **Responsivt:** 320 og 640 px uten sidelengs rulling på saksvisningen og AI-arbeidsflaten med alt åpent (E2E).

#### 18.14.6 Testresultater (endelig kode)

| Test | Resultat |
|---|---|
| `npm run test:unit` (Node 22, Docker) | **1769 bestått, 0 feilet, 0 hoppet over** (`a11yPhase4.test.js`: 56 tester) |
| `npm run lint:a11y` (Node 22) | **0 feil, 0 advarsler** (56 filer) |
| `npm run build` | Bestått |
| PHP, målrettet (som 18.4) | **355 bestått, 0 feilet** (2504 påstander) |
| E2E `a11y-tenders-ai.spec.js` | **10 bestått, 0 feilet** (ny test for A11Y-146/-147; No-Go-testen kontrollerer nå beskrivelse og `inert`) |
| E2E samlet, 29 spesifikasjoner (196 tester) | Kjøring 4: **196 bestått, 0 feilet** (14,3 min). Se 18.14.6.1 for kjøring 3. |
| Full PHP- og E2E-suite | Ikke kjørt (merge gate) |

##### 18.14.6.1 Samlede kjøringer i sluttkontrollen, og hvorfor én feilet

| Kjøring | Kode | Resultat |
|---|---|---|
| 3 | Endelig kode, startet like etter `npm run build` | 186 bestått, 1 feilet, 9 ikke kjørt (15,9 min). Første test i `a11y-tenders-ai` (Go/No-go): «Execution context was destroyed, most likely because of a navigation» under første axe-kall. Resten av den serielle specen ble derfor ikke kjørt. |
| 4 | Endelig kode, uten nytt bygg rett før | **196 bestått, 0 feilet** (14,3 min) |

**Undersøkt årsak:** alle tre feilene i Fase 4-regresjonene (kjøring 1 i 18.5, kjøring 3 her og et eget forsøk) kom like etter `npm run build`, mens Vite dev-serveren var i gang (`public/hot`). Symptomene var en side som lastet på nytt av seg selv: innloggingen falt tilbake til `/login`, eller siden navigerte under et axe-kall. Uten bygg rett før var hver kjøring grønn: to samlede kjøringer, `a11y-navigation` + `a11y-tenders-ai` to ganger (18/18) og alle fire `a11y-*`-spesifikasjonene (34/34). Det eneste forsøket med bygg rett før feilet igjen, på samme måte. Manifest-hashen (Inertia-versjonen) var lik på vert og i container med en gang etter bygget, så en versjonskonflikt (409) er lite sannsynlig. Mest sannsynlig sender dev-serveren en forsinket full sideoppfrisking når `public/build` skrives. Den samme typen avbrudd er beskrevet i §17.5 ved kildeendringer under kjøring.

**Vurdering:** testmiljø, ikke Fase 4-kode. Ingen påstand i Fase 4 feilet, og ingen test er endret for å bli grønn. **Prosedyre videre:** ikke bygg rett før eller under en E2E-kjøring mot dev-serveren, eller kjør mot bygde ressurser uten `public/hot`. Dev-serveren tilhører utviklermiljøet og er ikke stoppet av agenten.

#### 18.14.7 OPPF-001

`a11y-navigation.spec.js:155` besto i alle fire samlede kjøringer av regresjonsutvalget i Fase 4 (kjøring 1 og 2 i 18.5, kjøring 3 og 4 her), også i kjøringene der en annen test feilet. To av dem (3 og 4) er på helt endelig Fase 4-kode. Ingen kode og ingen ventetid er endret for testen, og årsaken til de tidligere feilene i Fase 3 er ikke funnet. Isolerte kjøringer er ikke regnet med. Oppgaven er fortsatt **åpen**. Formelt er tre påfølgende grønne samlede kjøringer oppnådd, men utvalget og koden endret seg underveis, og årsaken er uavklart. Lukking bør besluttes etter en ny serie på tre samlede kjøringer på commit-koden.

#### 18.14.8 Åpne funn, manuell verifisering og risiko før Fase 5

- **Åpne funn fra Fase 4:** A11Y-145 (utsatt produktbeslutning). A11Y-105 og A11Y-121 er fortsatt delvise (18.6).
- **Manuell verifisering:** hele 18.9 gjenstår, i tillegg til §15.8.7, §16.8 og §17.8. Nytt punkt: med NVDA/VoiceOver, avvis en opplasting (feil filtype) og kontroller at feilen leses én gang.
- **Risiko før Fase 5:**
  - Fokusreglene i AI-arbeidsflaten og fristskjemaet avhenger av element-id-er; enhetstestene låser de viktigste.
  - `refocusIfLost({ always: true })` brukes bare ett sted; den skal ikke bli en generell fokusmekanisme.
  - Ekte opplasting av flere filer og livesøket mot Doffin/TED er ikke E2E-testet.
  - E2E kjører bare i Chromium; skjermleser, Safari og Firefox er ikke testet.

#### 18.14.9 Vurdering

Sluttkontrollen rettet to funn (A11Y-146 og den nye A11Y-147, en tom knapp som eksisterte før Fase 4) og én dobbel opplesing av opplastingsfeil. A11Y-145 er dokumentert som utsatt produktbeslutning. Ingen ruter, tilganger, validering, data, AI-logikk, polling eller filbehandling er endret. Enhetstester, lint, bygg, målrettet PHP og den nye E2E-specen er grønne, og den siste samlede regresjonen er grønn (196/196). Feilen i kjøring 3 er undersøkt og knyttet til bygg under kjørende dev-server. Fase 4 er teknisk klar for commit. Fasen er ikke manuelt verifisert og gir ikke grunnlag for å hevde at Procynia oppfyller WCAG 2.2 AA. OPPF-001 er åpen og må lukkes før merge til `main`.

---

## 19. Fase 5 – gjennomføringsrapport (2026-10-16)

Branch `feature/wcag-accessibility`, utgangspunkt `8240951e` (Fase 4), rent arbeidstre ved start. Begrepene er de samme som i §17 og §18: **Kode** = implementert, **Auto** = automatisk test i repoet, **Agent** = kontrollert av agenten i ekte nettleser (Playwright, Chromium), **Manuell** = kontrollert av en person. Ingen manuell verifisering er gjort. Fase 5 er ikke committet.

### 19.1 Omfang og arbeidsmåte

Kartlagt før endringene (alle filer lest i sin helhet):

| Område | Filer | Hva som finnes |
|---|---|---|
| Wiki-sider (oversikt, søk, filtre) | `Wiki/Index.jsx` (`PagesTab`) | Publiseringsoppsummering, søk, fem filtre (type, status, dokumenteier, kvalitetsfunn, sortering), tabell (desktop) og kort (mobil), paginering, polling hvert 5. s mens en kjøring pågår |
| Kildedokumenter | `Wiki/Index.jsx` (`SourcesTab`) | Opplasting ved filvalg + dialog «Kildedokumentet er lastet opp», dokumenteier, filtre, tabell med eier-velger, Last ned / Lag Wiki / Slett, sletteforhåndsvisning, «Vis beslutning» (sideplan) |
| Kjøringer | `Wiki/Index.jsx` (`RunsTab`, `RunListItem`, `RunTimeline`, paneler) | Statusfilter, kjøringsrader med aktivitet og tidslinje, «Avbryt kjøring», «Prøv sideplanleggingen på nytt», paneler for berørte sider og kvalitetsfunn (lokale filtre, detaljrader, dyplenke «Tilbake til funn») |
| Artikkelvisning | `Wiki/Show.jsx` | Tittel, type/status, handlingsmeny (Slett side), artikkel per innholdsblokk (Markdown, tabell- og bildeblokker, beste praksis-seksjoner), Lenket fra, Navigasjon |
| Redigering | `Wiki/Show.jsx` | «Rediger artikkel»: ett Markdown-felt per redigerbart avsnitt (ingen rikteksteditor, ingen forhåndsvisning – den rendrede artikkelen *er* visningen etter lagring); i tillegg blokkredigering fra et funn |
| Kvalitetssikring og lint | `Wiki/Show.jsx`, `Wiki/Index.jsx` | Verifikasjonsgrunnlag: arbeidsliste over påstander som krever behandling, påstandskort (Behold/Fjern teksten, kommentar, kildekobling, blokkering), verifiserte påstander, strukturelle kvalitetsfunn; funn per kjøring under Kjøringer |
| Gjennomgang og publisering | `Wiki/WikiReviewPanel.jsx` | Publiseringsstatus og neste steg, kvalitetssikrer (tildeling), Send til gjennomgang (velg kontrollør), Gjenåpne, Godkjenn og publiser, Send tilbake (begrunnelse min. 10 tegn), historikk over tilbakemeldinger |
| Kunnskapsgraf | `Wiki/Graph.jsx`, `Wiki/WikiGraphFocusView.jsx`, `Components/App/MultiSelectFilterDropdown.jsx` | Sigma-lerret (fullgraf og fokus), søk og filtre, nodepanel, relasjonspanel, hover-tooltip, Tilpass visning |
| Spør Wiki (AI) | `Wiki/Ask.jsx` | Ett spørsmål, svar med kildehenvisninger |
| Kunnskapsoverføring til Wiki (AI-kilde) | `Components/App/WikiKnowledgeHandoffPanel.jsx` | «Lag kunnskapsartikkel» fra Avvik, Risiko, Mål, Leverandør, Revisjon og Ledelsens gjennomgåelse |

Det finnes ingen egen kategoriside, ingen arkiveringshandling i Wiki-UI (statusen `archived` vises bare som merke), ingen egen revisjonshistorikkvisning (versjonsnummer og publisert/arbeidsversjon vises i gjennomgangspanelet) og ingen AI-genererte forslag som godtas eller avvises i Wiki-UI utover beste praksis-påstandene. Den eldre kvalitetsfanen er fjernet fra før og er ikke gjeninnført.

Endringene er gjort på presentasjonsnivå. Ingen statusmodell, lint-regler, kvalitetskriterier, kontrollørtildeling, godkjennings- eller publiseringsregler, versjonering, AI-prompter, modeller, grafdata, relasjonsberegning, layoutalgoritme, ruter, kontrollere, validering, tilganger, data eller migrasjoner er endret. Ingen nye statusverdier eller prosesstrinn. De store filene er ikke refaktorert.

### 19.2 Implementert

| Del | Hva | Filer | Funn | Status |
|---|---|---|---|---|
| A. Graf: listevisning | «Graf \| Liste» (navngitt gruppe, veksleknapper med `aria-pressed`, fokus blir på knappen). Listen viser de samme sidene og koblingene som lerretet (se 19.3). Fokusmodus har sin egen liste. | `Wiki/Graph.jsx`, `Wiki/WikiGraphListView.jsx` (ny), `Wiki/wikiGraphList.js` (ny), `Wiki/WikiGraphFocusView.jsx`, `Wiki/wikiGraphFocusList.js` (ny) | A11Y-050 | Kode + auto (enhet + E2E) |
| B. Graf: lerret og sidepanel | Lerretet er `role="img"` med navn («Kunnskapsgraf: N sider og M koblinger … velg Liste»). Sidepanelene har overskrifter (h2) og navn, nodepanelet viser status i ord («Status: OK»), lukkeknappene heter «Lukk sidedetaljer» / «Lukk relasjonen». Sidetyper og Status er `fieldset`-grupper. Tekst 11 px `slate-400` → 12 px `slate-600`; tall i oversikten `-700`. Avkrysningsrader minst 24 px høye (2.5.8). | `Wiki/Graph.jsx`, `Wiki/WikiGraphFocusView.jsx` | A11Y-016, -120, -150 | Kode + auto |
| C. Graf: resultat kunngjøres | Etter søk eller filter: «Viser 2 av 16 sider.» én gang, når skrivingen har stoppet (0,7 s), aldri ved første lasting. Null treff: «Ingen Wiki-sider matcher filtrene.» Tomtilstanden beholder `role="status"` (eksisterende test), men er `aria-live="off"`, så den ikke leses i tillegg. Fokusvisningen kunngjør resultat og feil. | `Wiki/Graph.jsx`, `Wiki/wikiGraphList.js`, `Wiki/wikiGraphFocusList.js` | A11Y-111, -152 | Kode + auto (enhet + E2E) |
| D. Filtervelger | Panelet er portalt til `<body>`: åpning flytter nå fokus inn (søkefeltet eller «Alle …»), Tab forbi siste og Shift+Tab før første lukker panelet og fortsetter fra utløseren. Etikett 12 px `slate-600`, feltkant `field-border`. | `Components/App/MultiSelectFilterDropdown.jsx` | A11Y-026, -016, -019 | Kode + auto (eksisterende E2E) |
| E. Artikkel: overskrifter | Markdown-overskrifter rendres ett nivå ned: `#` → h2, `##` → h3 … (h6 er gulvet). Sidens tittel er eneste h1. Typografien følger Markdown-nivået (`data-md-level`), så artikkelen ser ut som før. Lagret innhold og parser (react-markdown uten plugins) er uendret. | `Wiki/WikiMarkdown.jsx` (ny), `Wiki/wikiMarkdownHeadings.js` (ny), `Wiki/Show.jsx`, `resources/css/app.css` | A11Y-046 | Kode + auto (enhet + E2E) |
| F. Artikkel: øvrig | Kildetabeller navngis av «Tabell N» (`aria-labelledby`) og ruller i egen `ScrollRegion`. Lenkede sider er lister med sidetype i ord (`sr-only`). Lange ord og URL-er brytes, kodeblokker og bilder holdes innenfor artikkelen; lang tittel brytes ved 320 px. Sammenleggbare deler (Verifikasjonsgrunnlag, Verifiserte påstander, Kvalitetsfunn) er overskrifter med `aria-expanded`/`aria-controls`. Handlingsmenyen er en disclosure (ikke `role="menu"`). Tilbake-lenken og grå tekst på sidebakgrunnen `slate-600`. | `Wiki/Show.jsx`, `resources/css/app.css` | A11Y-113, -028, -103, -016, -148, -149 | Kode + auto |
| G. Redigering | Feltene heter «Rediger avsnitt N av M» og beskrives av en Markdown-hjelpetekst. «Rediger artikkel» flytter fokus til første felt; Avbryt og vellykket lagring gir fokus tilbake. Avvist lagring (f.eks. tomt avsnitt) markerer *det* feltet (`aria-invalid`), kobler feilen til det og flytter fokus dit (`useFocusFirstError`); en feil uten felt får fokus selv. Under lagring er feltene `readOnly` og knappene `aria-disabled`, så fokus beholdes. Kant `field-border`, «Lagre» `sky-700`. Blokkredigeringen fra et funn: `autoFocus` erstattet med fokus ved åpning/lukking, feilen er `role="alert"` og koblet til feltet. | `Wiki/Show.jsx`, `Wiki/wikiShowA11y.js` (ny) | A11Y-049, -013, -019, -017 | Kode + auto (enhet + E2E) |
| H. Påstander | `preserveScroll` på alle påstandsbeslutninger; når kortet forsvinner fra arbeidslisten, går fokus til neste påstand som krever behandling (så selve påstanden, så overskriften) – bare hvis fokus faktisk gikk tapt. Kortet er navngitt («Påstand 1 av 2»), knappene beskrives av påstanden og er `aria-disabled` mens de lagres. Kommentarfeltet har etikett. Blokkeringsvalget og valgt kildeelement er `aria-pressed`. `emerald/sky-600` → `-700`. | `Wiki/Show.jsx`, `Wiki/wikiShowA11y.js` | A11Y-014, -047, -117, -017 | Kode + auto (enhet + E2E) |
| I. Gjennomgang og publisering | Panelet er en navngitt, fokuserbar seksjon med egen h2 («Gjennomgang og publisering») over h3-blokkene. Dialogene har beskrivelse og faller tilbake til panelet når utløseren er borte (etter innsending/publisering). Etter en handling går fokus til panelet; etter en avvisning til feltet i dialogen. Minstelengden for «Send tilbake» står i hjelpeteksten («Minst 10 tegn.»), feltet er `aria-required`. Regler og betingelser er uendret. | `Wiki/WikiReviewPanel.jsx` | A11Y-002, -014, -118 | Kode + auto (enhet + E2E) |
| J. Wiki-sider | Søk med etikett (søkelandemerke), alle fem filtre navngitt, antall sider kunngjøres når filtrene endres (ikke ved polling), tabell med `caption` og `scope`, «Åpne» heter «Åpne <tittel>», rullebokser som navngitte regioner (`ScrollRegion axis="y"/"both"`), paginering som navngitt `nav`. | `Wiki/Index.jsx`, `Components/App/ScrollRegion.jsx` | A11Y-048, -108, -109, -110, -111 | Kode + auto (E2E) |
| K. Kildedokumenter | Opplastingsfeltets fokusring er gjenopprettet (de tomme `peer-`-klassene), feltet beskrives av valgt fil, formater og feil; feilen er `role="alert"`; fokus tilbake til feltet etter avvist opplasting og etter dialogen. Søk og filtre navngitt, søkeknappen har navn. Radhandlingene beskrives av filnavnet, eier-velgeren heter «Dokumenteier for <fil>», første celle er `th scope="row"`. Sletting og sideplan er `ActionDialog` (tittel, beskrivelse, Avbryt får fokus, Escape, felle, fokusretur). | `Wiki/Index.jsx` | A11Y-002, -011, -048, -109 | Kode + auto |
| L. Kjøringer | Radene er ikke lenger live-regioner (de leste opp ved hver polling); en statusendring kunngjøres én gang («Kjøring for Rutiner.docx: Genererer sider.», feil assertive). Tidslinjen er en `ol` der hvert steg sier «fullført/pågår/venter/stoppet/gjenstår» og nåværende steg er `aria-current="step"`; den ruller i en navngitt region. Avbryt og Prøv på nytt er `ActionDialog`. Filtre navngitt; «Vis N berørte Wiki-sider»; funnfiltrene er `aria-pressed` i en navngitt gruppe; detaljknappen nevner funnet; dyplenke til et funn flytter fokus dit. | `Wiki/Index.jsx`, `Wiki/wikiRunAnnouncements.js` (ny) | A11Y-002, -024, -112, -117, -015, -151 | Kode + auto |
| M. Spør Wiki | Én h1 (layoutens tittel av). Spørsmålet via `FormField`, avvist innsending → fokus på feltet. «Søker i Wiki-en …» og svartypen («Svaret er klart.») kunngjøres; svarteksten leses ikke opp og fokus flyttes ikke. Knappen er `aria-disabled` mens det søkes. | `Wiki/Ask.jsx`, `Wiki/wikiAskStatus.js` (ny) | A11Y-042, -013 | Kode + auto |
| N. Kunnskapsoverføring | Tittel og læringspunkter via `FormField` (påkrevd), seksjonene som `FieldsetField` (hver avkrysning navngitt av overskriften og beskrevet av linjene), «Felt merket med * …», fokus til første feil, `aria-disabled` under innsending. | `Components/App/WikiKnowledgeHandoffPanel.jsx` | A11Y-013, -121 | Kode + auto (eksisterende E2E) |
| i18n | 51 nye nøkler i `procynia.a11y.*` (`wiki_*`), no + en. | `lang/{no,en}/procynia.php` | — | Auto |
| Lint | `lint:a11y` dekker 10 nye filer (66 totalt). Ingen regel er slått av. De 6 opprinnelige funnene (overlegg med klikk uten tastatur, `autoFocus`, etikett uten tekst, to ukjente `eslint-disable`-direktiver) er rettet. | `eslint.config.js` | — | Auto |

### 19.3 Grafens tilgjengelige alternativ

Listevisningen er ikke en beskrivelse av grafen, men grafen i lesbar rekkefølge:

- **Data:** radene bygges av `displayed` – det samme utvalget lerretet tegner etter søk og alle filtre (`buildGraphListRows(displayed.nodes, displayed.edges)`). Ingen ny forespørsel (`Graph.jsx` gjør fortsatt bare `fetch(fetchUrl)`), ingen egen filtrering, ingen ny datamodell. En relasjon er et par viste sider med minst én vist kobling, med samme nøkkel som en klikket linje (`relationPairKey`).
- **Per side:** tittel som lenke (åpner siden med grafen som returkontekst, `articleHrefFromGraph`), sidetype og lint-status i ord, antall påstander og funn, «Vis detaljer» (samme nodepanel som et klikk på en prikk, fokus flyttes inn, lukking gir fokus tilbake), «Fokuser på denne siden» (samme overgang til fokusmodus), og koblingene: «Lenker til / Lenkes fra / Gjensidig lenket med <side>» med lenke til den relaterte siden og «Hvorfor er de koblet?» (samme relasjonspanel som et klikk på linjen).
- **Fokusmodus:** samme svar fra `/graph-focus`, gruppert som «Fokusside», «1 hopp unna», «2 hopp unna», med «Åpne» (tittelen) og «Fokuser på denne siden».
- **Bytte:** «Graf | Liste» virker i begge moduser; fokus blir på knappen, og Sigma-lerretet beholdes skjult (layout og kamera er der når man bytter tilbake).

Lerretet er ikke gjort til en skjermleserbasert grafeditor; det er et navngitt bilde som peker til listen.

### 19.4 Funksjonell sammenligning: graf og liste

| Oppgave | Graf (peker) | Liste (tastatur/skjermleser) | Verifisert |
|---|---|---|---|
| Finne sider | Søk og filtre | De samme kontrollene; listen følger | E2E (søk, status, sidetyper) |
| Se hvor mange som vises | Grafoversikt | Oppsummering + kunngjøring | E2E (antall i liste = Grafoversikt = lerretets navn) |
| Velge side og se detaljer | Klikk på prikk → nodepanel | «Vis detaljer» → samme panel, fokus inn/ut | E2E |
| Åpne side | «Åpne side» i panelet | Tittellenken (eller panelet) | E2E (relatert side åpnet med «Tilbake til Grafvisning») |
| Se relasjoner | Linjer, hover-tooltip | «Koblinger (N)» med retning i ord | Enhet (samme par som lerretet) + E2E |
| Hvorfor er de koblet | Klikk på linje → relasjonspanel | «Hvorfor er de koblet?» → samme panel | E2E |
| Gå til relatert side | Klikk i relasjonspanelet | Lenke i koblingslisten | E2E |
| Fokusere på en side | «Fokuser på denne siden» | Samme knapp i raden | E2E (fokuslisten) |
| Fokusvisning | Lerret | Liste gruppert på hopp | E2E (med projisert fixture) |
| Tilpass visning | Kamera | Ikke relevant (ingen kamera i listen) | — |

Konklusjon: alle oppgaver grafen tilbyr, kan gjøres i listen, på samme datagrunnlag. Hover-tooltipen er det eneste som bare finnes for peker; innholdet står i relasjonspanelet (se A11Y-153).

### 19.5 Artikkelvisning og Markdown

- **Dobbel h1 (A11Y-046):** bekreftet i data – 44 av 51 sideversjoner med blokker starter med `# {tittel}` (ingest skriver det). Rettet ved rendering, ikke i innholdet.
- **GFM-tabeller (A11Y-114):** de tre versjonene med tabellsyntaks rendres som strukturerte tabellblokker (`WikiTableBlock`), ikke som Markdown. Det finnes altså ikke et dokumentert behov for `remark-gfm`; parseren er uendret.
- **Bilder:** alternativ tekst kommer fra Word-kildens egen beskrivelse; uten den blir bildet `alt=""` med figurtekst. Innholdsspørsmål, ikke komponentfeil (A11Y-154). Ingen bildeblokker i utviklingsdataene.
- **Lister, lenker, sitater:** rendres som semantisk HTML av react-markdown; interne lenker går via Inertia. Ingen endring.

### 19.6 Tester og resultater

Resultatene er fra kjøringer på endelig kode.

| Test | Resultat |
|---|---|
| `npm run test:unit` (Node 22, Docker) | **1826 bestått, 0 feilet, 0 hoppet over.** Ny: `resources/js/Support/a11yPhase5.test.js` (57 tester: listevisningens rader og relasjoner = lerretets par, retninger, filtrert side tar koblingene med seg, ingen ekstra forespørsel, samme paneler, fokus inn/ut av panelene, navngitte grupper og lerret, kunngjøring av resultat og tomt resultat, fokuslisten og kunngjøringene, overskriftsforskyvning, `WikiMarkdown` overalt, typografi per nivå, ingen parser-endring, reflow-CSS, tabellnavn, fokusmål etter påstandsbeslutning, `preserveScroll`, `aria-disabled`/`aria-pressed`, kommentaretikett, redigeringsfeltenes navn, feil koblet til riktig avsnitt, ingen `autoFocus`, disclosure i stedet for meny, gjennomgangspanelets navn/beskrivelser/fokusretur/minstelengde, ingen egne modaler i Index, navngitte filtre, statusoverganger for kjøringer, tidslinje i ord, opplastingsfeltet, Spør Wiki, filtervelgeren, `ScrollRegion axis="both"`, kunnskapsoverføringen, alle nye nøkler i begge språk). |
| `npm run lint:a11y` (Node 22) | **0 feil, 0 advarsler** (66 filer). |
| `npm run build` | Bestått. |
| PHP, målrettet (filter på `WikiSourceRowLayoutTest`, `WikiSourcesDocumentOwnerFilterTest`, `WikiQualityTabRemovalTest`, `WikiRunsTabPollingRefreshTest`, `WikiPageHelpTest`, `WikiAskControllerTest`, `WikiGraphControllerTest`, `A11yTranslationsTest`, `SharedTranslationsLocaleTest`, `WikiFindingDeepLinkTest`, `WikiClaimDefectReviewReferenceNavigationTest`, `WikiControllerTest`, `WikiReviewAssignmentTest`, `WikiClaimControllerTest`, `WikiWorkingVersionEditTest`, `KnowledgeHandoff*`; de navnene som finnes) | **457 bestått, 1 feilet** (2192 påstander). Feilen er eksisterende, se 19.7. |
| Pint (fixture, språkfilene) | Bestått. |
| E2E `a11y-wiki.spec.js` (ny, 12 tester, egen fixture `Tests\Support\A11yWikiE2EFixture` med egen kunde) | **12 bestått, 0 feilet.** Wiki-sider (navngitte filtre, søk, kunngjort antall, fokus beholdes), graf → liste (veksleknapp, fokus, søk kunngjort, detaljer med fokus inn/ut, relasjon, åpne relatert side), likhet graf/liste under filtre, fokusliste (projisert), artikkel (én h1, h2/h3 fra Markdown), to påstander besluttet med tastatur (fokus til neste påstand, så overskriften; rulleposisjon beholdt), redigering (navn, hjelpetekst, avvist tomt avsnitt → fokus/`aria-invalid`/beskrivelse, Avbryt → fokus tilbake), send til gjennomgang med tastatur (dialog, beskrivelse, toast, fokus på panelet, status), send tilbake som kontrollør (minstelengde, deaktivert til den er nådd, toast, fokus, «Endringer kreves»), kilder og kjøringer, Spør Wiki, reflow 320/640 px. axe uten brudd på alle disse flatene og i begge dialogene. Ingen AI-kall. |
| E2E Wiki-relatert (16 spesifikasjoner, 124 tester) | Første kjøring: 123 bestått, 1 feilet, 0 ikke kjørt (`knowledge-handoff`: den nye «Felt merket med * …» var 14 px – rettet til 16 px; specen besto deretter). |
| E2E samlet, 41 spesifikasjoner (256 tester) | Tre kjøringer: 255/1/0, 255/1/0 og 256/0/0 (bestått/feilet/ikke kjørt), se 19.7. |
| Full PHP- og E2E-suite | Ikke kjørt (merge gate). |

### 19.7 Regresjon og eksisterende feil

Utvalget er de 29 spesifikasjonene fra §18.5 pluss `a11y-wiki` og de 11 øvrige Wiki-relaterte (`wiki-best-practice`, `wiki-graph-focus`, `wiki-graph-labels`, `wiki-runs-readability`, `wiki-runs-stalled-indicator`, `wiki-source-upload-readability`, `wiki-tab-preservation`, `wiki-word-image`, `wiki-word-image-includepicture`, `wiki-word-table`, `supplier-wiki-guidance`): 41 spesifikasjoner, 256 tester, Chromium, endelig kode, Vite dev-server i gang, ingen kildeendringer under kjøringene.

| Kjøring | Resultat | Merknad |
|---|---|---|
| 1 | **255 bestått, 1 feilet, 0 ikke kjørt** av 256 (20,7 min) | Feilen: `wiki-graph-labels.spec.js:82`, eksisterende ustabil test (under). |
| 2 | **255 bestått, 1 feilet, 0 ikke kjørt** av 256 (18,8 min) | Samme test. |
| 3 | **256 bestått, 0 feilet, 0 ikke kjørt** av 256 (20,3 min) | Ingen feil i denne kjøringen. |

Mellom kjøring 1 og 2 ble to filer midlertidig byttet til HEAD-versjonen for baseline-kontrollen under og deretter gjenopprettet byte for byte (kontrollsum verifisert); koden var identisk i alle tre kjøringene.

- **`wiki-graph-labels.spec.js:82` (eksisterende, ustabil):** testen klikker 25 px til venstre for den venstreste tegnede etikettpikselen på et lerret med tilfeldig startlayout (`Math.random()`), og treffer derfor ikke alltid en node. Isolert med Fase 5-koden: 1 av 6 feilet. Med HEAD-versjonen av `Graph.jsx` og `MultiSelectFilterDropdown.jsx` lagt inn midlertidig: **2 av 6 feilet** (filene ble deretter gjenopprettet, kontrollsum verifisert). Feilen skyldes testen, ikke Fase 5. Registrert som OPPF-002; ikke endret her (ingen svekking).
- **`WikiQualityTabRemovalTest::test_the_wiki_navigation_no_longer_offers_a_quality_tab` (eksisterende):** leser `CustomerAppLayout.jsx` og forventer `wiki-pages`/`wiki-sources`/… som ikke finnes der på HEAD (`git show HEAD:… | grep` = 0; Wiki-navigasjonen ligger i dag et annet sted). Fase 5 har ikke rørt filen. Bekreftet feilende i en ren HEAD-worktree.
- **Én eksisterende test er tilpasset:** `wikiBackLinkStyle.test.js` låste fargen på «Tilbake til Wiki» til `text-slate-500`, som er 4,45:1 mot sidebakgrunnen `#f6f7fb` (under 4,5:1). Lenken er nå `slate-600` (7,08:1), og testen krever `text-slate-600` med samme styrke (A11Y-148). Ingen andre tester er endret; øvrige kildetester som låser markup (`wikiReviewPanel`, `wikiSourceUpload`, `WikiSourceRowLayoutTest`, `wikiGraph*`, `wikiListLayout` m.fl.) er oppfylt ved å tilpasse koden.

- **OPPF-001:** `a11y-navigation.spec.js:155` besto i alle tre samlede kjøringer på rad, på uendret kode, uten endret ventetid eller svekkede påstander. Akseptansekriteriet i §17.12.9 er dermed oppfylt. Årsaken til de tidligere feilene er fortsatt ikke funnet (sannsynligvis testmiljø, jf. §18.14.6.1). Anbefaling: lukk OPPF-001 i sluttkontrollen av Fase 5; koden er ikke committet ennå, så en ny serie på commit-koden kan kreves hvis man ønsker det.

### 19.8 Funnstatus etter Fase 5

Lukket betyr: utbedret og verifisert automatisk innenfor testomfanget i 19.6, på Wiki-flatene. Ingen er manuelt verifisert.

| ID | Status |
|---|---|
| A11Y-002 | Lukket for Wiki: sideplan, sletting av kilde, avbryt kjøring og prøv på nytt er `ActionDialog` (auto). Med Fase 3–4 er alle 11 dialogene fra Fase 0 migrert; `fixed inset-0` finnes ikke lenger i Wiki. |
| A11Y-011 | Delvis for Wiki: fokusring, beskrivelse, `aria-invalid`, alert og fokusretur er på plass (auto). Feltet er fortsatt `disabled` under opplasting (låst av eksisterende test `wikiSourceUpload`), så fokus faller midlertidig; det settes tilbake når opplastingen er ferdig eller avvist (A11Y-156). Gjenstår: `SupplierManagement/Import.jsx` (Fase 6). |
| A11Y-013 | Lukket for Wiki-skjemaene (redigering, Spør Wiki, kunnskapsoverføring, gjennomgangsdialogene). GRC gjenstår (Fase 6). |
| A11Y-014 | Lukket for påstandsbeslutninger, redigering, gjennomgang og graf-paneler (auto). Se A11Y-156 for de gjenværende `disabled`-knappene. |
| A11Y-015 | Lukket for dyplenken til et funn under Kjøringer (fokus til raden). Gjenstår: `SupplierManagement/Show.jsx`, `Compliance/Audits/Show.jsx` (Fase 6). |
| A11Y-016, -019 | Lukket i Wiki-filene (axe på alle testede flater). |
| A11Y-017 | Lukket i Wiki (`emerald/sky/amber/rose-600` med hvit tekst → `-700`). `GoNoGo`-delene ble rettet tidligere. |
| A11Y-024 | Lukket for Wiki-tidslinjen (auto). Ledelsens gjennomgåelse gjenstår (Fase 6). |
| A11Y-026 | Lukket (auto via eksisterende E2E for filtervelgeren + enhet). |
| A11Y-028 | Lukket for Wiki (verifikasjonsseksjonene, tekniske detaljer, funndetaljer, handlingsmenyen). |
| A11Y-042 | Lukket for `Wiki/Ask.jsx` (auto). |
| A11Y-046 | Lukket (auto). |
| A11Y-047, -048, -049 | Lukket (auto). |
| A11Y-050 | Lukket med listevisning som funksjonelt likeverdig alternativ (19.3, 19.4). Lerretet selv er fortsatt bare for peker, med vilje. |
| A11Y-103 | Lukket for Wiki-sidens handlingsmeny. |
| A11Y-108 – -110 | Lukket for Wiki-tabellene og -rulleboksene. |
| A11Y-111 | Lukket for Wiki-sider, grafen og fokusvisningen. |
| A11Y-112 | Lukket for Wiki-kjøringene (auto). |
| A11Y-113 | Lukket for Wiki-tabellene. `ManagementReview/Report.jsx` gjenstår (Fase 6). |
| A11Y-114 | Avsluttet uten endring: ingen GFM-tabeller når Markdown-rendereren i dagens data (19.5). Tas opp igjen hvis en generator begynner å skrive dem. |
| A11Y-117 | Lukket (funnfiltre, blokkeringsvalg, kildeelement). |
| A11Y-118 | Lukket (auto). |
| A11Y-120 | Lukket for grafens filtergrupper. GRC gjenstår (Fase 6). |
| A11Y-121 | Delvis: kunnskapsoverføringen og «Send tilbake» merket. GRC (Fase 6). |
| A11Y-105 | Uendret delvis: hardkodet norsk står igjen i deler av Wiki (blokkredigeringens knapper og meldinger, «Oppdater», «I kø siden …», mobiletikettene i Kjøringer, «Fjern kildefilter», feilteksten i sletteforhåndsvisningen, tabellhodene i Wiki-sider). Etter prosjektregelen oversettes eksisterende tekst når det bestilles. |

### 19.9 Nye funn

| ID | Funn | WCAG | Status |
|---|---|---|---|
| A11Y-148 | «Tilbake til Wiki» `slate-500` på sidebakgrunnen: 4,45:1. | 1.4.3 | Rettet (`slate-600`, 7,08:1). |
| A11Y-149 | Lang ettordstittel («Leverandørsikkerhet», 36 px) presset artikkelsiden 12 px for bred ved 320 px. | 1.4.10 | Rettet (tittelen kan brytes). |
| A11Y-150 | Grafens avkrysningsbokser (14 px) for tett (axe `target-size`). | 2.5.8 | Rettet (rader minst 24 px). |
| A11Y-151 | Kjøringens tidslinje rullet sideveis uten å kunne nås med tastatur (axe `scrollable-region-focusable`). | 2.1.1 | Rettet (`ScrollRegion`). |
| A11Y-152 | Grafens resultat ble aldri kunngjort; tomtilstanden var en live-region som monteres med teksten. | 4.1.3 | Rettet. |
| A11Y-153 | Grafens hover-tooltip kan ikke lukkes med Escape eller holdes ved å peke på den (`pointer-events-none`). Innholdet finnes i relasjonspanelet og i listen. | 1.4.13 | Åpen, P2. Forslag: Escape skjuler tooltipen; lav risiko, berører bare `EdgeTooltip`/`leaveEdge`. |
| A11Y-154 | Bilder uten alternativ tekst i Word-kilden vises med `alt=""` og figurtekst. | 1.1.1 | Innhold, ikke komponent. Oppfølging: vurdere advarsel i kvalitetskontrollen for bildeblokker uten beskrivelse. |
| A11Y-155 | Wiki-tabellens kolonnehoder «Tittel», «Type», «Status» og flere tekster er hardkodet norsk (se A11Y-105). | 3.1.2 | Informasjon. |
| A11Y-156 | Tre knapper/felt er fortsatt `disabled` mens en forespørsel går (opplastingsfeltet, dialogknappene i gjennomgangspanelet, blokkredigeringens Lagre), fordi eksisterende kildetester låser `disabled`. Fokus faller under forespørselen og settes tilbake etterpå (`refocusIfLost`, `fallbackFocusRef`). | 2.4.3 | Delvis. Forslag: endre til `aria-disabled` + vakt sammen med de låsende testene, som en egen, avtalt endring. |

### 19.10 Synlige endringer

- **Graf:** ny «Graf \| Liste»-bryter ved modusbryteren; listevisningen er ny. Etikettene i sidepanelet er 12 px og mørkere grå (var 11 px lys grå); tellerne for feil/advarsler er en nyanse mørkere; avkrysningsradene litt høyere. Nodepanelet viser «Status: OK» i stedet for «ok».
- **Artikkel:** overskriftene ser like ut. Tilbake-lenken og grå hjelpetekster er en nyanse mørkere. Kommentarfeltet ved en påstand har en synlig etikett «Kommentar (valgfritt)». Grønne/blå knapper er én nyanse mørkere. Det valgte blokkeringsvalget vises i full farge (var nedtonet som deaktivert). Lang tittel brytes på smal skjerm.
- **Redigering:** hjelpetekst om Markdown øverst i redigeringsmodus; feltkantene tydeligere.
- **Gjennomgang:** «Minst 10 tegn.» i hjelpeteksten for «Send tilbake».
- **Kildedokumenter og Kjøringer:** dialogene for sletting, sideplan, avbryt og prøv på nytt har standard dialogutforming (sideplanen med tittellinje og lukkeknapp); røde/oransje knapper én nyanse mørkere; gråtekster mørkere.
- **Kunnskapsoverføring:** «Felt merket med * må fylles ut.» over feltene.
- **Uendret:** ruter, handlinger, rekkefølge, statuser, polling, grafdata og layout, AI-svar og alle data.

### 19.11 Nettleserkontroll (agent)

Kontrollert i Chromium (Playwright) på `localhost:8080` med Vite dev-server:

- **Bredder:** 320 px og 640 px (E2E) for Wiki-sider, Kildedokumenter, Kjøringer, artikkel, Spør Wiki og grafens liste; 1440 px i alle testene. Ingen sidelengs rulling av siden; tidslinje og brede tabeller ruller i egne, navngitte regioner. 320 px dekker 400 % av 1280 px, 640 px dekker 200 %.
- **Tastatur:** filtervelgeren (inn/ut av panelet), graf ↔ liste, listens detaljer og relasjoner, påstandsbeslutninger, redigering, gjennomgangsdialogene – fokus synlig og aldri tapt i de testede flytene.
- **axe:** ingen brudd på Wiki-sider, Kildedokumenter, Kjøringer, artikkel (lesing, redigering, redigering med feil), graf, liste med sidepanel, fokusliste, begge gjennomgangsdialogene og Spør Wiki.
- **Ikke kontrollert:** nettleserens egen zoom, Firefox, Safari, forced-colors, skjermleser.

### 19.12 Manuell testing som gjenstår

I tillegg til §15.8.7, §16.8, §17.8 og §18.9:

1. **NVDA + Firefox/Chrome og VoiceOver + Safari:**
   1. Grafens liste: «Graf/Liste» leses som veksleknapper; listen kan gjennomgås med overskriftsnavigasjon (h3 per side); «Vis detaljer» flytter til panelet og leser sidens navn; antall kunngjøres én gang etter søk.
   2. Artikkel: overskriftslisten viser én h1 og artikkelens egne seksjoner under.
   3. Påstander: kortet leses med «Påstand N av M», knappene med påstandens tekst; etter «Behold teksten» står man på neste påstand, og toasten leses.
   4. Redigering: «Rediger avsnitt 2 av 5» og Markdown-hjelpen leses; avvist lagring leser feilen ved feltet én gang.
   5. Gjennomgang: dialogene leser tittel og beskrivelse; etter innsending leses panelet («Gjennomgang og publisering») og toasten.
   6. Kjøringer: en kjøring som går til et nytt steg leses én gang, ikke hvert 5. sekund.
2. **Ekte Safari:** at filtervelgerens panel tar fokus og Tab ut fortsetter riktig; at `aria-disabled`-knappene beholder fokus.
3. **Zoom 200/400 %** med nettleserens egen zoom på grafen og artikkelen.
4. **Visuelt:** listevisningen i grafen og den endrede blokkeringsknappen (19.10).
5. **Akseptansekriteriet i §8 Fase 5:** en QA-bruker (ikke System Owner) godkjenner alle påstander med bare tastatur – E2E er kjørt som System Owner.

### 19.13 Kjente risikoer og begrensninger

- Fokusmålene etter påstandsbeslutninger og i redigering bygger på element-id-er (`claim-<id>`, `wiki-article-edit-<blokk>`, `wiki-verification-toggle`, `wiki-review-panel`); enhetstestene låser de viktigste.
- Feilen ved avvist lagring kobles til avsnittet via rekkefølgen i forespørselen (`blocks.N.markdown`); endrer serveren navngivingen, faller koblingen tilbake til oppsummeringen (som da får fokus).
- Fokusvisningens liste krever at grafprojeksjonen er på; uten den vises og kunngjøres «ikke tilgjengelig», som før.
- `wiki-graph-labels.spec.js:82` er ustabil uavhengig av Fase 5 (OPPF-002).
- E2E kjører bare i Chromium; påstandsflyten er testet som System Owner, ikke som QA-bruker.

### 19.14 Oppfølgingsoppgaver

- **OPPF-001:** lukket i sluttkontrollen (19.17).
- **OPPF-002 (ny):** `wiki-graph-labels.spec.js:82` treffer ikke alltid en node fordi grafens startlayout er tilfeldig (2 av 6 feilet på HEAD). Forslag: finn nodeposisjonen via Sigma (`getNodeDisplayData`) i stedet for etikettpiksler, eller bruk listevisningens «Vis detaljer» for panelkontrollen.
- **A11Y-153, -154, -156:** se 19.9.
- **A11Y-105:** oversettelse av eksisterende hardkodet norsk i Wiki når det bestilles.
- **OPPF-003 (ny):** eksisterende PHP-feil `WikiQualityTabRemovalTest::test_the_wiki_navigation_no_longer_offers_a_quality_tab` (19.17).

### 19.15 Anbefalinger for Fase 6

1. Prosessdiagrammet (A11Y-004) kan følge samme mønster som grafen: en steglisteversjon av de samme dataene, med de samme handlingene, i stedet for å gjøre SVG-en til en full tastaturflate.
2. Gjenbruk `useRunStatusAnnouncements`-mønsteret (én overgang, én kunngjøring) for GRC-flater som oppdateres.
3. Sjekk sidebakgrunnen (`#f6f7fb`) for all grå tekst: `slate-500` holder ikke der (A11Y-148).
4. Avklar de låste `disabled`-knappene (A11Y-156) før lignende mønstre kopieres til GRC.
5. Kontroller lange ettordstitler ved 320 px tidlig (A11Y-149).

### 19.16 Vurdering

Alle Fase 5-funnene fra planen er rettet eller dokumentert med begrunnelse: A11Y-002, -026, -042, -046, -047, -048, -049, -050, -112, -117, -118 og Wiki-delene av -011 (delvis), -013, -014, -015, -016, -017, -019, -024, -028, -103, -108–-111, -113, -120, -121; A11Y-114 er avsluttet uten endring. Ni nye funn er registrert (A11Y-148 til -156): fem rettet, ett åpent (A11Y-153), ett innholdsfunn, én informasjon og én delvis. Kunnskapsgrafen har en funksjonelt likeverdig listevisning på samme data, verifisert med enhetstester og E2E. Ingen forretningslogikk, review- eller publiseringsregler, lint-motor, AI, grafdata, ruter, tilganger, data eller migrasjoner er endret. Enhetstester, lint, bygg og den nye E2E-specen er grønne; målrettet PHP og samlet E2E har bare eksisterende feil (19.7). Fase 5 er etter agentens vurdering teknisk klar for sluttkontroll, men er **ikke** sluttkontrollert, ikke manuelt verifisert med skjermleser, ekte Safari/Firefox, nettleserzoom eller Windows-høykontrast, og gir ikke grunnlag for å hevde at Procynia oppfyller WCAG 2.2 AA. Full E2E- og PHP-suite er merge gate og er ikke kjørt.

### 19.17 Sluttkontroll av Fase 5 (2026-10-16)

Kontroll på Fase 5-koden før commit. Branch `feature/wcag-accessibility`, HEAD `8240951e`. Ingen kodeendringer er gjort i sluttkontrollen; resultatene i 19.6–19.7 er fra siste kodeversjon, og testene er derfor ikke kjørt på nytt.

#### 19.17.1 Git

- 26 filer, alle Fase 5: 15 endrede (de seks Wiki-sidene, tre felleskomponenter, `app.css`, språkfilene, `eslint.config.js`, denne planen og `wikiBackLinkStyle.test.js`) og 11 nye (`WikiGraphListView.jsx`, `WikiMarkdown.jsx`, sju hjelpemoduler, `a11yPhase5.test.js`, `A11yWikiE2EFixture.php`, `a11y-wiki.spec.js`).
- Ingen PHP-kode i `app/`, ingen ruter, kontrollere, migrasjoner eller konfigurasjon. Ignorerte lokale artefakter (`public/hot`, `public/build`, `test-results/`) er ikke med. `git diff --check` uten funn.

#### 19.17.2 Wiki-funksjonaliteten

| Kontroll | Resultat |
|---|---|
| Listealternativet bygger på samme data og tilgang som grafen | Ja. Radene bygges av grafens eget `displayed` (samme svar fra `/app/wiki/graph-data`, som allerede er avgrenset til det brukeren får se); ingen ny forespørsel, filtrering eller datamodell (enhet + E2E, 19.3–19.4). |
| Finne, åpne og navigere mellom relaterte artikler med tastatur | Ja (E2E: søk, «Vis detaljer», «Hvorfor er de koblet?», lenke til relatert side med «Tilbake til Grafvisning»). |
| Overskriftsstruktur i artikkelen | Én h1 (sidens tittel); Markdown `#` → h2, `##` → h3 (E2E). |
| Redigering og kvalitetssikring | Fungerer: lagring/avvisning/Avbryt og påstandsbeslutninger (E2E). |
| Review, kontrollørtildeling, godkjenning og publisering | Uendret. Diffen inneholder ingen endring i betingelser (`can_submit`, `can_send_back`, `can_approve_final`, `MIN_REASON`) eller i forespørslene (`/submit`, `/approve`, `/reject`, `/qa-assignment`); bare navn, beskrivelser, fokus og hjelpetekst. Send til gjennomgang og Send tilbake kjørt i E2E. |
| Dialogene | Alle bruker `ActionDialog` (felle, Escape, `inert`, fokusretur, `descriptionId`, `fallbackFocusRef`). |
| Ingen gammel Wiki-prosess gjeninnført | Bekreftet: ingen kvalitetsfane, ingen direkte publisering av utkast, ingen nye statuser eller trinn. |

#### 19.17.3 Oppfølgingsoppgaver

- **OPPF-001 – lukket.** `a11y-navigation.spec.js:155` besto i tre påfølgende samlede regresjonskjøringer (19.7) på uendret kode, uten endret ventetid og uten svekkede påstander. Akseptansekriteriet i §17.12.9 er oppfylt. Den opprinnelige årsaken til feilene i Fase 3 er ikke endelig identifisert (sannsynligvis testmiljø, jf. §18.14.6.1). Dukker feilen opp igjen, gjenåpnes oppgaven.
- **OPPF-002 – åpen, må løses før merge til `main`.** `wiki-graph-labels.spec.js:82` («long node titles are handled …») feilet i samlet kjøring 1 og 2, men ikke i 3. Isolert, 6 gjentakelser: Fase 5-koden 1 av 6 feilet; med HEAD-versjonen av `Graph.jsx` og `MultiSelectFilterDropdown.jsx` midlertidig innlagt (deretter gjenopprettet, kontrollsum verifisert) 2 av 6 feilet. Testen klikker 25 px til venstre for den venstreste tegnede etikettpikselen på et lerret med tilfeldig startlayout og treffer derfor ikke alltid en node. Påstandene og testens hensikt (fullstendig tittel i nodepanelet) skal beholdes; det er måten noden finnes på som må gjøres deterministisk (forslag i 19.14).
- **OPPF-003 – åpen (ny).** `WikiQualityTabRemovalTest::test_the_wiki_navigation_no_longer_offers_a_quality_tab` feiler. Testen leser `resources/js/Layouts/CustomerAppLayout.jsx` og forventer `wiki-pages`, `wiki-sources`, `wiki-runs` og `wiki-graph`; ingen av dem finnes i filen på HEAD (`git show HEAD:… | grep -c` = 0), og Fase 5 har ikke endret filen. Baseline: i en ren HEAD-worktree (egen `vendor`-kopi, klassen verifisert lastet fra worktreen) feilet testklassen også – den aktuelle testen sammen med sju HTTP-tester som feilet der fordi worktreen mangler bygde ressurser. I hovedtreet feiler bare denne ene. Skal rettes mot dagens navigasjonsdefinisjon; ingen Wiki-funksjonslogikk endres for å gjøre den grønn.

#### 19.17.4 Testgrunnlag (kontrollert mot 19.6–19.7)

| Test | Resultat |
|---|---|
| Enhetstester | 1826 bestått, 0 feilet, 0 hoppet over |
| `lint:a11y` | 0 feil, 0 advarsler (66 filer) |
| `npm run build` | Bestått |
| Ny E2E `a11y-wiki.spec.js` | 12 bestått, 0 feilet |
| Samlet E2E (41 spesifikasjoner, 256 tester) | Kjøring 1: 255 bestått, 1 feilet, 0 ikke kjørt. Kjøring 2: 255 bestått, 1 feilet, 0 ikke kjørt. Kjøring 3: 256 bestått, 0 feilet, 0 ikke kjørt. Feilen i 1 og 2 er OPPF-002. |
| PHP, målrettet | 457 bestått, 1 feilet (OPPF-003) |

#### 19.17.5 Åpne funn, manuell verifisering og risiko før Fase 6

- **Åpne WCAG-funn fra Fase 5:** A11Y-153 (hover-tooltip i grafen kan ikke lukkes med Escape), A11Y-154 (bilder uten alternativ tekst i kilden – innhold), A11Y-156 (tre `disabled`-kontroller låst av eksisterende tester). Delvise: A11Y-011, A11Y-105/-155 (hardkodet norsk), A11Y-121. Fra tidligere faser: A11Y-145 (produktbeslutning).
- **Manuell verifisering:** hele 19.12 gjenstår, i tillegg til §15.8.7, §16.8, §17.8 og §18.9; påstandsflyten bør også prøves som QA-bruker.
- **Risiko før Fase 6:** fokusreglene i Wiki bygger på element-id-er (låst av enhetstester); `slate-500` holder ikke på sidebakgrunnen `#f6f7fb` og bør kontrolleres i GRC; mønsteret med `disabled` under forespørsler (A11Y-156) bør ikke kopieres; E2E kjører bare i Chromium; OPPF-002 og OPPF-003 må løses før merge. Ingen av disse hindrer oppstart av Fase 6.

#### 19.17.6 Vurdering

Sluttkontrollen fant ingen uavklarte regresjoner. Fase 5 er klar for commit. OPPF-001 er lukket; OPPF-002 og OPPF-003 er åpne og må løses før merge til `main`. Fasen er ikke manuelt verifisert og gir ikke grunnlag for å hevde at Procynia oppfyller WCAG 2.2 AA.

---

## 20. Fase 6 – gjennomføringsrapport (2026-10-16)

Branch `feature/wcag-accessibility`, utgangspunkt `641d4a1c` (Fase 5), rent arbeidstre ved start. Begrepene er de samme som i §17–§19: **Kode** = implementert, **Auto** = automatisk test i repoet, **Agent** = kontrollert av agenten i ekte nettleser (Playwright, Chromium), **Manuell** = kontrollert av en person. Ingen manuell verifisering er gjort. Fase 6 ble committet etter sluttkontrollen (§20.16).

### 20.1 Omfang og arbeidsmåte

Kartlagt før endringene. Hver modul ble gjennomgått fil for fil; ingenting er byttet ut mekanisk på tvers av modulene.

| Område | Filer | Hva som finnes |
|---|---|---|
| Kvalitet og prosessflyt | `Quality/{Index,Item}.jsx`, `Components/App/ProcessFlowPanel.jsx` (2 843 linjer), `ProcessSwimlaneDiagram.jsx`, `ProcessFlowStepList.jsx`, `ProcessFlowChangeCard.jsx`, `QualityItemActions.jsx` | Typeoversikt, oppmerksomhet, tre registre og Verktøy; dokumentvisning med styringsinformasjon, struktur, koblinger, evidens og verktøy; SVG-prosessdiagram, steglisten, struktureditor (roller, noder, forbindelser), AI-forslag og endringsforslag, avklaringer, dialogene Rediger steg / Flytt steg / Slett flyt / aktivitet (kontroller, artikler) |
| Risikostyring | `Risk/**` (13 filer) | Register med søk og status, Ny risiko, risikoside med detaljer og redigering, vurdering (sannsynlighet × konsekvens → nivå), behandling og tiltak, kontroller, kontekst, aksept av restrisiko, vurderingsintervall, oppmerksomhet |
| Avvik og forbedringer | `Improvements/**` (9 filer) | Register med søk og tre filtre (kort på mobil, tabell fra `md`), registrering, saksside med behandling (start, lukk, avbryt, gjenåpne), årsak, tiltak med livsløp og effektverifisering, historikk, oppmerksomhet |
| Mål og KPI | `Objectives/**` (14 filer) | Målregister, målside, KPI-side med resultat, måling, korrigering og tilbaketrekking, trendgraf (SVG) og målehistorikk, KPI-skjema med måltyper og `UnitInput`, kontekst mot Kvalitet, oppmerksomhet |
| Ledelsens gjennomgåelse | `ManagementReview/**` (6 filer), `resources/views/management-review/report.blade.php` | Register, gjennomgang med seksjonsnavigasjon, klarhetsliste og statusmarkører, deltakere, vurderinger, beslutninger/tiltak, rapport (HTML-utskrift og PDF via dompdf) |
| Etterlevelse og revisjon | `Compliance/**` (25 filer) | Kravregister (gruppert/liste), kravkilder, kravside (relevans/Kontrollvalg, vurdering, Kvalitet-koblinger, status), Etterlevelsesvurdering (tall, fremdrift, arbeidsliste), Kravbibliotek (tre med søk, aktiveringsdialog), Revisjoner (planlegging, omfang, kontrollhandlinger, funn → Avvik, livsløp, rapport, ARIA-faner) |
| Leverandøroppfølging | `SupplierManagement/**` (29 filer) | Register, leverandørside med seks faner (lenker), kritikalitet, profil, vurdering, aktsomhet, kontrollkrav og overstyringer, kravmaler (dialog), dokumentasjon med private filer, risiko- og avvikskoblinger, Wiki-veiledning, oppfølgingsplan, Excel-import i steg |
| Filament `/admin` | `AdminPanelProvider.php`, `app/Filament/**`, 23 egne Blade-visninger, `theme.css` | Filament v5.7.6 (Livewire), primærfarge amber, mørk modus aktiv (systemvalg). 16 egne sider (Varsler, AI-erfaring, AI-forbruk, AI-lønnsomhet, AI-bruksmønster, Backup, Fakturering, CSV, Doffin ×4, Overvåkning, Kø/Scheduler, Systemstatus, Hendelser) og 14 ressurser. Ingen plugins, ingen egne modaler (bare Filaments egne). |
| Øvrige flater | `Components/App/{StatusBadge,AlertBox,EmptyStateBox,FormButtonRow,SearchableMultiSelect,AiCapacityCard,AiQuotaCompact,BidPipelineOverview}.jsx`, `Pages/Public/*` (9) | Felleskomponenter som ikke var dekket av lint; offentlige sider (registrering, FAQ, personvern, betingelser, sikkerhet m.fl.) |

Oppgaver og tiltak (Oppfølging / Mine oppgaver), Styringsdashboardet (`Governance`), Brukere, Avdelinger, Kundemiljø/Tilganger og Abonnement ble rettet i Fase 2–3. De er kontrollert på nytt i Fase 6 (ingen endringer etter `5559fcb9`, mønstrene fra Fase 3 er intakte, lint uten funn) og ikke migrert på nytt. Etter Fase 6 finnes det ingen `.jsx`-fil i `resources/js` som verken er dekket av `lint:a11y` eller av en fase.

**Rammer som er holdt:** ingen endring i risikoberegning, KPI- og målberegninger, måltyper/toleranse, avviksprosess, revisjonsløp, etterlevelses- og relevansregler, godkjenninger, leverandørvurdering, signaler, kravoppløsning, importparsing, tilgangsregler, roller, datamodell, migrasjoner, ruter, kontrollere, validering, API-kontrakter, forespørselsdata eller historikk. Ingen PHP-kode i `app/` utenom Filament-presentasjon (§20.4). Ingen nye avhengigheter. `window.confirm` er beholdt (beslutning 3 i §13; native dialoger er tilgjengelige).

### 20.2 Implementert – React/GRC

| Del | Hva | Filer | Funn | Status |
|---|---|---|---|---|
| A. Prosessdiagram og stegliste | SVG-en er `role="group"` med navn og `<desc>` når den har knapper, `role="img"` bare når den ikke har det; dekor (baner, piler, ikke-klikkbare noder) er `aria-hidden`. Steglisten er et likeverdig alternativ på samme data: «Rediger steget X» (samme navn som noden) og «+ Legg til aktivitet etter» for nøyaktig de pilene diagrammet har «+» på; knappene kaller de samme handlerne og åpner de samme dialogene. Fokusringen tegnes i SVG (rect/sirkel som vises ved `:focus-visible`). Streker ≥ 3:1. | `ProcessSwimlaneDiagram.jsx`, `ProcessFlowStepList.jsx`, `ProcessFlowPanel.jsx`, `processFlowA11y.js` (ny) | A11Y-004, -018, -128 | Kode + auto (enhet + E2E) |
| B. Struktureditor og dialoger | Noder/Forbindelser i `ScrollRegion` med caption og `scope`; alle felt navngitt («Tekst, node 2», «Fra, forbindelse 1»), handlingskolonnen har tekst; «Fjern» med radkontekst og fokus til neste/forrige rad/«Legg til». Fjerning av en rolle: knappen sier hvor mange steg som forsvinner, og fjerningen kunngjøres som ulagret (ingen ny bekreftelse). Rediger/Flytt steg via `FormField`, utfall navngitt, dialogene har beskrivelse og reservefokus. | `ProcessFlowPanel.jsx` | A11Y-108, -014, -121, -127 | Kode + auto |
| C. AI i Kvalitet | Prosessbeskrivelse og endringsinstruks via `FormField`; knappene `aria-disabled` + vakt; «Leser beskrivelsen …» ved start og resultatet én gang (`useTransitionAnnouncement`); fokus beholdes eller settes bevisst etter avklaring, godta og forkast. AI-flyt, prompter og lagring er uendret. | `ProcessFlowPanel.jsx`, `ProcessFlowChangeCard.jsx` | A11Y-013, -014, -143 | Kode + auto |
| D. Kvalitet: sider | «Dokument \| Flyt» som `nav` med `aria-current`; radhandlingene er en disclosure (ikke `role="menu"`); lokale `Field` bygger på `FormField`; tabeller med caption, `scope` og radoverskrift; fjerning (med `window.confirm`) gir fokus til neste rad, forrige eller seksjonsoverskriften. | `Quality/{Index,Item}.jsx`, `QualityItemActions.jsx` | A11Y-027, -103, -013, -014, -108, -016, -019, -121 | Kode + auto |
| E. Risiko | Alle felt koblet (`fieldA11y`): hint, feil, `aria-invalid`, `aria-required`; `RequiredFieldsNote`; stjernen `rose-700`. Paneler (Ny risiko, Rediger fra tre utløsere, vurdering, tiltak, kontroller, kontekst, aksept) får fokus inn og tilbake til riktig utløser. Beregnet nivå var en live-region som monteres med skjemaet; nå kunngjøres en nivåendring én gang. Registeret: `ScrollRegion`, caption, radoverskrift, antall treff kunngjøres etter søk. | `Risk/**`, `riskA11y.js`, `useInlinePanelFocus.js` (nye) | A11Y-013, -014, -019, -108–-112, -121, -143, -191 | Kode + auto (enhet + E2E) |
| F. Avvik og forbedringer | Samme mønster for register, sak, årsak, status og tiltak; sletting av sak og tiltak med fokus til neste/forrige/«Nytt tiltak»; avvist sletting og avvist start vises ved knappen (`trackRowAction`, A11Y-170). | `Improvements/**`, `improvementA11y.js` (ny) | A11Y-013, -014, -108–-111, -121, -137, -143 | Kode + auto (enhet + E2E) |
| G. Mål og KPI | Trendgrafen er et rent `role="img"` med tekstlig sammendrag (antall perioder, siste verdi, mål, status, retning) og lenke til Målehistorikk; ingen tabstopp per punkt; tooltip lukkes med Escape. Målehistorikk med caption, radoverskrift (periode) og `ScrollRegion`; Korriger/Trekk tilbake beskrevet av perioden. Skjema via `FormField`/`fieldA11y`, fokus til første feil også ved klientsidig tallkontroll; `UnitInput`s fokusbytte er urørt. | `Objectives/**`, `objectivesA11y.js` (ny) | A11Y-052, -018, -013, -014, -108–-111, -120, -121 | Kode + auto (enhet + E2E) |
| H. Ledelsens gjennomgåelse | Klarhetslisten sier «fullført/gjenstår» i tekst, markørene har kanter ≥ 3:1; «Neste/Forrige seksjon» flytter fokus til seksjonens overskrift; skjema, grupper (Avgrensning, fagområder, overføring) og deltakere via `FormField`/`FieldsetField`; fjerning av deltaker og beslutning uten fokustap; flaggede tall har tekst. Rapporten: caption og `th scope="row"`; PDF-lenken er beskrevet av «PDF-filen er ikke tilrettelagt for skjermleser» og peker til HTML-rapporten. | `ManagementReview/**`, `reviewA11y.js` (ny), `report.blade.php` | A11Y-024, -113, -120, -013, -014, -126 | Kode + auto (enhet + E2E) |
| I. Etterlevelse og revisjon | Alle skjema koblet, radiogrupper som `fieldset role="radiogroup"`, avkrysningsgrupper beskrevet av feilen; alle paneler med fokus inn/ut; fjerning (kilder, omfang, kontrollhandlinger, funn, koblinger) gir fokus videre; avvist sletting av kilde ved knappen. Dyplenker og interne lenker til funn/kontroll flytter fokus (A11Y-015). Overskriftsnivåene følger strukturen (A11Y-119). Antall treff kunngjøres én gang (register, vurdering, revisjoner, bibliotekets tre). Tabeller i `ScrollRegion`. Revisjonsfanene (ARIA-faner) er beholdt. | `Compliance/**`, `complianceA11y.js`, `useComplianceFocus.js`, `FieldError.jsx`, `RequiredMark.jsx` (nye) | A11Y-010, -013, -014, -015, -019, -110, -111, -119, -120, -121, -137, -190, -192 | Kode + auto (enhet + E2E) |
| J. Leverandøroppfølging | Alle skjema koblet (felles `SupplierField`, beholder hint over feltet), grupper beskrevet av feilen, `aria-disabled` + vakt; paneler med fokus inn/ut; fjerning/frakobling uten fokustap; interne lenker flytter fokus (A11Y-015). Import: `FilePickerField`, stegene sier «fullført/nåværende/gjenstår» og har `aria-current="step"`, filterendring kunngjøres. Live-regioner som ble montert med innholdet (aktiveringsadvarsel, utfasingsfeil, Wiki-søk) er erstattet med beskrivelse + én kunngjøring. Dokumentopplastingen beholder den native filvelgeren (låst av test), men er koblet. | `SupplierManagement/**`, `supplierA11y.js`, `supplierFocus.js`, `SupplierField.jsx` (nye) | A11Y-011, -013, -014, -015, -019, -024, -109, -120, -121, -143 | Kode + auto (enhet + E2E) |
| K. Felleskomponenter og offentlige sider | `SearchableMultiSelect`: antall treff kunngjøres etter skriving, synlig aktiv rad, fokus etter fjernet brikke, `invalid`/`required`. `AlertBox` kan få `role`/`id` (ingen standardrolle: alle dagens bruk er statiske). Registrering via `FormField` med fokus til første feil og `RequiredFieldsNote`; FAQ som overskrift + disclosure; personvern-tabellene i `ScrollRegion` med radoverskrifter; innholdsfortegnelser som `nav`; overskriftsnivå på Sikkerhet; kontrast. | `Components/App/{SearchableMultiSelect,AlertBox,AiQuotaCompact,BidPipelineOverview}.jsx`, `searchableMultiSelectA11y.js` (ny), `Pages/Public/{Register,Faq,Security,Privacy,Terms,Home}.jsx` | A11Y-013, -016, -019, -111, -119, -121 | Kode + auto (enhet + E2E) |
| i18n | 48 nye nøkler i `procynia.a11y.*` (`quality_*` 24, `objectives_*` 9, `suppliers_*` 10, `management_review_*` 3, `compliance_*` 2) og 12 i den nye gruppen `procynia.admin_a11y.*` (Filament, lest server-side via `App\Filament\Support\AdminText`). no + en. Ellers gjenbrukt eksisterende modulnøkler. | `lang/{no,en}/procynia.php` | A11Y-105 (delvis) | Auto (`A11yTranslationsTest`) |
| Lint | `lint:a11y` dekker 123 nye filer (189 totalt): alle GRC-sider, prosesskomponentene, de resterende felleskomponentene og de offentlige sidene. Ingen regel er slått av. De reelle funnene (radioetiketter i `QualityItemActions`, klikk uten tastatur på `UnitInput`-rammen, et ukjent `eslint-disable`-direktiv m.fl.) er rettet. | `eslint.config.js` | — | Auto |

### 20.3 Status og visualiseringer

- **Status, alvorlighet, prioritet og frister:** risikonivå, KPI-status, måloppnåelse, avviksstatus, revisjonsfunn, tiltaksstatus, kritikalitet, vurderingsresultat og forsinkelser var allerede tekst i merkene (`StatusBadge` og modulenes egne merker). Det som bare var farge eller ikon, har fått tekst: klarhetslisten og markørene i Ledelsens gjennomgåelse, importstegene, flaggede tall i grunnlaget, valgt fase i revisjonen (2 px kant i tillegg til farge), alvorlighet i Filament (Kritisk/Advarsel/Info).
- **KPI-graf:** `role="img"` med et sammendrag som sier det vesentlige; tallene står i Målehistorikk-tabellen. Grafen tegnes som før.
- **Prosessdiagram:** innholdet og alle redigeringshandlingene finnes i steglisten (samme mønster som Wiki-grafens liste i Fase 5). Diagrammet beholdes som det er.
- **Filament AI-forbruk:** SVG-grafene var allerede `aria-hidden` med dataene i «Trendtabell».
- Ingen beregning, statusverdi eller datagrunnlag er endret.

### 20.4 Filament Admin

| Del | Hva | Filer | Funn | Status |
|---|---|---|---|---|
| Primærfarge | `array_replace(Color::Amber, [600 => Color::Amber[700]])`: bare trinn 600 byttes. Lenke- og merketekst går fra ca. 3,2:1 til 5,0:1 mot hvitt (4,85:1 mot amber-50); gjelder også Filaments egne merker. | `AdminPanelProvider.php` | A11Y-055 | Kode + agent (målt rendret) |
| Mørk modus | **Beslutning: mørk modus beholdes og støttes.** Alle 23 egne visninger og klassestrengene i `AiForbruk.php`/`DoffinSupplierHarvestRun.php` har fått `dark:`-varianter; AI-erfaringens egne stiler har `.dark`-regler. | `resources/views/filament/**`, `app/Filament/Pages/*` | A11Y-056 | Kode + agent (axe lys og mørk) |
| Vedleggstabellen | Ekte `<button aria-expanded aria-controls>` («Beskrivelse av <fil>») i stedet for bare klikk på raden (radklikket er beholdt for mus); caption, `scope`, radoverskrift, «Last ned <fil>», navngitt rulleregion. | `attachment-table.blade.php` | A11Y-053 | Kode + agent |
| Skjema | Datofelt Fra/Til har `for`/`id`; egne felt har `field-border` og synlig fokus; etter en avvist innsending flytter et lite skript (hendelsen `form-validation-error`) fokus til første ugyldige felt (A11Y-212). Filaments tømme-/fjernknapper minst 24 px (A11Y-210), tomverdier og plassholdere med nok kontrast (A11Y-211). | `ai-forbruk`, `ai-profitability`, `components/focus-first-error.blade.php` (ny), `theme.css` | A11Y-054, -210, -211, -212 | Kode + agent |
| Driftssider | Varsler: alvorlighet i ord, lister, «Merk lest» beskrevet av tittelen og fokus til neste. Systemstatus og Backup: overskrifter, alvorlighet i ord, `role="progressbar"` med verdi, disclosure for feilede jobber, og en knapp «Pause/Fortsett automatisk oppdatering» (samme `refreshRuntimeState`, ingen ny datahenting). Høsting: fremdriftsfelt med rolle og verdi, fasemerke `role="status"`, `motion-reduce`. Høstingens 3 s-polling beholdes (fremdrift i en kjøring brukeren selv har startet, stopper av seg selv; låst av test). | `admin-notifications`, `system-status`, `backup-recovery`, `doffin-harvest`, `doffin-supplier-harvest-run` | A11Y-125, -213 | Kode + agent |
| Struktur og tabeller | Doble h1 → h2, hopp i overskriftsnivå rettet (Kø/Scheduler, Overvåkning, AI-bruksmønster); tabeller med caption/`scope`/radoverskrift; rulleregioner som Blade-utgaven av `ScrollRegion` (`components/scroll-region-attrs.blade.php`). | Sidene over | A11Y-108–-110 (admin) | Kode + agent |
| Hjelp | Hjelpeknappen, «Rediger hjelpetekst» og «Lukk» er oversatt (`admin_a11y.*`); pilen `aria-hidden`. | `HasAdminPageHelp.php`, `page-help.blade.php`, `AdminText.php` (ny) | A11Y-105 (Filament-hjelpen) | Kode + auto |

Filaments egne komponenter (sidemeny, brødsmuler, tabeller, modaler, varsler) ble kontrollert med axe og tastatur og trengte ingen endring utover A11Y-210–212. Ingen leverandørkode er endret.

### 20.5 Tester og resultater

Resultatene er fra kjøringer på endelig kode.

| Test | Resultat |
|---|---|
| `npm run test:unit` (Node 22, Docker) | **2050 bestått, 0 feilet, 0 hoppet over.** Nye: `a11yPhase6{Quality,Risk,Improvements,Objectives,ManagementReview,Compliance,Suppliers,Remaining,Filament}.test.js` (rene hjelpere for fokusmål, kunngjøringstekster og stegstatus; kilderegler for feltkobling, tabellsemantikk, paneler, grafens rolle, diagrammets gruppe og stegliste, disclosure, Filament-visningene; alle nye nøkler i begge språk). |
| `npm run lint:a11y` (Node 22) | **0 feil, 0 advarsler** (189 filer). |
| `npm run build` | Bestått. |
| PHP, målrettet – GRC, offentlige sider, språk (76 testklasser: Quality*, Risk*, Kpi*, Objective*, Improvement*, Compliance*, ManagementReview*, Supplier*, Public*, A11y*, SharedTranslations*, InfoCenter*, Governance*, MyTasks*, DompdfPreview*, CustomerEnvironment*, CrossTenant*) | **987 bestått, 0 feilet** (13 589 påstander). |
| PHP, Filament (`tests/Feature/Filament`) | **282 bestått, 1 feilet** (1 404 påstander). Feilen er eksisterende, se 20.6 (OPPF-004). |
| Pint (berørte PHP-filer) | Bestått. |
| E2E, nye spesifikasjoner (9) | `a11y-grc-quality` (8), `a11y-grc-risk` (1), `a11y-grc-improvements` (1), `a11y-grc-objectives` (1), `a11y-grc-management-review` (1), `a11y-grc-compliance` (3), `a11y-grc-suppliers` (5), `a11y-remaining` (4), `a11y-admin` (13): **37 bestått, 0 feilet** i siste kjøring per spesifikasjon. Første samlede kjøring fant fire feil, se 20.6. |
| E2E-regresjon, 88 spesifikasjoner (256 tester): alle `a11y-*` fra Fase 1–6, alle GRC-modulspesifikasjoner (`quality-*`, `risk-*`, `improvement*`, `objective*`, `management-review`, `compliance-*`, `supplier-*`), `knowledge-handoff`, `my-tasks-*`, `admin`, `billing`, `package-change`, `permission-sections`, `roles-on-user-edit`, `info-hint`, `app-navigation`, `breadcrumbs`, `dashboard-structure` | Kjøring 1: **250 bestått, 5 feilet, 1 hoppet over** (39,9 min) – feilene er undersøkt og rettet, se 20.6. Kjøring 2 på endelig kode, uten kildeendringer underveis: **255 bestått, 0 feilet, 1 hoppet over** (40,0 min). Den ene som ble hoppet over (`a11y-forms.spec.js:199`), hoppet over seg selv fordi Fase 6 gjorde første celle til `<th>` (20.6); etter rettelsen: `a11y-forms` **13 bestått, 0 feilet, 0 hoppet over**. |
| Full PHP- og E2E-suite | Ikke kjørt (merge gate, jf. prosjektregel). |

Dekning i de nye E2E-spesifikasjonene: avvist innsending → fokus, `aria-invalid` og beskrivelse (alle moduler, Filament og registrering); paneler og dialoger med fokus inn og ut (inkl. Filament-modal med Escape); tabellsemantikk; radhandling/sletting uten fokustap; statustekst; kunngjort antall treff; grafens sammendrag; diagrammet som gruppe og steglisten; disclosure i Kvalitet og i vedleggstabellen; axe uten brudd på hovedflatene (Filament: hele sider, lys og mørk); 320 px uten sidelengs rulling.

### 20.6 Regresjon, feil funnet under testing og eksisterende feil

- **Antall treff etter søk (Fase 6-feil, rettet før levering):** Risiko, Avvik og Leverandøroppfølging kunngjorde `visible_count` (alt brukeren har tilgang til, uavhengig av søket) i stedet for antall rader søket ga – «1 risikoer» for et søk uten treff. Funnet av `a11y-grc-risk`/`-improvements`. Rettet til listens lengde; låst av en ny enhetstest.
- **Personvern ved 320 px (Fase 6-feil, rettet):** den nye `break-words`-løse tittelen «Personvernerklæring» (36 px, ett ord) og en tabellboks uten `min-w-0` gjorde siden 338 px bred. Tittelen kan nå brytes; tabellene ligger i `ScrollRegion`.
- **To testfeil i de nye spesifikasjonene:** feil import (`sidewaysOverflow` fra `helpers/a11y.js`) i to spesifikasjoner, og en musetest i `a11y-grc-objectives` der klikket på «Lagre» bommet fordi feilteksten fra tallkontrollen ved blur skjøv knappen ned (se A11Y-186). Testen bruker nå tastatur; påstandene er uendret.
- **Funnet i den første samlede regresjonen (kjøring 1, 20.5) og rettet:**
  - *Dupliserte id-er (Fase 6-feil):* en ny id på kravradens tittel i Leverandøroppfølging (`control-requirement-<id>-title`) var den samme som id-en `ControlRequirementForm` gir «Tittel»-feltet ved redigering, så etiketten pekte på teksten og feltet mistet navnet sitt (`supplier-assurance.spec.js:112`). Radens id-er har nå et eget prefiks. axe sjekker ikke `duplicate-id` med WCAG 2.2-taggene (4.1.1 er utgått), så slike feil fanges bare av tester som bruker feltets navn.
  - *To kontroller med samme navn (Fase 6-feil):* deltakerens «Fjern» i Ledelsens gjennomgåelse fikk navnet «Fjern <navn>», det samme som brikken i deltakervelgeren (`management-review.spec.js:85`). Knappen heter nå «Fjern» og beskrives av deltakeren.
  - *Opplastingsfeilen i leverandørimporten var ikke lenger `role="alert"`* (Fase 1-testen `a11y-foundation.spec.js:48`). Importen bruker nå `FilePickerField`s standard (alert + fokus tilbake til feltet), som Wiki-kildeopplastingen.
  - *Testfeil:* `objective-kpi-target-types.spec.js:138` (tilpasset i Fase 6) brukte vanlige mellomrom der `Intl` setter hardt mellomrom; mønsteret bruker nå `\s` og er litt strengere.
  - *Eksisterende feil fra Fase 3:* `risk-access.spec.js:56` søkte etter en knapp med navnet «Alle», men Fase 3 (`5559fcb9`, A11Y-109) ga knappen radkontekst («Alle – Endre fagområder: <rolle>»). Specen var ikke med i regresjonsutvalget i Fase 3. Påstanden bruker nå det fullstendige navnet (`exact`), like streng som før.
- **Eksisterende tester tilpasset (låste den utilgjengelige oppførselen):**
  - `objective-kpi-target-types.spec.js`: fokuserte `circle[tabindex="0"]` og forventet en `role=status`-tooltip – nettopp det A11Y-052 fjerner. Kontrollerer nå at punktene ikke har tabstopp, at grafens navn sier siste verdi, og hover-tooltipen.
  - `quality-flow-activity-articles`, `quality-activity-controls`, `quality-flow-subprocess`, `quality-flow-zoom`: `getByRole('img', { name: /Prosessflyt/ })` → `svg[aria-label^="Prosessflyt"]` (diagrammet med knapper er nå en gruppe, A11Y-004).
  - `quality-process-row-actions`, `quality-overview-sections`: `menuitem` → `button` (disclosure, A11Y-103).
  - `a11y-forms.spec.js:199` (Fase 3): fant lenken i første `td`; i Kvalitet-tabellene er første celle nå `<th scope="row">`, så testen hoppet over seg selv med meldingen «No seeded quality process». Leter nå i `th, td`; påstandene er uendret.
  - `risk-access.spec.js:56`: se over.
  - Ingen påstand er svekket. Kildetester som låser markup, er ellers oppfylt ved å tilpasse koden.
- **OPPF-004 (ny, eksisterende feil):** `CustomerCreatePageTest::test_internal_admin_can_create_customer_with_first_system_owner` forventer modulene `['wiki', 'quality', 'improvements']`, men `ModuleEntitlementService` gir i tillegg `management_review`. Bekreftet feilende i en ren HEAD-worktree (`641d4a1c`, egen `vendor`-kopi, klassen lastet fra worktreen). Ingen Fase 6-fil berører pakker eller moduler. Må rettes mot dagens modulkatalog før merge; ingen tilgangslogikk endres for å gjøre den grønn.

### 20.7 Funnstatus etter Fase 6

Lukket betyr: utbedret og verifisert automatisk innenfor testomfanget i 20.5, på Fase 6-flatene. Ingen er manuelt verifisert.

| ID | Status |
|---|---|
| A11Y-004 | Lukket (auto): diagrammet er en navngitt gruppe, strukturfeltene har navn, og steglisten kan redigere og sette inn steg med de samme dialogene. Akseptansekriteriet i §8 («prosesser kan redigeres fullt ut med tastatur og skjermleser via steglisten») er oppfylt med tastatur i E2E; skjermleser gjenstår manuelt. |
| A11Y-010 | Lukket for `RegisterGroups` og de øvrige svake ringene i Etterlevelse. |
| A11Y-011 | Lukket for leverandørimporten. Delvis for leverandørdokumentene (native filvelger, koblet; låst av `documentFiles.test.js`). Wiki: se A11Y-156. |
| A11Y-013 | Lukket for alle skjemaer i GRC, de offentlige sidene og egne Filament-skjema (auto). Med Fase 3–5 er alle kjente skjema i `/app` koblet. |
| A11Y-014 | Lukket for GRC-panelene og fjerninger (auto). Se A11Y-184, -195, -196 for gjenstående enkelttilfeller. |
| A11Y-015 | Lukket (Revisjon, Leverandør; Wiki i Fase 5). |
| A11Y-016, -019 | Lukket i Fase 6-filene (beregnet + axe). |
| A11Y-018 | Lukket for prosessdiagrammet og KPI-grafen. |
| A11Y-024 | Lukket for Ledelsens gjennomgåelse og leverandørimporten. Med Fase 4–5 er alle kjente tilfeller rettet. |
| A11Y-027 | Lukket for `Quality/Item.jsx`. |
| A11Y-052 | Lukket (auto). |
| A11Y-053, -054, -055, -056 | Lukket (agent: axe lys og mørk, målt kontrast). Beslutning 2 i §13: mørk modus støttes. |
| A11Y-103 | Lukket for `QualityItemActions`. Det finnes ikke lenger `role="menu"` i `resources/js`. |
| A11Y-107 | Uendret: `window.confirm` beholdes (beslutning 3). Fokus etter bekreftet fjerning er rettet der det gjaldt (A11Y-014). |
| A11Y-108 – -111 | Lukket i GRC, de offentlige sidene og Filament-visningene. |
| A11Y-113 | Lukket for rapporten i Ledelsens gjennomgåelse. |
| A11Y-119 | Lukket (Etterlevelse, Sikkerhet, Filament-sidene). |
| A11Y-120 | Lukket for GRC-gruppene. |
| A11Y-121 | Lukket for GRC, registreringen og de egne Filament-skjemaene: stjerne + «Felt merket med * må fylles ut.» + `aria-required`. Med Fase 3–5 er påkrevde felt forklart i alle kjente skjema. |
| A11Y-125 | Lukket (agent). Høstingens 3 s-polling er beholdt med begrunnelse (20.4). |
| A11Y-126 | Delvis: HTML-rapporten er lenket og beskrevet fra PDF-nedlastingen. PDF-en er fortsatt ikke tagget (dompdf). Krever annen PDF-motor (ikke gjort). |
| A11Y-127 | Delvis, med vilje: konsekvensen sies i knappens navn og kunngjøres etter fjerning; ingen ny bekreftelse (ville vært et nytt prosesstrinn). Endringen er ulagret og kan forkastes. |
| A11Y-128 | Lukket i kode (fokusring tegnet i SVG, auto i Chromium). Firefox/Safari gjenstår manuelt. |
| A11Y-137, -143 | Mønstrene er tatt i bruk i GRC (auto). |
| A11Y-105, -155 | Delvis: Filament-hjelpen er oversatt. Gjenstår til Fase 7: se 20.8. |
| A11Y-145, -153, -154, -156 | Uendret (Fase 4–5). Ingen av dem berører Fase 6-flatene. |

### 20.8 Nye funn

| ID | Funn | WCAG | Status |
|---|---|---|---|
| A11Y-160 | Tekst inne i prosessdiagrammet under 12 px (artikkel- og underprosessmerker 10,5 px, kantetiketter 11 px). Bredden beregnes fra antall tegn, så en rettelse berører tegningen. Samme informasjon står i steglisten. | 1.4.4 (beste praksis) | Åpen, P2 |
| A11Y-161 | «Legg artikkelen inn i Wiki» fra en prosessaktivitet: en valideringsfeil fra serveren vises ikke (ingen `onError`). | 3.3.1 | Åpen |
| A11Y-162 | `DESTRUCTIVE_CONFIRM` i `actionStyles.js` er `bg-rose-600` med hvit tekst (4,51:1) – oppfyller AA, men ikke §10-regelen om `-700`. Låst av `actionStyles.test.js`. | 1.4.3 | Informasjon |
| A11Y-163 | Hver node og «+» i diagrammet er et tabstopp. Steglisten er det effektive alternativet. | 2.4.3 (beste praksis) | Informasjon |
| A11Y-170 | Avvist «Start behandling» (`errors.status`) ble aldri vist. | 3.3.1 | Rettet |
| A11Y-171 | Forklaringen av risikovurderingen fantes bare som `title` ved hover. | 1.4.13, 1.3.1 | Rettet |
| A11Y-172 | Datoer i Risiko formateres med fast `nb-NO` også i engelsk UI (`RiskAcceptancePanel.jsx:14,16`, `RiskTreatmentPanel.jsx:15,17`, `RiskAssessmentPanel.jsx:17`, `RiskReviewSchedule.jsx:12`, `Show.jsx:210`). | 3.1.2 | Åpen (Fase 7, som A11Y-105) |
| A11Y-180 | `UnitInput`-rammens klikk-til-fokus hadde ingen rolle (lint). Rammen er `role="presentation"`; fokusbyttet er uendret. | 4.1.2 | Rettet |
| A11Y-181 | Søkefeltet i Mål kunne ikke krympe under 16rem og rullet siden sidelengs ved 320 px. | 1.4.10 | Rettet |
| A11Y-182 | Flaggede tall i grunnlaget i Ledelsens gjennomgåelse ble bare vist med farge. | 1.4.1 | Rettet |
| A11Y-183 | Korrigeringsmerknaden i KPI var en live-region som monteres med teksten. | 4.1.3 | Rettet |
| A11Y-184 | «Ferdigstill» i Ledelsens gjennomgåelse er `disabled`, og grunnen står bare i `title`. Klarhetslisten forklarer hva som mangler. | 2.4.3, 1.4.13 | Åpen (samme mønster som A11Y-156) |
| A11Y-185 | Statusmarkørene i Ledelsens gjennomgåelse hadde kanter under 3:1. | 1.4.11 | Rettet |
| A11Y-186 | KPI-skjemaene: tallkontrollen ved blur viser en feiltekst som skyver «Lagre»/«Registrer måling» ned mellom museklikk og slipp, så første klikk kan bomme. Eksisterende oppførsel. Rettelsen (fast plass til feilteksten) er en synlig layoutendring og er ikke gjort. | 3.2.2-nær, brukbarhet | Åpen, P2 |
| A11Y-190 | Tallflisene i Etterlevelsesvurdering hadde `dt`/`dd` inne i `<a>` (axe `dlitem`). | 1.3.1 | Rettet |
| A11Y-191 | `Risk/RequiredMark` tegnet stjernen i `rose-500` (under 4,5:1). | 1.4.3 | Rettet (`rose-700`) |
| A11Y-192 | Veiledningens h4 sto rett under en h2 på kravsiden. | 1.3.1 | Rettet |
| A11Y-193 | En handling i revisjonen som revisjonens status avviser (`errors.audit`), vises bare i varselet øverst, ikke ved knappen. | 3.3.1 (seende) | Åpen |
| A11Y-194 | Kapittelknappene i Kravbiblioteket har fortsatt `violet-300`-ringen i tillegg til app-rammen (låst av `complianceLibrary.test.js`). Kosmetisk. | — | Informasjon |
| A11Y-195 | «Registrer funn» når Nytt funn allerede er åpent, nullstiller skjemaet uten å flytte fokus. | 2.4.3 | Åpen, P2 |
| A11Y-196 | Aktiveringsknappen i Kravbiblioteket og lagreknappene i Etterlevelse er `disabled` under lagring (låst av test, som A11Y-156). | 2.4.3 | Åpen |
| A11Y-210 | Filaments tømme- og fjernknapper var 16/20 px. | 2.5.8 | Rettet |
| A11Y-211 | Filaments tomverdier/plassholdere hadde 2,6:1 (lys) og 3,7:1 (mørk). | 1.4.3 | Rettet |
| A11Y-212 | Filament ruller til feilen etter avvist innsending, men lar fokus stå på knappen. | 3.3.1, 2.4.3 | Rettet |
| A11Y-213 | Etikettene i Systemstatus overlappet («QUEUE/SCHEDULER» inn i «FAILED JOBS»). | 1.4.10 | Rettet |
| A11Y-214 | Y-aksen i AI-forbruk-grafene er snudd (0 øverst). Eksisterende visningsfeil; grafene er `aria-hidden` og dataene står i tabellen. | — | Åpen (informasjon) |
| A11Y-215 | Dekorative ikoner i Filaments kjerne er `gray-400`. Ikke tekst og ikke informasjonsbærende. | — | Informasjon |

ID-ene 164–169, 173–179, 187–189, 197–209 og 216–229 er ikke brukt.

**Gjenstående hardkodet tekst (A11Y-105/-155, til Fase 7):** Filament – `admin-notifications.blade.php:50,53,97,105,111` (antall uleste med stavefeilen «varseler», «Merk alle som lest», «Merk lest», «Ingen uleste varsler.», «Leste varsler (siste 30)»), `billing-overview.blade.php:9-13,37-53`, hele `monitoring.blade.php`, mesteparten av `ai-forbruk` og `ai-profitability`, hardkodet engelsk i `doffin-harvest`, `doffin-supplier-harvest(-run)`, `csv-import`, `queue-scheduler`, `view-doffin-supplier` og `system-status.blade.php:188,352,682`. Offentlige sider – samtykketekstene i `Register.jsx:269-296`, «Tilbake til registrering» (`Privacy.jsx:21`, `Terms.jsx:21`) og alt markedsførings- og juridisk innhold (bare norsk i dag). Risiko – datoformatet (A11Y-172). `BidPipelineOverview.jsx` er død kode (importeres ingen steder).

### 20.9 Synlige endringer

- **Felles i GRC:** tydeligere feltkanter (`field-border`), stjerne og feiltekst i `rose-700`, «Felt merket med * må fylles ut.» i skjema med påkrevde felt (også noen der det manglet stjerne på felt som allerede var påkrevd), enkelte grå tekster én nyanse mørkere, knapper som lagrer er dempet i stedet for grå mens de arbeider. En avvist sletting/start vises med en rød linje ved knappen.
- **Kvalitet:** mørkere omriss og piler i diagrammet og en fiolett fokusring i SVG; knappene «Rediger steget» og «+ Legg til aktivitet etter» i steglisten.
- **Mål og KPI:** mørkere akse, grunnlinje og målbånd i grafen; lenken «Se alle verdiene i Målehistorikk»; tooltipen lukkes med Escape.
- **Ledelsens gjennomgåelse:** mørkere kanter på statusmarkørene; merknaden om PDF-en i rapportens verktøylinje; hinten til Avgrensning står under valgene.
- **Etterlevelse:** valgt fase i revisjonen har 2 px kant; de svake fiolette ringene er borte (app-rammen gjelder).
- **Risiko:** forklaringen under «Iboende risiko» og «Restrisiko» er synlig tekst.
- **Offentlige sider:** stjerner og forklaring på registreringen, litt mørkere grå tekst, «⚠ verifiseres» 12 px, personverntittelen kan brytes ved 320 px.
- **Filament:** mørkere amber (trinn 700) på primærknapper, lenker og merker i hele panelet; egne sider vises som mørke kort i mørk modus; alvorlighetsmerke på Varsler; «Kritisk:/Advarsel:» i advarsler; pauseknapp på Systemstatus; pilknapp på vedlegg.
- **Uendret:** ruter, handlinger, rekkefølge, statusverdier, beregninger, polling (bortsett fra pauseknappen), data.

### 20.10 Nettleserkontroll (agent)

Kontrollert i Chromium (Playwright) på `localhost:8080` med Vite dev-server:

- **Bredder:** 320 px (E2E) for alle GRC-modulene, de offentlige sidene og de egne Filament-sidene; 640 px for Ledelsens gjennomgåelse; 1440 px i alle testene. Ingen sidelengs rulling av siden; brede tabeller ruller i egne, navngitte regioner. 320 px dekker 400 % av 1280 px, 640 px dekker 200 %.
- **Tastatur:** paneler, dialoger (inkl. Filament-modal), steglisten, revisjonsfanene, fjerning, avviste innsendinger – fokus synlig og aldri tapt i de testede flytene.
- **axe:** ingen brudd på de testede GRC-flatene; i Filament på hele sider, i lys og mørk modus.
- **Ikke kontrollert:** nettleserens egen zoom, Firefox, Safari, forced-colors, skjermleser.

### 20.11 Manuell testing som gjenstår

I tillegg til §15.8.7, §16.8, §17.8, §18.9 og §19.12:

1. **NVDA + Firefox/Chrome og VoiceOver + Safari:**
   1. Prosessflyt: diagrammet leses som gruppe med beskrivelse; steglisten leses og kan redigere og sette inn steg; kunngjøringen etter at en rolle fjernes.
   2. KPI-grafens navn leses i sin helhet (det er langt); korrigeringsmerknaden kunngjøres.
   3. Radiogrupper som `fieldset role="radiogroup"` (Etterlevelse, Leverandør) og fokus til gruppen etter avvist innsending.
   4. Antall treff etter søk leses én gang (Risiko, Avvik, Mål, Krav, Leverandør, `SearchableMultiSelect`).
   5. Klarhetslisten og «Neste seksjon» i Ledelsens gjennomgåelse.
   6. Filament: fasemerket og fremdriftsfeltene under en høsting; fokus etter «Merk lest»; AI-erfaringens kortvisning under 768 px.
2. **Ekte Firefox og Safari:** SVG-fokusringen i diagrammet (A11Y-128), at interne lenker flytter fokus (A11Y-015), `[contain:inline-size]` og rulleregionene i Filament, fokus etter klikk på en brikkes fjernknapp.
3. **Forced-colors / Windows høykontrast:** diagrammets fokusring (gjennomsiktig outline beholdt), tallflisene i Etterlevelsesvurdering.
4. **Zoom 200/400 %** med nettleserens zoom på prosessflyten, KPI-siden, revisjonen, leverandørsiden og Systemstatus.
5. **Akseptansekriteriet i §8 Fase 6:** opprette og redigere en prosess med bare tastatur og skjermleser via steglisten; registrere risiko, avvik og KPI-måling; gjennomføre en revisjon.
6. **Visuelt:** den mørkere amber i Filament og mørk modus på de egne sidene.

### 20.12 Kjente risikoer og begrensninger

- Fokusmålene bygger på element-id-er (`risk-edit-button`, `improvement-action-…`, `kpi-withdraw-…`, `#compliance-audit-status` m.fl.); enhetstestene låser de viktigste. Flere moduler har en egen, liten panelfokus-hook (`useInlinePanelFocus` i Risiko og Leverandør, `usePanelSwitchFocus` i Etterlevelse) ved siden av den felles `usePanelFocus`, fordi utløserne der byttes ut eller remonteres. De er like i oppførsel; en samling i `focusManagement.js` kan vurderes i Fase 7, men er ikke gjort for å unngå å endre felles kode sent.
- Fokusretur ved lukking flytter bare fokus når det faktisk er tapt (`refocusIfLost`), og bygger på at Inertia v3 bevarer tilstand ved ikke-GET-besøk (kontrollert i `node_modules`).
- Systemstatus oppdateres nå av et Alpine-intervall i stedet for `wire:poll` (samme metode); ikke testet over lange økter.
- Filaments fokus-til-feil-skript bygger på hendelsen `form-validation-error` og en forsinkelse på 150 ms; en Filament-oppgradering kan endre hendelsen.
- Den mørkere amber gjelder hele adminpanelet.
- E2E kjører bare i Chromium. Flere testkjøringer ble avbrutt av sideomlastinger mens andre filer ble endret under Vite dev-serveren (§18.14.6.1); regresjonen er kjørt uten kildeendringer underveis.

### 20.13 Oppfølgingsoppgaver

- **OPPF-001 – gjenåpnet i sluttkontrollen** (§20.16.3), må løses før merge til `main`.
- **OPPF-002 – åpen, må løses før merge til `main`** (uendret, Wiki-grafen er ikke rørt).
- **OPPF-003 – åpen, må løses før merge til `main`** (uendret, ingen Wiki-logikk endret).
- **OPPF-004 – åpen (ny), må løses før merge til `main`:** `CustomerCreatePageTest` (20.6).
- **A11Y-161, -184, -186, -193, -195, -196:** små, avgrensede rettelser som kan tas i Fase 7.
- **A11Y-105/-155, -172:** gjenstående hardkodet tekst (20.8).
- **A11Y-126:** tagget PDF krever annen PDF-motor (produktbeslutning).
- **A11Y-145, -153, -154, -156:** uendret fra Fase 4–5.

### 20.14 Risiko før Fase 7

- Fase 6 er den bredeste fasen (ca. 170 filer). Endringene er på presentasjonsnivå og dekket av enhetstester, lint, målrettet PHP og E2E per modul, men full PHP- og E2E-suite er ikke kjørt.
- Tre åpne oppfølgingsoppgaver (OPPF-002, -003, -004) er eksisterende feil som må løses før merge.
- Skjermleser, Firefox, Safari, forced-colors og nettleserzoom er ikke testet for noen fase. Fase 7 bør prioritere en manuell runde på prosessflyten (A11Y-004), KPI-grafen og Filament i mørk modus.
- Gjenstående hardkodet tekst og de små åpne funnene i 20.8 må enten rettes eller begrunnes i tilgjengelighetserklæringen.

### 20.15 Vurdering

Fase 6 er implementert og automatisk verifisert. Alle Fase 6-funnene fra planen er rettet eller dokumentert med begrunnelse: A11Y-004, -018, -052, -053, -054, -055, -056, -125, -128 og GRC-/admin-delene av -010, -011, -013, -014, -015, -016, -019, -024, -027, -103, -108–-111, -113, -119, -120, -121, -137, -143; A11Y-126 og -127 er delvis rettet med begrunnelse, og beslutningen om mørk modus i Filament er tatt (støttes). 27 nye funn er registrert (innenfor A11Y-160 til -215, se 20.8): 14 rettet, 9 åpne (små og avgrensede, eller eksisterende oppførsel) og 4 informasjon. Ingen beregninger, prosesser, godkjenninger, tilganger, ruter, kontrollere, validering, data eller migrasjoner er endret. Enhetstester (2050/0), lint (189 filer, 0 funn), bygg, målrettet PHP (987/0 for GRC; 282/1 for Filament, der feilen er eksisterende OPPF-004), de nye E2E-spesifikasjonene og den andre samlede regresjonen (255/0, og den ene oversprungne testen grønn etter rettelse) er grønne. Fase 6 er etter agentens vurdering **teknisk klar for sluttkontroll**, men er **ikke** sluttkontrollert, ikke manuelt verifisert med skjermleser, ekte Safari/Firefox, nettleserzoom eller Windows-høykontrast, ikke committet, og gir ikke grunnlag for å hevde at Procynia oppfyller WCAG 2.2 AA. OPPF-002, OPPF-003 og OPPF-004 må løses før merge til `main`. Full E2E- og PHP-suite er merge gate og er ikke kjørt.

### 20.16 Sluttkontroll av Fase 6 (2026-10-16)

Kontroll på Fase 6-koden før commit. Branch `feature/wcag-accessibility`, HEAD `641d4a1c`. Ingen kodeendringer er gjort i sluttkontrollen.

#### 20.16.1 Git og omfang

- Arbeidstreet inneholder bare Fase 6-endringer: GRC-sidene, prosesskomponentene og de resterende felleskomponentene, de offentlige sidene, Filament-presentasjon (`app/Filament/**`, `AdminPanelProvider.php`, `resources/views/filament/**`, `theme.css`), rapportvisningen for Ledelsens gjennomgåelse, språkfilene, `eslint.config.js`, denne planen, nye enhetstester, E2E-spesifikasjoner og -fixturer. Ingen filer i `database/`, `routes/`, `config/` eller `app/` utenom Filament. Ingen midlertidige eller genererte filer (ignorerte lokale artefakter som `public/hot`, `public/build` og `test-results/` er ikke med). `git diff --check` uten funn.
- **Forretningslogikk:** ingen logikkfil (`.js` utenom nye hjelpemoduler) er endret. Antall `useForm` per fil er uendret, og alle `setData`-felt går i null netto (flyttet inn i `FormField`, ikke endret). Ingen endepunkter er lagt til, fjernet eller endret; de seks `router.delete`-kallene i Kvalitet går nå via en hjelper med samme URL og metode, pluss fokus etterpå. Ingen risiko- eller KPI-beregning, tilgangsregel, migrasjon eller API-kontrakt er endret.
- **Tidligere faser:** fjernede `htmlFor`/`aria-required`/`aria-describedby` er erstattet av `FormField` (180 nye bruk); de fire `ActionDialog`-linjene som er endret, har bare fått flere egenskaper (`descriptionId`, `fallbackFocusRef`). Enhetstestene for Fase 1–5 er grønne.
- **Tester:** ingen `test.skip`, `.only`, `fixme` eller økt global timeout er lagt til; `playwright.config` er uendret. Tilpassede eksisterende spesifikasjoner er listet i 20.6.

#### 20.16.2 Endelige testresultater

| Test | Resultat |
|---|---|
| Enhetstester | 2050 bestått, 0 feilet, 0 hoppet over |
| `lint:a11y` | 0 feil, 0 advarsler (189 filer) |
| `npm run build` | Bestått |
| PHP, GRC m.fl. (76 klasser) | 987 bestått, 0 feilet |
| PHP, Filament | 282 bestått, 1 feilet (OPPF-004, feiler også på ren HEAD) |
| E2E samlet, kjøring 3 (endelig kode, 88 spesifikasjoner, 256 tester, uten bygg rett før og uten kildeendringer underveis) | **255 bestått, 1 feilet, 0 hoppet over** (44,2 min) |

- **Ingen hoppede tester:** `a11y-forms.spec.js:199` kjører og består etter rettelsen i 20.6.
- **Den ene feilen er OPPF-001:** `a11y-navigation.spec.js:155`, linje 188 (h1 etter Kunngjøringer → Saksliste har ikke fokus innen 5 s) – samme test, linje og symptom som i Fase 3 (§17.12.9), der den ble reprodusert 3/3 på `683246a7` uten Fase 3-kode. Fase 6 har ikke endret `Notices/*`, `Layouts/*`, `routeFocus.js`, `app.js` eller spesifikasjonen. Isolert på samme kode: **5 bestått av 5** (`--repeat-each=5`). Vurdert som eksisterende, sekvensavhengig ustabilitet, ikke en Fase 6-regresjon. Ingen kode eller ventetid er endret for testen.
- Beslutning 2026-10-16: Fase 6 committes med denne kjente feilen, og **OPPF-001 gjenåpnes** som forutsetning før merge (som ved commit av Fase 3). Det er ikke en generell aksept av feilede tester.

#### 20.16.3 Oppfølgingsoppgaver før merge til `main`

- **OPPF-001 – gjenåpnet.** Feilet i den samlede kjøringen på endelig Fase 6-kode (20.16.2), etter å ha bestått i alle samlede kjøringer i Fase 4–5 og i kjøring 1–2 i Fase 6. Årsaken er fortsatt ikke funnet. Akseptansekriteriet i §17.12.9 gjelder: tre påfølgende samlede kjøringer uten feil i testen, ingen økt timeout, ingen svekkede påstander, og en eventuell rettelse i appen dokumentert med årsak. Første spor: om `routeFocus.js` mister fokusflyttingen ved Kunngjøringer → Saksliste (samme komponent, ny spørrestreng og tittel) når siden tidligere i sekvensen har fått fokus via hopp-lenken.
- **OPPF-002 – åpen** (uendret; Wiki-grafen er ikke rørt).
- **OPPF-003 – åpen** (uendret; ingen Wiki-logikk er endret).
- **OPPF-004 – åpen** (`CustomerCreatePageTest`, eksisterende; ingen Filament- eller tilgangskode er endret for å gjøre den grønn).

#### 20.16.4 Åpne funn, manuell verifisering og risiko før Fase 7

- **Åpne WCAG-funn:** fra Fase 6 A11Y-160, -161, -172, -184, -186, -193, -195, -196, -214 (20.8); delvis A11Y-011 (leverandørdokumentene), -126 (PDF), -127; uendret A11Y-145, -153, -154, -156; gjenstående hardkodet tekst A11Y-105/-155 (20.8).
- **Manuell verifisering:** hele 20.11 gjenstår, i tillegg til §15.8.7, §16.8, §17.8, §18.9 og §19.12. Ingen fase er verifisert med skjermleser, Firefox, Safari, forced-colors eller nettleserzoom.
- **Risiko før Fase 7:** fire åpne oppfølgingsoppgaver (OPPF-001–004) før merge; fokusregler som bygger på element-id-er og flere modulvise panelfokus-hooker (20.12); dupliserte id-er og like navn fanges ikke av axe (20.6); E2E bare i Chromium.

#### 20.16.5 Vurdering

Sluttkontrollen fant ingen Fase 6-regresjoner og ingen endring i forretningslogikk, tilganger, data eller API. Den ene feilen i den samlede kjøringen er den kjente OPPF-001, som er gjenåpnet. Fase 6 committes. **Fase 7 er ikke startet.** Fasen er ikke manuelt verifisert og gir ikke grunnlag for å hevde at Procynia oppfyller WCAG 2.2 AA.

---

## 21. Fase 7A – teknisk opprydding (2026-10-16)

Branch `feature/wcag-accessibility`, utgangspunkt HEAD `c7feb597`, rent arbeidstre. Fase 7A gjelder bare åpne
og delvis rettede funn og oppfølgingsoppgavene OPPF-001–004. Lukkede funn er ikke gjennomgått på nytt.
Det er ikke gjort en ny kartlegging. Ingenting er committet.

### 21.1 Klassifisering av gjenværende forhold (før 7A)

Kategorier: 1 = bekreftet A/AA-avvik, 2 = teknisk testfeil, 3 = mulig avvik som krever manuell verifisering,
4 = innhold, 5 = produktbeslutning, 6 = forbedring utover AA.

| ID | Kategori | Hindrer sentral oppgave? | Behandling i 7A |
|---|---|---|---|
| OPPF-001 | 2 (viste seg å være en appfeil, se 21.2) | Nei, men fokus ved sideskift | Rettet |
| OPPF-002 | 2 | Nei | Rettet |
| OPPF-003 | 2 | Nei | Rettet |
| OPPF-004 | 2 | Nei | Rettet |
| A11Y-153 | 1 (1.4.13) | Nei (innholdet står i panelet og listen) | Rettet |
| A11Y-156, -196 | 1 (2.4.3), forbigående | Nei | Rettet for de dokumenterte kontrollene |
| A11Y-184 | 1 (1.3.1/3.3.2-nær: grunnen bare i `title`) | Nei | Rettet |
| A11Y-161 | 1 (3.3.1) | Ja, i Kvalitet → Wiki | Rettet |
| A11Y-193 | 1 (3.3.1, seende) | Nei | Rettet for fjerning fra omfanget (eneste vanlige kilde) |
| A11Y-195 | 1 (2.4.3) | Nei | Rettet |
| A11Y-172 | 1 (3.1.2) | Nei | Rettet |
| A11Y-105, -155 | 1 (3.1.2) der teksten vises i engelsk UI | Nei | Rettet for Wiki og Varsler i admin; resten se 21.5 |
| A11Y-121 | — | — | Lukket i Fase 6; ingen tegn til regresjon, ikke gjennomgått på nytt |
| A11Y-011 (leverandørdokumenter) | — | — | Ikke et avvik: native filfelt med etikett, beskrivelse og feil; avsluttes |
| A11Y-154 | 4 | Nei | Rutine dokumentert (21.6) |
| A11Y-145 | 5 | — | Eget beslutningspunkt (21.7) |
| A11Y-126 | 5 | — | Uendret, beslutningspunkt (21.7) |
| A11Y-160, -186 | 6 | Nei | Uendret (21.5) |
| A11Y-127 | 6 | Nei | Uendret, delvis med vilje (Fase 6) |
| A11Y-162, -163, -194, -214, -215 | Informasjon | Nei | Uendret |

### 21.2 Oppfølgingsoppgaver

**OPPF-001 – rettet (feil i appen, ikke i testen).**

- *Årsak, påvist:* `routeFocus.js` avgjør om et Inertia-besøk er et sideskifte én ramme etter `navigate`
  (`requestAnimationFrame` + `setTimeout 0`). Kunngjøringer → Saksliste er samme komponent og samme sti; bare
  tittelen skiller dem. Inertias `<Head>` skriver tittelen fra en passiv effekt via en `debounce` på 1 ms, og den
  kan lande *etter* avgjørelsen. Da så besøket ut som et filter, og fokus ble stående på lenken. En midlertidig
  Playwright-sonde (8 besøk, logget tittel og `activeElement` i avgjørelsesøyeblikket) viste den gamle tittelen
  i 2 av 8 besøk, og i begge sto fokus på lenken (`A`) i stedet for h1. Det forklarer hvorfor testen var
  ustabil i samlede kjøringer (høyere last) og grønn isolert, og hvorfor ingen fase kunne knyttes til feilen.
- *Rettelse:* et besøk som ser ut som en oppdatering av samme side (samme komponent og sti, annen spørrestreng)
  observerer `<head>` til neste besøk starter eller i høyst 1,5 s. Endres tittelen, er det et sideskifte, og
  fokus flyttes til h1 – men bare hvis fokus fortsatt er på `<body>` eller på kontrollen brukeren trykket.
  Filtre som beholder tittelen, berøres ikke. Ingen timeout eller påstand i testen er endret.
- *Verifisert:* samme sonde etter rettelsen, 30 besøk (15 med normal CPU, 15 med 6× CPU-struping): tittelen
  kom for sent i 11 av 30, fokus endte på h1 i 30 av 30. Ny enhetstest for den sene tittelen, filteret uten
  titteländring og brukeren som har gått videre. Sonden er slettet.
- *Akseptansekriteriet i §17.12.9* (tre påfølgende samlede kjøringer) kan først vurderes i Fase 7C. OPPF-001
  regnes som rettet med påvist årsak, men lukkes formelt etter 7C.

**OPPF-002 – rettet (testen).** Testen klikket 25 px til venstre for den venstreste etikettpikselen og bommet
når noden var liten. Den leser nå Sigmas egne kall til `fillText` på etikettlerretet
(`fillText(label, x + size + 3, y + 16/3)`) og klikker 3 px innenfor nodens høyre kant på nodens senterlinje.
Kartet nullstilles ved hver `clearRect`, så det gjelder rammen som vises, og testen venter til kameraet står
stille. Påstandene (panelet åpnes, full tittel, ingen ellipse) er uendret; ingen produksjonskode er endret.
10 av 10 gjentatte kjøringer bestått.

**OPPF-003 – rettet (testen).** Wiki-fanene defineres ikke lenger i `CustomerAppLayout.jsx`, men i
`areaNavigationItems()` i `Support/appNavigation.js`. Testen leser nå Wiki-grenen der, krever de fire fanene
(`key: 'wiki-…'`) og at ingen `wiki-quality`/`tab: 'quality'` finnes – og at verken layouten eller
navigasjonsfilen nevner `wiki-quality`. Ingen Wiki-funksjonalitet er endret. 11/11 i klassen.

**OPPF-004 – rettet (foreldet forventning).** Basis gir `wiki`, `quality`, `improvements` og
`management_review`; det er modulkatalogens bevisste regel, låst av `CustomerModuleEntitlementTest` og
`NavigationEntitlementMatrixTest`. `CustomerCreatePageTest` forventet katalogen fra før Ledelsens gjennomgåelse.
Forventningen er oppdatert; ingen tilgangs- eller administrasjonslogikk er endret.

### 21.3 WCAG-funn rettet i 7A

| ID | Rettelse |
|---|---|
| A11Y-153 | Grafens relasjonstooltip står der pekeren gikk inn på linjen (følger ikke lenger pekeren), kan pekes på (`pointer-events-none` fjernet, 300 ms frist før den skjules når pekeren forlater linjen) og lukkes med Escape. Escape lyttes bare på mens tooltipen vises. |
| A11Y-156 | Wiki: opplastingsfeltet i Kildedokumenter, alle knappene i gjennomgangspanelet og dialogene (send til gjennomgang, kvalitetssikrer, send tilbake, godkjenn, gjenåpne, avbryt) og blokkredigeringens Lagre/Avbryt er `aria-disabled` + vakt i stedet for `disabled` mens forespørselen går. `disabled` er beholdt der en fast forutsetning mangler (ingen kontrollør valgt, for kort begrunnelse, ingen kontekst). Låsende kildetester er endret til å kreve det nye mønsteret – like strenge. |
| A11Y-196 | Kravbibliotekets aktiveringsdialog og alle lagreknapper i Etterlevelse og revisjon (15 knapper i 11 filer). Ny felles hjelper i `focusManagement.js`: `submitWhenIdle(form, handler)` (avviser ny innsending mens den første går) og `BUSY_STYLE`. |
| A11Y-184 | «Ferdigstill» i Ledelsens gjennomgåelse: grunnen står som synlig tekst under knappen og beskriver den (`aria-describedby`); `title` er fjernet. Bekreftelsesknappen i panelet beholder fokus mens den lagrer. |
| A11Y-161 | «Legg artikkelen inn i Wiki»: feltfeil fra serveren vises ved Tittel/Artikkel med fokus dit; andre avvisninger (grensen per aktivitet) i en oppsummering ved knappen, som får fokus. Knappen er ikke lenger `disabled` mens den sender. |
| A11Y-193 | En fjerning fra revisjonens omfang som avvises (`errors.audit`, «kravet har kontrollhandlinger») vises også ved knappen og beskriver den. `trackRowAction` har fått valget `errorKey`, slik at en avvisning som kommer som valideringsfeil, kan vises ved raden. Varselet øverst er fortsatt eneste `role="alert"`. De andre `errors.audit`-kildene oppstår bare når revisjonens status har endret seg under brukeren; de vises i varselet øverst som før. |
| A11Y-195 | «Registrer funn» mens Nytt funn er åpent: fokus flyttes til første felt i det nystartede skjemaet. |
| A11Y-172 | Risiko: alle datoer følger appens språk (`riskDates.js`; `en-GB` / `nb-NO`), også i Trenger oppmerksomhet. |
| A11Y-105, -155 | Wiki: blokkredigeringens knapper og meldinger, «Oppdater», «I kø siden …», mobiletikettene i Kjøringer, «Fjern kildefilter», feilteksten i sletteforhåndsvisningen og tabellhodene i Wiki-sider. Admin: Varsler (inkl. stavefeilen «varseler»). 20 nye nøkler i begge språkfiler. |

Ingen ruter, kontrollere, validering, tilganger, data, migrasjoner eller forretningsregler er endret.

### 21.4 Systemisk restforhold: `disabled` under lagring

Etter 7A står 126 kontroller i 35 filer (Kvalitet, Anbud/AI, Notices, Ledelsens gjennomgåelse, Mål, admin av
brukere og avdelinger m.fl.) fortsatt `disabled` mens forespørselen går. Fokus faller da til `<body>` *under*
forespørselen. Når besøket er ferdig, setter `routeFocus.js` (A11Y-134) fokus tilbake på samme kontroll, og
skjema som avvises, flytter fokus til første feil. Virkningen er derfor forbigående (typisk under ett sekund):
en Tab trykket akkurat da starter øverst. Vurdering: kategori 3 – mulig avvik fra 2.4.3 i et kort tidsrom, ikke
en blokkerende barriere. Det er ikke rettet bredt i 7A for ikke å gjøre en generell omskriving; mønsteret
(`aria-disabled` + `BUSY_STYLE` + `submitWhenIdle`) finnes nå og kan tas modul for modul. Bør verifiseres med
skjermleser i 7B (hva leses når en lagreknapp trykkes).

### 21.5 Fortsatt åpne funn

| ID | Kategori | Status |
|---|---|---|
| A11Y-105, -155 (rest) | 1 for engelsk admin-UI, ellers 4 | Filament-driftssidene (`monitoring`, `ai-forbruk`, `ai-profitability`, `billing-overview`, `system-status`, høstingssidene, `csv-import`, `view-doffin-supplier`) har hardkodet norsk eller engelsk. Bare interne superadministratorer. De offentlige sidene og samtykketekstene er bare på norsk, og siden er `lang="no"`; det er innhold, ikke et 3.1.2-avvik. |
| §21.4 | 3 | Se over. |
| A11Y-160 | 6 | Tekst under 12 px i prosessdiagrammet; samme innhold i steglisten. |
| A11Y-186 | 6 | KPI: feilteksten ved blur skyver «Lagre» ned mellom klikk og slipp. Krever synlig layoutendring. |
| A11Y-127 | 6 | Delvis med vilje (Fase 6). |
| A11Y-126 | 5 | PDF ikke tagget; HTML-alternativet er lenket. |
| A11Y-145 | 5 | Se 21.7. |
| A11Y-154 | 4 | Se 21.6. |
| A11Y-162, -163, -194, -214, -215 | Informasjon | Uendret. |

### 21.6 A11Y-154 – alternativ tekst fra kildeinnhold

Fakta i koden: `DocumentTextExtractor` leser alternativ tekst fra Word (`wp:docPr` `descr`, ellers `title`).
`EnterpriseWikiImageBlockBuilder` viser den som `alt`; bilder klassifisert som dekorative får `alt=""`. Mangler
alternativ tekst i kilden, vises bildet med `alt=""` og figurteksten («Figur N» eller bildeteksten). Wiki har
allerede funntypen `planned_figure_alt_text_missing` for planlagte figurer uten alternativ tekst.

Rutine (innholdsarbeid, ingen kodeendring):

1. *Finne:* funn av typen `planned_figure_alt_text_missing` under Wiki → Kjøringer, og – for en samlet oversikt –
   bildeblokker der `image_data.alt_text` er tom og kategorien ikke er dekorativ.
2. *Rette:* dokumenteieren legger inn alternativ tekst i Word-kilden (Høyreklikk bildet → «Rediger alternativ
   tekst»), eller merker bildet som dekorativt, og laster opp dokumentet på nytt.
3. *Ikke:* generere beskrivelser med AI eller skrive dem i Wiki uten kilde. En beskrivelse skal komme fra den som
   kjenner bildet.
4. Vurderes senere: en egen advarsel i kvalitetskontrollen for alle bildeblokker uten beskrivelse (krever
   beslutning; endrer lint-reglene).

### 21.7 Produktbeslutninger (ikke kodeoppgaver)

- **A11Y-145 – «Velg ansvarlig» på AI-kravkortet.** Dialogen er tilgjengelig (`ActionDialog`), men kan ikke åpnes:
  ingen knapp kaller `openResponsiblePicker`. Koden er beholdt uendret. Beslutningen er om funksjonen skal
  tilbys (og hvor), eller fjernes. Det er ikke et WCAG-avvik så lenge den ikke vises.
- **A11Y-126 – tagget PDF.** Krever en annen PDF-motor enn dompdf. HTML-rapporten er det tilgjengelige
  alternativet og er lenket fra nedlastingen.
- **A11Y-154 – advarsel for bilder uten beskrivelse** (21.6 punkt 4).
- **§21.4 – bred overgang fra `disabled` til `aria-disabled`** i de øvrige modulene: om det skal tas nå eller
  modul for modul.

### 21.8 Tester i 7A

| Test | Resultat |
|---|---|
| Enhetstester (node 22, hele suiten) | 2065 bestått, 0 feilet, 0 hoppet over |
| `lint:a11y` | 0 feil, 0 advarsler |
| `npm run build` | Bestått |
| PHP, målrettet: hele `tests/Feature/Filament`, `WikiQualityTabRemovalTest`, fem oversettelsestester | 327 bestått, 0 feilet (OPPF-004 er grønn) |
| `a11y-navigation.spec.js:155`, sonde før/etter rettelse | Før: 2 av 8 besøk feil. Etter: 30 av 30 riktige (11 med sen tittel) |
| `wiki-graph-labels.spec.js:82`, `--repeat-each=10` | 10 bestått, 0 feilet |
| E2E, målrettet (20 spesifikasjoner for de berørte flytene) | 125 bestått, 0 feilet, 0 hoppet over (13,4 min; `a11y-navigation` hele filen inkludert) |

Full PHP- og E2E-suite er ikke kjørt (Fase 7C). Ingen tester er deaktivert, ingen påstand er svekket, og ingen
timeout er økt. Tilpassede eksisterende tester: `wikiReviewPanel.test.js`, `wikiSourceUpload.test.js`,
`complianceLibrary.test.js` (krever nå `aria-disabled` + vakt i stedet for `disabled` under lagring),
`complianceAudit.test.js` (fjernknappen kan ligge i en omslutning med feilteksten), `WikiQualityTabRemovalTest`,
`CustomerCreatePageTest`, `wiki-graph-labels.spec.js:82`. Ny: `Support/a11yPhase7.test.js`.

### 21.9 Krever manuell testing (til 7B)

1. Skjermleser (VoiceOver + Safari, NVDA hvis tilgjengelig): sideskifte Kunngjøringer → Saksliste leser ny h1
   én gang; `aria-disabled`-knappene i Wiki-gjennomgangen og Etterlevelse leses som utilgjengelige mens de lagrer
   og beholder fokus; «Ferdigstill» leser grunnen; feilen ved «Fjern fra omfanget» leses som beskrivelse når man
   går tilbake til knappen; artikkelfeilen i Kvalitet leses én gang.
2. Mus: grafens relasjonstooltip kan nås med pekeren og lukkes med Escape; den står stille.
3. Engelsk UI: Risiko-datoer og Wiki-tekstene over.
4. Det systemiske restforholdet i 21.4.
5. Alt som står i §15.8.7, §16.8, §17.8, §18.9, §19.12 og §20.11.

### 21.10 Git-status

Branch `feature/wcag-accessibility`, HEAD `c7feb597` (uendret). 39 endrede filer (inkl. denne planen) og 2 nye, ikke
committet og ikke pushet. Ingen filer i `database/`, `routes/`, `config/` eller `app/`.

### 21.11 Vurdering

OPPF-001–004 er rettet; OPPF-001 med påvist årsak i appen og en rettelse der, ikke i testen. Ni åpne WCAG-funn
er rettet (A11Y-153, -156, -161, -172, -184, -193, -195, -196 og Wiki/Varsler-delen av -105/-155). Det som står
igjen, er klassifisert: ett systemisk forbigående forhold som bør verifiseres manuelt, intern admin-tekst,
forbedringer utover AA, innhold og fire produktbeslutninger. Fase 7A er ikke manuelt verifisert, full suite er
ikke kjørt, og resultatet gir ikke grunnlag for å hevde WCAG 2.2 AA-etterlevelse. **Fase 7B er ikke startet.**

---

## 22. Fase 7B – praktisk tilgjengelighetstesting, første runde (2026-10-16)

### 22.1 Formål og avgrensning

Dokumentere den første manuelle testrunden, vurdere hva den faktisk verifiserer, og peke ut de kontrollene som
fortsatt mangler før Fase 7C. Ingen kodeendringer, ingen nye automatiske testkjøringer, ingen ny kartlegging.
Resultatene fra Fase 7A (§21) er uendret.

Grunnlaget er **brukerens muntlige tilbakemeldinger**. Det finnes ikke detaljerte testlogger, videoopptak eller
skjermlesertranskripsjoner, og det er ikke registrert nøyaktig hvilke sider og handlinger som ble prøvd utover
det som står under. Dette er en praktisk stikkprøve, ikke en formell tilgjengelighetsrevisjon.

### 22.2 Testmiljø

| | |
|---|---|
| Kode | `feature/wcag-accessibility`, HEAD `c7feb597` + ucommittede Fase 7A-endringer (§21.10) |
| Maskin og OS | Mac, macOS (versjon ikke registrert) |
| Nettleser | Safari (versjon ikke registrert) |
| Hjelpemiddel | VoiceOver (macOS, slått på under Systeminnstillinger → Tilgjengelighet → VoiceOver) |
| Zoom | Safaris egen sidezoom, 200 % og 400 % |
| Data | Utviklingsmiljøet (`localhost`), ikke produksjonsdata |

### 22.3 Manuelt gjennomførte kontroller

1. **Tastaturnavigasjon.** Tastaturet virket først ikke. Årsaken var Safaris egen innstilling: som standard
   flytter Tab bare mellom skjemafelt, ikke lenker og knapper, før tastaturnavigasjon slås på i Safari
   (Innstillinger → Avansert, «Trykk Tab for å utheve hvert objekt på en nettside», eventuelt sammen med
   macOS-innstillingen for tastaturnavigasjon). Etter at innstillingen ble slått på, virket grunnleggende
   betjening, navigasjon mellom sider og fokus ved sideskift så riktig ut. Startproblemet er nettleserens
   standardoppførsel og **registreres ikke som en feil i Procynia**.
2. **Dialog.** Hjelpedialogen fra PageHelp-knappen ble åpnet og brukt med tastatur uten problemer.
3. **Zoom.** Etter veiledning om hvordan zoomnivået kontrolleres: 200 % og 400 %, uten rapporterte problemer.
4. **VoiceOver.** VoiceOver med Safari i Enterprise Wiki: opplesing og navigasjon virket.

### 22.4 Resultater

| Kontroll | Miljø | Resultat | Grunnlag |
|---|---|---|---|
| Grunnleggende tastaturnavigasjon | Safari, macOS | Fungerer | Manuell, brukerens tilbakemelding |
| Fokus ved sidenavigasjon | Safari, macOS | Ser riktig ut | Manuell, brukerens tilbakemelding |
| PageHelp-dialog | Safari, macOS | Ingen problemer rapportert | Manuell, brukerens tilbakemelding |
| Zoom 200 % | Safari, macOS | Ingen problemer rapportert | Manuell, brukerens tilbakemelding |
| Zoom 400 % | Safari, macOS | Ingen problemer rapportert | Manuell, brukerens tilbakemelding |
| Grunnleggende VoiceOver-navigasjon | VoiceOver + Safari | Fungerer | Manuell, brukerens tilbakemelding |

**Ingen nye feil er avdekket.**

Hva dette tilfører utover Fase 1–7A: det er første gang noe er prøvd i **ekte Safari**, med **nettleserens egen
zoom** og med **skjermleser**. Tidligere var alt dette uttrykkelig «ikke kontrollert» (§19.11, §20.10). Det
styrker særlig 2.1.1 (tastatur), 2.4.3 (fokusrekkefølge ved sideskift), 2.4.7 (synlig fokus i Safari),
1.4.4/1.4.10 (zoom/reflow, overordnet) og 4.1.2 (navn og rolle, grunnleggende) – på de prøvde flatene.

### 22.5 Kjente begrensninger

- **Utvalg:** bare de prøvde navigasjonene, én dialog (PageHelp) og Wiki for VoiceOver. Fokusfelle og fokusretur
  i dialogen er ikke kontrollert særskilt. Andre dialoger (`ActionDialog`), skjemaer, feilmeldinger og
  statusmeldinger er ikke manuelt prøvd.
- **Dokumentasjon:** ingen logg over sider, handlinger eller hva VoiceOver leste; versjoner er ikke registrert.
- **Zoom:** en overordnet kontroll, ikke full reflow-verifisering av alle moduler, bredder og innholdstyper
  (brede tabeller, prosessdiagrammet, grafen).
- **Nettlesere og hjelpemidler:** bare Safari og VoiceOver på macOS. Ikke Firefox, ikke Windows/NVDA, ikke
  forced-colors/høykontrast, ikke mobil (VoiceOver iOS, TalkBack).
- **Engelsk grensesnitt** er ikke prøvd manuelt.

### 22.6 Gjenstående testbehov

Prioritert etter risiko. Fase 1–7A har automatisk dekning (E2E med axe og tastatur i Chromium) for alt under;
det som mangler, er hvordan det faktisk oppleves i Safari og med skjermleser. Det kreves ikke en ny full manuell
runde gjennom alle moduler.

**Nødvendige før endelig WCAG-vurdering (7C)** – hver er en kort, konkret kontroll med VoiceOver + Safari:

1. **Lagring og `aria-disabled`/`disabled`** (2.4.3, 4.1.2; §21.4, A11Y-156/-196). To representative tilfeller:
   (a) en knapp som nå er `aria-disabled` – «Send til gjennomgang» i Wiki-gjennomgangen eller «Lagre» i et
   Etterlevelse-skjema: fokus blir på knappen og VoiceOver sier at den er utilgjengelig/dimmet mens den lagrer;
   (b) en av de 126 som fortsatt er `disabled` – f.eks. «Lagre» i et Kvalitet- eller Mål-skjema: hva skjer med
   fokus og opplesing under og etter lagringen. Resultatet avgjør om §21.4 kan stå som forbigående, eller må
   rettes før merge.
2. **Skjemavalidering** (3.3.1, 3.3.2, 4.1.3). Send ett skjema med en tom påkrevd verdi (f.eks. ny risiko eller
   nytt avvik): fokus går til feltet, og VoiceOver leser etiketten, «ugyldig» og feilteksten én gang.
3. **Wiki-gjennomgang og publisering** (akseptansekriteriet i §8 Fase 5). Send en side til gjennomgang og send
   den tilbake eller godkjenn den som kontrollør, med tastatur og VoiceOver: dialogens tittel og beskrivelse
   leses, fokus er fanget i dialogen og kommer tilbake etterpå, og toasten leses.
4. **AI-statusmeldinger** (4.1.3; §18.9). Start kravuttrekk eller «Lag svar» i et anbud: «arbeider» og «ferdig»
   (eller feil) leses én gang hver, ikke ved hver polling, og fokus flyttes ikke. Kan bare gjøres der AI er
   tilgjengelig i testmiljøet; ellers registreres den som ikke verifisert.

**Anbefalte** (styrker grunnlaget, men er ikke blokkerende hver for seg):

5. **Wiki-grafens listevisning** (1.1.1, 2.1.1; A11Y-050): «Graf | Liste», «Vis detaljer» og relasjoner med
   VoiceOver. VoiceOver i Wiki er allerede prøvd generelt; listen spesielt er ikke det.
6. **Firefox (macOS)**: fokusringen i prosessdiagrammet (A11Y-128) og at interne lenker flytter fokus (A11Y-015).
7. **Engelsk UI**: én side i Risiko og Wiki (A11Y-172, -105).
8. **Grafens relasjonstooltip med mus** (A11Y-153): kan nås med pekeren og lukkes med Escape.
9. **NVDA + Firefox/Chrome på Windows**: anbefales før en påstand om *bred* skjermlesertilgjengelighet
   (§9.3). Mangler miljøet, rapporteres den som **ikke gjennomført** i 7C, og påstanden avgrenses til
   VoiceOver + Safari.
10. **Forced-colors / Windows høykontrast og mobil (VoiceOver iOS)**: ikke gjennomført; rapporteres som
    ikke verifisert dersom de ikke tas.

### 22.7 Åpne WCAG-avvik (uendret av 7B)

Ingen avvik lukkes på grunnlag av 7B. Fra §21.5:

| ID | Status |
|---|---|
| §21.4 – 126 kontroller `disabled` under lagring | Åpent, mulig forbigående avvik fra 2.4.3. Skal undersøkes manuelt (22.6 punkt 1). Ikke massekonvertert. |
| A11Y-126 – PDF ikke tagget | Åpent. Gjelder dersom PDF-en (Ledelsens gjennomgåelse) er omfattet av kravene; i så fall et registrert avvik med HTML-rapporten som tilgjengelig alternativ. |
| A11Y-105/-155 (rest) | Åpent for admin-driftssidene (interne superadministratorer). |
| A11Y-154 | Åpent innholdsforhold (rutine i §21.6). |
| A11Y-160, -186, -127 | Forbedringer utover AA, åpne. |
| OPPF-001 | Rettet med påvist årsak (§21.2), **ikke formelt lukket** før tre påfølgende grønne samlede regresjonskjøringer i 7C. |

### 22.8 Beslutningspunkter (som før)

- **A11Y-145 «Velg ansvarlig»:** ikke aktivert og ikke slettet. Produktbeslutningen er utsatt.
- **A11Y-126:** dagens PDF-løsning beholdes. Manglende tagging står som åpent avvik dersom PDF-ene er omfattet.
- **A11Y-154:** advarsel om manglende alternativ tekst planlegges som en egen forbedring. Dokumentimporten
  endres ikke nå.
- **§21.4:** ingen massekonvertering. Representative tilfeller undersøkes manuelt (22.6 punkt 1).

### 22.9 Anbefaling om neste steg

Første runde gir et reelt, positivt manuelt grunnlag for grunnleggende tastatur, fokus ved sideskift, zoom og
VoiceOver i Safari, og avdekket ingen nye feil. Den dekker ikke de dynamiske delene der risikoen er størst.
Anbefaling: gjennomfør **kontroll 1 (lagring/`aria-disabled`) og kontroll 2 (skjemavalidering)** i 22.6 før
Fase 7C – de tar noen minutter hver og avgjør om §21.4 kan bli stående. Kontroll 3 og 4 kan gjøres parallelt
med 7C. Det som ikke blir gjort, rapporteres som ikke verifisert i WCAG-matrisen i 7C. Prosjektet er **ikke**
godkjent som WCAG 2.2 AA-samsvarende. Ingenting er committet; **Fase 7C er ikke startet.**

---

## 23. Avslutning av utviklingsarbeidet (2026-10-16)

Prosjektbeslutning 2026-10-16: den aktive utviklingen av universell utforming avsluttes nå, og Fase 7C gjennomføres
ikke. Fase 7A og 7B committes og branchen pushes. Merge til `main` besluttes separat.

**Gjennomført**

- Fase 1–6: implementert, automatisk verifisert, sluttkontrollert og committet (`4c594ebf` … `c7feb597`).
- Fase 7A: OPPF-001–004 og ni WCAG-funn rettet, verifisert med målrettede tester (§21.8: enhet 2065/0, `lint:a11y`
  0, bygg, målrettet PHP 327/0, målrettet E2E 125/0).
- Fase 7B: første praktiske runde dokumentert – tastatur, fokus ved sideskift, PageHelp-dialog, zoom 200/400 % og
  VoiceOver i Safari på macOS, uten rapporterte problemer (§22). Bygger på brukerens tilbakemeldinger, uten logger.

**Ikke gjennomført**

- Fase 7C (samlet regresjon, WCAG 2.2 A/AA-matrise og merge-vurdering) gjennomføres ikke.
- Full PHP- og E2E-regresjon er **ikke** kjørt på den endelige Fase 7-koden. Siste samlede E2E-kjøring er fra
  sluttkontrollen av Fase 6 (§20.16.2: 255/1/0, feilen var OPPF-001).
- De manuelle kontrollene i §22.6 (lagring/`aria-disabled`, skjemavalidering, Wiki-gjennomgang, AI-status m.fl.),
  Firefox, NVDA/Windows, forced-colors og mobil er ikke verifisert.

**Står åpent**

- **OPPF-001:** rettet med påvist årsak (§21.2), men **ikke formelt lukket** – kriteriet om tre påfølgende grønne
  samlede regresjonskjøringer (§17.12.9) er ikke oppfylt.
- **WCAG-funn:** alt i §21.5 og §22.7 står som åpent: §21.4 (126 kontroller `disabled` under lagring), A11Y-105/-155
  (rest), A11Y-126, A11Y-154, A11Y-160, A11Y-186, A11Y-127 og informasjonspunktene.
- **Beslutningspunkter:** A11Y-145, A11Y-126, A11Y-154 og §21.4 (§22.8).
- **Ikke verifisert:** alt som står som manuell testing i §15.8.7, §16.8, §17.8, §18.9, §19.12, §20.11 og §22.6.

**Vurdering.** Procynia har fått omfattende, automatisk verifiserte tilgjengelighetsforbedringer og en første
praktisk kontroll med skjermleser. At utviklingsarbeidet avsluttes, innebærer **ikke** at full etterlevelse av
WCAG 2.2 AA er dokumentert. Før en slik påstand (f.eks. i en tilgjengelighetserklæring) må full regresjon kjøres,
OPPF-001 lukkes etter kriteriet, de nødvendige kontrollene i §22.6 gjennomføres og de åpne funnene rettes eller
begrunnes.
