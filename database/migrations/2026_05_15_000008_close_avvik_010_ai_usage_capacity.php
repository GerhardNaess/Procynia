<?php

use App\Models\OperationalDeviation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purpose: Close AVVIK-010 in existing databases after the AI usage overview has been introduced.
     * Inputs: None.
     * Returns: None.
     * Side effects: Updates the AVVIK-010 deviation row when the table exists.
     */
    public function up(): void
    {
        if (! Schema::hasTable('operational_deviations')) {
            return;
        }

        DB::table('operational_deviations')
            ->where('code', 'AVVIK-010')
            ->update([
                'status' => OperationalDeviation::STATUS_CLOSED,
                'started_at' => '2026-05-15 23:00:00',
                'ready_for_verification_at' => '2026-05-15 23:30:00',
                'verified_at' => '2026-05-15 23:45:00',
                'closed_at' => '2026-05-15 23:45:00',
                'verification_notes' => 'AI usage is now measured from ai_usage_events. Admin can review AI usage per customer and per user. Allowed and blocked AI operations are visible, including user-limit and customer-limit blocks. The view gives internal evidence for later tuning of included AI capacity. No sensitive prompt, document, or answer text is stored or shown. This is not billing or Stripe usage billing.',
                'updated_at' => now(),
            ]);
    }

    /**
     * Purpose: Reopen AVVIK-010 when rolling back the migration.
     * Inputs: None.
     * Returns: None.
     * Side effects: Restores the deviation row to an open state when the table exists.
     */
    public function down(): void
    {
        if (! Schema::hasTable('operational_deviations')) {
            return;
        }

        DB::table('operational_deviations')
            ->where('code', 'AVVIK-010')
            ->update([
                'status' => OperationalDeviation::STATUS_NEW,
                'started_at' => null,
                'ready_for_verification_at' => null,
                'verified_at' => null,
                'closed_at' => null,
                'verification_notes' => null,
                'updated_at' => now(),
            ]);
    }
};
