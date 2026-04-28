<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            if (! Schema::hasColumn('knowledge_item_chunks', 'matched_terms')) {
                $table->json('matched_terms')->nullable()->after('section_path');
            }

            if (! Schema::hasColumn('knowledge_item_chunks', 'summary_for_retrieval')) {
                $table->text('summary_for_retrieval')->nullable()->after('matched_terms');
            }

            if (! Schema::hasColumn('knowledge_item_chunks', 'confidence_score')) {
                $table->decimal('confidence_score', 6, 4)->nullable()->after('summary_for_retrieval');
            }

            if (! Schema::hasColumn('knowledge_item_chunks', 'metadata_status')) {
                $table->string('metadata_status', 32)->default('pending_review')->after('confidence_score');
            }
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            if (Schema::hasColumn('knowledge_item_chunks', 'metadata_status')) {
                $table->dropColumn('metadata_status');
            }

            if (Schema::hasColumn('knowledge_item_chunks', 'confidence_score')) {
                $table->dropColumn('confidence_score');
            }

            if (Schema::hasColumn('knowledge_item_chunks', 'summary_for_retrieval')) {
                $table->dropColumn('summary_for_retrieval');
            }

            if (Schema::hasColumn('knowledge_item_chunks', 'matched_terms')) {
                $table->dropColumn('matched_terms');
            }
        });
    }
};
