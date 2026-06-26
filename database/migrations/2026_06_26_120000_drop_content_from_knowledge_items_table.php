<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table): void {
            if (Schema::hasColumn('knowledge_items', 'content')) {
                $table->dropColumn('content');
            }
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('knowledge_items', 'content')) {
                $table->longText('content')->nullable();
            }
        });
    }
};
