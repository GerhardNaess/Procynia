<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A35 (docs/external-api-phase-0-plan.md §2i): each Relevans or Kontrollvalg decision on a library
 * requirement — from, to, the begrunnelse, who and when — in an append-only table, in the pattern of
 * compliance_requirement_status_changes. The library's own starting point (activation) is not a
 * decision and writes no row.
 *
 * Refuses UPDATE and DELETE while the customer exists; only a deleted user's foreign key may become
 * NULL. OR REPLACE: migrate:fresh drops tables but leaves functions behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_requirement_applicability_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('requirement_id');
            $table->string('from_applicability');
            $table->string('to_applicability');
            $table->text('rationale')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->foreign(['requirement_id', 'customer_id'])->references(['id', 'customer_id'])->on('compliance_requirements')->cascadeOnDelete();
            $table->index(['requirement_id', 'changed_at']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION compliance_requirement_applicability_changes_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                        RAISE EXCEPTION 'compliance_requirement_applicability_changes is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                    OR NEW.requirement_id IS DISTINCT FROM OLD.requirement_id
                    OR NEW.from_applicability IS DISTINCT FROM OLD.from_applicability
                    OR NEW.to_applicability IS DISTINCT FROM OLD.to_applicability
                    OR NEW.rationale IS DISTINCT FROM OLD.rationale
                    OR NEW.changed_at IS DISTINCT FROM OLD.changed_at
                    OR (NEW.changed_by_user_id IS NOT NULL AND NEW.changed_by_user_id IS DISTINCT FROM OLD.changed_by_user_id)
                THEN
                    RAISE EXCEPTION 'compliance_requirement_applicability_changes is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER compliance_requirement_applicability_changes_immutable
                BEFORE UPDATE OR DELETE ON compliance_requirement_applicability_changes
                FOR EACH ROW EXECUTE FUNCTION compliance_requirement_applicability_changes_immutable();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS compliance_requirement_applicability_changes_immutable ON compliance_requirement_applicability_changes;
                DROP FUNCTION IF EXISTS compliance_requirement_applicability_changes_immutable();
                SQL);
        }

        Schema::dropIfExists('compliance_requirement_applicability_changes');
    }
};
