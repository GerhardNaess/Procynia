<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            $table->json('embedding_vector')->nullable()->after('end_offset');
            $table->string('embedding_model', 191)->nullable()->after('embedding_vector');
            $table->timestamp('embedding_generated_at')->nullable()->after('embedding_model');
            $table->text('embedding_error')->nullable()->after('embedding_generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            $table->dropColumn([
                'embedding_vector',
                'embedding_model',
                'embedding_generated_at',
                'embedding_error',
            ]);
        });
    }
};
