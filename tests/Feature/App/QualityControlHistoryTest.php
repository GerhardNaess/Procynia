<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\QualityActivityControl;
use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\Quality\QualityActivityControlService;
use App\Services\Quality\QualityItemService;
use App\Services\Quality\QualityProcessBlueprintService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * A control with registered evidence is retired, never deleted — finding A19
 * (docs/external-api-phase-0-plan.md §2f).
 *
 * Registered evidence is the record that a control was carried out. Deleting the control used to
 * cascade it away. Now such a control is set to «Utgått» — the status that already closes a control
 * to new links — and keeps everything. The rule holds in the menu, the controller, the service and
 * the database; only the customer's own deletion takes it along. A control that only sits on
 * activities, uses a tool or is linked from other modules is deleted as before.
 */
class QualityControlHistoryTest extends TestCase
{
    use CreatesComplianceScenarios;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
        Queue::fake();
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

    /** Registered evidence blocks the delete — in the service and in the database. */
    public function test_a_control_with_registered_evidence_is_not_deleted(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();

        foreach (['with a file-less record' => null, 'with a file' => $this->document($customer)] as $kind => $document) {
            $control = $this->control($customer, owner: $owner, title: "Kontroll {$kind}");
            $evidence = app(QualityItemService::class)->addControlEvidence((int) $customer->id, $control, 'Signert skjema', null, $document, $owner);

            try {
                app(QualityItemService::class)->deleteItem((int) $customer->id, $control, $owner);
                $this->fail("{$kind}: the service refuses");
            } catch (ValidationException $e) {
                $this->assertSame(__('procynia.quality.errors.control_with_history_not_deletable'), $e->errors()['item'][0], $kind);
            }

            $this->assertRefused(fn () => DB::table('quality_items')->where('id', $control->id)->delete(), "{$kind}: deleting in SQL");
            $this->assertNotNull(QualityItem::query()->find($control->id), $kind);
            $this->assertNotNull(QualityItemDocument::query()->find($evidence->id), $kind);
        }
    }

    /**
     * Links from Risiko and Etterlevelse do not block, as before: Kvalitet is not told about them
     * (RiskControlLinkTest, ComplianceQualityContextTest, ComplianceAuditFindingTest), and a refusal
     * would tell. Whether they should is an open product decision (A19 in the plan).
     */
    public function test_links_from_other_modules_do_not_block_as_before(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $control = $this->control($customer, owner: $owner);
        $riskId = $this->risk($customer);
        DB::table('risk_controls')->insert(['customer_id' => $customer->id, 'risk_id' => $riskId, 'quality_item_id' => $control->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertNull(app(QualityItemService::class)->deletionBlocker($control));

        app(QualityItemService::class)->deleteItem((int) $customer->id, $control, $owner);
        $this->assertNull(QualityItem::query()->find($control->id));
        $this->assertTrue(DB::table('risks')->where('id', $riskId)->exists());
    }

    /** Placements and tools are Kvalitet's own structure: such a control is deleted as before. */
    public function test_a_control_without_history_is_deleted_with_its_placements(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $control = $this->control($customer, owner: $owner);
        $placement = $this->placeOnActivity($customer, $owner, $control);

        app(QualityItemService::class)->deleteItem((int) $customer->id, $control, $owner);

        $this->assertNull(QualityItem::query()->find($control->id));
        $this->assertNull(QualityActivityControl::query()->find($placement->id));
    }

    /** «Utgått» keeps the evidence, the placements and the links, and closes the control to new ones. */
    public function test_a_retired_control_keeps_everything_and_takes_no_new_placements(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $control = $this->control($customer, owner: $owner);
        $placement = $this->placeOnActivity($customer, $owner, $control);
        $evidence = app(QualityItemService::class)->addControlEvidence((int) $customer->id, $control, 'Signert skjema', 'Kvartal 3.', null, $owner);
        $riskId = $this->risk($customer);
        DB::table('risk_controls')->insert(['customer_id' => $customer->id, 'risk_id' => $riskId, 'quality_item_id' => $control->id, 'created_at' => now(), 'updated_at' => now()]);

        app(QualityItemService::class)->updateItem((int) $customer->id, $control, ['status' => QualityItem::STATUS_RETIRED], $owner);

        $this->assertSame(QualityItem::STATUS_RETIRED, $control->fresh()->status);
        $this->assertNotNull(QualityItemDocument::query()->find($evidence->id));
        $this->assertNotNull(QualityActivityControl::query()->find($placement->id));
        $this->assertTrue(DB::table('risk_controls')->where('risk_id', $riskId)->where('quality_item_id', $control->id)->exists());

        $this->expectException(ValidationException::class);
        app(QualityActivityControlService::class)->place((int) $customer->id, [['process_id' => $placement->quality_item_id, 'activity_key' => 'annet', 'control_item_id' => $control->id]], $owner);
    }

    /** The register's menu gives the reason, and the controller answers a delete anyway with it. */
    public function test_the_page_offers_no_delete_and_the_controller_refuses_one(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $withEvidence = $this->control($customer, owner: $owner, title: 'Med evidens');
        app(QualityItemService::class)->addControlEvidence((int) $customer->id, $withEvidence, 'Signert skjema', null, null, $owner);
        $plain = $this->control($customer, owner: $owner, title: 'Uten historikk');

        $rows = collect($this->actingAs($owner)->get('/app/quality?tab=controls')->viewData('page')['props']['items'])->keyBy('id');
        $this->assertSame(__('procynia.quality.errors.control_with_history_not_deletable'), $rows[$withEvidence->id]['delete_blocked_message']);
        $this->assertNull($rows[$plain->id]['delete_blocked_message']);

        $this->actingAs($owner)->delete("/app/quality/items/{$withEvidence->id}?tab=controls")
            ->assertSessionHas('error', __('procynia.quality.errors.control_with_history_not_deletable'));
        $this->assertNotNull(QualityItem::query()->find($withEvidence->id));
    }

    /** The one deletion that takes a control with history along: the customer's own. */
    public function test_deleting_the_customer_takes_controls_with_history_along(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $control = $this->control($customer, owner: $owner);
        app(QualityItemService::class)->addControlEvidence((int) $customer->id, $control, 'Signert skjema', null, null, $owner);

        DB::table('customers')->where('id', $customer->id)->delete();

        $this->assertNull(QualityItem::query()->find($control->id));
    }

    private function assertRefused(callable $statement, string $what): void
    {
        DB::beginTransaction();

        try {
            $statement();
            $this->fail("the database must refuse {$what}");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
    }

    private function control(Customer $customer, User $owner, string $title = 'Fire øyne'): QualityItem
    {
        return app(QualityItemService::class)->createItem((int) $customer->id, ['quality_type' => QualityItem::TYPE_CONTROL, 'title' => $title], $owner);
    }

    private function placeOnActivity(Customer $customer, User $owner, QualityItem $control): QualityActivityControl
    {
        $process = QualityItem::query()->create(['customer_id' => $customer->id, 'quality_type' => QualityItem::TYPE_PROCESS, 'title' => 'Innkjøp', 'status' => QualityItem::STATUS_DRAFT]);
        app(QualityProcessBlueprintService::class)->store((int) $customer->id, $process, [
            'lanes' => [['key' => 'innkjop', 'label' => 'Innkjøper']],
            'nodes' => [
                ['key' => 'start', 'lane' => 'innkjop', 'type' => 'start', 'label' => 'Start'],
                ['key' => 'steg', 'lane' => 'innkjop', 'type' => 'step', 'label' => 'Godkjenn'],
                ['key' => 'annet', 'lane' => 'innkjop', 'type' => 'step', 'label' => 'Arkiver'],
                ['key' => 'slutt', 'lane' => 'innkjop', 'type' => 'end', 'label' => 'Slutt'],
            ],
            'edges' => [['from' => 'start', 'to' => 'steg'], ['from' => 'steg', 'to' => 'annet'], ['from' => 'annet', 'to' => 'slutt']],
        ], QualityProcessBlueprint::SOURCE_MANUAL, $owner);

        return app(QualityActivityControlService::class)->place((int) $customer->id, [['process_id' => $process->id, 'activity_key' => 'steg', 'control_item_id' => $control->id]], $owner)[0];
    }

    private function document(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'kontrollskjema.pdf',
            'file_path' => sprintf('customers/%d/wiki-documents/%s', $customer->id, Str::ulid()),
            'file_hash_sha256' => hash('sha256', Str::random(16)),
            'extracted_text' => 'Kontrollskjema',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function risk(Customer $customer): int
    {
        $areaId = DB::table('business_areas')->insertGetId(['customer_id' => $customer->id, 'name' => 'Drift '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('risks')->insertGetId(['customer_id' => $customer->id, 'business_area_id' => $areaId, 'title' => 'Feil utbetaling', 'status' => 'identified', 'created_at' => now(), 'updated_at' => now()]);
    }
}
