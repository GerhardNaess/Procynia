<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_qa_snapshots', function (Blueprint $table) {
            $table->boolean('deep_repair_attempted')->default(false)->after('semantic_post_repair_factual_score');
            $table->string('deep_repair_source_hash', 64)->nullable()->after('deep_repair_attempted');
            $table->json('deep_repair_components_repaired')->nullable()->after('deep_repair_source_hash');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_qa_snapshots', function (Blueprint $table) {
            $table->dropColumn(['deep_repair_attempted', 'deep_repair_source_hash', 'deep_repair_components_repaired']);
        });
    }
};
