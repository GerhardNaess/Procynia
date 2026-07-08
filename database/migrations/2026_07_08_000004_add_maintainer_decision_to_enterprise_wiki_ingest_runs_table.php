<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_ingest_runs', function (Blueprint $table): void {
            $table->json('maintainer_decision_json')->nullable()->after('finished_at');
            $table->string('maintainer_decision_status')->nullable()->after('maintainer_decision_json');
            $table->timestamp('maintainer_decision_generated_at')->nullable()->after('maintainer_decision_status');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_ingest_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'maintainer_decision_json',
                'maintainer_decision_status',
                'maintainer_decision_generated_at',
            ]);
        });
    }
};
