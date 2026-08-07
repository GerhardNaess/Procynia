<?php

namespace Tests\Feature\App\Wiki;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * Covers the guard logic in the
 * 2026_08_07_000001_add_authoritative_version_constraints_to_enterprise_wiki_page_versions_table
 * migration itself — it must refuse to add either constraint (and must not touch/repair any row)
 * when existing data already violates the invariant it's about to enforce.
 *
 * Each test drops the two indexes first (simulating "before this migration ran"), corrupts the
 * data in the specific way the guard must catch, then re-requires and re-runs the migration's
 * up(). RefreshDatabase's per-test transaction rolls back the DROP INDEX/bad inserts afterward, so
 * this never leaves the schema in a degraded state for later tests.
 */
class EnterpriseWikiPageVersionConstraintsMigrationTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_07_000001_add_authoritative_version_constraints_to_enterprise_wiki_page_versions_table.php';

    public function test_migration_refuses_to_run_when_duplicate_version_numbers_already_exist(): void
    {
        $this->dropConstraintIndexes();

        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Guard Page', 'v1');

        DB::table('enterprise_wiki_page_versions')->insert([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/duplicate/i');

        $this->migration()->up();
    }

    public function test_migration_refuses_to_run_when_multiple_current_versions_already_exist(): void
    {
        $this->dropConstraintIndexes();

        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Guard Page', 'v1');

        DB::table('enterprise_wiki_page_versions')->insert([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/current version/i');

        $this->migration()->up();
    }

    public function test_migration_succeeds_and_recreates_both_indexes_when_data_is_clean(): void
    {
        $this->dropConstraintIndexes();

        $customer = $this->createWikiCustomer();
        $this->createWikiPageWithVersion($customer, 'Clean Page', 'v1');

        $this->migration()->up();

        $this->assertTrue($this->indexExists('ewpv_page_version_number_unique'));
        $this->assertTrue($this->indexExists('ewpv_page_single_current_unique'));
    }

    private function migration(): object
    {
        // A plain require() (never require_once/getRequire-with-caching) — PHP re-executes an
        // anonymous class's `new class {...}` expression cleanly on every require() of the same
        // file, producing a fresh, independent, fully usable instance each time (verified: no
        // redeclaration fatal). require_once would be unsafe here: Laravel's own migrator already
        // required this exact path once via requireOnce() during migrate:fresh, so a later
        // require_once() call in the same process returns bool(true), not the migration object.
        return require base_path(self::MIGRATION_PATH);
    }

    private function dropConstraintIndexes(): void
    {
        DB::statement('DROP INDEX IF EXISTS ewpv_page_version_number_unique');
        DB::statement('DROP INDEX IF EXISTS ewpv_page_single_current_unique');
    }

    private function indexExists(string $indexName): bool
    {
        return DB::table('pg_indexes')
            ->where('tablename', 'enterprise_wiki_page_versions')
            ->where('indexname', $indexName)
            ->exists();
    }
}
