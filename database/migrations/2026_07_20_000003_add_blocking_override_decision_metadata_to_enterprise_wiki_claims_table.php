<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_claims', function (Blueprint $table): void {
            // Mirrors the existing approved_by_user_id/approved_at pattern — cheap, no-join
            // "who/when" display for the CURRENT blocking_override, so the Funn panel and
            // Verifikasjonsgrunnlag don't need to join enterprise_wiki_claim_decisions just to
            // show the latest decision. The decisions table remains the full history.
            $table->foreignId('blocking_override_by_user_id')
                ->nullable()
                ->after('blocking_override')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('blocking_override_at')->nullable()->after('blocking_override_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_claims', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('blocking_override_by_user_id');
            $table->dropColumn('blocking_override_at');
        });
    }
};
