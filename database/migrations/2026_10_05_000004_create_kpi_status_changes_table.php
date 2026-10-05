<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The history of a KPI's status: every retirement and every reopening, as it was made.
 *
 * The same shape and rules as objective_status_changes. kpis.status says whether the KPI is
 * measured now; this table says from when and until when it was, and why that changed — what the
 * coming measurement step needs to tell a period nobody had to report from a measurement that is
 * missing. Never changed or deleted by the application (KpiStatusChange throws); a reopening always
 * says why.
 *
 * Deleting the KPI (for one registered by mistake) takes its history along.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kpi_id')->constrained('kpis')->cascadeOnDelete();
            $table->string('from_status');
            $table->string('to_status');
            $table->text('note')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->index(['kpi_id', 'changed_at']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Only two kinds of change exist: retiring an active KPI, and reopening a retired one.
            DB::statement("ALTER TABLE kpi_status_changes ADD CONSTRAINT kpi_status_changes_direction CHECK ((from_status = 'active' AND to_status = 'retired') OR (from_status = 'retired' AND to_status = 'active'))");
            // Reopening undoes a decision, so it always says why.
            DB::statement("ALTER TABLE kpi_status_changes ADD CONSTRAINT kpi_status_changes_reopen_note CHECK (to_status <> 'active' OR length(btrim(coalesce(note, ''))) > 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_status_changes');
    }
};
