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
            $table->boolean('ai_usage_enabled')->default(true)->after('is_active');
        });

        DB::table('knowledge_items')->update(['ai_usage_enabled' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('knowledge_items')) {
            return;
        }

        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->dropColumn('ai_usage_enabled');
        });
    }
};
