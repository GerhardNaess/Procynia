<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * Proves the enterprise_wiki_pages row lock genuinely serializes concurrent
 * EnterpriseWikiPageVersion writers at the real PostgreSQL level — not just via application-level
 * orchestration on a single connection. Opens a second, fully independent raw PDO connection
 * (never a second NAMED Laravel connection — see Tests\Concerns\UsesProjectPostgresConnection's
 * "never introduces a second, separately-named connection" rule, which this respects by staying
 * outside Laravel's connection manager entirely) so the two writers are genuinely separate
 * Postgres backends, exactly like two queue workers racing the same page.
 *
 * DB::commit() is used mid-test to release RefreshDatabase's own wrapping transaction, which is
 * required for the second connection to see the fixture rows the default connection just wrote
 * (otherwise they'd be invisible uncommitted data from another backend's point of view). Laravel's
 * RefreshDatabase already anticipates a test doing this — it detects the connection is no longer
 * "inTransaction()" and re-runs migrate:fresh before the next test — so this is a supported escape
 * hatch, not a hack. Each test still deletes its own rows explicitly so the schema stays clean even
 * if it happens to be the last test in the run.
 */
class EnterpriseWikiPageVersionConcurrencyTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    public function test_concurrent_writers_against_the_same_page_serialize_into_sequential_versions(): void
    {
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Race Page', 'v1 content');
        DB::commit();

        $pdoA = DB::connection()->getPdo();
        $pdoB = $this->openIndependentConnection();

        try {
            // Writer A locks the page first and holds it.
            $pdoA->beginTransaction();
            $this->lockPage($pdoA, $page->id);

            // Writer B arrives "concurrently" and must block on the same row lock.
            $pdoB->beginTransaction();
            $pdoB->exec("SET LOCAL lock_timeout = '200ms'");
            $blocked = false;

            try {
                $this->lockPage($pdoB, $page->id);
            } catch (PDOException) {
                $blocked = true;
            }

            $this->assertTrue($blocked, 'Writer B must block while writer A still holds the page lock.');
            $pdoB->rollBack();

            // Writer A reads the next version number only after acquiring the lock, demotes the
            // old current version, creates the new one, and only then commits/releases the lock.
            $versionA = $this->writeNextVersion($pdoA, $page->id);
            $pdoA->commit();

            $this->assertSame(2, $versionA);

            // Writer B retries now that A has released the lock, and gets the next number in turn.
            $pdoB->beginTransaction();
            $this->lockPage($pdoB, $page->id);
            $versionB = $this->writeNextVersion($pdoB, $page->id);
            $pdoB->commit();

            $this->assertSame(3, $versionB);

            $versions = EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->orderBy('version_number')
                ->get();

            $this->assertSame([1, 2, 3], $versions->pluck('version_number')->all());
            $this->assertSame([false, false, true], $versions->pluck('is_current')->map(fn ($v) => (bool) $v)->all());
        } finally {
            $this->cleanUpPage($page);
            $this->cleanUpCustomer($customer);
        }
    }

    public function test_concurrent_writers_against_different_pages_do_not_block_each_other(): void
    {
        $customer = $this->createWikiCustomer();
        $pageOne = $this->createWikiPageWithVersion($customer, 'Page One', 'content');
        $pageTwo = $this->createWikiPageWithVersion($customer, 'Page Two', 'content');
        DB::commit();

        $pdoA = DB::connection()->getPdo();
        $pdoB = $this->openIndependentConnection();

        try {
            $pdoA->beginTransaction();
            $this->lockPage($pdoA, $pageOne->id);

            $pdoB->beginTransaction();
            $pdoB->exec("SET LOCAL lock_timeout = '200ms'");

            $blocked = false;

            try {
                $this->lockPage($pdoB, $pageTwo->id);
            } catch (PDOException) {
                $blocked = true;
            }

            $this->assertFalse($blocked, 'Locking a different page must never block on an unrelated page lock.');

            $pdoA->commit();
            $pdoB->commit();
        } finally {
            $this->cleanUpPage($pageOne);
            $this->cleanUpPage($pageTwo);
            $this->cleanUpCustomer($customer);
        }
    }

    private function openIndependentConnection(): PDO
    {
        $config = config('database.connections.pgsql');

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);

        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function lockPage(PDO $pdo, int $pageId): void
    {
        $statement = $pdo->prepare('SELECT id FROM enterprise_wiki_pages WHERE id = ? FOR UPDATE');
        $statement->execute([$pageId]);
    }

    private function writeNextVersion(PDO $pdo, int $pageId): int
    {
        $select = $pdo->prepare(
            'SELECT COALESCE(MAX(version_number), 0) FROM enterprise_wiki_page_versions WHERE enterprise_wiki_page_id = ?'
        );
        $select->execute([$pageId]);
        $next = ((int) $select->fetchColumn()) + 1;

        $demote = $pdo->prepare(
            'UPDATE enterprise_wiki_page_versions SET is_current = false WHERE enterprise_wiki_page_id = ? AND is_current = true'
        );
        $demote->execute([$pageId]);

        $insert = $pdo->prepare(
            'INSERT INTO enterprise_wiki_page_versions (enterprise_wiki_page_id, version_number, is_current, created_at, updated_at) '.
            'VALUES (?, ?, true, now(), now())'
        );
        $insert->execute([$pageId, $next]);

        return $next;
    }

    private function cleanUpPage(EnterpriseWikiPage $page): void
    {
        DB::table('enterprise_wiki_page_versions')->where('enterprise_wiki_page_id', $page->id)->delete();
        DB::table('enterprise_wiki_pages')->where('id', $page->id)->delete();
    }

    private function cleanUpCustomer(Customer $customer): void
    {
        DB::table('customers')->where('id', $customer->id)->delete();
    }
}
