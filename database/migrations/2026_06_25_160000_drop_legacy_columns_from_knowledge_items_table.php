<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table): void {
            if (Schema::hasColumn('knowledge_items', 'content_type')) {
                $table->dropColumn('content_type');
            }

            if (Schema::hasColumn('knowledge_items', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('knowledge_items', 'content_type')) {
                $table->string('content_type', 32)->nullable()->after('document_type');
            }

            if (! Schema::hasColumn('knowledge_items', 'is_active')) {
                $table->boolean('is_active')->nullable()->after('ai_usage_enabled');
            }
        });
    }
};
