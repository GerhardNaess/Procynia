<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\EnterpriseWikiDocument;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityItem;
use App\Models\QualityItemDocument;
use App\Models\QualityItemRelation;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\Quality\QualityActivityControlService;
use App\Services\Quality\QualityAttentionService;
use App\Services\Quality\QualityItemService;
use App\Services\Quality\QualityProcessBlueprintService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Oversikt's "Trenger oppmerksomhet": four fixed rules over Quality's own rows.
 *
 * Each rule is checked from both sides — the object that trips it and the change that clears it —
 * plus the two things every rule shares: retired items are left out, and nothing crosses the
 * tenant boundary. quality.view is enough to read it.
 */
class QualityAttentionTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_quality_view_reads_the_findings_on_oversikt_only(): void
    {
        $customer = $this->customer();
        $reader = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $control = $this->item($customer, QualityItem::TYPE_CONTROL, 'Tilgangsgjennomgang');

        $attention = $this->actingAs($reader)->get('/app/quality')
            ->assertOk()->viewData('page')['props']['attention'];

        $this->assertSame([
            QualityAttentionService::CONTROLS_WITHOUT_EVIDENCE,
            QualityAttentionService::CONTROLS_WITHOUT_ACTIVITY,
            QualityAttentionService::PROCESSES_WITHOUT_GOVERNING_POLICY,
            QualityAttentionService::PROCESSES_OVERDUE_FOR_REVIEW,
        ], array_column($attention, 'key'));
        $this->assertSame([(int) $control->id], array_column($attention[0]['items'], 'id'));
        $this->assertSame(route('app.quality.items.show', ['item' => $control->id]), $attention[0]['items'][0]['url']);

        // Computed for Oversikt only; the other tabs do not pay for it.
        $this->assertSame([], $this->actingAs($reader)->get('/app/quality?tab=controls')
            ->assertOk()->viewData('page')['props']['attention']);

        $outsider = $this->member($customer, []);
        $this->actingAs($outsider)->get('/app/quality')->assertForbidden();
    }

    public function test_a_control_leaves_the_evidence_finding_once_evidence_is_recorded_and_stays_out_when_its_file_is_deleted(): void
    {
        $customer = $this->customer();
        $control = $this->item($customer, QualityItem::TYPE_CONTROL, 'Tilgangsgjennomgang');

        $this->assertSame([(int) $control->id], $this->ids($customer, QualityAttentionService::CONTROLS_WITHOUT_EVIDENCE));

        $document = $this->document($customer, 'gjennomgang-q3.pdf');
        app(QualityItemService::class)->addControlEvidence((int) $customer->id, $control, 'Gjennomgang Q3', null, $document);

        $this->assertSame([], $this->ids($customer, QualityAttentionService::CONTROLS_WITHOUT_EVIDENCE));

        // Evidence outlives its file; the control was still carried out.
        QualityItemDocument::releaseEvidenceFromDocument($document);
        $document->delete();
        $this->assertSame([], $this->ids($customer, QualityAttentionService::CONTROLS_WITHOUT_EVIDENCE));
    }

    public function test_a_control_counts_as_placed_only_on_an_activity_that_still_exists(): void
    {
        $customer = $this->customer();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Avvikshåndtering');
        $blueprint = $this->blueprintFor($customer, $process, withAssessment: true);
        $unplaced = $this->item($customer, QualityItem::TYPE_CONTROL, 'Uplassert kontroll');

        $placed = app(QualityActivityControlService::class)
            ->add($process, $blueprint, 'vurder', 'Fire øyne', null)
            ->control;

        $this->assertSame([(int) $unplaced->id], $this->ids($customer, QualityAttentionService::CONTROLS_WITHOUT_ACTIVITY));

        // The activity is removed from the flow: the link row remains, but places the control nowhere.
        $this->blueprintFor($customer, $process, withAssessment: false);

        $this->assertEqualsCanonicalizing(
            [(int) $unplaced->id, (int) $placed->id],
            $this->ids($customer, QualityAttentionService::CONTROLS_WITHOUT_ACTIVITY),
        );
    }

    public function test_a_process_is_governed_only_by_a_policy_in_force(): void
    {
        $customer = $this->customer();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Avvikshåndtering');
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Kvalitetspolicy');

        $this->assertSame([(int) $process->id], $this->ids($customer, QualityAttentionService::PROCESSES_WITHOUT_GOVERNING_POLICY));

        app(QualityItemService::class)->relate((int) $customer->id, $policy, $process, QualityItemRelation::TYPE_GOVERNS);
        $this->assertSame([], $this->ids($customer, QualityAttentionService::PROCESSES_WITHOUT_GOVERNING_POLICY));

        $policy->update(['status' => QualityItem::STATUS_RETIRED]);
        $this->assertSame([(int) $process->id], $this->ids($customer, QualityAttentionService::PROCESSES_WITHOUT_GOVERNING_POLICY));
    }

    public function test_a_process_is_overdue_only_once_its_derived_review_date_has_passed(): void
    {
        $customer = $this->customer();
        $items = app(QualityItemService::class);
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Avvikshåndtering');
        $this->item($customer, QualityItem::TYPE_POLICY, 'Forfalt policy')
            ->forceFill(['next_review_at' => today()->subYear()])->save();

        // No review cycle: no date, nothing to be overdue against.
        $this->assertSame([], $this->ids($customer, QualityAttentionService::PROCESSES_OVERDUE_FOR_REVIEW));

        // Last reviewed 13 months ago on a 12-month cycle: due a month ago.
        $items->updateItem((int) $customer->id, $process, [
            'review_interval_months' => 12,
            'last_reviewed_at' => today()->subMonths(13)->toDateString(),
        ]);
        $this->assertSame([(int) $process->id], $this->ids($customer, QualityAttentionService::PROCESSES_OVERDUE_FOR_REVIEW));

        // Due today is not yet overdue.
        $items->updateItem((int) $customer->id, $process, ['last_reviewed_at' => today()->subMonths(12)->toDateString()]);
        $this->assertSame([], $this->ids($customer, QualityAttentionService::PROCESSES_OVERDUE_FOR_REVIEW));

        // Recording a fresh review clears it.
        $items->updateItem((int) $customer->id, $process, ['last_reviewed_at' => today()->subMonths(13)->toDateString()]);
        $items->updateItem((int) $customer->id, $process, ['last_reviewed_at' => today()->toDateString()]);
        $this->assertSame([], $this->ids($customer, QualityAttentionService::PROCESSES_OVERDUE_FOR_REVIEW));
    }

    public function test_retired_items_are_never_findings(): void
    {
        $customer = $this->customer();
        $this->item($customer, QualityItem::TYPE_CONTROL, 'Utgått kontroll', QualityItem::STATUS_RETIRED);
        $this->item($customer, QualityItem::TYPE_PROCESS, 'Utgått prosess', QualityItem::STATUS_RETIRED)
            ->forceFill(['review_interval_months' => 12, 'last_reviewed_at' => today()->subYears(2), 'next_review_at' => today()->subYear()])
            ->save();

        foreach (app(QualityAttentionService::class)->findings((int) $customer->id) as $finding) {
            $this->assertSame([], $finding['items'], $finding['key']);
        }
    }

    public function test_findings_never_cross_the_tenant_boundary(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $ownControl = $this->item($customer, QualityItem::TYPE_CONTROL, 'Egen kontroll');
        $ownProcess = $this->item($customer, QualityItem::TYPE_PROCESS, 'Egen prosess');
        $ownProcess->forceFill(['next_review_at' => today()->subDay()])->save();
        $this->item($other, QualityItem::TYPE_CONTROL, 'Annen kundes kontroll');
        $this->item($other, QualityItem::TYPE_PROCESS, 'Annen kundes prosess')
            ->forceFill(['next_review_at' => today()->subDay()])->save();

        $reader = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $attention = collect($this->actingAs($reader)->get('/app/quality')
            ->assertOk()->viewData('page')['props']['attention'])
            ->mapWithKeys(fn (array $finding): array => [$finding['key'] => array_column($finding['items'], 'id')]);

        $this->assertSame([(int) $ownControl->id], $attention[QualityAttentionService::CONTROLS_WITHOUT_EVIDENCE]);
        $this->assertSame([(int) $ownControl->id], $attention[QualityAttentionService::CONTROLS_WITHOUT_ACTIVITY]);
        $this->assertSame([(int) $ownProcess->id], $attention[QualityAttentionService::PROCESSES_WITHOUT_GOVERNING_POLICY]);
        $this->assertSame([(int) $ownProcess->id], $attention[QualityAttentionService::PROCESSES_OVERDUE_FOR_REVIEW]);
    }

    // ---------------------------------------------------------------------

    /** @return list<int> */
    private function ids(Customer $customer, string $key): array
    {
        $finding = collect(app(QualityAttentionService::class)->findings((int) $customer->id))->firstWhere('key', $key);

        return array_column($finding['items'], 'id');
    }

    private function item(Customer $customer, string $type, string $title, string $status = QualityItem::STATUS_DRAFT): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => $type,
            'title' => $title,
            'status' => $status,
        ]);
    }

    private function blueprintFor(Customer $customer, QualityItem $process, bool $withAssessment): QualityProcessBlueprint
    {
        $nodes = [['key' => 'start', 'lane' => 'rolle-1', 'type' => 'start', 'label' => 'Start']];
        $edges = [];

        if ($withAssessment) {
            $nodes[] = ['key' => 'vurder', 'lane' => 'rolle-1', 'type' => 'step', 'label' => 'Vurder avviket'];
            $edges[] = ['from' => 'start', 'to' => 'vurder'];
            $edges[] = ['from' => 'vurder', 'to' => 'slutt'];
        } else {
            $edges[] = ['from' => 'start', 'to' => 'slutt'];
        }

        $nodes[] = ['key' => 'slutt', 'lane' => 'rolle-1', 'type' => 'end', 'label' => 'Slutt'];

        return app(QualityProcessBlueprintService::class)->store(
            (int) $customer->id,
            $process,
            ['lanes' => [['key' => 'rolle-1', 'label' => 'Kvalitetsleder']], 'nodes' => $nodes, 'edges' => $edges],
            QualityProcessBlueprint::SOURCE_MANUAL,
        );
    }

    private function document(Customer $customer, string $filename): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => $filename,
            'file_path' => sprintf('customers/%d/wiki-documents/%s', $customer->id, Str::ulid()),
            'file_hash_sha256' => hash('sha256', $filename.Str::random(8)),
            'extracted_text' => 'Innhold i '.$filename,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function member(Customer $customer, array $permissionKeys): User
    {
        $user = User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'oppmerksomhet-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);
        $role->syncPermissions($permissionKeys);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $user;
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        $customer = Customer::query()->create([
            'name' => 'Kvalitet Oppmerksomhet AS',
            'slug' => 'kvalitet-oppmerksomhet-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        CustomerPackageEntitlement::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'package_key' => 'quality'],
            ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
        );

        return $customer;
    }
}
