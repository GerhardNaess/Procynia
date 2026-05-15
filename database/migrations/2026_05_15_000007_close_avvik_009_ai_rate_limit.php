<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Purpose: Close AVVIK-009 in existing databases after AI rate limiting has been implemented.
     * Inputs: None.
     * Returns: None.
     * Side effects: Updates the operational_deviations row for AVVIK-009 when it exists.
     */
    public function up(): void
    {
        if (! DB::table('operational_deviations')->where('code', 'AVVIK-009')->exists()) {
            return;
        }

        DB::table('operational_deviations')
            ->where('code', 'AVVIK-009')
            ->update([
                'status' => 'closed',
                'started_at' => '2026-05-15 22:00:00',
                'ready_for_verification_at' => '2026-05-15 22:30:00',
                'verified_at' => '2026-05-15 22:45:00',
                'closed_at' => '2026-05-15 22:45:00',
                'verification_notes' => 'Server-side AI rate limiting is implemented. AI operations are limited per user and per customer. Uncontrolled AI volume is stopped before AI call or AI job dispatch starts. Users receive a controlled and understandable message. Non-sensitive usage logging is added as a basis for later limit tuning. The existing entitlement check remains in place. AVVIK-010 is not closed.',
                'updated_at' => now(),
            ]);
    }

    /**
     * Purpose: Reopen AVVIK-009 when the migration is rolled back.
     * Inputs: None.
     * Returns: None.
     * Side effects: Restores the original open status and clears the closure timestamps when the row exists.
     */
    public function down(): void
    {
        if (! DB::table('operational_deviations')->where('code', 'AVVIK-009')->exists()) {
            return;
        }

        DB::table('operational_deviations')
            ->where('code', 'AVVIK-009')
            ->update([
                'status' => 'new',
                'started_at' => null,
                'ready_for_verification_at' => null,
                'verified_at' => null,
                'closed_at' => null,
                'verification_notes' => null,
                'updated_at' => now(),
            ]);
    }
};
