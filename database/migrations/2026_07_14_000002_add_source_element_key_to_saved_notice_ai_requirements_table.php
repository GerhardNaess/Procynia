<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_ai_requirements', function (Blueprint $table): void {
            $table->string('source_element_key')->nullable()->after('source_row_key');
            $table->string('source_element_type')->nullable()->after('source_element_key');
            $table->index('source_element_key');
        });
    }

    public function down(): void
    {
        Schema::table('saved_notice_ai_requirements', function (Blueprint $table): void {
            $table->dropIndex(['source_element_key']);
            $table->dropColumn(['source_element_key', 'source_element_type']);
        });
    }
};
