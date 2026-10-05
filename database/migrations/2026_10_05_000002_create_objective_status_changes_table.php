<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The history of an objective's status: every closing and every reopening, as it was made.
 *
 * objectives.status and its closed_* columns say where the objective stands now; this table says
 * how it got there. A reopening clears the closed_* columns on the objective, so the closing it
 * undoes survives only here — which is why a row is never changed or deleted by the application
 * (ObjectiveStatusChange throws), and why a reopening must say why (note required).
 *
 * changed_at is the only time column: a row is written once, so created_at/updated_at would be a
 * second copy of the same moment. changed_by_user_id is nulled when that user is deleted, like
 * every other "who" column in Procynia; the row itself stays.
 *
 * Deleting the objective (objective.delete, for one registered by mistake) takes its history along.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('objective_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('objective_id')->constrained('objectives')->cascadeOnDelete();
            $table->string('from_status');
            $table->string('to_status');
            $table->text('note')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->index(['objective_id', 'changed_at']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            $statuses = "'active', 'achieved', 'not_achieved', 'cancelled'";
            DB::statement("ALTER TABLE objective_status_changes ADD CONSTRAINT objective_status_changes_statuses CHECK (from_status IN ({$statuses}) AND to_status IN ({$statuses}))");
            // Only two kinds of change exist: closing an active objective, and reopening a closed one.
            DB::statement("ALTER TABLE objective_status_changes ADD CONSTRAINT objective_status_changes_direction CHECK ((from_status = 'active' AND to_status <> 'active') OR (from_status <> 'active' AND to_status = 'active'))");
            // Reopening undoes a decision, so it always says why.
            DB::statement("ALTER TABLE objective_status_changes ADD CONSTRAINT objective_status_changes_reopen_note CHECK (to_status <> 'active' OR length(btrim(coalesce(note, ''))) > 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('objective_status_changes');
    }
};
