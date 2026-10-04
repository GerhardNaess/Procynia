<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Risiko — strukturert risikobeskrivelse: årsak → hendelse → konsekvens.
 *
 * cause, event and consequence become the authoritative description of a risk. They are nullable
 * only because risks registered before this existed have none; the controller requires all three
 * on every create and edit, so an older risk is completed the next time someone edits it.
 *
 * The existing free-text `description` is kept as it is — it holds real data and is never split
 * into the three parts by guessing. From here on it is the optional «Utfyllende informasjon» and
 * is never read as the risk description.
 *
 * Each assessment gets a snapshot of the three parts as they were when it was registered, so a
 * historical assessment can still be understood after the risk text changes. Assessments made
 * before this have no snapshot and are left that way: nothing is backfilled from today's text,
 * which may not be what was assessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risks', function (Blueprint $table): void {
            $table->text('cause')->nullable()->after('title');
            $table->text('event')->nullable()->after('cause');
            $table->text('consequence')->nullable()->after('event');
        });

        Schema::table('risk_assessments', function (Blueprint $table): void {
            $table->text('risk_cause')->nullable()->after('rationale');
            $table->text('risk_event')->nullable()->after('risk_cause');
            $table->text('risk_consequence')->nullable()->after('risk_event');
        });
    }

    public function down(): void
    {
        Schema::table('risk_assessments', function (Blueprint $table): void {
            $table->dropColumn(['risk_cause', 'risk_event', 'risk_consequence']);
        });

        Schema::table('risks', function (Blueprint $table): void {
            $table->dropColumn(['cause', 'event', 'consequence']);
        });
    }
};
