<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            $table->string('topic')->nullable();
            $table->string('sub_topic')->nullable();
            $table->json('keywords')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            $table->dropColumn(['topic', 'sub_topic', 'keywords']);
        });
    }
};
