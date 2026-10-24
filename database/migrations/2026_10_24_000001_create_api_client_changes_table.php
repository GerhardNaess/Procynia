<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who registered, changed, enabled or disabled a client application, and when (D13, point 5).
 *
 * Append-only, like customer_access_changes: the trigger refuses any change except nulling a
 * deleted actor, and any delete except along with the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_client_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_type', 32);
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->timestamp('created_at');

            $table->index(['api_client_id', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE api_client_changes
                ADD CONSTRAINT api_client_changes_change_type_check CHECK (change_type IN ('registered', 'changed', 'enabled', 'disabled'));

            CREATE OR REPLACE FUNCTION api_client_changes_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM api_clients WHERE id = OLD.api_client_id) THEN
                        RAISE EXCEPTION 'api_client_changes is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                IF (to_jsonb(NEW) - 'actor_user_id') IS DISTINCT FROM (to_jsonb(OLD) - 'actor_user_id')
                    OR (NEW.actor_user_id IS NOT NULL AND NEW.actor_user_id IS DISTINCT FROM OLD.actor_user_id)
                THEN
                    RAISE EXCEPTION 'api_client_changes is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER api_client_changes_immutable
                BEFORE UPDATE OR DELETE ON api_client_changes
                FOR EACH ROW EXECUTE FUNCTION api_client_changes_immutable();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS api_client_changes_immutable ON api_client_changes;
                DROP FUNCTION IF EXISTS api_client_changes_immutable();
                SQL);
        }

        Schema::dropIfExists('api_client_changes');
    }
};
