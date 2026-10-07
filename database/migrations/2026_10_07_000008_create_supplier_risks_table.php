<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Risikoer som gjelder leverandøren (docs/supplier-management-v1-plan.md §7.2, §10): which risks in
 * Risiko concern a supplier, and nothing about them. The risk's level, status, owner, treatment and
 * assessments stay in Risiko and are read through RiskAccessService when the supplier page is
 * shown — never copied here.
 *
 * origin says how the row came about: «created_from_supplier» — the risk was registered from the
 * supplier through RiskCreator — or «linked», an existing risk connected afterwards. A supplier may
 * have any number of risks, and a risk may concern any number of suppliers; a risk is listed once
 * per supplier.
 *
 * Keys:
 *  - (supplier_id, customer_id) → suppliers, NO ACTION: never another customer's supplier, and a
 *    supplier with risks is ended, not deleted.
 *  - (risk_id, customer_id) → risks, CASCADE: never another customer's risk, and a risk Risiko allows
 *    to be deleted takes the row with it (Risiko's delete rule is unchanged). Needs the new
 *    unique(id, customer_id) on risks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risks', function (Blueprint $table): void {
            $table->unique(['id', 'customer_id']);
        });

        Schema::create('supplier_risks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('risk_id');
            $table->string('origin');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->foreign(['supplier_id', 'customer_id'])->references(['id', 'customer_id'])->on('suppliers');
            $table->foreign(['risk_id', 'customer_id'])->references(['id', 'customer_id'])->on('risks')->cascadeOnDelete();
            $table->unique(['supplier_id', 'risk_id']);
            $table->index('risk_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE supplier_risks ADD CONSTRAINT supplier_risks_origin CHECK (origin IN ('created_from_supplier', 'linked'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_risks');

        Schema::table('risks', function (Blueprint $table): void {
            $table->dropUnique(['id', 'customer_id']);
        });
    }
};
