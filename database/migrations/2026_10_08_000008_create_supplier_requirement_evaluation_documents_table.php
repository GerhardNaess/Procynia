<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kontrollgrunnlag — exactly the documentation rows the person gave as the basis of one control
 * (docs/supplier-assurance-v2-plan.md §10.2, §10.2.1, §20.2). Not a pivot: each row also holds the
 * row's type, name, standard, location and validity as they were at the control, because
 * supplier_documents stays editable. The history shows this snapshot; today's status (expired,
 * replaced → Må fornyes) is read from the document row as it is now, never from here.
 *
 * Both references by (id, customer_id), NO ACTION: never across customers, and a document used in a
 * control can never be deleted — the delete-guard the plan locks (§10.4). That the document belongs
 * to the control's supplier is SupplierRequirementEvaluationService's check.
 *
 * Append-only like the control itself: SupplierRequirementEvaluationDocument throws on update and
 * delete, and a trigger refuses both while the customer exists. One row per document per control;
 * the same document may be the basis of any number of controls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_requirement_evaluation_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('evaluation_id');
            $table->unsignedBigInteger('supplier_document_id');
            $table->string('document_type');
            $table->string('document_title');
            $table->string('document_standard')->nullable();
            $table->text('document_location')->nullable();
            $table->date('document_valid_from')->nullable();
            $table->date('document_valid_until')->nullable();

            $table->foreign(['evaluation_id', 'customer_id'])->references(['id', 'customer_id'])->on('supplier_requirement_evaluations');
            $table->foreign(['supplier_document_id', 'customer_id'])->references(['id', 'customer_id'])->on('supplier_documents');
            $table->unique(['evaluation_id', 'supplier_document_id']);
            $table->index('supplier_document_id');
            $table->index('customer_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE supplier_requirement_evaluation_documents ADD CONSTRAINT supplier_requirement_evaluation_documents_values CHECK ('
            ."document_type IN ('agreement', 'data_processing_agreement', 'confidentiality_agreement', 'certificate', 'insurance_certificate', 'security_documentation', 'other',"
            ." 'self_declaration', 'code_of_conduct', 'audit_report', 'control_report', 'subcontractor_list', 'public_certificate', 'financial_statement', 'environmental_documentation', 'policy')"
            .' AND length(btrim(document_title)) > 0)');

        // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION supplier_requirement_evaluation_documents_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                        RAISE EXCEPTION 'supplier_requirement_evaluation_documents is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'supplier_requirement_evaluation_documents is history and cannot be changed';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_requirement_evaluation_documents_immutable
                BEFORE UPDATE OR DELETE ON supplier_requirement_evaluation_documents
                FOR EACH ROW EXECUTE FUNCTION supplier_requirement_evaluation_documents_immutable();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_requirement_evaluation_documents');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS supplier_requirement_evaluation_documents_immutable()');
        }
    }
};
