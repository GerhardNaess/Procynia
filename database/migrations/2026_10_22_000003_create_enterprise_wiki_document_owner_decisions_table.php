<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every decision a document owner (or a System Owner on their behalf) takes on a requirement, as an
 * event — decision B9, docs/external-api-phase-0-plan.md §4.10.
 *
 * enterprise_wiki_page_version_document_owner_approvals holds the CURRENT state of a requirement,
 * and a second decision used to overwrite the first without a trace. This table is the history:
 * append-only, one row per decision, never updated. A changed decision is a new row with its reason.
 *
 * Rows go with their requirement (a deleted page or version cascades) and with their customer;
 * nothing else may delete or change one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_document_owner_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained(indexName: 'ewdod_customer_fk')->cascadeOnDelete();
            $table->foreignId('approval_id')
                ->constrained('enterprise_wiki_page_version_document_owner_approvals', indexName: 'ewdod_approval_fk')
                ->cascadeOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users', indexName: 'ewdod_decided_by_fk')->nullOnDelete();
            $table->string('previous_status', 32);
            $table->string('decision', 32);
            $table->text('comment')->nullable();
            $table->boolean('is_override')->default(false);
            $table->timestampTz('decided_at');
            $table->timestamps();

            $table->index(['approval_id', 'decided_at'], 'ewdod_approval_decided_index');
        });

        // The first event for every requirement that is already decided, so the history starts
        // where the current state does instead of at this migration.
        DB::statement(<<<'SQL'
            INSERT INTO enterprise_wiki_document_owner_decisions
                (customer_id, approval_id, decided_by_user_id, previous_status, decision, comment, is_override, decided_at, created_at, updated_at)
            SELECT customer_id, id, decided_by_user_id, 'pending', approval_status, approval_comment, is_override,
                   COALESCE(decided_at, updated_at, CURRENT_TIMESTAMP), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM enterprise_wiki_page_version_document_owner_approvals
            WHERE approval_status IN ('approved', 'rejected')
            ORDER BY id
            SQL);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE enterprise_wiki_document_owner_decisions ADD CONSTRAINT ewdod_decision_check CHECK (decision IN ('approved', 'rejected') AND previous_status IN ('pending', 'approved', 'rejected'))");

        // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enterprise_wiki_document_owner_decisions_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                        AND EXISTS (SELECT 1 FROM enterprise_wiki_page_version_document_owner_approvals WHERE id = OLD.approval_id)
                    THEN
                        RAISE EXCEPTION 'enterprise_wiki_document_owner_decisions is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                -- nullOnDelete of the deciding user is the one change allowed.
                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                    OR NEW.approval_id IS DISTINCT FROM OLD.approval_id
                    OR NEW.previous_status IS DISTINCT FROM OLD.previous_status
                    OR NEW.decision IS DISTINCT FROM OLD.decision
                    OR NEW.comment IS DISTINCT FROM OLD.comment
                    OR NEW.is_override IS DISTINCT FROM OLD.is_override
                    OR NEW.decided_at IS DISTINCT FROM OLD.decided_at
                    OR (NEW.decided_by_user_id IS NOT NULL AND NEW.decided_by_user_id IS DISTINCT FROM OLD.decided_by_user_id)
                THEN
                    RAISE EXCEPTION 'enterprise_wiki_document_owner_decisions is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER enterprise_wiki_document_owner_decisions_immutable
                BEFORE UPDATE OR DELETE ON enterprise_wiki_document_owner_decisions
                FOR EACH ROW EXECUTE FUNCTION enterprise_wiki_document_owner_decisions_immutable();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_document_owner_decisions');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS enterprise_wiki_document_owner_decisions_immutable()');
        }
    }
};
