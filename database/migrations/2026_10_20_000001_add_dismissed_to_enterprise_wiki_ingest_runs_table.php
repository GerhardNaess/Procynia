<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Fjern fra oversikten» in Wiki → Kjøringer: a run a person has cleared away from the list.
 *
 * Visibility only. The run row, its status and every row that refers to it are kept — the run's
 * pages are how a page's document and owner are derived, and its QA snapshots, lint findings and AI
 * usage stay attributed to it. Nothing but the Kjøringer list reads these columns.
 *
 * Both nullable and additive: existing runs keep NULL and stay visible. Fully reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_ingest_runs', function (Blueprint $table): void {
            $table->timestamp('dismissed_at')->nullable();
            $table->foreignId('dismissed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_ingest_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dismissed_by_user_id');
            $table->dropColumn('dismissed_at');
        });
    }
};
