<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The plain-language description a flow was read from.
 *
 * WHY IT IS STORED AT ALL.
 *
 * The flow itself is the source of truth — nothing re-reads this text to draw a diagram, and
 * nothing may. What it is for is the next edit: a kvalitetsleder who comes back to a flow they
 * generated three weeks ago needs the sentence that produced it in front of them, or the only way
 * to refine the flow is to describe the whole process again from memory.
 *
 * WHY IT IS NOT A SECOND SOURCE OF TRUTH.
 *
 * It is never read back into the payload. Editing the structure does not touch it, and it carries
 * no claim about the stored flow beyond "this is what was described when it was first proposed".
 * A flow derived from the steps, or edited by hand, simply has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_process_blueprints', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        Schema::table('quality_process_blueprints', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
