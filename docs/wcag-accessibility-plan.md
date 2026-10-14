# Plan: Universell utforming (WCAG 2.2 AA)

**Status: Fase 0 er ferdig. Fase 1 er implementert, automatisk verifisert og teknisk sluttkontrollert (ikke committet; manuell verifisering med skjermleser, Firefox og Safari gjenstår, se §15.8). Fase 2–7 er ikke startet.**
Branch: `feature/wcag-accessibility`
Utgangspunkt: `bc9070c2` (2026-10-14)
Sist oppdatert: **2026-10-14**

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
| 1 | Felles komponenter og designregler | Implementert, automatisk verifisert og teknisk sluttkontrollert 2026-10-14. Manuell verifisering gjenstår (§15.8). | Ikke committet |
| 2 | Navigasjon, layout og standardvisninger | Ikke startet | — |
| 3 | Skjema, tabeller, dialoger og varsler | Ikke startet | — |
| 4 | Anbud, dokumenter og AI-arbeidsflyter | Ikke startet | — |
| 5 | Enterprise Wiki og editor | Ikke startet | — |
| 6 | Kvalitet/GRC, administrasjon og resterende flater | Ikke startet | — |
| 7 | Samlet testing, regresjon og dokumentasjon | Ikke startet | — |

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
| Sidetitler | `CustomerAppLayout` setter `<Head title>`, og alle 53 sidene i layouten sender `title`. Det samme gjør `PublicLayout` og `Login`. |
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
| A11Y-019 | Inputkanter `border-slate-200` (1,23:1), ugyldig `border-rose-400` | Feltgrensene er knapt synlige. | 1.4.11 | S | Hvilekant `slate-400` (eller mørkere) i `formStyles.js`, `rose-600` for ugyldig. | V |
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
| Kanter og ikoner (ikke-tekst) | Minst 3:1: `slate-400` på hvitt er grensen, og `slate-500` anbefales. |
| Status | Alltid tekst (synlig eller `sr-only`) i tillegg til farge og ikon. |
| Z-index | Header 60, overlegg og dialog 70, toast og kunngjøring 80. |
| Trykkflate | Minst 24×24 px for interaktive mål. |
| Skjemafelt | Alltid via `FormField`. Feil gir `aria-invalid` + `aria-describedby`. Første feil fokuseres. |
| Dialoger | Alltid `ActionDialog`/`useConfirm`. Ingen nye `fixed inset-0`-modaler. |
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
- Reflow ved 320 px / 400 % for Notices, AI-arbeidsflaten, tilgangsmatrisen og
  Kildedokumenter-tabellen (`table-fixed`, 1060 px).
- Om andre Wiki-generatorer enn ingest også starter med `# tittel`, og om GFM-tabeller forekommer i
  artikler (A11Y-046, -114).
- Trykkflater med avstandsunntak (A11Y-106).
- Hvordan `wire:poll` i Filament påvirker fokus og skjermleser.
- Tagging av PDF (forventet: ikke tagget).
- Om polling (`router.reload`) noen gang flytter fokus eller nullstiller felt mens brukeren skriver.

**Beslutninger som trengs før eller i fasene:**

1. Godkjenning av dev-avhengighetene i §9.1 (før Fase 1).
2. Filament mørk modus: legge til støtte eller deaktivere den (Fase 6).
3. Om `window.confirm` skal erstattes overalt (A11Y-107, ikke et AA-krav).
4. Om offentlige sider som er utkast (Security, Privacy, Terms) tas med nå eller når innholdet er
   endelig.

---

## 14. Endringslogg

| Dato | Endring |
|---|---|
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
