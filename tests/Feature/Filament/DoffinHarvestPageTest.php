<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\DoffinHarvest;
use App\Models\SupplierLookupRun;
use App\Services\Doffin\DoffinAdminExecutionService;
use App\Services\Doffin\SupplierLookupRunService;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class DoffinHarvestPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_internal_admin_can_open_the_doffin_harvest_page(): void
    {
        $response = $this->actingAs($this->internalAdmin())
            ->get(DoffinHarvest::getUrl());

        $response->assertOk();
    }

    public function test_run_harvest_calls_the_execution_service_and_sets_the_result_summary(): void
    {
        $admin = $this->internalAdmin();
        $service = Mockery::mock(DoffinAdminExecutionService::class);

        $service->shouldReceive('runHarvest')
            ->once()
            ->with([
                'from' => '2026-03-01',
                'to' => '2026-03-29',
                'supplier_name' => '',
                'types' => ['RESULT'],
            ])
            ->andReturn([
                'mode' => 'harvest',
                'run_id' => 11,
                'harvest' => [
                    'windows_processed' => 2,
                    'windows_split' => 1,
                    'notices_seen' => 5,
                    'records_built' => 6,
                ],
                'persistence' => [
                    'notices_persisted' => 5,
                    'suppliers_touched' => 4,
                    'notices_created' => 5,
                    'notices_updated' => 0,
                    'suppliers_created' => 4,
                    'suppliers_updated' => 0,
                    'links_created' => 6,
                    'links_updated' => 0,
                ],
                'selected_candidate' => null,
                'winner_candidates' => [],
            ]);

        $this->app->instance(DoffinAdminExecutionService::class, $service);

        Livewire::actingAs($admin)
            ->test(DoffinHarvest::class)
            ->set('data.from', '2026-03-01')
            ->set('data.to', '2026-03-29')
            ->set('data.types', ['RESULT'])
            ->set('data.supplier_name', '')
            ->call('runHarvest')
            ->assertSet('resultSummary.mode', 'harvest')
            ->assertSet('resultSummary.harvest.notices_seen', 5);

        Notification::assertNotified('Doffin harvest completed');
    }

    public function test_run_supplier_lookup_queues_a_background_run_and_loads_the_status_panel(): void
    {
        $admin = $this->internalAdmin();
        $run = SupplierLookupRun::query()->create([
            'uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'status' => SupplierLookupRun::STATUS_QUEUED,
            'source_from_date' => '2026-03-01',
            'source_to_date' => '2026-03-29',
            'supplier_query' => '4Service Eir Renhold AS',
            'notice_type_filters' => ['RESULT'],
            'created_by' => $admin->id,
        ]);
        $service = Mockery::mock(SupplierLookupRunService::class);

        $service->shouldReceive('statusPayloadForUuid')
            ->once()
            ->with('')
            ->andReturn(null);

        $service->shouldReceive('startRun')
            ->once()
            ->with([
                'from' => '2026-03-01',
                'to' => '2026-03-29',
                'supplier_name' => '4Service Eir Renhold AS',
                'types' => ['RESULT'],
            ], Mockery::type(User::class))
            ->andReturn($run);

        $service->shouldReceive('statusPayloadForUuid')
            ->once()
            ->with('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
            ->andReturn([
                'run_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'status' => 'queued',
                'status_label' => 'Queued',
                'supplier_query' => '4Service Eir Renhold AS',
                'resolved_winner_label' => null,
                'resolved_winner_id' => null,
                'total_items' => 0,
                'processed_items' => 0,
                'matched_items' => 0,
                'failed_items' => 0,
                'progress_percent' => 0.0,
                'estimated_seconds_remaining' => null,
                'started_at' => null,
                'finished_at' => null,
                'last_heartbeat_at' => null,
                'error_message' => null,
                'is_terminal' => false,
            ]);

        $this->app->instance(SupplierLookupRunService::class, $service);

        Livewire::actingAs($admin)
            ->test(DoffinHarvest::class)
            ->set('data.from', '2026-03-01')
            ->set('data.to', '2026-03-29')
            ->set('data.types', ['RESULT'])
            ->set('data.supplier_name', '4Service Eir Renhold AS')
            ->call('runSupplierLookup')
            ->assertSet('supplierLookupRunUuid', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
            ->assertSet('supplierLookupStatus.status', 'queued')
            ->assertSet('supplierLookupStatus.supplier_query', '4Service Eir Renhold AS');

        Notification::assertNotified('Supplier lookup queued');
    }

    public function test_the_page_shows_failures_from_the_execution_service(): void
    {
        $admin = $this->internalAdmin();
        $service = Mockery::mock(DoffinAdminExecutionService::class);

        $service->shouldReceive('runHarvest')
            ->once()
            ->andThrow(new \RuntimeException('Single-day window exceeded the accessible result cap.'));

        $this->app->instance(DoffinAdminExecutionService::class, $service);

        Livewire::actingAs($admin)
            ->test(DoffinHarvest::class)
            ->set('data.from', '2026-03-29')
            ->set('data.to', '2026-03-29')
            ->set('data.types', ['RESULT'])
            ->call('runHarvest')
            ->assertSet('lastError', 'Single-day window exceeded the accessible result cap.');

        Notification::assertNotified('Doffin execution failed');
    }

    private function internalAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
    }
}
