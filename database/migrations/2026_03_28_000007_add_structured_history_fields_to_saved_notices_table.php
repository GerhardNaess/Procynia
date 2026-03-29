<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->decimal('contract_value_mnok', 12, 2)->nullable();
            $table->unsignedInteger('contract_period_months')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->dropColumn([
                'contract_value_mnok',
                'contract_period_months',
            ]);
        });
    }
};
