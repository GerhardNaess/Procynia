<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arbeidspakke B (docs/enterprise-wiki-knowledge-integration-plan.md §6.3, §8.2): records, per page
 * of a run, what the backend observed while linking the page to existing knowledge.
 *
 * - link_assessment: deterministic journal of the catalog offered, intents proposed, materialized
 *   and rejected, and the derived outcome. Never self-reported by AI.
 * - links_assessed_at: checkpoint set once the page's links were materialized into rows.
 *
 * Both nullable and additive: existing rows keep NULL, which reads as "not assessed" (no backfill,
 * D9). Fully reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_ingest_run_pages', function (Blueprint $table): void {
            $table->json('link_assessment')->nullable();
            $table->timestamp('links_assessed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_ingest_run_pages', function (Blueprint $table): void {
            $table->dropColumn(['link_assessment', 'links_assessed_at']);
        });
    }
};
