<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tiltak: the concrete things to be done on an avvik or a forbedring, each with one person
 * responsible (Ansvarlig) and a Frist.
 *
 * A tiltak has no fagområde of its own. It belongs to a case and is reached only through it, so the
 * case's area decides who can see and change it. (improvement_case_id, customer_id) references the
 * case's own pair, so the database refuses a tiltak under another customer's case.
 *
 * owner_user_id is required by the form but nulled when that user is deleted; the tiltak stays and
 * the page says «Mangler ansvarlig». due_date is required and independent of the case's frist.
 *
 * status is the lifecycle: planned → in_progress → completed / cancelled, and back to planned by a
 * reopening. ImprovementActionLifecycleService is the only writer. completed_* and completion_note
 * («Hva ble gjort?») describe the current completion and are empty exactly while the tiltak is not
 * completed — the check below holds that. A cancellation's reason and every earlier completion live
 * in improvement_action_status_changes.
 *
 * Deleting the case cascades only for the customer going: a case with tiltak is never deletable
 * through the product (ImprovementCase::isDeletable()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('improvement_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('improvement_case_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date');
            $table->string('status')->default('planned');
            $table->text('completion_note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['improvement_case_id', 'customer_id'])->references(['id', 'customer_id'])->on('improvement_cases')->cascadeOnDelete();
            $table->unique(['id', 'customer_id']);
            $table->index(['improvement_case_id', 'status']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE improvement_actions ADD CONSTRAINT improvement_actions_status_check CHECK (status IN ('planned', 'in_progress', 'completed', 'cancelled'))");
            // completed_by_user_id is left out of the completed branch on purpose: it is nulled when
            // that user is deleted.
            DB::statement('ALTER TABLE improvement_actions ADD CONSTRAINT improvement_actions_completion_consistency CHECK ('
                ."(status <> 'completed' AND completed_at IS NULL AND completed_by_user_id IS NULL AND completion_note IS NULL)"
                ." OR (status = 'completed' AND completed_at IS NOT NULL AND length(btrim(coalesce(completion_note, ''))) > 0))");
            DB::statement('ALTER TABLE improvement_actions ADD CONSTRAINT improvement_actions_title_check CHECK (length(btrim(title)) > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('improvement_actions');
    }
};
