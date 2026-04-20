<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_ai_requirements', function (Blueprint $table): void {
            $table->longText('answer_draft_text')
                ->nullable()
                ->after('current_requirement_snapshot');
            $table->timestampTz('answer_draft_generated_at')
                ->nullable()
                ->after('answer_draft_text');
        });
    }

    public function down(): void
    {
        Schema::table('saved_notice_ai_requirements', function (Blueprint $table): void {
            $table->dropColumn([
                'answer_draft_text',
                'answer_draft_generated_at',
            ]);
        });
    }
};
