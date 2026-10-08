# Plan: Leverandørkontroll — Leverandøroppfølging v2

Status: **forslag — til gjennomgang.** Ikke implementert. Blir implementeringskontrakt når §19 er låst.
Utgangspunkt: `main` @ `b7e45e98` (2026-10-08). Bygger på [Leverandøroppfølging v1](supplier-management-v1-plan.md),
som er ferdig og merget.

Denne planen **utvider** v1. Alt v1 har låst (v1-plan §16.1), står ved lag med mindre det er nevnt
eksplisitt i §2.2 nedenfor.

---

## 1. Mål og standard

Procynia skal kunne dokumentere at virksomheten **faktisk har kontroll på leverandørene sine** —
særlig i offentlig sektor, der anskaffelsesregelverket, personvernregelverket, lønns- og
arbeidsvilkårsregelverket og krav om aktsomhet for menneskerettigheter og miljø forutsetter
oppfølging, ikke bare avtaleinngåelse.

Ny standard for området:

```
Leverandør → Kritikalitet → Profil → Kravprofil → Dokumentasjon → Kontroll → Beslutning → Oppfølging
```

| Steg | Spørsmål | Eier (modell) | Fra |
|---|---|---|---|
| Leverandør | Hvem er de, hva leverer de, hvem følger dem opp? | `suppliers` | v1 |
| Kritikalitet | Hvor viktig er leverandøren for oss? | `suppliers` + `supplier_criticality_changes` | v1 |
| **Profil** | Hva kjennetegner leveransen — data, tilgang, kjede, sektor, risiko? | `supplier_profiles` | **v2** |
| **Kravprofil** | Hvilke krav gjelder for akkurat denne leverandøren, og hvorfor? | Beregnet fra profil + `supplier_control_requirements` | **v2** |
| Dokumentasjon | Hva har vi av dokumentasjon, og er den gyldig? | `supplier_documents` (utvidet) | v1 |
| **Kontroll** | Hva har vi faktisk kontrollert, mot hvilket krav, med hvilken dokumentasjon? | `supplier_requirement_evaluations` | **v2** |
| **Beslutning** | Er leverandøren godkjent — og hvem besluttet det, på hvilket grunnlag? | `supplier_assurance_decisions` | **v2** |
| Oppfølging | Hva forfaller, og hva følges opp som avvik, tiltak eller risiko? | Beregnet plan + Trenger oppmerksomhet + Avvik/Risiko | v1 + **v2** |

### 1.1 Endelig navn

| Hvor | Navn |
|---|---|
| Området (seksjon på leverandørsiden, hjelpetittel) | **Leverandørkontroll** |
| Toppstatus på leverandøren | **Kontrollstatus** |
| Kravlisten på leverandøren | **Krav og kvalifikasjoner** |
| Kravkatalogen (fane under Leverandører) | **Kontrollkrav** |
| Bransjemalene | **Kravmaler** |
| Modulnavn (uendret) | **Leverandøroppfølging** |
| Teknisk navnerom | `supplier_assurance` (lang), `App\Services\Suppliers\Assurance\*`, ruter under `app.supplier-management.` |

Begrunnelse: «Leverandørkontroll» skiller seg tydelig fra «Leverandørvurdering» (prestasjon) i daglig
språk, er kjent fra kontraktsoppfølging i offentlig sektor, og sier hva det er: *kontroll av at
kravene er oppfylt og dokumentert*. «Supplier Assurance» brukes ikke i UI.

---

## 2. Produktprinsipper

1. **Risikobasert, ikke sjekkliste.** Ingen leverandør får alle krav. Kravprofilen utledes
   deterministisk av profil og kritikalitet. Riktige spørsmål til riktig leverandør.
2. **Hvorfor gjelder kravet?** Hvert krav på en leverandør viser grunnen («Gjelder fordi leverandøren
   behandler personopplysninger som databehandler»). Ingen svart boks.
3. **«Vet ikke» = kravet gjelder.** Ukjente profilsvar utløser krav. Tvil går i retning av kontroll.
4. **Dokumentert betyr dokumentert.** Status «Dokumentert» krever at minst én dokumentasjonsrad er
   oppgitt som grunnlag. Ellers er det en påstand.
5. **Ingen score.** Ingen prosent, poeng, vekting eller samlet tall — verken per krav, per tema eller
   per leverandør. Antall per status er lov («2 mangler»); andeler er ikke.
6. **Systemet beregner grunnlaget, mennesket beslutter.** Kontrollstatus «Krever beslutning» er
   beregnet. «Godkjent», «Godkjent med oppfølging» og «Ikke godkjent for nye kjøp» er alltid en
   registrert, begrunnet menneskelig beslutning.
7. **Én kravmotor.** Supplier eier ikke et generelt kravregister. Kontrollkrav er *hva vi krever av
   leverandører og hvordan vi kontrollerer det*, og kan forankres i et krav i Etterlevelse (§6).
8. **Beregnet, ikke lagret.** Gjeldende kravprofil, kravstatus, kontrollstatus, forfall og Trenger
   oppmerksomhet beregnes ved lesing (som v1 §2.7). Bare menneskelige handlinger lagres.
9. **Uforanderlig der det betyr noe.** Kontroller, beslutninger, profilendringer og
   aktsomhetsvurderinger er historikk. Katalog, profil og dokumentasjonsoversikt er gjeldende tilstand.
10. **Ingen AI i v2.** Ingen AI-forslag til profil, kravstatus eller beslutning.
11. **Opt-in per kunde.** Uten kontrollkrav i katalogen endrer ingenting seg for en eksisterende kunde.

### 2.1 Leverandørvurdering ≠ Leverandørkontroll

|  | **Leverandørvurdering** (v1, uendret) | **Leverandørkontroll** (v2, ny) |
|---|---|---|
| Spørsmål | Hvordan fungerer leverandøren i praksis? | Hvilke krav gjelder, og hva har vi faktisk dokumentert/kontrollert? |
| Natur | Periodisk prestasjonsvurdering, skjønn | Krav-for-krav kontroll mot dokumentasjon |
| Grunnlag | Erfaring med leveransen | Dokumentasjon (sertifikat, avtale, egenerklæring, kontrollrapport) |
| Granularitet | Fire (fem, §11.4) faste kriterier + samlet resultat | Én kontroll per gjeldende krav |
| Utløser | Kritikalitetens vurderingsintervall | Kravets kontrollintervall, dokumentets utløp, profilendring |
| Resultat | Tilfredsstillende / Delvis / Ikke tilfredsstillende | Kravstatus (§8) → Kontrollstatus (§9) |
| Tabell | `supplier_assessments` | `supplier_requirement_evaluations`, `supplier_assurance_decisions` |
| Rettighet | `supplier.assess` | `supplier.assure` (ny, §13) |

De blandes aldri: ingen kravstatus i vurderingen, ingen vurderingsresultat i kontrollstatusen.
Kriteriet «Etterlevelse av avtale og krav» i vurderingen beholdes — det vurderer *praksis*, mens
kontrollen vurderer *dokumentasjon*.

### 2.2 Bevisste endringer i v1-beslutninger

| v1-beslutning | v2 | Begrunnelse |
|---|---|---|
| «Ingen per-leverandør kravstatus» (v1 §7.3) | Oppheves for **kontrollkrav**. Gjelder fortsatt for krav i Etterlevelse: kravets etterlevelsesstatus vises aldri på leverandøren. | Leverandørkontroll er nettopp dette. Egen statusmodell (§8), ikke `ComplianceAssessment`. |
| «Påkrevde dokumenttyper per kritikalitet er en regelmotor» (v1 §4.4) | Erstattes av en begrenset, deterministisk anvendelsesregel (§5.3) over et fast sett predikater. | Risikobasert kravprofil krever regler. Avgrenset: faste predikater, ingen fritt uttrykksspråk. |
| Dokumentasjonsrad kan slettes fritt (v1 §10) | En rad som er brukt som grunnlag i en kontroll, kan ikke slettes. | Ellers forsvinner beviset bak en uforanderlig kontroll. |
| Fire kriterier i leverandørvurderingen | Femte, valgfritt kriterium «Bærekraft og ansvarlig virksomhet» (§11.4). | Etterspurt; nullbar kolonne, ingen redesign. |
| Trenger oppmerksomhet: fem signaler | Seks nye lokale signaler (§15). Fortsatt ingen cross-module-signaler. | |

Alt annet i v1 §16.1 står uendret: register, livssyklus, kritikalitet, vurderinger, handoff, Risiko-
og Etterlevelse-koblinger, tilgangsmodell, explicit-grant, tenant-isolasjon, uforanderlig historikk,
avsluttet = skrivebeskyttet, sletteregler.

---

## 3. Domenemodell (oversikt)

| Lag | Hva | Mutable? | Skrives av |
|---|---|---|---|
| Leverandør, kritikalitet | v1 | v1-regler | v1-tjenester |
| **Profil** | Strukturerte fakta om leveransen | Gjeldende tilstand mutable; hver endring gir uforanderlig historikkrad | `SupplierProfileService` |
| **Kontrollkrav (katalog)** | Kundens krav til leverandører, med tema, nivå, kontrollpunkt, intervall, anvendelsesregel, dokumentasjonskrav, forankring | Mutable; livssyklus aktiv/utgått | `SupplierControlRequirementService` |
| **Manuell kravtilknytning** | Et katalogkrav lagt til én leverandør uten at regelen slår til | Mutable | `SupplierRequirementInclusionService` |
| **Kravprofil** | Gjeldende krav for leverandøren + grunn | **Beregnet**, aldri lagret | `SupplierRequirementApplicability` |
| **Kontroll** | Kravstatus for (leverandør, krav) med begrunnelse og dokumentasjon | Uforanderlig | `SupplierRequirementEvaluationService` |
| **Beslutning** | Godkjent / med oppfølging / ikke godkjent, med øyeblikksbilde av grunnlaget | Uforanderlig | `SupplierAssuranceDecisionService` |
| **Kontrollstatus** | Toppstatus | **Beregnet** | `SupplierAssuranceResolver` |
| **Aktsomhetsvurdering** | Menneskerettigheter, arbeidsforhold og miljø i leverandørkjeden | Uforanderlig | `SupplierDueDiligenceService` |
| **Oppfølgingsplan** | Hva forfaller når | **Beregnet** | `SupplierFollowUpPlan` |
| **Kravmaler** | Leveres med Procynia, i kode | Kode (versjonert) | `SupplierRequirementTemplateLibrary` |

---

## 4. Leverandørprofil

### 4.1 Prinsipp

Profilen er **fakta om leveransen**, ikke en vurdering. Den svarer på spørsmål en innkjøper eller
intern ansvarlig kan svare på uten fagkompetanse. Kravprofilen utledes av profilen.

De fire spørsmålene fra v1-kritikaliteten (`processes_personal_data`, `has_system_access`,
`supports_critical_delivery`, `hard_to_replace`) **flyttes ikke og dupliseres ikke**. De står på
`suppliers`, endres via «Endre kritikalitet», og leses av anvendelsesreglene som profilfakta.
Profilkortet viser dem skrivebeskyttet med «Endres under Kritikalitet».

### 4.2 Profilfelter

Alle felter er nullbare. `null` = ikke besvart. Tre-verdi-felter bruker `yes`/`no`/`unknown`.

| Gruppe | Felt | Verdier | Vises når |
|---|---|---|---|
| **Data og tilgang** | `data_role` | `processor` (databehandler) · `controller` (selvstendig behandlingsansvarlig) · `unknown` | `processes_personal_data` = ja |
|  | `special_category_data` | yes/no/unknown — særlige kategorier eller andre sensitive personopplysninger | `processes_personal_data` = ja |
|  | `stores_our_data` | yes/no/unknown — lagrer eller behandler våre data i egne systemer | alltid |
|  | `confidential_information` | yes/no/unknown — taushetsbelagt eller forretningssensitiv informasjon | alltid |
|  | `privileged_access` | yes/no/unknown — administrator-, drifts- eller fjerntilgang | `has_system_access` = ja |
|  | `data_location` | `norway` · `eea` · `outside_eea` · `unknown` | `stores_our_data` ≠ nei |
| **Leverandørkjede** | `uses_subcontractors` | yes/no/unknown — underleverandører/underdatabehandlere i leveransen | alltid |
|  | `production_outside_eea` | yes/no/unknown — produksjon eller arbeid utenfor Norge/EØS | alltid |
|  | `high_risk_categories` | flervalg, fast liste (§4.3) eller tom | alltid |
| **Arbeid** | `on_site_work` | yes/no/unknown — arbeid utføres på våre lokasjoner | alltid |
|  | `labour_intensive` | yes/no/unknown — arbeidsintensiv leveranse | alltid |
|  | `sectors` | flervalg, fast liste (§4.3) | alltid |
| **Regulatorisk** | `public_contract_terms` | yes/no/unknown — omfattes av særlige offentlige kontraktskrav (lønns- og arbeidsvilkår, seriøsitetskrav, miljøkrav i kontrakt) | alltid |
|  | `significant_environmental_impact` | yes/no/unknown — leveransen har vesentlig klima- eller miljøpåvirkning | alltid |
| **Status** | `completed_at` | tidspunkt profilen sist ble lagret komplett | — |

«Komplett» = alle felter som vises for leverandøren, er besvart (inkl. `unknown`).

**Bevisst ikke i profilen:** kontraktsverdi, beløp, land-for-land-liste med risikoscore, sikkerhetsgradering
etter sikkerhetsloven (eget regime — utenfor v2, §18), DPIA (eies av personvernarbeidet, ikke leverandøren).

### 4.3 Faste kodelister

| Liste | Verdier |
|---|---|
| `sectors` | `ict` (IKT og digitale tjenester) · `construction` (bygg og anlegg) · `cleaning` (renhold) · `staffing` (bemanning/innleie) · `health_care` (helse og omsorg) · `transport` · `facility_services` (drift, vakthold, kantine) · `goods` (vareleveranser) |
| `high_risk_categories` | `textiles` (tekstiler og arbeidstøy) · `electronics` (IKT-utstyr og elektronikk) · `medical_consumables` (medisinsk forbruksmateriell, hansker) · `food_agriculture` (mat og landbruksprodukter) · `construction_materials` (byggevarer, stein, solcellepaneler) · `furniture_wood` (møbler og tre) · `other` |

`sectors` erstatter ikke v1-`category`. Kategori er en registerfilter med ett valg; sektorer er
regulatoriske utløsere med flervalg. Overlapp er akseptert og forklares i hjelpeteksten.

### 4.4 Historikk

Hver lagring av profilen skriver én uforanderlig rad i `supplier_profile_changes` med hele forrige og
nye profil som øyeblikksbilde. Første utfylling krever ikke begrunnelse; senere endringer gjør det.
Det gjør «hvorfor gjaldt ikke DPA-kravet i mars?» etterprøvbart.

---

## 5. Kravprofil — kontrollkrav og anvendelse

### 5.1 Kontrollkrav (katalogen)

Et kontrollkrav er **ett krav vi stiller til leverandører, og hvordan vi kontrollerer det.** Katalogen
eies av kunden. Den fylles fra kravmaler (§12) eller manuelt.

| Felt | Verdier / innhold |
|---|---|
| `title` | «Databehandleravtale», «HMS-kort for alle på arbeidsplassen» |
| `description` | Hva kravet innebærer |
| `guidance` | Slik kontrollerer vi det; hva som godtas som tilsvarende |
| `theme` | `human_rights` · `labour_conditions` · `environment` · `information_security` · `privacy` · `quality` · `continuity` · `ethics` · `financial` |
| `level` | `mandatory` (Obligatorisk) · `important` (Viktig) · `standard` (Oppfølging) — §7 |
| `control_point` | `before_contract` (før avtale/oppstart) · `ongoing` (løpende) · `on_change` (ved endring) — §5.4 |
| `control_interval_months` | `null` · 3 · 6 · 12 · 24 · 36 — §14 |
| `applies_when` | Anvendelsesregel — §5.3. `[]` = alle leverandører |
| `accepted_document_types` | Hvilke dokumenttyper som normalt dokumenterer kravet (veiledende, ikke tvang) |
| `basis_text` | Fritekst hjemmel/grunnlag («Personvernforordningen art. 28», «Kontrakt pkt. 7.3») |
| `compliance_requirement_id` | Valgfri forankring i Etterlevelse — §6 |
| `supplier_id` | `null` = katalogkrav. Satt = **leverandørspesifikt krav** (f.eks. kontraktsspesifikt miljøkrav) som alltid gjelder den ene leverandøren |
| `status` | `active` · `retired` |
| `template_key`, `template_item_key`, `template_version` | Proveniens når kravet kom fra en kravmal |

### 5.2 Gjeldende krav for en leverandør

Et krav **gjelder** leverandøren når kravet er aktivt og ett av følgende stemmer:

| Grunn | Kilde | Vises som |
|---|---|---|
| Regel | `applies_when` slår til mot profil + kritikalitet | «Gjelder fordi …» med predikatenes tekst |
| Alle | `applies_when = []` | «Gjelder alle leverandører» |
| Lagt til | Rad i `supplier_requirement_inclusions` | «Lagt til manuelt av …, dato» |
| Leverandørspesifikt | `supplier_id` = leverandøren | «Krav for denne leverandøren» |

Et krav med siste kontroll «Ikke relevant» gjelder fortsatt teknisk, men vises nedtonet og teller
ikke i statusgrunnlaget (§8.3).

Kravprofilen beregnes ved lesing (`SupplierRequirementApplicability::for(Supplier)`). Den lagres
aldri. Endres profil, kritikalitet eller katalog, endres kravprofilen umiddelbart; nye krav står som
«Ikke vurdert».

### 5.3 Anvendelsesregel

**Ikke et uttrykksspråk.** `applies_when` er en liste av grupper over et **fast sett navngitte
predikater** implementert i PHP (`SupplierProfilePredicates`). Kravet gjelder når *minst én gruppe*
har *alle* sine predikater sanne (disjunktiv normalform, maks to nivåer).

```json
[["processor"], ["personal_data", "data_outside_eea"]]
```

**Predikater (fast liste, v2):**

| Predikat | Sann når |
|---|---|
| `personal_data` | `processes_personal_data` |
| `processor` | `personal_data` og `data_role` ∈ {processor, unknown, null} |
| `special_category_data` | `special_category_data` ∈ {yes, unknown} |
| `system_access` | `has_system_access` |
| `privileged_access` | `system_access` og `privileged_access` ∈ {yes, unknown} |
| `stores_our_data` | `stores_our_data` ∈ {yes, unknown} |
| `confidential_information` | `confidential_information` ∈ {yes, unknown} |
| `data_outside_eea` | `stores_our_data` og `data_location` ∈ {outside_eea, unknown} |
| `subcontractors` | `uses_subcontractors` ∈ {yes, unknown} |
| `production_outside_eea` | `production_outside_eea` ∈ {yes, unknown} |
| `high_risk_products` | `high_risk_categories` ikke tom |
| `on_site_work` | `on_site_work` ∈ {yes, unknown} |
| `labour_intensive` | `labour_intensive` ∈ {yes, unknown} |
| `public_contract_terms` | `public_contract_terms` ∈ {yes, unknown} |
| `environmental_impact` | `significant_environmental_impact` ∈ {yes, unknown} |
| `critical_delivery` | `supports_critical_delivery` |
| `hard_to_replace` | `hard_to_replace` |
| `criticality_important` | kritikalitet ∈ {important, critical} |
| `criticality_critical` | kritikalitet = critical |
| `sector:<kode>` | `<kode>` ∈ `sectors` (én per sektor i §4.3) |

**Ubesvart profilfelt (`null`):** behandles som `unknown` for de tre-verdi-feltene — med ett unntak:
er **hele profilen** tom (gamle leverandører), slår bare predikater fra v1-feltene og
kritikalitetspredikatene til. Ellers ville en ny katalog gitt hver gamle leverandør alle krav.
Leverandøren meldes i stedet med «Profil ikke fylt ut» (§15). Se §17.

**Kundens egne krav (UI):** skjemaet tilbyr «Alle leverandører» eller «Når ett av disse gjelder»
(ett OR-sett) pluss valgfri «Bare for Viktig og Kritisk» / «Bare for Kritisk», som legges inn i hver
gruppe. Full DNF brukes bare av kravmalene. Én regelrepresentasjon, to skrivemåter.

Validering: hvert predikat må være i den faste listen; maks 6 grupper à maks 4 predikater.

### 5.4 Kravtype — hvor kvalifikasjonskrav, kontraktsvilkår osv. hører hjemme

Offentlige anskaffelser skiller mellom kvalifikasjonskrav, kravspesifikasjon, tildelingskriterier,
kontraktsvilkår og oppfølging. **De hører til ulike faser, og bare noen hører til leverandøren.**

| Rolle i anskaffelsen | Hører til | Modelleres i v2 | Slik |
|---|---|---|---|
| **Kvalifikasjonskrav** | Leverandøren, kontrolleres før kontrakt | Ja | `control_point = before_contract` |
| **Kontraktsvilkår** | Leverandøren, gjennom avtalen | Ja | `control_point = ongoing`; hjemmel i `basis_text` eller Etterlevelse-kravets kilde (`kind = contract`) |
| **Oppfølgingskrav** | Leverandøren, periodisk | Ja | `control_point = ongoing` + `control_interval_months` |
| **Krav ved endring** (ny underleverandør, nytt behandlingssted) | Leverandøren, hendelsesstyrt | Ja | `control_point = on_change` — vises som påminnelse i kravet, ingen automatikk |
| **Kravspesifikasjon** | Konkurransen / ytelsen | **Nei** | Beskriver ytelsen, ikke leverandøren. Kontraktsspesifikke krav som skal følges opp, blir leverandørspesifikke kontrollkrav |
| **Tildelingskriterium** | Konkurransen | **Nei** | Avgjort ved tildeling; følges ikke opp etterpå. Et vinnende tilbud som lovet noe (f.eks. utslippsfrie kjøretøy), blir et leverandørspesifikt kontraktsvilkår |

Begrunnelse: Procynia modellerer ikke anskaffelsen (konkurransen) — Anbud er tilbudssiden. Å legge
konkurranseroller i kontrollkatalogen ville blandet to domener. Kravtype uttrykkes derfor som
**kontrollpunkt**, og grunnlaget (lov, avtale, intern) som **forankring** — aldri som en egen
konkurranse-taksonomi.

---

## 6. Gjenbruk av Etterlevelse og revisjon

### 6.1 Utgangspunkt

`supplier` og `compliance` er **uavhengige tilvalg** (`config/procynia_modules.php`: «No option
requires another»). En kunde kan ha Leverandøroppfølging uten Etterlevelse. Leverandørkontroll kan
derfor **ikke kreve** Etterlevelse-krav for å fungere.

### 6.2 Ansvarsdeling

| | Etterlevelse og revisjon | Leverandørkontroll |
|---|---|---|
| Spørsmål | Hva må *virksomheten* etterleve? | Hva krever vi av *leverandøren*, og har vi dokumentert det? |
| Objekt | `compliance_requirements` (fra kilde: lov, standard, avtale, intern) | `supplier_control_requirements` |
| Status | `ComplianceAssessment` — virksomheten | `SupplierRequirementEvaluation` — per leverandør |
| Eksempel | «Personvernforordningen art. 28: behandlingsansvarlig skal bare bruke databehandlere som gir tilstrekkelige garantier» | «Databehandleravtale» — gjelder databehandlere, obligatorisk, dokumenteres med DBA |

**Forholdet er 1 → mange:** ett Etterlevelse-krav kan forankre flere kontrollkrav. Kontrollkravet er
*hvordan* virksomheten etterlever det overfor leverandørene.

### 6.3 Hva gjenbrukes

| Gjenbrukes | Hvordan |
|---|---|
| `compliance_requirements` som **forankring** | `supplier_control_requirements.compliance_requirement_id` (valgfri). Kontrollkravet viser «Forankret i: GDPR art. 28» med lenke — bare når kunden har modulen og brukeren kan lese den (`ComplianceAccessService::canReadFromAnotherModule()` + `findVisibleRequirement()`); ellers vises `basis_text` alene |
| `compliance_sources.kind = contract` | Kontraktskrav som virksomheten vil styre som krav, registreres i Etterlevelse og forankres herfra |
| v1-koblingen `supplier_compliance_requirements` | Beholdes uendret og vises som underseksjon «Krav i Etterlevelse og revisjon som gjelder leverandøren» under *Krav og kvalifikasjoner* |
| Mønstre | Livssyklus aktiv/utgått, uforanderlig vurdering med øyeblikksbilde, `ReviewSchedule`, attention-form, trigger-mønster |

### 6.4 Hva gjenbrukes ikke

| Ikke gjenbrukt | Hvorfor |
|---|---|
| `ComplianceAssessment` / resultatverdiene | Gjelder virksomheten. Å lese «Oppfylt» som om leverandøren oppfyller er nettopp feilen v1 §7.3 forbyr |
| `ComplianceStatusResolver` | Samme grunn |
| `ComplianceAudit` for leverandørrevisjon | Revisjonsmodellen skoper virksomhetens prosesser og krav. En leverandørrevisjon dokumenteres som `audit_report`/`control_report` + kontroller. Egen revisjonsplanlegging av leverandører er utenfor v2 |
| Etterlevelse-kravet som kontrollkravet selv | Ville gjort leverandørkontroll avhengig av et annet tilvalg, og blandet «hva vi må» med «hva vi krever» |

### 6.5 Slik unngås parallelle kravmotorer

- Kontrollkrav har **ingen kilde-tabell, ingen generell etterlevelsesstatus, ingen revisjon** —
  bare forankring (lenke eller fritekst).
- Etterlevelse får **ingen** leverandørstatus. Ingen Etterlevelse-skjerm leser leverandørkontroll i v2
  (§18: mulig senere, eid av Etterlevelse, med lekkasjeanalyse).
- Kravmalene i Procynia beskriver *leverandørkrav*, ikke lover. Det er ingen lovkatalog i Supplier.
- Slettes et forankret Etterlevelse-krav (sjeldent; Etterlevelse har slettevern), nullstilles
  forankringen (`ON DELETE SET NULL (compliance_requirement_id)`, PostgreSQL 15+). Kontrollkravet består.

---

## 7. Kravnivå og obligatoriske krav (gates)

| Nivå | Betydning | Effekt på kontrollstatus |
|---|---|---|
| **Obligatorisk** | Leverandøren skal ikke brukes uten at dette er dokumentert — eller midlertidig akseptert | Ikke oppfylt → **Krever beslutning** |
| **Viktig** | Skal dokumenteres; mangel følges opp | Ikke oppfylt → kan ikke være «Godkjent», bare «Godkjent med oppfølging» |
| **Oppfølging** | Skal følges opp etter behov | Vises; påvirker ikke hvilken beslutning som er tillatt |

«Informativt» er ikke et kravnivå. Veiledning uten kontroll hører hjemme i Wiki (§10.5).

**Eksempel:** «Databehandleravtale», `level = mandatory`, `applies_when = [["processor"]]`. En
leverandør som behandler personopplysninger som databehandler (eller der rollen er ukjent), får
kravet. Uten kontroll med status Dokumentert eller gyldig Midlertidig akseptert: **Krever beslutning**.

---

## 8. Kravstatus per leverandør

### 8.1 Lagrede statuser (`supplier_requirement_evaluations.status`)

| Status | Betyr | Krav |
|---|---|---|
| **Dokumentert** (`documented`) | Kravet er oppfylt og dokumentert | ≥ 1 dokumentasjonsrad oppgitt; begrunnelse |
| **Delvis dokumentert** (`partially_documented`) | Noe mangler | Begrunnelse; dokumentasjon valgfri |
| **Mangler** (`missing`) | Ikke oppfylt eller ikke dokumentert | Begrunnelse |
| **Midlertidig akseptert** (`temporarily_accepted`) | Mangel akseptert til en dato (f.eks. DBA under signering) | Begrunnelse + `accepted_until` (≤ 12 mnd fram) |
| **Ikke relevant** (`not_relevant`) | Kravet passer ikke denne leverandøren | Begrunnelse. **Ikke tillatt** når kravet er Obligatorisk og gjelder via regel — da er profilen feil og skal rettes |

### 8.2 Beregnede visningsstatuser

| Visning | Når |
|---|---|
| **Ikke vurdert** | Gjeldende krav uten kontroll |
| **Må fornyes** | Siste kontroll er Dokumentert/Delvis og (a) eldre enn kravets kontrollintervall, eller (b) en oppgitt dokumentasjonsrad er utløpt (`valid_until < i dag`) eller erstattet |
| **Aksept utløpt** | Midlertidig akseptert og `accepted_until < i dag` |

Gjeldende kontroll = den med senest `evaluated_on`, deretter høyest id (som v1 §4.3).

### 8.3 Oppfylt-regel (brukes av gate og beslutning)

| Visningsstatus | Oppfylt? | Akseptert? |
|---|---|---|
| Dokumentert | ja | ja |
| Midlertidig akseptert (gyldig) | nei | ja |
| Ikke relevant | (teller ikke) | (teller ikke) |
| Ikke vurdert · Mangler · Delvis · Må fornyes · Aksept utløpt | nei | nei |

---

## 9. Kontrollstatus (toppstatus)

### 9.1 Statuser

| Kontrollstatus | Type | Når |
|---|---|---|
| **Krever beslutning** | Beregnet | Minst ett gjeldende **obligatorisk** krav er verken oppfylt eller akseptert (§8.3). Trumfer enhver tidligere beslutning |
| **Godkjent** | Beslutning | Siste beslutning = Godkjent, og ingen «Krever beslutning» |
| **Godkjent med oppfølging** | Beslutning | Siste beslutning = Godkjent med oppfølging, og ingen «Krever beslutning» |
| **Ikke godkjent for nye kjøp** | Beslutning | Siste beslutning = Ikke godkjent |
| **Ikke besluttet** | Beregnet | Ingen beslutning, og ingen «Krever beslutning» |
| *(kortet vises ikke)* | — | Leverandøren har ingen gjeldende kontrollkrav |

«Ikke godkjent for nye kjøp» vises også når «Krever beslutning» ellers ville gjelde — en negativ
beslutning trenger ingen ny beslutning for å stå.

### 9.2 Hva er tillatt å beslutte

| Beslutning | Tillatt når |
|---|---|
| **Godkjent** | Alle gjeldende obligatoriske og viktige krav er **oppfylt** |
| **Godkjent med oppfølging** | Alle gjeldende obligatoriske krav er **akseptert** (oppfylt eller gyldig midlertidig akseptert). Begrunnelse + hva som følges opp |
| **Ikke godkjent for nye kjøp** | Alltid. Begrunnelse |

Beslutninger er uforanderlige og lagrer øyeblikksbilde av grunnlaget (antall per visningsstatus og
listen over ikke-oppfylte obligatoriske/viktige krav med tittel og status).

### 9.3 Automatisk / menneskelig / aldri beregnet

| Automatisk (beregnet ved lesing) | Menneskelig beslutning (lagret, begrunnet) | Beregnes aldri |
|---|---|---|
| Kravprofil og grunn per krav | Profilsvar | Prosent, poeng, score |
| Visningsstatus per krav (Ikke vurdert, Må fornyes, Aksept utløpt) | Kravstatus per kontroll | «Samlet risiko» for leverandøren |
| «Krever beslutning» | Midlertidig aksept og frist | Godkjent/Ikke godkjent |
| «Ikke besluttet» | Godkjent / med oppfølging / Ikke godkjent | Kritikalitet fra profil |
| Hvilke beslutninger som er tillatt | Aktsomhetskonklusjon | Endring i livssyklus |
| Forfall og oppfølgingsplan | «Ikke relevant» | Automatisk opprettede avvik/risikoer |

### 9.4 Forhold til livssyklus

Kontrollstatus **endrer aldri** livssyklusen og **blokkerer ikke** «Ta i bruk». Dialogen «Ta i bruk»
viser en advarsel når kontrollstatus er «Krever beslutning» eller «Ikke godkjent for nye kjøp».
Begrunnelse: Procynia er ikke innkjøpssystemet; beslutningen skal være synlig og sporbar, ikke en
teknisk sperre som omgås utenfor systemet. Registeret får filter på kontrollstatus.

---

## 10. Dokumentasjon som grunnlag

### 10.1 Utvidelse av `supplier_documents`

Fortsatt **metadata, ingen filopplasting** (v1 §4.4). To endringer:

**Nye dokumenttyper** (CHECK utvides; eksisterende verdier beholdes):

| Ny type | Brukes til |
|---|---|
| `self_declaration` — Egenerklæring | Lønns- og arbeidsvilkår, menneskerettigheter, HMS, OTP |
| `code_of_conduct` — Etiske retningslinjer (signert/akseptert) | Etikk og seriøsitet |
| `audit_report` — Revisjonsrapport | Tredjepartsrevisjon, SOC 2, fabrikkrevisjon |
| `control_report` — Kontrollrapport | Egen kontroll: stikkprøve lønnsslipper, HMS-kort på byggeplass, stedlig kontroll |
| `subcontractor_list` — Underleverandørliste | Underleverandører og underdatabehandlere |
| `public_certificate` — Offentlig attest | Skatteattest, firmaattest, registerutskrift (renhold, bemanning) |
| `financial_statement` — Økonomisk dokumentasjon | Årsregnskap, kredittvurdering |
| `environmental_documentation` — Miljødokumentasjon | Klimaregnskap, miljørapport, miljødeklarasjon |
| `policy` — Policy/rutine | Sikkerhetspolicy, BCP, personvernerklæring |

**Nytt felt `standard`** (nullbar fritekst, forslag i UI): «ISO 27001», «ISO 9001», «ISO 14001»,
«ISO 45001», «ISO 22301», «Miljøfyrtårn», «EMAS», «SOC 2 Type II». Hjelper kontrolløren; brukes ikke
i regler (kontrollen er menneskelig, §8).

Feltene «vurdert av» og «dokumentert dato» hører til **kontrollen**, ikke dokumentet: dokumentet
beskriver hva som finnes; kontrollen sier hvem som så på det, når, og for hvilket krav.

### 10.2 Dokument ↔ krav

| Behov | Løsning |
|---|---|
| Ett dokument beviser flere krav | Flere kontroller oppgir samme dokumentasjonsrad |
| Ett krav krever flere dokumenter | Én kontroll oppgir flere rader |
| Hva ble lagt til grunn da? | `supplier_requirement_evaluation_documents` — uforanderlig, med øyeblikksbilde (type, tittel, standard, gyldig til) |
| Hvilke krav dekker dette dokumentet nå? | Beregnet: gjeldende kontroller som oppgir raden |
| Gyldighet / utløp | Fra dokumentraden; utløp gir «Må fornyes» på kravene som bygger på den (§8.2) |
| Ekstern plassering | `location` (v1) |

**Ingen egen, redigerbar dokument-krav-koblingstabell.** Koblingen *er* kontrollen. Det gir én
sannhet og tvinger fram en menneskelig kontroll når grunnlaget endres.

### 10.3 Fornyelse

«Registrer fornyet» (v1) beholdes. Er den gamle raden grunnlag i gjeldende kontroller, tilbyr
dialogen etterpå **«Bekreft kravene på nytt»**: en liste over berørte krav, forhåndsvalgt, som
registrerer nye kontroller med samme status, ny rad som grunnlag og felles begrunnelse. Hver blir en
egen uforanderlig kontroll; ingenting skjer uten at brukeren bekrefter.

### 10.4 Sletting

En dokumentasjonsrad som er oppgitt i en kontroll, kan ikke slettes (NO ACTION + tjenestesjekk med
melding «Brukt som dokumentasjon i en kontroll — registrer fornyet i stedet»). Rader som ikke er
brukt, kan slettes som i v1.

### 10.5 Wiki

Uendret fra v1: leverandørens dokumenter legges aldri i Wiki. Wiki eier **veiledningen** — «Slik
kontrollerer vi lønns- og arbeidsvilkår», «Slik gjennomfører vi aktsomhetsvurdering». En lenke fra
kontrollkrav til Wiki-side er utenfor v2 (§18).

### 10.6 Filopplasting

Utenfor v2. Mulig v2.1: privat modul-eid lager (v1 §4.4). Datamodellen over er laget slik at en fil
kan legges til på `supplier_documents` uten å endre kontrollene.

---

## 11. Aktsomhet og bærekraft

### 11.1 Prinsipp

Aktsomhetsvurderingen er **en prosess med dokumentert konklusjon**, ikke en score. Den følger
OECD-modellen:

```
Kartlegg → Vurder risiko → Undersøk → Tiltak → Følg opp → Dokumenter
```

### 11.2 Slik dekkes hvert steg — med gjenbruk

| Steg | Hvor | Gjenbruk |
|---|---|---|
| **Kartlegg** | Profilen: `production_outside_eea`, `high_risk_categories`, `uses_subcontractors`, `labour_intensive`, `sectors` + fritekst om produksjonssteder i vurderingen | Profil (§4) |
| **Vurder risiko** | `supplier_due_diligence_assessments` — per område: Lav · Forhøyet · Høy · Ukjent | Ny, uforanderlig |
| **Undersøk** | Kontrollkrav med tema `human_rights`/`labour_conditions` (egenerklæring, oversikt over produksjonssteder, tredjepartsrevisjon) | Kontroller (§8) + dokumentasjon (§10) |
| **Tiltak** | «Følg opp i Avvik og forbedringer» fra vurderingen | `SupplierImprovementHandoffService` + `ImprovementCaseCreator` |
| **Følg opp** | Tiltak og effektverifisering i Avvik og forbedringer; ny vurdering ved forfall | Improvements; `SupplierFollowUpPlan` |
| **Dokumenter** | Uforanderlige vurderinger + kontroller + dokumentasjon | Historikk |
| *(vesentlig risiko for virksomheten)* | «Opprett risiko» fra vurderingen | `SupplierRiskService` + `RiskCreator` |

### 11.3 Aktsomhetsvurdering — innhold

| Felt | |
|---|---|
| Områder (hver: `low`/`elevated`/`high`/`unknown`) | Barnearbeid · Tvangsarbeid · Arbeidsforhold (lønn, arbeidstid, HMS) · Diskriminering · Organisasjonsfrihet · Miljø |
| `supply_chain_description` | Kartlegging: produksjonsland, ledd, kjente underleverandører (fritekst) |
| `investigation_summary` | Hva som er undersøkt og hvordan |
| `conclusion` | `no_significant_risk` (Ingen vesentlig risiko avdekket) · `monitor` (Risiko følges opp) · `measures_required` (Tiltak kreves) |
| `rationale` | Påkrevd |
| `review_interval_months` | 6 · 12 · 24 — forhåndsutfylt 12 ved konklusjon ≠ `no_significant_risk`, ellers 24 |
| `assessed_on`, `assessed_by_user_id` | |
| Øyeblikksbilde | Leverandørnavn, kritikalitet, `high_risk_categories`, `production_outside_eea` |

**Når er den relevant?** Predikat `due_diligence_relevant` = `high_risk_products` ∨
`production_outside_eea` ∨ (`labour_intensive` ∧ `subcontractors`). Kortet «Aktsomhet og bærekraft»
vises alltid; uten relevans viser det «Ikke påkrevd ut fra profilen» og tillater likevel vurdering.

Konklusjonen velges av mennesket; den beregnes ikke fra områdene. «Tiltak kreves» tilbyr handoff til
Avvik og forbedringer (med proveniens).

### 11.4 Bærekraft og miljø

Ikke «ISO 14001: Ja/Nei». Miljø dekkes av fire mekanismer:

| Behov | Mekanisme |
|---|---|
| Miljøstyring | Kontrollkrav «Miljøledelse» — `guidance`: ISO 14001, EMAS, Miljøfyrtårn **eller tilsvarende dokumentasjon**; kontrolløren vurderer alternativ dokumentasjon |
| Kontraktsspesifikke miljøkrav | Leverandørspesifikke kontrollkrav (`supplier_id` satt), f.eks. «Minst 50 % utslippsfrie kjøretøy i kontraktsperioden», med eget intervall |
| Rapportering | Kontrollkrav «Klima-/miljørapportering» med `environmental_documentation` |
| Faktisk oppfølging | Kontroll med intervall + **femte kriterium i leverandørvurderingen**: «Bærekraft og ansvarlig virksomhet» (`sustainability_rating`, nullbar; gamle vurderinger = ikke vurdert på kriteriet) |
| Oppfølging mot mål | Utenfor v2 — ev. kobling eies av Mål og KPI (v1 §7.5) |

---

## 12. Informasjonssikkerhet og personvern

Ingen egen «sikkerhetsvurdering»-tabell. Sikkerhet og personvern er **temaer i kravprofilen**,
aktivert av profilen. Kortet «Sikkerhet og personvern» er en filtrert visning av kravprofilen
(`theme ∈ {information_security, privacy, continuity}`) pluss nøkkelfakta fra profilen
(databehandlerrolle, behandlingssted, underleverandører, privilegert tilgang).

| Utløser (predikat) | Krav som aktiveres (fra kravmal-biblioteket §16) |
|---|---|
| `system_access` | Tilgangsstyring og MFA (oblig.) · Varsling av sikkerhetshendelser (oblig.) |
| `privileged_access` | Logging og sporbarhet av tilgang |
| `stores_our_data` | Kryptering · Exit: tilbakelevering og sletting · Behandlingssted dokumentert |
| `processor` | Databehandleravtale (oblig.) · Underdatabehandlere (oblig.) · Varsling av hendelser (oblig.) |
| `personal_data` ∧ `data_outside_eea` | Overføringsgrunnlag utenfor EØS (oblig.) |
| `special_category_data` | Forsterkede tiltak for særlige kategorier |
| `stores_our_data` ∧ `criticality_important` | Styringssystem for informasjonssikkerhet |
| `stores_our_data` ∧ `criticality_critical` ∨ `privileged_access` ∧ `criticality_critical` | Uavhengig sikkerhetsrapport (oblig.) |
| `critical_delivery` | Kontinuitetsplan · Kritiske avhengigheter kartlagt |
| `critical_delivery` ∧ `stores_our_data` | Test av gjenoppretting dokumentert |
| `sector:ict` ∧ `system_access` | Sårbarhets- og patchhåndtering |

En leverandør uten systemtilgang, data eller personopplysninger får **ingen** av disse kravene.

---

## 13. Tilgang og sikkerhet

### 13.1 Beholdt fra v1

`supplier` entitlement (tilvalg) · `supplier.view/edit/assess/delete` · domenet i
`explicitGrantDomains()` · System Owner fail-closed · kundeglobal · `SupplierAccessService` som eneste
inngang · tenant-isolasjon med sammensatte FK.

### 13.2 Ny rettighet: `supplier.assure`

**Anbefaling: innfør `supplier.assure`.**

Begrunnelse:
- Å sette kravstatus, akseptere obligatoriske mangler midlertidig og godkjenne en leverandør er en
  **kontrollfunksjon** (innkjøp, personvernombud, sikkerhet, HMS) — en annen kompetanse og et annet
  ansvar enn å registrere leverandøren (`edit`) eller vurdere leveransen (`assess`, ofte intern
  ansvarlig).
- Uten egen rettighet må enten `edit` eller `assess` gi godkjenningsmakt, og da kan den som eier
  leverandørforholdet godkjenne sin egen leverandør uten at virksomheten har valgt det.
- Samme mønster som `risk.assess`, `compliance.assess`, `compliance.audit`.

| Handling | Rettighet |
|---|---|
| Se alt i Leverandørkontroll (katalog, kravprofil, kontroller, beslutninger, aktsomhet) | `supplier.view` |
| Fylle ut / endre profil | `supplier.edit` (fakta om leverandøren) |
| Kontrollkrav-katalog, ta i bruk kravmal, utgå/slette kontrollkrav | `supplier.assure` |
| Legge til krav manuelt, leverandørspesifikt krav | `supplier.assure` |
| Registrere kontroll, midlertidig aksept, «Ikke relevant» | `supplier.assure` |
| Registrere beslutning | `supplier.assure` |
| Aktsomhetsvurdering | `supplier.assure` |
| Dokumentasjonsrad (ny/endre/fornyet) | `supplier.edit` **eller** `supplier.assure` (kontrolløren må kunne registrere grunnlaget sitt) |
| Handoff til Avvik/Risiko fra kontroll eller aktsomhet | `supplier.assure` + målmodulens rettighet (sjekket av målmodulen) |
| Handoff fra leverandør/vurdering (v1) | uendret: `supplier.edit` + målmodulens rettighet |

`edit` gir ikke `assure`, `assess` gir ikke `assure`, `assure` gir ikke `edit` eller `assess`.
System Owner får ikke `assure` automatisk (domenet er explicit-grant).

**Ikke innført:** firøyneprinsipp (den som kontrollerer kan også beslutte). Utenfor v2 (§18).

### 13.3 Felles regler

- Avsluttet leverandør: alle v2-skrivinger nektes (profil, kontroller, beslutninger, aktsomhet,
  manuelle krav, leverandørspesifikke krav). Lesing som før.
- Katalogkrav er kundeglobale; leverandørspesifikke krav arver leverandørens tilgang.
- Forankring i Etterlevelse leses bare via `ComplianceAccessService`; uten tilgang finnes den ikke i
  payload (heller ikke som «skjult forankring»).
- Rader i kravprofilen inneholder bare supplier-data.

---

## 14. Oppfølgingsplan

**Ikke et nytt oppgavesystem.** Planen er en beregnet liste per leverandør (`SupplierFollowUpPlan`),
vist som kortet «Neste kontroller», og kilden til Trenger oppmerksomhet.

| Kilde | Neste dato |
|---|---|
| Kontrollkrav med intervall | Gjeldende kontroll `evaluated_on` + `control_interval_months` |
| Kontrollkrav uten intervall | Tidligste `valid_until` blant oppgitte dokumenter |
| Midlertidig aksept | `accepted_until` |
| Dokumentasjon (v1) | `valid_until` |
| Leverandørvurdering (v1) | `SupplierReviewSchedule` |
| Aktsomhetsvurdering | `assessed_on` + `review_interval_months` |
| `on_change`-krav | Ingen dato — vises som «Kontrolleres ved endring: …» |

Alle beregnes med samme konvensjon som `RiskReviewSchedule` (`addMonthsNoOverflow`, forfalt fra
dagen etter). Ingen `next_*`-kolonner lagres.

| Mekanisme | Brukes til | Ikke brukt til |
|---|---|---|
| Beregnet plan | Hva forfaller når | — |
| Trenger oppmerksomhet | Forfalt / mangler / krever beslutning | — |
| Avvik og forbedringer | Avvik, tiltak, effektverifisering, frister på tiltak | Rutinekontroller |
| Bjella / Mine oppgaver | — | Utenfor v2 (felles for styringsmodulene, v1 §13) |

**Eksempler (oppfølgingsplan fra kravmal):**

| Leverandør | Plan |
|---|---|
| Kritisk IT-leverandør | Uavhengig sikkerhetsrapport årlig · DBA gjennomgås hver 24. mnd · Underdatabehandlere årlig · Sertifikat ved utløp · Leverandørvurdering hver 12. mnd |
| Entreprenør (bygg og anlegg) | Kvalifikasjonskrav før oppstart · Lønns- og arbeidsvilkår kontrolleres hver 6. mnd · HMS-kort hver 6. mnd · Ved ny underleverandør (on_change) · Aktsomhet årlig ved relevans |

Kvartalsvis hendelsesoppfølging hos IKT-leverandører er en dialog, ikke en kontroll; den er utenfor
planen (dekkes av leverandørvurderingen og av saker i Avvik og forbedringer ved hendelser).

---

## 15. Trenger oppmerksomhet

### 15.1 Nye lokale signaler

Samme form og regler som v1 §8: faste regler, beregnet ved lesing, bare **Supplier-tabeller**
(inkl. `supplier_control_requirements`), over leverandører fra `visibleSuppliers()`, ikke avsluttede,
ingen score. Gjelder bare leverandører som har minst ett gjeldende kontrollkrav — kunder uten katalog
får ingen nye signaler.

| # | Signal | Regel | Status |
|---|---|---|---|
| 6 | **Krever beslutning** | Kontrollstatus = Krever beslutning (§9.1) | Ikke avsluttet |
| 7 | **Krav ikke vurdert** | Gjeldende obligatorisk eller viktig krav uten kontroll; for Under vurdering bare `before_contract` | Ikke avsluttet |
| 8 | **Kontroll forfalt** | Gjeldende krav med visningsstatus Må fornyes eller Aksept utløpt | Aktiv |
| 9 | **Profil ikke fylt ut** | Viktig/Kritisk, profil ikke komplett, kunden har aktive katalogkrav | Ikke avsluttet |
| 10 | **Aktsomhetsvurdering mangler** | `due_diligence_relevant`, ingen aktsomhetsvurdering | Ikke avsluttet |
| 11 | **Aktsomhetsvurdering forfalt** | Neste aktsomhetsvurdering passert | Aktiv |

«Sikkerhetsvurdering mangler» er ikke et eget signal: det er signal 6/7 for sikkerhetskrav. Ett
signal per regel, ikke per tema.

«Dokument utløpt/utløper snart» (v1 #4/#5) beholdes uendret.

### 15.2 Cross-domain — fortsatt utenfor

Åpent avvik hos leverandøren, høy risiko koblet til leverandøren, Etterlevelse-status på forankret
krav. Krever tilgangsbevisst lesing av andre moduler; et skjult objekt kan da skru et signal av eller
på og røpe at det finnes. Uendret fra v1 §8.

---

## 16. Kravmaler

### 16.1 Mekanisme

- Kravmalene ligger **i kode** (`App\Support\Suppliers\RequirementTemplates\*`), versjonert, oversatt
  i lang-filene. Ingen database-tabell for maler.
- Malene er **utvalg fra ett felles kravbibliotek** (§16.3). Samme krav (f.eks. DBA) finnes i flere
  maler, men opprettes bare én gang per kunde: unik `(customer_id, template_item_key)`.
- «Ta i bruk kravmal» (`supplier.assure`) viser kravene malen legger til, hva kunden allerede har, og
  oppretter de manglende som kundens egne kontrollkrav med proveniens (`template_key`,
  `template_item_key`, `template_version`). Kunden kan deretter endre tekst, nivå, intervall og regel.
- Senere malversjoner oppdaterer **aldri** kundens krav automatisk. (Visning av «nyere malversjon
  finnes» er utenfor v2.)
- Malene er knyttet til leverandører gjennom **profilen**, ikke ved å tildele en mal til en
  leverandør. Malen fyller katalogen; regelen avgjør hvem kravet gjelder.
- **Faglig kvalitetssikring:** malinnhold (tekst, nivå, hjemmel) gjennomgås faglig før det leveres
  til kunder (§19.2). Malene sier «typisk grunnlag», ikke juridisk fasit.

### 16.2 Malene

Intervall i måneder; «utløp» = ved dokumentets utløp. **Fet** = obligatorisk (gate).

| # | Mal | Typisk profil | Temaer | Krav (biblioteksnøkler §16.3) | Gates | Fase |
|---|---|---|---|---|---|---|
| 1 | **Offentlig sektor – generell leverandør** | Alle; strengere ved Viktig/Kritisk og `public_contract_terms` | Etikk, økonomi, kvalitet, lønn, miljø | E1, E2, F1, F2, Q1, L1, M1 | **L1** (ved `public_contract_terms`) | 5 |
| 2 | **IT/SaaS-leverandør** | `sector:ict`, `stores_our_data`, `system_access` | Sikkerhet, personvern, kontinuitet | S1, S2, S4, S5, S6, S8, P1, P2, P3, P4, C1 | **S2, S4, P1, P2, P4** | 5 |
| 3 | **Databehandler** | `processor` | Personvern, sikkerhet | P1, P2, P3, P4, P5, S4, S6, S8 | **P1, P2, P4, S4** | 5 |
| 4 | **Produktleverandør med menneskerettighetsrisiko** | `high_risk_products`, `production_outside_eea` | Menneskerettigheter, arbeidsforhold, miljø | H1, H2, H3, E1, M1 + aktsomhetsvurdering | **H1** | 7 |
| 5 | **Kritisk IKT-leverandør** | `sector:ict` ∧ `criticality_critical` | Sikkerhet, kontinuitet, økonomi | Mal 2 + S3, S7, C2, C3, F2, Q2 | **S7** + mal 2 | 8 |
| 6 | **Bygg og anlegg** | `sector:construction`, `labour_intensive`, `subcontractors`, `on_site_work` | Lønn, HMS, seriøsitet, miljø | E2, L1, L2, L3, L4, L5, B1, F1, M1, M2 | **L1, L3** (+ E2 anbefalt obligatorisk, §16.3) | 8 |
| 7 | **Renhold** | `sector:cleaning`, `labour_intensive`, `on_site_work` | Lønn, HMS, seriøsitet | R1, E2, L1, L2, L3, L4, F1 | **R1, L1, L3** | 8 |
| 8 | **Bemanning** | `sector:staffing` | Lønn, likebehandling, seriøsitet | ST1, ST2, L1, L4, E2 | **ST1** | 8 |
| 9 | **Helse** | `sector:health_care`, `personal_data`, `special_category_data`, `on_site_work` | Personvern, sikkerhet, taushetsplikt | HE1, HE2, HE3, P1, P2, P4, P5, S2, S4 | **HE1, HE2, P1** | 8 |

Rekkefølgen: 1–3 først (fase 5) fordi de dekker flest kunder og alle digitale leverandører. Mal 4
følger aktsomhetsvurderingen (fase 7). 5–9 til slutt (fase 8).

### 16.3 Kravbiblioteket (første versjon)

Nivå: **O** = obligatorisk, V = viktig, F = oppfølging. Punkt: FK = før avtale, L = løpende, E = ved
endring. Intervall i måneder; «utløp» = ved dokumentets utløp.

| Nøkkel | Krav | Tema | Nivå | Gjelder når (`applies_when`) | Punkt | Int. | Dokumentasjon |
|---|---|---|---|---|---|---|---|
| E1 | Etiske retningslinjer for leverandører akseptert | ethics | V | alle | FK | 24 | code_of_conduct |
| E2 | Skatteattest og firmaattest | ethics | V (O i mal 6) | `public_contract_terms` ∨ `sector:construction` ∨ `sector:cleaning` ∨ `sector:staffing` | FK | 12 | public_certificate |
| F1 | Ansvarsforsikring | financial | V | `on_site_work` ∨ `criticality_critical` | FK | utløp | insurance_certificate |
| F2 | Økonomisk bæreevne vurdert | financial | V | `hard_to_replace` ∨ `criticality_critical` | L | 12 | financial_statement |
| Q1 | Kvalitetssystem (ISO 9001 eller tilsvarende) | quality | F | `criticality_important` | FK | utløp | certificate, policy |
| Q2 | Avvikshåndtering og rapportering til oss | quality | F | `criticality_critical` | L | 24 | policy, agreement |
| L1 | Lønns- og arbeidsvilkår (egenerklæring) | labour_conditions | O | `public_contract_terms` | FK | 12 | self_declaration |
| L2 | Lønns- og arbeidsvilkår kontrollert (arbeidsavtaler, timelister, lønnsslipper) | labour_conditions | V | `public_contract_terms` ∧ `labour_intensive` | L | 6 | control_report |
| L3 | HMS-kort for alle på arbeidsplassen | labour_conditions | O | `sector:construction` ∨ `sector:cleaning` | L | 6 | control_report |
| L4 | Obligatorisk tjenestepensjon | labour_conditions | V | `labour_intensive` | FK | 24 | self_declaration |
| L5 | Underleverandører godkjent og begrenset antall ledd | labour_conditions | V | `subcontractors` ∧ `labour_intensive` | E | — | subcontractor_list |
| B1 | SHA-koordinering avklart | labour_conditions | V | `sector:construction` ∧ `on_site_work` | FK | — | policy, agreement |
| R1 | Godkjent renholdsvirksomhet (offentlig register) | labour_conditions | O | `sector:cleaning` | FK | 12 | public_certificate |
| ST1 | Registrert bemanningsforetak (offentlig register) | labour_conditions | O | `sector:staffing` | FK | 12 | public_certificate |
| ST2 | Likebehandling av innleide | labour_conditions | V | `sector:staffing` | L | 12 | self_declaration, control_report |
| H1 | Egenerklæring om menneskerettigheter og arbeidsforhold i leverandørkjeden | human_rights | O | `high_risk_products` ∨ `production_outside_eea` | FK | 12 | self_declaration |
| H2 | Oversikt over produksjonssteder i leverandørkjeden | human_rights | V | `high_risk_products` | L | 12 | subcontractor_list |
| H3 | Tredjepartsrevisjon eller kontroll av produksjonssted | human_rights | V | `high_risk_products` ∧ `criticality_important` | L | 24 | audit_report, control_report |
| M1 | Miljøledelse (ISO 14001, EMAS, Miljøfyrtårn eller tilsvarende) | environment | V | `environmental_impact` | FK | utløp | certificate, environmental_documentation |
| M2 | Kontraktsfestede miljøkrav fulgt opp | environment | V | `environmental_impact` ∧ `public_contract_terms` | L | 12 | environmental_documentation, control_report |
| M3 | Klima-/miljørapportering | environment | F | `environmental_impact` ∧ `criticality_important` | L | 12 | environmental_documentation |
| S1 | Styringssystem for informasjonssikkerhet (ISO 27001, SOC 2 Type II eller tilsvarende) | information_security | V | `stores_our_data` ∧ `criticality_important` | L | utløp | certificate, audit_report |
| S2 | Tilgangsstyring og MFA | information_security | O | `system_access` | FK | 12 | security_documentation, policy |
| S3 | Logging og sporbarhet av tilgang | information_security | V | `privileged_access` | L | 12 | security_documentation |
| S4 | Varsling av sikkerhetshendelser og personvernbrudd (frist, kontaktpunkt) | information_security | O | `system_access` ∨ `processor` ∨ `confidential_information` | FK | 24 | agreement, data_processing_agreement |
| S5 | Sårbarhets- og patchhåndtering | information_security | V | `sector:ict` ∧ `system_access` | L | 12 | security_documentation |
| S6 | Kryptering i transitt og lagring | information_security | V | `stores_our_data` | FK | 24 | security_documentation |
| S7 | Uavhengig sikkerhetsrapport | information_security | O | (`stores_our_data` ∧ `criticality_critical`) ∨ (`privileged_access` ∧ `criticality_critical`) | L | 12 | audit_report, certificate |
| S8 | Exit: tilbakelevering og sletting av data | information_security | V | `stores_our_data` | FK | 24 | agreement, data_processing_agreement |
| P1 | Databehandleravtale | privacy | O | `processor` | FK | 24 | data_processing_agreement |
| P2 | Underdatabehandlere oversikt og godkjenning | privacy | O | `processor` | L/E | 12 | subcontractor_list |
| P3 | Behandlingssted dokumentert | privacy | V | `processor` ∨ `stores_our_data` | L | 12 | data_processing_agreement, security_documentation |
| P4 | Overføringsgrunnlag utenfor EØS og vurdering av overføringen | privacy | O | `personal_data` ∧ `data_outside_eea` | FK | 12 | data_processing_agreement, other |
| P5 | Forsterkede tiltak for særlige kategorier | privacy | V | `special_category_data` | FK | 12 | security_documentation |
| C1 | Kontinuitetsplan | continuity | V | `critical_delivery` | L | 12 | policy |
| C2 | Test av gjenoppretting dokumentert | continuity | V | `critical_delivery` ∧ `stores_our_data` | L | 12 | control_report, audit_report |
| C3 | Kritiske avhengigheter og underleverandører kartlagt | continuity | F | `critical_delivery` ∧ `subcontractors` | L | 24 | subcontractor_list |
| HE1 | Etterlevelse av bransjenorm for informasjonssikkerhet i helsesektoren | information_security | O | `sector:health_care` ∧ `personal_data` | FK | 12 | self_declaration, security_documentation |
| HE2 | Taushetserklæring for personell | privacy | O | `sector:health_care` ∧ `on_site_work` | FK | — | confidentiality_agreement |
| HE3 | Politiattest der regelverket krever det | ethics | V | `sector:health_care` ∧ `on_site_work` | FK | — | control_report |

Nivå for samme biblioteknøkkel er ett nivå. Når en mal trenger et strengere nivå (E2 i mal 6),
viser malen det som anbefaling ved import; kunden velger. Biblioteket inneholder ingen
lovhenvisninger som tekst i v2 utover `basis_text`-forslag som kvalitetssikres (§19.2).

---

## 17. Migreringsstrategi

**Ingen eksisterende data slettes, flyttes eller omtolkes.**

| v1-data | Etter v2 |
|---|---|
| `suppliers` | Uendret. Ingen profil-rad før noen fyller ut profilen. De fire kritikalitetsspørsmålene brukes umiddelbart av predikatene |
| `supplier_criticality_changes`, `supplier_status_changes` | Uendret |
| `supplier_assessments` | Beholdes. Ny nullbar `sustainability_rating`; triggerfunksjonen erstattes (`CREATE OR REPLACE`) og dekker kolonnen. Gamle rader = `null` = «ikke vurdert på dette kriteriet» |
| `supplier_documents` | Beholdes. CHECK utvides med nye typer; `standard` nullbar. Eksisterende rader kan oppgis i kontroller umiddelbart |
| `supplier_compliance_requirements` | Beholdes uendret og vises under *Krav og kvalifikasjoner*. **Ingen backfill** til kontrollkrav: en v1-kobling sa «kravet er relevant for leverandøren», ikke «vi kontrollerer det». Å backfille ville skapt «Ikke vurdert»-signaler kunden ikke har bedt om |
| `supplier_improvement_cases`, `supplier_risks` | Nye nullbare proveniens-kolonner (§20); gamle rader `null` |
| Kunde uten kontrollkrav | Ingen kravprofil, ingen kontrollstatus-kort, ingen nye signaler. Leverandørkontroll tas i bruk ved første kravmal eller første egne kontrollkrav |
| Leverandør uten profil, kunde med katalog | Bare krav med `applies_when = []` eller som slår til på kritikalitets-/v1-predikater gjelder; «Profil ikke fylt ut» meldes for Viktig/Kritisk |

**Ingen backfill-migrasjon.** Alle nye kolonner er nullbare eller har tom standard. Alle migrasjoner
er reversible (`down()` dropper tabeller/kolonner og `DROP FUNCTION IF EXISTS`, og gjeninnfører
v1-CHECK — `down()` på dokumenttype-CHECK feiler bevisst hvis nye typer er i bruk).

`Supplier::isDeletable()` utvides: profil-endringer, kontroller, beslutninger, aktsomhetsvurderinger,
manuelle kravtilknytninger og leverandørspesifikke krav gjør leverandøren ikke-slettbar.

---

## 18. Eksplisitt utenfor v2

| Utenfor | Hvorfor / når |
|---|---|
| Filopplasting / dokumentlager | v2.1 (§10.6) |
| Leverandørportal, egenerklæring/spørreskjema sendt til leverandøren | Eksterne brukere = ny sikkerhetsmodell |
| AI-forslag til profil, kravstatus, beslutning; AI-lesing av sertifikater | Ikke i v2 |
| Score, vekting, prosent, risikomatrise for leverandøren | Prinsipp 5 |
| Landrisiko-feeds, kredittsjekk, Brønnøysund-/register-oppslag | Ekstern integrasjon, kostnad |
| Sikkerhetsloven / skjermingsverdige leveranser | Eget regime med egne krav; kan bli egen kravmal senere |
| Modellering av anskaffelsen (konkurranse, kravspesifikasjon, tildelingskriterier) | §5.4 |
| Etterlevelse-visning av leverandørkontroll («3 av 5 databehandlere mangler DBA») | Eies av Etterlevelse; krever lekkasjeanalyse |
| Lenke fra kontrollkrav til Wiki-veiledning | Senere; Wiki eier innholdet |
| Leverandørrevisjon som planlagt revisjon (`ComplianceAudit`) | §6.4 |
| Firøyneprinsipp på beslutning | Senere ved behov |
| Automatisk oppdatering fra nyere kravmalversjon | Senere |
| Cross-module signaler i Trenger oppmerksomhet; bjelle / Mine oppgaver | §15.2 |
| KPI-kobling for miljø-/leverandørmål | Eies av Mål og KPI |
| Historikk på endringer i kontrollkrav-katalogen | Kontroller og beslutninger tar øyeblikksbilde; kataloghistorikk ved behov |
| Import av leverandører/profiler fra Excel | Senere |

---

## 19. Beslutninger

### 19.1 Foreslått låst

| Beslutning | Se |
|---|---|
| Navn: **Leverandørkontroll**; toppstatus **Kontrollstatus**; katalog **Kontrollkrav**; maler **Kravmaler** | §1.1 |
| Leverandørvurdering og Leverandørkontroll er to domener og to tabellsett | §2.1 |
| Profil i egen tabell; v1-kritikalitetsspørsmål gjenbrukes, flyttes ikke | §4 |
| «Vet ikke» utløser kravet; helt tom profil gjør det ikke | §4, §5.3 |
| Anvendelsesregel = DNF over faste PHP-predikater; ingen uttrykksspråk | §5.3 |
| Kravprofilen beregnes, lagres aldri | §5.2 |
| Kravtype uttrykkes som kontrollpunkt + forankring; kravspesifikasjon/tildelingskriterium modelleres ikke | §5.4 |
| Kontrollkrav eies av Supplier; valgfri forankring i Etterlevelse; ingen avhengighet av Etterlevelse-tilvalget | §6 |
| Tre kravnivåer: Obligatorisk, Viktig, Oppfølging | §7 |
| Egen kravstatusmodell (5 lagrede + 3 beregnede); «Dokumentert» krever dokumentasjon | §8 |
| «Ikke relevant» ikke tillatt for obligatorisk krav som gjelder via regel | §8.1 |
| Kontrollstatus: Krever beslutning (beregnet) + tre beslutninger + Ikke besluttet; ingen score | §9 |
| Kontrollstatus blokkerer ikke livssyklus | §9.4 |
| Dokument ↔ krav-koblingen er kontrollen; ingen redigerbar koblingstabell | §10.2 |
| Brukt dokumentasjonsrad kan ikke slettes | §10.4 |
| Aktsomhetsvurdering som egen uforanderlig vurdering; tiltak via Avvik, risiko via Risiko | §11 |
| Sikkerhet/personvern som temaer i kravprofilen, ingen egen vurderingstabell | §12 |
| Ny rettighet `supplier.assure` | §13.2 |
| Ingen nytt oppgavesystem; beregnet oppfølgingsplan + lokale signaler | §14, §15 |
| Kravmaler i kode; felles bibliotek; aldri auto-oppdatering | §16.1 |
| Ingen backfill; v1-krav-koblinger beholdes som de er | §17 |

### 19.2 Åpne spørsmål

**Ingen blokkerer fase 1–4.**

| # | Spørsmål | Blokkerer | Anbefalt standard hvis ikke avklart |
|---|---|---|---|
| 1 | Hvem kvalitetssikrer innholdet i kravmalene (tekst, nivå, `basis_text`) faglig/juridisk før de leveres til kunder? | Fase 5 (kunde-lansering av maler) | Malene merges med `basis_text` tomt og «typisk grunnlag» i veiledningen; hjemmelstekst legges inn etter faglig gjennomgang |
| 2 | Skal kravmalene være tilgjengelige for alle kunder med `supplier`, eller være en del av et eget tilvalg? | Fase 5 | Alle kunder med `supplier`; ingen entitlement-endring |

---

## 20. Datamodell

Felles for alle nye tabeller: `customer_id` FK → `customers` med `cascadeOnDelete`; `unique(id,
customer_id)` der andre tabeller peker inn; barn mot leverandør via sammensatt FK
`(supplier_id, customer_id) → suppliers(id, customer_id)` NO ACTION (mønster fra v1 §10).
Uforanderlige tabeller: modellen kaster på `updating`/`deleting`, og en PL/pgSQL-trigger
(`CREATE OR REPLACE FUNCTION`) nekter UPDATE (unntatt null-stilling av bruker-FK ved brukersletting)
og DELETE mens kunden finnes — som `compliance_assessments`. CHECK-er på alle kodeverdier og ikke-tomme
tekster. `SupplierE2EFixture` må slå av de nye triggerne ved opprydding.

### `supplier_profiles` — mutable (gjeldende tilstand)

| | |
|---|---|
| Formål | Leverandørprofil (§4) |
| Nøkler | `supplier_id` PK; `customer_id`; FK `(supplier_id, customer_id) → suppliers` NO ACTION |
| Felt | `data_role`, `special_category_data`, `stores_our_data`, `confidential_information`, `privileged_access`, `data_location`, `uses_subcontractors`, `production_outside_eea`, `high_risk_categories` (jsonb array), `on_site_work`, `labour_intensive`, `sectors` (jsonb array), `public_contract_terms`, `significant_environmental_impact`, `completed_at`, `updated_by` (nullOnDelete), timestamps |
| Constraints | CHECK per enum (`yes`/`no`/`unknown`, `data_role`, `data_location`); `jsonb_typeof(...) = 'array'`; kodeverdier i arrayene valideres i tjenesten |
| Sletting | Aldri alene; følger leverandøren (som da ikke er slettbar, §17) |
| Indekser | `customer_id` |

### `supplier_profile_changes` — immutable

| | |
|---|---|
| Formål | Historikk for profilen (§4.4) |
| Felt | `supplier_id`, `customer_id`, `from_profile` (jsonb, null ved første), `to_profile` (jsonb), `reason` (påkrevd når `from_profile` ikke er null), `changed_by_user_id` (nullOnDelete), `changed_at` |
| Constraints | CHECK begrunnelse; trigger |
| Indekser | `(supplier_id, changed_at, id)` |

### `supplier_control_requirements` — mutable

| | |
|---|---|
| Formål | Kontrollkrav-katalogen + leverandørspesifikke krav (§5.1) |
| Felt | `title`, `description`, `guidance`, `theme`, `level`, `control_point`, `control_interval_months` (nullbar), `applies_when` (jsonb, standard `[]`), `accepted_document_types` (jsonb array), `basis_text`, `compliance_requirement_id` (nullbar), `supplier_id` (nullbar), `status` (`active`/`retired`), `template_key`, `template_item_key`, `template_version` (nullbare), `created_by`, `updated_by`, timestamps |
| FK | `(compliance_requirement_id, customer_id) → compliance_requirements(id, customer_id)` `ON DELETE SET NULL (compliance_requirement_id)`; `(supplier_id, customer_id) → suppliers` NO ACTION |
| Constraints | `unique(id, customer_id)`; partiell unik `(customer_id, template_item_key) WHERE template_item_key IS NOT NULL`; CHECK `theme`, `level`, `control_point`, `status`, `control_interval_months IN (3,6,12,24,36)`; CHECK `supplier_id IS NULL OR applies_when = '[]'`; CHECK `jsonb_typeof(applies_when) = 'array'`; predikatnavn valideres i tjenesten |
| Sletting | Bare når ubrukt (ingen kontroller, ingen manuelle tilknytninger); ellers «Utgått». Utgått krav gjelder ingen; kontroller beholdes |
| Indekser | `(customer_id, status)`, `supplier_id` |

### `supplier_requirement_inclusions` — mutable kobling

| | |
|---|---|
| Formål | Katalogkrav lagt til én leverandør manuelt (§5.2) |
| Felt | `supplier_id`, `requirement_id`, `reason`, `created_by`, `created_at` |
| FK | Sammensatt mot `suppliers` og `supplier_control_requirements`, begge NO ACTION |
| Constraints | `unique(supplier_id, requirement_id)`; tjenestesjekk: kravet er et katalogkrav (`supplier_id IS NULL`) |
| Sletting | Bare når det ikke finnes kontroll av kravet for leverandøren; ellers «Ikke relevant» |

### `supplier_requirement_evaluations` — immutable

| | |
|---|---|
| Formål | Kontroll: kravstatus for (leverandør, krav) (§8) |
| Felt | `supplier_id`, `requirement_id`, `status`, `rationale`, `accepted_until` (nullbar), `evaluated_on` (dato, ikke fram i tid), `evaluated_by_user_id` (nullOnDelete), `recorded_at`; øyeblikksbilde `requirement_title`, `requirement_level`, `requirement_theme`, `applicability_reason` (tekst), `supplier_name`, `criticality` |
| FK | Sammensatt mot `suppliers` og `supplier_control_requirements` NO ACTION; `unique(id, supplier_id)` for proveniens |
| Constraints | CHECK `status`; CHECK `(status = 'temporarily_accepted') = (accepted_until IS NOT NULL)`; CHECK begrunnelse ikke tom; «Dokumentert krever dokument» og «ikke relevant for obligatorisk regelkrav» i tjenesten (krever lesing på tvers av rader) |
| Indekser | `(supplier_id, requirement_id, evaluated_on, id)`, `customer_id` |

### `supplier_requirement_evaluation_documents` — immutable

| | |
|---|---|
| Formål | Dokumentasjon lagt til grunn i en kontroll (§10.2) |
| Felt | `evaluation_id`, `supplier_document_id`, øyeblikksbilde `document_type`, `document_title`, `document_standard`, `document_valid_until` |
| FK | `evaluation_id` → evaluations NO ACTION; `supplier_document_id` → `supplier_documents` NO ACTION (gjør raden uslettbar); tjenestesjekk samme leverandør |
| Constraints | `unique(evaluation_id, supplier_document_id)`; trigger |
| Indekser | `supplier_document_id` |

### `supplier_assurance_decisions` — immutable

| | |
|---|---|
| Formål | Kontrollbeslutning (§9) |
| Felt | `supplier_id`, `decision` (`approved`/`approved_with_follow_up`/`not_approved`), `rationale`, `follow_up_note` (påkrevd ved `approved_with_follow_up`), `decided_on`, `decided_by_user_id` (nullOnDelete), `recorded_at`, `basis_snapshot` (jsonb: antall per visningsstatus + ikke-oppfylte obligatoriske/viktige krav med id, tittel, nivå, status), `supplier_name`, `criticality` |
| Constraints | CHECK `decision`; CHECK follow-up-note; trigger. «Tillatt beslutning» (§9.2) håndheves i tjenesten med leverandørraden låst |
| Indekser | `(supplier_id, decided_on, id)` |

### `supplier_due_diligence_assessments` — immutable

| | |
|---|---|
| Formål | Aktsomhetsvurdering (§11) |
| Felt | `supplier_id`, `child_labour_risk`, `forced_labour_risk`, `working_conditions_risk`, `discrimination_risk`, `freedom_of_association_risk`, `environment_risk` (`low`/`elevated`/`high`/`unknown`), `supply_chain_description`, `investigation_summary`, `conclusion`, `rationale`, `review_interval_months`, `assessed_on`, `assessed_by_user_id` (nullOnDelete), `recorded_at`, øyeblikksbilde `supplier_name`, `criticality`, `high_risk_categories`, `production_outside_eea` |
| Constraints | CHECK-verdier; `review_interval_months IN (6,12,24)`; `unique(id, supplier_id)`; trigger |
| Indekser | `(supplier_id, assessed_on, id)` |

### Endringer i v1-tabeller

| Tabell | Endring |
|---|---|
| `supplier_documents` | CHECK `document_type` utvides (§10.1); ny nullbar `standard` |
| `supplier_assessments` | Ny nullbar `sustainability_rating` (CHECK som de andre); triggerfunksjon erstattes |
| `supplier_improvement_cases` | Nye nullbare `supplier_requirement_evaluation_id` (FK `(id, supplier_id)`), `supplier_due_diligence_assessment_id` (FK `(id, supplier_id)`); CHECK høyst én av tre proveniens-id-er; utvidet `handoff_only`-CHECK |
| `supplier_risks` | Ny nullbar `supplier_due_diligence_assessment_id` (FK `(id, supplier_id)`); bare med `origin = created_from_supplier` |

**Ikke i datamodellen:** score/prosent, lagret kravprofil, lagret kontrollstatus, `next_*`-datoer,
redigerbar dokument-krav-kobling, mal-tabeller, kopi av Etterlevelse-status, fagområde.

---

## 21. Tjenester

| Tjeneste | Ansvar | Mønster |
|---|---|---|
| `SupplierProfilePredicates` | Ren klasse: profil + leverandør → sanne predikater, med lesbar tekst | Ren, enhetstestet |
| `SupplierRequirementApplicability` | Gjeldende krav + grunn for én eller mange leverandører (batch) | Ren over forhåndslastede data |
| `SupplierProfileService` | Lagre profil + historikkrad, låst leverandør, én transaksjon | `SupplierCriticalityService` |
| `SupplierControlRequirementService` | Katalog-CRUD, utgå, slettevern, validering av regel | `ComplianceRequirementLifecycleService` |
| `SupplierRequirementInclusionService` | Manuell tilknytning | |
| `SupplierRequirementEvaluationService` | Kontroll + dokumentgrunnlag; «bekreft på nytt» i batch | `SupplierAssessmentService` |
| `SupplierRequirementStatus` | Visningsstatus per krav (§8.2–8.3) | Ren |
| `SupplierAssuranceResolver` | Kontrollstatus + tillatte beslutninger (§9) | `ComplianceStatusResolver` (form, ikke data) |
| `SupplierAssuranceDecisionService` | Beslutning med låst leverandør og øyeblikksbilde | |
| `SupplierDueDiligenceService` | Aktsomhetsvurdering + handoff-proveniens | `SupplierAssessmentService` |
| `SupplierFollowUpPlan` | Beregnet plan (§14) | `SupplierReviewSchedule` |
| `SupplierRequirementTemplateLibrary` | Bibliotek + maler; «ta i bruk» | Kode |
| `SupplierAttentionService` | Utvides med signal 6–11 | v1 |

---

## 22. UI

### 22.1 Leverandørsiden

**Hode:** navn · livssyklusstatus · kritikalitet · **Kontrollstatus** (når kravprofil finnes) ·
handlinger etter tilgang.

**Oversikt** (standardvisning) — kort i fast rekkefølge, hvert med 1–3 linjer og «Åpne»:

| Kort | Viser | Vises når |
|---|---|---|
| Trenger oppmerksomhet | Funnene (v1 + v2) | Det finnes funn |
| Kritikalitet og profil | Nivå, intervall, profilsammendrag i klartekst («Databehandler · Data i EØS · Bruker underleverandører»), «Profil ikke fylt ut» | Alltid |
| Krav og kvalifikasjoner | Kontrollstatus + antall per visningsstatus («2 mangler · 1 ikke vurdert · 6 dokumentert»), obligatoriske mangler navngitt | Kravprofil finnes |
| Dokumentasjon | Antall gyldige; utløpte/utløper snart navngitt | Alltid |
| Leverandørvurdering | Siste resultat, neste vurdering | Alltid |
| Sikkerhet og personvern | Nøkkelfakta + status for sikkerhets-/personvernkrav | Profilen utløser slike krav |
| Aktsomhet og bærekraft | Siste konklusjon, neste dato, status for HR/miljø-krav | Alltid (tekst ved ikke relevant) |
| Neste kontroller | 5 nærmeste datoer fra oppfølgingsplanen | Noe har dato |
| Risiko | v1-panel, sammendrag | Modul + tilgang |
| Avvik og forbedringer | v1-panel, sammendrag | Modul + tilgang |

**Faner** (`?tab=`): Oversikt · Krav og kvalifikasjoner · Dokumentasjon · Vurderinger · Aktsomhet ·
Historikk. Risiko og Avvik vises i Oversikt (de er lister med lenker, ikke arbeidsflater her).

**Krav og kvalifikasjoner-fanen:** gruppert per tema, sortert Obligatorisk → Viktig → Oppfølging.
Hver rad: tittel · nivå · visningsstatus · «Gjelder fordi …» · neste kontroll · «Registrer kontroll».
Under: «Krav i Etterlevelse og revisjon som gjelder leverandøren» (v1, uendret). Nederst «Legg til
krav» og «Krav for denne leverandøren».

**Aldri:** generisk «Relasjoner», «koblinger», «predikat», «DNF», «applies_when» i UI.

### 22.2 Andre sider

| Side | Innhold |
|---|---|
| Register | Ny kolonne/filter **Kontrollstatus** |
| **Kontrollkrav** (fane på Leverandører) | Katalog gruppert per tema; «Ta i bruk kravmal»; «Nytt kontrollkrav»; per krav: hvor mange leverandører det gjelder (bare synlige) |
| Dialoger (`ActionDialog`) | Profil · Registrer kontroll · Bekreft kravene på nytt · Registrer beslutning · Aktsomhetsvurdering · Ta i bruk kravmal · Kontrollkrav |

PageHelp, 16 px, 390 px uten horisontal scroll, `SupplierValidationMessages`, i18n i `no` og `en` —
som v1 §11.4.

---

## 23. Faser

Én feature-gren (`feat/supplier-assurance`). Hver fase: én eller flere commits, målrettede PHP/JS-
tester, ingen full suite. v2 når ingen kunder før grenen er merget, og etter merge er v2-funksjonene
usynlige for kunder uten kontrollkrav (bortsett fra profilkortet).

| Fase | Leverer | Tabeller | Tester (målrettet) |
|---|---|---|---|
| **1. Tilgang og leverandørprofil** | `supplier.assure` i katalog, Tilganger-etiketter, `SupplierAccessService::canAssure()`; `supplier_profiles` + `supplier_profile_changes`; `SupplierProfileService`; `SupplierProfilePredicates`; profilkort + dialog; `isDeletable()` utvidet | 2 nye | Predikater (enhet, inkl. «vet ikke» og tom profil); profil-tilgang; historikk + trigger; tenant; System Owner uten assure |
| **2. Kontrollkrav og kravprofil** | `supplier_control_requirements`, `supplier_requirement_inclusions`; katalog-fane; `SupplierRequirementApplicability`; «Krav og kvalifikasjoner»-fane med «Gjelder fordi» og «Ikke vurdert»; manuelle og leverandørspesifikke krav; forankring i Etterlevelse (gated) | 2 nye | Anvendelse (enhet, DNF); utgått krav; forankring skjult uten compliance-tilgang/modul; slettevern; leverandørspesifikt krav aldri på annen leverandør |
| **3. Dokumentasjon og kontroller** | Dokumenttyper + `standard`; `supplier_requirement_evaluations` + `_documents`; `SupplierRequirementStatus`; «Registrer kontroll»; Må fornyes / Aksept utløpt; slettevern på brukt dokument; «Bekreft kravene på nytt» ved fornyelse | 2 nye, 1 endret | Statusregler (enhet); «Dokumentert krever dokument»; «Ikke relevant» nektet for obligatorisk regelkrav; uforanderlighet; dokument fra annen leverandør nektet; brukt dokument kan ikke slettes; fornyelse |
| **4. Gates, beslutning og kontrollstatus** | `supplier_assurance_decisions`; `SupplierAssuranceResolver`; tillatte beslutninger; Kontrollstatus i hode og register; advarsel i «Ta i bruk» | 1 ny | Resolver-tabell (enhet, alle kombinasjoner §9.1); tillatt/nektet beslutning; Krever beslutning trumfer Godkjent; øyeblikksbilde; låsing |
| **5. Kravmaler 1–3** | Bibliotek + mal 1, 2, 3; «Ta i bruk kravmal»; idempotent import | — | Import oppretter manglende, aldri duplikat; proveniens; kundens endringer overlever ny import; i18n-nøkler finnes |
| **6. Oppfølgingsplan og Trenger oppmerksomhet** | `SupplierFollowUpPlan`; «Neste kontroller»-kort; signal 6–11; handoff fra kontroll til Avvik (proveniens-kolonne) | 1 endret | Plan (enhet, månedskanter); hvert signal treffer/bommer én gang; ingen signal uten katalog; ingen lekkasje (usynlig leverandør teller ikke) |
| **7. Aktsomhet og bærekraft** | `supplier_due_diligence_assessments`; aktsomhetsfane; handoff til Avvik og Risiko med proveniens; `sustainability_rating`; mal 4 | 1 ny, 3 endret | Uforanderlighet; relevansregel; handoff-rettigheter (assure + målmodul); gamle vurderinger uten bærekraft vises riktig |
| **8. Kravmaler 5–9** | Kritisk IKT, Bygg og anlegg, Renhold, Bemanning, Helse | — | Regler i malene treffer forventede profiler (tabelltest per mal) |
| **9. Sikkerhet/personvern-kort, UX og merge-port** | «Sikkerhet og personvern»-kort; oversikt med kort; PageHelp; 16 px/390 px; tre E2E-reiser; full PHP/JS/E2E som merge-port | — | §24 |

Fase 1–4 gir en brukbar kjerne (manuelle kontrollkrav). Fase 5 gjør den nyttig uten oppsettarbeid.

---

## 24. Teststrategi

Én test per regel. Domeneregler i PHP; UI-logikk i JS; få, verdifulle E2E-reiser. Det som er dekket i
PHP, testes ikke på nytt i E2E. Full suite bare i fase 9 (merge-port) eller ved eksplisitt
forespørsel.

### 24.1 PHP

| Område | Hva |
|---|---|
| Rene klasser (enhet) | `SupplierProfilePredicates`, `SupplierRequirementApplicability`, `SupplierRequirementStatus`, `SupplierAssuranceResolver`, `SupplierFollowUpPlan` — tabelldrevne tester |
| Tenant | Annen kundes leverandør/krav/kontroll/dokument = 404; sammensatt FK avviser kryss-kunde-rad for hver ny tabell |
| Tilgang | `assure` vs `edit` vs `assess` hver for seg; System Owner uten assure: 403 og ingen v2-data; avsluttet leverandør nekter hver v2-skriving |
| Uforanderlighet | Modell kaster + trigger nekter for 5 nye historikktabeller; `sustainability_rating` dekket av ny triggerfunksjon |
| Lekkasje | Forankring fraværende i payload uten compliance; Risiko/Avvik-paneler uendret; signaler teller bare synlige leverandører |
| Migrering | v1-leverandør uten profil fungerer; kunde uten katalog får ingen nye signaler; `down()` reverserer |
| Mønster | `UsesProjectPostgresConnection`, kjør i Docker (`docker exec procynia-app php artisan test --filter=...`) |

### 24.2 JS (Vitest, node:22-docker)

Profil-dialogen viser/skjuler avhengige felt; kontrolldialogen krever dokument ved Dokumentert og
dato ved Midlertidig akseptert; «Ikke relevant» skjules for obligatorisk regelkrav; kort rendres ikke
når prop er `null`.

### 24.3 E2E (Playwright) — **3 reiser** i `tests/e2e/supplier-assurance.spec.js`

Egen GRC-fixture-kunde, markørnavngitte data, `remaining()` = 0, triggere av ved opprydding.

1. **Fra profil til godkjent IT-leverandør:** ta i bruk mal 2/3 → fyll profil (databehandler, data
   utenfor EØS) → kravprofil med «Gjelder fordi» → Krever beslutning → registrer DBA + kontroller →
   midlertidig aksept av overføringsgrunnlag → Godkjent med oppfølging. `readability.js` på
   leverandørside og Kontrollkrav.
2. **Fornyelse og forfall:** sertifikat brukt i kontroll utløper → Må fornyes + signal → Registrer
   fornyet → Bekreft kravene på nytt → signal borte; brukt dokument kan ikke slettes.
3. **Aktsomhet:** produktleverandør med høyrisikokategori → «Aktsomhetsvurdering mangler» →
   vurdering «Tiltak kreves» → Følg opp i Avvik og forbedringer → lenke begge veier.

---

## 25. Invarianter (sjekkliste for review)

1. Ingen kolonne, verdi eller UI-tekst uttrykker en score, vekt eller prosent.
2. Kravprofil og kontrollstatus lagres aldri.
3. Ingen Supplier-kode leser `compliance_assessments` eller `ComplianceStatusResolver`.
4. Leverandørkontroll fungerer uten modulen `compliance`.
5. «Dokumentert» uten dokumentgrunnlag kan ikke lagres.
6. Obligatorisk regelkrav kan ikke settes «Ikke relevant».
7. «Godkjent» kan ikke registreres med ikke-oppfylt obligatorisk eller viktig krav.
8. Alle v2-historikktabeller er uforanderlige i modell og database.
9. Avsluttet leverandør: ingen v2-skriving.
10. Trenger oppmerksomhet leser bare Supplier-tabeller.
11. En kunde uten kontrollkrav ser ingen forskjell fra v1, bortsett fra profilkortet.
12. Ingen eksisterende v1-data endres av migrasjonene.
