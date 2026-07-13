<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_claim_source_reconciliation_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('enterprise_wiki_claim_id');
            $table->foreign('enterprise_wiki_claim_id', 'ewcsra_claim_id_foreign')
                ->references('id')->on('enterprise_wiki_claims')->cascadeOnDelete();

            $table->unsignedBigInteger('enterprise_wiki_document_id');
            $table->foreign('enterprise_wiki_document_id', 'ewcsra_document_id_foreign')
                ->references('id')->on('enterprise_wiki_documents')->cascadeOnDelete();

            $table->string('status')->default('pending');
            $table->string('result')->nullable();

            $table->unsignedBigInteger('enterprise_wiki_source_reference_id')->nullable();
            $table->foreign('enterprise_wiki_source_reference_id', 'ewcsra_source_reference_id_foreign')
                ->references('id')->on('enterprise_wiki_source_references')->nullOnDelete();

            $table->timestamp('claimed_at')->nullable();
            $table->string('claim_token')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(
                ['enterprise_wiki_claim_id', 'enterprise_wiki_document_id'],
                'ewcsra_claim_document_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_claim_source_reconciliation_attempts');
    }
};
