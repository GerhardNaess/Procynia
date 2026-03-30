<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doffin_notices', function (Blueprint $table): void {
            $table->id();
            $table->string('notice_id')->unique();
            $table->string('notice_type')->nullable();
            $table->text('heading')->nullable();
            $table->timestamp('publication_date')->nullable();
            $table->timestamp('issue_date')->nullable();
            $table->text('buyer_name')->nullable();
            $table->string('buyer_org_id')->nullable();
            $table->json('cpv_codes_json')->nullable();
            $table->json('place_of_performance_json')->nullable();
            $table->decimal('estimated_value_amount', 18, 2)->nullable();
            $table->string('estimated_value_currency_code')->nullable();
            $table->string('estimated_value_display')->nullable();
            $table->json('awarded_names_json')->nullable();
            $table->json('raw_payload_json')->nullable();
            $table->timestamp('last_harvested_at')->nullable();
            $table->timestamps();

            $table->index('notice_type');
            $table->index('publication_date');
            $table->index('last_harvested_at');
        });

        Schema::create('doffin_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('supplier_name');
            $table->string('organization_number')->nullable();
            $table->string('normalized_name');
            $table->timestamps();

            $table->unique('organization_number');
            $table->index('normalized_name');
        });

        Schema::create('doffin_notice_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doffin_notice_id')->constrained('doffin_notices')->cascadeOnDelete();
            $table->foreignId('doffin_supplier_id')->constrained('doffin_suppliers')->cascadeOnDelete();
            $table->json('winner_lots_json')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();

            $table->unique(['doffin_notice_id', 'doffin_supplier_id'], 'doffin_notice_supplier_unique_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doffin_notice_suppliers');
        Schema::dropIfExists('doffin_suppliers');
        Schema::dropIfExists('doffin_notices');
    }
};
