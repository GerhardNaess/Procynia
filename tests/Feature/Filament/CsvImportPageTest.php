<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\CsvImport;
use App\Models\CpvCode;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class CsvImportPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_admin_can_open_the_csv_import_page(): void
    {
        $response = $this->actingAs($this->internalAdmin())
            ->get(CsvImport::getUrl());

        $response->assertOk();
        $response->assertSee('CSV Import');
    }

    public function test_run_import_calls_the_canonical_cpv_import_command(): void
    {
        $admin = $this->internalAdmin();

        CpvCode::query()->create([
            'code' => '03000000',
            'description_en' => 'Agricultural products',
            'description_no' => 'Landbruksprodukter',
        ]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('cpv:import-catalog')
            ->andReturn(0);

        Artisan::shouldReceive('output')
            ->once()
            ->andReturn('[DOFFIN][CPV] Import completed. created=0 updated=0 skipped=9409 total=9409');

        Livewire::actingAs($admin)
            ->test(CsvImport::class)
            ->call('runImport')
            ->assertSet('catalogCount', 1)
            ->assertSet('lastOutput', '[DOFFIN][CPV] Import completed. created=0 updated=0 skipped=9409 total=9409')
            ->assertSet('lastError', null);

        Notification::assertNotified('CSV import completed');
    }

    public function test_the_page_surfaces_import_failures(): void
    {
        $admin = $this->internalAdmin();

        Artisan::shouldReceive('call')
            ->once()
            ->with('cpv:import-catalog')
            ->andReturn(1);

        Artisan::shouldReceive('output')
            ->once()
            ->andReturn('[DOFFIN][CPV] Import failed: Catalog file was not found.');

        Livewire::actingAs($admin)
            ->test(CsvImport::class)
            ->call('runImport')
            ->assertSet('lastError', '[DOFFIN][CPV] Import failed: Catalog file was not found.');

        Notification::assertNotified('CSV import failed');
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
