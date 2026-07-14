<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_ai_requirement_wiki_answers', function (Blueprint $table): void {
            $table->jsonb('research_trace')->nullable()->after('sources');
            $table->string('engine_version')->nullable()->after('research_trace');
        });
    }

    public function down(): void
    {
        Schema::table('saved_notice_ai_requirement_wiki_answers', function (Blueprint $table): void {
            $table->dropColumn(['research_trace', 'engine_version']);
        });
    }
};
