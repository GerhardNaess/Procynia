<?php

namespace Tests\Feature\App;

use App\Exceptions\HumanDecisionRefused;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\QualityProcessRevision;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Quality\QualityItemService;
use App\Services\Quality\QualityProcessBlueprintService;
use App\Services\Security\ActorChannel;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The human decisions in Kvalitet — approving a process flow, adopting a flow the AI proposed, and
 * deleting (a flow or a whole item) — are refused in the services themselves, phase 0 step 12
 * (docs/external-api-phase-0-plan.md §2f).
 *
 * An integration, any request through the API (delegated mode included), an inactive person, a
 * person without the key, a person in another customer and a customer without the Kvalitet module
 * are turned away there, whatever the entry point, and nothing is written. Ordinary editing of a
 * flow stays open to whoever holds quality.edit, a machine included — it is the adoption and the
 * approval that are a person's.
 */
class QualityHumanDecisionTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const EVERYTHING = [
        CustomerPermissionCatalog::QUALITY_VIEW,
        CustomerPermissionCatalog::QUALITY_CREATE,
        CustomerPermissionCatalog::QUALITY_EDIT,
        CustomerPermissionCatalog::QUALITY_APPROVE,
        CustomerPermissionCatalog::QUALITY_DELETE,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    /** A person with the keys takes every decision, and the record names them. */
    public function test_a_person_with_the_keys_takes_every_decision(): void
    {
        $world = $this->world();
        $blueprints = app(QualityProcessBlueprintService::class);

        $adopted = $blueprints->store((int) $world['customer']->id, $world['process'], $this->flow('Foreslått steg'), QualityProcessBlueprint::SOURCE_AI, $world['decider']);
        $this->assertSame(QualityProcessBlueprint::SOURCE_AI, $adopted->source);
        $this->assertSame((int) $world['decider']->id, (int) $adopted->generated_by_user_id);

        $blueprints->approve((int) $world['customer']->id, $world['process'], $world['decider']);
        $revision = QualityProcessRevision::query()->where('quality_item_id', $world['process']->id)->sole();
        $this->assertSame((int) $world['decider']->id, (int) $revision->approved_by_user_id);
        $this->assertSame($world['decider']->name, $revision->approved_by_name);

        $mistake = $this->process($world['customer'], 'Opprettet ved en feil');
        app(QualityItemService::class)->deleteItem((int) $world['customer']->id, $mistake, $world['decider']);
        $this->assertDatabaseMissing('quality_items', ['id' => $mistake->id]);
    }

    /** Editing is not deciding: a machine with quality.edit may still save a draft by hand. */
    public function test_an_integration_may_edit_a_draft_but_never_adopt_approve_or_delete(): void
    {
        $world = $this->world();
        $integration = $this->member($world['customer']);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();
        $integration->customerRoles()->attach($world['role']->id, ['customer_id' => $world['customer']->id]);

        $draft = app(QualityProcessBlueprintService::class)->store((int) $world['customer']->id, $world['process'], $this->flow('Maskinens utkast'), QualityProcessBlueprint::SOURCE_MANUAL, $integration->fresh());
        $this->assertSame(QualityProcessBlueprint::STATUS_DRAFT, $draft->status);

        $this->assertEveryDecisionRefused($world, $integration->fresh(), HumanDecisionRefused::class);
    }

    /** Delegated mode: a person's own account, but the request comes through the API. */
    public function test_no_decision_is_taken_through_the_api_even_on_behalf_of_a_person(): void
    {
        $world = $this->world();
        $this->app->instance(ActorChannel::class, new class implements ActorChannel
        {
            public function isApi(): bool
            {
                return true;
            }
        });

        $this->assertEveryDecisionRefused($world, $world['decider'], HumanDecisionRefused::class);
    }

    public function test_an_inactive_person_takes_no_decision(): void
    {
        $world = $this->world();
        $world['decider']->forceFill(['is_active' => false])->save();

        $this->assertEveryDecisionRefused($world, $world['decider']->fresh(), HumanDecisionRefused::class);
    }

    /** The service refuses on its own, for each decision the key it needs. */
    public function test_a_person_without_the_key_is_refused_by_the_service(): void
    {
        $world = $this->world();
        $reader = $this->member($world['customer']);
        $this->grant($world['customer'], $reader, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $editor = $this->member($world['customer']);
        $this->grant($world['customer'], $editor, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT]);

        $this->assertEveryDecisionRefused($world, $reader, AuthorizationException::class, 'view only');

        // quality.edit adopts a proposal, but neither approves nor deletes.
        $this->assertDecisionsRefused($world, $editor, AuthorizationException::class, ['approve', 'delete_flow', 'delete_item'], 'view + edit');
    }

    /** Customer separation: a decider in their own customer is nobody in another's. */
    public function test_another_customers_decider_is_refused(): void
    {
        $world = $this->world();
        $other = $this->customer();
        $outsider = $this->member($other);
        $this->grant($other, $outsider, self::EVERYTHING);
        $otherOwner = $this->owner($other);

        foreach (['role with every key' => $outsider, 'System Owner' => $otherOwner] as $who => $person) {
            $this->assertEveryDecisionRefused($world, $person, AuthorizationException::class, $who);
        }

        // Naming the other customer does not help either: the item is not theirs.
        $this->expectException(\Throwable::class);
        app(QualityProcessBlueprintService::class)->approve((int) $other->id, $world['process'], $outsider);
    }

    /** Without the Kvalitet module the keys open nothing. */
    public function test_a_customer_without_the_kvalitet_module_takes_no_decision(): void
    {
        $world = $this->world();
        $world['customer']->packageEntitlements()->delete();
        $this->assertFalse(app(ModuleEntitlementService::class)->hasModule($world['customer']->fresh(), 'quality'));

        $this->assertEveryDecisionRefused($world, $world['decider']->fresh(), AuthorizationException::class);
    }

    /**
     * Every human decision in Kvalitet, called directly on its service with this actor: each is
     * refused with $refusal, and the process, its flow and its approved versions are as they were.
     *
     * @param  array<string, mixed>  $world
     * @param  class-string<\Throwable>  $refusal
     */
    private function assertEveryDecisionRefused(array $world, User $actor, string $refusal, string $who = ''): void
    {
        $this->assertDecisionsRefused($world, $actor, $refusal, ['adopt', 'approve', 'delete_flow', 'delete_item'], $who);
    }

    /**
     * @param  array<string, mixed>  $world
     * @param  class-string<\Throwable>  $refusal
     * @param  list<string>  $decisions
     */
    private function assertDecisionsRefused(array $world, User $actor, string $refusal, array $decisions, string $who = ''): void
    {
        $customerId = (int) $world['customer']->id;
        $blueprints = app(QualityProcessBlueprintService::class);
        $before = QualityProcessBlueprint::query()->where('quality_item_id', $world['process']->id)->sole();

        $attempts = [
            'adopt' => fn () => $blueprints->store($customerId, $world['process'], $this->flow('Uten rett'), QualityProcessBlueprint::SOURCE_AI, $actor),
            'approve' => fn () => $blueprints->approve($customerId, $world['process'], $actor),
            'delete_flow' => fn () => $blueprints->delete($customerId, $world['process'], $actor),
            'delete_item' => fn () => app(QualityItemService::class)->deleteItem($customerId, $world['process'], $actor, true),
        ];

        foreach ($decisions as $decision) {
            try {
                $attempts[$decision]();
                $this->fail("{$who}: {$decision} must be refused");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($refusal, $e, "{$who}: {$decision} — ".$e->getMessage());
            }
        }

        $this->assertNotNull(QualityItem::query()->find($world['process']->id), $who);
        $after = QualityProcessBlueprint::query()->where('quality_item_id', $world['process']->id)->sole();
        $this->assertSame($before->payload, $after->payload, $who);
        $this->assertSame(QualityProcessBlueprint::STATUS_DRAFT, $after->status, $who);
        $this->assertSame(QualityProcessBlueprint::SOURCE_MANUAL, $after->source, $who);
        $this->assertSame(0, QualityProcessRevision::query()->where('quality_item_id', $world['process']->id)->count(), $who);
    }

    /** @return array<string, mixed> a process with a manual draft flow, and a decider holding every key */
    private function world(): array
    {
        $customer = $this->customer();
        $owner = $this->owner($customer);
        $decider = $this->member($customer);
        $role = $this->grant($customer, $decider, self::EVERYTHING);
        $process = $this->process($customer, 'Innkjøp');
        app(QualityProcessBlueprintService::class)->store((int) $customer->id, $process, $this->flow('Registrer leverandøren'), QualityProcessBlueprint::SOURCE_MANUAL, $owner);

        return compact('customer', 'owner', 'decider', 'role') + ['process' => $process->fresh()];
    }

    /** @return array<string, mixed> */
    private function flow(string $step): array
    {
        return [
            'lanes' => [['key' => 'innkjop', 'label' => 'Innkjøper']],
            'nodes' => [
                ['key' => 'start', 'lane' => 'innkjop', 'type' => 'start', 'label' => 'Start'],
                ['key' => 'steg', 'lane' => 'innkjop', 'type' => 'step', 'label' => $step],
                ['key' => 'slutt', 'lane' => 'innkjop', 'type' => 'end', 'label' => 'Slutt'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'steg'],
                ['from' => 'steg', 'to' => 'slutt'],
            ],
        ];
    }

    private function process(Customer $customer, string $title): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => $title,
            'status' => QualityItem::STATUS_ACTIVE,
        ]);
    }

    /** @param  list<string>  $permissionKeys */
    private function grant(Customer $customer, User $user, array $permissionKeys): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $role->syncPermissions($permissionKeys);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    private function member(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'kvalitet-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function owner(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'System Owner',
            'email' => 'eier-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => 'Kvalitet Beslutning AS',
            'slug' => 'kvalitet-beslutning-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        app(ModuleEntitlementService::class)->activatePackage($customer, 'basis');

        return $customer;
    }
}
