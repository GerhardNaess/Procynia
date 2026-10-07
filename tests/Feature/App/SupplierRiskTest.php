<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\SupplierRisk;
use App\Models\User;
use App\Services\Risk\RiskScoringPolicy;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Leverandøroppfølging phase 7: Risikoer som gjelder leverandøren
 * (docs/supplier-management-v1-plan.md §7.2, §6.3, §6.4, §9.2). Only the integration — the risk's
 * own rules are Risiko's tests, which stay green unchanged after RiskCreator was extracted. One test
 * per rule:
 *
 *  - «Opprett risiko» creates the risk through RiskCreator with what the person chose and writes the
 *    row in the same transaction; a supplier may have many risks and a risk many suppliers;
 *  - it takes supplier.edit and risk.create in the chosen area; linking and unlinking take risk.edit
 *    on the risk; an ended supplier is read-only; another customer's supplier is 404;
 *  - neither side shows the other's data to someone who cannot read it;
 *  - a supplier with risks is ended, never deleted; a risk Risiko deletes takes the row with it.
 */
class SupplierRiskTest extends TestCase
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

    public function test_create_goes_through_the_risk_creator_and_a_supplier_may_have_many_risks(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $area = $this->area($customer, 'Innkjøp');
        $viewOnly = $this->area($customer, 'Økonomi');
        $manager = $this->riskManager($customer, $area);
        $this->grant($customer, $manager, [CustomerPermissionCatalog::RISK_VIEW], [$viewOnly]);
        $owner = $this->member($customer);
        $this->grant($customer, $owner, [CustomerPermissionCatalog::RISK_VIEW], [$area]);
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $other = $this->supplier($customer, $manager, 'Beta AS');
        $url = "/app/supplier-management/{$supplier->id}/risks";

        // RiskCreator's own rules, refused before anything is written: årsak/hendelse/konsekvens are
        // required, the area must be one the person creates in, the owner one who reads risks there.
        foreach ([
            'cause' => ['cause' => ''],
            'business_area_id' => ['business_area_id' => $viewOnly->id],
            'owner_user_id' => ['owner_user_id' => $this->member($customer)->id],
        ] as $field => $override) {
            $this->actingAs($manager)->post($url, $this->riskPayload($area, $owner, $override))->assertSessionHasErrors($field);
        }
        $this->assertSame([0, 0], [Risk::query()->where('customer_id', $customer->id)->count(), SupplierRisk::query()->count()]);

        // An ordinary risk: Identifisert, in the chosen area, created by the person; the row says only
        // that it came from the supplier.
        $this->actingAs($manager)->post($url, $this->riskPayload($area, $owner))->assertSessionHasNoErrors();
        $risk = Risk::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(
            ['Leverandør: Acme AS', 'Nøkkelperson slutter', $area->id, $owner->id, Risk::STATUS_IDENTIFIED, $manager->id, null],
            [$risk->title, $risk->cause, (int) $risk->business_area_id, (int) $risk->owner_user_id, $risk->status, (int) $risk->created_by, $risk->treatment_strategy],
        );
        $link = $supplier->riskLinks()->sole();
        $this->assertSame([SupplierRisk::ORIGIN_CREATED_FROM_SUPPLIER, $customer->id], [$link->origin, (int) $link->customer_id]);

        // Many per supplier, and the same risk may concern another supplier.
        $this->actingAs($manager)->post($url, $this->riskPayload($area, $owner, ['title' => 'Beta: datatap', 'owner_user_id' => null]))->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/app/supplier-management/{$other->id}/risks/link", ['risk_id' => $risk->id])->assertSessionHasNoErrors();

        // The page reads title, area, status and level from Risiko now — residual, else inherent.
        RiskAssessment::query()->create([
            'customer_id' => $customer->id, 'risk_id' => $risk->id, 'inherent_likelihood' => 4, 'inherent_consequence' => 4,
            'rationale' => 'Ingen backup av kompetanse.', 'assessed_at' => now(), 'assessed_by' => $manager->id, 'criteria_key' => RiskScoringPolicy::CRITERIA_STANDARD_5X5,
        ]);
        $rows = $this->actingAs($manager)->get("/app/supplier-management/{$supplier->id}")->assertOk()->viewData('page')['props']['risks'];
        $this->assertSame(
            [['Beta: datatap', 'Innkjøp', 'identified', null, 'created_from_supplier'], ['Leverandør: Acme AS', 'Innkjøp', 'identified', 'inherent', 'created_from_supplier']],
            array_map(fn (array $row): array => [$row['title'], $row['area_name'], $row['status'], $row['level']['kind'] ?? null, $row['origin']], $rows),
        );

        // The risk page names both suppliers for someone who reads both.
        $origin = $this->actingAs($manager)->get("/app/risk/risks/{$risk->id}")->assertOk()->viewData('page')['props']['supplier_origin'];
        $this->assertSame([['Acme AS', true], ['Beta AS', false]], array_map(fn (array $row): array => [$row['name'], $row['from_supplier']], $origin));
    }

    public function test_it_takes_supplier_edit_and_risk_rights_ended_is_read_only_and_other_customers_are_404(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $other] = $this->context('grc');
        $area = $this->area($customer, 'Innkjøp');
        $hiddenArea = $this->area($customer, 'Ledelse');
        $manager = $this->riskManager($customer, $area);
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $url = "/app/supplier-management/{$supplier->id}/risks";

        // supplier.assess is not enough; supplier.edit without Risiko hears nothing about risks, is
        // offered nothing, and a direct request is refused.
        $assessor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSESS]);
        $this->grant($customer, $assessor, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_CREATE], [$area]);
        $this->actingAs($assessor)->post($url, $this->riskPayload($area, null))->assertForbidden();
        $editorOnly = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $page = $this->actingAs($editorOnly)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props'];
        $this->assertSame([null, null], [$page['risks'], $page['risk_handoff']]);
        $this->actingAs($editorOnly)->post($url, $this->riskPayload($area, null))->assertSessionHasErrors('business_area_id');
        $this->assertFalse(Risk::query()->where('customer_id', $customer->id)->exists());

        // Koble til: a risk the person can edit, once. Hidden or view-only risks are neither offered nor accepted.
        $editable = $this->risk($customer, $area, 'Registrert i Risiko');
        $hidden = $this->risk($customer, $hiddenArea, 'Utenfor rekkevidde');
        $viewer = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $this->grant($customer, $viewer, [CustomerPermissionCatalog::RISK_VIEW], [$area]);
        $this->assertSame([['Registrert i Risiko']], array_map(fn (array $o): array => [$o['title']], $this->actingAs($manager)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['risk_handoff']['link_options']));
        $this->assertSame([], $this->actingAs($viewer)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props']['risk_handoff']['link_options']);
        $this->actingAs($manager)->post("{$url}/link", ['risk_id' => $hidden->id])->assertSessionHasErrors('risk_id');
        $this->actingAs($viewer)->post("{$url}/link", ['risk_id' => $editable->id])->assertSessionHasErrors('risk_id');
        $this->actingAs($manager)->post("{$url}/link", ['risk_id' => $editable->id])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("{$url}/link", ['risk_id' => $editable->id])->assertSessionHasErrors('risk_id');

        // Fjerne: risk.edit on the risk, whichever way it came; the risk itself stays.
        $this->actingAs($manager)->post($url, $this->riskPayload($area, null))->assertSessionHasNoErrors();
        $created = $supplier->riskLinks()->where('origin', SupplierRisk::ORIGIN_CREATED_FROM_SUPPLIER)->sole();
        $this->actingAs($viewer)->delete("{$url}/{$created->id}")->assertForbidden();
        $this->actingAs($manager)->delete("{$url}/{$created->id}")->assertSessionHas('success');
        $this->assertTrue(Risk::query()->whereKey($created->risk_id)->exists());
        $this->assertSame([SupplierRisk::ORIGIN_LINKED], $supplier->riskLinks()->pluck('origin')->all());

        // Ended: nothing new and nothing removed until reopened; the risk already there is still shown.
        $this->actingAs($manager)->post("/app/supplier-management/{$supplier->id}/end", ['reason' => 'Avtalen er sagt opp.'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post($url, $this->riskPayload($area, null))->assertSessionHasErrors('title');
        $this->actingAs($manager)->delete("{$url}/".$supplier->riskLinks()->value('id'))->assertSessionHasErrors('risk_id');
        $page = $this->actingAs($manager)->get("/app/supplier-management/{$supplier->id}")->viewData('page')['props'];
        $this->assertSame([null, ['Registrert i Risiko']], [$page['risk_handoff'], array_column($page['risks'], 'title')]);
        $this->assertSame(3, Risk::query()->where('customer_id', $customer->id)->count());

        // Another customer's supplier: 404; no row can cross customers.
        $foreign = $this->supplier($other, $this->supplierUser($other, []), 'Fremmed AS');
        $this->actingAs($manager)->post("/app/supplier-management/{$foreign->id}/risks", $this->riskPayload($area, null))->assertNotFound();
        $this->assertDatabaseRefuses(fn () => DB::table('supplier_risks')->insert([
            'customer_id' => $customer->id, 'supplier_id' => $foreign->id, 'risk_id' => $editable->id, 'origin' => 'linked', 'created_at' => now(),
        ]), 'a link across customers');
    }

    public function test_neither_side_shows_the_other_to_someone_who_cannot_read_it(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $area = $this->area($customer, 'Innkjøp');
        $hiddenArea = $this->area($customer, 'Ledelse');
        $manager = $this->riskManager($customer, $area);
        $this->grant($customer, $manager, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_CREATE], [$hiddenArea]);
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        foreach ([[$area, 'Synlig risiko'], [$hiddenArea, 'Skjult risiko']] as [$riskArea, $title]) {
            $this->actingAs($manager)->post("/app/supplier-management/{$supplier->id}/risks", $this->riskPayload($riskArea, null, ['title' => $title]))->assertSessionHasNoErrors();
        }

        // A supplier reader who sees only one area: the other risk is not listed, counted or named.
        $reader = $this->supplierUser($customer, []);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::RISK_VIEW], [$area]);
        $response = $this->actingAs($reader)->get("/app/supplier-management/{$supplier->id}")->assertOk();
        $this->assertSame(['Synlig risiko'], array_column($response->viewData('page')['props']['risks'], 'title'));
        $this->assertStringNotContainsString('Skjult risiko', $response->getContent());
        $this->assertStringNotContainsString('Ledelse', $response->getContent());

        // A risk reader without supplier.view, and a System Owner without a supplier role, see no supplier.
        $visibleRisk = Risk::query()->where('title', 'Synlig risiko')->sole();
        $riskReader = $this->member($customer);
        $this->grant($customer, $riskReader, [CustomerPermissionCatalog::RISK_VIEW], [$area]);
        $systemOwner = $this->member($customer);
        $systemOwner->forceFill(['bid_role' => User::BID_ROLE_SYSTEM_OWNER])->save();
        $this->grant($customer, $systemOwner, [CustomerPermissionCatalog::RISK_VIEW], [$area]);
        foreach ([$riskReader, $systemOwner->fresh()] as $person) {
            $response = $this->actingAs($person)->get("/app/risk/risks/{$visibleRisk->id}")->assertOk();
            $this->assertNull($response->viewData('page')['props']['supplier_origin']);
            $this->assertStringNotContainsString('Acme AS', $response->getContent());
        }
        $this->assertSame('Acme AS', $this->actingAs($reader)->get("/app/risk/risks/{$visibleRisk->id}")->viewData('page')['props']['supplier_origin'][0]['name']);
    }

    public function test_a_supplier_with_risks_is_ended_never_deleted_and_a_deleted_risk_takes_its_row_along(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $area = $this->area($customer, 'Innkjøp');
        $manager = $this->riskManager($customer, $area, [CustomerPermissionCatalog::SUPPLIER_DELETE]);
        $this->grant($customer, $manager, [CustomerPermissionCatalog::RISK_DELETE], [$area]);
        $supplier = $this->supplier($customer, $manager, 'Acme AS');
        $this->actingAs($manager)->post("/app/supplier-management/{$supplier->id}/risks", $this->riskPayload($area, null))->assertSessionHasNoErrors();

        $this->assertFalse($supplier->fresh()->isDeletable());
        $this->actingAs($manager)->delete("/app/supplier-management/{$supplier->id}")->assertSessionHas('error');
        $this->assertDatabaseRefuses(fn () => DB::table('suppliers')->where('id', $supplier->id)->delete(), 'deleting a supplier with risks');

        // Risiko's own delete rule is unchanged: the risk is deleted there and takes the row with it.
        $riskId = $supplier->riskLinks()->value('risk_id');
        $this->actingAs($manager)->delete("/app/risk/risks/{$riskId}")->assertRedirect('/app/risk');
        $this->assertFalse($supplier->riskLinks()->exists());
        $this->assertTrue($supplier->fresh()->isDeletable());
    }

    /**
     * supplier.view + supplier.edit (+ extra supplier keys), and risk.view + .create + .edit in the area.
     *
     * @param  list<string>  $extra
     */
    private function riskManager(Customer $customer, BusinessArea $area, array $extra = []): User
    {
        $user = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, ...$extra]);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_CREATE, CustomerPermissionCatalog::RISK_EDIT], [$area]);

        return $user->fresh();
    }

    private function risk(Customer $customer, BusinessArea $area, string $title): Risk
    {
        return Risk::query()->create([
            'customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => $title,
            'cause' => 'Årsak', 'event' => 'Hendelse', 'consequence' => 'Konsekvens', 'status' => Risk::STATUS_IDENTIFIED,
        ]);
    }

    /** @return array<string, mixed> */
    private function riskPayload(BusinessArea $area, ?User $owner, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Leverandør: Acme AS',
            'cause' => 'Nøkkelperson slutter',
            'event' => 'Leveransen stopper opp',
            'consequence' => 'Tjenesten er utilgjengelig for innbyggerne',
            'business_area_id' => $area->id,
            'owner_user_id' => $owner?->id,
        ], $overrides);
    }
}
