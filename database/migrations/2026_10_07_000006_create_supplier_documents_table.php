<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumentasjonsoversikt — which documentation exists for a supplier, where it is kept and when it
 * expires (docs/supplier-management-v1-plan.md §4.4, §10). Metadata only: the document itself stays
 * wherever the business already archives it, and location says where — an archive reference, a
 * SharePoint link, a case number. Nothing here stores, fetches or previews a file. Never the
 * Enterprise Wiki document store either: that is the source layer for reusable knowledge, not an
 * archive for one supplier's contracts and certificates.
 *
 * Not a contract register: no versions, signatures, amounts, notice periods or renewal workflow. An
 * agreement is one row with a validity, like a certificate.
 *
 * Mutable, unlike the supplier's history: a row is corrected in place and a mistaken one deleted,
 * as long as the supplier is not ended. «Registrer fornyet» adds a new row of the same type and
 * points the old one at it through replaced_by_document_id; the old row stays and reads as
 * Erstattet. Deleting the new row sets the pointer back to null, so the old one is current again.
 * That the replacement belongs to the same supplier is the service's check.
 *
 * The supplier is referenced by (supplier_id, customer_id) with NO ACTION: a row can never describe
 * another customer's supplier, and a supplier with documentation can never be deleted — it is ended
 * instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->string('document_type');
            $table->string('title');
            $table->text('location')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('replaced_by_document_id')->nullable()->constrained('supplier_documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['supplier_id', 'customer_id'])->references(['id', 'customer_id'])->on('suppliers');
            $table->index(['supplier_id', 'replaced_by_document_id']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE supplier_documents ADD CONSTRAINT supplier_documents_type CHECK (document_type IN ('agreement', 'data_processing_agreement', 'confidentiality_agreement', 'certificate', 'insurance_certificate', 'security_documentation', 'other'))");
        DB::statement('ALTER TABLE supplier_documents ADD CONSTRAINT supplier_documents_validity CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_from <= valid_until)');
        DB::statement('ALTER TABLE supplier_documents ADD CONSTRAINT supplier_documents_title CHECK (length(btrim(title)) > 0)');
        DB::statement('ALTER TABLE supplier_documents ADD CONSTRAINT supplier_documents_not_self_replaced CHECK (replaced_by_document_id IS NULL OR replaced_by_document_id <> id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_documents');
    }
};
