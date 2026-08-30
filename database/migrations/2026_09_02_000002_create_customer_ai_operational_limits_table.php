<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-customer operational NOK safety budgets.
 *
 * Deliberately not part of the plan or the commercial quota: this protects Procynia's own spend,
 * so it can stop even a customer whose plan says unlimited AI cases. Absent or disabled means no
 * operational ceiling for that customer — only the global one applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_ai_operational_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->decimal('daily_nok_limit', 14, 2)->nullable();
            $table->decimal('monthly_nok_limit', 14, 2)->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ai_operational_limits');
    }
};
