<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_prices', function (Blueprint $table): void {
            $table->unsignedInteger('included_ai_offers')->default(0)->after('included_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('billing_prices', function (Blueprint $table): void {
            $table->dropColumn('included_ai_offers');
        });
    }
};
