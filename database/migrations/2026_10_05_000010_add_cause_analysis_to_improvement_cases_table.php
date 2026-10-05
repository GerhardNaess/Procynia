<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Årsak og bakgrunn: why an avvik happened, or what lies behind a forbedring. One optional text on
 * the case — deliberately not a root-cause method (no 5 Why, no categories). It is written while
 * the case is open or under arbeid and left as it stood once the case has ended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('improvement_cases', function (Blueprint $table): void {
            $table->text('cause_analysis')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('improvement_cases', function (Blueprint $table): void {
            $table->dropColumn('cause_analysis');
        });
    }
};
