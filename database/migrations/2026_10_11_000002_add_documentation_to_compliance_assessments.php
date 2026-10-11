<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumentasjon on an etterlevelsesvurdering: what the assessment points to as its basis — a Wiki
 * article, a document reference, an external link — as the assessor wrote it. Optional, free text,
 * one reference per line; a line that is a link is shown as one.
 *
 * Deliberately not a documentation model of its own and not a link table: the assessment already is
 * the record of the judgement, and what it relied on belongs to that same moment. Like the rest of
 * the row it is history — the immutability trigger is widened to cover it, so a reference can never
 * be changed after the assessment is saved. A correction is a new assessment, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_assessments', function (Blueprint $table): void {
            $table->text('documentation')->nullable()->after('rationale');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared($this->immutableFunction(withDocumentation: true));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared($this->immutableFunction(withDocumentation: false));
        }

        Schema::table('compliance_assessments', function (Blueprint $table): void {
            $table->dropColumn('documentation');
        });
    }

    /** The trigger function from 2026_10_06_000004, with or without the documentation column. */
    private function immutableFunction(bool $withDocumentation): string
    {
        $documentation = $withDocumentation ? "\n                    OR NEW.documentation IS DISTINCT FROM OLD.documentation" : '';

        return <<<SQL
            CREATE OR REPLACE FUNCTION compliance_assessments_immutable() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                        RAISE EXCEPTION 'compliance_assessments is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                    OR NEW.requirement_id IS DISTINCT FROM OLD.requirement_id
                    OR NEW.result IS DISTINCT FROM OLD.result
                    OR NEW.rationale IS DISTINCT FROM OLD.rationale{$documentation}
                    OR NEW.assessed_at IS DISTINCT FROM OLD.assessed_at
                    OR NEW.requirement_reference IS DISTINCT FROM OLD.requirement_reference
                    OR NEW.requirement_title IS DISTINCT FROM OLD.requirement_title
                    OR NEW.requirement_text IS DISTINCT FROM OLD.requirement_text
                    OR NEW.source_name IS DISTINCT FROM OLD.source_name
                    OR NEW.source_version IS DISTINCT FROM OLD.source_version
                    OR (NEW.assessed_by_user_id IS NOT NULL AND NEW.assessed_by_user_id IS DISTINCT FROM OLD.assessed_by_user_id)
                THEN
                    RAISE EXCEPTION 'compliance_assessments is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
            SQL;
    }
};
