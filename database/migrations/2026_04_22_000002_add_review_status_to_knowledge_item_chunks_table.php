<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('knowledge_item_chunks', 'review_status')) {
            Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
                $table->string('review_status', 32)
                    ->default('pending_review')
                    ->index()
                    ->after('end_offset');
            });
        }

        DB::table('knowledge_item_chunks')
            ->whereNull('review_status')
            ->orWhere('review_status', '')
            ->update([
                'review_status' => 'pending_review',
            ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('knowledge_item_chunks', 'review_status')) {
            Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
                $table->dropColumn('review_status');
            });
        }
    }
};
