<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_metadata_term_suggestions', function (Blueprint $table): void {
            if (! Schema::hasColumn('knowledge_metadata_term_suggestions', 'batch_id')) {
                $table->foreignId('batch_id')
                    ->nullable()
                    ->after('source_chunk_id')
                    ->constrained('knowledge_vocabulary_analysis_batches')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('knowledge_metadata_term_suggestions', 'suggested_canonical_name')) {
                $table->string('suggested_canonical_name', 191)->nullable()->after('suggested_term');
            }

            if (! Schema::hasColumn('knowledge_metadata_term_suggestions', 'suggested_synonyms')) {
                $table->json('suggested_synonyms')->nullable()->after('suggested_type');
            }

            if (! Schema::hasColumn('knowledge_metadata_term_suggestions', 'suggested_description')) {
                $table->text('suggested_description')->nullable()->after('suggested_synonyms');
            }

            if (! Schema::hasColumn('knowledge_metadata_term_suggestions', 'related_existing_term_id')) {
                $table->foreignId('related_existing_term_id')
                    ->nullable()
                    ->after('suggested_canonical_parent')
                    ->constrained('knowledge_metadata_terms')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('knowledge_metadata_term_suggestions', 'confidence_score')) {
                $table->decimal('confidence_score', 5, 4)->nullable()->after('reason');
            }
        });

        DB::statement('ALTER TABLE knowledge_metadata_term_suggestions ALTER COLUMN source_chunk_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE knowledge_metadata_term_suggestions ALTER COLUMN source_chunk_id SET NOT NULL');

        Schema::table('knowledge_metadata_term_suggestions', function (Blueprint $table): void {
            if (Schema::hasColumn('knowledge_metadata_term_suggestions', 'confidence_score')) {
                $table->dropColumn('confidence_score');
            }

            if (Schema::hasColumn('knowledge_metadata_term_suggestions', 'related_existing_term_id')) {
                $table->dropConstrainedForeignId('related_existing_term_id');
            }

            if (Schema::hasColumn('knowledge_metadata_term_suggestions', 'suggested_description')) {
                $table->dropColumn('suggested_description');
            }

            if (Schema::hasColumn('knowledge_metadata_term_suggestions', 'suggested_synonyms')) {
                $table->dropColumn('suggested_synonyms');
            }

            if (Schema::hasColumn('knowledge_metadata_term_suggestions', 'suggested_canonical_name')) {
                $table->dropColumn('suggested_canonical_name');
            }

            if (Schema::hasColumn('knowledge_metadata_term_suggestions', 'batch_id')) {
                $table->dropConstrainedForeignId('batch_id');
            }
        });
    }
};
