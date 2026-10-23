<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anbud: every stored generation and hand-edit of a Wiki answer, as it was — P5,
 * docs/external-api-phase-0-plan.md §2l.
 *
 * The answer row holds the current text only: a new generation or edit replaced it in place. One
 * append-only row per stored generation and per edit now keeps the text, the sources it stood on
 * (with the exact Wiki versions, see RequirementWikiAnswerService::sourcesPayload), who did it and
 * when. Written in the same transaction as the answer row. The trigger refuses any change except
 * nulling a deleted person, and any delete except along with the requirement or the case — the
 * same rule as saved_notice_ai_requirement_revisions.
 *
 * No backfill: what existing answers looked like before their last write was never recorded, and
 * is not invented here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_notice_ai_requirement_wiki_answer_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_notice_ai_requirement_id')
                ->constrained('saved_notice_ai_requirements', indexName: 'swa_answer_revisions_requirement_fk')
                ->cascadeOnDelete();
            $table->foreignId('saved_notice_id')->constrained('saved_notices')->cascadeOnDelete();
            $table->string('event', 32);
            $table->text('answer_text');
            $table->string('coverage_status', 64)->nullable();
            $table->jsonb('sources')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['saved_notice_ai_requirement_id', 'id'], 'swa_answer_revisions_requirement_index');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE saved_notice_ai_requirement_wiki_answer_revisions
                ADD CONSTRAINT swa_answer_revisions_event_check CHECK (event IN ('generated', 'edited'));

            CREATE OR REPLACE FUNCTION saved_notice_ai_requirement_wiki_answer_revisions_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM saved_notices WHERE id = OLD.saved_notice_id)
                        AND EXISTS (SELECT 1 FROM saved_notice_ai_requirements WHERE id = OLD.saved_notice_ai_requirement_id)
                    THEN
                        RAISE EXCEPTION 'saved_notice_ai_requirement_wiki_answer_revisions is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                -- nullOnDelete of the person is the one change allowed.
                IF (to_jsonb(NEW) - 'actor_user_id') IS DISTINCT FROM (to_jsonb(OLD) - 'actor_user_id')
                    OR (NEW.actor_user_id IS NOT NULL AND NEW.actor_user_id IS DISTINCT FROM OLD.actor_user_id)
                THEN
                    RAISE EXCEPTION 'saved_notice_ai_requirement_wiki_answer_revisions is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER saved_notice_ai_requirement_wiki_answer_revisions_immutable
                BEFORE UPDATE OR DELETE ON saved_notice_ai_requirement_wiki_answer_revisions
                FOR EACH ROW EXECUTE FUNCTION saved_notice_ai_requirement_wiki_answer_revisions_immutable();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS saved_notice_ai_requirement_wiki_answer_revisions_immutable ON saved_notice_ai_requirement_wiki_answer_revisions;
                DROP FUNCTION IF EXISTS saved_notice_ai_requirement_wiki_answer_revisions_immutable();
                SQL);
        }

        Schema::dropIfExists('saved_notice_ai_requirement_wiki_answer_revisions');
    }
};
