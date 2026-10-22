<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ledelsens gjennomgang: a review becomes finalized only with its basis frozen — phase 0 step 11
 * (docs/external-api-phase-0-plan.md §2e).
 *
 * A finalized review was already locked (management_reviews_finalized_locked), but the step into
 * finalized was not: an UPDATE from draft to finalized with a finalized_at and a name passed the CHECK
 * without a snapshot and without a finalizing user — a «ferdigstilt» review with no frozen basis.
 * ManagementReviewFinalizationService writes the snapshot rows first, in the same transaction, so
 * this changes nothing for it.
 *
 * Only the transition is checked. No row is changed (dev: one draft, no finalized review).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION management_reviews_finalize_with_snapshot() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'draft' AND NEW.status = 'finalized' AND (
                    NEW.finalized_by_user_id IS NULL
                    OR NOT EXISTS (SELECT 1 FROM management_review_snapshot_sections WHERE management_review_id = NEW.id)
                ) THEN
                    RAISE EXCEPTION 'a management review is finalized only with its snapshot and the person who finalized it';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER management_reviews_finalize_with_snapshot
                BEFORE UPDATE OF status ON management_reviews
                FOR EACH ROW EXECUTE FUNCTION management_reviews_finalize_with_snapshot();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS management_reviews_finalize_with_snapshot ON management_reviews;
            DROP FUNCTION IF EXISTS management_reviews_finalize_with_snapshot();
            SQL);
    }
};
