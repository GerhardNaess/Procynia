<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->date('last_reviewed_at')->nullable()->after('document_status');
            $table->date('review_due_at')->nullable()->after('last_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->dropColumn(['last_reviewed_at', 'review_due_at']);
        });
    }
};
