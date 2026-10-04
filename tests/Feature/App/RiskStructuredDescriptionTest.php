<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Strukturert risikobeskrivelse: årsak → hendelse → konsekvens.
 *
 * What these tests defend:
 *
 *  - The three parts are the risk description, required on every create and edit.
 *  - They are shown as three parts; no sentence is stitched together from them. The register
 *    summarises a risk by its hendelse.
 *  - An older risk without them still opens, its free text kept as «Utfyllende informasjon» and
 *    never shown as the description — and it cannot be saved again without the three parts.
 *  - A new assessment keeps the description as it read then, and a later edit of the risk does not
 *    change that history. Older assessments get no invented snapshot.
 *  - Access is exactly what it was: risk.create/risk.edit in the area, hidden risks stay hidden.
 */
class RiskStructuredDescriptionTest extends TestCase
{
    use UsesProjectPostgresConnection;

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

    public function test_a_risk_is_created_with_cause_event_and_consequence_and_shown_as_its_parts(): void
    {
        ['customer' => $customer] = $this->context();
        $drift = $this->area($customer, 'Drift');
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_CREATE], [$drift]);

        $this->actingAs($user)->post('/app/risk/risks', $this->payload($drift, [
            'cause' => 'manglende test av gjenoppretting.',
            'event' => '  tap av kundedata  ',
            'consequence' => 'brudd på avtaler og tapt tillit',
            'description' => 'Avdekket i internrevisjon.',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $risk = Risk::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame('manglende test av gjenoppretting.', $risk->cause);
        $this->assertSame('tap av kundedata', $risk->event);
        $this->assertSame('brudd på avtaler og tapt tillit', $risk->consequence);
        $this->assertSame('Avdekket i internrevisjon.', $risk->description);

        $props = $this->showProps($user, $risk);
        $this->assertSame('manglende test av gjenoppretting.', $props['risk']['cause']);
        $this->assertSame('tap av kundedata', $props['risk']['event']);
        $this->assertSame('brudd på avtaler og tapt tillit', $props['risk']['consequence']);
        $this->assertTrue($props['risk']['has_structured_description']);
        $this->assertSame('Avdekket i internrevisjon.', $props['risk']['description']);
        // No composed sentence reaches the page.
        $this->assertArrayNotHasKey('statement', $props['risk']);

        // The register's short description is the hendelse, as written.
        $index = $this->actingAs($user)->get('/app/risk')->assertOk()->viewData('page')['props'];
        $this->assertSame('tap av kundedata', $index['risks'][0]['event']);
        $this->assertTrue($index['risks'][0]['has_structured_description']);
        $this->assertArrayNotHasKey('statement', $index['risks'][0]);
    }

    public function test_cause_event_and_consequence_are_required_on_create_and_on_edit(): void
    {
        ['customer' => $customer] = $this->context();
        $drift = $this->area($customer, 'Drift');
        $user = $this->member($customer);
        $this->grant($customer, $user, [
            CustomerPermissionCatalog::RISK_VIEW,
            CustomerPermissionCatalog::RISK_CREATE,
            CustomerPermissionCatalog::RISK_EDIT,
        ], [$drift]);

        foreach (['cause', 'event', 'consequence'] as $field) {
            foreach ([null, '', '   '] as $blank) {
                $this->actingAs($user)->post('/app/risk/risks', $this->payload($drift, [$field => $blank]))
                    ->assertSessionHasErrors($field);
            }
        }

        $this->assertSame(0, Risk::query()->where('customer_id', $customer->id)->count());

        $risk = $this->structuredRisk($customer, $drift);

        foreach (['cause', 'event', 'consequence'] as $field) {
            $this->actingAs($user)->patch("/app/risk/risks/{$risk->id}", $this->payload($drift, [$field => '']))
                ->assertSessionHasErrors($field);
        }

        $this->assertSame('strømbrudd', $risk->fresh()->cause);
    }

    public function test_an_older_risk_without_structure_opens_keeps_its_text_and_must_be_completed_on_edit(): void
    {
        ['customer' => $customer] = $this->context();
        $drift = $this->area($customer, 'Drift');
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$drift]);

        // As registered before the structured description existed.
        $legacy = Risk::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $drift->id,
            'title' => 'Varsling i krise',
            'description' => 'Dette ble avdekket i revisjonen den 30.5.2026.',
            'status' => Risk::STATUS_IDENTIFIED,
        ]);

        $props = $this->showProps($user, $legacy);
        $this->assertFalse($props['risk']['has_structured_description']);
        // Never a fallback to the free text: the description is missing, and says so.
        $this->assertNull($props['risk']['event']);
        $this->assertSame('Dette ble avdekket i revisjonen den 30.5.2026.', $props['risk']['description']);

        $index = $this->actingAs($user)->get('/app/risk')->assertOk()->viewData('page')['props'];
        $this->assertFalse($index['risks'][0]['has_structured_description']);
        $this->assertNull($index['risks'][0]['event']);

        // Saving it unchanged is refused until the three parts are given.
        $this->actingAs($user)->patch("/app/risk/risks/{$legacy->id}", [
            'title' => 'Varsling i krise',
            'description' => $legacy->description,
            'business_area_id' => $drift->id,
            'status' => Risk::STATUS_IDENTIFIED,
        ])->assertSessionHasErrors(['cause', 'event', 'consequence']);

        $this->actingAs($user)->patch("/app/risk/risks/{$legacy->id}", $this->payload($drift, [
            'title' => 'Varsling i krise',
            'description' => $legacy->description,
        ]))->assertSessionHasNoErrors();

        $legacy->refresh();
        $this->assertTrue($legacy->hasStructuredDescription());
        $this->assertSame('Dette ble avdekket i revisjonen den 30.5.2026.', $legacy->description);
    }

    public function test_an_assessment_keeps_the_description_it_was_made_against(): void
    {
        ['customer' => $customer] = $this->context();
        $drift = $this->area($customer, 'Drift');
        $user = $this->member($customer);
        $this->grant($customer, $user, [
            CustomerPermissionCatalog::RISK_VIEW,
            CustomerPermissionCatalog::RISK_EDIT,
            CustomerPermissionCatalog::RISK_ASSESS,
        ], [$drift]);
        $risk = $this->structuredRisk($customer, $drift);

        $this->actingAs($user)->post("/app/risk/risks/{$risk->id}/assessments", $this->assessment())->assertSessionHasNoErrors();

        $assessment = RiskAssessment::query()->where('risk_id', $risk->id)->sole();
        $this->assertSame('strømbrudd', $assessment->risk_cause);
        $this->assertSame('serverne stopper', $assessment->risk_event);
        $this->assertSame('kundene mister tilgang', $assessment->risk_consequence);

        $props = $this->showProps($user, $risk);
        $before = ['cause' => 'strømbrudd', 'event' => 'serverne stopper', 'consequence' => 'kundene mister tilgang'];
        $this->assertSnapshot($before, $props['assessments'][0]['risk_description']);
        $this->assertFalse($props['assessments'][0]['risk_description']['changed_since']);

        // The risk is described again afterwards.
        $this->actingAs($user)->patch("/app/risk/risks/{$risk->id}", $this->payload($drift, [
            'cause' => 'brann i datasenteret',
            'event' => 'driften stanser',
            'consequence' => 'lengre nedetid',
        ]))->assertSessionHasNoErrors();

        $assessment->refresh();
        $this->assertSame('strømbrudd', $assessment->risk_cause);
        $this->assertSame('serverne stopper', $assessment->risk_event);
        $this->assertSame('kundene mister tilgang', $assessment->risk_consequence);

        $props = $this->showProps($user, $risk);
        $this->assertSame('brann i datasenteret', $props['risk']['cause']);
        // History still shows what was assessed then, part by part.
        $this->assertSnapshot($before, $props['assessments'][0]['risk_description']);
        $this->assertTrue($props['assessments'][0]['risk_description']['changed_since']);
    }

    public function test_an_assessment_without_a_snapshot_gets_none_invented(): void
    {
        ['customer' => $customer] = $this->context();
        $drift = $this->area($customer, 'Drift');
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW], [$drift]);
        $risk = $this->structuredRisk($customer, $drift);

        // Registered before snapshots existed.
        RiskAssessment::query()->create([
            'customer_id' => $customer->id,
            'risk_id' => $risk->id,
            'assessed_at' => now(),
            'rationale' => 'Gammel vurdering.',
            'criteria_key' => 'standard_5x5_v1',
            'inherent_likelihood' => 3,
            'inherent_consequence' => 3,
        ]);

        $props = $this->showProps($user, $risk);
        $this->assertNull($props['assessments'][0]['risk_description']);
    }

    public function test_access_rules_are_unchanged(): void
    {
        ['customer' => $customer] = $this->context();
        $drift = $this->area($customer, 'Drift');
        $hr = $this->area($customer, 'HR');
        $visible = $this->structuredRisk($customer, $drift);
        $hidden = $this->structuredRisk($customer, $hr, ['cause' => 'hemmelig årsak']);

        $reader = $this->member($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::RISK_VIEW], [$drift]);

        // Seeing is not editing, and the structured fields are no way around it.
        $this->actingAs($reader)->patch("/app/risk/risks/{$visible->id}", $this->payload($drift, ['cause' => 'endret']))->assertForbidden();
        // Without risk.create the area is refused, as before.
        $this->actingAs($reader)->post('/app/risk/risks', $this->payload($drift))->assertSessionHasErrors('business_area_id');
        $this->assertSame('strømbrudd', $visible->fresh()->cause);
        $this->assertSame(2, Risk::query()->where('customer_id', $customer->id)->count());

        // A risk outside the reader's areas stays a 404, and its description is not searchable.
        $this->actingAs($reader)->get("/app/risk/risks/{$hidden->id}")->assertNotFound();
        $this->actingAs($reader)->patch("/app/risk/risks/{$hidden->id}", $this->payload($hr))->assertNotFound();

        $index = $this->actingAs($reader)->get('/app/risk?search=hemmelig')->assertOk()->viewData('page')['props'];
        $this->assertSame([], $index['risks']);

        $index = $this->actingAs($reader)->get('/app/risk?search=serverne')->assertOk()->viewData('page')['props'];
        $this->assertSame([$visible->id], array_column($index['risks'], 'id'));

        // Creating still takes risk.create in the chosen area.
        $creator = $this->member($customer);
        $this->grant($customer, $creator, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_CREATE], [$drift]);
        $this->actingAs($creator)->post('/app/risk/risks', $this->payload($hr))->assertSessionHasErrors('business_area_id');
        $this->actingAs($creator)->post('/app/risk/risks', $this->payload($drift))->assertSessionHasNoErrors();
    }

    // ---------------------------------------------------------------------

    /**
     * @param  array{cause: string, event: string, consequence: string}  $expected
     * @param  array<string, mixed>  $snapshot
     */
    private function assertSnapshot(array $expected, array $snapshot): void
    {
        $this->assertSame($expected, array_intersect_key($snapshot, $expected));
        $this->assertArrayNotHasKey('statement', $snapshot);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function payload(BusinessArea $area, array $changes = []): array
    {
        return array_merge([
            'title' => 'Strømbrudd',
            'cause' => 'strømbrudd',
            'event' => 'serverne stopper',
            'consequence' => 'kundene mister tilgang',
            'description' => null,
            'business_area_id' => $area->id,
            'owner_user_id' => null,
            'status' => Risk::STATUS_IDENTIFIED,
        ], $changes);
    }

    /** @param  array<string, mixed>  $changes */
    private function structuredRisk(Customer $customer, BusinessArea $area, array $changes = []): Risk
    {
        return Risk::query()->create(array_merge([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => 'Strømbrudd',
            'cause' => 'strømbrudd',
            'event' => 'serverne stopper',
            'consequence' => 'kundene mister tilgang',
            'status' => Risk::STATUS_IDENTIFIED,
        ], $changes));
    }

    /** @return array<string, mixed> */
    private function assessment(): array
    {
        return [
            'inherent_likelihood' => 3,
            'inherent_consequence' => 4,
            'rationale' => 'Erfaring fra tidligere hendelser.',
        ];
    }

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<BusinessArea>  $areas
     */
    private function grant(Customer $customer, User $user, array $permissionKeys, array $areas = []): CustomerRole
    {
        $role = $this->role($customer, $permissionKeys, $areas);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<BusinessArea>  $areas
     */
    private function role(Customer $customer, array $permissionKeys, array $areas = []): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $role->syncPermissions($permissionKeys);
        $role->syncBusinessAreas(false, array_map(fn (BusinessArea $area): int => (int) $area->id, $areas));

        return $role;
    }

    private function area(Customer $customer, string $name): BusinessArea
    {
        return BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => $name]);
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
