<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wiki approver, modelled exactly like QA: a capability layered on top of a user's ordinary
 * bid_role, never a replacement for it.
 *
 * WHY THE TWO HAD TO SEPARATE. Approving a claim says one statement is supported by its source.
 * Approving a page publishes it. They are different decisions with different consequences, and the
 * permission matrix already held them as two permissions — but the only supplemental capability a
 * user could be given was QA, so the page permission ended up being granted through the QA column.
 * A customer wanting one colleague to publish had to make every QA user a publisher.
 *
 * EXISTING ACCESS IS PRESERVED, NOT RESET. A customer who had ticked QA for approve_wiki_pages meant
 * it: those people publish today. The up migration therefore moves that grant rather than dropping
 * it — the customer's QA users become Wiki approvers, and the permission's 'qa' entry becomes
 * 'wiki_approver'. Nobody gains access and nobody loses it; the same people keep publishing, now
 * through a capability that says so. Customers who never granted it are untouched, and since no
 * user is a Wiki approver until this runs, the new default ['system_owner', 'wiki_approver'] grants
 * nothing on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_wiki_approver')->default(false)->after('is_qa');
        });

        foreach (DB::table('customers')->get(['id', 'permission_settings']) as $customer) {
            $settings = $this->decodeSettings($customer->permission_settings);
            $roles = $settings[Customer::PERMISSION_APPROVE_WIKI_PAGES] ?? null;

            // Only a customer who explicitly granted page approval to QA is affected. A null or
            // absent entry means "never configured", which resolves to the default.
            if (! is_array($roles) || ! in_array('qa', $roles, true)) {
                continue;
            }

            // The people who can publish today keep publishing tomorrow.
            DB::table('users')
                ->where('customer_id', $customer->id)
                ->where('is_qa', true)
                ->update(['is_wiki_approver' => true]);

            $settings[Customer::PERMISSION_APPROVE_WIKI_PAGES] = array_values(array_unique(array_map(
                static fn (string $role): string => $role === 'qa' ? Customer::ROLE_WIKI_APPROVER : $role,
                array_filter($roles, 'is_string'),
            )));

            DB::table('customers')
                ->where('id', $customer->id)
                ->update(['permission_settings' => json_encode($settings)]);
        }
    }

    /**
     * Put the grant back where it came from, so the column can be dropped without stranding anyone:
     * a customer whose page permission names wiki_approver gets 'qa' back, and their Wiki approvers
     * become QA. Users who were Wiki approvers without QA would otherwise silently lose access on a
     * rollback.
     */
    public function down(): void
    {
        foreach (DB::table('customers')->get(['id', 'permission_settings']) as $customer) {
            $settings = $this->decodeSettings($customer->permission_settings);
            $roles = $settings[Customer::PERMISSION_APPROVE_WIKI_PAGES] ?? null;

            if (! is_array($roles) || ! in_array(Customer::ROLE_WIKI_APPROVER, $roles, true)) {
                continue;
            }

            DB::table('users')
                ->where('customer_id', $customer->id)
                ->where('is_wiki_approver', true)
                ->update(['is_qa' => true]);

            $settings[Customer::PERMISSION_APPROVE_WIKI_PAGES] = array_values(array_unique(array_map(
                static fn (string $role): string => $role === Customer::ROLE_WIKI_APPROVER ? 'qa' : $role,
                array_filter($roles, 'is_string'),
            )));

            DB::table('customers')
                ->where('id', $customer->id)
                ->update(['permission_settings' => json_encode($settings)]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_wiki_approver');
        });
    }

    /** @return array<string, mixed> */
    private function decodeSettings(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
};
