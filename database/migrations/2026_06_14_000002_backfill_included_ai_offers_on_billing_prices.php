<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('billing_prices')) {
            return;
        }

        $backfill = [
            'ultra' => 60,
            'max'   => 20,
            'pro'   => 3,
        ];

        foreach ($backfill as $tierKey => $aiOffers) {
            DB::table('billing_prices')
                ->where('tier_key', $tierKey)
                ->where('included_ai_offers', 0)
                ->update(['included_ai_offers' => $aiOffers]);
        }
    }

    public function down(): void
    {
        // Intentionally empty — reversing a data backfill would risk overwriting
        // manually corrected or environment-specific values set after the original run.
    }
};
