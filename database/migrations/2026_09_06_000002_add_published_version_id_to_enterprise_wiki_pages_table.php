<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separate "the version the pipeline is working on" from "the version readers may rely on".
 *
 * `is_current` was doing both jobs, and FinalizeEnterpriseWikiIngest set it on brand-new, unreviewed
 * content — so a page's authoritative content changed the moment a run finished, before any human
 * had looked at it. Roughly forty readers (QA, lint, link building, patching, claim extraction,
 * graph, repair) legitimately want the working version, so `is_current` keeps that meaning and they
 * are left untouched. Publication moves to its own column.
 *
 * A single column on the page, rather than a flag on the version, makes "at most one published
 * version" true by construction — one column holds one value — instead of something a partial unique
 * index has to defend. NULL is meaningful: the page has never been approved, so it has no published
 * version at all.
 *
 * Backfill is one deterministic statement, so it lives here rather than in a command: a page whose
 * status is `approved` was approved while its current version was current, so that version is what
 * was published. Every other page has never been approved and correctly gets NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_pages', function (Blueprint $table): void {
            $table->foreignId('published_version_id')
                ->nullable()
                ->after('status')
                ->constrained('enterprise_wiki_page_versions')
                ->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE enterprise_wiki_pages AS p
            SET published_version_id = (
                SELECT v.id
                FROM enterprise_wiki_page_versions AS v
                WHERE v.enterprise_wiki_page_id = p.id
                  AND v.is_current = true
            )
            WHERE p.status = 'approved'
        SQL);
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_pages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('published_version_id');
        });
    }
};
