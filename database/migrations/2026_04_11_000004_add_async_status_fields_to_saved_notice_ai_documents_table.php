<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_ai_documents', function (Blueprint $table): void {
            $table->timestampTz('queued_at')->nullable()->after('text_extracted_at');
            $table->timestampTz('processing_started_at')->nullable()->after('queued_at');
            $table->timestampTz('processing_finished_at')->nullable()->after('processing_started_at');
            $table->string('processing_error_type', 64)->nullable()->after('processing_finished_at');
            $table->longText('processing_error_message')->nullable()->after('processing_error_type');
        });
    }

    public function down(): void
    {
        Schema::table('saved_notice_ai_documents', function (Blueprint $table): void {
            $table->dropColumn([
                'queued_at',
                'processing_started_at',
                'processing_finished_at',
                'processing_error_type',
                'processing_error_message',
            ]);
        });
    }
};
