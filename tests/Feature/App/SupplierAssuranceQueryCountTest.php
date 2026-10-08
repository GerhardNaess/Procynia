<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierDocument;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Supplier Assurance v2 phase 9: the register and the supplier page read Leverandørkontroll in batch
 * (docs/supplier-assurance-v2-plan.md §21). More suppliers on the register, or more requirements,
 * controls and documents on one supplier, must not mean more queries.
 */
class SupplierAssuranceQueryCountTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
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

    public function test_the_register_does_not_query_per_supplier(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $requirements = $this->requirements($customer, 3);
        $this->controlledSupplier($customer, $assurer, 'Leverandør 1', $requirements);
        $this->controlledSupplier($customer, $assurer, 'Leverandør 2', $requirements);

        $filtered = '/app/supplier-management?attention=1&decision_required=1';
        $few = $this->queriesFor($assurer, '/app/supplier-management');
        $fewFiltered = $this->queriesFor($assurer, $filtered);

        foreach (range(3, 8) as $n) {
            $this->controlledSupplier($customer, $assurer, "Leverandør {$n}", $requirements);
        }

        $this->assertSame($few, $this->queriesFor($assurer, '/app/supplier-management'));
        $this->assertSame($fewFiltered, $this->queriesFor($assurer, $filtered));
    }

    public function test_the_supplier_page_does_not_query_per_requirement_control_or_document(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $assurer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $small = $this->controlledSupplier($customer, $assurer, 'Liten AS', $this->requirements($customer, 2));
        $few = $this->queriesFor($assurer, "/app/supplier-management/{$small->id}");

        $large = $this->controlledSupplier($customer, $assurer, 'Stor AS', $this->requirements($customer, 8));

        $this->assertSame($few, $this->queriesFor($assurer, "/app/supplier-management/{$large->id}"));
    }

    private function queriesFor(User $user, string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @return list<SupplierControlRequirement> */
    private function requirements(Customer $customer, int $count): array
    {
        return array_map(fn (int $n): SupplierControlRequirement => SupplierControlRequirement::query()->create([
            'customer_id' => $customer->id,
            'title' => "Krav {$n} ".uniqid(),
            'theme' => $n % 2 === 0 ? 'privacy' : 'information_security',
            'level' => $n === 1 ? 'mandatory' : 'important',
            'control_point' => 'before_contract',
            'control_interval_months' => 12,
            'applies_when' => [],
        ]), range(1, $count));
    }

    /** A supplier with every requirement controlled: one documented with its own document, the rest missing. */
    private function controlledSupplier(Customer $customer, User $assurer, string $name, array $requirements): Supplier
    {
        $supplier = $this->supplier($customer, $assurer, $name);
        $show = "/app/supplier-management/{$supplier->id}";

        foreach (array_values($requirements) as $index => $requirement) {
            $document = SupplierDocument::query()->create([
                'customer_id' => $customer->id, 'supplier_id' => $supplier->id, 'document_type' => 'certificate',
                'title' => "Dokument {$index}", 'valid_until' => now()->addMonths(6)->toDateString(),
            ]);
            $this->actingAs($assurer)->post("{$show}/requirement-evaluations", [
                'requirement_id' => $requirement->id,
                'status' => $index === 0 ? 'documented' : 'missing',
                'rationale' => 'Kontrollert.',
                'evaluated_on' => now()->toDateString(),
                'accepted_until' => '',
                'document_ids' => [$document->id],
            ])->assertSessionHasNoErrors();
        }

        return $supplier;
    }
}
