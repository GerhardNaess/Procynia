<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Importer leverandører (docs/supplier-management-v1-plan.md, «Excel-import av leverandører»): one row
 * per uploaded Excel file.
 *
 * The file itself is never kept. It is read in the upload request, and only the cell values the
 * import understands are stored here as `rows`, so the preview can be shown again and the import
 * carried out later without the file. Carrying it out is a one-way step from `pending` to
 * `completed`, taken with this row locked: a double click or a retry finds it completed and changes
 * nothing. `rows` is cleared when the import completes; what happened is kept in `result`, with who
 * carried it out and when — the import's record of itself. A pending import nobody confirmed is
 * deleted after a day by suppliers:prune-imports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_name', 255);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('row_count');
            $table->jsonb('rows')->nullable();
            $table->jsonb('ignored_columns')->nullable();
            $table->boolean('update_existing')->default(false);
            $table->jsonb('result')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status', 'created_at'], 'supplier_imports_customer_status_index');
        });

        DB::statement("ALTER TABLE supplier_imports ADD CONSTRAINT supplier_imports_status_check CHECK (status IN ('pending', 'completed'))");
        // Pending holds the rows and no result; completed holds the result, when and no rows.
        DB::statement("ALTER TABLE supplier_imports ADD CONSTRAINT supplier_imports_state_check CHECK (
            (status = 'pending' AND rows IS NOT NULL AND result IS NULL AND completed_at IS NULL)
            OR (status = 'completed' AND rows IS NULL AND result IS NOT NULL AND completed_at IS NOT NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_imports');
    }
};
