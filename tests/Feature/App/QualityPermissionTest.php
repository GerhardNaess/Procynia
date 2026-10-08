<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\QualityItem;
use App\Models\QualityItemRelation;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Quality\QualityProcessBlueprintService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Kvalitet, gated by the customer's own roles.
 *
 * The module used to borrow Wiki's claim-approval authority for every write and to let anyone in
 * the virksomhet read. It now asks CustomerPermissionService for quality.view, quality.create,
 * quality.edit, quality.approve and quality.delete, which is the vocabulary the customer builds
 * their own role names out of.
 *
 * What these tests defend:
 *
 *  - Each of the five permissions is enforced on its own. Holding one grants exactly what it
 *    names and nothing adjacent — adding quality.delete is what makes deleting possible, and
 *    taking it away is what stops it.
 *  - A permission is never a substitute for object security. The tenant check still runs, the type
 *    check still runs, and Wiki still decides what happens to a Wiki page.
 *  - System Owner keeps the whole module unconditionally, so a customer cannot lock themselves out
 *    of the kvalitetssystem by how they named their roles.
 *  - The page is told what it may offer, so the UI hides what the controller would refuse — but
 *    the controller is the one that refuses it.
 */
class QualityPermissionTest extends TestCase
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

    // ---------------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------------

    public function test_a_user_without_quality_view_cannot_read_the_module(): void
    {
        ['customer' => $customer] = $this->context();
        $user = $this->member($customer);
        $item = $this->item($customer, QualityItem::TYPE_PROCESS, 'Avvikshåndtering');

        $this->actingAs($user)->get('/app/quality')->assertForbidden();
        $this->actingAs($user)->get("/app/quality/items/{$item->id}")->assertForbidden();
    }

    /** Scenario 1: quality.view alone reads the whole module and changes none of it. */
    public function test_quality_view_alone_reads_but_never_writes(): void
    {
        Queue::fake();

        ['customer' => $customer] = $this->context();
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::QUALITY_VIEW]);

        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Avvikshåndtering');
        $this->blueprintFor($customer, $process);

        $props = $this->actingAs($user)->get('/app/quality')->assertOk()->viewData('page')['props'];

        $this->assertSame(
            [
                'can_create' => false,
                'can_edit' => false,
                'can_approve' => false,
                'can_delete' => false,
                // The cross-module one. quality.view alone never reaches Wiki.
                'can_create_wiki_articles' => false,
            ],
            $props['permissions'],
        );

        $this->actingAs($user)->get("/app/quality/items/{$process->id}")->assertOk();

        // Every write the module has, refused.
        $this->actingAs($user)
            ->post('/app/quality/items', ['quality_type' => QualityItem::TYPE_PROCESS, 'title' => 'Ny prosess'])
            ->assertForbidden();
        $this->actingAs($user)
            ->patch("/app/quality/items/{$process->id}", ['title' => 'Endret'])
            ->assertForbidden();
        $this->actingAs($user)
            ->put("/app/quality/items/{$process->id}/blueprint", $this->simpleFlow())
            ->assertForbidden();
        $this->actingAs($user)
            ->post("/app/quality/items/{$process->id}/blueprint/approve")
            ->assertForbidden();
        $this->actingAs($user)
            ->delete("/app/quality/items/{$process->id}/blueprint")
            ->assertForbidden();
        $this->actingAs($user)
            ->delete("/app/quality/items/{$process->id}")
            ->assertForbidden();

        // Governing documents are read, never linked or unlinked, on quality.view alone.
        $policy = $this->item($customer, QualityItem::TYPE_POLICY, 'Avvikspolicy');
        $relation = QualityItemRelation::query()->create([
            'customer_id' => $customer->id,
            'from_item_id' => $policy->id,
            'to_item_id' => $process->id,
            'relation_type' => QualityItemRelation::TYPE_GOVERNS,
            'source' => QualityItemRelation::SOURCE_MANUAL,
        ]);
        $itemProps = $this->actingAs($user)->get("/app/quality/items/{$process->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame([(int) $policy->id], array_column($itemProps['governing_documents'], 'other_item_id'));
        $this->actingAs($user)
            ->post('/app/quality/relations', [
                'from_item_id' => $policy->id,
                'to_item_id' => $process->id,
                'relation_type' => QualityItemRelation::TYPE_GOVERNS,
            ])
            ->assertForbidden();
        $this->actingAs($user)->delete("/app/quality/relations/{$relation->id}")->assertForbidden();
        $this->assertNotNull($relation->fresh());

        $this->assertSame('Avvikshåndtering', $process->fresh()->title);
        $this->assertNotNull(QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->first());
    }

    // ---------------------------------------------------------------------
    // Creating, editing, approving
    // ---------------------------------------------------------------------

    public function test_creating_a_styrende_dokument_needs_quality_create(): void
    {
        ['customer' => $customer] = $this->context();
        $editor = $this->member($customer);
        $this->grant($customer, $editor, [
            CustomerPermissionCatalog::QUALITY_VIEW,
            CustomerPermissionCatalog::QUALITY_EDIT,
        ]);

        // Editing is not creating.
        $this->actingAs($editor)
            ->post('/app/quality/items', ['quality_type' => QualityItem::TYPE_PROCESS, 'title' => 'Ny prosess'])
            ->assertForbidden();

        $creator = $this->member($customer);
        $this->grant($customer, $creator, [
            CustomerPermissionCatalog::QUALITY_VIEW,
            CustomerPermissionCatalog::QUALITY_CREATE,
        ]);

        $this->actingAs($creator)
            ->post('/app/quality/items', ['quality_type' => QualityItem::TYPE_PROCESS, 'title' => 'Ny prosess'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(
            QualityItem::query()->where('customer_id', $customer->id)->where('title', 'Ny prosess')->first(),
        );
    }

    /** Scenario 2: view + edit changes the document and its flow, and nothing else. */
    public function test_quality_view_plus_edit_may_change_the_process_and_its_flow(): void
    {
        Queue::fake();

        ['customer' => $customer] = $this->context();
        $user = $this->member($customer);
        $this->grant($customer, $user, [
            CustomerPermissionCatalog::QUALITY_VIEW,
            CustomerPermissionCatalog::QUALITY_EDIT,
        ]);

        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Avvikshåndtering');

        $this->actingAs($user)
            ->patch("/app/quality/items/{$process->id}", [
                'title' => 'Avvikshåndtering 2.0',
                'status' => QualityItem::STATUS_ACTIVE,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Avvikshåndtering 2.0', $process->fresh()->title);

        $this->actingAs($user)
            ->put("/app/quality/items/{$process->id}/blueprint", $this->simpleFlow())
            ->assertSessionHasNoErrors();

        $blueprint = QualityProcessBlueprint::query()->where('quality_item_id', $process->id)->first();
        $this->assertNotNull($blueprint);

        // Approving and deleting are their own permissions, and edit is not either of them.
        $this->actingAs($user)
            ->post("/app/quality/items/{$process->id}/blueprint/approve")
            ->assertForbidden();
        $this->actingAs($user)
            ->delete("/app/quality/items/{$process->id}/blueprint")
            ->assertForbidden();

        $this->assertNotSame(
            QualityProcessBlueprint::STATUS_APPROVED,
            QualityProcessBlueprint::query()->find($blueprint->id)->status,
        );
    }

    public function test_approving_a_flow_needs_quality_approve(): void
    {
        Queue::fake();

        ['customer' => $customer] = $this->context();
        $user = $this->member($customer);
        $this->grant($customer, $user, [
            CustomerPermissionCatalog::QUALITY_VIEW,
            CustomerPermissionCatalog::QUALITY_APPROVE,
        ]);

        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Avvikshåndtering');
        $blueprint = $this->blueprintFor($customer, $process);

        $this->actingAs($user)
            ->post("/app/quality/items/{$process->id}/blueprint/approve")
            ->assertSessionHasNoErrors();

        $this->assertSame(
            QualityProcessBlueprint::STATUS_APPROVED,
            QualityProcessBlueprint::query()->find($blueprint->id)->status,
        );

        // Vouching for a flow is not the right to rewrite one.
        $this->actingAs($user)
            ->put("/app/quality/items/{$process->id}/blueprint", $this->simpleFlow())
            ->assertForbidden();
    }

    // ---------------------------------------------------------------------
    // Deleting
    // ---------------------------------------------------------------------

    /**
     * Scenarios 3 and 4, in one test because they are one claim: the delete is refused without
     * quality.delete and accepted the moment the role carries it — nothing else about the user,
     * the process or the request changes in between.
     */
    public function test_deleting_becomes_possible_only_when_quality_delete_is_added(): void
    {
        Queue::fake();

        ['customer' => $customer] = $this->context();
        $user = $this->member($customer);
        $role = $this->grant($customer, $user, [
            CustomerPermissionCatalog::QUALITY_VIEW,
            CustomerPermissionCatalog::QUALITY_EDIT,
        ]);

        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Avvikshåndtering');
        $this->blueprintFor($customer, $process);

        $this->actingAs($user)->delete("/app/quality/items/{$process->id}/blueprint")->assertForbidden();
        $this->actingAs($user)->delete("/app/quality/items/{$process->id}")->assertForbidden();

        $this->assertNotNull(QualityItem::query()->find($process->id));

        // The page offered neither button while the role did not carry the permission.
        $props = $this->actingAs($user)->get('/app/quality')->viewData('page')['props'];
        $this->assertFalse($props['permissions']['can_delete']);

        $role->syncPermissions([
            CustomerPermissionCatalog::QUALITY_VIEW,
            CustomerPermissionCatalog::QUALITY_EDIT,
            CustomerPermissionCatalog::QUALITY_DELETE,
        ]);

        $props = $this->actingAs($user->fresh())->get('/app/quality')->viewData('page')['props'];
        $this->assertTrue($props['permissions']['can_delete']);

        $this->actingAs($user->fresh())
            ->delete("/app/quality/items/{$process->id}/blueprint")
            ->assertSessionHasNoErrors();
        $this->actingAs($user->fresh())
            ->delete("/app/quality/items/{$process->id}")
            ->assertRedirect('/app/quality');

        $this->assertNull(QualityItem::query()->find($process->id));
    }

    // ---------------------------------------------------------------------
    // What a permission is not
    // ---------------------------------------------------------------------

    /**
     * A permission says what kind of act the user may perform. It never says which rows are
     * theirs — the tenant check does that, and it runs whatever the role grants.
     */
    public function test_a_permission_does_not_reach_across_the_tenant_boundary(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();

        $user = $this->member($customer);
        $this->grant($customer, $user, CustomerPermissionCatalog::domains()[CustomerPermissionCatalog::DOMAIN_QUALITY]);

        $theirs = $this->item($other, QualityItem::TYPE_PROCESS, 'Andres prosess');

        $this->actingAs($user)->get("/app/quality/items/{$theirs->id}")->assertNotFound();
        $this->actingAs($user)->patch("/app/quality/items/{$theirs->id}", ['title' => 'Kapret'])->assertNotFound();
        $this->actingAs($user)->delete("/app/quality/items/{$theirs->id}")->assertNotFound();

        $this->assertSame('Andres prosess', $theirs->fresh()->title);
    }

    /**
     * A role the customer turned off grants nothing, so the module closes behind the user without
     * the assignment having to be unpicked.
     */
    public function test_an_inactive_role_closes_the_module(): void
    {
        ['customer' => $customer] = $this->context();
        $user = $this->member($customer);
        $role = $this->grant($customer, $user, [CustomerPermissionCatalog::QUALITY_VIEW]);

        $this->actingAs($user)->get('/app/quality')->assertOk();

        $role->update(['is_active' => false]);

        $this->actingAs($user->fresh())->get('/app/quality')->assertForbidden();
    }

    /**
     * The left rail is told the same answer the controller gives, so it stops offering Kvalitet to
     * someone who would only reach a 403 — and starts again the moment the role carries the view.
     */
    public function test_the_rail_is_told_which_permissions_the_user_holds(): void
    {
        ['customer' => $customer] = $this->context();
        $user = $this->member($customer);

        $props = $this->actingAs($user)->get('/app/dashboard')->assertOk()->viewData('page')['props'];
        $this->assertSame([], $props['access']['permissions']);

        $role = $this->grant($customer, $user, [CustomerPermissionCatalog::QUALITY_VIEW]);

        $props = $this->actingAs($user->fresh())->get('/app/dashboard')->viewData('page')['props'];
        $this->assertSame([CustomerPermissionCatalog::QUALITY_VIEW], $props['access']['permissions']);

        $role->update(['is_active' => false]);

        $props = $this->actingAs($user->fresh())->get('/app/dashboard')->viewData('page')['props'];
        $this->assertSame([], $props['access']['permissions']);
    }

    /** No arrangement of customer roles can shut System Owner out of the kvalitetssystem. */
    public function test_system_owner_holds_the_whole_module_without_a_role(): void
    {
        Queue::fake();

        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $process = $this->item($customer, QualityItem::TYPE_PROCESS, 'Avvikshåndtering');
        $this->blueprintFor($customer, $process);

        $props = $this->actingAs($owner)->get('/app/quality')->assertOk()->viewData('page')['props'];

        $this->assertSame(
            [
                'can_create' => true,
                'can_edit' => true,
                'can_approve' => true,
                'can_delete' => true,
                // System Owner holds the whole catalogue, Wiki's half included, so the handover
                // into Wiki is open to them too.
                'can_create_wiki_articles' => true,
            ],
            $props['permissions'],
        );

        $this->actingAs($owner)
            ->post("/app/quality/items/{$process->id}/blueprint/approve")
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)
            ->delete("/app/quality/items/{$process->id}")
            ->assertRedirect('/app/quality');
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    /**
     * @param  list<string>  $permissionKeys
     */
    private function grant(Customer $customer, User $user, array $permissionKeys): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $role->syncPermissions($permissionKeys);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    private function member(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'kvalitet-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function item(Customer $customer, string $type, string $title): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => $type,
            'title' => $title,
            'status' => QualityItem::STATUS_ACTIVE,
        ]);
    }

    private function blueprintFor(Customer $customer, QualityItem $item): QualityProcessBlueprint
    {
        return app(QualityProcessBlueprintService::class)->store(
            (int) $customer->id,
            $item,
            $this->simpleFlow(),
            QualityProcessBlueprint::SOURCE_MANUAL,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function simpleFlow(): array
    {
        return [
            'lanes' => [['key' => 'rolle-1', 'label' => 'Kvalitetsleder']],
            'nodes' => [
                ['key' => 'start', 'lane' => 'rolle-1', 'type' => 'start', 'label' => 'Start'],
                ['key' => 'vurder', 'lane' => 'rolle-1', 'type' => 'step', 'label' => 'Vurder avviket'],
                ['key' => 'slutt', 'lane' => 'rolle-1', 'type' => 'end', 'label' => 'Slutt'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'vurder'],
                ['from' => 'vurder', 'to' => 'slutt'],
            ],
        ];
    }

    /**
     * @return array{customer: Customer, owner: User}
     */
    private function context(): array
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
            'name' => 'Kvalitet Tilgang AS',
            'slug' => 'kvalitet-tilgang-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        app(ModuleEntitlementService::class)->activatePackage($customer, 'basis');

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
