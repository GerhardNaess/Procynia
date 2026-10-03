<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence is history: it outlives the file it pointed at.
 *
 * The FK keeps cascadeOnDelete — every other capacity is a statement about a file and goes with it.
 * Evidence is released from its file before the file is deleted instead
 * (QualityItemDocument::releaseEvidenceFromDocument(), called by
 * EnterpriseWikiDocumentDeletionService), and this column records that it happened, so the page
 * can tell "never had a file" apart from "the file is gone".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_item_documents', function (Blueprint $table): void {
            $table->timestamp('document_removed_at')->nullable()->after('enterprise_wiki_document_id');
        });
    }

    public function down(): void
    {
        Schema::table('quality_item_documents', function (Blueprint $table): void {
            $table->dropColumn('document_removed_at');
        });
    }
};
