<?php

namespace Database\Seeders;

use App\Models\OperationalDeviation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class OperationalDeviationSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('operational_deviations')) {
            return;
        }

        // Status-rapport 15. mai 2026 — alle kjente avvik

        // --- Sikkerhetsavvik ---

        $this->seed([
            'code' => 'AVVIK-001',
            'title' => 'Hardkodede Docker-credentials',
            'category' => OperationalDeviation::CATEGORY_SECURITY,
            'severity' => OperationalDeviation::SEVERITY_CRITICAL,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => 'Docker-oppsettet inneholdt databasebruker og passord i klartekst i docker-compose.yml.',
            'impact' => 'Repoet kunne ikke trygt deles eller pushes uten risiko for lekkasje av databasecredentials.',
            'recommended_action' => 'Flytte credentials til .env og bruke miljøvariabler i docker-compose.yml.',
            'acceptance_criteria' => "docker-compose.yml inneholder ikke ekte databasepassord.\ndocker-compose.yml bruker miljøvariabler.\n.env er ikke versjonert.\nDocker Compose starter fortsatt.\nAppen bruker postgres:5432 inne i Docker.\nqueue.default er redis.",
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-15 08:00:00',
            'ready_for_verification_at' => '2026-05-15 10:00:00',
            'verified_at' => '2026-05-15 11:00:00',
            'closed_at' => '2026-05-15 12:00:00',
            'verification_notes' => 'docker compose config, docker compose ps, databasekonfigurasjon og queue.default ble verifisert. Passordet ble fjernet fra versjonskontrollerte filer.',
        ]);

        $this->seed([
            'code' => 'AVVIK-002',
            'title' => 'Database-dumper i Git-historikk',
            'category' => OperationalDeviation::CATEGORY_SECURITY,
            'severity' => OperationalDeviation::SEVERITY_CRITICAL,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => 'Databasebackupfiler fra Docker-migreringen ble committet og pushet til GitHub.',
            'impact' => 'Database-dumper kan inneholde faktiske data, brukere, passord-hasher, dokumentmetadata og driftsdata.',
            'recommended_action' => "Flytte backupfiler ut av repo, legge *.dump i .gitignore, rense Git-historikk med git-filter-repo og force-pushe renset historikk.",
            'acceptance_criteria' => "Dumpfiler ligger utenfor repoet.\n*.dump er ignorert.\ngit rev-list --objects --all | grep '\\.dump\$' returnerer ingen treff.\nRenset historikk er pushet til GitHub.",
            'source' => 'Docker-migrering 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-15 08:00:00',
            'ready_for_verification_at' => '2026-05-15 10:00:00',
            'verified_at' => '2026-05-15 11:00:00',
            'closed_at' => '2026-05-15 12:00:00',
            'verification_notes' => 'Git-historikk ble renset med git-filter-repo. Renset historikk ble force-pushet. Kontroll med git rev-list ga ingen .dump-treff.',
        ]);

        $this->seed([
            'code' => 'AVVIK-003',
            'title' => 'APP_DEBUG aktiv i Docker-konfigurasjon',
            'category' => OperationalDeviation::CATEGORY_SECURITY,
            'severity' => OperationalDeviation::SEVERITY_HIGH,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => 'docker-compose.yml hadde APP_ENV: local og APP_DEBUG: "true" hardkodet i environment-blokkene for app, queue og scheduler. Inline Docker Compose-verdier overstyrer env_file, slik at .env-verdier for APP_ENV og APP_DEBUG ble ignorert.',
            'impact' => 'Produksjonsdeploy med docker-compose.yml alene ville kjøre med APP_DEBUG=true og APP_ENV=local, noe som eksponerer stack traces og intern konfigurasjon.',
            'recommended_action' => 'Opprett docker-compose.prod.yml som overstyrer APP_ENV og APP_DEBUG for app, queue og scheduler. Dokumenter at produksjon alltid skal startes med begge filene.',
            'acceptance_criteria' => "docker-compose.prod.yml overstyrer APP_ENV=production og APP_DEBUG=false for app, queue og scheduler.\nProduksjon startes med docker compose -f docker-compose.yml -f docker-compose.prod.yml.\ndocker-compose.yml kan fortsatt brukes til lokal utvikling.\nIngen secrets er lagt i docker-compose.prod.yml.",
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-15 14:30:00',
            'ready_for_verification_at' => '2026-05-15 15:30:00',
            'verified_at' => '2026-05-15 15:45:00',
            'closed_at' => '2026-05-15 15:45:00',
            'verification_notes' => 'docker-compose.prod.yml opprettet. docker compose -f docker-compose.yml -f docker-compose.prod.yml config verifiserer at app, queue og scheduler får APP_ENV=production og APP_DEBUG=false. docker-compose.yml alene beholder lokal konfigurasjon uendret.',
        ]);

        $this->seed([
            'code' => 'AVVIK-004',
            'title' => 'Ops-health-endepunkter er åpne',
            'category' => OperationalDeviation::CATEGORY_SECURITY,
            'severity' => OperationalDeviation::SEVERITY_HIGH,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => '/ops/health/queue-scheduler og /ops/health/queues/{queue} manglet health.token middleware, og var tilgjengelige uten autentisering. /health/*-endepunktene var allerede beskyttet med EnsureHealthToken-middleware.',
            'impact' => 'Uautoriserte kunne lese intern driftsstatus for køer og scheduler uten token.',
            'recommended_action' => 'Legg health.token middleware på ops-rute-gruppen i routes/web.php. Middleware validerer X-Procynia-Health-Token mot PROCYNIA_HEALTH_TOKEN.',
            'acceptance_criteria' => "Alle /ops/health-endepunkter krever X-Procynia-Health-Token.\nManglende eller feil token gir HTTP 403.\nUptime Kuma bruker headeren X-Procynia-Health-Token ved overvåkning.",
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-15 15:45:00',
            'ready_for_verification_at' => '2026-05-15 16:30:00',
            'verified_at' => '2026-05-15 16:45:00',
            'closed_at' => '2026-05-15 16:45:00',
            'verification_notes' => 'health.token middleware lagt på ops-rute-gruppen. Tester bekrefter 403 uten token, 403 med feil token, 503 uten konfigurert token, og korrekt respons med gyldig token for alle ops-health-endepunkter.',
        ]);

        $this->seed([
            'code' => 'AVVIK-027',
            'title' => 'Manglende audit log for AI-operasjoner',
            'category' => OperationalDeviation::CATEGORY_SECURITY,
            'severity' => OperationalDeviation::SEVERITY_MEDIUM,
            'status' => OperationalDeviation::STATUS_NEW,
            'description' => 'Det er uklart eller mangelfullt hvem som genererte hvilke AI-svar, når og med hvilket grunnlag.',
            'impact' => 'Revisjon, sporbarhet og compliance svekkes.',
            'recommended_action' => 'Logg AI-operasjoner med bruker, tidspunkt, krav, input, modell, kilder og resultatstatus.',
            'acceptance_criteria' => 'AI-operasjoner kan spores i admin eller audit-logg.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
        ]);

        $this->seed([
            'code' => 'AVVIK-028',
            'title' => 'Manglende GDPR og personverndokumentasjon',
            'category' => OperationalDeviation::CATEGORY_SECURITY,
            'severity' => OperationalDeviation::SEVERITY_HIGH,
            'status' => OperationalDeviation::STATUS_NEW,
            'description' => 'Det mangler dokumentasjon for personvern, databehandleransvar og behandling av kundedata.',
            'impact' => 'Systemet er ikke klart for B2B-kunder med krav til compliance.',
            'recommended_action' => 'Lag GDPR-/personverndokumentasjon og databehandlergrunnlag.',
            'acceptance_criteria' => 'Det finnes dokumentert behandlingsgrunnlag, datatyper, lagring, sletting og ansvar.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
        ]);

        // --- Driftsavvik ---

        $this->seed([
            'code' => 'AVVIK-005',
            'title' => 'Manglende produksjonsdeploy-guide',
            'category' => OperationalDeviation::CATEGORY_OPERATION,
            'severity' => OperationalDeviation::SEVERITY_HIGH,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => 'Det fantes ingen komplett produksjonsdeploy-guide.',
            'impact' => 'Produksjonssetting ble personavhengig og risikabelt.',
            'recommended_action' => 'Lag docs/operations/production-deploy.md med Docker, secrets, HTTPS/TLS, migrasjoner, queue restart, backup og rollback.',
            'acceptance_criteria' => 'Det finnes en komplett deploy-guide som kan følges av en annen teknisk person.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-15 12:30:00',
            'ready_for_verification_at' => '2026-05-15 14:00:00',
            'verified_at' => '2026-05-15 14:30:00',
            'closed_at' => '2026-05-15 14:30:00',
            'verification_notes' => 'docs/operations/production-deploy.md opprettet med alle kravpunkter dekket: secrets, APP_DEBUG, Docker/produksjonsoppsett, migrasjoner, queue restart, scheduler, TLS/HTTPS, backup, restore, health checks og rollback.',
        ]);

        $this->seed([
            'code' => 'AVVIK-006',
            'title' => 'Queue worker bruker tries=1 og timeout=0',
            'category' => OperationalDeviation::CATEGORY_OPERATION,
            'severity' => OperationalDeviation::SEVERITY_HIGH,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => 'Queue worker kjørte med --tries=1 og --timeout=0, som betyr at midlertidige feil ga permanent jobb-feil og hengende jobber ikke ble avbrutt.',
            'impact' => 'Feilende jobber ble ikke automatisk retryet, og hengende jobber kunne blokkere worker uendelig lenge.',
            'recommended_action' => 'Endre queue worker-kommandoen til --tries=3 --backoff=60 --timeout=120 --sleep=3 i docker-compose.yml.',
            'acceptance_criteria' => "Queue worker bruker --tries=3, --backoff=60, --timeout=120 og --sleep=3.\nFailed jobs-tabellen er tilgjengelig.\nDokumentasjonen beskriver retry-strategi og failed jobs-håndtering.",
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-15 16:45:00',
            'ready_for_verification_at' => '2026-05-15 17:15:00',
            'verified_at' => '2026-05-15 17:30:00',
            'closed_at' => '2026-05-15 17:30:00',
            'verification_notes' => 'docker-compose.yml oppdatert til --tries=3 --backoff=60 --timeout=120 --sleep=3. Failed_jobs-tabellen finnes allerede. Alle docs-referanser er oppdatert. docker compose config bekrefter ny kommando.',
        ]);

        $this->seed([
            'code' => 'AVVIK-007',
            'title' => 'Manglende produksjonsbackup og restore-rutine',
            'category' => OperationalDeviation::CATEGORY_OPERATION,
            'severity' => OperationalDeviation::SEVERITY_CRITICAL,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => 'Det manglet komplett dokumentert og testet backup/restore-rutine for produksjon.',
            'impact' => 'Datatap eller feil restore kunne få kritiske konsekvenser uten dokumentert og testet prosedyre.',
            'recommended_action' => 'Etabler automatisk backup, restore-test, oppbevaringspolicy og dokumentert prosedyre.',
            'acceptance_criteria' => "scripts/backup-production.sh oppretter timestampet DB-dump og storage-arkiv.\nscripts/restore-production-backup.sh restorer database og storage fra backup-filer.\ndocs/operations/backup-restore.md dokumenterer RPO, RTO, oppbevaringspolicy, cron-oppsett, pre-deploy backup, restore steg for steg, etterkontroll og månedlig verifisering.\nSecrets tas ikke backup av skriptene.\nProduksjonsdeploy-guiden peker til backup-restore.md.",
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-15 17:30:00',
            'ready_for_verification_at' => '2026-05-15 19:30:00',
            'verified_at' => '2026-05-15 20:00:00',
            'closed_at' => '2026-05-15 20:00:00',
            'verification_notes' => 'scripts/backup-production.sh og scripts/restore-production-backup.sh opprettet og syntaksverifisert. docs/operations/backup-restore.md dekker RPO (1 time), RTO (4 timer), oppbevaringspolicy, cron-oppsett, pre-deploy backup, restore-prosedyre, etterkontroll, månedlig verifisering og sikkerhetstiltak. Admin → Drift → Backup og restore gir Super Admin mulighet til å aktivere/deaktivere backup, starte manuell backup, overvåke scheduler-heartbeat, se backup-kjøringer og backupfiler. Systemstatus i Admin viser varsel ved backup-feil eller stoppet backup. Secrets tas ikke backup av skriptene. production-deploy.md seksjon 9 peker til backup-restore.md.',
        ]);

        $this->seed([
            'code' => 'AVVIK-029',
            'title' => 'Ingen tydelig produksjons-HTTPS/TLS-rutine',
            'category' => OperationalDeviation::CATEGORY_OPERATION,
            'severity' => OperationalDeviation::SEVERITY_CRITICAL,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => 'Det manglet tydelig rutine for HTTPS/TLS i produksjon.',
            'impact' => 'Produksjon kunne ende med usikret HTTP.',
            'recommended_action' => 'Dokumenter og implementer TLS via reverse proxy, load balancer eller tilsvarende.',
            'acceptance_criteria' => "Produksjonsdeploy krever HTTPS og har dokumentert sertifikathåndtering.",
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-15 21:00:00',
            'ready_for_verification_at' => '2026-05-15 21:30:00',
            'verified_at' => '2026-05-15 21:30:00',
            'closed_at' => '2026-05-15 21:30:00',
            'verification_notes' => 'docs/operations/production-deploy.md seksjon 8 er utvidet med: eksplisitt krav om HTTPS og HTTP→HTTPS redirect, nginx reverse proxy-eksempel med X-Forwarded-Proto og øvrige forwarded headers, Laravel TrustProxies-konfigurasjon (bootstrap/app.php), certbot/Let\'s Encrypt fornyelsesrutine, konkrete verifikasjonskommandoer (curl -I https og curl -I http med forventet respons), sjekk av at interne porter ikke er eksponert, og note om at TLS-sjekklisten kjøres på nytt ved domene-/sertifikatendring. .env.example er oppdatert med produksjonskommentar for APP_URL.',
        ]);

        $this->seed([
            'code' => 'AVVIK-030',
            'title' => 'Uklart om BackupRecovery-siden dekker reell restore',
            'category' => OperationalDeviation::CATEGORY_OPERATION,
            'severity' => OperationalDeviation::SEVERITY_HIGH,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => 'BackupRecovery-siden finnes, men det er uklart om den dekker faktisk backup og restore for produksjon.',
            'impact' => 'Det kan finnes en UI-side som gir falsk trygghet uten reell restore-prosess.',
            'recommended_action' => 'Gjennomgå BackupRecovery-funksjonen og dokumenter faktisk virkemåte.',
            'acceptance_criteria' => "BackupRecovery-siden er verifisert, dokumentert og koblet til reell backup/restore-rutine, eller tydelig merket som ikke-produksjonsklar.",
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-16 09:00:00',
            'ready_for_verification_at' => '2026-05-16 10:00:00',
            'verified_at' => '2026-05-16 10:30:00',
            'closed_at' => '2026-05-16 10:30:00',
            'verification_notes' => 'BackupRecovery-siden er verifisert. Siden viser faktisk backupstatus og backupstyring. Restore er tydelig dokumentert som kontrollert driftsprosedyre. Siden gir ikke falsk inntrykk av automatisk produksjonsrestore. Dokumentasjon og tester er oppdatert. AVVIK-007-rutinen er lagt til grunn.',
        ]);

        // --- Integrasjoner ---

        $this->seed([
            'code' => 'AVVIK-008',
            'title' => 'Doffin peker mot beta-API som standard',
            'category' => OperationalDeviation::CATEGORY_INTEGRATIONS,
            'severity' => OperationalDeviation::SEVERITY_HIGH,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => 'Doffin-konfigurasjonen pekte mot betaapi.doffin.no som standard.',
            'impact' => 'Produksjon kunne bruke feil datakilde eller ustabil integrasjon.',
            'recommended_action' => 'Endre produksjonskonfigurasjon til live Doffin-API og dokumenter miljøforskjell.',
            'acceptance_criteria' => "Produksjon bruker live-API.\nLokal/test kan bruke beta hvis eksplisitt konfigurert.",
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-15 20:30:00',
            'ready_for_verification_at' => '2026-05-15 21:00:00',
            'verified_at' => '2026-05-15 21:00:00',
            'closed_at' => '2026-05-15 21:00:00',
            'verification_notes' => 'config/doffin.php: beta-fallback fjernet, base_url har nå ingen hardkodet standardverdi. .env.example: DOFFIN_BASE_URL=https://api.doffin.no er satt som produksjonsstandard med kommentar om at lokal beta-bruk krever eksplisitt valg. docs/operations/production-deploy.md: Doffin-seksjonen oppdatert med eksplisitt produksjonskrav, AVVIK-008 markert lukket i avvikstabellen. Tester verifiserer at config/doffin.php ikke inneholder beta-default og at .env.example dokumenterer live API som standard.',
        ]);

        // --- AI-avvik ---

        $this->seed([
            'code' => 'AVVIK-009',
            'title' => 'Manglende rate limiting og kostnadskontroll på AI-kall',
            'category' => OperationalDeviation::CATEGORY_AI,
            'severity' => OperationalDeviation::SEVERITY_CRITICAL,
            'status' => OperationalDeviation::STATUS_IN_PROGRESS,
            'description' => 'Kostnadskontroll er under ny implementering. Fase 2-4 etablerte commercial quota, runtime stops, kundevarsling og intern adminflate. Fase 5 legger operasjonelle NOK-sikkerhetsbudsjett, pris- og FX-policy og betalingsstatus-håndheving. Gjenstår: samlet sluttverifisering og rollout.',
            'impact' => 'Feilaktig loop eller aggressiv bruk kan gi ukontrollerte API-kostnader.',
            'recommended_action' => 'Legg inn rate limiting, kundegrenser og/eller AI credits-måling.',
            'acceptance_criteria' => 'Alle AI-entrypoints er målt, kommersiell quota håndheves server-side, queue og scheduler respekterer grensen, kunden varsles før stopp og global safeguard er testet.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-15 22:00:00',
            'ready_for_verification_at' => null,
            'verified_at' => null,
            'closed_at' => null,
            'verification_notes' => 'AVVIKET forblir åpent. Fase 1 la provider-attempt-måling og sikret Wiki Ask. Fase 2 la commercial quota, reservasjon, customer/global runtime stop og audit. Fase 3 la kanonisk quota-status, 80/90/100-varsling til System Owner og klassifiserte hard-stop-meldinger. Fase 4 la intern adminflate, append-only kapasitetsjusteringer og auditert operatøroverstyring som aldri kan omgå global emergency stop. Fase 5 la operasjonelle NOK-sikkerhetsbudsjett per kunde og globalt med atomisk reservasjon, immutable kostnadssnapshot på attempt-ledgeren, hard stop ved ukjent modellpris, konservativ FX-policy og håndheving av betalingsstatus. Closure krever fortsatt Fase 6: shadow-/stegvis aktivering i produksjon, beslutning om backfill av historisk forbruk, driftsrunbook og en samlet sluttverifisering av hele kjeden.',
        ]);

        $this->seed([
            'code' => 'AVVIK-012',
            'title' => 'Semantisk retrieval skalerer dårlig med PHP cosine similarity',
            'category' => OperationalDeviation::CATEGORY_AI,
            'severity' => OperationalDeviation::SEVERITY_MEDIUM,
            'status' => OperationalDeviation::STATUS_NEW,
            'description' => 'Cosine similarity beregnes i PHP.',
            'impact' => 'Dette kan bli en flaskehals når kunnskapsbasen vokser.',
            'recommended_action' => 'Planlegg overgang til pgvector eller annen vektorindeksert løsning.',
            'acceptance_criteria' => 'Retrieval kan skaleres uten full PHP-skanning av embeddings.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
        ]);

        $this->seed([
            'code' => 'AVVIK-013',
            'title' => 'Embeddings lagres som JSON i PostgreSQL',
            'category' => OperationalDeviation::CATEGORY_AI,
            'severity' => OperationalDeviation::SEVERITY_MEDIUM,
            'status' => OperationalDeviation::STATUS_NEW,
            'description' => 'Embeddings lagres som JSON i PostgreSQL, ikke i vektorindeksert struktur.',
            'impact' => 'Søk blir mindre effektivt ved vekst.',
            'recommended_action' => 'Vurder pgvector og migrering av embeddings til vektorkolonne.',
            'acceptance_criteria' => 'Det foreligger beslutning og plan for vektorsøk før større kundemengde.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
        ]);

        $this->seed([
            'code' => 'AVVIK-016',
            'title' => 'Manglende kildevisning per AI-svar',
            'category' => OperationalDeviation::CATEGORY_AI,
            'severity' => OperationalDeviation::SEVERITY_HIGH,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => 'Brukeren ser ikke tydelig nok hvilke kilder som ligger bak hvert AI-svar.',
            'impact' => 'Tillit, etterprøvbarhet og kvalitetssikring svekkes.',
            'recommended_action' => 'Vis dokument, seksjon, chunk og eventuelt sidegrunnlag per svar.',
            'acceptance_criteria' => 'Brukeren kan se konkret kildegrunnlag for hvert svarutkast.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-16 09:15:00',
            'ready_for_verification_at' => '2026-05-16 09:45:00',
            'verified_at' => '2026-05-16 10:00:00',
            'closed_at' => '2026-05-16 10:00:00',
            'verification_notes' => 'AI-svar viser nå kildegrunnlag per krav/svar. document_title, tabellgrunnlag, bildegrunnlag og retrieval_sources returneres og vises der dette finnes. Lagret kildegrunnlag overlever refresh, og brukeren kan skille dokumentert grunnlag fra mangler og antagelser. Ingen ny parallell kilde-modell er innført.',
        ]);

        $this->seed([
            'code' => 'AVVIK-017',
            'title' => 'Manglende brukerprompt per krav',
            'category' => OperationalDeviation::CATEGORY_AI,
            'severity' => OperationalDeviation::SEVERITY_HIGH,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => 'Brukeren kan ikke gi egen instruks eller kontekst per krav før svar genereres.',
            'impact' => 'Svarene blir mindre treffsikre og mindre tilpasset brukerens kompetanse.',
            'recommended_action' => "Legg til prompt-felt per krav som kombineres med Procynias standardprompt.",
            'acceptance_criteria' => 'Bruker kan angi prompt per krav, og prompten brukes ved generering.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-15 10:00:00',
            'ready_for_verification_at' => '2026-05-15 10:30:00',
            'verified_at' => '2026-05-15 10:45:00',
            'closed_at' => '2026-05-15 10:45:00',
            'verification_notes' => 'Per-requirement user prompt is fully implemented. AiController::generateRequirementAnswerDraft() accepts user_answer_prompt (nullable string, max 5000), normalises it, and forwards it to RequirementAnswerDraftService::ensureAnswerDraft() as $requirementUserPrompt. The service inserts it into the AI payload as requirement_user_instructions with scope and priority metadata. The frontend (Show.jsx) maintains a per-requirement prompt state map keyed by requirement ID, renders a textarea per requirement, and sends user_answer_prompt in the POST body.',
        ]);

        // --- Billing-avvik ---

        $this->seed([
            'code' => 'AVVIK-010',
            'title' => 'Ingen måling av AI-bruk mot inkluderte credits',
            'category' => OperationalDeviation::CATEGORY_BILLING,
            'severity' => OperationalDeviation::SEVERITY_HIGH,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => "Kundens inkluderte AI-bruk/credits måles ikke tydelig mot faktisk forbruk.",
            'impact' => 'Billing og kostnadskontroll blir upresis.',
            'recommended_action' => 'Implementer AI usage metering per kunde og vis forbruk i Billing.',
            'acceptance_criteria' => 'AI-bruk telles per kunde og sammenlignes med inkluderte credits.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-15 23:00:00',
            'ready_for_verification_at' => '2026-05-15 23:30:00',
            'verified_at' => '2026-05-15 23:45:00',
            'closed_at' => '2026-05-15 23:45:00',
            'verification_notes' => 'AI usage is now measured from ai_usage_events. Admin can review AI usage per customer and per user. Allowed and blocked AI operations are visible, including user-limit and customer-limit blocks. The view gives internal evidence for later tuning of included AI capacity. No sensitive prompt, document, or answer text is stored or shown. This is not billing or Stripe usage billing.',
        ]);

        $this->seed([
            'code' => 'AVVIK-011',
            'title' => 'Ingen self-service billing onboarding',
            'category' => OperationalDeviation::CATEGORY_BILLING,
            'severity' => OperationalDeviation::SEVERITY_HIGH,
            'status' => OperationalDeviation::STATUS_NEW,
            'description' => 'Nye kunder kan ikke selv opprette abonnement og aktivere betaling.',
            'impact' => 'Kommersiell onboarding blir manuell og skalerer dårlig.',
            'recommended_action' => 'Implementer Stripe Checkout eller tilsvarende selvbetjent abonnementsflyt.',
            'acceptance_criteria' => 'Ny kunde kan velge plan, legge inn betaling og aktivere abonnement uten intern manuell opprettelse.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
        ]);

        // --- Dokumenthåndtering ---

        $this->seed([
            'code' => 'AVVIK-014',
            'title' => 'PDF-ekstraksjon håndterer ikke tabeller og grafikk godt nok',
            'category' => OperationalDeviation::CATEGORY_DOCUMENT_HANDLING,
            'severity' => OperationalDeviation::SEVERITY_HIGH,
            'status' => OperationalDeviation::STATUS_IN_PROGRESS,
            'description' => 'PDF-ekstraksjon er hovedsakelig tekstbasert og håndterer ikke tabeller/grafikk godt nok.',
            'impact' => 'Viktige krav og dokumentasjonsgrunnlag kan gå tapt.',
            'recommended_action' => 'Utvid parser eller prosess for robust tabell- og grafikkhåndtering i PDF.',
            'acceptance_criteria' => 'PDF-dokumenter med tabeller/grafikk gir brukbare chunks og kilder.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-18 22:52:00',
            'verification_notes' => "Aktivt arbeid startet 18. mai 2026 på grenen fix-pdf-extraction.\n\nImplementert så langt:\n- Tabellelementer og bildelementer bevares under PDF-parsing (461f774, 2026-05-19)\n- Tabellrader holdes samlet på tvers av sideskift (0a74043, 2026-05-20)\n- Tabeller holdes samlet på tvers av sideskift (6992c4b, 2026-05-20)\n- Innholdsfortegnelsesstøy i PDF-er undertrykkes (7cc0592, 2026-05-20)\n- Tabell- og bildegrunnlag inkluderes i kunnskapsmatching (63922b1, 2026-05-20)\n- PDF-vektorfigurer bevares som bildeblokker (a4c977 og d24111e, 2026-05-20)\n- Forhåndsvisning av figurgap-chunks rendereres (50b7b9f og 553fb60, 2026-05-20)\n- Tabellkontinuasjonshåndtering for mellomkolonner og vertikalt overlapp (48da065, 55550ef, 2026-05-18)\n\nGjenstår: verifisering mot akseptansekriterie og lukking av grenen.",
        ]);

        $this->seed([
            'code' => 'AVVIK-015',
            'title' => 'pdftotext-sti er hardkodet',
            'category' => OperationalDeviation::CATEGORY_DOCUMENT_HANDLING,
            'severity' => OperationalDeviation::SEVERITY_MEDIUM,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => 'pdftotext-stien var hardkodet i konfigurasjon.',
            'impact' => 'PDF-ekstraksjon kunne feile i Docker eller andre miljøer.',
            'recommended_action' => 'Gjør binærsti miljøstyrt og dokumenter Docker-avhengighet.',
            'acceptance_criteria' => "pdftotext fungerer i Docker og sti styres via config/env.",
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-15 21:30:00',
            'ready_for_verification_at' => '2026-05-15 22:00:00',
            'verified_at' => '2026-05-15 22:00:00',
            'closed_at' => '2026-05-15 22:00:00',
            'verification_notes' => 'config/services.php: hardkodet Mac-sti /usr/local/bin/pdftotext fjernet, env(\'PDFTOTEXT_BINARY\') brukes uten fallback. DocumentTextExtractor.php: null-sjekk og Log::warning lagt til for ukonfigurert binær. docker/php/Dockerfile: poppler-utils lagt til i apt-get install. .env.example: PDFTOTEXT_BINARY=/usr/bin/pdftotext dokumentert med kommentar for Docker/produksjon og lokal Mac. Lokal utvikling angir egen sti i .env. Tester verifiserer config-basert sti og lukket avviksstatus.',
        ]);

        // --- Produktavvik ---

        $this->seed([
            'code' => 'AVVIK-018',
            'title' => 'Manglende eksport til Word',
            'category' => OperationalDeviation::CATEGORY_PRODUCT,
            'severity' => OperationalDeviation::SEVERITY_HIGH,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => 'Procynia mangler eksport av krav og svarutkast til Word.',
            'impact' => 'Brukerne får svak overgang fra Procynia til reelt tilbudsdokument.',
            'recommended_action' => 'Implementer Word-eksport med krav, svar, tabeller, grafikk og kilder.',
            'acceptance_criteria' => 'Brukeren kan eksportere relevante tilbudssvar til Word-format.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-16 22:00:00',
            'ready_for_verification_at' => '2026-05-16 23:00:00',
            'verified_at' => '2026-05-16 23:34:00',
            'closed_at' => '2026-05-16 23:34:00',
            'verification_notes' => 'Word-eksport er implementert og merget til main (commit 5312050, 2026-05-16 23:34).\n\nEksportfunksjonen lar brukere eksportere krav og svarutkast fra AI-arbeidsrommet direkte til .docx-format. Krav, svarutkast og kildegrunnlag inkluderes i eksporten. Brukeren aktiverer eksporten via AI-grensesnittet på SavedNotice.',
        ]);

        $this->seed([
            'code' => 'AVVIK-019',
            'title' => 'Manglende saksrom per anbud',
            'category' => OperationalDeviation::CATEGORY_PRODUCT,
            'severity' => OperationalDeviation::SEVERITY_MEDIUM,
            'status' => OperationalDeviation::STATUS_NEW,
            'description' => 'Det mangler et samlet saksrom per anbud med filer, krav, kommentarer, status og historikk.',
            'impact' => 'Arbeidet kan bli fragmentert og vanskelig å følge.',
            'recommended_action' => 'Utvikle samlet saksrom for hver SavedNotice.',
            'acceptance_criteria' => 'Brukeren har ett sted for alle sentrale aktiviteter og data per anbud.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
        ]);

        $this->seed([
            'code' => 'AVVIK-020',
            'title' => 'Manglende vinnersjanse-estimat',
            'category' => OperationalDeviation::CATEGORY_PRODUCT,
            'severity' => OperationalDeviation::SEVERITY_MEDIUM,
            'status' => OperationalDeviation::STATUS_NEW,
            'description' => 'Systemet mangler vurdering av vinnersjanse og konkurranseposisjon.',
            'impact' => 'Brukeren får mindre støtte til prioritering av hvilke anbud som bør følges.',
            'recommended_action' => 'Legg til første vurdering av win probability basert på fit, historikk, krav, kunde og konkurranse.',
            'acceptance_criteria' => 'Procynia kan gi en første, forklarbar vinnersjanse-vurdering.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
        ]);

        $this->seed([
            'code' => 'AVVIK-021',
            'title' => 'Manglende tilbudskalender og tydelig fristvisning',
            'category' => OperationalDeviation::CATEGORY_PRODUCT,
            'severity' => OperationalDeviation::SEVERITY_MEDIUM,
            'status' => OperationalDeviation::STATUS_NEW,
            'description' => 'Frister finnes i data, men er ikke tydelig nok samlet i kalender eller fremoverskuende visning.',
            'impact' => 'Viktige frister kan overses.',
            'recommended_action' => 'Lag tilbudskalender og dashboard for kommende frister.',
            'acceptance_criteria' => 'Brukeren kan se kommende tilbudsfrister, spørsmålsfrister og interne frister samlet.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
        ]);

        // --- Teknisk gjeld ---

        $this->seed([
            'code' => 'AVVIK-022',
            'title' => 'Lite gjenbruk i React-komponenter',
            'category' => OperationalDeviation::CATEGORY_TECHNICAL_DEBT,
            'severity' => OperationalDeviation::SEVERITY_MEDIUM,
            'status' => OperationalDeviation::STATUS_IN_PROGRESS,
            'description' => 'Det er få delte React-komponenter sammenlignet med antall sider.',
            'impact' => 'UI kan bli inkonsistent og dyrere å vedlikeholde.',
            'recommended_action' => 'Etabler flere felles komponenter for tabeller, statuser, kort, modaler og tomtilstander.',
            'acceptance_criteria' => 'Nye og eksisterende sider bruker et tydelig sett med gjenbrukbare UI-komponenter.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-16 21:20:00',
            'verification_notes' => "Arbeid startet 16. mai 2026. Fem delte React-komponenter er lagt til i resources/js/Components/App/Shared/:\n\n- StatusBadge — generisk statusindikatorbadge for gjenbruk på tvers av sider (9a44051, 2026-05-16)\n- EmptyStateBox — standardisert tom-tilstand-visning (008822d, 2026-05-16)\n- AlertBox — gjenbrukbart varselkomponent (9c2d4ca, 2026-05-16)\n- DepartmentCheckboxGroup — felles avdelingsvelger for bruker- og profilsider (9a44051, 2026-05-16)\n- FormButtonRow — standardisert knapperekke for skjemabunntekst (14d15f2, 2026-05-16)\n\nGjenstår: videre konsolidering av gjenbruksmønstre for tabeller, kort og modaler. Akseptansekriterie ikke fullt ut oppfylt.",
        ]);

        // --- Testing ---

        $this->seed([
            'code' => 'AVVIK-023',
            'title' => 'Ingen ende-til-ende-tester',
            'category' => OperationalDeviation::CATEGORY_TESTING,
            'severity' => OperationalDeviation::SEVERITY_HIGH,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => 'Det finnes ikke Playwright, Cypress, Dusk eller tilsvarende E2E-tester.',
            'impact' => 'Kritiske brukerflyter kan feile uten at testene oppdager det.',
            'recommended_action' => 'Legg til E2E-tester for login, kunngjøring, lagring, AI-krav, billing og admin.',
            'acceptance_criteria' => 'Minst de mest kritiske brukerflytene er dekket av E2E-test.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-15 22:00:00',
            'ready_for_verification_at' => '2026-05-15 23:00:00',
            'verified_at' => '2026-05-15 23:30:00',
            'closed_at' => '2026-05-15 23:30:00',
            'verification_notes' => 'Playwright E2E-oppsett innført (tests/e2e/). Chromium-baserte E2E-tester dekker: login-side, innlogging som kundebruker og super admin, ugyldig innlogging, admin-tilgang for super admin (kan se operasjonelle avvik), admin-blokkering for kundebrukere (CATEGORY_TESTING canAccess() = false), appnavigasjon (kunngjøringer, dashboard, AI), billing-tilgang for system owner og blokkering for vanlig bruker. Testdata opprettes via idempotent E2ETestSeeder. Ingen eksterne API-kall (OpenAI, Stripe, Doffin) gjøres i E2E-testene. Dokumentert i docs/testing/e2e.md.',
        ]);

        $this->seed([
            'code' => 'AVVIK-024',
            'title' => 'Ingen AI-output kvalitetstester',
            'category' => OperationalDeviation::CATEGORY_TESTING,
            'severity' => OperationalDeviation::SEVERITY_HIGH,
            'status' => OperationalDeviation::STATUS_IN_PROGRESS,
            'description' => 'Pipelines testes teknisk, men det finnes ikke god nok kvalitetstest av AI-output.',
            'impact' => 'Svar kan være teknisk generert, men faglig svake.',
            'recommended_action' => 'Etabler evalueringssett og QA-tests for AI-svar.',
            'acceptance_criteria' => 'AI-svar testes mot kjente krav og forventet kvalitet.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-16 22:49:00',
            'verification_notes' => "Arbeid startet 16. mai 2026.\n\nAVVIK-024A (feature-tester, 2026-05-16): Fem deterministiske tester lagt til i tests/Feature/App/AiControllerTest.php. Ingen ekte OpenAI-kall — alle bruker bind*Service-infrastruktur med Http::fake().\n- T1: Generering blokkeres og ingenting persisteres ved rød knowledge_grounding\n- T2: Generering blokkeres og ingenting persisteres når judge sier kan ikke generere\n- T3: Retrieval-kilder persisteres etter vellykket generering og overlever payload-readback\n- T4: answer_draft_payload returnerer generation_state=generated og retrieval_sources for lagret utkast\n- T5: answer_draft_payload returnerer null generation_state for krav uten utkast\n\nAVVIK-024B (unit-tester, 2026-05-16): Fire unit-tester lagt til i tests/Unit/Services/RequirementAnswerDraftServiceTest.php — kjøres uten Docker.\n- Ugyldig JSON fra OpenAI kaster RuntimeException uten DB-skriv\n- Manglende answer_draft_text-felt kaster RuntimeException\n- Tom tekst kaster RuntimeException\n- Gyldig minimal respons parses og persisteres korrekt\n\nGjenstår:\n- T6: RequirementAnswerDraftService med ugyldig JSON (delvis dekket av 024B)\n- T7: Grønn kvalitet uten retrieval_sources\n- Faglige evalueringstester mot kjente krav og forventet innhold",
        ]);

        // --- Dokumentasjon ---

        $this->seed([
            'code' => 'AVVIK-025',
            'title' => 'Ingen brukervendt dokumentasjon og onboarding-guide',
            'category' => OperationalDeviation::CATEGORY_DOCUMENTATION,
            'severity' => OperationalDeviation::SEVERITY_HIGH,
            'status' => OperationalDeviation::STATUS_NEW,
            'description' => 'Det mangler dokumentasjon for sluttbrukere.',
            'impact' => 'Nye brukere kan få lav mestring og feil bruk av systemet.',
            'recommended_action' => 'Lag kom-i-gang-guide for bid managers, selgere og adminbrukere.',
            'acceptance_criteria' => 'Ny bruker kan forstå grunnflyten uten utviklerhjelp.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
        ]);

        $this->seed([
            'code' => 'AVVIK-026',
            'title' => 'Ingen admin-guide for drift, billing og avvik',
            'category' => OperationalDeviation::CATEGORY_DOCUMENTATION,
            'severity' => OperationalDeviation::SEVERITY_MEDIUM,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => 'Det mangler samlet adminrettet dokumentasjon for drift, billing og avvik.',
            'impact' => 'Intern drift blir personavhengig.',
            'recommended_action' => 'Lag admin-guide for sentrale Filament-moduler.',
            'acceptance_criteria' => 'Intern admin kan bruke og vedlikeholde drift, billing og avvik uten chat-historikk.',
            'source' => 'Statusrapport 15. mai 2026',
            'source_date' => '2026-05-15',
            'started_at' => '2026-05-16 09:00:00',
            'ready_for_verification_at' => '2026-05-16 10:00:00',
            'verified_at' => '2026-05-16 10:15:00',
            'closed_at' => '2026-05-16 10:15:00',
            'verification_notes' => 'Intern admin-guide er etablert i docs/operations/admin-guide.md. Guiden dekker drift, avviksregister, backup/recovery, billing/fakturering, AI-bruk og kapasitet. Den beskriver tilgang og roller, daglige/ukentlige/månedlige rutiner, produksjonskontroll etter deploy og håndtering av feil. Guiden reduserer personavhengighet i intern drift. Ingen secrets, passord, tokens eller reelle kundedata er inkludert i dokumentasjonen.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seed(array $attributes): void
    {
        // AVVIK-009 was incorrectly closed while the acceptance criteria for cost control were no
        // longer met. Unlike historical seed records, this one must actively reopen existing
        // installations when the seeder is run.
        if (($attributes['code'] ?? null) === 'AVVIK-009') {
            OperationalDeviation::query()->updateOrCreate(
                ['code' => $attributes['code']],
                $attributes,
            );

            return;
        }

        OperationalDeviation::query()->firstOrCreate(
            ['code' => $attributes['code']],
            $attributes,
        );
    }
}
