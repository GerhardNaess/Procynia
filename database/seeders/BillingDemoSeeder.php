<?php

namespace Database\Seeders;

use App\Models\BillingEvent;
use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Models\Customer;
use App\Models\CustomerBillingLine;
use App\Models\CustomerUserServiceLevel;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BillingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $customerContext = $this->resolveAdvaniaCustomer();
        $customer = $customerContext['customer'];

        $this->command?->info(sprintf(
            'BillingDemoSeeder: bruker kunde %d (%s) via %s.',
            $customer->id,
            $customer->name,
            $customerContext['match_source'],
        ));

        $summary = [
            'products_created' => 0,
            'products_updated' => 0,
            'prices_created' => 0,
            'prices_updated' => 0,
            'billing_lines_created' => 0,
            'billing_lines_updated' => 0,
            'service_levels_created' => 0,
            'service_levels_updated' => 0,
        ];

        DB::transaction(function () use ($customer, &$summary): void {
            $summary = array_merge($summary, $this->seedBillingCatalog());
            $summary = array_merge($summary, $this->seedAdvaniaBillingData($customer));
        });

        $this->command?->info(sprintf(
            'BillingDemoSeeder: Billing Catalog inneholder nå %d produkter og %d priser.',
            BillingProduct::query()->count(),
            BillingPrice::query()->count(),
        ));
        $this->command?->info(sprintf(
            'BillingDemoSeeder: katalogendringer - %d produkter opprettet, %d produkter oppdatert, %d priser opprettet, %d priser oppdatert.',
            $summary['products_created'],
            $summary['products_updated'],
            $summary['prices_created'],
            $summary['prices_updated'],
        ));
        $this->command?->info(sprintf(
            'BillingDemoSeeder: Advania AS fikk %d billing lines og %d service levels opprettet/oppdatert.',
            $summary['billing_lines_created'] + $summary['billing_lines_updated'],
            $summary['service_levels_created'] + $summary['service_levels_updated'],
        ));
        $this->command?->info(sprintf(
            'BillingDemoSeeder: Stripe ble bevisst hoppet over. stripe_id på kunde ble ikke endret (%s).',
            $customer->stripe_id ?? 'ingen',
        ));
    }

    private function resolveAdvaniaCustomer(): array
    {
        $customer = Customer::query()->find(2162);

        if ($customer instanceof Customer && trim((string) $customer->name) === 'Advania AS') {
            return [
                'customer' => $customer,
                'match_source' => 'id 2162',
            ];
        }

        if ($customer instanceof Customer) {
            $this->command?->warn(sprintf(
                'BillingDemoSeeder: kunde 2162 finnes, men heter "%s". Jeg søker videre på navn.',
                $customer->name,
            ));
        }

        $customerByName = Customer::query()
            ->where('name', 'Advania AS')
            ->first();

        if ($customerByName instanceof Customer) {
            return [
                'customer' => $customerByName,
                'match_source' => 'navn',
            ];
        }

        $message = 'BillingDemoSeeder: fant ikke kunde "Advania AS" via id 2162 eller navn. Seeder stoppet.';
        $this->command?->error($message);

        throw new RuntimeException($message);
    }

    private function seedBillingCatalog(): array
    {
        $productDefinitions = [
            [
                'key' => 'base_pro',
                'legacy_key' => 'plan_pro',
                'name' => 'Pro',
                'description' => 'Grunnabonnement for små tilbudsteam',
                'category' => BillingProduct::CATEGORY_BASE_PLAN,
                'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
                'sort_order' => 3,
                'metadata' => $this->planMetadata('pro'),
            ],
            [
                'key' => 'base_max',
                'legacy_key' => 'plan_max',
                'name' => 'Max',
                'description' => 'Grunnabonnement for mellomstore tilbudsteam',
                'category' => BillingProduct::CATEGORY_BASE_PLAN,
                'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
                'sort_order' => 2,
                'metadata' => $this->planMetadata('max'),
            ],
            [
                'key' => 'base_ultra',
                'legacy_key' => 'plan_ultra',
                'name' => 'Ultra',
                'description' => 'Grunnabonnement for større tilbudsteam',
                'category' => BillingProduct::CATEGORY_BASE_PLAN,
                'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
                'sort_order' => 1,
                'metadata' => $this->planMetadata('ultra'),
            ],
            [
                'key' => 'base_enterprise',
                'legacy_key' => 'plan_enterprise',
                'name' => 'Enterprise',
                'description' => 'Manuelt priset enterprise-avtale',
                'category' => BillingProduct::CATEGORY_ENTERPRISE,
                'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
                'sort_order' => 0,
                'metadata' => [
                    'demo_seeded' => true,
                    'features' => ['alt'],
                ],
            ],
            [
                'key' => 'extra_user',
                'name' => 'Ekstra bruker',
                'description' => 'Ekstra fakturerbar bruker utover inkludert antall',
                'category' => BillingProduct::CATEGORY_USER_SEAT,
                'billing_scope' => BillingProduct::BILLING_SCOPE_QUANTITY,
                'sort_order' => 4,
                'metadata' => [
                    'demo_seeded' => true,
                ],
            ],
            [
                'key' => 'ai_offer',
                'name' => 'KI-tilbud',
                'description' => 'Brukerbasert tilgang til KI-assistert tilbudsarbeid',
                'category' => BillingProduct::CATEGORY_USER_SERVICE,
                'billing_scope' => BillingProduct::BILLING_SCOPE_USER,
                'sort_order' => 5,
                'metadata' => [
                    'demo_seeded' => true,
                    'features' => ['ai_offer'],
                ],
            ],
            [
                'key' => 'market_insight',
                'name' => 'Markedsinnsikt',
                'description' => 'Brukerbasert tilgang til markedsinnsikt',
                'category' => BillingProduct::CATEGORY_USER_SERVICE,
                'billing_scope' => BillingProduct::BILLING_SCOPE_USER,
                'sort_order' => 6,
                'metadata' => [
                    'demo_seeded' => true,
                    'features' => ['market_insight'],
                ],
            ],
            [
                'key' => 'flowcase_integration',
                'name' => 'Flowcase-integrasjon',
                'description' => 'Løpende tillegg for Flowcase-integrasjon',
                'category' => BillingProduct::CATEGORY_ADDON,
                'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
                'sort_order' => 7,
                'metadata' => [
                    'demo_seeded' => true,
                    'features' => ['flowcase'],
                ],
            ],
            [
                'key' => 'onboarding',
                'name' => 'Personlig oppstartsmøte',
                'description' => 'Engangstjeneste for oppstart og innføring',
                'category' => BillingProduct::CATEGORY_ONE_OFF,
                'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
                'sort_order' => 8,
                'metadata' => [
                    'demo_seeded' => true,
                    'features' => ['oppstartsmoete'],
                ],
            ],
            [
                'key' => 'data_setup',
                'name' => 'Datavask og oppsett',
                'description' => 'Engangstjeneste for strukturering og oppsett av kundedata',
                'category' => BillingProduct::CATEGORY_ONE_OFF,
                'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
                'sort_order' => 9,
                'metadata' => [
                    'demo_seeded' => true,
                    'features' => ['data_setup'],
                ],
            ],
        ];

        $products = [];
        $created = 0;
        $updated = 0;

        foreach ($productDefinitions as $definition) {
            [$product, $wasCreated] = $this->upsertProduct($definition);
            $products[$definition['key']] = $product;
            $wasCreated ? $created++ : $updated++;
        }

        $priceDefinitions = [
            [
                'product_key' => 'base_pro',
                'key' => 'pro_monthly',
                'name' => 'Pro månedlig',
                'interval' => BillingPrice::INTERVAL_MONTHLY,
                'currency' => 'nok',
                'unit_amount' => 99000,
                'tier_key' => 'pro',
                'is_recurring' => true,
                'included_quantity' => 1,
                'stripe_price_id' => $this->planStripePriceId('pro', BillingPrice::INTERVAL_MONTHLY),
                'metadata' => $this->priceMetadata('pro', BillingPrice::INTERVAL_MONTHLY),
            ],
            [
                'product_key' => 'base_pro',
                'key' => 'pro_yearly',
                'name' => 'Pro årlig',
                'interval' => BillingPrice::INTERVAL_YEARLY,
                'currency' => 'nok',
                'unit_amount' => 792000,
                'tier_key' => 'pro',
                'is_recurring' => true,
                'included_quantity' => 1,
                'stripe_price_id' => $this->planStripePriceId('pro', BillingPrice::INTERVAL_YEARLY),
                'metadata' => $this->priceMetadata('pro', BillingPrice::INTERVAL_YEARLY),
            ],
            [
                'product_key' => 'base_max',
                'key' => 'max_monthly',
                'name' => 'Max månedlig',
                'interval' => BillingPrice::INTERVAL_MONTHLY,
                'currency' => 'nok',
                'unit_amount' => 299000,
                'tier_key' => 'max',
                'is_recurring' => true,
                'included_quantity' => 5,
                'stripe_price_id' => $this->planStripePriceId('max', BillingPrice::INTERVAL_MONTHLY),
                'metadata' => $this->priceMetadata('max', BillingPrice::INTERVAL_MONTHLY),
            ],
            [
                'product_key' => 'base_max',
                'key' => 'max_yearly',
                'name' => 'Max årlig',
                'interval' => BillingPrice::INTERVAL_YEARLY,
                'currency' => 'nok',
                'unit_amount' => 2392000,
                'tier_key' => 'max',
                'is_recurring' => true,
                'included_quantity' => 5,
                'stripe_price_id' => $this->planStripePriceId('max', BillingPrice::INTERVAL_YEARLY),
                'metadata' => $this->priceMetadata('max', BillingPrice::INTERVAL_YEARLY),
            ],
            [
                'product_key' => 'base_ultra',
                'key' => 'ultra_monthly',
                'name' => 'Ultra månedlig',
                'interval' => BillingPrice::INTERVAL_MONTHLY,
                'currency' => 'nok',
                'unit_amount' => 649000,
                'tier_key' => 'ultra',
                'is_recurring' => true,
                'included_quantity' => 15,
                'stripe_price_id' => $this->planStripePriceId('ultra', BillingPrice::INTERVAL_MONTHLY),
                'metadata' => $this->priceMetadata('ultra', BillingPrice::INTERVAL_MONTHLY),
            ],
            [
                'product_key' => 'base_ultra',
                'key' => 'ultra_yearly',
                'name' => 'Ultra årlig',
                'interval' => BillingPrice::INTERVAL_YEARLY,
                'currency' => 'nok',
                'unit_amount' => 5192000,
                'tier_key' => 'ultra',
                'is_recurring' => true,
                'included_quantity' => 15,
                'stripe_price_id' => $this->planStripePriceId('ultra', BillingPrice::INTERVAL_YEARLY),
                'metadata' => $this->priceMetadata('ultra', BillingPrice::INTERVAL_YEARLY),
            ],
            [
                'product_key' => 'extra_user',
                'key' => 'extra_user_monthly',
                'name' => 'Ekstra bruker månedlig',
                'interval' => BillingPrice::INTERVAL_MONTHLY,
                'currency' => 'nok',
                'unit_amount' => 39000,
                'tier_key' => 'standard',
                'is_recurring' => true,
                'included_quantity' => 0,
                'stripe_price_id' => null,
                'metadata' => [
                    'demo_seeded' => true,
                    'category' => 'quantity',
                ],
            ],
            [
                'product_key' => 'ai_offer',
                'key' => 'ai_offer_pro_monthly',
                'name' => 'KI-tilbud Pro månedlig',
                'interval' => BillingPrice::INTERVAL_MONTHLY,
                'currency' => 'nok',
                'unit_amount' => 49000,
                'tier_key' => 'pro',
                'is_recurring' => true,
                'included_quantity' => 0,
                'stripe_price_id' => null,
                'metadata' => [
                    'demo_seeded' => true,
                    'features' => ['ai_offer'],
                ],
            ],
            [
                'product_key' => 'ai_offer',
                'key' => 'ai_offer_max_monthly',
                'name' => 'KI-tilbud Max månedlig',
                'interval' => BillingPrice::INTERVAL_MONTHLY,
                'currency' => 'nok',
                'unit_amount' => 99000,
                'tier_key' => 'max',
                'is_recurring' => true,
                'included_quantity' => 0,
                'stripe_price_id' => null,
                'metadata' => [
                    'demo_seeded' => true,
                    'features' => ['ai_offer'],
                ],
            ],
            [
                'product_key' => 'market_insight',
                'key' => 'market_insight_monthly',
                'name' => 'Markedsinnsikt månedlig',
                'interval' => BillingPrice::INTERVAL_MONTHLY,
                'currency' => 'nok',
                'unit_amount' => 149000,
                'tier_key' => 'standard',
                'is_recurring' => true,
                'included_quantity' => 0,
                'stripe_price_id' => null,
                'metadata' => [
                    'demo_seeded' => true,
                    'features' => ['market_insight'],
                ],
            ],
            [
                'product_key' => 'flowcase_integration',
                'key' => 'flowcase_integration_monthly',
                'name' => 'Flowcase-integrasjon månedlig',
                'interval' => BillingPrice::INTERVAL_MONTHLY,
                'currency' => 'nok',
                'unit_amount' => 99000,
                'tier_key' => 'standard',
                'is_recurring' => true,
                'included_quantity' => 0,
                'stripe_price_id' => null,
                'metadata' => [
                    'demo_seeded' => true,
                    'features' => ['flowcase'],
                ],
            ],
            [
                'product_key' => 'onboarding',
                'key' => 'onboarding_one_time',
                'name' => 'Personlig oppstartsmøte',
                'interval' => BillingPrice::INTERVAL_ONE_TIME,
                'currency' => 'nok',
                'unit_amount' => 990000,
                'tier_key' => 'standard',
                'is_recurring' => false,
                'included_quantity' => 0,
                'stripe_price_id' => null,
                'metadata' => [
                    'demo_seeded' => true,
                    'features' => ['oppstartsmoete'],
                ],
            ],
            [
                'product_key' => 'data_setup',
                'key' => 'data_setup_one_time',
                'name' => 'Datavask og oppsett',
                'interval' => BillingPrice::INTERVAL_ONE_TIME,
                'currency' => 'nok',
                'unit_amount' => 1490000,
                'tier_key' => 'standard',
                'is_recurring' => false,
                'included_quantity' => 0,
                'stripe_price_id' => null,
                'metadata' => [
                    'demo_seeded' => true,
                    'features' => ['data_setup'],
                ],
            ],
        ];

        $prices = [];
        $priceCreated = 0;
        $priceUpdated = 0;

        foreach ($priceDefinitions as $definition) {
            [$price, $wasCreated] = $this->upsertPrice($definition, $products[$definition['product_key']]);
            $prices[$definition['key']] = $price;
            $wasCreated ? $priceCreated++ : $priceUpdated++;
        }

        $this->upsertDemoEvent(
            'billing_demo_catalog_seeded',
            null,
            'manual',
            'Billing Catalog ble seeded for demo.',
            null,
            [
                'products_total' => BillingProduct::query()->count(),
                'prices_total' => BillingPrice::query()->count(),
                'products_created' => $created,
                'products_updated' => $updated,
                'prices_created' => $priceCreated,
                'prices_updated' => $priceUpdated,
            ],
        );

        return [
            'products_created' => $created,
            'products_updated' => $updated,
            'prices_created' => $priceCreated,
            'prices_updated' => $priceUpdated,
        ];
    }

    private function seedAdvaniaBillingData(Customer $customer): array
    {
        $customer->forceFill([
            'subscription_plan' => Customer::PLAN_MAX,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'included_users' => 5,
            'included_ai_credits' => 20,
        ])->save();

        $lineCreated = 0;
        $lineUpdated = 0;
        $levelCreated = 0;
        $levelUpdated = 0;

        $maxMonthly = $this->billingPriceOrFail('max_monthly');
        $flowcaseMonthly = $this->billingPriceOrFail('flowcase_integration_monthly');
        $extraUserMonthly = $this->billingPriceOrFail('extra_user_monthly');
        $onboardingOneTime = $this->billingPriceOrFail('onboarding_one_time');
        $dataSetupOneTime = $this->billingPriceOrFail('data_setup_one_time');
        $aiOfferProMonthly = $this->billingPriceOrFail('ai_offer_pro_monthly');
        $aiOfferMaxMonthly = $this->billingPriceOrFail('ai_offer_max_monthly');
        $marketInsightMonthly = $this->billingPriceOrFail('market_insight_monthly');

        [$planLine, $wasCreated] = $this->upsertBillingLine(
            $customer,
            $maxMonthly,
            [
                'billing_product_id' => $maxMonthly->billing_product_id,
                'description' => 'Max månedlig abonnement',
                'quantity' => 1,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'stripe_subscription_id' => null,
                'stripe_subscription_item_id' => null,
                'stripe_invoice_id' => null,
                'source' => 'manual',
                'metadata' => $this->demoLineMetadata('base_plan:max_monthly'),
            ],
            [
                'customer_id' => $customer->id,
                'billing_price_id' => $maxMonthly->id,
                'user_id' => null,
                'source' => 'manual',
            ],
        );
        $wasCreated ? $lineCreated++ : $lineUpdated++;

        [$flowcaseLine, $wasCreated] = $this->upsertBillingLine(
            $customer,
            $flowcaseMonthly,
            [
                'billing_product_id' => $flowcaseMonthly->billing_product_id,
                'description' => 'Flowcase-integrasjon',
                'quantity' => 1,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'stripe_subscription_id' => null,
                'stripe_subscription_item_id' => null,
                'stripe_invoice_id' => null,
                'source' => 'manual',
                'metadata' => $this->demoLineMetadata('addon:flowcase_integration'),
            ],
            [
                'customer_id' => $customer->id,
                'billing_price_id' => $flowcaseMonthly->id,
                'user_id' => null,
                'source' => 'manual',
            ],
        );
        $wasCreated ? $lineCreated++ : $lineUpdated++;

        $activeUsers = $customer->users()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $includedUsers = max(0, (int) $customer->included_users);
        $extraUsers = max(0, $activeUsers->count() - $includedUsers);

        if ($extraUsers > 0) {
            [, $wasCreated] = $this->upsertBillingLine(
                $customer,
                $extraUserMonthly,
                [
                    'billing_product_id' => $extraUserMonthly->billing_product_id,
                    'description' => 'Ekstra bruker utover inkludert antall',
                    'quantity' => $extraUsers,
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => null,
                    'stripe_subscription_id' => null,
                    'stripe_subscription_item_id' => null,
                    'stripe_invoice_id' => null,
                    'source' => 'manual',
                    'metadata' => $this->demoLineMetadata('seat:extra_user'),
                ],
                [
                    'customer_id' => $customer->id,
                    'billing_price_id' => $extraUserMonthly->id,
                    'user_id' => null,
                    'source' => 'manual',
                ],
            );
            $wasCreated ? $lineCreated++ : $lineUpdated++;
        }

        if ($activeUsers->isNotEmpty()) {
            $assignedBy = $this->billingDemoAssignedByUser($customer);
            $firstUser = $activeUsers->first();
            $secondUser = $activeUsers->get(1);

            foreach ([
                [
                    'user' => $firstUser,
                    'price' => $aiOfferMaxMonthly,
                    'level_key' => 'ai_offer_max',
                ],
                [
                    'user' => $firstUser,
                    'price' => $marketInsightMonthly,
                    'level_key' => 'market_insight',
                ],
            ] as $assignment) {
                if (! $assignment['user'] instanceof User) {
                    continue;
                }

                [, $wasCreated] = $this->upsertServiceLevel(
                    $customer,
                    $assignment['user'],
                    $assignment['price'],
                    $assignment['level_key'],
                    $assignedBy,
                );
                $wasCreated ? $levelCreated++ : $levelUpdated++;

                [, $lineWasCreated] = $this->upsertBillingLine(
                    $customer,
                    $assignment['price'],
                    [
                        'billing_product_id' => $assignment['price']->billing_product_id,
                        'user_id' => $assignment['user']->id,
                        'description' => $assignment['price']->name,
                        'quantity' => 1,
                        'status' => 'active',
                        'starts_at' => now(),
                        'ends_at' => null,
                        'stripe_subscription_id' => null,
                        'stripe_subscription_item_id' => null,
                        'stripe_invoice_id' => null,
                        'source' => 'manual',
                        'metadata' => $this->demoLineMetadata('service_level:'.$assignment['level_key']),
                    ],
                    [
                        'customer_id' => $customer->id,
                        'billing_price_id' => $assignment['price']->id,
                        'user_id' => $assignment['user']->id,
                        'source' => 'manual',
                    ],
                );
                $lineWasCreated ? $lineCreated++ : $lineUpdated++;
            }

            if ($secondUser instanceof User) {
                [, $wasCreated] = $this->upsertServiceLevel(
                    $customer,
                    $secondUser,
                    $aiOfferProMonthly,
                    'ai_offer_pro',
                    $assignedBy,
                );
                $wasCreated ? $levelCreated++ : $levelUpdated++;

                [, $lineWasCreated] = $this->upsertBillingLine(
                    $customer,
                    $aiOfferProMonthly,
                    [
                        'billing_product_id' => $aiOfferProMonthly->billing_product_id,
                        'user_id' => $secondUser->id,
                        'description' => $aiOfferProMonthly->name,
                        'quantity' => 1,
                        'status' => 'active',
                        'starts_at' => now(),
                        'ends_at' => null,
                        'stripe_subscription_id' => null,
                        'stripe_subscription_item_id' => null,
                        'stripe_invoice_id' => null,
                        'source' => 'manual',
                        'metadata' => $this->demoLineMetadata('service_level:ai_offer_pro'),
                    ],
                    [
                        'customer_id' => $customer->id,
                        'billing_price_id' => $aiOfferProMonthly->id,
                        'user_id' => $secondUser->id,
                        'source' => 'manual',
                    ],
                );
                $lineWasCreated ? $lineCreated++ : $lineUpdated++;
            }
        } else {
            $this->command?->warn('BillingDemoSeeder: ingen aktive brukere funnet for Advania AS. Service levels ble ikke seeded.');
        }

        [$onboardingLine, $wasCreated] = $this->upsertBillingLine(
            $customer,
            $onboardingOneTime,
            [
                'billing_product_id' => $onboardingOneTime->billing_product_id,
                'description' => 'Personlig oppstartsmøte',
                'quantity' => 1,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'stripe_subscription_id' => null,
                'stripe_subscription_item_id' => null,
                'stripe_invoice_id' => null,
                'source' => 'manual',
                'metadata' => $this->demoLineMetadata('one_time:onboarding'),
            ],
            [
                'customer_id' => $customer->id,
                'billing_price_id' => $onboardingOneTime->id,
                'user_id' => null,
                'source' => 'manual',
            ],
        );
        $wasCreated ? $lineCreated++ : $lineUpdated++;

        [$dataSetupLine, $wasCreated] = $this->upsertBillingLine(
            $customer,
            $dataSetupOneTime,
            [
                'billing_product_id' => $dataSetupOneTime->billing_product_id,
                'description' => 'Datavask og oppsett',
                'quantity' => 1,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'stripe_subscription_id' => null,
                'stripe_subscription_item_id' => null,
                'stripe_invoice_id' => null,
                'source' => 'manual',
                'metadata' => $this->demoLineMetadata('one_time:data_setup'),
            ],
            [
                'customer_id' => $customer->id,
                'billing_price_id' => $dataSetupOneTime->id,
                'user_id' => null,
                'source' => 'manual',
            ],
        );
        $wasCreated ? $lineCreated++ : $lineUpdated++;

        $this->upsertDemoEvent(
            'billing_demo_customer_billing_lines_seeded',
            $customer->id,
            'manual',
            'Demo billing lines ble seeded for Advania AS.',
            null,
            [
                'billing_lines_total' => $customer->billingLines()->count(),
                'line_ids' => [
                    $planLine->id,
                    $flowcaseLine->id,
                    $onboardingLine->id,
                    $dataSetupLine->id,
                ],
                'created' => $lineCreated,
                'updated' => $lineUpdated,
            ],
        );

        $this->upsertDemoEvent(
            'billing_demo_user_service_levels_seeded',
            $customer->id,
            'manual',
            'Demo service levels ble seeded for Advania AS.',
            null,
            [
                'service_levels_total' => $customer->userServiceLevels()->count(),
                'created' => $levelCreated,
                'updated' => $levelUpdated,
            ],
        );

        return [
            'billing_lines_created' => $lineCreated,
            'billing_lines_updated' => $lineUpdated,
            'service_levels_created' => $levelCreated,
            'service_levels_updated' => $levelUpdated,
        ];
    }

    private function upsertProduct(array $definition): array
    {
        $attributes = [
            'key' => $definition['key'],
            'name' => $definition['name'],
            'description' => $definition['description'],
            'category' => $definition['category'],
            'billing_scope' => $definition['billing_scope'],
            'is_active' => true,
            'sort_order' => $definition['sort_order'] ?? 0,
            'metadata' => $definition['metadata'] ?? [],
        ];

        $product = BillingProduct::query()
            ->where('key', $definition['key'])
            ->first();

        if (! $product && isset($definition['legacy_key'])) {
            $product = BillingProduct::query()
                ->where('key', $definition['legacy_key'])
                ->first();
        }

        if ($product) {
            $product->fill($attributes);
            $product->save();

            return [$product->fresh(), false];
        }

        return [BillingProduct::query()->create($attributes), true];
    }

    private function upsertPrice(array $definition, BillingProduct $product): array
    {
        $attributes = [
            'billing_product_id' => $product->id,
            'key' => $definition['key'],
            'name' => $definition['name'],
            'interval' => $definition['interval'],
            'currency' => $definition['currency'] ?? 'nok',
            'unit_amount' => $definition['unit_amount'] ?? null,
            'stripe_price_id' => $definition['stripe_price_id'] ?? null,
            'tier_key' => $definition['tier_key'] ?? null,
            'is_recurring' => $definition['is_recurring'] ?? true,
            'is_active' => true,
            'included_quantity' => $definition['included_quantity'] ?? 0,
            'metadata' => $definition['metadata'] ?? [],
        ];

        $price = BillingPrice::query()
            ->where('key', $definition['key'])
            ->first();

        if ($price) {
            $price->fill($attributes);
            $price->save();

            return [$price->fresh(), false];
        }

        return [BillingPrice::query()->create($attributes), true];
    }

    private function upsertBillingLine(Customer $customer, BillingPrice $price, array $attributes, array $key): array
    {
        $line = CustomerBillingLine::query()->updateOrCreate($key, $attributes);

        return [$line->fresh(), $line->wasRecentlyCreated];
    }

    private function upsertServiceLevel(Customer $customer, User $user, BillingPrice $price, string $levelKey, ?User $assignedBy): array
    {
        $level = CustomerUserServiceLevel::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'billing_price_id' => $price->id,
                'level_key' => $levelKey,
            ],
            [
                'billing_product_id' => $price->billing_product_id,
                'status' => 'active',
                'assigned_by' => $assignedBy?->id,
                'starts_at' => now(),
                'ends_at' => null,
                'metadata' => $this->demoLineMetadata('service_level:'.$levelKey),
            ],
        );

        return [$level->fresh(), $level->wasRecentlyCreated];
    }

    private function upsertDemoEvent(string $eventType, ?int $customerId, string $source, string $description, ?array $before, ?array $after): void
    {
        BillingEvent::query()->updateOrCreate(
            [
                'stripe_event_id' => $eventType,
            ],
            [
                'customer_id' => $customerId,
                'user_id' => null,
                'event_type' => $eventType,
                'source' => $source,
                'description' => $description,
                'before' => $before,
                'after' => $after,
            ],
        );
    }

    private function billingPriceOrFail(string $priceKey): BillingPrice
    {
        $price = BillingPrice::query()
            ->with('product')
            ->where('key', $priceKey)
            ->first();

        if (! $price instanceof BillingPrice) {
            throw new RuntimeException("BillingDemoSeeder: mangler pris '{$priceKey}'.");
        }

        return $price;
    }

    private function planStripePriceId(string $planKey, string $interval): ?string
    {
        return config("procynia_plans.{$planKey}." . ($interval === BillingPrice::INTERVAL_YEARLY ? 'stripe_yearly' : 'stripe_monthly')) ?: null;
    }

    private function planMetadata(string $planKey): array
    {
        $features = (array) config("procynia_plans.{$planKey}.features", []);

        return [
            'demo_seeded' => true,
            'plan_key' => $planKey,
            'features' => $features,
            'included_users' => config("procynia_plans.{$planKey}.included_users"),
            'included_ai_credits' => config("procynia_plans.{$planKey}.included_ai_credits"),
        ];
    }

    private function priceMetadata(string $planKey, string $interval): array
    {
        $metadata = [
            'demo_seeded' => true,
            'plan_key' => $planKey,
            'interval' => $interval,
        ];

        $features = (array) config("procynia_plans.{$planKey}.features", []);

        if ($features !== []) {
            $metadata['features'] = $features;
        }

        return $metadata;
    }

    private function demoLineMetadata(string $seedKey): array
    {
        return [
            'demo_seeded' => true,
            'seed_key' => $seedKey,
        ];
    }

    private function billingDemoAssignedByUser(Customer $customer): ?User
    {
        return $customer->users()
            ->where('is_active', true)
            ->where('role', User::ROLE_CUSTOMER_ADMIN)
            ->orderBy('id')
            ->first()
            ?? $customer->users()
                ->where('is_active', true)
                ->orderBy('id')
                ->first();
    }
}
