<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add explicit ownership metadata to legacy knowledge items and backfill existing rows.
     */
    public function up(): void
    {
        if (! Schema::hasTable('knowledge_items')) {
            return;
        }

        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->string('ownership_type', 32)
                ->default('company');
            $table->foreignId('owner_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('owning_saved_notice_id')
                ->nullable()
                ->constrained('saved_notices')
                ->nullOnDelete();
        });

        DB::table('knowledge_items')->update([
            'ownership_type' => 'company',
        ]);
    }

    /**
     * Remove ownership metadata from knowledge items.
     */
    public function down(): void
    {
        if (! Schema::hasTable('knowledge_items')) {
            return;
        }

        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropConstrainedForeignId('owning_saved_notice_id');
            $table->dropColumn('ownership_type');
        });
    }
};
