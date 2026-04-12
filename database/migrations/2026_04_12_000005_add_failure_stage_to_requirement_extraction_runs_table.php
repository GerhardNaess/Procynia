<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requirement_extraction_runs', function (Blueprint $table): void {
            $table->string('failure_stage', 64)->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('requirement_extraction_runs', function (Blueprint $table): void {
            $table->dropColumn('failure_stage');
        });
    }
};
