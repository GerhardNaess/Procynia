<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Risiko → kilde til → Enterprise Wiki-kunnskap.
 *
 * Provenance and nothing else. A person stood on a risk, wrote down what is reusable about it in
 * their own words, and that text became an ordinary Wiki SOURCE document handed to the ordinary
 * ingest run — the same path a Kvalitet activity article takes (QualityActivityArticleService).
 * After that the Wiki owns the knowledge: there is no title, no text and no status here, because
 * every one of them would be a copy, and a copy of something the risk deliberately did not share.
 *
 * ONE DIRECTION. Only Risiko reads this table. Nothing in the Wiki joins to it, so a Wiki reader
 * cannot learn from a page that a risk — or which risk — was behind it.
 *
 * DELETION, BOTH WAYS. Deleting the source document in Wiki removes the row and leaves the risk
 * untouched. Deleting the risk removes the row and leaves the Wiki untouched: the knowledge was
 * handed over, and it does not go back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_wiki_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained('risks')->cascadeOnDelete();
            $table->foreignId('enterprise_wiki_document_id')->constrained('enterprise_wiki_documents')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['risk_id', 'enterprise_wiki_document_id']);
            $table->index(['customer_id', 'risk_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_wiki_sources');
    }
};
