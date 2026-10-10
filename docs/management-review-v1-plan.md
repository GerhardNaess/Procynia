# Plan: Ledelsens gjennomgåelse (v1)

**Implementeringsstatus: Implementert og testet på `feat/management-review` (ikke committet, ikke merget).**
Sist statusgjennomgått: **2026-10-09**
Utgangspunkt: `main` @ `87ae5237` (2026-10-09)

Planen ble skrevet før implementeringen (§1–§16). **§17–§21 beskriver det som faktisk er bygget, og
går foran §1–§16 der de er uenige.** Avvikene fra planen er samlet i §17.1. Beslutninger merket
«— låst» endres bare ved en ny, eksplisitt beslutning, ikke ved nytolkning underveis.

---

## 1. Formål og funksjonelt omfang

### 1.1 Mål

Ledelsens gjennomgåelse er stedet der ledelsen samlet vurderer styringssystemet, med et grunnlag
som allerede finnes i Procynia. Modulen skal:

1. hente styringsinformasjon fra Kvalitet, Risiko, Mål og KPI, Avvik og forbedringer, Etterlevelse
   og revisjon og Leverandøroppfølging, uten dobbeltregistrering;
2. vise hvor det trengs oppmerksomhet;
3. dokumentere ledelsens egne vurderinger, konklusjoner og beslutninger eksplisitt;
4. sende tiltak videre til eksisterende oppfølging (Avvik og forbedringer → «Mine oppgaver», bjelle
   og påminnelser), uten en parallell oppgavemodell;
5. fryse beslutningsgrunnlaget ved ferdigstilling, slik at det i ettertid kan dokumenteres hva
   ledelsen faktisk så;
6. gi et samlet, lesbart dokument (utskrift og PDF).

Modulen er en del av posisjoneringen «styringssystemet for virksomheter som lever av anbud»:
kontroll, ansvar, sporbarhet og beslutningsstøtte. Den er ikke et møteverktøy.

### 1.2 Innhold i en gjennomgåelse

| # | Innhold | Kilde i v1 |
|---|---|---|
| 1 | Formål, periode, dato og deltakere | Egne felt |
| 2 | Status for tidligere beslutninger og tiltak | Tidligere gjennomgåelser + Avvik og forbedringer (live) |
| 3 | Mål og KPI | Mål og KPI (automatisk) |
| 4 | Vesentlige risikoer og endringer i risikobildet | Risiko (automatisk) |
| 5 | Avvik, forbedringer og trender | Avvik og forbedringer (automatisk) |
| 6 | Kontroller, etterlevelse og revisjoner | Etterlevelse og revisjon + kontroller i Kvalitet (automatisk) |
| 7 | Kvalitetsarbeid og observasjoner | Kvalitet (automatisk) |
| 8 | Leverandørforhold | Leverandøroppfølging (automatisk) |
| 9 | Endringer i interne og eksterne forhold | Ledelsens egen tekst + støttedata fra Etterlevelse |
| 10 | Ressurser, kompetanse og forbedringsbehov | Ledelsens egen tekst |
| 11 | Ledelsens vurderinger og konklusjoner | Vurdering per seksjon + samlet konklusjon |
| 12 | Beslutninger, tiltak, ansvarlige og frister | Egne beslutninger; tiltak følges opp i Avvik og forbedringer |

### 1.3 Produktprinsipper

1. **Ingen kopiert funksjonalitet.** Data leses fra modulens egen tilgangstjeneste. Tiltak følges
   opp i Avvik og forbedringer. Modulen har ingen egen oppgavemodell.
2. **Grunnlaget beregnes, vurderingen registreres.** Det som kan beregnes, skrives aldri inn av
   brukeren. Det ledelsen mener, skrives alltid inn av et menneske.
3. **Ett øyeblikksbilde ved ferdigstilling.** Utkast viser alltid levende data. Ferdigstilt viser
   alltid det frosne grunnlaget, og det leses aldri som «tilstanden nå».
4. **Tilgang følger kilden.** En gjennomgåelse gir aldri tilgang til data brukeren ikke ellers kan
   lese, heller ikke i øyeblikksbildet (§8).
5. **Få felt, ingen tvang.** Ingen obligatoriske fritekstfelt per seksjon. Kravet for å ferdigstille
   er en vurdering (tre nivåer) per seksjon, en samlet konklusjon og oppfølging på alle tiltak.
6. **Generelt først, standard som tillegg.** Seksjonene er generelle. ISO 9001 og ISO 27001 er
   valgbare rammeverk som legger til en dekningssjekk og eventuelt en ekstra seksjon (§3.4).
7. **Ingen AI i v1.** Ledelsens vurderinger og beslutninger er eksplisitte og sporbare. AI-støtte er
   en senere vurdering (§13).

---

## 2. Funn fra eksisterende kodebase

### 2.1 Samlet bilde

- **Ingenting finnes fra før.** Ingen kode, ingen lang-nøkler og ingen dokumentasjon. Uttrykket
  «ledelsens gjennomgang» finnes bare som eksempeltekst i tester og i en kommentar i migrasjonen
  `2026_10_03_000005_add_control_evidence_to_quality_item_documents_table.php:17`.
- **Hver styringsmodul har en tilgangstjeneste og en oppmerksomhetstjeneste som kan gjenbrukes
  direkte.** Oppmerksomhet beregnes ved lesing og lagres aldri. Dette er hovedbyggesteinene for
  beslutningsgrunnlaget.
- **Ingen styringsmodul har eksport, utskrift eller PDF i dag.** `dompdf/dompdf` og
  `phpoffice/phpword` er installert, men brukes bare i anbudsdelen (§11).
- **Mønsteret for uforanderlighet er modent og konsistent.** Modellen kaster `LogicException`, og
  PostgreSQL-triggeren lages med `CREATE OR REPLACE FUNCTION`. Det tillater sletting når kunden
  slettes, og at bruker-FK settes til null. Mønsteret kopieres direkte (§10).

### 2.2 Per modul

| Modul | Tilgang (inngang for andre moduler) | Oppmerksomhet | Periodedata som finnes | Hull |
|---|---|---|---|---|
| **Kvalitet** | `CustomerPermissionService::has(user, quality.view)`; kundeglobal | `QualityAttentionService::findings(customerId)`: kontroller uten bevis, kontroller uten aktivitet, prosesser uten styrende policy, prosesser med passert revisjonsdato | `QualityProcessRevision.approved_at` (godkjente prosessrevisjoner); `quality_item_documents.created_at` med `relation_type=evidence` (kontrollbevis) | Ingen gjennomføringsdato og ingen bestått/ikke bestått på kontroller; bare siste revisjon (`last_reviewed_at`), ingen historikk |
| **Risiko** | `RiskAccessService::visibleRisks(user)`; fagområde | `RiskAttentionService::overview(user, today)` / `findingsForRisks()` | `risk_assessments.assessed_at` (uforanderlig, med inherent og residual score); akseptanser (`accepted_at`/`revoked_at`/`valid_until`); tiltak (`created_at`/`completed_at`/`due_at`); `risks.created_at` | Ingen historikk for status, eier eller behandlingsstrategi; gjenåpning av tiltak nuller `completed_at` |
| **Mål og KPI** | `ObjectiveAccessService::visibleObjectives()` / `visibleKpis()`; fagområde | `ObjectiveAttentionService::overview()`; `detail` er **ferdig oversatt tekst** | `kpi_measurements` med periode og snapshot av målverdi; `KpiMeasurementResolver`, `KpiTargetPolicy`, `KpiPeriods`, `KpiPresenter`; `objective_status_changes` / `kpi_status_changes` | Ingen ferdig tjeneste for «KPI-status i periode X–Y». Den settes sammen av eksisterende byggeklosser |
| **Avvik og forbedringer** | `ImprovementCaseAccessService::visibleCases()`; fagområde | `ImprovementAttentionService::overview()`; `ImprovementActionVerificationResolver` | `improvement_cases.created_at`; `improvement_case_status_changes` / `improvement_action_status_changes` (uforanderlige); `improvement_action_verifications` | `closed_at` overskrives ved gjenåpning, så periodetall må leses fra historikken; ingen indeks `(customer_id, changed_at)` på historikktabellene |
| **Etterlevelse og revisjon** | `ComplianceAccessService::canReadFromAnotherModule()` + `visibleRequirements()` / `visibleAudits()`; kundeglobal, **krever eksplisitt tildeling** | `ComplianceAttentionService`, `ComplianceAuditAttentionService`, `ComplianceStatusResolver::summarize()` | `compliance_assessments` (append-only); `compliance_audit_status_changes` (faktiske datoer); `compliance_audit_findings.created_at` / `handed_off_at` | Status finnes bare «nå»; resolveren har ingen «per dato»-variant |
| **Leverandører** | `SupplierAccessService::canReadFromAnotherModule()` + `visibleSuppliers()`; kundeglobal, **krever eksplisitt tildeling** | `SupplierAttentionService::overview(user, today)`: signal 1–11 | `supplier_assessments.assessed_on`, `supplier_assurance_decisions` (jsonb `state_snapshot`), `supplier_requirement_evaluations`, `supplier_criticality_changes`, `supplier_due_diligence_assessments` | Åpne avvik hos leverandør krysser tilgangsgrensen (§15.2 i v2-planen), og må leses via `ImprovementCaseAccessService` |
| **Enterprise Wiki** | `wiki.view` + modul; `SupplierRequirementWikiGuidance::canRead()` er mønsteret | — | — | Ikke en datakilde i v1. Kunnskapsoverføring (`WikiKnowledgeHandoffService`) kan komme senere (§13) |

### 2.3 Felles infrastruktur

- **Mine oppgaver.**
  - Grensesnittet `MyTaskSource` har tre metoder: `module()`, `isAvailableFor()` og `openTasksFor()`.
  - Kildene registreres i konstruktøren til `MyTasksService`, og rekkefølgen i arrayen på linje 42
    skal følge railen.
  - Oppgaver leses levende og lagres aldri (`docs/notifications-and-tasks-plan.md` §1–2).
  - Påminnelser (`TaskDeadlineReminderService`, daglig kl. 06:45 UTC) virker automatisk når
    `subject.prefix` og metadata-id er satt.
  - `GovernanceTaskCard` dekker nye moduler uten nytt kort.
- **Bjelle.**
  - Hendelsestypen er `"<prefix>.<event>"`.
  - `UserNotificationAccessScope::GATED_PREFIXES` må få det nye prefikset. Ellers blir varslene
    behandlet som kontonivå og er alltid synlige.
  - `AssignmentNotifier::MODELS` gir eiervarsel automatisk via `AssignmentObserver`.
- **Tilganger.**
  - `CustomerPermissionCatalog::domains()` er eneste kilde. Tilganger-siden bygges automatisk fra den.
  - `explicitGrantDomains()` er i dag `[compliance, supplier]`.
  - `areaScopedDomains()` er i dag `[risk, objective, improvement]`. Disse løses via
    `BusinessAreaGrants`: rettighet og fagområde må komme fra samme rolle. «Alle» løses ved lesing.
    System Owner får aldri fagområder implisitt.
- **Moduler og navigasjon.** Disse må holdes konsistente med hverandre:
  - `config/procynia_modules.php` (`modules`, `packages`, `bundles`, `route_modules`);
  - `GovernanceController::MODULES`;
  - `appModules.js`.

  `GovernanceControllerTest` krever samme rekkefølge.
- **Overleveringsmønster til Avvik.** `ComplianceAuditFindingHandoffService` og
  `SupplierImprovementHandoffService` gjør det samme:
  - saken opprettes via `ImprovementCaseCreator`;
  - proveniens lagres på kildesiden;
  - `provenanceFor(user, case)` vises på saken bare når brukeren kan lese kilden;
  - `ImprovementCase::isDeletable()` nekter sletting når kilden peker på saken.

  Det finnes ingen generisk polymorf opprinnelse, og hver kilde har sin egen koblingstabell.
- **Øyeblikksbilde.** `SupplierAssuranceDecision.state_snapshot` er det nærmeste mønsteret:
  - `jsonb`-kolonne med CHECK `jsonb_typeof = 'object'`;
  - trigger som beskytter kolonnen;
  - beregnes på nytt inne i transaksjonen ved skriving;
  - projiseres bevisst via `SupplierAssuranceResolver::snapshot()`;
  - «vises bare som historikk».

### 2.4 Ucommittet arbeid som ikke skal berøres

Hovedmappen har ucommittede endringer i Tilganger og tilhørende filer. Planen forutsetter at dette
arbeidet committes før fase 1:

- `CustomerRolesPanel.jsx`, `Index.jsx`, `PermissionSection.jsx` og `permissionSections.js`;
- lang-filene;
- 14 E2E-spesifikasjoner.

Når det er inne, får det nye domenet i tillegg en `customer_env.roles.sections.descriptions.<domain>`
og en `DOMAIN_ICONS`-oppføring. Ellers vises seksjonen uten ikon og undertittel.

---

## 3. Anbefalt brukerreise

### 3.1 Arbeidsform — låst

**Seksjonsbasert arbeidsflate med veiledet rekkefølge, ikke veiviser.**

En veiviser passer dårlig fordi ledelsens gjennomgåelse gjøres i flere omganger: forberedelse,
møte og etterarbeid. Ulike personer arbeider dessuten med ulike seksjoner. Arbeidsflaten har derfor:

- faste seksjoner i en fast rekkefølge;
- en fremdriftsliste («Klar for ferdigstilling») som peker på neste ting som mangler;
- en «Neste seksjon»-knapp, som gir veiviserens fordel uten låst rekkefølge.

### 3.2 Reisen

| Steg | Hvem | Hva skjer |
|---|---|---|
| A. Opprett | Ansvarlig for gjennomgåelsen | «Ny gjennomgåelse». Felt som fylles ut på forhånd: tittel «Ledelsens gjennomgåelse 2026», periode fra dagen etter forrige ferdigstilte periode til i dag (første gang: siste 12 måneder), avgrensning «Hele virksomheten», rammeverk fra forrige gjennomgåelse, og ansvarlig = meg. Én dialog med fem felt |
| B. Forbered | Ansvarlig / redaktører | Arbeidsflaten åpnes på «Oversikt»: hvilke seksjoner som har oppmerksomhetspunkter, og hva som mangler. Grunnlaget i hver seksjon er allerede hentet. Deltakere legges til |
| C. Del forhåndsmateriale | Ansvarlig | «Rapport» viser utkastet som dokument, merket UTKAST, til utskrift eller PDF |
| D. Møtet | Ledelsen (én skriver) | Seksjon for seksjon: les grunnlaget, sett vurdering (Tilfredsstillende / Bør forbedres / Ikke tilfredsstillende), eventuell kommentar, og registrer beslutninger direkte i seksjonen |
| E. Etterarbeid | Ansvarlig | Tiltak sendes til Avvik og forbedringer (fagområde, ansvarlig, frist), eller kobles til en eksisterende sak. Samlet konklusjon skrives, og neste gjennomgåelse settes |
| F. Ferdigstill | Den som har `management_review.finalize` | Sjekklisten er grønn. Bekreftelsesdialogen viser hva som fryses, og om grunnlaget er begrenset av egen tilgang. Øyeblikksbildet lagres |
| G. Følg opp | Tiltakseiere | Tiltakene ligger i «Mine oppgaver» som ordinære avvik- og forbedringssaker. Saken viser «Fra Ledelsens gjennomgåelse 2026» |
| H. Neste gjennomgåelse | Ansvarlig | Påminnelse når fristen for neste gjennomgåelse nærmer seg. Seksjonen «Tidligere beslutninger» viser automatisk status på alt som ble besluttet sist |

### 3.3 Statuser i grensesnittet

Det finnes bare to lagrede statuser: **Utkast** og **Ferdigstilt** (§6). «Klar for ferdigstilling»
er ikke en status. Det er en beregnet merkelapp ved siden av «Utkast» når sjekklisten er oppfylt.

### 3.4 Generelle krav og standardspesifikke tillegg

**Generelle seksjoner** gjelder alltid. Seksjoner fra moduler kunden ikke har, eller som brukeren
ikke kan lese, vises ikke (§8):

| Nøkkel | Seksjon | Type |
|---|---|---|
| `previous_decisions` | Tidligere beslutninger og tiltak | Automatisk |
| `context_changes` | Endringer i interne og eksterne forhold | Manuell, med støttedata |
| `objectives` | Mål og KPI | Automatisk |
| `risks` | Risiko og risikobilde | Automatisk |
| `improvements` | Avvik, forbedringer og trender | Automatisk |
| `compliance` | Kontroller, etterlevelse og revisjoner | Automatisk |
| `quality` | Kvalitetsarbeid | Automatisk |
| `suppliers` | Leverandører | Automatisk |
| `resources` | Ressurser, kompetanse og forbedringsbehov | Manuell |

**Rammeverk** er valgbare per gjennomgåelse og definert i `config/management_review.php`. De gjør to
ting:

1. **Dekningssjekk.**
   - Hvert inputpunkt i standarden kobles til én eller flere seksjoner.
   - Dekningen vises som en liste på «Oversikt», for eksempel «ISO 27001 9.3.2 f — risikovurdering og
     risikobehandlingsplan → Risiko ✓».
   - Et punkt er dekket når seksjonen har en vurdering.
   - Punktene gjengis med egne, korte formuleringer, aldri med standardens tekst.
2. **Tilleggsseksjon.** Både ISO 9001 og ISO 27001 legger til `stakeholder_feedback`
   («Tilbakemeldinger fra kunder og interessenter», manuell). Uten rammeverk er seksjonen ikke med.

| Inputpunkt | ISO 9001 | ISO 27001 | Seksjon |
|---|---|---|---|
| Status på tiltak fra forrige gjennomgåelse | ✓ | ✓ | `previous_decisions` |
| Endringer i interne/eksterne forhold | ✓ | ✓ | `context_changes` |
| Endringer i interessentenes behov og forventninger | (via forhold) | ✓ | `context_changes` |
| Kundetilfredshet / tilbakemeldinger fra interessenter | ✓ | ✓ | `stakeholder_feedback` |
| Måloppnåelse | ✓ | ✓ | `objectives` |
| Prosessytelse og samsvar | ✓ | — | `quality` |
| Avvik og korrigerende tiltak | ✓ | ✓ | `improvements` |
| Måle- og overvåkingsresultater | ✓ | ✓ | `objectives`, `compliance` |
| Revisjonsresultater | ✓ | ✓ | `compliance` |
| Eksterne leverandørers ytelse | ✓ | — | `suppliers` |
| Ressursbehov | ✓ | — | `resources` |
| Effekt av tiltak for risiko og muligheter / risikobehandlingsplan | ✓ | ✓ | `risks` |
| Forbedringsmuligheter | ✓ | ✓ | Beslutninger |

Nye rammeverk (ISO 14001, ISO 45001) legges til i konfigurasjonen uten kodeendring.
Rammeverkdefinisjonen har en `version`, og øyeblikksbildet lagrer hvilken versjon som ble brukt. En
fremtidig revisjon av ISO 9001 endrer derfor ikke gamle gjennomgåelser.

---

## 4. Skjermbilder

Alle skjermbilder følger styringsmodulenes konvensjoner: `CustomerAppLayout`, `StatusBadge`,
`PageHelpButton`/`PageHelpPanel`, `text-base` (16 px), kort som `rounded-[24px]`, maks ett inline
panel åpent, og fanen i `?tab=`/`?section=` (som `supplierPage.js`).

### 4.1 Liste — `/app/management-reviews`

```
┌ Ledelsens gjennomgåelse                       [?] [+ Ny gjennomgåelse] ┐
│ ┌ Neste gjennomgåelse ─────────────────────────────────────────────┐   │
│ │ Planlagt innen 15.11.2026 (besluttet i «LG 2025»)    [Opprett]   │   │
│ └──────────────────────────────────────────────────────────────────┘   │
│ ┌ Åpne tiltak fra ledelsens gjennomgåelser (4) ────────────────────┐   │
│ │ Oppdater kompetanseplan · Avvik og forbedringer · frist 01.12 ⚠  │   │
│ │ …                                          [Se alle i Avvik →]   │   │
│ └──────────────────────────────────────────────────────────────────┘   │
│ [Gjennomgåelser] [Sammenlign over tid]                                  │
│ Tittel          Periode              Møtedato   Status       Ansvarlig  │
│ LG 2026         01.10.25–30.09.26    12.10.26   Utkast·Klar  Kari       │
│ LG 2025         01.10.24–30.09.25    14.10.25   Ferdigstilt  Kari       │
└─────────────────────────────────────────────────────────────────────────┘
```

- «Åpne tiltak» leses levende fra `ImprovementCaseAccessService::visibleCases()` via
  koblingstabellen. Saker brukeren ikke ser, telles ikke.
- «Sammenlign over tid» er en tabell med seksjoner som rader og de siste fire ferdigstilte
  gjennomgåelsene som kolonner. Hver celle viser vurderingsbadge og 1–2 nøkkeltall fra
  øyeblikksbildet, filtrert på lesers tilgang (§10.4).

### 4.2 Arbeidsflate — `/app/management-reviews/{id}`

```
┌ ← Ledelsens gjennomgåelse                                               ┐
│ [Utkast] [Klar for ferdigstilling]                                      │
│ Ledelsens gjennomgåelse 2026                                            │
│ Periode 01.10.2025–30.09.2026 · Hele virksomheten · ISO 9001, ISO 27001 │
│                         [?] [Rediger] [Rapport] [Ferdigstill]           │
├───────────────────┬─────────────────────────────────────────────────────┤
│ Oversikt        ● │  Seksjon: Risiko og risikobilde                     │
│ Tidl. beslutn.  ✓ │  ┌ Grunnlag ─────────────────────────────────────┐  │
│ Forhold         ✓ │  │ 42 risikoer · 3 høy/svært høy restrisiko      │  │
│ Mål og KPI      ⚠ │  │ Trenger oppmerksomhet: 2 forfalte tiltak, …   │  │
│ Risiko          ○ │  │ Endringer i perioden: 5 nye, 11 revurdert     │  │
│ Avvik           ○ │  │ [Tabell: høye risikoer → lenke til Risiko]    │  │
│ Etterlevelse    ○ │  └───────────────────────────────────────────────┘  │
│ Kvalitet        ○ │  ┌ Ledelsens vurdering ──────────────────────────┐  │
│ Leverandører    ○ │  │ (•) Tilfredsstillende ( ) Bør forbedres        │  │
│ Ressurser       ○ │  │ ( ) Ikke tilfredsstillende                     │  │
│ Beslutninger (3)  │  │ Kommentar (valgfri) [..........]               │  │
│ Rapport           │  └───────────────────────────────────────────────┘  │
│                   │  ┌ Beslutninger i denne seksjonen ───────────────┐  │
│                   │  │ + Registrer beslutning                        │  │
│                   │  └───────────────────────────────────────────────┘  │
│                   │                              [Neste seksjon →]      │
└───────────────────┴─────────────────────────────────────────────────────┘
```

- Venstre navigasjon blir en nedtrekksliste på mobil. Den har en markør per seksjon: ✓ vurdert,
  ⚠ har oppmerksomhetspunkter og er ikke vurdert, ○ ikke vurdert.
- **Oversikt** inneholder:
  - fremdriftslisten (§6.3);
  - deltakere (legg til / fjern; intern bruker eller navn + funksjon);
  - samlet oppmerksomhet per seksjon;
  - rammeverkdekning;
  - «Samlet konklusjon» (tekstfelt);
  - «Neste gjennomgåelse innen» (dato).
- **Grunnlag** viser for hver seksjon:
  - nøkkeltall;
  - oppmerksomhetspunkter fra modulens egen oppmerksomhetstjeneste;
  - hendelser i perioden;
  - en begrenset liste med lenker inn i kildemodulen. Lenken går til objektet, og objektet
    sjekker tilgang på nytt.
- Når brukerens fagområder ikke dekker avgrensningen, står det: «Du ser grunnlaget for dine
  fagområder.» Formuleringen «hele virksomheten» brukes bare når `reachesAllAreas()` er sann.
- **Beslutninger** er en samlet liste over alle beslutninger med type, seksjon og oppfølging.
  Oppfølgingen vises som «Sendt til Avvik og forbedringer · Sak #123 · Åpen» eller som knappene
  «Send til oppfølging» / «Koble til sak».

### 4.3 Registrer beslutning (inline panel)

| Felt | Regel |
|---|---|
| Type | «Beslutning» (ingen oppfølging, f.eks. «Kvalitetspolicyen videreføres») eller «Tiltak» |
| Beslutning | Tekst, påkrevd, maks 2000 |
| Seksjon | Forhåndsvalgt fra seksjonen panelet er åpnet i, kan endres |
| Ansvarlig, frist | Bare for «Tiltak». Foreslås, og brukes når tiltaket sendes til oppfølging |

«Send til oppfølging» åpner skjemaet `ComplianceAuditFindingHandoffService::formOptions()` bruker:
fagområde, ansvarlig og frist, forhåndsutfylt. Skjemaet oppretter en sak av type `improvement`.

### 4.4 Ferdigstilt visning

- Samme layout som arbeidsflaten, uten redigeringskontroller.
- Banner: «Ferdigstilt 14.10.2026 av Kari Nordmann. Grunnlaget viser situasjonen da gjennomgåelsen
  ble ferdigstilt.»
- Hver seksjon har lenken «Se nåsituasjonen i Risiko →».
- Tiltak viser to kolonner: «Ved ferdigstilling» (fra øyeblikksbildet) og «Nå» (levende, med
  tilgangssjekk).
- Panelet «Rettelser» viser tillegg registrert etter ferdigstilling (§6.5).

### 4.5 Rapport — `/app/management-reviews/{id}/report`

- Dokumentvisning tilpasset utskrift (§11).
- Knapper: «Skriv ut» og «Last ned PDF».
- Utkast får vannmerke og overskriften «UTKAST — ikke ferdigstilt».

---

## 5. Datakilder og integrasjoner

### 5.1 Felles arkitektur for innhenting — låst

```php
interface ManagementReviewSection   // app/Services/ManagementReview/Sections/
{
    public function key(): string;                    // 'risks'
    public function sourceDomain(): ?string;          // CustomerPermissionCatalog::DOMAIN_RISK, null = egen
    public function module(): ?string;                // 'risk', null = egen
    public function isAreaScoped(): bool;
    public function isAvailableFor(User $user): bool; // modul + lesetilgang via modulens tilgangstjeneste
    public function build(User $user, ReviewScope $scope): array; // strukturert, versjonert projeksjon
}
```

- `ReviewScope` er en readonly verdi med feltene `periodStart`, `periodEnd`,
  `areaIds|null` (`null` = hele virksomheten), `today` og `previousReview` (ferdigstilt, eller `null`).
- **Én kodevei** for levende visning og øyeblikksbilde. `ManagementReviewBasisService::build(user,
  review)` kaller alle tilgjengelige seksjoner. Visningen bruker resultatet direkte.
  Ferdigstilling lagrer det samme resultatet.
- **Strukturerte data, aldri oversatt tekst.** Payloaden har nøkler og rå verdier, for eksempel
  `{key:'kpi_off_target', count:2}`. Den har ikke `detail` fra `ObjectiveAttentionService`, som
  allerede er oversatt. Rapporten blir dermed lesbar på lesers språk også om fem år.
- **Fagområdedeling.** For fagområdestyrte kilder har alle elementer `area_id`. Aggregater lagres
  også `by_area`. Totalsummer regnes av leseren fra fagområdene den ser (§10.4).
- **Avgrensning.** `areaIds` filtrerer bare fagområdestyrte kilder (Risiko, Mål og KPI, Avvik).
  Kvalitet, Etterlevelse og Leverandører er kundeglobale. Seksjonen sier «Gjelder hele
  virksomheten» når en avgrenset gjennomgåelse viser dem.
- **Lister er begrenset.** Hver liste har maks 50 elementer, sortert etter alvor. `total` lagres
  alltid, og avkortingen vises («viser 50 av 73»).
- **Periode kontra tilstand.** Hver seksjon har to tydelig merkede blokker:
  - **«I perioden»**: hendelser med dato i `[periodStart, periodEnd]`, lest fra uforanderlige
    historikkrader.
  - **«Status nå»**: tilstand når grunnlaget ble hentet. For et ferdigstilt grunnlag er det
    ferdigstillingstidspunktet.

  Planen rekonstruerer ikke tilstand ved periodeslutt der kildedata ikke støtter det (Risiko-status,
  Kvalitet). Dette er ærlig og robust. Det ledelsen så, er det som fryses.
- **Ytelse.**
  - Hver seksjon bruker satsvise spørringer, samme mønster som `MyTasksGovernanceTest`, med
    konstant antall spørringer.
  - Utkast bygger grunnlaget ved hver visning.
  - De tyngste seksjonene sendes som Inertia deferred props (`Inertia::defer`, støttet i
    `inertia-laravel ^3.0`, ikke brukt i appen ennå), slik at arbeidsflaten åpner umiddelbart.
  - Ingen cache i v1.

### 5.2 Seksjon for seksjon

| Seksjon | Relevant informasjon | Hentes via | Periode / avgrensning | Tilgang | Presentasjon | Øyeblikksbilde |
|---|---|---|---|---|---|---|
| `previous_decisions` | Beslutninger fra ferdigstilte gjennomgåelser. Tiltak med koblet sak som er åpen, eller som er lukket etter forrige gjennomgåelse. Status, frist, forfalt, effektverifisering | `management_review_decisions` + `management_review_decision_cases` → `ImprovementCaseAccessService::visibleCases()`, `ImprovementActionVerificationResolver` | Alle tidligere ferdigstilte. Lukkede vises bare hvis de er lukket etter forrige `finalized_at` | `management_review.view` for beslutningen; `improvement.view` + fagområde for saksdetaljer. Utilgjengelig sak: «Oppfølgingen er ikke synlig for deg» | Tabell: beslutning · gjennomgåelse · ansvarlig · frist · status · effekt | Ja (status ved ferdigstilling) |
| `context_changes` | Ledelsens tekst. Støttedata: etterlevelseskrav opprettet eller utgått i perioden, nye eller endrede kilder (lov, standard, kontrakt) | `ComplianceAccessService::visibleRequirements()` + `compliance_requirement_status_changes.changed_at`, `compliance_sources.created_at/updated_at` | Periode | Tekst: `management_review.view`. Støttedata: Etterlevelse-gate | Tekstfelt + liste «Endringer i krav og kilder» | Ja |
| `objectives` | Aktive mål. Mål lukket i perioden (oppnådd/ikke oppnådd). Per KPI: målinger i perioden vurdert mot snapshot-mål (`KpiTargetPolicy`), siste resultat, serie for trend, manglende målinger (`KpiMeasurementSchedule::for(kpi, m, periodEnd)`). «X av Y KPI-er på mål» (`KpiPresenter::indicator`). Oppmerksomhet | `ObjectiveAccessService::visibleObjectives()` / `visibleKpis()`, `KpiMeasurementResolver::currentByPeriod()`, `objective_status_changes`, `ObjectiveAttentionService::findingsForObjectives()` (bare nøkler) | Målinger med `period_end` i perioden. Fagområde via objective | `objective.view` + fagområde, `objectives`-modul | Nøkkeltall, KPI-tabell med siste verdi og trendpil, lukkede mål | Ja |
| `risks` | Restrisikofordeling nå (`RiskAssessment::latestForRisks` + `RiskScoringPolicy`). Høy/svært høy restrisiko. I perioden: nye risikoer, vurderinger med endring i score mot forrige vurdering, akseptanser gitt, utløpt eller trukket, tiltak fullført. Forfalte tiltak og revisjoner (`RiskAttentionService::findingsForRisks`) | `RiskAccessService::visibleRisks()` | Hendelsesdatoer i perioden. Fagområde | `risk.view` + fagområde, `risk`-modul | Fordeling (4 nivåer), «Høyeste risikoer», «Endringer i perioden» | Ja |
| `improvements` | Registrert i perioden per type og fagområde. Lukket eller kansellert i perioden (fra `improvement_case_status_changes`). Åpne nå, forfalte saker og tiltak, effekt (effektive / ikke effektive). Trend: samme tall for forrige like lange periode | `ImprovementCaseAccessService::visibleCases()`, historikktabeller, `ImprovementAttentionService::findingsForCases()` | Periode + forrige periode. Fagområde | `improvement.view` + fagområde | Nøkkeltall med endring mot forrige periode, åpne avvik sortert etter alder | Ja |
| `compliance` | Kravstatus (samsvar / delvis / ikke samsvar / ikke vurdert) via `ComplianceStatusResolver`. Krav med forfalt vurdering. Revisjoner fullført i perioden (fra `compliance_audit_status_changes`) med konklusjon. Funn per type og overleveringsgrad. **Kvalitet-kontroller:** kontroller med bevis registrert i perioden, og kontroller uten bevis | `ComplianceAccessService`, `ComplianceAttentionService`, `ComplianceAuditAttentionService`. Kontrolldelen via `QualityItem` (type control) + `quality_item_documents` | Periode. Kundeglobal | Delene vises hver for seg: Etterlevelse krever `compliance.view` + modul (eksplisitt), kontrolldelen krever `quality.view` | Statusfordeling, revisjonstabell, kontrolldekning | Ja |
| `quality` | Prosessrevisjoner godkjent i perioden (`QualityProcessRevision.approved_at`). Prosesser og policyer gjennomgått i perioden (`last_reviewed_at`). Prosesser med passert revisjonsdato. Prosesser uten styrende policy | `QualityAttentionService::findings()`, `QualityProcessBlueprintService::history()` | Periode. Kundeglobal | `quality.view` | Nøkkeltall + oppmerksomhetsliste | Ja |
| `suppliers` | Antall per kritikalitet. Vurderinger i perioden med resultat. Gjeldende beslutning `not_approved` / `approved_with_follow_up`. Aktsomhet `measures_required`. Signal 1–11 som antall (`SupplierAttentionService::overview(user, today)`). Kritikalitetsendringer i perioden | `SupplierAccessService::visibleSuppliers()`, `SupplierAssuranceResolver::decisionsInForce()`, `SupplierDueDiligenceService::inForce()` | Periode. Kundeglobal | `supplier.view` + modul (eksplisitt) | Nøkkeltall, kritiske leverandører med signal | Ja |
| `resources` / `stakeholder_feedback` | Ledelsens tekst | — | — | `management_review.view` | Tekstfelt | Ja (tekst) |

«Åpne avvik hos leverandører» vises **ikke** i leverandørseksjonen. Slike saker telles i seksjonen
Avvik med sin egen gate. Dette følger §15.2 i `supplier-assurance-v2-plan.md`: et skjult objekt skal
ikke kunne skru et signal av eller på.

### 5.3 Små utvidelser i eksisterende moduler

| Utvidelse | Hvorfor | Omfang |
|---|---|---|
| `ImprovementCase::isDeletable()` sjekker `management_review_decision_cases` | Samme vern som revisjonsfunn | 1 linje + test |
| `ImprovementCaseController::show` får `review_origin` via `ManagementReviewHandoffService::provenanceFor()` | Saken viser «Fra Ledelsens gjennomgåelse …» | Prop + liten komponent i `Improvements/Show.jsx` |
| `QualityAttentionService::findings()` får valgfri `$today` | Testbarhet og konsistens med de andre tjenestene | Signaturutvidelse, standard `now()` |
| Indekser `(customer_id, changed_at)` på `improvement_case_status_changes`, `improvement_action_status_changes` og `compliance_audit_status_changes` | Periodespørringer per kunde | Én migrasjon. Verifiseres med `EXPLAIN` i fase 2 og legges bare til der planen viser behov |
| `BusinessArea::SCOPED_CONTENT_TABLES` får `management_review_business_areas` | Et fagområde som brukes som avgrensning, kan ikke slettes stille | 1 linje + `BusinessAreaDeletionTest` |

Ingen andre moduler endres. Det trengs ingen «per dato»-varianter av resolverne, fordi «Status nå» er
definert som hentetidspunktet (§5.1).

---

## 6. Statusmodell og livssyklus

### 6.1 Statuser — låst

| Status | Betydning |
|---|---|
| `draft` («Utkast») | Alt kan redigeres. Grunnlaget er levende |
| `finalized` («Ferdigstilt») | Innhold og grunnlag er frosset. Bare rettelser og «neste gjennomgåelse» kan endres |

**Vurdering av de foreslåtte statusene:**

- «Under arbeid» skiller seg ikke fra «Utkast» i noen regel.
- «Klar for ferdigstilling» er en beregning av sjekklisten. En lagret status ville kunne bli
  usann når grunnlaget endrer seg.
- To statuser gir ingen overgangsregler å vedlikeholde.
- Ingen «Avbrutt»: et utkast uten overleverte tiltak kan slettes, og et utkast med overleverte
  tiltak må ferdigstilles. Det hindrer at besluttede tiltak mister sin opprinnelse.

### 6.2 Hvem gjør hva

| Handling | Krav |
|---|---|
| Lese | `management_review.view` |
| Opprette, redigere utkast, deltakere, vurderinger, beslutninger, konklusjon | `management_review.edit` |
| Sende tiltak til oppfølging / koble til sak | `management_review.edit` **og** `improvement.edit` i valgt fagområde (`ImprovementCaseCreator::create()` sjekker selv) |
| Ferdigstille | `management_review.finalize` |
| Registrere rettelse etter ferdigstilling | `management_review.finalize` |
| Endre «neste gjennomgåelse innen» etter ferdigstilling | `management_review.edit` |
| Slette utkast (uten overleverte tiltak) | `management_review.delete` |
| Slette ferdigstilt | Aldri (bare kundesletting) |

Ingen godkjenningskjede i v1. Ferdigstilling er én bevisst handling fra en person med rettigheten.

### 6.3 Sjekkliste for ferdigstilling

Sjekklisten beregnes og lagres ikke:

1. Møtedato er satt og ikke frem i tid.
2. Minst én deltaker.
3. Hver seksjon som vises **for den som ferdigstiller**, har en vurdering.
4. Samlet konklusjon er skrevet.
5. Hvert tiltak er sendt til oppfølging eller koblet til en sak.

Listen vises på «Oversikt». Mangler lenker til riktig seksjon.

### 6.4 Ferdigstilling (én transaksjon)

1. `lockForUpdate` på gjennomgåelsen. Statusen må være `draft`, og tilgangen sjekkes på nytt.
2. Sjekklisten beregnes på nytt på serveren.
3. `ManagementReviewBasisService::build(finalizer, review)` beregnes **på nytt**. Grunnlaget fra
   siden brukes aldri.
4. Én rad i `management_review_snapshot_sections` skrives per seksjon. Den inneholder `coverage`:
   hvilke fagområder den som ferdigstiller nådde, og om det var hele avgrensningen.
5. Navnesnapshot skrives for deltakere, ansvarlige og den som ferdigstiller.
6. `status = finalized`, `finalized_at` og `finalized_by_user_id` settes.
7. Hendelsen `finalized` skrives.

Bekreftelsesdialogen viser:

- «Dette fryses: grunnlag, vurderinger, beslutninger, deltakere».
- Hvis dekningen er ufullstendig: «Grunnlaget ditt omfatter ikke alle fagområder i avgrensningen
  (mangler: 2). Det lagres som begrenset.»

Ferdigstilling blokkeres ikke av dette, men begrensningen dokumenteres.

### 6.5 Feil etter ferdigstilling — låst

- **Ingen gjenåpning.** En gjenåpning ville gjøre øyeblikksbildet til noe annet enn det ledelsen så.
- **Rettelser er tillegg.** `management_review_amendments` er append-only:
  - feltene er tekst, begrunnelse, hvem og når;
  - rettelsene vises i arbeidsflaten og i rapporten under «Rettelser etter ferdigstilling»;
  - de kan ikke endres eller slettes.
- Tiltakene lever videre i Avvik og forbedringer. Status på dem er levende og ikke en del av
  rettelsene.

### 6.6 Sammenligning over tid

- Hver seksjon i øyeblikksbildet har en `headline`: 2–4 navngitte nøkkeltall, med `by_area` for
  fagområdestyrte seksjoner.
- Ledelsens vurdering per seksjon ligger på `management_review_sections`.
- «Sammenlign over tid» (§4.1) leser bare disse to.
- Gjennomgåelser med ulik `schema_version` sammenlignes på felles nøkler. Manglende nøkler vises
  som «—».

---

## 7. Datamodell og teknisk arkitektur

*Forslag, ikke migrasjoner.*

Felles for alle tabeller:

- `customer_id` FK med `cascadeOnDelete`;
- `unique(id, customer_id)` på foreldretabeller;
- sammensatte FK-er `(management_review_id, customer_id)` med NO ACTION eller CASCADE som angitt;
- bruker-FK-er er `nullOnDelete`, og navnet snapshotes der historikken krever det;
- CHECK-constraints bare på pgsql;
- triggere med `CREATE OR REPLACE FUNCTION`, der `down()` dropper funksjonen.

### 7.1 Tabeller

#### `management_reviews` — mutable som utkast, låst som ferdigstilt

| | |
|---|---|
| Felt | `title`, `purpose` (text, null), `period_start`, `period_end` (date), `meeting_date` (date, null), `all_business_areas` (bool, default true), `frameworks` (jsonb-liste med nøkler fra config), `framework_versions` (jsonb, settes ved ferdigstilling), `owner_user_id` (null), `conclusion` (text, null), `next_review_due_on` (date, null), `status` (`draft`/`finalized`), `finalized_at`, `finalized_by_user_id`, `finalized_by_name`, `created_by`, `updated_by`, timestamps |
| Constraints | CHECK status. CHECK `period_end >= period_start`. CHECK `finalized_*` er satt hvis og bare hvis status er `finalized`. CHECK `jsonb_typeof(frameworks)='array'` |
| Trigger | `management_reviews_finalized_immutable`: når `OLD.status='finalized'` er bare `next_review_due_on`, `updated_by`/`updated_at` og nulling av bruker-FK tillatt. DELETE blokkeres mens kunden finnes |
| Indekser | `(customer_id, status)`, `(customer_id, period_end)`, `(customer_id, owner_user_id)` |
| Sletting | Bare utkast uten overleverte tiltak (`isDeletable()`) |

#### `management_review_business_areas` — kobling

`management_review_id`, `business_area_id`. Sammensatte FK-er. Fagområdet har `restrictOnDelete`.
Raden brukes bare når `all_business_areas=false`. Den er låst ved ferdigstilling (trigger via
foreldrestatus). Tabellen legges i `BusinessArea::SCOPED_CONTENT_TABLES`.

#### `management_review_participants` — mutable som utkast

`management_review_id`, `user_id` (null, `nullOnDelete`), `name` (snapshot / ekstern),
`role_label` (f.eks. «Daglig leder», null), `position`. Låses ved ferdigstilling.
Ingen oppmøtestatus i v1: deltakerlisten er de som deltok.

#### `management_review_sections` — mutable som utkast

`management_review_id`, `section_key` (fra seksjonskatalogen), `judgement`
(`satisfactory`/`needs_improvement`/`not_satisfactory`, null), `comment` (text, null), `notes`
(text, null; brukes av manuelle seksjoner som `context_changes`/`resources`), `updated_by`,
timestamps. `unique(management_review_id, section_key)`. Raden opprettes ved første lagring. Den er
låst ved ferdigstilling.

#### `management_review_decisions` — mutable som utkast

| | |
|---|---|
| Felt | `management_review_id`, `section_key` (null), `kind` (`decision`/`action`), `text`, `proposed_owner_user_id` (null), `proposed_due_date` (null), `position`, `created_by`, `updated_by`, timestamps |
| Regler | Låst ved ferdigstilling. Når et tiltak er overlevert, kan teksten ikke endres og tiltaket ikke slettes, heller ikke i utkast. Samme vern som `compliance_audit_findings_handed_off_immutable` |

#### `management_review_decision_cases` — kobling og proveniens (mønster: `supplier_improvement_cases`)

`decision_id` (sammensatt FK), `improvement_case_id` (sammensatt FK, NO ACTION), `origin`
(`handoff`/`linked`), `handoff_key` (uuid, idempotens), `created_by_user_id`, `created_at`.
`unique(decision_id, improvement_case_id)`. CHECK på at `decision.kind='action'` håndheves i
tjenesten. Raden er uforanderlig (trigger). En koblet sak kan ikke slettes fra Avvik (§5.3).

#### `management_review_snapshot_sections` — uforanderlig

| | |
|---|---|
| Felt | `management_review_id`, `section_key`, `source_domain` (null for egne seksjoner), `schema_version` (smallint), `coverage` (jsonb: `{all_areas, area_ids, area_names, complete}`), `headline` (jsonb), `payload` (jsonb), `captured_at` |
| Constraints | `unique(management_review_id, section_key)`. CHECK `jsonb_typeof(payload)='object'`. CHECK på `headline` |
| Trigger | `management_review_snapshot_sections_immutable`: UPDATE alltid nektet. DELETE nektet mens kunden finnes |
| Merk | Ledelsens vurdering ligger på `management_review_sections`. Den er låst via foreldrestatus og dupliseres ikke i øyeblikksbildet |

#### `management_review_amendments` — uforanderlig

`management_review_id`, `text`, `reason`, `created_by_user_id`, `created_by_name`, `created_at`.
Trigger som over.

#### `management_review_events` — uforanderlig revisjonsspor

`management_review_id`, `event` (`created`, `finalized`, `amendment_added`, `decision_handed_off`,
`decision_linked`, `next_review_changed`), `actor_user_id`, `actor_name`, `metadata` (jsonb),
`occurred_at`. Trigger som over.

### 7.2 Modeller

Følgende modeller lages under `app/Models/`:

- `ManagementReview`
  - konstanter: `STATUS_*`, `JUDGEMENTS`;
  - metoder: `isDraft()`, `isFinalized()`, `isDeletable()`;
  - relasjoner: `businessAreas`, `participants`, `sections`, `decisions`, `snapshotSections`,
    `amendments`, `events`.
- `ManagementReviewParticipant`, `ManagementReviewSection` og `ManagementReviewDecision`. De nekter
  skriving når foreldren er ferdigstilt, både i modellen og i triggeren.
- `ManagementReviewDecisionCase`, `ManagementReviewSnapshotSection`, `ManagementReviewAmendment` og
  `ManagementReviewEvent`. Modellen kaster på `updating`/`deleting`.

### 7.3 Tjenester (`app/Services/ManagementReview/`)

| Tjeneste | Ansvar |
|---|---|
| `ManagementReviewAccessService` | `canOpenModule`, `visibleReviews(user)` (kunde + `management_review.view`, ellers `1=0`), `findVisible`, `canEdit`, `canFinalize`, `canDelete`, `ownerCandidates`, `sectionVisibleTo(user, snapshotSection)` (§8.3) |
| `ManagementReviewSectionCatalog` | Seksjonsnøkler i fast rekkefølge, rammeverk fra `config/management_review.php`, dekningsberegning |
| `ManagementReviewBasisService` | `build(user, review): array<key, sectionPayload>`. Samler seksjoner fra `Sections/` |
| `Sections/*Section` (9 stk.) | `PreviousDecisionsSection`, `ContextChangesSection`, `ObjectivesSection`, `RisksSection`, `ImprovementsSection`, `ComplianceSection`, `QualitySection`, `SuppliersSection`, `ManualSection` (for `resources`/`stakeholder_feedback`) |
| `ManagementReviewService` | `create`, `update`, deltakere, vurderinger, konklusjon, `delete`. Alle skrivinger låser raden og nekter ved `finalized` |
| `ManagementReviewDecisionService` | `create`, `update`, `delete` og `reorder` av beslutninger |
| `ManagementReviewHandoffService` | `formOptions(user)`, `handOff(user, decision, validated)` via `ImprovementCaseCreator`, `link(user, decision, caseId)`, `provenanceFor(user, case)`, `followUpFor(user, decisions)` (levende status med tilgangssjekk) |
| `ManagementReviewReadiness` | Sjekklisten i §6.3. Ren beregning |
| `ManagementReviewFinalizationService` | Transaksjonen i §6.4 |
| `ManagementReviewSnapshotReader` | Leser øyeblikksbildet filtrert på lesers tilgang (§10.4). Brukes av visning, rapport og sammenligning |
| `ManagementReviewAmendmentService` | Rettelser |
| `ManagementReviewReportBuilder` | Bygger én dokumentmodell fra levende grunnlag (utkast) eller fra snapshot-leseren (ferdigstilt). Brukes av både rapportsiden og PDF |

### 7.4 Ruter og kontrollere

Ruteprefiks `app.management-review.`, sti `/app/management-reviews`. Rutene ligger inne i
`customer.module`-gruppen. Id-er er rene tall og løses via `findVisible()`. Implisitt model binding
brukes ikke.

| Metode | Sti | Kontroller@metode |
|---|---|---|
| GET | `/` | `ManagementReviewController@index` |
| POST | `/` | `@store` |
| GET | `/compare` | `@compare` |
| GET / PATCH / DELETE | `/{id}` | `@show` / `@update` / `@destroy` |
| POST / PATCH / DELETE | `/{id}/participants[/{pid}]` | `ManagementReviewParticipantController` |
| PUT | `/{id}/sections/{key}` | `ManagementReviewSectionController@update` (vurdering, kommentar, notater) |
| POST / PATCH / DELETE | `/{id}/decisions[/{did}]` | `ManagementReviewDecisionController` |
| POST | `/{id}/decisions/{did}/handoff` | `ManagementReviewDecisionController@handOff` |
| POST | `/{id}/decisions/{did}/link` | `@link` |
| POST | `/{id}/finalize` | `ManagementReviewController@finalize` |
| PATCH | `/{id}/next-review` | `@updateNextReview` |
| POST | `/{id}/amendments` | `ManagementReviewAmendmentController@store` |
| GET | `/{id}/report` | `ManagementReviewReportController@show` (Inertia) |
| GET | `/{id}/report.pdf` | `ManagementReviewReportController@pdf` |

### 7.5 Frontend (`resources/js/Pages/App/ManagementReview/`)

| Fil | Innhold |
|---|---|
| `Index.jsx` | Liste, «Neste gjennomgåelse», «Åpne tiltak», opprettelsesdialog |
| `Compare.jsx` | Sammenligning over tid |
| `Show.jsx` | Arbeidsflate og ferdigstilt visning (samme komponent, `mode` fra serveren) |
| `ReviewSectionNav.jsx` | Seksjonsnavigasjon, som blir nedtrekksliste på mobil |
| `ReviewOverview.jsx` | Sjekkliste, deltakere, dekning, konklusjon, neste gjennomgåelse |
| `ReviewSection.jsx` | Grunnlag + vurdering + beslutninger for én seksjon |
| `sections/*Basis.jsx` | Én visning per automatisk seksjon (nøkkeltall, lister). Leser samme payload fra levende data og snapshot |
| `ReviewJudgementForm.jsx` | Tre nivåer + kommentar |
| `ReviewDecisions.jsx`, `ReviewDecisionForm.jsx`, `ReviewHandoffForm.jsx` | Beslutninger og overlevering |
| `ReviewFinalizeDialog.jsx` | Bruker eksisterende `ActionDialog` |
| `ReviewAmendments.jsx` | Rettelser |
| `Report.jsx` | Dokumentvisning med utskriftsstil |
| `reviewStatus.js` | Statustoner, vurderingstoner, `formatDay` |
| `reviewHelp.js` | Innhold til PageHelp |
| `reviewSections.js` | Navigasjonsstatus og avledet sjekkliste, testbart med `node:test` |

Gjenbruk: `StatusBadge`, `PageHelpButton`/`PageHelpPanel`, `EmptyStateBox`, `AlertBox`,
`ActionDialog`, `FormButtonRow`, `actionStyles.js` og `riskLevel.js` (`RISK_LEVEL_TONES`).
Eieralternativer bruker samme mønster som `riskOwners.js`.

### 7.6 Registrering (sjekkliste fra kartleggingen)

| Sted | Endring |
|---|---|
| `config/procynia_modules.php` | `modules.management_review` med `sort_order` 45. Pakkeplassering (§15, beslutning 1). `route_modules['app.management-review.'] => 'management_review'` |
| `config/management_review.php` (ny) | Seksjonskatalog, rammeverk med versjon og dekningskart |
| `CustomerPermissionCatalog` | `DOMAIN_MANAGEMENT_REVIEW` med nøklene `management_review.view`, `.edit`, `.finalize`, `.delete`. Ikke i `areaScopedDomains()`. `explicitGrantDomains()` avhenger av §15, beslutning 2 |
| `GovernanceController::MODULES` + `appModules.js` | Ny oppføring i samme posisjon (`GovernanceControllerTest`) |
| `CustomerAppLayout.jsx`, `ModuleSidebar.jsx`, `Governance/Index.jsx` | Område, ikon, beskrivelse |
| `HandleInertiaRequests::share()` | `'management_review' => __('procynia.management_review')` |
| `lang/{no,en}/procynia.php` | Ny blokk `management_review`, pluss følgende nøkler: `navigation.modules`, `governance.descriptions`, `customer_env.roles.domains/permissions`, `billing.modules.*`, `info_center_page.my_tasks.*`, `task_notifications.*` |
| `MyTasksService` | Ny kilde, plassert i railrekkefølge |
| `UserNotificationAccessScope` | Prefikset `management_review.` med metadata `management_review_id` |
| `AssignmentNotifier::MODELS` | `ManagementReview` (eier) |
| `NotificationBell.jsx`, `CustomerAppLayout.jsx` `domainLabels` | Etikett for prefikset |
| Etter Tilganger-arbeidet | `customer_env.roles.sections.descriptions.management_review` og `DOMAIN_ICONS` |
| `docs/notifications-and-tasks-plan.md` §4–6 | Ny kilde og ny hendelse dokumenteres |

---

## 8. Rettighetsmodell

### 8.1 Rettigheter — låst

| Nøkkel | Gir |
|---|---|
| `management_review.view` | Lese gjennomgåelser (egne felt, beslutninger, rettelser) |
| `management_review.edit` | Opprette og redigere utkast, registrere beslutninger, sende tiltak videre (sammen med `improvement.edit`) |
| `management_review.finalize` | Ferdigstille, registrere rettelser |
| `management_review.delete` | Slette utkast uten overleverte tiltak |

- Domenet er **kundeglobalt** og ikke fagområdestyrt. En gjennomgåelse gjelder styringssystemet som
  helhet. Avgrensningen til fagområder er et filter på grunnlaget, ikke en tilgangsgrense.
- Det finnes ingen egen administrasjonsrettighet. Rammeverk og seksjoner er konfigurasjon, ikke
  kundedata.

### 8.2 To lag med tilgang — låst

**Lag 1: gjennomgåelsen selv.** `management_review.view` gir:

- overskrift, periode og avgrensning;
- deltakere;
- samlet konklusjon;
- manuelle seksjoner (`context_changes`-teksten, `resources`, `stakeholder_feedback`);
- **alle beslutninger og tiltakstekster**;
- rettelser.

Beslutningene er ledelsens utdata og skrevet for å deles.

**Lag 2: grunnlag og seksjonsvurdering.** Hver automatisk seksjon, **og ledelsens vurdering og
kommentar i den**, vises bare når leseren selv oppfyller seksjonens kildegate:

| Seksjon | Gate |
|---|---|
| `objectives` | `objective.view` i minst ett fagområde i avgrensningen |
| `risks` | `risk.view` på samme måte |
| `improvements`, saksstatus i `previous_decisions` | `improvement.view` på samme måte |
| `compliance` (Etterlevelse-delen), støttedata i `context_changes` | `compliance.view` (eksplisitt tildeling) |
| `compliance` (kontrolldelen), `quality` | `quality.view` |
| `suppliers` | `supplier.view` (eksplisitt tildeling) |

Vurderingen følger grunnlaget fordi en kommentar som «Tre kritiske leverandører uten gyldig
avtale …» ellers ville røpe beskyttet informasjon.

Seksjoner leseren ikke har tilgang til, **vises ikke og telles ikke**: fravær, ikke «skjult». Det
eneste unntaket er én samlet setning øverst: «Noen deler av gjennomgåelsen vises bare for personer
med tilgang til de aktuelle modulene.» Setningen vises når minst én seksjon er utelatt, og navngir
ingen seksjon.

### 8.3 Øyeblikksbilder — låst

1. Øyeblikksbildet lagres med **den som ferdigstiller sin synlighet**. `coverage` dokumenterer den.
2. En leser ser en snapshot-seksjon når leseren **nå** har kildedomenets lesetilgang (samme gate
   som 8.2).
3. **Fagområdestyrte seksjoner** filtreres per element og aggregat på
   `areaIds(leser) ∩ coverage.area_ids`. Totalsummer regnes fra `by_area` for de synlige
   fagområdene. Leseren ser aldri tall for fagområder de ikke når i dag, heller ikke historiske tall.
4. Nøkkeltall i `headline` følger samme regel.
5. Om modulabonnement kreves for å lese øyeblikksbildet, er §15, beslutning 3. Anbefalingen er
   **nei**: rettigheten kreves, abonnementet ikke. Det er historikk, og den skal ikke forsvinne om
   en opsjon sies opp.
6. Snapshot-leseren (`ManagementReviewSnapshotReader`) er eneste vei inn. Kontroller, rapport og
   sammenligning går alle gjennom den.

### 8.4 Kundeisolasjon

- Alle tabeller har `customer_id` og sammensatte FK-er `(…_id, customer_id)`. En rad kan derfor
  ikke peke på en annen kundes gjennomgåelse, sak eller fagområde.
- Hver spørring starter i `visibleReviews(user)` eller i kildemodulens `visible*()`.
  `ImprovementCaseCreator` og `findVisible` beholder sine egne kundesjekker.
- Eierkandidater: aktive brukere i kunden med `management_review.view`.
- Tester for kundeisolasjon i hver fase (§12).

### 8.5 System Owner

- Hvis domenet ikke er eksplisitt (anbefalt, §15, beslutning 2), har System Owner lag 1
  implisitt, som i Kvalitet.
- Lag 2 krever fortsatt kildegaten. System Owner ser derfor aldri risiko, mål eller avvik uten egen
  rolle med fagområder, og aldri Etterlevelse eller Leverandører uten egen rolle. Dette følger
  allerede av `BusinessAreaGrants` og `explicitGrantDomains()`, og krever ingen ny logikk.

---

## 9. Beslutninger, tiltak og varsling

### 9.1 Valg av tiltaksmodell — låst

**Beslutninger er egne objekter. Tiltak følges opp som saker i Avvik og forbedringer (type
`improvement`).** Det lages ingen egen tiltakstabell med status og frist.

Begrunnelse:

- Avvik og forbedringer er Basis, så alle kunder har den.
- ISO-utdata fra ledelsens gjennomgåelse er forbedringsmuligheter og endringsbehov. Det er nettopp
  hva `improvement` er.
- Uten ny kode får vi:
  - ansvarlig, frist, under-tiltak og effektverifisering;
  - uforanderlig historikk;
  - fagområdetilgang;
  - «Mine oppgaver» via `ImprovementTaskSource`;
  - eiervarsel via `AssignmentNotifier` og fristpåminnelser.
- Mønsteret er det samme som revisjonsfunn → Avvik og leverandør → Avvik.

Konsekvenser som aksepteres:

- Hvert tiltak må ha et fagområde, så overleveringsskjemaet krever ett.
- Lukking av saken krever at fullførte under-tiltak er verifisert effektive. Det er ønsket for
  ledelsens tiltak.
- Risikotiltak (`risk_treatment_actions`) og nye mål er **ikke** mål for overlevering i v1.
  «Koble til sak» dekker tilfeller der oppfølgingen allerede finnes i Avvik (§13).

### 9.2 Overlevering

`ManagementReviewHandoffService::handOff()` gjør følgende i én transaksjon:

1. Låser beslutningen og sjekker at den er `kind=action`, at gjennomgåelsen er `draft` og at
   `management_review.edit` er på plass.
2. `ImprovementCaseCreator::create()` sjekker `improvement.edit` i fagområdet og gyldig eier, og
   oppretter saken med:
   - `type=improvement`;
   - tittel = beslutningsteksten (avkortet til 255 tegn);
   - beskrivelse = full tekst + «Besluttet i Ledelsens gjennomgåelse 2026 (møte 12.10.2026)»;
   - eier og frist fra skjemaet.
3. Skriver en rad i `management_review_decision_cases` (`origin=handoff`, `handoff_key` for
   idempotens).
4. Skriver hendelsen `decision_handed_off`.

«Koble til sak» velger blant `visibleCases()` som er aktive, og skriver `origin=linked`.

Overlevering er tillatt bare i utkast. Etter ferdigstilling registreres nye oppfølgingsbehov som
rettelse og som ordinær sak i Avvik.

### 9.3 Tidligere tiltak i neste gjennomgåelse

`PreviousDecisionsSection` viser beslutninger fra alle ferdigstilte gjennomgåelser der minst én
koblet sak:

- er aktiv, eller
- er lukket eller kansellert etter forrige gjennomgåelses `finalized_at`, lest fra
  `improvement_case_status_changes`.

Tabellen har kolonnene beslutning, gjennomgåelse, ansvarlig, frist, status, forfalt og effekt.
Rene beslutninger (`kind=decision`) fra forrige gjennomgåelse vises også, uten status, slik at
ledelsen kan bekrefte at de er fulgt.

### 9.4 Mine oppgaver — `ManagementReviewTaskSource`

| Oppgave | Eier | Årsaker | Frist | `can_act` |
|---|---|---|---|---|
| `review_complete` («Fullfør gjennomgåelsen») | `owner_user_id` på utkast | `review_open`, `meeting_passed` (møtedato passert uten ferdigstilling), `actions_without_follow_up` (antall) | `meeting_date` | `management_review.edit` / `.finalize` |
| `review_due` («Planlegg neste ledelsens gjennomgåelse») | Eier av siste ferdigstilte gjennomgåelse | `next_review_due`. Vises fra 30 dager før `next_review_due_on`, så lenge det ikke finnes et nyere utkast eller en nyere ferdigstilt gjennomgåelse | `next_review_due_on` | `management_review.edit` |

- Id-er: `management-review-{id}` og `management-review-due-{id}`.
- `subject.prefix = 'management_review'`, metadata `management_review_id`. Påminnelser virker dermed
  automatisk.
- Selve tiltakene kommer fra `ImprovementTaskSource` som før, uten dobbelt oppføring.

### 9.5 Varsler

- **Eiertildeling:** `management_review.owner_assigned` via `AssignmentNotifier`. Reglene i
  `notifications-and-tasks-plan.md` §6 gjelder uendret.
- **Tiltak:** eiervarsel for saken sendes av Avvik (`improvement.case_assigned`) når saken
  opprettes.
- **Fristpåminnelser:** fra den eksisterende daglige kjøringen.
- **Lesegate:** `UserNotificationAccessScope` med prefikset `management_review.` mot
  `visibleReviews()`.
- **Ikke i v1:** varsel til deltakere ved ferdigstilling, og e-post.

---

## 10. Historikk og øyeblikksbilder

### 10.1 Levende kontra historisk — låst

| | Utkast | Ferdigstilt |
|---|---|---|
| Grunnlag | Levende, beregnet ved hver visning med lesers tilgang | Øyeblikksbilde, filtrert på lesers tilgang |
| Vurderinger, beslutninger, deltakere | Redigerbare | Låst (modell + trigger) |
| Tiltaksstatus | Levende | «Ved ferdigstilling» (snapshot) + «Nå» (levende), adskilt |
| Lenker til kildeobjekter | Lenke | Lenke. Objektet sjekker tilgang og kan være endret eller slettet («finnes ikke lenger») |

### 10.2 Hvorfor JSON per seksjon

- Seksjonene har ulik form. Typede kolonner for alle nøkkeltall ville gi mange tabeller som aldri
  spørres på tvers.
- `jsonb` med `schema_version` per seksjon følger `SupplierAssuranceDecision.state_snapshot`.
  Payloaden er en bevisst projeksjon, og nøkkeltallene ligger i `headline`.
- Rader per seksjon gjør tilgangsfiltrering, rapport og sammenligning enkle.
- Størrelse: 9 seksjoner, maks 50 elementer per liste, typisk under 200 kB per gjennomgåelse.

### 10.3 Beregnet på nytt ved ferdigstilling

Grunnlaget fra siden brukes aldri. Det kan være minutter gammelt og beregnet for en annen bruker.
Finaliseringen bygger alt på nytt i transaksjonen (§6.4).

### 10.4 Lesefiltrering

`ManagementReviewSnapshotReader::sections(user, review)` gjør følgende:

1. Utelater seksjoner der kildegaten ikke er oppfylt nå.
2. For fagområdestyrte seksjoner:
   - beholder elementer med `area_id ∈ areaIds(user)`;
   - summerer `by_area` og `headline.by_area` for de samme fagområdene;
   - omdefinerer «hele virksomheten» til «dine fagområder» når leseren ikke når alle i `coverage`.
3. Viser `coverage.complete=false` som «Grunnlaget var begrenset av tilgangen til den som
   ferdigstilte».

### 10.5 Revisjonsspor

| Hva | Hvor |
|---|---|
| Opprettet, ferdigstilt, rettelser, overleveringer og koblinger, neste dato endret | `management_review_events` (uforanderlig) |
| Hvem som sist endret utkastet | `updated_by` / `updated_at` på hver tabell |
| Frosset innhold | Triggere + modellvern |
| Tiltakenes livsløp | Avvik og forbedringer sin egen historikk |

Endringer i utkast logges ikke per felt. Utkastet er et arbeidsdokument, og det juridisk
interessante er det som ferdigstilles. Endringer etter ferdigstilling er bare mulige som
loggede tillegg. Dette er samme nivå som i de andre modulene.

---

## 11. Rapportering

### 11.1 Valg — låst

Det finnes ingen eksport i styringsmodulene i dag. Dette er tilgjengelig:

- `dompdf` (A4, DejaVu Sans, remote deaktivert i `DocumentPreviewService`);
- `PhpWord` (`RequirementWordExportService`, streamet nedlasting).

**v1 leverer:**

1. **Rapportside** (`Report.jsx`). Den viser dokumentmodellen fra `ManagementReviewReportBuilder`,
   med Tailwind `print:`-varianter: skjult navigasjon, sideskift før hver seksjon, svart-hvitt-trygge
   badger. «Skriv ut» bruker `window.print()`.
2. **PDF** (`/report.pdf`). Dompdf rendrer en Blade-mal fra **samme dokumentmodell**:
   - A4, DejaVu Sans for æøå, `isRemoteEnabled=false`;
   - lastes ned med `response()->streamDownload`;
   - **genereres ved behov og lagres ikke**. Øyeblikksbildet er arkivet, og samme snapshot gir
     samme PDF;
   - filnavn: `ledelsens-gjennomgaelse-2026-10-14.pdf`.

To maler (React og Blade) over én dokumentmodell er en bevisst, liten duplisering. Det gjør at det
ikke trengs en ny generell dokumentgenerator eller en headless nettleser i produksjonsbildet.
Word-eksport er utenfor v1 (§13).

### 11.2 Dokumentets innhold

1. Forside:
   - tittel, periode, møtedato, avgrensning og rammeverk;
   - status (UTKAST / Ferdigstilt dato og av hvem);
   - en linje om hvilket grunnlag dokumentet viser.
2. Deltakere.
3. Formål og samlet konklusjon.
4. Én del per seksjon: grunnlag (nøkkeltall, oppmerksomhet, lister), ledelsens vurdering og
   kommentar, og beslutninger i seksjonen.
5. Samlet beslutnings- og tiltaksliste: ansvarlig, frist og oppfølgingssak (saksnummer og status
   ved ferdigstilling).
6. Rammeverkdekning.
7. Rettelser etter ferdigstilling.
8. Sporbarhet:
   - «Grunnlag hentet [tidspunkt]»;
   - kildemodul per seksjon;
   - `coverage`;
   - id-er på kildeobjekter, slik at de kan slås opp.

Rapporten følger lesers tilgang (§8). En PDF lastet ned av en person uten leverandørtilgang har
derfor ikke leverandørseksjonen. Det står i forsiden: «Inneholder delene du har tilgang til.»

---

## 12. Teststrategi

PHP-tester kjøres som `tests/Feature/App/ManagementReview*Test.php` mot Postgres i Docker
(`docker exec procynia-app php artisan test --filter=…`). Mønsteret er:

- `UsesProjectPostgresConnection`;
- transaksjon med rollback;
- testdatabasen migreres med `--env=testing`;
- et nytt trait `CreatesManagementReviewScenarios` som bruker `activatePackage()` og ekte
  `CustomerRole`.

JS-tester kjøres med `node:test` i `node:22`-imaget. E2E kjøres med Playwright mot en egen
fixture-kunde.

Bare målrettede tester kjøres per fase. Hele suiten kjøres bare som sammenslåingsport, etter avtale.

| Område | Tester |
|---|---|
| Opprettelse og redigering | Standardverdier (periode, tittel, rammeverk fra forrige). Validering (periode, rammeverk mot config, eier). Redigering nektet etter ferdigstilling (HTTP 403/422, `LogicException` og `QueryException` fra triggeren) |
| Periodeavgrensning | Per seksjon: hendelse på første og siste dag er med, dagen før og etter er ikke. Gjenåpnet avvik telles riktig fra historikken, ikke fra `closed_at`. KPI-måling vurdert mot snapshot-mål |
| Innhenting | Én test per seksjon med kjent datasett → forventet payload. Konstant antall spørringer. Payload inneholder ingen oversatte strenger |
| Tilgang og kundeisolasjon | Seksjon utelatt uten modul eller lesetilgang. Fagområdefiltrering (bruker med 1 av 2 fagområder). System Owner ser lag 1, men ikke Risiko, Leverandør eller Etterlevelse uten rolle. Annen kundes id → 404 på alle ruter. Eierkandidater fra egen kunde. Vurderingstekst skjult med seksjonen |
| Øyeblikksbilde | Ferdigstilling skriver én rad per synlig seksjon. Endring i kildedata etter ferdigstilling endrer ikke visningen. Leser med færre fagområder enn den som ferdigstilte ser bare sine. `coverage.complete=false` vises. Trigger nekter UPDATE og DELETE. Kundesletting kaskaderer |
| Beslutninger og tiltak | Overlevering oppretter sak med riktig type, fagområde, eier og frist. Krever `improvement.edit` i fagområdet. Idempotens via `handoff_key`. Overlevert beslutning kan ikke endres eller slettes. Saken kan ikke slettes fra Avvik. `provenanceFor` vises bare med `management_review.view`. Koble til sak |
| Mine oppgaver og varsler | `review_complete` og `review_due` med riktige grenser (30 dager, nyere utkast skjuler). Tiltak vises én gang (fra Avvik). Påminnelse sendes. `owner_assigned`. Bjellen skjuler varsler for gjennomgåelser leseren ikke ser |
| Ferdigstilling og låsing | Sjekklisten (hvert punkt). Grunnlag beregnet på nytt (endring mellom visning og ferdigstilling fanges). Samtidig ferdigstilling (lås). Sletting av utkast med og uten overleverte tiltak |
| Revisjonsspor | Hendelser skrives for hver handling i §10.5. Rettelser er append-only |
| Rapportering | Rapportside: props filtrert på tilgang. PDF: 200, `application/pdf`, inneholder tittel og æøå (tekstuttrekk via eksisterende `pdftotext`), utelater seksjon uten tilgang. Utkast er merket |
| Norsk og engelsk | Ny test: alle nøkler under `management_review` finnes i både `no` og `en` (det finnes ingen generell paritetstest i dag). Rapporten rendres på lesers språk fra samme snapshot |
| Registrering | Utvid `GovernanceControllerTest`, `NavigationEntitlementMatrixTest`, `CustomerRolePermissionTest`, `MyTasksGovernanceTest`, `GovernanceAssignmentNotificationTest`, `TaskDeadlineReminderTest` og `BusinessAreaDeletionTest` |
| JS (`node:test`) | `reviewSections.js` (navigasjonsstatus, sjekkliste), `reviewStatus.js`, at PageHelp finnes, etiketter i `myTaskLabels.js` |
| E2E (`management-review.spec.js`) | Egen kunde `e2e-lg-<suffix>` (`ManagementReviewE2EFixture`, pakke `grc` + modulen). Reise: opprett → vurder alle seksjoner → registrer tiltak → send til Avvik → ferdigstill → endre et kildeobjekt → ferdigstilt visning er uendret → rapport vises. Egen spesifikasjon for 16 px og horisontal overflyt (`readability.js`) på desktop og mobil. Opprydding med `remaining()` = 0 |

---

## 13. Bevisste avgrensninger (utenfor v1)

| Utenfor | Begrunnelse |
|---|---|
| Møteplanlegging, kalenderinvitasjoner, agenda-maler, e-post | Procynia er ikke et møteverktøy. `meeting_date` + «neste gjennomgåelse» dekker sporbarheten |
| Godkjenningskjeder, signaturer, elektronisk signering | Én ansvarlig ferdigstilling + uforanderlig spor er nok for ISO-dokumentasjon. Kan legges på senere uten modellendring |
| AI-oppsummering eller AI-vurderinger | Vurderinger må være menneskelige. En senere AI-støtte kan foreslå sammendrag av grunnlaget, men aldri skrive vurdering eller beslutning, og bare med kilder (`ai_operations.php`) |
| Overlevering til risikotiltak, nye mål eller nye etterlevelseskrav | Avvik dekker forbedringstiltak. Andre mål vurderes etter erfaring med v1 |
| Rekonstruksjon av tilstand ved periodeslutt | Kildedata støtter det ikke for alle moduler (§2.2). «Status nå» ved ferdigstilling er definert og ærlig |
| Word-eksport | PDF + utskrift dekker behovet. PhpWord finnes hvis kundene ber om det |
| Lagret PDF / registrering som kontrollbevis i Kvalitet | Naturlig neste steg: knappen «Registrer som bevis» på en kontroll i Kvalitet. Krever filhåndtering og vurderes separat |
| Kunnskapsartikkel til Wiki (`WikiKnowledgeHandoffService`) | Registeret støtter det, men behovet er ikke dokumentert |
| Flere parallelle gjennomgåelser per fagområde med egne frister | Avgrensning per gjennomgåelse dekker behovet. Ingen planlegging per fagområde |
| Egne seksjoner definert av kunden | Faste seksjoner + manuelle tekstfelt holder v1 enkel. Rammeverk-config kan senere åpnes for kundevalg |
| Deltakernes oppmøte og fravær, roller i møtet | Deltakerlisten er de som deltok |

---

## 14. Implementeringsrekkefølge

Fire faser. Hver fase er en sammenhengende leveranse med egne tester og en egen commit-serie på
`feat/management-review`.

| Fase | Leverer | Nye tabeller | Tester |
|---|---|---|---|
| **1. Fundament og arbeidsflate** | Config, pakke og modulregistrering, rettigheter, rail og Styring, `ManagementReviewAccessService`, opprett/rediger/slett utkast, deltakere, avgrensning, rammeverk. `Index`, `Show` med Oversikt og seksjonsnavigasjon, manuelle seksjoner og vurdering per seksjon. Hendelser. Eiervarsel og bjellegate. Lang no/en. PageHelp | `management_reviews`, `management_review_business_areas`, `management_review_participants`, `management_review_sections`, `management_review_events` | CRUD, validering, kundeisolasjon, rettigheter, registreringstester, varsel og gate |
| **2. Automatisk beslutningsgrunnlag** | `ReviewScope`, `ManagementReviewBasisService`, de 7 automatiske seksjonene (alle unntatt `previous_decisions`), visningskomponenter per seksjon, oppmerksomhet på Oversikt, fagområdetekst, rammeverkdekning, deferred props. `$today` i `QualityAttentionService`. Indekser der `EXPLAIN` viser behov | (eventuell indeksmigrasjon) | Én test per seksjon: periodegrenser, tilgang og fagområde, spørringsantall, ingen oversatt tekst |
| **3. Beslutninger, tiltak og oppfølging** | Beslutninger (CRUD), overlevering og kobling til Avvik, proveniens på saken, vern i `isDeletable()`, `PreviousDecisionsSection`, «Åpne tiltak» på Index, `ManagementReviewTaskSource` (`review_complete`, `review_due`), «neste gjennomgåelse», sjekklisten | `management_review_decisions`, `management_review_decision_cases` | Overlevering, idempotens og vern, Mine oppgaver og påminnelser, tidligere tiltak, sjekkliste |
| **4. Ferdigstilling, historikk og rapport** | Ferdigstillingstransaksjon, snapshot-tabeller og triggere, `ManagementReviewSnapshotReader`, ferdigstilt visning, rettelser, «Sammenlign over tid», rapportside + PDF, E2E-reise og lesbarhet, oppdatering av `notifications-and-tasks-plan.md`, statusseksjon i denne planen | `management_review_snapshot_sections`, `management_review_amendments` (+ låsetriggere på tabellene fra fase 1 og 3) | Snapshot, låsing, lesefiltrering, rapport og PDF, E2E. **Sammenslåingsport:** full PHP-suite + E2E etter avtale |

Låsetriggerne på fase 1-tabellene legges til i fase 4. Først da finnes `finalized`-overgangen, og
testene for dem skrives sammen med den.

---

## 15. Avklaringer før implementering (besluttet 2026-10-09, se §17.2)

| # | Spørsmål | Anbefaling | Konsekvens av alternativet |
|---|---|---|---|
| 1 | **Pakkeplassering:** Basis eller egen opsjon? | **Basis.** ISO 9001 og ISO 27001 krever ledelsens gjennomgåelse for ethvert styringssystem. Modulen gir verdi med bare Kvalitet og Avvik, og blir rikere med hver opsjon | Opsjon: ny pakke i `packages`, `ai_customer_capacity.php` og billing-tekster. Bundles (`governance`/`iso`/`grc`) må oppdateres |
| 2 | **System Owner:** skal domenet være eksplisitt tildelt? | **Nei.** Lag 1 inneholder ledelsens utdata, og lag 2 er uansett beskyttet av kildegaten (§8.5) | Ja: System Owner må gi seg selv en rolle for å se gjennomgåelser, som i Leverandører |
| 3 | **Øyeblikksbilde uten modulabonnement:** kreves abonnement på kildemodulen for å lese en frossen seksjon? | **Nei, bare rettigheten.** Historikk skal ikke forsvinne ved oppsigelse | Ja: ferdigstilte gjennomgåelser mister seksjoner om en opsjon sies opp |
| 4 | **Vurderingstekst følger seksjonens gate** (§8.2) | **Ja** | Nei: enklere, men kommentarer kan røpe beskyttet informasjon. Krever da en tydelig instruks til brukerne |
| 5 | **Tiltak via Avvik og forbedringer** (type `improvement`, fagområde påkrevd) | **Ja** (§9.1) | Egen tiltakstabell: ny MyTaskSource, nye varsler og egen effektoppfølging, altså en parallell oppgavemodell |
| 6 | **PDF med dompdf i v1** i tillegg til utskriftsside | **Ja** | Bare utskrift: mindre arbeid, men PDF-en avhenger av brukerens nettleser |
| 7 | **Ingen gjenåpning, bare rettelser** | **Ja** (§6.5) | Gjenåpning: krever versjonering av snapshot og gjør «hva ledelsen så» tvetydig |
| 8 | **Rammeverkkatalog:** dekningskartet for ISO 9001/27001 (§3.4) | Gjennomgås av en kvalitetsfaglig person før fase 2, på samme måte som `supplier-assurance-template-review.md` | — |
| 9 | **Ucommittet Tilganger-arbeid** | Committes før fase 1, slik at den nye domeneseksjonen kan legges inn uten konflikt | Fase 1 må ellers vente med `DOMAIN_ICONS` og beskrivelse |

---

## 16. Anbefaling

Bygg Ledelsens gjennomgåelse som en **tynn styringsmodul over eksisterende moduler**:

- **Eget, lite domene.** Gjennomgåelse, deltakere, seksjonsvurderinger, beslutninger, rettelser og
  hendelser, med to statuser.
- **Grunnlaget beregnes** av ni seksjonsklasser. Hver av dem leser kun via modulens egen
  tilgangs- og oppmerksomhetstjeneste. Samme kodevei brukes for levende visning og øyeblikksbilde.
- **Ferdigstilling fryser** grunnlaget som én uforanderlig jsonb-rad per seksjon, fordelt per
  fagområde. Lesing filtreres alltid på lesers nåværende tilgang.
- **Tiltak blir saker i Avvik og forbedringer.** Proveniensen lagres på kildesiden, som for
  revisjonsfunn og leverandører. Dermed kommer «Mine oppgaver», varsler, påminnelser og
  effektverifisering uten ny oppgavelogikk.
- **Én dokumentmodell** gir både utskriftsside og PDF (dompdf), uten ny dokumentinfrastruktur.

Modulen tilfører dermed det ledelsen mangler i dag, nemlig sammenheng, vurdering og sporbare
beslutninger, uten å duplisere noe Procynia allerede gjør.


---

## 17. Endelige arkitekturvalg — låst

### 17.1 Avvik fra planen

| Plan | Bygget | Hvorfor |
|---|---|---|
| Tiltak følges alltid opp som sak i Avvik og forbedringer (§9.1) | **Tiltak følges opp i modulen selv som standard** (ansvarlig, frist og status på beslutningen, i Mine oppgaver via `ManagementReviewTaskSource`). Den som kan registrere saker i Avvik og forbedringer, kan **flytte** oppfølgingen dit (ny forbedringssak) eller **koble** til en eksisterende sak | Modulen skal kunne brukes fullt ut uten at Avvik og forbedringer er tilgjengelig for personen (rettigheter, fagområder) eller kunden. Et tiltak har alltid nøyaktig én oppfølging: her, eller i saken. Det kopieres aldri, så det blir aldri to oppgaver |
| Egen koblingstabell `management_review_decision_cases` | Koblingen ligger på beslutningen (`improvement_case_id`, `improvement_origin`, `handoff_key`, `handed_off_at`) | 1:1, samme mønster som `compliance_audit_findings.improvement_case_id` |
| `compliance`-seksjonen inneholdt også kontroller fra Kvalitet | Kontroller (evidens) ligger i `quality`-seksjonen. `compliance` er bare Etterlevelse og revisjon | Én seksjon har én kildegate |
| `headline`-nøkkeltall per seksjon for sammenligning over tid | «Utvikling over tid» sammenligner ledelsens vurdering per seksjon i de siste fire ferdigstilte gjennomgåelsene, med samme tilgangsgate | Vurderingen er det ledelsen faktisk sammenligner, og nøkkeltall per fagområde ble unødvendig komplisert |
| Sjekklisten krevde oppfølging på alle tiltak | Bortfalt: et tiltak har oppfølging fra det registreres | — |
| Snapshot ble fanget med lesers synlighet og merket med `coverage` | Som planlagt, pluss en eksplisitt tilstand per seksjon (`captured`, `module_unavailable`, `not_captured`) | Skiller «data ikke tilgjengelig» fra «ingen registrerte forhold» |
| Deferred props, indekser `(customer_id, changed_at)`, `$today` i `QualityAttentionService` | Ikke gjort | Ikke nødvendig for ytelse i v1. Kvalitet leses «nå» ved henting, slik planen definerer «Status nå» |

### 17.2 Beslutninger fra §15

| # | Beslutning |
|---|---|
| 1 | **Basis.** `management_review` ligger i Basis-pakken (`config/procynia_modules.php`, `sort_order` 55) |
| 2 | **Ikke eksplisitt tildeling.** System Owner leser og driver gjennomgåelser. Beskyttede seksjonsdata krever fortsatt kildemodulens rettighet og fagområde |
| 3 | **Historikk uten abonnement.** En ferdigstilt seksjon leses med rettigheten alene, selv om modulen er sagt opp. Et nytt utkast sier «Modulen er ikke aktivert» |
| 4 | **Vurdering følger grunnlaget.** Ledelsens vurdering og kommentar på en modulseksjon vises bare for den som kan lese grunnlaget. Beslutninger og tiltak vises for alle med `management_review.view` |
| 5 | **Tiltak:** se §17.1, første rad |
| 6 | **PDF med dompdf** i tillegg til utskriftsside |
| 7 | **Ingen gjenåpning.** Rettelser er tillegg |
| 8 | **ISO 9001 og ISO 27001** er implementert som valgbare rammeverk, merket som ikke faglig verifisert. Faglig gjennomgang av koblingene gjenstår |
| 9 | **Tilganger:** utviklet mot committet kode. Se §20 for integrasjonspunktene |

### 17.3 Prinsipp for tiltak (kort)

> Et tiltak fra ledelsens gjennomgåelse har én ansvarlig, én frist og én oppfølging. Det følges opp
> der det registreres, i den ansvarliges Mine oppgaver, med varsel og påminnelser. Hvis personen kan
> registrere saker i Avvik og forbedringer, kan oppfølgingen flyttes dit. Da er det saken som følges
> opp, og tiltaket forsvinner fra modulens egne oppgaver. Tiltak er domenetiltak, på samme måte som
> risikotiltak, og ikke en generell oppgavemodell.

---

## 18. Implementert løsning

### 18.1 Brukerreise

1. **Styring → Ledelsens gjennomgåelse** (rail og Styring-kort).
2. **Register**, som viser:
   - neste gjennomgåelse (frist besluttet i forrige gjennomgåelse);
   - åpne tiltak fra alle gjennomgåelser;
   - gjennomgåelser med status;
   - utvikling over tid.
3. **Ny gjennomgåelse.** Én inline-form med tittel, periode, møtedato, ansvarlig, avgrensning (hele
   virksomheten eller fagområder), rammeverk, deltakere og formål. Periode, tittel og rammeverk er
   utfylt på forhånd.
4. **Arbeidsflate.** Venstre navigasjon (nedtrekksliste på mobil) med Oversikt, ni til ti seksjoner,
   Beslutninger og tiltak, og Historikk. Hver seksjon har en markør: ✓ vurdert, ! har
   oppmerksomhetspunkter, ○ ikke vurdert, – ikke tilgjengelig. Seksjonen er valgt i `?section=`.
5. **Oversikt** viser:
   - sjekklisten «Klar for ferdigstilling?», med lenker til seksjonene som mangler vurdering;
   - «Hva krever oppmerksomhet»;
   - deltakere;
   - samlet konklusjon;
   - neste gjennomgåelse;
   - dekning av rammeverk med ansvarsfraskrivelse;
   - rettelser (etter ferdigstilling).
6. **Seksjon:**
   - grunnlaget: perioderesultater, forrige periode for trend, status nå eller ved ferdigstilling,
     lister og kjente begrensninger;
   - tidspunkt og eventuell avgrensning;
   - ledelsens vurdering i tre nivåer, med valgfri kommentar. Manuelle seksjoner har også en
     beskrivelse;
   - beslutninger og tiltak registrert i seksjonen;
   - Forrige/Neste.
7. **Ferdigstill:** knappen er aktiv bare når sjekklisten er oppfylt. Et bekreftelsespanel varsler om
   seksjoner som ikke blir med i øyeblikksbildet.
8. **Ferdigstilt** gjennomgåelse:
   - alt er låst, med et banner om tidspunkt og person;
   - tiltak viser «Ved ferdigstilling» ved siden av status nå;
   - rettelser kan registreres;
   - den ansvarlige fullfører tiltaket fra Mine oppgaver.
9. **Rapport:** en frittstående dokumentside med «Skriv ut» og «Last ned PDF».

### 18.2 Beslutningsgrunnlaget

- **Én kodevei.** `ManagementReviewBasisService::live()` og `capture()` bygger hver seksjon med
  `Sections/*Section`. `snapshot()` leser de frosne radene. Alle tre presenteres gjennom
  `SectionPresenter` og `BasisFormatter`.
- **Kilder.** Seksjonene leser bare via modulens egen tilgangstjeneste:
  - `visibleRisks()`, `visibleObjectives()`, `visibleCases()`, `visibleRequirements()`,
    `visibleAudits()` og `visibleSuppliers()`;
  - Kvalitet via `quality.view`.

  Seksjonene gjenbruker oppmerksomhetstjenestene `RiskAttentionService`, `ObjectiveAttentionService`,
  `ImprovementAttentionService`, `ComplianceAttentionService`, `ComplianceAuditAttentionService`,
  `QualityAttentionService` og `SupplierAttentionService`. De gjenbruker også `KpiMeasurementResolver`,
  `KpiTargetPolicy`, `KpiPresenter`, `RiskScoringPolicy` og `ComplianceStatusResolver`.
- **Perioderesultater** leses fra uforanderlig historikk med dato innenfor perioden:
  - Avvik: statusendringer, ikke `closed_at`;
  - Revisjoner: statusendringer;
  - Mål: statusendringer;
  - KPI: målinger med `period_end` i perioden, vurdert mot målverdien de ble registrert med;
  - Risiko: vurderinger, der hver sammenlignes med forrige vurdering.
- **Status nå / ved ferdigstilling** er tilstanden når grunnlaget leses. Det rekonstrueres ikke
  tilstand der kilden mangler historikk. Seksjonen sier det selv (`limitations`):
  - risikostatus, eier og strategi;
  - gjenåpnede risikotiltak;
  - kontrollresultat i Kvalitet;
  - bare siste gjennomgang i Kvalitet.
- **Fire tilstander** per seksjon: `available` (der `empty` gir «Ingen registrerte forhold»),
  `module_unavailable`, `no_access` og `not_captured` («Data ikke tilgjengelig»). En modul som ikke er
  aktiv, vises aldri som null.
- **Strukturerte data, aldri oversatt tekst.** Ord velges ved lesing (`BasisFormatter`), på lesers
  språk.

### 18.3 Øyeblikksbilder og historikk

- **Ferdigstilling** (`ManagementReviewFinalizationService`) gjør alt i én transaksjon:
  - låser raden;
  - beregner sjekklisten på nytt;
  - bygger grunnlaget på nytt med ferdigstillerens tilgang;
  - skriver én `management_review_snapshot_sections`-rad per seksjon med grunnlag, pluss
    `decisions` (beslutningene slik de sto);
  - lagrer rammeverkversjoner, status, tidspunkt og navn, og skriver hendelsen.
- **Fagområdedeling.** Fagområdestyrte tall og rader lagres per fagområde. En leser ser bare
  fagområdene de når i dag, innenfor dem som ble fanget.
- **Låsing i databasen:**
  - `management_reviews_finalized_locked`: ferdigstilt innhold er frosset. Bare eier og neste frist
    kan endres, og bruker-FK kan nulles;
  - `management_review_children_locked`: deltakere, seksjoner og fagområder;
  - `management_review_decisions_locked`: det som ble besluttet. Oppfølgingen av egne tiltak er
    fortsatt åpen;
  - `management_review_history_immutable`: snapshot, rettelser og hendelser.

  Sletting tillates bare når kunden slettes. Modellene kaster `LogicException`.
- **Revisjonsspor.** `management_review_events` inneholder opprettet, ferdigstilt, rettelse,
  overlevering eller kobling, tiltak fullført, avbrutt, gjenåpnet eller omfordelt etter
  ferdigstilling, neste frist endret og utkast slettet. Hendelsen for et slettet utkast blir stående
  med tittelen.

### 18.4 Tiltak, Mine oppgaver og varsler

- **`ManagementReviewTaskSource`** har tre oppgavetyper:
  - `review_action`: eget, åpent tiltak, med frist;
  - `review_complete`: utkast der personen er ansvarlig, med frist på møtedato. Forfalt når møtet er
    passert;
  - `review_due`: «Planlegg neste ledelsens gjennomgåelse», fra 30 dager før fristen, til en nyere
    gjennomgåelse finnes.

  `subject.prefix = management_review`, så fristpåminnelsene fra `TaskDeadlineReminderService`
  virker uten ny kode.
- **Varsler** via `AssignmentNotifier`: `management_review.owner_assigned` og
  `management_review.action_assigned`. Prefikset `management_review.` lesesjekkes i
  `UserNotificationAccessScope`.
- **Overleverte tiltak** vises som saker via `ImprovementTaskSource`, og saken varsles av Avvik. Saken
  viser «Fra ledelsens gjennomgåelse» bare for den som kan lese gjennomgåelser. Saken kan ikke slettes
  (`ImprovementCase::isDeletable()` og FK NO ACTION).
- **Tidligere tiltak.** `PreviousDecisionsSection` viser åpne tiltak fra alle tidligere
  gjennomgåelser, tiltak avsluttet siden forrige gjennomgåelse, og forrige gjennomgåelses rene
  beslutninger. Saksdetaljer vises bare for den som kan lese saken.

### 18.5 PDF og utskrift

- `ManagementReviewReportBuilder` lager ett ferdig formulert dokument. Det rendres av `Report.jsx`
  (utskrift, `print:`-stil) og av Blade-malen `management-review/report.blade.php` via dompdf (A4,
  DejaVu Sans, uten fjerninnhold).
- PDF-en genereres ved forespørsel, strømmes med `Cache-Control: no-store` og lagres aldri.
- Seksjoner leseren ikke har tilgang til, utelates helt, også vurdering og kommentar. Forsiden sier
  da at dokumentet bare inneholder delene leseren har tilgang til.
- Utkast merkes UTKAST.

---

## 19. Datamodell og tilgangsmodell (bygget)

- **Migrasjon:** `database/migrations/2026_10_11_000001_create_management_review_tables.php`.
  Reverserbar: `down()` dropper tabeller og funksjoner. Verifisert med rollback og ny migrering.
- **Tabeller:**
  - `management_reviews`
  - `management_review_business_areas`
  - `management_review_participants`
  - `management_review_sections`
  - `management_review_decisions`
  - `management_review_snapshot_sections`
  - `management_review_amendments`
  - `management_review_events`

  Alle har `customer_id` og sammensatte FK-er mot gjennomgåelsen. Hendelser bruker
  `ON DELETE SET NULL (management_review_id)`, som krever PostgreSQL 15 eller nyere. CHECK-constraints
  dekker status, vurdering, beslutningstype og oppfølging, og jsonb-typer.
- **Rettigheter** (`CustomerPermissionCatalog::DOMAIN_MANAGEMENT_REVIEW`):

  | Nøkkel | Gir |
  |---|---|
  | `management_review.view` | Lese |
  | `management_review.edit` | Opprette og redigere utkast, vurderinger, beslutninger og tiltak, sende tiltak videre, neste frist |
  | `management_review.finalize` | Ferdigstille og registrere rettelser |
  | `management_review.delete` | Slette utkast uten overleverte tiltak |

  Domenet er kundeglobalt og ikke eksplisitt. Ansvarlig for et eget tiltak kan fullføre, avbryte og
  gjenåpne det med bare `view`. Alt kontrolleres i backend.
- **Seksjonsgater** (`ManagementReviewSectionCatalog`):

  | Seksjon | Krever |
  |---|---|
  | `objectives` | `objective.view` + fagområde |
  | `risks` | `risk.view` + fagområde |
  | `improvements` | `improvement.view` + fagområde |
  | `compliance` og støtten i `context_changes` | `compliance.view` (eksplisitt) |
  | `quality` | `quality.view` |
  | `suppliers` | `supplier.view` (eksplisitt) |

  Utkast krever i tillegg modulabonnement. Eget innhold, manuelle seksjoner og beslutninger krever
  `management_review.view`.
- **Kundeisolasjon:**
  - alle spørringer starter i `visibleReviews()` eller i kildens `visible*()`;
  - id-er utenfor kunden gir 404;
  - eiere og deltakere må være aktive brukere i kunden;
  - fagområder valideres mot kunden.

---

## 20. Integrasjonspunkter mot ucommittet Tilganger-arbeid

Det nye domenet vises automatisk i Tilganger fra `CustomerPermissionCatalog::domains()`. Domene- og
rettighetsetiketter er lagt inn i `lang/{no,en}` under `customer_env.roles`.

Når Tilganger-arbeidet i hovedmappen merges, må to ting legges til:

1. `customer_env.roles.sections.descriptions.management_review` i begge språkfiler.
2. En oppføring `management_review` i `DOMAIN_ICONS` i `PermissionSection.jsx`, med ikonnøkkel
   `management_review` (finnes i `ModuleSidebar`).

Lang-filene vil trolig gi en tekstlig merge-konflikt nær `customer_env.roles`. Begge sider beholdes.

---

## 21. Tester og kjente begrensninger

### 21.1 Tester (kjørt 2026-10-09)

PHP-testene kjøres i Docker mot `procynia_test`.

| Tester | Resultat |
|---|---|
| `ManagementReviewTest` (15: tilgang, System Owner, kundeisolasjon, CRUD, sjekkliste, ferdigstilling, DB-låser, rettelser, sletting, kundesletting, katalog) | grønn |
| `ManagementReviewBasisTest` (11: periode og status, tilstander, tilgang per modul, System Owner, vurdering skjult, fagområder, frosset grunnlag, snapshot per leser, moduldeaktivering, ikke fanget, risikoendring, språk) | grønn |
| `ManagementReviewDecisionTest` (8: egne tiltak og Mine oppgaver, varsel og bjellegate, påminnelse, oppfølging etter ferdigstilling, overlevering, kobling, uten Avvik-rettigheter, tidligere tiltak, gjennomgåelsesoppgaver) | grønn |
| `ManagementReviewReportTest` (3: utskrift og PDF uten lekkasje, utkast, engelsk) | grønn |
| Regresjon: Governance, Navigation, ModuleEntitlement, PackageChange, CustomerRolePermission, BusinessAreaDeletion, MyTasks, notifikasjoner, påminnelser, Improvement\*, InfoCenter\*, CustomerEnvironment, Supplier-overleveringer, Billing og flere | 136 + 222 + 50 grønne |
| JS (`node:test` i `node:22`) | 1423/1423 |
| E2E `management-review.spec.js` (hovedreise desktop + mobil, 16 px og sideveis scroll) mot worktree via `artisan serve` | 2/2 |

**Endrede eksisterende tester:**

- Registertester som lister moduler, har fått `management_review`. Regex-en i `GovernanceControllerTest`
  tillater nå `_` i nøkler.
- To lekkasjetester (`SupplierImprovementHandoffTest`, `SupplierRiskTest`) brukte fagområdenavnet
  «Ledelse». Det finnes nå i delte oversettelser («Ledelsens gjennomgåelse»), så navnet er byttet til
  et som ingen oversettelse inneholder. Testens hensikt er uendret.

### 21.2 Kjente begrensninger

- Koblingene til ISO 9001 og ISO 27001 er ikke faglig verifisert.
- Kildedata uten historikk (risikostatus, kontrollresultater) vises som status ved
  henting/ferdigstilling, ikke som periodeslutt.
- En seksjon som den som ferdigstiller ikke kan lese, fryses som «ikke fanget». Den kan ikke fanges i
  ettertid.
- Seksjonsvurderinger kan ikke settes til «ikke relevant». En modul som ikke er aktiv, eller en
  seksjon personen ikke ser, kreves ikke vurdert.
- Ingen e-post, møteplanlegging, signatur eller AI (§13).
- Etter ferdigstilling kan tiltak ikke lenger overleveres til Avvik. Det registreres som rettelse og
  ordinær sak.
