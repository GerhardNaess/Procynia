<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * A fagområde that still holds content is not deleted. RiskAccessTest proves the refusal for risks
 * through Tilganger; this keeps the list of what counts as content complete as modules are added.
 */
class BusinessAreaDeletionTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
    }

    protected function tearDown(): void
    {
        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    /**
     * Every table with a foreign key to business_areas is either a role link (which cascades) or
     * content that BusinessArea::isInUse() must see. A new module table — objectives — fails here
     * until it is added to BusinessArea::SCOPED_CONTENT_TABLES.
     */
    public function test_every_table_that_keeps_content_in_a_fagomrade_blocks_its_deletion(): void
    {
        $referencing = collect(DB::select(<<<'SQL'
            SELECT DISTINCT cl.relname AS table_name
            FROM pg_constraint con
            JOIN pg_class cl ON cl.oid = con.conrelid
            JOIN pg_namespace ns ON ns.oid = cl.relnamespace
            WHERE con.contype = 'f'
              AND con.confrelid = 'business_areas'::regclass
              AND ns.nspname = current_schema()
        SQL))
            ->pluck('table_name')
            ->reject(fn (string $table): bool => $table === 'customer_role_business_areas')
            ->sort()
            ->values()
            ->all();

        $listed = BusinessArea::SCOPED_CONTENT_TABLES;
        sort($listed);

        $this->assertSame($listed, $referencing);
    }
}
