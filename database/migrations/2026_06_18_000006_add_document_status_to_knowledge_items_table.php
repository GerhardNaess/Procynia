<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('knowledge_items')) {
            return;
        }

        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->string('document_status', 32)->default('active')->after('ai_usage_enabled');
        });

        DB::table('knowledge_items')->update(['document_status' => 'active']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('knowledge_items')) {
            return;
        }

        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->dropColumn('document_status');
        });
    }
};
