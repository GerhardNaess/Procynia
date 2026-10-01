<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quality V1 — the faglig layer Kvalitet puts on top of Wiki.
 *
 * Two tables, and deliberately no third: Wiki already owns the content, the owner, the version
 * history, the review state and the approval. Quality adds what Wiki has no opinion about — what
 * kind of styrende dokument a page *is*, and how those documents govern each other.
 *
 * WHY CLASSIFICATION IS ITS OWN TABLE AND NOT A COLUMN ON enterprise_wiki_pages.
 *
 * `enterprise_wiki_pages.page_type` is a fact about how the Wiki pipeline produced the page
 * (article, summary, concept, entity, ...). The quality type is a fact about the virksomhet's
 * document hierarchy (policy, process, procedure, ...). The two answer different questions, change
 * for different reasons and are decided by different people, so they are stored apart. A page that
 * carries no row here is simply not part of the quality system — a real and common state, not a
 * missing value.
 *
 * Keeping it as a row with an id of its own is also what makes the planned extensions additive:
 * roles, input/output and derived process definitions all hang off a classification, and each
 * becomes a new table with a foreign key here rather than more columns on the Wiki page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_page_classifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // One classification per page. The unique index is the rule: a page is one kind of
            // styrende dokument, never two.
            $table->foreignId('enterprise_wiki_page_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('quality_type');
            // The virksomhet's own document number ("P-03", "RUT-12"). Free text and optional:
            // every QMS numbers its documents, and none of them agree on how.
            $table->string('quality_code')->nullable();
            // manual today. The value exists from the start so that a later AI-derived process
            // definition can be told apart from a human decision without having to guess from
            // timestamps — the same lesson EnterpriseWikiPageLink records for wikilinks.
            $table->string('source')->default('manual');
            $table->foreignId('classified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'quality_type']);
        });

        Schema::create('quality_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Edges point at Wiki pages rather than at classification rows. The graph projection
            // uses the Wiki page as the node, so an edge that addressed the classification would
            // have to be translated on every projection, and unclassifying a page would silently
            // renumber its edges. Which pairs are legal is enforced in QualityStructureService
            // against the classifications, not here.
            $table->foreignId('from_page_id')->constrained('enterprise_wiki_pages')->cascadeOnDelete();
            $table->foreignId('to_page_id')->constrained('enterprise_wiki_pages')->cascadeOnDelete();
            $table->string('relation_type');
            $table->string('source')->default('manual');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'from_page_id', 'to_page_id', 'relation_type'], 'quality_relations_edge_unique');
            $table->index(['customer_id', 'from_page_id']);
            $table->index(['customer_id', 'to_page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_relations');
        Schema::dropIfExists('quality_page_classifications');
    }
};
