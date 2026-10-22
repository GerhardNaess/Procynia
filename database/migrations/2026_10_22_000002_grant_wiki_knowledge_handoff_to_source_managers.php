<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * wiki.knowledge.handoff is split out of wiki.source.manage — docs/external-api-phase-0-plan.md §4.2.
 *
 * Until now handing knowledge over to the Wiki rode on wiki.source.manage. Every role that holds
 * that key today is given the new one as well, so nobody loses «Lag kunnskapsartikkel» and nobody
 * gains anything they could not already do. From here on a customer can grant the two separately.
 */
return new class extends Migration
{
    private const SOURCE_MANAGE = 'wiki.source.manage';

    private const KNOWLEDGE_HANDOFF = 'wiki.knowledge.handoff';

    public function up(): void
    {
        $now = now();

        DB::table('customer_role_permissions')
            ->where('permission_key', self::SOURCE_MANAGE)
            ->whereNotExists(fn ($existing) => $existing->selectRaw('1')
                ->from('customer_role_permissions as handoff')
                ->whereColumn('handoff.customer_role_id', 'customer_role_permissions.customer_role_id')
                ->where('handoff.permission_key', self::KNOWLEDGE_HANDOFF))
            ->orderBy('id')
            ->pluck('customer_role_id')
            ->each(fn (int $roleId) => DB::table('customer_role_permissions')->insert([
                'customer_role_id' => $roleId,
                'permission_key' => self::KNOWLEDGE_HANDOFF,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        // The key does not exist in the code this rolls back to; CustomerRole::permissionKeys()
        // would drop it anyway. Removing it keeps the table honest.
        DB::table('customer_role_permissions')->where('permission_key', self::KNOWLEDGE_HANDOFF)->delete();
    }
};
