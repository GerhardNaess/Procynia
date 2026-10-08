# Kravmaler — faglig og juridisk review

Status: **Ikke godkjent. Ingen av kravene er vurdert.**

Dette er grunnlaget for den faglige og juridiske kvalitetssikringen av kravmalene i Leverandørkontroll
(Supplier Assurance v2). Malene er implementert og testet. Innholdet er ikke kvalitetssikret.
Se release-gaten i [planen §19.2](supplier-assurance-v2-plan.md#192-kommersiell-pakking-og-faglig-review).

Kravmalene kan ikke regnes som produksjonsgodkjent innhold før en navngitt faglig og juridisk
eier har gått gjennom og godkjent dette dokumentet.

## Godkjenning

| Felt | Verdi |
|---|---|
| Faglig reviewer: | |
| Juridisk reviewer: | |
| Dato: | |
| Godkjent versjon: | |

«Godkjent versjon» er commit-hashen for den versjonen av dette dokumentet som er gjennomgått.
Alle ni malene har i dag `version = '1'`.

## Kilde

Dokumentet gjengir koden slik den er på `feat/supplier-assurance` (`c26b6ffc`). Det legger ikke til
noe innhold.

| Innhold | Kilde |
|---|---|
| Krav: tema, nivå, regel, kontrollpunkt, intervall, dokumenttyper | `app/Support/Suppliers/RequirementTemplates/RequirementLibrary.php` |
| Maler: krav, obligatoriske krav, `recommended_level`, versjon | `app/Support/Suppliers/RequirementTemplates/RequirementTemplates.php` |
| Titler, malnavn, formål og «Passer for» (NO/EN) | `lang/no/procynia.php`, `lang/en/procynia.php` (`supplier_management.templates`) |
| Bakgrunn og beslutninger | [`supplier-assurance-v2-plan.md`](supplier-assurance-v2-plan.md) §5, §7, §16 |

Kravbiblioteket i planen (§16.3) har 40 krav. M3 (klima-/miljørapportering) er ikke med i noen mal
og er derfor ikke implementert. Dette dokumentet dekker de **39 kravene** som finnes i koden.

## Slik gjennomføres reviewet

1. Gå gjennom hvert krav nedenfor. Sett **Faglig review-status** til én av:
   - `Ikke vurdert` — utgangspunktet for alle krav
   - `Godkjent` — kravet kan leveres slik det står
   - `Må endres` — skriv hva som må endres i «Reviewer-kommentar»
2. Gå gjennom de [særlige review-punktene](#særlige-review-punkter). De er allerede identifisert som
   usikre.
3. Gå gjennom hver mal: passer kravene og de obligatoriske kravene sammen for formålet?
4. Fyll ut godkjenningsfeltene øverst når alle 39 kravene er `Godkjent`.

Endringer reviewerne ber om, gjøres i koden og lang-filene. Dette dokumentet oppdateres i samme
commit, og reviewet gjelder den nye versjonen.

## Hva reviewerne bør vite om modellen

- **Malen er et startpunkt.** «Ta i bruk kravmal» legger kravene inn som kundens egne kontrollkrav.
  Kunden kan endre tekst, nivå, intervall og regel etterpå. En senere malversjon endrer aldri krav
  kunden allerede har.
- **Ett nivå per krav.** Et krav har samme nivå i alle maler det brukes i. Ønsker en mal et strengere
  nivå, vises det som en anbefaling (`recommended_level`) når malen tas i bruk. Det endrer ikke nivået.
- **Nivåene** (planen §7):
  - **Obligatorisk** — leverandøren skal ikke brukes uten at kravet er dokumentert, eller at mangelen
    er bevisst og midlertidig akseptert. Et udokumentert obligatorisk krav gir «Krever beslutning».
    Systemet setter aldri «Ikke godkjent» selv.
  - **Viktig** — skal dokumenteres. Mangel gir «Krever oppfølging» og hindrer «Godkjent», men ikke
    «Godkjent med oppfølging».
  - **Oppfølging** — følges opp etter behov. Mangel gir «Krever oppfølging».
- **«Gjelder når»** avgjøres automatisk av leverandørprofilen og kritikaliteten. Svarer kunden
  «Vet ikke» på et profilspørsmål, gjelder kravet. Er hele profilen tom, gjelder bare krav som
  avhenger av kritikalitet eller av de fire kritikalitetsspørsmålene fra v1.
- **Kontrollpunkt:** «Før avtale/oppstart», «Løpende» eller «Ved endring» (planen §5.4).
- **Kontrollintervall:** hvor ofte kravet må kontrolleres på nytt. Uten fast intervall kontrolleres
  kravet ved dokumentets utløp eller når det trengs.
- **Forventede dokumenttyper** er veiledende. Kontrolløren kan bruke andre dokumenttyper.
- **`basis_text`** (hjemmel/grunnlag) og **`guidance`** (slik kontrollerer vi det) er tomme for alle
  39 kravene. Ingen hjemmel eller veiledning er lagt inn uten faglig gjennomgang. Det eneste som står
  om grunnlaget i dag, er en felles merknad i UI-et: «Kravmalene beskriver typiske leverandørkrav,
  ikke juridisk fasit. Tekst, nivå og regel kan tilpasses etterpå.»

## Oversikt over kravene

Malnummer: 1 Offentlig sektor · 2 IT/SaaS · 3 Databehandler · 4 Menneskerettighetsrisiko ·
5 Kritisk IKT · 6 Bygg og anlegg · 7 Renhold · 8 Bemanning · 9 Helse.

| Nøkkel | Tittel | Nivå | Maler | Review-status |
|---|---|---|---|---|
| [`E1`](#e1) | Etiske retningslinjer for leverandører akseptert | Viktig | 1, 4 | Ikke vurdert |
| [`E2`](#e2) | Skatteattest og firmaattest | Viktig (anbefalt obligatorisk i mal 6) | 1, 6, 7, 8 | Ikke vurdert |
| [`F1`](#f1) | Ansvarsforsikring | Viktig | 1, 6, 7 | Ikke vurdert |
| [`F2`](#f2) | Økonomisk bæreevne vurdert | Viktig | 1, 5 | Ikke vurdert |
| [`Q1`](#q1) | Kvalitetssystem (ISO 9001 eller tilsvarende) | Oppfølging | 1 | Ikke vurdert |
| [`Q2`](#q2) | Avvikshåndtering og rapportering til oss | Oppfølging | 5 | Ikke vurdert |
| [`L1`](#l1) | Lønns- og arbeidsvilkår (egenerklæring) | Obligatorisk | 1, 6, 7, 8 | Ikke vurdert |
| [`L2`](#l2) | Lønns- og arbeidsvilkår kontrollert (arbeidsavtaler, timelister, lønnsslipper) | Viktig | 6, 7 | Ikke vurdert |
| [`L3`](#l3) | HMS-kort for alle på arbeidsplassen | Obligatorisk | 6, 7 | Ikke vurdert |
| [`L4`](#l4) | Obligatorisk tjenestepensjon | Viktig | 6, 7, 8 | Ikke vurdert |
| [`L5`](#l5) | Underleverandører godkjent og begrenset antall ledd | Viktig | 6 | Ikke vurdert |
| [`B1`](#b1) | SHA-koordinering avklart | Viktig | 6 | Ikke vurdert |
| [`R1`](#r1) | Godkjent renholdsvirksomhet (offentlig register) | Obligatorisk | 7 | Ikke vurdert |
| [`ST1`](#st1) | Registrert bemanningsforetak (offentlig register) | Obligatorisk | 8 | Ikke vurdert |
| [`ST2`](#st2) | Likebehandling av innleide | Viktig | 8 | Ikke vurdert |
| [`M1`](#m1) | Miljøledelse (ISO 14001, EMAS, Miljøfyrtårn eller tilsvarende) | Viktig | 1, 4, 6 | Ikke vurdert |
| [`M2`](#m2) | Kontraktsfestede miljøkrav fulgt opp | Viktig | 6 | Ikke vurdert |
| [`S1`](#s1) | Styringssystem for informasjonssikkerhet (ISO 27001, SOC 2 Type II eller tilsvarende) | Viktig | 2, 5 | Ikke vurdert |
| [`S2`](#s2) | Tilgangsstyring og MFA | Obligatorisk | 2, 5, 9 | Ikke vurdert |
| [`S3`](#s3) | Logging og sporbarhet av tilgang | Viktig | 5 | Ikke vurdert |
| [`S4`](#s4) | Varsling av sikkerhetshendelser og personvernbrudd (frist, kontaktpunkt) | Obligatorisk | 2, 3, 5, 9 | Ikke vurdert |
| [`S5`](#s5) | Sårbarhets- og patchhåndtering | Viktig | 2, 5 | Ikke vurdert |
| [`S6`](#s6) | Kryptering i transitt og lagring | Viktig | 2, 3, 5 | Ikke vurdert |
| [`S7`](#s7) | Uavhengig sikkerhetsrapport | Obligatorisk | 5 | Ikke vurdert |
| [`S8`](#s8) | Exit: tilbakelevering og sletting av data | Viktig | 2, 3, 5 | Ikke vurdert |
| [`P1`](#p1) | Databehandleravtale | Obligatorisk | 2, 3, 5, 9 | Ikke vurdert |
| [`P2`](#p2) | Underdatabehandlere oversikt og godkjenning | Obligatorisk | 2, 3, 5, 9 | Ikke vurdert |
| [`P3`](#p3) | Behandlingssted dokumentert | Viktig | 2, 3, 5 | Ikke vurdert |
| [`P4`](#p4) | Overføringsgrunnlag utenfor EØS og vurdering av overføringen | Obligatorisk | 2, 3, 5, 9 | Ikke vurdert |
| [`P5`](#p5) | Forsterkede tiltak for særlige kategorier | Viktig | 3, 9 | Ikke vurdert |
| [`C1`](#c1) | Kontinuitetsplan | Viktig | 2, 5 | Ikke vurdert |
| [`C2`](#c2) | Test av gjenoppretting dokumentert | Viktig | 5 | Ikke vurdert |
| [`C3`](#c3) | Kritiske avhengigheter og underleverandører kartlagt | Oppfølging | 5 | Ikke vurdert |
| [`H1`](#h1) | Egenerklæring om menneskerettigheter og arbeidsforhold i leverandørkjeden | Obligatorisk | 4 | Ikke vurdert |
| [`H2`](#h2) | Oversikt over produksjonssteder i leverandørkjeden | Viktig | 4 | Ikke vurdert |
| [`H3`](#h3) | Tredjepartsrevisjon eller kontroll av produksjonssted | Viktig | 4 | Ikke vurdert |
| [`HE1`](#he1) | Etterlevelse av bransjenorm for informasjonssikkerhet i helsesektoren | Obligatorisk | 9 | Ikke vurdert |
| [`HE2`](#he2) | Taushetserklæring for personell | Obligatorisk | 9 | Ikke vurdert |
| [`HE3`](#he3) | Politiattest der regelverket krever det | Viktig | 9 | Ikke vurdert |

## Malene

### 1. Offentlig sektor – generell leverandør

- **Formål:** Etikk, økonomi, kvalitet, lønns- og arbeidsvilkår og miljø.
- **Passer for:** Alle leverandører. Flere av kravene gjelder bare viktige og kritiske leverandører,
  og leverandører som omfattes av særlige offentlige kontraktskrav.
- **Antall krav:** 7 — E1, E2, F1, F2, Q1, L1, M1
- **Obligatoriske:** L1
- **`recommended_level`:** ingen
- **Vurderes særskilt:** L1 er obligatorisk, men gjelder bare når leveransen omfattes av særlige
  offentlige kontraktskrav. E1 gjelder alle leverandører uten unntak.

### 2. IT/SaaS-leverandør

- **Formål:** Informasjonssikkerhet, personvern og kontinuitet.
- **Passer for:** Leverandører av IKT og digitale tjenester som lagrer våre data eller har tilgang
  til våre systemer.
- **Antall krav:** 11 — S1, S2, S4, S5, S6, S8, P1, P2, P3, P4, C1
- **Obligatoriske:** S2, S4, P1, P2, P4
- **`recommended_level`:** ingen
- **Vurderes særskilt:** At S2 (tilgangsstyring og MFA) er obligatorisk for alle med systemtilgang.
  At S4 er obligatorisk også når det bare gjelder taushetsbelagt informasjon.

### 3. Databehandler

- **Formål:** Personvern og informasjonssikkerhet.
- **Passer for:** Leverandører som behandler personopplysninger på våre vegne.
- **Antall krav:** 8 — P1, P2, P3, P4, P5, S4, S6, S8
- **Obligatoriske:** P1, P2, P4, S4
- **`recommended_level`:** ingen
- **Vurderes særskilt:** P1, P2 og S4 gjelder også når databehandlerrollen er «Vet ikke». P2 står
  som «Løpende» i koden, mens planen sier «Løpende / ved endring». Hjemmel for P1, P2 og P4 er ikke
  lagt inn.

### 4. Produktleverandør med menneskerettighetsrisiko

- **Formål:** Menneskerettigheter, arbeidsforhold og miljø.
- **Passer for:** Leverandører av produkter i høyrisikokategorier eller med produksjon utenfor
  Norge/EØS. Slike leverandører bør også ha en aktsomhetsvurdering.
- **Antall krav:** 5 — H1, H2, H3, E1, M1
- **Obligatoriske:** H1
- **`recommended_level`:** ingen
- **Vurderes særskilt:** At H1 er obligatorisk for all produksjon utenfor Norge/EØS, ikke bare for
  høyrisikoprodukter. Aktsomhetsvurderingen er en egen vurdering og ikke et krav i malen.

### 5. Kritisk IKT-leverandør

- **Formål:** Informasjonssikkerhet, kontinuitet og økonomi. Kravene fra IT/SaaS-leverandør og noen
  flere.
- **Passer for:** Kritiske leverandører av IKT og digitale tjenester.
- **Antall krav:** 17 — de 11 fra mal 2 og S3, S7, C2, C3, F2, Q2
- **Obligatoriske:** S2, S4, P1, P2, P4, S7
- **`recommended_level`:** ingen
- **Vurderes særskilt:** S7, nivået på F2 og nivået på Q2. Se
  [Kritisk IKT](#kritisk-ikt).

### 6. Bygg og anlegg

- **Formål:** Lønns- og arbeidsvilkår, HMS, seriøsitet og miljø.
- **Passer for:** Leverandører i bygg og anlegg, særlig ved arbeidsintensive leveranser,
  underleverandører og arbeid på våre lokasjoner.
- **Antall krav:** 10 — E2, L1, L2, L3, L4, L5, B1, F1, M1, M2
- **Obligatoriske:** L1, L3
- **`recommended_level`:** E2 er Viktig, anbefalt Obligatorisk i denne malen
- **Vurderes særskilt:** L3, B1 og E2. Se [Bygg og anlegg](#bygg-og-anlegg).

### 7. Renhold

- **Formål:** Lønns- og arbeidsvilkår, HMS og seriøsitet.
- **Passer for:** Renholdsleverandører, særlig ved arbeidsintensive leveranser og arbeid på våre
  lokasjoner.
- **Antall krav:** 7 — R1, E2, L1, L2, L3, L4, F1
- **Obligatoriske:** R1, L1, L3
- **`recommended_level`:** ingen
- **Vurderes særskilt:** R1 og registerhenvisningen. Se [Renhold](#renhold).

### 8. Bemanning

- **Formål:** Lønns- og arbeidsvilkår, likebehandling og seriøsitet.
- **Passer for:** Bemanningsforetak som leier ut arbeidskraft til oss.
- **Antall krav:** 5 — ST1, ST2, L1, L4, E2
- **Obligatoriske:** ST1, L1
- **`recommended_level`:** ingen
- **Vurderes særskilt:** ST1, ST2 og L1 som obligatorisk krav. Se [Bemanning](#bemanning).

### 9. Helse

- **Formål:** Personvern, informasjonssikkerhet og taushetsplikt.
- **Passer for:** Leverandører i helsesektoren som behandler personopplysninger, også særlige
  kategorier, eller arbeider på våre lokasjoner.
- **Antall krav:** 9 — HE1, HE2, HE3, P1, P2, P4, P5, S2, S4
- **Obligatoriske:** HE1, HE2, P1, P2, P4, S2, S4
- **`recommended_level`:** ingen
- **Vurderes særskilt:** HE1, HE3 og at P2, P4, S2 og S4 er obligatoriske. Se [Helse](#helse).

## Særlige review-punkter

Disse punktene er allerede identifisert som usikre. De er spørsmål til reviewerne, ikke forslag til
nytt innhold.

### Kritisk IKT

- **S7 Uavhengig sikkerhetsrapport** — Obligatorisk. Gjelder kritiske leverandører som lagrer våre
  data eller har administrator-, drifts- eller fjerntilgang. Kontrolleres løpende hver 12. måned med
  revisjonsrapport eller sertifikat. Er det riktig at dette er obligatorisk? Hva skal godtas som en
  uavhengig sikkerhetsrapport? Er 12 måneder riktig intervall?
- **F2 Økonomisk bæreevne vurdert** — Viktig i alle maler. Er Viktig nok for en kritisk
  IKT-leverandør, eller bør malen anbefale Obligatorisk?
- **Q2 Avvikshåndtering og rapportering til oss** — Oppfølging, det laveste nivået. Gjelder bare
  kritiske leverandører. Er Oppfølging riktig nivå for et krav som bare gjelder kritiske leverandører?

### Bygg og anlegg

- **L3 HMS-kort for alle på arbeidsplassen** — Obligatorisk. Gjelder alle leverandører i bygg og
  anlegg eller renhold, også når det ikke arbeides på våre lokasjoner. Kontrolleres løpende hver
  6. måned med kontrollrapport. Er regelen for vid? Er intervallet og dokumenttypen riktige?
- **B1 SHA-koordinering avklart** — Viktig. Gjelder bygg og anlegg med arbeid på våre lokasjoner.
  Er dette et krav til leverandøren, eller beskriver det oppdragsgiverens egen rolle? Er tittelen
  forståelig uten veiledning?
- **E2 Skatteattest og firmaattest** — Viktig, med anbefalt Obligatorisk i Bygg og anlegg.
  Anbefalingen endrer ikke nivået. Er dette riktig løsning? Bør Renhold og Bemanning ha samme
  anbefaling?

### Renhold

- **R1 Godkjent renholdsvirksomhet (offentlig register)** — Obligatorisk. Gjelder alle i bransjen
  Renhold. Kontrolleres før avtale og deretter hver 12. måned med offentlig attest.
- **Registerhenvisning:** Tittelen sier «offentlig register», men navngir ikke registeret. Skal
  registeret navngis i tittel, `basis_text` eller `guidance`?

### Bemanning

- **ST1 Registrert bemanningsforetak (offentlig register)** — Obligatorisk. Samme spørsmål om
  registerhenvisning som for R1.
- **ST2 Likebehandling av innleide** — Viktig. Kontrolleres løpende hver 12. måned med
  egenerklæring eller kontrollrapport. Er nivået riktig? Er egenerklæring nok?
- **L1 Lønns- og arbeidsvilkår (egenerklæring)** — Obligatorisk i alle maler. Planens
  «Gates»-kolonne nevnte bare ST1 for Bemanning. L1 gjelder bare når leveransen omfattes av særlige
  offentlige kontraktskrav. Er det riktig at L1 er obligatorisk her? Bør L1 gjelde alle
  bemanningsforetak, uansett kontraktskrav?

### Helse

- **HE1 Etterlevelse av bransjenorm for informasjonssikkerhet i helsesektoren** — Obligatorisk.
  Tittelen navngir ikke normen. Skal en bestemt bransjenorm navngis, og hvor?
- **HE3 Politiattest der regelverket krever det** — Viktig. Tittelen overlater til regelverket å
  avgjøre når kravet gjelder, men regelen er bredere: den slår til for all helseleveranse med arbeid
  på våre lokasjoner. Er formuleringen brukbar for kontrolløren? Er kontrollrapport riktig
  dokumenttype?
- **P2, P4, S2 og S4 som obligatoriske krav** — Planens «Gates»-kolonne nevnte bare HE1, HE2 og P1
  for Helse. Kravene er obligatoriske i biblioteket og derfor også her. Er det riktig for Helse?

### Alle maler

For hvert krav kontrolleres:

- **Norsk tittel** — korrekt fagterminologi, forståelig uten veiledning
- **Engelsk oversettelse** — samme betydning som den norske
- **Gjelder når** — verken for vid eller for smal. Husk at «Vet ikke» utløser kravet
- **Nivå** — Obligatorisk bare der leverandøren ikke skal brukes uten dokumentasjon
- **Kontrollintervall** — rimelig. Krav uten fast intervall kontrolleres ved dokumentets utløp eller
  ved behov
- **Dokumenttyper** — hva som normalt dokumenterer kravet
- **Eventuell hjemmel** — skal kravet ha `basis_text`, og med hvilken tekst?
- **Eventuell veiledning** — skal kravet ha `guidance`, for eksempel hva som godtas som tilsvarende?

## Kravene

<a id="e1"></a>

### E1 — Etiske retningslinjer for leverandører akseptert

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `E1` |
| Tittel (NO) | Etiske retningslinjer for leverandører akseptert |
| Tittel (EN) | Supplier code of conduct accepted |
| Tema | Etikk |
| Brukes i mal | 1 Offentlig sektor – generell leverandør; 4 Produktleverandør med menneskerettighetsrisiko |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Alle leverandører |
| Regel (teknisk) | `[]` |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | 24 måneder |
| Forventede dokumenttyper | Etiske retningslinjer |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="e2"></a>

### E2 — Skatteattest og firmaattest

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `E2` |
| Tittel (NO) | Skatteattest og firmaattest |
| Tittel (EN) | Tax certificate and certificate of registration |
| Tema | Etikk |
| Brukes i mal | 1 Offentlig sektor – generell leverandør; 6 Bygg og anlegg; 7 Renhold; 8 Bemanning |
| Nivå | Viktig |
| `recommended_level` | Obligatorisk i mal 6 |
| Gjelder når | Når minst ett av disse gjelder:<br>• leveransen omfattes av særlige offentlige kontraktskrav<br>• leveransen gjelder bransjen «Bygg og anlegg»<br>• leveransen gjelder bransjen «Renhold»<br>• leveransen gjelder bransjen «Bemanning og innleie» |
| Regel (teknisk) | `public_contract_terms` ∨ `sector:construction` ∨ `sector:cleaning` ∨ `sector:staffing` |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Offentlig attest |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="f1"></a>

### F1 — Ansvarsforsikring

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `F1` |
| Tittel (NO) | Ansvarsforsikring |
| Tittel (EN) | Liability insurance |
| Tema | Økonomi |
| Brukes i mal | 1 Offentlig sektor – generell leverandør; 6 Bygg og anlegg; 7 Renhold |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når minst ett av disse gjelder:<br>• arbeid utføres på våre lokasjoner<br>• leverandøren er vurdert som kritisk |
| Regel (teknisk) | `on_site_work` ∨ `criticality_critical` |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | Ved dokumentets utløp (planen: «utløp»; lagres som intet fast intervall) |
| Forventede dokumenttyper | Forsikringsbevis |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="f2"></a>

### F2 — Økonomisk bæreevne vurdert

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `F2` |
| Tittel (NO) | Økonomisk bæreevne vurdert |
| Tittel (EN) | Financial capacity assessed |
| Tema | Økonomi |
| Brukes i mal | 1 Offentlig sektor – generell leverandør; 5 Kritisk IKT-leverandør |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når minst ett av disse gjelder:<br>• leverandøren er vanskelig å erstatte på kort sikt<br>• leverandøren er vurdert som kritisk |
| Regel (teknisk) | `hard_to_replace` ∨ `criticality_critical` |
| Kontrollpunkt | Løpende |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Økonomisk dokumentasjon |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="q1"></a>

### Q1 — Kvalitetssystem (ISO 9001 eller tilsvarende)

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `Q1` |
| Tittel (NO) | Kvalitetssystem (ISO 9001 eller tilsvarende) |
| Tittel (EN) | Quality management system (ISO 9001 or equivalent) |
| Tema | Kvalitet |
| Brukes i mal | 1 Offentlig sektor – generell leverandør |
| Nivå | Oppfølging |
| `recommended_level` | — |
| Gjelder når | Når leverandøren er vurdert som viktig eller kritisk |
| Regel (teknisk) | `criticality_important` |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | Ved dokumentets utløp (planen: «utløp»; lagres som intet fast intervall) |
| Forventede dokumenttyper | Sertifikat, Policy eller rutine |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="q2"></a>

### Q2 — Avvikshåndtering og rapportering til oss

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `Q2` |
| Tittel (NO) | Avvikshåndtering og rapportering til oss |
| Tittel (EN) | Non-conformity handling and reporting to us |
| Tema | Kvalitet |
| Brukes i mal | 5 Kritisk IKT-leverandør |
| Nivå | Oppfølging |
| `recommended_level` | — |
| Gjelder når | Når leverandøren er vurdert som kritisk |
| Regel (teknisk) | `criticality_critical` |
| Kontrollpunkt | Løpende |
| Kontrollintervall | 24 måneder |
| Forventede dokumenttyper | Policy eller rutine, Avtale |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="l1"></a>

### L1 — Lønns- og arbeidsvilkår (egenerklæring)

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `L1` |
| Tittel (NO) | Lønns- og arbeidsvilkår (egenerklæring) |
| Tittel (EN) | Pay and working conditions (self-declaration) |
| Tema | Lønns- og arbeidsvilkår |
| Brukes i mal | 1 Offentlig sektor – generell leverandør; 6 Bygg og anlegg; 7 Renhold; 8 Bemanning |
| Nivå | Obligatorisk |
| `recommended_level` | — |
| Gjelder når | Når leveransen omfattes av særlige offentlige kontraktskrav |
| Regel (teknisk) | `public_contract_terms` |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Egenerklæring |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="l2"></a>

### L2 — Lønns- og arbeidsvilkår kontrollert (arbeidsavtaler, timelister, lønnsslipper)

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `L2` |
| Tittel (NO) | Lønns- og arbeidsvilkår kontrollert (arbeidsavtaler, timelister, lønnsslipper) |
| Tittel (EN) | Pay and working conditions checked (employment contracts, timesheets, payslips) |
| Tema | Lønns- og arbeidsvilkår |
| Brukes i mal | 6 Bygg og anlegg; 7 Renhold |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når leveransen omfattes av særlige offentlige kontraktskrav **og** leveransen er arbeidsintensiv |
| Regel (teknisk) | (`public_contract_terms` ∧ `labour_intensive`) |
| Kontrollpunkt | Løpende |
| Kontrollintervall | 6 måneder |
| Forventede dokumenttyper | Kontrollrapport |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="l3"></a>

### L3 — HMS-kort for alle på arbeidsplassen

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `L3` |
| Tittel (NO) | HMS-kort for alle på arbeidsplassen |
| Tittel (EN) | HSE card for everyone at the workplace |
| Tema | Lønns- og arbeidsvilkår |
| Brukes i mal | 6 Bygg og anlegg; 7 Renhold |
| Nivå | Obligatorisk |
| `recommended_level` | — |
| Gjelder når | Når minst ett av disse gjelder:<br>• leveransen gjelder bransjen «Bygg og anlegg»<br>• leveransen gjelder bransjen «Renhold» |
| Regel (teknisk) | `sector:construction` ∨ `sector:cleaning` |
| Kontrollpunkt | Løpende |
| Kontrollintervall | 6 måneder |
| Forventede dokumenttyper | Kontrollrapport |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="l4"></a>

### L4 — Obligatorisk tjenestepensjon

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `L4` |
| Tittel (NO) | Obligatorisk tjenestepensjon |
| Tittel (EN) | Mandatory occupational pension |
| Tema | Lønns- og arbeidsvilkår |
| Brukes i mal | 6 Bygg og anlegg; 7 Renhold; 8 Bemanning |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når leveransen er arbeidsintensiv |
| Regel (teknisk) | `labour_intensive` |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | 24 måneder |
| Forventede dokumenttyper | Egenerklæring |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="l5"></a>

### L5 — Underleverandører godkjent og begrenset antall ledd

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `L5` |
| Tittel (NO) | Underleverandører godkjent og begrenset antall ledd |
| Tittel (EN) | Subcontractors approved and limited number of tiers |
| Tema | Lønns- og arbeidsvilkår |
| Brukes i mal | 6 Bygg og anlegg |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når leverandøren bruker underleverandører **og** leveransen er arbeidsintensiv |
| Regel (teknisk) | (`subcontractors` ∧ `labour_intensive`) |
| Kontrollpunkt | Ved endring |
| Kontrollintervall | Ingen fast intervall (planen: «—») |
| Forventede dokumenttyper | Underleverandørliste |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="b1"></a>

### B1 — SHA-koordinering avklart

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `B1` |
| Tittel (NO) | SHA-koordinering avklart |
| Tittel (EN) | Health, safety and working environment coordination (SHA) clarified |
| Tema | Lønns- og arbeidsvilkår |
| Brukes i mal | 6 Bygg og anlegg |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når leveransen gjelder bransjen «Bygg og anlegg» **og** arbeid utføres på våre lokasjoner |
| Regel (teknisk) | (`sector:construction` ∧ `on_site_work`) |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | Ingen fast intervall (planen: «—») |
| Forventede dokumenttyper | Policy eller rutine, Avtale |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="r1"></a>

### R1 — Godkjent renholdsvirksomhet (offentlig register)

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `R1` |
| Tittel (NO) | Godkjent renholdsvirksomhet (offentlig register) |
| Tittel (EN) | Approved cleaning company (public register) |
| Tema | Lønns- og arbeidsvilkår |
| Brukes i mal | 7 Renhold |
| Nivå | Obligatorisk |
| `recommended_level` | — |
| Gjelder når | Når leveransen gjelder bransjen «Renhold» |
| Regel (teknisk) | `sector:cleaning` |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Offentlig attest |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="st1"></a>

### ST1 — Registrert bemanningsforetak (offentlig register)

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `ST1` |
| Tittel (NO) | Registrert bemanningsforetak (offentlig register) |
| Tittel (EN) | Registered staffing agency (public register) |
| Tema | Lønns- og arbeidsvilkår |
| Brukes i mal | 8 Bemanning |
| Nivå | Obligatorisk |
| `recommended_level` | — |
| Gjelder når | Når leveransen gjelder bransjen «Bemanning og innleie» |
| Regel (teknisk) | `sector:staffing` |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Offentlig attest |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="st2"></a>

### ST2 — Likebehandling av innleide

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `ST2` |
| Tittel (NO) | Likebehandling av innleide |
| Tittel (EN) | Equal treatment of hired workers |
| Tema | Lønns- og arbeidsvilkår |
| Brukes i mal | 8 Bemanning |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når leveransen gjelder bransjen «Bemanning og innleie» |
| Regel (teknisk) | `sector:staffing` |
| Kontrollpunkt | Løpende |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Egenerklæring, Kontrollrapport |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="m1"></a>

### M1 — Miljøledelse (ISO 14001, EMAS, Miljøfyrtårn eller tilsvarende)

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `M1` |
| Tittel (NO) | Miljøledelse (ISO 14001, EMAS, Miljøfyrtårn eller tilsvarende) |
| Tittel (EN) | Environmental management (ISO 14001, EMAS, Eco-Lighthouse or equivalent) |
| Tema | Miljø |
| Brukes i mal | 1 Offentlig sektor – generell leverandør; 4 Produktleverandør med menneskerettighetsrisiko; 6 Bygg og anlegg |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når leveransen har vesentlig klima- eller miljøpåvirkning |
| Regel (teknisk) | `environmental_impact` |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | Ved dokumentets utløp (planen: «utløp»; lagres som intet fast intervall) |
| Forventede dokumenttyper | Sertifikat, Miljødokumentasjon |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="m2"></a>

### M2 — Kontraktsfestede miljøkrav fulgt opp

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `M2` |
| Tittel (NO) | Kontraktsfestede miljøkrav fulgt opp |
| Tittel (EN) | Contractual environmental requirements followed up |
| Tema | Miljø |
| Brukes i mal | 6 Bygg og anlegg |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når leveransen har vesentlig klima- eller miljøpåvirkning **og** leveransen omfattes av særlige offentlige kontraktskrav |
| Regel (teknisk) | (`environmental_impact` ∧ `public_contract_terms`) |
| Kontrollpunkt | Løpende |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Miljødokumentasjon, Kontrollrapport |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="s1"></a>

### S1 — Styringssystem for informasjonssikkerhet (ISO 27001, SOC 2 Type II eller tilsvarende)

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `S1` |
| Tittel (NO) | Styringssystem for informasjonssikkerhet (ISO 27001, SOC 2 Type II eller tilsvarende) |
| Tittel (EN) | Information security management system (ISO 27001, SOC 2 Type II or equivalent) |
| Tema | Informasjonssikkerhet |
| Brukes i mal | 2 IT/SaaS-leverandør; 5 Kritisk IKT-leverandør |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når leverandøren lagrer eller behandler våre data i egne systemer **og** leverandøren er vurdert som viktig eller kritisk |
| Regel (teknisk) | (`stores_our_data` ∧ `criticality_important`) |
| Kontrollpunkt | Løpende |
| Kontrollintervall | Ved dokumentets utløp (planen: «utløp»; lagres som intet fast intervall) |
| Forventede dokumenttyper | Sertifikat, Revisjonsrapport |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="s2"></a>

### S2 — Tilgangsstyring og MFA

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `S2` |
| Tittel (NO) | Tilgangsstyring og MFA |
| Tittel (EN) | Access control and MFA |
| Tema | Informasjonssikkerhet |
| Brukes i mal | 2 IT/SaaS-leverandør; 5 Kritisk IKT-leverandør; 9 Helse |
| Nivå | Obligatorisk |
| `recommended_level` | — |
| Gjelder når | Når leverandøren har tilgang til våre systemer eller vår informasjon |
| Regel (teknisk) | `system_access` |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Sikkerhetsdokumentasjon, Policy eller rutine |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="s3"></a>

### S3 — Logging og sporbarhet av tilgang

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `S3` |
| Tittel (NO) | Logging og sporbarhet av tilgang |
| Tittel (EN) | Logging and traceability of access |
| Tema | Informasjonssikkerhet |
| Brukes i mal | 5 Kritisk IKT-leverandør |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når leverandøren har administrator-, drifts- eller fjerntilgang |
| Regel (teknisk) | `privileged_access` |
| Kontrollpunkt | Løpende |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Sikkerhetsdokumentasjon |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="s4"></a>

### S4 — Varsling av sikkerhetshendelser og personvernbrudd (frist, kontaktpunkt)

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `S4` |
| Tittel (NO) | Varsling av sikkerhetshendelser og personvernbrudd (frist, kontaktpunkt) |
| Tittel (EN) | Notification of security incidents and personal data breaches (deadline, point of contact) |
| Tema | Informasjonssikkerhet |
| Brukes i mal | 2 IT/SaaS-leverandør; 3 Databehandler; 5 Kritisk IKT-leverandør; 9 Helse |
| Nivå | Obligatorisk |
| `recommended_level` | — |
| Gjelder når | Når minst ett av disse gjelder:<br>• leverandøren har tilgang til våre systemer eller vår informasjon<br>• leverandøren behandler personopplysninger som databehandler<br>• leverandøren får taushetsbelagt eller forretningssensitiv informasjon |
| Regel (teknisk) | `system_access` ∨ `processor` ∨ `confidential_information` |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | 24 måneder |
| Forventede dokumenttyper | Avtale, Databehandleravtale |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="s5"></a>

### S5 — Sårbarhets- og patchhåndtering

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `S5` |
| Tittel (NO) | Sårbarhets- og patchhåndtering |
| Tittel (EN) | Vulnerability and patch management |
| Tema | Informasjonssikkerhet |
| Brukes i mal | 2 IT/SaaS-leverandør; 5 Kritisk IKT-leverandør |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når leveransen gjelder bransjen «IKT og digitale tjenester» **og** leverandøren har tilgang til våre systemer eller vår informasjon |
| Regel (teknisk) | (`sector:ict` ∧ `system_access`) |
| Kontrollpunkt | Løpende |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Sikkerhetsdokumentasjon |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="s6"></a>

### S6 — Kryptering i transitt og lagring

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `S6` |
| Tittel (NO) | Kryptering i transitt og lagring |
| Tittel (EN) | Encryption in transit and at rest |
| Tema | Informasjonssikkerhet |
| Brukes i mal | 2 IT/SaaS-leverandør; 3 Databehandler; 5 Kritisk IKT-leverandør |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når leverandøren lagrer eller behandler våre data i egne systemer |
| Regel (teknisk) | `stores_our_data` |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | 24 måneder |
| Forventede dokumenttyper | Sikkerhetsdokumentasjon |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="s7"></a>

### S7 — Uavhengig sikkerhetsrapport

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `S7` |
| Tittel (NO) | Uavhengig sikkerhetsrapport |
| Tittel (EN) | Independent security report |
| Tema | Informasjonssikkerhet |
| Brukes i mal | 5 Kritisk IKT-leverandør |
| Nivå | Obligatorisk |
| `recommended_level` | — |
| Gjelder når | Når minst ett av disse gjelder:<br>• leverandøren lagrer eller behandler våre data i egne systemer **og** leverandøren er vurdert som kritisk<br>• leverandøren har administrator-, drifts- eller fjerntilgang **og** leverandøren er vurdert som kritisk |
| Regel (teknisk) | (`stores_our_data` ∧ `criticality_critical`) ∨ (`privileged_access` ∧ `criticality_critical`) |
| Kontrollpunkt | Løpende |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Revisjonsrapport, Sertifikat |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="s8"></a>

### S8 — Exit: tilbakelevering og sletting av data

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `S8` |
| Tittel (NO) | Exit: tilbakelevering og sletting av data |
| Tittel (EN) | Exit: return and deletion of data |
| Tema | Informasjonssikkerhet |
| Brukes i mal | 2 IT/SaaS-leverandør; 3 Databehandler; 5 Kritisk IKT-leverandør |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når leverandøren lagrer eller behandler våre data i egne systemer |
| Regel (teknisk) | `stores_our_data` |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | 24 måneder |
| Forventede dokumenttyper | Avtale, Databehandleravtale |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="p1"></a>

### P1 — Databehandleravtale

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `P1` |
| Tittel (NO) | Databehandleravtale |
| Tittel (EN) | Data processing agreement |
| Tema | Personvern |
| Brukes i mal | 2 IT/SaaS-leverandør; 3 Databehandler; 5 Kritisk IKT-leverandør; 9 Helse |
| Nivå | Obligatorisk |
| `recommended_level` | — |
| Gjelder når | Når leverandøren behandler personopplysninger som databehandler |
| Regel (teknisk) | `processor` |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | 24 måneder |
| Forventede dokumenttyper | Databehandleravtale |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="p2"></a>

### P2 — Underdatabehandlere oversikt og godkjenning

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `P2` |
| Tittel (NO) | Underdatabehandlere oversikt og godkjenning |
| Tittel (EN) | Sub-processors: overview and approval |
| Tema | Personvern |
| Brukes i mal | 2 IT/SaaS-leverandør; 3 Databehandler; 5 Kritisk IKT-leverandør; 9 Helse |
| Nivå | Obligatorisk |
| `recommended_level` | — |
| Gjelder når | Når leverandøren behandler personopplysninger som databehandler |
| Regel (teknisk) | `processor` |
| Kontrollpunkt | Løpende |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Underleverandørliste |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="p3"></a>

### P3 — Behandlingssted dokumentert

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `P3` |
| Tittel (NO) | Behandlingssted dokumentert |
| Tittel (EN) | Processing location documented |
| Tema | Personvern |
| Brukes i mal | 2 IT/SaaS-leverandør; 3 Databehandler; 5 Kritisk IKT-leverandør |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når minst ett av disse gjelder:<br>• leverandøren behandler personopplysninger som databehandler<br>• leverandøren lagrer eller behandler våre data i egne systemer |
| Regel (teknisk) | `processor` ∨ `stores_our_data` |
| Kontrollpunkt | Løpende |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Databehandleravtale, Sikkerhetsdokumentasjon |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="p4"></a>

### P4 — Overføringsgrunnlag utenfor EØS og vurdering av overføringen

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `P4` |
| Tittel (NO) | Overføringsgrunnlag utenfor EØS og vurdering av overføringen |
| Tittel (EN) | Transfer basis outside the EEA and assessment of the transfer |
| Tema | Personvern |
| Brukes i mal | 2 IT/SaaS-leverandør; 3 Databehandler; 5 Kritisk IKT-leverandør; 9 Helse |
| Nivå | Obligatorisk |
| `recommended_level` | — |
| Gjelder når | Når leverandøren behandler personopplysninger på våre vegne **og** våre data lagres eller behandles utenfor EØS |
| Regel (teknisk) | (`personal_data` ∧ `data_outside_eea`) |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Databehandleravtale, Annet |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="p5"></a>

### P5 — Forsterkede tiltak for særlige kategorier

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `P5` |
| Tittel (NO) | Forsterkede tiltak for særlige kategorier |
| Tittel (EN) | Enhanced measures for special categories |
| Tema | Personvern |
| Brukes i mal | 3 Databehandler; 9 Helse |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når leverandøren behandler særlige kategorier eller andre sensitive personopplysninger |
| Regel (teknisk) | `special_category_data` |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Sikkerhetsdokumentasjon |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="c1"></a>

### C1 — Kontinuitetsplan

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `C1` |
| Tittel (NO) | Kontinuitetsplan |
| Tittel (EN) | Continuity plan |
| Tema | Kontinuitet |
| Brukes i mal | 2 IT/SaaS-leverandør; 5 Kritisk IKT-leverandør |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når bortfall av leverandøren vil stoppe eller svekke en kritisk leveranse |
| Regel (teknisk) | `critical_delivery` |
| Kontrollpunkt | Løpende |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Policy eller rutine |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="c2"></a>

### C2 — Test av gjenoppretting dokumentert

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `C2` |
| Tittel (NO) | Test av gjenoppretting dokumentert |
| Tittel (EN) | Restore testing documented |
| Tema | Kontinuitet |
| Brukes i mal | 5 Kritisk IKT-leverandør |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når bortfall av leverandøren vil stoppe eller svekke en kritisk leveranse **og** leverandøren lagrer eller behandler våre data i egne systemer |
| Regel (teknisk) | (`critical_delivery` ∧ `stores_our_data`) |
| Kontrollpunkt | Løpende |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Kontrollrapport, Revisjonsrapport |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="c3"></a>

### C3 — Kritiske avhengigheter og underleverandører kartlagt

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `C3` |
| Tittel (NO) | Kritiske avhengigheter og underleverandører kartlagt |
| Tittel (EN) | Critical dependencies and subcontractors mapped |
| Tema | Kontinuitet |
| Brukes i mal | 5 Kritisk IKT-leverandør |
| Nivå | Oppfølging |
| `recommended_level` | — |
| Gjelder når | Når bortfall av leverandøren vil stoppe eller svekke en kritisk leveranse **og** leverandøren bruker underleverandører |
| Regel (teknisk) | (`critical_delivery` ∧ `subcontractors`) |
| Kontrollpunkt | Løpende |
| Kontrollintervall | 24 måneder |
| Forventede dokumenttyper | Underleverandørliste |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="h1"></a>

### H1 — Egenerklæring om menneskerettigheter og arbeidsforhold i leverandørkjeden

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `H1` |
| Tittel (NO) | Egenerklæring om menneskerettigheter og arbeidsforhold i leverandørkjeden |
| Tittel (EN) | Self-declaration on human rights and working conditions in the supply chain |
| Tema | Menneskerettigheter |
| Brukes i mal | 4 Produktleverandør med menneskerettighetsrisiko |
| Nivå | Obligatorisk |
| `recommended_level` | — |
| Gjelder når | Når minst ett av disse gjelder:<br>• leveransen omfatter produkter med kjent risiko i leverandørkjeden<br>• produksjon eller arbeid foregår utenfor Norge/EØS |
| Regel (teknisk) | `high_risk_products` ∨ `production_outside_eea` |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Egenerklæring |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="h2"></a>

### H2 — Oversikt over produksjonssteder i leverandørkjeden

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `H2` |
| Tittel (NO) | Oversikt over produksjonssteder i leverandørkjeden |
| Tittel (EN) | Overview of production sites in the supply chain |
| Tema | Menneskerettigheter |
| Brukes i mal | 4 Produktleverandør med menneskerettighetsrisiko |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når leveransen omfatter produkter med kjent risiko i leverandørkjeden |
| Regel (teknisk) | `high_risk_products` |
| Kontrollpunkt | Løpende |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Underleverandørliste |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="h3"></a>

### H3 — Tredjepartsrevisjon eller kontroll av produksjonssted

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `H3` |
| Tittel (NO) | Tredjepartsrevisjon eller kontroll av produksjonssted |
| Tittel (EN) | Third-party audit or inspection of production site |
| Tema | Menneskerettigheter |
| Brukes i mal | 4 Produktleverandør med menneskerettighetsrisiko |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når leveransen omfatter produkter med kjent risiko i leverandørkjeden **og** leverandøren er vurdert som viktig eller kritisk |
| Regel (teknisk) | (`high_risk_products` ∧ `criticality_important`) |
| Kontrollpunkt | Løpende |
| Kontrollintervall | 24 måneder |
| Forventede dokumenttyper | Revisjonsrapport, Kontrollrapport |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="he1"></a>

### HE1 — Etterlevelse av bransjenorm for informasjonssikkerhet i helsesektoren

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `HE1` |
| Tittel (NO) | Etterlevelse av bransjenorm for informasjonssikkerhet i helsesektoren |
| Tittel (EN) | Compliance with the health sector's information security code of conduct |
| Tema | Informasjonssikkerhet |
| Brukes i mal | 9 Helse |
| Nivå | Obligatorisk |
| `recommended_level` | — |
| Gjelder når | Når leveransen gjelder bransjen «Helse og omsorg» **og** leverandøren behandler personopplysninger på våre vegne |
| Regel (teknisk) | (`sector:health_care` ∧ `personal_data`) |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | 12 måneder |
| Forventede dokumenttyper | Egenerklæring, Sikkerhetsdokumentasjon |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="he2"></a>

### HE2 — Taushetserklæring for personell

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `HE2` |
| Tittel (NO) | Taushetserklæring for personell |
| Tittel (EN) | Confidentiality declaration for personnel |
| Tema | Personvern |
| Brukes i mal | 9 Helse |
| Nivå | Obligatorisk |
| `recommended_level` | — |
| Gjelder når | Når leveransen gjelder bransjen «Helse og omsorg» **og** arbeid utføres på våre lokasjoner |
| Regel (teknisk) | (`sector:health_care` ∧ `on_site_work`) |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | Ingen fast intervall (planen: «—») |
| Forventede dokumenttyper | Taushetserklæring |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |

<a id="he3"></a>

### HE3 — Politiattest der regelverket krever det

| Felt | Innhold |
|---|---|
| Stabil nøkkel | `HE3` |
| Tittel (NO) | Politiattest der regelverket krever det |
| Tittel (EN) | Police certificate where the regulations require it |
| Tema | Etikk |
| Brukes i mal | 9 Helse |
| Nivå | Viktig |
| `recommended_level` | — |
| Gjelder når | Når leveransen gjelder bransjen «Helse og omsorg» **og** arbeid utføres på våre lokasjoner |
| Regel (teknisk) | (`sector:health_care` ∧ `on_site_work`) |
| Kontrollpunkt | Før avtale/oppstart |
| Kontrollintervall | Ingen fast intervall (planen: «—») |
| Forventede dokumenttyper | Kontrollrapport |
| `basis_text` | Ikke kvalitetssikret / tom |
| `guidance` | Ikke kvalitetssikret / tom |
| Faglig review-status | **Ikke vurdert** |
| Reviewer-kommentar | |
