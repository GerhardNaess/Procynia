<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_page_helps', function (Blueprint $table): void {
            $table->id();
            $table->string('page_key')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('intro')->nullable();
            $table->json('sections')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_page_helps');
    }
};
