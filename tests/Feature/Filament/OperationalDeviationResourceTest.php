<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\OperationalDeviationResource;
use App\Filament\Resources\OperationalDeviationResource\Pages\CreateOperationalDeviation;
use App\Filament\Resources\OperationalDeviationResource\Pages\EditOperationalDeviation;
use App\Models\OperationalDeviation;
use App\Models\User;
use Database\Seeders\OperationalDeviationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class OperationalDeviationResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_internal_admin_can_open_the_operational_deviation_register(): void
    {
        $admin = $this->internalAdmin();

        $this->actingAs($admin)
            ->get(OperationalDeviationResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Avvik og forbedringer')
            ->assertSee('Nytt avvik')
            ->assertSee('Åpne avvik')
            ->assertSee('Lukkede avvik');
    }

    public function test_non_internal_admin_cannot_access_operational_deviation_resource(): void
    {
        $customerAdmin = User::query()->create([
            'name' => 'Customer Admin',
            'email' => 'customer.admin+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);

        $this->actingAs($customerAdmin);

        $this->assertFalse(OperationalDeviationResource::canAccess());
    }

    public function test_admin_can_create_an_operational_deviation_and_started_at_is_set_for_in_progress_status(): void
    {
        $admin = $this->internalAdmin();
        $owner = $this->internalAdmin('Owner User');

        Livewire::actingAs($admin)
            ->test(CreateOperationalDeviation::class)
            ->set('data.code', 'AVVIK-010')
            ->set('data.title', 'Test deviation')
            ->set('data.category', OperationalDeviation::CATEGORY_SECURITY)
            ->set('data.severity', OperationalDeviation::SEVERITY_HIGH)
            ->set('data.status', OperationalDeviation::STATUS_IN_PROGRESS)
            ->set('data.description', 'Test description')
            ->set('data.impact', 'Test impact')
            ->set('data.recommended_action', 'Test action')
            ->set('data.acceptance_criteria', 'Test criteria')
            ->set('data.owner_user_id', $owner->id)
            ->set('data.source', 'Manual observation')
            ->set('data.source_date', '2026-05-15')
            ->set('data.due_at', '2026-05-16 12:00:00')
            ->set('data.commit_hash', 'abc1234')
            ->call('create')
            ->assertHasNoErrors();

        $deviation = OperationalDeviation::query()->where('code', 'AVVIK-010')->firstOrFail();

        $this->assertSame('AVVIK-010', $deviation->code);
        $this->assertSame(OperationalDeviation::CATEGORY_SECURITY, $deviation->category);
        $this->assertSame(OperationalDeviation::SEVERITY_HIGH, $deviation->severity);
        $this->assertSame(OperationalDeviation::STATUS_IN_PROGRESS, $deviation->status);
        $this->assertSame($admin->id, $deviation->created_by_user_id);
        $this->assertSame($admin->id, $deviation->updated_by_user_id);
        $this->assertNotNull($deviation->started_at);
        $this->assertNull($deviation->closed_at);
        $this->assertSame($owner->id, $deviation->owner_user_id);
    }

    public function test_code_must_be_unique(): void
    {
        OperationalDeviation::query()->create([
            'code' => 'AVVIK-020',
            'title' => 'Unique code baseline',
            'category' => OperationalDeviation::CATEGORY_DOCKER,
            'severity' => OperationalDeviation::SEVERITY_MEDIUM,
            'status' => OperationalDeviation::STATUS_NEW,
            'description' => 'Baseline description',
        ]);

        $this->expectException(ValidationException::class);

        OperationalDeviation::query()->create([
            'code' => 'avvik-020',
            'title' => 'Duplicate code',
            'category' => OperationalDeviation::CATEGORY_DOCKER,
            'severity' => OperationalDeviation::SEVERITY_MEDIUM,
            'status' => OperationalDeviation::STATUS_NEW,
            'description' => 'Duplicate description',
        ]);
    }

    public function test_status_change_sets_closed_at(): void
    {
        $admin = $this->internalAdmin();

        $deviation = OperationalDeviation::query()->create([
            'code' => 'AVVIK-030',
            'title' => 'Close me',
            'category' => OperationalDeviation::CATEGORY_DATABASE,
            'severity' => OperationalDeviation::SEVERITY_LOW,
            'status' => OperationalDeviation::STATUS_PLANNED,
            'description' => 'A planned deviation.',
        ]);

        Livewire::actingAs($admin)
            ->test(EditOperationalDeviation::class, ['record' => $deviation->getKey()])
            ->set('data.status', OperationalDeviation::STATUS_CLOSED)
            ->call('save')
            ->assertHasNoErrors();

        $deviation->refresh();

        $this->assertSame(OperationalDeviation::STATUS_CLOSED, $deviation->status);
        $this->assertNotNull($deviation->closed_at);
    }

    public function test_operational_deviation_query_orders_open_items_before_closed_items(): void
    {
        OperationalDeviation::query()->create([
            'code' => 'AVVIK-050',
            'title' => 'Closed first in raw data',
            'category' => OperationalDeviation::CATEGORY_DOCKER,
            'severity' => OperationalDeviation::SEVERITY_LOW,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => 'Closed deviation.',
        ]);

        OperationalDeviation::query()->create([
            'code' => 'AVVIK-051',
            'title' => 'Open later',
            'category' => OperationalDeviation::CATEGORY_SECURITY,
            'severity' => OperationalDeviation::SEVERITY_CRITICAL,
            'status' => OperationalDeviation::STATUS_IN_PROGRESS,
            'description' => 'Open deviation.',
        ]);

        $codes = OperationalDeviationResource::getEloquentQuery()
            ->pluck('code')
            ->all();

        $this->assertSame('AVVIK-051', $codes[0]);
        $this->assertSame('AVVIK-050', $codes[1]);
    }

    public function test_seeded_deviations_are_idempotent_and_preserve_existing_rows(): void
    {
        OperationalDeviation::query()->create([
            'code' => 'AVVIK-001',
            'title' => 'Custom existing deviation',
            'category' => OperationalDeviation::CATEGORY_PRODUCT,
            'severity' => OperationalDeviation::SEVERITY_LOW,
            'status' => OperationalDeviation::STATUS_NEW,
            'description' => 'Custom row that should not be overwritten.',
        ]);

        app(OperationalDeviationSeeder::class)->run();
        app(OperationalDeviationSeeder::class)->run();

        // No duplicates for any seeded code
        $this->assertSame(1, OperationalDeviation::query()->where('code', 'AVVIK-001')->count());
        $this->assertSame(1, OperationalDeviation::query()->where('code', 'AVVIK-002')->count());
        $this->assertSame(1, OperationalDeviation::query()->where('code', 'AVVIK-003')->count());
        $this->assertSame(1, OperationalDeviation::query()->where('code', 'AVVIK-030')->count());

        // All 30 avvik exist (1 pre-created + 29 seeded)
        $this->assertSame(30, OperationalDeviation::query()->count());

        // Total unique seeded codes is 30
        $this->assertSame(30, OperationalDeviation::query()->pluck('code')->unique()->count());

        $avvik010 = OperationalDeviation::query()->where('code', 'AVVIK-010')->firstOrFail();
        $this->assertSame(OperationalDeviation::STATUS_CLOSED, $avvik010->status);
        $this->assertNotNull($avvik010->verified_at);
        $this->assertNotNull($avvik010->closed_at);
        $this->assertStringContainsString('ai_usage_events', (string) $avvik010->verification_notes);

        // Pre-created custom row is not overwritten by seeder
        $this->assertDatabaseHas('operational_deviations', [
            'code' => 'AVVIK-001',
            'title' => 'Custom existing deviation',
            'category' => OperationalDeviation::CATEGORY_PRODUCT,
            'severity' => OperationalDeviation::SEVERITY_LOW,
            'status' => OperationalDeviation::STATUS_NEW,
        ]);
    }

    public function test_seeder_creates_all_thirty_deviations_on_fresh_database(): void
    {
        app(OperationalDeviationSeeder::class)->run();

        $this->assertSame(30, OperationalDeviation::query()->count());
        $this->assertSame(30, OperationalDeviation::query()->pluck('code')->unique()->count());

        // AVVIK-001, AVVIK-002, AVVIK-003, AVVIK-004, AVVIK-005 and AVVIK-006 must be closed with a closed_at timestamp
        $avvik001 = OperationalDeviation::query()->where('code', 'AVVIK-001')->firstOrFail();
        $this->assertSame(OperationalDeviation::STATUS_CLOSED, $avvik001->status);
        $this->assertNotNull($avvik001->closed_at);
        $this->assertNotNull($avvik001->verified_at);

        $avvik002 = OperationalDeviation::query()->where('code', 'AVVIK-002')->firstOrFail();
        $this->assertSame(OperationalDeviation::STATUS_CLOSED, $avvik002->status);
        $this->assertNotNull($avvik002->closed_at);
        $this->assertNotNull($avvik002->verified_at);

        $avvik003 = OperationalDeviation::query()->where('code', 'AVVIK-003')->firstOrFail();
        $this->assertSame(OperationalDeviation::STATUS_CLOSED, $avvik003->status);
        $this->assertNotNull($avvik003->closed_at);
        $this->assertNotNull($avvik003->verified_at);

        $avvik004 = OperationalDeviation::query()->where('code', 'AVVIK-004')->firstOrFail();
        $this->assertSame(OperationalDeviation::STATUS_CLOSED, $avvik004->status);
        $this->assertNotNull($avvik004->closed_at);
        $this->assertNotNull($avvik004->verified_at);

        $avvik005 = OperationalDeviation::query()->where('code', 'AVVIK-005')->firstOrFail();
        $this->assertSame(OperationalDeviation::STATUS_CLOSED, $avvik005->status);
        $this->assertNotNull($avvik005->closed_at);
        $this->assertNotNull($avvik005->verified_at);

        $avvik006 = OperationalDeviation::query()->where('code', 'AVVIK-006')->firstOrFail();
        $this->assertSame(OperationalDeviation::STATUS_CLOSED, $avvik006->status);
        $this->assertNotNull($avvik006->closed_at);
        $this->assertNotNull($avvik006->verified_at);

        $avvik007 = OperationalDeviation::query()->where('code', 'AVVIK-007')->firstOrFail();
        $this->assertSame(OperationalDeviation::STATUS_CLOSED, $avvik007->status);
        $this->assertNotNull($avvik007->closed_at);
        $this->assertNotNull($avvik007->verified_at);

        // AVVIK-008 must be closed (Doffin beta-default fjernet)
        $avvik008 = OperationalDeviation::query()->where('code', 'AVVIK-008')->firstOrFail();
        $this->assertSame(OperationalDeviation::STATUS_CLOSED, $avvik008->status);
        $this->assertNotNull($avvik008->closed_at);
        $this->assertNotNull($avvik008->verified_at);

        // AVVIK-009 must be closed (AI rate limiting and cost control implemented)
        $avvik009 = OperationalDeviation::query()->where('code', 'AVVIK-009')->firstOrFail();
        $this->assertSame(OperationalDeviation::STATUS_CLOSED, $avvik009->status);
        $this->assertNotNull($avvik009->closed_at);
        $this->assertNotNull($avvik009->verified_at);

        // AVVIK-015 must be closed (pdftotext-sti miljøstyrt)
        $avvik015 = OperationalDeviation::query()->where('code', 'AVVIK-015')->firstOrFail();
        $this->assertSame(OperationalDeviation::STATUS_CLOSED, $avvik015->status);
        $this->assertNotNull($avvik015->closed_at);
        $this->assertNotNull($avvik015->verified_at);

        // AVVIK-029 must be closed (HTTPS/TLS-rutine dokumentert)
        $avvik029 = OperationalDeviation::query()->where('code', 'AVVIK-029')->firstOrFail();
        $this->assertSame(OperationalDeviation::STATUS_CLOSED, $avvik029->status);
        $this->assertNotNull($avvik029->closed_at);
        $this->assertNotNull($avvik029->verified_at);

        // Last seeded avvik exists
        $this->assertDatabaseHas('operational_deviations', ['code' => 'AVVIK-030']);

        // Running seeder a second time does not create duplicates
        app(OperationalDeviationSeeder::class)->run();
        $this->assertSame(30, OperationalDeviation::query()->count());
    }

    public function test_operational_deviation_list_page_renders_expected_labels(): void
    {
        $admin = $this->internalAdmin();

        OperationalDeviation::query()->create([
            'code' => 'AVVIK-040',
            'title' => 'Open deviation',
            'category' => OperationalDeviation::CATEGORY_AI,
            'severity' => OperationalDeviation::SEVERITY_CRITICAL,
            'status' => OperationalDeviation::STATUS_IN_PROGRESS,
            'description' => 'Open deviation description.',
        ]);

        OperationalDeviation::query()->create([
            'code' => 'AVVIK-041',
            'title' => 'Closed deviation',
            'category' => OperationalDeviation::CATEGORY_SECURITY,
            'severity' => OperationalDeviation::SEVERITY_HIGH,
            'status' => OperationalDeviation::STATUS_CLOSED,
            'description' => 'Closed deviation description.',
        ]);

        $this->actingAs($admin)
            ->get(OperationalDeviationResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Avvik og forbedringer')
            ->assertSee('Åpne avvik')
            ->assertSee('Lukkede avvik')
            ->assertSee('Kritisk')
            ->assertSee('Høy')
            ->assertSee('Open deviation')
            ->assertSee('Closed deviation');
    }

    private function internalAdmin(string $name = 'Procynia Admin'): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => 'procynia.admin+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
    }

    private function useProjectPostgresConnection(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'procynia_test',
            'database.connections.pgsql.host' => 'postgres',
            'database.connections.pgsql.port' => '5432',
            'database.connections.pgsql.username' => 'gehard',
            'database.connections.pgsql.password' => 'Opaque01',
            'database.connections.pgsql.search_path' => 'public',
        ]);

        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');
        DB::reconnect('pgsql');
    }
}
