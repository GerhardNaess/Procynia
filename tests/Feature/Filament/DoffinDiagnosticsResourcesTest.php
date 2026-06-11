<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\DoffinImportRunResource;
use App\Filament\Resources\SyncLogResource;
use App\Models\Customer;
use App\Models\DoffinImportRun;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Notice;
use App\Models\SyncLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class DoffinDiagnosticsResourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_doffin_diagnostics_resources_expose_expected_navigation_metadata(): void
    {
        $this->assertSame('Importkjøringer', DoffinImportRunResource::getNavigationLabel());
        $this->assertSame('Drift', DoffinImportRunResource::getNavigationGroup());
        $this->assertSame(7, DoffinImportRunResource::getNavigationSort());
        $this->assertTrue(DoffinImportRunResource::shouldRegisterNavigation());
        $this->assertSame(['index', 'view'], array_keys(DoffinImportRunResource::getPages()));

        $this->assertSame('Synkroniseringslogg', SyncLogResource::getNavigationLabel());
        $this->assertSame('Drift', SyncLogResource::getNavigationGroup());
        $this->assertSame(8, SyncLogResource::getNavigationSort());
        $this->assertTrue(SyncLogResource::shouldRegisterNavigation());
        $this->assertSame(['index'], array_keys(SyncLogResource::getPages()));
    }

    public function test_internal_admin_can_access_doffin_import_run_pages_and_see_run_details(): void
    {
        $admin = $this->internalAdmin();
        $run = DoffinImportRun::query()->create([
            'trigger' => 'scheduled-import',
            'started_at' => Carbon::parse('2026-06-11 08:15:00'),
            'finished_at' => Carbon::parse('2026-06-11 08:19:00'),
            'status' => 'completed',
            'fetched_count' => 128,
            'created_count' => 14,
            'updated_count' => 22,
            'skipped_count' => 91,
            'failed_count' => 777,
            'error_message' => 'Import finished with one recoverable warning.',
            'meta' => [
                'source' => 'diagnostics-suite',
                'batch_id' => 'batch-123',
            ],
        ]);

        $this->assertSame([
            'source' => 'diagnostics-suite',
            'batch_id' => 'batch-123',
        ], $run->fresh()->meta);

        $this->actingAs($admin);

        $this->assertTrue(DoffinImportRunResource::canAccess());
        $this->assertFalse(DoffinImportRunResource::canCreate());
        $this->assertFalse(DoffinImportRunResource::canEdit($run));
        $this->assertFalse(DoffinImportRunResource::canDelete($run));

        $this->get(DoffinImportRunResource::getUrl('index'))
            ->assertOk()
            ->assertSee('scheduled-import')
            ->assertSee('completed')
            ->assertSee('128')
            ->assertSee('14')
            ->assertSee('22')
            ->assertSee('91')
            ->assertSee('777');

        $this->get(DoffinImportRunResource::getUrl('view', ['record' => $run]))
            ->assertOk()
            ->assertSee('Run summary')
            ->assertSee('Meta')
            ->assertSee('Import finished with one recoverable warning.');
    }

    public function test_internal_admin_can_access_sync_log_index_and_see_related_notice_data(): void
    {
        $admin = $this->internalAdmin();
        $notice = Notice::query()->create([
            'notice_id' => 'DOFFIN-'.Str::upper(Str::random(8)),
            'title' => 'Diagnostics notice',
            'status' => 'published',
        ]);
        $log = SyncLog::query()->create([
            'job_type' => 'notice_parse',
            'status' => 'completed',
            'notice_id' => $notice->id,
            'message' => 'Parsed notice successfully for diagnostics coverage.',
            'started_at' => Carbon::parse('2026-06-11 09:00:00'),
            'finished_at' => Carbon::parse('2026-06-11 09:03:00'),
        ]);

        $this->actingAs($admin);

        $this->assertTrue(SyncLogResource::canAccess());

        $this->get(SyncLogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('notice_parse')
            ->assertSee('completed')
            ->assertSee($log->message)
            ->assertSee($notice->notice_id);
    }

    public function test_customer_admin_cannot_access_doffin_diagnostics_resources(): void
    {
        $customerAdmin = $this->customerAdmin();

        $this->actingAs($customerAdmin);

        $this->assertFalse(DoffinImportRunResource::canAccess());
        $this->get(DoffinImportRunResource::getUrl('index'))->assertForbidden();

        $run = DoffinImportRun::query()->create([
            'trigger' => 'manual',
            'started_at' => Carbon::parse('2026-06-11 10:00:00'),
            'status' => 'running',
        ]);

        $this->get(DoffinImportRunResource::getUrl('view', ['record' => $run]))->assertForbidden();

        $this->assertFalse(SyncLogResource::canAccess());
        $this->get(SyncLogResource::getUrl('index'))->assertForbidden();
    }

    private function internalAdmin(): User
    {
        return User::query()->create([
            'name' => 'Procynia Admin',
            'email' => 'procynia.admin+'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
    }

    private function customerAdmin(): User
    {
        $customer = $this->createCustomer();

        return User::query()->create([
            'name' => 'Kunde Admin',
            'email' => 'kunde.admin+'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
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
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
