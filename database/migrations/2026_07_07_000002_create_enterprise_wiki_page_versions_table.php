<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_page_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_wiki_page_id')->constrained('enterprise_wiki_pages')->cascadeOnDelete();
            $table->unsignedInteger('version_number')->default(1);
            $table->boolean('is_current')->default(false);
            $table->longText('content_markdown')->nullable();
            $table->string('generated_by_model')->nullable();
            $table->string('generation_prompt_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['enterprise_wiki_page_id', 'is_current']);
            $table->index(['enterprise_wiki_page_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_page_versions');
    }
};
