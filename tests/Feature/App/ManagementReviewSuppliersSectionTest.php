<?php

namespace Tests\Feature\App;

use App\Models\SupplierControlRequirement;
use App\Services\ManagementReview\ReviewScope;
use App\Services\ManagementReview\Sections\SuppliersSection;
use App\Support\CustomerPermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Ledelsens gjennomgang → Leverandøroppfølging after the kontrollbeslutning was retired
 * (2026-10-12): no decision counts, in the period or «gjeldende»; an open mandatory requirement is
 * counted under its own name, never as an approval; and reviews finalized before keep the labels
 * their snapshot needs.
 */
class ManagementReviewSuppliersSectionTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use UsesProjectPostgresConnection;

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

    public function test_the_section_counts_no_decisions_and_names_open_mandatory_requirements(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $reader = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        $supplier = $this->supplier($customer, $reader, 'Drift AS');
        SupplierControlRequirement::query()->create([
            'customer_id' => $customer->id, 'title' => 'Databehandleravtale', 'theme' => 'privacy', 'level' => 'mandatory',
            'control_point' => 'before_contract', 'applies_when' => [],
        ]);
        // A decision registered in the period, before they were retired.
        DB::table('supplier_assurance_decisions')->insert([
            'customer_id' => $customer->id, 'supplier_id' => $supplier->id, 'decision' => 'not_approved', 'rationale' => 'Ikke signert.',
            'decided_on' => now()->toDateString(), 'recorded_at' => now(), 'state_snapshot' => '{}', 'supplier_name' => $supplier->name,
        ]);

        $today = CarbonImmutable::parse(now()->toDateString());
        $scope = new ReviewScope((int) $customer->id, 0, $today->subMonth(), $today, null, $today, CarbonImmutable::now());
        $payload = app(SuppliersSection::class)->build($reader, $scope, null);

        $metrics = collect($payload['metrics'])->flatMap(fn (array $group): array => collect($group['metrics'])
            ->mapWithKeys(fn (array $metric): array => [$group['group'].'.'.$metric['key'] => (array) $metric['values']])
            ->all());

        foreach (['period.decisions_not_approved', 'period.decisions_approved_with_follow_up', 'status.in_force_not_approved', 'status.in_force_approved_with_follow_up', 'status.decision_required'] as $retired) {
            $this->assertArrayNotHasKey($retired, $metrics->all(), $retired);
        }

        $this->assertSame(1, array_sum($metrics['status.mandatory_open']));
        $this->assertSame(1, array_sum($metrics['status.suppliers_with_findings']));
        $this->assertContains('mandatory_open', $this->signals($payload));

        // Finalized reviews keep reading: the labels of the retired numbers stay.
        foreach (['no', 'en'] as $locale) {
            foreach (['decisions_not_approved', 'decisions_approved_with_follow_up', 'in_force_not_approved', 'in_force_approved_with_follow_up', 'decision_required', 'mandatory_open'] as $key) {
                $this->assertNotSame("procynia.management_review.metrics.suppliers.{$key}", __("procynia.management_review.metrics.suppliers.{$key}", [], $locale), "{$locale}: {$key}");
            }
        }
    }

    /** @return list<string> */
    private function signals(array $payload): array
    {
        return collect($payload['lists'][0]['items'][0]['fields'] ?? [])->firstWhere('key', 'signals')['value'] ?? [];
    }
}
