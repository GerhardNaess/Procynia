<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which controls sit on which activity of a process.
 *
 * A control is already a quality item of its own (`quality_type = control`, with its criterion in
 * `quality_control_details`). This table adds nothing to what a control is; it records where in a
 * process it applies — "this control is performed at this step".
 *
 * The activity is named by its key in the flow payload, on the same terms as
 * quality_activity_wiki_pages: an activity is a node in the blueprint, not a row, and the payload
 * is rewritten wholesale on every save, so a reference stored inside it would be lost to an
 * ordinary edit. A key later removed from the flow leaves a row that matches no activity and shows
 * nowhere; the control itself is untouched.
 *
 * Revisions are not involved. An approved revision is a snapshot of the flow; which controls apply
 * at a step is read from here, against the current keys, for the working version and the revision
 * alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_activity_controls', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // The process whose flow the activity is a node in.
            $table->foreignId('quality_item_id')->constrained('quality_items')->cascadeOnDelete();

            // Matches the 80-character ceiling the blueprint rules validate node keys on.
            $table->string('activity_key', 80);

            // The control. Deleting it from the register removes it from every activity it sat on.
            $table->foreignId('control_item_id')->constrained('quality_items')->cascadeOnDelete();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['quality_item_id', 'activity_key', 'control_item_id'], 'quality_activity_controls_unique');
            $table->index(['customer_id', 'quality_item_id'], 'quality_activity_controls_item_index');
            $table->index(['customer_id', 'control_item_id'], 'quality_activity_controls_control_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_activity_controls');
    }
};
