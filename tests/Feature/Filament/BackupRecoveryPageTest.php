<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\BackupRecovery;
use App\Filament\Pages\SystemStatus;
use App\Models\BackupRun;
use App\Models\BackupSetting;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Operations\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

class BackupRecoveryPageTest extends TestCase
{
    use RefreshDatabase;
    use UsesProjectPostgresConnection;

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

    public function test_backup_recovery_page_is_accessible_to_internal_admin(): void
    {
        $admin = $this->internalAdmin();

        $this->actingAs($admin)
            ->get(BackupRecovery::getUrl())
            ->assertOk()
            ->assertSee('Sikkerhetskopi og gjenoppretting');
    }

    public function test_backup_recovery_page_clarifies_that_restore_is_controlled(): void
    {
        $admin = $this->internalAdmin();

        $this->actingAs($admin)
            ->get(BackupRecovery::getUrl())
            ->assertOk()
            ->assertSee(__('procynia.backup_recovery.messages.temp_notice'))
            ->assertSee(__('procynia.backup_recovery.messages.restore_note'))
            ->assertDontSee('wire:click="restore"');
    }

    public function test_backup_recovery_page_is_not_accessible_to_customer_admin(): void
    {
        $user = User::query()->create([
            'name' => 'Customer Admin',
            'email' => 'customer.admin+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => null,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $this->assertFalse(BackupRecovery::canAccess());
    }

    public function test_super_admin_with_customer_id_cannot_access_backup_recovery(): void
    {
        $customer = $this->createMinimalCustomer();

        $user = User::query()->create([
            'name' => 'Super Admin With Customer',
            'email' => 'super.with.customer+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $this->assertFalse(BackupRecovery::canAccess());
    }

    public function test_regular_user_cannot_access_backup_recovery(): void
    {
        $user = User::query()->create([
            'name' => 'Regular User',
            'email' => 'regular.user+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => null,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $this->assertFalse(BackupRecovery::canAccess());
    }

    public function test_enable_backup_action_sets_backup_enabled(): void
    {
        $admin = $this->internalAdmin();

        BackupSetting::create(['backup_enabled' => false]);

        Livewire::actingAs($admin)
            ->test(BackupRecovery::class)
            ->callAction('enableBackup');

        $this->assertTrue(BackupSetting::first()->backup_enabled);
    }

    public function test_disable_backup_action_clears_backup_enabled(): void
    {
        $admin = $this->internalAdmin();

        BackupSetting::create(['backup_enabled' => true]);

        Livewire::actingAs($admin)
            ->test(BackupRecovery::class)
            ->callAction('disableBackup');

        $this->assertFalse(BackupSetting::first()->backup_enabled);
    }

    public function test_manual_backup_action_creates_a_backup_run(): void
    {
        $admin = $this->internalAdmin();

        $fakeRun = new BackupRun([
            'type' => BackupRun::TYPE_MANUAL,
            'status' => BackupRun::STATUS_SUCCESS,
            'started_at' => now(),
            'finished_at' => now(),
            'duration_seconds' => 3,
        ]);
        $fakeRun->id = 99;

        $mock = $this->mock(BackupService::class);
        $mock->shouldReceive('evaluateStatus')->andReturn([
            'enabled' => true,
            'directory' => '/backup/procynia',
            'directory_exists' => false,
            'last_success_at' => null,
            'last_success_at_human' => null,
            'last_failed_at' => null,
            'last_failed_at_human' => null,
            'last_scheduler_heartbeat_at' => null,
            'last_scheduler_heartbeat_at_human' => null,
            'file_count' => 0,
            'warnings' => [],
            'ok' => true,
        ]);
        $mock->shouldReceive('listBackupFiles')->andReturn([]);
        // The manual action is only offered where the runtime can execute the Compose script.
        $mock->shouldReceive('legacyBackupIsSupported')->andReturn(true);
        $mock->shouldReceive('runBackup')->with(BackupRun::TYPE_MANUAL, \Mockery::any())->andReturn($fakeRun);

        Livewire::actingAs($admin)
            ->test(BackupRecovery::class)
            ->callAction('manualBackup');
    }

    /**
     * In a runtime that cannot execute the legacy Compose script — Azure Container Apps — the manual
     * backup button must not be offered. BackupService refuses the run regardless; this keeps the
     * admin panel from showing an action that cannot work.
     */
    public function test_manual_backup_action_is_hidden_when_the_runtime_cannot_run_the_legacy_backup(): void
    {
        $admin = $this->internalAdmin();

        config(['procynia.backup.legacy_enabled' => false]);

        Livewire::actingAs($admin)
            ->test(BackupRecovery::class)
            ->assertActionHidden('manualBackup')
            ->assertActionVisible('refresh');
    }

    public function test_backup_service_enable_disable_persists(): void
    {
        $admin = $this->internalAdmin();
        $service = app(BackupService::class);

        $service->enableBackup($admin);
        $this->assertTrue($service->isEnabled());
        $this->assertNotNull(BackupSetting::first()->last_started_at);

        $service->disableBackup($admin);
        $this->assertFalse($service->isEnabled());
        $this->assertNotNull(BackupSetting::first()->last_stopped_at);
    }

    public function test_backup_service_evaluate_status_warns_when_overdue(): void
    {
        $service = app(BackupService::class);

        $setting = BackupSetting::create(['backup_enabled' => true]);
        $setting->last_scheduler_heartbeat_at = now()->subMinutes(5);
        $setting->save();

        BackupRun::create([
            'type' => BackupRun::TYPE_SCHEDULED,
            'status' => BackupRun::STATUS_SUCCESS,
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(2),
            'duration_seconds' => 10,
        ]);

        $status = $service->evaluateStatus();

        $this->assertFalse($status['ok']);
        $this->assertContains('backup_overdue', $status['warnings']);
    }

    public function test_backup_service_evaluate_status_warns_when_last_run_failed(): void
    {
        $service = app(BackupService::class);

        $setting = BackupSetting::create(['backup_enabled' => true]);
        $setting->last_scheduler_heartbeat_at = now()->subMinutes(5);
        $setting->save();

        BackupRun::create([
            'type' => BackupRun::TYPE_SCHEDULED,
            'status' => BackupRun::STATUS_FAILED,
            'started_at' => now()->subMinutes(30),
            'finished_at' => now()->subMinutes(30),
            'error_message' => 'Test error',
        ]);

        $status = $service->evaluateStatus();

        $this->assertContains('last_run_failed', $status['warnings']);
    }

    public function test_backup_service_evaluate_status_ok_when_recent_success(): void
    {
        $service = app(BackupService::class);

        $setting = BackupSetting::create(['backup_enabled' => true]);
        $setting->last_scheduler_heartbeat_at = now()->subMinutes(5);
        $setting->save();

        BackupRun::create([
            'type' => BackupRun::TYPE_SCHEDULED,
            'status' => BackupRun::STATUS_SUCCESS,
            'started_at' => now()->subMinutes(30),
            'finished_at' => now()->subMinutes(30),
            'duration_seconds' => 15,
        ]);

        $status = $service->evaluateStatus();

        $this->assertNotContains('backup_overdue', $status['warnings']);
        $this->assertNotContains('last_run_failed', $status['warnings']);
    }

    public function test_backup_service_evaluate_status_warns_backup_stopped_when_disabled(): void
    {
        $service = app(BackupService::class);

        BackupSetting::create(['backup_enabled' => false]);

        $status = $service->evaluateStatus();

        $this->assertContains('backup_stopped', $status['warnings']);
    }

    public function test_system_status_page_shows_backup_stopped_warning_when_disabled(): void
    {
        $admin = $this->internalAdmin();

        BackupSetting::create(['backup_enabled' => false]);

        $this->actingAs($admin)
            ->get(SystemStatus::getUrl())
            ->assertOk()
            ->assertSee('Backup er stoppet manuelt');
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

    private function createMinimalCustomer(): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => '🇳🇴'],
        );

        return Customer::query()->create([
            'name' => 'Test Customer',
            'slug' => 'test-customer-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
