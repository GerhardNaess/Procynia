<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierAssuranceDecision;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierDocument;
use App\Models\SupplierRequirementEvaluation;
use App\Models\User;
use App\Services\Suppliers\SupplierAttentionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Supplier Assurance v2 phase 6: Trenger oppmerksomhet from Leverandørkontroll and «Neste
 * kontroller» (docs/supplier-assurance-v2-plan.md §14, §15.1). The dates are SupplierFollowUpPlanTest's;
 * here, end to end:
 *
 *  - signals 6–9 each hit and miss, a requirement is named under one of them only, and v1's rules
 *    stand beside them unchanged;
 *  - the edge of Kontroll forfalt is the day after the date;
 *  - retired, excluded and no-longer-applying requirements raise nothing, nor does an ended supplier
 *    or a customer without a catalogue;
 *  - the register and the page read the same findings, batch equals single, nothing from another
 *    customer, and reading never writes a control or a decision.
 */
class SupplierAssuranceAttentionTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use UsesProjectPostgresConnection;

    private const TODAY = '2026-10-08';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
        Carbon::setTestNow(self::TODAY.' 12:00:00');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_each_assurance_signal_hits_and_misses_and_names_a_requirement_once(): void
    {
        ['customer' => $customer, 'reader' => $reader, 'mandatory' => $mandatory, 'important' => $important, 'standard' => $standard] = $this->catalogue();

        // Nothing controlled: the mandatory requirement asks for a decision; the important one is
        // Ikke vurdert; Oppfølging-level raises nothing.
        $new = $this->supplier($customer, $reader, 'A Ny AS');
        // «Ikke godkjent for nye kjøp» switches «Krever beslutning» off, so the mandatory requirement
        // is named under Krav ikke vurdert instead.
        $refused = $this->supplier($customer, $reader, 'B Ikke godkjent AS');
        $this->decide($refused, SupplierAssuranceDecision::DECISION_NOT_APPROVED);
        // Kontroll forfalt, each with why: the interval ran out yesterday / a document expired /
        // the acceptance ran out yesterday.
        $interval = $this->supplier($customer, $reader, 'C Intervall AS');
        $this->documented($interval, $mandatory);
        $this->evaluate($interval, $important, 'documented', '2026-04-07', documents: [$this->document($interval, 'Rapport 2026', null)]);
        $this->evaluate($interval, $standard, 'missing', '2026-09-01');
        $document = $this->supplier($customer, $reader, 'D Dokument AS');
        $this->documented($document, $mandatory);
        $this->evaluate($document, $important, 'documented', '2026-09-01', documents: [$this->document($document, 'SOC 2', '2026-10-07')]);
        $accepted = $this->supplier($customer, $reader, 'E Aksept AS');
        $this->documented($accepted, $mandatory);
        $this->evaluate($accepted, $important, 'temporarily_accepted', '2026-09-01', '2026-10-07');
        // Under vurdering: no Kontroll forfalt (active only), and only before-contract requirements
        // are Ikke vurdert.
        $onboarding = $this->supplier($customer, $reader, 'F Under vurdering AS', Supplier::STATUS_ONBOARDING);
        $this->decide($onboarding, SupplierAssuranceDecision::DECISION_NOT_APPROVED);
        $this->evaluate($onboarding, $standard, 'documented', '2026-01-01', documents: [$this->document($onboarding, 'Gammel', '2026-01-02')]);
        // Ended: nothing at all.
        $ended = $this->supplier($customer, $reader, 'G Avsluttet AS', Supplier::STATUS_ENDED);
        // Kritisk without a profile, while the customer has a catalogue.
        $critical = $this->supplier($customer, $reader, 'H Kritisk AS', classification: $this->classification(Supplier::CRITICALITY_CRITICAL, 12));
        $this->documented($critical, $mandatory);
        $this->documented($critical, $important);

        $service = app(SupplierAttentionService::class);
        $findings = $service->findingsForSuppliers(collect([$new, $refused, $interval, $document, $accepted, $onboarding, $ended, $critical]));
        $shape = fn (Supplier $supplier): array => array_map(fn (array $finding): array => [
            $finding['key'],
            array_map(fn (array $row): string => $row['title'].(isset($row['reason']) ? ':'.$row['reason'].'@'.($row['date'] ?? '-') : ''), $finding['requirements'] ?? []),
        ], $findings[$supplier->id]);

        $this->assertSame([['decision_required', ['DBA']], ['requirement_not_evaluated', ['Sikkerhetsrapport']]], $shape($new));
        $this->assertSame([['requirement_not_evaluated', ['DBA', 'Sikkerhetsrapport']]], $shape($refused));
        $this->assertSame([['control_overdue', ['Sikkerhetsrapport:control_interval@2026-10-07']]], $shape($interval));
        $this->assertSame([['control_overdue', ['Sikkerhetsrapport:document_expired@2026-10-07']], ['document_expired', []]], $shape($document));
        $this->assertSame('SOC 2', $findings[$document->id][0]['requirements'][0]['document_title']);
        $this->assertSame([['control_overdue', ['Sikkerhetsrapport:acceptance_expired@2026-10-07']]], $shape($accepted));
        $this->assertSame([['requirement_not_evaluated', ['DBA']], ['document_expired', []]], $shape($onboarding));
        $this->assertSame([], $shape($ended));
        // v1 stands beside it, unchanged: Kritisk and never assessed.
        $this->assertSame([['profile_incomplete', []], ['not_assessed', []]], $shape($critical));

        // The edge: due yesterday is overdue today; on the day itself it is not.
        $dayBefore = CarbonImmutable::parse('2026-10-07');
        $this->assertSame([], $service->findingsForSupplier($interval->fresh(), $dayBefore));
        $this->assertSame([], $service->findingsForSupplier($accepted->fresh(), $dayBefore));
        $this->assertSame('document_expiring', $service->findingsForSupplier($document->fresh(), $dayBefore)[0]['key']);

        // Kontroll forfalt clears once a new control is registered — the old one stays.
        $this->documented($interval, $important);
        $this->assertSame([], $service->findingsForSupplier($interval->fresh()));
        $this->assertSame(2, SupplierRequirementEvaluation::query()->where('supplier_id', $interval->id)->where('requirement_id', $important->id)->count());
    }

    public function test_retired_excluded_and_catalogue_free_requirements_raise_nothing(): void
    {
        ['customer' => $customer, 'reader' => $reader, 'mandatory' => $mandatory, 'important' => $important, 'standard' => $standard] = $this->catalogue();
        $supplier = $this->supplier($customer, $reader, 'Drift AS', classification: $this->classification(Supplier::CRITICALITY_IMPORTANT, 12));
        $this->evaluate($supplier, $important, 'documented', '2025-01-01');
        $service = app(SupplierAttentionService::class);

        $this->assertSame(['decision_required', 'control_overdue', 'profile_incomplete', 'not_assessed'], array_column($service->findingsForSupplier($supplier), 'key'));

        // Excluded (not mandatory): it no longer applies, so it is neither overdue nor planned.
        DB::table('supplier_requirement_overrides')->insert([
            'customer_id' => $customer->id, 'supplier_id' => $supplier->id, 'requirement_id' => $important->id, 'action' => 'exclude',
            'reason' => 'Ikke relevant.', 'requirement_title' => $important->title, 'requirement_level' => $important->level, 'created_at' => now(),
        ]);
        $this->assertSame(['decision_required', 'profile_incomplete', 'not_assessed'], array_column($service->findingsForSupplier($supplier), 'key'));

        // Retired: gone from the signals, its controls kept.
        $mandatory->forceFill(['status' => SupplierControlRequirement::STATUS_RETIRED])->save();
        $this->assertSame(['profile_incomplete', 'not_assessed'], array_column($service->findingsForSupplier($supplier), 'key'));

        // No active catalogue requirement left: Leverandørkontroll says nothing at all — only v1 speaks.
        $standard->forceFill(['status' => SupplierControlRequirement::STATUS_RETIRED])->save();
        $important->forceFill(['status' => SupplierControlRequirement::STATUS_RETIRED])->save();
        $this->assertSame(['not_assessed'], array_column($service->findingsForSupplier($supplier), 'key'));
        $this->assertSame(1, SupplierRequirementEvaluation::query()->where('supplier_id', $supplier->id)->count());

        // Nor does it plan anything for a requirement that no longer applies.
        $page = $this->actingAs($reader)->get("/app/supplier-management/{$supplier->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame([], $page['follow_up_plan']['entries']);
    }

    public function test_register_and_page_share_the_findings_batch_equals_single_and_nothing_crosses_customers_or_is_written(): void
    {
        ['customer' => $customer, 'reader' => $reader, 'mandatory' => $mandatory, 'important' => $important] = $this->catalogue();
        ['customer' => $foreign, 'reader' => $foreignReader, 'mandatory' => $foreignMandatory] = $this->catalogue();
        $overdue = $this->supplier($customer, $reader, 'A Forfalt AS');
        $this->documented($overdue, $mandatory);
        $this->evaluate($overdue, $important, 'documented', '2026-01-01');
        $calm = $this->supplier($customer, $reader, 'B Rolig AS');
        $this->documented($calm, $mandatory);
        $this->documented($calm, $important);
        $ended = $this->supplier($customer, $reader, 'C Avsluttet AS', Supplier::STATUS_ENDED);
        $foreignSupplier = $this->supplier($foreign, $foreignReader, 'D Annen kunde AS');
        $this->documented($foreignSupplier, $foreignMandatory);

        $writes = fn (): array => [SupplierRequirementEvaluation::query()->count(), SupplierAssuranceDecision::query()->count()];
        $before = $writes();

        // Batch and single agree.
        $service = app(SupplierAttentionService::class);
        $batch = $service->findingsForSuppliers(collect([$overdue, $calm]));
        $this->assertSame($batch[$overdue->id], $service->findingsForSupplier($overdue));
        $this->assertSame($batch[$calm->id], $service->findingsForSupplier($calm));
        $this->assertSame([], $batch[$calm->id]);

        // The register: the panel and the existing «trenger oppmerksomhet» filter carry the new signal,
        // and nothing of the other customer's.
        $index = $this->actingAs($reader)->get('/app/supplier-management?attention=1')->assertOk()->viewData('page')['props'];
        $this->assertSame(['A Forfalt AS'], array_column($index['suppliers'], 'name'));
        $this->assertSame(1, $index['attention']['total']);
        $this->assertSame([['key' => 'control_overdue', 'count' => 1]], $index['attention']['categories']);
        $this->assertSame($batch[$overdue->id], $index['attention']['suppliers'][0]['findings']);
        $foreignIndex = $this->actingAs($foreignReader)->get('/app/supplier-management')->viewData('page')['props'];
        $this->assertSame(['D Annen kunde AS'], array_column($foreignIndex['attention']['suppliers'], 'name'));

        // The page (supplier.view only): the same findings, and Neste kontroller with the overdue
        // control first. An ended supplier has no plan and no findings.
        $page = $this->actingAs($reader)->get("/app/supplier-management/{$overdue->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame($batch[$overdue->id], $page['attention']);
        $this->assertSame(['control', '2026-07-01', true, 'Sikkerhetsrapport'], [
            $page['follow_up_plan']['entries'][0]['kind'],
            $page['follow_up_plan']['entries'][0]['date'],
            $page['follow_up_plan']['entries'][0]['overdue'],
            $page['follow_up_plan']['entries'][0]['requirement']['title'],
        ]);
        $this->assertSame('2026-07-01', collect($page['control_requirements']['applicable'])->firstWhere('id', $important->id)['follow_up']['next_control_on']);
        $endedPage = $this->actingAs($reader)->get("/app/supplier-management/{$ended->id}")->assertOk()->viewData('page')['props'];
        $this->assertNull($endedPage['follow_up_plan']);
        $this->assertSame([], $endedPage['attention']);
        $this->actingAs($reader)->get("/app/supplier-management/{$foreignSupplier->id}")->assertNotFound();

        $this->assertSame($before, $writes());
    }

    /**
     * A customer with a reader and three requirements for every supplier: Obligatorisk (before the
     * contract, no interval), Viktig (ongoing, every 6 months) and Oppfølging (ongoing).
     *
     * @return array{customer: Customer, reader: User, mandatory: SupplierControlRequirement, important: SupplierControlRequirement, standard: SupplierControlRequirement}
     */
    private function catalogue(): array
    {
        ['customer' => $customer] = $this->context('grc');

        return [
            'customer' => $customer,
            'reader' => $this->supplierUser($customer, []),
            'mandatory' => $this->requirement($customer, 'DBA', 'mandatory', 'before_contract', null),
            'important' => $this->requirement($customer, 'Sikkerhetsrapport', 'important', 'ongoing', 6),
            'standard' => $this->requirement($customer, 'Kvalitetssystem', 'standard', 'ongoing', null),
        ];
    }

    private function requirement(Customer $customer, string $title, string $level, string $point, ?int $interval): SupplierControlRequirement
    {
        return SupplierControlRequirement::query()->create([
            'customer_id' => $customer->id, 'title' => $title, 'theme' => 'privacy', 'level' => $level,
            'control_point' => $point, 'control_interval_months' => $interval, 'applies_when' => [],
        ]);
    }

    private function document(Supplier $supplier, string $title, ?string $validUntil): SupplierDocument
    {
        return SupplierDocument::query()->create([
            'customer_id' => $supplier->customer_id, 'supplier_id' => $supplier->id,
            'document_type' => 'audit_report', 'title' => $title, 'valid_until' => $validUntil,
        ]);
    }

    /** Dokumentert today, on a document without expiry. */
    private function documented(Supplier $supplier, SupplierControlRequirement $requirement): void
    {
        $this->evaluate($supplier, $requirement, 'documented', self::TODAY, documents: [$this->document($supplier, $requirement->title.' dokument', null)]);
    }

    /**
     * A control written directly, as SupplierRequirementEvaluationService would store it.
     *
     * @param  list<SupplierDocument>  $documents
     */
    private function evaluate(Supplier $supplier, SupplierControlRequirement $requirement, string $status, string $on, ?string $acceptedUntil = null, array $documents = []): void
    {
        $id = DB::table('supplier_requirement_evaluations')->insertGetId([
            'customer_id' => $supplier->customer_id, 'supplier_id' => $supplier->id, 'requirement_id' => $requirement->id,
            'status' => $status, 'rationale' => 'Kontrollert.', 'accepted_until' => $acceptedUntil, 'evaluated_on' => $on,
            'recorded_at' => now(), 'requirement_title' => $requirement->title, 'requirement_level' => $requirement->level,
            'requirement_theme' => $requirement->theme, 'applicability_reason' => 'Gjelder alle leverandører', 'supplier_name' => $supplier->name,
        ]);

        foreach ($documents as $document) {
            DB::table('supplier_requirement_evaluation_documents')->insert([
                'customer_id' => $supplier->customer_id, 'evaluation_id' => $id, 'supplier_document_id' => $document->id,
                'document_type' => $document->document_type, 'document_title' => $document->title, 'document_valid_until' => $document->valid_until,
            ]);
        }
    }

    private function decide(Supplier $supplier, string $decision): void
    {
        DB::table('supplier_assurance_decisions')->insert([
            'customer_id' => $supplier->customer_id, 'supplier_id' => $supplier->id, 'decision' => $decision, 'rationale' => 'Besluttet.',
            'decided_on' => self::TODAY, 'recorded_at' => now(), 'state_snapshot' => '{}', 'supplier_name' => $supplier->name,
        ]);
    }
}
