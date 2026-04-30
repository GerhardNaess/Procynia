<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            if (! Schema::hasColumn('knowledge_item_chunks', 'table_markdown')) {
                $table->longText('table_markdown')->nullable()->after('chunk_type');
            }

            if (! Schema::hasColumn('knowledge_item_chunks', 'table_text')) {
                $table->longText('table_text')->nullable()->after('table_markdown');
            }

            if (! Schema::hasColumn('knowledge_item_chunks', 'table_metadata')) {
                $table->json('table_metadata')->nullable()->after('table_text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            if (Schema::hasColumn('knowledge_item_chunks', 'table_metadata')) {
                $table->dropColumn('table_metadata');
            }

            if (Schema::hasColumn('knowledge_item_chunks', 'table_text')) {
                $table->dropColumn('table_text');
            }

            if (Schema::hasColumn('knowledge_item_chunks', 'table_markdown')) {
                $table->dropColumn('table_markdown');
            }
        });
    }
};
