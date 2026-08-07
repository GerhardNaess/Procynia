<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enforces, at the database level, the two invariants every EnterpriseWikiPageVersion writer
 * must already respect: exactly one current version per page, and a version_number unique per
 * page. Prior to this migration both were application-level conventions only (see
 * App\Services\EnterpriseWiki\EnterpriseWikiPageVersionWriter), so a lost-update race between two
 * concurrent writers touching the same page (e.g. two repair jobs, or a repair racing ordinary
 * generation) could silently produce a duplicate version_number or more than one is_current row.
 *
 * Refuses to add either constraint if existing data already violates it — this migration never
 * deletes or repairs rows; a violation must be resolved by hand before this can run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicateVersionNumbers = DB::table('enterprise_wiki_page_versions')
            ->select('enterprise_wiki_page_id', 'version_number')
            ->groupBy('enterprise_wiki_page_id', 'version_number')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicateVersionNumbers->isNotEmpty()) {
            $offenders = $duplicateVersionNumbers
                ->map(fn (object $row): string => "page {$row->enterprise_wiki_page_id} version_number {$row->version_number}")
                ->implode(', ');

            throw new RuntimeException(
                'Cannot enforce unique (enterprise_wiki_page_id, version_number): duplicates already exist for '.$offenders.
                '. Resolve these rows by hand before rerunning this migration.'
            );
        }

        $multipleCurrentVersions = DB::table('enterprise_wiki_page_versions')
            ->select('enterprise_wiki_page_id')
            ->where('is_current', true)
            ->groupBy('enterprise_wiki_page_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($multipleCurrentVersions->isNotEmpty()) {
            $offenders = $multipleCurrentVersions
                ->map(fn (object $row): string => "page {$row->enterprise_wiki_page_id}")
                ->implode(', ');

            throw new RuntimeException(
                'Cannot enforce at most one is_current version per page: multiple current versions already exist for '.$offenders.
                '. Resolve these rows by hand before rerunning this migration.'
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX ewpv_page_version_number_unique '.
            'ON enterprise_wiki_page_versions (enterprise_wiki_page_id, version_number)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX ewpv_page_single_current_unique '.
            'ON enterprise_wiki_page_versions (enterprise_wiki_page_id) '.
            'WHERE is_current = true'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ewpv_page_version_number_unique');
        DB::statement('DROP INDEX IF EXISTS ewpv_page_single_current_unique');
    }
};
