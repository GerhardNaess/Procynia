<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_qa_snapshots', function (Blueprint $table): void {
            $table->unsignedBigInteger('semantic_post_repair_page_version_id')
                ->nullable()
                ->after('semantic_repair_model')
                ->comment('Page version reviewed during post-repair re-evaluation (8G-7 lineage)');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_qa_snapshots', function (Blueprint $table): void {
            $table->dropColumn('semantic_post_repair_page_version_id');
        });
    }
};
