<?php

namespace Tests\Feature\Console;

use App\Jobs\Doffin\PrepareDoffinSupplierHarvestRun;
use App\Models\DoffinSupplierHarvestRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HarvestDoffinSuppliersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_a_queued_supplier_harvest_run(): void
    {
        Queue::fake();

        $this->artisan('doffin:harvest-suppliers', [
            '--from' => '2026-03-01',
            '--to' => '2026-03-29',
            '--type' => ['RESULT'],
        ])
            ->expectsOutputToContain('Queued supplier harvest run')
            ->assertSuccessful();

        $this->assertDatabaseCount('doffin_supplier_harvest_runs', 1);
        $this->assertDatabaseHas('doffin_supplier_harvest_runs', [
            'status' => DoffinSupplierHarvestRun::STATUS_QUEUED,
        ]);

        Queue::assertPushed(PrepareDoffinSupplierHarvestRun::class, 1);
    }
}
