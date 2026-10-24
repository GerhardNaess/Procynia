# V3 i staging – sjekkliste

Gjelder V3 i [`external-api-phase-1-decisions.md`](../external-api-phase-1-decisions.md) §11. Plattformen settes opp etter [`azure-staging-runbook.md`](../azure-staging-runbook.md). Denne listen dekker bare det V3 legger til.

**Ingenting her er gjennomført ennå.** Azure-miljøet finnes ikke (runbookens GO/NO-GO).

## Kontrollskript

Begge skriptene bare leser. De kjøres i en READ ONLY-transaksjon, og sesjonen settes i tillegg skrivebeskyttet. De oppretter ingenting i databasen.

| Skript | Brukes i | Bestått når |
|---|---|---|
| [`scripts/v3/decision-actor-data-check.sql`](../../scripts/v3/decision-actor-data-check.sql) | Steg 2 og 7 | `0 STOPP`. Alle `VURDER`-linjer er forklart |
| [`scripts/v3/compare-trigger-function-catalog.sh`](../../scripts/v3/compare-trigger-function-catalog.sh) | Steg 4 og 5 | Exit 0, «identiske med referansen» |

```bash
# Datakontrollen
PGOPTIONS='-c default_transaction_read_only=on' \
  psql "host=<server> dbname=procynia user=<bruker> sslmode=require" -X -q -v ON_ERROR_STOP=1 \
  -f scripts/v3/decision-actor-data-check.sql

# Triggere og funksjoner mot referansen
scripts/v3/compare-trigger-function-catalog.sh "host=<server> dbname=procynia user=<bruker> sslmode=require"
```

**Datakontrollen** har fire deler:
- **A.** Integrasjonsidentiteter: tildeling, kunde, tilbakekalling og menneskelige markører.
- **B.** Integrasjon som aktør i de 44 beslutningskolonnene.
- **C.** Avgjort rad uten aktør, med samme utvalg som steg 19.
- **D.** Skriptets kolonneliste mot triggerne `human_decision_actor`.

Før grenens migreringer finnes mange av tabellene ikke. De vises som `HOPPET OVER`, ikke som feil.

**`VURDER` i del C** kan ha en lovlig forklaring:
- raden er eldre enn aktørkolonnen;
- personen er slettet (kolonnene er `nullOnDelete`). En `INFO`-linje viser da det bevarte navnet.

Hver slik rad må forklares før produksjon. Migreringene fyller ingenting inn i ettertid.

**Referansen** er [`v3-trigger-function-catalog.txt`](v3-trigger-function-catalog.txt): 97 triggere og 58 funksjoner. Den er laget fra utviklingsdatabasen på `feature/external-api-foundation` (398 migreringer), og er identisk med `procynia_test` og `procynia_e2e`.
- Gjelder bare PostgreSQL 16. Azure bruker 16.
- Utvidelser (`vector`, `amcheck`) og interne fremmednøkkeltriggere er utelatt.
- Referansen må lages på nytt når en ny migrering legger til eller endrer en trigger eller funksjon. Da kjøres `trigger-function-catalog.sql` mot en database migrert til den nye committen.

## Migreringsomfang

Alle migreringer som ikke er kjørt i måldatabasen, kjøres samtidig med `migrate --force`. Laravel kan ikke kjøre bare et utvalg.

Mot en database på dagens `main` er det de **30 migreringene på grenen**, fra `2026_10_22_000001` til `2026_10_24_000003`:
- 25 fra fase 0, med beslutningskolonnene i `2026_10_23_000014`;
- 5 fra API-et (`api_clients`, tildelinger og revisjonsspor).

Står produksjon bak `main`, kommer de manglende migreringene fra `main` i tillegg. Les listen før kjøring:

```bash
php artisan migrate:status --pending   # bare lesing; kjøres i migreringsjobben før migrate
```

## Rekkefølge

| # | Steg | Stopp hvis |
|---|---|---|
| 1 | Kopi av produksjonsdata gjenopprettet i staging (runbook fase 5.2). **Krever egen godkjenning.** | Restore feiler |
| 2 | Datakontrollen mot kopien, **før** migreringene. Lagre utskriften | `STOPP` > 0, eller en `VURDER` uten forklaring |
| 3 | `migrate:status --pending` lagres, deretter `migrate --force` (runbook 5.3 og 5.5) | Feil, eller noe migreres i andre kjøring |
| 4 | `compare-trigger-function-catalog.sh` | Exit ≠ 0 |
| 5 | `down()`: `migrate:rollback --step=<antall fra steg 3>` og deretter `migrate --force`. Etter tilbakerullingen må `pg_dump -s --no-owner --no-privileges` være lik skjemaet fra før steg 3. Etter ny migrering kjøres steg 4 igjen | Feil, skjemaavvik eller exit ≠ 0 |
| 6 | Workloads og runbook fase 7–9. Deretter røyktesten under | Et punkt i røyktesten feiler |
| 7 | Datakontrollen igjen, etter røyktesten | `STOPP` > 0. Del D må være `OK` |

Steg 5 ruller tilbake data som migreringene har fylt inn, og må derfor gjøres **før** røyktesten. I produksjon prøves ikke `down()`.

## Røyktest av menneskelige beslutninger

**Testbrukere i staging:**
- **P1:** System Owner i en testkunde.
- **P2:** en annen person i samme kunde med Wiki-godkjenner, QA og de modulrettighetene som står i tabellen.

Bruk en testkunde, ikke en ekte kunde fra kopien. Noter bruker-id-ene.

**Ingen AI-kall i røyktesten:**
- En Wiki-side og den første prosessflyten i Kvalitet lages bare av AI. Bruk sider og flyter som finnes i kopien.
- Last ikke opp anbudsdokumenter: opplasting starter kravuttrekk (V2).
- Krav legges inn med «Legg til krav».
- Finnes det ingen Wiki-side eller flyt, hoppes punktet over og noteres. En AI-kjøring krever egen godkjenning.

**Etter hvert punkt:** bekreft at kolonnen har id-en til den som handlet. Alle punktene er bestått når hver rad har et kryss.

| Modul | Handling i grensesnittet | Hvem | Kolonne som skal ha aktøren | ✓ |
|---|---|---|---|---|
| Wiki | Side → «Gjennomgang og publisering» → «Velg kontrollør» (P2) → «Send til gjennomgang» | P1 (sideeier) | `enterprise_wiki_page_versions.submitted_by_user_id` = P1, `reviewer_user_id` = P2 | ☐ |
| Wiki | Samme side → «Godkjenn og publiser». **Negativ prøve:** P1 får ikke godkjenne egen innsending | P2 | `enterprise_wiki_pages.reviewed_by_user_id` = P2 | ☐ |
| Wiki | Side → «Påstander som krever behandling» → «Behold teksten» | P2 | `enterprise_wiki_claims.approved_by_user_id` = P2 | ☐ |
| Risiko | Risiko → risiko → «Ny vurdering» → «Lagre vurdering» | P1 | `risk_assessments.assessed_by` | ☐ |
| Risiko | «Aksepter restrisiko» → begrunnelse → «Aksepter» | P1 | `risk_acceptances.accepted_by_user_id` | ☐ |
| Avvik og forbedringer | Sak → «Tiltak» → fullført tiltak → «Verifiser effekt» | P1 | `improvement_action_verifications.verified_by_user_id` | ☐ |
| Avvik og forbedringer | Sak → «Lukk sak» | P1 | `improvement_cases.closed_by_user_id` | ☐ |
| Ledelsens gjennomgang | Gjennomgang → «Ferdigstill» → «Ferdigstill» | P1 | `management_reviews.finalized_by_user_id` | ☐ |
| Kvalitet | «Prosesser» → prosess med flyt → «Flyt» → «Rediger struktur manuelt», lagre → «Godkjenn og publiser» | P1 | `quality_process_blueprints.approved_by_user_id` og `quality_process_revisions.approved_by_user_id` | ☐ |
| Etterlevelse og revisjon | Krav → «Endre relevans» → «Lagre relevans» (krav fra Kravbiblioteket) | P1 | `compliance_requirements.applicability_set_by` | ☐ |
| Etterlevelse og revisjon | Krav → «Vurder etterlevelse» → «Lagre vurdering» | P1 | `compliance_assessments.assessed_by_user_id` | ☐ |
| Leverandøroppfølging | Leverandør → «Oversikt» → «Vurder kritikalitet» / «Endre kritikalitet» → «Lagre vurdering» | P1 | `supplier_criticality_changes.changed_by_user_id` | ☐ |
| Leverandøroppfølging | Leverandør → «Vurderinger» → «Vurder leverandør» → «Lagre vurdering» | P1 | `supplier_assessments.assessed_by_user_id` | ☐ |
| Mål og KPI | Mål → KPI → «Registrer måling», deretter «Trekk tilbake» med begrunnelse | P1 | `kpi_measurements.recorded_by_user_id` og `withdrawn_by_user_id` | ☐ |
| Mål og KPI | Mål → «Lukk mål» med utfall | P1 | `objectives.closed_by_user_id` og `objective_status_changes.changed_by_user_id` | ☐ |
| Anbud | Sak → «Krav fra dokumenter» → «Legg til krav» → «Godkjenn» | P1 | `saved_notice_ai_requirements.approved_by_user_id` | ☐ |
| Anbud | Lagret sak → statushandling, for eksempel «Gå til kvalifisering» | P1 | `saved_notice_status_changes.changed_by_user_id` | ☐ |
| Anbud | Lagret sak → «Sett som No-go» med begrunnelse | P1 | `saved_notice_no_go_decisions.closed_by_user_id` | ☐ |

**Merknader:**
- `enterprise_wiki_page_review_events` registrerer bare «Send tilbake» og «Trekk tilbake», ikke innsending og godkjenning.
- «Legg til krav» krever at kunden har AI i abonnementet, selv om det ikke gjøres noe AI-kall.

**Ikke en del av røyktesten:** at databasen nekter en integrasjon som aktør. Det krever en skriving med en integrasjonsidentitet. Det er dekket lokalt av `HumanDecisionActorTriggerTest`, og i staging av steg 4 (samme triggerdefinisjon) og del B og D i steg 7.

## Godkjent når

- Steg 2–5 og 7 har bestått.
- Alle radene i røyktesten er krysset av, eller hoppet over med begrunnelse.
- Utskriftene fra steg 2, 3, 4 og 7 er lagret sammen med image-digesten fra runbook 4.8.

Kriteriene i §11 gjelder: staging og produksjon har samme triggere, røyktesten er grønn, og ingen rader er stoppet av triggerne.

## Produksjon (V3 punkt 6) – egen godkjenning

Samme steg som over, uten steg 5:
1. Vedlikeholdsvindu med stoppet skriving.
2. Backup rett før:
   - på Compose: `scripts/backup-production.sh` og [`operations/backup-restore.md`](../operations/backup-restore.md), oppbevaring 30–90 dager før deploy;
   - i Azure: et bekreftet PITR-punkt.
3. Datakontroll, migrering, katalogsammenligning, røyktest og ny datakontroll.

Rollback følger runbookens prinsipp: tilbake til det gamle miljøet eller backupen, aldri datakopiering bakover.
