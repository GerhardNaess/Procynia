<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_lint_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('enterprise_wiki_page_id')->nullable()->constrained('enterprise_wiki_pages')->nullOnDelete();
            $table->foreignId('enterprise_wiki_claim_id')->nullable()->constrained('enterprise_wiki_claims')->nullOnDelete();
            $table->foreignId('enterprise_wiki_document_id')->nullable()->constrained('enterprise_wiki_documents')->nullOnDelete();
            $table->string('code');
            $table->string('severity'); // info, warning, error
            $table->text('message');
            $table->string('status')->default('open'); // open, resolved
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['enterprise_wiki_page_id', 'code']);
            $table->index(['enterprise_wiki_claim_id', 'code']);
            $table->index(['enterprise_wiki_document_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_lint_findings');
    }
};
