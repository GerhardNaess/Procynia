<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The flow of one process: who does what, in what order, and where it branches.
 *
 * WHY A BLUEPRINT AND NOT JUST THE STEPS.
 *
 * `quality_process_steps` is an ordered list — step 1, then 2, then 3. That is what a styrende
 * dokument reads like, and it is deliberately all it is. A flow is a different statement about the
 * same process: it has lanes (who acts), branches (a decision with two outcomes), and joins (two
 * paths that meet again). None of those fit in a position column, and forcing them in would make
 * the step list unreadable as the document text it has to stay.
 *
 * So the flow is its own row, derived from the steps but editable away from them. The steps remain
 * the prose; the blueprint is the structure a diagram can be drawn from.
 *
 * WHY ONE JSON PAYLOAD AND NOT lanes/nodes/edges TABLES.
 *
 * A blueprint is read, written and approved as one whole. Nothing points at a single node from
 * outside it, nothing sorts or filters across blueprints by node, and every edit is a
 * restructuring — moving a node between lanes rewrites its edges with it. Three tables would buy
 * referential integrity nothing needs and cost a reconciliation on every save, which is the same
 * trade replaceProcessSteps() already made inside one table. If a node ever becomes something
 * another table points at — a control verifying one specific decision — that is the day to split
 * it, and the payload shape is already node-keyed for it.
 *
 * WHY THE DIAGRAM IS NOT STORED.
 *
 * It is a pure function of this payload. Storing coordinates would create a second source of truth
 * that drifts the first time somebody edits a lane, and would have to be migrated whenever the
 * layout improves. See resources/js/Support/processBlueprintLayout.js — same input, same geometry,
 * every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_process_blueprints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // One flow per process. A process has one way it is carried out — alternatives are
            // branches inside the flow, not a second blueprint — so the unique index is the rule.
            $table->foreignId('quality_item_id')->unique()->constrained('quality_items')->cascadeOnDelete();

            // {lanes: [...], nodes: [...], edges: [...]}. Normalised by
            // QualityProcessBlueprintService before it ever reaches here: node keys unique, every
            // edge endpoint and lane reference resolved, unreachable rows dropped.
            $table->jsonb('payload');

            // draft | approved. Separate from quality_items.status on purpose: a gjeldende process
            // may well have a flow nobody has reviewed yet, and saying otherwise would make the
            // approval meaningless.
            $table->string('status')->default('draft');

            // derived | example | ai | manual. Recorded for the same reason
            // quality_page_classifications.source was: a human-authored flow must be
            // distinguishable from a machine-proposed one without guessing from timestamps. `ai`
            // means a person adopted what QualityProcessFlowInterpreter proposed — the proposal
            // itself is never stored, so no row here was written without someone agreeing to it.
            $table->string('source')->default('derived');

            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Cleared whenever the payload changes. An approval is of a specific flow, not of the
            // row, so it cannot survive an edit to what it approved.
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_process_blueprints');
    }
};
