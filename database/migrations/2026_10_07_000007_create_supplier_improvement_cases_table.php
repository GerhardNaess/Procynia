<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Avvik og forbedringer hos leverandøren (docs/supplier-management-v1-plan.md §7.4, §10): which
 * cases in Avvik og forbedringer concern a supplier, and nothing about them. The case's type,
 * status, owner, frist, tiltak and verification stay in improvement_cases and are read through
 * that module's access rules when the supplier page is shown — never copied here.
 *
 * origin says how the row came about: «handoff» — the case was created from the supplier (or from
 * one of its assessments, then supplier_assessment_id says which) — or «linked», an existing case
 * connected afterwards. A supplier may have any number of cases; a case is listed once per supplier.
 *
 * handoff_key is the form's one-time key for «Følg opp i Avvik og forbedringer»: a second submit of
 * the same form finds the first row and creates no second case.
 *
 * Keys:
 *  - (supplier_id, customer_id) → suppliers, NO ACTION: never another customer's supplier, and a
 *    supplier with cases is ended, not deleted.
 *  - (improvement_case_id, customer_id) → improvement_cases, CASCADE: never another customer's case,
 *    and a case Avvik og forbedringer allows to be deleted takes the row with it
 *    (ImprovementCase::isDeletable() is unchanged).
 *  - (supplier_assessment_id, supplier_id) → supplier_assessments(id, supplier_id), NO ACTION: the
 *    assessment is always one of this supplier's. Assessments are never deleted anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_assessments', function (Blueprint $table): void {
            $table->unique(['id', 'supplier_id']);
        });

        Schema::create('supplier_improvement_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('improvement_case_id');
            $table->unsignedBigInteger('supplier_assessment_id')->nullable();
            $table->string('origin');
            $table->uuid('handoff_key')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->foreign(['supplier_id', 'customer_id'])->references(['id', 'customer_id'])->on('suppliers');
            $table->foreign(['improvement_case_id', 'customer_id'])->references(['id', 'customer_id'])->on('improvement_cases')->cascadeOnDelete();
            $table->foreign(['supplier_assessment_id', 'supplier_id'])->references(['id', 'supplier_id'])->on('supplier_assessments');
            $table->unique(['supplier_id', 'improvement_case_id']);
            $table->unique(['customer_id', 'handoff_key']);
            $table->index('improvement_case_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE supplier_improvement_cases ADD CONSTRAINT supplier_improvement_cases_origin CHECK (origin IN ('handoff', 'linked'))");
        DB::statement("ALTER TABLE supplier_improvement_cases ADD CONSTRAINT supplier_improvement_cases_handoff_only CHECK (origin = 'handoff' OR (supplier_assessment_id IS NULL AND handoff_key IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_improvement_cases');

        Schema::table('supplier_assessments', function (Blueprint $table): void {
            $table->dropUnique(['id', 'supplier_id']);
        });
    }
};
