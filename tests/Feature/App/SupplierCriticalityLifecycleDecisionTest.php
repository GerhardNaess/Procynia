<?php

namespace Tests\Feature\App;

use App\Exceptions\HumanDecisionRefused;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Security\ActorChannel;
use App\Services\Suppliers\Assurance\SupplierProfileService;
use App\Services\Suppliers\SupplierCriticalityService;
use App\Services\Suppliers\SupplierLifecycleService;
use App\Services\Suppliers\SupplierRegistration;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * A36 (docs/external-api-phase-0-plan.md §2j): how important a supplier is and whether it is in use
 * are a person's decisions. Vurder / Endre kritikalitet, Ta i bruk, Avslutt and Gjenåpne — and
 * registering a supplier that is already Aktiv or already classified, which takes the same decisions —
 * pass HumanDecisionGuard. The key is unchanged (supplier.edit), so who may decide, and every screen,
 * is as before. An integration and the API channel, delegated mode included, are refused, and so is
 * anyone without the key, of another customer or without the module. Nothing is written.
 */
class SupplierCriticalityLifecycleDecisionTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use UsesProjectPostgresConnection;

    private const DECISIONS = ['criticality', 'activate', 'end', 'reopen', 'register_active', 'register_classified'];

    /** The decisions on an existing supplier — registering is always in the actor's own customer. */
    private const ON_A_SUPPLIER = ['criticality', 'activate', 'end', 'reopen'];

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

    /** A person with supplier.edit takes every decision, and the history carries their name. */
    public function test_a_person_with_supplier_edit_takes_the_decisions_under_their_own_name(): void
    {
        $world = $this->world();
        $editor = $world['editor'];

        app(SupplierCriticalityService::class)->change($world['active'], $editor, $this->critical(), 'Leverer lønnssystemet.');
        app(SupplierLifecycleService::class)->activate($world['onboarding'], $editor);
        app(SupplierLifecycleService::class)->end($world['active']->fresh(), $editor, 'Avtalen er sagt opp.');
        app(SupplierLifecycleService::class)->reopen($world['ended'], $editor, 'Ny avtale.');
        $registered = app(SupplierRegistration::class)->register($editor, $this->fields($editor, 'Ny AS'), Supplier::STATUS_ACTIVE, $this->critical());

        $this->assertSame([Supplier::STATUS_ENDED, Supplier::CRITICALITY_CRITICAL], [$world['active']->fresh()->status, $world['active']->fresh()->criticality]);
        $this->assertSame(Supplier::STATUS_ACTIVE, $world['onboarding']->fresh()->status);
        $this->assertSame(Supplier::STATUS_ACTIVE, $world['ended']->fresh()->status);
        $this->assertSame([Supplier::STATUS_ACTIVE, Supplier::CRITICALITY_CRITICAL], [$registered->status, $registered->criticality]);
        $this->assertSame([(int) $editor->id], DB::table('supplier_status_changes')->pluck('changed_by_user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());
        $this->assertSame([(int) $editor->id], DB::table('supplier_criticality_changes')->pluck('changed_by_user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());
    }

    /** An integration holds supplier.edit (it is not human-only) and is still refused every decision. */
    public function test_an_integration_with_supplier_edit_takes_no_decision(): void
    {
        $world = $this->world();
        $integration = $this->supplierUser($world['customer'], [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();

        $this->assertDecisionsRefused($world, $integration->fresh(), HumanDecisionRefused::class, self::DECISIONS);
    }

    /** Delegated mode: a person's own account with the key, but the request comes through the API. */
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

        $this->assertDecisionsRefused($world, $world['editor'], HumanDecisionRefused::class, self::DECISIONS);
    }

    public function test_an_inactive_person_takes_no_decision(): void
    {
        $world = $this->world();
        $world['editor']->forceFill(['is_active' => false])->save();

        $this->assertDecisionsRefused($world, $world['editor']->fresh(), HumanDecisionRefused::class, self::DECISIONS);
    }

    /** The key is unchanged: without supplier.edit — or without supplier.view — a person decides nothing. */
    public function test_a_person_without_supplier_edit_takes_no_decision(): void
    {
        $world = $this->world();
        $customer = $world['customer'];

        foreach ([[], [CustomerPermissionCatalog::SUPPLIER_ASSESS], [CustomerPermissionCatalog::SUPPLIER_ASSURE], [CustomerPermissionCatalog::SUPPLIER_DELETE]] as $keys) {
            $this->assertDecisionsRefused($world, $this->supplierUser($customer, $keys), AuthorizationException::class, self::DECISIONS, implode(',', $keys) ?: 'view only');
        }

        $blind = $this->member($customer);
        $this->grantAll($customer, $blind, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $this->assertDecisionsRefused($world, $blind->fresh(), AuthorizationException::class, self::DECISIONS, 'no view');

        $this->assertDecisionsRefused($world, $world['owner'], AuthorizationException::class, self::DECISIONS, 'System Owner without a role');
    }

    /** Another customer's editor and System Owner decide nothing about this customer's suppliers. */
    public function test_another_customers_editor_is_refused(): void
    {
        $world = $this->world();
        ['customer' => $other, 'owner' => $otherOwner] = $this->context('grc');
        $keys = [CustomerPermissionCatalog::SUPPLIER_VIEW, CustomerPermissionCatalog::SUPPLIER_EDIT];
        $this->grantAll($other, $otherOwner, $keys);

        foreach (['editor' => $this->supplierUser($other, $keys), 'System Owner' => $otherOwner->fresh()] as $who => $person) {
            $this->assertDecisionsRefused($world, $person, AuthorizationException::class, self::ON_A_SUPPLIER, $who);
        }
    }

    public function test_a_customer_without_the_module_takes_no_decision(): void
    {
        $world = $this->world();
        $world['customer']->packageEntitlements()->delete();
        $this->assertFalse(app(ModuleEntitlementService::class)->hasModule($world['customer']->fresh(), 'supplier'));

        $this->assertDecisionsRefused($world, $world['editor']->fresh(), AuthorizationException::class, self::DECISIONS);
    }

    /**
     * A36 cannot be reached around the side. With supplier.edit an integration may register a supplier
     * Under vurdering and Ikke vurdert, edit its master data and fill in its profile (A37) — and none
     * of that sets the status or the criticality: they are not mass-assignable, and the profile never
     * writes them.
     */
    public function test_register_work_never_changes_status_or_criticality(): void
    {
        $world = $this->world();
        // A person with supplier.edit: an integration does no register work at all (D13).
        $integration = $this->supplierUser($world['customer'], [CustomerPermissionCatalog::SUPPLIER_EDIT]);

        $registered = app(SupplierRegistration::class)->register($integration, $this->fields($integration, 'Ny AS'), Supplier::STATUS_ONBOARDING, null);
        $this->assertSame([Supplier::STATUS_ONBOARDING, null], [$registered->status, $registered->criticality]);

        $before = $this->snapshot();
        DB::transaction(fn () => app(SupplierRegistration::class)->update($integration, $world['onboarding'], $this->fields($integration, 'Nytt navn AS') + [
            'status' => Supplier::STATUS_ACTIVE,
            'criticality' => Supplier::CRITICALITY_CRITICAL,
            'review_interval_months' => 6,
            'processes_personal_data' => true,
        ]));
        app(SupplierProfileService::class)->save($world['onboarding']->fresh(), $integration, SupplierProfileService::answers([
            'uses_subcontractors' => 'yes',
            'production_outside_eea' => 'yes',
        ]), null);

        $after = $world['onboarding']->fresh();
        $this->assertSame('Nytt navn AS', $after->name);
        $this->assertSame([Supplier::STATUS_ONBOARDING, null, null, null], [$after->status, $after->criticality, $after->review_interval_months, $after->processes_personal_data]);
        $this->assertSame([$before['status_changes'], $before['criticality_changes']], [$this->snapshot()['status_changes'], $this->snapshot()['criticality_changes']]);
    }

    /**
     * @param  array<string, mixed>  $world
     * @param  class-string<\Throwable>  $refusal
     * @param  list<string>  $decisions
     */
    private function assertDecisionsRefused(array $world, User $actor, string $refusal, array $decisions, string $who = ''): void
    {
        $before = $this->snapshot();

        $attempts = [
            'criticality' => fn () => app(SupplierCriticalityService::class)->change($world['active'], $actor, $this->critical(), 'Uten rett.'),
            'activate' => fn () => app(SupplierLifecycleService::class)->activate($world['onboarding'], $actor),
            'end' => fn () => app(SupplierLifecycleService::class)->end($world['active'], $actor, 'Uten rett.'),
            'reopen' => fn () => app(SupplierLifecycleService::class)->reopen($world['ended'], $actor, 'Uten rett.'),
            'register_active' => fn () => app(SupplierRegistration::class)->register($actor, $this->fields($world['editor'], 'Aktiv AS'), Supplier::STATUS_ACTIVE, null),
            'register_classified' => fn () => app(SupplierRegistration::class)->register($actor, $this->fields($world['editor'], 'Kritisk AS'), Supplier::STATUS_ONBOARDING, $this->critical()),
        ];

        foreach ($decisions as $decision) {
            try {
                $attempts[$decision]();
                $this->fail("{$who}: {$decision} must be refused");
            } catch (AssertionFailedError $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->assertInstanceOf($refusal, $e, "{$who}: {$decision} — ".$e::class.': '.$e->getMessage());
            }
        }

        $this->assertSame($before, $this->snapshot(), $who);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $rows = fn (string $table, array $columns): array => DB::table($table)->orderBy('id')->get($columns)->map(fn ($row): array => (array) $row)->all();

        return [
            'suppliers' => $rows('suppliers', ['id', 'status', 'criticality', 'review_interval_months']),
            'status_changes' => $rows('supplier_status_changes', ['id']),
            'criticality_changes' => $rows('supplier_criticality_changes', ['id']),
        ];
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);

        return [
            'customer' => $customer,
            'owner' => $owner,
            'editor' => $editor,
            'active' => $this->supplier($customer, $editor, 'Drift AS'),
            'onboarding' => $this->supplier($customer, $editor, 'Ny leverandør AS', Supplier::STATUS_ONBOARDING),
            'ended' => $this->supplier($customer, $editor, 'Gammel AS', Supplier::STATUS_ENDED),
        ];
    }

    /** @return array<string, mixed> */
    private function critical(): array
    {
        return SupplierCriticalityService::classification($this->classification(Supplier::CRITICALITY_CRITICAL, 12, ['processes_personal_data' => true]));
    }

    /** @return array<string, mixed> */
    private function fields(User $owner, string $name): array
    {
        return [
            'name' => $name,
            'category' => 'it_cloud',
            'deliverable_description' => 'Drift av lønnssystem',
            'owner_user_id' => $owner->id,
        ];
    }
}
