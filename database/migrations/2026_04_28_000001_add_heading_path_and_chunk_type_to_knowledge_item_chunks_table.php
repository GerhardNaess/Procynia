<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            if (! Schema::hasColumn('knowledge_item_chunks', 'heading_path')) {
                $table->text('heading_path')->nullable()->after('section_path');
            }

            if (! Schema::hasColumn('knowledge_item_chunks', 'chunk_type')) {
                $table->string('chunk_type', 32)->nullable()->default('semantic')->after('heading_path');
            }
        });

        if (Schema::hasColumn('knowledge_item_chunks', 'heading_path')) {
            DB::table('knowledge_item_chunks')
                ->whereNull('heading_path')
                ->whereNotNull('section_path')
                ->update([
                    'heading_path' => DB::raw('section_path'),
                ]);
        }

        if (Schema::hasColumn('knowledge_item_chunks', 'chunk_type')) {
            DB::table('knowledge_item_chunks')
                ->whereNull('chunk_type')
                ->orWhere('chunk_type', '')
                ->update([
                    'chunk_type' => 'semantic',
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            if (Schema::hasColumn('knowledge_item_chunks', 'chunk_type')) {
                $table->dropColumn('chunk_type');
            }

            if (Schema::hasColumn('knowledge_item_chunks', 'heading_path')) {
                $table->dropColumn('heading_path');
            }
        });
    }
};
