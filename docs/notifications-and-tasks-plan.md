# Varsler og «Mine oppgaver» — plan og arkitektur (punkt 6)

Status: **Fase 6A–6C merget til lokal `main`** (`feat/my-tasks-suppliers`, `7e1195bd`, merget med
`--no-ff` i `6c8a9641`), ikke pushet. **Punkt 6 er ikke fullført:** fase 6D (øvrige styringsmoduler),
automatiske fristpåminnelser (§8) og eventuelle senere forbedringer av oppgaveoversikten gjenstår.

Dette dokumentet er kontrakten for hvordan styringsmodulene kobles til Procynias felles varselbjelle
og «Mine oppgaver». Leverandører er første styringsmodul som bruker begge.

---

## 1. To spørsmål, to flater

| Flate | Spørsmål | Blir stille når | Lagres som |
|---|---|---|---|
| **Varselbjellen** | Hva har skjedd som jeg bør vite om? | Varselet er lest | `user_notifications`-rad |
| **Mine oppgaver** (Oppfølging) | Hva må jeg gjøre, og når? | Arbeidet er gjort i modulen | Ingenting — leses live fra modulen |

Et lest eller slettet varsel endrer aldri en oppgave. En oppgave forsvinner bare når modulens egne data
sier at arbeidet er gjort, og flytter seg når modulens egen ansvarsfelt flytter seg.

## 2. Godkjente arkitekturbeslutninger

1. **Ingen sentral oppgavetabell.** Oppgaver hentes fra modulenes egne tabeller og tjenester ved hver
   lesing.
2. **Én felles oppgavekontrakt:** `MyTaskSource` (grensesnitt), `MyTask` (normalisert oppgave) og
   `MyTasksService` (samler, sorterer, grupperer) i `app/Services/MyTasks/`.
3. **Domenelogikken blir i modulene.** Kilden spør modulens egne tjenester; den felles koden avgjør
   aldri om noe trenger oppfølging.
4. **Varsler og oppgaver er forskjellige** (§1).
5. **Eksisterende bjelle beholdes.** Nye moduler skriver `user_notifications` gjennom
   `UserNotificationWriter`.
6. **«Mine oppgaver» er standardvisningen** på eksisterende Oppfølging-side (`/app/info-center`) for
   alle personas. Ingen ny side.
7. **Modulens egne fristregler gjenbrukes.** Ingen generell 30-dagersgrense; Leverandørers 60 dager
   for dokumentasjon (`SupplierDocument::EXPIRING_SOON_DAYS`) gjelder.
8. **Tilgang håndheves ved lesing** — både for oppgaver og varsler (§5).
9. **Ingen ny køinfrastruktur.** Varsler skrives synkront etter commit (`DB::afterCommit`).
10. **Ingen e-post** i denne leveransen.

## 3. Eksisterende infrastruktur som gjenbrukes

| Komponent | Rolle |
|---|---|
| `user_notifications`, `UserNotificationService`, `UserNotificationController`, `NotificationBell.jsx` | Bjellen: liste, ulest-teller, les/slett, polling. Uendret oppførsel |
| `BidWorkflowNotificationService`, `EnterpriseWikiReviewNotificationService` | Anbud- og Wiki-varsler. Skriver nå gjennom `UserNotificationWriter` |
| `EnterpriseWikiReviewTaskService`, `EnterpriseWikiQaTaskService` | Wiki-oppgaver. Regler uendret, nå bak `WikiTaskSource` |
| `SavedNoticeInfoItem` + `RequirementResponsibilityTaskService` | Anbud-aksjoner. Uendret, nå bak `TenderTaskSource` |
| `SupplierAttentionService` («Trenger oppmerksomhet», 11 signaler) | Avgjør om og hvorfor en leverandør trenger oppfølging |
| `SupplierAccessService` | All tilgang i Leverandører, også for oppgaver og varsler |
| `SupplierReviewSchedule`, `SupplierDocument`, `SupplierAssuranceResolver`, `SupplierDueDiligenceService` | Fristene som vises |
| `InfoCenterController`, `InfoCenter/Index.jsx` | Oppfølging-siden, videreutviklet |

## 4. Felles kontrakt

### 4.1 Oppgave (`MyTask`)

| Felt | Innhold |
|---|---|
| `id` | Stabil: modulprefiks + modulens id (`supplier-12`, `wiki-review-34`, `tender-item-56`). Aldri en databasenøkkel |
| `module`, `type` | `tender` / `wiki` / `supplier`, og modulens egen type |
| `title`, `subject_title` | Hva oppgaven gjelder, og objektet |
| `assignee_user_id` | Ansvarlig (alltid den som leser) |
| `due_on`, `overdue` | Tidligste relevante frist; om noe allerede er forfalt |
| `group` | `overdue` / `this_week` / `later` / `no_due` |
| `reasons` | Hvorfor den krever oppfølging, med `due_on`, `overdue`, `can_act` per årsak |
| `can_act` | `false` når personen kan lese objektet, men mangler rettighet for minst én årsak |
| `action_url` | Relativ lenke til modulens vanlige arbeidsflate |

Grupper: forfalt = kilden sier forfalt eller frist før i dag; «Denne uken» = til og med søndag i
inneværende kalenderuke; «Senere» = senere frist; «Uten frist» = ingen dato. Sortering: gruppe, frist,
modul, tittel.

### 4.2 Kilde (`MyTaskSource`)

```php
public function module(): string;
public function openTasksFor(User $user, int $customerId, CarbonImmutable $today): Collection; // of MyTask
```

Kilden returnerer ingenting uten modultilgang og leserettighet, og bare objekter personen kan lese.
`MyTasksService` sjekker i tillegg at brukeren er aktiv og tilhører kunden.

### 4.3 Varselskriving (`UserNotificationWriter`)

Én metode, `notify()`, som håndhever for alle moduler:

- mottaker er aktiv og tilhører kunden varselet gjelder (isolasjonsgrensen);
- aktøren varsles aldri om egen handling;
- `dedupe_key` gjør skrivingen idempotent — modulen bestemmer hva som er «samme situasjon»;
- skrivingen skjer etter commit, så en rollback varsler ingen;
- feil fanges (`rescue`), så en varslingsfeil aldri stopper brukerens handling.

## 5. Sikkerhet

### 5.1 Oppgaver

| Kilde | Krav for å se oppgaven |
|---|---|
| Anbud | Kunden har `tender`-modulen; saken er synlig via `SavedNoticeAccessService`; aksjonen er åpen og personen er ansvarlig |
| Wiki | Kunden har `wiki`-modulen **og** personen har `wiki.view`; tildeling på gjeldende versjon |
| Leverandører | `SupplierAccessService::canReadFromAnotherModule()` (modul + `supplier.view`); leverandøren er i `visibleSuppliers()`; personen er `owner_user_id`; leverandøren er ikke avsluttet |

**Rettet svakhet (6A):** Wiki-oppgaver ble vist ut fra tildeling alene. En bruker som mistet
Wiki-modulen eller `wiki.view` så fortsatt sidetitler i Oppfølging. Begge kreves nå.

### 5.1.1 Oppfølging er modul-uavhengig

Oppfølging (`/app/info-center`) har ingen modulvakt og vises i toppmenyen for alle kunder. «Mine
oppgaver» viser arbeid fra nøyaktig de modulene kunden har (verifisert for: bare Leverandører, bare
Wiki, bare Anbud — som alltid inkluderer Wiki-modulen —, Leverandører + Wiki, alle, ingen;
`InfoCenterModuleMatrixTest`).

De øvrige visningene (Venter på svar, Opprettet av meg, Innkommende) og panelene Beslutninger,
Avklaringer, Venter på svar og Frister innen 7 dager er lister over Anbud-aksjoner
(`SavedNoticeInfoItem`). Uten Anbud-modulen:

- aksjonene vises ikke (de tilhører saker kunden ikke kan åpne);
- siden er «Mine oppgaver» alene: ett panel, én visning, nøytral overskrift uten «aksjoner»;
- en gammel lenke til en annen visning lander på «Mine oppgaver».

Med Anbud er siden som før.

Hjelpeteksten følger samme regel (`infoCenter.tender_available` → `infoCenterHelp.js`). Uten Anbud
forklarer den bare «Mine oppgaver» og omtaler ingen Anbud-visninger, og panelets forklaring er
oversatt og nøytral. Med Anbud forklarer den visningene som før, pluss «Mine oppgaver».

**Kjent, eksisterende avvik (ikke del av punkt 6):** `HandleInertiaRequests::share()` kjører før
`SetCustomerLocale`, så de delte `translations` bygges alltid på standardspråket (`no`), også for en
bruker med engelsk som foretrukket språk. Tekster controlleren selv skriver med `__()` (som den nøytrale
overskriften) følger brukerens språk. Gjelder alle sider og finnes uendret på `main`; bør rettes som
egen oppgave.

### 5.2 Varsler

`UserNotificationAccessScope` filtrerer i SQL ved hver lesing, før begrensning og telling, slik at et
skjult varsel verken vises, telles som ulest, merkes lest av «Merk alle» eller slettes av «Slett
uleste»:

| `event_type` | Krav |
|---|---|
| `bid.*` | `tender`-modul **og** saken (`saved_notice_id`) er synlig for personen. Prefiks alene er ikke nok — saksinnsyn er per person |
| `watch_profile.*` | `tender`-modul (kunngjøringen er felles Doffin-data, profilen er personens egen) |
| `wiki.*` | `wiki`-modul og `wiki.view`. Alle kundens sider er lesbare med `wiki.view`, så ingen sidesjekk trengs |
| `supplier.*` | Modul + `supplier.view`, og `metadata.supplier_id` er i `visibleSuppliers()` — også en slettet leverandørs navn forsvinner |
| Annet (`ai_quota.*`, fakturering) | Konto-nivå, ikke modulobjekter — vises |

Varsler slettes ikke ved tap av tilgang; de vises igjen om tilgangen gjenopprettes.

`target_url` er en vanlig, relativ lenke inn i modulens ruter, som kjører sin egen autorisasjon. Bjellen
gir ingen tilgang (testet: lenken gir 403 etter tap av `supplier.view`).

### 5.3 Kundeisolasjon

Alle kilder og skrivinger er scoped til brukerens `customer_id`; `UserNotificationWriter` nekter
mottakere fra annen kunde; leverandørskjemaet avviser ansvarlig fra annen kunde.

## 6. Fase 6A–6C (denne leveransen)

### 6A — Felles fundament

- `MyTask`, `MyTaskSource`, `MyTasksService`; `TenderTaskSource`, `WikiTaskSource`.
- `InfoItemPayload` trukket ut av `InfoCenterController`, brukt av liste og oppgave.
- `UserNotificationWriter`; Anbud og Wiki delegerer sin skriving dit (oppførsel uendret).
- `UserNotificationAccessScope` i `UserNotificationService::visibleQuery()`.
- N+1 i bjellen rettet: saker lastes med `with()` i stedet for `loadMissing()` per rad.
- Indekser (`2026_10_09_000009_add_my_tasks_indexes`): `suppliers(customer_id, owner_user_id)` og
  `saved_notice_info_items(owner_user_id, status)`. Wiki-kolonnene og bjellen var allerede indeksert.

### 6B — Leverandører i «Mine oppgaver»

- `SupplierTaskSource`: **én oppgave per leverandør** personen er intern ansvarlig for og som har minst
  ett funn i `SupplierAttentionService`. Hvert funn blir en årsak. Ingen nye regler.
- Frist per årsak: neste vurdering, «Gyldig til», kontrollens oppfølgingsdato, neste
  aktsomhetsvurdering. Oppgavens frist er den tidligste.
- Handlingsrettighet per årsak, som modulens egne kontrollere sjekker: kontroll/beslutning/aktsomhet =
  `supplier.assure`; vurdering = `supplier.assess`; dokumentasjon = `supplier.edit` eller
  `supplier.assure`; profil = `supplier.edit`. Mangler den, vises oppgaven med «Mangler rettighet».
- UI: «Mine oppgaver» gruppert Forfalt / Denne uken / Senere / Uten frist; hver modul har sitt kort;
  tellingen samler alle kilder. Aksjonslisten for de andre visningene er uendret.

### 6C — Leverandører i bjellen

Nøyaktig én hendelse: **`supplier.owner_assigned`** — en leverandør får ny intern ansvarlig.

- Ved registrering når ansvarlig er en annen enn den som registrerer.
- Ved endring av ansvarlig (`update`), når ansvarlig faktisk endres.
- Aldri ved lagring uten ansvarsendring, aldri til den som gjør tildelingen, aldri ved rollback, aldri
  til inaktiv bruker eller bruker uten `supplier.view`, aldri på tvers av kunder.
- `dedupe_key` = `supplier.owner_assigned:{supplier}:{ny ansvarlig}:{updated_at}` — samme overlevering
  gir ett varsel; A → B → A gir to.
- Tekst på mottakerens språk (`supplier_management.notifications.*`), kilde «Leverandører» i bjellen,
  lenke til leverandørsiden.

Andre tildelingshendelser ble vurdert: Leverandørmodulens datamodell har ingen andre ansvarsfelt
(`assessed_by_user_id` er forfatterskap, ikke tildeling; ansvarlig for avvik og risiko eies av de
modulene). Ingen nye felt eller prosesser er innført for å kunne varsle.

## 7. Ytelse

- Oppfølging: hver kilde er én batch. Leverandørkilden gjenbruker `findingsForSuppliers()`, som leser
  vurderinger, dokumenter, profiler og kontrollstatus én gang for alle personens leverandører.
- Bjellen: konstant antall spørringer uansett antall varsler (testet: 2 og 8 varsler gir like mange
  spørringer). Tilgangsfilteret legger til noen få spørringer per lesing (moduler, roller), ikke per rad.
- Oppgavelisten er ikke paginert; antallet er begrenset til det som faktisk er tildelt personen.

## 8. Utsatt: fristvarsling (senere leveranse)

Ikke bygget nå. Anbefalt form når den bygges:

- Én daglig planlagt kommando per kunde (samme mønster som `BidWorkflowNotificationService::sweepCustomer()`),
  ingen ny kø.
- Den leser **samme kilde** som «Mine oppgaver» (`SupplierTaskSource` / `SupplierAttentionService`),
  så varsel og oppgave aldri er uenige.
- `dedupe_key` koder **situasjonen**, ikke sjekken: `supplier.deadline:{supplier}:{årsak}:{objekt}:{frist}:{mottaker}`.
  En uendret situasjon gir ingen nye varsler; en flyttet frist eller nytt dokument gir ett nytt.
- Terskler fra modulen: varsle når en årsak går fra «utløper snart» (60 dager) og når den blir forfalt
  — maks to varsler per årsak, ikke daglig.
- Én samlet melding per leverandør per kjøring når flere årsaker endrer seg samtidig, for å unngå støy.
- Mottaker = intern ansvarlig, med samme tilgangskrav som oppgaven.

## 9. Fase 6D: øvrige styringsmodulene (senere)

For hver modul (Avvik, Risiko, Etterlevelse, Mål og KPI, Kvalitet):

1. Lag `XxxTaskSource implements MyTaskSource` som leser modulens eget ansvarsfelt og egne
   «Trenger oppmerksomhet»-regler, med modulens access-service først.
2. Legg kilden til i `MyTasksService`-konstruktøren.
3. Skriv varsler gjennom `UserNotificationWriter` med prefiks `<modul>.`.
4. Legg prefikset og regelen (modul + leserettighet + objektsjekk der innsyn er per objekt, f.eks.
   Risiko-fagområder) til i `UserNotificationAccessScope`.
5. Legg kort og etiketter i `MyTasks.jsx` / `info_center_page.my_tasks.modules`.
6. Indekser kun ansvarsfeltene modulen faktisk leser.

## 10. Tester

| Testsett | Dekker |
|---|---|
| `tests/Unit/MyTasks/MyTaskTest.php` | Gruppering, uke slutter søndag, detaljer kan ikke overstyre felles felt |
| `tests/Unit/MyTasks/MyTasksTranslationsTest.php` | Samme nøkler på norsk og engelsk; alle nøkler koden ber om finnes |
| `tests/Feature/App/InfoCenterModuleMatrixTest.php` | Seks modulkonfigurasjoner: oppgaver, telling, visninger, bjelle og ingen titler fra moduler kunden ikke har; hjelpetekst og gamle lenker per konfigurasjon, norsk og engelsk |
| `resources/js/Pages/App/InfoCenter/infoCenterHelp.test.js` | Hjelpetekst med og uten Anbud, hvilke oversettelsesnøkler hver variant leser |
| `tests/Feature/App/MyTasksAccessTest.php` | 6A: Wiki-oppgave skjules uten `wiki.view`/modul; Anbud-aksjon krever modul; bjellefilter for Wiki/sak/konto; «Merk alle» når ikke skjulte; N+1 |
| `tests/Feature/App/InfoCenterSupplierTaskTest.php` | 6B: samlet oppgave, grupper, oppdatering, løst, omfordeling, tilgang, modul, kundeisolasjon, deaktivert bruker, årsaker = `SupplierAttentionService`-funn, handlingsrettighet per årsak og live, backend avviser fortsatt, konstant antall spørringer, tom tilstand |
| `tests/Feature/App/SupplierOwnerNotificationTest.php` | 6C: første tildeling, omfordeling, uendret, egen tildeling, duplikater, rollback, inaktiv, kryss-kunde, tap av tilgang, slettet leverandør, les/slett påvirker ikke oppgaven, navigasjon |
| Eksisterende Wiki-, Anbud- og bjelletester | Regresjon (oppdatert til ny standardvisning og `my_tasks`-payload) |
| `resources/js/Pages/App/InfoCenter/myTaskLabels.test.js` m.fl. | Grupper, årsakstekst, kort per modul, rettighetsmerking |
