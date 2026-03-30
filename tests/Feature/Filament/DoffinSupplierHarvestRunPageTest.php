<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\DoffinSupplierHarvestRun;
use App\Models\DoffinSupplierHarvestRun as DoffinSupplierHarvestRunModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoffinSupplierHarvestRunPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_admin_can_open_the_supplier_harvest_run_page_by_uuid(): void
    {
        $run = DoffinSupplierHarvestRunModel::query()->create([
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'status' => DoffinSupplierHarvestRunModel::STATUS_QUEUED,
            'source_from_date' => '2026-01-22',
            'source_to_date' => '2026-03-29',
            'notice_type_filters' => ['RESULT'],
        ]);

        $response = $this->actingAs($this->internalAdmin())
            ->get(DoffinSupplierHarvestRun::getUrl([
                'runUuid' => $run->uuid,
            ]));

        $response
            ->assertOk()
            ->assertSee('Preparing notices')
            ->assertSee('Building the notice list for the selected date range.')
            ->assertSee('Notice list is being prepared')
            ->assertSee('Run activity');
    }

    public function test_run_page_renders_processing_phase_metrics_activity_and_polling_for_active_runs(): void
    {
        $run = DoffinSupplierHarvestRunModel::query()->create([
            'uuid' => '22222222-2222-4222-8222-222222222222',
            'status' => DoffinSupplierHarvestRunModel::STATUS_RUNNING,
            'source_from_date' => '2026-01-22',
            'source_to_date' => '2026-03-29',
            'notice_type_filters' => ['RESULT'],
            'total_items' => 4483,
            'processed_items' => 388,
            'harvested_suppliers' => 95,
            'failed_items' => 6,
            'progress_percent' => 8.7,
            'started_at' => now()->subMinutes(13),
            'last_heartbeat_at' => now()->subMinute(),
            'estimated_seconds_remaining' => 1080,
        ]);

        $response = $this->actingAs($this->internalAdmin())
            ->get(DoffinSupplierHarvestRun::getUrl([
                'runUuid' => $run->uuid,
            ]));

        $response
            ->assertOk()
            ->assertSee('Processing notices')
            ->assertSee('Harvesting suppliers from Doffin notices.')
            ->assertSee('388')
            ->assertSee('4,483')
            ->assertSee('95')
            ->assertSee('18m')
            ->assertSee('388 notices processed')
            ->assertSee('95 suppliers harvested')
            ->assertSee('wire:poll.3s="refreshRunStatus"', false);
    }

    public function test_run_page_renders_completed_state_without_polling(): void
    {
        $run = DoffinSupplierHarvestRunModel::query()->create([
            'uuid' => '33333333-3333-4333-8333-333333333333',
            'status' => DoffinSupplierHarvestRunModel::STATUS_COMPLETED,
            'source_from_date' => '2026-01-22',
            'source_to_date' => '2026-03-29',
            'notice_type_filters' => ['RESULT'],
            'total_items' => 4483,
            'processed_items' => 4483,
            'harvested_suppliers' => 1021,
            'failed_items' => 12,
            'progress_percent' => 100,
            'started_at' => now()->subMinutes(30),
            'finished_at' => now()->subMinute(),
            'last_heartbeat_at' => now()->subMinute(),
            'estimated_seconds_remaining' => 0,
        ]);

        $response = $this->actingAs($this->internalAdmin())
            ->get(DoffinSupplierHarvestRun::getUrl([
                'runUuid' => $run->uuid,
            ]));

        $response
            ->assertOk()
            ->assertSee('Completed')
            ->assertSee('Supplier harvest finished successfully.')
            ->assertSee('Harvest completed')
            ->assertDontSee('wire:poll.3s="refreshRunStatus"', false);
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
