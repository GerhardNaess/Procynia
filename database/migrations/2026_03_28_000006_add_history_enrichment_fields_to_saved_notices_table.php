<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->string('selected_supplier_name')->nullable();
            $table->string('contract_value')->nullable();
            $table->string('contract_period_text')->nullable();
            $table->timestamp('next_process_date_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->dropColumn([
                'selected_supplier_name',
                'contract_value',
                'contract_period_text',
                'next_process_date_at',
            ]);
        });
    }
};
