<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI instruction is one shared instruction per customer, not per case. Schema change only —
 * saved_notices.ai_instructions is left in place as an unused transitional column and is
 * deliberately not migrated over; it is dropped in a separate later cleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->text('ai_instructions')->nullable()->after('permission_settings');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('ai_instructions');
        });
    }
};
