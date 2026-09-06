# Enterprise Wiki — godkjenningsmodell

> **Status: Godkjenningsmodellen er implementert og verifisert (steg 1–14)**

Godkjenningsmodellen dette dokumentet beskriver — eierskap, innsending, kontrollørtildeling,
kildeeierport, endelig godkjenning med publisering, retur med begrunnelse, varsling og
publiseringsstyrt retrieval — er bygget, testet og verifisert. Den gjelder nå som **FAKTA**.

Dette er **ikke** det samme som at Enterprise Wiki er ferdig. Uttrekk, verifisering, reparasjon og
figurgenerering er egne løyper med egen status. Se `docs/enterprise-wiki-architecture.md`.

Der dokumentet beskriver dagens kode er det merket **FAKTA** med filreferanse. **BESLUTNING** markerer
vedtatt retning som nå er implementert; **ÅPENT** markerer det som fortsatt ikke er besluttet — ikke
improviser der.

Ved konflikt mellom dette dokumentet og eldre samtaler eller antakelser: dette dokumentet gjelder.

---

## 1. Formål

Dagens godkjenningsmodell for Wiki-sider er utilstrekkelig. Det ble avdekket under arbeidet med
PageHelp for Wiki-sidevisningen: hjelpeteksten kunne ikke forklare hva «Send til gjennomgang» gjør,
fordi handlingen i praksis ikke gjør annet enn å sette et statusfelt.

Kartleggingen som fulgte avdekket tre arkitekturproblemer:

1. Det finnes ingen mottaker, ingen reviewer og ingen oppgave — bare en statusverdi.
2. Innsender og godkjenner er samme rolle (System Owner), så det er ingen reell kontroll.
3. Nytt, ugodkjent innhold blir gjeldende versjon umiddelbart.

Dokumentet finnes for å etablere en eksplisitt modell for **eierskap**, **kildeansvar**, **review** og
**publisering** før implementeringen starter — slik at videre arbeid ikke improviserer roller eller
arbeidsflyt underveis, og slik at delimplementeringer kan måles mot en avtalt målmodell.

---

## 2. Dagens løsning — FAKTA

Alt i denne seksjonen er verifisert mot koden og mot lokal database.

### 2.1 Opprettelse — to veier

**FAKTA** En Wiki-side kan oppstå på to måter, og de oppfører seg forskjellig.

**Vei 1 — `article` / `summary`**
`app/Jobs/Ai/Wiki/ProcessEnterpriseWikiIngest.php:111`

Oppretter alltid en **ny** side. Slug får et tilfeldig suffiks
(`Str::slug($title).'-'.Str::lower(Str::random(6))`), så kollisjon med en eksisterende side er
praktisk talt utelukket. Oppretter samtidig versjon 1 og en `EnterpriseWikiIngestRunPage` med
`action = created`.

**Vei 2 — `concept` / `entity`**
`app/Services/EnterpriseWiki/EnterpriseWikiMaintainerDecisionApplyService.php:269-280`

Slår opp på `(customer_id, proposed_slug)`. Finnes siden allerede, **gjenbrukes den**
(`return [$existing, false]`), og pivotraden blir `action = updated`. Slugen er deterministisk, ikke
tilfeldig.

Konsekvensen er at vei 2 lar en kjøring fra ett dokument feste innhold på en side som ble opprettet
av et helt annet dokument.

**FAKTA** Én ingest-kjøring har nøyaktig én kilde: `enterprise_wiki_ingest_runs.source_type` +
`source_id`, hvor `source_type` er `enterprise_wiki_document` eller `knowledge_item_version`
(`app/Models/EnterpriseWikiIngestRun.php:117-123`). Men én kjøring produserer **mange** sider — i
lokal database ga 4 kjøringer 24 pivotrader.

### 2.2 Opprinnelse

**FAKTA** Det opprinnelige dokumentet kan identifiseres i dag, uten ny kolonne:

```
enterprise_wiki_ingest_run_pages  (action = 'created')
  → enterprise_wiki_ingest_runs   (source_type, source_id)
    → enterprise_wiki_documents
```

Verifisert: hver av de 19 sidene i lokal database har nøyaktig én `created`-rad.

**FAKTA** `enterprise_wiki_pages` har ingen `source_document_id`, `origin_document_id` eller
tilsvarende kolonne. Kolonnene er: `id, customer_id, department_id, slug, title, scope, status,
generated_by, owner_user_id, last_source_hash, reviewed_at, reviewed_by_user_id, archived_at,
page_type`.

Forbehold: koblingen er ikke indeksert for dette bruket, og kjøringer kan feile — sider fra en feilet
kjøring blir liggende (run 54 og 55 står som `failed` i lokal database).

### 2.3 Flere kilder på samme side

**FAKTA** En Wiki-side kan inneholde kunnskap fra flere dokumenter. Proveniens ligger **per
innholdsblokk** og **per claim**, ikke på siden.

`app/Services/EnterpriseWiki/EnterpriseWikiDocumentOwnerApprovalService.php:465`
(`sourceBasedDocumentIdsForVersion()`) leser `enterprise_wiki_page_versions.content_blocks_json`,
plukker blokker med `content_origin = source_based`, henter `source_type`/`source_id` per blokk, og
**unionerer** med claims' egne kildereferanser (`buildRequirementGroups()`, linje 382).

### 2.4 Sideeier

**FAKTA** `enterprise_wiki_pages.owner_user_id` **eksisterer allerede** — i `$fillable` og med
`belongsTo(User::class, 'owner_user_id')` på `app/Models/EnterpriseWikiPage.php:101`.

~~**Den skrives aldri.**~~ **Tatt i bruk i steg 2** — se 3.1. Begge opprettelsesveier setter den nå
fra eieren av det opprinnelige kildedokumentet, og eksisterende sider er backfillet.

### 2.5 Dokumenteier

**FAKTA** `enterprise_wiki_documents.owner_user_id` finnes og er i aktiv bruk. Den kan endres, og
`EnterpriseWikiDocumentOwnerApprovalService::syncForDocument()` finnes nettopp for å re-synke
godkjenningskrav på alle gjeldende versjoner som bruker dokumentet når eierskapet endres.

I lokal database har 3 av 3 dokumenter eier.

### 2.6 Document owner approvals

**FAKTA** `EnterpriseWikiPageVersionDocumentOwnerApproval` finnes og er et gjennomarbeidet
delsystem. Feltene inkluderer `enterprise_wiki_page_version_id`, `document_owner_user_id`,
`source_document_ids` (**array**), `approval_status` (`pending`/`approved`/`rejected`),
`decided_at`, `decided_by_user_id`, samt override-felter (`is_override`, `override_reason`,
`overridden_by_user_id`, `overridden_at`).

Modellen kan altså allerede representere flere kildedokumenter, flere dokumenteiere, og godkjenning
per versjon. **Dette er den viktigste eksisterende byggeklossen — målmodellen skal bygge på den, ikke
ved siden av den.**

### 2.7 Dagens review

**FAKTA** `app/Http/Controllers/App/WikiController.php`:

| Handling | Linje | Autorisasjon | Overgang |
|---|---|---|---|
| `submit()` | 2247 | `isSystemOwner()` | `draft → pending_review`, og `rejected → draft` |
| `approve()` | 2281 | `isSystemOwner()` | `pending_review → approved` |
| `reject()` | 2312 | `isSystemOwner()` | `pending_review → rejected` |

Alle tre bruker inline `if (! $user?->isSystemOwner()) abort(403);` — ingen policy. Alt annet gir
`abort(422)`.

Videre:

- **Ingen eksplisitt reviewer.** Ingen assignee-kolonne.
- **Ingen mottaker.** Siden tildeles ikke noen.
- **Ingen notification eller task.** Ingen `Notification::` eller `notify()` i `WikiController`.
- **Samme rolle sender inn og godkjenner.** Kontrollerkommentaren bekrefter at det er bevisst i
  pilot: *«System Owner only (pilot). Bid Manager as approver deferred to later phase.»*
- `approve()`/`reject()` setter `reviewed_at` og `reviewed_by_user_id` — det er all sporingen som
  finnes.
- **FAKTA** Sider kan nå `pending_review` uten at noen trykker: `FinalizeEnterpriseWikiIngest.php:186`
  setter status automatisk når en kjøring fullfører.
- **FAKTA** `visibleEnterpriseWikiPageStatuses()` (`app/Models/User.php:389`) returnerer **alle**
  statuser for enhver bruker med kundefront-tilgang. Status skjuler aldri en side; den styrer bare
  hvem som kan handle.
- **FAKTA** `STATUS_SUPERSEDED` er deklarert, men settes aldri i `app/`.

### 2.8 Versjonsfeilen — kritisk

**FAKTA** `app/Jobs/Ai/Wiki/FinalizeEnterpriseWikiIngest.php:179-189` gjør begge deler i samme steg:

```php
$pageVersion->update([
    'content_markdown' => $markdown,
    'is_current' => true,          // ny versjon blir gjeldende med én gang
]);
...
EnterpriseWikiPage::query()
    ->where('id', $run->enterprise_wiki_page_id)
    ->update(['status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW]);
```

**Nytt, ugodkjent innhold blir gjeldende versjon før noe menneske har sett på det.** Det finnes ingen
parallell publisert versjon som holder stand mens den nye vurderes — siden har én status og én
`is_current`-versjon om gangen.

Dette er **den mest alvorlige feilen i dagens modell**, og den undergraver hele
godkjenningsbegrepet: «godkjent» beskriver i praksis noe som allerede er ute hos leserne. Den må
rettes før godkjenningsløypen bygges videre, ellers bygger vi kontroller rundt innhold som allerede
er publisert.

---

## 3. BESLUTNING: To eierskapsbegreper

Systemet skal skille eksplisitt mellom to former for ansvar. De er ikke i konflikt, fordi de svarer
på forskjellige spørsmål.

### 3.1 Wiki-sideeier

**Betyr:** personen med helhetsansvar for siden som helhet.

**BESLUTNING**

- Settes når siden opprettes.
- Arves fra eieren av **det opprinnelige kildedokumentet** — dokumentet som faktisk opprettet siden
  (`action = created`, jf. 2.2).
- Senere kilder endrer **ikke** sideeier automatisk.
- Kan senere endres eksplisitt, og endringen skal auditeres.

**BESLUTNING — terminologi:** begrepet «primærkilde» skal **ikke** innføres som eget domeneobjekt.
Bruk «opprinnelig kildedokument». Grunnen er at «primærkilde» antyder en rangering mellom kilder som
systemet verken beregner eller vedlikeholder; «opprinnelig» er en ren historisk kjensgjerning vi
allerede kan lese ut av dataene.

### Gjennomført i steg 2

**BEKREFTET INVARIANT**

> Wiki-sideeier settes én gang ved opprettelse fra opprinnelig kildeeier, og endres ikke automatisk
> når senere kilder beriker siden eller når dokumenteierskap endres.

Implementert i `app/Services/EnterpriseWiki/EnterpriseWikiPageOwnerService.php`, koblet inn i begge
opprettelsesveiene (`ProcessEnterpriseWikiIngest`, og kun opprettelsesgrenen i
`EnterpriseWikiMaintainerDecisionApplyService::resolvePage()` — gjenbruksgrenen returnerer
eksisterende side urørt).

**BESLUTNING — ingen fallback.** Når kilden ikke kan navngi en eier, forblir `owner_user_id` NULL.
Det er ingen tilbakefall til innlogget bruker, System Owner eller første kundeadmin: en eier ingen
faktisk har akseptert ville gjort feltet usant, hvilket er verre enn at det er tomt.

**Kildetyper**

| `source_type` | Eier utledes | Hvordan |
|---|---|---|
| `enterprise_wiki_document` | Ja | `enterprise_wiki_documents.owner_user_id`, kundescopet |
| `knowledge_item_version` | **Nei** | Se ÅPENT nedenfor |

**ÅPENT — `knowledge_item_version`.** `KnowledgeItemVersion` har ingen eier. Forelderen
`KnowledgeItem` har `owner_user_id`, så en kjede finnes teknisk — men det er et produktspørsmål om
eierskap i AI-kunnskapsbasen skal gi helhetsansvar for en Wiki-side. Det er et annet ansvar, med
egne permissions (`be_enterprise_wiki_document_owner` gjelder kun Wiki-dokumenter). Ingen kjøringer
bruker denne kildetypen i dag. Sider fra en slik kjøring får derfor NULL eier, og det er bevisst.

**Backfill (gjennomført).** `php artisan enterprise-wiki:backfill-page-owners` (`--dry-run`,
`--customer=`). Kommando, ikke migrasjon: å tildele ansvar til navngitte personer bør være noe noen
velger å gjøre og kan lese resultatet av, ikke noe som skjer stille under deploy. Idempotent, og
overskriver aldri en eier som allerede er satt.

Opprinnelsen leses via `enterprise_wiki_ingest_run_pages.action = 'created'` → run → dokument. Kun
`created`-rader brukes; en `updated`-rad betyr at en senere kjøring festet innhold på en side som
allerede fantes, og å bruke den ville gitt siden til den som sist beriket den.

Sider hvor opprinnelsen ikke er entydig — ingen `created`-rad, flere, manglende kjøring, slettet
dokument, dokument uten eier, eller ikke-dokumentkilde — hoppes over og rapporteres med side-id.
Resultat i lokal database: **17 av 19 sider fikk eier; 2 (205, 208) har kun `updated`-rader og står
bevisst uten.**

**ÅPENT — manuelt eierskifte.** Det finnes ingen måte å endre sideeier på i dag, og det er ikke bygget
i dette steget. `owner_user_id` har nå reell semantikk, så et fremtidig eksplisitt eierskifte må være
auditert.

### 3.2 Kildedokumenteier

**Betyr:** personen som er ansvarlig for innhold som stammer fra ett bestemt kildedokument.

**BESLUTNING** Håndteres fortsatt **per versjon** gjennom den eksisterende
document-owner-approval-mekanismen (2.6). Ingen ny parallell mekanisme.

### 3.3 Følgen

En Wiki-side kan altså ha:

- **én** sideeier
- **flere** kildedokumenteiere

Dette er ikke en motsetning: sideeier svarer for siden, kildedokumenteier svarer for sitt eget
innhold på siden.

---

## 4. BESLUTNING: Roller i godkjenningssløyfen

Tre ansvarsnivåer, pluss én administrativ unntaksrolle.

### A. Wiki-sideeier

- Helhetsansvar for Wiki-siden
- Kvalitetssikrer arbeidsversjonen
- Korrigerer innhold
- Sender versjonen til gjennomgang
- Håndterer retur og endringskrav

### B. Kildedokumenteier(e)

- Bekrefter kunnskapen som stammer fra egne dokumenter
- Kan være flere personer på samme versjon

Dette er **kilde- og fagattestasjon**, ikke endelig publisering. En kildedokumenteier bekrefter at
innholdet er riktig gjengitt fra sitt dokument — ikke at siden som helhet er klar.

### C. Wiki-godkjenner

- Endelig kvalitetskontroll
- Godkjenner eller sender tilbake
- Publiserer ny versjon ved godkjenning

**BESLUTNING** Wiki-godkjenner styres av eksplisitt permission/capability. **System Owner skal ikke
hardkodes som normal godkjenner.**

### System Owner

Kun:

- administrativ override
- feilhåndtering
- plattformadministrasjon

**BESLUTNING** System Owner er ikke normal mottaker i arbeidsflyten.

---

## 5. BESLUTNING: Workflow

Mål-arbeidsflyt:

```
draft
  → source_owner_review
    → wiki_review
      → approved / published
```

Ved problemer i et av review-stegene:

```
source_owner_review | wiki_review
  → changes_requested
    → Wiki-sideeier
      → draft
        → ny innsending
```

**BESLUTNING** Hvis vi senere velger å slå sammen source-owner review og wiki-review til ett steg,
skal det være en **eksplisitt, dokumentert beslutning** — ikke en implementasjonsdetalj som oppstår
underveis. De to stegene svarer på forskjellige spørsmål («er dette riktig gjengitt fra mitt
dokument?» versus «er denne siden klar til å publiseres?»), og å slå dem sammen fjerner en reell
kontroll.

**ÅPENT** Forholdet til dagens `rejected`-status, som i dag krever «Gjenåpne» tilbake til `draft`
(jf. 2.7). Se 11.

---

## 6. BESLUTNING: Versjonering — kritisk invariant

**Hvis siden allerede har en godkjent versjon:**

- Den godkjente versjonen **forblir publisert/current**
- Den nye versjonen er en **arbeidsversjon**
- En draft/pending/review-versjon skal **ikke** erstatte publisert versjon
- Først ved endelig godkjenning blir den nye versjonen published/current
- Gammel publisert versjon beholdes i historikken

**Hvis siden aldri har vært godkjent:**

- Arbeidsversjonen finnes og kan leses
- Men den regnes **ikke** som publisert eller godkjent kunnskap før approval

**Ingen draft- eller pending-versjon skal være autoritativ Wiki-kunnskap.**

### Gjennomført i steg 3

**BESLUTNING — modell C: `enterprise_wiki_pages.published_version_id`** (nullable FK).

| Begrep | Kolonne | Betydning |
|---|---|---|
| Arbeidsversjon | `enterprise_wiki_page_versions.is_current` | Den versjonen pipelinen jobber på — QA, lint, lenkebygging, patching, claim-uttrekk |
| Publisert versjon | `enterprise_wiki_pages.published_version_id` | Den versjonen lesere kan stole på. NULL = aldri godkjent |

`is_current` beholder sin dokumenterte betydning. Rundt **40 lesere** i `app/` avhenger av den —
og modellen dokumenterer den selv som «the single source of truth for a page's live/active Wiki
version», håndhevet av `ewpv_page_single_current_unique`. Å omdefinere den til «publisert» ville
endret betydningen under alle disse leserne samtidig.

**Hvorfor én kolonne på siden, ikke et flagg på versjonen:** «maks én publisert versjon» blir sant
*ved konstruksjon* — én kolonne rommer én verdi — i stedet for noe en partial unique index må
forsvare. NULL er en meningsfull tilstand, ikke en manglende verdi.

Avviste alternativer: **A** (gjenbruke `is_current` som publisert) endrer betydningen under ~40
lesere; **B** (`is_published` på versjonen) krever indeks for å hindre to publiserte;
**D** (`working_version_id`) dupliserer det `is_current` allerede uttrykker og gir to
«current»-begreper; **E** er begge ulempene.

**BEKREFTET INVARIANT**

> En Wiki-side har høyst én publisert versjon, navngitt av `published_version_id`. Den settes kun
> ved godkjenning. En ny arbeidsversjon kan eksistere parallelt uten å bli publisert. Har ingen
> versjon vært godkjent, er `published_version_id` NULL og siden har ingen publisert versjon.

**Endringer:** `WikiController::approve()` setter `published_version_id` til arbeidsversjonen,
transaksjonelt. `reject()` rører den ikke — en avvist revisjon skal aldri trekke tilbake innhold som
allerede er godkjent. `FinalizeEnterpriseWikiIngest` publiserer ikke; den setter kun arbeidsversjonen,
som nå er korrekt oppførsel framfor en feil.

**Migrering (gjennomført, i migrasjonen).** Datatilstand før: 1 side `approved`, 18 `draft`, ingen
`pending_review`/`rejected`, ingen anomalier — hver side hadde nøyaktig én current-versjon.

| Gruppe | Antall | Regel |
|---|---|---|
| A: `approved` side | 1 | `published_version_id` = current versjon |
| D: `draft` side | 18 | NULL — aldri godkjent |
| B, C, E, F | 0 | — |

Regelen for A er entydig: siden ble godkjent mens dens current-versjon var current. Verifisert på
side 208 — `reviewed_at` er 2026-09-06, v11 ble opprettet 2026-08-16, altså før godkjenningen.

**ÅPENT — retrieval bytter ikke port i dette steget.** `RequirementWikiCatalogBuilder` leser
fortsatt `currentVersion` bak dokumenteier-porten. Å bytte til `publishedVersion` nå ville tatt
katalogen fra **7 til 1 side** lokalt, fordi nesten ingenting har vært sidegodkjent. Plandokumentets
egen rekkefølge legger dette til **steg 10** («Begrense Spør Wiki og retrieval til
approved/published»), og det hører hjemme der sammen med beslutningen om hvordan
dokumenteier-porten skal nøkles mot publisert versjon.

---

## 7. BESLUTNING: Proveniens — tre uavhengige akser

Disse tre skal aldri presenteres som samme slags status, verken i data, API eller UI.

| Akse | Eksempelverdier | Endres av godkjenning? |
|---|---|---|
| **Page type** | `article`, `summary`, `concept`, `entity` | Nei |
| **Workflow status** | `draft`, `pending`/`review`, `changes_requested`, `approved`, `archived` | Ja |
| **Proveniens** | AI-generert, manuelt redigert/opprettet, source-based | Nei |

**BESLUTNING** «Konsept», «Utkast» og «AI-generert» skal aldri presenteres som samme type status.

**FAKTA — hvorfor dette er nødvendig:** «Konsept» er `EnterpriseWikiPage::PAGE_TYPE_CONCEPT`.
«AI-generert» er verken status eller flagg — det finnes ingen AI-kolonne på modellen;
`resources/js/Pages/App/Wiki/Show.jsx` viser merknaden ubetinget for *alle* sider med status `draft`
eller `pending_review`. Bare «Utkast» er en faktisk workflow-status. De tre ble presentert som
likeartede statuser i UI, og det var opphavet til forvirringen.

---

## 8. BESLUTNING: Hva skjer ved ny kilde

**Scenario.** Dokument A eies av Kari. A oppretter Wiki-side X. Senere bidrar dokument B, eid av Per,
med innhold til samme side.

**Regler:**

1. Kari forblir Wiki-sideeier.
2. Per blir **ikke** automatisk sideeier.
3. Per får kildeansvar for innhold som stammer fra dokument B.
4. Kari og Per kan **begge** inngå i source-owner approval for samme versjon.
5. Wiki-godkjenner tar den endelige publiseringsbeslutningen.

**FAKTA — dette er ikke hypotetisk.** I lokal database har seks sider (275–280) allerede
godkjenningsrader fra to forskjellige dokumenteiere (bruker 8 og bruker 10). Side 275
(«Samhandlings- og styringsmodell») ble opprettet av kjøring 66 fra dokument 63, og har
`owner_user_id = NULL`.

---

## 9. Eksisterende dataproblem som må rettes

**FAKTA** Side 275 har to godkjenningsrader for **samme versjon (331)** og **samme dokument (63)**,
men med forskjellig eier:

| Versjon | `document_owner_user_id` | `source_document_ids` | Status |
|---|---|---|---|
| 331 | 10 | `[63]` | `pending` |
| 331 | 8 | `[63]` | `approved` |

Dokument 63 har i dag `owner_user_id = 10`. Bruker 8 er altså den **gamle** eieren, og raden hennes
står fortsatt igjen — med status `approved`.

**BEKREFTET (steg 1, gjennomført).** Reproduksjonstest viste at årsaken er bredere enn eierbytte.
Rader er nøklet på `(version, owner, source_documents_hash)`, og `syncForPageVersion()` var **rent
additiv** — den opprettet manglende rader, men fjernet eller merket aldri rader som ikke lenger var
påkrevd. **Enhver** endring i dokumentsettet forlot en foreldreløs rad, ikke bare eierbytte:

| Hendelse | Følge |
|---|---|
| Dokument bytter eier | Gammel eiers rad blir liggende |
| Dokument nr. 2 kommer til | Forrige rad (annen hash) blir liggende |
| Dokument slutter å bidra | Eierens rad blir liggende |

**Konsekvensen var en permanent vranglås.** `approvedCurrentVersionPageIds()` krever at *alle* rader
på gjeldende versjon er `approved`. Én foreldreløs `pending`-rad låste siden ute av godkjent kunnskap
for godt — raden var ikke i kravsettet, så den dukket aldri opp i noe godkjenningsgrensesnitt, og
ingen kunne avgjøre den. Siden falt dermed stille ut av `RequirementWikiCatalogBuilder`, katalogen
som grunnlegger AI-svar. Verifisert: med alle gjeldende krav innvilget var siden fortsatt utelatt.

**Konsekvens:** den eksisterende approval-mekanismen må ikke tas i bruk som **obligatorisk
kvalitetsport** før dette er undersøkt. Gjør vi det nå, kan en side enten bli blokkert av en
person som ikke lenger har ansvar, eller regnes som godkjent på grunnlag av en beslutning tatt av
noen som ikke lenger er eier.

### Rettingen — gjennomført

Rotårsaken var at én rad besvarte to spørsmål samtidig: *«må denne eieren fortsatt godkjenne?»* og
*«hva ble besluttet?»*. Ingenting skilte dem.

`superseded_at` (nullable timestamp) gjør skillet eksplisitt. `syncForPageVersion()` pensjonerer nå
hver rad kravsettet ikke lenger ber om. Radene **slettes ikke** — de bærer en reell beslutning med
`decided_at` og `decided_by_user_id`, og invariant 11 krever at det er sporbart.

**BESLUTNING** Ved eierskifte må ny eier godkjenne på nytt; gammel eiers beslutning bevares som
historikk og teller ikke. Dette var allerede avgjort i koden — `approvedCurrentVersionPageIds()`
dokumenterer at «a regenerated page gets fresh pending rows and loses its approval automatically», og
`EnterpriseWikiDocumentOwnerReassignmentTest` fastsetter at eierbytte gjenåpner en fullført kjøring.
Rettingen innfører altså ingen ny produktregel; den gjør den eksisterende gjennomførbar.

**BEKREFTET INVARIANT (steg 1)**

> For en gitt Wiki-versjon skal aktive document-owner approvals til enhver tid reflektere dagens
> eierskap til de kildedokumentene som faktisk bidrar til versjonen. Én eier har nøyaktig én aktiv
> rad per versjon, med `source_document_ids` som union av eierens bidragende dokumenter. Rader som
> ikke lenger er påkrevd merkes `superseded_at` og bevares som historikk — de teller aldri som
> utestående krav og kan ikke avgjøres.

**ÅPENT** Om side 275 er et reelt produksjonsmønster eller et artefakt av testdata.

**BESLUTNING — eksisterende rader backfilles ikke.** Rettingen virker fremover. En rad pensjoneres
først neste gang versjonen synkes, og det er tilstrekkelig: `syncForPageVersion()` kjøres på hver
kjøring, ved hvert eierbytte via `syncForDocument()`, og hver gang completion-gaten evalueres. En
versjon som er i bruk vil derfor rette seg selv.

Konkret betyr det:

- Ingen global reconciliation.
- Ingen Artisan-kommando.
- Ingen data-backfill.
- Ingen masseendring av historiske rader.

Eksisterende foreldreløse rader kan bli stående til neste naturlige sync. **Dette aksepteres.** En
versjon som aldri re-synkes er per definisjon en versjon ingen arbeider med, og en engangsrydding
ville skrevet `superseded_at` på rader ut fra et kravsett beregnet i dag — uten den kjøringen eller
eierendringen som faktisk gjorde dem foreldreløse. Det gir dårligere sporbarhet enn å la sync eie
overgangen.

Det skal derfor ikke bygges separat datarensing for denne tabellen.

---

## 10. Roller og permissions

**FAKTA — dagens rollemodell.** Brukere har to separate rollefelter:

- `users.role` — systemtilgang: `super_admin`, `customer_admin`, `user`
- `users.bid_role` — funksjon i kundefronten: `system_owner`, `bid_manager`, `contributor`

I tillegg finnes `users.is_qa` som et **additivt flagg** oppå `bid_role` — ikke en erstatning for
rollen.

**FAKTA — det finnes allerede en permission-mekanisme.** `customers.permission_settings` (JSON) med
`Customer::roleHasPermission($bidRole, $permission, $isQa)`. Tre Wiki-relaterte permissions finnes
allerede (`app/Models/Customer.php:20-24`):

| Permission | Standard |
|---|---|
| `approve_wiki_claims` | `['system_owner', 'qa']` |
| `be_enterprise_wiki_document_owner` | `['system_owner', 'bid_manager', 'contributor']` |
| `assign_enterprise_wiki_document_owner` | — |

**BESLUTNING** Wiki review skal **ikke** modelleres som en hardkodet rolle. Den skal styres av en
eksplisitt capability.

**BESLUTNING** Capability-en skal legges inn i den **eksisterende** `permission_settings`-mekanismen,
ikke som et nytt parallelt system. Det gir gratis administrasjon via Tilganger-fanen, QA-støtte og
konsistens med resten av produktet.

**Prinsipp:**

- Contributor kan normalt redigere og sende inn
- Bid Manager kan få review-capability
- Customer Admin kan få eller administrere den
- System Owner har override

**BESLUTNING** Den som sender inn skal normalt ikke kunne godkjenne sin egen versjon.

### Gjennomført i steg 4

**BESLUTNING — capability `approve_wiki_pages`.** Lagt i den eksisterende
`permission_settings`-mekanismen, direkte ved siden av `approve_wiki_claims`. Samme prefiks, annet
objekt: forskjellen mellom å gå god for én påstand og å publisere hele siden blir da lesbar både i
koden og i Tilganger-tabellen. `wiki.review`-formen ble avvist fordi huset bruker flat snake_case.

**Granularitet — viktig presisering.** `permission_settings` er verken et rent kundeflagg eller en
individuell brukerrettighet. Den er **per kunde × per rolle**: kunden bestemmer hvilke `bid_role`-er
(pluss pseudorollen `qa`) som holder rettigheten, og brukeren arver den via sin rolle.

Den kan altså uttrykke «Bid Managere kan godkjenne, Contributors kan ikke», men **ikke** «Kari kan,
Per kan ikke» når begge er Bid Manager. Det er tilstrekkelig for steg 4, som bare handler om hvem som
*har rett til* å godkjenne. Å peke ut én person er reviewer assignment — steg 5 — og det er der
behovet for individnivå faktisk oppstår.

**Default `['system_owner']`.** Nøyaktig de som kunne godkjenne før capability-en fantes, så
innføringen utvider ingens tilgang. En kunde må aktivt gi den videre.

**System Owner override.** `Customer::roleHasPermission()` kortslutter til `true` for
`system_owner` før rollelisten konsulteres. Overriden er dermed sentralisert i
permission-mekanismen, ikke gjentatt som en `isSystemOwner()`-sjekk i controlleren.

**Autorisasjon.** `User::canApproveWikiPages()`, samme mønster som `canApproveWikiClaims()`.
`WikiController::approve()` og `reject()` bruker den; ingen av dem har `isSystemOwner()` igjen.

**BESLUTNING — claim approval og page approval er separate rettigheter.** Å ha den ene gir aldri den
andre. Begge retninger er testet.

**Submit uendret.** `submit()` er fortsatt System Owner-only. Å sende inn er en redigeringsrettighet,
ikke en review-rettighet, og hører til et senere steg. Konsekvensen i dag er at en Bid Manager med
review-capability kan godkjenne, men ikke sende inn — som er den tiltenkte arbeidsdelingen.

**ÅPENT — frontend-porten.** `resources/js/Pages/App/Wiki/Show.jsx` viser fortsatt godkjenn/avvis
kun til System Owner (`auth.user.is_system_owner`). Filen var fryst i dette steget.
`can_approve_wiki_pages` deles allerede som Inertia-prop, så oppdateringen er en enlinjes endring når
filen kan røres.

### Gjennomført i steg 5 — tildeling og innsendingsmetadata

**BESLUTNING — metadata ligger på versjonen**, ikke siden:
`enterprise_wiki_page_versions.submitted_by_user_id`, `submitted_at`, `reviewer_user_id`.

Versjonen er det som vurderes. En side lever gjennom mange versjoner, og en tildeling som overlevde
en regenerering ville pekt på en gjennomgang av innhold som ikke lenger finnes. En egen
tildelingstabell tjener først til noe når én versjon kan bære flere review-runder — omtildeling er
ikke bygget, så den mindre modellen er fortsatt sann.

**BESLUTNING — hvem kan sende inn.** Sideeier, eller System Owner. Ingen ny `edit_wiki_pages`
capability var nødvendig: eierskapet fra steg 2 uttrykker allerede ansvaret. System Owner-adgangen
dekker også de sidene som ikke fikk en entydig eier (2 av 19 lokalt).

**BESLUTNING — hvem kan være kontrollør.** Fire krav, samlet i
`User::canBeEnterpriseWikiReviewerFor()`: samme kunde, aktiv bruker, `approve_wiki_pages`, og ikke
innsenderen selv.

**BESLUTNING — kontrollør velges eksplisitt.** Sideeier oppgir `reviewer_user_id` ved innsending, og
den valideres mot kravene over. Systemet tildeler aldri automatisk og velger aldri «første bruker».
Er lista tom, kan siden ikke sendes videre — det er et riktig svar, ikke en feil: da holder ingen
andre capability-en ennå.

**BESLUTNING — separation of duties håndheves absolutt.** Innsenderen kan ikke godkjenne sin egen
versjon, uansett capability og uansett rolle — **System Owner inkludert**. Overriden deres rekker til
*hvems kø det er*, ikke til å signere eget arbeid.

**Approve/reject respekterer tildelingen.** Er en kontrollør navngitt, kan bare vedkommende handle —
eller en System Owner som trår til i en fastlåst gjennomgang. En versjon uten tildeling faller
tilbake på capability alene, slik at sider fra før denne flyten ikke blir stående fast.

**Innsending rører ikke `published_version_id`.** Å sende nytt arbeid til gjennomgang trekker aldri
tilbake det som allerede er godkjent.

**Allerede tildelt versjon avvises med 409** framfor å bli stille omtildelt. Gjenåpning av en avvist
side nullstiller tildelingen, fordi den returnerer siden til eieren for redigering — det er ingen
overlevering.

**Eksisterende data:** 0 sider i `pending_review`, så ingen tildelinger å backfille og ingenting å
gjette.

**ÅPENT — nød-override for self-approval.** Ikke besluttet, og derfor ikke bygget. Dagens regel er
den sikre: ingen kan godkjenne eget arbeid. Skal det finnes et unntak, må det være eksplisitt og
auditert.

**ÅPENT — omtildeling.** Kan kontrollør byttes mens siden er til gjennomgang, av hvem, og skal
`submitted_by`/`submitted_at` beholdes? Ikke besluttet, ikke bygget.

**ÅPENT — frontend.** `Wiki/Show.jsx` er fortsatt fryst. Payloaden inneholder nå `review_assignment`
med eier, innsender, kontrollør, `can_submit`, `can_review` og `eligible_reviewers`, slik at
UI-steget blir en rendering-oppgave.

### Gjennomført i steg 6 — kildeeierporten

**BESLUTNING — semantikk.** Document-owner approval betyr: *«Jeg bekrefter at innholdet i denne
Wiki-versjonen som stammer fra mine kildedokumenter, er korrekt nok til å inngå i videre
Wiki-review.»* Den er **ikke** publisering, ikke sidegodkjenning og ikke en uttalelse om siden som
helhet — den vurderingen ligger hos den tildelte kontrolløren.

**BESLUTNING — ingen ny status.** `pending_review` dekker hele review-fasen; kildeeierporten er en
separat completion gate inni den. Å innføre en egen `source_owner_review`-status ville doblet
statusmodellen uten å si noe systemet ikke allerede vet fra godkjenningsradene. Spørsmålet om
`changes_requested` er fortsatt åpent (11.10) og hører til steg 8.

**Tre rettigheter, ingen impliserer de andre:**

| Rettighet | Spørsmål den svarer på |
|---|---|
| `approve_wiki_claims` | Er denne ene påstanden støttet av kilden sin? |
| Document-owner approval | Er innholdet fra *mine* dokumenter gjengitt riktig? |
| `approve_wiki_pages` | Skal denne siden publiseres? |

Alle seks kombinasjoner er testet.

**Hvem avgjør et krav.** Eieren av dokumentene i raden, samme kunde, aktiv bruker, ikke-superseded
rad. `EnterpriseWikiDocumentOwnerApprovalService::canDecide()` var allerede korrekt fra før.

**Innsending synker kravsettet.** `submit()` kaller `syncForPageVersion()` for versjonen, slik at de
som spørres er dagens dokumenteiere — ikke den som eide et dokument tidligere. Gaten synker på nytt
ved evaluering, samme mønster som `evaluateRunCompletionGate()`.

**Porten.** `approve()` avvises med **409** — ikke 403 — så lenge én aktiv rad er `pending` eller
`rejected`. Aktøren *har* lov til å reviewe; versjonen er bare ikke klar. Feilmeldingen navngir hvem
det ventes på.

**BESLUTNING — ingen bypass.** En System Owner kan avgjøre en enkelt eiers rad, og det registreres
på raden selv (`is_override`, `overridden_by_user_id`, `overridden_at`) — altså synlig, ikke en
stille omgåelse. Men å hoppe over rader ingen har svart på er aldri besluttet, og er ikke innført.

**Reject blokkerer, men returnerer ikke.** En avvist rad gjør endelig godkjenning umulig.
Sidestatus, tildeling og publisert versjon røres ikke. Full retur-/endringsflyt er steg 8.

**Ingenting publiseres av porten.** Når alle rader er godkjent, er versjonen klar — men kontrolløren
må fortsatt handle. Testet eksplisitt.

**Eksisterende data:** 19 gjeldende versjoner, alle med minst ett aktivt krav, 7 med flere eiere;
14 pending, 12 approved, 0 rejected, 0 superseded. Konsistent — ingen backfill.

### Gjennomført i steg 7 — endelig Wiki-review

**BESLUTNING — semantikk.** Endelig review er *publiseringsbeslutningen*: den tildelte kontrolløren
vurderer hele arbeidsversjonen og avgjør om den skal bli den publiserte. Den er ikke claim approval,
ikke dokumenteiergodkjenning, og ikke bare en statusendring.

**Forutsetninger, håndhevet i denne rekkefølgen** — valgt slik et menneske ville spurt, og gjenbrukt
både av endepunktet og av `final_approval_blocker` i payloaden, så skjerm og API ikke kan si
forskjellige ting:

1. siden er `pending_review` — ellers 422 («allerede avgjort» / «ikke sendt til gjennomgang»)
2. brukeren har `approve_wiki_pages` — ellers 403
3. brukeren er ikke innsenderen — ellers 403
4. brukeren er tildelt kontrollør, eller System Owner som trår inn — ellers 403
5. alle aktive dokumenteierkrav er godkjent — ellers 409

**KRITISK INVARIANT — en godkjent side navngir alltid det som ble godkjent.** `status` og
`published_version_id` skrives i samme operasjon. Finnes ingen arbeidsversjon, avbrytes hele
godkjenningen framfor å publisere ingenting.

**Samtidighet.** Alle forutsetninger revalideres inne i transaksjonen etter `lockForUpdate()` på
siderad-en. Låsen serialiserer to samtidige forsøk: den første committer, den andre leser en side som
ikke lenger er `pending_review` og får 422. `reviewed_by_user_id` tilhører dermed alltid den som
faktisk vant.

**Historikk bevares.** `submitted_by_user_id`, `submitted_at` og `reviewer_user_id` nullstilles ikke
ved godkjenning — de er journalen over hvem som overleverte versjonen og til hvem, og den er verdt
mer etter beslutningen enn før. Den forrige publiserte versjonen slettes ikke; siden slutter bare å
peke på den.

**Samtidig publisert og under vurdering er en gyldig tilstand:** `page.status = pending_review` mens
`published_version_id` peker på V1. Ved godkjenning av V2 flyttes publiseringen. Testet begge veier.

**System Owner takeover** virker som besluttet i steg 5 — de kan tre inn i en annens tildeling, men
aldri godkjenne noe de selv har sendt inn, og aldri hoppe over dokumenteierporten.
`reviewed_by_user_id` viser den faktiske beslutningstakeren. Det finnes ikke noe eget felt som
skiller en takeover fra en ordinær godkjenning — **ÅPENT** om det trengs; ingen ny auditmodell er
bygget nå.

**Reject** er uendret ut over å ha fått samme lås og revalidering. Publisert versjon røres ikke.
Full retur-/endringsflyt er steg 8.

**BESLUTNING — ingest ender alltid i `draft`.** En fullført kjøring produserer arbeid, ikke en
forespørsel om gjennomgang. `FinalizeEnterpriseWikiIngest` satte tidligere `pending_review` direkte,
noe som ga sider som ventet på ingen. Den setter nå `draft`.

**KRITISK INVARIANT — `pending_review` innebærer alltid en reell tildeling.** `submit()` er den
eneste veien inn i statusen, og den skriver alltid `submitted_by_user_id`, `submitted_at` og
`reviewer_user_id`.

Fallbacken fra steg 5 — «uten tildeling avgjør capability alene» — er derfor **fjernet**. En versjon
uten tildeling kan ikke behandles av noen, heller ikke System Owner: den er ødelagt tilstand, og det
finnes ingen ærlig måte å utpeke en kontrollør i ettertid. Både approve og reject svarer **409** med
beskjed om å gjenåpne siden og sende den inn på nytt.

Eksisterende data er ikke backfillet og ingen kontrollør er gjettet. Lokalt finnes **0** sider i
`pending_review`, så ingen legacy-rader er berørt. Skulle slike finnes i et annet miljø, må de
håndteres manuelt ved å gjenåpne og sende inn på nytt.

### Gjennomført i steg 8 — retur til sideeier

**FAKTA som avgjorde modellen: innhold muteres aldri.** `EnterpriseWikiPageVersionWriter` oppretter
alltid en ny versjon, og manuell blokkredigering *stager* en ny versjon før den promoteres. En
versjon som har vært til gjennomgang endrer derfor aldri innhold. Det gjør gamle
dokumenteiergodkjenninger entydige: de gjelder et innhold som ikke kan ha endret seg.

**BESLUTNING — `rejected` beholdes teknisk, med semantikken «endringer kreves».** Å innføre
`changes_requested` som ny enumverdi ville krevd endringer i statuslister, i katalogen og i
`Wiki/Show.jsx` sitt `PAGE_STATUS_STYLES` — en fryst fil, så statusen ville rendret uten stil. `rejected`
betyr allerede «sendt tilbake, må rettes», og flyten er `rejected` → gjenåpne → `draft` → ny
innsending. Dette lukker åpent spørsmål 11.10.

**BESLUTNING — begrunnelse er obligatorisk, og lagres i egen tabell.**
`enterprise_wiki_page_review_events`: side, versjon, aktør, aktørrolle, hendelsestype, begrunnelse,
tidspunkt. Append-only — ingenting oppdaterer eller sletter radene.

Grunnen til egen tabell framfor felt på siden eller på godkjenningsraden: begge holder *én*
beslutning. En side som går tre runder har tre begrunnelser verdt å lese, og å overskrive de
tidligere mister argumentet arbeidet svarte på.

Krav til begrunnelsen: obligatorisk, trimmet, 10–2000 tegn. Manglende eller tom gir **422**.

**To aktører, to slags innvending:**

| Aktør | Gjelder | Handling |
|---|---|---|
| Tildelt kontrollør | siden som helhet | `reject` med `reason` → side `rejected` |
| Dokumenteier | innhold fra egne kilder | avvis krav med `comment` → side `rejected` |

**BESLUTNING — dokumenteiers avvisning sender versjonen tilbake**, den parkerer den ikke i
gjennomgang. Å la siden stå i `pending_review` ville vært misvisende: den venter ikke på noen, den
venter på at sideeier svarer på en innvending. Godkjenning av et krav krever fortsatt ingen
begrunnelse — det er bare avvisning som må forklares.

**Andre eieres ventende krav røres ikke ved retur.** De tilhører denne versjonen. Sendes den inn på
nytt uendret, står de fortsatt; redigerer eieren, blir det en ny versjon og kravsettet utledes på
nytt fra bunnen. Det siste er testet.

**Resubmit.** Gjenåpning nullstiller runden sin tildeling, og ny innsending krever at kontrollør
oppgis eksplisitt på nytt — den forrige gjenbrukes ikke stille. Forhåndsutfylling er i så fall et
UI-valg, ikke en backend-default.

**Publisert versjon er urørt av enhver retur.** V1 publisert, V2 sendt tilbake → `published_version_id`
peker fortsatt på V1. Først når en senere runde godkjennes flyttes den.

**Payload:** `review_assignment.changes_requested` med `is_returned`, `latest` og `history` — alle
runder, nyeste først, med aktør, rolle, begrunnelse og tidspunkt, og `applies_to_working_version` per
hendelse. Historikken er sidebasert, ikke versjonsbasert: etter en retur redigerer eieren, som gir en
*ny* versjon, så en versjonsbasert liste ville vært tom nettopp når eieren trenger å lese hva de ble
bedt om å rette.

### Gjennomført i steg 9 — varsling

**FAKTA — Procynia har varsler, ikke oppgaver.** `UserNotification` (kundescopet, med `event_type`,
`severity`, `target_url`, `is_read`, `metadata`) rendres av `NotificationBell` via
`UserNotificationService::panelPayload()`. Mønsteret er å opprette radene direkte fra en service,
pakket i `rescue()`. Det finnes **ingen** generell task-modell —
`RequirementResponsibilityTaskService` er saksspesifikk. Ingen ny generell arkitektur ble bygget.

**BESLUTNING — varsler eier ingen workflow-state.** `reviewer_user_id` bestemmer hvem som
kontrollerer, godkjenningsradene bestemmer hva som gjenstår, og review-hendelsene holder begrunnelsen.
Slett alle varsler, og arbeidsflyten er uendret. Testet eksplisitt.

**BESLUTNING — notification, ikke task.** Siden produktet bare har varsler, brukes de. Arbeidslisten
kan senere utledes fra domenetabellene, som allerede holder «hva venter på meg».

| Hendelse | Mottaker | Nøkkel | Faller bort når |
|---|---|---|---|
| Innsending | tildelt kontrollør | versjon + bruker | leses; ansvaret følger `reviewer_user_id` |
| Innsending | hver dokumenteier med ventende krav | krav-id + bruker | kravet avgjøres eller supersedes |
| Porten åpnet | tildelt kontrollør | versjon + bruker | leses |
| Endringer kreves | sideeier | review-hendelse + bruker | ny runde gir ny hendelse |
| Publisert | sideeier, og innsender når det er en annen | versjon + bruker | leses |

**Ordlyd:** kontrolløren får «Du er tildelt som kontrollør», *ikke* «klar til godkjenning» —
kildeeierporten kan fortsatt være lukket, og å love en beslutning de ikke kan ta ville sendt dem til
en blokkert side. «Klar for endelig gjennomgang» sendes først når porten faktisk åpner.

**Én eier, ett varsel.** Godkjenningsraden bærer allerede alle dokumentene eieren svarer for, så en
eier med fire kilder spørres én gang. Superseded, godkjente og avviste rader varsles ikke.

**Idempotens:** ny nullable, unik `user_notifications.dedupe_key`. Alle skrivinger går gjennom
`firstOrCreate` på den nøkkelen, så en gjenkjørt jobb, en dobbeltklikk eller en ny
`syncForPageVersion()` ikke kan varsle to ganger. Nøkkelen er bygget av type + domene-id + mottaker,
aldri av tekst. Kolonnen er nullable, så eksisterende varseltyper er upåvirket.

**After commit:** alt sendes via `DB::afterCommit()`. En beslutning som rulles tilbake annonseres
aldri. Testet begge veier.

**Ingen selvvarsling.** Den som utfører handlingen varsles ikke om den. Skillet er «du gjorde noe»
mot «du har fått et ansvar» — det siste varsles.

**Kundeisolasjon** håndheves i selve skriveren: mottakeren må være aktiv og tilhøre sidens kunde. Et
varsel er en utlevering, så dette er en grense, ikke en bekvemmelighetssjekk.

**E-post er ikke innført.** Mail brukes i dag kun til fakturering, kvote og digest. Steg 9 holder seg
til in-app, i tråd med eksisterende mønster.

**Ingen backfill.** Varsler er hendelser; gamle hendelser sendes ikke i ettertid. Lokalt fantes
**0** varselrader, så ingenting å migrere.

**ÅPENT — sideeier mangler.** Har en side ingen eier (2 av 19 lokalt), sendes ingen
«endringer kreves». Å gjette en mottaker ville satt en annens navn på arbeid de aldri påtok seg. Ingen
eksisterende produktregel sier at System Owner mottar slike foreldreløse oppgaver.

### Gjennomført i steg 10 — retrieval leser publisert kunnskap

**BESLUTNING — én regel.** En side er tilgjengelig for autoritativ retrieval når den navngir en
publisert versjon (`enterprise_wiki_pages.published_version_id`), versjonen finnes og faktisk
tilhører siden, og innholdet ikke er tomt. Innholdet leses fra den versjonen — aldri fra
`is_current`, nyeste versjonsnummer eller arbeidsversjonen.

**Sidestatus er ikke lenger en del av regelen.** Status beskriver hva som skjer med
*arbeidsversjonen*. En side kan være `pending_review` eller `rejected` mens en tidligere godkjent
versjon fortsatt svarer på spørsmål. Bare `archived` og `superseded` holdes ute — de er pensjonert,
ikke under revisjon.

**Dokumenteierporten er en publiseringsport, ikke en retrieval-port.** Sign-off er det som tillater
at en versjon publiseres. Når publiseringen først har skjedd, står beslutningen: ventende
godkjenninger på en *ny* arbeidsversjon trekker ikke tilbake kunnskap som allerede er godkjent, og
et eierskifte i ettertid gjør det heller ikke.

**Dette rettet en reell lekkasje.** `RequirementWikiPageRanker` og `RequirementWikiResearchService`
leste claims fra arbeidsversjonen, så en påstand ingen hadde godkjent kunne både rangere sider og
siteres som evidens. Begge leser nå kun claims som tilhører den publiserte versjonen.

**Fail-closed.** Peker `published_version_id` på en versjon som tilhører en annen side, utelates
siden. Slettes den publiserte versjonen, nullstiller fremmednøkkelen (`nullOnDelete`) pekeren og
siden faller ut. Det faller **aldri** tilbake til arbeidsversjonen.

**API-et er strammet inn.** `RequirementWikiCatalogBuilder::build()` tar nå bare `customerId` —
`$statuses` og `$requireCurrentVersionApproval` er fjernet, så «utvid statusene og se hva som kommer»
ikke lenger lar seg uttrykke. `CURRENT_KNOWLEDGE_STATUSES` er borte.

**Spør Wiki og anbudssvar deler nå én regel.** Tidligere kunne Spør Wiki grunne svar i en utkastside
leseren hadde lov til å åpne. Å lese ugodkjent innhold er én ting; å la AI-en presentere det som
dokumentert faktum er en annen. Brukerens synlige statuser styrer fortsatt om hen får *spørre*.

**Ingen cache eller index.** Katalogen bygges direkte fra tabellene ved hvert kall, så det finnes
ingen indeks der ikke-publisert tekst kan ligge igjen. Ingen embeddings i denne kjeden.
`publishedVersion` eager-lastes, som `currentVersion` var før — ingen N+1.

**Eksisterende data:** 19 sider, 1 publisert, 18 uten. Ingen anomalier: ingen `approved` uten
publisert versjon, ingen peker på feil sides versjon. Ingen dataendring var nødvendig.

**ÅPENT — `edit_wiki_pages`** er ikke innført. En bredere redigeringsrettighet enn eierskap er ikke
besluttet.

---

## 11. ÅPNE SPØRSMÅL

Ingen av disse er besluttet. Ikke velg svar underveis i implementeringen uten å oppdatere dette
dokumentet.

1. Må **alle** document owners godkjenne før Wiki-review kan starte?
2. Skal source-owner review være **sekvensiell** eller **parallell**?
3. Hvordan velges Wiki-godkjenner?
4. Fast reviewer per kunde, eller per side?
5. Kan Wiki-sideeier også være source owner på samme side? (Sannsynlig i praksis — hva betyr det for
   «ikke godkjenne egen versjon»?)
6. Hvordan håndteres fravær eller bytte av eier?
7. ~~Hvilken notification/task-mekanisme skal brukes?~~ — **besluttet i steg 9**: eksisterende
   `UserNotification` (in-app). Ingen task-modell finnes, og ingen ble bygget.
8. Hvordan håndteres eksisterende sider med `owner_user_id = NULL`? (I dag: alle.)
9. Hvordan migreres dagens `is_current`-semantikk til skillet arbeidsversjon/publisert versjon?
10. ~~Hvordan skal eksisterende `rejected`-status behandles~~ — **besluttet i steg 8**: `rejected`
    beholdes teknisk og leses som «endringer kreves». Se 10.
11. ~~Hvilke Wiki-sider skal inngå i Spør Wiki mens review pågår?~~ — **besluttet i steg 10**: de
    som har en publisert versjon. Sidestatus styrer ikke retrieval.

---

## 12. Kritiske invarianter

Disse skal aldri brytes. Brudd er en regresjon, uansett hvor praktisk det måtte være.

1. Ingen draft- eller review-versjon skal publiseres før approval.
2. Eksisterende godkjent versjon forblir gjeldende mens ny versjon vurderes.
3. Én Wiki-side har én helhetlig sideeier.
4. Flere kildedokumenteiere kan ha ansvar på samme versjon.
5. Senere kilder endrer ikke sideeier automatisk.
6. Dokumenteierskap og sideeierskap er forskjellige begreper.
7. Endelig Wiki-godkjenning er separat fra source-owner approval.
8. System Owner er ikke normal workflow-mottaker.
9. AI-proveniens er ikke workflow-status.
10. Spør Wiki skal kun bruke godkjent/publisert kunnskap.
11. Alle review- og eierskapsendringer skal kunne auditeres.

**Merk:** invariant 10 er innfridd i steg 10 (6). Invariant 1 og 2 er innfridd i steg 3 (6). Invariant 3, 5 og 6 er innfridd i steg 2 (3.1).
Invariant 7 og 8 er innfridd i steg 4 (10) på backend — frontend-porten gjenstår, se ÅPENT i 10.

---

## 13. Implementeringsrekkefølge

Rekkefølgen er valgt slik at hvert steg står på et verifisert fundament. Særlig gjelder at steg 1 må
være ferdig før approval-mekanismen brukes som port (jf. 9), og at steg 3 må være ferdig før
review-løypen bygges — ellers bygges kontroller rundt innhold som allerede er publisert.

1. ~~Verifisere og rette document-owner approval sync~~ — **gjennomført**, se 9
2. ~~Definere og backfille Wiki-sideeier~~ — **gjennomført**, se 3.1
3. ~~Rette versjons-/current-/published-modellen~~ — **gjennomført**, se 6
4. ~~Definere Wiki review capability~~ — **gjennomført**, se 10
5. ~~Modellere reviewer- og submit-metadata~~ — **gjennomført**, se 10
6. ~~Implementere source-owner review~~ — **gjennomført**, se 10
7. ~~Implementere endelig Wiki-review~~ — **gjennomført**, se 10
8. ~~Implementere retur / endringskrav~~ — **gjennomført**, se 10
9. ~~Koble notification-/task-mekanisme~~ — **gjennomført**, se 10
10. ~~Begrense Spør Wiki og retrieval til approved/published~~ — **gjennomført**, se 6
11. ~~Oppdatere Wiki UI~~ — **gjennomført**
12. ~~Oppdatere PageHelp~~ — **gjennomført**: hjelpen beskriver nå eierskaps-, gjennomgangs- og
    publiseringsflyten, dokumenterer kun Wiki-sidevisningen, og den gamle teksten om «System Owner
    only», «ingen mottaker» og «ingen varsling» er fjernet
13. ~~Migrere og backfille eksisterende data~~ — **gjennomført**, se 14 — gjelder sideeier (steg 2) og `is_current`-semantikken
    (steg 3). **Document-owner approvals er eksplisitt unntatt**: de backfilles ikke, se 9.
14. ~~Full regresjons- og sikkerhetstest~~ — **gjennomført**, se 15


---

## 14. Datakontroll og utrulling (steg 13)

**Ingen data ble endret.** Kontrollen fant ingen alvorlige avvik, og alt som gjensto var enten
allerede riktig eller bevisst tomt.

**Kontrollert:** sider og statuser, sideeierskap, publiseringspeker og relasjonen dens,
arbeidsversjoner (én current per side, ingen manglende), review-metadata, dokumenteiergodkjenninger,
review-hendelser, varsler, kundegrenser og migrasjonsstatus.

**Backfill av sideeier:** kjørt som dry-run. **0 kan løses entydig**, 2 mangler `created`-rad — de
samme sidene (205, 208) som i steg 2. De forblir uten eier; å utlede en fra en senere
`updated`-kjøring ville gitt siden til den som sist beriket den.

**Publisering:** 1 side publisert, peker på egen side, ingen `approved` uten peker. Ingen flytting.

**Eksplisitt IKKE backfillet:** dokumenteiergodkjenninger, i tråd med beslutningen i 9. Kontrollen
fant 24 aktive rader på ikke-gjeldende versjoner og 7 aktive rader på gjeldende versjoner der eieren
ikke lenger eier de siterte dokumentene. Radene på ikke-gjeldende versjoner er inerte — porten leser
bare gjeldende versjon. De 7 rettes av neste ordinære sync. Ingen global reconciliation ble kjørt.

Heller ikke backfillet: varsler (hendelser sendes ikke i ettertid), review-hendelser (append-only),
review-tildelinger (kan ikke gjettes).

**`php artisan enterprise-wiki:audit-approval-model`** er lagt til: read-only, `--customer=`, og
exit-kode ≠ 0 ved alvorlige avvik, slik at en utrulling kan stoppe på den. Den reparerer ingenting —
hvert avvik den finner krever enten et menneske som vet hva riktig verdi var, eller et produktvalg.

**Utrullingsrekkefølge for et eksisterende miljø**

1. `php artisan migrate`
2. `php artisan enterprise-wiki:audit-approval-model` — utgangspunkt før endring
3. `php artisan enterprise-wiki:backfill-page-owners --dry-run` — les rapporten
4. `php artisan enterprise-wiki:backfill-page-owners` kun hvis «resolvable» > 0
5. `php artisan enterprise-wiki:audit-approval-model` — verifiser
6. Behandle eventuelle alvorlige avvik manuelt (se under)
7. Røyktest av Spør Wiki: en side med publisert versjon skal svare; en uten skal ikke

**Manuell håndtering av legacy-tilstander**

| Avvik | Handling |
|---|---|
| `pending_review` uten tildeling | Gjenåpne til utkast og send inn på nytt med navngitt kontrollør |
| `approved` uten publisert versjon | Avgjør hvilken versjon som faktisk ble godkjent; ikke anta gjeldende |
| Peker til feil sides versjon | Datakorrupsjon — krever undersøkelse |
| Side uten eier | Sett eier eksplisitt, eller la stå

---

## 15. Regresjons- og sikkerhetsverifisering (steg 14)

**Konklusjon: ingen regresjon innført av steg 1–13.**

**Godkjenningsmodellen isolert:** 13 suiter, **197 passed, 0 failed**. Dekker separasjon av plikter,
kundegrenser (eier, kontrollør, kildeeier, hendelsesaktør, varselmottaker, retrieval, Spør Wiki),
låsing og revalidering ved samtidig godkjenning, idempotent varsling, og fail-closed retrieval.

**Full suite, sammenlignet mot faktisk baseline** (`81c4fb7`, før steg 1) med identisk kommando:

| | Baseline `81c4fb7` | HEAD `d17430b` |
|---|---|---|
| Failed | 98 | 150 |
| Passed | 5331 | 5468 |
| Assertions | 25366 | 26271 |

Tallene alene er ikke bevis: to kjøringer av **samme** HEAD-kode ga 98 og 150. Suiten er
rekkefølge-avhengig, så **feilmengdene** ble diffet, ikke tallene.

- Baselinens 96 unike feilende testnavn er en **ekte delmengde** av HEADs 148. Ingen feil forsvant —
  det utelukker at endringene selektivt flyttet på noe.
- De 52 «nye» navnene er **null** innenfor godkjenningsmodellen; alle ligger i kunnskapsbase-,
  metadata- og chunk-løypene, som ikke er rørt.
- Tre av dem kjørt alene på HEAD: `KnowledgeChunkMetadataValidatorTest` 8 passed,
  `MetadataCandidateRetrievalServiceTest` 19 passed, `KnowledgeMetadataMapServiceTest` 8 passed.
  **Klassifisering C** — forurensning i samlet kjøring, ikke regresjon.
- Wiki-suiter som feiler også alene ble kjørt på pre-steg-1-koden og feilet **identisk**:
  `EnterpriseWikiLineageTest` 1 failed/7 passed, `EnterpriseWikiTableIngestTest` 2 failed/2 passed,
  `EnterpriseWikiClaimStepLeaseTest` 4 failed/7 passed. **Klassifisering B** — pre-eksisterende, med
  baseline-bevis. `EnterpriseWikiLineageTest` bryter `ewpv_page_single_current_unique` fra migrasjon
  `2026_08_07_000001`, som er eldre enn dette arbeidet.

Ingen failure ble klassifisert A (ny regresjon) eller D (fixture med gammel modell).

**Øvrig verifisert:** alle migrasjoner kjørt, 0 ventende, skjema komplett;
`enterprise-wiki:audit-approval-model` exit 0 med 0 alvorlige avvik; JS-guards 48 passed;
`npm run build` OK; Pint ren på berørte filer; `git diff --check` ren.

**Kjent, ikke-blokkerende:** den samlede suiten har en rekkefølge-avhengig forurensning som er eldre
enn dette arbeidet (98 feilende ved baseline). Den bør ryddes som eget arbeid — den skjuler ekte feil
for alle som leser sluttsummen i stedet for å diffe mengdene.

---

## Referanser

| Tema | Fil |
|---|---|
| Sideopprettelse (article/summary) | `app/Jobs/Ai/Wiki/ProcessEnterpriseWikiIngest.php:111` |
| Sidegjenbruk (concept/entity) | `app/Services/EnterpriseWiki/EnterpriseWikiMaintainerDecisionApplyService.php:269` |
| Versjon blir current + pending_review | `app/Jobs/Ai/Wiki/FinalizeEnterpriseWikiIngest.php:179` |
| submit / approve / reject | `app/Http/Controllers/App/WikiController.php:2247, 2281, 2312` |
| Statusverdier | `app/Models/EnterpriseWikiPage.php:12-30` |
| Sideeier (ubrukt) | `app/Models/EnterpriseWikiPage.php:79, 101` |
| Dokumenteier | `app/Models/EnterpriseWikiDocument.php:25, 54` |
| Document owner approvals | `app/Models/EnterpriseWikiPageVersionDocumentOwnerApproval.php` |
| Provenance-oppslag | `app/Services/EnterpriseWiki/EnterpriseWikiDocumentOwnerApprovalService.php:382, 465` |
| Statusvisibilitet | `app/Models/User.php:389` |
| Permission-mekanisme | `app/Models/Customer.php:14-31` |

Relatert dokumentasjon: `docs/enterprise-wiki-architecture.md` (autoritativ Wiki-arkitektur),
`AGENTS.md` (bindende invarianter).
