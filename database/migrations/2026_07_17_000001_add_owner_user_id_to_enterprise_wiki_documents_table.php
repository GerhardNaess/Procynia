<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_documents', function (Blueprint $table): void {
            $table->foreignId('owner_user_id')
                ->nullable()
                ->after('uploaded_by_user_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['customer_id', 'owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_documents', function (Blueprint $table): void {
            $table->dropIndex(['customer_id', 'owner_user_id']);
            $table->dropConstrainedForeignId('owner_user_id');
        });
    }
};
