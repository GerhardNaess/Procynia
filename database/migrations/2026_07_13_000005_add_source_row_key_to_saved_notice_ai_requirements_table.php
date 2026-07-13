<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_ai_requirements', function (Blueprint $table): void {
            $table->string('source_row_key')->nullable()->after('extraction_metadata');
            $table->index('source_row_key');
        });
    }

    public function down(): void
    {
        Schema::table('saved_notice_ai_requirements', function (Blueprint $table): void {
            $table->dropIndex(['source_row_key']);
            $table->dropColumn('source_row_key');
        });
    }
};
