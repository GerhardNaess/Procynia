<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_page_versions', function (Blueprint $table): void {
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users', indexName: 'ewpv_created_by_user_fk')
                ->nullOnDelete();
            $table->boolean('is_staged')->default(false);

            $table->index(['is_staged', 'is_current', 'created_at'], 'ewpv_staged_cleanup_idx');
            $table->index(['enterprise_wiki_page_id', 'is_staged', 'is_current'], 'ewpv_page_staged_current_idx');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_page_versions', function (Blueprint $table): void {
            $table->dropForeign('ewpv_created_by_user_fk');
            $table->dropIndex('ewpv_staged_cleanup_idx');
            $table->dropIndex('ewpv_page_staged_current_idx');
            $table->dropColumn(['created_by_user_id', 'is_staged']);
        });
    }
};
