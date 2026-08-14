<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The observable best-practice contract: what the generation call concluded about each planned
 * topic, stored with the version it concluded it about.
 *
 * On the version rather than the page, for the same reason content_blocks_json is: an assessment
 * belongs to one generated body of text. A later version is a different assessment, and the old one
 * stays readable next to the content it was made about.
 *
 * Nullable, because every version written before this contract existed has no assessment — and
 * "no assessment recorded" must stay distinguishable from "assessed, found nothing", which is the
 * whole point of the change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_page_versions', function (Blueprint $table): void {
            $table->json('best_practice_review_json')->nullable()->after('content_blocks_json');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_page_versions', function (Blueprint $table): void {
            $table->dropColumn('best_practice_review_json');
        });
    }
};
