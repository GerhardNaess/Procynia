<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One-time repair of orders left stranded by the old self-service flow.
     *
     * Ordering from the Abonnement page used to write `requested` and stop there, waiting for an
     * approval step that was never built. That left the row in the one state the product cannot
     * act on: `requested` carries no access, so the left rail says "Ikke bestilt" and
     * EnsureModuleIsEnabled refuses the route, while overviewFor() reports "Bestilt" and therefore
     * withholds the Bestill button. Nothing in the UI could move the row on.
     *
     * Self-service now activates in the same request (BillingController::requestPackage ->
     * ModuleEntitlementService::activatePackage), and nothing else writes `requested` any more, so
     * every row still sitting at that status came from the old flow and is stuck. They are granted
     * here, keeping requested_by/requested_at so the original order stays on record.
     *
     * `requested` remains a legitimate state for an admin-mediated path; this only clears the
     * backlog that predates the fix.
     */
    public function up(): void
    {
        $orderable = array_keys(array_filter(
            config('procynia_modules.packages', []),
            fn (array $package): bool => (bool) ($package['orderable'] ?? false),
        ));

        if ($orderable === []) {
            return;
        }

        $now = now();

        DB::table('customer_package_entitlements')
            ->where('status', 'requested')
            ->whereIn('package_key', $orderable)
            ->update([
                'status' => 'active',
                // An earlier activation date is history, not a defect: only fill the gap.
                'activated_at' => DB::raw('COALESCE(activated_at, '.DB::getPdo()->quote($now->toDateTimeString()).')'),
                'deactivated_at' => null,
                'updated_at' => $now,
            ]);
    }

    /**
     * Not reversible: the repaired rows are indistinguishable from packages activated normally,
     * and rolling them back would withdraw access the customer now legitimately holds.
     */
    public function down(): void {}
};
