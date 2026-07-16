<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_ai_requirement_wiki_answers', function (Blueprint $table): void {
            $table->timestampTz('stale_at')->nullable()->after('generated_at');
            $table->string('stale_reason')->nullable()->after('stale_at');
            $table->jsonb('stale_context')->nullable()->after('stale_reason');
        });
    }

    public function down(): void
    {
        Schema::table('saved_notice_ai_requirement_wiki_answers', function (Blueprint $table): void {
            $table->dropColumn(['stale_at', 'stale_reason', 'stale_context']);
        });
    }
};
