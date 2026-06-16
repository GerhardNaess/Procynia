<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_item_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_item_id')
                ->nullable()
                ->constrained('knowledge_items')
                ->nullOnDelete();
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();
            $table->unsignedInteger('revision_no');
            $table->string('change_type', 50);
            $table->foreignId('changed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->json('snapshot');
            $table->timestamps();

            $table->unique(['knowledge_item_id', 'revision_no'], 'knowledge_item_revisions_item_revision_unique');
            $table->index('customer_id', 'knowledge_item_revisions_customer_index');
            $table->index('change_type', 'knowledge_item_revisions_change_type_index');
            $table->index('changed_by_user_id', 'knowledge_item_revisions_changed_by_user_index');
            $table->index('created_at', 'knowledge_item_revisions_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_item_revisions');
    }
};
