<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed who may do what — F4, phase 0 step 18 (docs/external-api-phase-0-plan.md §4.8).
 *
 * One append-only row per change to a person's access (anbudsrolle, system role, QA, Wiki approver,
 * active, Bid Manager scope and departments, affiliation, customer roles), to a customer role (its
 * permissions, fagområder, active state, name, creation and deletion), and to the Tilganger matrix
 * (customers.permission_settings). before/after hold only access-relevant fields — never e-mail
 * or password. subject_id has no foreign key, so a deleted role or person keeps its history.
 *
 * The trigger refuses any change except nulling a deleted actor, and any delete except along with
 * the customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_access_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('change_type', 48);
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->timestamp('created_at');

            $table->index(['customer_id', 'subject_type', 'subject_id'], 'customer_access_changes_subject_index');
            $table->index(['customer_id', 'created_at'], 'customer_access_changes_time_index');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE customer_access_changes
                ADD CONSTRAINT customer_access_changes_subject_type_check CHECK (subject_type IN ('user', 'customer_role', 'permission_settings'));

            CREATE OR REPLACE FUNCTION customer_access_changes_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                        RAISE EXCEPTION 'customer_access_changes is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                -- nullOnDelete of the actor is the one change allowed.
                IF (to_jsonb(NEW) - 'actor_user_id') IS DISTINCT FROM (to_jsonb(OLD) - 'actor_user_id')
                    OR (NEW.actor_user_id IS NOT NULL AND NEW.actor_user_id IS DISTINCT FROM OLD.actor_user_id)
                THEN
                    RAISE EXCEPTION 'customer_access_changes is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER customer_access_changes_immutable
                BEFORE UPDATE OR DELETE ON customer_access_changes
                FOR EACH ROW EXECUTE FUNCTION customer_access_changes_immutable();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS customer_access_changes_immutable ON customer_access_changes;
                DROP FUNCTION IF EXISTS customer_access_changes_immutable();
                SQL);
        }

        Schema::dropIfExists('customer_access_changes');
    }
};
