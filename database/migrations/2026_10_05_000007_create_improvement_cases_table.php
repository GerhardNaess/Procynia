<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Avvik og forbedringer — one table for both. A case is an avvik («noe er ikke som ønsket») or a
 * forbedring («noe kan bli bedre»); everything after registration — ansvar, behandling, lukking,
 * historikk — is the same workflow, so the type is a column, not a second table.
 *
 * Scoped by fagområde like risks and objectives: business_area_id decides who can reach the case
 * (ImprovementCaseAccessService), and the area cannot be deleted while it holds one
 * (BusinessArea::SCOPED_CONTENT_TABLES).
 *
 * owner_user_id is required by the forms but nulled when that user is deleted, like an objective's.
 * occurred_at (Hendelsesdato) is a day, only for avvik. due_date (Frist) is when the case should be
 * handled; both are optional.
 *
 * status is the lifecycle: open → in_progress → closed / cancelled, and back to open by a reopening.
 * It is never a form field; ImprovementCaseLifecycleService is the only writer. closed_* and
 * closing_note describe the current ending (Lukket or Avbrutt) and are empty exactly while the case
 * is open or in progress — the checks below hold that, and that an ending always says why.
 *
 * (id, customer_id) is unique so that link tables can reference the pair and let the database
 * refuse a link across customers (see the process context migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('improvement_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_area_id')->constrained('business_areas')->restrictOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('description');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('occurred_at')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status')->default('open');
            $table->text('closing_note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id', 'customer_id']);
            $table->index(['customer_id', 'business_area_id']);
            $table->index(['customer_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE improvement_cases ADD CONSTRAINT improvement_cases_type_check CHECK (type IN ('deviation', 'improvement'))");
            DB::statement("ALTER TABLE improvement_cases ADD CONSTRAINT improvement_cases_status_check CHECK (status IN ('open', 'in_progress', 'closed', 'cancelled'))");
            // closed_by_user_id is left out on purpose: it is nulled when that user is deleted.
            DB::statement('ALTER TABLE improvement_cases ADD CONSTRAINT improvement_cases_ending_consistency CHECK ('
                ."(status IN ('open', 'in_progress') AND closed_at IS NULL AND closed_by_user_id IS NULL AND closing_note IS NULL)"
                ." OR (status IN ('closed', 'cancelled') AND closed_at IS NOT NULL AND length(btrim(coalesce(closing_note, ''))) > 0))");
            // Hendelsesdato belongs to an avvik; a forbedring has none.
            DB::statement("ALTER TABLE improvement_cases ADD CONSTRAINT improvement_cases_occurred_at_deviation_only CHECK (type = 'deviation' OR occurred_at IS NULL)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('improvement_cases');
    }
};
