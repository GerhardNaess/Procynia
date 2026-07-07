<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('slug');
            $table->string('title');
            $table->string('scope')->default('company');
            $table->string('status')->default('draft');
            $table->string('generated_by')->default('ai_job');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('last_source_hash', 64)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'slug']);
            $table->index('customer_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_pages');
    }
};
