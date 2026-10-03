<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which activity a Wiki article came out of.
 *
 * WHAT THIS IS, AND WHAT IT IS NOT.
 *
 * A prosessaktivitet — "Kontroller leverandørens informasjonssikkerhet" — is a place where the
 * virksomhet knows something that is not written down anywhere. Procynia lets that activity be the
 * SOURCE of an Enterprise Wiki article: the user asks for one, Procynia drafts it from the process,
 * the activity and the role, the user corrects it, and what is created is an ordinary Wiki page in
 * draft that then goes through Wiki's own review and approval.
 *
 * This table is the provenance of that act, and nothing else. It holds no title, no text and no
 * summary: Wiki is the source of truth for what the article says, and a copy here would be wrong
 * the first time the page is edited. One row says "this activity is why this page exists".
 *
 * WHY NOT ON THE BLUEPRINT NODE.
 *
 * The flow payload is rewritten wholesale every time the process is saved, and completely replaced
 * when a description is re-interpreted into a new flow. Provenance stored inside it would be
 * silently destroyed by an ordinary edit — and provenance that disappears when someone rewords a
 * step is not provenance. A row survives that, and keeps surviving it.
 *
 * The activity is identified by its key within the flow rather than by a foreign key, because an
 * activity is not a row anywhere: it is a node in the blueprint payload. A key that is later
 * removed from the flow leaves a row pointing at nothing, which is read as "no activity has this
 * key" and shows nowhere — the page itself is untouched, as it must be. Wiki never loses a page
 * because Kvalitet changed its mind about a step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_activity_wiki_pages', function (Blueprint $table): void {
            $table->id();

            // Denormalised from the item the same way the rest of Kvalitet denormalises it, so
            // every read is scoped on customer_id directly without a join back.
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_item_id')->constrained('quality_items')->cascadeOnDelete();

            // The node key within that process's flow. Matches the 80-character ceiling the
            // blueprint rules validate node keys on.
            $table->string('activity_key', 80);

            // cascadeOnDelete: deleting the Wiki page deletes the record that an activity produced
            // it. There is nothing left to be the provenance of, and a row pointing at a page that
            // no longer exists would have to be filtered out of every read anyway.
            $table->foreignId('enterprise_wiki_page_id')->constrained('enterprise_wiki_pages')->cascadeOnDelete();

            // Who asked for the article. nullOnDelete for the same reason ownership is nullable
            // elsewhere: losing the person must not lose the record that the article was created.
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One activity is the source of a given page once. Asking twice from the same step
            // produces a second page, not a second claim on the first.
            $table->unique(['quality_item_id', 'activity_key', 'enterprise_wiki_page_id'], 'quality_activity_wiki_pages_unique');

            // The read this table exists for: every article one process's activities produced.
            $table->index(['customer_id', 'quality_item_id'], 'quality_activity_wiki_pages_item_index');

            // And the read from the other end — which activity produced this page.
            $table->index(['customer_id', 'enterprise_wiki_page_id'], 'quality_activity_wiki_pages_page_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_activity_wiki_pages');
    }
};
