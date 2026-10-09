# Varsler og «Mine oppgaver» — arkitektur (punkt 6)

Status: **Punkt 6 er teknisk fullført og merget til lokal `main`.** Fase 6A–6C i `6c8a9641`; fase 6D,
tildelingsvarsler for styringsmodulene, fristpåminnelser og språkrettingen i `c1ee14df`
(`feat/punkt6-complete`, `c441bb1c`). Ikke pushet eller deployet. E2E-suiten er ikke kjørt ferdig (§11).
Driftsavhengigheter før produksjon: se §9.

Dette dokumentet er kontrakten for hvordan Procynias moduler bruker den felles varselbjellen og
«Mine oppgaver».

---

## 1. To spørsmål, to flater

| Flate | Spørsmål | Blir stille når | Lagres som |
|---|---|---|---|
| **Varselbjellen** | Hva har skjedd som jeg bør vite om? | Varselet er lest | `user_notifications`-rad |
| **Mine oppgaver** (Oppfølging) | Hva må jeg gjøre, og når? | Arbeidet er gjort i modulen | Ingenting — leses live fra modulen |

Et lest eller slettet varsel endrer aldri en oppgave. En oppgave forsvinner bare når modulens egne data
sier at arbeidet er gjort, og flytter seg når modulens eget ansvarsfelt flytter seg.

## 2. Arkitekturbeslutninger

1. **Ingen sentral oppgavetabell.** Oppgaver hentes fra modulenes egne tabeller og tjenester ved hver
   lesing.
2. **Én felles kontrakt:** `MyTaskSource` (grensesnitt), `MyTask` (normalisert oppgave) og
   `MyTasksService` (samler, sorterer, grupperer) i `app/Services/MyTasks/`.
3. **Domenelogikken blir i modulene.** Hver kilde spør modulens egen «Trenger oppmerksomhet»-tjeneste
   og tilgangstjeneste; den felles koden avgjør aldri om noe trenger oppfølging.
4. **Varsler og oppgaver er forskjellige** (§1).
5. **Én bjelle:** alle varsler skrives til `user_notifications` gjennom `UserNotificationWriter`.
6. **«Mine oppgaver» er standardvisningen** på Oppfølging (`/app/info-center`) for alle personas.
7. **Modulens egne fristregler gjenbrukes.** Ingen vilkårlig global grense (§6).
8. **Tilgang håndheves ved lesing** — oppgaver, varsler og påminnelser (§5).
9. **Ingen ny køinfrastruktur.** Varsler skrives synkront etter commit; påminnelser er én planlagt
   kommando.
10. **Ingen e-post.**

## 3. Felles kontrakt

### 3.1 Oppgave (`MyTask`)

| Felt | Innhold |
|---|---|
| `id` | Stabil: modulprefiks + modulens id (`risk-12`, `improvement-action-7`, `supplier-3`). Aldri en databasenøkkel |
| `module`, `type` | Modulnøkkel (`config/procynia_modules.php`) og modulens egen oppgavetype |
| `title`, `subject_title` | Hva oppgaven gjelder, og objektet den hører til |
| `due_on`, `overdue`, `group` | Tidligste relevante frist; om noe er forfalt; `overdue` / `this_week` (til og med søndag) / `later` / `no_due` |
| `reasons` | Hvorfor, i modulens ord, med `due_on`, `overdue`, `can_act` (og `count`) per årsak |
| `can_act` | `false` når personen kan lese objektet, men mangler rettigheten minst én årsak krever |
| `action_url` | Relativ lenke til modulens vanlige arbeidsflate (som har sin egen autorisasjon) |
| `dueSoonDays` | Modulens eget «nærmer seg»-vindu for påminnelser (§6); `null` = felles 7 dager |
| `subject` | Varselprefiks og objekt-id-er, slik at bjellen kan kontrollere tilgangen på nytt |

### 3.2 Kilde (`MyTaskSource`)

```php
public function module(): string;
public function isAvailableFor(User $user): bool;   // modul + leserettighet, modulens eget svar
public function openTasksFor(User $user, int $customerId, CarbonImmutable $today): Collection; // of MyTask
```

`MyTasksService` sjekker i tillegg at brukeren er aktiv og tilhører kunden, og bygger sidens
modulfilter fra `isAvailableFor()` — et filter tilbyr aldri en modul personen ikke kan se.

### 3.3 Varselskriving (`UserNotificationWriter`)

Mottaker aktiv og i riktig kunde; aktøren varsles aldri om egen handling; `dedupe_key` gjør skrivingen
idempotent; skrives etter commit (rollback varsler ingen); feil fanges, så et varsel aldri stopper
brukerens handling.

## 4. Integrerte moduler

| Modul | Oppgave | Ansvarlig | Årsaker / frist | Handlingsrettighet |
|---|---|---|---|---|
| **Anbud** | Åpen aksjon | `saved_notice_info_items.owner_user_id` | Oppfølgingsfrist | — |
| **Wiki** | Gjennomgang / QA | `reviewer_user_id` / `qa_user_id` på gjeldende versjon | Ingen frist | — |
| **Leverandører** | Én per leverandør med funn | `suppliers.owner_user_id` | `SupplierAttentionService` (11 signaler) | assure / assess / edit per årsak |
| **Risiko** | Risiko med funn | `risks.owner_user_id` | `RiskAttentionService`: ikke vurdert, restrisiko, høy restrisiko, vurdering forfalt, aksept utløpt, tiltak forfalt | assess / accept / edit i fagområdet |
| | Åpent risikotiltak | `risk_treatment_actions.owner_user_id` | Tiltakets frist | edit |
| **Avvik og forbedringer** | Åpen sak (Åpen / Under arbeid) | `improvement_cases.owner_user_id` | Sakens frist; tiltak som venter på / ikke besto effektverifisering | edit / close |
| | Aktivt tiltak (planlagt / under arbeid) | `improvement_actions.owner_user_id` | Tiltakets frist | edit |
| **Etterlevelse og revisjon** | Aktivt krav med funn | `compliance_requirements.owner_user_id` | `ComplianceAttentionService`; neste revurdering | assess |
| | Revisjon (planlagt / pågår, eller fullført med avvik uten oppfølging) | `compliance_audits.responsible_user_id` | Planlagt sluttdato; `ComplianceAuditAttentionService` | audit |
| **Kvalitet** | Element i kraft med funn | `quality_items.owner_user_id` | `QualityAttentionService`: kontroll uten evidens / aktivitet, prosess uten styrende dokument, prosess forfalt til revisjon | edit |
| **Mål og KPI** | Aktivt mål med passert måldato | `objectives.owner_user_id` | Måldato | edit |
| | Aktiv KPI: ikke på mål, måling mangler, måling skal registreres | `kpis.owner_user_id`, ellers målets eier (modulens egen fallback) | Rapporteringsfrist (`KpiPeriods::reportingDeadline`) | measure |

Avsluttede, utgåtte, kansellerte og fullførte objekter gir ingen oppgave. Revisjonsfunn,
effektverifisering og kvalitetssjekker har ikke fått nye ansvarsfelt: der modulen ikke har en eier,
er det heller ingen oppgave (se §10).

## 5. Tilgangsstyring og kundeisolasjon

### 5.1 Oppgaver

Hver kilde spør modulens egen tilgangstjeneste først: modulen (`ModuleEntitlementService`),
leserettigheten (`*.view`), og der modulen har fagområder (Risiko, Avvik, Mål og KPI) objektets
fagområde (`visibleRisks()`, `visibleCases()`, `visibleObjectives()`). Et tiltak vises bare på en
risiko/sak personen ser. Å være ansvarlig gir ingen rettigheter. Mangler personen rettigheten en årsak
krever, vises oppgaven med «Mangler rettighet» og en forklaring; backend avviser fortsatt handlingen.

### 5.2 Oppfølging er modul-uavhengig

Oppfølging har ingen modulvakt. Uten Anbud er siden «Mine oppgaver» alene (ett panel, én visning,
nøytral overskrift og hjelpetekst), og gamle lenker til Anbud-visningene lander der.
`InfoCenterModuleMatrixTest` dekker seks modulkombinasjoner på norsk og engelsk.

### 5.3 Varsler

`UserNotificationAccessScope` filtrerer i SQL ved hver lesing, før begrensning og telling — et skjult
varsel vises ikke, telles ikke som ulest og berøres ikke av «Merk alle» / «Slett uleste».

| `event_type` | Krav |
|---|---|
| `bid.*` | Anbud-modul og saken (`saved_notice_id`) synlig for personen |
| `watch_profile.*` | Anbud-modul |
| `wiki.*` | Wiki-modul og `wiki.view` |
| `supplier.*` | Modul, `supplier.view`, `metadata.supplier_id` synlig |
| `risk.*` | Modul, `risk.view`, `metadata.risk_id` i personens fagområder |
| `improvement.*` | Modul, `improvement.view`, `metadata.improvement_case_id` synlig |
| `compliance.*` | Modul, `compliance.view`, kravet eller revisjonen i metadata synlig |
| `quality.*` | Modul, `quality.view`, `metadata.quality_item_id` finnes i kunden |
| `objective.*` | Modul, `objective.view`, `metadata.objective_id` i personens fagområder |
| Annet (`ai_quota.*`, fakturering) | Konto-nivå — vises |

Metadata sammenlignes som tekst, så en ødelagt referanse skjuler bare sin egen rad. Et slettet objekt
skjuler varslene sine. Varsler slettes ikke ved tap av tilgang; de vises igjen om tilgangen kommer
tilbake. `target_url` gir aldri tilgang i seg selv.

### 5.4 Kundeisolasjon

Alle kilder og skrivinger er scoped til brukerens `customer_id`; `UserNotificationWriter` og
`AssignmentNotifier` nekter mottakere fra annen kunde, også når et eierfelt er tvunget på tvers.

## 6. Varslingshendelser

**Tildeling** (bjellen, «Du er tildelt ansvar»): ved ny eier på et nytt objekt, eller endret eier.
Aldri ved uendret eier, fjernet eier, eget valg, rollback, inaktiv mottaker, mottaker som ikke kan lese
objektet, eller avsluttet objekt. `dedupe_key` = hendelse + objekt + mottaker + lagringstidspunkt.

| Hendelse | Utløses av |
|---|---|
| `bid.task_assigned` | Kravansvar (`RequirementResponsibilityTaskService`) og manuelt opprettet aksjon med ansvarlig |
| `wiki.review_assigned`, `wiki.qa_assigned`, `wiki.changes_requested`, `wiki.page_published` | Wiki (uendret) |
| `supplier.owner_assigned` | Ny intern ansvarlig for leverandør |
| `risk.owner_assigned`, `risk.action_assigned` | Risikoeier / ansvarlig for risikotiltak |
| `improvement.case_assigned`, `improvement.action_assigned` | Ansvarlig for sak / tiltak |
| `compliance.requirement_assigned`, `compliance.audit_assigned` | Ansvarlig for krav / revisjon |
| `quality.item_assigned` | Ansvarlig for kvalitetselement |
| `objective.objective_assigned`, `objective.kpi_assigned` | Ansvarlig for mål / KPI |

Styringsmodulenes tildelinger går gjennom `AssignmentObserver` (`created`/`updated`) →
`AssignmentNotifier`, slik at alle skriveveier (skjema, overlevering fra annen modul, creator-tjenester)
varsles likt. Ordinære dataendringer varsler ikke.

**Fristpåminnelser**: `{prefiks}.task_due_soon` og `{prefiks}.task_overdue` (§7).

## 7. Fristpåminnelser

`notifications:task-reminders` (planlagt daglig 06:45, `withoutOverlapping`) → `TaskDeadlineReminderService`.

- **Én mekanisme for alle moduler:** leser `MyTasksService::tasksFor()` for hver aktiv bruker — samme
  liste personen ser, med modulenes tilgangsregler. Fullført, omfordelt, lukket eller utilgjengelig
  arbeid er derfor ikke med; tilgangen kontrolleres på nytt hver kjøring.
- **«Nærmer seg»** når fristen er innenfor modulens vindu: Leverandører 60 dager
  (`SupplierDocument::EXPIRING_SOON_DAYS`), KPI-er sine egne rapporteringsdager, ellers Oppfølgings
  «Frister innen 7 dager». **«Passert»** når oppgaven er forfalt.
- **`dedupe_key`** = `task.{type}:{oppgave-id}:{frist}:{mottaker}`. Gjentatt kjøring (samme dag, etter
  feil, ved retry) skriver ingenting nytt; en flyttet frist er en ny situasjon og varsles én gang; en
  uendret frist aldri igjen. Oppgaver uten frist får ingen påminnelse; forfalt uten dato (f.eks. erstattet
  dokument under en kontroll) én gang.
- **Robust:** feil for én person eller kunde stopper ikke de andre (logges, telles).
- Varselet bærer modulens prefiks og objekt, så bjellen skjuler det når tilgangen mistes.

Første kjøring etter innføring sender én «passert» for hver oppgave som allerede er forfalt — én gang.

## 8. Språk

`HandleInertiaRequests` bygde de delte `translations` før `SetCustomerLocale` når HTTP-kjernen ble
løst etter at providerne hadde startet (tester og konsoll), fordi Inertia flytter sin middleware opp i
prioritetslisten. I en vanlig forespørsel via `public/index.php` var rekkefølgen allerede riktig, så
brukerne var ikke rammet. `AppServiceProvider` legger nå `SetCustomerLocale` foran Inertia i
prioritetslisten i begge tilfeller (`SharedTranslationsLocaleTest`).

## 9. Drift

- **Migrasjoner:** `2026_10_09_000009_add_my_tasks_indexes` (i `main`) og
  `2026_10_10_000001_add_governance_owner_indexes` — kun indekser, reversible. Ingen nye tabeller.
- **Scheduler:** samme `php artisan schedule:work` i Docker og Azure (`SchedulerContractTest`); én
  replika. Ingen ny kø, ingen ny jobbklasse.
- **Før produksjon:** kjør migrasjonene; restart scheduler og kø-workere etter deploy; vurder å varsle
  brukerne om at forfalte oppgaver gir én påminnelse ved første kjøring.

## 10. Kjente begrensninger

- **Deaktiverte ansvarlige:** oppgaver som eies av en deaktivert bruker vises ikke for noen, og
  modulenes «Mangler ansvarlig» gjelder bare slettede brukere. Om deaktivering skal flagge arbeidet
  er en produktbeslutning.
- **Ingen eier = ingen oppgave:** revisjonsfunn, effektverifisering og kvalitetssjekker uten egen eier
  vises på eierens sak/revisjon/element, ikke som egne oppgaver.
- **Kvalitet** har bare forfalt revisjon som frist; elementer uten funn er ikke oppgaver.
- **Store lister:** oppgavelisten er ikke paginert. Spørringene er batchet per modul (konstant antall
  uavhengig av antall oppgaver, testet).

## 11. Tester

| Testsett | Dekker |
|---|---|
| `tests/Unit/MyTasks/*` | Gruppering, søndagsgrense, nøkkelparitet norsk/engelsk |
| `MyTasksAccessTest`, `InfoCenterModuleMatrixTest` | Wiki/Anbud-tilgang, bjellefilter, modulkombinasjoner, hjelpetekst, gamle lenker, N+1 |
| `InfoCenterSupplierTaskTest`, `SupplierOwnerNotificationTest` | Leverandører i oppgaver og bjelle |
| `MyTasksGovernanceTest` | Fase 6D: alle fem moduler, eierskap, lukkede objekter, omfordeling, fagområde, modul, kundeisolasjon, handlingsrettighet, KPI-fallback, modulfilter, batching |
| `GovernanceAssignmentNotificationTest` | Tildelingsvarsler for alle eierfelt og manuelle aksjoner, duplikater, rollback, tilgangstap, sletting, språk |
| `TaskDeadlineReminderTest` | Vindu, én gang, flyttet frist, fullført/omfordelt/utilgjengelig, uten frist, 60/7 dager, inaktiv, engelsk, feilisolasjon, kommando og scheduler |
| `SharedTranslationsLocaleTest` | Delte oversettelser på brukerens språk, innlogging, ingen lekkasje |
| JS: `myTaskLabels.test.js`, `infoCenterHelp.test.js` m.fl. | Grupper, filter, årsakstekster, kort per modul, hjelpetekst |
| E2E: `my-tasks-suppliers.spec.js`, `my-tasks-governance.spec.js`, `info-center-help.spec.js` | Brukerflyter i nettleser |

**Testresultater ved commit (`feat/punkt6-complete`):**

- PHP, hele suiten: 7 427 bestått, 3 hoppet over, 0 feilet. Testene som ble endret etterpå (139) er kjørt på nytt: alle bestått.
- JS, hele enhetssuiten: 1 400 av 1 400 bestått.
- E2E: `my-tasks-governance.spec.js` bestått. Den brede kjøringen ble stoppet ved test 104 av 274: de 103
  fullførte besto, **171 E2E-tester ble ikke fullført** (test 104 i `package-change.spec.js` avbrutt, 170 ikke
  startet). Hele E2E-suiten er derfor ikke verifisert grønn for denne grenen.
