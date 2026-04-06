<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_notice_id')->nullable()->constrained('saved_notices')->nullOnDelete();
            $table->string('event_type')->nullable();
            $table->string('severity', 32)->default('info');
            $table->string('title');
            $table->text('message');
            $table->string('target_url')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'user_id', 'is_read', 'created_at'], 'user_notifications_scope_index');
            $table->index(['customer_id', 'user_id', 'created_at'], 'user_notifications_recent_index');
            $table->index(['saved_notice_id'], 'user_notifications_saved_notice_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
