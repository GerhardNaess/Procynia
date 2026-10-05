<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mål — what the virksomhet wants to achieve, in one fagområde, with one person responsible.
 *
 * The fagområde is the access scope, exactly as for risks: who can reach an objective is decided
 * by business_area_id through ObjectiveAccessService, never by anything else on the row. The FK is
 * restrict for the same reason as on risks — an area that still holds objectives must not be
 * deleted from under them (BusinessArea::SCOPED_CONTENT_TABLES).
 *
 * owner_user_id is required by every form but nullable here, and nulled when the user is deleted:
 * removing a person must never be blocked by the objectives they owned. An objective left without
 * an owner is a state to draw attention to, not one to prevent.
 *
 * target_date null means a running objective with no end date. There is no start date in v1.
 *
 * status is the lifecycle: every objective is created active, and leaves it only by being closed
 * as achieved, not achieved or cancelled. The closed_* columns describe the current closure and are
 * empty exactly while the objective is active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('objectives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_area_id')->constrained('business_areas')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('target_date')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closing_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'business_area_id']);
            $table->index(['customer_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE objectives ADD CONSTRAINT objectives_status_check CHECK (status IN ('active', 'achieved', 'not_achieved', 'cancelled'))");
            // closed_by_user_id is left out on purpose: it is nulled when that user is deleted.
            DB::statement("ALTER TABLE objectives ADD CONSTRAINT objectives_closed_consistency CHECK ((status = 'active' AND closed_at IS NULL AND closed_by_user_id IS NULL) OR (status <> 'active' AND closed_at IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('objectives');
    }
};
