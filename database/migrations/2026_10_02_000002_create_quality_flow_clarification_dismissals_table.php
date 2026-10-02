<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suggestions the user has already said no to, for one process.
 *
 * WHAT THIS IS FOR.
 *
 * An optional clarification is Procynia noticing that the description decides something on a word
 * it never defines — "dersom leverandøren er kritisk", with no statement of what makes one
 * critical. That is worth saying once. It is not worth saying every time the user regenerates the
 * flow, because an organisation that has decided "kritisk" is deliberately left to judgement has
 * answered the question by dismissing it, and a suggestion that keeps coming back is a suggestion
 * the user learns to read past — taking the ones that do matter with it.
 *
 * WHY THE DESCRIPTION IS STORED BESIDE IT.
 *
 * A dismissal is about a question asked of a particular text, not a permanent opinion about a
 * phrase. If the process is described again from scratch — new work, new roles, new decisions — the
 * old answer no longer covers it and the question is worth asking again. Keeping the text the
 * dismissal was made against is what lets QualityFlowClarificationService tell those two cases
 * apart; it compares and, where the description has materially changed, lets the dismissal lapse.
 * Nothing reads this column as process content, and nothing may: the flow is the source of truth.
 *
 * WHY A HASH AS WELL AS THE TEXT.
 *
 * The question is matched, not displayed, so what it is matched on has to be stable under the
 * things a model varies freely — casing, a trailing question mark, doubled spaces. The hash is of
 * the normalised question and carries the unique index; the text is kept as written so a human
 * reading this table can see what was turned down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_flow_clarification_dismissals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_item_id')->constrained('quality_items')->cascadeOnDelete();

            // The question as it was shown, and the normalised form it is matched on.
            $table->text('question');
            $table->string('question_key', 64);

            // The description this question was asked of. Null where it was not known.
            $table->text('description')->nullable();

            $table->foreignId('dismissed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One dismissal per question per process. Saying no twice is saying no.
            $table->unique(['quality_item_id', 'question_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_flow_clarification_dismissals');
    }
};
