<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            $table->string('section_title')->nullable()->after('keywords');
            $table->text('section_path')->nullable()->after('section_title');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            $table->dropColumn(['section_title', 'section_path']);
        });
    }
};
