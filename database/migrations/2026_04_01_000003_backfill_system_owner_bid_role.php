<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasColumn('users', 'bid_role')) {
            return;
        }

        DB::table('users')
            ->where('bid_role', 'system_owner')
            ->update([
                'bid_manager_scope' => null,
            ]);

        $customerIds = DB::table('users')
            ->whereNotNull('customer_id')
            ->distinct()
            ->orderBy('customer_id')
            ->pluck('customer_id');

        foreach ($customerIds as $customerId) {
            $hasSystemOwner = DB::table('users')
                ->where('customer_id', $customerId)
                ->where('bid_role', 'system_owner')
                ->exists();

            if ($hasSystemOwner) {
                continue;
            }

            $systemOwnerId = DB::table('users')
                ->where('customer_id', $customerId)
                ->orderByRaw("CASE WHEN role = 'customer_admin' THEN 0 ELSE 1 END")
                ->orderBy('created_at')
                ->orderBy('id')
                ->value('id');

            if ($systemOwnerId === null) {
                continue;
            }

            DB::table('users')
                ->where('id', $systemOwnerId)
                ->update([
                    'bid_role' => 'system_owner',
                    'bid_manager_scope' => null,
                ]);
        }
    }

    public function down(): void
    {
        if (! DB::getSchemaBuilder()->hasColumn('users', 'bid_role')) {
            return;
        }

        DB::table('users')
            ->where('bid_role', 'system_owner')
            ->update([
                'bid_role' => 'bid_manager',
                'bid_manager_scope' => 'company',
            ]);
    }
};
