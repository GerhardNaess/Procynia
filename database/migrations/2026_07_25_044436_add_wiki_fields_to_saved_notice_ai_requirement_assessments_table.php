<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('saved_notice_ai_requirement_assessments', function (Blueprint $table): void {
            $table->boolean('has_possible_conflict')->nullable()->after('recommended_next_step');
            $table->string('engine_version')->nullable()->after('has_possible_conflict');
        });

        // Non-destructive rename: the column's purpose (a snapshot of what knowledge grounded the
        // assessment) is unchanged, only its shape moves from Knowledge-Base-chunk-shaped to
        // Enterprise-Wiki-page-shaped. Existing rows keep their data, readable under the new name.
        DB::statement('ALTER TABLE saved_notice_ai_requirement_assessments RENAME COLUMN source_evidence_snapshot TO wiki_sources_snapshot');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE saved_notice_ai_requirement_assessments RENAME COLUMN wiki_sources_snapshot TO source_evidence_snapshot');

        Schema::table('saved_notice_ai_requirement_assessments', function (Blueprint $table): void {
            $table->dropColumn(['has_possible_conflict', 'engine_version']);
        });
    }
};
