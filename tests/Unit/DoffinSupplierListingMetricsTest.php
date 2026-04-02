<?php

namespace Tests\Unit;

use App\Models\DoffinNotice;
use App\Models\DoffinNoticeSupplier;
use App\Models\DoffinSupplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoffinSupplierListingMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_calculates_a_nok_only_total_estimated_value_for_supplier_listings(): void
    {
        $supplier = DoffinSupplier::query()->create([
            'supplier_name' => 'Supplier One AS',
            'organization_number' => '917719993',
            'normalized_name' => 'supplier one as',
        ]);

        $nokNotice = DoffinNotice::query()->create([
            'notice_id' => '2026-900001',
            'estimated_value_amount' => '100000.00',
            'estimated_value_currency_code' => 'NOK',
            'estimated_value_display' => '100000 NOK',
        ]);

        $eurNotice = DoffinNotice::query()->create([
            'notice_id' => '2026-900002',
            'estimated_value_amount' => '50000.00',
            'estimated_value_currency_code' => 'EUR',
            'estimated_value_display' => '50000 EUR',
        ]);

        $nullNotice = DoffinNotice::query()->create([
            'notice_id' => '2026-900003',
            'estimated_value_amount' => null,
            'estimated_value_currency_code' => 'NOK',
            'estimated_value_display' => 'Unknown NOK',
        ]);

        foreach ([$nokNotice, $eurNotice, $nullNotice] as $notice) {
            DoffinNoticeSupplier::query()->create([
                'doffin_notice_id' => $notice->id,
                'doffin_supplier_id' => $supplier->id,
                'winner_lots_json' => [],
                'source' => 'test',
            ]);
        }

        $listedSupplier = DoffinSupplier::query()
            ->withListingMetrics()
            ->whereKey($supplier->id)
            ->firstOrFail();

        $this->assertSame(3, $listedSupplier->notices_count);
        $this->assertSame('100000.00', $listedSupplier->total_estimated_value_amount);
    }
}
