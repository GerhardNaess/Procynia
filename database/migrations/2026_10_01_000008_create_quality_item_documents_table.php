<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The seam between a styrende dokument and the files that belong to it.
 *
 * WHY A JOIN TABLE AND NOT COLUMNS ON quality_items.
 *
 * The same reason quality_item_wiki_links exists: a policy may carry the signed PDF, the form its
 * procedure uses and a year's worth of completed control records, and one template is used by half
 * the kvalitetssystem. Columns could express neither end of that.
 *
 * WHY enterprise_wiki_documents AND NOT A NEW quality_documents TABLE.
 *
 * enterprise_wiki_documents is already the virksomhet's uploaded-file store: customer-scoped,
 * private local storage, SHA-256 deduplicated, text-extracted, with an owner, a download route and
 * a deletion service that knows what a file is load-bearing for. Its name says Wiki because Wiki
 * was the first consumer, but nothing about the row is Wiki-specific, and `wiki_core` is a
 * mandatory module — every customer has it. A parallel quality_documents table would mean the same
 * PDF stored twice on disk, two deletion stories, and two answers to "has this file already been
 * uploaded". So Kvalitet points at the existing store rather than growing its own.
 *
 * WHAT THIS IS NOT.
 *
 * It is not quality_item_wiki_links with a different target. A Wiki link reaches the *knowledge*
 * the virksomhet has written down about a subject; a document link reaches a *file* the document
 * consists of, uses or leaves behind. The two are separate seams with separate vocabularies, and
 * the detail page keeps them in separate sections for that reason.
 *
 * NO GRAPH PROJECTION.
 *
 * Documents are not nodes in the Neo4j projection today — GraphProjectionService knows wiki pages
 * and quality items, nothing else — so there is deliberately nothing to project here. Introducing a
 * whole document layer in the graph to carry this one edge would be building the general case for a
 * single caller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_item_documents', function (Blueprint $table): void {
            // Denormalised from the item, the way every other quality table carries it, so a read
            // can be scoped on customer_id directly without a join back.
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_item_id')->constrained('quality_items')->cascadeOnDelete();

            // Spelled out rather than `document_id`: this codebase has three document models
            // (enterprise_wiki_documents, saved_notice_ai_documents, notice_documents) and a bare
            // `document_id` would not say which one. cascadeOnDelete is the right reading of the
            // existing deletion flow — when Wiki → Kildedokumenter deletes the file, the quality
            // item has nothing left to point at, and a dangling link row would be a lie.
            $table->foreignId('enterprise_wiki_document_id')
                ->constrained('enterprise_wiki_documents')
                ->cascadeOnDelete();

            // source | template | evidence | attachment — what the file is TO this document.
            // "The signed policy" and "the form the procedure hands out" are not interchangeable,
            // and an auditor reads the difference.
            $table->string('relation_type')->default('source');
            $table->text('note')->nullable();
            $table->string('source')->default('manual');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One file may be attached to one item in more than one capacity, but not twice in the
            // same one.
            $table->unique(
                ['quality_item_id', 'enterprise_wiki_document_id', 'relation_type'],
                'quality_item_documents_unique',
            );
            // The reverse read: "which styrende dokumenter does this file belong to" — which is
            // what makes a file safe or unsafe to delete.
            $table->index(['customer_id', 'enterprise_wiki_document_id'], 'quality_item_documents_document_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_item_documents');
    }
};
