<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_ingest_run_pages', function (Blueprint $table): void {
            $table->timestamp('claims_extracted_at')->nullable()->after('generation_error');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_ingest_run_pages', function (Blueprint $table): void {
            $table->dropColumn('claims_extracted_at');
        });
    }
};
