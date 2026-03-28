<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notice_cpv_codes', function (Blueprint $table) {
            $table->text('cpv_description_en')->nullable()->after('cpv_code');
            $table->text('cpv_description_no')->nullable()->after('cpv_description_en');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notice_cpv_codes', function (Blueprint $table) {
            $table->dropColumn([
                'cpv_description_en',
                'cpv_description_no',
            ]);
        });
    }
};
