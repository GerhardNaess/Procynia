<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give every customer that already exists the Tender package.
     *
     * Until now Anbud was reachable because the menu hardcoded it as available and no route
     * checked anything. Now entitlements decide, which means that without this backfill the first
     * deploy would silently take Anbud away from every paying customer who is using it today.
     *
     * This is a one-off reconciliation of existing state, not a default: customers created after
     * this migration start with Wiki/Core only and order Tender like any other package.
     */
    public function up(): void
    {
        $now = now();

        $rows = DB::table('customers')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('customer_package_entitlements')
                    ->whereColumn('customer_package_entitlements.customer_id', 'customers.id')
                    ->where('customer_package_entitlements.package_key', 'tender');
            })
            ->pluck('id')
            ->map(fn (int $customerId): array => [
                'customer_id' => $customerId,
                'package_key' => 'tender',
                'status' => 'active',
                'activated_at' => $now,
                'note' => 'Backfilled: Anbud was available to every customer before packages existed.',
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('customer_package_entitlements')->insert($chunk);
        }
    }

    /**
     * Only the rows this migration created, identified by the note it wrote. A customer who
     * ordered Tender for real must survive a rollback.
     */
    public function down(): void
    {
        DB::table('customer_package_entitlements')
            ->where('package_key', 'tender')
            ->where('note', 'Backfilled: Anbud was available to every customer before packages existed.')
            ->delete();
    }
};
