<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AiForbruk;
use App\Filament\Pages\AiProfitability;
use App\Filament\Pages\BackupRecovery;
use App\Filament\Pages\BillingOverview;
use App\Filament\Pages\CsvImport;
use App\Filament\Pages\DoffinAutomaticImport;
use App\Filament\Pages\DoffinSupplierHarvest;
use App\Filament\Resources\BillingPriceResource;
use App\Filament\Resources\CustomerUserServiceLevelResource;
use App\Filament\Resources\DoffinImportRunResource;
use App\Filament\Resources\SyncLogResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNavigationLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_fakturering_sort_order_is_correct(): void
    {
        $this->assertSame(1, BillingOverview::getNavigationSort());
        $this->assertSame(2, BillingPriceResource::getNavigationSort());
        $this->assertSame(3, CustomerUserServiceLevelResource::getNavigationSort());
        $this->assertSame(4, AiForbruk::getNavigationSort());
        $this->assertSame(5, AiProfitability::getNavigationSort());
    }

    public function test_fakturering_items_have_correct_group(): void
    {
        $this->assertSame('Fakturering', BillingOverview::getNavigationGroup());
        $this->assertSame('Fakturering', BillingPriceResource::getNavigationGroup());
        $this->assertSame('Fakturering', CustomerUserServiceLevelResource::getNavigationGroup());
        $this->assertSame('Fakturering', AiForbruk::getNavigationGroup());
        $this->assertSame('Fakturering', AiProfitability::getNavigationGroup());
    }

    public function test_drift_navigation_labels_are_norwegian(): void
    {
        $this->assertSame('Sikkerhetskopi og gjenoppretting', BackupRecovery::getNavigationLabel());
        $this->assertSame('Doffin automatisk import', DoffinAutomaticImport::getNavigationLabel());
        $this->assertSame('Importkjøringer', DoffinImportRunResource::getNavigationLabel());
        $this->assertSame('Synkroniseringslogg', SyncLogResource::getNavigationLabel());
    }

    public function test_doffin_supplier_harvest_label_is_norwegian(): void
    {
        $this->assertSame('Leverandørinnhenting', DoffinSupplierHarvest::getNavigationLabel());
    }

    public function test_cpv_import_label_is_norwegian(): void
    {
        $this->assertSame('CPV-import', CsvImport::getNavigationLabel());
    }
}
