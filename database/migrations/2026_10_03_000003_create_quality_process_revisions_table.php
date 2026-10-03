<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approved revisions of a process flow — the record of what was vouched for.
 *
 * WHY A SECOND TABLE AND NOT A STATUS ON THE BLUEPRINT.
 *
 * `quality_process_blueprints` is the working version: one row per process, edited in place, and
 * every save clears its approval. That is right for a draft and wrong for a record. If approval
 * only lived on that row, the first edit after approving would erase the only statement of what
 * had been approved. A revision is written once, when "Godkjenn struktur" succeeds, and never
 * touched again.
 *
 * So the two answer different questions: the blueprint row is "what are we working on", the
 * highest revision_number here is "what is currently approved". They can disagree — that is the
 * state a process is in between an edit and the next approval — and neither hides the other.
 *
 * The revision points at the process, not at the blueprint row. "Slett flyt" deletes the working
 * version to start over; the history of what was approved before must survive that.
 *
 * The payload is the blueprint payload verbatim, already normalised and validated when it was
 * approved. No diff, no rollback yet — just the snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_process_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_item_id')->constrained('quality_items')->cascadeOnDelete();

            // 1, 2, 3 … per process. The unique index is what makes two simultaneous approvals
            // fail rather than both claim the same number.
            $table->unsignedInteger('revision_number');

            // {lanes, nodes, edges} exactly as the blueprint held it at approval.
            $table->jsonb('payload');
            $table->text('description')->nullable();
            $table->string('source');

            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Kept as text too: the user row may be deleted, and a record of who approved must not
            // become "—" when that happens.
            $table->string('approved_by_name')->nullable();
            $table->timestamp('approved_at');

            $table->timestamp('created_at')->nullable();

            $table->unique(['quality_item_id', 'revision_number']);
            $table->index(['customer_id', 'quality_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_process_revisions');
    }
};
