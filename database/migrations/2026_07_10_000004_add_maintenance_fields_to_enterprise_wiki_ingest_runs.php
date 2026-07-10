<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_ingest_runs', function (Blueprint $table): void {
            $table->timestamp('maintenance_triggered_at')->nullable()->after('qa_last_error');
            $table->string('maintenance_source_hash', 64)->nullable()->after('maintenance_triggered_at');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_ingest_runs', function (Blueprint $table): void {
            $table->dropColumn(['maintenance_triggered_at', 'maintenance_source_hash']);
        });
    }
};
