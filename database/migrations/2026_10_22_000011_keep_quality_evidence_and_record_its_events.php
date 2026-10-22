<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kvalitet: evidence is withdrawn, never deleted, and its history is recorded — findings A29 and
 * (the part it needs of) A20, decided 2026-10-22 (docs/external-api-phase-0-plan.md §2h).
 *
 * 1. Evidence (quality_item_documents, relation_type evidence) gets a withdrawal: when, by whom.
 *    «Trekk tilbake» sets it; the row stays. Only one active row per file per item: the unique
 *    index now covers rows that are not withdrawn, so the same file can be registered again.
 * 2. quality_item_events: an append-only history for Kvalitet, starting with evidence registered
 *    and evidence withdrawn. The item id is kept without a foreign key, so an event outlives the
 *    row it is about; the model is described in the plan (§2h) for the events to come.
 * 3. Triggers:
 *    - an evidence row is never deleted while its item and customer exist; the only changes are
 *      the withdrawal (once, final), the release from a deleted file (document id to null,
 *      document_removed_at and a title from the filename), and foreign keys letting go of a
 *      deleted user;
 *    - an item with evidence — withdrawn evidence included, any item type — is not deleted
 *      (this widens quality_items_keep_control_history from controls to every type);
 *    - events are never changed or deleted, except a deleted user's id going to null and the
 *      customer's own deletion.
 *
 * Existing evidence keeps its rows as they are (none withdrawn); no events are back-filled — a row's
 * created_at and created_by_user_id are its registration record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_item_documents', function (Blueprint $table): void {
            $table->timestamp('withdrawn_at')->nullable()->after('document_removed_at');
            $table->foreignId('withdrawn_by_user_id')->nullable()->after('withdrawn_at')->constrained('users')->nullOnDelete();
            $table->string('withdrawn_by_name')->nullable()->after('withdrawn_by_user_id');
        });

        Schema::create('quality_item_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // No foreign key: the history outlives the item and the document link it is about.
            $table->unsignedBigInteger('quality_item_id');
            $table->unsignedBigInteger('quality_item_document_id')->nullable();
            $table->string('event', 64);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->timestamp('occurred_at');
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'quality_item_id', 'occurred_at'], 'quality_item_events_item_index');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE quality_item_documents DROP CONSTRAINT quality_item_documents_unique');
        DB::statement('CREATE UNIQUE INDEX quality_item_documents_unique ON quality_item_documents (quality_item_id, enterprise_wiki_document_id, relation_type) WHERE withdrawn_at IS NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE quality_item_documents
                ADD CONSTRAINT quality_item_documents_withdrawal_check CHECK (
                    (withdrawn_at IS NULL AND withdrawn_by_name IS NULL AND withdrawn_by_user_id IS NULL)
                    OR (withdrawn_at IS NOT NULL AND withdrawn_by_name IS NOT NULL AND relation_type = 'evidence')
                )
            SQL);

        // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION quality_item_documents_keep_evidence() RETURNS trigger AS $$
            DECLARE
                changeable text[] := ARRAY['withdrawn_at', 'withdrawn_by_user_id', 'withdrawn_by_name', 'enterprise_wiki_document_id', 'document_removed_at', 'title', 'created_by_user_id', 'updated_at'];
            BEGIN
                IF OLD.relation_type <> 'evidence' THEN
                    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                        AND EXISTS (SELECT 1 FROM quality_items WHERE id = OLD.quality_item_id)
                    THEN
                        RAISE EXCEPTION 'evidence % is history and is withdrawn, never deleted', OLD.id;
                    END IF;

                    RETURN OLD;
                END IF;

                IF (to_jsonb(NEW) - changeable) IS DISTINCT FROM (to_jsonb(OLD) - changeable)
                    OR (OLD.withdrawn_at IS NOT NULL AND (NEW.withdrawn_at IS DISTINCT FROM OLD.withdrawn_at OR NEW.withdrawn_by_name IS DISTINCT FROM OLD.withdrawn_by_name))
                    OR (NEW.withdrawn_by_user_id IS DISTINCT FROM OLD.withdrawn_by_user_id AND NEW.withdrawn_by_user_id IS NOT NULL AND OLD.withdrawn_at IS NOT NULL)
                    OR (NEW.enterprise_wiki_document_id IS DISTINCT FROM OLD.enterprise_wiki_document_id AND NEW.enterprise_wiki_document_id IS NOT NULL)
                    OR (OLD.document_removed_at IS NOT NULL AND NEW.document_removed_at IS DISTINCT FROM OLD.document_removed_at)
                    OR (OLD.title IS NOT NULL AND NEW.title IS DISTINCT FROM OLD.title)
                    OR (NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id AND NEW.created_by_user_id IS NOT NULL)
                THEN
                    RAISE EXCEPTION 'evidence % is history: only a withdrawal or the release from a deleted file may change it', OLD.id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER quality_item_documents_keep_evidence
                BEFORE UPDATE OR DELETE ON quality_item_documents
                FOR EACH ROW EXECUTE FUNCTION quality_item_documents_keep_evidence();

            CREATE OR REPLACE FUNCTION quality_items_keep_control_history() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                    AND EXISTS (SELECT 1 FROM quality_item_documents WHERE quality_item_id = OLD.id AND relation_type = 'evidence')
                THEN
                    RAISE EXCEPTION 'quality item % has registered evidence and cannot be deleted; retire it instead', OLD.id;
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION quality_item_events_keep() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.actor_user_id IS NULL
                        AND (to_jsonb(NEW) - 'actor_user_id') = (to_jsonb(OLD) - 'actor_user_id')
                    THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'quality_item_events is history and cannot be changed';
                END IF;

                IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                    RAISE EXCEPTION 'quality_item_events is history and cannot be deleted';
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER quality_item_events_keep
                BEFORE UPDATE OR DELETE ON quality_item_events
                FOR EACH ROW EXECUTE FUNCTION quality_item_events_keep();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS quality_item_events_keep ON quality_item_events;
                DROP FUNCTION IF EXISTS quality_item_events_keep();
                DROP TRIGGER IF EXISTS quality_item_documents_keep_evidence ON quality_item_documents;
                DROP FUNCTION IF EXISTS quality_item_documents_keep_evidence();

                CREATE OR REPLACE FUNCTION quality_items_keep_control_history() RETURNS trigger AS $$
                BEGIN
                    IF OLD.quality_type = 'control'
                        AND EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                        AND EXISTS (SELECT 1 FROM quality_item_documents WHERE quality_item_id = OLD.id AND relation_type = 'evidence')
                    THEN
                        RAISE EXCEPTION 'quality control % has registered evidence and cannot be deleted; retire it instead', OLD.id;
                    END IF;

                    RETURN OLD;
                END;
                $$ LANGUAGE plpgsql;
                SQL);

            DB::statement('ALTER TABLE quality_item_documents DROP CONSTRAINT IF EXISTS quality_item_documents_withdrawal_check');
            DB::statement('DROP INDEX IF EXISTS quality_item_documents_unique');
        }

        Schema::dropIfExists('quality_item_events');

        Schema::table('quality_item_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('withdrawn_by_user_id');
            $table->dropColumn(['withdrawn_at', 'withdrawn_by_name']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Only possible while no file is linked twice in one capacity, which a withdrawal allows.
            DB::statement('ALTER TABLE quality_item_documents ADD CONSTRAINT quality_item_documents_unique UNIQUE (quality_item_id, enterprise_wiki_document_id, relation_type)');
        }
    }
};
