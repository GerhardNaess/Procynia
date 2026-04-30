<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            if (! Schema::hasColumn('knowledge_item_chunks', 'table_json')) {
                $table->json('table_json')->nullable()->after('chunk_type');
            }

            if (! Schema::hasColumn('knowledge_item_chunks', 'table_html')) {
                $table->longText('table_html')->nullable()->after('table_json');
            }

            if (! Schema::hasColumn('knowledge_item_chunks', 'table_complexity')) {
                $table->string('table_complexity', 32)->nullable()->after('table_html');
            }

            if (! Schema::hasColumn('knowledge_item_chunks', 'table_warnings')) {
                $table->json('table_warnings')->nullable()->after('table_complexity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            if (Schema::hasColumn('knowledge_item_chunks', 'table_warnings')) {
                $table->dropColumn('table_warnings');
            }

            if (Schema::hasColumn('knowledge_item_chunks', 'table_complexity')) {
                $table->dropColumn('table_complexity');
            }

            if (Schema::hasColumn('knowledge_item_chunks', 'table_html')) {
                $table->dropColumn('table_html');
            }

            if (Schema::hasColumn('knowledge_item_chunks', 'table_json')) {
                $table->dropColumn('table_json');
            }
        });
    }
};
