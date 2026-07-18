<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_ingest_runs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('claim_content_repair_attempt_count')->default(0)->after('deep_repair_result');
            $table->timestamp('claim_content_repair_attempted_at')->nullable()->after('claim_content_repair_attempt_count');
            $table->json('claim_content_repair_result')->nullable()->after('claim_content_repair_attempted_at');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_ingest_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'claim_content_repair_attempt_count',
                'claim_content_repair_attempted_at',
                'claim_content_repair_result',
            ]);
        });
    }
};
