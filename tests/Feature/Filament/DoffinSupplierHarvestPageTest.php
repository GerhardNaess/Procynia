<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\DoffinSupplierHarvest;
use App\Filament\Pages\DoffinSupplierHarvestRun;
use App\Models\DoffinSupplierHarvestRun as DoffinSupplierHarvestRunModel;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class DoffinSupplierHarvestPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_admin_can_open_the_supplier_harvest_page(): void
    {
        $response = $this->actingAs($this->internalAdmin())
            ->get(DoffinSupplierHarvest::getUrl());

        $response->assertOk();
    }

    public function test_starting_a_supplier_harvest_creates_a_queued_run_and_redirects_to_the_run_page(): void
    {
        Queue::fake();

        $admin = $this->internalAdmin();

        $component = Livewire::actingAs($admin)
            ->test(DoffinSupplierHarvest::class)
            ->set('data.from', '2026-03-01')
            ->set('data.to', '2026-03-29')
            ->set('data.types', ['RESULT'])
            ->call('startSupplierHarvest');

        $run = DoffinSupplierHarvestRunModel::query()->first();

        $this->assertInstanceOf(DoffinSupplierHarvestRunModel::class, $run);
        $this->assertSame(DoffinSupplierHarvestRunModel::STATUS_QUEUED, $run->status);
        $this->assertSame(['RESULT'], $run->notice_type_filters);
        $this->assertSame($admin->id, $run->created_by);

        $component->assertRedirect(DoffinSupplierHarvestRun::getUrl([
            'runUuid' => $run->uuid,
        ]));

        Notification::assertNotified('Supplier harvest queued');
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
