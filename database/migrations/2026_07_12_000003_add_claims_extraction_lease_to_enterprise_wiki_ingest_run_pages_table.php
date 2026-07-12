<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_ingest_run_pages', function (Blueprint $table): void {
            $table->timestamp('claims_claimed_at')->nullable()->after('claims_extracted_at');
            $table->string('claims_claim_token')->nullable()->after('claims_claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_ingest_run_pages', function (Blueprint $table): void {
            $table->dropColumn(['claims_claimed_at', 'claims_claim_token']);
        });
    }
};
