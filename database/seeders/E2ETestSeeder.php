<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds stable test users for Playwright E2E tests.
 *
 * All users use non-real .procynia.test emails that cannot belong to real accounts.
 * No external API keys, no Stripe data, no OpenAI credentials are created.
 * Safe to run repeatedly — all upserts are idempotent.
 *
 * Known credentials (used in tests/e2e/helpers/auth.js):
 *   Super admin:   e2e.superadmin@procynia.test  /  E2eAdmin123!
 *   System owner:  e2e.systemowner@procynia.test  /  E2eUser123!
 *   Regular user:  e2e.user@procynia.test          /  E2eUser123!
 */
class E2ETestSeeder extends Seeder
{
    private const SUPER_ADMIN_EMAIL = 'e2e.superadmin@procynia.test';

    private const SUPER_ADMIN_PASSWORD = 'E2eAdmin123!';

    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    private const USER_EMAIL = 'e2e.user@procynia.test';

    private const E2E_PASSWORD = 'E2eUser123!';

    private const CUSTOMER_SLUG = 'e2e-test-customer';

    /**
     * Two process flows whose only job is to be different sizes.
     *
     * The Flyt tab's zoom controls only mean anything relative to the surface the diagram is given,
     * so the one case that has to exist is a flow that cannot fit: a two-node process and a
     * fourteen-step one across six lanes put fit-to-view on both sides of the line. See
     * tests/e2e/quality-flow-zoom.spec.js.
     */
    private const SMALL_FLOW_CODE = 'E2E-FLOW-S';

    private const LARGE_FLOW_CODE = 'E2E-FLOW-L';

    /**
     * A process that has no flow at all, which is a case of its own: it is the only state in which
     * the tab has nothing to show but the invitation to describe the process, and the state that
     * once told the user to press a button that had been removed. See
     * tests/e2e/quality-flow-describe-without-flow.spec.js.
     */
    private const NO_FLOW_CODE = 'E2E-FLOW-0';

    /**
     * A process whose middle step stands for another process — the small flow above.
     *
     * Drill-down cannot be tested on one process: it needs a real reference between two of them,
     * because what is being checked is that the parent reads the child's own stored blueprint
     * rather than a copy of it. See tests/e2e/quality-flow-subprocess.spec.js.
     */
    private const PARENT_FLOW_CODE = 'E2E-FLOW-P';

    public function run(): void
    {
        // Internal super admin — no customer, full Filament access
        User::query()->updateOrCreate(
            ['email' => self::SUPER_ADMIN_EMAIL],
            [
                'name' => 'E2E Super Admin',
                'password' => Hash::make(self::SUPER_ADMIN_PASSWORD),
                'role' => User::ROLE_SUPER_ADMIN,
                'customer_id' => null,
                'is_active' => true,
            ],
        );

        // Minimal test customer (language + nationality required by the Customer schema)
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => '🇳🇴'],
        );

        $customer = Customer::query()->updateOrCreate(
            ['slug' => self::CUSTOMER_SLUG],
            [
                'name' => 'E2E Test Customer',
                'language_id' => $language->id,
                'nationality_id' => $nationality->id,
                'is_active' => true,
                // A plan with AI credits on it, because AiCostControlService refuses a customer
                // whose quota policy resolves to NONE before any request is built — which it does
                // for a customer with no plan at all. Without this, every spec that exercises a
                // real AI feature gets "Abonnementet inkluderer ikke denne AI-funksjonen" instead
                // of the feature, and the only ones that can run are the ones that stub the
                // provider out. Nothing here charges anything: Stripe is not in this environment.
                'subscription_plan' => Customer::PLAN_PRO,
                'billing_interval' => Customer::BILLING_MONTHLY,
                'included_ai_credits' => 20,
            ],
        );

        // System owner — bid_role=system_owner gives access to /app/billing
        $systemOwner = User::query()->updateOrCreate(
            ['email' => self::SYSTEM_OWNER_EMAIL],
            [
                'name' => 'E2E System Owner',
                'password' => Hash::make(self::E2E_PASSWORD),
                'role' => User::ROLE_CUSTOMER_ADMIN,
                'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
                'customer_id' => $customer->id,
                'is_active' => true,
            ],
        );

        // Regular contributor — standard customer user without elevated permissions
        User::query()->updateOrCreate(
            ['email' => self::USER_EMAIL],
            [
                'name' => 'E2E User',
                'password' => Hash::make(self::E2E_PASSWORD),
                'role' => User::ROLE_USER,
                'bid_role' => User::BID_ROLE_CONTRIBUTOR,
                'customer_id' => $customer->id,
                'is_active' => true,
            ],
        );

        $this->seedQualityProcessFlows($customer, $systemOwner);
    }

    /**
     * A small and a large process, each with a saved flow, and one with none.
     *
     * Entitlement comes first: Kvalitet is an orderable module, so without the package the routes
     * redirect to Hjem and every quality spec would skip rather than fail — which is the worst of
     * both, a green run that tested nothing.
     */
    private function seedQualityProcessFlows(Customer $customer, User $owner): void
    {
        app(ModuleEntitlementService::class)->activatePackage($customer, 'quality', $owner);

        $flows = [
            [self::SMALL_FLOW_CODE, 'E2E liten prosess', $this->smallFlowPayload()],
            [self::LARGE_FLOW_CODE, 'E2E stor prosess', $this->largeFlowPayload()],
        ];

        QualityItem::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'code' => self::NO_FLOW_CODE],
            [
                'quality_type' => QualityItem::TYPE_PROCESS,
                // Deliberately without the word "flyt" in it: the specs reach the tab with
                // getByRole('link', { name: 'Flyt' }), which matches on a substring, and a row in
                // the Kvalitet list carrying that word is picked up as the tab.
                'title' => 'E2E prosess uten struktur',
                'purpose' => 'Fast testprosess for E2E, uten lagret flyt.',
                'status' => QualityItem::STATUS_DRAFT,
                'created_by_user_id' => $owner->id,
            ],
        );

        foreach ($flows as [$code, $title, $payload]) {
            $item = QualityItem::query()->updateOrCreate(
                ['customer_id' => $customer->id, 'code' => $code],
                [
                    'quality_type' => QualityItem::TYPE_PROCESS,
                    'title' => $title,
                    'purpose' => 'Fast testprosess for E2E.',
                    'status' => QualityItem::STATUS_DRAFT,
                    'created_by_user_id' => $owner->id,
                ],
            );

            QualityProcessBlueprint::query()->updateOrCreate(
                ['quality_item_id' => $item->id],
                [
                    'customer_id' => $customer->id,
                    'payload' => $payload,
                    'status' => QualityProcessBlueprint::STATUS_DRAFT,
                    'source' => QualityProcessBlueprint::SOURCE_MANUAL,
                    'generated_at' => now(),
                    'generated_by_user_id' => $owner->id,
                ],
            );
        }

        $this->seedParentProcess($customer, $owner);
    }

    /**
     * The drill-down case: a process with a step that is itself a process.
     *
     * Written after the loop because the reference is the small process's id, which only exists
     * once it has been created — which is the point of the whole feature: the parent holds an id,
     * not a copy, so there is nothing here that has to be kept in step with the small flow.
     */
    private function seedParentProcess(Customer $customer, User $owner): void
    {
        $child = QualityItem::query()
            ->where('customer_id', $customer->id)
            ->where('code', self::SMALL_FLOW_CODE)
            ->sole();

        $parent = QualityItem::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'code' => self::PARENT_FLOW_CODE],
            [
                'quality_type' => QualityItem::TYPE_PROCESS,
                'title' => 'E2E hovedprosess',
                'purpose' => 'Fast testprosess for E2E, med et steg som peker på en annen prosess.',
                'status' => QualityItem::STATUS_DRAFT,
                'created_by_user_id' => $owner->id,
            ],
        );

        QualityProcessBlueprint::query()->updateOrCreate(
            ['quality_item_id' => $parent->id],
            [
                'customer_id' => $customer->id,
                'payload' => [
                    'lanes' => [['key' => 'innkjoper', 'label' => 'Innkjøper']],
                    'nodes' => [
                        ['key' => 'start', 'lane' => 'innkjoper', 'type' => 'start', 'label' => 'Behov meldes', 'description' => null, 'subprocess_quality_item_id' => null],
                        ['key' => 'vurder', 'lane' => 'innkjoper', 'type' => 'step', 'label' => 'Vurder leverandøren', 'description' => null, 'subprocess_quality_item_id' => (int) $child->id],
                        ['key' => 'slutt', 'lane' => 'innkjoper', 'type' => 'end', 'label' => 'Bestillingen er sendt', 'description' => null, 'subprocess_quality_item_id' => null],
                    ],
                    'edges' => [
                        ['from' => 'start', 'to' => 'vurder', 'label' => null],
                        ['from' => 'vurder', 'to' => 'slutt', 'label' => null],
                    ],
                ],
                'status' => QualityProcessBlueprint::STATUS_DRAFT,
                'source' => QualityProcessBlueprint::SOURCE_MANUAL,
                'generated_at' => now(),
                'generated_by_user_id' => $owner->id,
            ],
        );
    }

    /** Two nodes in one lane: smaller than the diagram surface on any screen. */
    private function smallFlowPayload(): array
    {
        return [
            'lanes' => [['key' => 'saksbehandler', 'label' => 'Saksbehandler']],
            'nodes' => [
                ['key' => 'start', 'lane' => 'saksbehandler', 'type' => 'start', 'label' => 'Avvik meldes'],
                ['key' => 'slutt', 'lane' => 'saksbehandler', 'type' => 'end', 'label' => 'Avviket er lukket'],
            ],
            'edges' => [['from' => 'start', 'to' => 'slutt', 'label' => null]],
        ];
    }

    /** Fourteen steps across six lanes: several times wider than the surface. */
    private function largeFlowPayload(): array
    {
        $lanes = [];
        $nodes = [];
        $edges = [];

        for ($index = 0; $index < 6; $index++) {
            $lanes[] = ['key' => "lane-{$index}", 'label' => 'Rolle nummer '.($index + 1)];
        }

        for ($index = 0; $index < 14; $index++) {
            $nodes[] = [
                'key' => "node-{$index}",
                'lane' => 'lane-'.($index % 6),
                'type' => match ($index) {
                    0 => 'start',
                    13 => 'end',
                    default => 'step',
                },
                'label' => 'Steg '.($index + 1).' i en lang prosess',
            ];

            if ($index > 0) {
                $edges[] = ['from' => 'node-'.($index - 1), 'to' => "node-{$index}", 'label' => null];
            }
        }

        return ['lanes' => $lanes, 'nodes' => $nodes, 'edges' => $edges];
    }
}
