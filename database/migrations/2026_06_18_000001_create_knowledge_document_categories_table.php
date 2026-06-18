<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_document_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'is_active', 'sort_order'], 'knowledge_document_categories_customer_active_sort_index');
            $table->index('sort_order');
            $table->index('is_active');
        });

        DB::statement(
            'CREATE UNIQUE INDEX knowledge_document_categories_customer_name_unique '.
            'ON knowledge_document_categories (customer_id, LOWER(name)) '.
            'WHERE deleted_at IS NULL AND is_active = TRUE'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS knowledge_document_categories_customer_name_unique');

        Schema::dropIfExists('knowledge_document_categories');
    }
};
