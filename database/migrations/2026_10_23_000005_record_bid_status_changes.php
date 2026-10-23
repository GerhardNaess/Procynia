<?php

use App\Support\MigrationSchemaPrecondition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anbud: who changed a bid status — phase 0 step 17 (docs/external-api-phase-0-plan.md §2l).
 *
 * saved_notice_status_changes: one row per change of a case's bid status, written in the same
 * transaction by SavedNoticeStatusService — from, to, who, when, and the closure or reopening
 * reason. Before this, only a No-Go named its decider; Go, Levert, Vunnet, Tapt and Trukket named
 * nobody. Nothing is backfilled: earlier changes have no known actor and are not invented.
 *
 * A row is never changed, except nulling a deleted user, and goes only with its case or customer
 * (recognised as elsewhere: the parent row is already gone when the cascade arrives).
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationSchemaPrecondition::requireTables(basename(__FILE__, '.php'), 'saved_notices');

        if (! Schema::hasTable('saved_notice_status_changes')) {
            Schema::create('saved_notice_status_changes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('saved_notice_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->string('from_status', 32)->nullable();
                $table->string('to_status', 32);
                $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('reason')->nullable();
                $table->text('note')->nullable();
                $table->timestamp('changed_at');
                $table->timestamp('created_at')->nullable();

                $table->index(['saved_notice_id', 'changed_at']);
            });
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION saved_notice_status_changes_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                        AND EXISTS (SELECT 1 FROM saved_notices WHERE id = OLD.saved_notice_id)
                    THEN
                        RAISE EXCEPTION 'saved_notice_status_changes is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                -- nullOnDelete of the person is the one change allowed.
                IF (to_jsonb(NEW) - 'changed_by_user_id') IS DISTINCT FROM (to_jsonb(OLD) - 'changed_by_user_id')
                    OR (NEW.changed_by_user_id IS NOT NULL AND NEW.changed_by_user_id IS DISTINCT FROM OLD.changed_by_user_id)
                THEN
                    RAISE EXCEPTION 'saved_notice_status_changes is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER saved_notice_status_changes_immutable
                BEFORE UPDATE OR DELETE ON saved_notice_status_changes
                FOR EACH ROW EXECUTE FUNCTION saved_notice_status_changes_immutable();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS saved_notice_status_changes_immutable ON saved_notice_status_changes;
                DROP FUNCTION IF EXISTS saved_notice_status_changes_immutable();
                SQL);
        }

        Schema::dropIfExists('saved_notice_status_changes');
    }
};
