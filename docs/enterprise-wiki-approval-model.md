# Enterprise Wiki — godkjenningsmodell

> **Status: Beslutningsgrunnlag – ikke fullt implementert**

Dette dokumentet beskriver **målmodellen**. Store deler av den finnes ikke i koden ennå. Der dokumentet
beskriver dagens kode er det merket **FAKTA** med filreferanse. Alt annet er **BESLUTNING** (vedtatt
retning) eller **ÅPENT** (ikke besluttet — ikke improviser).

Ved konflikt mellom dette dokumentet og eldre samtaler eller antakelser: dette dokumentet gjelder,
inntil det er erstattet av implementert kode og oppdatert arkitekturdokumentasjon.

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

Dette står i direkte motstrid til dagens oppførsel (2.8) og krever at `is_current`-semantikken
splittes: «den nyeste arbeidsversjonen» og «den publiserte versjonen» er to forskjellige begreper som
i dag deler ett flagg.

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

**ÅPENT — navngivning.** Forslaget i oppdraget var `wiki.edit` / `wiki.review` / `wiki.manage`.
Husets konvensjon er flat snake_case (`approve_wiki_claims`). Antagelig `approve_wiki_pages` og
`edit_wiki_pages`, men navnene er ikke besluttet. Merk at `approve_wiki_claims` (claim-nivå) og en ny
side-nivå-permission er forskjellige ting og må ikke slås sammen.

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
7. Hvilken notification/task-mekanisme skal brukes? (Ingen finnes for Wiki i dag.)
8. Hvordan håndteres eksisterende sider med `owner_user_id = NULL`? (I dag: alle.)
9. Hvordan migreres dagens `is_current`-semantikk til skillet arbeidsversjon/publisert versjon?
10. Hvordan skal eksisterende `rejected`-status behandles i den nye modellen — blir den
    `changes_requested`, eller beholdes begge?
11. Hvilke Wiki-sider skal inngå i Spør Wiki mens review pågår?

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

**Merk:** invariant 1, 2 og 10 er brutt i dag (2.8). Invariant 8 er brutt i dag (2.7). Invariant 3,
5 og 6 er innfridd i steg 2 (3.1).

---

## 13. Implementeringsrekkefølge

Rekkefølgen er valgt slik at hvert steg står på et verifisert fundament. Særlig gjelder at steg 1 må
være ferdig før approval-mekanismen brukes som port (jf. 9), og at steg 3 må være ferdig før
review-løypen bygges — ellers bygges kontroller rundt innhold som allerede er publisert.

1. ~~Verifisere og rette document-owner approval sync~~ — **gjennomført**, se 9
2. ~~Definere og backfille Wiki-sideeier~~ — **gjennomført**, se 3.1
3. Rette versjons-/current-/published-modellen
4. Definere Wiki review capability
5. Modellere reviewer- og submit-metadata
6. Implementere source-owner review
7. Implementere endelig Wiki-review
8. Implementere retur / endringskrav
9. Koble notification-/task-mekanisme
10. Begrense Spør Wiki og retrieval til approved/published
11. Oppdatere Wiki UI
12. Oppdatere PageHelp
13. Migrere og backfille eksisterende data — gjelder sideeier (steg 2) og `is_current`-semantikken
    (steg 3). **Document-owner approvals er eksplisitt unntatt**: de backfilles ikke, se 9.
14. Full regresjons- og sikkerhetstest

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
