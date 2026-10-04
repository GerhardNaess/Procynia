<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityControlDetail;
use App\Models\QualityItem;
use App\Models\Risk;
use App\Models\RiskAccessArea;
use App\Models\RiskControl;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Risiko → håndteres av → Kontroll.
 *
 * What these tests defend:
 *
 *  - Only the relation is stored. The control stays the Kvalitet control it was; unlinking leaves it.
 *  - Linking takes risk.edit in the risk's area (same-role pair rule) AND the right to read controls
 *    in Kvalitet. Control information is shown only to someone who can read it in Kvalitet.
 *  - Only a `control` QualityItem of the risk's own customer can be linked.
 *  - A hidden or foreign risk is a 404 for linking and unlinking, as for reading.
 *  - Nothing about the link reaches Kvalitet, and a link never changes the risk assessment.
 */
class RiskControlLinkTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const RISK_EDITOR = [
        CustomerPermissionCatalog::RISK_VIEW,
        CustomerPermissionCatalog::RISK_EDIT,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
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

    public function test_a_control_is_linked_shown_from_kvalitet_and_unlinked_without_being_touched(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Feil lønnsutbetaling');
        $control = $this->control($customer, 'Månedlig lønnsavstemming', 'Lønn avstemmes mot hovedbok.');
        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::RISK_EDITOR, CustomerPermissionCatalog::QUALITY_VIEW], [$hr]);

        $props = $this->showProps($user, $risk);
        $this->assertSame([], $props['controls']);
        $this->assertTrue($props['permissions']['can_link_controls']);
        $this->assertSame([$control->id], array_column($props['control_options'], 'id'));

        $this->actingAs($user)->post($this->url($risk), ['quality_item_id' => $control->id])
            ->assertRedirect()->assertSessionHas('success');
        // Linking twice is the same link.
        $this->actingAs($user)->post($this->url($risk), ['quality_item_id' => $control->id])->assertRedirect();
        $this->assertSame(1, RiskControl::query()->where('risk_id', $risk->id)->count());
        $this->assertDatabaseHas('risk_controls', [
            'risk_id' => $risk->id,
            'quality_item_id' => $control->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
        ]);

        // Only ids: nothing about the control is copied onto the link.
        $this->assertEqualsCanonicalizing(
            ['id', 'customer_id', 'risk_id', 'quality_item_id', 'created_by', 'created_at', 'updated_at'],
            Schema::getColumnListing('risk_controls'),
        );

        // Read live: a change in Kvalitet is what the risk page shows.
        $control->update(['title' => 'Månedlig lønnsavstemming v2']);
        $props = $this->showProps($user, $risk);
        $this->assertCount(1, $props['controls']);
        $this->assertSame('Månedlig lønnsavstemming v2', $props['controls'][0]['title']);
        $this->assertSame('Lønn avstemmes mot hovedbok.', $props['controls'][0]['criterion']);
        $this->assertSame(route('app.quality.items.show', ['item' => $control->id]), $props['controls'][0]['url']);
        $this->assertSame([], $props['control_options']);

        // A link is not an assessment and changes none.
        $this->assertSame([], $props['assessments']);

        $this->actingAs($user)->delete("{$this->url($risk)}/{$control->id}")->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseMissing('risk_controls', ['risk_id' => $risk->id]);
        $this->assertDatabaseHas('quality_items', ['id' => $control->id, 'title' => 'Månedlig lønnsavstemming v2', 'quality_type' => 'control']);
        $this->assertDatabaseHas('quality_control_details', ['quality_item_id' => $control->id, 'criterion' => 'Lønn avstemmes mot hovedbok.']);

        // Unlinking what is not linked is a 404.
        $this->actingAs($user)->delete("{$this->url($risk)}/{$control->id}")->assertNotFound();
    }

    public function test_without_quality_view_the_risk_page_says_nothing_about_controls_and_cannot_link(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Nøkkelperson slutter');
        $control = $this->control($customer, 'Hemmelig kontrolltittel');
        RiskControl::query()->create(['customer_id' => $customer->id, 'risk_id' => $risk->id, 'quality_item_id' => $control->id]);

        $user = $this->member($customer);
        $this->grant($customer, $user, self::RISK_EDITOR, [$hr]);

        $response = $this->actingAs($user)->get("/app/risk/risks/{$risk->id}")->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertNull($props['controls']);
        $this->assertSame([], $props['control_options']);
        $this->assertFalse($props['permissions']['can_link_controls']);
        $this->assertTrue($props['permissions']['can_edit']);
        $this->assertStringNotContainsString('Hemmelig kontrolltittel', $response->getContent());

        $other = $this->control($customer, 'Annen kontroll');
        $this->actingAs($user)->post($this->url($risk), ['quality_item_id' => $other->id])->assertForbidden();
        $this->actingAs($user)->delete("{$this->url($risk)}/{$control->id}")->assertForbidden();
        $this->assertSame(1, RiskControl::query()->where('risk_id', $risk->id)->count());
    }

    public function test_risk_view_with_quality_view_sees_controls_but_needs_risk_edit_to_change_links(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Sykefravær');
        $control = $this->control($customer, 'Oppfølging av fravær');
        RiskControl::query()->create(['customer_id' => $customer->id, 'risk_id' => $risk->id, 'quality_item_id' => $control->id]);

        $reader = $this->member($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::QUALITY_VIEW], [$hr]);

        $props = $this->showProps($reader, $risk);
        $this->assertSame(['Oppfølging av fravær'], array_column($props['controls'], 'title'));
        $this->assertFalse($props['permissions']['can_link_controls']);
        $this->assertSame([], $props['control_options']);

        $other = $this->control($customer, 'Annen kontroll');
        $this->actingAs($reader)->post($this->url($risk), ['quality_item_id' => $other->id])->assertForbidden();
        $this->actingAs($reader)->delete("{$this->url($risk)}/{$control->id}")->assertForbidden();
        $this->assertSame(1, RiskControl::query()->where('risk_id', $risk->id)->count());
    }

    public function test_edit_and_area_must_come_from_the_same_role(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $control = $this->control($customer, 'Avstemming');

        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::QUALITY_VIEW], [$hr]);
        $this->grant($customer, $user, self::RISK_EDITOR, [$finance]);

        $this->assertFalse($this->showProps($user, $risk)['permissions']['can_link_controls']);
        $this->actingAs($user)->post($this->url($risk), ['quality_item_id' => $control->id])->assertForbidden();
        $this->assertSame(0, RiskControl::query()->count());
    }

    public function test_a_hidden_or_foreign_risk_cannot_be_linked_or_unlinked(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $board = $this->area($customer, 'Styre');
        $hidden = $this->risk($customer, $board, 'Fusjon under vurdering');
        $control = $this->control($customer, 'Innsidekontroll');
        RiskControl::query()->create(['customer_id' => $customer->id, 'risk_id' => $hidden->id, 'quality_item_id' => $control->id]);

        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::RISK_EDITOR, CustomerPermissionCatalog::QUALITY_VIEW], [$hr]);

        $other = $this->control($customer, 'Annen kontroll');
        $this->actingAs($user)->post($this->url($hidden), ['quality_item_id' => $other->id])->assertNotFound();
        $this->actingAs($user)->delete("{$this->url($hidden)}/{$control->id}")->assertNotFound();
        $this->assertSame(1, RiskControl::query()->where('risk_id', $hidden->id)->count());

        // Another tenant's risk, with the same rights there by name: still a 404.
        ['customer' => $foreign] = $this->context();
        $foreignArea = $this->area($foreign, 'HR');
        $foreignRisk = $this->risk($foreign, $foreignArea, 'Utenlandsk risiko');
        $foreignControl = $this->control($foreign, 'Utenlandsk kontroll');
        RiskControl::query()->create(['customer_id' => $foreign->id, 'risk_id' => $foreignRisk->id, 'quality_item_id' => $foreignControl->id]);

        $this->actingAs($user)->post($this->url($foreignRisk), ['quality_item_id' => $other->id])->assertNotFound();
        $this->actingAs($user)->delete("{$this->url($foreignRisk)}/{$foreignControl->id}")->assertNotFound();
        $this->assertSame(1, RiskControl::query()->where('risk_id', $foreignRisk->id)->count());
    }

    public function test_only_a_control_of_the_risks_own_customer_can_be_linked(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::RISK_EDITOR, CustomerPermissionCatalog::QUALITY_VIEW], [$hr]);

        $process = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => 'Lønnsprosess',
            'status' => QualityItem::STATUS_ACTIVE,
        ]);
        ['customer' => $foreign] = $this->context();
        $foreignControl = $this->control($foreign, 'Utenlandsk kontroll');

        foreach ([$process->id, $foreignControl->id, 999999999, 'abc'] as $id) {
            $this->actingAs($user)->post($this->url($risk), ['quality_item_id' => $id])->assertSessionHasErrors('quality_item_id');
        }

        $this->assertSame(0, RiskControl::query()->count());
        $this->assertNotContains($process->id, array_column($this->showProps($user, $risk)['control_options'], 'id'));
        $this->assertNotContains($foreignControl->id, array_column($this->showProps($user, $risk)['control_options'], 'id'));
    }

    public function test_system_owner_needs_a_role_reaching_the_area_to_link(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $control = $this->control($customer, 'Avstemming');

        // System Owner holds every key implicitly, quality.view included — but no risk area.
        $this->actingAs($owner)->post($this->url($risk), ['quality_item_id' => $control->id])->assertNotFound();

        $this->grant($customer, $owner, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);
        $this->actingAs($owner)->post($this->url($risk), ['quality_item_id' => $control->id])->assertForbidden();

        $this->grant($customer, $owner, self::RISK_EDITOR, [$hr]);
        $this->actingAs($owner)->post($this->url($risk), ['quality_item_id' => $control->id])->assertRedirect();
        $this->assertSame(1, RiskControl::query()->where('risk_id', $risk->id)->count());
    }

    public function test_nothing_about_the_link_reaches_kvalitet(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Konfidensiell risikotittel');
        $control = $this->control($customer, 'Avstemming');
        RiskControl::query()->create(['customer_id' => $customer->id, 'risk_id' => $risk->id, 'quality_item_id' => $control->id]);

        // A Kvalitet user who can see the risk too: Kvalitet still does not mention it.
        $user = $this->member($customer);
        $this->grant($customer, $user, [...self::RISK_EDITOR, CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_DELETE], [$hr]);

        foreach ([
            "/app/quality/items/{$control->id}",
            '/app/quality?tab=controls',
            '/app/quality?tab=overview',
            '/app/quality?tab=controls&search=Konfidensiell',
        ] as $url) {
            $content = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('Konfidensiell risikotittel', $content, $url);
            $this->assertStringNotContainsString('risk_controls', $content, $url);
            $this->assertStringNotContainsString("/app/risk/risks/{$risk->id}", $content, $url);
        }

        // Deleting the control in Kvalitet is not refused because of a link it cannot see; the
        // link goes, the risk stays.
        $this->actingAs($user)->delete("/app/quality/items/{$control->id}")->assertRedirect();
        $this->assertDatabaseMissing('quality_items', ['id' => $control->id]);
        $this->assertDatabaseMissing('risk_controls', ['risk_id' => $risk->id]);
        $this->assertDatabaseHas('risks', ['id' => $risk->id]);
    }

    // ---------------------------------------------------------------------

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<RiskAccessArea>  $areas
     */
    private function grant(Customer $customer, User $user, array $permissionKeys, array $areas = []): CustomerRole
    {
        $role = $this->role($customer, $permissionKeys, $areas);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<RiskAccessArea>  $areas
     */
    private function role(Customer $customer, array $permissionKeys, array $areas = []): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $role->syncPermissions($permissionKeys);
        $role->syncRiskAccessAreas(array_map(fn (RiskAccessArea $area): int => (int) $area->id, $areas));

        return $role;
    }

    private function control(Customer $customer, string $title, ?string $criterion = null): QualityItem
    {
        $control = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_CONTROL,
            'title' => $title,
            'status' => QualityItem::STATUS_ACTIVE,
        ]);

        if ($criterion !== null) {
            QualityControlDetail::query()->create([
                'customer_id' => $customer->id,
                'quality_item_id' => $control->id,
                'criterion' => $criterion,
            ]);
        }

        return $control;
    }

    private function url(Risk $risk): string
    {
        return "/app/risk/risks/{$risk->id}/controls";
    }

    private function area(Customer $customer, string $name): RiskAccessArea
    {
        return RiskAccessArea::query()->create(['customer_id' => $customer->id, 'name' => $name]);
    }

    private function risk(Customer $customer, RiskAccessArea $area, string $title): Risk
    {
        return Risk::query()->create([
            'customer_id' => $customer->id,
            'risk_access_area_id' => $area->id,
            'title' => $title,
            'status' => Risk::STATUS_IDENTIFIED,
        ]);
    }

    /** @return array<string, mixed> */
    private function showProps(User $user, Risk $risk): array
    {
        return $this->actingAs($user)->get("/app/risk/risks/{$risk->id}")->assertOk()->viewData('page')['props'];
    }

    private function member(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'risiko-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /** @return array{customer: Customer, owner: User} */
    private function context(bool $withRisk = true): array
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
            'name' => 'Risiko Tilgang AS',
            'slug' => 'risiko-tilgang-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        if ($withRisk) {
            CustomerPackageEntitlement::query()->updateOrCreate(
                ['customer_id' => $customer->id, 'package_key' => 'grc'],
                ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
            );
        }

        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => 'eier-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'owner' => $owner];
    }
}
