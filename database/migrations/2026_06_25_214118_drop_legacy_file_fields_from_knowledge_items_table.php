<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table): void {
            if (Schema::hasColumn('knowledge_items', 'original_filename')) {
                $table->dropColumn('original_filename');
            }

            if (Schema::hasColumn('knowledge_items', 'storage_path')) {
                $table->dropColumn('storage_path');
            }

            if (Schema::hasColumn('knowledge_items', 'mime_type')) {
                $table->dropColumn('mime_type');
            }

            if (Schema::hasColumn('knowledge_items', 'file_size_bytes')) {
                $table->dropColumn('file_size_bytes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('knowledge_items', 'original_filename')) {
                $table->string('original_filename')->nullable()->after('customer_id');
            }

            if (! Schema::hasColumn('knowledge_items', 'storage_path')) {
                $table->string('storage_path')->nullable()->after('original_filename');
            }

            if (! Schema::hasColumn('knowledge_items', 'mime_type')) {
                $table->string('mime_type', 120)->nullable()->after('storage_path');
            }

            if (! Schema::hasColumn('knowledge_items', 'file_size_bytes')) {
                $table->unsignedBigInteger('file_size_bytes')->nullable()->after('mime_type');
            }
        });
    }
};
