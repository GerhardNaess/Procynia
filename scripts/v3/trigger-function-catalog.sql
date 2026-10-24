-- V3 (external-api-phase-1-decisions.md §11): the application's own triggers and functions, one line
-- each, in a fixed order, so two databases can be compared with diff.
--
-- Read only. Run with psql:
--   psql -X -q -At -v ON_ERROR_STOP=1 -f scripts/v3/trigger-function-catalog.sql
-- The reference from the locally verified database is docs/testing/v3-trigger-function-catalog.txt;
-- scripts/v3/compare-trigger-function-catalog.sh does the comparison.
--
-- Not included: internal triggers (foreign keys) and objects that belong to an extension
-- (vector, amcheck). The md5 is over pg_get_triggerdef()/pg_get_functiondef(), which carry no owner,
-- so a different database role in Azure does not change the line. Compare only within the same
-- PostgreSQL major version (16).

\set ON_ERROR_STOP on
BEGIN TRANSACTION READ ONLY;

WITH triggers AS (
    SELECT c.relname AS table_name, t.tgname AS trigger_name, md5(pg_get_triggerdef(t.oid)) AS definition_md5
    FROM pg_trigger t
    JOIN pg_class c ON c.oid = t.tgrelid
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE NOT t.tgisinternal
      AND n.nspname = current_schema()
      AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_trigger'::regclass AND d.objid = t.oid AND d.deptype = 'e')
),
functions AS (
    SELECT p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ')' AS signature, md5(pg_get_functiondef(p.oid)) AS definition_md5
    FROM pg_proc p
    JOIN pg_namespace n ON n.oid = p.pronamespace
    WHERE n.nspname = current_schema()
      AND p.prokind IN ('f', 'p')
      AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_proc'::regclass AND d.objid = p.oid AND d.deptype = 'e')
)
SELECT line FROM (
    SELECT 0 AS part, 'count|triggers|' || (SELECT count(*) FROM triggers) AS line
    UNION ALL SELECT 0, 'count|functions|' || (SELECT count(*) FROM functions)
    UNION ALL SELECT 1, 'trigger|' || table_name || '|' || trigger_name || '|' || definition_md5 FROM triggers
    UNION ALL SELECT 2, 'function|' || signature || '|' || definition_md5 FROM functions
) catalog
ORDER BY part, line COLLATE "C";

ROLLBACK;
