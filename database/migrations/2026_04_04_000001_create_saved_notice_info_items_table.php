<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_notice_info_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_notice_id')->constrained('saved_notices')->cascadeOnDelete();
            $table->string('type');
            $table->string('direction');
            $table->string('channel');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('status');
            $table->boolean('requires_response')->default(false);
            $table->timestamp('response_due_at')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['saved_notice_id', 'created_at']);
            $table->index(['saved_notice_id', 'status']);
            $table->index(['saved_notice_id', 'response_due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_notice_info_items');
    }
};
