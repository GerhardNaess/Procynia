<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\BackupRecovery;
use App\Filament\Pages\Incidents;
use App\Filament\Pages\QueueScheduler;
use App\Filament\Pages\SystemStatus;
use App\Filament\Resources\OperationalRunbookResource;
use App\Filament\Resources\OperationalRunbookResource\Pages\CreateOperationalRunbook;
use App\Filament\Resources\OperationalRunbookResource\Pages\EditOperationalRunbook;
use App\Filament\Resources\OperationalRunbookResource\Pages\ListOperationalRunbooks;
use App\Filament\Resources\OperationalRunbookResource\Pages\ViewOperationalRunbook;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\OperationalRunbookAttachment;
use App\Models\OperationalRunbook;
use App\Models\User;
use Database\Seeders\OperatingProcedureSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class DriftPagesTest extends TestCase
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

    public function test_drift_pages_expose_expected_navigation_metadata(): void
    {
        $this->assertSame('Drift', SystemStatus::getNavigationGroup());
        $this->assertSame('Drift', QueueScheduler::getNavigationGroup());
        $this->assertSame('Drift', Incidents::getNavigationGroup());
        $this->assertSame('Drift', BackupRecovery::getNavigationGroup());
        $this->assertSame('Drift', OperationalRunbookResource::getNavigationGroup());

        $this->assertSame('System status', SystemStatus::getNavigationLabel());
        $this->assertSame('Queue and scheduler', QueueScheduler::getNavigationLabel());
        $this->assertSame('Incidents', Incidents::getNavigationLabel());
        $this->assertSame('Backup and recovery', BackupRecovery::getNavigationLabel());
        $this->assertSame('Driftsrutiner', OperationalRunbookResource::getNavigationLabel());

        $this->assertArrayHasKey('index', OperationalRunbookResource::getPages());
        $this->assertArrayHasKey('create', OperationalRunbookResource::getPages());
        $this->assertArrayHasKey('edit', OperationalRunbookResource::getPages());
        $this->assertArrayHasKey('view', OperationalRunbookResource::getPages());
    }

    public function test_internal_admin_can_open_the_drift_pages(): void
    {
        $admin = $this->internalAdmin();

        $this->actingAs($admin)->get(SystemStatus::getUrl())->assertOk()->assertSee('System status');
        $this->actingAs($admin)->get(QueueScheduler::getUrl())->assertOk()->assertSee('Queue and scheduler');
        $this->actingAs($admin)->get(Incidents::getUrl())->assertOk()->assertSee('Incidents');
        $this->actingAs($admin)->get(BackupRecovery::getUrl())->assertOk()->assertSee('Backup and recovery');
    }

    public function test_non_internal_admin_cannot_open_the_drift_pages(): void
    {
        $customer = $this->createCustomer();
        $admin = User::query()->create([
            'name' => 'Customer Admin',
            'email' => 'customer.admin+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin);

        $this->assertFalse(SystemStatus::canAccess());
        $this->assertFalse(QueueScheduler::canAccess());
        $this->assertFalse(Incidents::canAccess());
        $this->assertFalse(BackupRecovery::canAccess());
        $this->assertFalse(OperationalRunbookResource::canAccess());
    }

    public function test_operational_runbooks_list_page_renders_in_norwegian(): void
    {
        $admin = $this->internalAdmin();
        Storage::fake('local');

        $withAttachment = OperationalRunbook::query()->create([
            'title' => 'Backup verification',
            'category' => 'backup_recovery',
            'summary' => 'Verify nightly backups and restore points.',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $storedPath = 'operational-runbooks/runbook-'.$withAttachment->id.'/01__backup-guide.pdf';
        Storage::disk('local')->putFileAs(
            dirname($storedPath),
            UploadedFile::fake()->create('backup-guide.pdf', 128, 'application/pdf'),
            basename($storedPath),
        );

        OperationalRunbookAttachment::query()->create([
            'operational_runbook_id' => $withAttachment->id,
            'original_name' => 'backup-guide.pdf',
            'stored_path' => $storedPath,
            'mime_type' => 'application/pdf',
            'size_bytes' => 128 * 1024,
            'description' => 'Gjenopprettingsprosedyre',
            'sort_order' => 0,
            'uploaded_by_user_id' => $admin->id,
        ]);

        OperationalRunbook::query()->create([
            'title' => 'Uten vedlegg',
            'category' => 'general',
            'summary' => 'Rutine uten dokumenter.',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->actingAs($admin)
            ->get(OperationalRunbookResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Driftsrutiner')
            ->assertSee('Ny driftsrutine')
            ->assertSee('Kategori')
            ->assertSee('Antall vedlegg')
            ->assertSee('Aktiv')
            ->assertSee('Sortering')
            ->assertSee('Oppdatert')
            ->assertSee('Backup verification')
            ->assertSee('Backup og recovery')
            ->assertSee('1 vedlegg')
            ->assertSee('Mangler dokument');
    }

    public function test_operational_runbook_create_page_creates_new_runbook_with_docker_category(): void
    {
        $admin = $this->internalAdmin();
        Livewire::actingAs($admin)
            ->test(CreateOperationalRunbook::class)
            ->set('data.title', 'Docker-oppsett for test')
            ->set('data.category', 'docker')
            ->set('data.sort_order', 1)
            ->set('data.is_active', true)
            ->set('data.summary', 'Kort sammendrag av Docker-oppsett.')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('operational_runbooks', [
            'title' => 'Docker-oppsett for test',
            'category' => 'docker',
            'sort_order' => 1,
            'is_active' => true,
            'summary' => 'Kort sammendrag av Docker-oppsett.',
        ]);

        $runbook = OperationalRunbook::query()->where('title', 'Docker-oppsett for test')->firstOrFail();
        $this->assertSame(0, $runbook->attachments()->count());
    }

    public function test_operational_runbook_attachments_can_be_saved_and_downloaded(): void
    {
        $admin = $this->internalAdmin();
        Storage::fake('local');

        $runbook = OperationalRunbook::query()->create([
            'title' => 'Docker verification',
            'category' => 'docker',
            'summary' => 'Verify Docker operational documents.',
            'is_active' => true,
            'sort_order' => 3,
        ]);

        $storedPath = 'operational-runbooks/runbook-'.$runbook->id.'/01__docker-guide.pdf';
        Storage::disk('local')->put($storedPath, '%PDF-1.4 docker guide');

        $attachment = OperationalRunbookAttachment::query()->create([
            'operational_runbook_id' => $runbook->id,
            'original_name' => 'docker-guide.pdf',
            'stored_path' => $storedPath,
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'description' => 'Driftsdokument',
            'sort_order' => 0,
            'uploaded_by_user_id' => $admin->id,
        ]);

        $this->assertSame(1, $runbook->attachments()->count());
        $this->assertDatabaseHas('operational_runbook_attachments', [
            'operational_runbook_id' => $runbook->id,
            'original_name' => 'docker-guide.pdf',
            'description' => 'Driftsdokument',
            'sort_order' => 0,
            'uploaded_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.operational-runbook-attachments.download', ['attachment' => $attachment]))
            ->assertOk()
            ->assertDownload('docker-guide.pdf');
    }

    public function test_operational_runbook_view_and_edit_pages_render_existing_document(): void
    {
        $admin = $this->internalAdmin();
        Storage::fake('local');

        $runbook = OperationalRunbook::query()->create([
            'title' => 'Backup verification',
            'category' => 'backup_recovery',
            'summary' => 'Verify nightly backups and restore points.',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $storedPath = 'operational-runbooks/runbook-'.$runbook->id.'/01__backup-guide.pdf';
        Storage::disk('local')->put($storedPath, '%PDF-1.4 backup guide');

        OperationalRunbookAttachment::query()->create([
            'operational_runbook_id' => $runbook->id,
            'original_name' => 'backup-guide.pdf',
            'stored_path' => $storedPath,
            'mime_type' => 'application/pdf',
            'size_bytes' => 21,
            'description' => 'Gjenopprettingsprosedyre',
            'sort_order' => 0,
            'uploaded_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(ViewOperationalRunbook::getUrl(['record' => $runbook]))
            ->assertOk()
            ->assertSee('Backup verification')
            ->assertSee('Kategori: Backup og recovery')
            ->assertSee('Vedlegg')
            ->assertSee('backup-guide.pdf')
            ->assertSee('Last ned')
            ->assertSee('Rediger');

        $this->actingAs($admin)
            ->get(route('admin.operational-runbook-attachments.download', ['attachment' => $runbook->attachments()->firstOrFail()]))
            ->assertOk()
            ->assertDownload('backup-guide.pdf');

        $this->actingAs($admin)
            ->get(EditOperationalRunbook::getUrl(['record' => $runbook]))
            ->assertOk()
            ->assertSee('Rediger driftsrutine')
            ->assertSee('Tittel')
            ->assertSee('Kategori');

        Livewire::actingAs($admin)
            ->test(ListOperationalRunbooks::class)
            ->assertSee('Driftsrutiner')
            ->assertSee('Ny driftsrutine');
    }

    public function test_operating_procedure_seeder_is_idempotent(): void
    {
        OperationalRunbook::query()->create([
            'title' => 'Docker-oppsett for Procynia',
            'category' => 'infrastructure',
            'summary' => 'Custom summary that should not be overwritten.',
            'is_active' => false,
            'sort_order' => 99,
        ]);

        app(OperatingProcedureSeeder::class)->run();
        app(OperatingProcedureSeeder::class)->run();

        $this->assertSame(1, OperationalRunbook::query()->where('title', 'Docker-oppsett for Procynia')->count());

        $this->assertDatabaseHas('operational_runbooks', [
            'title' => 'Docker-oppsett for Procynia',
            'category' => 'infrastructure',
            'summary' => 'Custom summary that should not be overwritten.',
            'is_active' => false,
            'sort_order' => 99,
        ]);
    }

    private function internalAdmin(): User
    {
        return User::query()->create([
            'name' => 'Procynia Admin',
            'email' => 'procynia.admin+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
    }

    private function createCustomer(string $name = 'Procynia AS'): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }

    private function useProjectPostgresConnection(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'procynia_test',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => '5432',
            'database.connections.pgsql.username' => 'gehard',
            'database.connections.pgsql.password' => '',
            'database.connections.pgsql.search_path' => 'public',
        ]);

        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');
        DB::reconnect('pgsql');
    }
}
