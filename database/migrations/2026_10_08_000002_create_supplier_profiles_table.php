<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leverandørprofil: structured facts about the delivery — data, access, supply chain, work and
 * public contract terms — that later decide which control requirements apply to the supplier
 * (docs/supplier-assurance-v2-plan.md §4, §20.2). Not an assessment, a score or a status.
 *
 * Current state, one row per supplier (supplier_id is the primary key), written only by
 * SupplierProfileService together with a supplier_profile_changes row. No backfill: a supplier has
 * no row until someone fills in the profile (§17).
 *
 * Every answer is nullable, and null means «not answered». The yes/no/unknown questions keep
 * «unknown» (Ikke avklart) apart from «no» — the requirement rules treat the two differently. The
 * two multi-choice lists are JSON arrays; [] is the answer «none of these», null not answered. The
 * codes inside the arrays are checked by the service; the database checks the shape.
 *
 * The supplier is referenced by (supplier_id, customer_id) with NO ACTION: a profile can never
 * describe another customer's supplier, and a supplier with a profile cannot be deleted. The
 * customer going takes both.
 */
return new class extends Migration
{
    private const ANSWERS = [
        'special_category_data',
        'stores_our_data',
        'confidential_information',
        'privileged_access',
        'uses_subcontractors',
        'production_outside_eea',
        'on_site_work',
        'labour_intensive',
        'public_contract_terms',
        'significant_environmental_impact',
    ];

    public function up(): void
    {
        Schema::create('supplier_profiles', function (Blueprint $table): void {
            $table->unsignedBigInteger('supplier_id')->primary();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('data_role')->nullable();
            $table->string('special_category_data')->nullable();
            $table->string('stores_our_data')->nullable();
            $table->string('confidential_information')->nullable();
            $table->string('privileged_access')->nullable();
            $table->string('data_location')->nullable();
            $table->string('uses_subcontractors')->nullable();
            $table->string('production_outside_eea')->nullable();
            $table->jsonb('high_risk_categories')->nullable();
            $table->string('on_site_work')->nullable();
            $table->string('labour_intensive')->nullable();
            $table->jsonb('sectors')->nullable();
            $table->string('public_contract_terms')->nullable();
            $table->string('significant_environmental_impact')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['supplier_id', 'customer_id'])->references(['id', 'customer_id'])->on('suppliers');
            $table->index('customer_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $answers = implode(' AND ', array_map(
            fn (string $answer): string => "({$answer} IS NULL OR {$answer} IN ('yes', 'no', 'unknown'))",
            self::ANSWERS,
        ));

        DB::statement("ALTER TABLE supplier_profiles ADD CONSTRAINT supplier_profiles_answers CHECK ({$answers})");
        DB::statement('ALTER TABLE supplier_profiles ADD CONSTRAINT supplier_profiles_choices CHECK ('
            ."(data_role IS NULL OR data_role IN ('processor', 'controller', 'unknown'))"
            ." AND (data_location IS NULL OR data_location IN ('norway', 'eea', 'outside_eea', 'unknown')))");
        DB::statement('ALTER TABLE supplier_profiles ADD CONSTRAINT supplier_profiles_lists CHECK ('
            ."(high_risk_categories IS NULL OR jsonb_typeof(high_risk_categories) = 'array')"
            ." AND (sectors IS NULL OR jsonb_typeof(sectors) = 'array'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_profiles');
    }
};
