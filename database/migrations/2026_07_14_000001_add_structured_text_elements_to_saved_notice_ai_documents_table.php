<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_ai_documents', function (Blueprint $table): void {
            $table->json('structured_text_elements')->nullable()->after('structured_tables');
        });
    }

    public function down(): void
    {
        Schema::table('saved_notice_ai_documents', function (Blueprint $table): void {
            $table->dropColumn('structured_text_elements');
        });
    }
};
