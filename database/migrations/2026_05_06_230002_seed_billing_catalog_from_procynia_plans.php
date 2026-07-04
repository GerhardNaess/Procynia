<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('billing_products')->exists()) {
            return;
        }

        $plans = config('procynia_plans', []);
        $sortOrder = [
            'enterprise' => 0,
            'ultra' => 1,
            'max' => 2,
            'pro' => 3,
            'free' => 4,
        ];

        foreach ($plans as $planKey => $plan) {
            $productId = DB::table('billing_products')->insertGetId([
                'key' => "plan_{$planKey}",
                'name' => $plan['name'],
                'description' => match ($planKey) {
                    'free' => 'Gratis tilgang med grunnleggende funksjoner.',
                    'pro' => 'Plan for mindre team med utvidet arbeidsflate.',
                    'max' => 'Plan for voksende team med flere brukere.',
                    'ultra' => 'Plan for større team med prioritert kapasitet.',
                    'enterprise' => 'Manuelt håndtert enterprise-avtale.',
                    default => $plan['name'],
                },
                'category' => 'base_plan',
                'billing_scope' => 'customer',
                'is_active' => true,
                'sort_order' => $sortOrder[$planKey] ?? 99,
                'metadata' => json_encode([
                    'plan_key' => $planKey,
                    'features' => $plan['features'] ?? [],
                    'included_users' => $plan['included_users'] ?? null,
                    'included_ai_credits' => $plan['included_ai_credits'] ?? null,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($planKey === 'enterprise') {
                continue;
            }

            foreach (['monthly', 'yearly'] as $interval) {
                $priceKey = "{$planKey}_{$interval}";
                $priceAmount = $plan["{$interval}_price_nok"] ?? null;
                $stripePriceId = $this->normalizeStripePriceId($plan["stripe_{$interval}"] ?? null);

                DB::table('billing_prices')->insert([
                    'billing_product_id' => $productId,
                    'key' => $priceKey,
                    'name' => "{$plan['name']} — " . ($interval === 'monthly' ? 'Månedlig' : 'Årlig'),
                    'interval' => $interval,
                    'currency' => 'nok',
                    'unit_amount' => $priceAmount !== null ? ((int) $priceAmount * 100) : null,
                    'stripe_price_id' => $stripePriceId,
                    'tier_key' => $planKey,
                    'is_recurring' => true,
                    'is_active' => true,
                    'included_quantity' => (int) ($plan['included_users'] ?? 1),
                    'metadata' => json_encode([
                        'plan_key' => $planKey,
                        'interval' => $interval,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function normalizeStripePriceId(mixed $stripePriceId): ?string
    {
        if (! is_string($stripePriceId)) {
            return null;
        }

        $stripePriceId = trim($stripePriceId);

        if ($stripePriceId === '' || $stripePriceId === 'price_') {
            return null;
        }

        return $stripePriceId;
    }

    public function down(): void
    {
        DB::table('billing_prices')->delete();
        DB::table('billing_products')->delete();
    }
};
