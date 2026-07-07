<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_source_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_wiki_claim_id')->constrained('enterprise_wiki_claims')->cascadeOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('source_label');
            $table->text('excerpt')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->string('page_reference')->nullable();
            $table->timestamps();

            $table->index('enterprise_wiki_claim_id');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_source_references');
    }
};
