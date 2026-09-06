<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a version was sent back, who sent it, and when — kept for every round, not just the last one.
 *
 * The page carries reviewed_at/reviewed_by_user_id, and an approval row carries approval_comment,
 * but both hold exactly one decision. A page that goes back and forth three times has three reasons
 * worth reading, and overwriting the earlier ones loses the argument the work was responding to.
 *
 * Append-only by intent: nothing updates or deletes these rows. A return is an event that happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_page_review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_wiki_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_wiki_page_version_id')->constrained('enterprise_wiki_page_versions')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Who was speaking: the assigned reviewer about the page as a whole, or a document owner
            // about the content drawn from their own sources. The distinction is the point — the
            // page owner needs to know which kind of objection they are answering.
            $table->string('actor_role', 32);
            $table->string('event_type', 32);
            $table->text('reason');
            $table->timestamps();

            $table->index(['enterprise_wiki_page_version_id', 'created_at'], 'ewpre_version_created_index');
            $table->index(['enterprise_wiki_page_id', 'created_at'], 'ewpre_page_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_page_review_events');
    }
};
