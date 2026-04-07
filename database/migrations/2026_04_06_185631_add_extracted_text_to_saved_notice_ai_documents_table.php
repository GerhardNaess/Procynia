<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_ai_documents', function (Blueprint $table): void {
            $table->longText('extracted_text')->nullable()->after('processing_status');
            $table->timestamp('text_extracted_at')->nullable()->after('extracted_text');
        });
    }

    public function down(): void
    {
        Schema::table('saved_notice_ai_documents', function (Blueprint $table): void {
            $table->dropColumn([
                'extracted_text',
                'text_extracted_at',
            ]);
        });
    }
};
