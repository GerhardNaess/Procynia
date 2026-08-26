<?php

namespace Tests\Feature\Azure;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Azure migration readiness — migrations as a separate job, and pgvector.
 *
 * In Azure, migrations are not part of web startup. They run as a Container Apps Job between the
 * platform deployment and the workload deployment, which is also the step that creates the pgvector
 * extension — Bicep only adds "vector" to the server-level azure.extensions allowlist, it never runs
 * CREATE EXTENSION.
 *
 * The required order is:
 *   1. Azure PostgreSQL Flexible Server is created
 *   2. azure.extensions allows VECTOR
 *   3. the migration job runs
 *   4. the Laravel migration issues CREATE EXTENSION IF NOT EXISTS vector
 *
 * Everything here runs against procynia_test. The migration child process is given the test
 * database explicitly and refuses to run if the live connection resolves anywhere else.
 */
class MigrationJobAndPgvectorContractTest extends TestCase
{
    private const PGVECTOR_MIGRATION = 'database/migrations/2026_05_21_000001_add_pgvector_embedding_column_to_knowledge_item_chunks_table.php';

    // -----------------------------------------------------------------------
    // Migrations are a separate job
    // -----------------------------------------------------------------------

    /**
     * Nothing in the request/boot path may run migrations. In Azure that would mean every web
     * replica racing to migrate on cold start.
     */
    public function test_nothing_runs_migrations_during_application_boot(): void
    {
        $matches = [];
        exec(
            sprintf(
                'grep -rn %s %s %s %s 2>/dev/null',
                escapeshellarg("Artisan::call('migrate"),
                escapeshellarg(base_path('app')),
                escapeshellarg(base_path('bootstrap')),
                escapeshellarg(base_path('routes')),
            ),
            $matches,
        );

        $this->assertSame(
            [],
            $matches,
            'Migrations must never be triggered from application code: '.implode("\n", $matches),
        );

        $this->assertStringNotContainsString(
            'migrate',
            file_get_contents(base_path('public/index.php')),
            'public/index.php must not run migrations.',
        );

        $composer = json_decode(file_get_contents(base_path('composer.json')), true);

        foreach ((array) ($composer['scripts']['post-autoload-dump'] ?? []) as $command) {
            $this->assertStringNotContainsString(
                'migrate',
                (string) $command,
                'composer post-autoload-dump runs during image build and must not migrate.',
            );
        }
    }

    /**
     * The scheduler must not migrate either — it is a single always-on replica, not a deploy hook.
     */
    public function test_the_scheduler_does_not_run_migrations(): void
    {
        $this->assertStringNotContainsString(
            'migrate',
            file_get_contents(base_path('routes/console.php')),
            'routes/console.php must not schedule migrations.',
        );
    }

    /**
     * A separate process — the local stand-in for the Azure migration job — can run migrations to
     * completion, and running them again is a no-op. That is what makes redeploying the job safe.
     */
    public function test_migrations_run_from_a_separate_process_and_are_idempotent(): void
    {
        $first = $this->runMigrationJob();

        $this->assertTrue($first['ok'], 'The migration job failed: '.($first['error'] ?? 'unknown'));
        $this->assertSame(
            'procynia_test',
            $first['database'],
            'The migration job must run against the test database.',
        );
        $this->assertSame(0, $first['exit_code'], 'migrate --force must exit zero. Output: '.$first['output']);

        $second = $this->runMigrationJob();

        $this->assertTrue($second['ok'], 'The second migration run failed: '.($second['error'] ?? 'unknown'));
        $this->assertSame(0, $second['exit_code'], 'A repeated migrate --force must exit zero.');
        $this->assertSame(
            0,
            $second['pending_before'],
            'After a successful migration run there must be no pending migrations left. '
            .'Output: '.$second['output'],
        );
        $this->assertStringContainsString(
            'Nothing to migrate',
            $second['output'],
            'A repeated migration run must be a no-op, or redeploying the Azure migration job is unsafe.',
        );
    }

    // -----------------------------------------------------------------------
    // pgvector
    // -----------------------------------------------------------------------

    public function test_exactly_one_migration_creates_the_vector_extension(): void
    {
        $matches = [];
        exec(
            sprintf('grep -rln %s %s 2>/dev/null', escapeshellarg('CREATE EXTENSION'), escapeshellarg(base_path('database'))),
            $matches,
        );

        $relative = array_map(static fn ($path) => str_replace(base_path().'/', '', $path), $matches);

        $this->assertSame(
            [self::PGVECTOR_MIGRATION],
            $relative,
            'Exactly one migration may create a PostgreSQL extension, and it must be the pgvector one. '
            .'Anything else would need reviewing against the Azure azure.extensions allowlist.',
        );
    }

    /**
     * On Azure the extension may already exist (a restored database, a re-run job), and the
     * migration must not fail in that case.
     */
    public function test_the_vector_extension_is_created_idempotently(): void
    {
        $source = file_get_contents(base_path(self::PGVECTOR_MIGRATION));

        $this->assertStringContainsString(
            'CREATE EXTENSION IF NOT EXISTS vector',
            $source,
            'The pgvector migration must be safe to re-run against an existing Azure database.',
        );
    }

    /**
     * Azure PostgreSQL Flexible Server gives the application administrator no superuser role.
     * Anything requiring superuser would fail at migration time, in the job, after the platform was
     * already deployed.
     */
    public function test_the_pgvector_migration_needs_no_superuser_privileges(): void
    {
        $source = file_get_contents(base_path(self::PGVECTOR_MIGRATION));

        foreach ([
            'ALTER SYSTEM',
            'CREATE ROLE',
            'ALTER ROLE',
            'shared_preload_libraries',
            'SCHEMA pg_catalog',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                sprintf(
                    'The pgvector migration uses [%s], which the Azure PostgreSQL administrator role '
                    .'cannot execute. It would fail inside the migration job.',
                    $forbidden,
                ),
            );
        }
    }

    /**
     * The Bicep allowlist is step 2 of the required order. Without it, CREATE EXTENSION is rejected
     * by Azure no matter what privileges the role has.
     */
    public function test_the_azure_infrastructure_allowlists_the_vector_extension(): void
    {
        $this->assertStringContainsString(
            'azure.extensions',
            file_get_contents(base_path('infra/modules/postgres.bicep')),
            'The PostgreSQL module must configure the azure.extensions server parameter.',
        );

        foreach (['staging', 'production'] as $environment) {
            $this->assertStringContainsString(
                "postgresAllowedExtensions = 'VECTOR'",
                file_get_contents(base_path("infra/environments/{$environment}.bicepparam")),
                sprintf('%s.bicepparam must allowlist VECTOR.', $environment),
            );
        }
    }

    /**
     * A real check against the live test database: the extension exists and a vector column is
     * genuinely usable. This is the local proof that the schema half of the pgvector story works;
     * the Azure half is the allowlist, which cannot be exercised locally.
     */
    public function test_the_vector_extension_is_usable_on_the_live_test_database(): void
    {
        $this->assertSame(
            'procynia_test',
            DB::selectOne('select current_database() as db')->db,
            'This test must only ever touch the test database.',
        );

        $extension = DB::selectOne("select extversion from pg_extension where extname = 'vector'");

        if ($extension === null) {
            $this->markTestSkipped(
                'The vector extension is not installed in procynia_test. Run the migrations first: '
                .'docker exec procynia-app php artisan migrate --force',
            );
        }

        $this->assertNotSame('', (string) $extension->extversion);

        $column = DB::selectOne(
            "select data_type, udt_name from information_schema.columns
             where table_name = 'knowledge_item_chunks' and column_name = 'embedding_vector_pgvector'",
        );

        $this->assertNotNull(
            $column,
            'The pgvector column is missing, so the pgvector migration has not been applied to the test database.',
        );
        $this->assertSame('vector', $column->udt_name, 'The embedding column must really be of type vector.');

        // Prove the type works rather than only that it exists.
        $distance = DB::selectOne("select ('[1,0,0]'::vector <=> '[0,1,0]'::vector) as distance");
        $this->assertEqualsWithDelta(1.0, (float) $distance->distance, 0.0001);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function runMigrationJob(): array
    {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Feature/Azure/support/run-migration-job.php')],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => 'postgres',
                'DB_PORT' => '5432',
                'DB_DATABASE' => 'procynia_test',
                'DB_USERNAME' => 'gehard',
                'DB_PASSWORD' => 'Opaque01',
            ],
            null,
            600,
        );

        $process->run();

        $output = trim($process->getOutput());

        $this->assertNotSame(
            '',
            $output,
            'The migration job produced no output. stderr: '.$process->getErrorOutput(),
        );

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'The migration job did not return JSON. Output: '.$output);

        return $decoded;
    }
}
