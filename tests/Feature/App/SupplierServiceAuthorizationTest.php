<?php

namespace Tests\Feature\App;

use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Models\User;
use App\Services\EnterpriseWiki\Knowledge\Sources\SupplierKnowledgeSource;
use App\Services\Suppliers\Assurance\SupplierProfileService;
use App\Services\Suppliers\SupplierCriticalityService;
use App\Services\Suppliers\SupplierDocumentService;
use App\Services\Suppliers\SupplierImprovementHandoffService;
use App\Services\Suppliers\SupplierLifecycleService;
use App\Services\Suppliers\SupplierRegistration;
use App\Services\Suppliers\SupplierRiskService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Ordinary register work in Leverandøroppfølging checks supplier.edit in the service — phase 0
 * step 14 (docs/external-api-phase-0-plan.md §2j).
 *
 * Registering and editing a supplier, Endre kritikalitet, Ta i bruk / Avslutt / Gjenåpne, the
 * leverandørprofil, documentation and the links to Avvik og forbedringer and Risiko are not human-only:
 * an integration that holds supplier.edit may do them. What every entry point must respect is the key
 * itself, in the actor's own customer — the supplier's — with the module.
 */
class SupplierServiceAuthorizationTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use UsesProjectPostgresConnection;

    private const WRITES = [
        'register', 'update', 'criticality', 'activate', 'end', 'reopen', 'profile',
        'add_document', 'update_document', 'delete_document', 'link_case', 'unlink_case', 'link_risk', 'unlink_risk',
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

    /**
     * supplier.edit is register work, not a decision: a person and an integration with the key both
     * may register a supplier Under vurdering and Ikke vurdert, correct its master data and keep its
     * documentation. Criticality and the lifecycle are a person's (A36, SupplierCriticalityLifecycleDecisionTest).
     */
    public function test_an_editor_and_an_integration_with_supplier_edit_keep_the_register(): void
    {
        foreach (['person' => false, 'integration' => true] as $who => $integration) {
            $world = $this->world();
            $editor = $this->supplierUser($world['customer'], [CustomerPermissionCatalog::SUPPLIER_EDIT]);

            if ($integration) {
                $editor->forceFill(['role' => User::ROLE_INTEGRATION])->save();
                $editor = $editor->fresh();
            }

            $registered = app(SupplierRegistration::class)->register($editor, $this->fields($editor, 'Ny AS'), Supplier::STATUS_ONBOARDING, null);
            $this->assertSame((int) $world['customer']->id, (int) $registered->customer_id, $who);

            DB::transaction(fn () => app(SupplierRegistration::class)->update($editor, $registered, $this->fields($editor, 'Nytt navn AS')));
            app(SupplierProfileService::class)->save($registered->fresh(), $editor, SupplierProfileService::answers(['uses_subcontractors' => 'yes']), null);
            $document = app(SupplierDocumentService::class)->create($registered->fresh(), $editor, ['document_type' => 'certificate', 'title' => 'Avtale']);
            app(SupplierDocumentService::class)->delete($document, $editor);

            $this->assertSame(['Nytt navn AS', Supplier::STATUS_ONBOARDING, null], [$registered->fresh()->name, $registered->fresh()->status, $registered->fresh()->criticality], $who);
            $this->assertSame(0, SupplierDocument::query()->where('supplier_id', $registered->id)->count(), $who);
        }
    }

    /** Without supplier.edit — reading only, or assess, assure or delete alone — nothing is written. */
    public function test_without_supplier_edit_the_services_refuse(): void
    {
        $world = $this->world();

        foreach ([[], [CustomerPermissionCatalog::SUPPLIER_ASSESS], [CustomerPermissionCatalog::SUPPLIER_DELETE]] as $keys) {
            $this->assertWritesRefused($world, $this->supplierUser($world['customer'], $keys), self::WRITES, implode(',', $keys) ?: 'view only');
        }

        // supplier.assure keeps documentation, but registers and changes nothing else.
        $assurer = $this->supplierUser($world['customer'], [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $this->assertWritesRefused($world, $assurer, array_values(array_diff(self::WRITES, ['add_document', 'update_document', 'delete_document'])), 'assure');

        // The keys without supplier.view open nothing either.
        $blind = $this->member($world['customer']);
        $this->grantAll($world['customer'], $blind, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $this->assertWritesRefused($world, $blind->fresh(), self::WRITES, 'no view');
    }

    /** Another customer's editor and System Owner, with every key, write nothing on this customer's suppliers. */
    public function test_another_customers_editor_is_refused(): void
    {
        $world = $this->world();
        ['customer' => $other, 'owner' => $otherOwner] = $this->context('grc');
        $keys = [CustomerPermissionCatalog::SUPPLIER_VIEW, CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_ASSURE];
        $outsider = $this->supplierUser($other, $keys);
        $this->grantAll($other, $otherOwner, $keys);

        // Registering is always in the actor's own customer, so it is not a way in.
        $intoTheWorld = array_values(array_diff(self::WRITES, ['register']));

        foreach (['editor' => $outsider, 'System Owner' => $otherOwner->fresh()] as $who => $person) {
            $this->assertWritesRefused($world, $person, $intoTheWorld, $who);
        }
    }

    /** Without the module, supplier.edit opens nothing. */
    public function test_a_customer_without_the_module_writes_nothing(): void
    {
        $world = $this->world();
        $world['customer']->packageEntitlements()->delete();

        $this->assertWritesRefused($world, $world['editor']->fresh(), self::WRITES, 'no module');
    }

    /**
     * «Overfør til Wiki» from a supplier: supplier.assure in the supplier's own customer, with the
     * module. Wiki checks its own half (wiki.knowledge.handoff) in WikiKnowledgeHandoffService.
     */
    public function test_the_knowledge_handoff_needs_supplier_assure_in_the_suppliers_customer(): void
    {
        $world = $this->world();
        $source = app(SupplierKnowledgeSource::class);
        $assurer = $this->supplierUser($world['customer'], [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        ['customer' => $other] = $this->context('grc');

        $this->assertTrue($source->canHandOff($assurer, $world['supplier']));
        $this->assertFalse($source->canHandOff($world['editor'], $world['supplier']), 'supplier.edit alone');
        $this->assertFalse($source->canHandOff($this->supplierUser($other, [CustomerPermissionCatalog::SUPPLIER_ASSURE]), $world['supplier']), 'another customer');

        $world['customer']->packageEntitlements()->delete();
        $this->assertFalse($source->canHandOff($assurer->fresh(), $world['supplier']), 'no module');
    }

    /**
     * @param  array<string, mixed>  $world
     * @param  list<string>  $writes
     */
    private function assertWritesRefused(array $world, User $actor, array $writes, string $who): void
    {
        $before = $this->snapshot();
        $supplier = $world['supplier'];

        $attempts = [
            'register' => fn () => app(SupplierRegistration::class)->register($actor, $this->fields($world['editor'], 'Uten rett AS'), Supplier::STATUS_ACTIVE, null),
            'update' => fn () => app(SupplierRegistration::class)->update($actor, $supplier, $this->fields($world['editor'], 'Overskrevet AS')),
            'criticality' => fn () => app(SupplierCriticalityService::class)->change($supplier, $actor, SupplierCriticalityService::classification($this->classification(Supplier::CRITICALITY_CRITICAL, 12)), 'Uten rett.'),
            'activate' => fn () => app(SupplierLifecycleService::class)->activate($world['onboarding'], $actor),
            'end' => fn () => app(SupplierLifecycleService::class)->end($supplier, $actor, 'Uten rett.'),
            'reopen' => fn () => app(SupplierLifecycleService::class)->reopen($world['ended'], $actor, 'Uten rett.'),
            'profile' => fn () => app(SupplierProfileService::class)->save($supplier, $actor, SupplierProfileService::answers(['uses_subcontractors' => 'yes']), null),
            'add_document' => fn () => app(SupplierDocumentService::class)->create($supplier, $actor, ['document_type' => 'certificate', 'title' => 'Uten rett']),
            'update_document' => fn () => app(SupplierDocumentService::class)->update($world['document'], $actor, ['title' => 'Overskrevet']),
            'delete_document' => fn () => app(SupplierDocumentService::class)->delete($world['document'], $actor),
            'link_case' => fn () => app(SupplierImprovementHandoffService::class)->link($actor, $supplier, 1),
            'unlink_case' => fn () => app(SupplierImprovementHandoffService::class)->unlink($actor, $supplier, 1),
            'link_risk' => fn () => app(SupplierRiskService::class)->link($actor, $supplier, 1),
            'unlink_risk' => fn () => app(SupplierRiskService::class)->unlink($actor, $supplier, 1),
        ];

        foreach ($writes as $write) {
            try {
                $attempts[$write]();
                $this->fail("{$who}: {$write} must be refused");
            } catch (AuthorizationException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($before, $this->snapshot(), $who);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $rows = fn (string $table, array $columns): array => DB::table($table)->orderBy('id')->get($columns)->map(fn ($row): array => (array) $row)->all();

        return [
            'suppliers' => $rows('suppliers', ['id', 'name', 'status', 'criticality']),
            'status_changes' => $rows('supplier_status_changes', ['id']),
            'criticality_changes' => $rows('supplier_criticality_changes', ['id']),
            'profile_changes' => $rows('supplier_profile_changes', ['id']),
            'documents' => $rows('supplier_documents', ['id', 'title']),
        ];
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        ['customer' => $customer] = $this->context('grc');
        $editor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $editor, 'Drift AS');

        return [
            'customer' => $customer,
            'editor' => $editor,
            'supplier' => $supplier,
            'onboarding' => $this->supplier($customer, $editor, 'Ny AS', Supplier::STATUS_ONBOARDING),
            'ended' => $this->supplier($customer, $editor, 'Gammel AS', Supplier::STATUS_ENDED),
            'document' => SupplierDocument::query()->create([
                'customer_id' => $customer->id,
                'supplier_id' => $supplier->id,
                'document_type' => 'certificate',
                'title' => 'Avtale',
            ]),
        ];
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
