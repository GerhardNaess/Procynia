<?php

namespace Tests\Feature\App\Wiki;

use App\Models\EnterpriseWikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Proves the enterprise_wiki_pages row lock genuinely serializes concurrent
 * EnterpriseWikiPageVersion writers at the real PostgreSQL level — not just via application-level
 * orchestration on a single connection. The writers are separate raw PDO connections (never a
 * second NAMED Laravel connection — see Tests\Concerns\UsesProjectPostgresConnection's "never
 * introduces a second, separately-named connection" rule, which this respects by staying outside
 * Laravel's connection manager entirely), so they are genuinely separate Postgres backends,
 * exactly like two queue workers racing the same page.
 *
 * LIFECYCLE CONTRACT — read before changing anything in this file.
 *
 * A cross-backend test needs COMMITTED rows: one Postgres backend cannot see another backend's
 * uncommitted data, so the fixtures have to be outside any open transaction. This class therefore
 * owns its own data lifecycle end to end:
 *
 * - Every row it needs is created, read and deleted on independent PDO connections it opens
 *   itself (see openIndependentConnection()), which commit as they go.
 * - Laravel's DEFAULT connection is never written to and never committed. RefreshDatabase's
 *   wrapping transaction is opened and rolled back completely untouched, exactly as in every
 *   ordinary test.
 * - RefreshDatabase is kept solely for what it is good at here: guaranteeing the schema exists
 *   and owning RefreshDatabaseState.
 *
 * This replaces an earlier version that called DB::commit() mid-test to publish its fixtures. That
 * ended the framework's own outer transaction, so RefreshDatabase's teardown hook observed a
 * connection no longer "inTransaction()" and set RefreshDatabaseState::$migrated = false
 * (vendor/laravel/framework/.../Testing/RefreshDatabase.php, beginDatabaseTransaction()). The next
 * RefreshDatabase test in the process — and this class's own second test method — then paid for a
 * full migrate:fresh. Do not reintroduce DB::commit(), and do not "fix" a recurrence by assigning
 * RefreshDatabaseState::$migrated by hand: that hides a broken lifecycle instead of owning one.
 *
 * Because the fixtures are genuinely committed, they are NOT rolled back for us and are no longer
 * incidentally wiped by that stray migrate:fresh either. Everything this class creates is tracked
 * and deleted in tearDown() in FK-safe order — pages (versions cascade), then customers, then the
 * languages/nationalities lookup rows, and those last two only when this test created them. Any
 * new fixture row added here must be tracked and removed the same way, or it will leak into every
 * later test in the process.
 */
class EnterpriseWikiPageVersionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every PDO handle this test opened, so tearDown() can end their transactions and close them
     * even when a test fails part-way through holding a row lock.
     *
     * @var list<PDO>
     */
    private array $openedConnections = [];

    /**
     * The connection used for fixture setup, assertions and cleanup — deliberately separate from
     * the two racing writers so it is never the connection under test.
     */
    private ?PDO $control = null;

    /** @var list<int> */
    private array $createdPageIds = [];

    /** @var list<int> */
    private array $createdCustomerIds = [];

    private ?int $createdLanguageId = null;

    private ?int $createdNationalityId = null;

    protected function tearDown(): void
    {
        try {
            $this->rollBackOpenConnectionTransactions();
            $this->deleteCommittedFixtures();
        } finally {
            $this->openedConnections = [];
            $this->control = null;

            parent::tearDown();
        }
    }

    public function test_concurrent_writers_against_the_same_page_serialize_into_sequential_versions(): void
    {
        $customerId = $this->createCommittedCustomer();
        $pageId = $this->createCommittedPageWithFirstVersion($customerId, 'Race Page', 'v1 content');

        $pdoA = $this->openIndependentConnection();
        $pdoB = $this->openIndependentConnection();

        // Writer A locks the page first and holds it.
        $pdoA->beginTransaction();
        $this->lockPage($pdoA, $pageId);

        // Writer B arrives "concurrently" and must block on the same row lock.
        $pdoB->beginTransaction();
        $pdoB->exec("SET LOCAL lock_timeout = '200ms'");
        $blocked = false;

        try {
            $this->lockPage($pdoB, $pageId);
        } catch (PDOException) {
            $blocked = true;
        }

        $this->assertTrue($blocked, 'Writer B must block while writer A still holds the page lock.');
        $pdoB->rollBack();

        // Writer A reads the next version number only after acquiring the lock, demotes the old
        // current version, creates the new one, and only then commits/releases the lock.
        $versionA = $this->writeNextVersion($pdoA, $pageId);
        $pdoA->commit();

        $this->assertSame(2, $versionA);

        // Writer B retries now that A has released the lock, and gets the next number in turn.
        $pdoB->beginTransaction();
        $this->lockPage($pdoB, $pageId);
        $versionB = $this->writeNextVersion($pdoB, $pageId);
        $pdoB->commit();

        $this->assertSame(3, $versionB);

        $versions = $this->fetchVersions($pageId);

        $this->assertSame([1, 2, 3], array_column($versions, 'version_number'));
        $this->assertSame([false, false, true], array_column($versions, 'is_current'));
    }

    public function test_concurrent_writers_against_different_pages_do_not_block_each_other(): void
    {
        $customerId = $this->createCommittedCustomer();
        $pageOneId = $this->createCommittedPageWithFirstVersion($customerId, 'Page One', 'content');
        $pageTwoId = $this->createCommittedPageWithFirstVersion($customerId, 'Page Two', 'content');

        $pdoA = $this->openIndependentConnection();
        $pdoB = $this->openIndependentConnection();

        $pdoA->beginTransaction();
        $this->lockPage($pdoA, $pageOneId);

        $pdoB->beginTransaction();
        $pdoB->exec("SET LOCAL lock_timeout = '200ms'");

        $blocked = false;

        try {
            $this->lockPage($pdoB, $pageTwoId);
        } catch (PDOException) {
            $blocked = true;
        }

        $this->assertFalse($blocked, 'Locking a different page must never block on an unrelated page lock.');

        $pdoA->commit();
        $pdoB->commit();
    }

    /**
     * Opens a raw PDO connection to the test database, outside Laravel's connection manager, and
     * registers it for teardown. Re-checks the configured database name first: unlike every other
     * test, the rows written through these handles are genuinely committed, so the usual
     * "RefreshDatabase rolls it all back" backstop does not apply here.
     */
    private function openIndependentConnection(): PDO
    {
        $config = config('database.connections.pgsql');

        $this->assertSame(
            'procynia_test',
            $config['database'],
            'This test commits real rows on a raw connection and must never be pointed anywhere but the test database.',
        );

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);

        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $this->openedConnections[] = $pdo;

        return $pdo;
    }

    private function controlConnection(): PDO
    {
        return $this->control ??= $this->openIndependentConnection();
    }

    private function createCommittedCustomer(string $name = 'Wiki Concurrency Test AS'): int
    {
        $pdo = $this->controlConnection();

        $customerId = (int) $this->scalar(
            $pdo,
            'INSERT INTO customers (name, slug, language_id, nationality_id, is_active, created_at, updated_at) '.
            'VALUES (?, ?, ?, ?, true, now(), now()) RETURNING id',
            [$name, Str::slug($name).'-'.Str::lower(Str::random(8)), $this->languageId($pdo), $this->nationalityId($pdo)],
        );

        $this->createdCustomerIds[] = $customerId;

        return $customerId;
    }

    private function createCommittedPageWithFirstVersion(int $customerId, string $title, string $markdown): int
    {
        $pdo = $this->controlConnection();

        $pageId = (int) $this->scalar(
            $pdo,
            'INSERT INTO enterprise_wiki_pages (customer_id, slug, title, page_type, status, generated_by, created_at, updated_at) '.
            'VALUES (?, ?, ?, ?, ?, ?, now(), now()) RETURNING id',
            [
                $customerId,
                Str::slug($title).'-'.Str::lower(Str::random(8)),
                $title,
                EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
                EnterpriseWikiPage::STATUS_APPROVED,
                EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            ],
        );

        $this->createdPageIds[] = $pageId;

        $pdo->prepare(
            'INSERT INTO enterprise_wiki_page_versions (enterprise_wiki_page_id, version_number, is_current, content_markdown, created_at, updated_at) '.
            'VALUES (?, 1, true, ?, now(), now())'
        )->execute([$pageId, $markdown]);

        return $pageId;
    }

    /**
     * Reuses an existing lookup row when the schema already has one, and remembers the id only
     * when this test inserted it — so cleanup removes exactly what this test added and nothing a
     * migration or another fixture put there.
     */
    private function languageId(PDO $pdo): int
    {
        $existing = $this->scalar($pdo, 'SELECT id FROM languages WHERE code = ? LIMIT 1', ['no']);

        if ($existing !== null) {
            return (int) $existing;
        }

        return $this->createdLanguageId = (int) $this->scalar(
            $pdo,
            'INSERT INTO languages (code, name_en, name_no, created_at, updated_at) VALUES (?, ?, ?, now(), now()) RETURNING id',
            ['no', 'Norwegian', 'Norsk'],
        );
    }

    private function nationalityId(PDO $pdo): int
    {
        $existing = $this->scalar($pdo, 'SELECT id FROM nationalities WHERE code = ? LIMIT 1', ['NO']);

        if ($existing !== null) {
            return (int) $existing;
        }

        return $this->createdNationalityId = (int) $this->scalar(
            $pdo,
            'INSERT INTO nationalities (code, name_en, name_no, flag_emoji, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now()) RETURNING id',
            ['NO', 'Norwegian', 'Norsk', 'NO'],
        );
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

    /**
     * @return list<array{version_number: int, is_current: bool}>
     */
    private function fetchVersions(int $pageId): array
    {
        $statement = $this->controlConnection()->prepare(
            'SELECT version_number, is_current FROM enterprise_wiki_page_versions '.
            'WHERE enterprise_wiki_page_id = ? ORDER BY version_number'
        );
        $statement->execute([$pageId]);

        return array_map(
            fn (array $row): array => [
                'version_number' => (int) $row['version_number'],
                'is_current' => (bool) $row['is_current'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private function scalar(PDO $pdo, string $query, array $bindings = []): mixed
    {
        $statement = $pdo->prepare($query);
        $statement->execute($bindings);

        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * A failing assertion can leave a writer holding a row lock. Ending those transactions first
     * is what keeps cleanup from blocking on the test's own lock.
     */
    private function rollBackOpenConnectionTransactions(): void
    {
        foreach ($this->openedConnections as $pdo) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function deleteCommittedFixtures(): void
    {
        if ($this->control === null) {
            return;
        }

        // FK order: page versions cascade from pages, customers only after their pages are gone,
        // and the lookup rows only after the customers that reference them.
        foreach ($this->createdPageIds as $pageId) {
            $this->control->prepare('DELETE FROM enterprise_wiki_pages WHERE id = ?')->execute([$pageId]);
        }

        foreach ($this->createdCustomerIds as $customerId) {
            $this->control->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        }

        if ($this->createdNationalityId !== null) {
            $this->control->prepare('DELETE FROM nationalities WHERE id = ?')->execute([$this->createdNationalityId]);
        }

        if ($this->createdLanguageId !== null) {
            $this->control->prepare('DELETE FROM languages WHERE id = ?')->execute([$this->createdLanguageId]);
        }

        $this->createdPageIds = [];
        $this->createdCustomerIds = [];
        $this->createdNationalityId = null;
        $this->createdLanguageId = null;
    }
}
