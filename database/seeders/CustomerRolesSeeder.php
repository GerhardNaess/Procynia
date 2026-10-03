<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds sensible starter roles for the configurable Quality and Enterprise Wiki
 * permission model.
 *
 * Important:
 * - Bid/Anbud roles are deliberately NOT touched here.
 * - System Owner is deliberately NOT created here; it remains an existing
 *   customer-admin authority and already receives the full permission catalogue
 *   through CustomerPermissionService.
 * - These are starter templates only. Customers may rename, change, deactivate
 *   or delete them afterwards.
 *
 * Re-running:
 * The seeder skips a customer that already has one or more customer_roles.
 * This is intentional: role names are customer-configurable, so a normal
 * name-based upsert could recreate a role the customer renamed or deleted.
 */
class CustomerRolesSeeder extends Seeder
{
    private const DEFAULT_ROLES = [
        [
            'name' => 'Kvalitetsleder',
            'description' => 'Full tilgang til å administrere Kvalitet.',
            'permissions' => [
                'quality.view',
                'quality.create',
                'quality.edit',
                'quality.approve',
                'quality.delete',
            ],
        ],
        [
            'name' => 'Kvalitetsbidragsyter',
            'description' => 'Kan lese, opprette og redigere innhold i Kvalitet.',
            'permissions' => [
                'quality.view',
                'quality.create',
                'quality.edit',
            ],
        ],
        [
            'name' => 'Kvalitetsgodkjenner',
            'description' => 'Kan lese og godkjenne innhold i Kvalitet.',
            'permissions' => [
                'quality.view',
                'quality.approve',
            ],
        ],
        [
            'name' => 'Kvalitetsleser',
            'description' => 'Har lesetilgang til Kvalitet.',
            'permissions' => [
                'quality.view',
            ],
        ],
        [
            'name' => 'Wiki-ansvarlig',
            'description' => 'Full tilgang til Enterprise Wiki, inkludert kilder, godkjenning og sletting.',
            'permissions' => [
                'wiki.view',
                'wiki.edit',
                'wiki.review',
                'wiki.approve',
                'wiki.delete',
                'wiki.source.manage',
            ],
        ],
        [
            'name' => 'Wiki-redaktør',
            'description' => 'Kan lese og redigere sider i Enterprise Wiki.',
            'permissions' => [
                'wiki.view',
                'wiki.edit',
            ],
        ],
        [
            'name' => 'Wiki-godkjenner',
            'description' => 'Kan lese, gjennomgå og godkjenne sider i Enterprise Wiki.',
            'permissions' => [
                'wiki.view',
                'wiki.review',
                'wiki.approve',
            ],
        ],
        [
            'name' => 'Wiki-kildeansvarlig',
            'description' => 'Kan lese Enterprise Wiki og administrere kildedokumenter.',
            'permissions' => [
                'wiki.view',
                'wiki.source.manage',
            ],
        ],
        [
            'name' => 'Wiki-leser',
            'description' => 'Har lesetilgang til Enterprise Wiki.',
            'permissions' => [
                'wiki.view',
            ],
        ],
    ];

    public function run(): void
    {
        $customerIds = DB::table('customers')
            ->orderBy('id')
            ->pluck('id');

        foreach ($customerIds as $customerId) {
            $alreadyConfigured = DB::table('customer_roles')
                ->where('customer_id', $customerId)
                ->exists();

            if ($alreadyConfigured) {
                $this->command?->warn(
                    "Customer {$customerId}: hopper over standardroller fordi kunden allerede har konfigurerbare roller."
                );

                continue;
            }

            DB::transaction(function () use ($customerId): void {
                foreach (self::DEFAULT_ROLES as $role) {
                    $roleId = DB::table('customer_roles')->insertGetId([
                        'customer_id' => $customerId,
                        'name' => $role['name'],
                        'description' => $role['description'],
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('customer_role_permissions')->insert(
                        array_map(
                            static fn (string $permission): array => [
                                'customer_role_id' => $roleId,
                                'permission_key' => $permission,
                            ],
                            $role['permissions'],
                        ),
                    );
                }
            });

            $this->command?->info(
                "Customer {$customerId}: opprettet ".count(self::DEFAULT_ROLES).' standardroller.'
            );
        }
    }
}
