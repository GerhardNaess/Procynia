<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The package catalog moved from Kvalitet/GRC to the ladder Basis → Styring → ISO → GRC.
     *
     * `quality` no longer exists, and `grc` now means the top of the ladder rather than the old
     * compound package. Each old row is moved to the lowest new step that still carries every
     * module it used to give, so no customer loses anything:
     *
     *  - `grc` (Kvalitet, Risiko, Mål og KPI, Avvik og forbedringer, Etterlevelse og revisjon) →
     *    `iso`, which is exactly that.
     *  - `quality` (Kvalitet, Mål og KPI, Avvik og forbedringer) → `governance`, the lowest step
     *    that carries Mål og KPI.
     *
     * The old `grc` rows are moved first. A row is dropped instead of moved when the customer
     * already holds its target or a step above it, so a customer that held both ends up on `iso`
     * alone and no row is duplicated (the table is unique on customer + package). The mandatory
     * `core` package never had rows.
     *
     * from => [to, packages that already cover it]
     */
    private const MOVES = [
        'grc' => ['iso', ['iso']],
        'quality' => ['governance', ['governance', 'iso']],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::MOVES as $from => [$to, $coveredBy]) {
                $rows = DB::table('customer_package_entitlements')->where('package_key', $from)->get();

                foreach ($rows as $row) {
                    $taken = DB::table('customer_package_entitlements')
                        ->where('customer_id', $row->customer_id)
                        ->whereIn('package_key', $coveredBy)
                        ->exists();

                    $query = DB::table('customer_package_entitlements')->where('id', $row->id);

                    $taken ? $query->delete() : $query->update(['package_key' => $to, 'updated_at' => now()]);
                }
            }
        });
    }

    /**
     * Not reversible: after the move a `governance` or `iso` row from the old catalog cannot be told
     * apart from one ordered under the new one.
     */
    public function down(): void {}
};
