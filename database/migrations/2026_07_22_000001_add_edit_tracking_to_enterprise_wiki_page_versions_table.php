<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_page_versions', function (Blueprint $table): void {
            $table->foreignId('edited_by_user_id')
                ->nullable()
                ->after('generation_prompt_hash')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('edited_at')->nullable()->after('edited_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_page_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('edited_by_user_id');
            $table->dropColumn('edited_at');
        });
    }
};
