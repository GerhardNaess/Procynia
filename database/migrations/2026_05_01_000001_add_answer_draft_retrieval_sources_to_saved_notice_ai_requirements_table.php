<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_ai_requirements', function (Blueprint $table): void {
            $table->json('answer_draft_retrieval_sources')
                ->nullable()
                ->after('answer_draft_generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('saved_notice_ai_requirements', function (Blueprint $table): void {
            $table->dropColumn('answer_draft_retrieval_sources');
        });
    }
};
