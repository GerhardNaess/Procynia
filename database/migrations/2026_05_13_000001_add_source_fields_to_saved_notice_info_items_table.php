<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_info_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('saved_notice_info_items', 'source_type')) {
                $table->string('source_type', 80)->nullable();
            }

            if (! Schema::hasColumn('saved_notice_info_items', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable();
            }
        });

        Schema::table('saved_notice_info_items', function (Blueprint $table): void {
            if (
                Schema::hasColumn('saved_notice_info_items', 'source_type')
                && Schema::hasColumn('saved_notice_info_items', 'source_id')
            ) {
                $table->index(
                    ['saved_notice_id', 'type', 'status', 'source_type', 'source_id'],
                    'saved_notice_info_items_source_task_lookup_index',
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('saved_notice_info_items', function (Blueprint $table): void {
            if (
                Schema::hasColumn('saved_notice_info_items', 'source_type')
                && Schema::hasColumn('saved_notice_info_items', 'source_id')
            ) {
                $table->dropIndex('saved_notice_info_items_source_task_lookup_index');
            }

            if (Schema::hasColumn('saved_notice_info_items', 'source_type')) {
                $table->dropColumn('source_type');
            }

            if (Schema::hasColumn('saved_notice_info_items', 'source_id')) {
                $table->dropColumn('source_id');
            }
        });
    }
};
