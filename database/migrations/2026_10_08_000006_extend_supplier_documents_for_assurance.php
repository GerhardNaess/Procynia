<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumentasjon as the basis of a control (docs/supplier-assurance-v2-plan.md §10.1, §20.2):
 *
 *  - nine more document types — egenerklæring, etiske retningslinjer, revisjonsrapport,
 *    kontrollrapport, underleverandørliste, offentlig attest, økonomisk dokumentasjon,
 *    miljødokumentasjon, policy/rutine — next to the v1 types, which stay;
 *  - standard, an optional free text («ISO 27001», «Miljøfyrtårn») that helps the person who
 *    controls; no rule ever reads it;
 *  - unique (id, customer_id), so a control's documentation can refer to a row by id and customer
 *    and never cross customers.
 *
 * Still metadata only, no file. No row is changed: every existing row is valid under the wider
 * CHECK. down() restores the v1 CHECK and fails on purpose while a new type is in use.
 */
return new class extends Migration
{
    private const V1_TYPES = ['agreement', 'data_processing_agreement', 'confidentiality_agreement', 'certificate', 'insurance_certificate', 'security_documentation', 'other'];

    private const V2_TYPES = ['self_declaration', 'code_of_conduct', 'audit_report', 'control_report', 'subcontractor_list', 'public_certificate', 'financial_statement', 'environmental_documentation', 'policy'];

    public function up(): void
    {
        Schema::table('supplier_documents', function (Blueprint $table): void {
            $table->string('standard')->nullable()->after('title');
            $table->unique(['id', 'customer_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->typeCheck([...self::V1_TYPES, ...self::V2_TYPES]);
        DB::statement('ALTER TABLE supplier_documents ADD CONSTRAINT supplier_documents_standard CHECK (standard IS NULL OR length(btrim(standard)) > 0)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_documents DROP CONSTRAINT IF EXISTS supplier_documents_standard');
            $this->typeCheck(self::V1_TYPES);
        }

        Schema::table('supplier_documents', function (Blueprint $table): void {
            $table->dropUnique(['id', 'customer_id']);
            $table->dropColumn('standard');
        });
    }

    /** @param  list<string>  $types */
    private function typeCheck(array $types): void
    {
        DB::statement('ALTER TABLE supplier_documents DROP CONSTRAINT IF EXISTS supplier_documents_type');
        DB::statement("ALTER TABLE supplier_documents ADD CONSTRAINT supplier_documents_type CHECK (document_type IN ('".implode("', '", $types)."'))");
    }
};
