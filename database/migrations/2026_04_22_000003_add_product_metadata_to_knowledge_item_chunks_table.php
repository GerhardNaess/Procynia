<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('knowledge_item_chunks', 'title')) {
            Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
                $table->string('title', 255)->nullable()->after('review_status');
            });
        }

        if (! Schema::hasColumn('knowledge_item_chunks', 'ai_summary')) {
            Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
                $table->text('ai_summary')->nullable()->after('title');
            });
        }

        if (! Schema::hasColumn('knowledge_item_chunks', 'service_product_tag')) {
            Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
                $table->string('service_product_tag', 191)->nullable()->after('ai_summary');
            });
        }

        if (! Schema::hasColumn('knowledge_item_chunks', 'theme_tag')) {
            Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
                $table->string('theme_tag', 191)->nullable()->after('service_product_tag');
            });
        }

        DB::table('knowledge_item_chunks')
            ->whereNull('title')
            ->orWhere('title', '')
            ->update([
                'title' => null,
            ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('knowledge_item_chunks', 'theme_tag')) {
            Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
                $table->dropColumn('theme_tag');
            });
        }

        if (Schema::hasColumn('knowledge_item_chunks', 'service_product_tag')) {
            Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
                $table->dropColumn('service_product_tag');
            });
        }

        if (Schema::hasColumn('knowledge_item_chunks', 'ai_summary')) {
            Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
                $table->dropColumn('ai_summary');
            });
        }

        if (Schema::hasColumn('knowledge_item_chunks', 'title')) {
            Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
                $table->dropColumn('title');
            });
        }
    }
};
