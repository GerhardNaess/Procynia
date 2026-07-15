<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_ai_requirement_wiki_answers', function (Blueprint $table): void {
            $table->jsonb('alignment_trace')->nullable()->after('engine_version');
            $table->boolean('has_possible_conflict')->nullable()->after('alignment_trace');
        });
    }

    public function down(): void
    {
        Schema::table('saved_notice_ai_requirement_wiki_answers', function (Blueprint $table): void {
            $table->dropColumn(['alignment_trace', 'has_possible_conflict']);
        });
    }
};
