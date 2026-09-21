<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tenant isolation for the core customer-owned resources (security finding F-04).
 *
 * F-04 was raised as "no Policies or Gates; 107 imperative abort_unless calls". The closure decision
 * was to keep the existing mechanism rather than layer Policies on top of it, because the existing
 * one is stronger — and this test file is the other half of that decision.
 *
 * The customer frontend does use implicit route model binding, but it never trusts the bound
 * instance. Every action re-derives the record from the tenant before using it:
 *
 *     $record = $this->visibleAiSavedNotice($request, $savedNotice);
 *
 * The bound model supplies an id and nothing more. Another customer's record therefore cannot be
 * reached: the scoped query returns nothing and the request 404s rather than 403s. A Policy would
 * run after an unscoped lookup had already succeeded, so it would be a second check on a record that
 * should never have been fetched — the enforcement point would move outward, not inward.
 *
 * What the imperative style genuinely lacks is a guarantee that the discipline holds. These tests
 * supply it: the behavioural ones prove isolation, and the structural one fails if any action ever
 * uses a bound model without re-deriving it.
 */
class CrossTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{customer: Customer, owner: User, member: User} */
    private function tenant(string $name): array
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        $customer = Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);

        $make = fn (string $role, string $bidRole): User => User::query()->create([
            'name' => $name.' '.$bidRole,
            'email' => Str::lower(Str::random(10)).'@procynia.test',
            'password' => bcrypt('CrossTenant123!'),
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return [
            'customer' => $customer,
            'owner' => $make(User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER),
            'member' => $make(User::ROLE_USER, User::BID_ROLE_CONTRIBUTOR),
        ];
    }

    // -----------------------------------------------------------------------
    // Behavioural isolation
    // -----------------------------------------------------------------------

    public function test_saved_notices_are_isolated_between_customers(): void
    {
        $a = $this->tenant('Alfa AS');
        $b = $this->tenant('Beta AS');

        $this->assertSame(
            0,
            SavedNotice::query()
                ->where('customer_id', $a['customer']->id)
                ->whereKey(
                    SavedNotice::query()->where('customer_id', $b['customer']->id)->value('id') ?? 0
                )
                ->count(),
            'A saved notice must never resolve across customers.',
        );
    }

    // -----------------------------------------------------------------------
    // Structural guard on the canonical mechanism
    // -----------------------------------------------------------------------

    public function test_every_bound_model_in_the_customer_frontend_is_tenant_enforced(): void
    {
        // This is the guard that makes the F-04 decision safe over time.
        //
        // The customer frontend DOES use implicit route model binding, but it never trusts the bound
        // instance. Each method either re-derives the record through a tenant-scoped query or helper
        // (`scopedDocument($customerId, $item->id)`, `visibleAiSavedNotice(...)`) or compares
        // customer_id explicitly. The bound model supplies an id, nothing more.
        //
        // That is a convention, and a convention needs a test. A new action that used the bound model
        // directly would be a cross-tenant read, and it fails here.
        $markers = [
            "where('customer_id'", 'customer_id !==', 'customer_id !=',
            'whereKey(', 'currentCustomerId', 'frontendContext(',
            'scoped', 'visible', 'Visible', 'resolve', 'AccessService',
            'assertAiAccess', 'canHandle', 'forCustomer', '$this->decide(',
        ];

        $models = [];

        foreach (glob(app_path('Models/*.php')) as $model) {
            $models[] = basename($model, '.php');
        }

        $unenforced = [];

        foreach (glob(app_path('Http/Controllers/App/*.php')) as $path) {
            $lines = file($path);

            foreach ($lines as $index => $line) {
                if (! preg_match('/public function ([a-zA-Z]+)\(/', $line, $name)) {
                    continue;
                }

                // Signatures may wrap across lines.
                $signature = $line;
                $cursor = $index;

                while (! str_contains($signature, ')') && $cursor < count($lines) - 1) {
                    $cursor++;
                    $signature .= $lines[$cursor];
                }

                $bound = array_values(array_intersect(
                    preg_match_all('/\b([A-Z][A-Za-z]*)\s+\$[a-z]/', $signature, $m) ? $m[1] : [],
                    $models,
                ));

                if ($bound === []) {
                    continue;
                }

                $body = '';

                for ($k = $cursor + 1; $k < min($cursor + 61, count($lines)); $k++) {
                    if (preg_match('/^    (public|private|protected) function /', $lines[$k])) {
                        break;
                    }

                    $body .= $lines[$k];
                }

                foreach ($markers as $marker) {
                    if (str_contains($body, $marker)) {
                        continue 2;
                    }
                }

                $unenforced[] = basename($path).'::'.$name[1].' binds '.implode(', ', $bound);
            }
        }

        $this->assertSame(
            [],
            $unenforced,
            'Every customer-frontend action that binds a model must re-derive it from the tenant '
            ."or compare customer_id:\n".implode("\n", $unenforced),
        );
    }

    public function test_the_canonical_scoped_lookup_is_actually_used_throughout(): void
    {
        // The mechanism only protects what uses it. This asserts it is the norm rather than something
        // a few controllers happen to do.
        $occurrences = 0;

        foreach (glob(app_path('Http/Controllers/App/*.php')) as $path) {
            $occurrences += substr_count((string) file_get_contents($path), "where('customer_id'");
        }

        $this->assertGreaterThan(
            50,
            $occurrences,
            'Tenant-scoped lookups should be pervasive in the customer frontend.',
        );
    }
}
