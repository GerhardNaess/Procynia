<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_page_versions', function (Blueprint $table): void {
            $table->json('content_blocks_json')->nullable()->after('content_markdown');
        });

        Schema::table('enterprise_wiki_claims', function (Blueprint $table): void {
            $table->string('content_block_key')->nullable()->after('page_excerpt');
            $table->json('review_metadata')->nullable()->after('review_reason');

            $table->index('content_block_key');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_claims', function (Blueprint $table): void {
            $table->dropIndex(['content_block_key']);
            $table->dropColumn(['content_block_key', 'review_metadata']);
        });

        Schema::table('enterprise_wiki_page_versions', function (Blueprint $table): void {
            $table->dropColumn('content_blocks_json');
        });
    }
};
