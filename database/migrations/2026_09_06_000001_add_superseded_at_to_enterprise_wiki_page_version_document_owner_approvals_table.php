<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An approval row is two things at once: the requirement "this owner must sign off on this document
 * set", and the record "this owner decided at this time". Nothing distinguished them, so once a row
 * stopped being required — the document changed owner, or the document set behind the owner changed —
 * it stayed in the table looking exactly like a live requirement.
 *
 * approvedCurrentVersionPageIds() demands that EVERY row on the current version be approved, so one
 * orphaned pending row locked a page out of approved knowledge permanently: it was not in the
 * requirement set any more, so it never appeared in any approval UI for anyone to clear.
 *
 * superseded_at marks a row as historic. The decision it records is preserved for audit; it just
 * stops counting as something still to be done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_page_version_document_owner_approvals', function (Blueprint $table): void {
            $table->timestamp('superseded_at')->nullable()->after('overridden_at');

            // Every read of "what is still required" filters on this, alongside the version.
            $table->index(
                ['enterprise_wiki_page_version_id', 'superseded_at'],
                'ewpvoa_version_superseded_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_page_version_document_owner_approvals', function (Blueprint $table): void {
            $table->dropIndex('ewpvoa_version_superseded_index');
            $table->dropColumn('superseded_at');
        });
    }
};
