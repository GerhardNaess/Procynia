<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence on a control — documentation that the control is met.
 *
 * WHY quality_item_documents AND NOT A NEW quality_control_evidence TABLE.
 *
 * This seam already carries `relation_type = evidence` — "a record that the work described actually
 * happened" — and a file attached to a control in that capacity is exactly what evidence is. A
 * second table would give the same statement two homes and two removal stories.
 *
 * What the seam lacked is evidence that is not (yet) a file in the store: "Protokoll fra ledelsens
 * gjennomgang 2026, ligger i styreportalen". So a row gets a name of its own, and the file becomes
 * optional — for evidence only. Every other capacity is still a statement about a file, and the
 * check constraint keeps it that way.
 *
 * The FK keeps cascadeOnDelete: deleting the file in Wiki → Kildedokumenter takes evidence that
 * consisted of that file with it, as it always did. Switching to nullOnDelete would turn every
 * source/template row into a file-less row the constraint forbids, and make the deletion fail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_item_documents', function (Blueprint $table): void {
            $table->string('title')->nullable()->after('relation_type');
            $table->foreignId('enterprise_wiki_document_id')->nullable()->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE quality_item_documents
                ADD CONSTRAINT quality_item_documents_file_or_named_evidence
                CHECK (
                    enterprise_wiki_document_id IS NOT NULL
                    OR (relation_type = 'evidence' AND title IS NOT NULL)
                )
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE quality_item_documents DROP CONSTRAINT IF EXISTS quality_item_documents_file_or_named_evidence');
        }

        DB::table('quality_item_documents')->whereNull('enterprise_wiki_document_id')->delete();

        Schema::table('quality_item_documents', function (Blueprint $table): void {
            $table->dropColumn('title');
            $table->foreignId('enterprise_wiki_document_id')->nullable(false)->change();
        });
    }
};
