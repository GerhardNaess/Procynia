<?php

namespace Tests\Feature\Console;

use App\Models\DoffinNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillDoffinEstimatedValuesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_backfills_numeric_estimated_values_from_display_strings(): void
    {
        DoffinNotice::query()->create([
            'notice_id' => '2026-700001',
            'estimated_value_display' => '250000.0 NOK',
            'estimated_value_amount' => null,
            'estimated_value_currency_code' => null,
        ]);

        DoffinNotice::query()->create([
            'notice_id' => '2026-700002',
            'estimated_value_display' => '3.0E8 NOK',
            'estimated_value_amount' => null,
            'estimated_value_currency_code' => null,
        ]);

        DoffinNotice::query()->create([
            'notice_id' => '2026-700003',
            'estimated_value_display' => '8.0E7 NOK',
            'estimated_value_amount' => '80000000.00',
            'estimated_value_currency_code' => 'NOK',
        ]);

        $this->artisan('doffin:backfill-estimated-values')
            ->expectsOutputToContain('[PROCYNIA][DOFFIN][ESTIMATED_VALUE_BACKFILL]')
            ->assertSuccessful();

        $this->assertDatabaseHas('doffin_notices', [
            'notice_id' => '2026-700001',
            'estimated_value_amount' => '250000.00',
            'estimated_value_currency_code' => 'NOK',
        ]);

        $this->assertDatabaseHas('doffin_notices', [
            'notice_id' => '2026-700002',
            'estimated_value_amount' => '300000000.00',
            'estimated_value_currency_code' => 'NOK',
        ]);
    }
}
