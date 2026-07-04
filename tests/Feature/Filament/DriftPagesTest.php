<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\BackupRecovery;
use App\Filament\Pages\DoffinAutomaticImport;
use App\Models\AdminPageHelp;
use App\Filament\Pages\AdminNotifications;
use App\Filament\Pages\Incidents;
use App\Filament\Pages\Monitoring;
use App\Filament\Pages\QueueScheduler;
use App\Filament\Pages\SystemStatus;
use App\Filament\Resources\DoffinImportRunResource;
use App\Filament\Resources\SyncLogResource;
use App\Filament\Resources\SyncLogResource\Pages\ListSyncLogs;
use App\Filament\Resources\OperationalRunbookResource;
use App\Filament\Resources\OperationalRunbookResource\Pages\CreateOperationalRunbook;
use App\Filament\Resources\OperationalRunbookResource\Pages\EditOperationalRunbook;
use App\Filament\Resources\OperationalRunbookResource\Pages\ListOperationalRunbooks;
use App\Filament\Resources\OperationalRunbookResource\Pages\ViewOperationalRunbook;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\OperationalRunbookCategory;
use App\Models\OperationalRunbookAttachment;
use App\Models\OperationalRunbook;
use App\Models\User;
use Database\Seeders\OperatingProcedureSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
        $previousLocale = app()->getLocale();
        app()->setLocale('no');

        try {
            $this->assertSame('Drift', SystemStatus::getNavigationGroup());
            $this->assertSame('Drift', Monitoring::getNavigationGroup());
            $this->assertSame('Drift', QueueScheduler::getNavigationGroup());
            $this->assertSame('Drift', Incidents::getNavigationGroup());
            $this->assertSame('Drift', BackupRecovery::getNavigationGroup());
            $this->assertSame('Drift', DoffinAutomaticImport::getNavigationGroup());
            $this->assertSame('Drift', OperationalRunbookResource::getNavigationGroup());
            $this->assertSame('Drift', DoffinImportRunResource::getNavigationGroup());
            $this->assertSame('Drift', SyncLogResource::getNavigationGroup());

            $this->assertFalse(QueueScheduler::shouldRegisterNavigation());
            $this->assertTrue(DoffinAutomaticImport::shouldRegisterNavigation());
            $this->assertTrue(DoffinImportRunResource::shouldRegisterNavigation());
            $this->assertTrue(SyncLogResource::shouldRegisterNavigation());

            $this->assertSame('Systemstatus', SystemStatus::getNavigationLabel());
            $this->assertSame('Overvåkning', Monitoring::getNavigationLabel());
            $this->assertSame('Queue and scheduler', QueueScheduler::getNavigationLabel());
            $this->assertSame('Varsler', AdminNotifications::getNavigationLabel());
            $this->assertSame('Incidents', Incidents::getNavigationLabel());
            $this->assertSame('Sikkerhetskopi og gjenoppretting', BackupRecovery::getNavigationLabel());
            $this->assertSame('Doffin automatisk import', DoffinAutomaticImport::getNavigationLabel());
            $this->assertSame('Driftsrutiner', OperationalRunbookResource::getNavigationLabel());
            $this->assertSame('Importkjøringer', DoffinImportRunResource::getNavigationLabel());
            $this->assertSame('Synkroniseringslogg', SyncLogResource::getNavigationLabel());

            $this->assertSame(6, DoffinAutomaticImport::getNavigationSort());
            $this->assertSame(7, DoffinImportRunResource::getNavigationSort());
            $this->assertSame(8, SyncLogResource::getNavigationSort());

            $this->assertArrayHasKey('index', OperationalRunbookResource::getPages());
            $this->assertArrayHasKey('create', OperationalRunbookResource::getPages());
            $this->assertArrayHasKey('edit', OperationalRunbookResource::getPages());
            $this->assertArrayHasKey('view', OperationalRunbookResource::getPages());
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    public function test_internal_admin_can_open_the_drift_pages(): void
    {
        $admin = $this->internalAdmin();

        $this->actingAs($admin)->get(SystemStatus::getUrl())->assertOk()->assertSee('Systemstatus');
        $this->actingAs($admin)->get(Monitoring::getUrl())->assertOk()->assertSee('Overvåkning');
        $this->actingAs($admin)->get(QueueScheduler::getUrl())->assertOk()->assertSee('Queue and scheduler');
        $this->actingAs($admin)->get(Incidents::getUrl())->assertOk()->assertSee('Incidents');
        $this->actingAs($admin)->get(BackupRecovery::getUrl())->assertOk()->assertSee('Sikkerhetskopi og gjenoppretting');
        $this->actingAs($admin)->get(DoffinAutomaticImport::getUrl())->assertOk()->assertSee('Doffin automatisk import');
    }

    public function test_system_status_page_help_is_seeded_by_migration(): void
    {
        $record = AdminPageHelp::query()
            ->where('page_key', 'admin.system_status')
            ->first();

        $this->assertNotNull($record, 'admin.system_status mangler i admin_page_helps');
        $this->assertSame('Systemstatus', $record->title);
        $this->assertTrue($record->is_active);
        $this->assertCount(5, $record->sections);

        $allText = collect($record->sections)
            ->flatMap(fn ($section) => array_merge([$section['title'] ?? ''], array_column($section['items'] ?? [], 'text')))
            ->implode(' ');

        $this->assertStringContainsString('Driftsrutiner', $allText);
        $this->assertStringContainsString('Avvik og forbedringer', $allText);
        $this->assertStringContainsString('Sikkerhetskopi og gjenoppretting', $allText);
        $this->assertStringContainsString('Importkjøringer', $allText);
        $this->assertStringContainsString('Synkroniseringslogg', $allText);
    }

    public function test_system_status_page_shows_help_action_when_page_help_record_exists(): void
    {
        $admin = $this->internalAdmin();

        $response = $this->actingAs($admin)->get(SystemStatus::getUrl());

        $response->assertOk();
        $response->assertSee('Hjelp');
    }

    public function test_operational_runbooks_page_help_is_seeded_by_migration(): void
    {
        $record = AdminPageHelp::query()
            ->where('page_key', 'admin.operational_runbooks')
            ->first();

        $this->assertNotNull($record, 'admin.operational_runbooks mangler i admin_page_helps');
        $this->assertSame('admin.operational_runbooks', $record->page_key);
        $this->assertSame('Driftsrutiner', $record->title);
        $this->assertTrue($record->is_active);
        $this->assertCount(5, $record->sections);
    }

    public function test_operational_runbooks_list_page_shows_help_action_when_page_help_record_exists(): void
    {
        $admin = $this->internalAdmin();

        $response = $this->actingAs($admin)->get(ListOperationalRunbooks::getUrl());

        $response->assertOk();
        $response->assertSee('Hjelp');
    }

    public function test_sync_logs_page_help_is_seeded_by_migration(): void
    {
        $record = AdminPageHelp::query()
            ->where('page_key', 'admin.sync_logs')
            ->first();

        $this->assertNotNull($record, 'admin.sync_logs mangler i admin_page_helps');
        $this->assertSame('admin.sync_logs', $record->page_key);
        $this->assertSame('Synkroniseringslogg', $record->title);
        $this->assertTrue($record->is_active);
        $this->assertCount(5, $record->sections);
    }

    public function test_sync_logs_list_page_shows_help_action_when_page_help_record_exists(): void
    {
        $admin = $this->internalAdmin();

        $response = $this->actingAs($admin)->get(ListSyncLogs::getUrl());

        $response->assertOk();
        $response->assertSee('Hjelp');
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
        $this->assertFalse(Monitoring::canAccess());
        $this->assertFalse(QueueScheduler::canAccess());
        $this->assertFalse(Incidents::canAccess());
        $this->assertFalse(BackupRecovery::canAccess());
        $this->assertFalse(DoffinAutomaticImport::canAccess());
        $this->assertFalse(OperationalRunbookResource::canAccess());
    }

    public function test_monitoring_page_shows_uptime_kuma_with_configured_url(): void
    {
        config(['services.uptime_kuma.url' => 'http://127.0.0.1:3001']);

        $admin = $this->internalAdmin();

        $this->actingAs($admin)
            ->get(Monitoring::getUrl())
            ->assertOk()
            ->assertSee('Overvåkning')
            ->assertSee('Uptime Kuma')
            ->assertSee('http://127.0.0.1:3001')
            ->assertSee('Åpne Uptime Kuma')
            ->assertSee('Oppetidsovervåkning og varsling')
            ->assertSee('Anbefalt bruk');
    }

    public function test_monitoring_page_shows_missing_url_message_when_not_configured(): void
    {
        config(['services.uptime_kuma.url' => '']);

        $admin = $this->internalAdmin();

        $this->actingAs($admin)
            ->get(Monitoring::getUrl())
            ->assertOk()
            ->assertSee('Uptime Kuma')
            ->assertSee('UPTIME_KUMA_URL')
            ->assertDontSee('Åpne Uptime Kuma');
    }

    public function test_monitoring_page_does_not_expose_secrets(): void
    {
        $admin = $this->internalAdmin();

        $response = $this->actingAs($admin)->get(Monitoring::getUrl())->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('api_key', strtolower($content));
        $this->assertStringNotContainsString('webhook_secret', strtolower($content));
        $this->assertStringNotContainsString('notification_secret', strtolower($content));
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
            ->assertSee('Status')
            ->assertSee('Sortering')
            ->assertSee('Sist revidert')
            ->assertSee('Backup verification')
            ->assertSee('Backup og recovery')
            ->assertSee('1 vedlegg')
            ->assertSee('Mangler dokument');
    }

    public function test_operational_runbook_create_page_creates_new_runbook_with_docker_category(): void
    {
        $admin = $this->internalAdmin();
        $category = OperationalRunbookCategory::query()->where('slug', 'docker')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(CreateOperationalRunbook::class)
            ->set('data.title', 'Docker-oppsett for test')
            ->set('data.operational_runbook_category_id', $category->id)
            ->set('data.sort_order', 1)
            ->set('data.is_active', true)
            ->set('data.summary', 'Kort sammendrag av Docker-oppsett.')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('operational_runbooks', [
            'title' => 'Docker-oppsett for test',
            'category' => 'docker',
            'operational_runbook_category_id' => $category->id,
            'sort_order' => 1,
            'is_active' => true,
            'summary' => 'Kort sammendrag av Docker-oppsett.',
        ]);

        $runbook = OperationalRunbook::query()->where('title', 'Docker-oppsett for test')->firstOrFail();
        $this->assertSame(0, $runbook->attachments()->count());
    }

    public function test_operational_runbook_categories_are_loaded_from_the_database(): void
    {
        $options = OperationalRunbookResource::categoryOptions();

        $this->assertArrayHasKey('general', $options);
        $this->assertArrayHasKey('docker', $options);
        $this->assertArrayHasKey('backup_recovery', $options);
        $this->assertArrayHasKey('monitoring', $options);
        $this->assertSame('Docker', $options['docker']);
        $this->assertSame('Backup og recovery', $options['backup_recovery']);
    }

    public function test_new_operational_runbook_category_can_be_created_and_selected(): void
    {
        $admin = $this->internalAdmin();

        $category = OperationalRunbookCategory::query()->create([
            'name' => 'Incident response',
            'sort_order' => 95,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('operational_runbook_categories', [
            'name' => 'Incident response',
            'slug' => 'incident-response',
            'sort_order' => 95,
            'is_active' => true,
        ]);

        $this->assertArrayHasKey('incident-response', OperationalRunbookResource::categoryOptions());
        $this->assertSame('Incident response', OperationalRunbookResource::categoryOptions()['incident-response']);

        Livewire::actingAs($admin)
            ->test(CreateOperationalRunbook::class)
            ->set('data.title', 'Incident response runbook')
            ->set('data.operational_runbook_category_id', $category->id)
            ->set('data.sort_order', 4)
            ->set('data.is_active', true)
            ->set('data.summary', 'Kort beskrivelse av incident response.')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('operational_runbooks', [
            'title' => 'Incident response runbook',
            'category' => 'incident-response',
            'operational_runbook_category_id' => $category->id,
            'sort_order' => 4,
            'is_active' => true,
            'summary' => 'Kort beskrivelse av incident response.',
        ]);
    }

    public function test_duplicate_operational_runbook_category_cannot_be_created(): void
    {
        OperationalRunbookCategory::query()->create([
            'name' => 'Change management',
            'sort_order' => 100,
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        OperationalRunbookCategory::query()->create([
            'name' => 'Change management',
            'sort_order' => 101,
            'is_active' => false,
        ]);
    }

    public function test_existing_operational_runbooks_keep_their_category_value(): void
    {
        $runbook = OperationalRunbook::query()->create([
            'title' => 'Legacy category runbook',
            'category' => 'backup_recovery',
            'summary' => 'Legacy category should stay intact.',
            'is_active' => true,
            'sort_order' => 11,
        ]);

        $runbook->refresh();

        $this->assertSame('backup_recovery', $runbook->category);
        $this->assertNotNull($runbook->operational_runbook_category_id);
        $this->assertSame('Backup og recovery', $runbook->categoryDefinition?->name);
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
            ->assertSee('Status: Aktiv')
            ->assertSee('Sist revidert:')
            ->assertSee('Vedlegg')
            ->assertSee('Rutinedetaljer')
            ->assertSee('backup-guide.pdf')
            ->assertSee('Last ned')
            ->assertSee('Rediger')
            ->assertSee('Verify nightly backups and restore points.');

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

    public function test_runbook_view_shows_empty_state_when_no_attachments(): void
    {
        $admin = $this->internalAdmin();

        $runbook = OperationalRunbook::query()->create([
            'title' => 'Rutine uten vedlegg',
            'category' => 'general',
            'summary' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->actingAs($admin)
            ->get(ViewOperationalRunbook::getUrl(['record' => $runbook]))
            ->assertOk()
            ->assertSee('Rutine uten vedlegg')
            ->assertSee('Ingen vedlegg er lagt til ennå.')
            ->assertDontSee('Sammendrag');
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
        $this->assertSame(1, OperationalRunbook::query()->where('title', 'Uptime Kuma overvåkning')->count());

        $this->assertDatabaseHas('operational_runbooks', [
            'title' => 'Docker-oppsett for Procynia',
            'category' => 'infrastructure',
            'summary' => 'Custom summary that should not be overwritten.',
            'is_active' => false,
            'sort_order' => 99,
        ]);

        $this->assertDatabaseHas('operational_runbooks', [
            'title' => 'Uptime Kuma overvåkning',
            'category' => 'monitoring',
            'summary' => 'Beskriver hvordan Uptime Kuma brukes til oppetidsovervåkning av Procynia på tvers av Azure, Google Cloud, AWS og on-premise.',
            'is_active' => true,
            'sort_order' => 2,
        ]);
    }

    public function test_system_status_page_shows_norwegian_section_labels(): void
    {
        $admin = $this->internalAdmin();

        $this->actingAs($admin)
            ->get(SystemStatus::getUrl())
            ->assertOk()
            ->assertSee('Systemstatus')
            ->assertSee('Driftstatus')
            ->assertSee('Infrastruktur')
            ->assertSee('Teknisk miljø')
            ->assertSee('Planlagte oppgaver')
            ->assertSee('Database')
            ->assertSee('Redis');
    }

    public function test_system_status_shows_database_and_redis_status_labels(): void
    {
        $admin = $this->internalAdmin();

        $this->actingAs($admin)
            ->get(SystemStatus::getUrl())
            ->assertOk()
            ->assertSee('Databaseforbindelse')
            ->assertSee('Redis-forbindelse')
            ->assertSee('Queue/Scheduler')
            ->assertSee('Failed jobs');
    }

    public function test_system_status_shows_failed_jobs_as_warning_when_count_is_positive(): void
    {
        $admin = $this->internalAdmin();

        $table = (string) config('queue.failed.table', 'failed_jobs');
        DB::table($table)->insert([
            'uuid'       => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue'      => 'default',
            'payload'    => json_encode(['displayName' => 'App\\Jobs\\TestJob', 'test' => true]),
            'exception'  => 'RuntimeException: Something went wrong'."\n".'Stack trace line 1'."\n".'Stack trace line 2',
            'failed_at'  => now(),
        ]);

        $this->actingAs($admin)
            ->get(SystemStatus::getUrl())
            ->assertOk()
            ->assertSee('Avvik og oppfølging')
            ->assertSee('Krever oppfølging')
            ->assertSee('Feilede jobber')
            ->assertSee('Vis detaljer');
    }

    public function test_system_status_shows_reason_text_for_failed_jobs(): void
    {
        $admin = $this->internalAdmin();

        $table = (string) config('queue.failed.table', 'failed_jobs');
        DB::table($table)->insert([
            'uuid'       => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue'      => 'default',
            'payload'    => json_encode(['displayName' => 'App\\Jobs\\ImportJob']),
            'exception'  => 'ErrorException: Import failed',
            'failed_at'  => now(),
        ]);

        $this->actingAs($admin)
            ->get(SystemStatus::getUrl())
            ->assertOk()
            ->assertSee('Systemstatus er påvirket fordi feilede bakgrunnsjobber kan stoppe import')
            ->assertSee('Åpne feilede jobber');
    }

    public function test_system_status_shows_failed_job_detail_table_with_job_info(): void
    {
        $admin = $this->internalAdmin();

        $table = (string) config('queue.failed.table', 'failed_jobs');
        DB::table($table)->insert([
            'uuid'       => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue'      => 'ai-requirements',
            'payload'    => json_encode(['displayName' => 'App\\Jobs\\Ai\\GenerateRequirementsJob']),
            'exception'  => 'TimeoutException: Job exceeded time limit',
            'failed_at'  => now(),
        ]);

        $response = $this->actingAs($admin)
            ->get(SystemStatus::getUrl())
            ->assertOk();

        // Job type (short class name), queue, connection, and first line of error are in the HTML
        $response->assertSee('GenerateRequirementsJob');
        $response->assertSee('ai-requirements');
        $response->assertSee('TimeoutException: Job exceeded time limit');
        $response->assertSee(__('procynia.system_status.fields.job_type'));
        $response->assertSee(__('procynia.system_status.fields.failed_at'));
        $response->assertSee(__('procynia.system_status.fields.error_reason'));
        $response->assertSee(__('procynia.system_status.fields.actions'));
        $response->assertSee(__('procynia.system_status.actions.delete_failed_job'));
    }

    public function test_system_status_does_not_show_full_stack_trace_in_main_view(): void
    {
        $admin = $this->internalAdmin();

        $table = (string) config('queue.failed.table', 'failed_jobs');
        DB::table($table)->insert([
            'uuid'       => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue'      => 'default',
            'payload'    => json_encode(['displayName' => 'App\\Jobs\\SomeJob']),
            'exception'  => 'RuntimeException: Top-level message'."\n".'#0 /app/vendor/laravel/framework/src/Queue/Worker.php(123): call_user_func()'."\n".'#1 /app/vendor/laravel/framework/src/Queue/Worker.php(456): process()',
            'failed_at'  => now(),
        ]);

        $content = $this->actingAs($admin)
            ->get(SystemStatus::getUrl())
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('#0 /app/vendor', $content);
        $this->assertStringNotContainsString('call_user_func', $content);
        $this->assertStringContainsString('RuntimeException: Top-level message', $content);
    }

    public function test_system_status_shows_no_issues_found_when_all_ok(): void
    {
        $admin = $this->internalAdmin();

        $this->mock(\App\Services\Operations\RuntimeStatusService::class, function ($mock) {
            $mock->shouldReceive('snapshot')->andReturn([
                'failed_jobs_count' => 0,
                'database'  => ['available' => true, 'connection' => 'pgsql', 'driver' => 'pgsql', 'database' => 'test', 'error_message' => null],
                'redis'     => ['available' => true, 'connection' => 'default', 'host' => 'localhost', 'database' => '0', 'error_message' => null],
                'queue'     => ['driver' => 'redis', 'connection' => 'redis', 'queue' => 'default', 'known_queues' => [], 'failed_jobs_count' => 0],
                'scheduler' => ['available' => true, 'status_label' => 'Configured', 'task_count' => 0, 'tasks' => []],
                'uptime'    => ['available' => false, 'label' => ''],
                'cache_driver' => 'array', 'session_driver' => 'array',
                'app_env' => 'testing', 'app_debug' => false,
                'laravel_version' => '13.0.0', 'php_version' => PHP_VERSION,
                'app_url' => 'http://localhost',
            ]);
            $mock->shouldReceive('recentFailedJobs')->andReturn([]);
        });

        $this->mock(\App\Services\Operations\QueueSchedulerHealthService::class, function ($mock) {
            $mock->shouldReceive('evaluate')->andReturn(['ok' => true, 'scheduler' => 'ok', 'queue' => 'ok']);
        });

        $this->actingAs($admin)
            ->get(SystemStatus::getUrl())
            ->assertOk()
            ->assertSee('Ingen avvik funnet');
    }

    public function test_system_status_shows_scheduler_tasks_in_compact_table(): void
    {
        $admin = $this->internalAdmin();

        $this->actingAs($admin)
            ->get(SystemStatus::getUrl())
            ->assertOk()
            ->assertSee('Planlagte oppgaver')
            ->assertSee(__('procynia.system_status.fields.task'))
            ->assertSee(__('procynia.system_status.fields.frequency'))
            ->assertSee(__('procynia.system_status.scheduler.next_run'));
    }

    public function test_system_status_renders_live_scheduler_progress_metadata(): void
    {
        $admin = $this->internalAdmin();

        $this->mock(\App\Services\Operations\RuntimeStatusService::class, function ($mock) {
            $mock->shouldReceive('snapshot')->andReturn([
                'failed_jobs_count' => 0,
                'database' => ['available' => true, 'connection' => 'pgsql', 'driver' => 'pgsql', 'database' => 'test', 'error_message' => null],
                'redis' => ['available' => true, 'connection' => 'default', 'host' => 'localhost', 'database' => '0', 'error_message' => null],
                'queue' => ['driver' => 'redis', 'connection' => 'redis', 'queue' => 'default', 'known_queues' => [], 'failed_jobs_count' => 0],
                'scheduler' => [
                    'available' => true,
                    'status_label' => 'Configured',
                    'task_count' => 2,
                    'tasks' => [
                        [
                            'task_name' => 'Procynia scheduler heartbeat',
                            'command' => 'php artisan ops:scheduler-heartbeat',
                            'description' => null,
                            'expression' => '* * * * *',
                            'timezone' => 'UTC',
                            'has_mutex' => false,
                            'previous_run_at_iso' => '2026-05-13T17:00:00+00:00',
                            'next_run_at_iso' => '2026-05-13T18:00:00+00:00',
                            'cycle_duration_seconds' => 3600,
                            'progress_ratio' => 0.25,
                            'next_run_at_human' => 'om 45 minutter',
                            'next_due_date' => '2026-05-13 18:00:00 +00:00',
                            'next_due_date_human' => 'om 45 minutter',
                            'repeat_seconds' => 60,
                            'environments' => [],
                        ],
                        [
                            'task_name' => 'Missing timing data',
                            'command' => 'php artisan schedule:run',
                            'description' => null,
                            'expression' => '* * * * *',
                            'timezone' => 'UTC',
                            'has_mutex' => false,
                            'previous_run_at_iso' => null,
                            'next_run_at_iso' => null,
                            'cycle_duration_seconds' => 0,
                            'progress_ratio' => null,
                            'next_run_at_human' => __('procynia.system_status.scheduler.unavailable'),
                            'next_due_date' => '',
                            'next_due_date_human' => __('procynia.system_status.scheduler.unavailable'),
                            'repeat_seconds' => 0,
                            'environments' => [],
                        ],
                    ],
                ],
                'uptime' => ['available' => false, 'label' => ''],
                'cache_driver' => 'array',
                'session_driver' => 'array',
                'app_env' => 'testing',
                'app_debug' => false,
                'laravel_version' => '13.0.0',
                'php_version' => PHP_VERSION,
                'app_url' => 'http://localhost',
            ]);
            $mock->shouldReceive('recentFailedJobs')->andReturn([]);
        });

        $this->mock(\App\Services\Operations\QueueSchedulerHealthService::class, function ($mock) {
            $mock->shouldReceive('evaluate')->andReturn(['ok' => true, 'scheduler' => 'ok', 'queue' => 'ok']);
        });

        $response = $this->actingAs($admin)
            ->get(SystemStatus::getUrl())
            ->assertOk();

        $response->assertSee(__('procynia.system_status.scheduler.progress'));
        $response->assertSee('om 45 minutter');
        $response->assertSee(__('procynia.system_status.scheduler.unavailable'));
        $response->assertSee('data-scheduler-task-row', false);
        $response->assertSee('data-scheduler-previous-run-at', false);
        $response->assertSee('data-scheduler-next-run-at', false);
        $response->assertSee('data-scheduler-cycle-duration-seconds', false);
        $response->assertSee('data-scheduler-progress-ring', false);
    }

    public function test_system_status_does_not_translate_dynamic_system_values(): void
    {
        $admin = $this->internalAdmin();

        $response = $this->actingAs($admin)
            ->get(SystemStatus::getUrl())
            ->assertOk();

        $appEnv = (string) config('app.env', app()->environment());
        $laravelVersion = app()->version();
        $phpVersion = PHP_VERSION;

        $response->assertSee($appEnv);
        $response->assertSee($laravelVersion);
        $response->assertSee($phpVersion);
    }

    public function test_system_status_translation_keys_resolve_correctly_in_english(): void
    {
        app()->setLocale('en');

        try {
            $this->assertSame('System status', __('procynia.system_status.title'));
            $this->assertSame('Operational status', __('procynia.system_status.sections.operational_status'));
            $this->assertSame('Infrastructure', __('procynia.system_status.sections.infrastructure'));
            $this->assertSame('Technical environment', __('procynia.system_status.sections.technical_environment'));
            $this->assertSame('Scheduled tasks', __('procynia.system_status.sections.scheduled_tasks'));
            $this->assertSame('Database connection', __('procynia.system_status.fields.database_connection'));
            $this->assertSame('Redis connection', __('procynia.system_status.fields.redis_connection'));
            $this->assertSame('Progress', __('procynia.system_status.scheduler.progress'));
            $this->assertSame('Next run', __('procynia.system_status.scheduler.next_run'));
            $this->assertSame('in', __('procynia.system_status.scheduler.in_prefix'));
            $this->assertSame('now', __('procynia.system_status.scheduler.now'));
            $this->assertSame('Not available', __('procynia.system_status.scheduler.unavailable'));
            $this->assertSame('Connected', __('procynia.system_status.statuses.connected'));
            $this->assertSame('Requires follow-up', __('procynia.system_status.statuses.requires_follow_up'));
            $this->assertSame('Show details', __('procynia.system_status.fields.show_details'));
            $this->assertSame('Failed jobs', __('procynia.system_status.issues.failed_jobs.title'));
            $this->assertSame('No issues found', __('procynia.system_status.statuses.no_issues'));
        } finally {
            app()->setLocale('no');
        }
    }

    public function test_clear_failed_jobs_action_is_visible_when_failed_jobs_exist(): void
    {
        $admin = $this->internalAdmin();

        $table = (string) config('queue.failed.table', 'failed_jobs');
        DB::table($table)->insert([
            'uuid'       => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue'      => 'default',
            'payload'    => json_encode(['displayName' => 'App\\Jobs\\SomeJob']),
            'exception'  => 'RuntimeException: Something failed',
            'failed_at'  => now(),
        ]);

        $this->actingAs($admin)
            ->get(SystemStatus::getUrl())
            ->assertOk()
            ->assertSee('Rydd feilede jobber');
    }

    public function test_clear_failed_jobs_action_is_hidden_when_no_failed_jobs(): void
    {
        $admin = $this->internalAdmin();

        $this->mock(\App\Services\Operations\RuntimeStatusService::class, function ($mock) {
            $mock->shouldReceive('snapshot')->andReturn([
                'failed_jobs_count' => 0,
                'database'  => ['available' => true, 'connection' => 'pgsql', 'driver' => 'pgsql', 'database' => 'test', 'error_message' => null],
                'redis'     => ['available' => true, 'connection' => 'default', 'host' => 'localhost', 'database' => '0', 'error_message' => null],
                'queue'     => ['driver' => 'redis', 'connection' => 'redis', 'queue' => 'default', 'known_queues' => [], 'failed_jobs_count' => 0],
                'scheduler' => ['available' => true, 'status_label' => 'Configured', 'task_count' => 0, 'tasks' => []],
                'uptime'    => ['available' => false, 'label' => ''],
                'cache_driver' => 'array', 'session_driver' => 'array',
                'app_env' => 'testing', 'app_debug' => false,
                'laravel_version' => '13.0.0', 'php_version' => PHP_VERSION,
                'app_url' => 'http://localhost',
            ]);
            $mock->shouldReceive('recentFailedJobs')->andReturn([]);
        });

        $this->mock(\App\Services\Operations\QueueSchedulerHealthService::class, function ($mock) {
            $mock->shouldReceive('evaluate')->andReturn(['ok' => true, 'scheduler' => 'ok', 'queue' => 'ok']);
        });

        $content = $this->actingAs($admin)
            ->get(SystemStatus::getUrl())
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Rydd feilede jobber', $content);
    }

    public function test_clear_failed_jobs_action_deletes_all_failed_jobs_rows(): void
    {
        $admin = $this->internalAdmin();

        $table = (string) config('queue.failed.table', 'failed_jobs');

        DB::table($table)->insert([
            'uuid'       => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue'      => 'default',
            'payload'    => json_encode(['displayName' => 'App\\Jobs\\JobA']),
            'exception'  => 'Exception: First failure',
            'failed_at'  => now(),
        ]);

        DB::table($table)->insert([
            'uuid'       => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue'      => 'ai-requirements',
            'payload'    => json_encode(['displayName' => 'App\\Jobs\\JobB']),
            'exception'  => 'Exception: Second failure',
            'failed_at'  => now()->subMinutes(5),
        ]);

        $this->assertSame(2, (int) DB::table($table)->count());

        Livewire::actingAs($admin)
            ->test(SystemStatus::class)
            ->call('handleClearFailedJobs')
            ->assertHasNoErrors();

        $this->assertSame(0, (int) DB::table($table)->count());
    }

    public function test_clear_failed_jobs_action_refreshes_snapshot_and_failed_jobs_list(): void
    {
        $admin = $this->internalAdmin();

        $table = (string) config('queue.failed.table', 'failed_jobs');
        DB::table($table)->insert([
            'uuid'       => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue'      => 'default',
            'payload'    => json_encode(['displayName' => 'App\\Jobs\\RefreshTestJob']),
            'exception'  => 'Exception: Will be cleared',
            'failed_at'  => now(),
        ]);

        $component = Livewire::actingAs($admin)->test(SystemStatus::class);

        $this->assertSame(1, (int) ($component->get('snapshot')['failed_jobs_count'] ?? 0));
        $this->assertCount(1, $component->get('failedJobs'));

        $component->call('handleClearFailedJobs')->assertHasNoErrors();

        $this->assertSame(0, (int) ($component->get('snapshot')['failed_jobs_count'] ?? 0));
        $this->assertCount(0, $component->get('failedJobs'));
    }

    public function test_clear_failed_jobs_translation_keys_resolve_correctly(): void
    {
        $previousLocale = app()->getLocale();
        app()->setLocale('no');

        try {
            $this->assertSame('Rydd feilede jobber', __('procynia.system_status.actions.clear_failed_jobs'));
            $this->assertSame('Feilede jobber er ryddet.', __('procynia.system_status.messages.failed_jobs_cleared'));
            $this->assertSame('Slett', __('procynia.system_status.actions.delete_failed_job'));
            $this->assertSame('Handling', __('procynia.system_status.fields.actions'));
            $this->assertSame('Fremdrift', __('procynia.system_status.scheduler.progress'));
            $this->assertSame('Neste kjøring', __('procynia.system_status.scheduler.next_run'));
            $this->assertSame('om', __('procynia.system_status.scheduler.in_prefix'));
            $this->assertSame('nå', __('procynia.system_status.scheduler.now'));
            $this->assertSame('Ikke tilgjengelig', __('procynia.system_status.scheduler.unavailable'));
            $this->assertSame('Feilet jobb er slettet.', __('procynia.system_status.messages.failed_job_deleted'));
            $this->assertSame('Feilet jobb finnes ikke lenger.', __('procynia.system_status.messages.failed_job_not_found'));
            $this->assertStringContainsString('failed_jobs-listen', (string) __('procynia.system_status.messages.clear_failed_jobs_description'));

            app()->setLocale('en');

            $this->assertSame('Clear failed jobs', __('procynia.system_status.actions.clear_failed_jobs'));
            $this->assertSame('Failed jobs have been cleared.', __('procynia.system_status.messages.failed_jobs_cleared'));
            $this->assertSame('Delete', __('procynia.system_status.actions.delete_failed_job'));
            $this->assertSame('Actions', __('procynia.system_status.fields.actions'));
            $this->assertSame('Failed job deleted.', __('procynia.system_status.messages.failed_job_deleted'));
            $this->assertSame('Failed job no longer exists.', __('procynia.system_status.messages.failed_job_not_found'));
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    public function test_failed_job_rows_show_a_delete_action_that_deletes_only_the_selected_row(): void
    {
        $admin = $this->internalAdmin();
        $table = (string) config('queue.failed.table', 'failed_jobs');

        $firstId = DB::table($table)->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\JobA']),
            'exception' => 'Exception: First failure',
            'failed_at' => now(),
        ]);

        $secondId = DB::table($table)->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue' => 'ai-requirements',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\JobB']),
            'exception' => 'Exception: Second failure',
            'failed_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($admin)->get(SystemStatus::getUrl())->assertOk();
        $response->assertSee(__('procynia.system_status.fields.actions'));
        $response->assertSee(__('procynia.system_status.actions.delete_failed_job'));

        Livewire::actingAs($admin)
            ->test(SystemStatus::class)
            ->call('promptDeleteFailedJob', $firstId)
            ->call('callMountedAction')
            ->assertHasNoErrors();

        Notification::assertNotified(__('procynia.system_status.messages.failed_job_deleted'));

        $this->assertDatabaseMissing($table, ['id' => $firstId]);
        $this->assertDatabaseHas($table, ['id' => $secondId]);
        $this->assertSame(1, (int) DB::table($table)->count());
    }

    public function test_failed_job_delete_action_refreshes_snapshot_and_handles_missing_rows_gracefully(): void
    {
        $admin = $this->internalAdmin();
        $table = (string) config('queue.failed.table', 'failed_jobs');

        $component = Livewire::actingAs($admin)->test(SystemStatus::class);

        $component
            ->call('promptDeleteFailedJob', 999999999)
            ->call('callMountedAction')
            ->assertHasNoErrors();

        Notification::assertNotified(__('procynia.system_status.messages.failed_job_not_found'));

        $this->assertSame(0, (int) ($component->get('snapshot')['failed_jobs_count'] ?? 0));
        $this->assertCount(0, $component->get('failedJobs'));
        $this->assertSame(0, (int) DB::table($table)->count());
    }

    public function test_operational_runbook_view_renders_multiline_summary_with_preserved_whitespace(): void
    {
        $admin = $this->internalAdmin();

        $runbook = OperationalRunbook::query()->create([
            'title' => 'Rutine med avsnitt',
            'category' => 'general',
            'summary' => "Første avsnitt.\nAndre avsnitt.\nTredje avsnitt.",
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->actingAs($admin)
            ->get(ViewOperationalRunbook::getUrl(['record' => $runbook]))
            ->assertOk()
            ->assertSee('Første avsnitt.')
            ->assertSee('Andre avsnitt.')
            ->assertSee('whitespace-pre-wrap', false);
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
            'database.connections.pgsql.database' => env('DB_DATABASE', 'procynia_test'),
            'database.connections.pgsql.host' => env('DB_HOST', 'postgres'),
            'database.connections.pgsql.port' => env('DB_PORT', '5432'),
            'database.connections.pgsql.username' => env('DB_USERNAME', 'gehard'),
            'database.connections.pgsql.password' => env('DB_PASSWORD', 'Opaque01'),
            'database.connections.pgsql.search_path' => 'public',
        ]);

        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');
        DB::reconnect('pgsql');
    }
}
