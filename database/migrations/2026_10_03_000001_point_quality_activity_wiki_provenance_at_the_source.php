<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An activity is the source of a Wiki SOURCE, not of one hand-made Wiki page.
 *
 * WHAT CHANGED AND WHY.
 *
 * The first version of this feature wrote an EnterpriseWikiPage straight out of the activity. That
 * produced a page, and nothing else: no concept pages, no entity pages, no summary, none of the
 * cross-page relations the rest of the Wiki has — because every one of those is planned by the
 * maintainer decision, and a maintainer decision only ever exists for an EnterpriseWikiDocument.
 * An activity that wrote its own page was an activity that skipped the entire ingest run.
 *
 * So the article the user approves is now stored as an ordinary Wiki source document and handed to
 * the ordinary document flow. Kvalitet is provenance and nothing more: it says which activity a
 * source came out of, and the pages are whatever that run produced — one, or several, of whatever
 * types the maintainer decision judged right.
 *
 * WHY BOTH COLUMNS STAY.
 *
 * Rows written by the old direction point at a page that genuinely exists and genuinely came out
 * of that activity. Rewriting them into documents would be inventing a source that was never
 * stored, and dropping them would destroy provenance to tidy up a column. They keep their page;
 * new rows get a document; exactly one of the two is set, which the check constraint enforces
 * rather than leaves to convention.
 *
 * The table keeps its name. It is still "which Wiki knowledge this activity is behind" — what
 * moved is the end the reference is anchored to, and renaming the table would churn every reader
 * of it for no change in meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_activity_wiki_pages', function (Blueprint $table): void {
            // cascadeOnDelete for the same reason the page column has it: deleting the source
            // deletes the record that an activity produced it. EnterpriseWikiDocumentDeletionService
            // already removes the runs and the sole-source pages, so there is nothing left to be
            // the provenance of.
            $table->foreignId('enterprise_wiki_document_id')
                ->nullable()
                ->after('activity_key')
                ->constrained('enterprise_wiki_documents')
                ->cascadeOnDelete();
        });

        // Nullable now, because a row written by the current direction names a document instead.
        // Done outside the Blueprint change() so the existing foreign key and its cascade survive
        // untouched — change() on a constrained column drops and rebuilds more than the nullability.
        Schema::getConnection()->statement(
            'ALTER TABLE quality_activity_wiki_pages ALTER COLUMN enterprise_wiki_page_id DROP NOT NULL'
        );

        // One activity is the source of a given document once, the same rule the page column has.
        // Postgres treats NULLs as distinct in a unique index, so neither index constrains rows
        // that use the other column.
        Schema::table('quality_activity_wiki_pages', function (Blueprint $table): void {
            $table->unique(
                ['quality_item_id', 'activity_key', 'enterprise_wiki_document_id'],
                'quality_activity_wiki_documents_unique'
            );

            $table->index(
                ['customer_id', 'enterprise_wiki_document_id'],
                'quality_activity_wiki_pages_document_index'
            );
        });

        // A row is provenance for exactly one thing. Without this, a half-written row naming
        // neither end would read as "an activity produced nothing", which is not a fact anyone
        // ever meant to record.
        Schema::getConnection()->statement(<<<'SQL'
            ALTER TABLE quality_activity_wiki_pages
            ADD CONSTRAINT quality_activity_wiki_pages_one_target
            CHECK (
                (enterprise_wiki_page_id IS NOT NULL AND enterprise_wiki_document_id IS NULL)
                OR (enterprise_wiki_page_id IS NULL AND enterprise_wiki_document_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::getConnection()->statement(
            'ALTER TABLE quality_activity_wiki_pages DROP CONSTRAINT IF EXISTS quality_activity_wiki_pages_one_target'
        );

        // Rows that name a document have no page to fall back to, so they cannot survive the
        // column becoming NOT NULL again. They go, and the pages their runs produced stay — the
        // Wiki is untouched by this, exactly as it is untouched by any other Kvalitet change.
        Schema::getConnection()->statement(
            'DELETE FROM quality_activity_wiki_pages WHERE enterprise_wiki_page_id IS NULL'
        );

        Schema::table('quality_activity_wiki_pages', function (Blueprint $table): void {
            $table->dropUnique('quality_activity_wiki_documents_unique');
            $table->dropIndex('quality_activity_wiki_pages_document_index');
            $table->dropConstrainedForeignId('enterprise_wiki_document_id');
        });

        Schema::getConnection()->statement(
            'ALTER TABLE quality_activity_wiki_pages ALTER COLUMN enterprise_wiki_page_id SET NOT NULL'
        );
    }
};
