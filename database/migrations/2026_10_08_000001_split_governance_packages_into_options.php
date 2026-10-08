<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The catalog moved from the ladder Basis → Styring → ISO → GRC to Basis plus independent
     * options (Risiko, Mål og KPI, Etterlevelse og revisjon, Leverandøroppfølging, Anbud).
     *
     * Every customer ends up with exactly the modules it had, as Basis and options:
     *
     *  - every customer holds Basis, which is now mandatory (an Anbud-only customer gains it);
     *  - an active `governance` row becomes Risiko + Mål og KPI;
     *  - an active `iso` row becomes Risiko + Mål og KPI + Etterlevelse og revisjon;
     *  - an active `grc` row becomes all four, with Leverandøroppfølging.
     *
     * Anbud rows are left as they are. The mapping is written out here rather than read from config,
     * so the migration means the same thing however the catalog changes later.
     *
     * An option takes the earliest activated_at of the old rows that carried it — when the customer
     * actually got that access — and Basis likewise; a customer that only gains Basis gets now.
     *
     * The old rows are revoked, not deleted, and every row this writes is marked in `metadata`, so
     * down() can put back exactly what was there. Requested or declined old rows carried no access
     * and are left alone; their keys are no longer in the catalog, so they grant nothing.
     */
    private const OPTIONS = [
        'governance' => ['risk', 'objectives'],
        'iso' => ['risk', 'objectives', 'compliance'],
        'grc' => ['risk', 'objectives', 'compliance', 'supplier'],
    ];

    private const MARK = '2026_10_08_000001';

    public function up(): void
    {
        DB::transaction(function (): void {
            $now = Carbon::now();

            foreach (DB::table('customers')->orderBy('id')->pluck('id') as $customerId) {
                $old = DB::table('customer_package_entitlements')
                    ->where('customer_id', $customerId)
                    ->where('status', 'active')
                    ->whereIn('package_key', array_keys(self::OPTIONS))
                    ->get();

                // option => earliest activation among the old rows that carried it. Timestamps are
                // compared as 'Y-m-d H:i:s' strings, which order the same way as the instants.
                $today = $now->toDateTimeString();
                $grants = ['basis' => $old->min('activated_at') ?? $today];

                foreach ($old as $row) {
                    foreach (self::OPTIONS[$row->package_key] as $option) {
                        $since = $row->activated_at ?? $today;
                        $grants[$option] = isset($grants[$option]) ? min($grants[$option], $since) : $since;
                    }
                }

                foreach ($grants as $packageKey => $activatedAt) {
                    $this->grant($customerId, $packageKey, $activatedAt, $now);
                }

                foreach ($old as $row) {
                    DB::table('customer_package_entitlements')->where('id', $row->id)->update([
                        'status' => 'revoked',
                        'deactivated_at' => $now,
                        'metadata' => $this->withMark($row->metadata, ['replaced_by_options' => true]),
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $rows = DB::table('customer_package_entitlements')
                ->whereNotNull('metadata')
                ->where('metadata', 'like', '%'.self::MARK.'%')
                ->get();

            foreach ($rows as $row) {
                $metadata = json_decode((string) $row->metadata, true) ?: [];
                $mark = $metadata[self::MARK] ?? null;

                if (! is_array($mark)) {
                    continue;
                }

                unset($metadata[self::MARK]);
                $query = DB::table('customer_package_entitlements')->where('id', $row->id);

                if (($mark['created'] ?? false) === true) {
                    $query->delete();

                    continue;
                }

                $restore = ($mark['replaced_by_options'] ?? false) === true
                    ? ['status' => 'active', 'deactivated_at' => null]
                    : ['status' => $mark['previous_status'], 'activated_at' => $mark['previous_activated_at'], 'deactivated_at' => $mark['previous_deactivated_at']];

                $query->update($restore + ['metadata' => $metadata === [] ? null : json_encode($metadata), 'updated_at' => Carbon::now()]);
            }
        });
    }

    /** An active row for the package: created, reactivated, or — when already active — left alone. */
    private function grant(int $customerId, string $packageKey, string $activatedAt, Carbon $now): void
    {
        $existing = DB::table('customer_package_entitlements')
            ->where('customer_id', $customerId)
            ->where('package_key', $packageKey)
            ->first();

        if ($existing === null) {
            DB::table('customer_package_entitlements')->insert([
                'customer_id' => $customerId,
                'package_key' => $packageKey,
                'status' => 'active',
                'activated_at' => $activatedAt,
                'metadata' => json_encode([self::MARK => ['created' => true]]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        if ($existing->status === 'active') {
            return;
        }

        DB::table('customer_package_entitlements')->where('id', $existing->id)->update([
            'status' => 'active',
            'activated_at' => $activatedAt,
            'deactivated_at' => null,
            'metadata' => $this->withMark($existing->metadata, [
                'previous_status' => $existing->status,
                'previous_activated_at' => $existing->activated_at,
                'previous_deactivated_at' => $existing->deactivated_at,
            ]),
            'updated_at' => $now,
        ]);
    }

    /** @param  array<string, mixed>  $mark */
    private function withMark(?string $metadata, array $mark): string
    {
        $decoded = json_decode((string) $metadata, true) ?: [];
        $decoded[self::MARK] = $mark;

        return json_encode($decoded);
    }
};
