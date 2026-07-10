<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_ingest_runs', function (Blueprint $table): void {
            $table->timestamp('deep_repair_attempted_at')->nullable()->after('maintenance_source_hash');
            $table->string('deep_repair_source_hash', 64)->nullable()->after('deep_repair_attempted_at');
            $table->json('deep_repair_result')->nullable()->after('deep_repair_source_hash');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_ingest_runs', function (Blueprint $table): void {
            $table->dropColumn(['deep_repair_attempted_at', 'deep_repair_source_hash', 'deep_repair_result']);
        });
    }
};
