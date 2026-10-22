<?php

namespace Tests\Feature\App;

use App\Exceptions\HumanDecisionRefused;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\QualityItem;
use App\Models\User;
use App\Services\Quality\QualityItemService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
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
 * A process or control another module points at is retired, never deleted — findings A19 and A28,
 * decided 2026-10-22 (docs/external-api-phase-0-plan.md §2h).
 *
 * Risiko, Mål og KPI, Avvik og forbedringer and Etterlevelse og revisjon link to Kvalitet's
 * processes, activities and controls. Deleting the item used to take those links away. Now it is
 * refused — in the service, the controller and the database — even for someone who cannot see the
 * module that made the link, and the refusal is one general sentence that does not tell which
 * module, record or person is behind it. This deliberately replaces the earlier rule that Kvalitet
 * is not affected by links it cannot see.
 */
class QualityCrossModuleLinkTest extends TestCase
{
    use CreatesComplianceScenarios;
    use UsesProjectPostgresConnection;

    /** Words that would give away where the link comes from. */
    private const TELLTALES = ['risiko', 'risk', 'kpi', 'mål', 'avvik', 'forbedring', 'krav', 'etterlevelse', 'revisjon', 'funn', 'Konfidensiell'];

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

    /** Every kind of link, alone, blocks the delete — with the same general sentence. */
    public function test_every_link_from_another_module_blocks_and_says_the_same_thing(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $messages = [];

        foreach ($this->links($customer, $owner) as $kind => [$type, $link]) {
            $item = $this->item($customer, $type, "Element {$kind}");
            $link($item);

            try {
                app(QualityItemService::class)->deleteItem((int) $customer->id, $item, $owner);
                $this->fail("{$kind}: the service refuses");
            } catch (ValidationException $e) {
                $messages[$type][] = $e->errors()['item'][0];
            }

            $this->assertRefused(fn () => DB::table('quality_items')->where('id', $item->id)->delete(), "{$kind}: deleting in SQL");
            $this->assertNotNull(QualityItem::query()->find($item->id), $kind);
        }

        $this->assertSame([__('procynia.quality.errors.in_use_not_deletable')], array_values(array_unique($messages[QualityItem::TYPE_PROCESS])));
        $this->assertSame([__('procynia.quality.errors.in_use_not_deletable')], array_values(array_unique($messages[QualityItem::TYPE_CONTROL])));

        foreach ([__('procynia.quality.errors.in_use_not_deletable'), __('procynia.quality.errors.in_use_not_deletable')] as $message) {
            foreach (self::TELLTALES as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $message);
            }
        }
    }

    /**
     * Hidden links stay hidden: a deleter without Risiko gets the general sentence and nothing more;
     * a reader is told nothing at all, because they are offered no delete.
     */
    public function test_a_hidden_link_is_never_named_and_never_shown_to_a_reader(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $control = $this->item($customer, QualityItem::TYPE_CONTROL, 'Avstemming');
        $this->riskLink($customer, 'risk_controls', 'quality_item_id', $control, 'Konfidensiell risikotittel');

        $deleter = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_DELETE]);
        $reader = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);

        $response = $this->actingAs($deleter)->delete("/app/quality/items/{$control->id}?tab=controls");
        $response->assertRedirect('/app/quality?tab=controls')
            ->assertSessionHas('error', __('procynia.quality.errors.in_use_not_deletable'));
        $this->assertNotNull(QualityItem::query()->find($control->id));

        $deleterRow = collect($this->actingAs($deleter)->get('/app/quality?tab=controls')->viewData('page')['props']['items'])->firstWhere('id', $control->id);
        $this->assertTrue($deleterRow['in_use']);

        $readerPage = $this->actingAs($reader)->get('/app/quality?tab=controls');
        $this->assertFalse(collect($readerPage->viewData('page')['props']['items'])->firstWhere('id', $control->id)['in_use']);
        $this->assertStringNotContainsString('Konfidensiell', $readerPage->getContent());
        // (The sentence itself ships with the page's translations for everyone; what must not reach a
        // reader is that it applies to this row.)

        // A reader asking to delete anyway is refused for lacking the key, before any rule is read.
        $this->actingAs($reader)->delete("/app/quality/items/{$control->id}")->assertForbidden();
    }

    /** The same answer whether the link is from a module the deleter can see or one they cannot. */
    public function test_the_answer_does_not_depend_on_what_the_deleter_can_see(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $hidden = $this->item($customer, QualityItem::TYPE_PROCESS, 'Skjult kobling');
        $this->riskLink($customer, 'risk_processes', 'quality_item_id', $hidden);
        $visible = $this->item($customer, QualityItem::TYPE_PROCESS, 'Synlig kobling');
        $this->riskLink($customer, 'risk_processes', 'quality_item_id', $visible);

        $deleter = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_DELETE]);
        $alsoRisk = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_DELETE, CustomerPermissionCatalog::RISK_VIEW]);

        $answers = [];
        foreach ([[$deleter, $hidden], [$alsoRisk, $visible]] as [$person, $item]) {
            $response = $this->actingAs($person)->delete("/app/quality/items/{$item->id}");
            $answers[] = [$response->getStatusCode(), session('error')];
        }

        $this->assertSame($answers[0], $answers[1]);
    }

    /** An integration cannot delete at all, links or not; nor can SQL. */
    public function test_an_integration_cannot_get_around_the_rule(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Innkjøp');
        $this->riskLink($customer, 'risk_processes', 'quality_item_id', $process);
        $integration = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_DELETE]);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();

        try {
            app(QualityItemService::class)->deleteItem((int) $customer->id, $process, $integration->fresh());
            $this->fail('an integration takes no delete decision');
        } catch (HumanDecisionRefused) {
            $this->addToAssertionCount(1);
        }

        $this->assertRefused(fn () => QualityItem::query()->whereKey($process->id)->delete(), 'deleting through the query builder');
        $this->assertNotNull(QualityItem::query()->find($process->id));
    }

    /** Another customer's deleter is refused for being in another customer, and learns nothing. */
    public function test_another_customer_is_refused_before_the_links_are_read(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Innkjøp');
        $this->riskLink($customer, 'risk_processes', 'quality_item_id', $process);
        ['customer' => $other, 'owner' => $otherOwner] = $this->complianceContext();

        $this->expectException(AuthorizationException::class);
        app(QualityItemService::class)->deleteItem((int) $customer->id, $process, $otherOwner);
    }

    /**
     * The link is the other module's to remove. Once its record lets go — a risk deleted in Risiko —
     * the item is free to be deleted again; and retiring it keeps the link in place.
     */
    public function test_retiring_keeps_the_link_and_the_other_module_still_decides_its_own(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $control = $this->item($customer, QualityItem::TYPE_CONTROL, 'Fire øyne');
        $riskId = $this->riskLink($customer, 'risk_controls', 'quality_item_id', $control);

        app(QualityItemService::class)->updateItem((int) $customer->id, $control, ['status' => QualityItem::STATUS_RETIRED], $owner);
        $this->assertSame(QualityItem::STATUS_RETIRED, $control->fresh()->status);
        $this->assertTrue(DB::table('risk_controls')->where('quality_item_id', $control->id)->exists());

        DB::table('risk_controls')->where('risk_id', $riskId)->delete();
        app(QualityItemService::class)->deleteItem((int) $customer->id, $control, $owner);
        $this->assertNull(QualityItem::query()->find($control->id));
    }

    /**
     * «Sett til Utgått» in the row menu is the ordinary status change (PATCH, quality.edit), for
     * every kind of item in use — linked, approved or with evidence. One click, nothing else.
     */
    public function test_an_item_in_use_is_retired_with_the_ordinary_status_change(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $linked = $this->item($customer, QualityItem::TYPE_PROCESS, 'Koblet prosess');
        $this->riskLink($customer, 'risk_processes', 'quality_item_id', $linked);
        $withEvidence = $this->item($customer, QualityItem::TYPE_CONTROL, 'Kontroll med evidens');
        app(QualityItemService::class)->addControlEvidence((int) $customer->id, $withEvidence, 'Skjema', null, null, $owner);
        $editor = $this->member($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT, CustomerPermissionCatalog::QUALITY_DELETE]);

        $rows = collect($this->actingAs($editor)->get('/app/quality?tab=overview')->viewData('page')['props']['items'])->keyBy('id');

        foreach ([$linked, $withEvidence] as $item) {
            $this->assertTrue($rows[$item->id]['in_use']);
            $this->actingAs($editor)->patch("/app/quality/items/{$item->id}", ['status' => QualityItem::STATUS_RETIRED])
                ->assertSessionHasNoErrors();
            $this->assertSame(QualityItem::STATUS_RETIRED, $item->fresh()->status);
        }

        $this->assertTrue(DB::table('risk_processes')->where('quality_item_id', $linked->id)->exists());
    }

    public function test_deleting_the_customer_takes_linked_items_along(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Innkjøp');
        $this->riskLink($customer, 'risk_processes', 'quality_item_id', $process);

        DB::table('customers')->where('id', $customer->id)->delete();

        $this->assertNull(QualityItem::query()->find($process->id));
    }

    /** @return array<string, array{0: string, 1: \Closure(QualityItem): void}> one of each link the trigger knows */
    private function links(Customer $customer, User $owner): array
    {
        $kpi = fn (): int => DB::table('kpis')->insertGetId(['customer_id' => $customer->id, 'objective_id' => DB::table('objectives')->insertGetId(['customer_id' => $customer->id, 'business_area_id' => $this->area($customer), 'title' => 'Mål', 'created_at' => now(), 'updated_at' => now()]), 'title' => 'KPI', 'unit' => 'count', 'target_min' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $case = fn (): int => DB::table('improvement_cases')->insertGetId(['customer_id' => $customer->id, 'business_area_id' => $this->area($customer), 'type' => 'deviation', 'title' => 'Sak', 'description' => 'Beskrivelse', 'created_at' => now(), 'updated_at' => now()]);
        $requirement = fn (): int => $this->complianceRequirement($this->complianceSource($customer), 'Krav')->id;
        $audit = fn (): int => $this->complianceAudit($customer, $owner)->id;
        $row = fn (string $table, array $values) => DB::table($table)->insert(['customer_id' => $customer->id, 'created_at' => now(), 'updated_at' => now()] + $values);
        $process = QualityItem::TYPE_PROCESS;
        $control = QualityItem::TYPE_CONTROL;

        return [
            'risk_processes' => [$process, fn (QualityItem $i) => $this->riskLink($customer, 'risk_processes', 'quality_item_id', $i)],
            'risk_activities' => [$process, fn (QualityItem $i) => $this->riskLink($customer, 'risk_activities', 'quality_item_id', $i, extra: ['activity_key' => 'steg'])],
            'kpi_processes' => [$process, fn (QualityItem $i) => $row('kpi_processes', ['kpi_id' => $kpi(), 'quality_process_id' => $i->id])],
            'kpi_activities' => [$process, fn (QualityItem $i) => $row('kpi_activities', ['kpi_id' => $kpi(), 'quality_process_id' => $i->id, 'activity_key' => 'steg'])],
            'improvement_case_processes' => [$process, fn (QualityItem $i) => $row('improvement_case_processes', ['improvement_case_id' => $case(), 'quality_process_id' => $i->id])],
            'improvement_case_activities' => [$process, fn (QualityItem $i) => $row('improvement_case_activities', ['improvement_case_id' => $case(), 'quality_process_id' => $i->id, 'activity_key' => 'steg'])],
            'compliance_requirement_processes' => [$process, fn (QualityItem $i) => $row('compliance_requirement_processes', ['requirement_id' => $requirement(), 'quality_process_id' => $i->id])],
            'compliance_audit_processes' => [$process, fn (QualityItem $i) => $row('compliance_audit_processes', ['audit_id' => $audit(), 'quality_process_id' => $i->id])],
            'compliance_audit_findings (prosess)' => [$process, fn (QualityItem $i) => DB::table('compliance_audit_findings')->insert(['customer_id' => $customer->id, 'audit_id' => $audit(), 'finding_type' => 'observation', 'title' => 'Funn', 'description' => 'Beskrivelse', 'quality_process_id' => $i->id, 'created_at' => now(), 'updated_at' => now()])],
            'risk_controls' => [$control, fn (QualityItem $i) => $this->riskLink($customer, 'risk_controls', 'quality_item_id', $i)],
            'compliance_requirement_controls' => [$control, fn (QualityItem $i) => $row('compliance_requirement_controls', ['requirement_id' => $requirement(), 'control_item_id' => $i->id])],
            'compliance_audit_findings (kontroll)' => [$control, fn (QualityItem $i) => DB::table('compliance_audit_findings')->insert(['customer_id' => $customer->id, 'audit_id' => $audit(), 'finding_type' => 'observation', 'title' => 'Funn', 'description' => 'Beskrivelse', 'control_item_id' => $i->id, 'created_at' => now(), 'updated_at' => now()])],
        ];
    }

    /** @param  array<string, mixed>  $extra */
    private function riskLink(Customer $customer, string $table, string $column, QualityItem $item, string $title = 'Feil utbetaling', array $extra = []): int
    {
        $riskId = DB::table('risks')->insertGetId(['customer_id' => $customer->id, 'business_area_id' => $this->area($customer), 'title' => $title, 'status' => 'identified', 'created_at' => now(), 'updated_at' => now()]);
        DB::table($table)->insert(['customer_id' => $customer->id, 'risk_id' => $riskId, $column => $item->id, 'created_at' => now(), 'updated_at' => now()] + $extra);

        return $riskId;
    }

    private function area(Customer $customer): int
    {
        return DB::table('business_areas')->insertGetId(['customer_id' => $customer->id, 'name' => 'Område '.Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function item(Customer $customer, string $type, string $title): QualityItem
    {
        return QualityItem::query()->create(['customer_id' => $customer->id, 'quality_type' => $type, 'title' => $title, 'status' => QualityItem::STATUS_DRAFT]);
    }

    /** @param  list<string>  $keys */
    private function member(Customer $customer, array $keys): User
    {
        $user = User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'kvalitet-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => 'Rolle '.Str::upper(Str::random(8)), 'is_active' => true]);
        $role->syncPermissions($keys);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $user;
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
}
