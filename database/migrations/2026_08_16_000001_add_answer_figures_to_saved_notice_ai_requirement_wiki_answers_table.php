<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wiki figures chosen for a requirement answer are stored as structured references, never inside
 * answer_text.
 *
 * answer_text is a hand-editable operative draft: a bid manager rewrites it freely, and any figure
 * encoded in that text would be silently destroyed by an ordinary edit. Keeping the references in
 * their own column means editing the wording never touches which figures the answer carries, and
 * regeneration replaces both together.
 *
 * The column holds identity only — {figure_ref, document_id, source_image_key, page_id,
 * section_key, section_index} — no captions, no URLs and no image bytes. Everything displayed is
 * resolved from live Wiki state at render time, through the existing customer-scoped image route.
 * Nullable, so every answer generated before this migration keeps working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_ai_requirement_wiki_answers', function (Blueprint $table): void {
            $table->jsonb('answer_figures')->nullable()->after('answer_text');
        });
    }

    public function down(): void
    {
        Schema::table('saved_notice_ai_requirement_wiki_answers', function (Blueprint $table): void {
            $table->dropColumn('answer_figures');
        });
    }
};
