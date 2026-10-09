<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leverandøroppfølging v2.1: one private file per documentation row (docs/supplier-assurance-v2-plan.md
 * §10.6, §27). The row stays the description it always was; a file is optional and kept in the
 * private file store (App\Support\PrivateFiles), never in the Enterprise Wiki and never read by AI.
 *
 * supplier_documents gets the file's identity and what was computed from its bytes on the server:
 * file_key (the ULID that is also the stored file name), the internal file_path (never shown), the
 * person's file name, the type decided from the content, size, SHA-256 and the malware scan status
 * («not_scanned» until a scanner is connected). The file columns are all set or all empty.
 *
 * supplier_requirement_evaluation_documents — a control's snapshot of the rows it rests on — gets the
 * file's key, name and SHA-256 as they were at the control, so the history can show which file the
 * control was based on. Old snapshots have none (there were no files). Adding nullable columns
 * changes no row, so the table's immutability trigger is untouched; new snapshots are written with
 * the columns filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_documents', function (Blueprint $table): void {
            $table->string('file_key', 26)->nullable()->unique();
            $table->string('file_path')->nullable()->unique();
            $table->string('file_original_name')->nullable();
            $table->string('file_mime_type')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->string('file_scan_status')->nullable();
            $table->foreignId('file_uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('file_uploaded_at')->nullable();
        });

        Schema::table('supplier_requirement_evaluation_documents', function (Blueprint $table): void {
            $table->string('document_file_key', 26)->nullable();
            $table->string('document_file_name')->nullable();
            $table->char('document_file_sha256', 64)->nullable();
            $table->index('document_file_key');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE supplier_documents ADD CONSTRAINT supplier_documents_file_complete CHECK ('
            .'(file_key IS NULL AND file_path IS NULL AND file_original_name IS NULL AND file_mime_type IS NULL AND file_size_bytes IS NULL AND file_sha256 IS NULL AND file_scan_status IS NULL AND file_uploaded_at IS NULL)'
            .' OR (file_key IS NOT NULL AND file_path IS NOT NULL AND length(btrim(file_original_name)) > 0 AND file_mime_type IS NOT NULL AND file_size_bytes IS NOT NULL AND file_sha256 IS NOT NULL AND file_scan_status IS NOT NULL AND file_uploaded_at IS NOT NULL))');
        DB::statement('ALTER TABLE supplier_documents ADD CONSTRAINT supplier_documents_file_values CHECK (file_key IS NULL OR ('
            ."file_path = 'customers/' || customer_id || '/supplier-documents/' || file_key || '.' || split_part(file_path, '.', 2)"
            ." AND split_part(file_path, '.', 2) IN ('pdf', 'docx', 'xlsx', 'png', 'jpg')"
            ." AND file_mime_type IN ('application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'image/png', 'image/jpeg')"
            .' AND file_size_bytes BETWEEN 1 AND 20971520'
            ." AND file_sha256 ~ '^[0-9a-f]{64}$'"
            ." AND file_scan_status IN ('not_scanned', 'pending', 'clean', 'infected')))");
        DB::statement('ALTER TABLE supplier_requirement_evaluation_documents ADD CONSTRAINT supplier_requirement_evaluation_documents_file CHECK ('
            .'(document_file_key IS NULL AND document_file_name IS NULL AND document_file_sha256 IS NULL)'
            ." OR (document_file_key IS NOT NULL AND document_file_name IS NOT NULL AND document_file_sha256 ~ '^[0-9a-f]{64}$'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_requirement_evaluation_documents DROP CONSTRAINT IF EXISTS supplier_requirement_evaluation_documents_file');
            DB::statement('ALTER TABLE supplier_documents DROP CONSTRAINT IF EXISTS supplier_documents_file_values');
            DB::statement('ALTER TABLE supplier_documents DROP CONSTRAINT IF EXISTS supplier_documents_file_complete');
        }

        Schema::table('supplier_requirement_evaluation_documents', function (Blueprint $table): void {
            $table->dropIndex(['document_file_key']);
            $table->dropColumn(['document_file_key', 'document_file_name', 'document_file_sha256']);
        });

        Schema::table('supplier_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('file_uploaded_by');
            $table->dropUnique(['file_key']);
            $table->dropUnique(['file_path']);
            $table->dropColumn(['file_key', 'file_path', 'file_original_name', 'file_mime_type', 'file_size_bytes', 'file_sha256', 'file_scan_status', 'file_uploaded_at']);
        });
    }
};
