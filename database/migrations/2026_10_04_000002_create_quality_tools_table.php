<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verktøy — the documents a person uses to carry out an activity or a control correctly: a
 * veiledning, a checklist as a document, a mal, a metodebeskrivelse, a standardtekst.
 *
 * WHY A TABLE AT ALL.
 *
 * The file is already in enterprise_wiki_documents and is not copied anywhere. What the file lacks is
 * what makes it a tool in the library: a name a person recognises, a line on what it is for, and
 * what kind of tool it is. Those belong to the document, not to any one place it is used, so they
 * cannot live on quality_item_documents, whose rows are one per use.
 *
 * WHY THE USE IS A quality_item_documents ROW.
 *
 * "This control is carried out with this file" is exactly the statement that seam already makes —
 * a control pointing at a file in a given capacity — so the use is a row there in the `tool`
 * capacity. Removal is the seam's own removal, which never touches the file, and deleting the file
 * in Wiki → Kildedokumenter takes the uses with it by the existing FK cascade.
 *
 * One library entry per file: two tool entries for the same bytes would be two answers to "what is
 * this document for".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_tools', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // A tool is a statement about a file. When the file is deleted there is nothing left to
            // carry out the control with, and an entry pointing nowhere would be a lie.
            $table->foreignId('enterprise_wiki_document_id')
                ->constrained('enterprise_wiki_documents')
                ->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            // guide | checklist | template | method | standard_text | reference — optional.
            $table->string('category')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'enterprise_wiki_document_id'], 'quality_tools_document_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_tools');
    }
};
