-- V3 punkt (1): datakontrollen fra steg 19 (external-api-phase-0-plan.md §2m), gjenbrukbar.
--
-- Bare lesing. Alt kjøres i én READ ONLY-transaksjon som rulles tilbake, og skriptet oppretter
-- ingenting (ingen funksjoner, tabeller eller temp-tabeller). Resultatet kommer som NOTICE-linjer.
--
--   PGOPTIONS='-c default_transaction_read_only=on' \
--     psql "<tilkobling>" -X -q -v ON_ERROR_STOP=1 -f scripts/v3/decision-actor-data-check.sql
--
-- Kan kjøres både før og etter grenens migreringer. Før migreringene (en kopi av produksjonsdata)
-- finnes mange av tabellene og kolonnene ikke ennå. De rapporteres som HOPPET OVER, ikke som feil.
--
-- Linjene:
--   OK          kontrollen er kjørt og fant ingenting
--   STOPP       må være 0. V3 stoppes til funnet er forklart
--   VURDER      kan ha en lovlig forklaring (eldre rad fra før aktørkolonnen fantes, eller en
--               slettet person: kolonnene er nullOnDelete). Hver rad må vurderes før produksjon
--   HOPPET OVER tabellen eller kolonnen finnes ikke i denne databasen
--
-- Del A og B: kolonnelisten er den samme som COLUMNS i
-- database/migrations/2026_10_23_000014_reject_integration_actors_in_decision_columns.php
-- (44 kolonner i 36 tabeller). Finnes triggerne, sammenlignes listen med katalogen (del D).

\set ON_ERROR_STOP on
SET client_min_messages = notice;
BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;

DO $check$
DECLARE
    decision_columns constant text[][] := ARRAY[
        -- Enterprise Wiki
        ['enterprise_wiki_pages', 'reviewed_by_user_id'],
        ['enterprise_wiki_page_versions', 'submitted_by_user_id'],
        ['enterprise_wiki_page_versions', 'reviewer_user_id'],
        ['enterprise_wiki_page_versions', 'qa_assigned_by_user_id'],
        ['enterprise_wiki_claims', 'approved_by_user_id'],
        ['enterprise_wiki_claims', 'blocking_override_by_user_id'],
        ['enterprise_wiki_claim_decisions', 'decided_by_user_id'],
        ['enterprise_wiki_page_version_document_owner_approvals', 'decided_by_user_id'],
        ['enterprise_wiki_page_version_document_owner_approvals', 'overridden_by_user_id'],
        ['enterprise_wiki_document_owner_decisions', 'decided_by_user_id'],
        ['enterprise_wiki_page_review_events', 'actor_user_id'],
        -- Risiko
        ['risk_acceptances', 'accepted_by_user_id'],
        ['risk_acceptances', 'revoked_by_user_id'],
        ['risk_assessments', 'assessed_by'],
        -- Avvik og forbedringer
        ['improvement_cases', 'closed_by_user_id'],
        ['improvement_action_verifications', 'verified_by_user_id'],
        -- Mål og KPI
        ['kpi_measurements', 'recorded_by_user_id'],
        ['kpi_measurements', 'withdrawn_by_user_id'],
        ['objectives', 'closed_by_user_id'],
        ['objective_status_changes', 'changed_by_user_id'],
        ['kpi_status_changes', 'changed_by_user_id'],
        -- Etterlevelse og revisjon
        ['compliance_assessments', 'assessed_by_user_id'],
        ['compliance_audit_status_changes', 'changed_by_user_id'],
        ['compliance_audit_finding_changes', 'changed_by_user_id'],
        ['compliance_requirements', 'applicability_set_by'],
        ['compliance_requirement_applicability_changes', 'changed_by_user_id'],
        -- Leverandøroppfølging
        ['supplier_assessments', 'assessed_by_user_id'],
        ['supplier_requirement_evaluations', 'evaluated_by_user_id'],
        ['supplier_requirement_overrides', 'created_by_user_id'],
        ['supplier_due_diligence_assessments', 'assessed_by_user_id'],
        ['supplier_criticality_changes', 'changed_by_user_id'],
        ['supplier_status_changes', 'changed_by_user_id'],
        ['supplier_control_requirement_status_changes', 'changed_by_user_id'],
        ['supplier_assurance_decisions', 'decided_by_user_id'],
        -- Ledelsens gjennomgang
        ['management_reviews', 'finalized_by_user_id'],
        ['management_review_amendments', 'created_by_user_id'],
        -- Kvalitet
        ['quality_process_revisions', 'approved_by_user_id'],
        ['quality_process_blueprints', 'approved_by_user_id'],
        -- Anbud
        ['saved_notice_ai_requirements', 'approved_by_user_id'],
        ['saved_notice_ai_requirements', 'rejected_by_user_id'],
        ['saved_notice_status_changes', 'changed_by_user_id'],
        ['saved_notice_no_go_decisions', 'closed_by_user_id'],
        ['saved_notice_no_go_decisions', 'reopened_by_user_id'],
        -- Tilgang (F4)
        ['customer_access_changes', 'actor_user_id']
    ];

    -- Del C: avgjorte rader som skal ha en aktør. {kontroll, tabell, aktørkolonne, vilkår for «avgjort»,
    -- kolonner vilkåret bruker (kommaseparert)}. Samme utvalg som steg 19.
    decided_rows constant text[][] := ARRAY[
        ['Godkjente Wiki-sider', 'enterprise_wiki_pages', 'reviewed_by_user_id', 'status = ''approved''', 'status'],
        ['Godkjente Wiki-påstander', 'enterprise_wiki_claims', 'approved_by_user_id', 'approval_status = ''approved''', 'approval_status'],
        ['Godkjente anbudskrav', 'saved_notice_ai_requirements', 'approved_by_user_id', 'approval_status = ''approved''', 'approval_status'],
        ['Avviste anbudskrav', 'saved_notice_ai_requirements', 'rejected_by_user_id', 'approval_status = ''rejected''', 'approval_status'],
        ['Lukkede avvik', 'improvement_cases', 'closed_by_user_id', 'status = ''closed''', 'status'],
        ['Ferdigstilte ledelsens gjennomganger', 'management_reviews', 'finalized_by_user_id', 'status = ''finalized''', 'status'],
        ['Godkjente prosessflyter', 'quality_process_blueprints', 'approved_by_user_id', 'status = ''approved''', 'status'],
        ['Godkjente prosessrevisjoner', 'quality_process_revisions', 'approved_by_user_id', 'approved_at IS NOT NULL', 'approved_at'],
        ['Avgjorte dokumenteierkrav', 'enterprise_wiki_page_version_document_owner_approvals', 'COALESCE(decided_by_user_id, overridden_by_user_id)', 'approval_status IN (''approved'', ''rejected'')', 'approval_status,decided_by_user_id,overridden_by_user_id'],
        ['Tilbaketrukne KPI-målinger', 'kpi_measurements', 'withdrawn_by_user_id', 'withdrawn_at IS NOT NULL', 'withdrawn_at'],
        ['Tilbakekalte risikoaksepter', 'risk_acceptances', 'revoked_by_user_id', 'revoked_at IS NOT NULL', 'revoked_at'],
        ['Risikoaksepter', 'risk_acceptances', 'accepted_by_user_id', 'accepted_at IS NOT NULL', 'accepted_at']
    ];

    -- Navnet som er bevart når personen er slettet (nullOnDelete), der tabellen har et:
    -- {tabell, aktørkolonne, navnekolonne}.
    preserved_names constant text[][] := ARRAY[
        ['management_reviews', 'finalized_by_user_id', 'finalized_by_name'],
        ['quality_process_revisions', 'approved_by_user_id', 'approved_by_name']
    ];

    i int;
    n bigint;
    table_name text;
    column_name text;
    actor_expression text;
    missing text;
    checked int := 0;
    skipped int := 0;
    stops int := 0;
    actor_stops int := 0;
    reviews int := 0;
    has_role boolean;
    catalog_columns text[];
    listed_columns text[] := ARRAY[]::text[];
    extra text[];
    absent text[];
BEGIN
    RAISE NOTICE 'V3 datakontroll – database %, %', current_database(), to_char(now(), 'YYYY-MM-DD HH24:MI:SS TZ');

    has_role := EXISTS (
        SELECT 1 FROM information_schema.columns c
        WHERE c.table_schema = current_schema() AND c.table_name = 'users' AND c.column_name = 'role'
    );

    -- Del A: integrasjonsidentiteter -------------------------------------------------------------
    RAISE NOTICE '--- A. Integrasjonsidentiteter';

    IF NOT has_role THEN
        RAISE NOTICE 'HOPPET OVER  A1 users.role finnes ikke';
    ELSE
        SELECT count(*) INTO n FROM users WHERE role = 'integration';
        RAISE NOTICE 'INFO         A1 integrasjonsidentiteter (users.role = integration): %', n;

        IF to_regclass('api_client_customer_grants') IS NULL THEN
            SELECT count(*) INTO n FROM users WHERE role = 'integration';
            IF n = 0 THEN
                RAISE NOTICE 'OK           A2 ingen integrasjonsidentitet, og tildelingstabellen finnes ikke ennå';
            ELSE
                stops := stops + 1;
                RAISE NOTICE 'STOPP        A2 % integrasjonsidentitet(er) uten tildelingstabell (api_client_customer_grants finnes ikke)', n;
            END IF;
        ELSE
            -- A2: en identitet uten tildeling er ikke opprettet gjennom ApiClientGrantService.
            SELECT count(*) INTO n FROM users u
            WHERE u.role = 'integration'
              AND NOT EXISTS (SELECT 1 FROM api_client_customer_grants g WHERE g.integration_user_id = u.id);
            IF n = 0 THEN RAISE NOTICE 'OK           A2 hver integrasjonsidentitet har en tildeling';
            ELSE stops := stops + 1; RAISE NOTICE 'STOPP        A2 % integrasjonsidentitet(er) uten tildeling', n; END IF;

            -- A3: en tildeling som peker på en person.
            SELECT count(*) INTO n FROM api_client_customer_grants g
            JOIN users u ON u.id = g.integration_user_id
            WHERE u.role IS DISTINCT FROM 'integration';
            IF n = 0 THEN RAISE NOTICE 'OK           A3 hver tildeling peker på en integrasjonsidentitet';
            ELSE stops := stops + 1; RAISE NOTICE 'STOPP        A3 % tildeling(er) peker på en bruker som ikke er integrasjon', n; END IF;

            -- A4: identiteten tilhører en annen kunde enn tildelingen.
            SELECT count(*) INTO n FROM api_client_customer_grants g
            JOIN users u ON u.id = g.integration_user_id
            WHERE u.customer_id IS DISTINCT FROM g.customer_id;
            IF n = 0 THEN RAISE NOTICE 'OK           A4 identitet og tildeling har samme kunde';
            ELSE stops := stops + 1; RAISE NOTICE 'STOPP        A4 % identitet(er) med annen kunde enn tildelingen', n; END IF;

            -- A5: tilbakekalt tildeling med aktiv identitet (§16: «Trekk tilbake» deaktiverer identiteten).
            SELECT count(*) INTO n FROM api_client_customer_grants g
            JOIN users u ON u.id = g.integration_user_id
            WHERE g.revoked_at IS NOT NULL AND u.is_active;
            IF n = 0 THEN RAISE NOTICE 'OK           A5 ingen tilbakekalt tildeling har aktiv identitet';
            ELSE stops := stops + 1; RAISE NOTICE 'STOPP        A5 % tilbakekalt(e) tildeling(er) med aktiv identitet', n; END IF;
        END IF;

        -- A6: menneskelige markører på en integrasjon. Koden ser bort fra dem (§16), men de skal ikke stå der.
        SELECT count(*) INTO n FROM users u
        WHERE u.role = 'integration'
          AND (u.bid_role IN ('system_owner', 'bid_manager')
               OR (to_jsonb(u) ->> 'is_qa')::boolean IS TRUE
               OR (to_jsonb(u) ->> 'is_wiki_approver')::boolean IS TRUE);
        IF n = 0 THEN RAISE NOTICE 'OK           A6 ingen integrasjon har System Owner, Bid Manager, QA eller Wiki-godkjenner på raden';
        ELSE reviews := reviews + 1; RAISE NOTICE 'VURDER       A6 % integrasjon(er) med menneskelig rolle eller markør på raden', n; END IF;
    END IF;

    -- Del B: integrasjon som aktør i en beslutningskolonne ---------------------------------------
    RAISE NOTICE '--- B. Integrasjon som aktør i beslutningskolonnene (44 kolonner, 36 tabeller)';

    FOR i IN 1 .. array_length(decision_columns, 1) LOOP
        table_name := decision_columns[i][1];
        column_name := decision_columns[i][2];
        listed_columns := listed_columns || (table_name || '.' || column_name);

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns c
            WHERE c.table_schema = current_schema() AND c.table_name = decision_columns[i][1] AND c.column_name = decision_columns[i][2]
        ) THEN
            skipped := skipped + 1;
            RAISE NOTICE 'HOPPET OVER  B  %.% finnes ikke', table_name, column_name;
            CONTINUE;
        END IF;

        IF NOT has_role THEN
            skipped := skipped + 1;
            CONTINUE;
        END IF;

        EXECUTE format(
            'SELECT count(*) FROM %I t JOIN users u ON u.id = t.%I WHERE u.role = ''integration''',
            table_name, column_name
        ) INTO n;
        checked := checked + 1;

        IF n > 0 THEN
            stops := stops + 1;
            actor_stops := actor_stops + 1;
            RAISE NOTICE 'STOPP        B  %.%: % rad(er) med integrasjon som aktør', table_name, column_name, n;
        END IF;
    END LOOP;

    RAISE NOTICE 'INFO         B  % kolonner kontrollert, % hoppet over', checked, skipped;
    IF checked > 0 AND actor_stops = 0 THEN
        RAISE NOTICE 'OK           B  ingen integrasjon er registrert som aktør i en kontrollert kolonne';
    END IF;

    -- Del C: avgjort rad uten aktør --------------------------------------------------------------
    RAISE NOTICE '--- C. Avgjort rad uten aktør';

    FOR i IN 1 .. array_length(decided_rows, 1) LOOP
        table_name := decided_rows[i][2];
        actor_expression := decided_rows[i][3];

        SELECT string_agg(needed, ', ') INTO missing
        FROM unnest(string_to_array(decided_rows[i][5], ',') || CASE WHEN actor_expression LIKE 'COALESCE%' THEN ARRAY[]::text[] ELSE ARRAY[actor_expression] END) AS needed
        WHERE NOT EXISTS (
            SELECT 1 FROM information_schema.columns c
            WHERE c.table_schema = current_schema() AND c.table_name = decided_rows[i][2] AND c.column_name = needed
        );

        IF missing IS NOT NULL THEN
            RAISE NOTICE 'HOPPET OVER  C  % (%: mangler %)', decided_rows[i][1], table_name, missing;
            CONTINUE;
        END IF;

        EXECUTE format('SELECT count(*) FROM %I WHERE (%s) AND %s IS NULL', table_name, decided_rows[i][4], actor_expression) INTO n;

        IF n = 0 THEN
            RAISE NOTICE 'OK           C  %', decided_rows[i][1];
            CONTINUE;
        END IF;

        reviews := reviews + 1;
        RAISE NOTICE 'VURDER       C  %: % rad(er) uten aktør (%)', decided_rows[i][1], n, table_name;
    END LOOP;

    -- Navn bevart der personen er slettet: forklarer en VURDER-linje over.
    FOR i IN 1 .. array_length(preserved_names, 1) LOOP
        IF EXISTS (
            SELECT 1 FROM information_schema.columns c
            WHERE c.table_schema = current_schema() AND c.table_name = preserved_names[i][1] AND c.column_name = preserved_names[i][3]
        ) THEN
            EXECUTE format(
                'SELECT count(*) FROM %I WHERE %I IS NULL AND %I IS NOT NULL',
                preserved_names[i][1], preserved_names[i][2], preserved_names[i][3]
            ) INTO n;
            IF n > 0 THEN
                RAISE NOTICE 'INFO         C  %: % rad(er) uten aktør-id, men med bevart navn i % (personen er slettet)', preserved_names[i][1], n, preserved_names[i][3];
            END IF;
        END IF;
    END LOOP;

    -- Del D: listen over mot triggerkatalogen -----------------------------------------------------
    RAISE NOTICE '--- D. Kolonnelisten mot triggerne human_decision_actor';

    SELECT array_agg(c.relname || '.' || arg ORDER BY 1)
    INTO catalog_columns
    FROM pg_trigger t
    JOIN pg_class c ON c.oid = t.tgrelid
    JOIN pg_namespace ns ON ns.oid = c.relnamespace
    CROSS JOIN LATERAL unnest(string_to_array(encode(t.tgargs, 'escape'), E'\\000')) AS arg
    WHERE t.tgname = 'human_decision_actor' AND ns.nspname = current_schema() AND arg <> '';

    IF catalog_columns IS NULL THEN
        RAISE NOTICE 'HOPPET OVER  D  triggerne finnes ikke (databasen er ikke migrert med 000014)';
    ELSE
        SELECT array_agg(x) INTO extra FROM unnest(catalog_columns) x WHERE x <> ALL (listed_columns);
        SELECT array_agg(x) INTO absent FROM unnest(listed_columns) x WHERE x <> ALL (catalog_columns);

        IF extra IS NULL AND absent IS NULL THEN
            RAISE NOTICE 'OK           D  skriptets 44 kolonner er de samme som triggernes (%)', array_length(catalog_columns, 1);
        ELSE
            stops := stops + 1;
            RAISE NOTICE 'STOPP        D  listen og triggerne er ulike. Bare i triggerne: %. Bare i skriptet: %', extra, absent;
        END IF;
    END IF;

    RAISE NOTICE '--- Resultat: % STOPP, % VURDER', stops, reviews;
END
$check$;

ROLLBACK;
