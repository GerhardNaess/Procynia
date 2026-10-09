# Plan: Leverandøroppfølging v1

Status: **v1 er implementert (fase 1–9) og merget til `main` i `e74d4232` (2026-10-07).** Planen
står som implementeringskontrakt for v1. Videre utvikling styres av
[Leverandørkontroll — v2-planen](supplier-assurance-v2-plan.md), som også har gjeldende
implementeringsstatus for hele modulen (v2-plan §26). Sist statusgjennomgått: **2026-10-09**.
Utgangspunkt: `main` @ `6caf7970` (2026-10-07).

Beslutningene i §16 er låst. Endringer i dem krever en ny beslutning, ikke en tolkning under
implementering.

---

## 1. Mål

Gi virksomheten ett sted å holde oversikt over leverandørene den er avhengig av, og en enkel, sporbar
oppfølging av dem:

- **Hvem leverer hva til oss, og hvem hos oss følger dem opp?**
- **Hvor viktig er leverandøren for oss?**
- **Hvordan fungerer leverandøren nå, og når vurderte vi det sist?**
- **Hvilken dokumentasjon har vi, hvor ligger den, og er den gyldig?**
- **Hvilke risikoer, krav og avvik gjelder leverandøren?**

Leverandøroppfølging skal dekke det ISO 9001 (8.4) og ISO 27001 (A.5.19–A.5.22) faktisk krever av
leverandørstyring — utvelgelse, klassifisering, periodisk evaluering, oppfølging og dokumentasjon —
uten å bli et innkjøpssystem.

Modulen eier **leverandøren, kritikaliteten, leverandørvurderingene og dokumentasjonsoversikten**.
Alt annet — risiko, krav, avvik, tiltak, KPI — eies av modulene som allerede finnes, og nås derfra.

### 1.1 Plass i pakkemodellen

| Pakke | Moduler |
|---|---|
| Basis | Wiki · Kvalitet · Avvik og forbedringer |
| Styring | Basis + Risiko · Mål og KPI |
| ISO | Styring + Etterlevelse og revisjon |
| **GRC** | ISO + **Leverandøroppfølging** |
| Anbud | Eget tillegg, utenfor stigen |

**Leverandøroppfølging er den nye funksjonelle modulen som gjør GRC-pakken større enn ISO-pakken.**

> **Oppdatert 2026-10-09:** Pakkestigen over er erstattet (`b7e45e98`, 2026-10-08) av **Basis +
> uavhengige tilvalg**. `supplier` er nå et eget tilvalg (`kind: option`) som kan kjøpes uten Risiko,
> Mål og KPI eller Etterlevelse. Styring, ISO og GRC finnes bare som navngitte utvalg (`bundles`);
> GRC = risk + objectives + compliance + supplier. Rail-raden er `built: true`. Koblingspanelene mot
> Risiko, Avvik og Etterlevelse var allerede gated på modul + rettighet, ikke på pakke, og påvirkes
> ikke.

Denne planen endrer verken `config/procynia_modules.php` eller entitlements. Modulen `supplier`
finnes allerede i konfigurasjonen og ligger allerede i `grc`; rail-raden står som `built: false`, så
ingen kunde får noe før modulen er bygget. Implementeringen (fase 1) legger bare til rute-gaten.

## 2. Produktprinsipper

1. **Enkelhet først.** En leder i en mellomstor virksomhet skal forstå arbeidsflyten uten å være
   GRC-konsulent. Kompleksiteten (tilgang, historikk, låser) ligger under panseret.
2. **Domenespråk, ikke datamodell.** UI sier «Risikoer som gjelder leverandøren», «Krav som gjelder
   leverandøren», «Avvik og forbedringer hos leverandøren». Aldri «relasjoner», «koblinger»,
   «source/target», «graph».
3. **Leverandøren er masterobjektet.** Én leverandør-record per virksomhet/part (§4.1).
4. **Ingen kopiert funksjonalitet.** Leverandøroppfølging får ingen egen risikomotor, kravregister,
   etterlevelsesvurdering, avviks-/tiltaksmotor eller KPI-motor. Den viser og initierer; modulene som
   eier dette, gjør resten (§7).
5. **Kritikalitet og vurdering er to forskjellige spørsmål.** Kritikalitet: *hvor viktig er
   leverandøren for virksomheten?* Leverandørvurdering: *hvordan fungerer leverandøren nå?* De
   blandes aldri — verken i datamodell, skjema eller UI.
6. **Sporbarhet der det betyr noe.** Leverandørvurderinger og endringer i status og kritikalitet er
   uforanderlig historikk. Masterdata og dokumentasjonsoversikt er vanlige, redigerbare data.
7. **Beregnet, ikke lagret.** Neste vurderingsdato, «forfalt», «utløper snart» og Trenger
   oppmerksomhet beregnes ved lesing — slik som i Risiko og Etterlevelse.
8. **Ingen poengscore.** Verken på leverandøren, i kritikaliteten, i vurderingen eller i Trenger
   oppmerksomhet.
9. **Ingen AI i v1.** Ingen AI-scoring, ingen AI-forslag til kritikalitet.

## 3. V1-scope (kort)

**Med i v1:**

- Leverandørregister med masterdata, intern ansvarlig, én kontaktperson og kategori
- Enkel livssyklus: Under vurdering → Aktiv → Avsluttet, med gjenåpning
- Kritikalitet i tre nivåer — Standard, Viktig, Kritisk — valgt av brukeren med fire ja/nei-spørsmål
  som beslutningsgrunnlag, med uforanderlig historikk
- Vurderingsintervall; påkrevd for Viktig og Kritisk; neste vurdering beregnes
- Periodisk, uforanderlig leverandørvurdering med fire faste kriterier og et samlet resultat
- Dokumentasjonsoversikt (metadata, ingen filer): type, navn, plassering, gyldighet, fornyelse
- Risikoer som gjelder leverandøren: se, koble til eksisterende, opprette ny via Risiko
- Krav som gjelder leverandøren: se og koble til eksisterende krav i Etterlevelse og revisjon — uten
  leverandørspesifikk kravstatus
- Avvik og forbedringer hos leverandøren: opprette via Avvik og forbedringer, koble til eksisterende
- Trenger oppmerksomhet: fem lokale, deterministiske signaler
- Tilgang via fire `supplier.*`-rettigheter; System Owner fail-closed

**Ikke med i v1:** se [§13](#13-eksplisitt-utenfor-v1).

---

## 4. Domenemodell

| Lag | Hva | Endres hvordan |
|---|---|---|
| **Masterdata** | Hvem leverandøren er, hva de leverer, hvem som følger dem opp | Redigeres (`supplier.edit`) mens leverandøren ikke er avsluttet |
| **Kritikalitet** | Hvor viktig leverandøren er, og hvor ofte den skal vurderes | Egen handling «Endre kritikalitet» med begrunnelse; hver endring blir en uforanderlig historikkrad |
| **Leverandørvurderinger** | Hvordan leverandøren fungerer nå | Kun nye vurderinger; aldri endret eller slettet. Feil rettes med en ny vurdering |
| **Dokumentasjonsoversikt** | Hvilken dokumentasjon finnes, hvor den ligger, når den utløper | Redigerbar oversikt; fornyelse erstatter forrige rad uten å slette den |
| **Koblinger** | Risiko, krav, avvik og forbedringer | Objektene eies av sin modul; leverandøren har bare id-koblinger og leser gjennom modulens tilgangsregler |
| **Historikk** | Status- og kritikalitetsendringer, vurderinger | Uforanderlig, beskyttet av modell + PostgreSQL-trigger |

### 4.1 Leverandøren — masterobjektet

**Én leverandør-record representerer leverandøren som virksomhet/part.** Det opprettes aldri en egen
leverandør-record per kontrakt, tjeneste, krav, risiko eller dokument. Leverandøren er objektet de
andre kobles til: flere dokumenter, flere risikoer, flere krav og flere saker peker på samme
leverandør. Leverer samme virksomhet flere ting, beskrives det i «Hva leverer de til oss?».

Feltene v1 trenger — ikke flere:

| Felt | Påkrevd | Begrunnelse |
|---|---|---|
| Navn | ja | |
| Organisasjonsnummer | nei | Utenlandske leverandører har ikke norsk orgnr. Unikt per kunde når satt — den enkleste sperren mot dobbeltregistrering av samme part. Ingen oppslag mot Brønnøysund. |
| Kategori | ja | Fast liste (under). Gir filtrering i registeret uten å bli en taksonomi. |
| Hva leverer de til oss? | ja | Fritekst — «Drift av lønnssystem», «Renhold Oslo-kontoret». Dekker tjeneste/leveranse uten et eget tjenesteobjekt. |
| Intern ansvarlig | ja | En bruker i kunden. `nullOnDelete`: slettes brukeren, blir feltet tomt og Trenger oppmerksomhet melder «Mangler ansvarlig» (mønster fra Mål). |
| Kontaktperson hos leverandøren | nei | Navn, e-post, telefon. Én kontakt i v1. |
| Notat | nei | Fritekst. |

**Kategorier v1** (kodeliste, oversatt i lang-filene): IT og skytjenester · Konsulent og rådgivning ·
Varer og materiell · Bygg og anlegg · Transport og logistikk · Drift og fasilitet · Annet.

**Bevisst ikke på leverandøren:**

- **Avtaleperiode / kontraktsdatoer.** Gyldigheten til en avtale står på dokumentasjonsraden for
  avtalen (§4.4). Én utløpsdato per avtale, ett sted.
- **Fagområde.** Leverandøren er kundeglobal (§9.1).
- **Leverandørnummer/ERP-id, beløp, betalingsbetingelser.** Innkjøp, ikke styring.

### 4.2 Kritikalitet — hvor viktig er leverandøren for virksomheten?

**Tre nivåer:**

| Nivå | Betydning (hjelpetekst) | Forhåndsutfylt vurderingsintervall |
|---|---|---|
| **Standard** | Begrenset betydning; lett å erstatte. | ingen (valgfritt) |
| **Viktig** | Bortfall eller svikt merkes, men kan håndteres innen rimelig tid. | 24 måneder |
| **Kritisk** | Bortfall eller svikt rammer kjernevirksomheten eller sensitive opplysninger direkte. | 12 måneder |

**Brukeren velger nivået.** Fire ja/nei-spørsmål vises som beslutningsgrunnlag, og svarene lagres:

1. Behandler leverandøren personopplysninger på våre vegne?
2. Har leverandøren tilgang til våre systemer eller vår informasjon?
3. Vil bortfall av leverandøren stoppe eller svekke en kritisk leveranse hos oss?
4. Er leverandøren vanskelig å erstatte på kort sikt?

Systemet regner ikke ut noen score, foreslår ikke et nivå ut fra svarene og overstyrer aldri
brukerens valg. Spørsmålene gjør valget etterprøvbart («hvorfor er denne Kritisk?»), ikke automatisk.

**Vurderingsintervall:** 6, 12, 24 eller 36 måneder. Forhåndsutfylles fra nivået og kan endres.
**Viktig og Kritisk krever et intervall**; bare Standard kan stå uten. Håndheves i validering og med
CHECK-constraint, slik at en viktig leverandør ikke kan falle ut av oppfølging ved å mangle intervall.

**Historikk.** Gjeldende nivå, intervall og spørsmålssvar står på leverandøren. Hver senere endring —
også endring av bare intervallet — gjøres med «Endre kritikalitet», krever begrunnelse og skriver én
uforanderlig rad med fra- og til-verdier for nivå, intervall og grunnlag. Klassifiseringen ved
registrering står på leverandøren selv.

Kritikalitet endres aldri som følge av en leverandørvurdering, og en leverandørvurdering endrer aldri
kritikaliteten.

### 4.3 Leverandørvurdering — hvordan fungerer leverandøren nå?

En periodisk, uforanderlig vurdering av leverandørens faktiske leveranse. Den er noe annet enn
kritikalitet (§4.2) og noe annet enn risiko (hva som kan gå galt, eid av Risiko).

**Hvem:** en bruker med `supplier.assess`. Typisk intern ansvarlig, men det kreves ikke.

**Når:** når som helst for en aktiv leverandør. Ikke for leverandører som er Under vurdering eller
Avsluttet.

**Fire faste kriterier** (kodeliste, ikke kundekonfigurerbar i v1):

| Kriterium | Hva det dekker |
|---|---|
| Kvalitet på leveransen | Leverer de det som er avtalt, i forventet kvalitet? |
| Leveringspresisjon og respons | Frister, tilgjengelighet, responstid |
| Informasjonssikkerhet og personvern | Hendelser, tilganger, databehandling |
| Etterlevelse av avtale og krav | Følger *denne leverandøren* avtalen og kravene som gjelder dem? |

Hvert kriterium: **Bra · Akseptabelt · Svakt · Ikke relevant**.

Det fjerde kriteriet er stedet der leverandørens faktiske situasjon mot kravene vurderes i v1 — samlet
og med begrunnelse, ikke krav for krav (§7.3).

**Samlet resultat**, valgt av vurdereren, ikke beregnet fra kriteriene:
**Tilfredsstillende · Delvis tilfredsstillende · Ikke tilfredsstillende.**

**Begrunnelse:** påkrevd fritekst. **Vurderingsdato:** påkrevd, ikke fram i tid; registreringstidspunkt
lagres i tillegg. **Øyeblikksbilde:** leverandørnavn, kritikalitet og vurderingsintervall på
vurderingstidspunktet lagres i vurderingen, som i Risiko- og Etterlevelsesvurderinger.

**Gjeldende vurdering** = den med senest vurderingsdato, deretter høyest id.

**Neste vurdering** = gjeldende vurderingsdato + leverandørens nåværende intervall, beregnet ved
lesing i en ren `SupplierReviewSchedule` med samme konvensjon som `RiskReviewSchedule`
(`addMonthsNoOverflow`; forfalt fra dagen etter). Ingen `next_review_at` lagres. Uten intervall eller
uten vurdering finnes ingen neste dato.

**Uforanderlig.** Modellen kaster og triggeren nekter UPDATE og DELETE. En feilregistrert vurdering
rettes ved å registrere en ny.

**Fra vurdering til oppfølging.** Er resultatet Delvis eller Ikke tilfredsstillende, viser
vurderingen handlingen «Følg opp i Avvik og forbedringer» (§7.4). Vurderingen lagrer ingenting om
oppfølgingen.

### 4.4 Dokumentasjonsoversikt

V1 svarer på fire spørsmål og ingen andre: **hvilken dokumentasjon finnes, hvor ligger den, når
utløper den, og trenger den oppfølging?**

**Ingen dokumentopplasting i v1.** Raden beskriver dokumentet; selve filen ligger der virksomheten
allerede arkiverer den.

| Felt | Påkrevd | |
|---|---|---|
| Dokumenttype | ja | Avtale · Databehandleravtale · Taushetserklæring · Sertifikat · Forsikringsbevis · Sikkerhetsdokumentasjon · Annet |
| Navn/beskrivelse | ja | «ISO 27001-sertifikat 2026», «Rammeavtale drift» |
| Plassering | nei | Fritekst eller URL — arkivreferanse, SharePoint-lenke, saksnummer |
| Gyldig fra | nei | |
| Gyldig til | nei | Tom = ingen utløpsdato |
| Kommentar | nei | |

**Fornyelse.** «Registrer fornyet» oppretter en ny rad av samme type og markerer den gamle som
erstattet. Den gamle blir stående, men teller ikke lenger i Trenger oppmerksomhet.

**Ikke et kontraktsstyringssystem.** Ingen forhandling, avtaleversjoner, elektronisk signatur,
kontraktsworkflow, godkjenningsrunder, beløp, spend eller bestillinger. En avtale er én rad med en
gyldighet, som et sertifikat.

**Ikke Enterprise Wiki.** Leverandørspesifikke kontrakter, sertifikater og avtaler legges aldri i
Wiki-ens dokumentlager (`EnterpriseWikiDocumentUploadService`). Det lageret er kildelaget for Wiki,
deles på tvers av virksomheten og leses av ingest. Wiki brukes til gjenbrukbar kunnskap
(«Slik følger vi opp leverandører»), ikke til leverandørens dokumenter.

**v1.1 (ikke v1):** et privat, modul-eid dokumentlager med egen nedlastingsrute og tilgangsstyring
kan vurderes.

**Bevisst ikke i v1:** påkrevde dokumenttyper per kritikalitet («Kritisk krever DBA»). Det er en
regelmotor.

---

## 5. Primære brukerreiser

### A. Registrere leverandør

1. Styring → **Leverandører**. Tomt register viser en kort forklaring og «Registrer leverandør».
2. Skjema: navn, orgnr, kategori, «Hva leverer de til oss?», intern ansvarlig, kontaktperson.
3. Samme skjema, egen seksjon **«Hvor viktig er leverandøren for oss?»**: de fire ja/nei-spørsmålene,
   deretter brukerens valg av Standard, Viktig eller Kritisk. Vurderingsintervall forhåndsutfylles fra
   nivået.
4. Valg: «Leverandøren er allerede i bruk» (standard, gir **Aktiv**) eller «Vi vurderer leverandøren»
   (**Under vurdering**).
5. Lagre → leverandørsiden. Er en aktiv leverandør Viktig eller Kritisk, viser siden straks
   «Ikke vurdert» under Trenger oppmerksomhet, med handlingen «Registrer vurdering».

Under vurdering → **«Ta i bruk»** gjør leverandøren Aktiv. Ingen godkjenningsrunde.

### B. Vurdere leverandør

1. Registeret (filter «Trenger oppmerksomhet») eller leverandørsiden viser «Vurdering forfalt».
2. «Registrer vurdering»: fire kriterier, samlet resultat, begrunnelse, vurderingsdato.
3. Lagre → vurderingen øverst under Vurderinger; «Neste vurdering» regnes ut på nytt.
4. Er resultatet ikke tilfredsstillende, tilbyr vurderingen «Følg opp i Avvik og forbedringer»
   (reise E).

### C. Håndtere risiko

1. Leverandørsiden → **«Risikoer som gjelder leverandøren»**: risikoene brukeren selv kan se i
   Risiko — tittel, fagområde, risikonivå, lenke.
2. **«Koble til eksisterende risiko»**: velg blant risikoer brukeren kan redigere i Risiko.
3. **«Opprett ny risiko»**: dialog med Risikos egne felter (fagområde, tittel, årsak, hendelse,
   konsekvens, ansvarlig). Tittelen forhåndsutfylles med leverandørnavnet; resten er tomt. Risikoen
   opprettes av Risiko (`RiskCreator`) og kobles til leverandøren i samme transaksjon.
4. Vurdering, behandling, tiltak, aksept og revurdering skjer i Risiko.
5. Risikosiden viser **«Gjelder leverandør»** med lenke tilbake — kun for brukere som kan lese
   Leverandører.

### D. Håndtere krav

1. Leverandørsiden → **«Krav som gjelder leverandøren»**: kravene brukeren selv kan se i Etterlevelse
   og revisjon — referanse, tittel, kilde, lenke til kravet.
2. **«Legg til krav»**: velg blant aktive krav. Nye krav lages i Etterlevelse og revisjon.
3. Leverandørsiden viser **ingen etterlevelsesstatus** for kravene. Kravets etterlevelsesstatus i
   Etterlevelse og revisjon gjelder virksomheten som helhet, ikke leverandøren.
4. Om leverandøren følger kravene, vurderes i leverandørvurderingen (kriteriet «Etterlevelse av
   avtale og krav»), og avvik følges opp via reise E.

### E. Håndtere avvik

1. Fra en vurdering (Delvis/Ikke tilfredsstillende) eller fra leverandørsiden:
   **«Følg opp i Avvik og forbedringer»**.
2. Dialog med Avvik og forbedringers egne felter: type (avvik/forbedring), tittel, beskrivelse,
   fagområde, ansvarlig, frist. Tittelen forhåndsutfylles med leverandørnavnet. Fagområdet kreves fordi
   Avvik og forbedringer krever det — ikke fordi leverandøren har et.
3. Saken opprettes av `ImprovementCaseCreator`, nøyaktig som en sak registrert i modulen, og kobles
   til leverandøren (og vurderingen, når den kom derfra).
4. Leverandørsiden → **«Avvik og forbedringer hos leverandøren»**: sakene brukeren selv kan se, med
   lenke. Status, tiltak, effektverifisering og lukking skjer i Avvik og forbedringer.
5. **«Koble til eksisterende sak»** for saker registrert direkte i Avvik og forbedringer.
6. Saksiden viser **«Gjelder leverandør»** for brukere som kan lese Leverandører.

### F. Dokument som utløper

1. ISO 27001-sertifikatet til en kritisk leverandør har «Gyldig til» om 45 dager.
2. Leverandøren står i Trenger oppmerksomhet: «Sertifikat utløper 21.11.2026».
3. Ansvarlig får nytt sertifikat og velger **«Registrer fornyet»**: ny gyldighet og plassering.
4. Den gamle raden vises som «Erstattet», signalet forsvinner.
5. Kommer ikke nytt sertifikat, blir signalet «Sertifikat utløpt» etter utløpsdatoen. Ansvarlig kan
   følge opp via reise E.

---

## 6. Livssyklus

### 6.1 Statuser

| Status | Betyr |
|---|---|
| **Under vurdering** | Registrert, ikke tatt i bruk. |
| **Aktiv** | I bruk. Periodisk vurdering gjelder. |
| **Avsluttet** | Ikke lenger i bruk, eller ikke valgt. |

Ikke flere. «Sperret», «Utfases» og «Godkjent» uttrykkes i v1 med kritikalitet, vurderingsresultat og
notat.

### 6.2 Overganger

| Fra → til | Handling | Krever |
|---|---|---|
| Under vurdering → Aktiv | «Ta i bruk» | `supplier.edit` |
| Under vurdering → Avsluttet | «Avslutt» | `supplier.edit` + begrunnelse |
| Aktiv → Avsluttet | «Avslutt» | `supplier.edit` + begrunnelse |
| Avsluttet → Aktiv | «Gjenåpne» | `supplier.edit` + begrunnelse |

Aktiv → Under vurdering finnes ikke. Hver overgang skriver én uforanderlig rad i
`supplier_status_changes`, med leverandørraden låst, i én transaksjon. `SupplierLifecycleService` er
eneste skriver (mønster fra `ObjectiveLifecycleService`).

### 6.3 Avsluttet leverandør

- **Skrivebeskyttet:** masterdata, kritikalitet, nye vurderinger, dokumentasjonsoversikt, nye og
  fjernede koblinger, handoff. Alt nektes til leverandøren er gjenåpnet.
- **Historikk og koblinger beholdes** og vises som før. Avslutning sletter aldri vurderinger,
  kritikalitetshistorikk, statushistorikk, dokumentasjonsrader eller koblinger.
- Avslutning endrer ikke koblede risikoer, krav eller saker, og blokkeres ikke av dem.
- Gjenåpning opphever skrivebeskyttelsen; avslutningen står igjen i historikken.

### 6.4 Sletting

Sletting finnes bare for en leverandør registrert ved en feil, og bare når den er **helt ubrukt**:
`supplier.delete` og `Supplier::isDeletable()`, som krever at leverandøren ikke har statusendringer,
kritikalitetsendringer, vurderinger, dokumentasjonsrader eller koblinger til risiko, krav eller saker.
Alt annet avsluttes i stedet. Databasen håndhever det samme med NO ACTION fra alle barnetabeller (§10).

---

## 7. Integrasjon med eksisterende moduler

### 7.1 Arkitekturprinsipp: ingen kopiert funksjonalitet

Leverandøroppfølging **viser og initierer**. Modulen som eier objektet, gjør alt annet.

| Modul | Leverandøroppfølging gjør | Modulen selv eier |
|---|---|---|
| **Risiko** | Viser risikoer som gjelder leverandøren; kobler til; starter opprettelse via `RiskCreator` | Vurdering, iboende risiko og restrisiko, behandling, tiltak, aksept, revurdering |
| **Avvik og forbedringer** | Viser saker hos leverandøren; kobler til; starter opprettelse via `ImprovementCaseCreator` | Status, fagområde, tiltak, effektverifisering, lukking |
| **Etterlevelse og revisjon** | Viser krav som gjelder leverandøren; kobler til | Krav, kilde, generell etterlevelsesvurdering, revisjon |

Felles regler:

- **Koblingen er bare id-er.** Ingen kopierte felter, ingen speilet status, ingen egne kolonner for
  noe den andre modulen eier.
- **Lesing går gjennom den andre modulens tilgangstjeneste** (`RiskAccessService::visibleRisks()`,
  `ComplianceAccessService::visibleRequirements()`, `ImprovementCaseAccessService::visibleCases()`).
  Det brukeren ikke kan se, finnes ikke: ikke listet, ikke telt, ikke nevnt («2 skjulte risikoer» er
  også en lekkasje).
- **Panelet vises bare når kunden har modulen og brukeren kan lese den.** Ellers er prop-en `null`,
  som `quality_context` i Etterlevelse. Gaten står på modul + rettighet, ikke på pakke.
- **Skriving krever rettighet på begge sider**: `supplier.edit` her og målmodulens egen rettighet der,
  sjekket av målmodulens egen kode.
- **Avsluttet leverandør** tillater ingen nye koblinger, frakoblinger eller handoff.
- **Slettes objektet i den andre modulen, forsvinner koblingen** (`ON DELETE CASCADE` på den siden).
  En restrict ville fortalt en Risiko-bruker at en leverandør de kanskje ikke kan se, peker på
  risikoen.

### 7.2 Risiko

- Koblingstabell `supplier_risks`.
- **Se:** `visibleRisks()` ∩ koblede. Tittel, fagområde, risikonivå (slik Risiko selv beregner det
  med `RiskScoringPolicy`), status, lenke.
- **Koble/fjerne:** `supplier.edit` + `risk.edit` på risikoen i dens fagområde.
- **Opprette:** `supplier.edit` + `risk.create` i valgt fagområde. Forarbeid: opprettelseslogikken i
  `RiskController::store()` trekkes ut til en gjenbrukbar `RiskCreator` (`rules()` + `create()`) uten
  atferdsendring, i egen commit før noe i Leverandøroppfølging kan opprette risiko — samme grep som
  ga `ImprovementCaseCreator` (fc32714e). Både `RiskController::store()` og leverandørens handoff
  bruker deretter `RiskCreator`.
- **Omvendt visning:** risikosiden viser «Gjelder leverandør» kun med modul `supplier` +
  `supplier.view`.

### 7.3 Etterlevelse og revisjon — krav som gjelder leverandøren

- Koblingstabell `supplier_compliance_requirements`.
- **Se:** `visibleRequirements()` ∩ koblede, gated på `ComplianceAccessService::canReadFromAnotherModule()`.
  Viser referanse, tittel, kilde og lenke. Et utgått krav (kravets egen livssyklus) vises nedtonet.
- **Koble:** `supplier.edit` + `compliance.view`; bare aktive krav. Å si «dette kravet gjelder
  leverandøren» er en påstand om leverandøren, ikke en endring av kravet.
- **Ingen leverandørstatus fra kravet.** Et krav vurdert som *Oppfylt* i Etterlevelse betyr at
  virksomheten oppfyller det; det betyr ikke at leverandør A gjør det. Leverandørsiden viser derfor
  aldri kravets etterlevelsesstatus, og `ComplianceStatusResolver` brukes ikke av Leverandøroppfølging.
- **Ingen per-leverandør kravstatus i v1** og ingen `supplier_requirement_assessments`. Leverandørens
  faktiske situasjon vurderes i leverandørvurderingen (§4.3).
- Opprette krav gjøres i Etterlevelse og revisjon. Kildetypen `contract` (`ComplianceSource::KIND_CONTRACT`)
  finnes der for krav som stammer fra en avtale.

### 7.4 Avvik og forbedringer

- Koblingstabell `supplier_improvement_cases`.
- **Opprette:** `SupplierImprovementHandoffService`, modellert etter
  `ComplianceAuditFindingHandoffService`: saken opprettes av `ImprovementCaseCreator`, som selv sjekker
  `improvement.edit` i valgt fagområde og gyldig ansvarlig; koblingen skrives i samme transaksjon, med
  leverandørraden låst mot samtidig avslutning. Brukeren velger type.
- **Fagområde:** kreves av Avvik og forbedringer etter modulens egne regler. Det gir ikke leverandøren
  noe fagområde og ikke noe parallelt fagområdesystem.
- **Koble eksisterende:** `supplier.edit` + saken synlig i `visibleCases()`.
- **Se:** `visibleCases()` ∩ koblede: type, tittel, status og frist lest gjennom Avvik og forbedringers
  tilgangstjeneste ved visning, med lenke. Ingenting av dette lagres hos leverandøren.
- **Proveniens:** `supplier_assessment_id` på koblingen sier at saken kom fra en bestemt vurdering.
  Saksiden får `supplier_origin` via `provenanceFor()`, gated på modul `supplier` + `supplier.view`.
- `ImprovementCase::isDeletable()` endres ikke; en slettbar sak tar koblingen med seg.

### 7.5 Mål og KPI — utenfor v1

Leverandør-KPI (leveringspresisjon, SLA, antall avvik) forutsetter en avklaring av hvordan målinger
knyttes til en leverandør i Mål og KPI. Leverandørvurderingens kriterier dekker behovet kvalitativt i
v1. En senere kobling skal eies av KPI-siden (mønster `kpi_processes`).

### 7.6 Kvalitet — utenfor v1

Kobling til prosessen leverandøren leverer inn i (ISO 9001 8.4) dekkes i v1 av «Hva leverer de til
oss?». En senere kobling skal bruke `QualityProcessContextReader`, og en aktivitetskobling må
registreres i `QualityActivityLinkCleanup`.

---

## 8. Trenger oppmerksomhet

`SupplierAttentionService::overview()` i samme form som `RiskAttentionService` og
`ImprovementAttentionService`: faste regler over synlige, ikke-avsluttede leverandører; kategorier kan
overlappe; total = unike leverandører; bare ikke-tomme kategorier returneres; ingen poengscore. Ett
panel på registeret, og grunnene vises inline på leverandørsiden.

**Regel for v1:** signalene bruker bare Leverandøroppfølgings egne tabeller (`suppliers`,
`supplier_assessments`, `supplier_documents`). Ingen signal leser status, aggregater eller
koblingstabeller mot andre moduler.

| # | Signal | Regel |
|---|---|---|
| 1 | **Ikke vurdert** | Aktiv, Viktig eller Kritisk, ingen vurdering. |
| 2 | **Vurdering forfalt** | Aktiv, neste vurdering (`SupplierReviewSchedule`) er passert. |
| 3 | **Mangler ansvarlig** | Ikke avsluttet, intern ansvarlig er tom. |
| 4 | **Dokument utløpt** | Ikke avsluttet; en ikke-erstattet dokumentasjonsrad har «Gyldig til» før i dag. |
| 5 | **Dokument utløper snart** | Som 4, men «Gyldig til» innen 60 dager (konstant i koden). |

**Utenfor v1** — krever tilgangsbevisst lesing av andre moduler, eller en terskel som ikke er avklart:

- Åpent leverandørrelatert avvik
- Leverandør med høy risiko
- Krav som ikke etterleves
- Svak vurdering uten oppfølging (måtte lese koblinger til saker brukeren kanskje ikke ser; et skjult
  sak-objekt ville da skrudd av signalet og røpet at det finnes)
- Lenge under vurdering
- Signaler i bjella / Mine oppgaver — punkt 6, se [notifications-and-tasks-plan.md](notifications-and-tasks-plan.md)

---

## 9. Tilgang

### 9.1 Kundeglobal

Leverandøroppfølging er **kundeglobal i v1**, som Etterlevelse og revisjon. Leverandøren får ingen
`business_area_id`, står ikke i `BusinessArea::SCOPED_CONTENT_TABLES` og ikke i
`CustomerPermissionCatalog::areaScopedDomains()`. En leverandør betjener ofte flere fagområder, og
fagområdestyring ville tvunget fram duplikater av masterobjektet.

Fagområde dukker bare opp der en annen modul krever det etter egne regler: når en risiko eller en sak
opprettes fra leverandøren, velges fagområdet for *risikoen* eller *saken*.

### 9.2 Rettigheter

Fire, ikke flere:

| Nøkkel | Gir |
|---|---|
| `supplier.view` | Åpne Leverandører; se register, leverandørside, vurderinger, dokumentasjonsoversikt, historikk |
| `supplier.edit` | Registrere og endre leverandør; endre kritikalitet; dokumentasjonsoversikt; ta i bruk, avslutte, gjenåpne; koble til/fra og opprette i andre moduler (sammen med rettighet der) |
| `supplier.assess` | Registrere leverandørvurdering. `edit` gir det ikke, og `assess` gir ikke `edit` — som `risk.assess` og `compliance.assess` |
| `supplier.delete` | Slette en helt ubrukt leverandør (§6.4) |

### 9.3 System Owner — fail-closed

**System Owner får ikke automatisk tilgang til Leverandøroppfølging.** Domenet `supplier` legges i
`CustomerPermissionCatalog::explicitGrantDomains()` ved siden av `compliance`.

Tilgang krever begge deler:

1. at kunden har modulen `supplier` (GRC-entitlement), og
2. en `supplier.*`-rettighet gjennom en rolle brukeren selv har.

Uten eksplisitt supplier-grant er System Owner **fail-closed**: menypunktet vises ikke, rutene svarer
403, og ingen leverandørdata vises — heller ikke de omvendte visningene på risiko- og saksidene.
System Owner administrerer fortsatt rollene som gir `supplier.*`, og kan gi seg selv en slik rolle.

### 9.4 Inngang

- `SupplierAccessService` er eneste inngang til leverandørdata: `canOpenModule()`, `visibleSuppliers()`,
  `findVisibleSupplier()` (annen kunde = 404, mangler `view` = 403), `canEdit()`, `canAssess()`,
  `canDelete()`, `canReadFromAnotherModule()` (modul + `view`, for omvendte visninger),
  `isValidOwner()`, `ownerCandidates()` — samme form som `ComplianceAccessService`.
- Ruter under `app.supplier-management.` gates av modul `supplier` via `route_modules` (fase 1).

---

## 10. Datamodell

Forslag, ikke migrasjoner. Alle tabeller har `customer_id` med `cascadeOnDelete` mot `customers`.
Barnetabeller bruker sammensatt FK `(supplier_id, customer_id) → suppliers(id, customer_id)` med
NO ACTION, så kryss-kunde-rader avvises og en brukt leverandør ikke kan slettes, mens sletting av
kunden fortsatt kaskaderer (mønster fra `kpis` og `compliance_requirements`). Koblinger mot andre
moduler bruker sammensatt FK mot den andre tabellens `(id, customer_id)` der den finnes, ellers enkel
FK + tjenestesjekk.

### `suppliers` — mutable

| | |
|---|---|
| Formål | Masterobjektet: masterdata, gjeldende kritikalitet og status |
| Felt | `name`, `organization_number` (nullbar), `category`, `deliverable_description`, `owner_user_id` (nullOnDelete), `contact_name`, `contact_email`, `contact_phone`, `note`, `criticality` (`standard`/`important`/`critical`), `review_interval_months` (nullbar), `processes_personal_data`, `has_system_access`, `supports_critical_delivery`, `hard_to_replace` (bool), `status` (`onboarding`/`active`/`ended`), `created_by`, `updated_by`, timestamps |
| Constraints | `unique(id, customer_id)`; partiell unik `(customer_id, organization_number) WHERE organization_number IS NOT NULL`; CHECK på `category`, `criticality`, `status`; CHECK `review_interval_months IN (6, 12, 24, 36)`; CHECK `criticality = 'standard' OR review_interval_months IS NOT NULL` |
| Sletting | Bare når `isDeletable()` (§6.4) |

### `supplier_status_changes` — immutable

| | |
|---|---|
| Formål | Livssyklushistorikk |
| Felt | `supplier_id`, `from_status`, `to_status`, `reason` (påkrevd ved avslutt/gjenåpne), `changed_by_user_id` (nullOnDelete), `changed_at` |
| Constraints | CHECK på lovlige overganger og påkrevd begrunnelse; trigger nekter UPDATE (unntatt null-stilling av forfatter-FK) og DELETE mens kunden finnes — samme som `compliance_requirement_status_changes`; `CREATE OR REPLACE FUNCTION` |

### `supplier_criticality_changes` — immutable

| | |
|---|---|
| Formål | Historikk for endret kritikalitet og intervall |
| Felt | `supplier_id`, `from_criticality`, `to_criticality`, `from_review_interval_months`, `to_review_interval_months`, `from_*`/`to_*` for de fire grunnlagsspørsmålene, `reason` (påkrevd), `changed_by_user_id` (nullOnDelete), `changed_at` |
| Constraints | CHECK-verdier; samme trigger som over |

### `supplier_assessments` — immutable

| | |
|---|---|
| Formål | Leverandørvurdering |
| Felt | `supplier_id`, `assessed_on` (dato), `assessed_by_user_id` (nullOnDelete), `quality_rating`, `delivery_rating`, `security_rating`, `compliance_rating` (`good`/`acceptable`/`poor`/`not_relevant`), `overall_result` (`satisfactory`/`partially_satisfactory`/`unsatisfactory`), `rationale` (påkrevd), øyeblikksbilde `supplier_name`, `criticality`, `review_interval_months`, `recorded_at` |
| Constraints | CHECK-verdier; trigger nekter UPDATE og DELETE; `assessed_on` ikke fram i tid (validering) |

### `supplier_documents` — mutable

| | |
|---|---|
| Formål | Dokumentasjonsoversikt — metadata, ingen fil |
| Felt | `supplier_id`, `document_type`, `title`, `location` (fritekst/URL), `valid_from`, `valid_until`, `comment`, `replaced_by_document_id` (nullbar, selv-FK, set null), `created_by`, `updated_by`, timestamps |
| Constraints | CHECK `document_type`; CHECK `valid_from <= valid_until`; erstatning innen samme leverandør (tjenestesjekk) |
| Sletting | Enkeltrader kan slettes med `supplier.edit` mens leverandøren ikke er avsluttet |

### `supplier_risks` — kobling

| | |
|---|---|
| Felt | `supplier_id`, `risk_id`, `origin` (`created_from_supplier`/`linked`), `created_by`, `created_at` |
| Constraints | `unique(supplier_id, risk_id)`; cascade fra risiko, NO ACTION fra leverandør |

### `supplier_compliance_requirements` — kobling

| | |
|---|---|
| Felt | `supplier_id`, `compliance_requirement_id`, `created_by`, `created_at` |
| Constraints | `unique(supplier_id, compliance_requirement_id)`; cascade fra krav, NO ACTION fra leverandør |
| Merknad | Ingen statuskolonne. Det finnes ingen leverandørspesifikk kravstatus i v1. |

### `supplier_improvement_cases` — kobling + proveniens

| | |
|---|---|
| Felt | `supplier_id`, `improvement_case_id`, `supplier_assessment_id` (nullbar), `origin` (`handoff`/`linked`), `created_by`, `created_at` |
| Constraints | `unique(supplier_id, improvement_case_id)`; cascade fra sak, NO ACTION fra leverandør og vurdering |

**Ikke i datamodellen:** poeng, `next_review_at`, «forfalt»-flagg, kopi av risikonivå, saksstatus eller
kravstatus, per-leverandør kravvurdering, fagområde på leverandøren, kontraktsdatoer på leverandøren,
filer.

**E2E-opprydding** (`SupplierE2EFixture`) må slå av de tre historikk-triggerne i egen transaksjon,
som `ComplianceE2EFixture` gjør.

---

## 11. Navigasjon og UI

### 11.1 Navn

| Hvor | Navn |
|---|---|
| Menypunkt under Styring | **Leverandører** |
| Sidetittel på registeret | **Leverandører** |
| Modulnavn — Styring-landingssiden, Abonnement, rettighetsdomenet i Tilganger, hjelpetittel | **Leverandøroppfølging** |

«Leverandører» er ikke tvetydig i dagens navigasjon: Anbuds Doffin-side vises som «Konkurrenter»
(`navigation.competitors`). Brukeren ser aldri den tekniske stien.

### 11.2 Teknisk navnerom

Anbud bruker allerede `supplier`-ordet internt for Doffin-konkurrentdata: `/app/suppliers`,
`app.suppliers.*` (gated til `tender`), `App\Http\Controllers\App\SupplierController`,
`procynia.suppliers`, og rail-området `suppliers` (`activeModuleKey('suppliers') → 'tenders'`).
Leverandøroppfølging bruker derfor:

| | |
|---|---|
| URL | `/app/supplier-management` |
| Rutenavn | `app.supplier-management.*` |
| Kontrollere | `SupplierManagementController`, `SupplierAssessmentController`, `SupplierDocumentController`, … — aldri `SupplierController` |
| Lang | `procynia.supplier_management.*` |
| Rail-område (aktiv-markering) | `supplier-management` — aldri `suppliers`, som markerer Anbud aktiv |
| Modul / rettighetsdomene | `supplier` (finnes i config) / `supplier.*` |
| Tabeller / modell / tjenester | `suppliers`, `supplier_*` / `Supplier` / `App\Services\Suppliers\*` (ledige; Doffin bruker `doffin_suppliers`) |

Rail-*modulnøkkelen* `suppliers` i `appModules.js` kan beholdes (den er en modulnøkkel, ikke et område,
og `moduleSidebar.test.js` bruker den); fase 1 bekrefter med den testen at aktiv-markeringen havner
riktig.

### 11.3 Plassering

- `appModules.js`: den planlagte raden får `built: true`, `href: '/app/supplier-management'`,
  `permission: 'supplier.view'`.
- `GovernanceController::MODULES` får `supplier` (`GovernanceControllerTest` krever at listene
  stemmer).
- Rekkefølge fra `sort_order` i `config/procynia_modules.php` (50, etter Etterlevelse og revisjon).
- Ingen nivå 2 i menyen. Ingen egen «Oversikt»-side.

### 11.4 Sider

| Side | Innhold |
|---|---|
| **Register** | Trenger oppmerksomhet øverst (bare med funn). Tabell: navn, kategori, kritikalitet, status, intern ansvarlig, neste vurdering. Filtre: status (standard: ikke avsluttet), kritikalitet, kategori, «Trenger oppmerksomhet», søk på navn/orgnr. «Registrer leverandør». |
| **Leverandørside** | Hode: navn, status, kritikalitet, handlinger etter tilgang. Seksjoner: Om leverandøren · Hvor viktig er leverandøren · Vurderinger · Dokumentasjon · Risikoer som gjelder leverandøren · Krav som gjelder leverandøren · Avvik og forbedringer hos leverandøren · Historikk. Paneler for andre moduler vises bare når brukeren kan lese dem. |
| **Dialoger** (`ActionDialog`) | Registrer/endre, Endre kritikalitet, Registrer vurdering, Dokumentasjon (ny/endre/fornyet), Ta i bruk/Avslutt/Gjenåpne, Opprett risiko, Følg opp i Avvik og forbedringer, Koble til. |

På alle sider: `PageHelpButton` med kort hjelpetekst i domenespråk; minst 16 px for brødtekst,
etiketter og hjelpetekst; ingen horisontal scroll ved 390 px (registeret blir kortliste på mobil);
norske, modul-lokale valideringsmeldinger (`SupplierValidationMessages`, som `RiskValidationMessages`);
alle strenger i både `lang/no` og `lang/en`.

---

## 12. Teststrategi

Én test per regel, ikke per felt eller valideringsgren. Det som dekkes i PHP, testes ikke på nytt i
E2E.

### PHP (Feature mot Postgres i Docker; rene enhetstester der logikken er ren)

| Område | Hva |
|---|---|
| Tenant-isolasjon | Annen kundes leverandør = 404 på side, vurdering, dokumentasjon og kobling; sammensatt FK avviser kryss-kunde-rad |
| Modul | Ruter nektes uten modul `supplier` |
| Rettigheter | view/edit/assess/delete hver for seg; `edit` gir ikke vurdering og omvendt |
| System Owner | Uten egen supplier-rolle: 403, ingen menypunkt, ingen omvendte visninger. Med egen rolle: tilgang som andre |
| Livssyklus | Lovlige og ulovlige overganger; begrunnelse påkrevd; avsluttet leverandør nekter hver skrivehandling; gjenåpning; historikk skrevet |
| Kritikalitet | Viktig/Kritisk uten intervall avvises (validering + CHECK); endring skriver historikk |
| Uforanderlighet | Vurdering, kritikalitetshistorikk og statushistorikk: modellen kaster og triggeren nekter UPDATE/DELETE |
| Slettevern | Bare helt ubrukt leverandør kan slettes; databasen nekter også |
| Handoff-sikkerhet | Avvik: mangler `improvement.edit` i fagområdet → 422; dobbel innsending gir én sak. Risiko: mangler `risk.create` i fagområdet → 422. Kobling krever rettighet i begge moduler |
| Synlighet på tvers | Koblet risiko/sak/krav brukeren ikke kan se: ikke listet, ikke telt; panel `null` uten modul/rettighet; ingen kravstatus i payload |
| `RiskCreator` | Eksisterende Risiko-tester grønne uendret etter uttrekket |
| Plan og signaler | `SupplierReviewSchedule` (enhetstest, månedskanter); hver av fem regler treffer og bommer én gang |

### JS (Vitest i node:22-docker)

Bare logikk i UI: kritikalitet forhåndsutfyller intervall uten å låse det; paneler rendres ikke når
prop-en er `null`; registeret som kortliste på mobil.

### E2E (Playwright)

Tre spesifikasjoner, med egen fixture, markørnavngitte data og `remaining()` = 0:

1. **Registrere og vurdere** (reise A + B, pluss avslutt/gjenåpne), med `readability.js` (16 px + 390 px)
   på register og leverandørside.
2. **Dokument som utløper** (reise F) og at signalet vises og forsvinner.
3. **Følge opp i andre moduler** (reise C, D, E): opprette risiko og sak fra leverandøren, koble krav,
   og lenkene tilbake.

---

## 13. Eksplisitt utenfor v1

| Utenfor | Hvorfor |
|---|---|
| Innkjøp, bestillinger, faktura, spend-analyse, ERP-integrasjon | Innkjøpssystem, ikke styring |
| Sourcing og anbud mot leverandører | Annet domene |
| Kontraktsforhandling, avtaleversjoner, e-signatur, kontraktsworkflow, full kontraktslivssyklus | Kontraktsstyring; egen planlagt modul (`contracts`) |
| Dokumentopplasting og eget dokumentlager | Kan vurderes i v1.1 (§4.4). *Bygget som v2.1, se v2-plan §27* |
| Leverandørspesifikke dokumenter i Enterprise Wiki | Wiki er kunnskapslag, ikke arkiv (§4.4) |
| **Leverandørspesifikk vurdering av enkeltkrav** | Egen vurderingstype; kan bli egen modell senere hvis behovet blir reelt (§7.3). *Bygget i v2 som kontroller av kontrollkrav (ikke av Etterlevelse-krav), se v2-plan §8* |
| Visning av kravets etterlevelsesstatus på leverandøren | Den gjelder virksomheten, ikke leverandøren (§7.3) |
| Leverandørportal, self-service, egenvurdering fra leverandør | Eksterne brukere er en ny sikkerhetsmodell |
| Spørreskjema/due diligence-utsending | Krever portal eller e-postflyt |
| Avansert ESG, åpenhetslov-vurderinger | Senere, eget behov. *Delvis dekket i v2 av aktsomhetsvurderingen, v2-plan §11* |
| Automatiske kredittsjekker, Brønnøysund-oppslag, tredjepartsfeeds | Eksterne integrasjoner, kostnad, personvern |
| AI-scoring, AI-forslag til kritikalitet | Ikke behov i v1; AI skal støtte, ikke vurdere |
| Poengscore, vekting, beregnet kritikalitet, kundedefinerte kriterier | Scoringsmotor |
| Påkrevde dokumenttyper per kritikalitet | Regelmotor. *Erstattet i v2 av en avgrenset anvendelsesregel over faste predikater, v2-plan §5.3* |
| Avanserte dashboards, grafer, heatmap | Register + Trenger oppmerksomhet er nok |
| Egen risikomotor, kravregister, etterlevelsesvurdering, avviks-/tiltaksmotor, KPI-motor | Eies av eksisterende moduler (§7.1) |
| Trenger oppmerksomhet-signaler som leser andre moduler | §8 |
| Fagområde på leverandøren | §9.1 |
| Flere kontaktpersoner, konsernstruktur, underleverandørkjeder | Senere |
| KPI- og Kvalitet-kobling | §7.5, §7.6 |
| Varsler i bjella / Mine oppgaver | Punkt 6, felles for styringsmodulene — Leverandører først ([notifications-and-tasks-plan.md](notifications-and-tasks-plan.md)) |
| Import fra Excel | Eget steg etter v1 |

---

## 14. Gjenbruk av eksisterende arkitektur

| Behov | Gjenbruk |
|---|---|
| Rettigheter | `CustomerPermissionCatalog` (nytt domene `supplier`, i `explicitGrantDomains()`), `CustomerPermissionService`, Tilganger-UI |
| Tilgangstjeneste | Form fra `ComplianceAccessService` (kundeglobal, eksplisitt tildeling, `canReadFromAnotherModule()`) |
| Pakke/modul | `supplier` i `grc` finnes allerede; ny linje i `route_modules`; `ModuleEntitlementService` |
| Navigasjon | `appModules.js` (eksisterende planlagt rad), `GovernanceController::MODULES`, `navigationGroups.js` |
| Livssyklus + historikk | `ObjectiveLifecycleService`/`ComplianceRequirementLifecycleService` (lås, én transaksjon) + trigger fra `compliance_requirement_status_changes` |
| Uforanderlig vurdering | `ComplianceAssessment`/`RiskAssessment` (øyeblikksbilde, ingen update/delete, siste = gjeldende) |
| Vurderingsplan | `RiskReviewSchedule` (ren klasse, `addMonthsNoOverflow`) |
| Opprette sak | `ImprovementCaseCreator` + mønster fra `ComplianceAuditFindingHandoffService` (lås, dobbel innsending, `provenanceFor()`) |
| Opprette risiko | `RiskCreator`, trukket ut av `RiskController::store()` |
| Lesing på tvers | `visibleRisks()`, `visibleRequirements()`, `visibleCases()`, `RiskScoringPolicy` |
| Trenger oppmerksomhet | Form fra `RiskAttentionService`/`ImprovementAttentionService`; komponentmønster fra `ComplianceAttention.jsx` |
| UI | `PageHelpButton`, `ActionDialog`, `access.permissions` (aldri `permissions`) |
| Validering | Modul-lokale meldinger etter `RiskValidationMessages` |
| Test | `UsesProjectPostgresConnection`, `tests/e2e/helpers/readability.js`, ny `tests/e2e/helpers/suppliers.js` |

---

## 15. Implementeringsrekkefølge

Én feature-gren (`feat/supplier-management`). Hver fase er én eller flere commits med målrettede
tester; full suite bare som merge-port i siste fase.

| Fase | Innhold | Reise |
|---|---|---|
| **1. Tilgang, entitlement-kontrakt og navigasjon** | Domene `supplier` i katalogen + `explicitGrantDomains()`; etiketter i Tilganger; `SupplierAccessService`; `route_modules`; tom registerside; rail `built: true`; `GovernanceController::MODULES`. Tester: modul, tenant, rettigheter, System Owner fail-closed. | |
| **2. Leverandørregister og livssyklus** | `suppliers` (uten kritikalitetsfeltene), `supplier_status_changes`, `SupplierLifecycleService`; registrere/endre, ta i bruk/avslutt/gjenåpne, slette; register med filtre; leverandørside; PageHelp. | A (uten kritikalitet) |
| **3. Kritikalitet** | Kritikalitetsfelter og CHECK-er på `suppliers`; `supplier_criticality_changes`; seksjonen i registreringsskjemaet; «Endre kritikalitet»; filter og kolonne i registeret. | A |
| **4. Leverandørvurderinger** | `supplier_assessments`, `SupplierReviewSchedule`; skjema og historikk; «Neste vurdering» i registeret. | B |
| **5. Dokumentasjonsoversikt** | `supplier_documents`; ny/endre/fornyet/slett; utløpsvisning. | F |
| **6. Avvik og forbedringer** | `supplier_improvement_cases`; `SupplierImprovementHandoffService` (fra leverandør og fra vurdering); koble eksisterende; panel; `supplier_origin` på saksiden. | E |
| **7. `RiskCreator` + Risiko** | Først uttrekket av `RiskCreator` uten atferdsendring (egen commit). Så `supplier_risks`, koble/opprette, panel, «Gjelder leverandør» på risikosiden. | C |
| **8. Krav** | `supplier_compliance_requirements`; koble; panel uten kravstatus. | D |
| **9. Trenger oppmerksomhet, lesbarhet og merge-port** | `SupplierAttentionService` (fem regler); panel og inline-grunner; gjennomgang av 16 px og 390 px; de tre E2E-spesifikasjonene samlet; full PHP-/JS-/E2E-kjøring som merge-port. | alle |

Kritikalitet er skilt ut fra registeret (fase 3) fordi den har egen historikk, egne regler og et eget
spørsmål — og fordi vurderingene i fase 4 tar øyeblikksbilde av den. Fase 2 kan derfor ikke tas i bruk
av kunder alene; modulen skrus på for kunder først når v1 er merget.

---

## 16. Beslutninger

### 16.1 Låst

| Beslutning | Se |
|---|---|
| System Owner får ikke automatisk tilgang; fail-closed uten egen supplier-rolle (`explicitGrantDomains()`) | §9.3 |
| Fire rettigheter: `supplier.view`, `supplier.edit`, `supplier.assess`, `supplier.delete` | §9.2 |
| Kundeglobal; ingen fagområde på leverandøren | §9.1 |
| Én leverandør-record per virksomhet/part — masterobjektet | §4.1 |
| Tre kritikalitetsnivåer: Standard, Viktig, Kritisk; brukeren velger; ingen score; ingen overstyring | §4.2 |
| Viktig og Kritisk krever vurderingsintervall | §4.2 |
| Uforanderlig kritikalitetshistorikk med begrunnelse | §4.2 |
| Uforanderlige leverandørvurderinger; neste dato beregnet, ikke lagret | §4.3 |
| Kritikalitet og leverandørvurdering holdes adskilt | §2, §4.2, §4.3 |
| Ingen dokumentopplasting i v1; dokumentasjonsoversikt med metadata; aldri Enterprise Wiki som lager | §4.4 |
| Livssyklus Under vurdering → Aktiv → Avsluttet, med gjenåpning; avsluttet er skrivebeskyttet; historikk og koblinger beholdes | §6 |
| Sletting bare for helt ubrukt leverandør | §6.4 |
| Ingen kopiert funksjonalitet fra Risiko, Etterlevelse eller Avvik og forbedringer | §7.1 |
| `RiskCreator` trekkes ut før Leverandøroppfølging kan opprette risiko | §7.2 |
| Ingen per-leverandør kravstatus; kravets etterlevelsesstatus vises ikke på leverandøren | §7.3 |
| Trenger oppmerksomhet: fem lokale signaler, ingen cross-module-signaler, ingen score | §8 |
| Teknisk sti `/app/supplier-management`; menypunkt «Leverandører»; modulnavn «Leverandøroppfølging» | §11 |
| Ingen endring i pakkekonfigurasjon eller entitlements i denne planen | §1.1 |

### 16.2 Åpne spørsmål

Ingen som blokkerer fase 1 eller senere faser.

Detaljer som er bestemt i planen og kan justeres under implementering uten ny produktbeslutning:
kategorilisten (§4.1), forhåndsutfylte intervaller (§4.2) og 60-dagersgrensen for «utløper snart» (§8).
